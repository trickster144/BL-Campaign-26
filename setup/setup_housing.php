<?php
// setup_housing.php — Creates housing tables and inserts 1000 homes per active town
// Run once via browser, then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// ── Create town_housing table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_housing (
    town_id INT PRIMARY KEY,
    houses INT DEFAULT 1000,
    small_flats INT DEFAULT 0,
    medium_flats INT DEFAULT 0,
    large_flats INT DEFAULT 0,
    FOREIGN KEY (town_id) REFERENCES towns(id)
)");
$messages[] = $r ? "✅ town_housing table created" : "❌ Error: " . $conn->error;

// ── Insert 1000 default homes for all active towns ──
$r = $conn->query("INSERT IGNORE INTO town_housing (town_id, houses)
    SELECT id, 1000 FROM towns WHERE population > 0");
$messages[] = $r ? "✅ Inserted housing for " . $conn->affected_rows . " towns (1000 homes each = 5000 citizens)" : "❌ Error: " . $conn->error;

// ── Create housing_costs table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS housing_costs (
    housing_type VARCHAR(20) NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (housing_type, resource_id),
    FOREIGN KEY (resource_id) REFERENCES world_prices(id)
)");
$messages[] = $r ? "✅ housing_costs table created" : "❌ Error: " . $conn->error;

// ── Populate costs ──
$conn->query("DELETE FROM housing_costs");

// Small flats (+10 homes): 100 Bricks, 10 Wooden boards, 3 Steel
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'small_flats', id, 100 FROM world_prices WHERE resource_name = 'Bricks'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'small_flats', id, 10 FROM world_prices WHERE resource_name = 'Wooden boards'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'small_flats', id, 3 FROM world_prices WHERE resource_name = 'Steel'");

// Medium flats (+50 homes): 450 Bricks, 45 Wooden boards, 13.5 Steel (4.5× small)
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'medium_flats', id, 450 FROM world_prices WHERE resource_name = 'Bricks'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'medium_flats', id, 45 FROM world_prices WHERE resource_name = 'Wooden boards'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'medium_flats', id, 13.5 FROM world_prices WHERE resource_name = 'Steel'");

// Large flats (+100 homes): 700 Prefab panels, 70 Wooden boards, 21 Steel (7× small, prefabs replace bricks)
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'large_flats', id, 700 FROM world_prices WHERE resource_name = 'Prefab panels'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'large_flats', id, 70 FROM world_prices WHERE resource_name = 'Wooden boards'");
$conn->query("INSERT INTO housing_costs (housing_type, resource_id, amount)
    SELECT 'large_flats', id, 21 FROM world_prices WHERE resource_name = 'Steel'");

$messages[] = "✅ Housing costs populated";

// ── Verify costs ──
$res = $conn->query("SELECT hc.housing_type, wp.resource_name, hc.amount
    FROM housing_costs hc
    JOIN world_prices wp ON hc.resource_id = wp.id
    ORDER BY hc.housing_type, wp.resource_name");
$costRows = [];
if ($res) { while ($r = $res->fetch_assoc()) $costRows[] = $r; }

// ── Verify town housing ──
$townCount = 0;
$tcRes = $conn->query("SELECT COUNT(*) as cnt FROM town_housing");
if ($tcRes && $tc = $tcRes->fetch_assoc()) $townCount = (int)$tc['cnt'];

// ── Display ──
echo "<!DOCTYPE html><html><head><title>Setup Housing</title></head>";
echo "<body style='background:#1a1a2e;color:#e0e0e0;font-family:monospace;padding:30px;max-width:800px;margin:0 auto;'>";
echo "<h2>🏠 Setup: Town Housing</h2><hr style='border-color:#333;'>";

foreach ($messages as $msg) echo "<p style='margin:6px 0;'>$msg</p>";

echo "<hr style='border-color:#333;'>";
echo "<h3>Housing Types</h3>";
echo "<table style='border-collapse:collapse;width:100%;margin-bottom:20px;'>";
echo "<tr style='background:#222;'><th style='padding:8px;border:1px solid #444;'>Type</th><th style='padding:8px;border:1px solid #444;'>+Homes</th><th style='padding:8px;border:1px solid #444;'>+Citizens</th></tr>";
echo "<tr><td style='padding:8px;border:1px solid #444;'>House (default)</td><td style='padding:8px;border:1px solid #444;'>1</td><td style='padding:8px;border:1px solid #444;'>5</td></tr>";
echo "<tr><td style='padding:8px;border:1px solid #444;'>Small Flats</td><td style='padding:8px;border:1px solid #444;'>10</td><td style='padding:8px;border:1px solid #444;'>50</td></tr>";
echo "<tr><td style='padding:8px;border:1px solid #444;'>Medium Flats</td><td style='padding:8px;border:1px solid #444;'>50</td><td style='padding:8px;border:1px solid #444;'>250</td></tr>";
echo "<tr><td style='padding:8px;border:1px solid #444;'>Large Flats</td><td style='padding:8px;border:1px solid #444;'>100</td><td style='padding:8px;border:1px solid #444;'>500</td></tr>";
echo "</table>";

echo "<h3>Build Costs</h3>";
echo "<table style='border-collapse:collapse;width:100%;'>";
echo "<tr style='background:#222;'><th style='padding:8px;border:1px solid #444;'>Type</th><th style='padding:8px;border:1px solid #444;'>Resource</th><th style='padding:8px;border:1px solid #444;'>Amount (tonnes)</th></tr>";
foreach ($costRows as $cr) {
    echo "<tr><td style='padding:8px;border:1px solid #444;'>{$cr['housing_type']}</td><td style='padding:8px;border:1px solid #444;'>{$cr['resource_name']}</td><td style='padding:8px;border:1px solid #444;'>{$cr['amount']}</td></tr>";
}
echo "</table>";

echo "<p style='margin-top:20px;'><strong>Towns with housing:</strong> $townCount (each starts with 1000 homes = 5000 citizen capacity)</p>";
echo "<p><a href='" . BASE_URL . "towns.php' style='color:#4fc3f7;font-size:1.1em;'>→ View Towns</a></p>";
echo "</body></html>";
$conn->close();
?>
