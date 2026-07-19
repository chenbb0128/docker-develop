<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * 终端控制器 - 实时命令输出
 */
#[Controller]
class TerminalController extends AbstractController
{
    private string $projectPath = '/var/www/docker-develop';

    /**
     * 验证登录
     */
    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    /**
     * 执行命令并返回输出
     */
    #[RequestMapping(path: '/api/terminal/exec', methods: 'POST')]
    public function exec()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $cmd = $this->request->input('cmd', '');

        if (empty($cmd)) {
            return $this->error('命令不能为空', 400);
        }

        $fullCmd = 'cd ' . escapeshellarg($this->projectPath) . " && unset PHP_VERSION && {$cmd} 2>&1";

        $output = [];
        $returnVar = 0;
        exec($fullCmd, $output, $returnVar);

        return $this->json([
            'success' => $returnVar === 0,
            'output' => implode("\n", $output),
            'exitCode' => $returnVar,
        ]);
    }

    /**
     * 启动环境预设
     */
    #[RequestMapping(path: '/api/terminal/start-env', methods: 'POST')]
    public function startEnv()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        $preset = $this->request->input('preset', '');

        $presets = [
            'php73' => 'nginx php73-fpm redis',
            'php80' => 'nginx php80-fpm redis',
            'php81' => 'nginx php81-fpm redis',
            'php83' => 'nginx php83-fpm redis',
            'minimal' => 'nginx php83-fpm redis',
            'go' => 'go redis',
        ];

        if (!isset($presets[$preset])) {
            return $this->error('预设不存在', 404);
        }

        $services = $presets[$preset];
        $cmd = "docker-compose up -d {$services} 2>&1";

        $fullCmd = 'cd ' . escapeshellarg($this->projectPath) . " && unset PHP_VERSION && {$cmd}";

        // 使用 proc_open 获取实时输出
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($fullCmd, $descriptors, $pipes, $this->projectPath);
        $output = '';

        if (is_resource($process)) {
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return $this->json([
                'success' => $exitCode === 0,
                'output' => $output,
                'exitCode' => $exitCode,
                'preset' => $preset,
            ]);
        }

        return $this->error('启动进程失败', 500);
    }
}
