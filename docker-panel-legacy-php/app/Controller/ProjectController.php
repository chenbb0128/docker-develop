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
    private const HYPERF_ENTRY_CHECK = 'if [ ! -f bin/hyperf.php ]; then echo "Hyperf entry file missing."; echo "Check HOST_PROJECT_PATH in .env and the project path."; exit 66; fi';
    private const JAVA_DEV_COMMAND = 'PORT=${SERVER_PORT:-8080}; if [ -f pom.xml ]; then mvn spring-boot:run -Dspring-boot.run.arguments=--server.port=$PORT; elif [ -f ./mvnw ]; then tr -d "\015" < ./mvnw > /tmp/project-mvnw && chmod +x /tmp/project-mvnw && sh /tmp/project-mvnw spring-boot:run -Dspring-boot.run.arguments=--server.port=$PORT; elif [ -f ./gradlew ]; then tr -d "\015" < ./gradlew > /tmp/project-gradlew && chmod +x /tmp/project-gradlew && sh /tmp/project-gradlew bootRun --args=--server.port=$PORT; else echo "No pom.xml, mvnw, or gradlew found"; exit 1; fi';
    private const GO_DEV_COMMAND = 'export PATH=/go/bin:/usr/local/go/bin:$PATH; PORT=${PORT:-8080}; export PORT; SERVER_PORT=${SERVER_PORT:-$PORT}; export SERVER_PORT; if [ ! -f go.mod ]; then echo "go.mod not found"; exit 1; fi; go mod download; if [ -f main.go ]; then exec go run .; elif [ -d cmd/server ]; then exec go run ./cmd/server; elif [ -d cmd/api ]; then exec go run ./cmd/api; elif [ -d cmd/app ]; then exec go run ./cmd/app; else MAIN_PACKAGE=$(go list -f "{{if eq .Name \"main\"}}{{.ImportPath}}{{end}}" ./... 2>/dev/null | sed -n "/./{p;q;}"); if [ -z "$MAIN_PACKAGE" ]; then echo "No Go main package found"; exit 1; fi; exec go run "$MAIN_PACKAGE"; fi';

    #[Inject]
    protected DockerService $docker;

    private string $projectPath = '/var/www/docker-develop';
    private string $configFile = '/var/www/docker-develop/projects.json';
    private string $exampleFile = '/var/www/docker-develop/projects.example.json';

    private array $allowedTypes = ['hyperf', 'laravel', 'php-fpm', 'static', 'java', 'go'];

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
        if ($project['command'] === '' && $project['type'] === 'java') {
            $project['command'] = self::JAVA_DEV_COMMAND;
        }
        if ($project['command'] === '' && $project['type'] === 'go') {
            $project['command'] = self::GO_DEV_COMMAND;
        }
        if ($project['log'] === '' && $project['type'] === 'hyperf') {
            $project['log'] = 'runtime/logs/hyperf.log';
        }
        if ($project['log'] === '' && $project['type'] === 'java') {
            $project['log'] = 'logs/spring.log';
        }
        if ($project['type'] === 'java' && $project['service'] === '') {
            $project['service'] = $this->javaServiceName($project['key']);
        }
        if ($project['type'] === 'java' && empty($project['services'])) {
            $project['services'] = [$project['service'], 'redis', 'mysql', 'rabbitmq'];
        }
        if ($project['type'] === 'go' && $project['service'] === '') {
            $project['service'] = $this->goServiceName($project['key']);
        }
        if ($project['type'] === 'go' && empty($project['services'])) {
            $project['services'] = [$project['service']];
        }
        if ($project['type'] === 'hyperf' && $project['service'] === '') {
            $project['service'] = $this->hyperfServiceName($project['key']);
        }
        if ($project['type'] === 'hyperf' && !in_array($project['service'], $project['services'], true)) {
            array_unshift($project['services'], $project['service']);
        }
        if ($project['type'] === 'java' && !in_array($project['service'], $project['services'], true)) {
            array_unshift($project['services'], $project['service']);
        }
        if ($project['type'] === 'go' && !in_array($project['service'], $project['services'], true)) {
            array_unshift($project['services'], $project['service']);
        }
        $project['services'] = $this->normalizeProjectServicesForType(
            $project['type'],
            $project['services'],
            $project['service']
        );
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
                $port = match ($type) {
                    'hyperf' => 9502,
                    'java' => 8080,
                    'go' => 8081,
                    default => 8002,
                };
            }

            $containerPath = $this->toContainerPath($pathInput);
            $service = match ($type) {
                'hyperf' => $this->hyperfServiceName($key),
                'java' => $this->javaServiceName($key),
                'go' => $this->goServiceName($key),
                default => '',
            };
            $defaultServices = $this->defaultProjectServices($type, $php, $service);
            $services = $this->normalizeServices($servicesInput === '' ? $defaultServices : $servicesInput);

            if (in_array($type, ['hyperf', 'java', 'go'], true) && $service !== '' && !in_array($service, $services, true)) {
                array_unshift($services, $service);
            }
            $services = $this->normalizeProjectServicesForType($type, $services, $service);
            if ($command === '' && $type === 'hyperf') {
                $command = self::HYPERF_DEV_COMMAND;
            }
            if ($command === '' && $type === 'java') {
                $command = self::JAVA_DEV_COMMAND;
            }
            if ($command === '' && $type === 'go') {
                $command = self::GO_DEV_COMMAND;
            }
            if ($log === '' && $type === 'hyperf') {
                $log = 'runtime/logs/hyperf.log';
            }
            if ($log === '' && $type === 'java') {
                $log = 'logs/spring.log';
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
            if (!in_array($type, ['hyperf', 'java', 'go'], true) && !$noSite) {
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
            if ($type === 'java') {
                $compose = [
                    'service' => $service,
                    'hostPort' => $port,
                    'containerPort' => $port,
                ];
                $actions[] = 'Write Java service ' . $service . ' with port ' . $port . ':' . $port;
            }
            if ($type === 'go') {
                $compose = [
                    'service' => $service,
                    'hostPort' => $port,
                    'containerPort' => 8080,
                ];
                $actions[] = 'Write Go service ' . $service . ' with port ' . $port . ':8080';
            }

            $nextSteps = match ($type) {
                'hyperf' => ['在项目卡片点击 Start；面板会自动 build，缺 vendor 时会自动 composer install。', '打开 ' . $url],
                'java' => ['在项目卡片点击 Start；面板会启动 Java 专属容器，并一起拉起 Redis / MySQL / RabbitMQ。', '打开 ' . $url],
                'go' => ['Click Start on the project card; the panel builds the Go container, downloads modules, and starts selected dependencies.', 'Open ' . $url],
                default => ['docker-compose up -d ' . implode(' ', $services) . ' docker-panel', '新增端口后重启 Nginx，普通配置变更可重载 Nginx。', '打开 ' . $url],
            };

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

            if ($compose !== null && $type === 'hyperf') {
                $this->ensureHyperfComposeService($service, $containerPath, $port, $compose['phpVersion'], $command);
            }
            if ($compose !== null && $type === 'java') {
                $this->ensureJavaComposeService($service, $containerPath, $port, $command, $services);
            }
            if ($compose !== null && $type === 'go') {
                $this->ensureGoComposeService($service, $containerPath, $port, $command, $services);
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
            $this->ensureWebProjectRuntime($project);
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
            if (!in_array(($project['type'] ?? ''), ['hyperf', 'java', 'go'], true)) {
                return $this->success([], 'No project process to stop');
            }

            $service = match ($project['type'] ?? '') {
                'java' => $this->javaService($project),
                'go' => $this->goService($project),
                default => $this->hyperfService($project),
            };
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
            $this->ensureWebProjectRuntime($project);
            $services = $this->projectServices($project);
            $output = [];
            if (($project['type'] ?? '') === 'hyperf') {
                $output[] = $this->runHostCommand('docker-compose stop ' . escapeshellarg($this->hyperfService($project)));
            }
            if (($project['type'] ?? '') === 'java') {
                $output[] = $this->runHostCommand('docker-compose stop ' . escapeshellarg($this->javaService($project)));
            }
            if (($project['type'] ?? '') === 'go') {
                $output[] = $this->runHostCommand('docker-compose stop ' . escapeshellarg($this->goService($project)));
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

    #[RequestMapping(path: '/api/projects/{key}/start-task', methods: 'POST')]
    public function startTask(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        try {
            return $this->createProjectActionTask($key, 'start');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/projects/{key}/restart-task', methods: 'POST')]
    public function restartTask(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        try {
            return $this->createProjectActionTask($key, 'restart');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    #[RequestMapping(path: '/api/project-tasks/{taskId}', methods: 'GET')]
    public function projectTask(string $taskId)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        $taskId = $this->normalizeTaskId($taskId);
        if ($taskId === '') {
            return $this->error('Task not found', 404);
        }

        $statusFile = $this->projectTaskFile($taskId, '.status');
        clearstatcache(true, $statusFile);
        if (!is_file($statusFile)) {
            return $this->error('Task not found', 404);
        }

        return $this->json([
            'success' => true,
            'data' => $this->readProjectTask($taskId, (int) $this->request->input('offset', 0)),
        ]);
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

        if (!in_array(($project['type'] ?? ''), ['hyperf', 'java', 'go'], true)) {
            return $this->json(['logs' => 'This project type does not have a managed process log.']);
        }

        try {
            $service = match ($project['type'] ?? '') {
                'java' => $this->javaService($project),
                'go' => $this->goService($project),
                default => $this->hyperfService($project),
            };
            return $this->json([
                'logs' => $this->runHostCommand('docker-compose logs --tail=200 ' . escapeshellarg($service)),
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
        if ($type === 'java') {
            $javaService = $hyperfService !== '' ? $hyperfService : $this->javaServiceName('project');
            return [$javaService, 'redis', 'mysql', 'rabbitmq'];
        }
        if ($type === 'go') {
            $goService = $hyperfService !== '' ? $hyperfService : $this->goServiceName('project');
            return [$goService];
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

        $pattern = '/(  nginx:\R(?:(?!^  [a-zA-Z0-9_-]+:).)*?    ports:\R)((?:      - .*\R)+)/ms';
        $newContent = preg_replace_callback($pattern, function (array $matches) use ($port): string {
            $ports = $matches[2];
            if (str_contains($ports, '"' . $port . ':' . $port . '"')) {
                return $matches[0];
            }

            return $matches[1] . rtrim($ports, "\r\n") . PHP_EOL . '      - "' . $port . ':' . $port . '"' . PHP_EOL;
        }, $content, 1, $count);
        if ($count !== 1 || $newContent === null) {
            throw new \RuntimeException('Unable to find nginx ports block in docker-compose.yml.');
        }

        $this->writeTextFile($composeFile, $newContent);
        return true;
    }

    private function ensureWebProjectRuntime(array $project): void
    {
        if (!$this->isNginxProject($project)) {
            return;
        }

        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($project['key'] ?? '')) ?: 'project';
        $type = (string) ($project['type'] ?? 'laravel');
        $path = $this->containerPath($project);
        $port = (int) ($project['port'] ?? 0);
        if ($port <= 0) {
            $port = 8002;
        }

        $sitesPath = $this->projectPath . '/services/nginx/sites';
        if (!is_dir($sitesPath) && !mkdir($sitesPath, 0775, true) && !is_dir($sitesPath)) {
            throw new \RuntimeException('Unable to create nginx sites directory.');
        }

        $siteFile = $sitesPath . '/' . $key . '.conf';
        $root = $type === 'static' ? $path : $this->joinContainerPath($path, 'public');
        $siteConfig = $this->generateSiteConfig($type, 'localhost', $root, $this->projectPhpService($project), $port);
        if (!is_file($siteFile)) {
            $this->writeTextFile($siteFile, $siteConfig);
        } elseif ((string) file_get_contents($siteFile) !== $siteConfig) {
            copy($siteFile, $siteFile . '.bak.' . date('YmdHis'));
            $this->writeTextFile($siteFile, $siteConfig);
        }

        $this->ensureNginxPort($port);
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
            '    command: sh -lc ' . $this->yamlSingleQuote(self::HYPERF_STARTUP_PREPARE . '; ' . self::HYPERF_ENTRY_CHECK . '; ' . $command),
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


    private function ensureJavaComposeService(string $service, string $path, int $hostPort, string $command, array $services): void
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $begin = '# BEGIN Java project: ' . $service;
        $end = '# END Java project: ' . $service;
        $block = $begin . PHP_EOL . $this->newJavaServiceConfig($service, $path, $hostPort, $command, $services) . $end;
        $pattern = '/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, PHP_EOL . $block, $content, 1) ?? $content;
        } else {
            $markerPattern = '/#-+\R# Docker [^\r\n]*(?:\R#-+[^\r\n]*)?/';
            if (preg_match($markerPattern, $content)) {
                $content = preg_replace($markerPattern, $block . PHP_EOL . PHP_EOL . '$0', $content, 1) ?? $content;
            } else {
                $javaMarker = '/\R#-+?\R# Java Development Environment\R#-+?\R/';
                if (!preg_match($javaMarker, $content)) {
                    throw new \RuntimeException('Unable to find docker-panel marker in docker-compose.yml.');
                }
                $content = preg_replace($javaMarker, PHP_EOL . $block . PHP_EOL . PHP_EOL . '$0', $content, 1) ?? $content;
            }
        }

        $this->writeTextFile($composeFile, rtrim($content) . PHP_EOL);
    }

    private function newJavaServiceConfig(string $service, string $path, int $hostPort, string $command, array $services): string
    {
        $services = $this->normalizeServices($services);
        $optionalDeps = array_values(array_intersect(['redis', 'mysql', 'rabbitmq'], $services));

        // Bake the host/app port into the command so docker-compose does not interpolate $VAR.
        $startup = 'export SERVER_PORT=' . $hostPort
            . '; export PORT=' . $hostPort
            . '; if [ -f pom.xml ]; then mvn spring-boot:run -Dspring-boot.run.arguments=--server.port=' . $hostPort
            . '; elif [ -f ./mvnw ]; then tr -d "\015" < ./mvnw > /tmp/project-mvnw && chmod +x /tmp/project-mvnw && sh /tmp/project-mvnw spring-boot:run -Dspring-boot.run.arguments=--server.port=' . $hostPort
            . '; elif [ -f ./gradlew ]; then tr -d "\015" < ./gradlew > /tmp/project-gradlew && chmod +x /tmp/project-gradlew && sh /tmp/project-gradlew bootRun --args=--server.port=' . $hostPort
            . '; else echo "No pom.xml, mvnw, or gradlew found"; exit 1; fi';
        // Keep a custom command if the user provided one; escape $ for compose interpolation.
        if ($command !== '' && $command !== self::JAVA_DEV_COMMAND) {
            $startup = 'export SERVER_PORT=' . $hostPort . '; export PORT=' . $hostPort . '; ' . str_replace('$', '$$', $command);
        }

        $envLines = [
            '      - TZ=${TIMEZONE}',
            '      - SERVER_PORT=' . $hostPort,
            '      - PORT=' . $hostPort,
            '      - JAVA_TOOL_OPTIONS=${JAVA_TOOL_OPTIONS}',
            '      - MAVEN_OPTS=${MAVEN_OPTS}',
        ];
        if (in_array('redis', $optionalDeps, true)) {
            $envLines[] = '      - REDIS_HOST=redis';
            $envLines[] = '      - REDIS_PORT=6379';
            $envLines[] = '      - REDIS_PASSWORD=${REDIS_PASSWORD}';
        }
        if (in_array('mysql', $optionalDeps, true)) {
            $envLines[] = '      - DB_HOST=mysql';
            $envLines[] = '      - DB_PORT=3306';
            $envLines[] = '      - DB_NAME=${MYSQL_DATABASE}';
            $envLines[] = '      - DB_USERNAME=root';
            $envLines[] = '      - DB_PASSWORD=${MYSQL_ROOT_PASSWORD}';
        }
        if (in_array('rabbitmq', $optionalDeps, true)) {
            $envLines[] = '      - RABBITMQ_HOST=rabbitmq';
            $envLines[] = '      - RABBITMQ_PORT=5672';
            $envLines[] = '      - RABBITMQ_USERNAME=guest';
            $envLines[] = '      - RABBITMQ_PASSWORD=guest';
        }

        $lines = [
            '',
            '  ' . $service . ':',
            '    build:',
            '      context: ./services/java',
            '      args:',
            '        - JAVA_VERSION=${JAVA_VERSION}',
            '        - MAVEN_VERSION=${MAVEN_VERSION}',
            '        - MAVEN_MIRROR=${MAVEN_MIRROR}',
            '        - PUID=${JAVA_PUID}',
            '        - PGID=${JAVA_PGID}',
            '        - CONTAINER_PROJECT_PATH=${CONTAINER_PROJECT_PATH}',
            '    working_dir: ' . $this->yamlSingleQuote($path),
            '    command: sh -lc ' . $this->yamlSingleQuote($startup),
            '    volumes:',
            '      - ${HOST_PROJECT_PATH}:${CONTAINER_PROJECT_PATH}',
            '      - maven-cache:/home/developer/.m2',
            '      - gradle-cache:/home/developer/.gradle',
            '    environment:',
        ];
        $lines = array_merge($lines, $envLines, [
            '    ports:',
            '      - "' . $hostPort . ':' . $hostPort . '"',
            '    extra_hosts:',
            '      - "dockerhost:${DOCKER_HOST_IP}"',
            '      - "host.docker.internal:host-gateway"',
        ]);
        if ($optionalDeps !== []) {
            $lines[] = '    depends_on:';
            foreach ($optionalDeps as $dep) {
                $lines[] = '      ' . $dep . ':';
                $lines[] = '        condition: service_healthy';
            }
        }        $lines = array_merge($lines, [
            '    tty: true',
            '    networks:',
            '      - frontend',
            '      - backend',
        ]);

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function ensureGoComposeService(string $service, string $path, int $hostPort, string $command, array $services): void
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $begin = '# BEGIN Go project: ' . $service;
        $end = '# END Go project: ' . $service;
        $block = $begin . PHP_EOL . $this->newGoServiceConfig($service, $path, $hostPort, $command, $services) . $end;
        $pattern = '/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '/s';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, PHP_EOL . $block, $content, 1) ?? $content;
        } else {
            $goMarker = '/\R#-+?\R# Go Development Environment\R#-+?\R/';
            if (!preg_match($goMarker, $content)) {
                throw new \RuntimeException('Unable to find Go marker in docker-compose.yml.');
            }
            $content = preg_replace($goMarker, PHP_EOL . $block . PHP_EOL . PHP_EOL . '$0', $content, 1) ?? $content;
        }

        $this->writeTextFile($composeFile, rtrim($content) . PHP_EOL);
    }

    private function newGoServiceConfig(string $service, string $path, int $hostPort, string $command, array $services): string
    {
        $services = $this->normalizeServices($services);
        $optionalDeps = array_values(array_intersect(['redis', 'mysql', 'rabbitmq'], $services));
        $startup = $command !== '' ? $command : self::GO_DEV_COMMAND;
        $startup = str_replace('$', '$$', $startup);

        $envLines = [
            '      - TZ=${TIMEZONE}',
            '      - GO111MODULE=on',
            '      - GOPROXY=${GOPROXY}',
            '      - CGO_ENABLED=${GO_CGO_ENABLED}',
            '      - PORT=8080',
            '      - SERVER_PORT=8080',
            '      - APP_PORT=8080',
        ];
        if (in_array('redis', $optionalDeps, true)) {
            $envLines[] = '      - REDIS_HOST=redis';
            $envLines[] = '      - REDIS_PORT=6379';
            $envLines[] = '      - REDIS_PASSWORD=${REDIS_PASSWORD}';
        }
        if (in_array('mysql', $optionalDeps, true)) {
            $envLines[] = '      - DB_HOST=mysql';
            $envLines[] = '      - DB_PORT=3306';
            $envLines[] = '      - DB_NAME=${MYSQL_DATABASE}';
            $envLines[] = '      - DB_USERNAME=root';
            $envLines[] = '      - DB_PASSWORD=${MYSQL_ROOT_PASSWORD}';
        }
        if (in_array('rabbitmq', $optionalDeps, true)) {
            $envLines[] = '      - RABBITMQ_HOST=rabbitmq';
            $envLines[] = '      - RABBITMQ_PORT=5672';
            $envLines[] = '      - RABBITMQ_USERNAME=guest';
            $envLines[] = '      - RABBITMQ_PASSWORD=guest';
        }

        $lines = [
            '',
            '  ' . $service . ':',
            '    build:',
            '      context: ./services/go',
            '      args:',
            '        - GO_VERSION=${GO_VERSION}',
            '        - GOPROXY=${GOPROXY}',
            '        - PUID=${GO_PUID}',
            '        - PGID=${GO_PGID}',
            '        - CONTAINER_PROJECT_PATH=${CONTAINER_PROJECT_PATH}',
            '        - INSTALL_DELVE=${GO_INSTALL_DELVE}',
            '        - CHANGE_SOURCE=${CHANGE_SOURCE}',
            '        - BASE_IMAGE_REGISTRY=${BASE_IMAGE_REGISTRY}',
            '    working_dir: ' . $this->yamlSingleQuote($path),
            '    command: sh -c ' . $this->yamlSingleQuote($startup),
            '    volumes:',
            '      - ${HOST_PROJECT_PATH}:${CONTAINER_PROJECT_PATH}',
            '      - go-mod-cache:/go/pkg/mod',
            '      - go-build-cache:/home/developer/.cache/go-build',
            '    environment:',
        ];
        $lines = array_merge($lines, $envLines, [
            '    ports:',
            '      - "' . $hostPort . ':8080"',
            '    extra_hosts:',
            '      - "dockerhost:${DOCKER_HOST_IP}"',
            '      - "host.docker.internal:host-gateway"',
        ]);
        if ($optionalDeps !== []) {
            $lines[] = '    depends_on:';
            foreach ($optionalDeps as $dep) {
                $lines[] = '      ' . $dep . ':';
                $lines[] = '        condition: service_healthy';
            }
        }
        $lines = array_merge($lines, [
            '    tty: true',
            '    networks:',
            '      - frontend',
            '      - backend',
        ]);

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
        $configExists = file_exists($this->configFile);
        if ($configExists) {
            $payload = json_decode((string) file_get_contents($this->configFile), true);
            if (!is_array($payload)) {
                throw new \RuntimeException('projects.json is not valid JSON.');
            }

            $projects = is_array($payload['projects'] ?? null) ? array_values(array_filter($payload['projects'], 'is_array')) : [];
        } else {
            $projects = $this->loadExampleProjects();
        }

        [$projects, $changed] = $this->mergeProjectsFromCompose($projects);
        if (!$configExists && $projects !== []) {
            $changed = true;
        }
        if ($changed) {
            $this->saveProjects($projects);
        }

        return $projects;
    }

    private function loadExampleProjects(): array
    {
        if (!file_exists($this->exampleFile)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($this->exampleFile), true);
        if (!is_array($payload) || !is_array($payload['projects'] ?? null)) {
            return [];
        }

        return array_values(array_filter($payload['projects'], 'is_array'));
    }

    private function mergeProjectsFromCompose(array $projects): array
    {
        $next = array_values(array_filter($projects, 'is_array'));
        $changed = false;

        foreach ($this->loadProjectsFromCompose() as $composeProject) {
            $found = false;
            foreach ($next as $index => $existing) {
                $sameKey = (string) ($existing['key'] ?? '') === (string) ($composeProject['key'] ?? '');
                $sameService = (string) ($existing['service'] ?? '') !== ''
                    && (string) ($existing['service'] ?? '') === (string) ($composeProject['service'] ?? '');

                if (!$sameKey && !$sameService) {
                    continue;
                }

                foreach (['type', 'path', 'port', 'php', 'service', 'command', 'log', 'url'] as $field) {
                    if (($existing[$field] ?? '') === '' && ($composeProject[$field] ?? '') !== '') {
                        $existing[$field] = $composeProject[$field];
                        $changed = true;
                    }
                }

                $services = $this->normalizeServices($existing['services'] ?? []);
                foreach ($this->normalizeServices($composeProject['services'] ?? []) as $service) {
                    if (!in_array($service, $services, true)) {
                        $services[] = $service;
                        $changed = true;
                    }
                }
                $existing['services'] = $services;
                $next[$index] = $existing;
                $found = true;
                break;
            }

            if (!$found) {
                $next[] = $composeProject;
                $changed = true;
            }
        }

        return [array_values($next), $changed];
    }

    private function loadProjectsFromCompose(): array
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        if (!file_exists($composeFile)) {
            return [];
        }

        $content = (string) file_get_contents($composeFile);
        if (!preg_match_all('/# BEGIN Hyperf project:\s*([a-zA-Z0-9_-]+)\R(.*?)# END Hyperf project:\s*\1/s', $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $projects = [];
        foreach ($matches as $match) {
            $project = $this->projectFromHyperfComposeBlock($match[1], $match[2]);
            if ($project !== null) {
                $projects[] = $project;
            }
        }

        return $projects;
    }

    private function projectFromHyperfComposeBlock(string $service, string $block): ?array
    {
        $path = $this->composeScalar($block, 'working_dir');
        if ($path === '') {
            return null;
        }

        $pathName = basename(rtrim(str_replace('\\', '/', $path), '/'));
        $fallbackKey = preg_replace('/^hyperf-/', '', $service) ?: $service;
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '-', $pathName !== '' ? $pathName : $fallbackKey) ?: $fallbackKey;
        $port = $this->composeHyperfPort($block) ?? 9502;
        $php = $this->phpVersionToService($this->composePhpVersion($block));
        $command = $this->composeCommand($block);

        return [
            'key' => $key,
            'name' => $key,
            'type' => 'hyperf',
            'path' => $path,
            'port' => $port,
            'php' => $php,
            'service' => $service,
            'services' => [$service, 'redis'],
            'command' => $command !== '' ? $command : self::HYPERF_DEV_COMMAND,
            'log' => 'runtime/logs/hyperf.log',
            'url' => 'http://localhost:' . $port,
        ];
    }

    private function composeScalar(string $block, string $key): string
    {
        if (!preg_match('/^\s+' . preg_quote($key, '/') . ':\s*(.+?)\s*$/m', $block, $match)) {
            return '';
        }

        return $this->unquoteComposeValue($match[1]);
    }

    private function composeCommand(string $block): string
    {
        $command = $this->composeScalar($block, 'command');
        if (str_starts_with($command, 'sh -lc ')) {
            $command = $this->unquoteComposeValue(substr($command, 7));
        }

        $prepare = self::HYPERF_STARTUP_PREPARE . '; ';
        if (str_starts_with($command, $prepare)) {
            $command = substr($command, strlen($prepare));
        }

        $entryCheck = self::HYPERF_ENTRY_CHECK . '; ';
        if (str_starts_with($command, $entryCheck)) {
            $command = substr($command, strlen($entryCheck));
        }

        return trim($command);
    }

    private function composeHyperfPort(string $block): ?int
    {
        if (preg_match('/^\s+-\s*["\']?(\d+):9501["\']?\s*$/m', $block, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    private function composePhpVersion(string $block): string
    {
        return preg_match('/-\s*PHP_VERSION=([0-9.]+)/', $block, $match) ? $match[1] : '8.3';
    }

    private function phpVersionToService(string $version): string
    {
        return match ($version) {
            '7.3' => 'php73-fpm',
            '8.0' => 'php80-fpm',
            '8.1' => 'php81-fpm',
            default => 'php83-fpm',
        };
    }

    private function unquoteComposeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === "'" || $quote === '"') && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        return str_replace("''", "'", $value);
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
        $serviceStatuses = [];

        foreach ($services as $service) {
            $matched = $this->findContainerForService($containers, $service);

            $isRunning = ($matched['state'] ?? '') === 'running';
            if ($isRunning) {
                $running++;
            }

            $serviceStatuses[] = [
                'name' => $service,
                'container' => $matched['name'] ?? '',
                'state' => $matched['state'] ?? 'missing',
                'status' => $matched['status'] ?? '未创建容器',
                'running' => $isRunning,
            ];
        }

        $project['services'] = $services;
        $project['status'] = [
            'servicesRunning' => $running,
            'servicesTotal' => count($services),
            'active' => count($services) > 0 && $running === count($services),
            'services' => $serviceStatuses,
        ];

        return $project;
    }

    private function findContainerForService(array $containers, string $service): ?array
    {
        if ($service === '') {
            return null;
        }

        $composeProject = basename($this->projectPath) ?: 'docker-develop';
        $fallback = null;
        $fallbackScore = -1;

        foreach ($containers as $container) {
            $name = (string) ($container['name'] ?? '');
            $labels = is_array($container['labels'] ?? null) ? $container['labels'] : [];
            $labelService = (string) ($labels['com.docker.compose.service'] ?? '');
            $labelProject = (string) ($labels['com.docker.compose.project'] ?? '');
            $score = -1;

            if ($labelService === $service && $labelProject === $composeProject) {
                return $container;
            }
            if ($labelService === $service) {
                $score = 80;
            } elseif ($name === $composeProject . '-' . $service . '-1' || $name === $composeProject . '_' . $service . '_1') {
                $score = 70;
            } elseif ($name === $service) {
                $score = 60;
            } elseif ($this->containerMatchesService($name, $service)) {
                $score = 10;
            }

            if ($score >= 0 && ($container['state'] ?? '') === 'running') {
                $score += 2;
            }
            if ($score > $fallbackScore) {
                $fallback = $container;
                $fallbackScore = $score;
            }
        }

        return $fallback;
    }

    private function containerMatchesService(string $containerName, string $service): bool
    {
        if ($containerName === '' || $service === '') {
            return false;
        }

        return $containerName === $service
            || str_contains($containerName, '-' . $service . '-')
            || str_contains($containerName, '_' . $service . '_')
            || str_ends_with($containerName, '-' . $service)
            || str_ends_with($containerName, '_' . $service);
    }

    private function projectServices(array $project): array
    {
        $services = $this->normalizeServices($project['services'] ?? []);
        if (($project['type'] ?? '') === 'hyperf') {
            $service = $this->hyperfService($project);
            $services = $this->normalizeProjectServicesForType('hyperf', $services, $service);
        }
        if (($project['type'] ?? '') === 'java') {
            $service = $this->javaService($project);
            $services = $this->normalizeProjectServicesForType('java', $services, $service);
        }
        if (($project['type'] ?? '') === 'go') {
            $service = $this->goService($project);
            $services = $this->normalizeProjectServicesForType('go', $services, $service);
        }
        if ($this->isNginxProject($project)) {
            $php = $this->projectPhpService($project);
            if (!in_array('nginx', $services, true)) {
                array_unshift($services, 'nginx');
            }
            if (($project['type'] ?? '') !== 'static' && !in_array($php, $services, true)) {
                $services[] = $php;
            }
        }

        return array_values(array_unique($services));
    }

    private function isNginxProject(array $project): bool
    {
        return in_array((string) ($project['type'] ?? ''), ['laravel', 'php-fpm', 'static'], true);
    }

    private function projectPhpService(array $project): string
    {
        $php = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['php'] ?? '')) ?: '';
        return $php !== '' ? $php : 'php83-fpm';
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
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $key) ?: 'project');
        return 'hyperf-' . $name;
    }

    private function javaService(array $project): string
    {
        $service = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['service'] ?? '')) ?: '';
        if ($service !== '') {
            return $service;
        }

        return $this->javaServiceName((string) ($project['key'] ?? 'project'));
    }

    private function javaServiceName(string $key): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $key) ?: 'project');
        return 'java-' . $name;
    }

    private function goService(array $project): string
    {
        $service = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['service'] ?? '')) ?: '';
        if ($service !== '') {
            return $service;
        }

        return $this->goServiceName((string) ($project['key'] ?? 'project'));
    }

    private function goServiceName(string $key): string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $key) ?: 'project');
        return 'go-' . $name;
    }

    private function commandService(array $project): string
    {
        if (($project['type'] ?? '') === 'hyperf') {
            return $this->hyperfService($project);
        }
        if (($project['type'] ?? '') === 'java') {
            return $this->javaService($project);
        }
        if (($project['type'] ?? '') === 'go') {
            return $this->goService($project);
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

    private function normalizeProjectServicesForType(string $type, mixed $services, string $dedicatedService = ''): array
    {
        $services = $this->normalizeServices($services);
        if (!in_array($type, ['hyperf', 'java', 'go'], true)) {
            return $services;
        }

        $prefix = match ($type) { 'hyperf' => 'hyperf-', 'java' => 'java-', default => 'go-' };
        $services = array_values(array_filter($services, static function (string $service) use ($prefix, $dedicatedService, $type): bool {
            if ($type === 'java' && $service === 'java') {
                return false;
            }
            if ($type === 'go' && $service === 'go') {
                return false;
            }
            if ($dedicatedService !== '' && $service === $dedicatedService) {
                return true;
            }
            return !str_starts_with($service, $prefix);
        }));

        if ($dedicatedService !== '' && !in_array($dedicatedService, $services, true)) {
            array_unshift($services, $dedicatedService);
        }

        return array_values(array_unique($services));
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
            'java' => [
                'java-version' => 'java -version',
                'maven-version' => 'mvn -v',
                'spring-boot-run' => self::JAVA_DEV_COMMAND,
                'package' => 'if [ -f ./mvnw ]; then sed -i "s/\r$//" ./mvnw 2>/dev/null || true; chmod +x ./mvnw 2>/dev/null || true; sh ./mvnw -DskipTests package; elif [ -f pom.xml ]; then mvn -DskipTests package; else echo "No Maven project found"; exit 1; fi',
                'test' => 'if [ -f ./mvnw ]; then sed -i "s/\r$//" ./mvnw 2>/dev/null || true; chmod +x ./mvnw 2>/dev/null || true; sh ./mvnw test; elif [ -f pom.xml ]; then mvn test; else echo "No Maven project found"; exit 1; fi',
            ],
            'go' => [
                'go-version' => 'go version',
                'go-env' => 'go env GOPROXY GOOS GOARCH',
                'go-mod-download' => 'go mod download',
                'build' => 'go build ./...',
                'test' => 'go test ./...',
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

    private function createProjectActionTask(string $key, string $action)
    {
        if (!in_array($action, ['start', 'restart'], true)) {
            return $this->error('Unsupported project action', 400);
        }

        $project = $this->findProject($key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        $this->ensureWebProjectRuntime($project);
        $services = $this->projectServices($project);
        if ($services === []) {
            return $this->error('Project does not have services to start.', 400);
        }

        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $key) ?: 'project';
        $taskId = date('YmdHis') . '-' . $safeKey . '-' . bin2hex(random_bytes(3));
        $this->ensureProjectTaskDir();

        $logFile = $this->projectTaskFile($taskId, '.log');
        $statusFile = $this->projectTaskFile($taskId, '.status');
        $exitFile = $this->projectTaskFile($taskId, '.exit');
        $startedFile = $this->projectTaskFile($taskId, '.started');
        $finishedFile = $this->projectTaskFile($taskId, '.finished');
        $scriptFile = $this->projectTaskFile($taskId, '.sh');

        file_put_contents($statusFile, 'queued');
        file_put_contents($startedFile, (string) time());
        file_put_contents($logFile, '[' . date('H:i:s') . "] 已创建后台任务，正在准备执行。\n");

        $script = $this->buildProjectTaskScript(
            $taskId,
            $action,
            $project,
            $services,
            $logFile,
            $statusFile,
            $exitFile,
            $startedFile,
            $finishedFile
        );
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0755);
        $pid = $this->launchDetachedScript($scriptFile);

        return $this->success([
            'taskId' => $taskId,
            'status' => 'queued',
            'pid' => $pid,
            'services' => $services,
        ], 'Project task started');
    }

    private function ensureProjectTaskDir(): string
    {
        $dir = BASE_PATH . '/runtime/tasks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create task runtime directory.');
        }

        return $dir;
    }

    private function normalizeTaskId(string $taskId): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '', $taskId) ?? '';
    }

    private function projectTaskFile(string $taskId, string $suffix): string
    {
        return $this->ensureProjectTaskDir() . '/' . $this->normalizeTaskId($taskId) . $suffix;
    }

    private function readProjectTask(string $taskId, int $offset): array
    {
        $logFile = $this->projectTaskFile($taskId, '.log');
        clearstatcache();
        $status = trim((string) @file_get_contents($this->projectTaskFile($taskId, '.status'))) ?: 'running';
        $exitText = trim((string) @file_get_contents($this->projectTaskFile($taskId, '.exit')));
        $startedAt = (int) trim((string) @file_get_contents($this->projectTaskFile($taskId, '.started')));
        $finishedAt = (int) trim((string) @file_get_contents($this->projectTaskFile($taskId, '.finished')));
        if ($startedAt <= 0) {
            $startedAt = time();
        }

        $size = is_file($logFile) ? (int) filesize($logFile) : 0;
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        $output = '';
        if ($size > 0) {
            $handle = fopen($logFile, 'rb');
            if ($handle !== false) {
                fseek($handle, $offset);
                $output = (string) stream_get_contents($handle);
                fclose($handle);
            }
        }

        return [
            'taskId' => $taskId,
            'status' => $status,
            'exitCode' => $exitText === '' ? null : (int) $exitText,
            'offset' => $size,
            'output' => $output,
            'elapsed' => max(0, ($finishedAt > 0 ? $finishedAt : time()) - $startedAt),
            'finishedAt' => $finishedAt > 0 ? $finishedAt : null,
        ];
    }

    private function launchDetachedScript(string $scriptFile): string
    {
        $output = [];
        $returnVar = 0;
        exec('sh ' . escapeshellarg($scriptFile) . ' >/dev/null 2>&1 & echo $!', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new \RuntimeException('Unable to launch project task.');
        }

        return trim((string) ($output[0] ?? ''));
    }

    private function buildProjectTaskScript(
        string $taskId,
        string $action,
        array $project,
        array $services,
        string $logFile,
        string $statusFile,
        string $exitFile,
        string $startedFile,
        string $finishedFile
    ): string {
        $serviceText = implode(' ', array_map('escapeshellarg', $services));
        $prefix = 'cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0 ' . $this->composeHostEnvPrefix();
        $upNoBuildCommand = $prefix . 'docker-compose up -d --no-build ' . $serviceText;
        $buildPlainCommand = $prefix . 'docker-compose build ' . $serviceText;
        $upCommand = $prefix . 'docker-compose up -d ' . $serviceText;
        $psCommand = $prefix . 'docker-compose ps ' . $serviceText;
        $runningServicesCommand = $prefix . 'docker-compose ps --services --status running ' . $serviceText;
        $logsCommand = $prefix . 'docker-compose logs --tail=80 ' . $serviceText;
        $stopTarget = match ($project['type'] ?? '') {
            'java' => $this->javaService($project),
            'go' => $this->goService($project),
            default => $this->hyperfService($project),
        };
        $stopCommand = $prefix . 'docker-compose stop ' . escapeshellarg($stopTarget);
        $projectName = (string) ($project['name'] ?? $project['key'] ?? $taskId);
        $actionName = $action === 'restart' ? '重启' : '启动';

        $lines = [
            '#!/bin/sh',
            'LOG_FILE=' . escapeshellarg($logFile),
            'STATUS_FILE=' . escapeshellarg($statusFile),
            'EXIT_FILE=' . escapeshellarg($exitFile),
            'STARTED_FILE=' . escapeshellarg($startedFile),
            'FINISHED_FILE=' . escapeshellarg($finishedFile),
            'mark_status() { printf "%s" "$1" > "$STATUS_FILE"; }',
            'log() { printf "[%s] %s\n" "$(date +%H:%M:%S)" "$*" >> "$LOG_FILE"; }',
            'run_step() {',
            '  log ""',
            '  log "$ $1"',
            '  sh -lc "$1" >> "$LOG_FILE" 2>&1',
            '  code=$?',
            '  if [ "$code" -ne 0 ]; then log "命令失败，退出码：$code"; fi',
            '  return "$code"',
            '}',
            'image_source_advice() {',
            '  if grep -qiE "manifest unknown|unknown blob|tls: first record|context deadline exceeded|Client.Timeout|registry-1.docker.io|pull access denied" "$LOG_FILE"; then',
            '    log "Image source hint: Aliyun mirror accelerator belongs in Docker Desktop Docker Engine registry-mirrors."',
            '    log "Keep BASE_IMAGE_REGISTRY/PHP_BASE_IMAGE_REGISTRY empty unless the value is a real image namespace containing the required tags."',
            '    log "Do not put https://*.mirror.aliyuncs.com into .env as a Dockerfile FROM prefix."',
            '  fi',
            '}',
            'verify_services() {',
            '  check_command="$1"',
            '  shift',
            '  check_file="${LOG_FILE}.running"',
            '  : > "$check_file"',
            '  log ""',
            '  log "$ $check_command"',
            '  if ! sh -lc "$check_command" > "$check_file" 2>> "$LOG_FILE"; then',
            '    cat "$check_file" >> "$LOG_FILE"',
            '    rm -f "$check_file"',
            '    log "运行状态检查失败。"',
            '    return 1',
            '  fi',
            '  cat "$check_file" >> "$LOG_FILE"',
            '  missing=0',
            '  for service in "$@"; do',
            '    if ! grep -qx "$service" "$check_file"; then',
            '      log "服务未保持运行：$service"',
            '      missing=1',
            '    fi',
            '  done',
            '  rm -f "$check_file"',
            '  return "$missing"',
            '}',
            'finish() {',
            '  code="$1"',
            '  printf "%s" "$code" > "$EXIT_FILE"',
            '  date +%s > "$FINISHED_FILE"',
            '  if [ "$code" -eq 0 ]; then mark_status success; log "任务完成。"; else mark_status failed; log "任务失败，请查看上方最后一段错误。"; fi',
            '  exit "$code"',
            '}',
            'mark_status running',
            'date +%s > "$STARTED_FILE"',
            'log ' . escapeshellarg($actionName . '项目：' . $projectName),
            'log ' . escapeshellarg('服务列表：' . implode(', ', $services)),
        ];

        if ($action === 'restart' && in_array(($project['type'] ?? ''), ['hyperf', 'java', 'go'], true)) {
            $lines[] = 'log "阶段 1/5：停止旧的项目容器"';
            $lines[] = 'run_step ' . escapeshellarg($stopCommand) . ' || true';
            $directStage = '阶段 2/5：尝试直接启动已有镜像';
            $buildStage = '阶段 3/5：构建项目镜像';
            $upStage = '阶段 4/5：启动项目服务';
            $logsStage = '阶段 5/5：检查运行状态并读取最近日志';
        } else {
            $directStage = '阶段 1/4：尝试直接启动已有镜像';
            $buildStage = '阶段 2/4：构建项目镜像';
            $upStage = '阶段 3/4：启动项目服务';
            $logsStage = '阶段 4/4：检查运行状态并读取最近日志';
        }

        $appendSuccessCheck = function (bool $finishOnFailure = true) use (&$lines, $psCommand, $runningServicesCommand, $logsCommand, $serviceText, $logsStage): void {
            $lines[] = 'log "项目服务已启动，等待容器稳定..."';
            $lines[] = 'sleep 3';
            $lines[] = 'run_step ' . escapeshellarg($psCommand) . ' || true';
            $lines[] = 'log ' . escapeshellarg($logsStage);
            $lines[] = 'if ! verify_services ' . escapeshellarg($runningServicesCommand) . ' ' . $serviceText . '; then';
            $lines[] = '  log "服务没有全部保持运行，开始读取最近容器日志。"';
            $lines[] = '  run_step ' . escapeshellarg($logsCommand) . ' || true';
            if ($finishOnFailure) {
                $lines[] = '  finish 1';
            } else {
                $lines[] = '  log "直接启动没有跑稳，将继续重新构建项目镜像。"';
            }
            $lines[] = 'else';
            $lines[] = '  run_step ' . escapeshellarg($logsCommand) . ' || true';
            $lines[] = '  finish 0';
            $lines[] = 'fi';
        };

        $lines[] = 'log ' . escapeshellarg($directStage);
        $lines[] = 'if run_step ' . escapeshellarg($upNoBuildCommand) . '; then';
        $appendSuccessCheck(false);
        $lines[] = 'fi';
        $lines[] = 'log "直接启动没有成功，通常是镜像尚未构建、基础镜像需要拉取，或 compose 配置需要重新生成。"';
        $lines[] = 'log ' . escapeshellarg($buildStage);
        $lines[] = 'if ! run_step ' . escapeshellarg($buildPlainCommand) . '; then';
        $lines[] = '  image_source_advice';
        $lines[] = '  finish 1';
        $lines[] = 'fi';
        $lines[] = 'log ' . escapeshellarg($upStage);
        $lines[] = 'if ! run_step ' . escapeshellarg($upCommand) . '; then';
        $lines[] = '  run_step ' . escapeshellarg($logsCommand) . ' || true';
        $lines[] = '  image_source_advice';
        $lines[] = '  finish 1';
        $lines[] = 'fi';
        $appendSuccessCheck();

        return implode("\n", $lines) . "\n";
    }
    private function runHostCommand(string $command): string
    {
        $output = [];
        $returnVar = 0;
        exec('cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0 ' . $this->composeHostEnvPrefix() . $command . ' 2>&1', $output, $returnVar);
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
        $assignments[] = 'DOCKER_DEVELOP_HOST_PATH=' . escapeshellarg($hostProjectRoot);
        $registry = trim((string) ($env['BASE_IMAGE_REGISTRY'] ?? ''));
        if ($registry !== '') {
            $assignments[] = 'BASE_IMAGE_REGISTRY=' . escapeshellarg($registry);
        }
        $phpRegistry = trim((string) ($env['PHP_BASE_IMAGE_REGISTRY'] ?? ''));
        if ($phpRegistry !== '') {
            $assignments[] = 'PHP_BASE_IMAGE_REGISTRY=' . escapeshellarg($phpRegistry);
        }


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

    private function imageSourceAdvice(string $message): ?string
    {
        $lower = strtolower($message);
        $needles = [
            'manifest unknown',
            'unknown blob',
            'tls: first record',
            'context deadline exceeded',
            'client.timeout',
            'registry-1.docker.io',
            'pull access denied',
        ];

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return 'Image source issue: configure Aliyun as Docker Desktop Docker Engine registry-mirrors, keep BASE_IMAGE_REGISTRY/PHP_BASE_IMAGE_REGISTRY empty, and do not use https://*.mirror.aliyuncs.com as a Dockerfile FROM prefix.';
            }
        }

        return null;
    }

    private function friendlyCommandError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Command failed.';
        }

        $imageAdvice = $this->imageSourceAdvice($message);
        if ($imageAdvice !== null) {
            return $message . "\n\n" . $imageAdvice;
        }

        if (str_contains($message, 'No such service')) {
            return $message . "\n\nNo docker-compose service was found for this project. Check docker-compose.yml or regenerate the project service from the panel.";
        }

        if (str_contains($message, 'No such image') || str_contains($message, 'pull access denied')) {
            return $message . "\n\nThe image is not built or cannot be pulled. The panel will build automatically when the base images are reachable.";
        }

        return $message;
    }
}
