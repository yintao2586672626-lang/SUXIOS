(() => {
    'use strict';
    const labels = { legacy_unconfigured: '待补任务条件', pending: '待开始', in_progress: '进行中', completed: '完成填报', returned: '已退回', reopened: '已重开', blocked: '已阻塞', manual_verified: '人工已核实', reviewed: '已复盘', unestablished: '效果未成立' };
    const lines = value => String(value || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    const clone = value => JSON.parse(JSON.stringify(value));
    const requestId = () => `workflow:${globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`}`;
    const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const create = ({ h }) => ({
        name: 'OperationTaskWorkflowPanel',
        props: { hotelId: [String, Number], request: Function, context: Object, canExecute: { type: Boolean, default: false } },
        data: () => ({ items: [], selected: null, selectedId: 0, lookupId: '', loading: false, saving: false, error: '', message: '', epoch: 0, readSequence: 0, listSequence: 0, pending: null, form: {}, mode: '', truncated: false }),
        watch: {
            hotelId: { immediate: true, handler() { this.resetScope(); } },
            context: { deep: true, handler() { this.resetScope(); } },
        },
        beforeUnmount() { this.epoch++; this.readSequence++; },
        methods: {
            resetScope() { this.epoch++; this.readSequence++; this.items = []; this.selected = null; this.selectedId = 0; this.pending = null; this.saving = false; this.error = ''; this.message = ''; this.mode = ''; this.load(); },
            async response(url, options = {}) {
                const res = await this.request(url, options);
                if (Number(res?.code) !== 200 || !res?.data) { const error = new Error(res?.message || res?.msg || '任务请求失败'); error.code = Number(res?.code); throw error; }
                return res.data;
            },
            async load() {
                const hotel = Number(this.hotelId); const epoch = this.epoch, sequence = ++this.listSequence;
                if (!(hotel > 0) || typeof this.request !== 'function') { this.loading = false; return; }
                this.loading = true; this.error = '';
                try {
                    const data = await this.response(`/operation/task-workflows?hotel_id=${hotel}`, { businessContext: { hotelId: hotel } });
                    if (epoch !== this.epoch || sequence !== this.listSequence) return;
                    if (Number(data.scope?.hotel_id) !== hotel || !Array.isArray(data.items) || data.items.some(row => Number(row.scope?.hotel_id) !== hotel || Number(row.scope?.tenant_id) !== Number(data.scope?.tenant_id))) throw new Error('任务列表范围不匹配');
                    this.items = data.items; this.truncated = data.truncated === true;
                } catch (error) { if (epoch === this.epoch && sequence === this.listSequence) this.error = error.message; }
                finally { if (epoch === this.epoch && sequence === this.listSequence) this.loading = false; }
            },
            async select(id, version = null) {
                if (!Number.isSafeInteger(Number(id)) || Number(id) < 1) { this.error = '请输入有效任务编号'; return; }
                if (this.pending && Number(id) !== this.pending.taskId) { this.error = '请先确认原任务的保存结果'; return; }
                const epoch = this.epoch, sequence = ++this.readSequence, hotel = Number(this.hotelId);
                this.selectedId = Number(id); this.selected = null; this.mode = ''; this.error = '';
                try {
                    const data = await this.response(`/operation/execution-tasks/${id}/workflow?hotel_id=${hotel}${version ? `&version=${version}` : ''}`, { businessContext: { hotelId: hotel } });
                    if (epoch !== this.epoch || sequence !== this.readSequence) return;
                    this.assertScope(data, id, hotel);
                    if (version !== null && Number(data.version) !== Number(version)) throw new Error('历史版本回读不匹配');
                    this.selected = data; this.fillForm();
                } catch (error) { if (epoch === this.epoch && sequence === this.readSequence) this.error = error.message; }
            },
            assertScope(data, id, hotel) {
                if (Number(data?.task_id) !== Number(id) || Number(data?.scope?.hotel_id) !== hotel || !(Number(data?.scope?.tenant_id) > 0) || !data?.readback_verified) throw new Error('任务回读身份或版本未核实');
                const expected = this.items.find(row => Number(row.task_id) === Number(id));
                if (expected && ['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end'].some(key => String(expected.scope[key]) !== String(data.scope[key]))) throw new Error('任务范围与列表不匹配，请重新读取列表');
            },
            fillForm() {
                const s = this.selected, p = s?.source?.proposal || {}, scope = s?.scope || {};
                const w = s?.review_window || p.review_window || {};
                this.form = { type: s?.workflow_type || p.workflow_type || 'conversion_optimization', object: scope.object_ref || p.evidence_snapshot?.scope?.object_ref || '',
                    assignee: s?.assignee_id || '', due: s?.due_date || scope.date_end || '', criteria: (s?.completion_criteria?.length ? s.completion_criteria : p.completion_criteria || []).join('\n'),
                    dependencies: (s?.dependencies || []).join(','), ...w, reason: '', kind: 'manual_check', reference: '', note: '', performed: today(), checks: [],
                    metric: '', unit: 'percent', before: '', after: '', beforeRef: '', afterRef: '', human: false };
            },
            async send(action, extra = {}, retry = false) {
                if (this.saving || !this.canExecute || !this.selected || this.selected.historical) return;
                const epoch = this.epoch, hotel = Number(this.hotelId), id = this.selected.task_id;
                if (this.pending && !retry) { this.error = '上次保存结果待确认，请先回读或重试原提交。'; return; }
                const body = retry ? clone(this.pending.body) : { hotel_id: hotel, request_id: requestId(), expected_version: this.selected.version, action, ...clone(extra) };
                if (retry && this.pending.taskId !== id) return;
                this.pending = { taskId: id, body }; this.saving = true; this.error = ''; this.message = '';
                try {
                    const result = await this.response(`/operation/execution-tasks/${id}/workflow`, { method: 'POST', body: JSON.stringify(body), businessContext: { hotelId: hotel } });
                    if (epoch !== this.epoch) return;
                    this.assertScope(result.workflow, id, hotel);
                    this.selected = result.workflow; this.pending = null; this.mode = ''; this.fillForm();
                    this.message = result.replayed ? '已回读原提交，未重复写入。' : `已保存并回读版本 ${result.saved_version}。`;
                    await this.load();
                } catch (error) {
                    if (epoch !== this.epoch) return;
                    this.error = error.message;
                    if ([400, 401, 403, 404, 409, 422].includes(error.code)) this.pending = null;
                    if (error.code === 409) this.error += '；请读取最新版本后重新操作。';
                } finally { if (epoch === this.epoch) this.saving = false; }
            },
            async recover() {
                const pending = this.pending, epoch = this.epoch;
                await this.select(this.selectedId);
                if (epoch !== this.epoch || !pending || !this.selected) return;
                if (this.selected.history.some(row => row.request_id === pending.body.request_id)) {
                    this.pending = null; this.message = '已从历史核实上次保存成功，没有重复提交。';
                } else this.message = '未找到原提交，可使用同一请求重试；结果仍以回读为准。';
            },
            configure() {
                const f = this.form;
                this.send('configure', { workflow_type: f.type, scope: { ...this.selected.scope, object_ref: f.object }, assignee_id: Number(f.assignee), due_date: f.due,
                    completion_criteria: lines(f.criteria), dependencies: f.dependencies.trim() ? f.dependencies.split(',').map(v => Number(v.trim())) : [],
                    review_window: { baseline_start: f.baseline_start || '', baseline_end: f.baseline_end || '', followup_start: f.followup_start || '', followup_end: f.followup_end || '' } });
            },
            record() { const f = this.form; this.send('record', { record: { scope: this.selected.scope, kind: f.kind, reference: f.reference, note: f.note, performed_on: f.performed, checks: f.checks } }); },
            review() {
                const f = this.form, s = this.selected;
                const observation = period => ({ scope: { ...s.scope, date_start: s.review_window[`${period}_start`], date_end: s.review_window[`${period}_end`] },
                    metric: f.metric, unit: f.unit, value: period === 'baseline' ? f.before : f.after, reference: period === 'baseline' ? f.beforeRef : f.afterRef });
                const review = { note: f.note };
                if (f.before !== '' || f.after !== '') { review.before = observation('baseline'); review.after = observation('followup'); }
                this.send('review', { causality_claimed: false, review });
            },
        },
        render() {
            const s = this.selected, f = this.form;
            const field = (label, key, type = 'text') => h('label', { class: 'block text-sm text-slate-700' }, [label, h('input', { type, value: f[key] || '', 'aria-label': label, class: 'mt-1 w-full rounded-lg border border-slate-300 p-2', onInput: e => { f[key] = e.target.value; } })]);
            const area = (label, key) => h('label', { class: 'block text-sm text-slate-700' }, [label, h('textarea', { value: f[key], rows: 3, 'aria-label': label, class: 'mt-1 w-full rounded-lg border border-slate-300 p-2', onInput: e => { f[key] = e.target.value; } })]);
            const select = (label, key, options) => h('label', { class: 'block text-sm text-slate-700' }, [label, h('select', { value: f[key], 'aria-label': label, class: 'mt-1 w-full rounded-lg border border-slate-300 p-2', onChange: e => { f[key] = e.target.value; } }, options.map(([value, title]) => h('option', { value }, title)))]);
            const button = (label, action, disabled = false) => h('button', { type: 'button', disabled: disabled || this.saving, class: 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 disabled:opacity-50', onClick: action }, label);
            const checks = () => h('div', { class: 'space-y-2' }, (s?.completion_criteria || []).map(c => h('label', { class: 'flex gap-2 text-sm' }, [h('input', { type: 'checkbox', checked: f.checks.includes(c), onChange: e => { f.checks = e.target.checked ? [...f.checks, c] : f.checks.filter(v => v !== c); } }), c])));
            const modes = [];
            if (s && this.mode === 'configure') modes.push(select('工作流类型', 'type', [['conversion_optimization', '转化优化'], ['price_check', '价格检查'], ['service_remediation', '服务问题整改']]), field('执行对象（房型、页面或服务问题编号）', 'object'), field('负责人用户编号', 'assignee', 'number'), field('截止日期', 'due', 'date'), area('逐项完成条件（每行一条）', 'criteria'), field('前置任务编号（逗号分隔）', 'dependencies'), ...['baseline_start', 'baseline_end', 'followup_start', 'followup_end'].map((key, i) => field(['前窗起日', '前窗止日', '后窗起日', '后窗止日'][i], key, 'date')), button('保存任务条件', () => this.configure()));
            if (s && this.mode === 'record') modes.push(select('材料类型', 'kind', [['manual_check', '逐项人工核查'], ['screenshot', '截图（待核实）'], ['receipt', '回执（待核实）']]), field('实际执行日期（区别于业务日）', 'performed', 'date'), field('材料引用', 'reference'), area('执行记录', 'note'), f.kind === 'manual_check' ? checks() : h('p', '截图或回执不自动判为已执行、已批准或有效。'), button('保存执行材料', () => this.record()));
            if (s && this.mode === 'reschedule_review') modes.push(area('调整复盘窗口原因', 'reason'), ...['baseline_start', 'baseline_end', 'followup_start', 'followup_end'].map((key, i) => field(['前窗起日', '前窗止日', '后窗起日', '后窗止日'][i], key, 'date')), button('保存复盘窗口', () => this.send('reschedule_review', { reason: f.reason, review_window: { baseline_start: f.baseline_start, baseline_end: f.baseline_end, followup_start: f.followup_start, followup_end: f.followup_end } })));
            if (s && this.mode === 'complete') modes.push(h('p', '逐项确认完成填报。执行是否属实仍需人工核实。'), checks(), button('确认完成填报', () => this.send('complete', { completed_criteria: s.completion_criteria.filter(c => f.checks.includes(c)) })));
            if (s && this.mode === 'verify') modes.push(area('核实说明', 'reason'), h('label', { class: 'flex gap-2 text-sm' }, [h('input', { type: 'checkbox', checked: f.human, onChange: e => { f.human = e.target.checked; } }), '我已人工核对同对象执行与全部完成条件']), button('保存人工核实', () => this.send('verify', { human_confirmed: f.human, reason: f.reason }), !f.human));
            if (s && ['return', 'reopen', 'block', 'postpone'].includes(this.mode)) modes.push(area('操作原因', 'reason'), this.mode === 'postpone' ? field('新截止日期', 'due', 'date') : null, button('保存状态变更', () => this.send(this.mode, { reason: f.reason, ...(this.mode === 'postpone' ? { due_date: f.due } : {}) })));
            if (s && this.mode === 'review') modes.push(h('p', '人工前后观察；两个数均留空表示证据缺失。时间窗不同不计算变化，任何变化都不证明因果效果。'), field('指标定义', 'metric'), select('指标单位', 'unit', [['percent', '百分数（0至100）'], ['ratio', '比例（0至1）'], ['CNY', '元'], ['count', '次数'], ['score', '评分'], ['minutes', '分钟']]), field('前窗数值', 'before', 'number'), field('后窗数值', 'after', 'number'), field('前窗来源引用', 'beforeRef'), field('后窗来源引用', 'afterRef'), area('复盘结论与其他影响因素', 'note'), button('保存复盘观察', () => this.review()));
            const canWrite = this.canExecute && s && !s.historical && !this.pending;
            return h('section', { 'data-testid': 'operation-task-workflow', class: 'rounded-2xl border border-slate-200 bg-white p-5 space-y-4' }, [
                h('div', { class: 'flex flex-wrap justify-between gap-3' }, [h('div', [h('h3', { class: 'font-bold text-slate-900' }, '任务安排、执行核实与复盘'), h('p', { class: 'mt-1 text-sm text-slate-600' }, '该酒店全部任务，事项按各自原始日期与平台。任务完成、执行核实、效果成立分别记录。')]), button('刷新任务列表', () => this.load(), this.loading)]),
                !Number(this.hotelId) ? h('p', { role: 'status' }, '请选择一个酒店查看任务。') : null,
                this.loading ? h('p', { role: 'status' }, '正在读取原任务…') : null,
                this.error ? h('p', { role: 'alert', class: 'rounded-lg bg-rose-50 p-3 text-sm text-rose-800' }, this.error) : null,
                this.message ? h('p', { role: 'status', class: 'text-sm text-emerald-800' }, this.message) : null,
                !this.loading && !this.error && Number(this.hotelId) && !this.items.length ? h('p', '当前酒店暂无执行任务。可从运营机会、诊断建议或周计划进入原事项，完成所需人工审批后回到这里。') : null,
                this.truncated ? h('p', { role: 'status' }, '当前显示最新100项。更早任务可输入原任务编号精确读取。') : null,
                h('div', { class: 'flex flex-wrap gap-2 items-end' }, [h('label', { class: 'text-sm' }, ['按原任务编号读取（含更早任务）', h('input', { type: 'number', min: 1, value: this.lookupId, 'aria-label': '原任务编号', class: 'ml-2 rounded-lg border border-slate-300 p-2', onInput: e => { this.lookupId = e.target.value; } })]), button('读取指定任务', () => this.select(Number(this.lookupId)), !Number(this.hotelId))]),
                h('div', { class: 'grid gap-2 md:grid-cols-2' }, this.items.map(item => button(`#${item.task_id} ${labels[item.task_status] || item.task_status} · ${item.scope.platform} · ${item.scope.date_start}—${item.scope.date_end}`, () => this.select(item.task_id)))),
                s ? h('div', { class: 'border-t border-slate-200 pt-4 space-y-4', 'data-testid': 'workflow-detail' }, [
                    h('h4', { class: 'font-semibold' }, `任务 #${s.task_id} · 原意图 #${s.intent_id} · 版本 ${s.version}${s.historical ? '（历史）' : ''}`),
                    h('p', { class: 'text-sm break-words' }, `酒店 ${s.scope.hotel_id} / 租户 ${s.scope.tenant_id} / ${s.scope.platform} / ${s.scope.date_start}—${s.scope.date_end} / 对象 ${s.scope.object_ref || '待补'} / 负责人 ${s.assignee_id || '待定'} / 截止 ${s.due_date || '待定'}`),
                    h('p', { class: 'text-sm' }, `原审批：${s.approval_status}；来源：${s.source.module}；建议关联：${s.source.proposal?.recommendation_id || '原事项'}（仅关联）`),
                    h('p', { class: 'rounded-lg bg-slate-50 p-3 text-sm' }, `任务：${labels[s.task_status] || s.task_status}；执行核实：${labels[s.verification.status] || '待核实'}；复盘：${labels[s.review.status] || '待复盘'}；效果未建立因果关系`),
                    h('p', { class: 'text-sm font-medium text-emerald-900' }, `下一步：${s.next_step.label}`),
                    s.next_step.overdue ? h('p', { class: 'text-sm text-amber-800' }, '已逾期，请补充阻塞原因或调整期限。') : null,
                    (s.blocked_reason || s.schedule_reason || s.review_schedule_reason) ? h('p', { class: 'text-sm break-words' }, `操作说明：${[s.blocked_reason, s.schedule_reason, s.review_schedule_reason].filter(Boolean).join('；')}`) : null,
                    s.review_window ? h('p', { class: 'text-sm' }, `复盘前窗 ${s.review_window.baseline_start}—${s.review_window.baseline_end}；后窗 ${s.review_window.followup_start}—${s.review_window.followup_end}（上海时间）`) : null,
                    s.dependencies.length ? h('p', { class: 'text-sm' }, `前置任务：${s.dependencies.map(id => '#' + id).join('、')}`) : null,
                    h('ul', { class: 'space-y-1 text-sm' }, s.execution_records.map((r, index) => h('li', { class: 'break-words' }, `${index + 1}. ${r.performed_on} · ${r.kind} · ${r.note} · ${r.reference}`))),
                    s.review.status === 'reviewed' ? h('p', { class: 'text-sm', 'data-testid': 'workflow-review-result' }, `${s.review.note}；${s.review.reason}；变化：${s.review.delta === null ? '未计算' : s.review.delta + ' ' + s.review.delta_unit}`) : null,
                    h('div', { class: 'flex flex-wrap gap-2' }, [button('读取最新版本', () => this.select(s.task_id)), ...s.history.map(row => button(`历史 v${row.version}`, () => this.select(s.task_id, row.version)))]),
                    this.pending ? h('div', { class: 'space-x-2' }, [h('p', '保存结果待确认；请保留原请求恢复。'), button('回读确认保存', () => this.recover()), button('重试原提交', () => this.send('', {}, true), this.pending.taskId !== s.task_id)]) : null,
                    canWrite ? h('div', { class: 'flex flex-wrap gap-2' }, [
                        ...((s.version === 0 || ['pending', 'returned', 'reopened'].includes(s.task_status)) ? [button('设置任务条件', () => { this.mode = 'configure'; })] : []),
                        ...(s.version > 0 ? [button('开始或恢复', () => this.send('start'), !['pending', 'returned', 'reopened', 'blocked'].includes(s.task_status)),
                            ...[['record', '登记执行材料'], ['complete', '完成填报'], ['verify', '人工核实'], ['review', '复盘观察'], ['reschedule_review', '调整复盘窗口'], ['block', '登记阻塞'], ['return', '退回补充'], ['postpone', '延期'], ['reopen', '重开任务']].map(([mode, label]) => {
                                const allowed = { record: s.task_status === 'in_progress', complete: s.task_status === 'in_progress', verify: s.task_status === 'completed', review: s.task_status === 'completed' && s.verification.status === 'manual_verified', block: s.task_status !== 'completed', return: ['in_progress','completed','blocked'].includes(s.task_status), postpone: s.task_status !== 'completed', reopen: s.task_status === 'completed', reschedule_review: true };
                                return button(label, () => { this.fillForm(); this.mode = mode; }, !allowed[mode]);
                            })] : []),
                    ]) : null,
                    canWrite && modes.length ? h('div', { class: 'grid gap-3 rounded-xl border border-slate-200 p-4 md:grid-cols-2', 'data-testid': 'workflow-form' }, modes) : null,
                ]) : null,
            ]);
        },
    });
    window.SUXI_TASK_WORKFLOW_PANEL = Object.freeze({ create });
})();
