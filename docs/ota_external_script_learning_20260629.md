# OTA 外部采集脚本学习笔记

状态：只读学习稿；未接入生产自动采集；不得替代现有 Profile / response 主链路。

来源文件：

| 文件 | 类型 | 主要内容 |
| --- | --- | --- |
| `D:/AIOS/ai插件/采集美团和携程房态和实时数据的.txt` | ScriptCat 用户脚本 | 在 PMS / OTA 后台之间打开页面，采集美团、携程房态与实时数据 |
| `D:/AIOS/ai插件/美团订单采集.txt` | Tampermonkey 用户脚本 | 美团订单 DOM 解析、自动翻页、CSV 导出 |
| `D:/AIOS/ai插件/美团同行数据20260610021(1)(1).txt` | Tampermonkey 用户脚本 | Hook 美团同行排名、流量、预测、关键词接口并生成 HTML 报告 |

## 总原则

这些脚本只能作为授权 OTA 后台的补充采集知识，目标仍然服务：

```text
OTA 渠道数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策
```

吸收时必须保留边界：

| 规则 | 要求 |
| --- | --- |
| 渠道边界 | 美团、携程、去哪儿数据只代表 OTA 渠道，不等同于全酒店经营事实 |
| 主链路优先 | response JSON / 浏览器 Profile 主链路优先，DOM 只做补充证据 |
| 失败显式化 | 登录失效、页面未打开、接口未命中、选择器失效、字段为空、口径未知必须显式标记 |
| 不写假值 | 不用空值、0、页面文案或兜底逻辑掩盖采集失败 |
| 敏感数据 | 不保存 Cookie、token、手机号、账号、Profile、完整原始页面或未脱敏响应 |
| 入库边界 | 默认复用 `online_daily_data.raw_data` 和现有导入包，不默认新增表 |

## 1. 房态与实时数据脚本

### 采集目标

| key | 平台/模块 | 页面别名 | 原始 URL 线索 | 结果类型 |
| --- | --- | --- | --- | --- |
| `ctrip` | 携程房态 | `ctrip_inventory_calendar` | `ebooking.ctrip.com/ebkovsroom/inventory/calendar` | `inventory` |
| `ctripStats` | 携程/去哪儿实时数据 | `ctrip_flowdata_realtime` | `ebooking.ctrip.com/datacenter/inland/businessreport/flowdata` | `traffic_realtime` / `peer_rank` |
| `meituan` | 美团房态 | `meituan_product_inventory` | `me.meituan.com/ebooking/merchant/product#/index` | `inventory` |
| `meituanStats` | 美团实时数据 | `meituan_data_center_realtime` | `eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html` | `traffic_realtime` |

脚本参数：

| 参数 | 值 |
| --- | --- |
| 展示/采集天数 | `SHOW_DAYS = 5` |
| 默认采集间隔 | 5 分钟 |
| 允许采集间隔 | 3 / 5 / 10 分钟 |
| 单页超时 | 120 秒 |
| 暂存方式 | `GM_setValue` / `GM_getValue` |

### 字段合同

| 字段 | 平台 | 来源 | 类型 | 单位 | 建议存储 | 缺失状态 |
| --- | --- | --- | --- | --- | --- | --- |
| `hotel_name` | meituan | 门店选择器或页面文案 | text | - | `raw_data.hotel_name` | `hotel_name_missing` |
| `room_name` | meituan/ctrip | 房型行 | text | - | `raw_data.inventory[].room_name` | `room_name_missing` |
| `data_date` | meituan/ctrip | 表头日期列 | date/text | 日 | `raw_data.inventory[].days[].date` | `date_column_missing` |
| `room_sale_status` | meituan/ctrip | 开房/关房/满房/停售状态 | text/boolean | 状态 | `raw_data.inventory[].days[].state` | `inventory_status_missing` |
| `room_inventory_remaining` | meituan/ctrip | 剩余/库存 | integer/null | 间 | `raw_data.inventory[].days[].remain` | `inventory_remain_missing` |
| `room_inventory_reserved` | meituan | 房量三段值第 2 项 | integer/null | 间 | `raw_data.inventory[].days[].reserved` | `inventory_reserved_missing` |
| `room_inventory_sold` | meituan/ctrip | 已售/售出 | integer/null | 间 | `raw_data.inventory[].days[].sold` | `inventory_sold_missing` |
| `meituan_exposure_users` | meituan | 曝光人数 | integer/text | 人 | `raw_data.metrics.exposure_users` | `traffic_exposure_missing` |
| `meituan_browse_users` | meituan | 浏览人数 | integer/text | 人 | `raw_data.metrics.browse_users` | `traffic_browse_missing` |
| `meituan_paid_orders` | meituan | 支付订单数 | integer/text | 单 | `raw_data.metrics.paid_orders` | `traffic_paid_orders_missing` |
| `meituan_exposure_browse_rate` | meituan | 曝光-浏览转化率 | percent/text | % | `raw_data.metrics.exposure_browse_rate` | `traffic_rate_missing` |
| `meituan_browse_pay_rate` | meituan | 浏览-支付转化率 | percent/text | % | `raw_data.metrics.browse_pay_rate` | `traffic_rate_missing` |
| `ctrip_realtime_visitors` | ctrip/qunar | 实时访客量 | integer/text | 人 | `raw_data.metrics.realtime_visitors` | `traffic_visitors_missing` |
| `ctrip_visitor_peer_avg` | ctrip/qunar | 竞争圈平均 | number/text | 人 | `raw_data.metrics.visitor_peer_avg` | `traffic_peer_avg_missing` |
| `ctrip_order_conversion_rate` | ctrip/qunar | 实时下单转化率 | percent/text | % | `raw_data.metrics.order_conversion_rate` | `traffic_conversion_missing` |
| `ctrip_realtime_rank` | ctrip | 实时排名 | integer/text | 名 | `raw_data.rank_metrics.realtime_rank` | `rank_missing` |

可吸收方式：

| 项目 | 判断 |
| --- | --- |
| 房态 | 可作为库存/关房巡检补充，不直接生成收益指标 |
| 实时流量 | P0 价值较高，应优先并入现有浏览器 response / DOM 证据链 |
| 悬浮面板 | 不吸收；系统应使用现有 OTA 数据健康与采集页展示 |
| `GM_setValue` 暂存 | 不吸收；系统使用采集日志、导入包和 `raw_data` |

## 2. 美团订单采集脚本

### 输出字段

| 字段 | 原始含义 | 类型 | 建议处理 | 注意 |
| --- | --- | --- | --- | --- |
| `orderNo` | 订单号 | text | 作为去重键之一，脱敏展示 | 不在日志打印完整订单号 |
| `roomType` | 房型 | text | 写入 `raw_data.orders[].room_type` | 脚本取 `+` 前文本，套餐信息会被截断 |
| `checkIn` | 入住日期 | date/text | 写入 `raw_data.orders[].check_in` | `MM-DD` 会补当前年，跨年订单需复核 |
| `checkOut` | 离店日期 | date/text | 写入 `raw_data.orders[].check_out` | 同上 |
| `buyTime` | 购买时间 | datetime/text | 写入 `raw_data.orders[].buy_time` | 脚本按购买时间截止近一年 |
| `price` | 底价 | money/text | 暂存 `raw_data.orders[].base_price` | 不能直接等同成交收入 |

采集特征：

| 项目 | 内容 |
| --- | --- |
| 页面 | `eb.meituan.com/ebooking/order-eb/*`、`me.meituan.com/ebooking/merchant/ebIframe*` |
| 默认范围 | 近一年购买时间 |
| 翻页 | 自动点击下一页，直到早于起始日期、最后一页或找不到按钮 |
| 导出 | UTF-8 BOM CSV，文件名含日期和条数 |
| 主要选择器 | `.order-date-wrapper`、`.order-room-info-wrapper`、分页按钮 |

可吸收方式：

| 用途 | 结论 |
| --- | --- |
| 历史订单补录 | 可以做 `manual_import` / `browser_assist_dom` 补充 |
| 收益分析 | 仅在订单状态、取消/退款、间夜数、金额口径验证后参与 |
| 自动采集 | 不直接吸收脚本；应优先监听订单接口 response |
| 入库 | 建议走现有美团订单导入包，按 `platform=meituan + data_type=order` 分包 |

缺失状态建议：

| 场景 | 状态 |
| --- | --- |
| 页面无订单节点 | `order_dom_missing` |
| 订单号缺失 | `order_no_missing` |
| 购买时间缺失 | `buy_time_missing` |
| 房型解析失败 | `room_type_parse_failed` |
| 翻页按钮缺失 | `pagination_missing` |
| 价格只有底价 | `amount_scope_unverified` |

## 3. 美团同行与流量 Hook 脚本

### Hook 接口

| 模块 | URL 线索 | 捕获 key | 原始响应 |
| --- | --- | --- | --- |
| 同行排名 | `/business/peer/rank/data/detail` | `{rankType}_{dateRange}` | `json.data.peerRankData` |
| 流量转化漏斗 | `/flowConversion` | `FLOW_CONV_{dateRange}` | `json.data` |
| 流量趋势 | `/flowTrend` | `FLOW_TREND_{dateRange}` | `json.data` |
| 流量来源 | `/flowTrendDetail` | `FLOW_SRC_{dateRange}` | `json.data` |
| 未来 30 天流量 | `/flowForecast?type=...` | `FORECAST_{type}` | `json.data.detail` |
| 搜索关键词 | `/searchKeyWords` | `KEYWORDS` | `json.data.cards` |

时段与榜单：

| 类型 | 代码 | 名称 |
| --- | --- | --- |
| 时段 | `0` / `1` / `7` / `30` | 今日实时 / 昨日 / 近7天 / 近30天 |
| 榜单 | `P_RZ` | 入住榜 |
| 榜单 | `P_XS` | 销售榜 |
| 榜单 | `P_LL` | 流量榜 |
| 榜单 | `P_ZH` | 转化榜 |

### 字段合同

| 字段 | 来源 | 类型 | 单位 | 建议存储 | 缺失状态 |
| --- | --- | --- | --- | --- | --- |
| `rank_type` | URL `rankType` | text | - | `raw_data.peer_rank[].rank_type` | `rank_type_missing` |
| `date_range` | URL `dateRange` | enum | 时段 | `raw_data.peer_rank[].date_range` | `date_range_missing` |
| `dimension_name` | `peerRankData[].dimName` | text | - | `raw_data.peer_rank[].dimension_name` | `dimension_missing` |
| `peer_hotel_name` | `roundRanks[].poiName` | text | - | `raw_data.peer_rank[].rows[].hotel_name` | `peer_hotel_missing` |
| `peer_rank` | `roundRanks[].rank` | integer/text | 名 | `raw_data.peer_rank[].rows[].rank` | `peer_rank_missing` |
| `peer_percent` | `roundRanks[].percent` | percent/text | % | `raw_data.peer_rank[].rows[].percent` | `peer_percent_missing` |
| `peer_data_value` | `roundRanks[].dataValue` | number/text | 随维度 | `raw_data.peer_rank[].rows[].data_value` | `peer_value_missing` |
| `own_checkin_room_nights` | 首页 DOM | number | 间夜 | `raw_data.own_metrics.checkin_room_nights` | `own_metric_missing` |
| `own_sales_room_nights` | 首页 DOM | number | 间夜 | `raw_data.own_metrics.sales_room_nights` | `own_metric_missing` |
| `own_avg_price` | 首页 DOM | money | 元 | `raw_data.own_metrics.avg_price` | `own_metric_missing` |
| `own_sales_amount` | 首页 DOM | money | 元 | `raw_data.own_metrics.sales_amount` | `own_metric_missing` |
| `flow_expose_count` | `FLOW_CONV.data.exposeCount` | integer | 人 | `raw_data.flow.conversion.expose_count` | `flow_expose_missing` |
| `flow_visit_count` | `FLOW_CONV.data.visitCount` | integer | 人 | `raw_data.flow.conversion.visit_count` | `flow_visit_missing` |
| `flow_order_count` | `FLOW_CONV.data.orderCount` | integer | 单 | `raw_data.flow.conversion.order_count` | `flow_order_missing` |
| `flow_expose_visit_rate` | `FLOW_CONV.data.exposeVisitRate` | percent | % | `raw_data.flow.conversion.expose_visit_rate` | `flow_rate_missing` |
| `flow_visit_order_rate` | `FLOW_CONV.data.visitOrderRate` | percent | % | `raw_data.flow.conversion.visit_order_rate` | `flow_rate_missing` |
| `flow_source_name` | `FLOW_SRC.data.list[].name` | text | - | `raw_data.flow.sources[].name` | `flow_source_missing` |
| `flow_source_value` | `FLOW_SRC.data.list[].value` | number | 次/人 | `raw_data.flow.sources[].value` | `flow_source_value_missing` |
| `flow_source_percent` | `FLOW_SRC.data.list[].percent` | percent | % | `raw_data.flow.sources[].percent` | `flow_source_percent_missing` |
| `flow_trend_rank` | `FLOW_TREND.data.list[].rank` | text | 名次 | `raw_data.flow.trend[].rank` | `flow_trend_rank_missing` |
| `forecast_current` | `FORECAST.data.detail[].current` | number | 随类型 | `raw_data.forecast[].current` | `forecast_missing` |
| `forecast_peer_avg` | `FORECAST.data.detail[].peerAvg` | number | 随类型 | `raw_data.forecast[].peer_avg` | `forecast_peer_missing` |
| `search_keywords` | `KEYWORDS.data.cards` | json | - | `raw_data.search_keywords` | `keywords_missing` |

### 派生估算边界

脚本 HTML 中包含按占比反推总数：

```text
总数 = 已知酒店数据 / 已知酒店占比
每家数据 = 总数 * 每家占比
```

该结果只能作为 `derived_peer_estimate`，不能直接作为 OTA 原始事实、收益事实或投资判断依据。进入 AI 分析前必须携带：

| 字段 | 值 |
| --- | --- |
| `evidence_type` | `derived_estimate` |
| `formula` | `known_value / percent` |
| `source_metric` | 已知本店指标 |
| `source_percent` | 同行榜占比 |
| `quality_status` | `estimate_only` |

## 推荐吸收顺序

| 优先级 | 内容 | 原因 | 验证 |
| --- | --- | --- | --- |
| P0 | 美团同行/流量 Hook 的 response 字段合同 | 结构化响应更稳定，能补充流量、榜单、竞对信号 | fixture + JSON path + `source_path -> metric_key -> raw_data` |
| P1 | 美团订单 CSV / DOM 解析为手工导入补充 | 有历史订单价值，但金额和状态口径需复核 | CSV fixture + 去重 + `amount_scope_unverified` |
| P1 | 美团/携程房态库存补充 | 可支持关房、库存、售卖状态巡检 | DOM fixture + 缺失状态 + 不写收益字段 |
| P2 | 美团未来30天预测、关键词 | 适合运营信号和趋势判断，不是收入事实 | 标记 `signal_only` / `forecast` |
| 禁止 | 照搬用户脚本 UI、GM 存储、Cookie 传参 | 与项目采集、审计和多门店隔离机制不一致 | 不接入 |

## 接入建议

后续如执行实现，建议沿用现有入口：

| 方向 | 项目内路径 |
| --- | --- |
| 标准化导入 | `scripts/normalize_ota_browser_assist_capture.mjs` |
| 浏览器辅助导入接口 | `POST /api/online-data/browser-assist-import` |
| 美团浏览器采集主链路 | `scripts/meituan_browser_capture.mjs` |
| 携程浏览器采集主链路 | `scripts/ctrip_browser_capture.mjs` |
| 默认存储 | `online_daily_data.raw_data` |

最小落地闭环：

1. 先建本地脱敏 fixture，不提交原始平台页面、Cookie 或完整敏感响应。
2. 按 `platform + data_type` 拆包：`meituan/traffic`、`meituan/order`、`meituan/peer_rank`、`meituan/inventory`、`ctrip/inventory`、`ctrip/traffic`。
3. 每个字段记录 `source_path`、`metric_key`、`collection_mode`、`missing_state`。
4. UI 只展示“已采集 / 缺字段 / 登录失效 / 接口未命中 / 仅估算”，不展示假成功。
5. AI 只消费质量状态明确的数据；`signal_only` 和 `estimate_only` 不进入全酒店收益结论。

## 当前学习结论

| 结论 | 说明 |
| --- | --- |
| 可学习 | 字段清单、URL 页面别名、Hook 接口、DOM 选择器线索、缺失状态设计 |
| 可吸收 | response 优先的美团同行/流量合同，订单/房态的手工补充导入路径 |
| 不可直接吸收 | 用户脚本运行方式、悬浮面板、GM 本地缓存、Cookie 控制传参、未脱敏导出 |
| 最大风险 | 把外部脚本采集值误当生产闭环、把 OTA 渠道数据误当全酒店事实、把派生估算误当原始数据 |
