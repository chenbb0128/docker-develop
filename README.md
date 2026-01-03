# 🐳 Docker Develop - 多 PHP 版本开发环境

一个功能强大的 Docker 开发环境管理工具，支持多 PHP 版本切换，并提供可视化 Web 管理面板。

![PHP](https://img.shields.io/badge/PHP-7.3%20%7C%208.0%20%7C%208.1%20%7C%208.3-777BB4?logo=php)
![Docker](https://img.shields.io/badge/Docker-Compose-2496ED?logo=docker)
![Hyperf](https://img.shields.io/badge/Hyperf-3.0-00BFFF)

---

## 📋 目录

- [功能特性](#-功能特性)
- [系统要求](#-系统要求)
- [快速开始](#-快速开始)
- [项目结构](#-项目结构)
- [管理面板使用](#-管理面板使用)
- [多 PHP 版本配置](#-多-php-版本配置)
- [Nginx 站点管理](#-nginx-站点管理)
- [常用命令](#-常用命令)
- [配置说明](#-配置说明)
- [故障排除](#-故障排除)

---

## ✨ 功能特性

### 🎯 核心功能
- **多 PHP 版本支持** - PHP 7.3 / 8.0 / 8.1 / 8.3 同时运行
- **可视化管理面板** - 基于 Hyperf + Swoole 的 Web 管理界面
- **一键环境切换** - 快速切换不同 PHP 版本环境
- **容器管理** - 启动、停止、重启、删除容器
- **实时日志查看** - 查看容器运行日志
- **站点管理** - 可视化创建和管理 Nginx 虚拟站点

### 🛠 技术栈
| 组件 | 版本/说明 |
|------|-----------|
| Docker | Docker Desktop for Windows |
| PHP-FPM | 7.3 / 8.0 / 8.1 / 8.3 |
| Nginx | 最新稳定版 |
| Redis | 最新稳定版 |
| Hyperf | 3.0 (管理面板框架) |
| Swoole | 协程 HTTP 服务器 |

---

## 💻 系统要求

- **操作系统**: Windows 10/11 (64-bit)
- **Docker Desktop**: 4.0+
- **内存**: 建议 8GB+
- **磁盘**: 至少 10GB 可用空间

---

## 🚀 快速开始

### 1. 克隆项目

```bash
git clone https://github.com/your-repo/docker-develop.git
cd docker-develop
```

### 2. 配置环境变量

```bash
# 复制环境配置文件
cp .env.example .env

# 编辑 .env 文件，根据需要修改配置
```

### 3. 启动服务

**方式一：使用快捷脚本（推荐）**
```
双击 启动面板.bat
```

**方式二：命令行启动**
```bash
# 启动管理面板
docker-compose up -d docker-panel

# 启动完整环境
docker-compose up -d
```

### 4. 访问管理面板

打开浏览器访问: **http://localhost:9501**

- 用户名: `admin`
- 密码: `admin123`

---

## 📁 项目结构

```
docker-develop/
├── 📄 docker-compose.yml      # Docker Compose 主配置
├── 📄 .env                    # 环境变量配置
├── 📄 .env.example            # 环境变量示例
├── 📄 Makefile                # Make 命令快捷方式
│
├── 📂 docker-panel/           # 管理面板 (Hyperf 应用)
│   ├── app/                   # 应用代码
│   │   ├── Controller/        # 控制器
│   │   └── Service/           # 服务层
│   ├── config/                # 配置文件
│   ├── public/                # 静态资源 (前端)
│   ├── runtime/               # 运行时文件
│   └── Dockerfile             # 面板容器构建
│
├── 📂 services/               # Docker 服务配置
│   ├── nginx/                 # Nginx 配置
│   │   ├── nginx.conf         # 主配置
│   │   ├── sites/             # 虚拟站点配置
│   │   └── ssl/               # SSL 证书
│   ├── php-fpm/               # PHP-FPM 配置
│   │   ├── Dockerfile         # PHP 镜像构建
│   │   └── php*.ini           # PHP 配置
│   └── workspace/             # 开发工具容器
│
├── 📂 data/                   # 数据持久化目录
├── 📂 logs/                   # 日志目录
├── 📂 cache/                  # 缓存目录
│
├── 📄 启动面板.bat            # 快捷启动脚本
├── 📄 停止面板.bat            # 停止脚本
├── 📄 快速启动.bat            # 交互式启动
└── 📄 创建桌面快捷方式.ps1    # 创建桌面快捷方式
```

---

## 🖥 管理面板使用

### 登录

访问 `http://localhost:9501`，使用默认账号登录：
- 用户名: `admin`
- 密码: `admin123`

### 功能模块

#### 1️⃣ 容器管理
- 查看所有容器状态（运行/停止/已删除）
- 启动、停止、重启容器
- 查看容器日志
- 删除容器

#### 2️⃣ 快捷环境
一键启动预设的开发环境组合：

| 环境 | 包含服务 |
|------|----------|
| PHP 7.3 环境 | Nginx + PHP 7.3 + Redis |
| PHP 8.0 环境 | Nginx + PHP 8.0 + Redis |
| PHP 8.1 环境 | Nginx + PHP 8.1 + Redis |
| PHP 8.3 环境 | Nginx + PHP 8.3 + Redis |
| 默认环境 | Nginx + 默认 PHP + Redis |

#### 3️⃣ 站点管理
- 创建 Nginx 虚拟站点配置
- 选择 PHP 版本
- 支持端口访问（无需修改 hosts）
- 支持域名访问（需修改 hosts）
- 一键复制 hosts 配置命令
- 重载/重启 Nginx

#### 4️⃣ 配置管理
- 在线编辑 docker-compose.yml
- 编辑 .env 配置
- 编辑 Nginx 配置
- 编辑 PHP 配置

---

## 🐘 多 PHP 版本配置

### 可用的 PHP 容器

| 容器名 | PHP 版本 | 内部端口 |
|--------|---------|----------|
| php-fpm | 默认版本 (.env 配置) | 9000 |
| php73-fpm | 7.3 | 9000 |
| php80-fpm | 8.0 | 9000 |
| php81-fpm | 8.1 | 9000 |
| php83-fpm | 8.3 | 9000 |

### Nginx 配置示例

为不同项目配置不同 PHP 版本：

```nginx
# PHP 7.3 项目
server {
    listen 80;
    server_name php73-project.test;
    root /develop/php73-project/public;
    
    location ~ \.php$ {
        fastcgi_pass php73-fpm:9000;  # 使用 PHP 7.3
        # ... 其他配置
    }
}

# PHP 8.3 项目
server {
    listen 80;
    server_name php83-project.test;
    root /develop/php83-project/public;
    
    location ~ \.php$ {
        fastcgi_pass php83-fpm:9000;  # 使用 PHP 8.3
        # ... 其他配置
    }
}
```

---

## 🌐 Nginx 站点管理

### 通过面板创建站点

1. 登录管理面板
2. 点击顶部 **🌐 站点** 按钮
3. 填写站点信息：
   - **配置名称**: 例如 `myproject`
   - **PHP 版本**: 选择需要的版本
   - **根目录**: 例如 `/develop/myproject/public`
   - **访问方式**: 端口访问（推荐）或域名访问

4. 点击 **创建站点**
5. 点击 **重启容器** 或 **重载配置**

### 访问方式对比

| 方式 | 优点 | 缺点 | 示例 |
|------|------|------|------|
| **端口访问** | 无需改 hosts，即开即用 | 需要记住端口号 | `http://localhost:8080` |
| **域名访问** | URL 更友好 | 需要修改 hosts | `http://myproject.test` |

### 修改 hosts 文件

如果使用域名访问，需要修改 Windows hosts 文件：

```powershell
# 以管理员身份运行 PowerShell
Add-Content -Path 'C:\Windows\System32\drivers\etc\hosts' -Value '127.0.0.1 myproject.test'
```

---

## 📝 常用命令

### Docker Compose 命令

```bash
# 启动所有服务
docker-compose up -d

# 启动指定服务
docker-compose up -d nginx php83-fpm redis docker-panel

# 停止所有服务
docker-compose down

# 查看运行状态
docker-compose ps

# 查看日志
docker-compose logs -f docker-panel

# 重启服务
docker-compose restart nginx

# 重新构建
docker-compose build --no-cache php-fpm
```

### 进入容器

```bash
# 进入 PHP 容器
docker-compose exec php83-fpm bash

# 进入 Nginx 容器
docker-compose exec nginx bash

# 进入面板容器
docker-compose exec docker-panel sh
```

### Make 命令 (如果安装了 Make)

```bash
make up        # 启动服务
make down      # 停止服务
make restart   # 重启服务
make logs      # 查看日志
make bash      # 进入 workspace
```

---

## ⚙️ 配置说明

### .env 主要配置项

```env
# 项目路径
HOST_PROJECT_PATH=D:/Projects
CONTAINER_PROJECT_PATH=/develop

# PHP 默认版本
PHP_VERSION=8.1

# Nginx 端口
NGINX_HOST_HTTP_PORT=80
NGINX_HOST_HTTPS_PORT=443

# Redis
REDIS_PORT=6379

# 时区
TIMEZONE=Asia/Shanghai

# PHP 扩展开关
PHP_FPM_INSTALL_XDEBUG=false
PHP_FPM_INSTALL_SWOOLE=true
PHP_FPM_INSTALL_REDIS=true
```

### PHP 扩展配置

在 `.env` 中启用/禁用 PHP 扩展：

```env
PHP_FPM_INSTALL_XDEBUG=true      # Xdebug 调试
PHP_FPM_INSTALL_SWOOLE=true      # Swoole 协程
PHP_FPM_INSTALL_REDIS=true       # Redis 扩展
PHP_FPM_INSTALL_MONGO=false      # MongoDB 扩展
PHP_FPM_INSTALL_OPCACHE=true     # OPcache 加速
PHP_FPM_INSTALL_IMAGEMAGICK=true # 图片处理
```

---

## 🔧 故障排除

### 1. 端口被占用

```bash
# 查看端口占用
netstat -ano | findstr "9501"

# 停止占用进程或修改端口
```

### 2. Docker Desktop 未运行

```bash
# 启动 Docker Desktop
start "" "C:\Program Files\Docker\Docker\Docker Desktop.exe"
```

### 3. 面板无法访问

```bash
# 检查容器状态
docker-compose ps

# 查看日志
docker-compose logs docker-panel

# 重启面板
docker-compose restart docker-panel
```

### 4. Nginx 配置错误

```bash
# 测试 Nginx 配置
docker-compose exec nginx nginx -t

# 重载配置
docker-compose exec nginx nginx -s reload
```

### 5. PHP 容器启动失败

```bash
# 查看日志
docker-compose logs php83-fpm

# 重新构建
docker-compose build --no-cache php83-fpm
docker-compose up -d php83-fpm
```

### 6. 网络问题

```bash
# 清理网络
docker-compose down
docker network prune -f
docker-compose up -d
```

---

## 📜 许可证

MIT License

---

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

---

## 📞 联系方式

如有问题，请提交 Issue。
