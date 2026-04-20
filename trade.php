<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);

// Determine which factions this user can trade for
$allowedFactions = viewableFactions($user);
$selectedFaction = $_GET['faction'] ?? $allowedFactions[0] ?? 'blue';
if (!in_array($selectedFaction, $allowedFactions)) $selectedFaction = $allowedFactions[0];

// Get faction balances
$balances = ['blue' => 0, 'red' => 0];
$bRes = $conn->query("SELECT faction, points FROM faction_balance");
if ($bRes) { while ($b = $bRes->fetch_assoc()) $balances[$b['faction']] = (float)$b['points']; }

// Get customs house IDs
$customsIds = [];
$cRes = $conn->query("SELECT id, name, side FROM towns WHERE name LIKE 'Customs House%'");
if ($cRes) { while ($c = $cRes->fetch_assoc()) $customsIds[$c['side']] = (int)$c['id']; }

// Get all resources with current prices and volume
$resources = [];
$rRes = $conn->query("
    SELECT wp.id, wp.resource_name, wp.resource_type, wp.image_file,
           wp.buy_price, wp.sell_price, wp.base_buy_price, wp.base_sell_price,
           COALESCE(pv.total_bought, 0) as total_bought, COALESCE(pv.total_sold, 0) as total_sold
    FROM world_prices wp
    LEFT JOIN price_volume pv ON wp.id = pv.resource_id
    ORDER BY wp.resource_type, wp.resource_name
");
if ($rRes) { while ($r = $rRes->fetch_assoc()) $resources[] = $r; }

// Get customs house inventories
$customsStock = ['blue' => [], 'red' => []];
foreach ($customsIds as $faction => $cid) {
    $sRes = $conn->query("SELECT resource_id, stock FROM town_resources WHERE town_id = $cid AND stock > 0");
    if ($sRes) { while ($s = $sRes->fetch_assoc()) $customsStock[$faction][(int)$s['resource_id']] = (float)$s['stock']; }
}

// Recent trade history
$history = [];
$hRes = $conn->query("
    SELECT tl.*, wp.resource_name, wp.image_file
    FROM trade_log tl
    JOIN world_prices wp ON tl.resource_id = wp.id
    ORDER BY tl.created_at DESC LIMIT 50
");
if ($hRes) { while ($h = $hRes->fetch_assoc()) $history[] = $h; }

// Message handling
$msg = $_GET['msg'] ?? '';
$detail = $_GET['detail'] ?? '';

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">🏪 Customs House Trading</h1>

			<?php if ($msg === 'success'): ?>
			<div class="alert alert-success alert-dismissible fade show">✅ <?= htmlspecialchars($detail) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'insufficient_funds'): ?>
			<div class="alert alert-danger alert-dismissible fade show">❌ Not enough points!<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'insufficient_stock'): ?>
			<div class="alert alert-danger alert-dismissible fade show">❌ Not enough stock in customs house! Transport resources there first.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php endif; ?>

			<!-- Faction selector + Balance -->
			<div class="row mb-3">
				<div class="col-md-6">
					<div class="btn-group w-100" role="group">
						<?php if (in_array('blue', $allowedFactions)): ?>
						<a href="trade.php?faction=blue" class="btn btn-lg <?= $selectedFaction === 'blue' ? 'btn-primary' : 'btn-outline-primary' ?>">
							🔵 Blue Team — <?= number_format($balances['blue'], 2) ?> pts
						</a>
						<?php endif; ?>
						<?php if (in_array('red', $allowedFactions)): ?>
						<a href="trade.php?faction=red" class="btn btn-lg <?= $selectedFaction === 'red' ? 'btn-danger' : 'btn-outline-danger' ?>">
							🔴 Red Team — <?= number_format($balances['red'], 2) ?> pts
						</a>
						<?php endif; ?>
					</div>
				</div>
				<div class="col-md-6">
					<div class="card bg-dark border-secondary">
						<div class="card-body py-2">
							<strong>📍 Trading at:</strong>
							<?= $selectedFaction === 'blue' ? 'Customs House West' : 'Customs House East' ?>
							<br><small class="text-muted">Buy: items delivered to customs house · Sell: items must be in customs house</small>
						</div>
					</div>
				</div>
			</div>

			<!-- How Trading Works -->
			<div class="card bg-dark border-info mb-3">
				<div class="card-header border-info cursor-pointer" data-bs-toggle="collapse" data-bs-target="#tradeHelp" style="cursor:pointer">
					<h6 class="mb-0">ℹ️ How Trading Works <small class="text-muted">(click to expand)</small></h6>
				</div>
				<div id="tradeHelp" class="collapse">
					<div class="card-body small">
						<div class="row">
							<div class="col-md-6">
								<h6 class="text-info">🛒 Buying Resources</h6>
								<ul>
									<li>Click <strong>Buy 1t</strong> to purchase 1 tonne of a resource</li>
									<li>Cost is deducted from your team's <strong>points balance</strong></li>
									<li>The resource is delivered to your <strong>Customs House</strong></li>
									<li>Use <strong>vehicles</strong> to transport resources from the customs house to your towns</li>
								</ul>
							</div>
							<div class="col-md-6">
								<h6 class="text-warning">💰 Selling Resources</h6>
								<ul>
									<li>Resources must be <strong>physically in the customs house</strong> to sell</li>
									<li>Transport resources to the customs house using <strong>vehicles</strong></li>
									<li>Click <strong>Sell 1t</strong> — points are added to your team's balance</li>
									<li>Sell price is 5% lower than buy price</li>
								</ul>
							</div>
						</div>
						<hr class="border-secondary">
						<h6 class="text-success">📈 Price Fluctuation</h6>
						<p>Prices change based on global market activity. For every <strong>100 tonnes bought</strong>, prices increase by <strong>0.1%</strong>.
						For every <strong>100 tonnes sold</strong>, prices decrease by <strong>0.1%</strong>. Both teams share the same market prices.</p>
					</div>
				</div>
			</div>

			<!-- Buy / Sell Tables -->
			<div class="row">
				<!-- BUY Column -->
				<div class="col-lg-6 mb-3">
					<div class="card bg-dark border-success">
						<div class="card-header border-success"><h5 class="mb-0">🛒 Buy Resources</h5></div>
						<div class="card-body p-0">
							<div class="table-responsive">
								<table class="table table-dark table-hover table-sm mb-0">
									<thead><tr><th></th><th>Resource</th><th>Buy Price</th><th>Trend</th><th colspan="2">Action</th></tr></thead>
									<tbody>
									<?php
									$currentType = '';
									foreach ($resources as $r):
										if ($r['resource_type'] !== $currentType):
											$currentType = $r['resource_type'];
									?>
										<tr class="table-secondary"><td colspan="5" class="fw-bold small text-dark"><?= htmlspecialchars($currentType) ?></td></tr>
									<?php endif;
										$img = $r['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($r['image_file']) . '" width="20" height="20">' : '';
										$priceDiff = (float)$r['buy_price'] - (float)$r['base_buy_price'];
										$trendIcon = $priceDiff > 0.01 ? '<span class="text-danger">▲</span>' : ($priceDiff < -0.01 ? '<span class="text-success">▼</span>' : '<span class="text-muted">—</span>');
									?>
										<tr>
											<td><?= $img ?></td>
											<td class="small"><?= htmlspecialchars($r['resource_name']) ?></td>
											<td class="fw-bold"><?= number_format($r['buy_price'], 2) ?></td>
											<td><?= $trendIcon ?></td>
											<td>
												<form method="post" action="trade_action.php" class="d-flex gap-1">
													<input type="hidden" name="faction" value="<?= $selectedFaction ?>">
													<input type="hidden" name="resource_id" value="<?= $r['id'] ?>">
													<input type="hidden" name="trade_action" value="buy">
													<input type="number" name="quantity" value="1" min="1" max="10000" class="form-control form-control-sm" style="width:70px;">
													<button type="submit" class="btn btn-sm btn-outline-success py-0 px-2">Buy</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>

				<!-- SELL Column -->
				<div class="col-lg-6 mb-3">
					<div class="card bg-dark border-warning">
						<div class="card-header border-warning"><h5 class="mb-0">💰 Sell Resources <small class="text-muted">(from <?= $selectedFaction === 'blue' ? 'Customs West' : 'Customs East' ?>)</small></h5></div>
						<div class="card-body p-0">
							<div class="table-responsive">
								<table class="table table-dark table-hover table-sm mb-0">
									<thead><tr><th></th><th>Resource</th><th>In Stock</th><th>Sell Price</th><th>Action</th></tr></thead>
									<tbody>
									<?php
									$hasStock = false;
									foreach ($resources as $r):
										$stock = $customsStock[$selectedFaction][(int)$r['id']] ?? 0;
										if ($stock <= 0) continue;
										$hasStock = true;
										$img = $r['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($r['image_file']) . '" width="20" height="20">' : '';
									?>
										<tr>
											<td><?= $img ?></td>
											<td class="small"><?= htmlspecialchars($r['resource_name']) ?></td>
											<td class="fw-bold"><?= number_format($stock, 2) ?>t</td>
											<td class="fw-bold text-warning"><?= number_format($r['sell_price'], 2) ?></td>
											<td>
												<form method="post" action="trade_action.php" class="d-flex gap-1">
													<input type="hidden" name="faction" value="<?= $selectedFaction ?>">
													<input type="hidden" name="resource_id" value="<?= $r['id'] ?>">
													<input type="hidden" name="trade_action" value="sell">
													<input type="number" name="quantity" value="1" min="1" max="<?= floor($stock) ?>" class="form-control form-control-sm" style="width:70px;">
													<button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2">Sell</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
									<?php if (!$hasStock): ?>
										<tr><td colspan="5" class="text-center text-muted py-3">No resources in customs house.<br><small>Transport resources here using vehicles to sell them.</small></td></tr>
									<?php endif; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Vehicle Purchase -->
			<div class="card bg-dark border-info mb-3">
				<div class="card-header border-info"><h5 class="mb-0">🚛 Buy Vehicles <small class="text-muted">(delivered to <?= $selectedFaction === 'blue' ? 'Customs West' : 'Customs East' ?>)</small></h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr><th>Vehicle</th><th>Cat</th><th>Class</th><th>Cargo</th><th>Speed</th><th>Capacity</th><th>Fuel Cap</th><th>Price</th><th></th></tr></thead>
							<tbody>
							<?php
							$vtRes = $conn->query("SELECT vt.* FROM vehicle_types vt WHERE vt.active = 1 ORDER BY vt.points_price");
							$hasVehicles = false;
							if ($vtRes) { while ($vt = $vtRes->fetch_assoc()): $hasVehicles = true; ?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars($vt['name']) ?></td>
									<td><?= $vt['category'] === 'military' ? '<span class="badge bg-danger">MIL</span>' : '<span class="badge bg-info">CIV</span>' ?></td>
									<td><?= $vt['vehicle_class'] === 'cargo' ? '<span class="badge bg-success">Cargo</span>' : '<span class="badge bg-primary">Pax</span>' ?></td>
									<td class="small"><?= $vt['cargo_class'] ?? '—' ?></td>
									<td><?= $vt['max_speed_kmh'] ?> km/h</td>
									<td><?= $vt['max_capacity'] ?></td>
									<td><?= $vt['fuel_capacity'] ?></td>
									<td class="fw-bold text-info"><?= number_format($vt['points_price'], 2) ?> pts</td>
									<td>
										<form method="post" action="trade_action.php" class="d-inline">
											<input type="hidden" name="faction" value="<?= $selectedFaction ?>">
											<input type="hidden" name="trade_action" value="buy_vehicle">
											<input type="hidden" name="vehicle_type_id" value="<?= $vt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-info py-0 px-2"
												<?= $balances[$selectedFaction] < $vt['points_price'] ? 'disabled' : '' ?>>
												Buy
											</button>
										</form>
									</td>
								</tr>
							<?php endwhile; }
							if (!$hasVehicles): ?>
								<tr><td colspan="9" class="text-center text-muted py-3">No vehicle types available. Admin must add them first.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Locomotive Purchase -->
			<div class="card bg-dark border-warning mb-3">
				<div class="card-header border-warning"><h5 class="mb-0">🚂 Buy Locomotives <small class="text-muted">(delivered to <?= $selectedFaction === 'blue' ? 'Customs West' : 'Customs East' ?>)</small></h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr><th>Locomotive</th><th>Propulsion</th><th>Speed</th><th>Fuel/km</th><th>Max Wagons</th><th>Price</th><th></th></tr></thead>
							<tbody>
							<?php
							$ltRes = $conn->query("SELECT lt.*, wp.resource_name as fuel_name FROM locomotive_types lt LEFT JOIN world_prices wp ON lt.fuel_resource_id = wp.id WHERE lt.active = 1 ORDER BY lt.points_price");
							$hasLocos = false;
							if ($ltRes) { while ($lt = $ltRes->fetch_assoc()): $hasLocos = true;
								$pIcon = match($lt['propulsion']) { 'steam'=>'🔥','diesel'=>'⛽','electric'=>'⚡',default=>'🚂' };
							?>
								<tr>
									<td class="fw-bold"><?= $pIcon ?> <?= htmlspecialchars($lt['name']) ?></td>
									<td><span class="badge bg-<?= match($lt['propulsion']) {'steam'=>'secondary','diesel'=>'warning text-dark','electric'=>'info',default=>'dark'} ?>"><?= ucfirst($lt['propulsion']) ?></span></td>
									<td><?= $lt['max_speed_kmh'] ?> km/h</td>
									<td><?= $lt['fuel_per_km'] ?> t <?= $lt['fuel_name'] ?? '' ?></td>
									<td><?= $lt['max_wagons'] ?></td>
									<td class="fw-bold text-warning"><?= number_format($lt['points_price']) ?> pts</td>
									<td>
										<form method="post" action="trade_action.php" class="d-inline">
											<input type="hidden" name="faction" value="<?= $selectedFaction ?>">
											<input type="hidden" name="trade_action" value="buy_locomotive">
											<input type="hidden" name="loco_type_id" value="<?= $lt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-warning py-0 px-2"
												<?= $balances[$selectedFaction] < $lt['points_price'] ? 'disabled' : '' ?>>Buy</button>
										</form>
									</td>
								</tr>
							<?php endwhile; }
							if (!$hasLocos): ?>
								<tr><td colspan="7" class="text-center text-muted py-3">No locomotive types available. Run setup_trains.php first.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Wagon Purchase -->
			<div class="card bg-dark border-success mb-3">
				<div class="card-header border-success"><h5 class="mb-0">🚃 Buy Wagons & Carriages <small class="text-muted">(delivered to <?= $selectedFaction === 'blue' ? 'Customs West' : 'Customs East' ?>)</small></h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead><tr><th>Wagon</th><th>Type</th><th>Cargo Class</th><th>Capacity</th><th>Price</th><th></th></tr></thead>
							<tbody>
							<?php
							$wtRes = $conn->query("SELECT * FROM wagon_types WHERE active = 1 ORDER BY points_price");
							$hasWagons = false;
							if ($wtRes) { while ($wt = $wtRes->fetch_assoc()): $hasWagons = true; ?>
								<tr>
									<td class="fw-bold"><?= $wt['wagon_class'] === 'passenger' ? '👥' : '📦' ?> <?= htmlspecialchars($wt['name']) ?></td>
									<td><span class="badge bg-<?= $wt['wagon_class'] === 'cargo' ? 'success' : 'primary' ?>"><?= ucfirst($wt['wagon_class']) ?></span></td>
									<td class="small"><?= $wt['cargo_class'] ?? '—' ?></td>
									<td><?= $wt['max_capacity'] ?> <?= $wt['wagon_class'] === 'passenger' ? 'pax' : 't' ?></td>
									<td class="fw-bold text-success"><?= number_format($wt['points_price']) ?> pts</td>
									<td>
										<form method="post" action="trade_action.php" class="d-inline">
											<input type="hidden" name="faction" value="<?= $selectedFaction ?>">
											<input type="hidden" name="trade_action" value="buy_wagon">
											<input type="hidden" name="wagon_type_id" value="<?= $wt['id'] ?>">
											<button type="submit" class="btn btn-sm btn-outline-success py-0 px-2"
												<?= $balances[$selectedFaction] < $wt['points_price'] ? 'disabled' : '' ?>>Buy</button>
										</form>
									</td>
								</tr>
							<?php endwhile; }
							if (!$hasWagons): ?>
								<tr><td colspan="6" class="text-center text-muted py-3">No wagon types available. Run setup_trains.php first.</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Trade History -->
			<div class="card bg-dark border-secondary">
				<div class="card-header border-secondary"><h5 class="mb-0">📜 Trade History <small class="text-muted">(Last 50)</small></h5></div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-striped table-hover table-sm mb-0">
							<thead><tr><th>Time</th><th>Team</th><th>Action</th><th>Resource</th><th>Qty</th><th>Price/t</th><th>Total</th></tr></thead>
							<tbody>
							<?php foreach ($history as $h):
								$teamBadge = $h['faction'] === 'blue'
									? '<span class="badge bg-primary">Blue</span>'
									: '<span class="badge bg-danger">Red</span>';
								$actionBadge = $h['action'] === 'buy'
									? '<span class="badge bg-success">BUY</span>'
									: '<span class="badge bg-warning text-dark">SELL</span>';
								$img = $h['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($h['image_file']) . '" width="16" height="16"> ' : '';
							?>
								<tr>
									<td class="small"><?= $h['created_at'] ?></td>
									<td><?= $teamBadge ?></td>
									<td><?= $actionBadge ?></td>
									<td class="small"><?= $img . htmlspecialchars($h['resource_name']) ?></td>
									<td><?= number_format($h['quantity'], 1) ?>t</td>
									<td><?= number_format($h['price_per_unit'], 2) ?></td>
									<td class="fw-bold"><?= number_format($h['total_cost'], 2) ?></td>
								</tr>
							<?php endforeach; ?>
							<?php if (empty($history)): ?>
								<tr><td colspan="7" class="text-center text-muted py-3">No trades yet</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<!-- END #content -->
<?php
include "files/scripts.php";
$conn->close();
?>

