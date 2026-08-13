(() => {
    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});

    const numeric = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    };
    const numberText = (value, digits = 0) => {
        const number = numeric(value);
        return number === null
            ? '不可计算'
            : number.toLocaleString('zh-CN', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    };
    const moneyText = (value) => {
        const number = numeric(value);
        return number === null
            ? '不可计算'
            : `¥${number.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    };
    const percentText = (value) => {
        const number = numeric(value);
        return number === null ? '不可计算' : `${(number * 100).toFixed(1)}%`;
    };
    const safeRows = (value) => Array.isArray(value) ? value : [];

    components.CtripOrderAnalysisPanelBody = {
        name: 'CtripOrderAnalysisPanelBody',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
        },
        data() {
            return {
                analysis: null,
                loading: false,
                error: '',
                dateFrom: '',
                dateTo: '',
                requestSequence: 0,
            };
        },
        computed: {
            systemHotelId() {
                const value = Number(this.ctx?.platformHotelSelectedId || 0);
                return Number.isInteger(value) && value > 0 ? value : 0;
            },
            uploadReceiptKey() {
                const result = this.ctx?.ctripChannelOrderUploadResult || {};
                return [result.task_id || '', result.import_readback?.readback_count || '', result.status || ''].join(':');
            },
            status() {
                return String(this.analysis?.status || 'no_data');
            },
            statusLabel() {
                return ({
                    available_unverified: '已保存 · 来源待核验',
                    available_partial: '部分可分析',
                    partial: '部分可分析',
                    indeterminate: '证据冲突',
                    no_data: '暂无订单数据',
                })[this.status] || '状态待确认';
            },
            statusClass() {
                return ({
                    available_unverified: 'border-amber-200 bg-amber-50 text-amber-800',
                    available_partial: 'border-amber-200 bg-amber-50 text-amber-800',
                    partial: 'border-amber-200 bg-amber-50 text-amber-800',
                    indeterminate: 'border-red-200 bg-red-50 text-red-700',
                    no_data: 'border-slate-200 bg-slate-50 text-slate-600',
                })[this.status] || 'border-slate-200 bg-slate-50 text-slate-600';
            },
            summary() {
                return this.analysis?.summary && typeof this.analysis.summary === 'object'
                    ? this.analysis.summary
                    : {};
            },
            channels() {
                return safeRows(this.analysis?.channels);
            },
            missingDimensions() {
                return safeRows(this.analysis?.missing_dimensions);
            },
            losDistribution() {
                return this.analysis?.distributions?.los || { status: 'evidence_missing', buckets: [] };
            },
            leadTimeDistribution() {
                return this.analysis?.distributions?.lead_time || { status: 'evidence_missing', buckets: [] };
            },
            roomTypes() {
                return this.analysis?.room_types || { status: 'evidence_missing', rows: [] };
            },
            uploadPreview() {
                const preview = this.ctx?.ctripChannelOrderUploadPreview;
                return preview && typeof preview === 'object' ? preview : null;
            },
            uploadChannels() {
                return safeRows(this.ctx?.ctripChannelOrderUploadChannels);
            },
            importContract() {
                return String(this.analysis?.batch?.import_contract || '');
            },
            isLegacyAggregate() {
                return this.importContract === 'ctrip_order_aggregate_v1';
            },
            contractLabel() {
                return this.isLegacyAggregate ? '旧聚合 v1' : (this.importContract === 'ctrip_order_aggregate_v2' ? '原始订单 v2' : '契约待确认');
            },
            contractClass() {
                return this.isLegacyAggregate
                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                    : 'border-blue-200 bg-blue-50 text-blue-700';
            },
            metricCards() {
                return [
                    { key: 'gross', label: '总订单（含取消）', value: numberText(this.summary.gross_orders), note: this.isLegacyAggregate ? '旧聚合保存口径；缺逐单去重回执' : '本批次按订单号去重' },
                    { key: 'active', label: '有效订单', value: numberText(this.summary.active_orders), note: this.isLegacyAggregate ? '旧聚合保存的有效口径' : '按原始订单状态分类' },
                    { key: 'stayed', label: '已入住订单', value: numberText(this.summary.stayed_orders), note: this.summary.stayed_orders === null || this.summary.stayed_orders === undefined ? '旧聚合缺逐状态证据' : '订单状态=已入住' },
                    { key: 'cancelled', label: '取消订单 / 取消率', value: `${numberText(this.summary.cancelled_orders)} / ${percentText(this.summary.cancel_rate)}`, note: '取消订单 ÷ 含取消总单' },
                    { key: 'nights', label: '有效间夜', value: numberText(this.summary.room_nights, 1), note: '有效订单房间数×晚数' },
                    { key: 'bottom', label: '参考底价（非确认收入）', value: moneyText(this.summary.reference_bottom_price_total), note: `参考ADR ${moneyText(this.summary.reference_bottom_price_adr)}` },
                    { key: 'los', label: '平均连住', value: numeric(this.summary.average_los) === null ? '不可计算' : `${numberText(this.summary.average_los, 2)} 晚`, note: this.isLegacyAggregate ? `旧聚合均值；单晚占比 ${percentText(this.summary.single_night_rate)}` : `单晚占比 ${percentText(this.summary.single_night_rate)}` },
                    { key: 'lead', label: '平均提前预订', value: numeric(this.summary.average_booking_lead_days) === null ? '不可计算' : `${numberText(this.summary.average_booking_lead_days, 1)} 天`, note: this.isLegacyAggregate ? '旧聚合均值；不可反推分布' : '入住日－预订日；负值不归零' },
                ];
            },
            dateRangeLabel() {
                const range = this.analysis?.date_range || {};
                const from = range.from || range.date_from || this.dateFrom || '未取得';
                const to = range.to || range.date_to || this.dateTo || '未取得';
                return `${from} 至 ${to}`;
            },
        },
        watch: {
            systemHotelId: {
                immediate: true,
                handler() {
                    this.dateFrom = '';
                    this.dateTo = '';
                    this.loadAnalysis();
                },
            },
            uploadReceiptKey(next, previous) {
                if (next && next !== previous && next !== '::') {
                    this.dateFrom = '';
                    this.dateTo = '';
                    this.loadAnalysis();
                }
            },
        },
        methods: {
            numberText,
            moneyText,
            percentText,
            safeRows,
            resetRange() {
                this.dateFrom = '';
                this.dateTo = '';
                this.loadAnalysis();
            },
            openEvidenceUpload() {
                const open = this.ctx?.openCtripChannelOrderEvidenceUpload;
                if (typeof open === 'function') open();
            },
            async loadAnalysis() {
                const hotelId = this.systemHotelId;
                const sequence = ++this.requestSequence;
                this.error = '';
                if (!hotelId) {
                    this.analysis = null;
                    this.loading = false;
                    return;
                }
                if ((this.dateFrom && !this.dateTo) || (!this.dateFrom && this.dateTo)) {
                    this.error = '开始日期和结束日期需要同时填写。';
                    return;
                }
                if (this.dateFrom && this.dateTo && this.dateFrom > this.dateTo) {
                    this.error = '开始日期不能晚于结束日期。';
                    return;
                }

                this.loading = true;
                try {
                    const params = new URLSearchParams({ system_hotel_id: String(hotelId) });
                    if (this.dateFrom && this.dateTo) {
                        params.set('date_from', this.dateFrom);
                        params.set('date_to', this.dateTo);
                    }
                    let authToken = String(this.ctx?.token || '').trim();
                    if (!authToken) {
                        try {
                            authToken = sessionStorage.getItem('token') || '';
                        } catch (error) {
                            authToken = '';
                        }
                    }
                    const response = await fetch(`/api/online-data/ctrip/order-analysis?${params.toString()}`, {
                        headers: authToken ? { Authorization: `Bearer ${authToken}` } : {},
                        cache: 'no-store',
                    });
                    const payload = await response.json().catch(() => null);
                    if (!response.ok || !payload || Number(payload.code) !== 200) {
                        throw new Error(payload?.message || `订单分析读取失败（HTTP ${response.status}）`);
                    }
                    if (sequence !== this.requestSequence) return;
                    this.analysis = payload.data || {};
                    const range = this.analysis?.date_range || {};
                    if (!this.dateFrom && !this.dateTo) {
                        this.dateFrom = String(range.from || range.date_from || '');
                        this.dateTo = String(range.to || range.date_to || '');
                    }
                } catch (error) {
                    if (sequence !== this.requestSequence) return;
                    this.analysis = null;
                    this.error = error?.message || '订单分析读取失败。';
                } finally {
                    if (sequence === this.requestSequence) this.loading = false;
                }
            },
        },
        render() {
            const h = Vue.h;
            const badge = (text, classes) => h('span', {
                class: `rounded-full border px-2 py-0.5 text-xs font-semibold ${classes}`,
            }, text);
            const metricCard = (card) => h('div', {
                key: card.key,
                class: 'min-w-0 rounded-lg border border-slate-100 bg-slate-50 p-3',
            }, [
                h('div', { class: 'text-[11px] text-slate-500' }, card.label),
                h('div', { class: 'mt-1 truncate text-base font-semibold text-slate-900', title: card.value }, card.value),
                h('div', { class: 'mt-1 text-[10px] leading-4 text-slate-400' }, card.note),
            ]);
            const actionButton = (label, onClick, secondary = false) => h('button', {
                type: 'button',
                disabled: this.loading || !this.systemHotelId,
                onClick,
                class: secondary
                    ? 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-50'
                    : 'rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50',
            }, label);
            const dateInput = (label, value, update) => h('label', { class: 'text-xs text-slate-500' }, [
                label,
                h('input', {
                    type: 'date',
                    value,
                    onInput: (event) => update(event.target.value),
                    class: 'mt-1 block rounded-lg border border-slate-200 px-2 py-1.5 text-sm text-slate-700',
                }),
            ]);
            const header = h('header', { class: 'border-b border-slate-100 px-4 py-4 lg:px-5' }, [
                h('div', { class: 'flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between' }, [
                    h('div', {}, [
                        h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                            h('h4', { class: 'text-lg font-semibold text-slate-900' }, '订单深度分析'),
                            badge(this.statusLabel, this.statusClass),
                            this.analysis?.persistence_readback_status === 'verified'
                                ? badge('保存值级回读已确认', 'border-emerald-200 bg-emerald-50 text-emerald-700')
                                : null,
                            this.analysis ? badge(this.contractLabel, this.contractClass) : null,
                        ]),
                        h('p', { class: 'mt-1 text-xs leading-5 text-slate-500' }, `${this.analysis?.hotel?.name || this.ctx?.platformHotelSelectedName || '当前酒店'} · 携程系 OTA 渠道 · ${this.dateRangeLabel}`),
                        h('p', { class: 'text-xs leading-5 text-amber-700' }, '人工文件来源仍为待核验；参考底价不是确认收入，也不扩大为全酒店经营口径。'),
                        h('p', { class: 'text-xs leading-5 text-blue-700' }, '已保存订单分析与实时 Cookie 采集相互独立；顶部授权告警只影响实时抓取。'),
                    ]),
                    h('div', { class: 'flex flex-wrap items-end gap-2' }, [
                        dateInput('开始日期', this.dateFrom, (value) => { this.dateFrom = value; }),
                        dateInput('结束日期', this.dateTo, (value) => { this.dateTo = value; }),
                        actionButton(this.loading ? '读取中' : '查询', () => this.loadAnalysis()),
                        actionButton('全部已存范围', () => this.resetRange(), true),
                    ]),
                ]),
            ]);

            let body;
            if (!this.systemHotelId) {
                body = h('div', { class: 'p-5 text-sm text-slate-500' }, '请先在页面顶部选择酒店。');
            } else if (this.error) {
                body = h('div', { class: 'm-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700' }, this.error);
            } else if (this.loading && !this.analysis) {
                body = h('div', { class: 'p-5 text-sm text-slate-500' }, '正在按酒店、来源与日期范围读取已保存订单…');
            } else if (!this.analysis || this.status === 'no_data') {
                body = h('div', { class: 'p-5' }, [h('div', {
                    class: 'rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600',
                }, [
                    h('p', {}, '当前酒店没有可用的携程订单聚合。请上传同一门店的原始携程 XLS；系统会去重、保存并精确回读。'),
                    h('button', {
                        type: 'button',
                        'data-testid': 'ctrip-order-analysis-open-upload',
                        onClick: () => this.openEvidenceUpload(),
                        class: 'mt-3 rounded-lg bg-[#65502f] px-4 py-2 text-xs font-semibold text-white',
                    }, '上传原始 XLS'),
                ])]);
            } else {
                const sections = [];
                if (this.uploadPreview) {
                    const uploadMetric = (label, value) => h('div', { class: 'rounded bg-white p-2' }, [
                        h('div', { class: 'text-[11px] text-emerald-700' }, label), h('b', {}, value),
                    ]);
                    sections.push(h('div', {
                        'data-testid': 'ctrip-channel-order-upload-preview',
                        class: 'space-y-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3',
                    }, [
                        h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                            h('b', { class: 'text-sm text-emerald-900' }, '本次上传分析'),
                            h('span', { class: 'text-xs text-emerald-700' }, `${this.uploadPreview.date_from || '未取得'} 至 ${this.uploadPreview.date_to || '未取得'}`),
                        ]),
                        h('div', { class: 'grid grid-cols-2 gap-2 md:grid-cols-4' }, [
                            uploadMetric('有效订单', numberText(this.ctx?.ctripChannelOrderUploadTotalOrders)),
                            uploadMetric('含取消总单', numberText(this.ctx?.ctripChannelOrderUploadGrossOrders)),
                            uploadMetric('取消订单', numberText(this.ctx?.ctripChannelOrderUploadCancelledOrders)),
                            uploadMetric('取消率', numeric(this.ctx?.ctripChannelOrderUploadCancelRate) === null ? '不可计算' : `${Number(this.ctx.ctripChannelOrderUploadCancelRate).toFixed(1)}%`),
                        ]),
                        h('div', { class: 'overflow-x-auto rounded border border-emerald-100 bg-white' }, [h('table', { class: 'min-w-full text-xs' }, [
                            h('thead', { class: 'bg-emerald-50 text-emerald-800' }, [h('tr', {}, ['渠道', '有效订单', '含取消总单', '取消率', '间夜'].map((label, index) => h('th', { class: `px-2 py-2 ${index ? 'text-right' : 'text-left'}` }, label)))]),
                            h('tbody', { class: 'divide-y divide-emerald-50' }, this.uploadChannels.map((row) => h('tr', { key: row.key }, [
                                h('td', { class: 'px-2 py-2 font-medium text-slate-700' }, row.label),
                                h('td', { class: 'px-2 py-2 text-right' }, numberText(row.orders)),
                                h('td', { class: 'px-2 py-2 text-right' }, numberText(row.gross_orders)),
                                h('td', { class: 'px-2 py-2 text-right' }, percentText(row.cancel_rate)),
                                h('td', { class: 'px-2 py-2 text-right' }, numberText(row.room_nights, 1)),
                            ]))),
                        ])]),
                        h('p', { class: 'text-xs leading-5 text-emerald-800' }, this.ctx?.ctripChannelOrderPortraitInsight || ''),
                    ]));
                }
                sections.push(h('div', { class: 'grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8' }, this.metricCards.map(metricCard)));
                sections.push(h('div', { class: 'overflow-x-auto rounded-lg border border-slate-200' }, [
                    h('table', { class: 'min-w-full text-sm', 'data-testid': 'ctrip-order-analysis-channels' }, [
                        h('thead', { class: 'bg-slate-50 text-xs text-slate-500' }, [h('tr', {}, ['渠道', '含取消总单', '有效订单', '取消单', '取消率', '间夜', '参考底价', '参考ADR'].map((label, index) => h('th', { class: `px-3 py-2 ${index ? 'text-right' : 'text-left'}` }, label)))]),
                        h('tbody', { class: 'divide-y divide-slate-100' }, this.channels.map((row) => h('tr', { key: row.key || row.channel_key || row.label }, [
                            h('td', { class: 'px-3 py-2 font-medium text-slate-800' }, row.label || row.channel_label || row.key || row.channel_key),
                            h('td', { class: 'px-3 py-2 text-right' }, numberText(row.gross_orders)),
                            h('td', { class: 'px-3 py-2 text-right' }, numberText(row.active_orders)),
                            h('td', { class: 'px-3 py-2 text-right' }, numberText(row.cancelled_orders)),
                            h('td', { class: 'px-3 py-2 text-right' }, percentText(row.cancel_rate)),
                            h('td', { class: 'px-3 py-2 text-right' }, numberText(row.room_nights, 1)),
                            h('td', { class: 'px-3 py-2 text-right' }, moneyText(row.reference_bottom_price_total)),
                            h('td', { class: 'px-3 py-2 text-right' }, moneyText(row.reference_bottom_price_adr)),
                        ]))),
                    ]),
                ]));
                const distributionCard = (title, distribution, missingText) => h('div', { class: 'rounded-lg border border-slate-200 p-4' }, [
                    h('h5', { class: 'font-semibold text-slate-800' }, title),
                    distribution.status === 'available'
                        ? h('div', { class: 'mt-3 space-y-2' }, safeRows(distribution.buckets).map((bucket) => h('div', { key: bucket.key, class: 'flex items-center justify-between text-sm' }, [h('span', { class: 'text-slate-500' }, bucket.label), h('b', {}, numberText(bucket.orders))])))
                        : h('p', { class: 'mt-3 text-sm leading-6 text-amber-700' }, missingText),
                ]);
                sections.push(h('div', { class: 'grid grid-cols-1 gap-4 xl:grid-cols-3' }, [
                    distributionCard('连住分布', this.losDistribution, '旧聚合没有精确分布，不能从平均值反推；需重新上传原始 XLS。'),
                    distributionCard('提前预订分布', this.leadTimeDistribution, '缺原始预订日明细，现有均值不能恢复分布；需重新上传原始 XLS。'),
                    h('div', { class: 'rounded-lg border border-slate-200 p-4' }, [
                        h('h5', { class: 'font-semibold text-slate-800' }, '房型偏好'),
                        this.roomTypes.status === 'available'
                            ? h('div', { class: 'mt-3 space-y-2' }, safeRows(this.roomTypes.rows).slice(0, 10).map((row) => h('div', { key: row.name, class: 'flex items-start justify-between gap-3 text-sm' }, [h('span', { class: 'min-w-0 truncate text-slate-500', title: row.name }, row.name), h('b', { class: 'shrink-0' }, `${numberText(row.active_orders || row.orders)} 单`)])))
                            : h('p', { class: 'mt-3 text-sm leading-6 text-amber-700' }, '旧聚合仅保留每日 Top5，不能恢复完整房型排名；需重新上传原始 XLS。'),
                    ]),
                ]));
                sections.push(h('div', { class: 'grid grid-cols-1 gap-4 xl:grid-cols-2' }, [
                    h('div', { class: 'rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm' }, [
                        h('h5', { class: 'font-semibold text-slate-800' }, '订单状态分类回执'),
                        this.analysis.classification?.status === 'available'
                            ? h('div', { class: 'mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600' }, [
                                `已入住：${numberText(this.analysis.classification.stayed_orders)}`,
                                `有效未入住：${numberText(this.analysis.classification.active_not_stayed_orders)}`,
                                `取消：${numberText(this.analysis.classification.cancelled_orders)}`,
                                `未知状态：${numberText(this.analysis.classification.unknown_status_orders)}`,
                            ].map((text) => h('div', {}, text)))
                            : h('p', { class: 'mt-2 leading-6 text-amber-700' }, '现存 v1 聚合没有逐状态回执，已入住订单不可独立核验。'),
                    ]),
                    h('div', { class: 'rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm' }, [
                        h('h5', { class: 'font-semibold text-slate-800' }, '排除规则回执'),
                        this.analysis.exclusions?.status === 'available'
                            ? h('p', { class: 'mt-2 text-slate-600' }, `已排除 ${numberText(this.analysis.exclusions.excluded_order_count)} 单；规则 ${this.analysis.exclusions.policy_version || '未返回'}。`)
                            : h('p', { class: 'mt-2 leading-6 text-amber-700' }, '扫码单/关房记录的精确字段值未取得，因此未猜测排除；请重新上传原始 5 份 XLS 后核验。'),
                    ]),
                ]));
                if (this.missingDimensions.length) {
                    sections.push(h('div', { class: 'rounded-lg border border-amber-200 bg-amber-50 p-4' }, [
                        h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                            h('h5', { class: 'text-sm font-semibold text-amber-900' }, '仍缺原始证据的板块'),
                            h('button', {
                                type: 'button',
                                'data-testid': 'ctrip-order-analysis-open-upload',
                                onClick: () => this.openEvidenceUpload(),
                                class: 'rounded-lg bg-[#65502f] px-3 py-2 text-xs font-semibold text-white',
                            }, '上传原始 XLS 补全'),
                        ]),
                        h('p', { class: 'mt-2 text-xs leading-5 text-amber-800' }, '分析报告 HTML 可作材料对照，但不能替代逐笔订单、去重、状态和排除规则回执。'),
                        h('ul', { class: 'mt-2 space-y-1 text-xs leading-5 text-amber-800' }, this.missingDimensions.map((item) => h('li', { key: item.key }, `${item.label || item.key}：${item.reason || '原始证据缺失'}${item.next_action ? `；${item.next_action}` : ''}`))),
                    ]));
                }
                body = h('div', { class: 'space-y-5 p-4 lg:p-5' }, sections);
            }

            return h('section', {
                'data-testid': 'ctrip-order-analysis-panel',
                class: 'rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden',
            }, [header, body]);
        },
        template: `
            <section data-testid="ctrip-order-analysis-panel" class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <header class="border-b border-slate-100 px-4 py-4 lg:px-5">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h4 class="text-lg font-semibold text-slate-900">订单深度分析</h4>
                                <span :class="['rounded-full border px-2 py-0.5 text-xs font-semibold', statusClass]">{{ statusLabel }}</span>
                                <span v-if="analysis?.persistence_readback_status === 'verified'" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">保存值级回读已确认</span>
                                <span v-if="analysis" :class="['rounded-full border px-2 py-0.5 text-xs font-semibold', contractClass]">{{ contractLabel }}</span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ analysis?.hotel?.name || ctx?.platformHotelSelectedName || '当前酒店' }} · 携程系 OTA 渠道 · {{ dateRangeLabel }}</p>
                            <p class="text-xs leading-5 text-amber-700">人工文件来源仍为待核验；参考底价不是确认收入，也不扩大为全酒店经营口径。</p>
                            <p class="text-xs leading-5 text-blue-700">已保存订单分析与实时 Cookie 采集相互独立；顶部授权告警只影响实时抓取。</p>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="text-xs text-slate-500">开始日期<input v-model="dateFrom" type="date" class="mt-1 block rounded-lg border border-slate-200 px-2 py-1.5 text-sm text-slate-700"></label>
                            <label class="text-xs text-slate-500">结束日期<input v-model="dateTo" type="date" class="mt-1 block rounded-lg border border-slate-200 px-2 py-1.5 text-sm text-slate-700"></label>
                            <button type="button" @click="loadAnalysis" :disabled="loading || !systemHotelId" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{{ loading ? '读取中' : '查询' }}</button>
                            <button type="button" @click="resetRange" :disabled="loading || !systemHotelId" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-50">全部已存范围</button>
                        </div>
                    </div>
                </header>

                <div v-if="!systemHotelId" class="p-5 text-sm text-slate-500">请先在页面顶部选择酒店。</div>
                <div v-else-if="error" class="m-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</div>
                <div v-else-if="loading && !analysis" class="p-5 text-sm text-slate-500">正在按酒店、来源与日期范围读取已保存订单…</div>
                <div v-else-if="!analysis || status === 'no_data'" class="p-5">
                    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600"><p>当前酒店没有可用的携程订单聚合。请上传同一门店的原始携程 XLS；系统会去重、保存并精确回读。</p><button type="button" data-testid="ctrip-order-analysis-open-upload" @click="openEvidenceUpload" class="mt-3 rounded-lg bg-[#65502f] px-4 py-2 text-xs font-semibold text-white">上传原始 XLS</button></div>
                </div>
                <div v-else class="space-y-5 p-4 lg:p-5">
                    <div v-if="uploadPreview" data-testid="ctrip-channel-order-upload-preview" class="space-y-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2"><b class="text-sm text-emerald-900">本次上传分析</b><span class="text-xs text-emerald-700">{{ uploadPreview.date_from || '未取得' }} 至 {{ uploadPreview.date_to || '未取得' }}</span></div>
                        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                            <div class="rounded bg-white p-2"><div class="text-[11px] text-emerald-700">有效订单</div><b>{{ numberText(ctx?.ctripChannelOrderUploadTotalOrders) }}</b></div>
                            <div class="rounded bg-white p-2"><div class="text-[11px] text-emerald-700">含取消总单</div><b>{{ numberText(ctx?.ctripChannelOrderUploadGrossOrders) }}</b></div>
                            <div class="rounded bg-white p-2"><div class="text-[11px] text-emerald-700">取消订单</div><b>{{ numberText(ctx?.ctripChannelOrderUploadCancelledOrders) }}</b></div>
                            <div class="rounded bg-white p-2"><div class="text-[11px] text-emerald-700">取消率</div><b>{{ numeric(ctx?.ctripChannelOrderUploadCancelRate) === null ? '不可计算' : Number(ctx.ctripChannelOrderUploadCancelRate).toFixed(1) + '%' }}</b></div>
                        </div>
                        <div class="overflow-x-auto rounded border border-emerald-100 bg-white"><table class="min-w-full text-xs"><thead class="bg-emerald-50 text-emerald-800"><tr><th class="px-2 py-2 text-left">渠道</th><th class="px-2 py-2 text-right">有效订单</th><th class="px-2 py-2 text-right">含取消总单</th><th class="px-2 py-2 text-right">取消率</th><th class="px-2 py-2 text-right">间夜</th></tr></thead><tbody class="divide-y divide-emerald-50"><tr v-for="row in uploadChannels" :key="row.key"><td class="px-2 py-2 font-medium text-slate-700">{{ row.label }}</td><td class="px-2 py-2 text-right">{{ numberText(row.orders) }}</td><td class="px-2 py-2 text-right">{{ numberText(row.gross_orders) }}</td><td class="px-2 py-2 text-right">{{ percentText(row.cancel_rate) }}</td><td class="px-2 py-2 text-right">{{ numberText(row.room_nights, 1) }}</td></tr></tbody></table></div>
                        <p class="text-xs leading-5 text-emerald-800">{{ ctx?.ctripChannelOrderPortraitInsight }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
                        <div v-for="card in metricCards" :key="card.key" class="min-w-0 rounded-lg border border-slate-100 bg-slate-50 p-3">
                            <div class="text-[11px] text-slate-500">{{ card.label }}</div>
                            <div class="mt-1 truncate text-base font-semibold text-slate-900" :title="card.value">{{ card.value }}</div>
                            <div class="mt-1 text-[10px] leading-4 text-slate-400">{{ card.note }}</div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="min-w-full text-sm" data-testid="ctrip-order-analysis-channels">
                            <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-3 py-2 text-left">渠道</th><th class="px-3 py-2 text-right">含取消总单</th><th class="px-3 py-2 text-right">有效订单</th><th class="px-3 py-2 text-right">取消单</th><th class="px-3 py-2 text-right">取消率</th><th class="px-3 py-2 text-right">间夜</th><th class="px-3 py-2 text-right">参考底价</th><th class="px-3 py-2 text-right">参考ADR</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in channels" :key="row.key || row.channel_key || row.label">
                                    <td class="px-3 py-2 font-medium text-slate-800">{{ row.label || row.channel_label || row.key || row.channel_key }}</td>
                                    <td class="px-3 py-2 text-right">{{ numberText(row.gross_orders) }}</td><td class="px-3 py-2 text-right">{{ numberText(row.active_orders) }}</td><td class="px-3 py-2 text-right">{{ numberText(row.cancelled_orders) }}</td><td class="px-3 py-2 text-right">{{ percentText(row.cancel_rate) }}</td><td class="px-3 py-2 text-right">{{ numberText(row.room_nights, 1) }}</td><td class="px-3 py-2 text-right">{{ moneyText(row.reference_bottom_price_total) }}</td><td class="px-3 py-2 text-right">{{ moneyText(row.reference_bottom_price_adr) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                        <div class="rounded-lg border border-slate-200 p-4">
                            <h5 class="font-semibold text-slate-800">连住分布</h5>
                            <div v-if="losDistribution.status === 'available'" class="mt-3 space-y-2"><div v-for="bucket in safeRows(losDistribution.buckets)" :key="bucket.key" class="flex items-center justify-between text-sm"><span class="text-slate-500">{{ bucket.label }}</span><b>{{ numberText(bucket.orders) }}</b></div></div>
                            <p v-else class="mt-3 text-sm leading-6 text-amber-700">旧聚合没有精确分布，不能从平均值反推；需重新上传原始 XLS。</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-4">
                            <h5 class="font-semibold text-slate-800">提前预订分布</h5>
                            <div v-if="leadTimeDistribution.status === 'available'" class="mt-3 space-y-2"><div v-for="bucket in safeRows(leadTimeDistribution.buckets)" :key="bucket.key" class="flex items-center justify-between text-sm"><span class="text-slate-500">{{ bucket.label }}</span><b>{{ numberText(bucket.orders) }}</b></div></div>
                            <p v-else class="mt-3 text-sm leading-6 text-amber-700">缺原始预订日明细，现有均值不能恢复分布；需重新上传原始 XLS。</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 p-4">
                            <h5 class="font-semibold text-slate-800">房型偏好</h5>
                            <div v-if="roomTypes.status === 'available'" class="mt-3 space-y-2"><div v-for="row in safeRows(roomTypes.rows).slice(0, 10)" :key="row.name" class="flex items-start justify-between gap-3 text-sm"><span class="min-w-0 truncate text-slate-500" :title="row.name">{{ row.name }}</span><b class="shrink-0">{{ numberText(row.active_orders || row.orders) }} 单</b></div></div>
                            <p v-else class="mt-3 text-sm leading-6 text-amber-700">旧聚合仅保留每日 Top5，不能恢复完整房型排名；需重新上传原始 XLS。</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                            <h5 class="font-semibold text-slate-800">订单状态分类回执</h5>
                            <div v-if="analysis.classification?.status === 'available'" class="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-600"><div>已入住：{{ numberText(analysis.classification.stayed_orders) }}</div><div>有效未入住：{{ numberText(analysis.classification.active_not_stayed_orders) }}</div><div>取消：{{ numberText(analysis.classification.cancelled_orders) }}</div><div>未知状态：{{ numberText(analysis.classification.unknown_status_orders) }}</div></div>
                            <p v-else class="mt-2 leading-6 text-amber-700">现存 v1 聚合没有逐状态回执，已入住订单不可独立核验。</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                            <h5 class="font-semibold text-slate-800">排除规则回执</h5>
                            <p v-if="analysis.exclusions?.status === 'available'" class="mt-2 text-slate-600">已排除 {{ numberText(analysis.exclusions.excluded_order_count) }} 单；规则 {{ analysis.exclusions.policy_version || '未返回' }}。</p>
                            <p v-else class="mt-2 leading-6 text-amber-700">扫码单/关房记录的精确字段值未取得，因此未猜测排除；请重新上传原始 5 份 XLS 后核验。</p>
                        </div>
                    </div>

                    <div v-if="missingDimensions.length" class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h5 class="text-sm font-semibold text-amber-900">仍缺原始证据的板块</h5><button type="button" data-testid="ctrip-order-analysis-open-upload" @click="openEvidenceUpload" class="rounded-lg bg-[#65502f] px-3 py-2 text-xs font-semibold text-white">上传原始 XLS 补全</button></div>
                        <p class="mt-2 text-xs leading-5 text-amber-800">分析报告 HTML 可作材料对照，但不能替代逐笔订单、去重、状态和排除规则回执。</p>
                        <ul class="mt-2 space-y-1 text-xs leading-5 text-amber-800"><li v-for="item in missingDimensions" :key="item.key">{{ item.label || item.key }}：{{ item.reason || '原始证据缺失' }}<span v-if="item.next_action">；{{ item.next_action }}</span></li></ul>
                    </div>
                </div>
            </section>
        `,
    };
})();
