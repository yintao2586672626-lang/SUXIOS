# 宿析OS：极度详细审查问题修复与复验报告

日期：2026-08-25（Asia/Shanghai）
对应审查：`docs/qa/2026-08-25-extreme-merge-project-review.md`
隔离分支：`review/extreme-merge-20260825`
隔离工作树：`D:\桌面\SUXIOS\宿析OS初始版\.review-worktrees\extreme-merge-20260825`

## 1. 结论

- `VERDICT: PASS`
- `PROCESS_STATUS: READY`
- 证据成熟度：`integration_pass`
- 原审查中的 2 项 P0 与 15 项 P1 已关闭；终审新增的“客户端伪造收益基准日”和“生命周期 task scope 查询放大”也已关闭。
- 原审查 P2 中能在不改变业务语义、事实边界或授权边界的前提下安全修复的项目已经修复；需要异步任务、批量 read model 或制品验真分层的剩余项被保留为显式 P2，未用缩短超时、任意 limit、默认值、跳过验真或提高 ratchet 的方式伪装完成。
- 本报告证明的是隔离工作树的本地集成通过，不是 GitHub 已保存、已部署、生产可用、真实 OTA/企微/PMS 写入成功或酒店现场验证。

## 2. Git 与执行边界

- 所有整合与修复均位于上述隔离 worktree；没有 reset、clean 或覆盖原始脏 `HOTEL` checkout。
- 没有 commit、push、PR、部署或生产发布。
- 没有读取或持久化密码、Cookie、浏览器 Profile、localStorage、token 或 OTA/企微/PMS 凭证。
- 没有触发真实 OTA、企微机器人、PMS 或其他外部经营写入。
- 审批动作仍必须由用户主动触发；`pending_approval` 不会被系统自行批准或执行。

## 3. P0 修复结果

| ID | 状态 | 修复结果 | 主要防回归证据 |
|---|---|---|---|
| P0-1 认证首屏携程 facade 缺 key | 已关闭 | `ctrip-static-loader.js` 的 facade 与 full helper 全 key 对齐；full-only 方法在完整模块未加载时 fail-closed，加载后由真实实现替换。启动脚本同时校验当前 worktree 与实际 origin 的资产身份。 | `frontend_startup_helpers_build.test.mjs`、`verify_public_entry_guard.mjs`、完整 Node suite、实际资产 SHA readback |
| P0-2 通用 OTA 携凭证任意代理 | 已关闭 | `OtaCustomRequestService` 已变成无 transport 的 inert boundary，任何输入固定返回 `custom_request_disabled`/410；浏览器 URL、Auth、header、POST UI 已移除；恶意 fake transport 调用次数必须为 0。 | `OtaCustomRequestServiceTest.php`、`ota_custom_proxy_disabled.test.mjs`、`verify_high_risk_security.php`、P0 guards |

说明：旧兼容路由暂时仍保留，但只经过认证、权限、酒店/租户范围检查后返回 410 并记录内部拒绝审计，没有外部网络能力。它是兼容期维护面，不再是可利用代理。

## 4. P1 修复结果

| ID | 状态 | 修复结果 |
|---|---|---|
| P1-1 三源 onboarding 消失 | 已关闭 | 恢复四步门店 onboarding、真实入口、酒店精确绑定、计划保存和精确 readback；不采集凭证。 |
| P1-2 三源整点通知无前端控制面 | 已关闭 | 恢复严格来源状态、整点 preset、时段/runtime/readiness 展示和酒店 80 的 30 分钟特例；外部发送仍受正式机器人和显式边界控制。 |
| P1-3 携程可见页下载使用旧表 | 已关闭 | 下载数据改为从当前渲染 DOM 的 visible snapshot 取得，缺失时 fail-closed，不再回退第二套字段映射。 |
| P1-4 AI 报告表 hotel_id 未进云迁移 registry | 已关闭 | `ai_report_presentation_specs.hotel_id` 与 `ai_report_presentation_artifacts.hotel_id` 已纳入统一 registry 与迁移检查。 |
| P1-5 携程公开档案选店依赖已有采集配置 | 已关闭 | 选项改从当前用户 permitted hotel pool 构建；无采集配置的新门店仍可进入公开档案绑定。 |
| P1-6 operation lazy fingerprint 过期 | 已关闭 | loader 指纹与 `operation-static.js` 当前内容一致，并由资产版本测试锁定。 |
| P1-7 顾问 grounding 只查百分比 | 已关闭 | grounding 覆盖金额、数量、日期、百分比、单位、scope、source ref；无法绑定的数量主张使 run 变为 partial/blocked，不能冒充 verified。 |
| P1-8 WeCom pending 崩溃永久吞事件 | 已关闭 | 增加 processing lease、随机 claim token、过期租约恢复、CAS 终态更新和精确回读。 |
| P1-9 WeCom 同帧绑定重试 digest 冲突 | 已关闭 | 在状态变化前建立原始帧 identity digest；脱敏展示文本与幂等身份分离，同帧重试复用原 identity。 |
| P1-10 点评订单变更 commit 后才审计 | 已关闭 | 携程/美团 bind、reject、unbind 的审计均在同一事务 commit 前完成；审计失败会整体回滚。 |
| P1-11 blocked 仍展示经营数字 | 已关闭 | 三源门禁失败时数值 facts/metrics/integrated sources 为 `null`，报告只显示 blocker，不以 0 或旧值掩盖缺证。 |
| P1-12 7 项源码 no-growth 门禁失败 | 已关闭 | 通过 concern/service/route/verifier 抽取与精确 ratchet 恢复门禁；没有提高上限掩盖新增。 |
| P1-13 本地、hook、CI gate 清单漂移 | 已关闭 | `suxios.integration_gate.v1` 成为 package、规则、staged hook 与 CI 的 canonical 入口，逐项 fail-fast。 |
| P1-14 未知 Throwable 原文泄漏 | 已关闭 | 统一 `ApiExceptionMapper`：明确业务 4xx 可返回安全消息；未知错误固定 fallback、correlation id 与脱敏日志。 |
| P1-15 收益快照浏览器/后端双时钟 | 已关闭 | overview 发布版本化 server `as_of_date`；浏览器只消费服务端日期与 server-issued canonical model，不再独立生成完整语义。 |
| 终审 P1-A 客户端可伪造 `as_of_date` | 已关闭 | controller 丢弃 body/query 中的 `as_of_date` 与版本；生产 overview 仅使用 Asia/Shanghai 服务端日期；签发、快照 readback、pending 创建和最终 provenance 都要求当前日期+版本，旧/未来日期返回 stale/409。 |
| 终审 P1-B 生命周期完整性校验放大为约 +5N task 点查 | 已关闭 | 完整聚合读取复用同一 scope-safe persistence read 已加载的 task identity；已加载 task 的点查为 0。缺失、未知、异域或直接链读取仍回退数据库 fail-closed；异域 task 不进入 task count、trace refs 或 evidence 查询。 |

### 4.1 收益基准日最终边界

当前链路为：

`客户端请求（日期覆盖被丢弃） → Asia/Shanghai serverAsOfDate → 服务端签发 canonical model + digest → 快照精确保存/readback → 当前来源与当前基准日复核 → pending_approval → 用户主动批准时再次 provenance 复核`

关键性质：

- 普通客户端 SHA digest 不是授权令牌；服务端会基于当前事实重新签发并做 canonical digest 全等。
- 跨午夜旧快照会明确变为 `stale_current_as_of_date`，不能继续创建 pending 或通过最终行动当前性检查。
- `buildOverviewFromDataset()` 仍允许测试/历史内部 fixture 传显式日期，但生产 `overview()` 不接受客户端日期，任何写边界都要求当前服务端日期。

### 4.2 生命周期查询优化最终边界

- 只有 intent、task 同时满足 `task_id > 0` 且 tenant、hotel、intent 全等时才进入本次聚合读取的 known set。
- event/review 的 tenant、hotel、intent、sequence、previous digest、content digest 校验未删除。
- event/review 引用不在 known set 的 task 时，仍执行数据库 scope 查询；查不到即链损坏。
- `decorateTask()`、`eventsForIntent()`、`reviewsForIntent()` 保持数据库 scope 校验。
- 异域 task 即使出现在传入 tasks 数组，也不会进入 `task_count`、`task_refs` 或 evidence ID 查询。

## 5. 原 P2 处理结果

| ID | 当前状态 | 处理 |
|---|---|---|
| P2-1 数据库异常被伪装成未迁移 | 已关闭 | schema-missing 与普通 DB/运行异常分开分类，未知异常不再被降格成迁移提示。 |
| P2-2 巨型服务、trait 隐式合同、循环依赖 | 已缓解并设硬门禁 | 已抽取 operation persistence/snapshot/alert/receipt/effect/tenant concern、route manifests、共享 source-concern registry，并解除 Revenue/Operation 循环依赖；现存单体继续由 no-growth ratchet 阻止增长。 |
| P2-3 收益 visible model JS/PHP 双实现 | 已关闭主要风险 | PHP 作为 canonical issuer，浏览器只接受 server-issued v2 model 与 digest；保存前服务端重新签发并全等比对。 |
| P2-4 校验脚本成为第二套单体 | 已缓解并治理 | verifier domain registry、可执行 policy 与 hotspot budget 已接入 canonical gate；大 verifier 仍是显式债。 |
| P2-5 顶层静态断言吞掉后续覆盖 | 已缓解 | 关键安全合同迁移到 PHPUnit/Node test；runner 逐文件报告；遗留大测试文件仍需按业务域继续拆分。 |
| P2-6 Node runner 无超时/hang 定位 | 已关闭 | 逐文件串行、300 秒超时、精确 START/COMPLETE/timeout 文件报告；本轮最慢文件约 158 秒并正常完成。 |
| P2-7 Windows 主运行面、CI 仅 Ubuntu | 已关闭关键缺口 | CI 增加 Windows lane 与 Windows/PowerShell 合同；不能把本地通过等同为远端 CI 已执行。 |
| P2-8 路由/规则固化单文件热点 | 已缓解 | 认证域路由拆为 manifest 并锁定 method、URL、handler、顺序、Auth surface；根路由仍受 787 行 ratchet。 |
| P2-9 OTA 代理回传 upstream headers | 已关闭 | 整个代理 transport 与前端入口停用，已不存在 upstream response/header 回传。 |
| P2-10 模型文档与运行身份相反 | 已关闭 | 文档改为当前本地模型与用途边界，不再把旧模型写成实际运行事实。 |
| P2-11 通知候选加载与 due-row N+1 | 已关闭主要查询放大 | 候选使用 bounded keyset/prefetch，避免把 limit 只用于最终输出；调度语义与失败状态保持不变。 |
| P2-12 54k+ 前端入口占大部分 gzip | 已缓解并锁定 | 多个运行域已 lazy/extract；startup gzip 为 587,092/620,000；`app-main.js` 当前 55,710/55,710，禁止继续增长。 |
| P2-13 多 worktree 运行身份串树 | 已关闭当前启动误认 | 启动解析脚本所在 repo 的绝对 root，检查 HEAD/dirty/asset digest，并对 origin 资产做字节级 SHA readback；完整多资产 manifest 身份仍列为后续 P2。 |

## 6. 终审新增、明确保留的 P2

以下项目没有当前 P0/P1 复现，也不在现有 deterministic integration contract 中；它们需要独立的产品/架构循环，不能在本轮用表面改动安全关闭。

| P2 | 当前风险 | 正确后续方向 |
|---|---|---|
| 本地媒体同步提取最长占用 HTTP 约 900 秒 | 长音视频会占用请求，超时/进程树回收证据不足 | 持久化 extraction job，worker 执行，GET 回读 pending/ready/partial/failed，按 source SHA 幂等并验证进程树回收。 |
| 顾问会诊 3–5 成员加主持人串行模型调用 | 最坏响应时长可能超过控制器预算 | 持久化 council run，成员独立落盘，总 deadline 后 partial 收口，主持人仅汇总 ready 成员；保持 `decision_effect=none`。 |
| 美团批量点评 200–500 条逐行扫描与逐条事务 | 大真实批次 CPU 与事务 N+1 尚无量级证据 | 先冻结候选语义并构建日期/标识索引；再决定逐条部分提交或批次原子语义。 |
| 经营记忆在线重嵌入最多 30 条文档 | 本地模型不可用或慢时拉长提问请求 | 按 `memory_id + text_digest + model/version` 持久化文档向量，在线只嵌入问题，保留 lexical fallback。 |
| 收益驾驶舱恢复逐 intent readback | 高基数日期可能出现 N+1 | 增加 scope-safe exact-ID 批读 intents/tasks，再逐步批量化 events/reviews；不得取消 cockpit identity/readback assertions。 |
| 演示包 metadata GET 读取、hash、解包 blob | 打开页面或切换 audience 可能读取大 blob | 保存时持久化版本化验真收据；metadata 与显式 download/integrity endpoint 分层，不能继续沿用过强字段名。 |
| 运行身份只比对 `app-main.min.js` | 两个 worktree 主入口相同但 lazy 资产不同的理论误认 | 生成不可变 asset-manifest digest，覆盖 index、main、startup/deferred helpers、render 与关键 lazy loaders。 |
| facade 全 key parity 主要由 full Node/CI 拦截 | 单独只跑 public-entry 时可能延后发现新增 key | 将 VM key parity 下沉到 `inspectFrontendStartupHelpers()`，让 public-entry 自身直接阻断。 |
| 生命周期公开装饰方法可接收非空陈旧 task 快照 | 当前正式路由均由数据库完整加载后立即装饰，未发现现行绕过；但未来新调用者若复用陈旧非空快照，known scope 可能跳过一次数据库复核 | 将“完整且可信的本次数据库 aggregate”改成显式、默认关闭的内部契约，并增加非空陈旧 task 快照回归；不要向请求参数开放该标记。 |

这些 P2 会阻止对应能力获得 `field_validated` 或特定延迟 SLO，但在当前证据下不推翻本次 `integration_pass`。

## 7. 最终验证矩阵

| 验证 | 结果 |
|---|---|
| 变更 PHP 语法 | 213 个文件，0 失败 |
| 聚焦收益日期/快照/provenance | 86 tests，933 assertions，全部通过 |
| 聚焦生命周期完整性/查询边界 | 23 tests，280 assertions，全部通过；完整聚合 task point select=0，不完整/直接读取均为 2 并 fail-closed |
| 全量 PHPUnit | 4,906 tests，45,609 assertions，0 fail/error，2 skipped |
| 全量 Node 自动化 | 275/275 test files complete；0 fail/cancel/timeout |
| Revenue AI 闭环 | 1,774 checks passed |
| Revenue AI 前端静态合同 | 52/52 passed |
| 数据库迁移幂等 | `db:migrate` 连续两次：192/192 current |
| 数据库版本 | `db:check`：192/192 current |
| Source hotspot | 通过；没有提高 ratchet |
| Canonical integration | 通过：registry、hotspot、P0 guards、2,288 static integration checks、public-entry/core-scope、diff-check |
| 本地 health | `http://127.0.0.1:18080/api/health` = 200，backend pool size=3 |
| 本地运行身份 | 本地与 origin `app-main.min.js` SHA-256 都为 `66300ae70c63a0ca1145c074172c17049c1aac47ab2a5013859eb7d67697d293` |
| 未认证根页面 | HTTP 200，HTML 引用当前 `66300ae70c` 资产版本 |

PHPUnit 的 2 个 skip 是既有环境条件跳过，不是失败；Node runner 没有使用 allow-runtime-skip。
验证结束后，已按精确 PID/端口身份停止本轮隔离测试栈（18080–18083）；上表 HTTP 与资产摘要是停止前的点时验证证据，不表示服务仍在运行，也未触碰原工作区的 8080。

### 7.1 独立零写入终审

- 前端与高风险安全终审：`PASS`；原 2 项 P0、15 项 P1 均未在最终树中复现，源码、模板、lazy loader、生成物与指纹未发现静态漂移。
- 收益日期终审：`PASS`；未找到 body、query、旧快照或跨上海午夜绕过服务端 `as_of_date`、pending 创建或最终审批当前性门禁的路径。
- 生命周期终审：`PASS`；未发现 tenant、hotel、intent、task scope 绕过，异域 task 不进入 task count、trace refs 或 evidence 查询；保留上表“非空陈旧 task 快照”为未来维护型 P2。

三次终审均为当前未提交树的零写入静态复核；它们与本节的实际测试、迁移、HTTP 身份验证互相补充，但不替代目标环境与真实业务现场验证。

## 8. 已验证与未验证边界

### 已验证

- 隔离 worktree 中的源码、模板、构建产物、指纹、迁移和静态/后端合同一致。
- P0/P1 触发链已由实现和防回归测试共同关闭。
- 通用 OTA 代理没有 transport，浏览器没有入口，恶意 transport 不会被调用。
- 收益日期不能由客户端覆盖，旧/未来快照不能跨越 pending/最终行动当前性边界。
- 生命周期查询优化没有取消 task scope 的 fail-closed fallback。
- 本地隔离 origin 确实服务当前 worktree 的主资产，不是仅凭端口或 HTTP 200 推断。

### 未验证

- 未登录本机 admin 后复验所有认证业务页面；无可用安全登录态时没有读取密码库、Cookie、Profile、localStorage 或 token。
- 未执行真实携程/美团采集、企微发送、PMS 推送或经营动作。
- 未运行 GitHub 远端 CI、未创建 PR、未部署腾讯云或生产环境。
- 未做真实 200/500 条点评批处理、长音视频、长 council run 或高基数 cockpit 恢复的性能压测。
- 未获得同任务生产 A/B、用户结果或酒店现场证据，因此不是 `comparative_pass` 或 `field_validated`。

## 9. 发布判断

当前隔离树可以称为 `integration_pass`，不能称为 production-ready 或 field-validated。若要进入 GitHub/发布流程，下一步必须由用户明确授权 commit/push/PR；之后仍需远端 CI、目标环境 migration/readback、已认证页面验收及零外部副作用检查。真实 OTA、企微、PMS 或审批写入仍须用户主动触发并使用目标环境证据重新验收。
