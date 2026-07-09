<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

#[Controller]
class ConfigCenterController extends AbstractController
{
    private string $projectPath = '/var/www/docker-develop';

    private array $knownFiles = [
        'docker-compose.yml' => [
            'description' => '服务编排配置',
            'group' => '核心配置',
            'risk' => 'high',
            'validator' => 'compose',
            'nextAction' => '保存后建议验证 Compose，并重启受影响的容器。',
            'applyAction' => 'restart-containers',
        ],
        '.env' => [
            'description' => '本地环境变量',
            'group' => '核心配置',
            'risk' => 'high',
            'validator' => 'env',
            'nextAction' => '保存后需要重启相关容器，环境变量才会重新加载。',
            'applyAction' => 'restart-containers',
        ],
        '.env.example' => [
            'description' => '环境变量示例',
            'group' => '核心配置',
            'risk' => 'low',
            'validator' => 'env',
            'nextAction' => '这是示例文件，保存后通常不需要重启。',
            'applyAction' => 'none',
        ],
        'Makefile' => [
            'description' => 'Make 命令',
            'group' => '核心配置',
            'risk' => 'medium',
            'validator' => 'text',
            'nextAction' => '保存后新的 make 命令会立即生效。',
            'applyAction' => 'none',
        ],
        'projects.json' => [
            'description' => '项目启动器配置',
            'group' => '项目配置',
            'risk' => 'medium',
            'validator' => 'json',
            'nextAction' => '保存后刷新项目列表即可。',
            'applyAction' => 'refresh-projects',
        ],
        'projects.example.json' => [
            'description' => '项目启动器示例',
            'group' => '项目配置',
            'risk' => 'low',
            'validator' => 'json',
            'nextAction' => '这是示例文件，保存后通常不需要额外操作。',
            'applyAction' => 'none',
        ],
        'PROJECTS.md' => [
            'description' => '项目启动器说明',
            'group' => '项目配置',
            'risk' => 'low',
            'validator' => 'text',
            'nextAction' => '文档修改会立即生效。',
            'applyAction' => 'none',
        ],
        'services/nginx/nginx.conf' => [
            'description' => 'Nginx 主配置',
            'group' => 'Nginx 配置',
            'risk' => 'high',
            'validator' => 'nginx',
            'nextAction' => '保存后建议验证 Nginx，再重载 Nginx。',
            'applyAction' => 'reload-nginx',
        ],
        'services/php-fpm/php7.3.ini' => [
            'description' => 'PHP 7.3 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启 php73-fpm 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/php-fpm/php8.0.ini' => [
            'description' => 'PHP 8.0 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启 php80-fpm 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/php-fpm/php8.1.ini' => [
            'description' => 'PHP 8.1 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启 php81-fpm 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/php-fpm/php8.3.ini' => [
            'description' => 'PHP 8.3 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启 php83-fpm 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/php-fpm/conf.d/opcache.ini' => [
            'description' => 'OPcache 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启相关 PHP-FPM 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/php-fpm/conf.d/xdebug.ini' => [
            'description' => 'Xdebug 配置',
            'group' => 'PHP 配置',
            'risk' => 'medium',
            'validator' => 'ini',
            'nextAction' => '保存后重启相关 PHP-FPM 容器。',
            'applyAction' => 'restart-php',
        ],
        'services/redis/redis.conf' => [
            'description' => 'Redis 配置',
            'group' => 'Redis 配置',
            'risk' => 'medium',
            'validator' => 'text',
            'nextAction' => '保存后重启 Redis 容器。',
            'applyAction' => 'restart-redis',
        ],
    ];

    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    #[RequestMapping(path: '/api/config/center/files', methods: 'GET')]
    public function files()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $files = [];
        foreach (array_keys($this->knownFiles) as $path) {
            $files[] = $this->fileInfo($path);
        }
        foreach ($this->siteConfigPaths() as $path) {
            $files[] = $this->fileInfo($path);
        }

        usort($files, fn(array $a, array $b) => [$a['groupOrder'], $a['path']] <=> [$b['groupOrder'], $b['path']]);

        return $this->json([
            'files' => $files,
            'groups' => $this->groups(),
        ]);
    }

    #[RequestMapping(path: '/api/config/center/read', methods: 'GET')]
    public function read()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->query('path', ''));
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许访问此文件', 403);
        }

        $fullPath = $this->fullPath($path);
        if (!is_file($fullPath)) {
            return $this->error('文件不存在', 404);
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return $this->error('读取失败', 500);
        }

        return $this->json([
            'file' => $this->fileInfo($path),
            'content' => $content,
            'checksum' => sha1($content),
        ]);
    }

    #[RequestMapping(path: '/api/config/center/save', methods: 'POST')]
    public function save()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->input('path', ''));
        $content = (string) $this->request->input('content', '');
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许修改此文件', 403);
        }

        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->error('无法创建目录', 500);
        }

        $backup = null;
        if (is_file($fullPath)) {
            $backup = $fullPath . '.bak.' . date('YmdHis');
            if (!copy($fullPath, $backup)) {
                return $this->error('创建备份失败', 500);
            }
        }

        $bytes = file_put_contents($fullPath, $content);
        if ($bytes === false) {
            return $this->error('保存失败', 500);
        }

        return $this->success([
            'file' => $this->fileInfo($path),
            'size' => $bytes,
            'checksum' => sha1($content),
            'backup' => $backup ? basename($backup) : null,
            'backups' => $this->backupInfos($path),
        ], '文件已保存');
    }

    #[RequestMapping(path: '/api/config/center/backups', methods: 'GET')]
    public function backups()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->query('path', ''));
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许访问此文件', 403);
        }

        return $this->json(['backups' => $this->backupInfos($path)]);
    }

    #[RequestMapping(path: '/api/config/center/restore', methods: 'POST')]
    public function restore()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->input('path', ''));
        $backup = basename(trim((string) $this->request->input('backup', '')));
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许修改此文件', 403);
        }

        $backupPath = $this->backupFullPath($path, $backup);
        if ($backupPath === null || !is_file($backupPath)) {
            return $this->error('备份不存在', 404);
        }

        $fullPath = $this->fullPath($path);
        if (is_file($fullPath)) {
            $beforeRestore = $fullPath . '.bak.' . date('YmdHis');
            if (!copy($fullPath, $beforeRestore)) {
                return $this->error('恢复前备份失败', 500);
            }
        }

        if (!copy($backupPath, $fullPath)) {
            return $this->error('恢复失败', 500);
        }

        $content = file_get_contents($fullPath) ?: '';
        return $this->success([
            'file' => $this->fileInfo($path),
            'content' => $content,
            'checksum' => sha1($content),
            'backups' => $this->backupInfos($path),
        ], '备份已恢复');
    }

    #[RequestMapping(path: '/api/config/center/backup-read', methods: 'GET')]
    public function readBackup()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->query('path', ''));
        $backup = basename(trim((string) $this->request->query('backup', '')));
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许访问此文件', 403);
        }

        $backupPath = $this->backupFullPath($path, $backup);
        if ($backupPath === null || !is_file($backupPath)) {
            return $this->error('备份不存在', 404);
        }

        $content = file_get_contents($backupPath);
        if ($content === false) {
            return $this->error('读取备份失败', 500);
        }

        return $this->json([
            'backup' => $backup,
            'content' => $content,
            'size' => strlen($content),
            'checksum' => sha1($content),
        ]);
    }

    #[RequestMapping(path: '/api/config/center/validate', methods: 'POST')]
    public function validateFile()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $path = trim((string) $this->request->input('path', ''));
        $content = $this->request->input('content', null);
        if (!$this->isAllowedFile($path)) {
            return $this->error('不允许访问此文件', 403);
        }

        $meta = $this->metadata($path);
        $validator = $meta['validator'] ?? 'text';
        $text = is_string($content) ? $content : (is_file($this->fullPath($path)) ? (file_get_contents($this->fullPath($path)) ?: '') : '');

        return match ($validator) {
            'compose' => $this->validateCompose(),
            'nginx' => $this->validateNginx(),
            'json' => $this->validateJson($text),
            'env' => $this->validateEnv($text),
            'ini' => $this->validateIni($text),
            default => $this->validateText($text),
        };
    }

    private function groups(): array
    {
        return [
            ['name' => '核心配置', 'order' => 10, 'hint' => '影响 Docker、环境变量和常用命令。'],
            ['name' => '项目配置', 'order' => 20, 'hint' => '影响项目启动器和项目卡片。'],
            ['name' => 'Nginx 配置', 'order' => 30, 'hint' => '影响站点访问、端口和反向代理。'],
            ['name' => 'PHP 配置', 'order' => 40, 'hint' => '影响 PHP-FPM、OPcache 和 Xdebug。'],
            ['name' => 'Redis 配置', 'order' => 50, 'hint' => '影响 Redis 运行参数。'],
        ];
    }

    private function groupOrder(string $group): int
    {
        foreach ($this->groups() as $item) {
            if ($item['name'] === $group) {
                return (int) $item['order'];
            }
        }
        return 999;
    }

    private function metadata(string $path): array
    {
        if (isset($this->knownFiles[$path])) {
            return $this->knownFiles[$path];
        }

        return [
            'description' => 'Nginx 站点配置',
            'group' => 'Nginx 配置',
            'risk' => 'medium',
            'validator' => 'nginx',
            'nextAction' => '保存后建议验证 Nginx，再重载 Nginx。',
            'applyAction' => 'reload-nginx',
        ];
    }

    private function fileInfo(string $path): array
    {
        $fullPath = $this->fullPath($path);
        $exists = is_file($fullPath);
        $meta = $this->metadata($path);
        $group = (string) ($meta['group'] ?? '其他配置');

        return [
            'path' => $path,
            'name' => basename($path),
            'description' => $meta['description'] ?? '',
            'group' => $group,
            'groupOrder' => $this->groupOrder($group),
            'risk' => $meta['risk'] ?? 'low',
            'validator' => $meta['validator'] ?? 'text',
            'nextAction' => $meta['nextAction'] ?? '保存后通常不需要额外操作。',
            'applyAction' => $meta['applyAction'] ?? 'none',
            'exists' => $exists,
            'size' => $exists ? filesize($fullPath) : 0,
            'modified' => $exists ? date('Y-m-d H:i:s', filemtime($fullPath)) : null,
            'backupCount' => count($this->backupInfos($path)),
        ];
    }

    private function isAllowedFile(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }
        if (isset($this->knownFiles[$path])) {
            return true;
        }

        return (bool) preg_match('#^services/nginx/sites/[A-Za-z0-9_.-]+\.conf$#', $path);
    }

    private function fullPath(string $path): string
    {
        return $this->projectPath . '/' . ltrim($path, '/');
    }

    private function siteConfigPaths(): array
    {
        $siteDir = $this->projectPath . '/services/nginx/sites';
        if (!is_dir($siteDir)) {
            return [];
        }

        $paths = [];
        foreach (glob($siteDir . '/*.conf') ?: [] as $file) {
            $name = basename($file);
            if (preg_match('/^[A-Za-z0-9_.-]+\.conf$/', $name)) {
                $paths[] = 'services/nginx/sites/' . $name;
            }
        }
        return $paths;
    }

    private function backupInfos(string $path): array
    {
        $fullPath = $this->fullPath($path);
        $backups = [];
        foreach (glob($fullPath . '.bak.*') ?: [] as $backupPath) {
            if (!is_file($backupPath)) {
                continue;
            }
            $backups[] = [
                'name' => basename($backupPath),
                'size' => filesize($backupPath),
                'modified' => date('Y-m-d H:i:s', filemtime($backupPath)),
            ];
        }
        usort($backups, fn(array $a, array $b) => strcmp((string) $b['modified'], (string) $a['modified']));
        return $backups;
    }

    private function backupFullPath(string $path, string $backup): ?string
    {
        if ($backup === '' || str_contains($backup, '/') || str_contains($backup, '\\')) {
            return null;
        }

        foreach ($this->backupInfos($path) as $item) {
            if (($item['name'] ?? '') === $backup) {
                return dirname($this->fullPath($path)) . '/' . $backup;
            }
        }
        return null;
    }

    private function validateCompose()
    {
        $result = $this->runCommand('cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && docker-compose config 2>&1');
        if ($result['code'] === 0) {
            return $this->success(['output' => $result['output']], 'Compose 配置验证通过');
        }
        return $this->error('Compose 配置验证失败: ' . $result['output'], 400);
    }

    private function validateNginx()
    {
        $result = $this->runCommand('docker exec docker-develop-nginx-1 nginx -t 2>&1');
        if ($result['code'] === 0) {
            return $this->success(['output' => $result['output']], 'Nginx 配置验证通过');
        }
        return $this->error('Nginx 配置验证失败: ' . $result['output'], 400);
    }

    private function validateJson(string $content)
    {
        json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $this->success([], 'JSON 格式正确');
        }
        return $this->error('JSON 格式错误: ' . json_last_error_msg(), 400);
    }

    private function validateEnv(string $content)
    {
        $errors = [];
        foreach (preg_split('/\R/', $content) ?: [] as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*$/', $trimmed)) {
                $errors[] = '第 ' . ($index + 1) . ' 行不是 KEY=value 格式';
            }
        }

        if ($errors === []) {
            return $this->success([], '环境变量格式检查通过');
        }
        return $this->error(implode("\n", $errors), 400);
    }

    private function validateIni(string $content)
    {
        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        $parsed = parse_ini_string($content, false, INI_SCANNER_RAW);
        restore_error_handler();

        if ($parsed !== false) {
            return $this->success([], 'INI 格式检查通过');
        }
        return $this->error('INI 格式检查失败: ' . ($error ?: '请检查引号、等号和注释。'), 400);
    }

    private function validateText(string $content)
    {
        if (str_contains($content, "\0")) {
            return $this->error('文件包含非法的空字节。', 400);
        }
        return $this->success([], '基础文本检查通过');
    }

    private function runCommand(string $command): array
    {
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        return [
            'code' => $code,
            'output' => implode("\n", $output),
        ];
    }
}