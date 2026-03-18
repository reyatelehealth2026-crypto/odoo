@echo off
REM Setup script for Windows users
REM Initializes Docker environment for Odoo Dashboard

echo Setting up Odoo Dashboard Docker Environment...
echo.

REM Check if Docker is installed
docker --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Docker is not installed or not in PATH
    echo Please install Docker Desktop from https://www.docker.com/products/docker-desktop
    pause
    exit /b 1
)

REM Check if Docker Compose is available
docker-compose --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Docker Compose is not available
    echo Please ensure Docker Desktop is running
    pause
    exit /b 1
)

REM Create environment file for development
if not exist .env.dev (
    echo Creating .env.dev from example...
    copy .env.dev.example .env.dev
    echo.
    echo Please edit .env.dev with your configuration before starting services
    echo.
)

REM Create log directories
echo Creating log directories...
if not exist logs mkdir logs
if not exist logs\backend mkdir logs\backend
if not exist logs\websocket mkdir logs\websocket
if not exist logs\nginx mkdir logs\nginx

REM Create SSL directory for production
if not exist docker\nginx\ssl mkdir docker\nginx\ssl

echo.
echo Setup completed successfully!
echo.
echo Next steps:
echo 1. Edit .env.dev with your database passwords and configuration
echo 2. Run: make dev-start (or docker-compose -f docker-compose.dev.yml up)
echo 3. Access the application at http://localhost:8080
echo.
pause