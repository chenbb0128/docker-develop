@echo off
setlocal
chcp 65001 >nul
title Docker Panel

cd /d "%~dp0"

echo ============================================
echo Docker Develop startup
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

echo [1/6] Checking Docker Desktop...
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
echo [2/6] Preparing local config...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\init-env.ps1"
if errorlevel 1 (
    echo.
    echo Failed to initialize local config.
    echo Please check .env.example and scripts\init-env.ps1.
    echo.
    pause
    exit /b 1
)

set "HOST_PROJECT_PATH="
set "CONTAINER_PROJECT_PATH="
for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b /c:"HOST_PROJECT_PATH=" .env 2^>nul`) do set "HOST_PROJECT_PATH=%%B"
for /f "usebackq tokens=1,* delims==" %%A in (`findstr /b /c:"CONTAINER_PROJECT_PATH=" .env 2^>nul`) do set "CONTAINER_PROJECT_PATH=%%B"
echo HOST_PROJECT_PATH=%HOST_PROJECT_PATH%
echo CONTAINER_PROJECT_PATH=%CONTAINER_PROJECT_PATH%

echo.
echo [3/6] Running environment doctor...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0doctor.ps1" -SkipPanelHttp
if errorlevel 1 (
    echo.
    echo Environment doctor found blocking issues. Please fix the items above, then run this script again.
    echo.
    pause
    exit /b 1
)

echo.
echo [4/6] Starting docker-panel service...
%COMPOSE_CMD% up -d docker-panel
if errorlevel 1 (
    echo.
    echo Failed to start docker-panel.
    echo Recent docker-panel logs:
    %COMPOSE_CMD% logs --tail=80 docker-panel
    echo.
    echo You can run this command manually to see details:
    echo %COMPOSE_CMD% up -d docker-panel
    echo.
    pause
    exit /b 1
)

echo.
echo [5/6] Waiting for panel HTTP...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$ok=$false; for($i=1;$i -le 20;$i++){ try { $r=Invoke-WebRequest -Uri 'http://localhost:9501' -UseBasicParsing -TimeoutSec 3; if($r.StatusCode -ge 200 -and $r.StatusCode -lt 500){ Write-Host ('Panel HTTP is ready: ' + $r.StatusCode); $ok=$true; break } } catch { Write-Host ('Waiting for panel... ' + $i + '/20'); Start-Sleep -Seconds 2 } }; if(-not $ok){ exit 1 }"
if errorlevel 1 (
    echo Panel did not respond in time. Recent docker-panel logs:
    %COMPOSE_CMD% logs --tail=120 docker-panel
    echo.
    echo The container may still be installing Composer dependencies. You can retry opening http://localhost:9501 in a moment.
) else (
    echo Panel HTTP is reachable.
)

echo.
echo [6/6] Current service status:
%COMPOSE_CMD% ps docker-panel

echo.
echo ============================================
echo Docker Develop is ready.
echo URL: http://localhost:9501
echo Auth: disabled for local development
echo Project root: %HOST_PROJECT_PATH%  -^>  %CONTAINER_PROJECT_PATH%
echo ============================================
echo.

start "" "http://localhost:9501"
pause