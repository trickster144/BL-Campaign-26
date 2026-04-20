<?php
// setup_vehicles.php — Creates vehicle system tables
// Run once via browser, then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// ── vehicle_types: admin-defined vehicle templates ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicle_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category ENUM('civilian','military') NOT NULL DEFAULT 'civilian',
    vehicle_class ENUM('cargo','passenger') NOT NULL DEFAULT 'cargo',
    cargo_resource_id INT DEFAULT NULL COMMENT 'Which resource this vehicle can carry (NULL = any/passenger)',
    max_speed_kmh INT NOT NULL DEFAULT 60,
    fuel_capacity DECIMAL(10,2) NOT NULL DEFAULT 100 COMMENT 'Litres of fuel tank',
    fuel_per_km DECIMAL(10,4) NOT NULL DEFAULT 0.5 COMMENT 'Fuel consumed per km',
    max_capacity DECIMAL(10,2) NOT NULL DEFAULT 10 COMMENT 'Tonnes cargo or passenger count',
    points_price DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Price to buy at customs in points',
    build_time_ticks INT NOT NULL DEFAULT 12 COMMENT 'Ticks to build at factory (12 = 1 hour)',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_class (vehicle_class)
)");
$messages[] = $r ? "✅ vehicle_types table ready" : "❌ " . $conn->error;

// ── vehicle_build_costs: resources needed to build at factory ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicle_build_costs (
    vehicle_type_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (vehicle_type_id, resource_id)
)");
$messages[] = $r ? "✅ vehicle_build_costs table ready" : "❌ " . $conn->error;

// ── vehicles: actual vehicle instances in the game world ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_type_id INT NOT NULL,
    town_id INT DEFAULT NULL COMMENT 'Current town (NULL if in transit)',
    faction VARCHAR(10) NOT NULL,
    status ENUM('idle','in_transit','building','loading') NOT NULL DEFAULT 'idle',
    build_started_at DATETIME DEFAULT NULL,
    build_complete_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_town (town_id),
    INDEX idx_faction (faction),
    INDEX idx_status (status)
)");
$messages[] = $r ? "✅ vehicles table ready" : "❌ " . $conn->error;

// ── vehicle_trips: active journeys between towns ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicle_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    from_town_id INT NOT NULL,
    to_town_id INT NOT NULL,
    cargo_resource_id INT DEFAULT NULL,
    cargo_amount DECIMAL(10,2) DEFAULT 0,
    departed_at DATETIME NOT NULL,
    eta_at DATETIME NOT NULL,
    distance_km DECIMAL(10,2) NOT NULL,
    speed_kmh DECIMAL(10,2) NOT NULL,
    return_empty TINYINT(1) NOT NULL DEFAULT 0,
    fuel_used DECIMAL(10,4) NOT NULL DEFAULT 0,
    arrived TINYINT(1) NOT NULL DEFAULT 0,
    arrived_at DATETIME DEFAULT NULL,
    INDEX idx_vehicle (vehicle_id),
    INDEX idx_eta (eta_at),
    INDEX idx_arrived (arrived)
)");
$messages[] = $r ? "✅ vehicle_trips table ready" : "❌ " . $conn->error;

// Ensure arrived_at column exists (for pre-existing tables)
$check = $conn->query("SHOW COLUMNS FROM vehicle_trips LIKE 'arrived_at'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE vehicle_trips ADD COLUMN arrived_at DATETIME DEFAULT NULL AFTER arrived");
}

// ── vehicle_trip_cargo: per-resource cargo for multi-resource loads (Warehouse class) ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS vehicle_trip_cargo (
    trip_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (trip_id, resource_id)
)");
$messages[] = $r ? "✅ vehicle_trip_cargo table ready" : "❌ " . $conn->error;

// ── Migration: Add cargo_class column to vehicle_types ──
$check = $conn->query("SHOW COLUMNS FROM vehicle_types LIKE 'cargo_class'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE vehicle_types ADD COLUMN cargo_class VARCHAR(50) DEFAULT NULL COMMENT 'Resource class matching world_prices.resource_type (NULL = passenger)' AFTER cargo_resource_id");
    $messages[] = "✅ Added cargo_class column to vehicle_types";
} else {
    // Widen column if it exists but is too narrow
    $conn->query("ALTER TABLE vehicle_types MODIFY cargo_class VARCHAR(50) DEFAULT NULL");
    $messages[] = "ℹ️ cargo_class column already exists on vehicle_types (ensured VARCHAR(50))";
}

// Check if migration from cargo_resource_id is needed
$needsMigration = false;
$migCheck = $conn->query("SELECT COUNT(*) as cnt FROM vehicle_types WHERE cargo_class IS NULL AND cargo_resource_id IS NOT NULL");
if ($migCheck) { $row = $migCheck->fetch_assoc(); $needsMigration = (int)$row['cnt'] > 0; }

if ($needsMigration) {
    // Discover actual resource_type values from DB
    $typeMap = [];
    $tmRes = $conn->query("SELECT DISTINCT resource_type FROM world_prices WHERE resource_type IS NOT NULL");
    if ($tmRes) { while ($tm = $tmRes->fetch_assoc()) $typeMap[] = $tm['resource_type']; }
    $messages[] = "ℹ️ Found resource types in DB: " . implode(', ', $typeMap);

    // Migrate existing cargo_resource_id → cargo_class using world_prices.resource_type
    $conn->query("UPDATE vehicle_types vt
        JOIN world_prices wp ON vt.cargo_resource_id = wp.id
        SET vt.cargo_class = wp.resource_type
        WHERE vt.cargo_resource_id IS NOT NULL AND vt.cargo_class IS NULL");

    // Determine the "open storage" and "warehouse" type names from the DB
    $openStorageType = null;
    $warehouseType = null;
    foreach ($typeMap as $t) {
        $tl = strtolower($t);
        if (str_contains($tl, 'open') || str_contains($tl, 'storage')) $openStorageType = $t;
        if (str_contains($tl, 'warehouse')) $warehouseType = $t;
    }

    // Flatbed Truck (null cargo_resource_id, cargo class) → open storage hauler
    if ($openStorageType) {
        $conn->query("UPDATE vehicle_types SET cargo_class = '" . $conn->real_escape_string($openStorageType) . "' WHERE vehicle_class = 'cargo' AND cargo_resource_id IS NULL AND cargo_class IS NULL AND category = 'civilian'");
    }

    // Military Transport (null cargo_resource_id) → warehouse logistics
    if ($warehouseType) {
        $conn->query("UPDATE vehicle_types SET cargo_class = '" . $conn->real_escape_string($warehouseType) . "' WHERE vehicle_class = 'cargo' AND cargo_resource_id IS NULL AND cargo_class IS NULL AND category = 'military'");
    }

    $messages[] = "✅ Migrated existing vehicle types to cargo_class system";
} else {
    $emptyCheck = $conn->query("SELECT COUNT(*) as cnt FROM vehicle_types WHERE cargo_class IS NOT NULL");
    $ecCnt = $emptyCheck ? $emptyCheck->fetch_assoc()['cnt'] : 0;
    if ($ecCnt > 0) {
        $messages[] = "ℹ️ Vehicle types already have cargo_class values ($ecCnt types)";
    } else {
        $messages[] = "ℹ️ No vehicle types need cargo_class migration";
    }
}

// ── roads: road segments between towns ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS roads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id_1 INT NOT NULL,
    town_id_2 INT NOT NULL,
    road_type ENUM('mud','gravel','asphalt','dual') NOT NULL DEFAULT 'mud',
    speed_limit INT NOT NULL DEFAULT 30,
    UNIQUE KEY uk_towns (town_id_1, town_id_2),
    INDEX idx_town1 (town_id_1),
    INDEX idx_town2 (town_id_2)
)");
$messages[] = $r ? "✅ roads table ready" : "❌ " . $conn->error;

// ── Initialize all town connections as mud roads ──
$existing = $conn->query("SELECT COUNT(*) as cnt FROM roads");
$cnt = $existing ? $existing->fetch_assoc()['cnt'] : 0;
if ($cnt == 0) {
    // Create roads from town_distances (both directions already exist, only insert one)
    $conn->query("INSERT IGNORE INTO roads (town_id_1, town_id_2, road_type, speed_limit)
        SELECT town_id_1, town_id_2, 'mud', 30 FROM town_distances WHERE town_id_1 < town_id_2");
    $roadCount = $conn->affected_rows;
    $messages[] = "✅ Initialized $roadCount road segments as mud roads (30 km/h)";
} else {
    $messages[] = "ℹ️ Roads already exist ($cnt segments)";
}

// ── road_upgrade_costs: resource costs to upgrade road type ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS road_upgrade_costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    road_type_from ENUM('mud','gravel','asphalt','dual') NOT NULL,
    road_type_to ENUM('mud','gravel','asphalt','dual') NOT NULL,
    resource_id INT NOT NULL,
    amount_per_km DECIMAL(10,2) NOT NULL COMMENT 'Resource amount per km of road',
    UNIQUE KEY uk_upgrade (road_type_from, road_type_to, resource_id)
)");
$messages[] = $r ? "✅ road_upgrade_costs table ready" : "❌ " . $conn->error;

// Insert default road upgrade costs (per km of distance)
$conn->query("DELETE FROM road_upgrade_costs");
// Get resource IDs
$resIds = [];
$res = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $res->fetch_assoc()) $resIds[$r['resource_name']] = (int)$r['id'];

$upgrades = [
    // mud → gravel: 5t Stone/km, 1t Wood/km
    ['mud', 'gravel', 'Stone', 5], ['mud', 'gravel', 'Wood', 1],
    // gravel → asphalt: 10t Stone/km, 5t Bricks/km, 2t Steel/km
    ['gravel', 'asphalt', 'Stone', 10], ['gravel', 'asphalt', 'Bricks', 5], ['gravel', 'asphalt', 'Steel', 2],
    // asphalt → dual: 15t Stone/km, 10t Bricks/km, 5t Steel/km, 2t Prefab panels/km
    ['asphalt', 'dual', 'Stone', 15], ['asphalt', 'dual', 'Bricks', 10],
    ['asphalt', 'dual', 'Steel', 5], ['asphalt', 'dual', 'Prefab panels', 2],
];

$stmt = $conn->prepare("INSERT INTO road_upgrade_costs (road_type_from, road_type_to, resource_id, amount_per_km) VALUES (?, ?, ?, ?)");
foreach ($upgrades as $u) {
    $rid = $resIds[$u[2]] ?? 0;
    if ($rid > 0) {
        $stmt->bind_param("ssid", $u[0], $u[1], $rid, $u[3]);
        $stmt->execute();
    }
}
$stmt->close();
$messages[] = "✅ Road upgrade costs set (per km of distance)";

// ── Insert some starter vehicle types ──
$vtCheck = $conn->query("SELECT COUNT(*) as cnt FROM vehicle_types");
$vtCnt = $vtCheck ? $vtCheck->fetch_assoc()['cnt'] : 0;
if ($vtCnt == 0) {
    // Discover actual resource_type names from DB for cargo_class values
    $rtMap = [];
    $rtRes = $conn->query("SELECT DISTINCT resource_type FROM world_prices WHERE resource_type IS NOT NULL");
    if ($rtRes) { while ($rt = $rtRes->fetch_assoc()) $rtMap[strtolower($rt['resource_type'])] = $rt['resource_type']; }

    // Find the actual type names
    $classAggregates = null; $classOpenStorage = null; $classWarehouse = null; $classLiquids = null;
    foreach ($rtMap as $lower => $actual) {
        if (str_contains($lower, 'aggregat')) $classAggregates = $actual;
        elseif (str_contains($lower, 'open') || str_contains($lower, 'storage')) $classOpenStorage = $actual;
        elseif (str_contains($lower, 'warehouse')) $classWarehouse = $actual;
        elseif (str_contains($lower, 'liquid')) $classLiquids = $actual;
    }

    $starters = [
        // name, category, class, cargo_class, speed, fuel_cap, fuel_per_km, capacity, points_price, build_ticks
        ['Flatbed Truck', 'civilian', 'cargo', $classOpenStorage, 60, 200, 0.5, 10, 500, 12],
        ['Tanker Truck', 'civilian', 'cargo', $classLiquids, 50, 250, 0.6, 15, 800, 18],
        ['Fuel Tanker', 'civilian', 'cargo', $classLiquids, 50, 250, 0.6, 15, 800, 18],
        ['Lumber Hauler', 'civilian', 'cargo', $classOpenStorage, 55, 200, 0.5, 20, 600, 12],
        ['Ore Hauler', 'civilian', 'cargo', $classAggregates, 45, 300, 0.7, 25, 900, 24],
        ['Civilian Bus', 'civilian', 'passenger', null, 70, 150, 0.3, 50, 400, 12],
        ['Military Transport', 'military', 'cargo', $classWarehouse, 80, 400, 1.0, 15, 2000, 36],
        ['Troop Carrier', 'military', 'passenger', null, 75, 350, 0.8, 30, 1500, 24],
    ];

    $stmt = $conn->prepare("INSERT INTO vehicle_types (name, category, vehicle_class, cargo_class, max_speed_kmh, fuel_capacity, fuel_per_km, max_capacity, points_price, build_time_ticks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($starters as $v) {
        $stmt->bind_param("ssssiidddi", $v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6], $v[7], $v[8], $v[9]);
        $stmt->execute();
    }
    $stmt->close();
    $messages[] = "✅ Inserted " . count($starters) . " starter vehicle types (cargo_class system)";
    $messages[] = "ℹ️ Classes used: Aggregates=" . ($classAggregates ?? 'NULL') . ", Open=" . ($classOpenStorage ?? 'NULL') . ", Warehouse=" . ($classWarehouse ?? 'NULL') . ", Liquids=" . ($classLiquids ?? 'NULL');
} else {
    $messages[] = "ℹ️ Vehicle types already exist ($vtCnt types)";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Vehicles</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}
table{border-collapse:collapse;width:100%;margin-top:15px}th,td{border:1px solid #333;padding:6px 10px;text-align:left}
th{background:#0f3460}</style></head><body>
<h2>🚛 Vehicle System Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>

<h3 style="color:#e94560;">Road Speed Limits</h3>
<table>
<tr><th>Type</th><th>Speed Limit</th><th>Upgrade Cost (per km)</th></tr>
<tr><td>🟤 Mud</td><td>30 km/h</td><td>Default (free)</td></tr>
<tr><td>⬜ Gravel</td><td>60 km/h</td><td>5t Stone + 1t Wood /km</td></tr>
<tr><td>⬛ Asphalt</td><td>90 km/h</td><td>10t Stone + 5t Bricks + 2t Steel /km</td></tr>
<tr><td>🟦 Dual Lane</td><td>130 km/h</td><td>15t Stone + 10t Bricks + 5t Steel + 2t Prefab /km</td></tr>
</table>

<p style="margin-top:20px;"><a href="<?= BASE_URL ?>trade.php">→ Trade Page</a> | <a href="<?= BASE_URL ?>towns.php">→ Towns</a></p>
</body></html>
