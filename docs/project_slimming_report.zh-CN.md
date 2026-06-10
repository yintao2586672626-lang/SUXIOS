# 项目瘦身报告

更新日期：2026-06-10

范围：本报告只处理本地运行产物、测试产物、可再生成缓存和自净化审计；不删除业务代码、验收文档、`.git` 历史、依赖锁文件或数据库备份。

当前执行状态：默认瘦身脚本已可执行；只读自净化审计脚本已覆盖项目体积、代码行数、可清理产物、Git 状态、跟踪代码热点和拆分候选。

## 当前体积判断

| 项目 | 体积 | 处理策略 |
|---|---:|---|
| 完整 `HOTEL/` 目录 | 约 242.26 MB | 包含 `.git`、依赖、本地报告和项目代码；会随本地 Git 对象轻微波动。 |
| 不含 `.git` | 约 91.93 MB | 更接近工作副本体积。 |
| 不含 `.git`、`node_modules/`、`vendor/` | 约 62.74 MB | 更接近业务与资料体积。 |
| Git 跟踪文件 | 约 17.82 MB / 606 个文件 | 这是代码提交体积口径。 |
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
| 代码文件 | 364 |
| 总代码行 | 约 186,165 |
| 非空代码行 | 约 170,495 |

## 跟踪代码热点

`npm run self:audit` 已新增跟踪代码热点、拆分候选和已接受候选输出。当前拆分候选不是自动删除清单，只用于后续架构瘦身排序。

| 文件 | 行数 | 体积 | 当前本地改动 | 判断 |
|---|---:|---:|---|---|
| `public/index.html` | 41,586 | 2.99 MB | 本轮拆分中 | 当前前端 SPA 主入口；已先抽出扩张/市场测算静态选项数据到 `public/expansion-static-options.js`，抽出酒店图片优化/AI 工具箱静态选项到 `public/hotel-image-optimizer-static.js`，抽出收益研究中心静态产品清单到 `public/revenue-research-static.js`，抽出自动采集静态配置到 `public/auto-fetch-static.js`，抽出门店罗盘静态配置到 `public/compass-static.js`，抽出模拟测算/转让字段静态配置到 `public/simulation-static.js`，抽出运营/开业静态选项到 `public/operation-static.js`，继续把门店罗盘宏观信号文案归入 `public/compass-static.js`，抽出携程字段/Profile/概览接口静态配置到 `public/ctrip-static.js`，抽出系统/AI/知识库静态配置、AI 模型配置 I18N、语言选项和导航菜单定义到 `public/system-static.js`，抽出前端复用 Vue 组件到 `public/shared-components.js`，抽出全局通知展示工具到 `public/notification-static.js`，抽出美团榜单展示工具和美团竞对摘要卡片构建器到 `public/meituan-static.js`，并扩展 `public/data-health-static.js` 承载数据健康展示、失败原因排名和今日待办构建工具；后续继续按页面或面板拆分，同时保持 Vue CDN 运行契约。 |
| `app/controller/OnlineData.php` | 27,333 | 1.17 MB | 本轮拆分中 | OTA 采集、字段配置、展示和诊断职责仍过重；已先抽出携程字段静态元数据、关键字段清单、默认采集字段行、流量漏斗/周报/竞争圈画像元数据、Ctrip overview 汇总逻辑、在线数据分析报告渲染逻辑和平台 Profile 绑定检查逻辑，并删除已被禁用响应短路的携程/美团点评旧直连、旧浏览器抓取、旧配置读写和旧自动抓取执行死代码；后续继续迁移到聚焦 service，不改变现有路由。 |

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
| `public/index.html` | 1,151 个函数级块；44 个 `currentPage` 引用 | `printFeasibilityReport` 342 行、`runOtaDiagnosisHotelFetch` 265 行、`requireDataHealthStatic` 231 行 | `general` 10,186 行、`ctrip` 3,877 行、`ai` 1,516 行、`hotel_admin` 1,516 行、`meituan` 1,371 行 |
| `app/controller/OnlineData.php` | 871 个方法 | `captureMeituanBrowserData` 274 行、`captureCtripBrowserData` 272 行、`parseAndSaveMeituanData` 237 行 | `ctrip` 11,861 行、`meituan` 4,979 行、`general` 4,478 行、`auto_fetch` 1,838 行、`profile` 941 行 |

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

## 后续处理建议

1. 日常开发结束后先运行 `npm run self:audit`。
2. 如果默认可清理目标明显增长，先运行 `npm run self:clean:dry-run`，确认后再运行 `npm run self:clean`。
3. 提交前运行 `npm run self:check` 或至少运行 `npm run verify:p0-guards`。
4. 安全整改阶段再单独处理数据库备份和凭据轮换，不要把备份清理混入普通瘦身。
