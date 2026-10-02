(() => {
    'use strict';
    const { h } = window.Vue;
    const statuses = { pending_diagnosis: '待核实原因', planned: '待执行', in_progress: '执行中', awaiting_review: '待复查', awaiting_evidence: '待补证据', needs_followup: '继续跟进', completed: '已达计划目标', cancelled: '已取消' };
    const causes = { unknown: '原因待核实', knowledge: '知识标准不清楚', skill: '操作不熟练', execution: '责任或执行不到位', objective: '流程、资源或工具障碍' };
    const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const requestId = () => globalThis.crypto?.randomUUID?.() || `coach-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const blank = () => ({ title: '', cause: 'unknown', cause_basis: '', objective: '', steps: '', acceptance_criteria: '', responsible_name: '', business_date: today(), due_on: today(), review_on: today(), minimum_samples: 1, knowledge_chunk_ids: [] });
    const buttonClass = 'min-h-[44px] rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 disabled:opacity-50';
    const inputClass = 'w-full min-w-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900';
    const component = {
        name: 'ManagerCoachingPanel',
        props: { hotelId: { required: true }, managerId: { required: true }, request: { type: Function, required: true }, cases: { type: Array, default: () => [] }, canManage: Boolean },
        data: () => ({ plans: [], selected: null, events: [], form: blank(), caseId: '', action: '', record: {}, error: '', notice: '', busy: false, loading: false, epoch: 0, listSequence: 0, readSequence: 0, searchSequence: 0, referencePending: 0, saveKey: '', pendingPayload: '', search: '', sources: [], references: [], searching: false }),
        computed: { scopeKey() { return `${this.hotelId}:${this.managerId}`; } },
        watch: { scopeKey: { immediate: true, handler() { this.epoch++; this.plans = []; this.selected = null; this.events = []; this.action = ''; this.form = blank(); this.references = []; this.referencePending = 0; this.sources = []; this.error = ''; this.notice = ''; this.busy = false; this.saveKey = ''; void this.load(); } } },
        beforeUnmount() { this.epoch++; },
        methods: {
            context() { return { epoch: this.epoch, scope: this.scopeKey, hotel: Number(this.hotelId), manager: Number(this.managerId) }; },
            current(c) { return c.epoch === this.epoch && c.scope === this.scopeKey; },
            query(c) { return `hotel_id=${c.hotel}&manager_user_id=${c.manager}`; },
            async load() {
                const c = this.context(); if (!c.hotel || !c.manager) return;
                const sequence = ++this.listSequence;
                const current = () => this.current(c) && sequence === this.listSequence;
                this.loading = true;
                try {
                    const r = await this.request(`/operation/manager-capability/coaching?${this.query(c)}`);
                    if (!current()) return;
                    if (r.code !== 200 || Number(r.data?.hotel_id) !== c.hotel || Number(r.data?.manager_user_id) !== c.manager) throw new Error(r.message || '计划列表范围不一致');
                    if (!Array.isArray(r.data.list) || r.data.list.some(plan => Number(plan.hotel_id) !== c.hotel || Number(plan.manager_user_id) !== c.manager)) throw new Error('计划列表格式或范围不一致');
                    this.plans = r.data.list;
                } catch (e) { if (current()) this.error = e.message || '带教计划加载失败'; }
                finally { if (current()) this.loading = false; }
            },
            assertDetail(response, id, c) {
                if (response.code !== 200 || Number(response.data?.plan?.id) !== Number(id)
                    || Number(response.data?.plan?.hotel_id) !== c.hotel || Number(response.data?.plan?.manager_user_id) !== c.manager
                    || !Array.isArray(response.data?.events)) throw new Error(response.message || '计划读取身份或范围不一致');
            },
            async open(plan) {
                if (this.busy) return;
                this.epoch++; this.loading = false; this.busy = false; this.searching = false;
                this.action = ''; this.selected = null; this.events = [];
                const c = this.context(); this.error = '';
                const sequence = ++this.readSequence;
                this.referencePending = 0;
                const current = () => this.current(c) && sequence === this.readSequence;
                try {
                    const r = await this.request(`/operation/manager-capability/coaching/${plan.id}?${this.query(c)}`);
                    if (!current()) return;
                    this.assertDetail(r, plan.id, c);
                    this.selected = r.data.plan; this.events = r.data.events; this.action = ''; this.notice = '';
                } catch (e) { if (current()) this.error = e.message; }
            },
            start() {
                if (this.busy) return;
                this.epoch++; this.loading = false; this.busy = false; this.searching = false;
                this.readSequence++; this.searchSequence++;
                this.referencePending = 0;
                this.selected = null; this.events = []; this.form = blank(); this.caseId = String(this.cases.find(c => !c.is_voided)?.id || '');
                this.action = 'create'; this.error = ''; this.references = []; this.sources = []; this.saveKey = ''; this.pendingPayload = '';
            },
            begin(action) {
                if (this.busy) return;
                this.readSequence++; this.searchSequence++;
                this.referencePending = 0;
                this.action = action; this.error = ''; this.notice = ''; this.saveKey = ''; this.pendingPayload = '';
                this.record = { stage: 'practiced', observed_on: today(), sample_count: '', evidence_ref: '', note: '', conclusion: 'insufficient', next_review_on: '', criteria_confirmed: false, title: '', summary: '', steps: '', applicability: '', stop_conditions: '' };
                if (action === 'edit') {
                    this.form = JSON.parse(JSON.stringify(this.selected.content));
                    this.references = [...(this.form.knowledge_snapshots || [])];
                    this.form.knowledge_chunk_ids = this.references.map(s => Number(s.chunk_id));
                    this.caseId = String(this.selected.case_id);
                }
            },
            async searchKnowledge() {
                const c = this.context(); this.searching = true; this.error = '';
                const sequence = ++this.searchSequence, keyword = this.search, draftSequence = this.readSequence;
                const current = () => this.current(c) && sequence === this.searchSequence && keyword === this.search && draftSequence === this.readSequence;
                try {
                    const r = await this.request(`/knowledge/list?hotel_id=${c.hotel}&keyword=${encodeURIComponent(this.search)}&page=1&page_size=20`);
                    if (!current()) return;
                    if (![0, 200].includes(r.code)) throw new Error(r.message || r.msg || '知识检索失败');
                    const units = r.data?.list ?? r.data?.items;
                    if (!Array.isArray(units)) throw new Error('知识列表格式不完整，不能确认为无资料');
                    const options = [];
                    for (const unit of units.slice(0, 12)) {
                        const detail = await this.request(`/knowledge/${unit.unit_id}`);
                        if (!current()) return;
                        if (![0, 200].includes(detail.code)) continue;
                        const chunk = (detail.data?.chunks || []).find(x => Number(x.chunk_id) === Number(unit.current_chunk_id))
                            || (detail.data?.chunks || []).filter(x => (x.lifecycle_status || 'active') === 'active').at(-1);
                        if (chunk) options.push({ title: unit.name, chunk_id: Number(chunk.chunk_id) });
                    }
                    this.sources = options;
                } catch (e) { if (current()) this.error = e.message; }
                finally { if (this.current(c) && sequence === this.searchSequence) this.searching = false; }
            },
            async addReference(item) {
                if (this.busy) return;
                const c = this.context();
                const draftSequence = this.readSequence;
                const action = this.action;
                const currentDraft = () => this.current(c) && draftSequence === this.readSequence;
                const current = () => currentDraft() && action === this.action && !this.busy;
                this.referencePending++; this.error = '';
                try {
                    const r = await this.request(`/knowledge/reference-sources/${item.chunk_id}?hotel_id=${c.hotel}`);
                    if (!current()) return;
                    if (r.code !== 200 || Number(r.data?.chunk_id) !== Number(item.chunk_id)) throw new Error(r.message || '知识来源不可用或编号不一致');
                    if (!this.references.some(s => Number(s.chunk_id) === Number(item.chunk_id))) this.references.push(r.data);
                    this.form.knowledge_chunk_ids = this.references.map(s => Number(s.chunk_id));
                } catch (e) { if (current()) this.error = e.message; }
                finally { if (currentDraft()) this.referencePending = Math.max(0, this.referencePending - 1); }
            },
            async save() {
                if (this.busy || this.referencePending > 0 || !this.canManage) return;
                const c = this.context(); const action = this.action; const selected = this.selected;
                const values = ['create', 'edit'].includes(action) ? { ...this.form, case_id: Number(this.caseId), minimum_samples: Number(this.form.minimum_samples) }
                    : { ...this.record, sample_count: this.record.sample_count === '' ? null : Number(this.record.sample_count) };
                const payload = { ...values, hotel_id: c.hotel, manager_user_id: c.manager,
                    ...(selected ? { expected_revision: Number(selected.revision) } : {}) };
                const fingerprint = JSON.stringify([action, payload]);
                if (!this.saveKey || this.pendingPayload !== fingerprint) { this.saveKey = requestId(); this.pendingPayload = fingerprint; }
                payload.idempotency_key = this.saveKey;
                const url = action === 'create' ? '/operation/manager-capability/coaching' : `/operation/manager-capability/coaching/${selected.id}/${action}`;
                this.busy = true; this.error = ''; this.notice = '';
                try {
                    const r = await this.request(url, { method: 'POST', body: JSON.stringify(payload) });
                    if (!this.current(c)) return;
                    if (r.code !== 200) throw new Error(r.message || '操作失败');
                    const saved = r.data?.plan;
                    const exact = await this.request(`/operation/manager-capability/coaching/${saved?.id}?${this.query(c)}`);
                    if (!this.current(c)) return;
                    const read = exact.data?.plan;
                    if (exact.code !== 200 || !read || Number(read.id) !== Number(saved.id) || Number(read.hotel_id) !== c.hotel
                        || Number(read.manager_user_id) !== c.manager || Number(read.revision) !== Number(saved.revision)
                        || read.content_digest !== saved.content_digest || JSON.stringify(read.content) !== JSON.stringify(saved.content)
                        || JSON.stringify(exact.data.events) !== JSON.stringify(r.data.events)) throw new Error('保存结果与独立回读不一致，请刷新核对后重试');
                    this.selected = read; this.events = exact.data.events; this.action = ''; this.saveKey = ''; this.pendingPayload = '';
                    this.notice = '已保存并完成独立回读。记录为人工观察，未计入能力分或经营效果。';
                    await this.load();
                } catch (e) { if (this.current(c)) this.error = `${e.message || '操作失败'}。表单内容已保留。`; }
                finally { if (this.current(c)) this.busy = false; }
            },
            async refreshKeepingDraft() {
                const c = this.context(); if (!this.selected || this.busy) return;
                const id = this.selected.id, sequence = ++this.readSequence;
                this.referencePending = 0;
                const current = () => this.current(c) && sequence === this.readSequence && this.selected?.id === id;
                try {
                    const r = await this.request(`/operation/manager-capability/coaching/${id}?${this.query(c)}`);
                    if (!current()) return;
                    this.assertDetail(r, id, c);
                    this.selected = r.data.plan; this.events = r.data.events;
                    this.notice = '已读取最新记录，表单草稿保留。请比较下方目标和事件后再提交。';
                } catch (e) { if (current()) this.error = e.message || '读取最新版本失败'; }
            },
            field(obj, key, label, type = 'text', options = null) {
                return h('label', { class: 'block min-w-0 space-y-1' }, [h('span', { class: 'text-sm font-medium text-slate-700' }, label),
                    options ? h('select', { class: inputClass, disabled: this.busy, value: obj[key], onChange: e => obj[key] = e.target.value, 'aria-label': label }, Object.entries(options).map(([value, text]) => h('option', { value }, text)))
                    : type === 'checkbox' ? h('input', { type, disabled: this.busy, checked: obj[key] === true, onChange: e => obj[key] = e.target.checked, 'aria-label': label })
                    : h(type === 'textarea' ? 'textarea' : 'input', { class: inputClass, type: type === 'textarea' ? undefined : type, rows: type === 'textarea' ? 3 : undefined,
                        disabled: this.busy, value: obj[key], onInput: e => obj[key] = e.target.value, 'aria-label': label })]);
            },
        },
        render() {
            const btn = (label, click, disabled = false) => h('button', { type: 'button', class: buttonClass, onClick: click, disabled: disabled || this.busy }, label);
            const plan = this.selected?.content;
            const edit = ['create', 'edit'].includes(this.action);
            const recordEvidence = ['evidence', 'review', 'recur'].includes(this.action);
            return h('section', { class: 'rounded-xl border border-slate-200 bg-white p-4 space-y-4', 'data-testid': 'manager-coaching-panel' }, [
                h('div', { class: 'flex flex-wrap items-center justify-between gap-3' }, [h('div', [h('h4', { class: 'text-base font-semibold text-slate-900' }, '案例带教与改进'), h('p', { class: 'mt-1 text-xs text-slate-600' }, '原因核实 → 实操证据 → 到期复查 → 参考经验')]), this.canManage ? btn('制定带教/改进计划', () => this.start(), !this.cases.some(c => !c.is_voided)) : null]),
                this.error ? h('div', { role: 'alert', class: 'rounded-lg bg-red-50 p-3 text-sm text-red-800' }, [this.error, this.action && this.selected ? btn('读取最新版本并保留草稿', () => this.refreshKeepingDraft()) : null]) : null,
                this.notice ? h('p', { role: 'status', class: 'text-sm text-emerald-800' }, this.notice) : null,
                this.loading ? h('p', '正在读取计划…') : this.plans.length ? h('div', { class: 'space-y-2' }, this.plans.map(p => h('button', { type: 'button', class: 'w-full rounded-lg border border-slate-200 p-3 text-left text-sm', onClick: () => this.open(p) }, [h('strong', p.content.title), h('div', { class: 'mt-1 text-xs text-slate-600' }, `${statuses[p.status] || p.status} · 复查 ${p.review_on}${p.overdue ? ' · 已逾期，需跟进' : ''}`)]))) : h('p', { class: 'text-sm text-slate-500' }, this.cases.length ? '暂无带教计划，可从已有管理案例开始。' : '先记录一个管理案例，再制定有依据的计划。'),
                plan ? h('div', { class: 'space-y-2 text-sm text-slate-700' }, [h('h5', { class: 'font-semibold' }, `${plan.title} · 第 ${this.selected.revision} 版`),
                    h('p', `${causes[plan.cause]} / ${plan.method} · 负责人：${plan.responsible_name}`),
                    h('p', `依据：${plan.cause_basis}`), h('p', `目标：${plan.objective}`),
                    h('p', { class: 'whitespace-pre-wrap' }, `步骤：${plan.steps}`), h('p', `验收：${plan.acceptance_criteria}；最低样本 ${plan.minimum_samples}`),
                    h('p', `截止 ${plan.due_on} · 复查 ${plan.review_on}`),
                    ...(plan.knowledge_snapshots || []).map(s => h('details', { class: 'rounded-lg bg-slate-50 p-2' }, [h('summary', `引用知识：${s.title} · 固定版本 ${s.chunk_id}`), h('pre', { class: 'whitespace-pre-wrap break-words text-xs' }, (s.source_segments || []).map(x => `${x.locator}：${x.quote}`).join('\n'))])),
                    this.canManage && !this.action ? h('div', { class: 'flex flex-wrap gap-2' }, this.selected.status === 'cancelled' ? [] : this.selected.status === 'completed'
                        ? [btn('记录复发', () => this.begin('recur')), btn('整理参考经验', () => this.begin('knowledge'))]
                        : [!this.events.some(e => e.event_type === 'evidence') ? btn('编辑计划', () => this.begin('edit')) : null,
                            btn('记录学习/实操', () => this.begin('evidence'), plan.cause === 'unknown'), btn('追加复查', () => this.begin('review')),
                            btn('延期补证据', () => this.begin('defer')), btn('取消计划', () => this.begin('cancel')),
                            this.events.some(e => e.event_type === 'review') ? btn('整理参考经验', () => this.begin('knowledge')) : null]) : null,
                    h('details', [h('summary', `过程记录（${this.events.length}）`), ...this.events.map(e => h('div', { class: 'border-b border-slate-100 py-2 text-xs' }, [
                        h('strong', `${({ created: '创建', edit: '修订', evidence: '实操', review: '复查', defer: '延期', cancel: '取消', recur: '复发', knowledge: '参考经验' })[e.event_type] || e.event_type} · ${e.created_at}`),
                        h('p', { class: 'whitespace-pre-wrap' }, e.payload?.note || e.payload?.plan?.objective || (e.payload?.knowledge_unit_id ? `知识单元 #${e.payload.knowledge_unit_id}（参考，未正式晋级）` : '')),
                        e.payload?.evidence_ref ? h('p', `证据：${e.payload.evidence_ref}；样本 ${e.payload.sample_count}`) : null,
                        e.payload?.conclusion ? h('p', `结论：${({ improved: '有改善', target_met: '达到目标', not_improved: '未改善', insufficient: '证据不足' })[e.payload.conclusion]}`) : null,
                    ]))]),
                ]) : null,
                this.action && this.canManage ? h('form', { class: 'space-y-3 rounded-xl border border-emerald-200 p-4', 'data-testid': 'coaching-form', onSubmit: e => { e.preventDefault(); void this.save(); } }, [
                    h('h5', { class: 'font-semibold' }, ({ create: '制定计划', edit: '编辑计划', evidence: '记录学习与实操', review: '追加复查', defer: '延期补证据', cancel: '取消计划', recur: '记录问题复发', knowledge: '整理脱敏参考经验' })[this.action]),
                    edit ? h('div', { class: 'space-y-3' }, [
                        this.action === 'create' ? this.field(this, 'caseId', '来源管理案例', 'select', Object.fromEntries(this.cases.filter(c => !c.is_voided).map(c => [c.id, `${c.business_date} · ${c.problem_facts.slice(0, 80)}`]))) : null,
                        this.field(this.form, 'title', '计划标题'), this.field(this.form, 'cause', '原因分类', 'select', causes),
                        this.field(this.form, 'cause_basis', '原因依据或待核实问题', 'textarea'), this.field(this.form, 'objective', '目标行为', 'textarea'),
                        this.field(this.form, 'steps', '执行步骤', 'textarea'), this.field(this.form, 'acceptance_criteria', '验收标准', 'textarea'), this.field(this.form, 'responsible_name', '带教或整改负责人'),
                        h('div', { class: 'grid grid-cols-1 gap-3 sm:grid-cols-3' }, [this.field(this.form, 'business_date', '起始日期', 'date'), this.field(this.form, 'due_on', '截止日期', 'date'), this.field(this.form, 'review_on', '复查日期', 'date')]),
                        this.field(this.form, 'minimum_samples', '最低复查样本数', 'number'),
                        h('details', [h('summary', '引用知识版本（知识问题必填）'), this.field(this, 'search', '查找知识'), btn(this.searching ? '查找中' : '查找', () => this.searchKnowledge(), this.searching),
                            ...this.sources.map(s => btn(`引用：${s.title}`, () => this.addReference(s))),
                            ...this.references.map(s => h('p', { class: 'text-xs' }, [s.title, btn('移除', () => { this.references = this.references.filter(r => r.chunk_id !== s.chunk_id); this.form.knowledge_chunk_ids = this.references.map(r => r.chunk_id); })]))]),
                    ]) : null,
                    this.action === 'evidence' ? this.field(this.record, 'stage', '观察阶段', 'select', { learned: '已学习或复述', practiced: '已练习或执行整改', independent: '已独立完成/整改动作完成' }) : null,
                    this.action === 'review' ? this.field(this.record, 'conclusion', '复查结论', 'select', { insufficient: '证据不足', improved: '有改善，继续跟进', target_met: '达到计划目标', not_improved: '未改善，继续核实' }) : null,
                    recordEvidence ? h('div', { class: 'space-y-3' }, [this.field(this.record, 'observed_on', '观察日期', 'date'), this.field(this.record, 'sample_count', '实际样本数', 'number'), this.field(this.record, 'evidence_ref', '证据位置或记录编号')]) : null,
                    !edit && this.action !== 'knowledge' ? this.field(this.record, 'note', '观察记录或原因说明', 'textarea') : null,
                    ['review', 'defer', 'recur'].includes(this.action) && this.record.conclusion !== 'target_met' ? this.field(this.record, 'next_review_on', '下次复查日期', 'date') : null,
                    this.action === 'review' && this.record.conclusion === 'target_met' ? this.field(this.record, 'criteria_confirmed', '我已逐项核对本计划验收标准', 'checkbox') : null,
                    this.action === 'knowledge' ? h('div', { class: 'space-y-3' }, [h('p', { class: 'text-xs text-amber-800' }, '请填写脱敏经验，不复制员工姓名、客人信息或原始人员评价。保存为参考，不自动发布正式标准。'),
                        ...Object.entries({ title: '经验标题', summary: '脱敏经验摘要', steps: '经验步骤', applicability: '适用条件', stop_conditions: '停止条件', acceptance_criteria: '脱敏验收方法' }).map(([key, label]) => this.field(this.record, key, label, key === 'title' ? 'text' : 'textarea'))]) : null,
                    h('div', { class: 'flex flex-wrap gap-2' }, [h('button', { type: 'submit', class: 'min-h-[44px] rounded-lg bg-[#315d50] px-4 py-2 text-sm text-white', disabled: this.busy || this.referencePending > 0 }, this.busy ? '保存并回读中…' : this.referencePending > 0 ? '正在读取知识引用…' : '保存并核对'), btn('收起表单', () => { this.action = ''; })]),
                ]) : null,
            ]);
        },
    };
    (window.SUXI_SYSTEM_COMPONENTS ||= {}).ManagerCoachingPanel = component;
})();
