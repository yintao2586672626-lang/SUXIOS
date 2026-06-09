# 项目瘦身报告

更新日期：2026-06-10

范围：本报告只处理本地运行产物、测试产物、可再生成缓存和自净化审计；不删除业务代码、验收文档、`.git` 历史、依赖锁文件或数据库备份。

当前执行状态：默认瘦身脚本已可执行；只读自净化审计脚本已覆盖项目体积、代码行数、可清理产物、Git 状态、跟踪代码热点和拆分候选。

## 当前体积判断

| 项目 | 体积 | 处理策略 |
|---|---:|---|
| 完整 `HOTEL/` 目录 | 约 228.52 MB | 包含 `.git`、依赖、本地报告和项目代码。 |
| 不含 `.git` | 约 91.89 MB | 更接近工作副本体积。 |
| 不含 `.git`、`node_modules/`、`vendor/` | 约 62.70 MB | 更接近业务与资料体积。 |
| Git 跟踪文件 | 约 17.77 MB / 589 个文件 | 这是代码提交体积口径。 |
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
| 代码文件 | 346 |
| 总代码行 | 约 186,133 |
| 非空代码行 | 约 170,454 |

## 跟踪代码热点

`npm run self:audit` 已新增跟踪代码热点、拆分候选和已接受候选输出。当前拆分候选不是自动删除清单，只用于后续架构瘦身排序。

| 文件 | 行数 | 体积 | 当前本地改动 | 判断 |
|---|---:|---:|---|---|
| `public/index.html` | 43,322 | 3.11 MB | 否 | 当前前端 SPA 主入口，后续应按页面或面板拆分，同时保持 Vue CDN 运行契约。 |
| `app/controller/OnlineData.php` | 28,485 | 1.23 MB | 本轮拆分中 | OTA 采集、字段配置、展示和诊断职责仍过重；已先抽出携程字段静态元数据、关键字段清单、默认采集字段行、流量漏斗/周报/竞争圈画像元数据和 Ctrip overview 汇总逻辑，后续继续迁移到聚焦 service，不改变现有路由。 |

前一轮 10 个业务改动文件已单独保存并推送；当前自净化拆分只改动 `app/controller/OnlineData.php`、`app/service/CtripProfileFieldMetaService.php`、`app/service/CtripOverviewSummaryService.php` 和状态文档。

## 已接受拆分候选

`docs/self_cleaning_split_dispositions.json` 用于记录有证据保留的非业务代码大文件。已接受项不会触发 `self:check:strict`，但仍会在审计输出中显示。

| 文件 | 行数 | 体积 | 处置 |
|---|---:|---:|---|
| `public/tailwind.min.css` | 1 | 2.80 MB | 当前 `public/index.html` 直接加载的本地 Tailwind CSS 静态依赖，不作为业务代码拆分目标；前端拆分或迁移后复查。 |

`npm run self:check` 保持日常可通过，只把默认可清理产物超过阈值视为失败；`npm run self:check:strict` 用于核心大文件拆分阶段，当前预计会因为 `public/index.html` 和 `app/controller/OnlineData.php` 两个拆分候选失败。

## 拆分地图

`npm run self:split-map` 当前只读分析剩余两个严格候选，不修改业务文件。当前 `public/index.html` 无本地改动；`app/controller/OnlineData.php` 正在本轮自净化拆分中。

| 文件 | 结构信号 | 最大拆分起点 | 领域分布信号 |
|---|---:|---|---|
| `public/index.html` | 1,162 个函数级块；44 个 `currentPage` 引用 | `resetSystemConfig` 421 行、`printFeasibilityReport` 342 行、`formatOtaMetricValue` 312 行 | `general` 11,061 行、`ctrip` 3,909 行、`hotel_admin` 1,571 行、`ai` 1,543 行 |
| `app/controller/OnlineData.php` | 874 个方法 | `captureMeituanBrowserData` 274 行、`captureCtripBrowserData` 272 行、`parseAndSaveMeituanData` 237 行 | `ctrip` 12,319 行、`meituan` 5,307 行、`general` 4,486 行、`auto_fetch` 1,838 行 |

## 2026-06-10 后端第一刀拆分

- 新增 `app/service/CtripProfileFieldMetaService.php`，承载携程字段基础静态元数据、关键字段 key 清单、字段元数据刷新 key 清单、默认采集字段行、流量漏斗元数据、周报元数据和竞争圈画像元数据。
- 新增 `app/service/CtripOverviewSummaryService.php`，承载 Ctrip overview 汇总逻辑；控制器保留 `summarizeCtripOverviewRows()` 薄包装以兼容现有调用和反射测试。
- `app/controller/OnlineData.php` 中 `defaultCtripProfileFieldMeta()` 保留编排入口，具体字段元数据改为调用 service。
- `OnlineData.php` 从 `31,140` 行降至 `28,485` 行；`CtripProfileFieldMetaService.php` 当前为 `2,415` 行，`CtripOverviewSummaryService.php` 当前为 `361` 行。
- 路由、接口名、字段口径、OTA 渠道边界不变。
- 验证通过：PHP 语法检查、`tests\OnlineDataTest.php --filter CtripProfile`、`tests\OnlineDataTest.php --filter CtripOverview`、完整 `tests\OnlineDataTest.php`、`git diff --check`、`npm.cmd run verify:p0-guards`、`npm.cmd run self:audit`。
- 当前严格门禁仍预计失败，原因仍是 `public/index.html` 和 `app/controller/OnlineData.php` 两个真实拆分候选尚未全部收口。

## 后续处理建议

1. 日常开发结束后先运行 `npm run self:audit`。
2. 如果默认可清理目标明显增长，先运行 `npm run self:clean:dry-run`，确认后再运行 `npm run self:clean`。
3. 提交前运行 `npm run self:check` 或至少运行 `npm run verify:p0-guards`。
4. 安全整改阶段再单独处理数据库备份和凭据轮换，不要把备份清理混入普通瘦身。
