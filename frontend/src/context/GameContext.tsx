import { createContext, useContext, useState, useEffect, useCallback, ReactNode } from 'react';
import { gameAPI, weatherAPI } from '../services/api';
import {
  connectSocket,
  disconnectSocket,
  onTickUpdate,
  offTickUpdate,
  onWeatherChange,
  offWeatherChange,
  onBattleUpdate,
  offBattleUpdate,
  onVehicleMove,
  offVehicleMove,
} from '../services/socket';
import type { GameState, WeatherState, Battle, Vehicle } from '../types';
import { useAuth } from './AuthContext';

interface GameContextType {
  gameState: GameState | null;
  weather: WeatherState | null;
  isConnected: boolean;
  isLoading: boolean;
  recentBattles: Battle[];
  movedVehicles: Vehicle[];
  refreshGameState: () => Promise<void>;
}

const defaultWeather: WeatherState = {
  type: 'clear',
  temperature: 20,
  wind_speed: 10,
  season: 'summer',
  effects: { crop_modifier: 1, travel_modifier: 1, combat_modifier: 1 },
};

const GameContext = createContext<GameContextType>({
  gameState: null,
  weather: null,
  isConnected: false,
  isLoading: true,
  recentBattles: [],
  movedVehicles: [],
  refreshGameState: async () => {},
});

export function GameProvider({ children }: { children: ReactNode }) {
  const { isAuthenticated } = useAuth();
  const [gameState, setGameState] = useState<GameState | null>(null);
  const [weather, setWeather] = useState<WeatherState | null>(null);
  const [isConnected, setIsConnected] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [recentBattles, setRecentBattles] = useState<Battle[]>([]);
  const [movedVehicles, setMovedVehicles] = useState<Vehicle[]>([]);

  const refreshGameState = useCallback(async () => {
    try {
      const [stateRes, weatherRes] = await Promise.all([
        gameAPI.getState(),
        weatherAPI.getCurrent(),
      ]);
      setGameState(stateRes.data);
      setWeather(weatherRes.data);
    } catch {
      setWeather(defaultWeather);
    } finally {
      setIsLoading(false);
    }
  }, []);

  // Fetch initial data
  useEffect(() => {
    if (isAuthenticated) {
      refreshGameState();
    } else {
      setIsLoading(false);
    }
  }, [isAuthenticated, refreshGameState]);

  // Socket connection
  useEffect(() => {
    if (!isAuthenticated) return;

    const sock = connectSocket();

    sock.on('connect', () => setIsConnected(true));
    sock.on('disconnect', () => setIsConnected(false));

    const handleTick = (state: GameState) => setGameState(state);
    const handleWeather = (w: WeatherState) => setWeather(w);
    const handleBattle = (b: Battle) =>
      setRecentBattles((prev) => [b, ...prev].slice(0, 20));
    const handleVehicle = (v: Vehicle) =>
      setMovedVehicles((prev) => {
        const filtered = prev.filter((x) => x.id !== v.id);
        return [v, ...filtered].slice(0, 50);
      });

    onTickUpdate(handleTick);
    onWeatherChange(handleWeather);
    onBattleUpdate(handleBattle);
    onVehicleMove(handleVehicle);

    return () => {
      offTickUpdate(handleTick);
      offWeatherChange(handleWeather);
      offBattleUpdate(handleBattle);
      offVehicleMove(handleVehicle);
      disconnectSocket();
      setIsConnected(false);
    };
  }, [isAuthenticated]);

  return (
    <GameContext.Provider
      value={{
        gameState,
        weather,
        isConnected,
        isLoading,
        recentBattles,
        movedVehicles,
        refreshGameState,
      }}
    >
      {children}
    </GameContext.Provider>
  );
}

export function useGame(): GameContextType {
  const ctx = useContext(GameContext);
  if (!ctx) throw new Error('useGame must be used within GameProvider');
  return ctx;
}

export default GameContext;
