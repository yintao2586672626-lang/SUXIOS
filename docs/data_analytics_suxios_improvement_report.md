# Data Analytics 对宿析OS的提升作用与执行记录

生成日期：2026-06-06 Asia/Shanghai

## Executive Summary

- **Data Analytics 最直接的提升是把“能分析”升级为“可复用、可追溯、可验证”。** 本次已创建项目级语义层，未来再问 OTA 收益、AI 诊断、运营执行或投决问题时，会优先读取同一套指标口径、表选择、数据缺口和验证边界。
- **宿析OS当前最适合优先强化主链路，而不是横向扩功能。** 证据显示项目规则、指标文档、ETL、收益指标、根因诊断、执行闭环、AI治理和转让测算都围绕“OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策”展开。
- **可执行部分已经落地为上下文资产。** 本次没有改业务代码、数据库或 OTA 采集逻辑；已执行的是 Data Analytics 本地状态初始化、SUXIOS OTA 收益语义层创建、源清单/证据登记和本报告输出。
- **仍不能声称已具备实时经营洞察。** 本次未读取生产 MySQL、实时 OTA 后台、BI 仪表盘、团队沟通或外部公司文档；所有数值类结论未来仍必须走实时源读取或用户提供的已审核数据。

## 事实、假设、未知

| 类型 | 内容 |
| --- | --- |
| 事实 | 项目规则要求所有行为回到真实 OTA 数据 -> 收益分析 -> AI 决策 -> 运营管理 -> 投资决策。 |
| 事实 | 本地代码和测试已覆盖 OTA 标准化事实、收益指标、数据缺口、执行审批/证据/ROI、AI治理和转让测算的关键契约。 |
| 假设 | 当前请求中的“执行”优先指可安全执行的 Data Analytics 上下文搭建、报告整理和验证，不直接修改业务代码或上线数据。 |
| 未知 | 生产数据库当前行质量、OTA 最新页面字段、实际 MySQL 表状态、外部 BI/Drive/沟通记录是否存在且是否应作为更高优先级事实源。 |

## 全部提升作用清单

| 序号 | 提升作用 | 作用链路 | 当前可执行方式 | 本次状态 | 证据 |
| --- | --- | --- | --- | --- | --- |
| 1 | 建立可复用数据语义层 | 全链路 | 将指标、表、口径、缺口、验证规则沉淀为项目 Skill | 已执行 | `.agents/skills/suxi-ota-revenue-semantic-layer/` |
| 2 | 固定源优先级 | 全链路 | 先读本地权威 docs/code/tests，再读实时库/接口/导出 | 已执行 | `references/source-inventory.md` |
| 3 | 防止 OTA 口径冒充全店口径 | OTA -> 收益 -> 投决 | 所有未来回答必须标注 OTA-channel 或 whole-hotel | 已执行为规则 | `HOTEL/AGENTS.md`、`hotel_ota_metric_professional_knowledge.md` |
| 4 | 指标公式统一 | OTA -> 收益 | ADR、OCC、RevPAR、Net RevPAR、佣金率、取消率统一到标准事实口径 | 已沉淀 | `revenue_metric_standard_fact_table.md`、`OtaRevenueMetricService.php` |
| 5 | 缺字段不再被假 0 掩盖 | OTA -> 收益 -> AI | 分母缺失返回 `null`、`data_gaps`、`blocked_reason` | 已沉淀 | `OtaStandardModuleTest.php` |
| 6 | ETL 形成逻辑事实表 | OTA -> 收益 | `online_daily_data` 标准化为逻辑 `fact_ota_daily`、traffic、ads、quality 等 | 已沉淀 | `OtaStandardEtlService.php` |
| 7 | 数据质量可解释 | OTA -> 收益 | 保留 rejected rows、collection disabled、source trace、metric trust | 已沉淀 | `OtaStandardEtlService.php`、`OtaRevenueMetricService.php` |
| 8 | Ctrip 字段闭环更清晰 | OTA | response -> source path -> metric_key -> storage -> UI status -> verifier | 已纳入语义层 | `ctrip_table_build_plan_20260602.md`、`suxi-ctrip-field-table-closure` |
| 9 | 收益分析更可行动 | 收益 -> AI | 将 ADR、RevPAR、Net RevPAR、取消率、价差拆成诊断模块 | 已沉淀 | `OtaInsightAnalysisService.php` |
| 10 | 流量漏斗诊断更稳定 | OTA -> 收益 | 曝光、详情、填单、提交转化分开解释 | 已沉淀 | `hotel_ota_metric_professional_knowledge.md`、`OtaInsightAnalysisService.php` |
| 11 | 广告效率能进入收益诊断 | OTA -> 收益 | 消耗、归因订单额、ROAS、点击/预订纳入标准事实 | 已沉淀 | `OtaRevenueMetricService.php` |
| 12 | 服务质量能进入经营判断 | OTA -> 运营 | PSI/service score 进入 quality 模块，但不反推平台权重 | 已沉淀 | `hotel_ota_metric_professional_knowledge.md` |
| 13 | 根因定位可复用 | 收益 -> AI -> 运营 | 规则识别采集异常、曝光下降、转化低、价格高、服务低、节假日临近 | 已沉淀 | `OperationManagementService.php` |
| 14 | 预警从临时生成走向持久化 | 运营 | `operation_alerts` 存在时可持久化并按酒店权限标记已读 | 已识别为能力 | `OperationManagementService.php` |
| 15 | AI 建议不再停留在文本 | AI -> 运营 | 建议可转执行意图、审批、任务、证据、复盘 | 已沉淀 | `p0_decision_execution_closed_loop.md`、`OperationExecutionLoopTest.php` |
| 16 | 防止“未审批即执行” | 运营 | 未批准 intent 不能执行；blocked intent 不能审批 | 已沉淀 | `OperationExecutionLoopTest.php` |
| 17 | 防止“无证据执行成功” | 运营 | executed 状态必须带 evidence，否则 blocked | 已沉淀 | `OperationExecutionLoopTest.php` |
| 18 | 执行效果可量化复盘 | 运营 -> 投决 | execution flow 汇总 approval/execution/evidence/ROI rate、利润和瓶颈 | 已沉淀 | `OperationExecutionLoopTest.php` |
| 19 | 定价建议更安全 | 收益 -> AI -> 运营 | 价格建议保持 advisory-only，受 min/max、信号数量、数据缺口和人工复核约束 | 已沉淀 | `RevenuePricingRecommendationServiceTest.php` |
| 20 | AI 结论可追责 | AI -> 运营/投决 | 记录 prompt version、sources、confidence、human confirmation、evaluation set | 已沉淀 | `ai_governance_p2.md`、`LlmClientTest.php` |
| 21 | 投资/转让测算可接入经营数据 | 收益 -> 投决 | 资产定价、转让时机、看板合并风险点与数据异常 | 已沉淀 | `TransferDecisionService.php`、`TransferDecisionServiceTest.php` |
| 22 | 采集路径选择更稳 | OTA | 浏览器 Profile 登录态采集作为日常主线；手动 Cookie/API 与 CDP 仅用于临时补数、首次接入和排障分层 | 已沉淀 | `ota_acquisition_decision_playbook.md` |
| 23 | 报告输出更稳定 | 全链路 | 每次分析可先读语义层，再生成口径一致的 Markdown/报告 | 本次已产出 | 本文件 |
| 24 | 后续测试范围更明确 | 全链路 | 聚焦 ETL、收益指标、执行闭环、AI治理、投决服务最小验证集 | 已验证 | 本报告“验证结果” |

## 本次已执行

| 执行动作 | 结果 |
| --- | --- |
| 初始化 Data Analytics 本地上下文状态 | 已创建 `C:/Users/Administrator/.codex/state/plugins/data-analytics/user-context.md` 与 `onboarding-state.json` |
| 创建 SUXIOS OTA 收益语义层 | 已创建 `HOTEL/.agents/skills/suxi-ota-revenue-semantic-layer/SKILL.md` |
| 写入源清单 | 已创建 `references/source-inventory.md`，记录 15 个本地证据源和外部缺口 |
| 写入语义规则 | 已创建 `references/semantic-layer.md`，覆盖指标、表、过滤维度、查询模式、风险点 |
| 写入证据登记 | 已创建 `references/evidence.md`，记录关键事实对应来源 |
| 生成提升作用报告 | 已创建本文件 |

## 未执行与原因

| 项目 | 原因 | 后续最小动作 |
| --- | --- | --- |
| 读取生产 MySQL / 实时 `online_daily_data` | 当前任务是上下文搭建与本地源分析；未启动本地 MySQL/PHP 服务 | 用户要求具体数值分析时，先启动 MySQL 并验证 `/api/health` |
| 读取实时携程/美团后台 | 涉及授权、登录态、验证码和敏感 OTA 数据，不应静默执行 | 只在用户明确授权并提供合法登录路径时按项目采集规则执行 |
| 安装或连接外部数据仓库/Slack/BI | 当前会话未暴露 Databricks/BigQuery/Snowflake/Slack/BI 连接 | 用户或管理员启用对应连接，或提供导出/SQL/截图摘要 |
| 修改业务代码/数据库结构/UI | 本次目标可通过上下文资产和报告执行，直接改代码会扩大范围 | 后续按具体 P0/P1 项逐项补码 |
| 周期性语义层刷新 | 需要用户确认自动化 | 用户回复 `set up weekly refresh` 后再建自动化 |

## 推荐执行顺序

1. **P0：用语义层驱动未来所有 Data Analytics 问答。** 任何 OTA 收益、AI诊断、运营闭环或投决分析先读取 `suxi-ota-revenue-semantic-layer`。
2. **P0：做一次实时数据验证。** 启动本地 MySQL/PHP 后，用 `/api/ota-standard/revenue-metrics` 或相关服务读取真实 `online_daily_data`，验证数据缺口是否与文档一致。
3. **P1：把“建议到执行闭环”作为主线继续补强。** 优先验证 execution intents/tasks/evidence 在当前数据库中的表状态、接口路由和前端入口。
4. **P1：补齐 AI 批量评估运行器。** 当前治理已有日志、确认和评估集存储，但批量回放与评分仍是明确缺口。
5. **P2：连接外部权威源。** 将生产数据库、BI、Drive 文档或团队沟通纳入源清单后，刷新语义层。

## 验证计划

| 验证项 | 命令 | 结果 |
| --- | --- | --- |
| 上下文资产结构 | `npm.cmd run verify:context-assets` | 通过 |
| OTA 标准事实与收益指标 | `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaStandardModuleTest.php` | 通过 |
| 建议到执行闭环 | `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OperationExecutionLoopTest.php` | 通过 |
| 定价建议 | `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/RevenuePricingRecommendationServiceTest.php` | 通过 |
| 投资/转让测算 | `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/TransferDecisionServiceTest.php` | 通过 |
| AI 治理 | `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/LlmClientTest.php tests/AiModelCallLogTest.php` | 通过 |

## 验证结果

- `npm.cmd run verify:context-assets`：通过。
- `C:\xampp\php\php.exe vendor\bin\phpunit --colors=never tests/OtaStandardModuleTest.php tests/OperationExecutionLoopTest.php tests/RevenuePricingRecommendationServiceTest.php tests/TransferDecisionServiceTest.php tests/LlmClientTest.php tests/AiModelCallLogTest.php`：52 tests, 468 assertions, OK。
- Data Analytics setup readback：已识别 1 个语义层，核心本地源设置完成；实时数据库、团队沟通、BI 和外部文档仍为未来数据源缺口。

## 结论

Data Analytics 对宿析OS的核心价值不是新增一个独立模块，而是把现有链路变成可复用、可验证、可追责的分析工作方式。本次已把这套工作方式执行为项目级语义层与报告资产；下一步应接入真实运行数据做一次端到端验证。
