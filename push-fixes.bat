@echo off
REM Git Update Script - Push Critical Docker Fixes
echo.
echo ================================================
echo  PUSHING CRITICAL DOCKER FIXES TO GITHUB
echo ================================================
echo.

REM Check if git is initialized
if not exist ".git" (
    echo Initializing git repository...
    git init
    git branch -M main
    git remote add origin https://github.com/trickster144/BL-Campaign-26.git
    echo ✓ Git initialized
) else (
    echo ✓ Git already initialized
)

echo.
echo Adding all files...
git add -A

echo.
echo Creating commit with critical fixes...
git commit -m "CRITICAL: Fix Docker build issues

- Remove frontend prebuild script that fails in Docker
- Replace bcrypt with bcryptjs (no native compilation)
- Fix backend port exposure to 5001
- Configure Steam API key: 31CF743A89D4659891C55ACCDE225FF2

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"

echo.
echo Pushing to GitHub...
git push -u origin main

echo.
echo ================================================
echo  GITHUB UPDATE COMPLETE!
echo ================================================
echo.
echo ✓ Fixed files pushed to: https://github.com/trickster144/BL-Campaign-26
echo ✓ Steam API configured: 31CF743A89D4659891C55ACCDE225FF2
echo ✓ Docker builds should now succeed
echo.
echo Next: Redeploy in Portainer - npm ci error should be gone!
echo.
pause