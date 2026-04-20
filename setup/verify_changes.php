<?php
header('Content-Type: text/plain');

$files = [
    'military.php',
    'military_action.php',
    'files/tick_engine.php',
    'setup/setup_military.php'
];

foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $output = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
    echo "$f: " . trim($output) . "\n";
}

// Also verify DB columns exist
include __DIR__ . "/files/config.php";
$res = $conn->query("SHOW COLUMNS FROM troop_movements LIKE 'transport_type'");
echo "\ntransport_type column: " . ($res && $res->num_rows > 0 ? "EXISTS" : "MISSING") . "\n";
$res2 = $conn->query("SHOW COLUMNS FROM troop_movements LIKE 'vehicle_id'");
echo "vehicle_id column: " . ($res2 && $res2->num_rows > 0 ? "EXISTS" : "MISSING") . "\n";

// Cleanup
@unlink(__FILE__);
