# 酒店学习功能第二轮深化

日期：2026-10-02。围绕耗材成本引用、人工核对失效及错误输入恢复完善现有八种工具，不扩大至真实经营数据写入、经营动作或部署。本页保留第二轮证据；后续见 [第三轮其他板块深化](hotel-learning-deep-optimization-round3-20261003.md)，上一轮见 [第一轮深化](hotel-learning-deep-optimization-20261002.md)。

## 本轮结果

| 问题及修改前证据 | 修复后的实际行为 |
|---|---|
| 投资采用耗材版本的绝对金额容差允许4e-8改为0、4e-8改为2e-8及2改为2.00000005 | 与准确保存版本的同一float64金额严格一致；不同值返回409，不能继续保存；兼容数值字符串和真实零 |
| 实际耗材引用接受true/1.0作为版本编号，忽略标量行，items字符串抛TypeError | 版本编号只接受正整数或规范整数文本；错误计量项和行格式明确拒绝，不隐式丢弃 |
| 修改耗材数量、计价、来源、分母、纳入状态或增删行后旧人工核对仍为true | 相关编辑撤销旧operator_attested；用户再次明确勾选可确认。旧结果、保存标记和CSV同时失效 |
| 修改合同起止日或来源后仍提交旧contract_confirmed=true | 修改合同原文相关字段撤销旧核对；只修改基准日、预测或目标回本月数保留合同原文核对 |
| 原始非零数字1e-999经JavaScript/PHP转换或百分比1e-323除100变为0；重复行数字v-model提前丢失原文 | 数字控件保留原文，在发请求前提示具体字段；机制API也拒绝非零字符串下溢。真实零、空白及可计算科学记数兼容 |
| 错误列表/行/日期/单位返回500，错误对象被转成字段缺失后接受为部分成功 | 先进行类型与规范化校验，再校验来源月份；错误结构、单位、日期及保存请求标识返回422且不写入，修正后可用同请求键保存 |

金额引用只绑定人工核对的月度版本，仍为投资测算参考。采购现金、库存消耗、领用、盘点差异、项目现金与投资人实收不合并成同一种事实；人工核对不等于来源已独立核验。

## 保存、回读和直接衔接

已通过专用SQLite的真实服务链：0.04元月度耗材 / 1000000有来源已售间夜 =4e-8元/间夜；保存耗材版本后明确引用该ID与摘要，投资情景预览、保存、按项目重读仍保留4e-8、原摘要、采用确认、2026-10来源月份及2026-10-02资料日期。再改为0时预览和保存均拒绝，已有情景摘要、事件数与实际现金台账行保持不变。该验证为合成测试，不是实际酒店经营数据采用。

八种模式共用的恢复、预览/保存一致、失败重试、范围及迟到响应守卫继续通过。没有找到合法服务端入口的“历史耗材无ID”和“已规范化商圈混合单位”人工hydrate样例仅保留探索证据，未据此扩大产品实现。

## 验证与实际边界

- PASS：同步主线后12组相关PHP测试282项/1588断言；新增完整耗材→投资保存链1项/16断言。合计283项/1604断言，未重复计数。修改前绑定15项中7失败、入口25项中22失败；原业务断言保持。
- PASS：前端72项。原59项与本轮13项均通过，包括实际Vue控件事件、恢复后编辑、保存中断回读重试、重新确认及数值入口。确认问题修改前6项失败，数值转换3项失败。
- PASS：组件、宿主及主入口产物校验；canonical integration gate全部通过，包含迁移锁、P0保护及差异检查，没有覆盖或放宽规则。
- PASS：本机真实编译组件、控制器和服务，使用隔离合成租户7/酒店80/2026-10及专用SQLite。耗材版本#1为180元，修改采购入库100→110后旧核对撤销；重新确认保存#2为200元、领用成本180元、差异20元，重开数量/确认/结果一致。数字控件输入1e-999时明确显示“采购入库数量（基础单位）数值不可计算”，没有生成新版本。
- PASS：合同#3保存；只改预测12→13保留核对，修改来源撤销核对，预览为“未核对”、合同安全垫“未取得”；重新确认保存#4准确回读新来源。320×740下无横向溢出。
- BLOCKED：真实8080项目页面本次只读复核仍停在登录页，没有可复用登录态。未填写凭证、修改登录页或读浏览器存储；真实账号及现场经营数据验收未完成。
- UNVERIFIED：CSV内容、字面文本、转义及负数/零的测试通过；实际点击导出后，内置浏览器15秒未返回download事件，预期Downloads文件未找到，临时下载目录设置也被浏览器能力明确拒绝。没有把内容测试写成文件已落盘，也未据此更改正确的CSV实现。
- N/A：真实采购/核销、生产迁移、部署及经营效果。

## 代码与交付身份

工作区：`.active-worktrees/HOTEL-hotel-learning-delivery-20261002`；分支：`codex/hotel-learning-hardening-20261002`。已安全快进至本次核对的主线`d3e53e176d86d74f7868297125772116f666b3e4`，同步前保留完整本轮补丁与文件。仅共享压缩入口和缓存版本冲突，按双方合并后的源码重建。共享脏工作区与冻结HOTEL未写入。本轮及上一轮深化仍未提交、未推送、未部署；同步主线不表示这些本地优化已经合入GitHub。

最终源组件SHA256：`08f38ea1d510ea8522776820568650c312262a74e2e7c233609a9911243e1c7e`；压缩组件短hash：`e26d207292`。最终源码与文件完整指纹记录在本地`final-receipt.json`。

证据目录：`output/validation/hotel-learning-deep-optimization-round2-20261002/`。主要回执为`phpunit-after-main-final.xml`、`actual-binding-persistence.txt`、`node-final.txt`、`integration-final.txt`、`api-input-before.txt`、`actual-reference-precision-evidence.md`、`ui-second-pass-report.md`。页面截图与两个合成版本的准确JSON回读保留于同目录。临时浏览器页面、视口及专用测试服务在验收后恢复或关闭，CSV下载受限仍如实保留。

复现当前直接衔接验收：

```powershell
C:\xampp\php\php.exe vendor/bin/phpunit tests/ActualConsumablesScenarioReferencePrecisionTest.php tests/HotelLearningControllerTest.php tests/InvestmentScenarioPersistenceTest.php --no-progress --do-not-cache-result
node --test tests/hotel-learning-workbench.test.cjs
node scripts/build_hotel_learning_component.mjs --verify
node scripts/integrate_hotel_learning_workbench.mjs --verify
npm.cmd run verify:frontend-entry-build
npm.cmd run verify:integration
```
