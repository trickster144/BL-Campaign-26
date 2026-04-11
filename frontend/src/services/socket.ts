import { io, Socket } from 'socket.io-client';
import type { GameState, Vehicle, Battle, WeatherState, ChatMessage } from '../types';

const SOCKET_URL = import.meta.env.VITE_SOCKET_URL || 'http://localhost:5000';

let socket: Socket | null = null;

export function connectSocket(): Socket {
  if (socket?.connected) return socket;

  const token = localStorage.getItem('bl_token');

  socket = io(SOCKET_URL, {
    auth: { token },
    transports: ['websocket', 'polling'],
    reconnection: true,
    reconnectionDelay: 2000,
    reconnectionAttempts: 10,
  });

  socket.on('connect', () => {
    console.log('[Socket] Connected:', socket?.id);
  });

  socket.on('disconnect', (reason) => {
    console.log('[Socket] Disconnected:', reason);
  });

  socket.on('connect_error', (err) => {
    console.error('[Socket] Connection error:', err.message);
  });

  return socket;
}

export function disconnectSocket(): void {
  if (socket) {
    socket.disconnect();
    socket = null;
  }
}

export function getSocket(): Socket | null {
  return socket;
}

// ── Typed event listeners ────────────────────────────────────────────────────

export function onTickUpdate(callback: (state: GameState) => void): void {
  socket?.on('tick_update', callback);
}

export function onResourceChange(callback: (data: { location_id: number; resources: unknown[] }) => void): void {
  socket?.on('resource_change', callback);
}

export function onVehicleMove(callback: (vehicle: Vehicle) => void): void {
  socket?.on('vehicle_move', callback);
}

export function onBattleUpdate(callback: (battle: Battle) => void): void {
  socket?.on('battle_update', callback);
}

export function onWeatherChange(callback: (weather: WeatherState) => void): void {
  socket?.on('weather_change', callback);
}

export function onChatMessage(callback: (message: ChatMessage) => void): void {
  socket?.on('chat_message', callback);
}

export function offTickUpdate(callback: (state: GameState) => void): void {
  socket?.off('tick_update', callback);
}

export function offResourceChange(callback: (data: { location_id: number; resources: unknown[] }) => void): void {
  socket?.off('resource_change', callback);
}

export function offVehicleMove(callback: (vehicle: Vehicle) => void): void {
  socket?.off('vehicle_move', callback);
}

export function offBattleUpdate(callback: (battle: Battle) => void): void {
  socket?.off('battle_update', callback);
}

export function offWeatherChange(callback: (weather: WeatherState) => void): void {
  socket?.off('weather_change', callback);
}

export function offChatMessage(callback: (message: ChatMessage) => void): void {
  socket?.off('chat_message', callback);
}

// ── Emit helpers ─────────────────────────────────────────────────────────────

export function emitJoinTeam(team: string): void {
  socket?.emit('join_team', { team });
}

export function emitSendChat(message: string): void {
  socket?.emit('send_chat', { message });
}

export { socket };
