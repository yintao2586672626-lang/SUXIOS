# OTA 浏览器辅助采集方式吸收契约

状态：已吸收为宿析OS采集方法论与字段契约；已新增浏览器辅助采集标准化导入补充，未接入生产自动采集。

## 目标

把外部脚本中的可用模式吸收到宿析OS，但不照搬脚本猫/Tampermonkey运行方式。

宿析OS采用的目标链路仍然是：

```text
已授权OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策
```

## 吸收边界

| 项目 | 外部脚本方式 | 宿析OS吸收方式 |
| --- | --- | --- |
| 采集入口 | PMS页面悬浮面板打开OTA后台页 | 宿析OS数据源/采集任务触发 |
| 登录状态 | 依赖用户浏览器已登录 | 复用门店隔离浏览器Profile，失效时提示人工登录 |
| 数据来源 | DOM文本、表格、图表文本 | 优先业务JSON响应，DOM只作为可见证据补充 |
| 暂存位置 | GM_setValue浏览器本地缓存 | `online_daily_data.raw_data`、采集日志、字段事实 |
| 业务范围 | 单机辅助看数 | 多门店、可审计、可回放、可供AI分析 |
| 失败处理 | 页面错误或无数据提示 | 明确 `missing_state`，不写假成功、不补假值 |

## 可吸收模块

| 平台 | 模块 | 采集内容 | 宿析OS用途 | 优先级 |
| --- | --- | --- | --- | --- |
| 美团 | 房态库存 | 房型、日期、开关房、剩余、预留、已售 | 库存预警、关房巡检、价格库存诊断 | P1 |
| 美团 | 实时流量 | 曝光人数、浏览人数、支付订单、曝光-浏览转化率、浏览-支付转化率 | 流量漏斗、异常诊断、今日动作建议 | P0 |
| 携程 | 房态库存 | 房型、日期、开关房/满房、剩余库存、已售 | 携程库存巡检、售卖状态诊断 | P1 |
| 携程/去哪儿 | 实时数据 | 实时访客量、竞争圈平均、下单转化率、实时排名 | 竞对比较、渠道转化诊断、日报异常说明 | P0 |

## 证据契约

每个被吸收字段必须具备以下证据，缺一项不得标记为完整采集。

| 字段 | 要求 |
| --- | --- |
| `platform` | `meituan`、`ctrip`、`qunar` |
| `module` | `inventory`、`traffic_realtime`、`order_realtime`、`rank_realtime` |
| `collection_mode` | `browser_profile_response` 优先，`browser_assist_dom` 仅作补充 |
| `source_url_alias` | 不保存敏感完整URL，使用平台页面别名 |
| `source_path` | JSON path、DOM selector alias 或 `raw_data.facts.metric_key=...` |
| `metric_key` | 宿析OS标准指标键 |
| `raw_label` | OTA页面原始展示标签 |
| `value_type` | `number`、`percent`、`integer`、`text`、`boolean` |
| `unit` | 人、单、百分比、间、状态、名次等 |
| `data_date` | 业务日期，实时数据需额外记录采集时间 |
| `storage_target` | 默认 `online_daily_data.raw_data` 或已有结构字段 |
| `missing_state` | 登录失效、页面未打开、接口未命中、选择器未命中、值为空、口径未知 |

## 字段映射初版

| metric_key | 平台 | 模块 | OTA原始标签 | 类型 | 单位 | 建议存储 | 缺失状态 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `room_inventory_remaining` | meituan/ctrip | inventory | 剩余/剩 | integer | 间 | `raw_data.inventory[].days[].remain` | `inventory_remain_missing` |
| `room_inventory_reserved` | meituan | inventory | 预留 | integer | 间 | `raw_data.inventory[].days[].reserved` | `inventory_reserved_missing` |
| `room_inventory_sold` | meituan/ctrip | inventory | 已售/售出 | integer | 间 | `raw_data.inventory[].days[].sold` | `inventory_sold_missing` |
| `room_sale_status` | meituan/ctrip | inventory | 开房/关房/满房/停售 | text | 状态 | `raw_data.inventory[].days[].state` | `inventory_status_missing` |
| `meituan_exposure_users` | meituan | traffic_realtime | 曝光人数 | integer | 人 | `raw_data.metrics.exposure_users` | `traffic_exposure_missing` |
| `meituan_browse_users` | meituan | traffic_realtime | 浏览人数 | integer | 人 | `raw_data.metrics.browse_users` | `traffic_browse_missing` |
| `meituan_paid_orders` | meituan | order_realtime | 支付订单数 | integer | 单 | `raw_data.metrics.paid_orders` | `traffic_paid_orders_missing` |
| `meituan_exposure_browse_rate` | meituan | traffic_realtime | 曝光-浏览转化率 | percent | % | `raw_data.metrics.exposure_browse_rate` | `traffic_rate_missing` |
| `meituan_browse_pay_rate` | meituan | traffic_realtime | 浏览-支付转化率 | percent | % | `raw_data.metrics.browse_pay_rate` | `traffic_rate_missing` |
| `ctrip_realtime_visitors` | ctrip/qunar | traffic_realtime | 实时访客量 | integer | 人 | `raw_data.metrics.realtime_visitors` | `traffic_visitors_missing` |
| `ctrip_visitor_peer_avg` | ctrip/qunar | traffic_realtime | 竞争圈平均 | number | 人 | `raw_data.metrics.visitor_peer_avg` | `traffic_peer_avg_missing` |
| `ctrip_order_conversion_rate` | ctrip/qunar | traffic_realtime | 实时下单转化率 | percent | % | `raw_data.metrics.order_conversion_rate` | `traffic_conversion_missing` |
| `ctrip_realtime_rank` | ctrip | rank_realtime | 实时排名 | integer | 名次 | `raw_data.rank_metrics.realtime_rank` | `rank_missing` |

## 接入阶段

1. 字段契约沉淀：保留本文件和 `docs/ota_browser_assist_collection_contract.json`，作为后续实现依据。
2. 标准化导入补充：`scripts/normalize_ota_browser_assist_capture.mjs` 可把浏览器辅助采集JSON转成 `/api/online-data/data-import` 可接收的分包。
3. 采集脚本吸收：在现有 `ctrip_browser_capture.mjs`、`meituan_browser_capture.mjs` 内补充模块，不新增第三方脚本猫依赖。
4. 证据闭环：每个字段必须形成 `source_path -> metric_key -> storage_target -> UI状态 -> verifier`。
5. UI呈现：进入数据健康/OTA日报，不独立做悬浮面板；状态必须显示已采集、缺字段、登录失效、页面未命中。
6. AI使用：AI只读取已验证或明确标记质量状态的数据，不把OTA渠道数据升级成全酒店经营事实。

## 当前系统入口

### 后端导入接口

```text
POST /api/online-data/browser-assist-import
```

请求体：

```json
{
  "system_hotel_id": 58,
  "capture": {
    "ctrip": {},
    "ctripStats": {},
    "meituan": {},
    "meituanStats": {}
  }
}
```

也支持美团同行/流量 Hook 原始键名输入，例如：

```json
{
  "system_hotel_id": 58,
  "capture": {
    "P_RZ_0": {},
    "FLOW_CONV_0": {},
    "FLOW_SRC_0": {},
    "FORECAST_2": {},
    "KEYWORDS": {}
  }
}
```

也支持上传 JSON 文件字段：`file`、`capture_file` 或 `import_file`。

后端会自动按 `platform + data_type` 分包，并逐包复用现有 `PlatformDataSyncService::importRows()` 入库。

### CLI 调试入口

```powershell
node scripts/normalize_ota_browser_assist_capture.mjs `
  --input=runtime/ota-browser-assist/capture.json `
  --package-dir=runtime/ota-browser-assist/import-packages `
  --system-hotel-id=58
```

输出目录中的每个 JSON 文件都是一个独立导入包，也可以提交到：

```text
POST /api/online-data/data-import
```

导入包按 `platform + data_type` 拆分，例如 `ctrip/inventory`、`ctrip/traffic`、`ctrip/peer_rank`、`meituan/traffic`、`meituan/peer_rank`、`meituan/traffic_analysis`、`meituan/search_keyword`、`meituan/traffic_forecast`。这样可以适配现有导入服务的规则，避免手工导入时 `source.data_type` 覆盖行级 `data_type` 造成误归类。

## 禁止项

- 不绕过OTA登录、验证码、短信、人机验证或账号权限。
- 不提交Cookie、token、Profile、截图、原始页面或含敏感信息的响应。
- 不用DOM文案替代可获得的业务JSON。
- 不把实时流量、房态、排名直接解释成全酒店入住率、ADR或RevPAR。
- 不在字段缺失、选择器失效或登录失败时写入空值假成功。

## 验收标准

| 检查项 | 通过标准 |
| --- | --- |
| 字段清单 | P0实时流量/排名字段有标准 `metric_key` 和缺失状态 |
| 数据来源 | JSON响应优先，DOM补充必须写明 `collection_mode=browser_assist_dom` |
| 入库兼容 | 默认写入 `online_daily_data.raw_data`，不新增表 |
| 状态透明 | 登录失效、接口未命中、字段缺失、空数据均显式返回 |
| 下游可用 | 收益分析、AI诊断、运营动作只消费有质量状态的数据 |
