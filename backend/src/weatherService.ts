import { RowDataPacket } from 'mysql2/promise';
import { query, execute, getConnection } from './databaseConfig.js';
import {
  WEATHER_TYPES, WeatherType,
  SEASON_WEATHER_WEIGHTS,
  TICKS_PER_DAY,
} from './gameConstants.js';

// ── Types ────────────────────────────────────────────────────────────────────
export interface WeatherState {
  type: WeatherType;
  temperature: number;
  windSpeed: number;
  season: string;
  updatedAtTick: number;
}

let currentWeather: WeatherState = {
  type: 'clear',
  temperature: 15,
  windSpeed: 5,
  season: 'spring',
  updatedAtTick: 0,
};

// ── Season helpers ───────────────────────────────────────────────────────────

function tickToSeason(tick: number): string {
  // 1 in-game year ≈ 365 days = 525,600 ticks
  const dayOfYear = Math.floor(tick / TICKS_PER_DAY) % 365;
  if (dayOfYear < 91) return 'spring';
  if (dayOfYear < 182) return 'summer';
  if (dayOfYear < 273) return 'autumn';
  return 'winter';
}

function baseTemperature(season: string): number {
  switch (season) {
    case 'spring': return 12;
    case 'summer': return 25;
    case 'autumn': return 10;
    case 'winter': return -5;
    default: return 15;
  }
}

// ── Weighted random weather selection ────────────────────────────────────────

function weightedRandom(weights: Record<string, number>): string {
  const entries = Object.entries(weights);
  const total = entries.reduce((sum, [, w]) => sum + w, 0);
  let roll = Math.random() * total;
  for (const [key, weight] of entries) {
    roll -= weight;
    if (roll <= 0) return key;
  }
  return entries[entries.length - 1][0];
}

// ── Public API ───────────────────────────────────────────────────────────────

export async function generateWeather(tick: number): Promise<WeatherState> {
  const season = tickToSeason(tick);
  const weights = SEASON_WEATHER_WEIGHTS[season] ?? SEASON_WEATHER_WEIGHTS.spring;
  const weatherType = weightedRandom(weights) as WeatherType;

  const base = baseTemperature(season);
  const tempVariation = (Math.random() - 0.5) * 10;
  const temperature = Math.round((base + tempVariation) * 10) / 10;

  let windSpeed = Math.round(Math.random() * 20);
  if (weatherType === 'storm' || weatherType === 'blizzard') {
    windSpeed = 40 + Math.round(Math.random() * 40);
  }

  currentWeather = {
    type: weatherType,
    temperature,
    windSpeed,
    season,
    updatedAtTick: tick,
  };

  // Persist in game_state.weather_data
  await execute(
    'UPDATE game_state SET weather_data = ? WHERE id = 1',
    [JSON.stringify(currentWeather)],
  );

  // Also write a weather_events row for every location
  const locations = await query<(RowDataPacket & { id: number })[]>('SELECT id FROM locations');
  for (const loc of locations) {
    await execute(
      `INSERT INTO weather_events (location_id, weather_type, temperature, wind_speed, start_time, end_time, effects)
       VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)`,
      [
        loc.id,
        weatherType.includes('heavy_rain') ? 'rain' : (weatherType.includes('blizzard') ? 'storm' : (weatherType.includes('heatwave') || weatherType.includes('frost') ? 'storm' : (weatherType.includes('cloudy') ? 'clear' : weatherType))) as string,
        temperature,
        windSpeed,
        JSON.stringify({ crop: getCropModifier(currentWeather), travel: getTravelModifier(currentWeather), combat: getCombatModifier(currentWeather) }),
      ],
    );
  }

  return currentWeather;
}

export function getCurrentWeather(): WeatherState {
  return { ...currentWeather };
}

export function getLocationWeather(_hexQ: number, _hexR: number): WeatherState {
  // Global weather for now; future: per-hex micro-weather
  return { ...currentWeather };
}

export function getCropModifier(weather: WeatherState): number {
  switch (weather.type) {
    case 'clear':       return 1.1;
    case 'cloudy':      return 1.0;
    case 'rain':        return 1.2;
    case 'heavy_rain':  return 0.8;
    case 'heatwave':    return 0.6;
    case 'frost':       return 0.3;
    case 'snow':        return 0.0;
    case 'blizzard':    return 0.0;
    case 'storm':       return 0.5;
    case 'fog':         return 0.9;
    default:            return 1.0;
  }
}

export function getTravelModifier(weather: WeatherState): number {
  switch (weather.type) {
    case 'clear':       return 1.0;
    case 'cloudy':      return 1.0;
    case 'rain':        return 0.85;
    case 'heavy_rain':  return 0.6;
    case 'snow':        return 0.5;
    case 'blizzard':    return 0.2;
    case 'fog':         return 0.7;
    case 'storm':       return 0.4;
    case 'heatwave':    return 0.9;
    case 'frost':       return 0.7;
    default:            return 1.0;
  }
}

export function getCombatModifier(weather: WeatherState): number {
  switch (weather.type) {
    case 'clear':       return 1.0;
    case 'cloudy':      return 0.95;
    case 'rain':        return 0.85;
    case 'heavy_rain':  return 0.7;
    case 'snow':        return 0.75;
    case 'blizzard':    return 0.5;
    case 'fog':         return 0.6;
    case 'storm':       return 0.55;
    case 'heatwave':    return 0.85;
    case 'frost':       return 0.8;
    default:            return 1.0;
  }
}

export async function initWeatherFromDB(): Promise<void> {
  const rows = await query<(RowDataPacket & { weather_data: string | null })[]>(
    'SELECT weather_data FROM game_state WHERE id = 1',
  );
  if (rows.length > 0 && rows[0].weather_data) {
    try {
      currentWeather = typeof rows[0].weather_data === 'string'
        ? JSON.parse(rows[0].weather_data)
        : rows[0].weather_data;
    } catch { /* keep default */ }
  }
}
