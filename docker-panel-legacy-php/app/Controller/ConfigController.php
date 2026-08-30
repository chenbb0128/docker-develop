<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * 配置文件管理控制器
 */
#[Controller]
class ConfigController extends AbstractController
{
    // 项目根目录
    private string $projectPath = '/var/www/docker-develop';

    // 允许编辑的文件列表
    private array $allowedFiles = [
        'docker-compose.yml' => '服务编排配置',
        'PROJECTS.md' => 'Project launcher guide',
        'projects.json' => 'Project launcher config',
        'projects.example.json' => 'Project launcher example',
        '.env' => '环境变量',
        '.env.example' => '环境变量示例',
        'Makefile' => 'Make 命令',
        'services/nginx/nginx.conf' => 'Nginx 主配置',
        'services/php-fpm/php7.3.ini' => 'PHP 7.3 配置',
        'services/php-fpm/php8.0.ini' => 'PHP 8.0 配置',
        'services/php-fpm/php8.1.ini' => 'PHP 8.1 配置',
        'services/php-fpm/php8.3.ini' => 'PHP 8.3 配置',
        'services/php-fpm/conf.d/opcache.ini' => 'OPcache 配置',
        'services/php-fpm/conf.d/xdebug.ini' => 'Xdebug 配置',
        'services/redis/redis.conf' => 'Redis 配置',
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
     * 获取可编辑文件列表
     */
    #[RequestMapping(path: '/api/config/files', methods: 'GET')]
    public function listFiles()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $files = [];
        foreach ($this->allowedFiles as $path => $desc) {
            $fullPath = $this->projectPath . '/' . $path;
            $files[] = [
                'path' => $path,
                'description' => $desc,
                'exists' => file_exists($fullPath),
                'size' => file_exists($fullPath) ? filesize($fullPath) : 0,
                'modified' => file_exists($fullPath) ? date('Y-m-d H:i:s', filemtime($fullPath)) : null,
            ];
        }

        return $this->json(['files' => $files]);
    }

    /**
     * 读取文件内容
     */
    #[RequestMapping(path: '/api/config/read', methods: 'GET')]
    public function readFile()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = $this->request->query('path', '');

        // 安全检查
        if (!isset($this->allowedFiles[$path])) {
            return $this->error('不允许访问此文件', 403);
        }

        $fullPath = $this->projectPath . '/' . $path;

        if (!file_exists($fullPath)) {
            return $this->error('文件不存在', 404);
        }

        $content = file_get_contents($fullPath);

        return $this->json([
            'path' => $path,
            'content' => $content,
            'size' => strlen($content),
        ]);
    }

    /**
     * 保存文件内容
     */
    #[RequestMapping(path: '/api/config/save', methods: 'POST')]
    public function saveFile()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = $this->request->input('path', '');
        $content = $this->request->input('content', '');

        // 安全检查
        if (!isset($this->allowedFiles[$path])) {
            return $this->error('不允许修改此文件', 403);
        }

        $fullPath = $this->projectPath . '/' . $path;

        // 创建备份
        if (file_exists($fullPath)) {
            $backupPath = $fullPath . '.bak.' . date('YmdHis');
            copy($fullPath, $backupPath);
        }

        // 保存文件
        $result = file_put_contents($fullPath, $content);

        if ($result === false) {
            return $this->error('保存失败', 500);
        }

        return $this->success([
            'path' => $path,
            'size' => $result,
        ], '文件已保存');
    }

    /**
     * 重新加载 Docker Compose (验证配置)
     */
    #[RequestMapping(path: '/api/config/validate', methods: 'POST')]
    public function validateConfig()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        // 执行 docker-compose config 验证
        $output = [];
        $returnVar = 0;
        exec('cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && docker-compose config 2>&1', $output, $returnVar);

        if ($returnVar === 0) {
            return $this->success(['output' => implode("\n", $output)], '配置验证通过');
        } else {
            return $this->error('配置验证失败: ' . implode("\n", $output), 400);
        }
    }
}
