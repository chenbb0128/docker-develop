# Docker 管理面板 - 创建桌面快捷方式
# 以管理员身份运行此脚本

$ScriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$DesktopPath = [Environment]::GetFolderPath("Desktop")

# 创建启动面板快捷方式
$WshShell = New-Object -ComObject WScript.Shell
$Shortcut = $WshShell.CreateShortcut("$DesktopPath\Docker 管理面板.lnk")
$Shortcut.TargetPath = "$ScriptPath\启动面板.bat"
$Shortcut.WorkingDirectory = $ScriptPath
$Shortcut.IconLocation = "imageres.dll,150"
$Shortcut.Description = "启动 Docker 管理面板"
$Shortcut.Save()

# 创建快速启动快捷方式
$Shortcut2 = $WshShell.CreateShortcut("$DesktopPath\Docker 快速启动.lnk")
$Shortcut2.TargetPath = "$ScriptPath\快速启动.bat"
$Shortcut2.WorkingDirectory = $ScriptPath
$Shortcut2.IconLocation = "imageres.dll,150"
$Shortcut2.Description = "快速启动 Docker 开发环境"
$Shortcut2.Save()

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  ✅ 桌面快捷方式创建成功！" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "已创建以下快捷方式:" -ForegroundColor Yellow
Write-Host "  📌 Docker 管理面板" -ForegroundColor White
Write-Host "  📌 Docker 快速启动" -ForegroundColor White
Write-Host ""

Read-Host "按 Enter 键退出"
