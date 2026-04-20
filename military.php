<?php
// military.php — Main military page: view/manage troops, weapons, movements
session_start();
include "files/config.php";
include "files/auth.php";

$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);
$factionSQL = "'" . implode("','", $factions) . "'";

$msg = $_GET['msg'] ?? '';
$selectedTown = (int)($_GET['town'] ?? 0);

// Load towns with military data
$towns = [];
$tRes = $conn->query("
    SELECT t.id, t.name, t.side, t.population,
           COALESCE(b.level, 0) as barracks_level,
           COALESCE(b.workers_assigned, 0) as barracks_workers,
           COALESCE(m.level, 0) as munitions_level,
           COALESCE(m.workers_assigned, 0) as munitions_workers,
           m.producing_weapon_type_id,
           (SELECT COALESCE(SUM(tt.quantity), 0) FROM town_troops tt WHERE tt.town_id = t.id AND tt.faction = t.side) as total_troops
    FROM towns t
    LEFT JOIN town_barracks b ON t.id = b.town_id
    LEFT JOIN town_munitions_factory m ON t.id = m.town_id
    WHERE t.side IN ($factionSQL) AND t.name NOT LIKE 'Customs House%'
    ORDER BY t.side, t.name
");
if ($tRes) { while ($t = $tRes->fetch_assoc()) $towns[] = $t; }

// Load active weapon types
$weaponTypes = [];
$wtRes = $conn->query("SELECT * FROM weapon_types WHERE active = 1 ORDER BY name");
if ($wtRes) { while ($w = $wtRes->fetch_assoc()) $weaponTypes[] = $w; }

// Use DB time for ETA calculations (PHP timezone may differ from MySQL)
$dbNowRes = $conn->query("SELECT UNIX_TIMESTAMP(NOW()) as db_ts");
$dbNow = $dbNowRes ? (int)$dbNowRes->fetch_assoc()['db_ts'] : time();

// Load troop movements
$movements = [];
$mvRes = $conn->query("
    SELECT tm.*, ft.name as from_name, tt.name as to_name, wt.name as weapon_name,
           UNIX_TIMESTAMP(tm.eta_at) as eta_ts
    FROM troop_movements tm
    JOIN towns ft ON tm.from_town_id = ft.id
    JOIN towns tt ON tm.to_town_id = tt.id
    LEFT JOIN weapon_types wt ON tm.weapon_type_id = wt.id
    WHERE tm.arrived = 0 AND tm.faction IN ($factionSQL)
    ORDER BY tm.eta_at ASC
");
if ($mvRes) { while ($m = $mvRes->fetch_assoc()) $movements[] = $m; }

// If a town is selected, load detailed data
$townDetail = null;
$townTroops = [];
$townWeapons = [];
$townAllTowns = []; // for movement destination dropdown
if ($selectedTown > 0) {
    // Town info
    $tdRes = $conn->query("
        SELECT t.*, COALESCE(b.level, 0) as barracks_level, COALESCE(b.workers_assigned, 0) as barracks_workers,
               COALESCE(m.level, 0) as munitions_level, COALESCE(m.workers_assigned, 0) as munitions_workers,
               m.producing_weapon_type_id
        FROM towns t
        LEFT JOIN town_barracks b ON t.id = b.town_id
        LEFT JOIN town_munitions_factory m ON t.id = m.town_id
        WHERE t.id = $selectedTown
    ");
    $townDetail = $tdRes ? $tdRes->fetch_assoc() : null;

    if ($townDetail && canViewFaction($user, $townDetail['side'])) {
        $tdFaction = $townDetail['side'];

        // Troops at this town
        $ttRes = $conn->query("
            SELECT tt.*, wt.name as weapon_name, wt.attack_stat, wt.defense_stat
            FROM town_troops tt
            LEFT JOIN weapon_types wt ON tt.weapon_type_id = wt.id
            WHERE tt.town_id = $selectedTown AND tt.faction = '$tdFaction'
            ORDER BY wt.name
        ");
        if ($ttRes) { while ($tr = $ttRes->fetch_assoc()) $townTroops[] = $tr; }

        // Weapon stock
        $wsRes = $conn->query("
            SELECT tws.*, wt.name as weapon_name, wt.attack_stat, wt.defense_stat
            FROM town_weapons_stock tws
            JOIN weapon_types wt ON tws.weapon_type_id = wt.id
            WHERE tws.town_id = $selectedTown AND tws.stock > 0
            ORDER BY wt.name
        ");
        if ($wsRes) { while ($ws = $wsRes->fetch_assoc()) $townWeapons[] = $ws; }

        // All towns for movement (same and enemy factions)
        $atRes = $conn->query("SELECT id, name, side FROM towns WHERE id != $selectedTown AND population > 0 AND name NOT LIKE 'Customs House%' ORDER BY side, name");
        if ($atRes) { while ($at = $atRes->fetch_assoc()) $townAllTowns[] = $at; }

        // Available military vehicles at this town (for troop transport)
        $townMilVehicles = [];
        $mvRes2 = $conn->query("
            SELECT v.id, vt.name as type_name, vt.max_speed_kmh, vt.max_capacity
            FROM vehicles v
            JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
            WHERE v.town_id = $selectedTown AND v.faction = '$tdFaction'
              AND v.status = 'idle' AND vt.category = 'military'
            ORDER BY vt.name, v.id
        ");
        if ($mvRes2) { while ($mv2 = $mvRes2->fetch_assoc()) $townMilVehicles[] = $mv2; }
    } else {
        $townDetail = null;
    }
}

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">⚔️ Military Command</h1>

			<?php if ($msg): ?>
			<div class="alert alert-<?= in_array($msg, ['armed','rearmed','troops_sent','production_set','production_stopped']) ? 'success' : 'warning' ?> alert-dismissible fade show">
				<?php
				$qty = (int)($_GET['qty'] ?? 0);
				echo match($msg) {
					'armed' => "✅ Armed $qty troops with weapons!",
					'rearmed' => "✅ Re-armed $qty troops with a new weapon!",
					'troops_sent' => "✅ Sent $qty troops " . htmlspecialchars($_GET['action'] ?? '') . " " . htmlspecialchars($_GET['to'] ?? '') . "!",
					'production_set' => "✅ Factory production target updated. Weapons will be produced each tick.",
					'production_stopped' => "✅ Factory production stopped (idle).",
					'garrison_full' => "⚠️ Barracks garrison capacity is full. Upgrade barracks for more space.",
					'no_barracks' => "⚠️ This town has no barracks. Build one first.",
					'no_munitions' => "⚠️ This town has no munitions factory. Build one first.",
					'no_workers' => "⚠️ No workers assigned. Assign workers first.",
					'not_enough_resources' => "⚠️ Not enough resources in this town.",
					'not_enough_troops' => "⚠️ Not enough troops available.",
					'not_enough_weapons' => "⚠️ Not enough weapons in stock.",
					'invalid_destination' => "⚠️ Invalid destination.",
					'no_vehicle' => "⚠️ Vehicle not available or not at this town.",
					'vehicle_capacity' => "⚠️ Too many troops for this vehicle's capacity.",
					default => "ℹ️ " . htmlspecialchars($msg),
				};
				?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
			<?php endif; ?>

			<!-- Military Overview -->
			<div class="row mb-4">
				<div class="col-12">
					<div class="card bg-dark border-danger">
						<div class="card-header border-danger">
							<h5 class="mb-0">🏰 Military Overview — Select a town to manage</h5>
						</div>
						<div class="card-body p-0">
							<div class="table-responsive">
								<table class="table table-dark table-hover table-sm mb-0">
									<thead>
										<tr>
											<th>Town</th><th>Faction</th><th>Population</th>
											<th>🏰 Barracks</th><th>🏭 Munitions</th>
											<th>Troops</th><th>Recruit/tick</th><th>Wpns/tick</th><th>Actions</th>
										</tr>
									</thead>
									<tbody>
									<?php foreach ($towns as $t):
										$fBadge = '<span class="badge bg-' . ($t['side'] === 'blue' ? 'primary' : 'danger') . '">' . ucfirst($t['side']) . '</span>';
										$barLvl = (int)$t['barracks_level'];
										$munLvl = (int)$t['munitions_level'];
										$isSelected = ($selectedTown === (int)$t['id']);
									?>
										<tr class="<?= $isSelected ? 'table-active' : '' ?>">
											<td class="fw-bold"><?= htmlspecialchars($t['name']) ?></td>
											<td><?= $fBadge ?></td>
											<td><?= number_format($t['population']) ?></td>
											<td><?= $barLvl > 0 ? "Lv.$barLvl" : '<span class="text-muted">None</span>' ?></td>
											<td><?= $munLvl > 0 ? "Lv.$munLvl" : '<span class="text-muted">None</span>' ?></td>
											<td><?= number_format($t['total_troops']) ?></td>
											<td class="text-warning"><?= $barLvl > 0 ? floor((int)$t['barracks_workers'] / 10) : '-' ?></td>
											<td class="text-info"><?= $munLvl > 0 && $t['producing_weapon_type_id'] ? floor((int)$t['munitions_workers'] / 20) : '-' ?></td>
											<td>
												<a href="?town=<?= $t['id'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2">
													<i class="fa fa-crosshairs"></i> Manage
												</a>
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

			<?php if ($townDetail): ?>
			<!-- Town Military Detail -->
			<div class="row mb-4">
				<div class="col-12">
					<h3 class="text-danger">⚔️ <?= htmlspecialchars($townDetail['name']) ?> — Military Command</h3>
				</div>
			</div>

			<div class="row g-4">
				<!-- Garrison -->
				<div class="col-md-6">
					<div class="card bg-dark border-warning h-100">
						<div class="card-header border-warning">
							<h5 class="mb-0">🪖 Garrison (<?= array_sum(array_column($townTroops, 'quantity')) ?> troops)</h5>
						</div>
						<div class="card-body">
							<?php if (empty($townTroops)): ?>
								<p class="text-muted">No troops stationed here.</p>
							<?php else: ?>
								<table class="table table-dark table-sm mb-0">
									<thead><tr><th>Type</th><th>Qty</th><th>⚔️ Atk</th><th>🛡️ Def</th><th>Total Power</th></tr></thead>
									<tbody>
									<?php foreach ($townTroops as $tr):
										$atkStat = $tr['weapon_type_id'] ? (int)$tr['attack_stat'] : 3;
										$defStat = $tr['weapon_type_id'] ? (int)$tr['defense_stat'] : 2;
										$qty = (int)$tr['quantity'];
									?>
										<tr>
											<td><?= $tr['weapon_name'] ? htmlspecialchars($tr['weapon_name']) : '<span class="text-muted">Unarmed</span>' ?></td>
											<td class="fw-bold"><?= number_format($qty) ?></td>
											<td class="text-danger"><?= $atkStat ?></td>
											<td class="text-info"><?= $defStat ?></td>
											<td>⚔️ <?= number_format($atkStat * $qty) ?> / 🛡️ <?= number_format($defStat * $qty) ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>

							<!-- Recruit Info -->
							<?php if ((int)$townDetail['barracks_level'] > 0): ?>
							<hr class="border-secondary">
							<?php
								$brkWorkers = (int)$townDetail['barracks_workers'];
								$autoRate = floor($brkWorkers / 10);
								$brkCapacity = (int)$townDetail['barracks_level'] * 100;
							?>
							<div class="mb-2">
								<small class="text-muted d-block">⚙️ Auto-Recruit Rate: <span class="fw-bold text-warning"><?= $autoRate ?> troops/tick</span>
								(<?= $brkWorkers ?> workers ÷ 10)
								&nbsp;|&nbsp; Capacity: <?= number_format($brkCapacity) ?></small>
								<?php if ($brkWorkers === 0): ?>
								<small class="text-danger d-block">⚠️ Assign workers to the barracks to start auto-recruitment!</small>
								<?php endif; ?>
							</div>
							<?php else: ?>
							<p class="text-muted mt-2"><small>Build a barracks to recruit soldiers.</small></p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Weapons Stock -->
				<div class="col-md-6">
					<div class="card bg-dark border-info h-100">
						<div class="card-header border-info">
							<h5 class="mb-0">🗡️ Weapons Stock</h5>
						</div>
						<div class="card-body">
							<?php if (empty($townWeapons)): ?>
								<p class="text-muted">No weapons in stock.</p>
							<?php else: ?>
								<table class="table table-dark table-sm mb-0">
									<thead><tr><th>Weapon</th><th>⚔️ Atk</th><th>🛡️ Def</th><th>Stock</th></tr></thead>
									<tbody>
									<?php foreach ($townWeapons as $ws): ?>
										<tr>
											<td><?= htmlspecialchars($ws['weapon_name']) ?></td>
											<td class="text-danger"><?= $ws['attack_stat'] ?></td>
											<td class="text-info"><?= $ws['defense_stat'] ?></td>
											<td class="fw-bold"><?= number_format($ws['stock']) ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>

							<!-- Produce Weapons -->
							<?php if ((int)$townDetail['munitions_level'] > 0 && !empty($weaponTypes)): ?>
							<hr class="border-secondary">
							<?php
								$munWorkers = (int)$townDetail['munitions_workers'];
								$munAutoRate = floor($munWorkers / 20);
								$currentProdId = $townDetail['producing_weapon_type_id'];
								$currentProdName = 'Idle';
								if ($currentProdId) {
									foreach ($weaponTypes as $wt) {
										if ((int)$wt['id'] === (int)$currentProdId) {
											$currentProdName = htmlspecialchars($wt['name']);
											break;
										}
									}
								}
							?>
							<div class="mb-2">
								<small class="text-muted d-block">⚙️ Auto-Production: <span class="fw-bold text-info"><?= $munAutoRate ?> weapons/tick</span>
								(<?= $munWorkers ?> workers ÷ 20)
								&nbsp;|&nbsp; Producing: <span class="fw-bold <?= $currentProdId ? 'text-success' : 'text-warning' ?>"><?= $currentProdName ?></span></small>
								<?php if ($munWorkers === 0): ?>
								<small class="text-danger d-block">⚠️ Assign workers to the factory to start production!</small>
								<?php endif; ?>
							</div>

							<!-- Set Production Target -->
							<form method="post" action="military_action.php" class="row g-2 align-items-end mb-2">
								<input type="hidden" name="action" value="set_production">
								<input type="hidden" name="town_id" value="<?= $selectedTown ?>">
								<div class="col-md-6">
									<label class="form-label small">Production Target</label>
									<select name="weapon_type_id" class="form-select form-select-sm">
										<option value="0" <?= !$currentProdId ? 'selected' : '' ?>>⏸️ Idle (stop production)</option>
										<?php foreach ($weaponTypes as $wt): ?>
										<option value="<?= $wt['id'] ?>" <?= (int)$currentProdId === (int)$wt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($wt['name']) ?> (⚔️<?= $wt['attack_stat'] ?> 🛡️<?= $wt['defense_stat'] ?>)</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-auto">
									<button type="submit" class="btn btn-sm btn-info">🏭 Set Production</button>
								</div>
							</form>

							<?php elseif ((int)$townDetail['munitions_level'] === 0): ?>
							<p class="text-muted mt-2"><small>Build a munitions factory to produce weapons.</small></p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Arm / Re-arm Troops -->
				<?php
				$totalTroops = array_sum(array_column($townTroops, 'quantity'));
				?>
				<?php if ($totalTroops > 0 && !empty($townWeapons)): ?>
				<div class="col-md-6">
					<div class="card bg-dark border-success">
						<div class="card-header border-success">
							<h5 class="mb-0">🛡️ Arm / Re-arm Troops</h5>
						</div>
						<div class="card-body">
							<form method="post" action="military_action.php" class="row g-2 align-items-end">
								<input type="hidden" name="action" value="arm_troops">
								<input type="hidden" name="town_id" value="<?= $selectedTown ?>">
								<div class="col-md-4">
									<label class="form-label small">Troops</label>
									<select name="source_weapon_id" class="form-select form-select-sm" required>
										<?php foreach ($townTroops as $tr): ?>
										<option value="<?= $tr['weapon_type_id'] ?? 'NULL' ?>">
											<?= $tr['weapon_name'] ? htmlspecialchars($tr['weapon_name']) : 'Unarmed' ?>
											(<?= number_format($tr['quantity']) ?>)
										</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-4">
									<label class="form-label small">New Weapon</label>
									<select name="weapon_type_id" class="form-select form-select-sm" required>
										<?php foreach ($townWeapons as $ws): ?>
										<option value="<?= $ws['weapon_type_id'] ?>"><?= htmlspecialchars($ws['weapon_name']) ?> (<?= $ws['stock'] ?> stock)</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<label class="form-label small">Qty</label>
									<input type="number" name="quantity" class="form-control form-control-sm" value="10" min="1">
								</div>
								<div class="col-auto">
									<button type="submit" class="btn btn-sm btn-success">🛡️ Arm</button>
								</div>
							</form>
							<small class="text-muted mt-1 d-block">Re-arming returns old weapons to stock.</small>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<!-- Move / Attack -->
				<?php if (!empty($townTroops) && !empty($townAllTowns)): ?>
				<div class="col-md-6">
					<div class="card bg-dark border-danger">
						<div class="card-header border-danger">
							<h5 class="mb-0">🚀 Move / Attack</h5>
						</div>
						<div class="card-body">
							<form method="post" action="military_action.php" class="row g-2 align-items-end">
								<input type="hidden" name="action" value="move_troops">
								<input type="hidden" name="town_id" value="<?= $selectedTown ?>">
								<div class="col-md-3">
									<label class="form-label small">Troop Type</label>
									<select name="weapon_type_id" class="form-select form-select-sm">
										<?php foreach ($townTroops as $tr): ?>
										<option value="<?= $tr['weapon_type_id'] ?? 'NULL' ?>">
											<?= $tr['weapon_name'] ? htmlspecialchars($tr['weapon_name']) : 'Unarmed' ?>
											(<?= number_format($tr['quantity']) ?>)
										</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2">
									<label class="form-label small">Qty</label>
									<input type="number" name="quantity" class="form-control form-control-sm" value="10" min="1">
								</div>
								<div class="col-md-3">
									<label class="form-label small">Transport</label>
									<select name="transport" class="form-select form-select-sm">
										<option value="walk">🚶 Walk (2 km/h)</option>
										<?php foreach ($townMilVehicles as $mv2): ?>
										<option value="v_<?= $mv2['id'] ?>">🚛 <?= htmlspecialchars($mv2['type_name']) ?> #<?= $mv2['id'] ?> (<?= $mv2['max_speed_kmh'] ?> km/h, <?= (int)$mv2['max_capacity'] ?> cap)</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-3">
									<label class="form-label small">Destination</label>
									<select name="to_town_id" class="form-select form-select-sm" required>
										<optgroup label="Friendly Towns">
										<?php foreach ($townAllTowns as $at):
											if ($at['side'] === $townDetail['side']):
										?>
										<option value="<?= $at['id'] ?>">🟢 <?= htmlspecialchars($at['name']) ?></option>
										<?php endif; endforeach; ?>
										</optgroup>
										<optgroup label="⚔️ Enemy Towns (Attack)">
										<?php foreach ($townAllTowns as $at):
											if ($at['side'] !== $townDetail['side']):
										?>
										<option value="<?= $at['id'] ?>">🔴 <?= htmlspecialchars($at['name']) ?> (<?= ucfirst($at['side']) ?>)</option>
										<?php endif; endforeach; ?>
										</optgroup>
									</select>
								</div>
								<div class="col-auto">
									<button type="submit" class="btn btn-sm btn-danger">🚀 Send Troops</button>
								</div>
							</form>
							<small class="text-muted mt-1 d-block">Walk: 2 km/h on foot. Vehicle: uses vehicle speed &amp; capacity. Sending to enemy towns triggers combat.</small>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Active Troop Movements -->
			<?php if (!empty($movements)): ?>
			<div class="card bg-dark border-warning mt-4">
				<div class="card-header border-warning">
					<h5 class="mb-0">🚶 Active Troop Movements (<?= count($movements) ?>)</h5>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>From</th><th>To</th><th>Troops</th><th>Weapon</th>
									<th>Type</th><th>Transport</th><th>Speed</th><th>Distance</th><th>ETA</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($movements as $mv):
								$typeBadge = $mv['is_attack'] ? '<span class="badge bg-danger">⚔️ Attack</span>' : '<span class="badge bg-success">🏰 Reinforce</span>';
								$transportBadge = ($mv['transport_type'] ?? 'walk') === 'vehicle'
									? '<span class="badge bg-info">🚛 Vehicle</span>'
									: '<span class="badge bg-secondary">🚶 Walk</span>';
								$eta = (int)$mv['eta_ts'];
								$now = $dbNow;
								$remaining = max(0, $eta - $now);
								$mins = ceil($remaining / 60);
								$etaStr = $remaining > 0 ? "{$mins} min remaining" : '<span class="text-success">Arriving...</span>';
							?>
								<tr>
									<td><?= htmlspecialchars($mv['from_name']) ?></td>
									<td><?= htmlspecialchars($mv['to_name']) ?></td>
									<td class="fw-bold"><?= number_format($mv['quantity']) ?></td>
									<td><?= $mv['weapon_name'] ? htmlspecialchars($mv['weapon_name']) : '<span class="text-muted">Unarmed</span>' ?></td>
									<td><?= $typeBadge ?></td>
									<td><?= $transportBadge ?></td>
									<td><?= number_format($mv['speed_kmh'], 0) ?> km/h</td>
									<td><?= number_format($mv['distance_km'], 1) ?> km</td>
									<td><?= $etaStr ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Link to Combat Log -->
			<div class="mt-4">
				<a href="combat_log.php" class="btn btn-outline-danger"><i class="fa fa-scroll"></i> View Combat Log</a>
			</div>
		</div>
		<!-- END #content -->

<?php
include "files/scripts.php";
$conn->close();
?>
