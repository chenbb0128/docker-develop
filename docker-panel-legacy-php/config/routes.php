<?php

declare(strict_types=1);

/**
 * 路由由控制器注解定义，此文件仅作为路由概览参考
 * 
 * 实际路由:
 * GET  /                       -> IndexController::index
 * POST /api/login              -> AuthController::login
 * POST /api/logout             -> AuthController::logout
 * GET  /api/check-auth         -> AuthController::checkAuth
 * GET  /api/containers         -> ContainerController::list
 * GET  /api/containers/{id}    -> ContainerController::show
 * POST /api/containers/{id}/start   -> ContainerController::start
 * POST /api/containers/{id}/stop    -> ContainerController::stop
 * POST /api/containers/{id}/restart -> ContainerController::restart
 * GET  /api/containers/{id}/logs    -> ContainerController::logs
 * GET  /api/containers/{id}/stats   -> ContainerController::stats
 */
