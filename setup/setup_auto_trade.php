<?php
// setup_auto_trade.php — Creates tables for auto buy/sell and auto resupply
// Run once via browser, then delete
include __DIR__ . "/../files/config.php";

$messages = [];

// 1. Auto trade orders (buy/sell at customs houses)
$conn->query("
    CREATE TABLE IF NOT EXISTS auto_trade (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faction VARCHAR(10) NOT NULL,
        resource_id INT NOT NULL,
        trade_action ENUM('buy','sell') NOT NULL,
        threshold DECIMAL(12,2) NOT NULL DEFAULT 100 COMMENT 'Buy: buy when customs stock < this. Sell: sell when customs stock >= this.',
        order_amount DECIMAL(12,2) NOT NULL DEFAULT 50 COMMENT 'Amount to buy/sell per execution',
        min_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Buy only: minimum faction points to maintain after purchase',
        enabled TINYINT(1) DEFAULT 1,
        last_executed_at DATETIME NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (resource_id) REFERENCES world_prices(id)
    ) ENGINE=InnoDB
");
$messages[] = "✅ Created auto_trade table";

// 2. Auto resupply (monitors town stock → buys at customs → dispatches to town)
$conn->query("
    CREATE TABLE IF NOT EXISTS auto_resupply (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faction VARCHAR(10) NOT NULL,
        town_id INT NOT NULL COMMENT 'Town to keep supplied',
        resource_id INT NOT NULL,
        low_threshold DECIMAL(12,2) NOT NULL DEFAULT 50 COMMENT 'Trigger when town stock drops below this',
        order_amount DECIMAL(12,2) NOT NULL DEFAULT 100 COMMENT 'Amount to buy and ship',
        min_balance DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Min faction points to keep after purchase',
        transport_type ENUM('truck','train') DEFAULT 'truck',
        enabled TINYINT(1) DEFAULT 1,
        last_executed_at DATETIME NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (town_id) REFERENCES towns(id),
        FOREIGN KEY (resource_id) REFERENCES world_prices(id)
    ) ENGINE=InnoDB
");
$messages[] = "✅ Created auto_resupply table";

// 3. Shared log for both systems
$conn->query("
    CREATE TABLE IF NOT EXISTS auto_trade_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        log_type ENUM('trade','resupply') NOT NULL,
        rule_id INT NOT NULL,
        faction VARCHAR(10) NOT NULL,
        resource_name VARCHAR(100),
        action_taken VARCHAR(200),
        amount DECIMAL(12,2),
        points_spent DECIMAL(14,2) DEFAULT 0,
        vehicle_id INT NULL,
        train_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
");
$messages[] = "✅ Created auto_trade_log table";

echo "<h2>Auto Trade & Resupply Setup</h2>";
foreach ($messages as $m) echo "<p>$m</p>";
echo "<p><a href='../auto_trade.php'>Go to Auto Trade →</a></p>";
?>
