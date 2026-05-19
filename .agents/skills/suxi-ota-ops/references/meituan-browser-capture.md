# 美团浏览器采集方法

## 定位

美团 eBooking 数据采集以浏览器 Profile + response 监听为主。手动采集路径默认用户已提供 Cookie、Session、门店 ID、Payload、动态签名等必要抓取上下文，宿析OS系统只负责校验字段、调用对应接口完成数据抓取、清洗和入库。

目标是在酒店已有合法账号和授权范围内，自动路径模拟运营人员打开美团 eBooking 后台并监听页面返回的经营 JSON；手动路径使用用户提交的字段上下文，由系统端抓取并按宿析 OS 现有字段入库。

## 合规边界

- 只采集当前酒店账号下可见的数据。
- 不绕过登录、短信、滑块、人机验证或平台权限体系。
- 不采集非授权门店、竞品后台或平台内部不可见数据。
- 不把 Cookie、Profile、账号密码、手机号明文写入文档、日志或 Git。
- 客人手机号等敏感字段进入 `raw_data` 前应脱敏或最小化保留。

## 端点记录规则

“端口”只记录真实观察到的访问端点，不写死为固定值。

| 项 | 记录规则 |
| --- | --- |
| 页面 URL | 记录完整入口 URL，例如点评页、流量 iframe、newhb SPA、订单页、广告页 |
| 请求 URL 关键词 | 记录可稳定匹配的接口片段，例如 `queryGeneralCommentInfo` |
| 协议与域名 | 从浏览器请求 URL 解析，如 `https` + `me.meituan.com`、`eb.meituan.com`、`ebmidas.dianping.com` |
| 端口来源 | URL 有显式端口时记录显式端口；无显式端口时只备注“按协议默认推断”，不作为代码常量 |
| 端口分类 | 外部平台端点、本地系统端口、浏览器调试端口分开记录 |
| 是否显式端口 | 用布尔值或说明字段记录，避免把默认推断值误当作平台固定规则 |

## 登录态与 Profile

| 项目 | 规则 |
| --- | --- |
| Profile 目录 | `storage/meituan_profile_{store_id}` |
| 隔离粒度 | 每个门店一个独立浏览器 Profile |
| 登录流程 | 复用 Profile -> 打开登录入口检查登录态 -> 失效时人工登录 -> 登录成功后自动继续采集 |
| 保存策略 | Profile 不进入 Git；`.gitignore` 必须忽略 `storage/meituan_profile_*` |

多门店采集必须带 `system_hotel_id` 和美团 `store_id` / `poi_id`，入库前确认当前账号与目标门店匹配。

## 手动采集路线

手动采集不是手工复制经营数据，也不要求系统登录 OTA 后台；它默认用户已经提供系统抓取所需上下文字段。

1. 用户在宿析OS选择平台、酒店、数据模块和日期范围。
2. 用户提交已取得的必要字段：Cookie/Session、`partner_id`、`poi_id`、`store_id`、原始 Payload、iframe URL、必要时的 `mtgsig` 或其他动态字段。
3. 系统校验必填字段；字段缺失时只提示用户补充对应字段和参考页面入口，不在手动流程内自动登录后台。
4. 系统调用 `/api/online-data/fetch-meituan`、`/fetch-meituan-traffic`、`/fetch-meituan-comments` 或浏览器采集接口抓取。
5. 系统完成 JSON 解析、空值兜底、去重、脱敏和入库；人工页面文本、iframe 文本或 OCR 仅用于接口缺失时的兜底线索。

## 自动采集路线

1. Playwright 启动持久化 Profile：`storage/meituan_profile_{store_id}`。
2. 复用已登录状态；失效时打开美团登录页，等待人工完成短信、滑块或人机验证。
3. 注册 `response` 监听，只处理 XHR/fetch、HTTP 200、JSON 响应。
4. 按页面入口触发接口，使用 URL 关键词归入点评、流量、排名、订单、广告等数据槽位。
5. 接口未命中时读取 iframe DOM、页面文本或 HTML 摘要；OCR 仅作为最后排障兜底。
6. 清洗后写入 `online_daily_data`，保留脱敏原始结构到 `raw_data`。

## 页面与接口监听

| 数据模块 | 页面入口 | 请求 URL / 关键字 | 手动必须字段 | 可取字段与兜底 |
| --- | --- | --- | --- | --- |
| 登录态 | `https://me.meituan.com/ebooking/` | Cookie / Session | 用户已提供的 Cookie/Session、账号可见门店 | 手动模式只校验已提交 Cookie/Session；自动模式才检查 Profile 登录态 |
| 点评数据 | `https://me.meituan.com/ebooking/merchant/comment-manage-react#/home` | `queryGeneralCommentInfo`、`commentsInfo`、`comments/statistics` | Cookie/Session、`poi_id` / `store_id`、点评 Payload、日期/分页条件 | 评分、评价总数、好评数、差评数、新增差评、未回复数、含图数、评价内容、房型、入住日期、评价时间、商家回复、标签；页面文本/iframe/OCR 兜底 |
| 流量数据 | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Fdata-center%2Findex.html` | `businessData`、`weightTraffic`、`traffic`、`peerTrends` | Cookie/Session、`partner_id`、`poi_id` / `store_id`、iframe URL、日期 Payload | 曝光量、浏览人数/UV、页面浏览量、点击量、支付转化率；iframe DOM/HTML/OCR 兜底 |
| 排名数据 | `https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html` | 页面文案：`同行排名`、`订单量`、`曝光量`、`浏览人数`、`入住间夜` | Cookie/Session、`poi_id` / `store_id`、页面上下文 | 订单量排名、曝光排名、访客排名、入住间夜排名、综合排名、分类排名；DOM 文本正则优先，OCR 兜底 |
| 订单数据 | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Forder-eb%2Findex.html%23%2Fcheckin` | `/orders/list`、`/order/unhandled/count` | Cookie/Session、`partner_id`、`poi_id` / `store_id`、订单筛选 Payload、日期范围 | 订单号、状态、房型、间数、入住/离店日期、晚数、金额、均价、客人姓名、来源；iframe 表格 DOM 兜底 |
| 推广通广告 | `https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry?continueUrl=/app/peon-merchant-product-menu/html/index.html` | `cureShops` | Cookie/Session、店铺 ID、广告 Payload、日期范围 | 店铺 ID、店铺名、推广日期、曝光、点击、预订成交量、点击率、昨日消耗、去重说明；失败时截图留证并返回空数据 |

响应处理只接收：

- XHR 或 fetch 返回的数据。
- HTTP 状态码为 200 的业务响应。
- 可解析为 JSON 的响应体优先。
- URL 命中明确业务规则。

DOM、HTML 和截图只能作为兜底：DOM 用于页面已展示但接口未命中的摘要、排名或列表文本；截图只用于排障和人工复核。

`mt_revenue`、`mt_rooms` 等美团收入/间夜口径通常来自 PMS 或经营报表，不默认视为美团商家平台页面直接提供的核心字段；只有实际接口或页面可见且可追溯时才映射。

## 入库映射

项目实际使用 `online_daily_data`，不新增 `reviews`、`orders`、`traffic_data` 表。

通用字段：

| 字段 | 规则 |
| --- | --- |
| `source` | 固定 `meituan` |
| `platform` | 优先 `Meituan` |
| `system_hotel_id` | 宿析 OS 酒店 ID，能拿到时必须写入 |
| `hotel_id` | 美团 `poi_id` 或 `store_id` |
| `hotel_name` | 平台或系统酒店名 |
| `data_date` | 指标日期；无法识别时用采集日期 |
| `dimension` | `点评`、`流量`、`广告`、`订单` |
| `raw_data` | 脱敏后的原始 JSON、DOM 摘要和命中来源 |

业务映射：

| 来源数据 | `data_type` | 核心映射 |
| --- | --- | --- |
| 点评 | `review` | `comment_score=score`，`data_value=score`，点评 ID、内容、回复、是否差评、点评人、房型、入住时间、标签进 `raw_data` |
| 流量 | `traffic` | `list_exposure=exposure_count`，`detail_exposure=page_views/click_count`，`flow_rate=conversion_rate`，搜索/品类/关键词排名进 `raw_data` |
| 广告 | `advertising` | `list_exposure=exposure_count`，`detail_exposure=click_count/page_views`，`flow_rate=conversion_rate`，广告计划、关键词、ROI 进 `raw_data` |
| 订单 | `order` | `amount=total_amount`，`quantity=room_count*nights`，`book_order_num=order_count/1`，`data_value=avg_price`，订单状态、客人、房型、入住离店日期进 `raw_data` |

新增结构化字段的条件：

- 现有字段和 `raw_data` 无法支持保存、回显、编辑、分析或去重。
- 字段会被明确页面、接口、报表、预警或 AI 分析读取。
- 同时补迁移、旧数据兼容、空值兜底和验证。

## 清洗与去重

- 点评按平台点评 ID 去重；缺少 ID 时按酒店、日期、内容摘要兜底。
- 订单按订单号去重；缺少订单号时只写汇总行，不伪造明细级订单。
- 流量和广告按 `system_hotel_id + source + data_type + dimension + data_date` 更新。
- 评分口径统一到 1.0-5.0。
- 小于 4 分可在 `raw_data` 标记 `is_negative=true`，不新增字段。

## 兜底策略

1. 接口 JSON 优先：结构化、可追溯，适合落库。
2. DOM/HTML 兜底：只补页面展示指标或摘要，必须标记 `_capture_source`。
3. 原始响应留存：关键接口保留脱敏 JSON，便于字段变化排查、对账和修复。
4. 失败显式返回：登录超时、接口未命中、空数据不写假数据。

## 验证

- 确认 `POST /api/online-data/capture-meituan-browser` 可启动脚本。
- 确认首次登录、已登录 Profile、登录超时都有可解释结果。
- 确认抓取结果写入 `online_daily_data` 后，OTA 历史、AI 诊断、收益分析和经营预警仍可读取。
- 确认 `storage/meituan_profile_*`、截图和含敏感数据的输出不进入 Git。
