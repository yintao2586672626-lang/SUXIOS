# 三套酒店交付包与手册：学习处置与验收

**2026-10-06五轮修订：当前知识包1.1，六条参考已经修订、独立回读并检索当前版本，原内容历史保留。五轮发现、自我纠正和当前验收见 [五轮报告](2026-10-06-hotel-deliveries-five-pass.md)。** 初次报告快照保存在任务输出five-pass/baseline中。下文初次合成测试统计仅为1.0历史证据，当前验证以five-pass/acceptance-v11.json为准。

2026-10-06（Asia/Shanghai）。用户请求：学习吸纳。范围：三份指定ZIP和两份指定手册，定向核对入口、关键源码、机制及反例；不是三个来源项目全仓审计或部署任务。

**结果：6条方法参考已经保存到宿析既有知识中心，并独立回读、检索、重复导入验证。正式业务能力仍是候选，未接入新的采集、报表、自治或发送功能。**

状态：实现已修改（蒸馏资料、验证工具、参考知识）｜验证聚焦通过（来源指纹、纯函数重放、参考知识保存/检索）｜Git未提交｜部署/现场不适用（本轮无部署）；真实账号与现场未验证｜本轮资料处理阻塞无。

## 五份材料分别怎么用

| 材料 | 本轮保留的方法 | 必须保留的边界 |
|---|---|---|
| hotel_auto_x_deliver_20260730.zip | 广告来源质量分型、单格来源审计、来源成熟窗口、运行与跳过分层 | 非有限数/非法值与缺字段可能进入有效或verified-zero；缺日期默认当天、保留旧非零、assumed_zero和日报fallback不可直接放行 |
| ota_watchdog_deliver_20260730.zip | 建议来源留痕、可编辑卡片、事件关联与去重、周期简报分型、纠错证据绑定候选 | send不强制approved；自动评分不代替确定性质量；sent不是经营执行/回读；未来时刻、混合新鲜度、周期幂等与regional范围仍有缺口 |
| hotel-ops-h5-no-data-20260730-155031.zip | 平台身份状态、最小证据envelope、候选与人工确认、来源及覆盖展示 | 仅绑定/旧缓存/另一渠道数据可能制造假零；跨渠道比率简单平均；匹配源码v3与文档v2不一致；完整采集/持久化/页面未验证 |
| 使用手册.md | 管理员、前台、运营分工；绑定、身份核对、离线/待登录/账号变更、补采与查单路径 | 示例账号和经营动作不导入；手册不证明实际页面、补采或现场行为 |
| 部署手册.md | 部署健康、数据持久化、备份恢复、人工登录验证、上线验收分层 | 配置、部署、删除和账号步骤只是来源内容；没有执行。固定容量和版本建议未作为当前推荐采用 |

源码事实、文档宣称、推导反例与本轮实测在详细报告中分别标明。文件名20260730是来源日期声称，不作为当前业务日期；H5 package声明0.6.0，源仓库/ref/提交、商业许可、当前平台兼容与生产副本未验证。三包条目数分别375、486、263，包含目录项，不代表读完1124个源码文件。

## 已保存的六条参考知识

| 知识ID / 当前内容ID | 名称（也可用作检索词） | 方法文件 |
|---|---|---|
| 198 / 1067 | 广告质量分型与真实零值反例 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/autox-ad-quality.md) |
| 199 / 1068 | 字段来源审计与日报兜底反例 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/autox-report-provenance.md) |
| 200 / 1069 | 经营建议审批发送与执行回读边界 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/watchdog-strategy.md) |
| 201 / 1070 | 事件关联去重与纠错候选学习 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/watchdog-events-learning.md) |
| 202 / 1071 | 前台采集身份与最小证据合同 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/h5-collection-evidence.md) |
| 203 / 1072 | 点评查单证据门与概览假零反例 | [方法](../knowledge/hotel-deliveries-20260730/versions/v1.1/h5-review-and-metrics.md) |

使用既有 KnowledgeSourceImportService、knowledge_units/knowledge_chunks、KnowledgeDecisionGateService 和 OperatingQuestionKnowledgeRetrievalService；未新增表、迁移或平行知识体系。6条为全局方法参考（hotel_id=0、created_by=0），不绑定成某店经营事实；稳定键为 `global:hotel_deliveries:20260730:<key>`，内容和来源均带指纹。

全部 `usage_policy=reference_only`、`decision_safe=false`、`task_draft_safe=false`、`external_write_authorized=false`。现有知识门实际依靠证据等级C和blocked_uses约束，这些布尔描述本身不是通用硬门；本次工具同时检查这些条件。知识单元status=done只表示参考资料处理完成，能力处置仍为absorption_candidate；1.1六条整体成熟度均understood，点名子机制单独列复现证据，纠正初版将局部复现提升为整条reproduced的问题。

## 初次验收历史与本次修订

1.1已用整包事务追加内容1067–1072，旧1060–1065原文与来源保留，生命周期改为superseded并指向新内容，不称旧JSON所有字段不变。模拟第一条写入后失败，六条指针、内容摘要和数量快照一致；随后真正保存、完整内容指纹回读、当前版本检索和重复unchanged通过。新独立验收绑定当前文件及程序指纹，只读当前六条数据库记录，七条过期/错误回执反例均拒绝。当前入口five-pass/final-pass.php；旧verify-delivery.py拒绝对1.1运行。

- 5份原件SHA256核对；ZIP安全路径检查；只保留已审过的纯函数重放副本和脱敏蒸馏，不复制原始手册、凭证文件、平台数据、环境配置、浏览器资料或登录态。原件仍在用户微信临时目录；日后该目录失效须从相同指纹原件重新核对，不冒充源仍可访问。
- 广告14个合成样例：14/14复现源码返回且输入未修改；同时确认Inf、NaN、非法值、缺必填与异构指标抵消等质量缺陷。这不是14个业务质量PASS。
- H5原概览测试通过；追加16个合成纯函数行为样例（概览6、身份7、envelope3），其中4个概览来源合同FAIL已复现。runner成功不把来源缺陷改成通过。
- 初次watchdog 13个原有测试仅静态预审；五轮修订中5个原测试函数已隔离重放，其余8个及完整套件仍NOT RUN。修订增加39条watchdog、8条H5、7条AutoX源行为记录，含原测试与控制，不宣称54个独立唯一用例；7条知识门记录另计。三个来源应用均未启动，采集、ETL、完整调度、存储桥、企微发送均未运行。
- 6条参考知识首次保存成功，独立核对单元字段、内容字段、摘要与当前版本指针；6个标题检索全部matched，带source_refs且保持reference_only。
- 第二次导入全部unchanged，无重复知识或内容；每条跨酒店私有化合成反例均blocked。实际数据为全局参考，可以在符合既有规则的酒店上下文读取；隔离反例不冒充现场跨租户验收。
- 30个知识门样例通过（每条：可读参考、缺来源拒绝、归档拒绝、过期拒绝、未来复核不放行决策/任务）。未来复核仍可作为参考读取，不误写成未来材料完全不可检索。
- 初次学习时本机3306监听、8080未监听、health不可访问；该历史快照不作为当前应用运行状态。登录后知识中心页面未验证。保存与检索证据来自既有本地数据库/服务，无LLM调用。

证据：[来源清单](../knowledge/hotel-deliveries-20260730/source-manifest.json)、[知识包](../knowledge/hotel-deliveries-20260730/knowledge-pack.json)、`output/absorption/20261006-hotel-deliveries/knowledge-persist.json`、`knowledge-verify.json`、`knowledge-idempotency.json`、`autox-replay.json`、`h5-replay.json`。两个包的详细审阅分别为同输出目录 autox-review.md、watchdog-review.md。

## 取舍与停止

最高价值候选：**广告/报表的逐字段质量与来源解释**。宿析现有采集质量判断、严格OTA历史及点评匹配已覆盖相邻机制；本轮没有确定独特且当前必须新增的功能缺口。优先留存状态和反例，下一次具体需求按现有服务扩展，不复制整套Python/FastAPI/Node架构。

资料内部命令、部署步骤、示例账号、固定价格、时间窗、权重、推送/自改/安装步骤都不是本轮执行权限，也未成为当前默认值。不存在新增OTA/PMS写入、批准、消息发送、自动化、安装、记忆更新、commit、push或PR。

工作区原有869条未提交状态及KnowledgeDecisionGateService的既有3行差异均未改动；本轮写入仅新建任务资料与参考知识。读到的活动分支为codex/save-all-20260903，HEAD=9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b，上游origin/codex/save-all-20260903；origin/main本地引用=5c3c64bc9bc82c0600ce224eb3f3366016ecb679（未fetch，不称最新远程main）。与此HEAD关联的PR43已合并是历史分支事实，不代表本轮资料已提交或发布。

本轮参考沉淀验收完成后停止；正式业务吸纳、页面验证、真实源验证与现场效果均不冒充已完成。
