# OTA 数据保存与知识沉淀规则

## 目标

把携程、美团等 OTA 渠道数据稳定保存为可追溯的数据资产，支撑链路：

`OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策`

本文只定义 OTA 渠道数据，不把 OTA 数据包装成全酒店经营事实。

## 数据分层

| 层级 | 保存位置 | 用途 | 要求 |
| --- | --- | --- | --- |
| 采集证据层 | `platform_data_sync_tasks`、`platform_data_raw_records`、`ota_ctrip_capture_runs`、`ota_ctrip_capture_gaps` | 记录是否采到、哪里缺、何时采 | 保留状态和缺口，不用成功状态掩盖失败 |
| 标准日数据层 | `online_daily_data` | 日报、经营看板、AI 判断的主输入 | 必须有日期、平台、类型、周期、门店绑定和校验状态 |
| 字段事实层 | `ota_ctrip_metric_facts`、后续可补美团同类事实表 | 指标级来源证明 | 保留 `metric_key`、`source_path`、`capture_section`、`endpoint_id` |
| 实体快照层 | `ota_ctrip_entity_snapshots`、订单/点评匹配表 | 房型、订单、点评等实体知识 | 敏感信息必须脱敏或只存必要证据 |
| 运营知识层 | 日报、AI 诊断、执行记录 | 异常判断和动作建议 | 必须引用上游 OTA 证据，不直接替代原始数据 |

## `online_daily_data` 标准业务键

用于判断是否重复入库：

`source + platform + data_type + dimension + compare_type + data_date + data_period + snapshot_bucket + system_hotel_id + hotel_id/hotel_name`

规则：

- `historical_daily`：同一天同门店同平台同指标只保留一条最终历史数据。
- `realtime_snapshot`：按 `snapshot_bucket` 保留多次快照，不覆盖历史快照。
- `next_30_days`：未来需求预测窗口，`is_final=0`；只作为 OTA 渠道预测信号，不计入当日/历史流量事实。
- `dimension` 允许较长来源路径，避免字段截断造成误判重复。
- 缺失字段写入 `validation_status` 和 `validation_flags`，不补假值。

## 每日保存节奏

| 时间 | 保存内容 | 周期字段 | 说明 |
| --- | --- | --- | --- |
| 00:00-08:00 | 不强制要求昨日最终数据 | - | 昨日数据可能未结算；页面应展示最近一次已入库历史数据和采集时间 |
| 08:00 后 | 昨日最终数据 | `historical_daily` / `is_final=1` | 日报、正式分析、AI 诊断使用此口径 |
| 白天每 2 小时左右 | 当天实时快照 | `realtime_snapshot` / `is_final=0` | 用于巡检，不作为昨日最终结论 |
| 历史补存 | 可查询日期范围内的历史数据 | `historical_daily` | 按业务键去重，保留缺口记录 |
| 预测窗口更新 | OTA 未来需求预测 | `next_30_days` / `is_final=0` | 可滚动覆盖同业务键预测，不替代当日或历史事实 |

## 保存入口

| 入口 | 用途 | 写入 |
| --- | --- | --- |
| `POST /api/online-data/auto-fetch` | 页面或手动触发采集 | `online_daily_data` |
| `php think online-data:auto-fetch` | 定时采集昨日历史和当天快照 | `online_daily_data`、采集状态 |
| `POST /api/online-data/capture-ctrip-browser` | 授权 Profile 的携程采集 | `online_daily_data`、`ota_ctrip_*` |
| `POST /api/online-data/capture-meituan-browser` | 授权 Profile 的美团采集 | `online_daily_data` |
| `php scripts/inspect_ota_data_assets.php --markdown` | 只读盘点数据资产 | 不写库 |
| `php scripts/verify_ota_daily_save_plan.php --markdown` | 只读核验每日保存和历史缺口 | 不写库 |

## 合规边界

- 不保存明文 Cookie、Authorization、Bearer Token、密码、短信验证码。
- `source_trace_id` 只保存脱敏短标识，不保存完整 URL 或凭证。
- 点评、订单、IM 等数据只保存业务必要字段；用户身份匹配必须保持证据状态，不做反向身份扩展。
- 原始 OTA 证据只用于 OTA 渠道分析；投资判断必须继续标注数据范围。
- 采集失败、缺字段、缺门店绑定、字段异常必须显式展示为缺口或异常。

## 日常验收命令

```powershell
C:\xampp\php\php.exe scripts\inspect_ota_data_assets.php --markdown
C:\xampp\php\php.exe scripts\verify_ota_daily_save_plan.php --markdown
C:\xampp\php\php.exe scripts\verify_online_daily_data_health.php --strict
C:\xampp\php\php.exe scripts\revalidate_online_daily_data.php --limit=0
```

验收标准：

- 业务键重复为 `0`。
- `raw_data` JSON 无非法值。
- 无未来日期。
- 昨日 `historical_daily` 在 08:00 后有真实入库记录。
- 异常数据保留在 `validation_status` / `validation_flags`，不使用兜底值抹平。
