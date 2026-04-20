<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);

$action = $_POST['action'] ?? '';
$faction = $user['team'];
$username = $user['username'];

// Green team can act for any faction
$allowedFactions = viewableFactions($user);

// ============================================================
// CREATE AUTO-TRANSPORT RULE
// ============================================================
if ($action === 'create_rule') {
    $fromTownId = (int)($_POST['from_town_id'] ?? 0);
    $toTownId = (int)($_POST['to_town_id'] ?? 0);
    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $threshold = max(1, (float)($_POST['threshold'] ?? 100));
    $sendAmount = max(1, (float)($_POST['send_amount'] ?? 50));
    $transportType = in_array($_POST['transport_type'] ?? '', ['truck', 'train']) ? $_POST['transport_type'] : 'truck';
    $returnEmpty = isset($_POST['return_empty']) ? 1 : 0;
    $ruleFaction = $_POST['faction'] ?? $faction;

    if (!in_array($ruleFaction, $allowedFactions)) {
        header("Location: auto_transport.php?msg=denied");
        exit;
    }

    // Validate towns exist and belong to same faction
    $t1 = $conn->query("SELECT id, name, side FROM towns WHERE id = $fromTownId")->fetch_assoc();
    $t2 = $conn->query("SELECT id, name, side FROM towns WHERE id = $toTownId")->fetch_assoc();
    if (!$t1 || !$t2 || $t1['side'] !== $ruleFaction || $t2['side'] !== $ruleFaction) {
        header("Location: auto_transport.php?msg=invalid_towns&faction=$ruleFaction");
        exit;
    }
    if ($fromTownId === $toTownId) {
        header("Location: auto_transport.php?msg=same_town&faction=$ruleFaction");
        exit;
    }

    // Validate route exists
    $rt1 = min($fromTownId, $toTownId);
    $rt2 = max($fromTownId, $toTownId);
    $routeCheck = $conn->query("SELECT distance_km FROM town_distances WHERE town_id_1 = $rt1 AND town_id_2 = $rt2");
    if (!$routeCheck || $routeCheck->num_rows === 0) {
        header("Location: auto_transport.php?msg=no_route&faction=$ruleFaction");
        exit;
    }

    // Validate resource exists
    $resCheck = $conn->query("SELECT id FROM world_prices WHERE id = $resourceId");
    if (!$resCheck || $resCheck->num_rows === 0) {
        header("Location: auto_transport.php?msg=invalid_resource&faction=$ruleFaction");
        exit;
    }

    // Check for duplicate rule
    $dupCheck = $conn->prepare("SELECT id FROM auto_transport WHERE faction = ? AND from_town_id = ? AND to_town_id = ? AND resource_id = ?");
    $dupCheck->bind_param("siii", $ruleFaction, $fromTownId, $toTownId, $resourceId);
    $dupCheck->execute();
    if ($dupCheck->get_result()->num_rows > 0) {
        $dupCheck->close();
        header("Location: auto_transport.php?msg=duplicate&faction=$ruleFaction");
        exit;
    }
    $dupCheck->close();

    $stmt = $conn->prepare("INSERT INTO auto_transport (faction, from_town_id, to_town_id, resource_id, threshold, send_amount, transport_type, return_empty, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siiiddsss", $ruleFaction, $fromTownId, $toTownId, $resourceId, $threshold, $sendAmount, $transportType, $returnEmpty, $username);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_transport.php?msg=created&faction=$ruleFaction");
    exit;
}

// ============================================================
// TOGGLE RULE ON/OFF
// ============================================================
if ($action === 'toggle_rule') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);

    $rule = $conn->query("SELECT id, faction FROM auto_transport WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_transport.php?msg=denied");
        exit;
    }

    $conn->query("UPDATE auto_transport SET enabled = NOT enabled WHERE id = $ruleId");
    header("Location: auto_transport.php?msg=toggled&faction=" . $rule['faction']);
    exit;
}

// ============================================================
// DELETE RULE
// ============================================================
if ($action === 'delete_rule') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);

    $rule = $conn->query("SELECT id, faction FROM auto_transport WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_transport.php?msg=denied");
        exit;
    }

    $conn->query("DELETE FROM auto_transport WHERE id = $ruleId");
    header("Location: auto_transport.php?msg=deleted&faction=" . $rule['faction']);
    exit;
}

// ============================================================
// UPDATE RULE AMOUNTS
// ============================================================
if ($action === 'update_rule') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $threshold = max(1, (float)($_POST['threshold'] ?? 100));
    $sendAmount = max(1, (float)($_POST['send_amount'] ?? 50));

    $rule = $conn->query("SELECT id, faction FROM auto_transport WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_transport.php?msg=denied");
        exit;
    }

    $stmt = $conn->prepare("UPDATE auto_transport SET threshold = ?, send_amount = ? WHERE id = ?");
    $stmt->bind_param("ddi", $threshold, $sendAmount, $ruleId);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_transport.php?msg=updated&faction=" . $rule['faction']);
    exit;
}

header("Location: auto_transport.php");
exit;
?>
