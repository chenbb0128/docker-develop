@echo off
setlocal
chcp 65001 >nul
title Docker Panel

cd /d "%~dp0"

echo ============================================
echo Docker Panel startup
echo ============================================
echo.

echo [1/3] Checking Docker Desktop...
docker info >nul 2>&1
if errorlevel 1 (
    echo Docker Desktop is not ready.
    echo Please start Docker Desktop first, wait until it is running, then run this script again.
    echo.
    pause
    exit /b 1
)
echo Docker Desktop is ready.

echo.
echo [2/3] Starting docker-panel service...
docker-compose up -d docker-panel
if errorlevel 1 (
    echo.
    echo Failed to start docker-panel.
    echo You can run this command manually to see details:
    echo docker-compose up -d docker-panel
    echo.
    pause
    exit /b 1
)

echo.
echo [3/3] Current service status:
docker-compose ps docker-panel

echo.
echo ============================================
echo Panel is starting.
echo URL: http://localhost:9501
echo Username: admin
echo Password: docker123
echo ============================================
echo.

start "" "http://localhost:9501"
pause
