# Docker Develop Demo

这是一个最小 PHP-FPM 验证项目。新用户保留默认 `.env` 时，`HOST_PROJECT_PATH=./data` 会把本目录挂载到容器 `/develop/demo`。

启动默认 PHP 8.3 环境后访问：

```text
http://localhost
```

能看到 Demo 页面，就说明 Nginx、PHP-FPM 和路径映射已经跑通。