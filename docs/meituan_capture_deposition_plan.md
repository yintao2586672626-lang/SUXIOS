# 美团 eBooking 数据采集方法沉淀计划

## 目标

将美团 eBooking 采集方式沉淀为项目可复用能力，覆盖三类使用场景：

1. 研发与 Agent 执行：通过 `suxi-ota-ops` 技能约束采集、入库、验证方式。
2. 系统知识中枢：通过 `knowledge_units` / `knowledge_chunks` 保存可检索经验片段。
3. 员工知识库：通过 `knowledge_base` 保存面向运营人员的操作方法。

## 当前采集口径

| 项目 | 规则 |
| --- | --- |
| 登录态 | 每个门店独立保存 `storage/meituan_profile_{store_id}` |
| 触发方式 | 页面点击“开始抓取并入库”，后端启动本机浏览器脚本 |
| 首次登录 | 弹出美团窗口，人工完成登录；脚本自动等待登录态 |
| 数据来源 | response 监听优先，DOM/HTML 兜底，截图仅用于排障 |
| 当前重点 | 先做流量、订单、经营数据和广告；美团点评暂缓，不进入默认自动采集 |
| 入库表 | 统一写入 `online_daily_data`，不新增 `reviews`、`traffic_data`、`orders` |
| 备用方式 | 保留命令复制和 JSON 导入，用于排障和离线补录 |

## 页面与接口

| 数据 | 页面 | 命中关键词 |
| --- | --- | --- |
| 点评（暂缓） | `https://me.meituan.com/ebooking/merchant/comment-manage-react#/home` | `queryGeneralCommentInfo`、`commentsInfo`、`comments/statistics` |
| 流量 | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Fdata-center%2Findex.html` | `businessData`、`traffic`、`peerTrends` |
| 新流量 SPA | `https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html` | `businessData`、`traffic`、`peerTrends` |
| 广告 | 通过 `ads_url` 指定 | `cureShops` |
| 订单 | `https://me.meituan.com/ebooking/merchant/ebIframe?iUrl=%2Febooking%2Forder-eb%2Findex.html%23%2Fcheckin` | `/orders/list`、`/order/unhandled/count` |

## 入库映射

| 来源数据 | `data_type` | 项目字段 |
| --- | --- | --- |
| 点评（暂缓） | `review` | 仅显式手动启用时保存；不进入当前默认自动采集 |
| 流量 | `traffic` | `list_exposure`、`detail_exposure`、`flow_rate`、关键词/排名写入 `raw_data` |
| 广告 | `advertising` | 曝光、点击、转化沿用流量字段，计划、关键词、ROI 写入 `raw_data` |
| 订单 | `order` | `amount`、`quantity`、`book_order_num`、`data_value`，订单详情写入 `raw_data` |
| 竞对平台标签 | `peer_rank` / `competition` | 基础展示已补：记录美团竞对榜单返回的 `VIP` 等平台标签；仅展示已授权响应或页面 DOM 明确返回的标签，未返回时标记“未返回”，不通过订单、客人、房态或房源映射推断 |

## 沉淀任务

| 阶段 | 任务 | 产物 |
| --- | --- | --- |
| P0 | 固化真实抓取链路 | `scripts/meituan_browser_capture.mjs`、`POST /api/online-data/capture-meituan-browser`；默认采集 `traffic,orders` |
| P1 | 固化 Agent 技能 | `suxi-ota-ops/references/meituan-browser-capture.md` |
| P2 | 固化知识中枢 | `database/migrations/20260519_seed_meituan_browser_capture_knowledge.sql` |
| P3 | 固化初始化链路 | `database/init_full.sql` 引入美团知识 seed |
| P3 | 补充竞对 VIP 标签展示 | 基础展示已完成：美团竞对榜单解析、入库 `raw_data` 和前端表格/首页摘要已展示平台返回的 VIP 标签；缺字段时保留缺失状态 |
| P4 | 验证可用性 | PHP lint、Node check、现有 `OnlineDataTest`、前端入口检查 |

## 验证清单

1. 页面存在“开始抓取并入库”，且传入 `system_hotel_id`、`store_id`、`poi_id`、`poi_name`。
2. 首次无登录态时能弹出美团登录页，并在人工登录后继续抓取。
3. 抓取完成后生成 `runtime/meituan_capture/*.json`。
4. 入库结果可被 OTA 历史、经营分析、收益分析和 AI 诊断读取。
5. 未命中数据时返回“抓取完成但未解析到可入库数据”，不伪造经营结果。
6. Profile、Cookie、截图、手机号等敏感内容不进入 Git。
