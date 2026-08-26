# 宿析OS：历史修改整合与极度详细项目审查

- 审查日期：2026-08-25（Asia/Shanghai）
- 审查代码快照：`9acd16471aded0ee523aca043fcad2da19570356`
- 审查分支：`review/extreme-merge-20260825`
- 审查工作树：`D:\桌面\SUXIOS\宿析OS初始版\.review-worktrees\extreme-merge-20260825`
- 基线：`origin/main@65433e7437e300b235ed59ea6d0dacd2fef255cc`
- `VERDICT: FAIL`
- `PROCESS_STATUS: READY`
- 成熟度：局部 `unit_contract_pass`；整体不是 `integration_pass`、不是 release candidate、不是 `field_validated`、不是 production-ready

## 1. 结论先行

历史修改已经被保存到一个可追溯的本地整合分支和提交中；共享的原始脏工作区没有被 reset、clean、覆盖或机械提交。整合提交本身可复现，但当前不能合入主线或发布。

阻断原因不是单一的“测试没绿”，而是已经确认有两项 P0：

1. 认证首屏的携程 startup facade 缺少两个根应用初始化时立即读取的 key，已登录应用可在初始化阶段直接崩溃。
2. `ota.collect` 采集权限可以调用一个带任意 Cookie/Authorization/正文的携程/美团官方域通用 POST 代理，绕过经营动作权限、人工审批和执行回读。通用认证 POST 能力已在零网络假传输中确认；本审查没有请求真实 OTA，因此没有声称某个真实写接口已改变外部数据。

此外还确认了多项 P1：新门店三源 onboarding 前端整体丢失、三源整点通知前后端状态机不对称、携程可见页面下载仍混用旧表、AI 顾问可把虚构金额标为 grounded、WeCom 事件存在不可恢复的 pending 崩溃窗口、人工点评订单决策存在“业务已提交但审计失败仍返回 500”的事务分裂、被三源门禁阻断的数字仍展示为经营事实、源码 no-growth 门禁失败 7 项，以及全量 Node/PHP 回归均不全绿。

## 2. “先合并所有修改”的实际执行边界

### 2.1 已整合并保存的来源

| 层级 | 提交 | 说明 |
|---|---|---|
| 远端基线 | `65433e7437` | 当前 `origin/main` |
| 本地 main 增量 | `40130a4cc1` | `feat: integrate local second brain runtime`，35 个路径 |
| 共享脏工作区快照 | `f389db94c0` | 精确保存 373 个非排除路径；原工作区/index 未改动 |
| 共享快照语义合并 | `b068c327f1` | 199 个冲突按业务语义解决，不使用整文件覆盖 |
| 已验证收益/OTA 候选 | `a59e2ac02a` | 精确 53 个路径的候选提交 |
| 候选合并 | `88bd26e990` | 20 个冲突按语义解决 |
| 其余有效本地修正 | `9acd16471a` | 显式纳入 81 个路径、4,668 insertions、731 deletions |

最终 81 个路径包括当前有效的跨酒店 fail-closed、PMS 来源身份、数据库范围门禁、HTTP 状态、online-data 缓存与强刷、前端下载/公开档案抽取、店长三问能力以及配套测试和生成物。11 个原未跟踪正式文件已显式纳入，没有遗漏被生产代码引用的新 service/static 文件。

### 2.2 没有盲目应用的内容

- 23 个 stash 均早于 2026-07-16，且多为重复、隔离或 quarantine 状态；没有按顺序盲目 apply。
- 多个旧 production-candidate、risk-remediation、deep-review、DeepSeek、罗盘、竞争圈、GEO、OTA automatic worktree 是较旧、相互冲突或替代方案；只抽取仍然独有且适用于当前主线的语义修正，没有整树覆盖。
- 58 个 linked worktree 仍存在，1 个 locked；本审查没有自动删除任何 worktree 或 stash。
- 没有移动本地 `main`，没有 push、PR、部署、真实 OTA 写入或外部消息。

因此这里的“全部”指当前有效、非排除、可归属的源代码修改全部被保存和语义整合；不包括把过期 stash、替代实验和相互冲突的历史版本机械叠加到产品代码上。

## 3. 验证矩阵

### 3.1 通过项

| 检查 | 结果 | 证据边界 |
|---|---|---|
| PHP 语法 | 49/49 变更 PHP 文件通过 | 只证明语法 |
| JS/MJS 语法 | 24/24 变更文件通过 | 只证明语法 |
| `git diff --check` | 通过 | 无 whitespace error/冲突标记 |
| 冲突标记扫描 | 0 | 扫描 app/route/scripts/docs/tests/resources/public |
| `verify:public-entry` | 通过 | 构建、CSS、入口、体积门禁；不能发现 facade API 缺 key |
| 前端模板 | 62 fragments，snapshot/manifest 一致 | render hash `8b4858f07f` |
| 前端入口 | source 3,411,092 bytes；artifact 1,338,903 bytes | artifact hash `61a07f4b90` |
| 启动 gzip | 582,398 / 620,000 bytes | 余量 37,602 bytes，预算通过 |
| 数据库版本只读检查 | 191/191 migrations，current | 未运行 migrate、未改 schema |
| PR base freshness | ready | base-only 0，审查代码提交前 head-only 9 |
| 店长能力 API verifier | pass | 本地、事务/无外部写；页面 verification 为 null |
| 店长评分 verifier | pass | 写入均在事务内，rollback 后 synthetic rows 为 0 |
| 自定义 OTA 请求 service tests | 7 tests / 55 assertions 通过 | 同时证明安全限制与通用 POST 能力确实存在 |
| 提交钩子 | 通过 | 证明 staged source/generated identity；不等于 integration pass |

### 3.2 明确失败项

| 检查 | 结果 | 说明 |
|---|---|---|
| 全量 Node | 1,803 tests：1,729 pass、74 fail、0 skip | 失败分布于 34 个文件，耗时约 338.9 秒 |
| 全量 PHPUnit | 4,844 tests、45,021 assertions：4,824 pass、14 failure、5 error、1 skip | 耗时约 149.4 秒 |
| source hotspot | 7 failures | 6 个棘轮超限、1 个未登记热点 |
| P0 guard 子项 | 10/13 通过、3/13 失败 | business-page、revenue-ai-closure、e2e-contracts 失败 |
| startup facade 聚焦测试 | 2/3 pass、1 fail | 缺 `ctripRankEligibilityText` 与 `captureCtripBusinessDownloadSnapshot` |

Node 的 74 个失败不能等同为 74 个产品故障：其中既有本报告确认的真实回归，也有旧源码字符串、旧变量名、互相矛盾的两代 golden contract 和损坏 fixture。反过来，也不能因为部分测试过期就忽略整套红灯：P0 startup facade、onboarding 丢失、整点通知控制面丢失、下载事实源错误、hotel-ID registry 漏项、public profile 选店错误和 lazy asset hash 过期均有独立代码证据。

PHP 的 19 个非通过 case 也需要分类：

- `ComposerWorktreeAutoloadTest` 是本次隔离树为了复用依赖而建立共享 `vendor` junction 所触发的环境身份失败，不能直接定性为产品代码故障；但它说明当前依赖隔离还不够强。
- 明确代码错误包括 `OperatingQuestionKnowledgeRetrievalService.php:364` 对整数日期词直接调用 `mb_strlen()` 的 TypeError。
- AI knowledge manifest digest mismatch、Local Second Brain/WeCom contract、Meituan funnel、route coverage、Revenue Decision Frame 等属于生成物/合同/合并漂移，需逐项修复或更新经过审查的测试合同。
- `OperationEffectReviewServiceTest` 与新的“pending intent 必须零任务”硬门禁发生预期冲突，不能为追绿直接放宽生产门禁，应重写 fixture 验证新不变量。

## 4. P0：发布与主线合并阻断

### P0-1 认证首屏携程 facade 缺 key，可让根应用初始化失败

证据：

- `scripts/lib/frontend_startup_helpers_build.mjs:16-37`：首屏只加载 `ctrip-static-loader.js`，完整 `ctrip-static.js` deferred。
- `public/app-bootstrap.js:578-610`：先加载 startup prerequisites 和 `app-main.min.js`。
- `public/app-main.js:1228-1236`：`requireCtripStatic()` 对缺 key 直接 throw。
- `public/app-main.js:2823,2832`：根 setup 立即读取 `ctripRankEligibilityText` 和 `captureCtripBusinessDownloadSnapshot`。
- `public/ctrip-static-loader.js:797-866`：facade 漏掉这两个 key。
- VM 对比：loader 107 keys，full 109 keys，差集正好是上述两项。
- `tests/automation/frontend_startup_helpers_build.test.mjs:44` 稳定复现失败。

影响：错误发生于已认证根应用初始化，而不是用户进入携程页之后；可以使整个登录后应用进入“项目启动失败”。`verify:public-entry` 仍通过，证明现有入口门禁有假阴性。

最小修复：让 facade export 来源由 full module 的同一声明生成，或至少补齐两个 key、重建 startup/helper/entry/fingerprint，再做实际已认证首页验证。

### P0-2 `ota.collect` 可调用携带凭证的官方域任意 POST 代理

证据链：

- `route/app.php:309,445`：路由在普通 Auth 组。
- `OnlineDataRequestConcern.php:2618-2651`：只要求 `can_fetch_online_data`，随后接收 `url/method/headers/body`。
- `OnlineDataSupportConcern.php:71-97` 与 `Role.php:103-116,128-131`：该权限等价 `ota.collect`，与 `operation.execute` 分离。
- `OtaCustomRequestService.php:16-22,46-74,130-170,178-245`：允许任意官方子域路径上的 GET/POST，Cookie、Authorization 和正文均可透传。
- `resources/frontend/app-template.html:18262-18289`：页面向有采集权限的用户公开 URL、GET/POST、Authorization 和任意请求体输入。
- 纯内存假 transport 用 synthetic `/api/operations/change-price` 捕获到 POST、Authorization、Cookie 和 JSON body 全部原样进入 transport；没有网络请求、没有 DB/外部写。
- `tests/OtaCustomRequestServiceTest.php:106-126` 也把 POST + Authorization + body 当作既有成功合同。

影响：仅有采集权限的账号可借宿析OS服务器向携程/美团官方域发起认证 POST，绕过 execution-intent、`operation.execute`、人工确认、幂等键和外部结果回读，并可能借用服务器出口/IP 信任。真实 OTA 某个写 API 是否存在、是否接受该请求、是否实际改变数据未验证。

最小修复：删除通用 POST；报表型 POST 必须使用服务端固定的 host + exact path + method + request schema allowlist。任何可能改变外部状态的请求进入正式经营动作审批/执行/回读链。成功审计还应绑定 tenant、system hotel、动作模板和固定 endpoint，不能以 hotel `null` 记录。

## 5. P1：高优先级正确性、稳定性与事实边界

### P1-1 新门店四步三源 onboarding 从前端整体消失

- 当前 `public/components/system/app-main-components.js:917-1027,2158` 无 `HotelThreeSourceOnboardingPanel`，factory 也不导出。
- `public/app-main.js:272,290-313` 不解构、不注册该组件。
- `resources/frontend/templates/fragments/40-dialog-hotel.html:20-153` 只剩普通门店表单/PMS/旧平台配置。
- 后端 `route/app.php:138,143-144` 和 `Hotel.php:456,700,808` 仍保留 onboarding、collection plan、PMS binding。
- `origin/main` 有约 337 行完整组件；当前聚焦测试在 marker 缺失时顶层失败，后续细粒度断言根本未执行。

影响：用户只能创建门店记录，无法完成“门店 → OTA/PMS 身份 → 验证 → 显式启用采集 → 企业微信”的可信入口闭环；后端能力成为不可发现孤岛。

### P1-2 三源整点正式通知后端可执行，前端控制面被删除

- 后端 `ManualNotificationService.php:250-286,511-515,696-711` 支持严格 `hourly_on_the_hour` 三源计划和 hotel-scoped readback。
- `ManualNotificationScheduleRuleService.php:18-35` 正确按 Asia/Shanghai 处理 00:00 前一业务日。
- 当前 `app-main.js:26608-26635` 只提供 manual/daily 和酒店80 30分钟 interval。
- `wechat-notification-static.js:341-349,605-620` 对经营日报隐藏 hourly 控件。
- `origin/main` 中的 hourly preset、readiness、expiry recovery 和来源修复动作被合并删除。

影响：旧/数据库中的计划仍可能执行，但当前 UI 不能正确创建、查看、修复或解释状态；后台状态继续存在而前台失去控制面。

### P1-3 携程“下载当前可见页面”仍用旧 reactive table

- `ctrip-static.js:3773-3874` 已从 DOM 捕获真实可见 cards/table/source notice。
- `app-main.js:51634-51646` 却把 `table: ctripDownloadRows()` 传给 canvas，而不是 `visibleSnapshot.table`。

影响：导出 PNG 与用户眼前的分页、筛选、列顺序、动态标题和格式化文本可能不同，破坏“可见页面即证据”。

### P1-4 两张 AI 报告表的 hotel_id 未进入云迁移 registry

- 新迁移创建 `ai_report_presentation_specs.hotel_id` 与 `ai_report_presentation_artifacts.hotel_id`。
- `scripts/cloud_hotel_id_column_registry.php` 未将其列为 positive/negative/derived 任一类别。
- 自动 contract 明确失败并列出这两列。

当前 guard 是 fail-closed，因此未证明跨酒店泄漏；但 hotel merge/rename/cloud migration 会被阻断，绕过 guard 则可能形成酒店身份漂移。

### P1-5 携程公开档案自动选店错误依赖“已有采集配置”

- `app-main.js:35916-35934` 已有 permitted-hotel 的 `ctripPublicProfileHotelOptions`。
- `app-main.js:20138-20152` 的 public profile selector 却使用只含已有采集配置的 `ctripTargetHotelOptions`。

影响：有酒店权限但尚未配置采集的门店无法完成公开门店 ID/公开档案闭环，错误地把公开数据流程与账号采集配置绑定。

### P1-6 `operation-static.js` lazy cache fingerprint 过期

- `app-main.js:23104-23105` 固定版本 `20260824-operation-hotel-freeze-h7ae331f1d4`。
- 当前 `operation-static.js` SHA-256 前10位为 `296c865cf0`。

影响：浏览器/CDN 可复用旧 lazy module，与新主入口形成契约错配；可能表现为只在部分浏览器出现的动作标签、状态或恢复逻辑故障。

### P1-7 顾问 grounding 只校验百分比，虚构金额仍可被标为 verified

- `OperatingQuestionCouncilService.php:410-435,456-523` 只抽取带 `%/％` 的数字。
- 有合法事实 ref 即可满足引用存在性；人民币金额、订单、间夜、日期等没有 value/unit/date/scope 绑定。
- 反射复现：事实仅有 `123.45 CNY`，模型声称“订单收入为99999元”并引用合法 ref，校验通过。

影响：不会自动执行，但会把未经事实支持的量化结论标为 `verified_scope_guard_passed`，对运营人员形成错误信任。

### P1-8 WeCom inbound 的 pending 崩溃窗口会永久吞事件

- `WecomInboundService.php:277-289` 先插入 `processing_status=pending`。
- `:292-355` 后续才执行问答并更新终态。
- `:358-401` 的相同 event/digest 重试直接返回 duplicate success，不检查 existing 是否仍是 pending，也不验证最终 update 的 affected rows。

确定性故障序列：插入成功 → 进程崩溃 → 平台重试 → duplicate 分支确认成功 → 原记录永久 pending、不会再处理。

### P1-9 WeCom AI Bot 首次绑定后的同帧重试发生 digest 冲突

- 第一次绑定会把内容脱敏为 `绑定门店 ********` 后归档 digest。
- 同一 msg_id 原样重试时会话已绑定，走普通回答并用原内容计算 digest。
- 同 event ID 两次 digest 不一致，返回 409，而不是稳定 duplicate readback。

最小修复是在任何绑定状态转换前先按 event identity 去重；原始事件身份 hash 与持久化脱敏展示分离。

### P1-10 点评—订单决策在 commit 后写审计，形成“数据已变但 API 失败”

- 美团 bind/reject/unbind：`MeituanReviewOrderMatchConcern.php:380/386,481/487,556/562`。
- 携程 reject/unbind：`CtripReviewOrderMatchConcern.php:808/814,907/913`。
- `OperationLog::record()` 可抛异常；业务事务已经 commit，外层 catch 却返回 500。
- 携程 bind 在 commit 前写审计，是可复用的正确模式。

影响：客户端看到失败后重试，实际状态已改变且审计可能缺失，破坏人工决策的幂等和证据链。

### P1-11 三源门禁 blocked 时仍展示具体经营数字

- `SingleHotelOperatingBriefService.php:19-38,43-117,122-146` 声称 blocked 只显示阻断，却仍无条件格式化 facts。
- `OperatingTargetNotificationPayloadService.php:81-122` 与 `OperatingTargetReportGateService.php:36-67,422-466,735-780` 页面预览同样不按 source `delivery_evidence_ready` 隐藏数字。
- 纯服务 sentinel 复现：三个来源都 blocked，`98765.43` 与 `54321` 仍出现在内容中。

正式 delivery candidate 会被 gate 阻断，因此没有确认外发；问题是本地用户仍会把未通过身份/日期/回读的数据误当成可决策事实。

### P1-12 源码 no-growth 硬门禁失败 7 项

| 文件 | 实际 | 强制上限 | 结果 |
|---|---:|---:|---|
| `PlatformDataSyncService.php` | 4,546 | 4,379 | +167 |
| `OperationManagementService.php` | 7,207 | 6,294 | +913 |
| `PlatformDataSyncServiceTest.php` | 6,465 | 6,334 | +131 |
| `AgentOtaDiagnosisPersistenceConcern.php` | 2,442 | 2,399 | +43 |
| `PlatformSyncTaskConcern.php` | 2,396 | 2,349 | +47 |
| `PlatformDataPersistenceConcern.php` | 1,738 | 1,700 | +38 |
| `public/revenue-ai-static.js` | 5,955 | discovery 5,000 | 未登记热点 |

这是 CI `.github/workflows/php.yml:217-218` 执行的项目自有硬门禁，不是主观代码风格意见。

### P1-13 本地/文档/P0 hook 与 CI 的 gate 清单漂移

`verify:p0-guards` 不包含 source-hotspot，staged frontend hook 也不包含；CI 另起 step 执行。当前正是本地提交钩子通过、CI hotspot 确定失败的真实样本。需要唯一 canonical `verify:integration`，由规则、hook 和 CI共同引用。

### P1-14 控制器未知 Throwable 原文直接返回客户端

新增/扩展路径包括：

- `CtripReviewOrderMatchConcern.php:711-712`
- `MeituanReviewOrderMatchConcern.php:74-75,285-286,402-403,577-578`
- `AiDailyReport.php:189-193,229-233,280-284,326-330,371-375,458-461`

当前 59 个 controller 文件使用 `$e->getMessage()`；七个不同 `safeErrorMessage` 又有不同放行逻辑。数据库、路径、上游错误可能进入 500。应统一映射明确的 4xx business exception；未知 5xx 只返回稳定 code + correlation ID，详情只进脱敏日志。

### P1-15 收益快照的“今天”由浏览器与后端两个不可冻结时钟生成

- `system-static.js:595-599` 用浏览器本地日期。
- `app-main.js:44124-44139` 在 Vue computed 中调用非响应式 `new Date()`；跨午夜可缓存旧日。
- `app-main.js:46233-46245` load 时再次读浏览器今天。
- `RevenueDecisionViewModelAttestationService.php:424-432,1441-1458` 保存时按 Asia/Shanghai 重新生成完整模型。
- `RevenueDecisionSnapshotService.php:446-456` 强制全等 attestation。

非上海浏览器或 23:59 打开、00:01 保存可产生 `view_model_unattested`。应由 overview 返回固定、版本化 `as_of_date`，前端展示、快照和服务端证明全部复用同一值。

## 6. P2：架构、规则、测试与规模风险

### P2-1 任意数据库异常被伪装成“表未迁移”

`AiEvaluationRunService.php:241-247`、`WecomAibotService.php:679-695`、`OperationManagementService.php:634-643,5570-5577` 和 `DatabaseSchemaRequirement.php:28-39` 对任意 Throwable 返回 schema gap。连接中断、超时和权限错误会误导用户迁移。应复用 `DatabaseSchemaGuard.php:36-57` 的 `upgrade_required` 与 `check_failed` 分类。

### P2-2 巨型服务、trait 隐式合同和循环依赖形成高变更风险

| 文件 | 行数 | 方法数（约） | 主要风险 |
|---|---:|---:|---|
| `OperationManagementService.php` | 7,207 | 166 | 6 traits、61 Db、约247 new |
| `RevenueAiOverviewService.php` | 6,386 | 142 | 与 operation/approval 双向调用 |
| `OtaLocalCollectorService.php` | 6,376 | 110 | 99 Db、采集/lease/持久化耦合 |
| `PlatformDataSyncService.php` | 4,546 | 97 | trait 依赖 host 私有常量 |
| `Agent.php` | 3,149 | 86 | 6 traits、路由编排过重 |

已确认调用环：OperationManagement → RevenueCockpitApproval → OperationManagement / RevenueAiOverview → OperationManagement。当前未发现立即无限递归，但测试替身、故障定位和查询成本都变差。应按 provenance strategy、operation read model、lifecycle write service 做小步抽取，不做一次性重写。

### P2-3 收益 visible model 在 JS 与 PHP 双重实现完整 UI 语义

`revenue-ai-static.js:4971-5149` 与 `RevenueDecisionViewModelAttestationService.php:345-524,1441-1458,1927+` 各自生成状态、中文文案、CSS class 和卡片，再要求完全相等。应保留事实/证据 attestation，但改为服务端签发 versioned canonical model + digest，前端只渲染。

### P2-4 校验脚本已成为第二套未治理单体

- `package.json` 有 240 scripts：verify 93、test 25、review 17、report 13、build 11；没有统一 `test/verify/ci` 入口。
- `scripts/` 约 428 个文件，168 个 `verify_*`。
- `verify_e2e_contracts.mjs` 约 8,021 行，约 1,471 个 `requireText`、559 个 `requireNoText`；把多个 concern 拼字符串后找 token，不能证明 token 位于真实调用路径。
- `verify_worktree_guard.mjs` 内嵌美团业务字符串，职责漂移。

应建立 domain contract registry、AST/runtime assertion，并把 scripts 自身纳入 hotspot budget。

### P2-5 多个测试文件用顶层静态断言，一个旧断言会吞掉后续安全覆盖

268 个 Node test 文件中，至少 11 个文件共有 601 个顶层 `assert.*`。`access_tier_permissions.test.mjs` 有 444 个顶层断言，并同时要求旧 map 实现和当前显式两平台实现；第一个失败会终止后续约两百条断言，还会把数 MB 源码打印为 actual。

`cloakbrowser_launcher.test.mjs` 的 fixture 也被拼接错位：测试间变量作用域错误、同一输入要求两种冲突错误文案，当前失败不能归因于 launcher 实现。安全测试应按行为拆为独立 `test()`，用短 message，避免为追绿削弱实现。

### P2-6 Node runner 无全局/分文件超时与 hang 定位

`run_node_automation_tests.mjs` 把 268 个文件以 `--test-concurrency=1` 交给一个 `spawnSync()`，没有 timeout/watchdog/最后完成文件记录。串行是合理的共享状态保护，但一个未关闭 server/timer 就能让整套无结论。应保持串行，改为有超时的小批/逐文件执行。

### P2-7 Windows 是主要运行控制面，正式 CI 全为 Ubuntu

`.github/workflows/php.yml` 的 jobs 全是 `ubuntu-latest`；而启动、Task Scheduler、Profile login、dispatcher、lease/process ownership 依赖 Windows/PowerShell。至少 25 个真实 Windows 测试在非 win32 跳过。应增加聚焦 `windows-latest` smoke job，不必复制整套回归。

### P2-8 路由和项目规则共同固化单文件冲突热点

`route/app.php` 941 行，约 620 个 route/group 调用、46 group、50 middleware；auth/hotel/OTA/revenue/operations/AI/admin/WeCom 全部集中。规则又要求路由都直接写在该文件。应改为明确的 domain route manifest，并保留 route/auth/permission contract。

### P2-9 自定义 OTA 代理返回完整 upstream response headers

`OtaCustomRequestService.php:207-223,269-290` 收集并拼接所有响应头，控制器 `OnlineDataRequestConcern.php:2647-2651` 把它们放入 JSON。`Set-Cookie`、`WWW-Authenticate`、认证挑战或内部诊断头可能被前端读取。默认不应返回；如需诊断只 allowlist content-type、安全 request-id 和批准的 rate-limit headers。

### P2-10 模型文档与实际运行模型身份相反

`docs/capability-absorption/2026-08-23-master-perspectives-advisory-council.md:23` 声称复用 qwen3:8b；代码/迁移固定 qwen3:4b，本机只读 `ollama list` 也没有 8b。运行时不因此阻断，但证据记录失真。模型声明应由保存配置和 Ollama readback 生成。

### P2-11 通知调度 limit 不限制候选加载，并对 due row 做 N+1 查询

`ManualNotificationScheduleService.php:150-255` 先加载全部 enabled candidates，再用 `$limit` 限制新工作数量；dispatch 模式逐条调用 `existingDispatch()`。数据量小时不构成已测性能故障，但规模增长后扫描、内存和查询数按全部候选增长。应使用 bounded keyset chunk 和本轮 dispatch slot 批量预读。

### P2-12 前端首屏预算通过，但 69.7% gzip 来自一个 54k+ 行入口

`app-main.min.js` gzip 405,895 bytes，占 startup 582,398 bytes 约 69.7%；目标余量只有 37,602 bytes（约 6.1%）。这不是已证实的首屏性能故障，但本次 facade 漂移已经证明“大入口立即读取 + 功能模块 deferred”的合同脆弱。应继续真实 lazy extraction，不能提高预算掩盖增长。

### P2-13 58 个 worktree 使运行身份容易串树

本地 main、共享 HOTEL、review tree 和大量历史 worktree 指向不同 SHA。HTTP 200、8080 页面或静态文件存在都不足以证明运行的是本次提交。启动/验收应输出并核对 repo realpath、HEAD、dirty state 和 public digest；旧树只能在人为确认归属与 clean 后逐个退役。

## 7. 已做得好的保护

这些优点不抵消上述阻断，但值得保留：

1. 前端 fragments 是 canonical source，manifest 绑定 snapshot hash/bytes；构建脚本能阻止 source/generated 混版和构建期间并发漂移。
2. 提交钩子在 staged index snapshot 上验证，没有借用共享脏工作区制造假绿。
3. 已登记 migrations 未被改写；当前差异是新增迁移，checksum/unknown registration/fresh-repeat-concurrency 都有 fail-closed 保护。
4. 收益和运营决策大量使用 append-only snapshot、digest、正式保存后二次 exact GET readback。
5. 经营动作继续保持 `pending_approval`，未发现模型自动审批或自动 OTA 写入路径。
6. 证据不足时多数核心路径保留 `null/partial/blocked`，没有用 0 或旧值掩盖缺口。
7. 本次 PMS 来源选择把钉钉岛、Meituan Cloud PMS 和通用 PMS 身份分开，并在已检查消费者中要求 `readback_verified`。
8. `BaseTenantModel` 当前修正不再吞数据库异常为 tenant 0；未发现新的跨酒店读取证据。
9. 自定义 OTA service 的 HTTPS、官方域后缀、公共 IP DNS pin、禁重定向、禁代理、大小限制和 hop-by-hop header 限制本身实现良好；问题在业务动作 allowlist 和权限层。
10. WeCom 回调有签名、AES、CorpID、会话 hash/XML 安全检查；AI Bot relay 有 loopback 和高熵 token 限制。幂等问题不否定这些认证保护。
11. 媒体处理使用固定脚本、数组参数、文件类型/大小限制和本机 Ollama，聚焦审查未发现命令注入。
12. `operation-static.js` 等领域抽取方向正确，问题是 fingerprint 和入口合同没有统一生成。

## 8. 最小修复顺序

建议只按一条链处理，不同时开多个共享前端大文件改造：

1. **立即封住外部写边界**：移除通用 OTA POST；固定只读 endpoint/schema allowlist；补 tenant/hotel/action 审计。
2. **恢复可启动性**：补齐/自动生成 Ctrip startup facade，重建产物，跑聚焦测试并实际登录首页。
3. **恢复核心用户入口**：语义恢复三源 onboarding；不要整文件 checkout 覆盖当前收益/PMS 修改。
4. **恢复通知控制面**：在保留酒店80 interval 特例的同时恢复 strict hourly preset/readiness/expiry recovery。
5. **修复事实边界**：blocked source 不展示数字；Council 对所有量化 claim 做 metric/value/unit/scope/date 绑定。
6. **修复事务/幂等**：WeCom pending lease/retry、绑定帧 digest、点评订单审计与业务同事务。
7. **修复剩余确定性前端回归**：DOM-visible table、public profile permitted hotels、lazy hash、cloud hotel-ID registry。
8. **恢复 integration gates**：处理 7 个 hotspot failure；建立 canonical integration command；修复/更新经过审查的 Node/PHP contracts。
9. **统一错误与 schema 分类**：unknown Throwable 固定 5xx + correlation ID；DB missing/unreadable 分开。
10. **再做小步架构抽取**：operation read/write、provenance strategy、revenue canonical model、domain routes、verifier registry。
11. **补 Windows 聚焦 CI 与有超时的 Node runner**。

每一轮都应保持：原始脏工作区不被覆盖、hotel/tenant/date/source 边界、pending approval、零外部写、正式保存后二次 exact readback，以及局部修复后全量回归重新计数。

## 9. 未验证与禁止过度解释的范围

- 未请求真实携程/美团，因此没有验证任何真实 OTA 写接口、登录态、采集成功率或平台字段现状。
- 未发送企业微信、未执行真实定时任务、未运行云端浏览器/Cloudflare/Tencent Cloud release。
- 未合入 main、未 push、未建 PR、未部署。
- 未做生产数据迁移，只运行只读 `db:check`。
- 未在当前最终提交上完成已认证浏览器页面验收；P0 startup 问题使“页面可用”不能宣称。
- 未做生产负载、长稳、灾备恢复、跨实例或现场经营效果验证。
- Node/PHP 聚焦或全量测试通过都不能授予 `field_validated`；当前全量测试本身也没有通过。

最终可接受的状态表述只有：**历史修改已在本地隔离分支完成可追溯整合；审查过程完成；整合结果因已确认 P0/P1 和回归门禁失败而阻断，尚不可进入 main/发布/生产。**
