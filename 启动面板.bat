@echo off
chcp 65001 >nul
title Docker 管理面板

echo ============================================
echo    🐳 Docker 管理面板 快捷启动
echo ============================================
echo.

cd /d "%~dp0"

echo [1/3] 检查 Docker Desktop 是否运行...
docker info >nul 2>&1
if errorlevel 1 (
    echo ❌ Docker Desktop 未运行，正在启动...
    start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe"
    echo 等待 Docker Desktop 启动 (30秒)...
    timeout /t 30 /nobreak >nul
) else (
    echo ✅ Docker Desktop 已运行
)

echo.
echo [2/3] 启动 Docker Panel 容器...
docker-compose up -d docker-panel

echo.
echo [3/3] 等待服务就绪...
timeout /t 5 /nobreak >nul

echo.
echo ============================================
echo ✅ 启动完成！
echo.
echo 🌐 访问地址: http://localhost:9501
echo 📝 默认账号: admin
echo 🔑 默认密码: admin123
echo ============================================
echo.

choice /c YN /m "是否立即打开浏览器"
if errorlevel 2 goto end
if errorlevel 1 start http://localhost:9501

:end
echo.
echo 按任意键退出...
pause >nul
