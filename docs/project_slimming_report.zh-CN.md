# 项目瘦身报告

更新日期：2026-06-11

范围：本报告只处理本地运行产物、测试产物、可再生成缓存和自净化审计；不删除业务代码、验收文档、`.git` 历史、依赖锁文件或数据库备份。

当前执行状态：默认瘦身脚本已可执行；只读自净化审计脚本已覆盖项目体积、代码行数、可清理产物、Git 状态、跟踪代码热点和拆分候选。

## 当前体积判断

| 项目 | 体积 | 处理策略 |
|---|---:|---|
| 完整 `HOTEL/` 目录 | 约 291.69 MB | 包含 `.git`、依赖、本地报告和项目代码；会随本地 Git 对象轻微波动。 |
| 不含 `.git` | 约 92.58 MB | 更接近工作副本体积。 |
| 不含 `.git`、`node_modules/`、`vendor/` | 约 63.39 MB | 更接近业务与资料体积。 |
| Git 跟踪文件 | 约 18.46 MB / 613 个文件 | 这是代码提交体积口径。 |
| `reports/` | 约 1.14 MB | 大型可再生成采集产物已清理；剩余报告文件默认保留。 |
| `node_modules/`、`vendor/` | 约 29.19 MB | 默认不清理；需要重新安装依赖时可显式清理。 |

## 自净化命令

| 命令 | 作用 |
|---|---|
| `npm run self:audit` | 只读输出项目体积、Git 状态、代码行数、可清理目标和大文件清单。 |
| `npm run self:audit:json` | 输出机器可读 JSON，供后续自动化或报告引用。 |
| `npm run self:split-map` | 只读输出剩余拆分候选的页面/方法/领域分布和最大块。 |
| `npm run self:split-map:json` | 输出机器可读拆分地图，供后续自动化或拆分计划引用。 |
| `npm run self:check` | 自审计 + P0 guard；当默认可清理产物超过阈值时失败。 |
| `npm run self:check:strict` | 严格自审计 + P0 guard；除默认可清理产物外，还会在跟踪代码拆分候选存在时失败，用于重构收口阶段。 |
| `npm run self:clean:dry-run` | `slim:local:dry-run` 的语义化别名。 |
| `npm run self:clean` | `slim:local` 的语义化别名。 |
| `npm run slim:local:dry-run` | 只列出可清理目标和预计释放空间，不删除文件。 |
| `npm run slim:local` | 清理默认本地运行产物。 |
| `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/clean_project_local_artifacts.ps1 -Apply -IncludeDependencies` | 额外清理 `node_modules/` 和 `vendor/`，需要后续重新安装依赖。 |
| `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/clean_project_local_artifacts.ps1 -Apply -IncludeSensitiveBackups` | 额外清理 `database/backups/`；仅在完成凭据轮换/备份处置授权后使用。 |

## 默认清理清单

- `output/`
- `runtime/`
- `test-results/`
- `.pytest_cache/`
- `.gstack/`
- `storage/ctrip_profile_*`
- `storage/meituan_profile_*`
- `storage/*.log`

## 默认不清理但持续观察

- `reports/`：包含采集目录、审计结果和证据文件。只清理明确可再生成的大型 raw capture 或 assets。
- `.git/`：属于版本历史，不纳入普通瘦身。
- `.agents/`：项目本地 Skill 和工具资产，不纳入普通瘦身。
- `node_modules/`、`vendor/`：可再安装，但默认保留以保证本地验证效率。
- 数据库 dump / backup：默认保留；清理前必须先确认安全和恢复边界。

## 不自动清理

- `app/`
- `public/index.html`
- `route/`
- `config/`
- `tests/`
- `docs/`
- `.git/`
- `database/backups/`
- `node_modules/`
- `vendor/`

## 风险说明

- 清理 OTA 浏览器 profile 会释放空间，但会丢失本地登录态，需要重新登录平台。
- 清理依赖目录后必须重新执行 `npm ci` 和 `composer install`。
- 清理 `database/backups/` 前必须先完成凭据轮换或确认备份内容可删除。

## 2026-06-10 执行结果

| 命令 | 结果 |
|---|---|
| `npm run slim:local:dry-run` | 通过，清理前识别 22 个本地产物目标，预计可释放约 485.94 MB。 |
| `npm run slim:local` | 通过，删除 22 个本地产物目标。 |
| `npm run slim:local:dry-run` | 清理后通过，`Target count: 0`、`Estimated reclaim: 0 MB`。 |
| `npm run self:audit` | 通过，显示完整目录约 381.42 MB、Git 跟踪文件约 17.73 MB、默认可清理目标约 0 MB。 |
| `npm run verify:p0-guards` | 通过。 |
| `npm run verify:e2e-contracts` | 通过。 |
| `npm run review:non-security` | 通过。 |

## 2026-06-10 报告产物治理补充

| 命令 | 结果 |
|---|---|
| `npm run self:audit` | 识别 `reports/ctrip_capture_assets/` 和忽略的 raw capture JSON 为默认清理候选，预计可释放约 153.08 MB。 |
| `npm run self:clean` | 通过，删除 4 个本地报告产物目标：`reports/ctrip_capture_assets/`、2 个 `reports/ctrip_browser_capture_*.json`、`runtime/`。 |
| `npm run self:audit` | 清理后完整目录约 228.37 MB，不含 `.git` 约 91.86 MB，不含 `.git` 和依赖约 62.67 MB，`reports/` 降至约 1.14 MB。 |
| `npm run self:check` | 通过，默认可清理目标为 0 MB，P0 guard 通过。 |

## 代码行数口径

`npm run self:audit` 当前只统计 Git 跟踪的项目代码文件，不把 `node_modules/`、`vendor/` 和本地运行产物算作项目代码。

| 口径 | 当前值 |
|---|---:|
| 代码文件 | 370 |
| 总代码行 | 约 193,633 |
| 非空代码行 | 约 177,788 |

## 跟踪代码热点

`npm run self:audit` 已新增跟踪代码热点、拆分候选和已接受候选输出。当前拆分候选不是自动删除清单，只用于后续架构瘦身排序。

| 文件 | 行数 | 体积 | 当前本地改动 | 判断 |
|---|---:|---:|---|---|
| `public/index.html` | 36,726 | 2.72 MB | 本轮拆分中 | 当前前端 SPA 主入口；已先抽出扩张/市场测算静态选项数据到 `public/expansion-static-options.js`，抽出酒店图片优化/AI 工具箱静态选项到 `public/hotel-image-optimizer-static.js`，抽出收益研究中心静态产品清单到 `public/revenue-research-static.js`，抽出自动采集静态配置与手动触发自动采集流程到 `public/auto-fetch-static.js`，抽出门店罗盘静态配置到 `public/compass-static.js`，抽出模拟测算/转让字段静态配置到 `public/simulation-static.js`，抽出运营/开业静态选项到 `public/operation-static.js`，继续把门店罗盘宏观信号文案归入 `public/compass-static.js`，抽出携程字段/Profile/概览接口静态配置和携程概览/流量概要/广告采集流程到 `public/ctrip-static.js`，抽出系统/AI/知识库静态配置、AI 模型配置 I18N、语言选项和导航菜单定义到 `public/system-static.js`，抽出前端复用 Vue 组件到 `public/shared-components.js`，抽出全局通知展示工具到 `public/notification-static.js`，抽出美团榜单展示工具和美团竞对摘要卡片构建器到 `public/meituan-static.js`，扩展 `public/data-health-static.js` 承载数据健康展示、失败原因排名和今日待办构建工具，新增 `public/home-static.js` 承载首页闭环与 AI 轨迹展示构建器，新增 `public/ota-diagnosis-static.js` 承载 OTA 诊断结果展示构建器，并持续收口携程、美团、OTA AI 运行态数据整形、分组状态更新、携程 Profile 重抓上下文、携程浏览器采集请求上下文、携程普通榜单采集请求上下文与采集流程、携程流量采集流程、携程配置保存流程、美团批量榜单采集流程、美团流量/订单/广告采集流程、美团浏览器采集流程、美团手动采集 JSON 保存流程、美团 AI 分析启动流程、转让决策层级行构建器、OTA AI 分析启动/汇总上下文与流程编排、携程 Cookie API 采集流程、携程 Profile 字段表单默认值/智能推断/保存 payload 与校验工具、携程 Profile 重抓流程、OTA 诊断补抓与生成流程、携程浏览器采集流程；后续继续按页面或面板拆分，同时保持 Vue CDN 运行契约。 |
| `app/controller/OnlineData.php` | 26,725 | 1.14 MB | 本轮未改动 | OTA 采集、字段配置、展示和诊断职责仍过重；已先抽出携程字段静态元数据、关键字段清单、默认采集字段行、流量漏斗/周报/竞争圈画像元数据、Ctrip overview 汇总逻辑、在线数据分析报告渲染逻辑和平台 Profile 绑定检查逻辑，并删除已被禁用响应短路的携程/美团点评旧直连、旧浏览器抓取、旧配置读写和旧自动抓取执行死代码；后续继续迁移到聚焦 service，不改变现有路由。 |

前一轮 10 个业务改动文件已单独保存并推送；当前自净化拆分集中在 `app/controller/OnlineData.php` 后端瘦身与 `public/index.html` 前端静态配置拆分，均保持现有路由、接口和 Vue CDN 运行契约。

## 已接受拆分候选

`docs/self_cleaning_split_dispositions.json` 用于记录有证据保留的非业务代码大文件。已接受项不会触发 `self:check:strict`，但仍会在审计输出中显示。

| 文件 | 行数 | 体积 | 处置 |
|---|---:|---:|---|
| `public/tailwind.min.css` | 1 | 2.80 MB | 当前 `public/index.html` 直接加载的本地 Tailwind CSS 静态依赖，不作为业务代码拆分目标；前端拆分或迁移后复查。 |

`npm run self:check` 保持日常可通过，只把默认可清理产物超过阈值视为失败；`npm run self:check:strict` 用于核心大文件拆分阶段，当前预计会因为 `public/index.html` 和 `app/controller/OnlineData.php` 两个拆分候选失败。

## 拆分地图

`npm run self:split-map` 当前只读分析剩余两个严格候选，不修改业务文件。当前 `public/index.html` 正在本轮前端静态配置拆分中；`app/controller/OnlineData.php` 本轮未改动。

| 文件 | 结构信号 | 最大拆分起点 | 领域分布信号 |
|---|---:|---|---|
| `public/index.html` | 1,395 个函数级块；44 个 `currentPage` 引用 | `importKnowledgeUnits` 68 行、`openSystemConfigModal` 68 行、`testDataConfig` 66 行、`platformAccountBindingGuideRows` 61 行、`getDataConfigTypeDefaults` 61 行、`startAiAnalysis` 61 行 | `general` 7,111 行、`ctrip` 2,819 行、`hotel_admin` 1,289 行、`ai` 1,160 行、`meituan` 876 行、`transfer` 244 行、`ota` 384 行 |
| `app/controller/OnlineData.php` | 871 个方法 | `captureMeituanBrowserData` 274 行、`captureCtripBrowserData` 272 行、`parseAndSaveMeituanData` 237 行 | `ctrip` 11,463 行、`meituan` 4,979 行、`general` 4,478 行、`auto_fetch` 1,838 行、`profile` 941 行 |

## 2026-06-10 后端第一刀拆分

- 新增 `app/service/CtripProfileFieldMetaService.php`，承载携程字段基础静态元数据、关键字段 key 清单、字段元数据刷新 key 清单、默认采集字段行、流量漏斗元数据、周报元数据和竞争圈画像元数据。
- 新增 `app/service/CtripOverviewSummaryService.php`，承载 Ctrip overview 汇总逻辑；控制器保留 `summarizeCtripOverviewRows()` 薄包装以兼容现有调用和反射测试。
- `app/controller/OnlineData.php` 中 `defaultCtripProfileFieldMeta()` 保留编排入口，具体字段元数据改为调用 service。
- `OnlineData.php` 从 `31,140` 行降至 `28,485` 行；`CtripProfileFieldMetaService.php` 当前为 `2,415` 行，`CtripOverviewSummaryService.php` 当前为 `361` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter CtripProfile`、`tests\OnlineDataTest.php --filter CtripOverview`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run self:audit`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第二刀拆分

- 新增 `app/service/OnlineDataAnalysisReportService.php`，承载在线数据分析报告 HTML 渲染逻辑；`OnlineData.php` 保留 `generateAnalysisReport()` 薄包装以兼容现有内部调用。
- 新增 `tests/OnlineDataAnalysisReportServiceTest.php`，覆盖核心报告结构、酒店/指标渲染和建议块开关。
- `OnlineData.php` 从 `28,485` 行降至 `28,276` 行；拆分地图显示方法数从 `874` 降至 `873`，`analysis` 领域 span 从 `388` 行降至 `186` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 暂存态自审计：跟踪文件约 `17.78 MB` / `593` 个；代码范围 `350` 个文件，`186,325` 行，非空行 `170,620`。
- 验证通过：PHP 语法检查、`tests\OnlineDataAnalysisReportServiceTest.php`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第三刀拆分

- 新增 `app/service/PlatformProfileBindingReadinessService.php`，承载平台 Profile 绑定检查的 P0 readiness 状态生成逻辑。
- `OnlineData.php` 保留 `buildPlatformProfileBindingChecks()` 薄包装，兼容现有内部调用和反射测试；平台登录状态文本、缓存、数据源绑定等控制器逻辑未迁移。
- `OnlineData.php` 从 `28,276` 行降至 `28,119` 行；拆分地图显示 `profile` 领域 span 从 `1,099` 行降至 `941` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 暂存态自审计：跟踪文件约 `17.79 MB` / `594` 个；代码范围 `351` 个文件，`186,394` 行，非空行 `170,684`。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter PlatformProfile`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第四刀清理

- 删除 `fetchCtripComments()` 中已被 `commentCollectionDisabledResponse()` 短路的旧携程点评直连请求代码。
- 保留 `parseAndSaveCtripComments()` 及 Browser Profile 聚合保存调用点；评论数据仍维持 aggregate-only 存储边界，不恢复原始评论列表返回。
- `OnlineData.php` 从 `28,119` 行降至 `27,942` 行；拆分地图显示 `ctrip` 领域 span 从 `12,319` 行降至 `12,142` 行，最大块列表中不再出现 `fetchCtripComments`。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 暂存态自审计：跟踪文件约 `17.78 MB` / `594` 个；代码范围 `351` 个文件，`186,217` 行，非空行 `170,527`。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter "CtripComment|Comment|PlatformProfile"`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第五刀清理

- 删除 `fetchMeituanComments()` 中已被 `commentCollectionDisabledResponse()` 短路的旧美团点评直连请求代码。
- 删除 `captureCtripCommentsBrowserData()` 中已被 `commentCollectionDisabledResponse()` 短路的旧携程点评浏览器抓取代码。
- 保留 `parseAndSaveMeituanComments()`、`parseAndSaveCtripComments()` 及仍在 Browser Profile 聚合路径使用的调用点；评论数据仍维持 aggregate-only 存储边界，不恢复原始评论列表返回。
- `OnlineData.php` 从 `27,942` 行降至 `27,615` 行；拆分地图显示 `ctrip` 领域 span 从 `12,142` 行降至 `11,998` 行，`meituan` 领域 span 从 `5,307` 行降至 `5,124` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 暂存态自审计：跟踪文件约 `17.77 MB` / `594` 个；代码范围 `351` 个文件，`185,890` 行，非空行 `170,228`。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter "CtripComment|MeituanComment|Comment|PlatformProfile"`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第六刀清理

- 删除 `saveMeituanCommentConfig()` 和 `saveCtripCommentConfig()` 中已被 `commentCollectionDisabledResponse()` 短路的旧点评配置保存逻辑。
- 删除 `getMeituanCommentConfigList()` 和 `getCtripCommentConfigList()` 中固定返回空数组后的旧配置读取、排序和脱敏逻辑。
- 更新 `scripts/verify_high_risk_security.php`：不再要求不可达的旧点评配置字段绑定逻辑，改为验证禁用端点不保存、不暴露旧点评凭据。
- `OnlineData.php` 从 `27,615` 行降至 `27,498` 行；拆分地图显示 `ctrip` 领域 span 从 `11,998` 行降至 `11,940` 行，`meituan` 领域 span 从 `5,124` 行降至 `5,065` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 暂存态自审计：跟踪文件约 `17.77 MB` / `594` 个；代码范围 `351` 个文件，`185,777` 行，非空行 `170,132`。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter "Comment|Config|PlatformProfile"`、完整 `tests\OnlineDataTest.php`、`scripts\verify_high_risk_security.php`、`npm.cmd run review:non-security`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第七刀清理

- 删除旧的 `executeCtripCommentsAutoFetchTask()` 和 `executeMeituanCommentsAutoFetchTask()` 方法；这两个方法已先返回禁用状态，后续直连 OTA 点评请求代码不可达。
- `executeAutoFetchTask()` 对旧任务标签 `ctrip:comments` 和 `meituan:comments` 统一返回显式禁用结果，保留旧任务记录兼容，不恢复点评/评论采集。
- 更新 `scripts/verify_ota_diagnosis_auto_fetch.mjs`：要求旧点评自动抓取执行方法不存在，并要求旧任务标签仍返回 `Comment/review data collection is disabled by policy.`。
- `OnlineData.php` 从 `27,498` 行降至 `27,333` 行；方法数从 `873` 降至 `871`，`ctrip` 领域 span 从 `11,940` 行降至 `11,861` 行，`meituan` 领域 span 从 `5,065` 行降至 `4,979` 行。
- 当前审计：完整目录约 `232 MB`；不含 `.git` 约 `91.88 MB`；不含 `.git` 和依赖约 `62.69 MB`；Git 跟踪文件约 `17.77 MB` / `594` 个；代码范围 `351` 个文件、`185,616` 行、非空 `169,985` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变；点评/评论数据仍维持 aggregate-only 和禁用采集边界，不恢复原始评论列表或旧凭据保存。
- 验证通过：PHP 语法检查、Node 语法检查、`npm.cmd run verify:ota-diagnosis-auto-fetch`、`tests\OnlineDataTest.php --filter "AutoFetch|Comment|PlatformProfile"`、完整 `tests\OnlineDataTest.php`、`node scripts\verify_platform_data_source_contract.mjs`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第一刀拆分

- 新增 `public/expansion-static-options.js`，承载市场评估/扩张测算用的城市层级、城市列表、行政区、地址关键词、竞品数量、客群、装修和默认输入等静态选项数据。
- `public/index.html` 只保留 `window.SUXI_EXPANSION_STATIC` 读取和 Vue 状态绑定，不再内嵌该大段静态选项；入口脚本新增 `expansion-static-options.js`，保持当前 Vue CDN 运行方式。
- 更新 `scripts/verify_expansion_p2.mjs` 和 `scripts/verify_strategy_location_ui_contract.mjs`，前端合同验证改为同时检查入口文件和静态选项文件；动态地址候选 builder 仍要求留在入口中。
- `public/index.html` 从 `43,322` 行降至 `43,132` 行，体积从 `3.11 MB` 降至 `3.08 MB`；`config` 领域 span 从 `1,197` 行降至 `984` 行。
- 当前审计：完整目录约 `234 MB`；不含 `.git` 约 `91.88 MB`；不含 `.git` 和依赖约 `62.69 MB`；Git 跟踪文件约 `17.77 MB` / `595` 个；代码范围 `352` 个文件、`185,669` 行、非空 `170,036` 行。
- 验证通过：`node --check public\expansion-static-options.js`、`node --check scripts\verify_expansion_p2.mjs`、`node --check scripts\verify_strategy_location_ui_contract.mjs`、`node scripts\verify_expansion_p2.mjs`、`node scripts\verify_strategy_location_ui_contract.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:p0-guards`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二刀拆分

- 新增 `public/hotel-image-optimizer-static.js`，承载酒店 AI 工具箱入口、图片优化场景/目标/风格、提示模板、问题项和推荐工具等静态选项。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_HOTEL_IMAGE_OPTIMIZER_STATIC` 显式读取静态配置；缺少脚本或字段时直接抛出明确错误，不用空数组兜底掩盖配置缺失。
- `public/index.html` 从 `43,132` 行降至 `42,907` 行，体积从 `3.08 MB` 降至 `3.06 MB`；`ota` 领域 span 从 `1,010` 行降至 `754` 行。
- 当前审计：完整目录约 `234 MB`；不含 `.git` 约 `91.89 MB`；不含 `.git` 和依赖约 `62.7 MB`；Git 跟踪文件约 `17.77 MB` / `596` 个；代码范围 `353` 个文件、`185,700` 行、非空 `170,066` 行。
- 验证通过：`node --check public\hotel-image-optimizer-static.js`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三刀拆分

- 新增 `public/revenue-research-static.js`，承载酒店收益管理研究中心产品清单和运行步骤静态配置。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_REVENUE_RESEARCH_STATIC` 显式读取收益研究静态配置；缺少脚本或字段时直接抛出明确错误，不用空数组兜底。
- 更新 `scripts/verify_e2e_contracts.mjs`，收益研究 `service-quality` / `review-topic` 前端合同改为同时检查入口文件和收益研究静态配置文件；后端合同仍检查 `RevenueResearchService.php`。
- `public/index.html` 从 `42,907` 行降至 `42,830` 行；`hotel_admin` 领域 span 从 `1,601` 行降至 `1,515` 行。
- 当前审计：完整目录约 `235 MB`；不含 `.git` 约 `91.89 MB`；不含 `.git` 和依赖约 `62.7 MB`；Git 跟踪文件约 `17.78 MB` / `597` 个；代码范围 `354` 个文件、`185,741` 行、非空 `170,104` 行。
- 验证通过：`node --check public\revenue-research-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第四刀拆分

- 新增 `public/auto-fetch-static.js`，承载平台自动采集模式选项、采集蓝图行和 OTA 字段范围分组。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_AUTO_FETCH_STATIC` 显式读取自动采集静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组或默认值兜底。
- `public/index.html` 从 `42,830` 行降至 `42,782` 行；`general` 领域 span 从 `11,069` 行降至 `11,020` 行。
- 当前审计：完整目录约 `236 MB`；不含 `.git` 约 `91.89 MB`；不含 `.git` 和依赖约 `62.7 MB`；Git 跟踪文件约 `17.78 MB` / `598` 个；代码范围 `355` 个文件、`185,764` 行、非空 `170,126` 行。
- 验证通过：`node --check public\auto-fetch-static.js`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第五刀拆分

- 新增 `public/compass-static.js`，承载门店罗盘首页趋势选项、趋势默认卡片、每日运营动作、复盘步骤、天气城市清单和快捷入口定义。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_COMPASS_STATIC` 显式读取门店罗盘静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组或默认值兜底。
- `public/index.html` 从 `42,782` 行降至 `42,744` 行，体积从 `3.06 MB` 降至 `3.05 MB`；`general` 领域 span 从 `11,020` 行降至 `11,008` 行，`ai` 领域 span 从 `1,543` 行降至 `1,516` 行。
- 当前审计：完整目录约 `236 MB`；不含 `.git` 约 `91.90 MB`；不含 `.git` 和依赖约 `62.71 MB`；Git 跟踪文件约 `17.78 MB` / `599` 个；代码范围 `356` 个文件、`185,849` 行、非空 `170,210` 行。
- 验证通过：`node --check public\compass-static.js`、`git diff --cached --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第六刀拆分

- 新增 `public/simulation-static.js`，承载模拟测算默认输入、竞品模型字段、协同状态选项、扩张记录页面映射、转让决策字段和模拟测算字段分组。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_SIMULATION_STATIC` 显式读取模拟测算静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组或默认值兜底。
- `public/index.html` 从 `42,744` 行降至 `42,521` 行，体积从 `3.05 MB` 降至 `3.04 MB`；`general` 领域 span 从 `11,008` 行降至 `10,842` 行，`config` 领域 span 从 `984` 行降至 `897` 行。
- 当前审计：完整目录约 `237 MB`；不含 `.git` 约 `91.90 MB`；不含 `.git` 和依赖约 `62.71 MB`；Git 跟踪文件约 `17.79 MB` / `600` 个；代码范围 `357` 个文件、`185,810` 行、非空 `170,171` 行。
- 验证通过：`node --check public\simulation-static.js`、`git diff --cached --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第七刀拆分

- 新增 `public/operation-static.js`，承载生命周期指标标题、生命周期阶段标题、运营预警筛选项、运营策略类型、开业任务分类、开业状态选项和进度快捷值。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_OPERATION_STATIC` 显式读取运营管理静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组或默认值兜底。
- `public/index.html` 从 `42,521` 行降至 `42,493` 行；`general` 领域 span 从 `10,842` 行降至 `10,809` 行。
- 当前审计：完整目录约 `237 MB`；不含 `.git` 约 `91.90 MB`；不含 `.git` 和依赖约 `62.71 MB`；Git 跟踪文件约 `17.79 MB` / `601` 个；代码范围 `358` 个文件、`185,852` 行、非空 `170,212` 行。
- 验证通过：`node --check public\operation-static.js`、`git diff --cached --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第八刀拆分

- 扩展 `public/compass-static.js`，承载宏观信号解释文案、市场预估说明文案和美团默认榜单类型。
- `public/index.html` 改为通过既有 `window.SUXI_COMPASS_STATIC` 读取这些门店罗盘静态配置；缺少字段时继续抛出明确配置错误，不用空数组或默认值兜底。
- `public/index.html` 从 `42,493` 行降至 `42,453` 行；`general` 领域 span 从 `10,809` 行降至 `10,769` 行。
- 当前审计：完整目录约 `238 MB`；不含 `.git` 约 `91.91 MB`；不含 `.git` 和依赖约 `62.72 MB`；Git 跟踪文件约 `17.79 MB` / `601` 个；代码范围 `358` 个文件、`185,858` 行、非空 `170,218` 行。
- 验证通过：`node --check public\compass-static.js`、`git diff --cached --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第九刀拆分

- 新增 `public/ctrip-static.js`，承载携程 Profile 字段模块、禁止采集字段边界、携程概览接口关键词、流量概览接口分组和默认请求 URL。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_CTRIP_STATIC` 显式读取携程静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组、默认 URL 或静默兜底掩盖配置缺失。
- `public/index.html` 从 `42,453` 行降至 `42,404` 行；`ctrip` 领域 span 从 `3,909` 行降至 `3,877` 行，`general` 领域 span 从 `10,769` 行降至 `10,751` 行。
- 当前审计：完整目录约 `238.66 MB`；不含 `.git` 约 `91.91 MB`；不含 `.git` 和依赖约 `62.72 MB`；Git 跟踪文件约 `17.80 MB` / `602` 个；代码范围 `359` 个文件、`185,888` 行、非空 `170,247` 行。
- 验证通过：`node --check public\ctrip-static.js`、残留内联静态定义扫描、`git diff --check`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run verify:p0-guards`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十刀拆分

- 新增 `public/system-static.js`，承载测试 ID 名称映射、酒店/用户表格列、知识库来源和导入模式、AI 快速配置、AI 治理 tab、数据配置 profile、知识库文档扩展名和 Agent tab。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_SYSTEM_STATIC` 显式读取系统静态配置；缺少脚本或字段时直接抛出明确配置错误，不用空数组或默认配置掩盖缺失。
- `public/index.html` 从 `42,404` 行降至 `42,241` 行；`general` 领域 span 从 `10,751` 行降至 `10,586` 行。
- 当前审计：完整目录约 `239.26 MB`；不含 `.git` 约 `91.91 MB`；不含 `.git` 和依赖约 `62.72 MB`；Git 跟踪文件约 `17.80 MB` / `603` 个；代码范围 `360` 个文件、`185,930` 行、非空 `170,289` 行。
- 验证通过：`node --check public\system-static.js`、残留内联静态定义扫描、`git diff --cached --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十一刀拆分

- 扩展 `public/system-static.js`，承载 AI 模型配置 I18N 文案和语言选项。
- `public/index.html` 改为通过 `window.SUXI_SYSTEM_STATIC` 显式读取 `aiModelConfigI18n` 与 `languageOptions`；缺少脚本或字段时直接抛出明确配置错误，不用空对象、空数组或默认文案兜底。
- 更新 `scripts/verify_ai_model_config_i18n.mjs`，I18N 合同校验同时读取 `public/index.html` 和 `public/system-static.js`，避免静态配置拆分后误报缺失。
- `public/index.html` 从 `42,241` 行降至 `42,101` 行；当前 `public/system-static.js` 为 `359` 行。
- 当前审计：完整目录约 `239.29 MB`；不含 `.git` 约 `91.92 MB`；不含 `.git` 和依赖约 `62.73 MB`；Git 跟踪文件约 `17.80 MB` / `603` 个；代码范围 `360` 个文件、`185,946` 行、非空 `170,305` 行。
- 验证通过：`node --check public\system-static.js`、`node --check scripts\verify_ai_model_config_i18n.mjs`、`node scripts\verify_ai_model_config_i18n.mjs`、残留内联静态定义扫描、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十二刀拆分

- 新增 `public/shared-components.js`，承载 `CompassCardHeader`、`MetricCard`、`SearchInput`、`StatusFilter`、`StatusBadge`、`RoleBadge`、`ActionButtons` 和 `DataTable` 8 个前端复用 Vue 组件。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_SHARED_COMPONENTS` 显式读取组件；缺少脚本或组件 key 时直接抛出明确错误，不静默兜底。
- `public/index.html` 从 `42,101` 行降至 `41,996` 行；当前 `public/shared-components.js` 为 `136` 行。
- 当前审计：完整目录约 `240.43 MB`；不含 `.git` 约 `91.92 MB`；不含 `.git` 和依赖约 `62.73 MB`；Git 跟踪文件约 `17.80 MB` / `604` 个；代码范围 `361` 个文件、`185,977` 行、非空 `170,335` 行。
- 验证通过：`node --check public\shared-components.js`、残留内联组件定义扫描、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、串行补跑 `npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十三刀拆分

- 扩展 `public/system-static.js`，承载主导航菜单定义。
- `public/index.html` 改为通过 `menuItemDefinitions` 生成菜单，仅保留递归注入 `systemConfig.menu_hotel_name` 的动态名称逻辑；缺少静态 key 时仍由 `requireAppSystemStatic` 直接报错。
- `public/index.html` 从 `41,996` 行降至 `41,899` 行；当前 `public/system-static.js` 为 `469` 行。
- 当前审计：完整目录约 `240.46 MB`；不含 `.git` 约 `91.92 MB`；不含 `.git` 和依赖约 `62.73 MB`；Git 跟踪文件约 `17.81 MB` / `604` 个；代码范围 `361` 个文件、`185,990` 行、非空 `170,348` 行。
- 验证通过：`node --check public\system-static.js`、残留内联菜单定义扫描、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十四刀拆分

- 新增 `public/notification-static.js`，承载全局通知文本脱敏、优先级映射、徽标样式、动作目标、时间格式和后端通知归一化工具。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_NOTIFICATION_STATIC` 显式读取通知工具；缺少脚本时直接抛出明确错误，不静默兜底。
- `public/index.html` 从 `41,899` 行降至 `41,837` 行；当前 `public/notification-static.js` 为 `96` 行；当前拆分地图报告 `1,166` 个前端函数级块和 `44` 个 `currentPage` 引用。
- 当前审计：完整目录约 `241.64 MB`；不含 `.git` 约 `91.93 MB`；不含 `.git` 和依赖约 `62.74 MB`；Git 跟踪文件约 `17.81 MB` / `605` 个；代码范围 `362` 个文件、`186,024` 行、非空 `170,374` 行。
- 验证通过：`node --check public\notification-static.js`、残留通知工具定义扫描、`git diff --check`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十五刀拆分

- 新增 `public/meituan-static.js`，承载美团榜单指标标签、排序值、差距格式化、展示行 key 和榜单展示行构建工具。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_MEITUAN_STATIC` 显式读取美团展示工具；缺少脚本或函数 key 时直接抛出明确错误，不静默兜底。
- `public/index.html` 从 `41,837` 行降至 `41,781` 行；当前 `public/meituan-static.js` 为 `84` 行；当前拆分地图报告 `1,162` 个前端函数级块和 `44` 个 `currentPage` 引用，`meituan` 领域 span 降至 `1,369` 行。
- 当前审计：完整目录约 `242.26 MB`；不含 `.git` 约 `91.93 MB`；不含 `.git` 和依赖约 `62.74 MB`；Git 跟踪文件约 `17.82 MB` / `606` 个；代码范围 `363` 个文件、`186,052` 行、非空 `170,397` 行。
- 验证通过：`node --check public\meituan-static.js`、残留美团展示工具定义扫描、`git diff --check`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十六刀拆分

- 新增 `public/data-health-static.js`，承载在线数据质量状态文案/样式、提示列表、质量摘要范围文案、自动采集记录状态样式、授权健康灯样式/文案、数据健康状态归一化、优先级文案/样式和 OTA 平台名称展示工具。
- `public/index.html` 新增脚本引用，并通过 `window.SUXI_DATA_HEALTH_STATIC` 显式读取数据健康展示工具；缺少脚本或函数 key 时直接抛出明确错误，不静默兜底。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，让拆分后的证据源分别读取 `public/data-health-static.js` 和既有 `public/ctrip-static.js`，避免继续从主入口查找已拆分静态常量。
- `public/index.html` 从 `41,781` 行降至 `41,740` 行；当前 `public/data-health-static.js` 为 `90` 行；当前拆分地图报告 `1,152` 个前端函数级块和 `44` 个 `currentPage` 引用。
- 当前审计：完整目录约 `242.84 MB`；不含 `.git` 约 `91.93 MB`；不含 `.git` 和依赖约 `62.74 MB`；Git 跟踪文件约 `17.82 MB` / `607` 个；代码范围 `364` 个文件、`186,103` 行、非空 `170,437` 行。
- 验证通过：`node --check public\data-health-static.js`、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、残留数据健康展示工具定义扫描、`git diff --check`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十七刀拆分

- 扩展 `public/data-health-static.js`，承载数据健康失败原因排名构建器和今日待办行构建器。
- `public/index.html` 只保留 `collectionHealthFailureReasonRanking` 和 `dataHealthTodayWorkOrders` 两个 computed 绑定，具体合并、去重、排序和默认展示文案转移到 `window.SUXI_DATA_HEALTH_STATIC`。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，要求这两个构建器存在于 `public/data-health-static.js`，同时入口通过 `requireDataHealthStatic()` 显式读取。
- `public/index.html` 从 `41,740` 行降至 `41,657` 行；当前 `public/data-health-static.js` 为 `178` 行；当前拆分地图报告 `1,151` 个前端函数级块和 `44` 个 `currentPage` 引用，`general` 领域 span 降至 `10,259` 行。
- 当前审计：完整目录约 `242.88 MB`；不含 `.git` 约 `91.94 MB`；不含 `.git` 和依赖约 `62.75 MB`；Git 跟踪文件约 `17.82 MB` / `607` 个；代码范围 `364` 个文件、`186,126` 行、非空 `170,458` 行。
- 验证通过：`node --check public\data-health-static.js`、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、残留数据健康构建器扫描、`git diff --check`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十八刀拆分

- 扩展 `public/meituan-static.js`，承载 `buildCompetitorSummaryCoreCards` 和 `buildHomeCompetitorSummaryCards` 两个美团竞对摘要卡片构建器。
- `public/index.html` 只保留 `competitorSummaryCoreCards` 包装函数和 `homeCompetitorSummaryCards` computed 绑定，卡片标签、缺失状态文案和入口参数转移到 `window.SUXI_MEITUAN_STATIC`。
- 更新 `scripts/verify_p0_learning_contract.mjs`，让美团竞对摘要文案同时读取 `public/index.html` 和 `public/meituan-static.js`，并把平台 Profile 绑定检查证据扩展到 `app/service/PlatformProfileBindingReadinessService.php`。
- `public/index.html` 从 `41,657` 行降至 `41,586` 行；当前 `public/meituan-static.js` 为 `181` 行；当前拆分地图报告 `1,151` 个前端函数级块和 `44` 个 `currentPage` 引用，`general` 领域 span 降至 `10,186` 行，`meituan` 领域 span 为 `1,371` 行。
- 当前审计：完整目录约 `243.47 MB`；不含 `.git` 约 `91.94 MB`；不含 `.git` 和依赖约 `62.75 MB`；Git 跟踪文件约 `17.83 MB` / `607` 个；代码范围 `364` 个文件、`186,165` 行、非空 `170,495` 行。
- 验证通过：`node --check public\meituan-static.js`、`node --check scripts\verify_p0_learning_contract.mjs`、`npm.cmd run verify:p0-learning`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第十九刀拆分

- 新增 `public/home-static.js`，承载 `buildHomeClosedLoopStages` 和 `buildHomeAiTraceRows` 两个首页展示构建器。
- `public/index.html` 只保留 Vue computed 绑定和运行态输入，首页闭环卡片、AI 轨迹卡片、缺失状态文案和入口跳转 payload 转移到 `window.SUXI_HOME_STATIC`；缺少脚本或 key 时直接抛出明确错误。
- 更新 `scripts/verify_home_visual_hierarchy_contract.mjs`，让首页闭环文案和产品链路证据读取 `public/home-static.js`，同时要求入口显式加载并读取两个构建器。
- `public/index.html` 从 `41,586` 行降至 `41,527` 行；当前 `public/home-static.js` 为 `122` 行；当前拆分地图报告 `1,152` 个前端函数级块和 `44` 个 `currentPage` 引用，`general` 领域 span 降至 `10,126` 行。
- 当前审计：完整目录约 `244.06 MB`；不含 `.git` 约 `91.95 MB`；不含 `.git` 和依赖约 `62.76 MB`；Git 跟踪文件约 `17.83 MB` / `608` 个；代码范围 `365` 个文件、`186,245` 行、非空 `170,572` 行。
- 验证通过：`node --check public\home-static.js`、`node --check scripts\verify_home_visual_hierarchy_contract.mjs`、`npm.cmd run verify:home-visual-hierarchy`、`npm.cmd run verify:public-entry`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十刀拆分

- 新增 `public/ota-diagnosis-static.js`，承载 `normalizeOtaDiagnosisList`、OTA 诊断优先级、日期范围、指标卡和诊断分组展示构建器。
- `public/index.html` 只保留 computed 绑定和运行态输入；OTA 诊断结果展示文案、图标、分组映射和空状态文案转移到 `window.SUXI_OTA_DIAGNOSIS_STATIC`。本轮不移动 `runOtaDiagnosisHotelFetch`，不改采集、Cookie/Profile 检查、接口调用或入库链路。
- 更新 `scripts/verify_e2e_contracts.mjs`，让 OTA 诊断 UI 合同读取 `public/index.html` 和 `public/ota-diagnosis-static.js`；更新 `scripts/verify_ota_diagnosis_auto_fetch.mjs`，让携程 overview 静态接口名证据读取 `public/ctrip-static.js`，避免前序静态拆分后误报。
- `public/index.html` 从 `41,527` 行降至 `41,467` 行；当前 `public/ota-diagnosis-static.js` 为 `94` 行；当前拆分地图报告 `1,151` 个前端函数级块和 `44` 个 `currentPage` 引用，`ota` 领域 span 降至 `693` 行。
- 当前审计：完整目录约 `244.65 MB`；不含 `.git` 约 `91.96 MB`；不含 `.git` 和依赖约 `62.77 MB`；Git 跟踪文件约 `17.84 MB` / `609` 个；代码范围 `366` 个文件、`186,288` 行、非空 `170,608` 行。
- 验证通过：`node --check public\ota-diagnosis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check scripts\verify_ota_diagnosis_auto_fetch.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:ota-diagnosis-auto-fetch`、`npm.cmd run verify:public-entry`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十一刀拆分

- 扩展 `public/simulation-static.js`，承载模拟测算的纯函数：`simulationGroupTotal`、收入/成本摘要构建、风险提示构建、模型分析归一化、输入兼容归一化和输入校验。
- `public/index.html` 通过 `window.SUXI_SIMULATION_STATIC` 显式读取这些函数；缺少脚本或 key 时仍由 `requireSimulationStatic()` 直接抛出明确配置错误，不用空函数或默认成功掩盖缺失。
- 本轮不移动 `handleSimulation` 的请求、保存、历史加载、localStorage 状态读写和 Vue ref 绑定，避免触碰量化模拟接口与运行态链路。
- `public/index.html` 从 `41,467` 行降至 `41,182` 行，体积从 `2.98 MB` 降至 `2.96 MB`；`public/simulation-static.js` 扩展为 `444` 行；拆分地图中 `simulation` 领域 span 从 `616` 行降至 `380` 行。
- 当前审计：完整目录约 `245.23 MB`；不含 `.git` 约 `91.95 MB`；不含 `.git` 和依赖约 `62.76 MB`；Git 跟踪文件约 `17.84 MB` / `609` 个；代码范围 `366` 个文件、`186,265` 行、非空 `170,593` 行。
- 验证通过：`node --check public\simulation-static.js`、静态导出 smoke 检查、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`node scripts\verify_simulation_p2.mjs`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十二刀拆分

- 扩展 `public/simulation-static.js`，承载协同提效默认任务构建器 `buildCollaborationTasks`；`public/index.html` 仅保留 `ref()` 绑定和显式读取，不移动协同提效接口调用、历史复用或运行态结果。
- 扩展 `public/expansion-static-options.js`，承载市场评估/战略选址纯 helper：城市层级识别、城市行政区选项、地址关键词选项、已知地址关键词判断和 `normalizeMarketEvaluationForm`。
- `public/index.html` 通过 `window.SUXI_EXPANSION_STATIC` 和 `window.SUXI_SIMULATION_STATIC` 显式读取上述函数；缺少脚本或 key 时继续直接抛出明确配置错误，不用空函数、空数组或默认成功掩盖缺失。
- `public/index.html` 从 `41,182` 行降至 `41,127` 行；拆分地图中前端函数级块从 `1,133` 降至 `1,126`，`general` 领域 span 从 `10,111` 行降至 `9,866` 行。
- 当前 `public/expansion-static-options.js` 为 `287` 行，`public/simulation-static.js` 为 `452` 行。
- 当前审计：完整目录约 `245.82 MB`；不含 `.git` 约 `91.96 MB`；不含 `.git` 和依赖约 `62.77 MB`；Git 跟踪文件约 `17.85 MB` / `609` 个；代码范围 `366` 个文件、`186,284` 行、非空 `170,612` 行。
- 验证通过：`node --check public\expansion-static-options.js`、`node --check public\simulation-static.js`、两个静态导出 smoke 检查、`npm.cmd run verify:public-entry`、`node scripts\verify_expansion_p2.mjs`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十三刀拆分

- 扩展 `public/data-health-static.js`，承载数据健康诊断边界、授权提醒行、质量任务行、后台高风险动作行、公共端点安全摘要和公共端点文本格式化等纯展示 builder。
- `public/index.html` 仅保留 computed 绑定和运行态输入，不移动 OTA 采集、Cookie/Profile 检查、接口调用、入库、数据源诊断加载或高风险动作拉取。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，要求新增数据健康 builder 留在 `public/data-health-static.js`，并要求入口通过 `requireDataHealthStatic()` 显式读取。
- `public/index.html` 从 `41,127` 行降至 `40,976` 行；拆分地图中前端函数级块从 `1,126` 降至 `1,124`，`general` 领域 span 从 `9,866` 行降至 `9,715` 行。
- 当前 `public/data-health-static.js` 为 `418` 行；总代码行数因静态模块和测试契约扩展增加到 `186,363` 行，非空 `170,682` 行。
- 当前审计：完整目录约 `246.41 MB`；不含 `.git` 约 `91.97 MB`；不含 `.git` 和依赖约 `62.78 MB`；Git 跟踪文件约 `17.85 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\data-health-static.js`、数据健康静态导出 smoke 检查、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十四刀拆分

- 扩展 `public/ctrip-static.js`，承载携程流量概览接口诊断行构建器 `buildCtripFlowOverviewInterfaceRows`，包括接口命中、未响应、请求失败、已响应但未入库等原因文案。
- `public/index.html` 仅保留 `ctripFlowOverviewInterfaceRows` computed 绑定，不移动携程概览抓取、补抓、Cookie/Profile 检查、接口请求或持久化链路。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，要求携程流量接口诊断 builder 和原因文案留在 `public/ctrip-static.js`，入口通过 `requireCtripStatic()` 显式读取。
- `public/index.html` 从 `40,976` 行降至 `40,885` 行；拆分地图中前端函数级块从 `1,124` 降至 `1,120`，`general` 领域 span 从 `9,715` 行降至 `9,533` 行。
- 当前 `public/ctrip-static.js` 为 `175` 行；总代码行数因静态模块和测试契约扩展增加到 `186,369` 行，非空 `170,687` 行。
- 当前审计：完整目录约 `247 MB`；不含 `.git` 约 `91.97 MB`；不含 `.git` 和依赖约 `62.78 MB`；Git 跟踪文件约 `17.86 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\ctrip-static.js`、携程静态导出 smoke 检查、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十五刀拆分

- 扩展 `public/ctrip-static.js`，承载携程概览指标卡、携程 TOP 榜和携程流量概览指标卡构建器：`buildCtripOverviewMetricCards`、`buildCtripOverviewTopRankTables`、`buildCtripFlowOverviewMetricCards`。
- `public/index.html` 仅保留 `ctripOverviewMetricCards`、`ctripOverviewTopRankTables`、`ctripFlowOverviewMetricCards` 三个 computed 绑定，不移动携程概览抓取、流量概览抓取、补抓、Cookie/Profile 检查、接口请求或持久化链路。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，要求上述 Ctrip 展示构建器留在 `public/ctrip-static.js`，入口通过 `requireCtripStatic()` 显式读取，且入口不再保留 `normalizeCtripTopRankItems`。
- `public/index.html` 从 `40,885` 行降至 `40,757` 行；拆分地图中前端函数级块从 `1,120` 降至 `1,119`，`ctrip` 领域 span 从 `3,968` 行降至 `3,840` 行。
- 当前 `public/ctrip-static.js` 为 `317` 行；总代码行数因静态模块和测试契约扩展增加到 `186,391` 行，非空行 `170,704` 行。
- 当前审计：完整目录约 `247.58 MB`；不含 `.git` 约 `91.97 MB`；不含 `.git` 和依赖约 `62.78 MB`；Git 跟踪文件约 `17.86 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\ctrip-static.js`、Ctrip static export smoke check、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十六刀拆分

- 扩展 `public/ctrip-static.js`，承载携程榜单表格排序值映射和排序构建器：`ctripSortMetricValue`、`buildCtripSortedHotelRows`。
- `public/index.html` 仅保留 `ctripSortedHotelsList` computed 绑定、排序状态和分页状态；不移动携程榜单数据获取、入库、概览抓取、Cookie/Profile 检查或 OTA 数据链路。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，要求携程榜单排序构建器留在 `public/ctrip-static.js`，入口通过 `requireCtripStatic()` 显式读取，且入口不再保留 `const field = ctripSortField.value;` 排序映射主体。
- `public/index.html` 从 `40,757` 行降至 `40,703` 行；拆分地图中 `ctrip` 领域 span 从 `3,840` 行降至 `3,786` 行。
- 当前 `public/ctrip-static.js` 为 `350` 行；总代码行数为 `186,373` 行，非空行 `170,684` 行。
- 当前审计：完整目录约 `248.17 MB`；不含 `.git` 约 `91.98 MB`；不含 `.git` 和依赖约 `62.79 MB`；Git 跟踪文件约 `17.86 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\ctrip-static.js`、Ctrip static export smoke check、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十七刀拆分

- 扩展 `public/expansion-static-options.js`，承载战略选址项目级选项和重置 helper：`strategyCityOptionsForProject`、`strategyDistrictOptionsForProject`、`strategyAddressKeywordOptionsForProject`、`strategyNextDistrictForProject`、`strategyNextAddressForProject`、`estimateStrategyCompetitorCount`。
- `public/index.html` 仅保留战略选址 computed/watch 绑定和运行态赋值；城市、区域、地址候选、地址重置和竞品数量估算规则转移到 `window.SUXI_EXPANSION_STATIC`。本轮不移动市场评估、战略测算、历史复用、接口请求、保存或 OTA 数据链路。
- 更新 `scripts/verify_strategy_location_ui_contract.mjs`，要求项目级地址/区域 helper 留在 `public/expansion-static-options.js`，入口显式通过 `aiProject.value` 调用 helper。
- `public/index.html` 从 `40,703` 行降至 `40,679` 行；拆分地图中 `strategy` 领域 span 从 `381` 行降至 `360` 行。
- 当前 `public/expansion-static-options.js` 为 `338` 行；总代码行数为 `186,405` 行，非空行 `170,716` 行。
- 当前审计：完整目录约 `249.34 MB`；不含 `.git` 约 `91.98 MB`；不含 `.git` 和依赖约 `62.79 MB`；Git 跟踪文件约 `17.87 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\expansion-static-options.js`、`node --check scripts\verify_strategy_location_ui_contract.mjs`、Expansion strategy helper smoke check、`node scripts\verify_strategy_location_ui_contract.mjs`、`node scripts\verify_expansion_p2.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十八刀拆分

- 扩展 `public/operation-static.js`，承载运营总览展示构建器：`buildOperationSummaryCards`、`buildOperationOtaCards`、`buildOperationCompetitorCards`、`buildOperationSourceBrief`、`buildOperationDecisionCards`。
- `public/index.html` 仅保留对应 computed 绑定和运行态格式化函数；不移动酒店权限选择、运营接口请求、执行流、AI 日报、根因分析或 OTA 数据链路。
- 更新 `scripts/verify_e2e_contracts.mjs`，运营服务质量和决策卡合同改为同时读取入口文件和 `public/operation-static.js`，避免纯展示拆分后误要求文案留在入口。
- `public/index.html` 从 `40,679` 行降至 `40,583` 行；拆分地图中 `operation` 领域 span 从 `676` 行降至 `575` 行。
- 当前 `public/operation-static.js` 为 `183` 行；总代码行数为 `186,431` 行，非空行 `170,742` 行。
- 当前审计：完整目录约 `249.38 MB`；不含 `.git` 约 `91.99 MB`；不含 `.git` 和依赖约 `62.8 MB`；Git 跟踪文件约 `17.87 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\operation-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、Operation display helper smoke check、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第二十九刀拆分

- 扩展 `public/data-health-static.js`，承载携程采集健康展示构建器：`buildCollectionHealthCtripCatalogCards`、`collectionHealthCtripCatalogDiagnosticScopeText`、`collectionHealthCtripCatalogAuthText`、`collectionHealthCtripCatalogPendingFetchText`、`collectionHealthCtripCatalogPendingFieldText`、`buildCollectionHealthCtripCatalogVisibleNotes`、`collectionHealthCtripCatalogActionText`、`buildCollectionHealthCtripLatestCards`、`buildCollectionHealthCtripOverviewStatusCards`、`buildCtripOverviewFetchModuleCards`。
- `public/index.html` 仅保留对应 computed 绑定、携程授权状态、门店身份阻断、入库行计数和运行态来源；本轮不移动携程采集、Cookie/Profile 检查、接口请求、持久化或 OTA 数据链路。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，携程总览卡片、补抓按钮、身份冲突文案和新 builder 的静态证据改为同时读取 `public/index.html` 与 `public/data-health-static.js`。
- `public/index.html` 从 `40,583` 行降至 `40,525` 行；split-map 前端函数级块从 `1,119` 降至 `1,117`，`general` 域 span 从 `9,538` 行降至 `9,411` 行。
- 当前 `public/data-health-static.js` 为 `536` 行；总代码行数为 `186,498` 行，非空行 `170,799` 行。
- 当前审计：完整目录约 `249.97 MB`；不含 `.git` 约 `92 MB`；不含 `.git` 和依赖约 `62.81 MB`；Git 跟踪文件约 `17.88 MB` / `609` 个；代码范围 `366` 个文件。
- 验证通过：`node --check public\data-health-static.js`、`node --check tests\automation\ctrip_store_data_overview.test.mjs`、携程数据健康展示 builder smoke check、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍未宣称完成，原因仍是 `public/index.html` 与 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第一刀拆分

- 新增 `app/service/CtripTrafficDisplayService.php`，承载携程流量展示行、展示汇总、APP 流量派生分析、流量数值读取、百分比归一化和转化率计算等纯计算逻辑。
- `app/controller/OnlineData.php` 保留原私有方法名作为薄 wrapper，继续兼容 `OnlineDataTest` 的反射覆盖和 `verify_frontend_display_boundary.mjs` 的方法名契约。
- 本轮不移动携程流量请求、Cookie 校验、日期范围解析、采集结果入库、`extractCtripTrafficRows` 递归解析、广告/美团/自动采集链路或路由。
- `app/controller/OnlineData.php` 从 `27,333` 行降至 `27,052` 行；split-map 中 `traffic` 域 span 从 `547` 行降至 `335` 行。
- 当前 `app/service/CtripTrafficDisplayService.php` 为 `368` 行；总代码行数为 `186,584` 行，非空行 `170,868` 行。
- 当前审计：完整目录约 `250.83 MB`；不含 `.git` 约 `92 MB`；不含 `.git` 和依赖约 `62.81 MB`；Git 跟踪文件约 `17.89 MB` / `610` 个；代码范围 `367` 个文件。
- 验证通过：`C:\xampp\php\php.exe -l app\controller\OnlineData.php`、`C:\xampp\php\php.exe -l app\service\CtripTrafficDisplayService.php`、`C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php`、`npm.cmd run verify:e2e-contracts`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍未宣称完成，原因仍是 `public/index.html` 与 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 后端第二刀拆分

- 新增 `app/service/CtripCaptureDiagnosisService.php`，承载携程采集诊断的计数统计、事实行计数 payload、诊断分组、指标 key 拆分、维度 key 提取和指标中文标签。
- `app/controller/OnlineData.php` 保留原私有方法名作为薄 wrapper，继续兼容 `OnlineDataTest` 反射覆盖和 `verify_ota_diagnosis_auto_fetch.mjs` 的静态方法名契约。
- 本轮不移动携程/美团采集执行、Cookie/Profile 检查、`extractCtripCapturedSection` 去重提取、采集 gate 判定、响应解析、入库、路由或 UI。
- `app/controller/OnlineData.php` 从 `27,052` 行降至 `26,725` 行；split-map 中 `ctrip` 域 span 从 `11,791` 行降至 `11,463` 行。
- 当前 `app/service/CtripCaptureDiagnosisService.php` 为 `360` 行；总代码行数为 `186,636` 行，非空行 `170,911` 行。
- 当前审计：完整目录约 `251.14 MB`；不含 `.git` 约 `92 MB`；不含 `.git` 和依赖约 `62.81 MB`；Git 跟踪文件约 `17.89 MB` / `611` 个；代码范围 `368` 个文件。
- 验证通过：`C:\xampp\php\php.exe -l app\controller\OnlineData.php`、`C:\xampp\php\php.exe -l app\service\CtripCaptureDiagnosisService.php`、`C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php --filter "CtripCaptureDiagnosisSummary|CtripCaptureCounts|CtripCaptureGate"`、`C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\ServiceInventoryTest.php`、`C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\OnlineDataTest.php`、`node scripts\verify_ota_diagnosis_auto_fetch.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍未宣称完成，原因仍是 `public/index.html` 与 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十刀拆分

- 扩展 `public/home-static.js`，承载首页首屏决策条、动作行和数据就绪度构建器：`buildHomeBoardActionRows`、`buildCompassDataReadiness`、`buildHomeDecisionSummaryRows`。
- `public/index.html` 仅保留现有 Vue computed 输入绑定和运行态状态读取；不移动首页趋势加载、罗盘数据源、竞对摘要、宏观信号、快捷入口拖拽、接口请求或 OTA 数据链路。
- 更新 `scripts/verify_home_visual_hierarchy_contract.mjs`，首页首屏层级契约改为检查入口显式读取 `home-static.js` builder，文案和结构证据留在 `public/home-static.js`。
- `public/index.html` 从 `40,525` 行降至 `40,449` 行；split-map 中 `general` 域 span 从 `9,411` 行降至 `9,335` 行。
- 当前 `public/home-static.js` 为 `246` 行；总代码行数为 `186,698` 行，非空行 `170,971` 行。
- 当前审计：完整目录约 `251.19 MB`；不含 `.git` 约 `92.01 MB`；不含 `.git` 和依赖约 `62.82 MB`；Git 跟踪文件约 `17.9 MB` / `611` 个；代码范围 `368` 个文件。
- 验证通过：`node --check public\home-static.js`、`node --check scripts\verify_home_visual_hierarchy_contract.mjs`、`npm.cmd run verify:home-visual-hierarchy`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍未宣称完成，原因仍是 `public/index.html` 与 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十一刀拆分

- 扩展 `public/expansion-static-options.js`，承载可行性报告展示构建器：`buildFeasibilityInputCards`、`buildFeasibilityReportCards`、`buildFeasibilityAiEmpowerment`、`feasibilityDecisionClassForGrade`、`stringifyFeasibilityReport`。
- `public/index.html` 仅保留 Vue computed 绑定、运行态输入和复制/打印动作；本轮不移动可行性报告生成请求、历史复用、归档、接口调用、localStorage 状态或 OTA 数据链路。
- 更新 `scripts/verify_expansion_p2.mjs`，要求入口显式通过 `requireExpansionStaticOption()` 读取上述构建器，同时要求构建器留在 `public/expansion-static-options.js`。
- `public/index.html` 从 `40,449` 行降至 `40,356` 行；split-map 中前端函数级块从 `1,117` 降至 `1,113`，`general` 域 span 从 `9,335` 行降至 `9,237` 行。
- 当前 `public/expansion-static-options.js` 为 `451` 行；本轮代码改动后总代码行数为 `186,730`，非空行 `171,005`。
- 本轮代码改动后自审计：完整目录约 `251.77 MB`；不含 `.git` 约 `92.01 MB`；不含 `.git` 和依赖约 `62.82 MB`；Git 跟踪文件约 `17.9 MB` / `611` 个；代码范围 `368` 个文件。
- 验证通过：`node --check public\expansion-static-options.js`、`node --check scripts\verify_expansion_p2.mjs`、`node scripts\verify_expansion_p2.mjs`、`node scripts\verify_strategy_location_ui_contract.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check -- public\index.html public\expansion-static-options.js scripts\verify_expansion_p2.mjs`、`npm.cmd run self:check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十二刀拆分

- 扩展 `public/simulation-static.js`，承载量化模拟纯展示构建器：`buildSimulationInvestmentGroups`、`simulationInvestmentTotalFromGroups`、`simulationInvestmentPerRoom`、`buildSimulationRoomRevenueSegments`、`buildSimulationCostGroups`、`buildSimulationOtaCommissionChannels`、`isSimulationModelAnalysisVisible`、`simulationModelSourceLabel`。
- `public/index.html` 仅保留 Vue computed 绑定和运行态输入；本轮不移动量化模拟请求、历史加载、复用、归档、localStorage 持久化、后端保存或 OTA 数据链路。
- 更新 `scripts/verify_simulation_p2.mjs`，要求入口显式通过 `requireSimulationStatic()` 读取上述展示构建器，同时要求构建器留在 `public/simulation-static.js`。
- `public/index.html` 从 `40,356` 行降至 `40,319` 行；split-map 中前端函数级块从 `1,113` 降至 `1,110`，`simulation` 域 span 从 `380` 行降至 `343` 行，`handleSimulation` 块 span 从 `231` 行降至 `194` 行。
- 当前 `public/simulation-static.js` 为 `514` 行；本轮代码改动后总代码行数为 `186,785`，非空行 `171,051`。
- 本轮代码改动后自审计：完整目录约 `252.36 MB`；不含 `.git` 约 `92.02 MB`；不含 `.git` 和依赖约 `62.83 MB`；Git 跟踪文件约 `17.91 MB` / `611` 个；代码范围 `368` 个文件。
- 验证通过：`node --check public\simulation-static.js`、`node --check scripts\verify_simulation_p2.mjs`、`node scripts\verify_simulation_p2.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check -- public\index.html public\simulation-static.js scripts\verify_simulation_p2.mjs`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十三刀拆分

- 扩展 `public/auto-fetch-static.js`，承载数据源配置表单解析、字段别名归一化、请求体压缩和采集请求体构建器：`parseDataConfigValue`、`normalizeDataConfigForForm`、`compactDataConfigBody`、`buildDataConfigRequestBody`。
- `public/index.html` 仅保留数据配置表单状态、保存/测试动作、请求执行和运行态校验；本轮不移动配置保存、接口测试、OTA 抓取调用、系统配置持久化、toast 校验或凭据处理。
- 更新 `scripts/verify_platform_data_source_contract.mjs`，要求入口通过 `requireAutoFetchStatic()` 读取数据配置 normalizer/request builder，且要求 `public/auto-fetch-static.js` 保留 `ctrip-cookie-api` 和 `meituan-ads` 请求映射。
- `public/index.html` 从 `40,319` 行降至 `40,113` 行；split-map 前端函数级块从 `1,110` 降至 `1,107`，`config` 域 span 从 `897` 行降至 `687` 行。
- 当前 `public/auto-fetch-static.js` 为 `284` 行；本轮代码改动后总代码行数为 `186,806`，非空行 `171,075`。
- 本轮代码改动后自审计：完整目录约 `252.94 MB`；不含 `.git` 约 `92.02 MB`；不含 `.git` 和依赖约 `62.83 MB`；Git 跟踪文件约 `17.91 MB` / `611` 个；代码范围 `368` 个文件。
- 已验证：`node --check public\auto-fetch-static.js`、`node --check scripts\verify_platform_data_source_contract.mjs`、`node scripts\verify_platform_data_source_contract.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十四刀拆分

- 扩展 `public/meituan-static.js`，承载美团默认表单工厂和浏览器采集 section 归一化：`defaultMeituanAdsUrl`、`createMeituanRankingForm`、`createMeituanTrafficForm`、`createMeituanOrderForm`、`createMeituanAdsForm`、`createMeituanBrowserCaptureForm`、`normalizeMeituanCaptureSections`。
- `public/index.html` 仅保留 `ref(create...)` 状态绑定、采集命令拼接、Profile 登录 payload、切换 tab 和执行请求；本轮不移动美团抓取接口、保存抓取结果、Profile 登录轮询、数据源绑定或 OTA 入库链路。
- 更新 `scripts/verify_p0_learning_contract.mjs`，要求美团默认采集表单和 section 归一化留在 `public/meituan-static.js`，入口通过 `requireMeituanStatic()` 读取。
- `public/index.html` 从 `40,113` 行降至 `40,049` 行；split-map 前端函数级块从 `1,107` 降至 `1,106`，`meituan` 域 span 从 `1,371` 行降至 `1,364` 行。
- 当前 `public/meituan-static.js` 为 `264` 行；本轮代码改动后总代码行数为 `186,842`，非空行 `171,104`。
- 本轮代码改动后自审计：完整目录约 `253.52 MB`；不含 `.git` 约 `92.03 MB`；不含 `.git` 和依赖约 `62.84 MB`；Git 跟踪文件约 `17.92 MB` / `611` 个；代码范围 `368` 个文件。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_p0_learning_contract.mjs`、`npm.cmd run verify:p0-learning`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`git diff --check`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十五刀拆分

- 扩展 `public/ctrip-static.js`，承载携程默认采集表单工厂：`createCtripFetchForm`、`createCtripTrafficForm`、`createCtripAdsBrowserCaptureForm`、`createCtripOverviewForm`、`createCtripFlowOverviewForm`、`createCtripBrowserCaptureForm`、`createCtripCookieApiForm`、`createCtripEndpointEvidenceForm`、`createCtripCommentForm`、`createCtripCommentBrowserCaptureForm`。
- `public/index.html` 仅保留 `ref(create...)` 状态绑定、采集运行态、Profile/Cookie/API 请求执行和 UI 回显；本轮不移动携程采集接口、Cookie/Profile 检查、Endpoint evidence 校验、评论聚合边界、数据源绑定或 OTA 入库链路。
- 更新 `scripts/verify_p0_learning_contract.mjs`，要求携程默认采集表单留在 `public/ctrip-static.js`，入口通过 `requireCtripStatic()` 读取，并禁止关键表单对象重新内联。
- `public/index.html` 从 `40,049` 行降至 `39,986` 行；split-map 前端函数级块保持 `1,106`，`ctrip` 域 span 从 `3,802` 行降至 `3,739` 行。
- 当前自审计：完整目录约 `254.1 MB`；不含 `.git` 约 `92.04 MB`；不含 `.git` 和依赖约 `62.85 MB`；Git 跟踪文件约 `17.92 MB` / `611` 个；代码范围 `368` 个文件、`186,886` 行、非空行 `171,148`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_p0_learning_contract.mjs`、`npm.cmd run verify:p0-learning`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:check`、`npm.cmd run self:split-map`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十六刀拆分

- 扩展 `public/simulation-static.js`，承载投资/扩张/转让相关默认表单工厂：`createBenchmarkModelForm`、`createCollaborationProject`、`createTransferPricingForm`、`createTransferTimingForm`。
- `public/index.html` 仅保留 `ref(create...)` 状态绑定、计算结果、历史记录复用、接口请求和运行态校验；本轮不移动量化模拟、扩张协同、资产定价、转让时机请求、历史加载、复用归档或 OTA 数据路径。
- 更新 `scripts/verify_simulation_p2.mjs`，要求默认表单工厂留在 `public/simulation-static.js`，入口通过 `requireSimulationStatic()` 读取，并禁止关键表单对象重新内联。
- 修正 `scripts/project_split_map.mjs`，将 Vue `computed(...)` 识别为前端块边界，避免把 4 行 `printFeasibilityReport` 误报为 269 行；修正后真实最大前端块为 `runOtaDiagnosisHotelFetch`。
- `public/index.html` 从 `39,986` 行降至 `39,930` 行；修正后的 split-map 前端块计数为 `1,470`，`strategy` 域 span 从 `307` 行降至 `231` 行。
- 当前自审计：完整目录约 `254.69 MB`；不含 `.git` 约 `92.04 MB`；不含 `.git` 和依赖约 `62.85 MB`；Git 跟踪文件约 `17.93 MB` / `611` 个；代码范围 `368` 个文件、`186,917` 行、非空行 `171,179`。
- 已验证：`node --check public\simulation-static.js`、`node --check scripts\verify_simulation_p2.mjs`、`node scripts\verify_simulation_p2.mjs`、`node scripts\verify_expansion_p2.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`node --check scripts\project_split_map.mjs`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十七刀拆分

- 新增 `public/testid-static.js`，承载前端稳定测试 ID 的段名归一化、菜单/页面 testid 生成、页面控件自动补 `data-testid`、当前页根节点观察器和刷新调度逻辑。
- `public/index.html` 仅保留 `testIdNameMap` 读取、`createPageTestIdController(...)` 装配和原有 `pageTestId` / `menuTestId` / watcher 接口名；导航、页面容器和当前页切换逻辑不变。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式加载 `testid-static.js`，并要求 `assignPageControlTestIds` 与 `normalizeTestIdSegment` 留在新静态模块中。
- `public/index.html` 从 `39,930` 行降至 `39,801` 行；split-map 前端函数级块从 `1,470` 降至 `1,457`，`general` 域 span 从 `8,747` 行降至 `8,617` 行。
- 当前自审：完整目录约 `255.8 MB`；不含 `.git` 约 `92.05 MB`；不含 `.git` 和依赖约 `62.86 MB`；Git 跟踪文件约 `17.93 MB` / `612` 个；代码范围 `369` 个文件、`186,970` 行、非空 `171,230` 行。
- 已验证：`node --check public\testid-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十八刀拆分

- 扩展 `public/system-static.js`，承载系统菜单递归解析和可见菜单权限过滤：`resolveMenuItems`、`filterVisibleMenuItems`。
- `public/index.html` 仅保留当前语言菜单名称、系统配置读取、`menuItems` / `visibleMenuItems` computed 装配；菜单定义、权限字段、导航点击和页面加载逻辑不变。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口使用 `filterVisibleMenuItems(menuItems.value, user.value)`，并要求菜单解析/过滤 helper 留在 `public/system-static.js`，禁止 `isItemVisible` 重新内联。
- `public/index.html` 从 `39,801` 行降至 `39,745` 行；split-map 前端函数级块从 `1,457` 降至 `1,455`，`general` 域 span 从 `8,617` 行降至 `8,561` 行。
- 当前自审：完整目录约 `255.85 MB`；不含 `.git` 约 `92.05 MB`；不含 `.git` 和依赖约 `62.86 MB`；Git 跟踪文件约 `17.93 MB` / `612` 个；代码范围 `369` 个文件、`186,960` 行、非空 `171,227` 行。
- 已验证：`node --check public\system-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 2026-06-10 前端第三十九刀拆分

- 扩展 `public/system-static.js`，承载酒店平台账号行构造相关纯展示逻辑：`platformNextActionMeta`、`platformAccountStoreText` 和 `buildHotelPlatformAccountRow`。
- `public/index.html` 仅保留 `buildHotelPlatformAccountRowStatic` 包装和现有 Vue/运行态依赖注入；酒店账号绑定状态、下一个动作、登录/日志/采集入口语义保持不变。
- 本轮不移动 OTA 采集执行、Profile/Cookie/API 检查、接口调用、数据源保存、同步日志或入库链路。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口继续使用提取后的账号行构造器，并禁止 `platformNextActionMeta` / `platformAccountStoreText` 重新内联回 `public/index.html`。
- 更新 `scripts/verify_platform_account_guide_contract.mjs`，让平台账号向导契约同时读取 `public/system-static.js` 和入口路由代码；后端动作契约对齐当前 `configure_platform_profile` / `platform-sources` 行为。
- 更新 `tests/automation/ctrip_store_data_overview.test.mjs`，让平台账号 badge 相关断言从 `public/system-static.js` 读取行构造器，并确认入口通过 `platformSourceForHotel(...)` 注入来源数据。
- `public/index.html` 从 `39,745` 行降至 `39,607` 行；`public/system-static.js` 当前为约 `690` 行；split-map 前端函数级块从 `1,455` 降至 `1,453`，`general` 域 span 从 `8,561` 行降至 `8,518` 行。
- 当前自审计：完整目录约 `256.44 MB`，不含 `.git` 约 `92.06 MB`，不含 `.git` 和依赖约 `62.87 MB`，Git 跟踪文件约 `17.95 MB` / `612` 个；代码范围 `369` 个文件，`187,022` 行，非空行 `171,291`。
- 已验证：`node --check public\system-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check scripts\verify_platform_account_guide_contract.mjs`、`node --check tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:platform-account-guide`、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十刀拆分

- 扩展 `public/expansion-static-options.js`，承载战略测算结果展示构建器：`buildStrategyScoreCards`、`strategyFreshnessLabelForSnapshot`、`strategyAiSourceLabelForResult`、`strategyAiModelDisplayLabelForSnapshot`、`strategyPoiDataSourceLabelForSnapshot`、`strategyDataNoticeForSnapshot`、`buildStrategyDataSourceRows`、`buildStrategyAiEmpowermentCards`。
- `public/index.html` 仅保留原有 computed 名称和运行态输入绑定；战略测算请求、历史加载、复用、归档、可研报告生成、localStorage 和 OTA 数据链路均未移动。
- 更新 `scripts/verify_expansion_p2.mjs`，要求入口通过 `requireExpansionStaticOption(...)` 显式读取上述构建器，并禁止关键长逻辑片段重新内联回 `public/index.html`。
- 更新 `scripts/project_split_map.mjs`，把 Vue setup 顶层 `return {` 识别为块边界，修正 `homeAiTraceRows` 被尾部 return 误报为 245 行大块的问题；修正后最大前端块仍是 `runOtaDiagnosisHotelFetch`，本轮未移动 OTA 采集执行链路。
- `public/index.html` 从 `39,607` 行降至 `39,513` 行；`public/expansion-static-options.js` 当前约 `588` 行；split-map 中 `ai` 域 span 从 `1,918` 行降至 `1,637` 行。
- 当前自审计：完整目录约 `257.05 MB`，不含 `.git` 约 `92.07 MB`，不含 `.git` 和依赖约 `62.88 MB`，Git 跟踪文件约 `17.95 MB` / `612` 个；代码范围 `369` 个文件，`187,092` 行，非空行 `171,360`。总代码行数较上轮增加，原因是静态模块和守卫脚本新增的显式契约多于入口减少行数。
- 已验证：`node --check public\expansion-static-options.js`、`node --check scripts\verify_expansion_p2.mjs`、`node --check scripts\project_split_map.mjs`、`node scripts\verify_expansion_p2.mjs`、`node scripts\verify_strategy_location_ui_contract.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十一刀拆分

- 扩展 `public/home-static.js`，承载首页经营结果卡片和因果链展示构建器：`buildHomeOperatingResultCards`、`buildHomeCausalChainNodes`。
- `public/index.html` 仅保留原有 `homeOperatingResultCards` / `homeCausalChainNodes` computed 名称和运行态输入采集；首页趋势加载、宏观信号、竞品摘要、快捷入口、接口请求和 OTA 数据链路均未移动。
- 保留“OTA/经营日报样本口径，不替代全酒店总营收”“优先展示采集字段，不用收入/间夜倒推”等口径边界，不用兜底逻辑掩盖缺失字段。
- 更新 `scripts/verify_home_visual_hierarchy_contract.mjs`，要求入口显式读取上述两个 builder，并禁止 `cardVisual` 与 `homeOperatingResultCards.value.find(...)` 等长逻辑重新内联回 `public/index.html`。
- `public/index.html` 从 `39,513` 行降至 `39,432` 行；`public/home-static.js` 当前约 `382` 行；split-map 中 `general` 域 span 从 `8,518` 行降至 `8,444` 行。
- 当前自审计：完整目录约 `257.64 MB`，不含 `.git` 约 `92.07 MB`，不含 `.git` 和依赖约 `62.88 MB`，Git 跟踪文件约 `17.96 MB` / `612` 个；代码范围 `369` 个文件，`187,155` 行，非空行 `171,422`。总代码行数较上轮增加，原因是静态模块和首页契约新增显式 builder/守卫多于入口减少行数。
- 已验证：`node --check public\home-static.js`、`node --check scripts\verify_home_visual_hierarchy_contract.mjs`、`npm.cmd run verify:home-visual-hierarchy`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十二刀拆分

- 扩展 `public/data-health-static.js`，承载平台批量体检的 badge 样式、门店状态行构建器 `buildPlatformBatchHealthRows` 和摘要卡构建器 `buildPlatformBatchHealthSummaryCards`。
- `public/index.html` 只保留 `platformBatchHealthRows` / `platformBatchHealthSummaryCards` computed 名称、运行态数据输入和显式静态工具读取；平台数据源保存/同步、竞对摘要加载、OTA 采集、入库链路和 UI 模板均未移动。
- 保留“待绑定”“未采集”“采集失败”“待试采”“缺少最近采集证据”等缺失/失败状态文案，不用空数据或默认成功掩盖采集缺口。
- 更新 `scripts/verify_platform_batch_health_contract.mjs`，让批量体检契约同时读取 `public/index.html` 与 `public/data-health-static.js`，并防止 `sourceMap` 等长逻辑重新内联回主入口。
- `public/index.html` 从 `39,432` 行降至 `39,333` 行；split-map 前端函数级块从 `1,453` 降至 `1,449`，`general` 域 span 从 `8,444` 降至 `8,346`。
- 当前自审：完整目录约 `258.23 MB`，不含 `.git` 约 `92.09 MB`，不含 `.git` 和依赖约 `62.90 MB`，Git 跟踪文件约 `17.96 MB` / `612` 个；代码范围 `369` 个文件，`187,204` 行，非空 `171,467` 行。
- 已验证：`node --check public\data-health-static.js`、`node --check scripts\verify_platform_batch_health_contract.mjs`、`npm.cmd run verify:platform-batch-health`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十三刀拆分

- 扩展 `public/ctrip-static.js`，承载携程 Cookie/API 核心诊断端点预设 `getCtripCookieApiCorePresetEndpoints`，包含经营、流量、广告、PSI、商旅、用户画像、IM、竞对、流失等 section。
- `public/index.html` 只保留 `getCtripCookieApiCorePresetJson()`、表单填充和运行态调用；Cookie/API 执行、Profile 复用、数据配置保存、诊断抓取和入库链路均未移动。
- 更新 `scripts/verify_ota_diagnosis_auto_fetch.mjs`，让核心预设端点覆盖从 `public/ctrip-static.js` 取证，同时要求入口页通过 `requireCtripStatic('getCtripCookieApiCorePresetEndpoints')` 显式读取，防止端点数组重新内联。
- 更新 `tests/automation/ctrip_endpoint_evidence_ui.test.mjs`，让已拆出的端点证据表单默认值和核心预设端点分别从 `public/ctrip-static.js` 取证，入口页继续验证模板绑定与请求 payload。
- `public/index.html` 从 `39,333` 行降至 `39,217` 行；split-map 前端函数级块从 `1,449` 降至 `1,448`，`ctrip` 域 span 从 `3,750` 降至 `3,634`。
- 当前自审：完整目录约 `258.81 MB`，不含 `.git` 约 `92.09 MB`，不含 `.git` 和依赖约 `62.90 MB`，Git 跟踪文件约 `17.97 MB` / `612` 个；代码范围 `369` 个文件，`187,212` 行，非空 `171,474` 行。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_ota_diagnosis_auto_fetch.mjs`、`node --check tests\automation\ctrip_endpoint_evidence_ui.test.mjs`、`npm.cmd run verify:ota-diagnosis-auto-fetch`、`node --test tests\automation\ctrip_endpoint_evidence_ui.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十四刀拆分

- 扩展 `public/operation-static.js`，承载开业总览卡片纯构建器 `buildOpeningOverviewCards`，覆盖开业倒计时、总评分、风险等级、检查项完成率、核心完成率、高风险事项、逾期事项和 AI 建议推进率。
- `public/index.html` 只保留 `openingOverviewCards` computed 名称和 `openingOverview.value` 运行态输入；开业项目/任务请求、批量更新、评分、保存、回显、编辑和存储链路均未迁移。
- 修正 `scripts/project_split_map.mjs` 的 Vue setup 边界识别，新增 `watch(...)`、`onMounted(...)`、`onUnmounted(...)` 和顶层 `ref(...)` 边界，避免把后续 watcher、生命周期钩子或状态声明误并入前一个函数块。
- 更新 `scripts/verify_opening_batch_actions.mjs`，同时校验入口显式读取 `buildOpeningOverviewCards`、构建器留在 `public/operation-static.js`，并运行一次样例输出检查，防止总览卡片逻辑重新内联回入口文件。
- `public/index.html` 从 `39,217` 行降至 `39,129` 行；split-map 前端函数级块从 `1,448` 降至 `1,446`，`general` 域 span 从 `7,648` 降至 `7,562`，当前最大前端块仍为 `runOtaDiagnosisHotelFetch`（`265` 行）。
- 当前自审计：完整目录约 `259.4 MB`，不含 `.git` 约 `92.1 MB`，不含 `.git` 和依赖约 `62.91 MB`；Git 跟踪文件约 `17.97 MB` / `612` 个；代码范围 `369` 个文件，`187,285` 行，非空 `171,542` 行。
- 已验证：`node --check public\operation-static.js`、`node --check scripts\verify_opening_batch_actions.mjs`、`npm.cmd run verify:opening-batch-actions`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十五刀拆分

- 扩展 `public/operation-static.js`，承载开业 AI 输出聚合构建器 `buildOpeningAiOutputResult`，同时内聚 `openingAiTaskReason`、`openingAiTaskPriorityScore` 和进度读取逻辑。
- `public/index.html` 只保留 `openingAiOutputResult` computed 名称、`openingTasks` / `openingTaskStats` / `openingOverview.ai_suggestions` 输入，以及现有任务状态 helper 注入；开业项目请求、任务批量更新、评分刷新、保存、回显、编辑和存储链路均未迁移。
- 更新 `scripts/verify_opening_batch_actions.mjs`，同时校验入口显式读取 `buildOpeningAiOutputResult`、构建器留在 `public/operation-static.js`，并运行一次样例 AI 输出聚合检查。
- `public/index.html` 从 `39,129` 行降至 `39,043` 行；split-map 前端函数级块从 `1,446` 降至 `1,444`，`ai` 域 span 从 `1,569` 降至 `1,482`，当前最大前端块仍为 `runOtaDiagnosisHotelFetch`（`265` 行）。
- 当前自审计：完整目录约 `259.98 MB`，不含 `.git` 约 `92.1 MB`，不含 `.git` 和依赖约 `62.91 MB`；Git 跟踪文件约 `17.98 MB` / `612` 个；代码范围 `369` 个文件，`187,362` 行，非空 `171,621` 行。
- 已验证：`node --check public\operation-static.js`、`node --check scripts\verify_opening_batch_actions.mjs`、`npm.cmd run verify:opening-batch-actions`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十六刀拆分

- 扩展 `public/home-static.js`，承载首页经营趋势图 Chart.js 配置构建器 `buildHomeTrendChartConfig`，同时内聚趋势图 Y 轴格式化 `formatHomeTrendAxisTick` 和指标配色。
- `public/index.html` 只保留 `renderHomeTrendChart()` 的浏览器运行时职责：检测 `window.Chart`、校验 canvas、销毁旧实例、创建 Chart 实例和重试调度；趋势数据加载、刷新、筛选、OTA 样本来源和 canvas 生命周期均未迁移。
- 更新 `scripts/verify_home_visual_hierarchy_contract.mjs`，同时校验入口显式读取 `buildHomeTrendChartConfig`、图表配置留在 `public/home-static.js`，并运行一次样例 Chart 配置输出检查。
- `public/index.html` 从 `39,043` 行降至 `38,984` 行；split-map 前端函数级块从 `1,444` 降至 `1,443`，`general` 域 span 从 `7,562` 降至 `7,503`，当前最大前端块仍为 `runOtaDiagnosisHotelFetch`（`265` 行）。
- 当前自审计：完整目录约 `260.56 MB`，不含 `.git` 约 `92.11 MB`，不含 `.git` 和依赖约 `62.92 MB`；Git 跟踪文件约 `17.98 MB` / `612` 个；代码范围 `369` 个文件，`187,402` 行，非空 `171,659` 行。
- 已验证：`node --check public\home-static.js`、`node --check scripts\verify_home_visual_hierarchy_contract.mjs`、`npm.cmd run verify:home-visual-hierarchy`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十七刀拆分

- 扩展 `public/expansion-static-options.js`，承载市场评估 AI 风险建议构建器 `buildMarketEvaluationAiRiskSuggestions`，并内聚风险等级归一化和风险详情推断。
- `public/index.html` 只保留 `marketEvaluationAiRiskSuggestions` computed 名称及 `marketEvaluationResult` / `marketEvaluationForm` 运行态输入；市场评估请求、评分、历史记录、保存、回显、编辑、OTA 数据路径和投决 UI 模板均未迁移。
- 更新 `scripts/verify_expansion_p2.mjs`，要求入口通过 `requireExpansionStaticOption('buildMarketEvaluationAiRiskSuggestions')` 显式读取构建器，禁止风险详情推断函数重新内联，并运行一次样例输出校验。
- 补齐 `package.json` 的 `verify:expansion-p2` 脚本别名，指向既有 `scripts/verify_expansion_p2.mjs`，便于后续复跑扩张 P2 契约。
- `public/index.html` 从 `38,984` 行降至 `38,887` 行；split-map 前端函数级块从 `1,443` 降至 `1,441`，当前最大前端块仍为 `runOtaDiagnosisHotelFetch`（`265` 行）。
- 当前自审计：完整目录约 `261.14 MB`，不含 `.git` 约 `92.12 MB`，不含 `.git` 和依赖约 `62.93 MB`；Git 跟踪文件约 `17.99 MB` / `612` 个；代码范围 `369` 个文件，`187,470` 行，非空 `171,724` 行。
- 已验证：`node --check public\expansion-static-options.js`、`node --check scripts\verify_expansion_p2.mjs`、`node scripts\verify_expansion_p2.mjs`、`npm.cmd run verify:expansion-p2`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十八刀拆分

- 扩展 `public/simulation-static.js`，承载转让时机数据口径检查构建器 `buildTransferTimingDataCheck`，覆盖数据断档、口径冲突、疑似采集异常和正常状态。
- `public/index.html` 只保留 `transferTimingDataCheck` computed 名称及 `transferTimingForm` 运行态输入；转让时机请求、结果保存、历史记录、复用、归档、AI 判断和 OTA 原始数据路径均未迁移。
- 更新 `scripts/verify_simulation_p2.mjs`，要求入口通过 `requireSimulationStatic('buildTransferTimingDataCheck')` 显式读取构建器，禁止口径检查长逻辑重新内联，并在 VM 中校验正常、断档、疑似采集异常三种样例输出。
- 补齐 `package.json` 的 `verify:simulation-p2` 脚本别名，指向既有 `scripts/verify_simulation_p2.mjs`。
- `public/index.html` 从 `38,887` 行降至 `38,797` 行；split-map 中 `transfer` 域 span 从 `364` 行降至 `274` 行，当前最大前端块仍为 `runOtaDiagnosisHotelFetch`（`265` 行）。
- 当前自审计：完整目录约 `261.74 MB`，不含 `.git` 约 `92.12 MB`，不含 `.git` 和依赖约 `62.93 MB`；Git 跟踪文件约 `18 MB` / `612` 个；代码范围 `369` 个文件，`187,515` 行，非空 `171,766` 行。
- 已验证：`node --check public\simulation-static.js`、`node --check scripts\verify_simulation_p2.mjs`、`npm.cmd run verify:simulation-p2`、`npm.cmd run verify:public-entry`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第四十九刀拆分

- 扩展 `public/notification-static.js`，承载全局通知展示聚合构建器 `buildGlobalNotifications`，覆盖后端通知、OTA 自动采集中、最近采集结果、最近采集记录和数据健康工单提醒。
- `public/index.html` 只保留 `globalNotifications` computed 名称及运行态状态输入；通知接口加载、缺表提示、已读回写、隐藏状态、轮询计时器和 OTA 采集状态来源均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `buildGlobalNotifications({ ... })` 显式读取构建器，禁止通知行聚合循环重新内联，并在 VM 中校验采集中通知、数据健康目标和去重行为。
- `public/index.html` 从 `38,797` 行降至 `38,732` 行；split-map 中 `general` 域 span 从 `7,498` 行降至 `7,433` 行，`globalNotifications` 不再进入最大前端块列表。
- 当前自审计：完整目录约 `262.32 MB`，不含 `.git` 约 `92.13 MB`，不含 `.git` 和依赖约 `62.94 MB`；Git 跟踪文件约 `18 MB` / `612` 个；代码范围 `369` 个文件，`187,604` 行，非空 `171,849` 行。
- 已验证：`node --check public\notification-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十刀拆分

- 扩展 `public/system-static.js`，承载数据配置默认表单构建器 `getDefaultDataConfigForm`，覆盖通用字段、携程/美团账号字段、Cookie/API 诊断字段和广告配置字段默认值。
- `public/index.html` 只保留 `getDefaultDataConfigForm` 的静态读取和调用；数据配置弹窗、类型默认值覆盖、保存请求、测试请求、OTA 采集配置和入库链路均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireSystemStatic('getDefaultDataConfigForm')` 显式读取构建器，禁止默认表单对象重新内联，并在 VM 中校验 OTA 配置默认值和数组隔离。
- `public/index.html` 从 `38,732` 行降至 `38,658` 行；split-map 前端函数级块从 `1,441` 降至 `1,440`，`config` 域 span 从 `607` 行降至 `532` 行。
- 当前自审计：完整目录约 `262.9 MB`，不含 `.git` 约 `92.13 MB`，不含 `.git` 和依赖约 `62.94 MB`；Git 跟踪文件约 `18.01 MB` / `612` 个；代码范围 `369` 个文件，`187,647` 行，非空 `171,891` 行。
- 已验证：`node --check public\system-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十一刀拆分

- 扩展 `public/ctrip-static.js`，承载携程 Profile 浏览器采集的请求体构建器 `buildCtripBrowserCapturePayload`、section 归一化 `normalizeCtripBrowserCaptureSections` 和错误结果归一化 `normalizeCtripBrowserCaptureErrorResult`。
- `public/index.html` 只保留 `runCtripBrowserCapture()` 的运行态职责：选择酒店、读取/补全配置、发起 `/online-data/capture-ctrip-browser` 请求、更新 UI 状态和刷新入库结果；Profile 采集接口、绑定数据源、入库和数据健康刷新链路均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic('buildCtripBrowserCapturePayload')` 显式读取构建器，禁止 section 归一化和错误归一化重新内联，并在 VM 中校验请求体默认值、section 归一化和 partial_capture 错误证据保留。
- `public/index.html` 从 `38,658` 行降至 `38,632` 行；split-map 前端函数级块从 `1,440` 降至 `1,438`；`runCtripBrowserCapture` 当前为 `105` 行。
- 当前自审计：完整目录约 `263.49 MB`，不含 `.git` 约 `92.14 MB`，不含 `.git` 和依赖约 `62.95 MB`；Git 跟踪文件约 `18.02 MB` / `612` 个；代码范围 `369` 个文件，`187,770` 行，非空 `172,014` 行。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十二刀拆分

- 扩展 `public/ota-diagnosis-static.js`，承载 OTA 诊断采集前的纯任务构建逻辑：`buildOtaDiagnosisFetchContext` 和 `buildOtaDiagnosisFetchTasks`，覆盖携程经营、携程流量、携程 Cookie API、美团排名和美团流量任务。
- `public/index.html` 只保留 `runOtaDiagnosisHotelFetch()` 的运行态职责：读取保存配置、补充通用 Cookie、探测携程 Profile、执行各采集任务、保留失败结果和返回同步摘要；OTA 诊断接口、采集接口、入库、失败提示和后续 AI 诊断链路均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireOtaDiagnosisStatic('buildOtaDiagnosisFetchContext')` 和 `requireOtaDiagnosisStatic('buildOtaDiagnosisFetchTasks')` 显式读取构建器，禁止任务推入 helper 和美团任务列表重新内联，并在 VM 中校验保存配置任务覆盖、核心预设来源和通用 Cookie 证据。
- `public/index.html` 从 `38,632` 行降至 `38,438` 行；split-map 前端函数级块从 `1,438` 降至 `1,435`；`ota` 域 span 从 `679` 行降至 `515` 行；`runOtaDiagnosisHotelFetch` 从 `265` 行降至 `100` 行。
- 当前自审计：完整目录约 `264.08 MB`，不含 `.git` 约 `92.15 MB`，不含 `.git` 和依赖约 `62.96 MB`；Git 跟踪文件约 `18.03 MB` / `612` 个；代码范围 `369` 个文件，`187,930` 行，非空 `172,175` 行。
- 已验证：`node --check public\ota-diagnosis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十三刀拆分

- 扩展 `public/meituan-static.js`，承载美团批量榜单采集的纯构建逻辑：`buildMeituanBatchFetchTasks`、`buildMeituanBatchFetchResultEntry` 和 `buildMeituanDisplayModelPayload`。
- `public/index.html` 只保留 `fetchMeituanData()` 的运行态职责：校验酒店与授权、应用美团配置、逐个请求 `/online-data/fetch-meituan`、更新保存数量、调用展示模型、刷新历史和展示状态；美团采集接口、入库、展示模型接口和失败提示链路均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireMeituanStatic('buildMeituanBatchFetchTasks')` 显式读取构建器，禁止榜单类型、榜单标签和展示模型 payload 重新内联，并在 VM 中校验自定义日期、四榜单任务、成功/失败结果和展示模型 payload。
- `public/index.html` 从 `38,438` 行降至 `38,381` 行；split-map 中 `meituan` 域 span 从 `1,257` 行降至 `1,197` 行；`fetchMeituanData` 从 `164` 行降至 `104` 行。
- 当前自审计：完整目录约 `264.66 MB`，不含 `.git` 约 `92.16 MB`，不含 `.git` 和依赖约 `62.97 MB`；Git 跟踪文件约 `18.04 MB` / `612` 个；代码范围 `369` 个文件，`188,055` 行，非空 `172,304` 行。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十四刀拆分

- 新增 `public/ai-analysis-static.js`，承载携程 OTA AI 分析的纯展示/构建逻辑：状态/优先级文案、问题酒店归一化、错误脱敏、批次切分、采集酒店 payload、分组汇总、fallback 报告、进度对象、批次状态、综合汇总请求体和历史记录构建器。
- `public/index.html` 只保留 AI 分析运行态职责：选中酒店校验、日期校验、调用 `/agent/analyze-captured-ota-data`、失败拆分重试、调用 `/agent/summarize-captured-ota-analysis`、UI 状态更新和结果复制；AI 接口、OTA 数据来源、入库链路和缺失/失败展示口径均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口加载 `ai-analysis-static.js` 并通过 `requireAiAnalysisStatic(...)` 显式读取核心构建器，禁止状态文案、chunk、captured payload、summary request 和 fallback report 逻辑重新内联，并在 VM 中校验 payload、批次状态、汇总请求、fallback 脱敏和历史记录样例。
- `public/index.html` 从 `38,381` 行降至 `38,101` 行；split-map 前端函数级块从 `1,435` 降至 `1,407`；`ai` 域 span 从 `1,516` 行降至 `1,257` 行；`startAiAnalysis` 当前为 `145` 行。
- 当前自审计：完整目录约 `265.24 MB`，不含 `.git` 约 `92.16 MB`，不含 `.git` 和依赖约 `62.97 MB`；Git 跟踪文件约 `18.03 MB` / `612` 个；代码范围 `369` 个文件，`187,921` 行，非空 `172,193` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:clean`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十五刀拆分

- 扩展 `public/ctrip-static.js`，承载携程 Profile 缺口/存疑字段重抓的纯状态构建器：初始状态、采集后刷新状态、成功/部分成功结果、错误结果和中断状态。
- `public/index.html` 只保留 `recheckCtripProfileMismatchedFields()` 的运行态职责：字段筛选、是否可启动浏览器重抓、调用 `runCtripBrowserCapture()`、请求 `/online-data/recheck-ctrip-profile-mismatched-fields`、刷新字段响应、toast 和计时器收尾；Profile 采集、字段保存、样本刷新、二次确认和待补解析口径均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic(...)` 显式读取重抓状态构建器，禁止结果文案和中断状态重新内联，并在 VM 中校验采集失败后刷新、仅刷新历史获取值、错误和中断状态样例。
- `public/index.html` 从 `38,101` 行降至 `38,088` 行；`public/ctrip-static.js` 当前约 `710` 行；`recheckCtripProfileMismatchedFields` 从 `126` 行降至 `108` 行；`ctrip` 域 span 从 `3,566` 行降至 `3,548` 行。
- 当前自审计：完整目录约 `265.83 MB`，不含 `.git` 约 `92.17 MB`，不含 `.git` 和依赖约 `62.98 MB`；Git 跟踪文件约 `18.06 MB` / `613` 个；代码范围 `370` 个文件，`188,514` 行，非空 `172,749` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十六刀拆分

- 扩展 `public/ctrip-static.js`，承载携程经营数据抓取的纯构建逻辑：默认日期范围、请求体、成功响应 payload、最近抓取 meta 和 raw failure 展示对象。
- `public/index.html` 只保留 `fetchCtripData()` 的运行态职责：登录/酒店/配置/授权校验、调用 `/online-data/fetch-ctrip`、刷新携程展示模型、更新 AI 分析酒店列表、刷新历史和最新数据；携程采集接口、入库、错误处理和缺失状态展示口径均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic(...)` 显式读取 fetch 构建器，禁止默认日期、请求体和 raw failure 重新内联，并在 VM 中校验默认昨天日期、自定义日期、请求字段、跨日响应 payload、最近抓取 meta 和 raw 截断证据。
- `public/index.html` 从 `38,088` 行降至 `38,070` 行；`public/ctrip-static.js` 当前约 `791` 行；`fetchCtripData` 从 `126` 行降至 `103` 行；`ctrip` 域 span 从 `3,548` 行降至 `3,525` 行。
- 当前自审计：完整目录约 `266.42 MB`，不含 `.git` 约 `92.18 MB`，不含 `.git` 和依赖约 `62.99 MB`；Git 跟踪文件约 `18.07 MB` / `613` 个；代码范围 `370` 个文件，`188,667` 行，非空 `172,897` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十七刀拆分

- 扩展 `public/ctrip-static.js`，承载携程最新入库快照的纯模型构建器 `buildLatestCtripSnapshotModel`，统一切分 `rank`、`traffic`、`review`、`metadata` 和 `onlineResult`。
- `public/index.html` 只保留 `applyLatestCtripSnapshot()` 的运行时职责：写入 `ctripLatestMeta`、刷新携程经营展示行、流量展示行、点评聚合结果和 `onlineDataResult`；最新快照接口、入库数据口径、历史数据读取、缺失/失败状态展示均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic('buildLatestCtripSnapshotModel')` 显式读取快照模型构建器，禁止 latest 快照切片逻辑重新内联，并在 VM 中校验 rank、traffic、review、onlineResult 与空快照行为。
- `public/index.html` 从 `38,070` 行降至 `38,058` 行；`public/ctrip-static.js` 当前 `836` 行；`ctrip` 领域 span 从 `3,525` 行降至 `3,512` 行。
- 当前自审计：完整目录约 `267.01 MB`，不含 `.git` 约 `92.19 MB`，不含 `.git` 和依赖约 `63 MB`，Git 跟踪文件约 `18.08 MB` / `613` 个；代码范围 `370` 个文件，`188,752` 行，非空 `172,980` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十八刀拆分

- 扩展 `public/ctrip-static.js`，承载携程流量抓取请求体构建器 `buildCtripTrafficFetchRequestBody` 和成功响应展示模型构建器 `buildCtripTrafficResponseModel`。
- `public/index.html` 只保留 `fetchCtripTrafficData()` 的运行时职责：酒店/配置/Cookie/日期校验、调用 `/online-data/ctrip/traffic`、写入流量展示行、刷新历史和 toast 状态；携程流量接口、入库行为、展示字段、失败处理和最新快照兜底均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic(...)` 显式读取流量构建器，禁止流量请求体、URL trim 和响应展示模型重新内联，并在 VM 中校验 URL 裁剪、空酒店 ID、traffic rows、display rows、raw response 和 derived analysis。
- `public/index.html` 从 `38,058` 行降至 `38,038` 行；`public/ctrip-static.js` 当前 `890` 行；`ctrip` 领域 span 从 `3,512` 行降至 `3,490` 行。
- 当前自审计：完整目录约 `267.6 MB`，不含 `.git` 约 `92.2 MB`，不含 `.git` 和依赖约 `63.01 MB`，Git 跟踪文件约 `18.09 MB` / `613` 个；代码范围 `370` 个文件，`188,850` 行，非空 `173,076` 行；`self:clean` 已清理 `runtime` 约 `0.03 MB`，当前默认可回收量为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:clean:dry-run`、`npm.cmd run self:clean`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第五十九刀拆分

- 扩展 `public/ctrip-static.js`，承载携程今日概况和流量概要直连共用的请求体构建器 `buildCtripOverviewFetchRequestBody`。
- `public/index.html` 只保留 `fetchCtripOverviewData()` / `fetchCtripFlowOverviewData()` 的运行时职责：酒店/配置/Cookie/Request URL 校验、调用 `/online-data/fetch-ctrip-overview`、写入结果、刷新最新快照和历史；携程概况接口、入库行为、展示字段、失败提示和 OTA 渠道范围均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic('buildCtripOverviewFetchRequestBody')` 显式读取构建器，禁止概况/流量概要请求体和 method fallback 重新内联，并在 VM 中校验表单 method 优先级、默认 GET 方法、请求 URL、payload、spidertoken 和 data_date。
- `public/index.html` 从 `38,038` 行降至 `38,037` 行；`public/ctrip-static.js` 当前约 `910` 行；`ctrip` 领域 span 从 `3,490` 行降至 `3,488` 行。
- 当前自审计：完整目录约 `268.19 MB`，不含 `.git` 约 `92.21 MB`，不含 `.git` 和依赖约 `63.02 MB`，Git 跟踪文件约 `18.09 MB` / `613` 个；代码范围 `370` 个文件，`188,925` 行，非空 `173,150` 行；`self:clean` 已清理 `runtime` 约 `0.04 MB`，当前默认可回收量为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:clean`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`；提交前仍需跑完整门禁。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十刀拆分

- 扩展 `public/ctrip-static.js`，承载携程广告效果报表的默认接口、URL 判定、API 类型归一化和请求体构建器 `buildCtripAdsFetchRequestBody`。
- `public/index.html` 只保留 `fetchCtripAdsData()` 的运行时职责：酒店/配置/Cookie/URL/日期校验、调用 `/online-data/fetch-ctrip-ads`、写入结果、刷新最新快照和历史；携程广告接口、入库行为、展示字段、失败提示和效果报表单一口径均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口通过 `requireCtripStatic(...)` 显式读取广告 URL guard 和请求体 builder，禁止默认 URL、URL guard、广告请求体重新内联，并在 VM 中校验默认接口、页面 URL 排除、`effect_report` 归一化和请求字段。
- 同步更新 `tests/automation/manual_minimum_credential_ui.test.mjs`，让手工凭据 UI 契约适配当前静态 builder 口径，并覆盖美团竞品摘要加载不等待全店汇总的并行优化。
- `public/index.html` 当前为 `38,053` 行；前端函数级块从 `1,407` 降至 `1,405`；`ctrip` 领域 span 从 `3,488` 行降至 `3,471` 行；`fetchCtripAdsData` 当前为 `72` 行。
- 当前自审计：完整目录约 `268.79 MB`，不含 `.git` 约 `92.22 MB`，不含 `.git` 和依赖约 `63.03 MB`，Git 跟踪文件约 `18.1 MB` / `613` 个；代码范围 `370` 个文件，`189,043` 行，非空 `173,268` 行；当前默认可回收量为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:e2e-contracts`、`node --test tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十一刀拆分

- 扩展 `public/ctrip-static.js`，承载携程 Cookie API 采集请求体构建器 `buildCtripCookieApiFetchRequestBody`。
- `public/index.html` 只保留 `runCtripCookieApiCapture()` 的运行时职责：目标酒店校验、Request URL / endpoints JSON 校验、登录会话/Profile 解析、`/online-data/fetch-ctrip-cookie-api` 请求执行、结果写入、历史刷新与 toast 状态；携程 Cookie API 接口、入库行为、字段展示和缺失/失败状态口径均未迁移。
- 更新 `scripts/verify_e2e_contracts.mjs`：要求入口通过 `requireCtripStatic('buildCtripCookieApiFetchRequestBody')` 显式读取 builder，禁止 `profile_id`、method 归一化、payload trim 等请求体细节重新内联回 `public/index.html`，并在 VM 中校验 Cookie API 请求字段样例。
- 当前保存点也保留美团摘要加载保护的延续修正：`scheduleMeituanRankingSummaryRefresh`、配置详情 single-flight、配置列表 single-flight、进入美团排名页后的延迟加载，避免全店竞品摘要阻塞页面切换。
- 当前 split-map：`public/index.html` 为 `38104` 行；前端函数级块 `1409` 个；`ctrip` 领域 span 为 `3472` 行；`meituan` 领域 span 为 `1246` 行；`runCtripCookieApiCapture` 当前为 `85` 行。
- 当前自审计：完整目录约 `269.4 MB`；不含 `.git` 约 `92.23 MB`；不含 `.git` 和依赖约 `63.04 MB`；Git 跟踪文件约 `18.12 MB` / `613` 个；代码范围 `370` 个文件，`189178` 行，非空 `173401` 行；默认可清理目标为 `0`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:clean`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十二刀拆分

- 扩展 `public/ai-analysis-static.js`，承载携程 OTA AI 分析酒店列表聚合与选中项过滤构建器 `buildCtripAiAnalysisHotelSelection`。
- `public/index.html` 只保留 `updateAiAnalysisHotelList()` 的 Vue 状态写入：读取当前携程酒店列表、写入 `aiAnalysisHotelList`、过滤 `aiSelectedHotels`；同店多榜单指标合并、曝光/访客取最大、排名取更优等纯数据整形已移入静态模块。
- 本轮未改 AI 分析接口、日期校验、`/agent/analyze-captured-ota-data` 调用、汇总报告、历史记录、OTA 入库链路或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`：要求入口通过 `requireAiAnalysisStatic('buildCtripAiAnalysisHotelSelection')` 显式读取 builder，禁止携程 AI 酒店聚合细节重新内联，并在 VM 中校验同店多榜单合并和无效选中项清除。
- 当前 split-map：`public/index.html` 为 `38043` 行；前端函数级块 `1408` 个；`general` 领域 span 为 `7165` 行；`ai` 领域 span 为 `1275` 行；`startAiAnalysis` 仍为 `145` 行。
- 当前自审计：完整目录约 `270 MB`；不含 `.git` 约 `92.23 MB`；不含 `.git` 和依赖约 `63.04 MB`；Git 跟踪文件约 `18.12 MB` / `613` 个；代码范围 `370` 个文件，`189235` 行，非空 `173460` 行；默认可清理目标为 `0`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十三刀拆分

- 扩展 `public/ai-analysis-static.js`，承载携程 OTA AI 分析运行计划构建器 `buildCapturedOtaAnalysisRunPlan`。
- `public/index.html` 中 `startAiAnalysis()` 不再直接拼接 `hotelsPayload`、group size、progress 和 batch results；入口只读取 run plan 后继续执行分组请求、重试、汇总报告和 UI 状态写入。
- 本轮未改 `/agent/analyze-captured-ota-data`、`/agent/summarize-captured-ota-analysis`、日期校验、AI 模型选择、汇总 fallback、历史记录或 OTA 入库链路。
- 同一保存点保留美团目标酒店切换时的配置同步保护：排名页目标酒店切换由 hotelId watcher 统一应用当前配置、配置详情请求返回前后校验目标酒店是否已变化，并补充手工凭据 UI 契约断言。
- 更新 `scripts/verify_e2e_contracts.mjs`：要求入口通过 `requireAiAnalysisStatic('buildCapturedOtaAnalysisRunPlan')` 显式读取 builder，禁止运行计划细节重新内联，并在 VM 中校验 DeepSeek Pro 3 家一组的分组、进度对象和批次 key。
- 当前 split-map：`public/index.html` 为 `38043` 行；前端函数级块 `1408` 个；`ai` 领域 span 为 `1272` 行；`meituan` 领域 span 为 `1246` 行；`startAiAnalysis` 当前为 `144` 行。
- 当前自审计：完整目录约 `271.19 MB`；不含 `.git` 约 `92.24 MB`；不含 `.git` 和依赖约 `63.05 MB`；Git 跟踪文件约 `18.13 MB` / `613` 个；代码范围 `370` 个文件，`189313` 行，非空 `173537` 行；默认可清理目标为 `0`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:e2e-contracts`、`node --test tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十四刀拆分

- 扩展 `public/ai-analysis-static.js`，承载 AI 报告 HTML 清洗与转文本工具：`sanitizeAiReportHtml` 和 `aiReportHtmlToText`。
- `public/index.html` 仅通过 `requireAiAnalysisStatic(...)` 显式读取上述工具；美团 AI 报告展示、复制与历史回显路径保持原入口函数名和状态写入不变。
- 本轮不移动 AI 分析请求、汇总报告、历史记录、日期校验、模型选择、OTA 入库链路或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口读取提取后的 sanitizer/text converter，要求静态模块导出这两个函数，并禁止对应实现重新内联回 `public/index.html`。
- 当前 split-map：`public/index.html` 从 `38043` 行降至 `38009` 行；前端函数级块从 `1408` 降至 `1406`；`ai` 领域 span 从 `1272` 行降至 `1238` 行；`startAiAnalysis` 仍为 `144` 行。
- 当前自审计：完整目录约 `271.76 MB`；不含 `.git` 约 `92.25 MB`；不含 `.git` 和依赖约 `63.06 MB`；Git 跟踪文件约 `18.13 MB` / `613` 个；代码范围 `370` 个文件，`189325` 行，非空 `173549` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成，原因：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十五刀拆分

- 扩展 `public/ai-analysis-static.js`，承载美团 AI 分析酒店 key、酒店列表去重、选中酒店解析、请求体构建和历史记录构建：`getMeituanAiAnalysisHotelKey`、`buildMeituanAiAnalysisHotelList`、`resolveMeituanAiSelectedData`、`buildMeituanAiAnalysisRequestBody`、`buildMeituanAiAnalysisHistoryRecord`。
- `public/index.html` 只保留美团 AI 分析的运行时职责：选择校验、`/online-data/ai-analysis` 请求、toast、结果写入、历史数组裁剪、查看和复制行为；本轮不迁移接口、存储路径、OTA 数据范围或缺失/失败状态展示。
- 同一保存点也保留并验证当前美团数据源匹配修正：配置列表加载中显示“正在匹配美团数据源...”，未加载完成前不误报未配置；`findMeituanConfigByHotelId()` 先按系统酒店 ID 匹配，再按规范化酒店名/配置名匹配。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取新美团 AI helper，要求静态模块导出对应函数，禁止请求体/历史命名/key 构建重新内联，并在 VM 中验证美团酒店去重、选中数据、请求体和历史记录样本。
- 更新 `tests/automation/manual_minimum_credential_ui.test.mjs`，覆盖美团配置列表 loading 状态、酒店名兜底匹配和未配置提示的加载边界。
- 当前 split-map：`public/index.html` 为 `37997` 行，前端函数级块 `1407` 个；`ai` 领域 span 为 `1242` 行，`meituan` 领域 span 为 `1221` 行；`startAiAnalysis` 仍为 `144` 行。
- 当前自审计：完整目录约 `272.36 MB`；不含 `.git` 约 `92.25 MB`；不含 `.git` 和依赖约 `63.06 MB`；Git 跟踪文件约 `18.14 MB` / `613` 个；代码范围 `370` 个文件，`189427` 行，非空 `173646` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`node --test tests\automation\manual_minimum_credential_ui.test.mjs`、`git diff --check`、`npm.cmd run self:clean`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十六刀拆分

- 扩展 `public/meituan-static.js`，承载美团批量榜单抓取前的纯输入校验：`validateMeituanBatchFetchInput`。
- `public/index.html` 中 `fetchMeituanData()` 继续负责目标酒店确认、数据源配置确认、`applyMeituanHotelConfig()`、`/online-data/fetch-meituan` 请求、展示模型构建、历史刷新和 toast 状态；本轮不迁移接口、保存、展示模型、AI 酒店列表刷新或 OTA 入库链路。
- 新校验 helper 明确返回缺授权、缺平台接口标识/门店标识、缺时间维度、自定义日期缺失等状态，不用兜底值隐藏缺失原因。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `validateMeituanBatchFetchInput`，要求静态模块导出该函数，禁止美团批量抓取输入校验重新内联，并在 VM 中验证缺授权、缺门店标识、自定义日期缺失和成功路径样本。
- 当前 split-map：`public/index.html` 从 `37997` 行降至 `37982` 行；`meituan` 领域 span 从 `1221` 行降至 `1205` 行；`fetchMeituanData` 从 `104` 行降至 `88` 行。
- 当前自审计：完整目录约 `272.96 MB`；不含 `.git` 约 `92.26 MB`；不含 `.git` 和依赖约 `63.07 MB`；Git 跟踪文件约 `18.15 MB` / `613` 个；代码范围 `370` 个文件，`189502` 行，非空 `173720` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十七刀拆分

- 扩展 `public/ai-analysis-static.js`，承载携程 OTA AI 分析中的纯选中酒店解析和批次结果汇总：`resolveAiSelectedData`、`buildCapturedOtaGroupOutcome`。
- `public/index.html` 中 `startAiAnalysis()` 继续负责日期校验、`/agent/analyze-captured-ota-data` 分组请求、重试、`/agent/summarize-captured-ota-analysis` 汇总请求、UI 状态写入和历史记录；本轮不迁移 AI 请求、汇总接口、模型选择、历史裁剪、OTA 存储或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取新 helper，要求静态模块导出对应函数，禁止选中酒店查找、成功/失败组过滤和失败原因拼接重新内联，并在 VM 中验证无效选中 key 清理、成功组、失败组和失败原因样本。
- 当前 split-map：`public/index.html` 从 `37982` 行降至 `37975` 行；`ai` 领域 span 从 `1242` 行降至 `1235` 行；`startAiAnalysis` 从 `144` 行降至 `135` 行。
- 当前自审计：完整目录约 `273.56 MB`；不含 `.git` 约 `92.27 MB`；不含 `.git` 和依赖约 `63.08 MB`；Git 跟踪文件约 `18.15 MB` / `613` 个；代码范围 `370` 个文件，`189551` 行，非空 `173767` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十八刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 分析启动校验 `validateCapturedOtaAiAnalysisStart()`，统一校验已选酒店、选中数据、开始/结束日期和日期顺序。
- `public/index.html` 中 `startAiAnalysis()` 继续负责运行态编排：解析选中酒店、调用启动校验并显示 toast、执行 `/agent/analyze-captured-ota-data` 分组请求、失败重试、`/agent/summarize-captured-ota-analysis` 汇总、UI 状态写入和历史记录；本轮不迁移 AI 请求、汇总接口、模型选择、OTA 存储或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取启动校验 helper，要求静态模块导出该 helper，禁止酒店/日期启动校验重新内联，并在 VM 中验证未选酒店、选中数据缺失、日期缺失、日期倒置和合法启动样例。
- 当前 split-map：`public/index.html` 从 `37975` 行降至 `37968` 行；`ai` 领域 span 从 `1235` 行降至 `1228` 行；`startAiAnalysis` 从 `135` 行降至 `127` 行。
- 当前自审计：完整目录约 `274.16 MB`；不含 `.git` 约 `92.28 MB`；不含 `.git` 和依赖约 `63.09 MB`；Git 跟踪文件约 `18.16 MB` / `613` 个；代码范围 `370` 个文件，`189617` 行，非空 `173834` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-10 前端第六十九刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 分析收尾构建器 `buildCapturedOtaAnalysisCompletion()`，统一生成报告复制 HTML 和裁剪后的历史记录。
- `public/index.html` 中 `startAiAnalysis()` 仅保留运行态写回：调用 completion helper 后写入 `aiAnalysisResult` 和 `aiAnalysisHistory`；本轮不迁移 `/agent/analyze-captured-ota-data`、`/agent/summarize-captured-ota-analysis`、失败重试、模型选择、OTA 存储或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 completion helper，要求静态模块导出该 helper，禁止历史记录 unshift 和长度裁剪重新内联，并在 VM 中验证报告 HTML、历史摘要和历史裁剪样例。
- 当前 split-map：`public/index.html` 从 `37968` 行降至 `37965` 行；`ai` 领域 span 从 `1228` 行降至 `1225` 行；`startAiAnalysis` 从 `127` 行降至 `124` 行。
- 当前自审计：完整目录约 `274.77 MB`；不含 `.git` 约 `92.28 MB`；不含 `.git` 和依赖约 `63.09 MB`；Git 跟踪文件约 `18.17 MB` / `613` 个；代码范围 `370` 个文件，`189669` 行，非空 `173885` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 汇总响应构建器 `buildCapturedOtaSummaryResponseResult()`，统一处理汇总接口成功响应、失败响应和网络异常转基础综合报告。
- `public/index.html` 中 `startAiAnalysis()` 继续负责 `/agent/summarize-captured-ota-analysis` 请求本身，只把响应转换交给静态 helper，并写回 `aiAnalysisCapturedReport` / `aiAnalysisProcess`；本轮不迁移 AI 请求、失败重试、模型选择、OTA 存储或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 summary response helper，要求静态模块导出该 helper，禁止 `summaryRes.code === 200`、`summaryData` 提取和汇总失败原因重新内联，并在 VM 中验证成功响应、失败响应和敏感错误脱敏样例。
- 当前 split-map：`public/index.html` 从 `37965` 行降至 `37962` 行；`ai` 领域 span 从 `1225` 行降至 `1222` 行；`startAiAnalysis` 从 `124` 行降至 `122` 行。
- 当前自审计：完整目录约 `275.37 MB`；不含 `.git` 约 `92.29 MB`；不含 `.git` 和依赖约 `63.10 MB`；Git 跟踪文件约 `18.18 MB` / `613` 个；代码范围 `370` 个文件，`189733` 行，非空 `173948` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十一刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 汇总上下文构建器 `buildCapturedOtaSummaryContext()`，统一生成 `selectedHotelCount`、`selectedCount`、完成/失败酒店数、分组数、成功组和失败组。
- `public/index.html` 中 `startAiAnalysis()` 不再重复从 `hotelsPayload`、`aiAnalysisProgress` 和 `aiAnalysisBatchResults` 里拼汇总上下文；汇总请求体、汇总响应兜底和 completion 历史记录都复用同一份 `summaryContext`。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 summary context helper，要求静态模块导出该 helper，禁止选中数量、分组数量和完成数量上下文重新内联，并在 VM 中验证字符串数字归一化、成功组和失败组样例。
- 当前 split-map：`public/index.html` 从 `37962` 行降至 `37956` 行；`ai` 领域 span 从 `1222` 行降至 `1216` 行；`startAiAnalysis` 从 `122` 行降至 `115` 行。
- 当前自审计：完整目录约 `275.97 MB`；不含 `.git` 约 `92.30 MB`；不含 `.git` 和依赖约 `63.11 MB`；Git 跟踪文件约 `18.18 MB` / `613` 个；代码范围 `370` 个文件，`189766` 行，非空 `173980` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`git diff --check`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十二刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 分组运行态更新工具 `applyCapturedOtaGroupRunState()`，统一处理成功组、失败组、重试结果的 `groupState` 和完成/失败计数写入。
- `public/index.html` 中 `startAiAnalysis()` 仍保留日期校验、`/agent/analyze-captured-ota-data` 分组请求、失败重试、`/agent/summarize-captured-ota-analysis` 汇总请求、UI 状态写入和历史记录；本轮只委托分组状态与计数更新。
- 本轮不迁移 AI 请求、汇总接口、模型选择、捕获 OTA 存储、历史裁剪或缺失/失败状态展示口径；OTA 渠道范围不变。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 group run-state helper，要求静态模块导出该 helper，禁止结果/计数更新逻辑重新内联，并在 VM 中验证成功分组和重试失败计数样例。
- 当前 split-map：`public/index.html` 从 `37956` 行降至 `37953` 行；`ai` 领域 span 从 `1216` 行降至 `1213` 行；`startAiAnalysis` 从 `115` 行降至 `111` 行。
- 本轮执行 `npm.cmd run self:clean:dry-run` 命中 `runtime` 37 个本地运行产物文件，预计释放 `0.04 MB`；随后 `npm.cmd run self:clean` 已删除该本地运行产物目标。
- 当前自审计：完整目录约 `276.58 MB`；不含 `.git` 约 `92.30 MB`；不含 `.git` 和依赖约 `63.11 MB`；Git 跟踪文件约 `18.19 MB` / `613` 个；代码范围 `370` 个文件，`189,824` 行，非空 `174,037` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node scripts\verify_frontend_display_boundary.mjs`、`npm.cmd run self:clean:dry-run`、`npm.cmd run self:clean`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十三刀拆分

- 扩展 `public/ctrip-static.js`，新增携程 Profile 重抓运行上下文构建器 `buildCtripProfileRecheckRunContext()`，统一生成去重后的重抓模块、是否可启动浏览器重抓、初始状态、开始提示和 `/online-data/recheck-ctrip-profile-mismatched-fields` 请求 options。
- `public/index.html` 中 `recheckCtripProfileMismatchedFields()` 继续负责字段筛选、是否等待获取值、启动浏览器重抓、调用重抓接口、刷新字段样本、toast 和计时器收尾；本轮只委托重抓上下文与请求 options 构造。
- 本轮不迁移携程 Profile 采集、字段保存、样本刷新、二次确认、待补解析口径或缺失/失败状态展示；OTA 渠道范围不变。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 run-context helper，要求静态模块导出该 helper，禁止重抓模块去重、可重抓判断和重抓请求 options 重新内联，并在 VM 中验证模块去重、默认模块、可重抓状态和 POST body 样例。
- 当前 split-map：`public/index.html` 从 `37953` 行降至 `37940` 行；前端函数级块从 `1407` 降至 `1406`；`ctrip` 领域 span 从 `3472` 行降至 `3459` 行；`recheckCtripProfileMismatchedFields` 从 `108` 行降至 `102` 行。
- 当前自审计：完整目录约 `277.18 MB`；不含 `.git` 约 `92.31 MB`；不含 `.git` 和依赖约 `63.12 MB`；Git 跟踪文件约 `18.20 MB` / `613` 个；代码范围 `370` 个文件，`189,878` 行，非空 `174,091` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十四刀拆分

- 扩展 `public/ctrip-static.js`，新增携程浏览器采集目标上下文构建器 `buildCtripBrowserCaptureTargetContext()` 和请求上下文构建器 `buildCtripBrowserCaptureRequestContext()`，统一处理目标酒店选择、Profile 缺失错误、hotelId 解析、Cookie/DataDate 取值和采集 payload 生成。
- `public/index.html` 中 `runCtripBrowserCapture()` 继续负责加载携程配置、补全密钥、调用 `/online-data/capture-ctrip-browser`、写回 Profile 状态、刷新最新快照/历史/数据健康和错误展示；本轮只委托采集目标与请求 payload 构造。
- 本轮不迁移携程浏览器采集接口、Profile 登录保存、数据源绑定、入库刷新、数据健康刷新或缺失/失败状态展示；OTA 渠道范围不变。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 target/request context helper，要求静态模块导出这些 helper，禁止 hotelId 和 Cookie payload 逻辑重新内联，并在 VM 中验证缺失目标、目标回退、Profile 缺失、payload 字段和 sections 样例。
- 当前 split-map：`public/index.html` 从 `37940` 行降至 `37936` 行；`ctrip` 领域 span 从 `3459` 行降至 `3454` 行；`runCtripBrowserCapture` 从 `105` 行降至 `100` 行。
- 当前自审计：完整目录约 `277.79 MB`；不含 `.git` 约 `92.32 MB`；不含 `.git` 和依赖约 `63.13 MB`；Git 跟踪文件约 `18.20 MB` / `613` 个；代码范围 `370` 个文件，`189,984` 行，非空 `174,197` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十五刀拆分

- 扩展 `public/ctrip-static.js`，新增携程普通榜单采集请求上下文构建器 `buildCtripFetchRequestContext()`，集中处理平台授权内容裁剪、nodeId 请求资源标识、日期范围、请求 body 和 debug meta。
- `public/index.html` 中 `fetchCtripData()` 继续负责登录/目标酒店检查、携程配置密钥加载、接口调用、成功数据展示、历史刷新、最近快照刷新和失败状态展示；本轮只委托请求上下文构造。
- 本轮不迁移 `/online-data/fetch-ctrip` 接口、保存逻辑、展示模型、历史刷新、最近快照刷新、缺失/失败状态展示或 OTA 渠道范围；nodeId 仅作为请求资源标识保留，不作为 OTA hotelId 回退。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 request context helper，要求静态模块导出该 helper，禁止 cookies、nodeId、日期范围和 request body 逻辑重新内联，并在 VM 中验证请求字段、缺失授权和 debug meta 样例。
- 更新 `tests/automation/manual_minimum_credential_ui.test.mjs`，将已下沉到静态模块的携程 nodeId 请求字段和美团一次性门店标识文案断言对齐到实际承载模块。
- 当前 split-map：`public/index.html` 从 `37936` 行降至 `37928` 行；`ctrip` 领域 span 从 `3454` 行降至 `3447` 行；`fetchCtripData` 从 `103` 行降至 `96` 行。
- 当前自审计：完整目录约 `278.42 MB`；不含 `.git` 约 `92.33 MB`；不含 `.git` 和依赖约 `63.14 MB`；Git 跟踪文件约 `18.21 MB` / `613` 个；代码范围 `370` 个文件，`190,046` 行，非空 `174,259` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`node --test tests\automation\manual_minimum_credential_ui.test.mjs`、`node --test tests\automation\ctrip_store_data_overview.test.mjs`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十六刀拆分

- 扩展 `public/ai-analysis-static.js`，新增 OTA AI 分析启动上下文 `buildCapturedOtaAnalysisStartContext()` 和运行上下文 `buildCapturedOtaAnalysisRunContext()`，集中处理选中酒店解析、开始校验、run plan、空抓取数据状态和开始提示文案。
- `public/index.html` 中 `startAiAnalysis()` 继续负责批量请求 `/agent/analyze-captured-ota-data`、失败重试、汇总结果写回、历史记录和错误展示；本轮只委托启动/运行上下文构造。
- 新增局部 helper `requestCapturedOtaSummaryResult()`，把 `/agent/summarize-captured-ota-analysis` 请求、汇总 fallback 和 `summarizing` 状态收口出 `startAiAnalysis()`；不改变 AI 汇总接口、模型选择、报告结构、失败可见性或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 start/run context helper，要求静态模块导出这些 helper，禁止选中数据解析、开始校验和 run plan 逻辑重新内联，并在 VM 中验证成功启动、缺选、空数据和 run context 样例。
- 当前 split-map：`public/index.html` 为 `37929` 行；前端函数级块从 `1406` 升至 `1407`；`ai` 领域 span 从 `1213` 行降至 `1187` 行；`startAiAnalysis` 从 `111` 行降至 `86` 行。
- 当前自审计：完整目录约 `279.04 MB`；不含 `.git` 约 `92.33 MB`；不含 `.git` 和依赖约 `63.14 MB`；Git 跟踪文件约 `18.22 MB` / `613` 个；代码范围 `370` 个文件，`190,132` 行，非空 `174,344` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十七刀拆分

- 扩展 `public/ctrip-static.js`，新增携程 Profile 字段默认表单 `createCtripProfileFieldForm()`、智能默认值 `buildCtripProfileFieldSmartDefaults()` 和保存 payload `buildCtripProfileFieldSavePayload()`。
- `public/index.html` 继续负责 Vue 表单状态、字段保存接口 `/online-data/save-ctrip-profile-field`、字段列表刷新和 toast 展示；本轮只委托字段默认值、来源 key、section、endpoint、value type、unit、storage 字段与待补解析状态构造。
- 本轮不改变携程 Profile 字段保存接口、字段编辑回显、样本核验、二次确认、待补解析状态、缺失/失败可见性或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 Profile 字段表单/智能推断/保存 payload builder，要求静态模块导出这些 builder，禁止默认表单、字段 key hash、endpoint 解析、智能默认值和保存 payload 逻辑重新内联，并在 VM 中验证 section、source key、endpoint、value type、unit、storage 字段和 `needs_parser` 样例。
- 当前 split-map：`public/index.html` 从 `37929` 行降至 `37745` 行；前端函数级块从 `1407` 降至 `1396`；`ctrip` 领域 span 从 `3447` 行降至 `3261` 行。
- 当前自审计：完整目录约 `279.65 MB`；不含 `.git` 约 `92.34 MB`；不含 `.git` 和依赖约 `63.15 MB`；Git 跟踪文件约 `18.23 MB` / `613` 个；代码范围 `370` 个文件，`190,189` 行，非空 `174,411` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十八刀拆分

- 扩展 `public/ctrip-static.js`，新增携程 Profile 缺口/存疑字段重抓流程编排器 `runCtripProfileRecheckFlow()`，统一处理可选浏览器重抓、采集后样本刷新状态、重抓接口响应、成功/失败/中断状态和停止回调。
- `public/index.html` 中 `recheckCtripProfileMismatchedFields()` 只保留运行态输入：请求序号、样本已加载校验、目标字段筛选、计时器、Vue 状态写入、toast、`runCtripBrowserCapture()`、`/online-data/recheck-ctrip-profile-mismatched-fields` 和字段响应回填。
- 本轮不改变携程 Profile 采集接口、字段保存、样本刷新、二次确认、待补解析、缺失/失败状态可见性或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripProfileRecheckFlow()`，要求静态模块导出该流程函数，禁止浏览器重抓、recheck 请求、成功/错误/中断处理重新内联，并在 VM 中验证 capture、request、response、toast 和 stop 回调顺序。
- 当前 split-map：`public/index.html` 从 `37,745` 行降至 `37,684` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `3,261` 行降至 `3,203` 行；`recheckCtripProfileMismatchedFields` 已不再进入最大块列表。
- 当前自审计：完整目录约 `280.27 MB`；不含 `.git` 约 `92.35 MB`；不含 `.git` 和依赖约 `63.16 MB`；Git 跟踪文件约 `18.23 MB` / `613` 个；代码范围 `370` 个文件，`190,289` 行，非空 `174,508` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第七十九刀拆分

- 扩展 `public/ota-diagnosis-static.js`，新增 OTA 诊断补抓流程编排器 `runOtaDiagnosisHotelFetchFlow()`，统一处理门店配置读取、补抓上下文构造、通用携程 Cookie 选择、携程核心预设判定、任务执行和结果汇总。
- `public/index.html` 中 `runOtaDiagnosisHotelFetch()` 只保留现有运行态回调：携程/美团配置查找、已保存 OTA 配置读取、通用 Cookie 读取、携程 Profile 状态探测、Profile 状态写入、请求执行、toast 和 debug 日志。
- 本轮不改变 OTA 诊断接口、携程/美团补抓接口、数据入库、失败项可见性、继续用已入库数据诊断的行为或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runOtaDiagnosisHotelFetchFlow()`，要求静态模块导出该流程函数，禁止补抓上下文、任务构造、通用 Cookie 选择、核心预设判定和结果汇总重新内联，并在 VM 中验证 Profile 核心预设、任务执行、toast 和结果汇总样例。
- 当前 split-map：`public/index.html` 从 `37,684` 行降至 `37,615` 行；前端函数级块保持 `1,396`；`ota` 领域 span 从 `506` 行降至 `437` 行；`runOtaDiagnosisHotelFetch` 已不再进入最大块列表。
- 当前自审计：完整目录约 `280.88 MB`；不含 `.git` 约 `92.36 MB`；不含 `.git` 和依赖约 `63.17 MB`；Git 跟踪文件约 `18.24 MB` / `613` 个；代码范围 `370` 个文件，`190,392` 行，非空 `174,606` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ota-diagnosis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`node --check public\ctrip-static.js`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十刀拆分

- 扩展 `public/ctrip-static.js`，新增携程浏览器采集流程编排器 `runCtripBrowserCaptureFlow()`，统一处理目标酒店上下文、携程配置加载/补密、Profile 校验、`/online-data/capture-ctrip-browser` 请求、成功写回、普通采集刷新和异常证据保留。
- `public/index.html` 中 `runCtripBrowserCapture()` 只保留 Vue 状态、接口请求和刷新函数的薄适配；本轮不改变携程浏览器采集接口、Profile 登录保存、数据源绑定、入库刷新、数据健康刷新、平台 Profile 状态刷新或缺失/失败状态展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripBrowserCaptureFlow()`，要求静态模块导出该流程函数，禁止 target/request/request-catch 流程重新内联，并在 VM 中验证普通采集刷新链和登录保存 Profile 状态样例。
- 当前 split-map：`public/index.html` 从 `37,615` 行降至 `37,551` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `3,203` 行降至 `3,141` 行；当前最大前端块为 `fetchCtripData`，`96` 行。
- 当前自审计：完整目录约 `281.50 MB`；不含 `.git` 约 `92.37 MB`；不含 `.git` 和依赖约 `63.18 MB`；Git 跟踪文件约 `18.25 MB` / `613` 个；代码范围 `370` 个文件，`190,583` 行，非空 `174,797` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十一刀拆分

- 扩展 `public/ctrip-static.js`，新增携程普通采集流程编排器 `runCtripFetchDataFlow()`，统一处理登录/目标酒店/数据源校验、请求上下文构造、`/online-data/fetch-ctrip` 调用、成功展示写回、历史/最新快照刷新、401 提示和 raw failure 证据展示。
- `public/index.html` 中 `fetchCtripData()` 只保留 Vue 状态、接口请求、展示/刷新函数和日志函数的薄适配；本轮不改变 `/online-data/fetch-ctrip` 接口、保存逻辑、展示模型、历史刷新、最近快照刷新、缺失/失败状态展示或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripFetchDataFlow()`，要求静态模块导出该流程函数，禁止 request context、成功结果、meta 和 raw failure 分支重新内联，并在 VM 中验证成功刷新链、raw failure 展示和未登录守卫样例。
- 当前 split-map：`public/index.html` 从 `37,551` 行降至 `37,491` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `3,141` 行降至 `3,084` 行；`fetchCtripData` 已不再进入最大块列表，当前最大前端块为 `fetchMeituanData`，`88` 行。
- 当前自审计：完整目录约 `282.13 MB`；不含 `.git` 约 `92.38 MB`；不含 `.git` 和依赖约 `63.19 MB`；Git 跟踪文件约 `18.27 MB` / `613` 个；代码范围 `370` 个文件，`190,762` 行，非空 `174,974` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十二刀拆分

- 扩展 `public/meituan-static.js`，新增美团批量榜单采集流程编排器 `runMeituanBatchFetchFlow()`，统一处理目标酒店/数据源校验、一次性门店标识校验、批量任务构造、`/online-data/fetch-meituan` 循环请求、展示模型 payload、保存数汇总、刷新链和错误 toast。
- `public/index.html` 中 `fetchMeituanData()` 只保留 Vue 状态、接口请求、展示模型和刷新函数的薄适配；本轮不改变 `/online-data/fetch-meituan`、`/online-data/meituan/display-model`、保存逻辑、展示模型、历史刷新、数据列表刷新、缺失/失败状态展示或 OTA 渠道范围。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanBatchFetchFlow()`，要求静态模块导出该流程函数，禁止 validation、fetch tasks、result entry 和 display payload 分支重新内联，并在 VM 中验证四榜单任务、展示 payload、保存数汇总、刷新链和缺目标酒店守卫样例。
- 当前 split-map：`public/index.html` 从 `37,491` 行降至 `37,431` 行；前端函数级块保持 `1,396`；`meituan` 领域 span 从 `1,205` 行降至 `1,148` 行；`fetchMeituanData` 已不再进入最大块列表，当前最大前端块为 `startAiAnalysis`，`86` 行。
- 当前自审计：完整目录约 `282.75 MB`；不含 `.git` 约 `92.39 MB`；不含 `.git` 和依赖约 `63.20 MB`；Git 跟踪文件约 `18.28 MB` / `613` 个；代码范围 `370` 个文件，`190,891` 行，非空 `175,105` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十三刀拆分

- 扩展 `public/ai-analysis-static.js`，新增携程 OTA AI 分析流程编排器 `runCapturedOtaAnalysisExecution()`，统一处理分组执行、失败重试后的进度计数、汇总上下文、综合报告结果、全部失败错误和历史记录构造。
- `public/index.html` 中 `startAiAnalysis()` 只保留 Vue 状态初始化、启动/运行上下文、请求回调和结果写回；分组循环、成功/失败组归集、summary context 和 completion history 不再内联在入口。
- 本轮不改变 `/agent/analyze-captured-ota-data`、`/agent/summarize-captured-ota-analysis`、模型选择、报告结构、历史保留数量、缺失/失败可见性或携程 OTA 渠道范围；全部失败仍显式展示，敏感错误继续脱敏。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCapturedOtaAnalysisExecution()`，要求静态模块导出该 runner，禁止分组执行循环重新内联，并在 VM 中验证成功+重试流程、summary context、history 生成和全部失败脱敏样例。
- 当前 split-map：`public/index.html` 从 `37,431` 行降至 `37,403` 行；前端函数级块保持 `1,396`；`ai` 领域 span 从 `1,187` 行降至 `1,159` 行；`startAiAnalysis` 已不再进入最大块列表，当前最大前端块为 `runCtripCookieApiCapture`，`85` 行。
- 当前自审计：完整目录约 `283.37 MB`；不含 `.git` 约 `92.40 MB`；不含 `.git` 和依赖约 `63.21 MB`；Git 跟踪文件约 `18.29 MB` / `613` 个；代码范围 `370` 个文件，`191,047` 行，非空 `175,258` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十四刀拆分

- 扩展 `public/ctrip-static.js`，新增携程 Cookie API 采集流程编排器 `runCtripCookieApiCaptureFlow()`，统一处理目标酒店校验、Request URL / endpoints JSON 校验、配置加载/补密、Profile 解析、请求 body、成功刷新链、未就绪提示、错误响应和异常结果保留。
- `public/index.html` 中 `runCtripCookieApiCapture()` 只保留 Vue ref、`/online-data/fetch-ctrip-cookie-api` 请求回调、toast 和刷新函数的薄适配；Cookie API 请求体构造和流程状态不再内联在入口。
- 本轮不改变 `/online-data/fetch-ctrip-cookie-api`、入库行为、携程 Profile 绑定、最近快照刷新、历史刷新、数据健康刷新、缺失/失败状态展示或携程 OTA 渠道范围；未就绪、身份不匹配、缺 Profile 和缺请求来源仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripCookieApiCaptureFlow()`，要求静态模块导出该 runner，禁止请求来源校验、Cookie 选择和采集请求流程重新内联，并在 VM 中验证成功刷新链、未就绪、错误响应、异常、缺 Profile 和缺请求来源样例。
- 当前 split-map：`public/index.html` 从 `37,403` 行降至 `37,351` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `3,084` 行降至 `3,032` 行；`runCtripCookieApiCapture` 已不再进入最大块列表，当前最大前端块为 `triggerAutoFetch`，`82` 行。
- 当前自审计：完整目录约 `284 MB`；不含 `.git` 约 `92.42 MB`；不含 `.git` 和依赖约 `63.23 MB`；Git 跟踪文件约 `18.30 MB` / `613` 个；代码范围 `370` 个文件，`191,296` 行，非空 `175,503` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十五刀拆分

- 扩展 `public/auto-fetch-static.js`，新增手动触发自动采集流程编排器 `runAutoFetchTriggerFlow()`，并抽出 `buildAutoFetchTriggerRequestBody()` 与 `buildAutoFetchRunStartState()`。
- `public/index.html` 中 `triggerAutoFetch()` 只保留 Vue ref、`/online-data/auto-fetch` 请求回调、toast、计时器和刷新函数的薄适配；请求 body、运行态、成功刷新链、错误响应和异常路径不再内联在入口。
- 本轮不改变 `/online-data/auto-fetch`、自动采集入库、最近快照刷新、历史刷新、数据健康刷新、携程 Profile 字段复核入口、失败状态可见性或 OTA 渠道范围；未选酒店和未配置平台数据源仍显式拦截。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runAutoFetchTriggerFlow()`，要求静态模块导出触发流程 helper，禁止请求 body、运行态和成功刷新链重新内联，并在 VM 中验证成功、接口错误、异常、未选酒店和未配置样例。
- 当前 split-map：`public/index.html` 从 `37,351` 行降至 `37,297` 行；前端函数级块保持 `1,396`；`general` 领域 span 从 `7,165` 行降至 `7,111` 行；`triggerAutoFetch` 已不再进入最大块列表，当前最大前端块为 `fetchCtripFlowOverviewData`，`72` 行。
- 当前自审计：完整目录约 `284.64 MB`；不含 `.git` 约 `92.43 MB`；不含 `.git` 和依赖约 `63.24 MB`；Git 跟踪文件约 `18.32 MB` / `613` 个；代码范围 `370` 个文件，`191,558` 行，非空 `175,756` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\auto-fetch-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十六刀拆分

- 扩展 `public/ctrip-static.js`，新增携程概览采集流程编排器 `runCtripOverviewFetchFlow()`，统一处理目标酒店校验、携程配置加载/补密、表单规范化、接口 URL/Cookie 校验、请求 body、成功刷新链、错误响应和异常证据保留。
- `public/index.html` 中 `fetchCtripOverviewData()` 和 `fetchCtripFlowOverviewData()` 只保留 Vue ref、`/online-data/fetch-ctrip-overview` 请求回调、toast、最近快照刷新和历史刷新函数装配。
- 本轮不改变 `/online-data/fetch-ctrip-overview`、入库行为、最近快照刷新、历史刷新、原始数据展示开关、缺失/失败状态可见性或携程 OTA 渠道范围；缺目标酒店、缺携程配置、页面 URL 误填和缺 Cookie 仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripOverviewFetchFlow()`，要求静态模块导出该 runner，禁止流量概要默认 URL 选择和概览请求体逻辑重新内联，并在 VM 中验证成功、错误响应、异常、缺目标酒店、缺配置、页面 URL 误填和缺 Cookie 样例。
- 当前 split-map：`public/index.html` 从 `37,297` 行降至 `37,217` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `3,032` 行降至 `2,952` 行；`fetchCtripFlowOverviewData` 与 `fetchCtripOverviewData` 已不再进入最大块列表，当前最大前端块为 `fetchCtripAdsData`，`72` 行。
- 当前自审计：完整目录约 `285.27 MB`；不含 `.git` 约 `92.45 MB`；不含 `.git` 和依赖约 `63.26 MB`；Git 跟踪文件约 `18.33 MB` / `613` 个；代码范围 `370` 个文件，`191,705` 行，非空 `175,904` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十七刀拆分

- 扩展 `public/ctrip-static.js`，新增携程广告采集流程编排器 `runCtripAdsFetchFlow()`，统一处理目标酒店校验、携程配置加载/补密、广告配置同步、广告接口 URL 校验、Cookie 校验、自定义日期校验、请求 body、成功刷新链、错误响应和异常证据保留。
- `public/index.html` 中 `fetchCtripAdsData()` 只保留 Vue ref、`/online-data/fetch-ctrip-ads` 请求回调、toast、最近快照刷新和历史刷新函数装配；`isCtripAdsApiUrl()` 和 `normalizeCtripAdsApiType()` 仍保留入口引用，供配置表单校验继续使用。
- 本轮不改变 `/online-data/fetch-ctrip-ads`、广告数据入库、最近快照刷新、历史刷新、原始数据展示开关、缺失/失败状态可见性或携程 OTA 渠道范围；页面 URL 误填、非广告接口 URL、缺 Cookie 和自定义日期缺失仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripAdsFetchFlow()`，要求静态模块导出该 runner，禁止广告 URL/Cookie 选择与请求流程重新内联，并在 VM 中验证成功、错误响应、异常、缺目标酒店、缺配置、页面 URL 误填、非广告接口、缺 Cookie 和自定义日期缺失样例。
- 当前 split-map：`public/index.html` 从 `37,217` 行降至 `37,171` 行；前端函数级块保持 `1,396`；`ctrip` 领域 span 从 `2,952` 行降至 `2,906` 行；`fetchCtripAdsData` 已不再进入最大块列表，当前最大前端块为 `generateOtaDiagnosis`，`71` 行。
- 当前自审计：完整目录约 `285.91 MB`；不含 `.git` 约 `92.46 MB`；不含 `.git` 和依赖约 `63.27 MB`；Git 跟踪文件约 `18.34 MB` / `613` 个；代码范围 `370` 个文件，`191,887` 行，非空 `176,084` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十八刀拆分

- 扩展 `public/ota-diagnosis-static.js`，新增 OTA 诊断生成流程编排器 `runOtaDiagnosisGenerateFlow()`，并抽出请求体构建、空数据识别和补抓失败提示。
- `public/index.html` 中 `generateOtaDiagnosis()` 只保留 Vue ref、酒店选项、`runOtaDiagnosisHotelFetch()`、`/agent/ota-diagnosis` 请求回调和 toast 装配。
- 本轮不改变 `/agent/ota-diagnosis`、诊断前补抓、继续使用已入库数据生成诊断、空数据提示、失败/异常状态可见性或 OTA 渠道范围；缺酒店、缺日期、日期倒置、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runOtaDiagnosisGenerateFlow()`，要求静态模块导出该 runner，禁止生成请求体、空数据识别和补抓失败提示重新内联，并在 VM 中验证成功、补抓部分失败但继续诊断、缺酒店、后端失败和异常释放 loading 样例。
- 当前 split-map：`public/index.html` 从 `37,171` 行降至 `37,118` 行；前端函数级块保持 `1,396`；`ota` 领域 span 从 `437` 行降至 `384` 行；`generateOtaDiagnosis` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits`、`fetchMeituanOrdersData` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `286.55 MB`；不含 `.git` 约 `92.47 MB`；不含 `.git` 和依赖约 `63.28 MB`；Git 跟踪文件约 `18.36 MB` / `613` 个；代码范围 `370` 个文件，`192,092` 行，非空 `176,281` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ota-diagnosis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第八十九刀拆分

- 扩展 `public/meituan-static.js`，新增美团订单采集流程编排器 `runMeituanOrderFetchFlow()`，并抽出订单表单规整、输入门禁和请求体构建。
- `public/index.html` 中 `fetchMeituanOrdersData()` 只保留 Vue ref、`/online-data/fetch-meituan-orders` 请求回调、结果写回、toast 和历史刷新装配。
- 本轮不改变 `/online-data/fetch-meituan-orders`、订单数据入库、历史刷新、结果展示、缺失/失败状态可见性或美团 OTA 渠道范围；缺 Request URL、误填订单页面 URL、缺 partnerId、缺 poiId、缺 Cookies、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanOrderFetchFlow()`，要求静态模块导出订单请求体 builder 和 runner，禁止订单请求流程、页面 URL 门禁、成功写回和成功 toast 重新内联，并在 VM 中验证成功、缺 URL、后端失败和异常释放 busy 样例。
- 当前 split-map：`public/index.html` 从 `37,118` 行降至 `37,066` 行；前端函数级块保持 `1,396`；`meituan` 领域 span 从 `1,148` 行降至 `1,095` 行；`fetchMeituanOrdersData` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `287.18 MB`；不含 `.git` 约 `92.49 MB`；不含 `.git` 和依赖约 `63.30 MB`；Git 跟踪文件约 `18.37 MB` / `613` 个；代码范围 `370` 个文件，`192,282` 行，非空 `176,462` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十刀拆分

- 扩展 `public/meituan-static.js`，新增美团广告采集流程编排器 `runMeituanAdsFetchFlow()`，并抽出广告表单规整、输入门禁和请求体构建。
- `public/index.html` 中 `fetchMeituanAdsData()` 只保留 Vue ref、`/online-data/fetch-meituan-ads` 请求回调、结果写回、toast 和历史刷新装配。
- 本轮不改变 `/online-data/fetch-meituan-ads`、广告数据入库、历史刷新、结果展示、缺失/失败状态可见性或美团 OTA 广告口径；缺 Request URL、误填推广通页面 URL、缺推广店铺/门店标识、缺 Cookies、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanAdsFetchFlow()`，要求静态模块导出广告请求体 builder 和 runner，禁止广告请求流程、页面 URL 门禁、成功写回和成功 toast 重新内联，并在 VM 中验证成功、缺 URL、后端失败和异常释放 busy 样例。
- 当前 split-map：`public/index.html` 从 `37,066` 行降至 `37,014` 行；前端函数级块从 `1,396` 降至 `1,395`；`meituan` 领域 span 从 `1,095` 行降至 `1,042` 行；`fetchMeituanAdsData` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `287.82 MB`；不含 `.git` 约 `92.50 MB`；不含 `.git` 和依赖约 `63.31 MB`；Git 跟踪文件约 `18.38 MB` / `613` 个；代码范围 `370` 个文件，`192,475` 行，非空 `176,649` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十一刀拆分

- 扩展 `public/meituan-static.js`，新增美团流量采集流程编排器 `runMeituanTrafficFetchFlow()`，并抽出流量表单规整、输入门禁和请求体构建。
- `public/index.html` 中 `fetchMeituanTrafficData()` 只保留 Vue ref、`/online-data/fetch-meituan-traffic` 请求回调、流量结果写回、toast、历史刷新和在线数据刷新装配。
- 本轮不改变 `/online-data/fetch-meituan-traffic`、流量数据入库、历史刷新、在线数据列表刷新、缺失/失败状态可见性或美团 OTA 流量口径；缺接口地址、缺平台接口标识、缺平台门店标识、缺平台授权内容、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanTrafficFetchFlow()`，要求静态模块导出流量请求体 builder 和 runner，禁止流量请求流程、成功写回和成功 toast 重新内联，并在 VM 中验证成功、缺 URL、后端失败和异常释放 busy 样例。
- 当前 split-map：`public/index.html` 从 `37,014` 行降至 `36,970` 行；前端函数级块保持 `1,395`；`meituan` 领域 span 从 `1,042` 行降至 `997` 行；`fetchMeituanTrafficData` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `288.45 MB`；不含 `.git` 约 `92.51 MB`；不含 `.git` 和依赖约 `63.32 MB`；Git 跟踪文件约 `18.40 MB` / `613` 个；代码范围 `370` 个文件，`192,660` 行，非空 `176,826` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十二刀拆分

- 扩展 `public/ctrip-static.js`，新增携程流量采集流程编排器 `runCtripTrafficFetchFlow()`，复用既有 `buildCtripTrafficFetchRequestBody()` 和 `buildCtripTrafficResponseModel()`。
- `public/index.html` 中 `fetchCtripTrafficData()` 只保留 Vue ref、`/online-data/ctrip/traffic` 请求回调、流量展示写回、历史刷新、在线数据刷新和失败处理回调装配。
- 本轮不改变 `/online-data/ctrip/traffic`、流量数据入库、展示行构建、历史刷新、最新快照失败兜底、缺失/失败状态可见性或携程 OTA 流量口径；缺目标酒店、缺携程数据源、缺 Cookie、缺自定义日期、后端失败、空流量结果和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runCtripTrafficFetchFlow()`，要求静态模块导出流量 runner，禁止流量请求流程、响应模型写回和成功写回重新内联，并在 VM 中验证成功、空结果、后端失败、异常和缺失状态样例。
- 当前 split-map：`public/index.html` 从 `36,970` 行降至 `36,927` 行；前端函数级块保持 `1,395`；`ctrip` 领域 span 从 `2,906` 行降至 `2,864` 行；`fetchCtripTrafficData` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `289.09 MB`；不含 `.git` 约 `92.52 MB`；不含 `.git` 和依赖约 `63.33 MB`；Git 跟踪文件约 `18.41 MB` / `613` 个；代码范围 `370` 个文件，`192,820` 行，非空 `176,983` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十三刀拆分

- 扩展 `public/meituan-static.js`，新增美团浏览器采集流程编排器 `runMeituanBrowserCaptureFlow()` 和请求上下文构造器 `buildMeituanBrowserCaptureRequestContext()`。
- `public/index.html` 中 `runMeituanBrowserCapture()` 只保留 Vue ref、`/online-data/capture-meituan-browser` 请求回调、结果写回、历史刷新、平台 Profile 状态刷新和数据源刷新装配。
- 本轮不改变 `/online-data/capture-meituan-browser`、浏览器采集入库、Profile 登录保存、数据源绑定、历史刷新、平台状态刷新、缺失/失败状态可见性或美团 OTA 渠道范围；缺目标酒店、缺门店标识、缺广告入口 URL、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanBrowserCaptureFlow()`，要求静态模块导出浏览器采集上下文 builder 和 runner，禁止浏览器采集目标上下文、请求流程、登录保存 payload 和异常结果重新内联，并在 VM 中验证成功、登录保存、后端失败、异常和缺失状态样例。
- 当前 split-map：`public/index.html` 从 `36,927` 行降至 `36,889` 行；前端函数级块保持 `1,395`；`meituan` 领域 span 从 `997` 行降至 `958` 行；`runMeituanBrowserCapture` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `289.74 MB`；不含 `.git` 约 `92.54 MB`；不含 `.git` 和依赖约 `63.35 MB`；Git 跟踪文件约 `18.43 MB` / `613` 个；代码范围 `370` 个文件，`193,054` 行，非空 `177,211` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十四刀拆分

- 扩展 `public/meituan-static.js`，新增美团手动采集 JSON 保存上下文构造器 `buildMeituanCapturedPayloadSaveContext()` 和流程编排器 `runMeituanCapturedPayloadSaveFlow()`。
- `public/index.html` 中 `saveMeituanCapturedPayload()` 只保留 Vue ref、`/online-data/save-meituan-captured-data` 请求回调、结果写回、toast 和历史刷新装配。
- 本轮不改变 `/online-data/save-meituan-captured-data`、手动粘贴采集 JSON 入库、保存成功回显、在线历史刷新、缺失/失败状态可见性或美团 OTA 渠道范围；缺目标酒店、缺抓取结果 JSON、JSON 格式错误、非对象 JSON、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanCapturedPayloadSaveFlow()`，要求静态模块导出保存上下文 builder 和 runner，禁止 JSON trim/parse、payload 补齐和保存请求流程重新内联，并在 VM 中验证成功、后端失败、异常和缺失状态样例。
- 当前 split-map：`public/index.html` 从 `36,889` 行降至 `36,848` 行；前端函数级块保持 `1,395`；`meituan` 领域 span 从 `958` 行降至 `916` 行；`saveMeituanCapturedPayload` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `290.39 MB`；不含 `.git` 约 `92.55 MB`；不含 `.git` 和依赖约 `63.36 MB`；Git 跟踪文件约 `18.44 MB` / `613` 个；代码范围 `370` 个文件，`193,237` 行，非空 `177,388` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\meituan-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十五刀拆分

- 扩展 `public/ctrip-static.js`，新增携程配置默认表单 `createCtripConfigForm()`、保存 payload 构造器 `buildCtripConfigSavePayload()`、输入校验器 `validateCtripConfigSaveInput()` 和流程编排器 `runCtripConfigSaveFlow()`。
- `public/index.html` 中 `saveCtripConfig()` 只保留 Vue ref、`/online-data/save-ctrip-config` 请求回调、toast、表单重置、配置列表刷新和错误日志装配。
- 本轮不改变 `/online-data/save-ctrip-config`、携程配置字段、授权内容保存、保存成功回显、配置列表刷新、缺失/失败状态可见性或携程 OTA 渠道范围；缺配置名称、缺平台授权内容、后端失败和带 `response.json()` 的请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `createCtripConfigForm()` 和 `runCtripConfigSaveFlow()`，要求静态模块导出配置保存 builder/validator/runner，禁止配置保存校验、payload、后端失败处理和异常响应解析重新内联，并在 VM 中验证成功、后端失败、异常和缺失状态样例。
- 当前 split-map：`public/index.html` 从 `36,848` 行降至 `36,795` 行；前端函数级块保持 `1,395`；`ctrip` 领域 span 从 `2,864` 行降至 `2,819` 行；`saveCtripConfig` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `291.03 MB`；不含 `.git` 约 `92.57 MB`；不含 `.git` 和依赖约 `63.38 MB`；Git 跟踪文件约 `18.45 MB` / `613` 个；代码范围 `370` 个文件，`193,406` 行，非空 `177,557` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ctrip-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十六刀拆分

- 扩展 `public/ai-analysis-static.js`，新增美团 AI 分析启动校验器 `validateMeituanAiAnalysisStart()` 和流程编排器 `runMeituanAiAnalysisFlow()`。
- `public/index.html` 中 `startMeituanAiAnalysis()` 只保留 Vue ref、`/online-data/ai-analysis` 请求回调、toast、结果写回、历史写回、运行态写回和错误日志装配。
- 本轮不改变 `/online-data/ai-analysis`、美团 OTA 渠道范围、AI 报告 HTML 清洗、历史记录 10 条上限、缺失/失败状态可见性或后端 AI 分析职责；缺选择酒店、选中数据缺失、后端失败和请求异常仍显式返回。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `runMeituanAiAnalysisFlow()`，要求静态模块导出美团 AI 启动 validator 和 runner，禁止选择校验、选中数据解析、请求体构造、接口请求、历史构造/裁剪和异常日志重新内联，并在 VM 中验证成功、后端失败、异常和缺失状态样例。
- 当前 split-map：`public/index.html` 从 `36,795` 行降至 `36,756` 行；前端函数级块保持 `1,395`；`meituan` 领域 span 从 `916` 行降至 `876` 行；`startMeituanAiAnalysis` 已不再进入最大块列表，当前最大前端块为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 当前自审计：完整目录约 `291.69 MB`；不含 `.git` 约 `92.58 MB`；不含 `.git` 和依赖约 `63.39 MB`；Git 跟踪文件约 `18.46 MB` / `613` 个；代码范围 `370` 个文件，`193,552` 行，非空 `177,708` 行；默认可清理目标为 `0 MB`。
- 已验证：`node --check public\ai-analysis-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`、`npm.cmd run self:check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 前端第九十七刀拆分

- 扩展 `public/simulation-static.js`，新增转让决策层级行构建器 `buildTransferDecisionLayerRows()`，集中构造事实数据、人工假设、测算结果、风险与决策四层状态。
- `public/index.html` 中 `transferDecisionLayerRows` 只保留 Vue ref 读取和 builder 调用，不再内联快照状态、人工假设状态、测算状态和最终判断展示行。
- 本轮不改变转让测算接口、经营快照读取、人工输入口径、最终决策板字段或投资决策边界；没有经营快照、人工假设未填、测算未生成、决策板未汇总仍显式展示。
- 更新 `scripts/verify_e2e_contracts.mjs`，要求入口显式读取 `buildTransferDecisionLayerRows()`，要求静态模块导出该 builder，禁止事实行和测算证据重新内联，并在 VM 中验证有快照/无快照、人工假设、测算结果和风险决策样例。
- 本轮提交口径：`public/index.html` 从 `36,756` 行降至 `36,726` 行；`transfer` 领域 span 从 `274` 行降至 `244` 行；`transferDecisionLayerRows` 已不再进入最大块列表，当前最大前端块仍为 `importKnowledgeUnits` 和 `openSystemConfigModal`，均为 `68` 行。
- 本轮提交口径代码统计：代码范围 `370` 个文件，`193,633` 行，非空 `177,788` 行；Git 跟踪文件约 `18.46 MB` / `613` 个；默认可清理目标仍为 `0 MB`。
- 已验证：`node --check public\simulation-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`npm.cmd run self:clean`、`npm.cmd run self:check`、`git diff --cached --check`。
- 当前工作树另有未暂存外部改动，本轮保存点只暂存并提交转让决策层级行拆分相关文件；严格门禁仍不声明完成，`public/index.html` 与 `app/controller/OnlineData.php` 仍是真实拆分候选，需要继续收口或明确 disposition。

## 2026-06-11 保存点：在线明细分页与入口瘦身守卫

- 执行本地瘦身清理：`npm.cmd run self:clean:dry-run` 确认仅清理 `runtime` 1 个本地生成目标，预计回收约 `0.01 MB`；随后执行 `npm.cmd run self:clean`，已移除该目标，当前默认可清理目标为 `0 MB`。
- `SystemNotificationController` 改为在数据库层 join `system_notification_user_states`，列表按当前用户状态过滤、分页并计算未读数；批量已读/清空也通过 `visibleNotificationIdsForCurrentUser()` 查询 DB 可见 ID，避免先加载全部通知再内存过滤；可见范围字段改为显式表别名限定。
- `SystemConfigController::index()` 的单 key 查询先走 `SystemConfig::getValue($requestedKey, ...)` 并提前返回；公开配置读取通过 `SystemConfig::getConfigsByKeys($publicKeys)` 只查白名单 key，避免单项/公开配置读取时仍全量扫描全部配置。`SystemConfig` 新增 bounded key read helper。
- `Hotel::all()` 返回 `status` 字段，支撑前端酒店选项和数据源健康统计继续区分营业/停用状态。
- `public/router.php` 增加静态资源直出：限定在 `public` 根目录内，补齐 MIME、ETag、Last-Modified、Cache-Control 和 gzip；gzip 输出缓存到 `runtime/static-gzip`，避免大静态资源在本地开发反复 CPU 压缩；未命中静态文件时仍回落 ThinkPHP 应用入口。
- `public/index.html` 将在线分析明细请求从 `page_size=all` 收口为 100 条样本，并将页面文案改为“样本/汇总指标口径”；Chart.js 改为按需加载；美团竞对摘要默认不再拉取全店 `by_hotel`，仅数据源健康/批量面板显式传 `includeByHotel: true`，榜单刷新继续带所选酒店但显式 `includeByHotel: false`。
- 更新守卫：`verify_public_entry_guard.mjs` 禁止入口急切加载 `vue-router.global.prod.js`，要求 `form-operation-support.js` defer 加载，并要求 `public/router.php` 保持 `runtime/static-gzip` 缓存与 gzip level 1；平台批量健康、平台数据源、P0 learning、手工凭证 UI 测试均同步到新的数据边界。
- 当前自审计：完整目录约 `293.54 MB`；不含 `.git` 约 `92.60 MB`；不含 `.git` 和依赖约 `63.41 MB`；Git 跟踪文件约 `18.48 MB` / `613` 个；代码范围 `370` 个文件，`193,845` 行，非空 `177,986` 行；默认可清理目标为 `0 MB`。
- 当前 split-map：`public/index.html` 为 `36,772` 行、`1,396` 个前端函数级块；`app/controller/OnlineData.php` 为 `26,725` 行、`871` 个方法；两者仍是真实拆分候选。`public/tailwind.min.css` 仍按本地 CSS 依赖接受 disposition，不作为业务代码拆分目标。
- 已验证：`C:\xampp\php\php.exe -l app\controller\Hotel.php`、`C:\xampp\php\php.exe -l app\controller\SystemConfigController.php`、`C:\xampp\php\php.exe -l app\model\SystemConfig.php`、`C:\xampp\php\php.exe -l app\controller\SystemNotificationController.php`、`C:\xampp\php\php.exe -l public\router.php`、`node --check scripts\verify_p0_learning_contract.mjs`、`node --check scripts\verify_platform_batch_health_contract.mjs`、`node --check scripts\verify_platform_data_source_contract.mjs`、`node --check tests\automation\manual_minimum_credential_ui.test.mjs`、`C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests\SystemNotificationTest.php`、`node scripts\verify_platform_batch_health_contract.mjs`、`node scripts\verify_platform_data_source_contract.mjs`、`node --test tests\automation\manual_minimum_credential_ui.test.mjs`、`npm.cmd run verify:p0-guards`、`npm.cmd run verify:p0-learning`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:audit`、`npm.cmd run self:split-map`、`npm.cmd run self:check`、`git diff --check`。
- 当前严格门禁仍不声明完成：`public/index.html` 与 `app/controller/OnlineData.php` 仍需继续拆分或明确 disposition；PR #2 继续保持 Draft，不能作为发布 ready 状态。

## 后续处理建议

1. 日常开发结束后先运行 `npm run self:audit`。
2. 如果默认可清理目标明显增长，先运行 `npm run self:clean:dry-run`，确认后再运行 `npm run self:clean`。
3. 提交前运行 `npm run self:check` 或至少运行 `npm run verify:p0-guards`。
4. 安全整改阶段再单独处理数据库备份和凭据轮换，不要把备份清理混入普通瘦身。
