<?php
// setup_tick_event.php — Removes old MySQL event/procedure, ensures tick_log table exists
// The tick engine now runs via PHP (tick.php) — no MySQL event needed
// Run once via browser, then delete this file
include __DIR__ . "/../files/config.php";

$messages = [];

// ── Ensure tick_log table exists ──
$r = $conn->query("CREATE TABLE IF NOT EXISTS tick_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tick_time DATETIME NOT NULL,
    duration_ms INT DEFAULT 0,
    towns_processed INT DEFAULT 0,
    resources_produced DECIMAL(12,4) DEFAULT 0,
    fuel_burned DECIMAL(12,4) DEFAULT 0
)");
$messages[] = $r ? "✅ tick_log table ready" : "❌ tick_log error: " . $conn->error;

// ── Remove old MySQL event ──
try {
    $conn->query("DROP EVENT IF EXISTS game_tick_event");
    $messages[] = "✅ Removed game_tick_event (MySQL event)";
} catch (mysqli_sql_exception $e) {
    $messages[] = "⚠️ Could not drop event: " . $e->getMessage();
    $messages[] = "   → Run this in phpMyAdmin as root: <code>DROP EVENT IF EXISTS campaign_data.game_tick_event;</code>";
}

// ── Remove old stored procedure ──
try {
    $conn->query("DROP PROCEDURE IF EXISTS game_tick");
    $messages[] = "✅ Removed game_tick stored procedure";
} catch (mysqli_sql_exception $e) {
    $messages[] = "⚠️ Could not drop procedure: " . $e->getMessage();
    $messages[] = "   → Run this in phpMyAdmin as root: <code>DROP PROCEDURE IF EXISTS campaign_data.game_tick;</code>";
}

// ── Disable event scheduler (optional, saves resources) ──
try {
    $conn->query("SET GLOBAL event_scheduler = OFF");
    $messages[] = "✅ Event scheduler disabled (no longer needed)";
} catch (mysqli_sql_exception $e) {
    $messages[] = "ℹ️ Could not disable event scheduler (requires SUPER privilege — not critical)";
}

// ── Test the PHP tick engine ──
include __DIR__ . "/../files/tick_engine.php";
try {
    $result = runGameTick($conn);
    if ($result['skipped'] ?? false) {
        $messages[] = "✅ PHP tick engine works (skipped: {$result['reason']})";
    } else {
        $messages[] = "✅ PHP tick engine test: Tick #{$result['tick_id']} — {$result['towns_processed']} towns, +{$result['resources_produced']}t produced, -{$result['fuel_burned']}t fuel, {$result['duration_ms']}ms";
    }
} catch (Exception $e) {
    $messages[] = "❌ PHP tick engine error: " . $e->getMessage();
}

$messages[] = "";
$messages[] = "🎉 <strong>Migration complete!</strong> The game tick now runs entirely via PHP.";

$conn->close();
?>
<!DOCTYPE html>
<html><head><title>Setup: Remove MySQL Event → PHP Tick</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:800px;margin:auto}
code{background:#333;padding:2px 6px;border-radius:3px}a{color:#4fc3f7}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
h2{color:#4fc3f7}h3{color:#e94560;margin-top:30px}ol{line-height:2.2}</style>
</head><body>
<h2>🔧 Tick Engine Migration — MySQL → PHP</h2>
<div class="box">
<?php foreach ($messages as $m) echo "<p style='margin:6px 0;'>$m</p>"; ?>
</div>

<h3>📋 How to Keep the Tick Running</h3>

<div class="box">
<p><strong>Option 1: Browser Tab (Easiest)</strong></p>
<p>Open <a href="<?= BASE_URL ?>admin/tick.php">tick.php</a> in your browser and <strong>keep the tab open</strong>.<br>
JavaScript automatically fires a tick every 60 seconds via AJAX.<br>
You can also click "Run Tick Now" for manual ticks.</p>
</div>

<div class="box">
<p><strong>Option 2: Windows Task Scheduler + cURL (Runs in background)</strong></p>
<p>This runs ticks even when no browser is open:</p>
<ol>
<li>Press <kbd>Win+R</kbd>, type <code>taskschd.msc</code>, press Enter</li>
<li>Click <strong>"Create Task"</strong> (not Basic Task)</li>
<li><strong>General tab:</strong> Name = <code>Game Tick</code>, check "Run whether user is logged on or not"</li>
<li><strong>Triggers tab:</strong> New → Begin "At startup" → check "Repeat task every <code>5 minutes</code>" for <code>Indefinitely</code> → OK</li>
<li><strong>Actions tab:</strong> New → Program: <code>C:\xampp\php\php.exe</code></li>
<li>Arguments: <code>-r "file_get_contents('http://localhost/2026/template_html_startup/dist/tick.php?action=tick');"</code></li>
<li>Click OK, enter your Windows password if prompted</li>
</ol>
<p>This calls the tick endpoint every 5 minutes, same as the browser does.</p>
</div>

<div class="box">
<p><strong>Option 3: XAMPP Batch File (Simple background loop)</strong></p>
<p>Create a file called <code>tick_loop.bat</code> in your XAMPP folder with:</p>
<pre style="background:#0d1117;padding:12px;border-radius:4px;">
@echo off
:loop
C:\xampp\php\php.exe -r "file_get_contents('http://localhost/2026/template_html_startup/dist/tick.php?action=tick');"
timeout /t 300 /nobreak >nul
goto loop</pre>
<p>Double-click to run. Keep the command window open. Close it to stop ticks.</p>
</div>

<p style="margin-top:30px;text-align:center;">
<a href="<?= BASE_URL ?>admin/tick.php" style="font-size:1.3em;padding:10px 30px;background:#0f3460;border:1px solid #4fc3f7;border-radius:6px;text-decoration:none;color:#4fc3f7;">
→ Open Tick Engine Dashboard
</a>
</p>
</body></html>
