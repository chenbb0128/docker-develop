<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

/**
 * 认证控制器
 */
#[Controller]
class AuthController extends AbstractController
{
    private const AUTH_ENABLED = false;
    // 默认账号密码 (请在生产环境中修改)
    private const ADMIN_USERNAME = 'admin';
    private const ADMIN_PASSWORD = 'docker123';
    private const TOKEN_FILE = '/tmp/docker_panel_tokens.json';

    /**
     * 获取存储的 tokens
     */
    private static function getTokens(): array
    {
        if (!file_exists(self::TOKEN_FILE)) {
            return [];
        }
        $content = file_get_contents(self::TOKEN_FILE);
        return json_decode($content, true) ?: [];
    }

    /**
     * 保存 tokens
     */
    private static function saveTokens(array $tokens): void
    {
        file_put_contents(self::TOKEN_FILE, json_encode($tokens));
    }

    /**
     * 登录
     */
    #[RequestMapping(path: '/api/login', methods: 'POST')]
    public function login()
    {
        if (!self::AUTH_ENABLED) {
            return $this->success(['token' => 'local-dev'], 'Auth disabled');
        }
        $username = $this->request->input('username', '');
        $password = $this->request->input('password', '');

        if ($username === self::ADMIN_USERNAME && $password === self::ADMIN_PASSWORD) {
            // 使用简单的 token
            $token = md5(self::ADMIN_USERNAME . time() . uniqid());

            // 存储到文件
            $tokens = self::getTokens();
            $tokens[$token] = [
                'username' => $username,
                'expires' => time() + 86400, // 24小时过期
            ];
            self::saveTokens($tokens);

            return $this->success(['token' => $token], '登录成功');
        }

        return $this->error('用户名或密码错误', 401);
    }

    /**
     * 登出
     */
    #[RequestMapping(path: '/api/logout', methods: 'POST')]
    public function logout()
    {
        $token = $this->request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        $tokens = self::getTokens();
        if (isset($tokens[$token])) {
            unset($tokens[$token]);
            self::saveTokens($tokens);
        }

        return $this->success([], '已登出');
    }

    /**
     * 检查登录状态
     */
    #[RequestMapping(path: '/api/check-auth', methods: 'GET')]
    public function checkAuth()
    {
        if (!self::AUTH_ENABLED) {
            return $this->success(['authenticated' => true]);
        }

        $token = $this->request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $token);

        $tokens = self::getTokens();
        if (!empty($token) && isset($tokens[$token])) {
            $auth = $tokens[$token];
            if ($auth['expires'] > time()) {
                return $this->success(['authenticated' => true]);
            }
            // 清理过期 token
            unset($tokens[$token]);
            self::saveTokens($tokens);
        }

        return $this->json(['authenticated' => false]);
    }

    /**
     * 验证 Token
     */
    public static function validateToken(string $token): bool
    {
        if (!self::AUTH_ENABLED) {
            return true;
        }

        if (empty($token))
            return false;
        $token = str_replace('Bearer ', '', $token);

        $tokens = self::getTokens();
        if (isset($tokens[$token])) {
            $auth = $tokens[$token];
            return $auth['expires'] > time();
        }

        return false;
    }
}
