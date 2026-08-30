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
    const shanghaiDateText = (date = new Date()) => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(date);
        const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return `${value.year}-${value.month}-${value.day}`;
    };
    const shiftIsoDateText = (dateText, offsetDays) => {
        const [year, month, day] = String(dateText || '').split('-').map(Number);
        const shifted = new Date(Date.UTC(year, month - 1, day + offsetDays));
        return shifted.toISOString().slice(0, 10);
    };
    const quickMetricKeys = ['orders', 'room_nights', 'revenue', 'adr', 'cancellation_rate'];
    const quickMetricMeta = {
        orders: { label: '订单数' },
        room_nights: { label: '间夜' },
        revenue: { label: 'OTA 房费收入' },
        adr: { label: 'ADR' },
        cancellation_rate: { label: '取消率' },
    };

    components.CtripOrderAnalysisPanelBody = {
        name: 'CtripOrderAnalysisPanelBody',
        props: {
            ctx: {
                type: Object,
                required: true,
            },
            detailMode: {
                type: String,
                default: 'ctrip',
            },
        },
        data() {
            return {
                quickAnalysis: null,
                quickLoading: false,
                quickError: '',
                quickStale: false,
                quickDateFrom: '',
                quickDateTo: '',
                quickRangePreset: '30d',
                quickRequestSequence: 0,
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
            showCtripDetail() {
                return this.detailMode !== 'summary';
            },
            quickStatus() {
                return String(this.quickAnalysis?.status || 'data_missing');
            },
            quickStatusLabel() {
                if (this.quickError && !this.quickAnalysis) return '读取失败';
                if (this.quickStale) return '上次结果';
                return ({
                    ready: '双平台已核验',
                    separate_ready: '分别可看',
                    partial: '部分可用',
                    data_missing: '待补订单数据',
                })[this.quickStatus] || '状态待确认';
            },
            quickStatusClass() {
                if (this.quickError && !this.quickAnalysis) return 'border-red-300 bg-red-700 text-white';
                if (this.quickStale) return 'border-yellow-300 bg-yellow-700 text-yellow-100';
                return ({
                    ready: 'border-green-300 bg-green-800 text-green-100',
                    separate_ready: 'border-yellow-300 bg-yellow-700 text-yellow-100',
                    partial: 'border-yellow-300 bg-yellow-700 text-yellow-100',
                    data_missing: 'border-gray-300 bg-gray-700 text-white',
                })[this.quickStatus] || 'border-gray-300 bg-gray-700 text-white';
            },
            quickContractVersion() {
                return String(this.quickAnalysis?.contract_version || '契约待回读');
            },
            quickPlatforms() {
                const platforms = this.quickAnalysis?.platforms || {};
                return [
                    { key: 'ctrip', label: '携程', data: platforms.ctrip || {} },
                    { key: 'meituan', label: '美团', data: platforms.meituan || {} },
                ];
            },
            quickComparison() {
                return this.quickAnalysis?.comparison && typeof this.quickAnalysis.comparison === 'object'
                    ? this.quickAnalysis.comparison
                    : { can_compare: false, reason: '两平台可比较证据未回读。' };
            },
            quickComparisonRows() {
                const metrics = this.quickComparison?.metrics;
                if (Array.isArray(metrics)) return metrics;
                if (!metrics || typeof metrics !== 'object') return [];
                return quickMetricKeys.map((key) => ({ key, ...(metrics[key] || {}) }));
            },
            quickComparableRows() {
                return this.quickComparisonRows.filter((row) => String(row?.status || '') === 'ready');
            },
            quickActions() {
                return safeRows(this.quickAnalysis?.actions);
            },
            quickRequiredActions() {
                const selected = new Map();
                for (const action of this.quickActions) {
                    const status = String(action?.status || '').toLowerCase();
                    if (action?.required !== true && status !== 'required') continue;
                    const key = String(action?.key || action?.action || '').toLowerCase();
                    const platform = String(action?.platform || '').toLowerCase();
                    const group = key.includes('order_flow') || key.includes('flow')
                        ? `${platform || 'unknown'}:order_flow`
                        : `${platform || 'unknown'}:order`;
                    if (!selected.has(group)) selected.set(group, action);
                }
                return [...selected.values()];
            },
            quickDateRangeLabel() {
                const range = this.quickAnalysis?.date_range || {};
                const from = range.from || range.date_from || this.quickDateFrom || '最早已存';
                const to = range.to || range.date_to || this.quickDateTo || '最新已存';
                return `${from} 至 ${to}`;
            },
            meituanRefreshKey() {
                const order = this.ctx?.meituanOrderResult || {};
                const capture = this.ctx?.meituanBrowserCaptureResult || {};
                const flow = this.ctx?.meituanOrderFlowView || {};
                const flowSummary = (direction, key) => flow?.[direction]?.summary?.[key] ?? '';
                return [
                    this.ctx?.meituanDataFetchTime || '',
                    this.ctx?.meituanFetchSuccess ? '1' : '0',
                    order.task_id || order.updated_at || order.saved_count || order.import_row_count || order.readback_verified || order.ui_flow_status || order.status || order.error || '',
                    capture.task_id || capture.updated_at || capture.saved_count || capture.readback_verified || capture.ui_flow_status || capture.status || capture.error || '',
                    flow.status || '',
                    flow.period || '',
                    flow.periodStart || '',
                    flow.periodEnd || '',
                    flow.capturedAt || '',
                    flowSummary('loss', 'orderCount'),
                    flowSummary('loss', 'roomNights'),
                    flowSummary('loss', 'amount'),
                    flowSummary('inflow', 'orderCount'),
                    flowSummary('inflow', 'roomNights'),
                    flowSummary('inflow', 'amount'),
                ].join(':');
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
                    this.quickAnalysis = null;
                    this.quickStale = false;
                    this.setQuickRangePreset('30d', false);
                    this.loadQuickAnalysis();
                    this.analysis = null;
                    this.dateFrom = '';
                    this.dateTo = '';
                    if (this.showCtripDetail) this.loadAnalysis();
                },
            },
            uploadReceiptKey(next, previous) {
                if (next && next !== previous && next !== '::') {
                    this.loadQuickAnalysis();
                    this.dateFrom = '';
                    this.dateTo = '';
                    if (this.showCtripDetail) this.loadAnalysis();
                }
            },
            meituanRefreshKey(next, previous) {
                if (previous !== undefined && next !== previous) this.loadQuickAnalysis();
            },
        },
        methods: {
            numberText,
            moneyText,
            percentText,
            safeRows,
            quickMetric(platform, key) {
                const metric = platform?.metrics?.[key];
                const direct = metric !== undefined ? metric : platform?.[key];
                const objectMetric = direct && typeof direct === 'object' && !Array.isArray(direct)
                    ? direct
                    : { value: direct };
                const rawStatus = String(objectMetric.status || platform?.metric_statuses?.[key] || platform?.status || 'missing');
                const value = objectMetric.value ?? objectMetric.metric_value ?? null;
                let status = rawStatus;
                if (['available', 'available_partial', 'partial', 'unverified'].includes(status)) status = 'available_unverified';
                if (['no_data', 'data_missing', 'evidence_missing', 'unavailable', 'not_available'].includes(status)) status = 'missing';
                if (!['verified', 'available_unverified', 'missing'].includes(status)) {
                    status = value === null || value === undefined || value === '' ? 'missing' : 'available_unverified';
                }
                if ((value === null || value === undefined || value === '') && status !== 'missing') status = 'missing';
                return {
                    key,
                    label: objectMetric.label || quickMetricMeta[key]?.label || key,
                    value,
                    status,
                    reason: String(objectMetric.reason || objectMetric.source_trust?.failure_reasons?.join?.('；') || ''),
                };
            },
            quickMetricStatusLabel(status) {
                return ({
                    verified: '已核验',
                    available_unverified: '可用·待核验',
                    missing: '缺失',
                })[status] || '缺失';
            },
            quickMetricStatusClass(status) {
                return ({
                    verified: 'border-green-200 bg-green-50 text-green-700',
                    available_unverified: 'border-yellow-200 bg-yellow-50 text-yellow-800',
                    missing: 'border-gray-200 bg-gray-50 text-gray-500',
                })[status] || 'border-gray-200 bg-gray-50 text-gray-500';
            },
            quickMetricText(key, value) {
                if (key === 'revenue' || key === 'adr') return moneyText(value);
                if (key === 'cancellation_rate') {
                    const number = numeric(value);
                    return number === null ? '不可计算' : `${number.toFixed(1)}%`;
                }
                return numberText(value, key === 'room_nights' ? 1 : 0);
            },
            comparisonMetricText(key, value) {
                const number = numeric(value);
                if (number === null) return '不可计算';
                const sign = number > 0 ? '+' : '';
                if (key === 'revenue' || key === 'adr') {
                    return `${sign}¥${number.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                }
                if (key === 'cancellation_rate') {
                    return `${sign}${number.toFixed(1)} 个百分点`;
                }
                return `${sign}${number.toLocaleString('zh-CN', { maximumFractionDigits: key === 'room_nights' ? 1 : 0 })}`;
            },
            comparisonLeaderText(leader) {
                return ({ ctrip: '携程更高', meituan: '美团更高', equal: '持平', same: '持平' })[leader] || '差值';
            },
            quickOrderFlowPeriodText(period) {
                return ({
                    yesterday: '昨天',
                    last_7_days: '近7天',
                    last_30_days: '近30天',
                })[String(period || '')] || '已保存期间';
            },
            setQuickRangePreset(preset, shouldLoad = true) {
                this.quickRangePreset = preset;
                if (preset === 'all') {
                    this.quickDateFrom = '';
                    this.quickDateTo = '';
                } else if (preset === '7d' || preset === '30d') {
                    const today = shanghaiDateText();
                    this.quickDateFrom = shiftIsoDateText(today, -(preset === '7d' ? 6 : 29));
                    this.quickDateTo = today;
                }
                if (shouldLoad && preset !== 'custom') this.loadQuickAnalysis();
            },
            quickNavigationError(message) {
                this.quickError = String(message || '订单补数入口暂不可用。');
                this.quickStale = false;
                const notify = this.ctx?.showToast;
                if (typeof notify === 'function') notify(this.quickError, 'error');
                return false;
            },
            focusQuickTarget(platform, tab) {
                if (typeof document === 'undefined') return;
                const selector = platform === 'meituan'
                    ? (tab === 'meituan-order-flow'
                        ? '[data-testid="meituan-order-flow-page"]'
                        : '[data-testid="meituan-orders-page"]')
                    : '[data-testid="dual-ota-order-quick-analysis-panel"]';
                const target = document.querySelector(selector);
                target?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
                target?.focus?.({ preventScroll: true });
            },
            async openPlatformPage(platform, tab = '', options = {}) {
                const targetPlatform = platform === 'meituan' ? 'meituan' : 'ctrip';
                const hotelId = String(this.systemHotelId || '');
                const targetLabel = targetPlatform === 'meituan' ? '美团' : '携程';
                const optionProvider = this.ctx?.platformHotelOptionsFor;
                const targetOptions = typeof optionProvider === 'function'
                    ? safeRows(optionProvider(targetPlatform))
                    : [];
                const targetHotel = targetOptions.find((hotel) => String(hotel?.id || '') === hotelId);
                if (!targetHotel) {
                    return this.quickNavigationError(`当前酒店尚未进入${targetLabel}可选范围，不能跳转补数；请先完成该平台酒店绑定。`);
                }

                this.quickError = '';
                this.ctx.currentPage = targetPlatform === 'meituan' ? 'meituan-ebooking' : 'ctrip-ebooking';
                await Vue.nextTick();
                if (String(this.ctx?.platformHotelSelectedId || '') !== hotelId) {
                    const selectHotel = this.ctx?.selectPlatformHotelOption;
                    if (typeof selectHotel !== 'function') {
                        return this.quickNavigationError(`${targetLabel}酒店切换入口不可用，已停止跳转以避免串酒店。`);
                    }
                    selectHotel(targetHotel);
                    await Vue.nextTick();
                }

                const open = targetPlatform === 'meituan'
                    ? this.ctx?.openMeituanManualTab
                    : this.ctx?.openCtripManualTab;
                if (typeof open !== 'function') {
                    return this.quickNavigationError(`${targetLabel}订单入口暂不可用。`);
                }
                const targetTab = tab || (targetPlatform === 'meituan' ? 'meituan-orders' : 'data-health');
                await Promise.resolve(open(targetTab));
                await Vue.nextTick();
                if (options.focus !== false) this.focusQuickTarget(targetPlatform, targetTab);
                return true;
            },
            async openCtripUpload() {
                const opened = await this.openPlatformPage('ctrip', 'data-health', { focus: false });
                if (!opened) return false;
                await Vue.nextTick();
                this.openEvidenceUpload();
                return true;
            },
            async runQuickAction(action = {}) {
                const key = String(action.key || action.action || '').toLowerCase();
                const platform = String(action.platform || (String(action.page || '').includes('meituan') ? 'meituan' : 'ctrip'));
                if (platform === 'ctrip' && /(upload|import|evidence|reimport)/.test(key)) {
                    return this.openCtripUpload();
                }
                const tab = String(action.tab || (key.includes('flow') ? 'meituan-order-flow' : (platform === 'meituan' ? 'meituan-orders' : 'data-health')));
                return this.openPlatformPage(platform, tab);
            },
            async loadQuickAnalysis() {
                const hotelId = this.systemHotelId;
                const sequence = ++this.quickRequestSequence;
                this.quickError = '';
                this.quickStale = false;
                if (!hotelId) {
                    this.quickAnalysis = null;
                    this.quickLoading = false;
                    return;
                }
                if ((this.quickDateFrom && !this.quickDateTo) || (!this.quickDateFrom && this.quickDateTo)) {
                    this.quickError = '开始日期和结束日期需要同时填写。';
                    return;
                }
                if (this.quickDateFrom && this.quickDateTo && this.quickDateFrom > this.quickDateTo) {
                    this.quickError = '开始日期不能晚于结束日期。';
                    return;
                }

                this.quickLoading = true;
                try {
                    const params = new URLSearchParams({ system_hotel_id: String(hotelId) });
                    if (this.quickDateFrom && this.quickDateTo) {
                        params.set('date_from', this.quickDateFrom);
                        params.set('date_to', this.quickDateTo);
                    }
                    let authToken = String(this.ctx?.token || '').trim();
                    if (!authToken) {
                        try {
                            authToken = sessionStorage.getItem('token') || '';
                        } catch (error) {
                            authToken = '';
                        }
                    }
                    const response = await fetch(`/api/online-data/dual-ota/order-analysis?${params.toString()}`, {
                        headers: authToken ? { Authorization: `Bearer ${authToken}` } : {},
                        cache: 'no-store',
                    });
                    const payload = await response.json().catch(() => null);
                    if (!response.ok || !payload || Number(payload.code) !== 200) {
                        throw new Error(payload?.message || `双平台订单快析读取失败（HTTP ${response.status}）`);
                    }
                    if (sequence !== this.quickRequestSequence) return;
                    this.quickAnalysis = payload.data || {};
                    this.quickStale = false;
                } catch (error) {
                    if (sequence !== this.quickRequestSequence) return;
                    this.quickError = error?.message || '双平台订单快析读取失败。';
                    this.quickStale = !!this.quickAnalysis;
                } finally {
                    if (sequence === this.quickRequestSequence) this.quickLoading = false;
                }
            },
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
            renderQuickAnalysis() {
                const h = Vue.h;
                const smallBadge = (text, classes) => h('span', {
                    class: `inline-flex flex-shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${classes}`,
                }, text);
                const toolbarButton = (label, preset) => h('button', {
                    type: 'button',
                    'data-testid': `dual-ota-order-range-${preset}`,
                    'aria-pressed': this.quickRangePreset === preset ? 'true' : 'false',
                    disabled: this.quickLoading || !this.systemHotelId,
                    onClick: () => this.setQuickRangePreset(preset),
                    class: this.quickRangePreset === preset
                        ? 'rounded-lg bg-green-800 px-3 py-2 text-xs font-semibold text-white shadow-sm disabled:opacity-50'
                        : 'rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50',
                }, label);
                const actionButton = (label, onClick, primary = false, testId = '') => h('button', {
                    type: 'button',
                    'data-testid': testId || undefined,
                    disabled: !this.systemHotelId,
                    onClick,
                    style: primary ? { background: 'linear-gradient(135deg, #a88a52, #6f572f)' } : undefined,
                    class: primary
                        ? 'rounded-lg px-3 py-2 text-xs font-semibold text-white shadow-sm disabled:opacity-50'
                        : 'rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50',
                }, label);
                const platformSection = ({ key, label, data }) => {
                    const metrics = quickMetricKeys.map((metricKey) => this.quickMetric(data, metricKey));
                    const platformStatus = String(data?.status || 'missing');
                    const platformStatusNormalized = platformStatus === 'verified'
                        ? 'verified'
                        : (platformStatus === 'missing' || platformStatus === 'data_missing' ? 'missing' : 'available_unverified');
                    const gaps = safeRows(data?.data_gaps).slice(0, 3);
                    const orderFlow = key === 'meituan' && data?.order_flow && typeof data.order_flow === 'object'
                        ? data.order_flow
                        : null;
                    const flowMetric = (direction, metricKey, formatter = numberText) => {
                        const value = orderFlow?.[direction]?.[metricKey];
                        return formatter(value);
                    };
                    return h('section', {
                        key,
                        'data-testid': `dual-ota-order-platform-${key}`,
                        class: 'min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white',
                    }, [
                        h('div', { class: 'flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3' }, [
                            h('div', { class: 'min-w-0' }, [
                                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                    h('h5', { class: 'text-base font-semibold text-gray-900' }, label),
                                    smallBadge(this.quickMetricStatusLabel(platformStatusNormalized), this.quickMetricStatusClass(platformStatusNormalized)),
                                ]),
                                h('p', { class: 'mt-0.5 truncate text-xs text-gray-500' }, data?.latest_data_date ? `最新订单日 ${data.latest_data_date}` : '尚未回读最新订单日'),
                            ]),
                            h('span', { class: 'flex-shrink-0 text-xs text-gray-400' }, String(data?.quality_label || 'OTA 渠道口径')),
                        ]),
                        h('div', { class: 'divide-y divide-gray-100' }, metrics.map((metric) => h('div', {
                            key: metric.key,
                            'data-testid': `dual-ota-order-metric-${key}-${metric.key}`,
                            class: 'grid grid-cols-1 items-center gap-x-3 gap-y-1 px-4 py-2.5 sm:grid-cols-3',
                        }, [
                            h('span', { class: 'min-w-0 text-xs font-medium text-gray-600' }, metric.label),
                            h('strong', { class: 'text-left text-sm tabular-nums text-gray-900 sm:text-right' }, this.quickMetricText(metric.key, metric.value)),
                            smallBadge(this.quickMetricStatusLabel(metric.status), this.quickMetricStatusClass(metric.status)),
                            metric.reason ? h('span', {
                                class: 'min-w-0 text-xs leading-4 text-gray-400 sm:col-span-3',
                                title: metric.reason,
                            }, metric.reason) : null,
                        ]))),
                        orderFlow ? h('div', {
                            'data-testid': 'dual-ota-order-flow-summary',
                            class: 'border-t border-gray-100 bg-gray-50 px-4 py-3',
                        }, [
                            h('div', { class: 'flex flex-wrap items-center justify-between gap-2' }, [
                                h('div', {}, [
                                    h('div', { class: 'text-xs font-semibold text-gray-800' }, '美团订单流向'),
                                    h('div', { class: 'mt-0.5 text-xs text-gray-500' }, orderFlow.period ? `${this.quickOrderFlowPeriodText(orderFlow.period)} · 与订单收入分别记账` : '与订单收入分别记账'),
                                ]),
                                smallBadge(
                                    orderFlow.status === 'verified' ? '已核验' : (orderFlow.status === 'available_unverified' ? '可用·待核验' : '缺失'),
                                    this.quickMetricStatusClass(orderFlow.status === 'verified' ? 'verified' : (orderFlow.status === 'available_unverified' ? 'available_unverified' : 'missing')),
                                ),
                            ]),
                            orderFlow.status === 'missing' ? h('p', { class: 'mt-2 text-xs leading-4 text-gray-500' }, orderFlow.reason || '当前范围没有已保存的流失/流入汇总。') : h('div', { class: 'mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2' }, [
                                h('div', { class: 'rounded-lg border border-red-100 bg-white px-3 py-2 text-xs' }, [
                                    h('div', { class: 'font-semibold text-red-700' }, '流失'),
                                    h('div', { class: 'mt-1 text-gray-600' }, `${flowMetric('loss', 'orders')} 单 · ${flowMetric('loss', 'room_nights')} 间夜`),
                                    h('div', { class: 'mt-0.5 font-semibold text-gray-900' }, moneyText(orderFlow?.loss?.amount)),
                                ]),
                                h('div', { class: 'rounded-lg border border-green-100 bg-white px-3 py-2 text-xs' }, [
                                    h('div', { class: 'font-semibold text-green-700' }, '流入'),
                                    h('div', { class: 'mt-1 text-gray-600' }, `${flowMetric('inflow', 'orders')} 单 · ${flowMetric('inflow', 'room_nights')} 间夜`),
                                    h('div', { class: 'mt-0.5 font-semibold text-gray-900' }, moneyText(orderFlow?.inflow?.amount)),
                                ]),
                            ]),
                        ]) : null,
                        gaps.length ? h('div', { class: 'border-t border-yellow-100 bg-yellow-50 px-4 py-2.5' }, [
                            h('p', { class: 'text-xs font-semibold text-yellow-900' }, '数据缺口'),
                            h('ul', { class: 'mt-1 space-y-0.5 text-xs leading-4 text-yellow-800' }, gaps.map((gap, index) => h('li', { key: gap?.key || index }, typeof gap === 'string' ? gap : (gap?.reason || gap?.label || gap?.key || '证据未齐')))),
                        ]) : null,
                    ]);
                };

                const comparison = this.quickComparison;
                const canCompare = comparison?.can_compare === true;
                const blockers = safeRows(comparison?.blockers);
                const comparisonReason = String(comparison?.reason || blockers.map((item) => typeof item === 'string' ? item : (item?.reason || item?.label || item?.key || '')).filter(Boolean).join('；') || '两平台的同店、同日期、同口径证据未同时核验。');
                const comparisonBlock = h('section', {
                    'data-testid': 'dual-ota-order-comparison',
                    class: canCompare
                        ? 'overflow-hidden rounded-xl border border-green-200 bg-green-50'
                        : 'rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3',
                }, canCompare ? [
                    h('div', { class: 'flex flex-col gap-1 border-b border-green-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between' }, [
                        h('div', {}, [h('h5', { class: 'text-sm font-semibold text-green-900' }, '同口径差值'), h('p', { class: 'mt-0.5 text-xs text-green-800' }, comparisonReason || '同店同期证据已核验，可以对比。')]),
                        smallBadge('允许比较', 'border-green-300 bg-white text-green-700'),
                    ]),
                    this.quickComparableRows.length
                        ? h('div', { class: 'grid grid-cols-1 divide-y divide-gray-100 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-5' }, this.quickComparableRows.map((row) => {
                            const key = String(row.key || row.metric_key || '');
                            return h('div', { key, class: 'min-w-0 px-4 py-3' }, [
                                h('div', { class: 'text-xs text-green-800' }, row.label || quickMetricMeta[key]?.label || key),
                                h('div', { class: 'mt-1 flex items-baseline justify-between gap-2' }, [
                                    h('strong', { class: 'text-sm tabular-nums text-green-900' }, this.comparisonMetricText(key, row.delta)),
                                    h('span', { class: 'text-xs text-green-700' }, this.comparisonLeaderText(row.leader)),
                                ]),
                                row.reason ? h('p', { class: 'mt-1 text-xs leading-4 text-green-700' }, row.reason) : null,
                            ]);
                        }))
                        : h('p', { class: 'px-4 py-3 text-xs text-green-800' }, '后端已确认可比，但未返回可展示的指标差值。'),
                ] : [
                    h('div', { class: 'flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between' }, [
                        h('div', {}, [
                            h('h5', { class: 'text-sm font-semibold text-yellow-900' }, '分别展示，不判高低'),
                            h('p', { class: 'mt-1 text-xs leading-5 text-yellow-800' }, comparisonReason),
                        ]),
                        smallBadge('不可直接比较', 'border-yellow-300 bg-white text-yellow-800'),
                    ]),
                ]);

                const fallbackActions = [
                    actionButton('上传携程订单', () => this.openCtripUpload(), true, 'dual-ota-order-upload-ctrip'),
                    actionButton('美团订单补采', () => this.openPlatformPage('meituan', 'meituan-orders'), false, 'dual-ota-order-open-meituan-orders'),
                ];
                const requiredActions = this.quickRequiredActions.length ? h('div', {
                    'data-testid': 'dual-ota-order-required-actions',
                    class: 'space-y-2 border-t border-gray-200 pt-4',
                }, this.quickRequiredActions.map((action, index) => h('button', {
                    key: action.key || index,
                    type: 'button',
                    'data-testid': `dual-ota-order-action-${String(action.key || index)}`,
                    onClick: () => this.runQuickAction(action),
                    class: 'block w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-left text-xs hover:bg-gray-50',
                }, [
                    h('span', { class: 'font-semibold text-gray-800' }, action.label || '补全订单证据'),
                    action.reason ? h('span', { class: 'mt-0.5 block text-xs leading-4 text-gray-500' }, action.reason) : null,
                ]))) : null;

                let content;
                if (!this.systemHotelId) {
                    content = h('div', { class: 'bg-white px-5 py-6 text-sm text-gray-500' }, '请先在页面顶部选择酒店。');
                } else if (this.quickLoading && !this.quickAnalysis) {
                    content = h('div', { role: 'status', 'aria-live': 'polite', class: 'bg-white px-5 py-6 text-sm text-gray-500' }, '正在从已存订单中同时回读携程与美团…');
                } else if (this.quickError && !this.quickAnalysis) {
                    content = h('div', { 'data-testid': 'dual-ota-order-read-failure', class: 'bg-white px-5 py-6' }, [
                        h('p', { class: 'text-sm leading-6 text-red-700' }, '订单回读未完成；系统没有把读取失败当成“无数据”，也不会提示重复补数。'),
                        h('button', {
                            type: 'button',
                            onClick: () => this.loadQuickAnalysis(),
                            class: 'mt-3 rounded-lg bg-gray-800 px-4 py-2 text-xs font-semibold text-white',
                        }, '重新读取'),
                    ]);
                } else if (!this.quickAnalysis) {
                    content = h('div', { class: 'bg-white px-5 py-6' }, [
                        h('div', { class: 'rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 text-sm text-gray-600' }, '暂时没有可展示的双平台订单回读。可先上传携程订单，或进入美团订单补采。'),
                        h('div', { class: 'mt-3 flex flex-wrap gap-2' }, fallbackActions),
                    ]);
                } else {
                    content = h('div', { class: 'space-y-4 bg-gray-50 p-4 lg:p-5' }, [
                        h('div', { class: 'grid grid-cols-1 gap-3 lg:grid-cols-2' }, this.quickPlatforms.map(platformSection)),
                        comparisonBlock,
                        requiredActions,
                    ]);
                }

                return h('section', {
                    'data-testid': 'dual-ota-order-quick-analysis-panel',
                    tabindex: '-1',
                    'aria-labelledby': 'dual-ota-order-quick-analysis-title',
                    'aria-busy': this.quickLoading ? 'true' : 'false',
                    class: 'overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-yellow-200',
                    style: { borderColor: 'rgba(20, 58, 49, 0.25)' },
                }, [
                    h('header', { class: 'px-4 py-4 text-white lg:px-5', style: { background: '#06110d' } }, [
                        h('div', { class: 'flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between' }, [
                            h('div', { class: 'min-w-0' }, [
                                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                    h('h4', { id: 'dual-ota-order-quick-analysis-title', class: 'text-lg font-semibold tracking-tight text-white' }, '双平台订单快析'),
                                    smallBadge(this.quickStatusLabel, this.quickStatusClass),
                                ]),
                                h('p', { class: 'mt-1 text-xs leading-5 text-gray-300' }, `${this.quickAnalysis?.hotel?.name || this.ctx?.platformHotelSelectedName || '当前酒店'} · ${this.quickDateRangeLabel}`),
                                h('p', { class: 'text-xs leading-4', style: { color: '#dcc591' } }, `${this.quickContractVersion} · 仅使用 OTA 渠道订单事实，不扩大为全酒店收入。`),
                            ]),
                            h('div', { class: 'flex max-w-full flex-col gap-2' }, [
                                h('div', { class: 'flex flex-wrap gap-2' }, [
                                    toolbarButton('近7天', '7d'), toolbarButton('近30天', '30d'), toolbarButton('最近已存30天', 'all'), toolbarButton('自定义', 'custom'),
                                    h('button', {
                                        type: 'button',
                                        disabled: this.quickLoading || !this.systemHotelId,
                                        onClick: () => this.loadQuickAnalysis(),
                                        class: 'rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-50',
                                        style: { borderColor: 'rgba(220, 197, 145, 0.5)', background: 'rgba(220, 197, 145, 0.1)', color: '#f4e7c7' },
                                    }, this.quickLoading ? '刷新中' : '刷新'),
                                ]),
                                this.quickRangePreset === 'custom' ? h('div', { class: 'flex flex-wrap items-end gap-2' }, [
                                    h('label', { class: 'text-xs text-gray-300' }, ['开始日期', h('input', { type: 'date', value: this.quickDateFrom, onInput: (event) => { this.quickDateFrom = event.target.value; }, class: 'mt-1 block rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900' })]),
                                    h('label', { class: 'text-xs text-gray-300' }, ['结束日期', h('input', { type: 'date', value: this.quickDateTo, onInput: (event) => { this.quickDateTo = event.target.value; }, class: 'mt-1 block rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900' })]),
                                    h('button', { type: 'button', disabled: this.quickLoading, onClick: () => this.loadQuickAnalysis(), class: 'rounded-lg px-3 py-2 text-xs font-semibold disabled:opacity-50', style: { background: '#dcc591', color: '#06110d' } }, '查询'),
                                ]) : null,
                            ]),
                        ]),
                    ]),
                    this.quickError ? h('div', {
                        'data-testid': 'dual-ota-order-quick-error',
                        role: this.quickStale ? 'status' : 'alert',
                        'aria-live': this.quickStale ? 'polite' : 'assertive',
                        class: 'border-b border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700',
                    }, this.quickStale ? `刷新失败，以下仍为上次成功回读：${this.quickError}` : this.quickError) : null,
                    content,
                ]);
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

            const quickPanel = this.renderQuickAnalysis();
            if (!this.showCtripDetail) return quickPanel;
            const detailPanel = h('section', {
                'data-testid': 'ctrip-order-analysis-panel',
                class: 'rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden',
            }, [header, body]);
            return h('div', {
                'data-testid': 'dual-ota-order-analysis-stack',
                class: 'space-y-4',
            }, [quickPanel, detailPanel]);
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
