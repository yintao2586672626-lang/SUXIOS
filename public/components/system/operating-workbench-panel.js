(() => {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const clone = value => JSON.parse(JSON.stringify(value));
    const uuid = () => typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : Array.from(crypto.getRandomValues(new Uint8Array(16)), v => v.toString(16).padStart(2, '0')).join('');
    const budgetBlank = month => ({ period_month: month, revenue_budget: '', online_target: '', offline_target: '', other_revenue_target: '', break_even_revenue: '', weekly_target: '', tax_basis: 'unknown', source_ref: '', break_even_basis: '', tasks: [] });
    const appealBlank = date => ({ platform: 'ctrip', review_date: date, review_reference: '', factual_description: '', appeal_reason: '', evidence: [], status: 'draft', platform_receipt: '', result_reference: '', case_note: '', reusable_case: false, human_review_confirmed: false });
    registry.OperatingWorkbenchPanel = {
        name: 'OperatingWorkbenchPanel',
        props: { request: { type: Function, required: true }, hotelId: { type: [Number, String], required: true }, hotels: { type: Array, default: () => [] },
            periodMonth: { type: String, required: true }, businessDate: { type: String, required: true }, canExecute: { type: Boolean, default: false }, initialTab: { type: String, default: 'budget' } },
        data() { return { tab: this.initialTab, ids: [Number(this.hotelId)], overview: null, budget: budgetBlank(this.periodMonth), report: null, reportEnd: this.businessDate,
            cases: [], appeal: appealBlank(this.businessDate), caseKey: uuid().replaceAll('-', '').slice(0, 16), latest: { budget: 0, report: 0, appeal: 0, booking: 0 },
            booking: null, context: { business_date: this.businessDate, region: '', area_manager: '', prices: [] }, priceText: '[]', taskText: '[]', evidenceText: '[]',
            fixedTime: '09:00', horizon: 7, busy: false, error: '', notice: '', seq: 0, pending: null }; },
        watch: {
            hotelId() { this.reset(); }, periodMonth() { this.reset(); }, businessDate() { this.reset(); },
            initialTab(value) { this.tab = value; },
            reportEnd() { this.invalidateQuery('report'); }, fixedTime() { this.invalidateQuery('booking'); }, horizon() { this.invalidateQuery('booking'); },
            tab() { this.seq++; this.error = ''; this.notice = ''; this.pending = null; void this.load(); },
        },
        mounted() { void this.load(); },
        beforeUnmount() { this.seq++; },
        computed: {
            periodEnd() { return this.businessDate.startsWith(`${this.periodMonth}-`) ? this.businessDate : new Date(Date.UTC(Number(this.periodMonth.slice(0,4)), Number(this.periodMonth.slice(5,7)), 0)).toISOString().slice(0,10); },
        },
        methods: {
            invalidateQuery(kind) { this.seq++; this.busy = false; this.pending = null; this[kind] = null; this.error = ''; this.notice = ''; },
            reset() { this.seq++; this.overview = null; this.report = null; this.booking = null; this.cases = []; this.ids = [Number(this.hotelId)];
                this.latest = { budget: 0, report: 0, appeal: 0, booking: 0 }; this.budget = budgetBlank(this.periodMonth); this.appeal = appealBlank(this.businessDate);
                this.context = { business_date: this.businessDate, region: '', area_manager: '', prices: [] }; this.priceText = '[]'; this.taskText = '[]'; this.evidenceText = '[]';
                this.caseKey = uuid().replaceAll('-', '').slice(0, 16); this.reportEnd = this.businessDate; this.pending = null; this.error = ''; this.notice = ''; void this.load(); },
            async call(url, options = {}) {
                const response = await this.request(url, { ...options, body: options.body ? JSON.stringify(options.body) : undefined, businessContext: { hotelId: Number(this.hotelId) } });
                if (response?.code !== 200) throw new Error(`${response?.message || '读取或保存失败'}${response?.data?.reason_code ? `（${response.data.reason_code}）` : ''}`);
                return response.data;
            },
            isScope(record, kind) { return Number(record?.scope?.hotel_id) === Number(this.hotelId) && record.scope.kind === kind; },
            async load() {
                if (!this.hotelId) return;
                const seq = ++this.seq, tab = this.tab; this.busy = true; this.error = ''; this.notice = '';
                try {
                    if (tab === 'budget' || tab === 'table') {
                        const ids = tab === 'budget' ? [Number(this.hotelId)] : this.ids;
                        const data = await this.call(`/operating-workbench/overview?${new URLSearchParams({ hotel_ids: ids.join(','), period_month: this.periodMonth, business_date: this.periodEnd })}`);
                        if (seq !== this.seq) return;
                        if (data.period_month !== this.periodMonth || data.business_date !== this.periodEnd || JSON.stringify(data.hotel_ids) !== JSON.stringify(ids)) throw new Error('经营汇总范围不一致');
                        this.overview = data;
                        if (tab === 'budget') { const saved = data.items[0]?.budget; this.budget = { ...budgetBlank(this.periodMonth), ...(saved?.inputs || {}) };
                            this.latest.budget = Number(saved?.snapshot_id || 0); this.taskText = JSON.stringify(this.budget.tasks || [], null, 2); }
                    } else if (tab === 'report') {
                        const data = await this.call(`/operating-workbench/report?${new URLSearchParams({ hotel_id: this.hotelId, period_end: this.reportEnd })}`);
                        if (seq !== this.seq) return;
                        if (!this.isScope(data, `report_${this.reportEnd}`) || data.period_end !== this.reportEnd) throw new Error('周报范围不一致');
                        this.report = data; this.latest.report = Number(data.prior_snapshot?.snapshot_id || 0);
                        this.report.human_judgment = data.prior_snapshot?.inputs?.human_judgment || ''; this.report.manager_note = data.prior_snapshot?.inputs?.manager_note || '';
                        this.report.human_review_confirmed = false; this.report.actions = clone(data.prior_snapshot?.inputs?.actions || []);
                    } else if (tab === 'appeal') {
                        const data = await this.call(`/operating-workbench/appeals?hotel_id=${encodeURIComponent(this.hotelId)}`);
                        if (seq !== this.seq) return;
                        if (Number(data.hotel_id) !== Number(this.hotelId)) throw new Error('评价案例范围不一致'); this.cases = data.cases;
                    } else if (tab === 'booking') {
                        const fixedTime = this.fixedTime, horizon = Number(this.horizon);
                        const data = await this.call(`/operating-workbench/booking?${new URLSearchParams({ hotel_ids: this.ids.join(','), business_date: this.businessDate, platform: 'ctrip', fixed_time: fixedTime, horizon_days: horizon })}`);
                        if (seq !== this.seq) return;
                        if (data.business_date !== this.businessDate || data.fixed_time !== fixedTime || Number(data.horizon_days) !== horizon || JSON.stringify([...data.hotel_ids].sort((a,b)=>a-b)) !== JSON.stringify([...this.ids].sort((a,b)=>a-b))) throw new Error('预订扩展范围不一致');
                        this.booking = data; const saved = data.contexts[String(this.hotelId)]; this.latest.booking = Number(saved?.snapshot_id || 0);
                        this.context = clone(saved?.inputs || { business_date: this.businessDate, region: '', area_manager: '', prices: [] }); this.priceText = JSON.stringify(this.context.prices, null, 2);
                    }
                } catch (error) { if (seq === this.seq) { this.error = error.message; this.overview = null; this.booking = null; if (tab === 'report') this.report = null; if (tab === 'appeal') this.cases = []; } }
                finally { if (seq === this.seq) this.busy = false; }
            },
            selectCase(record) { this.caseKey = record.scope.kind.slice(7); this.appeal = clone(record.inputs); this.appeal.human_review_confirmed = false;
                this.latest.appeal = record.snapshot_id; this.evidenceText = JSON.stringify(this.appeal.evidence || [], null, 2); this.pending = null; },
            newCase() { this.caseKey = uuid().replaceAll('-', '').slice(0, 16); this.appeal = appealBlank(this.businessDate); this.latest.appeal = 0; this.evidenceText = '[]'; this.notice = ''; this.pending = null; },
            async save() {
                if (!this.canExecute || this.busy) return;
                const tab = this.tab, seq = ++this.seq; this.busy = true; this.error = ''; this.notice = '';
                try {
                    let inputs;
                    if (tab === 'budget') inputs = clone(this.budget);
                    else if (tab === 'report') { if (!this.report || this.report.period_end !== this.reportEnd) throw new Error('请先生成当前日期周报'); inputs = { period_end: this.reportEnd, human_judgment: this.report.human_judgment, manager_note: this.report.manager_note, human_review_confirmed: this.report.human_review_confirmed === true, actions: clone(this.report.actions || []) }; }
                    else if (tab === 'appeal') inputs = clone(this.appeal);
                    else if (tab === 'booking') inputs = clone(this.context);
                    else return;
                    const signature = JSON.stringify([this.hotelId, tab, this.caseKey, this.latest[tab], inputs]);
                    if (this.pending?.signature !== signature) this.pending = { signature, key: `workbench_${uuid()}` };
                    const data = await this.call('/operating-workbench/snapshots', { method: 'POST', body: { hotel_id: Number(this.hotelId), type: tab, inputs,
                        case_key: this.caseKey, expected_snapshot_id: this.latest[tab], idempotency_key: this.pending.key } });
                    if (seq !== this.seq) return;
                    const kind = tab === 'budget' ? `budget_${this.periodMonth}` : tab === 'report' ? `report_${this.reportEnd}` : tab === 'appeal' ? `appeal_${this.caseKey}` : `booking_${this.businessDate}`;
                    if (!this.isScope(data, kind) || data.readback_verified !== true || !data.snapshot_id || !/^[a-f0-9]{64}$/.test(data.content_digest || '')) throw new Error('保存未完成同范围精确回读');
                    this.latest[tab] = data.snapshot_id; this.notice = `版本 #${data.snapshot_id} 已保存并回读`; this.pending = null;
                    if (tab === 'budget') this.budget = clone(data.inputs);
                    if (tab === 'report') this.report = { ...clone(data.inputs), saved_snapshot_id: data.snapshot_id, saved_content_digest: data.content_digest };
                    if (tab === 'appeal') { this.appeal = clone(data.inputs); this.cases = [data, ...this.cases.filter(r => r.scope.kind !== kind)]; }
                    if (tab === 'booking') this.context = clone(data.inputs);
                    if (tab === 'budget') { const notice = this.notice; await this.load(); if (this.tab === tab) this.notice = notice; }
                } catch (error) { if (seq === this.seq) this.error = error.message; }
                finally { if (seq === this.seq) this.busy = false; }
            },
            download(value, name) { const url = URL.createObjectURL(new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' }));
                const link = document.createElement('a'); link.href = url; link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); },
            metric(value) { return value === null || value === undefined || value === '' ? '未取得' : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 }); },
        },
        render() {
            const h = Vue.h, button = (label, action, props = {}) => h('button', { type: 'button', class: 'rounded-lg border px-3 py-2 text-sm', disabled: this.busy, onClick: action, ...props }, label);
            const field = (model, key, label, props = {}) => h('label', { class: 'text-xs block text-slate-600' }, [label, h('input', { class: 'mt-1 block rounded-lg border p-2 w-full text-sm', disabled: this.busy, value: model[key] ?? '', onInput: e => { model[key] = e.target.value; }, ...props })]);
            const textarea = (model, key, label, props = {}) => h('label', { class: 'text-xs block text-slate-600 mt-3' }, [label, h('textarea', { class: 'mt-1 block rounded-lg border p-2 w-full text-sm', disabled: this.busy, rows: 3, value: model[key], onInput: e => { model[key] = e.target.value; }, ...props })]);
            const check = (model, key, label) => h('label', { class: 'flex gap-2 text-sm mt-3' }, [h('input', { type: 'checkbox', disabled: this.busy, checked: model[key] === true, onChange: e => { model[key] = e.target.checked; } }), label]);
            const select = (model, key, label, choices) => h('label', { class: 'text-xs block text-slate-600' }, [label, h('select', { 'aria-label': label, class: 'mt-1 block rounded-lg border p-2 w-full text-sm', disabled: this.busy, value: model[key], onChange: e => { model[key] = e.target.value; } }, choices.map(([value, text]) => h('option', { value }, text)))]);
            const taskEditor = (model, key) => h('div', { class: 'mt-4' }, [h('h3', { class: 'text-sm font-semibold' }, '责任与完成清单'), ...(model[key] || []).map((row, index) => h('div', { class: 'grid gap-2 md:grid-cols-3 mt-3 border-t pt-3' }, [
                field(row, 'measure', '任务'), field(row, 'owner', '负责人'), field(row, 'due_date', '截止日期', { type: 'date' }), select(row, 'status', '进度', [['pending','待处理'],['in_progress','处理中'],['completed','已完成']]), field(row, 'evidence_ref', '完成凭据（结案必填）'), button('移除任务', () => model[key].splice(index, 1)),
            ])), button('添加任务', () => { (model[key] || (model[key] = [])).push({ measure: '', owner: '', due_date: '', status: 'pending', evidence_ref: '' }); })]);
            const table = (columns, rows) => h('div', { class: 'overflow-x-auto mt-3' }, [h('table', { class: 'min-w-full text-xs' }, [
                h('thead', [h('tr', columns.map(c => h('th', { class: 'text-left px-3 py-3 whitespace-nowrap' }, c[1])))]),
                h('tbody', rows.map(row => h('tr', { class: 'border-t' }, columns.map(c => h('td', { class: 'px-3 py-3 whitespace-nowrap' }, typeof c[2] === 'function' ? c[2](row) : String(row[c[0]] ?? '未取得'))))))])]);
            const selection = h('fieldset', { class: 'flex flex-wrap gap-3 mt-3' }, [h('legend', { class: 'text-xs' }, '汇总所选门店（同租户，最多20家）'),
                ...this.hotels.map(hotel => h('label', { class: 'text-xs flex gap-1' }, [h('input', { type: 'checkbox', checked: this.ids.includes(Number(hotel.id)), onChange: e => { const id = Number(hotel.id); this.ids = e.target.checked ? [...this.ids, id] : this.ids.filter(v => v !== id); this.seq++; this.busy = false; this.overview = null; this.booking = null; } }), hotel.name]))]);
            let content;
            if (this.tab === 'budget') content = [h('p', { class: 'mt-3 text-xs text-slate-500' }, '月度预算与任务保存为独立版本；旧月任务可预填，缺失字段留空。保本营收门槛由你定义，其差额不代表利润或回本。'),
                h('div', { class: 'grid gap-3 md:grid-cols-3 mt-3' }, ['revenue_budget', 'online_target', 'offline_target', 'other_revenue_target', 'break_even_revenue', 'weekly_target'].map((key, i) => field(this.budget, key, ['月总营收预算', '月线上房费目标', '月线下房费目标', '月其他营收目标', '月保本营收门槛', '周营收目标（人工设定）'][i], { inputmode: 'decimal' }))),
                field(this.budget, 'source_ref', '预算依据（必填）'), field(this.budget, 'break_even_basis', '保本门槛依据（填写门槛时必填）'),
                h('label', { class: 'text-xs mt-3 block' }, ['税口径', h('select', { value: this.budget.tax_basis, class: 'rounded-lg border p-2 ml-2', onChange: e => { this.budget.tax_basis = e.target.value; } }, ['unknown', 'tax_included', 'tax_excluded'].map(v => h('option', { value: v }, ({ unknown: '待确认', tax_included: '含税', tax_excluded: '不含税' })[v])))]),
                taskEditor(this.budget, 'tasks'), this.overview ? table([['key', '指标'], ['target', '目标', r => this.metric(r.target)], ['actual', '完成额', r => this.metric(r.actual)], ['completion_percent', '完成率 %', r => this.metric(r.completion_percent)], ['gap', '差额', r => this.metric(r.gap)], ['actual_quality', '事实状态']], Object.entries(this.overview.items[0].comparison.matrix).map(([key, r]) => ({ key: ({revenue_budget:'总营收',online_target:'线上房费',offline_target:'线下房费'})[key], ...r }))) : null];
            if (this.tab === 'table') content = [selection, button('读取所选门店', () => this.load()), this.overview ? table([
                ['hotel_name', '门店'], ['day', '当日确认营收', r => this.metric(r.daily.admitted_revenue)], ['reported', '当日填报营收', r => this.metric(r.daily.reported_revenue)],
                ['month', '截至当日月确认营收', r => this.metric(r.month.admitted_revenue)], ['observed', '已填日期营收小计', r => this.metric(r.month.observed_reported_revenue)],
                ['coverage', '完整性', r => `${r.month.admitted_days}/${r.month.expected_days}确认；${r.month.reported_days}填报`], ['budget', '月预算', r => this.metric(r.budget?.inputs?.revenue_budget)],
                ['completion', '月完成率 %', r => this.metric(r.comparison.matrix.revenue_budget.completion_percent)], ['break_even', '距保本门槛差额', r => this.metric(r.comparison.break_even_gap)],
                ['occ', '综合出租率 %（填报）', r => this.metric(r.month.combined_occupancy_percent)], ['overnight', '过夜出租率 %（填报）', r => this.metric(r.month.overnight_occupancy_percent)],
            ], this.overview.items) : null, button('导出当前同口径数据', () => this.download(this.overview, `经营汇总-${this.periodMonth}.json`), { disabled: !this.overview || this.busy })];
            if (this.tab === 'report') content = [field(this, 'reportEnd', '周末日期', { type: 'date' }), button('生成本周经营与店长报告', () => this.load()),
                this.report ? h('div', [h('p', { class: 'mt-3 text-sm' }, `${this.report.period_start} 至 ${this.report.period_end}；确认覆盖 ${this.report.facts.admitted_days}/${this.report.facts.expected_days} 天，状态 ${this.report.source_quality}`),
                    h('p', { class: 'mt-2 text-sm' }, `确认营收 ${this.metric(this.report.facts.admitted_revenue)}；周目标 ${this.metric(this.report.weekly_target)}；完成率 ${this.metric(this.report.weekly_completion_percent)}%`),
                    textarea(this.report, 'human_judgment', '运营分析与下周重点（人工）'), textarea(this.report, 'manager_note', '店长管理复盘（人工）'),
                    taskEditor(this.report, 'actions'), check(this.report, 'human_review_confirmed', '我已逐项核对本次事实、缺失日期和人工判断'),
                    h('details', { class: 'mt-3' }, [h('summary', '逐日来源与质量'), table([['business_date', '业务日'], ['admitted_revenue', '确认营收', r => this.metric(r.admitted_revenue)], ['reported_revenue', '填报营收', r => this.metric(r.reported_revenue)], ['reported_quality', '填报状态'], ['admitted_quality', '确认状态'], ['source_ref', '日报来源'], ['admitted_source_ref', '确认依据']], this.report.facts.series)]),
                    button('导出当前周报（未发送）', () => this.download(this.report, `经营周报-${this.reportEnd}.json`)),
                ]) : null];
            if (this.tab === 'appeal') content = [h('p', { class: 'mt-3 text-xs' }, '材料 → 草稿 → 人工复核 → 手工登记平台提交和结果。案例只在当前酒店使用；平台结果为人工登记，系统不会自动申诉。'),
                button('新建评价申诉', () => this.newCase()), h('div', { class: 'flex flex-wrap gap-2 mt-3' }, this.cases.map(r => button(`${r.inputs.review_reference} · ${r.inputs.status}${r.inputs.reusable_case ? ' · 案例' : ''}`, () => this.selectCase(r)))),
                h('div', { class: 'grid gap-3 md:grid-cols-2 mt-3' }, [select(this.appeal, 'platform', '平台', [['ctrip','携程'],['meituan','美团']]), field(this.appeal, 'review_date', '评价日期', { type: 'date' }), field(this.appeal, 'review_reference', '评价引用（不填住客身份）'), select(this.appeal, 'status', '处理阶段', [['draft','草稿'],['evidence_ready','材料已齐'],['reviewed','负责人已复核'],['submitted','已在平台手工提交'],['accepted','平台已接受'],['rejected','平台已拒绝'],['withdrawn','已撤回']])]),
                textarea(this.appeal, 'factual_description', '可核对事实描述（勿填个人身份信息）'), textarea(this.appeal, 'appeal_reason', '申请复核理由'),
                h('div', { class: 'mt-3' }, [...this.appeal.evidence.map((row,index)=>h('div',{class:'grid gap-2 md:grid-cols-3 mt-2'},[field(row,'description','证据说明'),field(row,'source_ref','证据引用（勿填私密链接）'),field(row,'business_date','证据业务日',{type:'date'}),button('移除证据',()=>this.appeal.evidence.splice(index,1))])),button('添加证据',()=>this.appeal.evidence.push({description:'',source_ref:'',business_date:this.businessDate}))]), check(this.appeal, 'human_review_confirmed', '负责人已核对材料与申诉草稿'),
                field(this.appeal, 'platform_receipt', '在平台手工提交后的凭据'), field(this.appeal, 'result_reference', '平台结果引用'), textarea(this.appeal, 'case_note', '可复用经验（去除住客及订单身份）'), check(this.appeal, 'reusable_case', '将有结果的经验列入本店案例库'),
                this.appeal.draft_text ? h('pre', { class: 'whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 mt-3 text-xs' }, this.appeal.draft_text) : null];
            if (this.tab === 'booking') content = [selection, h('div', { class: 'flex gap-3 mt-3' }, [field(this, 'fixedTime', '固定时点', { type: 'time' }), field(this, 'horizon', '提前期天数（1–30）', { type: 'number', min: 1, max: 30 }), button('读取预订扩展', () => this.load())]),
                this.booking ? table([['hotel_name', '酒店'], ['stay_date', '入住日'], ['room_type_name', '房型'], ['manager', '区域 / 负责人', r => `${r.region || '未配置'} / ${r.area_manager || '未配置'}`],
                    ['current', '当前在手间夜', r => this.metric(r.current.status === 'ready' ? r.current.on_books_room_nights : null)], ['prior', '去年同提前期间夜', r => this.metric(r.prior_year.on_books_room_nights)], ['diff', '同比差额', r => this.metric(r.prior_year.difference)], ['state', '同比状态', r => r.prior_year.status],
                    ['price', '携程人工观察起价', r => `${this.metric(r.ctrip_starting_price?.starting_price)} · ${r.ctrip_starting_price?.quality_status || 'missing'}`], ['ref', '起价来源与采集时刻', r => r.ctrip_starting_price ? `${r.ctrip_starting_price.source_ref} / ${r.ctrip_starting_price.captured_at}` : '未取得'],
                ], this.booking.cells) : null,
                this.booking ? table([['region', '区域', r => r.region?.trim() || '未配置'], ['area_manager', '负责人', r => r.area_manager?.trim() || '未配置'], ['scope', '汇总范围', r => r.assignment_status === 'missing' ? `${r.hotel_name}（分组未配置）` : '所选已配置门店'], ['stay_date', '入住日'], ['room_nights', '所选店在手间夜', r => this.metric(r.room_nights)], ['coverage', '覆盖', r => `${r.covered_hotels}/${r.expected_hotels}`], ['status', '状态']], this.booking.group_rollup) : null,
                field(this.context, 'region', '当前门店区域（人工配置）'), field(this.context, 'area_manager', '当前门店区域负责人（人工配置）'),
                h('div', {class:'mt-3'}, [...this.context.prices.map((row,index)=>h('div',{class:'grid gap-2 md:grid-cols-3 mt-2'},[field(row,'stay_date','报价入住日',{type:'date'}),field(row,'room_type_id','房型ID（0代表酒店起价）',{type:'number',min:0}),field(row,'starting_price','携程观察起价（元）',{inputmode:'decimal'}),field(row,'captured_at','实际采集时间（上海 YYYY-MM-DD HH:mm:ss）'),field(row,'source_ref','授权价格来源'),check(row,'operator_attested','已人工核对本条价格'),button('移除报价',()=>this.context.prices.splice(index,1))])),button('添加起价观察',()=>this.context.prices.push({stay_date:'',room_type_id:0,starting_price:'',captured_at:'',source_ref:'',operator_attested:false}))]),
                h('p', { class: 'text-xs text-slate-500 mt-2' }, '同比只比较同日历观察日与提前期，闰日不替代。区域汇总只加酒店汇总行；任一店缺失或口径不同则不形成总数。价格是人工来源，未自动采集。')];
            return h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-5 min-w-0', 'data-testid': 'operating-workbench' }, [
                h('h2', { class: 'font-bold text-slate-900' }, '经营预算、周报与运营工具'), h('p', { class: 'text-xs text-slate-500 mt-1' }, `门店 #${this.hotelId} · ${this.periodMonth} · 月数据截至 ${this.periodEnd}`),
                h('nav', { class: 'flex flex-wrap gap-2 mt-3', 'aria-label': '经营工具' }, [['budget', '月预算与任务'], ['table', '多店经营表'], ['report', '经营 / 店长周报'], ['appeal', '评价申诉与案例'], ['booking', '预订同比与区域']].map(([key, label]) => button(label, () => { this.tab = key; }, { 'aria-pressed': this.tab === key, 'data-testid': `workbench-tab-${key}` }))),
                this.error ? h('p', { role: 'alert', class: 'rounded-lg bg-red-50 text-red-700 p-3 mt-3 break-all' }, this.error) : null,
                this.notice ? h('p', { role: 'status', class: 'rounded-lg bg-emerald-50 text-emerald-800 p-3 mt-3' }, this.notice) : null,
                this.busy ? h('p', { class: 'mt-3 text-sm' }, '正在读取或保存…') : null, ...(content || []),
                this.canExecute && this.tab !== 'table' ? button('保存当前版本并回读', () => this.save(), { class: 'operating-finance-primary-action rounded-lg px-4 py-2 text-white mt-4', 'data-testid': 'workbench-save' }) : null,
                button('重新读取', () => this.load(), { class: 'rounded-lg border px-3 py-2 text-sm mt-4 ml-2' }),
            ]);
        },
    };
})();
