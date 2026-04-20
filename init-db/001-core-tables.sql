-- Core tables needed for the app to start
-- Run setup/*.php scripts in browser after first boot for full game data

-- Users table (auth system)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    account_type VARCHAR(20) NOT NULL DEFAULT 'user',
    team ENUM('grey','blue','red','green') NOT NULL DEFAULT 'grey',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- World market prices (core resource table, referenced by many others)
CREATE TABLE IF NOT EXISTS world_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_name VARCHAR(100) NOT NULL UNIQUE,
    resource_type VARCHAR(50) DEFAULT NULL,
    image_file VARCHAR(100) DEFAULT NULL,
    buy_price DECIMAL(14,4) NOT NULL DEFAULT 0,
    sell_price DECIMAL(14,4) NOT NULL DEFAULT 0,
    base_buy_price DECIMAL(14,4) NOT NULL DEFAULT 0,
    base_sell_price DECIMAL(14,4) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Seed world_prices with all resources
INSERT INTO world_prices (resource_name, resource_type, image_file, buy_price, sell_price, base_buy_price, base_sell_price) VALUES
-- Raw Resources (Tier 0)
('Stone',     'Raw Resource', 'Stone.png',     10,    9.50,   10,    9.50),
('Wood',      'Raw Resource', 'Wood.png',      15,   14.25,   15,   14.25),
('Coal',      'Raw Resource', 'Coal.png',      20,   19.00,   20,   19.00),
('Iron',      'Raw Resource', 'Iron.png',      25,   23.75,   25,   23.75),
('Bauxite',   'Raw Resource', 'Bauxite.png',   30,   28.50,   30,   28.50),
('Oil',       'Raw Resource', 'Oil.png',       35,   33.25,   35,   33.25),
('Uranium',   'Raw Resource', 'Uranium.png',    5,    4.75,    5,    4.75),
-- Basic Processed (Tier 1)
('Bricks',        'Basic Processed', 'Bricks.png',        65,   61.75,   65,   61.75),
('Wooden boards',  'Basic Processed', 'Wooden_Boards.png', 110,  104.50,  110,  104.50),
('Prefab panels',  'Basic Processed', 'Prefab_panels.png', 105,   99.75,  105,   99.75),
('Aluminium',      'Basic Processed', 'Aluminium.png',      90,   85.50,   90,   85.50),
('Steel',          'Basic Processed', 'Steel.png',         250,  237.50,  250,  237.50),
('Fuel',           'Basic Processed', 'Fuel.png',          280,  266.00,  280,  266.00),
('Nuclear fuel',   'Basic Processed', 'Nuclear_Fuel.png',  150,  142.50,  150,  142.50),
-- Advanced Processed (Tier 2)
('Chemicals',              'Advanced Processed', 'Chemicals.png',              550,   522.50,   550,   522.50),
('Plastics',               'Advanced Processed', 'Plastics.png',              1500,  1425.00,  1500,  1425.00),
('Mechanical components',  'Advanced Processed', 'Mechanical_components.png', 3200,  3040.00,  3200,  3040.00),
('Explosives',             'Advanced Processed', 'Explosives.png',            2800,  2660.00,  2800,  2660.00),
('Electronic components',  'Advanced Processed', NULL,                        6500,  6175.00,  6500,  6175.00);
