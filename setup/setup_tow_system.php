<?php
// setup_tow_system.php — Adds tow truck support to the vehicle system
// Run once via browser
include __DIR__ . "/../files/config.php";

$messages = [];

// ── Expand vehicle_class ENUM to include 'tow' ──
$r = $conn->query("ALTER TABLE vehicle_types MODIFY COLUMN vehicle_class ENUM('cargo','passenger','tow') NOT NULL DEFAULT 'cargo'");
$messages[] = $r ? "✅ Expanded vehicle_class ENUM (added 'tow')" : "❌ " . $conn->error;

// ── Expand vehicle status ENUM to include 'towing' ──
$r = $conn->query("ALTER TABLE vehicles MODIFY COLUMN status ENUM('idle','in_transit','building','loading','repairing','scrapped','towing') NOT NULL DEFAULT 'idle'");
$messages[] = $r ? "✅ Expanded vehicle status ENUM (added 'towing')" : "❌ " . $conn->error;

// ── Add tow_vehicle_id column to vehicle_trips ──
$check = $conn->query("SHOW COLUMNS FROM vehicle_trips LIKE 'tow_vehicle_id'");
if ($check && $check->num_rows === 0) {
	$r = $conn->query("ALTER TABLE vehicle_trips ADD COLUMN tow_vehicle_id INT DEFAULT NULL COMMENT 'Vehicle being towed (NULL if not a tow trip)' AFTER return_empty");
	$messages[] = $r ? "✅ Added tow_vehicle_id column to vehicle_trips" : "❌ " . $conn->error;
} else {
	$messages[] = "ℹ️ tow_vehicle_id column already exists on vehicle_trips";
}

// ── Insert a default Tow Truck vehicle type if none exists ──
$towCheck = $conn->query("SELECT id FROM vehicle_types WHERE vehicle_class = 'tow'");
if ($towCheck && $towCheck->num_rows === 0) {
	$r = $conn->query("INSERT INTO vehicle_types (name, category, vehicle_class, cargo_class, max_speed_kmh, fuel_capacity, fuel_per_km, max_capacity, points_price, build_time_ticks, active)
		VALUES ('Tow Truck', 'civilian', 'tow', NULL, 50, 120, 0.8, 0, 500, 18, 1)");
	$messages[] = $r ? "✅ Inserted default Tow Truck vehicle type (id: " . $conn->insert_id . ")" : "❌ " . $conn->error;

	$towId = $conn->insert_id;

	// Add build costs for tow truck
	$resLookup = [];
	$resQ = $conn->query("SELECT id, resource_name FROM world_prices");
	while ($row = $resQ->fetch_assoc()) $resLookup[$row['resource_name']] = (int)$row['id'];

	$towBuildCosts = [
		'Steel' => 25,
		'Mechanical components' => 15,
		'Fuel' => 30,
	];
	foreach ($towBuildCosts as $resName => $amount) {
		if (!isset($resLookup[$resName])) continue;
		$rid = $resLookup[$resName];
		$conn->query("INSERT INTO vehicle_build_costs (vehicle_type_id, resource_id, amount) VALUES ($towId, $rid, $amount) ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
	}
	$messages[] = "✅ Inserted tow truck build costs";
} else {
	$messages[] = "ℹ️ Tow truck vehicle type already exists";
}

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Tow System</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:700px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}
table{border-collapse:collapse;width:100%;margin-top:15px}th,td{border:1px solid #333;padding:6px 10px;text-align:left}
th{background:#0f3460}</style></head><body>
<h2>🚛 Tow System Setup</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p>$m</p>"; ?>
</div>

<h3 style="color:#e94560;">Tow System Overview</h3>
<table>
<tr><th>Feature</th><th>Details</th></tr>
<tr><td>🚛 Tow Truck</td><td>Special vehicle class that can tow immobilised vehicles to workshops</td></tr>
<tr><td>⚠️ Immobilised</td><td>Vehicles below 50% health cannot drive themselves</td></tr>
<tr><td>📍 Tow Process</td><td>Tow truck drives from its town to the broken vehicle, then tows it to a workshop town</td></tr>
<tr><td>⛽ Fuel</td><td>Tow trucks use 1.5× normal fuel (extra load). Fuel deducted from tow truck's origin town</td></tr>
<tr><td>🏁 Arrival</td><td>Both tow truck and towed vehicle arrive at the destination. Towed vehicle set to idle for repair</td></tr>
</table>

<p style="margin-top:20px;"><a href="<?= BASE_URL ?>towns.php">→ Towns</a></p>
</body></html>
