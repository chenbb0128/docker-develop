param(
    [switch] $SkipPanelHttp
)

$ErrorActionPreference = 'Continue'
$RootDir = $PSScriptRoot
$script:FailCount = 0
$script:WarnCount = 0

function Write-Check {
    param(
        [ValidateSet('OK', 'WARN', 'FAIL', 'INFO')]
        [string] $Status,
        [string] $Title,
        [string] $Message = '',
        [string] $Suggestion = ''
    )

    $color = switch ($Status) {
        'OK' { 'Green' }
        'WARN' { 'Yellow' }
        'FAIL' { 'Red' }
        default { 'Cyan' }
    }

    if ($Status -eq 'FAIL') { $script:FailCount++ }
    if ($Status -eq 'WARN') { $script:WarnCount++ }

    Write-Host ("[{0}] {1}" -f $Status, $Title) -ForegroundColor $color
    if ($Message) { Write-Host "     $Message" }
    if ($Suggestion) { Write-Host "     建议：$Suggestion" -ForegroundColor DarkYellow }
}

function Read-DotEnv {
    param([string] $FilePath)

    $values = @{}
    if (-not (Test-Path -LiteralPath $FilePath)) {
        return $values
    }

    foreach ($line in Get-Content -Encoding UTF8 -LiteralPath $FilePath) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
            continue
        }

        $parts = $trimmed -split '=', 2
        $name = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        if ($name -ne '') {
            $values[$name] = $value
        }
    }

    return $values
}

function Resolve-LocalPath {
    param([string] $PathValue)

    if ([string]::IsNullOrWhiteSpace($PathValue)) {
        return ''
    }

    if ([System.IO.Path]::IsPathRooted($PathValue)) {
        return [System.IO.Path]::GetFullPath($PathValue)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $RootDir $PathValue))
}

function Test-CommandExists {
    param([string] $Name)
    return $null -ne (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Get-ComposeCommand {
    if (Test-CommandExists 'docker-compose') {
        return @('docker-compose')
    }

    if (Test-CommandExists 'docker') {
        $output = & docker compose version 2>&1
        if ($LASTEXITCODE -eq 0) {
            return @('docker', 'compose')
        }
    }

    return @()
}

function Invoke-Compose {
    param([string[]] $Arguments)

    if ($script:ComposeCommand.Count -eq 1) {
        $exe = $script:ComposeCommand[0]
        return & $exe @Arguments 2>&1
    }

    $exe = $script:ComposeCommand[0]
    $subCommand = $script:ComposeCommand[1]
    return & $exe $subCommand @Arguments 2>&1
}

Write-Host ''
Write-Host 'Docker Develop 环境诊断' -ForegroundColor Cyan
Write-Host ("项目目录：{0}" -f $RootDir)
Write-Host ''

Set-Location -LiteralPath $RootDir

if (Test-CommandExists 'docker') {
    Write-Check OK 'Docker CLI' '已找到 docker 命令。'
} else {
    Write-Check FAIL 'Docker CLI' '没有找到 docker 命令。' '安装并启动 Docker Desktop。'
}

$script:ComposeCommand = @(Get-ComposeCommand)
if ($script:ComposeCommand.Count -gt 0) {
    Write-Check OK 'Docker Compose' ("使用命令：{0}" -f ($script:ComposeCommand -join ' '))
} else {
    Write-Check FAIL 'Docker Compose' '没有找到 docker-compose 或 docker compose。' '安装新版 Docker Desktop，或启用 Docker Compose 插件。'
}

if (Test-CommandExists 'docker') {
    $dockerInfo = & docker info 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Check OK 'Docker Desktop' 'Docker 引擎正在运行。'
    } else {
        Write-Check FAIL 'Docker Desktop' ($dockerInfo -join "`n") '先启动 Docker Desktop，等状态变成 Running 后再重试。'
    }
}

$envFile = Join-Path $RootDir '.env'
if (Test-Path -LiteralPath $envFile) {
    Write-Check OK '.env 文件' '已找到本机配置。'
} else {
    Write-Check FAIL '.env 文件' '缺少 .env。' '复制 .env.example 为 .env，或重新运行 start-panel.bat。'
}

$envValues = Read-DotEnv $envFile
$hostProjectPath = if ($envValues.ContainsKey('HOST_PROJECT_PATH')) { $envValues['HOST_PROJECT_PATH'] } else { '' }
$containerProjectPath = if ($envValues.ContainsKey('CONTAINER_PROJECT_PATH')) { $envValues['CONTAINER_PROJECT_PATH'] } else { '' }

if ($hostProjectPath) {
    $hostFullPath = Resolve-LocalPath $hostProjectPath
    if (Test-Path -LiteralPath $hostFullPath) {
        Write-Check OK 'HOST_PROJECT_PATH' ("{0} -> {1}" -f $hostProjectPath, $hostFullPath)
    } else {
        Write-Check FAIL 'HOST_PROJECT_PATH' ("目录不存在：{0}" -f $hostFullPath) '把 .env 里的 HOST_PROJECT_PATH 改成业务项目共同父目录，或先创建该目录。'
    }
} else {
    Write-Check FAIL 'HOST_PROJECT_PATH' '.env 中没有配置 HOST_PROJECT_PATH。' '建议先使用 HOST_PROJECT_PATH=./data，接入真实项目时再改成 D:\Develop 或 E:\Work。'
}

if ($containerProjectPath -match '^/') {
    Write-Check OK 'CONTAINER_PROJECT_PATH' $containerProjectPath
} else {
    Write-Check FAIL 'CONTAINER_PROJECT_PATH' '容器路径必须以 / 开头。' '推荐使用 /develop。'
}

$changeSource = if ($envValues.ContainsKey('CHANGE_SOURCE')) { $envValues['CHANGE_SOURCE'] } else { '' }
if ($changeSource -eq 'true') {
    Write-Check OK '系统软件源' 'CHANGE_SOURCE=true，构建时会优先使用国内镜像。'
} else {
    Write-Check WARN '系统软件源' 'CHANGE_SOURCE 不是 true。' '国内网络建议设置 CHANGE_SOURCE=true。'
}

$composerRepo = if ($envValues.ContainsKey('WORKSPACE_COMPOSER_REPO_PACKAGIST')) { $envValues['WORKSPACE_COMPOSER_REPO_PACKAGIST'] } else { '' }
if ($composerRepo -match 'aliyun|npmmirror|packagist') {
    $composerMessage = if ($composerRepo) { $composerRepo } else { '已使用默认源' }
    Write-Check OK 'Composer 源' $composerMessage
} else {
    Write-Check WARN 'Composer 源' '没有检测到 WORKSPACE_COMPOSER_REPO_PACKAGIST。' '国内网络建议配置为 https://mirrors.aliyun.com/composer/。'
}

$goProxy = if ($envValues.ContainsKey('GOPROXY')) { $envValues['GOPROXY'] } else { '' }
if ($goProxy -match 'goproxy|direct') {
    Write-Check OK 'Go Proxy' $goProxy
} else {
    Write-Check WARN 'Go Proxy' '没有检测到 GOPROXY。' 'Go 项目建议设置 GOPROXY=https://goproxy.cn,direct。'
}

if ($script:ComposeCommand.Count -gt 0) {
    $configOutput = Invoke-Compose -Arguments @('config', '--quiet')
    if ($LASTEXITCODE -eq 0) {
        Write-Check OK 'docker-compose 配置' 'docker-compose config --quiet 通过。'
    } else {
        Write-Check FAIL 'docker-compose 配置' ($configOutput -join "`n") '优先修复 .env 或 docker-compose.yml 中的路径、端口和变量。'
    }

    $servicesOutput = Invoke-Compose -Arguments @('config', '--services')
    if ($LASTEXITCODE -eq 0) {
        $services = @($servicesOutput | Where-Object { $_ -and -not $_.ToString().StartsWith('time=') })
        $required = @('docker-panel', 'nginx', 'redis', 'php83-fpm')
        $missing = @($required | Where-Object { $services -notcontains $_ })
        if ($missing.Count -eq 0) {
            Write-Check OK '核心服务' ("已识别：{0}" -f ($required -join ', '))
        } else {
            Write-Check FAIL '核心服务' ("缺少：{0}" -f ($missing -join ', ')) '检查 docker-compose.yml 是否被误删。'
        }
    }
}

$panelPort = 9501
$listeners = @()
try {
    $listeners = @(Get-NetTCPConnection -LocalPort $panelPort -State Listen -ErrorAction SilentlyContinue)
} catch {
    $listeners = @()
}

if ($listeners.Count -gt 0) {
    Write-Check WARN '面板端口' "端口 $panelPort 已被占用。如果 docker-panel 已在运行，这是正常的。" '如果启动失败，先确认占用端口的进程是不是 docker-panel。'
} else {
    Write-Check OK '面板端口' "端口 $panelPort 当前未被占用。"
}

if (-not $SkipPanelHttp) {
    try {
        $response = Invoke-WebRequest -Uri 'http://localhost:9501' -UseBasicParsing -TimeoutSec 5
        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
            Write-Check OK '面板 HTTP' ("http://localhost:9501 返回 {0}" -f $response.StatusCode)
        } else {
            Write-Check WARN '面板 HTTP' ("返回状态码：{0}" -f $response.StatusCode) '如果刚启动容器，可以等几秒再刷新。'
        }
    } catch {
        Write-Check WARN '面板 HTTP' $_.Exception.Message '如果面板尚未启动，可以先运行 start-panel.bat。'
    }
}

Write-Host ''
if ($script:FailCount -gt 0) {
    Write-Host ("诊断完成：{0} 个失败，{1} 个警告。" -f $script:FailCount, $script:WarnCount) -ForegroundColor Red
    exit 1
}

if ($script:WarnCount -gt 0) {
    Write-Host ("诊断完成：0 个失败，{0} 个警告。" -f $script:WarnCount) -ForegroundColor Yellow
    exit 0
}

Write-Host '诊断完成：环境看起来可以启动。' -ForegroundColor Green
exit 0
