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
| 代码文件 | 366 |
| 总代码行 | 约 186,288 |
| 非空代码行 | 约 170,608 |

## 跟踪代码热点

`npm run self:audit` 已新增跟踪代码热点、拆分候选和已接受候选输出。当前拆分候选不是自动删除清单，只用于后续架构瘦身排序。

| 文件 | 行数 | 体积 | 当前本地改动 | 判断 |
|---|---:|---:|---|---|
| `public/index.html` | 41,467 | 2.98 MB | 本轮拆分中 | 当前前端 SPA 主入口；已先抽出扩张/市场测算静态选项数据到 `public/expansion-static-options.js`，抽出酒店图片优化/AI 工具箱静态选项到 `public/hotel-image-optimizer-static.js`，抽出收益研究中心静态产品清单到 `public/revenue-research-static.js`，抽出自动采集静态配置到 `public/auto-fetch-static.js`，抽出门店罗盘静态配置到 `public/compass-static.js`，抽出模拟测算/转让字段静态配置到 `public/simulation-static.js`，抽出运营/开业静态选项到 `public/operation-static.js`，继续把门店罗盘宏观信号文案归入 `public/compass-static.js`，抽出携程字段/Profile/概览接口静态配置到 `public/ctrip-static.js`，抽出系统/AI/知识库静态配置、AI 模型配置 I18N、语言选项和导航菜单定义到 `public/system-static.js`，抽出前端复用 Vue 组件到 `public/shared-components.js`，抽出全局通知展示工具到 `public/notification-static.js`，抽出美团榜单展示工具和美团竞对摘要卡片构建器到 `public/meituan-static.js`，扩展 `public/data-health-static.js` 承载数据健康展示、失败原因排名和今日待办构建工具，新增 `public/home-static.js` 承载首页闭环与 AI 轨迹展示构建器，并新增 `public/ota-diagnosis-static.js` 承载 OTA 诊断结果展示构建器；后续继续按页面或面板拆分，同时保持 Vue CDN 运行契约。 |
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
| `public/index.html` | 1,151 个函数级块；44 个 `currentPage` 引用 | `requireHomeStatic` 273 行、`runOtaDiagnosisHotelFetch` 265 行、`requireDataHealthStatic` 231 行 | `general` 10,126 行、`ctrip` 3,877 行、`ai` 1,516 行、`hotel_admin` 1,516 行、`meituan` 1,371 行、`ota` 693 行 |
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
- 已验证：`node --check public\system-static.js`、`node --check scripts\verify_e2e_contracts.mjs`、`npm.cmd run verify:e2e-contracts`、`npm.cmd run verify:public-entry`、`npm.cmd run self:split-map`、`npm.cmd run self:audit`、`git diff --check`。
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

## 后续处理建议

1. 日常开发结束后先运行 `npm run self:audit`。
2. 如果默认可清理目标明显增长，先运行 `npm run self:clean:dry-run`，确认后再运行 `npm run self:clean`。
3. 提交前运行 `npm run self:check` 或至少运行 `npm run verify:p0-guards`。
4. 安全整改阶段再单独处理数据库备份和凭据轮换，不要把备份清理混入普通瘦身。
