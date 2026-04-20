<?php
session_start();
include "files/config.php";
include "files/power_functions.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);

// Validate town ID
$townId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($townId <= 0) {
    header("Location: towns.php");
    exit;
}

// Fetch town details
$stmt = $conn->prepare("SELECT id, name, population, side FROM towns WHERE id = ?");
$stmt->bind_param("i", $townId);
$stmt->execute();
$town = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Check faction access
if (!$town || !canViewFaction($user, $town['side'])) {
    header("Location: towns.php");
    exit;
}

$isCustomsHouse = str_starts_with($town['name'], 'Customs House');

// Fetch resources for this town
$resources = $conn->query("
    SELECT wp.resource_name, wp.resource_type, wp.image_file, tr.stock
    FROM town_resources tr
    JOIN world_prices wp ON tr.resource_id = wp.id
    WHERE tr.town_id = $townId
    ORDER BY wp.resource_type, wp.resource_name
");

// Fetch production info (mine)
$production = $conn->query("
    SELECT wp.resource_name, wp.image_file, tp.level, tp.workers_assigned, tp.resource_id
    FROM town_production tp
    JOIN world_prices wp ON tp.resource_id = wp.id
    WHERE tp.town_id = $townId
");
$prodData = $production ? $production->fetch_assoc() : null;

// Power grid info
$gridInfo = getGridPowerInfo($conn, $townId);
$powerRatio = $gridInfo['power_ratio'];

// Calculate mine production using grid power
$mineInfo = null;
if ($prodData) {
    $level = (int)$prodData['level'];
    $workersAssigned = (int)$prodData['workers_assigned'];

    $baseRate = $level * 1.0;
    $maxWorkers = $level * 100;
    $powerRequired = $level * 1.0;

    $workerEff = $maxWorkers > 0 ? $workersAssigned / $maxWorkers : 0;
    $powerEff = $powerRatio; // from grid
    $actualOutput = $baseRate * $workerEff * $powerEff;

    $resName = $prodData['resource_name'];
    $facilityName = getFacilityName($resName);
    $buildType = getMineType($resName);

    $nextLevel = $level + 1;
    $upgradeCostInfo = getUpgradeCosts($conn, $buildType, $nextLevel, $townId);

    $mineInfo = compact('level','workersAssigned','baseRate','maxWorkers',
        'powerRequired','workerEff','powerEff','actualOutput','facilityName',
        'nextLevel','buildType');
    $mineInfo['upgradeCosts'] = $upgradeCostInfo['costs'];
    $mineInfo['canUpgrade'] = $upgradeCostInfo['can_afford'];
}

// Transmission lines from this town
$transmissionLines = getTownTransmissionLines($conn, $townId);

// Same-faction towns for transmission line building dropdown
$sameFactionTowns = [];
$sfRes = $conn->query("SELECT id, name FROM towns WHERE side = '{$town['side']}' AND id != $townId AND population > 0 ORDER BY name");
if ($sfRes) { while ($sf = $sfRes->fetch_assoc()) $sameFactionTowns[] = $sf; }

// Existing transmission connections (to exclude from dropdown)
$existingConnections = [];
$transmissionUpgradeCosts = []; // keyed by line id
foreach ($transmissionLines as $tl) {
    $existingConnections[] = (int)$tl['other_town_id'];
    // Get upgrade costs for each existing line
    $nextLvl = (int)$tl['level'] + 1;
    $dist = (float)($tl['distance_km'] ?? 1);
    $tlCostInfo = getUpgradeCosts($conn, 'transmission', $nextLvl, $townId);
    foreach ($tlCostInfo['costs'] as &$c) {
        $c['amount'] = ceil($c['amount'] * $dist);
        $c['enough'] = $c['in_stock'] >= $c['amount'];
    }
    unset($c);
    $transmissionUpgradeCosts[$tl['id']] = $tlCostInfo['costs'];
}

// Base transmission costs (for new line builder — will be scaled per-target)
$transmissionBaseCosts = getUpgradeCosts($conn, 'transmission', 1, $townId);

// Same-faction town distances for transmission cost display
$sameFactionDistances = [];
$sdRes = $conn->query("SELECT town_id_1, town_id_2, distance_km FROM town_distances WHERE town_id_1 = $townId OR town_id_2 = $townId");
if ($sdRes) {
    while ($sd = $sdRes->fetch_assoc()) {
        $otherId = ($sd['town_id_1'] == $townId) ? (int)$sd['town_id_2'] : (int)$sd['town_id_1'];
        $sameFactionDistances[$otherId] = (float)$sd['distance_km'];
    }
}

// Power station types for build dropdown
$stationTypes = [];
$stRes = $conn->query("SELECT * FROM power_station_types ORDER BY fuel_type");
if ($stRes) { while ($st = $stRes->fetch_assoc()) $stationTypes[] = $st; }

// Flash messages
$msg = $_GET['msg'] ?? '';

// Housing data
$housingData = ['houses' => 0, 'small_flats' => 0, 'medium_flats' => 0, 'large_flats' => 0];
$hRes = $conn->query("SELECT * FROM town_housing WHERE town_id = $townId");
if ($hRes && $hRes->num_rows > 0) $housingData = $hRes->fetch_assoc();

$totalHomes = (int)$housingData['houses']
    + ((int)$housingData['small_flats'] * 10)
    + ((int)$housingData['medium_flats'] * 50)
    + ((int)$housingData['large_flats'] * 100);
$maxPopulation = $totalHomes * 5;
$popPct = $maxPopulation > 0 ? round($town['population'] / $maxPopulation * 100) : 0;

// Workers used across all buildings
$workersUsed = getTownWorkersUsed($conn, $townId);
$workersAvailable = max(0, (int)$town['population'] - $workersUsed);
$workerPct = $town['population'] > 0 ? round($workersUsed / $town['population'] * 100) : 0;

// Housing build costs with affordability check
$housingTypes = [
    'small_flats'  => ['name' => 'Small Flats',  'homes' => 10,  'citizens' => 50,  'icon' => 'fa-home'],
    'medium_flats' => ['name' => 'Medium Flats', 'homes' => 50,  'citizens' => 250, 'icon' => 'fa-building'],
    'large_flats'  => ['name' => 'Large Flats',  'homes' => 100, 'citizens' => 500, 'icon' => 'fa-city'],
];
$housingCosts = [];
$housingAffordable = [];
$hcRes = $conn->query("SELECT hc.housing_type, hc.resource_id, hc.amount, wp.resource_name, wp.image_file
    FROM housing_costs hc JOIN world_prices wp ON hc.resource_id = wp.id ORDER BY hc.housing_type, wp.resource_name");
if ($hcRes) {
    while ($hc = $hcRes->fetch_assoc()) $housingCosts[$hc['housing_type']][] = $hc;
}
foreach (array_keys($housingTypes) as $hType) {
    $affordable = true;
    if (isset($housingCosts[$hType])) {
        foreach ($housingCosts[$hType] as &$cost) {
            $csRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$cost['resource_id']}");
            $csRow = $csRes ? $csRes->fetch_assoc() : null;
            $cost['in_stock'] = $csRow ? (float)$csRow['stock'] : 0;
            $cost['enough'] = $cost['in_stock'] >= (float)$cost['amount'];
            if (!$cost['enough']) $affordable = false;
        }
        unset($cost);
    }
    $housingAffordable[$hType] = $affordable;
}

// Factory data for this town
$factoryData = [];
$fRes = $conn->query("
    SELECT tf.id, tf.factory_type_id, tf.level, tf.workers_assigned,
           ft.display_name, ft.power_per_level, ft.workers_per_level,
           ft.output_per_level, ft.output_resource_id,
           wp.resource_name AS output_name, wp.image_file AS output_image
    FROM town_factories tf
    JOIN factory_types ft ON tf.factory_type_id = ft.type_id
    JOIN world_prices wp ON ft.output_resource_id = wp.id
    WHERE tf.town_id = $townId
    ORDER BY ft.display_name
");
if ($fRes) { while ($f = $fRes->fetch_assoc()) $factoryData[] = $f; }

// Calculate factory production stats
foreach ($factoryData as &$factory) {
    $fLevel = (int)$factory['level'];
    $fWorkers = (int)$factory['workers_assigned'];
    $fMaxWorkers = $fLevel * (int)$factory['workers_per_level'];
    $fPowerReq = $fLevel * (float)$factory['power_per_level'];
    $fWorkerEff = $fMaxWorkers > 0 ? $fWorkers / $fMaxWorkers : 0;
    $fPowerEffVal = $powerRatio;
    $fBaseOutput = $fLevel * (float)$factory['output_per_level'];
    $fActualOutput = $fBaseOutput * $fWorkerEff * $fPowerEffVal;

    $factory['max_workers'] = $fMaxWorkers;
    $factory['power_required'] = $fPowerReq;
    $factory['worker_eff'] = $fWorkerEff;
    $factory['power_eff'] = $fPowerEffVal;
    $factory['base_output'] = $fBaseOutput;
    $factory['actual_output'] = $fActualOutput;

    // Get upgrade costs for this factory
    $fNextLevel = $fLevel + 1;
    $fCostInfo = getUpgradeCosts($conn, $factory['factory_type_id'], $fNextLevel, $townId);
    $factory['upgrade_costs'] = $fCostInfo['costs'];
    $factory['can_upgrade'] = $fCostInfo['can_afford'];
}
unset($factory);

// Available factory types (not yet built)
$builtTypeIds = array_column($factoryData, 'factory_type_id');
$allFactoryTypes = [];
$ftRes = $conn->query("
    SELECT ft.type_id, ft.display_name, ft.power_per_level, ft.workers_per_level,
           ft.output_per_level, wp.resource_name AS output_name
    FROM factory_types ft
    JOIN world_prices wp ON ft.output_resource_id = wp.id
    ORDER BY ft.display_name
");
if ($ftRes) { while ($ft = $ftRes->fetch_assoc()) $allFactoryTypes[] = $ft; }
$availableFactoryTypes = array_filter($allFactoryTypes, function($ft) use ($builtTypeIds) {
    return !in_array($ft['type_id'], $builtTypeIds);
});

// Build costs for available factory types (level 1)
$factoryBuildCosts = [];
foreach ($availableFactoryTypes as $aft) {
    $fbc = getUpgradeCosts($conn, $aft['type_id'], 1, $townId);
    $factoryBuildCosts[$aft['type_id']] = $fbc;
}

// Factory input recipes for display
$factoryInputs = [];
$fiRes = $conn->query("
    SELECT fi.factory_type_id, fi.amount_per_level, wp.resource_name, wp.image_file
    FROM factory_inputs fi
    JOIN world_prices wp ON fi.resource_id = wp.id
    ORDER BY fi.factory_type_id, wp.resource_name
");
if ($fiRes) { while ($fi = $fiRes->fetch_assoc()) $factoryInputs[$fi['factory_type_id']][] = $fi; }

// Check if this town has a vehicle factory (enables local vehicle construction)
$hasVehicleFactory = in_array('vehicle_factory', $builtTypeIds);

// Load purchasable vehicle types for customs houses (buy with faction points)
$purchasableVehicleTypes = [];
if ($isCustomsHouse) {
    $pvRes = $conn->query("
        SELECT id, name, category, vehicle_class, cargo_class, max_speed_kmh,
               fuel_capacity, fuel_per_km, max_capacity, points_price
        FROM vehicle_types WHERE active = 1 ORDER BY category, name
    ");
    if ($pvRes) { while ($pv = $pvRes->fetch_assoc()) $purchasableVehicleTypes[] = $pv; }
    
    $factionBalance = 0;
    $fbRes = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($town['side']) . "'");
    if ($fbRes && $fb = $fbRes->fetch_assoc()) $factionBalance = (float)$fb['points'];
}

// Load vehicle types with build costs if vehicle factory exists
$buildableVehicleTypes = [];
if ($hasVehicleFactory) {
    $bvRes = $conn->query("
        SELECT vt.id, vt.name, vt.category, vt.vehicle_class, vt.max_speed_kmh,
               vt.fuel_capacity, vt.fuel_per_km, vt.max_capacity, vt.active,
               vt.cargo_class
        FROM vehicle_types vt
        WHERE vt.active = 1
        ORDER BY vt.name
    ");
    if ($bvRes) {
        while ($bv = $bvRes->fetch_assoc()) {
            $vtId = (int)$bv['id'];
            $bv['build_costs'] = [];
            $bcRes = $conn->query("
                SELECT vbc.amount, wp.resource_name, wp.image_file, wp.id as resource_id
                FROM vehicle_build_costs vbc
                JOIN world_prices wp ON vbc.resource_id = wp.id
                WHERE vbc.vehicle_type_id = $vtId
                ORDER BY wp.resource_name
            ");
            $canBuild = true;
            if ($bcRes) {
                while ($bc = $bcRes->fetch_assoc()) {
                    $stRes2 = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
                    $stRow2 = $stRes2 ? $stRes2->fetch_assoc() : null;
                    $bc['in_stock'] = $stRow2 ? (float)$stRow2['stock'] : 0;
                    $bc['enough'] = $bc['in_stock'] >= (float)$bc['amount'];
                    if (!$bc['enough']) $canBuild = false;
                    $bv['build_costs'][] = $bc;
                }
            }
            $bv['can_build'] = $canBuild && !empty($bv['build_costs']);
            $buildableVehicleTypes[] = $bv;
        }
    }
}

// Fetch vehicles in this town (check for health column)
$townVehicles = [];
$hasHealthCol = false;
$hcCheck = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'health'");
if ($hcCheck && $hcCheck->num_rows > 0) $hasHealthCol = true;

$vRes = $conn->query("
    SELECT v.id, v.status, v.faction, " . ($hasHealthCol ? "v.health," : "100 as health,") . "
           vt.name as type_name, vt.category, vt.vehicle_class,
           vt.max_speed_kmh, vt.fuel_capacity, vt.fuel_per_km, vt.max_capacity,
           vt.cargo_class, vt.points_price
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.town_id = $townId AND v.status IN ('idle','loading','towing')
    ORDER BY vt.name
");
if ($vRes) { while ($v = $vRes->fetch_assoc()) $townVehicles[] = $v; }

// Fetch vehicles being repaired at this town
$repairingVehicles = [];
$rvrCheck = $conn->query("SHOW TABLES LIKE 'vehicle_repairs'");
if ($rvrCheck && $rvrCheck->num_rows > 0) {
    $rpRes = $conn->query("
        SELECT v.id, v.faction, " . ($hasHealthCol ? "v.health," : "100 as health,") . "
               vt.name as type_name, vt.category, vt.vehicle_class, vt.points_price,
               vr.start_health, vr.target_health, vr.cost_total, vr.started_at
        FROM vehicle_repairs vr
        JOIN vehicles v ON vr.vehicle_id = v.id
        JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE vr.town_id = $townId AND v.status = 'repairing'
        ORDER BY vr.started_at
    ");
    if ($rpRes) { while ($rp = $rpRes->fetch_assoc()) $repairingVehicles[] = $rp; }
}

// Fetch scrapped vehicles at this town
$scrappedVehicles = [];
if ($hasHealthCol) {
    $scRes = $conn->query("
        SELECT v.id, v.health, vt.name as type_name, vt.category
        FROM vehicles v
        JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE v.town_id = $townId AND v.status = 'scrapped'
        ORDER BY vt.name
    ");
    if ($scRes) { while ($sc = $scRes->fetch_assoc()) $scrappedVehicles[] = $sc; }
}

// Fetch workshop for this town
$workshop = null;
$wsTableCheck = $conn->query("SHOW TABLES LIKE 'workshops'");
if ($wsTableCheck && $wsTableCheck->num_rows > 0) {
    $workshop = $conn->query("SELECT id, level FROM workshops WHERE town_id = $townId")->fetch_assoc();
}
$workshopLevel = $workshop ? (int)$workshop['level'] : 0;
$workshopSlots = $workshopLevel;
$workshopActiveRepairs = count($repairingVehicles);

// Fetch available tow trucks in connected towns (for tow requests)
$availableTowTrucks = [];
$towTruckRes = $conn->query("
    SELECT v.id, v.town_id, t.name as town_name, vt.name as type_name,
           td.distance_km, COALESCE(r.speed_limit, 30) as speed_limit, vt.max_speed_kmh, vt.fuel_per_km
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    JOIN towns t ON v.town_id = t.id
    JOIN town_distances td ON td.town_id_1 = v.town_id AND td.town_id_2 = $townId
    LEFT JOIN roads r ON (r.town_id_1 = LEAST(v.town_id, $townId) AND r.town_id_2 = GREATEST(v.town_id, $townId))
    WHERE vt.vehicle_class = 'tow' AND v.status = 'idle' AND v.town_id != $townId
    ORDER BY td.distance_km
");
if ($towTruckRes) { while ($tt = $towTruckRes->fetch_assoc()) $availableTowTrucks[] = $tt; }

// Fetch towns with workshops (for tow destinations)
$workshopTowns = [];
$wtRes = $conn->query("
    SELECT w.town_id, t.name, w.level,
           td.distance_km
    FROM workshops w
    JOIN towns t ON w.town_id = t.id
    JOIN town_distances td ON td.town_id_1 = $townId AND td.town_id_2 = w.town_id
    WHERE w.level > 0
    ORDER BY td.distance_km
");
if ($wtRes) { while ($wt = $wtRes->fetch_assoc()) $workshopTowns[] = $wt; }
// Also include this town if it has a workshop
if ($workshop) {
    array_unshift($workshopTowns, ['town_id' => $townId, 'name' => $town['name'] . ' (here)', 'level' => $workshopLevel, 'distance_km' => 0]);
}

// Get all towns for dispatch dropdown (with distances and road types)
$dispatchTargets = [];
$dtRes = $conn->query("
    SELECT t.id, t.name, t.side, td.distance_km,
           COALESCE(r.road_type, 'mud') as road_type,
           COALESCE(r.speed_limit, 30) as speed_limit
    FROM town_distances td
    JOIN towns t ON t.id = td.town_id_2
    LEFT JOIN roads r ON (r.town_id_1 = LEAST(td.town_id_1, td.town_id_2) AND r.town_id_2 = GREATEST(td.town_id_1, td.town_id_2))
    WHERE td.town_id_1 = $townId
    ORDER BY td.distance_km
");
if ($dtRes) { while ($dt = $dtRes->fetch_assoc()) $dispatchTargets[] = $dt; }

// Cargo resources for vehicle loading (grouped by resource_type/class)
$cargoResources = [];
$crRes = $conn->query("SELECT wp.id, wp.resource_name, wp.resource_type, COALESCE(tr.stock, 0) as stock
    FROM world_prices wp
    LEFT JOIN town_resources tr ON tr.resource_id = wp.id AND tr.town_id = $townId
    ORDER BY wp.resource_type, wp.resource_name");
if ($crRes) { while ($cr = $crRes->fetch_assoc()) $cargoResources[] = $cr; }

// Vehicles in transit from/to this town
$inTransit = [];
$trRes = $conn->query("
    SELECT vt2.id as trip_id, vt2.departed_at, vt2.eta_at, vt2.distance_km, vt2.speed_kmh,
           vt2.return_empty, vt2.arrived,
           v.id as vehicle_id, vtype.name as vehicle_name, vtype.vehicle_class,
           ft.name as from_name, tt.name as to_name
    FROM vehicle_trips vt2
    JOIN vehicles v ON vt2.vehicle_id = v.id
    JOIN vehicle_types vtype ON v.vehicle_type_id = vtype.id
    JOIN towns ft ON vt2.from_town_id = ft.id
    JOIN towns tt ON vt2.to_town_id = tt.id
    WHERE vt2.arrived = 0 AND (vt2.from_town_id = $townId OR vt2.to_town_id = $townId)
    ORDER BY vt2.eta_at
");
if ($trRes) { while ($tr = $trRes->fetch_assoc()) $inTransit[] = $tr; }

// Load cargo details for in-transit trips
$transitCargo = [];
if (!empty($inTransit)) {
    $tripIds = array_column($inTransit, 'trip_id');
    $tripIdsStr = implode(',', array_map('intval', $tripIds));
    $tcRes = $conn->query("SELECT vtc.trip_id, vtc.amount, wp.resource_name
        FROM vehicle_trip_cargo vtc
        JOIN world_prices wp ON vtc.resource_id = wp.id
        WHERE vtc.trip_id IN ($tripIdsStr)
        ORDER BY wp.resource_name");
    if ($tcRes) { while ($tc = $tcRes->fetch_assoc()) $transitCargo[(int)$tc['trip_id']][] = $tc; }
}

// ════════════════════════════════════════════
// TRAIN DATA
// ════════════════════════════════════════════

// Locomotives in this town
$townLocos = [];
$locoRes = $conn->query("
    SELECT l.id, l.status, l.faction,
           lt.name as type_name, lt.propulsion, lt.max_speed_kmh, lt.fuel_per_km,
           lt.max_wagons, lt.fuel_resource_id,
           wp.resource_name as fuel_name
    FROM locomotives l
    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
    LEFT JOIN world_prices wp ON lt.fuel_resource_id = wp.id
    WHERE l.town_id = $townId AND l.status = 'idle'
    ORDER BY lt.name
");
if ($locoRes) { while ($lo = $locoRes->fetch_assoc()) $townLocos[] = $lo; }

// Wagons in this town (not attached to any train)
$townWagons = [];
$wagonRes = $conn->query("
    SELECT w.id, w.status, w.faction, w.train_id,
           wt.name as type_name, wt.wagon_class, wt.cargo_class, wt.max_capacity
    FROM wagons w
    JOIN wagon_types wt ON w.wagon_type_id = wt.id
    WHERE w.town_id = $townId AND w.status = 'idle' AND w.train_id IS NULL
    ORDER BY wt.wagon_class, wt.name
");
if ($wagonRes) { while ($wa = $wagonRes->fetch_assoc()) $townWagons[] = $wa; }

// Composed trains in this town
$townTrains = [];
$trainRes = $conn->query("
    SELECT t.id, t.name, t.status, t.faction,
           l.id as loco_id, lt.name as loco_name, lt.propulsion, lt.max_speed_kmh,
           lt.fuel_per_km, lt.max_wagons, lt.fuel_resource_id,
           wp.resource_name as fuel_name
    FROM trains t
    JOIN locomotives l ON t.locomotive_id = l.id
    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
    LEFT JOIN world_prices wp ON lt.fuel_resource_id = wp.id
    WHERE t.town_id = $townId AND t.status IN ('idle','loading')
    ORDER BY t.name
");
if ($trainRes) {
    while ($tr = $trainRes->fetch_assoc()) {
        $tid = (int)$tr['id'];
        // Load consist (wagons attached)
        $tr['wagons'] = [];
        $cwRes = $conn->query("
            SELECT tc.position, w.id as wagon_id, wt.name as wagon_name, wt.wagon_class,
                   wt.cargo_class, wt.max_capacity
            FROM train_consist tc
            JOIN wagons w ON tc.wagon_id = w.id
            JOIN wagon_types wt ON w.wagon_type_id = wt.id
            WHERE tc.train_id = $tid
            ORDER BY tc.position
        ");
        if ($cwRes) { while ($cw = $cwRes->fetch_assoc()) $tr['wagons'][] = $cw; }
        $townTrains[] = $tr;
    }
}

// Train dispatch targets (with rail info)
$trainTargets = [];
$ttRes = $conn->query("
    SELECT t.id, t.name, t.side, td.distance_km,
           rl.rail_type, rl.speed_limit
    FROM town_distances td
    JOIN towns t ON t.id = td.town_id_2
    LEFT JOIN rail_lines rl ON (
        (rl.town_id_1 = LEAST(td.town_id_1, td.town_id_2) AND rl.town_id_2 = GREATEST(td.town_id_1, td.town_id_2))
    )
    WHERE td.town_id_1 = $townId AND rl.id IS NOT NULL
    ORDER BY td.distance_km
");
if ($ttRes) { while ($tt = $ttRes->fetch_assoc()) $trainTargets[] = $tt; }

// Trains in transit from/to this town
$trainsInTransit = [];
$ttripRes = $conn->query("
    SELECT tt.id as trip_id, tt.departed_at, tt.eta_at, tt.distance_km, tt.speed_kmh,
           tt.return_empty, tt.arrived,
           t.id as train_id, t.name as train_name,
           lt.name as loco_name, lt.propulsion,
           ft.name as from_name, tto.name as to_name
    FROM train_trips tt
    JOIN trains t ON tt.train_id = t.id
    JOIN locomotives l ON t.locomotive_id = l.id
    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
    JOIN towns ft ON tt.from_town_id = ft.id
    JOIN towns tto ON tt.to_town_id = tto.id
    WHERE tt.arrived = 0 AND (tt.from_town_id = $townId OR tt.to_town_id = $townId)
    ORDER BY tt.eta_at
");
if ($ttripRes) { while ($ttr = $ttripRes->fetch_assoc()) $trainsInTransit[] = $ttr; }

// Load cargo for trains in transit
$trainTransitCargo = [];
if (!empty($trainsInTransit)) {
    $tTripIds = array_column($trainsInTransit, 'trip_id');
    $tTripIdsStr = implode(',', array_map('intval', $tTripIds));
    $ttcRes = $conn->query("SELECT ttc.trip_id, ttc.wagon_id, ttc.amount, wp.resource_name
        FROM train_trip_cargo ttc
        JOIN world_prices wp ON ttc.resource_id = wp.id
        WHERE ttc.trip_id IN ($tTripIdsStr)
        ORDER BY wp.resource_name");
    if ($ttcRes) { while ($ttc = $ttcRes->fetch_assoc()) $trainTransitCargo[(int)$ttc['trip_id']][] = $ttc; }
}

// Buildable locomotive/wagon types (if vehicle factory exists)
$buildableLocoTypes = [];
$buildableWagonTypes = [];
if ($hasVehicleFactory) {
    // Locomotive types
    $blRes = $conn->query("SELECT lt.id, lt.name, lt.propulsion, lt.max_speed_kmh, lt.fuel_per_km,
            lt.max_wagons, lt.build_time_ticks, lt.active
        FROM locomotive_types lt WHERE lt.active = 1 ORDER BY lt.name");
    if ($blRes) {
        while ($bl = $blRes->fetch_assoc()) {
            $ltId = (int)$bl['id'];
            $bl['build_costs'] = [];
            $blcRes = $conn->query("SELECT lbc.amount, wp.resource_name, wp.image_file, wp.id as resource_id
                FROM locomotive_build_costs lbc JOIN world_prices wp ON lbc.resource_id = wp.id
                WHERE lbc.locomotive_type_id = $ltId ORDER BY wp.resource_name");
            $canBuild = true;
            if ($blcRes) {
                while ($bc = $blcRes->fetch_assoc()) {
                    $stCheck = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
                    $stRow = $stCheck ? $stCheck->fetch_assoc() : null;
                    $bc['in_stock'] = $stRow ? (float)$stRow['stock'] : 0;
                    $bc['enough'] = $bc['in_stock'] >= (float)$bc['amount'];
                    if (!$bc['enough']) $canBuild = false;
                    $bl['build_costs'][] = $bc;
                }
            }
            $bl['can_build'] = $canBuild && !empty($bl['build_costs']);
            $buildableLocoTypes[] = $bl;
        }
    }
    // Wagon types
    $bwRes = $conn->query("SELECT wt.id, wt.name, wt.wagon_class, wt.cargo_class, wt.max_capacity,
            wt.build_time_ticks, wt.active
        FROM wagon_types wt WHERE wt.active = 1 ORDER BY wt.name");
    if ($bwRes) {
        while ($bw = $bwRes->fetch_assoc()) {
            $wtId = (int)$bw['id'];
            $bw['build_costs'] = [];
            $bwcRes = $conn->query("SELECT wbc.amount, wp.resource_name, wp.image_file, wp.id as resource_id
                FROM wagon_build_costs wbc JOIN world_prices wp ON wbc.resource_id = wp.id
                WHERE wbc.wagon_type_id = $wtId ORDER BY wp.resource_name");
            $canBuild = true;
            if ($bwcRes) {
                while ($bc = $bwcRes->fetch_assoc()) {
                    $stCheck = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$bc['resource_id']}");
                    $stRow = $stCheck ? $stCheck->fetch_assoc() : null;
                    $bc['in_stock'] = $stRow ? (float)$stRow['stock'] : 0;
                    $bc['enough'] = $bc['in_stock'] >= (float)$bc['amount'];
                    if (!$bc['enough']) $canBuild = false;
                    $bw['build_costs'][] = $bc;
                }
            }
            $bw['can_build'] = $canBuild && !empty($bw['build_costs']);
            $buildableWagonTypes[] = $bw;
        }
    }
}

// Fetch distances to other towns (with road info)
$distResult = $conn->query("
    SELECT t.id, t.name, td.distance_km,
           COALESCE(r.road_type, 'mud') as road_type,
           COALESCE(r.speed_limit, 30) as speed_limit
    FROM town_distances td
    JOIN towns t ON t.id = td.town_id_2
    LEFT JOIN roads r ON (r.town_id_1 = LEAST(td.town_id_1, td.town_id_2) AND r.town_id_2 = GREATEST(td.town_id_1, td.town_id_2))
    WHERE td.town_id_1 = $townId
    ORDER BY td.distance_km
");

// ── Military buildings data ──
$barracksData = null;
$munitionsData = null;
$barCheck = $conn->query("SHOW TABLES LIKE 'town_barracks'");
if ($barCheck && $barCheck->num_rows > 0) {
    $bRes2 = $conn->query("SELECT * FROM town_barracks WHERE town_id = $townId");
    $barracksData = ($bRes2 && $bRes2->num_rows > 0) ? $bRes2->fetch_assoc() : null;
}
$munCheck = $conn->query("SHOW TABLES LIKE 'town_munitions_factory'");
if ($munCheck && $munCheck->num_rows > 0) {
    $mRes2 = $conn->query("SELECT * FROM town_munitions_factory WHERE town_id = $townId");
    $munitionsData = ($mRes2 && $mRes2->num_rows > 0) ? $mRes2->fetch_assoc() : null;
}

// Barracks upgrade costs
$barracksUpgradeCosts = [];
$barracksCanUpgrade = false;
if ($barracksData) {
    $bNextLvl = (int)$barracksData['level'] + 1;
    if ($bNextLvl <= 10) {
        $bucRes = $conn->query("SELECT buc.resource_id, buc.amount, wp.resource_name, wp.image_file
            FROM barracks_upgrade_costs buc JOIN world_prices wp ON buc.resource_id = wp.id
            WHERE buc.target_level = $bNextLvl ORDER BY wp.resource_name");
        $barracksCanUpgrade = true;
        if ($bucRes) {
            while ($buc = $bucRes->fetch_assoc()) {
                $sRes3 = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$buc['resource_id']}");
                $sRow3 = $sRes3 ? $sRes3->fetch_assoc() : null;
                $buc['in_stock'] = $sRow3 ? (float)$sRow3['stock'] : 0;
                $buc['enough'] = $buc['in_stock'] >= (float)$buc['amount'];
                if (!$buc['enough']) $barracksCanUpgrade = false;
                $barracksUpgradeCosts[] = $buc;
            }
        }
    }
}

// Munitions upgrade costs
$munitionsUpgradeCosts = [];
$munitionsCanUpgrade = false;
if ($munitionsData) {
    $mNextLvl = (int)$munitionsData['level'] + 1;
    if ($mNextLvl <= 10) {
        $mucRes = $conn->query("SELECT muc.resource_id, muc.amount, wp.resource_name, wp.image_file
            FROM munitions_upgrade_costs muc JOIN world_prices wp ON muc.resource_id = wp.id
            WHERE muc.target_level = $mNextLvl ORDER BY wp.resource_name");
        $munitionsCanUpgrade = true;
        if ($mucRes) {
            while ($muc = $mucRes->fetch_assoc()) {
                $sRes4 = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$muc['resource_id']}");
                $sRow4 = $sRes4 ? $sRes4->fetch_assoc() : null;
                $muc['in_stock'] = $sRow4 ? (float)$sRow4['stock'] : 0;
                $muc['enough'] = $muc['in_stock'] >= (float)$muc['amount'];
                if (!$muc['enough']) $munitionsCanUpgrade = false;
                $munitionsUpgradeCosts[] = $muc;
            }
        }
    }
}

// Garrison troops
$garrisonTroops = [];
$gtCheck = $conn->query("SHOW TABLES LIKE 'town_troops'");
if ($gtCheck && $gtCheck->num_rows > 0) {
    $gtRes = $conn->query("
        SELECT tt.*, COALESCE(wt.name, 'Unarmed') as weapon_name,
               COALESCE(wt.attack_stat, 3) as attack_stat, COALESCE(wt.defense_stat, 2) as defense_stat
        FROM town_troops tt
        LEFT JOIN weapon_types wt ON tt.weapon_type_id = wt.id
        WHERE tt.town_id = $townId AND tt.faction = '{$town['side']}'
        ORDER BY wt.name
    ");
    if ($gtRes) { while ($gt = $gtRes->fetch_assoc()) $garrisonTroops[] = $gt; }
}
$totalGarrison = array_sum(array_column($garrisonTroops, 'quantity'));

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header"><?= htmlspecialchars($town['name']) ?> <small class="text-inverse text-opacity-50"><?= ucfirst($town['side']) ?> Faction</small></h1>

			<?php if ($msg === 'upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">Mine upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'insufficient'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough resources. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'max_level'): ?>
				<div class="alert alert-warning alert-dismissible fade show">Already at maximum level. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'power_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">Power station built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'power_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">Power station upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'line_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">Transmission line built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'line_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">Transmission line upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'wrong_faction'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Cannot connect to enemy faction towns. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'line_exists'): ?>
				<div class="alert alert-warning alert-dismissible fade show">Transmission line already exists. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'housing_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">Housing built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'factory_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">Factory built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'factory_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">Factory upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'factory_exists'): ?>
				<div class="alert alert-warning alert-dismissible fade show">Factory already exists in this town. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'vehicle_dispatched'): ?>
				<div class="alert alert-success alert-dismissible fade show">Vehicle dispatched! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'vehicle_immobilised'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Vehicle is immobilised — health below 50%. Repair at workshop or request a tow. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'tow_dispatched'): ?>
				<div class="alert alert-success alert-dismissible fade show">Tow truck dispatched! Vehicle will be towed to the workshop. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'tow_no_fuel'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough fuel in the tow truck's town for this tow trip. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'tow_invalid'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Invalid tow request — check that the tow truck and destination are valid. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'no_fuel'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough fuel in town for this trip. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'no_cargo'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough cargo in town. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'wrong_class'): ?>
				<div class="alert alert-danger alert-dismissible fade show">That resource doesn't match this vehicle's cargo class. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'road_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">Road upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'vehicle_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">Vehicle built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'no_factory'): ?>
				<div class="alert alert-danger alert-dismissible fade show">This town does not have a Vehicle Factory. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'immigrants_arrived'): ?>
				<div class="alert alert-success alert-dismissible fade show">Immigrants have arrived at their destination! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'no_housing_space'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough housing space in the target town. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'insufficient_funds'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough points to complete this action. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'barracks_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">🏰 Barracks built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'barracks_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">🏰 Barracks upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'munitions_built'): ?>
				<div class="alert alert-success alert-dismissible fade show">🏭 Munitions factory built! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'munitions_upgraded'): ?>
				<div class="alert alert-success alert-dismissible fade show">🏭 Munitions factory upgraded! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'already_built'): ?>
				<div class="alert alert-warning alert-dismissible fade show">This building already exists in this town. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'workers_updated'): ?>
				<div class="alert alert-success alert-dismissible fade show">Workers updated! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'vehicle_purchased'): ?>
				<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-truck me-1"></i>Vehicle purchased and ready at the customs house! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'insufficient_points'): ?>
				<div class="alert alert-danger alert-dismissible fade show">Not enough faction points to purchase this vehicle. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php endif; ?>

			<!-- Town Info -->
			<?php if ($isCustomsHouse): ?>
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-warehouse me-1"></i>Customs House</div>
				<div class="card-body">
					<p class="mb-1">This is a <strong>customs house</strong> — a border trading post with no permanent population.</p>
					<ul class="mb-0 small text-inverse text-opacity-75">
						<li>Buy and sell resources on the <a href="trade.php" class="text-theme">global market</a></li>
						<li>Purchased goods are stored here — dispatch vehicles to move them to towns</li>
						<li>Sell resources by transporting them here from your towns</li>
						<li>Import vehicles and rolling stock below using faction points</li>
					</ul>
				</div>
			</div>

			<!-- Purchase Vehicles at Customs House -->
			<?php if (!empty($purchasableVehicleTypes)): ?>
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-truck me-1"></i>Import Vehicles</span>
					<span class="badge bg-info">Balance: <?= number_format($factionBalance) ?> pts</span>
				</div>
				<div class="card-body">
					<div class="row">
						<?php foreach ($purchasableVehicleTypes as $pv): 
							$canAfford = $factionBalance >= (int)$pv['points_price'];
						?>
						<div class="col-lg-4 col-md-6 mb-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-body py-2 px-3">
									<div class="d-flex justify-content-between align-items-center mb-1">
										<span class="fw-bold small"><?= htmlspecialchars($pv['name']) ?></span>
										<span>
											<?= $pv['category'] === 'military' ? '<span class="badge bg-danger" style="font-size:0.65em">MIL</span>' : '<span class="badge bg-info" style="font-size:0.65em">CIV</span>' ?>
											<?= $pv['vehicle_class'] === 'cargo' ? '<span class="badge bg-success" style="font-size:0.65em">Cargo</span>' : '<span class="badge bg-primary" style="font-size:0.65em">Pax</span>' ?>
										</span>
									</div>
									<div class="small text-inverse text-opacity-50 mb-1">
										Speed: <?= $pv['max_speed_kmh'] ?> km/h | Cap: <?= $pv['max_capacity'] ?>t
										<?php if ($pv['cargo_class']): ?> | <?= htmlspecialchars($pv['cargo_class']) ?><?php endif; ?>
									</div>
									<div class="small text-inverse text-opacity-50 mb-2">
										Fuel: <?= $pv['fuel_capacity'] ?>t (<?= $pv['fuel_per_km'] ?>/km)
									</div>
									<div class="d-flex justify-content-between align-items-center">
										<span class="fw-bold <?= $canAfford ? 'text-success' : 'text-danger' ?>"><?= number_format($pv['points_price']) ?> pts</span>
										<form method="post" action="mine_action.php" class="d-inline">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="purchase_vehicle">
											<input type="hidden" name="vehicle_type_id" value="<?= $pv['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-theme<?= !$canAfford ? ' disabled' : '' ?>"<?= !$canAfford ? ' disabled' : '' ?>>
												<i class="fa fa-cart-plus me-1"></i>Buy
											</button>
										</form>
									</div>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Immigration -->
			<?php
			$immigrationCostPerPerson = 10;
			$factionForImmigration = $town['side'];
			$immBalRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($factionForImmigration) . "'")->fetch_assoc();
			$immBalance = $immBalRow ? (float)$immBalRow['points'] : 0;
			$maxImmigrants = floor($immBalance / $immigrationCostPerPerson);

			// Get target towns with housing capacity
			$immigrationTargets = [];
			$immTowns = $conn->query("
				SELECT t.id, t.name, t.population,
				       COALESCE(h.houses,0) AS houses, COALESCE(h.small_flats,0) AS small_flats,
				       COALESCE(h.medium_flats,0) AS medium_flats, COALESCE(h.large_flats,0) AS large_flats
				FROM towns t
				LEFT JOIN town_housing h ON t.id = h.town_id
				WHERE t.side = '" . $conn->real_escape_string($factionForImmigration) . "'
				  AND t.name NOT LIKE 'Customs House%'
				  AND t.population > 0
				ORDER BY t.name
			");
			if ($immTowns) {
				while ($it = $immTowns->fetch_assoc()) {
					$totalHomes = (int)$it['houses'] + (int)$it['small_flats'] * 10 + (int)$it['medium_flats'] * 50 + (int)$it['large_flats'] * 100;
					$maxPop = $totalHomes * 5;
					$space = max(0, $maxPop - (int)$it['population']);
					$it['max_pop'] = $maxPop;
					$it['space'] = $space;
					$immigrationTargets[] = $it;
				}
			}
			?>
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-users me-1"></i>Immigration</span>
					<span class="badge bg-theme"><?= number_format($immBalance, 0) ?> pts available</span>
				</div>
				<div class="card-body">
					<p class="small text-inverse text-opacity-75 mb-2">
						Invite immigrants to join your faction. Cost: <strong><?= $immigrationCostPerPerson ?> points per person</strong>.
						Immigrants will be sent to the chosen town (must have housing space).
					</p>
					<?php if (!empty($immigrationTargets)): ?>
					<form method="post" action="mine_action.php" class="row g-2 align-items-end">
						<input type="hidden" name="town_id" value="<?= $townId ?>">
						<input type="hidden" name="action" value="invite_immigrants">
						<div class="col-md-5">
							<label class="form-label small">Destination Town</label>
							<select name="target_town_id" class="form-select form-select-sm">
								<?php foreach ($immigrationTargets as $it): ?>
								<option value="<?= $it['id'] ?>" <?= $it['space'] <= 0 ? 'disabled' : '' ?>>
									<?= htmlspecialchars($it['name']) ?> — Pop: <?= number_format($it['population']) ?>/<?= number_format($it['max_pop']) ?> (<?= number_format($it['space']) ?> space)
								</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-3">
							<label class="form-label small">Number of People</label>
							<input type="number" name="immigrant_count" class="form-control form-control-sm" value="1" min="1" max="<?= max(1, $maxImmigrants) ?>">
						</div>
						<div class="col-md-2">
							<label class="form-label small">Cost</label>
							<div class="form-control form-control-sm bg-dark bg-opacity-25 text-center" id="immigrationCost"><?= $immigrationCostPerPerson ?> pts</div>
						</div>
						<div class="col-md-2">
							<button type="submit" class="btn btn-sm btn-theme w-100" <?= $maxImmigrants < 1 ? 'disabled' : '' ?>>
								<i class="fa fa-user-plus me-1"></i>Invite
							</button>
						</div>
					</form>
					<script>
					document.querySelector('[name="immigrant_count"]')?.addEventListener('input', function() {
						const cost = Math.max(1, parseInt(this.value) || 0) * <?= $immigrationCostPerPerson ?>;
						document.getElementById('immigrationCost').textContent = cost.toLocaleString() + ' pts';
					});
					</script>
					<?php else: ?>
					<p class="text-inverse text-opacity-50 small mb-0">No towns available for immigration.</p>
					<?php endif; ?>
				</div>
			</div>
			<?php else: ?>
			<div class="card mb-3">
				<div class="card-header fw-bold small">Town Info</div>
				<div class="card-body">
					<div class="row">
						<div class="col-md-6">
							<p class="mb-1"><strong>Population:</strong> <?= number_format($town['population']) ?> / <?= number_format($maxPopulation) ?>
								<?php if ($town['population'] >= $maxPopulation): ?>
									<span class="badge bg-danger ms-1">At Capacity</span>
								<?php endif; ?>
							</p>
							<div class="progress mb-2" style="height: 8px;">
								<div class="progress-bar <?= $popPct >= 100 ? 'bg-danger' : ($popPct >= 80 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= min($popPct, 100) ?>%"></div>
							</div>
							<p class="mb-1"><strong>Workers:</strong> <?= number_format($workersUsed) ?> assigned / <?= number_format($workersAvailable) ?> available
								<?php if ($workersAvailable <= 0 && $town['population'] > 0): ?>
									<span class="badge bg-warning ms-1">No Workers Free</span>
								<?php endif; ?>
							</p>
							<div class="progress mb-2" style="height: 8px;">
								<div class="progress-bar <?= $workerPct >= 100 ? 'bg-danger' : ($workerPct >= 80 ? 'bg-warning' : 'bg-info') ?>" style="width: <?= min($workerPct, 100) ?>%"></div>
							</div>
							<?php if ($prodData): ?>
								<p class="mb-0"><strong>Speciality:</strong> <?= htmlspecialchars($prodData['resource_name']) ?>
								 — <span class="text-theme"><?= htmlspecialchars($mineInfo['facilityName']) ?> (Level <?= $mineInfo['level'] ?>)</span></p>
							<?php endif; ?>
						</div>
						<div class="col-md-6">
							<table class="table table-sm table-borderless mb-0">
								<tbody>
									<tr><td class="text-inverse text-opacity-50 py-0">Houses</td><td class="py-0 fw-bold"><?= number_format((int)$housingData['houses']) ?></td></tr>
									<tr><td class="text-inverse text-opacity-50 py-0">Small Flats</td><td class="py-0 fw-bold"><?= number_format((int)$housingData['small_flats']) ?> <small class="text-muted">(<?= number_format((int)$housingData['small_flats'] * 10) ?> homes)</small></td></tr>
									<tr><td class="text-inverse text-opacity-50 py-0">Medium Flats</td><td class="py-0 fw-bold"><?= number_format((int)$housingData['medium_flats']) ?> <small class="text-muted">(<?= number_format((int)$housingData['medium_flats'] * 50) ?> homes)</small></td></tr>
									<tr><td class="text-inverse text-opacity-50 py-0">Large Flats</td><td class="py-0 fw-bold"><?= number_format((int)$housingData['large_flats']) ?> <small class="text-muted">(<?= number_format((int)$housingData['large_flats'] * 100) ?> homes)</small></td></tr>
									<tr class="table-active"><td class="fw-bold py-0">Total Homes</td><td class="py-0 fw-bold text-theme"><?= number_format($totalHomes) ?> <small>(<?= number_format($maxPopulation) ?> citizens max)</small></td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			<?php endif; /* end customs/town info */ ?>

			<!-- Resources Row -->
			<div class="row">
				<div class="<?= $isCustomsHouse ? 'col-12' : 'col-lg-7' ?>">
					<div class="card mb-3">
						<div class="card-header fw-bold small">Resources in Stock</div>
						<div class="card-body">
							<table class="table table-hover">
								<thead>
									<tr>
										<th scope="col"></th>
										<th scope="col">Resource</th>
										<th scope="col">Stock</th>
									</tr>
								</thead>
								<tbody>
								<?php
								$currentType = '';
								while ($r = $resources->fetch_assoc()):
									if ($r['resource_type'] !== $currentType):
										$currentType = $r['resource_type'];
								?>
									<tr class="table-dark"><td colspan="3" class="fw-bold small"><?= htmlspecialchars($currentType) ?></td></tr>
								<?php endif;
									$img = $r['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($r['image_file']) . '" alt="' . htmlspecialchars($r['resource_name']) . '" width="32" height="32">' : '';
								?>
									<tr>
										<td><?= $img ?></td>
										<td><?= htmlspecialchars($r['resource_name']) ?></td>
										<td><?= number_format($r['stock'], 2) ?></td>
									</tr>
								<?php endwhile; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<?php if (!$isCustomsHouse): ?>
				<div class="col-lg-5">
					<!-- Mine Card -->
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<?php if ($mineInfo): ?>
								<?= htmlspecialchars($mineInfo['facilityName']) ?>
								<span class="badge bg-theme">Level <?= $mineInfo['level'] ?></span>
							<?php else: ?>
								Production
							<?php endif; ?>
						</div>
						<div class="card-body">
							<?php if ($mineInfo):
								$prodImg = $prodData['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($prodData['image_file']) . '" alt="" width="32" height="32">' : '';
								$wPct = round($mineInfo['workerEff'] * 100);
								$pPct = round($mineInfo['powerEff'] * 100);
							?>
								<div class="d-flex align-items-center mb-3">
									<?= $prodImg ?>
									<span class="ms-2 fs-5 fw-bold"><?= htmlspecialchars($prodData['resource_name']) ?></span>
								</div>

								<!-- Production Stats -->
								<table class="table table-hover table-sm mb-3">
									<tbody>
										<tr>
											<td class="text-inverse text-opacity-50">Base Rate</td>
											<td class="fw-bold"><?= number_format($mineInfo['baseRate'], 1) ?> t/hr</td>
										</tr>
										<tr>
											<td class="text-inverse text-opacity-50">Worker Efficiency</td>
											<td class="fw-bold<?= $wPct < 50 ? ' text-warning' : '' ?><?= $wPct === 0 ? ' text-danger' : '' ?>"><?= $wPct ?>%</td>
										</tr>
										<tr>
											<td class="text-inverse text-opacity-50">Grid Power</td>
											<td class="fw-bold<?= $pPct < 100 && $pPct > 0 ? ' text-warning' : '' ?><?= $pPct === 0 ? ' text-danger' : '' ?>">
												<?= $pPct ?>%
												<?php if ($pPct === 0): ?><small class="text-danger ms-1">(No power!)</small><?php endif; ?>
											</td>
										</tr>
										<tr>
											<td class="text-inverse text-opacity-50">Power Required</td>
											<td class="fw-bold"><?= number_format($mineInfo['powerRequired'], 1) ?> MW</td>
										</tr>
										<tr class="table-active">
											<td class="fw-bold">Actual Output</td>
											<td class="fw-bold text-theme"><?= number_format($mineInfo['actualOutput'], 2) ?> t/hr</td>
										</tr>
										<tr>
											<td class="text-inverse text-opacity-50">Daily Output</td>
											<td class="fw-bold"><?= number_format($mineInfo['actualOutput'] * 24, 1) ?> t/day</td>
										</tr>
									</tbody>
								</table>

								<!-- Workers -->
								<div class="card bg-dark bg-opacity-50 mb-3">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<small class="fw-bold"><i class="fa fa-users me-1"></i>Workers</small>
											<small><?= $mineInfo['workersAssigned'] ?> / <?= $mineInfo['maxWorkers'] ?></small>
										</div>
										<div class="progress mb-2" style="height: 8px;">
											<div class="progress-bar<?= $wPct < 50 ? ' bg-warning' : ' bg-success' ?><?= $wPct === 0 ? ' bg-danger' : '' ?>" style="width: <?= $wPct ?>%"></div>
										</div>
										<form method="post" action="mine_action.php" class="d-flex gap-2">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="set_workers">
											<input type="number" name="workers" class="form-control form-control-sm" value="<?= $mineInfo['workersAssigned'] ?>" min="0" max="<?= $mineInfo['maxWorkers'] ?>" style="width:100px;">
											<button type="submit" class="btn btn-sm btn-outline-theme">Set</button>
										</form>
									</div>
								</div>

								<!-- Upgrade Mine -->
								<div class="card bg-dark bg-opacity-50">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-2">
											<small class="fw-bold"><i class="fa fa-arrow-up me-1"></i>Upgrade to Level <?= $mineInfo['nextLevel'] ?></small>
										</div>
										<?php if (!empty($mineInfo['upgradeCosts'])): ?>
											<table class="table table-sm table-borderless mb-2">
												<tbody>
												<?php foreach ($mineInfo['upgradeCosts'] as $cost):
													$costImg = $cost['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($cost['image_file']) . '" alt="" width="20" height="20">' : '';
												?>
													<tr>
														<td class="py-1"><?= $costImg ?> <?= htmlspecialchars($cost['resource_name']) ?></td>
														<td class="py-1 text-end fw-bold"><?= number_format($cost['amount']) ?></td>
														<td class="py-1 text-end <?= $cost['enough'] ? 'text-success' : 'text-danger' ?>">
															(<?= number_format($cost['in_stock']) ?>)
															<i class="fa <?= $cost['enough'] ? 'fa-check' : 'fa-times' ?>"></i>
														</td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
											<form method="post" action="mine_action.php">
												<input type="hidden" name="town_id" value="<?= $townId ?>">
												<input type="hidden" name="action" value="upgrade_mine">
												<button type="submit" class="btn btn-sm btn-theme w-100<?= !$mineInfo['canUpgrade'] ? ' disabled' : '' ?>"<?= !$mineInfo['canUpgrade'] ? ' disabled' : '' ?>>
													<i class="fa fa-arrow-up me-1"></i>Upgrade <?= htmlspecialchars($mineInfo['facilityName']) ?>
												</button>
											</form>
										<?php else: ?>
											<p class="text-inverse text-opacity-50 mb-0 small">Maximum level reached.</p>
										<?php endif; ?>
									</div>
								</div>

								<?php if (!empty($mineInfo['upgradeCosts'])): ?>
								<div class="mt-2">
									<small class="text-inverse text-opacity-50">
										Level <?= $mineInfo['nextLevel'] ?>: <?= $mineInfo['nextLevel'] ?> t/hr base, <?= $mineInfo['nextLevel'] * 100 ?> max workers, <?= $mineInfo['nextLevel'] ?>.0 MW required
									</small>
								</div>
								<?php endif; ?>

							<?php else: ?>
								<p class="text-inverse text-opacity-50 mb-0">This location has no production.</p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Factories Card (under mine) -->
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-industry me-1"></i>Factories</span>
							<span class="badge bg-theme"><?= count($factoryData) ?> built</span>
						</div>
						<div class="card-body">
							<?php if (!empty($factoryData)): ?>
								<?php foreach ($factoryData as $factory):
									$fWPct = round($factory['worker_eff'] * 100);
									$fPPct = round($factory['power_eff'] * 100);
									$fInputs = $factoryInputs[$factory['factory_type_id']] ?? [];
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<small class="fw-bold"><?= htmlspecialchars($factory['display_name']) ?></small>
											<span class="badge bg-theme">Lvl <?= $factory['level'] ?></span>
										</div>

										<div class="small mb-1">
											<span class="text-inverse text-opacity-50">In:</span>
											<?php foreach ($fInputs as $ii => $inp): ?>
												<?= $ii > 0 ? ' + ' : '' ?><?= number_format($inp['amount_per_level'],2) ?>t <?= htmlspecialchars($inp['resource_name']) ?>
											<?php endforeach; ?>
										</div>
										<div class="small mb-1">
											<?php $outImg = $factory['output_image'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($factory['output_image']) . '" alt="" width="18" height="18">' : ''; ?>
											<span class="text-inverse text-opacity-50">Out:</span>
											<?= $outImg ?>
											<span class="fw-bold text-theme"><?= number_format($factory['actual_output'], 2) ?> t/hr</span>
											<?= htmlspecialchars($factory['output_name']) ?>
										</div>

										<table class="table table-sm table-borderless mb-1">
											<tr>
												<td class="text-inverse text-opacity-50 py-0">Power</td>
												<td class="py-0 fw-bold<?= $fPPct < 100 && $fPPct > 0 ? ' text-warning' : '' ?><?= $fPPct === 0 ? ' text-danger' : '' ?>"><?= number_format($factory['power_required'], 1) ?> MW (<?= $fPPct ?>%)</td>
											</tr>
											<tr>
												<td class="text-inverse text-opacity-50 py-0">Workers</td>
												<td class="py-0"><?= $factory['workers_assigned'] ?>/<?= $factory['max_workers'] ?> (<?= $fWPct ?>%)</td>
											</tr>
										</table>
										<div class="progress mb-2" style="height: 6px;">
											<div class="progress-bar<?= $fWPct < 50 ? ' bg-warning' : ' bg-success' ?><?= $fWPct === 0 ? ' bg-danger' : '' ?>" style="width: <?= $fWPct ?>%"></div>
										</div>

										<div class="d-flex gap-2">
											<form method="post" action="mine_action.php" class="d-flex gap-1 flex-grow-1">
												<input type="hidden" name="town_id" value="<?= $townId ?>">
												<input type="hidden" name="action" value="set_factory_workers">
												<input type="hidden" name="factory_id" value="<?= $factory['id'] ?>">
												<input type="number" name="workers" class="form-control form-control-sm" value="<?= $factory['workers_assigned'] ?>" min="0" max="<?= $factory['max_workers'] ?>" style="width:70px;">
												<button type="submit" class="btn btn-sm btn-outline-theme">Set</button>
											</form>
										</div>

										<!-- Upgrade costs -->
										<div class="mt-2 border-top pt-2">
											<small class="fw-bold"><i class="fa fa-arrow-up me-1"></i>Upgrade to Level <?= $factory['level'] + 1 ?></small>
											<div class="small text-inverse text-opacity-50 mb-1">
												+<?= (int)$factory['workers_per_level'] ?> max workers, +<?= number_format($factory['power_per_level'], 1) ?> MW, +<?= number_format($factory['output_per_level'], 2) ?> t/hr
											</div>
											<?php if (!empty($factory['upgrade_costs'])): ?>
												<table class="table table-sm table-borderless mb-1">
													<tbody>
													<?php foreach ($factory['upgrade_costs'] as $ucost):
														$ucImg = $ucost['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($ucost['image_file']) . '" alt="" width="16" height="16">' : '';
													?>
														<tr>
															<td class="py-0 small"><?= $ucImg ?> <?= htmlspecialchars($ucost['resource_name']) ?></td>
															<td class="py-0 text-end fw-bold small"><?= number_format($ucost['amount']) ?></td>
															<td class="py-0 text-end small <?= $ucost['enough'] ? 'text-success' : 'text-danger' ?>">
																(<?= number_format($ucost['in_stock']) ?>)
																<i class="fa <?= $ucost['enough'] ? 'fa-check' : 'fa-times' ?> fa-xs"></i>
															</td>
														</tr>
													<?php endforeach; ?>
													</tbody>
												</table>
												<form method="post" action="mine_action.php">
													<input type="hidden" name="town_id" value="<?= $townId ?>">
													<input type="hidden" name="action" value="upgrade_factory">
													<input type="hidden" name="factory_id" value="<?= $factory['id'] ?>">
													<button type="submit" class="btn btn-sm btn-outline-success w-100<?= !$factory['can_upgrade'] ? ' disabled' : '' ?>"<?= !$factory['can_upgrade'] ? ' disabled' : '' ?>>
														<i class="fa fa-arrow-up me-1"></i>Upgrade
													</button>
												</form>
											<?php else: ?>
												<p class="text-inverse text-opacity-50 mb-0 small">Maximum level reached.</p>
											<?php endif; ?>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-inverse text-opacity-50 small mb-2">No factories in this town yet.</p>
							<?php endif; ?>

							<!-- Build New Factory -->
							<?php if (!empty($availableFactoryTypes)): ?>
							<hr class="my-2">
							<small class="fw-bold d-block mb-2"><i class="fa fa-plus me-1"></i>Build New Factory</small>
							<?php
								// Encode cost data as JSON for JS
								$factoryCostJson = [];
								foreach ($availableFactoryTypes as $aft) {
									$aftCosts = $factoryBuildCosts[$aft['type_id']] ?? ['costs' => [], 'can_afford' => false];
									$aftInputs = $factoryInputs[$aft['type_id']] ?? [];
									$factoryCostJson[$aft['type_id']] = [
										'name' => $aft['display_name'],
										'output' => number_format($aft['output_per_level'], 2),
										'output_name' => $aft['output_name'],
										'workers' => (int)$aft['workers_per_level'],
										'power' => number_format($aft['power_per_level'], 1),
										'inputs' => array_map(fn($inp) => ['amount' => number_format($inp['amount_per_level'], 2), 'name' => $inp['resource_name']], $aftInputs),
										'costs' => array_map(fn($bc) => ['name' => $bc['resource_name'], 'img' => $bc['image_file'] ?? '', 'amount' => number_format($bc['amount']), 'stock' => number_format($bc['in_stock']), 'enough' => $bc['enough']], $aftCosts['costs']),
										'can_afford' => $aftCosts['can_afford'],
									];
								}
							?>
							<form method="post" action="mine_action.php">
								<input type="hidden" name="town_id" value="<?= $townId ?>">
								<input type="hidden" name="action" value="build_factory">
								<select name="factory_type" id="factory-type-select" class="form-select form-select-sm mb-2" onchange="showFactoryCost(this.value)">
									<option value="">— Select a factory —</option>
									<?php foreach ($availableFactoryTypes as $aft): ?>
										<option value="<?= htmlspecialchars($aft['type_id']) ?>">
											<?= htmlspecialchars($aft['display_name']) ?>
											— <?= number_format($aft['output_per_level'], 2) ?>t <?= htmlspecialchars($aft['output_name']) ?>/hr
										</option>
									<?php endforeach; ?>
								</select>
								<div id="factory-cost-detail" style="display:none;">
									<div id="factory-cost-info" class="small mb-2"></div>
									<button type="submit" id="factory-build-btn" class="btn btn-sm btn-outline-theme w-100" disabled>
										<i class="fa fa-plus me-1"></i>Build
									</button>
								</div>
							</form>
							<script>
							var _fCosts = <?= json_encode($factoryCostJson) ?>;
							function showFactoryCost(typeId) {
								var detail = document.getElementById('factory-cost-detail');
								var info = document.getElementById('factory-cost-info');
								var btn = document.getElementById('factory-build-btn');
								if (!typeId || !_fCosts[typeId]) { detail.style.display='none'; return; }
								var d = _fCosts[typeId];
								var html = '<div class="mb-1"><span class="text-inverse text-opacity-50">Produces:</span> <span class="text-theme fw-bold">'+d.output+'</span> t/hr '+d.output_name+'</div>';
								if (d.inputs.length) {
									html += '<div class="mb-1"><span class="text-inverse text-opacity-50">Consumes:</span> ';
									d.inputs.forEach(function(inp,i){ html += (i>0?' + ':'') + inp.amount+'t '+inp.name+'/hr'; });
									html += '</div>';
								}
								html += '<div class="mb-1"><span class="text-inverse text-opacity-50">Requires:</span> '+d.workers+' workers, '+d.power+' MW</div>';
								if (d.costs.length) {
									html += '<table class="table table-sm table-borderless mb-1"><tbody>';
									d.costs.forEach(function(c){
										var img = c.img ? '<img src="assets/resource_imgs/'+c.img+'" alt="" width="16" height="16"> ' : '';
										var cls = c.enough ? 'text-success' : 'text-danger';
										var icon = c.enough ? 'fa-check' : 'fa-times';
										html += '<tr><td class="py-0 small">'+img+c.name+'</td><td class="py-0 text-end fw-bold small">'+c.amount+'</td><td class="py-0 text-end small '+cls+'">('+c.stock+') <i class="fa '+icon+' fa-xs"></i></td></tr>';
									});
									html += '</tbody></table>';
								}
								info.innerHTML = html;
								btn.disabled = !d.can_afford;
								btn.classList.toggle('disabled', !d.can_afford);
								detail.style.display = 'block';
							}
							</script>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endif; /* end mine for non-customs */ ?>
			</div>

			<!-- ============================================================ -->
			<!-- MILITARY BUILDINGS -->
			<!-- ============================================================ -->
			<?php if (!$isCustomsHouse): ?>
			<div class="row">
				<!-- Barracks -->
				<div class="col-lg-6">
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-shield-halved me-1"></i>Barracks <?= $barracksData ? '(Lv.' . $barracksData['level'] . ')' : '' ?></span>
							<?php if ($barracksData): ?>
							<span class="badge bg-warning text-dark">🪖 <?= number_format($totalGarrison) ?> / <?= number_format((int)$barracksData['level'] * 100) ?> troops</span>
							<?php endif; ?>
						</div>
						<div class="card-body">
							<?php if (!$barracksData): ?>
								<p class="text-muted small mb-2">No barracks in this town. Build one to recruit soldiers.</p>
								<form method="post" action="military_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="build_barracks">
									<button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-plus me-1"></i>Build Barracks</button>
								</form>
							<?php else: ?>
								<?php
								$bLvl = (int)$barracksData['level'];
								$bWorkers = (int)$barracksData['workers_assigned'];
								$bMaxWorkers = $bLvl * 50;
								$bMaxGarrison = $bLvl * 100;
								?>
								<div class="row mb-2">
									<div class="col-6">
										<small class="text-muted d-block">Level</small>
										<span class="fw-bold"><?= $bLvl ?></span>
									</div>
									<div class="col-6">
										<small class="text-muted d-block">Garrison Capacity</small>
										<span class="fw-bold"><?= number_format($totalGarrison) ?> / <?= number_format($bMaxGarrison) ?></span>
									</div>
								</div>
								<div class="mb-2">
									<small class="text-muted d-block">⚙️ Auto-Recruit Rate</small>
									<span class="fw-bold text-warning"><?= floor($bWorkers / 10) ?> troops/tick</span>
									<small class="text-muted"> (<?= $bWorkers ?> workers ÷ 10)</small>
									<?php if ($bWorkers === 0): ?>
									<br><small class="text-danger">⚠️ Assign workers to start auto-recruitment</small>
									<?php endif; ?>
								</div>

								<!-- Garrison summary -->
								<?php if (!empty($garrisonTroops)): ?>
								<table class="table table-dark table-sm mb-2">
									<thead><tr><th>Type</th><th>Qty</th><th>⚔️</th><th>🛡️</th></tr></thead>
									<tbody>
									<?php foreach ($garrisonTroops as $gt): ?>
									<tr>
										<td class="small"><?= htmlspecialchars($gt['weapon_name']) ?></td>
										<td class="fw-bold"><?= number_format($gt['quantity']) ?></td>
										<td class="text-danger small"><?= $gt['attack_stat'] ?></td>
										<td class="text-info small"><?= $gt['defense_stat'] ?></td>
									</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
								<?php endif; ?>

								<!-- Workers -->
								<form method="post" action="military_action.php" class="d-flex gap-2 mb-2">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="set_barracks_workers">
									<div class="input-group input-group-sm" style="max-width:250px">
										<span class="input-group-text">Workers</span>
										<input type="number" name="workers" class="form-control" value="<?= $bWorkers ?>" min="0" max="<?= $bMaxWorkers ?>">
										<span class="input-group-text">/ <?= $bMaxWorkers ?></span>
										<button type="submit" class="btn btn-outline-theme">Set</button>
									</div>
								</form>

								<!-- Upgrade -->
								<?php if ($bLvl < 10): ?>
								<hr class="my-2">
								<small class="fw-bold d-block mb-1">Upgrade to Level <?= $bLvl + 1 ?></small>
								<div class="d-flex flex-wrap gap-1 mb-1">
									<?php foreach ($barracksUpgradeCosts as $buc): ?>
									<span class="badge <?= $buc['enough'] ? 'bg-success' : 'bg-danger' ?>">
										<?= number_format($buc['amount']) ?>t <?= $buc['resource_name'] ?>
										(<?= number_format($buc['in_stock'], 1) ?>t)
									</span>
									<?php endforeach; ?>
								</div>
								<form method="post" action="military_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="upgrade_barracks">
									<button type="submit" class="btn btn-sm btn-outline-warning" <?= $barracksCanUpgrade ? '' : 'disabled' ?>>
										<i class="fa fa-arrow-up me-1"></i>Upgrade
									</button>
								</form>
								<?php else: ?>
								<small class="text-success">✅ Max level reached</small>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Munitions Factory -->
				<div class="col-lg-6">
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-bomb me-1"></i>Munitions Factory <?= $munitionsData ? '(Lv.' . $munitionsData['level'] . ')' : '' ?></span>
						</div>
						<div class="card-body">
							<?php if (!$munitionsData): ?>
								<p class="text-muted small mb-2">No munitions factory in this town. Build one to produce weapons.</p>
								<form method="post" action="military_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="build_munitions">
									<button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-plus me-1"></i>Build Munitions Factory</button>
								</form>
							<?php else: ?>
								<?php
								$mLvl = (int)$munitionsData['level'];
								$mWorkers = (int)$munitionsData['workers_assigned'];
								$mMaxWorkers = $mLvl * 100;
								$mProdId = $munitionsData['producing_weapon_type_id'] ?? null;
								$mProdName = 'Idle';
								if ($mProdId) {
									$wpnRes = $conn->query("SELECT name FROM weapon_types WHERE id = " . (int)$mProdId);
									if ($wpnRes && $wpnRow = $wpnRes->fetch_assoc()) $mProdName = $wpnRow['name'];
								}
								?>
								<div class="row mb-2">
									<div class="col-4">
										<small class="text-muted d-block">Level</small>
										<span class="fw-bold"><?= $mLvl ?></span>
									</div>
									<div class="col-4">
										<small class="text-muted d-block">Workers</small>
										<span class="fw-bold"><?= number_format($mWorkers) ?> / <?= number_format($mMaxWorkers) ?></span>
									</div>
									<div class="col-4">
										<small class="text-muted d-block">Producing</small>
										<span class="fw-bold <?= $mProdId ? 'text-success' : 'text-warning' ?>"><?= htmlspecialchars($mProdName) ?></span>
									</div>
								</div>
								<div class="mb-2">
									<small class="text-muted d-block">⚙️ Auto-Production Rate</small>
									<span class="fw-bold text-info"><?= floor($mWorkers / 20) ?> weapons/tick</span>
									<small class="text-muted"> (<?= $mWorkers ?> workers ÷ 20)</small>
									<?php if ($mWorkers === 0): ?>
									<br><small class="text-danger">⚠️ Assign workers to start production</small>
									<?php endif; ?>
								</div>

								<!-- Workers -->
								<form method="post" action="military_action.php" class="d-flex gap-2 mb-2">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="set_munitions_workers">
									<div class="input-group input-group-sm" style="max-width:250px">
										<span class="input-group-text">Workers</span>
										<input type="number" name="workers" class="form-control" value="<?= $mWorkers ?>" min="0" max="<?= $mMaxWorkers ?>">
										<span class="input-group-text">/ <?= $mMaxWorkers ?></span>
										<button type="submit" class="btn btn-outline-theme">Set</button>
									</div>
								</form>

								<!-- Upgrade -->
								<?php if ($mLvl < 10): ?>
								<hr class="my-2">
								<small class="fw-bold d-block mb-1">Upgrade to Level <?= $mLvl + 1 ?></small>
								<div class="d-flex flex-wrap gap-1 mb-1">
									<?php foreach ($munitionsUpgradeCosts as $muc): ?>
									<span class="badge <?= $muc['enough'] ? 'bg-success' : 'bg-danger' ?>">
										<?= number_format($muc['amount']) ?>t <?= $muc['resource_name'] ?>
										(<?= number_format($muc['in_stock'], 1) ?>t)
									</span>
									<?php endforeach; ?>
								</div>
								<form method="post" action="military_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="upgrade_munitions">
									<button type="submit" class="btn btn-sm btn-outline-warning" <?= $munitionsCanUpgrade ? '' : 'disabled' ?>>
										<i class="fa fa-arrow-up me-1"></i>Upgrade
									</button>
								</form>
								<?php else: ?>
								<small class="text-success">✅ Max level reached</small>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<div class="mb-2">
				<a href="military.php?town=<?= $townId ?>" class="btn btn-sm btn-outline-danger">
					<i class="fa fa-crosshairs me-1"></i>Full Military Command for <?= htmlspecialchars($town['name']) ?>
				</a>
			</div>
			<?php endif; ?>

			<!-- ============================================================ -->
			<!-- VEHICLES -->
			<!-- ============================================================ -->
			<div class="row">
				<!-- Vehicles in Town -->
				<div class="col-lg-6">
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-truck me-1"></i>Vehicles in Town (<?= count($townVehicles) ?>)</span>
						</div>
						<div class="card-body">
							<?php if (!empty($townVehicles)): ?>
								<?php foreach ($townVehicles as $veh): ?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<span class="fw-bold"><?= htmlspecialchars($veh['type_name']) ?> <small class="text-muted">#<?= $veh['id'] ?></small></span>
											<span>
												<?php if (($veh['status'] ?? '') === 'towing'): ?>
													<span class="badge bg-warning text-dark">🚛 Tow Pending</span>
												<?php else: ?>
													<?= $veh['category'] === 'military' ? '<span class="badge bg-danger">MIL</span>' : '<span class="badge bg-info">CIV</span>' ?>
													<?= $veh['vehicle_class'] === 'cargo' ? '<span class="badge bg-success">Cargo</span>' : ($veh['vehicle_class'] === 'tow' ? '<span class="badge bg-warning text-dark">Tow</span>' : '<span class="badge bg-primary">Pax</span>') ?>
												<?php endif; ?>
											</span>
										</div>
										<?php
											$vHealth = (float)($veh['health'] ?? 100);
											$hColor = $vHealth >= 75 ? 'bg-success' : ($vHealth >= 40 ? 'bg-warning' : ($vHealth >= 10 ? 'bg-danger' : 'bg-dark'));
										?>
										<div class="d-flex align-items-center mb-1" style="gap:6px;">
											<small class="text-muted" style="min-width:50px;">HP <?= number_format($vHealth, 1) ?>%</small>
											<div class="progress flex-grow-1" style="height:5px;">
												<div class="progress-bar <?= $hColor ?>" style="width:<?= $vHealth ?>%"></div>
											</div>
											<?php if ($vHealth < 100 && $vHealth >= 10 && $workshop): ?>
											<form method="post" action="mine_action.php" class="d-inline" style="margin:0;">
												<input type="hidden" name="town_id" value="<?= $townId ?>">
												<input type="hidden" name="action" value="start_repair">
												<input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>">
												<button type="submit" class="btn btn-outline-warning py-0 px-1" style="font-size:0.7em;" title="Repair — Cost: <?= number_format((100 - $vHealth) * 0.85 * (float)($veh['points_price'] ?? 0) / 100, 0) ?> pts">
													<i class="fa fa-wrench"></i>
												</button>
											</form>
											<?php endif; ?>
										</div>
										<small class="text-muted">
											Speed: <?= $veh['max_speed_kmh'] ?> km/h |
											Fuel: <?= $veh['fuel_capacity'] ?> (<?= $veh['fuel_per_km'] ?>/km) |
											Cap: <?= $veh['max_capacity'] ?>t |
											Cargo: <?= $veh['cargo_class'] ?? 'Passenger' ?>
										</small>
										<?php if (($veh['status'] ?? '') === 'towing'): ?>
										<!-- Vehicle awaiting tow pickup -->
										<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
											<i class="fa fa-truck-pickup me-1"></i><strong>Awaiting Tow</strong> — A tow truck is en route to pick up this vehicle.
										</div>
										<?php elseif ($vHealth < 50): ?>
										<!-- Vehicle too damaged to drive -->
										<div class="alert alert-danger py-1 px-2 mt-2 mb-1 small">
											<i class="fa fa-exclamation-triangle me-1"></i><strong>Immobilised</strong> — Health below 50%.
											<?php if ($workshop): ?>
												Send to workshop for repair.
											<?php elseif (!empty($availableTowTrucks)): ?>
												Request a tow to a workshop.
											<?php else: ?>
												No tow trucks available nearby.
											<?php endif; ?>
										</div>
										<?php if (!empty($availableTowTrucks) && !empty($workshopTowns)): ?>
										<form method="post" action="mine_action.php" class="mt-1">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="request_tow">
											<input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>">
											<div class="row g-1">
												<div class="col-md-5">
													<select name="tow_truck_id" class="form-select form-select-sm" required>
														<option value="">🚛 Select Tow Truck</option>
														<?php foreach ($availableTowTrucks as $tt): ?>
														<option value="<?= $tt['id'] ?>"><?= htmlspecialchars($tt['type_name']) ?> #<?= $tt['id'] ?> @ <?= htmlspecialchars($tt['town_name']) ?> (<?= number_format($tt['distance_km'],1) ?>km)</option>
														<?php endforeach; ?>
													</select>
												</div>
												<div class="col-md-5">
													<select name="destination_id" class="form-select form-select-sm" required>
														<option value="">🔧 Tow to Workshop</option>
														<?php foreach ($workshopTowns as $wt): ?>
														<option value="<?= $wt['town_id'] ?>"><?= htmlspecialchars($wt['name']) ?> (Lv<?= $wt['level'] ?>, <?= number_format($wt['distance_km'],1) ?>km)</option>
														<?php endforeach; ?>
													</select>
												</div>
												<div class="col-md-2">
													<button type="submit" class="btn btn-sm btn-outline-warning w-100"><i class="fa fa-truck-pickup me-1"></i>Tow</button>
												</div>
											</div>
										</form>
										<?php endif; ?>
										<?php else: ?>
										<!-- Dispatch Form -->
										<form method="post" action="mine_action.php" class="mt-2">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="dispatch_vehicle">
											<input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>">
											<div class="row g-1">
												<div class="col-md-3">
													<select name="destination_id" class="form-select form-select-sm" required>
														<option value="">Destination</option>
														<?php foreach ($dispatchTargets as $dt): ?>
														<option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?> (<?= number_format($dt['distance_km'],1) ?>km, <?= ucfirst($dt['road_type']) ?>)</option>
														<?php endforeach; ?>
													</select>
												</div>
												<?php if ($veh['vehicle_class'] === 'cargo' && $veh['cargo_class']): ?>
													<?php
														// Filter resources by this vehicle's cargo class
														$classResources = array_filter($cargoResources, fn($cr) => $cr['resource_type'] === $veh['cargo_class']);
														$isWarehouseClass = stripos($veh['cargo_class'], 'warehouse') !== false;
													?>
													<?php if ($isWarehouseClass): ?>
														<!-- Warehouse class: multiple resource types allowed per load -->
														<div class="col-md-7">
															<div class="small text-info mb-1"><i class="fa fa-boxes-stacked me-1"></i>Warehouse Load (max <?= $veh['max_capacity'] ?>t total, can mix types)</div>
															<?php foreach ($classResources as $cr): ?>
															<div class="row g-1 mb-1">
																<div class="col-6">
																	<div class="input-group input-group-sm">
																		<span class="input-group-text small" style="min-width:130px"><?= htmlspecialchars($cr['resource_name']) ?></span>
																		<input type="number" name="wh_cargo[<?= $cr['id'] ?>]" class="form-control form-control-sm wh-cargo-input" placeholder="0" step="0.1" min="0" max="<?= min($cr['stock'], $veh['max_capacity']) ?>" value="0" data-stock="<?= $cr['stock'] ?>">
																		<span class="input-group-text small text-muted">(<?= number_format($cr['stock'],1) ?>t)</span>
																	</div>
																</div>
															</div>
															<?php endforeach; ?>
														</div>
													<?php else: ?>
														<!-- Single resource class: pick one resource from the class -->
														<div class="col-md-3">
															<select name="cargo_resource_id" class="form-select form-select-sm" required>
																<option value=""><?= htmlspecialchars($veh['cargo_class']) ?></option>
																<?php foreach ($classResources as $cr): ?>
																<option value="<?= $cr['id'] ?>"><?= htmlspecialchars($cr['resource_name']) ?> (<?= number_format($cr['stock'],1) ?>t)</option>
																<?php endforeach; ?>
															</select>
														</div>
														<div class="col-md-2">
															<input type="number" name="cargo_amount" class="form-control form-control-sm" placeholder="Tonnes" step="0.1" min="0.1" max="<?= $veh['max_capacity'] ?>" required>
														</div>
													<?php endif; ?>
												<?php endif; ?>
												<div class="col-md-2">
													<div class="form-check form-check-sm mt-1">
														<input type="checkbox" name="return_empty" value="1" class="form-check-input" id="ret_<?= $veh['id'] ?>">
														<label class="form-check-label small" for="ret_<?= $veh['id'] ?>">Return</label>
													</div>
												</div>
												<div class="col-md-2">
													<button type="submit" class="btn btn-sm btn-outline-success w-100"><i class="fa fa-paper-plane me-1"></i>Go</button>
												</div>
											</div>
										</form>
										<?php endif; /* end health >= 50 dispatch check */ ?>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-muted small mb-0">No vehicles stationed here. Purchase at Customs or build at a Vehicle Factory.</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- In Transit -->
				<div class="col-lg-6">
					<div class="card mb-3">
						<div class="card-header fw-bold small"><i class="fa fa-route me-1"></i>Vehicles In Transit (<?= count($inTransit) ?>)</div>
						<div class="card-body">
							<?php if (!empty($inTransit)): ?>
								<?php foreach ($inTransit as $trip):
									$now = time();
									$eta = strtotime($trip['eta_at']);
									$dep = strtotime($trip['departed_at']);
									$total = max($eta - $dep, 1);
									$elapsed = $now - $dep;
									$pct = min(100, max(0, round(($elapsed / $total) * 100)));
									$remaining = max(0, $eta - $now);
									$mins = ceil($remaining / 60);
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between small mb-1">
											<span class="fw-bold">
												<?php if (($trip['vehicle_class'] ?? '') === 'tow'): ?>🚛 <?php endif; ?>
												<?= htmlspecialchars($trip['vehicle_name']) ?> #<?= $trip['vehicle_id'] ?>
												<?php if (($trip['vehicle_class'] ?? '') === 'tow'): ?><span class="badge bg-warning text-dark">Towing</span><?php endif; ?>
											</span>
											<span class="text-muted">ETA: <?= $mins > 0 ? $mins . ' min' : 'Arriving...' ?></span>
										</div>
										<div class="small mb-1">
											<?= htmlspecialchars($trip['from_name']) ?> → <?= htmlspecialchars($trip['to_name']) ?>
											<?php
												$tripCargoItems = $transitCargo[(int)$trip['trip_id']] ?? [];
												if (!empty($tripCargoItems)):
													$cargoStr = implode(', ', array_map(fn($c) => number_format($c['amount'],1) . 't ' . $c['resource_name'], $tripCargoItems));
											?>
												| <?= htmlspecialchars($cargoStr) ?>
											<?php endif; ?>
											<?php if ($trip['return_empty']): ?>
												<span class="badge bg-secondary">+ Return</span>
											<?php endif; ?>
										</div>
										<div class="progress" style="height: 6px;">
											<div class="progress-bar bg-theme" style="width: <?= $pct ?>%"></div>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-muted small mb-0">No vehicles in transit to/from this town.</p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<?php if (!$isCustomsHouse): ?>
			<!-- ============================================================ -->
			<!-- WORKSHOP & REPAIRS -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-wrench me-1"></i>Workshop & Vehicle Repairs</span>
					<?php if ($workshop): ?>
						<span class="badge bg-success">Level <?= $workshopLevel ?> — <?= $workshopSlots - $workshopActiveRepairs ?>/<?= $workshopSlots ?> slots free</span>
					<?php else: ?>
						<span class="badge bg-secondary">Not Built</span>
					<?php endif; ?>
				</div>
				<div class="card-body">
					<?php if (!$workshop): ?>
						<!-- Build Workshop -->
						<?php
							$wsBuildCosts = getUpgradeCosts($conn, 'workshop', 1, $townId);
						?>
						<p class="text-muted small">Build a workshop to repair damaged vehicles. Each level provides 1 simultaneous repair slot.</p>
						<?php if (!empty($wsBuildCosts['costs'])): ?>
						<div class="row align-items-end">
							<div class="col-md-8">
								<table class="table table-sm table-borderless mb-1" style="font-size:0.8em;">
									<tbody>
									<?php foreach ($wsBuildCosts['costs'] as $wc): ?>
										<tr>
											<td class="py-0"><?= htmlspecialchars($wc['resource_name']) ?></td>
											<td class="py-0 text-end fw-bold"><?= number_format($wc['amount']) ?>t</td>
											<td class="py-0 text-end <?= $wc['enough'] ? 'text-success' : 'text-danger' ?>">
												(<?= number_format($wc['in_stock']) ?>t)
												<i class="fa <?= $wc['enough'] ? 'fa-check' : 'fa-times' ?>" style="font-size:0.8em"></i>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<div class="col-md-4">
								<form method="post" action="mine_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="build_workshop">
									<button type="submit" class="btn btn-sm btn-outline-theme w-100<?= !$wsBuildCosts['can_afford'] ? ' disabled' : '' ?>"<?= !$wsBuildCosts['can_afford'] ? ' disabled' : '' ?>>
										<i class="fa fa-plus me-1"></i>Build Workshop
									</button>
								</form>
							</div>
						</div>
						<?php endif; ?>
					<?php else: ?>
						<div class="row">
							<!-- Active Repairs -->
							<div class="col-lg-7">
								<?php if (!empty($repairingVehicles)): ?>
									<h6 class="small fw-bold mb-2"><i class="fa fa-gear fa-spin me-1"></i>Active Repairs (<?= count($repairingVehicles) ?>/<?= $workshopSlots ?>)</h6>
									<?php foreach ($repairingVehicles as $rv):
										$rvHealth = (float)$rv['health'];
										$rvStart = (float)$rv['start_health'];
										$rvTarget = (float)$rv['target_health'];
										$totalRepairPct = $rvTarget - $rvStart;
										$donePct = $totalRepairPct > 0 ? min(100, round(($rvHealth - $rvStart) / $totalRepairPct * 100)) : 100;
										$ticksLeft = max(0, ceil($rvTarget - $rvHealth));
										$minsLeft = $ticksLeft * 5;
									?>
									<div class="card bg-dark bg-opacity-50 mb-2">
										<div class="card-body py-2 px-3">
											<div class="d-flex justify-content-between align-items-center small">
												<span class="fw-bold"><?= htmlspecialchars($rv['type_name']) ?> #<?= $rv['id'] ?></span>
												<span class="text-muted"><?= number_format($rvHealth, 1) ?>% → <?= number_format($rvTarget, 0) ?>% | ~<?= $minsLeft ?> min left</span>
											</div>
											<div class="progress mt-1" style="height:6px;">
												<div class="progress-bar bg-warning" style="width:<?= $donePct ?>%"></div>
											</div>
											<small class="text-muted">Cost: <?= number_format($rv['cost_total'], 0) ?> pts | <?= $ticksLeft ?> ticks remaining</small>
										</div>
									</div>
									<?php endforeach; ?>
								<?php else: ?>
									<p class="text-muted small mb-2"><i class="fa fa-check-circle me-1"></i>No active repairs. All repair slots available.</p>
								<?php endif; ?>

								<?php if (!empty($scrappedVehicles)): ?>
									<h6 class="small fw-bold mb-2 mt-3 text-danger"><i class="fa fa-skull-crossbones me-1"></i>Scrapped Vehicles (<?= count($scrappedVehicles) ?>)</h6>
									<?php foreach ($scrappedVehicles as $sv): ?>
									<div class="d-flex justify-content-between align-items-center small py-1 border-bottom border-dark">
										<span class="text-muted text-decoration-line-through"><?= htmlspecialchars($sv['type_name']) ?> #<?= $sv['id'] ?> (<?= number_format((float)$sv['health'], 1) ?>%)</span>
										<form method="post" action="mine_action.php" class="d-inline">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="scrap_vehicle">
											<input type="hidden" name="vehicle_id" value="<?= $sv['id'] ?>">
											<button type="submit" class="btn btn-outline-danger py-0 px-1" style="font-size:0.65em;" onclick="return confirm('Remove this scrapped vehicle?');">
												<i class="fa fa-trash"></i> Remove
											</button>
										</form>
									</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<!-- Workshop Info & Upgrade -->
							<div class="col-lg-5">
								<div class="card bg-dark bg-opacity-25">
									<div class="card-body py-2 px-3">
										<h6 class="small fw-bold mb-2">Workshop Level <?= $workshopLevel ?></h6>
										<ul class="small mb-2" style="padding-left:1.2rem;">
											<li>Repair slots: <strong><?= $workshopSlots ?></strong></li>
											<li>Repair speed: <strong>+1% per tick</strong> (5 min/pp)</li>
											<li>Cost: <strong>85%</strong> of vehicle price for full repair</li>
										</ul>
										<?php
											$wsNextLevel = $workshopLevel + 1;
											$wsUpgradeCosts = getUpgradeCosts($conn, 'workshop', $wsNextLevel, $townId);
										?>
										<?php if (!empty($wsUpgradeCosts['costs'])): ?>
										<h6 class="small fw-bold mb-1">Upgrade to Level <?= $wsNextLevel ?></h6>
										<table class="table table-sm table-borderless mb-1" style="font-size:0.75em;">
											<tbody>
											<?php foreach ($wsUpgradeCosts['costs'] as $wuc): ?>
												<tr>
													<td class="py-0"><?= htmlspecialchars($wuc['resource_name']) ?></td>
													<td class="py-0 text-end fw-bold"><?= number_format($wuc['amount']) ?>t</td>
													<td class="py-0 text-end <?= $wuc['enough'] ? 'text-success' : 'text-danger' ?>">
														(<?= number_format($wuc['in_stock']) ?>t)
													</td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
										<form method="post" action="mine_action.php">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="upgrade_workshop">
											<button type="submit" class="btn btn-sm btn-outline-success w-100<?= !$wsUpgradeCosts['can_afford'] ? ' disabled' : '' ?>"<?= !$wsUpgradeCosts['can_afford'] ? ' disabled' : '' ?>>
												<i class="fa fa-arrow-up me-1"></i>Upgrade Workshop
											</button>
										</form>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; /* end workshop for non-customs */ ?>

			<!-- Distances & Roads -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-road me-1"></i>Roads & Distances</div>
				<div class="card-body">
					<table class="table table-hover table-sm">
						<thead>
							<tr>
								<th>Town</th>
								<th>Distance</th>
								<th>Road Type</th>
								<th>Speed Limit</th>
								<th>Upgrade</th>
							</tr>
						</thead>
						<tbody>
						<?php
						// Pre-load all road upgrade costs
						$roadUpgradeCosts = [];
						$rucQuery = $conn->query("SELECT ruc.road_type_from, ruc.road_type_to, ruc.resource_id, ruc.amount_per_km, wp.resource_name
							FROM road_upgrade_costs ruc
							JOIN world_prices wp ON wp.id = ruc.resource_id
							ORDER BY ruc.road_type_from, wp.resource_name");
						if ($rucQuery) {
							while ($ruc = $rucQuery->fetch_assoc()) {
								$key = $ruc['road_type_from'] . '_' . $ruc['road_type_to'];
								$roadUpgradeCosts[$key][] = $ruc;
							}
						}

						$roadOrder = ['mud' => 'gravel', 'gravel' => 'asphalt', 'asphalt' => 'dual'];
						while ($d = $distResult->fetch_assoc()):
							$roadType = $d['road_type'] ?? 'mud';
							$speedLimit = $d['speed_limit'] ?? 30;
							$roadBadge = match($roadType) {
								'mud' => '<span class="badge bg-secondary">Mud</span>',
								'gravel' => '<span class="badge bg-warning text-dark">Gravel</span>',
								'asphalt' => '<span class="badge bg-info">Asphalt</span>',
								'dual' => '<span class="badge bg-success">Dual Lane</span>',
								default => '<span class="badge bg-secondary">'.ucfirst($roadType).'</span>'
							};
							$nextRoad = $roadOrder[$roadType] ?? null;
							$distKm = (float)$d['distance_km'];

							// Build cost summary for this road upgrade
							$costSummary = '';
							if ($nextRoad) {
								$key = $roadType . '_' . $nextRoad;
								if (!empty($roadUpgradeCosts[$key])) {
									$parts = [];
									foreach ($roadUpgradeCosts[$key] as $c) {
										$total = ceil($c['amount_per_km'] * $distKm);
										$parts[] = number_format($total) . ' ' . $c['resource_name'];
									}
									$costSummary = implode(', ', $parts);
								}
							}
						?>
							<tr>
								<td><a href="town_view.php?id=<?= $d['id'] ?>" class="text-theme"><?= htmlspecialchars($d['name']) ?></a></td>
								<td><?= number_format($d['distance_km'], 1) ?> km</td>
								<td><?= $roadBadge ?></td>
								<td><?= $speedLimit ?> km/h</td>
								<td>
									<?php if ($nextRoad): ?>
									<form method="post" action="mine_action.php" class="d-inline">
										<input type="hidden" name="town_id" value="<?= $townId ?>">
										<input type="hidden" name="action" value="upgrade_road">
										<input type="hidden" name="target_town_id" value="<?= $d['id'] ?>">
										<button type="submit" class="btn btn-sm btn-outline-success py-0 px-1" title="Upgrade to <?= ucfirst($nextRoad) ?>">
											<i class="fa fa-arrow-up me-1"></i><?= ucfirst($nextRoad) ?>
										</button>
									</form>
									<?php if ($costSummary): ?>
									<div class="text-muted small mt-1" style="font-size:.75rem"><?= $costSummary ?></div>
									<?php endif; ?>
									<?php else: ?>
									<span class="text-muted small">Max</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endwhile; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- TRAINS -->
			<!-- ============================================================ -->
			<div class="row">
				<!-- Composed Trains -->
				<div class="col-lg-6">
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-train me-1"></i>Trains (<?= count($townTrains) ?>)</span>
						</div>
						<div class="card-body">
							<?php if (!empty($townTrains)): ?>
								<?php foreach ($townTrains as $train):
									$wagonCount = count($train['wagons']);
									$maxWagons = (int)$train['max_wagons'];
									$propIcon = match($train['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
									// Calculate total cargo capacity by class
									$trainCapacity = [];
									foreach ($train['wagons'] as $tw) {
										$cls = $tw['cargo_class'] ?? 'passenger';
										$trainCapacity[$cls] = ($trainCapacity[$cls] ?? 0) + (float)$tw['max_capacity'];
									}
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<span class="fw-bold"><?= $propIcon ?> <?= htmlspecialchars($train['name'] ?? 'Train #'.$train['id']) ?> <small class="text-muted">#<?= $train['id'] ?></small></span>
											<span class="badge bg-secondary"><?= $wagonCount ?>/<?= $maxWagons ?> wagons</span>
										</div>
										<small class="text-muted d-block mb-1">
											Loco: <?= htmlspecialchars($train['loco_name']) ?> |
											Speed: <?= $train['max_speed_kmh'] ?> km/h |
											<?= $train['fuel_name'] ? $train['fuel_per_km'].'t '.$train['fuel_name'].'/km' : 'Electric (free fuel)' ?>
										</small>
										<?php if (!empty($train['wagons'])): ?>
										<div class="small text-info mb-1">
											<?php foreach ($train['wagons'] as $tw): ?>
											<span class="badge bg-dark border border-secondary me-1" title="Position <?= $tw['position'] ?>">
												<?= $tw['wagon_class'] === 'passenger' ? '👥' : '📦' ?> <?= htmlspecialchars($tw['wagon_name']) ?> (<?= $tw['max_capacity'] ?><?= $tw['wagon_class'] === 'passenger' ? 'pax' : 't' ?>)
											</span>
											<?php endforeach; ?>
										</div>
										<?php endif; ?>

										<!-- Dispatch Train Form -->
										<?php if ($wagonCount > 0): ?>
										<form method="post" action="mine_action.php" class="mt-2">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="dispatch_train">
											<input type="hidden" name="train_id" value="<?= $train['id'] ?>">
											<div class="row g-1 align-items-end">
												<div class="col-md-4">
													<select name="destination_id" class="form-select form-select-sm" required>
														<option value="">Destination</option>
														<?php foreach ($trainTargets as $tt):
															$railBadge = $tt['rail_type'] === 'electrified' ? '⚡' : '🛤️';
															// Electric trains need electrified rail
															if ($train['propulsion'] === 'electric' && $tt['rail_type'] !== 'electrified') continue;
														?>
														<option value="<?= $tt['id'] ?>"><?= $railBadge ?> <?= htmlspecialchars($tt['name']) ?> (<?= number_format($tt['distance_km'],1) ?>km, <?= $tt['speed_limit'] ?>km/h)</option>
														<?php endforeach; ?>
													</select>
												</div>
												<?php
													// Cargo loading per wagon class
													$cargoWagons = array_filter($train['wagons'], fn($w) => $w['wagon_class'] === 'cargo');
													$wagonsByClass = [];
													foreach ($cargoWagons as $cw) {
														$cls = $cw['cargo_class'] ?? '';
														if (!isset($wagonsByClass[$cls])) $wagonsByClass[$cls] = ['wagons' => [], 'total_cap' => 0];
														$wagonsByClass[$cls]['wagons'][] = $cw;
														$wagonsByClass[$cls]['total_cap'] += (float)$cw['max_capacity'];
													}
												?>
												<div class="col-md-6">
													<?php foreach ($wagonsByClass as $cls => $clsInfo):
														$isWarehouse = stripos($cls, 'warehouse') !== false;
														$classRes = array_filter($cargoResources, fn($cr) => $cr['resource_type'] === $cls);
													?>
													<div class="mb-1">
														<small class="text-warning fw-bold"><?= htmlspecialchars($cls) ?> (<?= number_format($clsInfo['total_cap'],0) ?>t capacity)</small>
														<?php if ($isWarehouse): ?>
															<?php foreach ($classRes as $cr): ?>
															<div class="input-group input-group-sm mb-1">
																<span class="input-group-text small" style="min-width:120px"><?= htmlspecialchars($cr['resource_name']) ?></span>
																<input type="number" name="train_cargo[<?= $cls ?>][<?= $cr['id'] ?>]" class="form-control form-control-sm" placeholder="0" step="0.1" min="0" max="<?= min($cr['stock'], $clsInfo['total_cap']) ?>" value="0">
																<span class="input-group-text small text-muted">(<?= number_format($cr['stock'],1) ?>t)</span>
															</div>
															<?php endforeach; ?>
														<?php else: ?>
															<div class="input-group input-group-sm">
																<select name="train_cargo_res[<?= $cls ?>]" class="form-select form-select-sm">
																	<option value="">Select...</option>
																	<?php foreach ($classRes as $cr): ?>
																	<option value="<?= $cr['id'] ?>"><?= htmlspecialchars($cr['resource_name']) ?> (<?= number_format($cr['stock'],1) ?>t)</option>
																	<?php endforeach; ?>
																</select>
																<input type="number" name="train_cargo_amt[<?= $cls ?>]" class="form-control form-control-sm" placeholder="Tonnes" step="0.1" min="0" max="<?= $clsInfo['total_cap'] ?>">
															</div>
														<?php endif; ?>
													</div>
													<?php endforeach; ?>
												</div>
												<div class="col-md-1">
													<div class="form-check form-check-sm">
														<input type="checkbox" name="return_empty" value="1" class="form-check-input" id="tret_<?= $train['id'] ?>">
														<label class="form-check-label small" for="tret_<?= $train['id'] ?>">Ret</label>
													</div>
												</div>
												<div class="col-md-1">
													<button type="submit" class="btn btn-sm btn-outline-success w-100"><i class="fa fa-paper-plane"></i></button>
												</div>
											</div>
										</form>
										<?php else: ?>
										<small class="text-muted">Attach wagons before dispatching.</small>
										<?php endif; ?>

										<!-- Decompose button -->
										<form method="post" action="mine_action.php" class="mt-1 d-inline">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="decompose_train">
											<input type="hidden" name="train_id" value="<?= $train['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="return confirm('Decompose this train?')" title="Decompose">
												<i class="fa fa-unlink me-1"></i>Decompose
											</button>
										</form>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-muted small mb-0">No trains assembled. Compose a train from idle locomotives and wagons below.</p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Trains In Transit -->
					<div class="card mb-3">
						<div class="card-header fw-bold small"><i class="fa fa-route me-1"></i>Trains In Transit (<?= count($trainsInTransit) ?>)</div>
						<div class="card-body">
							<?php if (!empty($trainsInTransit)): ?>
								<?php foreach ($trainsInTransit as $ttrip):
									$now = time(); $eta = strtotime($ttrip['eta_at']); $dep = strtotime($ttrip['departed_at']);
									$total = max($eta - $dep, 1); $elapsed = $now - $dep;
									$pct = min(100, max(0, round(($elapsed / $total) * 100)));
									$remaining = max(0, $eta - $now); $mins = ceil($remaining / 60);
									$propIcon = match($ttrip['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between small mb-1">
											<span class="fw-bold"><?= $propIcon ?> <?= htmlspecialchars($ttrip['train_name'] ?? 'Train #'.$ttrip['train_id']) ?></span>
											<span class="text-muted">ETA: <?= $mins > 0 ? $mins . ' min' : 'Arriving...' ?></span>
										</div>
										<div class="small mb-1">
											<?= htmlspecialchars($ttrip['from_name']) ?> → <?= htmlspecialchars($ttrip['to_name']) ?>
											<?php
												$ttcItems = $trainTransitCargo[(int)$ttrip['trip_id']] ?? [];
												if (!empty($ttcItems)):
													$cargoBySummary = [];
													foreach ($ttcItems as $c) $cargoBySummary[$c['resource_name']] = ($cargoBySummary[$c['resource_name']] ?? 0) + (float)$c['amount'];
													$cargoStr = implode(', ', array_map(fn($n, $a) => number_format($a,1).'t '.$n, array_keys($cargoBySummary), $cargoBySummary));
											?>
												| <?= htmlspecialchars($cargoStr) ?>
											<?php endif; ?>
											<?php if ($ttrip['return_empty']): ?><span class="badge bg-secondary">+ Return</span><?php endif; ?>
										</div>
										<div class="progress" style="height: 6px;">
											<div class="progress-bar bg-warning" style="width: <?= $pct ?>%"></div>
										</div>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-muted small mb-0">No trains in transit to/from this town.</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Compose Train + Idle Rolling Stock -->
				<div class="col-lg-6">
					<!-- Compose a Train -->
					<div class="card mb-3">
						<div class="card-header fw-bold small"><i class="fa fa-link me-1"></i>Compose a Train</div>
						<div class="card-body">
							<?php if (!empty($townLocos)): ?>
							<form method="post" action="mine_action.php">
								<input type="hidden" name="town_id" value="<?= $townId ?>">
								<input type="hidden" name="action" value="compose_train">
								<div class="row g-2 mb-2">
									<div class="col-md-5">
										<label class="form-label small">Locomotive</label>
										<select name="locomotive_id" class="form-select form-select-sm" required>
											<option value="">Select loco...</option>
											<?php foreach ($townLocos as $lo):
												$pIcon = match($lo['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
											?>
											<option value="<?= $lo['id'] ?>"><?= $pIcon ?> <?= htmlspecialchars($lo['type_name']) ?> #<?= $lo['id'] ?> (<?= $lo['max_wagons'] ?> wagons)</option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-md-4">
										<label class="form-label small">Train Name (optional)</label>
										<input type="text" name="train_name" class="form-control form-control-sm" placeholder="e.g. Coal Express">
									</div>
									<div class="col-md-3 d-flex align-items-end">
										<button type="submit" class="btn btn-sm btn-success w-100"><i class="fa fa-link me-1"></i>Compose</button>
									</div>
								</div>
								<?php if (!empty($townWagons)): ?>
								<div class="mb-2">
									<label class="form-label small">Attach Wagons (select multiple)</label>
									<select name="wagon_ids[]" class="form-select form-select-sm" multiple size="<?= min(8, count($townWagons)) ?>">
										<?php foreach ($townWagons as $wa): ?>
										<option value="<?= $wa['id'] ?>"><?= $wa['wagon_class'] === 'passenger' ? '👥' : '📦' ?> <?= htmlspecialchars($wa['type_name']) ?> #<?= $wa['id'] ?> (<?= $wa['max_capacity'] ?><?= $wa['wagon_class'] === 'passenger' ? 'pax' : 't' ?><?= $wa['cargo_class'] ? ' - '.htmlspecialchars($wa['cargo_class']) : '' ?>)</option>
										<?php endforeach; ?>
									</select>
									<small class="text-muted">Hold Ctrl/Cmd to select multiple. Max depends on locomotive.</small>
								</div>
								<?php endif; ?>
							</form>
							<?php else: ?>
								<p class="text-muted small mb-0">No idle locomotives in this town. Build or purchase one first.</p>
							<?php endif; ?>
						</div>
					</div>

					<!-- Idle Rolling Stock -->
					<div class="card mb-3">
						<div class="card-header fw-bold small">
							<i class="fa fa-warehouse me-1"></i>Idle Rolling Stock
							<span class="badge bg-secondary ms-2"><?= count($townLocos) ?> locos, <?= count($townWagons) ?> wagons</span>
						</div>
						<div class="card-body">
							<?php if (!empty($townLocos) || !empty($townWagons)): ?>
								<?php if (!empty($townLocos)): ?>
								<h6 class="small text-info">Locomotives</h6>
								<?php foreach ($townLocos as $lo):
									$pIcon = match($lo['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
								?>
								<div class="d-flex justify-content-between align-items-center small border-bottom border-dark py-1">
									<span><?= $pIcon ?> <?= htmlspecialchars($lo['type_name']) ?> <span class="text-muted">#<?= $lo['id'] ?></span></span>
									<span class="text-muted"><?= $lo['max_speed_kmh'] ?> km/h | <?= $lo['max_wagons'] ?> wagons | <?= $lo['fuel_name'] ?? 'Electric' ?></span>
								</div>
								<?php endforeach; ?>
								<?php endif; ?>
								<?php if (!empty($townWagons)): ?>
								<h6 class="small text-info mt-2">Wagons</h6>
								<?php foreach ($townWagons as $wa): ?>
								<div class="d-flex justify-content-between align-items-center small border-bottom border-dark py-1">
									<span><?= $wa['wagon_class'] === 'passenger' ? '👥' : '📦' ?> <?= htmlspecialchars($wa['type_name']) ?> <span class="text-muted">#<?= $wa['id'] ?></span></span>
									<span class="text-muted"><?= $wa['max_capacity'] ?><?= $wa['wagon_class'] === 'passenger' ? ' pax' : 't' ?><?= $wa['cargo_class'] ? ' | '.htmlspecialchars($wa['cargo_class']) : '' ?></span>
								</div>
								<?php endforeach; ?>
								<?php endif; ?>
							<?php else: ?>
								<p class="text-muted small mb-0">No idle rolling stock. Build at Vehicle Factory or buy at Customs.</p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<?php if (!$isCustomsHouse): ?>
			<!-- ============================================================ -->
			<!-- POWER INFRASTRUCTURE ROW -->
			<!-- ============================================================ -->
			<div class="row">
				<!-- Power Grid Status -->
				<div class="col-lg-4">
					<div class="card mb-3">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa fa-bolt me-1"></i>Power Grid</span>
							<?php
							$gridPct = $gridInfo['total_demand'] > 0 ? round(min($gridInfo['total_generation'] / $gridInfo['total_demand'], 1.0) * 100) : ($gridInfo['total_generation'] > 0 ? 100 : 0);
							$gridClass = $gridPct >= 100 ? 'bg-success' : ($gridPct > 0 ? 'bg-warning' : 'bg-danger');
							?>
							<span class="badge <?= $gridClass ?>"><?= $gridPct ?>%</span>
						</div>
						<div class="card-body">
							<table class="table table-sm table-borderless mb-2">
								<tbody>
									<tr>
										<td class="text-inverse text-opacity-50">Grid Generation</td>
										<td class="fw-bold text-success"><?= number_format($gridInfo['total_generation'], 2) ?> MW</td>
									</tr>
									<tr>
										<td class="text-inverse text-opacity-50">Grid Demand</td>
										<td class="fw-bold text-danger"><?= number_format($gridInfo['total_demand'], 2) ?> MW</td>
									</tr>
									<tr class="table-active">
										<td class="fw-bold">Power Efficiency</td>
										<td class="fw-bold <?= $gridPct >= 100 ? 'text-success' : ($gridPct > 0 ? 'text-warning' : 'text-danger') ?>"><?= $gridPct ?>%</td>
									</tr>
								</tbody>
							</table>
							<div class="progress mb-3" style="height: 10px;">
								<div class="progress-bar <?= $gridClass ?>" style="width: <?= $gridPct ?>%"></div>
							</div>

							<hr class="my-2">
							<small class="fw-bold">This Town</small>
							<table class="table table-sm table-borderless mb-2">
								<tbody>
									<tr>
										<td class="text-inverse text-opacity-50">Local Generation</td>
										<td class="fw-bold"><?= number_format($gridInfo['local_generation'], 2) ?> MW</td>
									</tr>
									<tr>
										<td class="text-inverse text-opacity-50">Local Demand</td>
										<td class="fw-bold"><?= number_format($gridInfo['local_demand'], 2) ?> MW</td>
									</tr>
								</tbody>
							</table>

							<?php if (count($gridInfo['grid_towns']) > 1): ?>
							<hr class="my-2">
							<small class="fw-bold">Connected Towns (<?= count($gridInfo['grid_towns']) ?>)</small>
							<div class="mt-1">
								<?php foreach ($gridInfo['grid_town_names'] as $gtId => $gtName): ?>
									<?php if ($gtId !== $townId): ?>
										<a href="town_view.php?id=<?= $gtId ?>" class="badge bg-dark bg-opacity-50 text-decoration-none me-1 mb-1"><?= htmlspecialchars($gtName) ?></a>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
							<?php else: ?>
							<div class="mt-2">
								<small class="text-inverse text-opacity-50"><i class="fa fa-info-circle me-1"></i>Town is not connected to any power grid. Build transmission lines to share power.</small>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Power Stations -->
				<div class="col-lg-4">
					<div class="card mb-3">
						<div class="card-header fw-bold small"><i class="fa fa-industry me-1"></i>Power Stations</div>
						<div class="card-body">
							<?php if (!empty($gridInfo['local_stations'])): ?>
								<?php foreach ($gridInfo['local_stations'] as $station):
									$sMaxW = $station['max_workers'];
									$sWPct = $sMaxW > 0 ? round($station['workers_assigned'] / $sMaxW * 100) : 0;
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<small class="fw-bold"><?= htmlspecialchars($station['display_name']) ?></small>
											<span class="badge bg-theme">Lvl <?= $station['level'] ?></span>
										</div>
										<table class="table table-sm table-borderless mb-1">
											<tbody>
												<tr>
													<td class="text-inverse text-opacity-50 py-0">Output</td>
													<td class="fw-bold text-success py-0">
														<?php
														$throttle = $station['throttle_ratio'] ?? 1.0;
														$throttledOut = ($station['max_output'] ?? $station['actual_output']) * $throttle;
														$maxOut = $station['max_output'] ?? $station['actual_output'];
														if ($throttle < 1.0 && $maxOut > 0): ?>
															<?= number_format($throttledOut, 2) ?> / <?= number_format($maxOut, 2) ?> MW
															<small class="text-warning ms-1" title="Grid demand is below capacity — station is throttled">(<?= round($throttle * 100) ?>%)</small>
														<?php else: ?>
															<?= number_format($maxOut, 2) ?> MW
														<?php endif; ?>
													</td>
												</tr>
												<tr>
													<td class="text-inverse text-opacity-50 py-0">Fuel Burn</td>
													<td class="py-0">
														<?php
														$throttledFuel = ($station['throttled_fuel'] ?? $station['fuel_consumption']);
														$maxFuel = $station['fuel_consumption'];
														if ($throttle < 1.0 && $maxFuel > 0): ?>
															<?= number_format($throttledFuel, 2) ?> / <?= number_format($maxFuel, 2) ?> t/hr <?= htmlspecialchars($station['fuel_resource_name']) ?>
														<?php else: ?>
															<?= number_format($maxFuel, 2) ?> t/hr <?= htmlspecialchars($station['fuel_resource_name']) ?>
														<?php endif; ?>
													</td>
												</tr>
												<tr>
													<td class="text-inverse text-opacity-50 py-0">Workers</td>
													<td class="py-0"><?= $station['workers_assigned'] ?>/<?= $sMaxW ?> (<?= $sWPct ?>%)</td>
												</tr>
											</tbody>
										</table>
										<div class="progress mb-2" style="height: 6px;">
											<div class="progress-bar<?= $sWPct < 50 ? ' bg-warning' : ' bg-success' ?>" style="width: <?= $sWPct ?>%"></div>
										</div>
										<div class="d-flex gap-2">
											<form method="post" action="mine_action.php" class="d-flex gap-1 flex-grow-1">
												<input type="hidden" name="town_id" value="<?= $townId ?>">
												<input type="hidden" name="action" value="set_power_workers">
												<input type="hidden" name="station_id" value="<?= $station['id'] ?>">
												<input type="number" name="workers" class="form-control form-control-sm" value="<?= $station['workers_assigned'] ?>" min="0" max="<?= $sMaxW ?>" style="width:70px;">
												<button type="submit" class="btn btn-sm btn-outline-theme">Set</button>
											</form>
										</div>

										<?php
											$psNextLevel = (int)$station['level'] + 1;
											$psBuildType = 'power_' . $station['fuel_type'];
											$psCostInfo = getUpgradeCosts($conn, $psBuildType, $psNextLevel, $townId);
										?>
										<?php if (!empty($psCostInfo['costs'])): ?>
										<div class="mt-2 border-top pt-2">
											<small class="fw-bold"><i class="fa fa-arrow-up me-1"></i>Upgrade to Level <?= $psNextLevel ?></small>
											<table class="table table-sm table-borderless mb-1">
												<tbody>
												<?php foreach ($psCostInfo['costs'] as $psCost):
													$psImg = $psCost['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($psCost['image_file']) . '" alt="" width="16" height="16">' : '';
												?>
													<tr>
														<td class="py-0 small"><?= $psImg ?> <?= htmlspecialchars($psCost['resource_name']) ?></td>
														<td class="py-0 text-end fw-bold small"><?= number_format($psCost['amount']) ?></td>
														<td class="py-0 text-end small <?= $psCost['enough'] ? 'text-success' : 'text-danger' ?>">
															(<?= number_format($psCost['in_stock']) ?>)
															<i class="fa <?= $psCost['enough'] ? 'fa-check' : 'fa-times' ?> fa-xs"></i>
														</td>
													</tr>
												<?php endforeach; ?>
												</tbody>
											</table>
											<form method="post" action="mine_action.php">
												<input type="hidden" name="town_id" value="<?= $townId ?>">
												<input type="hidden" name="action" value="upgrade_power_station">
												<input type="hidden" name="station_id" value="<?= $station['id'] ?>">
												<button type="submit" class="btn btn-sm btn-outline-success w-100<?= !$psCostInfo['can_afford'] ? ' disabled' : '' ?>"<?= !$psCostInfo['can_afford'] ? ' disabled' : '' ?>>
													<i class="fa fa-arrow-up me-1"></i>Upgrade
												</button>
											</form>
										</div>
										<?php else: ?>
										<div class="mt-2 border-top pt-2">
											<small class="text-inverse text-opacity-50">Max level reached.</small>
										</div>
										<?php endif; ?>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-inverse text-opacity-50 small mb-2">No power stations in this town.</p>
							<?php endif; ?>

							<!-- Build New Power Station -->
							<hr class="my-2">
							<small class="fw-bold d-block mb-2">Build Power Station</small>
							<div class="d-flex flex-wrap gap-1">
								<?php foreach ($stationTypes as $st): ?>
								<form method="post" action="mine_action.php" class="d-inline">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="build_power_station">
									<input type="hidden" name="fuel_type" value="<?= $st['fuel_type'] ?>">
									<button type="submit" class="btn btn-sm btn-outline-secondary" title="Build <?= htmlspecialchars($st['display_name']) ?>">
										<i class="fa fa-plus me-1"></i><?= ucfirst($st['fuel_type']) ?>
									</button>
								</form>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>

				<!-- Transmission Lines -->
				<div class="col-lg-4">
					<div class="card mb-3">
						<div class="card-header fw-bold small"><i class="fa fa-project-diagram me-1"></i>Transmission Lines</div>
						<div class="card-body">
							<?php if (!empty($transmissionLines)): ?>
								<?php foreach ($transmissionLines as $tl):
									$tlUpCosts = $transmissionUpgradeCosts[$tl['id']] ?? [];
								?>
								<div class="card bg-dark bg-opacity-50 mb-2">
									<div class="card-body py-2 px-3">
										<div class="d-flex justify-content-between align-items-center mb-1">
											<div>
												<a href="town_view.php?id=<?= $tl['other_town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($tl['other_town_name']) ?></a>
												<small class="text-inverse text-opacity-50 ms-1"><?= number_format($tl['distance_km'] ?? 0, 1) ?> km</small>
											</div>
											<div class="d-flex align-items-center gap-2">
												<small class="fw-bold"><?= $tl['capacity'] ?> MW</small>
												<span class="badge bg-theme">Lvl <?= $tl['level'] ?></span>
											</div>
										</div>
										<?php if (!empty($tlUpCosts)): ?>
										<div class="small text-inverse text-opacity-50 mb-1">
											Upgrade to Lvl <?= $tl['level'] + 1 ?> (<?= ($tl['level'] + 1) * 100 ?> MW):
											<?php foreach ($tlUpCosts as $c): ?>
												<span class="<?= $c['enough'] ? 'text-success' : 'text-danger' ?>">
													<?= number_format($c['amount']) ?> <?= htmlspecialchars($c['resource_name']) ?>
												</span>
											<?php endforeach; ?>
										</div>
										<form method="post" action="mine_action.php" class="d-inline">
											<input type="hidden" name="town_id" value="<?= $townId ?>">
											<input type="hidden" name="action" value="upgrade_transmission">
											<input type="hidden" name="line_id" value="<?= $tl['id'] ?>">
											<?php
											$tlCanAfford = !empty($tlUpCosts) && array_reduce($tlUpCosts, fn($carry, $c) => $carry && $c['enough'], true);
											?>
											<button type="submit" class="btn btn-sm btn-outline-success w-100<?= !$tlCanAfford ? ' disabled' : '' ?>"<?= !$tlCanAfford ? ' disabled' : '' ?>>
												<i class="fa fa-arrow-up me-1"></i>Upgrade
											</button>
										</form>
										<?php else: ?>
										<small class="text-muted">Max level reached</small>
										<?php endif; ?>
									</div>
								</div>
								<?php endforeach; ?>
							<?php else: ?>
								<p class="text-inverse text-opacity-50 small mb-2">No transmission lines from this town.</p>
							<?php endif; ?>

							<!-- Build New Transmission Line -->
							<?php
							$availableTowns = array_filter($sameFactionTowns, function($t) use ($existingConnections) {
								return !in_array((int)$t['id'], $existingConnections);
							});
							?>
							<?php if (!empty($availableTowns)): ?>
							<hr class="my-2">
							<small class="fw-bold d-block mb-2">Build Transmission Line</small>
							<?php if (!empty($transmissionBaseCosts['costs'])): ?>
							<div class="small text-inverse text-opacity-50 mb-2">
								Base cost per km:
								<?php foreach ($transmissionBaseCosts['costs'] as $bc): ?>
									<?= number_format($bc['amount']) ?> <?= htmlspecialchars($bc['resource_name']) ?>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
							<form method="post" action="mine_action.php" class="d-flex gap-2">
								<input type="hidden" name="town_id" value="<?= $townId ?>">
								<input type="hidden" name="action" value="build_transmission">
								<select name="target_town_id" class="form-select form-select-sm flex-grow-1">
									<?php foreach ($availableTowns as $at):
										$atDist = $sameFactionDistances[(int)$at['id']] ?? 0;
									?>
										<option value="<?= $at['id'] ?>"><?= htmlspecialchars($at['name']) ?> (<?= number_format($atDist, 1) ?> km)</option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="btn btn-sm btn-outline-theme"><i class="fa fa-plus me-1"></i>Build</button>
							</form>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php endif; /* end power for non-customs */ ?>

			<?php if (!$isCustomsHouse): ?>
			<!-- ============================================================ -->
			<!-- HOUSING INFRASTRUCTURE ROW -->
			<!-- ============================================================ -->
			<div class="row mb-3">
				<?php foreach ($housingTypes as $hType => $hMeta): ?>
				<div class="col-lg-4">
					<div class="card h-100">
						<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
							<span><i class="fa <?= $hMeta['icon'] ?> me-1"></i><?= $hMeta['name'] ?></span>
							<span class="badge bg-theme">+<?= $hMeta['homes'] ?> homes</span>
						</div>
						<div class="card-body">
							<p class="mb-2 small text-inverse text-opacity-50">
								Adds <?= $hMeta['homes'] ?> homes (<?= $hMeta['citizens'] ?> citizens)
							</p>
							<?php if (isset($housingCosts[$hType])): ?>
								<table class="table table-sm table-borderless mb-2">
									<tbody>
									<?php foreach ($housingCosts[$hType] as $cost):
										$costImg = $cost['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($cost['image_file']) . '" alt="" width="20" height="20">' : '';
									?>
										<tr>
											<td class="py-1"><?= $costImg ?> <?= htmlspecialchars($cost['resource_name']) ?></td>
											<td class="py-1 text-end fw-bold"><?= number_format($cost['amount']) ?></td>
											<td class="py-1 text-end <?= $cost['enough'] ? 'text-success' : 'text-danger' ?>">
												(<?= number_format($cost['in_stock']) ?>)
												<i class="fa <?= $cost['enough'] ? 'fa-check' : 'fa-times' ?>"></i>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
								<form method="post" action="mine_action.php">
									<input type="hidden" name="town_id" value="<?= $townId ?>">
									<input type="hidden" name="action" value="build_housing">
									<input type="hidden" name="housing_type" value="<?= $hType ?>">
									<button type="submit" class="btn btn-sm btn-theme w-100<?= !$housingAffordable[$hType] ? ' disabled' : '' ?>"<?= !$housingAffordable[$hType] ? ' disabled' : '' ?>>
										<i class="fa fa-plus me-1"></i>Build <?= $hMeta['name'] ?>
									</button>
								</form>
							<?php else: ?>
								<p class="text-muted small mb-0">Cost data not found — run setup_housing.php</p>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; /* end housing for non-customs */ ?>

			<?php if ($hasVehicleFactory && !empty($buildableVehicleTypes)): ?>
			<!-- ============================================================ -->
			<!-- BUILD VEHICLES (requires Vehicle Factory) -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-wrench me-1"></i>Vehicle Factory — Build Vehicles</span>
					<span class="badge bg-success">Factory Active</span>
				</div>
				<div class="card-body">
					<div class="row">
						<?php foreach ($buildableVehicleTypes as $bv): ?>
						<div class="col-lg-4 col-md-6 mb-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-body py-2 px-3">
									<div class="d-flex justify-content-between align-items-center mb-1">
										<span class="fw-bold small"><?= htmlspecialchars($bv['name']) ?></span>
										<span>
											<?= $bv['category'] === 'military' ? '<span class="badge bg-danger" style="font-size:0.65em">MIL</span>' : '<span class="badge bg-info" style="font-size:0.65em">CIV</span>' ?>
											<?= $bv['vehicle_class'] === 'cargo' ? '<span class="badge bg-success" style="font-size:0.65em">Cargo</span>' : '<span class="badge bg-primary" style="font-size:0.65em">Pax</span>' ?>
										</span>
									</div>
									<div class="small text-inverse text-opacity-50 mb-2">
										Speed: <?= $bv['max_speed_kmh'] ?> km/h | Cap: <?= $bv['max_capacity'] ?>t
										<?php if ($bv['cargo_class']): ?> | <?= htmlspecialchars($bv['cargo_class']) ?><?php endif; ?>
									</div>
									<?php if (!empty($bv['build_costs'])): ?>
									<table class="table table-sm table-borderless mb-2" style="font-size:0.8em;">
										<tbody>
										<?php foreach ($bv['build_costs'] as $bc):
											$bcImg = $bc['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($bc['image_file']) . '" width="16" height="16">' : '';
										?>
											<tr>
												<td class="py-0"><?= $bcImg ?> <?= htmlspecialchars($bc['resource_name']) ?></td>
												<td class="py-0 text-end fw-bold"><?= number_format($bc['amount']) ?></td>
												<td class="py-0 text-end <?= $bc['enough'] ? 'text-success' : 'text-danger' ?>">
													(<?= number_format($bc['in_stock']) ?>)
													<i class="fa <?= $bc['enough'] ? 'fa-check' : 'fa-times' ?>" style="font-size:0.8em"></i>
												</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
									<form method="post" action="mine_action.php">
										<input type="hidden" name="town_id" value="<?= $townId ?>">
										<input type="hidden" name="action" value="build_vehicle">
										<input type="hidden" name="vehicle_type_id" value="<?= $bv['id'] ?>">
										<button type="submit" class="btn btn-sm btn-outline-theme w-100<?= !$bv['can_build'] ? ' disabled' : '' ?>"<?= !$bv['can_build'] ? ' disabled' : '' ?>>
											<i class="fa fa-plus me-1"></i>Build
										</button>
									</form>
									<?php else: ?>
									<small class="text-muted">No build costs defined — contact admin</small>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<?php if ($hasVehicleFactory && (!empty($buildableLocoTypes) || !empty($buildableWagonTypes))): ?>
			<!-- ============================================================ -->
			<!-- BUILD TRAINS (requires Vehicle Factory) -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-train me-1"></i>Vehicle Factory — Build Locomotives & Wagons</span>
					<span class="badge bg-success">Factory Active</span>
				</div>
				<div class="card-body">
					<?php if (!empty($buildableLocoTypes)): ?>
					<h6 class="text-info">🚂 Locomotives</h6>
					<div class="row mb-3">
						<?php foreach ($buildableLocoTypes as $bl):
							$pIcon = match($bl['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
						?>
						<div class="col-lg-4 col-md-6 mb-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-body py-2 px-3">
									<div class="fw-bold small mb-1"><?= $pIcon ?> <?= htmlspecialchars($bl['name']) ?></div>
									<div class="small text-inverse text-opacity-50 mb-2">
										Speed: <?= $bl['max_speed_kmh'] ?> km/h | Wagons: <?= $bl['max_wagons'] ?>
									</div>
									<?php if (!empty($bl['build_costs'])): ?>
									<table class="table table-sm table-borderless mb-2" style="font-size:0.8em;">
										<tbody>
										<?php foreach ($bl['build_costs'] as $bc):
											$bcImg = $bc['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($bc['image_file']) . '" width="16" height="16">' : '';
										?>
										<tr>
											<td class="py-0"><?= $bcImg ?> <?= htmlspecialchars($bc['resource_name']) ?></td>
											<td class="py-0 text-end fw-bold"><?= number_format($bc['amount']) ?></td>
											<td class="py-0 text-end <?= $bc['enough'] ? 'text-success' : 'text-danger' ?>">
												(<?= number_format($bc['in_stock']) ?>)
												<i class="fa <?= $bc['enough'] ? 'fa-check' : 'fa-times' ?>" style="font-size:0.8em"></i>
											</td>
										</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
									<form method="post" action="mine_action.php">
										<input type="hidden" name="town_id" value="<?= $townId ?>">
										<input type="hidden" name="action" value="build_locomotive">
										<input type="hidden" name="locomotive_type_id" value="<?= $bl['id'] ?>">
										<button type="submit" class="btn btn-sm btn-outline-theme w-100<?= !$bl['can_build'] ? ' disabled' : '' ?>"<?= !$bl['can_build'] ? ' disabled' : '' ?>>
											<i class="fa fa-plus me-1"></i>Build
										</button>
									</form>
									<?php else: ?>
									<small class="text-muted">No build costs defined</small>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<?php if (!empty($buildableWagonTypes)): ?>
					<h6 class="text-info">🚃 Wagons & Carriages</h6>
					<div class="row">
						<?php foreach ($buildableWagonTypes as $bw): ?>
						<div class="col-lg-4 col-md-6 mb-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-body py-2 px-3">
									<div class="fw-bold small mb-1"><?= $bw['wagon_class'] === 'passenger' ? '👥' : '📦' ?> <?= htmlspecialchars($bw['name']) ?></div>
									<div class="small text-inverse text-opacity-50 mb-2">
										<?= ucfirst($bw['wagon_class']) ?> | Cap: <?= $bw['max_capacity'] ?><?= $bw['wagon_class'] === 'passenger' ? ' pax' : 't' ?>
										<?php if ($bw['cargo_class']): ?> | <?= htmlspecialchars($bw['cargo_class']) ?><?php endif; ?>
									</div>
									<?php if (!empty($bw['build_costs'])): ?>
									<table class="table table-sm table-borderless mb-2" style="font-size:0.8em;">
										<tbody>
										<?php foreach ($bw['build_costs'] as $bc):
											$bcImg = $bc['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($bc['image_file']) . '" width="16" height="16">' : '';
										?>
										<tr>
											<td class="py-0"><?= $bcImg ?> <?= htmlspecialchars($bc['resource_name']) ?></td>
											<td class="py-0 text-end fw-bold"><?= number_format($bc['amount']) ?></td>
											<td class="py-0 text-end <?= $bc['enough'] ? 'text-success' : 'text-danger' ?>">
												(<?= number_format($bc['in_stock']) ?>)
												<i class="fa <?= $bc['enough'] ? 'fa-check' : 'fa-times' ?>" style="font-size:0.8em"></i>
											</td>
										</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
									<form method="post" action="mine_action.php">
										<input type="hidden" name="town_id" value="<?= $townId ?>">
										<input type="hidden" name="action" value="build_wagon">
										<input type="hidden" name="wagon_type_id" value="<?= $bw['id'] ?>">
										<button type="submit" class="btn btn-sm btn-outline-theme w-100<?= !$bw['can_build'] ? ' disabled' : '' ?>"<?= !$bw['can_build'] ? ' disabled' : '' ?>>
											<i class="fa fa-plus me-1"></i>Build
										</button>
									</form>
									<?php else: ?>
									<small class="text-muted">No build costs defined</small>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>

		</div>
		<!-- END #content -->
<?php
include "files/scripts.php";
?>
