@echo off
REM Force Push Script - Override GitHub with Local Fixed Files
echo.
echo ================================================
echo  FORCE PUSHING FIXED FILES TO GITHUB
echo ================================================
echo.
echo ⚠️  This will OVERWRITE the broken files on GitHub
echo     with your local fixed versions.
echo.

set /p confirm="Continue? (y/N): "
if /i not "%confirm%"=="y" (
    echo Cancelled.
    pause
    exit /b
)

echo.
echo 🔧 Adding all local changes...
git add -A

echo.
echo 📝 Creating commit with fixes...
git commit -m "FORCE FIX: Replace broken GitHub files

Frontend: Remove prebuild script that breaks Docker
Backend: Replace bcrypt with bcryptjs (no native compilation)
Config: All TypeScript and Docker issues resolved

This fixes the npm ci --omit=dev exit code 1 error."

echo.
echo 🚀 Force pushing to GitHub (overwriting broken files)...
git push --force origin master

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ✅ SUCCESS! GitHub updated with fixed files.
    echo.
    echo 🎯 Next steps:
    echo 1. Go to Portainer: https://10.0.0.28:9443/#!/3/docker/stacks/newstack
    echo 2. Redeploy your stack
    echo 3. npm ci should now succeed!
    echo.
) else (
    echo.
    echo ❌ Push failed. Check git status and try again.
    echo.
)

pause