import axios, { InternalAxiosRequestConfig, AxiosResponse } from 'axios';
import type {
  User, GameState, Location, Resource, Vehicle, Container,
  Army, Building, Battle, MarketPrice, MarketOrder, WeatherState,
  Spy, Field, ChatMessage, AuditLogEntry, Route, Livestock,
} from '../types';

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:5000/api';

const api = axios.create({
  baseURL: API_BASE,
  headers: { 'Content-Type': 'application/json' },
  timeout: 15000,
});

// Attach JWT token from localStorage
api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem('bl_token');
  if (token && config.headers) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Global error handler
api.interceptors.response.use(
  (response: AxiosResponse) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('bl_token');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

// ── Auth ─────────────────────────────────────────────────────────────────────
export const authAPI = {
  getSteamLoginUrl: () => api.get<{ url: string }>('/auth/steam'),
  getCurrentUser: () => api.get<User>('/auth/me'),
  logout: () => api.post('/auth/logout'),
  verifyToken: () => api.get<{ valid: boolean }>('/auth/verify'),
};

// ── Game State ───────────────────────────────────────────────────────────────
export const gameAPI = {
  getState: () => api.get<GameState>('/game/state'),
  startGame: () => api.post('/game/start'),
  pauseGame: () => api.post('/game/pause'),
  resetGame: () => api.post('/game/reset'),
  advanceTick: () => api.post('/game/tick'),
};

// ── Resources ────────────────────────────────────────────────────────────────
export const resourceAPI = {
  getAll: (locationId?: number) =>
    api.get<Resource[]>('/resources', { params: { location_id: locationId } }),
  getById: (id: number) => api.get<Resource>(`/resources/${id}`),
  transfer: (data: { from_location: number; to_location: number; resource_id: number; quantity: number }) =>
    api.post('/resources/transfer', data),
};

// ── Locations ────────────────────────────────────────────────────────────────
export const locationAPI = {
  getAll: () => api.get<Location[]>('/locations'),
  getById: (id: number) => api.get<Location>(`/locations/${id}`),
  getBuildings: (id: number) => api.get<Building[]>(`/locations/${id}/buildings`),
  getResources: (id: number) => api.get<Resource[]>(`/locations/${id}/resources`),
};

// ── Vehicles ─────────────────────────────────────────────────────────────────
export const vehicleAPI = {
  getAll: () => api.get<Vehicle[]>('/vehicles'),
  getById: (id: number) => api.get<Vehicle>(`/vehicles/${id}`),
  create: (data: Partial<Vehicle>) => api.post<Vehicle>('/vehicles', data),
  move: (id: number, destination: string) => api.post(`/vehicles/${id}/move`, { destination }),
  load: (id: number, containerId: number) => api.post(`/vehicles/${id}/load`, { container_id: containerId }),
  unload: (id: number, containerId: number) => api.post(`/vehicles/${id}/unload`, { container_id: containerId }),
  repair: (id: number) => api.post(`/vehicles/${id}/repair`),
  refuel: (id: number) => api.post(`/vehicles/${id}/refuel`),
  getContainers: (id: number) => api.get<Container[]>(`/vehicles/${id}/containers`),
};

// ── Armies ────────────────────────────────────────────────────────────────────
export const armyAPI = {
  getAll: () => api.get<Army[]>('/armies'),
  getById: (id: number) => api.get<Army>(`/armies/${id}`),
  create: (data: Partial<Army>) => api.post<Army>('/armies', data),
  move: (id: number, hex_q: number, hex_r: number) =>
    api.post(`/armies/${id}/move`, { hex_q, hex_r }),
  disband: (id: number) => api.post(`/armies/${id}/disband`),
  merge: (id: number, targetId: number) => api.post(`/armies/${id}/merge`, { target_id: targetId }),
};

// ── Battles ──────────────────────────────────────────────────────────────────
export const battleAPI = {
  getAll: () => api.get<Battle[]>('/battles'),
  getById: (id: number) => api.get<Battle>(`/battles/${id}`),
  getActive: () => api.get<Battle[]>('/battles/active'),
  retreat: (id: number) => api.post(`/battles/${id}/retreat`),
};

// ── Market ────────────────────────────────────────────────────────────────────
export const marketAPI = {
  getPrices: () => api.get<MarketPrice[]>('/market/prices'),
  buy: (data: { resource_id: number; quantity: number }) => api.post('/market/buy', data),
  sell: (data: { resource_id: number; quantity: number }) => api.post('/market/sell', data),
  getOrders: () => api.get<MarketOrder[]>('/market/orders'),
  cancelOrder: (id: number) => api.post(`/market/orders/${id}/cancel`),
  getVehicleMarket: () => api.get('/market/vehicles'),
  buyVehicle: (data: { vehicle_type: string }) => api.post('/market/vehicles/buy', data),
};

// ── Weather ──────────────────────────────────────────────────────────────────
export const weatherAPI = {
  getCurrent: () => api.get<WeatherState>('/weather'),
  getForecast: () => api.get<WeatherState[]>('/weather/forecast'),
};

// ── Spies / Espionage ────────────────────────────────────────────────────────
export const spyAPI = {
  getAll: () => api.get<Spy[]>('/spies'),
  getById: (id: number) => api.get<Spy>(`/spies/${id}`),
  deploy: (id: number, location: string) => api.post(`/spies/${id}/deploy`, { location }),
  recall: (id: number) => api.post(`/spies/${id}/recall`),
  train: (id: number) => api.post(`/spies/${id}/train`),
  recruit: (name: string) => api.post('/spies/recruit', { name }),
  getReports: () => api.get('/spies/reports'),
};

// ── Buildings ────────────────────────────────────────────────────────────────
export const buildingAPI = {
  getAll: (locationId?: number) =>
    api.get<Building[]>('/buildings', { params: { location_id: locationId } }),
  getById: (id: number) => api.get<Building>(`/buildings/${id}`),
  build: (data: { type: string; location_id: number }) => api.post<Building>('/buildings', data),
  upgrade: (id: number) => api.post(`/buildings/${id}/upgrade`),
  assignWorkers: (id: number, count: number) =>
    api.post(`/buildings/${id}/workers`, { count }),
  demolish: (id: number) => api.post(`/buildings/${id}/demolish`),
};

// ── Farms ────────────────────────────────────────────────────────────────────
export const farmAPI = {
  getFields: (locationId?: number) =>
    api.get<Field[]>('/farms/fields', { params: { location_id: locationId } }),
  createField: (data: { location_id: number }) => api.post<Field>('/farms/fields', data),
  plant: (fieldId: number, cropType: string) =>
    api.post(`/farms/fields/${fieldId}/plant`, { crop_type: cropType }),
  harvest: (fieldId: number) => api.post(`/farms/fields/${fieldId}/harvest`),
  fertilize: (fieldId: number) => api.post(`/farms/fields/${fieldId}/fertilize`),
  getLivestock: (locationId?: number) =>
    api.get<Livestock[]>('/farms/livestock', { params: { location_id: locationId } }),
};

// ── Chat ─────────────────────────────────────────────────────────────────────
export const chatAPI = {
  getMessages: (team?: string) =>
    api.get<ChatMessage[]>('/chat', { params: { team } }),
  sendMessage: (message: string) => api.post('/chat', { message }),
};

// ── Routes ───────────────────────────────────────────────────────────────────
export const routeAPI = {
  getAll: () => api.get<Route[]>('/routes'),
  getById: (id: number) => api.get<Route>(`/routes/${id}`),
  create: (data: Partial<Route>) => api.post<Route>('/routes', data),
  update: (id: number, data: Partial<Route>) => api.put<Route>(`/routes/${id}`, data),
  remove: (id: number) => api.delete(`/routes/${id}`),
  activate: (id: number) => api.post(`/routes/${id}/activate`),
};

// ── Admin ────────────────────────────────────────────────────────────────────
export const adminAPI = {
  getUsers: () => api.get<User[]>('/admin/users'),
  updateUserRole: (userId: number, role: string) =>
    api.put(`/admin/users/${userId}/role`, { role }),
  updateUserTeam: (userId: number, team: string) =>
    api.put(`/admin/users/${userId}/team`, { team }),
  getApplications: () => api.get('/admin/applications'),
  processApplication: (id: number, action: 'approve' | 'reject') =>
    api.post(`/admin/applications/${id}`, { action }),
  getAuditLog: (page?: number) =>
    api.get<AuditLogEntry[]>('/admin/audit', { params: { page } }),
  getRules: (type: 'team' | 'gamemaster') =>
    api.get<{ content: string }>(`/rules/${type}`),
  updateRules: (type: 'team' | 'gamemaster', content: string) =>
    api.put(`/rules/${type}`, { content }),
};

export default api;
