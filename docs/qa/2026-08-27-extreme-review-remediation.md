# 2026-08-27 极度详细 Review 与修复总账

## 结论

本轮在独立同仓工作树 `review/extreme-review-20260827` 中完成代码、稳定性、规则和架构审查，并修复审查中确认的高影响问题。最终候选已通过完整 PHP、完整 Node、canonical integration、前端入口、运行资源身份和隔离业务链验证。

当前证据成熟度是 **local integration pass**：代码与本地隔离业务链已验证；未提交、未推送、未部署，也没有真实 OTA/Profile、PMS、模型或生产现场验证。

## 审查边界

- 基线：`origin/main@819e90812abc99af8be636c47b4b82c615668534`。
- 工作树：`D:\桌面\SUXIOS\宿析OS初始版\.review-worktrees\extreme-review-20260827`。
- 共享脏工作树未清理、未 reset、未批量 stage。
- 没有执行真实 OTA/PMS 写入、企微发送、审批、模型调用、Git commit/push 或部署。
- 测试数据只写入 SQLite fixture 或明确命名的本地 `_e2e` 数据库；本轮创建的 review 数据库已精确删除。

## 已确认并修复的问题

### 1. Cloud collection 阻断任务无法安全恢复

**问题**

- 昨日终态采集被数据缺口阻断后无法重新排队。
- 初版重试又暴露旧 receipt 指纹不兼容、父 attempt 迟到成功与子 attempt 并存、永久错误无限重试等问题。

**修复**

- 仅 `missing_saved`、`missing_readback` 可自动重试。
- 最多 3 次、60 秒退避；永久错误进入人工复核。
- 保留旧 receipt fingerprint 兼容。
- newer attempt 存在时，旧 attempt 的迟到 receipt 返回 `superseded_attempt`，不改写父终态。
- dispatch evidence 保存 attempt、parent、上限、终止时间和下一次允许时间。

### 2. 美团/携程 traffic 来源身份不完整

**问题**

- Cookie/API traffic 行可能缺少 `platform`、`ingestion_method` 和平台酒店身份，写入看似成功但严格消费者随后丢弃。
- 正式 P0 importer 曾遗漏新参数。

**修复**

- 统一保存并精确回读 `platform + ingestion_method + system hotel + platform hotel + date`。
- MySQL/SQLite schema 读取统一使用 `DatabaseSchemaRequirement`。
- 所有 controller 和正式 P0 importer 调用点补齐来源身份；旧数据保持显式未验证，不做默认值冒充。

### 3. Operation lifecycle 使用陈旧 task snapshot

**问题**

- task snapshot 取得后 tenant/hotel/intent 发生漂移，旧快照仍可能装饰成可继续生命周期。

**修复**

- 默认 `decorateIntent()` 重新按数据库验证 task scope。
- 只有同次数据库聚合才能显式调用 trusted aggregate 入口。
- tenant/hotel/intent 漂移均 fail closed，不自动批准或执行。

### 4. AI 日报空态与隔离测试模型调用

**问题**

- 尚未生成 presentation 时，页面 GET 返回 404 并产生控制台错误。
- 隔离业务测试会继承可用模型配置，产生非确定性真实模型调用风险。

**修复**

- “当前尚未生成”返回 HTTP 200、`not_generated` 明确空态；显式不存在的 artifact ID 仍返回 404。
- 前端把 `not_generated` 呈现为空态，不伪装失败或已生成。
- `SUXI_DISABLE_MODEL_CALLS` 同时支持进程环境与 ThinkPHP `.env`；隔离 E2E 强制启用。

### 5. migration 注册后可被同时改 SQL 与 checksum

**问题**

- 原验证只证明“当前 SQL 与当前 checksum 自洽”，首次引入时可同时改历史 migration 和重生成 checksum。
- base ref 不可读时曾 fail open。

**修复**

- 新增 `database/migration_checksums.lock.json`，锁定 194 个 migration 和 5 个 frozen source。
- base ref 不存在或不可读时非零退出。
- base 尚无 lock 时，直接从 base Git tree 计算历史身份进行 bootstrap；不能通过修改当前 lock 掩盖历史漂移。
- CI checkout 使用完整 Git 历史并传入明确 base SHA。

### 6. linked worktree 读取错误规则根

**问题**

- 隔离 worktree 可能读取 `.review-worktrees/AGENTS.md`，而不是主仓外层权威规则。

**修复**

- context resolver 优先依据 Git common-dir 找到主仓外层根。
- staged frontend hook 复用同一解析器，并加入 stale shadow AGENTS 反例。

### 7. CI 缺少 OTA → 收益 → 运营业务链

**问题**

- 现有 CI 没有执行 `business-chains.spec.js`，局部门禁绿色不能证明产品主链闭合。

**修复**

- CI 增加 `npm run test:e2e:business`。
- isolated runner 固定专用 `_e2e` 数据库、loopback 服务、模型禁用、精确 seed/readback/cleanup。

### 8. 前端 facade 与运行资源身份门禁不完整

**问题**

- Ctrip startup facade 只比较导出 key，不比较导出类型。
- 运行资源身份曾只覆盖 JS/CSS，遗漏动态登录背景、Font Awesome CSS/webfont 和 CSS `url()` 资源。

**修复**

- facade 逐 key 比较 `typeof`，函数退化为标量会阻断 public-entry。
- runtime asset graph 扫描 HTML 静态/动态引用、认证 manifest、JS lazy 引用、CSS `url()` 与字体。
- Font `src` 多格式按同一 face 回退组判断；普通缺失 CSS 依赖仍 fail closed。
- 本地启动按完整资源清单逐文件比较 bytes/SHA-256，而不是只比较 `app-main`。

### 9. 经营顾问会诊同步串行模型调用

**问题**

- 一个 HTTP 请求内串行最多 5 个 lens + 1 个 chair。
- 配置上界约 543 秒，超过 controller 的 180 秒预算。
- 原实现无 pending reservation、checkpoint、worker ACK、断点恢复或跨 worker fencing。

**修复**

- HTTP 只原子保留 pending run，并自动派发本机 worker；POST 不调用模型或 strict fact reader。
- 同 tenant/hotel/question 在数据库问题行锁内只允许一个 active run，跨不同 client key 复用同一 run。
- 每次派发绑定唯一 attempt、parent digest 和 expected generation；数据库 ACK 才算启动成功。
- 每个 worker 使用数据库时钟 lease、generation 和 fencing token；所有 checkpoint/terminal 更新均 CAS。
- lens 逐个持久化 checkpoint；同 run resume 复用身份一致的 ready lens。
- panel/per-lens contract、事实 packet、members/evidence/model-meta 均做摘要绑定。
- member 后、chair 前、chair 后三次严格事实复读；发生事实或 panel 漂移时隔离 checkpoint，正式 members/evidence/model-meta 清空。
- 前端有界轮询，问题切换 generation 隔离；只展示 `completed/partial + artifact_integrity=verified` 的观点。
- v5 running 有受控 stale 升级，API 返回真实 `persisted_contract_version`。

### 10. 31 天 × 房型调价建议 N+1 与长事务

**问题**

- 31 天、20 房型时保守约 2,480 次 SELECT，缺 forecast 时可接近 4,960 次。
- 查询位于长事务内；批量改造初版又暴露 tenant hook 绕过、窗口限额漂移、MySQL placeholder 超限和二次 unique race。

**修复**

- 事务前批量加载 pending、forecast、competitor、traffic/history，并按 `date|room_type` 建不可变索引。
- 正常写事务只执行预算受控的多行 INSERT；事务后分块精确回读。
- 每次生成先读酒店和房型权威 tenant/hotel，不能信任调用者行。
- pending 按 canonical dedupe key 精确查询，不使用日期范围近似。
- INSERT 同时受 60,000 bind 和 2 MB 估算预算限制；31×118 不越过 MySQL 65,535 参数上限。
- unique conflict 最多 3 轮，每轮完整回滚后读取 winner；第二次 race 也转换成稳定 deduplicated skip。
- strict Ctrip traffic 读取拆为：
  - `CanonicalOtaHistoryReceiptVerifier`：验证 source/task/run-readback/promotion v3/row digest/operation selection/provenance/content digest。
  - `StrictCtripTrafficHistoryReader`：只按 receipt 选出的 authoritative row ID 做窗口回读和 2,001 行哨兵。
- `field_fact.status` 必须精确为 `captured`；failed/unverified/伪 source-task/无 `system_hotel_id` 均 fail closed。
- 正式 canonical promotion row 可用，未选 partial sibling 只计为 ignored，不阻断权威行。

**查询上界证据**

- `1 天 × 1 房型` 与 `31 天 × 8 房型` 的核心查询数相同：forecast 1、competitor 1、pending/readback 2、正常 INSERT 1。
- 31×8 共 248 组合的隔离实测总 SQL 为 24 条，正常事务内 1 条 INSERT。
- 31×118 的 pending key 分 4 个 IN chunk；写入按 bind/packet 预算分块，不回退逐行。

### 11. 浏览器采集同步占用 HTTP worker、进程树与日志不受控

**问题**

- 默认 capture 可同步占用 HTTP worker 600–900 秒。
- 三套 runner 行为不一致；部分路径只终止 Node 父进程。
- 根进程正常退出不代表 Chromium 子树退出。
- Windows 输出文件无真正硬上限；Profile 锁、quarantine、auto-fetch 和登录路径不统一。
- 后台 task 曾缺少原子 claim，并可能把未回读/no-data 包装为成功。

**修复**

- 默认 UI 改为 HTTP 202 + `task_id` + 有界轮询；后台 CLI 在进程内通过 ThinkPHP kernel 调用，不再 curl 回环占用第二个 HTTP worker。
- `BrowserCaptureProcessRunner` 统一 controller、Ctrip adapter、Meituan adapter、auto-fetch 和 Profile login。
- Windows 使用 PID + CreationDate 跟踪完整后代；Linux 使用 bounded PGID/SID handshake，同时保留逃组后代 PID identity。
- timeout/cancel/nonzero/unknown/orphan/output-limit/tree-unconfirmed 各自返回稳定 receipt。
- Windows 通过 PowerShell+C# bounded relay 并发读取双流；8 MiB 高速输出时持久化输出不超过配置 cap，metadata 固定小型，超限后终止整树。
- Profile 锁只在完整树确认退出后释放；unconfirmed 把 PID/tree/artifacts 写入 quarantine，复核树为空后才受控恢复。
- auto-fetch 与手动采集/登录使用同一 Profile key 和锁；输出路径使用微秒 + 随机 token。
- task 使用 `queued → running` owner CAS；迟到或重复 worker 不执行采集。
- `success/available` 必须同时满足 `saved_count>0`、`readback_count=saved_count`、显式 `readback_verified=true`。
- 登录 stdout/stderr 先经 sanitizer，再写 0600 临时日志；Bearer/Cookie/token 不落 raw log。
- 前端终态 identity 只使用 server-verified profile/store，不自回显提交值。

## 最终验证证据

| 验证 | 最终结果 |
|---|---|
| 完整 PHPUnit | 5,137 tests / 48,912 assertions / 0 failures / 3 skipped |
| 完整 Node automation | 280/280 test files / exit 0 |
| Canonical integration | 5/5 checks / pass |
| P0 static integration contracts | 2,288 checks / pass |
| Revenue AI closure | 1,774 checks / pass |
| Business-chain Playwright E2E | 3/3 / pass |
| Business E2E cleanup | 142 scoped rows before cleanup → 0 after cleanup |
| Migration lock | 194 migrations + 5 frozen sources / pass |
| Public entry | pass; startup gzip 589,361 / 620,000 bytes |
| Runtime asset identity | 55 assets / SHA-256 manifest pass |
| Source hotspot ratchet | pass; no threshold increased |
| Project self-audit | status `ok` |
| `git diff --check` | pass |

## 数据库与临时状态

- 新建并初始化 `hotelx_extreme_review_final_20260827_e2e`：194/194 migration。
- 业务 E2E 结束后 scoped rows 精确清理为 0。
- `hotelx_extreme_review_final_20260827_e2e` 与本轮早期 `hotelx_extreme_review_node_20260827_e2e` 已精确删除，`information_schema` 回读数量为 0。
- 既有 `hotelx_e2e` 因历史 migration checksum 漂移无法安全升级，保持原状；未删除、未覆盖、未绕过 checksum。

## 仍然存在的边界与架构债

1. **没有现场证据**
   - 未运行真实 OTA/Profile、真实 Chromium OTA 会话、PMS、真实模型或生产账号。
   - 未证明生产部署、真实发送、审批执行或经营效果。

2. **真实 MySQL 并发/性能证据有限**
   - 本地 MySQL E2E 已通过，但没有双 PHP 进程的 council lease 断线重连压力测试。
   - 31-window UNION、MySQL optimizer、packet 和极端房型规模没有生产级 EXPLAIN/压测。

3. **受控同步兼容路径仍保留**
   - 默认 UI 已异步；显式 `sync=true/async=false` 的内部兼容入口仍可能同步等待 600–900 秒。

4. **大型文件仍有 ratchet 债务**
   - `public/app-main.js`：55,710 行，当前 ratchet 余量 0。
   - `OperatingQuestionCouncilService.php`：约 3,116 行。
   - `RevenuePricingRecommendationService.php`：约 3,328 行。
   - `BrowserCaptureProcessRunner.php`：约 1,479 行。
   - 本轮没有抬高阈值；后续新增行为前应优先按业务职责继续抽取。

5. **PHPUnit skip**
   - 最终完整套件仍有 3 个环境/fixture 条件 skip；它们不是失败，也不能当作已验证现场能力。

## Git / 发布状态

- Git：未提交、未推送、未创建 PR。
- 部署：未执行。
- 现场：未验证。
- 当前修复只存在于隔离 review 工作树；共享脏工作树未被覆盖。

## 2026-08-28 integration 收口补充

本节覆盖上方旧的 Git 状态描述；原 review 证据和边界说明保留用于追溯。

### 本地 checkpoint 与集成基线

- review 工作树已整理为本地 checkpoint `78b2d0600d652d435b0ef563ab466ef47b8cbd82`，未推送。
- 已获取最新 `origin/main`，集成基线为 `0817e0cf8cc47245fa30dc3b083bc602b01d0efe`（`fix: close daily review regressions (#41)`）。
- 新集成工作树：`integration/extreme-review-20260828`；最终状态应为相对 `origin/main` ahead 1、工作树 clean、本地待推送。
- 共享脏工作树未清理、未重置、未覆盖。

### 已登记 migration 修复

- 恢复 `20260822_zzzz_refine_manager_capability_event_timestamps.sql` 的已登记原始内容；followup 表结构继续由既有后续 migration `20260822_zzzzz_refine_manager_capability_followup_event_timestamp.sql` 承担。
- 原 migration 的受信 SHA-256 为 `f03b5e1e722f220803516b70f478dd2bbce3f1d43b058ca984709ccd5ed352ea`。
- migration checksum lock 升级为带受信祖先锚点的 schema v2；锚点提交为 `819e90812abc99af8be636c47b4b82c615668534`，锚点目录摘要为 `6f83207ea1884d7aad953dc59770028999e800b8b8e4ed783c2feb18069abd88`。
- verifier 明确识别并修复 1 个上游历史漂移，同时保持锚点之后新增 migration 的不可变约束：`194 migrations + 5 frozen sources`，`base source=anchored_ancestor_bootstrap`，通过。

### 5 个前端重叠收口

最新主线与 review checkpoint 重叠的 5 个文件已在新集成工作树完成语义合并，并通过官方 `build:frontend` 从合并后的源重新生成产物：

| 文件 | 最终 SHA-256 |
|---|---|
| `public/app-main.js` | `0e6d4ed61a8296cb19377e89a856c02b6ce5cbd14741811aa903e0361c08afe4` |
| `public/app-main.min.js` | `0d5e2c2a2481a4709654629e2291ba932d9332be7811e0ada7b1e5c9a200b736` |
| `public/components/system/ai-daily-report-delivery.js` | `de97a97ae7971e63cbe73899da836791c482efe426465a602144f69247bdab19` |
| `public/components/system/business-closure-loader.js` | `5e72434fb457d87aac2a1e4bacdd35c695141d080a8a0a54114fc93440aef4b8` |
| `public/index.html` | `40b477eeeee4998ac98112e74f190a45d09dbe766dd122b34362da55465a2489` |

合并保留了主线的 `AbortError` 收口和 AI 日报 `404/not_generated` 处理，也保留了 review checkpoint 的前端修复；冲突标记为 0。

### 最新完整验证

| 验证 | 2026-08-28 integration 结果 |
|---|---|
| 完整 PHPUnit | 5,138 tests / 48,916 assertions / 0 failures / 3 skipped |
| 完整 Node automation | 280/280 test files / exit 0 |
| Canonical integration | 5/5 checks / pass |
| P0 static integration contracts | 2,288 checks / pass |
| Revenue AI closure | 1,774 checks / pass |
| 独立数据库初始化 | 194/194 registered migrations |
| `db:migrate` 幂等性 | 连续两次 current / pass |
| `db:check` | 194/194 current / pass |
| Business-chain Playwright E2E | 3/3 / pass |
| Business E2E cleanup | 142 scoped rows before cleanup → 0 after cleanup |
| 专用 E2E 数据库清理 | `hotelx_extreme_integration_20260828_e2e` 已删除，`information_schema` 回读 0 |
| Public entry | pass；startup gzip 589,382 / 620,000 bytes |
| Runtime asset identity | 55 assets / digest `4ba413fb26b6bd288b5fb4e6208a10b2461fe9b94bae4d7c4dcbddd3a35d3692` |
| Project self-audit | status `ok`；可回收临时产物 0 MB |
| `git diff --check` | pass |

### 证据边界

- Git：本地 checkpoint 和本地 integration commit；未推送、未创建 PR。
- 部署：未执行。
- 现场：未运行真实 OTA/Profile、PMS、模型、企微发送、审批或经营动作；本地/自动化通过不等于生产或现场验证。
