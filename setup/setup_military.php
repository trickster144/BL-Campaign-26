<?php
// setup/setup_military.php — Creates military system tables
// Run once via browser to initialise the military system
include __DIR__ . "/../files/config.php";

$messages = [];

// ── weapon_types: admin-defined weapon templates ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS weapon_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    attack_stat INT NOT NULL DEFAULT 10,
    defense_stat INT NOT NULL DEFAULT 5,
    build_time_ticks INT NOT NULL DEFAULT 6 COMMENT '6 ticks = 30 min',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$messages[] = $r ? "✅ weapon_types table ready" : "❌ " . $conn->error;

// ── weapon_build_costs: resources needed to produce a weapon ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS weapon_build_costs (
    weapon_type_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (weapon_type_id, resource_id)
)");
$messages[] = $r ? "✅ weapon_build_costs table ready" : "❌ " . $conn->error;

// ── town_barracks: barracks building per town ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_barracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    level INT NOT NULL DEFAULT 1,
    workers_assigned INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_town (town_id)
)");
$messages[] = $r ? "✅ town_barracks table ready" : "❌ " . $conn->error;

// ── town_munitions_factory: munitions factory per town ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_munitions_factory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    level INT NOT NULL DEFAULT 1,
    workers_assigned INT NOT NULL DEFAULT 0,
    producing_weapon_type_id INT DEFAULT NULL COMMENT 'Which weapon type this factory is currently producing (NULL=idle)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_town (town_id)
)");
$messages[] = $r ? "✅ town_munitions_factory table ready" : "❌ " . $conn->error;

// Add producing_weapon_type_id column if not present (for existing installs)
$colCheck = $conn->query("SHOW COLUMNS FROM town_munitions_factory LIKE 'producing_weapon_type_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE town_munitions_factory ADD COLUMN producing_weapon_type_id INT DEFAULT NULL COMMENT 'Which weapon type this factory is currently producing (NULL=idle)' AFTER workers_assigned");
    $messages[] = "✅ Added producing_weapon_type_id column to town_munitions_factory";
}

// ── Migration: Add transport_type and vehicle_id to troop_movements ──
$colCheck2 = $conn->query("SHOW COLUMNS FROM troop_movements LIKE 'transport_type'");
if ($colCheck2 && $colCheck2->num_rows === 0) {
    $conn->query("ALTER TABLE troop_movements ADD COLUMN transport_type ENUM('walk','vehicle') NOT NULL DEFAULT 'walk' AFTER is_attack");
    $conn->query("ALTER TABLE troop_movements ADD COLUMN vehicle_id INT DEFAULT NULL AFTER transport_type");
    $messages[] = "✅ Added transport_type and vehicle_id columns to troop_movements";
} else {
    $messages[] = "ℹ️ transport_type column already exists on troop_movements";
}

// ── town_troops: soldiers garrisoned at a town ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_troops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    faction VARCHAR(10) NOT NULL,
    weapon_type_id INT DEFAULT NULL COMMENT 'NULL = unarmed',
    quantity INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_town_weapon (town_id, faction, weapon_type_id),
    INDEX idx_faction (faction)
)");
$messages[] = $r ? "✅ town_troops table ready" : "❌ " . $conn->error;

// ── town_weapons_stock: weapon inventory at a town ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_weapons_stock (
    town_id INT NOT NULL,
    weapon_type_id INT NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    PRIMARY KEY (town_id, weapon_type_id)
)");
$messages[] = $r ? "✅ town_weapons_stock table ready" : "❌ " . $conn->error;

// ── troop_movements: troops in transit between towns ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS troop_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faction VARCHAR(10) NOT NULL,
    from_town_id INT NOT NULL,
    to_town_id INT NOT NULL,
    weapon_type_id INT DEFAULT NULL,
    quantity INT NOT NULL,
    departed_at DATETIME NOT NULL,
    eta_at DATETIME NOT NULL,
    distance_km DECIMAL(10,2) NOT NULL DEFAULT 0,
    speed_kmh DECIMAL(10,2) NOT NULL DEFAULT 40,
    is_attack TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = attacking enemy town',
    arrived TINYINT(1) NOT NULL DEFAULT 0,
    arrived_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_faction (faction),
    INDEX idx_eta (eta_at),
    INDEX idx_arrived (arrived)
)");
$messages[] = $r ? "✅ troop_movements table ready" : "❌ " . $conn->error;

// ── combat_log: battle history ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS combat_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    town_name VARCHAR(100) DEFAULT NULL,
    attacker_faction VARCHAR(10) NOT NULL,
    defender_faction VARCHAR(10) NOT NULL,
    attacker_troops INT NOT NULL DEFAULT 0,
    defender_troops INT NOT NULL DEFAULT 0,
    attacker_attack_total INT NOT NULL DEFAULT 0,
    attacker_defense_total INT NOT NULL DEFAULT 0,
    defender_attack_total INT NOT NULL DEFAULT 0,
    defender_defense_total INT NOT NULL DEFAULT 0,
    attacker_losses INT NOT NULL DEFAULT 0,
    defender_losses INT NOT NULL DEFAULT 0,
    result ENUM('attacker_won','defender_won','draw') NOT NULL DEFAULT 'draw',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_town (town_id),
    INDEX idx_time (created_at)
)");
$messages[] = $r ? "✅ combat_log table ready" : "❌ " . $conn->error;

// ── barracks_upgrade_costs: resource costs to upgrade barracks ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS barracks_upgrade_costs (
    target_level INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (target_level, resource_id)
)");
$messages[] = $r ? "✅ barracks_upgrade_costs table ready" : "❌ " . $conn->error;

// ── munitions_upgrade_costs: resource costs to upgrade munitions factory ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS munitions_upgrade_costs (
    target_level INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (target_level, resource_id)
)");
$messages[] = $r ? "✅ munitions_upgrade_costs table ready" : "❌ " . $conn->error;

// ── recruit_costs: resources to recruit one soldier ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS recruit_costs (
    resource_id INT NOT NULL PRIMARY KEY,
    amount DECIMAL(10,2) NOT NULL
)");
$messages[] = $r ? "✅ recruit_costs table ready" : "❌ " . $conn->error;

// ── Seed data ──

// Get resource IDs
$resIds = [];
$res = $conn->query("SELECT id, resource_name FROM world_prices");
while ($row = $res->fetch_assoc()) $resIds[$row['resource_name']] = (int)$row['id'];

// Barracks upgrade costs (per level, scales: amount × level)
$conn->query("DELETE FROM barracks_upgrade_costs");
$bCosts = [
    ['Bricks', 50], ['Steel', 20], ['Wood', 30],
];
$stmt = $conn->prepare("INSERT INTO barracks_upgrade_costs (target_level, resource_id, amount) VALUES (?, ?, ?)");
for ($lvl = 2; $lvl <= 10; $lvl++) {
    foreach ($bCosts as $bc) {
        $rid = $resIds[$bc[0]] ?? 0;
        if ($rid > 0) {
            $amt = $bc[1] * $lvl;
            $stmt->bind_param("iid", $lvl, $rid, $amt);
            $stmt->execute();
        }
    }
}
$stmt->close();
$messages[] = "✅ Barracks upgrade costs seeded (levels 2-10)";

// Munitions factory upgrade costs (per level)
$conn->query("DELETE FROM munitions_upgrade_costs");
$mCosts = [
    ['Steel', 40], ['Bricks', 30], ['Mechanical components', 10],
];
$stmt = $conn->prepare("INSERT INTO munitions_upgrade_costs (target_level, resource_id, amount) VALUES (?, ?, ?)");
for ($lvl = 2; $lvl <= 10; $lvl++) {
    foreach ($mCosts as $mc) {
        $rid = $resIds[$mc[0]] ?? 0;
        if ($rid > 0) {
            $amt = $mc[1] * $lvl;
            $stmt->bind_param("iid", $lvl, $rid, $amt);
            $stmt->execute();
        }
    }
}
$stmt->close();
$messages[] = "✅ Munitions factory upgrade costs seeded (levels 2-10)";

// Recruit costs: 1 soldier = some food/resources
$conn->query("DELETE FROM recruit_costs");
$recruitRes = [
    ['Steel', 2], ['Wood', 3],
];
$stmt = $conn->prepare("INSERT INTO recruit_costs (resource_id, amount) VALUES (?, ?)");
foreach ($recruitRes as $rc) {
    $rid = $resIds[$rc[0]] ?? 0;
    if ($rid > 0) {
        $stmt->bind_param("id", $rid, $rc[1]);
        $stmt->execute();
    }
}
$stmt->close();
$messages[] = "✅ Recruit costs seeded (per soldier)";

// Seed some starter weapon types
$wtCheck = $conn->query("SELECT COUNT(*) as cnt FROM weapon_types");
$wtCnt = $wtCheck ? $wtCheck->fetch_assoc()['cnt'] : 0;
if ($wtCnt == 0) {
    $weapons = [
        // name, attack, defense, build_ticks
        ['Rifle', 15, 5, 3],
        ['Machine Gun', 25, 3, 6],
        ['Mortar', 35, 2, 9],
        ['Shield & Pistol', 8, 20, 4],
        ['Anti-Tank Launcher', 50, 1, 12],
        ['Body Armour Kit', 5, 30, 6],
    ];
    $stmt = $conn->prepare("INSERT INTO weapon_types (name, attack_stat, defense_stat, build_time_ticks) VALUES (?, ?, ?, ?)");
    foreach ($weapons as $w) {
        $stmt->bind_param("siii", $w[0], $w[1], $w[2], $w[3]);
        $stmt->execute();
        $wid = $stmt->insert_id;

        // Default build costs for each weapon
        $costStmt = $conn->prepare("INSERT INTO weapon_build_costs (weapon_type_id, resource_id, amount) VALUES (?, ?, ?)");
        $steelId = $resIds['Steel'] ?? 0;
        $mechId = $resIds['Mechanical components'] ?? 0;
        $exploId = $resIds['Explosives'] ?? 0;

        if ($steelId > 0) {
            $amt = round($w[1] * 0.2 + $w[2] * 0.1, 2);
            $costStmt->bind_param("iid", $wid, $steelId, $amt);
            $costStmt->execute();
        }
        if ($mechId > 0) {
            $amt = round(($w[1] + $w[2]) * 0.05, 2);
            $costStmt->bind_param("iid", $wid, $mechId, $amt);
            $costStmt->execute();
        }
        if ($exploId > 0 && $w[1] >= 25) {
            $amt = round($w[1] * 0.1, 2);
            $costStmt->bind_param("iid", $wid, $exploId, $amt);
            $costStmt->execute();
        }
        $costStmt->close();
    }
    $stmt->close();
    $messages[] = "✅ Inserted " . count($weapons) . " starter weapon types with build costs";
} else {
    $messages[] = "ℹ️ Weapon types already exist ($wtCnt types)";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Military</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#e94560}
table{border-collapse:collapse;width:100%;margin-top:15px}th,td{border:1px solid #333;padding:6px 10px;text-align:left}
th{background:#0f3460}</style></head><body>
<h2>⚔️ Military System Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>

<h3 style="color:#e94560;">Military Buildings</h3>
<table>
<tr><th>Building</th><th>Function</th><th>Workers/Level</th><th>Capacity/Level</th></tr>
<tr><td>🏰 Barracks</td><td>Recruit soldiers</td><td>50 workers</td><td>100 troops garrison</td></tr>
<tr><td>🏭 Munitions Factory</td><td>Produce weapons</td><td>100 workers</td><td>Depends on weapon type</td></tr>
</table>

<h3 style="color:#e94560;">Starter Weapon Types</h3>
<table>
<tr><th>Weapon</th><th>Attack</th><th>Defense</th><th>Build Time</th></tr>
<tr><td>🔫 Rifle</td><td>15</td><td>5</td><td>3 ticks (15 min)</td></tr>
<tr><td>🔫 Machine Gun</td><td>25</td><td>3</td><td>6 ticks (30 min)</td></tr>
<tr><td>💣 Mortar</td><td>35</td><td>2</td><td>9 ticks (45 min)</td></tr>
<tr><td>🛡️ Shield & Pistol</td><td>8</td><td>20</td><td>4 ticks (20 min)</td></tr>
<tr><td>🚀 Anti-Tank Launcher</td><td>50</td><td>1</td><td>12 ticks (1 hr)</td></tr>
<tr><td>🛡️ Body Armour Kit</td><td>5</td><td>30</td><td>6 ticks (30 min)</td></tr>
</table>

<h3 style="color:#e94560;">Combat System</h3>
<table>
<tr><th>Mechanic</th><th>Detail</th></tr>
<tr><td>Troop Speed (Walk)</td><td>2 km/h (on foot)</td></tr>
<tr><td>Troop Speed (Vehicle)</td><td>Vehicle max speed (mounted in military vehicle)</td></tr>
<tr><td>Unarmed Troops</td><td>Attack: 3, Defense: 2</td></tr>
<tr><td>Attack Power</td><td>Sum of all troops × (weapon attack_stat or 3)</td></tr>
<tr><td>Defense Power</td><td>Sum of all troops × (weapon defense_stat or 2)</td></tr>
<tr><td>Combat</td><td>Attacker attack vs Defender defense → losses proportional to ratio</td></tr>
<tr><td>Garrison Bonus</td><td>Defenders get +20% defense (fortification)</td></tr>
</table>

<p style="margin-top:20px;"><a href="<?= BASE_URL ?>military.php">→ Military Page</a> | <a href="<?= BASE_URL ?>towns.php">→ Towns</a></p>
</body></html>
