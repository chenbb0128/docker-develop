@echo off
chcp 65001 >nul
title 快速启动开发环境

echo ============================================
echo    🚀 快速启动开发环境
echo ============================================
echo.
echo 请选择要启动的环境:
echo.
echo   [1] PHP 7.3 环境 (Nginx + PHP 7.3 + Redis)
echo   [2] PHP 8.0 环境 (Nginx + PHP 8.0 + Redis)
echo   [3] PHP 8.1 环境 (Nginx + PHP 8.1 + Redis)
echo   [4] PHP 8.3 环境 (Nginx + PHP 8.3 + Redis)
echo   [5] 完整环境 (所有服务)
echo   [6] 仅启动面板
echo   [0] 退出
echo.
choice /c 1234560 /m "请选择"

cd /d "%~dp0"

set "COMPOSE_CMD=docker-compose"
where docker-compose >nul 2>&1
if errorlevel 1 (
    docker compose version >nul 2>&1
    if errorlevel 1 (
        echo Docker Compose 不可用，请先安装并启动 Docker Desktop。
        pause
        exit /b 1
    )
    set "COMPOSE_CMD=docker compose"
)

if not exist ".env" (
    if exist ".env.example" copy ".env.example" ".env" >nul
)
if not exist "data" mkdir "data"
if not exist "logs" mkdir "logs"
if not exist "logs\nginx" mkdir "logs\nginx"

if errorlevel 7 goto end
if errorlevel 6 goto panel
if errorlevel 5 goto full
if errorlevel 4 goto php83
if errorlevel 3 goto php81
if errorlevel 2 goto php80
if errorlevel 1 goto php73

:php73
echo 启动 PHP 7.3 环境...
%COMPOSE_CMD% up -d nginx php73-fpm redis docker-panel
goto done

:php80
echo 启动 PHP 8.0 环境...
%COMPOSE_CMD% up -d nginx php80-fpm redis docker-panel
goto done

:php81
echo 启动 PHP 8.1 环境...
%COMPOSE_CMD% up -d nginx php81-fpm redis docker-panel
goto done

:php83
echo 启动 PHP 8.3 环境...
%COMPOSE_CMD% up -d nginx php83-fpm redis docker-panel
goto done

:full
echo 启动完整环境...
%COMPOSE_CMD% up -d
goto done

:panel
echo 仅启动面板...
%COMPOSE_CMD% up -d docker-panel
goto done

:done
echo.
echo ============================================
echo ✅ 启动完成！
echo.
echo 🌐 面板地址: http://localhost:9501
echo ============================================
echo.
choice /c YN /m "是否打开浏览器"
if errorlevel 1 if not errorlevel 2 start http://localhost:9501

:end
echo.
pause
