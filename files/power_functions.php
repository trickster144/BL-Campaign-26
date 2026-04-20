<?php
// Power grid calculation functions used by town_view.php and other pages

/**
 * Get total workers assigned across all buildings in a town (mine + power stations + factories)
 * Optionally exclude a specific building to calculate "others" total when reassigning
 */
function getTownWorkersUsed($conn, $townId, $excludeType = null, $excludeId = null) {
    $total = 0;

    // Mine workers
    if ($excludeType !== 'mine') {
        $r = $conn->query("SELECT COALESCE(workers_assigned, 0) AS w FROM town_production WHERE town_id = $townId");
        if ($r && $row = $r->fetch_assoc()) $total += (int)$row['w'];
    }

    // Power station workers
    $psql = "SELECT COALESCE(SUM(workers_assigned), 0) AS w FROM power_stations WHERE town_id = $townId";
    if ($excludeType === 'power_station' && $excludeId > 0) $psql .= " AND id != $excludeId";
    $r = $conn->query($psql);
    if ($r && $row = $r->fetch_assoc()) $total += (int)$row['w'];

    // Factory workers
    $fsql = "SELECT COALESCE(SUM(workers_assigned), 0) AS w FROM town_factories WHERE town_id = $townId";
    if ($excludeType === 'factory' && $excludeId > 0) $fsql .= " AND id != $excludeId";
    $r = $conn->query($fsql);
    if ($r && $row = $r->fetch_assoc()) $total += (int)$row['w'];

    // Barracks workers
    if ($excludeType !== 'barracks') {
        $bCheck = $conn->query("SHOW TABLES LIKE 'town_barracks'");
        if ($bCheck && $bCheck->num_rows > 0) {
            $r = $conn->query("SELECT COALESCE(workers_assigned, 0) AS w FROM town_barracks WHERE town_id = $townId");
            if ($r && $row = $r->fetch_assoc()) $total += (int)$row['w'];
        }
    }

    // Munitions factory workers
    if ($excludeType !== 'munitions') {
        $mCheck = $conn->query("SHOW TABLES LIKE 'town_munitions_factory'");
        if ($mCheck && $mCheck->num_rows > 0) {
            $r = $conn->query("SELECT COALESCE(workers_assigned, 0) AS w FROM town_munitions_factory WHERE town_id = $townId");
            if ($r && $row = $r->fetch_assoc()) $total += (int)$row['w'];
        }
    }

    return $total;
}

/**
 * Get the building_type string for upgrade_costs lookup based on mine resource
 */
function getMineType($resourceName) {
    if (in_array($resourceName, ['Coal','Iron','Bauxite','Stone','Uranium'])) return 'mine_standard';
    if ($resourceName === 'Oil') return 'oil_well';
    if ($resourceName === 'Wood') return 'forestry';
    return 'mine_standard';
}

/**
 * Get display name for a mine facility
 */
function getFacilityName($resourceName) {
    if ($resourceName === 'Wood') return 'Forestry';
    if ($resourceName === 'Oil') return 'Oil Well';
    return $resourceName . ' Mine';
}

/**
 * Calculate maximum MW output of a power station (before demand throttle)
 * Uses mw_per_level from station type; falls back to 1.0 if not provided
 */
function getPowerStationOutput($level, $workersAssigned, $mwPerLevel = 1.0) {
    $baseOutput = $level * $mwPerLevel;
    $maxWorkers = $level * 100;
    $workerEff = $maxWorkers > 0 ? $workersAssigned / $maxWorkers : 0;
    return $baseOutput * $workerEff;
}

/**
 * Calculate fuel consumption in tonnes/hr for a given power output
 * Uses the fuel_per_mw_hr rate from power_station_types
 */
function getFuelConsumption($fuelType, $actualOutputMW, $fuelPerMwHr = null) {
    if ($fuelPerMwHr !== null) {
        return $fuelPerMwHr * $actualOutputMW;
    }
    // Legacy fallback rates (used if fuel_per_mw_hr not passed)
    $rates = ['wood' => 1.0, 'coal' => 0.5, 'oil' => 0.3333, 'nuclear' => 0.01];
    return ($rates[$fuelType] ?? 1.0) * $actualOutputMW;
}

/**
 * BFS: Find all towns connected to $townId via transmission lines within same faction
 * Returns array of town IDs in the grid (including $townId itself)
 */
function findGridTowns($conn, $townId) {
    $stmt = $conn->prepare("SELECT side FROM towns WHERE id = ?");
    $stmt->bind_param("i", $townId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result) return [$townId];
    $faction = $result['side'];

    $visited = [$townId => true];
    $queue = [$townId];
    $gridTowns = [$townId];

    while (!empty($queue)) {
        $current = array_shift($queue);
        $res = $conn->query("
            SELECT tl.town_id_1, tl.town_id_2, tl.level,
                   t1.side AS side1, t2.side AS side2
            FROM transmission_lines tl
            JOIN towns t1 ON tl.town_id_1 = t1.id
            JOIN towns t2 ON tl.town_id_2 = t2.id
            WHERE tl.town_id_1 = $current OR tl.town_id_2 = $current
        ");
        while ($row = $res->fetch_assoc()) {
            $neighbor = ($row['town_id_1'] == $current) ? $row['town_id_2'] : $row['town_id_1'];
            $neighborSide = ($row['town_id_1'] == $current) ? $row['side2'] : $row['side1'];
            if ($neighborSide !== $faction) continue;
            if (isset($visited[$neighbor])) continue;
            $visited[$neighbor] = true;
            $queue[] = $neighbor;
            $gridTowns[] = $neighbor;
        }
    }
    return $gridTowns;
}

/**
 * Get complete power grid info for a town.
 * Returns: grid_towns, total_generation, total_demand, local_generation, local_demand,
 *          power_ratio, local_stations (array), grid_town_names
 */
function getGridPowerInfo($conn, $townId) {
    $gridTowns = findGridTowns($conn, $townId);
    $gridTownIds = implode(',', array_map('intval', $gridTowns));

    // Power generation from all stations on grid
    $totalGeneration = 0.0;
    $localGeneration = 0.0;
    $localStations = [];

    $res = $conn->query("
        SELECT ps.id, ps.town_id, ps.fuel_type, ps.level, ps.workers_assigned,
               pst.display_name, pst.fuel_resource_name, pst.fuel_per_mw_hr,
               COALESCE(pst.mw_per_level, 1.0) AS mw_per_level
        FROM power_stations ps
        JOIN power_station_types pst ON ps.fuel_type = pst.fuel_type
        WHERE ps.town_id IN ($gridTownIds)
    ");
    if ($res) {
        while ($ps = $res->fetch_assoc()) {
            $mwPerLevel = (float)$ps['mw_per_level'];
            $output = getPowerStationOutput((int)$ps['level'], (int)$ps['workers_assigned'], $mwPerLevel);
            $totalGeneration += $output;
            if ((int)$ps['town_id'] === $townId) {
                $localGeneration += $output;
                $ps['actual_output'] = $output;
                $ps['max_output'] = $output;
                $ps['max_workers'] = (int)$ps['level'] * 100;
                $ps['fuel_consumption'] = getFuelConsumption($ps['fuel_type'], $output, (float)$ps['fuel_per_mw_hr']);
                $localStations[] = $ps;
            }
        }
    }

    // Power demand from all mines on grid
    $totalDemand = 0.0;
    $localDemand = 0.0;
    $res = $conn->query("
        SELECT tp.town_id, tp.level
        FROM town_production tp
        WHERE tp.town_id IN ($gridTownIds)
    ");
    if ($res) {
        while ($tp = $res->fetch_assoc()) {
            $demand = (float)$tp['level'] * 1.0;
            $totalDemand += $demand;
            if ((int)$tp['town_id'] === $townId) $localDemand += $demand;
        }
    }

    // Power demand from factories on grid
    $res = $conn->query("
        SELECT tf.town_id, SUM(tf.level * ft.power_per_level) AS factory_demand
        FROM town_factories tf
        JOIN factory_types ft ON tf.factory_type_id = ft.type_id
        WHERE tf.town_id IN ($gridTownIds) AND tf.level > 0
        GROUP BY tf.town_id
    ");
    if ($res) {
        while ($fd = $res->fetch_assoc()) {
            $demand = (float)$fd['factory_demand'];
            $totalDemand += $demand;
            if ((int)$fd['town_id'] === $townId) $localDemand += $demand;
        }
    }

    // Grid power ratio (how much of demand is met)
    if ($totalDemand > 0) {
        $powerRatio = min($totalGeneration / $totalDemand, 1.0);
    } else {
        $powerRatio = ($totalGeneration > 0) ? 1.0 : 0.0;
    }

    // Demand throttle: if generation > demand, stations scale down output & fuel
    // throttleRatio = 1.0 means full burn, < 1.0 means grid has surplus
    $throttleRatio = ($totalGeneration > 0 && $totalDemand > 0)
        ? min($totalDemand / $totalGeneration, 1.0)
        : ($totalDemand > 0 ? 1.0 : 0.0);

    foreach ($localStations as &$ls) {
        $ls['throttle_ratio'] = $throttleRatio;
        $ls['throttled_output'] = $ls['max_output'] * $throttleRatio;
        $ls['throttled_fuel'] = $ls['fuel_consumption'] * $throttleRatio;
    }
    unset($ls);

    // Get grid town names for display
    $gridTownNames = [];
    $nameRes = $conn->query("SELECT id, name FROM towns WHERE id IN ($gridTownIds) ORDER BY name");
    if ($nameRes) {
        while ($t = $nameRes->fetch_assoc()) $gridTownNames[(int)$t['id']] = $t['name'];
    }

    return [
        'grid_towns'       => $gridTowns,
        'grid_town_names'  => $gridTownNames,
        'total_generation'  => $totalGeneration,
        'total_demand'      => $totalDemand,
        'local_generation'  => $localGeneration,
        'local_demand'      => $localDemand,
        'power_ratio'       => $powerRatio,
        'throttle_ratio'    => $throttleRatio,
        'local_stations'    => $localStations,
    ];
}

/**
 * Get transmission lines for a town (only same-faction connections)
 */
function getTownTransmissionLines($conn, $townId) {
    $lines = [];
    $res = $conn->query("
        SELECT tl.id, tl.town_id_1, tl.town_id_2, tl.level,
               t1.name AS name1, t1.side AS side1,
               t2.name AS name2, t2.side AS side2,
               td.distance_km
        FROM transmission_lines tl
        JOIN towns t1 ON tl.town_id_1 = t1.id
        JOIN towns t2 ON tl.town_id_2 = t2.id
        LEFT JOIN town_distances td ON
            (td.town_id_1 = tl.town_id_1 AND td.town_id_2 = tl.town_id_2)
        WHERE tl.town_id_1 = $townId OR tl.town_id_2 = $townId
        ORDER BY tl.level DESC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['capacity'] = (int)$row['level'] * 100;
            $row['other_town_id'] = ($row['town_id_1'] == $townId) ? $row['town_id_2'] : $row['town_id_1'];
            $row['other_town_name'] = ($row['town_id_1'] == $townId) ? $row['name2'] : $row['name1'];
            $lines[] = $row;
        }
    }
    return $lines;
}

/**
 * Get upgrade costs for a building type at a target level, with stock check for a town
 */
function getUpgradeCosts($conn, $buildingType, $targetLevel, $townId) {
    $costs = [];
    $canAfford = true;

    $stmt = $conn->prepare("
        SELECT uc.resource_id, uc.amount, wp.resource_name, wp.image_file
        FROM upgrade_costs uc
        JOIN world_prices wp ON uc.resource_id = wp.id
        WHERE uc.building_type = ? AND uc.target_level = ?
        ORDER BY wp.resource_name
    ");
    $stmt->bind_param("si", $buildingType, $targetLevel);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($c = $res->fetch_assoc()) {
        $sStmt = $conn->prepare("SELECT stock FROM town_resources WHERE town_id = ? AND resource_id = ?");
        $rid = (int)$c['resource_id'];
        $sStmt->bind_param("ii", $townId, $rid);
        $sStmt->execute();
        $sRow = $sStmt->get_result()->fetch_assoc();
        $sStmt->close();
        $inStock = $sRow ? (float)$sRow['stock'] : 0;
        $enough = $inStock >= (float)$c['amount'];
        if (!$enough) $canAfford = false;
        $costs[] = [
            'resource_id'   => $rid,
            'resource_name' => $c['resource_name'],
            'image_file'    => $c['image_file'],
            'amount'        => (float)$c['amount'],
            'in_stock'      => $inStock,
            'enough'        => $enough,
        ];
    }
    $stmt->close();

    return ['costs' => $costs, 'can_afford' => $canAfford];
}

/**
 * Deduct upgrade costs from town resources. Returns true on success.
 */
function deductUpgradeCosts($conn, $buildingType, $targetLevel, $townId) {
    $info = getUpgradeCosts($conn, $buildingType, $targetLevel, $townId);
    if (!$info['can_afford'] || empty($info['costs'])) return false;

    foreach ($info['costs'] as $cost) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = $cost['amount'];
        $rid = $cost['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }
    return true;
}
?>
