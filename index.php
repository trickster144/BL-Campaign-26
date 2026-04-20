<?php
session_start();
include "files/config.php";
include "files/auth.php";
ensureTeamColumn($conn);
$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
$msg = $_GET['msg'] ?? '';
include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">

		<?php if ($msg === 'no_team'): ?>
		<div class="alert alert-warning"><i class="fa fa-clock me-2"></i>You are on <strong>Grey Team</strong> (unassigned). A moderator will assign you to a team shortly. Until then, you can only view world market prices.</div>
		<?php elseif ($msg === 'access_denied'): ?>
		<div class="alert alert-danger"><i class="fa fa-lock me-2"></i>You don't have permission to access that page.</div>
		<?php endif; ?>

		<?php if ($user): ?>
		<div class="card mb-3">
			<div class="card-header fw-bold">Black Legion Campaign</div>
			<div class="card-body">
				<p class="mb-1">Welcome, <strong><?= htmlspecialchars($user['username']) ?></strong> <?= teamBadge($user['team'] ?? 'grey') ?></p>
				<?php if (($user['team'] ?? 'grey') === 'grey'): ?>
				<div class="alert alert-secondary mt-2 mb-0"><i class="fa fa-info-circle me-1"></i>You are currently unassigned. A moderator will place you on Blue or Red team. Please wait.</div>
				<?php endif; ?>
			</div>
		<?php include "files/cardarrow.php"; ?></div>
		<?php else: ?>
		<div class="card mb-3">
			<div class="card-header fw-bold">Black Legion Campaign</div>
			<div class="card-body">
				<p class="mb-1">Welcome! <a href="<?= BASE_URL ?>auth/login.php">Log in</a> or <a href="<?= BASE_URL ?>auth/register.php">register</a> to play.</p>
			</div>
		<?php include "files/cardarrow.php"; ?></div>
		<?php endif; ?>
		<div class="card mb-3">
  		<div class="card-header fw-bold small">World Market Prices</div>
  		<div class="card-body">
											<table class="table table-hover">
									<thead>
										<tr>
										<th scope="col"></th>
										<th scope="col">Resource</th>
										<th scope="col">Buy per Tonne</th>
										<th scope="col">Sell per Tonne</th>
										</tr>
									</thead>
									<tbody>
									<?php
									$result = $conn->query("SELECT resource_name, resource_type, image_file, buy_price, sell_price FROM world_prices ORDER BY resource_type, resource_name");
									$currentType = '';
									while ($row = $result->fetch_assoc()):
										if ($row['resource_type'] !== $currentType):
											$currentType = $row['resource_type'];
									?>
										<tr class="table-dark"><td colspan="4" class="fw-bold small"><?= htmlspecialchars($currentType) ?></td></tr>
									<?php
										endif;
										$img = $row['image_file'] ? '<img src="assets/resource_imgs/' . htmlspecialchars($row['image_file']) . '" alt="' . htmlspecialchars($row['resource_name']) . '" width="32" height="32">' : '';
									?>
										<tr>
											<td><?= $img ?></td>
											<td><?= htmlspecialchars($row['resource_name']) ?></td>
											<td><?= number_format($row['buy_price'], 2) ?></td>
											<td><?= number_format($row['sell_price'], 2) ?></td>
										</tr>
									<?php endwhile; ?>
									</tbody>
									</table>
  		</div>
  		<?php include "files/cardarrow.php"; ?></div>
		</div>
		<!-- END #content -->
<?php
//include "files/themepanel.php";
include "files/scripts.php";
?>	

