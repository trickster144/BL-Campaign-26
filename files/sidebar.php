		<!-- BEGIN #sidebar -->
		<div id="sidebar" class="app-sidebar">
			<!-- BEGIN scrollbar -->
			<div class="app-sidebar-content" data-scrollbar="true" data-height="100%">
				<!-- BEGIN menu -->
				<?php
				$_sidebarTeam = $_SESSION['team'] ?? 'grey';
				$_sidebarLoggedIn = isset($_SESSION['user_id']);
				$_sidebarIsPlayer = in_array($_sidebarTeam, ['blue', 'red', 'green']);
				$_sidebarIsGreen = $_sidebarTeam === 'green';
				$_sidebarIsMod = in_array($_SESSION['account_type'] ?? '', ['admin', 'mod']) || $_sidebarIsGreen;
				$_currentPage = basename($_SERVER['PHP_SELF']);
				?>
				<div class="menu">
					<!-- ── Overview ── -->
					<div class="menu-header">Overview</div>
					<div class="menu-item <?= $_currentPage === 'index.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>index.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-house-door"></i></span>
							<span class="menu-text">Home</span>
						</a>
					</div>
					<?php if ($_sidebarLoggedIn): ?>
					<div class="menu-item <?= $_currentPage === 'map.php' || $_currentPage === 'map_hex.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>map.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-map"></i></span>
							<span class="menu-text">Map</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'guide.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>guide.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-book"></i></span>
							<span class="menu-text">Production Guide</span>
						</a>
					</div>
					<?php endif; ?>

					<?php if ($_sidebarIsPlayer): ?>
					<!-- ── Economy ── -->
					<div class="menu-header">Economy</div>
					<div class="menu-item <?= $_currentPage === 'towns.php' || $_currentPage === 'town_view.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>towns.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-building"></i></span>
							<span class="menu-text">Towns</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'trade.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>trade.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-shop"></i></span>
							<span class="menu-text">Trade</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'power.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>power.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-lightning-charge"></i></span>
							<span class="menu-text">Power Grid</span>
						</a>
					</div>

					<!-- ── Transport ── -->
					<div class="menu-header">Transport</div>
					<div class="menu-item <?= $_currentPage === 'vehicles.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>vehicles.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-collection"></i></span>
							<span class="menu-text">Vehicle Fleet</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'transit.php' && ($_GET['tab'] ?? 'vehicles') === 'vehicles' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>transit.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-truck"></i></span>
							<span class="menu-text">Vehicles in Transit</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'transit.php' && ($_GET['tab'] ?? '') === 'trains' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>transit.php?tab=trains" class="menu-link">
							<span class="menu-icon"><i class="bi bi-train-front"></i></span>
							<span class="menu-text">Trains in Transit</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'auto_transport.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>auto_transport.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-arrow-repeat"></i></span>
							<span class="menu-text">Auto Transport</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'auto_trade.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>auto_trade.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-cart-check"></i></span>
							<span class="menu-text">Auto Trade</span>
						</a>
					</div>

					<!-- ── Military ── -->
					<div class="menu-header">Military</div>
					<div class="menu-item <?= $_currentPage === 'military.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>military.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-shield-fill-exclamation"></i></span>
							<span class="menu-text">Command</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'combat_log.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>combat_log.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-journal-text"></i></span>
							<span class="menu-text">Combat Log</span>
						</a>
					</div>
					<?php endif; ?>

					<?php if ($_sidebarIsMod): ?>
					<!-- ── Admin ── -->
					<div class="menu-header">Admin</div>
					<div class="menu-item <?= $_currentPage === 'manage_teams.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>manage_teams.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-people"></i></span>
							<span class="menu-text">Manage Teams</span>
						</a>
					</div>
					<?php endif; ?>
					<?php if ($_sidebarIsGreen): ?>
					<?php if (!$_sidebarIsMod): ?><div class="menu-header">Admin</div><?php endif; ?>
					<div class="menu-item <?= $_currentPage === 'tick.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>admin/tick.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-gear"></i></span>
							<span class="menu-text">Tick Engine</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'admin_vehicles.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>admin/admin_vehicles.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-wrench"></i></span>
							<span class="menu-text">Admin: Vehicles</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'admin_trains.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>admin/admin_trains.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-train-front"></i></span>
							<span class="menu-text">Admin: Trains</span>
						</a>
					</div>
					<div class="menu-item <?= $_currentPage === 'admin_weapons.php' ? 'active' : '' ?>">
						<a href="<?= BASE_URL ?>admin/admin_weapons.php" class="menu-link">
							<span class="menu-icon"><i class="bi bi-crosshair"></i></span>
							<span class="menu-text">Admin: Weapons</span>
						</a>
					</div>
					<?php endif; ?>
				</div>
				<!-- END menu -->
			</div>
			<!-- END scrollbar -->
		</div>
		<!-- END #sidebar -->
		<!-- BEGIN mobile-sidebar-backdrop -->
		<button class="app-sidebar-mobile-backdrop" data-toggle-target=".app" data-toggle-class="app-sidebar-mobile-toggled"></button>
		<!-- END mobile-sidebar-backdrop -->