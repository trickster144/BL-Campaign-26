<?php
session_start();
include "files/config.php";
include "files/power_functions.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);

$allowedFactions = viewableFactions($user);
$selectedFaction = $_GET['faction'] ?? $allowedFactions[0] ?? 'blue';
if (!in_array($selectedFaction, $allowedFactions)) $selectedFaction = $allowedFactions[0];

// ── Discover all power grids for this faction ──
$towns = [];
$res = $conn->query("SELECT id, name, side, population FROM towns WHERE side = '" . $conn->real_escape_string($selectedFaction) . "' AND name NOT LIKE 'Customs House%' ORDER BY name");
if ($res) { while ($r = $res->fetch_assoc()) $towns[(int)$r['id']] = $r; }

$lines = [];
$res = $conn->query("SELECT id, town_id_1, town_id_2, level FROM transmission_lines WHERE level > 0");
if ($res) { while ($r = $res->fetch_assoc()) $lines[] = $r; }

// Union-find to discover grids
$gridId = [];
foreach ($towns as $tid => $t) $gridId[$tid] = $tid;

$changed = true;
while ($changed) {
	$changed = false;
	foreach ($lines as $line) {
		$t1 = (int)$line['town_id_1'];
		$t2 = (int)$line['town_id_2'];
		if (!isset($gridId[$t1]) || !isset($gridId[$t2])) continue;
		$g1 = $gridId[$t1];
		$g2 = $gridId[$t2];
		if ($g1 !== $g2) {
			$minG = min($g1, $g2);
			$gridId[$t1] = $minG;
			$gridId[$t2] = $minG;
			$changed = true;
		}
	}
}

// Group towns by grid
$grids = [];
foreach ($gridId as $tid => $gid) {
	$grids[$gid][] = $tid;
}

// Load all power stations for this faction
$allStations = [];
$stRes = $conn->query("
	SELECT ps.id, ps.town_id, ps.fuel_type, ps.level, ps.workers_assigned,
	       pst.display_name, pst.fuel_resource_name, pst.fuel_per_mw_hr,
	       COALESCE(pst.mw_per_level, 1.0) AS mw_per_level
	FROM power_stations ps
	JOIN power_station_types pst ON ps.fuel_type = pst.fuel_type
	JOIN towns t ON ps.town_id = t.id
	WHERE t.side = '" . $conn->real_escape_string($selectedFaction) . "'
	ORDER BY ps.town_id, pst.display_name
");
if ($stRes) {
	while ($s = $stRes->fetch_assoc()) {
		$s['max_workers'] = (int)$s['level'] * 100;
		$mwPerLevel = (float)$s['mw_per_level'];
		$s['actual_output'] = getPowerStationOutput((int)$s['level'], (int)$s['workers_assigned'], $mwPerLevel);
		$s['fuel_consumption'] = getFuelConsumption($s['fuel_type'], $s['actual_output'], (float)$s['fuel_per_mw_hr']);
		$allStations[(int)$s['town_id']][] = $s;
	}
}

// Load demand per town: mines
$mineDemand = [];
$mRes = $conn->query("
	SELECT tp.town_id, tp.level
	FROM town_production tp
	JOIN towns t ON tp.town_id = t.id
	WHERE t.side = '" . $conn->real_escape_string($selectedFaction) . "'
");
if ($mRes) {
	while ($m = $mRes->fetch_assoc()) {
		$tid = (int)$m['town_id'];
		$mineDemand[$tid] = ($mineDemand[$tid] ?? 0) + (float)$m['level'] * 1.0;
	}
}

// Load demand per town: factories
$factDemand = [];
$fRes = $conn->query("
	SELECT tf.town_id, SUM(tf.level * ft.power_per_level) AS fd
	FROM town_factories tf
	JOIN factory_types ft ON tf.factory_type_id = ft.type_id
	JOIN towns t ON tf.town_id = t.id
	WHERE t.side = '" . $conn->real_escape_string($selectedFaction) . "' AND tf.level > 0
	GROUP BY tf.town_id
");
if ($fRes) {
	while ($f = $fRes->fetch_assoc()) {
		$factDemand[(int)$f['town_id']] = (float)$f['fd'];
	}
}

// Load fuel stock levels for power station fuel types
$fuelStock = [];
$fuelRes = $conn->query("
	SELECT tr.town_id, wp.resource_name, tr.stock
	FROM town_resources tr
	JOIN world_prices wp ON tr.resource_id = wp.id
	JOIN towns t ON tr.town_id = t.id
	WHERE t.side = '" . $conn->real_escape_string($selectedFaction) . "'
	  AND wp.resource_name IN ('Coal', 'Wood', 'Oil', 'Nuclear fuel')
	  AND tr.stock > 0
");
if ($fuelRes) {
	while ($fs = $fuelRes->fetch_assoc()) {
		$fuelStock[(int)$fs['town_id']][$fs['resource_name']] = (float)$fs['stock'];
	}
}

// Load all transmission lines for display
$factionLines = [];
$tlRes = $conn->query("
	SELECT tl.id, tl.town_id_1, tl.town_id_2, tl.level,
	       t1.name AS name1, t2.name AS name2,
	       td.distance_km
	FROM transmission_lines tl
	JOIN towns t1 ON tl.town_id_1 = t1.id
	JOIN towns t2 ON tl.town_id_2 = t2.id
	LEFT JOIN town_distances td ON (td.town_id_1 = tl.town_id_1 AND td.town_id_2 = tl.town_id_2)
	WHERE t1.side = '" . $conn->real_escape_string($selectedFaction) . "'
	  AND t2.side = '" . $conn->real_escape_string($selectedFaction) . "'
	ORDER BY tl.level DESC, t1.name
");
if ($tlRes) {
	while ($tl = $tlRes->fetch_assoc()) {
		$tl['capacity'] = (int)$tl['level'] * 100;
		$factionLines[] = $tl;
	}
}

// Build per-grid summaries
$gridSummaries = [];
foreach ($grids as $gid => $townIds) {
	$gen = 0; $dem = 0; $stationCount = 0; $townNames = [];
	foreach ($townIds as $tid) {
		$townNames[$tid] = $towns[$tid]['name'] ?? "Town #$tid";
		$dem += ($mineDemand[$tid] ?? 0) + ($factDemand[$tid] ?? 0);
		if (!empty($allStations[$tid])) {
			foreach ($allStations[$tid] as $st) {
				$gen += $st['actual_output'];
				$stationCount++;
			}
		}
	}
	$ratio = $dem > 0 ? min($gen / $dem, 1.0) : ($gen > 0 ? 1.0 : 0.0);
	$throttle = ($gen > 0 && $dem > 0) ? min($dem / $gen, 1.0) : ($dem > 0 ? 1.0 : 0.0);
	$gridSummaries[$gid] = [
		'town_ids' => $townIds,
		'town_names' => $townNames,
		'generation' => $gen,
		'demand' => $dem,
		'ratio' => $ratio,
		'throttle' => $throttle,
		'station_count' => $stationCount,
	];
}

// Sort grids: multi-town grids first, then by generation descending
uasort($gridSummaries, function($a, $b) {
	$ac = count($a['town_ids']); $bc = count($b['town_ids']);
	if ($ac !== $bc) return $bc - $ac;
	return $b['generation'] <=> $a['generation'];
});

// Station type reference
$stationTypes = [];
$stRes2 = $conn->query("SELECT * FROM power_station_types ORDER BY fuel_type");
if ($stRes2) { while ($st = $stRes2->fetch_assoc()) $stationTypes[] = $st; }

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<div class="d-flex align-items-center mb-3">
				<h1 class="page-header mb-0"><i class="fa fa-bolt me-2"></i>Power Networks</h1>
				<?php if (count($allowedFactions) > 1): ?>
				<div class="ms-3">
					<?php foreach ($allowedFactions as $f): ?>
					<a href="power.php?faction=<?= $f ?>" class="btn btn-sm <?= $f === $selectedFaction ? 'btn-theme' : 'btn-outline-theme' ?>"><?= ucfirst($f) ?></a>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div>

			<!-- ── OVERALL SUMMARY ── -->
			<?php
			$totalGen = array_sum(array_column($gridSummaries, 'generation'));
			$totalDem = array_sum(array_column($gridSummaries, 'demand'));
			$totalStations = array_sum(array_column($gridSummaries, 'station_count'));
			$overallPct = $totalDem > 0 ? round(min($totalGen / $totalDem, 1.0) * 100) : ($totalGen > 0 ? 100 : 0);
			$overallClass = $overallPct >= 100 ? 'bg-success' : ($overallPct > 50 ? 'bg-warning' : 'bg-danger');
			$connectedGrids = count(array_filter($gridSummaries, fn($g) => count($g['town_ids']) > 1));
			$isolatedTowns = count(array_filter($gridSummaries, fn($g) => count($g['town_ids']) === 1));
			?>
			<div class="row mb-3">
				<div class="col-lg-3 col-md-6 mb-2">
					<div class="card">
						<div class="card-body text-center py-3">
							<div class="text-inverse text-opacity-50 small">Total Generation</div>
							<div class="fw-bold fs-4 text-success"><?= number_format($totalGen, 2) ?> MW</div>
						</div>
					</div>
				</div>
				<div class="col-lg-3 col-md-6 mb-2">
					<div class="card">
						<div class="card-body text-center py-3">
							<div class="text-inverse text-opacity-50 small">Total Demand</div>
							<div class="fw-bold fs-4 text-danger"><?= number_format($totalDem, 2) ?> MW</div>
						</div>
					</div>
				</div>
				<div class="col-lg-3 col-md-6 mb-2">
					<div class="card">
						<div class="card-body text-center py-3">
							<div class="text-inverse text-opacity-50 small">Overall Efficiency</div>
							<div class="fw-bold fs-4 <?= $overallPct >= 100 ? 'text-success' : ($overallPct > 50 ? 'text-warning' : 'text-danger') ?>"><?= $overallPct ?>%</div>
							<div class="progress mt-1" style="height:6px"><div class="progress-bar <?= $overallClass ?>" style="width:<?= $overallPct ?>%"></div></div>
						</div>
					</div>
				</div>
				<div class="col-lg-3 col-md-6 mb-2">
					<div class="card">
						<div class="card-body text-center py-3">
							<div class="text-inverse text-opacity-50 small">Infrastructure</div>
							<div class="fw-bold"><?= $totalStations ?> Stations · <?= count($factionLines) ?> Lines</div>
							<div class="small text-inverse text-opacity-50"><?= $connectedGrids ?> Grids · <?= $isolatedTowns ?> Isolated</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ── POWER GRIDS ── -->
			<?php foreach ($gridSummaries as $gid => $grid):
				$isMulti = count($grid['town_ids']) > 1;
				$gridPct = $grid['demand'] > 0 ? round(min($grid['generation'] / $grid['demand'], 1.0) * 100) : ($grid['generation'] > 0 ? 100 : 0);
				$gridClass = $gridPct >= 100 ? 'bg-success' : ($gridPct > 0 ? 'bg-warning' : 'bg-danger');
				$gridLabel = $isMulti ? 'Power Grid (' . count($grid['town_ids']) . ' towns)' : htmlspecialchars($grid['town_names'][$gid] ?? 'Town');
			?>
			<div class="card mb-3">
				<div class="card-header fw-bold small d-flex justify-content-between align-items-center">
					<span><i class="fa fa-bolt me-1"></i><?= $gridLabel ?></span>
					<span class="badge <?= $gridClass ?>"><?= $gridPct ?>% · <?= number_format($grid['generation'], 2) ?> / <?= number_format($grid['demand'], 2) ?> MW</span>
				</div>
				<div class="card-body">
					<div class="progress mb-3" style="height:8px">
						<div class="progress-bar <?= $gridClass ?>" style="width:<?= $gridPct ?>%"></div>
					</div>

					<?php if ($isMulti): ?>
					<div class="mb-3">
						<small class="fw-bold d-block mb-1">Connected Towns</small>
						<?php foreach ($grid['town_names'] as $tid => $tname): ?>
						<a href="town_view.php?id=<?= $tid ?>" class="badge bg-dark bg-opacity-50 text-decoration-none me-1 mb-1"><?= htmlspecialchars($tname) ?></a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>

					<!-- Stations per town in this grid -->
					<div class="row">
						<?php foreach ($grid['town_ids'] as $tid):
							$tStations = $allStations[$tid] ?? [];
							$tDemand = ($mineDemand[$tid] ?? 0) + ($factDemand[$tid] ?? 0);
							$tGen = 0;
							foreach ($tStations as $st) $tGen += $st['actual_output'];
							$tFuel = $fuelStock[$tid] ?? [];
						?>
						<div class="col-lg-6 mb-2">
							<div class="card bg-dark bg-opacity-25">
								<div class="card-header py-2 d-flex justify-content-between align-items-center">
									<a href="town_view.php?id=<?= $tid ?>" class="text-theme text-decoration-none fw-bold small"><?= htmlspecialchars($towns[$tid]['name'] ?? 'Town') ?></a>
									<div>
										<span class="small text-success me-2"><i class="fa fa-bolt me-1"></i><?= number_format($tGen, 2) ?> MW</span>
										<span class="small text-danger"><i class="fa fa-plug me-1"></i><?= number_format($tDemand, 2) ?> MW</span>
									</div>
								</div>
								<div class="card-body py-2">
									<?php if (!empty($tStations)): ?>
									<table class="table table-sm table-borderless mb-2">
										<thead>
											<tr class="small text-inverse text-opacity-50">
												<th>Station</th>
												<th>Level</th>
												<th>Output</th>
												<th>Fuel Burn</th>
												<th>Workers</th>
											</tr>
										</thead>
										<tbody>
										<?php foreach ($tStations as $st):
											$wPct = $st['max_workers'] > 0 ? round($st['workers_assigned'] / $st['max_workers'] * 100) : 0;
											$gThrottle = $grid['throttle'] ?? 1.0;
											$throttledOutput = $st['actual_output'] * $gThrottle;
											$throttledFuel = $st['fuel_consumption'] * $gThrottle;
										?>
											<tr>
												<td class="small"><?= htmlspecialchars($st['display_name']) ?></td>
												<td><span class="badge bg-theme"><?= $st['level'] ?></span></td>
												<td class="text-success small">
													<?php if ($gThrottle < 1.0 && $st['actual_output'] > 0): ?>
														<?= number_format($throttledOutput, 2) ?> / <?= number_format($st['actual_output'], 2) ?> MW
														<span class="text-warning" title="Throttled — grid has surplus">(<?= round($gThrottle * 100) ?>%)</span>
													<?php else: ?>
														<?= number_format($st['actual_output'], 2) ?> MW
													<?php endif; ?>
												</td>
												<td class="small">
													<?php if ($gThrottle < 1.0 && $st['fuel_consumption'] > 0): ?>
														<?= number_format($throttledFuel, 2) ?> / <?= number_format($st['fuel_consumption'], 2) ?> t/hr <?= htmlspecialchars($st['fuel_resource_name']) ?>
													<?php else: ?>
														<?= number_format($st['fuel_consumption'], 2) ?> t/hr <?= htmlspecialchars($st['fuel_resource_name']) ?>
													<?php endif; ?>
												</td>
												<td class="small"><?= $st['workers_assigned'] ?>/<?= $st['max_workers'] ?> <span class="text-inverse text-opacity-50">(<?= $wPct ?>%)</span></td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
									<?php else: ?>
									<p class="small text-inverse text-opacity-50 mb-1">No power stations</p>
									<?php endif; ?>

									<?php if (!empty($tFuel)): ?>
									<div class="mt-1">
										<small class="fw-bold">Fuel Stock:</small>
										<?php foreach ($tFuel as $fName => $fQty): ?>
										<span class="badge bg-dark bg-opacity-50 me-1"><?= htmlspecialchars($fName) ?>: <?= number_format($fQty, 1) ?>t</span>
										<?php endforeach; ?>
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<?php endforeach; ?>

			<?php if (empty($gridSummaries)): ?>
			<div class="card">
				<div class="card-body text-center py-4">
					<i class="fa fa-bolt fa-3x text-inverse text-opacity-25 mb-3"></i>
					<p class="text-inverse text-opacity-50">No power infrastructure found. Build power stations in your towns to generate electricity.</p>
				</div>
			</div>
			<?php endif; ?>

			<!-- ── TRANSMISSION LINES ── -->
			<?php if (!empty($factionLines)): ?>
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-project-diagram me-1"></i>All Transmission Lines (<?= count($factionLines) ?>)</div>
				<div class="card-body">
					<table class="table table-hover table-sm">
						<thead>
							<tr>
								<th>From</th>
								<th>To</th>
								<th>Distance</th>
								<th>Level</th>
								<th>Capacity</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($factionLines as $tl): ?>
							<tr>
								<td><a href="town_view.php?id=<?= $tl['town_id_1'] ?>" class="text-theme"><?= htmlspecialchars($tl['name1']) ?></a></td>
								<td><a href="town_view.php?id=<?= $tl['town_id_2'] ?>" class="text-theme"><?= htmlspecialchars($tl['name2']) ?></a></td>
								<td><?= number_format($tl['distance_km'] ?? 0, 1) ?> km</td>
								<td><span class="badge bg-theme">Lvl <?= $tl['level'] ?></span></td>
								<td><?= $tl['capacity'] ?> MW</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<!-- ── POWER STATION TYPES REFERENCE ── -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-info-circle me-1"></i>Power Station Types</div>
				<div class="card-body">
					<table class="table table-sm">
						<thead>
							<tr>
								<th>Type</th>
								<th>Fuel</th>
								<th>Fuel per MW/hr</th>
								<th>Notes</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($stationTypes as $st): ?>
							<tr>
								<td class="fw-bold"><?= htmlspecialchars($st['display_name']) ?></td>
								<td><?= htmlspecialchars($st['fuel_resource_name']) ?></td>
								<td><?= number_format($st['fuel_per_mw_hr'], 4) ?> t/MW/hr</td>
								<td class="text-inverse text-opacity-50 small">
									<?php
									echo match($st['fuel_type']) {
										'coal' => 'Common, moderate efficiency',
										'wood' => 'Renewable but low efficiency',
										'oil' => 'High efficiency, expensive fuel',
										'nuclear' => 'Very high efficiency, rare fuel',
										default => ''
									};
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>
		<!-- END #content -->
<?php include "files/scripts.php"; ?>
