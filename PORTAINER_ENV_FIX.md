# CRITICAL: Missing .env File - Portainer Deployment Fix

## 🚨 Problem: 
Portainer can't find `/data/compose/42/backend/.env`

## 🔍 Root Cause:
The `.env` files are excluded by `.gitignore` (which is correct for security), so they're not in your GitHub repo. Portainer needs these files to set environment variables.

## ✅ Solutions:

### Option 1: Create .env File Directly in Portainer (Recommended)

1. **Go to Portainer**: https://10.0.0.28:9443/#!/3/docker/stacks/newstack
2. **Click "Upload"** instead of using Git repository
3. **Upload your local project folder** (which includes the .env files)

### Option 2: Use Environment Variables in Portainer

Instead of env_file, set the variables directly in docker-compose.yml:

```yaml
services:
  backend:
    build: ./backend
    container_name: bl-campaign-backend
    restart: unless-stopped
    ports:
      - "${BACKEND_PORT:-5001}:5001"
    environment:
      # Database
      - DB_HOST=10.0.0.28
      - DB_PORT=3306
      - DB_NAME=campaign_data
      - DB_USER=ash_user_copilot
      - DB_PASSWORD=Ashleycampaignsql123
      # Server
      - PORT=5001
      - NODE_ENV=production
      - FRONTEND_URL=http://localhost:8080
      # Steam Auth
      - STEAM_API_KEY=31CF743A89D4659891C55ACCDE225FF2
      - STEAM_RETURN_URL=http://localhost:5001/api/auth/steam/callback
      - STEAM_REALM=http://localhost:5001/
      # Security  
      - JWT_SECRET=bl-campaign-development-secret-change-for-production
      # Game
      - TICK_INTERVAL_MS=60000
      - GAME_START_DATE=2026-04-11T00:00:00Z
    networks:
      - bl-network
```

### Option 3: Create .env in Portainer Stack Editor

1. **Create Stack** in Portainer
2. **Use Web Editor** 
3. **Add the environment variables** in the stack configuration
4. **Deploy**

## 🔑 Your Backend .env Content:
```env
DB_HOST=10.0.0.28
DB_PORT=3306
DB_NAME=campaign_data
DB_USER=ash_user_copilot
DB_PASSWORD=Ashleycampaignsql123
PORT=5001
NODE_ENV=production
FRONTEND_URL=http://localhost:8080
STEAM_API_KEY=31CF743A89D4659891C55ACCDE225FF2
STEAM_RETURN_URL=http://localhost:5001/api/auth/steam/callback
STEAM_REALM=http://localhost:5001/
JWT_SECRET=bl-campaign-development-secret-change-for-production
TICK_INTERVAL_MS=60000
GAME_START_DATE=2026-04-11T00:00:00Z
```

## ⚡ Quickest Fix:
**Upload your local project folder directly** to Portainer instead of using GitHub - this includes the .env files!