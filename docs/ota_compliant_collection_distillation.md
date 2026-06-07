# OTA 合规采集能力沉淀

## 目标

把竞品系统里值得学习的采集思路，沉淀为宿析 OS 可复用、可解释、可交付的产品能力。

本文件只沉淀授权采集、账号绑定、Profile 登录态、任务状态、字段证据和 ETL 可展示状态；不沉淀绕过验证码、绕过平台限制、规避权限、抓取客人隐私或非授权门店数据的实现细节。

## 可沉淀能力

| 能力 | 产品化表达 | 宿析 OS 落点 |
| --- | --- | --- |
| 账号绑定替代 Cookie 表单 | 用户只看到绑定平台账号、登录状态、最近同步 | 酒店管理、线上数据自动获取、平台数据源 |
| Profile 登录态 | 每个门店独立 Profile，登录失效时提示人工登录 | `storage/ctrip_profile_{id}`、`storage/meituan_profile_{store_id}` |
| 采集资源目录 | 用统一资源名描述要采什么 | `businessData`、`peerRank`、`flowData`、`searchKeywords`、`reviewData`、`roomTypes` |
| 任务化采集 | 创建任务 -> 执行中 -> 成功/失败 -> 入库 -> 页面刷新 | `platform_sync_tasks`、`platform_sync_logs`、线上数据状态面板 |
| 模块级状态 | 每个资源显示已登录、采集中、登录失效、字段缺失、最近同步 | `/api/online-data/collection-resources`、采集资源状态矩阵 |
| ETL 可展示状态 | 区分采集成功、已解析、已入库、可展示 | `stored_displayable`、`capture_success_not_stored`、`normalized_not_stored` |
| 字段证据契约 | 记录来源、周期、平台、更新时间、缺失原因 | `raw_data`、字段目录、标准事实层 |
| 数据过期提醒 | 超过 24 小时未更新，不继续当新数据使用 | `freshness=stale`、页面过期提醒 |
| 多账号/POI 承接 | 一个物理门店可存在多个平台来源 | 先用来源数/可用来源数展示，后续再做主账号切换 |

## 不沉淀为实现细节的内容

| 风险项 | 宿析 OS 处理方式 |
| --- | --- |
| 验证码、短信、人机验证 | 标记为 `manual_intervention_required`，提示人工处理 |
| 平台频控、权限限制、风控拦截 | 记录失败原因，不绕过，不伪造成功 |
| 非授权门店或非当前账号可见数据 | 不采集，不入库 |
| 客人手机号、证件号、姓名等隐私 | 不进入本轮范围，不写采集逻辑，不进入普通日志或文档 |
| 房态、房源映射、客人订单隐私链路 | 不作为学习目标，不补自动化 |
| 页面菜单、按钮、导航文本 | 不当作业务数据，只作为触发或定位证据 |

## 采集方式选择

```text
已确认稳定接口
-> Cookie/API 后端直连
-> 保存字段来源、请求周期、响应口径

接口不确定或参数复杂
-> 临时使用授权浏览器会话监听 XHR/fetch
-> 只沉淀脱敏 URL、Payload 结构、字段含义

必须打开真实页面才触发数据
-> 门店独立 Profile + response 监听
-> 登录失效时进入人工处理，不尝试绕过验证
```

## 统一资源名

| 资源名 | 内部 `data_type` | 默认采集 | 边界 |
| --- | --- | --- | --- |
| `businessData` | `business` | 是 | 经营概况、收入、间夜、订单聚合 |
| `peerRank` | `peer_rank` | 是 | 竞对榜单、排名、平台标签；不从订单或房态推断 |
| `flowData` | `traffic` | 是 | 曝光、浏览、转化、漏斗 |
| `searchKeywords` | `search_keyword` | 是 | 搜索词、曝光/点击/转化聚合 |
| `reviewData` | `review` | 否 | 默认只做评分、数量、回复率等聚合；点评明文需显式授权 |
| `roomTypes` | `room_type` | 是 | 仅房型目录和产品信息；不做房态或房源映射 |

## 状态契约

| 层级 | 推荐状态 | 含义 |
| --- | --- | --- |
| 账号 | `authorized` | 数据源已绑定且登录态可用 |
| 账号 | `login_required` | 登录态失效，需要重新登录 |
| 账号 | `manual_intervention_required` | 触发验证码、短信或平台人工校验 |
| 采集 | `ready_to_sync` | 有数据源，可发起同步 |
| 采集 | `collecting` | 后台任务执行中 |
| 采集 | `ready` | 最近一次采集有可用数据 |
| 采集 | `stale` | 超过 24 小时未更新 |
| 采集 | `failed` | 采集失败，必须保留原因 |
| ETL | `stored_displayable` | 已入库且页面可展示 |
| ETL | `capture_success_not_stored` | 采集成功但未入库 |
| ETL | `normalized_not_stored` | 已解析但未入库 |
| ETL | `not_stored` | 无可展示记录 |

## 当前已落地位置

| 层级 | 文件/接口 | 说明 |
| --- | --- | --- |
| 路由 | `route/app.php` | `GET /api/online-data/collection-resources` |
| 控制器 | `app/controller/OnlineData.php` | `collectionResourceCatalog()` |
| 服务 | `app/service/PlatformDataSyncService.php` | 资源目录、状态聚合、别名归一 |
| 美团适配器 | `app/service/platform/MeituanBrowserProfileDataSourceAdapter.php` | 统一资源 payload 映射 |
| 前端 | `public/index.html` | 采集资源状态矩阵 |
| 标准化脚本 | `scripts/lib/ota_capture_standard.mjs` | 资源名归一和响应分类 |
| 美团脚本 | `scripts/meituan_browser_capture.mjs` | 美团响应路径补充 |
| 测试 | `tests/PlatformDataSyncServiceTest.php` | 资源目录、别名、适配器映射 |
| 测试 | `tests/automation/ota_capture_standard.test.mjs` | 美团资源名和榜单 URL 分类 |

## 后续可继续补强

| 优先级 | 事项 | 说明 |
| --- | --- | --- |
| P0 | 账号绑定体验统一 | 门店管理里继续弱化 Cookie 表单，强化平台账号动作 |
| P1 | 多账号/POI 主账号切换 | 当前先显示来源数，后续补主账号、切换查看、禁用来源 |
| P1 | 全周期采集任务 | 榜单类支持实时、昨天、近 7 天、近 30 天一次任务化采集 |
| P1 | 任务日志中心 | 用资源名、平台、门店、开始/完成时间、失败原因统一展示 |
| P2 | 字段证据面板 | 页面上显示字段来源、周期、平台、更新时间、缺失原因 |
| P2 | 采集失败样本库 | 把登录失效、字段缺失、接口改版、ETL 未入库沉淀为评估样本 |

