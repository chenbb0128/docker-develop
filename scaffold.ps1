param(
    [Parameter(Position = 0)]
    [string] $Type = '',

    [string] $Key = '',

    [string] $Name = '',

    [Alias('ProjectPath')]
    [string] $Path = '',

    [int] $Port = 0,

    [ValidateSet('php-fpm', 'php73-fpm', 'php80-fpm', 'php81-fpm', 'php83-fpm')]
    [string] $Php = 'php83-fpm',

    [string] $Services = '',
    [string] $Command = '',
    [string] $Log = '',
    [string] $Url = '',
    [string] $Root = '',
    [string] $ServerName = 'localhost',

    [switch] $NoSite,
    [switch] $NoComposePort,
    [switch] $Force,
    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'
$RootDir = $PSScriptRoot

function Read-DotEnv {
    param([string] $FilePath)

    $values = @{}
    if (-not (Test-Path $FilePath)) {
        return $values
    }

    foreach ($line in Get-Content -Encoding UTF8 $FilePath) {
        if ($line -match '^\s*#' -or $line -notmatch '=') {
            continue
        }

        $parts = $line -split '=', 2
        $name = $parts[0].Trim()
        $value = $parts[1].Trim().Trim('"').Trim("'")
        if ($name -ne '') {
            $values[$name] = $value
        }
    }

    return $values
}

function Write-Utf8NoBom {
    param(
        [string] $FilePath,
        [string] $Content
    )

    $encoding = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($FilePath, $Content, $encoding)
}

function Resolve-HostRoot {
    param([string] $HostPath)

    if ([string]::IsNullOrWhiteSpace($HostPath)) {
        $HostPath = '.'
    }

    if ([System.IO.Path]::IsPathRooted($HostPath)) {
        return [System.IO.Path]::GetFullPath($HostPath)
    }

    return [System.IO.Path]::GetFullPath((Join-Path $RootDir $HostPath))
}

function Join-ContainerPath {
    param(
        [string] $BasePath,
        [string] $ChildPath
    )

    $base = $BasePath.Replace('\', '/').TrimEnd('/')
    $child = $ChildPath.Replace('\', '/').TrimStart('/')
    if ($child -eq '') {
        return $base
    }

    return "$base/$child"
}

function Convert-ToContainerPath {
    param(
        [string] $InputPath,
        [string] $HostRoot,
        [string] $ContainerRoot
    )

    $raw = $InputPath.Trim().Trim('"').Trim("'")
    if ($raw -eq '') {
        throw "项目路径不能为空。"
    }

    $normalized = $raw.Replace('\', '/')
    if ($normalized.StartsWith('/')) {
        return '/' + $normalized.TrimStart('/')
    }

    $hostFull = Resolve-HostRoot $HostRoot
    if ([System.IO.Path]::IsPathRooted($raw)) {
        $inputFull = [System.IO.Path]::GetFullPath($raw)
    } else {
        $inputFull = [System.IO.Path]::GetFullPath((Join-Path $hostFull $raw))
    }

    $trimChars = [char[]] @('\', '/')
    $hostNorm = $hostFull.TrimEnd($trimChars)
    $inputNorm = $inputFull.TrimEnd($trimChars)
    $hostLower = $hostNorm.ToLowerInvariant()
    $inputLower = $inputNorm.ToLowerInvariant()
    $separator = [System.IO.Path]::DirectorySeparatorChar

    if ($inputLower -ne $hostLower -and -not $inputLower.StartsWith($hostLower + $separator)) {
        throw "项目路径 $InputPath 不在 HOST_PROJECT_PATH $hostFull 下面。请填写 /develop/project-name 这种容器路径，或修改 .env。"
    }

    $relative = $inputNorm.Substring($hostNorm.Length).TrimStart($trimChars)
    return Join-ContainerPath $ContainerRoot $relative
}

function Split-Services {
    param(
        [string] $Raw,
        [string[]] $DefaultServices
    )

    if ([string]::IsNullOrWhiteSpace($Raw)) {
        return $DefaultServices
    }

    return @($Raw -split '[,\s]+' | Where-Object { $_ -ne '' } | Select-Object -Unique)
}

function Get-HyperfServiceName {
    param([string] $ProjectKey)

    $name = $ProjectKey.ToLowerInvariant() -replace '[^a-z0-9_-]', '-'
    return "hyperf-$name"
}

function Convert-PhpServiceToVersion {
    param([string] $PhpService)

    switch ($PhpService) {
        'php83-fpm' { return '8.3' }
        'php81-fpm' { return '8.1' }
        'php80-fpm' { return '8.0' }
        'php73-fpm' { return '7.3' }
        default { return '8.3' }
    }
}

function Quote-YamlSingle {
    param([string] $Value)

    return "'" + $Value.Replace("'", "''") + "'"
}

function New-HyperfServiceConfig {
    param(
        [string] $ServiceName,
        [string] $ProjectPath,
        [int] $ListenPort,
        [string] $PhpVersion,
        [string] $StartCommand,
        [int] $ContainerPort = 9501
    )

    $quotedPath = Quote-YamlSingle $ProjectPath
    $quotedCommand = Quote-YamlSingle $StartCommand

    return @"

  ${ServiceName}:
    build:
      context: ./services/workspace
      args:
        - CHANGE_SOURCE=`${CHANGE_SOURCE}
        - UBUNTU_VERSION=`${UBUNTU_VERSION}
        - PHP_VERSION=$PhpVersion
        - INSTALL_XDEBUG=`${WORKSPACE_INSTALL_XDEBUG}
        - XDEBUG_PORT=`${WORKSPACE_XDEBUG_PORT}
        - INSTALL_MONGO=`${WORKSPACE_INSTALL_MONGO}
        - INSTALL_PHPREDIS=`${WORKSPACE_INSTALL_PHPREDIS}
        - INSTALL_SWOOLE=`${WORKSPACE_INSTALL_SWOOLE}
        - INSTALL_COMPOSER=`${WORKSPACE_INSTALL_COMPOSER}
        - COMPOSER_VERSION=`${WORKSPACE_COMPOSER_VERSION}
        - COMPOSER_REPO_PACKAGIST=`${WORKSPACE_COMPOSER_REPO_PACKAGIST}
        - INSTALL_NODE=`${WORKSPACE_INSTALL_NODE}
        - NODE_VERSION=`${WORKSPACE_NODE_VERSION}
        - INSTALL_SUPERVISOR=`${WORKSPACE_INSTALL_SUPERVISOR}
        - SHELL_OH_MY_ZSH=`${SHELL_OH_MY_ZSH}
        - PUID=`${WORKSPACE_PUID}
        - PGID=`${WORKSPACE_PGID}
        - TZ=`${TIMEZONE}
        - CONTAINER_PROJECT_PATH=`${CONTAINER_PROJECT_PATH}
    working_dir: $quotedPath
    command: sh -lc $quotedCommand
    volumes:
      - `${HOST_PROJECT_PATH}:`${CONTAINER_PROJECT_PATH}
    extra_hosts:
      - "dockerhost:`${DOCKER_HOST_IP}"
    ports:
      - "${ListenPort}:${ContainerPort}"
    depends_on:
      - redis
    tty: true
    networks:
      - frontend
      - backend
"@
}

function Ensure-HyperfComposeService {
    param(
        [string] $ComposeFile,
        [string] $ServiceName,
        [string] $ProjectPath,
        [int] $ListenPort,
        [string] $PhpVersion,
        [string] $StartCommand,
        [int] $ContainerPort = 9501
    )

    if (-not (Test-Path $ComposeFile)) {
        throw "找不到 docker-compose.yml。"
    }

    $content = Get-Content -Raw -Encoding UTF8 $ComposeFile
    $begin = "# BEGIN Hyperf project: $ServiceName"
    $end = "# END Hyperf project: $ServiceName"
    $serviceConfig = New-HyperfServiceConfig $ServiceName $ProjectPath $ListenPort $PhpVersion $StartCommand $ContainerPort
    $block = "$begin$serviceConfig$end"
    $pattern = "(?s)\r?\n?" + [regex]::Escape($begin) + ".*?" + [regex]::Escape($end)

    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, [Environment]::NewLine + $block)
    } else {
        $marker = "#---------------------------------------------`r`n# Docker 管理面板"
        $lfMarker = "#---------------------------------------------`n# Docker 管理面板"
        if ($content.Contains($marker)) {
            $content = $content.Replace($marker, $block + [Environment]::NewLine + [Environment]::NewLine + $marker)
        } elseif ($content.Contains($lfMarker)) {
            $content = $content.Replace($lfMarker, $block + [Environment]::NewLine + [Environment]::NewLine + $lfMarker)
        } else {
            throw "没有找到插入 Hyperf service 的位置。"
        }
    }

    Write-Utf8NoBom $ComposeFile ($content.TrimEnd() + [Environment]::NewLine)
}

function Load-Projects {
    param([string] $FilePath)

    if (-not (Test-Path $FilePath)) {
        return @()
    }

    $raw = Get-Content -Raw -Encoding UTF8 $FilePath
    if ([string]::IsNullOrWhiteSpace($raw)) {
        return @()
    }

    $payload = $raw | ConvertFrom-Json
    if ($null -eq $payload.projects) {
        return @()
    }

    return @($payload.projects)
}

function Save-Projects {
    param(
        [string] $FilePath,
        [object[]] $Projects
    )

    $payload = [ordered] @{
        projects = @($Projects)
    }
    $json = $payload | ConvertTo-Json -Depth 10
    Write-Utf8NoBom $FilePath ($json + [Environment]::NewLine)
}

function New-SiteConfig {
    param(
        [string] $SiteType,
        [string] $ListenPort,
        [string] $SiteServerName,
        [string] $SiteRoot,
        [string] $PhpService
    )

    $logName = ($SiteServerName -replace '[\.\s]', '_')
    if ($ListenPort -ne '80') {
        $logName = "${logName}_${ListenPort}"
    }

    if ($SiteType -eq 'static') {
        $template = @'
server {
    listen __PORT__;
    listen [::]:__PORT__;

    server_name __SERVER_NAME__;
    root __ROOT__;
    index index.html index.htm;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ /\.ht {
        deny all;
    }

    error_log /var/log/nginx/__LOG_NAME___error.log;
    access_log /var/log/nginx/__LOG_NAME___access.log;
}
'@
    } else {
        $template = @'
server {
    listen __PORT__;
    listen [::]:__PORT__;

    server_name __SERVER_NAME__;
    root __ROOT__;
    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri /index.php =404;
        fastcgi_pass __PHP_SERVICE__:9000;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 600;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }

    error_log /var/log/nginx/__LOG_NAME___error.log;
    access_log /var/log/nginx/__LOG_NAME___access.log;
}
'@
    }

    $template = $template.Replace('__PORT__', $ListenPort)
    $template = $template.Replace('__SERVER_NAME__', $SiteServerName)
    $template = $template.Replace('__ROOT__', $SiteRoot)
    $template = $template.Replace('__PHP_SERVICE__', $PhpService)
    $template = $template.Replace('__LOG_NAME__', $logName)
    return $template
}

function Ensure-NginxPort {
    param(
        [string] $ComposeFile,
        [int] $ListenPort
    )

    if ($ListenPort -le 0 -or -not (Test-Path $ComposeFile)) {
        return $false
    }

    $content = Get-Content -Encoding UTF8 $ComposeFile
    $portLine = "      - ""${ListenPort}:${ListenPort}"""
    if ($content -match [regex]::Escape("""${ListenPort}:${ListenPort}""")) {
        return $false
    }

    $insertAfter = -1
    for ($i = 0; $i -lt $content.Count; $i++) {
        if ($content[$i] -match '^\s*-\s*"8002:8002"\s*$') {
            $insertAfter = $i
        } elseif ($insertAfter -lt 0 -and $content[$i] -match '^\s*-\s*"7002:7002"\s*$') {
            $insertAfter = $i
        } elseif ($insertAfter -lt 0 -and $content[$i] -match '^\s*-\s*"\$\{NGINX_HOST_HTTPS_PORT\}:443"\s*$') {
            $insertAfter = $i
        }
    }

    if ($insertAfter -lt 0) {
        throw "没有在 docker-compose.yml 中找到 nginx 的 ports 配置块。请手动添加 ${ListenPort}:${ListenPort}。"
    }

    $newContent = @()
    if ($insertAfter -gt 0) {
        $newContent += $content[0..$insertAfter]
    } else {
        $newContent += $content[0]
    }
    $newContent += $portLine
    if ($insertAfter + 1 -lt $content.Count) {
        $newContent += $content[($insertAfter + 1)..($content.Count - 1)]
    }

    Write-Utf8NoBom $ComposeFile (($newContent -join [Environment]::NewLine) + [Environment]::NewLine)
    return $true
}

function Read-Required {
    param([string] $Prompt)

    do {
        $value = Read-Host $Prompt
        $value = $value.Trim()
    } while ($value -eq '')

    return $value
}

function Read-WithDefault {
    param(
        [string] $Prompt,
        [string] $DefaultValue
    )

    $value = Read-Host "$Prompt [$DefaultValue]"
    if ([string]::IsNullOrWhiteSpace($value)) {
        return $DefaultValue
    }

    return $value.Trim()
}

function Start-InteractiveWizard {
    Write-Host ""
    Write-Host "Docker Develop 新增网站向导"
    Write-Host "============================================"
    Write-Host ""
    Write-Host "请选择项目类型："
    Write-Host "  1. Laravel / 普通 PHP 网站"
    Write-Host "  2. Hyperf 项目"
    Write-Host "  3. 静态站点"
    Write-Host ""

    do {
        $choice = Read-Host "请输入 1 / 2 / 3"
    } while (-not (@("1", "2", "3") -contains $choice))

    if ($choice -eq "1") {
        $script:Type = "laravel"
    } elseif ($choice -eq "2") {
        $script:Type = "hyperf"
    } else {
        $script:Type = "static"
    }

    $script:Key = Read-Required "项目标识，例如 youquangou"
    $script:Name = Read-WithDefault "项目名称" $script:Key
    $script:Path = Read-Required "项目路径，可以填写 Windows 路径或 /develop 路径"

    if ($script:Type -eq "hyperf") {
        $script:Port = [int] (Read-WithDefault "访问端口" "9502")
        Write-Host ""
        Write-Host "请选择 Hyperf 使用的 PHP 版本："
        Write-Host "  1. PHP 8.3"
        Write-Host "  2. PHP 8.1"
        Write-Host "  3. PHP 8.0"
        Write-Host "  4. PHP 7.3"
        do {
            $phpChoice = Read-Host "请输入 1 / 2 / 3 / 4"
        } while (-not (@("1", "2", "3", "4") -contains $phpChoice))

        if ($phpChoice -eq "1") {
            $script:Php = "php83-fpm"
        } elseif ($phpChoice -eq "2") {
            $script:Php = "php81-fpm"
        } elseif ($phpChoice -eq "3") {
            $script:Php = "php80-fpm"
        } else {
            $script:Php = "php73-fpm"
        }

        $script:Services = Read-WithDefault "依赖服务，默认会使用项目专属容器和 redis" "redis"
        $script:Command = Read-WithDefault "启动命令" "php bin/hyperf.php start"
        $script:Log = Read-WithDefault "日志路径" "runtime/logs/hyperf.log"
    } elseif ($script:Type -eq "static") {
        $script:Port = [int] (Read-WithDefault "访问端口" "8002")
        $script:Services = Read-WithDefault "依赖服务" "nginx"
    } else {
        $script:Port = [int] (Read-WithDefault "访问端口" "8002")
        Write-Host ""
        Write-Host "请选择 PHP 版本："
        Write-Host "  1. PHP 8.3"
        Write-Host "  2. PHP 8.1"
        Write-Host "  3. PHP 8.0"
        Write-Host "  4. PHP 7.3"
        Write-Host "  5. 默认 php-fpm"
        do {
            $phpChoice = Read-Host "请输入 1 / 2 / 3 / 4 / 5"
        } while (-not (@("1", "2", "3", "4", "5") -contains $phpChoice))

        if ($phpChoice -eq "1") {
            $script:Php = "php83-fpm"
        } elseif ($phpChoice -eq "2") {
            $script:Php = "php81-fpm"
        } elseif ($phpChoice -eq "3") {
            $script:Php = "php80-fpm"
        } elseif ($phpChoice -eq "4") {
            $script:Php = "php73-fpm"
        } else {
            $script:Php = "php-fpm"
        }

        $script:Services = Read-WithDefault "依赖服务" "nginx,$script:Php,redis"

        $customRoot = Read-Host "站点根目录默认是 项目路径/public；如需自定义请输入，直接回车使用默认"
        if (-not [string]::IsNullOrWhiteSpace($customRoot)) {
            $script:Root = $customRoot.Trim()
        }
    }

    if ($script:Url -eq "" -and $script:Port -gt 0) {
        $script:Url = "http://localhost:$script:Port"
    }

    Write-Host ""
    Write-Host "即将写入以下配置："
    Write-Host "  类型：$script:Type"
    Write-Host "  标识：$script:Key"
    Write-Host "  名称：$script:Name"
    Write-Host "  路径：$script:Path"
    Write-Host "  端口：$script:Port"
    Write-Host "  PHP：$script:Php"
    Write-Host "  依赖服务：$script:Services"
    Write-Host "  访问地址：$script:Url"
    Write-Host ""

    $confirm = Read-Host "确认写入请直接回车或输入 Y；输入 N 取消"
    if (($confirm -eq "N") -or ($confirm -eq "n")) {
        Write-Host "已取消。"
        exit 0
    }
}

if ([string]::IsNullOrWhiteSpace($Type) -or [string]::IsNullOrWhiteSpace($Key) -or [string]::IsNullOrWhiteSpace($Path)) {
    Start-InteractiveWizard
}

$allowedTypes = @('hyperf', 'laravel', 'php-fpm', 'static')
if (-not ($allowedTypes -contains $Type)) {
    throw "不支持的项目类型：$Type。可用类型：hyperf、laravel、php-fpm、static。"
}

if ($Key -notmatch '^[a-zA-Z0-9_-]{2,64}$') {
    throw "项目标识只能使用 2-64 个字母、数字、中横线或下划线。"
}

$envValues = Read-DotEnv (Join-Path $RootDir '.env')
$hostProjectPath = if ($envValues.ContainsKey('HOST_PROJECT_PATH')) { $envValues['HOST_PROJECT_PATH'] } else { '.' }
$containerProjectPath = if ($envValues.ContainsKey('CONTAINER_PROJECT_PATH')) { $envValues['CONTAINER_PROJECT_PATH'] } else { '/develop' }

if ($Name -eq '') {
    $Name = $Key
}

if ($Port -eq 0) {
    $Port = if ($Type -eq 'hyperf') { 9502 } else { 8002 }
}

$containerPath = Convert-ToContainerPath $Path $hostProjectPath $containerProjectPath
$hyperfServiceName = if ($Type -eq 'hyperf') { Get-HyperfServiceName $Key } else { '' }

$defaultServices = switch ($Type) {
    'hyperf' { @($hyperfServiceName, 'redis') }
    'laravel' { @('nginx', $Php, 'redis') }
    'php-fpm' { @('nginx', $Php, 'redis') }
    'static' { @('nginx') }
}
$projectServices = Split-Services $Services $defaultServices
if ($Type -eq 'hyperf' -and -not ($projectServices -contains $hyperfServiceName)) {
    $projectServices = @($hyperfServiceName) + @($projectServices)
}

if ($Command -eq '' -and $Type -eq 'hyperf') {
    $Command = 'php bin/hyperf.php start'
}
if ($Log -eq '' -and $Type -eq 'hyperf') {
    $Log = 'runtime/logs/hyperf.log'
}
if ($Url -eq '' -and $Port -gt 0) {
    $Url = "http://localhost:$Port"
}

$siteRoot = ''
if ($Type -ne 'hyperf' -and -not $NoSite) {
    if ($Root -ne '') {
        $siteRoot = Convert-ToContainerPath $Root $hostProjectPath $containerProjectPath
    } elseif ($Type -eq 'static') {
        $siteRoot = $containerPath
    } else {
        $siteRoot = Join-ContainerPath $containerPath 'public'
    }
}

$project = [ordered] @{
    key = $Key
    name = $Name
    type = $Type
    path = $containerPath
    port = $Port
    php = $Php
    service = $hyperfServiceName
    services = @($projectServices)
    command = $Command
    log = $Log
    url = $Url
}

$projectsFile = Join-Path $RootDir 'projects.json'
$sitesDir = Join-Path $RootDir 'services/nginx/sites'
$composeFile = Join-Path $RootDir 'docker-compose.yml'
$siteFile = Join-Path $sitesDir "$Key.conf"

if (-not $DryRun -and $siteRoot -ne '' -and (Test-Path $siteFile) -and -not $Force) {
    throw "站点配置已存在：$siteFile。如需覆盖，请使用 -Force。"
}

$projectServicesText = [string]::Join(",", [string[]] $projectServices)
Write-Host "项目标识：$Key"
Write-Host "项目类型：$Type"
Write-Host "容器路径：$containerPath"
Write-Host "访问端口：$Port"
if ($Type -eq 'hyperf') {
    Write-Host "项目容器：$hyperfServiceName"
    Write-Host "PHP 版本：$(Convert-PhpServiceToVersion $Php)"
    Write-Host "容器内端口：9501"
}
Write-Host "依赖服务：$projectServicesText"

if ($DryRun) {
    Write-Host ''
    Write-Host "当前是预览模式，没有写入任何文件。"
    if ($siteRoot -ne '') {
        Write-Host "将生成站点配置：$siteFile"
        Write-Host "站点根目录：$siteRoot"
    }
    if ($Type -eq 'hyperf') {
        Write-Host "将生成 Hyperf 专属容器：$hyperfServiceName"
        Write-Host "端口映射：${Port}:9501"
    }
    Write-Host "将更新项目配置：$projectsFile"
    exit 0
}

$projects = Load-Projects $projectsFile
$updated = $false
$nextProjects = @()
foreach ($existing in $projects) {
    if ($existing.key -eq $Key) {
        $nextProjects += [pscustomobject] $project
        $updated = $true
    } else {
        $nextProjects += $existing
    }
}
if (-not $updated) {
    $nextProjects += [pscustomobject] $project
}
Save-Projects $projectsFile $nextProjects

if ($siteRoot -ne '') {
    if (-not (Test-Path $sitesDir)) {
        New-Item -ItemType Directory -Path $sitesDir | Out-Null
    }

    $siteConfig = New-SiteConfig $Type ([string] $Port) $ServerName $siteRoot $Php
    Write-Utf8NoBom $siteFile $siteConfig

    if (-not $NoComposePort) {
        $portAdded = Ensure-NginxPort $composeFile $Port
        if ($portAdded) {
            Write-Host "已添加 nginx 端口映射：${Port}:${Port}"
        }
    }
}

if ($Type -eq 'hyperf') {
    Ensure-HyperfComposeService $composeFile $hyperfServiceName $containerPath $Port (Convert-PhpServiceToVersion $Php) $Command
    Write-Host "已生成 Hyperf 专属容器配置：$hyperfServiceName"
}

Write-Host ''
if ($updated) {
    Write-Host "已更新 projects.json 中的项目配置。"
} else {
    Write-Host "已添加项目到 projects.json。"
}
if ($siteRoot -ne '') {
    Write-Host "已生成 Nginx 站点配置：services/nginx/sites/$Key.conf"
}

Write-Host ''
Write-Host "下一步："
if ($Type -eq 'hyperf') {
    Write-Host "docker-compose build $hyperfServiceName"
    Write-Host "docker-compose up -d $hyperfServiceName redis docker-panel"
    Write-Host "容器内默认监听 9501，宿主机访问端口是 $Port。"
    Write-Host "然后访问：$Url"
} else {
    $serviceText = [string]::Join(" ", [string[]] $projectServices)
    Write-Host "docker-compose up -d $serviceText docker-panel"
    Write-Host "然后访问：$Url"
}

