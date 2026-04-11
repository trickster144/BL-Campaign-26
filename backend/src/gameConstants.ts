// src/config - all game constants
// Placed in src/ because src/config/ directory doesn't exist yet.
// Move to src/config/gameConstants.ts when directory is created.

// ── Tick system ──────────────────────────────────────────────────────────────
export const TICK_INTERVAL_MS = 60_000; // 1 minute per tick
export const TICKS_PER_HOUR = 60;
export const TICKS_PER_DAY = 1_440;

// ── Resource categories & storage types ──────────────────────────────────────
export type StorageCategory =
  | 'aggregate'
  | 'open_storage'
  | 'dry_bulk'
  | 'warehouse'
  | 'liquid'
  | 'live';

export interface ResourceDef {
  name: string;
  category: StorageCategory;
  unit: string;
  basePrice: number;
  canStore?: boolean; // defaults true; false for asphalt/concrete
}

export const RESOURCES: Record<string, ResourceDef> = {
  // Aggregates
  gravel:              { name: 'Gravel',              category: 'aggregate',    unit: 'tons',    basePrice: 5 },
  coal:                { name: 'Coal',                category: 'aggregate',    unit: 'tons',    basePrice: 8 },
  iron:                { name: 'Iron',                category: 'aggregate',    unit: 'tons',    basePrice: 12 },
  bauxite:             { name: 'Bauxite',             category: 'aggregate',    unit: 'tons',    basePrice: 15 },
  uranium_ore:         { name: 'Uranium Ore',         category: 'aggregate',    unit: 'tons',    basePrice: 50 },
  steel_scrap:         { name: 'Steel Scrap',         category: 'aggregate',    unit: 'tons',    basePrice: 25 },
  aluminium_scrap:     { name: 'Aluminium Scrap',     category: 'aggregate',    unit: 'tons',    basePrice: 30 },
  construction_waste:  { name: 'Construction Waste',  category: 'aggregate',    unit: 'tons',    basePrice: 2 },

  // Open storage
  steel:               { name: 'Steel',               category: 'open_storage', unit: 'tons',    basePrice: 20 },
  prefab_panels:       { name: 'Prefab Panels',       category: 'open_storage', unit: 'units',   basePrice: 10 },
  bricks:              { name: 'Bricks',              category: 'open_storage', unit: 'units',   basePrice: 3 },
  wood:                { name: 'Wood',                category: 'open_storage', unit: 'tons',    basePrice: 6 },
  boards:              { name: 'Boards',              category: 'open_storage', unit: 'tons',    basePrice: 10 },
  aluminium:           { name: 'Aluminium',           category: 'open_storage', unit: 'tons',    basePrice: 35 },
  uranium_oxide:       { name: 'Uranium Oxide',       category: 'open_storage', unit: 'tons',    basePrice: 100 },
  plastic_waste:       { name: 'Plastic Waste',       category: 'open_storage', unit: 'tons',    basePrice: 4 },

  // Dry bulk
  cement:              { name: 'Cement',              category: 'dry_bulk',     unit: 'tons',    basePrice: 18 },
  aluminium_oxide:     { name: 'Aluminium Oxide',     category: 'dry_bulk',     unit: 'tons',    basePrice: 40 },

  // Warehouse
  crops:               { name: 'Crops',               category: 'warehouse',    unit: 'tons',    basePrice: 3 },
  fabrics:             { name: 'Fabrics',             category: 'warehouse',    unit: 'tons',    basePrice: 12 },
  clothes:             { name: 'Clothes',             category: 'warehouse',    unit: 'tons',    basePrice: 25 },
  alcohol:             { name: 'Alcohol',             category: 'warehouse',    unit: 'liters',  basePrice: 8 },
  food:                { name: 'Food',                category: 'warehouse',    unit: 'tons',    basePrice: 15 },
  plastics:            { name: 'Plastics',            category: 'warehouse',    unit: 'tons',    basePrice: 22 },
  mechanical_components: { name: 'Mechanical Components', category: 'warehouse', unit: 'units',  basePrice: 45 },
  electrical_components: { name: 'Electrical Components', category: 'warehouse', unit: 'units',  basePrice: 55 },
  electronics:         { name: 'Electronics',         category: 'warehouse',    unit: 'units',   basePrice: 80 },
  explosives:          { name: 'Explosives',          category: 'warehouse',    unit: 'tons',    basePrice: 60 },
  weapons:             { name: 'Weapons',             category: 'warehouse',    unit: 'units',   basePrice: 150 },
  vehicle_parts:       { name: 'Vehicle Parts',       category: 'warehouse',    unit: 'units',   basePrice: 200 },
  nuclear_fuel:        { name: 'Nuclear Fuel',        category: 'warehouse',    unit: 'tons',    basePrice: 200 },
  chemicals:           { name: 'Chemicals',           category: 'warehouse',    unit: 'tons',    basePrice: 30 },

  // Liquid
  oil:                 { name: 'Oil',                 category: 'liquid',       unit: 'barrels', basePrice: 25 },
  bitumen:             { name: 'Bitumen',             category: 'liquid',       unit: 'barrels', basePrice: 20 },
  fuel:                { name: 'Fuel',                category: 'liquid',       unit: 'liters',  basePrice: 2.5 },
  liquid_fertilizer:   { name: 'Liquid Fertilizer',   category: 'liquid',       unit: 'liters',  basePrice: 1.8 },

  // Live / Special
  livestock:           { name: 'Livestock',           category: 'live',         unit: 'heads',   basePrice: 150 },
  meat:                { name: 'Meat',                category: 'live',         unit: 'tons',    basePrice: 12 },

  // Non-storable intermediates
  asphalt:             { name: 'Asphalt',             category: 'aggregate',    unit: 'tons',    basePrice: 8,  canStore: false },
  concrete:            { name: 'Concrete',            category: 'aggregate',    unit: 'tons',    basePrice: 10, canStore: false },
};

// ── Production recipes ───────────────────────────────────────────────────────
export interface RecipeIO {
  resource: string;
  qty: number;
}

export interface Recipe {
  inputs: RecipeIO[];
  outputs: RecipeIO[];
}

export const PRODUCTION_RECIPES: Record<string, Recipe> = {
  steel_mill: {
    inputs:  [{ resource: 'iron', qty: 1 }, { resource: 'coal', qty: 1 }],
    outputs: [{ resource: 'steel', qty: 2 }],
  },
  uranium_processing: {
    inputs:  [{ resource: 'uranium_ore', qty: 1 }],
    outputs: [{ resource: 'uranium_oxide', qty: 0.5 }],
  },
  nuclear_fuel_plant: {
    inputs:  [{ resource: 'uranium_oxide', qty: 1 }, { resource: 'chemicals', qty: 0.5 }],
    outputs: [{ resource: 'nuclear_fuel', qty: 0.2 }],
  },
  alcohol_distillery: {
    inputs:  [{ resource: 'crops', qty: 2 }],
    outputs: [{ resource: 'alcohol', qty: 1 }],
  },
  aluminium_factory: {
    inputs:  [{ resource: 'aluminium_oxide', qty: 1 }, { resource: 'chemicals', qty: 0.5 }],
    outputs: [{ resource: 'aluminium', qty: 1 }],
  },
  aluminium_oxide_factory: {
    inputs:  [{ resource: 'bauxite', qty: 2 }, { resource: 'chemicals', qty: 0.5 }],
    outputs: [{ resource: 'aluminium_oxide', qty: 1 }],
  },
  asphalt_plant: {
    inputs:  [{ resource: 'gravel', qty: 1 }, { resource: 'bitumen', qty: 1 }],
    outputs: [{ resource: 'asphalt', qty: 2 }],
  },
  oil_refinery: {
    inputs:  [{ resource: 'oil', qty: 4 }],
    outputs: [{ resource: 'fuel', qty: 3 }, { resource: 'bitumen', qty: 1 }],
  },
  sawmill: {
    inputs:  [{ resource: 'wood', qty: 1 }],
    outputs: [{ resource: 'boards', qty: 2 }],
  },
  brick_factory: {
    inputs:  [{ resource: 'coal', qty: 1 }],
    outputs: [{ resource: 'bricks', qty: 4 }],
  },
  chemical_factory: {
    inputs:  [
      { resource: 'wood', qty: 0.25 },
      { resource: 'gravel', qty: 0.25 },
      { resource: 'crops', qty: 0.25 },
      { resource: 'oil', qty: 0.25 },
    ],
    outputs: [{ resource: 'chemicals', qty: 1 }],
  },
  cement_factory: {
    inputs:  [{ resource: 'gravel', qty: 1 }, { resource: 'coal', qty: 1 }],
    outputs: [{ resource: 'cement', qty: 2 }],
  },
  concrete_plant: {
    inputs:  [{ resource: 'gravel', qty: 1 }, { resource: 'cement', qty: 1 }],
    outputs: [{ resource: 'concrete', qty: 2 }],
  },
  clothing_factory: {
    inputs:  [{ resource: 'fabrics', qty: 1 }],
    outputs: [{ resource: 'clothes', qty: 1 }],
  },
  fabric_factory: {
    inputs:  [{ resource: 'crops', qty: 1 }, { resource: 'chemicals', qty: 0.5 }],
    outputs: [{ resource: 'fabrics', qty: 1 }],
  },
  electronic_components_factory: {
    inputs:  [{ resource: 'plastics', qty: 1 }, { resource: 'chemicals', qty: 1 }],
    outputs: [{ resource: 'electrical_components', qty: 1 }],
  },
  mechanical_components_factory: {
    inputs:  [{ resource: 'steel', qty: 2 }],
    outputs: [{ resource: 'mechanical_components', qty: 1 }],
  },
  electronic_assembly: {
    inputs:  [
      { resource: 'plastics', qty: 1 },
      { resource: 'electrical_components', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
    ],
    outputs: [{ resource: 'electronics', qty: 1 }],
  },
  plastic_factory: {
    inputs:  [{ resource: 'oil', qty: 1 }, { resource: 'chemicals', qty: 1 }],
    outputs: [{ resource: 'plastics', qty: 1 }],
  },
  food_factory: {
    inputs:  [{ resource: 'crops', qty: 2 }],
    outputs: [{ resource: 'food', qty: 1 }],
  },
  livestock_farm: {
    inputs:  [{ resource: 'crops', qty: 3 }],
    outputs: [{ resource: 'livestock', qty: 1 }],
  },
  slaughterhouse: {
    inputs:  [{ resource: 'livestock', qty: 1 }],
    outputs: [{ resource: 'meat', qty: 2 }],
  },
  prefab_factory: {
    inputs:  [{ resource: 'cement', qty: 1 }, { resource: 'gravel', qty: 1 }],
    outputs: [{ resource: 'prefab_panels', qty: 2 }],
  },
  explosive_factory: {
    inputs:  [{ resource: 'chemicals', qty: 2 }, { resource: 'gravel', qty: 1 }],
    outputs: [{ resource: 'explosives', qty: 1 }],
  },
  weapons_factory: {
    inputs:  [
      { resource: 'explosives', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
      { resource: 'electronics', qty: 0.5 },
    ],
    outputs: [{ resource: 'weapons', qty: 1 }],
  },
  vehicle_factory: {
    inputs:  [
      { resource: 'plastics', qty: 1 },
      { resource: 'steel', qty: 2 },
      { resource: 'electrical_components', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
      { resource: 'electronics', qty: 1 },
      { resource: 'fabrics', qty: 1 },
    ],
    outputs: [{ resource: 'vehicle_parts', qty: 1 }],
  },
  rail_factory: {
    inputs:  [
      { resource: 'plastics', qty: 1 },
      { resource: 'steel', qty: 2 },
      { resource: 'electrical_components', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
      { resource: 'electronics', qty: 1 },
      { resource: 'fabrics', qty: 1 },
    ],
    outputs: [{ resource: 'vehicle_parts', qty: 1 }],
  },
  dry_dock: {
    inputs:  [
      { resource: 'plastics', qty: 1 },
      { resource: 'steel', qty: 2 },
      { resource: 'electrical_components', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
      { resource: 'electronics', qty: 1 },
      { resource: 'fabrics', qty: 1 },
    ],
    outputs: [{ resource: 'vehicle_parts', qty: 1 }],
  },
  airplane_factory: {
    inputs:  [
      { resource: 'plastics', qty: 1 },
      { resource: 'steel', qty: 2 },
      { resource: 'electrical_components', qty: 1 },
      { resource: 'mechanical_components', qty: 1 },
      { resource: 'electronics', qty: 1 },
      { resource: 'fabrics', qty: 1 },
      { resource: 'aluminium', qty: 2 },
    ],
    outputs: [{ resource: 'vehicle_parts', qty: 1 }],
  },
};

// ── Worker consumption (per worker per day = 1440 ticks) ─────────────────────
export const WORKER_CONSUMPTION = {
  food_kg_per_day: 1,          // 1 kg food
  meat_g_per_day: 100,         // 100 g meat
  clothing_g_per_day: 100,     // 100 g clothing
  alcohol_ml_per_day: 100,     // 100 ml alcohol
} as const;

// ── Education ────────────────────────────────────────────────────────────────
export const EDUCATION = {
  school_duration_ticks: 7_200,          // 5 days
  college_duration_ticks: 7_200,         // 5 days
  officer_school_duration_ticks: 7_200,  // 5 days
  school_teacher_ratio: 30,              // 1 teacher per 30 students
  college_professor_ratio: 10,           // 1 professor per 10 students
  school_speed_bonus_per_empty_seat: 0.03, // 3 % faster per student below 30
} as const;

// ── Health ───────────────────────────────────────────────────────────────────
export const HEALTH = {
  sick_rate: 0.005,                  // 0.5 % chance per tick-day
  treatment_deadline_ticks: 1_440,   // 24 hrs to get treatment
  healing_duration_ticks: 7_200,     // 5 days to heal
} as const;

// ── Starvation ───────────────────────────────────────────────────────────────
export const STARVATION = {
  death_after_days: 10,              // 10 days no food → death
  death_after_ticks: 14_400,         // 10 * 1440
  productivity_loss_per_day: 0.20,   // 20 % loss per day without food
} as const;

// ── Happiness modifiers ──────────────────────────────────────────────────────
export const HAPPINESS_MODIFIERS = {
  no_meat: -0.30,       // −30 %
  no_clothing: -0.10,   // −10 %
  no_alcohol: -0.10,    // −10 %
} as const;

// ── Housing ──────────────────────────────────────────────────────────────────
export const HOUSING = {
  leave_after_ticks: 43_200, // 30 days without house → worker leaves
} as const;

// ── Breakdowns ───────────────────────────────────────────────────────────────
export const BREAKDOWN = {
  clearance_ticks: 120, // 2 hours to clear
} as const;

// ── Containers ───────────────────────────────────────────────────────────────
export interface ContainerType {
  name: string;
  capacity_tons: number;
  load_manhours: number;
  unload_manhours: number;
}

export const CONTAINER_TYPES: Record<string, ContainerType> = {
  '20ft': { name: '20 ft Container', capacity_tons: 20,  load_manhours: 2, unload_manhours: 2 },
  '40ft': { name: '40 ft Container', capacity_tons: 40,  load_manhours: 4, unload_manhours: 4 },
};

// ── Building types ───────────────────────────────────────────────────────────
export interface BuildingTypeDef {
  name: string;
  category: 'production' | 'infrastructure' | 'military' | 'civic' | 'agriculture';
  maxWorkers: number;
  recipe?: string; // key into PRODUCTION_RECIPES
}

export const BUILDING_TYPES: Record<string, BuildingTypeDef> = {
  // Production
  steel_mill:                     { name: 'Steel Mill',                     category: 'production',      maxWorkers: 50,  recipe: 'steel_mill' },
  uranium_processing:             { name: 'Uranium Processing Plant',       category: 'production',      maxWorkers: 30,  recipe: 'uranium_processing' },
  nuclear_fuel_plant:             { name: 'Nuclear Fuel Plant',             category: 'production',      maxWorkers: 20,  recipe: 'nuclear_fuel_plant' },
  alcohol_distillery:             { name: 'Alcohol Distillery',             category: 'production',      maxWorkers: 20,  recipe: 'alcohol_distillery' },
  aluminium_factory:              { name: 'Aluminium Factory',              category: 'production',      maxWorkers: 40,  recipe: 'aluminium_factory' },
  aluminium_oxide_factory:        { name: 'Aluminium Oxide Factory',        category: 'production',      maxWorkers: 30,  recipe: 'aluminium_oxide_factory' },
  asphalt_plant:                  { name: 'Asphalt Plant',                  category: 'production',      maxWorkers: 25,  recipe: 'asphalt_plant' },
  oil_refinery:                   { name: 'Oil Refinery',                   category: 'production',      maxWorkers: 40,  recipe: 'oil_refinery' },
  sawmill:                        { name: 'Sawmill',                        category: 'production',      maxWorkers: 20,  recipe: 'sawmill' },
  brick_factory:                  { name: 'Brick Factory',                  category: 'production',      maxWorkers: 30,  recipe: 'brick_factory' },
  chemical_factory:               { name: 'Chemical Factory',               category: 'production',      maxWorkers: 35,  recipe: 'chemical_factory' },
  cement_factory:                 { name: 'Cement Factory',                 category: 'production',      maxWorkers: 30,  recipe: 'cement_factory' },
  concrete_plant:                 { name: 'Concrete Plant',                 category: 'production',      maxWorkers: 25,  recipe: 'concrete_plant' },
  clothing_factory:               { name: 'Clothing Factory',               category: 'production',      maxWorkers: 40,  recipe: 'clothing_factory' },
  fabric_factory:                 { name: 'Fabric Factory',                 category: 'production',      maxWorkers: 30,  recipe: 'fabric_factory' },
  electronic_components_factory:  { name: 'Electronic Components Factory',  category: 'production',      maxWorkers: 35,  recipe: 'electronic_components_factory' },
  mechanical_components_factory:  { name: 'Mechanical Components Factory',  category: 'production',      maxWorkers: 35,  recipe: 'mechanical_components_factory' },
  electronic_assembly:            { name: 'Electronic Assembly Plant',      category: 'production',      maxWorkers: 30,  recipe: 'electronic_assembly' },
  plastic_factory:                { name: 'Plastic Factory',                category: 'production',      maxWorkers: 25,  recipe: 'plastic_factory' },
  food_factory:                   { name: 'Food Factory',                   category: 'production',      maxWorkers: 40,  recipe: 'food_factory' },
  livestock_farm:                 { name: 'Livestock Farm',                 category: 'agriculture',     maxWorkers: 20,  recipe: 'livestock_farm' },
  slaughterhouse:                 { name: 'Slaughterhouse',                 category: 'production',      maxWorkers: 25,  recipe: 'slaughterhouse' },
  prefab_factory:                 { name: 'Prefab Panel Factory',           category: 'production',      maxWorkers: 30,  recipe: 'prefab_factory' },
  explosive_factory:              { name: 'Explosive Factory',              category: 'production',      maxWorkers: 20,  recipe: 'explosive_factory' },
  weapons_factory:                { name: 'Weapons Factory',                category: 'production',      maxWorkers: 30,  recipe: 'weapons_factory' },
  vehicle_factory:                { name: 'Vehicle Factory',                category: 'production',      maxWorkers: 60,  recipe: 'vehicle_factory' },
  rail_factory:                   { name: 'Rail Factory',                   category: 'production',      maxWorkers: 50,  recipe: 'rail_factory' },
  dry_dock:                       { name: 'Dry Dock',                       category: 'production',      maxWorkers: 60,  recipe: 'dry_dock' },
  airplane_factory:               { name: 'Airplane Factory',               category: 'production',      maxWorkers: 60,  recipe: 'airplane_factory' },

  // Infrastructure
  warehouse:          { name: 'Warehouse',          category: 'infrastructure', maxWorkers: 10 },
  power_plant:        { name: 'Power Plant',        category: 'infrastructure', maxWorkers: 15 },
  nuclear_power:      { name: 'Nuclear Power Plant',category: 'infrastructure', maxWorkers: 25 },
  road:               { name: 'Road',               category: 'infrastructure', maxWorkers: 0 },
  rail:               { name: 'Rail Line',          category: 'infrastructure', maxWorkers: 0 },
  customs_house:      { name: 'Customs House',      category: 'infrastructure', maxWorkers: 10 },
  workshop:           { name: 'Workshop',           category: 'infrastructure', maxWorkers: 15 },

  // Civic
  town_hall:          { name: 'Town Hall',          category: 'civic',          maxWorkers: 5 },
  school:             { name: 'School',             category: 'civic',          maxWorkers: 10 },
  college:            { name: 'College',            category: 'civic',          maxWorkers: 15 },
  officer_school:     { name: 'Officer School',     category: 'civic',          maxWorkers: 10 },
  hospital:           { name: 'Hospital',           category: 'civic',          maxWorkers: 30 },
  housing:            { name: 'Housing Block',      category: 'civic',          maxWorkers: 0 },

  // Military
  barracks:           { name: 'Barracks',           category: 'military',       maxWorkers: 5 },
  military_base:      { name: 'Military Base',      category: 'military',       maxWorkers: 20 },
  airfield:           { name: 'Airfield',           category: 'military',       maxWorkers: 15 },

  // Agriculture
  farm:               { name: 'Farm',               category: 'agriculture',    maxWorkers: 30 },
};

// ── Combat unit types (OGame-inspired) ───────────────────────────────────────
export interface UnitTypeDef {
  name: string;
  attack: number;
  defense: number;
  health: number;
  speed: number;
}

export const UNIT_TYPES: Record<string, UnitTypeDef> = {
  infantry:          { name: 'Infantry',          attack: 10,  defense: 15,  health: 100, speed: 4 },
  mechanized:        { name: 'Mechanized Inf.',   attack: 25,  defense: 30,  health: 200, speed: 8 },
  tank:              { name: 'Tank',              attack: 60,  defense: 50,  health: 500, speed: 6 },
  artillery:         { name: 'Artillery',         attack: 80,  defense: 10,  health: 150, speed: 3 },
  anti_air:          { name: 'Anti-Air',          attack: 40,  defense: 20,  health: 120, speed: 5 },
  helicopter:        { name: 'Helicopter',        attack: 50,  defense: 15,  health: 180, speed: 12 },
  fighter_jet:       { name: 'Fighter Jet',       attack: 100, defense: 25,  health: 250, speed: 20 },
  bomber:            { name: 'Bomber',            attack: 150, defense: 10,  health: 200, speed: 15 },
  special_forces:    { name: 'Special Forces',    attack: 35,  defense: 20,  health: 120, speed: 6 },
};

// ── Weather types ────────────────────────────────────────────────────────────
export const WEATHER_TYPES = [
  'clear', 'cloudy', 'rain', 'heavy_rain', 'snow',
  'blizzard', 'fog', 'storm', 'heatwave', 'frost',
] as const;

export type WeatherType = (typeof WEATHER_TYPES)[number];

export interface SeasonWeightMap {
  [weather: string]: number;
}

export const SEASON_WEATHER_WEIGHTS: Record<string, SeasonWeightMap> = {
  spring: { clear: 25, cloudy: 25, rain: 20, heavy_rain: 10, fog: 10, storm: 5, frost: 5, snow: 0, blizzard: 0, heatwave: 0 },
  summer: { clear: 35, cloudy: 20, rain: 10, heavy_rain: 5,  fog: 5,  storm: 10, heatwave: 15, snow: 0, blizzard: 0, frost: 0 },
  autumn: { clear: 20, cloudy: 25, rain: 20, heavy_rain: 10, fog: 15, storm: 5, frost: 5, snow: 0, blizzard: 0, heatwave: 0 },
  winter: { clear: 10, cloudy: 15, rain: 5,  heavy_rain: 0,  fog: 10, storm: 5, frost: 15, snow: 25, blizzard: 15, heatwave: 0 },
};

// ── Espionage ────────────────────────────────────────────────────────────────
export const ESPIONAGE = {
  roll_interval_ticks: 1_440,         // every 24 hours
  base_detection_chance: 0.20,        // 20 %
  training_reduction_per_level: 0.02, // −2 % per level
  outcome_detected: { escape: 0.50, captured: 0.30, executed: 0.20 },
} as const;

// ── Combat timing ────────────────────────────────────────────────────────────
export const COMBAT = {
  round_interval_ticks: 60, // 1 battle round per hour
} as const;
