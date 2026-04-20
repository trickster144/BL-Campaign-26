<?php
// vehicles.php — Fleet management page: view all vehicles, filter by town/type/status
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);
$factionSQL = "'" . implode("','", $factions) . "'";

// ── GET FILTER PARAMS ──
$filterTown   = isset($_GET['town'])   ? (int)$_GET['town']   : 0;
$filterType   = isset($_GET['type'])   ? (int)$_GET['type']   : 0;
$filterStatus = $_GET['status'] ?? '';
$filterClass  = $_GET['class']  ?? '';

// ── LOAD ALL VEHICLES ──
$vehicles = [];
$vRes = $conn->query("
    SELECT v.id, v.town_id, v.faction, v.status, v.health, v.created_at,
           vt.id AS type_id, vt.name AS type_name, vt.category, vt.vehicle_class,
           vt.max_speed_kmh, vt.max_capacity, vt.cargo_class,
           t.name AS town_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    LEFT JOIN towns t ON v.town_id = t.id
    WHERE v.faction IN ($factionSQL)
    ORDER BY v.status, vt.name, v.id
");
if ($vRes) { while ($r = $vRes->fetch_assoc()) $vehicles[] = $r; }

// ── LOAD ACTIVE TRIPS (for in-transit vehicles) ──
$activeTrips = []; // vehicle_id => trip data
$atRes = $conn->query("
    SELECT vt.vehicle_id, vt.from_town_id, vt.to_town_id, vt.departed_at, vt.eta_at,
           vt.distance_km, vt.speed_kmh, vt.cargo_resource_id, vt.cargo_amount,
           vt.return_empty, vt.tow_vehicle_id,
           ft.name AS from_name, tt.name AS to_name,
           wp.resource_name AS cargo_name
    FROM vehicle_trips vt
    JOIN vehicles v ON vt.vehicle_id = v.id
    JOIN towns ft ON vt.from_town_id = ft.id
    JOIN towns tt ON vt.to_town_id = tt.id
    LEFT JOIN world_prices wp ON vt.cargo_resource_id = wp.id
    WHERE vt.arrived = 0 AND v.faction IN ($factionSQL)
    ORDER BY vt.eta_at ASC
");
if ($atRes) { while ($at = $atRes->fetch_assoc()) $activeTrips[(int)$at['vehicle_id']] = $at; }

// ── LOAD REPAIR INFO ──
$repairs = []; // vehicle_id => repair data
$rpRes = $conn->query("
    SELECT vr.vehicle_id, vr.start_health, vr.target_health, vr.cost_total, vr.started_at
    FROM vehicle_repairs vr
    JOIN vehicles v ON vr.vehicle_id = v.id
    WHERE v.faction IN ($factionSQL)
");
if ($rpRes) { while ($rp = $rpRes->fetch_assoc()) $repairs[(int)$rp['vehicle_id']] = $rp; }

// ── FILTER OPTIONS ──
$allTowns = [];
$tRes = $conn->query("SELECT id, name FROM towns WHERE side IN ($factionSQL) ORDER BY name");
if ($tRes) { while ($t = $tRes->fetch_assoc()) $allTowns[(int)$t['id']] = $t['name']; }

$allTypes = [];
$vtRes = $conn->query("SELECT id, name, vehicle_class FROM vehicle_types WHERE active = 1 ORDER BY name");
if ($vtRes) { while ($vt = $vtRes->fetch_assoc()) $allTypes[(int)$vt['id']] = $vt; }

$statusLabels = [
    'idle' => ['Idle', 'bg-success'],
    'in_transit' => ['In Transit', 'bg-primary'],
    'building' => ['Building', 'bg-secondary'],
    'loading' => ['Loading', 'bg-info'],
    'repairing' => ['Repairing', 'bg-warning text-dark'],
    'scrapped' => ['Scrapped', 'bg-danger'],
    'towing' => ['Towing', 'bg-purple'],
];

$classLabels = [
    'cargo' => '🚛 Cargo',
    'passenger' => '🚌 Passenger',
    'tow' => '🔧 Tow',
];

// ── APPLY FILTERS ──
$filtered = array_filter($vehicles, function($v) use ($filterTown, $filterType, $filterStatus, $filterClass, $activeTrips) {
    if ($filterStatus && $v['status'] !== $filterStatus) return false;
    if ($filterClass && $v['vehicle_class'] !== $filterClass) return false;
    if ($filterType && (int)$v['type_id'] !== $filterType) return false;
    if ($filterTown) {
        // Match vehicles in this town OR in transit to/from this town
        if ((int)$v['town_id'] === $filterTown) return true;
        $trip = $activeTrips[(int)$v['id']] ?? null;
        if ($trip && ((int)$trip['from_town_id'] === $filterTown || (int)$trip['to_town_id'] === $filterTown)) return true;
        return false;
    }
    return true;
});

// ── STATUS COUNTS ──
$statusCounts = [];
foreach ($vehicles as $v) {
    $s = $v['status'];
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}
$totalVehicles = count($vehicles);
$filteredCount = count($filtered);

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">🚛 Vehicle Fleet</h1>

			<!-- STATS ROW -->
			<div class="row mb-3">
				<?php foreach ($statusLabels as $sKey => $sInfo):
					$cnt = $statusCounts[$sKey] ?? 0;
					if ($cnt === 0 && $sKey === 'scrapped') continue;
				?>
				<div class="col">
					<a href="?status=<?= $sKey ?>" class="text-decoration-none">
						<div class="card <?= $filterStatus === $sKey ? 'border-warning border-2' : 'bg-dark' ?>">
							<div class="card-body text-center py-2">
								<h4 class="mb-0 <?= str_replace('bg-', 'text-', explode(' ', $sInfo[1])[0]) ?>"><?= $cnt ?></h4>
								<small class="text-muted"><?= $sInfo[0] ?></small>
							</div>
						</div>
					</a>
				</div>
				<?php endforeach; ?>
				<div class="col">
					<a href="?" class="text-decoration-none">
						<div class="card <?= !$filterStatus ? 'border-warning border-2' : 'bg-dark' ?>">
							<div class="card-body text-center py-2">
								<h4 class="mb-0 text-white"><?= $totalVehicles ?></h4>
								<small class="text-muted">Total</small>
							</div>
						</div>
					</a>
				</div>
			</div>

			<!-- FILTERS -->
			<div class="card mb-3">
				<div class="card-body py-2">
					<form method="get" class="row g-2 align-items-end">
						<div class="col-auto">
							<label class="form-label small mb-0">Town</label>
							<select name="town" class="form-select form-select-sm" style="min-width:150px">
								<option value="0">All Towns</option>
								<?php foreach ($allTowns as $tid => $tname): ?>
								<option value="<?= $tid ?>" <?= $filterTown === $tid ? 'selected' : '' ?>><?= htmlspecialchars($tname) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-auto">
							<label class="form-label small mb-0">Vehicle Type</label>
							<select name="type" class="form-select form-select-sm" style="min-width:150px">
								<option value="0">All Types</option>
								<?php foreach ($allTypes as $vtid => $vt): ?>
								<option value="<?= $vtid ?>" <?= $filterType === $vtid ? 'selected' : '' ?>><?= htmlspecialchars($vt['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-auto">
							<label class="form-label small mb-0">Status</label>
							<select name="status" class="form-select form-select-sm" style="min-width:120px">
								<option value="">All</option>
								<?php foreach ($statusLabels as $sKey => $sInfo): ?>
								<option value="<?= $sKey ?>" <?= $filterStatus === $sKey ? 'selected' : '' ?>><?= $sInfo[0] ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-auto">
							<label class="form-label small mb-0">Class</label>
							<select name="class" class="form-select form-select-sm" style="min-width:120px">
								<option value="">All</option>
								<?php foreach ($classLabels as $cKey => $cLabel): ?>
								<option value="<?= $cKey ?>" <?= $filterClass === $cKey ? 'selected' : '' ?>><?= $cLabel ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-auto">
							<button type="submit" class="btn btn-sm btn-outline-theme">Filter</button>
							<a href="?" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
						</div>
						<div class="col-auto ms-auto">
							<small class="text-muted">Showing <?= $filteredCount ?> of <?= $totalVehicles ?></small>
						</div>
					</form>
				</div>
			</div>

			<!-- VEHICLE TABLE -->
			<div class="card">
				<div class="table-responsive">
					<table class="table table-sm table-striped table-hover mb-0">
						<thead>
							<tr class="small text-inverse text-opacity-50">
								<th>#</th>
								<th>Vehicle</th>
								<th>Class</th>
								<th>Status</th>
								<th>Health</th>
								<th>Location / Route</th>
								<th>Cargo</th>
								<th>Progress</th>
							</tr>
						</thead>
						<tbody>
						<?php if (empty($filtered)): ?>
							<tr><td colspan="8" class="text-center text-muted py-3">No vehicles match filters</td></tr>
						<?php else: foreach ($filtered as $v):
							$vid = (int)$v['id'];
							$trip = $activeTrips[$vid] ?? null;
							$repair = $repairs[$vid] ?? null;
							$sInfo = $statusLabels[$v['status']] ?? ['?', 'bg-secondary'];
							$hp = (float)$v['health'];

							// Health color
							if ($hp >= 75) $hpClass = 'text-success';
							elseif ($hp >= 50) $hpClass = 'text-warning';
							elseif ($hp >= 10) $hpClass = 'text-danger';
							else $hpClass = 'text-danger fw-bold';

							// Health bar color
							if ($hp >= 75) $hpBar = 'bg-success';
							elseif ($hp >= 50) $hpBar = 'bg-warning';
							elseif ($hp >= 10) $hpBar = 'bg-danger';
							else $hpBar = 'bg-danger';

							// Progress for in-transit
							$progressPct = 0;
							$etaText = '';
							if ($trip) {
								$departed = strtotime($trip['departed_at']);
								$eta = strtotime($trip['eta_at']);
								$now = time();
								$totalSec = max($eta - $departed, 1);
								$elapsed = $now - $departed;
								$progressPct = min(100, max(0, round($elapsed / $totalSec * 100)));
								$remaining = max(0, $eta - $now);
								$mins = floor($remaining / 60);
								$secs = $remaining % 60;
								$etaText = $remaining > 0 ? "{$mins}m {$secs}s" : "Arriving...";
							}
						?>
							<tr>
								<td class="small text-muted"><?= $vid ?></td>
								<td>
									<span class="fw-bold small"><?= htmlspecialchars($v['type_name']) ?></span>
									<?php if ($v['category'] === 'military'): ?>
										<span class="badge bg-danger bg-opacity-25 text-danger ms-1" style="font-size:0.65em">MIL</span>
									<?php endif; ?>
								</td>
								<td class="small"><?= $classLabels[$v['vehicle_class']] ?? $v['vehicle_class'] ?></td>
								<td><span class="badge <?= $sInfo[1] ?>" style="font-size:0.75em"><?= $sInfo[0] ?></span></td>
								<td>
									<div class="d-flex align-items-center gap-1">
										<span class="<?= $hpClass ?> small fw-bold" style="width:35px"><?= number_format($hp, 0) ?>%</span>
										<div class="progress flex-grow-1" style="height:5px; min-width:40px">
											<div class="progress-bar <?= $hpBar ?>" style="width:<?= $hp ?>%"></div>
										</div>
									</div>
								</td>
								<td class="small">
									<?php if ($v['status'] === 'in_transit' && $trip): ?>
										<a href="town_view.php?id=<?= (int)$trip['from_town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($trip['from_name']) ?></a>
										→
										<a href="town_view.php?id=<?= (int)$trip['to_town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($trip['to_name']) ?></a>
										<br><small class="text-muted"><?= number_format((float)$trip['distance_km'], 1) ?> km @ <?= number_format((float)$trip['speed_kmh'], 0) ?> km/h</small>
									<?php elseif ($v['status'] === 'towing' && $trip): ?>
										<span class="text-warning"><i class="bi bi-wrench"></i> Towing</span>
										<a href="town_view.php?id=<?= (int)$trip['from_town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($trip['from_name']) ?></a>
										→
										<a href="town_view.php?id=<?= (int)$trip['to_town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($trip['to_name']) ?></a>
									<?php elseif ($v['town_id']): ?>
										<a href="town_view.php?id=<?= (int)$v['town_id'] ?>" class="text-theme text-decoration-none"><?= htmlspecialchars($v['town_name']) ?></a>
									<?php else: ?>
										<span class="text-muted">—</span>
									<?php endif; ?>
								</td>
								<td class="small">
									<?php if ($trip && !$trip['return_empty'] && $trip['cargo_name']): ?>
										<?= htmlspecialchars($trip['cargo_name']) ?>
										<span class="text-muted"><?= number_format((float)$trip['cargo_amount'], 1) ?>t</span>
									<?php elseif ($trip && $trip['return_empty']): ?>
										<span class="text-muted fst-italic">Empty</span>
									<?php elseif ($trip && $trip['tow_vehicle_id']): ?>
										<span class="text-warning">Towing #<?= (int)$trip['tow_vehicle_id'] ?></span>
									<?php elseif ($v['status'] === 'repairing' && $repair): ?>
										<span class="text-warning small">
											<?= number_format((float)$repair['start_health'], 0) ?>%→<?= number_format((float)$repair['target_health'], 0) ?>%
											<span class="text-muted">(<?= number_format((float)$repair['cost_total'], 0) ?> pts)</span>
										</span>
									<?php else: ?>
										<span class="text-muted">—</span>
									<?php endif; ?>
								</td>
								<td style="min-width:100px">
									<?php if ($trip): ?>
										<div class="d-flex align-items-center gap-1">
											<div class="progress flex-grow-1" style="height:6px">
												<div class="progress-bar bg-primary" style="width:<?= $progressPct ?>%"></div>
											</div>
											<small class="text-muted" style="white-space:nowrap"><?= $etaText ?></small>
										</div>
									<?php elseif ($v['status'] === 'repairing' && $repair): ?>
										<?php
											$repairPct = 0;
											$startH = (float)$repair['start_health'];
											$targetH = (float)$repair['target_health'];
											$totalRepair = $targetH - $startH;
											if ($totalRepair > 0) {
												$repaired = $hp - $startH;
												$repairPct = min(100, max(0, round($repaired / $totalRepair * 100)));
											}
										?>
										<div class="d-flex align-items-center gap-1">
											<div class="progress flex-grow-1" style="height:6px">
												<div class="progress-bar bg-warning" style="width:<?= $repairPct ?>%"></div>
											</div>
											<small class="text-warning"><?= $repairPct ?>%</small>
										</div>
									<?php elseif ($v['status'] === 'building'): ?>
										<span class="badge bg-secondary" style="font-size:0.7em">Under construction</span>
									<?php else: ?>
										<span class="text-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>
		<!-- END #content -->
<?php include "files/scripts.php"; ?>
