<?php
/**
 * Rebalance power stations:
 * - Add mw_per_level column to power_station_types
 * - Set realistic MW output and fuel burn rates per fuel type
 * - Run once via browser. Safe to re-run.
 */
include "../files/config.php";

echo "<h2>Power Station Rebalance</h2>";

// Add mw_per_level column if missing
$col = $conn->query("SHOW COLUMNS FROM power_station_types LIKE 'mw_per_level'");
if ($col->num_rows === 0) {
	$conn->query("ALTER TABLE power_station_types ADD COLUMN mw_per_level DECIMAL(10,2) NOT NULL DEFAULT 1.00 AFTER fuel_per_mw_hr");
	echo "✅ Added mw_per_level column<br>";
} else {
	echo "ℹ️ mw_per_level column already exists<br>";
}

// Rebalance values:
// fuel_per_mw_hr = tonnes of fuel burned per MW of output per hour
// mw_per_level = MW output per level (at 100% workers)
//
// Coal: 20 MW/level, burns 0.5t coal per MW/hr → 10t coal/hr per level
// Wood: 15 MW/level, burns 1.0t wood per MW/hr → 15t wood/hr per level  
// Oil:  30 MW/level, burns 0.33t oil per MW/hr → 10t oil/hr per level
// Nuclear: 100 MW/level, burns 0.01t fuel per MW/hr → 1t nuclear fuel/hr per level
$updates = [
	['coal',    0.5000, 20.00],   // 20 MW/lvl, 10t coal/hr/lvl
	['wood',    1.0000, 15.00],   // 15 MW/lvl, 15t wood/hr/lvl
	['oil',     0.3333, 30.00],   // 30 MW/lvl, 10t oil/hr/lvl
	['nuclear', 0.0100, 100.00],  // 100 MW/lvl, 1t nuclear/hr/lvl
];

$stmt = $conn->prepare("UPDATE power_station_types SET fuel_per_mw_hr = ?, mw_per_level = ? WHERE fuel_type = ?");
foreach ($updates as $u) {
	$stmt->bind_param("dds", $u[1], $u[2], $u[0]);
	$stmt->execute();
	$fuelPerLevel = $u[1] * $u[2];
	echo "✅ {$u[0]}: {$u[2]} MW/level, {$u[1]} t/MW/hr (= {$fuelPerLevel} t/hr per level at full load)<br>";
}
$stmt->close();

echo "<br><strong>Done! Power stations are now rebalanced.</strong>";
echo "<br>Stations will also throttle fuel burn based on grid demand (code change in power_functions.php and tick_engine.php).";
$conn->close();
?>
