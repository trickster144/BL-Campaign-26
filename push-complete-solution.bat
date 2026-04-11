@echo off
REM Push Fixed Files + No-Env Docker Compose
echo.
echo ================================================
echo  PUSHING COMPLETE PORTAINER SOLUTION
echo ================================================
echo.

echo 📝 Adding all files including no-env docker-compose...
git add -A

echo.
echo 📋 Creating comprehensive commit...
git commit -m "Complete Portainer deployment fix

Frontend: Fixed package.json (removed prebuild script)
Backend: Fixed package.json (bcrypt → bcryptjs)
Docker: Added docker-compose-no-env.yml (no .env dependency)
Deployment: Multiple deployment options for Portainer

Fixes:
- npm ci build issues resolved
- Missing .env file issue resolved  
- Steam API configured: 31CF743A89D4659891C55ACCDE225FF2

Deploy options:
1. Use docker-compose-no-env.yml (no .env needed)
2. Upload project folder directly (includes .env)
3. Use environment variables in stack"

echo.
echo 🚀 Pushing to GitHub...
git push origin master

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ✅ SUCCESS! Complete solution pushed to GitHub.
    echo.
    echo 🎯 Deployment Options:
    echo.
    echo Option 1 - Use No-Env Docker Compose:
    echo   - Use docker-compose-no-env.yml in Portainer
    echo   - No .env files needed
    echo.
    echo Option 2 - Upload Local Folder:  
    echo   - Upload your local project folder directly
    echo   - Includes all .env files
    echo.
    echo Option 3 - Environment Variables:
    echo   - Set variables directly in Portainer stack
    echo   - See PORTAINER_ENV_FIX.md for details
    echo.
    echo 🔗 GitHub: https://github.com/trickster144/BL-Campaign-26
    echo 🔗 Portainer: https://10.0.0.28:9443/#!/3/docker/stacks/newstack
    echo.
) else (
    echo.
    echo ❌ Push failed. Check git status and try again.
    echo.
)

pause