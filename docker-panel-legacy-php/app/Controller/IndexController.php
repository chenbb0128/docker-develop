<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

/**
 * 首页控制器
 */
#[Controller]
class IndexController extends AbstractController
{
    #[GetMapping(path: '/')]
    public function index()
    {
        $html = file_get_contents(BASE_PATH . '/public/index.html');
        return $this->response->raw($html)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    }
}
