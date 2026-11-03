# JHIRA 方法在宿析 OS 的业务落地

日期：2026-10-02（北京时间）。来源：[JHIRA](https://joydo.us.ci/)、用户提供的《1001酒店易耗品采购价目表（各档位推荐）》及本项目已有业务实现。

本轮交付是来源启发的本地业务功能。外站可访问前端的字段、函数、公式与状态已形成清单；未取得其私有后端、隐藏角色页面和真实业务运行证据，不能声称完整复刻其全部实现。外站示例价格、固定系数、评分权重和收益阈值不成为酒店实际数据或默认经营规则。

## 用户入口与业务行为

| 对应板块 | 落地功能 | 保存和使用方式 |
|---|---|---|
| 经营与自投项目 | 耗材库存平衡、领用记录核对、账面与实盘差异、清洁次数及单位清洁成本 | 复用原 `consumables_actual` 版本库，新增记录可由原“实际耗材核算”读取，满足核对条件后明确采用为投资测算参考。差异和清洁成本不自动替换原成本采用值。 |
| 经营与自投项目 | 计划和实绩七指标复盘 | 同酒店、同月、同口径；收入、经营成本、偿债、项目现金、投资人实收独立记录。错期或错口径不计算差额。 |
| 投资回本 | 给定回本目标反求净房价或入住率 | 复用现有年度现金流模型、爬坡和现金调整。明确上下界及入住率形状，保存输入、建议值、正向复验和模型摘要。 |
| 投资回本 | 合同自然日期和安全垫 | 按实际自然月份和月末规则判断合同边界，保留合同来源和人工核对；手填回本预测仍是假设。 |
| 线上经营数据 | OTA 搜索场景、排名区间、可成交价格资格 | 记录关键词、定位、设备、登录状态、排序筛选、观察时点、入住日期、平台酒店 ID 与价格条件；“范围内未见”不产生虚构排名。 |
| 线上经营数据 | 商圈同口径参考评分 | 酒店 ID 唯一、单位明确、权重明确、样本来源和版本明确；缺指标、无区分度或混合场景不产生完整评分。 |
| 知识中心 | 基础资料逐字段来源和核对状态 | 字段值、单位、来源、资料日期和人工状态可编辑、保存、按版本读取。人工核对不自动成为独立核实事实。 |
| 知识中心 | AI 问答观测记录 | 保存问题、模型版本、地区网络、观察时点、回答摘要、引用及事实一致性；不自动声称模型调用、获客效果或订单归因。 |

所有新记录使用酒店、租户、月份和渠道范围；支持预览、新版本、按 ID 回读、编辑失效及基于同一返回对象的 CSV。界面不预填演示价格和未知成本，保存失败不显示成功。

## 十二项学习单元的承接

| 已入库学习单元 | 功能承接 |
|---|---|
| field_lineage | 基础资料来源表单、各模式的来源与日期、不可变业务快照 |
| consumables_unit_contract | 原采购参考确认和包装换算；原库存平衡；本轮领用、实盘和清扫对账 |
| financing_cash_pressure | 复用逐月债务计划、最低现金、缺资和偿债覆盖；年度融资与月度计划仍须分别明确，禁止暗中叠加 |
| inverse_target_caliber | 年度正向现金流模型的有界反求与候选正向复验 |
| ota_scene_comparability | 搜索场景、观察区间、售卖资格和同口径引用键 |
| sci_normalization | 明确权重、完整样本和版本的参考分；保留空缺，不赋 0/50 默认分 |
| actual_plan_bridge | 同期七指标实绩复盘；复用原经营实际成本与投资参考采用链 |
| report_delivery_gate | 复用已存在的报告版本审核、来源指纹、准确回读及正式导出约束；此前修正否定词被误判为通过 |
| ai_failure_provenance | 复用现有 AI 失败/部分完成状态和可信输入约束；来源读取失败不回填空成功 |
| expert_knowledge_curating | 复用知识来源、生命周期、人工审核和决策引用约束，12 个来源单元继续保持参考属性 |
| geo_evidence_chain | AI 问答观测与引用记录，保留固定问题环境和未验证归因 |
| field_ui_semantics | 中文字段、明确单位、显式状态、来源和日期；八类表单替代自由 JSON 编辑 |

## 实现范围

- 计算：`HotelLearningMechanismService`、`ConsumablesOperationalReconciliationService`、`InvestmentScenarioTargetSolver`、`InvestmentContractDateConstraintService`。
- 保存：`HotelLearningSnapshotService` 复用 `OperatingEvidenceSnapshotStore` 与现有迁移 `20261002_create_operating_evidence_snapshots.sql`。没有另建耗材孤岛版本库。
- API：`GET /api/hotel-learning/overview`、`POST /api/hotel-learning/preview`、`POST /api/hotel-learning/snapshots`、`GET /api/hotel-learning/snapshots/:id`。
- 路由独立放在 ThinkPHP 自动加载的根路由 `route/hotel_learning.php`，使用原 Auth 和酒店能力权限。
- 页面：`HotelLearningWorkbench` 按现有板块选择对应模式；新增内容有明确标记，接线脚本核对保留原文件其他内容。
- 原投资现金台账和采购参考功能继续由其原 API 承担。新目标反求保存的是手填假设版本，不能伪装实际成本引用或写入实际投资回款。

## 2026-10-02 后续巩固

针对八类模式完成十类边界问题复现：八类直接修复，以及新增耗材入口的两类精度保护。相关 PHP 173 项、805 断言和前端组件 35 项通过；隔离页面验证了非法排名拒绝、缺价保存为部分资料、补齐后保存可比较新版本。原共享实际耗材计算器两处精度缺陷仍需其并发维护任务修复，不把入口保护称为共享计算器完全修复。详见 `output/validation/joydo-hardening-20261002/RESULT.md` 和 `verification.json`。下述初次集成验收保留其原日期范围，不替代本轮证据。

## 验收与证据边界

验证结果、运行时状态、编译资源指纹和浏览器截图由 `output/validation/joydo-mechanisms-20261002/verification.json` 记录。隔离 SQLite 和浏览器合成数据只证明本地业务链路，不等于真实酒店经营结果。

本轮结果：151 项 PHP 测试、847 项断言及 35 项编译组件测试通过；组件、页面模板、主入口构建校验通过。八类模式在真实编译页面逐一录入、保存、按 ID 回读，生成隔离合成版本 1–8；逐条核对 SQLite 内容摘要。320 像素窗口没有横向溢出，按钮不低于 44 像素。编辑后旧结果失效、历史版本确认恢复和原实际耗材版本库兼容均已验证。

最终复查时主系统健康接口已恢复 HTTP 200，运行进程指向当前工作区；主系统提供的新组件与本地产物 SHA256 一致，新接口未登录返回 401。浏览器停在登录页，没有现成登录态；按本机授权约定，真实账户页面验收须由用户在原设备完成登录后继续。CSV 内容与 DOM 下载逻辑测试通过，但内置浏览器未返回下载完成事件，故下载落盘仍标注未验证。

可复现验证命令：

```powershell
& C:\xampp\php\php.exe vendor/bin/phpunit tests/HotelLearningMechanismServiceTest.php tests/HotelLearningSnapshotServiceTest.php tests/ConsumablesOperationalReconciliationServiceTest.php tests/InvestmentScenarioTargetSolverTest.php tests/InvestmentContractDateConstraintServiceTest.php tests/HotelLearningControllerTest.php tests/AiDailyReportPresentationReviewServiceTest.php tests/AiDailyReportReadFailureTest.php tests/AiReportGenerationTaskContractTest.php tests/KnowledgeDecisionGateServiceTest.php tests/KnowledgeLifecycleOptimizationTest.php --no-progress
node --test tests/hotel-learning-workbench.test.cjs
node scripts/build_hotel_learning_component.mjs --verify
node scripts/integrate_hotel_learning_workbench.mjs --verify
node scripts/verify_frontend_template_build.mjs
node scripts/verify_frontend_entry_build.mjs
```

外站完整字段与函数清单、来源限制和负面示例见 `docs/knowledge/joydo-jhira-field-code-review-20261002/README.md`；采购参考既有闭环见 `docs/knowledge/consumables-procurement-integration-20261002.md`。

Git 提交、推送、部署、真实账户验收和实际经营效果需分别记录，不能由代码或测试推定。
