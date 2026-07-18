<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DockerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

#[Controller]
class ProjectController extends AbstractController
{
    private const HYPERF_DEV_COMMAND = 'if php bin/hyperf.php list 2>/dev/null | grep -q "server:watch"; then php bin/hyperf.php server:watch; else php bin/hyperf.php start; fi';
    private const HYPERF_STARTUP_PREPARE = 'git config --global --add safe.directory "$$PWD" 2>/dev/null || true; if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then composer install --no-interaction --prefer-dist; fi';

    #[Inject]
    protected DockerService $docker;

    private string $projectPath = '/var/www/docker-develop';
    private string $configFile = '/var/www/docker-develop/projects.json';
    private string $exampleFile = '/var/www/docker-develop/projects.example.json';

    private array $allowedTypes = ['hyperf', 'laravel', 'php-fpm', 'static'];

    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    #[RequestMapping(path: '/api/projects', methods: 'GET')]
    public function list()
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        try {
            $projects = $this->loadProjects();
            $containers = $this->docker->getContainers(true);

            return $this->json([
                'projects' => array_map(fn(array $project) => $this->projectWithStatus($project, $containers), $projects),
                'configExists' => file_exists($this->configFile),
                'configPath' => 'projects.json',
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    #[RequestMapping(path: '/api/projects', methods: 'POST')]
    public function save()
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = [
            'key' => trim((string) $this->request->input('key', '')),
            'name' => trim((string) $this->request->input('name', '')),
            'type' => trim((string) $this->request->input('type', 'hyperf')),
            'path' => trim((string) $this->request->input('path', '')),
            'port' => (int) $this->request->input('port', 0),
            'php' => trim((string) $this->request->input('php', 'php83-fpm')),
            'service' => trim((string) $this->request->input('service', '')),
            'services' => $this->normalizeServices($this->request->input('services', [])),
            'command' => trim((string) $this->request->input('command', '')),
            'log' => trim((string) $this->request->input('log', '')),
            'url' => trim((string) $this->request->input('url', '')),
        ];

        $validationError = $this->validateProject($project);
        if ($validationError !== null) {
            return $this->error($validationError, 400);
        }

        if ($project['command'] === '' && $project['type'] === 'hyperf') {
            $project['command'] = self::HYPERF_DEV_COMMAND;
        }
        if ($project['log'] === '' && $project['type'] === 'hyperf') {
            $project['log'] = 'runtime/logs/hyperf.log';
        }
        if ($project['type'] === 'hyperf' && $project['service'] === '') {
            $project['service'] = $this->hyperfServiceName($project['key']);
        }
        if ($project['type'] === 'hyperf' && !in_array($project['service'], $project['services'], true)) {
            array_unshift($project['services'], $project['service']);
        }
        if ($project['url'] === '' && $project['port'] > 0) {
            $project['url'] = 'http://localhost:' . $project['port'];
        }

        $projects = $this->loadProjects();
        $updated = false;
        foreach ($projects as $index => $existing) {
            if (($existing['key'] ?? '') === $project['key']) {
                $projects[$index] = $project;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $projects[] = $project;
        }

        $this->saveProjects($projects);

        return $this->success(['project' => $project], $updated ? 'Project updated' : 'Project created');
    }

    #[RequestMapping(path: '/api/project-scaffold', methods: 'POST')]
    public function scaffold()
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        try {
            $type = trim((string) $this->request->input('type', 'laravel'));
            $key = trim((string) $this->request->input('key', ''));
            $name = trim((string) $this->request->input('name', $key));
            $pathInput = trim((string) $this->request->input('path', ''));
            $port = (int) $this->request->input('port', 0);
            $php = trim((string) $this->request->input('php', 'php83-fpm'));
            $servicesInput = $this->request->input('services', '');
            $command = trim((string) $this->request->input('command', ''));
            $log = trim((string) $this->request->input('log', ''));
            $url = trim((string) $this->request->input('url', ''));
            $rootInput = trim((string) $this->request->input('root', ''));
            $serverName = trim((string) $this->request->input('serverName', 'localhost')) ?: 'localhost';
            $noSite = (bool) $this->request->input('noSite', false);
            $force = (bool) $this->request->input('force', false);
            $dryRun = (bool) $this->request->input('dryRun', false);

            if ($port === 0) {
                $port = $type === 'hyperf' ? 9502 : 8002;
            }

            $containerPath = $this->toContainerPath($pathInput);
            $service = $type === 'hyperf' ? $this->hyperfServiceName($key) : '';
            $defaultServices = $this->defaultProjectServices($type, $php, $service);
            $services = $this->normalizeServices($servicesInput === '' ? $defaultServices : $servicesInput);

            if ($type === 'hyperf' && !in_array($service, $services, true)) {
                array_unshift($services, $service);
            }
            if ($command === '' && $type === 'hyperf') {
                $command = self::HYPERF_DEV_COMMAND;
            }
            if ($log === '' && $type === 'hyperf') {
                $log = 'runtime/logs/hyperf.log';
            }
            if ($url === '' && $port > 0) {
                $url = 'http://localhost:' . $port;
            }
            if ($name === '') {
                $name = $key;
            }

            $project = [
                'key' => $key,
                'name' => $name,
                'type' => $type,
                'path' => $containerPath,
                'port' => $port,
                'php' => $php,
                'service' => $service,
                'services' => $services,
                'command' => $command,
                'log' => $log,
                'url' => $url,
            ];

            $validationError = $this->validateProject($project);
            if ($validationError !== null) {
                return $this->error($validationError, 400);
            }

            $actions = ['Write or update projects.json'];
            $site = null;
            if ($type !== 'hyperf' && !$noSite) {
                $siteRoot = $rootInput !== '' ? $this->toContainerPath($rootInput) : ($type === 'static' ? $containerPath : $this->joinContainerPath($containerPath, 'public'));
                $siteFile = $this->projectPath . '/services/nginx/sites/' . $key . '.conf';
                if (!$dryRun && file_exists($siteFile) && !$force) {
                    return $this->error('Site config already exists. Enable force overwrite or choose another key.', 400);
                }
                $site = [
                    'file' => 'services/nginx/sites/' . $key . '.conf',
                    'root' => $siteRoot,
                    'serverName' => $serverName,
                    'port' => $port,
                    'php' => $php,
                ];
                $actions[] = 'Write Nginx site ' . $site['file'];
                $actions[] = 'Ensure nginx port ' . $port . ':' . $port;
            }

            $compose = null;
            if ($type === 'hyperf') {
                $compose = [
                    'service' => $service,
                    'hostPort' => $port,
                    'containerPort' => 9501,
                    'phpVersion' => $this->phpServiceToVersion($php),
                ];
                $actions[] = 'Write Hyperf service ' . $service . ' with port ' . $port . ':9501';
            }

            $nextSteps = $type === 'hyperf'
                ? ['在项目卡片点击 Start；面板会自动 build，缺 vendor 时会自动 composer install。', '打开 ' . $url]
                : ['docker-compose up -d ' . implode(' ', $services) . ' docker-panel', '新增端口后重启 Nginx，普通配置变更可重载 Nginx。', '打开 ' . $url];

            if ($dryRun) {
                return $this->success([
                    'project' => $project,
                    'site' => $site,
                    'compose' => $compose,
                    'actions' => $actions,
                    'nextSteps' => $nextSteps,
                ], 'Scaffold preview');
            }

            if ($site !== null) {
                $sitesPath = $this->projectPath . '/services/nginx/sites';
                if (!is_dir($sitesPath) && !mkdir($sitesPath, 0775, true) && !is_dir($sitesPath)) {
                    throw new \RuntimeException('Unable to create nginx sites directory.');
                }
                $siteFile = $sitesPath . '/' . $key . '.conf';
                $this->writeTextFile($siteFile, $this->generateSiteConfig($type, $serverName, $site['root'], $php, $port));
                $this->ensureNginxPort($port);
            }

            if ($compose !== null) {
                $this->ensureHyperfComposeService($service, $containerPath, $port, $compose['phpVersion'], $command);
            }

            $this->saveProjects($this->upsertProject($this->loadProjects(), $project));

            return $this->success([
                'project' => $project,
                'site' => $site,
                'compose' => $compose,
                'actions' => $actions,
                'nextSteps' => $nextSteps,
            ], 'Project scaffolded');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    #[RequestMapping(path: '/api/projects/{key}/start', methods: 'POST')]
    public function start(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        try {
            $services = $this->projectServices($project);
            $output = [];
            if (!empty($services)) {
                $output[] = $this->startProjectServices($services);
            }

            return $this->success([
                'output' => trim(implode("\n\n", array_filter($output))),
                'url' => $project['url'] ?? null,
            ], 'Project started');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}/stop', methods: 'POST')]
    public function stop(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        try {
            if (($project['type'] ?? '') !== 'hyperf') {
                return $this->success([], 'No project process to stop');
            }

            $service = $this->hyperfService($project);
            return $this->success([
                'output' => $this->runHostCommand('docker-compose stop ' . escapeshellarg($service)),
            ], 'Project stopped');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}/restart', methods: 'POST')]
    public function restart(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        try {
            $services = $this->projectServices($project);
            $output = [];
            if (($project['type'] ?? '') === 'hyperf') {
                $output[] = $this->runHostCommand('docker-compose stop ' . escapeshellarg($this->hyperfService($project)));
            }
            if (!empty($services)) {
                $output[] = $this->startProjectServices($services);
            }

            return $this->success([
                'output' => trim(implode("\n\n", array_filter($output))),
                'url' => $project['url'] ?? null,
            ], 'Project restarted');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}/logs', methods: 'GET')]
    public function logs(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        if (($project['type'] ?? '') !== 'hyperf') {
            return $this->json(['logs' => 'This project type does not have a managed process log.']);
        }

        try {
            return $this->json([
                'logs' => $this->runHostCommand('docker-compose logs --tail=200 ' . escapeshellarg($this->hyperfService($project))),
            ]);
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}/run', methods: 'POST')]
    public function runCommand(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        $commandKey = trim((string) $this->request->input('command', ''));
        $customCommand = trim((string) $this->request->input('custom', ''));
        $command = $this->resolveProjectCommand($project, $commandKey, $customCommand);

        if ($command === null) {
            return $this->error('Unknown project command', 400);
        }

        if ($this->looksDangerous($command)) {
            return $this->error('This command is blocked by the launcher.', 400);
        }

        try {
            $execService = $this->commandService($project);
            $this->startProjectServices([$execService]);
            $path = $this->containerPath($project);
            $this->prepareProjectRuntime($execService, $path, str_starts_with($commandKey, 'composer-'));
            $output = $this->runServiceCommand($execService, 'cd ' . escapeshellarg($path) . ' && ' . $command);

            return $this->success([
                'command' => $command,
                'output' => $output,
            ], 'Command finished');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}', methods: 'DELETE')]
    public function delete(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $projects = array_values(array_filter(
            $this->loadProjects(),
            fn(array $project) => ($project['key'] ?? '') !== $key
        ));

        $this->saveProjects($projects);

        return $this->success([], 'Project deleted');
    }

    private function readDotEnv(): array
    {
        $file = $this->projectPath . '/.env';
        if (!file_exists($file)) {
            return [];
        }

        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $values[trim($name)] = trim(trim($value), "\"'");
        }

        return $values;
    }

    private function toContainerPath(string $path): string
    {
        $raw = trim(trim($path), "\"'");
        if ($raw === '') {
            throw new \RuntimeException('Project path is required.');
        }

        $normalized = str_replace('\\', '/', $raw);
        if (str_starts_with($normalized, '/')) {
            return '/' . trim($normalized, '/');
        }

        $env = $this->readDotEnv();
        $hostRoot = str_replace('\\', '/', (string) ($env['HOST_PROJECT_PATH'] ?? ''));
        $containerRoot = (string) ($env['CONTAINER_PROJECT_PATH'] ?? '/develop');
        $hostRoot = rtrim($hostRoot, '/');
        $containerRoot = '/' . trim($containerRoot, '/');

        $relative = $this->relativePathFromHostRoot($normalized, $hostRoot);
        if ($relative !== null) {
            return $this->joinContainerPath($containerRoot, $relative);
        }

        if (preg_match('/^[a-zA-Z]:\//', $normalized)) {
            $hostDisplay = $hostRoot !== '' ? $hostRoot : '(not set)';
            throw new \RuntimeException('Host path is outside HOST_PROJECT_PATH (' . $hostDisplay . '). Use a /develop/... container path or update .env.');
        }

        return $this->joinContainerPath($containerRoot, $normalized);
    }

    private function relativePathFromHostRoot(string $input, string $hostRoot): ?string
    {
        if ($hostRoot === '') {
            return null;
        }

        if (str_starts_with(strtolower($input), strtolower($hostRoot))) {
            return ltrim(substr($input, strlen($hostRoot)), '/');
        }

        if (preg_match('/^[a-zA-Z]:\//', $hostRoot) || str_starts_with($hostRoot, '/')) {
            return null;
        }

        $hostAbs = $this->normalizeLocalPath($this->projectPath . '/' . $hostRoot);
        $inputAbs = $this->normalizeLocalPath($this->projectPath . '/' . $input);
        if ($inputAbs === $hostAbs) {
            return '';
        }
        if (str_starts_with(strtolower($inputAbs), strtolower($hostAbs . '/'))) {
            return ltrim(substr($inputAbs, strlen($hostAbs)), '/');
        }

        return null;
    }

    private function normalizeLocalPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private function joinContainerPath(string $base, string $child): string
    {
        $base = '/' . trim(str_replace('\\', '/', $base), '/');
        $child = trim(str_replace('\\', '/', $child), '/');
        return $child === '' ? $base : $base . '/' . $child;
    }

    private function defaultProjectServices(string $type, string $php, string $hyperfService): array
    {
        if ($type === 'hyperf') {
            return [$hyperfService, 'redis'];
        }
        if ($type === 'static') {
            return ['nginx'];
        }
        return ['nginx', $php, 'redis'];
    }

    private function phpServiceToVersion(string $php): string
    {
        return match ($php) {
            'php73-fpm' => '7.3',
            'php80-fpm' => '8.0',
            'php81-fpm' => '8.1',
            default => '8.3',
        };
    }

    private function upsertProject(array $projects, array $project): array
    {
        $updated = false;
        foreach ($projects as $index => $existing) {
            if (($existing['key'] ?? '') === $project['key']) {
                $projects[$index] = $project;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $projects[] = $project;
        }
        return array_values($projects);
    }

    private function generateSiteConfig(string $type, string $serverName, string $root, string $php, int $port): string
    {
        $logName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $serverName ?: 'site') ?: 'site';
        if ($port !== 80) {
            $logName .= '_' . $port;
        }

        $lines = [
            'server {',
            '    listen ' . $port . ';',
            '    listen [::]:' . $port . ';',
            '',
            '    server_name ' . $serverName . ';',
            '    root ' . $root . ';',
        ];

        if ($type === 'static') {
            $lines = array_merge($lines, [
                '    index index.html index.htm;',
                '',
                '    location / {',
                '        try_files $uri $uri/ =404;',
                '    }',
            ]);
        } else {
            $lines = array_merge($lines, [
                '    index index.php index.html index.htm;',
                '',
                '    location / {',
                '        try_files $uri $uri/ /index.php$is_args$args;',
                '    }',
                '',
                '    location ~ \.php$ {',
                '        try_files $uri /index.php =404;',
                '        fastcgi_pass ' . $php . ':9000;',
                '        fastcgi_index index.php;',
                '        fastcgi_buffers 16 16k;',
                '        fastcgi_buffer_size 32k;',
                '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
                '        fastcgi_read_timeout 600;',
                '        include fastcgi_params;',
                '    }',
            ]);
        }

        $lines = array_merge($lines, [
            '',
            '    location ~ /\.ht {',
            '        deny all;',
            '    }',
            '',
            '    error_log /var/log/nginx/' . $logName . '_error.log;',
            '    access_log /var/log/nginx/' . $logName . '_access.log;',
            '}',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }

    private function ensureNginxPort(int $port): bool
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $portText = '"' . $port . ':' . $port . '"';
        if (str_contains($content, $portText)) {
            return false;
        }

        $pattern = '/(  nginx:\R(?:(?!^  [a-zA-Z0-9_-]+:).)*?    ports:\R(?:(?:      - .*\R)+))/ms';
        $newContent = preg_replace($pattern, '$1      - "' . $port . ':' . $port . '"' . PHP_EOL, $content, 1, $count);
        if ($count !== 1 || $newContent === null) {
            throw new \RuntimeException('Unable to find nginx ports block in docker-compose.yml.');
        }

        $this->writeTextFile($composeFile, $newContent);
        return true;
    }

    private function ensureHyperfComposeService(string $service, string $path, int $hostPort, string $phpVersion, string $command): void
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $begin = '# BEGIN Hyperf project: ' . $service;
        $end = '# END Hyperf project: ' . $service;
        $block = $begin . PHP_EOL . $this->newHyperfServiceConfig($service, $path, $hostPort, $phpVersion, $command) . $end;
        $pattern = '/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, PHP_EOL . $block, $content, 1) ?? $content;
        } else {
            $markerPattern = '/#-+\R# Docker 管理面板[^\r\n]*(?:\R#-+[^\r\n]*)?/';
            if (!preg_match($markerPattern, $content)) {
                throw new \RuntimeException('Unable to find docker-panel marker in docker-compose.yml.');
            }
            $content = preg_replace($markerPattern, $block . PHP_EOL . PHP_EOL . '$0', $content, 1) ?? $content;
        }

        $this->writeTextFile($composeFile, rtrim($content) . PHP_EOL);
    }

    private function newHyperfServiceConfig(string $service, string $path, int $hostPort, string $phpVersion, string $command): string
    {
        $lines = [
            '',
            '  ' . $service . ':',
            '    build:',
            '      context: ./services/workspace',
            '      args:',
            '        - CHANGE_SOURCE=${CHANGE_SOURCE}',
            '        - UBUNTU_VERSION=${UBUNTU_VERSION}',
            '        - PHP_VERSION=' . $phpVersion,
            '        - INSTALL_XDEBUG=${WORKSPACE_INSTALL_XDEBUG}',
            '        - XDEBUG_PORT=${WORKSPACE_XDEBUG_PORT}',
            '        - INSTALL_MONGO=${WORKSPACE_INSTALL_MONGO}',
            '        - INSTALL_PHPREDIS=${WORKSPACE_INSTALL_PHPREDIS}',
            '        - INSTALL_SWOOLE=${WORKSPACE_INSTALL_SWOOLE}',
            '        - INSTALL_COMPOSER=${WORKSPACE_INSTALL_COMPOSER}',
            '        - COMPOSER_VERSION=${WORKSPACE_COMPOSER_VERSION}',
            '        - COMPOSER_REPO_PACKAGIST=${WORKSPACE_COMPOSER_REPO_PACKAGIST}',
            '        - INSTALL_NODE=${WORKSPACE_INSTALL_NODE}',
            '        - NODE_VERSION=${WORKSPACE_NODE_VERSION}',
            '        - INSTALL_SUPERVISOR=${WORKSPACE_INSTALL_SUPERVISOR}',
            '        - SHELL_OH_MY_ZSH=${SHELL_OH_MY_ZSH}',
            '        - PUID=${WORKSPACE_PUID}',
            '        - PGID=${WORKSPACE_PGID}',
            '        - TZ=${TIMEZONE}',
            '        - CONTAINER_PROJECT_PATH=${CONTAINER_PROJECT_PATH}',
            '    working_dir: ' . $this->yamlSingleQuote($path),
            '    command: sh -lc ' . $this->yamlSingleQuote(self::HYPERF_STARTUP_PREPARE . '; ' . $command),
            '    volumes:',
            '      - ${HOST_PROJECT_PATH}:${CONTAINER_PROJECT_PATH}',
            '    extra_hosts:',
            '      - "dockerhost:${DOCKER_HOST_IP}"',
            '    ports:',
            '      - "' . $hostPort . ':9501"',
            '    depends_on:',
            '      - redis',
            '    tty: true',
            '    networks:',
            '      - frontend',
            '      - backend',
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function yamlSingleQuote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function writeTextFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write ' . basename($path));
        }
    }
    private function loadProjects(): array
    {
        if (!file_exists($this->configFile)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($this->configFile), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('projects.json is not valid JSON.');
        }

        $projects = $payload['projects'] ?? [];
        return is_array($projects) ? array_values(array_filter($projects, 'is_array')) : [];
    }

    private function saveProjects(array $projects): void
    {
        $payload = json_encode(['projects' => array_values($projects)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($this->configFile, $payload . PHP_EOL) === false) {
            throw new \RuntimeException('Unable to save projects.json.');
        }
    }

    private function findProject(string $key): ?array
    {
        foreach ($this->loadProjects() as $project) {
            if (($project['key'] ?? '') === $key) {
                return $project;
            }
        }

        return null;
    }

    private function projectWithStatus(array $project, array $containers): array
    {
        $services = $this->projectServices($project);
        $running = 0;
        foreach ($services as $service) {
            foreach ($containers as $container) {
                if (str_contains((string) ($container['name'] ?? ''), $service) && ($container['state'] ?? '') === 'running') {
                    $running++;
                    break;
                }
            }
        }

        $project['services'] = $services;
        $project['status'] = [
            'servicesRunning' => $running,
            'servicesTotal' => count($services),
            'active' => count($services) > 0 && $running === count($services),
        ];

        return $project;
    }

    private function projectServices(array $project): array
    {
        $services = $this->normalizeServices($project['services'] ?? []);
        if (($project['type'] ?? '') === 'hyperf') {
            $service = $this->hyperfService($project);
            if (!in_array($service, $services, true)) {
                array_unshift($services, $service);
            }
        }

        return array_values(array_unique($services));
    }

    private function hyperfService(array $project): string
    {
        $service = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['service'] ?? '')) ?: '';
        if ($service !== '') {
            return $service;
        }

        return $this->hyperfServiceName((string) ($project['key'] ?? 'project'));
    }

    private function hyperfServiceName(string $key): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $key) ?: 'project');
        return 'hyperf-' . $name;
    }

    private function commandService(array $project): string
    {
        if (($project['type'] ?? '') === 'hyperf') {
            return $this->hyperfService($project);
        }

        $phpService = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['php'] ?? '')) ?: '';
        return $phpService !== '' ? $phpService : 'workspace';
    }

    private function validateProject(array $project): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $project['key'])) {
            return 'Project key must be 2-64 letters, numbers, dashes, or underscores.';
        }
        if ($project['name'] === '') {
            return 'Project name is required.';
        }
        if (!in_array($project['type'], $this->allowedTypes, true)) {
            return 'Unsupported project type.';
        }
        if ($project['path'] === '') {
            return 'Project path is required.';
        }
        if ($project['port'] !== 0 && ($project['port'] < 1024 || $project['port'] > 65535)) {
            return 'Port must be between 1024 and 65535.';
        }

        return null;
    }

    private function normalizeServices(mixed $services): array
    {
        if (is_string($services)) {
            $services = preg_split('/[\s,]+/', $services) ?: [];
        }
        if (!is_array($services)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn($service) => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $service),
            $services
        ))));
    }

    private function resolveProjectCommand(array $project, string $commandKey, string $customCommand): ?string
    {
        $type = (string) ($project['type'] ?? '');

        $common = [
            'php-version' => 'php -v',
            'composer-install' => 'composer install',
            'composer-update' => 'composer update',
            'composer-dump' => 'composer dump-autoload',
            'test' => 'if [ -x vendor/bin/phpunit ]; then vendor/bin/phpunit; elif [ -x vendor/bin/pest ]; then vendor/bin/pest; else echo "No phpunit or pest binary found"; exit 1; fi',
        ];

        $typeCommands = [
            'hyperf' => [
                'migrate' => 'php bin/hyperf.php migrate',
                'di-proxy' => 'php bin/hyperf.php di:init-proxy',
                'routes' => 'php bin/hyperf.php describe:routes',
            ],
            'laravel' => [
                'migrate' => 'php artisan migrate',
                'optimize-clear' => 'php artisan optimize:clear',
                'route-list' => 'php artisan route:list',
            ],
            'php-fpm' => [],
            'static' => [
                'npm-install' => 'npm install',
                'npm-build' => 'npm run build',
            ],
        ];

        $commands = array_merge($common, $typeCommands[$type] ?? []);

        if ($commandKey === 'custom') {
            return $customCommand !== '' ? $customCommand : null;
        }

        return $commands[$commandKey] ?? null;
    }

    private function looksDangerous(string $command): bool
    {
        $blocked = [
            'rm -rf /',
            'mkfs',
            ':(){',
            'chmod -R 777 /',
            'chown -R',
            'dd if=',
        ];

        foreach ($blocked as $pattern) {
            if (stripos($command, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function containerPath(array $project): string
    {
        $path = (string) ($project['path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('Project path is empty.');
        }

        return $path;
    }

    private function prepareProjectRuntime(string $service, string $path, bool $prepareComposer): void
    {
        $commands = [
            'if command -v git >/dev/null 2>&1; then git config --global --add safe.directory ' . escapeshellarg($path) . ' 2>/dev/null || true; fi',
        ];

        if ($prepareComposer) {
            $commands[] = 'if command -v composer >/dev/null 2>&1; then composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true; fi';
        }

        $this->runServiceCommand($service, implode(' && ', $commands));
    }
    private function runHostCommand(string $command): string
    {
        $output = [];
        $returnVar = 0;
        exec('cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && ' . $this->composeHostEnvPrefix() . $command . ' 2>&1', $output, $returnVar);
        $text = implode("\n", $output);
        if ($returnVar !== 0) {
            throw new \RuntimeException($text !== '' ? $text : 'Command failed.');
        }

        return $text;
    }

    private function composeHostEnvPrefix(): string
    {
        $hostProjectRoot = $this->detectHostProjectRoot();
        if ($hostProjectRoot === null) {
            return '';
        }

        $env = $this->readDotEnv();
        $pathKeys = [
            'HOST_PROJECT_PATH',
            'DATA_PATH',
            'NGINX_HOST_LOG_PATH',
            'NGINX_SITES_PATH',
            'NGINX_SSL_PATH',
            'MYSQL_HOST_DATA_PATH',
            'MYSQL_ENTRYPOINT_INITDB',
        ];

        $assignments = [];
        foreach ($pathKeys as $key) {
            $value = (string) ($env[$key] ?? '');
            if ($this->isRelativeHostPath($value)) {
                $assignments[] = $key . '=' . escapeshellarg($this->joinHostPath($hostProjectRoot, $value));
            }
        }

        return $assignments === [] ? '' : implode(' ', $assignments) . ' ';
    }

    private function detectHostProjectRoot(): ?string
    {
        $hostname = gethostname();
        if ($hostname === false || $hostname === '') {
            return null;
        }

        $output = [];
        $returnVar = 0;
        exec('docker inspect ' . escapeshellarg($hostname) . ' --format ' . escapeshellarg('{{json .Mounts}}') . ' 2>/dev/null', $output, $returnVar);
        if ($returnVar !== 0 || $output === []) {
            return null;
        }

        $mounts = json_decode(implode("\n", $output), true);
        if (!is_array($mounts)) {
            return null;
        }

        foreach ($mounts as $mount) {
            if (($mount['Destination'] ?? '') === $this->projectPath && isset($mount['Source'])) {
                return (string) $mount['Source'];
            }
        }

        return null;
    }

    private function isRelativeHostPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '${')) {
            return false;
        }

        return !str_starts_with($path, '/')
            && !preg_match('/^[a-zA-Z]:[\\\\\\/]/', $path);
    }

    private function joinHostPath(string $base, string $relative): string
    {
        $relative = preg_replace('/^\.[\\\\\\/]/', '', trim($relative)) ?? trim($relative);
        $relative = trim($relative, "\\/");
        if ($relative === '') {
            return rtrim($base, "\\/");
        }

        $separator = preg_match('/^[a-zA-Z]:[\\\\\\/]/', $base) ? '\\' : '/';
        $relative = str_replace(['\\', '/'], $separator, $relative);

        return rtrim($base, "\\/") . $separator . $relative;
    }

    private function runServiceCommand(string $service, string $command): string
    {
        return $this->runHostCommand('docker-compose exec -T ' . escapeshellarg($service) . ' sh -lc ' . escapeshellarg($command));
    }

    /**
     * 启动项目相关服务。第一次启动时如果镜像还没构建，会自动补一次 build 再启动。
     */
    private function startProjectServices(array $services): string
    {
        $services = array_values(array_filter(array_map(
            fn($service) => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $service),
            $services
        )));
        if ($services === []) {
            return '';
        }

        $serviceText = implode(' ', array_map('escapeshellarg', $services));

        try {
            return $this->runHostCommand("docker-compose up -d --no-build {$serviceText}");
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (!$this->shouldBuildAndRetry($message)) {
                throw $e;
            }

            $buildOutput = $this->runHostCommand("docker-compose build {$serviceText}");
            $upOutput = $this->runHostCommand("docker-compose up -d {$serviceText}");

            return trim($buildOutput . "\n\n" . $upOutput);
        }
    }

    private function shouldBuildAndRetry(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'no such image')
            || str_contains($message, 'pull access denied')
            || str_contains($message, 'failed to solve:');
    }

    private function friendlyCommandError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return '命令执行失败。';
        }
        if (str_contains($message, 'No such service')) {
            return $message . "\n\n没有找到项目容器配置。Hyperf 项目需要先用脚手架生成专属容器，或检查 docker-compose.yml。";
        }
        if (str_contains($message, 'No such image') || str_contains($message, 'pull access denied')) {
            return $message . "\n\n镜像还没有构建好。请先确认网络可用，或者让面板先执行一次 build 再启动。";
        }

        return $message;
    }
}
