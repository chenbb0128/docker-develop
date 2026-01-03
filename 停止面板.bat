@echo off
chcp 65001 >nul
title 停止 Docker 面板

echo ============================================
echo    🛑 停止 Docker 管理面板
echo ============================================
echo.

cd /d "%~dp0"

echo 正在停止所有开发容器...
docker-compose down

echo.
echo ============================================
echo ✅ 所有容器已停止
echo ============================================
echo.
pause
