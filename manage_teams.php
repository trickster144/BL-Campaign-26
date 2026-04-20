<?php
// manage_teams.php — Mod/Admin page to assign players to teams
session_start();
include "files/config.php";
include "files/auth.php";
ensureTeamColumn($conn);

$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";

if (!$user || !isModOrAbove($user)) {
    header("Location: index.php?msg=access_denied");
    exit;
}

$msg = $_GET['msg'] ?? '';

// Handle team assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_team'])) {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newTeam = $_POST['new_team'] ?? '';

    if ($targetId > 0 && in_array($newTeam, ['grey', 'blue', 'red', 'green'])) {
        // Only admins/green can assign green team
        if ($newTeam === 'green' && !isAdmin($user)) {
            $msg = 'no_permission';
        } else {
            $stmt = $conn->prepare("UPDATE users SET team = ? WHERE id = ?");
            $stmt->bind_param("si", $newTeam, $targetId);
            $stmt->execute();
            $stmt->close();

            // If they're currently logged in, their session will update on next page load via getCurrentUser
            $msg = 'assigned';
        }
    }
    header("Location: manage_teams.php?msg=$msg");
    exit;
}

// Handle account type change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_role'])) {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newRole = $_POST['new_role'] ?? '';

    if ($targetId > 0 && in_array($newRole, ['user', 'mod', 'admin']) && isAdmin($user)) {
        $stmt = $conn->prepare("UPDATE users SET account_type = ? WHERE id = ?");
        $stmt->bind_param("si", $newRole, $targetId);
        $stmt->execute();
        $stmt->close();
        $msg = 'role_updated';
    }
    header("Location: manage_teams.php?msg=$msg");
    exit;
}

// Fetch all users
$users = [];
$uRes = $conn->query("SELECT id, username, account_type, team, created_at FROM users ORDER BY team, username");
if ($uRes) { while ($u = $uRes->fetch_assoc()) $users[] = $u; }

// Count per team
$counts = ['grey' => 0, 'blue' => 0, 'red' => 0, 'green' => 0];
foreach ($users as $u) $counts[$u['team'] ?? 'grey']++;

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">👥 Team Management</h1>

			<?php if ($msg === 'assigned'): ?>
			<div class="alert alert-success alert-dismissible fade show">✅ Team assignment updated! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'role_updated'): ?>
			<div class="alert alert-success alert-dismissible fade show">✅ Account role updated! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php elseif ($msg === 'no_permission'): ?>
			<div class="alert alert-danger alert-dismissible fade show">⛔ Only admins can assign Green Team. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
			<?php endif; ?>

			<!-- Team Counts -->
			<div class="row mb-3">
				<div class="col-md-3">
					<div class="card bg-dark border-secondary">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-secondary"><?= $counts['grey'] ?></h4>
							<small>Grey (Unassigned)</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-primary">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-primary"><?= $counts['blue'] ?></h4>
							<small>Blue Team</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-danger">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-danger"><?= $counts['red'] ?></h4>
							<small>Red Team</small>
						</div>
					</div>
				</div>
				<div class="col-md-3">
					<div class="card bg-dark border-success">
						<div class="card-body text-center py-2">
							<h4 class="mb-0 text-success"><?= $counts['green'] ?></h4>
							<small>Green (Admin)</small>
						</div>
					</div>
				</div>
			</div>

			<!-- User List -->
			<div class="card bg-dark">
				<div class="card-header fw-bold"><i class="fa fa-users me-2"></i>All Users (<?= count($users) ?>)</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>ID</th>
									<th>Username</th>
									<th>Role</th>
									<th>Current Team</th>
									<th>Registered</th>
									<th>Assign Team</th>
									<?php if (isAdmin($user)): ?><th>Set Role</th><?php endif; ?>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($users as $u):
								$isSelf = ($u['id'] == $user['id']);
							?>
								<tr class="<?= $isSelf ? 'table-active' : '' ?>">
									<td><?= $u['id'] ?></td>
									<td class="fw-bold"><?= htmlspecialchars($u['username']) ?> <?= $isSelf ? '<small class="text-muted">(you)</small>' : '' ?></td>
									<td>
										<?php
										echo match($u['account_type']) {
											'admin' => '<span class="badge bg-warning text-dark">Admin</span>',
											'mod' => '<span class="badge bg-info">Mod</span>',
											default => '<span class="badge bg-secondary">User</span>',
										};
										?>
									</td>
									<td><?= teamBadge($u['team'] ?? 'grey') ?></td>
									<td class="small"><?= $u['created_at'] ?? '' ?></td>
									<td>
										<form method="post" class="d-flex gap-1">
											<input type="hidden" name="assign_team" value="1">
											<input type="hidden" name="user_id" value="<?= $u['id'] ?>">
											<select name="new_team" class="form-select form-select-sm" style="width:auto;">
												<option value="grey" <?= ($u['team'] ?? 'grey') === 'grey' ? 'selected' : '' ?>>Grey</option>
												<option value="blue" <?= ($u['team'] ?? '') === 'blue' ? 'selected' : '' ?>>Blue</option>
												<option value="red" <?= ($u['team'] ?? '') === 'red' ? 'selected' : '' ?>>Red</option>
												<?php if (isAdmin($user)): ?>
												<option value="green" <?= ($u['team'] ?? '') === 'green' ? 'selected' : '' ?>>Green</option>
												<?php endif; ?>
											</select>
											<button type="submit" class="btn btn-sm btn-outline-theme">Set</button>
										</form>
									</td>
									<?php if (isAdmin($user)): ?>
									<td>
										<form method="post" class="d-flex gap-1">
											<input type="hidden" name="set_role" value="1">
											<input type="hidden" name="user_id" value="<?= $u['id'] ?>">
											<select name="new_role" class="form-select form-select-sm" style="width:auto;">
												<option value="user" <?= $u['account_type'] === 'user' ? 'selected' : '' ?>>User</option>
												<option value="mod" <?= $u['account_type'] === 'mod' ? 'selected' : '' ?>>Mod</option>
												<option value="admin" <?= $u['account_type'] === 'admin' ? 'selected' : '' ?>>Admin</option>
											</select>
											<button type="submit" class="btn btn-sm btn-outline-warning">Set</button>
										</form>
									</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
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
