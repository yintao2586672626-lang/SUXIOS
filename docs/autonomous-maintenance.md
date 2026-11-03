# 自主维护与迭代

## 使用入口与状态

本机宿析OS管理员登录后：系统与工具 → 系统与权限 → 系统配置 → 自主维护与迭代。

页面显示任务依据、范围、优先级、验收条件、执行状态、结果、检查点、失败原因和每日汇总。完成结果区分本地完成、自动验证、实际页面、GitHub保存、生产部署、经营效果；旧记录无回执时标记未核实。

复用 Codex heartbeat `os`，每15分钟检查一次，绑定“建立宿析OS自主维护机制”聊天，持续到用户明确说停。调度配置与实际检查时间分别显示；页面刷新不会更新实际检查时间。Codex本地任务需要本机与Codex可运行、额度可用；配置ACTIVE仅证明启用，实际唤醒须以后续check回执为准。

暂停阻止新修改/完成任务。执行中的文件/数据库写入由执行者在安全检查点保存后停止；在确认全部写入子进程退出前不释放OS执行锁，页面显示“停止已请求”。停止/截止与暂停同样受执行门禁控制。恢复按钮只恢复控制许可，不跳过中断复核；异常中断有恢复入口，不能直接重跑。默认截止为空；截止时间按上海时间保存，取消截止按钮恢复不限时间。

## 持久化与单执行者

队列位于 `runtime/autonomous_maintenance/state.json`，永久queue.lock序列化事务，临时文件原子替换并做SHA256精确回读。任务及结果/每日汇总同一事务保存。state.json损坏时返回不可访问且保留原文，不重建空成功。executor.lock在整个执行轮次保持独占；execution_id、心跳、控制版本、任务版本一起校验。此锁约束采用本机制的执行者；遇到其他聊天/进程修改同工作区必须先只读确认，不能假定它也持锁。

去重依据kind、issue_key、scope和证据指纹。指纹只含相关稳定源码或实际输入，不含运行日志/队列更新时间。已失败或待决定的任务没有新证据不重复运行。重启后的running任务归入interrupted，保存检查点与复核依据后才续接。旧异常owner不得阻断下一轮已复核的安全中断。

## 执行协议

在唯一入口 `D:\桌面\SUXIOS\宿析OS初始版\.active-worktrees\HOTEL-clean-20260901` 执行下列CLI，PHP为 `C:\xampp\php\php.exe`。JSON文件只保存脱敏依据，不能包含密码、令牌、认证材料或执行命令。

1. `scripts/autonomous_maintenance.php status`；核对工作区/Git/目标diff、服务health、额度、活动聊天与写入进程。
2. `guard <execution_id>` 作为本轮常驻进程持锁；`assert <execution_id> <control_revision>` 在每个副作用前执行。guard退出、暂停/停止/异常/到期均禁止继续修改。
3. `enqueue <task.json>` 记录已复现问题，`claim <execution_id> [task_id]` 每轮仅领取一个研发任务。
4. `update <execution_id> <task_id> <task_revision> <update.json>` 保存checkpoint或completed/failed/waiting_user。checkpoint需note、next_step、相关file_fingerprints、processes；可安全保存于暂停状态。终态需当前control_revision。completed必须逐条acceptance_checks具有passed和非空evidence，required_tiers逐项为true，不能弱化验收层次。
5. 所有写入已退出后，`release <execution_id> <review.json>`，review必须包含writes_quiescent=true、process_evidence、checkpoint_review；确认guard返回released并退出，才允许下一轮。
6. interrupted任务在核对原进程/文件指纹/已保存结果后使用 `recover <task_id> <task_revision> <review.json>`。review还需execution_id、next_step。无任务的遗留执行者用 `recover-executor <review.json>`。复核只解除恢复门禁，不自动resume。failed/waiting_user重试另需变化后的new_evidence_fingerprint。
7. `check <check.json>` 写入真实检查时间，含fingerprint、outcome、evidence；相同fingerprint/outcome不重复生成事件。`scheduler <config.json>` 只保存已精确读回的调度配置证据，与check分开。

## 空队列续接与实际执行回执

`next <execution_id> <control_revision>` 在guard内选择一个下一动作：先续接运行中/待执行研发任务，再将已复现ready候选去重入队，再复核needs_review候选。空队列不代表产品没有问题。`business-paths-v1` 目录目前限定六条实际路径：首页补证范围、收益页补证跳转、可信收益逐指标门禁、收益对比日期与旧请求回显、混合快照建议恢复、待确认建议重复保存与回读。每次返回明确的discovery_area、discovery_path、target_paths及源码/输入指纹；单条历史候选不能封住整个区域。仅六条路径都具有当前指纹的终态复核且没有可执行项时才no_action，这不表示全产品无缺陷。真实来源缺失只阻塞需要真实事实的经营动作，不能阻止独立本地研发复现。

`candidate <execution_id> <control_revision> <candidate.json>` 保存issue_key、scope（新路径含discovery_area与discovery_path）、title、priority、target_paths、evidence_fingerprint、evidence、state、next_step。state为needs_review/ready/blocked/reviewed_no_issue。ready必须有reproduction和development task_input（含acceptance）；没有复现不能伪造研发问题，没有真实资料不能创建运营依据。同scope/issue候选更新，指纹不变的linked候选不会重新执行。目录覆盖绑定具体路径、目录版本、稳定目标源码SHA与check明确提交的input_fingerprint；不含日志、队列更新时间或测试产物。旧check未提供输入指纹时保留上次信号；不可访问须提交明确不可访问信号，不作空成功。历史三个已知issue仅映射各自旧路径，首次核对稳定基线后可用reuse_verified_baseline=true补记已完成linked候选的路径覆盖，保留原ID/任务/验收结果，不重复执行。阻塞只覆盖该路径；新源码/同scope元数据变化后重开限定复核，failed任务仍须changed evidence恢复，不能自动重跑。

`run <execution_id> <control_revision> <run.json>` 保存run_id、trigger（scheduled/manual）、triggered_at、observed_at、outcome、evidence、next_step，执行时记录selected_task_id，等待并发时必填resume_condition。并发轮次禁止写共享队列，先把脱敏依据暂存在当前聊天；取得独占锁后延后登记，保留原观察时间、registered_at与delayed_registration，不把补登记时间当作原轮次执行时间。历史回执不能覆盖较新的当前轮次。

页面“实际执行与下一动作”由任务/候选/控制/回执推导；执行锁持有、控制允许运行与实际任务执行分开。超过两个调度间隔没有实际检查回执会明确提示待核对，不把ACTIVE或页面刷新冒充运行成功。每日汇总另含执行、等待等轮次计数。旧v1队列以增量字段兼容，不补造历史唤醒或完成结果。

## 运营范围与保存边界

研发任务由本机制排序和执行。运营建议复用已保存的pending_approval运营intent；queue kind=operation必须提供operation_intent_id，服务重新核验同tenant/hotel/platform/business_date、当前真实来源和verified事实，不接受自称真实的标签。建议仅为pending_confirmation，不能由研发claim执行。酒店/接收人/渠道/任务类型未明确授权时不派发，不自动审批或调整价格、库存、订单、付款。缺失、过期、失败、导入与本地模拟分别标注，OTA事实仅支持渠道结论。

当前只授权本地修改、必要构建/相关验证、服务启动重启。commit/push/PR/合并/部署/真实数据恢复覆盖仍需另行授权。没有新问题、状态变化或可执行任务时保持安静；重要功能、严重故障、中断或用户决定才通知。每天队列自动形成上海日期汇总。用户说停或到截止时保存进度、暂停调度并输出自包含收口报告。
