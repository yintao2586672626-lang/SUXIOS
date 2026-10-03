# JHIRA 酒店方法在 SUXIOS 主线的落地

日期：2026-10-02。本页记录 PR #54 的初次落地；后续各轮证据见 [第一轮深化](hotel-learning-deep-optimization-20261002.md)、[第二轮深化](hotel-learning-deep-optimization-round2-20261002.md)、[第三轮其他板块深化](hotel-learning-deep-optimization-round3-20261003.md)。来源为用户指定的 https://joydo.us.ci/ 前端资料及项目已有业务模型。外站示例、手填观测和采购参考不自动成为酒店经营事实；未取得私有后端、隐藏角色及真实经营效果，不能声称完整复刻外站。

## 用户入口

| 当前主线页面 | 功能 | 保存与边界 |
|---|---|---|
| 经营财务：耗材领用盘点与计划实绩复盘 | 库存平衡、领用金额、账实差异、每次清扫成本 | 酒店、月份、来源和数量单位明确；领用不等于消耗，盘点差异不自动归因或核销 |
| 同上 | 计划与实绩七指标复盘 | 同酒店、同月、同口径；收入、经营成本、偿债、项目现金、投资人实收分别记录 |
| 经营财务：回本目标反推与合同安全垫 | 在给定目标、上下界及入住率形状下反求净房价或入住率 | 手填假设，复用年度现金流正向模型核验；实际成本与采购引用不能未经项目接口校验而采用 |
| 同上 | 合同自然日期、安全垫及期限判断 | 按自然月/月末计算，保存合同来源与人工核对状态；预测保持假设属性 |
| 线上经营数据 | OTA 搜索排名及成交价格场景 | 固定观察环境、时点、入住日期及售卖条件；缺价或条件不全不能比较 |
| 同上 | 商圈同口径参考评分 | 明确样本、单位、权重和版本；缺失、相同值与真实零分别处理，不使用默认值补齐 |
| 知识中心 | 酒店资料字段来源 | 每字段保留值、单位、来源、日期与质量状态；不会升级为已核验酒店事实 |
| 同上 | AI 问答及引用观测 | 记录模型版本、问题环境、回答来源和引用；不把问答出现等同于营销效果或因果关系 |

初次交付基线 71a57340 中，原投资决策页面已退出运行时，因此本包把两个投资工具放在经营财务页。后续主线 535ffcc1 新增独立投资回本入口，本包的八种工具继续保留上述入口。原财务控制中心及其他页面内容完整保留，只增加带 SUXI_HOTEL_LEARNING_BEGIN/END 标记的区块。

## 保存与兼容

API 为 /api/hotel-learning/overview、/preview、/snapshots 和 /snapshots/:id，采用 main 原 Auth、Base、User 与 PermissionService。客户端不能指定另一租户，酒店权限、平台、月份、模式与准确回读共同约束每个版本。投资模式使用 investment.simulate 权限，其他保存使用 operation.execute。

HotelLearningSnapshotService 与 OperatingEvidenceSnapshotStore 保存不可变、带摘要和幂等键的版本；耗材模式沿用 consumables_actual kind。支持缺少新增字段的旧耗材 payload；ActualConsumablesScenarioReferenceService 保留对同酒店、同期间版本的准确引用核验。本包没有新增投资项目台账或原场景实际成本采用 API，不能把上述兼容核验写成主线项目采用流程已交付。

数据库新增 20261002_create_operating_evidence_snapshots.sql，使用项目原迁移流程和校验锁。全新主线库可创建完整表；已由其他功能创建旧物理表的环境，正式应用前需确认 source_hotel_id 等列存在。提交代码不代表已在实际数据库执行迁移。

## 功能巩固

本包包含先前八项直接修复与两项新增耗材入口保护：缺价禁止比较、整数计数、复盘口径规范化、非有限比例拒绝、排除行派生计算、按整数分汇总差额、年份/自然月边界、回本期限噪声一致性、大库存抵消防假零及小负库存防假零。

主线隔离测试另复现了空采购引用被放过后触发缺失类错误的问题，现对任何非 null 的 procurement_reference 明确拒绝，包括空数组、空串或 false；null 继续表示无引用。无需隐式进入外部采购参考路径。

初次交付只保护了新公开耗材入口，当时共享耗材基础计算器的两处精度缺陷仍未修复。后续深化已在共享基础计算器、实际耗用及领用盘点服务修复已复现的数值问题，并补验最新主线投资测算消费者；当前修改与验证状态见上述深化记录，不等同于本轮修改已经推送或部署。

## 验证与证据上限

初次交付的隔离候选相关六组 PHP 测试通过：139 项、652 断言；编译组件 Node 测试 35 项通过。包含八模式计算、酒店/租户/期间/模式隔离、无写入失败、幂等保存、按 ID 准确回读、旧 payload 兼容、同版本引用核验及新增空采购引用回归。

组件由 main 原 Vue 模板编译器生成；共享模板快照、模板 manifest、主入口、运行时渲染和裁剪样式在隔离候选统一重建。组件 SHA256 为 254727d5a383249d615613b74ba764de9b32abe1345a5a871745de515192421f，与此前八模式隔离浏览器验收的产物一致。

本地隔离页面及 SQLite 证明合成数据功能链路，不能代替真实账号、真实经营数据、上线迁移、CSV 浏览器落盘或经营效果。GitHub CI 和 main 合并结果以实际 PR 检查为准。

可复现的聚焦命令：

```powershell
& C:\xampp\php\php.exe vendor/bin/phpunit tests/HotelLearningMechanismServiceTest.php tests/HotelLearningControllerTest.php tests/HotelLearningSnapshotServiceTest.php tests/ConsumablesOperationalReconciliationServiceTest.php tests/InvestmentScenarioTargetSolverTest.php tests/InvestmentContractDateConstraintServiceTest.php --no-progress
node --test tests/hotel-learning-workbench.test.cjs
node scripts/build_hotel_learning_component.mjs --verify
node scripts/integrate_hotel_learning_workbench.mjs --verify
npm.cmd run verify:integration
```
