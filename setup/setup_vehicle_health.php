<?php
// setup_vehicle_health.php — Adds vehicle health, workshops, and repair system
// Run once via browser
include __DIR__ . "/../files/config.php";

$messages = [];

// ── Add health column to vehicles ──
$check = $conn->query("SHOW COLUMNS FROM vehicles LIKE 'health'");
if ($check && $check->num_rows === 0) {
    $r = $conn->query("ALTER TABLE vehicles ADD COLUMN health DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER status");
    $messages[] = $r ? "✅ Added health column to vehicles" : "❌ " . $conn->error;
} else {
    $messages[] = "ℹ️ health column already exists on vehicles";
}

// ── Expand vehicle status ENUM to include 'repairing' and 'scrapped' ──
$r = $conn->query("ALTER TABLE vehicles MODIFY COLUMN status ENUM('idle','in_transit','building','loading','repairing','scrapped') NOT NULL DEFAULT 'idle'");
$messages[] = $r ? "✅ Expanded vehicle status ENUM (added repairing, scrapped)" : "❌ " . $conn->error;

// ── Create workshops table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS workshops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    level INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_town (town_id),
    INDEX idx_town (town_id)
)");
$messages[] = $r ? "✅ workshops table ready" : "❌ " . $conn->error;

// ── Create vehicle_repairs table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicle_repairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    town_id INT NOT NULL,
    faction VARCHAR(10) NOT NULL,
    start_health DECIMAL(5,2) NOT NULL,
    target_health DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    cost_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vehicle (vehicle_id),
    INDEX idx_town (town_id)
)");
$messages[] = $r ? "✅ vehicle_repairs table ready" : "❌ " . $conn->error;

// ── Insert workshop upgrade costs ──
$resLookup = [];
$resQ = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $resQ->fetch_assoc()) $resLookup[$r['resource_name']] = (int)$r['id'];

// Workshop costs per level: Steel + Mechanical components + Stone
$workshopCosts = [
    'Steel' => 15,
    'Mechanical components' => 8,
    'Stone' => 20,
];

$insertedCosts = 0;
for ($lvl = 1; $lvl <= 10; $lvl++) {
    foreach ($workshopCosts as $resName => $baseAmount) {
        if (!isset($resLookup[$resName])) continue;
        $rid = $resLookup[$resName];
        $amt = (float)$baseAmount * $lvl;
        $conn->query("INSERT INTO upgrade_costs (building_type, target_level, resource_id, amount)
                      VALUES ('workshop', $lvl, $rid, $amt)
                      ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
        $insertedCosts += $conn->affected_rows;
    }
}
$messages[] = "✅ Inserted/updated $insertedCosts workshop upgrade cost entries (levels 1-10)";

// ── Set all existing vehicles to 100 health ──
$conn->query("UPDATE vehicles SET health = 100.00 WHERE health IS NULL OR health = 0");
$updated = $conn->affected_rows;
if ($updated > 0) {
    $messages[] = "✅ Set $updated existing vehicles to 100% health";
} else {
    $messages[] = "ℹ️ All vehicles already have health set";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Vehicle Health</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}
table{border-collapse:collapse;width:100%;margin-top:15px}th,td{border:1px solid #333;padding:6px 10px;text-align:left}
th{background:#0f3460}</style></head><body>
<h2>🔧 Vehicle Health & Workshop Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>

<h3 style="color:#e94560;">System Overview</h3>
<table>
<tr><th>Feature</th><th>Details</th></tr>
<tr><td>🏥 Vehicle Health</td><td>0-100%, wear-and-tear per trip based on distance & road quality</td></tr>
<tr><td>💀 Scrap Threshold</td><td>Below 10% — vehicle is scrapped and cannot be repaired</td></tr>
<tr><td>🔧 Workshop</td><td>Build in any town. Each level = 1 simultaneous repair slot</td></tr>
<tr><td>⏱️ Repair Speed</td><td>+1% health per tick (5 minutes per percentage point)</td></tr>
<tr><td>💰 Repair Cost</td><td>0.85 × vehicle points price × (repair% / 100) — deducted from faction balance</td></tr>
</table>

<h3 style="color:#e94560;margin-top:20px;">Wear & Tear (damage per 100km)</h3>
<table>
<tr><th>Road Type</th><th>Damage per 100km</th><th>Trips before repair (100km)</th></tr>
<tr><td>🟤 Mud</td><td>2.0%</td><td>~45 trips</td></tr>
<tr><td>⬜ Gravel</td><td>1.2%</td><td>~75 trips</td></tr>
<tr><td>⬛ Asphalt</td><td>0.6%</td><td>~150 trips</td></tr>
<tr><td>🟦 Dual Lane</td><td>0.3%</td><td>~300 trips</td></tr>
</table>

<h3 style="color:#e94560;margin-top:20px;">Workshop Build Costs (per level)</h3>
<table>
<tr><th>Level</th><th>Repair Slots</th><th>Steel</th><th>Mech. Components</th><th>Stone</th></tr>
<?php for ($l = 1; $l <= 5; $l++): ?>
<tr>
    <td><?= $l ?></td>
    <td><?= $l ?></td>
    <td><?= 15 * $l ?>t</td>
    <td><?= 8 * $l ?>t</td>
    <td><?= 20 * $l ?>t</td>
</tr>
<?php endfor; ?>
</table>

<p style="margin-top:20px;"><a href="<?= BASE_URL ?>towns.php">→ Towns</a></p>
</body></html>
