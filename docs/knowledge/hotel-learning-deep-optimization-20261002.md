# 酒店学习功能深化与验收记录

日期：2026-10-02。本页记录第一轮深化；后续当前修改与验收见 [第二轮深化记录](hotel-learning-deep-optimization-round2-20261002.md)。范围为已落地的八种酒店学习工具及耗材直接依赖，围绕计算正确性、来源保留、版本恢复和用户操作完成一次闭环。外站私有后端和隐藏角色未取得，不把本轮功能验收写成外站全部代码已分析。

## 工作区与交付状态

隔离工作区为 `.active-worktrees/HOTEL-hotel-learning-delivery-20261002`，分支为 `codex/hotel-learning-hardening-20261002`。最初基于已合入 GitHub 的 PR #54 / `71a57340b663c504f4f193a242a70a372b6050cd`。验收期间主线新增 PR #55，工作区已快进同步至 `535ffcc156bf434c32993f1279b28c1b024a3c45`；本轮修改仍为未提交、未暂存的局部修改，未新建 PR、推送或部署。

同步前保留本轮16个文件及完整补丁到本地验收目录。仅主入口压缩产物与缓存版本发生冲突，已用双方合并后的源码重新构建；主线投资回本入口、迁移及采购引用可用性保护均保留。没有修改共享脏工作区或冻结的 HOTEL 工作区。

## 修复的具体问题

| 路径 | 修复及验收依据 |
|---|---|
| 耗材库存消耗 | 用补偿求和保留微量流转；先用原始数量计价。完全抵消的大库存不会掩盖独立微小负库存，超出可靠精度的输入明确拒绝 |
| 耗材领用与盘点 | 领用量、库存减领用量、实盘减账面量及每间夜/每次清洁成本保留非零精度；实际成本、报损和差额按整数分汇总，行顺序不改变金额 |
| 耗材缺失与停用 | 缺价仍保留有来源数量；没有可计价行时已知成本为 null，可计价真实零仍为0。停用行保留输入并跳过派生计价，页面明确显示“未纳入本期” |
| 计数与数值边界 | 已售/可售间夜和清洁次数须非负整数；缺失与真实零分开。非零数值字符串转成零、单价除法/乘法下溢及非有限成本比例明确报错，保留正常人民币分精度 |
| 计划实际复盘 | 计划与实际来源期间各自可见、可编辑；恢复版本或编辑复盘期间不覆盖来源日期。期间不匹配时保持部分资料并禁止差额比较，旧版本缺日期保持空白 |
| 投资目标恢复 | 保留完整规范情景的来源、摘要、现金计划及合同约束，合并表单编辑；百分比只转换一次，不丢失未直接展示的已保存输入 |
| 商圈人工核对 | 后端保存严格布尔确认；只恢复明确的 true，不根据引用键推断人工核对。编辑样本、场景、单位、权重或增删行撤销确认；旧版本缺确认保持 false |
| 文本与结果显示 | 普通业务文本保持原文，只翻译明确的状态字段；自有属性查找防止 constructor 等文本被当作函数。非零数量若六位小数显示成0，改为原数字字符串，例如1e-7 |
| 请求入口 | mode、月份、平台必须为文本；数组等错误请求返回422并且不保存，替代此前500。酒店、租户、月份、平台、模式及不可变摘要约束保持 |

库存平衡消耗、采购现金、领用、盘点差异及单列报损继续分别记录；盘点差异不能自动归因员工浪费或自动核销。外站示例、采购参考、手填观测和投资假设不能升级为已核验经营事实。

## 八种模式的验收覆盖

所有模式均通过后端相关测试以及编译组件的恢复、预览与保存输入一致、失败重试、当前版本CSV内容检查。共享前端实际 Vue 运行时另覆盖酒店/月/平台/模式快速切换、倒序响应和旧回读丢弃。

| 模式及用户入口 | 本轮主要检查 |
|---|---|
| 基础资料 · 知识中心 | 字段值、单位、来源、日期、质量状态恢复；原文与零/缺失区分 |
| 耗材领用盘点 · 经营财务 | 基础单位、原始数量、库存/领用/账面来源、分母、停用、精确金额及旧耗材版本兼容 |
| 回本目标反推 · 经营财务 | 完整情景、来源摘要、现金计划、合同约束、房价/入住率与百分比恢复 |
| 合同安全垫 · 经营财务 | 自然日期、目标与预测、约束及布尔值恢复；未取得数据不伪造安全结论 |
| OTA搜索与价格 · 线上经营数据 | 北京时间、搜索环境、价格权益、排名范围、转化率单位与渠道范围 |
| 商圈样本评分 · 线上经营数据 | 样本、权重、单位、同口径键和明确人工核对；编辑后核对失效 |
| 计划实际复盘 · 经营财务 | 独立来源期间、口径、七指标、真实零与缺失；项目现金与投资人实收分开 |
| AI问答观测 · 知识中心 | 模型版本、环境、观测日期、回答来源和引用状态；不推断营销效果 |

## 验证结果与证据上限

- PASS：最新主线基线的八组 PHP 回归 **190项 / 817断言**；新主线基础耗材及投资测算消费者兼容 **37项 / 349断言**。合计227项、1166断言，套件不重复计数。
- PASS：编译组件 Node **59项**，其中包括实际 Vue 运行时交互；修改前失败日志与修改后结果均保留，原业务预期断言未改。
- PASS：组件与宿主资源版本校验、主入口构建校验。最终组件 SHA256：`c6e1f9b66deaf8271c2f952f1a07a26c2affd4b7b5dc1e4fedf52a6023ea271e`。同步主线后该组件与浏览器验收版本完全相同。
- PASS：真实编译组件 + 真实控制器/服务 + 专用 SQLite 的隔离合成浏览器验收。版本#1保存并精确回读，重开后1e-7单位仍为1e-7，乘1万亿元/单位为100000元；每100间夜为1000元/间夜。版本#2重开保留2026-09-01至09-30的实际来源期间，并提示与十月复盘不一致，不计算差额。
- PASS：320×740手机宽度无横向溢出，保存按钮可用；临时视口已恢复，专用测试服务及临时页面已关闭。
- PASS：最新主线的 canonical integration gate 全部通过，包含迁移校验锁、源码规模约束、P0保护及工作区差异检查；没有覆盖或放宽任何校验规则。
- BLOCKED：真实 `http://127.0.0.1:8080/` 停在登录页，没有复用到现成登录态；真实账号与真实经营数据验收未完成。
- NOT RUN：浏览器CSV实际下载落盘；本轮只确认组件生成内容与当前回读版本一致。
- N/A：生产数据库迁移、生产部署、现场验证、采购/核销动作及经营效果，不属于本轮本地深化已验证事实。

本地证据目录：`output/validation/hotel-learning-deep-optimization-20261002/`。关键回执为 `phpunit-main-final.xml`、`main-consumer-compatibility.txt`、`frontend-final-node.tap`、`host-verify-main-final.json`、`integration-main-final.txt`；页面证据为 `consumables-reopened-after.png`、`review-source-period-after.png`、`review-mobile-320-after.png`。初次集成失败来自并行主线新增迁移后的基线差异，保留 `integration-final.txt` 与 `main-sync.txt`，不删除失败证据或放宽检查规则。

复现命令：

```powershell
C:\xampp\php\php.exe vendor/bin/phpunit tests/HotelLearningMechanismServiceTest.php tests/HotelLearningControllerTest.php tests/HotelLearningSnapshotServiceTest.php tests/ConsumablesActualCostPrecisionTest.php tests/ConsumablesCostCalculatorPrecisionTest.php tests/ConsumablesOperationalReconciliationServiceTest.php tests/InvestmentScenarioTargetSolverTest.php tests/InvestmentContractDateConstraintServiceTest.php --no-progress --do-not-cache-result
node scripts/run_php.mjs vendor/bin/phpunit tests/ConsumablesCostCalculatorTest.php tests/InvestmentScenarioCalculatorTest.php --do-not-cache-result
node --test tests/hotel-learning-workbench.test.cjs
node scripts/build_hotel_learning_component.mjs --verify
node scripts/integrate_hotel_learning_workbench.mjs --verify
npm.cmd run verify:frontend-entry-build
npm.cmd run verify:integration
```
