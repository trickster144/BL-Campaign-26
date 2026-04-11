# Manual Git Fix - Copy and Paste These Commands

## The batch file had syntax errors. Run these commands manually:

### Step 1: Check status
```bash
git status
```

### Step 2: Add all files  
```bash
git add -A
```

### Step 3: Commit with simple message
```bash
git commit -m "Fix deployment: add docker-compose-no-env.yml with embedded env vars"
```

### Step 4: Force push to resolve conflicts
```bash
git push --force origin master
```

## Alternative: Reset and sync
If the above doesn't work:

```bash
git fetch origin
git reset --hard origin/master
git add docker-compose-no-env.yml PORTAINER_ENV_FIX.md
git commit -m "Add no-env docker compose"
git push origin master
```

## Quick Deploy Test

After git push succeeds:

1. **Go to Portainer**: https://10.0.0.28:9443
2. **Use**: `docker-compose-no-env.yml` (not docker-compose.yml)  
3. **Deploy** - should work with no .env file errors

## Your Steam API Key
Already configured in docker-compose-no-env.yml:
`STEAM_API_KEY=31CF743A89D4659891C55ACCDE225FF2`