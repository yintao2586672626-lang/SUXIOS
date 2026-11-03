# 事件关联去重、周期简报与纠错候选学习合同

来源：用户提供 ota_watchdog 源码包；详细逐项出处及13个现有测试的初轮静态预审见watchdog-review.md。五轮复核后来隔离重放其中5个PMS/文本纯函数测试（排除顶层模块导入），其余8个仍未运行；原包测试套件未执行，不能写成13个全部通过。

## 事件与低噪声

输入：来源事件 ID、目标群与门店、发送人、正文、发生时间。`backend/app/agents/relay_events.py:L23-220` 先核对已绑定路由，再按事件或限定时间/门店/正文关联；冲突显式 route_conflict，歧义不扩大到多个门店。派发用短期 Redis NX 键去重。`backend/app/tasks/relay_events.py:L17-47` 先领标记后排队，两者不在原子事务内，进程中断可能抑制未派发事件；TTL 到期也能重复领取。

可用合同：稳定来源事件键+目标门店/平台/任务范围；已接收、已领取、已产出、已外发分别记状态；原子化 outbox 或可恢复状态，不能把短期防重当永久 exactly-once。正文模糊关联是辅助证据，不能代替唯一身份。

## 周期简报

`backend/app/agents/agent_scheduled.py:L26-189` 读取 agent 开关与频率、绑定门店、PMS/资料，生成可查看输出；此路径没有直接发群。PMS全缺会切为资料学习周报，是有价值的输出分型。`backend/app/tasks/celery_app.py:L264-273` 的定时配置存在，但本轮未验证实际触发。

失败和反例：PMS某店失败直接略过，结果未必明确列出缺店；critic异常仍保存原稿；同 agent/周期直接新增 AgentOutput，未见周期唯一键（models.py:L845-863）。应按 agent+周期+结果种类+版本幂等，并显示本次覆盖、失败、未运行与来源日期。来源频率、阈值和时间表均只是示例配置。

## 纠错到学习候选

`backend/app/agents/critique.py:L33-228` 限制改稿轮次与预算；self_rewrite 指稿件重写，不是改应用源码。`skill_miner.py:L31-192` 从近30天纠错提出 pending 候选并保留来源ID，人工批准后可安装。代码的三条纠错只检查总数，未确定性证明三条同类；模型评分和安装文件存在不证明能力生效、安全或业务效果。

`backend/app/api/deps.py:L66-134` 的门店范围 helper 读/写分开，但 `workbench.py:L426-457` 的技能审批与 personal_agents.py:L29-37 有 regional 资源范围缺口。不能因为 helper有测试就推断全部入口隔离。候选学习保留 source IDs、版本、反例与权限，默认只提议；不得把外部SKILL文本当执行授权。

宿析复用方向：已有知识中心来源、版本、参考检索和决策门适合存这些方法；后续正式SOP晋级需要独立证据，不自动改记忆、安装技能或启用定时任务。

五轮自纠补充：源码把“只供参考”、decision_safe/task_draft_safe字段当描述，不代表它们会独立强制执行。当前知识中心的实际判定由C级证据与blocked_uses共同限制；隔离反例显示把评级改B可覆盖描述性reference_only，而C级未配置blocked_uses也可能放行任务草稿。因此本批参考每次保存及回读均须实测两个闸门为false，不升级评级，不借这些潜在风险宣称本批已误放行。

处置：参考 `store_only`、整个能力 `absorption_candidate / understood`。除本轮参考知识修订保存外，无外部数据/消息/经营动作。用户要求“锤炼自己”在本轮落实为证据、反例、纠正版本与回读；没有修改模型权重、永久记忆、插件或自治权限。
