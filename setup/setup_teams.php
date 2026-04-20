<?php
// setup_teams.php — Add team column + assign existing admin to green
session_start();
include __DIR__ . "/../files/config.php";
include __DIR__ . "/../files/auth.php";

$messages = [];

// Add team column if missing
ensureTeamColumn($conn);
$messages[] = "✅ Team column ensured on users table";

// Set any existing admin accounts to green team
$conn->query("UPDATE users SET team = 'green' WHERE account_type = 'admin' AND team = 'grey'");
$affected = $conn->affected_rows;
$messages[] = $affected > 0
    ? "✅ Promoted $affected admin account(s) to Green Team"
    : "ℹ️ No admin accounts needed promotion";

$conn->close();
?>
<!DOCTYPE html><html><head><title>Setup Teams</title>
<style>body{background:#1a1a2e;color:#eee;font-family:monospace;padding:40px;max-width:600px;margin:auto}
.box{background:#16213e;border:1px solid #0f3460;padding:20px;border-radius:8px;margin:20px 0}
a{color:#4fc3f7}h2{color:#4fc3f7}</style>
</head><body>
<h2>🎯 Team System Setup</h2>
<div class="box">
<?php foreach ($messages as $m): ?>
    <p><?= $m ?></p>
<?php endforeach; ?>
</div>
<p><strong>Teams:</strong></p>
<ul>
    <li><strong>Grey</strong> — Default for new signups. Cannot access game until assigned.</li>
    <li><strong>Blue</strong> — Blue faction. Sees only blue towns/resources.</li>
    <li><strong>Red</strong> — Red faction. Sees only red towns/resources.</li>
    <li><strong>Green</strong> — Game admin. Sees everything, can manage all systems.</li>
</ul>
<p><a href="<?= BASE_URL ?>manage_teams.php">→ Manage Teams</a> | <a href="<?= BASE_URL ?>index.php">→ Home</a></p>
</body></html>
