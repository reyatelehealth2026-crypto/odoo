@echo off
REM Push to GitHub Repository Script
REM Repository: https://github.com/reyatelehealth2026-crypto/odoo.git

echo ==========================================
echo Push to GitHub Repository
echo ==========================================
echo.

REM Check if git is initialized
if not exist ".git" (
    echo [WARNING] Git repository not initialized. Initializing...
    git init
    echo [SUCCESS] Git initialized
) else (
    echo [SUCCESS] Git repository already initialized
)

REM Check if README.md exists
if not exist "README.md" (
    echo [WARNING] Creating README.md...
    (
        echo # LINE Telepharmacy Platform - Odoo Dashboard Modernization
        echo.
        echo Modern, high-performance dashboard system for LINE Telepharmacy Platform with Odoo ERP integration.
        echo.
        echo ## Features
        echo.
        echo - **Real-time Dashboard**: Live updates via WebSocket
        echo - **Odoo Integration**: Seamless sync with Odoo ERP ^(orders, invoices, BDOs^)
        echo - **Customer Management**: Advanced search, profile management, LINE account linking
        echo - **Payment Processing**: Automated slip matching with AI-powered OCR
        echo - **Security**: JWT authentication, RBAC, audit logging, rate limiting
        echo - **Performance**: Redis caching, connection pooling, optimized queries
        echo - **Monitoring**: Grafana dashboards, Prometheus metrics, health checks
        echo.
        echo ## Quick Start
        echo.
        echo See [DEPLOYMENT_GUIDE_TH.md]^(docs/DEPLOYMENT_GUIDE_TH.md^) for comprehensive deployment guide.
        echo.
        echo ## License
        echo.
        echo Proprietary - RE-YA Telehealth 2026
    ) > README.md
    echo [SUCCESS] README.md created
)

REM Check git status
echo.
echo Checking git status...
git status

REM Stage and commit changes
echo.
echo [INFO] Staging all changes...
git add .

echo.
echo [INFO] Committing changes...
git commit -m "Initial commit: Odoo Dashboard Modernization - Production Ready"

REM Check if remote exists and remove it
git remote | findstr "origin" >nul 2>&1
if %errorlevel% equ 0 (
    echo.
    echo [WARNING] Remote 'origin' already exists. Removing...
    git remote remove origin
)

REM Add remote
echo.
echo [INFO] Adding remote repository...
git remote add origin https://github.com/reyatelehealth2026-crypto/odoo.git
echo [SUCCESS] Remote added

REM Rename branch to main
echo.
echo [INFO] Renaming branch to 'main'...
git branch -M main
echo [SUCCESS] Branch renamed to main

REM Push to GitHub
echo.
echo [INFO] Pushing to GitHub...
echo [INFO] You may be prompted for GitHub credentials
echo.

git push -u origin main

if %errorlevel% equ 0 (
    echo.
    echo ==========================================
    echo [SUCCESS] Successfully pushed to GitHub!
    echo ==========================================
    echo.
    echo Repository: https://github.com/reyatelehealth2026-crypto/odoo.git
    echo.
    echo Next steps:
    echo 1. Visit: https://github.com/reyatelehealth2026-crypto/odoo
    echo 2. Verify all files are uploaded correctly
    echo 3. Set up branch protection rules ^(Settings ^> Branches^)
    echo 4. Configure GitHub Actions for CI/CD ^(optional^)
    echo 5. Add collaborators ^(Settings ^> Collaborators^)
    echo.
) else (
    echo.
    echo ==========================================
    echo [ERROR] Push failed!
    echo ==========================================
    echo.
    echo Common issues:
    echo 1. Authentication failed - Use GitHub Personal Access Token
    echo 2. Repository not empty - Use 'git push -f origin main' to force push
    echo 3. Network issues - Check internet connection
    echo.
    echo To force push ^(WARNING: overwrites remote^):
    echo   git push -f origin main
    echo.
    exit /b 1
)

pause
