# 来源与适配边界

观察日期：2026-08-29（Asia/Shanghai）。

## 用户提供的展示层

- 附件定位：`<temporary-attachment-root>/codex-clipboard-0d4540e5-97ca-4960-bac4-6c3b3f6d2927.png`；临时本机路径不写入可分发合同。
- SHA-256：`8BB3351224918D9ADA0B06871B595325E8F034EF1FF6B68EF59771CC56D82AEB`
- 可见身份：技能卡“产品经理”“产品分析报告”，以及同页其他技能/连接器名称和简介片段。
- `mapping_status=unverified`：截图没有 package ID、版本、来源仓库、许可或实际文件树；本机订单来了市场和当前 Codex 插件缓存未找到这两个精确展示名，公开精确名称检索也未建立权威包页面。
- 结论边界：可以判断能力方向与宿析适配价值，不能声称已读取这两张卡对应包的完整细则、权限或安全状态，也不批准安装。

## 可定位的机制参照

- package：`data-analytics@0.2.8-13ceeea1f599`
- skill：`product-business-analysis`
- 审查定位：`<codex-home>/plugins/cache/openai-curated-remote/data-analytics/0.2.8-13ceeea1f599/skills/product-business-analysis`
- package manifest SHA-256：`A3AF158BB9AC14C316AEE39371731BE3E6825C135DBCBDDF82BEC07407D035F8`
- `SKILL.md` SHA-256：`3E6B684ACE151BBF777AFD88FE34758B337AC0D6FF2BF332958D7A495D624A4B`
- skill tree SHA-256：`dd8c6557787966c7bb580625cfd933868bd408d0557748704e414ef6bd55f0f1`
- 文件树：`SKILL.md`、`agents/openai.yaml`；无脚本。
- manifest：作者 `Data Analytics Maintainers`，主页 `https://openai.com/`，仓库字段为空，许可 `Proprietary`，Codex 插件兼容。
- 静态预览：`status=previewed`，无风险命中或结构错误；`manual_review_required=true`、`install_allowed=false`。本轮没有安装、覆盖或执行该外部包。

吸收的机制：从要支持的决定开始；核对来源权威性、新鲜度、口径、粒度和冲突；只分析会改变决定的问题；把证据、解释、未知和建议连成一个可行动结论。

未吸收的机制：默认搜索所有可能来源、强制调用数据仓库/Notebook/MCP、没有用户明确要求时强制生成耐久报告、自动发布，以及来源插件的任何读写工具能力。宿析已有语义层、用户研究、测试守卫和报告入口继续各司其职。

## 本地处置

- 截图卡：`disposition=absorption_candidate`，`delivery_label=source_inspired`，精确包映射仍未验证。
- 可定位参照：机制门和价值门通过；复现门以同一脱敏合成决策题进行来源重放，并与无 Skill 基线及本地适配结果比较。
- 同名冲突：截至观察时，项目 Skill、插件分发目录、用户 Skill 和插件缓存中没有 `suxi-product-decision` 或“宿析产品决策”。
- 本地权威入口：`.agents/skills/suxi-product-decision/`；插件分发副本位于 `plugins/suxi-os-toolkit/skills/suxi-product-decision/`。实际加载缓存未刷新前，不得声称运行态已激活。

## 重放与基线差异

黄金题为 `evals/evals.json` 中的 `choose-trust-closure-over-polish-or-unauthorized-write`；所有观察均为脱敏合成场景，不是酒店、用户或生产事实。

- 无本 Skill 基线：正确选择 B，能说明事实可信上游断点、最小闭环、非目标和外部写入缺口。说明项目 `AGENTS.md` 已提供较强通用基线；本 Skill 的价值不能宣称为“让模型第一次选对”。
- 可定位来源重放：同样选择 B，并保持小样本和 OTA 渠道边界；但额外提出题面未要求的“至少两家”与 `3/3` 验收数量，还按来源链加载了额外上下文/验证 Skill。数量可用于具体测试设计，但没有需求依据时不应成为产品验收门。
- 本地适配重放：选择 B，明确 `decision_status=provisional`、`execution_authorization=decision_only`，把合成观察、解释、未知、数据身份和外部写入边界分开；没有新增分数、比例、时限或用户事实，也没有修改文件或执行外部动作。
- 边界 A（两个候选都无直接证据）：输出 `blocked + decision_only`，只要求一份同口径真实工作观察，不用主观评分拍板。
- 边界 B（方向已批准、只要需求）：输出 `decision_ready + spec`，保持零代码修改，验收覆盖同一记录、缺失、失败、旧数据兼容和零外部写入。

结论：来源机制已在同题重放，本地适配达到 `maturity=reproduced`。相对基线的可观察增量是决策状态、执行模式、权限、数据身份和可证伪条件的一致性，而不是候选选择准确率。结构、触发、必要质量案例、项目/插件字节一致和故障注入由仓库自动合同持续守卫；最终是否 `guarded` 以当前候选上的新鲜测试结果为准，不在本文硬编码绿色状态。

## 可重复行为评测

当前 sealed 运行、真实失败、修正历史、全部哈希和证据上限统一记录在 [behavior-eval-provenance.md](behavior-eval-provenance.md)。早期没有外部 prepare seal 或完整 schema 分支约束的运行只保留为历史，不再称为最终证据。

- 行为合同：`evals/behavior-evals.json`，版本 `suxi.skill.behavior_evals.v1`，包含 5 个结构化案例；项目版与插件版必须逐字节一致。
- 无 API 评测器：`scripts/suxi_skill_behavior_eval.mjs` 只生成最小答题包、在回答存在后构造裁判包并做确定性核对；自身不调用模型、网络、凭证或子进程。
- 泄漏与隔离边界：答题快照只包含 `SKILL.md`、`agents/openai.yaml` 和 `decision-evidence-contract.md`，不包含 `evals/`、本来源记录或行为证据文件；期望与断言只在回答全部存在后进入裁判包。文件系统分离仍为 `instruction_only`，不得称为 OS 沙箱或独立身份验证。
