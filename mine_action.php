<?php
session_start();
include "files/config.php";
include "files/auth.php";
include "files/power_functions.php";

$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);

$townId = (int)($_POST['town_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($townId <= 0) {
    header("Location: towns.php");
    exit;
}

// Verify user can act on this town's faction
$townCheck = $conn->query("SELECT side FROM towns WHERE id = $townId")->fetch_assoc();
if ($townCheck && !canViewFaction($user, $townCheck['side'])) {
    header("Location: towns.php");
    exit;
}

// ============================================================
// MINE ACTIONS
// ============================================================

// --- Set Mine Workers ---
if ($action === 'set_workers') {
    $stmt = $conn->prepare("SELECT level FROM town_production WHERE town_id = ?");
    $stmt->bind_param("i", $townId);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($prod) {
        $workers = max(0, (int)($_POST['workers'] ?? 0));
        $maxWorkers = $prod['level'] * 100;
        $workers = min($workers, $maxWorkers);
        // Cap by available population (exclude this mine's current workers)
        $population = (int)($conn->query("SELECT population FROM towns WHERE id = $townId")->fetch_assoc()['population'] ?? 0);
        $othersUsed = getTownWorkersUsed($conn, $townId, 'mine', 0);
        $available = max(0, $population - $othersUsed);
        $workers = min($workers, $available);
        $stmt = $conn->prepare("UPDATE town_production SET workers_assigned = ? WHERE town_id = ?");
        $stmt->bind_param("ii", $workers, $townId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: town_view.php?id=$townId");
    exit;
}

// --- Upgrade Mine ---
if ($action === 'upgrade_mine') {
    $stmt = $conn->prepare("SELECT tp.level, wp.resource_name FROM town_production tp JOIN world_prices wp ON tp.resource_id = wp.id WHERE tp.town_id = ?");
    $stmt->bind_param("i", $townId);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$prod) { header("Location: town_view.php?id=$townId"); exit; }

    $nextLevel = $prod['level'] + 1;
    $buildType = getMineType($prod['resource_name']);
    $costInfo = getUpgradeCosts($conn, $buildType, $nextLevel, $townId);

    if (empty($costInfo['costs'])) {
        header("Location: town_view.php?id=$townId&msg=max_level");
        exit;
    }
    if (!$costInfo['can_afford']) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    deductUpgradeCosts($conn, $buildType, $nextLevel, $townId);
    $stmt = $conn->prepare("UPDATE town_production SET level = ? WHERE town_id = ?");
    $stmt->bind_param("ii", $nextLevel, $townId);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=upgraded");
    exit;
}

// ============================================================
// POWER STATION ACTIONS
// ============================================================

// --- Build Power Station ---
if ($action === 'build_power_station') {
    $fuelType = $_POST['fuel_type'] ?? '';
    $validTypes = ['coal','wood','oil','nuclear'];
    if (!in_array($fuelType, $validTypes)) {
        header("Location: town_view.php?id=$townId&msg=invalid");
        exit;
    }

    $buildType = 'power_' . $fuelType;
    $costInfo = getUpgradeCosts($conn, $buildType, 1, $townId);

    if (!$costInfo['can_afford'] && !empty($costInfo['costs'])) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    if (!empty($costInfo['costs'])) {
        deductUpgradeCosts($conn, $buildType, 1, $townId);
    }

    $stmt = $conn->prepare("INSERT INTO power_stations (town_id, fuel_type, level, workers_assigned) VALUES (?, ?, 1, 0)");
    $stmt->bind_param("is", $townId, $fuelType);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=power_built");
    exit;
}

// --- Upgrade Power Station ---
if ($action === 'upgrade_power_station') {
    $stationId = (int)($_POST['station_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, town_id, fuel_type, level FROM power_stations WHERE id = ? AND town_id = ?");
    $stmt->bind_param("ii", $stationId, $townId);
    $stmt->execute();
    $station = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$station) { header("Location: town_view.php?id=$townId"); exit; }

    $nextLevel = $station['level'] + 1;
    $buildType = 'power_' . $station['fuel_type'];
    $costInfo = getUpgradeCosts($conn, $buildType, $nextLevel, $townId);

    if (empty($costInfo['costs'])) {
        header("Location: town_view.php?id=$townId&msg=max_level");
        exit;
    }
    if (!$costInfo['can_afford']) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    deductUpgradeCosts($conn, $buildType, $nextLevel, $townId);
    $stmt = $conn->prepare("UPDATE power_stations SET level = ? WHERE id = ?");
    $stmt->bind_param("ii", $nextLevel, $stationId);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=power_upgraded");
    exit;
}

// --- Set Power Station Workers ---
if ($action === 'set_power_workers') {
    $stationId = (int)($_POST['station_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, level FROM power_stations WHERE id = ? AND town_id = ?");
    $stmt->bind_param("ii", $stationId, $townId);
    $stmt->execute();
    $station = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($station) {
        $workers = max(0, (int)($_POST['workers'] ?? 0));
        $maxWorkers = $station['level'] * 100;
        $workers = min($workers, $maxWorkers);
        // Cap by available population
        $population = (int)($conn->query("SELECT population FROM towns WHERE id = $townId")->fetch_assoc()['population'] ?? 0);
        $othersUsed = getTownWorkersUsed($conn, $townId, 'power_station', $stationId);
        $available = max(0, $population - $othersUsed);
        $workers = min($workers, $available);
        $stmt = $conn->prepare("UPDATE power_stations SET workers_assigned = ? WHERE id = ?");
        $stmt->bind_param("ii", $workers, $stationId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: town_view.php?id=$townId");
    exit;
}

// ============================================================
// TRANSMISSION LINE ACTIONS
// ============================================================

// --- Build Transmission Line ---
if ($action === 'build_transmission') {
    $targetTownId = (int)($_POST['target_town_id'] ?? 0);
    if ($targetTownId <= 0 || $targetTownId === $townId) {
        header("Location: town_view.php?id=$townId&msg=invalid");
        exit;
    }

    // Must be same faction
    $factionCheck = $conn->query("SELECT t1.side AS s1, t2.side AS s2 FROM towns t1, towns t2 WHERE t1.id = $townId AND t2.id = $targetTownId");
    $fc = $factionCheck->fetch_assoc();
    if (!$fc || $fc['s1'] !== $fc['s2']) {
        header("Location: town_view.php?id=$townId&msg=wrong_faction");
        exit;
    }

    // Check if line already exists (either direction)
    $existing = $conn->query("SELECT id FROM transmission_lines WHERE (town_id_1=$townId AND town_id_2=$targetTownId) OR (town_id_1=$targetTownId AND town_id_2=$townId)");
    if ($existing && $existing->num_rows > 0) {
        header("Location: town_view.php?id=$townId&msg=line_exists");
        exit;
    }

    // Get distance for cost calculation
    $distRes = $conn->query("SELECT distance_km FROM town_distances WHERE town_id_1=$townId AND town_id_2=$targetTownId");
    $distRow = $distRes ? $distRes->fetch_assoc() : null;
    $distance = $distRow ? (float)$distRow['distance_km'] : 1;

    // Get base costs and multiply by distance (rounded up)
    $costInfo = getUpgradeCosts($conn, 'transmission', 1, $townId);
    // Scale costs by distance
    $canAfford = true;
    foreach ($costInfo['costs'] as &$cost) {
        $cost['amount'] = ceil($cost['amount'] * $distance);
        $cost['enough'] = $cost['in_stock'] >= $cost['amount'];
        if (!$cost['enough']) $canAfford = false;
    }
    unset($cost);

    if (!$canAfford) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    // Deduct scaled costs manually
    foreach ($costInfo['costs'] as $cost) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = (float)$cost['amount'];
        $rid = (int)$cost['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }

    // Ensure town_id_1 < town_id_2 for uniqueness
    $t1 = min($townId, $targetTownId);
    $t2 = max($townId, $targetTownId);
    $stmt = $conn->prepare("INSERT INTO transmission_lines (town_id_1, town_id_2, level) VALUES (?, ?, 1)");
    $stmt->bind_param("ii", $t1, $t2);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=line_built");
    exit;
}

// --- Upgrade Transmission Line ---
if ($action === 'upgrade_transmission') {
    $lineId = (int)($_POST['line_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, town_id_1, town_id_2, level FROM transmission_lines WHERE id = ? AND (town_id_1 = ? OR town_id_2 = ?)");
    $stmt->bind_param("iii", $lineId, $townId, $townId);
    $stmt->execute();
    $line = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$line) { header("Location: town_view.php?id=$townId"); exit; }

    $nextLevel = $line['level'] + 1;

    // Get distance for cost scaling
    $distRes = $conn->query("SELECT distance_km FROM town_distances WHERE town_id_1={$line['town_id_1']} AND town_id_2={$line['town_id_2']}");
    $distRow = $distRes ? $distRes->fetch_assoc() : null;
    $distance = $distRow ? (float)$distRow['distance_km'] : 1;

    $costInfo = getUpgradeCosts($conn, 'transmission', $nextLevel, $townId);
    $canAfford = true;
    foreach ($costInfo['costs'] as &$cost) {
        $cost['amount'] = ceil($cost['amount'] * $distance);
        $cost['enough'] = $cost['in_stock'] >= $cost['amount'];
        if (!$cost['enough']) $canAfford = false;
    }
    unset($cost);

    if (empty($costInfo['costs'])) {
        header("Location: town_view.php?id=$townId&msg=max_level");
        exit;
    }
    if (!$canAfford) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    foreach ($costInfo['costs'] as $cost) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = (float)$cost['amount'];
        $rid = (int)$cost['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE transmission_lines SET level = ? WHERE id = ?");
    $stmt->bind_param("ii", $nextLevel, $lineId);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=line_upgraded");
    exit;
}

// ============================================================
// FACTORY ACTIONS
// ============================================================

// --- Build Factory ---
if ($action === 'build_factory') {
    $factoryType = $_POST['factory_type'] ?? '';

    $stmt = $conn->prepare("SELECT type_id FROM factory_types WHERE type_id = ?");
    $stmt->bind_param("s", $factoryType);
    $stmt->execute();
    $ft = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ft) {
        header("Location: town_view.php?id=$townId&msg=invalid");
        exit;
    }

    // Check if already built
    $stmt = $conn->prepare("SELECT id FROM town_factories WHERE town_id = ? AND factory_type_id = ?");
    $stmt->bind_param("is", $townId, $factoryType);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        header("Location: town_view.php?id=$townId&msg=factory_exists");
        exit;
    }
    $stmt->close();

    // Check and deduct build costs (uses upgrade_costs table if entries exist)
    $costInfo = getUpgradeCosts($conn, $factoryType, 1, $townId);
    if (!empty($costInfo['costs'])) {
        if (!$costInfo['can_afford']) {
            header("Location: town_view.php?id=$townId&msg=insufficient");
            exit;
        }
        deductUpgradeCosts($conn, $factoryType, 1, $townId);
    }

    $stmt = $conn->prepare("INSERT INTO town_factories (town_id, factory_type_id, level, workers_assigned) VALUES (?, ?, 1, 0)");
    $stmt->bind_param("is", $townId, $factoryType);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=factory_built");
    exit;
}

// --- Upgrade Factory ---
if ($action === 'upgrade_factory') {
    $factoryId = (int)($_POST['factory_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, town_id, factory_type_id, level FROM town_factories WHERE id = ? AND town_id = ?");
    $stmt->bind_param("ii", $factoryId, $townId);
    $stmt->execute();
    $factory = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$factory) { header("Location: town_view.php?id=$townId"); exit; }

    $nextLevel = $factory['level'] + 1;
    $costInfo = getUpgradeCosts($conn, $factory['factory_type_id'], $nextLevel, $townId);

    if (!empty($costInfo['costs']) && !$costInfo['can_afford']) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }
    if (!empty($costInfo['costs'])) {
        deductUpgradeCosts($conn, $factory['factory_type_id'], $nextLevel, $townId);
    }

    $stmt = $conn->prepare("UPDATE town_factories SET level = ? WHERE id = ?");
    $stmt->bind_param("ii", $nextLevel, $factoryId);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=factory_upgraded");
    exit;
}

// --- Set Factory Workers ---
if ($action === 'set_factory_workers') {
    $factoryId = (int)($_POST['factory_id'] ?? 0);
    $stmt = $conn->prepare("SELECT tf.id, tf.level, ft.workers_per_level
        FROM town_factories tf JOIN factory_types ft ON tf.factory_type_id = ft.type_id
        WHERE tf.id = ? AND tf.town_id = ?");
    $stmt->bind_param("ii", $factoryId, $townId);
    $stmt->execute();
    $factory = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($factory) {
        $workers = max(0, (int)($_POST['workers'] ?? 0));
        $maxWorkers = $factory['level'] * $factory['workers_per_level'];
        $workers = min($workers, $maxWorkers);
        // Cap by available population
        $population = (int)($conn->query("SELECT population FROM towns WHERE id = $townId")->fetch_assoc()['population'] ?? 0);
        $othersUsed = getTownWorkersUsed($conn, $townId, 'factory', $factoryId);
        $available = max(0, $population - $othersUsed);
        $workers = min($workers, $available);
        $stmt = $conn->prepare("UPDATE town_factories SET workers_assigned = ? WHERE id = ?");
        $stmt->bind_param("ii", $workers, $factoryId);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: town_view.php?id=$townId");
    exit;
}

// ============================================================
// HOUSING ACTIONS
// ============================================================

// --- Build Housing ---
if ($action === 'build_housing') {
    $housingType = $_POST['housing_type'] ?? '';
    $validTypes = ['small_flats', 'medium_flats', 'large_flats'];
    if (!in_array($housingType, $validTypes)) {
        header("Location: town_view.php?id=$townId&msg=invalid");
        exit;
    }

    // Get costs and check affordability
    $costs = [];
    $canAfford = true;
    $cRes = $conn->query("SELECT hc.resource_id, hc.amount FROM housing_costs hc WHERE hc.housing_type = '" . $conn->real_escape_string($housingType) . "'");
    if ($cRes) {
        while ($c = $cRes->fetch_assoc()) {
            $sRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$c['resource_id']}");
            $sRow = $sRes ? $sRes->fetch_assoc() : null;
            $inStock = $sRow ? (float)$sRow['stock'] : 0;
            if ($inStock < (float)$c['amount']) $canAfford = false;
            $costs[] = $c;
        }
    }

    if (!$canAfford || empty($costs)) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    // Deduct resources
    foreach ($costs as $cost) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = (float)$cost['amount'];
        $rid = (int)$cost['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }

    // Increment housing count
    $stmt = $conn->prepare("UPDATE town_housing SET $housingType = $housingType + 1 WHERE town_id = ?");
    $stmt->bind_param("i", $townId);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=housing_built");
    exit;
}

// ============================================================
// VEHICLE DISPATCH
// ============================================================
if ($action === 'dispatch_vehicle') {
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $destId = (int)($_POST['destination_id'] ?? 0);
    $returnEmpty = isset($_POST['return_empty']) ? 1 : 0;

    // Validate vehicle exists and is idle in this town
    $vStmt = $conn->prepare("SELECT v.id, v.faction, v.status, v.health, vt.max_speed_kmh, vt.fuel_capacity, vt.fuel_per_km, vt.max_capacity, vt.vehicle_class, vt.cargo_class
        FROM vehicles v JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE v.id = ? AND v.town_id = ? AND v.status = 'idle'");
    $vStmt->bind_param("ii", $vehicleId, $townId);
    $vStmt->execute();
    $vehicle = $vStmt->get_result()->fetch_assoc();
    $vStmt->close();
    if (!$vehicle) { header("Location: town_view.php?id=$townId"); exit; }

    // Block dispatch if vehicle health is below 50%
    if ((float)($vehicle['health'] ?? 100) < 50) {
        header("Location: town_view.php?id=$townId&msg=vehicle_immobilised");
        exit;
    }

    // Build cargo list from POST data
    $cargoItems = []; // array of [resource_id => amount]
    $totalCargoWeight = 0;

    if ($vehicle['vehicle_class'] === 'cargo' && $vehicle['cargo_class']) {
        $isWarehouseClass = stripos($vehicle['cargo_class'], 'warehouse') !== false;
        if ($isWarehouseClass && !empty($_POST['wh_cargo']) && is_array($_POST['wh_cargo'])) {
            // Warehouse: multiple resources allowed
            $whClassEsc = $conn->real_escape_string($vehicle['cargo_class']);
            foreach ($_POST['wh_cargo'] as $rid => $amt) {
                $rid = (int)$rid;
                $amt = (float)$amt;
                if ($rid > 0 && $amt > 0) {
                    $chk = $conn->query("SELECT id FROM world_prices WHERE id = $rid AND resource_type = '$whClassEsc'");
                    if ($chk && $chk->num_rows > 0) {
                        $cargoItems[$rid] = $amt;
                        $totalCargoWeight += $amt;
                    }
                }
            }
        } else {
            // Single resource from the vehicle's cargo class
            $cargoResId = !empty($_POST['cargo_resource_id']) ? (int)$_POST['cargo_resource_id'] : null;
            $cargoAmount = (float)($_POST['cargo_amount'] ?? 0);
            if ($cargoResId && $cargoAmount > 0) {
                // Validate resource belongs to the vehicle's cargo class
                $classEsc = $conn->real_escape_string($vehicle['cargo_class']);
                $chk = $conn->query("SELECT id FROM world_prices WHERE id = $cargoResId AND resource_type = '$classEsc'");
                if (!$chk || $chk->num_rows === 0) {
                    header("Location: town_view.php?id=$townId&msg=wrong_class");
                    exit;
                }
                $cargoItems[$cargoResId] = $cargoAmount;
                $totalCargoWeight = $cargoAmount;
            }
        }
        // Cap total weight to vehicle capacity
        if ($totalCargoWeight > $vehicle['max_capacity']) {
            $ratio = $vehicle['max_capacity'] / $totalCargoWeight;
            foreach ($cargoItems as &$amt) $amt = round($amt * $ratio, 2);
            unset($amt);
            $totalCargoWeight = $vehicle['max_capacity'];
        }
    }

    // Get distance and road info
    $t1 = min($townId, $destId); $t2 = max($townId, $destId);
    $rdStmt = $conn->prepare("SELECT td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit
        FROM town_distances td
        LEFT JOIN roads r ON r.town_id_1 = ? AND r.town_id_2 = ?
        WHERE td.town_id_1 = ? AND td.town_id_2 = ?");
    $rdStmt->bind_param("iiii", $t1, $t2, $townId, $destId);
    $rdStmt->execute();
    $road = $rdStmt->get_result()->fetch_assoc();
    $rdStmt->close();
    if (!$road) { header("Location: town_view.php?id=$townId"); exit; }

    $distance = (float)$road['distance_km'];
    $speedLimit = (int)$road['speed_limit'];
    $speed = min($vehicle['max_speed_kmh'], $speedLimit);
    $fuelNeeded = $distance * $vehicle['fuel_per_km'];
    if ($returnEmpty) $fuelNeeded *= 2;

    // Check fuel in town (resource_name = 'Fuel')
    $fuelRow = $conn->query("SELECT tr.stock, wp.id as fuel_id FROM town_resources tr JOIN world_prices wp ON tr.resource_id = wp.id WHERE wp.resource_name = 'Fuel' AND tr.town_id = $townId")->fetch_assoc();
    $fuelStock = $fuelRow ? (float)$fuelRow['stock'] : 0;
    $fuelResId = $fuelRow ? (int)$fuelRow['fuel_id'] : 0;

    if ($fuelStock < $fuelNeeded) {
        header("Location: town_view.php?id=$townId&msg=no_fuel");
        exit;
    }

    // Check cargo stock for each item
    foreach ($cargoItems as $crid => $camt) {
        $csRow = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = $crid")->fetch_assoc();
        $cargoStock = $csRow ? (float)$csRow['stock'] : 0;
        if ($cargoStock < $camt) {
            header("Location: town_view.php?id=$townId&msg=no_cargo");
            exit;
        }
    }

    // Calculate travel time
    $travelHours = $distance / $speed;
    $travelSeconds = (int)($travelHours * 3600);
    $etaAt = date('Y-m-d H:i:s', time() + $travelSeconds);
    $departedAt = date('Y-m-d H:i:s');

    // Deduct fuel
    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $townId AND resource_id = $fuelResId");

    // Deduct cargo from town
    foreach ($cargoItems as $crid => $camt) {
        $conn->query("UPDATE town_resources SET stock = stock - $camt WHERE town_id = $townId AND resource_id = $crid");
    }

    // Create trip record (cargo_resource_id/cargo_amount kept for backward compat)
    $firstResId = !empty($cargoItems) ? array_key_first($cargoItems) : null;
    $tripStmt = $conn->prepare("INSERT INTO vehicle_trips (vehicle_id, from_town_id, to_town_id, cargo_resource_id, cargo_amount, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $tripStmt->bind_param("iiiidssdidi", $vehicleId, $townId, $destId, $firstResId, $totalCargoWeight, $departedAt, $etaAt, $distance, $speed, $fuelNeeded, $returnEmpty);
    $tripStmt->execute();
    $tripId = $tripStmt->insert_id;
    $tripStmt->close();

    // Insert per-resource cargo records
    if (!empty($cargoItems)) {
        $cargoStmt = $conn->prepare("INSERT INTO vehicle_trip_cargo (trip_id, resource_id, amount) VALUES (?, ?, ?)");
        foreach ($cargoItems as $crid => $camt) {
            $cargoStmt->bind_param("iid", $tripId, $crid, $camt);
            $cargoStmt->execute();
        }
        $cargoStmt->close();
    }

    // Update vehicle status
    $conn->query("UPDATE vehicles SET status = 'in_transit' WHERE id = $vehicleId");

    header("Location: town_view.php?id=$townId&msg=vehicle_dispatched");
    exit;
}

// ============================================================
// BUILD VEHICLE (requires vehicle factory in town)
// ============================================================
if ($action === 'build_vehicle') {
    $vtId = (int)($_POST['vehicle_type_id'] ?? 0);

    // Verify town has a vehicle factory
    $vfCheck = $conn->query("SELECT id FROM town_factories WHERE town_id = $townId AND factory_type_id = 'vehicle_factory'");
    if (!$vfCheck || $vfCheck->num_rows === 0) {
        header("Location: town_view.php?id=$townId&msg=no_factory");
        exit;
    }

    // Verify vehicle type exists and is active
    $stmt = $conn->prepare("SELECT id, name FROM vehicle_types WHERE id = ? AND active = 1");
    $stmt->bind_param("i", $vtId);
    $stmt->execute();
    $vt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$vt) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    // Check build costs
    $buildCosts = [];
    $canAfford = true;
    $bcRes = $conn->query("
        SELECT vbc.resource_id, vbc.amount, wp.resource_name
        FROM vehicle_build_costs vbc
        JOIN world_prices wp ON vbc.resource_id = wp.id
        WHERE vbc.vehicle_type_id = $vtId
    ");
    if ($bcRes) {
        while ($bc = $bcRes->fetch_assoc()) {
            $stRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
            $stRow = $stRes ? $stRes->fetch_assoc() : null;
            $inStock = $stRow ? (float)$stRow['stock'] : 0;
            if ($inStock < (float)$bc['amount']) $canAfford = false;
            $buildCosts[] = $bc;
        }
    }

    if (empty($buildCosts) || !$canAfford) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    // Deduct resources
    foreach ($buildCosts as $bc) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = (float)$bc['amount'];
        $rid = (int)$bc['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }

    // Determine faction from town
    $townSide = $conn->query("SELECT side FROM towns WHERE id = $townId")->fetch_assoc()['side'];

    // Create the vehicle
    $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_type_id, town_id, faction, status) VALUES (?, ?, ?, 'idle')");
    $stmt->bind_param("iis", $vtId, $townId, $townSide);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=vehicle_built");
    exit;
}

// ============================================================
// PURCHASE VEHICLE (Customs House — costs faction points)
// ============================================================
if ($action === 'purchase_vehicle') {
    $vtId = (int)($_POST['vehicle_type_id'] ?? 0);

    // Verify this is a customs house
    $townRow = $conn->query("SELECT name, side FROM towns WHERE id = $townId")->fetch_assoc();
    if (!$townRow || !str_starts_with($townRow['name'], 'Customs House')) {
        header("Location: town_view.php?id=$townId&msg=not_customs");
        exit;
    }

    // Verify vehicle type exists and is active
    $vt = $conn->query("SELECT id, name, points_price FROM vehicle_types WHERE id = $vtId AND active = 1")->fetch_assoc();
    if (!$vt) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    $price = (int)$vt['points_price'];
    $factionSide = $townRow['side'];

    // Check faction balance
    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($factionSide) . "'")->fetch_assoc();
    $balance = $balRow ? (float)$balRow['points'] : 0;

    if ($balance < $price) {
        header("Location: town_view.php?id=$townId&msg=insufficient_points");
        exit;
    }

    // Deduct points
    $conn->query("UPDATE faction_balance SET points = points - $price WHERE faction = '" . $conn->real_escape_string($factionSide) . "'");

    // Create the vehicle
    $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_type_id, town_id, faction, status) VALUES (?, ?, ?, 'idle')");
    $stmt->bind_param("iis", $vtId, $townId, $factionSide);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=vehicle_purchased");
    exit;
}

// ============================================================
// ROAD UPGRADE
// ============================================================
if ($action === 'upgrade_road') {
    $targetTownId = (int)($_POST['target_town_id'] ?? 0);
    $t1 = min($townId, $targetTownId); $t2 = max($townId, $targetTownId);

    // Get current road
    $roadRow = $conn->query("SELECT road_type, speed_limit FROM roads WHERE town_id_1 = $t1 AND town_id_2 = $t2")->fetch_assoc();
    $currentType = $roadRow ? $roadRow['road_type'] : 'mud';

    $nextMap = ['mud' => 'gravel', 'gravel' => 'asphalt', 'asphalt' => 'dual'];
    $nextType = $nextMap[$currentType] ?? null;
    if (!$nextType) { header("Location: town_view.php?id=$townId&msg=max_level"); exit; }

    // Get upgrade cost
    $distRow = $conn->query("SELECT distance_km FROM town_distances WHERE town_id_1 = $townId AND town_id_2 = $targetTownId")->fetch_assoc();
    $distance = $distRow ? (float)$distRow['distance_km'] : 0;

    $costRow = $conn->query("SELECT resource_id, amount_per_km FROM road_upgrade_costs WHERE road_type_from = '" . $conn->real_escape_string($currentType) . "' AND road_type_to = '" . $conn->real_escape_string($nextType) . "'");
    $costs = [];
    $canAfford = true;
    if ($costRow) {
        while ($c = $costRow->fetch_assoc()) {
            $totalCost = (float)$c['amount_per_km'] * $distance;
            $sRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$c['resource_id']}");
            $sRow = $sRes ? $sRes->fetch_assoc() : null;
            if (!$sRow || (float)$sRow['stock'] < $totalCost) $canAfford = false;
            $costs[] = ['resource_id' => $c['resource_id'], 'amount' => $totalCost];
        }
    }

    if (!$canAfford || empty($costs)) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    // Deduct costs
    foreach ($costs as $cost) {
        $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
        $amt = $cost['amount'];
        $rid = $cost['resource_id'];
        $stmt->bind_param("dii", $amt, $townId, $rid);
        $stmt->execute();
        $stmt->close();
    }

    // Get speed limit for new road type
    $speedMap = ['gravel' => 60, 'asphalt' => 90, 'dual' => 130];
    $newSpeed = $speedMap[$nextType] ?? 30;

    // Update or insert road
    if ($roadRow) {
        $conn->query("UPDATE roads SET road_type = '$nextType', speed_limit = $newSpeed WHERE town_id_1 = $t1 AND town_id_2 = $t2");
    } else {
        $conn->query("INSERT INTO roads (town_id_1, town_id_2, road_type, speed_limit) VALUES ($t1, $t2, '$nextType', $newSpeed)");
    }

    header("Location: town_view.php?id=$townId&msg=road_upgraded");
    exit;
}

// ============================================================
// COMPOSE TRAIN
// ============================================================
if ($action === 'compose_train') {
    $locoId = (int)($_POST['locomotive_id'] ?? 0);
    $wagonIds = $_POST['wagon_ids'] ?? [];
    $trainName = trim($_POST['train_name'] ?? '');

    // Verify loco exists, is idle, and is in this town
    $locoCheck = $conn->query("SELECT l.id, l.faction, lt.max_wagons
        FROM locomotives l JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
        WHERE l.id = $locoId AND l.town_id = $townId AND l.status = 'idle'");
    $loco = $locoCheck ? $locoCheck->fetch_assoc() : null;
    if (!$loco) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    $maxWagons = (int)$loco['max_wagons'];
    $faction = $loco['faction'];

    // Validate wagon IDs (idle, in this town, not in another train, same faction)
    $validWagons = [];
    foreach ($wagonIds as $wid) {
        $wid = (int)$wid;
        if ($wid <= 0) continue;
        $wCheck = $conn->query("SELECT id FROM wagons WHERE id = $wid AND town_id = $townId AND status = 'idle' AND train_id IS NULL AND faction = '" . $conn->real_escape_string($faction) . "'");
        if ($wCheck && $wCheck->fetch_assoc()) $validWagons[] = $wid;
        if (count($validWagons) >= $maxWagons) break;
    }

    // Create the train
    $nameEsc = $trainName ? "'" . $conn->real_escape_string($trainName) . "'" : 'NULL';
    $conn->query("INSERT INTO trains (name, locomotive_id, town_id, faction, status) VALUES ($nameEsc, $locoId, $townId, '$faction', 'idle')");
    $trainId = $conn->insert_id;

    // Mark loco as part of a train
    $conn->query("UPDATE locomotives SET status = 'in_transit' WHERE id = $locoId"); // 'in_transit' used as 'in_train' for locos

    // Attach wagons
    $pos = 1;
    foreach ($validWagons as $wid) {
        $conn->query("INSERT INTO train_consist (train_id, wagon_id, position) VALUES ($trainId, $wid, $pos)");
        $conn->query("UPDATE wagons SET status = 'in_train', train_id = $trainId WHERE id = $wid");
        $pos++;
    }

    header("Location: town_view.php?id=$townId&msg=train_composed");
    exit;
}

// ============================================================
// DECOMPOSE TRAIN
// ============================================================
if ($action === 'decompose_train') {
    $trainId = (int)($_POST['train_id'] ?? 0);

    // Verify train exists and is idle in this town
    $trainCheck = $conn->query("SELECT id, locomotive_id FROM trains WHERE id = $trainId AND town_id = $townId AND status = 'idle'");
    $train = $trainCheck ? $trainCheck->fetch_assoc() : null;
    if (!$train) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    // Release wagons
    $conn->query("UPDATE wagons SET status = 'idle', train_id = NULL, town_id = $townId WHERE train_id = $trainId");
    $conn->query("DELETE FROM train_consist WHERE train_id = $trainId");

    // Release locomotive
    $conn->query("UPDATE locomotives SET status = 'idle' WHERE id = {$train['locomotive_id']}");

    // Delete train
    $conn->query("DELETE FROM trains WHERE id = $trainId");

    header("Location: town_view.php?id=$townId&msg=train_decomposed");
    exit;
}

// ============================================================
// DISPATCH TRAIN
// ============================================================
if ($action === 'dispatch_train') {
    $trainId = (int)($_POST['train_id'] ?? 0);
    $destId = (int)($_POST['destination_id'] ?? 0);
    $returnEmpty = (int)($_POST['return_empty'] ?? 0);

    // Verify train
    $trainCheck = $conn->query("
        SELECT t.id, t.locomotive_id, t.town_id, t.faction,
               lt.max_speed_kmh, lt.fuel_per_km, lt.fuel_resource_id, lt.propulsion
        FROM trains t
        JOIN locomotives l ON t.locomotive_id = l.id
        JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
        WHERE t.id = $trainId AND t.town_id = $townId AND t.status IN ('idle','loading')
    ");
    $train = $trainCheck ? $trainCheck->fetch_assoc() : null;
    if (!$train) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    // Get rail line + distance
    $t1 = min($townId, $destId); $t2 = max($townId, $destId);
    $railCheck = $conn->query("SELECT rl.rail_type, rl.speed_limit
        FROM rail_lines rl WHERE rl.town_id_1 = $t1 AND rl.town_id_2 = $t2");
    $rail = $railCheck ? $railCheck->fetch_assoc() : null;
    if (!$rail) { header("Location: town_view.php?id=$townId&msg=no_rail"); exit; }

    // Electric trains need electrified rail
    if ($train['propulsion'] === 'electric' && $rail['rail_type'] !== 'electrified') {
        header("Location: town_view.php?id=$townId&msg=need_electrified");
        exit;
    }

    $distCheck = $conn->query("SELECT distance_km FROM town_distances WHERE town_id_1 = $townId AND town_id_2 = $destId");
    $dist = $distCheck ? $distCheck->fetch_assoc() : null;
    if (!$dist) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }
    $distance = (float)$dist['distance_km'];

    // Speed is min of loco max speed and rail speed limit
    $speed = min((int)$train['max_speed_kmh'], (int)$rail['speed_limit']);
    $travelHours = $distance / max($speed, 1);
    $travelSeconds = $travelHours * 3600;

    // Fuel cost
    $fuelPerKm = (float)$train['fuel_per_km'];
    $totalFuel = $fuelPerKm * $distance;
    $fuelResId = $train['fuel_resource_id'] ? (int)$train['fuel_resource_id'] : null;

    // Deduct fuel from town (if not electric)
    if ($fuelResId && $totalFuel > 0) {
        $fuelCheck = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = $fuelResId");
        $fuelRow = $fuelCheck ? $fuelCheck->fetch_assoc() : null;
        $fuelInStock = $fuelRow ? (float)$fuelRow['stock'] : 0;
        if ($fuelInStock < $totalFuel) {
            header("Location: town_view.php?id=$townId&msg=insufficient_fuel");
            exit;
        }
        $conn->query("UPDATE town_resources SET stock = stock - $totalFuel WHERE town_id = $townId AND resource_id = $fuelResId");
    }

    // Load cargo from wagons
    // Get consist
    $consistRes = $conn->query("
        SELECT tc.wagon_id, wt.wagon_class, wt.cargo_class, wt.max_capacity
        FROM train_consist tc
        JOIN wagons w ON tc.wagon_id = w.id
        JOIN wagon_types wt ON w.wagon_type_id = wt.id
        WHERE tc.train_id = $trainId
        ORDER BY tc.position
    ");
    $consist = [];
    if ($consistRes) { while ($cw = $consistRes->fetch_assoc()) $consist[] = $cw; }

    // Process cargo from POST per class
    $trainCargoRes = $_POST['train_cargo_res'] ?? [];     // class => resource_id (for non-warehouse)
    $trainCargoAmt = $_POST['train_cargo_amt'] ?? [];     // class => amount
    $trainCargoMix = $_POST['train_cargo'] ?? [];         // class => [resource_id => amount] (for warehouse)

    // Build per-wagon cargo assignments
    $wagonCargo = []; // wagon_id => [{resource_id, amount}]

    // Group wagons by cargo class
    $wagonsByClass = [];
    foreach ($consist as $cw) {
        if ($cw['wagon_class'] !== 'cargo' || !$cw['cargo_class']) continue;
        $cls = $cw['cargo_class'];
        if (!isset($wagonsByClass[$cls])) $wagonsByClass[$cls] = [];
        $wagonsByClass[$cls][] = $cw;
    }

    foreach ($wagonsByClass as $cls => $clsWagons) {
        $isWarehouse = stripos($cls, 'warehouse') !== false;
        $totalCap = array_sum(array_column($clsWagons, 'max_capacity'));

        if ($isWarehouse && isset($trainCargoMix[$cls])) {
            // Warehouse: multiple resources, distribute across wagons
            $cargoItems = [];
            $totalLoaded = 0;
            foreach ($trainCargoMix[$cls] as $rid => $amt) {
                $amt = max(0, (float)$amt);
                if ($amt <= 0) continue;
                $cargoItems[] = ['resource_id' => (int)$rid, 'amount' => $amt];
                $totalLoaded += $amt;
            }
            if ($totalLoaded > $totalCap) {
                header("Location: town_view.php?id=$townId&msg=overloaded");
                exit;
            }

            // Verify stock availability
            foreach ($cargoItems as $ci) {
                $stk = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$ci['resource_id']}")->fetch_assoc();
                if (!$stk || (float)$stk['stock'] < $ci['amount']) {
                    header("Location: town_view.php?id=$townId&msg=insufficient");
                    exit;
                }
            }

            // Deduct and assign cargo spread across wagons
            $remainingCargo = $cargoItems;
            foreach ($clsWagons as $cw) {
                $wagonCap = (float)$cw['max_capacity'];
                $wagonId = (int)$cw['wagon_id'];
                $wagonCargo[$wagonId] = [];
                $wagonUsed = 0;
                foreach ($remainingCargo as &$rc) {
                    if ($rc['amount'] <= 0 || $wagonUsed >= $wagonCap) continue;
                    $canLoad = min($rc['amount'], $wagonCap - $wagonUsed);
                    $wagonCargo[$wagonId][] = ['resource_id' => $rc['resource_id'], 'amount' => $canLoad];
                    $rc['amount'] -= $canLoad;
                    $wagonUsed += $canLoad;
                }
                unset($rc);
            }

            // Deduct from town
            foreach ($cargoItems as $ci) {
                $conn->query("UPDATE town_resources SET stock = stock - {$ci['amount']} WHERE town_id = $townId AND resource_id = {$ci['resource_id']}");
            }

        } elseif (!$isWarehouse && isset($trainCargoRes[$cls]) && isset($trainCargoAmt[$cls])) {
            // Single resource class
            $resId = (int)$trainCargoRes[$cls];
            $amount = max(0, (float)$trainCargoAmt[$cls]);
            if ($resId > 0 && $amount > 0) {
                if ($amount > $totalCap) {
                    header("Location: town_view.php?id=$townId&msg=overloaded");
                    exit;
                }
                // Verify resource is in the right class
                $resTypeCheck = $conn->query("SELECT resource_type FROM world_prices WHERE id = $resId");
                $resType = $resTypeCheck ? $resTypeCheck->fetch_assoc()['resource_type'] ?? '' : '';
                if ($resType !== $cls) {
                    header("Location: town_view.php?id=$townId&msg=wrong_class");
                    exit;
                }
                // Verify stock
                $stk = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = $resId")->fetch_assoc();
                if (!$stk || (float)$stk['stock'] < $amount) {
                    header("Location: town_view.php?id=$townId&msg=insufficient");
                    exit;
                }
                // Distribute across wagons
                $remaining = $amount;
                foreach ($clsWagons as $cw) {
                    if ($remaining <= 0) break;
                    $canLoad = min($remaining, (float)$cw['max_capacity']);
                    $wagonCargo[(int)$cw['wagon_id']][] = ['resource_id' => $resId, 'amount' => $canLoad];
                    $remaining -= $canLoad;
                }
                // Deduct
                $conn->query("UPDATE town_resources SET stock = stock - $amount WHERE town_id = $townId AND resource_id = $resId");
            }
        }
    }

    // Calculate ETA
    $now = new DateTime();
    $etaTime = (clone $now)->modify("+{$travelSeconds} seconds");
    $etaStr = $etaTime->format('Y-m-d H:i:s');
    $nowStr = $now->format('Y-m-d H:i:s');

    // Double fuel for return trip
    $totalFuelFinal = $returnEmpty ? $totalFuel * 2 : $totalFuel;
    $fuelResStr = $fuelResId ? $fuelResId : 'NULL';

    // Create trip
    $fuelResSQL = $fuelResId ? $fuelResId : 'NULL';
    $conn->query("INSERT INTO train_trips (train_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, fuel_resource_id, return_empty)
        VALUES ($trainId, $townId, $destId, '$nowStr', '$etaStr', $distance, $speed, $totalFuelFinal, $fuelResSQL, $returnEmpty)");
    $tripId = $conn->insert_id;

    // Insert per-wagon cargo
    if ($tripId > 0 && !empty($wagonCargo)) {
        $cargoStmt = $conn->prepare("INSERT INTO train_trip_cargo (trip_id, wagon_id, resource_id, amount) VALUES (?, ?, ?, ?)");
        foreach ($wagonCargo as $wid => $items) {
            foreach ($items as $ci) {
                $cargoStmt->bind_param("iiid", $tripId, $wid, $ci['resource_id'], $ci['amount']);
                $cargoStmt->execute();
            }
        }
        $cargoStmt->close();
    }

    // Update train status
    $conn->query("UPDATE trains SET status = 'in_transit', town_id = NULL WHERE id = $trainId");

    header("Location: town_view.php?id=$townId&msg=train_dispatched");
    exit;
}

// ============================================================
// BUILD LOCOMOTIVE
// ============================================================
if ($action === 'build_locomotive') {
    $ltId = (int)($_POST['locomotive_type_id'] ?? 0);

    // Verify vehicle factory
    $vfCheck = $conn->query("SELECT id FROM town_factories WHERE town_id = $townId AND factory_type_id = 'vehicle_factory'");
    if (!$vfCheck || $vfCheck->num_rows === 0) {
        header("Location: town_view.php?id=$townId&msg=no_factory");
        exit;
    }

    // Verify type
    $ltCheck = $conn->query("SELECT id, name FROM locomotive_types WHERE id = $ltId AND active = 1");
    $lt = $ltCheck ? $ltCheck->fetch_assoc() : null;
    if (!$lt) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    // Check and deduct costs
    $bcRes = $conn->query("SELECT resource_id, amount FROM locomotive_build_costs WHERE locomotive_type_id = $ltId");
    $costs = [];
    $canAfford = true;
    if ($bcRes) {
        while ($bc = $bcRes->fetch_assoc()) {
            $stRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
            $stRow = $stRes ? $stRes->fetch_assoc() : null;
            if (!$stRow || (float)$stRow['stock'] < (float)$bc['amount']) $canAfford = false;
            $costs[] = $bc;
        }
    }
    if (empty($costs) || !$canAfford) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    foreach ($costs as $bc) {
        $conn->query("UPDATE town_resources SET stock = stock - {$bc['amount']} WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
    }

    $townSide = $conn->query("SELECT side FROM towns WHERE id = $townId")->fetch_assoc()['side'];
    $conn->query("INSERT INTO locomotives (locomotive_type_id, town_id, faction, status) VALUES ($ltId, $townId, '$townSide', 'idle')");

    header("Location: town_view.php?id=$townId&msg=loco_built");
    exit;
}

// ============================================================
// BUILD WAGON
// ============================================================
if ($action === 'build_wagon') {
    $wtId = (int)($_POST['wagon_type_id'] ?? 0);

    // Verify vehicle factory
    $vfCheck = $conn->query("SELECT id FROM town_factories WHERE town_id = $townId AND factory_type_id = 'vehicle_factory'");
    if (!$vfCheck || $vfCheck->num_rows === 0) {
        header("Location: town_view.php?id=$townId&msg=no_factory");
        exit;
    }

    // Verify type
    $wtCheck = $conn->query("SELECT id, name FROM wagon_types WHERE id = $wtId AND active = 1");
    $wt = $wtCheck ? $wtCheck->fetch_assoc() : null;
    if (!$wt) { header("Location: town_view.php?id=$townId&msg=invalid"); exit; }

    // Check and deduct costs
    $bcRes = $conn->query("SELECT resource_id, amount FROM wagon_build_costs WHERE wagon_type_id = $wtId");
    $costs = [];
    $canAfford = true;
    if ($bcRes) {
        while ($bc = $bcRes->fetch_assoc()) {
            $stRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
            $stRow = $stRes ? $stRes->fetch_assoc() : null;
            if (!$stRow || (float)$stRow['stock'] < (float)$bc['amount']) $canAfford = false;
            $costs[] = $bc;
        }
    }
    if (empty($costs) || !$canAfford) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }

    foreach ($costs as $bc) {
        $conn->query("UPDATE town_resources SET stock = stock - {$bc['amount']} WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
    }

    $townSide = $conn->query("SELECT side FROM towns WHERE id = $townId")->fetch_assoc()['side'];
    $conn->query("INSERT INTO wagons (wagon_type_id, town_id, faction, status) VALUES ($wtId, $townId, '$townSide', 'idle')");

    header("Location: town_view.php?id=$townId&msg=wagon_built");
    exit;
}

// ============================================================
// WORKSHOP ACTIONS
// ============================================================

// --- Build Workshop ---
if ($action === 'build_workshop') {
    // Check if workshop already exists
    $wsCheck = $conn->query("SELECT id FROM workshops WHERE town_id = $townId");
    if ($wsCheck && $wsCheck->num_rows > 0) {
        header("Location: town_view.php?id=$townId&msg=workshop_exists");
        exit;
    }

    // Check and deduct build costs
    $costInfo = getUpgradeCosts($conn, 'workshop', 1, $townId);
    if (!empty($costInfo['costs']) && !$costInfo['can_afford']) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }
    if (!empty($costInfo['costs'])) {
        deductUpgradeCosts($conn, 'workshop', 1, $townId);
    }

    $conn->query("INSERT INTO workshops (town_id, level) VALUES ($townId, 1)");
    header("Location: town_view.php?id=$townId&msg=workshop_built");
    exit;
}

// --- Upgrade Workshop ---
if ($action === 'upgrade_workshop') {
    $ws = $conn->query("SELECT id, level FROM workshops WHERE town_id = $townId")->fetch_assoc();
    if (!$ws) {
        header("Location: town_view.php?id=$townId");
        exit;
    }

    $nextLevel = $ws['level'] + 1;
    $costInfo = getUpgradeCosts($conn, 'workshop', $nextLevel, $townId);
    if (!empty($costInfo['costs']) && !$costInfo['can_afford']) {
        header("Location: town_view.php?id=$townId&msg=insufficient");
        exit;
    }
    if (!empty($costInfo['costs'])) {
        deductUpgradeCosts($conn, 'workshop', $nextLevel, $townId);
    }

    $conn->query("UPDATE workshops SET level = $nextLevel WHERE id = " . (int)$ws['id']);
    header("Location: town_view.php?id=$townId&msg=workshop_upgraded");
    exit;
}

// --- Start Vehicle Repair ---
if ($action === 'start_repair') {
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

    // Verify vehicle exists, is in this town, and is idle with damage
    $veh = $conn->query("
        SELECT v.id, v.health, v.faction, v.vehicle_type_id, vt.points_price
        FROM vehicles v
        JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE v.id = $vehicleId AND v.town_id = $townId AND v.status = 'idle'
    ")->fetch_assoc();

    if (!$veh) {
        header("Location: town_view.php?id=$townId&msg=invalid_vehicle");
        exit;
    }

    $health = (float)$veh['health'];

    // Cannot repair if below 10%
    if ($health < 10.0) {
        header("Location: town_view.php?id=$townId&msg=vehicle_scrapped");
        exit;
    }

    // Already at 100%
    if ($health >= 100.0) {
        header("Location: town_view.php?id=$townId&msg=vehicle_full_health");
        exit;
    }

    // Check workshop exists
    $ws = $conn->query("SELECT id, level FROM workshops WHERE town_id = $townId")->fetch_assoc();
    if (!$ws) {
        header("Location: town_view.php?id=$townId&msg=no_workshop");
        exit;
    }

    // Check repair slots available
    $activeRepairs = 0;
    $arCheck = $conn->query("SELECT COUNT(*) as cnt FROM vehicle_repairs WHERE town_id = $townId");
    if ($arCheck) $activeRepairs = (int)$arCheck->fetch_assoc()['cnt'];

    if ($activeRepairs >= (int)$ws['level']) {
        header("Location: town_view.php?id=$townId&msg=workshop_full");
        exit;
    }

    // Already being repaired
    $repCheck = $conn->query("SELECT id FROM vehicle_repairs WHERE vehicle_id = $vehicleId");
    if ($repCheck && $repCheck->num_rows > 0) {
        header("Location: town_view.php?id=$townId&msg=already_repairing");
        exit;
    }

    // Calculate repair cost: (100 - health)% × 0.85 × points_price / 100
    $repairPct = 100.0 - $health;
    $pointsPrice = (float)$veh['points_price'];
    $repairCost = round($repairPct * 0.85 * $pointsPrice / 100.0, 2);

    // Check faction balance
    $faction = $veh['faction'];
    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '$faction'")->fetch_assoc();
    $balance = $balRow ? (float)$balRow['points'] : 0;

    if ($balance < $repairCost) {
        header("Location: town_view.php?id=$townId&msg=insufficient_funds");
        exit;
    }

    // Deduct cost and start repair
    $conn->query("UPDATE faction_balance SET points = points - $repairCost WHERE faction = '$faction'");
    $conn->query("UPDATE vehicles SET status = 'repairing' WHERE id = $vehicleId");

    $stmt = $conn->prepare("INSERT INTO vehicle_repairs (vehicle_id, town_id, faction, start_health, target_health, cost_total) VALUES (?, ?, ?, ?, 100.00, ?)");
    $stmt->bind_param("iisdd", $vehicleId, $townId, $faction, $health, $repairCost);
    $stmt->execute();
    $stmt->close();

    header("Location: town_view.php?id=$townId&msg=repair_started");
    exit;
}

// --- Scrap Vehicle (manually scrap a damaged vehicle) ---
if ($action === 'scrap_vehicle') {
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);

    $veh = $conn->query("SELECT id FROM vehicles WHERE id = $vehicleId AND town_id = $townId AND status IN ('idle','scrapped')")->fetch_assoc();
    if ($veh) {
        $conn->query("UPDATE vehicles SET status = 'scrapped' WHERE id = $vehicleId");
        // Remove any pending repair
        $conn->query("DELETE FROM vehicle_repairs WHERE vehicle_id = $vehicleId");
    }

    header("Location: town_view.php?id=$townId&msg=vehicle_scrapped_ok");
    exit;
}

// --- Invite Immigrants (customs houses only) ---
if ($action === 'invite_immigrants') {
    $targetTownId = (int)($_POST['target_town_id'] ?? 0);
    $count = max(1, (int)($_POST['immigrant_count'] ?? 1));
    $costPerPerson = 10;
    $totalCost = $count * $costPerPerson;

    // Verify this is a customs house
    $srcTown = $conn->query("SELECT name, side FROM towns WHERE id = $townId")->fetch_assoc();
    if (!$srcTown || !str_starts_with($srcTown['name'], 'Customs House')) {
        header("Location: town_view.php?id=$townId");
        exit;
    }
    $faction = $srcTown['side'];

    // Verify target town is same faction and not a customs house
    $target = $conn->query("
        SELECT t.id, t.population, t.side,
               COALESCE(h.houses,0) AS houses, COALESCE(h.small_flats,0) AS small_flats,
               COALESCE(h.medium_flats,0) AS medium_flats, COALESCE(h.large_flats,0) AS large_flats
        FROM towns t
        LEFT JOIN town_housing h ON t.id = h.town_id
        WHERE t.id = $targetTownId AND t.side = '" . $conn->real_escape_string($faction) . "'
          AND t.name NOT LIKE 'Customs House%'
    ")->fetch_assoc();

    if (!$target) {
        header("Location: town_view.php?id=$townId");
        exit;
    }

    // Check housing capacity
    $totalHomes = (int)$target['houses'] + (int)$target['small_flats'] * 10 + (int)$target['medium_flats'] * 50 + (int)$target['large_flats'] * 100;
    $maxPop = $totalHomes * 5;
    $space = max(0, $maxPop - (int)$target['population']);
    $count = min($count, $space);

    if ($count <= 0) {
        header("Location: town_view.php?id=$townId&msg=no_housing_space");
        exit;
    }

    $totalCost = $count * $costPerPerson;

    // Check faction balance
    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($faction) . "'")->fetch_assoc();
    $balance = $balRow ? (float)$balRow['points'] : 0;

    if ($balance < $totalCost) {
        header("Location: town_view.php?id=$townId&msg=insufficient_funds");
        exit;
    }

    // Deduct points and add population
    $conn->query("UPDATE faction_balance SET points = points - $totalCost WHERE faction = '" . $conn->real_escape_string($faction) . "'");
    $conn->query("UPDATE towns SET population = population + $count WHERE id = $targetTownId");

    header("Location: town_view.php?id=$townId&msg=immigrants_arrived");
    exit;
}

// ============================================================
// REQUEST TOW — Send a tow truck to pick up an immobilised vehicle
// ============================================================
if ($action === 'request_tow') {
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $towTruckId = (int)($_POST['tow_truck_id'] ?? 0);
    $destId = (int)($_POST['destination_id'] ?? 0);

    // Validate the broken vehicle exists in this town and is immobilised (health < 50)
    $vStmt = $conn->prepare("SELECT v.id, v.health, v.faction, v.town_id
        FROM vehicles v WHERE v.id = ? AND v.town_id = ? AND v.status = 'idle'");
    $vStmt->bind_param("ii", $vehicleId, $townId);
    $vStmt->execute();
    $brokenVehicle = $vStmt->get_result()->fetch_assoc();
    $vStmt->close();
    if (!$brokenVehicle || (float)$brokenVehicle['health'] >= 50) {
        header("Location: town_view.php?id=$townId&msg=tow_invalid");
        exit;
    }

    // Validate tow truck exists, is idle, is a tow class, and NOT in this town
    $ttStmt = $conn->prepare("SELECT v.id, v.town_id, v.faction, vt.max_speed_kmh, vt.fuel_per_km, vt.fuel_capacity
        FROM vehicles v JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE v.id = ? AND v.status = 'idle' AND vt.vehicle_class = 'tow' AND v.town_id != ?");
    $ttStmt->bind_param("ii", $towTruckId, $townId);
    $ttStmt->execute();
    $towTruck = $ttStmt->get_result()->fetch_assoc();
    $ttStmt->close();
    if (!$towTruck) {
        header("Location: town_view.php?id=$townId&msg=tow_invalid");
        exit;
    }
    $towOriginTown = (int)$towTruck['town_id'];

    // Validate destination has a workshop
    $wsCheck = $conn->query("SELECT id FROM workshops WHERE town_id = $destId AND level > 0");
    if (!$wsCheck || $wsCheck->num_rows === 0) {
        header("Location: town_view.php?id=$townId&msg=tow_invalid");
        exit;
    }

    // Calculate leg 1: tow truck origin → broken vehicle town
    $t1a = min($towOriginTown, $townId); $t2a = max($towOriginTown, $townId);
    $leg1Rd = $conn->query("SELECT td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit
        FROM town_distances td
        LEFT JOIN roads r ON r.town_id_1 = $t1a AND r.town_id_2 = $t2a
        WHERE td.town_id_1 = $t1a AND td.town_id_2 = $t2a")->fetch_assoc();
    if (!$leg1Rd) {
        header("Location: town_view.php?id=$townId&msg=tow_invalid");
        exit;
    }
    $leg1Dist = (float)$leg1Rd['distance_km'];
    $leg1Speed = min((int)$towTruck['max_speed_kmh'], (int)$leg1Rd['speed_limit']);

    // Calculate leg 2: broken vehicle town → workshop destination
    if ($destId == $townId) {
        $leg2Dist = 0;
        $leg2Speed = $leg1Speed;
    } else {
        $t1b = min($townId, $destId); $t2b = max($townId, $destId);
        $leg2Rd = $conn->query("SELECT td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit
            FROM town_distances td
            LEFT JOIN roads r ON r.town_id_1 = $t1b AND r.town_id_2 = $t2b
            WHERE td.town_id_1 = $t1b AND td.town_id_2 = $t2b")->fetch_assoc();
        if (!$leg2Rd) {
            header("Location: town_view.php?id=$townId&msg=tow_invalid");
            exit;
        }
        $leg2Dist = (float)$leg2Rd['distance_km'];
        $leg2Speed = min((int)$towTruck['max_speed_kmh'], (int)$leg2Rd['speed_limit']);
    }

    // Total distance and fuel (1.5x fuel for towing on leg 2)
    $totalDist = $leg1Dist + $leg2Dist;
    $fuelPerKm = (float)$towTruck['fuel_per_km'];
    $fuelNeeded = ($leg1Dist * $fuelPerKm) + ($leg2Dist * $fuelPerKm * 1.5);

    // Calculate total travel time
    $leg1Hours = $leg1Speed > 0 ? $leg1Dist / $leg1Speed : 0;
    // Tow speed is 60% of normal on leg 2
    $towSpeed2 = max(1, $leg2Speed * 0.6);
    $leg2Hours = $leg2Dist > 0 ? $leg2Dist / $towSpeed2 : 0;
    $totalHours = $leg1Hours + $leg2Hours;
    $totalSeconds = (int)($totalHours * 3600);

    // Check fuel at tow truck's origin town
    $fuelRow = $conn->query("SELECT tr.stock, wp.id as fuel_id FROM town_resources tr JOIN world_prices wp ON tr.resource_id = wp.id WHERE wp.resource_name = 'Fuel' AND tr.town_id = $towOriginTown")->fetch_assoc();
    $fuelStock = $fuelRow ? (float)$fuelRow['stock'] : 0;
    $fuelResId = $fuelRow ? (int)$fuelRow['fuel_id'] : 0;

    if ($fuelStock < $fuelNeeded) {
        header("Location: town_view.php?id=$townId&msg=tow_no_fuel");
        exit;
    }

    // Deduct fuel from tow truck's origin town
    $conn->query("UPDATE town_resources SET stock = stock - $fuelNeeded WHERE town_id = $towOriginTown AND resource_id = $fuelResId");

    // Create tow trip (tow truck drives origin → broken vehicle town → destination)
    $departedAt = date('Y-m-d H:i:s');
    $etaAt = date('Y-m-d H:i:s', time() + $totalSeconds);
    $avgSpeed = $totalHours > 0 ? $totalDist / $totalHours : 30;

    $tripStmt = $conn->prepare("INSERT INTO vehicle_trips (vehicle_id, from_town_id, to_town_id, departed_at, eta_at, distance_km, speed_kmh, fuel_used, return_empty, tow_vehicle_id, arrived) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0)");
    $tripStmt->bind_param("iiissdddi", $towTruckId, $towOriginTown, $destId, $departedAt, $etaAt, $totalDist, $avgSpeed, $fuelNeeded, $vehicleId);
    $tripStmt->execute();
    $tripStmt->close();

    // Update statuses
    $conn->query("UPDATE vehicles SET status = 'in_transit' WHERE id = $towTruckId");
    $conn->query("UPDATE vehicles SET status = 'towing' WHERE id = $vehicleId");

    header("Location: town_view.php?id=$townId&msg=tow_dispatched");
    exit;
}

header("Location: town_view.php?id=$townId");
exit;
?>
