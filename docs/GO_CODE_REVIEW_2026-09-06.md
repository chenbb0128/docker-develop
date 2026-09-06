# Go 版本代码检查：2026-09-06

## 范围与结论

检查基准：本地分支 `codex/docker-panel-project-runtime`，提交 `2be5b01`。本地远程跟踪引用指向同一提交；本次实际读取 GitHub 远程失败（连接重置及超时），不能据此确认远程此刻没有更新。

检查覆盖当前 Go 源码、前端模板、Compose/Dockerfile、初始化与启动脚本及 README。当前正在运行的面板容器在 `docker-compose ps docker-panel` 中仍显示 `docker-php-entrypoi…`，因此没有把旧 PHP 容器的运行情况当作 Go 版验收结果。

Go 后端能够通过已有单元测试和静态检查，但一键启动与管理流程仍有确定缺陷。本轮变更为 README 和本报告；下列代码问题尚未修复。P1 表示优先处理的主流程或误操作问题，P2 表示特定场景故障或能力缺口。

> 后续进展（2026-09-07）：启动、初始化和诊断入口已切换到 Go，并补充 WSL2 路径适配及回归测试。下文保留原检查基准下的发现，不代表这些启动问题仍未修复；真实容器启停尚待验证，其余问题未在本次 WSL2 适配中处理。

## P1：停止全部会影响其他项目

位置：[env_handlers.go](../docker-panel/internal/httpapi/env_handlers.go#L93)。

`/api/env/stop-all` 调用整个 Docker 引擎的容器列表，对所有 running 容器执行停止，仅以名称包含 `docker-panel` 作为排除条件。未检查 `com.docker.compose.project` 标签，和前端“停止所有开发容器”的提示范围不一致。

触发：同一 Docker 引擎中运行其他仓库的数据库或服务，调用该接口会一起停掉它们。依据为逐行代码检查，未对真实容器执行停止操作。

建议：按本仓库明确的 Compose 项目标识过滤，并精确排除面板服务；增加“其他项目容器不得被操作”的接口测试。

## P1：动态按钮的 HTML 引号截断

位置：[index.html](../docker-panel/public/index.html#L38)，项目、环境、容器渲染分别位于第 51、52、53 行。

`arg()` 返回 JSON 双引号字符串，再直接拼入双引号包围的 `onclick` 属性。普通标识 `demo-go` 就会生成：

```html
<button onclick="startProject("demo-go")">启动</button>
```

属性在第二个双引号处结束，实际处理器变成 `startProject(`，编译失败。相同写法影响启动、重启、编辑、删除、日志和容器操作等。

最小复现：用 Node VM 载入实际页面函数，调用 `renderProjectCard`，提取生成属性并编译处理器。普通示例项目的 12 个处理器全部无效；顶层脚本语法检查却通过。该复现未调用真实 API，也不是浏览器端到端测试。

建议：使用 `data-*` 和事件监听器绑定，避免 HTML 属性与 JavaScript 字符串双层拼接；增加真实按钮点击验证。

## P1：双击启动仍会被 PHP 诊断拦截

位置：[doctor.ps1](../doctor.ps1#L196)、[doctor.sh](../doctor.sh#L216)、[start-panel.bat](../start-panel.bat#L57)。

诊断把 `php83-fpm` 视为核心服务，但当前 Compose 已移除该服务。`start-panel.bat` 会在诊断返回失败时退出，无法进入启动 Go 面板阶段；Shell 入口也存在相同旧要求。

实测 `docker compose --env-file .env.example config --services`，与诊断要求对比后缺失项为 `php83-fpm`。未运行会修改本机 `.env` 的初始化脚本。

此外，`scripts/init-env.*` 仍写入 PHP/Java 变量，`scaffold.ps1` 仍使用旧项目类型，`Makefile` 和 `PROJECTS.md` 尚未完成 Go 化。

建议：统一启动入口、初始化、诊断与脚手架；以全新配置和从 PHP 升级两个场景做验收。README 已给出显式构建 Go 面板的替代步骤。

## P2：项目配置缺少并发保护与提交一致性

位置：[manager_actions.go](../docker-panel/internal/project/manager_actions.go#L14)、[manager_compose.go](../docker-panel/internal/project/manager_compose.go#L14)、[project_json.go](../docker-panel/internal/storage/project_json.go#L32)。

保存使用“读全量列表 → 修改 → 覆盖写入”，没有覆盖整个过程的锁；Compose 也直接读取并覆盖同一个主文件。两个保存请求可以基于相同旧内容分别写入，后一次覆盖前一次。读者也可能读到正在截断重写的文件。

`Scaffold` 先写 Compose、再保存项目记录；后一步失败没有回滚，可能留下孤立服务块。此项是静态代码确定的执行路径，尚未进行文件故障注入或并发压力复现。

建议：将读改写放在同一事务或临界区，使用临时文件原子替换；在落盘前验证 Compose，并保证项目记录和生成配置的一致性。

## P2：鉴权与命令开关没有形成有效约束

位置：[handlers.go](../docker-panel/internal/httpapi/handlers.go#L47)、[router.go](../docker-panel/internal/httpapi/router.go#L67)、[manager_actions.go](../docker-panel/internal/project/manager_actions.go#L151)。

即使设置 `PANEL_AUTH_ENABLED=true`，登录仍固定返回 `local-dev`，路由没有鉴权中间件。`PANEL_UNSAFE_TERMINAL` 只检查 `/api/terminal/exec`；项目 `/run` 的 `custom` 命令未使用该开关，仅有少量危险命令字符串过滤。

默认本机绑定降低了暴露范围，但这些配置不能被当作访问控制。原生 `go run` 默认监听所有网卡，也不能仅靠 `PANEL_PUBLIC_BIND` 改变监听行为。

建议：实现真实鉴权或明确拒绝未实现的启用选项；统一自定义命令策略。README 已写明只适用于可信本机环境。

## P2：修改 .env 后，启动任务可能继续使用旧值

位置：[config.go](../docker-panel/internal/config/config.go#L57)、[runner.go](../docker-panel/internal/compose/runner.go#L165)。

进程启动时 `godotenv.Load` 把配置写入进程环境；每次 Compose 执行又继承 `os.Environ()`。runner 虽然重新读取文件，但主要只覆盖相对宿主机路径等少数项，数据库密码、Go 版本、绝对路径等旧值仍可能作为高优先级环境变量传给 Compose。

触发：通过配置接口修改 `.env` 后直接点击项目启动，结果与新文件内容不一致。依据为配置优先级和执行路径检查，未修改用户真实配置来试验。

建议：在每次任务开始时生成一致的最新配置快照，避免旧 dotenv 值残留。当前操作办法是在修改 `.env` 后重启面板，再重新创建受影响服务。

## P2：running 被误当作应用已就绪

位置：[manager_taskflow.go](../docker-panel/internal/project/manager_taskflow.go#L73)、[service.go](../docker-panel/internal/project/service.go#L61)、[env_handlers.go](../docker-panel/internal/httpapi/env_handlers.go#L60)。

项目任务第一次等待约 3 秒后，只要 `compose ps --status running` 包含服务名就返回成功。此时容器可能仍在下载模块或编译，应用也可能监听错误端口；数据依赖的健康状态与业务 HTTP 没有作为完整成功条件。环境预设启动更是只根据 `up -d` 的退出码成功返回。

建议：区分构建、运行、就绪、失败状态；允许配置 TCP/HTTP 就绪探测和等待超时，失败时保留原始原因。避免把所有启动错误都自动当成需要重建镜像。

## P2：构建过程的日志不是实时流

位置：[runner.go](../docker-panel/internal/compose/runner.go#L50)、[manager_taskflow.go](../docker-panel/internal/project/manager_taskflow.go#L53)。

stdout/stderr 先写入内存 `bytes.Buffer`，`cmd.Run()` 结束后才返回，由任务流程统一写入日志文件。前端即使每秒轮询，也无法在漫长构建中看到逐行进度，容易被误认为卡住。

建议：边读取子进程输出边写任务日志，并限制日志大小；补充取消、同项目互斥和重启后的中断标记。当前 `PANEL_TASK_RETENTION` 未接入清理，后台 goroutine 也未接入进程退出后的任务恢复。

## P2：静态站点新增端口会触发正则 panic

位置：[site_handlers.go](../docker-panel/internal/httpapi/site_handlers.go#L216)。

`ensureNginxPort` 使用了 `(?!...)` 负向前瞻，Go `regexp` 不支持该语法。新端口不存在时会执行 `regexp.MustCompile` 并 panic，HTTP recoverer 将其转成 500。站点文件在此前已经写入，失败还会留下部分状态。

本次用 Go AST 提取 `internal/` 下 20 个字面量 `regexp.MustCompile` 参数，再交给 Go 正则编译器；发现 1 个无效表达式，错误为 `invalid or unsupported Perl syntax: (?!`。未写入真实站点文件。

建议：使用结构化 YAML 或兼容 Go 的明确扫描逻辑，且在所有检查通过后统一落盘。

## P2：配置校验没有检查待保存内容

位置：[config_handlers.go](../docker-panel/internal/httpapi/config_handlers.go#L228)。

校验接口支持传入 `content`，但 Compose 分支只调用现有主文件的 `compose config`，Nginx 分支只执行运行容器的 `nginx -t`。因此磁盘配置合法时，即使传入的候选配置有错误也会显示通过；后续保存接口又直接写入。

建议：对候选内容创建隔离校验文件，成功后再保存；保存前再次校验，并与备份、回滚保持一致。本轮没有向运行面板提交配置变更。

## P2：容器端口与接口错误被前端丢弃

位置：[container.go](../docker-panel/internal/domain/container.go#L10)、[index.html](../docker-panel/public/index.html#L44)。

后端 `ports` 为字符串，例如 `8004:8080`；前端只处理数组，导致端口列显示 `-`。列表请求失败时，catch 先写错误，随后无条件执行 render，错误又被“暂无项目/容器/预设”覆盖。

使用实际渲染函数和模拟响应已复现两种情况。建议统一响应类型，把错误、加载、空列表作为不同状态保留。

## 其他核对结果

- `.env` 仍被 Git 跟踪（`git ls-files --error-unmatch .env` 成功）；`.gitignore` 不会停止跟踪已有文件。本轮未读取或输出本机凭据内容，也未变更其跟踪状态。
- Go 服务共用一个宿主机根目录挂载，路径校验只检查非空等基本条件；缺少端口占用、目录存在、服务名唯一性与依赖服务存在性预检。
- 主 Compose 包含具体本机项目的服务块，配置和业务命令与仓库基础设施混在一起；独立生成文件的配置项尚未使用。
- `redis` 的 Compose `command: --requirepass ...` 覆盖了 Dockerfile 中带配置文件参数的 CMD，因而 `services/redis/redis.conf` 不会按该 CMD 加载；不能把“编辑该文件再重启”当作有效配置流程。
- 面板 Dockerfile 未接入业务镜像的 `GOPROXY`/`CHANGE_SOURCE` 构建参数。runner 强制关闭 BuildKit；此事实不能直接解释所有 TLS、镜像标签或网络错误。
- 默认 `go run` 没有热重载；入口探测只看文件、目录等条件，不能保证多入口项目选择正确。
- `internal/web/assets/index.html` 是刻意简化的兜底提示页，不是完整前端副本；部署完整 UI 应保留 `public/`。

## 本次验证

| 检查 | 结果与范围 |
| --- | --- |
| `go test -mod=readonly -count=1 ./...` | 通过，使用已有依赖缓存，关闭模块代理下载 |
| `go vet -mod=readonly ./...` | 通过；Go 遥测目录权限有警告，退出码为 0 |
| `docker compose --env-file .env.example config --quiet` | 通过，只证明 Compose 配置合法 |
| Compose 服务名与 doctor 必需项比较 | 确认缺少 `php83-fpm` |
| 前端顶层脚本语法 | 通过，不覆盖动态生成的 HTML 属性 |
| 前端函数最小复现 | 12/12 项目处理器无效；端口与错误展示缺陷均复现 |
| Go 字面量正则编译扫描 | 20 个表达式中 1 个无效 |
| 当前面板容器状态 | 仍显示旧 PHP 入口，不作为 Go 版运行验收 |
| 远程最新提交核对 | GitHub 连接失败，无法确认 |

现有测试只有配置加载、部分查询接口、项目状态、服务名处理和文件读取等覆盖；Compose runner、任务管理、完整前端和并发持久化没有对应测试。未运行镜像下载、重建、真实容器启停或业务 HTTP 全流程测试。

## 建议修复顺序

1. 限定全局停止范围，修复动态按钮，清理启动/诊断中的 PHP 依赖。
2. 补齐配置写入保护、候选内容校验和 .env 更新行为。
3. 完成就绪检测、实时日志、同项目任务互斥和超时/中断处理。
4. 修复静态站点端口、数据服务配置生效方式和前端异常状态。
5. 用独立示例 Go 项目完成“新建 → 启动 → HTTP 可访问 → 停止 → 重启 → 删除环境且保留源码与共享数据”的验收。
