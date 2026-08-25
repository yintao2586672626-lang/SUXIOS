(() => {
    'use strict';

    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const { h } = Vue;
    const today = () => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const nowLocal = () => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
    };
    const normalizeObservedAt = value => {
        const text = String(value || '').trim();
        return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(text) ? `${text}:00` : text;
    };
    const key = prefix => {
        const suffix = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
        return `${prefix}-${suffix}`;
    };
    const numberOrNull = value => {
        if (value === '' || value === null || value === undefined) return null;
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    };
    const checked = value => value === true;

    const makeForms = () => ({
        service_promise_risk: {
            benefit_type: 'early_checkin', promised_quantity: '', fulfillable_capacity: '',
            breach_cost_per_unit: '', source_quality: 'manual_unverified', source_reference: '',
        },
        promotion_incrementality: {
            promotion_name: '', treated_before: '', treated_after: '', control_before: '', control_after: '',
            discount_cost: '', contribution_per_room_night: '', design_quality: 'unverified',
            pretrend_status: 'not_checked', sample_size: '', source_quality: 'manual_unverified', source_reference: '',
        },
        bookability_gap: {
            platform: 'ctrip', pms_expected_sellable: '', adults: 2, children: 0, benefits: '',
            search_status: 'bookable', detail_status: 'bookable', pre_checkout_status: 'bookable',
            observed_at: nowLocal(), real_demand_estimate: '',
            source_quality: 'manual_unverified', source_reference: '',
        },
        ai_guest_acquisition: {
            intent: '', model: 'ChatGPT', region: '中国',
            source_quality: 'manual_unverified', source_reference: '',
            repeats: [1, 2, 3].map(repeat_no => ({
                observed_at: '', evidence_ref: '',
                repeat_no, hotel_identified: false, facts_checked: false, facts_correct: false,
                matched: false, bookable_handoff: false,
            })),
        },
    });

    const tabs = [
        ['service_promise_risk', '权益履约预警', '明天哪些订单可能接不住？'],
        ['promotion_incrementality', '促销真实增量', '这个活动到底赚没赚？'],
        ['bookability_gap', '客人端真实可售', '明明有房，客人为什么订不到？'],
        ['ai_guest_acquisition', 'AI客源检测', 'AI为什么不推荐我？'],
    ];

    components.OperatingOpportunityLabBody = {
        name: 'OperatingOpportunityLabBody',
        props: {
            hotels: { type: Array, default: () => [] },
            request: { type: Function, required: true },
        },
        data: () => ({
            hotelId: '', businessDate: today(), activeFeature: 'service_promise_risk',
            loading: false, savingFeature: '', prioritySaving: false, error: '', overview: null,
            forms: makeForms(), requestSeq: 0,
        }),
        computed: {
            normalizedHotels() {
                return (Array.isArray(this.hotels) ? this.hotels : [])
                    .filter(item => Number(item?.id || 0) > 0)
                    .map(item => ({ id: Number(item.id), name: String(item.name || `酒店 ${item.id}`) }));
            },
            latestByFeature() {
                const map = {};
                (Array.isArray(this.overview?.latest_runs) ? this.overview.latest_runs : []).forEach(run => {
                    if (run?.feature_key) map[run.feature_key] = run;
                });
                return map;
            },
            todayResult() {
                return this.overview?.today || null;
            },
            history() {
                return Array.isArray(this.overview?.history) ? this.overview.history : [];
            },
        },
        watch: {
            hotels: { immediate: true, deep: false, handler() {
                if (!this.normalizedHotels.some(item => String(item.id) === String(this.hotelId))) {
                    this.hotelId = this.normalizedHotels[0]?.id ? String(this.normalizedHotels[0].id) : '';
                }
                if (this.hotelId) void this.loadOverview();
            } },
            businessDate() { if (this.hotelId) void this.loadOverview(); },
        },
        methods: {
            notify(message, type = 'success') { this.$root?.showToast?.(message, type); },
            status(result = {}) {
                const explicit = result.status || result.effect_status || result.verdict;
                if (explicit) return String(explicit);
                if (result.blocked_by_missing_evidence === true) return 'blocked_by_missing_evidence';
                if (result.gap_detected === true) return 'gap_detected';
                if (result.aligned === true) return 'aligned';
                return 'unverified';
            },
            statusText(status) {
                return ({
                    risk_detected: '发现履约风险', capacity_available: '容量充足', blocked_by_missing_facts: '缺少事实',
                    supported: '估算赚钱', contradicted: '估算没赚', indeterminate: '还不能判断',
                    gap_detected: '发现不可售断点', aligned: '三方状态一致', blocked_by_missing_evidence: '证据不足',
                    measured: '已形成检测结果', insufficient_repeatability: '重复性不足',
                    action_required: '今天需要处理', no_action: '今天无需动作',
                })[status] || status || '未计算';
            },
            statusClass(status) {
                if (['risk_detected', 'contradicted', 'gap_detected'].includes(status)) return 'border-rose-200 bg-rose-50 text-rose-700';
                if (['supported', 'capacity_available', 'aligned', 'measured', 'no_action'].includes(status)) return 'border-emerald-200 bg-emerald-50 text-emerald-700';
                return 'border-amber-200 bg-amber-50 text-amber-800';
            },
            money(value) {
                const amount = numberOrNull(value);
                return amount === null ? '未计算' : `¥${amount.toLocaleString('zh-CN', { maximumFractionDigits: 2 })}`;
            },
            percent(value) {
                const amount = numberOrNull(value?.pass_rate_percent ?? value);
                return amount === null ? '未计算' : `${amount.toLocaleString('zh-CN', { maximumFractionDigits: 2 })}%`;
            },
            latest(featureKey) { return this.latestByFeature[featureKey] || null; },
            async loadOverview() {
                const hotelId = Number(this.hotelId || 0);
                if (hotelId <= 0 || !this.businessDate) return null;
                const requestSeq = ++this.requestSeq;
                this.loading = true;
                this.error = '';
                try {
                    const params = new URLSearchParams({ hotel_id: String(hotelId), business_date: this.businessDate });
                    const res = await this.request(`/operating-opportunities/overview?${params}`, {
                        businessContext: { hotelId },
                    });
                    if (requestSeq !== this.requestSeq || hotelId !== Number(this.hotelId || 0)) return null;
                    if (res.code !== 200) throw new Error(res.message || '经营机会读取失败');
                    if (Number(res.data?.system_hotel_id || 0) !== hotelId
                        || String(res.data?.business_date || '') !== this.businessDate
                        || !Array.isArray(res.data?.catalog)) {
                        throw new Error('经营机会没有按当前酒店和业务日期精确回读');
                    }
                    this.overview = res.data;
                    return res.data;
                } catch (error) {
                    if (requestSeq !== this.requestSeq) return null;
                    this.overview = null;
                    this.error = error?.message || '经营机会读取失败';
                    return null;
                } finally {
                    if (requestSeq === this.requestSeq) this.loading = false;
                }
            },
            sourcePayload(form) {
                const sourceReference = String(form.source_reference || '').trim();
                return {
                    source_quality: form.source_quality,
                    source_quality_status: form.source_quality,
                    source_reference: sourceReference,
                    source_references: sourceReference ? [sourceReference] : [],
                };
            },
            buildPayload(featureKey) {
                const hotel_id = Number(this.hotelId || 0);
                const base = {
                    feature_key: featureKey, hotel_id, business_date: this.businessDate,
                    idempotency_key: key(`opportunity-${featureKey}`),
                };
                const form = this.forms[featureKey];
                if (featureKey === 'service_promise_risk') {
                    return {
                        ...base, ...this.sourcePayload(form),
                        benefit_type: form.benefit_type, promise_type: form.benefit_type,
                        promised_quantity: numberOrNull(form.promised_quantity), promised_count: numberOrNull(form.promised_quantity),
                        fulfillable_capacity: numberOrNull(form.fulfillable_capacity), available_capacity: numberOrNull(form.fulfillable_capacity),
                        breach_cost_per_unit: numberOrNull(form.breach_cost_per_unit), unit_failure_cost: numberOrNull(form.breach_cost_per_unit),
                    };
                }
                if (featureKey === 'promotion_incrementality') {
                    const contribution = numberOrNull(form.contribution_per_room_night);
                    return {
                        ...base, ...this.sourcePayload(form), promotion_name: form.promotion_name,
                        treated_before: numberOrNull(form.treated_before), treated_after: numberOrNull(form.treated_after),
                        control_before: numberOrNull(form.control_before), control_after: numberOrNull(form.control_after),
                        discount_cost: numberOrNull(form.discount_cost),
                        contribution_per_room_night: contribution,
                        contribution_per_incremental_room_night: contribution,
                        incremental_contribution_per_room_night: contribution,
                        design_quality: form.design_quality, design_type: form.design_quality,
                        pretrend_status: form.pretrend_status,
                        pretrend_passed: form.pretrend_status === 'passed',
                        sample_size: numberOrNull(form.sample_size),
                    };
                }
                if (featureKey === 'bookability_gap') {
                    const source = this.sourcePayload(form);
                    const benefits = String(form.benefits || '').split(/[,，、]/).map(item => item.trim()).filter(Boolean);
                    const observations = [{
                        condition_id: 'guest-condition-1', adults: Number(form.adults || 0),
                        children: Number(form.children || 0), benefits,
                        search: form.search_status, detail: form.detail_status,
                        pre_checkout: form.pre_checkout_status,
                        observed_at: normalizeObservedAt(form.observed_at), source_quality: form.source_quality,
                        evidence_ref: source.source_reference,
                    }];
                    const expected = numberOrNull(form.pms_expected_sellable);
                    const demand = numberOrNull(form.real_demand_estimate);
                    return {
                        ...base, ...source, platform: form.platform,
                        pms_expected_sellable: expected, observations,
                        ...(demand === null ? {} : { real_demand_estimate: demand }),
                    };
                }
                const source = this.sourcePayload(form);
                return {
                    ...base, ...source,
                    observations: form.repeats.map(row => ({
                        intent: String(form.intent || '').trim(), model: String(form.model || '').trim(),
                        region: String(form.region || '').trim(), observed_at: normalizeObservedAt(row.observed_at),
                        repeat_no: Number(row.repeat_no), hotel_identified: checked(row.hotel_identified),
                        facts_checked: checked(row.facts_checked), facts_correct: checked(row.facts_correct),
                        matched: checked(row.matched), bookable_handoff: checked(row.bookable_handoff),
                        source_quality: form.source_quality, evidence_ref: String(row.evidence_ref || '').trim(),
                    })),
                };
            },
            async submitFeature(featureKey) {
                const hotelId = Number(this.hotelId || 0);
                if (hotelId <= 0) return this.notify('请先选择门店', 'warning');
                this.savingFeature = featureKey;
                this.error = '';
                try {
                    const res = await this.request('/operating-opportunities/evaluate', {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify(this.buildPayload(featureKey)),
                    });
                    if (res.code !== 200) throw new Error(res.message || '计算失败');
                    const run = res.data?.run || {};
                    if (res.data?.readback_verified !== true
                        || Number(run.system_hotel_id || 0) !== hotelId
                        || run.feature_key !== featureKey
                        || !/^[a-f0-9]{64}$/i.test(String(run.input_digest || ''))
                        || !/^[a-f0-9]{64}$/i.test(String(run.result_digest || ''))) {
                        throw new Error('计算结果保存后没有完成精确回读');
                    }
                    await this.loadOverview();
                    this.notify(res.data.replayed ? '已读取同一次计算结果' : '计算完成，已保存并回读');
                } catch (error) {
                    this.error = error?.message || '计算失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.savingFeature = '';
                }
            },
            async savePriority() {
                const hotelId = Number(this.hotelId || 0);
                if (hotelId <= 0) return this.notify('请先选择门店', 'warning');
                this.prioritySaving = true;
                this.error = '';
                try {
                    const res = await this.request('/operating-opportunities/priority', {
                        method: 'POST', businessContext: { hotelId }, body: JSON.stringify({
                            hotel_id: hotelId, business_date: this.businessDate,
                            idempotency_key: key('daily-one-thing'),
                        }),
                    });
                    if (res.code !== 200 || res.data?.readback_verified !== true) {
                        throw new Error(res.message || '今日一件事保存后未完成回读');
                    }
                    await this.loadOverview();
                    this.notify('今日一件事已保存并回读');
                } catch (error) {
                    this.error = error?.message || '今日一件事生成失败';
                    this.notify(this.error, 'error');
                } finally {
                    this.prioritySaving = false;
                }
            },
            resultMetrics(featureKey, result = {}) {
                if (featureKey === 'service_promise_risk') return [
                    ['短缺数量', result.shortage_quantity],
                    ['剩余容量', result.surplus_quantity],
                    ['预计风险金额', this.money(result.risk_amount)],
                ];
                if (featureKey === 'promotion_incrementality') return [
                    ['增量间夜', result.incremental_room_nights ?? result.incremental_effect],
                    ['增量贡献', this.money(result.incremental_contribution)],
                    ['净增量利润', this.money(result.net_incremental_profit ?? result.incremental_net_profit)],
                ];
                if (featureKey === 'bookability_gap') return [
                    ['受影响条件', (result.affected_conditions || []).length],
                    ['最早断点', ({ search: '搜索页', detail: '详情页', pre_checkout: '提交订单前' })[result.earliest_failure_stage] || result.earliest_failure_stage],
                    ['潜在损失', result.potential_loss === null || result.potential_loss === undefined ? '未计算' : `${result.potential_loss} 间夜`],
                ];
                const gates = result.gate_pass_rates || {};
                return [
                    ['酒店被识别', this.percent(gates.hotel_identified)],
                    ['事实正确', this.percent(gates.facts_correct)],
                    ['需求匹配', this.percent(gates.matched)],
                    ['走到可订', this.percent(gates.bookable_handoff)],
                ];
            },
        },
        render() {
            const field = (label, node, help = '') => h('label', { class: 'block' }, [
                h('span', { class: 'mb-1 block text-xs font-semibold text-slate-600' }, label), node,
                help ? h('span', { class: 'mt-1 block text-[11px] leading-4 text-slate-400' }, help) : null,
            ]);
            const input = (form, name, type = 'text', attrs = {}) => h('input', {
                value: form[name], type, ...attrs,
                class: `w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm ${attrs.class || ''}`,
                onInput: event => { form[name] = event.target.value; },
            });
            const select = (form, name, options) => h('select', {
                value: form[name], class: 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm',
                onChange: event => { form[name] = event.target.value; },
            }, options.map(([value, label]) => h('option', { value }, label)));
            const sourceFields = (form, featureKey) => {
                const systemOnly = ['service_promise_risk', 'promotion_incrementality'].includes(featureKey);
                const options = systemOnly
                    ? [['manual_unverified', '手工录入 · 未核验'], ['verified', '系统证据已核验'], ['readback_verified', '系统证据保存回读已核验']]
                    : [['manual_unverified', '手工录入 · 未核验'], ['manual_verified', '人工逐项核对'], ['verified', '系统证据已核验'], ['readback_verified', '系统证据保存回读已核验']];
                const help = systemOnly
                    ? '手工未核验可以保存估算，但只有系统核验或保存回读证据才形成结论。'
                    : '人工核对只描述本次逐项观察；系统不会自动升级为线上采集事实。';
                return h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [
                    field('数据状态', select(form, 'source_quality', options), help),
                    field(featureKey === 'ai_guest_acquisition' ? '本次证据包引用（可选）' : '来源引用', input(form, 'source_reference', 'text', { maxlength: 1000, placeholder: '文件、回执或页面观察编号' })),
                ]);
            };
            const latest = this.latest(this.activeFeature);
            const result = latest?.result || null;
            const activeMeta = tabs.find(item => item[0] === this.activeFeature) || tabs[0];
            const form = this.forms[this.activeFeature];

            let formBody;
            if (this.activeFeature === 'service_promise_risk') {
                formBody = [
                    h('div', { class: 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }, [
                        field('权益类型', select(form, 'benefit_type', [['early_checkin', '提前入住'], ['late_checkout', '延迟退房'], ['room_upgrade', '免费升房'], ['breakfast', '早餐'], ['parking', '停车'], ['shuttle', '接送']])),
                        field('已承诺数量', input(form, 'promised_quantity', 'number', { min: 0, step: 1, required: true })),
                        field('真实可履约容量', input(form, 'fulfillable_capacity', 'number', { min: 0, step: 1, required: true })),
                        field('单次违约成本', input(form, 'breach_cost_per_unit', 'number', { min: 0, step: '0.01', required: true })),
                    ]), sourceFields(form, this.activeFeature),
                ];
            } else if (this.activeFeature === 'promotion_incrementality') {
                formBody = [
                    field('活动名称', input(form, 'promotion_name', 'text', { maxlength: 120, required: true, placeholder: '例如：暑期连住优惠' })),
                    h('div', { class: 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }, [
                        field('参与组·活动前间夜', input(form, 'treated_before', 'number', { min: 0, step: '0.01', required: true })),
                        field('参与组·活动后间夜', input(form, 'treated_after', 'number', { min: 0, step: '0.01', required: true })),
                        field('对照组·活动前间夜', input(form, 'control_before', 'number', { min: 0, step: '0.01', required: true })),
                        field('对照组·活动后间夜', input(form, 'control_after', 'number', { min: 0, step: '0.01', required: true })),
                    ]),
                    h('div', { class: 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }, [
                        field('活动折扣总成本', input(form, 'discount_cost', 'number', { min: 0, step: '0.01', required: true })),
                        field('每增量间夜贡献', input(form, 'contribution_per_room_night', 'number', { min: 0, step: '0.01', required: true })),
                        field('实验设计', select(form, 'design_quality', [['unverified', '未验证对照'], ['validated_matched', '已验证匹配对照'], ['randomized', '随机对照']])),
                        field('样本量', input(form, 'sample_size', 'number', { min: 0, step: 1, required: true })),
                    ]),
                    field('前趋势检查', select(form, 'pretrend_status', [['not_checked', '未检查'], ['passed', '已通过'], ['failed', '未通过']])),
                    sourceFields(form, this.activeFeature),
                ];
            } else if (this.activeFeature === 'bookability_gap') {
                formBody = [
                    h('div', { class: 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4' }, [
                        field('平台', select(form, 'platform', [['ctrip', '携程'], ['meituan', '美团']])),
                        field('PMS预期可售数', input(form, 'pms_expected_sellable', 'number', { min: 0, step: 1, required: true })),
                        field('成人数', input(form, 'adults', 'number', { min: 1, max: 20, step: 1, required: true })),
                        field('儿童数', input(form, 'children', 'number', { min: 0, max: 20, step: 1, required: true })),
                    ]),
                    field('权益条件', input(form, 'benefits', 'text', { maxlength: 120, placeholder: '例如：含早、可取消；多个条件用逗号分隔' })),
                    h('div', { class: 'grid gap-3 sm:grid-cols-3' }, [
                        field('搜索页', select(form, 'search_status', [['bookable', '可售'], ['unavailable', '不可售'], ['error', '页面错误']])),
                        field('详情页', select(form, 'detail_status', [['bookable', '可售'], ['unavailable', '不可售'], ['error', '页面错误']])),
                        field('提交订单前', select(form, 'pre_checkout_status', [['bookable', '可售'], ['unavailable', '不可售'], ['error', '页面错误']])),
                    ]),
                    h('div', { class: 'grid gap-3 sm:grid-cols-3' }, [
                        field('观察时间', input(form, 'observed_at', 'datetime-local', { required: true })),
                        field('真实需求估计（可选）', input(form, 'real_demand_estimate', 'number', { min: 0, step: '0.01' })),
                    ]), sourceFields(form, this.activeFeature),
                ];
            } else {
                formBody = [
                    field('真实客人问题', input(form, 'intent', 'text', { maxlength: 300, required: true, placeholder: '例如：凌晨到店、有停车、适合医院陪护的酒店' })),
                    h('div', { class: 'grid gap-3 sm:grid-cols-2' }, [
                        field('AI入口', input(form, 'model', 'text', { maxlength: 80, required: true })),
                        field('地区', input(form, 'region', 'text', { maxlength: 80, required: true })),
                    ]),
                    h('div', { class: 'grid gap-3 lg:grid-cols-3' }, form.repeats.map(row => h('div', {
                        key: row.repeat_no, class: 'rounded-xl border border-slate-200 bg-slate-50 p-3',
                    }, [
                        h('div', { class: 'text-xs font-semibold text-slate-700' }, `重复观测 ${row.repeat_no}`),
                        h('div', { class: 'mt-3 space-y-3' }, [
                            field('本次观察时间', input(row, 'observed_at', 'datetime-local', { required: true, step: 1 }), '三次必须是不同的实际观察时间。'),
                            field('本次证据引用', input(row, 'evidence_ref', 'text', { maxlength: 1000, required: true, placeholder: `例如：AI观察回执-${row.repeat_no}` }), '三次必须对应不同的截图、回执或观察记录。'),
                        ]),
                        h('label', { class: 'mt-3 flex items-center gap-2 text-xs text-slate-600' }, [h('input', { type: 'checkbox', checked: row.hotel_identified, onChange: e => { row.hotel_identified = e.target.checked; } }), '识别到本酒店']),
                        h('label', { class: 'mt-2 flex items-center gap-2 text-xs text-slate-600' }, [h('input', { type: 'checkbox', checked: row.facts_checked, onChange: e => { row.facts_checked = e.target.checked; if (!e.target.checked) row.facts_correct = false; } }), '已核对酒店事实']),
                        h('label', { class: 'mt-2 flex items-center gap-2 text-xs text-slate-600' }, [h('input', { type: 'checkbox', disabled: !row.facts_checked, checked: row.facts_correct, onChange: e => { row.facts_correct = e.target.checked; } }), '核对结果正确']),
                        h('label', { class: 'mt-2 flex items-center gap-2 text-xs text-slate-600' }, [h('input', { type: 'checkbox', checked: row.matched, onChange: e => { row.matched = e.target.checked; } }), '判断为需求匹配']),
                        h('label', { class: 'mt-2 flex items-center gap-2 text-xs text-slate-600' }, [h('input', { type: 'checkbox', checked: row.bookable_handoff, onChange: e => { row.bookable_handoff = e.target.checked; } }), '走到真实可订入口']),
                    ]))), sourceFields(form, this.activeFeature),
                ];
            }

            return h('div', { class: 'mx-auto max-w-7xl space-y-5', 'data-testid': 'operating-opportunity-lab' }, [
                h('section', { class: 'overflow-hidden rounded-3xl border border-[#2c4f45] bg-[#06110d] p-5 text-white shadow-xl sm:p-6' }, [
                    h('div', { class: 'flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between' }, [
                        h('div', [
                            h('p', { class: 'text-xs font-semibold tracking-[0.2em] text-[#dcc591]' }, '宿析OS · 新增卖点功能'),
                            h('h2', { class: 'mt-2 text-2xl font-semibold' }, '经营机会'),
                            h('p', { class: 'mt-2 max-w-3xl text-sm leading-6 text-slate-300' }, '不是报告展示：每项都能输入证据、计算、保存、刷新回显。缺数据就明确阻断，不自动改OTA。'),
                        ]),
                        h('div', { class: 'grid w-full gap-3 sm:grid-cols-2 lg:w-auto lg:grid-cols-[220px_170px_auto]' }, [
                            field('当前门店', h('select', { value: this.hotelId, class: 'w-full rounded-xl border border-white/20 bg-white px-3 py-2.5 text-sm text-slate-900', onChange: e => { this.hotelId = e.target.value; void this.loadOverview(); } }, [h('option', { value: '' }, '请选择门店'), ...this.normalizedHotels.map(item => h('option', { value: String(item.id) }, item.name))])),
                            field('业务日期', h('input', { value: this.businessDate, type: 'date', class: 'w-full rounded-xl border border-white/20 bg-white px-3 py-2.5 text-sm text-slate-900', onInput: e => { this.businessDate = e.target.value; } })),
                            h('button', { type: 'button', disabled: this.loading || !this.hotelId, class: 'self-end rounded-xl border border-[#dcc591]/60 bg-[#143a31] px-4 py-2.5 text-sm font-semibold text-[#f5e8c7] disabled:opacity-50', onClick: () => this.loadOverview() }, this.loading ? '读取中' : '刷新结果'),
                        ]),
                    ]),
                ]),
                this.error ? h('div', { class: 'rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700', role: 'alert' }, this.error) : null,
                !this.hotelId ? h('div', { class: 'rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800' }, '请先选择一个有权限的门店；五项功能都不会跨店汇总。') : null,
                h('section', { class: 'overflow-hidden rounded-2xl border border-[#eadfc9] bg-[#fffdf8] shadow-sm', 'data-testid': 'daily-one-thing-card' }, [
                    h('div', { class: 'flex flex-col gap-3 border-b border-[#eadfc9] px-5 py-4 sm:flex-row sm:items-center sm:justify-between' }, [
                        h('div', [h('p', { class: 'text-xs font-semibold text-[#96743f]' }, '卖点 1'), h('h3', { class: 'mt-1 text-lg font-semibold text-slate-900' }, '今天先做哪件事')]),
                        h('button', { type: 'button', disabled: this.prioritySaving || !this.hotelId, class: 'rounded-xl bg-[#6f572f] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50', onClick: () => this.savePriority() }, this.prioritySaving ? '保存中' : '生成并保存今日一件事'),
                    ]),
                    h('div', { class: 'p-5' }, this.todayResult ? [
                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                            h('span', { class: `rounded-full border px-2.5 py-1 text-xs font-semibold ${this.statusClass(this.todayResult.status)}` }, this.statusText(this.todayResult.status)),
                            this.todayResult.selected ? h('span', { class: 'text-xs text-slate-500' }, this.todayResult.selected.feature_label) : null,
                            this.overview?.today_state === 'saved_current' ? h('span', { class: 'rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700' }, `已保存并回读 #${this.overview.today_saved_run?.id}`) : null,
                            this.overview?.today_state === 'saved_stale' ? h('span', { class: 'rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800' }, '已有旧快照，当前结果尚未保存') : null,
                            this.overview?.today_state === 'not_saved' ? h('span', { class: 'rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600' }, '当前预览尚未保存') : null,
                        ]),
                        h('div', { class: 'mt-3 text-xl font-semibold text-slate-900' }, this.todayResult.headline || '尚未形成优先事项'),
                        this.todayResult.selected ? h('div', { class: 'mt-2 text-sm leading-6 text-slate-600' }, `${this.todayResult.selected.reason || ''}。下一步：${this.todayResult.selected.next_step || '核对详情后决定'}。`) : null,
                        h('p', { class: 'mt-3 text-xs text-slate-400' }, this.todayResult.selection_boundary || '仅排序，不自动执行。'),
                    ] : [h('p', { class: 'text-sm text-slate-500' }, '先完成下面任意一项计算，再生成今日一件事。')]),
                ]),
                h('section', { class: 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm' }, [
                    h('nav', { class: 'grid border-b border-slate-200 sm:grid-cols-2 xl:grid-cols-4', 'aria-label': '经营机会功能' }, tabs.map(([featureKey, label, question]) => h('button', {
                        type: 'button', class: `border-b-2 px-4 py-4 text-left transition ${this.activeFeature === featureKey ? 'border-[#6f572f] bg-[#fffdf8]' : 'border-transparent hover:bg-slate-50'}`,
                        onClick: () => { this.activeFeature = featureKey; },
                    }, [h('div', { class: 'text-sm font-semibold text-slate-900' }, label), h('div', { class: 'mt-1 text-xs text-slate-500' }, question)]))),
                    h('div', { class: 'grid gap-5 p-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]' }, [
                        h('form', { class: 'space-y-4', onSubmit: e => { e.preventDefault(); void this.submitFeature(this.activeFeature); } }, [
                            h('div', [h('h3', { class: 'text-lg font-semibold text-slate-900' }, activeMeta[2]), h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, '本次计算会作为新记录追加保存；不会覆盖历史，也不会触发平台写入。')]),
                            ...formBody,
                            h('button', { type: 'submit', disabled: this.savingFeature === this.activeFeature || !this.hotelId, class: 'w-full rounded-xl bg-[#143a31] px-4 py-3 text-sm font-semibold text-white hover:bg-[#0d2b24] disabled:opacity-50' }, this.savingFeature === this.activeFeature ? '计算、保存并回读中' : '开始计算并保存结果'),
                        ]),
                        h('aside', { class: 'rounded-2xl border border-slate-200 bg-slate-50 p-4' }, result ? [
                            h('div', { class: 'flex items-center justify-between gap-3' }, [
                                h('div', [h('div', { class: 'text-xs text-slate-500' }, `最近结果 #${latest.id}`), h('div', { class: 'mt-1 text-sm font-semibold text-slate-900' }, latest.feature_label)]),
                                h('span', { class: `rounded-full border px-2 py-1 text-xs font-semibold ${this.statusClass(this.status(result))}` }, this.statusText(this.status(result))),
                            ]),
                            h('div', { class: 'mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-1' }, this.resultMetrics(this.activeFeature, result).map(([label, value]) => h('div', { class: 'rounded-xl border border-slate-200 bg-white px-3 py-2.5' }, [h('div', { class: 'text-[11px] text-slate-400' }, label), h('div', { class: 'mt-1 break-words text-sm font-semibold text-slate-800' }, value === null || value === undefined || value === '' ? '未计算' : String(value))]))),
                            h('div', { class: 'mt-3 text-xs leading-5 text-slate-500' }, `来源：${latest.source_quality_status} · ${latest.source_reference || '未填写引用'}`),
                            h('details', { class: 'mt-3' }, [h('summary', { class: 'cursor-pointer text-xs font-medium text-[#315d50]' }, '查看完整计算结果'), h('pre', { class: 'mt-2 max-h-72 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-900 p-3 text-[11px] leading-5 text-slate-100' }, JSON.stringify(result, null, 2))]),
                        ] : [h('div', { class: 'py-10 text-center text-sm text-slate-500' }, '当前门店和日期暂无该项结果。完成左侧计算后，这里会显示保存回读结果。')]),
                    ]),
                ]),
                h('section', { class: 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm' }, [
                    h('div', { class: 'flex items-center justify-between gap-3' }, [h('h3', { class: 'font-semibold text-slate-900' }, '最近保存记录'), h('span', { class: 'text-xs text-slate-400' }, '最多显示30条')]),
                    this.history.length ? h('div', { class: 'mt-3 overflow-x-auto' }, [h('table', { class: 'min-w-full text-left text-xs' }, [
                        h('thead', { class: 'bg-slate-50 text-slate-500' }, [h('tr', ['功能', '业务日期', '状态', '来源', '保存时间'].map(label => h('th', { class: 'px-3 py-2 font-medium' }, label)))]),
                        h('tbody', { class: 'divide-y divide-slate-100' }, this.history.map(run => h('tr', { key: run.id }, [
                            h('td', { class: 'px-3 py-2.5 font-medium text-slate-800' }, `${run.feature_label} #${run.id}`),
                            h('td', { class: 'px-3 py-2.5 text-slate-600' }, run.business_date),
                            h('td', { class: 'px-3 py-2.5' }, h('span', { class: `rounded-full border px-2 py-0.5 ${this.statusClass(this.status(run.result))}` }, this.statusText(this.status(run.result)))),
                            h('td', { class: 'px-3 py-2.5 text-slate-500' }, run.source_quality_status),
                            h('td', { class: 'px-3 py-2.5 text-slate-500' }, run.created_at),
                        ]))),
                    ])]) : h('p', { class: 'mt-3 text-sm text-slate-500' }, '暂无保存记录。'),
                ]),
            ]);
        },
    };
})();
