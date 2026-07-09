# Docker Develop 环境管理
# ============================
# 使用方法: make <command>

.PHONY: help build up down restart logs ps php-versions workspace

# 默认显示帮助
help:
	@echo "Docker Develop 环境管理命令"
	@echo "============================"
	@echo ""
	@echo "基础命令:"
	@echo "  make up              - 启动所有服务"
	@echo "  make down            - 停止所有服务"
	@echo "  make restart         - 重启所有服务"
	@echo "  make logs            - 查看服务日志"
	@echo "  make ps              - 查看运行中的服务"
	@echo ""
	@echo "Workspace 工作区:"
	@echo "  make workspace       - 进入 Workspace 容器"
	@echo "  make build-workspace - 构建 Workspace 容器"
	@echo "  make artisan cmd=xxx - 运行 Laravel Artisan 命令"
	@echo "  make composer cmd=x  - 运行 Composer 命令"
	@echo "  make npm cmd=xxx     - 运行 npm 命令"
	@echo ""
	@echo "PHP 版本管理:"
	@echo "  make build-php       - 构建所有 PHP 版本"
	@echo "  make build-php73     - 构建 PHP 7.3"
	@echo "  make build-php80     - 构建 PHP 8.0"
	@echo "  make build-php81     - 构建 PHP 8.1"
	@echo "  make build-php83     - 构建 PHP 8.3"
	@echo ""
	@echo "  make php-versions    - 显示所有 PHP 版本"
	@echo "  make shell-php73     - 进入 PHP 7.3 容器"
	@echo "  make shell-php80     - 进入 PHP 8.0 容器"
	@echo "  make shell-php81     - 进入 PHP 8.1 容器"
	@echo "  make shell-php83     - 进入 PHP 8.3 容器"
	@echo ""
	@echo "Docker 管理面板:"
	@echo "  make panel-setup     - 设置 Docker 管理面板"
	@echo "  访问地址: http://docker-panel.localhost"
	@echo "  默认账号: admin / docker123"

#---------------------------------------------
# 基础命令
#---------------------------------------------

up:
	docker-compose up -d nginx redis

up-all:
	docker-compose up -d nginx redis workspace

down:
	docker-compose down

restart:
	docker-compose restart

logs:
	docker-compose logs -f

ps:
	docker-compose ps

#---------------------------------------------
# Workspace 开发工具容器
#---------------------------------------------

build-workspace:
	docker-compose build workspace

workspace:
	docker-compose exec workspace bash

# Laravel Artisan 命令
# 用法: make artisan cmd="migrate"
artisan:
	docker-compose exec workspace php artisan $(cmd)

# Composer 命令
# 用法: make composer cmd="install"
composer:
	docker-compose exec workspace composer $(cmd)

# npm 命令
# 用法: make npm cmd="install"
npm:
	docker-compose exec workspace npm $(cmd)

# 运行队列 Worker (需要启用 Supervisor)
queue-work:
	docker-compose exec workspace php artisan queue:work --sleep=3 --tries=3

# 运行定时任务
schedule:
	docker-compose exec workspace php artisan schedule:run

#---------------------------------------------
# PHP 构建命令
#---------------------------------------------

build-php: build-php73 build-php80 build-php81 build-php83
	@echo "所有 PHP 版本构建完成!"

build-php73:
	docker-compose build php73-fpm

build-php80:
	docker-compose build php80-fpm

build-php81:
	docker-compose build php81-fpm

build-php83:
	docker-compose build php83-fpm

build-all: build-workspace build-php
	@echo "所有容器构建完成!"

#---------------------------------------------
# PHP 版本检查
#---------------------------------------------

php-versions:
	@echo "检查各 PHP 版本..."
	@docker-compose exec php73-fpm php -v 2>/dev/null || echo "php73-fpm 未运行"
	@docker-compose exec php80-fpm php -v 2>/dev/null || echo "php80-fpm 未运行"
	@docker-compose exec php81-fpm php -v 2>/dev/null || echo "php81-fpm 未运行"
	@docker-compose exec php83-fpm php -v 2>/dev/null || echo "php83-fpm 未运行"
	@docker-compose exec workspace php -v 2>/dev/null || echo "workspace 未运行"

#---------------------------------------------
# 进入容器
#---------------------------------------------

shell-php73:
	docker-compose exec php73-fpm bash

shell-php80:
	docker-compose exec php80-fpm bash

shell-php81:
	docker-compose exec php81-fpm bash

shell-php83:
	docker-compose exec php83-fpm bash

shell-nginx:
	docker-compose exec nginx sh

shell-redis:
	docker-compose exec redis redis-cli

#---------------------------------------------
# Docker 管理面板 (Hyperf + Swoole)
#---------------------------------------------

panel-install:
	@echo "安装 Docker 管理面板依赖..."
	docker-compose run --rm docker-panel composer install

panel-build:
	@echo "构建 Docker 管理面板..."
	docker-compose build docker-panel

panel-up:
	@echo "启动 Docker 管理面板..."
	docker-compose up -d docker-panel
	@echo ""
	@echo "✅ Docker 管理面板已启动!"
	@echo "📍 访问地址: http://localhost:9501"
	@echo "👤 默认账号: admin / docker123"

panel-logs:
	docker-compose logs -f docker-panel

panel-shell:
	docker-compose exec docker-panel bash
