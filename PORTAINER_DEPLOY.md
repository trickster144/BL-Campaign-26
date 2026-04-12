# Portainer Deployment Guide

## Files You Need

✅ **Root .env** - Sets custom ports (already created)
✅ **backend/.env** - Backend configuration (already created)  
✅ **docker-compose.yml** - Orchestration config (updated for custom ports)

## Quick Setup

### 1. Configure Steam Authentication
- Follow `STEAM_SETUP.md` to get your Steam API key
- Edit `backend/.env` and replace `CONFIGURE_YOUR_STEAM_API_KEY_HERE`

### 2. Deploy on Portainer
- **Upload project folder** to your Portainer host
- **Create new stack** from docker-compose.yml
- **Environment file**: Point to the root `.env` file
- **Deploy**

### 3. Default Ports
- **Frontend**: http://localhost:10011
- **Backend**: http://localhost:10012

### 4. Verify Database Connection
The backend connects to your existing MySQL server:
- Host: 10.0.0.28:3306
- Database: campaign_data  
- User: ash_user_copilot

Make sure your MySQL server allows connections from Docker containers.

## Environment Variables Summary

### Root .env (for Portainer)
```
FRONTEND_PORT=10011
BACKEND_PORT=10012
COMPOSE_PROJECT_NAME=bl-campaign
```

### backend/.env (main configuration)
```
# Database (already configured)
DB_HOST=10.0.0.28
DB_PORT=3306
DB_NAME=campaign_data
DB_USER=ash_user_copilot
DB_PASSWORD=Ashleycampaignsql123

# Server ports (updated)
PORT=10012
FRONTEND_URL=http://localhost:10011

# Steam auth (CONFIGURE THIS!)
STEAM_API_KEY=CONFIGURE_YOUR_STEAM_API_KEY_HERE
STEAM_RETURN_URL=http://localhost:10012/api/auth/steam/callback

# Security
JWT_SECRET=bl-campaign-development-secret-change-for-production
```

## Post-Deployment Steps

1. **Test database connection**: Backend logs should show "✅ Database connection successful"
2. **Test frontend**: Visit http://localhost:10011
3. **Test Steam login**: Click "Sign in with Steam" (requires Steam API key configured)
4. **Create first admin**: First user to login can be manually promoted to gamemaster in database

## Troubleshooting

- **Port conflicts**: Adjust FRONTEND_PORT/BACKEND_PORT in root .env
- **Database connection**: Check if MySQL allows Docker network access
- **Steam login fails**: Verify Steam API key is configured correctly
- **Permission denied**: Ensure Portainer has access to your project directory
