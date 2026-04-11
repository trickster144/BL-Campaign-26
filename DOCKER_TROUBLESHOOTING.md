# Docker Build Troubleshooting Guide

## Current Issue: npm ci --omit=dev failed

### Root Cause
The backend build is failing because of native dependencies (like `bcrypt`) that require compilation tools not available in Alpine Linux containers.

## Solutions (try in order):

### 1. 🔄 Use Updated Files (Recommended)
I've made these fixes:
- **Replaced `bcrypt` with `bcryptjs`** (no native compilation needed)
- **Simplified Dockerfile** to avoid multi-stage complexity
- **Fixed port exposure** to 5001

**Action**: Update your GitHub repo with the latest files and redeploy.

### 2. 🛠 Alternative: Use Debian-based Dockerfile
If Alpine continues to cause issues, replace `backend/Dockerfile` with `backend/Dockerfile.debian`:

```bash
# In your project root
cp backend/Dockerfile.debian backend/Dockerfile
```

### 3. 🔧 Regenerate package-lock.json
Sometimes the package-lock.json gets corrupted:

```bash
# Remove old lockfiles
rm backend/package-lock.json frontend/package-lock.json

# Regenerate (run in each directory)
cd backend && npm install
cd ../frontend && npm install
```

### 4. 🐳 Test Builds Locally
Before deploying to Portainer, test locally:

```bash
# Test backend build only
docker build -t test-backend ./backend

# Test full stack
docker-compose build
```

### 5. 🔍 Container Registry Alternative
If builds keep failing, try using pre-built images:

```bash
# Build and push to a registry, then reference in docker-compose
docker build -t your-registry/bl-backend ./backend
docker push your-registry/bl-backend
```

## Files Changed
✅ `backend/package.json` - Switched bcrypt → bcryptjs  
✅ `backend/Dockerfile` - Simplified, removed native build complexity  
✅ `backend/Dockerfile.debian` - Alternative Debian-based option  

## Quick Fix Checklist
- [ ] Updated package.json with bcryptjs
- [ ] Updated Dockerfile to expose port 5001  
- [ ] Regenerated package-lock.json
- [ ] Tested build locally (optional)
- [ ] Redeployed in Portainer

## Still Having Issues?
1. **Check Portainer logs** for specific error messages
2. **Try the Debian Dockerfile** (`Dockerfile.debian`)
3. **Use Node 18** instead of 20 (change FROM node:20-alpine to node:18-alpine)
4. **Contact support** with full error logs

The bcryptjs change should resolve the native compilation issue that's causing the npm ci failure.