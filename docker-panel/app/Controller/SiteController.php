<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * Nginx 站点管理控制器
 */
#[Controller]
class SiteController extends AbstractController
{
    private string $sitesPath = '/var/www/docker-develop/services/nginx/sites';
    private string $composePath = '/var/www/docker-develop/docker-compose.yml';
    private string $projectPath = '/var/www/docker-develop';

    // PHP 版本映射
    private array $phpVersions = [
        'php-fpm' => ['name' => '默认 PHP', 'upstream' => 'php-fpm:9000'],
        'php73-fpm' => ['name' => 'PHP 7.3', 'upstream' => 'php73-fpm:9000'],
        'php80-fpm' => ['name' => 'PHP 8.0', 'upstream' => 'php80-fpm:9000'],
        'php81-fpm' => ['name' => 'PHP 8.1', 'upstream' => 'php81-fpm:9000'],
        'php83-fpm' => ['name' => 'PHP 8.3', 'upstream' => 'php83-fpm:9000'],
    ];

    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    /**
     * 获取所有站点配置
     */
    #[RequestMapping(path: '/api/sites', methods: 'GET')]
    public function listSites()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $sites = [];
        $files = glob($this->sitesPath . '/*.conf');

        foreach ($files as $file) {
            $filename = basename($file);
            $content = file_get_contents($file);

            preg_match('/server_name\s+([^;]+);/', $content, $serverNameMatch);
            preg_match('/root\s+([^;]+);/', $content, $rootMatch);
            preg_match('/fastcgi_pass\s+([^:]+):/', $content, $phpMatch);
            preg_match('/listen\s+(\d+);/', $content, $portMatch);

            $port = isset($portMatch[1]) ? (int) $portMatch[1] : 80;
            $serverName = trim($serverNameMatch[1] ?? '');

            $sites[] = [
                'filename' => $filename,
                'serverName' => $serverName,
                'root' => trim($rootMatch[1] ?? ''),
                'phpVersion' => trim($phpMatch[1] ?? 'php-fpm'),
                'port' => $port,
                'enabled' => strpos($content, '#server') !== 0,
            ];
        }

        // 获取已暴露的端口
        $exposedPorts = $this->getExposedPorts();

        return $this->json([
            'sites' => $sites,
            'phpVersions' => $this->phpVersions,
            'exposedPorts' => $exposedPorts,
        ]);
    }

    /**
     * 获取 nginx 已暴露的端口
     */
    private function getExposedPorts(): array
    {
        $content = file_get_contents($this->composePath);
        $ports = [80, 443]; // 默认端口

        // 查找 nginx 的 ports 配置
        if (preg_match_all('/-\s*"(\d+):(\d+)"/', $content, $matches)) {
            foreach ($matches[1] as $port) {
                $ports[] = (int) $port;
            }
        }

        return array_unique($ports);
    }

    /**
     * 创建新站点
     */
    #[RequestMapping(path: '/api/sites', methods: 'POST')]
    public function createSite()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $name = $this->request->input('name', '');
        $serverName = $this->request->input('serverName', 'localhost');
        $root = $this->request->input('root', '');
        $phpVersion = $this->request->input('phpVersion', 'php-fpm');
        $port = (int) $this->request->input('port', 80);
        $type = $this->request->input('type', 'laravel');
        $usePort = (bool) $this->request->input('usePort', false);

        if (empty($name) || empty($root)) {
            return $this->error('名称和根目录不能为空', 400);
        }

        if ($usePort && ($port < 1024 || $port > 65535)) {
            return $this->error('端口号必须在 1024-65535 之间', 400);
        }

        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $name) . '.conf';
        $filepath = $this->sitesPath . '/' . $filename;

        if (file_exists($filepath)) {
            return $this->error('站点配置已存在', 400);
        }

        $upstream = $this->phpVersions[$phpVersion]['upstream'] ?? 'php-fpm:9000';

        if ($usePort) {
            $serverName = 'localhost';
        }

        $config = $this->generateConfig($serverName, $root, $upstream, $type, $usePort ? $port : 80);

        if (file_put_contents($filepath, $config) === false) {
            return $this->error('创建配置文件失败', 500);
        }

        // 检查端口是否需要暴露
        $portExposed = true;
        $needRestartNginx = false;
        if ($usePort) {
            $exposedPorts = $this->getExposedPorts();
            if (!in_array($port, $exposedPorts)) {
                $portExposed = $this->addPortToCompose($port);
                $needRestartNginx = $portExposed;
            }
        }

        $accessUrl = $usePort ? "http://localhost:{$port}" : "http://{$serverName}";
        $hostsCmd = !$usePort ? "127.0.0.1 {$serverName}" : null;

        return $this->success([
            'filename' => $filename,
            'accessUrl' => $accessUrl,
            'portExposed' => $portExposed,
            'needRestartNginx' => $needRestartNginx,
            'hostsEntry' => $hostsCmd,
            'usePort' => $usePort,
        ], '站点创建成功！');
    }

    /**
     * 添加端口到 docker-compose.yml
     */
    private function addPortToCompose(int $port): bool
    {
        $content = file_get_contents($this->composePath);

        // 查找 nginx 服务的 ports 部分
        // 在 "7002:7002" 后面添加新端口
        $pattern = '/(\s*-\s*"7002:7002")/';
        $replacement = "$1\n      - \"{$port}:{$port}\"";

        $newContent = preg_replace($pattern, $replacement, $content, 1, $count);

        if ($count > 0) {
            file_put_contents($this->composePath, $newContent);
            return true;
        }

        // 备用方案：在 "443:443" 后面添加
        $pattern2 = '/(\s*-\s*"\$\{NGINX_HOST_HTTPS_PORT\}:443")/';
        $replacement2 = "$1\n      - \"{$port}:{$port}\"";
        $newContent2 = preg_replace($pattern2, $replacement2, $content, 1, $count2);

        if ($count2 > 0) {
            file_put_contents($this->composePath, $newContent2);
            return true;
        }

        return false;
    }

    /**
     * 删除站点
     */
    #[RequestMapping(path: '/api/sites/{filename}', methods: 'DELETE')]
    public function deleteSite(string $filename)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+\.conf$/', $filename)) {
            return $this->error('无效的文件名', 400);
        }

        $filepath = $this->sitesPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return $this->error('站点不存在', 404);
        }

        copy($filepath, $filepath . '.bak.' . date('YmdHis'));

        if (!unlink($filepath)) {
            return $this->error('删除失败', 500);
        }

        return $this->success([], '站点已删除');
    }

    /**
     * 重新加载 Nginx
     */
    #[RequestMapping(path: '/api/sites/reload-nginx', methods: 'POST')]
    public function reloadNginx()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        exec('docker exec docker-develop-nginx-1 nginx -t 2>&1', $testOutput, $testCode);

        if ($testCode !== 0) {
            return $this->error('Nginx 配置有误: ' . implode("\n", $testOutput), 400);
        }

        exec('docker exec docker-develop-nginx-1 nginx -s reload 2>&1', $output, $returnVar);

        if ($returnVar === 0) {
            return $this->success([], 'Nginx 已重新加载');
        } else {
            return $this->error('重新加载失败: ' . implode("\n", $output), 500);
        }
    }

    /**
     * 重启 Nginx 容器 (用于端口变更)
     */
    #[RequestMapping(path: '/api/sites/restart-nginx', methods: 'POST')]
    public function restartNginx()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $cmd = "cd {$this->projectPath} && docker-compose up -d nginx 2>&1";
        exec($cmd, $output, $returnVar);

        if ($returnVar === 0) {
            return $this->success(['output' => implode("\n", $output)], 'Nginx 容器已重启');
        } else {
            return $this->error('重启失败: ' . implode("\n", $output), 500);
        }
    }

    /**
     * 生成 hosts 条目命令
     */
    #[RequestMapping(path: '/api/sites/hosts-command', methods: 'GET')]
    public function getHostsCommand()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $domain = $this->request->query('domain', '');

        if (empty($domain)) {
            return $this->error('域名不能为空', 400);
        }

        // Windows 命令 (需要管理员权限)
        $winCmd = "echo 127.0.0.1 {$domain} >> C:\\Windows\\System32\\drivers\\etc\\hosts";

        // PowerShell 命令
        $psCmd = "Add-Content -Path 'C:\\Windows\\System32\\drivers\\etc\\hosts' -Value '127.0.0.1 {$domain}'";

        return $this->success([
            'domain' => $domain,
            'entry' => "127.0.0.1 {$domain}",
            'windowsCmd' => $winCmd,
            'powershellCmd' => $psCmd,
            'instruction' => '请以管理员身份运行 PowerShell 并执行上述命令',
        ]);
    }

    /**
     * 生成 Nginx 配置
     */
    private function generateConfig(string $serverName, string $root, string $upstream, string $type, int $port = 80): string
    {
        $logName = str_replace(['.', ' '], '_', $serverName ?: 'site') . ($port !== 80 ? "_{$port}" : '');

        if ($type === 'static') {
            return <<<CONF
server {
    listen {$port};
    listen [::]:{$port};

    server_name {$serverName};
    root {$root};
    index index.html index.htm;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ /\.ht {
        deny all;
    }

    error_log /var/log/nginx/{$logName}_error.log;
    access_log /var/log/nginx/{$logName}_access.log;
}
CONF;
        }

        return <<<CONF
server {
    listen {$port};
    listen [::]:{$port};

    server_name {$serverName};
    root {$root};
    index index.php index.html index.htm;

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        try_files \$uri /index.php =404;
        fastcgi_pass {$upstream};
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 600;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }

    error_log /var/log/nginx/{$logName}_error.log;
    access_log /var/log/nginx/{$logName}_access.log;
}
CONF;
    }
}
