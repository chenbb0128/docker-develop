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
            'description' => 'Nginx + 默认 PHP + Redis',
            'services' => ['nginx', 'php-fpm', 'redis'],
            'icon' => '⚡',
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
        $cmd = "cd {$this->projectPath} && docker-compose {$args} 2>&1";
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
}
