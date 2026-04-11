# Black Legion Cold War Campaign

A comprehensive real-time strategy game based on Workers & Resources: Soviet Republic, featuring two competing teams (Blue and Red) in a long-term campaign spanning several months.

## Overview

This project implements a browser-based game where players manage resources, logistics, armies, and infrastructure on a hexagonal map. The game features:

- **Real-time Simulation**: 1-minute tick system running 24/7
- **Resource Management**: Complex economy with mining, production, and global trade
- **Logistics System**: Vehicles, trains, ships, and air transport with wear & tear
- **Combat System**: Army management with hourly battles and casualties
- **Agriculture**: Farm management with weather effects
- **Espionage**: Spy system for intelligence gathering
- **Multi-user Roles**: Admins, members, observers, and gamemaster

## Tech Stack

### Frontend
- React 18 + TypeScript
- Vite (build tool)
- React Router (navigation)
- Socket.io Client (real-time updates)
- Leaflet (map visualization)
- Bootstrap + React Bootstrap (UI)

### Backend
- Node.js + Express
- TypeScript
- Socket.io (real-time communication)
- MySQL (database)
- Passport.js + Steam authentication
- JWT (session management)

### Database
- MySQL 5.7+ or MariaDB 10.2+ (compatible)
- Comprehensive schema with 55+ tables
- Real-time data updates

## Project Structure

```
black-legion-campaign/
├── frontend/                 # React frontend
│   ├── src/
│   │   ├── components/       # React components
│   │   ├── pages/           # Page components
│   │   ├── services/        # API services
│   │   ├── types/           # TypeScript types
│   │   └── utils/           # Utility functions
│   ├── package.json
│   └── vite.config.ts
├── backend/                  # Node.js backend
│   ├── src/
│   │   ├── controllers/     # Route controllers
│   │   ├── models/          # Database models
│   │   ├── services/        # Business logic
│   │   ├── middleware/      # Express middleware
│   │   ├── routes/          # API routes
│   │   └── utils/           # Utilities
│   ├── package.json
│   └── tsconfig.json
├── database/                 # Database files
│   ├── schema.sql           # Database schema
│   └── seed.sql             # Initial data
└── README.md
```

## Setup Instructions

### Prerequisites
- Node.js 18+
- MySQL 8.0+
- Steam API Key (for authentication)

### Database Setup
1. Create a MySQL database:
```sql
CREATE DATABASE campaign_data;
```

2. Run the schema:
```bash
mysql -u ash_user_copilot -p campaign_data < database/schema.sql
```

3. Seed initial data:
```bash
mysql -u ash_user_copilot -p campaign_data < database/seed.sql
```

### Backend Setup
1. Navigate to backend directory:
```bash
cd backend
```

2. Install dependencies:
```bash
npm install
```

3. Configure environment variables:
```bash
cp .env.example .env
# Edit .env with your database credentials and Steam API key
```

4. Build and start the server:
```bash
npm run build
npm start
```

### Frontend Setup
1. Navigate to frontend directory:
```bash
cd frontend
```

2. Install dependencies:
```bash
npm install
```

3. Start development server:
```bash
npm run dev
```

## Game Systems

### Resource System
- **Aggregates**: Gravel, coal, iron, bauxite, uranium ore, steel/aluminium scrap, construction waste
- **Open Storage**: Steel, prefab panels, bricks, wood, aluminium, uranium oxide, plastic waste
- **Dry Bulk**: Cement, aluminium oxide
- **Warehouse**: Crops, fabrics, clothes, alcohol, food, plastics, mechanical/electrical components, electronics, explosives
- **Liquid**: Oil, bitumen, fuel, liquid fertilizer
- **Live**: Livestock, meat, nuclear fuel

### Production Chain
1. **Raw Materials**: Coal, iron, gravel, wood, uranium ore, bauxite, oil
2. **Processing**: Steel mills, aluminium factories, chemical plants, sawmills
3. **Manufacturing**: Vehicle assembly, electronics, weapons production
4. **Agriculture**: Farms, livestock, food processing

### Logistics System
- **Vehicles**: Trucks, trains, ships, aircraft
- **Containers**: 20ft and 40ft variants with wear & tear
- **Routes**: Road, rail, sea, air transport
- **Scheduling**: Automated logistics based on thresholds

### Combat System
- **Armies**: Infantry, vehicles, artillery
- **Battles**: Hourly resolution with casualties
- **Experience**: Generals and units gain experience
- **Hospitals**: Treatment and healing system

### Weather System
- Random weather generation
- Effects on agriculture, travel times, combat

### Espionage
- Spy recruitment and training
- Intelligence gathering with risk of detection
- Counter-intelligence capabilities

## API Endpoints

### Authentication
- `POST /api/auth/steam` - Steam login
- `GET /api/auth/user` - Get current user
- `POST /api/auth/logout` - Logout

### Game State
- `GET /api/game/state` - Current game state
- `GET /api/game/tick` - Current tick information

### Resources
- `GET /api/resources` - List all resources
- `GET /api/locations/:id/resources` - Location resources
- `POST /api/market/buy` - Buy from global market
- `POST /api/market/sell` - Sell to global market

### Logistics
- `GET /api/vehicles` - List vehicles
- `POST /api/vehicles` - Create vehicle
- `POST /api/routes` - Create route
- `GET /api/containers` - List containers

### Combat
- `GET /api/armies` - List armies
- `POST /api/armies` - Create army
- `POST /api/armies/:id/move` - Move army
- `GET /api/battles` - Battle history

## Real-time Features

The application uses Socket.io for real-time updates:
- Game tick events
- Resource changes
- Vehicle movements
- Battle updates
- Weather changes

## User Roles

1. **Blue/Red Admin**: Manage team members, approve applications
2. **Blue/Red Member**: Full game access, can play actively
3. **Blue/Red Observer**: View-only access to team assets (fog of war)
4. **Gamemaster**: Full access to all systems, can modify game state

## Docker Deployment

### Quick Start
```bash
# Build and run both services
docker-compose up -d --build

# View logs
docker-compose logs -f
```

The frontend will be available at `http://localhost` (port 80) and the backend API at `http://localhost:5000`.

> **Note**: The MySQL database runs externally (not in Docker). Ensure `backend/.env` has the correct DB connection settings before building.

### Individual Services
```bash
# Build & run backend only
docker build -t bl-backend ./backend
docker run -d -p 5000:5000 --env-file backend/.env bl-backend

# Build & run frontend only
docker build -t bl-frontend ./frontend
docker run -d -p 80:80 bl-frontend
```

## Development

### Running in Development
```bash
# Backend
cd backend && npm run dev

# Frontend (new terminal)
cd frontend && npm run dev
```

### Building for Production
```bash
# Backend
cd backend && npm run build

# Frontend
cd frontend && npm run build
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

This project is proprietary software for the Black Legion gaming community.

## Contact

For questions or support, contact the development team through the Black Legion Discord server.