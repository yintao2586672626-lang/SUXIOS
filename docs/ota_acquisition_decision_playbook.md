# OTA 数据采集决策流程

> 更新日期：2026-05-30
> 适用范围：携程 eBooking、美团 eBooking/TMC、宿析OS线上数据模块
> 核心目标：OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策

## 一、推荐顺序

| 优先级 | 路径 | 适用条件 | 项目内证据 | 不做什么 |
| --- | --- | --- | --- | --- |
| P0 | 已确认接口：后端直连 Cookie/API | 接口、Payload、Cookie/Token、参数和字段含义已确认，且能稳定返回 JSON | `OnlineData::fetchCtrip`、`fetchCtripTraffic`、`fetchMeituanTraffic`、`ApiDataSourceAdapter` | 不启动浏览器，不用 DOM 解析补假数据 |
| P1 | 接口不确定：CDP 临时监听页面 | 页面接口、动态 token、Payload 或签名规则不确定，需要先定位真实请求 | `scripts/ctrip_browser_capture.mjs`、`scripts/meituan_browser_capture.mjs` 的 response 监听能力 | 不直接固化未知接口，不猜 token，不猜 Payload |
| P2 | 必须真实打开页面：Profile + CDP 兜底 | 只有登录后页面真实加载才会触发数据；iframe/SPA/动态签名复杂；人工登录态需要复用 | `storage/ctrip_profile_{id}`、`storage/meituan_profile_{store_id}`、`capture-ctrip-browser`、`capture-meituan-browser` | 不作为默认路径，不绕过短信/滑块/人机验证 |

默认判断规则：

```text
已确认接口可稳定复用 -> 后端直连 Cookie/API
接口还没确认或参数复杂 -> 用浏览器/CDP 临时监听，沉淀 URL、Payload、响应样本
页面必须真实打开才有数据 -> 门店独立 Profile + CDP 监听，作为复杂页面兜底
```

## 二、当前项目采集入口

| 模块 | 已有入口 | 当前定位 |
| --- | --- | --- |
| 携程经营概况 | `POST /api/online-data/fetch-ctrip` | 已确认接口直连入口，默认接口含 `getDayReportCompeteHotelReport` |
| 携程流量 | `POST /api/online-data/fetch-ctrip-traffic`、`POST /api/online-data/ctrip/traffic` | 已确认流量接口直连入口 |
| 携程浏览器采集 | `POST /api/online-data/capture-ctrip-browser`、`scripts/ctrip_browser_capture.mjs` | Profile + response 监听，用于经营概况/流量复杂场景 |
| 美团流量 | `POST /api/online-data/fetch-meituan-traffic` | Cookie/API 兼容入口 |
| 美团订单/广告 | `POST /api/online-data/fetch-meituan-orders`、`fetch-meituan-ads` | Cookie/API 兼容入口，按模块确认参数后使用 |
| 美团浏览器采集 | `POST /api/online-data/capture-meituan-browser`、`scripts/meituan_browser_capture.mjs` | Profile + response 监听，默认采集 `traffic,orders` |
| 平台数据源同步 | `/api/online-data/data-sources`、`PlatformDataSyncService` | 统一管理 `manual`、`import_*`、`api` 类型数据源 |
| 原始/任务追踪 | `platform_data_sources`、`platform_data_sync_tasks`、`platform_data_raw_records`、`platform_data_sync_logs` | 保存来源、状态、原始响应和同步日志 |

## 三、资源到方法的路由

| 资源 | 默认方法 | 监听方法 | 入库/标准化 |
| --- | --- | --- | --- |
| `businessData` 经营概况 | 携程直连接口优先；美团按已确认接口优先 | 未确认字段时监听经营概况页 response | `online_daily_data`，后续进入标准事实表 |
| `tradeData` 交易/订单 | 已确认订单接口或导出文件优先 | 订单页必须加载才返回时使用 Profile + CDP | `data_type=order`，订单明细脱敏后进 `raw_data` |
| `flowData` 流量 | 已确认流量概要接口优先 | 接口变化、iframe/SPA 复杂时监听页面 | `data_type=traffic`，PV/UV/曝光/转化口径分开 |
| `searchKeyWords` 搜索词 | 已确认报表/API 优先 | 排名/关键词只在页面展示时监听或有限 DOM 补充 | 标注 OTA 搜索/广告行为，不代表全网需求 |
| `peerRank` 竞品排名 | 已确认竞品接口优先 | 竞品页接口不稳定时监听 | 标注 OTA 竞品/渠道流量参考，不代表全市场 |
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
5. 判断是否可固化为后端直连：
   - Cookie/API 可复用且参数稳定：沉淀到后端直连或 `ApiDataSourceAdapter`。
   - 动态 token/签名依赖页面上下文：保留 Profile + CDP 路径。
6. 保存脱敏样本和规则，不保存 Cookie、Token、手机号、订单号明文。

## 六、Profile + CDP 兜底流程

适用于必须真实打开页面才有数据的复杂页面。

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

当前 Agent 已具备浏览器和桌面应用操作能力，但在宿析OS项目中必须按任务选择工具，不把操作能力等同于默认采集路径。

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

1. 先把已确认接口做成稳定直连接口清单。
2. 对不确定接口建立 CDP 监听记录模板。
3. 对复杂页面保留 Profile + CDP 兜底，不上升为默认方案。
4. 统一采集任务模型：来源、方法、状态、失败原因、原始响应、标准化结果。
5. 统一展示口径：所有 OTA-only 页面明确标注 `OTA 渠道口径`。
6. 将稳定字段进入事实表和指标服务，再进入日报、诊断、AI 建议和投资测算。
