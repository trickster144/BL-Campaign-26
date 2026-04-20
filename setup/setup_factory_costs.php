<?php
/**
 * Setup factory upgrade costs for all factory types (levels 1-10, scaling by level).
 * Run once via browser. Safe to re-run (uses INSERT IGNORE).
 */
include "../files/config.php";

echo "<h2>Factory Upgrade Costs Setup</h2>";

// Resource lookup
$resLookup = [];
$resQ = $conn->query("SELECT id, resource_name FROM world_prices");
while ($r = $resQ->fetch_assoc()) $resLookup[$r['resource_name']] = (int)$r['id'];

// Base costs per level for each factory type (amount × level = actual cost)
$factoryCosts = [
	'aluminium_factory'    => ['Stone'=>40, 'Steel'=>50, 'Mechanical components'=>15],
	'steel_factory'        => ['Stone'=>35, 'Steel'=>30, 'Bricks'=>20, 'Mechanical components'=>10],
	'uranium_processing'   => ['Stone'=>80, 'Steel'=>100, 'Mechanical components'=>40, 'Electronic components'=>20],
	'brick_factory'        => ['Stone'=>15, 'Steel'=>10],
	'prefab_factory'       => ['Stone'=>20, 'Steel'=>15, 'Mechanical components'=>5],
	'sawmill'              => ['Stone'=>10, 'Steel'=>8],
	'chemicals_factory'    => ['Stone'=>50, 'Steel'=>60, 'Mechanical components'=>25, 'Electronic components'=>10],
	'electronics_factory'  => ['Stone'=>60, 'Steel'=>70, 'Mechanical components'=>30, 'Electronic components'=>15],
	'explosives_factory'   => ['Stone'=>70, 'Steel'=>80, 'Mechanical components'=>35, 'Chemicals'=>20],
	'mechanical_factory'   => ['Stone'=>45, 'Steel'=>55, 'Electronic components'=>10],
	'plastics_factory'     => ['Stone'=>30, 'Steel'=>40, 'Mechanical components'=>15],
	'oil_refinery'         => ['Stone'=>40, 'Steel'=>50, 'Mechanical components'=>20],
	'vehicle_factory'      => ['Steel'=>60, 'Mechanical components'=>20, 'Electronic components'=>10, 'Stone'=>40],
];

$inserted = 0;
$stmt = $conn->prepare("INSERT IGNORE INTO upgrade_costs (building_type, target_level, resource_id, amount) VALUES (?, ?, ?, ?)");

foreach ($factoryCosts as $buildType => $resources) {
	for ($lvl = 1; $lvl <= 10; $lvl++) {
		foreach ($resources as $resName => $baseAmount) {
			if (!isset($resLookup[$resName])) {
				echo "⚠️ Resource '$resName' not found<br>";
				continue;
			}
			$rid = $resLookup[$resName];
			$amt = (float)$baseAmount * $lvl;
			$stmt->bind_param("siid", $buildType, $lvl, $rid, $amt);
			$stmt->execute();
			$inserted += $conn->affected_rows;
		}
	}
	echo "✅ $buildType — levels 1-10 (cost scales with level)<br>";
}
$stmt->close();

echo "<br><strong>Done! Inserted $inserted new cost entries.</strong>";
echo "<br>Existing entries (e.g. vehicle_factory) were preserved (INSERT IGNORE).</p>";
$conn->close();
?>
