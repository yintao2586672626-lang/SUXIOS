# 每日经营目标：既有事实复用合同

> 用途：限定“经营目标”可以从哪里预填事实。它不是采集实现，也不把 OTA 渠道数据扩大为全酒店经营事实。

## 允许预填：同店同日经营日报

`daily_reports` 是当前唯一已有的全酒店日报事实入口。

| 目标字段 | 允许读取的日报字段 | 前提 | 预填后的质量 |
| --- | --- | --- | --- |
| 实际总营收 | `revenue`、`day_revenue` | 同一酒店、同一自然日；值必须存在 | `unverified`，须人工确认后才能计算正式结论 |
| 已售间夜 | `total_rooms`、`day_total_rooms` | 同一酒店、同一自然日；不得用 OTA 间夜补齐 | `unverified` |
| 可售房夜 | `salable_rooms` | 同一酒店、同一自然日；不得用静态总房数代替 | `unverified` |

证据：`app/controller/DailyReport.php:300-310` 只在完整收入/间夜证据具备时派生日报总值；`:409-424` 对缺营收、缺已售间夜、缺可售房显式产出缺口；`:3119-3134` 只在“总房数+维修房”或“过夜+钟点房”同时具备时派生字段。

## 必须校验的身份与日期

1. `hotel_id` 必须精确相等。
2. `report_date` 必须精确等于经营目标 `target_date`；不得用最近日报、历史日报或零值顶替。
3. 日报表具有 `tenant_id` 时，必须精确相等；`OperatingTargetService::prefillFromDailyReport()` 当前已在该列存在时加此条件（`app/service/OperatingTargetService.php:138-144`）。
4. 若运行库的 `daily_reports` **没有** `tenant_id`，不能声称租户已校验：预填必须保持 `unverified`，后续应补 `daily_report_tenant_scope_unverifiable` 门禁后才能把它自动作为可用事实。
5. `DailyReport` 状态为草稿（`STATUS_DRAFT=1`，见 `app/model/DailyReport.php:31-32`）时，只能作为人工核对候选，不能自动升级为已验证事实；正式复用应要求已提交状态与可追溯日报编号。

## 导入与 PMS 的边界

- 既有 XLSX 导入只负责解析、门店字段映射和返回候选值（`app/controller/DailyReport.php:2509-2585`）。它要求 `can_fill_daily_report` 权限（`:2521-2525`），但解析成功不是经营事实已验证。
- `source_type=import`：必须保存导入文件/日报编号等 `source_reference`，默认 `unverified`；人工核对后才能标为 `manual_confirmed`。
- `source_type=pms`：订单来了与美团云 PMS 已分别具备独立门店绑定、受保护采集、事实持久化、内部对账和数据库回读适配器。只有对应来源同店、同日、身份、字段、对账和回读全部通过时才能标为 `verified`；人工提交仍不能自证可信采集。
- 两个 PMS 当前都只提供 `accommodation_room_fee` 住宿客房事实。订单来了总房费与美团云预计房费不能扩成全酒店总收入或已结算财务收入；预填经营目标时，目标金额也必须保持同一住宿房费口径。
- `online_daily_data`、携程/美团订单、流量、广告、竞品数据都属于 OTA 渠道口径，禁止预填“全酒店实际营收 / 已售间夜 / 可售房夜”。`DailyReport` 已显式将其标为 `ota_channel_only_not_whole_hotel_scope`（`app/controller/DailyReport.php:619-626`）。

## 缺口与计算规则

- 缺任一字段保持 `null` 并输出缺口，不用 `0`、旧值或静态房量伪装。
- 已售间夜大于可售房夜为 `blocked`，不计算销售进度或所需均价。
- 只有 `verified` 或 `manual_confirmed` 的同口径全酒店事实才可产生正式完成率、剩余目标、销售进度和所需均价。
- 日报预填默认只产生 `unverified` 候选；这一点由 `app/service/OperatingTargetService.php:126-178` 固化，且目标测试覆盖同租户、同门店、同日期读取（`tests/OperatingTargetServiceTest.php:143-171`）。

## 当前明确缺口

1. 用户提供的经营目标源码包已完成只读复核；`PmsFactReconciliationService` 已把同源相邻快照的 `gap + delta` 核心接入运行时，包括时间归一、净拾取、房费/ADR/OCC/RevPAR变化、回落优先和重新建基线。完整目标节奏曲线、差距收窄/扩大、满房提醒状态机仍未上线，不得宣称与朋友项目完全等价。
2. 订单来了与美团云 PMS 的代码、持久化和回读门禁已具备；当前环境仍缺两个外部真实账号的同日连续采集凭证，因此真实数据效果保持 `unverified_runtime`，不能用测试样例代替。
3. 旧 `daily_reports` 无 `tenant_id` 的运行库需要增加租户可验证门禁，才允许自动把日报候选作为可用事实。
