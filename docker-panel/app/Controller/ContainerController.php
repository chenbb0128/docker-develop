<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DockerService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * 容器管理控制器
 */
#[Controller]
class ContainerController extends AbstractController
{
    #[Inject]
    protected DockerService $docker;

    /**
     * 验证登录
     */
    private function checkAuth(): bool
    {
        $token = $this->request->header('Authorization', '');
        return AuthController::validateToken($token);
    }

    /**
     * 容器列表
     */
    #[RequestMapping(path: '/api/containers', methods: 'GET')]
    public function list()
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $containers = $this->docker->getContainers(true);
            return $this->json(['containers' => $containers]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 容器详情
     */
    #[RequestMapping(path: '/api/containers/{id}', methods: 'GET')]
    public function show(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $container = $this->docker->getContainer($id);
            return $this->json(['container' => $container]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 启动容器
     */
    #[RequestMapping(path: '/api/containers/{id}/start', methods: 'POST')]
    public function start(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $this->docker->startContainer($id);
            return $this->success([], '容器已启动');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 停止容器
     */
    #[RequestMapping(path: '/api/containers/{id}/stop', methods: 'POST')]
    public function stop(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $this->docker->stopContainer($id);
            return $this->success([], '容器已停止');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 重启容器
     */
    #[RequestMapping(path: '/api/containers/{id}/restart', methods: 'POST')]
    public function restart(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $this->docker->restartContainer($id);
            return $this->success([], '容器已重启');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 容器日志
     */
    #[RequestMapping(path: '/api/containers/{id}/logs', methods: 'GET')]
    public function logs(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $tail = (int) $this->request->query('tail', 100);
            $logs = $this->docker->getContainerLogs($id, $tail);
            return $this->json(['logs' => $logs]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 容器统计信息
     */
    #[RequestMapping(path: '/api/containers/{id}/stats', methods: 'GET')]
    public function stats(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            $stats = $this->docker->getContainerStats($id);
            return $this->json(['stats' => $stats]);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 删除容器
     */
    #[RequestMapping(path: '/api/containers/{id}', methods: 'DELETE')]
    public function remove(string $id)
    {
        if (!$this->checkAuth()) {
            return $this->error('未登录', 401);
        }

        try {
            // 强制删除 (即使正在运行)
            $this->docker->removeContainer($id, true);
            return $this->success([], '容器已删除');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
