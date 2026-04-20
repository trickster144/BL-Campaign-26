<?php
session_start();
include "files/config.php";
include "files/auth.php";
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();

// Fetch all factory types with outputs
$factoryTypes = [];
$ftRes = $conn->query("
    SELECT ft.type_id, ft.display_name, ft.power_per_level, ft.workers_per_level,
           ft.output_per_level, ft.output_resource_id,
           wp.resource_name AS output_name, wp.image_file AS output_image
    FROM factory_types ft
    JOIN world_prices wp ON ft.output_resource_id = wp.id
    ORDER BY ft.display_name
");
if ($ftRes) { while ($r = $ftRes->fetch_assoc()) $factoryTypes[$r['type_id']] = $r; }

// Fetch all factory inputs
$factoryInputs = [];
$fiRes = $conn->query("
    SELECT fi.factory_type_id, fi.amount_per_level, fi.resource_id,
           wp.resource_name, wp.image_file
    FROM factory_inputs fi
    JOIN world_prices wp ON fi.resource_id = wp.id
    ORDER BY fi.factory_type_id, wp.resource_name
");
if ($fiRes) { while ($r = $fiRes->fetch_assoc()) $factoryInputs[$r['factory_type_id']][] = $r; }

// Fetch power station types
$powerTypes = [];
$ptRes = $conn->query("SELECT * FROM power_station_types ORDER BY fuel_type");
if ($ptRes) { while ($r = $ptRes->fetch_assoc()) $powerTypes[] = $r; }

// Icon mapping for factories
$factoryIcons = [
    'aluminium_factory'   => 'fa-fire',
    'steel_factory'       => 'fa-hammer',
    'uranium_processing'  => 'fa-radiation',
    'brick_factory'       => 'fa-cubes',
    'prefab_factory'      => 'fa-building',
    'sawmill'             => 'fa-tree',
    'chemicals_factory'   => 'fa-flask',
    'electronics_factory' => 'fa-microchip',
    'explosives_factory'  => 'fa-bomb',
    'mechanical_factory'  => 'fa-cog',
    'plastics_factory'    => 'fa-recycle',
    'oil_refinery'        => 'fa-gas-pump',
    'vehicle_factory'     => 'fa-truck',
];

// Classify into tiers
$tier2 = ['sawmill','brick_factory','steel_factory','aluminium_factory','prefab_factory','uranium_processing','oil_refinery'];
$tier3_mid = ['chemicals_factory','plastics_factory'];
$tier3_adv = ['electronics_factory','explosives_factory','mechanical_factory','vehicle_factory'];

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">Production Guide <small class="text-inverse text-opacity-50">Resource Chain Reference</small></h1>

			<!-- ============================================================ -->
			<!-- PRODUCTION CHAIN OVERVIEW -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-sitemap me-1"></i>Production Chain Overview</div>
				<div class="card-body">
					<div class="row text-center">
						<!-- Tier 1: Raw -->
						<div class="col-lg-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-header fw-bold small bg-success bg-opacity-25">Tier 1 — Raw Extraction</div>
								<div class="card-body py-2">
									<p class="small text-inverse text-opacity-50 mb-2">Mines, Oil Wells &amp; Forestry</p>
									<?php
									$rawResources = ['Stone','Coal','Iron','Bauxite','Uranium','Wood','Oil'];
									foreach ($rawResources as $rr):
									?>
										<span class="badge bg-secondary mb-1"><?= $rr ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<!-- Arrow -->
						<div class="col-lg-1 d-flex align-items-center justify-content-center">
							<i class="fa fa-arrow-right fa-2x text-theme"></i>
						</div>
						<!-- Tier 2: Basic Processing -->
						<div class="col-lg-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-header fw-bold small bg-warning bg-opacity-25">Tier 2 — Basic Processing</div>
								<div class="card-body py-2">
									<p class="small text-inverse text-opacity-50 mb-2">Factories using raw materials</p>
									<?php foreach ($tier2 as $tid):
										if (!isset($factoryTypes[$tid])) continue;
										$ft = $factoryTypes[$tid];
									?>
										<span class="badge bg-warning text-dark mb-1"><?= htmlspecialchars($ft['output_name']) ?></span>
									<?php endforeach; ?>
									<br>
									<?php foreach ($tier3_mid as $tid):
										if (!isset($factoryTypes[$tid])) continue;
										$ft = $factoryTypes[$tid];
									?>
										<span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($ft['output_name']) ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
						<!-- Arrow -->
						<div class="col-lg-1 d-flex align-items-center justify-content-center">
							<i class="fa fa-arrow-right fa-2x text-theme"></i>
						</div>
						<!-- Tier 3: Advanced -->
						<div class="col-lg-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-header fw-bold small bg-danger bg-opacity-25">Tier 3 — Advanced Manufacturing</div>
								<div class="card-body py-2">
									<p class="small text-inverse text-opacity-50 mb-2">Factories using processed materials</p>
									<?php foreach ($tier3_adv as $tid):
										if (!isset($factoryTypes[$tid])) continue;
										$ft = $factoryTypes[$tid];
									?>
										<span class="badge bg-danger mb-1"><?= htmlspecialchars($ft['output_name']) ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- RAW RESOURCE EXTRACTION -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-mountain me-1"></i>Raw Resource Extraction</div>
				<div class="card-body">
					<p class="small text-inverse text-opacity-50 mb-3">Each town is assigned one raw resource speciality. Mines/wells/forestry produce 1 tonne per hour per level.</p>
					<table class="table table-hover table-sm">
						<thead>
							<tr>
								<th>Resource</th>
								<th>Facility Type</th>
								<th>Base Output</th>
								<th>Power/Level</th>
								<th>Workers/Level</th>
							</tr>
						</thead>
						<tbody>
							<tr><td>Stone</td><td>Stone Mine</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Coal</td><td>Coal Mine</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Iron</td><td>Iron Mine</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Bauxite</td><td>Bauxite Mine</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Uranium</td><td>Uranium Mine</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Wood</td><td>Forestry</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
							<tr><td>Oil</td><td>Oil Well</td><td>1 t/hr</td><td>1 MW</td><td>100</td></tr>
						</tbody>
					</table>
					<div class="alert alert-secondary py-2 small mb-0">
						<strong>Formula:</strong> Actual Output = Base Rate × Level × (Workers ÷ Max Workers) × Power Efficiency
					</div>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- PROCESSING FACTORIES -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-industry me-1"></i>Processing Factories</div>
				<div class="card-body">
					<p class="small text-inverse text-opacity-50 mb-3">Factories convert raw or processed resources into higher-value goods. Any town can build any factory. All values are per level per hour.</p>
					<div class="table-responsive">
						<table class="table table-hover">
							<thead>
								<tr>
									<th style="width:5%"></th>
									<th style="width:18%">Factory</th>
									<th style="width:32%">Inputs (per level/hr)</th>
									<th style="width:20%">Output (per level/hr)</th>
									<th style="width:10%">Power/lvl</th>
									<th style="width:10%">Workers/lvl</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($factoryTypes as $tid => $ft):
								$icon = $factoryIcons[$tid] ?? 'fa-industry';
								$ins = $factoryInputs[$tid] ?? [];
								$outImg = $ft['output_image'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($ft['output_image']) . '" alt="" width="24" height="24">' : '';
							?>
								<tr>
									<td class="text-center"><i class="fa <?= $icon ?> text-theme"></i></td>
									<td class="fw-bold"><?= htmlspecialchars($ft['display_name']) ?></td>
									<td>
										<?php foreach ($ins as $i => $inp):
											$inImg = $inp['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($inp['image_file']) . '" alt="" width="20" height="20">' : '';
										?>
											<?= $i > 0 ? '<span class="text-muted mx-1">+</span>' : '' ?>
											<?= $inImg ?>
											<span class="small"><?= number_format($inp['amount_per_level'], 1) ?>t <?= htmlspecialchars($inp['resource_name']) ?></span>
										<?php endforeach; ?>
									</td>
									<td>
										<?= $outImg ?>
										<span class="fw-bold text-theme"><?= number_format($ft['output_per_level'], 1) ?>t</span>
										<span class="small"><?= htmlspecialchars($ft['output_name']) ?></span>
									</td>
									<td><?= number_format($ft['power_per_level'], 1) ?> MW</td>
									<td><?= number_format($ft['workers_per_level']) ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="alert alert-secondary py-2 small mb-0">
						<strong>Formula:</strong> Actual Output = Output/lvl × Level × (Workers ÷ Max Workers) × Power Eff × Input Availability<br>
						<strong>Max Workers:</strong> Level × Workers/lvl &nbsp;|&nbsp; <strong>Power Required:</strong> Level × Power/lvl MW
					</div>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- POWER GENERATION -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-bolt me-1"></i>Power Generation</div>
				<div class="card-body">
					<p class="small text-inverse text-opacity-50 mb-3">Power stations burn fuel to generate electricity. Power is shared across towns connected by transmission lines (same faction only).</p>
					<table class="table table-hover table-sm">
						<thead>
							<tr>
								<th>Station Type</th>
								<th>Fuel Resource</th>
								<th>Fuel per MW/hr</th>
								<th>Output/Level</th>
								<th>Workers/Level</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($powerTypes as $pt): ?>
							<tr>
								<td class="fw-bold"><?= htmlspecialchars($pt['display_name']) ?></td>
								<td><?= htmlspecialchars($pt['fuel_resource_name']) ?></td>
								<td><?= number_format($pt['fuel_per_mw_hr'], 2) ?> t/MW/hr</td>
								<td>1.0 MW</td>
								<td>100</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<div class="row mt-3">
						<div class="col-md-6">
							<div class="alert alert-info py-2 small mb-0">
								<strong>Efficiency:</strong> Nuclear is 100× more fuel-efficient than coal, but uranium processing is complex.
							</div>
						</div>
						<div class="col-md-6">
							<div class="alert alert-warning py-2 small mb-0">
								<strong>Grid System:</strong> Power is pooled across connected towns. If demand exceeds supply, all buildings operate at reduced efficiency.
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- HOUSING -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-home me-1"></i>Housing</div>
				<div class="card-body">
					<p class="small text-inverse text-opacity-50 mb-3">Each home supports 5 citizens. Population cannot exceed housing capacity.</p>
					<table class="table table-hover table-sm">
						<thead>
							<tr>
								<th>Type</th>
								<th>Homes Added</th>
								<th>Citizens Added</th>
								<th>Build Cost</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="fw-bold">House (default)</td>
								<td>1</td>
								<td>5</td>
								<td class="text-inverse text-opacity-50">Pre-built (1000 per town)</td>
							</tr>
							<tr>
								<td class="fw-bold">Small Flats</td>
								<td>10</td>
								<td>50</td>
								<td>100t Bricks + 10t Wooden Boards + 3t Steel</td>
							</tr>
							<tr>
								<td class="fw-bold">Medium Flats</td>
								<td>50</td>
								<td>250</td>
								<td>450t Bricks + 45t Wooden Boards + 13.5t Steel</td>
							</tr>
							<tr>
								<td class="fw-bold">Large Flats</td>
								<td>100</td>
								<td>500</td>
								<td>700t Prefab Panels + 70t Wooden Boards + 21t Steel</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- ============================================================ -->
			<!-- DETAILED PRODUCTION RECIPES -->
			<!-- ============================================================ -->
			<div class="card mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-list me-1"></i>Detailed Production Recipes</div>
				<div class="card-body">
					<div class="row">
					<?php foreach ($factoryTypes as $tid => $ft):
						$icon = $factoryIcons[$tid] ?? 'fa-industry';
						$ins = $factoryInputs[$tid] ?? [];
						$outImg = $ft['output_image'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($ft['output_image']) . '" alt="" width="28" height="28">' : '';
					?>
						<div class="col-lg-4 col-md-6 mb-3">
							<div class="card bg-dark bg-opacity-50 h-100">
								<div class="card-header d-flex justify-content-between align-items-center">
									<span class="fw-bold small"><i class="fa <?= $icon ?> me-1 text-theme"></i><?= htmlspecialchars($ft['display_name']) ?></span>
								</div>
								<div class="card-body py-2">
									<table class="table table-sm table-borderless mb-2">
										<thead><tr><th colspan="2" class="text-inverse text-opacity-50 small py-0">Consumes per level/hr</th></tr></thead>
										<tbody>
										<?php foreach ($ins as $inp):
											$inImg = $inp['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($inp['image_file']) . '" alt="" width="20" height="20">' : '';
										?>
											<tr>
												<td class="py-0"><?= $inImg ?> <?= htmlspecialchars($inp['resource_name']) ?></td>
												<td class="py-0 text-end fw-bold text-danger"><?= number_format($inp['amount_per_level'], 1) ?>t</td>
											</tr>
										<?php endforeach; ?>
										</tbody>
									</table>
									<table class="table table-sm table-borderless mb-2">
										<thead><tr><th colspan="2" class="text-inverse text-opacity-50 small py-0">Produces per level/hr</th></tr></thead>
										<tbody>
											<tr class="table-active">
												<td class="py-0"><?= $outImg ?> <?= htmlspecialchars($ft['output_name']) ?></td>
												<td class="py-0 text-end fw-bold text-success"><?= number_format($ft['output_per_level'], 1) ?>t</td>
											</tr>
										</tbody>
									</table>
									<div class="d-flex justify-content-between small text-inverse text-opacity-50">
										<span><i class="fa fa-bolt me-1"></i><?= number_format($ft['power_per_level'], 1) ?> MW/lvl</span>
										<span><i class="fa fa-users me-1"></i><?= number_format($ft['workers_per_level']) ?>/lvl</span>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
					</div>
				</div>
			</div>

			<!-- Transport & Vehicles -->
			<div class="card bg-dark border-info mb-3">
				<div class="card-header fw-bold small"><i class="fa fa-truck me-1"></i>Transport — Vehicles & Trains</div>
				<div class="card-body small">
					<div class="row">
						<div class="col-md-6">
							<h6 class="text-info">🚛 Road Vehicles</h6>
							<ul class="mb-2">
								<li>Vehicles carry <strong>one cargo class</strong> per trip (Aggregates, Open Storage, Warehouse, Liquids)</li>
								<li><strong>Warehouse</strong> class is special — can mix multiple resource types in one load</li>
								<li>All other classes: single resource type per trip, but the vehicle can carry different resources on different trips</li>
								<li>Vehicles are bought at Customs Houses or built at Vehicle Factories</li>
								<li>Dispatch from the town view — select destination, load cargo, send!</li>
							</ul>
							<h6 class="text-warning">Road Types & Speed Limits</h6>
							<table class="table table-dark table-sm mb-2">
								<thead><tr><th>Road</th><th>Limit</th></tr></thead>
								<tbody>
									<tr><td>Mud</td><td>30 km/h</td></tr>
									<tr><td>Gravel</td><td>60 km/h</td></tr>
									<tr><td>Asphalt</td><td>90 km/h</td></tr>
									<tr><td>Dual Lane</td><td>130 km/h</td></tr>
								</tbody>
							</table>
						</div>
						<div class="col-md-6">
							<h6 class="text-warning">🚂 Trains</h6>
							<p>Trains consist of <strong>1 locomotive + up to 30 wagons/carriages</strong>.</p>
							<h6 class="text-muted">Locomotive Types</h6>
							<table class="table table-dark table-sm mb-2">
								<thead><tr><th>Type</th><th>Fuel</th><th>Notes</th></tr></thead>
								<tbody>
									<tr><td>🔥 Steam</td><td>Coal</td><td>Cheapest, slowest</td></tr>
									<tr><td>⛽ Diesel</td><td>Fuel</td><td>Mid-tier, flexible</td></tr>
									<tr><td>⚡ Electric</td><td>None</td><td>Fast, no fuel — requires electrified rail</td></tr>
								</tbody>
							</table>
							<h6 class="text-muted">Wagon Types</h6>
							<ul class="mb-2">
								<li><strong>Hopper Wagon</strong> — Aggregates (Stone, Coal, Iron, etc.)</li>
								<li><strong>Flatcar</strong> — Open Storage (Wood, Steel, Bricks, etc.)</li>
								<li><strong>Box Car</strong> — Warehouse (Plastics, Chemicals, etc.)</li>
								<li><strong>Tank Car</strong> — Liquids (Oil, Fuel)</li>
								<li><strong>Passenger Coach</strong> — Moves people</li>
							</ul>
							<h6 class="text-muted">Rail Types</h6>
							<table class="table table-dark table-sm mb-2">
								<thead><tr><th>Rail</th><th>Limit</th></tr></thead>
								<tbody>
									<tr><td>Basic</td><td>60 km/h</td></tr>
									<tr><td>Electrified</td><td>120 km/h</td></tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="alert alert-info bg-opacity-10 border-info small mt-2 mb-0">
						<strong>How trains work:</strong>
						<ol class="mb-0">
							<li><strong>Buy or Build</strong> — Purchase locos/wagons at Customs or build at Vehicle Factory</li>
							<li><strong>Compose</strong> — In a town, combine 1 loco + wagons into a train</li>
							<li><strong>Load & Dispatch</strong> — Select destination, load cargo into wagons, send</li>
							<li><strong>In Transit</strong> — Speed = min(loco speed, rail speed limit). Fuel deducted at departure</li>
							<li><strong>Arrival</strong> — Cargo unloaded, train returns empty if requested</li>
							<li><strong>Decompose</strong> — Split train back into individual loco + wagons when done</li>
						</ol>
					</div>
				</div>
			</div>

		</div>
		<!-- END #content -->
<?php
include "files/scripts.php";
?>
