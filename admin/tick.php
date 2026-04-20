<?php
// tick.php — Game Tick Engine (PHP-based)
// Runs tick every 60 seconds via JavaScript, or manually via button
// Also serves as JSON endpoint when ?action=tick is passed
session_start();
include __DIR__ . "/../files/config.php";
include __DIR__ . "/../files/auth.php";
include __DIR__ . "/../files/tick_engine.php";

$user = getCurrentUser($conn);
$username = $user ? $user['username'] : "Please Login";

// Allow tick API calls without full page auth (for cron)
if (isset($_GET['action']) && $_GET['action'] === 'tick') {
    // API endpoint — proceed
} else {
    requireLogin();
    if (!isGreenTeam($user)) {
        header("Location: " . BASE_URL . "index.php?msg=access_denied");
        exit;
    }
}

// Ensure tick_log table exists
$conn->query("CREATE TABLE IF NOT EXISTS tick_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tick_time DATETIME NOT NULL,
    duration_ms INT DEFAULT 0,
    towns_processed INT DEFAULT 0,
    resources_produced DECIMAL(12,4) DEFAULT 0,
    fuel_burned DECIMAL(12,4) DEFAULT 0
)");

// JSON API endpoint for auto-tick
if (isset($_GET['action']) && $_GET['action'] === 'tick') {
    header('Content-Type: application/json');
    try {
        $result = runGameTick($conn);
        echo json_encode(['success' => true, 'result' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// Handle manual tick trigger (form POST)
$tickMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_tick'])) {
    try {
        $result = runGameTick($conn);
        if ($result['skipped'] ?? false) {
            $tickMsg = "info|Tick skipped: {$result['reason']} (wait 4 min between ticks)";
        } else {
            $tickMsg = "success|Tick #{$result['tick_id']} executed: {$result['towns_processed']} towns, "
                . "+{$result['resources_produced']}t produced, -{$result['fuel_burned']}t fuel, "
                . "🪖+{$result['troops_recruited']} recruits, 🗡️+{$result['weapons_produced']} weapons, {$result['duration_ms']}ms";
        }
    } catch (Exception $e) {
        $tickMsg = "danger|Tick failed: " . $e->getMessage();
    }
}

// Tick statistics
$totalTicks = 0; $avgDuration = 0; $totalProduced = 0; $totalBurned = 0;
$statsRes = $conn->query("SELECT COUNT(*) as cnt, AVG(duration_ms) as avg_ms, SUM(resources_produced) as total_prod, SUM(fuel_burned) as total_fuel FROM tick_log");
if ($statsRes && $s = $statsRes->fetch_assoc()) {
    $totalTicks = (int)$s['cnt'];
    $avgDuration = round((float)$s['avg_ms'], 1);
    $totalProduced = (float)$s['total_prod'];
    $totalBurned = (float)$s['total_fuel'];
}

// Last tick
$lastTick = null;
$ltRes = $conn->query("SELECT * FROM tick_log ORDER BY id DESC LIMIT 1");
if ($ltRes && $ltRes->num_rows > 0) $lastTick = $ltRes->fetch_assoc();

// Tick rate (ticks in last 10 minutes)
$recentCount = 0;
$rcRes = $conn->query("SELECT COUNT(*) as cnt FROM tick_log WHERE tick_time > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
if ($rcRes && $rc = $rcRes->fetch_assoc()) $recentCount = (int)$rc['cnt'];

// Recent history
$history = [];
$hRes = $conn->query("SELECT * FROM tick_log ORDER BY id DESC LIMIT 50");
if ($hRes) { while ($h = $hRes->fetch_assoc()) $history[] = $h; }

include __DIR__ . "/../files/header.php";
include __DIR__ . "/../files/sidebar.php";
?>
		<!-- BEGIN #content -->
		<div id="content" class="app-content">
			<h1 class="page-header">⚙️ Game Tick Engine <small class="text-muted">(PHP)</small></h1>

			<?php if ($tickMsg): 
				$parts = explode('|', $tickMsg, 2);
				$alertType = $parts[0]; $alertText = $parts[1];
			?>
			<div class="alert alert-<?= $alertType ?> alert-dismissible fade show">
				<?= htmlspecialchars($alertText) ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
			</div>
			<?php endif; ?>

			<!-- Status Cards Row -->
			<div class="row mb-4">
				<!-- Tick Engine Status -->
				<div class="col-lg-3 col-md-6 mb-3">
					<div class="card bg-dark border-secondary h-100">
						<div class="card-header border-secondary"><h6 class="mb-0">🔄 Tick Engine</h6></div>
						<div class="card-body">
							<p class="mb-2">Mode: <span class="badge bg-success">PHP (Auto)</span></p>
							<p class="mb-2">Interval: <span class="badge bg-info">5 minutes</span></p>
							<p class="mb-0">Status: <span id="tick-status" class="badge bg-success">Running</span></p>
						</div>
					</div>
				</div>

				<!-- Last Tick -->
				<div class="col-lg-3 col-md-6 mb-3">
					<div class="card bg-dark border-secondary h-100">
						<div class="card-header border-secondary"><h6 class="mb-0">📊 Last Tick</h6></div>
						<div class="card-body" id="last-tick-card">
							<?php if ($lastTick): ?>
								<p class="mb-1"><small class="text-muted">Time:</small> <?= $lastTick['tick_time'] ?></p>
								<p class="mb-1"><small class="text-muted">Towns:</small> <?= $lastTick['towns_processed'] ?> &nbsp; <small class="text-muted">Duration:</small> <?= $lastTick['duration_ms'] ?>ms</p>
								<p class="mb-1 text-success">+<?= number_format($lastTick['resources_produced'], 4) ?>t produced</p>
								<p class="mb-0 text-danger">-<?= number_format($lastTick['fuel_burned'], 4) ?>t fuel burned</p>
							<?php else: ?>
								<p class="text-muted mb-0">No ticks recorded yet</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Lifetime Stats -->
				<div class="col-lg-3 col-md-6 mb-3">
					<div class="card bg-dark border-secondary h-100">
						<div class="card-header border-secondary"><h6 class="mb-0">📈 Lifetime Stats</h6></div>
						<div class="card-body">
							<p class="mb-1"><small class="text-muted">Total ticks:</small> <span id="total-ticks"><?= number_format($totalTicks) ?></span></p>
							<p class="mb-1"><small class="text-muted">Avg duration:</small> <?= $avgDuration ?>ms</p>
							<p class="mb-1 text-success">+<?= number_format($totalProduced, 2) ?>t total produced</p>
							<p class="mb-0 text-danger">-<?= number_format($totalBurned, 2) ?>t total fuel burned</p>
							<p class="mb-0 mt-1"><small class="text-muted">Rate (10min):</small> <?= $recentCount ?> ticks</p>
						</div>
					</div>
				</div>

				<!-- Manual Control -->
				<div class="col-lg-3 col-md-6 mb-3">
					<div class="card bg-dark border-secondary h-100">
						<div class="card-header border-secondary"><h6 class="mb-0">🔧 Manual Control</h6></div>
						<div class="card-body d-flex flex-column justify-content-center">
							<form method="POST">
								<button name="manual_tick" value="1" class="btn btn-warning btn-lg w-100 mb-2">
									⚡ Run Tick Now
								</button>
							</form>
							<small class="text-muted text-center">Runs PHP tick engine<br>4-min debounce applies</small>
							<div id="countdown" class="text-center mt-2">
								<small class="text-info">Next auto-tick in <span id="timer-min">5</span>m <span id="timer-sec">00</span>s</small>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Auto-tick log -->
			<div id="auto-tick-log" class="mb-3" style="display:none;">
				<div class="alert alert-info alert-dismissible fade show mb-2">
					<span id="auto-tick-msg"></span>
					<button type="button" class="btn-close" data-bs-dismiss="alert" onclick="document.getElementById('auto-tick-log').style.display='none'"></button>
				</div>
			</div>

			<!-- Tick History Table -->
			<div class="card bg-dark border-secondary">
				<div class="card-header border-secondary">
					<h5 class="mb-0">📜 Tick History <small class="text-muted">(Last 50)</small></h5>
				</div>
				<div class="card-body p-0">
					<div class="table-responsive">
						<table class="table table-dark table-striped table-hover table-sm mb-0">
							<thead>
								<tr>
									<th>ID</th>
									<th>Time</th>
									<th>Duration</th>
									<th>Towns</th>
									<th>Resources Produced</th>
									<th>Fuel Burned</th>
								</tr>
							</thead>
							<tbody id="tick-history-body">
								<?php foreach ($history as $h): ?>
								<tr>
									<td><?= $h['id'] ?></td>
									<td><?= $h['tick_time'] ?></td>
									<td><?= $h['duration_ms'] ?>ms</td>
									<td><?= $h['towns_processed'] ?></td>
									<td class="text-success">+<?= number_format($h['resources_produced'], 4) ?>t</td>
									<td class="text-danger">-<?= number_format($h['fuel_burned'], 4) ?>t</td>
								</tr>
								<?php endforeach; ?>
								<?php if (empty($history)): ?>
								<tr id="empty-row"><td colspan="6" class="text-center text-muted py-3">No tick history yet</td></tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<!-- END #content -->

<script>
// Auto-tick every 5 minutes (300 seconds) via AJAX
let countdown = 300;
const timerMinEl = document.getElementById('timer-min');
const timerSecEl = document.getElementById('timer-sec');
const statusEl = document.getElementById('tick-status');

function updateCountdown() {
    countdown--;
    if (timerMinEl && timerSecEl) {
        const min = Math.floor(countdown / 60);
        const sec = countdown % 60;
        timerMinEl.textContent = min;
        timerSecEl.textContent = sec.toString().padStart(2, '0');
    }
    if (countdown <= 0) {
        runAutoTick();
        countdown = 300;
    }
}

function runAutoTick() {
    if (statusEl) { statusEl.textContent = 'Ticking...'; statusEl.className = 'badge bg-warning'; }
    
    fetch('tick.php?action=tick')
        .then(r => r.json())
        .then(data => {
            if (statusEl) { statusEl.textContent = 'Running'; statusEl.className = 'badge bg-success'; }
            if (data.success && data.result && !data.result.skipped) {
                const r = data.result;
                const msg = `Auto-tick #${r.tick_id}: ${r.towns_processed} towns, +${r.resources_produced.toFixed(4)}t, -${r.fuel_burned.toFixed(4)}t fuel, ${r.duration_ms}ms`;
                const logDiv = document.getElementById('auto-tick-log');
                const logMsg = document.getElementById('auto-tick-msg');
                if (logDiv && logMsg) { logMsg.textContent = msg; logDiv.style.display = 'block'; }
                // Prepend to history
                const tbody = document.getElementById('tick-history-body');
                const empty = document.getElementById('empty-row');
                if (empty) empty.remove();
                if (tbody) {
                    const row = document.createElement('tr');
                    row.innerHTML = `<td>${r.tick_id}</td><td>just now</td><td>${r.duration_ms}ms</td><td>${r.towns_processed}</td><td class="text-success">+${r.resources_produced.toFixed(4)}t</td><td class="text-danger">-${r.fuel_burned.toFixed(4)}t</td>`;
                    tbody.insertBefore(row, tbody.firstChild);
                }
            }
        })
        .catch(err => {
            if (statusEl) { statusEl.textContent = 'Error'; statusEl.className = 'badge bg-danger'; }
            console.error('Auto-tick error:', err);
        });
}

setInterval(updateCountdown, 1000);
</script>

<?php
include __DIR__ . "/../files/scripts.php";
$conn->close();
?>
