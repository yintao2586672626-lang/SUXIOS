# 数据回传可靠性与证据复查闭环

## 目标和边界

依据用户批准的八项清单：完成本机结果回传、保存事务、确认回执、断网/重启恢复、脱敏业务证据留存、统一核验及已有页面状态。新实现只在当前 active worktree；保留原有修改，不触碰冻结 HOTEL，不提交/推送/部署，不读取凭证，不调用真实 OTA/PMS。

本地工程完成与真实现场采集分开。隔离测试数据不得作为真实经营事实展示。需要用户登录或新授权时明确报告，不降低门店、日期、来源验证要求。

## 验收账本

| 项目 | 确定性验收依据 | 当前执行状态 |
| --- | --- | --- |
| 保存事务 | 真实 LocalCollector adapter/sync 路径不替换外层连接；提交/回滚同时约束任务和数据 | PASS（真实 adapter 空结果故障路径；完整业务成功链仍待集成） |
| 待回传恢复 | 临时隔离目录中模拟断网/进程重启，原结果继续发送且不重新采集 | PASS（客户端合成 transport）；客户端到PHP整链待验证 |
| 确认回执 | 同设备/任务/结果重试重取原回执；不同结果及跨范围请求拒绝 | PASS（隔离服务测试；receipt成功路径使用已有替代 importer） |
| 证据留存 | 完整脱敏业务输入可读回，哈希/范围对应；超限明确失败而非静默截断 | PASS（隔离文件/服务）；用户可见证据入口待实现 |
| 核验接线 | 本机入口复用统一历史核验，状态不把保存成功混为可分析 | NOT RUN |
| 页面/API | 现有本机采集入口显示回传/保存/核验状态与安全证据入口 | NOT RUN |
| 本地集成 | 正常、断网、重启、ACK丢失、重复、超限、跨酒店拒绝覆盖直接依赖 | NOT RUN |
| 真实OTA现场 | 本轮不调用真实OTA；需要账户/登录时单独报告 | N/A |

## 工作记录

- 2026-09-05：重新确认 active worktree HEAD `9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b`，已有 79 个状态项，全部保留。新增修改从本账本开始记录。
- 保存事务复现采用现有隔离 SQLite harness + 真实 LocalCollectorDataSourceAdapter，禁止替代 importer 掩盖重连分支。
- 回归先失败：新增提交/回滚两个用例均报 `Sync task identity does not match the source scope.`。修复：本机结果没有外部等待，不刷新其调用方数据库连接。原用例随后 2 tests / 12 assertions 通过。
- 直接依赖回归：`C:\xampp\php\php.exe vendor/bin/phpunit --colors=never tests/PlatformDataSyncVaultBoundaryTest.php tests/PlatformDataSyncLocalCollectorP0Test.php tests/OtaLocalCollectorServiceTest.php`，111 tests / 989 assertions，通过。
- 第二个事务缺陷：catch 分支也有强制重连。新增真实 adapter + 身份检查异常提交/回滚用例，先 2 个失败，修复后合计 4 tests / 24 assertions 通过。
- 当前服务端聚焦：`C:\xampp\php\php.exe vendor/bin/phpunit --colors=never tests/OtaLocalCollectorServiceTest.php tests/OtaLocalCollectorEvidenceStoreTest.php tests/LocalCollectorControllerSecurityTest.php tests/PlatformDataSyncVaultBoundaryTest.php tests/PlatformDataSyncLocalCollectorP0Test.php`，123 tests / 1056 assertions，通过；6 个修改 PHP 文件语法与目标 diff 检查通过。
- 当前客户端聚焦：`node --test tests/automation/ota_local_collector_outbox.test.mjs tests/automation/ota_local_collector_contract.test.mjs`，21 tests 通过。仅合成临时文件、fake transport/测试 loopback，无实际 OTA 调用。

## 待回传协议（实施中）

- 客户端先在独立 outbox 原子保存脱敏业务 JSON，不保存 lease、设备 token、Profile 或原始认证上下文。
- `POST /api/ota-local-collector/tasks/{id}/resume-upload`：设备鉴权，输入 `result_id/result_hash/attempt`。确认同设备/账号/酒店/原采集 attempt；返回同结果已提交回执，或 `upload_ready` + 仅上传租约；不能生成新的采集任务或覆盖更新 attempt。
- `POST .../result`：输入上述标识、内存 lease 及原样 `result_json`；hash 对该 UTF-8 JSON 字节计算，避免 PHP/JS 数字序列化差异。服务器解包后仍走原身份、日期、标准行、回读校验。
- 确认回执 `delivery.status=accepted` 与经营质量独立。只有匹配 task/result/attempt 的明确回执才允许清理本机 outbox；`field_gap` 可能已保存但尚不可用于正式分析。
- 服务器确认回执必须与保存事务一起持久化；按结果 hash 保留回执历史，不能被后续采集 attempt 覆盖。完整脱敏输入另外保存至非 public 证据区，避免原始记录 256KiB 摘要限制。
- 客户端恢复先于 heartbeat/next；2001 行和 3MB envelope 边界不得静默截断成功。

## 后续必做（目标仍 active，不得提前宣称完成）

1. 统一核验：当前 `submitTaskResult()` 仍在外层提交前调用 `refreshDualOtaAuthorityReceipt()`；必须把外部验真放在成功提交后，并接 `OtaCanonicalHistoryPromotionCoordinator`，将失败留为待核验而非把已保存结果改成上传失败。核验不应阻断另一平台已有合格事实。测试必须注入验真执行器，不能让子进程读真实业务库。
2. 完整真实导入集成：现有事务回归走真实 adapter/sync 的空结果及身份错误路径；receipt成功测试复用替代 importer，不能当作完整实际导入证明。增加隔离 SQLite 的真实业务保存/精确回读/提交回滚及 JS→PHP 协议集成。
3. 已有页面：`resources/frontend/templates/fragments/35-page-online-data.html` 的本机采集块（约2295/2323行），相邻 `public/app-main.js` 的 `localCollectorLoginTaskRows`/`loadLocalCollectorStatus`（约21676/21872）复用 status.tasks 添加独立 collect/backfill 回执视图。不得破坏现有登录handoff轮询合同。
4. 证据查看：目前只在非 public 文件中保留完整输入，尚无用户授权的查看/重新解析入口。新增入口应按当前用户/租户/酒店过滤；不要直接展示 raw_data_json。检查并复用已有 `PlatformDataSyncService` 的 `sanitizePayloadForStorage` 对订单PII及URL敏感参数的规则，避免新证据路径绕过现有脱敏。
5. 超限UI：2001行/3MB结果目前完整留在blocked outbox并停止新采集；服务器任务可能直到租约过期才反映失败。需补明确失败通知/恢复指引，不让用户看到一直运行。400/401/403/404/409/410/413/422目前永久blocked；确认需人工恢复的状态与可重试/可处理状态区分。
6. 实际页面/API验收与范围身份绑定，明确测试数据/真实数据层。现有任务页是否登录、是否有现场数据需重新观察。未提交、未推送、未部署；新采集器代码未重启到真实运行进程。

## 当前修改文件

- `app/service/PlatformDataSyncService.php`：本机正常与catch路径均不重连。
- `app/service/OtaLocalCollectorService.php`：新envelope解码/回执与原事务接线，保留旧协议。
- `app/service/concern/OtaLocalCollectorResultDeliveryConcern.php`：补传许可、attempt/hash限制、原事务内回执历史。
- `app/service/OtaLocalCollectorEvidenceStore.php`：完整业务输入原子写入与hash回读，非public路径，支持测试目录注入。
- `app/controller/ota/LocalCollectorController.php`、`route/app.php`：设备鉴权与限流的resume-upload接口。
- `scripts/ota_local_collector.mjs`、`scripts/lib/ota_local_collector_outbox.mjs`：可靠待传及边界状态。
- `tests/OtaLocalCollectorServiceTest.php`、`tests/PlatformDataSyncVaultBoundaryTest.php`（保留先前已有权限改动）、`tests/OtaLocalCollectorEvidenceStoreTest.php`、`tests/automation/ota_local_collector_outbox.test.mjs`。
