<?php
// Run this file ONCE to create town_production and assign mine specialties, then delete it.
// NOTE: Run setup_power.php AFTER this to create upgrade_costs and power tables.
include __DIR__ . "/../files/config.php";

$conn->query("DROP TABLE IF EXISTS town_production");

$conn->query("CREATE TABLE town_production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    resource_id INT NOT NULL,
    level INT NOT NULL DEFAULT 1,
    workers_assigned INT NOT NULL DEFAULT 0,
    FOREIGN KEY (town_id) REFERENCES towns(id),
    FOREIGN KEY (resource_id) REFERENCES world_prices(id),
    UNIQUE KEY (town_id)
)");
echo "town_production table created.<br>";

// Build lookups
$townLookup = [];
$res = $conn->query("SELECT id, name FROM towns");
while ($r = $res->fetch_assoc()) $townLookup[$r['name']] = (int)$r['id'];

$resourceLookup = [];
$res = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $res->fetch_assoc()) $resourceLookup[$r['resource_name']] = (int)$r['id'];

$assignments = [
    'Ashbury'=>'Coal','Bramwell'=>'Oil','Chedford'=>'Stone','Dunwick'=>'Iron',
    'Elmhurst'=>'Bauxite','Fairford'=>'Uranium','Glenmore'=>'Wood','Hartleigh'=>'Coal',
    'Iverton'=>'Oil','Kinbury'=>'Iron',
    'Langton'=>'Coal','Merefield'=>'Oil','Northby'=>'Stone','Oakworth'=>'Iron',
    'Pemford'=>'Bauxite','Ridgwell'=>'Uranium','Stoneleigh'=>'Wood','Thornbury'=>'Coal',
    'Uppingham'=>'Oil','Wexford'=>'Iron',
];

$stmt = $conn->prepare("INSERT INTO town_production (town_id, resource_id, level, workers_assigned) VALUES (?, ?, 1, 0)");
foreach ($assignments as $townName => $resourceName) {
    if (!isset($townLookup[$townName]) || !isset($resourceLookup[$resourceName])) {
        echo "WARNING: '$townName' or '$resourceName' not found<br>";
        continue;
    }
    $tid = $townLookup[$townName];
    $rid = $resourceLookup[$resourceName];
    $stmt->bind_param("ii", $tid, $rid);
    $stmt->execute();
    echo "$townName → $resourceName (Lvl 1)<br>";
}
$stmt->close();

echo "<br><strong>Done! Now run setup_power.php, then delete both files.</strong>";
$conn->close();
?>
