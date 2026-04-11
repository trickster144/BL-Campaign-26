# Black Legion Cold War Campaign — Copilot Instructions

## Commands

```bash
# Backend (from backend/)
npm run dev          # Start dev server with tsx watch (port 5000)
npm run build        # TypeScript compilation to dist/
npm start            # Run compiled JS from dist/

# Frontend (from frontend/)
npm run dev          # Vite dev server (port 3000, proxies /api → :5000)
npm run build        # tsc check + vite build
npm run lint         # ESLint for ts,tsx files

# Database
mysql -u ash_user_copilot -p campaign_data < database/schema.sql
mysql -u ash_user_copilot -p campaign_data < database/seed.sql
```

No test framework is configured yet.

## Architecture

This is a real-time browser strategy game where two teams (blue/red) compete on a hex map over months. A **tick engine** (1 tick/minute) drives all game simulation server-side. Clients receive updates via Socket.io and make actions via REST.

### Backend (Node.js + Express + Socket.io)

The backend is an ESM TypeScript project (`"type": "module"` in package.json, `module: "NodeNext"` in tsconfig).

**Tick engine** (`src/tickEngine.ts`) is the heart of the game. Every 60 seconds it runs 17 subsystems sequentially:
1. Production → Construction → Population (aging/births/deaths) → Education
2. Vehicle movement → Farm growth
3. Every 60 ticks (hourly): weather generation, combat resolution, wounded/hospital processing
4. Every 1440 ticks (daily): espionage rolls
5. Worker consumption → Happiness calculation → Market prices → Livestock → Spoilage → Breakdowns → Scheduled logistics
6. Socket.io emissions: `tick` (every tick), `weather` (hourly), `production` (when buildings produce)

Each subsystem is a **service file** in `src/` (e.g., `resourceService.ts`, `combatService.ts`). Services export named async functions that take `tick: number` and are called sequentially — order matters because later subsystems depend on earlier results.

**Database access** uses three helpers from `src/databaseConfig.ts`:
- `query<T>(sql, params)` — SELECT queries, returns typed `RowDataPacket[]`
- `execute(sql, params)` — INSERT/UPDATE/DELETE, returns `ResultSetHeader`
- `getConnection()` — Raw connection for transactions (`beginTransaction`, `commit`, `rollback`)

**Routes** mount at `/api/{domain}` (game, auth, resources, logistics, combat, map, admin). Each route file default-exports an Express router. Controllers are in `src/controllers/`.

**Auth chain** is middleware-based: `authenticateToken` (JWT verification) → optional `requireRole(['admin','gamemaster'])` → optional `requireTeam` (team assignment check). The `AuthenticatedRequest` interface in `src/middleware/auth.ts` extends Express `Request` with a `user` object containing `id`, `steam_id`, `username`, `team`, and `role`.

**Game constants** live in `src/gameConstants.ts` — all resource definitions, 31+ production recipes, worker consumption rates, education/health/starvation timers, combat unit stats, weather types, and espionage parameters. Always use these constants rather than hardcoding values.

### Frontend (React 18 + TypeScript + Vite)

**Routing** uses React Router v6 with nested routes. `BrowserRouter` wraps at `main.tsx` level. `App.tsx` defines:
- Public: `/login`, `/auth/callback`
- Protected (requires auth): all game pages wrapped in `<Layout>` which provides Navbar + Sidebar + `<Outlet />`
- Admin-only: `/admin` requires admin or gamemaster role

Two context providers:
- `AuthContext` — user state, Steam login flow, JWT from localStorage (`bl_token`), role helpers (`isAdmin`, `isMember`, `isGamemaster`)
- `GameContext` — game state + weather via REST on mount, then Socket.io for real-time updates (`tick_update`, `weather_change`, `battle_update`, `vehicle_move`)

**API layer** (`src/services/api.ts`) uses Axios with JWT interceptor. API methods are grouped as named exports by domain: `authAPI`, `gameAPI`, `resourceAPI`, `vehicleAPI`, `armyAPI`, etc. Auto-redirects to `/login` on 401.

**Socket layer** (`src/services/socket.ts`) is a singleton pattern — `connectSocket()` returns existing socket or creates new one with JWT auth. Event listeners follow `onEventName` / `offEventName` naming.

### Database (MySQL 8.0+)

55 tables in `campaign_data` database across 18 sections: auth, game state, hex map, population, resources, buildings, vehicles, logistics, agriculture, weather, combat, officers, espionage, market, power grid, governance, audit, happiness.

`seed.sql` extends the schema with ALTER TABLE statements and populates initial game world: 37 resources, 900 hex tiles, 26 locations (split between teams), 27 vehicle types, 55 building types, 47 production recipes.

## Key Conventions

### Backend imports require `.js` extensions
NodeNext ESM resolution means all relative imports must end in `.js`:
```typescript
import { query, execute } from './databaseConfig.js';
import { RESOURCES } from './gameConstants.js';
import { authenticateToken } from '../middleware/auth.js';
```

### Frontend imports omit extensions
Vite bundler handles resolution — no `.js` or `.ts` suffixes:
```typescript
import { useAuth } from './context/AuthContext';
import Layout from './components/layout/Layout';
```

### Export patterns
- **Services**: named exports only (`export async function processX`)
- **Routes**: default export (`export default router`)
- **Frontend API modules**: named object exports (`export const resourceAPI = { ... }`)
- **Context hooks**: named `useX()` with guard (`if (!ctx) throw new Error(...)`)

### Controller error handling
Every controller handler follows: try-catch → validate params (400) → call service → return JSON. Errors return `{ error: string }` and log to console. Never leak internals in error responses.

### Database naming
All snake_case. Primary keys are `id INT AUTO_INCREMENT`. Foreign keys use `{referenced_table}_id`. Timestamps use `created_at` / `updated_at` with MySQL defaults. ENUM values are lowercase strings.

### Team/role system
7 roles: `blue_admin`, `red_admin`, `blue_member`, `red_member`, `blue_observer`, `red_observer`, `gamemaster`. In the database, `team` and `role` are separate columns. Backend middleware checks them independently.

### CSS theming
Dark military theme with CSS custom properties in `App.css`: `--bg-primary: #1a1a2e`, `--blue-team: #1e90ff`, `--red-team: #dc3545`. Component classes use `bl-` prefix (e.g., `bl-card`, `bl-sidebar`, `bl-navbar`). Bootstrap 5 is used with dark overrides in `index.css`.

### Real-time communication pattern
REST for initial data loads and user actions. Socket.io for server-pushed updates. Server emits to rooms: `team:blue`, `team:red`, `admin`. Clients join rooms after authentication. Socket events: `tick`, `weather`, `production`, `battle_update`, `vehicle_move`, `chat_message`.

### Service files live in src/ root
Backend service files (e.g., `resourceService.ts`, `combatService.ts`) are in `src/` alongside `server.ts`, not in a `src/services/` subdirectory. Controllers import them with `../resourceService.js`. Keep new services in the same location for consistency.

### Environment variables
Backend reads from `backend/.env` — see `.env.example` for required vars. Frontend uses Vite env: `VITE_API_URL` and `VITE_SOCKET_URL` (defaults to `http://localhost:5000`).