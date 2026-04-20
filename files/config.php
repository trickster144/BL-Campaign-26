<?php
// Database configuration — reads from environment variables (Docker / production)
// For local XAMPP development, create files/config.local.php with your overrides
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'campaign_data';

// Optional local override (gitignored)
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    include $localConfig;
}

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Auto-detect base URL for web links (works from any subfolder)
if (!defined('BASE_URL')) {
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    $appRoot = realpath(dirname(__DIR__));
    if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
        define('BASE_URL', str_replace('\\', '/', substr($appRoot, strlen($docRoot))) . '/');
    } else {
        define('BASE_URL', '/');
    }
}
?>