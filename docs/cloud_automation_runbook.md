# 宿析OS 云端自动化运行手册

## 运行链路

```text
本地授权采集携程/美团
  -> cloud-data-bridge:run 生成无凭证数据包并经 SCP 放入云端 inbox
  -> 云端校验 SHA256、酒店/数据源绑定、日期和来源
  -> 云端 MySQL 保存与字段级回读
  -> cloud-automation:run --mode=daily
  -> 复用 AiDailyReportService 生成已保存日报
  -> 企业微信投递队列
  -> 失败时仅重发已保存消息
```

云端不会保存或运行 OTA 浏览器 Profile，不会自动绕过登录、验证码或风控。数据缺失、登录失效、目标日期错误、门店/租户不符、字段证据缺失和回读失败都会保持显式阻塞，不使用 `0` 代替。

## 命令

```bash
php think cloud-automation:run --mode=status
php think cloud-automation:run --mode=health --target-date=2026-07-21 --no-push
php think cloud-automation:run --mode=daily --target-date=2026-07-21 --no-push
php think cloud-automation:run --mode=weekly --target-date=2026-07-21 --no-push
php think cloud-automation:run --mode=retry
```

- `daily`：只在门店、日期和 MySQL 回读门禁通过后生成日报；异常先发数据健康预警。
- `health`：读取已保存巡检/同步状态，不触发 OTA 采集或日报生成。
- `weekly`：汇总最近 7 天已保存日报；缺日明确显示，不补造数据。
- `retry`：只读取 `/var/lib/suxios-cloud-automation/deliveries` 内的消息；不调用巡检、采集或报告生成。
- `--no-push`：执行检查/生成但不创建企微投递记录，也不发送消息。
- 默认不启用 LLM；需要调用已配置的远程模型时显式增加 `--use-llm`。

## 本地到云端数据桥

桥接文件只允许标准化 OTA 事实和来源编号，不允许 `Cookie`、`Authorization`、Token、密码、Webhook 或浏览器 Profile。绑定文件必须显式写出本地酒店、云端酒店以及每个平台两端的数据源编号，禁止按酒店名称或第一条数据自动猜测。

```powershell
php think cloud-data-bridge:run --mode=export `
  --target-date=2026-07-21 `
  --binding-file=deploy/cloud-data-bridge-binding.json `
  --output-file=runtime/cloud_bridge/outbox/ota-2026-07-21.json

powershell -ExecutionPolicy Bypass -File scripts/upload_cloud_ota_bundle.ps1 `
  -BundlePath runtime/cloud_bridge/outbox/ota-2026-07-21.json
```

云端收件箱处理与检查：

```bash
sudo -u www-data env \
  CLOUD_DATA_BRIDGE_STATE_DIR=/var/lib/suxios-cloud-automation/bridge \
  CLOUD_DATA_BRIDGE_ACTOR_USER_ID=1 \
  php think cloud-data-bridge:run --mode=status

sudo systemctl start suxios-cloud-data-bridge.service
```

- 只有本地 `validation_status` 可信且 `readback_verified=1` 的记录会进入数据包。
- 缺少目标日数据或登录失效仍会形成显式健康包，但不会补造 `0`，也不会触发报告生成。
- 数据包哈希、目标日期、门店或数据源不匹配时拒绝入库。
- 同一来源追踪记录使用确定性桥接追踪号，重复上传执行更新和再次回读，不生成重复事实。
- 云端导入器不调用浏览器采集，也不生成报告；后续 `daily` 任务仍需通过数据健康门禁。

## systemd 计划

| 任务 | 时间（Asia/Shanghai） | 作用 |
|---|---:|---|
| `suxios-cloud-ota-daily.timer` | 每日 08:30 | 昨日 OTA 最终采集，仅一次 |
| `suxios-cloud-ota-realtime.timer` | 每小时 :05 | 当日 OTA 实时快照，独立于昨日采集 |
| `suxios-cloud-daily.timer` | 每日 09:00 | 消费昨日最终回执，生成正式日报或缺口状态 |
| `suxios-cloud-health.timer` | 每日 09:10、14:10、20:10 | 数据健康巡检 |
| `suxios-cloud-weekly.timer` | 每周一 09:30 | 已保存日报周度复盘 |
| `suxios-cloud-retry.timer` | 每 15 分钟 | 仅消息投递重试 |
| `suxios-cloud-data-bridge.timer` | 每 5 分钟 | 只处理已上传的无凭证 OTA 数据包 |

采集 timer 与日报 timer 是两条链：昨天数据在 08:30 只采集一次；今天实时按小时独立采集；09:00 的日报只消费已经保存并回读的昨天最终数据。采集服务限制为 `MemoryMax=512M`、`CPUQuota=60%`、`TasksMax=32`，不会把 Cookie、Webhook 或帐号信息写入 systemd 参数。

按门店灰度时，使用 `suxios-cloud-hotel-daily@<hotel_id>.timer` 和
`suxios-cloud-hotel-health@<hotel_id>.timer`。全局与按门店调度只能启用一种；
切换前先停用旧的全局 timer，再启用明确的门店实例：

```bash
sudo systemctl disable --now suxios-cloud-daily.timer suxios-cloud-health.timer
sudo systemctl enable --now suxios-cloud-hotel-daily@123.timer
sudo systemctl enable --now suxios-cloud-hotel-health@123.timer
```

门店实例仍共享全局串行锁。服务最多等待 1500 秒，锁仍被占用时以临时失败码退出，
由 systemd 每 2 分钟重试；不能把锁冲突当作成功。模板 timer 同时声明与旧全局
timer 冲突，防止两套计划并行启用。恢复全局调度时先停用所有门店实例，再启用
`suxios-cloud-daily.timer` 和 `suxios-cloud-health.timer`。

## 首次启用检查

1. 云端至少存在一条启用酒店记录，并有正确的租户/门店范围。
2. 携程和美团数据源分别绑定到该酒店。云端 Profile 仅允许服务器拥有者本人的 `single_user_local` 兼容模式，不是多租户集中代采方案；本机 Profile、Cookie 和本机测试文件不得复制到云端，也不得写入 unit、日志或 Git。
3. 目标日数据已上传到 `online_daily_data`，并能按平台、酒店、日期和质量状态回读。
4. `competitor_wechat_robot` 中有该酒店启用的企业微信机器人。
5. 先运行 `health --no-push`，再运行 `daily --no-push`，最后才去掉 `--no-push` 做一次真实发送。

## 云端 OTA 采集启用前检查

先在本机运行只读检查，不会读取或输出云端 Cookie、Webhook、Profile 内容：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/inspect_tencent_cloud_ota_runtime.ps1
```

只有以下条件同时满足，才允许在云端启用 `suxios-cloud-ota-daily.timer` 与 `suxios-cloud-ota-realtime.timer`：

1. 云端 release 已包含 `--daily-only` 和 `--realtime-only`；
2. 两个 OTA unit 已安装；
3. 从 `deploy/systemd/ota-collector.env.example` 创建 `/etc/suxios/ota-collector.env`（权限 `0600`），显式配置服务器拥有者用户、服务器设备、单一系统酒店、数据源 ID 白名单和平台白名单；
4. 采集用户必须与酒店同租户；若服务器拥有者是跨租户超级管理员，则还必须存在该用户、该酒店、该酒店租户完全匹配且当前有效的 `can_fetch_online_data=1` 授权记录。超级管理员身份本身不能替代显式酒店授权；
5. 每条白名单 `platform_data_sources` 必须同时满足：同一 `tenant_id`、`user_id`、`system_hotel_id`，`ingestion_method=browser_profile`，`config_json.source_method=single_user_local`，且 `config_json.collector_device_id_hash` 等于配置设备 ID 的 SHA-256；
6. 同一个浏览器 Profile 账号只能选择一条数据源；旧结构中 business/traffic 投影行不得同时进入白名单，否则会造成同一会话重复采集；
7. 首次绑定先使用 `--bind-cloud-scope` 预览；输出必须为 `status=confirmation_required` 且 `database_write_performed=false`。确认用户、设备、酒店、平台和数据源范围后，再追加 `--confirm-cloud-scope-binding` 写入绑定。更换设备必须在新设备重新登录后显式追加 `--rotate-cloud-device-binding`，不得静默继承旧设备会话；
8. 启用 timer 前，使用同一组范围参数执行 `--validate-cloud-scope`。成功输出 `status=scope_ready_for_current_session_probe` 仅证明绑定可进入会话探测，不会访问 OTA、不写业务数据，也不代表当前登录仍有效；两个 OTA service 还会在每次正式采集前通过 `ExecStartPre` 重跑该预检；

绑定操作示例（只包含非会话范围标识，不要把 Cookie、Profile 或令牌写入命令）：

```bash
set -a
. /etc/suxios/ota-collector.env
set +a
COMMON_SCOPE="--collector-mode=single_user_local --collector-user-id=${SUXIOS_OTA_CLOUD_USER_ID} --collector-device-id=${SUXIOS_OTA_CLOUD_DEVICE_ID} --hotel-id=${SUXIOS_OTA_CLOUD_HOTEL_ID} --source-ids=${SUXIOS_OTA_CLOUD_SOURCE_IDS} --platforms=${SUXIOS_OTA_CLOUD_PLATFORMS}"
SUXIOS_OTA_CLOUD_COLLECTOR=1 php think online-data:auto-fetch --bind-cloud-scope ${COMMON_SCOPE} --no-interaction
SUXIOS_OTA_CLOUD_COLLECTOR=1 php think online-data:auto-fetch --bind-cloud-scope --confirm-cloud-scope-binding ${COMMON_SCOPE} --no-interaction
SUXIOS_OTA_CLOUD_COLLECTOR=1 php think online-data:auto-fetch --validate-cloud-scope ${COMMON_SCOPE} --no-interaction
```

撤销时先停用 timer，再预览并确认清除绑定；清除只移除采集器范围元数据，不删除 Profile 配置或历史业务数据：

```bash
sudo systemctl disable --now suxios-cloud-ota-daily.timer suxios-cloud-ota-realtime.timer
SUXIOS_OTA_CLOUD_COLLECTOR=1 php think online-data:auto-fetch --unbind-cloud-scope ${COMMON_SCOPE} --no-interaction
SUXIOS_OTA_CLOUD_COLLECTOR=1 php think online-data:auto-fetch --unbind-cloud-scope --confirm-cloud-scope-unbind ${COMMON_SCOPE} --no-interaction
```
5. 管理员已在这台服务器的受控浏览器完成白名单平台授权；每次采集仍会用本次进程的登录状态和平台酒店身份做当前会话门禁，历史布尔标记不能代替；
6. 先对同一组 `--hotel-id`、`--source-ids`、`--platforms` 跑一次保存与回读验证，再显式启用定时器。安装 unit 不代表允许自动启用。

云端命令缺少任一范围参数、数据源归属或设备哈希不一致时，以配置错误退出，不回退为所有启用酒店扫描。需要让其他用户或其他设备采集时，使用设备侧 `OtaLocalCollectorService` 任务领取/上传链路，不复用此云端 Profile。

检查通过后的安装动作必须在已发布、干净的云端 release 上执行；不要从本机复制 Cookie 或未提交工作区。

## 回滚

应用采用 `/var/www/suxios/current` 指向 release 的原子软链接发布。回滚时把该链接切回上一个保留的 release，重新加载 PHP-FPM；投递队列位于 `/var/lib/suxios-cloud-automation`，不会因应用回滚丢失。

复制新 release 后必须保持该 release 的 `runtime/` 和 `storage/` 为 `www-data:www-data` 且目录可写；切换前用 `sudo -u www-data test -w` 验证。否则普通健康接口可能仍为 200，但需要落盘的巡检端点会返回 500。

登录缓存与竞对任务锁不得保存在 release 自身的 `runtime/`。生产环境必须按
[`deployment_single_instance_state.md`](deployment_single_instance_state.md)
配置 release 外部持久目录，并在切换 `current` 前运行
`sudo -u www-data php scripts/verify_single_instance_state_paths.php`。
