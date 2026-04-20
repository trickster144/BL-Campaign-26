<?php
// trade_action.php — Handles buy/sell POST actions from trade.php
session_start();
include __DIR__ . "/files/config.php";
include __DIR__ . "/files/auth.php";

$user = getCurrentUser($conn);
requireLogin();
requireTeamAssignment($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: trade.php");
    exit;
}

$faction = $_POST['faction'] ?? '';
$resourceId = (int)($_POST['resource_id'] ?? 0);
$action = $_POST['trade_action'] ?? '';
$quantity = (float)($_POST['quantity'] ?? 1);

// Verify user can trade for this faction
if ($faction && !canViewFaction($user, $faction)) {
    header("Location: trade.php?msg=access_denied");
    exit;
}

// Handle vehicle purchase separately
if ($action === 'buy_vehicle') {
    $vtId = (int)($_POST['vehicle_type_id'] ?? 0);
    if (!in_array($faction, ['blue', 'red']) || $vtId <= 0) {
        header("Location: trade.php?msg=invalid");
        exit;
    }

    // Get vehicle type
    $vtStmt = $conn->prepare("SELECT id, name, points_price FROM vehicle_types WHERE id = ? AND active = 1");
    $vtStmt->bind_param("i", $vtId);
    $vtStmt->execute();
    $vType = $vtStmt->get_result()->fetch_assoc();
    $vtStmt->close();
    if (!$vType) { header("Location: trade.php?msg=invalid"); exit; }

    // Check faction balance
    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($faction) . "'")->fetch_assoc();
    $balance = $balRow ? (float)$balRow['points'] : 0;
    if ($balance < $vType['points_price']) {
        header("Location: trade.php?faction=$faction&msg=insufficient_funds");
        exit;
    }

    // Get customs house
    $customsName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
    $csRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($customsName) . "'")->fetch_assoc();
    $customsTownId = $csRow ? (int)$csRow['id'] : 0;
    if (!$customsTownId) { header("Location: trade.php?msg=no_customs"); exit; }

    // Deduct points
    $conn->query("UPDATE faction_balance SET points = points - " . (float)$vType['points_price'] . " WHERE faction = '" . $conn->real_escape_string($faction) . "'");

    // Create vehicle in customs house
    $vStmt = $conn->prepare("INSERT INTO vehicles (vehicle_type_id, town_id, faction, status) VALUES (?, ?, ?, 'idle')");
    $vStmt->bind_param("iis", $vtId, $customsTownId, $faction);
    $vStmt->execute();
    $vStmt->close();

    header("Location: trade.php?faction=$faction&msg=success&detail=" . urlencode("Purchased " . $vType['name'] . " for " . number_format($vType['points_price'], 2) . " points. Vehicle is at " . $customsName . "."));
    exit;
}

// ── Buy Locomotive at Customs ──
if ($action === 'buy_locomotive') {
    $ltId = (int)($_POST['loco_type_id'] ?? 0);
    if (!in_array($faction, ['blue', 'red']) || $ltId <= 0) {
        header("Location: trade.php?msg=invalid");
        exit;
    }

    $lt = $conn->query("SELECT id, name, points_price FROM locomotive_types WHERE id = $ltId AND active = 1")->fetch_assoc();
    if (!$lt) { header("Location: trade.php?msg=invalid"); exit; }

    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($faction) . "'")->fetch_assoc();
    if (!$balRow || (float)$balRow['points'] < (float)$lt['points_price']) {
        header("Location: trade.php?faction=$faction&msg=insufficient_funds");
        exit;
    }

    $customsName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
    $csRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($customsName) . "'")->fetch_assoc();
    $customsTownId = $csRow ? (int)$csRow['id'] : 0;
    if (!$customsTownId) { header("Location: trade.php?msg=no_customs"); exit; }

    $conn->query("UPDATE faction_balance SET points = points - " . (float)$lt['points_price'] . " WHERE faction = '" . $conn->real_escape_string($faction) . "'");
    $conn->query("INSERT INTO locomotives (locomotive_type_id, town_id, faction, status) VALUES ($ltId, $customsTownId, '$faction', 'idle')");

    header("Location: trade.php?faction=$faction&msg=success&detail=" . urlencode("Purchased " . $lt['name'] . " for " . number_format($lt['points_price']) . " points. Locomotive at " . $customsName . "."));
    exit;
}

// ── Buy Wagon at Customs ──
if ($action === 'buy_wagon') {
    $wtId = (int)($_POST['wagon_type_id'] ?? 0);
    if (!in_array($faction, ['blue', 'red']) || $wtId <= 0) {
        header("Location: trade.php?msg=invalid");
        exit;
    }

    $wt = $conn->query("SELECT id, name, points_price FROM wagon_types WHERE id = $wtId AND active = 1")->fetch_assoc();
    if (!$wt) { header("Location: trade.php?msg=invalid"); exit; }

    $balRow = $conn->query("SELECT points FROM faction_balance WHERE faction = '" . $conn->real_escape_string($faction) . "'")->fetch_assoc();
    if (!$balRow || (float)$balRow['points'] < (float)$wt['points_price']) {
        header("Location: trade.php?faction=$faction&msg=insufficient_funds");
        exit;
    }

    $customsName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
    $csRow = $conn->query("SELECT id FROM towns WHERE name = '" . $conn->real_escape_string($customsName) . "'")->fetch_assoc();
    $customsTownId = $csRow ? (int)$csRow['id'] : 0;
    if (!$customsTownId) { header("Location: trade.php?msg=no_customs"); exit; }

    $conn->query("UPDATE faction_balance SET points = points - " . (float)$wt['points_price'] . " WHERE faction = '" . $conn->real_escape_string($faction) . "'");
    $conn->query("INSERT INTO wagons (wagon_type_id, town_id, faction, status) VALUES ($wtId, $customsTownId, '$faction', 'idle')");

    header("Location: trade.php?faction=$faction&msg=success&detail=" . urlencode("Purchased " . $wt['name'] . " for " . number_format($wt['points_price']) . " points. Wagon at " . $customsName . "."));
    exit;
}

if (!in_array($faction, ['blue', 'red']) || !in_array($action, ['buy', 'sell']) || $resourceId <= 0 || $quantity <= 0) {
    header("Location: trade.php?msg=invalid");
    exit;
}

// Get the customs house for this faction
$customsName = $faction === 'blue' ? 'Customs House West' : 'Customs House East';
$stmt = $conn->prepare("SELECT id FROM towns WHERE name = ?");
$stmt->bind_param("s", $customsName);
$stmt->execute();
$customsTown = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customsTown) {
    header("Location: trade.php?msg=no_customs");
    exit;
}
$customsTownId = (int)$customsTown['id'];

// Get resource info + current price
$stmt = $conn->prepare("SELECT id, resource_name, buy_price, sell_price, base_buy_price, base_sell_price FROM world_prices WHERE id = ?");
$stmt->bind_param("i", $resourceId);
$stmt->execute();
$resource = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resource) {
    header("Location: trade.php?msg=invalid_resource");
    exit;
}

// Get faction balance
$stmt = $conn->prepare("SELECT points FROM faction_balance WHERE faction = ?");
$stmt->bind_param("s", $faction);
$stmt->execute();
$balance = $stmt->get_result()->fetch_assoc();
$stmt->close();
$currentPoints = (float)($balance['points'] ?? 0);

if ($action === 'buy') {
    $pricePerUnit = (float)$resource['buy_price'];
    $totalCost = $pricePerUnit * $quantity;

    // Check faction has enough points
    if ($currentPoints < $totalCost) {
        header("Location: trade.php?msg=insufficient_funds&faction=$faction");
        exit;
    }

    // Deduct points
    $stmt = $conn->prepare("UPDATE faction_balance SET points = points - ? WHERE faction = ?");
    $stmt->bind_param("ds", $totalCost, $faction);
    $stmt->execute();
    $stmt->close();

    // Add resource to customs house
    $stmt = $conn->prepare("UPDATE town_resources SET stock = stock + ? WHERE town_id = ? AND resource_id = ?");
    $stmt->bind_param("dii", $quantity, $customsTownId, $resourceId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        // Resource row might not exist for customs house — insert it
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO town_resources (town_id, resource_id, stock) VALUES (?, ?, ?)");
        $stmt->bind_param("iid", $customsTownId, $resourceId, $quantity);
        $stmt->execute();
    }
    $stmt->close();

    // Update volume tracking
    $stmt = $conn->prepare("UPDATE price_volume SET total_bought = total_bought + ? WHERE resource_id = ?");
    $stmt->bind_param("di", $quantity, $resourceId);
    $stmt->execute();
    $stmt->close();

} elseif ($action === 'sell') {
    $pricePerUnit = (float)$resource['sell_price'];
    $totalCost = $pricePerUnit * $quantity;

    // Check customs house has enough stock
    $stmt = $conn->prepare("SELECT stock FROM town_resources WHERE town_id = ? AND resource_id = ?");
    $stmt->bind_param("ii", $customsTownId, $resourceId);
    $stmt->execute();
    $stockRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inStock = (float)($stockRow['stock'] ?? 0);

    if ($inStock < $quantity) {
        header("Location: trade.php?msg=insufficient_stock&faction=$faction");
        exit;
    }

    // Deduct resource from customs house
    $stmt = $conn->prepare("UPDATE town_resources SET stock = stock - ? WHERE town_id = ? AND resource_id = ?");
    $stmt->bind_param("dii", $quantity, $customsTownId, $resourceId);
    $stmt->execute();
    $stmt->close();

    // Add points to faction
    $stmt = $conn->prepare("UPDATE faction_balance SET points = points + ? WHERE faction = ?");
    $stmt->bind_param("ds", $totalCost, $faction);
    $stmt->execute();
    $stmt->close();

    // Update volume tracking
    $stmt = $conn->prepare("UPDATE price_volume SET total_sold = total_sold + ? WHERE resource_id = ?");
    $stmt->bind_param("di", $quantity, $resourceId);
    $stmt->execute();
    $stmt->close();
}

// Log the trade
$stmt = $conn->prepare("INSERT INTO trade_log (faction, resource_id, action, quantity, price_per_unit, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sisddd", $faction, $resourceId, $action, $quantity, $pricePerUnit, $totalCost);
$stmt->execute();
$stmt->close();

// ── Recalculate prices based on volume ──
// Formula: new_price = base_price × (1.001 ^ (total_bought/100)) × (0.999 ^ (total_sold/100))
$stmt = $conn->prepare("SELECT total_bought, total_sold FROM price_volume WHERE resource_id = ?");
$stmt->bind_param("i", $resourceId);
$stmt->execute();
$vol = $stmt->get_result()->fetch_assoc();
$stmt->close();

$baseBuy = (float)$resource['base_buy_price'];
$baseSell = (float)$resource['base_sell_price'];
$totalBought = (float)($vol['total_bought'] ?? 0);
$totalSold = (float)($vol['total_sold'] ?? 0);

$buyMultiplier = pow(1.001, $totalBought / 100) * pow(0.999, $totalSold / 100);
$newBuy = round($baseBuy * $buyMultiplier, 4);
$newSell = round($baseSell * $buyMultiplier, 4); // same multiplier keeps 5% spread

$stmt = $conn->prepare("UPDATE world_prices SET buy_price = ?, sell_price = ? WHERE id = ?");
$stmt->bind_param("ddi", $newBuy, $newSell, $resourceId);
$stmt->execute();
$stmt->close();

$conn->close();

$actionWord = $action === 'buy' ? 'bought' : 'sold';
header("Location: trade.php?msg=success&detail=" . urlencode("$actionWord {$quantity}t {$resource['resource_name']} for " . number_format($totalCost, 2) . " pts") . "&faction=$faction");
exit;
?>
