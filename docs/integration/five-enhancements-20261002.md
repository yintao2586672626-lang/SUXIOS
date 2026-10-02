# 五项强化与三轮巩固交付

用户于 2026-10-02 授权合并到主聊天并提交 GitHub。本增量从主整合提交 `30cac6f8c37c66dbe14aecc87366e9c901b36dec` 隔离整理，未批量纳入原工作树的其他修改。

## 接收内容

1. 同酒店、平台、账期的渠道净贡献；缺失来源保留缺失，空白人工来源不能当作已确认资料。
2. 实际耗材的采购现金、库存耗用、损耗与差异；同酒店、版本、指纹显式采用为投资测算参考。
3. 上海固定时点、精确24小时与同提前期预订监测；四位小数更正、按ID独立回读、来源指纹及酒店范围核对。
4. 保守/基准/乐观独立版本、历史复制、项目比较、月度偿债与现金压力。此项核心在投资回本基础包中复用，本增量补齐实际耗材与采购参考依赖。
5. 同规格与来源指纹绑定的逐项报告人审；待审/需修订不能正式导出，HTML/PPTX/包内审核状态一致。审核不授予经营审批。

三轮巩固修复空白来源、错误范围/历史ID、失败刷新残留、四位小数更正、保存回读不完整、营销资料缺失显示问题。新补酒店编号变化回归：旧审核证据不可改写，旧人审失效并要求重新复核。

## 必须按顺序组合

先接收 `HOTEL-payback-mainline-20261002` 的投资回本基础提交，再接收本增量。基础包需提供回本台账、导入、InvestmentScenarioService/Calculator/CashPlanner/controller/UI、ConsumablesCostCalculator、投资路由/菜单/模板及 InvestmentScenarioFixture/InvestmentPaybackRoutingTest。本增量的两种耗材引用服务存在后，基础包的能力门禁自动启用对应入口；保留其缺依赖失败状态修复。

本增量含采购参考目录服务及其九个严格哈希资料文件，始终为 `reference_only`；不得将参考价格自动应用为当前酒店经营事实。

本包提供共享组件、模板与构建脚本的语义增量，并包含隔离候选自行构建的产物，以通过精确暂存树检查。接收方须按自身合并后的版本重建经营组件、模板、entry、startup helpers、投资组件及全部相关loader缓存标识；合并共享源码差异时排除候选生成入口覆盖，不得从 dirty 源覆盖共享index。候选产物不证明主线程的组合产物已验证。

## 当前候选验证

- 渠道/预订：82 PHP tests / 369 assertions。
- 报告复核/spec/renderer：24 PHP tests / 491 assertions，保留主整合严格日期、503和判断对象展示修复。
- SchemaVersion显式白名单/采购资料：28 PHP tests / 194 assertions。
- 渠道、预订、报告导出/复核/培训边界、酒店编号登记：71 Node tests，通过且无跳过。
- 经营组件、模板、entry与startup helpers构建及完整提交前检查通过，相关diff检查通过。测试bootstrap重绑定候选app路径，共享vendor仅作为依赖。
- 将基础包6个未提交服务按稳定指纹仅载入候选作组合试验，实际耗材引用12 PHP tests / 93 assertions通过；这些服务不在本增量中，正式接收后仍须核对基础提交身份并复验。

以上是隔离候选的证据；实际耗材到投资的联合回读、投资能力启用及共享入口必须在基础包组合后再次验证。源版本已有186 PHP/1028断言与84 Node三轮证据，不能代替接收方集成验证，也不能与候选计数相加。

未执行真实数据库迁移、经营保存、真实人审、OTA/PMS写入或部署；本机先前迁移和合成页面结果另有源证据。GitHub、CI、主分支合并和现场状态按实际回执分别报告。

## 接收方验证

运行本增量与基础包的明确PHP测试文件；特别加入 `ActualConsumablesScenarioReferenceTest.php`、InvestmentScenarioPersistence/Portfolio/CashPlanner/Routing 和回本既有测试。运行上述71项Node及基础包的投资UI测试；重建后运行frontend-template、public-entry和startup-helpers校验。只用隔离合成数据检查同酒店耗材采用、编辑解除引用、三情景保存/历史、预订按ID回读与报告正式导出门禁。

源验收记录位于原工作树 `output/validation/five-enhancements-three-rounds-20261002/RESULT.md`；本提交不复制原数据库、截图、原始账号响应或session材料。
