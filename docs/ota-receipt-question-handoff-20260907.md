# 单店 OTA 回执到问答闭环

目标：单酒店、单平台、单业务日，保存回执到合格渠道事实，再到经营问答保存和重新打开。真实现场验收尚未完成。

## 本轮改动

- OtaLocalCollectorService：field_gap 保存提交后不再跳过核验回执；保持缺字段与 accepted 回传状态分离。回执按租户/设备/任务、状态、空租约及原保存摘要条件更新并精确回读，避免覆盖更新的采集结果。
- PlatformDataPersistenceConcern：列查询增加 SQLite 分支，保留 MySQL 路径，允许隔离环境使用真实保存路径。
- RealImportFixture：初始化前配置隔离缓存和日志、恢复 PHPUnit 错误处理器；正常合成用户具备明确单酒店采集权限，保留真实权限检查。
- RealImportTest：增加回执持久化断言及撤销酒店采集权限后无业务写入的用例。原业务指标和预期未改。

## 证据

- 首次运行：真实导入测试 3 个初始化错误；修复后进一步复现正常用户权限样例缺失、SQLite SHOW 不兼容，以及 field_gap 跳过核验和回执未持久化。均保留原产品断言进行修复。
- 最终 PHPUnit：OtaLocalCollectorRealImportTest、OtaLocalCollectorServiceTest、OperatingQuestionReadbackIntegrityTest、OperatingQuestionToolCallingPersistenceTest、PlatformDataReadAuthorizationTest：117 tests / 974 assertions PASS。
- Node ota_local_collector_receipts.test.mjs：11 PASS。
- 目标 git diff --check：PASS。
- 本机 health：status=ok，development_fallback，production_runtime_ready=false。
- 浏览器：独立 IAB 是登录页；原 Chrome 页被另一任务占用，未接管。真实酒店/租户、平台、业务日期、来源与字段质量尚未绑定，现场 BLOCKED。

## 唯一下一步

用户在本任务本机登录页完成登录后，从已有保存记录选择实际酒店/租户、一个平台和一个业务日，核对来源编号、字段质量与精确回读；在相同范围保存经营问答，重新打开并核对内容及来源引用。禁止默认使用本测试的合成酒店或日期。缺合格记录时明确证据不足，不能自动外站采集。

当前改动未提交、未推送、未部署；保留其他已有修改。无真实 OTA/PMS 写入、审批或外发。目标仍 active，不能以本地测试代替现场完成。
