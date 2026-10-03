(() => {
    'use strict';
    const registry = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const clone = value => JSON.parse(JSON.stringify(value));
    const blankReview = (cycle = 'weekly') => {
        const end = today(), start = cycle === 'monthly' ? `${end.slice(0, 7)}-01` : new Date(Date.parse(`${end}T00:00:00Z`) - 6 * 86400000).toISOString().slice(0, 10);
        return { period_start: start, period_end: end, title: '', review_note: '', source_ref: '', source_references: [], actions: [], field_mapping: {} };
    };
    registry.BusinessFeatureWorkspace = {
        name: 'BusinessFeatureWorkspace',
        components: { GuestOperationsPanel: registry.GuestOperationsPanel, CampaignOperationsPanel: registry.CampaignOperationsPanel },
        props: { request: { type: Function, required: true }, hotels: { type: Array, default: () => [] },
            hotelId: { type: [String, Number], required: true }, canExecute: { type: Boolean, default: false },
            initialSection: { type: String, default: 'configuration' }, entryKey: { type: String, default: '' } },
        emits: ['navigate', 'finance-tab', 'settings-applied', 'update:selected-hotel-id'],
        data() { return { active: this.initialSection === 'guests' ? 'guests' : 'configuration', phase: 1, catalog: [], configuration: null, applied: null, configurationId: 0,
            overview: null, displayedRecord: null, review: blankReview(), mappingText: '{}', sourceRows: '[]', preview: null,
            latestId: 0, guestTab: this.entryKey ? 'feedback' : 'repeat', campaignTab: 'campaign', busy: false, error: '', notice: '', seq: 0, weeklySource: null, weeklyRequestId: 0, previewRequestId: 0, pendingSave: null }; },
        computed: {
            kind() { return ['weekly_review', 'manager_review', 'ota_review', 'source_mapping'].includes(this.active) ? this.active : 'configuration'; },
            configRows() { return (this.configuration?.modules || []).map(item => ({ ...this.catalog.find(row => row.module_id === item.module_id), ...item })); },
            enabledRows() { return (this.applied?.modules || []).filter(row => row.enabled && row.phase === Number(this.phase))
                .map(item => ({ ...this.catalog.find(row => row.module_id === item.module_id), ...item })); },
        },
        watch: {
            hotelId: { immediate: true, handler() { this.seq++; this.configuration = null; this.applied = null; this.configurationId = 0;
                this.catalog = []; this.review = blankReview(); this.mappingText = '{}'; this.sourceRows = '[]'; this.pendingSave = null;
                this.weeklySource = null; this.preview = null; void this.load(); } },
            active() { this.seq++; this.review = blankReview(this.applied?.review_cycle); this.mappingText = '{}'; this.sourceRows = '[]'; this.pendingSave = null;
                this.preview = null; this.weeklySource = null; void this.load(); },
        },
        methods: {
            async call(url, options = {}) {
                const transport = { ...options, businessContext: { hotelId: Number(this.hotelId) } };
                if (transport.body && typeof transport.body === 'object') transport.body = JSON.stringify(transport.body);
                const response = await this.request(url, transport);
                if (response?.code !== 200) throw new Error(response?.message || '当前功能读取或保存失败'); return response.data;
            },
            scoped(data, kind = this.kind) { return data?.contract_version === 'business_workspace.v1'
                && Number(data.scope?.hotel_id) === Number(this.hotelId) && data.scope?.kind === kind; },
            async load() {
                if (!Number.isSafeInteger(Number(this.hotelId)) || Number(this.hotelId) <= 0) {
                    this.seq++; this.busy = false; this.overview = null; this.displayedRecord = null; this.latestId = 0;
                    this.configuration = null; this.applied = null; this.configurationId = 0; this.catalog = [];
                    this.preview = null; this.weeklySource = null; this.pendingSave = null; this.notice = '';
                    this.error = '请选择有效酒店后读取工作区'; return;
                }
                const sequence = ++this.seq, kind = this.kind; this.busy = true; this.error = ''; this.notice = ''; this.overview = null; this.displayedRecord = null; this.latestId = 0; this.preview = null; this.weeklySource = null;
                try {
                    const data = await this.call(`/business-workspace/overview?${new URLSearchParams({ hotel_id: this.hotelId, kind })}`);
                    if (sequence !== this.seq) return;
                    if (!this.scoped(data, kind)) throw new Error('工作区返回的酒店或功能范围不一致');
                    this.catalog = data.catalog; this.overview = data; this.latestId = Number(data.latest?.snapshot_id || 0);
                    this.displayedRecord = data.latest ? clone(data.latest) : null;
                    if (kind === 'configuration') {
                        this.configuration = clone(data.latest?.inputs || data.suggested_configuration);
                        this.configurationId = this.latestId;
                        this.applied = data.latest?.readback_verified === true ? clone(data.latest.inputs) : null;
                        if (this.applied) this.$emit('settings-applied', clone(this.applied));
                    } else if (data.latest) { this.review = clone(data.latest.inputs); this.mappingText = JSON.stringify(this.review.field_mapping || {}, null, 2); }
                } catch (error) { if (sequence === this.seq) { this.error = error.message; this.overview = null; if (kind === 'configuration') this.applied = null; } }
                finally { if (sequence === this.seq) this.busy = false; }
            },
            async save() {
                if (!this.canExecute || this.busy || !this.overview) return;
                const sequence = ++this.seq, kind = this.kind; this.busy = true; this.error = ''; this.notice = '';
                try {
                    const input = clone(kind === 'configuration' ? this.configuration : this.review);
                    if (kind === 'source_mapping') input.field_mapping = JSON.parse(this.mappingText);
                    const draftSignature = JSON.stringify([kind === 'configuration' ? this.configuration : this.review, kind === 'source_mapping' ? this.mappingText : null]);
                    const signature = JSON.stringify([this.hotelId, kind, this.latestId, input]);
                    if (this.pendingSave?.signature !== signature) this.pendingSave = { signature, key: `business_${crypto.randomUUID()}` };
                    const data = await this.call('/business-workspace/snapshots', { method: 'POST', body: {
                        hotel_id: Number(this.hotelId), kind, inputs: input, expected_snapshot_id: this.latestId,
                        idempotency_key: this.pendingSave.key,
                    } });
                    if (sequence !== this.seq) return;
                    if (!this.scoped(data, kind) || data.readback_verified !== true || !Number(data.snapshot_id) || !/^[a-f0-9]{64}$/.test(data.content_digest || '')) throw new Error('保存未完成同范围精确回读');
                    this.latestId = Number(data.snapshot_id);
                    this.displayedRecord = clone(data);
                    this.preview = null;
                    this.overview = { ...this.overview, latest: data, history: [data, ...(this.overview.history || [])] };
                    const draftUnchanged = draftSignature === JSON.stringify([kind === 'configuration' ? this.configuration : this.review, kind === 'source_mapping' ? this.mappingText : null]);
                    if (kind === 'configuration') { if (draftUnchanged) this.configuration = clone(data.inputs); this.applied = clone(data.inputs); this.configurationId = this.latestId; this.$emit('settings-applied', clone(this.applied)); }
                    else if (draftUnchanged) { this.review = clone(data.inputs); if (kind === 'source_mapping') this.mappingText = JSON.stringify(data.inputs.field_mapping || {}, null, 2); }
                    this.notice = `版本 #${data.snapshot_id} 已保存并精确回读${draftUnchanged ? '' : '；当前较新的草稿尚未提交，已保留'}`;
                    this.pendingSave = null;
                } catch (error) { if (sequence === this.seq) this.error = error.message; }
                finally { if (sequence === this.seq) this.busy = false; }
            },
            async restore(id) {
                const sequence = ++this.seq, kind = this.kind; this.busy = true; this.error = ''; this.notice = '';
                try {
                    const data = await this.call(`/business-workspace/snapshots/${id}?${new URLSearchParams({ hotel_id: this.hotelId, kind })}`);
                    if (sequence !== this.seq) return;
                    if (!this.scoped(data, kind) || data.readback_verified !== true || Number(data.snapshot_id) !== Number(id)) throw new Error('历史版本回读范围不一致');
                    this.displayedRecord = clone(data);
                    this.preview = null; this.weeklySource = null;
                    if (kind === 'configuration') this.configuration = clone(data.inputs);
                    else { this.review = clone(data.inputs); this.mappingText = JSON.stringify(data.inputs.field_mapping || {}, null, 2); }
                    this.notice = `已读取版本 #${id}；修改后保存为新版本`;
                } catch (error) { if (sequence === this.seq) this.error = error.message; }
                finally { if (sequence === this.seq) this.busy = false; }
            },
            move(index, direction) {
                const rows = this.configuration.modules, target = index + direction;
                if (target < 0 || target >= rows.length) return;
                [rows[index], rows[target]] = [rows[target], rows[index]];
                rows.forEach((row, position) => { row.rank = position + 1; });
            },
            execute(module) {
                if (!this.applied || !module.enabled) return;
                if (module.target === 'finance') return this.$emit('finance-tab', { tab: module.tab, settings: clone(this.applied), workbench_tab: [10, 12].includes(module.module_id) ? 'report' : module.module_id === 7 ? 'table' : 'budget' });
                if (module.target === 'guests') { this.guestTab = module.tab; this.active = 'guests'; return; }
                if (module.target === 'campaigns') { this.campaignTab = module.tab; this.active = 'campaigns'; return; }
                if (module.target === 'review') { this.active = module.tab; return; }
                if (module.target === 'source') { this.active = 'source_mapping'; return; }
                if (module.module_id === 26 && this.applied.preferred_platform === 'meituan') return this.$emit('navigate', { page: 'meituan-ebooking', tab: 'ads', stored: true });
                if (module.module_id === 17 && this.applied.preferred_platform === 'meituan') return this.$emit('navigate', { page: 'meituan-ebooking', tab: 'meituan-review-match' });
                this.$emit('navigate', { page: module.target, tab: module.tab });
            },
            addAction() { this.review.actions.push({ measure: '', owner: '', due_date: this.review.period_end, status: 'planned', evidence_ref: '' }); },
            invalidateWeeklySource() {
                this.weeklyRequestId++; this.weeklySource = null;
                this.review.source_references = (this.review.source_references || []).filter(ref => !String(ref).startsWith('weekly_operating_plan#'));
            },
            async readWeeklySource() {
                this.invalidateWeeklySource();
                const sequence = this.seq, requestId = this.weeklyRequestId, start = this.review.period_start, end = this.review.period_end;
                const current = () => sequence === this.seq && requestId === this.weeklyRequestId && start === this.review.period_start && end === this.review.period_end;
                this.error = '';
                try {
                    const data = await this.call(`/operating-opportunities/weekly-plan/latest?${new URLSearchParams({ hotel_id: this.hotelId, week_end: end })}`);
                    if (!current()) return;
                    if (Number(data?.hotel_id) !== Number(this.hotelId) || data.week_end !== end || !['weekly_operating_plan.v1', 'weekly_operating_plan.v2'].includes(data.contract_version)) throw new Error('周计划酒店或截止日期范围不一致');
                    this.weeklySource = data;
                    const id = Number(data.snapshot_id || data.id || 0);
                    if (data.readback_verified === true && id > 0) this.review.source_references.push(`weekly_operating_plan#${id}`);
                } catch (error) { if (current()) this.error = error.message; }
            },
            async previewMapping() {
                const sequence = this.seq, requestId = ++this.previewRequestId, snapshotId = Number(this.displayedRecord?.snapshot_id), sourceRows = this.sourceRows;
                const current = () => sequence === this.seq && requestId === this.previewRequestId && snapshotId === Number(this.displayedRecord?.snapshot_id) && sourceRows === this.sourceRows;
                this.error = ''; this.preview = null;
                try {
                    const data = await this.call('/business-workspace/source-preview', { method: 'POST', body: { hotel_id: Number(this.hotelId), snapshot_id: snapshotId, rows: JSON.parse(sourceRows) } });
                    if (!current()) return;
                    if (data?.contract_version !== 'business_source_mapping_preview.v1' || Number(data.scope?.hotel_id) !== Number(this.hotelId) || Number(data.mapping_snapshot_id) !== snapshotId || data.ota_fact_created !== false) throw new Error('映射预览范围不一致');
                    this.preview = data;
                } catch (error) { if (current()) this.error = error.message; }
            },
            downloadReview() {
                const record = this.displayedRecord;
                if (!record?.readback_verified || this.busy) return;
                const content = JSON.stringify({ ...record, export_note: '保存版本的人工经营复盘；不是已核验财务事实，也没有外部发送。' }, null, 2);
                const url = URL.createObjectURL(new Blob([content], { type: 'application/json' }));
                const anchor = document.createElement('a'); anchor.href = url; anchor.download = `hotel-${this.hotelId}-${this.kind}-v${record.snapshot_id}.json`; anchor.click(); URL.revokeObjectURL(url);
            },
        },
        render() {
            const h = Vue.h, button = (label, onClick, disabled = false, attrs = {}) => h('button', { type: 'button', class: 'rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:opacity-40', disabled, onClick, ...attrs }, label);
            const field = (label, target, key, type = 'text') => h('label', { class: 'block text-xs text-slate-600' }, [label, h('input', { class: 'mt-1 w-full rounded-lg border p-2 text-sm', type, value: target[key], onInput: event => { target[key] = event.target.value; if (target === this.review && ['period_start', 'period_end'].includes(key)) this.invalidateWeeklySource(); }, 'aria-label': label })]);
            const select = (label, value, choices, update) => h('label', { class: 'block text-xs text-slate-600' }, [label, h('select', { class: 'mt-1 w-full rounded-lg border p-2 text-sm', value, onChange: event => update(event.target.value), 'aria-label': label }, choices.map(([key, text]) => h('option', { value: key }, text)))]);
            const navigation = [['configuration', '自定义设定'], ['execution', '按批次执行'], ['weekly_review', '酒店周报'], ['manager_review', '店总复盘'], ['ota_review', 'OTA措施复盘'], ['source_mapping', '来源字段映射']];
            let body = null;
            if (this.active === 'configuration' && this.configuration) {
                const settings = this.configuration;
                body = h('div', [h('div', { class: 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' }, [
                    select('业务定位', settings.profile, [['self_investment', '自投项目'], ['third_party_ota', '第三方OTA运营'], ['custom', '自定义']], value => { settings.profile = value; }),
                    select('默认平台', settings.preferred_platform, [['ctrip', '携程'], ['meituan', '美团']], value => { settings.preferred_platform = value; }),
                    field('预订固定观察时间', settings, 'booking_fixed_time', 'time'),
                    h('label', { class: 'text-xs text-slate-600' }, ['预订未来天数', h('input', { class: 'mt-1 w-full rounded-lg border p-2', type: 'number', min: 1, max: 30, value: settings.booking_horizon_days, 'aria-label': '预订未来天数', onInput: event => { settings.booking_horizon_days = Number(event.target.value); } })]),
                    h('label', { class: 'text-xs text-slate-600' }, ['复购观察天数', h('input', { class: 'mt-1 w-full rounded-lg border p-2', type: 'number', min: 7, max: 365, value: settings.repeat_window_days, 'aria-label': '复购观察天数', onInput: event => { settings.repeat_window_days = Number(event.target.value); } })]),
                    select('复盘周期', settings.review_cycle, [['weekly', '每周'], ['monthly', '每月']], value => { settings.review_cycle = value; }),
                ]), h('p', { class: 'mt-3 text-xs text-slate-500' }, this.applied ? `当前生效版本 #${this.configurationId}；编辑后保存才应用。` : '当前为建议设置，尚未保存和应用。'),
                    h('div', { class: 'mt-3 overflow-x-auto' }, [h('table', { class: 'min-w-full text-left text-sm', 'data-testid': 'business-configuration-rows' }, [
                        h('thead', h('tr', ['启用', '顺序', '模块', '批次', '调整'].map(text => h('th', { class: 'px-2 py-2' }, text)))),
                        h('tbody', this.configRows.map((row, index) => h('tr', { key: row.module_id, class: 'border-t', 'data-module-id': row.module_id }, [
                            h('td', { class: 'px-2 py-2' }, h('input', { type: 'checkbox', checked: row.enabled, 'aria-label': `启用${row.name}`, onChange: event => { settings.modules[index].enabled = event.target.checked; } })),
                            h('td', { class: 'px-2 py-2' }, String(row.rank)), h('td', { class: 'px-2 py-2 whitespace-nowrap' }, row.name),
                            h('td', { class: 'px-2 py-2' }, select(`${row.name}批次`, row.phase, [[1, '第一批'], [2, '第二批'], [3, '第三批']], value => { settings.modules[index].phase = Number(value); })),
                            h('td', { class: 'px-2 py-2 whitespace-nowrap' }, [button('上移', () => this.move(index, -1), index === 0), button('下移', () => this.move(index, 1), index === 30)]),
                        ]))),
                    ])]), button('保存设置并应用', () => this.save(), !this.canExecute || this.busy || !this.overview),
                ]);
            } else if (this.active === 'execution') {
                body = h('div', [h('div', { class: 'flex flex-wrap gap-2' }, [1, 2, 3].map(phase => button(`第${phase}批`, () => { this.phase = phase; }, false, { 'aria-pressed': this.phase === phase }))),
                    !this.applied ? h('p', { class: 'mt-4 text-sm text-amber-700' }, '先保存自定义设置，再按批次打开已启用功能。') : h('div', { class: 'mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3' }, this.enabledRows.map(row => button(`${row.rank}. ${row.name}`, () => this.execute(row), false, { 'data-execute-module': row.module_id }))),
                    h('p', { class: 'mt-3 text-xs text-slate-500' }, '执行按钮打开实际功能，由操作者填写、核对和保存。不会批量自动调价、发消息或代替人工审核。'),
                ]);
            } else if (this.active === 'guests') {
                body = h(registry.GuestOperationsPanel, { key: `${this.hotelId}:${this.guestTab}`, hotels: this.hotels, request: this.request, hotelId: this.hotelId,
                    selectedHotelId: this.hotelId, canExecute: this.canExecute, initialTab: this.guestTab, entryKey: this.entryKey, settings: this.applied, onNavigate: event => this.$emit('navigate', event), 'onUpdate:selectedHotelId': value => this.$emit('update:selected-hotel-id', value) });
            } else if (this.active === 'campaigns') {
                body = h(registry.CampaignOperationsPanel, { key: `${this.hotelId}:${this.campaignTab}`, request: this.request, hotels: this.hotels, hotelId: this.hotelId,
                    selectedHotelId: this.hotelId, canExecute: this.canExecute, initialTab: this.campaignTab, settings: this.applied, onNavigate: event => this.$emit('navigate', event), 'onUpdate:selectedHotelId': value => this.$emit('update:selected-hotel-id', value) });
            } else if (['weekly_review', 'manager_review', 'ota_review', 'source_mapping'].includes(this.active)) {
                const review = this.review;
                body = h('div', [h('p', { class: 'mb-3 text-xs text-slate-500' }, '保存的是人工评述、措施与来源引用；经营数字沿用原事实入口，未知保持缺失。'),
                    h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [field('开始日期', review, 'period_start', 'date'), field('结束日期', review, 'period_end', 'date'), field('复盘或映射标题', review, 'title'), field('来源文件或记录引用', review, 'source_ref')]),
                    h('label', { class: 'mt-3 block text-xs text-slate-600' }, ['评述与未结事项', h('textarea', { class: 'mt-1 w-full rounded-lg border p-3 text-sm', rows: 4, value: review.review_note, 'aria-label': '评述与未结事项', onInput: event => { review.review_note = event.target.value; } })]),
                    this.active !== 'source_mapping' ? h('div', { class: 'mt-3' }, [button('读取同酒店周计划', () => this.readWeeklySource(), this.busy), this.weeklySource ? h('p', { class: 'mt-2 text-xs text-slate-600' }, this.weeklySource.readback_verified ? `已读取 ${this.weeklySource.week_start} 至 ${this.weeklySource.week_end} 的周计划引用；人工判断仍待审核。` : '该期间尚未形成保存的周计划，不能补成零。') : null,
                        ...review.actions.map((action, index) => h('div', { class: 'mt-3 grid gap-2 rounded-xl border p-3 sm:grid-cols-2' }, [field('措施', action, 'measure'), field('负责人', action, 'owner'), field('完成期限', action, 'due_date', 'date'), field('处理证据引用', action, 'evidence_ref'), select('措施状态', action.status, [['planned', '计划'], ['in_progress', '进行中'], ['completed', '已完成'], ['blocked', '阻塞']], value => { action.status = value; }), button('移除该措施', () => review.actions.splice(index, 1))])), button('增加措施', () => this.addAction(), !this.canExecute),
                    ]) : h('div', { class: 'mt-3' }, [h('p', { class: 'text-xs text-slate-500' }, '字段映射只适配手工来源；专有API连接需要对应接口合同。映射预览不会写入OTA事实。'),
                        h('textarea', { class: 'mt-2 w-full rounded-lg border p-3 text-sm', rows: 5, value: this.mappingText, 'aria-label': '字段映射JSON', onInput: event => { this.mappingText = event.target.value; } }),
                        h('textarea', { class: 'mt-2 w-full rounded-lg border p-3 text-sm', rows: 4, value: this.sourceRows, 'aria-label': '手工来源行JSON', onInput: event => { this.sourceRows = event.target.value; this.preview = null; } }),
                        button('预览已保存映射', () => this.previewMapping(), !this.displayedRecord?.snapshot_id || this.busy),
                        this.preview ? h('pre', { class: 'mt-2 max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs' }, JSON.stringify(this.preview, null, 2)) : null,
                    ]), h('div', { class: 'mt-4 flex flex-wrap gap-2' }, [button('保存新版本并回读', () => this.save(), !this.canExecute || this.busy || !this.overview), button('下载已保存复盘', () => this.downloadReview(), !this.displayedRecord || this.busy)]),
                ]);
            }
            return h('section', { class: 'min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5', 'data-testid': 'business-feature-workspace' }, [
                h('div', [h('h3', { class: 'text-lg font-bold text-slate-900' }, '三批功能 · 自定义设定与执行'), h('p', { class: 'mt-1 text-xs text-slate-500' }, `酒店 #${this.hotelId}。设置按当前用户和酒店保存；业务记录按酒店回读，权限沿用原角色。`)]),
                h('nav', { class: 'flex flex-wrap gap-2', 'aria-label': '三批工作区功能' }, [...navigation.map(([key, label]) => button(label, () => { this.active = key; }, false, { 'aria-pressed': this.active === key })), button('重新加载当前范围', () => this.load(), this.busy)]),
                this.busy ? h('p', { role: 'status', class: 'text-sm text-slate-500' }, '正在读取或保存当前范围…') : null,
                this.error ? h('p', { role: 'alert', class: 'rounded-lg bg-red-50 p-3 text-sm text-red-700' }, this.error) : null,
                this.notice ? h('p', { role: 'status', class: 'rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700' }, this.notice) : null,
                !this.busy ? body : null,
                !this.busy && this.overview?.history?.length ? h('details', { class: 'rounded-lg border p-3' }, [h('summary', { class: 'cursor-pointer text-sm' }, '已保存版本与精确回读'), h('div', { class: 'mt-2 flex flex-wrap gap-2' }, this.overview.history.map(row => button(`版本 #${row.snapshot_id} · ${row.created_at}`, () => this.restore(row.snapshot_id), this.busy)))]) : null,
            ]);
        },
    };
})();
