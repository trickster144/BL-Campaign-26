<?php
// setup_trains.php — Creates train system tables and starter data
// Run once via browser after setup_vehicles.php
include __DIR__ . "/../files/config.php";

$messages = [];

// ── rail_lines: rail connections between towns ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS rail_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id_1 INT NOT NULL,
    town_id_2 INT NOT NULL,
    rail_type ENUM('basic','electrified') NOT NULL DEFAULT 'basic',
    speed_limit INT NOT NULL DEFAULT 60,
    UNIQUE KEY uk_towns (town_id_1, town_id_2),
    INDEX idx_town1 (town_id_1),
    INDEX idx_town2 (town_id_2)
)");
$messages[] = $r ? "✅ rail_lines table ready" : "❌ " . $conn->error;

// Initialize rail lines between all connected towns
$existing = $conn->query("SELECT COUNT(*) as cnt FROM rail_lines");
$cnt = $existing ? $existing->fetch_assoc()['cnt'] : 0;
if ($cnt == 0) {
    $conn->query("INSERT IGNORE INTO rail_lines (town_id_1, town_id_2, rail_type, speed_limit)
        SELECT town_id_1, town_id_2, 'basic', 60 FROM town_distances WHERE town_id_1 < town_id_2");
    $railCount = $conn->affected_rows;
    $messages[] = "✅ Initialized $railCount rail line segments as basic rail (60 km/h)";
} else {
    $messages[] = "ℹ️ Rail lines already exist ($cnt segments)";
}

// ── rail_upgrade_costs: cost to electrify rail ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS rail_upgrade_costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rail_type_from ENUM('basic','electrified') NOT NULL,
    rail_type_to ENUM('basic','electrified') NOT NULL,
    resource_id INT NOT NULL,
    amount_per_km DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uk_upgrade (rail_type_from, rail_type_to, resource_id)
)");
$messages[] = $r ? "✅ rail_upgrade_costs table ready" : "❌ " . $conn->error;

// Get resource IDs
$resIds = [];
$res = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $res->fetch_assoc()) $resIds[$r['resource_name']] = (int)$r['id'];

// Insert rail upgrade costs
$conn->query("DELETE FROM rail_upgrade_costs");
$railUpgrades = [
    // basic → electrified: 10t Steel/km + 5t Wooden boards/km + 2t Electronic components/km
    ['basic', 'electrified', 'Steel', 10],
    ['basic', 'electrified', 'Wooden boards', 5],
    ['basic', 'electrified', 'Electronic components', 2],
];
$stmt = $conn->prepare("INSERT INTO rail_upgrade_costs (rail_type_from, rail_type_to, resource_id, amount_per_km) VALUES (?, ?, ?, ?)");
foreach ($railUpgrades as $u) {
    $rid = $resIds[$u[2]] ?? 0;
    if ($rid > 0) {
        $stmt->bind_param("ssid", $u[0], $u[1], $rid, $u[3]);
        $stmt->execute();
    }
}
$stmt->close();
$messages[] = "✅ Rail upgrade costs set";

// ── locomotive_types: admin-defined locomotive templates ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS locomotive_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    propulsion ENUM('steam','diesel','electric') NOT NULL DEFAULT 'steam',
    max_speed_kmh INT NOT NULL DEFAULT 40,
    fuel_per_km DECIMAL(10,4) NOT NULL DEFAULT 1.0 COMMENT 'Fuel consumed per km (0 for electric)',
    fuel_resource_id INT DEFAULT NULL COMMENT 'world_prices.id of fuel resource (NULL for electric)',
    max_wagons INT NOT NULL DEFAULT 10,
    points_price DECIMAL(14,2) NOT NULL DEFAULT 1000,
    build_time_ticks INT NOT NULL DEFAULT 24,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_propulsion (propulsion)
)");
$messages[] = $r ? "✅ locomotive_types table ready" : "❌ " . $conn->error;

// ── locomotive_build_costs: resources to build a locomotive ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS locomotive_build_costs (
    locomotive_type_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (locomotive_type_id, resource_id)
)");
$messages[] = $r ? "✅ locomotive_build_costs table ready" : "❌ " . $conn->error;

// ── wagon_types: admin-defined wagon/carriage templates ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS wagon_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    wagon_class ENUM('cargo','passenger') NOT NULL DEFAULT 'cargo',
    cargo_class VARCHAR(50) DEFAULT NULL COMMENT 'Matches world_prices.resource_type (NULL for passenger)',
    max_capacity DECIMAL(10,2) NOT NULL DEFAULT 30 COMMENT 'Tonnes cargo or passenger count',
    points_price DECIMAL(14,2) NOT NULL DEFAULT 200,
    build_time_ticks INT NOT NULL DEFAULT 6,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_class (wagon_class)
)");
$messages[] = $r ? "✅ wagon_types table ready" : "❌ " . $conn->error;

// ── wagon_build_costs: resources to build a wagon ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS wagon_build_costs (
    wagon_type_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (wagon_type_id, resource_id)
)");
$messages[] = $r ? "✅ wagon_build_costs table ready" : "❌ " . $conn->error;

// ── locomotives: actual locomotive instances ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS locomotives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locomotive_type_id INT NOT NULL,
    town_id INT DEFAULT NULL,
    faction VARCHAR(10) NOT NULL,
    status ENUM('idle','in_transit','building') NOT NULL DEFAULT 'idle',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_town (town_id),
    INDEX idx_faction (faction),
    INDEX idx_status (status)
)");
$messages[] = $r ? "✅ locomotives table ready" : "❌ " . $conn->error;

// ── wagons: actual wagon instances ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS wagons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wagon_type_id INT NOT NULL,
    town_id INT DEFAULT NULL,
    faction VARCHAR(10) NOT NULL,
    status ENUM('idle','in_transit','building','in_train') NOT NULL DEFAULT 'idle',
    train_id INT DEFAULT NULL COMMENT 'Which train this wagon is attached to',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_town (town_id),
    INDEX idx_faction (faction),
    INDEX idx_status (status),
    INDEX idx_train (train_id)
)");
$messages[] = $r ? "✅ wagons table ready" : "❌ " . $conn->error;

// ── trains: composed train (1 locomotive + N wagons) ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS trains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) DEFAULT NULL,
    locomotive_id INT NOT NULL,
    town_id INT DEFAULT NULL,
    faction VARCHAR(10) NOT NULL,
    status ENUM('idle','loading','in_transit') NOT NULL DEFAULT 'idle',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_town (town_id),
    INDEX idx_faction (faction),
    INDEX idx_status (status),
    INDEX idx_loco (locomotive_id)
)");
$messages[] = $r ? "✅ trains table ready" : "❌ " . $conn->error;

// ── train_consist: wagons attached to a train with position ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS train_consist (
    train_id INT NOT NULL,
    wagon_id INT NOT NULL,
    position INT NOT NULL DEFAULT 1,
    PRIMARY KEY (train_id, wagon_id),
    INDEX idx_wagon (wagon_id)
)");
$messages[] = $r ? "✅ train_consist table ready" : "❌ " . $conn->error;

// ── train_trips: active train journeys ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS train_trips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    from_town_id INT NOT NULL,
    to_town_id INT NOT NULL,
    departed_at DATETIME NOT NULL,
    eta_at DATETIME NOT NULL,
    distance_km DECIMAL(10,2) NOT NULL,
    speed_kmh DECIMAL(10,2) NOT NULL,
    fuel_used DECIMAL(10,4) NOT NULL DEFAULT 0,
    fuel_resource_id INT DEFAULT NULL,
    return_empty TINYINT(1) NOT NULL DEFAULT 0,
    arrived TINYINT(1) NOT NULL DEFAULT 0,
    arrived_at DATETIME DEFAULT NULL,
    INDEX idx_train (train_id),
    INDEX idx_eta (eta_at),
    INDEX idx_arrived (arrived)
)");
$messages[] = $r ? "✅ train_trips table ready" : "❌ " . $conn->error;

// ── train_trip_cargo: cargo loaded per wagon per trip ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS train_trip_cargo (
    trip_id INT NOT NULL,
    wagon_id INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (trip_id, wagon_id, resource_id)
)");
$messages[] = $r ? "✅ train_trip_cargo table ready" : "❌ " . $conn->error;

// Common resource IDs for build costs
$steelId = $resIds['Steel'] ?? 0;
$ironId = $resIds['Iron'] ?? 0;
$mechId = $resIds['Mechanical components'] ?? 0;
$elecId = $resIds['Electronic components'] ?? 0;
$woodId = $resIds['Wood'] ?? 0;
$boardsId = $resIds['Wooden boards'] ?? 0;
$coalId = $resIds['Coal'] ?? null;
$fuelId = $resIds['Fuel'] ?? null;

// ── Insert starter locomotive types ──
$ltCheck = $conn->query("SELECT COUNT(*) as cnt FROM locomotive_types");
$ltCnt = $ltCheck ? $ltCheck->fetch_assoc()['cnt'] : 0;
if ($ltCnt == 0) {

    $locos = [
        // name, propulsion, speed, fuel_per_km, fuel_resource_id, max_wagons, points_price, build_ticks
        ['Steam Locomotive', 'steam', 40, 2.0, $coalId, 10, 1500, 24],
        ['Heavy Steam Locomotive', 'steam', 35, 3.0, $coalId, 20, 3000, 36],
        ['Diesel Locomotive', 'diesel', 60, 1.0, $fuelId, 15, 5000, 36],
        ['Heavy Diesel Locomotive', 'diesel', 50, 1.5, $fuelId, 30, 8000, 48],
        ['Electric Locomotive', 'electric', 100, 0, null, 20, 12000, 48],
    ];

    foreach ($locos as $l) {
        $n = $conn->real_escape_string($l[0]);
        $p = $conn->real_escape_string($l[1]);
        $fuelRes = ($l[4] === null) ? "NULL" : (int)$l[4];
        $conn->query("INSERT INTO locomotive_types (name, propulsion, max_speed_kmh, fuel_per_km, fuel_resource_id, max_wagons, points_price, build_time_ticks)
            VALUES ('$n', '$p', {$l[2]}, {$l[3]}, $fuelRes, {$l[5]}, {$l[6]}, {$l[7]})");
    }

    // Build costs for locos

    // Get inserted IDs
    $locoIds = [];
    $liRes = $conn->query("SELECT id, name FROM locomotive_types ORDER BY id");
    while ($li = $liRes->fetch_assoc()) $locoIds[$li['name']] = (int)$li['id'];

    $locoCosts = [
        ['Steam Locomotive', $steelId, 20], ['Steam Locomotive', $ironId, 30], ['Steam Locomotive', $mechId, 5],
        ['Heavy Steam Locomotive', $steelId, 40], ['Heavy Steam Locomotive', $ironId, 50], ['Heavy Steam Locomotive', $mechId, 10],
        ['Diesel Locomotive', $steelId, 30], ['Diesel Locomotive', $mechId, 15], ['Diesel Locomotive', $elecId, 5],
        ['Heavy Diesel Locomotive', $steelId, 50], ['Heavy Diesel Locomotive', $mechId, 25], ['Heavy Diesel Locomotive', $elecId, 10],
        ['Electric Locomotive', $steelId, 40], ['Electric Locomotive', $mechId, 20], ['Electric Locomotive', $elecId, 20],
    ];
    $costStmt = $conn->prepare("INSERT INTO locomotive_build_costs (locomotive_type_id, resource_id, amount) VALUES (?, ?, ?)");
    foreach ($locoCosts as $c) {
        $lid = $locoIds[$c[0]] ?? 0;
        if ($lid > 0 && $c[1] > 0) {
            $costStmt->bind_param("iid", $lid, $c[1], $c[2]);
            $costStmt->execute();
        }
    }
    $costStmt->close();
    $messages[] = "✅ Inserted " . count($locos) . " starter locomotive types with build costs";
} else {
    $messages[] = "ℹ️ Locomotive types already exist ($ltCnt types)";
}

// ── Insert starter wagon types ──
$wtCheck = $conn->query("SELECT COUNT(*) as cnt FROM wagon_types");
$wtCnt = $wtCheck ? $wtCheck->fetch_assoc()['cnt'] : 0;
if ($wtCnt == 0) {
    // Discover resource class names from DB
    $rtMap = [];
    $rtRes = $conn->query("SELECT DISTINCT resource_type FROM world_prices WHERE resource_type IS NOT NULL");
    if ($rtRes) { while ($rt = $rtRes->fetch_assoc()) $rtMap[strtolower($rt['resource_type'])] = $rt['resource_type']; }

    $classAggregates = null; $classOpenStorage = null; $classWarehouse = null; $classLiquids = null;
    foreach ($rtMap as $lower => $actual) {
        if (str_contains($lower, 'aggregat')) $classAggregates = $actual;
        elseif (str_contains($lower, 'open') || str_contains($lower, 'storage')) $classOpenStorage = $actual;
        elseif (str_contains($lower, 'warehouse')) $classWarehouse = $actual;
        elseif (str_contains($lower, 'liquid')) $classLiquids = $actual;
    }

    $wagons = [
        // name, wagon_class, cargo_class, max_capacity, points_price, build_ticks
        ['Hopper Wagon', 'cargo', $classAggregates, 30, 200, 6],
        ['Flatcar', 'cargo', $classOpenStorage, 25, 150, 6],
        ['Box Car', 'cargo', $classWarehouse, 20, 250, 8],
        ['Tank Car', 'cargo', $classLiquids, 35, 300, 8],
        ['Passenger Coach', 'passenger', null, 80, 400, 10],
    ];

    $stmt = $conn->prepare("INSERT INTO wagon_types (name, wagon_class, cargo_class, max_capacity, points_price, build_time_ticks) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($wagons as $w) {
        $stmt->bind_param("sssddi", $w[0], $w[1], $w[2], $w[3], $w[4], $w[5]);
        $stmt->execute();
    }
    $stmt->close();

    // Wagon build costs
    $wagonIds = [];
    $wiRes = $conn->query("SELECT id, name FROM wagon_types ORDER BY id");
    while ($wi = $wiRes->fetch_assoc()) $wagonIds[$wi['name']] = (int)$wi['id'];

    $wagonCosts = [
        ['Hopper Wagon', $steelId, 8], ['Hopper Wagon', $ironId, 5],
        ['Flatcar', $steelId, 5], ['Flatcar', $boardsId, 8],
        ['Box Car', $steelId, 6], ['Box Car', $boardsId, 10], ['Box Car', $mechId, 2],
        ['Tank Car', $steelId, 10], ['Tank Car', $mechId, 3],
        ['Passenger Coach', $steelId, 8], ['Passenger Coach', $boardsId, 12], ['Passenger Coach', $mechId, 3],
    ];
    $costStmt = $conn->prepare("INSERT INTO wagon_build_costs (wagon_type_id, resource_id, amount) VALUES (?, ?, ?)");
    foreach ($wagonCosts as $c) {
        $wid = $wagonIds[$c[0]] ?? 0;
        if ($wid > 0 && $c[1] > 0) {
            $costStmt->bind_param("iid", $wid, $c[1], $c[2]);
            $costStmt->execute();
        }
    }
    $costStmt->close();

    $messages[] = "✅ Inserted " . count($wagons) . " starter wagon types with build costs";
    $messages[] = "ℹ️ Classes: Agg=" . ($classAggregates ?? 'NULL') . ", Open=" . ($classOpenStorage ?? 'NULL') . ", WH=" . ($classWarehouse ?? 'NULL') . ", Liq=" . ($classLiquids ?? 'NULL');
} else {
    $messages[] = "ℹ️ Wagon types already exist ($wtCnt types)";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Trains</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}
table{border-collapse:collapse;width:100%;margin-top:15px}th,td{border:1px solid #333;padding:6px 10px;text-align:left}
th{background:#0f3460}</style></head><body>
<h2>🚂 Train System Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>

<h3 style="color:#e94560;">Rail Speed Limits</h3>
<table>
<tr><th>Type</th><th>Speed Limit</th><th>Upgrade Cost (per km)</th></tr>
<tr><td>🟫 Basic Rail</td><td>60 km/h</td><td>Default (free)</td></tr>
<tr><td>⚡ Electrified</td><td>120 km/h</td><td>10t Steel + 5t Boards + 2t Electronics /km</td></tr>
</table>

<h3 style="color:#e94560;">Locomotive Types</h3>
<table>
<tr><th>Name</th><th>Propulsion</th><th>Speed</th><th>Fuel/km</th><th>Max Wagons</th><th>Price</th></tr>
<tr><td>🚂 Steam Locomotive</td><td>Steam (Coal)</td><td>40 km/h</td><td>2.0t Coal</td><td>10</td><td>1,500</td></tr>
<tr><td>🚂 Heavy Steam</td><td>Steam (Coal)</td><td>35 km/h</td><td>3.0t Coal</td><td>20</td><td>3,000</td></tr>
<tr><td>🚆 Diesel Locomotive</td><td>Diesel (Fuel)</td><td>60 km/h</td><td>1.0t Fuel</td><td>15</td><td>5,000</td></tr>
<tr><td>🚆 Heavy Diesel</td><td>Diesel (Fuel)</td><td>50 km/h</td><td>1.5t Fuel</td><td>30</td><td>8,000</td></tr>
<tr><td>🚅 Electric</td><td>Electric</td><td>100 km/h</td><td>Free</td><td>20</td><td>12,000</td></tr>
</table>

<h3 style="color:#e94560;">Wagon Types</h3>
<table>
<tr><th>Name</th><th>Class</th><th>Cargo Type</th><th>Capacity</th><th>Price</th></tr>
<tr><td>Hopper Wagon</td><td>Cargo</td><td>Aggregates</td><td>30t</td><td>200</td></tr>
<tr><td>Flatcar</td><td>Cargo</td><td>Open Storage</td><td>25t</td><td>150</td></tr>
<tr><td>Box Car</td><td>Cargo</td><td>Warehouse</td><td>20t</td><td>250</td></tr>
<tr><td>Tank Car</td><td>Cargo</td><td>Liquids</td><td>35t</td><td>300</td></tr>
<tr><td>Passenger Coach</td><td>Passenger</td><td>—</td><td>80 pax</td><td>400</td></tr>
</table>

<p style="margin-top:20px;"><a href="<?= BASE_URL ?>towns.php">→ Towns</a> | <a href="<?= BASE_URL ?>transit.php">→ Transit</a></p>
</body></html>
