# 宿析OS 全量修改合并、极限审查与修复报告

- 审查日期：2026-08-22（Asia/Shanghai）
- 应用范围：`HOTEL/`
- 原始工作区：`D:\桌面\SUXIOS\宿析OS初始版\HOTEL`
- 隔离集成工作树：`D:\桌面\SUXIOS\宿析OS初始版\.review-worktrees\extreme-merge-20260822`
- 集成分支：`review/extreme-merge-20260822`
- `origin/main` 冻结基线：`b42fc9e7e4ad971c27be8505bd3276a98472e3cc`
- 合并检查点：`2d7e2ab8ce7e9e2bd9782c2c280a33d97735c908`
- 最终提交：本报告所在 GitHub PR head（报告不内嵌自引用提交 SHA，以远端 PR 回读为准）
- 最终成熟度：`integration_pass`
- 最终审查结论：`VERDICT: PASS` / `PROCESS_STATUS: READY`

## 1. 执行摘要

本轮工作的目标不是做一次“看过代码”的静态报告，而是完成以下闭环：

1. 在不污染原始脏工作区的前提下，把当前可纳入版本控制的功能修改合并到独立工作树。
2. 以真实功能链路为主线审查：已验证 OTA 数据 → 收益分析 → AI 判断 → 运营管理。
3. 对能够用代码、测试或一次性本地数据库证明的问题直接修复。
4. 用独立只读 reviewer 复核主要业务域和最终集成结果。
5. 在代码冻结后执行完整 PHP、Node、前端入口、业务规则、OTA 报告和数据库迁移行为验证。
6. 把代码、测试和本报告保存到 GitHub 的独立分支与 Draft PR；不部署、不触发真实 OTA 写入、不迁移生产数据库。

最终冻结快照没有开放 P0/P1。完整 PHPUnit `4,672/4,672`、Node `1,709/1,709`、P0 守卫、前端入口、业务页面合同、OTA 竞争报告和一次性 MariaDB 迁移行为均已通过；审查发现的主要 P1/P2 功能问题已完成最小修复。当前只有明确且非阻断的 P2 架构余量债务。不能外推为生产完成：真实酒店、真实 OTA 账号、真实 DeepSeek、真实生产数据库和部署后运行仍未验证。

## 2. 合并策略与工作区保护

### 2.1 为什么使用隔离工作树

原始 `HOTEL` 工作区在任务开始时已经包含大量用户和并行任务修改。直接在原目录执行全量暂存、提交或清理会产生三类风险：

- 把运行时数据、报告、浏览器采集资产或本地状态误提交到 GitHub；
- 覆盖用户尚未提交的工作，无法区分本轮修复与原有修改；
- 在前端构建、依赖目录和迁移脚本上产生不可复现的混合快照。

因此，本轮从冻结的 `origin/main` 建立独立工作树，只把符合源代码范围的修改汇入。原始工作区不执行 `reset`、`clean`、提交、推送或部署。

### 2.2 纳入、排除与冲突处理

纳入范围：应用源代码、路由、受控前端源码与生成产物、数据库迁移、部署模板、规则、hooks、测试、验证脚本和文档。

明确排除：

- `storage/`、`runtime/`、`reports/`、`output/`、`test-results/`；
- 浏览器 Profile、Cookie、localStorage、令牌、原始账号响应或敏感 HAR；
- `node_modules/`、`vendor/` 等本机依赖目录；
- 原始 OTA 捕获大文件、备份、压缩包和本地自动化状态；
- 任何真实平台写入、真实审批、真实酒店状态变更或生产发布。

本轮合并审计的关键事实：

- 合并约 185 个符合范围的源代码路径；
- 处理 23 个路径级冲突；
- 最终未保留未解决 Git 冲突；
- 有意拒绝 `app/service/OperationActionAiReviewService.php`：该服务未接入真实调用链，却包含把 AI 结果推进为审批的危险语义，违背“审批必须由用户主动触发”的产品边界；
- 不使用 `git add .` 或 `git add -A`，最终只会显式暂存经检查的路径。

### 2.3 原始工作区保护证据

任务开始时原始工作区：

- 分支：`agent/fix-daily-review-gates`
- HEAD：`8200d04dadb99d34d5bedb7bee1a5cfa55c10ae6`

最终提交前会再次读取原始分支、HEAD 和状态，确认本轮 Git 操作只发生在隔离工作树。

## 3. 审查方法与证据等级

本轮按四层证据分开结论，避免把低层证明写成生产完成：

| 等级 | 本轮含义 | 可以证明 | 不能证明 |
|---|---|---|---|
| 静态合同 | 源码、schema、规则、lint、确定性脚本 | 接口形状、权限分类、失败状态、引用路径、迁移分类 | 真实数据库版本、真实并发、真实账号返回 |
| 本地功能 | PHPUnit、Node、一次性 MariaDB、隔离 E2E | 本地请求、保存、精确回读、页面交互、失败关闭 | 云环境、生产定时器、真实 OTA/模型结果 |
| `integration_pass` | 全量回归与主要链路同时通过 | 当前集成快照具备继续发布验收的条件 | 生产部署和现场经营结果 |
| `field_validated` | 同酒店、同日期、真实账号、正式保存与回读 | 真实业务闭环 | 本轮未达到 |

审查顺序遵循：复现或构造反例 → 定位根因 → 最小修改 → 定向验证 → 全量回归 → 独立只读复核。

## 4. 重大功能与稳定性发现

### F-01 — P1 — Manager follow-up 并发写入可能基于过期案例覆盖新状态

#### 根因

旧路径在事务外读取案例投影，请求进入事务后只锁原始案例行，却继续使用之前的可变投影。如果另一个请求在两者之间完成纠错、作废、恢复或复核，后到请求可能基于过期事实生成 follow-up，甚至覆盖更新后的状态。

#### 业务影响

- 经理能力评分和待跟进列表可能与最新纠错状态不一致；
- 同一个案例的两个合法请求可能出现“后写覆盖正确结果”；
- 幂等重放与数据库死锁/序列化失败可能被混淆。

#### 修复

- 保存可变案例摘要，在事务内加锁后重新读取完整有效投影；
- 使用摘要 CAS 判断事务外读取是否已漂移；
- follow-up、adjustment、review 三条写路径统一应用同类校验；
- 事件时间升级为 `DATETIME(6)`，减少同秒事件排序歧义；
- 对 duplicate、deadlock、lock wait、serialization failure 做有界重试，并精确回读并发胜者；
- 达到重试上限后保留真实数据库异常，不伪装成幂等成功。

#### 验证

- 定向 PHPUnit 覆盖死锁不被误认成 replay、有限重试、并发胜者回读；
- Manager profile 和 follow-up 合同通过；
- 尚未执行两个真实生产连接通过 barrier 同时写同一案例的现场测试，因此结论仍是集成级。

### F-02 — P1 — 促销增量使用原始间夜 DID，组规模不一致时可能反转结论

#### 根因

旧公式直接计算两组原始间夜差分：`(参与后-参与前) - (对照后-对照前)`，没有用可售间夜 exposure 归一化。参与组和对照组规模不同时，绝对增量会把组规模误当促销效果。

典型反例：参与组 `100→120`，对照组 `50→60`。两组都增长 20%，归一化效果应为 0；旧公式却返回 10，可能产生错误的正增量结论。

#### 修复

- 升级为 `promotion_incrementality.v2`；
- 要求参与组和对照组前后四个可售间夜 exposure；
- 先计算每组转化/占用率变化，再执行 exposure-normalized DID；
- 返回组内率、增量率和 exposure assessment；
- 缺少 exposure 时明确 `indeterminate`，不回退到旧公式；
- 明确说明估计器不自动等同因果证明。

#### 验证

- 覆盖“不同规模、相同增长率、增量为 0”；
- 覆盖旧输入没有 exposure 时失败关闭；
- 页面增加四个必填 exposure 字段及解释。

### F-03 — P1 — 云端酒店 ID `5→80` 迁移最初不能证明完整、正确和可回滚判断

#### 根因

最初的迁移脚本依赖手写列名和部分表名，无法完整区分：

- 宿析OS 本地 `system_hotel_id`；
- 携程、美团、PMS 等外部平台 `hotel_id`；
- 活跃配置 JSON 内嵌的本地酒店 ID；
- 不可变历史证据中的旧 ID；
- 只读派生列；
- 运行中采集器、systemd 环境和数据库身份之间的漂移。

此外，最初只更新关系列，不能证明：

- `system_configs.config_value` 的活跃 JSON 已迁移；
- `platform_data_sources.config_json` 的本地 collector ID 已迁移，而外部 `platform_hotel_id` 保持不变；
- 命名锁在 commit 后独立连接审计结束前仍然有效；
- 未登记的旧 ID 引用会阻断而不是被遗漏；
- `hotels.id` 更新到 80 后 `AUTO_INCREMENT` 不会在未来撞 80。

#### 业务影响

这类遗漏会造成最危险的数据问题之一：代码表面显示“酒店 80”，但配置、采集器或部分事实仍指向 5；或者把外部平台酒店 ID 误改成 80，直接串错来源。若主键序列未越过 80，未来创建酒店还可能发生主键冲突。

#### 修复

- 从数据库 schema 关系和明确策略构建正向/负向酒店 ID registry，而不是按模糊命名猜测；
- 当前登记 122 个本地系统酒店列、14 个外部 ID 负向列、1 个只读派生本地酒店列；
- 关系列只更新登记的正向列，外部/provider/platform ID 明确保持不变；
- 可选表只有在 schema 中真实存在且通过分类时才更新；
- `hotels.id` 最后更新；
- 使用 dedicated connection 持有命名锁，覆盖事务、commit 和 post-commit 独立连接审计；
- 事务使用 serializable 语义，执行前后都检查身份、from/to 冲突、列计数和精确回读；
- `ALL_WRITERS_PAUSED` 仅作为操作者声明，不再伪装成系统已证明所有 writer 停止；
- Dingdandao runtime env、timer/service 停止状态、systemd 示例和安装脚本都进入目标 ID 漂移门禁；
- 对 `system_configs.config_value` 和 `platform_data_sources.config_json` 使用表级 JSON 策略：只变更明确的本地 ID key，保留值类型，并保持 `platform_hotel_id` 等外部 ID 不变；
- 活跃配置使用“行身份 + 旧原始字节”CAS 更新与精确回读；回执只记录摘要，不泄露完整配置 JSON；
- 不可变历史证据不改写，只记录旧 ID 匹配行的多重集摘要，并在迁移后证明原始字节与摘要未变化；
- 未登记、非历史 JSON 引用旧 ID 时失败关闭；目标 ID 已存在于可变配置时失败关闭，避免重复绑定；
- 一次性 MariaDB 行为测试覆盖关系列、活跃 JSON、外部 ID、不明引用、目标冲突、不可变历史、可选表和主键自增序列。

#### 仍然受限

本轮不会连接或修改真实 `hotelx_cloud`，不会停止真实定时器，也不会执行生产迁移。一次性 MariaDB 只能证明脚本在本机兼容数据库上的行为，不证明生产表规模、锁时长、版本差异和现场 writer 已经停止。

### F-04 — P1 — 携程公开资料与市场竞争页错误依赖“已配置实时采集”

#### 根因

公开资料和市场竞争页使用了普通携程采集页的酒店选项集合。该集合只包含已经配置携程实时采集的酒店，导致用户即使有酒店权限、已有人工导入或公开事实，也无法在这两个页面选择酒店。

#### 修复

- 只在 `ctrip-public-profiles` 和 `ctrip-market-competition` 两个页面使用完整的授权酒店集合；
- 普通携程采集标签仍维持“必须配置实时采集”的严格集合；
- 统一页面选择、存储上下文和 disabled 条件，避免显示有酒店但控件仍禁用；
- 更新源码模板后重新生成受控前端与压缩产物。

#### 验证

- 携程公开资料 Node 契约 `8/8`；
- 一次隔离 E2E 完成公开美团证据保存、精确回读、待审批意图、计划调整、拒绝和重试意图；
- E2E 后测试数据清理为 0。

### F-05 — P1 — 运营动作按钮在后台刷新期间可见可点，但点击会被静默丢弃

#### 根因

用户完成计划调整后，页面先结束一个请求，再继续刷新动作状态。旧按钮只看局部 loading 标记；在动作列表仍刷新时按钮可能恢复可点，但 handler 会因为全局 `operationLoading.actions` 直接返回，造成“看起来点了，实际什么都没发生”。

#### 修复

- 审批和拒绝按钮同时绑定动作刷新状态；
- 后台动作刷新结束前控件保持 disabled；
- 保留服务端 `pending_approval` 和当前卡片摘要校验，不以 UI 禁用代替后端保护。

#### 验证

- operation frontend closure `16/16`；
- 隔离 E2E 的 reschedule → reject → retry 完整通过。

### F-06 — P2 — 经营机会页面原来形成“只能保存缺口”的功能死端

#### 根因

页面把人工输入正确标记为 `manual_unverified`，四个正式计算器又正确拒绝把未验证输入升级为正式事实。但二者叠加后，用户无论输入什么都只能得到 blocked/indeterminate，无法得到可核对的中间结果。

#### 修复

- 为人工输入生成 `provisional_manual_estimate`；
- 只展示用户能够自行复算的指标；
- 强制 `metric_provenance=manual_estimate`、`formal_conclusion=null`、`decision_eligible=false`、`can_execute=false`；
- 页面明确提示“人工输入估算，仅供核对；不形成正式结论，也不能执行经营动作”。

这保留了数据真实性，同时让功能真正可用，而不是用虚假的“正式结论”换取页面有数字。

### F-07 — P2 — 经营机会精确回读没有重算 JSON，也没有核对顶层元数据

#### 根因

旧回读只比较数据库摘要列，没有重新 canonicalize `input_json/result_json`，也没有完整比较 tenant、hotel、feature、business date、source quality、source reference 和 created_by。JSON 被截断、损坏或顶层范围漂移时仍可能返回 `readback_verified=true`。

#### 修复

- 对关联数组稳定排序后生成 canonical JSON；
- 回读时重新计算 input/result digest；
- 同时比较计算值、数据库摘要和本次保存的预期摘要；
- 精确比较全部业务范围元数据；
- 损坏或标量 JSON 直接失败，不降级为空数组。

### F-08 — P2 — Manager profile/queue 先截断 1000 条再投影，可能静默漏案例

#### 根因

旧查询先截取 1000 条原始案例，再应用 append-only adjustment 投影和日期过滤。超过 1000 条时，纠正后进入当前日期窗的老案例或排在后面的 overdue 案例会被漏掉，但接口仍表现为完整结果。

#### 修复

- 使用固定最大 case ID 边界的 keyset scan；
- 每页 250，先形成有效投影再执行日期过滤；
- 最多扫描 20,000 条；
- 未完成时显式返回 `data_status=partial`、`profile_status=data_incomplete`、`overall_score=null` 和 `case_scan_incomplete`；
- 不把子集伪装成完整评分。

#### 验证

- 覆盖 1001 条跨页完整扫描；
- 覆盖达到上限后的 partial metadata；
- 覆盖 adjustment 修改业务日期后再过滤。

### F-09 — P1/P2 — OTA competition 证据引用无效，未知技术异常被伪装成采集失败

#### 根因

报告和建议曾引用不存在的 `competition_circle_bundle.platforms.*` 对象；同时构建器捕获所有 `Throwable` 并统一转换成 `collection_failed`，使 TypeError、schema 错误等程序故障看起来像普通缺数。

#### 修复

- 所有引用改成相对 bundle root 的 RFC 6901 JSON Pointer；
- verifier 实际解析每个 section 和 recommendation 引用，要求落到本次返回对象；
- Ctrip 读取要求返回数组；未知技术异常直接传播；
- 正常缺数继续由明确的 `data_missing/unverified` 状态表达；
- synthetic 数据不能产生真实动作，blocked 情况必须 withheld。

### F-10 — P2 — 经营机会 API 未进入统一 ProtectedCapability 分类

#### 根因

Controller 自身已有酒店与权限检查，但统一 capability 分类返回 null，导致 Auth 的一致授权、摘要裁剪和受保护模块审计语义绕过该新业务域。

#### 修复

- GET 读取分类为 `operation_decision`，要求 `operation.view`；
- evaluate/priority 写入分类为 `operation_execution`，要求 `operation.execute`；
- 未知 POST 不使用宽泛路径误匹配；
- 摘要裁剪后仍保留页面需要的 provisional 指标。

### F-11 — P2 — 经营机会输入没有容量边界

#### 根因与影响

大量 observations、references 或超长字符串会触发同步遍历、重复 JSON 编码/摘要和 LONGTEXT 写入。即使 HTTP 层最终限制 body，也会产生不必要的延迟与存储膨胀。

#### 修复

- 输入 JSON 最大 256 KB；
- observations 最大 100 条；
- references 最大 50 条；
- 单个字符串最大 1000 字符；
- 在 schema 查询和数据库保存前先拒绝超限输入。

### F-12 — P1 — DeepSeek V4 Pro 证明与不确定重试必须严格

#### 审查结论

- V4 Pro 使用 8192 token、60 秒超时；
- transport 层不自动重试可能已经到达模型的请求，避免同一个经营审批证据被重复生成；
- direct proof 同时要求 configured model 与实际返回 model 都是 `deepseek-v4-pro`；
- 所有受支持的 Pro alias 使用相同策略；
- Revenue AI 待审批使用事实层酒店的 tenant，不直接相信操作者传入 tenant；
- 本轮没有真实调用 DeepSeek，因而只证明请求和元数据合同，不证明供应商现场可用。

### F-13 — P1/P2 — Cloud browser gateway 存在重复会话、容量和 profile 范围竞态

#### 修复范围

- 对同 profile/酒店会话做重复与竞态保护；
- 容量判断与创建路径保持一致；
- profile scope 不跨酒店复用；
- CDP 只允许 loopback 目标；
- 对创建失败和已有会话回读保持明确状态，不用 fallback 隐藏失败。

这些验证是本地 contract 级；没有重新连接真实云端浏览器或真实 OTA 账号。

### F-14 — P2 — AI 日报旗舰版与轻量版输出合同可能混淆

#### 修复

- exporter 严格绑定 report、bundle 和 fingerprint；
- 旗舰版保留完整详情，轻量版只输出 top 3 与 gaps；
- 版本、数据范围和证据引用不允许跨报告复用；
- 前端静态合同和 OTA competition verifier 同时覆盖。

### F-15 — P2 — 前端合法演进后，部分 E2E 仍断言旧启动与执行语义

#### 修复

- operating-question floating spec 改为验证认证 bridge ready，不要求初始阶段已渲染完整业务结果；
- business-chains spec 使用 `manual_execution` 前后状态作为执行证据，不再把财务收入/成本数字当成动作已执行；
- 只更新陈旧测试合同，没有降低权限、事实来源或审批门禁。

### F-16 — P2 — 新经营机会页面缺少显式视觉覆盖声明

#### 根因

页面、路由、业务合同和功能测试已经接入，但 `public/style.css` 的登录后页面主题覆盖清单没有登记 `operating-opportunities`。全量功能测试不会修改 Git 索引，因此未触发这一 staged-only 提交合同；真正提交时门禁正确阻断。

#### 修复

- 将经营机会页加入现有运营域绿色主题组；
- 从 `public/style.css` 受控生成 authenticated/full CSS 与入口版本；
- 运行 `verify:taste-coverage`、视觉 smoke、公开入口和索引级提交钩子；
- 不为通过门禁增加空白例外，也不把该页排除出覆盖集合。

### F-17 — P2 — 视觉 smoke 把兼容别名当成独立页面，模拟响应形状落后于前端合同

#### 根因

`ai-workbench` 已明确归一化为 `compass`，但视觉 smoke 仍要求激活页等于旧别名；同时 `/api/online-data/manual-fetch-evidence` 被通用空数组模拟，前端实际要求 `{ rows: [] }`，因此产生非产品 console error。首次运行还复用了 8080 上的原工作区实例，不能作为隔离分支的最终页面证据。

#### 修复

- 为视觉状态显式声明兼容别名的 canonical 预期，继续验证别名会落到 `compass`；
- 为 manual-fetch-evidence 返回符合前端合同的空证据结构；
- 新增静态回归，固定别名映射和 mock 数据形状；
- 使用隔离工作树自己的 `public/index.html` 与临时 loopback 端口复测，`45` 个登录后页面键、`76` 个视觉状态全部通过；该证据仍明确为 mock，不提升为现场验收。

## 5. 审批和真实经营动作边界

本轮明确拒绝“AI 自动审批”路径。最终保留的闭环是：

```text
证据与建议
  -> 创建 pending_approval 意图
  -> 用户主动打开并核对卡片
  -> 服务端核对酒店/来源/日期/摘要仍为当前版本
  -> 用户主动批准或拒绝
  -> 任务与效果证据追加记录
```

关键规则：

- `operation.execute` 是写入与审批权限，`operation.view` 不能替代；
- `pending_approval` 不能由 AI、计时器、预览页或测试自动升级；
- approval card 必须验证当前 intent、当前 evidence 和 no-drift；
- 本轮没有自动执行 OTA 改价、房态、促销或外部发送；
- HTTP 200、登录成功、页面可见和本地 E2E 都不是生产动作完成证据。

## 6. 规则体系审查

### 6.1 当前规则中有效且应保留的部分

- 功能优先，但不能用伪造值、旧值或跨范围 fallback 让页面“看起来完成”；
- 酒店、tenant、平台、业务日期、来源和事实质量必须跟随数据进入分析与动作；
- OTA 事实只支持渠道分析，不扩大为全酒店经营事实；
- 缺失、失败、历史、缓存、预测和人工输入必须有不同状态；
- 用户审批是不可替代的外部授权边界；
- 前端修改从 source/template 产生，压缩产物通过受控构建生成；
- 脏工作区不是阻塞，但必须隔离并保护用户改动；
- 本地、集成、真实账号、现场和生产证据必须分层。

### 6.2 本轮规则工程改进

- 新增 business-page contract 和 registry，把酒店范围、来源、日期、权限、保存与失败状态变成可执行规则；
- 新增 verifier，当前覆盖 87 条页面合同；
- context assets gate 验证规则、文档和实现引用没有漂移；
- CI 增加经营问题 E2E 合同；
- 规则保持业务页面级，不强制所有简单页面套入重流程；
- 不把文档声明当作运行证明，关键边界由测试或脚本执行。

### 6.3 仍需长期控制的规则风险

- registry 越来越大时，要避免出现“为了通过规则而复制文案”的形式主义；
- 新增接口必须同时登记读/写权限，不应只依赖 Controller 内零散判断；
- schema/迁移和规则 registry 需要同 PR 更新，否则酒店 ID、来源字段和能力分类容易漂移；
- 规则应 fail closed，但不应把所有业务缺数都升级成系统故障；要继续区分 `blocked`、`partial`、`unverified` 和 technical error。

## 7. 架构审查

### 7.1 当前可接受的主链

```text
携程 / 美团来源事实
  -> tenant + system_hotel_id + platform + business_date + source + quality
  -> 渠道与收益分析
  -> 带证据的 AI 建议
  -> pending_approval
  -> 用户主动经营动作
  -> append-only 证据与效果复核
  -> 投资判断（仅在上游事实足够时）
```

正向评价：

- 事实层、建议层、审批层和动作层已经有清晰边界；
- 关键写路径正在从“返回成功”转向“保存 + 精确回读 + 摘要匹配”；
- append-only adjustment/review/lifecycle 比覆盖式更新更适合审计经营动作；
- ProtectedCapability 提供统一读写能力语义；
- 对人工输入提供可用的 provisional 输出，同时不升级成正式事实；
- 关键失败状态能进入 UI，而不是用 0 或空数组遮蔽。

### 7.2 主要架构债务

#### A. 前端启动热点

`public/app-main.js` 仍有 55,806 行，已贴住 ratchet，长期目标债务 5,872 行。它同时承担路由、状态、权限、数据请求、页面 glue 和大量业务 helper；任何小功能都可能进入启动链并推高 gzip。

建议后续按行为保持的小边界拆分：

- 把 `operatingQuestionActionIsCurrent()` 等纯合同校验移到现有静态模块；
- 页面只在进入对应标签后加载业务模块；
- 不提高 620,000 B 目标，优先 split/defer。

#### B. Revenue AI overview 过大

`RevenueAiOverviewService.php` 约 6,386 行，长期目标 4,800，ratchet 余量为 0。价格建议 review queue、格式化与证据 helper 已形成独立业务域，适合后续抽成 `RevenueAiPricingReviewService`。

#### C. OTA diagnosis concern 边界不纯

- `AgentOtaDiagnosisBuildConcern.php` 约 2,453 行，长期目标 1,500；
- `AgentOtaDiagnosisPersistenceConcern.php` 约 2,399 行，长期目标 1,750。

前者混入服务质量 eligibility 和动作规则，后者混入 no-action 展示投影与 coverage 分类。后续应抽出纯 policy/service，让 concern 只负责编排与持久化。

#### D. 特殊迁移脚本复杂度高

酒店 ID 迁移必须同时处理关系字段、活跃 JSON、历史摘要、运行时 env、writer 停止和主键序列，天然复杂。当前以一次性脚本、registry、inspector 和行为测试把风险显式化是合理的；不建议把它包装成日常后台按钮。生产执行应继续要求人工窗口、备份、writer 停止、预检和 post-commit 审计。

### 7.3 不建议本轮进行的重构

- 不进行全仓 repository/service 重写；
- 不把所有 Controller 迁移到新框架；
- 不为了“架构漂亮”改动稳定的 OTA 捕获协议；
- 不提高热点或 gzip 预算掩盖增长；
- 不把生产治理、分布式锁、全链路观测作为本轮功能交付前置条件。

## 8. 前端构建与性能

最终受控构建使用 template snapshot 与 frontend build 生成 `public/app-main.js`、minified assets 和入口引用；没有直接手改 minified 产物。

代码冻结前的最新预算结果：

| 指标 | 结果 |
|---|---:|
| `startup_gzip_bytes` | 618,766 B |
| 目标 | 620,000 B |
| 目标余量 | 1,234 B |
| hard limit | 650,000 B |
| hard-limit 余量 | 31,234 B |
| 状态 | `within_target` |

结论：当前通过，但余量很薄。任何进入启动链的新逻辑都可能再次越过目标。后续应 defer/split，不应把预算提高到适配代码增长。

## 9. 数据库与迁移审查

### 9.1 业务功能迁移

经理能力评分、follow-up、adjustment/review 和经营机会运行表具备：

- 明确的唯一幂等键；
- append-only 纠错/复核记录；
- `DATETIME(6)` 事件顺序；
- 保存后精确回读；
- 旧数据兼容和 partial 状态。

### 9.2 云酒店 ID 一次性迁移

正式执行前必须满足：

- 数据库身份、源 ID=5、目标 ID=80 与预期完全一致；
- 目标 ID 在所有可变关系和活跃配置中不存在；
- schema 中所有疑似酒店 ID 列都已被正向、负向、派生或未知分类；
- 所有未知非历史引用都为 0；
- Dingdandao collector/runtime env 指向 80；
- timer/service 确认停止；
- 操作者明确声明所有 writer 已暂停；
- dedicated connection 获得命名锁；
- 事务和 post-commit 独立连接审计通过；
- `hotels.AUTO_INCREMENT > 80`；
- 回执只含计数和摘要，不含原始敏感 JSON。

### 9.3 生产迁移仍未验证

本轮不会证明：

- 生产 MariaDB/MySQL 精确版本兼容；
- 大表 UPDATE 的锁时长和复制延迟；
- 所有外部 writer 确实停止；
- systemd 实际部署文件已更新；
- 生产备份可恢复；
- 正式迁移后的真实采集和 UI 回读。

因此，脚本通过只能标记为“迁移实现与本地行为合同通过”，不能标记为“酒店 80 已在生产迁移完成”。

## 10. 安全审查（功能之后的最低必要检查）

本轮没有把泛化安全加固放在功能之前，也没有开展完整渗透测试。只检查会直接中断交付的高风险边界：

- 未发现新增明文密码、token、Cookie 或浏览器 Profile 进入版本控制；
- 未发现新增跨酒店/跨 tenant 无鉴权读写；
- 未发现未鉴权公网写入口；
- 未执行不可逆真实数据删除；
- Cloud browser CDP 目标限制为 loopback；
- 外部动作保持人工审批；
- 迁移回执不输出活跃配置原文。

这些结论不是“系统安全完成”。未覆盖完整依赖漏洞、主机加固、WAF、SAST/DAST、供应链、权限矩阵全量证明、云账号配置和生产渗透测试。

## 11. 验证矩阵

### 11.1 已完成的定向验证

| 范围 | 结果 |
|---|---|
| 携程公开资料前端合同 | `8/8` 通过 |
| 运营动作前端闭环 | `16/16` 通过 |
| 公开页面隔离 E2E | `1/1` 通过；保存/回读/待审批/调整/拒绝/重试完整，清理后计数 0 |
| 经营问题隔离 E2E | `7/7` 通过，清理后计数 0 |
| 快速业务隔离 E2E | `5/5` 通过，清理后计数 0 |
| business-page contract | `87/87` 通过 |
| context assets | 通过 |
| source hotspot budget | 通过 |
| public entry | 通过 |
| taste page coverage | `45/45` 页面键通过 |
| taste visual smoke | 隔离入口 mock：`45` 个页面键、`76` 个视觉状态通过 |
| 云酒店 ID 静态/兼容合同 | `35/35` 通过，其中迁移核心合同 `22/22` |
| 云酒店 ID MariaDB 行为 | `17/17` 通过；`cleanup_remaining=0`、临时数据库残留 0、命名锁已释放 |

### 11.2 代码冻结后的最终验证

| 验证 | 最终结果 |
|---|---|
| PHPUnit 全量 | `4,672/4,672` 通过；`42,966 assertions`；`skipped 1` |
| Node automation 全量 | `1,710/1,710` 通过；0 fail / 0 skipped |
| `verify:p0-guards` | 通过；含 business page `87/87`、Revenue AI `51/51`、closure `1,767 checks`、E2E static contract `2,286 checks` |
| `verify:public-entry` | 通过；`startup_gzip_bytes=618,766`，目标余量 `1,234 B` |
| `verify:taste-coverage` / `verify:taste-visual` | `45/45` 页面键；隔离入口 mock `45` 个页面键、`76` 个视觉状态通过 |
| `verify:source-hotspot-budget` | 通过；`failures=[]`，已知债务继续受 ratchet 约束 |
| `verify:business-page-contract` | `87/87` 通过 |
| `verify:context-assets` | 通过 |
| `verify:ota-competition-report` | Python `9/9` + PHP bundle + report contract 全部通过；synthetic 动作 0、live review 动作 2、blocked withheld |
| PHP lint（迁移脚本） | `4/4` 通过 |
| `git diff --check` / 冲突标记 | 通过；无未解决状态、无冲突标记 |
| 独立 reviewer | `VERDICT: PASS` / `PROCESS_STATUS: READY` / `MATURITY: integration_pass` |

## 12. 剩余风险与明确阻塞

以下项目没有被本地测试替代，仍需后续现场验收：

1. 真实酒店、tenant、平台、Profile、业务日期的携程/美团采集。
2. 同店同日的 PMS → 携程 → 美团正式保存与精确回读。
3. 真实 DeepSeek V4 Pro provider 返回和模型身份检查。
4. 真实用户在原设备主动批准经营动作。
5. 真实 OTA 改价、房态、促销、消息发送及效果回看。
6. 生产数据库迁移窗口、备份恢复、writer 停止、锁时长和 post-commit 验收。
7. GitHub CI 最终完成状态，以及后续部署环境健康与业务页面验收。
8. 跨浏览器、长时间运行、负载、故障恢复和多实例一致性。

## 13. 最终判断

最终冻结快照没有开放 P0/P1 功能、稳定性、兼容性、权限、跨酒店或数据库迁移阻断。审查发现的并发旧投影、促销公式、经营机会死端/回读、OTA 证据引用、公开资料酒店选择、运营按钮竞态、云酒店 ID 迁移、视觉 smoke 隔离及陈旧测试合同均已修复并获得对应回归。

当前保留两类非阻断 P2：多个热点源文件贴住零增长 ratchet；启动 gzip 距 620,000 B 目标仅余 1,234 B。后续应通过按业务域抽取和 split/defer 缓解，不应提高预算。

本轮可准确标记为 `integration_pass`，具备保存到 GitHub Draft PR 并进入现场验收的条件；不具备 `field_validated` 或生产完成证据。

准确的完成边界应是：代码与主要本地业务链达到 `integration_pass` 后，保存到 GitHub Draft PR，等待真实账号、生产迁移和部署验收。不能把本报告、全量测试、HTTP 200 或本地 E2E 写成 `field_validated` 或生产完成。
