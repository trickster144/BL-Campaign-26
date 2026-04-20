<?php
// transit.php — View all vehicles in transit
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);
$factionSQL = "'" . implode("','", $factions) . "'";

// Use DB time for all progress calculations (PHP timezone may differ from MySQL)
$dbNowRes = $conn->query("SELECT UNIX_TIMESTAMP(NOW()) as db_ts");
$dbNow = $dbNowRes ? (int)$dbNowRes->fetch_assoc()['db_ts'] : time();

// All active trips
$trips = [];
$tRes = $conn->query("
    SELECT vt.id as trip_id, vt.departed_at, vt.eta_at, vt.distance_km, vt.speed_kmh,
           vt.fuel_used, vt.return_empty, vt.arrived,
           UNIX_TIMESTAMP(vt.departed_at) as dep_ts, UNIX_TIMESTAMP(vt.eta_at) as eta_ts,
           v.id as vehicle_id, v.faction, vtype.name as vehicle_name, vtype.category,
           ft.name as from_name, ft.id as from_id, tt.name as to_name, tt.id as to_id
    FROM vehicle_trips vt
    JOIN vehicles v ON vt.vehicle_id = v.id
    JOIN vehicle_types vtype ON v.vehicle_type_id = vtype.id
    JOIN towns ft ON vt.from_town_id = ft.id
    JOIN towns tt ON vt.to_town_id = tt.id
    WHERE vt.arrived = 0 AND vt.eta_at > NOW() AND v.faction IN ($factionSQL)
    ORDER BY vt.eta_at ASC
");
if ($tRes) { while ($t = $tRes->fetch_assoc()) $trips[] = $t; }

// Trips past ETA but not yet processed by tick engine
$pendingArrivals = [];
$paRes = $conn->query("
    SELECT vt.id as trip_id, v.id as vehicle_id, vtype.name as vehicle_name,
           ft.name as from_name, tt.name as to_name
    FROM vehicle_trips vt
    JOIN vehicles v ON vt.vehicle_id = v.id
    JOIN vehicle_types vtype ON v.vehicle_type_id = vtype.id
    JOIN towns ft ON vt.from_town_id = ft.id
    JOIN towns tt ON vt.to_town_id = tt.id
    WHERE vt.arrived = 0 AND vt.eta_at <= NOW() AND v.faction IN ($factionSQL)
    ORDER BY vt.eta_at ASC
");
if ($paRes) { while ($pa = $paRes->fetch_assoc()) $pendingArrivals[] = $pa; }

// Recent completed trips (last 20)
$completed = [];
$cRes = $conn->query("
    SELECT vt.id as trip_id, vt.departed_at, vt.eta_at, vt.distance_km, vt.speed_kmh,
           vt.fuel_used, vt.arrived_at,
           vtype.name as vehicle_name,
           ft.name as from_name, tt.name as to_name
    FROM vehicle_trips vt
    JOIN vehicles v ON vt.vehicle_id = v.id
    JOIN vehicle_types vtype ON v.vehicle_type_id = vtype.id
    JOIN towns ft ON vt.from_town_id = ft.id
    JOIN towns tt ON vt.to_town_id = tt.id
    WHERE vt.arrived = 1 AND v.faction IN ($factionSQL)
    ORDER BY vt.arrived_at DESC
    LIMIT 20
");
if ($cRes) { while ($c = $cRes->fetch_assoc()) $completed[] = $c; }

// Load cargo details for all displayed trips
$allTripCargo = [];
$allTripIds = array_merge(array_column($trips, 'trip_id'), array_column($completed, 'trip_id'));
if (!empty($allTripIds)) {
    $tripIdsStr = implode(',', array_map('intval', $allTripIds));
    $tcRes = $conn->query("SELECT vtc.trip_id, vtc.amount, wp.resource_name
        FROM vehicle_trip_cargo vtc
        JOIN world_prices wp ON vtc.resource_id = wp.id
        WHERE vtc.trip_id IN ($tripIdsStr)
        ORDER BY wp.resource_name");
    if ($tcRes) { while ($tc = $tcRes->fetch_assoc()) $allTripCargo[(int)$tc['trip_id']][] = $tc; }
}

// Count vehicles by status
$statusCounts = [];
$scRes = $conn->query("SELECT status, COUNT(*) as cnt FROM vehicles WHERE faction IN ($factionSQL) GROUP BY status");
if ($scRes) { while ($sc = $scRes->fetch_assoc()) $statusCounts[$sc['status']] = (int)$sc['cnt']; }

// ── TRAIN DATA ──
$trainTrips = [];
$ttRes = $conn->query("
    SELECT tt.id as trip_id, tt.departed_at, tt.eta_at, tt.distance_km, tt.speed_kmh,
           tt.fuel_used, tt.return_empty, tt.arrived,
           UNIX_TIMESTAMP(tt.departed_at) as dep_ts, UNIX_TIMESTAMP(tt.eta_at) as eta_ts,
           t.id as train_id, t.name as train_name, t.faction,
           lt.name as loco_name, lt.propulsion,
           ft.name as from_name, ft.id as from_id, tto.name as to_name, tto.id as to_id
    FROM train_trips tt
    JOIN trains t ON tt.train_id = t.id
    JOIN locomotives l ON t.locomotive_id = l.id
    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
    JOIN towns ft ON tt.from_town_id = ft.id
    JOIN towns tto ON tt.to_town_id = tto.id
    WHERE tt.arrived = 0 AND tt.eta_at > NOW() AND t.faction IN ($factionSQL)
    ORDER BY tt.eta_at ASC
");
if ($ttRes) { while ($tt = $ttRes->fetch_assoc()) $trainTrips[] = $tt; }

// Train trips past ETA but not yet processed
$pendingTrainArrivals = [];
$ptaRes = $conn->query("
    SELECT tt.id as trip_id, t.name as train_name,
           ft.name as from_name, tto.name as to_name
    FROM train_trips tt
    JOIN trains t ON tt.train_id = t.id
    JOIN towns ft ON tt.from_town_id = ft.id
    JOIN towns tto ON tt.to_town_id = tto.id
    WHERE tt.arrived = 0 AND tt.eta_at <= NOW() AND t.faction IN ($factionSQL)
    ORDER BY tt.eta_at ASC
");
if ($ptaRes) { while ($pta = $ptaRes->fetch_assoc()) $pendingTrainArrivals[] = $pta; }

$completedTrains = [];
$ctRes = $conn->query("
    SELECT tt.id as trip_id, tt.departed_at, tt.eta_at, tt.distance_km, tt.speed_kmh,
           tt.fuel_used, tt.arrived_at,
           t.name as train_name, lt.name as loco_name, lt.propulsion,
           ft.name as from_name, tto.name as to_name
    FROM train_trips tt
    JOIN trains t ON tt.train_id = t.id
    JOIN locomotives l ON t.locomotive_id = l.id
    JOIN locomotive_types lt ON l.locomotive_type_id = lt.id
    JOIN towns ft ON tt.from_town_id = ft.id
    JOIN towns tto ON tt.to_town_id = tto.id
    WHERE tt.arrived = 1 AND t.faction IN ($factionSQL)
    ORDER BY tt.arrived_at DESC
    LIMIT 20
");
if ($ctRes) { while ($ct = $ctRes->fetch_assoc()) $completedTrains[] = $ct; }

// Load train trip cargo
$allTrainTripCargo = [];
$allTrainTripIds = array_merge(array_column($trainTrips, 'trip_id'), array_column($completedTrains, 'trip_id'));
if (!empty($allTrainTripIds)) {
    $ttIdsStr = implode(',', array_map('intval', $allTrainTripIds));
    $ttcRes = $conn->query("SELECT ttc.trip_id, ttc.amount, wp.resource_name
        FROM train_trip_cargo ttc JOIN world_prices wp ON ttc.resource_id = wp.id
        WHERE ttc.trip_id IN ($ttIdsStr)
        ORDER BY wp.resource_name");
    if ($ttcRes) { while ($tc = $ttcRes->fetch_assoc()) $allTrainTripCargo[(int)$tc['trip_id']][] = $tc; }
}

// Count trains by status
$trainStatusCounts = [];
$tscRes = $conn->query("SELECT status, COUNT(*) as cnt FROM trains WHERE faction IN ($factionSQL) GROUP BY status");
if ($tscRes) { while ($tsc = $tscRes->fetch_assoc()) $trainStatusCounts[$tsc['status']] = (int)$tsc['cnt']; }

$activeTab = $_GET['tab'] ?? 'vehicles';

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">🚛 Transit Network <small class="text-muted fs-6">Auto-refresh in <span id="refresh-countdown">30</span>s</small></h1>

			<?php
			$msg = $_GET['msg'] ?? '';
			if ($msg === 'recalled'): ?>
				<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-rotate-left me-2"></i>Vehicle recalled — returning to origin with cargo.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'recalled_instant'): ?>
				<div class="alert alert-success alert-dismissible fade show"><i class="fa fa-rotate-left me-2"></i>Vehicle recalled instantly (barely departed). Cargo returned to origin.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'trip_not_found'): ?>
				<div class="alert alert-warning alert-dismissible fade show">Trip not found or already arrived.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'denied'): ?>
				<div class="alert alert-danger alert-dismissible fade show">You don't have permission to recall that vehicle.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php endif; ?>

			<!-- TABS -->
			<ul class="nav nav-tabs mb-3" role="tablist">
				<li class="nav-item"><a class="nav-link <?= $activeTab === 'vehicles' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-vehicles">🚛 Vehicles (<?= count($trips) ?> active)</a></li>
				<li class="nav-item"><a class="nav-link <?= $activeTab === 'trains' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-trains">🚂 Trains (<?= count($trainTrips) ?> active)</a></li>
			</ul>

			<div class="tab-content">
			<!-- ══════ VEHICLES TAB ══════ -->
			<div class="tab-pane fade <?= $activeTab === 'vehicles' ? 'show active' : '' ?>" id="tab-vehicles">

			<!-- Stats Row -->
			<div class="row mb-3">
				<div class="col-md-3">
					<div class="card bg-dark border-info">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-info"><?= count($trips) ?></h4>
							<small class="text-muted">In Transit</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-success">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-success"><?= $statusCounts['idle'] ?? 0 ?></h4>
							<small class="text-muted">Idle Vehicles</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-warning">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-warning"><?= $statusCounts['in_transit'] ?? 0 ?></h4>
							<small class="text-muted">Driving</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-secondary">
						<div class="card-body text-center py-2">
							<h4 class="mb-0"><?= array_sum($statusCounts) ?></h4>
							<small class="text-muted">Total Vehicles</small>
						</div>
					</div>
				</div>
			</div>

			<!-- Active Trips -->
			<div class="card mb-3">
				<div class="card-header fw-bold"><i class="fa fa-route me-2"></i>Active Trips</div>
				<div class="card-body p-0">
					<?php if (!empty($trips)): ?>
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>Vehicle</th>
									<th>Faction</th>
									<th>From → To</th>
									<th>Cargo</th>
									<th>Speed</th>
									<th>Distance</th>
									<th>Progress</th>
									<th>ETA</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($trips as $trip):
								$now = $dbNow;
								$eta = (int)$trip['eta_ts'];
								$dep = (int)$trip['dep_ts'];
								$total = max($eta - $dep, 1);
								$elapsed = $now - $dep;
								$pct = min(100, max(0, round(($elapsed / $total) * 100)));
								$remaining = max(0, $eta - $now);
								$mins = floor($remaining / 60);
								$secs = $remaining % 60;
								$factionBadge = $trip['faction'] === 'red' ? '<span class="badge bg-danger">Red</span>' : '<span class="badge bg-primary">Blue</span>';
								$catBadge = $trip['category'] === 'military' ? '<span class="badge bg-danger ms-1">MIL</span>' : '';
							?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars($trip['vehicle_name']) ?> #<?= $trip['vehicle_id'] ?> <?= $catBadge ?></td>
									<td><?= $factionBadge ?></td>
									<td>
										<a href="town_view.php?id=<?= $trip['from_id'] ?>" class="text-theme"><?= htmlspecialchars($trip['from_name']) ?></a>
										→ <a href="town_view.php?id=<?= $trip['to_id'] ?>" class="text-theme"><?= htmlspecialchars($trip['to_name']) ?></a>
										<?php if ($trip['return_empty']): ?><span class="badge bg-info ms-1">Leg 1</span><?php endif; ?>
									</td>
									<td>
										<?php
											$tripCargoItems = $allTripCargo[(int)$trip['trip_id']] ?? [];
											if (!empty($tripCargoItems)):
												echo implode(', ', array_map(fn($c) => number_format($c['amount'],1) . 't ' . htmlspecialchars($c['resource_name']), $tripCargoItems));
											else:
										?>
											<span class="text-muted">Empty</span>
										<?php endif; ?>
									</td>
									<td><?= $trip['speed_kmh'] ?> km/h</td>
									<td><?= number_format($trip['distance_km'], 1) ?> km</td>
									<td style="min-width: 150px;">
										<div class="progress" style="height: 18px;">
											<div class="progress-bar <?= $pct >= 90 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : 'bg-warning') ?>"
												 style="width: <?= $pct ?>%">
												<?= $pct ?>%
											</div>
										</div>
									</td>
									<td class="fw-bold">
										<?php if ($pct >= 100): ?>
											<span class="text-success"><i class="fa fa-check-circle me-1"></i>Arriving…</span>
										<?php else: ?>
											<?= $mins ?>m <?= $secs ?>s
										<?php endif; ?>
									</td>
									<td>
										<?php if ($pct < 95): ?>
										<form method="POST" action="transit_action.php" class="d-inline" onsubmit="return confirm('Recall this vehicle? It will turn around and return cargo to origin.')">
											<input type="hidden" name="action" value="recall_vehicle">
											<input type="hidden" name="trip_id" value="<?= $trip['trip_id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-warning" title="Recall vehicle"><i class="fa fa-rotate-left"></i> Recall</button>
										</form>
										<?php else: ?>
											<span class="text-muted">—</span>
										<?php endif; ?>
									</td>
								</tr>
								<?php if ($trip['return_empty']): ?>
								<tr class="opacity-50">
									<td class="fw-bold"><?= htmlspecialchars($trip['vehicle_name']) ?> #<?= $trip['vehicle_id'] ?> <?= $catBadge ?></td>
									<td><?= $factionBadge ?></td>
									<td>
										<a href="town_view.php?id=<?= $trip['to_id'] ?>" class="text-theme"><?= htmlspecialchars($trip['to_name']) ?></a>
										→ <a href="town_view.php?id=<?= $trip['from_id'] ?>" class="text-theme"><?= htmlspecialchars($trip['from_name']) ?></a>
										<span class="badge bg-secondary ms-1">Leg 2</span>
									</td>
									<td><span class="text-muted">Empty</span></td>
									<td><?= $trip['speed_kmh'] ?> km/h</td>
									<td><?= number_format($trip['distance_km'], 1) ?> km</td>
									<td style="min-width: 150px;" colspan="3">
										<span class="text-warning"><i class="fa fa-hourglass-half me-1"></i>Waiting for outbound leg</span>
									</td>
								</tr>
								<?php endif; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php else: ?>
						<div class="text-center text-muted py-4">No vehicles currently in transit.</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if (!empty($pendingArrivals)): ?>
			<!-- Vehicles Arriving (past ETA, awaiting next tick) -->
			<div class="card mb-3 border-success">
				<div class="card-header fw-bold text-success"><i class="fa fa-check-double me-2"></i>Arriving (<?= count($pendingArrivals) ?>)</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr><th>Vehicle</th><th>Route</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ($pendingArrivals as $pa): ?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars($pa['vehicle_name']) ?> #<?= $pa['vehicle_id'] ?></td>
									<td><?= htmlspecialchars($pa['from_name']) ?> → <?= htmlspecialchars($pa['to_name']) ?></td>
									<td><span class="badge bg-success">Arrived — awaiting unload</span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Recent Completed -->
			<div class="card mb-3">
				<div class="card-header fw-bold"><i class="fa fa-check-circle me-2"></i>Recent Completed Trips (last 20)</div>
				<div class="card-body p-0">
					<?php if (!empty($completed)): ?>
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>Vehicle</th>
									<th>From → To</th>
									<th>Cargo</th>
									<th>Distance</th>
									<th>Speed</th>
									<th>Departed</th>
									<th>Arrived</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($completed as $c): ?>
								<tr>
									<td><?= htmlspecialchars($c['vehicle_name']) ?></td>
									<td><?= htmlspecialchars($c['from_name']) ?> → <?= htmlspecialchars($c['to_name']) ?></td>
									<td>
										<?php
											$cCargoItems = $allTripCargo[(int)$c['trip_id']] ?? [];
											if (!empty($cCargoItems)):
												echo implode(', ', array_map(fn($ci) => number_format($ci['amount'],1) . 't ' . htmlspecialchars($ci['resource_name']), $cCargoItems));
											else:
										?>
											<span class="text-muted">Empty</span>
										<?php endif; ?>
									</td>
									<td><?= number_format($c['distance_km'], 1) ?> km</td>
									<td><?= $c['speed_kmh'] ?> km/h</td>
									<td class="small"><?= $c['departed_at'] ?></td>
									<td class="small"><?= $c['arrived_at'] ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php else: ?>
						<div class="text-center text-muted py-4">No completed trips yet.</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- How it works -->
			<div class="card mb-3 border-info">
				<div class="card-header fw-bold"><i class="fa fa-info-circle me-2"></i>How Vehicle Transport Works</div>
				<div class="card-body small">
					<ul class="mb-0">
						<li><strong>Dispatch:</strong> Select a vehicle in a town, choose destination, cargo type & amount, then send.</li>
						<li><strong>Fuel:</strong> Vehicles consume Fuel from the departure town. Check fuel stock before dispatching.</li>
						<li><strong>Speed:</strong> Limited by vehicle max speed OR road speed limit, whichever is lower.</li>
						<li><strong>Road Types:</strong> Mud (30 km/h), Gravel (60), Asphalt (90), Dual Lane (130). Upgrade roads in town view.</li>
						<li><strong>Return Empty:</strong> Check this to have the vehicle automatically return (costs 2× fuel). Shows as two separate legs.</li>
						<li><strong>Arrival:</strong> Cargo is delivered to destination town's stockpile on the next game tick after ETA.</li>
					</ul>
				</div>
			</div>
			</div><!-- end vehicles tab -->

			<!-- ══════ TRAINS TAB ══════ -->
			<div class="tab-pane fade <?= $activeTab === 'trains' ? 'show active' : '' ?>" id="tab-trains">

			<!-- Train Stats -->
			<div class="row mb-3">
				<div class="col-md-3">
					<div class="card bg-dark border-warning">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-warning"><?= count($trainTrips) ?></h4>
							<small class="text-muted">In Transit</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-success">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-success"><?= $trainStatusCounts['idle'] ?? 0 ?></h4>
							<small class="text-muted">Idle Trains</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-info">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-info"><?= $trainStatusCounts['in_transit'] ?? 0 ?></h4>
							<small class="text-muted">Running</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-secondary">
						<div class="card-body text-center py-2">
							<h4 class="mb-0"><?= array_sum($trainStatusCounts) ?></h4>
							<small class="text-muted">Total Trains</small>
						</div>
					</div>
				</div>
			</div>

			<!-- Active Train Trips -->
			<div class="card mb-3">
				<div class="card-header fw-bold"><i class="fa fa-route me-2"></i>Active Train Trips</div>
				<div class="card-body p-0">
					<?php if (!empty($trainTrips)): ?>
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr>
								<th>Train</th><th>Loco</th><th>Faction</th><th>From → To</th><th>Cargo</th><th>Speed</th><th>Distance</th><th>Progress</th><th>ETA</th><th>Actions</th>
							</tr></thead>
							<tbody>
							<?php foreach ($trainTrips as $tt):
								$now = $dbNow; $eta = (int)$tt['eta_ts']; $dep = (int)$tt['dep_ts'];
								$total = max($eta - $dep, 1); $elapsed = $now - $dep;
								$pct = min(100, max(0, round(($elapsed / $total) * 100)));
								$remaining = max(0, $eta - $now);
								$mins = floor($remaining / 60); $secs = $remaining % 60;
								$fBadge = $tt['faction'] === 'red' ? '<span class="badge bg-danger">Red</span>' : '<span class="badge bg-primary">Blue</span>';
								$pIcon = match($tt['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
							?>
							<tr>
								<td class="fw-bold"><?= htmlspecialchars($tt['train_name'] ?? 'Train #'.$tt['train_id']) ?></td>
								<td><?= $pIcon ?> <?= htmlspecialchars($tt['loco_name']) ?></td>
								<td><?= $fBadge ?></td>
								<td>
									<a href="town_view.php?id=<?= $tt['from_id'] ?>" class="text-theme"><?= htmlspecialchars($tt['from_name']) ?></a>
									→ <a href="town_view.php?id=<?= $tt['to_id'] ?>" class="text-theme"><?= htmlspecialchars($tt['to_name']) ?></a>
									<?php if ($tt['return_empty']): ?><span class="badge bg-info ms-1">Leg 1</span><?php endif; ?>
								</td>
								<td>
									<?php
										$ttcItems = $allTrainTripCargo[(int)$tt['trip_id']] ?? [];
										if (!empty($ttcItems)):
											$summary = []; foreach ($ttcItems as $c) $summary[$c['resource_name']] = ($summary[$c['resource_name']] ?? 0) + (float)$c['amount'];
											echo implode(', ', array_map(fn($n, $a) => number_format($a,1).'t '.htmlspecialchars($n), array_keys($summary), $summary));
										else:
									?><span class="text-muted">Empty</span><?php endif; ?>
								</td>
								<td><?= $tt['speed_kmh'] ?> km/h</td>
								<td><?= number_format($tt['distance_km'], 1) ?> km</td>
								<td style="min-width: 150px;">
									<div class="progress" style="height: 18px;">
										<div class="progress-bar <?= $pct >= 90 ? 'bg-success' : ($pct >= 50 ? 'bg-warning' : 'bg-info') ?>" style="width: <?= $pct ?>%"><?= $pct ?>%</div>
									</div>
								</td>
								<td class="fw-bold">
									<?php if ($pct >= 100): ?>
										<span class="text-success"><i class="fa fa-check-circle me-1"></i>Arriving…</span>
									<?php else: ?>
										<?= $mins ?>m <?= $secs ?>s
									<?php endif; ?>
								</td>
								<td>
									<?php if ($pct < 95): ?>
									<form method="POST" action="transit_action.php" class="d-inline" onsubmit="return confirm('Recall this train? It will turn around and return cargo to origin.')">
										<input type="hidden" name="action" value="recall_train">
										<input type="hidden" name="trip_id" value="<?= $tt['trip_id'] ?>">
										<button type="submit" class="btn btn-sm btn-outline-warning" title="Recall train"><i class="fa fa-rotate-left"></i> Recall</button>
									</form>
									<?php else: ?>
										<span class="text-muted">—</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php if ($tt['return_empty']): ?>
							<tr class="opacity-50">
								<td class="fw-bold"><?= htmlspecialchars($tt['train_name'] ?? 'Train #'.$tt['train_id']) ?></td>
								<td><?= $pIcon ?> <?= htmlspecialchars($tt['loco_name']) ?></td>
								<td><?= $fBadge ?></td>
								<td>
									<a href="town_view.php?id=<?= $tt['to_id'] ?>" class="text-theme"><?= htmlspecialchars($tt['to_name']) ?></a>
									→ <a href="town_view.php?id=<?= $tt['from_id'] ?>" class="text-theme"><?= htmlspecialchars($tt['from_name']) ?></a>
									<span class="badge bg-secondary ms-1">Leg 2</span>
								</td>
								<td><span class="text-muted">Empty</span></td>
								<td><?= $tt['speed_kmh'] ?> km/h</td>
								<td><?= number_format($tt['distance_km'], 1) ?> km</td>
								<td style="min-width: 150px;" colspan="3">
									<span class="text-warning"><i class="fa fa-hourglass-half me-1"></i>Waiting for outbound leg</span>
								</td>
							</tr>
							<?php endif; ?>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php else: ?>
					<div class="text-center text-muted py-4">No trains currently in transit.</div>
					<?php endif; ?>
				</div>
			</div>

			<?php if (!empty($pendingTrainArrivals)): ?>
			<!-- Trains Arriving -->
			<div class="card mb-3 border-success">
				<div class="card-header fw-bold text-success"><i class="fa fa-check-double me-2"></i>Trains Arriving (<?= count($pendingTrainArrivals) ?>)</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr><th>Train</th><th>Route</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ($pendingTrainArrivals as $pta): ?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars($pta['train_name']) ?></td>
									<td><?= htmlspecialchars($pta['from_name']) ?> → <?= htmlspecialchars($pta['to_name']) ?></td>
									<td><span class="badge bg-success">Arrived — awaiting unload</span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Recent Completed Train Trips -->
			<div class="card mb-3">
				<div class="card-header fw-bold"><i class="fa fa-check-circle me-2"></i>Recent Completed Train Trips</div>
				<div class="card-body p-0">
					<?php if (!empty($completedTrains)): ?>
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr>
								<th>Train</th><th>Loco</th><th>From → To</th><th>Cargo</th><th>Distance</th><th>Speed</th><th>Departed</th><th>Arrived</th>
							</tr></thead>
							<tbody>
							<?php foreach ($completedTrains as $ct):
								$pIcon = match($ct['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
							?>
							<tr>
								<td><?= htmlspecialchars($ct['train_name'] ?? 'Train') ?></td>
								<td><?= $pIcon ?> <?= htmlspecialchars($ct['loco_name']) ?></td>
								<td><?= htmlspecialchars($ct['from_name']) ?> → <?= htmlspecialchars($ct['to_name']) ?></td>
								<td>
									<?php
										$ctcItems = $allTrainTripCargo[(int)$ct['trip_id']] ?? [];
										if (!empty($ctcItems)):
											$summary = []; foreach ($ctcItems as $c) $summary[$c['resource_name']] = ($summary[$c['resource_name']] ?? 0) + (float)$c['amount'];
											echo implode(', ', array_map(fn($n, $a) => number_format($a,1).'t '.htmlspecialchars($n), array_keys($summary), $summary));
										else:
									?><span class="text-muted">Empty</span><?php endif; ?>
								</td>
								<td><?= number_format($ct['distance_km'], 1) ?> km</td>
								<td><?= $ct['speed_kmh'] ?> km/h</td>
								<td class="small"><?= $ct['departed_at'] ?></td>
								<td class="small"><?= $ct['arrived_at'] ?></td>
							</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php else: ?>
					<div class="text-center text-muted py-4">No completed train trips yet.</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- How Trains Work -->
			<div class="card mb-3 border-warning">
				<div class="card-header fw-bold"><i class="fa fa-info-circle me-2"></i>How Train Transport Works</div>
				<div class="card-body small">
					<ul class="mb-0">
						<li><strong>Compose:</strong> Select a locomotive and attach wagons to form a train.</li>
						<li><strong>Fuel:</strong> Steam trains burn Coal, Diesel trains burn Fuel, Electric trains need electrified rail but no fuel.</li>
						<li><strong>Speed:</strong> Limited by locomotive max speed OR rail speed limit, whichever is lower.</li>
						<li><strong>Rail Types:</strong> Basic (60 km/h), Electrified (120 km/h). Upgrade on admin panel.</li>
						<li><strong>Wagons:</strong> Each wagon carries one cargo class. Warehouse wagons can carry mixed resources.</li>
						<li><strong>Max 30 wagons</strong> per train. Locomotive determines the max wagon count.</li>
					</ul>
				</div>
			</div>
			</div><!-- end trains tab -->
			</div><!-- end tab-content -->
		</div>
		<!-- END #content -->
<script>
// Auto-refresh transit page every 30 seconds + trigger tick if needed
let transitCountdown = 30;
function transitAutoRefresh() {
	transitCountdown--;
	const el = document.getElementById('refresh-countdown');
	if (el) el.textContent = transitCountdown;
	if (transitCountdown <= 0) {
		// Ping tick engine to process any pending arrivals, then reload
		fetch('admin/tick.php?action=tick')
			.then(() => location.reload())
			.catch(() => location.reload());
	}
}
setInterval(transitAutoRefresh, 1000);
</script>
<?php
include "files/scripts.php";
$conn->close();
?>
