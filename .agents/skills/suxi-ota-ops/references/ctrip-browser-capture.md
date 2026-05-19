# 携程浏览器采集方法

## 定位

携程数据采集以浏览器 Profile + response 监听为主。手动采集路径只负责让运营人员登录后台、进入指定页面并取得 Cookie、Token、酒店 ID、Payload 等必要抓取上下文，再由宿析OS系统调用对应接口完成数据抓取、清洗和入库。

目标是在酒店已有合法账号和授权范围内，模拟运营人员打开携程商家后台，优先监听页面自动返回的经营 JSON；手动路径则复用同一套字段上下文，由系统端抓取并按宿析OS现有字段入库。

## 合规边界

- 只采集当前酒店账号下可见的数据。
- 不绕过登录、短信、滑块、人机验证或平台权限体系。
- 不采集非授权门店、竞品后台或平台内部不可见数据。
- 不把 Cookie、spidertoken、Profile、账号密码、手机号明文写入文档、日志或 Git。
- 客人手机号等敏感字段进入 `raw_data` 前应脱敏或最小化保留。

## 端点记录规则

“端口”只记录真实观察到的访问端点，不写死为固定值。

| 项 | 记录规则 |
| --- | --- |
| 页面 URL | 记录完整入口 URL，例如点评页、订单页、数据中心页 |
| 请求 URL 关键词 | 记录可稳定匹配的接口片段，例如 `getCommentList` |
| 协议与域名 | 从浏览器请求 URL 解析，如 `https` + `ebooking.ctrip.com` |
| 端口来源 | URL 有显式端口时记录显式端口；无显式端口时只备注“按协议默认推断”，不作为代码常量 |
| 端口分类 | 外部平台端点、本地系统端口、浏览器调试端口分开记录 |
| 是否显式端口 | 用布尔值或说明字段记录，避免把默认推断值误当作平台固定规则 |

## 登录态与 Profile

| 项 | 规则 |
| --- | --- |
| Profile 目录 | `storage/ctrip_profile_{store_id}` |
| 隔离粒度 | 每个门店一个独立浏览器 Profile |
| 登录优先级 | 复用 Profile Cookie -> 打开后台检查登录 -> 自动登录 -> 人工完成短信/滑块/人机验证 |
| 保存策略 | 登录成功后继续复用同一 Profile，不提交到 Git |

多门店采集必须带 `system_hotel_id` 或平台 `hotel_id`，入库前确认当前账号和目标门店匹配。

## 手动采集路线

手动采集不是手工复制经营数据，而是人工取得系统抓取所需上下文字段。

1. 人工登录携程商家后台。
2. 进入目标数据页面，如点评、流量、订单、金字塔广告或昨日概况。
3. 从浏览器、书签脚本或抓包中取得必要字段：`Cookie`、`spidertoken`、`node_id`、平台 `hotel_id`、原始 Payload、日期范围、渠道 Tab。
4. 在宿析OS页面或接口中提交这些字段，由系统调用 `/api/online-data/fetch-ctrip`、`/fetch-ctrip-traffic`、`/fetch-ctrip-comments` 等接口抓取。
5. 系统完成 JSON 解析、空值兜底、去重、脱敏和入库；人工页面文本仅用于核对或接口缺失时的兜底线索。

## 自动采集路线

1. Playwright 启动持久化 Profile：`storage/ctrip_profile_{store_id}`。
2. 复用已登录状态；失效时打开携程登录页，等待人工完成短信、滑块或人机验证。
3. 注册 `response` 监听，只处理 XHR/fetch、HTTP 200、JSON 响应。
4. 按页面入口触发接口，使用 URL 关键词归入点评、流量、订单、广告、昨日概况等数据槽位。
5. 接口未命中时只从 DOM 补排名、摘要或页面已展示指标；OCR 仅作为最后排障兜底。
6. 清洗后写入 `online_daily_data`，保留脱敏原始结构到 `raw_data`。

## 页面与接口监听

按业务页面触发接口，不假设一个页面包含全部数据。

| 数据模块 | 页面入口 | 请求 URL / 关键字 | 手动必须字段 | 可取字段与兜底 |
| --- | --- | --- | --- | --- |
| 登录态 | `https://ebooking.ctrip.com/login?...` | 登录页表单、Cookie | `Cookie`、登录状态、账号可见酒店 | 登录态检查；失效后重新登录 |
| 点评明细 | `https://ebooking.ctrip.com/comment/commentList?microJump=true` | `getCommentList` | `Cookie`、`spidertoken`、Payload、日期/分页条件、渠道 Tab | 评价 ID、评分、内容、回复、是否回复、是否差评、评价人、房型、入住日期、评价时间、标签；DOM 兜底 |
| 流量数据 | `https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true` | `queryScanFlowDetailsV2`、`queryFlowTransforNew`、`queryHomePageRealTimeData`、`getFlowData`、`getTrafficData`、`getStatData` | `Cookie`、`node_id` / `hotel_id`、Payload、日期范围 | PV、UV、订单/点击数、转化率、竞争圈排名、品类排名、原始排名 JSON；DOM 兜底排名 |
| 订单数据 | `https://ebooking.ctrip.com/ebkorderv3/domestic` | `unprocessOrderList`、`queryOrderList`、`getOrderList`、`getDomesticOrder` | `Cookie`、订单筛选 Payload、日期范围 | 订单号、状态、房型、间数、入住/离店日期、晚数、金额、均价、客人姓名、电话、下单时间；订单表格 DOM 兜底 |
| 金字塔广告 | `https://ebooking.ctrip.com/toolcenter/cpc/pyramid` | `pyramidad`、`promotion` | `Cookie`、广告页 Payload、日期范围 | 曝光、点击、预订/成交数、消耗/费用、原始广告数据；DOM 兜底 |
| 昨日概况 | `https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true` | `getDayReportRealTimeDate`、`fetchMarketOverViewV2`、`getDayReportFlowCompete`、`getDayReportServerQuantity`、`fetchVisitorTitleV2`、`fetchCapacityOverViewV4` | `Cookie`、`node_id` / `hotel_id`、昨日日期、页面 Payload | 昨日 UV、订单数、成交收入、成交间夜、均价、成交率、竞品 UV/订单/收入、PSI、回复率、收藏数、访客排名 |
| 分渠道评分 | 点评页 + 渠道 Tab | `getCommentList` | `Cookie`、`spidertoken`、渠道 Tab、日期/分页 Payload | 携程/同程/去哪儿/智行等渠道评分、好评数、差评数 |

响应处理只接收：

- 资源类型为 XHR 或 fetch。
- HTTP 状态码为 200。
- 返回体可解析为 JSON。
- URL 命中明确业务规则。

未命中的响应如果包含 `pv`、`uv`、`order`、`score`、`amount` 等指标，应记录接口摘要和样例结构，后续再补规则；不要直接宽泛入库。

## 入库映射

项目实际使用 `online_daily_data`，不新增 `reviews`、`orders`、`traffic_data` 表。

通用字段：

| 字段 | 规则 |
| --- | --- |
| `source` | 固定 `ctrip` |
| `platform` | 优先 `Ctrip` |
| `system_hotel_id` | 宿析OS酒店 ID，能拿到时必须写入 |
| `hotel_id` | 携程平台酒店 ID |
| `hotel_name` | 平台或系统酒店名 |
| `data_date` | 指标日期；日报优先昨日完整数据 |
| `dimension` | 指标分组，如 `点评`、`流量`、`订单`、`广告` |
| `raw_data` | 原始 JSON 或脱敏后的关键原始结构 |

业务映射：

| 来源数据 | `data_type` | 核心映射 |
| --- | --- | --- |
| 昨日经营概况 | `overview` 或空值兼容旧数据 | `amount=成交金额`、`quantity=间夜数`、`book_order_num=订单数`、`comment_score=携程评分`、排名和 PSI 进 `raw_data` |
| 点评 | `review` | `comment_score=评分`、`data_value=评分`、点评 ID/内容/回复/房型/入住日期/评价时间进 `raw_data` |
| 流量 | `traffic` | `list_exposure=曝光/PV`、`detail_exposure=浏览/UV`、`flow_rate=转化率`、`order_filling_num=填单人数`、`order_submit_num=提交人数`、排名进 `raw_data` |
| 订单 | `order` | `amount=订单金额`、`quantity=间夜数`、`book_order_num=订单数`、`data_value=平均房价`、订单明细进 `raw_data` |
| 广告 | `advertising` | `list_exposure=曝光量`、`detail_exposure=点击量`、`flow_rate=点击到预订转化`、消耗/计划/ROI 进 `raw_data` |

新增结构化字段的条件：

- 现有字段和 `raw_data` 无法支持保存、回显、编辑、分析或去重。
- 字段会被明确的页面、接口、报表、预警或 AI 分析读取。
- 同时补迁移、旧数据兼容、空值兜底和验证。

## 清洗与去重

- 点评按平台评价 ID 去重；没有评价 ID 时按酒店、日期和原始内容摘要兜底。
- 订单按平台订单号去重；无法获取订单号时只写汇总行，不写明细级订单。
- 每日概况按 `system_hotel_id + source + data_type + data_date` 更新。
- 流量与广告按 `system_hotel_id + source + data_type + dimension + data_date` 更新。
- 评分如果是 10-50 口径，统一换算为 1.0-5.0。
- 小于 4 分可在 `raw_data` 中标记 `is_negative=true`，不新增字段。

## 兜底策略

1. 接口 JSON 优先：结构化、可追溯，适合落库。
2. 页面 DOM 兜底：只补排名、摘要或页面展示指标，避免抓取导航、菜单、按钮文字。
3. 原始响应留存：关键接口保留脱敏 JSON，便于字段变化排查、对账和修复。

## 稳定性

- 不把所有接口都当目标接口，必须维护接口匹配规则。
- 页面加载慢时允许重试；日报建议采集上午较晚的昨日数据。
- 数据不完整时标记暂缺或异常，不写入错误数据冒充真实经营结果。
- 采集后验证 OTA 列表、AI 诊断、收益分析、经营预警是否还能读取已保存行。
