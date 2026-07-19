<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DockerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * 环境预设管理控制器
 */
#[Controller]
class EnvironmentController extends AbstractController
{
    #[Inject]
    protected DockerService $docker;

    // 项目路径
    private string $projectPath = '/var/www/docker-develop';

    // 预设环境配置
    private array $presets = [
        'php73' => [
            'name' => 'PHP 7.3 开发环境',
            'description' => 'Nginx + PHP 7.3 + Redis',
            'services' => ['nginx', 'php73-fpm', 'redis'],
            'icon' => '🐘',
        ],
        'php80' => [
            'name' => 'PHP 8.0 开发环境',
            'description' => 'Nginx + PHP 8.0 + Redis',
            'services' => ['nginx', 'php80-fpm', 'redis'],
            'icon' => '🐘',
        ],
        'php81' => [
            'name' => 'PHP 8.1 开发环境',
            'description' => 'Nginx + PHP 8.1 + Redis',
            'services' => ['nginx', 'php81-fpm', 'redis'],
            'icon' => '🐘',
        ],
        'php83' => [
            'name' => 'PHP 8.3 开发环境',
            'description' => 'Nginx + PHP 8.3 + Redis (最新)',
            'services' => ['nginx', 'php83-fpm', 'redis'],
            'icon' => '🚀',
        ],
        'minimal' => [
            'name' => '默认开发环境',
            'description' => 'Nginx + PHP 8.3 + Redis',
            'services' => ['nginx', 'php83-fpm', 'redis'],
            'icon' => '⚡',
        ],
        'go' => [
            'name' => 'Go Dev',
            'description' => 'Go toolchain + Redis',
            'services' => ['go', 'redis'],
            'icon' => 'Go',
        ],
    ];

    /**
     * 验证登录
     */
    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    /**
     * 根据名称关键字查找容器
     */
    private function findContainerByName(array $containers, string $keyword): ?array
    {
        foreach ($containers as $c) {
            if (strpos($c['name'], $keyword) !== false) {
                return $c;
            }
        }
        return null;
    }

    /**
     * 执行 docker-compose 命令
     */
    private function runDockerCompose(string $args): array
    {
        $cmd = 'cd ' . escapeshellarg($this->projectPath) . " && unset PHP_VERSION && docker-compose {$args} 2>&1";
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);
        return [
            'success' => $returnVar === 0,
            'output' => implode("\n", $output),
            'code' => $returnVar,
        ];
    }

    /**
     * 获取所有预设环境
     */
    #[RequestMapping(path: '/api/env/presets', methods: 'GET')]
    public function listPresets()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $presets = [];
        foreach ($this->presets as $key => $preset) {
            $presets[] = array_merge(['key' => $key], $preset);
        }

        return $this->json(['presets' => $presets]);
    }

    /**
     * 启动预设环境 (支持自动创建)
     */
    #[RequestMapping(path: '/api/env/start/{preset}', methods: 'POST')]
    public function startPreset(string $preset)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        if (!isset($this->presets[$preset])) {
            return $this->error('预设不存在', 404);
        }

        $config = $this->presets[$preset];
        $services = implode(' ', $config['services']);

        // 使用 docker-compose up -d 启动（会自动创建不存在的容器）
        $result = $this->runDockerCompose("up -d {$services}");

        if ($result['success']) {
            return $this->success([
                'preset' => $preset,
                'name' => $config['name'],
                'output' => $result['output'],
            ], '环境已启动');
        } else {
            return $this->error('启动失败: ' . $result['output'], 500);
        }
    }

    /**
     * 停止所有非必要容器
     */
    #[RequestMapping(path: '/api/env/stop-all', methods: 'POST')]
    public function stopAll()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $containers = $this->docker->getContainers(false);
            $results = [];

            $excludeKeywords = ['docker-panel'];

            foreach ($containers as $c) {
                $shouldExclude = false;
                foreach ($excludeKeywords as $keyword) {
                    if (strpos($c['name'], $keyword) !== false) {
                        $shouldExclude = true;
                        break;
                    }
                }

                if (!$shouldExclude && $c['state'] === 'running') {
                    try {
                        $this->docker->stopContainer($c['id']);
                        $results[$c['name']] = 'stopped';
                    } catch (\Throwable $e) {
                        $results[$c['name']] = 'error';
                    }
                }
            }

            return $this->success([
                'results' => $results
            ], '已停止所有开发容器');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 获取当前运行状态
     */
    #[RequestMapping(path: '/api/env/status', methods: 'GET')]
    public function getStatus()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $containers = $this->docker->getContainers(true);

            $presetStatus = [];
            foreach ($this->presets as $key => $preset) {
                $running = 0;
                $total = count($preset['services']);

                foreach ($preset['services'] as $name) {
                    $container = $this->findContainerByName($containers, $name);
                    if ($container && $container['state'] === 'running') {
                        $running++;
                    }
                }

                $presetStatus[$key] = [
                    'running' => $running,
                    'total' => $total,
                    'active' => $running === $total,
                ];
            }

            return $this->json(['status' => $presetStatus]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 获取本机开发环境配置体检结果。
     */
    #[RequestMapping(path: '/api/env/doctor', methods: 'GET')]
    public function doctor()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $envFile = $this->projectPath . '/.env';
            $env = $this->readDotEnv($envFile);
            $checks = [];

            $checks[] = [
                'title' => '.env 配置',
                'status' => file_exists($envFile) ? 'ok' : 'error',
                'badge' => file_exists($envFile) ? '已加载' : '缺失',
                'message' => file_exists($envFile) ? '.env 已存在，面板会按本机配置运行。' : '缺少 .env，无法读取本机路径和端口配置。',
                'suggestion' => file_exists($envFile) ? '' : '复制 .env.example 为 .env，或运行 start-panel.bat 自动创建。',
            ];

            $hostProjectPath = trim((string) ($env['HOST_PROJECT_PATH'] ?? ''));
            $containerProjectPath = trim((string) ($env['CONTAINER_PROJECT_PATH'] ?? '/develop'));
            $hostPathStatus = $hostProjectPath !== '' ? 'ok' : 'error';
            $hostPathMessage = $hostProjectPath !== ''
                ? '宿主机项目根目录：' . $hostProjectPath . '，容器内路径：' . $containerProjectPath . '。'
                : 'HOST_PROJECT_PATH 未配置，项目路径无法映射到容器。';
            if ($hostProjectPath !== '' && !$this->isRelativeHostPath($hostProjectPath) && !$this->looksLikeAbsoluteHostPath($hostProjectPath)) {
                $hostPathStatus = 'warn';
                $hostPathMessage = 'HOST_PROJECT_PATH 看起来不是常见的绝对路径或相对路径：' . $hostProjectPath;
            }
            $checks[] = [
                'title' => '项目路径映射',
                'status' => $hostPathStatus,
                'badge' => $containerProjectPath !== '' ? $containerProjectPath : '未配置',
                'message' => $hostPathMessage,
                'suggestion' => $hostPathStatus === 'ok' ? '面板里建议填写 /develop/... 这种容器路径。' : '把 HOST_PROJECT_PATH 改成业务项目共同父目录，例如 D:\\Develop 或 E:\\Work。',
            ];

            $defaultPhp = trim((string) ($env['PHP_VERSION'] ?? ''));
            $workspacePhp = trim((string) ($env['WORKSPACE_PHP_VERSION'] ?? ''));
            $upstream = trim((string) ($env['NGINX_PHP_UPSTREAM_CONTAINER'] ?? ''));
            $phpOk = $defaultPhp === '8.3' && $workspacePhp === '8.3' && $upstream === 'php83-fpm';
            $checks[] = [
                'title' => '默认 PHP',
                'status' => $phpOk ? 'ok' : 'warn',
                'badge' => $defaultPhp !== '' ? 'PHP ' . $defaultPhp : '未配置',
                'message' => 'php-fpm=' . ($defaultPhp ?: '-') . '，workspace=' . ($workspacePhp ?: '-') . '，Nginx upstream=' . ($upstream ?: '-') . '。',
                'suggestion' => $phpOk ? '默认版本已统一到 PHP 8.3。' : '建议新人默认使用 PHP 8.3，旧项目再单独选择 php81-fpm/php80-fpm/php73-fpm。',
            ];

            $changeSource = trim((string) ($env['CHANGE_SOURCE'] ?? ''));
            $checks[] = [
                'title' => '系统软件源',
                'status' => $changeSource === 'true' ? 'ok' : 'warn',
                'badge' => $changeSource === 'true' ? '已加速' : '默认源',
                'message' => $changeSource === 'true' ? 'CHANGE_SOURCE=true，构建镜像时会使用国内软件源。' : 'CHANGE_SOURCE 未开启。',
                'suggestion' => $changeSource === 'true' ? '构建 PHP / Nginx / Workspace 镜像时更适合国内网络。' : '国内网络建议设置 CHANGE_SOURCE=true。',
            ];

            $composerRepo = trim((string) ($env['WORKSPACE_COMPOSER_REPO_PACKAGIST'] ?? ''));
            $checks[] = [
                'title' => 'Composer 源',
                'status' => $composerRepo !== '' ? 'ok' : 'warn',
                'badge' => $composerRepo !== '' ? '已配置' : '默认源',
                'message' => $composerRepo !== '' ? $composerRepo : '未配置 Composer 镜像源。',
                'suggestion' => $composerRepo !== '' ? '国内安装依赖会优先使用镜像源。' : '国内网络建议配置 https://mirrors.aliyun.com/composer/。',
            ];

            $goProxy = trim((string) ($env['GOPROXY'] ?? ''));
            $checks[] = [
                'title' => 'Go Proxy',
                'status' => $goProxy !== '' ? 'ok' : 'warn',
                'badge' => $goProxy !== '' ? '已配置' : '未配置',
                'message' => $goProxy !== '' ? $goProxy : '未配置 GOPROXY。',
                'suggestion' => $goProxy !== '' ? 'Go 模块下载会使用代理配置。' : 'Go 项目建议设置 GOPROXY=https://goproxy.cn,direct。',
            ];

            $compose = $this->runDockerCompose('config --quiet');
            $checks[] = [
                'title' => 'Compose 配置',
                'status' => $compose['success'] ? 'ok' : 'error',
                'badge' => $compose['success'] ? '通过' : '失败',
                'message' => $compose['success'] ? 'docker-compose config --quiet 校验通过。' : ($compose['output'] ?: 'docker-compose config 校验失败。'),
                'suggestion' => $compose['success'] ? 'Compose 文件可以被解析。' : '优先检查 .env 路径、端口和 docker-compose.yml 语法。',
            ];

            return $this->json([
                'checks' => $checks,
                'summary' => [
                    'hostProjectPath' => $hostProjectPath,
                    'containerProjectPath' => $containerProjectPath,
                    'phpVersion' => $defaultPhp,
                    'workspacePhpVersion' => $workspacePhp,
                    'nginxPhpUpstream' => $upstream,
                    'composerRepo' => $composerRepo,
                    'goProxy' => $goProxy,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function readDotEnv(string $file): array
    {
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

    private function isRelativeHostPath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }

    private function looksLikeAbsoluteHostPath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1;
    }
}
