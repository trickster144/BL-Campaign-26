# GitHub Update Required - Exact File Fixes

## ⚠️ Your GitHub repo has the OLD files causing npm ci failure!

### 1. Update frontend/package.json

**Current (BROKEN) on GitHub:**
```json
{
  "scripts": {
    "setup:dirs": "node setup-dirs.cjs",
    "predev": "node setup-dirs.cjs",
    "dev": "vite",
    "prebuild": "node setup-dirs.cjs",    ← DELETE THIS LINE
    "build": "tsc && vite build",
```

**Fixed version (upload this):**
```json
{
  "scripts": {
    "setup:dirs": "node setup-dirs.cjs",
    "predev": "node setup-dirs.cjs", 
    "dev": "vite",
    "build": "tsc && vite build",         ← No prebuild line
```

### 2. Update backend/package.json

**Current (BROKEN) dependencies on GitHub:**
```json
"dependencies": {
  "bcrypt": "^5.1.0",                    ← CHANGE TO bcryptjs
```

**Fixed version:**
```json
"dependencies": {
  "bcryptjs": "^2.4.3",                  ← No native compilation
```

**Current (BROKEN) devDependencies on GitHub:**
```json
"devDependencies": {
  "@types/bcrypt": "^5.0.0",             ← CHANGE TO @types/bcryptjs
```

**Fixed version:**
```json
"devDependencies": {
  "@types/bcryptjs": "^2.4.2",           ← Correct types
```

## 🎯 The "production=false" Fix

You mentioned finding "production=false" - this might be in:
- package.json `"private": false` field (should be `true` for frontend)
- npm configuration causing issues with `--omit=dev`

## 📋 Quick Fix Actions:

### Option 1: Manual GitHub Edit
1. Go to https://github.com/trickster144/BL-Campaign-26/edit/master/frontend/package.json
2. Delete the line: `"prebuild": "node setup-dirs.cjs",`
3. Go to https://github.com/trickster144/BL-Campaign-26/edit/master/backend/package.json  
4. Change `bcrypt` → `bcryptjs` and `@types/bcrypt` → `@types/bcryptjs`

### Option 2: Force Push Local Changes
Your local files are already fixed. Run:
```bash
git add -A
git commit -m "Fix npm ci issues - remove prebuild, use bcryptjs"
git push --force origin master
```

## ✅ After GitHub Update:
Redeploy in Portainer - the npm ci exit code 1 error will be gone!