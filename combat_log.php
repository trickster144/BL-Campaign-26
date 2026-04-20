<?php
// combat_log.php — View battle history
session_start();
include "files/config.php";
include "files/auth.php";

$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";
requireLogin();
requireTeamAssignment($user);
$factions = viewableFactions($user);
$factionSQL = "'" . implode("','", $factions) . "'";

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Total count
$totalRes = $conn->query("SELECT COUNT(*) as cnt FROM combat_log WHERE attacker_faction IN ($factionSQL) OR defender_faction IN ($factionSQL)");
$total = $totalRes ? (int)$totalRes->fetch_assoc()['cnt'] : 0;
$totalPages = max(1, ceil($total / $perPage));

// Load battles
$battles = [];
$bRes = $conn->query("
    SELECT cl.*, t.name as current_town_name
    FROM combat_log cl
    LEFT JOIN towns t ON cl.town_id = t.id
    WHERE cl.attacker_faction IN ($factionSQL) OR cl.defender_faction IN ($factionSQL)
    ORDER BY cl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
if ($bRes) { while ($b = $bRes->fetch_assoc()) $battles[] = $b; }

include "files/header.php";
include "files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">📜 Combat Log</h1>

			<?php if (empty($battles)): ?>
			<div class="alert alert-info">No battles have taken place yet.</div>
			<?php else: ?>

			<div class="card bg-dark border-secondary">
				<div class="card-header border-secondary">
					<h5 class="mb-0">⚔️ Battle History (<?= number_format($total) ?> total)</h5>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>Time</th>
									<th>Location</th>
									<th>Attacker</th>
									<th>Defender</th>
									<th>⚔️ Atk Power</th>
									<th>🛡️ Def Power</th>
									<th>Attacker Losses</th>
									<th>Defender Losses</th>
									<th>Result</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($battles as $b):
								$resultBadge = match($b['result']) {
									'attacker_won' => '<span class="badge bg-danger">Attacker Won</span>',
									'defender_won' => '<span class="badge bg-success">Defender Won</span>',
									default => '<span class="badge bg-secondary">Draw</span>',
								};
								$atkFaction = '<span class="badge bg-' . ($b['attacker_faction'] === 'blue' ? 'primary' : 'danger') . '">' . ucfirst($b['attacker_faction']) . '</span>';
								$defFaction = '<span class="badge bg-' . ($b['defender_faction'] === 'blue' ? 'primary' : 'danger') . '">' . ucfirst($b['defender_faction']) . '</span>';
							?>
								<tr>
									<td class="small"><?= date('d M H:i', strtotime($b['created_at'])) ?></td>
									<td><?= htmlspecialchars($b['town_name'] ?? $b['current_town_name'] ?? 'Unknown') ?></td>
									<td><?= $atkFaction ?> (<?= number_format($b['attacker_troops']) ?> troops)</td>
									<td><?= $defFaction ?> (<?= number_format($b['defender_troops']) ?> troops)</td>
									<td class="text-danger"><?= number_format($b['attacker_attack_total']) ?></td>
									<td class="text-info"><?= number_format($b['defender_defense_total']) ?></td>
									<td class="text-warning">-<?= number_format($b['attacker_losses']) ?></td>
									<td class="text-warning">-<?= number_format($b['defender_losses']) ?></td>
									<td><?= $resultBadge ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Pagination -->
			<?php if ($totalPages > 1): ?>
			<nav class="mt-3">
				<ul class="pagination justify-content-center">
					<?php for ($p = 1; $p <= $totalPages; $p++): ?>
					<li class="page-item <?= $p === $page ? 'active' : '' ?>">
						<a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
					</li>
					<?php endfor; ?>
				</ul>
			</nav>
			<?php endif; ?>

			<?php endif; ?>
		</div>
		<!-- END #content -->

<?php
include "files/scripts.php";
$conn->close();
?>
