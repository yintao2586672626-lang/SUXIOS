# 酒店交付包学习成果：参考知识使用限制接入

2026-10-06（Asia/Shanghai）。用户在学习与五轮自查后要求“吸纳”。本次选择一个有明确现有落点、已复现缺口的机制：**外部方法可以检索；明确的参考限制不能被证据等级提升或遗漏任务禁用清单覆盖。**

结果：改动已接入宿析现有 KnowledgeDecisionGateService。经营问答检索、知识SOP草稿校验、后续来源重检、知识页汇总和收益研究复用此服务，无新模块或平行规则。局部机制处置为absorb，学习阶段为integrated/guarded（本地服务层）；三套来源应用的其他能力仍是候选，六条学习参考仍保持reference_only。

状态：实现已修改｜验证集成通过（服务层）｜Git未提交｜部署/现场未部署｜阻塞：登录后页面与HTTP业务入口验收缺少现成登录态；本地代码和服务验收已完成。

## 机制、价值和复现依据

机制来自此前来源审阅中的“资料/建议、授权、发送、执行/回读分层”及五轮自查发现的明确参考政策缺口；未复制来源项目程序或采纳固定价格、评分、安装与自动外发规则。来源版本和指纹保存在 `docs/knowledge/hotel-deliveries-20260730/source-manifest.json`。

价值门：旧宿析gate仅参考证据等级、时效和blocked_uses，未执行明确参考政策和显式false限制。五轮 `round-3-gate.json` 已发现B等级可以覆盖参考标记、C等级可以允许任务草稿。当前入口审阅还发现同项目使用scope与decision_policy表示参考限制，必须兼容。

复现门：在完全相同的合成输入上加载保存的改动前gate和当前gate，调用真实检索与SOP来源验证服务。五项受限输入改动前可被映射为decision_support并通过草稿来源验证；改动后仍可检索，映射reference_only，在任务创建前拒绝。第六项无明确限制的旧C等级SOP仍正常通过。没有创建真实任务或执行经营动作。

## 具体行为

- `usage_policy=reference_only`、`scope=reference_only`、`decision_policy=reference_only` 或 `reference_only_…` 同样限制决策和任务草稿；规范化大小写及前后空格。
- 明确 `decision_safe=false` 或 `task_draft_safe=false` 各自限制对应用途。已有显式字段中的false、0、"false"、"0"、null、数组或非法字符串不能放行；字段缺失维持旧行为。
- 受限决策同时返回status=reference_only，避免经营问答依据status再次映射为decision_support。
- true只表示没有该项显式禁用，不提升证据等级，也不覆盖来源、生命周期、时效、冲突和blocked_uses。
- 没有明确参考政策的旧C等级已复核SOP仍可生成草稿；不把evidence_level中出现reference这个单词等同于禁止用途。

知识SOP入口为 `POST /api/knowledge/:unit_id/chunks/:chunk_id/execution-intent`（route/app.php:469，Knowledge::createExecutionIntent）；经营问答是 `POST /api/agent/operating-questions`（route/app.php:710）。服务被既有入口调用，未新增API。上述HTTP入口未在登录态下实测；本次执行的是入口直接依赖服务和本地知识检索。

## 实际验证

- 改动前新增六个测试方法中三项失败，分别对应参考政策被覆盖、显式否定被忽略、否定用途不独立。
- 最终运行7个相关测试文件，46项测试、450条断言全部通过，覆盖gate、经营问答检索、SOP来源、收益知识、知识页汇总及正式知识提升。知识提升测试使用临时SQLite并恢复配置，未使用真实业务表写入。
- 同输入前后重放六个样例，包含四种既有参考政策表示、显式否定和合法旧SOP；源行为与适配行为均可比较。
- 新进程显式绑定本活动树app目录并核对实际加载的gate路径；只读原六条知识198–203、当前内容1067–1072。完整内容摘要与当前指针一致，六个既有服务检索词全部命中且保持reference_only；前后数据库回读结果一致。
- PHP语法及目标Git差异格式通过。gate原有“未来复核不得授权”三行保留；原KnowledgeDecisionGateServiceTest.php指纹不变，未覆盖既有47行测试改动。此次应用修改仅一个gate服务，另增本任务回归文件。
- 本机8080在本轮监听，health返回HTTP200/status=ok，浏览器实际显示登录页。无现成登录态或密码自动填充；未读取凭证、Cookie、localStorage或令牌，也未尝试绕过认证。健康与登录页不证明本次业务HTTP行为或该HTTP进程使用当前代码。

证据目录：`output/absorption/20261006-hotel-deliveries/integration-reference-policy/`。包含 `gate-before.php`、`pre-existing-gate.patch`、`before-test.txt`、`integration-tests.txt`、`entry-baseline.json`、`entry-current.json`、`ui-probe.json`、`acceptance.json`。前后入口重放程序为verify-entry.php；最终绑定检查为verify-acceptance.py。

## 保存、版本与边界

本次未新增知识字段、表或经营数据。原六条1.1参考和旧内容历史保持原样；改动执行已存字段，持久化证明是原六条完整回读及检索而不是新增业务保存。此前五轮资料和验收描述的是吸纳前gate快照，其“显式标志尚未硬执行”的描述是历史发现；当前政策由本报告绑定的应用源码执行。整个来源知识单元仍understood/candidate，只有此处选定使用限制机制接入了本地服务。

活动分支codex/save-all-20260903，HEAD=9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b，上游origin/codex/save-all-20260903。origin/main本地引用=5c3c64bc9bc82c0600ce224eb3f3366016ecb679（未fetch，非当前远程确认）。目标文件先保存已知基线并在写入前核对稳定指纹，采用局部补丁保留原有差异；未改变其他应用文件或已有测试。

没有OTA/PMS写入、LLM调用、真实经营任务创建、审批、发送、安装、长期记忆更新、模型权重训练、commit、push、PR或部署。登录后的UI/API、生产部署和现场效果尚未验证；页面验收需用户在原设备完成登录后继续。交接见 `output/handoffs/hotel-reference-policy-20261006.md`。本次本地机制实现与服务验收完成后停止。
