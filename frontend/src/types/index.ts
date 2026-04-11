// ============================================================================
// Black Legion Cold War Campaign - TypeScript Interfaces
// ============================================================================

export type TeamColor = 'blue' | 'red' | null;
export type UserRole = 'member' | 'officer' | 'admin' | 'gamemaster' | 'observer';
export type SeasonType = 'spring' | 'summer' | 'autumn' | 'winter';
export type WeatherType = 'clear' | 'cloudy' | 'rain' | 'storm' | 'snow' | 'fog' | 'blizzard';
export type LocationType = 'city' | 'town' | 'village' | 'outpost' | 'base' | 'port' | 'airfield';
export type VehicleType = 'truck' | 'train' | 'ship' | 'helicopter' | 'plane' | 'apc' | 'tank';
export type VehicleStatus = 'idle' | 'moving' | 'loading' | 'unloading' | 'repairing' | 'destroyed';
export type BattleStatus = 'pending' | 'in_progress' | 'completed' | 'retreat';
export type SpyStatus = 'idle' | 'deployed' | 'captured' | 'returning' | 'training';
export type BuildingType = 'factory' | 'barracks' | 'hospital' | 'warehouse' | 'farm' | 'refinery' | 'port' | 'airfield' | 'bunker' | 'radar';
export type ResourceCategory = 'raw' | 'processed' | 'military' | 'food' | 'fuel' | 'medical';
export type ContainerType = 'crate' | 'barrel' | 'pallet' | 'tanker' | 'refrigerated';
export type MarketTrend = 'up' | 'down' | 'stable';
export type CropType = 'wheat' | 'corn' | 'potatoes' | 'vegetables' | 'cotton' | 'tobacco';

export interface User {
  id: number;
  steam_id: string;
  username: string;
  avatar_url: string;
  team: TeamColor;
  role: UserRole;
}

export interface GameState {
  current_tick: number;
  game_started: boolean;
  start_date: string;
  season: SeasonType;
  weather: WeatherState;
}

export interface Location {
  id: number;
  name: string;
  type: LocationType;
  hex_q: number;
  hex_r: number;
  team: TeamColor;
  population: number;
  happiness: number;
  resources: Resource[];
}

export interface Resource {
  id: number;
  name: string;
  category: ResourceCategory;
  unit: string;
  quantity: number;
  max_capacity: number;
}

export interface Vehicle {
  id: number;
  vehicle_type: VehicleType;
  team: TeamColor;
  name: string;
  health: number;
  fuel_level: number;
  is_moving: boolean;
  status: VehicleStatus;
  location: string;
  destination: string | null;
  cargo: Container[];
}

export interface Container {
  id: number;
  type: ContainerType;
  health: number;
  contents: Resource[];
  location: string;
  vehicle_id: number | null;
}

export interface Army {
  id: number;
  name: string;
  team: TeamColor;
  general: string;
  location: string;
  hex_q: number;
  hex_r: number;
  strength: number;
  morale: number;
  units: ArmyUnit[];
  status: 'idle' | 'moving' | 'in_combat' | 'retreating';
}

export interface ArmyUnit {
  id: number;
  type: string;
  count: number;
  experience: number;
}

export interface Building {
  id: number;
  type: BuildingType;
  name: string;
  level: number;
  health: number;
  is_operational: boolean;
  workers_assigned: number;
  max_workers: number;
  location_id: number;
  production?: ProductionChain;
}

export interface ProductionChain {
  inputs: Resource[];
  outputs: Resource[];
  cycle_time: number;
}

export interface Battle {
  id: number;
  location: string;
  hex_q: number;
  hex_r: number;
  attacker: string;
  attacker_team: TeamColor;
  defender: string;
  defender_team: TeamColor;
  status: BattleStatus;
  casualties: BattleCasualties;
  started_at: string;
  ended_at: string | null;
}

export interface BattleCasualties {
  attacker_losses: number;
  defender_losses: number;
  civilian_losses: number;
}

export interface WeatherState {
  type: WeatherType;
  temperature: number;
  wind_speed: number;
  season: SeasonType;
  effects: WeatherEffects;
}

export interface WeatherEffects {
  crop_modifier: number;
  travel_modifier: number;
  combat_modifier: number;
}

export interface Spy {
  id: number;
  team: TeamColor;
  codename: string;
  training_level: number;
  status: SpyStatus;
  location: string | null;
  intel: IntelReport[];
}

export interface IntelReport {
  id: number;
  type: string;
  content: string;
  timestamp: string;
  reliability: number;
}

export interface MarketPrice {
  resource_id: number;
  name: string;
  category: ResourceCategory;
  price: number;
  trend: MarketTrend;
  volume_24h: number;
}

export interface MarketOrder {
  id: number;
  type: 'buy' | 'sell';
  resource_name: string;
  quantity: number;
  price_per_unit: number;
  total: number;
  status: 'pending' | 'completed' | 'cancelled';
  created_at: string;
}

export interface Field {
  id: number;
  location: string;
  location_id: number;
  crop_type: CropType | null;
  growth_progress: number;
  is_planted: boolean;
  is_fertilized: boolean;
  health: number;
  yield_estimate: number;
}

export interface ChatMessage {
  id: number;
  user: string;
  avatar_url: string;
  team: TeamColor;
  message: string;
  timestamp: string;
}

export interface Notification {
  id: number;
  type: 'info' | 'warning' | 'danger' | 'success';
  title: string;
  message: string;
  timestamp: string;
  read: boolean;
}

export interface TeamApplication {
  id: number;
  user_id: number;
  username: string;
  requested_team: TeamColor;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
}

export interface AuditLogEntry {
  id: number;
  user: string;
  action: string;
  details: string;
  timestamp: string;
}

export interface Route {
  id: number;
  name: string;
  vehicle_id: number;
  waypoints: RouteWaypoint[];
  is_scheduled: boolean;
  schedule_interval: number | null;
}

export interface RouteWaypoint {
  order: number;
  location_id: number;
  location_name: string;
  action: 'pickup' | 'dropoff' | 'wait';
  resource_name?: string;
  quantity?: number;
}

export interface Livestock {
  id: number;
  type: string;
  count: number;
  health: number;
  location_id: number;
}
