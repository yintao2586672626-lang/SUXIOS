---
name: suxi-ota-ops
description: Handle宿析OS OTA运营、携程/美团数据获取、手动采集、自动采集、Cookie/Profile、订单、房价、库存、流量、点评、竞品和渠道诊断任务。Use when the request includes OTA、携程、美团、ebooking、Trip Connect、TMC、browser capture、Profile、Cookie、订单抓取、在线数据、渠道、房价、库存、竞品、排名、转化率、曝光、点评、广告、OnlineData、cron_fetch、auto_fetch_online_data。
---

# Suxi OTA Ops

## Business Goal

宿析OS的目标不是把所有平台都套进同一种抓取方式，而是让携程和美团都具备两条清晰路径：

1. 手动获取：用户已经拿到平台上下文、导出文件、Cookie/Payload 或接口参数，系统负责校验、抓取/导入、清洗和入库。
2. 自动获取：系统复用每个门店独立浏览器 Profile，在授权账号下打开平台页面，监听真实业务 JSON，定时或按需采集。

两条路径不能混用概念：手动路径不要求系统登录 OTA 后台；自动路径不要求用户每次复制 Cookie/Payload。

## Global Rules

1. 修改前先检查当前 OTA 数据流：controller、route、scripts、storage、scheduled jobs、现有入库读取链路。
2. Cookie、token、spidertoken、Profile、账号密码、手机号、证件号等均按敏感信息处理；不打印、不提交、不写入普通文档。
3. 先按业务模块定位问题，再改对应平台和对应路径；不要因为一个模块失败改全平台通用逻辑。
4. 保留现有渠道字段名、历史导入数据和 `online_daily_data` 兼容性。
5. 默认不新增 `reviews`、`orders`、`traffic_data` 表；只有明确产品功能需要结构化明细查询时才新增表或字段。
6. 记录外部平台端点、本地系统端口、浏览器调试端口时必须分开；不要把默认 HTTPS 端口硬编码成平台规则。
7. 空数据、登录失效、接口未命中、字段缺失必须显式返回原因，不写假成功、不写兜底假数据。

## Collection Modes

| 路径 | 适用场景 | 用户提供 | 系统动作 | 不做什么 |
| --- | --- | --- | --- | --- |
| 手动获取 | 临时补数、首次接入、平台改版排障、用户已导出报表或抓到请求上下文 | 平台、酒店、日期、数据模块、导出文件或 Cookie/Payload/必要 ID | 校验字段，调用现有接口或导入解析，清洗、脱敏、去重、入库 | 不自动登录 OTA，不启动 Profile，不要求全量页面自动化 |
| 自动获取 | 日常稳定采集、日报、巡检、预警、无需每次人工复制上下文 | 平台账号已授权，门店和系统酒店已绑定 | 使用门店独立 Profile，失效时提示人工登录，监听业务 JSON，按模块入库 | 不绕过验证码/短信/权限，不采集非授权门店 |

## Ctrip Rules

- 参考文档：`references/ctrip-browser-capture.md`。
- 手动优先处理用户提交的 `Cookie`、`spidertoken`、`node_id` / 平台酒店 ID、Payload、日期范围、渠道 Tab，或携程后台/Trip Connect 可导出的内容、ARI、预订、点评、分析数据。
- 自动使用 `storage/ctrip_profile_{store_id}`，按门店隔离 Profile；失效时打开携程登录页等待人工完成短信、滑块或人机验证。
- 自动模块优先级：经营概况、流量、订单、点评、房态房价；广告、渠道评分、更多页面只在明确业务需要时加入。
- JSON 响应优先；DOM 只补页面已展示的排名、摘要或缺失指标，不抓导航、菜单、按钮文本作为业务数据。
- 入库优先映射：
  - Overview: `amount`, `quantity`, `book_order_num`, `comment_score`, `raw_data`.
  - Reviews: `data_type=review`, score in `comment_score`/`data_value`, details in `raw_data`.
  - Traffic: `data_type=traffic`, use `list_exposure`, `detail_exposure`, `flow_rate`, `order_filling_num`, `order_submit_num`, details in `raw_data`.
  - Orders: `data_type=order`, amount in `amount`, room nights in `quantity`, order count in `book_order_num`, details in `raw_data`.
  - ARI/price/inventory: keep room/product/calendar details in `raw_data` first unless a product feature needs structured fields.

## Meituan Rules

- 参考文档：`references/meituan-browser-capture.md`。
- 手动优先处理用户提交的 Cookie/Session、`partner_id`、`poi_id` / `store_id`、Payload、iframe URL、必要动态签名，或直连平台可导出的产品、订单日志、订单监控数据。
- 自动优先走页面触发流程：`POST /api/online-data/capture-meituan-browser` starts `scripts/meituan_browser_capture.mjs`，复用 `storage/meituan_profile_{store_id}`。
- 自动模块优先级：点评、流量/数据中心、订单/入住管理、价格库存/直连产品；广告只在已有广告账号、成本口径和复盘需求时加入。
- 美团页面 iframe、SPA、签名和登录态变化更频繁，自动路径应以浏览器响应监听为主；手动路径只校验用户已提供的上下文，不在后台代登录。
- 入库优先映射：
  - Reviews: `data_type=review`, score in `comment_score`/`data_value`, details in `raw_data`.
  - Traffic: `data_type=traffic`, use `list_exposure`, `detail_exposure`, `flow_rate`, ranking and keyword data in `raw_data`.
  - Orders: `data_type=order`, amount in `amount`, room nights in `quantity`, order count in `book_order_num`, average price in `data_value`, details in `raw_data`.
  - Price/inventory/products: keep direct product, price, stock, breakfast, commission, switch status in `raw_data` first.
  - Ads: `data_type=advertising` only when ad cost/campaign data is available and needed.

## Storage Boundary

- 当前统一优先写入 `online_daily_data`，通过 `source`、`data_type`、`dimension`、`data_date`、`raw_data` 区分平台和模块。
- 订单号、点评 ID、房型/价型/产品 ID、库存快照、接口耗时等先保留在脱敏 `raw_data`；当页面、报表、预警、AI 分析明确读取时，再补结构化字段。
- 新增字段必须同时考虑保存、回显、编辑、旧数据兼容、权限过滤、空值和脱敏。

## Verification

- 明确本次改的是携程还是美团、手动路径还是自动路径、哪个数据模块。
- 验证正常采集、登录失效、缺字段、空数据、平台返回异常。
- 验证写入后 OTA 历史、经营分析、收益分析、AI 诊断和预警仍可读取。
- 验证 Profile、Cookie、token、截图、含敏感数据的导出不进入 Git。
