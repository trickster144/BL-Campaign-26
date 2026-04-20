<?php
// setup_trading.php — Creates trading tables: faction_balance, trade_log, price_volume
// Also adds base_buy_price / base_sell_price columns to world_prices for fluctuation baseline
// Run once via browser, then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// ── faction_balance: global points per team ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS faction_balance (
    faction VARCHAR(10) PRIMARY KEY,
    points DECIMAL(14,2) NOT NULL DEFAULT 0
)");
$messages[] = $r ? "✅ faction_balance table ready" : "❌ " . $conn->error;

$conn->query("INSERT IGNORE INTO faction_balance (faction, points) VALUES ('blue', 50000), ('red', 50000)");
$messages[] = "✅ Starting balance: 50,000 points per faction";

// ── trade_log: history of all transactions ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS trade_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faction VARCHAR(10) NOT NULL,
    resource_id INT NOT NULL,
    action ENUM('buy','sell') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    price_per_unit DECIMAL(14,4) NOT NULL,
    total_cost DECIMAL(14,4) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_faction (faction),
    INDEX idx_resource (resource_id),
    INDEX idx_time (created_at)
)");
$messages[] = $r ? "✅ trade_log table ready" : "❌ " . $conn->error;

// ── price_volume: cumulative buy/sell volume per resource for price fluctuation ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS price_volume (
    resource_id INT PRIMARY KEY,
    total_bought DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_sold DECIMAL(14,2) NOT NULL DEFAULT 0
)");
$messages[] = $r ? "✅ price_volume table ready" : "❌ " . $conn->error;

// Initialize price_volume for all resources
$conn->query("INSERT IGNORE INTO price_volume (resource_id) SELECT id FROM world_prices");
$messages[] = "✅ price_volume initialized for all resources";

// ── Add base price columns to world_prices (for fluctuation reference) ──
$cols = $conn->query("SHOW COLUMNS FROM world_prices LIKE 'base_buy_price'");
if ($cols && $cols->num_rows === 0) {
    $conn->query("ALTER TABLE world_prices ADD COLUMN base_buy_price DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER sell_price");
    $conn->query("ALTER TABLE world_prices ADD COLUMN base_sell_price DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER base_buy_price");
    $conn->query("UPDATE world_prices SET base_buy_price = buy_price, base_sell_price = sell_price");
    $messages[] = "✅ Added base_buy_price/base_sell_price columns (snapshot of current prices)";
} else {
    $messages[] = "ℹ️ base price columns already exist";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Trading</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}</style></head><body>
<h2>🏪 Trading System Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>
<p><a href="<?= BASE_URL ?>trade.php">→ Open Trade Page</a></p>
</body></html>
