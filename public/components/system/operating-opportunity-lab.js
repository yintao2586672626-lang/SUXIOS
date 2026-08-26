(() => {
    'use strict';

    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const { h } = Vue;
    const lifecycle = [
        'draft', 'pending_approval', 'approved', 'executing',
        'evidence_recorded', 'review_pending', 'reviewed',
    ];
    const labels = {
        draft: '草稿', pending_approval: '待人工审批', approved: '已审批', executing: '执行中',
        evidence_recorded: '执行证据已记录', review_pending: '等待同口径复盘', reviewed: '已复盘',
        blocked: '已阻塞', no_eligible_item: '暂无合格事项',
    };
    const today = () => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const digestShort = value => {
        const text = String(value || '').trim();
        return /^[a-f0-9]{64}$/i.test(text) ? text.slice(0, 12) : '-';
    };
    const scopeKey = (hotelId, businessDate) => `${Number(hotelId || 0)}|${String(businessDate || '')}`;

    components.OperatingOpportunityLabBody = {
        name: 'OperatingOpportunityLabBody',
        emits: ['open-operations', 'update:selected-hotel-id'],
        props: {
            hotels: { type: Array, default: () => [] },
            request: { type: Function, required: true },
            selectedHotelId: { type: [String, Number], default: '' },
            openTask: { type: Function, default: null },
        },
        data: () => ({
            hotelId: '', businessDate: today(), loading: false, saving: false,
            error: '', overview: null, requestSeq: 0, loadedScope: '',
        }),
        computed: {
            normalizedHotels() {
                return (Array.isArray(this.hotels) ? this.hotels : [])
                    .filter(item => Number(item?.id || 0) > 0)
                    .map(item => ({ id: Number(item.id), name: String(item.name || `酒店 ${item.id}`) }));
            },
            selectedHotel() {
                return this.normalizedHotels.find(item => String(item.id) === String(this.hotelId)) || null;
            },
            todayResult() { return this.overview?.today || null; },
            selected() { return this.todayResult?.selected || null; },
            intent() { return this.overview?.today_execution_intent || null; },
            intentId() { return Number(this.overview?.today_execution_intent_id || this.todayResult?.execution_intent_id || 0); },
            taskId() { return Number(this.overview?.today_execution_task_id || this.todayResult?.execution_task_id || 0); },
            currentStatus() {
                return String(this.overview?.today_lifecycle_status || this.todayResult?.status || 'draft');
            },
            isSavedCurrent() { return this.overview?.today_state === 'saved_current'; },
            canSave() {
                return Boolean(this.hotelId
                    && this.selected
                    && this.overview?.today_state !== 'source_unavailable'
                    && !this.saving
                    && !this.isSavedCurrent);
            },
        },
        watch: {
            selectedHotelId: { immediate: true, handler(value) {
                const candidate = String(value || '');
                if (candidate && this.normalizedHotels.some(item => String(item.id) === candidate)
                    && candidate !== String(this.hotelId || '')
                ) {
                    this.hotelId = candidate;
                    void this.loadOverview();
                }
            } },
            hotels: { immediate: true, deep: false, handler() {
                if (!this.normalizedHotels.some(item => String(item.id) === String(this.hotelId))) {
                    const preferred = String(this.selectedHotelId || '');
                    this.hotelId = this.normalizedHotels.some(item => String(item.id) === preferred)
                        ? preferred
                        : (this.normalizedHotels[0]?.id ? String(this.normalizedHotels[0].id) : '');
                }
                if (this.hotelId) void this.loadOverview();
            } },
            businessDate() { if (this.hotelId) void this.loadOverview(); },
        },
        methods: {
            notify(message, type = 'success') { this.$root?.showToast?.(message, type); },
            statusText(status) { return labels[String(status || '')] || String(status || '未知'); },
            statusClass(status) {
                if (status === 'reviewed') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
                if (status === 'blocked') return 'border-rose-200 bg-rose-50 text-rose-700';
                if (['pending_approval', 'review_pending'].includes(status)) return 'border-amber-200 bg-amber-50 text-amber-800';
                if (['approved', 'executing', 'evidence_recorded'].includes(status)) return 'border-blue-200 bg-blue-50 text-blue-700';
                return 'border-slate-200 bg-slate-50 text-slate-600';
            },
            scoreLabel(key) {
                return ({ impact: '影响', urgency: '紧迫性', evidence_strength: '证据强度', execution_cost: '执行成本' })[key] || key;
            },
            async loadOverview() {
                const hotelId = Number(this.hotelId || 0);
                const businessDate = String(this.businessDate || '');
                if (hotelId <= 0 || !/^\d{4}-\d{2}-\d{2}$/.test(businessDate)) return null;
                const requestScope = scopeKey(hotelId, businessDate);
                const seq = ++this.requestSeq;
                if (this.loadedScope !== requestScope) this.overview = null;
                this.loading = true;
                this.error = '';
                try {
                    const params = new URLSearchParams({ hotel_id: String(hotelId), business_date: businessDate });
                    const res = await this.request(`/operating-opportunities/overview?${params}`, {
                        businessContext: { hotelId },
                    });
                    if (seq !== this.requestSeq || requestScope !== scopeKey(this.hotelId, this.businessDate)) return null;
                    if (res.code !== 200) throw new Error(res.message || '每日一件事读取失败');
                    const data = res.data || {};
                    if (Number(data.system_hotel_id || 0) !== hotelId
                        || String(data.business_date || '') !== businessDate
                        || String(data.today_preview?.contract_version || '') !== 'daily_one_thing.v2'
                        || data.today_preview?.selection_policy?.full_candidate_list_exposed !== false
                    ) throw new Error('每日一件事没有按当前酒店、日期和唯一选择合同精确回读');
                    this.overview = data;
                    this.loadedScope = requestScope;
                    return data;
                } catch (error) {
                    if (seq !== this.requestSeq) return null;
                    this.overview = null;
                    this.loadedScope = '';
                    this.error = error?.message || '每日一件事读取失败';
                    return null;
                } finally {
                    if (seq === this.requestSeq) this.loading = false;
                }
            },
            async savePriority() {
                const hotelId = Number(this.hotelId || 0);
                const businessDate = String(this.businessDate || '');
                const mutationScope = scopeKey(hotelId, businessDate);
                if (!this.canSave) return;
                this.saving = true;
                this.error = '';
                try {
                    const res = await this.request('/operating-opportunities/priority', {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify({
                            hotel_id: hotelId,
                            business_date: businessDate,
                            idempotency_key: `daily-one-thing-${hotelId}-${businessDate}`,
                        }),
                    });
                    if (mutationScope !== scopeKey(this.hotelId, this.businessDate)) return null;
                    const run = res.data?.run || {};
                    const intent = res.data?.execution_intent || {};
                    if (res.code !== 200
                        || res.data?.readback_verified !== true
                        || Number(run.id || 0) <= 0
                        || Number(run.system_hotel_id || 0) !== hotelId
                        || String(run.business_date || '') !== businessDate
                        || run.feature_key !== 'daily_one_thing'
                        || !/^[a-f0-9]{64}$/i.test(String(run.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(run.result_digest || ''))
                        || Number(intent.id || 0) <= 0
                        || intent.source_module !== 'daily_one_thing'
                        || intent.action_management?.contract_version !== 'operation_action_card.v2'
                        || res.data?.external_action_triggered !== false
                        || Number(res.data?.external_write_count ?? -1) !== 0
                    ) throw new Error(res.message || '每日一件事保存后未完成行动与事实精确回读');
                    const reloaded = await this.loadOverview();
                    if (!reloaded
                        || Number(reloaded.today_saved_run?.id || 0) !== Number(run.id)
                        || Number(reloaded.today_execution_intent_id || 0) !== Number(intent.id)
                        || reloaded.today_state !== 'saved_current'
                    ) throw new Error('每日一件事刷新后没有恢复同一行动');
                    this.notify('每日一件事已保存为待人工审批；未执行任何外部写入');
                    return intent;
                } catch (error) {
                    if (mutationScope !== scopeKey(this.hotelId, this.businessDate)) return null;
                    this.error = error?.message || '每日一件事保存失败';
                    this.notify(this.error, 'error');
                    return null;
                } finally {
                    if (mutationScope === scopeKey(this.hotelId, this.businessDate)) this.saving = false;
                }
            },
            openOriginalAction() {
                if (!this.intentId || !this.hotelId) return this.notify('原行动尚未完成精确回读', 'warning');
                const payload = {
                    intentId: this.intentId,
                    hotelId: Number(this.hotelId),
                    hotelName: this.selectedHotel?.name || '',
                };
                if (typeof this.openTask === 'function') {
                    void this.openTask(payload);
                    return;
                }
                this.$emit('open-operations', payload);
            },
        },
        render() {
            const field = (label, node) => h('label', { class: 'block' }, [
                h('span', { class: 'mb-1 block text-xs font-semibold text-slate-500' }, label), node,
            ]);
            const selected = this.selected;
            const factBasis = Array.isArray(selected?.fact_basis) ? selected.fact_basis : [];
            const action = selected?.recommended_action || {};
            const metric = selected?.expected_observation_metric || {};
            const scope = selected?.scope || {};
            const risk = selected?.risk || {};
            const responsibility = selected?.responsibility || {};
            const boundary = selected?.external_write_boundary || {};
            const ranking = selected?.ranking || {};
            const currentIndex = lifecycle.indexOf(this.currentStatus);

            return h('div', { class: 'mx-auto max-w-6xl space-y-5', 'data-testid': 'daily-one-thing-workbench' }, [
                h('section', { class: 'overflow-hidden rounded-3xl border border-[#2c4f45] bg-[#06110d] p-5 text-white shadow-xl sm:p-6' }, [
                    h('div', { class: 'flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between' }, [
                        h('div', [
                            h('p', { class: 'text-xs font-semibold tracking-[0.2em] text-[#dcc591]' }, '宿析OS · 经营执行主线'),
                            h('h2', { class: 'mt-2 text-2xl font-semibold' }, '每日一件事'),
                            h('p', { class: 'mt-2 max-w-2xl text-sm leading-6 text-slate-300' }, '每天只保留当前最值得处理的一项：事实先行、人工审批、原任务执行、同范围复盘。'),
                        ]),
                        h('div', { class: 'grid w-full gap-3 sm:grid-cols-2 lg:w-auto lg:grid-cols-[220px_170px_auto]' }, [
                            field('当前门店', h('select', { value: this.hotelId, class: 'w-full rounded-xl border border-white/20 bg-white px-3 py-2.5 text-sm text-slate-900', onChange: event => { this.hotelId = event.target.value; this.$emit('update:selected-hotel-id', this.hotelId); void this.loadOverview(); } }, [h('option', { value: '' }, '请选择门店'), ...this.normalizedHotels.map(item => h('option', { value: String(item.id) }, item.name))])),
                            field('营业日', h('input', { value: this.businessDate, type: 'date', class: 'w-full rounded-xl border border-white/20 bg-white px-3 py-2.5 text-sm text-slate-900', onInput: event => { this.businessDate = event.target.value; } })),
                            h('button', { type: 'button', disabled: this.loading || !this.hotelId, class: 'self-end rounded-xl border border-[#dcc591]/60 bg-[#143a31] px-4 py-2.5 text-sm font-semibold text-[#f5e8c7] disabled:opacity-50', onClick: () => this.loadOverview() }, this.loading ? '读取中' : '刷新事实'),
                        ]),
                    ]),
                ]),
                this.error ? h('div', { class: 'rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700', role: 'alert' }, this.error) : null,
                this.loading && !this.overview ? h('div', { class: 'rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500' }, '正在读取严格事实、已保存问题和明确缺口…') : null,
                !this.loading && this.overview && !selected ? h('div', { class: 'rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800' }, this.overview?.today_state === 'source_unavailable'
                    ? '严格事实来源暂不可读取；系统已阻止保存和送审，不会把系统故障伪装成数据缺口。'
                    : '当前没有通过来源门槛的每日事项；系统没有用空值或泛化建议补位。') : null,
                selected ? h('section', { class: 'overflow-hidden rounded-3xl border border-[#ddcfb2] bg-[#fffdf8] shadow-lg', 'data-testid': 'daily-one-thing-card' }, [
                    h('header', { class: 'border-b border-[#eadfc9] bg-gradient-to-r from-[#fffaf0] to-white px-5 py-5 sm:px-6' }, [
                        h('div', { class: 'flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between' }, [
                            h('div', { class: 'min-w-0' }, [
                                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                    h('span', { class: `rounded-full border px-2.5 py-1 text-xs font-semibold ${this.statusClass(this.currentStatus)}` }, this.statusText(this.currentStatus)),
                                    h('span', { class: 'rounded-full border border-[#ddcfb2] bg-white px-2.5 py-1 text-xs text-[#6f572f]' }, selected.source_type === 'explicit_data_gap' ? '明确数据缺口' : selected.source_type === 'saved_question' ? '已保存经营问题' : '严格事实信号'),
                                    this.isSavedCurrent ? h('span', { class: 'text-xs text-slate-500' }, `快照 #${this.overview?.today_saved_run?.id}`) : null,
                                ]),
                                h('h3', { class: 'mt-3 text-xl font-semibold leading-8 text-slate-900', 'data-testid': 'daily-one-thing-problem' }, selected.problem),
                                h('p', { class: 'mt-2 text-sm leading-6 text-slate-600' }, action.description),
                            ]),
                            h('div', { class: 'flex shrink-0 flex-col gap-2 sm:items-end' }, [
                                this.canSave ? h('button', { type: 'button', disabled: this.saving, class: 'rounded-xl bg-gradient-to-r from-[#a88a52] to-[#6f572f] px-4 py-2.5 text-sm font-semibold text-white shadow-sm disabled:opacity-50', onClick: () => this.savePriority(), 'data-testid': 'daily-one-thing-save' }, this.saving ? '保存并回读中' : '保存为待人工审批') : null,
                                this.isSavedCurrent && this.intentId ? h('button', { type: 'button', class: 'rounded-xl border border-[#a88a52] bg-white px-4 py-2.5 text-sm font-semibold text-[#6f572f]', onClick: () => this.openOriginalAction(), 'data-testid': 'daily-one-thing-open-original' }, this.taskId ? '继续原任务' : '打开原行动审批') : null,
                                this.overview?.today_state === 'saved_stale' ? h('span', { class: 'max-w-[240px] text-right text-xs leading-5 text-amber-700' }, '旧快照已保留；当前事实身份已变化，不能静默改写原任务。') : null,
                            ]),
                        ]),
                    ]),
                    h('div', { class: 'grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.65fr)]' }, [
                        h('div', { class: 'space-y-5' }, [
                            h('section', [
                                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '事实依据'),
                                h('div', { class: 'mt-2 space-y-2' }, factBasis.map((fact, index) => h('div', { key: `${fact.evidence_ref}-${index}`, class: 'rounded-xl border border-slate-200 bg-white px-4 py-3' }, [
                                    h('p', { class: 'text-sm leading-6 text-slate-700' }, fact.statement),
                                    h('p', { class: 'mt-1 break-all text-[11px] text-slate-400' }, `${fact.evidence_ref} · ${fact.quality_status || '状态未返回'}`),
                                ]))),
                            ]),
                            h('section', [
                                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '建议动作'),
                                h('div', { class: 'mt-2 rounded-xl border border-[#ddcfb2] bg-[#fffaf0] p-4' }, [
                                    h('div', { class: 'font-semibold text-slate-900' }, action.title),
                                    h('ol', { class: 'mt-3 space-y-2 text-sm leading-6 text-slate-700' }, (action.steps || []).map((step, index) => h('li', { class: 'flex gap-2' }, [h('span', { class: 'font-semibold text-[#96743f]' }, `${index + 1}.`), h('span', step)]))),
                                ]),
                            ]),
                            h('section', { class: 'grid gap-3 sm:grid-cols-2' }, [
                                h('div', { class: 'rounded-xl border border-slate-200 bg-white p-4' }, [h('div', { class: 'text-xs text-slate-400' }, '预期观察指标'), h('div', { class: 'mt-1 text-sm font-semibold text-slate-900' }, metric.label || metric.key), h('div', { class: 'mt-1 text-xs text-slate-500' }, `基线 ${metric.baseline_value} ${metric.unit} · 只观察变化`)]),
                                h('div', { class: 'rounded-xl border border-slate-200 bg-white p-4' }, [h('div', { class: 'text-xs text-slate-400' }, '适用范围'), h('div', { class: 'mt-1 text-sm font-semibold text-slate-900' }, `${scope.platform || '-'} · ${scope.business_date || '-'}`), h('div', { class: 'mt-1 text-xs leading-5 text-slate-500' }, scope.scope_note)]),
                            ]),
                        ]),
                        h('aside', { class: 'space-y-4' }, [
                            h('div', { class: 'rounded-2xl border border-slate-200 bg-white p-4' }, [
                                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '负责人和时间'),
                                h('dl', { class: 'mt-3 grid grid-cols-[90px_1fr] gap-y-2 text-xs leading-5' }, [
                                    h('dt', { class: 'text-slate-400' }, '负责人'), h('dd', { class: 'text-slate-700' }, `${responsibility.owner_label || '当前确认人'} · 用户 #${responsibility.owner_id || '-'}`),
                                    h('dt', { class: 'text-slate-400' }, '截止'), h('dd', { class: 'text-slate-700' }, responsibility.due_at || '-'),
                                    h('dt', { class: 'text-slate-400' }, '复盘时间'), h('dd', { class: 'text-slate-700' }, responsibility.review_at || '-'),
                                    h('dt', { class: 'text-slate-400' }, '行动/任务'), h('dd', { class: 'text-slate-700' }, this.intentId ? `行动 #${this.intentId}${this.taskId ? ` · 任务 #${this.taskId}` : ' · 尚未建任务'}` : '尚未保存'),
                                ]),
                            ]),
                            h('div', { class: 'rounded-2xl border border-slate-200 bg-white p-4' }, [
                                h('h4', { class: 'text-sm font-semibold text-slate-900' }, '四维排序'),
                                h('div', { class: 'mt-3 grid grid-cols-2 gap-2' }, ['impact', 'urgency', 'evidence_strength', 'execution_cost'].map(key => h('div', { class: 'rounded-lg bg-slate-50 px-3 py-2' }, [h('div', { class: 'text-[11px] text-slate-400' }, this.scoreLabel(key)), h('div', { class: 'mt-1 text-lg font-semibold text-slate-800' }, String(ranking[key] ?? '-'))]))),
                                h('p', { class: 'mt-2 text-[11px] leading-5 text-slate-400' }, '排序分只分配注意力，不代表收入、概率或因果。'),
                            ]),
                            h('div', { class: 'rounded-2xl border border-amber-200 bg-amber-50 p-4' }, [
                                h('h4', { class: 'text-sm font-semibold text-amber-900' }, `风险 · ${risk.level || 'medium'}`),
                                h('p', { class: 'mt-2 text-xs leading-5 text-amber-800' }, risk.summary),
                                h('p', { class: 'mt-2 text-[11px] leading-5 text-amber-700' }, '审批前外部写入：0 次。系统不自动修改携程、美团、PMS，也不自动发送企业微信。'),
                            ]),
                        ]),
                    ]),
                    h('footer', { class: 'border-t border-[#eadfc9] bg-white px-5 py-4 sm:px-6' }, [
                        h('div', { class: 'grid gap-2 sm:grid-cols-4 lg:grid-cols-7', 'data-testid': 'daily-one-thing-lifecycle' }, lifecycle.map((status, index) => {
                            const done = currentIndex >= 0 && index < currentIndex;
                            const active = status === this.currentStatus;
                            return h('div', { class: `rounded-lg border px-2 py-2 text-center text-[11px] ${active ? 'border-[#a88a52] bg-[#fff7e6] font-semibold text-[#6f572f]' : done ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-400'}` }, this.statusText(status));
                        })),
                        this.currentStatus === 'blocked' ? h('div', { class: 'rounded-lg border border-rose-200 bg-rose-50 px-2 py-2 text-center text-[11px] font-semibold text-rose-700' }, '已阻塞') : null,
                        h('div', { class: 'sm:col-span-4 lg:col-span-7 mt-1 flex flex-wrap justify-between gap-2 text-[11px] text-slate-400' }, [
                            h('span', `选择摘要 ${digestShort(selected.content_digest)} · 来源摘要 ${digestShort(selected.source?.snapshot_digest)}`),
                            h('span', boundary.causality_claimed === false ? '前后变化只作观察，不声明因果' : '因果边界未返回'),
                        ]),
                    ]),
                ]) : null,
            ]);
        },
    };
})();
