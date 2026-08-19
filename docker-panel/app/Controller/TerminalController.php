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
            'java' => 'java redis',
        ];

        if (!isset($presets[$preset])) {
            return $this->error('预设不存在', 404);
        }

        $services = $presets[$preset];
        return $this->createEnvironmentTask($preset, $services);
    }

    private function createEnvironmentTask(string $preset, string $services)
    {
        $safePreset = preg_replace('/[^a-zA-Z0-9_-]/', '', $preset) ?: 'environment';
        $taskId = date('YmdHis') . '-env-' . $safePreset . '-' . bin2hex(random_bytes(3));
        $taskDir = BASE_PATH . '/runtime/tasks';
        if (!is_dir($taskDir) && !mkdir($taskDir, 0775, true) && !is_dir($taskDir)) {
            throw new \RuntimeException('Unable to create environment task directory.');
        }

        $logFile = $taskDir . '/' . $taskId . '.log';
        $statusFile = $taskDir . '/' . $taskId . '.status';
        $exitFile = $taskDir . '/' . $taskId . '.exit';
        $startedFile = $taskDir . '/' . $taskId . '.started';
        $finishedFile = $taskDir . '/' . $taskId . '.finished';
        $scriptFile = $taskDir . '/' . $taskId . '.sh';
        $registryPrefix = 'DOCKER_BUILDKIT=0 COMPOSE_DOCKER_CLI_BUILD=0 ' . $this->composeHostPathPrefix() . $this->composeRegistryPrefix();
        $command = 'cd ' . escapeshellarg($this->projectPath)
            . ' && unset PHP_VERSION && ' . $registryPrefix . 'docker-compose up -d --no-build ' . $services
            . ' || (' . $registryPrefix . 'docker-compose build ' . $services . ' && '
            . $registryPrefix . 'docker-compose up -d ' . $services . ')';

        $script = implode("\n", [
            '#!/bin/sh',
            'LOG_FILE=' . escapeshellarg($logFile),
            'STATUS_FILE=' . escapeshellarg($statusFile),
            'EXIT_FILE=' . escapeshellarg($exitFile),
            'STARTED_FILE=' . escapeshellarg($startedFile),
            'FINISHED_FILE=' . escapeshellarg($finishedFile),
            'printf "%s" "running" > "$STATUS_FILE"',
            'date +%s > "$STARTED_FILE"',
            'printf "[%s] Starting environment: %s\n" "$(date +%H:%M:%S)" ' . escapeshellarg($preset) . ' >> "$LOG_FILE"',
            'sh -lc ' . escapeshellarg($command) . ' >> "$LOG_FILE" 2>&1',
            'code=$?',
            'if [ "$code" -ne 0 ] && grep -qiE "manifest unknown|unknown blob|tls: first record|context deadline exceeded|Client.Timeout|registry-1.docker.io|pull access denied" "$LOG_FILE"; then',
            '  printf "[%s] Image source hint: Aliyun mirror accelerator belongs in Docker Desktop Docker Engine registry-mirrors. Keep BASE_IMAGE_REGISTRY/PHP_BASE_IMAGE_REGISTRY empty unless using a real image namespace. Do not put https://*.mirror.aliyuncs.com into .env as a FROM prefix.\n" "$(date +%H:%M:%S)" >> "$LOG_FILE"',
            'fi',
            'printf "%s" "$code" > "$EXIT_FILE"',
            'date +%s > "$FINISHED_FILE"',
            'if [ "$code" -eq 0 ]; then printf "%s" "success" > "$STATUS_FILE"; else printf "%s" "failed" > "$STATUS_FILE"; fi',
            'exit "$code"',
            '',
        ]);

        file_put_contents($statusFile, 'queued');
        file_put_contents($startedFile, (string) time());
        file_put_contents($logFile, '[' . date('H:i:s') . "] Environment task queued.\n");
        file_put_contents($scriptFile, $script);
        chmod($scriptFile, 0755);

        $output = [];
        $returnVar = 0;
        exec('sh ' . escapeshellarg($scriptFile) . ' >/dev/null 2>&1 & echo $!', $output, $returnVar);
        if ($returnVar !== 0) {
            throw new \RuntimeException('Unable to launch environment task.');
        }

        return $this->success([
            'taskId' => $taskId,
            'status' => 'queued',
            'pid' => trim((string) ($output[0] ?? '')),
            'preset' => $preset,
            'services' => preg_split('/\s+/', trim($services)) ?: [],
        ], 'Environment task started');
    }


    private function composeHostPathPrefix(): string
    {
        $hostname = gethostname();
        if ($hostname === false || $hostname === '') {
            return '';
        }

        $output = [];
        $returnVar = 0;
        exec('docker inspect ' . escapeshellarg($hostname) . ' --format ' . escapeshellarg('{{json .Mounts}}') . ' 2>/dev/null', $output, $returnVar);
        if ($returnVar !== 0 || $output === []) {
            return '';
        }

        $mounts = json_decode(implode("\n", $output), true);
        if (!is_array($mounts)) {
            return '';
        }

        foreach ($mounts as $mount) {
            if (($mount['Destination'] ?? '') === $this->projectPath && isset($mount['Source'])) {
                return 'DOCKER_DEVELOP_HOST_PATH=' . escapeshellarg((string) $mount['Source']) . ' ';
            }
        }

        return '';
    }
    private function composeRegistryPrefix(): string
    {
        $file = $this->projectPath . '/.env';
        if (!is_file($file)) {
            return '';
        }

        $assignments = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (in_array($name, ['BASE_IMAGE_REGISTRY', 'PHP_BASE_IMAGE_REGISTRY'], true)) {
                $value = trim(trim($value), "\"'");
                if ($value !== '') {
                    $assignments[] = $name . '=' . escapeshellarg($value);
                }
            }
        }

        return $assignments === [] ? '' : implode(' ', $assignments) . ' ';
    }
}
