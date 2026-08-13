# 宿析OS 极度详细 Review 与锤炼修复报告

日期：2026-08-13
仓库：`D:\桌面\SUXIOS\宿析OS初始版\HOTEL`
基线 HEAD：`378015f8538c344145ce8d67baa85af733361373`
审查模式：Gauntlet evidence-calibrated review；并行只读审计 → 最小修复 → 全量回归 → 多轮 fresh-context 零写盲审与定向返工
当前结论：`PASS_WITH_KNOWN_RELEASE_BLOCKERS`（累计确认并修复/收口 119 个 P1/P2 缺口；按用户明确要求在最后 3 个 P1 定向修复与验证后停止扩展盲审）

## 1. 结论摘要

本轮初审、十九轮 fresh-context 零写盲审及确定性全量回归累计确认 76 个 P1 与 43 个 P2，共 119 个真实可触发缺口；每轮盲审发现均先判定 `FAIL` 再进入定向返工。返工覆盖权限精确路由、跨酒店/跨租户证据重绑、执行生命周期与旧策略动作的当前 tenant 约束、reschedule/read/list/flow/latest-attempt、simulation/expansion 与全部 source-backed 创建/审批 tenant/currentness/replay 边界、quant 权威酒店身份、审批/创建/任务写入的酒店/任务/意图/来源行锁协议、详情读取的 schema fail-closed、日报与 online OTA 经营事实 current-tenant SQL scope、手工运营记忆/目标合同/intervention 与 assessment 的迁租/证据竞态、来源漂移与 TOCTOU、稳定业务快照幂等、bridge/tracking 展示投影与业务 digest 隔离、订单路径与值级 PII 脱敏、未知身份包装 fail-closed 与未知经营对象保真、source module 与 payload key 大小写/驼峰/连字符规范化、控制器重放副作用、业务口径/流量别名与跨日基线混算、正式日报 status 契约、请求天数覆盖分母、采集时间真实性/小数秒选优/统一上海业务日与严格历史 DB 时间兼容、schema 缺失与截断的诚实状态、bridge 大小写/驼峰/祖先 scope/残留 ID 旁路、外部副作用重复、来源执行意图并发重复、监控原子性、测试并发隔离、动态时间断言稳定性、PHP 版本声明漂移、目标日期缺事实却先调用模型、完整 staged-index 构建输入/生成物/public-entry 门禁，以及前端静态契约未跟随组件边界等问题。

确定性结果：

- PHP 全量：4,386 tests、40,517 assertions、1 skipped，退出码 0。
- Node 全量：233 个文件，1,553/1,553 通过。
- P0 门禁：高风险安全、工作树保护、登录/认证样式、Revenue AI、生产入口、展示边界、受保护核心契约全部通过。
- 数据库：主本地库与专用性能 E2E 库均为 171/171；新增列与唯一索引精确读回。
- fresh 认证运行时性能：5/5 有效，当前产物 digest 一致；硬门槛通过。
- 静态启动包预算仍失败：779,329 gzip，超过 650,000 硬上限 129,329 字节。
- 本机 PHP CLI 缺少 `ext-gd`；Composer 元数据有效，但当前 CLI 不能满足 PhpSpreadsheet 的完整生产平台要求。
- 本地 health 与首页均为 HTTP 200，但运行模式是 `development_fallback`，`production_runtime_ready=false`。
- 热点零增长 ratchet 通过，但严格架构目标线失败；历史大文件债务没有被本轮测试绿灯掩盖。

因此，本轮“审查与高优先缺陷修复”已形成集成级闭环；“生产发布就绪”仍不成立。

## 2. 冻结的评价合同

### 2.1 主要用户价值维度

1. 酒店、租户、平台、来源、业务日期与指标 scope 必须精确绑定。
2. 缺目标日期事实、失败采集或不一致证据必须失败关闭，不得用历史、默认值或模型文案补齐。
3. 外部副作用必须先有持久化身份与可读回状态；未知结果不得盲重试。
4. 并发重放只能回读同一业务事实，不能产生重复执行意图、重复告警或重复通知。
5. 修改必须通过相关全量回归、P0 门禁、schema 读回和本地实际运行检查。

### 2.2 硬门禁

- 跨酒店/跨租户证据或写入：失败。
- `operation.execute` 可被宽泛 AI/投资权限旁路：失败。
- PMS 全酒店指标与 OTA 渠道指标混加并产生派生经营指标：失败。
- 外部企业微信或首次调度可能因本地状态写失败而重复执行：失败。
- 目标期无事实仍进入模型调用：失败。
- 迁移未完整应用或唯一索引无法精确读回：失败。
- 只以测试绿灯替代实际失败状态：失败。

### 2.3 非目标与授权边界

- 未执行真实 OTA 采集、OTA 写价、生产迁移、企业微信真实发送、云部署、提交、推送或 PR。
- 未清理或重置脏工作树；保留全部用户与并发任务改动。
- 未把 OTA 渠道事实扩大为全酒店经营事实。
- 本轮不以大规模重写消除全部历史热点；对未直接阻断本次闭环的架构债保留 no-growth ratchet。

## 3. 对照基准

- OWASP ASVS 5.0：服务端授权、输入/数据边界与可验证安全控制。
  - https://owasp.org/www-project-application-security-verification-standard/
- OWASP API Security Top 10 2023：对象级与功能级授权、资源与业务流滥用。
  - https://owasp.org/API-Security/editions/2023/en/0x11-t10/
- PHP 官方支持版本：依赖运行时声明必须与实际受支持版本一致。
  - https://www.php.net/supported-versions.php
- PHPUnit 11.5：fixture 与 text runner 的隔离/执行约定。
  - https://docs.phpunit.de/en/11.5/fixtures.html
  - https://docs.phpunit.de/en/11.5/textui.html
- MySQL InnoDB locking：唯一约束、事务和并发写入不能只依赖应用层 check-then-insert。
  - https://dev.mysql.com/doc/refman/9.7/en/innodb-locking.html
- GitHub dependency review 与 protected branches：依赖变化和合并保护应由自动门禁补强。
  - https://docs.github.com/en/code-security/concepts/supply-chain-security/dependency-review
  - https://docs.github.com/en/repositories/configuring-branches-and-merges/in-your-repository/managing-protected-branches/about-protected-branches

这些基准用于界定控制目标，不用于声称本项目已获得任何外部认证。

## 4. 已确认并修复的问题

### SEC-BFLA-001 — P1 — 执行意图入口绕过 `operation.execute`

原问题：多个 AI、收益研究、投资、时间洞察与 feasibility 路由被宽泛能力分类，能在没有 `operation.execute` 的情况下写入运营执行意图。

修复：

- `ProtectedCapabilityService` 增加 execution-intent POST 精确模式，优先归入 `operation_execution`。
- 各控制器在解析真实 `hotel_id` 后再次执行酒店级 `operation.execute` 授权。
- `Base` 增加一致的 hotel capability denied 响应。

验证：受控伙伴、错误酒店与无执行权限路径聚焦回归；P0 高风险安全门禁通过。

### COR-SCOPE-003 — P1 — feasibility 报告可从酒店 A 重绑到酒店 B

原问题：执行入口采用客户端重新传入的酒店 ID，同时复用原报告证据和结论。

修复：

- `FeasibilityReportService` 从已保存 input/snapshot 解析执行酒店。
- saved input、snapshot scope、request 与 intent 的酒店必须一致；冲突或历史缺 scope 时失败关闭。

验证：A 报告提交 B 返回冲突且零新增；A→A 正常创建并读回。

### ARCH-001 — P1 — PMS 全酒店与 OTA 渠道指标双计/跨口径派生

原问题：全酒店日报与 OTA 渠道 revenue/orders/room nights 被累加到同一标量，再计算 ADR/OCC/RevPAR。

修复：

- `OperationSnapshotConcern` 增加 daily order coverage，避免同日 OTA 与 PMS 订单双计。
- ADR 只在同一精确 scope 内派生。
- OCC/RevPAR 仅允许全酒店 scope。
- 混合 scope 返回 `operation_metric_scope_mixed` 与 partial，不再伪装 `ok`。
- 首轮盲审发现 30 日 baseline 仍可能把不同日期的 whole-hotel 与 OTA-channel 日摘要混入同一平均值；第二轮进一步发现 conversion/traffic 的日期与平台、以及 source-only OTA 平台仍未完整进入签名。最终返工后，每个平均指标都绑定精确日期、scope 与规范化平台，平台可从合法 source 字段归一化；任一窗口漂移返回 `baseline_scope_drift`、`partial` 和全量不可计算平均值，策略模拟不再消费混合基线。

验证：PMS orders=10、OTA orders=3 不再得到 13；跨 scope 派生值保持不可计算；交替 PMS/OTA、source-only 携程/美团、跨日独立 traffic conversion 均触发 drift；同平台同 scope 正例保持 ok；baseline 聚焦回归 5 tests / 39 assertions。

### ARCH-002 — P1 — PHP 兼容声明低于锁定生产依赖

原问题：项目宣称 PHP >=8.0，但锁定的 PhpSpreadsheet 需要 PHP ^8.2，CI 也只覆盖 8.2。

修复：

- `composer.json`、`AGENTS.md`、`README.md` 统一最低 PHP 8.2。
- Composer lock 元数据刷新，不更换无关依赖版本。

验证：`composer validate --strict` 通过；当前 PHP 8.2.12 满足 PHP 版本约束。环境仍缺 `ext-gd`，见开放项。

### STAB-001 — P1 — Autopilot 首次调度先外发后持久化

原问题：`start_now` 在首次 dispatch 状态 CAS 之前执行；若之后 CAS/回读失败，队列重试可能重复启动。

修复：

- 调度前先以 digest/CAS claim 并持久化首次 dispatch 身份。
- 只有 claim owner 可以传递 `start_now=true`；后续调用只能精确回读并返回 false。
- 加入“外部调用后 CAS 冲突、第二次不得重复启动”测试。

验证：模拟冲突后外部启动调用总数保持 1。

### STAB-002 — P1 — OTA 失败企业微信通知可并发重复发送

原问题：Cache get → 远端发送 → Cache set，两个 worker 可同时未命中并重复外发。

修复：

- 新增 `ota_failure_wecom_deliveries` 持久台账及唯一 `dedupe_key`。
- 发送前数据库 claim；台账不可用时返回 `receipt_store_unavailable` 且不发送。
- 成功重放精确回读；异常、歧义或收据失败标记 `outcome_unknown`，禁止盲重试。

验证：sent 与 ambiguous 两类重复调用的 transport 调用次数均为 1；本轮没有真实企业微信发送。

### COR-IDEMP-002 — P2 — 来源转运营执行意图 check-then-insert

原问题：feasibility/opening/transfer/strategy/quant 等入口并发重试可插入多个 pending intent。

修复：

- 新增 `SourceBackedExecutionIntentIdentityService`。
- 幂等身份绑定来源模块、来源记录、酒店、动作和规范化完整来源快照摘要。
- 相同快照重放同一 intent；来源快照变化允许创建新 intent；冲突不能借用旧记录。
- 删除 controller 层只看旧 intent 链接就返回 409 的提前短路；是否重放或创建新 intent 统一由完整 source snapshot 身份决定。
- 新增审批事务内来源行锁与当前快照重建；opening/transfer/feasibility 的酒店、平台、对象、动作、tenant 和 64 位摘要必须与创建时一致。仅 execution-link 元数据不造成虚假漂移，真实业务来源变化会拒绝审批。

验证：同快照精确重放、变更快照生成新 intent、opening 任务状态变化导致旧 intent 审批被拒绝；来源缺 scope、tenant 冲突与摘要漂移均失败关闭。

### STAB-003 / STAB-004 — P2 — 监控告警与 heartbeat 非原子

原问题：告警 read-check-insert 无业务唯一键；heartbeat 首次并发可唯一键异常，已有行 `run_count=N+1` 可丢增量。

修复：

- 增加 `monitor_dedupe_key` 与活动告警唯一索引，迁移对历史行规范回填。
- 唯一插入竞争按业务键精确回读。
- `run_count` 使用数据库原子 `inc`，并恢复首次插入冲突。

验证：schema 精确读回 `uq_operation_alerts_monitor_dedupe`；监控聚焦回归通过。

### STAB-005 — P2 — PHPUnit 多工作树共享缓存与锁

原问题：默认 `%TEMP%/suxios-phpunit-state/cache|locks` 为整机固定目录，不同工作树/进程会互相 clear 或竞争锁。

修复：

- 默认目录改为 `<worktree hash>/<run id>`。
- 子进程通过 `SUXIOS_PHPUNIT_RUN_ID` 显式继承同一次运行的状态命名空间。

验证：新增 bootstrap 隔离测试；PHP 全量通过。

### ARCH-004 — P2 — 目标日期缺 OTA 事实仍先调用 LLM

原问题：查询自动回退最近历史日，模型调用完成后才由末端 guard 清空动作。

修复：

- 新增 `OtaDiagnosisRequestedPeriodGateService`，在 LLM 调用前检查请求期事实资格。
- 历史数据仅作确定性只读参考；目标期缺失时模型禁用并保持 blocked。

验证：历史存在、目标期为空时 model call 次数为 0。

### ARCH-005 — P2 — pre-commit 未实际启用且 staged 路由不完整

原问题：只有可手工运行脚本，checkout 未配置 hooksPath；frontend fragments 与公开 route 改动未走对应验证器。

修复：

- 新增版本化 `.githooks/pre-commit` wrapper。
- 当前 checkout 配置 `core.hooksPath=.githooks`。
- hook 只按 staged ACMRD 文件路由；fragments、snapshot、manifest、index 与受管理生成物全部触发 frontend gate。
- 新增 staged-index 验证器：用 `git checkout-index` 把整个 index 物化到临时目录，再在该目录运行 template/entry/Tailwind verifier；不读取工作树对应文件，也不 stash/reset/checkout 当前工作树。

验证：fragment-only staged 不能借用工作树已同步 snapshot/generated 假通过；generated-only staged 会触发并失败；完整一致 staged 通过；第二轮盲审发现的 managed fragment rename-away 也按 `--no-renames --diff-filter=ACMRD` 路由并失败关闭，5/5。当前 index 无 staged 文件时 `git hook run pre-commit`、context asset verifier 与 pre-commit checks 通过。

### INT-CONTRACT-001 — P2 — E2E 静态验证器漏扫新组件边界

原问题：wrapper/loading state 已从入口提取到 `public/components/system/app-main-components.js`，但验证器仍只扫描 index/template/app-main，造成 7 项假失败。

修复：把页面实际加载的 `app-main-components.js` 加入 frontend semantic sources；没有恢复旧实现或放宽断言。

验证：静态集成契约 2,286 项通过。

## 5. 九轮盲审 FAIL 与定向返工

首轮两名 fresh-context 审查者均为零写入：不运行测试、不构建、不修复，只读取白名单源文件、测试、报告和冻结哈希。安全/正确性审查与稳定性/架构审查共同确认 6 个可触发缺口，判定 `VERDICT: FAIL / PROCESS_STATUS: READY`；目标文件在审查前后哈希一致。

| 级别 | 首轮盲审发现 | 定向返工 |
|---|---|---|
| P1 | strategy simulation 可把 A 的来源记录与 B 的 request hotel 绑定 | 从持久化 input/data snapshot 解析唯一酒店；缺失/冲突失败关闭；先解析源酒店与 tenant，再授权和构建 intent |
| P1 | opening/transfer/feasibility 只在创建时绑定来源，批准时未重新证明来源未变 | 审批事务内锁定来源行、重建当前输入并比对完整来源摘要、tenant、hotel、platform、object、action；真实来源漂移拒绝批准 |
| P1 | 30 日 baseline 可跨日期混合 whole-hotel 与 OTA-channel，仍返回 ok | 每日 scope signature 必须全窗口一致；漂移返回 `baseline_scope_drift`、partial、null averages，simulation false |
| P2 | controller 只看已有 execution link 就 409，阻止相同快照重放或来源变化后的新 intent | 移除提前短路；统一交给 snapshot-backed idempotency 决定 replay/new |
| P2 | 两条真实 execution-intent 路由遗漏 `operation.execute` 精确分类 | `api/agent/price-suggestions/*/execution-intent` 与 `api/online-data/public-page-diagnosis/execution-intent` 均归入 `operation_execution` |
| P2 | hook 虽按 staged 路由，却让 verifier 读取工作树；fragment-only/generated-only 可假通过或漏检 | 物化完整 index 到临时目录并在其中验证；三种 staged 契约 3/3 通过 |

返工后的聚焦回归为 160 tests / 1,110 assertions；全量第一次运行暴露一个名为 scoped、但持久化 fixture 未写 `hotel_id` 的兼容性回归。测试 fixture 补齐其名义上应有的持久化酒店身份，生产 fail-closed 规则没有放宽；相关 38 tests / 304 assertions 后，全量第二次通过。

第二轮使用新的两名 fresh-context 零写审查者，重新冻结代码清单并在前后核对 HEAD/目标哈希。两名审查者仍判定 `VERDICT: FAIL / PROCESS_STATUS: READY`，确认第一轮返工还有以下 5 个真实可触发缺口：

| 级别 | 第二轮盲审发现 | 定向返工 |
|---|---|---|
| P1 | intent tenant 只与 source row 比较，未与当前 hotel tenant 做三方相等校验；酒店与来源同时迁租户可让旧 intent 继续批准 | 审批事务内要求 intent、来源行、当前酒店三方 tenant 都有效且完全相等；迁租户旧 intent 拒绝 |
| P1 | opening 只锁 parent project，审批摘要中的 child tasks 可在比较与批准之间变化 | 审批同一事务中锁 parent 后以 `FOR UPDATE` 锁定全部 child tasks，再重建当前摘要 |
| P1 | baseline 的 conversion/traffic 日期与平台未完整进入签名，且合法 source-only OTA 行可跨携程/美团漏报漂移 | 将各平均指标绑定日期/scope/规范化平台；平台从 evidence platform 或 source 归一化；任一漂移使全部 averages 为 null |
| P2 | transfer/feasibility 的 tracking writeback 会改变 readiness，从而改变幂等键；重试可能建第二个 intent并重复 tracking | 幂等身份优先使用稳定业务快照 digest，并剥离 tracking 派生状态；控制器 replay 直接回读原对象，attach 同 intent 二次调用无副作用 |
| P2 | hook 使用默认 rename detection；把受管 fragment rename-away 可能只呈现为非受管新路径并跳过 verifier | PowerShell router 与 staged verifier 都固定 `--no-renames --diff-filter=ACMRD`，真实 `git mv` rename-away 回归失败关闭 |

第二轮返工后的聚焦 PHP 回归为 178 tests / 1,288 assertions，staged hook 为 5/5。全量 Node 随后诚实暴露 `OperationSnapshotConcern` 超过 no-growth ratchet；没有抬高预算，而是把单一职责的 baseline 计算/读行逻辑提取到 `OperationBaselineConcern`，原热点降至 2,126/2,300。提取后 baseline/operation 59 tests / 403 assertions通过，最终 PHP 与 Node 全量均通过。

第三轮再使用两名全新上下文、零写审查者，并复算 28 文件冻结清单。安全/正确性审查发现 1 个 P1，稳定性/架构审查发现 3 个 P1，均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`：

| 级别 | 第三轮盲审发现 | 定向返工 |
|---|---|---|
| P1 | strategy/quant simulation 独立审批遗漏 intent tenant；hotel 与 source 同迁租户后旧 intent 可获批 | 锁定来源后要求 intent/source/current hotel tenant 三方均有效且严格相等；strategy/quant 迁租旧 intent 都拒绝且零 task |
| P1 | 同渠道 source-only/platform-only/alias 流量行使用不同去重键，conversion 可静默重复聚合 | 统一规范化渠道身份，platform 优先否则 source；source/platform 冲突失败关闭；同渠道只保留最高 rank、最新、最高 ID |
| P1 | source-backed 幂等身份不含 tenant，迁租后新租户会重放旧租户 intent | key 纳入 tenant；replay、按 key/detail/list 读回校验当前 tenant；迁租后创建新的当前租户 intent，旧 intent 不可泄露 |
| P1 | quant execution 请求 hotel 可覆盖持久化来源 hotel，创建与批准均可能串同租户另一酒店 | quant 保存时持久化已授权唯一酒店；创建/批准都从来源重新解析，缺失/冲突失败关闭，请求只允许一致 |

第三轮返工拆为两个互不重叠单元。流量身份单元先复现错误 conversion `16.36%`，修复后 `7 tests / 46 assertions` 通过；tenant/simulation 单元把审批租户逻辑提取到 `OperationExecutionTenantConcern`，`186 tests / 1,384 assertions` 通过，`OperationManagementService` 降至 6,224 行。主代理集成回归 `184 tests / 1,178 assertions` 通过。第一次全量诚实暴露 reserved-source 负例在 tenant 查询前置后错误碰数据库；仅调整失败关闭顺序、不放宽 tenant 规则，两个失败用例及幂等回归 `33 tests / 172 assertions` 通过，第二次全量从头通过。

第四轮重新冻结 32 文件，由两名全新上下文审查者在零写状态复算前后哈希。安全/正确性审查发现 3 个 P1，稳定性/架构审查发现 2 个 P1，两者均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`：

| 级别 | 第四轮盲审发现 | 定向返工 |
|---|---|---|
| P1 | online flow 归一化器不认识 ETL 已支持的生产别名，可能静默排除较新高 rank 行而用旧行返回 ok | 将 ETL 渠道归一化开放为唯一纯函数，Snapshot 直接复用；覆盖 browser/business/rank/manual 别名族，真实 source/platform 冲突仍拒绝 |
| P1 | simulation detail bridge 的 intent/tracking 投影进入来源摘要，详情重载后的正常重试无法幂等 replay | 来源摘要只使用持久化业务字段，递归剥离 bridge/tracking 与其派生 readiness；首次创建→详情重载→重试返回同一 intent，真实业务变化仍拒绝 |
| P1 | `POST /api/operation/execution-tasks/*` 落入 view 规则，查看权限可执行、写证据、评审或写运营记忆 | method-scoped POST 全部前置归类为 `operation_execution / operation.execute`；GET detail 保持 `operation.view` |
| P1 | hotel/source 迁租户后，B 可按旧 task ID 读取和修改 A 的 task | 集中 task→parent intent 授权；source-backed task/intent tenant 必须等于酒店当前 tenant；写事务内重新锁定并校验当前来源与快照 |
| P1 | Opening/Transfer/Feasibility 来源响应仍可向迁入租户投影旧租户 intent tracking | 新增只读 tenant-safe bridge projection；来源、酒店、intent tenant 与 module/source/hotel 全一致才投影，历史 tracking 保留数据库但从跨租户响应剥离 |

第四轮返工分为三个不重叠单元：别名/simulation 幂等 `105 tests / 1,071 assertions`，task 权限/迁租隔离 `179 / 1,359`，三类来源 bridge `22 / 162`，均通过。主代理交叉集成 `257 / 2,238` 通过。第五次从头全量 PHP、Node、P0、热点、hook 与 Composer 校验随后全部通过。

第五轮冻结 39 文件后，两名 fresh-context 零写审查者再次独立反证。安全审查确认 2 个 P1，稳定性审查确认 2 个 P1 与 1 个 P2，均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`：

| 级别 | 第五轮盲审发现 | 定向返工 |
|---|---|---|
| P1 | source-backed task 写事务锁 task/intent/source，却未锁 hotel ownership row，迁租可在校验后写入前提交 | 所有 mutation 进入单一外层事务，稳定锁序 `hotel → task → intent → source`，锁齐后重验四方 tenant；execute/evidence/reconcile/review/memory/assessment 均在同一回调内写 |
| P1 | quant detail 为 scenario 添加 `metric_truth`，该展示投影进入来源摘要，真实创建会与 DB 原始场景自我漂移 | quant 创建与审批复用显式持久化业务 DTO；只移除已知 scenario metric_truth 投影，其他持久化字段仍参与摘要；真实场景变化仍拒绝 |
| P1 | 同 rank 流量按 DB update/create 时间选优，旧业务快照晚回填可冒充最新 | 优先严格一致的 `snapshot_time/collected_at/captured_at` 业务采集时间；非法/冲突时间整行失败关闭；完全缺失才回退 DB 时间 |
| P1 | bridge sanitizer 未覆盖 tracking_record_id/tracking_records/investment_tracking_id 等旁路，迁租后仍可泄露并抬升 readiness | 唯一递归声明式 sanitizer 覆盖全部已识别 tracking 形态；只有 tenant/source/hotel/module/record 可证明者才投影，readiness 只消费净化后集合 |
| P2 | Transfer/Feasibility 列表逐行查询 hotel 与 intent，80 行产生约 160 次 bridge 查询 | 增加共享批量 projection context；1 行和 80 行均固定 hotels 1 次、intents 1 次查询，异常 fail closed、零写 |

第五轮返工仍拆为三个不重叠单元：quant/流量 `105 tests / 1,234 assertions`，酒店行锁/事务 `85 / 813`，bridge 全字段与批量投影 `86 / 572`，均通过。主代理交叉集成 `204 / 1,432` 通过；第六次 PHP、Node、P0、热点、hook、Composer 与 diff 从头复验全部通过。

第六轮继续冻结同一 39 文件契约并由全新上下文审查者零写反证，再确认 3 个 P1 与 1 个 P2，仍判定 `VERDICT: FAIL / PROCESS_STATUS: READY`：

| 级别 | 第六轮盲审发现 | 第七轮定向返工 |
|---|---|---|
| P1 | source-backed **审批**仍先锁 intent/source、未锁 hotel ownership row；与已修复的 task mutation 形成两套锁序，迁租与审批可发生 TOCTOU 或死锁 | 审批改为单一外层事务，稳定锁序 `hotel → existing tasks(id asc) → intent → source`；锁齐后重验 intent identity、task set、三方 tenant 与当前来源，再更新 intent、插入 task 与 tracking；三类来源均加入双连接两种时序回归 |
| P1 | bridge sanitizer 按原始大小写匹配，`Execution_Tracking`、`Tracking_Record_Id` 等混合大小写键可绕过净化、跨租户泄露并抬升 readiness | 所有分类键先 canonical lowercase；root/nested/list/assoc 与非 list 结构统一递归清洗；合法字段输出 canonical key，普通业务字段保留，大小写 scope 冲突失败关闭 |
| P1 | 流量选优在业务时间缺失时忽略可信的 `received_at/raw.fetched_at/raw.fetch_time/meta|capture.fetched_at`，旧采集行可仅凭较晚 DB 更新时间胜出 | 在 DB 时间之前严格解析并比较受信采集时间；时区等价归一，非法或真实冲突整行失败关闭；只有业务时间与全部采集时间均缺失才回退 update/create |
| P2 | staged frontend router 未把 `public/app-main.js` / `app-main.min.js` 纳入受管三联，source-only 或 artifact-only 提交可跳过前端生成一致性门禁 | PowerShell router 与 index 物化 verifier 同时纳入 source/artifact/version 三联；真实 Git-index 夹具覆盖 source-only、artifact-only 失败与三联一致通过，连同 fragment/generated/rename-away 共 8/8 |

第七轮返工后的聚焦交叉集成为 PHP `215 tests / 1,507 assertions`、staged Git-index `8/8`；随后从头完成 PHP、Node、P0、schema、构建、热点、hook、Composer 与 diff 复验。一次 PHP 全量只暴露旧静态测试仍搜索重构前方法位置；测试契约改为同时断言主服务集中授权入口与 concern 内 source-currentness 校验，生产 fail-closed 规则未放宽，第二次全量从头通过。

第七轮冻结 40 个目标文件。稳定性审查者第一次使用未指定读取编码的 PowerShell `Get-Content -Raw`，把 UTF-8 中文误解码并产生假 `BENCHMARK_INVALID`；显式 UTF-8 复算后，HEAD、aggregate 与报告哈希全部精确匹配，原流程判断撤销，两名审查者在同一冻结快照上从头完成正文零写盲审。最终两者均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`，共确认 3 个 P1 与 2 个 P2：

| 级别 | 第七轮盲审发现 | 第八轮定向返工 |
|---|---|---|
| P1 | 只有五类 source-backed intent/task 校验当前酒店 tenant；`ota_diagnosis_saved`、operating target、manual 等旧生命周期在酒店迁租后仍可被新租户读取、批准和写入 | 所有 intent/task 的 stored tenant 都必须与当前 `hotels.tenant_id` 严格一致，tenant=0 fail closed；读/list/flow/detail/approval 与全部写生命周期统一保护；写锁序 `hotel → task → intent → source`，审批锁序 `hotel → existing tasks(id asc) → intent → source`；迁租旧记录零副作用，新租户可创建新生命周期 |
| P2 | assessment 在公共授权锁外读取 evidence 并计算判断；并发追加证据先提交后，旧判断仍可写入 | task/intervention/goal/evidence 读取、judgment、digest 与写入全部移入同一 task 授权事务；受控时序证明 assessment 获锁前提交的 E2 必须进入判断 |
| P2 | 祖先层 tenant/hotel/module/source-record 冲突不传播到后代 tracking；合法嵌套 ID 仍可抬升 readiness | sanitizer 递归传播 `ancestorScopeConflict`；任一祖先冲突都会清空后代有效 intent 集合，但不含 tracking 的普通业务子树原样保留，读路径不写回 |
| P1 | `tracking_records` 等合法展示 bridge 字段未从 source snapshot digest 剥离，同一业务详情重试可生成第二个 intent | 建立大小写无关的完整 bridge 容器/标识符集合；snapshot digest 与回退 idempotency key 统一只剥离结构上真实的 bridge 投影，同名普通业务对象仍参与摘要；detail→retry 同 key，真实业务变化仍改 digest |
| P1 | `onlineRowTimestamp()` 声明的 DB 时间回退被更早的可信 envelope 截断，真实选优入口不可达，可能静默保留旧流量行 | 仅对原 validation/readback/ingestion 全通过、历史 final 日、白名单 flow endpoint、有数值证据且业务/collection 时间全部 missing 的行允许严格 DB 时间回退；任何显式非法/冲突时间不能被救活，其他 OTA 事实入口不放宽 |

第八轮三个返工单元先得到可复现红证据：迁租旧 intent 泄漏、assessment 只读到 1/2 证据、四类 ancestor/digest 反例 `4/4` 失败、真实 flow DB 回退 sample=0，随后分别转绿。主代理交叉集成 `261 tests / 3,147 assertions` 与 staged-index `8/8` 通过。第一次 PHP 全量暴露一份 operating-target SQLite fixture 未同步正式 tenant 列；只补齐 fixture schema/tenant 身份，不放宽生产规则。第一次 Node 全量又诚实暴露 execution-flow 把 current-tenant 过滤放在 limit 后，使 `matched_total` 退化为返回行数、`truncated` 恒 false；最终把当前酒店 tenant 约束前推到 SQL query，scope 后 count、count 后 limit，聚焦业务链 `7/7`。PHP 与 Node 随后再次从头全量通过。

第八轮冻结 43 个目标文件。两名全新上下文审查者分别按安全/正确性与稳定性/架构反证，并在结束时复算 HEAD、目标 aggregate 与报告哈希；两者保持零写、零测试、零构建，且均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并去重后确认 3 个 P1 与 3 个 P2：

| 级别 | 第八轮盲审发现 | 第九轮定向返工 |
|---|---|---|
| P1 | `reschedulePendingExecutionIntent` 在统一 tenant/currentness 授权事务之外先更新 intent；酒店迁租或来源过期时，最终详情虽失败，更新已可能提交 | reschedule 复用统一 intent mutation 外层事务，稳定锁序 `hotel → tasks(id asc) → intent → source`，锁齐后重验 tenant/source/snapshot；更新与最终详情读回同事务，任何 scope 丢失整单回滚 |
| P1 | 运营记忆 existing-idempotent hit 与 duplicate-winner 收敛可绕过 task mutation 授权；迁租后仍可能返回旧 memory | existing 命中、duplicate winner 与新写都经统一 task 授权；从已锁 task/intent 重建 record，严格比对 tenant/hotel/key/digest；read/list/timeline JOIN 当前 hotel+tenant，tenant=0 与旧租户均失败关闭 |
| P1 | source digest 把仅含 `status/type/hotel_id/tenant_id` 的普通 `tracking_records` 业务对象误判为 bridge，真实业务变化不改摘要 | 只有显式 bridge 专用 ID/ref 或布尔 `post_decision_tracking` 才分类为 bridge；普通同名对象完整进入 digest/fallback key，`business_note` 等业务变化必然改变摘要 |
| P2 | projection 对普通同名 `tracking_records` 对象执行 bridge sanitizer，可能把合法业务对象静默改成空数组 | 只有显式 bridge 引用的容器才净化并参与 readiness；普通同名对象结构和值原样保留；真实混合大小写 bridge、ancestor scope 冲突仍严格失败关闭 |
| P2 | execution intent list 在当前 tenant 过滤之前 `ORDER/LIMIT 100`，大量异租户新行可挤掉当前租户合法旧行 | 将 hotel/current-tenant SQL scope 前推至排序与 limit 之前，之后仍二次过滤；缺 hotels/tenant 列失败关闭；超过 100 条异租户夹具不再影响合法结果 |
| P2 | execution-flow 原测试只做字符串断言，未验证 SQL scope、matched_total、returned 与 truncated 的真实行为 | 新增 SQLite 行为回归：3 条当前租户 + 105 条旧租户，limit 2/100 分别得到 matched 3/3、returned 2/3 与正确 truncated；旧租户 ID 为零进入；缺表/列返回空结果 |

第九轮三个返工单元均先复现红证据再转绿：reschedule/list/flow 与 tenant 锁协议 `147 tests / 2,341 assertions`，bridge 分类与投影 `100 / 689`，运营记忆竞态 `16 / 98`；主代理交叉集成 `220 / 2,856` 与相关 Node `15/15` 通过。随后从头执行 PHP 全量 `4,270 tests / 39,520 assertions / 1 skipped`、Node 全量 `233 files / 1,542 checks`、全部 P0 guards、schema、前端三联构建、hook、diff 与热点 ratchet，均通过。

第九轮扩大冻结至 49 个目标文件。两名全新上下文审查者完成安全/正确性与稳定性/架构零写反证，起止 HEAD、aggregate、report hash 完全一致，`writes/tests/builds=0/0/0`；两者再次判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并去重后确认 5 个 P1 与 4 个 P2：

| 级别 | 第九轮盲审发现 | 第十轮定向返工 |
|---|---|---|
| P1 | `source_module` 未统一大小写，generic 入口可用 `Opening`、`TRANSFER_DECISION` 等绕过 reserved-source 创建保护；历史混合大小写 intent 的审批/task mutation 也会跳过来源锁与 currentness | Identity 建立唯一 `trim+lowercase` 纯归一化；build/persist/readback、reserved 分流、审批 probe 与 task mutation 全部复用；五类保留模块的大小写/空白变体拒绝 generic 创建，历史 intent 仍按 source-backed 锁定与重验 |
| P1 | digest/projection 在任意深度仅凭 `execution_status`、`opening_project_id` 等字段名判断 bridge，普通业务变化可能不改摘要且详情丢字段 | 只在明确 tracking 容器或真实 intent 引用上下文中识别 bridge；普通 `business_context.opening_project_id`、`tracking_records.execution_status` 保留并参与摘要，合法 tracking 容器 readiness 恢复，祖先 scope 与混合大小写真实 bridge 继续失败关闭 |
| P1 | 手工 growth/annotation/milestone 在 scope 预读后迁租，写事务不锁 hotel；接口最终读回失败但旧租户 memory 与 supersede 已提交 | 单事务固定 `hotel ownership → source parent → duplicate → previous version → insert/supersede → scoped readback`；所有 tenant/hotel/digest/readback 在提交前重验，独立 PDO 迁租反例失败且行字节/计数不变 |
| P1 | goal contract 与 intervention 在 `resolveScope()` 后迁租仍可写入旧租户并返回成功 | goal 使用 `hotel → versions`；intervention 使用 `hotel → intent → frozen goal → tasks → versions` 稳定锁序，事务内重验 tenant/hotel/source 与精确 readback，迁租零副作用 |
| P1 | hotels/tenant schema 缺失时 execution list/flow 返回 `data_status=ok`、loaded=true、零 gaps，把迁移故障伪装为无数据 | schema 不可用改为 `migration_required`、明确 data gap、loaded=false；列表与 flow 行为测试不再把空数组当成功 |
| P2 | OTA diagnosis latest-attempt 在 tenant scope 前排序/limit，100 条旧租户新行可挤掉当前 attempt | current hotel tenant SQL scope 前推至 attempt 排序与 limit 前，数值 attempt 语义保持；105 条旧租户夹具不影响当前结果 |
| P2 | execution intent/memory list 与 growth timeline 静默截断；timeline 先 limit 500 后按 event kind 过滤 | list/timeline 返回 `matched_total/returned_count/truncated`；event kind 先进入 SQLite/MySQL SQL scope，再 count、limit 100；610 条同租户样本验证 610/100 与 judgement 105/100 截断元数据 |
| P2 | 流量时间解析接受小数秒却返回整秒，同秒 `.900` 可能因 ID 更小输给 `.100` | 保持秒级兼容 API，另保留微秒 sort timestamp 用于同 rank 选优；时区等价、非法/冲突、整秒兼容继续通过 |
| P2 | pre-commit 只有 template/app-main 三联读取 Git index，public-entry/style/context/Ctrip verifier 仍可借用工作树 | 所有条件 verifier 在完整 `git checkout-index` 临时快照中运行；嵌套仓库外层 AGENTS 以只读边界复制；style-only、entry-only、context-only 反例与原三联/rename 场景共 `11/11`，另验证子 verifier 非零退出码原样传播，共 `13/13` |

第十轮三个代码单元均以受控红测开局并转绿：identity/bridge/微秒选优交叉 `90 tests / 613 assertions`，memory/goal/intervention `43 / 309`，execution truth/source canonicalization `195 / 1,333`；主代理交叉集成 `210 / 1,547`。第一次 PHP 全量诚实暴露合法 feasibility tracking readiness 被结构识别收得过窄；只在明确 tracking 容器内恢复正数 domain tracking 引用，普通同名业务字段继续参与摘要且不抬升 readiness，`90 / 613` 再次通过。第二次 PHP 全量为 `4,282 tests / 39,603 assertions / 1 skipped`；Node 第一次暴露两份旧 hook 契约和子退出码折叠，修复后两个直接用例及 staged-index `13/13` 通过，第二次 Node 全量为 `233 files / 1,545 checks`。P0、schema、前端三联、hook、diff、热点、health 与运行时预算随后全部重跑通过。

第十轮扩大冻结至 50 个目标文件。两名全新上下文审查者按安全/正确性与稳定性/架构完成零写反证，起止 HEAD、aggregate 与报告哈希一致，且均未执行测试、构建或服务；两者再次判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并同一根因并按最高级别计数后确认 5 个 P1 与 5 个 P2：

| 级别 | 第十轮盲审发现 | 第十一次定向返工 |
|---|---|---|
| P1 | hotels/tenant schema 缺失时，generic approval 与非 source-backed task mutation 仍可走可选 hotel 回退并提交写入 | approval 与全部 task mutation 都强制 fresh hotels/tenant schema；锁内无条件读取并锁定 hotel，schema 缺失返回 `migration_required`、tenant=0 失败关闭，任何 intent/task/evidence 均零副作用 |
| P1 | 空 intent flow 在检查 task/evidence schema 前提前返回 `ok`，子表缺失被伪装为真实零记录 | flow 即使 intent=0 也先验证 tasks/evidence 表与 tenant 列；缺失时明确 gaps、所有 loaded=false，不再把迁移故障当空业务 |
| P1 | 任意残留 domain tracking ID 或 simulation bridge ID 可在未验证真实 intent/hotel/tenant/status 时抬升 readiness | domain tracking ID 保留并参与业务 digest，但不再单独证明执行跟踪；readiness 只接受 bridge 服务生成的 `_source_bridge_verified` 投影，simulation 从持久化源解析唯一酒店并核验当前 tenant、source、status、deleted，任何残留/跨酒店/旧租户引用全部清理并降级 |
| P1 | 30 日 baseline 只有 1 日事实仍可按 actual days 求均值并返回可模拟，覆盖率不足未进入硬门槛 | 覆盖与三项必需指标统一使用请求 days 作分母；1/29 日为 `partial + insufficient_baseline_days`，均值仅作观察，策略模拟 fail closed；30 日完整与 scope drift 分别保留成功/阻断语义 |
| P1 | 自动 intervention 在事务外只冻结“最新 goal”，并发新增 G2 后锁内会把原本基于 G1 的判断绑定到 G2 | 事务前冻结确切 goal ID+version，事务内锁同一 ID 并复核版本；独立 PDO 在窗口插入 G2 后，本次判断仍只绑定 G1 |
| P2 | latest OTA attempt 在当前 tenant 内仍先 limit 100 再取数值最大值，较老高 attempt 可被后续低值行挤掉 | 去掉取 max 前的 limit，在完整当前 tenant scope 内按数值语义选最大；attempt 1000 与后续 105 个低值、旧租户 2000 的反例通过 |
| P2 | 无 offset 的业务时间依赖 PHP 进程默认时区，部署时区变化可改变事实日与同 rank 排序 | 无 offset 固定解释为 `Asia/Shanghai`；显式 `+08:00`/`Z` 原样解释，保留微秒排序，跨进程默认时区得到同一结果 |
| P2 | projection 查询历史 intent 时对 `source_module` 精确匹配，混合大小写/空白的合法历史桥接可能被漏清理或漏验证 | 查询与 replay 比较统一 `LOWER(TRIM(source_module))`/canonical module；只接受当前 tenant/hotel/source 且处于允许状态的 intent，拒绝态、删除或不存在均清理投影 |
| P2 | legacy mixed-case source module 在正常 replay 与唯一索引冲突收敛路径比较不一致，等价重放可能 409 或建立分叉 | normal pre-read 与 unique-collision convergence 共用 canonical identity；等价 legacy replay 返回原记录，不同业务事实仍严格 409 |
| P2 | source-backed bridge sanitizer 对同名业务容器与真实 tracking 容器边界不足，可能丢业务字段或错误抬升状态 | 业务同名字段保持原值并进入 digest；只有 bridge 服务验证后的结构投影可提升状态，大小写、祖先 scope、跨租户/跨酒店反例继续失败关闭 |

第十一次三个返工单元全部先得到真实红证据再转绿：execution/schema 核心 `6 tests / 57 assertions`，tracking/bridge 相关集成 `98 / 747`，baseline/goal/time `108 / 778`；主代理交叉集成 `234 / 1,640`。第一次 PHP 全量在新 schema 硬门槛下暴露四份旧 SQLite fixture 缺正式 hotels/evidence tenant 结构；仅补足生产同构 fixture，不削弱任何断言或 fail-closed 行为，四文件 `28 / 239`、扩大交叉 `161 / 1,260` 通过。随后 PHP 从头全量 `4,298 tests / 39,730 assertions / 1 skipped`、Node 从头全量 `233 files / 1,545 checks` 均退出 0；P0 guards、hardening schema 精确读回、前端 template/entry/Tailwind、pre-commit、diff 与 hotspot no-growth 再次通过。

第十一次冻结 50 个目标文件。两名全新上下文审查者按安全/正确性与稳定性/架构完成零写反证，起止 HEAD、aggregate 与报告哈希一致，`writes/tests/builds=0/0/0`；两者仍判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并后确认 3 个 P1 与 2 个 P2：

| 级别 | 第十一次盲审发现 | 第十二次定向返工 |
|---|---|---|
| P1 | 连续 30 条 `daily_reports` 即使全部 `unverified`、`failed` 或无验证状态，也会被计作完整基线、生成规则情景并允许进入待审批意图 | 任何聚合前统一校验项目既有正式可信状态；不可信行不进入指标，输出拒绝数量/日期/原因与 `operation_daily_report_validation_untrusted`、`baseline_daily_report_validation_untrusted`；全不可信为 partial/null，29 verified + 1 unverified 不能模拟，30 verified 正例保持 |
| P1 | `expansion` 未纳入 source-backed identity/currentness；源归档、业务事实变化或超级管理员跨租户绑定后，旧意图仍可批准并生成任务 | expansion 进入统一 source-backed table/identity/snapshot/approval 协议；digest 覆盖 input/result/decision/risk/readiness，创建前校验 source↔hotel tenant；审批按 `hotel → tasks → intent → source` 锁内重算，归档/事实漂移/跨租户均零写，合法同租户与 legacy 同事实 replay 保持兼容 |
| P1 | execution intent/task detail 在 hotels 权威表缺失或查询异常时跳过当前 tenant 校验并返回旧 intent/task/evidence | intent 与 task detail 统一强制 schema gate；hotels 缺失、不可查询或 tenant schema 不完整直接 `migration_required`，不返回任何资源；list/flow/write 的既有 fail-closed 行为不变 |
| P2 | 无显式 end date 的 30 日 baseline 用进程默认时区计算业务日，UTC 部署可错取相邻一天仍报告 30/30 | 默认窗口固定按 `Asia/Shanghai` 业务日期计算；显式 `end_date` 严格 `YYYY-MM-DD` 且保持 exclusive end；切换进程默认时区的 30 日行为回归通过 |
| P2 | order-context 脱敏对未知字段默认保留，`clientName/personName/linkMan/email/wechatId/QQ/IM` 等可明文进入标准 `raw_data` | 统一规范化中英、驼峰、下划线和连字符键；姓名/电话继续掩码，订单号继续 hash，邮箱/社交账号/地址/证件/备注/生日/个人金融账号及 PII 数组 fail closed；金额、房型、日期、状态、数量等业务字段保持 |

第十二次三个返工单元均先得到真实红证据：baseline trust 4 个失败、上海窗口 1 个失败，expansion/detail 4 个合同中 3 个失败，订单 PII 1 个失败。修后直接回归分别为 `53 tests / 404 assertions`、`147 / 1,191`、OtaStandard 全模块 `50 / 685` 加消费者 `25 / 222`；主代理跨单元 `252 / 2,261`。第一次 PHP 全量暴露三个旧 dashboard 正例 fixture 缺验证状态；仅补 `validation_status=verified`，原断言不变，并新增 dashboard `unverified` 行为反例。最终 PHP 从头全量 `4,310 tests / 39,871 assertions / 1 skipped`，Node 从头全量 `233 files / 1,545 checks`；P0、schema、template/entry/Tailwind、pre-commit、diff、hotspot no-growth 与本地 health 均再次通过。

第十二轮冻结扩大到 53 个目标文件。两名全新上下文审查者完成安全/正确性与稳定性/架构零写反证，起止 HEAD、aggregate 与报告哈希精确一致，且无测试、构建、服务、迁移或 index 操作；两者仍判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并相同 PII 根因后确认 6 个 P1：

| 级别 | 第十二轮盲审发现 | 第十三次定向返工 |
|---|---|---|
| P1 | Expansion 控制器“已有 linked intent”直接返回幂等成功；source 事实漂移时伪装成当前意图，特殊 legacy key 又不含 tenant/hotel/digest | 删除控制器私有短路，每次委托统一 source-backed create/replay；身份改为 tenant+hotel+source snapshot digest，同事实同 ID，事实/决策/风险/readiness 漂移建立新受治理 lifecycle；旧 intent 审批 currentness 拒绝，迁租后旧租户不阻断新租户；legacy 仅同 tenant/hotel/digest 回放，unique collision 重验存储摘要 |
| P1 | 日报可信门读取生产 schema/writer 从不持久化的 `validation_status`，正常 `status=2` 提交会全部被拒 | 读回真实 MySQL 15 列契约：`status=1` 草稿、`2` 已提交且无 validation_status；数字 workflow status 优先，2 接纳，1/0/其他拒绝并明确原因；仅无 workflow status 的 legacy exact `verified` 兼容，测试 schema 不再伪造生产列 |
| P1 | 可信枚举把 `success/complete/approved/passed/ok` 等泛工作流话术等同事实核验，可用 30 条宽泛状态解锁模拟/意图 | 删除宽泛字符串白名单；有数字 workflow status 时任何字符串均不能升级草稿，legacy 也只接受精确 `verified`，其它状态输出 untrusted gap |
| P1 | 默认 30 日上海窗口包含今天，而正式日报 writer 禁止今天，真实提交路径最多 29/30 | 默认窗口改为上海“昨天往前连续 30 天”；今天行不进入默认 baseline，显式 end_date 继续保持严格、exclusive 语义，切换进程默认时区不漂移 |
| P1 | 订单 PII 对 `contactInfo:{value}`、`guests:[scalar]`、`guest:{text}`、first/last name 等通用包装和无名数组仍明文透传；booking/reservation 别名未进入 order context | order/booking/reservation 及 list/data 别名统一进入订单语境；识别 contact/guest 包装、标量 guests、first/last/given/family/surname 与 reservation ID；PII 原值零输出，业务金额/房型/入住日/状态/数量保留，非订单 competitor profile 不误删 |
| P1 | bridge 只做 lowercase，不识别 camelCase/kebab 键；迁租或祖先 scope 冲突后可保留 `executionTracking/executionIntentId/hotelId/tenantId` 等旧元数据 | payload key 统一 camel/kebab/snake/case 规范；各变体参与同一 sanitizer/digest；祖先冲突、迁租、无有效 intent 清理全部残留；普通非 bridge subtree 保留；输入 marker 丢弃，`hasProjectedTracking` 仅接受服务内部验证 token，原始 ID/伪造 bool 均不能提升 readiness |

第十三次三个返工单元均先红后绿：日报真实 schema 4/4 失败后相关 `110 tests / 760 assertions`；Expansion 生命周期 `3 errors + 1 failure` 及 unique collision 1 failure 后相关 `297 / 2,139`；PII/bridge 2 个失败并修正 1 个假绿断言盲点后相关 `167 / 1,564`。主代理跨单元 `340 / 2,677`，随后 PHP 从头全量 `4,328 tests / 39,987 assertions / 1 skipped`、Node 从头全量 `233 files / 1,545 checks`；P0、schema、template/entry/Tailwind、pre-commit、diff、hotspot no-growth 与本地 health 再次通过。

第十三轮冻结 54 个目标文件。两名全新上下文审查者完成安全/正确性与稳定性/架构零写反证，起止 HEAD、aggregate、report hash 一致，`writes/tests/builds=0/0/0`；两者仍判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并相同 PII 与业务保真根因后确认 2 个 P1、2 个 P2：

| 级别 | 第十三轮盲审发现 | 第十四次定向返工 |
|---|---|---|
| P1 | `daily_reports` 有独立 tenant_id，正式 writer 以当时酒店 tenant 持久化；baseline/dashboard 却只按 hotel/date 查询，迁租后旧租户 status=2 日报进入新租户经营事实与规则模拟 | 日报查询在排序/聚合前 JOIN hotels 并强制 `daily_reports.tenant_id = hotels.tenant_id`；buildSummaryFromRows 自身也验证当前 tenant，不信任调用方预过滤；迁租旧行静默排除，新租户同日/30日正常，缺 tenant schema、无效酒店 tenant 或 SQL 异常返回 `migration_required` 明确 gap |
| P1 | 订单 PII 仍可从 `primaryGuestName/contactPhoneNumber` 等复合键及 `passengers/travellers/occupants` 标量列表泄漏 | 使用 canonical key segments 与身份祖先路径识别复合姓名/电话/旅客列表/包装值；订单/预订号 hash、姓名电话 mask、其它身份明文删除，order/booking/reservation 一致，原值零输出 |
| P2 | `orders` 无边界子串把 `orderStatusSummary/preordersTrend` 误判订单容器；order context 又把 `roomType.name/ratePlan.name` 误当住客姓名 | order/booking/reservation 只按精确容器边界；递归显式区分 identity-bearing ancestor 与 roomType/ratePlan/channel/product 业务描述路径；经营汇总、房型、价型名称保真，非订单 competitor profile 不误删 |
| P2 | OTA realtime/historical/is_final 的“今天”仍用进程默认时区，与 baseline 上海业务日不一致 | Snapshot 两处裸 today 与日期解析统一复用 `Asia/Shanghai` helper；UTC/其它默认时区且上海跨日时，同一 OTA 行仍得到一致 realtime/historical/final 判断 |

第十四次两个返工单元均先得到真实红证据：日报 tenant/schema/query/today 新增 4 个失败，订单路径用例确认复合身份与旅客列表泄漏、roomType.name 误脱敏。修后日报/tenant 交叉 `143 tests / 1,061 assertions`，Ota 全量及直接消费者 `997 / 9,117`；主代理合并 `174 / 1,557`。初版 PII segment 规则还被模块回归抓到误伤 `book_order_num` 的 4 个失败，收紧相邻 subject+受限 qualifier 后全绿。最终 PHP 从头全量 `4,333 tests / 40,083 assertions / 1 skipped`、Node `233 files / 1,545 checks`；P0、schema、template/entry/Tailwind、pre-commit、diff、hotspot no-growth 与 health 再次通过。

第十四轮冻结扩大到 55 个目标文件。安全/正确性审查者完成了起止 HEAD、aggregate 与报告哈希一致的 fresh-context 零写复审并判定 `VERDICT: FAIL / PROCESS_STATUS: READY`；稳定性/架构审查者完成内容审查但终态哈希辅助脚本失配，诚实标记 `BLIND_REVIEW_INCOMPLETE`。其两条代码发现与已完成完整身份校验的安全审查相互印证，因此只计真实缺口、不把该不完整流程计作有效独立判决。合并后确认 1 个 P1、1 个 P2；随后确定性全量另暴露并确认 1 个 P2：

| 级别 | 第十四轮盲审/全量回归发现 | 第十五次定向返工 |
|---|---|---|
| P1 | 已识别 guest/customer/contact 等身份对象后，`description/details/summary/custom/freeText/raw` 等未知后代仍默认保留；业务描述对象又必须保真，形成可隐藏 PII 的原文通道 | 身份路径改为默认 fail closed，只允许已 hash/mask 的安全投影；经营描述、房型、价型、渠道、商品等业务对象保持结构，但对所有标量/列表/复合对象递归应用高置信手机号、邮箱、有效/显式证件号、微信和 QQ 值级阻断；不对任意中文姓名或普通业务数字作无证据猜测 |
| P2 | OperationManagement 控制器的 dashboard/rootCause/manual intent/strategy intent 默认日期仍使用进程 `date('Y-m-d')`，与服务层上海业务日不同，UTC 部署跨日可错读/错建相邻日期 | 控制器增加唯一 `currentBusinessDate()`，固定 `Asia/Shanghai`；四条默认入口统一复用，显式合法日期原样保留，非法、空值或 null 继续 422，UTC 存储时间戳不变 |
| P2 | 全量测试把两个独立响应连同动态 `generated_at` 直接 `assertSame`；恰逢跨秒时业务完全一致也会随机失败 | 分别严格验证两侧时间存在、`Y-m-d H:i:s` 格式、上海时区可无警告解析并落在各自调用时间窗；仅移除该动态字段后对完整剩余 payload 继续精确相等。人为插入 1.1 秒可稳定复现红测，修后连续 3 次跨秒及全文件均通过 |

第十五次返工均有红证据并按最小范围修复：PII 目标模块 `53 tests / 825 assertions`、11 个直接消费者 `166 / 1,916`；控制器/路由/经营链 PHP `96 / 2,010`、前端相关 Node `19/19`；跨秒稳定性用例人为延迟连续 `3/3`，`OtaDomainSplitTest` 全文件 `7 / 727`。第一次 PHP 全量诚实暴露动态时间脆弱断言，修复后从头全量 `4,335 tests / 40,193 assertions / 1 skipped`、退出码 0；Node 从头全量 `233 files / 1,545 checks`、0 skipped、退出码 0。P0、schema、template/entry/Tailwind、pre-commit、diff、hotspot no-growth 与本地 health 再次通过；在第十五轮双盲通过前，报告仍保持 pending。

第十五轮冻结扩大到 57 个目标文件。两名 fresh-context 审查者分别按安全/正确性与稳定性/架构完成零写反证，起止 HEAD、COUNT、aggregate 与报告哈希精确一致，`writes/tests/builds/services/migrations/network/index=0`；两者均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并未知订单 PII 与 source-backed 创建根因后确认 4 个 P1、3 个 P2：

| 级别 | 第十五轮盲审发现 | 第十六次定向返工 |
|---|---|---|
| P1 | `online_daily_data` 只按 hotel/date 读取，未与 hotels 当前 tenant 对齐；迁租后旧租户 OTA 行可进入 dashboard、baseline、模拟和意图依据 | 与日报同级增加 tenant schema gate 和 hotels join，强制 online.tenant_id=hotels.tenant_id；旧租户行排除、当前租户行接受，缺列/酒店租户/query error 为 migration_required，不回退旧事实 |
| P1 | `operation_action_tracks` list/finish/effect statistics 只按 hotel，迁租后新租户可读取、结束并覆盖旧租户动作，旧建议/预警也污染效果统计 | list/effect 统一当前租户过滤；finish 在同一事务按 hotel→action 锁并二次验证 tenant，旧动作不可见不可变；缺 tenant schema 失败关闭 |
| P1 | Quant 正式保存遇到权威 hotels 表缺失时，把用户 primary hotel 当唯一授权证据继续写 | 删除生产异常文本兼容；任何保存都要求 hotels.id+tenant_id 精确匹配，缺表直接拒绝且 simulation records 零写，测试 fixture 补正式酒店行 |
| P1 | 订单根/未知 `travellerProfile` 包装及 custom/status 列表/对象中的高置信 PII 未走值级扫描；有限 descriptor 白名单又会误把 mealPlan.name 等经营名称当住客姓名 | 所有 order 剩余标量递归执行高置信值门禁；含身份主体的未知包装默认 identity fail-closed；裸 name 只在 identity ancestor 下视为姓名，未知 mealPlan/hotelPackage 等经营对象保真；显式 ID/name/phone 继续 hash/mask |
| P2 | expansion/opening/transfer/feasibility/strategy/quant 的 source-backed 创建在来源读取后、插入前仍有删除/迁租/事实更新窗口，审批期拒绝不能撤销已建幽灵 intent | 六类创建统一 hotel→source 事务锁；锁内重验 hotel/source tenant、复算 source digest，之后才查重与插入；missing/deleted/tenant drift/读取后更新零写拒绝，创建与审批都以 hotel 为首锁 |
| P2 | 控制器显式日期用宽松 strtotime，`2026-02-30`/relative 可静默归一；effect 30 日窗口仍依赖进程默认时区 | 控制器与服务复用严格 `!Y-m-d`、warnings/roundtrip 校验，非法/relative/空/null/首尾空格 422；默认路径保持；effect 窗口统一 Asia/Shanghai 业务时钟 |
| P2 | staged helper 的有限手写列表遗漏模板构建库及 style/tailwind/bootstrap 等生成物，build-input-only/generated-only 可跳过三联门禁 | 新增单一 `frontend-managed-paths` 清单，由 hook 与测试共同消费；按目录/扩展名/构建命名覆盖源、构建/验证输入和生成物，managed 变更进入三联 verifier，public 变更另进 public-entry；4 个真实 Git-index 绕过先红后绿 |

第十六次三个单元均保留真实红证据后转绿：PII 模块 `54 tests / 845 assertions` 加 11 个消费者 `166 / 1,916`；staged-index 目标与 public-entry `18/18`；经营/执行主回归加 DailyWorkbench `229 / 1,538`，OMS 抽取持久化边界后降至 `6,228 / 6,294`。第一次 PHP 全量暴露 TC307 旧 SQLite fixture 无权威 hotels 表导致 3 errors+4 failures；只补正式 hotels/tenant 行且原断言、因子和失败 trigger 不变，TC307 `9 / 252` 转绿，PHP 再从头全量 `4,344 / 40,248 / 1 skipped`。前两次 Node 全量各暴露一个静态契约仍把已抽取的持久化/Knowledge SOP 分支固定在 OMS 主文件；测试改为同时验证 OMS 委托与 tenant concern 实现，相关 `5/5` 通过，第三次 Node 从头全量 `233 files / 1,550 checks`、0 skipped。P0、schema、template/entry/Tailwind、pre-commit、diff、hotspot no-growth 与 health 随后再次通过；第十六轮盲审尚未执行，报告继续 pending。

第十六轮冻结 61 个目标文件。两名 fresh-context 审查者按安全/正确性与稳定性/架构完成零写反证，起止 HEAD、COUNT、aggregate、report hash 一致，且 `writes/tests/builds/services/migrations/network/index=0`；均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。合并相同 alert/effect 与 Transfer 日期根因后确认 2 个 P1、2 个 P2：

| 级别 | 第十六轮盲审发现 | 第十七次定向返工 |
|---|---|---|
| P1 | action rows 已 current-tenant，但近30日 price_suggestions/operation_alerts 效果统计仍只按 hotel/date；alerts list/mark/create-intent 与 dedupe/update 也可在迁租后读取、修改、消费或覆盖旧租户行 | 为 alerts/suggestions 建统一 tenant schema/current-hotel gate；所有读、count、limit、update、dedupe、intent bridge 与效果统计先 tenant scope；mark/create 使用 hotel→alert 事务锁并二次重验；缺 schema/query error 失败关闭，迁租旧行不可见不可变不计入 |
| P1 | order 下语义明确的 `identity:{name,description}`、`personalInfo` 不含已登记 guest/traveller 主体，未进入 identity fail-closed，裸姓名可明文保留 | 明确 identity/personal identity 容器进入身份上下文；避免把普通含 info 的经营对象误判；身份后代默认关闭，经营 mealPlan/hotelPackage/未知业务 name 继续保真 |
| P2 | staged managed 清单覆盖 public css/html/js，却遗漏 public-entry verifier 自身读取的 `public/router.php` 与 `public/.htaccess`，单独暂存可跳过安全入口检查 | 增加独立 public-entry predicate 精确覆盖 router/.htaccess；只触发 public-entry，不把 PHP/.htaccess 误送三联；新增真实 Git-index 单文件反例 |
| P2 | TransferDecision controller/service 仍用宽松 strtotime 和进程默认日期，Feb30/tomorrow 可静默归一，30/365 日来源窗口错位 | controller/service 统一 `Asia/Shanghai + !Y-m-d + warnings/roundtrip`；显式非法/relative/空/null/首尾空格拒绝，默认上海业务日；补 HTTP 与服务反例 |

第十七次三个返工单元均保留真实红证据后转绿。告警/价格建议链补齐 authoritative hotel tenant schema gate、SQL scope、`hotel → alert` 锁、dedupe、intent bridge 与效果统计隔离，Transfer controller/service 统一严格 `!Y-m-d` 往返解析和上海业务日；直接/Route 回归 `148 tests / 2,133 assertions`，OperationManagementController + DailyWorkbench `26 / 290`。OTA 身份语义补齐 `identity/identities/personal/personals`，同时保持 `marketInfo` 等经营对象不被误伤，模块 `55 / 859`、11 个消费者 `166 / 1,916`。staged public-entry 清单补齐 `public/router.php` 与 `public/.htaccess`，二者只触发 public-entry、不误入前端三联，目标与 guard `20/20`。生产热点仍在 ratchet 内：OMS `6,277/6,294`，Alert concern `996/1,000`。

fresh PHP 首跑只暴露一份旧 `OperationAlertTaskBridgeTest` SQLite fixture 缺少正式 hotels/tenant identity；生产按新硬门正确返回 `migration_required`。仅补齐 fixture 的 `hotels(id,tenant_id)`、`operation_alerts.tenant_id` 与桥接输入 tenant，不改断言或生产代码后，该文件 `8 tests / 36 assertions`，随后从头全量 `4,351 tests / 40,317 assertions / 1 skipped`、退出码 0。fresh Node 首跑只暴露静态契约仍要求旧 `v1|hotel|alert` identity；测试改为验证生产已加固的 `v2|tenant|hotel|alert` 后目标 `13/13`，随后从头全量 `233 files / 1,552 checks / 0 skipped`、退出码 0。前端 template/entry/Tailwind 全部通过，hook/public-entry/staged-index 目标组合 `33/33`，Node/PowerShell 空 index 实际入口通过。P0、schema、hotspot、PHP 语法、diff 与本地 health 已通过；Composer CLI 当前不可用，PHP `ext-gd` 环境债保持公开。最终双盲尚未执行，因此不提前更新 maturity 结论。

第十七轮冻结 55 个目标文件；安全/正确性与稳定性/架构两名 fresh-context 审查者均在起止 HEAD、aggregate、report hash 完全一致且 `writes/tests/builds/services/migrations/network/index=0` 的条件下判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。去重并按最高严重度合并后新增 5 个 P1 与 3 个 P2：规则告警 generate→persist 的迁租/并发重复，价格建议未纳入 source-backed currentness，Transfer 历史记录及 daily/online 来源的 current-tenant 隔离，`roomingList` 入住名单 PII，价格建议上海默认执行日，告警缺表仍宣称可标已读并假成功，以及开放 action 效果窗口使用进程日期。第十八次返工按 Alert/effect、price source-backed、Transfer/PII 三个互不覆盖的生产单元先红后绿进行；完成前不更新最终 maturity。

第十八次三个返工单元已完成红→绿。Alert/effect 单元冻结 expected tenant，按 `hotels → operation_alerts` 锁后复核，使用 `SHA-256(tenant|hotel|type|source|date)` 与正式唯一索引收敛重复 winner；迁租旧事实不重标，新并发反例只产生一个 alert/intent。缺告警表、tenant 列、唯一索引或读写异常统一 fail closed，GET 不再宣称可标记、POST `updated=0` 不再返回伪成功；开放 action 窗口、三日观察和 finish timestamp 全部使用上海日历。纯分析 helper 提取至 `OperationAlertAnalysisConcern` 后热点为 `934/1000`，直接相关 `123 tests / 750 assertions`、Operation/Goal `367 / 2,839`、执行交叉 `34 / 574`。Price 单元将 `price_suggestion` 纳入 source-backed identity/create/approval/task currentness，锁内校验 hotel/tenant/status/applied_by/content/digest，同事实同目标 replay，来源或目标变化进入新受治理 lifecycle，旧租户 lifecycle 不可重包；默认与过去日比较统一上海业务日。Expansion `77 / 566`、OMS `57 / 382`、执行消费者 `82 / 615`。Transfer/PII 单元使 records/detail/archive 与 daily/online source 在查询、排序、聚合、归档前等值 JOIN current hotel tenant，缺 schema/query 异常诚实失败；订单 `roomingList/rooming` 进入 identity fail-closed，非订单经营描述保真。目标 `89 / 1,049`、Transfer 消费者 `12 / 108`、OTA 消费者 `35 / 265`。合并态 Expansion 全文件复验 `77 / 566`；fresh 全量与第十八轮双盲尚未执行。

第十八次合并态从头验证已完成：Expansion 三个全文件 `97 tests / 887 assertions`；PHP 全量 `4,372 / 40,431 / 1 skipped`，退出码 0；Node 全量 `233 files / 1,552 checks / 0 skipped`，退出码 0。P0 guards（含 high-risk、Revenue AI closure 1,767、静态集成 2,286）、schema readback 171/171、普通 hotspot、全工作树 diff 均通过。前端 template `4/4`、entry `3/3`、Tailwind `1/1`；staged/public-entry 三目标 `24/24`，public-entry guard、真实空 index helper、PowerShell context-only hook、Node syntax 与 PowerShell AST 均通过。Composer CLI/`composer.phar` 不存在，未虚报 Composer diagnose；vendor autoload 与 PHPUnit 可用，`ext-gd` 仍为公开环境债。第十八轮双盲尚未执行，因此 maturity 仍 pending。

第十八轮冻结 56 个目标文件；两名 fresh-context 零写审查者的起止 HEAD、aggregate、report hash 与 staged=0 全部一致，均判定 `VERDICT: FAIL / PROCESS_STATUS: READY`。去重后新增 6 个 P1 与 2 个 P2：Transfer 接受 A snapshot+B target 及多查询/保存迁租混合；同日 alert 原地更新后旧 intent 仍可 replay/审批；action effect 允许 before partial 或跨 whole-hotel/OTA scope 比较；price identity 漏 expected metric/delta/risk；`roomingInfo/Data/Details` 别名 PII；MySQL composite/prefix unique 被误认单列 dedupe；以及 temporal forecast/patrol/review 的进程业务日残留。第十九次返工按 Transfer/PII、source identity/alert、effect/业务日三单元先红后绿；完成前 maturity 保持 pending。

第十九次三个返工单元已完成红→绿。Transfer controller 要求 `snapshot/data_snapshot` 双别名一致且快照酒店与目标酒店一致；service 在单一事务内先锁 hotel、冻结 authoritative tenant，四组 daily/online 查询全部绑定该 tenant，保存时在同一锁内复核 snapshot/root/nested/source identity 后插入，迁租中途不能形成混合快照。订单 `roomingInfo(s)/Information/Data/Detail(s)/Row(s)/Item(s)/Record(s)` 以有界别名进入 identity fail-closed，非订单同名经营对象及 `groomingDetails` 保真；目标与消费者 `208 tests / 1,929 assertions`。`operation_alert` 纳入 source-backed identity/create/approval/task currentness，材料摘要变化后旧 intent/task 拒绝、当前材料建立新 lifecycle，材料变化恢复 unread，相同刷新保留 read；MySQL unique gate 按 Key_name 聚合，只接受完整单列无前缀索引。price identity 增加 normalized expected metric/delta/risk，分别变化均建立新 lifecycle；核心与交叉 `197 / 1,475`。Effect 判断要求 before/after 均 ok、有目标样本、source scopes 和 metric/scope/platform/source/grain 单一且完全一致；不可比结果为 observing+gap 且不进入 reviewed/命中率/提升率。Temporal forecast、patrol 与 review fallback 统一上海业务日；交叉 `192 / 1,424`。合并热点门禁通过：OMS `6,294/6,294`，Alert concern `993/1,000`。fresh 全量与第十九轮双盲尚未执行。

第十九次合并态 fresh PHP 首跑暴露两份旧 fixture 未携带告警材料快照和 effect metric identity；只补正式 fixture、不改生产或断言，定向 `2 / 24` 后从头全量 `4,386 tests / 40,517 assertions / 1 skipped`、退出码 0。Node 首跑只暴露一条静态契约仍要求 OMS 内联旧 md5；测试改为同时验证 Alert concern 委托与 tenant-bound SHA-256 snapshot helper，目标 `13/13` 后从头全量 `233 files / 1,553 tests / 0 skipped`、退出码 0。template/entry/Tailwind、staged/public-entry `20/20`、真实空 index hook、Node syntax、PowerShell AST 与 diff 全过。高风险门禁首跑又暴露 Transfer tenant 静态契约仍硬匹配重构前直接查询；门禁收紧为必须同时验证事务、`lockedHotelIdentity`、锁后 tenant、snapshot binding 与写入，high-risk 和完整 P0 随后通过。schema、hotspot 继续通过；startup 779,329 > 650,000 与 `ext-gd` 仍作为既有公开阻塞，不冒充本轮回归。第十九轮双盲尚未执行。

第十九轮双盲冻结 57 个目标文件，起止 HEAD/aggregate/report/staged 完全一致；两名审查者共同确认 3 个最终 P1：Transfer execution tracking 的迁租/并发追加，effect 的 7 天 before 与短 after 不等长终态，以及 price suggestion 的内部房型批准证据可绑定任意 OTA 房型/价计划。最终定向返工后：Transfer 写事务内重新执行 `hotel → transfer_record` 锁与 tenant/currentness，锁后追加且同 intent 幂等；effect 要求请求天数、actual days、calendar range 与目标样本天数完整等长，短窗口保持 observing；由于正式 schema 没有独立 OTA 映射表，价格建议只接受持久化在 source factors 中、带 record/version/tenant/hotel/platform/internal room/OTA room+rate/confirm audit/SHA-256 digest 的 `confirmed` 映射，并在创建、审批和 task mutation 前锁后复核。最终聚焦分别为 Transfer `40 / 203`、effect 目标 `5 / 37`、price mapping 及交叉 `200 / 1,366`；完整 P0、171/171 schema、hotspot 与 diff 通过。用户明确要求尽快收口并提交 GitHub，因此不再启动第二十轮扩展盲审；流程状态记为 `CAPPED`，而不是声称穷尽所有潜在问题。

## 6. 前端可见闭环修复

近期并发 UI 重构移除了 Revenue AI 远期定价预检的可见 hotel checks/required inputs 证据，P0 closure 因此失败。本轮在既有页面锚点内恢复一个紧凑、只读、事实化证据行：

- 可见输入缺口数。
- 可见酒店检查数。
- 保留“人工审核、不自动写 OTA”。
- 不引入默认值或伪造就绪状态。

同时不提高静态产物上限，通过压缩同段冗余文案使 `app-render.min.js` 为 1,424,996 字节，低于 1,425,000 上限 4 字节；Revenue AI closure 1,767 项通过。

## 7. 最终验证矩阵

| 层级 | 结果 | 证据与限制 |
|---|---:|---|
| 修改相关 PHP 语法 | PASS | 最终返工路径的 controller/service/concern/test 文件逐一 `php -l`；全量随后覆盖全部 PHP 测试 |
| PHP 全量 | PASS | 4,386 tests；40,517 assertions；1 skipped；第十九次合并态从头完整运行退出码 0 |
| Node 全量 | PASS | 233 files；1,553/1,553；0 skipped；第十九次合并态从头完整运行退出码 0 |
| P0 guards | PASS | security、worktree、login/auth style、Revenue AI、E2E static、production hygiene、display boundary、protected core |
| Revenue AI closure | PASS | 1,767 checks |
| 静态集成契约 | PASS | 2,286 checks；明确不是浏览器 E2E 证据 |
| 前端构建读回 | PASS | template snapshot/manifest/generated、entry artifact、Tailwind selectors 全部一致；render 1,424,996 / 1,425,000 |
| 数据库版本 | PASS | 主本地库 171/171 |
| hardening schema 读回 | PASS | 两列、两个唯一索引精确存在 |
| 专用性能 E2E 库 | PASS | 明确 `hotelx_performance_e2e`；171/171；测试数据执行后清理至 0 |
| Composer 元数据 | PASS | `composer validate --strict` |
| Composer 平台要求 | FAIL (ENV) | PHP 8.2.12 通过；`ext-gd` missing |
| fresh 认证性能 | PASS WITH WARNING | 最终重新隔离实测 5/5，产物 digest 全程一致且临时数据清理至 0；FCP/LCP p95 460ms；login→interactive 882ms；auth→interactive 295ms；API p95 275ms；最长任务 223ms，目标 200ms、硬线 550ms |
| 静态启动预算 | FAIL | 779,329 gzip；目标 620,000；硬线 650,000；超硬线 129,329 |
| 本地 health | PASS (LOCAL ONLY) | `/api/health` 200、数据库/schema ok；`development_fallback`、非生产就绪 |
| 本地首页 | PASS (LOCAL ONLY) | `/` 200；`text/html; charset=utf-8` |
| hotspot no-growth | PASS WITH DEBT | 所有 ratchet 通过；`OperationManagementService.php` 6,228/6,294，`OperationSnapshotConcern.php` 2,292/2,300，`Agent.php` 3,210/3,212 |
| hotspot 严格目标 | FAIL (KNOWN DEBT) | 多个历史热点仍高于目标；严格 `--strict-targets` 非零，不被 ratchet PASS 覆盖 |
| diff / hook | PASS | `git diff --check`；`core.hooksPath=.githooks`；index staged=0 的 Node/PowerShell hook 通过；第十七次真实目标组合 33/33，覆盖 managed frontend、router.php 与 .htaccess public-entry |
| 真实 OTA/PMS/WeCom/生产 | NOT RUN | 未获本轮自然业务或外部动作证据 |

## 8. 尚未关闭的问题

### OPEN-REL-001 — P1 — 静态启动包超过硬上限

当前 startup gzip 779,329，硬上限 650,000。主要贡献包括 `app-main.min.js` 398,800、startup helpers 109,480、deferred helpers 70,185、style 64,372、Vue runtime 39,853 等。

结论：fresh 本机运行时样本通过不覆盖静态 hard budget 失败；生产发布门禁仍应判失败。建议后续以行为域拆分 app-main/startup helpers，并逐项保留 digest 与登录/鉴权交互基线。

### OPEN-ENV-002 — P1 — 本机 CLI 缺 `ext-gd`

PhpSpreadsheet 明确需要 `ext-gd`。当前 Composer 安装目录中的其余非 dev 平台要求通过，但这一项缺失；任何依赖图片处理的 XLSX 能力都不能在该 CLI 上宣称完整可用。

### OPEN-RUNTIME-003 — P1 — 当前仅 development fallback

health 明确 `production_runtime_ready=false`，cache/lock 均 `not_enforced`。这证明本地开发可用，不证明生产运行模式、外部缓存/锁或部署配置可用。

### OPEN-ARCH-004 — P2 — OTA service 仍反向依赖 controller/HTTP concerns

首轮审计确认 `OtaActionHandler` 继承 HTTP Base 并组合 controller concerns。该问题需要逐 action 的应用服务/DTO/Response adapter 迁移，超出本轮最小安全修复范围。现有行为未因本轮扩大，但复用性、测试边界与入口一致性仍有风险。

### OPEN-ARCH-005 — P2 — 大文件热点仍存在

no-growth ratchet 全绿，但以下代表性债务仍在：

- `public/app-main.js`：54,424 行，目标 49,934。
- `OperationManagementService.php`：6,228 行，目标 4,800。
- `RevenueAiOverviewService.php`：6,386 行，目标 4,800。
- `OtaLocalCollectorService.php`：6,379 行，目标 4,800。
- `Agent.php`：3,210 行，目标 2,700。
- `AgentOtaDiagnosisBuildConcern.php`：2,457 行，目标 1,500。

这些是可度量的架构债，不是本轮修复失败；应按高频业务 action 逐个提取，禁止一次性全盘重写。

### OPEN-EVIDENCE-006 — 未获得 field validation

本轮没有真实 OTA/PMS 采集、企业微信真实送达、生产部署或自然经营日效果窗口。因此不能声称 `field_validated`，也不能把审批/测试通过表述为业务效果。

## 9. 变更与外部动作边界

- 已改代码、测试、规则、两份数据库迁移、前端源/兼容快照/生成物和本地 Git hook。
- 已在主本地库应用两份增量迁移；已在专用 `hotelx_performance_e2e` 库应用同两份迁移。
- 隔离性能测试只创建临时 tenant/user/hotel 与本地日志，结束精确清理到 0。
- 未发送企业微信、未写 OTA、未控制用户浏览器、未部署；本报告随用户明确授权的 GitHub 整合提交一并保存，具体提交与远端校验见任务最终交接。

## 10. 最终 Gauntlet 判定

- `VERDICT: PASS_WITH_KNOWN_RELEASE_BLOCKERS`
- `PROCESS_STATUS: CAPPED`（用户明确要求在最终三个 P1 收口、聚焦验证与 GitHub 整合后停止扩展审查）
- maturity：`integration_pass`。不具备 `comparative_pass` 或 `field_validated` 证据。
- 发布结论：静态 startup 包预算、`ext-gd` 与 `development_fallback` 仍阻止“生产发布就绪”；这些公开阻塞没有被测试绿灯覆盖。
