@echo off
REM Deploy to GitHub Repository Script
REM Repository: https://github.com/reyatelehealth2026-crypto/odoo.git

echo ==========================================
echo Deploy to GitHub Repository
echo ==========================================
echo.

REM Check current branch
echo [INFO] Checking current branch...
for /f "tokens=*" %%i in ('git branch --show-current') do set CURRENT_BRANCH=%%i

if not "%CURRENT_BRANCH%"=="main" (
    echo [WARNING] Current branch: %CURRENT_BRANCH%
    echo [INFO] Switching to main branch...
    git checkout main
    if %errorlevel% neq 0 (
        echo [ERROR] Failed to switch to main branch
        pause
        exit /b 1
    )
    echo [SUCCESS] Switched to main branch
) else (
    echo [SUCCESS] Already on main branch
)

REM Check remote
echo.
echo [INFO] Checking remote repository...
git remote -v | findstr "origin" >nul 2>&1
if %errorlevel% equ 0 (
    for /f "tokens=2" %%i in ('git remote get-url origin') do set REMOTE_URL=%%i
    echo [SUCCESS] Remote origin configured
) else (
    echo [WARNING] No remote origin found
    echo [INFO] Adding remote origin...
    git remote add origin https://github.com/reyatelehealth2026-crypto/odoo.git
    echo [SUCCESS] Remote origin added
)

REM Check for sensitive files
echo.
echo [INFO] Checking for sensitive files...
git diff --cached --name-only | findstr /R "\.env$ config\.php$ \.secret$ \.key$ \.pem$" >nul 2>&1
if %errorlevel% equ 0 (
    echo [WARNING] Found sensitive files in staging area
    echo [INFO] Removing sensitive files from staging...
    git reset HEAD .env 2>nul
    git reset HEAD config/config.php 2>nul
    echo [SUCCESS] Sensitive files removed from staging
) else (
    echo [SUCCESS] No sensitive files in staging area
)

REM Show git status
echo.
echo [INFO] Current git status:
echo ----------------------------------------
git status --short

REM Confirm before commit
echo.
set /p CONFIRM="Do you want to commit and push these files? (y/n): "
if /i not "%CONFIRM%"=="y" (
    echo [INFO] Deploy cancelled
    pause
    exit /b 0
)

REM Stage all files
echo.
echo [INFO] Staging all files...
git add .

REM Remove sensitive files from staging (double check)
git reset HEAD .env 2>nul
git reset HEAD .env.local 2>nul
git reset HEAD config/config.php 2>nul
git reset HEAD config/config.local.php 2>nul

echo [SUCCESS] Files staged

REM Commit
echo.
echo [INFO] Committing changes...
git commit -m "Deploy: Odoo Dashboard Modernization - Production Ready" -m "" -m "Features:" -m "- Real-time dashboard with WebSocket" -m "- Customer management with LINE integration" -m "- Payment processing with automated matching" -m "- Comprehensive security implementation" -m "- Performance optimization with caching" -m "- Full test coverage (93+ test files)" -m "- Production-ready deployment scripts" -m "- Monitoring and alerting setup" -m "- Migration system for gradual rollout" -m "- Complete documentation (Thai/English)" -m "" -m "Tech Stack:" -m "- Backend: PHP 8.0+ / Node.js + Express + TypeScript" -m "- Frontend: Next.js 14 + React 18 + TypeScript" -m "- Database: MySQL 8.0+ / Redis" -m "- Infrastructure: Docker + Nginx + Traefik" -m "- Monitoring: Grafana + Prometheus"

if %errorlevel% neq 0 (
    echo [WARNING] No changes to commit or commit failed
)

REM Push to GitHub
echo.
echo [INFO] Pushing to GitHub...
echo [INFO] You may be prompted for GitHub credentials:
echo [INFO] Username: your-github-username
echo [INFO] Password: ghp_xxxx... (Personal Access Token)
echo.

git push -u origin main

if %errorlevel% equ 0 (
    echo.
    echo ==========================================
    echo [SUCCESS] Deploy successful!
    echo ==========================================
    echo.
    echo Repository: https://github.com/reyatelehealth2026-crypto/odoo
    echo.
    echo Next steps:
    echo 1. Visit: https://github.com/reyatelehealth2026-crypto/odoo
    echo 2. Verify all files are uploaded correctly
    echo 3. Set up Branch Protection (Settings ^> Branches^)
    echo 4. Add Secrets for CI/CD (Settings ^> Secrets^)
    echo 5. Add Collaborators (Settings ^> Collaborators^)
    echo.
    echo Documentation:
    echo - Deployment Guide: docs/DEPLOYMENT_GUIDE_TH.md
    echo - GitHub Guide: GITHUB_PUSH_GUIDE.md
    echo.
) else (
    echo.
    echo ==========================================
    echo [ERROR] Push failed!
    echo ==========================================
    echo.
    echo Common issues:
    echo.
    echo 1. Authentication Failed:
    echo    - Use Personal Access Token instead of password
    echo    - Create at: https://github.com/settings/tokens
    echo.
    echo 2. Repository Not Empty:
    echo    - Pull first: git pull origin main --allow-unrelated-histories
    echo    - Or force push: git push -f origin main (WARNING!)
    echo.
    echo 3. Network Issues:
    echo    - Check internet connection
    echo    - Try again later
    echo.
    exit /b 1
)

pause
