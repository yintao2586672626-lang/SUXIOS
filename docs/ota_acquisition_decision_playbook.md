# OTA 数据采集决策流程

> 更新日期：2026-06-27
> 适用范围：携程 eBooking、美团 eBooking/TMC、宿析OS线上数据模块
> 核心目标：OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策

## 一、推荐顺序

| 优先级 | 路径 | 适用条件 | 项目内证据 | 不做什么 |
| --- | --- | --- | --- | --- |
| P0 | 浏览器 Profile 登录态采集 | 日常采集、同日补数、日报、巡检、预警；平台账号已授权且门店已绑定 | `storage/ctrip_profile_{id}`、`storage/meituan_profile_{store_id}`、`capture-ctrip-browser`、`capture-meituan-browser` | 不绕过短信/滑块/人机验证，不采集非授权门店 |
| P1 | 手动 Cookie/API 或文件导入 | 临时补数、首次接入、平台改版排障、自动采集失效后的补录；用户已提供上下文或导出文件 | `OnlineData::fetchCtrip`、`fetchCtripTraffic`、`fetchMeituanTraffic`、`ApiDataSourceAdapter` | 不作为日常主线，不代登录 OTA，不猜 token/Payload |
| P2 | 临时 CDP 接口定位 | 页面接口、动态 token、Payload 或签名规则不确定，需要先定位真实请求 | `scripts/ctrip_browser_capture.mjs`、`scripts/meituan_browser_capture.mjs` 的 response 监听能力 | 不直接固化未知接口，不保存敏感凭据，不把样例当实时数据 |

默认判断规则：

```text
日常/同日 OTA 数据 -> 门店独立浏览器 Profile 登录态采集
Profile 登录失效 -> 提示人工完成登录/验证后重试
临时补数/首次接入/排障 -> 手动 Cookie/API 或文件导入
接口还没确认或参数复杂 -> 临时 CDP 监听，沉淀脱敏 URL、Payload、响应样本
```

## 二、当前项目采集入口

| 模块 | 已有入口 | 当前定位 |
| --- | --- | --- |
| 携程浏览器采集 | `POST /api/online-data/capture-ctrip-browser`、`scripts/ctrip_browser_capture.mjs` | 默认主线：Profile + response 监听，用于经营概况、流量、订单、房态房价 |
| 美团浏览器采集 | `POST /api/online-data/capture-meituan-browser`、`scripts/meituan_browser_capture.mjs` | 默认主线：Profile + response 监听，默认采集 `traffic,orders` |
| 携程经营概况 | `POST /api/online-data/fetch-ctrip` | 手动 Cookie/API 兼容入口，仅用于临时补数、首次接入或排障 |
| 携程流量 | `POST /api/online-data/fetch-ctrip-traffic`、`POST /api/online-data/ctrip/traffic` | 手动 Cookie/API 兼容入口，仅用于临时补数、首次接入或排障 |
| 美团流量 | `POST /api/online-data/fetch-meituan-traffic` | 手动 Cookie/API 兼容入口，仅用于临时补数、首次接入或排障 |
| 美团订单/广告 | `POST /api/online-data/fetch-meituan-orders`、`fetch-meituan-ads` | 手动 Cookie/API 兼容入口，按模块确认参数后临时使用 |
| 平台数据源同步 | `/api/online-data/data-sources`、`PlatformDataSyncService` | 统一管理 `manual`、`import_*`、`api` 类型数据源 |
| 原始/任务追踪 | `platform_data_sources`、`platform_data_sync_tasks`、`platform_data_raw_records`、`platform_data_sync_logs` | 保存来源、状态、原始响应和同步日志 |

## 三、资源到方法的路由

| 资源 | 默认方法 | 监听方法 | 入库/标准化 |
| --- | --- | --- | --- |
| `businessData` 经营概况 | 浏览器 Profile 登录态采集 | 接口变化、iframe/SPA 复杂时临时 CDP 定位 | `online_daily_data`，后续进入标准事实表 |
| `tradeData` 交易/订单 | 浏览器 Profile 登录态采集 | 订单页必须加载才返回时使用 Profile response 监听 | `data_type=order`，订单明细脱敏后进 `raw_data` |
| `flowData` 流量 | 浏览器 Profile 登录态采集 | 接口变化、iframe/SPA 复杂时监听页面 | `data_type=traffic`，PV/UV/曝光/转化口径分开 |
| `searchKeyWords` 搜索词 | 浏览器 Profile 登录态采集；手动报表仅临时补充 | 排名/关键词只在页面展示时监听或有限 DOM 补充 | 标注 OTA 搜索/广告行为，不代表全网需求 |
| `peerRank` 竞品排名 | 浏览器 Profile 登录态采集；手动报表仅临时补充 | 竞品页接口不稳定时监听 | 标注 OTA 竞品/渠道流量参考，不代表全市场 |
| `roomTypes` 房型/产品 | PMS/OTA 映射优先 | 页面展示的房型、价格、库存仅作补充 | 保留 `unmapped`、`matched`、`conflict`、`needs_review` 状态 |

## 四、采集状态与来源字段

每次采集或导入必须保留以下元数据：

| 字段 | 说明 |
| --- | --- |
| `hotelId` / `system_hotel_id` | 宿析OS酒店 ID |
| `platform` | `ctrip` / `meituan` |
| `resource` / `data_type` | 业务资源，如经营、订单、流量、广告 |
| `ingestion_method` | `api`、`manual`、`import_json`、`import_csv`、`browser_profile`、`network_response` 等 |
| `request_params` | 日期、酒店 ID、渠道 Tab、必要 Payload 摘要；敏感字段脱敏 |
| `collected_at` | 采集时间 |
| `source_updated_at` | 平台数据更新时间，拿不到则显式为空 |
| `status` | `success`、`partial_success`、`failed`、`waiting_config`、`waiting_auth` |
| `failure_reason` | 登录失效、字段缺失、接口未命中、Payload 不完整等具体原因 |
| `schema_version` | 解析规则或标准化版本 |

禁止用空数据、默认值或宽泛 catch 把失败包装成成功。

## 五、CDP 临时监听流程

适用于接口不确定或参数复杂的页面。

1. 使用已有浏览器控制能力打开真实 OTA 页面。
2. 开启 Network/CDP 监听，只记录 XHR/fetch、HTTP 200、JSON 或疑似 JSON 响应。
3. 完成一次真实人工操作：切换日期、点击查询、展开 Tab、滚动触发加载。
4. 记录命中接口：URL、method、headers 摘要、Payload、响应结构、业务字段。
5. 判断是否进入主线或临时路径：
   - 日常采集需要稳定复用：优先沉淀到浏览器 Profile 登录态采集路径。
   - 用户只提供一次性上下文或补历史数据：保留为手动 Cookie/API 或导入兼容入口。
   - 动态 token/签名依赖页面上下文：继续保留 Profile + CDP 路径。
6. 保存脱敏样本和规则，不保存 Cookie、Token、手机号、订单号明文。

## 六、Profile 登录态主线流程

适用于日常采集、同日补数、日报、巡检和预警。手动 Cookie/API 不替代该主线。

| 环节 | 规则 |
| --- | --- |
| Profile 隔离 | 每个门店独立目录：`storage/ctrip_profile_{id}`、`storage/meituan_profile_{store_id}` |
| 登录 | 只允许人工完成短信、滑块、人机验证；系统不绕过验证 |
| 页面触发 | 由真实页面触发业务接口，系统监听 response |
| 数据优先级 | JSON response 优先，DOM 仅补页面已展示的排名/摘要 |
| 失败处理 | 登录超时、接口未命中、字段缺失必须返回明确状态 |
| Git 边界 | Profile、Cookie、截图、含敏感数据的导出不得进入 Git |

## 七、口径边界

| 口径 | 允许表达 | 禁止表达 |
| --- | --- | --- |
| OTA 渠道口径 | OTA 订单、OTA 间夜、OTA 渠道 ADR、渠道曝光、渠道转化 | 全酒店入住率、全酒店 ADR、全酒店 RevPAR |
| 全酒店经营口径 | 仅在接入 PMS/CRS、线下/直客订单、全量可售房和全量收入后使用 | 用携程/美团局部数据推导全酒店经营真相 |
| AI 决策口径 | 原因 + 证据 + 建议动作 + 影响指标 | 只有预测分数，没有证据链 |

## 八、Agent 操作能力路由

当前 Agent 已具备浏览器和桌面应用操作能力，但在宿析OS项目中必须按任务选择工具。默认数据路径是授权门店的浏览器 Profile 登录态采集，工具选择不能用于绕过平台登录、安全验证或权限边界。

| 能力 | 适用任务 | 在本项目中的用途 | 边界 |
| --- | --- | --- | --- |
| Browser / Playwright | 本地页面、localhost、前端交互、网络响应检查 | 验证宿析OS页面、触发采集按钮、检查接口调用和页面状态 | 不用于绕过 OTA 登录、安全验证或权限 |
| Chrome / CDP | 需要真实 Chrome、已登录账号、扩展、现有浏览器会话或平台页面 Network | 临时监听 OTA 页面接口，定位 URL、Payload、动态 token、响应结构 | 只沉淀脱敏接口证据，不保存 Cookie/Token 明文 |
| Computer Use | 必须操作 Windows 桌面应用、XAMPP、浏览器弹窗、文件选择器或非网页窗口 | 辅助完成本地环境启动、人工登录后的观察、导入文件选择等 | 只在浏览器工具不能覆盖时使用，不作为自动采集默认路径 |
| OpenAI Developers | OpenAI API、Responses API、Agents SDK、ChatGPT Apps 相关开发和排障 | 用于 AI 能力接入、模型配置、联网检索链路说明 | 不替代 OTA 平台采集；涉及 OpenAI 产品规则时以官方文档或当前代码为准 |

推荐执行顺序：

```text
本地宿析OS页面验证 -> Browser / Playwright
真实 OTA 页面接口定位 -> Chrome / CDP
系统级桌面操作 -> Computer Use
OpenAI API / Agent 能力 -> OpenAI Developers
```

## 九、后续整理顺序

1. 先把浏览器 Profile 登录态采集做成日常主线清单。
2. 对不确定接口建立 CDP 监听记录模板。
3. 手动 Cookie/API 和文件导入只作为临时补数、首次接入和排障路径保留。
4. 统一采集任务模型：来源、方法、状态、失败原因、原始响应、标准化结果。
5. 统一展示口径：所有 OTA-only 页面明确标注 `OTA 渠道口径`。
6. 将稳定字段进入事实表和指标服务，再进入日报、诊断、AI 建议和投资测算。
