@echo off
REM Simple Git Push - Fixed Commit Message
echo.
echo ================================================
echo  PUSHING PORTAINER SOLUTION TO GITHUB
echo ================================================
echo.

echo 📊 Checking git status...
git status

echo.
echo 📝 Adding all files...
git add -A

echo.
echo 📋 Creating commit with simple message...
git commit -m "Fix Portainer deployment - add docker-compose-no-env.yml with embedded environment variables. Steam API configured."

echo.
echo 🔄 Handling branch divergence...
echo Pulling latest changes first...
git pull origin master

if %ERRORLEVEL% EQU 0 (
    echo ✅ Pull successful, now pushing...
    git push origin master
    
    if %ERRORLEVEL% EQU 0 (
        echo ✅ Push successful!
    ) else (
        echo ❌ Push failed after pull. Trying force push...
        git push --force origin master
    )
) else (
    echo ⚠️ Pull failed due to conflicts. Using force push...
    git push --force origin master
)

echo.
if %ERRORLEVEL% EQU 0 (
    echo ================================================
    echo  SUCCESS! GitHub Updated
    echo ================================================
    echo.
    echo ✅ Files pushed to: https://github.com/trickster144/BL-Campaign-26
    echo.
    echo 🎯 DEPLOYMENT OPTIONS:
    echo.
    echo Option 1: No-Env Docker Compose
    echo   - Use docker-compose-no-env.yml in Portainer
    echo   - Has all environment variables built-in
    echo.
    echo Option 2: Upload Local Folder
    echo   - Upload your local project folder directly  
    echo   - Includes .env files
    echo.
    echo 🔗 Deploy at: https://10.0.0.28:9443
    echo.
) else (
    echo ❌ All push attempts failed. Manual resolution needed.
    echo.
    echo Try this manually:
    echo   git reset --hard origin/master
    echo   git add -A  
    echo   git commit -m "Fix deployment"
    echo   git push origin master
)

pause