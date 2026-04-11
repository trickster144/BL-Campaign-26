-- =============================================================================
-- Black Legion Cold War Campaign - Comprehensive Seed Data
-- Database: campaign_data
-- Version 3.2 — Safe to re-run (uses INSERT IGNORE for duplicates)
-- Compatible with MySQL 5.7+ and MariaDB 10.2+
-- Run this AFTER schema.sql
-- =============================================================================

USE campaign_data;

-- =============================================================================
-- 0. SYSTEM ADMIN USER (required for gamemaster_rules foreign key)
-- =============================================================================

INSERT IGNORE INTO users (steam_id, username, role) VALUES
('SYSTEM', 'GameMaster', 'gamemaster');

-- =============================================================================
-- 1. GAME STATE
-- =============================================================================

INSERT IGNORE INTO game_state (current_tick, game_started, season) VALUES
(0, FALSE, 'spring');

-- =============================================================================
-- 2. RESOURCES (37 types)
-- =============================================================================

INSERT IGNORE INTO resources (name, category, unit, can_be_stored, requires_refrigeration, requires_special_container, base_market_price) VALUES
-- Aggregates
('gravel',             'aggregate',    'tons',    TRUE,  FALSE, FALSE,   5.00),
('coal',               'aggregate',    'tons',    TRUE,  FALSE, FALSE,   8.00),
('iron',               'aggregate',    'tons',    TRUE,  FALSE, FALSE,  12.00),
('bauxite',            'aggregate',    'tons',    TRUE,  FALSE, FALSE,  15.00),
('uranium_ore',        'aggregate',    'tons',    TRUE,  FALSE, FALSE,  50.00),
('steel_scrap',        'aggregate',    'tons',    TRUE,  FALSE, FALSE,  25.00),
('aluminium_scrap',    'aggregate',    'tons',    TRUE,  FALSE, FALSE,  30.00),
('construction_waste', 'aggregate',    'tons',    TRUE,  FALSE, FALSE,   2.00),
-- Open Storage
('steel',              'open_storage', 'tons',    TRUE,  FALSE, FALSE,  45.00),
('prefab_panels',      'open_storage', 'units',   TRUE,  FALSE, FALSE,  35.00),
('bricks',             'open_storage', 'units',   TRUE,  FALSE, FALSE,   8.00),
('wood',               'open_storage', 'tons',    TRUE,  FALSE, FALSE,  12.00),
('aluminium',          'open_storage', 'tons',    TRUE,  FALSE, FALSE,  60.00),
('uranium_oxide',      'open_storage', 'tons',    TRUE,  FALSE, FALSE, 120.00),
('plastic_waste',      'open_storage', 'tons',    TRUE,  FALSE, FALSE,   4.00),
-- Dry Bulk
('cement',             'dry_bulk',     'tons',    TRUE,  FALSE, FALSE,  18.00),
('aluminium_oxide',    'dry_bulk',     'tons',    TRUE,  FALSE, FALSE,  40.00),
-- Warehouse
('crops',              'warehouse',    'tons',    TRUE,  FALSE, FALSE,   6.00),
('fabrics',            'warehouse',    'tons',    TRUE,  FALSE, FALSE,  20.00),
('clothes',            'warehouse',    'tons',    TRUE,  FALSE, FALSE,  55.00),
('alcohol',            'warehouse',    'liters',  TRUE,  FALSE, FALSE,  10.00),
('food',               'warehouse',    'tons',    TRUE,  FALSE, FALSE,  15.00),
('plastics',           'warehouse',    'tons',    TRUE,  FALSE, FALSE,  25.00),
('mechanical_components','warehouse',  'units',   TRUE,  FALSE, FALSE,  70.00),
('electrical_components','warehouse',  'units',   TRUE,  FALSE, FALSE,  85.00),
('electronics',        'warehouse',    'units',   TRUE,  FALSE, FALSE, 150.00),
('explosives',         'warehouse',    'tons',    TRUE,  FALSE, FALSE, 100.00),
-- Liquid
('oil',                'liquid',       'barrels', TRUE,  FALSE, FALSE,  30.00),
('bitumen',            'liquid',       'barrels', TRUE,  FALSE, FALSE,  22.00),
('fuel',               'liquid',       'liters',  TRUE,  FALSE, FALSE,   2.50),
('liquid_fertilizer',  'liquid',       'liters',  TRUE,  FALSE, FALSE,   3.50),
-- Live / Special handling
('livestock',          'live',         'heads',   TRUE,  FALSE, FALSE, 200.00),
('meat',               'live',         'tons',    TRUE,  TRUE,  FALSE,  25.00),
('nuclear_fuel',       'live',         'tons',    TRUE,  FALSE, TRUE,  500.00),
-- Non-storable (produced and consumed immediately)
('asphalt',            'special',      'tons',    FALSE, FALSE, FALSE,  15.00),
('concrete',           'special',      'tons',    FALSE, FALSE, FALSE,  20.00),
('power',              'special',      'kWh',     FALSE, FALSE, FALSE,   0.10);

-- =============================================================================
-- 3. HEX MAP (axial coordinates q,r — basic hex grid for game start)
-- Compatible with MySQL 5.7+ and MariaDB
-- =============================================================================

-- Basic grid around main locations (manageable size for initial seed)
INSERT IGNORE INTO hex_tiles (hex_q, hex_r, terrain_type, has_road, has_rail, has_river) VALUES
-- Central area plains (good for building)
( 0,  0, 'plains', FALSE, FALSE, FALSE),
( 1,  0, 'plains', FALSE, FALSE, FALSE),
(-1,  0, 'plains', FALSE, FALSE, FALSE),
( 0,  1, 'plains', FALSE, FALSE, FALSE),
( 0, -1, 'plains', FALSE, FALSE, FALSE),
( 1, -1, 'plains', FALSE, FALSE, FALSE),
(-1,  1, 'plains', FALSE, FALSE, FALSE),

-- Blue team area (northwest)
(-8,  2, 'plains', FALSE, FALSE, FALSE), -- Northaven
(-6,  5, 'plains', FALSE, FALSE, FALSE), -- Frostpeak  
(-10, 0, 'plains', FALSE, FALSE, FALSE), -- Blueport
(-7,  0, 'plains', FALSE, FALSE, FALSE), -- Frostvale
(-9,  3, 'plains', FALSE, FALSE, FALSE), -- Snowfield
(-5,  4, 'plains', FALSE, FALSE, FALSE), -- Bluehaven
(-11, 2, 'plains', FALSE, FALSE, FALSE), -- Icemere
(-4,  6, 'forest', FALSE, FALSE, FALSE), -- Pinegrove
(-8,  1, 'forest', FALSE, FALSE, FALSE), -- Winterwood
(-12, 1, 'plains', FALSE, FALSE, FALSE), -- Stormwatch
(-6,  3, 'mountain', FALSE, FALSE, FALSE), -- Goldpeak
(-9,  5, 'forest', FALSE, FALSE, FALSE), -- Cedarvale
(-7,  7, 'plains', FALSE, FALSE, FALSE), -- Ironhull
(-13, 3, 'plains', FALSE, FALSE, FALSE), -- Customs House North

-- Red team area (southeast)
( 8, -2, 'plains', FALSE, FALSE, FALSE), -- Ironforge
( 6, -5, 'plains', FALSE, FALSE, FALSE), -- Redfield
(10,  0, 'plains', FALSE, FALSE, FALSE), -- Southgate
( 7,  0, 'plains', FALSE, FALSE, FALSE), -- Crimsondale
( 9, -3, 'plains', FALSE, FALSE, FALSE), -- Emberfall
( 5, -4, 'desert', FALSE, FALSE, FALSE), -- Sandstone
(11, -2, 'plains', FALSE, FALSE, FALSE), -- Redrock
( 4, -6, 'plains', FALSE, FALSE, FALSE), -- Rubyvale
( 8, -1, 'plains', FALSE, FALSE, FALSE), -- Scarletmoor
(12, -1, 'plains', FALSE, FALSE, FALSE), -- Flameheart
( 6, -3, 'mountain', FALSE, FALSE, FALSE), -- Copperhill
( 9, -5, 'desert', FALSE, FALSE, FALSE), -- Dusthaven
( 7, -7, 'plains', FALSE, FALSE, FALSE), -- Ironclad
(13, -3, 'plains', FALSE, FALSE, FALSE), -- Customs House South

-- Strategic connecting areas
( 2,  1, 'plains', FALSE, FALSE, FALSE),
( 3,  0, 'plains', FALSE, FALSE, FALSE),
( 4, -1, 'plains', FALSE, FALSE, FALSE),
( 1,  2, 'forest', FALSE, FALSE, FALSE),
(-1,  3, 'forest', FALSE, FALSE, FALSE),
(-2,  1, 'plains', FALSE, FALSE, FALSE),
(-3,  2, 'plains', FALSE, FALSE, FALSE),
(-4,  3, 'forest', FALSE, FALSE, FALSE),

-- Some river tiles (strategic chokepoints)
( 0,  3, 'water', FALSE, FALSE, TRUE),
( 1,  3, 'water', FALSE, FALSE, TRUE),
( 2,  2, 'water', FALSE, FALSE, TRUE),
( 3,  1, 'water', FALSE, FALSE, TRUE),
( 4,  0, 'water', FALSE, FALSE, TRUE),
( 3, -1, 'water', FALSE, FALSE, TRUE),
( 2, -2, 'water', FALSE, FALSE, TRUE),
( 1, -3, 'water', FALSE, FALSE, TRUE),
( 0, -3, 'water', FALSE, FALSE, TRUE),
(-1, -2, 'water', FALSE, FALSE, TRUE),
(-2, -1, 'water', FALSE, FALSE, TRUE),
(-3,  0, 'water', FALSE, FALSE, TRUE),
(-2,  2, 'water', FALSE, FALSE, TRUE),

-- Mountain barriers
( 5, -7, 'mountain', FALSE, FALSE, FALSE),
( 6, -8, 'mountain', FALSE, FALSE, FALSE),
( 7, -8, 'mountain', FALSE, FALSE, FALSE),
(-5,  7, 'mountain', FALSE, FALSE, FALSE),
(-6,  8, 'mountain', FALSE, FALSE, FALSE),
(-7,  8, 'mountain', FALSE, FALSE, FALSE),
( 8,  1, 'mountain', FALSE, FALSE, FALSE),
( 9,  0, 'mountain', FALSE, FALSE, FALSE),
(-8, -1, 'mountain', FALSE, FALSE, FALSE),
(-9,  0, 'mountain', FALSE, FALSE, FALSE),

-- Resource areas
( 5,  2, 'mountain', FALSE, FALSE, FALSE), -- Iron deposits
( 6,  1, 'mountain', FALSE, FALSE, FALSE),
(-5, -2, 'mountain', FALSE, FALSE, FALSE), -- Coal deposits  
(-6, -1, 'mountain', FALSE, FALSE, FALSE),
( 2, -5, 'mountain', FALSE, FALSE, FALSE), -- Uranium
(-2,  5, 'forest', FALSE, FALSE, FALSE),   -- Wood
(-3,  6, 'forest', FALSE, FALSE, FALSE),
( 3,  5, 'forest', FALSE, FALSE, FALSE),
( 4,  4, 'forest', FALSE, FALSE, FALSE),

-- Additional plains for expansion
( 5,  3, 'plains', FALSE, FALSE, FALSE),
( 6,  2, 'plains', FALSE, FALSE, FALSE),
( 7,  1, 'plains', FALSE, FALSE, FALSE),
(-5, -3, 'plains', FALSE, FALSE, FALSE),
(-6, -2, 'plains', FALSE, FALSE, FALSE),
(-7, -1, 'plains', FALSE, FALSE, FALSE),
( 2, -6, 'plains', FALSE, FALSE, FALSE),
( 3, -7, 'plains', FALSE, FALSE, FALSE),
(-2,  6, 'plains', FALSE, FALSE, FALSE),
(-3,  7, 'plains', FALSE, FALSE, FALSE);

-- =============================================================================
-- 4. LOCATIONS (6 towns + 20 villages + 2 customs houses)
-- Schema columns: name, type, hex_q, hex_r, team, population
-- =============================================================================

INSERT IGNORE INTO locations (name, type, hex_q, hex_r, team, population) VALUES
-- Blue Team Towns
('Northaven',          'town',    -8,   2, 'blue', 45000),
('Frostpeak',          'town',    -6,   5, 'blue', 30000),
('Blueport',           'town',   -10,   0, 'blue', 35000),
-- Red Team Towns
('Ironforge',          'town',     8,  -2, 'red',  40000),
('Redfield',           'town',     6,  -5, 'red',  28000),
('Southgate',          'town',    10,   0, 'red',  32000),
-- Blue Team Villages
('Frostvale',          'village',  -7,   0, 'blue',  4500),
('Snowfield',          'village',  -9,   3, 'blue',  3200),
('Pinewatch',          'village',  -5,   1, 'blue',  5500),
('Wolfhaven',          'village', -11,   2, 'blue',  2800),
('Coldspring',         'village',  -7,   4, 'blue',  3800),
('Irondale',           'village',  -8,  -1, 'blue',  4200),
('Millbrook',          'village',  -6,   3, 'blue',  3000),
('Stonewall',          'village', -10,  -2, 'blue',  5000),
('Windmere',           'village',  -5,  -1, 'blue',  3600),
('Ravenswood',         'village',  -9,   1, 'blue',  2500),
-- Red Team Villages
('Dusthaven',          'village',   7,  -4, 'red',   4000),
('Sunvale',            'village',   9,  -1, 'red',   3500),
('Ironpike',           'village',   5,  -3, 'red',   5200),
('Copperfield',        'village',  11,  -2, 'red',   2800),
('Ashford',            'village',   7,   0, 'red',   3800),
('Thornbury',          'village',   8,  -4, 'red',   4500),
('Sandcreek',          'village',   6,  -1, 'red',   3200),
('Goldmere',           'village',  10,  -3, 'red',   2600),
('Drywell',            'village',   9,  -4, 'red',   4800),
('Redthorn',           'village',   5,  -1, 'red',   3000),
-- Customs Houses (border locations)
('Blue Customs House', 'customs_house', -3, 0, 'blue', 0),
('Red Customs House',  'customs_house',  3, 0, 'red',  0);

-- =============================================================================
-- 5. CUSTOMS HOUSES (linked to their locations)
-- =============================================================================

INSERT IGNORE INTO customs_houses (location_id, team, border_edge)
SELECT id, 'blue', 'west' FROM locations WHERE name = 'Blue Customs House'
UNION ALL
SELECT id, 'red',  'east' FROM locations WHERE name = 'Red Customs House';

-- =============================================================================
-- 6. POPULATION COHORTS
-- baby 8% | child 17% | adult(uneducated) 40% | adult(primary) 25%
-- adult(advanced) 5% | elderly 5%   (split ~50/50 male/female)
-- =============================================================================

INSERT IGNORE INTO population (location_id, age_group, education_level, gender, count)
-- babies
SELECT id, 'baby',  'none', 'male',   FLOOR(population * 0.04) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'baby',  'none', 'female', FLOOR(population * 0.04) FROM locations WHERE type IN ('town','village')
UNION ALL
-- children
SELECT id, 'child', 'none', 'male',   FLOOR(population * 0.085) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'child', 'none', 'female', FLOOR(population * 0.085) FROM locations WHERE type IN ('town','village')
UNION ALL
-- adults uneducated
SELECT id, 'adult', 'none', 'male',   FLOOR(population * 0.20) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'adult', 'none', 'female', FLOOR(population * 0.20) FROM locations WHERE type IN ('town','village')
UNION ALL
-- adults primary educated
SELECT id, 'adult', 'primary', 'male',   FLOOR(population * 0.125) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'adult', 'primary', 'female', FLOOR(population * 0.125) FROM locations WHERE type IN ('town','village')
UNION ALL
-- adults advanced educated
SELECT id, 'adult', 'advanced', 'male',   FLOOR(population * 0.025) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'adult', 'advanced', 'female', FLOOR(population * 0.025) FROM locations WHERE type IN ('town','village')
UNION ALL
-- elderly
SELECT id, 'elderly', 'none', 'male',   FLOOR(population * 0.025) FROM locations WHERE type IN ('town','village')
UNION ALL
SELECT id, 'elderly', 'none', 'female', FLOOR(population * 0.025) FROM locations WHERE type IN ('town','village');

-- =============================================================================
-- 7. LOCATION STORAGE
-- Schema enum: 'aggregate','open','dry_bulk','warehouse','liquid'
-- Towns: aggregate(5000), open(3000), dry_bulk(2000), warehouse(2000), liquid(1500)
-- Villages: aggregate(1000), open(500), dry_bulk(300), warehouse(300), liquid(200)
-- =============================================================================

INSERT IGNORE INTO location_storage (location_id, storage_type, capacity)
-- Town storage
SELECT id, 'aggregate',    5000 FROM locations WHERE type = 'town'
UNION ALL
SELECT id, 'open',         3000 FROM locations WHERE type = 'town'
UNION ALL
SELECT id, 'dry_bulk',     2000 FROM locations WHERE type = 'town'
UNION ALL
SELECT id, 'warehouse',    2000 FROM locations WHERE type = 'town'
UNION ALL
SELECT id, 'liquid',       1500 FROM locations WHERE type = 'town'
UNION ALL
-- Village storage
SELECT id, 'aggregate',    1000 FROM locations WHERE type = 'village'
UNION ALL
SELECT id, 'open',          500 FROM locations WHERE type = 'village'
UNION ALL
SELECT id, 'dry_bulk',      300 FROM locations WHERE type = 'village'
UNION ALL
SELECT id, 'warehouse',     300 FROM locations WHERE type = 'village'
UNION ALL
SELECT id, 'liquid',        200 FROM locations WHERE type = 'village';

-- =============================================================================
-- 8. STARTING RESOURCES
-- Towns: coal 500, food 300, fuel 200, wood 200, gravel 300, crops 200
-- Villages: coal 100, food 50, fuel 30, wood 50
-- =============================================================================

INSERT IGNORE INTO location_resources (location_id, resource_id, quantity)
-- Town starting resources
SELECT l.id, r.id,
    CASE r.name
        WHEN 'coal'   THEN 500
        WHEN 'food'   THEN 300
        WHEN 'fuel'   THEN 200
        WHEN 'wood'   THEN 200
        WHEN 'gravel' THEN 300
        WHEN 'crops'  THEN 200
    END
FROM locations l
CROSS JOIN resources r
WHERE l.type = 'town'
  AND r.name IN ('coal','food','fuel','wood','gravel','crops')
UNION ALL
-- Village starting resources
SELECT l.id, r.id,
    CASE r.name
        WHEN 'coal' THEN 100
        WHEN 'food' THEN 50
        WHEN 'fuel' THEN 30
        WHEN 'wood' THEN 50
    END
FROM locations l
CROSS JOIN resources r
WHERE l.type = 'village'
  AND r.name IN ('coal','food','fuel','wood');

-- =============================================================================
-- 9. VEHICLE TYPES
-- Schema columns: name, category (truck/locomotive/wagon/ship/aircraft/military/farm),
--   fuel_type, fuel_consumption_per_km, base_speed_kmh,
--   container_capacity_20ft, can_carry_40ft, cargo_type, max_cargo_weight, requires_education
-- =============================================================================

INSERT IGNORE INTO vehicle_types (name, category, fuel_type, fuel_consumption_per_km, base_speed_kmh,
    container_capacity_20ft, can_carry_40ft, cargo_type, max_cargo_weight, requires_education) VALUES
-- Trucks
('Light Truck',           'truck',      'fuel',    0.30,  80, 0, FALSE, 'any',            5.00,  'none'),
('Medium Truck',          'truck',      'fuel',    0.50,  70, 1, FALSE, 'container',     15.00,  'none'),
('Heavy Truck',           'truck',      'fuel',    0.80,  60, 2, TRUE,  'container',     25.00,  'primary'),
('Aggregate Truck',       'truck',      'fuel',    0.60,  65, 0, FALSE, 'aggregate',     20.00,  'none'),
('Tanker Truck',          'truck',      'fuel',    0.70,  60, 0, FALSE, 'liquid',        15.00,  'none'),
('Livestock Truck',       'truck',      'fuel',    0.50,  55, 0, FALSE, 'livestock',     50.00,  'none'),
('Refrigerated Truck',    'truck',      'fuel',    0.60,  65, 0, FALSE, 'refrigerated',  10.00,  'none'),
-- Locomotives
('Steam Locomotive',      'locomotive', 'coal',   50.00,  80, 0, FALSE, 'any',            0.00,  'primary'),
('Diesel Locomotive',     'locomotive', 'fuel',   20.00, 120, 0, FALSE, 'any',            0.00,  'primary'),
('Electric Locomotive',   'locomotive', 'power',   5.00, 140, 0, FALSE, 'any',            0.00,  'advanced'),
-- Wagons (towed, no fuel)
('Flatcar',               'wagon',      NULL,      0.00,   0, 2, TRUE,  'container',     40.00,  'none'),
('Boxcar',                'wagon',      NULL,      0.00,   0, 0, FALSE, 'any',           50.00,  'none'),
('Hopper',                'wagon',      NULL,      0.00,   0, 0, FALSE, 'aggregate',     60.00,  'none'),
('Tank Wagon',            'wagon',      NULL,      0.00,   0, 0, FALSE, 'liquid',        40.00,  'none'),
('Livestock Wagon',       'wagon',      NULL,      0.00,   0, 0, FALSE, 'livestock',    100.00,  'none'),
('Refrigerated Wagon',    'wagon',      NULL,      0.00,   0, 0, FALSE, 'refrigerated',  30.00,  'none'),
-- Ships
('Cargo Ship',            'ship',       'fuel',  200.00,  40, 0, FALSE, 'any',         5000.00,  'advanced'),
('Tanker Ship',           'ship',       'fuel',  150.00,  35, 0, FALSE, 'liquid',      3000.00,  'advanced'),
('Container Ship',        'ship',       'fuel',  180.00,  38, 20,TRUE,  'container',     20.00,  'advanced'),
-- Aircraft
('Cargo Plane',           'aircraft',   'fuel',  500.00, 800, 0, FALSE, 'any',           50.00,  'advanced'),
-- Military Vehicles
('Tank',                  'military',   'fuel',    3.00,  45, 0, FALSE, 'any',            0.00,  'officer'),
('APC',                   'military',   'fuel',    1.50,  65, 0, FALSE, 'any',            0.00,  'primary'),
('Artillery',             'military',   'fuel',    2.00,  30, 0, FALSE, 'any',            0.00,  'primary'),
('Ambulance',             'military',   'fuel',    0.40,  90, 0, FALSE, 'any',            0.00,  'primary'),
('Military Truck',        'military',   'fuel',    0.60,  70, 1, FALSE, 'container',     10.00,  'none'),
-- Farm Vehicles
('Tractor',               'farm',       'fuel',    1.00,  30, 0, FALSE, 'any',            0.00,  'none'),
('Combine Harvester',     'farm',       'fuel',    2.00,  20, 0, FALSE, 'any',            0.00,  'none');

-- =============================================================================
-- 10. BUILDING TYPES
-- Schema columns: name, category (mine/factory/farm/military/education/medical/
--   infrastructure/residential/logistics/power), construction_cost (JSON),
--   workers_required, education_required, description
-- =============================================================================

INSERT IGNORE INTO building_types (name, category, construction_cost, workers_required, education_required, description) VALUES
-- Infrastructure / Administrative
('town_hall',               'infrastructure', '{"bricks":50,"wood":30,"steel":10}',                                     10, 'primary',  'Central administration building for a settlement'),
('customs_house',           'infrastructure', '{"bricks":40,"steel":20,"wood":10}',                                      8, 'primary',  'Controls trade and collects tariffs at borders'),
-- Extraction / Mining
('coal_mine',               'mine',           '{"wood":40,"steel":20,"bricks":30}',                                     20, 'none',     'Extracts coal from underground deposits'),
('iron_mine',               'mine',           '{"wood":40,"steel":30,"bricks":30}',                                     25, 'none',     'Extracts iron ore from underground deposits'),
('gravel_quarry',           'mine',           '{"wood":20,"steel":15,"bricks":10}',                                     15, 'none',     'Quarries gravel and aggregate materials'),
('bauxite_mine',            'mine',           '{"wood":40,"steel":35,"bricks":30}',                                     25, 'none',     'Extracts bauxite ore for aluminium production'),
('uranium_mine',            'mine',           '{"steel":60,"bricks":50}',                                               30, 'primary',  'Extracts uranium ore with safety equipment'),
('oil_well',                'mine',           '{"steel":80,"mechanical_components":10}',                                 15, 'primary',  'Pumps crude oil from underground reserves'),
('logging_camp',            'mine',           '{"wood":20,"steel":5}',                                                  15, 'none',     'Harvests timber from surrounding forests'),
-- Processing / Manufacturing (all are 'factory')
('steel_mill',              'factory',        '{"bricks":100,"steel":50,"mechanical_components":10}',                    40, 'primary',  'Smelts iron and scrap into steel'),
('sawmill',                 'factory',        '{"wood":30,"steel":15,"bricks":20}',                                     12, 'none',     'Processes raw timber into lumber and panels'),
('brick_factory',           'factory',        '{"steel":20,"bricks":10,"wood":10}',                                     15, 'none',     'Produces bricks from aggregate materials'),
('cement_plant',            'factory',        '{"steel":40,"bricks":60,"mechanical_components":5}',                      20, 'primary',  'Grinds and heats aggregate into cement'),
('aluminium_smelter',       'factory',        '{"steel":60,"bricks":80,"electrical_components":5}',                      30, 'primary',  'Smelts bauxite into aluminium oxide and aluminium'),
('oil_refinery',            'factory',        '{"steel":100,"mechanical_components":15,"bricks":60}',                    35, 'primary',  'Refines crude oil into fuel, bitumen, and plastics'),
('uranium_processing_plant','factory',        '{"steel":80,"bricks":100,"electronics":10}',                              25, 'advanced', 'Processes uranium ore into uranium oxide'),
('nuclear_fuel_plant',      'factory',        '{"steel":100,"bricks":120,"electronics":20}',                             20, 'advanced', 'Fabricates nuclear fuel rods from uranium oxide'),
('food_processing_plant',   'factory',        '{"bricks":40,"steel":20,"mechanical_components":5}',                      20, 'none',     'Processes crops into packaged food products'),
('textile_mill',            'factory',        '{"bricks":30,"steel":15,"mechanical_components":5}',                      18, 'none',     'Weaves raw crops (cotton/flax) into fabrics'),
('plastics_factory',        'factory',        '{"steel":30,"bricks":40,"mechanical_components":8}',                      20, 'primary',  'Produces plastics from oil derivatives'),
('fertilizer_plant',        'factory',        '{"steel":25,"bricks":30,"mechanical_components":5}',                      12, 'primary',  'Produces liquid fertilizer from oil'),
('concrete_plant',          'factory',        '{"steel":15,"bricks":20}',                                                 8, 'none',     'Mixes cement and gravel into concrete on demand'),
('asphalt_plant',           'factory',        '{"steel":15,"bricks":20}',                                                 8, 'none',     'Mixes bitumen and gravel into asphalt on demand'),
('clothing_factory',        'factory',        '{"bricks":40,"steel":20,"mechanical_components":8}',                      25, 'primary',  'Manufactures clothing from fabrics'),
('electronics_factory',     'factory',        '{"steel":50,"bricks":60,"electrical_components":10}',                     30, 'advanced', 'Produces electronics from components'),
('electrical_factory',      'factory',        '{"steel":40,"bricks":50,"mechanical_components":8}',                      25, 'primary',  'Manufactures electrical components'),
('mechanical_workshop',     'factory',        '{"steel":30,"bricks":30,"wood":10}',                                     20, 'primary',  'Produces mechanical components from steel'),
('explosives_factory',      'factory',        '{"steel":40,"bricks":60,"mechanical_components":5}',                      15, 'primary',  'Manufactures explosives from chemical inputs'),
('weapons_factory',         'factory',        '{"steel":80,"bricks":60,"mechanical_components":15,"electronics":5}',     35, 'advanced', 'Produces military weaponry and ammunition'),
('brewery',                 'factory',        '{"bricks":25,"wood":15,"mechanical_components":3}',                       10, 'none',     'Brews alcohol from crops'),
-- Vehicle Production (factory)
('vehicle_factory',         'factory',        '{"steel":120,"bricks":80,"mechanical_components":20,"electronics":10}',   50, 'advanced', 'Assembles trucks and civilian vehicles'),
('rail_factory',            'factory',        '{"steel":150,"bricks":100,"mechanical_components":25,"electronics":15}',  60, 'advanced', 'Builds locomotives and rolling stock'),
('dry_dock',                'factory',        '{"steel":200,"bricks":120,"mechanical_components":30,"electronics":10}',  70, 'advanced', 'Constructs and repairs ships'),
('airplane_factory',        'factory',        '{"steel":180,"bricks":100,"aluminium":50,"electronics":30}',              80, 'advanced', 'Manufactures aircraft'),
-- Power Generation
('coal_power_station',      'power',          '{"steel":80,"bricks":100,"mechanical_components":15}',                    25, 'primary',  'Generates electricity by burning coal'),
('oil_power_station',       'power',          '{"steel":60,"bricks":80,"mechanical_components":12}',                     20, 'primary',  'Generates electricity by burning fuel'),
('nuclear_power_station',   'power',          '{"steel":200,"bricks":300,"electronics":50,"mechanical_components":30}',  40, 'advanced', 'Generates large amounts of power from nuclear fuel'),
-- Education
('school',                  'education',      '{"bricks":60,"wood":30,"steel":10}',                                     15, 'primary',  'Provides primary education to children and adults'),
('college',                 'education',      '{"bricks":100,"steel":30,"wood":20,"electronics":5}',                     25, 'advanced', 'Provides advanced education and training'),
('officer_training_school', 'education',      '{"bricks":80,"steel":40,"wood":20}',                                     20, 'advanced', 'Trains military officers and strategists'),
-- Medical
('hospital',                'medical',        '{"bricks":80,"steel":30,"electronics":10,"mechanical_components":5}',     30, 'advanced', 'Provides medical care and reduces mortality'),
-- Logistics
('workshop',                'logistics',      '{"bricks":20,"steel":15,"wood":10}',                                      8, 'primary',  'Repairs vehicles and equipment'),
('warehouse_building',      'logistics',      '{"bricks":40,"steel":20,"wood":15}',                                      5, 'none',     'Provides storage space for goods'),
('rail_hub',                'logistics',      '{"steel":60,"bricks":40,"gravel":50}',                                   10, 'primary',  'Enables rail transport connections'),
('dockyard',                'logistics',      '{"steel":80,"bricks":50,"wood":30}',                                     15, 'primary',  'Port facility for loading/unloading ships'),
('airport',                 'logistics',      '{"steel":100,"bricks":80,"gravel":100,"electronics":10}',                20, 'advanced', 'Airfield for cargo plane operations'),
-- Residential
('house_low',               'residential',    '{"wood":20,"bricks":15}',                                                 2, 'none',     'Basic housing for up to 4 people'),
('house_medium',            'residential',    '{"bricks":30,"wood":10,"steel":5}',                                       3, 'none',     'Standard housing for up to 6 people'),
('house_high',              'residential',    '{"bricks":50,"steel":15,"wood":10,"electronics":2}',                      4, 'none',     'Quality housing for up to 6 people'),
('apartment_block',         'residential',    '{"bricks":100,"steel":40,"cement":30,"electronics":5}',                  10, 'primary',  'Multi-family housing for up to 40 people'),
-- Military
('barracks',                'military',       '{"bricks":60,"steel":30,"wood":20}',                                      5, 'none',     'Houses and trains military units'),
('weapons_depot',           'military',       '{"steel":50,"bricks":60,"cement":20}',                                    5, 'primary',  'Secure storage for weapons and ammunition'),
-- Agriculture
('farm',                    'farm',           '{"wood":30,"steel":5,"bricks":10}',                                      10, 'none',     'Cultivates crops on surrounding land'),
('livestock_farm',          'farm',           '{"wood":40,"steel":10,"bricks":15}',                                      8, 'none',     'Raises livestock for meat and other products'),
('slaughterhouse',          'farm',           '{"bricks":30,"steel":20,"mechanical_components":5}',                     12, 'none',     'Processes livestock into meat');

-- =============================================================================
-- 11. STARTING BUILDINGS
-- Uses building_type_id FK via subquery
-- Main towns (Northaven, Ironforge): town_hall, warehouse_building, school,
--   hospital, coal_mine, farm, barracks
-- Other towns: town_hall, warehouse_building, school
-- All villages: warehouse_building
-- =============================================================================

-- Northaven (Blue HQ)
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'blue'
FROM locations l, building_types bt
WHERE l.name = 'Northaven' AND bt.name IN ('town_hall','warehouse_building','school','hospital','coal_mine','farm','barracks');

-- Ironforge (Red HQ)
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'red'
FROM locations l, building_types bt
WHERE l.name = 'Ironforge' AND bt.name IN ('town_hall','warehouse_building','school','hospital','coal_mine','farm','barracks');

-- Other Blue Towns: Frostpeak, Blueport
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'blue'
FROM locations l, building_types bt
WHERE l.name IN ('Frostpeak','Blueport') AND bt.name IN ('town_hall','warehouse_building','school');

-- Other Red Towns: Redfield, Southgate
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'red'
FROM locations l, building_types bt
WHERE l.name IN ('Redfield','Southgate') AND bt.name IN ('town_hall','warehouse_building','school');

-- Blue Villages: warehouse_building
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'blue'
FROM locations l, building_types bt
WHERE l.type = 'village' AND l.team = 'blue' AND bt.name = 'warehouse_building';

-- Red Villages: warehouse_building
INSERT IGNORE INTO buildings (location_id, building_type_id, level, health, max_health, is_operational, is_under_construction, construction_progress, team)
SELECT l.id, bt.id, 1, 100.00, 100.00, TRUE, FALSE, 100.00, 'red'
FROM locations l, building_types bt
WHERE l.type = 'village' AND l.team = 'red' AND bt.name = 'warehouse_building';

-- =============================================================================
-- 12. PRODUCTION RECIPES
-- Schema table: resource_production_recipes
-- Columns: building_type, input_resources (JSON), output_resources (JSON),
--   workers_required, ticks_per_cycle, education_required
-- =============================================================================

INSERT IGNORE INTO resource_production_recipes (building_type, input_resources, output_resources, workers_required, ticks_per_cycle, education_required) VALUES
-- Extraction (no material inputs)
('coal_mine',                '{}',                                            '{"coal":10}',                    5,  1, 'none'),
('iron_mine',                '{}',                                            '{"iron":8}',                     6,  1, 'none'),
('gravel_quarry',            '{}',                                            '{"gravel":15}',                  4,  1, 'none'),
('bauxite_mine',             '{}',                                            '{"bauxite":6}',                  6,  1, 'none'),
('uranium_mine',             '{}',                                            '{"uranium_ore":2}',              8,  2, 'primary'),
('oil_well',                 '{}',                                            '{"oil":12}',                     4,  1, 'primary'),
('logging_camp',             '{}',                                            '{"wood":10}',                    4,  1, 'none'),
-- Processing
('steel_mill',               '{"iron":5,"coal":2}',                           '{"steel":3}',                   10,  2, 'primary'),
('steel_mill',               '{"steel_scrap":8}',                             '{"steel":5}',                    8,  2, 'primary'),
('sawmill',                  '{"wood":5}',                                    '{"prefab_panels":8}',            4,  1, 'none'),
('brick_factory',            '{"gravel":4}',                                  '{"bricks":20}',                  5,  1, 'none'),
('cement_plant',             '{"gravel":5,"coal":1}',                         '{"cement":4}',                   6,  2, 'primary'),
('aluminium_smelter',        '{"bauxite":5}',                                 '{"aluminium_oxide":3}',          8,  2, 'primary'),
('aluminium_smelter',        '{"aluminium_oxide":3}',                         '{"aluminium":2}',               10,  3, 'primary'),
('oil_refinery',             '{"oil":10}',                                    '{"fuel":8,"bitumen":2}',          8,  2, 'primary'),
('oil_refinery',             '{"oil":8}',                                     '{"plastics":3}',                  8,  2, 'primary'),
('uranium_processing_plant', '{"uranium_ore":3}',                             '{"uranium_oxide":1}',             8,  3, 'advanced'),
('nuclear_fuel_plant',       '{"uranium_oxide":2}',                           '{"nuclear_fuel":1}',              6,  4, 'advanced'),
('food_processing_plant',    '{"crops":5}',                                   '{"food":4}',                      6,  1, 'none'),
('food_processing_plant',    '{"meat":3}',                                    '{"food":5}',                      5,  1, 'none'),
('textile_mill',             '{"crops":4}',                                   '{"fabrics":2}',                   5,  1, 'none'),
('plastics_factory',         '{"oil":5}',                                     '{"plastics":3}',                  6,  2, 'primary'),
('fertilizer_plant',         '{"oil":3}',                                     '{"liquid_fertilizer":8}',         4,  2, 'primary'),
('concrete_plant',           '{"cement":3,"gravel":5}',                       '{"concrete":6}',                  3,  1, 'none'),
('asphalt_plant',            '{"bitumen":3,"gravel":5}',                      '{"asphalt":6}',                   3,  1, 'none'),
-- Manufacturing
('clothing_factory',         '{"fabrics":3}',                                 '{"clothes":2}',                   8,  1, 'primary'),
('electronics_factory',      '{"electrical_components":3,"plastics":1}',      '{"electronics":2}',              10,  2, 'advanced'),
('electrical_factory',       '{"aluminium":2,"plastics":1}',                  '{"electrical_components":5}',     8,  2, 'primary'),
('mechanical_workshop',      '{"steel":3}',                                   '{"mechanical_components":5}',     6,  1, 'primary'),
('explosives_factory',       '{"coal":2,"oil":3}',                            '{"explosives":2}',                5,  2, 'primary'),
('weapons_factory',          '{"steel":5,"explosives":2,"mechanical_components":3}', '{"explosives":8}',        12,  3, 'advanced'),
('brewery',                  '{"crops":3}',                                   '{"alcohol":5}',                   3,  2, 'none'),
-- Power Generation
('coal_power_station',       '{"coal":10}',                                   '{"power":500}',                   8,  1, 'primary'),
('oil_power_station',        '{"fuel":15}',                                   '{"power":400}',                   6,  1, 'primary'),
('nuclear_power_station',    '{"nuclear_fuel":1}',                            '{"power":5000}',                 15,  5, 'advanced'),
-- Agriculture
('farm',                     '{}',                                            '{"crops":8}',                     6,  3, 'none'),
('farm',                     '{"liquid_fertilizer":5}',                       '{"crops":15}',                    6,  3, 'none'),
('livestock_farm',           '{"crops":3,"food":1}',                          '{"livestock":2}',                 4,  4, 'none'),
('slaughterhouse',           '{"livestock":5}',                               '{"meat":4}',                      5,  1, 'none'),
-- Education (consume food to operate; progression handled by game logic)
('school',                   '{"food":1}',                                    '{}',                              10,  5, 'primary'),
('college',                  '{"food":2}',                                    '{}',                              15, 10, 'advanced'),
('officer_training_school',  '{"food":2}',                                    '{}',                              10, 10, 'advanced'),
-- Medical
('hospital',                 '{"food":2}',                                    '{}',                              15,  1, 'advanced'),
-- Vehicle Production (materials consumed; vehicle creation handled by game logic)
('vehicle_factory',          '{"steel":10,"mechanical_components":5,"electronics":2}',           '{}',           20,  5, 'advanced'),
('rail_factory',             '{"steel":20,"mechanical_components":10,"electronics":5}',          '{}',           25,  8, 'advanced'),
('dry_dock',                 '{"steel":50,"mechanical_components":15,"electronics":5}',          '{}',           30, 15, 'advanced'),
('airplane_factory',         '{"aluminium":30,"electronics":15,"mechanical_components":10}',     '{}',           30, 10, 'advanced');

-- =============================================================================
-- 13. INITIAL WEATHER STATE
-- =============================================================================

INSERT IGNORE INTO weather_state (current_weather, temperature, wind_speed, precipitation, season, day_of_year)
VALUES ('clear', 15.00, 8.00, 0.00, 'spring', 1);

-- =============================================================================
-- 14. INITIAL MARKET PRICES (one row per resource)
-- =============================================================================

INSERT IGNORE INTO market_prices (resource_id, current_price, price_trend, last_updated_tick)
SELECT id, base_market_price, 0.00, 0
FROM resources;

-- =============================================================================
-- 15. LOCATION HAPPINESS (one row per settlement)
-- =============================================================================

INSERT IGNORE INTO location_happiness (location_id, food_satisfaction, meat_satisfaction,
    clothing_satisfaction, alcohol_satisfaction, health_satisfaction,
    housing_satisfaction, overall_happiness, productivity_modifier, last_updated_tick)
SELECT id, 50.00, 50.00, 50.00, 50.00, 50.00, 50.00, 50.00, 1.00, 0
FROM locations WHERE type IN ('town','village');

-- =============================================================================
-- 16. GAMEMASTER RULES
-- =============================================================================

INSERT IGNORE INTO gamemaster_rules (title, content, category, created_by) VALUES
(
    'Game Objectives',
    'Each team must build a self-sustaining economy, develop military capability, and achieve strategic dominance. Victory is achieved by controlling 75% of all towns on the map or by forcing the opposing team to surrender. Economic warfare, espionage, and diplomacy are all valid strategies.',
    'objectives',
    (SELECT id FROM users WHERE username = 'GameMaster')
),
(
    'Tick System',
    'The game operates on a tick-based system. Each tick represents 1 minute of real time. All production, resource consumption, population changes, vehicle movement, and combat are calculated once per tick (1440 ticks per day). The gamemaster can start/stop the tick engine.',
    'mechanics',
    (SELECT id FROM users WHERE username = 'GameMaster')
),
(
    'Combat Rules',
    'Military engagements occur when opposing armies occupy the same hex. Combat is resolved hourly (every 60 ticks) based on unit types, equipment, terrain, morale, and supply levels. Defenders receive a terrain bonus. Units require food, fuel, and ammunition to fight effectively. Retreating armies suffer additional casualties.',
    'combat',
    (SELECT id FROM users WHERE username = 'GameMaster')
),
(
    'Economy Rules',
    'Resources are produced by buildings and consumed by population and industry. Each resource has a base market price that fluctuates based on supply and demand. Teams may trade on the global market or negotiate bilateral agreements. Customs houses control border trade. Resources purchased are delivered to customs houses.',
    'economy',
    (SELECT id FROM users WHERE username = 'GameMaster')
),
(
    'Weather Effects',
    'Weather changes every 60 ticks and varies by season. Rain reduces movement speed by 20% and farm output by 10%. Snow reduces movement by 40%, increases fuel consumption by 30%, and halts farming. Storms halt all air and sea transport. Fog reduces combat accuracy by 25%. Spring and summer favour farming; winter increases food and fuel consumption.',
    'environment',
    (SELECT id FROM users WHERE username = 'GameMaster')
),
(
    'Population and Morale',
    'Population grows naturally when food supply is adequate and happiness is above 60%. Happiness is affected by food availability, housing quality, employment rate, proximity to combat, and access to consumer goods (clothes, alcohol, electronics). Unhappy populations produce less and may refuse to work. Starvation causes population decline and severe morale penalties.',
    'population',
    (SELECT id FROM users WHERE username = 'GameMaster')
);

-- =============================================================================
-- SEED DATA COMPLETE
-- =============================================================================