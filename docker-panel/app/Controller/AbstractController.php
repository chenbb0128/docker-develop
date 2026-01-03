<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

abstract class AbstractController
{
    protected ContainerInterface $container;
    protected RequestInterface $request;
    protected ResponseInterface $response;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->request = $container->get(RequestInterface::class);
        $this->response = $container->get(ResponseInterface::class);
    }

    /**
     * 返回 JSON 响应
     */
    protected function json(array $data, int $status = 200)
    {
        return $this->response->json($data)->withStatus($status);
    }

    /**
     * 返回成功响应
     */
    protected function success(array $data = [], string $message = 'success')
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * 返回错误响应
     */
    protected function error(string $message, int $code = 400)
    {
        return $this->json([
            'success' => false,
            'error' => $message,
        ], $code);
    }
}
