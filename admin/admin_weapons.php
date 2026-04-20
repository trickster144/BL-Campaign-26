<?php
// admin/admin_weapons.php — Admin page for managing weapon types
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

$msg = $_GET['msg'] ?? '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['admin_action'] ?? '';

    if ($act === 'add_weapon') {
        $name = trim($_POST['name'] ?? '');
        $attack = (int)($_POST['attack_stat'] ?? 10);
        $defense = (int)($_POST['defense_stat'] ?? 5);
        $buildTicks = (int)($_POST['build_time_ticks'] ?? 6);

        if ($name) {
            $stmt = $conn->prepare("INSERT INTO weapon_types (name, attack_stat, defense_stat, build_time_ticks) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("siii", $name, $attack, $defense, $buildTicks);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            // Save build costs
            if (!empty($_POST['cost_resource']) && is_array($_POST['cost_resource'])) {
                $costStmt = $conn->prepare("INSERT INTO weapon_build_costs (weapon_type_id, resource_id, amount) VALUES (?, ?, ?)");
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
            header("Location: admin_weapons.php?msg=added");
            exit;
        }
    }

    if ($act === 'edit_weapon') {
        $wid = (int)($_POST['weapon_type_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $attack = (int)($_POST['attack_stat'] ?? 10);
        $defense = (int)($_POST['defense_stat'] ?? 5);
        $buildTicks = (int)($_POST['build_time_ticks'] ?? 6);

        if ($wid > 0 && $name) {
            $stmt = $conn->prepare("UPDATE weapon_types SET name = ?, attack_stat = ?, defense_stat = ?, build_time_ticks = ? WHERE id = ?");
            $stmt->bind_param("siiii", $name, $attack, $defense, $buildTicks, $wid);
            $stmt->execute();
            $stmt->close();

            // Replace build costs
            $conn->query("DELETE FROM weapon_build_costs WHERE weapon_type_id = $wid");
            if (!empty($_POST['cost_resource']) && is_array($_POST['cost_resource'])) {
                $costStmt = $conn->prepare("INSERT INTO weapon_build_costs (weapon_type_id, resource_id, amount) VALUES (?, ?, ?)");
                foreach ($_POST['cost_resource'] as $i => $crid) {
                    $camt = (float)($_POST['cost_amount'][$i] ?? 0);
                    if ((int)$crid > 0 && $camt > 0) {
                        $cridInt = (int)$crid;
                        $costStmt->bind_param("iid", $wid, $cridInt, $camt);
                        $costStmt->execute();
                    }
                }
                $costStmt->close();
            }
            header("Location: admin_weapons.php?msg=updated");
            exit;
        }
    }

    if ($act === 'delete_weapon') {
        $wid = (int)($_POST['weapon_type_id'] ?? 0);
        if ($wid > 0) {
            $conn->query("DELETE FROM weapon_build_costs WHERE weapon_type_id = $wid");
            $conn->query("DELETE FROM weapon_types WHERE id = $wid");
            header("Location: admin_weapons.php?msg=deleted");
            exit;
        }
    }

    if ($act === 'toggle_weapon') {
        $wid = (int)($_POST['weapon_type_id'] ?? 0);
        if ($wid > 0) {
            $conn->query("UPDATE weapon_types SET active = NOT active WHERE id = $wid");
            header("Location: admin_weapons.php?msg=toggled");
            exit;
        }
    }
}

// Load all weapon types
$weaponTypes = [];
$wtRes = $conn->query("SELECT * FROM weapon_types ORDER BY name");
if ($wtRes) { while ($w = $wtRes->fetch_assoc()) $weaponTypes[] = $w; }

// Load build costs per weapon type
$buildCosts = [];
$bcRes = $conn->query("SELECT wbc.*, wp.resource_name, wp.image_file FROM weapon_build_costs wbc JOIN world_prices wp ON wbc.resource_id = wp.id ORDER BY wp.resource_name");
if ($bcRes) { while ($bc = $bcRes->fetch_assoc()) $buildCosts[(int)$bc['weapon_type_id']][] = $bc; }

// All resources for dropdowns
$allResources = [];
$arRes = $conn->query("SELECT id, resource_name FROM world_prices ORDER BY resource_name");
if ($arRes) { while ($ar = $arRes->fetch_assoc()) $allResources[] = $ar; }

include __DIR__ . "/../files/header.php";
include __DIR__ . "/../files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">⚔️ Admin: Weapon Types</h1>

			<?php if ($msg): ?>
			<div class="alert alert-success alert-dismissible fade show">
				✅ <?= $msg === 'added' ? 'Weapon type added!' : ($msg === 'deleted' ? 'Weapon type deleted!' : ($msg === 'updated' ? 'Weapon type updated!' : 'Weapon type toggled!')) ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
			<?php endif; ?>

			<!-- Add Weapon Form -->
			<div class="card bg-dark border-danger mb-4">
				<div class="card-header border-danger"><h5 class="mb-0">➕ Add New Weapon Type</h5></div>
				<div class="card-body">
					<form method="post">
						<input type="hidden" name="admin_action" value="add_weapon">
						<div class="row g-3">
							<div class="col-md-3">
								<label class="form-label small">Weapon Name</label>
								<input type="text" name="name" class="form-control" required placeholder="e.g. Assault Rifle">
							</div>
							<div class="col-md-2">
								<label class="form-label small">⚔️ Attack Stat</label>
								<input type="number" name="attack_stat" class="form-control" value="10" min="0">
							</div>
							<div class="col-md-2">
								<label class="form-label small">🛡️ Defense Stat</label>
								<input type="number" name="defense_stat" class="form-control" value="5" min="0">
							</div>
							<div class="col-md-2">
								<label class="form-label small">Build Time (ticks)</label>
								<input type="number" name="build_time_ticks" class="form-control" value="6" min="1">
								<small class="text-muted">12 ticks = 1 hour</small>
							</div>
						</div>

						<!-- Build costs -->
						<div class="mt-3">
							<h6 class="text-danger">Build Costs (resources per weapon)</h6>
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
							<button type="button" class="btn btn-sm btn-outline-danger" onclick="addCostRow()">+ Add Resource Cost</button>
						</div>

						<div class="mt-3">
							<button type="submit" class="btn btn-danger">Add Weapon Type</button>
						</div>
					</form>
				</div>
			</div>

			<!-- Existing Weapon Types -->
			<div class="card bg-dark border-secondary">
				<div class="card-header border-secondary"><h5 class="mb-0">🗡️ Existing Weapon Types (<?= count($weaponTypes) ?>)</h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>ID</th><th>Name</th><th>⚔️ Attack</th><th>🛡️ Defense</th>
									<th>Build Time</th><th>Build Costs</th><th>Status</th><th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($weaponTypes as $wt):
								$costs = $buildCosts[(int)$wt['id']] ?? [];
								$costStr = '';
								foreach ($costs as $c) $costStr .= number_format($c['amount'], 2) . 't ' . $c['resource_name'] . ', ';
								$costStr = $costStr ? rtrim($costStr, ', ') : '<span class="text-muted">None set</span>';
							?>
								<tr class="<?= $wt['active'] ? '' : 'text-muted' ?>">
									<td><?= $wt['id'] ?></td>
									<td class="fw-bold"><?= htmlspecialchars($wt['name']) ?></td>
									<td><span class="text-danger fw-bold"><?= $wt['attack_stat'] ?></span></td>
									<td><span class="text-info fw-bold"><?= $wt['defense_stat'] ?></span></td>
									<td><?= $wt['build_time_ticks'] ?> ticks (<?= round($wt['build_time_ticks'] * 5) ?> min)</td>
									<td class="small"><?= $costStr ?></td>
									<td>
										<?php if ($wt['active']): ?>
											<span class="badge bg-success">Active</span>
										<?php else: ?>
											<span class="badge bg-secondary">Disabled</span>
										<?php endif; ?>
									</td>
									<td>
										<!-- Edit button triggers modal -->
										<button type="button" class="btn btn-sm btn-outline-info py-0 px-1" title="Edit"
											data-bs-toggle="modal" data-bs-target="#editModal<?= $wt['id'] ?>">
											<i class="fa fa-edit"></i>
										</button>
										<form method="post" class="d-inline">
											<input type="hidden" name="admin_action" value="toggle_weapon">
											<input type="hidden" name="weapon_type_id" value="<?= $wt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-warning py-0 px-1" title="Toggle active">
												<i class="fa fa-power-off"></i>
											</button>
										</form>
										<form method="post" class="d-inline" onsubmit="return confirm('Delete this weapon type? This cannot be undone.')">
											<input type="hidden" name="admin_action" value="delete_weapon">
											<input type="hidden" name="weapon_type_id" value="<?= $wt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete">
												<i class="fa fa-trash"></i>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if (empty($weaponTypes)): ?>
								<tr><td colspan="8" class="text-center text-muted py-3">No weapon types defined. Add one above.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Edit Modals -->
			<?php foreach ($weaponTypes as $wt):
				$wCosts = $buildCosts[(int)$wt['id']] ?? [];
			?>
			<div class="modal fade" id="editModal<?= $wt['id'] ?>" tabindex="-1">
				<div class="modal-dialog modal-lg">
					<div class="modal-content bg-dark">
						<form method="post">
							<input type="hidden" name="admin_action" value="edit_weapon">
							<input type="hidden" name="weapon_type_id" value="<?= $wt['id'] ?>">
							<div class="modal-header border-danger">
								<h5 class="modal-title">Edit: <?= htmlspecialchars($wt['name']) ?></h5>
								<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
							</div>
							<div class="modal-body">
								<div class="row g-3">
									<div class="col-md-3">
										<label class="form-label small">Name</label>
										<input type="text" name="name" class="form-control" value="<?= htmlspecialchars($wt['name']) ?>" required>
									</div>
									<div class="col-md-2">
										<label class="form-label small">⚔️ Attack</label>
										<input type="number" name="attack_stat" class="form-control" value="<?= $wt['attack_stat'] ?>" min="0">
									</div>
									<div class="col-md-2">
										<label class="form-label small">🛡️ Defense</label>
										<input type="number" name="defense_stat" class="form-control" value="<?= $wt['defense_stat'] ?>" min="0">
									</div>
									<div class="col-md-2">
										<label class="form-label small">Build Ticks</label>
										<input type="number" name="build_time_ticks" class="form-control" value="<?= $wt['build_time_ticks'] ?>" min="1">
									</div>
								</div>
								<div class="mt-3">
									<h6 class="text-danger">Build Costs</h6>
									<div id="edit-costs-<?= $wt['id'] ?>">
										<?php if (empty($wCosts)): ?>
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
												<input type="number" name="cost_amount[]" class="form-control form-control-sm" placeholder="Amount" step="0.01" min="0">
											</div>
										</div>
										<?php else: ?>
										<?php foreach ($wCosts as $wc): ?>
										<div class="row g-2 mb-2 cost-row">
											<div class="col-md-5">
												<select name="cost_resource[]" class="form-select form-select-sm">
													<option value="">Select resource...</option>
													<?php foreach ($allResources as $ar): ?>
													<option value="<?= $ar['id'] ?>" <?= (int)$ar['id'] === (int)$wc['resource_id'] ? 'selected' : '' ?>><?= htmlspecialchars($ar['resource_name']) ?></option>
													<?php endforeach; ?>
												</select>
											</div>
											<div class="col-md-3">
												<input type="number" name="cost_amount[]" class="form-control form-control-sm" value="<?= $wc['amount'] ?>" step="0.01" min="0">
											</div>
										</div>
										<?php endforeach; ?>
										<?php endif; ?>
									</div>
									<button type="button" class="btn btn-sm btn-outline-danger" onclick="addEditCostRow(<?= $wt['id'] ?>)">+ Add Cost</button>
								</div>
							</div>
							<div class="modal-footer border-danger">
								<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
								<button type="submit" class="btn btn-danger">Save Changes</button>
							</div>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<!-- END #content -->

<script>
function addCostRow() {
    const container = document.getElementById('cost-rows');
    const row = container.querySelector('.cost-row').cloneNode(true);
    row.querySelectorAll('input, select').forEach(el => el.value = '');
    container.appendChild(row);
}
function addEditCostRow(wid) {
    const container = document.getElementById('edit-costs-' + wid);
    const row = container.querySelector('.cost-row').cloneNode(true);
    row.querySelectorAll('input, select').forEach(el => el.value = '');
    container.appendChild(row);
}
</script>

<?php
include __DIR__ . "/../files/scripts.php";
$conn->close();
?>
