<?php
// files/tick_engine.php — PHP-based game tick engine
// Called by tick.php (manual/cron) instead of MySQL stored procedure
// Tick interval: 5 minutes (12 ticks per hour)
// All rates defined as "per hour" — each tick processes 1/12th of an hour

define('TICKS_PER_HOUR', 12); // 60 min / 5 min = 12

/**
 * Run a single game tick — processes all power grids, burns fuel, mines resources, runs factories.
 * Returns associative array with tick stats, or false if debounce/lock prevented execution.
 */
function runGameTick($conn) {
    $start = microtime(true);

    // Debounce: skip if last tick < 4 minutes ago
    $recent = $conn->query("SELECT COUNT(*) AS cnt FROM tick_log WHERE tick_time > DATE_SUB(NOW(), INTERVAL 4 MINUTE)");
    if ($recent && $recent->fetch_assoc()['cnt'] > 0) {
        return ['skipped' => true, 'reason' => 'debounce'];
    }

    $totalProduced = 0.0;
    $totalBurned = 0.0;
    $factoryProduced = 0.0;
    $factoryConsumed = 0.0;

    // ═══════════════════════════════════════════════
    // PHASE 1: Discover power grids
    // Union-find via iterative propagation
    // ═══════════════════════════════════════════════

    // Load all active towns
    $towns = [];
    $res = $conn->query("SELECT id, side FROM towns WHERE population > 0");
    while ($r = $res->fetch_assoc()) {
        $towns[(int)$r['id']] = [
            'side' => $r['side'],
            'grid_id' => (int)$r['id']
        ];
    }
    $townsCount = count($towns);

    // Load all transmission lines
    $lines = [];
    $res = $conn->query("SELECT town_id_1, town_id_2 FROM transmission_lines WHERE level > 0");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $lines[] = [(int)$r['town_id_1'], (int)$r['town_id_2']];
        }
    }

    // Propagate minimum grid_id through same-faction lines
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($lines as $line) {
            $t1 = $line[0]; $t2 = $line[1];
            if (!isset($towns[$t1]) || !isset($towns[$t2])) continue;
            if ($towns[$t1]['side'] !== $towns[$t2]['side']) continue;
            $g1 = $towns[$t1]['grid_id'];
            $g2 = $towns[$t2]['grid_id'];
            if ($g1 !== $g2) {
                $minG = min($g1, $g2);
                $towns[$t1]['grid_id'] = $minG;
                $towns[$t2]['grid_id'] = $minG;
                $changed = true;
            }
        }
    }

    // Group towns by grid
    $grids = []; // grid_id => [town_ids]
    foreach ($towns as $tid => $t) {
        $grids[$t['grid_id']][] = $tid;
    }

    // ═══════════════════════════════════════════════
    // PHASE 2: Calculate power generation per grid
    // ═══════════════════════════════════════════════

    // Load all active power stations
    $stations = [];
    $res = $conn->query("
        SELECT ps.id, ps.town_id, ps.fuel_type, ps.level, ps.workers_assigned,
               pst.fuel_per_mw_hr, pst.fuel_resource_name,
               COALESCE(pst.mw_per_level, 1.0) AS mw_per_level,
               wp.id AS fuel_rid
        FROM power_stations ps
        JOIN power_station_types pst ON ps.fuel_type = pst.fuel_type
        LEFT JOIN world_prices wp ON wp.resource_name = pst.fuel_resource_name
        WHERE ps.level > 0 AND ps.workers_assigned > 0
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $level = (int)$r['level'];
            $workers = (int)$r['workers_assigned'];
            $maxWorkers = $level * 100;
            $mwPerLevel = (float)$r['mw_per_level'];
            $baseMW = $level * $mwPerLevel * ($workers / $maxWorkers);
            $fuelPerMin = (float)$r['fuel_per_mw_hr'] * $baseMW / TICKS_PER_HOUR;

            $stations[] = [
                'id' => (int)$r['id'],
                'town_id' => (int)$r['town_id'],
                'base_mw' => $baseMW,
                'fuel_per_min' => $fuelPerMin,
                'fuel_rid' => (int)$r['fuel_rid'],
                'actual_mw' => 0,
                'actual_burn' => 0,
            ];
        }
    }

    // Load fuel stock for station towns
    $fuelStocks = []; // town_id => resource_id => stock
    $res = $conn->query("SELECT town_id, resource_id, stock FROM town_resources");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $fuelStocks[(int)$r['town_id']][(int)$r['resource_id']] = (float)$r['stock'];
        }
    }

    // Cap station output by fuel availability
    foreach ($stations as &$s) {
        $stock = $fuelStocks[$s['town_id']][$s['fuel_rid']] ?? 0;
        if ($s['fuel_per_min'] > 0 && $stock < $s['fuel_per_min']) {
            $ratio = $s['fuel_per_min'] > 0 ? $stock / $s['fuel_per_min'] : 0;
            $s['actual_mw'] = $s['base_mw'] * $ratio;
            $s['actual_burn'] = $stock;
        } else {
            $s['actual_mw'] = $s['base_mw'];
            $s['actual_burn'] = $s['fuel_per_min'];
        }
    }
    unset($s);

    // ═══════════════════════════════════════════════
    // PHASE 3: Compute power ratio per grid
    // ═══════════════════════════════════════════════

    // Generation per grid
    $gridGen = [];
    foreach ($stations as $s) {
        $tid = $s['town_id'];
        if (!isset($towns[$tid])) continue;
        $gid = $towns[$tid]['grid_id'];
        $gridGen[$gid] = ($gridGen[$gid] ?? 0) + $s['actual_mw'];
    }

    // Demand: mines
    $mineDemand = []; // grid_id => MW
    $mineData = [];
    $res = $conn->query("SELECT town_id, resource_id, level, workers_assigned FROM town_production WHERE level > 0");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $tid = (int)$r['town_id'];
            if (!isset($towns[$tid])) continue;
            $gid = $towns[$tid]['grid_id'];
            $mineDemand[$gid] = ($mineDemand[$gid] ?? 0) + (int)$r['level'] * 1.0;
            $mineData[] = [
                'town_id' => $tid,
                'resource_id' => (int)$r['resource_id'],
                'level' => (int)$r['level'],
                'workers_assigned' => (int)$r['workers_assigned'],
            ];
        }
    }

    // Demand: factories
    $factoryDemand = []; // grid_id => MW
    $factoryData = [];
    $res = $conn->query("
        SELECT tf.id, tf.town_id, tf.factory_type_id, tf.level, tf.workers_assigned,
               ft.power_per_level, ft.workers_per_level, ft.output_per_level, ft.output_resource_id
        FROM town_factories tf
        JOIN factory_types ft ON tf.factory_type_id = ft.type_id
        WHERE tf.level > 0 AND tf.workers_assigned > 0
    ");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $tid = (int)$r['town_id'];
            if (!isset($towns[$tid])) continue;
            $gid = $towns[$tid]['grid_id'];
            $dem = (int)$r['level'] * (float)$r['power_per_level'];
            $factoryDemand[$gid] = ($factoryDemand[$gid] ?? 0) + $dem;
            $factoryData[] = $r;
        }
    }

    // Power ratio per grid
    $gridPowerRatio = [];
    foreach ($grids as $gid => $tids) {
        $gen = $gridGen[$gid] ?? 0;
        $dem = ($mineDemand[$gid] ?? 0) + ($factoryDemand[$gid] ?? 0);
        if ($dem > 0) {
            $gridPowerRatio[$gid] = min($gen / $dem, 1.0);
        } elseif ($gen > 0) {
            $gridPowerRatio[$gid] = 1.0;
        } else {
            $gridPowerRatio[$gid] = 0.0;
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 3b: Demand throttle — reduce burn if generation exceeds demand
    // ═══════════════════════════════════════════════
    $gridThrottle = []; // grid_id => ratio (1.0 = full burn, <1.0 = surplus)
    foreach ($grids as $gid => $tids) {
        $gen = $gridGen[$gid] ?? 0;
        $dem = ($mineDemand[$gid] ?? 0) + ($factoryDemand[$gid] ?? 0);
        if ($gen > 0 && $dem > 0) {
            $gridThrottle[$gid] = min($dem / $gen, 1.0);
        } elseif ($dem > 0) {
            $gridThrottle[$gid] = 1.0;
        } else {
            $gridThrottle[$gid] = 0.0; // no demand → no burn
        }
    }

    // Apply throttle to each station
    foreach ($stations as &$s) {
        $tid = $s['town_id'];
        if (!isset($towns[$tid])) continue;
        $gid = $towns[$tid]['grid_id'];
        $throttle = $gridThrottle[$gid] ?? 1.0;
        $s['actual_mw']   *= $throttle;
        $s['actual_burn']  *= $throttle;
    }
    unset($s);

    // ═══════════════════════════════════════════════
    // PHASE 4: Burn fuel
    // ═══════════════════════════════════════════════

    // Aggregate burns per town+resource
    $fuelBurns = []; // "town_id:resource_id" => amount
    foreach ($stations as $s) {
        if ($s['actual_burn'] <= 0) continue;
        $key = $s['town_id'] . ':' . $s['fuel_rid'];
        $fuelBurns[$key] = ($fuelBurns[$key] ?? 0) + $s['actual_burn'];
        $totalBurned += $s['actual_burn'];
    }

    foreach ($fuelBurns as $key => $amount) {
        list($tid, $rid) = explode(':', $key);
        $stmt = $conn->prepare("UPDATE town_resources SET stock = GREATEST(0, stock - ?) WHERE town_id = ? AND resource_id = ?");
        $stmt->bind_param("dii", $amount, $tid, $rid);
        $stmt->execute();
        $stmt->close();
    }

    // ═══════════════════════════════════════════════
    // PHASE 5: Mine production
    // output_per_tick = level × worker_eff × power_ratio / 60
    // ═══════════════════════════════════════════════

    foreach ($mineData as $mine) {
        if ($mine['workers_assigned'] <= 0) continue;
        $tid = $mine['town_id'];
        $gid = $towns[$tid]['grid_id'];
        $pRatio = $gridPowerRatio[$gid] ?? 0;
        $workerEff = $mine['workers_assigned'] / ($mine['level'] * 100.0);
        $output = $mine['level'] * $workerEff * $pRatio / TICKS_PER_HOUR;
        if ($output <= 0) continue;

        $totalProduced += $output;
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
        $stmt->bind_param("dii", $output, $tid, $mine['resource_id']);
        $stmt->execute();
        $stmt->close();
    }

    // ═══════════════════════════════════════════════
    // PHASE 6: Factory production
    // ═══════════════════════════════════════════════

    // Load factory inputs
    $allInputs = []; // factory_type_id => [[resource_id, amount_per_level], ...]
    $res = $conn->query("SELECT factory_type_id, resource_id, amount_per_level FROM factory_inputs");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $allInputs[$r['factory_type_id']][] = [
                'resource_id' => (int)$r['resource_id'],
                'amount_per_level' => (float)$r['amount_per_level'],
            ];
        }
    }

    // Reload stocks (after fuel burn)
    $stocks = [];
    $res = $conn->query("SELECT town_id, resource_id, stock FROM town_resources");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $stocks[(int)$r['town_id']][(int)$r['resource_id']] = (float)$r['stock'];
        }
    }

    foreach ($factoryData as $f) {
        $tid = (int)$f['town_id'];
        $gid = $towns[$tid]['grid_id'];
        $pRatio = $gridPowerRatio[$gid] ?? 0;
        $level = (int)$f['level'];
        $workers = (int)$f['workers_assigned'];
        $maxWorkers = $level * (int)$f['workers_per_level'];
        $workerEff = $maxWorkers > 0 ? $workers / $maxWorkers : 0;
        $efficiency = $workerEff * $pRatio;
        if ($efficiency <= 0) continue;

        $typeId = $f['factory_type_id'];
        $inputs = $allInputs[$typeId] ?? [];

        // Calculate bottleneck
        $bottleneck = 1.0;
        foreach ($inputs as $inp) {
            $desired = $inp['amount_per_level'] * $level * $efficiency / TICKS_PER_HOUR;
            if ($desired > 0) {
                $available = $stocks[$tid][$inp['resource_id']] ?? 0;
                $bottleneck = min($bottleneck, min($available / $desired, 1.0));
            }
        }
        if ($bottleneck <= 0) continue;

        // Consume inputs
        foreach ($inputs as $inp) {
            $consumed = $inp['amount_per_level'] * $level * $efficiency * $bottleneck / TICKS_PER_HOUR;
            if ($consumed <= 0) continue;
            $factoryConsumed += $consumed;
            $stocks[$tid][$inp['resource_id']] = max(0, ($stocks[$tid][$inp['resource_id']] ?? 0) - $consumed);
            $rid = $inp['resource_id'];
            $stmt = $conn->prepare("UPDATE town_resources SET stock = GREATEST(0, stock - ?) WHERE town_id = ? AND resource_id = ?");
            $stmt->bind_param("dii", $consumed, $tid, $rid);
            $stmt->execute();
            $stmt->close();
        }

        // Produce output
        $outputPerLevel = (float)$f['output_per_level'];
        $produced = $outputPerLevel * $level * $efficiency * $bottleneck / TICKS_PER_HOUR;
        if ($produced > 0) {
            $factoryProduced += $produced;
            $outRid = (int)$f['output_resource_id'];
            $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
            $stmt->bind_param("dii", $produced, $tid, $outRid);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 7: Process vehicle arrivals
    // ═══════════════════════════════════════════════
    $vehiclesArrived = 0;
    $vehiclesScrapped = 0;

    // Road damage factors per 1km (damage % per km of travel)
    $roadDamageFactor = [
        'mud' => 0.0200,    // 2.0% per 100km
        'gravel' => 0.0120, // 1.2% per 100km
        'asphalt' => 0.0060,// 0.6% per 100km
        'dual' => 0.0030,   // 0.3% per 100km
    ];

    // Check if health column exists on vehicles table
    $hasHealthCol = false;
    $hcCheck = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'health'");
    if ($hcCheck && $hcCheck->num_rows > 0) $hasHealthCol = true;

    // Check if tow_vehicle_id column exists on vehicle_trips
    $hasTowCol = false;
    $towColCheck = $conn->query("SHOW COLUMNS FROM vehicle_trips LIKE 'tow_vehicle_id'");
    if ($towColCheck && $towColCheck->num_rows > 0) $hasTowCol = true;

    $tripsResult = $conn->query("
        SELECT vt.id as trip_id, vt.vehicle_id, vt.from_town_id, vt.to_town_id,
               vt.return_empty, vt.distance_km, vt.fuel_used,
               v.vehicle_type_id" . ($hasHealthCol ? ", v.health" : "") .
               ($hasTowCol ? ", vt.tow_vehicle_id" : "") . "
        FROM vehicle_trips vt
        JOIN vehicles v ON vt.vehicle_id = v.id
        WHERE vt.arrived = 0 AND vt.eta_at <= NOW()
    ");
    if ($tripsResult) {
        while ($trip = $tripsResult->fetch_assoc()) {
            $tripId = (int)$trip['trip_id'];
            $vehId = (int)$trip['vehicle_id'];
            $toTown = (int)$trip['to_town_id'];
            $fromTown = (int)$trip['from_town_id'];
            $towedVehicleId = $hasTowCol ? ((int)($trip['tow_vehicle_id'] ?? 0)) : 0;
            $isTowTrip = $towedVehicleId > 0;

            // Deliver cargo from vehicle_trip_cargo table
            $cargoRes = $conn->query("SELECT resource_id, amount FROM vehicle_trip_cargo WHERE trip_id = $tripId");
            if ($cargoRes) {
                while ($cargo = $cargoRes->fetch_assoc()) {
                    $crid = (int)$cargo['resource_id'];
                    $camt = (float)$cargo['amount'];
                    if ($crid > 0 && $camt > 0) {
                        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
                        $stmt->bind_param("dii", $camt, $toTown, $crid);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            // Mark trip as arrived
            $conn->query("UPDATE vehicle_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");

            // Apply wear-and-tear damage based on distance and road quality
            if ($hasHealthCol && !$isTowTrip) {
                $t1d = min($fromTown, $toTown); $t2d = max($fromTown, $toTown);
                $roadRow = $conn->query("SELECT road_type FROM roads WHERE town_id_1 = $t1d AND town_id_2 = $t2d")->fetch_assoc();
                $roadType = $roadRow ? $roadRow['road_type'] : 'mud';
                $dmgPerKm = $roadDamageFactor[$roadType] ?? 0.02;
                $dist = (float)$trip['distance_km'];
                $damage = round($dist * $dmgPerKm, 2);
                $currentHealth = isset($trip['health']) ? (float)$trip['health'] : 100.0;
                $newHealth = max(0, round($currentHealth - $damage, 2));

                $conn->query("UPDATE vehicles SET health = $newHealth WHERE id = $vehId");

                // Scrap vehicle if health drops below 10%
                if ($newHealth < 10.0) {
                    $conn->query("UPDATE vehicles SET status = 'scrapped', town_id = $toTown WHERE id = $vehId");
                    $vehiclesScrapped++;
                    $vehiclesArrived++;
                    continue; // Skip return trip — vehicle is scrapped
                }
            }

            // Handle tow trip: deliver towed vehicle to destination
            if ($isTowTrip) {
                // Move towed vehicle to destination, set idle
                $conn->query("UPDATE vehicles SET town_id = $toTown, status = 'idle' WHERE id = $towedVehicleId");
                // Move tow truck to destination, set idle
                $conn->query("UPDATE vehicles SET town_id = $toTown, status = 'idle' WHERE id = $vehId");
                // Apply minor wear to tow truck only (half normal)
                if ($hasHealthCol) {
                    $t1d = min($fromTown, $toTown); $t2d = max($fromTown, $toTown);
                    $roadRow = $conn->query("SELECT road_type FROM roads WHERE town_id_1 = $t1d AND town_id_2 = $t2d")->fetch_assoc();
                    $roadType = $roadRow ? $roadRow['road_type'] : 'mud';
                    $dmgPerKm = ($roadDamageFactor[$roadType] ?? 0.02) * 0.5;
                    $dist = (float)$trip['distance_km'];
                    $damage = round($dist * $dmgPerKm, 2);
                    $conn->query("UPDATE vehicles SET health = GREATEST(10, health - $damage) WHERE id = $vehId");
                }
                $vehiclesArrived++;
                continue; // Tow trips don't have return legs
            }

            // Handle return trip
            if ($trip['return_empty']) {
                // Get road speed for return
                $t1 = min($fromTown, $toTown); $t2 = max($fromTown, $toTown);
                $rdRow = $conn->query("SELECT COALESCE(r.speed_limit, 30) as speed_limit FROM roads r WHERE r.town_id_1 = $t1 AND r.town_id_2 = $t2")->fetch_assoc();
                $speedLimit = $rdRow ? (int)$rdRow['speed_limit'] : 30;
                $vtRow = $conn->query("SELECT max_speed_kmh FROM vehicle_types WHERE id = " . (int)$trip['vehicle_type_id'])->fetch_assoc();
                $maxSpeed = $vtRow ? (int)$vtRow['max_speed_kmh'] : 60;
                $speed = min($maxSpeed, $speedLimit);
                $dist = (float)$trip['distance_km'];
                $travelSecs = (int)(($dist / $speed) * 3600);
                $retEta = date('Y-m-d H:i:s', time() + $travelSecs);
                $retDep = date('Y-m-d H:i:s');
                $halfFuel = (float)$trip['fuel_used'] / 2;

                $stmt = $conn->prepare("INSERT INTO vehicle_trips (vehicle_id, from_town_id, to_town_id, cargo_resource_id, cargo_amount, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty, arrived) VALUES (?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, 0, 0)");
                $stmt->bind_param("iisssdid", $vehId, $toTown, $fromTown, $retDep, $retEta, $dist, $speed, $halfFuel);
                $stmt->execute();
                $stmt->close();
                // Vehicle stays in_transit
            } else {
                // Move vehicle to destination town, set idle
                $conn->query("UPDATE vehicles SET town_id = $toTown, status = 'idle' WHERE id = $vehId");
            }
            $vehiclesArrived++;
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 8: Process train arrivals
    // ═══════════════════════════════════════════════
    $trainsArrived = 0;
    $trainTripsResult = $conn->query("
        SELECT tt.id as trip_id, tt.train_id, tt.from_town_id, tt.to_town_id,
               tt.return_empty, tt.distance_km, tt.fuel_used, tt.fuel_resource_id
        FROM train_trips tt
        WHERE tt.arrived = 0 AND tt.eta_at <= NOW()
    ");
    if ($trainTripsResult) {
        while ($ttrip = $trainTripsResult->fetch_assoc()) {
            $tripId = (int)$ttrip['trip_id'];
            $trainId = (int)$ttrip['train_id'];
            $toTown = (int)$ttrip['to_town_id'];
            $fromTown = (int)$ttrip['from_town_id'];

            // Deliver cargo from train_trip_cargo
            $tCargoRes = $conn->query("SELECT resource_id, SUM(amount) as total FROM train_trip_cargo WHERE trip_id = $tripId GROUP BY resource_id");
            if ($tCargoRes) {
                while ($cargo = $tCargoRes->fetch_assoc()) {
                    $crid = (int)$cargo['resource_id'];
                    $camt = (float)$cargo['total'];
                    if ($crid > 0 && $camt > 0) {
                        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
                        $stmt->bind_param("dii", $camt, $toTown, $crid);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            // Mark trip as arrived
            $conn->query("UPDATE train_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");

            if ($ttrip['return_empty']) {
                // Get rail speed for return
                $t1 = min($fromTown, $toTown); $t2 = max($fromTown, $toTown);
                $rlRow = $conn->query("SELECT speed_limit FROM rail_lines WHERE town_id_1 = $t1 AND town_id_2 = $t2")->fetch_assoc();
                $railSpeed = $rlRow ? (int)$rlRow['speed_limit'] : 60;

                // Get loco speed
                $locoSpeed = $conn->query("SELECT lt.max_speed_kmh FROM trains t JOIN locomotives l ON t.locomotive_id = l.id JOIN locomotive_types lt ON l.locomotive_type_id = lt.id WHERE t.id = $trainId")->fetch_assoc();
                $maxSpeed = $locoSpeed ? (int)$locoSpeed['max_speed_kmh'] : 60;
                $speed = min($maxSpeed, $railSpeed);
                $dist = (float)$ttrip['distance_km'];
                $travelSecs = (int)(($dist / max($speed, 1)) * 3600);
                $retEta = date('Y-m-d H:i:s', time() + $travelSecs);
                $retDep = date('Y-m-d H:i:s');
                $halfFuel = (float)$ttrip['fuel_used'] / 2;
                $fuelResId = $ttrip['fuel_resource_id'] ? (int)$ttrip['fuel_resource_id'] : 'NULL';

                $conn->query("INSERT INTO train_trips (train_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, fuel_resource_id, return_empty, arrived)
                    VALUES ($trainId, $toTown, $fromTown, '$retDep', '$retEta', $dist, $speed, $halfFuel, $fuelResId, 0, 0)");
                // Train stays in_transit
            } else {
                // Move train and all rolling stock to destination
                $trainRow = $conn->query("SELECT locomotive_id FROM trains WHERE id = $trainId")->fetch_assoc();
                $conn->query("UPDATE trains SET town_id = $toTown, status = 'idle' WHERE id = $trainId");
                if ($trainRow) $conn->query("UPDATE locomotives SET town_id = $toTown, status = 'idle' WHERE id = {$trainRow['locomotive_id']}");
                $conn->query("UPDATE wagons SET town_id = $toTown WHERE train_id = $trainId");
            }
            $trainsArrived++;
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 9: Process auto-transport rules
    // ═══════════════════════════════════════════════
    $autoDispatched = 0;

    // Check if auto_transport table exists
    $atTableCheck = $conn->query("SHOW TABLES LIKE 'auto_transport'");
    if ($atTableCheck && $atTableCheck->num_rows > 0) {

    $atRules = $conn->query("
        SELECT at.*, wp.resource_name, wp.resource_type,
               t1.name as from_town_name, t2.name as to_town_name
        FROM auto_transport at
        JOIN world_prices wp ON at.resource_id = wp.id
        JOIN towns t1 ON at.from_town_id = t1.id
        JOIN towns t2 ON at.to_town_id = t2.id
        WHERE at.enabled = 1
        ORDER BY at.id
    ");

    if ($atRules) {
        // Get fuel resource ID once
        $fuelRow = $conn->query("SELECT id FROM world_prices WHERE resource_name = 'Fuel'")->fetch_assoc();
        $fuelResId = $fuelRow ? (int)$fuelRow['id'] : 0;

        while ($rule = $atRules->fetch_assoc()) {
            $ruleId = (int)$rule['id'];
            $fromTown = (int)$rule['from_town_id'];
            $toTown = (int)$rule['to_town_id'];
            $resId = (int)$rule['resource_id'];
            $threshold = (float)$rule['threshold'];
            $sendAmount = (float)$rule['send_amount'];
            $transportType = $rule['transport_type'];
            $returnEmpty = (int)$rule['return_empty'];

            // Check stock in origin town
            $stockRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $fromTown AND resource_id = $resId")->fetch_assoc();
            $stock = $stockRow ? (float)$stockRow['stock'] : 0;
            if ($stock < $threshold) continue;

            // Get route distance and speed
            $rt1 = min($fromTown, $toTown);
            $rt2 = max($fromTown, $toTown);

            if ($transportType === 'truck') {
                // Find an idle vehicle in origin town that can carry this resource type
                $resType = $rule['resource_type']; // Aggregates, Liquids, Open Storage, Warehouse
                $idleVeh = $conn->query("
                    SELECT v.id, vt.max_capacity, vt.max_speed_kmh, vt.fuel_per_km, vt.cargo_class
                    FROM vehicles v
                    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                    WHERE v.town_id = $fromTown
                      AND v.faction = '{$rule['faction']}'
                      AND v.status = 'idle'
                      AND (vt.cargo_class = '$resType' OR vt.cargo_class = 'Warehouse')
                    LIMIT 1
                ");
                if (!$idleVeh || $idleVeh->num_rows === 0) continue;
                $veh = $idleVeh->fetch_assoc();
                $vehicleId = (int)$veh['id'];
                $maxCap = (float)$veh['max_capacity'];

                // Road info
                $rdRow = $conn->query("SELECT td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit
                    FROM town_distances td
                    LEFT JOIN roads r ON r.town_id_1 = $rt1 AND r.town_id_2 = $rt2
                    WHERE (td.town_id_1 = $fromTown AND td.town_id_2 = $toTown)
                       OR (td.town_id_1 = $toTown AND td.town_id_2 = $fromTown)
                    LIMIT 1")->fetch_assoc();
                if (!$rdRow) continue;

                $distance = (float)$rdRow['distance_km'];
                $speedLimit = (int)$rdRow['speed_limit'];
                $speed = min((int)$veh['max_speed_kmh'], $speedLimit);
                $fuelPerKm = (float)$veh['fuel_per_km'];
                $fuelNeeded = $distance * $fuelPerKm;
                if ($returnEmpty) $fuelNeeded *= 2;

                // Check fuel
                if ($fuelResId > 0) {
                    $fuelStockRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $fromTown AND resource_id = $fuelResId")->fetch_assoc();
                    $fuelStock = $fuelStockRow ? (float)$fuelStockRow['stock'] : 0;
                    if ($fuelStock < $fuelNeeded) continue;
                }

                // Calculate actual send amount (min of: rule amount, vehicle capacity, stock)
                $actualSend = min($sendAmount, $maxCap, $stock);
                if ($actualSend <= 0) continue;

                // Calculate travel time
                $travelSecs = (int)(($distance / max($speed, 1)) * 3600);
                $etaAt = date('Y-m-d H:i:s', time() + $travelSecs);
                $departedAt = date('Y-m-d H:i:s');

                // Deduct fuel
                if ($fuelResId > 0) {
                    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $fromTown AND resource_id = $fuelResId");
                }

                // Deduct cargo
                $conn->query("UPDATE town_resources SET stock = stock - $actualSend WHERE town_id = $fromTown AND resource_id = $resId");

                // Create trip
                $tripStmt = $conn->prepare("INSERT INTO vehicle_trips (vehicle_id, from_town_id, to_town_id, cargo_resource_id, cargo_amount, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $tripStmt->bind_param("iiiidssdidi", $vehicleId, $fromTown, $toTown, $resId, $actualSend, $departedAt, $etaAt, $distance, $speed, $fuelNeeded, $returnEmpty);
                $tripStmt->execute();
                $tripId = $tripStmt->insert_id;
                $tripStmt->close();

                // Create cargo record
                $cargoStmt = $conn->prepare("INSERT INTO vehicle_trip_cargo (trip_id, resource_id, amount) VALUES (?, ?, ?)");
                $cargoStmt->bind_param("iid", $tripId, $resId, $actualSend);
                $cargoStmt->execute();
                $cargoStmt->close();

                // Update vehicle status
                $conn->query("UPDATE vehicles SET status = 'in_transit' WHERE id = $vehicleId");

                // Log dispatch
                $logStmt = $conn->prepare("INSERT INTO auto_transport_log (auto_transport_id, vehicle_id, resource_name, amount_sent, from_town, to_town) VALUES (?, ?, ?, ?, ?, ?)");
                $logStmt->bind_param("iisdss", $ruleId, $vehicleId, $rule['resource_name'], $actualSend, $rule['from_town_name'], $rule['to_town_name']);
                $logStmt->execute();
                $logStmt->close();

                // Update rule last_dispatched_at
                $conn->query("UPDATE auto_transport SET last_dispatched_at = NOW() WHERE id = $ruleId");
                $autoDispatched++;

            } elseif ($transportType === 'train') {
                // Find an idle train in origin town with appropriate wagon
                $resType = $rule['resource_type'];
                $idleTrain = $conn->query("
                    SELECT t.id as train_id, l.id as loco_id,
                           lt.max_speed_kmh, lt.fuel_per_km, lt.fuel_resource_id,
                           lt.propulsion
                    FROM trains t
                    JOIN locomotives l ON t.locomotive_id = l.id
                    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
                    WHERE t.town_id = $fromTown
                      AND t.faction = '{$rule['faction']}'
                      AND t.status = 'idle'
                    LIMIT 1
                ");
                if (!$idleTrain || $idleTrain->num_rows === 0) continue;
                $train = $idleTrain->fetch_assoc();
                $trainId = (int)$train['train_id'];

                // Find total cargo capacity of matching wagons
                $wagonCap = $conn->query("
                    SELECT SUM(wt.max_capacity) as total_cap
                    FROM train_consist tc
                    JOIN wagons w ON tc.wagon_id = w.id
                    JOIN wagon_types wt ON w.wagon_type_id = wt.id
                    WHERE tc.train_id = $trainId
                      AND (wt.cargo_class = '$resType' OR wt.cargo_class = 'Warehouse')
                      AND wt.wagon_class = 'cargo'
                ")->fetch_assoc();
                $totalWagonCap = $wagonCap ? (float)$wagonCap['total_cap'] : 0;
                if ($totalWagonCap <= 0) continue;

                // Rail info
                $rlRow = $conn->query("SELECT td.distance_km, COALESCE(rl.speed_limit, 60) as speed_limit
                    FROM town_distances td
                    LEFT JOIN rail_lines rl ON rl.town_id_1 = $rt1 AND rl.town_id_2 = $rt2
                    WHERE (td.town_id_1 = $fromTown AND td.town_id_2 = $toTown)
                       OR (td.town_id_1 = $toTown AND td.town_id_2 = $fromTown)
                    LIMIT 1")->fetch_assoc();
                if (!$rlRow) continue;

                $distance = (float)$rlRow['distance_km'];
                $railSpeed = (int)$rlRow['speed_limit'];
                $speed = min((int)$train['max_speed_kmh'], $railSpeed);
                $fuelPerKm = (float)$train['fuel_per_km'];
                $trainFuelResId = $train['fuel_resource_id'] ? (int)$train['fuel_resource_id'] : 0;
                $fuelNeeded = $distance * $fuelPerKm;
                if ($returnEmpty) $fuelNeeded *= 2;

                // Check fuel (electric trains use 0 fuel)
                if ($trainFuelResId > 0 && $fuelNeeded > 0) {
                    $fuelStockRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $fromTown AND resource_id = $trainFuelResId")->fetch_assoc();
                    $fuelStock = $fuelStockRow ? (float)$fuelStockRow['stock'] : 0;
                    if ($fuelStock < $fuelNeeded) continue;
                }

                // Calculate actual send
                $actualSend = min($sendAmount, $totalWagonCap, $stock);
                if ($actualSend <= 0) continue;

                $travelSecs = (int)(($distance / max($speed, 1)) * 3600);
                $etaAt = date('Y-m-d H:i:s', time() + $travelSecs);
                $departedAt = date('Y-m-d H:i:s');

                // Deduct fuel
                if ($trainFuelResId > 0 && $fuelNeeded > 0) {
                    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $fromTown AND resource_id = $trainFuelResId");
                }

                // Deduct cargo
                $conn->query("UPDATE town_resources SET stock = stock - $actualSend WHERE town_id = $fromTown AND resource_id = $resId");

                // Create train trip
                $fuelResVal = $trainFuelResId > 0 ? $trainFuelResId : 'NULL';
                $conn->query("INSERT INTO train_trips (train_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, fuel_resource_id, return_empty, arrived)
                    VALUES ($trainId, $fromTown, $toTown, '$departedAt', '$etaAt', $distance, $speed, $fuelNeeded, $fuelResVal, $returnEmpty, 0)");
                $tripId = $conn->insert_id;

                // Distribute cargo across wagons
                $remaining = $actualSend;
                $wagonRes = $conn->query("
                    SELECT w.id as wagon_id, wt.max_capacity
                    FROM train_consist tc
                    JOIN wagons w ON tc.wagon_id = w.id
                    JOIN wagon_types wt ON w.wagon_type_id = wt.id
                    WHERE tc.train_id = $trainId
                      AND (wt.cargo_class = '$resType' OR wt.cargo_class = 'Warehouse')
                      AND wt.wagon_class = 'cargo'
                    ORDER BY tc.position
                ");
                if ($wagonRes) {
                    while ($wg = $wagonRes->fetch_assoc()) {
                        if ($remaining <= 0) break;
                        $load = min($remaining, (float)$wg['max_capacity']);
                        $wgId = (int)$wg['wagon_id'];
                        $conn->query("INSERT INTO train_trip_cargo (trip_id, wagon_id, resource_id, amount) VALUES ($tripId, $wgId, $resId, $load)");
                        $remaining -= $load;
                    }
                }

                // Update train/loco status
                $conn->query("UPDATE trains SET status = 'in_transit' WHERE id = $trainId");
                $conn->query("UPDATE locomotives SET status = 'in_transit' WHERE id = " . (int)$train['loco_id']);

                // Log dispatch
                $logStmt = $conn->prepare("INSERT INTO auto_transport_log (auto_transport_id, train_id, resource_name, amount_sent, from_town, to_town) VALUES (?, ?, ?, ?, ?, ?)");
                $logStmt->bind_param("iisdss", $ruleId, $trainId, $rule['resource_name'], $actualSend, $rule['from_town_name'], $rule['to_town_name']);
                $logStmt->execute();
                $logStmt->close();

                $conn->query("UPDATE auto_transport SET last_dispatched_at = NOW() WHERE id = $ruleId");
                $autoDispatched++;
            }
        }
    }

    } // end auto_transport table check

    // ═══════════════════════════════════════════════
    // PHASE 10: Process auto-trade rules (buy/sell at customs)
    // ═══════════════════════════════════════════════
    $autoTraded = 0;

    $atTradeCheck = $conn->query("SHOW TABLES LIKE 'auto_trade'");
    if ($atTradeCheck && $atTradeCheck->num_rows > 0) {

    $tradeRules = $conn->query("
        SELECT at.*, wp.resource_name, wp.resource_type,
               wp.buy_price, wp.sell_price, wp.base_buy_price, wp.base_sell_price
        FROM auto_trade at
        JOIN world_prices wp ON at.resource_id = wp.id
        WHERE at.enabled = 1
        ORDER BY at.id
    ");

    if ($tradeRules) {
        while ($tr = $tradeRules->fetch_assoc()) {
            $trId = (int)$tr['id'];
            $faction = $tr['faction'];
            $resId = (int)$tr['resource_id'];
            $tradeAction = $tr['trade_action'];
            $threshold = (float)$tr['threshold'];
            $orderAmount = (float)$tr['order_amount'];
            $minBalance = (float)$tr['min_balance'];

            // Get customs house
            $cName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
            $cRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($cName) . "'")->fetch_assoc();
            if (!$cRow) continue;
            $customsId = (int)$cRow['id'];

            // Current customs stock
            $csRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $customsId AND resource_id = $resId")->fetch_assoc();
            $customsStock = $csRow ? (float)$csRow['stock'] : 0;

            // Faction balance
            $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '$faction'")->fetch_assoc();
            $balance = $balRow ? (float)$balRow['points'] : 0;

            if ($tradeAction === 'buy') {
                // Trigger: customs stock < threshold
                if ($customsStock >= $threshold) continue;

                $price = (float)$tr['buy_price'];
                $totalCost = $price * $orderAmount;

                // Check balance (must keep min_balance)
                if ($balance - $totalCost < $minBalance) continue;

                // Execute buy
                $conn->query("UPDATE faction_balance SET points = points - $totalCost WHERE faction = '$faction'");

                // Add to customs
                $conn->query("UPDATE town_resources SET stock = stock + $orderAmount WHERE town_id = $customsId AND resource_id = $resId");
                if ($conn->affected_rows === 0) {
                    $conn->query("INSERT INTO town_resources (town_id, resource_id, stock) VALUES ($customsId, $resId, $orderAmount)");
                }

                // Update volume + prices
                $conn->query("UPDATE price_volume SET total_bought = total_bought + $orderAmount WHERE resource_id = $resId");
                $volRow = $conn->query("SELECT total_bought, total_sold FROM price_volume WHERE resource_id = $resId")->fetch_assoc();
                if ($volRow) {
                    $baseBuy = (float)$tr['base_buy_price'];
                    $baseSell = (float)$tr['base_sell_price'];
                    $mult = pow(1.001, (float)$volRow['total_bought'] / 100) * pow(0.999, (float)$volRow['total_sold'] / 100);
                    $newBuy = round($baseBuy * $mult, 4);
                    $newSell = round($baseSell * $mult, 4);
                    $conn->query("UPDATE world_prices SET buy_price = $newBuy, sell_price = $newSell WHERE id = $resId");
                }

                // Trade log
                $conn->query("INSERT INTO trade_log (faction, resource_id, action, quantity, price_per_unit, total_cost) VALUES ('$faction', $resId, 'buy', $orderAmount, $price, $totalCost)");

                // Auto-trade log
                $logStmt = $conn->prepare("INSERT INTO auto_trade_log (log_type, rule_id, faction, resource_name, action_taken, amount, points_spent) VALUES ('trade', ?, ?, ?, ?, ?, ?)");
                $actionDesc = "Auto-bought at $cName";
                $logStmt->bind_param("isssdd", $trId, $faction, $tr['resource_name'], $actionDesc, $orderAmount, $totalCost);
                $logStmt->execute();
                $logStmt->close();

                $conn->query("UPDATE auto_trade SET last_executed_at = NOW() WHERE id = $trId");
                $autoTraded++;

            } elseif ($tradeAction === 'sell') {
                // Trigger: customs stock >= threshold
                if ($customsStock < $threshold) continue;

                // Sell the order_amount (or what's available)
                $sellQty = min($orderAmount, $customsStock);
                $price = (float)$tr['sell_price'];
                $totalRevenue = $price * $sellQty;

                // Deduct from customs
                $conn->query("UPDATE town_resources SET stock = stock - $sellQty WHERE town_id = $customsId AND resource_id = $resId");

                // Add points
                $conn->query("UPDATE faction_balance SET points = points + $totalRevenue WHERE faction = '$faction'");

                // Update volume + prices
                $conn->query("UPDATE price_volume SET total_sold = total_sold + $sellQty WHERE resource_id = $resId");
                $volRow = $conn->query("SELECT total_bought, total_sold FROM price_volume WHERE resource_id = $resId")->fetch_assoc();
                if ($volRow) {
                    $baseBuy = (float)$tr['base_buy_price'];
                    $baseSell = (float)$tr['base_sell_price'];
                    $mult = pow(1.001, (float)$volRow['total_bought'] / 100) * pow(0.999, (float)$volRow['total_sold'] / 100);
                    $newBuy = round($baseBuy * $mult, 4);
                    $newSell = round($baseSell * $mult, 4);
                    $conn->query("UPDATE world_prices SET buy_price = $newBuy, sell_price = $newSell WHERE id = $resId");
                }

                // Trade log
                $conn->query("INSERT INTO trade_log (faction, resource_id, action, quantity, price_per_unit, total_cost) VALUES ('$faction', $resId, 'sell', $sellQty, $price, $totalRevenue)");

                // Auto-trade log
                $logStmt = $conn->prepare("INSERT INTO auto_trade_log (log_type, rule_id, faction, resource_name, action_taken, amount, points_spent) VALUES ('trade', ?, ?, ?, ?, ?, ?)");
                $actionDesc = "Auto-sold at $cName";
                $negRevenue = -$totalRevenue;
                $logStmt->bind_param("isssdd", $trId, $faction, $tr['resource_name'], $actionDesc, $sellQty, $negRevenue);
                $logStmt->execute();
                $logStmt->close();

                $conn->query("UPDATE auto_trade SET last_executed_at = NOW() WHERE id = $trId");
                $autoTraded++;
            }
        }
    }

    } // end auto_trade table check

    // ═══════════════════════════════════════════════
    // PHASE 11: Process auto-resupply rules
    // (town low on resource → buy at customs → dispatch vehicle)
    // ═══════════════════════════════════════════════
    $autoResupplied = 0;

    $arTableCheck = $conn->query("SHOW TABLES LIKE 'auto_resupply'");
    if ($arTableCheck && $arTableCheck->num_rows > 0) {

    // Get fuel resource ID
    $fuelIdRow = $conn->query("SELECT id FROM world_prices WHERE resource_name = 'Fuel'")->fetch_assoc();
    $fuelResIdGlobal = $fuelIdRow ? (int)$fuelIdRow['id'] : 0;

    $resupplyRules = $conn->query("
        SELECT ar.*, wp.resource_name, wp.resource_type,
               wp.buy_price, wp.base_buy_price, wp.base_sell_price,
               t.name as town_name
        FROM auto_resupply ar
        JOIN world_prices wp ON ar.resource_id = wp.id
        JOIN towns t ON ar.town_id = t.id
        WHERE ar.enabled = 1
        ORDER BY ar.id
    ");

    if ($resupplyRules) {
        while ($rs = $resupplyRules->fetch_assoc()) {
            $rsId = (int)$rs['id'];
            $faction = $rs['faction'];
            $townId = (int)$rs['town_id'];
            $resId = (int)$rs['resource_id'];
            $lowThreshold = (float)$rs['low_threshold'];
            $orderAmount = (float)$rs['order_amount'];
            $minBalance = (float)$rs['min_balance'];
            $transportType = $rs['transport_type'];
            $resType = $rs['resource_type'];

            // Check town stock
            $stockRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = $resId")->fetch_assoc();
            $townStock = $stockRow ? (float)$stockRow['stock'] : 0;
            if ($townStock >= $lowThreshold) continue;

            // Get customs house
            $cName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
            $cRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($cName) . "'")->fetch_assoc();
            if (!$cRow) continue;
            $customsId = (int)$cRow['id'];

            // Route from customs to town
            $rt1 = min($customsId, $townId);
            $rt2 = max($customsId, $townId);

            if ($transportType === 'truck') {
                // Find idle vehicle at customs
                $idleVeh = $conn->query("
                    SELECT v.id, vt.max_capacity, vt.max_speed_kmh, vt.fuel_per_km, vt.cargo_class
                    FROM vehicles v
                    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                    WHERE v.town_id = $customsId
                      AND v.faction = '$faction'
                      AND v.status = 'idle'
                      AND (vt.cargo_class = '$resType' OR vt.cargo_class = 'Warehouse')
                    LIMIT 1
                ");
                if (!$idleVeh || $idleVeh->num_rows === 0) continue;
                $veh = $idleVeh->fetch_assoc();
                $vehicleId = (int)$veh['id'];
                $maxCap = (float)$veh['max_capacity'];

                // Road distance + speed
                $rdRow = $conn->query("SELECT td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit
                    FROM town_distances td
                    LEFT JOIN roads r ON r.town_id_1 = $rt1 AND r.town_id_2 = $rt2
                    WHERE (td.town_id_1 = $customsId AND td.town_id_2 = $townId)
                       OR (td.town_id_1 = $townId AND td.town_id_2 = $customsId)
                    LIMIT 1")->fetch_assoc();
                if (!$rdRow) continue;

                $distance = (float)$rdRow['distance_km'];
                $speed = min((int)$veh['max_speed_kmh'], (int)$rdRow['speed_limit']);
                $fuelNeeded = $distance * (float)$veh['fuel_per_km'] * 2; // return empty

                // Check fuel at customs
                if ($fuelResIdGlobal > 0) {
                    $fuelStk = $conn->query("SELECT stock FROM town_resources WHERE town_id = $customsId AND resource_id = $fuelResIdGlobal")->fetch_assoc();
                    if (!$fuelStk || (float)$fuelStk['stock'] < $fuelNeeded) continue;
                }

                // Calculate actual amount to buy (capped by vehicle capacity)
                $actualAmount = min($orderAmount, $maxCap);

                // Check faction balance
                $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '$faction'")->fetch_assoc();
                $balance = $balRow ? (float)$balRow['points'] : 0;
                $buyPrice = (float)$rs['buy_price'];
                $totalCost = $buyPrice * $actualAmount;
                if ($balance - $totalCost < $minBalance) continue;

                // ── Execute: buy at customs ──
                $conn->query("UPDATE faction_balance SET points = points - $totalCost WHERE faction = '$faction'");
                // We add to customs then immediately deduct for cargo loading
                // Update price volume
                $conn->query("UPDATE price_volume SET total_bought = total_bought + $actualAmount WHERE resource_id = $resId");
                $volRow = $conn->query("SELECT total_bought, total_sold FROM price_volume WHERE resource_id = $resId")->fetch_assoc();
                if ($volRow) {
                    $baseBuy = (float)$rs['base_buy_price'];
                    $baseSell = (float)$rs['base_sell_price'];
                    $mult = pow(1.001, (float)$volRow['total_bought'] / 100) * pow(0.999, (float)$volRow['total_sold'] / 100);
                    $conn->query("UPDATE world_prices SET buy_price = " . round($baseBuy * $mult, 4) . ", sell_price = " . round($baseSell * $mult, 4) . " WHERE id = $resId");
                }
                $conn->query("INSERT INTO trade_log (faction, resource_id, action, quantity, price_per_unit, total_cost) VALUES ('$faction', $resId, 'buy', $actualAmount, $buyPrice, $totalCost)");

                // ── Deduct fuel from customs ──
                if ($fuelResIdGlobal > 0) {
                    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $customsId AND resource_id = $fuelResIdGlobal");
                }

                // ── Dispatch vehicle (cargo goes straight from purchase to truck, no customs stock change needed) ──
                $travelSecs = (int)(($distance / max($speed, 1)) * 3600);
                $etaAt = date('Y-m-d H:i:s', time() + $travelSecs);
                $departedAt = date('Y-m-d H:i:s');
                $returnEmpty = 1;

                $tripStmt = $conn->prepare("INSERT INTO vehicle_trips (vehicle_id, from_town_id, to_town_id, cargo_resource_id, cargo_amount, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $tripStmt->bind_param("iiiidssdidi", $vehicleId, $customsId, $townId, $resId, $actualAmount, $departedAt, $etaAt, $distance, $speed, $fuelNeeded, $returnEmpty);
                $tripStmt->execute();
                $tripId = $tripStmt->insert_id;
                $tripStmt->close();

                $cargoStmt = $conn->prepare("INSERT INTO vehicle_trip_cargo (trip_id, resource_id, amount) VALUES (?, ?, ?)");
                $cargoStmt->bind_param("iid", $tripId, $resId, $actualAmount);
                $cargoStmt->execute();
                $cargoStmt->close();

                $conn->query("UPDATE vehicles SET status = 'in_transit' WHERE id = $vehicleId");

                // Log
                $logStmt = $conn->prepare("INSERT INTO auto_trade_log (log_type, rule_id, faction, resource_name, action_taken, amount, points_spent, vehicle_id) VALUES ('resupply', ?, ?, ?, ?, ?, ?, ?)");
                $actionDesc = "Bought & shipped to " . $rs['town_name'];
                $logStmt->bind_param("isssddi", $rsId, $faction, $rs['resource_name'], $actionDesc, $actualAmount, $totalCost, $vehicleId);
                $logStmt->execute();
                $logStmt->close();

                $conn->query("UPDATE auto_resupply SET last_executed_at = NOW() WHERE id = $rsId");
                $autoResupplied++;

            } elseif ($transportType === 'train') {
                // Find idle train at customs
                $idleTrain = $conn->query("
                    SELECT t.id as train_id, l.id as loco_id,
                           lt.max_speed_kmh, lt.fuel_per_km, lt.fuel_resource_id, lt.propulsion
                    FROM trains t
                    JOIN locomotives l ON t.locomotive_id = l.id
                    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
                    WHERE t.town_id = $customsId
                      AND t.faction = '$faction'
                      AND t.status = 'idle'
                    LIMIT 1
                ");
                if (!$idleTrain || $idleTrain->num_rows === 0) continue;
                $train = $idleTrain->fetch_assoc();
                $trainId = (int)$train['train_id'];

                // Wagon capacity
                $wagonCap = $conn->query("
                    SELECT SUM(wt.max_capacity) as total_cap
                    FROM train_consist tc
                    JOIN wagons w ON tc.wagon_id = w.id
                    JOIN wagon_types wt ON w.wagon_type_id = wt.id
                    WHERE tc.train_id = $trainId
                      AND (wt.cargo_class = '$resType' OR wt.cargo_class = 'Warehouse')
                      AND wt.wagon_class = 'cargo'
                ")->fetch_assoc();
                $totalWagonCap = $wagonCap ? (float)$wagonCap['total_cap'] : 0;
                if ($totalWagonCap <= 0) continue;

                // Rail distance
                $rlRow = $conn->query("SELECT td.distance_km, COALESCE(rl.speed_limit, 60) as speed_limit
                    FROM town_distances td
                    LEFT JOIN rail_lines rl ON rl.town_id_1 = $rt1 AND rl.town_id_2 = $rt2
                    WHERE (td.town_id_1 = $customsId AND td.town_id_2 = $townId)
                       OR (td.town_id_1 = $townId AND td.town_id_2 = $customsId)
                    LIMIT 1")->fetch_assoc();
                if (!$rlRow) continue;

                $distance = (float)$rlRow['distance_km'];
                $speed = min((int)$train['max_speed_kmh'], (int)$rlRow['speed_limit']);
                $trainFuelResId = $train['fuel_resource_id'] ? (int)$train['fuel_resource_id'] : 0;
                $fuelNeeded = $distance * (float)$train['fuel_per_km'] * 2;

                if ($trainFuelResId > 0 && $fuelNeeded > 0) {
                    $fuelStk = $conn->query("SELECT stock FROM town_resources WHERE town_id = $customsId AND resource_id = $trainFuelResId")->fetch_assoc();
                    if (!$fuelStk || (float)$fuelStk['stock'] < $fuelNeeded) continue;
                }

                $actualAmount = min($orderAmount, $totalWagonCap);

                // Check balance
                $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '$faction'")->fetch_assoc();
                $balance = $balRow ? (float)$balRow['points'] : 0;
                $buyPrice = (float)$rs['buy_price'];
                $totalCost = $buyPrice * $actualAmount;
                if ($balance - $totalCost < $minBalance) continue;

                // Buy
                $conn->query("UPDATE faction_balance SET points = points - $totalCost WHERE faction = '$faction'");
                $conn->query("UPDATE price_volume SET total_bought = total_bought + $actualAmount WHERE resource_id = $resId");
                $volRow = $conn->query("SELECT total_bought, total_sold FROM price_volume WHERE resource_id = $resId")->fetch_assoc();
                if ($volRow) {
                    $baseBuy = (float)$rs['base_buy_price'];
                    $baseSell = (float)$rs['base_sell_price'];
                    $mult = pow(1.001, (float)$volRow['total_bought'] / 100) * pow(0.999, (float)$volRow['total_sold'] / 100);
                    $conn->query("UPDATE world_prices SET buy_price = " . round($baseBuy * $mult, 4) . ", sell_price = " . round($baseSell * $mult, 4) . " WHERE id = $resId");
                }
                $conn->query("INSERT INTO trade_log (faction, resource_id, action, quantity, price_per_unit, total_cost) VALUES ('$faction', $resId, 'buy', $actualAmount, $buyPrice, $totalCost)");

                // Deduct fuel
                if ($trainFuelResId > 0 && $fuelNeeded > 0) {
                    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $customsId AND resource_id = $trainFuelResId");
                }

                // Dispatch train
                $travelSecs = (int)(($distance / max($speed, 1)) * 3600);
                $etaAt = date('Y-m-d H:i:s', time() + $travelSecs);
                $departedAt = date('Y-m-d H:i:s');
                $fuelResVal = $trainFuelResId > 0 ? $trainFuelResId : 'NULL';
                $returnEmpty = 1;

                $conn->query("INSERT INTO train_trips (train_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, fuel_resource_id, return_empty, arrived)
                    VALUES ($trainId, $customsId, $townId, '$departedAt', '$etaAt', $distance, $speed, $fuelNeeded, $fuelResVal, $returnEmpty, 0)");
                $tripId = $conn->insert_id;

                // Distribute cargo across wagons
                $remaining = $actualAmount;
                $wagonRes = $conn->query("
                    SELECT w.id as wagon_id, wt.max_capacity
                    FROM train_consist tc
                    JOIN wagons w ON tc.wagon_id = w.id
                    JOIN wagon_types wt ON w.wagon_type_id = wt.id
                    WHERE tc.train_id = $trainId
                      AND (wt.cargo_class = '$resType' OR wt.cargo_class = 'Warehouse')
                      AND wt.wagon_class = 'cargo'
                    ORDER BY tc.position
                ");
                if ($wagonRes) {
                    while ($wg = $wagonRes->fetch_assoc()) {
                        if ($remaining <= 0) break;
                        $load = min($remaining, (float)$wg['max_capacity']);
                        $wgId = (int)$wg['wagon_id'];
                        $conn->query("INSERT INTO train_trip_cargo (trip_id, wagon_id, resource_id, amount) VALUES ($tripId, $wgId, $resId, $load)");
                        $remaining -= $load;
                    }
                }

                $conn->query("UPDATE trains SET status = 'in_transit' WHERE id = $trainId");
                $conn->query("UPDATE locomotives SET status = 'in_transit' WHERE id = " . (int)$train['loco_id']);

                $logStmt = $conn->prepare("INSERT INTO auto_trade_log (log_type, rule_id, faction, resource_name, action_taken, amount, points_spent, train_id) VALUES ('resupply', ?, ?, ?, ?, ?, ?, ?)");
                $actionDesc = "Bought & shipped to " . $rs['town_name'];
                $logStmt->bind_param("isssddi", $rsId, $faction, $rs['resource_name'], $actionDesc, $actualAmount, $totalCost, $trainId);
                $logStmt->execute();
                $logStmt->close();

                $conn->query("UPDATE auto_resupply SET last_executed_at = NOW() WHERE id = $rsId");
                $autoResupplied++;
            }
        }
    }

    } // end auto_resupply table check

    // ═══════════════════════════════════════════════
    // PHASE 12: Process vehicle repairs at workshops
    // ═══════════════════════════════════════════════
    $vehiclesRepaired = 0;
    $repairsCompleted = 0;

    $wsCheck = $conn->query("SHOW TABLES LIKE 'workshops'");
    $vrCheck = $conn->query("SHOW TABLES LIKE 'vehicle_repairs'");
    if ($wsCheck && $wsCheck->num_rows > 0 && $vrCheck && $vrCheck->num_rows > 0 && $hasHealthCol) {

        // Get all workshops with active repairs
        $wsRes = $conn->query("
            SELECT w.id as workshop_id, w.town_id, w.level
            FROM workshops w
        ");
        if ($wsRes) {
            while ($ws = $wsRes->fetch_assoc()) {
                $wsTown = (int)$ws['town_id'];
                $wsLevel = (int)$ws['level'];

                // Get active repairs at this workshop, limited by workshop level (slots)
                $repRes = $conn->query("
                    SELECT vr.id as repair_id, vr.vehicle_id, vr.target_health,
                           v.health as current_health
                    FROM vehicle_repairs vr
                    JOIN vehicles v ON vr.vehicle_id = v.id
                    WHERE vr.town_id = $wsTown AND v.status = 'repairing'
                    ORDER BY vr.started_at ASC
                    LIMIT $wsLevel
                ");
                if ($repRes) {
                    while ($rep = $repRes->fetch_assoc()) {
                        $repId = (int)$rep['repair_id'];
                        $vId = (int)$rep['vehicle_id'];
                        $curHealth = (float)$rep['current_health'];
                        $targetHealth = (float)$rep['target_health'];

                        // +1% health per tick
                        $newHealth = min($targetHealth, round($curHealth + 1.0, 2));
                        $conn->query("UPDATE vehicles SET health = $newHealth WHERE id = $vId");
                        $vehiclesRepaired++;

                        // Check if repair is complete
                        if ($newHealth >= $targetHealth) {
                            $conn->query("UPDATE vehicles SET status = 'idle' WHERE id = $vId");
                            $conn->query("DELETE FROM vehicle_repairs WHERE id = $repId");
                            $repairsCompleted++;
                        }
                    }
                }
            }
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 13: Population Growth (Birth Rate)
    // ═══════════════════════════════════════════════
    $birthRate = 0.0005; // 0.05% per tick (~0.6% per hour)
    $populationGrowth = 0;

    $popRes = $conn->query("
        SELECT t.id, t.population,
               COALESCE(h.houses,0) AS houses, COALESCE(h.small_flats,0) AS small_flats,
               COALESCE(h.medium_flats,0) AS medium_flats, COALESCE(h.large_flats,0) AS large_flats
        FROM towns t
        LEFT JOIN town_housing h ON t.id = h.town_id
        WHERE t.population > 0 AND t.name NOT LIKE 'Customs House%'
    ");
    if ($popRes) {
        while ($pt = $popRes->fetch_assoc()) {
            $pop = (int)$pt['population'];
            $totalHomes = (int)$pt['houses'] + (int)$pt['small_flats'] * 10 + (int)$pt['medium_flats'] * 50 + (int)$pt['large_flats'] * 100;
            $maxPop = $totalHomes * 5;

            if ($pop >= $maxPop) continue; // At housing capacity

            $growth = $pop * $birthRate;
            // Minimum 1 person growth for towns with population >= 100
            if ($growth < 1 && $pop >= 100) $growth = 1;
            $growth = (int)floor($growth);
            if ($growth <= 0) continue;

            // Cap at housing capacity
            $growth = min($growth, $maxPop - $pop);
            if ($growth <= 0) continue;

            $conn->query("UPDATE towns SET population = population + $growth WHERE id = {$pt['id']}");
            $populationGrowth += $growth;
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 14A: Barracks Auto-Recruitment
    // ═══════════════════════════════════════════════
    $troopsRecruited = 0;

    $barCheck = $conn->query("SHOW TABLES LIKE 'town_barracks'");
    if ($barCheck && $barCheck->num_rows > 0) {
        // Load recruit costs
        $recruitCosts = [];
        $rcRes = $conn->query("SELECT resource_id, amount FROM recruit_costs");
        if ($rcRes) { while ($rc = $rcRes->fetch_assoc()) $recruitCosts[] = $rc; }

        // Get all barracks with workers assigned
        $barRes = $conn->query("
            SELECT b.town_id, b.level, b.workers_assigned, t.side as faction
            FROM town_barracks b
            JOIN towns t ON b.town_id = t.id
            WHERE b.workers_assigned > 0
        ");
        $barracks = [];
        if ($barRes) { while ($br = $barRes->fetch_assoc()) $barracks[] = $br; }

        foreach ($barracks as $bar) {
            $tid = (int)$bar['town_id'];
            $bLevel = (int)$bar['level'];
            $bWorkers = (int)$bar['workers_assigned'];
            $bFaction = $bar['faction'];

            // Rate: 1 recruit per 10 workers per tick
            $recruitsPerTick = floor($bWorkers / 10);
            if ($recruitsPerTick <= 0) continue;

            // Check garrison capacity (100 per level)
            $maxGarrison = $bLevel * 100;
            $garRes = $conn->query("SELECT COALESCE(SUM(quantity),0) as total FROM town_troops WHERE town_id = $tid AND faction = '$bFaction'");
            $currentGarrison = $garRes ? (int)$garRes->fetch_assoc()['total'] : 0;
            $spaceLeft = $maxGarrison - $currentGarrison;
            if ($spaceLeft <= 0) continue;
            $recruitsPerTick = min($recruitsPerTick, $spaceLeft);

            // Check how many we can afford (each recruit costs recruit_costs)
            $canAfford = $recruitsPerTick;
            foreach ($recruitCosts as $rc) {
                $needed = (float)$rc['amount'];
                $sRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $tid AND resource_id = {$rc['resource_id']}");
                $inStock = ($sRes && $sRow = $sRes->fetch_assoc()) ? (float)$sRow['stock'] : 0;
                if ($needed > 0) {
                    $canAfford = min($canAfford, floor($inStock / $needed));
                }
            }
            $canAfford = (int)$canAfford;
            if ($canAfford <= 0) continue;

            // Deduct resources
            foreach ($recruitCosts as $rc) {
                $totalCost = (float)$rc['amount'] * $canAfford;
                $rid = (int)$rc['resource_id'];
                $conn->query("UPDATE town_resources SET stock = GREATEST(0, stock - $totalCost) WHERE town_id = $tid AND resource_id = $rid");
            }

            // Add unarmed troops
            $conn->query("INSERT INTO town_troops (town_id, faction, weapon_type_id, quantity)
                VALUES ($tid, '$bFaction', NULL, $canAfford)
                ON DUPLICATE KEY UPDATE quantity = quantity + $canAfford");

            $troopsRecruited += $canAfford;
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 14B: Munitions Factory Auto-Production
    // ═══════════════════════════════════════════════
    $weaponsProduced = 0;

    $munCheck = $conn->query("SHOW TABLES LIKE 'town_munitions_factory'");
    if ($munCheck && $munCheck->num_rows > 0) {
        // Check if producing_weapon_type_id column exists
        $colExists = false;
        $colChk = $conn->query("SHOW COLUMNS FROM town_munitions_factory LIKE 'producing_weapon_type_id'");
        if ($colChk && $colChk->num_rows > 0) $colExists = true;

        if ($colExists) {
            // Load all weapon build costs grouped by weapon_type_id
            $allWeaponCosts = [];
            $wcRes = $conn->query("SELECT weapon_type_id, resource_id, amount FROM weapon_build_costs");
            if ($wcRes) {
                while ($wc = $wcRes->fetch_assoc()) {
                    $allWeaponCosts[(int)$wc['weapon_type_id']][] = [
                        'resource_id' => (int)$wc['resource_id'],
                        'amount' => (float)$wc['amount'],
                    ];
                }
            }

            // Get all factories producing something with workers assigned
            $munRes = $conn->query("
                SELECT m.town_id, m.level, m.workers_assigned, m.producing_weapon_type_id
                FROM town_munitions_factory m
                WHERE m.workers_assigned > 0 AND m.producing_weapon_type_id IS NOT NULL
            ");
            $munitions = [];
            if ($munRes) { while ($mf = $munRes->fetch_assoc()) $munitions[] = $mf; }

            foreach ($munitions as $mun) {
                $tid = (int)$mun['town_id'];
                $mLevel = (int)$mun['level'];
                $mWorkers = (int)$mun['workers_assigned'];
                $wpnTypeId = (int)$mun['producing_weapon_type_id'];

                // Rate: 1 weapon per 20 workers per tick
                $weaponsPerTick = floor($mWorkers / 20);
                if ($weaponsPerTick <= 0) continue;

                $costs = $allWeaponCosts[$wpnTypeId] ?? [];

                // Check how many we can afford
                $canAfford = $weaponsPerTick;
                foreach ($costs as $c) {
                    $needed = (float)$c['amount'];
                    $sRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $tid AND resource_id = {$c['resource_id']}");
                    $inStock = ($sRes && $sRow = $sRes->fetch_assoc()) ? (float)$sRow['stock'] : 0;
                    if ($needed > 0) {
                        $canAfford = min($canAfford, floor($inStock / $needed));
                    }
                }
                $canAfford = (int)$canAfford;
                if ($canAfford <= 0) continue;

                // Deduct resources
                foreach ($costs as $c) {
                    $totalCost = (float)$c['amount'] * $canAfford;
                    $rid = (int)$c['resource_id'];
                    $conn->query("UPDATE town_resources SET stock = GREATEST(0, stock - $totalCost) WHERE town_id = $tid AND resource_id = $rid");
                }

                // Add weapons to stock
                $conn->query("INSERT INTO town_weapons_stock (town_id, weapon_type_id, stock)
                    VALUES ($tid, $wpnTypeId, $canAfford)
                    ON DUPLICATE KEY UPDATE stock = stock + $canAfford");

                $weaponsProduced += $canAfford;
            }
        }
    }

    // ═══════════════════════════════════════════════
    // PHASE 14C: Troop Arrivals & Combat
    // ═══════════════════════════════════════════════
    $troopsArrived = 0;
    $battlesResolved = 0;

    // Check if military tables exist
    $milCheck = $conn->query("SHOW TABLES LIKE 'troop_movements'");
    if ($milCheck && $milCheck->num_rows > 0) {

        // Find movements past their ETA
        $tmRes = $conn->query("
            SELECT tm.*, t.name as to_name, t.side as to_side
            FROM troop_movements tm
            JOIN towns t ON tm.to_town_id = t.id
            WHERE tm.arrived = 0 AND tm.eta_at <= NOW()
            ORDER BY tm.eta_at ASC
        ");

        $arrivals = [];
        if ($tmRes) { while ($a = $tmRes->fetch_assoc()) $arrivals[] = $a; }

        foreach ($arrivals as $arr) {
            $mvId = (int)$arr['id'];
            $toTownId = (int)$arr['to_town_id'];
            $mvFaction = $arr['faction'];
            $mvWeaponId = $arr['weapon_type_id'] ? (int)$arr['weapon_type_id'] : null;
            $mvQty = (int)$arr['quantity'];
            $isAttack = (int)$arr['is_attack'];
            $toSide = $arr['to_side'];

            // Mark as arrived
            $conn->query("UPDATE troop_movements SET arrived = 1, arrived_at = NOW() WHERE id = $mvId");
            $troopsArrived += $mvQty;

            // Release vehicle at destination if one was used
            $mvVehicleId = !empty($arr['vehicle_id']) ? (int)$arr['vehicle_id'] : null;
            if ($mvVehicleId) {
                $conn->query("UPDATE vehicles SET status = 'idle', town_id = $toTownId WHERE id = $mvVehicleId");
            }

            if ($isAttack && $toSide !== $mvFaction) {
                // ── COMBAT ──
                $battlesResolved++;

                // Calculate attacker power
                $atkAttackPower = 0;
                $atkDefensePower = 0;
                if ($mvWeaponId) {
                    $wRes = $conn->query("SELECT attack_stat, defense_stat FROM weapon_types WHERE id = $mvWeaponId");
                    $wRow = $wRes ? $wRes->fetch_assoc() : null;
                    $atkAttackPower = $mvQty * ($wRow ? (int)$wRow['attack_stat'] : 3);
                    $atkDefensePower = $mvQty * ($wRow ? (int)$wRow['defense_stat'] : 2);
                } else {
                    $atkAttackPower = $mvQty * 3;  // unarmed attack
                    $atkDefensePower = $mvQty * 2;  // unarmed defense
                }

                // Calculate defender power (all troops at target town)
                $defTroops = 0;
                $defAttackPower = 0;
                $defDefensePower = 0;
                $dtRes = $conn->query("
                    SELECT tt.quantity, tt.weapon_type_id,
                           COALESCE(wt.attack_stat, 3) as atk,
                           COALESCE(wt.defense_stat, 2) as def
                    FROM town_troops tt
                    LEFT JOIN weapon_types wt ON tt.weapon_type_id = wt.id
                    WHERE tt.town_id = $toTownId AND tt.faction = '$toSide'
                ");
                if ($dtRes) {
                    while ($dt = $dtRes->fetch_assoc()) {
                        $dq = (int)$dt['quantity'];
                        $defTroops += $dq;
                        $defAttackPower += $dq * (int)$dt['atk'];
                        $defDefensePower += $dq * (int)$dt['def'];
                    }
                }

                // Garrison bonus: +20% defense for defenders
                $defDefensePower = (int)round($defDefensePower * 1.2);

                // Combat resolution
                // Attacker losses = proportion of defender attack vs attacker defense
                // Defender losses = proportion of attacker attack vs defender defense
                $atkLosses = 0;
                $defLosses = 0;

                if ($defTroops > 0) {
                    // Loss ratio: enemy_attack / (your_defense + enemy_attack)
                    $atkLossRatio = $defAttackPower / max(1, $atkDefensePower + $defAttackPower);
                    $defLossRatio = $atkAttackPower / max(1, $defDefensePower + $atkAttackPower);

                    $atkLosses = min($mvQty, max(1, (int)round($mvQty * $atkLossRatio)));
                    $defLosses = min($defTroops, max(1, (int)round($defTroops * $defLossRatio)));
                } else {
                    // Undefended town — no losses for attacker
                    $atkLosses = 0;
                    $defLosses = 0;
                }

                $atkSurvivors = $mvQty - $atkLosses;
                $defSurvivors = $defTroops - $defLosses;

                // Determine result
                $result = 'draw';
                if ($atkSurvivors > 0 && $defSurvivors <= 0) $result = 'attacker_won';
                elseif ($defSurvivors > 0 && $atkSurvivors <= 0) $result = 'defender_won';
                elseif ($atkSurvivors > 0 && $defSurvivors > 0) {
                    // Both survive — attacker retreats (defender wins)
                    $result = 'defender_won';
                }
                // Undefended town with troops arriving = attacker won
                if ($defTroops === 0 && $atkSurvivors > 0) $result = 'attacker_won';

                // Apply defender losses (proportionally across troop types)
                if ($defLosses > 0 && $defTroops > 0) {
                    $lossRatio = $defLosses / $defTroops;
                    $conn->query("
                        UPDATE town_troops
                        SET quantity = GREATEST(0, quantity - ROUND(quantity * $lossRatio))
                        WHERE town_id = $toTownId AND faction = '$toSide'
                    ");
                    $conn->query("DELETE FROM town_troops WHERE quantity <= 0");
                }

                // Place surviving attackers
                if ($atkSurvivors > 0) {
                    if ($result === 'attacker_won') {
                        // Attackers garrison the conquered town
                        $wpIdSql = $mvWeaponId ? $mvWeaponId : 'NULL';
                        $wpCond = $mvWeaponId ? "weapon_type_id = $mvWeaponId" : "weapon_type_id IS NULL";
                        $conn->query("INSERT INTO town_troops (town_id, faction, weapon_type_id, quantity)
                            VALUES ($toTownId, '$mvFaction', $wpIdSql, $atkSurvivors)
                            ON DUPLICATE KEY UPDATE quantity = quantity + $atkSurvivors");
                    } else {
                        // Attackers retreat back to origin
                        $fromTownId = (int)$arr['from_town_id'];
                        $wpIdSql = $mvWeaponId ? $mvWeaponId : 'NULL';
                        $conn->query("INSERT INTO town_troops (town_id, faction, weapon_type_id, quantity)
                            VALUES ($fromTownId, '$mvFaction', $wpIdSql, $atkSurvivors)
                            ON DUPLICATE KEY UPDATE quantity = quantity + $atkSurvivors");
                    }
                }

                // Log battle
                $townName = $conn->real_escape_string($arr['to_name']);
                $conn->query("INSERT INTO combat_log
                    (town_id, town_name, attacker_faction, defender_faction,
                     attacker_troops, defender_troops,
                     attacker_attack_total, attacker_defense_total,
                     defender_attack_total, defender_defense_total,
                     attacker_losses, defender_losses, result)
                    VALUES ($toTownId, '$townName', '$mvFaction', '$toSide',
                     $mvQty, $defTroops,
                     $atkAttackPower, $atkDefensePower,
                     $defAttackPower, $defDefensePower,
                     $atkLosses, $defLosses, '$result')");

            } else {
                // Reinforcement — add troops to destination
                $wpIdSql = $mvWeaponId ? $mvWeaponId : 'NULL';
                $conn->query("INSERT INTO town_troops (town_id, faction, weapon_type_id, quantity)
                    VALUES ($toTownId, '$mvFaction', $wpIdSql, $mvQty)
                    ON DUPLICATE KEY UPDATE quantity = quantity + $mvQty");
            }
        }
    }

    // ═══════════════════════════════════════════════
    // LOG
    // ═══════════════════════════════════════════════

    $durationMs = round((microtime(true) - $start) * 1000);
    $totalRes = $totalProduced + $factoryProduced;
    $totalFuel = $totalBurned + $factoryConsumed;

    $stmt = $conn->prepare("INSERT INTO tick_log (tick_time, duration_ms, towns_processed, resources_produced, fuel_burned) VALUES (NOW(), ?, ?, ?, ?)");
    $stmt->bind_param("iidd", $durationMs, $townsCount, $totalRes, $totalFuel);
    $stmt->execute();
    $tickId = $stmt->insert_id;
    $stmt->close();

    return [
        'skipped' => false,
        'tick_id' => $tickId,
        'duration_ms' => $durationMs,
        'towns_processed' => $townsCount,
        'resources_produced' => $totalRes,
        'fuel_burned' => $totalFuel,
        'mine_produced' => $totalProduced,
        'factory_produced' => $factoryProduced,
        'factory_consumed' => $factoryConsumed,
        'grids' => count($grids),
        'vehicles_arrived' => $vehiclesArrived,
        'vehicles_scrapped' => $vehiclesScrapped,
        'vehicles_repaired' => $vehiclesRepaired,
        'repairs_completed' => $repairsCompleted,
        'auto_dispatched' => $autoDispatched,
        'auto_traded' => $autoTraded,
        'auto_resupplied' => $autoResupplied,
        'population_growth' => $populationGrowth,
        'troops_arrived' => $troopsArrived,
        'battles_resolved' => $battlesResolved,
        'troops_recruited' => $troopsRecruited,
        'weapons_produced' => $weaponsProduced,
    ];
}
