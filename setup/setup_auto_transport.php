<?php
// setup_auto_transport.php — Creates tables for automated transport rules
// Run once via browser, then delete
include __DIR__ . "/../files/config.php";

$messages = [];

// 1. Auto transport rules table
$conn->query("
    CREATE TABLE IF NOT EXISTS auto_transport (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faction VARCHAR(10) NOT NULL,
        from_town_id INT NOT NULL,
        to_town_id INT NOT NULL,
        resource_id INT NOT NULL,
        threshold DECIMAL(12,2) NOT NULL DEFAULT 100,
        send_amount DECIMAL(12,2) NOT NULL DEFAULT 50,
        transport_type ENUM('truck','train') DEFAULT 'truck',
        return_empty TINYINT(1) DEFAULT 1,
        enabled TINYINT(1) DEFAULT 1,
        last_dispatched_at DATETIME NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (from_town_id) REFERENCES towns(id),
        FOREIGN KEY (to_town_id) REFERENCES towns(id),
        FOREIGN KEY (resource_id) REFERENCES world_prices(id)
    ) ENGINE=InnoDB
");
$messages[] = "✅ Created auto_transport table";

// 2. Dispatch log for audit trail
$conn->query("
    CREATE TABLE IF NOT EXISTS auto_transport_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auto_transport_id INT NOT NULL,
        vehicle_id INT NULL,
        train_id INT NULL,
        resource_name VARCHAR(100),
        amount_sent DECIMAL(12,2),
        from_town VARCHAR(100),
        to_town VARCHAR(100),
        dispatched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (auto_transport_id) REFERENCES auto_transport(id) ON DELETE CASCADE
    ) ENGINE=InnoDB
");
$messages[] = "✅ Created auto_transport_log table";

echo "<h2>Auto Transport Setup</h2>";
foreach ($messages as $m) echo "<p>$m</p>";
echo "<p><a href='../auto_transport.php'>Go to Auto Transport →</a></p>";
?>
