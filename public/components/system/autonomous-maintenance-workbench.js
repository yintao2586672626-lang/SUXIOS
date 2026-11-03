(() => {
    'use strict';
    const MODE = { running: '允许运行', paused: '已暂停', stopped: '已停止', exception_stop: '异常停止' };
    const STATE = { queued: '待执行', running: '执行中', completed: '已验收', failed: '失败',
        waiting_user: '待你处理', pending_confirmation: '运营建议待确认', interrupted: '中断待复核' };
    const TIERS = { local_completed: '本地完成', automated_verified: '自动验证', actual_page_verified: '实际账号页面',
        github_saved: 'GitHub保存', production_deployed: '生产部署', business_effect_verified: '经营效果' };
    const WORK = { executing: '正在执行任务', pending_execution: '已有任务待执行', discovery_pending: '待复核下一项使用路径',
        waiting_concurrency: '等待同工作区执行者退出', control_blocked: '控制已暂停或停止', reviewed_no_action: '本轮已复核，暂无可执行项' };
    const CANDIDATE = { needs_review: '待复现与复核', ready: '已复现，可入队', blocked: '有具体阻塞', reviewed_no_issue: '已复核，无新问题', linked: '已接入任务队列' };
    const RUN = { checking: '检查中', executing: '实际任务执行', completed: '本轮完成', waiting_concurrency: '并发等待', blocked: '阻塞', no_action: '已复核，无变化' };
    const create = ({ Vue, h }) => ({
        name: 'AutonomousMaintenanceWorkbench',
        props: { request: { type: Function, required: true } },
        setup(props) {
            const data = Vue.ref(null), error = Vue.ref(''), busy = Vue.ref(false), loading = Vue.ref(false);
            const notice = Vue.ref(''), deadline = Vue.ref(''), filter = Vue.ref('all'), deadlineError = Vue.ref('');
            let generation = 0, disposed = false, timer, deadlineDirty = false;
            const load = async (expected = null) => {
                const version = ++generation;
                loading.value = true;
                try {
                    const response = await props.request('/autonomous-maintenance/overview');
                    if (disposed || version !== generation) return null;
                    if (response?.code !== 200 || !response.data?.control || !response.data?.tasks
                        || !Array.isArray(response.data.events)) throw new Error('维护回读格式不完整');
                    const snapshot = response.data;
                    if (expected && (snapshot.control.revision !== expected.revision
                        || snapshot.control.mode !== expected.mode || snapshot.control.deadline_at !== expected.deadline_at)) {
                        throw new Error('控制状态已被其他操作更新，请重新核对');
                    }
                    if (data.value && snapshot.control.revision < data.value.control.revision) return null;
                    data.value = snapshot; error.value = '';
                    if (!deadlineDirty) deadline.value = snapshot.control.deadline_at?.slice(0, 16) || '';
                    return snapshot;
                } catch (e) {
                    if (!disposed && version === generation) error.value = e?.message || '维护状态读取失败';
                    if (expected) throw e;
                    return null;
                } finally { if (version === generation) loading.value = false; }
            };
            const mutate = async (action, deadlineAt = null) => {
                if (busy.value || !data.value || error.value) return;
                busy.value = true; notice.value = ''; ++generation;
                try {
                    const response = await props.request('/autonomous-maintenance/control', {
                        method: 'POST', body: JSON.stringify({
                            action, expected_revision: data.value.control.revision,
                            deadline_at: deadlineAt, reason: '系统配置维护页面：' + action,
                        }),
                    });
                    if (response?.code !== 200 || !response.data?.control) throw new Error(response?.message || '控制保存失败');
                    if (action === 'deadline') deadlineDirty = false;
                    await load(response.data.control);
                    notice.value = data.value.executor
                        ? (data.value.control.mode === 'running' ? '控制已保存并精确回读；当前执行者仍持有执行锁'
                            : '控制已保存并精确回读；正在等待执行者在安全检查点停止')
                        : '控制已保存并精确回读，当前没有执行者占用';
                } catch (e) { error.value = e?.message || '控制保存或回读失败'; }
                finally { busy.value = false; }
            };
            const saveDeadline = () => {
                if (!deadline.value) { deadlineError.value = '请选择截止时间；清除请使用取消截止时间按钮'; return; }
                deadlineError.value = '';
                const text = deadline.value.length === 16 ? deadline.value + ':00' : deadline.value;
                return mutate('deadline', text + '+08:00');
            };
            Vue.onMounted(() => {
                load();
                timer = setInterval(() => { if (!busy.value && !loading.value) load(); }, 15000);
            });
            Vue.onUnmounted(() => { disposed = true; ++generation; clearInterval(timer); });
            const rows = Vue.computed(() => Object.values(data.value?.tasks || {})
                .filter(task => filter.value === 'all' || task.state === filter.value)
                .sort((a, b) => String(a.priority || '').localeCompare(String(b.priority || ''))
                    || String(b.updated_at || '').localeCompare(String(a.updated_at || ''))));
            const label = (tag, text, cls = '', attrs = {}) => h(tag, { class: cls, ...attrs }, text);
            const button = (text, action, testid, primary = false) => h('button', {
                type: 'button', disabled: busy.value || loading.value || !data.value || !!error.value,
                'data-testid': testid, onClick: action, class: primary ? 'btn btn-primary' : 'btn btn-secondary',
                style: 'min-height:44px;padding:8px 14px;',
            }, text);
            return () => h('section', { class: 'card mb-6 p-5', 'data-testid': 'autonomous-maintenance-workbench' }, [
                h('header', { class: 'flex flex-wrap items-start justify-between gap-4 mb-4' }, [
                    h('div', {}, [
                        label('h2', '自主维护与迭代', 'text-xl font-semibold'),
                        label('p', '携程 / 美团数据 → 收益分析 → 运营任务。研发本地执行，运营建议待确认。', 'text-sm text-gray-600 mt-1'),
                    ]),
                    h('button', { type: 'button', class: 'btn btn-secondary', disabled: busy.value || loading.value,
                        'data-testid': 'maintenance-refresh', onClick: () => load(), style: 'min-height:44px;' }, loading.value ? '读取中…' : '刷新状态'),
                ]),
                error.value ? h('div', { role: 'alert', class: 'bg-red-50 text-red-700 p-3 mb-3', 'data-testid': 'maintenance-error' }, [
                    label('strong', '当前访问失败：'), error.value,
                    data.value ? '。以下为上次已保存记录，当前状态未确认；刷新后再操作。' : '。无法确认队列是否为空，请刷新重试。',
                ]) : null,
                deadlineError.value ? label('p', deadlineError.value, 'text-sm text-red-700 mb-3',
                    { role: 'alert', 'data-testid': 'maintenance-deadline-error' }) : null,
                notice.value ? label('p', notice.value, 'text-sm text-green-700 mb-3', { role: 'status', 'data-testid': 'maintenance-control-receipt' }) : null,
                !data.value ? label('p', loading.value ? '正在读取持久维护记录…' : '尚未取得维护记录', 'text-gray-600') : h('div', {}, [
                    h('div', { class: 'border rounded p-3 mb-4', 'data-testid': 'maintenance-work-status' }, [
                        label('div', '实际执行与下一动作', 'text-sm text-gray-500'),
                        label('strong', WORK[data.value.work_status?.state] || '旧记录尚无实际执行回执', 'text-lg'),
                        label('p', data.value.work_status?.next_step || '先核对未完成任务，再复核一处业务使用路径；不能凭调度启用判断正在工作', 'text-sm mt-1'),
                        data.value.work_status?.discovery_coverage ? label('p', '已复核 ' + data.value.work_status.discovery_coverage.reviewed_paths
                            + '/' + data.value.work_status.discovery_coverage.total_paths + ' 条限定业务路径；单条旧记录不代表整个区域已检查',
                            'text-xs text-gray-600 mt-1', { 'data-testid': 'maintenance-discovery-coverage' }) : null,
                        label('p', data.value.work_status ? '待执行 ' + data.value.work_status.counts.queued + ' · 待复核候选 ' + data.value.work_status.counts.needs_review
                            + ' · 可执行候选 ' + data.value.work_status.counts.ready + ' · 阻塞候选 ' + data.value.work_status.counts.blocked : '候选记录尚未补齐', 'text-xs text-gray-600 mt-1'),
                        data.value.work_status?.check_overdue ? label('p', '最近实际检查回执已超过 ' + data.value.work_status.overdue_after_minutes
                            + ' 分钟或尚未登记；需要核对唤醒和并发等待，不能视为持续执行', 'text-sm text-red-700 mt-1', { 'data-testid': 'maintenance-overdue' }) : null,
                    ]),
                    h('div', { class: 'grid grid-cols-1 md:grid-cols-3 gap-4 mb-4' }, [
                        h('div', {}, [
                            label('div', '运行控制', 'text-sm text-gray-500'),
                            label('strong', MODE[data.value.control.mode] || '未知状态', 'text-lg', { 'data-testid': 'maintenance-mode' }),
                            label('p', data.value.execution_state === 'executor_lost' ? '执行者失联，写入是否停止尚未确认；需要复核'
                                : data.value.execution_state === 'recovery_pending' ? '执行已中断，先复核检查点和进程，再恢复'
                                : data.value.executor ? (data.value.execution_state === 'stop_requested'
                                ? '暂停/停止已请求，执行者尚未确认退出'
                                : '执行锁持有者 ' + data.value.executor.execution_id) : '执行锁已释放',
                                'text-sm text-gray-600 mt-1', { 'data-testid': 'maintenance-executor' }),
                            label('p', '原因：' + data.value.control.reason, 'text-xs text-gray-500 mt-1'),
                        ]),
                        h('div', {}, [
                            label('div', '调度配置', 'text-sm text-gray-500'),
                            label('strong', data.value.scheduler ? '每 ' + data.value.scheduler.interval_minutes + ' 分钟 · ' + data.value.scheduler.status : '尚未核实调度配置'),
                            label('p', '配置启用与实际执行分别核验', 'text-xs text-gray-500 mt-1'),
                            label('p', '最近有效检查：' + (data.value.last_check?.checked_at || '尚无检查回执'), 'text-sm mt-2', { 'data-testid': 'maintenance-last-check' }),
                            label('p', data.value.last_check?.evidence || '页面访问不会冒充定时检查', 'text-xs text-gray-500 mt-1'),
                        ]),
                        h('div', {}, [
                            label('div', '截止与恢复', 'text-sm text-gray-500'),
                            label('strong', data.value.control.deadline_at || '直到你明确说停'),
                            label('p', data.value.recovery_required ? '异常中断：先复核进程和检查点，再恢复' : '中断任务保留检查点，恢复前复核已完成操作', 'text-sm text-gray-600 mt-1'),
                            label('p', data.value.restart_recovery?.enabled
                                ? '本机重启恢复已授权：启动时间、执行锁与检查点校验通过后自动接续；主动暂停、停止或到期保持生效'
                                : '本机重启自动恢复未启用，保持人工复核', 'text-sm text-gray-600 mt-1', { 'data-testid': 'maintenance-restart-policy' }),
                            data.value.restart_recovery?.last_review?.outcome === 'resumed'
                                ? label('p', '最近重启已复核续跑：' + data.value.restart_recovery.last_review.observed_at,
                                    'text-xs text-gray-500 mt-1', { 'data-testid': 'maintenance-restart-review' }) : null,
                        ]),
                    ]),
                    h('div', { class: 'flex flex-wrap gap-2 items-center mb-4' }, [
                        button('暂停维护', () => mutate('pause'), 'maintenance-pause'),
                        button('恢复维护', () => mutate('resume'), 'maintenance-resume', true),
                        button('停止维护', () => mutate('stop'), 'maintenance-stop'),
                        h('label', { class: 'text-sm' }, ['截止时间（上海） ', h('input', {
                            type: 'datetime-local', value: deadline.value, 'aria-label': '维护截止时间（上海）',
                            disabled: busy.value, onInput: e => { deadline.value = e.target.value; deadlineDirty = true; deadlineError.value = ''; },
                            class: 'border rounded p-2', style: 'min-height:44px;max-width:100%;',
                        })]),
                        button('保存截止时间', saveDeadline, 'maintenance-save-deadline'),
                        button('取消截止时间', () => { deadline.value = ''; deadlineError.value = ''; return mutate('deadline', null); }, 'maintenance-clear-deadline'),
                    ]),
                    h('div', { class: 'flex flex-wrap gap-3 items-center mb-3' }, [
                        label('h3', '维护任务', 'font-semibold'),
                        h('select', { value: filter.value, 'aria-label': '维护任务状态筛选', class: 'border rounded p-2',
                            onChange: e => { filter.value = e.target.value; } }, [
                            h('option', { value: 'all' }, '全部状态'),
                            ...Object.entries(STATE).map(([key, value]) => h('option', { value: key }, value)),
                        ]),
                        label('span', '任务完成必须逐条取得验收证据；待确认运营任务不会自动派发。', 'text-xs text-gray-500'),
                    ]),
                    rows.value.length === 0 ? label('p', '当前筛选范围没有已保存任务。', 'text-gray-600', { 'data-testid': 'maintenance-empty' })
                        : h('div', { style: 'max-width:100%;overflow-x:auto;' }, [
                            h('table', { class: 'w-full text-sm', style: 'min-width:620px;', 'data-testid': 'maintenance-tasks' }, [
                                h('thead', {}, h('tr', {}, ['任务 / 范围', '状态 / 优先级', '依据与验收', '结果 / 恢复'].map(text =>
                                    h('th', { class: 'text-left border-b p-3' }, text)))),
                                h('tbody', {}, rows.value.map(task => h('tr', { key: task.id, 'data-task-id': task.id }, [
                                    h('td', { class: 'align-top p-3 border-b', style: 'max-width:260px;overflow-wrap:anywhere;' }, [
                                        label('strong', task.title), label('p', task.id, 'text-xs text-gray-500 mt-1'),
                                        label('p', task.kind === 'operation' ? '酒店 ' + task.scope.hotel_id + ' / ' + task.scope.platform + ' / ' + task.scope.business_date
                                            : '本地研发任务', 'text-xs mt-1'),
                                    ]),
                                    h('td', { class: 'align-top p-3 border-b' }, [
                                        label('strong', STATE[task.state] || '旧状态待核实'),
                                        label('p', task.priority + ' · 执行 ' + (task.attempts ?? 0) + ' 次', 'text-xs mt-1'),
                                    ]),
                                    h('td', { class: 'align-top p-3 border-b', style: 'max-width:350px;overflow-wrap:anywhere;' }, [
                                        h('details', {}, [
                                            label('summary', '查看问题依据与验收条件', 'cursor-pointer'),
                                            ...(task.evidence || []).map(text => label('p', text, 'text-xs mt-2')),
                                            ...(task.acceptance || []).map(text => label('p', '验收：' + text, 'text-xs mt-2')),
                                            label('p', task.suggested_action || '', 'text-xs mt-2'),
                                        ]),
                                    ]),
                                    h('td', { class: 'align-top p-3 border-b', style: 'max-width:350px;overflow-wrap:anywhere;' }, [
                                        label('p', task.result?.summary || task.blocked_reason || task.checkpoint?.note || '尚无完成回执'),
                                        task.result?.tiers ? label('p', Object.entries(TIERS).map(([key, text]) =>
                                            text + '：' + (task.result.tiers[key] === true ? '已验证' : '未验证')).join('；'), 'text-xs text-gray-500 mt-2') : null,
                                        label('p', task.result?.next_step || task.checkpoint?.next_step || '', 'text-xs text-gray-600 mt-2'),
                                    ]),
                                ]))),
                            ]),
                        ]),
                    label('h3', '下一项候选与复核依据', 'font-semibold mt-5 mb-2'),
                    ...Object.values(data.value.candidates || {}).map(candidate => h('div', { class: 'text-sm border-t py-2', 'data-testid': 'maintenance-candidate' }, [
                        label('strong', candidate.title + ' · ' + (CANDIDATE[candidate.state] || '旧状态待核对')),
                        label('p', candidate.next_step, 'text-sm mt-1'),
                        label('p', '限定路径：' + (candidate.target_paths || []).join('、'), 'text-xs text-gray-600 mt-1'),
                        ...(candidate.evidence || []).map(text => label('p', text, 'text-xs text-gray-500 mt-1')),
                    ])),
                    label('h3', '实际唤醒与等待回执', 'font-semibold mt-5 mb-2'),
                    !Object.keys(data.value.runs || {}).length ? label('p', '尚无已登记的逐轮回执；不能把配置启用当成执行成功', 'text-sm text-gray-600') : null,
                    ...Object.values(data.value.runs || {}).sort((a, b) => b.triggered_at.localeCompare(a.triggered_at)).slice(0, 8).map(run => h('div', { class: 'text-sm border-t py-2', 'data-testid': 'maintenance-run' }, [
                        label('strong', run.triggered_at + ' · ' + (run.trigger === 'scheduled' ? '定时唤醒' : '手工触发') + ' · ' + (RUN[run.outcome] || run.outcome)),
                        label('p', '实际观察：' + run.observed_at + (run.delayed_registration ? '；空闲后延后登记于 ' + run.registered_at : ''), 'text-xs text-gray-600 mt-1'),
                        label('p', run.evidence, 'text-xs mt-1'),
                        label('p', '下一步：' + run.next_step + (run.resume_condition ? '；恢复条件：' + run.resume_condition : ''), 'text-xs mt-1'),
                    ])),
                    label('h3', '每日汇总（上海时间）', 'font-semibold mt-5 mb-2'),
                    ...Object.values(data.value.daily_summaries || {}).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 7).map(day =>
                        h('div', { class: 'text-sm border-t py-2', 'data-testid': 'maintenance-daily-summary' }, [
                            label('strong', day.date + ' · 已验收 ' + day.completed_task_ids.length + ' 项'),
                            label('p', '待处理任务：' + (day.waiting_task_ids.length ? day.waiting_task_ids.join('、') : '无已登记待处理项'), 'text-xs text-gray-600 mt-1'),
                            label('p', '已验收任务：' + (day.completed_task_ids.length ? day.completed_task_ids.join('、') : '本日暂无验收完成回执'), 'text-xs text-gray-600 mt-1'),
                            day.run_counts ? label('p', '轮次回执：' + Object.entries(day.run_counts).map(([key, count]) => (RUN[key] || key) + ' ' + count).join('、'), 'text-xs text-gray-600 mt-1') : null,
                        ])),
                ]),
            ]);
        },
    });
    window.SUXI_MAINTENANCE_WORKBENCH = Object.freeze({ create });
})();
