<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Docker API 客户端服务
 * 通过 Unix Socket 与 Docker Engine 通信
 */
class DockerService
{
    private string $socketPath;

    public function __construct(string $socketPath = '/var/run/docker.sock')
    {
        $this->socketPath = $socketPath;
    }

    /**
     * 发送请求到 Docker API
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $socket = stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 30);

        if (!$socket) {
            throw new \RuntimeException("无法连接到 Docker: $errstr ($errno)");
        }

        $body = !empty($data) ? json_encode($data) : '';

        $request = "$method $endpoint HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $body;

        fwrite($socket, $request);

        $response = '';
        while (!feof($socket)) {
            $response .= fread($socket, 8192);
        }
        fclose($socket);

        // 解析响应
        $parts = explode("\r\n\r\n", $response, 2);
        $headers = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        // 处理 chunked 编码
        if (stripos($headers, 'Transfer-Encoding: chunked') !== false) {
            $body = $this->decodeChunked($body);
        }

        return json_decode($body, true) ?? [];
    }

    /**
     * 解码 chunked 响应
     */
    private function decodeChunked(string $data): string
    {
        $result = '';
        while (strlen($data) > 0) {
            $pos = strpos($data, "\r\n");
            if ($pos === false)
                break;

            $length = hexdec(substr($data, 0, $pos));
            if ($length === 0)
                break;

            $result .= substr($data, $pos + 2, $length);
            $data = substr($data, $pos + 4 + $length);
        }
        return $result;
    }

    /**
     * 获取容器列表
     */
    public function getContainers(bool $all = true): array
    {
        $allParam = $all ? 'true' : 'false';
        $containers = $this->request('GET', "/containers/json?all=$allParam");

        return array_map(function ($c) {
            $ports = [];
            foreach ($c['Ports'] ?? [] as $port) {
                if (isset($port['PublicPort'])) {
                    $ports[] = $port['PublicPort'] . ':' . $port['PrivatePort'];
                }
            }

            return [
                'id' => substr($c['Id'], 0, 12),
                'name' => ltrim($c['Names'][0] ?? '', '/'),
                'image' => $c['Image'],
                'state' => $c['State'],
                'status' => $c['Status'],
                'ports' => implode(', ', $ports),
                'created' => date('Y-m-d H:i:s', $c['Created']),
            ];
        }, $containers);
    }

    /**
     * 获取单个容器信息
     */
    public function getContainer(string $id): array
    {
        return $this->request('GET', "/containers/$id/json");
    }

    /**
     * 启动容器
     */
    public function startContainer(string $id): bool
    {
        $this->request('POST', "/containers/$id/start");
        return true;
    }

    /**
     * 停止容器
     */
    public function stopContainer(string $id): bool
    {
        $this->request('POST', "/containers/$id/stop");
        return true;
    }

    /**
     * 重启容器
     */
    public function restartContainer(string $id): bool
    {
        $this->request('POST', "/containers/$id/restart");
        return true;
    }

    /**
     * 获取容器日志
     */
    public function getContainerLogs(string $id, int $tail = 100): string
    {
        $socket = stream_socket_client('unix://' . $this->socketPath, $errno, $errstr, 30);

        if (!$socket) {
            throw new \RuntimeException("无法连接到 Docker: $errstr ($errno)");
        }

        $request = "GET /containers/$id/logs?stdout=true&stderr=true&tail=$tail HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";

        fwrite($socket, $request);

        $response = '';
        while (!feof($socket)) {
            $response .= fread($socket, 8192);
        }
        fclose($socket);

        // 跳过 HTTP 头
        $parts = explode("\r\n\r\n", $response, 2);
        $body = $parts[1] ?? '';

        // 清理日志格式 (Docker 日志有 8 字节头)
        $cleanLogs = '';
        $offset = 0;
        while ($offset < strlen($body)) {
            if ($offset + 8 > strlen($body))
                break;
            $header = substr($body, $offset, 8);
            $size = unpack('N', substr($header, 4, 4))[1] ?? 0;
            if ($size > 0 && $offset + 8 + $size <= strlen($body)) {
                $cleanLogs .= substr($body, $offset + 8, $size);
            }
            $offset += 8 + $size;
        }

        return $cleanLogs ?: $body;
    }

    /**
     * 获取容器统计信息 (CPU/内存)
     */
    public function getContainerStats(string $id): array
    {
        return $this->request('GET', "/containers/$id/stats?stream=false");
    }

    /**
     * 删除容器 (需要先停止)
     */
    public function removeContainer(string $id, bool $force = false): bool
    {
        $forceParam = $force ? 'true' : 'false';
        $this->request('DELETE', "/containers/$id?force=$forceParam");
        return true;
    }
}
