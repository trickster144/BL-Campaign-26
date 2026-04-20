<?php
// Run this file ONCE after setup_production.php to create power network tables, then delete it.
include __DIR__ . "/../files/config.php";

// --- Migration: remove power_available if it exists on town_production ---
$cols = $conn->query("SHOW COLUMNS FROM town_production LIKE 'power_available'");
if ($cols && $cols->num_rows > 0) {
    $conn->query("ALTER TABLE town_production DROP COLUMN power_available");
    echo "Removed power_available column from town_production.<br>";
}

// --- Rebuild upgrade_costs with building_type ---
$conn->query("DROP TABLE IF EXISTS upgrade_costs");
$conn->query("CREATE TABLE upgrade_costs (
    building_type VARCHAR(50) NOT NULL,
    target_level INT NOT NULL,
    resource_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (building_type, target_level, resource_id)
)");
echo "upgrade_costs table created (with building_type).<br>";

// --- Power station types reference ---
$conn->query("DROP TABLE IF EXISTS power_station_types");
$conn->query("CREATE TABLE power_station_types (
    fuel_type VARCHAR(20) PRIMARY KEY,
    fuel_resource_name VARCHAR(100) NOT NULL,
    fuel_per_mw_hr DECIMAL(10,4) NOT NULL,
    display_name VARCHAR(100) NOT NULL
)");
$conn->query("INSERT INTO power_station_types (fuel_type, fuel_resource_name, fuel_per_mw_hr, display_name) VALUES
    ('coal', 'Coal', 1.0000, 'Coal Power Station'),
    ('wood', 'Wood', 2.0000, 'Wood Power Station'),
    ('oil', 'Oil', 0.5000, 'Oil Power Station'),
    ('nuclear', 'Nuclear fuel', 0.0100, 'Nuclear Power Station')
");
echo "power_station_types table created.<br>";

// --- Power stations (empty — players build them) ---
$conn->query("DROP TABLE IF EXISTS power_stations");
$conn->query("CREATE TABLE power_stations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    fuel_type VARCHAR(20) NOT NULL,
    level INT NOT NULL DEFAULT 1,
    workers_assigned INT NOT NULL DEFAULT 0,
    FOREIGN KEY (town_id) REFERENCES towns(id),
    FOREIGN KEY (fuel_type) REFERENCES power_station_types(fuel_type)
)");
echo "power_stations table created (empty).<br>";

// --- Transmission lines (empty — players build them) ---
$conn->query("DROP TABLE IF EXISTS transmission_lines");
$conn->query("CREATE TABLE transmission_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id_1 INT NOT NULL,
    town_id_2 INT NOT NULL,
    level INT NOT NULL DEFAULT 1,
    FOREIGN KEY (town_id_1) REFERENCES towns(id),
    FOREIGN KEY (town_id_2) REFERENCES towns(id),
    UNIQUE KEY (town_id_1, town_id_2)
)");
echo "transmission_lines table created (empty).<br>";

// --- Populate upgrade costs ---
$resourceLookup = [];
$res = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $res->fetch_assoc()) $resourceLookup[$r['resource_name']] = (int)$r['id'];

// Mine costs from list2.txt (same cost every level, adjustable in DB later)
$mineCosts = [
    'mine_standard' => ['Stone'=>40, 'Steel'=>75, 'Wooden boards'=>10, 'Mechanical components'=>5],
    'oil_well'      => ['Stone'=>2, 'Steel'=>85, 'Wooden boards'=>1, 'Mechanical components'=>25],
    'forestry'      => ['Bricks'=>25, 'Steel'=>5, 'Wooden boards'=>8, 'Mechanical components'=>1],
];

// Power station placeholder costs (user will set real costs later)
$powerCosts = [
    'power_coal'    => ['Stone'=>30, 'Steel'=>50, 'Mechanical components'=>10],
    'power_wood'    => ['Stone'=>20, 'Steel'=>30, 'Wooden boards'=>15],
    'power_oil'     => ['Stone'=>25, 'Steel'=>60, 'Mechanical components'=>15],
    'power_nuclear' => ['Stone'=>100, 'Steel'=>150, 'Mechanical components'=>50, 'Electronic components'=>25],
];

// Transmission line base costs (per km — multiplied by distance at build time)
$transmissionCosts = [
    'transmission'  => ['Steel'=>2, 'Wooden boards'=>1],
];

$allCosts = array_merge($mineCosts, $powerCosts, $transmissionCosts);

$stmt = $conn->prepare("INSERT INTO upgrade_costs (building_type, target_level, resource_id, amount) VALUES (?, ?, ?, ?)");
foreach ($allCosts as $buildType => $resources) {
    // Populate levels 1-10 (level 1 = build cost, 2+ = upgrade cost)
    for ($lvl = 1; $lvl <= 10; $lvl++) {
        foreach ($resources as $resName => $amount) {
            if (!isset($resourceLookup[$resName])) {
                echo "WARNING: Resource '$resName' not found in world_prices<br>";
                continue;
            }
            $rid = $resourceLookup[$resName];
            $amt = (float)$amount * $lvl;  // cost scales with level
            $stmt->bind_param("siid", $buildType, $lvl, $rid, $amt);
            $stmt->execute();
        }
    }
    echo "$buildType costs defined (levels 1-10, scaling).<br>";
}
$stmt->close();

echo "<br><strong>All done! Delete this file and setup_production.php now.</strong>";
$conn->close();
?>
