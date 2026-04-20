<?php
// setup_factories.php — Creates factory system tables and populates 12 factory definitions
// Run once via browser, then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// ── Create factory_types table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS factory_types (
    type_id VARCHAR(50) PRIMARY KEY,
    display_name VARCHAR(100) NOT NULL,
    power_per_level DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    workers_per_level INT NOT NULL DEFAULT 100,
    output_resource_id INT NOT NULL,
    output_per_level DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    FOREIGN KEY (output_resource_id) REFERENCES world_prices(id)
)");
$messages[] = $r ? "✅ factory_types table created" : "❌ Error: " . $conn->error;

// ── Create factory_inputs table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS factory_inputs (
    factory_type_id VARCHAR(50) NOT NULL,
    resource_id INT NOT NULL,
    amount_per_level DECIMAL(10,4) NOT NULL,
    PRIMARY KEY (factory_type_id, resource_id),
    FOREIGN KEY (factory_type_id) REFERENCES factory_types(type_id),
    FOREIGN KEY (resource_id) REFERENCES world_prices(id)
)");
$messages[] = $r ? "✅ factory_inputs table created" : "❌ Error: " . $conn->error;

// ── Create town_factories table ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS town_factories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    factory_type_id VARCHAR(50) NOT NULL,
    level INT DEFAULT 1,
    workers_assigned INT DEFAULT 0,
    UNIQUE KEY uq_town_factory (town_id, factory_type_id),
    FOREIGN KEY (town_id) REFERENCES towns(id),
    FOREIGN KEY (factory_type_id) REFERENCES factory_types(type_id)
)");
$messages[] = $r ? "✅ town_factories table created" : "❌ Error: " . $conn->error;

// ── Clear old definitions (preserves player data in town_factories) ──
$conn->query("DELETE FROM factory_inputs");
$conn->query("DELETE FROM factory_types WHERE type_id NOT IN (SELECT DISTINCT factory_type_id FROM town_factories)");

// ── Populate factory_types ──
// [type_id, display_name, power_per_level, workers_per_level, output_resource_name, output_per_level]
$factories = [
    ['aluminium_factory',    'Aluminium Smelter',               5.00,  300, 'Aluminium',              1.0],
    ['steel_factory',        'Steel Works',                     3.00,  200, 'Steel',                  1.0],
    ['uranium_processing',   'Uranium Processing Plant',       10.00,  350, 'Nuclear fuel',           1.0],
    ['brick_factory',        'Brick Factory',                   1.00,  100, 'Bricks',                 1.0],
    ['prefab_factory',       'Prefabrication Factory',          1.00,   75, 'Prefab panels',          1.0],
    ['sawmill',              'Sawmill',                         1.00,   50, 'Wooden boards',          1.0],
    ['chemicals_factory',    'Chemicals Factory',               8.00,  500, 'Chemicals',              1.0],
    ['electronics_factory',  'Electronics Factory',            10.00,  200, 'Electronic components',  1.0],
    ['explosives_factory',   'Explosives Factory',             20.00,  150, 'Explosives',             1.0],
    ['mechanical_factory',   'Mechanical Components Factory',  15.00,  200, 'Mechanical components',  1.0],
    ['plastics_factory',     'Plastics Factory',               10.00,  100, 'Plastics',               1.0],
    ['oil_refinery',         'Oil Refinery',                    5.00,  500, 'Fuel',                   1.0],
    ['vehicle_factory',      'Vehicle Factory',                20.00,  400, 'Mechanical components',  0.5],
];

$insertedFactories = 0;
foreach ($factories as $f) {
    // Upsert: update if exists, insert if new
    $stmt = $conn->prepare("INSERT INTO factory_types (type_id, display_name, power_per_level, workers_per_level, output_resource_id, output_per_level)
        SELECT ?, ?, ?, ?, id, ? FROM world_prices WHERE resource_name = ?
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            power_per_level = VALUES(power_per_level),
            workers_per_level = VALUES(workers_per_level),
            output_resource_id = VALUES(output_resource_id),
            output_per_level = VALUES(output_per_level)");
    $stmt->bind_param("ssdids", $f[0], $f[1], $f[2], $f[3], $f[5], $f[4]);
    $stmt->execute();
    $insertedFactories += $stmt->affected_rows;
    $stmt->close();
}
$messages[] = "✅ Upserted $insertedFactories factory types (13 total)";

// ── Populate factory_inputs ──
// [factory_type_id, resource_name, amount_per_level (tonnes/hr)]
$inputs = [
    // Aluminium Smelter: 1t Bauxite → 1t Aluminium
    ['aluminium_factory', 'Bauxite', 1.0],

    // Steel Works: 3t Coal + 3t Iron → 1t Steel
    ['steel_factory', 'Coal', 3.0],
    ['steel_factory', 'Iron', 3.0],

    // Uranium Processing: 10t Uranium → 1t Nuclear fuel
    ['uranium_processing', 'Uranium', 10.0],

    // Brick Factory: 2t Coal → 1t Bricks
    ['brick_factory', 'Coal', 2.0],

    // Prefab Factory: 1t Coal + 5t Stone → 1t Prefab panels
    ['prefab_factory', 'Coal', 1.0],
    ['prefab_factory', 'Stone', 5.0],

    // Sawmill: 5t Wood → 1t Wooden boards
    ['sawmill', 'Wood', 5.0],

    // Chemicals Factory: 3t Stone + 3t Wood + 10t Oil → 1t Chemicals
    ['chemicals_factory', 'Stone', 3.0],
    ['chemicals_factory', 'Wood', 3.0],
    ['chemicals_factory', 'Oil', 10.0],

    // Electronics Factory: 3t Plastics + 2t Chemicals → 1t Electronic components
    ['electronics_factory', 'Plastics', 3.0],
    ['electronics_factory', 'Chemicals', 2.0],

    // Explosives Factory: 4t Chemicals + 4t Wood + 10t Stone → 1t Explosives
    ['explosives_factory', 'Chemicals', 4.0],
    ['explosives_factory', 'Wood', 4.0],
    ['explosives_factory', 'Stone', 10.0],

    // Mechanical Components Factory: 10t Steel → 1t Mechanical components
    ['mechanical_factory', 'Steel', 10.0],

    // Plastics Factory: 5t Oil + 2t Chemicals → 1t Plastics
    ['plastics_factory', 'Oil', 5.0],
    ['plastics_factory', 'Chemicals', 2.0],

    // Oil Refinery: 5t Oil → 1t Fuel
    ['oil_refinery', 'Oil', 5.0],

    // Vehicle Factory: 5t Steel + 3t Mechanical components + 1t Electronic components → 0.5t Mechanical components
    ['vehicle_factory', 'Steel', 5.0],
    ['vehicle_factory', 'Mechanical components', 3.0],
    ['vehicle_factory', 'Electronic components', 1.0],
];

$insertedInputs = 0;
foreach ($inputs as $inp) {
    $stmt = $conn->prepare("INSERT INTO factory_inputs (factory_type_id, resource_id, amount_per_level)
        SELECT ?, id, ? FROM world_prices WHERE resource_name = ?
        ON DUPLICATE KEY UPDATE amount_per_level = VALUES(amount_per_level)");
    $stmt->bind_param("sds", $inp[0], $inp[2], $inp[1]);
    $stmt->execute();
    $insertedInputs += $stmt->affected_rows;
    $stmt->close();
}
$messages[] = "✅ Populated $insertedInputs factory input recipes";

// ── Vehicle factory upgrade costs ──
$factoryUpgradeCosts = [
    'vehicle_factory' => ['Steel'=>60, 'Mechanical components'=>20, 'Electronic components'=>10, 'Stone'=>40],
];
$resLookup = [];
$resQ = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $resQ->fetch_assoc()) $resLookup[$r['resource_name']] = (int)$r['id'];

$insertedCosts = 0;
foreach ($factoryUpgradeCosts as $buildType => $resources) {
    for ($lvl = 1; $lvl <= 10; $lvl++) {
        foreach ($resources as $resName => $amount) {
            if (!isset($resLookup[$resName])) continue;
            $rid = $resLookup[$resName];
            $amt = (float)$amount * $lvl;
            $conn->query("INSERT IGNORE INTO upgrade_costs (building_type, target_level, resource_id, amount)
                          VALUES ('$buildType', $lvl, $rid, $amt)");
            $insertedCosts += $conn->affected_rows;
        }
    }
}
$messages[] = "✅ Inserted $insertedCosts vehicle factory upgrade cost entries";

// ── Balance patch: update power station fuel rates ──
$powerFixes = [
    "UPDATE power_station_types SET fuel_per_mw_hr = 2.0000 WHERE fuel_type = 'wood' AND fuel_per_mw_hr != 2.0000",
];
foreach ($powerFixes as $sql) {
    $conn->query($sql);
    if ($conn->affected_rows > 0) {
        $messages[] = "✅ Rebalanced wood power station: 10 → 2 t/MW/hr";
    }
}

// ── Verify factory definitions ──
$verifyRes = $conn->query("
    SELECT ft.type_id, ft.display_name, ft.power_per_level, ft.workers_per_level,
           ft.output_per_level, wp.resource_name AS output_name
    FROM factory_types ft
    JOIN world_prices wp ON ft.output_resource_id = wp.id
    ORDER BY ft.display_name
");
$factoryRows = [];
if ($verifyRes) { while ($r = $verifyRes->fetch_assoc()) $factoryRows[] = $r; }

// ── Verify input recipes ──
$inputRows = [];
$inputRes = $conn->query("
    SELECT fi.factory_type_id, wp.resource_name, fi.amount_per_level
    FROM factory_inputs fi
    JOIN world_prices wp ON fi.resource_id = wp.id
    ORDER BY fi.factory_type_id, wp.resource_name
");
if ($inputRes) { while ($r = $inputRes->fetch_assoc()) $inputRows[$r['factory_type_id']][] = $r; }

// ── Display ──
echo "<!DOCTYPE html><html><head><title>Setup Factories</title></head>";
echo "<body style='background:#1a1a2e;color:#e0e0e0;font-family:monospace;padding:30px;max-width:1000px;margin:0 auto;'>";
echo "<h2>🏭 Setup: Factory System</h2><hr style='border-color:#333;'>";

foreach ($messages as $msg) echo "<p style='margin:6px 0;'>$msg</p>";

echo "<hr style='border-color:#333;'>";
echo "<h3>Factory Definitions</h3>";
echo "<table style='border-collapse:collapse;width:100%;margin-bottom:20px;'>";
echo "<tr style='background:#222;'>";
echo "<th style='padding:8px;border:1px solid #444;'>Factory</th>";
echo "<th style='padding:8px;border:1px solid #444;'>Inputs (per level/hr)</th>";
echo "<th style='padding:8px;border:1px solid #444;'>Output (per level/hr)</th>";
echo "<th style='padding:8px;border:1px solid #444;'>Power/lvl</th>";
echo "<th style='padding:8px;border:1px solid #444;'>Workers/lvl</th>";
echo "</tr>";

foreach ($factoryRows as $fr) {
    $ins = isset($inputRows[$fr['type_id']]) ? $inputRows[$fr['type_id']] : [];
    $inputStr = implode(' + ', array_map(function($i) {
        return $i['amount_per_level'] . 't ' . $i['resource_name'];
    }, $ins));

    echo "<tr>";
    echo "<td style='padding:8px;border:1px solid #444;font-weight:bold;'>{$fr['display_name']}</td>";
    echo "<td style='padding:8px;border:1px solid #444;'>{$inputStr}</td>";
    echo "<td style='padding:8px;border:1px solid #444;color:#4fc3f7;'>{$fr['output_per_level']}t {$fr['output_name']}</td>";
    echo "<td style='padding:8px;border:1px solid #444;'>{$fr['power_per_level']} MW</td>";
    echo "<td style='padding:8px;border:1px solid #444;'>{$fr['workers_per_level']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Notes</h3>";
echo "<ul style='line-height:2;'>";
echo "<li>All values are <strong>per level per hour</strong> — a Level 2 factory uses/produces 2× the listed amounts</li>";
echo "<li>Actual output scales by <strong>worker efficiency</strong> (workers / max) and <strong>power efficiency</strong> (grid ratio)</li>";
echo "<li>Factory build/upgrade costs use the <code>upgrade_costs</code> table — add rows with <code>building_type</code> = factory type_id to set costs</li>";
echo "<li>All parameters can be adjusted directly in the <code>factory_types</code> and <code>factory_inputs</code> tables via phpMyAdmin</li>";
echo "</ul>";

echo "<p style='margin-top:20px;'><a href='" . BASE_URL . "guide.php' style='color:#4fc3f7;font-size:1.2em;'>→ View Production Guide</a></p>";
echo "<p><a href='" . BASE_URL . "towns.php' style='color:#4fc3f7;font-size:1.1em;'>→ View Towns</a></p>";
echo "</body></html>";
$conn->close();
?>
