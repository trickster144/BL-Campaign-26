<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);
$factionSQL = "'" . implode("','", $factions) . "'";
include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">Towns <?= teamBadge($user['team']) ?></h1>

			<?php
			// ── Pre-fetch vehicle & train counts per town ──
			$vehicleCounts = [];
			$vcRes = $conn->query("
				SELECT v.town_id,
					COUNT(*) as total,
					SUM(CASE WHEN vt.vehicle_class = 'cargo' THEN 1 ELSE 0 END) as cargo,
					SUM(CASE WHEN vt.vehicle_class = 'passenger' THEN 1 ELSE 0 END) as pax,
					SUM(CASE WHEN vt.vehicle_class = 'tow' THEN 1 ELSE 0 END) as tow
				FROM vehicles v
				JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
				WHERE v.status NOT IN ('scrapped')
				GROUP BY v.town_id
			");
			if ($vcRes) { while ($vc = $vcRes->fetch_assoc()) $vehicleCounts[(int)$vc['town_id']] = $vc; }

			$trainCounts = [];
			$tcRes = $conn->query("
				SELECT l.town_id, COUNT(*) as total
				FROM locomotives l
				WHERE l.status NOT IN ('scrapped')
				GROUP BY l.town_id
			");
			if ($tcRes) { while ($tc = $tcRes->fetch_assoc()) $trainCounts[(int)$tc['town_id']] = (int)$tc['total']; }

			// ── Pre-fetch mine production per town ──
			$mineData = [];
			$mpRes = $conn->query("
				SELECT tp.town_id, wp.resource_name, wp.image_file, tp.level
				FROM town_production tp
				JOIN world_prices wp ON tp.resource_id = wp.id
			");
			if ($mpRes) { while ($mp = $mpRes->fetch_assoc()) $mineData[(int)$mp['town_id']] = $mp; }
			?>

			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-landmark me-1"></i> Customs Houses</div>
				<div class="card-body p-0">
					<table class="table table-hover mb-0">
						<thead>
							<tr>
								<th scope="col">Location</th>
								<th scope="col" class="text-center">🚛 Trucks</th>
								<th scope="col" class="text-center">🚂 Trains</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$customs = $conn->query("SELECT id, name, population, side FROM towns WHERE name LIKE 'Customs House%' AND side IN ($factionSQL) ORDER BY name");
						while ($ch = $customs->fetch_assoc()):
							$chId = (int)$ch['id'];
							$chVc = $vehicleCounts[$chId] ?? ['total' => 0, 'cargo' => 0, 'pax' => 0, 'tow' => 0];
							$chTr = $trainCounts[$chId] ?? 0;
							$chBadge = $ch['side'] === 'blue' ? '<span class="badge bg-primary">Blue</span>' : '<span class="badge bg-danger">Red</span>';
						?>
							<tr>
								<td><a href="town_view.php?id=<?= $chId ?>" class="text-theme"><?= htmlspecialchars($ch['name']) ?></a> <?= $chBadge ?></td>
								<td class="text-center"><?= (int)$chVc['total'] ?></td>
								<td class="text-center"><?= $chTr ?></td>
							</tr>
						<?php endwhile; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-city me-1"></i> <?= isGreenTeam($user) ? 'All Towns' : ucfirst($user['team']) . ' Towns' ?></div>
				<div class="card-body p-0">
					<table class="table table-hover mb-0">
						<thead>
							<tr>
								<th scope="col">Town</th>
								<th scope="col">Faction</th>
								<th scope="col" class="text-end">Population</th>
								<th scope="col">Mining</th>
								<th scope="col" class="text-center">🚛 Trucks</th>
								<th scope="col" class="text-center">🚂 Trains</th>
							</tr>
						</thead>
						<tbody>
						<?php
						$towns = $conn->query("SELECT id, name, population, side FROM towns WHERE name NOT LIKE 'Customs House%' AND side IN ($factionSQL) ORDER BY side, name");
						while ($town = $towns->fetch_assoc()):
							$sid = (int)$town['id'];
							$vc = $vehicleCounts[$sid] ?? ['total' => 0, 'cargo' => 0, 'pax' => 0, 'tow' => 0];
							$tr = $trainCounts[$sid] ?? 0;
							$mine = $mineData[$sid] ?? null;
							$fBadge = $town['side'] === 'blue' ? '<span class="badge bg-primary">Blue</span>' : '<span class="badge bg-danger">Red</span>';
						?>
							<tr>
								<td><a href="town_view.php?id=<?= $town['id'] ?>" class="text-theme"><?= htmlspecialchars($town['name']) ?></a></td>
								<td><?= $fBadge ?></td>
								<td class="text-end"><?= number_format($town['population']) ?></td>
								<td>
									<?php if ($mine): ?>
										<?php if (!empty($mine['image_file'])): ?>
											<img src="assets/resource_imgs/<?= htmlspecialchars($mine['image_file']) ?>" alt="" style="width:18px;height:18px;vertical-align:middle;" class="me-1">
										<?php endif; ?>
										<?= htmlspecialchars($mine['resource_name']) ?>
										<small class="text-muted">Lv.<?= (int)$mine['level'] ?></small>
									<?php else: ?>
										<span class="text-muted">—</span>
									<?php endif; ?>
								</td>
								<td class="text-center">
									<?php if ((int)$vc['total'] > 0): ?>
										<span title="<?= (int)$vc['cargo'] ?> cargo, <?= (int)$vc['pax'] ?> passenger<?= (int)$vc['tow'] > 0 ? ', ' . (int)$vc['tow'] . ' tow' : '' ?>"><?= (int)$vc['total'] ?></span>
									<?php else: ?>
										<span class="text-muted">0</span>
									<?php endif; ?>
								</td>
								<td class="text-center">
									<?php if ($tr > 0): ?>
										<?= $tr ?>
									<?php else: ?>
										<span class="text-muted">0</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endwhile; ?>
						</tbody>
					</table>
				</div>
			</div>

		</div>
		<!-- END #content -->
<?php
include "files/scripts.php";
?>	