<?php
// admin_vehicles.php — Admin page for managing vehicle types
session_start();
include __DIR__ . "/../files/config.php";
include __DIR__ . "/../files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
if (!isGreenTeam($user)) {
    header("Location: " . BASE_URL . "index.php?msg=access_denied");
    exit;
}
$isAdmin = true;

$msg = $_GET['msg'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $act = $_POST['admin_action'] ?? '';

    if ($act === 'add_vehicle') {
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'civilian';
        $vClass = $_POST['vehicle_class'] ?? 'cargo';
        $cargoClass = !empty($_POST['cargo_class']) ? trim($_POST['cargo_class']) : null;
        $speed = (int)($_POST['max_speed_kmh'] ?? 60);
        $fuelCap = (float)($_POST['fuel_capacity'] ?? 100);
        $fuelPerKm = (float)($_POST['fuel_per_km'] ?? 0.5);
        $maxCap = (float)($_POST['max_capacity'] ?? 10);
        $pointsPrice = (float)($_POST['points_price'] ?? 500);
        $buildTicks = (int)($_POST['build_time_ticks'] ?? 12);

        if ($name) {
            $stmt = $conn->prepare("INSERT INTO vehicle_types (name, category, vehicle_class, cargo_class, max_speed_kmh, fuel_capacity, fuel_per_km, max_capacity, points_price, build_time_ticks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiidddi", $name, $category, $vClass, $cargoClass, $speed, $fuelCap, $fuelPerKm, $maxCap, $pointsPrice, $buildTicks);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            // Save build costs if provided
            if (!empty($_POST['cost_resource']) && is_array($_POST['cost_resource'])) {
                $costStmt = $conn->prepare("INSERT INTO vehicle_build_costs (vehicle_type_id, resource_id, amount) VALUES (?, ?, ?)");
                foreach ($_POST['cost_resource'] as $i => $crid) {
                    $camt = (float)($_POST['cost_amount'][$i] ?? 0);
                    if ((int)$crid > 0 && $camt > 0) {
                        $cridInt = (int)$crid;
                        $costStmt->bind_param("iid", $newId, $cridInt, $camt);
                        $costStmt->execute();
                    }
                }
                $costStmt->close();
            }
            header("Location: admin_vehicles.php?msg=added");
            exit;
        }
    }

    if ($act === 'delete_vehicle') {
        $vid = (int)($_POST['vehicle_type_id'] ?? 0);
        if ($vid > 0) {
            $conn->query("DELETE FROM vehicle_build_costs WHERE vehicle_type_id = $vid");
            $conn->query("DELETE FROM vehicle_types WHERE id = $vid");
            header("Location: admin_vehicles.php?msg=deleted");
            exit;
        }
    }

    if ($act === 'toggle_vehicle') {
        $vid = (int)($_POST['vehicle_type_id'] ?? 0);
        if ($vid > 0) {
            $conn->query("UPDATE vehicle_types SET active = NOT active WHERE id = $vid");
            header("Location: admin_vehicles.php?msg=toggled");
            exit;
        }
    }
}

// Load all vehicle types
$vehicleTypes = [];
$vtRes = $conn->query("
    SELECT vt.*
    FROM vehicle_types vt
    ORDER BY vt.category, vt.vehicle_class, vt.name
");
if ($vtRes) { while ($v = $vtRes->fetch_assoc()) $vehicleTypes[] = $v; }

// Load build costs per vehicle type
$buildCosts = [];
$bcRes = $conn->query("SELECT vbc.*, wp.resource_name, wp.image_file FROM vehicle_build_costs vbc JOIN world_prices wp ON vbc.resource_id = wp.id ORDER BY wp.resource_name");
if ($bcRes) { while ($bc = $bcRes->fetch_assoc()) $buildCosts[(int)$bc['vehicle_type_id']][] = $bc; }

// All resources (for dropdowns)
$allResources = [];
$arRes = $conn->query("SELECT id, resource_name FROM world_prices ORDER BY resource_name");
if ($arRes) { while ($ar = $arRes->fetch_assoc()) $allResources[] = $ar; }

// Get distinct resource classes for cargo_class dropdown
$resourceClasses = [];
$rcRes = $conn->query("SELECT DISTINCT resource_type FROM world_prices WHERE resource_type IS NOT NULL ORDER BY resource_type");
if ($rcRes) { while ($rc = $rcRes->fetch_assoc()) $resourceClasses[] = $rc['resource_type']; }

include __DIR__ . "/../files/header.php";
include __DIR__ . "/../files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">🔧 Admin: Vehicle Types</h1>

			<?php if (!$isAdmin): ?>
			<div class="alert alert-danger">⛔ Admin access required. Your account must have admin privileges.</div>
			<?php else: ?>

			<?php if ($msg): ?>
			<div class="alert alert-success alert-dismissible fade show">
				✅ <?= $msg === 'added' ? 'Vehicle type added!' : ($msg === 'deleted' ? 'Vehicle type deleted!' : 'Vehicle type updated!') ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
			<?php endif; ?>

			<!-- Add Vehicle Form -->
			<div class="card bg-dark border-info mb-4">
				<div class="card-header border-info"><h5 class="mb-0">➕ Add New Vehicle Type</h5></div>
				<div class="card-body">
					<form method="post">
						<input type="hidden" name="admin_action" value="add_vehicle">
						<div class="row g-3">
							<div class="col-md-4">
								<label class="form-label small">Vehicle Name</label>
								<input type="text" name="name" class="form-control" required placeholder="e.g. Heavy Tanker">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Category</label>
								<select name="category" class="form-select">
									<option value="civilian">Civilian</option>
									<option value="military">Military</option>
								</select>
							</div>
							<div class="col-md-2">
								<label class="form-label small">Class</label>
								<select name="vehicle_class" class="form-select">
									<option value="cargo">Cargo</option>
									<option value="passenger">Passenger</option>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label small">Cargo Class (cargo vehicles only)</label>
								<select name="cargo_class" class="form-select">
									<option value="">None (Passenger)</option>
									<?php foreach ($resourceClasses as $rc): ?>
									<option value="<?= htmlspecialchars($rc) ?>"><?= htmlspecialchars($rc) ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2">
								<label class="form-label small">Max Speed (km/h)</label>
								<input type="number" name="max_speed_kmh" class="form-control" value="60" min="1">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Fuel Capacity</label>
								<input type="number" name="fuel_capacity" class="form-control" value="200" step="0.01" min="1">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Fuel/km</label>
								<input type="number" name="fuel_per_km" class="form-control" value="0.05" step="0.01" min="0.01">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Max Capacity (t or pax)</label>
								<input type="number" name="max_capacity" class="form-control" value="10" step="0.01" min="0.1">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Points Price</label>
								<input type="number" name="points_price" class="form-control" value="500" step="0.01" min="0">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Build Time (ticks)</label>
								<input type="number" name="build_time_ticks" class="form-control" value="12" min="1">
								<small class="text-muted">12 ticks = 1 hour</small>
							</div>
						</div>

						<!-- Build costs -->
						<div class="mt-3">
							<h6 class="text-info">Build Costs (resources to construct at factory)</h6>
							<div id="cost-rows">
								<div class="row g-2 mb-2 cost-row">
									<div class="col-md-5">
										<select name="cost_resource[]" class="form-select form-select-sm">
											<option value="">Select resource...</option>
											<?php foreach ($allResources as $ar): ?>
											<option value="<?= $ar['id'] ?>"><?= htmlspecialchars($ar['resource_name']) ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-md-3">
										<input type="number" name="cost_amount[]" class="form-control form-control-sm" placeholder="Amount (tonnes)" step="0.01" min="0">
									</div>
								</div>
							</div>
							<button type="button" class="btn btn-sm btn-outline-info" onclick="addCostRow()">+ Add Resource Cost</button>
						</div>

						<div class="mt-3">
							<button type="submit" class="btn btn-success">Add Vehicle Type</button>
						</div>
					</form>
				</div>
			</div>

			<!-- Existing Vehicle Types -->
			<div class="card bg-dark border-secondary">
				<div class="card-header border-secondary"><h5 class="mb-0">🚛 Existing Vehicle Types (<?= count($vehicleTypes) ?>)</h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>ID</th><th>Name</th><th>Cat</th><th>Class</th><th>Cargo</th>
									<th>Speed</th><th>Fuel Cap</th><th>Fuel/km</th><th>Capacity</th>
									<th>Points</th><th>Build</th><th>Costs</th><th>Status</th><th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($vehicleTypes as $vt):
								$catBadge = $vt['category'] === 'military' ? '<span class="badge bg-danger">MIL</span>' : '<span class="badge bg-info">CIV</span>';
								$clsBadge = $vt['vehicle_class'] === 'cargo' ? '<span class="badge bg-success">Cargo</span>' : '<span class="badge bg-primary">Pax</span>';
								$costs = $buildCosts[(int)$vt['id']] ?? [];
								$costStr = '';
								foreach ($costs as $c) $costStr .= number_format($c['amount']) . 't ' . $c['resource_name'] . ', ';
								$costStr = $costStr ? rtrim($costStr, ', ') : '<span class="text-muted">None set</span>';
							?>
								<tr class="<?= $vt['active'] ? '' : 'text-muted' ?>">
									<td><?= $vt['id'] ?></td>
									<td class="fw-bold"><?= htmlspecialchars($vt['name']) ?></td>
									<td><?= $catBadge ?></td>
									<td><?= $clsBadge ?></td>
									<td class="small"><?= $vt['cargo_class'] ? htmlspecialchars($vt['cargo_class']) : '<span class="text-muted">—</span>' ?></td>
									<td><?= $vt['max_speed_kmh'] ?> km/h</td>
									<td><?= $vt['fuel_capacity'] ?></td>
									<td><?= $vt['fuel_per_km'] ?></td>
									<td><?= $vt['max_capacity'] ?></td>
									<td><?= number_format($vt['points_price']) ?></td>
									<td><?= $vt['build_time_ticks'] ?> ticks</td>
									<td class="small"><?= $costStr ?></td>
									<td>
										<?php if ($vt['active']): ?>
											<span class="badge bg-success">Active</span>
										<?php else: ?>
											<span class="badge bg-secondary">Disabled</span>
										<?php endif; ?>
									</td>
									<td>
										<form method="post" class="d-inline">
											<input type="hidden" name="admin_action" value="toggle_vehicle">
											<input type="hidden" name="vehicle_type_id" value="<?= $vt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-warning py-0 px-1" title="Toggle active">
												<i class="fa fa-power-off"></i>
											</button>
										</form>
										<form method="post" class="d-inline" onsubmit="return confirm('Delete this vehicle type?')">
											<input type="hidden" name="admin_action" value="delete_vehicle">
											<input type="hidden" name="vehicle_type_id" value="<?= $vt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete">
												<i class="fa fa-trash"></i>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if (empty($vehicleTypes)): ?>
								<tr><td colspan="14" class="text-center text-muted py-3">No vehicle types defined. Add one above.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<?php endif; ?>
		</div>
		<!-- END #content -->

<script>
function addCostRow() {
    const container = document.getElementById('cost-rows');
    const row = container.querySelector('.cost-row').cloneNode(true);
    row.querySelectorAll('input, select').forEach(el => el.value = '');
    container.appendChild(row);
}
</script>

<?php
include __DIR__ . "/../files/scripts.php";
$conn->close();
?>
