@echo off
setlocal
chcp 65001 >nul
title Docker Develop - WSL2
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-panel-wsl.ps1" %*
set "RESULT=%ERRORLEVEL%"
pause
exit /b %RESULT%
