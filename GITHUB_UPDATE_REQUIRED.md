# CRITICAL DOCKER FIXES - Upload These Files to GitHub
# The GitHub repo has OLD files that cause the npm ci failure

## Files You Must Update on GitHub:

### 1. frontend/package.json (CRITICAL FIX)
**Problem**: GitHub has `"prebuild": "node setup-dirs.cjs"` which fails in Docker
**Solution**: Remove the prebuild line

Current (broken) frontend/package.json on GitHub:
```json
"scripts": {
  "setup:dirs": "node setup-dirs.cjs",
  "predev": "node setup-dirs.cjs", 
  "dev": "vite",
  "prebuild": "node setup-dirs.cjs",    ← REMOVE THIS LINE
  "build": "tsc && vite build",
```

**Fixed version** (use this):
```json
"scripts": {
  "setup:dirs": "node setup-dirs.cjs",
  "predev": "node setup-dirs.cjs",
  "dev": "vite",
  "build": "tsc && vite build",         ← No prebuild script
```

### 2. backend/package.json (CRITICAL FIX)  
**Problem**: GitHub has `bcrypt` which needs native compilation
**Solution**: Change to `bcryptjs`

Replace this in dependencies:
```json
"bcrypt": "^5.1.0",                    ← CHANGE THIS
```

With this:
```json  
"bcryptjs": "^2.4.3",                  ← TO THIS
```

And in devDependencies:
```json
"@types/bcrypt": "^5.0.0",             ← CHANGE THIS
```

With this:
```json
"@types/bcryptjs": "^2.4.2",           ← TO THIS  
```

### 3. backend/Dockerfile (PORT FIX)
**Problem**: GitHub exposes port 5000, but we use 5001

Change:
```dockerfile
EXPOSE 5000     ← CHANGE TO 5001
```

### 4. backend/.env (STEAM API CONFIGURED)
✅ Already has your Steam API key: `31CF743A89D4659891C55ACCDE225FF2`

## Quick Fix Action Plan:

1. **Upload these corrected files** to your GitHub repo
2. **Update Portainer** to redeploy from the updated GitHub repo
3. **The build should now succeed**

## Root Cause Summary:
- ❌ **Frontend**: `prebuild` script fails because `setup-dirs.cjs` not copied to Docker
- ❌ **Backend**: `bcrypt` requires C++ build tools not available in Alpine Linux
- ❌ **Ports**: Mismatched port configuration

## After Upload:
- ✅ **Frontend**: No prebuild script, clean build
- ✅ **Backend**: `bcryptjs` (pure JS, no compilation needed)  
- ✅ **Ports**: Consistent 5001 backend, 8080 frontend
- ✅ **Steam**: API key configured

**The npm ci failure will be resolved once you upload the fixed package.json files!**