# 美团数据获取方案（自动主线 + 手动临时）

## 定位

美团数据获取分一条日常主线和一条临时补充路径：

1. 自动获取（默认主线）：宿析OS使用每个门店独立 Profile 打开美团 eBooking/直连相关页面，在授权范围内监听业务 JSON，并按模块入库。
2. 手动获取（临时路径）：用户提供美团 TMC/直连平台导出、订单日志、监控数据，或已取得的 Cookie/Session、`partner_id`、`poi_id`、Payload、动态签名等上下文；宿析OS负责校验、抓取/导入、清洗和入库。

美团 iframe、SPA、动态签名和登录态变化较多，默认走真实浏览器 Profile 响应监听；手动 Cookie/API 只处理用户已提供的上下文，用于临时补数、首次接入或排障，不后台代登录。

## 当前优先业务

| 优先级 | 数据模块 | 主要用途 | 推荐路径 |
| --- | --- | --- | --- |
| P0 | 订单、订单日志、订单监控 | 经营日报、实时驾驶舱、失败归因 | 自动 Profile 主线；直连导出/上下文仅临时补数 |
| P0 | 房型、产品、价格、库存 | 房态价量日报、价格库存巡检 | 自动 Profile 主线；直连平台导出/API 上下文仅授权补充 |
| 暂缓 | 点评、评分、回复 | 平台管控较严，短期投入产出低 | 仅保留显式手动入口，不进入默认自动采集 |
| P1 | 流量、排名、转化 | 周月看板、漏斗诊断 | 自动 Profile 主线；商家报表仅临时补充 |
| P1 | 接口耗时、异常、SLA | 运营监控、告警 | 订单日志/监控页 |
| P2 | 广告投放 | 活动复盘、ROI | 有广告账号、成本口径和复盘需求时再接入 |

## 合规边界

- 只采集当前酒店账号可见的数据。
- 不绕过登录、短信、滑块、人机验证或平台权限体系。
- 不采集非授权门店、竞品后台或平台内部不可见数据。
- Cookie、Session、Profile、账号密码、手机号明文不得写入文档、日志或 Git。
- 客人信息进入 `raw_data` 前应脱敏或最小化保留。

## 手动获取

手动路径只适合直连平台补数、平台改版排障、订单日志核对、自动采集失败后的临时补录。

| 手动方式 | 用户提供 | 系统处理 | 不做什么 |
| --- | --- | --- | --- |
| 直连/TMC 导出导入 | 产品、库存、价格、订单日志、订单监控导出 | 解析、校验、字段映射、入库 | 不启动浏览器，不自动登录 |
| 请求上下文抓取 | Cookie/Session、`partner_id`、`poi_id`/`store_id`、Payload、日期范围、必要动态签名 | 调用现有 `/api/online-data/fetch-meituan*` 兼容接口 | 不猜签名，不补未知 Payload |
| JSON 离线导入 | 已脱敏的接口响应或排障样例 | 离线解析、对账、修复字段映射 | 不把样例当成实时经营数据 |

字段缺失时只返回缺失项和参考入口，不在手动流程里打开美团后台。

## 自动获取

自动路径用于日常采集、日报、巡检、实时监控和预警。

1. 使用 `storage/meituan_profile_{store_id}`，每个门店独立 Profile。
2. 页面触发入口优先走 `POST /api/online-data/capture-meituan-browser`，后端启动 `scripts/meituan_browser_capture.mjs`。
3. 复用已登录状态；失效时返回 `needs_login` 并打开登录页，等待人工完成短信、滑块或人机验证。
4. 按模块打开页面：点评、数据中心/流量、订单/入住、直连产品/价格库存。
5. 只监听 XHR/fetch、HTTP 200、可解析 JSON、命中 URL 规则的响应。
6. iframe DOM/HTML 只补页面已展示的摘要、排名或列表文本；截图仅排障，不作为常规数据源。
7. 清洗、脱敏、去重后写入 `online_daily_data`，关键原始结构进入 `raw_data`。

## 模块字段与入口

| 数据模块 | 手动临时字段 | 自动入口/关键词 | 核心字段 |
| --- | --- | --- | --- |
| 订单/入住 | Cookie/Session、`partner_id`、`poi_id`/`store_id`、订单筛选 Payload、日期 | 订单/入住管理页，`/orders/list`、`/order/unhandled/count` | 订单号、状态、房型、间数、入住离店、金额、均价 |
| 订单日志/监控 | 导出表或监控页上下文 | 直连订单日志/监控页 | 步骤、结果、时间、耗时、成功/失败/异常、TP95 |
| 产品/价格库存 | 直连产品导出、`poiId`、房型/产品上下文 | 直连产品/房态价页面 | 产品名、底价、卖价、佣金、库存、早餐、开关 |
| 点评（暂缓） | Cookie/Session、`poi_id`/`store_id`、点评 Payload、日期 | 点评页，`queryGeneralCommentInfo`、`commentsInfo` | 仅显式手动启用时采集 |
| 流量/排名 | Cookie/Session、`partner_id`、`poi_id`/`store_id`、iframe URL、日期 Payload | 数据中心 iframe/newhb SPA，`businessData`、`traffic`、`peerTrends` | 曝光、浏览、PV/UV、转化、排名 |
| 广告 | 广告账号、成本、活动数据、日期 | 广告页，仅明确需要时加入 | 曝光、点击、消耗、订单、ROI/ROAS |

## 入库映射

统一优先写入 `online_daily_data`，不默认新增 `reviews`、`orders`、`traffic_data` 表。

| 来源数据 | `data_type` | 核心映射 |
| --- | --- | --- |
| 订单 | `order` | `amount`、`quantity`、`book_order_num`、`data_value`、订单明细进 `raw_data` |
| 订单监控 | `monitoring` | 成功率、失败率、异常、TP95、步骤明细先进 `raw_data` |
| 产品/价格库存 | `price_inventory` | 产品、价格、库存、佣金、早餐、开关先进 `raw_data` |
| 点评 | `review` | `comment_score`、`data_value`、点评详情进 `raw_data` |
| 流量 | `traffic` | `list_exposure`、`detail_exposure`、`flow_rate`、排名和关键词进 `raw_data` |
| 广告 | `advertising` | 有成本和活动口径时再写，成本、计划、ROI/ROAS 进 `raw_data` |

`mt_revenue`、`mt_rooms` 等收入/间夜口径不默认视为美团商家后台直接字段；只有实际接口、页面或导出可见且可追溯时才映射。

## 去重与质量

- 订单按美团订单号去重；缺少订单号时只写汇总，不伪造明细订单。
- 产品优先按平台产品键去重；必要时用 `poiId + roomType + breakfastNum` 辅助去重。
- 点评按平台点评 ID 去重；缺少 ID 时按酒店、日期、内容摘要兜底。
- 流量、监控和价格库存按 `system_hotel_id + source + data_type + dimension + data_date` 更新。
- 金额、库存、耗时、状态、日期必须做合法性校验。
- 空数据、登录失效、签名缺失、接口未命中必须显式返回原因。
