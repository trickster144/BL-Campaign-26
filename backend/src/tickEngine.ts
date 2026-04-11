import { Server } from 'socket.io';
import { execute, query } from './databaseConfig.js';
import { TICK_INTERVAL_MS, TICKS_PER_DAY, COMBAT, ESPIONAGE } from './gameConstants.js';
import { processAllProduction } from './resourceService.js';
import { processAging, processBirths, processDeaths, processConsumption, calculateHappiness } from './populationService.js';
import { processEducation } from './educationService.js';
import { processMovement, processBreakdowns, processScheduledLogistics } from './logisticsService.js';
import { processCombat, processWounded, processHospitals } from './combatService.js';
import { generateWeather, initWeatherFromDB } from './weatherService.js';
import { processGrowth, processSpoilage, processLivestock } from './agricultureService.js';
import { processEspionageRolls } from './espionageService.js';
import { updatePrices } from './marketService.js';
import { processConstruction } from './buildingService.js';
import { RowDataPacket } from 'mysql2/promise';

let tickTimer: ReturnType<typeof setInterval> | null = null;
let io: Server | null = null;
let isProcessing = false;

async function runTick(): Promise<void> {
  if (isProcessing) {
    console.warn('⚠️  Tick skipped: previous tick still processing');
    return;
  }
  isProcessing = true;

  try {
    // 1. Increment tick
    await execute('UPDATE game_state SET current_tick = current_tick + 1, last_tick_time = NOW() WHERE id = 1');
    const tickRows = await query<(RowDataPacket & { current_tick: number })[]>(
      'SELECT current_tick FROM game_state WHERE id = 1',
    );
    const tick = Number(tickRows[0]?.current_tick ?? 0);

    // 2. Resource production for all operational buildings
    const produced = await processAllProduction(tick);

    // 3. Process construction progress
    await processConstruction(tick);

    // 4. Population (aging, births, deaths)
    await processAging(tick);
    await processBirths(tick);
    await processDeaths(tick);

    // 5. Education queues
    await processEducation(tick);

    // 6. Vehicle movements
    await processMovement(tick);

    // 7. Farm growth
    await processGrowth(tick);

    // 8. Weather changes (every 60 ticks = 1 hour)
    if (tick % 60 === 0) {
      await generateWeather(tick);
    }

    // 9. Combat rounds (every COMBAT.round_interval_ticks = 60 ticks)
    await processCombat(tick);
    await processWounded(tick);
    await processHospitals(tick);

    // 10. Espionage rolls (every 1440 ticks = 24 hours)
    await processEspionageRolls(tick);

    // 11. Worker consumption per location
    const locations = await query<(RowDataPacket & { id: number })[]>('SELECT id FROM locations');
    for (const loc of locations) {
      await processConsumption(loc.id, tick);
    }

    // 12. Update happiness per location
    for (const loc of locations) {
      await calculateHappiness(loc.id);
    }

    // 13. Market price adjustments
    await updatePrices(tick);

    // 14. Livestock processing
    await processLivestock(tick);

    // 15. Spoilage
    await processSpoilage(tick);

    // 16. Breakdown checks
    await processBreakdowns(tick);

    // 17. Scheduled logistics
    await processScheduledLogistics(tick);

    // 18. Emit socket.io events
    if (io) {
      io.emit('tick', { tick, timestamp: new Date().toISOString() });

      if (tick % 60 === 0) {
        const { getCurrentWeather } = await import('./weatherService.js');
        io.emit('weather', getCurrentWeather());
      }

      if (produced > 0) {
        io.emit('production', { tick, buildingsProduced: produced });
      }
    }

    if (tick % 60 === 0) {
      console.log(`⏱️  Tick ${tick} processed (hourly summary: ${produced} buildings produced)`);
    }
  } catch (error) {
    console.error('❌ Tick processing error:', error);
  } finally {
    isProcessing = false;
  }
}

export async function startTickEngine(socketIo: Server): Promise<void> {
  io = socketIo;

  // Initialize weather from DB
  await initWeatherFromDB();

  // Check if game is started
  const gs = await query<(RowDataPacket & { game_started: boolean })[]>(
    'SELECT game_started FROM game_state WHERE id = 1',
  );

  if (!gs[0]?.game_started) {
    console.log('🎮 Game not started yet. Tick engine on standby.');
    // Still set up the timer but skip ticks until game_started = true
  }

  tickTimer = setInterval(async () => {
    const state = await query<(RowDataPacket & { game_started: boolean })[]>(
      'SELECT game_started FROM game_state WHERE id = 1',
    );
    if (state[0]?.game_started) {
      await runTick();
    }
  }, TICK_INTERVAL_MS);

  console.log(`🚀 Tick engine started (interval: ${TICK_INTERVAL_MS}ms)`);
}

export function stopTickEngine(): void {
  if (tickTimer) {
    clearInterval(tickTimer);
    tickTimer = null;
    console.log('🛑 Tick engine stopped');
  }
}
