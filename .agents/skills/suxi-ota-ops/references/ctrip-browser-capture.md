# 携程数据获取方案（手动 + 自动）

> 客户/项目团队可读详版方法见：`docs/ctrip_browser_capture_method.md`。

## 定位

携程数据获取分两条路线：

1. 手动获取：用户提供携程后台导出文件、Trip Connect 接口结果，或已取得的 Cookie、`spidertoken`、`node_id`、Payload 等上下文；宿析OS只负责校验、抓取/导入、清洗和入库。
2. 自动获取：宿析OS使用每个门店独立 Profile 打开携程商家后台，在授权范围内监听页面业务 JSON，并按模块入库。

手动不是自动登录；自动不是让用户每次复制 Cookie。两条路径服务同一个业务目标，但触发方式和依赖条件不同。

## 当前优先业务

| 优先级 | 数据模块 | 主要用途 | 推荐路径 |
| --- | --- | --- | --- |
| P0 | 经营概况、订单、间夜、收入 | OTA 经营日报、收益分析 | 手动导入/接口上下文 + 自动 Profile |
| P0 | 流量、转化、排名 | 周月看板、漏斗诊断 | 手动报表或 Payload + 自动 Profile |
| 暂缓 | 点评、评分、回复 | 平台管控较严，短期投入产出低 | 仅保留显式手动入口，不进入默认自动采集 |
| P1 | 房态、房价、ARI、产品 | 房态价量日报、价格巡检 | Trip Connect/API/导出优先，自动补充 |
| P1 | 竞对、市场热度 | 经营复盘、收益建议 | 仅采集后台可见或官方导出项 |
| P2 | 广告投放 | 活动复盘、ROI | 有广告成本和账号权限时再接入 |

## 合规边界

- 只采集当前酒店账号可见的数据。
- 不绕过登录、短信、滑块、人机验证或平台权限体系。
- 不采集非授权门店、竞品后台或平台内部不可见数据。
- Cookie、`spidertoken`、Profile、账号密码、手机号明文不得写入文档、日志或 Git。
- 客人信息进入 `raw_data` 前应脱敏或最小化保留。

## 手动获取

手动路径适合临时补数、首次接入、平台改版排障、自动采集失效时补录。

| 手动方式 | 用户提供 | 系统处理 | 不做什么 |
| --- | --- | --- | --- |
| 报表/文件导入 | 携程后台导出的订单、流量、点评、房态价量报表 | 解析、校验、字段映射、入库 | 不启动浏览器，不自动登录 |
| 请求上下文抓取 | Cookie、`spidertoken`、`node_id`/酒店 ID、Payload、日期范围、渠道 Tab | 调用现有 `/api/online-data/fetch-ctrip*` 兼容接口 | 不替用户补未知 token，不猜 Payload |
| Trip Connect/API | 已授权接口返回或导出 | 按内容、ARI、预订、点评模块解析 | 不把未授权 API 当成已有能力 |

字段缺失时只返回缺失项和获取入口，不在手动流程里自动打开后台。

## 自动获取

自动路径用于日常日报、巡检、看板和预警。

1. 使用 `storage/ctrip_profile_{store_id}`，每个门店独立 Profile。
2. 复用已登录状态；失效时返回 `needs_login` 并打开登录页，等待人工完成短信、滑块或人机验证。
3. 按模块打开页面：经营概况、流量、订单、房态房价；点评暂缓。
4. 只监听 XHR/fetch、HTTP 200、可解析 JSON、命中 URL 规则的响应。
5. DOM 只补页面已展示的排名、摘要或缺失指标；不抓菜单、按钮、导航文本作为业务数据。
6. 清洗、脱敏、去重后写入 `online_daily_data`，关键原始结构进入 `raw_data`。

## 模块字段与入口

| 数据模块 | 手动必须字段 | 自动入口/关键词 | 核心字段 |
| --- | --- | --- | --- |
| 经营概况 | Cookie 或导出报表、酒店 ID、日期 | 数据中心概况页、昨日概况接口 | 成交金额、订单数、间夜、均价、成交率、评分 |
| 流量 | Cookie、`node_id`/酒店 ID、Payload、日期，或流量报表 | 数据中心流量页，`queryScanFlowDetailsV2`、`queryFlowTransforNew` 等 | PV、UV、曝光、点击、转化、排名 |
| 订单 | Cookie、订单筛选 Payload、日期，或订单导出 | 订单页，`queryOrderList`、`getOrderList` 等 | 订单号、状态、房型、间数、入住离店、金额 |
| 点评（暂缓） | Cookie、`spidertoken`、Payload、日期、渠道 Tab | 点评页，`getCommentList` | 仅显式手动启用时采集 |
| 房态房价/ARI | Trip Connect/API、导出表，或后台可见页面 | 房态房价页或官方接口 | 日历价、库存、关房、限制、取消规则 |
| 广告 | 广告报表、成本、日期 | 广告页，仅明确需要时加入 | 曝光、点击、消耗、订单、ROI/ROAS |

## 入库映射

统一优先写入 `online_daily_data`，不默认新增 `reviews`、`orders`、`traffic_data` 表。

| 来源数据 | `data_type` | 核心映射 |
| --- | --- | --- |
| 经营概况 | `overview` 或空值兼容旧数据 | `amount`、`quantity`、`book_order_num`、`comment_score`、`raw_data` |
| 流量 | `traffic` | `list_exposure`、`detail_exposure`、`flow_rate`、`order_filling_num`、`order_submit_num`、排名进 `raw_data` |
| 订单 | `order` | `amount`、`quantity`、`book_order_num`、`data_value`、订单明细进 `raw_data` |
| 点评 | `review` | `comment_score`、`data_value`、点评详情进 `raw_data` |
| 房态房价 | `price_inventory` | 日历价、库存、限制条件先进入 `raw_data` |
| 广告 | `advertising` | 曝光、点击优先复用流量字段，成本和活动进 `raw_data` |

## 去重与质量

- 订单按平台订单号去重；缺少订单号时只写汇总，不伪造明细订单。
- 点评按评价 ID 去重；缺少评价 ID 时按酒店、日期、内容摘要兜底。
- 汇总按 `system_hotel_id + source + data_type + dimension + data_date` 更新。
- 金额、间夜、评分、日期必须做合法性校验。
- 空数据、登录失效、字段缺失必须显式返回原因。
