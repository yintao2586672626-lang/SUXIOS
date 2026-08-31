(() => {
    'use strict';

    const components = window.SUXI_SYSTEM_COMPONENTS || (window.SUXI_SYSTEM_COMPONENTS = {});
    const shanghaiDate = (offsetDays = 0) => {
        const now = new Date(Date.now() + (offsetDays * 86400000));
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(now);
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}`;
    };
    const currentMonth = () => shanghaiDate().slice(0, 7);
    const monthEndDate = periodMonth => {
        const match = /^(\d{4})-(\d{2})$/.exec(String(periodMonth || ''));
        if (!match) throw new Error('账期格式无效');
        const days = new Date(Date.UTC(Number(match[1]), Number(match[2]), 0)).getUTCDate();
        return `${match[1]}-${match[2]}-${String(days).padStart(2, '0')}`;
    };
    const shanghaiDateTime = () => {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}:${values.second}`;
    };
    const asNumberOrNull = (value, nonNegative = true) => {
        const text = String(value ?? '').trim().replace(/[,，]/g, '');
        if (!text) return null;
        const number = Number(text);
        if (!Number.isFinite(number) || (nonNegative && number < 0)) throw new Error('除预算 GOP 外，金额、房晚和成本必须是大于等于0的数字');
        return number;
    };
    const csvRows = (text) => {
        const rows = [];
        let row = [], cell = '', quoted = false;
        const pushCell = () => { row.push(cell); cell = ''; };
        const pushRow = () => { pushCell(); if (row.some(value => String(value).trim() !== '')) rows.push(row); row = []; };
        for (let i = 0; i < text.length; i += 1) {
            const char = text[i];
            if (char === '"') {
                if (quoted && text[i + 1] === '"') { cell += '"'; i += 1; }
                else quoted = !quoted;
            } else if (char === ',' && !quoted) pushCell();
            else if ((char === '\n' || char === '\r') && !quoted) {
                if (char === '\r' && text[i + 1] === '\n') i += 1;
                pushRow();
            } else cell += char;
        }
        if (cell !== '' || row.length) pushRow();
        if (quoted) throw new Error('CSV 引号未闭合');
        return rows;
    };
    const parseCanonicalFile = (text, name = '') => {
        if (String(name).toLowerCase().endsWith('.json') || String(text).trim().startsWith('[')) {
            const parsed = JSON.parse(text);
            if (!Array.isArray(parsed)) throw new Error('JSON 根节点必须是结算明细数组');
            return parsed;
        }
        const rows = csvRows(text);
        if (rows.length < 2) throw new Error('CSV 至少需要表头和一行明细');
        const headers = rows.shift().map(value => String(value).trim());
        return rows.map(values => Object.fromEntries(headers.map((header, index) => [header, String(values[index] ?? '').trim()])))
            .map(row => {
                for (const key of [
                    'source_line_no', 'gross_amount', 'commission_amount', 'subsidy_amount', 'refund_amount',
                    'settlement_amount', 'net_revenue', 'ota_comparison_amount', 'pms_comparison_amount', 'discrepancy_amount',
                ]) {
                    if (row[key] !== '') row[key] = Number(row[key]);
                    else delete row[key];
                }
                return row;
            });
    };
    const sha256 = async text => {
        const bytes = new TextEncoder().encode(String(text));
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, '0')).join('');
    };
    const statusText = value => ({
        ready: '已就绪', available: '已取得', partial: '部分可用', invalid: '无效批次', blocked: '已阻塞', missing: '未取得',
        empty: '暂无记录', baseline_only: '仅建立基线', no_blocker_observed: '未观察到阻塞',
        evidence_missing: '缺少证据', sender_mapping_and_verified_event_required: '待员工映射与已验证事件',
        reference_only: '仅作参考', verified_source: '来源已核对', operator_attested: '人工已核对', unverified: '未核验',
        not_applicable: '不适用',
        same_scope_comparable: '同口径可比', same_scope_manual_snapshot_comparable: '同口径人工快照可比',
        blocked_incomplete_or_mixed_scope: '口径不齐，暂不可比',
        data_gap_repair: '先补数据', observation_only: '仅观察',
    })[String(value || '')] || String(value || '未知');
    const scopeText = value => ({
        whole_hotel: '全酒店', accommodation_room_fee: '住宿房费', ota_channel: 'OTA渠道',
    })[String(value || '')] || String(value || '未取得');
    const sourceText = value => ({
        ctrip: '携程', meituan: '美团', dingdandao_pms: '订单来了 PMS', manual_all_channels: '人工全渠道',
    })[String(value || '')] || String(value || '未取得');
    const discrepancyText = value => ({
        source_direct_gross: '来源直接给出的成交总额差异',
        source_direct_settlement: '来源直接给出的结算金额差异',
        source_direct_net_revenue: '来源直接给出的净收入差异',
        source_direct_refund: '来源直接给出的退款差异',
    })[String(value || '')] || (String(value || '').startsWith('derived_absolute_difference:')
        ? '同口径金额绝对差异'
        : '差异依据未取得');
    const gapText = value => {
        const raw = String(value || '');
        const separator = raw.indexOf(':');
        const datePrefix = separator > 0 ? raw.slice(0, separator) : '';
        const code = separator > 0 ? raw.slice(separator + 1) : raw;
        const label = ({
        verified_on_books_snapshot_missing: '缺少已核对的在手预订快照',
        previous_verified_on_books_snapshot_missing: '缺少上一条同范围快照',
        on_books_snapshot_time_not_increasing: '快照时间没有递增',
        on_books_snapshot_after_stay_date: '快照晚于目标入住日',
        on_books_fact_scope_changed: '前后快照事实范围不同',
        on_books_room_nights_missing: '在手间夜缺失',
        on_books_room_revenue_missing: '在手房费缺失',
        cumulative_cancel_room_nights_missing: '累计取消间夜缺失',
        cancellation_counter_reset_or_mismatch: '累计取消计数回退或口径变化',
        cancellation_gross_booking_base_missing: '累计毛预订基数缺失',
        ota_net_revenue_missing: 'OTA净收入缺失',
        whole_hotel_revenue_scope_unavailable: '当前不是全酒店收入范围',
        gop_not_calculable_from_ota_channel_scope: 'OTA渠道净收入不能计算全酒店 GOP',
        room_operating_revenue_missing: '客房经营收入缺失',
        departmental_expense_missing: '部门经营费用缺失',
        undistributed_operating_expense_missing: '未分配经营费用缺失',
        non_room_operating_revenue_missing: '非房经营收入缺失',
        gop_input_coverage_incomplete: 'GOP 输入口径不完整',
        rent_expense_missing: '租金缺失',
        other_fixed_cash_cost_missing: '其他固定现金成本缺失',
        whole_hotel_non_room_revenue_scope_unavailable: '住宿房费范围不含非房收入',
        gop_not_calculable_from_room_only_scope: '住宿房费范围不能计算全酒店 GOP',
        budget_total_operating_revenue_missing: '预算经营总收入缺失',
        budget_gop_missing: '预算 GOP 缺失',
        monthly_operating_finance_snapshot_missing: '当前账期尚无月度快照',
        window_snapshot_coverage_incomplete: '周期内在手快照覆盖不完整',
        window_pickup_coverage_incomplete: '周期内缺少可比的第二次快照',
        window_pickup_comparison_window_mismatch: '周期内前后快照时间对不一致，净拾取不可相加',
        window_on_books_room_nights_incomplete: '周期内在手间夜不完整',
        window_on_books_room_revenue_incomplete: '周期内在手房费不完整',
        })[code] || code || '未知缺口';
        return datePrefix ? `${datePrefix}：${label}` : label;
    };
    const financeFields = [
        { key: 'ota_net_revenue', label: 'OTA净收入', scopes: ['ota_channel'] },
        { key: 'room_operating_revenue', label: '客房经营收入', scopes: ['accommodation_room_fee', 'whole_hotel'] },
        { key: 'non_room_operating_revenue', label: '非房经营收入', scopes: ['whole_hotel'] },
        { key: 'departmental_expense', label: '部门经营费用', scopes: ['accommodation_room_fee', 'whole_hotel'] },
        { key: 'undistributed_operating_expense', label: '未分配经营费用', scopes: ['accommodation_room_fee', 'whole_hotel'] },
        { key: 'rent_expense', label: '租金', scopes: ['whole_hotel'] },
        { key: 'other_fixed_cash_cost', label: '其他固定现金成本', scopes: ['whole_hotel'] },
        { key: 'budget_total_operating_revenue', label: '预算经营总收入', scopes: ['whole_hotel'] },
        { key: 'budget_gop', label: '预算 GOP', scopes: ['whole_hotel'] },
    ];
    const emptyOnBooks = () => ({
        captured_at: shanghaiDateTime(), rooms: '', revenue: '', cancelled: '', gross: '', source_ref: '', confirmed: false,
    });
    const emptyEventForm = () => ({
        name: '', type: 'exhibition', start: shanghaiDate(1), end: shanghaiDate(1), area: '',
        source_ref: '', source_status: 'reference_only', observed_at: shanghaiDateTime(),
    });
    const emptyFinanceForm = () => ({
        fact_scope: 'whole_hotel', tax_basis: 'unknown', operator_attested: false, source_refs: '', ota_net_revenue: '', room_operating_revenue: '',
        non_room_operating_revenue: '', departmental_expense: '', undistributed_operating_expense: '',
        rent_expense: '', other_fixed_cash_cost: '', budget_total_operating_revenue: '', budget_gop: '',
    });

    components.OperatingFinanceControlCenterBody = {
        name: 'OperatingFinanceControlCenterBody',
        props: {
            hotels: { type: Array, default: () => [] },
            request: { type: Function, required: true },
            selectedHotelId: { type: [String, Number], default: '' },
            canExecute: { type: Boolean, default: false },
        },
        emits: ['update:selected-hotel-id'],
        data: () => ({
            hotelId: '', businessDate: shanghaiDate(), periodMonth: currentMonth(), stayDate: shanghaiDate(1), platform: 'ctrip',
            activeTab: 'settlement', loading: false, error: '', overview: null, requestSeq: 0,
            settlementText: '', settlementUploadFile: null, settlementFileName: '', settlementVerified: false,
            settlementInputKey: 0, settlementParserVersion: 'canonical_settlement_json.v1', savingSettlement: false,
            settlementImportNotice: null,
            onBooks: emptyOnBooks(),
            savingOnBooks: false,
            eventForm: emptyEventForm(),
            savingEvent: false,
            financeForm: emptyFinanceForm(),
            savingFinance: false,
        }),
        computed: {
            normalizedHotels() {
                return (Array.isArray(this.hotels) ? this.hotels : [])
                    .filter(hotel => Number(hotel?.id || 0) > 0)
                    .map(hotel => ({ id: Number(hotel.id), name: String(hotel.name || `酒店 ${hotel.id}`) }));
            },
            tabs() {
                return [
                    ['settlement', '净收入对账'], ['recovery', '阻塞恢复'], ['booking', '预订节奏'],
                    ['demand', '需求日历'], ['wecom', '企微回执'], ['finance', '月度经营贡献'], ['portfolio', '多店组合'],
                ];
            },
            currentSettlement() { return this.overview?.settlement || {}; },
            currentSettlementBasis() { return this.currentSettlement?.basis_ledger?.components || {}; },
            currentSettlementRecovery() { return this.currentSettlement?.recovery_blocker || {}; },
            settlementPlatformSupported() { return ['ctrip', 'meituan'].includes(this.platform); },
            currentRecovery() { return this.overview?.recovery || {}; },
            currentBooking() { return this.overview?.booking_pace || {}; },
            currentDemandPlan() { return this.overview?.booking_demand_plan || {}; },
            currentDemandWindows() {
                return Array.isArray(this.currentDemandPlan.windows) ? this.currentDemandPlan.windows : [];
            },
            currentDemand() { return this.overview?.demand_calendar || {}; },
            currentWecom() { return this.overview?.wecom_task_receipt || {}; },
            currentFinance() { return this.overview?.monthly_finance || {}; },
            currentPortfolio() { return this.overview?.portfolio || {}; },
            visibleFinanceFields() {
                return financeFields.filter(field => field.scopes.includes(this.financeForm.fact_scope));
            },
            financeScopeHint() {
                if (this.financeForm.fact_scope === 'ota_channel') return '只记录 OTA 渠道净收入，不计算全酒店 GOP。';
                if (this.financeForm.fact_scope === 'accommodation_room_fee') return '只计算住宿房费范围贡献；费用也必须是同一住宿范围，不扩大为全酒店 GOP。';
                return 'GOP 需要客房、非房收入和两类经营费用；业主现金代理还需要租金与其他固定现金成本。';
            },
        },
        watch: {
            selectedHotelId: { immediate: true, handler(value) {
                const candidate = String(value || '');
                if (candidate && this.normalizedHotels.some(hotel => String(hotel.id) === candidate)) this.hotelId = candidate;
            } },
            hotels: { immediate: true, handler() {
                if (!this.normalizedHotels.some(hotel => String(hotel.id) === String(this.hotelId))) {
                    const preferred = String(this.selectedHotelId || '');
                    this.hotelId = this.normalizedHotels.some(hotel => String(hotel.id) === preferred)
                        ? preferred : String(this.normalizedHotels[0]?.id || '');
                }
                if (this.hotelId) void this.loadOverview();
            } },
            hotelId(value, previous) {
                if (previous != null && String(value) !== String(previous)) this.resetAllWriteDrafts();
            },
            businessDate() { if (this.hotelId) void this.loadOverview(); },
            periodMonth(value, previous) {
                if (previous != null && value !== previous) {
                    this.resetSettlementDraft();
                    this.resetFinanceDraft();
                }
                if (this.hotelId) void this.loadOverview();
            },
            stayDate(value, previous) {
                if (previous != null && value !== previous) this.resetOnBooksDraft();
                if (this.hotelId) void this.loadOverview();
            },
            platform(value, previous) {
                if (previous != null && value !== previous) {
                    this.resetSettlementDraft();
                    this.resetOnBooksDraft();
                }
                if (this.hotelId) void this.loadOverview();
            },
        },
        methods: {
            notify(message, type = 'success') { this.$root?.showToast?.(message, type); },
            statusText,
            scopeText,
            sourceText,
            discrepancyText,
            gapText,
            money(value) { return value == null || value === '' ? '未取得' : `¥${Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 2 })}`; },
            async loadOverview() {
                const hotelId = Number(this.hotelId || 0);
                if (hotelId <= 0) return;
                const seq = ++this.requestSeq;
                this.loading = true; this.error = '';
                try {
                    const params = new URLSearchParams({
                        hotel_id: String(hotelId), business_date: this.businessDate, period_month: this.periodMonth,
                        stay_date: this.stayDate, platform: this.platform,
                    });
                    const response = await this.request(`/operating-finance/overview?${params}`, { businessContext: { hotelId } });
                    if (seq !== this.requestSeq) return;
                    if (response.code !== 200
                        || response.data?.contract_version !== 'operating_finance_control_center.v1'
                        || Number(response.data?.hotel_id || 0) !== hotelId
                        || Number(response.data?.boundaries?.external_write_count ?? -1) !== 0
                    ) throw new Error(response.message || '经营财务与恢复中心读取失败');
                    this.overview = response.data;
                    this.$emit('update:selected-hotel-id', hotelId);
                } catch (error) {
                    if (seq === this.requestSeq) { this.overview = null; this.error = error?.message || '经营财务与恢复中心读取失败'; }
                } finally { if (seq === this.requestSeq) this.loading = false; }
            },
            clearSettlementFile() {
                this.settlementUploadFile = null;
                this.settlementFileName = '';
                this.settlementInputKey += 1;
            },
            resetSettlementDraft() {
                this.clearSettlementFile();
                this.settlementText = '';
                this.settlementVerified = false;
                this.settlementImportNotice = null;
            },
            resetOnBooksDraft() { this.onBooks = emptyOnBooks(); },
            resetEventDraft() { this.eventForm = emptyEventForm(); },
            resetFinanceDraft() { this.financeForm = emptyFinanceForm(); },
            resetAllWriteDrafts() {
                this.resetSettlementDraft();
                this.resetOnBooksDraft();
                this.resetEventDraft();
                this.resetFinanceDraft();
            },
            onSettlementTextInput() {
                if (this.settlementUploadFile) this.clearSettlementFile();
            },
            async onSettlementFile(event) {
                const file = event?.target?.files?.[0];
                if (!file) return;
                this.error = '';
                this.settlementVerified = false;
                try {
                    if (!/\.(json|csv|xlsx)$/i.test(file.name)) throw new Error('只支持 JSON、CSV 或 XLSX 结算文件');
                    this.settlementUploadFile = file;
                    this.settlementFileName = file.name;
                    if (/\.xlsx$/i.test(file.name)) {
                        this.settlementText = '';
                        return;
                    }
                    const text = await file.text();
                    const lines = parseCanonicalFile(text, file.name);
                    this.settlementText = JSON.stringify(lines, null, 2);
                } catch (error) {
                    this.clearSettlementFile();
                    this.error = error?.message || '结算文件解析失败';
                    this.notify(this.error, 'error');
                }
            },
            async importSettlement() {
                if (!this.hotelId) return;
                if (!this.settlementPlatformSupported) {
                    this.error = '结算导入只适用于携程或美团；PMS与人工全渠道来源不会静默代换成携程。';
                    this.notify(this.error, 'error');
                    return;
                }
                this.savingSettlement = true; this.error = ''; this.settlementImportNotice = null;
                try {
                    const first = `${this.periodMonth}-01`;
                    const periodEnd = monthEndDate(this.periodMonth);
                    let response;
                    if (this.settlementUploadFile) {
                        const body = new FormData();
                        body.append('file', this.settlementUploadFile);
                        body.append('hotel_id', String(this.hotelId));
                        body.append('platform', this.platform);
                        body.append('period_start', first);
                        body.append('period_end', periodEnd);
                        body.append('amount_scope', 'settlement');
                        body.append('operator_attested', this.settlementVerified ? '1' : '0');
                        response = await this.request('/operating-finance/settlements/import-file', {
                            method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body,
                        });
                    } else {
                        const lines = JSON.parse(this.settlementText || '[]');
                        if (!Array.isArray(lines) || !lines.length) throw new Error('请上传或粘贴至少一行规范结算明细');
                        const serialized = JSON.stringify(lines);
                        const fileSha = await sha256(serialized);
                        response = await this.request('/operating-finance/settlements/import', {
                            method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify({
                                hotel_id: Number(this.hotelId), lines, scope: {
                                    platform: this.platform, period_start: first, period_end: periodEnd, file_sha256: fileSha,
                                    source_method: 'manual_export', operator_attested: this.settlementVerified,
                                    parser_version: this.settlementParserVersion,
                                },
                            }),
                        });
                    }
                    if (response.code !== 200
                        || response.data?.readback_verified !== true
                        || response.data?.request_status !== 'saved_and_readback_verified'
                    ) throw new Error(response.message || '结算批次保存后未完成精确回读');
                    const batchStatus = String(response.data?.business_result_status || response.data?.batch_status || '');
                    if (!['available', 'partial', 'invalid'].includes(batchStatus)) throw new Error('结算批次业务状态无效');
                    if (batchStatus === 'available' && response.data?.business_success !== true) throw new Error('结算批次成功状态与业务结果不一致');
                    if (batchStatus !== 'available' && response.data?.business_success !== false) throw new Error('结算批次警告状态与业务结果不一致');
                    const gapCodes = [...new Set((response.data?.lines || []).flatMap(row => Array.isArray(row?.gap_codes) ? row.gap_codes : []))];
                    const notice = {
                        status: batchStatus,
                        message: response.message || (batchStatus === 'invalid'
                            ? '结算失败尝试已留痕；未形成可用净收入事实'
                            : (batchStatus === 'partial' ? '结算批次仅部分可用' : '结算批次已保存并回读')),
                        gap_codes: gapCodes,
                    };
                    this.notify(notice.message, batchStatus === 'available' ? 'success' : 'warning');
                    this.resetSettlementDraft();
                    this.settlementImportNotice = notice;
                    await this.loadOverview();
                } catch (error) { this.error = error?.message || '结算批次导入失败'; this.notify(this.error, 'error'); }
                finally { this.savingSettlement = false; }
            },
            async saveOnBooks() {
                this.savingOnBooks = true; this.error = '';
                try {
                    const content = {
                        hotel_id: Number(this.hotelId), platform: this.platform,
                        fact_scope: ['ctrip', 'meituan'].includes(this.platform) ? 'ota_channel' : 'accommodation_room_fee',
                        stay_date: this.stayDate, captured_at: this.onBooks.captured_at,
                        source_ref: this.onBooks.source_ref, on_books_room_nights: asNumberOrNull(this.onBooks.rooms),
                        on_books_room_revenue: asNumberOrNull(this.onBooks.revenue),
                        cumulative_cancel_room_nights: asNumberOrNull(this.onBooks.cancelled),
                        gross_booking_room_nights: asNumberOrNull(this.onBooks.gross),
                        operator_attested: this.onBooks.confirmed,
                    };
                    content.idempotency_key = `onbooks:${await sha256(JSON.stringify(content))}`;
                    const response = await this.request('/operating-finance/on-books-snapshots', {
                        method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify(content),
                    });
                    if (response.code !== 200 || Number(response.data?.id || 0) <= 0) throw new Error(response.message || '在手预订保存失败');
                    this.notify(this.onBooks.confirmed ? '人工核对快照已保存并完成本地回读，可进入同范围基线' : '在手预订已保存为未核验，不参与正式节奏');
                    this.resetOnBooksDraft();
                    await this.loadOverview();
                } catch (error) { this.error = error?.message || '在手预订保存失败'; this.notify(this.error, 'error'); }
                finally { this.savingOnBooks = false; }
            },
            async saveEvent() {
                this.savingEvent = true; this.error = '';
                try {
                    const content = {
                        hotel_id: Number(this.hotelId), event_name: this.eventForm.name, event_type: this.eventForm.type,
                        event_start_date: this.eventForm.start, event_end_date: this.eventForm.end, area_label: this.eventForm.area,
                        source_method: 'manual_reference', source_ref: this.eventForm.source_ref,
                        source_status: 'reference_only', observed_at: this.eventForm.observed_at,
                    };
                    content.idempotency_key = `event:${await sha256(JSON.stringify(content))}`;
                    const response = await this.request('/operating-finance/demand-events', {
                        method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify(content),
                    });
                    if (response.code !== 200 || Number(response.data?.id || 0) <= 0) throw new Error(response.message || '需求事件保存失败');
                    this.notify('需求事件已保存为参考事实，不会自动触发调价');
                    this.resetEventDraft();
                    await this.loadOverview();
                } catch (error) { this.error = error?.message || '需求事件保存失败'; this.notify(this.error, 'error'); }
                finally { this.savingEvent = false; }
            },
            async saveFinance() {
                this.savingFinance = true; this.error = '';
                try {
                    const inputs = Object.fromEntries(financeFields.map(field => [field.key, null]));
                    for (const field of this.visibleFinanceFields) {
                        inputs[field.key] = asNumberOrNull(this.financeForm[field.key], field.key === 'budget_gop' ? false : true);
                    }
                    const sourceRefs = this.financeForm.source_refs.split(/[,，\n]/).map(value => value.trim()).filter(Boolean);
                    const content = {
                        hotel_id: Number(this.hotelId), period_month: this.periodMonth,
                        fact_scope: this.financeForm.fact_scope, tax_basis: this.financeForm.tax_basis,
                        operator_attested: this.financeForm.operator_attested, inputs, source_refs: sourceRefs,
                    };
                    content.idempotency_key = `monthly:${await sha256(JSON.stringify(content))}`;
                    const response = await this.request('/operating-finance/monthly-finance', {
                        method: 'POST', businessContext: { hotelId: Number(this.hotelId) }, body: JSON.stringify(content),
                    });
                    if (response.code !== 200 || response.data?.readback_verified !== true) throw new Error(response.message || '月度经营财务保存后未完成精确回读');
                    this.notify('月度经营财务快照已保存并回读');
                    this.resetFinanceDraft();
                    await this.loadOverview();
                } catch (error) { this.error = error?.message || '月度经营财务保存失败'; this.notify(this.error, 'error'); }
                finally { this.savingFinance = false; }
            },
        },
        template: `
            <section class="suxi-dashboard-scope space-y-4" data-testid="operating-finance-control-center">
                <header class="operating-finance-hero overflow-hidden rounded-2xl border px-5 py-5 text-white shadow-sm">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div><p class="operating-finance-eyebrow text-xs font-semibold tracking-[0.2em]">经营事实 · 恢复 · 财务</p><h2 class="mt-2 text-xl font-bold">经营财务与恢复中心</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-emerald-100/80">把结算净收入、唯一阻塞、真实预订节奏、需求事件、企微回执、月度经营贡献和多店组合放在同一范围内。缺失继续缺失，不自动审批、外发或写 OTA/PMS。</p></div>
                        <button type="button" data-testid="operating-finance-refresh" @click="loadOverview" :disabled="loading" class="operating-finance-gold-action rounded-xl px-4 py-2.5 text-sm font-semibold disabled:opacity-50"><i :class="['fas mr-2', loading ? 'fa-spinner fa-spin' : 'fa-sync-alt']"></i>{{ loading ? '读取中' : '刷新全部' }}</button>
                    </div>
                </header>
                <div class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-5">
                    <label class="text-xs text-slate-500">酒店<select v-model="hotelId" @change="loadOverview" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-800"><option v-for="hotel in normalizedHotels" :key="hotel.id" :value="String(hotel.id)">{{ hotel.name }}</option></select></label>
                    <label class="text-xs text-slate-500">业务日期<input v-model="businessDate" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></label>
                    <label class="text-xs text-slate-500">账期<input v-model="periodMonth" type="month" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></label>
                    <label class="text-xs text-slate-500">目标入住日<input v-model="stayDate" type="date" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></label>
                    <label class="text-xs text-slate-500">来源<select v-model="platform" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"><option value="ctrip">携程</option><option value="meituan">美团</option><option value="dingdandao_pms">订单来了 PMS</option><option value="manual_all_channels">人工全渠道</option></select></label>
                </div>
                <p v-if="error" data-testid="operating-finance-error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
                <nav class="flex gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2" aria-label="经营财务模块">
                    <button v-for="tab in tabs" :key="tab[0]" type="button" @click="activeTab = tab[0]" :data-testid="'operating-finance-tab-' + tab[0]" :class="['whitespace-nowrap rounded-xl px-4 py-2 text-sm font-medium', activeTab === tab[0] ? 'operating-finance-tab-active' : 'text-slate-600 hover:bg-slate-50']">{{ tab[1] }}</button>
                </nav>

                <section v-if="activeTab === 'settlement'" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,.75fr)]" data-testid="operating-finance-settlement">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">OTA净收入与差异</h3><span class="rounded-full border px-2 py-1 text-xs">{{ statusText(currentSettlement.batch_status || currentSettlement.status) }}</span></div>
                        <p v-if="currentSettlement.projection_status === 'latest_non_invalid_with_newer_invalid_attempt'" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">最新一次导入（批次 #{{ currentSettlement.latest_attempt?.batch_id }}，{{ currentSettlement.latest_attempt?.imported_at }}）校验失败；下方暂显示上一份未判无效的批次。失败尝试未覆盖旧事实，请修正文件后重新导入。</p>
                        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3"><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">OTA订单成交总额</div><div class="mt-1 font-bold">{{ money(currentSettlementBasis.order_gross_amount?.value) }}</div><div class="mt-1 text-[10px] text-slate-400">{{ currentSettlementBasis.order_gross_amount?.basis || '口径未取得' }}</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">佣金</div><div class="mt-1 font-bold">{{ money(currentSettlementBasis.commission_amount?.value) }}</div><div class="mt-1 text-[10px] text-slate-400">{{ currentSettlementBasis.commission_amount?.basis || '口径未取得' }}</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">退款</div><div class="mt-1 font-bold">{{ money(currentSettlementBasis.refund_amount?.value) }}</div><div class="mt-1 text-[10px] text-slate-400">{{ currentSettlementBasis.refund_amount?.basis || '口径未取得' }}</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">平台补贴调账</div><div class="mt-1 font-bold">{{ money(currentSettlementBasis.adjustment?.value) }}</div><div class="mt-1 text-[10px] text-slate-400">只代表已取得的平台补贴，不代表全部调账</div></div><div class="rounded-xl bg-sky-50 p-3"><div class="text-xs text-sky-600">结算金额</div><div class="mt-1 font-bold text-sky-900">{{ money(currentSettlementBasis.settlement_amount?.value) }}</div><div class="mt-1 text-[10px] text-sky-600">结算金额不自动等于净收入</div></div><div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-600">渠道净收入</div><div class="mt-1 font-bold text-emerald-900">{{ money(currentSettlementBasis.net_revenue?.value) }}</div><div class="mt-1 text-[10px] text-emerald-600">仅当前OTA渠道，不代表全酒店GOP</div></div></div>
                        <div v-if="currentSettlement.ranked_discrepancies?.length" class="mt-4 space-y-2"><div v-for="item in currentSettlement.ranked_discrepancies.slice(0,5)" :key="item.source_line_no" class="flex items-center justify-between rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-sm"><span>第 {{ item.source_line_no }} 行 · {{ discrepancyText(item.discrepancy_basis) }}</span><strong>{{ money(item.discrepancy_amount) }}</strong></div></div>
                        <p v-else class="mt-4 text-sm text-slate-500">没有同账期已回读结算批次，或当前批次没有可排序差异。</p>
                        <div v-if="currentSettlementRecovery.selected" data-testid="operating-finance-settlement-recovery-candidate" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4"><div class="flex items-center justify-between gap-3"><strong class="text-sm text-amber-950">唯一恢复事项</strong><span class="rounded-full bg-white px-2 py-1 text-[11px] text-amber-800">{{ currentSettlementRecovery.selected.reason_code }}</span></div><p class="mt-2 text-sm font-medium text-amber-950">{{ currentSettlementRecovery.selected.title }}</p><p class="mt-2 text-xs leading-5 text-amber-800">仅提示人工复核；没有创建审批、执行任务或财务写入。</p></div>
                    </div>
                    <form v-if="settlementPlatformSupported && canExecute" @submit.prevent="importSettlement" class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold text-slate-900">导入规范 JSON / CSV / XLSX</h3>
                        <p class="mt-1 text-xs leading-5 text-slate-500">支持规范字段导入；平台原始 Excel 若不是规范表头仍需对应解析模板，不能凭列名猜测。订单和住宿号只存哈希。</p>
                        <div v-if="settlementImportNotice" data-testid="operating-finance-settlement-import-notice" :class="['mt-3 rounded-xl border p-3 text-xs leading-5', settlementImportNotice.status === 'available' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800']">
                            <strong>{{ settlementImportNotice.status === 'invalid' ? '未形成可用净收入事实' : (settlementImportNotice.status === 'partial' ? '结算数据仅部分可用' : '结算批次可用') }}</strong>
                            <p class="mt-1">{{ settlementImportNotice.message }}</p>
                            <p v-if="settlementImportNotice.gap_codes?.length" class="mt-1">需修正：{{ settlementImportNotice.gap_codes.slice(0, 5).map(gapText).join('、') }}</p>
                        </div>
                        <input :key="settlementInputKey" type="file" accept=".json,.csv,.xlsx,application/json,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="onSettlementFile" class="mt-3 block w-full text-xs">
                        <div v-if="settlementFileName" class="mt-2 flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            <span>当前按文件导入：{{ settlementFileName }}</span>
                            <button type="button" @click="clearSettlementFile" class="operating-finance-link font-semibold">改用下方文本</button>
                        </div>
                        <textarea v-model="settlementText" @input="onSettlementTextInput" rows="8" placeholder='[{"source_line_no":1,"business_date":"2026-08-01","amount_scope":"settlement","gross_amount":1000,"gross_amount_basis":"source_direct","commission_amount":150,"commission_amount_basis":"source_direct","match_status":"not_evaluated"}]' class="mt-3 w-full rounded-xl border border-slate-200 p-3 font-mono text-xs"></textarea>
                        <p class="mt-1 text-xs text-slate-400">编辑文本会自动取消已选文件，避免旧文件覆盖新内容；切换酒店、平台或账期会清空本次导入草稿。</p>
                        <label class="mt-3 flex items-start gap-2 text-xs text-slate-600"><input v-model="settlementVerified" type="checkbox" class="mt-0.5">我已人工核对这是当前酒店、平台和账期的授权导出；本地导入仍标为“人工核对”，不会冒充平台身份已验证</label>
                        <button type="submit" :disabled="savingSettlement" class="operating-finance-primary-action mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ savingSettlement ? '保存并回读中…' : '导入结算批次' }}</button>
                    </form>
                    <div v-else-if="!settlementPlatformSupported" class="rounded-2xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-600">结算导入只适用于携程或美团。当前来源是 {{ sourceText(currentSettlement.scope?.platform || platform) }}，系统未代换为其他平台，也没有显示跨来源结算事实。</div>
                    <div v-else data-testid="operating-finance-view-only" class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm leading-6 text-slate-600">当前账号只有查看权限；可读取结算事实和差异，但不能导入文件、保存快照或创建经营记录。</div>
                </section>

                <section v-if="activeTab === 'recovery'" class="rounded-2xl border border-slate-200 bg-white p-5" data-testid="operating-finance-recovery"><div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">唯一当前阻塞</h3><span class="rounded-full border px-2 py-1 text-xs">{{ statusText(currentRecovery.status) }}</span></div><div v-if="currentRecovery.selected" class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-5"><div class="flex flex-wrap gap-2 text-xs"><span class="rounded-full bg-white px-2 py-1">{{ currentRecovery.selected.source_label }}</span><span class="rounded-full bg-white px-2 py-1">{{ currentRecovery.selected.category_label }}</span><span class="rounded-full bg-white px-2 py-1">{{ currentRecovery.selected.business_impact }}</span></div><h4 class="mt-3 font-bold text-amber-950">{{ currentRecovery.selected.reason }}</h4><p class="mt-2 text-sm leading-6 text-amber-900">{{ currentRecovery.selected.next_action }}</p><p class="mt-2 text-xs text-amber-700">{{ currentRecovery.selected.resumable ? '满足恢复条件后，可由操作者重新进行同范围只读核验；当前不会自动执行。' : '必须由有权人员主动处理；当前不会自动执行。' }}</p></div><div v-else class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800">{{ currentRecovery.status === 'no_blocker_observed' ? '当前同范围证据未观察到阻塞；系统没有执行任何恢复动作。' : '阻塞证据尚未取得；不能据此判断系统正常。' }}</div></section>

                <section v-if="activeTab === 'booking'" class="rounded-2xl border border-slate-200 bg-white p-5" data-testid="operating-finance-booking-demand-plan">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-bold text-slate-900">明天 / 未来3天 / 未来7天需求计划</h3><p class="mt-1 text-xs leading-5 text-slate-500">三个窗口都从明天开始，不包含今天。只有窗口内每天都有同范围快照时才显示完整合计；部分覆盖只显示已观察值。</p></div><span class="rounded-full border px-2 py-1 text-xs">{{ statusText(currentDemandPlan.status) }}</span></div>
                    <div v-if="currentDemandWindows.length" class="mt-4 grid gap-3 lg:grid-cols-3">
                        <article v-for="window in currentDemandWindows" :key="window.window_key" :data-window-key="window.window_key" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex items-center justify-between gap-2"><strong class="text-sm text-slate-900">{{ window.label }}</strong><span class="text-[11px] text-slate-500">{{ statusText(window.status) }}</span></div>
                            <p class="mt-1 text-[11px] text-slate-400">{{ window.start_date }}<span v-if="window.end_date !== window.start_date"> 至 {{ window.end_date }}</span> · 快照 {{ window.snapshot_coverage_days }}/{{ window.day_count }}天 · 可比 {{ window.pickup_coverage_days }}/{{ window.day_count }}天</p>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs"><div class="rounded-lg bg-white p-2"><span class="text-slate-400">在手间夜</span><div class="mt-1 font-semibold">{{ window.on_books_room_nights_total ?? '未形成完整合计' }}</div><div v-if="window.on_books_room_nights_total == null && window.observed_on_books_room_nights != null" class="mt-1 text-[10px] text-slate-400">已观察 {{ window.observed_on_books_room_nights }}</div></div><div class="rounded-lg bg-white p-2"><span class="text-slate-400">净拾取</span><div class="mt-1 font-semibold">{{ window.net_pickup_room_nights_total ?? '未形成完整合计' }}</div><div v-if="window.net_pickup_room_nights_total == null && window.observed_net_pickup_room_nights != null" class="mt-1 text-[10px] text-slate-400">已观察 {{ window.observed_net_pickup_room_nights }}</div></div><div class="rounded-lg bg-white p-2"><span class="text-slate-400">在手房费</span><div class="mt-1 font-semibold">{{ money(window.on_books_room_revenue_total) }}</div></div><div class="rounded-lg bg-white p-2"><span class="text-slate-400">需求事件</span><div class="mt-1 font-semibold">{{ window.event_count ?? 0 }} 条</div></div></div>
                            <p v-if="window.data_gaps?.length" class="mt-3 text-[11px] leading-5 text-amber-700">{{ window.data_gaps.slice(0, 4).map(gapText).join(' · ') }}</p>
                        </article>
                    </div>
                    <p v-else class="mt-4 text-sm text-slate-500">明天至未来7天暂无已回读在手快照；系统不会用今天、长期预测或默认值补齐。</p>
                </section>

                <section v-if="activeTab === 'booking'" class="grid gap-4 xl:grid-cols-2" data-testid="operating-finance-booking"><div class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-slate-900">选定入住日的真实预订节奏</h3><div class="mt-4 grid grid-cols-2 gap-3"><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">净拾取</div><div class="mt-1 font-bold">{{ currentBooking.net_pickup_room_nights ?? '未取得' }} 间夜</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">毛拾取</div><div class="mt-1 font-bold">{{ currentBooking.gross_pickup_room_nights ?? '未取得' }} 间夜</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">每小时净拾取</div><div class="mt-1 font-bold">{{ currentBooking.pickup_room_nights_per_hour ?? '未取得' }}</div></div><div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">取消率</div><div class="mt-1 font-bold">{{ currentBooking.cancellation_rate_percent ?? '未取得' }}<span v-if="currentBooking.cancellation_rate_percent != null">%</span></div></div></div><p class="mt-3 text-xs leading-5 text-slate-500">{{ (currentBooking.data_gaps || []).map(gapText).join(' · ') || '两个同范围已核对且本地回读的快照可比。' }}</p></div><form v-if="canExecute" @submit.prevent="saveOnBooks" class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-slate-900">记录一条在手快照</h3><div class="mt-3 grid grid-cols-2 gap-3"><input v-model="onBooks.captured_at" type="datetime-local" step="1" class="col-span-2 rounded-lg border p-2 text-sm"><input v-model="onBooks.rooms" inputmode="decimal" placeholder="在手间夜" class="rounded-lg border p-2 text-sm"><input v-model="onBooks.revenue" inputmode="decimal" placeholder="在手房费" class="rounded-lg border p-2 text-sm"><input v-model="onBooks.cancelled" inputmode="decimal" placeholder="累计取消间夜，可空" class="rounded-lg border p-2 text-sm"><input v-model="onBooks.gross" inputmode="decimal" placeholder="累计毛预订间夜，可空" class="rounded-lg border p-2 text-sm"><input v-model="onBooks.source_ref" placeholder="来源引用/文件指纹" class="col-span-2 rounded-lg border p-2 text-sm"></div><label class="mt-3 flex items-start gap-2 text-xs text-slate-600"><input v-model="onBooks.confirmed" type="checkbox" class="mt-0.5">我已人工核对酒店、平台、入住日和来源；否则只保存为未核验，不进入正式节奏。本地回读由服务端完成，不由此勾选声明。</label><button type="submit" :disabled="savingOnBooks" class="operating-finance-primary-action mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ savingOnBooks ? '保存中…' : '保存快照' }}</button></form><div v-else data-testid="operating-finance-view-only" class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">当前账号只有查看权限，不能保存新的在手预订快照。</div></section>

                <section v-if="activeTab === 'demand'" class="grid gap-4 xl:grid-cols-2" data-testid="operating-finance-demand">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-slate-900">明天至未来7天本地需求事件</h3><div v-if="currentDemand.events?.length" class="mt-3 space-y-2"><div v-for="event in currentDemand.events" :key="event.id" class="rounded-xl border border-slate-200 p-3"><div class="flex items-center justify-between gap-3"><strong class="text-sm">{{ event.event_name }}</strong><span class="text-xs text-slate-400">{{ event.event_start_date }}—{{ event.event_end_date }}</span></div><p class="mt-1 text-xs text-slate-500">{{ event.area_label }} · {{ statusText(event.source_status) }} · 观察于 {{ event.observed_at }}</p></div></div><p v-else class="mt-4 text-sm text-slate-500">明天至未来7天没有已保存事件；天气和节假日之外的本地活动必须有来源才能进入。</p></div>
                    <form v-if="canExecute" @submit.prevent="saveEvent" class="rounded-2xl border border-slate-200 bg-white p-5"><h3 class="font-bold text-slate-900">添加参考事件</h3><div class="mt-3 grid grid-cols-2 gap-3"><input v-model="eventForm.name" placeholder="事件名称" class="col-span-2 rounded-lg border p-2 text-sm"><select v-model="eventForm.type" class="rounded-lg border p-2 text-sm"><option value="exhibition">会展</option><option value="concert">演出</option><option value="exam">考试</option><option value="transport">交通</option><option value="weather">天气</option><option value="holiday">节假日</option><option value="policy">政策</option><option value="other">其他</option></select><input v-model="eventForm.area" placeholder="影响区域" class="rounded-lg border p-2 text-sm"><input v-model="eventForm.start" type="date" class="rounded-lg border p-2 text-sm"><input v-model="eventForm.end" type="date" class="rounded-lg border p-2 text-sm"><input v-model="eventForm.observed_at" type="datetime-local" step="1" class="col-span-2 rounded-lg border p-2 text-sm"><input v-model="eventForm.source_ref" placeholder="来源引用/内容指纹" class="col-span-2 rounded-lg border p-2 text-sm"></div><p class="mt-2 text-xs leading-5 text-slate-500">手工添加始终保存为“仅作参考”，不会由客户端自行升级为已验证来源，也不会自动触发调价。</p><button type="submit" :disabled="savingEvent" class="operating-finance-primary-action mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ savingEvent ? '保存中…' : '保存为参考事件' }}</button></form><div v-else data-testid="operating-finance-view-only" class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">当前账号只有查看权限，不能保存需求事件。</div>
                </section>

                <section v-if="activeTab === 'wecom'" class="rounded-2xl border border-slate-200 bg-white p-5" data-testid="operating-finance-wecom"><div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">企业微信员工执行回执</h3><span class="rounded-full border px-2 py-1 text-xs">{{ statusText(currentWecom.status) }}</span></div><p class="mt-3 text-sm leading-6 text-slate-600">当前已记录 {{ currentWecom.receipt_count ?? 0 }} 条结构化员工自报。回执服务只接收事件 ID，并由服务端重读已归档终态事件；回执不等于审批、执行成功或财务证据。原消息正文、结果和证据说明不会复制到新表，只保存摘要与可关联的发送者伪名哈希。</p><pre class="mt-4 overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-5 text-emerald-100">{"task_id":321,"status":"acknowledged|in_progress|completed|blocked|failed","result":"1-500字","evidence_note":"1-500字","amount":"120.50"}</pre><p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">现场启用前必须建立“企微发送者哈希 → 宿析OS员工”验证映射，并确认员工正是任务负责人。缺映射时保持阻断，不允许用当前登录用户或自由文本冒充。</p></section>

                <section v-if="activeTab === 'finance'" class="grid gap-4 xl:grid-cols-2" data-testid="operating-finance-monthly">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold text-slate-900">月度经营贡献</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ statusText(currentFinance.source?.source_quality_status || 'unverified') }} · {{ currentFinance.source?.currency || 'CNY' }} · {{ currentFinance.source?.tax_basis === 'tax_inclusive' ? '含税' : (currentFinance.source?.tax_basis === 'tax_exclusive' ? '不含税' : '税费口径未确认') }}</p>
                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">{{ currentFinance.results?.fact_scope === 'whole_hotel' ? '经营总收入' : (currentFinance.results?.fact_scope === 'ota_channel' ? 'OTA净收入' : (currentFinance.results?.fact_scope === 'accommodation_room_fee' ? '客房经营收入' : '经营收入')) }}</div><div class="mt-1 font-bold">{{ money(currentFinance.results?.fact_scope === 'whole_hotel' ? currentFinance.results?.total_operating_revenue : currentFinance.results?.recognized_revenue) }}</div></div>
                            <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-600">{{ currentFinance.results?.fact_scope === 'accommodation_room_fee' ? '住宿范围贡献' : 'GOP' }}</div><div class="mt-1 font-bold text-emerald-900">{{ money(currentFinance.results?.fact_scope === 'accommodation_room_fee' ? currentFinance.results?.room_operating_contribution : currentFinance.results?.gop) }}</div></div>
                            <div v-if="currentFinance.results?.fact_scope === 'whole_hotel'" class="rounded-xl bg-slate-50 p-3"><div class="text-xs text-slate-400">GOP率</div><div class="mt-1 font-bold">{{ currentFinance.results?.gop_margin_percent ?? '未取得' }}<span v-if="currentFinance.results?.gop_margin_percent != null">%</span></div></div>
                            <div v-if="currentFinance.results?.fact_scope === 'whole_hotel'" class="rounded-xl bg-amber-50 p-3"><div class="text-xs text-amber-600">业主现金代理</div><div class="mt-1 font-bold text-amber-900">{{ money(currentFinance.results?.owner_cash_proxy_before_tax_capex_and_financing) }}</div></div>
                            <div v-if="currentFinance.results?.fact_scope === 'whole_hotel'" class="rounded-xl bg-sky-50 p-3"><div class="text-xs text-sky-600">收入较预算</div><div class="mt-1 font-bold text-sky-900">{{ money(currentFinance.results?.budget_total_operating_revenue_variance) }}</div></div>
                            <div v-if="currentFinance.results?.fact_scope === 'whole_hotel'" class="rounded-xl bg-sky-50 p-3"><div class="text-xs text-sky-600">GOP较预算</div><div class="mt-1 font-bold text-sky-900">{{ money(currentFinance.results?.budget_gop_variance) }}</div></div>
                        </div>
                        <p class="mt-3 text-xs leading-5 text-slate-500">业主现金代理是 GOP 扣租金及已录入固定现金成本后的代理值，不等于会计现金流；未单独扣除税费、资本开支、融资、折旧、营运资金与还本付息。</p>
                        <p v-if="currentFinance.missing_items?.length" class="mt-2 text-xs leading-5 text-amber-700">{{ currentFinance.missing_items.map(gapText).join(' · ') }}</p>
                    </div>
                    <form v-if="canExecute" @submit.prevent="saveFinance" class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold text-slate-900">保存月度快照</h3>
                        <select v-model="financeForm.fact_scope" class="mt-3 w-full rounded-lg border p-2 text-sm"><option value="whole_hotel">全酒店（需房费与非房收入）</option><option value="accommodation_room_fee">住宿房费范围</option><option value="ota_channel">OTA渠道范围</option></select>
                        <select v-model="financeForm.tax_basis" class="mt-2 w-full rounded-lg border p-2 text-sm"><option value="unknown">税费口径未确认（不可跨店排名）</option><option value="tax_inclusive">含税口径</option><option value="tax_exclusive">不含税口径</option></select>
                        <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">{{ financeScopeHint }}</p>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <label v-for="field in visibleFinanceFields" :key="field.key" class="text-xs text-slate-500"><span>{{ field.label }}（元）</span><input v-model="financeForm[field.key]" inputmode="decimal" :placeholder="'输入' + field.label" class="mt-1 w-full rounded-lg border p-2 text-sm text-slate-800"></label>
                            <textarea v-model="financeForm.source_refs" rows="2" placeholder="来源引用，逗号或换行分隔，例如 pms_capture#101, cost_ledger#202608" class="col-span-2 rounded-lg border p-2 text-xs"></textarea>
                        </div>
                        <label class="mt-3 flex items-start gap-2 text-xs leading-5 text-slate-600"><input v-model="financeForm.operator_attested" type="checkbox" class="mt-0.5">我已人工核对本酒店、当前账期、CNY、税费口径和全部来源引用；这只代表人工核对并完成本地回读，不代表会计审计或平台来源自动验证</label>
                        <button type="submit" :disabled="savingFinance" class="operating-finance-primary-action mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ savingFinance ? '保存并回读中…' : '保存月度快照' }}</button>
                    </form>
                    <div v-else data-testid="operating-finance-view-only" class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">当前账号只有查看权限，不能保存月度经营财务快照。</div>
                </section>

                <section v-if="activeTab === 'portfolio'" class="rounded-2xl border border-slate-200 bg-white p-5" data-testid="operating-finance-portfolio"><div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">多店老板组合视图</h3><span class="rounded-full border px-2 py-1 text-xs">{{ statusText(currentPortfolio.ranking_status || currentPortfolio.status) }}</span></div><div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="text-xs text-slate-400"><tr><th class="px-3 py-2">酒店</th><th class="px-3 py-2">范围</th><th class="px-3 py-2">来源</th><th class="px-3 py-2">税口径</th><th class="px-3 py-2">状态</th><th class="px-3 py-2">GOP</th><th class="px-3 py-2">GOP率</th><th class="px-3 py-2">同口径排名</th></tr></thead><tbody><tr v-for="item in currentPortfolio.items || []" :key="item.hotel_id" class="border-t border-slate-100"><td class="px-3 py-3 font-medium">{{ item.hotel_name }}</td><td class="px-3 py-3">{{ scopeText(item.fact_scope) }}</td><td class="px-3 py-3">{{ statusText(item.source_quality_status) }}</td><td class="px-3 py-3">{{ item.tax_basis === 'tax_inclusive' ? '含税' : (item.tax_basis === 'tax_exclusive' ? '不含税' : '未确认') }}</td><td class="px-3 py-3">{{ statusText(item.status) }}</td><td class="px-3 py-3">{{ money(item.gop) }}</td><td class="px-3 py-3">{{ item.gop_margin_percent ?? '未取得' }}</td><td class="px-3 py-3">{{ item.rank ?? '不可比' }}</td></tr></tbody></table></div><p class="mt-3 text-xs text-slate-500">只有所有授权酒店都具备同账期、全酒店口径、完整成本、CNY、相同含税/不含税口径、同一指标版本且已人工核对来源时才显示人工快照排名；排名不等于会计审计，也不授权员工奖惩或跨店数据外发。</p></section>
            </section>
        `,
    };
})();
