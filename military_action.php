<?php
// military_action.php — POST handler for all military actions
session_start();
include "files/config.php";
include "files/auth.php";

$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);

$act = $_POST['action'] ?? '';
$townId = (int)($_POST['town_id'] ?? 0);

if ($townId <= 0 || !$act) {
    header("Location: military.php?msg=invalid");
    exit;
}

// Verify town exists and user can access it
$stmt = $conn->prepare("SELECT id, name, side FROM towns WHERE id = ?");
$stmt->bind_param("i", $townId);
$stmt->execute();
$town = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$town || !canViewFaction($user, $town['side'])) {
    header("Location: military.php?msg=access_denied");
    exit;
}

$faction = $town['side'];

// Helper: check if town has enough resources
function checkResources($conn, $townId, $costs, $multiplier = 1) {
    foreach ($costs as $c) {
        $needed = (float)$c['amount'] * $multiplier;
        $sRes = $conn->query("SELECT stock FROM town_resources WHERE town_id = $townId AND resource_id = {$c['resource_id']}");
        $sRow = $sRes ? $sRes->fetch_assoc() : null;
        $inStock = $sRow ? (float)$sRow['stock'] : 0;
        if ($inStock < $needed) return false;
    }
    return true;
}

// Helper: deduct resources
function deductResources($conn, $townId, $costs, $multiplier = 1) {
    foreach ($costs as $c) {
        $needed = (float)$c['amount'] * $multiplier;
        $conn->query("UPDATE town_resources SET stock = stock - $needed WHERE town_id = $townId AND resource_id = {$c['resource_id']}");
    }
}

// ═══════════════════════════════════
// BUILD BARRACKS
// ═══════════════════════════════════
if ($act === 'build_barracks') {
    // Check if barracks already exists
    $existing = $conn->query("SELECT id FROM town_barracks WHERE town_id = $townId");
    if ($existing && $existing->num_rows > 0) {
        header("Location: town_view.php?id=$townId&msg=already_built");
        exit;
    }

    // Level 1 costs: use level 2 costs at half rate (or free for level 1)
    $conn->query("INSERT INTO town_barracks (town_id, level, workers_assigned) VALUES ($townId, 1, 0)");
    header("Location: town_view.php?id=$townId&msg=barracks_built");
    exit;
}

// ═══════════════════════════════════
// UPGRADE BARRACKS
// ═══════════════════════════════════
if ($act === 'upgrade_barracks') {
    $bar = $conn->query("SELECT * FROM town_barracks WHERE town_id = $townId")->fetch_assoc();
    if (!$bar) { header("Location: town_view.php?id=$townId&msg=no_barracks"); exit; }

    $nextLevel = (int)$bar['level'] + 1;
    if ($nextLevel > 10) { header("Location: town_view.php?id=$townId&msg=max_level"); exit; }

    // Get upgrade costs
    $costs = [];
    $cRes = $conn->query("SELECT buc.resource_id, buc.amount, wp.resource_name FROM barracks_upgrade_costs buc JOIN world_prices wp ON buc.resource_id = wp.id WHERE buc.target_level = $nextLevel");
    if ($cRes) { while ($c = $cRes->fetch_assoc()) $costs[] = $c; }

    if (!checkResources($conn, $townId, $costs)) {
        header("Location: town_view.php?id=$townId&msg=not_enough_resources");
        exit;
    }

    deductResources($conn, $townId, $costs);
    $conn->query("UPDATE town_barracks SET level = $nextLevel WHERE town_id = $townId");
    header("Location: town_view.php?id=$townId&msg=barracks_upgraded");
    exit;
}

// ═══════════════════════════════════
// SET BARRACKS WORKERS
// ═══════════════════════════════════
if ($act === 'set_barracks_workers') {
    $workers = max(0, (int)($_POST['workers'] ?? 0));
    $conn->query("UPDATE town_barracks SET workers_assigned = $workers WHERE town_id = $townId");
    header("Location: town_view.php?id=$townId&msg=workers_updated");
    exit;
}

// ═══════════════════════════════════
// BUILD MUNITIONS FACTORY
// ═══════════════════════════════════
if ($act === 'build_munitions') {
    $existing = $conn->query("SELECT id FROM town_munitions_factory WHERE town_id = $townId");
    if ($existing && $existing->num_rows > 0) {
        header("Location: town_view.php?id=$townId&msg=already_built");
        exit;
    }

    $conn->query("INSERT INTO town_munitions_factory (town_id, level, workers_assigned) VALUES ($townId, 1, 0)");
    header("Location: town_view.php?id=$townId&msg=munitions_built");
    exit;
}

// ═══════════════════════════════════
// UPGRADE MUNITIONS FACTORY
// ═══════════════════════════════════
if ($act === 'upgrade_munitions') {
    $mun = $conn->query("SELECT * FROM town_munitions_factory WHERE town_id = $townId")->fetch_assoc();
    if (!$mun) { header("Location: town_view.php?id=$townId&msg=no_munitions"); exit; }

    $nextLevel = (int)$mun['level'] + 1;
    if ($nextLevel > 10) { header("Location: town_view.php?id=$townId&msg=max_level"); exit; }

    $costs = [];
    $cRes = $conn->query("SELECT muc.resource_id, muc.amount, wp.resource_name FROM munitions_upgrade_costs muc JOIN world_prices wp ON muc.resource_id = wp.id WHERE muc.target_level = $nextLevel");
    if ($cRes) { while ($c = $cRes->fetch_assoc()) $costs[] = $c; }

    if (!checkResources($conn, $townId, $costs)) {
        header("Location: town_view.php?id=$townId&msg=not_enough_resources");
        exit;
    }

    deductResources($conn, $townId, $costs);
    $conn->query("UPDATE town_munitions_factory SET level = $nextLevel WHERE town_id = $townId");
    header("Location: town_view.php?id=$townId&msg=munitions_upgraded");
    exit;
}

// ═══════════════════════════════════
// SET MUNITIONS WORKERS
// ═══════════════════════════════════
if ($act === 'set_munitions_workers') {
    $workers = max(0, (int)($_POST['workers'] ?? 0));
    $conn->query("UPDATE town_munitions_factory SET workers_assigned = $workers WHERE town_id = $townId");
    header("Location: town_view.php?id=$townId&msg=workers_updated");
    exit;
}

// (Instant recruit removed — troops are auto-recruited by barracks workers each tick)

// ═══════════════════════════════════
// ARM TROOPS (equip or re-arm with weapons)
// ═══════════════════════════════════
if ($act === 'arm_troops') {
    $newWeaponId = (int)($_POST['weapon_type_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $sourceWeapon = $_POST['source_weapon_id'] ?? 'NULL';

    if ($newWeaponId <= 0) { header("Location: military.php?town=$townId&msg=invalid"); exit; }

    // Parse source weapon (NULL = unarmed, or an integer weapon_type_id)
    $sourceIsUnarmed = ($sourceWeapon === '' || $sourceWeapon === 'NULL');
    $sourceWeaponInt = $sourceIsUnarmed ? null : (int)$sourceWeapon;

    // Check source troops available
    if ($sourceIsUnarmed) {
        $srcCheck = $conn->query("SELECT quantity FROM town_troops WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id IS NULL");
    } else {
        $srcCheck = $conn->query("SELECT quantity FROM town_troops WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id = $sourceWeaponInt");
    }
    $srcAvailable = ($srcCheck && $srcRow = $srcCheck->fetch_assoc()) ? (int)$srcRow['quantity'] : 0;

    if ($srcAvailable < $qty) {
        header("Location: military.php?town=$townId&msg=not_enough_troops");
        exit;
    }

    // Don't re-arm to same weapon
    if (!$sourceIsUnarmed && $sourceWeaponInt === $newWeaponId) {
        header("Location: military.php?town=$townId&msg=invalid");
        exit;
    }

    // Check new weapon stock
    $wStock = 0;
    $wsRes = $conn->query("SELECT stock FROM town_weapons_stock WHERE town_id = $townId AND weapon_type_id = $newWeaponId");
    if ($wsRes && $wsRow = $wsRes->fetch_assoc()) $wStock = (int)$wsRow['stock'];

    if ($wStock < $qty) {
        header("Location: military.php?town=$townId&msg=not_enough_weapons");
        exit;
    }

    // Deduct source troops
    if ($sourceIsUnarmed) {
        $conn->query("UPDATE town_troops SET quantity = quantity - $qty WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id IS NULL");
    } else {
        $conn->query("UPDATE town_troops SET quantity = quantity - $qty WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id = $sourceWeaponInt");
        // Return old weapons to stock
        $conn->query("INSERT INTO town_weapons_stock (town_id, weapon_type_id, stock) VALUES ($townId, $sourceWeaponInt, $qty)
            ON DUPLICATE KEY UPDATE stock = stock + $qty");
    }
    $conn->query("DELETE FROM town_troops WHERE quantity <= 0");

    // Deduct new weapon stock
    $conn->query("UPDATE town_weapons_stock SET stock = stock - $qty WHERE town_id = $townId AND weapon_type_id = $newWeaponId");
    $conn->query("DELETE FROM town_weapons_stock WHERE stock <= 0");

    // Add armed troops with new weapon
    $conn->query("INSERT INTO town_troops (town_id, faction, weapon_type_id, quantity)
        VALUES ($townId, '$faction', $newWeaponId, $qty)
        ON DUPLICATE KEY UPDATE quantity = quantity + $qty");

    $msgType = $sourceIsUnarmed ? 'armed' : 'rearmed';
    header("Location: military.php?town=$townId&msg=$msgType&qty=$qty");
    exit;
}

// ═══════════════════════════════════
// MOVE TROOPS (walk or mount in vehicle)
// ═══════════════════════════════════
if ($act === 'move_troops') {
    $toTownId = (int)($_POST['to_town_id'] ?? 0);
    $weaponId = $_POST['weapon_type_id'] ?? '';
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $transport = $_POST['transport'] ?? 'walk';

    // weapon_type_id can be empty string for unarmed
    $weaponIdSql = ($weaponId === '' || $weaponId === 'NULL') ? 'NULL' : (int)$weaponId;
    $weaponIdInt = ($weaponId === '' || $weaponId === 'NULL') ? null : (int)$weaponId;

    if ($toTownId <= 0 || $toTownId === $townId) {
        header("Location: military.php?town=$townId&msg=invalid_destination");
        exit;
    }

    // Check destination town exists
    $destStmt = $conn->prepare("SELECT id, name, side FROM towns WHERE id = ?");
    $destStmt->bind_param("i", $toTownId);
    $destStmt->execute();
    $destTown = $destStmt->get_result()->fetch_assoc();
    $destStmt->close();

    if (!$destTown) {
        header("Location: military.php?town=$townId&msg=invalid_destination");
        exit;
    }

    // Check if troops available
    $troopCheck = $weaponIdInt === null
        ? $conn->query("SELECT quantity FROM town_troops WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id IS NULL")
        : $conn->query("SELECT quantity FROM town_troops WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id = $weaponIdInt");
    $available = ($troopCheck && $tr = $troopCheck->fetch_assoc()) ? (int)$tr['quantity'] : 0;

    if ($available < $qty) {
        header("Location: military.php?town=$townId&msg=not_enough_troops");
        exit;
    }

    // Determine transport type and speed
    $transportType = 'walk';
    $vehicleId = null;
    $speed = 2; // walking speed: 2 km/h

    if ($transport !== 'walk' && str_starts_with($transport, 'v_')) {
        $vehicleId = (int)substr($transport, 2);
        // Validate vehicle is idle, military, at this town, and belongs to faction
        $vehStmt = $conn->prepare("
            SELECT v.id, vt.max_speed_kmh, vt.max_capacity
            FROM vehicles v
            JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
            WHERE v.id = ? AND v.town_id = ? AND v.faction = ? AND v.status = 'idle' AND vt.category = 'military'
        ");
        $vehStmt->bind_param("iis", $vehicleId, $townId, $faction);
        $vehStmt->execute();
        $veh = $vehStmt->get_result()->fetch_assoc();
        $vehStmt->close();

        if (!$veh) {
            header("Location: military.php?town=$townId&msg=no_vehicle");
            exit;
        }

        // Check vehicle capacity
        if ($qty > (int)$veh['max_capacity']) {
            header("Location: military.php?town=$townId&msg=vehicle_capacity");
            exit;
        }

        $speed = (float)$veh['max_speed_kmh'];
        $transportType = 'vehicle';
    }

    // Calculate distance and ETA
    $dist = 50; // default
    $dRes = $conn->query("SELECT distance_km FROM town_distances WHERE
        (town_id_1 = $townId AND town_id_2 = $toTownId) OR (town_id_1 = $toTownId AND town_id_2 = $townId) LIMIT 1");
    if ($dRes && $dRow = $dRes->fetch_assoc()) $dist = (float)$dRow['distance_km'];

    $travelHours = $dist / $speed;
    $travelMinutes = ceil($travelHours * 60);

    // Is this an attack? (different faction)
    $isAttack = ($destTown['side'] !== $faction) ? 1 : 0;

    // Deduct troops from town
    if ($weaponIdInt === null) {
        $conn->query("UPDATE town_troops SET quantity = quantity - $qty WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id IS NULL");
    } else {
        $conn->query("UPDATE town_troops SET quantity = quantity - $qty WHERE town_id = $townId AND faction = '$faction' AND weapon_type_id = $weaponIdInt");
    }
    $conn->query("DELETE FROM town_troops WHERE quantity <= 0");

    // Mark vehicle as in_transit if used
    if ($vehicleId) {
        $conn->query("UPDATE vehicles SET status = 'in_transit' WHERE id = $vehicleId");
    }

    // Create movement
    $stmt = $conn->prepare("INSERT INTO troop_movements (faction, from_town_id, to_town_id, weapon_type_id, quantity, departed_at, eta_at, distance_km, speed_kmh, is_attack, transport_type, vehicle_id)
        VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiiiiidisi", $faction, $townId, $toTownId, $weaponIdInt, $qty, $travelMinutes, $dist, $speed, $isAttack, $transportType, $vehicleId);
    $stmt->execute();
    $stmt->close();

    $actionWord = $isAttack ? 'attacking' : 'reinforcing';
    header("Location: military.php?town=$townId&msg=troops_sent&to=" . urlencode($destTown['name']) . "&qty=$qty&action=$actionWord");
    exit;
}

// ═══════════════════════════════════
// SET WEAPON PRODUCTION (choose what the factory produces per tick)
// ═══════════════════════════════════
if ($act === 'set_production') {
    $weaponId = $_POST['weapon_type_id'] ?? '';

    // Must have munitions factory
    $mun = $conn->query("SELECT * FROM town_munitions_factory WHERE town_id = $townId")->fetch_assoc();
    if (!$mun) { header("Location: military.php?town=$townId&msg=no_munitions"); exit; }

    if ($weaponId === '' || $weaponId === '0') {
        // Stop production (set to idle)
        $conn->query("UPDATE town_munitions_factory SET producing_weapon_type_id = NULL WHERE town_id = $townId");
        header("Location: military.php?town=$townId&msg=production_stopped");
    } else {
        $weaponId = (int)$weaponId;
        // Verify weapon type exists and is active
        $wtCheck = $conn->query("SELECT id FROM weapon_types WHERE id = $weaponId AND active = 1");
        if (!$wtCheck || $wtCheck->num_rows === 0) {
            header("Location: military.php?town=$townId&msg=invalid");
            exit;
        }
        $conn->query("UPDATE town_munitions_factory SET producing_weapon_type_id = $weaponId WHERE town_id = $townId");
        header("Location: military.php?town=$townId&msg=production_set");
    }
    exit;
}

// (Instant produce removed — weapons are auto-produced by munitions factory workers each tick)

header("Location: military.php");
exit;
