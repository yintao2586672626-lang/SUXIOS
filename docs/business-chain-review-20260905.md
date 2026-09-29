# 经营事实、日报与问答回读审查 — 2026-09-05

## 结果与范围

本轮沿已有“已保存 OTA 事实 → 日报 → 经营问答 → 待人工审批任务”入口做定向审查，修复三处已复现问题。不是全项目审计、真实采集验收或生产交付。

活动工作树 `.active-worktrees/HOTEL-clean-20260901`；当前分支 `codex/save-all-20260903`，HEAD `9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b`，上游同名分支。本地 origin/main 为 `7cec7c2cb22694457652e3b3e0f9771eed15704e`（未 fetch）。GitHub 只读查询显示 PR #43 OPEN、目标 main；本轮修改未进入该 PR。

原有未提交改动全部保留。以下五个目标源码/测试文件在本轮写入前无 Git 改动；冻结 HOTEL 未修改。

| 问题 | 修改与用户结果 | 状态 |
| --- | --- | --- |
| 日报在没有合格候选时回退到不合格数值，门店身份未核验也可能写成已确认事实 | 字段必须具备 canonical 字段级可用资格及来源引用。删除不合格回退；合格的单平台、部分指标仍可播报 | 已修改，聚焦通过 |
| 明细已有缺失项，正文仍可能写未发现关键事实缺口 | 正文在专用提示未命中时使用实际 missing_items；保留缺失状态，不补零 | 已修改，聚焦通过 |
| 单条经营问答校验内容，但历史列表仅解析 JSON，损坏的日期、状态等仍作为已保存记录返回 | 列表复用精确回读校验和质量回执；所选范围内存在不一致记录时明确失败，不静默过滤成空列表；范围外坏记录不影响本范围 | 已修改，聚焦通过 |
| 经营问答转待人工审批任务 | 检查已有证据、范围及人工确认门，并运行已有回归；未发现本轮需要改动的复现问题 | 已检查，无修改；不代表线上全部无缺陷 |

## 本轮文件

- `app/service/AiDailyReportBroadcastSnapshotService.php`
- `app/service/OperatingQuestionService.php`
- `tests/AiDailyReportBroadcastSnapshotServiceTest.php`
- `tests/DualOtaFieldClosureServiceTest.php`
- `tests/OperatingQuestionReadbackIntegrityTest.php`
- 本交付记录。

字段资格复用 `DualOtaFieldClosureService`：本字段状态和 strictFinal、身份核验、本字段口径共同决定可用性。其他指标缺失只影响平台分析准备状态，不否决已经合格的字段。已有 immutable 日报不改写；新正文已纳入事实指纹，不会误复用正文不同的旧快照。

## 验证证据

Windows 使用 `C:/xampp/php/php.exe vendor/bin/phpunit --colors=never`。测试使用 fixture、内存或临时 SQLite；未把这些数据写入本机经营库。

| 检查 | 结果与边界 |
| --- | --- |
| 问答历史新增回归，修复前 | 12 tests / 68 assertions，6 failures：列表缺少精确回读结果，以及摘要、JSON、来源引用、平台、状态损坏未被拒绝 |
| `tests/OperatingQuestionReadbackIntegrityTest.php`，修复后 | PASS，12 tests / 73 assertions；含合法结果一致性及范围外坏记录隔离 |
| `tests/OperatingQuestionExecutionBridgeServiceTest.php tests/OperatingQuestionReadbackIntegrityTest.php`，审查基线 | PASS，28 tests / 328 assertions；这是修改前基线，后续问答修改只涉及 list，最终 list 证据见上一行 |
| `tests/OperatingIntelligenceServiceTest.php tests/OperatingQuestionToolCallingPersistenceTest.php` | PASS，29 tests / 570 assertions；直接依赖保存与回读 |
| `tests/AiDailyReportBroadcastSnapshotServiceTest.php` | PASS，6 tests / 36 assertions；日报身份/来源排除、保留合格部分、缺失正文 |
| `tests/AiDailyReportBroadcastFactServiceTest.php` | PASS，3 tests / 19 assertions；事实投影 |
| `tests/DualOtaFieldClosureServiceTest.php --filter testVerifiedRowsRemainNonConsumableWhenProfileOrStoreIdentityIsUnverified` | PASS，1 test / 10 assertions；原始测试行与未核身份 → canonical evaluator → FactService → 日报，不得形成事实正文 |
| `node --test tests/automation/ai_daily_report_trusted_broadcast.test.mjs` | PASS，6 tests；模拟请求下显示/复制/朗读同快照、刷新恢复、现有入口与存储合同。不是浏览器现场证明 |
| 修改服务 PHP 语法、目标文件 `git diff --check` | PASS |
| 本机 `GET /api/health` | PASS：status=ok；runtime_mode=development_fallback，production_runtime_ready=false；仅服务健康 |
| 实际登录后日报/历史操作 | BLOCKED：当前审查浏览器为登录页，无已用登录态；已有 Chrome 本机页由另一任务占用，未接管 |

## 未完成层与停止点

本地修改与聚焦验证已完成，Git 未提交，未推送、未部署；未执行真实 OTA/PMS 采集、经营审批、外发或真实 LLM 调用。未读取密码、Cookie、localStorage、Profile 或令牌。

现场下一步需用户先在本机原设备完成登录，然后绑定实际所选酒店/租户、平台、业务日期、来源编号与质量状态，验收日报和历史回读。当前登录页没有这些身份信息，因此没有自行指定门店或把历史 fixture 的 Hotel 80 当作现场目标。本轮停止于已修复的本地代码，不自动开展新的业务阶段。
