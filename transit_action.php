<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);

$action = $_POST['action'] ?? '';
$faction = $user['team'];
$allowedFactions = viewableFactions($user);

// ============================================================
// RECALL VEHICLE
// ============================================================
if ($action === 'recall_vehicle') {
    $tripId = (int)($_POST['trip_id'] ?? 0);
    if ($tripId <= 0) {
        header("Location: transit.php?msg=invalid_trip");
        exit;
    }

    // Load the active trip + vehicle info
    $trip = $conn->query("
        SELECT vt.*, v.faction,
               UNIX_TIMESTAMP(vt.departed_at) AS dep_ts,
               UNIX_TIMESTAMP(vt.eta_at) AS eta_ts,
               UNIX_TIMESTAMP(NOW()) AS now_ts
        FROM vehicle_trips vt
        JOIN vehicles v ON vt.vehicle_id = v.id
        WHERE vt.id = $tripId AND vt.arrived = 0
    ")->fetch_assoc();

    if (!$trip) {
        header("Location: transit.php?msg=trip_not_found");
        exit;
    }

    // Check faction permission
    if (!in_array($trip['faction'], $allowedFactions)) {
        header("Location: transit.php?msg=denied");
        exit;
    }

    $vehId    = (int)$trip['vehicle_id'];
    $fromTown = (int)$trip['from_town_id'];
    $toTown   = (int)$trip['to_town_id'];
    $speed    = (float)$trip['speed_kmh'];
    $totalDist = (float)$trip['distance_km'];
    $depTs    = (int)$trip['dep_ts'];
    $etaTs    = (int)$trip['eta_ts'];
    $nowTs    = (int)$trip['now_ts'];

    // Calculate how far the vehicle has traveled
    $totalTime = max($etaTs - $depTs, 1);
    $elapsed   = max(0, $nowTs - $depTs);
    $progress  = min(1.0, $elapsed / $totalTime);
    $distTraveled = round($progress * $totalDist, 2);

    // If barely started (< 0.5 km), just cancel and return vehicle to origin instantly
    if ($distTraveled < 0.5) {
        // Cancel trip
        $conn->query("UPDATE vehicle_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");
        // Return vehicle to origin, idle
        $conn->query("UPDATE vehicles SET town_id = $fromTown, status = 'idle' WHERE id = $vehId");
        // Return cargo to origin
        $cargoRes = $conn->query("SELECT resource_id, amount FROM vehicle_trip_cargo WHERE trip_id = $tripId");
        if ($cargoRes) {
            while ($c = $cargoRes->fetch_assoc()) {
                $rid = (int)$c['resource_id'];
                $amt = (float)$c['amount'];
                if ($rid > 0 && $amt > 0) {
                    $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
                    $stmt->bind_param("dii", $amt, $fromTown, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        header("Location: transit.php?msg=recalled_instant");
        exit;
    }

    // Cancel current trip (don't deliver cargo to destination)
    $conn->query("UPDATE vehicle_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");

    // Create return trip back to origin with proportional distance/time
    $returnDist = max(0.1, $distTraveled);
    $returnTimeSecs = (int)(($returnDist / max($speed, 1)) * 3600);
    $returnEta = date('Y-m-d H:i:s', time() + $returnTimeSecs);
    $returnDep = date('Y-m-d H:i:s');
    $fuelUsed = round(((float)$trip['fuel_used']) * $progress, 2);

    $stmt = $conn->prepare("INSERT INTO vehicle_trips 
        (vehicle_id, from_town_id, to_town_id, cargo_resource_id, cargo_amount, 
         departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty, arrived)
        VALUES (?, ?, ?, NULL, 0, ?, ?, ?, ?, ?, 0, 0)");
    $stmt->bind_param("iisssdid", $vehId, $toTown, $fromTown, $returnDep, $returnEta, $returnDist, $speed, $fuelUsed);
    $stmt->execute();
    $recallTripId = $conn->insert_id;
    $stmt->close();

    // Copy cargo from original trip to recall trip (will be delivered to origin on arrival)
    $cargoRes = $conn->query("SELECT resource_id, amount FROM vehicle_trip_cargo WHERE trip_id = $tripId");
    if ($cargoRes) {
        while ($c = $cargoRes->fetch_assoc()) {
            $rid = (int)$c['resource_id'];
            $amt = (float)$c['amount'];
            if ($rid > 0 && $amt > 0) {
                $stmt = $conn->prepare("INSERT INTO vehicle_trip_cargo (trip_id, resource_id, amount) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $recallTripId, $rid, $amt);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // Vehicle stays in_transit (it's now on the recall trip)
    header("Location: transit.php?msg=recalled");
    exit;
}

// ============================================================
// RECALL TRAIN
// ============================================================
if ($action === 'recall_train') {
    $tripId = (int)($_POST['trip_id'] ?? 0);
    if ($tripId <= 0) {
        header("Location: transit.php?tab=trains&msg=invalid_trip");
        exit;
    }

    // Load the active train trip
    $trip = $conn->query("
        SELECT tt.*, t.faction,
               UNIX_TIMESTAMP(tt.departed_at) AS dep_ts,
               UNIX_TIMESTAMP(tt.eta_at) AS eta_ts,
               UNIX_TIMESTAMP(NOW()) AS now_ts
        FROM train_trips tt
        JOIN trains t ON tt.train_id = t.id
        WHERE tt.id = $tripId AND tt.arrived = 0
    ")->fetch_assoc();

    if (!$trip) {
        header("Location: transit.php?tab=trains&msg=trip_not_found");
        exit;
    }

    if (!in_array($trip['faction'], $allowedFactions)) {
        header("Location: transit.php?tab=trains&msg=denied");
        exit;
    }

    $trainId   = (int)$trip['train_id'];
    $fromTown  = (int)$trip['from_town_id'];
    $toTown    = (int)$trip['to_town_id'];
    $speed     = (float)$trip['speed_kmh'];
    $totalDist = (float)$trip['distance_km'];
    $depTs     = (int)$trip['dep_ts'];
    $etaTs     = (int)$trip['eta_ts'];
    $nowTs     = (int)$trip['now_ts'];

    $totalTime = max($etaTs - $depTs, 1);
    $elapsed   = max(0, $nowTs - $depTs);
    $progress  = min(1.0, $elapsed / $totalTime);
    $distTraveled = round($progress * $totalDist, 2);

    // If barely started, instant return
    if ($distTraveled < 0.5) {
        $conn->query("UPDATE train_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");
        $conn->query("UPDATE trains SET town_id = $fromTown, status = 'idle' WHERE id = $trainId");
        // Return cargo
        $cargoRes = $conn->query("SELECT resource_id, amount FROM train_trip_cargo WHERE trip_id = $tripId");
        if ($cargoRes) {
            while ($c = $cargoRes->fetch_assoc()) {
                $rid = (int)$c['resource_id'];
                $amt = (float)$c['amount'];
                if ($rid > 0 && $amt > 0) {
                    $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
                    $stmt->bind_param("dii", $amt, $fromTown, $rid);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        header("Location: transit.php?tab=trains&msg=recalled_instant");
        exit;
    }

    // Cancel current trip
    $conn->query("UPDATE train_trips SET arrived = 1, arrived_at = NOW() WHERE id = $tripId");

    // Create recall return trip
    $returnDist = max(0.1, $distTraveled);
    $returnTimeSecs = (int)(($returnDist / max($speed, 1)) * 3600);
    $returnEta = date('Y-m-d H:i:s', time() + $returnTimeSecs);
    $returnDep = date('Y-m-d H:i:s');
    $fuelUsed = round(((float)$trip['fuel_used']) * $progress, 2);

    $stmt = $conn->prepare("INSERT INTO train_trips 
        (train_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty, arrived)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
    $stmt->bind_param("iisssdid", $trainId, $toTown, $fromTown, $returnDep, $returnEta, $returnDist, $speed, $fuelUsed);
    $stmt->execute();
    $recallTripId = $conn->insert_id;
    $stmt->close();

    // Copy cargo to recall trip
    $cargoRes = $conn->query("SELECT resource_id, amount FROM train_trip_cargo WHERE trip_id = $tripId");
    if ($cargoRes) {
        while ($c = $cargoRes->fetch_assoc()) {
            $rid = (int)$c['resource_id'];
            $amt = (float)$c['amount'];
            if ($rid > 0 && $amt > 0) {
                $stmt = $conn->prepare("INSERT INTO train_trip_cargo (trip_id, resource_id, amount) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $recallTripId, $rid, $amt);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    header("Location: transit.php?tab=trains&msg=recalled");
    exit;
}

header("Location: transit.php");
exit;
?>
