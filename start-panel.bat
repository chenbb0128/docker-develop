@echo off
setlocal
chcp 65001 >nul
title Docker Panel

cd /d "%~dp0"

echo ============================================
echo Docker Panel startup
echo ============================================
echo.

set "COMPOSE_CMD=docker-compose"
where docker-compose >nul 2>&1
if errorlevel 1 (
    docker compose version >nul 2>&1
    if errorlevel 1 (
        echo Docker Compose is not available.
        echo Please install Docker Desktop with Docker Compose, then run this script again.
        echo.
        pause
        exit /b 1
    )
    set "COMPOSE_CMD=docker compose"
)

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
echo [2/4] Preparing local files...
if not exist ".env" (
    if exist ".env.example" (
        copy ".env.example" ".env" >nul
        echo Created .env from .env.example.
    )
)
if not exist "data" mkdir "data"
if not exist "logs" mkdir "logs"
if not exist "logs\nginx" mkdir "logs\nginx"

echo.
echo [3/4] Starting docker-panel service...
%COMPOSE_CMD% up -d docker-panel
if errorlevel 1 (
    echo.
    echo Failed to start docker-panel.
    echo You can run this command manually to see details:
    echo %COMPOSE_CMD% up -d docker-panel
    echo.
    pause
    exit /b 1
)

echo.
echo [4/4] Current service status:
%COMPOSE_CMD% ps docker-panel

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
