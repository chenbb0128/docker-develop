@echo off
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scaffold.ps1" %*
if "%~1"=="" pause
