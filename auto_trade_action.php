<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);

$action = $_POST['action'] ?? '';
$allowedFactions = viewableFactions($user);
$username = $user['username'];

// ============================================================
// CREATE AUTO-TRADE RULE (buy/sell at customs)
// ============================================================
if ($action === 'create_trade') {
    $ruleFaction = $_POST['faction'] ?? '';
    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $tradeAction = in_array($_POST['trade_action'] ?? '', ['buy', 'sell']) ? $_POST['trade_action'] : 'buy';
    $threshold = max(1, (float)($_POST['threshold'] ?? 100));
    $orderAmount = max(1, (float)($_POST['order_amount'] ?? 50));
    $minBalance = max(0, (float)($_POST['min_balance'] ?? 0));

    if (!in_array($ruleFaction, $allowedFactions)) {
        header("Location: auto_trade.php?msg=denied");
        exit;
    }

    $resCheck = $conn->query("SELECT id FROM world_prices WHERE id = $resourceId");
    if (!$resCheck || $resCheck->num_rows === 0) {
        header("Location: auto_trade.php?msg=invalid_resource&faction=$ruleFaction");
        exit;
    }

    // Check duplicate
    $dup = $conn->prepare("SELECT id FROM auto_trade WHERE faction = ? AND resource_id = ? AND trade_action = ?");
    $dup->bind_param("sis", $ruleFaction, $resourceId, $tradeAction);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        header("Location: auto_trade.php?msg=duplicate&faction=$ruleFaction");
        exit;
    }
    $dup->close();

    $stmt = $conn->prepare("INSERT INTO auto_trade (faction, resource_id, trade_action, threshold, order_amount, min_balance, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisddds", $ruleFaction, $resourceId, $tradeAction, $threshold, $orderAmount, $minBalance, $username);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_trade.php?msg=created&faction=$ruleFaction");
    exit;
}

// ============================================================
// CREATE AUTO-RESUPPLY RULE
// ============================================================
if ($action === 'create_resupply') {
    $ruleFaction = $_POST['faction'] ?? '';
    $townId = (int)($_POST['town_id'] ?? 0);
    $resourceId = (int)($_POST['resource_id'] ?? 0);
    $lowThreshold = max(1, (float)($_POST['low_threshold'] ?? 50));
    $orderAmount = max(1, (float)($_POST['order_amount'] ?? 100));
    $minBalance = max(0, (float)($_POST['min_balance'] ?? 0));
    $transportType = in_array($_POST['transport_type'] ?? '', ['truck', 'train']) ? $_POST['transport_type'] : 'truck';

    if (!in_array($ruleFaction, $allowedFactions)) {
        header("Location: auto_trade.php?tab=resupply&msg=denied");
        exit;
    }

    // Validate town
    $townCheck = $conn->query("SELECT id, side FROM towns WHERE id = $townId")->fetch_assoc();
    if (!$townCheck || $townCheck['side'] !== $ruleFaction) {
        header("Location: auto_trade.php?tab=resupply&msg=invalid_town&faction=$ruleFaction");
        exit;
    }

    // Validate resource
    $resCheck = $conn->query("SELECT id FROM world_prices WHERE id = $resourceId");
    if (!$resCheck || $resCheck->num_rows === 0) {
        header("Location: auto_trade.php?tab=resupply&msg=invalid_resource&faction=$ruleFaction");
        exit;
    }

    // Check duplicate
    $dup = $conn->prepare("SELECT id FROM auto_resupply WHERE faction = ? AND town_id = ? AND resource_id = ?");
    $dup->bind_param("sii", $ruleFaction, $townId, $resourceId);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        $dup->close();
        header("Location: auto_trade.php?tab=resupply&msg=duplicate&faction=$ruleFaction");
        exit;
    }
    $dup->close();

    $stmt = $conn->prepare("INSERT INTO auto_resupply (faction, town_id, resource_id, low_threshold, order_amount, min_balance, transport_type, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("siidddss", $ruleFaction, $townId, $resourceId, $lowThreshold, $orderAmount, $minBalance, $transportType, $username);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_trade.php?tab=resupply&msg=created&faction=$ruleFaction");
    exit;
}

// ============================================================
// TOGGLE (works for both tables)
// ============================================================
if ($action === 'toggle_trade' || $action === 'toggle_resupply') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $table = $action === 'toggle_trade' ? 'auto_trade' : 'auto_resupply';
    $tab = $action === 'toggle_resupply' ? '&tab=resupply' : '';

    $rule = $conn->query("SELECT id, faction FROM $table WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_trade.php?msg=denied");
        exit;
    }

    $conn->query("UPDATE $table SET enabled = NOT enabled WHERE id = $ruleId");
    header("Location: auto_trade.php?msg=toggled&faction=" . $rule['faction'] . $tab);
    exit;
}

// ============================================================
// DELETE (works for both tables)
// ============================================================
if ($action === 'delete_trade' || $action === 'delete_resupply') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $table = $action === 'delete_trade' ? 'auto_trade' : 'auto_resupply';
    $tab = $action === 'delete_resupply' ? '&tab=resupply' : '';

    $rule = $conn->query("SELECT id, faction FROM $table WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_trade.php?msg=denied");
        exit;
    }

    $conn->query("DELETE FROM $table WHERE id = $ruleId");
    header("Location: auto_trade.php?msg=deleted&faction=" . $rule['faction'] . $tab);
    exit;
}

// ============================================================
// UPDATE AMOUNTS
// ============================================================
if ($action === 'update_trade') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $threshold = max(1, (float)($_POST['threshold'] ?? 100));
    $orderAmount = max(1, (float)($_POST['order_amount'] ?? 50));
    $minBalance = max(0, (float)($_POST['min_balance'] ?? 0));

    $rule = $conn->query("SELECT id, faction FROM auto_trade WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_trade.php?msg=denied");
        exit;
    }

    $stmt = $conn->prepare("UPDATE auto_trade SET threshold = ?, order_amount = ?, min_balance = ? WHERE id = ?");
    $stmt->bind_param("dddi", $threshold, $orderAmount, $minBalance, $ruleId);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_trade.php?msg=updated&faction=" . $rule['faction']);
    exit;
}

if ($action === 'update_resupply') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $lowThreshold = max(1, (float)($_POST['low_threshold'] ?? 50));
    $orderAmount = max(1, (float)($_POST['order_amount'] ?? 100));
    $minBalance = max(0, (float)($_POST['min_balance'] ?? 0));

    $rule = $conn->query("SELECT id, faction FROM auto_resupply WHERE id = $ruleId")->fetch_assoc();
    if (!$rule || !in_array($rule['faction'], $allowedFactions)) {
        header("Location: auto_trade.php?tab=resupply&msg=denied");
        exit;
    }

    $stmt = $conn->prepare("UPDATE auto_resupply SET low_threshold = ?, order_amount = ?, min_balance = ? WHERE id = ?");
    $stmt->bind_param("dddi", $lowThreshold, $orderAmount, $minBalance, $ruleId);
    $stmt->execute();
    $stmt->close();

    header("Location: auto_trade.php?tab=resupply&msg=updated&faction=" . $rule['faction']);
    exit;
}

header("Location: auto_trade.php");
exit;
?>
