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
| `suxios-cloud-daily.timer` | 每日 08:10 | 昨日经营闭环 |
| `suxios-cloud-health.timer` | 每日 09:10、14:10、20:10 | 数据健康巡检 |
| `suxios-cloud-weekly.timer` | 每周一 09:30 | 已保存日报周度复盘 |
| `suxios-cloud-retry.timer` | 每 15 分钟 | 仅消息投递重试 |
| `suxios-cloud-data-bridge.timer` | 每 5 分钟 | 只处理已上传的无凭证 OTA 数据包 |

服务按一个全局文件锁串行运行，限制为 `MemoryMax=512M`、`CPUQuota=80%`、`TasksMax=32`，适配 2 核 2GB 云服务器。

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
2. 携程和美团数据源分别绑定到该酒店；授权登录仍在本地浏览器完成。
3. 目标日数据已上传到 `online_daily_data`，并能按平台、酒店、日期和质量状态回读。
4. `competitor_wechat_robot` 中有该酒店启用的企业微信机器人。
5. 先运行 `health --no-push`，再运行 `daily --no-push`，最后才去掉 `--no-push` 做一次真实发送。

## 回滚

应用采用 `/var/www/suxios/current` 指向 release 的原子软链接发布。回滚时把该链接切回上一个保留的 release，重新加载 PHP-FPM；投递队列位于 `/var/lib/suxios-cloud-automation`，不会因应用回滚丢失。

复制新 release 后必须保持该 release 的 `runtime/` 和 `storage/` 为 `www-data:www-data` 且目录可写；切换前用 `sudo -u www-data test -w` 验证。否则普通健康接口可能仍为 200，但需要落盘的巡检端点会返回 500。
