<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

#[Controller]
class ProjectDeleteController extends AbstractController
{
    private string $projectPath = '/var/www/docker-develop';
    private string $configFile = '/var/www/docker-develop/projects.json';

    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    #[RequestMapping(path: '/api/projects/{key}/delete-environment', methods: 'POST')]
    public function deleteEnvironment(string $key)
    {
        if (!$this->checkAuth()) {
            return $this->error('Unauthenticated', 401);
        }

        if ($this->boolInput('removeSource')) {
            return $this->error('Source deletion is not supported by this panel. Only environment cleanup is allowed.', 400);
        }

        $projects = $this->loadProjects();
        $project = $this->findProject($projects, $key);
        if ($project === null) {
            return $this->error('Project not found', 404);
        }

        $removeContainer = $this->boolInput('removeContainer');
        $removeCompose = $this->boolInput('removeCompose');
        $removeSite = $this->boolInput('removeSite');
        $removeNginxPort = $this->boolInput('removeNginxPort');

        $actions = [];
        $warnings = [];
        $output = [];

        try {
            $dedicatedService = $this->dedicatedService($project);

            if ($removeContainer) {
                if ($dedicatedService === null) {
                    $warnings[] = 'No dedicated container is managed for this project type.';
                } else {
                    $actions[] = 'Remove dedicated container: ' . $dedicatedService;
                    try {
                        $output[] = $this->runHostCommand('docker-compose rm -sf ' . escapeshellarg($dedicatedService));
                    } catch (\Throwable $e) {
                        $warnings[] = 'Container cleanup failed: ' . $this->friendlyCommandError($e);
                    }
                }
            }

            if ($removeCompose) {
                if (($project['type'] ?? '') !== 'hyperf') {
                    $warnings[] = 'Compose service cleanup is only available for dedicated project services.';
                } else {
                    $service = $dedicatedService ?? $this->hyperfService($project);
                    if ($this->removeHyperfComposeService($service)) {
                        $actions[] = 'Remove docker-compose service block: ' . $service;
                    } else {
                        $warnings[] = 'docker-compose service block was not found: ' . $service;
                    }
                }
            }

            if ($removeSite) {
                $siteName = $this->siteConfigName($project);
                if ($this->removeProjectSiteConfig($project)) {
                    $actions[] = 'Remove Nginx site config: ' . $siteName;
                } else {
                    $warnings[] = 'Nginx site config was not found: ' . $siteName;
                }
            }

            if ($removeNginxPort) {
                $result = $this->removeNginxPortIfUnused($project, $projects);
                if ($result['removed']) {
                    $actions[] = 'Remove unused Nginx port mapping: ' . $result['port'];
                } else {
                    $warnings[] = $result['reason'];
                }
            }

            $this->saveProjects(array_values(array_filter(
                $projects,
                fn(array $item) => ($item['key'] ?? '') !== $key
            )));
            $actions[] = 'Remove project record from projects.json';

            return $this->success([
                'actions' => $actions,
                'warnings' => $warnings,
                'output' => trim(implode("\n\n", array_filter($output))),
            ], 'Project environment deleted');
        } catch (\Throwable $e) {
            return $this->error($this->friendlyCommandError($e), 500);
        }
    }

    private function boolInput(string $key, bool $default = false): bool
    {
        $value = $this->request->input($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $value;
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

    private function findProject(array $projects, string $key): ?array
    {
        foreach ($projects as $project) {
            if (($project['key'] ?? '') === $key) {
                return $project;
            }
        }

        return null;
    }

    private function dedicatedService(array $project): ?string
    {
        if (($project['type'] ?? '') === 'hyperf') {
            return $this->hyperfService($project);
        }

        return null;
    }

    private function hyperfService(array $project): string
    {
        $service = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($project['service'] ?? '')) ?: '';
        if ($service !== '') {
            return $service;
        }

        $key = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($project['key'] ?? 'project')) ?: 'project');
        return 'hyperf-' . $key;
    }

    private function removeHyperfComposeService(string $service): bool
    {
        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $begin = '# BEGIN Hyperf project: ' . $service;
        $end = '# END Hyperf project: ' . $service;
        $pattern = '/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R?/s';
        $newContent = preg_replace($pattern, PHP_EOL, $content, 1, $count);

        if ($count !== 1 || $newContent === null) {
            return false;
        }

        $this->writeTextFile($composeFile, rtrim($newContent) . PHP_EOL);
        return true;
    }

    private function siteConfigName(array $project): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($project['key'] ?? '')) ?: 'project';
        return $key . '.conf';
    }

    private function removeProjectSiteConfig(array $project): bool
    {
        $siteFile = $this->projectPath . '/services/nginx/sites/' . $this->siteConfigName($project);
        if (!file_exists($siteFile)) {
            return false;
        }

        copy($siteFile, $siteFile . '.bak.' . date('YmdHis'));
        if (!unlink($siteFile)) {
            throw new \RuntimeException('Unable to remove Nginx site config.');
        }

        return true;
    }

    private function removeNginxPortIfUnused(array $project, array $projects): array
    {
        $port = (int) ($project['port'] ?? 0);
        if ($port < 1024 || $port > 65535) {
            return ['removed' => false, 'port' => $port, 'reason' => 'Project port is empty or outside the removable range.'];
        }

        foreach ($projects as $item) {
            if (($item['key'] ?? '') !== ($project['key'] ?? '') && (int) ($item['port'] ?? 0) === $port) {
                return ['removed' => false, 'port' => $port, 'reason' => 'Port is still used by another project record: ' . ($item['key'] ?? 'unknown')];
            }
        }

        $sitesPath = $this->projectPath . '/services/nginx/sites';
        foreach (glob($sitesPath . '/*.conf') ?: [] as $file) {
            if (basename($file) === $this->siteConfigName($project)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (preg_match('/listen\s+(?:\[::\]:)?' . preg_quote((string) $port, '/') . '\b/', $content)) {
                return ['removed' => false, 'port' => $port, 'reason' => 'Port is still referenced by Nginx site config: ' . basename($file)];
            }
        }

        $composeFile = $this->projectPath . '/docker-compose.yml';
        $content = (string) file_get_contents($composeFile);
        $patterns = [
            '/\R\s*-\s*"' . preg_quote((string) $port, '/') . ':' . preg_quote((string) $port, '/') . '"/',
            "/\R\s*-\s*'" . preg_quote((string) $port, '/') . ':' . preg_quote((string) $port, '/') . "'/",
        ];

        $changed = false;
        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content, 1, $count) ?? $content;
            if ($count > 0) {
                $changed = true;
                break;
            }
        }

        if (!$changed) {
            return ['removed' => false, 'port' => $port, 'reason' => 'Nginx port mapping was not found or was already removed.'];
        }

        $this->writeTextFile($composeFile, rtrim($content) . PHP_EOL);
        return ['removed' => true, 'port' => $port, 'reason' => ''];
    }

    private function writeTextFile(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Unable to write ' . basename($path));
        }
    }

    private function runHostCommand(string $command): string
    {
        $output = [];
        $returnVar = 0;
        exec('cd ' . escapeshellarg($this->projectPath) . ' && unset PHP_VERSION && ' . $command . ' 2>&1', $output, $returnVar);
        $text = implode("\n", $output);
        if ($returnVar !== 0) {
            throw new \RuntimeException($text !== '' ? $text : 'Command failed.');
        }

        return $text;
    }

    private function friendlyCommandError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Command failed.';
        }
        if (str_contains($message, 'No such service')) {
            return $message . "\n\nNo docker-compose service was found for this project.";
        }

        return $message;
    }
}
