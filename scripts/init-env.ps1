param(
    [switch] $Force,
    [switch] $NonInteractive,
    [string] $HostProjectPath = '',
    [string] $ContainerProjectPath = ''
)

$ErrorActionPreference = 'Stop'
$RootDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$EnvFile = Join-Path $RootDir '.env'
$ExampleFile = Join-Path $RootDir '.env.example'
$InitFlag = Join-Path $RootDir 'data\.docker-develop-initialized'
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Read-EnvMap {
    $map = @{}
    if ((Test-Path -LiteralPath $EnvFile) -eq $false) {
        return $map
    }

    foreach ($line in Get-Content -Encoding UTF8 -LiteralPath $EnvFile) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '') { continue }
        if ($trimmed.StartsWith('#')) { continue }
        if ($trimmed.Contains('=') -eq $false) { continue }

        $parts = $trimmed -split '=', 2
        $key = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        if ($key -ne '') { $map[$key] = $value }
    }
    return $map
}

function Write-EnvValue {
    param([string] $Key, [string] $Value)

    $lines = New-Object System.Collections.Generic.List[string]
    if (Test-Path -LiteralPath $EnvFile) {
        foreach ($line in [System.IO.File]::ReadAllLines($EnvFile, [System.Text.Encoding]::UTF8)) {
            $lines.Add($line)
        }
    }

    $found = $false
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) {
            $lines[$i] = "$Key=$Value"
            $found = $true
            break
        }
    }

    if ($found -eq $false) {
        if ($lines.Count -gt 0) {
            if ($lines[$lines.Count - 1].Trim() -ne '') { $lines.Add('') }
        }
        $lines.Add("$Key=$Value")
    }

    [System.IO.File]::WriteAllText($EnvFile, (($lines -join [Environment]::NewLine) + [Environment]::NewLine), $Utf8NoBom)
}

function Write-EnvDefault {
    param([hashtable] $Map, [string] $Key, [string] $Value, [bool] $Overwrite)

    $current = ''
    if ($Map.ContainsKey($Key)) { $current = [string] $Map[$Key] }
    if ($Overwrite -or [string]::IsNullOrWhiteSpace($current)) {
        Write-EnvValue $Key $Value
        $Map[$Key] = $Value
    }
}

function Ensure-LocalDirectory {
    param([string] $PathValue)
    if ([string]::IsNullOrWhiteSpace($PathValue)) { return }

    $target = $PathValue
    if (([System.IO.Path]::IsPathRooted($target) -eq $false) -and ($target -notmatch '^[A-Za-z]:[\\/]')) {
        $target = Join-Path $RootDir $target
    }
    if ((Test-Path -LiteralPath $target) -eq $false) {
        New-Item -ItemType Directory -Force -Path $target | Out-Null
    }
}

Set-Location -LiteralPath $RootDir

$created = $false
if ((Test-Path -LiteralPath $EnvFile) -eq $false) {
    if ((Test-Path -LiteralPath $ExampleFile) -eq $false) { throw 'Missing .env and .env.example.' }
    Copy-Item -LiteralPath $ExampleFile -Destination $EnvFile
    $created = $true
    Write-Host 'Created .env from .env.example.'
}

Ensure-LocalDirectory 'data'
Ensure-LocalDirectory 'logs'
Ensure-LocalDirectory 'logs\nginx'
Ensure-LocalDirectory 'services\nginx\ssl'

$envMap = Read-EnvMap
$overwrite = $false
if ($Force) { $overwrite = $true }
if ($created) { $overwrite = $true }

$hostValue = $HostProjectPath
if ([string]::IsNullOrWhiteSpace($hostValue)) {
    if ($envMap.ContainsKey('HOST_PROJECT_PATH')) { $hostValue = [string] $envMap['HOST_PROJECT_PATH'] }
}
if ([string]::IsNullOrWhiteSpace($hostValue)) { $hostValue = './data' }
if ([string]::IsNullOrWhiteSpace($HostProjectPath) -eq $false) { $overwrite = $true }

$containerValue = $ContainerProjectPath
if ([string]::IsNullOrWhiteSpace($containerValue)) {
    if ($envMap.ContainsKey('CONTAINER_PROJECT_PATH')) { $containerValue = [string] $envMap['CONTAINER_PROJECT_PATH'] }
}
if ([string]::IsNullOrWhiteSpace($containerValue)) { $containerValue = '/develop' }
if ([string]::IsNullOrWhiteSpace($ContainerProjectPath) -eq $false) { $overwrite = $true }

Write-EnvDefault $envMap 'HOST_PROJECT_PATH' $hostValue $overwrite
Write-EnvDefault $envMap 'CONTAINER_PROJECT_PATH' $containerValue $overwrite
Write-EnvDefault $envMap 'DATA_PATH' './data' $overwrite
Write-EnvDefault $envMap 'TIMEZONE' 'Asia/Shanghai' $overwrite
Write-EnvDefault $envMap 'CHANGE_SOURCE' 'true' $overwrite
Write-EnvDefault $envMap 'DOCKER_PANEL_PHP_IMAGE' 'php:8.3-cli-alpine' $overwrite
Write-EnvDefault $envMap 'DOCKER_PANEL_COMPOSER_IMAGE' 'composer:2' $overwrite
Write-EnvDefault $envMap 'PHP_VERSION' '8.3' $overwrite
Write-EnvDefault $envMap 'WORKSPACE_PHP_VERSION' '8.3' $overwrite
Write-EnvDefault $envMap 'NGINX_PHP_UPSTREAM_CONTAINER' 'php83-fpm' $overwrite
Write-EnvDefault $envMap 'NGINX_PHP_UPSTREAM_PORT' '9000' $overwrite
Write-EnvDefault $envMap 'WORKSPACE_COMPOSER_REPO_PACKAGIST' 'https://mirrors.aliyun.com/composer/' $overwrite
Write-EnvDefault $envMap 'GOPROXY' 'https://goproxy.cn,direct' $overwrite

$latest = Read-EnvMap
if ($latest['HOST_PROJECT_PATH'] -eq './data') { Ensure-LocalDirectory './data' }

$flagDir = Split-Path -Parent $InitFlag
if ((Test-Path -LiteralPath $flagDir) -eq $false) { New-Item -ItemType Directory -Force -Path $flagDir | Out-Null }
[System.IO.File]::WriteAllText($InitFlag, ((Get-Date).ToString('s') + [Environment]::NewLine), $Utf8NoBom)

Write-Host ''
Write-Host 'Local config is ready.' -ForegroundColor Green
Write-Host ('  .env: ' + $EnvFile)
Write-Host ('  HOST_PROJECT_PATH=' + $latest['HOST_PROJECT_PATH'])
Write-Host ('  CONTAINER_PROJECT_PATH=' + $latest['CONTAINER_PROJECT_PATH'])
Write-Host ('  PHP_VERSION=' + $latest['PHP_VERSION'])
Write-Host ('  Composer=' + $latest['WORKSPACE_COMPOSER_REPO_PACKAGIST'])
Write-Host ''