# Docker Develop command shortcuts

SHELL := /bin/sh
COMPOSE := $(shell if command -v docker-compose >/dev/null 2>&1; then echo docker-compose; else echo docker compose; fi)

.PHONY: help doctor start panel demo stop down ps logs restart build-panel build-nginx build-php83 php83 shell-panel shell-nginx shell-php83 go workspace composer-install

help:
	@echo "Docker Develop commands"
	@echo "======================="
	@echo "make doctor            - run cross-platform environment doctor"
	@echo "make start             - start docker-panel"
	@echo "make demo              - start nginx + php83-fpm + redis + docker-panel"
	@echo "make stop              - stop all containers for this compose project"
	@echo "make ps                - show service status"
	@echo "make logs              - follow docker-panel logs"
	@echo "make restart           - restart docker-panel"
	@echo "make build-panel       - rebuild docker-panel image"
	@echo "make build-nginx       - rebuild nginx image"
	@echo "make build-php83       - rebuild PHP 8.3 image"
	@echo "make php83             - start PHP 8.3 web stack"
	@echo "make shell-panel       - shell into docker-panel"
	@echo "make shell-nginx       - shell into nginx"
	@echo "make shell-php83       - shell into php83-fpm"

doctor:
	bash ./doctor.sh

start panel:
	$(COMPOSE) up -d docker-panel

demo php83:
	$(COMPOSE) up -d nginx php83-fpm redis docker-panel

stop down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f docker-panel

restart:
	$(COMPOSE) restart docker-panel

build-panel:
	$(COMPOSE) build docker-panel

build-nginx:
	$(COMPOSE) build nginx

build-php83:
	$(COMPOSE) build php83-fpm

shell-panel:
	$(COMPOSE) exec docker-panel bash

shell-nginx:
	$(COMPOSE) exec nginx sh

shell-php83:
	$(COMPOSE) exec php83-fpm sh

go:
	$(COMPOSE) up -d go redis docker-panel

workspace:
	$(COMPOSE) up -d workspace docker-panel

composer-install:
	$(COMPOSE) exec docker-panel composer install --no-interaction --prefer-dist
