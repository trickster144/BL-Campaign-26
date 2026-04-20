<?php
// admin_trains.php — Admin page for managing locomotive & wagon types
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

    // ── Add Locomotive Type ──
    if ($act === 'add_loco') {
        $name = trim($_POST['name'] ?? '');
        $propulsion = $_POST['propulsion'] ?? 'steam';
        $speed = (int)($_POST['max_speed_kmh'] ?? 40);
        $fuelPerKm = (float)($_POST['fuel_per_km'] ?? 1.0);
        $fuelResId = !empty($_POST['fuel_resource_id']) ? (int)$_POST['fuel_resource_id'] : null;
        $maxWagons = (int)($_POST['max_wagons'] ?? 10);
        $price = (float)($_POST['points_price'] ?? 1000);
        $buildTicks = (int)($_POST['build_time_ticks'] ?? 24);

        if ($propulsion === 'electric') { $fuelPerKm = 0; $fuelResId = null; }

        if ($name) {
            $fuelResStr = ($fuelResId === null) ? "NULL" : $fuelResId;
            $nameEsc = $conn->real_escape_string($name);
            $propEsc = $conn->real_escape_string($propulsion);
            $conn->query("INSERT INTO locomotive_types (name, propulsion, max_speed_kmh, fuel_per_km, fuel_resource_id, max_wagons, points_price, build_time_ticks)
                VALUES ('$nameEsc', '$propEsc', $speed, $fuelPerKm, $fuelResStr, $maxWagons, $price, $buildTicks)");
            $newId = $conn->insert_id;

            if (!empty($_POST['cost_resource']) && is_array($_POST['cost_resource'])) {
                $costStmt = $conn->prepare("INSERT INTO locomotive_build_costs (locomotive_type_id, resource_id, amount) VALUES (?, ?, ?)");
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
            header("Location: admin_trains.php?msg=loco_added");
            exit;
        }
    }

    // ── Add Wagon Type ──
    if ($act === 'add_wagon') {
        $name = trim($_POST['name'] ?? '');
        $wagonClass = $_POST['wagon_class'] ?? 'cargo';
        $cargoClass = !empty($_POST['cargo_class']) ? trim($_POST['cargo_class']) : null;
        $maxCap = (float)($_POST['max_capacity'] ?? 30);
        $price = (float)($_POST['points_price'] ?? 200);
        $buildTicks = (int)($_POST['build_time_ticks'] ?? 6);

        if ($wagonClass === 'passenger') $cargoClass = null;

        if ($name) {
            $stmt = $conn->prepare("INSERT INTO wagon_types (name, wagon_class, cargo_class, max_capacity, points_price, build_time_ticks) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssddi", $name, $wagonClass, $cargoClass, $maxCap, $price, $buildTicks);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            if (!empty($_POST['cost_resource']) && is_array($_POST['cost_resource'])) {
                $costStmt = $conn->prepare("INSERT INTO wagon_build_costs (wagon_type_id, resource_id, amount) VALUES (?, ?, ?)");
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
            header("Location: admin_trains.php?msg=wagon_added");
            exit;
        }
    }

    // ── Delete types ──
    if ($act === 'delete_loco') {
        $lid = (int)($_POST['loco_type_id'] ?? 0);
        if ($lid > 0) {
            $conn->query("DELETE FROM locomotive_build_costs WHERE locomotive_type_id = $lid");
            $conn->query("DELETE FROM locomotive_types WHERE id = $lid");
            header("Location: admin_trains.php?msg=loco_deleted");
            exit;
        }
    }
    if ($act === 'delete_wagon') {
        $wid = (int)($_POST['wagon_type_id'] ?? 0);
        if ($wid > 0) {
            $conn->query("DELETE FROM wagon_build_costs WHERE wagon_type_id = $wid");
            $conn->query("DELETE FROM wagon_types WHERE id = $wid");
            header("Location: admin_trains.php?msg=wagon_deleted");
            exit;
        }
    }

    // ── Toggle types ──
    if ($act === 'toggle_loco') {
        $lid = (int)($_POST['loco_type_id'] ?? 0);
        if ($lid > 0) { $conn->query("UPDATE locomotive_types SET active = NOT active WHERE id = $lid"); }
        header("Location: admin_trains.php?msg=toggled");
        exit;
    }
    if ($act === 'toggle_wagon') {
        $wid = (int)($_POST['wagon_type_id'] ?? 0);
        if ($wid > 0) { $conn->query("UPDATE wagon_types SET active = NOT active WHERE id = $wid"); }
        header("Location: admin_trains.php?msg=toggled");
        exit;
    }

    // ── Upgrade Rail ──
    if ($act === 'upgrade_rail') {
        $railId = (int)($_POST['rail_id'] ?? 0);
        if ($railId > 0) {
            $conn->query("UPDATE rail_lines SET rail_type = 'electrified', speed_limit = 120 WHERE id = $railId AND rail_type = 'basic'");
            header("Location: admin_trains.php?msg=rail_upgraded");
            exit;
        }
    }
}

// Load locomotive types
$locoTypes = [];
$ltRes = $conn->query("SELECT lt.*, wp.resource_name as fuel_name
    FROM locomotive_types lt LEFT JOIN world_prices wp ON lt.fuel_resource_id = wp.id
    ORDER BY lt.propulsion, lt.name");
if ($ltRes) { while ($l = $ltRes->fetch_assoc()) $locoTypes[] = $l; }

// Load loco build costs
$locoCosts = [];
$lcRes = $conn->query("SELECT lbc.*, wp.resource_name FROM locomotive_build_costs lbc JOIN world_prices wp ON lbc.resource_id = wp.id ORDER BY wp.resource_name");
if ($lcRes) { while ($lc = $lcRes->fetch_assoc()) $locoCosts[(int)$lc['locomotive_type_id']][] = $lc; }

// Load wagon types
$wagonTypes = [];
$wtRes = $conn->query("SELECT * FROM wagon_types ORDER BY wagon_class, name");
if ($wtRes) { while ($w = $wtRes->fetch_assoc()) $wagonTypes[] = $w; }

// Load wagon build costs
$wagonCosts = [];
$wcRes = $conn->query("SELECT wbc.*, wp.resource_name FROM wagon_build_costs wbc JOIN world_prices wp ON wbc.resource_id = wp.id ORDER BY wp.resource_name");
if ($wcRes) { while ($wc = $wcRes->fetch_assoc()) $wagonCosts[(int)$wc['wagon_type_id']][] = $wc; }

// Load rail lines
$railLines = [];
$rlRes = $conn->query("SELECT rl.*, t1.name as town1_name, t2.name as town2_name, td.distance_km
    FROM rail_lines rl
    JOIN towns t1 ON rl.town_id_1 = t1.id
    JOIN towns t2 ON rl.town_id_2 = t2.id
    LEFT JOIN town_distances td ON (td.town_id_1 = rl.town_id_1 AND td.town_id_2 = rl.town_id_2) OR (td.town_id_1 = rl.town_id_2 AND td.town_id_2 = rl.town_id_1)
    ORDER BY t1.name, t2.name");
if ($rlRes) { while ($rl = $rlRes->fetch_assoc()) $railLines[] = $rl; }

// All resources for dropdowns
$allResources = [];
$arRes = $conn->query("SELECT id, resource_name FROM world_prices ORDER BY resource_name");
if ($arRes) { while ($ar = $arRes->fetch_assoc()) $allResources[] = $ar; }

// Fuel resources (Coal, Fuel)
$fuelResources = [];
$frRes = $conn->query("SELECT id, resource_name FROM world_prices WHERE resource_name IN ('Coal','Fuel') ORDER BY resource_name");
if ($frRes) { while ($fr = $frRes->fetch_assoc()) $fuelResources[] = $fr; }

// Resource classes for cargo dropdown
$resourceClasses = [];
$rcRes = $conn->query("SELECT DISTINCT resource_type FROM world_prices WHERE resource_type IS NOT NULL ORDER BY resource_type");
if ($rcRes) { while ($rc = $rcRes->fetch_assoc()) $resourceClasses[] = $rc['resource_type']; }

include __DIR__ . "/../files/header.php";
include __DIR__ . "/../files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">🚂 Admin: Train System</h1>

			<?php if ($msg): ?>
			<div class="alert alert-success alert-dismissible fade show">
				✅ <?= match($msg) {
					'loco_added' => 'Locomotive type added!',
					'wagon_added' => 'Wagon type added!',
					'loco_deleted' => 'Locomotive type deleted!',
					'wagon_deleted' => 'Wagon type deleted!',
					'rail_upgraded' => 'Rail line upgraded to electrified!',
					default => 'Action completed!'
				} ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
			<?php endif; ?>

			<!-- TABS -->
			<ul class="nav nav-tabs mb-3" role="tablist">
				<li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-locos">🚂 Locomotives</a></li>
				<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-wagons">🚃 Wagons</a></li>
				<li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-rails">🛤️ Rail Lines</a></li>
			</ul>

			<div class="tab-content">
			<!-- ══════ LOCOMOTIVES TAB ══════ -->
			<div class="tab-pane fade show active" id="tab-locos">
				<div class="card bg-dark border-info mb-4">
					<div class="card-header border-info"><h5 class="mb-0">➕ Add Locomotive Type</h5></div>
					<div class="card-body">
						<form method="post">
							<input type="hidden" name="admin_action" value="add_loco">
							<div class="row g-3">
								<div class="col-md-3">
									<label class="form-label small">Name</label>
									<input type="text" name="name" class="form-control" required placeholder="e.g. Fast Diesel">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Propulsion</label>
									<select name="propulsion" class="form-select" id="loco-propulsion" onchange="toggleFuel()">
										<option value="steam">Steam (Coal)</option>
										<option value="diesel">Diesel (Fuel)</option>
										<option value="electric">Electric</option>
									</select>
								</div>
								<div class="col-md-2">
									<label class="form-label small">Max Speed (km/h)</label>
									<input type="number" name="max_speed_kmh" class="form-control" value="40" min="1">
								</div>
								<div class="col-md-2" id="fuel-per-km-group">
									<label class="form-label small">Fuel/km (tonnes)</label>
									<input type="number" name="fuel_per_km" class="form-control" value="1.0" step="0.01" min="0">
								</div>
								<div class="col-md-3" id="fuel-res-group">
									<label class="form-label small">Fuel Resource</label>
									<select name="fuel_resource_id" class="form-select">
										<option value="">None (Electric)</option>
										<?php foreach ($fuelResources as $fr): ?>
										<option value="<?= $fr['id'] ?>"><?= htmlspecialchars($fr['resource_name']) ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<label class="form-label small">Max Wagons</label>
									<input type="number" name="max_wagons" class="form-control" value="10" min="1" max="30">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Points Price</label>
									<input type="number" name="points_price" class="form-control" value="1000" min="0">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Build Time (ticks)</label>
									<input type="number" name="build_time_ticks" class="form-control" value="24" min="1">
									<small class="text-muted">12 ticks = 1 hr</small>
								</div>
							</div>
							<div class="mt-3">
								<h6 class="text-info">Build Costs</h6>
								<div id="loco-cost-rows">
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
											<input type="number" name="cost_amount[]" class="form-control form-control-sm" placeholder="Amount (t)" step="0.01">
										</div>
									</div>
								</div>
								<button type="button" class="btn btn-sm btn-outline-info" onclick="addCostRow('loco-cost-rows')">+ Add Cost</button>
							</div>
							<div class="mt-3"><button type="submit" class="btn btn-success">Add Locomotive</button></div>
						</form>
					</div>
				</div>

				<!-- Existing Locos -->
				<div class="card bg-dark border-secondary">
					<div class="card-header border-secondary"><h5 class="mb-0">🚂 Locomotive Types (<?= count($locoTypes) ?>)</h5></div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-dark table-hover table-sm mb-0">
								<thead><tr>
									<th>ID</th><th>Name</th><th>Propulsion</th><th>Speed</th><th>Fuel/km</th><th>Fuel</th><th>Wagons</th><th>Price</th><th>Build</th><th>Costs</th><th>Status</th><th>Actions</th>
								</tr></thead>
								<tbody>
								<?php foreach ($locoTypes as $lt):
									$propBadge = match($lt['propulsion']) {
										'steam' => '<span class="badge bg-secondary">🔥 Steam</span>',
										'diesel' => '<span class="badge bg-warning text-dark">⛽ Diesel</span>',
										'electric' => '<span class="badge bg-info">⚡ Electric</span>',
										default => $lt['propulsion']
									};
									$costs = $locoCosts[(int)$lt['id']] ?? [];
									$costStr = '';
									foreach ($costs as $c) $costStr .= number_format($c['amount']) . 't ' . $c['resource_name'] . ', ';
									$costStr = $costStr ? rtrim($costStr, ', ') : '<span class="text-muted">None</span>';
								?>
								<tr class="<?= $lt['active'] ? '' : 'text-muted' ?>">
									<td><?= $lt['id'] ?></td>
									<td class="fw-bold"><?= htmlspecialchars($lt['name']) ?></td>
									<td><?= $propBadge ?></td>
									<td><?= $lt['max_speed_kmh'] ?> km/h</td>
									<td><?= $lt['fuel_per_km'] ?> t</td>
									<td><?= $lt['fuel_name'] ?? 'None' ?></td>
									<td><?= $lt['max_wagons'] ?></td>
									<td><?= number_format($lt['points_price']) ?></td>
									<td><?= $lt['build_time_ticks'] ?> ticks</td>
									<td class="small"><?= $costStr ?></td>
									<td><?= $lt['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></td>
									<td>
										<form method="post" class="d-inline">
											<input type="hidden" name="admin_action" value="toggle_loco">
											<input type="hidden" name="loco_type_id" value="<?= $lt['id'] ?>">
											<button class="btn btn-sm btn-outline-warning py-0 px-1" title="Toggle"><i class="fa fa-power-off"></i></button>
										</form>
										<form method="post" class="d-inline" onsubmit="return confirm('Delete this locomotive type?')">
											<input type="hidden" name="admin_action" value="delete_loco">
											<input type="hidden" name="loco_type_id" value="<?= $lt['id'] ?>">
											<button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="fa fa-trash"></i></button>
										</form>
									</td>
								</tr>
								<?php endforeach; ?>
								<?php if (empty($locoTypes)): ?>
								<tr><td colspan="12" class="text-center text-muted py-3">No locomotive types. Run setup_trains.php or add above.</td></tr>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>

			<!-- ══════ WAGONS TAB ══════ -->
			<div class="tab-pane fade" id="tab-wagons">
				<div class="card bg-dark border-info mb-4">
					<div class="card-header border-info"><h5 class="mb-0">➕ Add Wagon Type</h5></div>
					<div class="card-body">
						<form method="post">
							<input type="hidden" name="admin_action" value="add_wagon">
							<div class="row g-3">
								<div class="col-md-3">
									<label class="form-label small">Name</label>
									<input type="text" name="name" class="form-control" required placeholder="e.g. Refrigerated Car">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Class</label>
									<select name="wagon_class" class="form-select">
										<option value="cargo">Cargo</option>
										<option value="passenger">Passenger</option>
									</select>
								</div>
								<div class="col-md-3">
									<label class="form-label small">Cargo Class (cargo only)</label>
									<select name="cargo_class" class="form-select">
										<option value="">None (Passenger)</option>
										<?php foreach ($resourceClasses as $rc): ?>
										<option value="<?= htmlspecialchars($rc) ?>"><?= htmlspecialchars($rc) ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<label class="form-label small">Capacity (t or pax)</label>
									<input type="number" name="max_capacity" class="form-control" value="30" step="0.01" min="1">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Points Price</label>
									<input type="number" name="points_price" class="form-control" value="200" min="0">
								</div>
								<div class="col-md-2">
									<label class="form-label small">Build Ticks</label>
									<input type="number" name="build_time_ticks" class="form-control" value="6" min="1">
								</div>
							</div>
							<div class="mt-3">
								<h6 class="text-info">Build Costs</h6>
								<div id="wagon-cost-rows">
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
											<input type="number" name="cost_amount[]" class="form-control form-control-sm" placeholder="Amount (t)" step="0.01">
										</div>
									</div>
								</div>
								<button type="button" class="btn btn-sm btn-outline-info" onclick="addCostRow('wagon-cost-rows')">+ Add Cost</button>
							</div>
							<div class="mt-3"><button type="submit" class="btn btn-success">Add Wagon</button></div>
						</form>
					</div>
				</div>

				<!-- Existing Wagons -->
				<div class="card bg-dark border-secondary">
					<div class="card-header border-secondary"><h5 class="mb-0">🚃 Wagon Types (<?= count($wagonTypes) ?>)</h5></div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-dark table-hover table-sm mb-0">
								<thead><tr>
									<th>ID</th><th>Name</th><th>Class</th><th>Cargo</th><th>Capacity</th><th>Price</th><th>Build</th><th>Costs</th><th>Status</th><th>Actions</th>
								</tr></thead>
								<tbody>
								<?php foreach ($wagonTypes as $wt):
									$clsBadge = $wt['wagon_class'] === 'cargo' ? '<span class="badge bg-success">Cargo</span>' : '<span class="badge bg-primary">Passenger</span>';
									$costs = $wagonCosts[(int)$wt['id']] ?? [];
									$costStr = '';
									foreach ($costs as $c) $costStr .= number_format($c['amount']) . 't ' . $c['resource_name'] . ', ';
									$costStr = $costStr ? rtrim($costStr, ', ') : '<span class="text-muted">None</span>';
								?>
								<tr class="<?= $wt['active'] ? '' : 'text-muted' ?>">
									<td><?= $wt['id'] ?></td>
									<td class="fw-bold"><?= htmlspecialchars($wt['name']) ?></td>
									<td><?= $clsBadge ?></td>
									<td><?= $wt['cargo_class'] ? htmlspecialchars($wt['cargo_class']) : '—' ?></td>
									<td><?= $wt['max_capacity'] ?> <?= $wt['wagon_class'] === 'passenger' ? 'pax' : 't' ?></td>
									<td><?= number_format($wt['points_price']) ?></td>
									<td><?= $wt['build_time_ticks'] ?> ticks</td>
									<td class="small"><?= $costStr ?></td>
									<td><?= $wt['active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></td>
									<td>
										<form method="post" class="d-inline">
											<input type="hidden" name="admin_action" value="toggle_wagon">
											<input type="hidden" name="wagon_type_id" value="<?= $wt['id'] ?>">
											<button class="btn btn-sm btn-outline-warning py-0 px-1" title="Toggle"><i class="fa fa-power-off"></i></button>
										</form>
										<form method="post" class="d-inline" onsubmit="return confirm('Delete this wagon type?')">
											<input type="hidden" name="admin_action" value="delete_wagon">
											<input type="hidden" name="wagon_type_id" value="<?= $wt['id'] ?>">
											<button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="fa fa-trash"></i></button>
										</form>
									</td>
								</tr>
								<?php endforeach; ?>
								<?php if (empty($wagonTypes)): ?>
								<tr><td colspan="10" class="text-center text-muted py-3">No wagon types defined.</td></tr>
								<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>

			<!-- ══════ RAIL LINES TAB ══════ -->
			<div class="tab-pane fade" id="tab-rails">
				<div class="card bg-dark border-secondary">
					<div class="card-header border-secondary"><h5 class="mb-0">🛤️ Rail Network (<?= count($railLines) ?> segments)</h5></div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-dark table-hover table-sm mb-0">
								<thead><tr>
									<th>From</th><th>To</th><th>Distance</th><th>Rail Type</th><th>Speed Limit</th><th>Actions</th>
								</tr></thead>
								<tbody>
								<?php foreach ($railLines as $rl):
									$typeBadge = $rl['rail_type'] === 'electrified'
										? '<span class="badge bg-info">⚡ Electrified</span>'
										: '<span class="badge bg-secondary">🟫 Basic</span>';
								?>
								<tr>
									<td><?= htmlspecialchars($rl['town1_name']) ?></td>
									<td><?= htmlspecialchars($rl['town2_name']) ?></td>
									<td><?= $rl['distance_km'] ? number_format($rl['distance_km'], 1) . ' km' : '?' ?></td>
									<td><?= $typeBadge ?></td>
									<td><?= $rl['speed_limit'] ?> km/h</td>
									<td>
										<?php if ($rl['rail_type'] === 'basic'): ?>
										<form method="post" class="d-inline" onsubmit="return confirm('Electrify this rail line?')">
											<input type="hidden" name="admin_action" value="upgrade_rail">
											<input type="hidden" name="rail_id" value="<?= $rl['id'] ?>">
											<button class="btn btn-sm btn-outline-info py-0 px-1" title="Electrify">⚡ Electrify</button>
										</form>
										<?php else: ?>
										<span class="text-muted small">Max level</span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>
			</div>
		</div>
		<!-- END #content -->

<script>
function addCostRow(containerId) {
    const container = document.getElementById(containerId);
    const row = container.querySelector('.cost-row').cloneNode(true);
    row.querySelectorAll('input, select').forEach(el => el.value = '');
    container.appendChild(row);
}
function toggleFuel() {
    const prop = document.getElementById('loco-propulsion').value;
    document.getElementById('fuel-per-km-group').style.display = prop === 'electric' ? 'none' : '';
    document.getElementById('fuel-res-group').style.display = prop === 'electric' ? 'none' : '';
}
</script>

<?php
include __DIR__ . "/../files/scripts.php";
$conn->close();
?>
