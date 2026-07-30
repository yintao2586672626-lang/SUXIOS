# 单实例缓存与任务锁部署约束

## 当前支持范围

宿析OS当前只支持：

- 一台应用主机；
- 一个活动 release；
- PHP-FPM、定时任务和竞对任务共享同一组本机持久化状态目录。

当前不支持多台应用服务器、多副本负载均衡或新旧 release 并行处理业务请求。启用这些模式前，必须先把登录会话、竞对任务归属/消费状态和幂等结果迁移到共享 Redis 或数据库，并把 `flock` 替换为 Redis 原子锁或数据库事务锁。

## 必需配置

生产环境必须设置：

```dotenv
SUXIOS_DEPLOYMENT_MODE=single_instance
SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE=true
SUXIOS_CACHE_PATH=/var/lib/suxios/app-cache
SUXIOS_LOCAL_LOCK_PATH=/var/lib/suxios/app-locks
```

这些值写入服务器的 `/etc/suxios/suxios.env`。每个 release 的 `.env` 必须链接到该文件，不在 OTA 专用 `/etc/suxios/ota-collector.env` 中重复维护。

两个目录必须：

- 使用绝对路径；
- 位于 `/var/www/suxios/releases` 和 `current` 软链接之外；
- 归 PHP-FPM/任务执行用户所有并可读写；
- 不随 release 删除、回滚或清理。

`SUXIOS_CACHE_PATH` 保存宿析OS登录 Token 摘要索引及竞对任务状态。`SUXIOS_LOCAL_LOCK_PATH` 保存同机并发锁；竞对任务和公开接口限流使用独立子目录。竞对报告的最终幂等结果同时写入数据库 `report_fingerprint`，即使进程在数据库提交后、缓存确认前退出，重试也会回读同一条记录。

仓库外 `RELEASE_ENV_FILE` 只用于发布门禁取证，不会自动注入运行进程。PHP-FPM、CLI 和 systemd timer 通过 release 的 `.env -> /etc/suxios/suxios.env` 链接读取同一组配置。

## 发布前检查

```bash
sudo install -d -m 0700 -o www-data -g www-data \
  /var/lib/suxios/app-cache \
  /var/lib/suxios/app-locks

test "$(readlink -f /var/www/suxios/current/.env)" = "/etc/suxios/suxios.env"
sudo -u www-data php scripts/verify_single_instance_state_paths.php
```

该预检会实际写入并回读一个短期缓存探针、实际获取一次作用域文件锁，随后删除探针。只检查目录存在不算通过。

数据库迁移完成后继续执行：

```bash
sudo -u www-data php scripts/check_database_version.php
sudo -u www-data php scripts/verify_competitor_report_idempotency_schema.php
```

第二个检查会确认 `report_fingerprint` 为可空 `char(64)`，并确认唯一索引真实存在。生产持久化模式下若该索引缺失，竞对报告接口会返回 503，不再静默降级为仅缓存幂等。

任一检查未通过时禁止切换 `current`。发布时先停止领取新竞对任务，等待在途任务结束，再切换 release 并重新加载 PHP-FPM。

重新加载后必须请求 `/api/health`，并确认 HTTP 200，且 `checks.local_state`、`checks.cache`、`checks.lock`、`checks.database_schema`、`checks.competitor_report_idempotency` 均为 `ok`。健康响应只返回状态和稳定错误码，不返回目录、数据库连接信息或凭证。完整迁移目录未登记、持久化路径或竞对幂等索引异常时会返回 HTTP 503。

## 首次启用

首次从 release 内 `runtime/cache` 切换到外置目录时，采用一次可预期的重新登录，不复制旧缓存文件：

1. 暂停 OTA timer，并通知当前操作人员停止领取新的竞对任务；
2. 等待在途竞对上报完成；
3. 创建外置目录、更新 `/etc/suxios/suxios.env` 并运行预检；
4. 在新 release 执行 `php think db:migrate`，再运行两个数据库检查，确认 `20260725_add_competitor_report_fingerprint.sql` 已登记且唯一索引有效；
5. 切换 `current`、重新加载 PHP-FPM，再恢复 timer；
6. 用户重新登录一次，确认登录后刷新页面仍保持会话；
7. 领取一条测试竞对任务并完成上报，确认保存和回读成功。

不复制旧 `runtime/cache`，避免把历史 Token、过期任务和 release 私有缓存整体迁入长期目录。后续 release 共用外置目录，不再因版本切换强制退出。

## 横向扩展门槛

多实例改造必须同时完成，不能只把文件缓存换成 Redis：

1. 登录会话只保存 Token 哈希，并由共享 Redis/数据库读取和撤销；
2. 竞对任务领取、消费、完成结果具有共享持久状态；
3. 领取与消费使用原子比较更新，避免重复领取；
4. 幂等结果能跨实例、跨重启查询；
5. 双实例验证覆盖 A 登录/B 鉴权、A 领取/B 上报、并发领取、发布中上报和重放。
