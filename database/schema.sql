-- =============================================================================
-- Black Legion Cold War Campaign — Complete Database Schema
-- Version 2.0
--
-- A Workers & Resources: Soviet Republic inspired web strategy game.
-- Two teams (blue / red) compete on a hex map across economic, military,
-- logistics, espionage, and governance systems.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS campaign_data
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE campaign_data;

-- =============================================================================
-- 1. AUTHENTICATION & USERS
-- =============================================================================

CREATE TABLE users (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    steam_id        VARCHAR(20) UNIQUE NOT NULL,
    username        VARCHAR(50) NOT NULL,
    avatar_url      VARCHAR(255) NULL,
    team            ENUM('blue','red') NULL                     COMMENT 'NULL until assigned',
    role            ENUM('blue_admin','red_admin',
                         'blue_member','red_member',
                         'blue_observer','red_observer',
                         'gamemaster') NOT NULL DEFAULT 'blue_observer',
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_login      TIMESTAMP NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Authenticated players & gamemasters';

CREATE TABLE user_sessions (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NOT NULL,
    token_hash      VARCHAR(255) NOT NULL                       COMMENT 'SHA-256 of JWT',
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(512) NULL,
    issued_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMP NOT NULL,
    revoked_at      TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='JWT session tracking';

CREATE TABLE team_internal_roles (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    user_id         INT NOT NULL,
    role_name       VARCHAR(100) NOT NULL                       COMMENT 'e.g. Minister of Defence',
    permissions     JSON NULL                                   COMMENT 'Granular permission flags',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_team_user_role (team, user_id, role_name)
) ENGINE=InnoDB COMMENT='Custom team-defined roles';

-- =============================================================================
-- 2. GAME STATE
-- =============================================================================

CREATE TABLE game_state (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    current_tick    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    game_started    BOOLEAN NOT NULL DEFAULT FALSE,
    start_date      TIMESTAMP NULL,
    last_tick_time  TIMESTAMP NULL,
    season          ENUM('spring','summer','autumn','winter') NOT NULL DEFAULT 'spring',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Single-row global game state';

-- =============================================================================
-- 3. MAP & LOCATIONS
-- =============================================================================

CREATE TABLE hex_tiles (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    hex_q           INT NOT NULL                                COMMENT 'Axial coordinate q',
    hex_r           INT NOT NULL                                COMMENT 'Axial coordinate r',
    terrain_type    ENUM('plains','forest','mountain','water','desert') NOT NULL DEFAULT 'plains',
    has_road        BOOLEAN NOT NULL DEFAULT FALSE,
    has_rail        BOOLEAN NOT NULL DEFAULT FALSE,
    has_river       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hex_coords (hex_q, hex_r)
) ENGINE=InnoDB COMMENT='Hex map tiles with axial coordinates';

CREATE TABLE locations (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,
    type            ENUM('town','city','village','customs_house') NOT NULL,
    hex_q           INT NOT NULL,
    hex_r           INT NOT NULL,
    team            ENUM('blue','red') NULL,
    population      INT UNSIGNED NOT NULL DEFAULT 0             COMMENT 'Total population (denormalised)',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_location_hex (hex_q, hex_r),
    INDEX idx_location_team (team)
) ENGINE=InnoDB COMMENT='Towns, cities, villages, customs houses';

CREATE TABLE location_storage (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    storage_type    ENUM('aggregate','open','dry_bulk','warehouse','liquid') NOT NULL,
    capacity        DECIMAL(15,3) NOT NULL DEFAULT 0,
    current_used    DECIMAL(15,3) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_locstorage_location (location_id)
) ENGINE=InnoDB COMMENT='Per-location typed storage pools';

CREATE TABLE customs_houses (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    team            ENUM('blue','red') NOT NULL,
    border_edge     VARCHAR(20) NOT NULL                        COMMENT 'Border edge position identifier',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Customs houses at border edges';

-- =============================================================================
-- 4. POPULATION & WORKERS
-- =============================================================================

CREATE TABLE population (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    age_group       ENUM('baby','child','adult','elderly') NOT NULL,
    education_level ENUM('none','primary','advanced','officer') NOT NULL DEFAULT 'none',
    gender          ENUM('male','female') NOT NULL DEFAULT 'male',
    count           INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_pop_location (location_id)
) ENGINE=InnoDB COMMENT='Population cohorts per location';

CREATE TABLE worker_assignments (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    population_id   INT NOT NULL,
    building_id     INT NOT NULL                                COMMENT 'FK to buildings.id',
    role            ENUM('worker','teacher','professor','doctor','nurse',
                         'soldier','spy','general') NOT NULL DEFAULT 'worker',
    assigned_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (population_id) REFERENCES population(id) ON DELETE CASCADE,
    INDEX idx_wa_building (building_id)
) ENGINE=InnoDB COMMENT='Worker allocations from population to buildings';

CREATE TABLE education_queue (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    building_id     INT NOT NULL                                COMMENT 'School / college / officer school',
    student_count   INT UNSIGNED NOT NULL DEFAULT 0,
    teacher_count   INT UNSIGNED NOT NULL DEFAULT 0,
    start_tick      BIGINT UNSIGNED NOT NULL,
    end_tick        BIGINT UNSIGNED NOT NULL,
    education_type  ENUM('primary','advanced','officer') NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_eq_building (building_id)
) ENGINE=InnoDB COMMENT='Education in-progress queue';

CREATE TABLE housing (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    type            ENUM('low','medium','high','flat') NOT NULL,
    material        ENUM('prefab','brick') NOT NULL DEFAULT 'prefab',
    capacity        INT UNSIGNED NOT NULL DEFAULT 0,
    occupied        INT UNSIGNED NOT NULL DEFAULT 0,
    health          DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    building_id     INT NULL                                    COMMENT 'FK to buildings.id',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_housing_location (location_id)
) ENGINE=InnoDB COMMENT='Residential housing units';

-- =============================================================================
-- 5. RESOURCES
-- =============================================================================

CREATE TABLE resources (
    id                          INT PRIMARY KEY AUTO_INCREMENT,
    name                        VARCHAR(80) UNIQUE NOT NULL,
    category                    ENUM('aggregate','open_storage','dry_bulk','warehouse',
                                     'liquid','live','special') NOT NULL,
    unit                        VARCHAR(20) NOT NULL DEFAULT 'tons',
    can_be_stored               BOOLEAN NOT NULL DEFAULT TRUE   COMMENT 'FALSE for asphalt, concrete, power',
    requires_refrigeration      BOOLEAN NOT NULL DEFAULT FALSE,
    requires_special_container  BOOLEAN NOT NULL DEFAULT FALSE  COMMENT 'E.g. nuclear material',
    base_market_price           DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Master resource catalogue (35+ types)';

CREATE TABLE location_resources (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    resource_id     INT NOT NULL,
    quantity        DECIMAL(15,3) NOT NULL DEFAULT 0,
    max_capacity    DECIMAL(15,3) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE KEY uq_loc_resource (location_id, resource_id)
) ENGINE=InnoDB COMMENT='Resource stockpiles at each location';

CREATE TABLE resource_production_recipes (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    building_type       VARCHAR(80) NOT NULL                    COMMENT 'Matches building_types.name',
    input_resources     JSON NOT NULL                           COMMENT '[{resource_id, quantity}, ...]',
    output_resources    JSON NOT NULL                           COMMENT '[{resource_id, quantity}, ...]',
    workers_required    INT UNSIGNED NOT NULL DEFAULT 1,
    ticks_per_cycle     INT UNSIGNED NOT NULL DEFAULT 1,
    education_required  ENUM('none','primary','advanced','officer') NOT NULL DEFAULT 'none',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Production recipes mapping inputs to outputs';

-- =============================================================================
-- 6. BUILDINGS & CONSTRUCTION
-- =============================================================================

CREATE TABLE building_types (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    name                VARCHAR(80) UNIQUE NOT NULL,
    category            ENUM('mine','factory','farm','military','education','medical',
                             'infrastructure','residential','logistics','power') NOT NULL,
    construction_cost   JSON NOT NULL                           COMMENT '[{resource_id, quantity}, ...]',
    workers_required    INT UNSIGNED NOT NULL DEFAULT 0,
    education_required  ENUM('none','primary','advanced','officer') NOT NULL DEFAULT 'none',
    description         TEXT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Master list of all building types';

CREATE TABLE buildings (
    id                      INT PRIMARY KEY AUTO_INCREMENT,
    location_id             INT NOT NULL,
    building_type_id        INT NOT NULL,
    name                    VARCHAR(120) NULL,
    level                   INT UNSIGNED NOT NULL DEFAULT 1,
    health                  DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    max_health              DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    is_operational          BOOLEAN NOT NULL DEFAULT FALSE,
    is_under_construction   BOOLEAN NOT NULL DEFAULT TRUE,
    construction_progress   DECIMAL(5,2) NOT NULL DEFAULT 0.00  COMMENT '0-100 %',
    construction_start_tick BIGINT UNSIGNED NULL,
    team                    ENUM('blue','red') NULL,
    workers_assigned        INT UNSIGNED NOT NULL DEFAULT 0,
    workers_needed          INT UNSIGNED NOT NULL DEFAULT 0,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id)    REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (building_type_id) REFERENCES building_types(id),
    INDEX idx_building_location (location_id),
    INDEX idx_building_team (team)
) ENGINE=InnoDB COMMENT='Placed buildings within locations';

-- =============================================================================
-- 7. VEHICLES & TRANSPORT
-- =============================================================================

CREATE TABLE vehicle_types (
    id                          INT PRIMARY KEY AUTO_INCREMENT,
    name                        VARCHAR(80) NOT NULL,
    category                    ENUM('truck','locomotive','wagon','ship','aircraft',
                                     'military','farm') NOT NULL,
    fuel_type                   VARCHAR(30) NULL                COMMENT 'diesel, petrol, electric, nuclear, none',
    fuel_consumption_per_km     DECIMAL(8,4) NOT NULL DEFAULT 0,
    base_speed_kmh              DECIMAL(7,2) NOT NULL DEFAULT 60.00,
    container_capacity_20ft     TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '0, 1, or 2',
    can_carry_40ft              BOOLEAN NOT NULL DEFAULT FALSE,
    cargo_type                  ENUM('aggregate','container','liquid','livestock',
                                     'refrigerated','special_nuclear','any') NOT NULL DEFAULT 'any',
    max_cargo_weight            DECIMAL(10,2) NOT NULL DEFAULT 0,
    requires_education          ENUM('none','primary','advanced','officer') NOT NULL DEFAULT 'none',
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Master vehicle/rolling-stock catalogue';

CREATE TABLE vehicles (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_type_id     INT NOT NULL,
    team                ENUM('blue','red') NOT NULL,
    name                VARCHAR(120) NULL,
    location_id         INT NULL,
    health              DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    max_health          DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    fuel_level          DECIMAL(10,2) NOT NULL DEFAULT 0,
    fuel_capacity       DECIMAL(10,2) NOT NULL DEFAULT 0,
    km_traveled         DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_moving           BOOLEAN NOT NULL DEFAULT FALSE,
    current_hex_q       INT NULL,
    current_hex_r       INT NULL,
    destination_hex_q   INT NULL,
    destination_hex_r   INT NULL,
    departure_tick      BIGINT UNSIGNED NULL,
    arrival_tick        BIGINT UNSIGNED NULL,
    assigned_to         VARCHAR(80) NULL                        COMMENT 'Reference e.g. train:<id>, army:<id>',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id),
    FOREIGN KEY (location_id)     REFERENCES locations(id) ON DELETE SET NULL,
    INDEX idx_vehicle_team (team),
    INDEX idx_vehicle_location (location_id),
    INDEX idx_vehicle_hex (current_hex_q, current_hex_r)
) ENGINE=InnoDB COMMENT='Individual vehicle instances';

CREATE TABLE trains (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    name            VARCHAR(120) NULL,
    locomotive_id   INT NOT NULL                                COMMENT 'FK to vehicles (must be locomotive)',
    location_id     INT NULL,
    is_moving       BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (locomotive_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id)   REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Composed train sets';

CREATE TABLE train_wagons (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    train_id            INT NOT NULL,
    vehicle_id          INT NOT NULL                            COMMENT 'FK to vehicles (wagon)',
    position_in_train   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (train_id)  REFERENCES trains(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    UNIQUE KEY uq_train_position (train_id, position_in_train)
) ENGINE=InnoDB COMMENT='Wagons attached to a train';

CREATE TABLE containers (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    type            ENUM('20ft','40ft') NOT NULL,
    team            ENUM('blue','red') NULL,
    location_id     INT NULL,
    vehicle_id      INT NULL,
    health          DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    max_health      DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    contents        JSON NULL                                   COMMENT '[{resource_id, quantity}, ...]',
    is_loaded       BOOLEAN NOT NULL DEFAULT FALSE,
    is_refrigerated BOOLEAN NOT NULL DEFAULT FALSE,
    is_nuclear_rated BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    FOREIGN KEY (vehicle_id)  REFERENCES vehicles(id) ON DELETE SET NULL,
    INDEX idx_container_location (location_id)
) ENGINE=InnoDB COMMENT='Shipping containers (20ft / 40ft)';

-- =============================================================================
-- 8. LOGISTICS & ROUTES
-- =============================================================================

CREATE TABLE infrastructure (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    hex_q           INT NOT NULL,
    hex_r           INT NOT NULL,
    type            ENUM('road','rail','port','airport') NOT NULL,
    team            ENUM('blue','red') NULL,
    level           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    capacity        INT UNSIGNED NOT NULL DEFAULT 0,
    health          DECIMAL(7,2) NOT NULL DEFAULT 100.00,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_infra_hex (hex_q, hex_r)
) ENGINE=InnoDB COMMENT='Infrastructure segments on the hex map';

CREATE TABLE routes (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    team            ENUM('blue','red') NOT NULL,
    transport_type  ENUM('road','rail','sea','air') NOT NULL,
    waypoints       JSON NOT NULL                               COMMENT '[{hex_q, hex_r}, ...]',
    distance_km     DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_route_team (team)
) ENGINE=InnoDB COMMENT='Named transport routes';

CREATE TABLE route_schedules (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    route_id        INT NOT NULL,
    vehicle_id      INT NULL                                    COMMENT 'Assigned vehicle (or NULL if train)',
    train_id        INT NULL                                    COMMENT 'Assigned train (or NULL if vehicle)',
    resource_id     INT NOT NULL,
    threshold_quantity  DECIMAL(15,3) NOT NULL DEFAULT 0,
    transport_quantity  DECIMAL(15,3) NOT NULL DEFAULT 0,
    schedule_type   ENUM('threshold','interval','manual') NOT NULL DEFAULT 'threshold',
    interval_ticks  INT UNSIGNED NULL,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_run_tick   BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id)    REFERENCES routes(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id)  REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (train_id)    REFERENCES trains(id)  ON DELETE SET NULL,
    FOREIGN KEY (resource_id) REFERENCES resources(id)
) ENGINE=InnoDB COMMENT='Automated logistics schedules';

CREATE TABLE vehicle_trips (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id          INT NOT NULL,
    route_id            INT NULL,
    start_location_id   INT NULL,
    end_location_id     INT NULL,
    start_tick          BIGINT UNSIGNED NOT NULL,
    eta_tick            BIGINT UNSIGNED NULL,
    actual_arrival_tick BIGINT UNSIGNED NULL,
    cargo               JSON NULL                               COMMENT '[{resource_id, quantity}, ...]',
    fuel_consumed       DECIMAL(10,2) NOT NULL DEFAULT 0,
    status              ENUM('loading','in_transit','unloading','completed','broken_down')
                            NOT NULL DEFAULT 'loading',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id)        REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (route_id)          REFERENCES routes(id) ON DELETE SET NULL,
    FOREIGN KEY (start_location_id) REFERENCES locations(id) ON DELETE SET NULL,
    FOREIGN KEY (end_location_id)   REFERENCES locations(id) ON DELETE SET NULL,
    INDEX idx_trip_vehicle (vehicle_id),
    INDEX idx_trip_status (status)
) ENGINE=InnoDB COMMENT='Individual vehicle journey records';

CREATE TABLE breakdowns (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id      INT NOT NULL,
    hex_q           INT NOT NULL,
    hex_r           INT NOT NULL,
    start_tick      BIGINT UNSIGNED NOT NULL,
    clearance_tick  BIGINT UNSIGNED NULL,
    blocks_type     ENUM('road','rail') NULL                    COMMENT 'Which infrastructure is blocked',
    is_cleared      BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    INDEX idx_breakdown_hex (hex_q, hex_r)
) ENGINE=InnoDB COMMENT='Vehicle breakdowns blocking routes';

-- =============================================================================
-- 9. AGRICULTURE
-- =============================================================================

CREATE TABLE fields (
    id                      INT PRIMARY KEY AUTO_INCREMENT,
    location_id             INT NOT NULL,
    team                    ENUM('blue','red') NOT NULL,
    size_hectares           DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    crop_type               VARCHAR(60) NULL,
    growth_progress         DECIMAL(5,2) NOT NULL DEFAULT 0.00  COMMENT '0–100 %',
    is_planted              BOOLEAN NOT NULL DEFAULT FALSE,
    is_fertilized           BOOLEAN NOT NULL DEFAULT FALSE,
    fertilizer_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
    planted_tick            BIGINT UNSIGNED NULL,
    estimated_harvest_tick  BIGINT UNSIGNED NULL,
    spoilage_progress       DECIMAL(5,2) NOT NULL DEFAULT 0.00  COMMENT '0–100 %',
    weather_modifier        DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_field_location (location_id)
) ENGINE=InnoDB COMMENT='Crop fields attached to locations';

CREATE TABLE livestock_pens (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    location_id     INT NOT NULL,
    team            ENUM('blue','red') NOT NULL,
    animal_count    INT UNSIGNED NOT NULL DEFAULT 0,
    feed_required   DECIMAL(10,2) NOT NULL DEFAULT 0,
    output_per_tick DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Livestock farming pens';

-- =============================================================================
-- 10. WEATHER
-- =============================================================================

CREATE TABLE weather_state (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    current_weather ENUM('clear','cloudy','rain','heavy_rain','snow','blizzard',
                         'fog','storm','heatwave','frost') NOT NULL DEFAULT 'clear',
    temperature     DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    wind_speed      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    precipitation   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    season          ENUM('spring','summer','autumn','winter') NOT NULL DEFAULT 'spring',
    day_of_year     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Current global weather state';

CREATE TABLE weather_history (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    tick            BIGINT UNSIGNED NOT NULL,
    hex_q           INT NOT NULL,
    hex_r           INT NOT NULL,
    weather_type    ENUM('clear','cloudy','rain','heavy_rain','snow','blizzard',
                         'fog','storm','heatwave','frost') NOT NULL,
    temperature     DECIMAL(5,2) NOT NULL,
    wind_speed      DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    crop_modifier   DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    travel_modifier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    combat_modifier DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wh_tick (tick),
    INDEX idx_wh_hex (hex_q, hex_r)
) ENGINE=InnoDB COMMENT='Historical weather per hex per tick';

-- =============================================================================
-- 11. COMBAT & MILITARY
-- =============================================================================

CREATE TABLE armies (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    name                VARCHAR(120) NOT NULL,
    team                ENUM('blue','red') NOT NULL,
    general_id          INT NULL                                COMMENT 'FK to generals.id',
    location_hex_q      INT NULL,
    location_hex_r      INT NULL,
    destination_hex_q   INT NULL,
    destination_hex_r   INT NULL,
    is_moving           BOOLEAN NOT NULL DEFAULT FALSE,
    departure_tick      BIGINT UNSIGNED NULL,
    arrival_tick        BIGINT UNSIGNED NULL,
    morale              DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    experience          DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    supplies            JSON NULL                               COMMENT '{food, ammo, fuel, medical}',
    fuel_level          DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_army_team (team),
    INDEX idx_army_hex (location_hex_q, location_hex_r)
) ENGINE=InnoDB COMMENT='Military armies';

CREATE TABLE army_units (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    army_id             INT NOT NULL,
    unit_type           ENUM('infantry','tank','artillery','anti_air',
                             'transport','ambulance') NOT NULL,
    count               INT UNSIGNED NOT NULL DEFAULT 0,
    health              DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    experience          DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    equipment_status    DECIMAL(5,2) NOT NULL DEFAULT 100.00    COMMENT 'Equipment readiness %',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (army_id) REFERENCES armies(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Unit composition within an army';

CREATE TABLE army_vehicles (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    army_id         INT NOT NULL,
    vehicle_id      INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (army_id)   REFERENCES armies(id) ON DELETE CASCADE,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    UNIQUE KEY uq_army_vehicle (army_id, vehicle_id)
) ENGINE=InnoDB COMMENT='Vehicles assigned to armies';

CREATE TABLE battles (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    location_hex_q      INT NOT NULL,
    location_hex_r      INT NOT NULL,
    attacker_army_id    INT NOT NULL,
    defender_army_id    INT NOT NULL,
    start_tick          BIGINT UNSIGNED NOT NULL,
    end_tick            BIGINT UNSIGNED NULL,
    status              ENUM('active','resolved') NOT NULL DEFAULT 'active',
    winner              ENUM('attacker','defender','draw') NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attacker_army_id) REFERENCES armies(id),
    FOREIGN KEY (defender_army_id) REFERENCES armies(id),
    INDEX idx_battle_hex (location_hex_q, location_hex_r),
    INDEX idx_battle_status (status)
) ENGINE=InnoDB COMMENT='Battles between two armies';

CREATE TABLE battle_rounds (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    battle_id           INT NOT NULL,
    round_number        INT UNSIGNED NOT NULL,
    tick                BIGINT UNSIGNED NOT NULL,
    attacker_losses     JSON NULL,
    defender_losses     JSON NULL,
    round_log           JSON NULL                               COMMENT 'Detailed per-round narrative',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (battle_id) REFERENCES battles(id) ON DELETE CASCADE,
    UNIQUE KEY uq_battle_round (battle_id, round_number)
) ENGINE=InnoDB COMMENT='Per-round combat resolution';

CREATE TABLE casualties (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    battle_id       INT NOT NULL,
    army_id         INT NOT NULL,
    unit_type       ENUM('infantry','tank','artillery','anti_air',
                         'transport','ambulance') NOT NULL,
    killed          INT UNSIGNED NOT NULL DEFAULT 0,
    wounded         INT UNSIGNED NOT NULL DEFAULT 0,
    tick            BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (battle_id) REFERENCES battles(id) ON DELETE CASCADE,
    FOREIGN KEY (army_id)   REFERENCES armies(id) ON DELETE CASCADE,
    INDEX idx_casualty_battle (battle_id)
) ENGINE=InnoDB COMMENT='Casualty records per battle';

CREATE TABLE hospital_patients (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    hospital_building_id INT NOT NULL                           COMMENT 'FK to buildings.id (hospital)',
    origin_army_id      INT NULL,
    unit_type           ENUM('infantry','tank','artillery','anti_air',
                             'transport','ambulance') NOT NULL,
    count               INT UNSIGNED NOT NULL DEFAULT 0,
    admission_tick      BIGINT UNSIGNED NOT NULL,
    healing_progress    DECIMAL(5,2) NOT NULL DEFAULT 0.00      COMMENT '0–100 %',
    death_deadline_tick BIGINT UNSIGNED NULL                    COMMENT 'Die if not healed by this tick',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hp_hospital (hospital_building_id)
) ENGINE=InnoDB COMMENT='Wounded soldiers recovering in hospitals';

-- =============================================================================
-- 12. GENERALS & OFFICERS
-- =============================================================================

CREATE TABLE generals (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NULL                                    COMMENT 'FK to users.id (player general)',
    team            ENUM('blue','red') NOT NULL,
    name            VARCHAR(120) NOT NULL,
    experience      DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    rank            VARCHAR(60) NOT NULL DEFAULT 'Lieutenant',
    skill_level     DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    assigned_army_id INT NULL,
    is_available    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_general_team (team)
) ENGINE=InnoDB COMMENT='General officers commanding armies';

CREATE TABLE officer_training_queue (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    building_id     INT NOT NULL                                COMMENT 'FK to buildings.id (officer school)',
    trainee_count   INT UNSIGNED NOT NULL DEFAULT 0,
    start_tick      BIGINT UNSIGNED NOT NULL,
    end_tick        BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_otq_building (building_id)
) ENGINE=InnoDB COMMENT='Officer training in progress';

-- =============================================================================
-- 13. ESPIONAGE
-- =============================================================================

CREATE TABLE spies (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    name            VARCHAR(120) NOT NULL,
    training_level  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    location_id     INT NULL                                    COMMENT 'Target location',
    status          ENUM('training','active','captured','dead','escaped')
                        NOT NULL DEFAULT 'training',
    deployed_tick   BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
    INDEX idx_spy_team (team),
    INDEX idx_spy_status (status)
) ENGINE=InnoDB COMMENT='Espionage agents';

CREATE TABLE spy_missions (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    spy_id              INT NOT NULL,
    target_location_id  INT NOT NULL,
    mission_type        ENUM('recon','sabotage') NOT NULL,
    start_tick          BIGINT UNSIGNED NOT NULL,
    next_roll_tick      BIGINT UNSIGNED NULL,
    result              ENUM('undetected','detected_escaped','captured','executed') NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (spy_id)             REFERENCES spies(id) ON DELETE CASCADE,
    FOREIGN KEY (target_location_id) REFERENCES locations(id) ON DELETE CASCADE,
    INDEX idx_sm_spy (spy_id)
) ENGINE=InnoDB COMMENT='Active spy missions';

CREATE TABLE intelligence_reports (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    spy_id          INT NOT NULL,
    team            ENUM('blue','red') NOT NULL,
    tick            BIGINT UNSIGNED NOT NULL,
    report_type     VARCHAR(80) NOT NULL,
    data            JSON NOT NULL,
    accuracy_percent DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spy_id) REFERENCES spies(id) ON DELETE CASCADE,
    INDEX idx_ir_team_tick (team, tick)
) ENGINE=InnoDB COMMENT='Intel gathered by spies';

CREATE TABLE captured_spies (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    spy_id          INT NOT NULL,
    captured_by_team ENUM('blue','red') NOT NULL,
    capture_tick    BIGINT UNSIGNED NOT NULL,
    is_exchanged    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (spy_id) REFERENCES spies(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Captured enemy spies';

-- =============================================================================
-- 14. GLOBAL MARKET
-- =============================================================================

CREATE TABLE market_prices (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    resource_id     INT NOT NULL,
    current_price   DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    price_trend     DECIMAL(8,4) NOT NULL DEFAULT 0.00          COMMENT 'Positive = rising',
    last_updated_tick BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (resource_id) REFERENCES resources(id) ON DELETE CASCADE,
    UNIQUE KEY uq_market_resource (resource_id)
) ENGINE=InnoDB COMMENT='Current market prices per resource';

CREATE TABLE market_orders (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    resource_id     INT NOT NULL,
    order_type      ENUM('buy','sell') NOT NULL,
    quantity        DECIMAL(15,3) NOT NULL,
    price_per_unit  DECIMAL(12,2) NOT NULL,
    status          ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    tick            BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (resource_id) REFERENCES resources(id),
    INDEX idx_mo_team (team),
    INDEX idx_mo_status (status)
) ENGINE=InnoDB COMMENT='Buy / sell orders on the global market';

CREATE TABLE market_vehicle_listings (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    vehicle_type_id INT NOT NULL,
    price           DECIMAL(12,2) NOT NULL,
    status          ENUM('available','sold','cancelled') NOT NULL DEFAULT 'available',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id),
    INDEX idx_mvl_status (status)
) ENGINE=InnoDB COMMENT='Vehicle purchase listings';

-- =============================================================================
-- 15. POWER GRID
-- =============================================================================

CREATE TABLE power_grid (
    id                      INT PRIMARY KEY AUTO_INCREMENT,
    location_id             INT NOT NULL,
    generation_capacity_mw  DECIMAL(10,2) NOT NULL DEFAULT 0,
    consumption_mw          DECIMAL(10,2) NOT NULL DEFAULT 0,
    surplus_mw              DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_connected_to_border  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    UNIQUE KEY uq_power_location (location_id)
) ENGINE=InnoDB COMMENT='Per-location power balance';

CREATE TABLE power_trades (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    direction       ENUM('import','export') NOT NULL,
    amount_mw       DECIMAL(10,2) NOT NULL,
    price_per_mw    DECIMAL(10,2) NOT NULL DEFAULT 0,
    tick            BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Cross-border power import/export';

-- =============================================================================
-- 16. RULES & GOVERNANCE
-- =============================================================================

CREATE TABLE team_rules (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    title           VARCHAR(200) NOT NULL,
    content         TEXT NOT NULL,
    created_by      INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB COMMENT='Internal team rules & policies';

CREATE TABLE gamemaster_rules (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    title           VARCHAR(200) NOT NULL,
    content         TEXT NOT NULL,
    category        VARCHAR(80) NOT NULL,
    created_by      INT NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB COMMENT='Gamemaster-defined global rules';

CREATE TABLE gamemaster_actions (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    action_type     ENUM('reverse','modify','create','delete') NOT NULL,
    target_table    VARCHAR(80) NOT NULL,
    target_id       INT NOT NULL,
    old_value       JSON NULL,
    new_value       JSON NULL,
    reason          TEXT NULL,
    tick            BIGINT UNSIGNED NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB COMMENT='Gamemaster intervention audit trail';

-- =============================================================================
-- 17. AUDIT LOG & TEAM CHAT
-- =============================================================================

CREATE TABLE audit_log (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NULL,
    action          VARCHAR(120) NOT NULL,
    details         JSON NULL,
    ip_address      VARCHAR(45) NULL,
    tick            BIGINT UNSIGNED NULL,
    timestamp       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_ts (timestamp),
    INDEX idx_audit_user (user_id)
) ENGINE=InnoDB COMMENT='Global action audit log';

CREATE TABLE team_chat (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team            ENUM('blue','red') NOT NULL,
    user_id         INT NOT NULL,
    message         TEXT NOT NULL,
    tick            BIGINT UNSIGNED NULL,
    timestamp       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chat_team_ts (team, timestamp)
) ENGINE=InnoDB COMMENT='In-game team chat messages';

-- =============================================================================
-- 18. HAPPINESS & NEEDS
-- =============================================================================

CREATE TABLE location_happiness (
    id                      INT PRIMARY KEY AUTO_INCREMENT,
    location_id             INT NOT NULL,
    food_satisfaction       DECIMAL(5,2) NOT NULL DEFAULT 50.00 COMMENT '0–100',
    meat_satisfaction       DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    clothing_satisfaction   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    alcohol_satisfaction    DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    health_satisfaction     DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    housing_satisfaction    DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    overall_happiness       DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    productivity_modifier   DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    last_updated_tick       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    UNIQUE KEY uq_happiness_location (location_id)
) ENGINE=InnoDB COMMENT='Population happiness per location';