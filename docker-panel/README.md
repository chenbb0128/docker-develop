# 🎛 Docker Panel - 管理面板文档

Docker Panel 是一个基于 Hyperf + Swoole 的可视化 Docker 管理面板。

---

## 📋 功能概览

| 功能 | 说明 |
|------|------|
| 🐳 **容器管理** | 启动、停止、重启、删除容器 |
| ⚡ **快捷环境** | 一键启动预设环境组合 |
| 🌐 **站点管理** | 创建和管理 Nginx 虚拟站点 |
| 📝 **配置编辑** | 在线编辑配置文件 |
| 📋 **日志查看** | 实时查看容器日志 |
| 🖥 **终端输出** | 查看命令执行结果 |

---

## 🔐 登录信息

| 项目 | 值 |
|------|-----|
| 地址 | http://localhost:9501 |
| 用户名 | admin |
| 密码 | admin123 |

---

## 📁 目录结构

```
docker-panel/
├── app/
│   ├── Controller/
│   │   ├── AbstractController.php   # 基础控制器
│   │   ├── AuthController.php       # 认证控制器
│   │   ├── ContainerController.php  # 容器管理
│   │   ├── EnvironmentController.php # 环境预设
│   │   ├── SiteController.php       # 站点管理
│   │   ├── TerminalController.php   # 终端命令
│   │   └── ConfigController.php     # 配置管理
│   └── Service/
│       └── DockerService.php        # Docker API 服务
├── config/
│   ├── autoload/
│   │   └── server.php               # 服务器配置
│   └── routes.php                   # 路由配置
├── public/
│   └── index.html                   # 前端页面
├── runtime/                         # 运行时目录
├── Dockerfile                       # 容器构建文件
└── composer.json                    # PHP 依赖
```

---

## 🔌 API 接口

### 认证

```
POST /api/auth/login
Body: { "username": "admin", "password": "admin123" }
Response: { "success": true, "data": { "token": "..." } }
```

### 容器管理

```
GET  /api/containers              # 获取容器列表
POST /api/container/{id}/start    # 启动容器
POST /api/container/{id}/stop     # 停止容器
POST /api/container/{id}/restart  # 重启容器
DELETE /api/container/{id}        # 删除容器
GET  /api/container/{id}/logs     # 获取日志
```

### 环境预设

```
GET  /api/env/presets             # 获取预设列表
POST /api/env/start/{preset}      # 启动预设环境
POST /api/env/stop-all            # 停止所有容器
GET  /api/env/status              # 获取环境状态
```

### 站点管理

```
GET    /api/sites                 # 获取站点列表
POST   /api/sites                 # 创建站点
DELETE /api/sites/{filename}      # 删除站点
POST   /api/sites/reload-nginx    # 重载 Nginx
POST   /api/sites/restart-nginx   # 重启 Nginx 容器
```

### 配置管理

```
GET  /api/config/files            # 获取配置文件列表
GET  /api/config/content          # 获取文件内容
POST /api/config/save             # 保存文件
POST /api/config/validate         # 验证配置
```

### 终端

```
POST /api/terminal/exec           # 执行命令
POST /api/terminal/start-env      # 启动环境 (带输出)
```

---

## ⚙️ 技术实现

### 后端

- **框架**: Hyperf 3.0
- **服务器**: Swoole HTTP Server
- **认证**: Token 认证 (文件存储)
- **Docker 通信**: Unix Socket API

### 前端

- **纯原生**: HTML + CSS + JavaScript
- **无框架**: 不依赖任何前端框架
- **响应式**: 适配不同屏幕
- **设计**: 现代化 UI，支持深色终端

---

## 🔧 开发指南

### 本地运行

```bash
cd docker-panel
composer install
php bin/hyperf.php start
```

### 在容器中运行

```bash
docker-compose up -d docker-panel
docker-compose logs -f docker-panel
```

### 添加新控制器

1. 在 `app/Controller/` 创建控制器类
2. 继承 `AbstractController`
3. 使用注解定义路由

```php
<?php
namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;

#[Controller]
class MyController extends AbstractController
{
    #[RequestMapping(path: '/api/my/action', methods: 'GET')]
    public function action()
    {
        return $this->json(['message' => 'Hello']);
    }
}
```

### 添加新服务

1. 在 `app/Service/` 创建服务类
2. 使用依赖注入

```php
<?php
namespace App\Service;

class MyService
{
    public function doSomething(): array
    {
        // 业务逻辑
        return [];
    }
}
```

---

## 📝 配置文件

### config/autoload/server.php

```php
return [
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 9501,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
        ],
    ],
];
```

---

## 🔒 安全说明

1. **Token 存储**: 使用文件存储 (`/tmp/docker_panel_tokens.json`)
2. **密码**: 生产环境请修改默认密码
3. **Docker Socket**: 面板容器挂载了 Docker Socket，具有完全的 Docker 控制权限
4. **网络**: 仅监听 localhost:9501，不对外暴露

---

## 🐛 调试

### 查看日志

```bash
# 面板日志
docker-compose logs -f docker-panel

# PHP 错误日志
docker-compose exec docker-panel cat /var/www/docker-panel/runtime/logs/hyperf.log
```

### 重启面板

```bash
docker-compose restart docker-panel
```

### 进入容器调试

```bash
docker-compose exec docker-panel sh
```
