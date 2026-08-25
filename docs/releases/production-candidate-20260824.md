# Production candidate 2026-08-24

## 结论

- 本地成熟度：`integration_pass`。
- 候选基线：`origin/main@65433e7437e300b235ed59ea6d0dacd2fef255cc`；候选分支 `candidate/production-20260823` 仍停在该基线，所有候选内容均为未提交差异。
- 隔离工作树：`D:\桌面\SUXIOS\宿析OS初始版\.release-worktrees\production-candidate-20260823`。
- 已完成：范围盘点、白名单提取、迁移与 checksum 验证、数据库幂等/并发门禁、冻结基线全量回归与续推专项回归、前端同步/构建/指纹/预算、真实 DOM 与业务链路、权限/酒店隔离/失败状态检查、临时资源精确清理。
- 未授权且未执行：commit、push、PR、合并、部署、生产数据库迁移、真实 OTA/PMS 写入、外部消息。
- 本结论只证明隔离候选的本地集成；不等同于 `GitHub-persisted`、`deployed`、`account_verified`、`field_validated` 或 `production-ready`。

### 2026-08-24 续推增量结论

- 已把双 OTA 字段闭环、平台同步任务精确身份回读及其回归补入隔离候选；当前候选共有 53 个状态条目（40 个已跟踪修改、13 个未跟踪正式文件）。
- 新鲜专用数据库复验：184/184 个迁移登记，正式迁移重复执行两次稳定，8 个并发执行意图只落 1 条唯一任务；专用 `_e2e` 数据库已由验证器自动删除。
- 酒店 80、营业日 `2026-08-23` 已完成两家真实 Profile 精确采集与回读：携程任务 `#4428` 为 164/164、目标日 43/43；美团任务 `#4427` 为 134/134、目标日 7/7；两家平台、酒店身份、营业日和当前任务绑定均通过。
- 当前字段合同仍为 `partial / blocked`，但收益可消费字段已由 0 增至 3：美团权威流量事实 `online_daily_data#102476` 已晋级为 `verified / success`，曝光 1,422、访问 206、转化 14.49 可供收益分析读取。美团的两个正式金额口径 6,461.43 与 7,025.14 冲突，收益与 ADR 保持 `caliber_uncertain / null`；携程 P0 权威流量校验仍不完整，未晋级。
- 修复了已有事实晋级脚本的旧版采集锚点缺口，并把本地分析授权检查前移到只读预检。美团晋级完成后，4 项本地分析检查因计划未启用而保持阻断，记录数 0、外部动作数 0；未伪造 `user_goal` 授权。
- 修复收益驾驶舱把“部分来源严格回读”误判为整页不可用的前端契约缺陷。候选前端在本机同源登录态下刷新后可选择 `2026-08-23 · 最近严格回读`，只显示美团曝光 1,422、访问 206、转化 14.49% 三项已严格回读事实；订单金额、订单、间夜和 ADR 保持缺失，决策快照与待审批行动保持禁用。数据健康页的“确认本页已显示并核对”仍由用户本人触发。
- 实际采集过程中仅复用本机安全登录态/浏览器自动填充；未读取或保存密码、Cookie、localStorage、Profile 或令牌，也未自动发起 OTA 写入。

## 成熟度

| 层级 | 状态 | 证据边界 |
|---|---|---|
| `integration_pass` | **是** | 隔离候选在冻结源码上完成本文件所列本地门禁。 |
| `GitHub-persisted` | 否 | 未获 commit/push/PR 授权，没有远端 commit/PR 读回证据。 |
| `deployed` | 否 | 未获部署或生产迁移授权，没有生产版本或静态资源身份。 |
| `account_verified` | 否 | 已复用本机 `admin` 登录态完成真实 DOM 验收，但这不是生产账号或生产环境验证。 |
| `field_validated` | 否 | 已取得同酒店、同营业日两家 OTA 的真实采集与精确回读，但字段合同仍为 `partial / blocked`，不能标记完整现场字段验证。 |
| `production-ready` | 否 | 尚缺 GitHub 固化、已授权部署、生产回滚演练、真实账号与现场字段验收。 |

## 共享工作树盘点与归属边界

冻结时间：2026-08-24（Asia/Shanghai）。

- 共享工作树：`D:\桌面\SUXIOS\宿析OS初始版\HOTEL`。
- 共享分支：`agent/fix-daily-review-gates@8200d04dadb99d34d5bedb7bee1a5cfa55c10ae6`。
- 共享分支基线落后于 `origin/main`，因此没有把共享树整体复制或打包为候选。
- 首次盘点与候选完成后的只读复核完全一致：187 个已跟踪修改条目、3767 个逐文件未跟踪条目；最终状态指纹为 `25b49316be6b74fb18057ac3ef16bc3dd05a32f1f9b314693df94e8b0242b110`。
- 生成产物状态条目：11；包括前端模板快照、入口、压缩 JS/CSS。候选只从已纳入的源文件重新构建所需产物。
- 临时/验证类未跟踪条目：3353；主要位于 `.automation-worktrees/`、`storage/`、`reports/p0_closure/`，全部排除。
- 其余未跟踪正式文件候选：414；未整体复制，只按本候选功能白名单逐个审查。
- 本整合任务对共享工作树写入：0；没有 clean、reset、checkout 覆盖、删除或重命名共享内容。

Git 只能证明路径和内容差异，不能可靠证明未提交内容的作者。因此归属采用以下保守规则：

1. **本整合任务直接产生的修改**：隔离候选与本清单；迁移字段注册、静态资源指纹、源码热点棘轮、启动到完整渲染的收益页上下文修复及其回归测试。
2. **前序已验收功能**：只按“功能白名单 + 文件审查 + 回归证据”纳入，不把未提交作者身份写成事实。
3. **用户修改**：无法从 Git 可靠归属的共享内容全部留在原位，没有覆盖或清理。
4. **其他并发任务修改**：无法从 Git 可靠归属的共享内容默认排除；只有属于本候选白名单且独立验证通过的内容才被提取。
5. **生成产物**：仅纳入由候选源重建并通过身份检查的 `public/index.html`、`public/app-main.min.js`、`public/app-render.min.js`、`public/app-startup-helpers.min.js` 等发布产物。
6. **临时与验证产物**：不纳入。专用浏览器数据库 `suxi_release_browser_20260824_e2e`、临时审计 JS 和验收端口 18488/18489/18490 均已精确清理并复核无残留。

## 纳入的功能闭环

1. 双 OTA 当前回执字段闭环：字段级事实、来源与任务身份、失败状态、数据健康页展示。
2. 收益决策快照：服务端证明、追加保存、精确回读、从同一经营机会生成 `pending_approval`。
3. 经营行动生命周期：人工审批、单任务约束、执行证据、效果复盘、摘要链与酒店身份。
4. 可信收益分析直接入口：启动壳到完整渲染过程中保持 `agentTab=revenue` 与 `revenueAgentTab=analysis`，非超级管理员不会落入隐藏概览页。
5. 仅为上述闭环所需的路由、前端入口、测试、校验脚本和两条新增迁移。

明确排除：本地第二大脑、AI 报告/JHIRA、GEO/入住率、经理评分、知识库、云端采集部署、其他并发任务及其迁移。

## 候选文件清单

候选当前有 53 个状态条目：40 个已跟踪修改、13 个未跟踪正式文件。已跟踪差异统计为 6643 行新增、2918 行删除；大部分删除来自 `revenue-ai-static.js` 的等价压缩，不代表能力删除。

### 已跟踪修改（40）

- 后端与路由
  - `app/controller/OperationManagement.php`
  - `app/controller/RevenueAi.php`
  - `app/controller/concern/CollectionReliabilityConcern.php`
  - `app/service/DualOtaContinuousTrustService.php`
  - `app/service/OperationActionLifecycleService.php`
  - `app/service/RevenueCockpitApprovalService.php`
  - `app/service/concern/PlatformSyncTaskConcern.php`
  - `route/app.php`
- 前端源与生成产物
  - `public/app-main.js`
  - `public/app-main.min.js`
  - `public/app-render.min.js`
  - `public/app-startup-helpers.min.js`
  - `public/components/system/app-main-components-loader.js`
  - `public/components/system/app-main-components.js`
  - `public/index.html`
  - `public/operation-static.js`
  - `public/revenue-ai-static.js`
  - `public/system-static.js`
  - `resources/frontend/app-template.html`
  - `resources/frontend/templates/fragments/27-page-agent-center.html`
  - `resources/frontend/templates/fragments/35-page-online-data.html`
  - `resources/frontend/templates/manifest.json`
- 校验与测试
  - `evals/ai-operation-strict-contract/ai_operation_strict_contract.pending.test.mjs`
  - `scripts/cloud_hotel_id_column_registry.php`
  - `scripts/complete_existing_canonical_ota_daily_operations.php`
  - `scripts/lib/frontend_startup_helpers_build.mjs`
  - `scripts/run_platform_data_source_sync.php`
  - `scripts/verify_public_entry_guard.mjs`
  - `scripts/verify_revenue_ai_closure_contract.mjs`
  - `scripts/verify_revenue_cockpit_live_readback.mjs`
  - `tests/DualOtaContinuousTrustServiceTest.php`
  - `tests/OperatingQuestionExecutionBridgeServiceTest.php`
  - `tests/PlatformDataSyncServiceTest.php`
  - `tests/RevenueAiControllerTest.php`
  - `tests/RevenueCockpitApprovalServiceTest.php`
  - `tests/automation/business-chains.spec.js`
  - `tests/automation/frontend_full_render_transition.spec.js`
  - `tests/automation/operation_frontend_closure.test.mjs`
  - `tests/automation/platform_data_source_sync_cli_contract.test.mjs`
  - `tests/automation/revenue_ai_static.test.mjs`

### 未跟踪正式文件（13）

- 服务
  - `app/service/DualOtaFieldClosureService.php`
  - `app/service/RevenueDecisionSnapshotService.php`
  - `app/service/RevenueDecisionViewModelAttestationService.php`
- 新迁移
  - `database/migrations/20260823_zzzz_create_revenue_decision_snapshots.sql`
  - `database/migrations/20260824_enforce_one_operation_execution_task_per_intent.sql`
- 前端
  - `public/components/system/dual-ota-field-closure-loader.js`
  - `public/components/system/dual-ota-field-closure-panel.js`
- 校验与测试
  - `scripts/verify_dual_ota_field_closure.php`
  - `tests/DualOtaFieldClosureServiceTest.php`
  - `tests/RevenueDecisionSnapshotServiceTest.php`
  - `tests/automation/dual_ota_field_closure_integration.test.mjs`
  - `tests/automation/dual_ota_field_closure_panel.test.mjs`
- 候选文档
  - `docs/releases/production-candidate-20260824.md`

## 迁移边界与数据库验证

### 冻结 checksum

- 历史迁移 `20260810_z_version_hotel_collection_plans.sql` 保持字节级不变；SHA-256：`3186f495a14675ed26b13d2981a72e1f8e4cf70e696b281ce38226008061fffb`。
- 新迁移 `20260823_zzzz_create_revenue_decision_snapshots.sql`；SHA-256：`97bd6bf67fffaadb26d5b68bb7481900c085c1056376a06a31b1a24ae0cac9d6`。
- 新迁移 `20260824_enforce_one_operation_execution_task_per_intent.sql`；SHA-256：`20787fa910376050bd30554b41e33def54a176dc4615cd0a35a4689e1410f887`。
- `revenue_decision_snapshots.system_hotel_id` 已进入显式云酒店 ID 字段注册表。
- 没有修改数据库 checksum 记录，没有绕过迁移守卫，没有执行生产数据库迁移。

### 本地专用数据库证据

- `verify:mysql-fresh-concurrency`：PASS；MariaDB 10.4.32，迁移目录 184/184，当前版本 `20260824`。
- 全新专用库执行正式初始化、正式迁移两次、`db:check`：全部 PASS；两次迁移均保持幂等。
- 原始可重复迁移回放两次：PASS；历史一次性迁移仅凭冻结 checksum 做受控排除。
- 初始化结果：153 张表；checksum/执行基线 4/4。
- 8 个并发执行意图 worker 最终只产生 1 条唯一任务；并发合同全部通过。
- 预置重复执行任务的负向库：新迁移通过显式 `SIGNAL` 失败关闭；业务表和任务 DDL 哈希未改变，只增加失败审计记录。
- 浏览器 E2E 专用库已在验收结束后删除，复核 `INFORMATION_SCHEMA` 计数为 0。

## 关键发布阻断修复

1. **迁移注册缺口**：新增快照表的 `system_hotel_id` 已登记，云酒店 ID 迁移合同恢复通过。
2. **静态资源旧指纹**：运营脚本更新为 `20260824-operation-hotel-freeze-h7ae331f1d4`；组件 loader 与 index 统一为 `components/system/app-main-components.js?v=20260822-manager-capability-he5d5f0c8d0`。
3. **收益脚本热点**：`public/revenue-ai-static.js` 当前 SHA-256 为 `aaf53c84fa2e29d118526fabddcf2c8e85b8e7bca0f916358a50d5bcda289fbd`；新增“至少一条当前来源严格指标即可读取部分响应”的失败关闭合同，零条严格来源行仍拒绝，字段级遮蔽、快照和审批门禁不放宽。收益静态回归 51/51、收益闭环 1767 checks 均通过。
4. **启动切换真实缺陷**：用户在启动壳点击“可信收益分析”时，原实现会在完整渲染重挂载后丢失嵌套页签。现通过受限初始上下文覆盖保存并校验 `revenue/analysis`，鉴权后再归一化到可见页签；无权限账号仍回到运营罗盘。
5. **源码棘轮与预算**：`public/app-main.js` 为 55,805 行，门禁上限 55,806，当前余量 1 行；续推后入口 startup gzip 为 586,322 bytes，目标 620,000，余量 33,678 bytes，硬上限余量 63,678 bytes。
6. **已有事实晋级脚本**：改用 `ota_collection_anchor.v2` 的统一投影与哈希，并从指定任务的精确回读行重新计算历史核心字段状态；只读预检同时验证可撤销的本地分析授权，缺授权时在任何新增晋级写入前失败关闭。

## 验证记录

| 范围 | 结果 |
|---|---|
| PHP 专项 | 167/167 tests，1126 assertions，PASS。 |
| 续推 PHP 专项 | 双 OTA 字段闭环 + 平台同步：156/156 tests，1487 assertions，PASS。 |
| 晋级与授权专项 | 历史事实晋级、协调器、计划授权与每日操作终结器：67/67 tests，416 assertions，PASS；晋级脚本 PHP 语法与真实只读预检 PASS。 |
| PHP 全量 | PHPUnit 11.5.55 / PHP 8.2.12；4726 tests、43314 assertions、1 skipped、0 failure，耗时 2:12.625。全量后没有 PHP 源码变化。 |
| PHP 唯一跳过项 | `LoginRateLimiterDatabaseTest::testIndependentServiceInstancesShareAndReleaseAtomicCounters`；需要显式 `SUXI_LOGIN_RATE_LIMITER_DB_TEST=1` 的授权数据库环境。 |
| Node 专项 | 第一组 42/42，携程订单 9/9，AI 严格合同与运营前端 30/30，全部 PASS。 |
| 续推 Node 专项 | 字段面板、双页面接入、同步 CLI 精确回读合同：18/18 tests，PASS。 |
| Node 全量 | 冻结基线入口指纹前后均为 `7927050f94`；258 个测试文件，1745/1745 tests，fail/skipped/todo/cancelled 均为 0，exit 0，耗时 297934.158 ms。最终部分严格前端修复后未重跑全量；已重跑收益静态 51/51、收益闭环 1767 checks 与公开入口验证。 |
| 前端同步与构建 | 冻结基线 `build:frontend`、模板同步、startup helper 构建均 PASS；最终修复后重新执行入口构建与 `verify:public-entry`，PASS。 |
| 前端身份 | `app-main.min.js` SHA-256 `c0a4e4b812803017fb8d50c9d174c38e21e6e0c4ddeb89aa5c247b5a0fd47eb3`；收益脚本 SHA-256 `aaf53c84fa2e29d118526fabddcf2c8e85b8e7bca0f916358a50d5bcda289fbd`；组件 SHA-256 `e5d5f0c8d0d4e86166c166209474181755305dd42759dcee581ea08f34497548`；render `67a2af178f`；startup helper `73d03b1cb0`；bootstrap `8b3cb2a497`；deferred helper `f16ccab580`；双 OTA 字段面板 `77e48ce6fe`。 |
| 前端体积 | 源 3,427,804 bytes；入口产物 1,344,710 bytes；源 gzip 724,333；产物 gzip 465,624；启动 gzip 586,322。阻塞脚本 0。 |
| 续推字段合同 | 酒店 80 / `2026-08-23`：合同结构与刷新身份稳定性 PASS；整体 `partial`、严格完成 `blocked`、收益可消费字段 3；闭包摘要 `34add9b47b861b52e615106e761b53111bb1aa7532b9788c7086040eca74d2e8`，敏感值暴露为 false。 |
| 续推真实 DOM | 本机 8080 数据健康页显示携程 `#4428` 164/164、目标日 43/43，以及美团 `#4427` 134/134、目标日 7/7。候选静态前端短暂同源接入共享后端后，收益页刷新可选择 `2026-08-23 · 最近严格回读`，无“加载失败”，显示三项严格流量事实；金额/ADR 继续缺失，保存快照和生成待审批行动均禁用。验收后已恢复共享静态服务；该证据是本机静态候选 + 共享后端集成，不等同于候选完整独立启动或生产部署。 |
| 实际事实晋级 | 美团权威流量行 `#102476` 为 `validation_status=verified`、`history_status=success`、`readback_verified=1`；携程 verifier 仍为 `incomplete`。4 项本地分析检查因 `canonical_scheduled_analysis_status_not_enabled` 未获授权，写入 0、外部动作 0。 |
| 静态/闭环门禁 | 收益静态 51/51；收益闭环 1767 checks；业务页合同 93/93；工作树/CSS/核心范围/生产卫生/显示边界/受保护核心门禁全部 PASS。 |
| 真实 DOM | 使用项目 Playwright 隔离 runner 和 headless Chrome，经过真实本地路由、后端登录流程、启动壳与完整 Vue DOM；不是字符串夹具或仅 HTTP 200。 |
| 浏览器业务闭环 | 业务 E2E 3/3、公共页面 1/1、可信收益直接入口 1/1、最终完整渲染过渡套件 5/5，全部 PASS。 |
| 权限与酒店隔离 | 隔离数据库中的非超级管理员、单酒店上下文、权限/能力门禁、受保护读取和跨酒店负向路径通过；这不构成生产账号验证。 |
| 失败状态 | 最新 OTA 任务失败时保持 blocked/null，不借用旧成功回执；证据/摘要链漂移失败关闭；效果复盘缺源事实时保持 observing/blocked。 |
| 审批与外部动作 | 新建行动保持 `pending_approval` 且任务数 0；人工审批后唯一任务约束通过；未自动审批、未自动写 OTA/PMS、未发外部消息。 |
| 清洁性 | `git diff --check` PASS；专用 E2E 数据库、单个临时审计 JS、端口 18488/18489/18490 均无残留；8080 已恢复共享工作树静态服务，`/api/health` 为 200。 |

## 仍存风险

1. 候选尚未 commit，审查身份只能由当前工作树路径与冻结哈希表达；在获准固化前应保持该工作树只读。
2. 共享树的大量未提交内容无法可靠按作者归属；本候选依赖内容级白名单与测试证据，而不是未经证实的作者标签。
3. `app-main.js` 热点棘轮仅余 1 行；本候选已通过，但后续任何直接扩张都可能触发门禁，应继续拆分或压缩，不能抬高上限掩盖增长。
4. 启动 gzip 虽有 33,678 bytes 目标余量，但不是无限余量；后续前端变更需要重跑相同构建与指纹门禁。
5. PHP 全量有 1 个显式环境型跳过项；当前新鲜库并发门禁已覆盖本候选关键执行意图唯一性，但该独立 limiter 用例仍未在其专用开关下执行。
6. 本次补充了酒店 80、同一营业日的真实携程/美团 Profile 采集与回读，但严格字段闭环仍受携程 P0 流量证据、美团金额口径冲突和用户手工页面确认限制；不能据此宣称完整 `field_validated` 或全酒店经营结果。
7. 回滚方法已定义，但尚未在已部署环境演练；数据库只能前向修复，不能以改写历史迁移或 checksum 的方式回滚。
8. 未执行生产可用性、真实静态资源身份、生产数据库版本、真实登录或现场链路验证，因此不得宣称 `production-ready`。
9. 候选完整启动曾被当前共享 `hotelx` 的迁移证据守卫拒绝。只读诊断确认 `checksum_mismatches=[]`，实际阻断是 7 条只存在于共享树/共享库、未纳入本候选白名单的 `unknown_registrations`（酒店 GEO/入住率参考、本地第二大脑、AI 报告演示与 JHIRA 参考迁移）；未把这些无关能力扩入候选，也未绕过 checksum 或修改登记证据。因此最终浏览器证据只覆盖候选静态前端与共享健康后端的本地集成，完整候选运行时仍需在与候选 catalog 一致的隔离数据库上复验。

## 回滚方法

### 当前未提交候选

- 共享工作树未被修改；如用户决定放弃候选，可在确认无需保留后仅移除该隔离工作树。
- 不对共享树执行 reset、clean 或 checkout 覆盖。

### 未来获准提交或部署后

1. 以唯一候选 commit 和静态资源身份作为应用回滚单元；回滚到上一个已验证 commit 后重新构建并核对前一版资源哈希。
2. 在部署前记录数据库版本、迁移 checksum、备份/恢复点和受影响表；先在同版本演练环境验证恢复。
3. 已登记迁移保持字节级不可变。数据库问题通过新的、经审查的前向补偿迁移处理，不删除 schema-version 记录，不改写历史 SQL/checksum。
4. 决策快照、审批事件、执行证据和复盘事件属于审计链；回滚应用时不静默删除。需要作废时走正式 void/compensation 事件。
5. 若静态资源身份或页面闭环不匹配，停止现场写入验收，恢复上一版应用；未获用户主动批准前不得创建经营执行结果或 OTA/PMS 外部写入。

当前只完成了回滚方案设计，没有执行生产回滚演练。

## 现场验收步骤（部署后，需逐项授权）

1. **GitHub 固化**：获准后 commit/push/PR；从远端重新读取 commit、目标分支、文件清单与 CI，才能标记 `GitHub-persisted`。
2. **部署身份**：记录生产 commit；确认生产 `app-main.min.js` 与候选身份一致（当前候选前缀 `c0a4e4b812`，若受控重建改变哈希则记录并重新验签），同时核对组件 `e5d5f0c8d0`、收益脚本 `aaf53c84fa`、运营脚本 `7ae331f1d4`。
3. **数据库版本**：获准执行生产迁移后，确认版本 `20260824`、两条新增迁移已登记且 checksum 与本清单一致；再运行正式 `db:check`。不得手改 checksum。
4. **真实登录**：用户在原设备完成密码/MFA 等必要步骤；Codex 不读取或保存密码、Cookie、localStorage、Profile 或令牌。
5. **现场范围冻结**：记录酒店 ID、平台（携程/美团）、营业日、当前采集任务 ID、回执时间和操作者；每一项必须属于同一验收样本。
6. **真实字段**：逐字段核对当前回执的值、来源、记录 ID、采集状态与失败原因；缺失保持 null/blocked。制造一条或等待一条“最新任务失败”样本，确认不借用旧成功值。
7. **收益分析**：从页面入口进入“可信收益分析”，确认实际 DOM、酒店上下文、平台/日期口径和 OTA 渠道范围；不得扩写为全酒店经营结论。
8. **保存与刷新回读**：保存一个决策快照，记录快照 ID、内容摘要、证据摘要和来源记录；刷新页面后按同一 ID 精确回读并比对。
9. **待审批动作**：从同一机会创建行动，确认状态为 `pending_approval`、任务数为 0、没有自动审批、没有 OTA/PMS 写入或外部消息。
10. **酒店隔离**：切换到另一酒店做读取和写入负向测试；原酒店事实、快照、行动和任务不得显示或被提交。
11. **人工审批与单任务**：由用户主动批准；确认只创建一个本地执行任务。重复审批/并发请求仍只能保留一个任务。
12. **执行证据与复盘**：人工录入执行证据；无可信结果源时成功复盘必须失败关闭。证据充分后记录 observing/tracking/最终评估，刷新后精确回读事件链。
13. **无外部副作用**：检查平台操作日志与应用审计，确认验收过程中没有自动改价、改房态、发布、消息或其他 OTA/PMS 写入。
14. **回滚演练**：在非生产或获准窗口完成应用版本回退和数据库前向补偿演练，记录恢复时间、数据完整性与静态资源身份。

只有上述关键门禁、回滚演练和真实现场闭环都有可回读证据后，才能评估 `production-ready`；不能由本地测试结果自动升级。
