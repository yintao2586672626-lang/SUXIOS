# 产品决策证据合同

只在候选较多、来源冲突、需要正式交接或用户要求产品分析简报时读取。普通单点决定保持简短。

## 1. 决策证据表

每条证据只占一行，并保留原始范围：

```text
evidence_id / evidence_type / source_ref / observed_at /
hotel_tenant_platform_date_scope / claim_supported /
quality_or_limit / conflicting_evidence / decision_effect
```

`evidence_type` 只使用：

- `system_fact`：当前页面、API、数据库或代码的可重复事实；
- `business_rule`：用户确认或项目权威规则；
- `user_behavior`：真实参与者、反馈或已定义遥测；
- `derived_metric`：可回指输入事实和公式的派生结果；
- `external_reference`：竞品、截图、文档或外部 Skill；
- `assumption`：尚未验证但为继续决策而显式采用；
- `unknown`：当前无法判断。

外部参考只能支持机制、界面假设或待验证问题，不能单独证明宿析当前用户、数据或经营效果。

## 2. 候选筛选

需要展示比较时使用下面的定性表，不给各列随意打分：

```text
candidate / target_user_task / chain_break /
supporting_evidence / contradicting_or_missing_evidence /
smallest_observable_outcome / prerequisites /
irreversible_or_external_effect / disposition
```

`disposition` 使用：

- `select_now`：当前证据支持，且能形成可逆、可验收闭环；
- `defer`：有价值但不属于最靠前断点，或依赖未完成上游；
- `reject_currently`：重复、无落点、无证据或需要未授权/不存在的外部能力；
- `needs_decisive_evidence`：候选差异会改变业务结果，而当前证据无法选择。

只有一个 `select_now`。如果没有候选达到该状态，决策为 `provisional` 或 `blocked`，不能为了显得果断而硬选。

## 3. 最小产品合同

```text
decision_id / decision_status / target_user /
problem_and_evidence / selected_direction / rejected_or_deferred /
entry / primary_action / output_or_persistence / readback /
missing_failure_state / compatibility / data_identity /
acceptance_oracle / non_goals / execution_authorization /
owner_or_next_action / falsifier / evidence_refs
```

- `acceptance_oracle` 写用户能观察或系统能确定判断的结果；没有来源时不用覆盖率、完成率、耗时、p95或样本比例装饰。
- 需要保存时必须定义精确回读；纯判断或聊天简报可标 `persistence=N/A`，并说明当前对话就是交付面。
- 涉及 OTA 或收益时，`data_identity` 至少保留平台、系统酒店、平台门店或绑定、业务日期、来源方法、采集时间和质量状态；缺失时不得向决策链放行。

## 4. 复验

- 决策复验：检查新增证据是否命中原先的 `falsifier`，而不是只确认实施进度。
- 产品复验：同角色、同任务、同入口和同核心口径比较；条件变化则标为不可比。
- 交付复验：正常路径加一个最关键的缺失、失败或不应执行反例。
- 经营结果：上线或使用变化不自动证明因果；没有同口径观察窗和来源时保持未知。
