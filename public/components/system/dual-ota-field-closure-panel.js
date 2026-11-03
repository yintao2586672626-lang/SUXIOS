window.SUXI_DUAL_OTA_FIELD_CLOSURE = (() => {
    const CONTRACT_VERSION = 'dual_ota_field_closure_panel.v1';
    const READY_STATUSES = new Set(['strict_readback', 'verified_calculation']);

    const statusText = status => ({
        strict_readback: '已严格回读',
        verified_calculation: '已验证计算',
        source_missing: '来源缺失',
        field_unavailable: '字段未取得',
        readback_failed: '回读未闭合',
        missing: '缺失',
        platform_not_provided: '平台未提供',
        collection_failed: '采集失败',
        login_expired: '登录失效',
        date_mismatch: '日期不符',
        caliber_uncertain: '口径不确定',
    }[String(status || '').trim()] || '状态未确认');

    const statusClass = status => {
        const value = String(status || '').trim();
        if (value === 'strict_readback') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
        if (value === 'verified_calculation') return 'border-blue-200 bg-blue-50 text-blue-800';
        if (value === 'caliber_uncertain') return 'border-amber-200 bg-amber-50 text-amber-900';
        if (['readback_failed', 'collection_failed', 'login_expired', 'date_mismatch'].includes(value)) {
            return 'border-rose-200 bg-rose-50 text-rose-800';
        }
        if (value === 'source_missing') return 'border-orange-200 bg-orange-50 text-orange-800';
        return 'border-slate-200 bg-slate-50 text-slate-700';
    };

    const exactScopeText = status => ({
        verified: '整批精确回读通过',
        exact_run_readback_scope_mismatch: '整批精确回读未闭合',
        not_required: '本次无需整批门禁',
        unverified: '整批精确回读未验证',
    }[String(status || '').trim()] || '整批精确回读未验证');

    const finiteNumber = value => {
        if (value === null || value === undefined || value === '') return null;
        const normalized = typeof value === 'string'
            ? value.replace(/,/g, '').replace(/%$/, '').trim()
            : value;
        const number = Number(normalized);
        return Number.isFinite(number) ? number : null;
    };

    const numericText = (value, unit) => {
        const number = finiteNumber(value);
        if (number === null) return '—';
        if (unit === 'CNY') {
            return `¥${number.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        if (unit === 'percent') {
            return `${number.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
        }
        return number.toLocaleString('zh-CN', {
            minimumFractionDigits: Number.isInteger(number) ? 0 : 2,
            maximumFractionDigits: 2,
        });
    };

    const observedValueText = (row, unit) => {
        const value = numericText(row?.value, unit);
        const basis = String(row?.basis || '').trim();
        const ref = String(row?.source_record_ref || '').trim();
        return [value, basis, ref].filter(Boolean).join(' · ');
    };

    const valueText = field => {
        const status = String(field?.status || '').trim();
        const unit = String(field?.unit || '').trim();
        if (status === 'caliber_uncertain') {
            const observed = Array.isArray(field?.observed_values)
                ? field.observed_values.map(row => observedValueText(row, unit)).filter(Boolean)
                : [];
            return observed.length ? observed.join(' / ') : '—';
        }
        if (!READY_STATUSES.has(status)) return '—';
        if (Array.isArray(field?.value)) {
            const values = field.value.map(value => String(value || '').trim()).filter(Boolean);
            return values.length ? values.join('、') : '—';
        }
        if (unit === 'date' || unit === 'datetime' || unit === 'records') {
            const value = String(field?.value || '').trim();
            return value || '—';
        }
        return numericText(field?.value, unit);
    };

    const sourceRefsText = field => {
        const refs = Array.isArray(field?.source_record_refs)
            ? field.source_record_refs.map(value => String(value || '').trim()).filter(Boolean)
            : [];
        return refs.length ? refs.join('、') : '无正式记录';
    };

    const localRecordRequirementText = code => ({
        record_readback_required: '记录数据库精确回读待验证',
        record_validation_required: '记录质量须为已验证',
        history_finality_required: '历史最终态须为成功',
        current_receipt_binding_required: '须匹配当前已接受回执、来源和任务',
        exact_scope_readback_required: '同酒店、平台、日期整批精确回读待闭合',
    }[code] || '要件未返回，请重新读取');

    const localRecordStatusText = status => ({
        verified: '已验证', unverified: '未验证', unknown: '未知',
        success: '成功', partial: '部分验证', failed: '失败',
        blocked: '阻断', invalid: '无效', mismatch: '不匹配',
        quarantined: '隔离', rejected: '拒绝', rolled_back: '已回滚',
        pending: '待处理', running: '处理中',
    }[status] || '未知');

    const localRecordOriginText = origin => origin?.type === 'manual_import' && origin?.method === 'manual_override'
        ? '人工更正' : ({
        manual_import: '人工录入/导入',
        browser_assisted_import: '页面辅助导入',
        collection_registered: '浏览器/本地采集登记',
        interface_registered: '接口方式登记',
    }[origin?.type] || '未知（来源登记缺失或未识别）');

    const localRecordOriginRecoveryText = origin => ({
        manual_import: '核对原始导入文件或人工记录、平台门店、业务日和字段口径；导入与数据库回读不能替代来源真实性校验',
        browser_assisted_import: '核对页面辅助导入的门店与业务日；页面可见内容不能替代结构化响应及正式来源证据',
        collection_registered: '核对采集回执、任务、Profile/账号、门店与业务日；采集方式登记不能替代同范围证据校验',
        interface_registered: '核对接口来源、门店、业务日与响应证据；接口方式登记不能证明官方来源或真实性',
    }[origin?.type] || '先补齐可核对的来源方式与原始证据；不能猜测为线上采集、人工导入或模拟数据');

    const fieldNoteText = field => {
        const note = String(field?.note || '').trim();
        const marker = note.indexOf('下一步：');
        return (marker >= 0 ? note.slice(0, marker) : note).trim();
    };

    const normalizeRecordRefs = values => Array.isArray(values)
        ? [...new Set(values.map(value => {
            const normalized = String(value ?? '').trim();
            if (!normalized) return '';
            return /^\d+$/.test(normalized)
                ? `online_daily_data#${normalized}`
                : normalized;
        }).filter(Boolean))]
        : [];

    const recordRefsSummary = values => {
        const refs = normalizeRecordRefs(values);
        if (!refs.length) return '0 条';
        if (refs.length === 1) return `1 条（${refs[0]}）`;
        if (refs.length === 2) return `2 条（${refs.join('、')}）`;
        return `${refs.length} 条（${refs[0]}…${refs[refs.length - 1]}）`;
    };

    const platformRows = closure => ['ctrip', 'meituan']
        .map(platform => closure?.platforms?.[platform])
        .filter(row => row && typeof row === 'object');

    const buildVisibleRows = closure => {
        let order = 0;
        return platformRows(closure).flatMap(platform => (
            (Array.isArray(platform.fields) ? platform.fields : []).map(field => {
                order += 1;
                return {
                    order,
                    platform: String(platform.platform || ''),
                    platform_label: String(platform.platform_label || platform.platform || ''),
                    field_key: String(field?.metric_key || field?.key || ''),
                    field_label: String(field?.label || field?.key || ''),
                    display: valueText(field),
                    unit: String(field?.unit || ''),
                    status: String(field?.status || 'source_missing'),
                    status_label: statusText(field?.status),
                    formal_saved: field?.formal_saved === true ? 'true' : 'false',
                    readback_status: String(field?.readback_status || 'not_attempted'),
                    validation_status: String(field?.validation_status || field?.status || 'source_missing'),
                    source_record_refs: (Array.isArray(field?.source_record_refs)
                        ? field.source_record_refs
                        : []).join('、'),
                    data_source_ids: (Array.isArray(field?.data_source_ids)
                        ? field.data_source_ids
                        : []).join('、'),
                    capture_ref: String(field?.capture_ref || ''),
                    endpoint_ids: (Array.isArray(field?.endpoint_ids) ? field.endpoint_ids : []).join('、'),
                    source_paths: (Array.isArray(field?.source_paths) ? field.source_paths : []).join('、'),
                    metric_key: String(field?.metric_key || field?.key || ''),
                    business_date: String(field?.business_date || closure?.business_date || ''),
                    next_action: String(field?.next_action || ''),
                    tenant_id: String(closure?.tenant_id ?? ''),
                    hotel_id: String(closure?.hotel_id ?? ''),
                    closure_identity: String(closure?.page_identity ?? ''),
                    closure_contract_version: String(closure?.contract_version ?? ''),
                    field,
                };
            })
        ));
    };

    const closureCsvCell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;

    const buildClosureDownloadRows = closure => buildVisibleRows(closure).map(({ field, ...row }) => row);

    const buildClosureCsv = closure => {
        const headers = [
            ['order', '顺序'],
            ['platform_label', '平台'],
            ['field_label', '字段'],
            ['field_key', '字段键'],
            ['display', '页面显示'],
            ['unit', '单位'],
            ['status_label', '闭环状态'],
            ['validation_status', '验证状态'],
            ['formal_saved', '是否正式保存'],
            ['readback_status', '回读状态'],
            ['source_record_refs', '正式记录'],
            ['data_source_ids', '数据源ID'],
            ['capture_ref', '采集批次'],
            ['endpoint_ids', '端点'],
            ['source_paths', '字段路径'],
            ['metric_key', '指标键'],
            ['business_date', '业务日期'],
            ['next_action', '下一步'],
            ['tenant_id', '租户ID'],
            ['hotel_id', '酒店ID'],
            ['closure_identity', '字段闭环合同身份'],
            ['closure_contract_version', '字段闭环合同版本'],
        ];
        const rows = buildClosureDownloadRows(closure);
        return `\uFEFF${[
            headers.map(([, label]) => closureCsvCell(label)).join(','),
            ...rows.map(row => headers.map(([key]) => closureCsvCell(row[key])).join(',')),
        ].join('\r\n')}`;
    };

    const buildClosureDownloadPayload = closure => {
        const rows = buildClosureDownloadRows(closure);
        if (!rows.length) {
            return { ok: false, rows, csv: '', fileName: '', message: '当前没有可见字段可下载' };
        }
        const hotelId = Number(closure?.hotel_id || 0);
        const businessDate = String(closure?.business_date || '').trim() || 'unknown-date';
        return {
            ok: true,
            rows,
            csv: buildClosureCsv(closure),
            fileName: `可信OTA事实底座_Hotel${hotelId || 'unknown'}_${businessDate}.csv`,
            message: '已按当前可见字段、状态和顺序生成下载',
        };
    };

    const closureRequestPath = ({ hotelId, businessDate, force = true } = {}) => {
        const normalizedHotelId = Number(hotelId || 0);
        const normalizedDate = String(businessDate || '').trim();
        if (!Number.isInteger(normalizedHotelId) || normalizedHotelId <= 0
            || !/^\d{4}-\d{2}-\d{2}$/.test(normalizedDate)
        ) {
            throw new Error('dual_ota_field_closure_scope_invalid');
        }
        const params = new URLSearchParams({
            hotel_id: String(normalizedHotelId),
            days: '1',
            end_date: normalizedDate,
            mode: 'light',
        });
        if (force === true) params.set('force', '1');
        return `/online-data/collection-reliability?${params.toString()}`;
    };

    const resolveClosureResponse = (response, { hotelId, businessDate } = {}) => {
        const expectedHotelId = Number(hotelId || 0);
        const expectedDate = String(businessDate || '').trim();
        if (!Number.isInteger(expectedHotelId) || expectedHotelId <= 0
            || !/^\d{4}-\d{2}-\d{2}$/.test(expectedDate)) {
            return { ok: false, reason: 'dual_ota_field_closure_scope_mismatch', closure: null };
        }
        const closure = response?.code === 200
            && response?.data
            && typeof response.data === 'object'
            ? response.data.dual_ota_field_closure
            : null;
        if (!closure || typeof closure !== 'object') {
            return { ok: false, reason: response?.message || 'dual_ota_field_closure_missing', closure: null };
        }
        if (closure.status === 'unavailable'
            && closure.reason === 'dual_ota_field_closure_read_failed'
            && Number(closure.hotel_id || 0) === expectedHotelId
            && String(closure.business_date || '') === expectedDate
        ) {
            return { ok: false, reason: closure.reason, closure: null };
        }
        if (closure.contract_version !== 'dual_ota_field_closure.v1'
            || !Number.isInteger(Number(closure.tenant_id))
            || Number(closure.tenant_id) <= 0
            || Number(closure.hotel_id || 0) !== expectedHotelId
            || String(closure.business_date || '') !== expectedDate
            || closure.sensitive_values_exposed === true
        ) {
            return { ok: false, reason: 'dual_ota_field_closure_scope_mismatch', closure: null };
        }
        for (const key of ['ctrip', 'meituan']) {
            const platform = closure.platforms?.[key];
            if (!platform || typeof platform !== 'object' || Array.isArray(platform)
                || (platform.platform !== undefined && platform.platform !== key)
                || (platform.business_date !== undefined && platform.business_date !== expectedDate)
                || (platform.fields !== undefined && !Array.isArray(platform.fields))
                || (platform.fields || []).some(field => !field || typeof field !== 'object' || Array.isArray(field)
                    || (field.tenant_id !== undefined && Number(field.tenant_id) !== Number(closure.tenant_id))
                    || (field.system_hotel_id !== undefined && Number(field.system_hotel_id) !== expectedHotelId)
                    || (field.platform !== undefined && field.platform !== key)
                    || (field.business_date !== undefined && field.business_date !== expectedDate))
            ) {
                return { ok: false, reason: 'dual_ota_field_closure_scope_mismatch', closure: null };
            }
        }
        return { ok: true, reason: '', closure };
    };

    const closureErrorText = reason => ({
        dual_ota_field_closure_scope_mismatch: '字段闭环的租户、酒店、平台或营业日身份不一致，请重新读取。',
        dual_ota_field_closure_missing: '尚未取得当前范围的字段闭环合同，请重新读取。',
        dual_ota_field_closure_read_failed: '当前范围的字段闭环读取失败，请重新读取。',
        'Authentication session changed': '登录会话已更新，请重新读取当前范围的字段闭环。',
    }[String(reason || '')] || String(reason || '字段闭环读取失败'));

    const createPanel = ({ h } = {}) => {
        if (typeof h !== 'function') {
            throw new Error('dual_ota_field_closure_panel_requires_vue_h');
        }
        const badge = (text, className, attrs = {}) => h('span', {
            class: `inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium ${className}`,
            ...attrs,
        }, text);

        return {
            name: 'DualOtaFieldClosurePanel',
            props: {
                closure: { type: Object, default: null },
                surface: { type: String, default: 'data_health' },
                request: { type: Function, default: null },
                hotelId: { type: [String, Number], default: '' },
                businessDate: { type: String, default: '' },
                forceRead: { type: Boolean, default: true },
            },
            data: () => ({
                fetchedClosure: null,
                closureLoading: false,
                closureError: '',
                closureDownloadError: '',
                closureRequestSeq: 0,
                closureRequestScope: '',
                closureExplicitReadScope: '',
            }),
            computed: {
                activeClosure() {
                    if (this.closureLoading || this.closureError) return null;
                    const expectedHotelId = Number(this.hotelId || 0);
                    const expectedDate = String(this.businessDate || '').trim();
                    const candidates = [this.fetchedClosure, this.closure];
                    return candidates.find(candidate => resolveClosureResponse(
                        { code: 200, data: { dual_ota_field_closure: candidate } },
                        { hotelId: expectedHotelId, businessDate: expectedDate },
                    ).ok) || null;
                },
            },
            watch: {
                hotelId() { void this.refreshClosure(); },
                businessDate() { void this.refreshClosure(); },
                closure() { void this.refreshClosure(); },
            },
            mounted() {
                void this.refreshClosure();
            },
            beforeUnmount() {
                this.closureRequestSeq += 1;
            },
            methods: {
                async refreshClosure(options = {}) {
                    this.closureDownloadError = '';
                    const hotelId = Number(this.hotelId || 0);
                    const businessDate = String(this.businessDate || '').trim();
                    const requestScope = `${hotelId}|${businessDate}|${this.forceRead === true ? 'force' : 'normal'}`;
                    if (this.closureExplicitReadScope !== requestScope) {
                        this.closureExplicitReadScope = '';
                    }
                    // A parent update must not cancel a current read or replace
                    // the result/error of the user's explicit same-scope refresh.
                    if (this.closureLoading && this.closureRequestScope === requestScope) {
                        return this.fetchedClosure;
                    }
                    if (options.forceReload !== true && this.closureExplicitReadScope === requestScope) {
                        return this.fetchedClosure;
                    }
                    const parent = resolveClosureResponse(
                        { code: 200, data: { dual_ota_field_closure: this.closure } },
                        { hotelId, businessDate },
                    );
                    if (options.forceReload !== true && parent.ok) {
                        this.closureRequestSeq += 1;
                        this.fetchedClosure = null;
                        this.closureLoading = false;
                        this.closureError = '';
                        this.closureRequestScope = '';
                        return this.closure;
                    }
                    if (typeof this.request !== 'function') {
                        this.closureRequestSeq += 1;
                        this.fetchedClosure = null;
                        this.closureLoading = false;
                        this.closureRequestScope = '';
                        this.closureError = hotelId > 0 && /^\d{4}-\d{2}-\d{2}$/.test(businessDate)
                            ? '字段闭环读取函数未就绪'
                            : '请先确定酒店和营业日';
                        return null;
                    }
                    let path;
                    try {
                        path = closureRequestPath({ hotelId, businessDate, force: this.forceRead === true });
                    } catch (error) {
                        this.closureRequestSeq += 1;
                        this.fetchedClosure = null;
                        this.closureLoading = false;
                        this.closureRequestScope = '';
                        this.closureError = '请先确定酒店和营业日';
                        return null;
                    }
                    const requestSeq = ++this.closureRequestSeq;
                    this.closureRequestScope = requestScope;
                    if (options.forceReload === true) this.closureExplicitReadScope = requestScope;
                    this.closureLoading = true;
                    this.closureError = '';
                    this.fetchedClosure = null;
                    try {
                        const response = await this.request(path, {
                            requestPolicy: {
                                scope: 'session',
                                priority: 'current',
                            },
                        });
                        if (requestSeq !== this.closureRequestSeq) return null;
                        const resolved = resolveClosureResponse(response, { hotelId, businessDate });
                        if (!resolved.ok) {
                            this.fetchedClosure = null;
                            this.closureError = closureErrorText(resolved.reason);
                            return null;
                        }
                        this.fetchedClosure = resolved.closure;
                        this.closureError = '';
                        return resolved.closure;
                    } catch (error) {
                        if (requestSeq !== this.closureRequestSeq) return null;
                        this.fetchedClosure = null;
                        this.closureError = closureErrorText(error?.message);
                        return null;
                    } finally {
                        if (requestSeq === this.closureRequestSeq) {
                            this.closureLoading = false;
                            this.closureRequestScope = '';
                        }
                    }
                },
                downloadClosure() {
                    this.closureDownloadError = '';
                    const closure = this.activeClosure;
                    if (!closure) {
                        return { ok: false, rows: [], csv: '', fileName: '', message: this.closureLoading
                            ? '字段闭环正在读取，请等待当前范围读取完成'
                            : (this.closureError || '当前没有通过范围核验的可见字段可下载') };
                    }
                    const payload = buildClosureDownloadPayload(closure);
                    if (!payload.ok) {
                        this.closureDownloadError = payload.message;
                        return payload;
                    }
                    let anchor = null;
                    let url = '';
                    const cleanup = () => {
                        if (anchor?.isConnected) anchor.remove();
                        if (url) URL.revokeObjectURL(url);
                    };
                    try {
                        url = URL.createObjectURL(new Blob([payload.csv], { type: 'text/csv;charset=utf-8' }));
                        anchor = document.createElement('a');
                        anchor.href = url;
                        anchor.download = payload.fileName;
                        document.body.appendChild(anchor);
                        anchor.click();
                        // Match the app's existing downloadBlob lifetime for embedded browsers.
                        window.setTimeout(cleanup, 60000);
                        return payload;
                    } catch (error) {
                        cleanup();
                        this.closureDownloadError = 'CSV下载未能启动，请重试。';
                        return { ...payload, ok: false, message: this.closureDownloadError };
                    }
                },
            },
            render() {
                const candidate = this.activeClosure;
                const closure = candidate && typeof candidate === 'object'
                    ? candidate
                    : null;
                const testId = `dual-ota-field-closure-${String(this.surface || 'unknown')}`;
                const refreshControl = h('button', {
                    type: 'button',
                    class: 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 disabled:cursor-wait disabled:opacity-50',
                    'data-testid': `dual-ota-field-refresh-${this.surface}`,
                    disabled: this.closureLoading || typeof this.request !== 'function'
                        || !Number.isInteger(Number(this.hotelId)) || !(Number(this.hotelId) > 0)
                        || !/^\d{4}-\d{2}-\d{2}$/.test(String(this.businessDate || '').trim()),
                    onClick: () => this.refreshClosure({ forceReload: true }),
                }, this.closureLoading ? '读取中…' : (this.closureError ? '重新读取字段' : '刷新字段事实'));
                if (!closure || !Array.isArray(platformRows(closure)) || platformRows(closure).length === 0) {
                    return h('section', {
                        class: 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm',
                        'data-testid': testId,
                        'data-read-status': this.closureLoading ? 'loading' : (this.closureError ? 'error' : 'not_loaded'),
                    }, [
                        h('h3', { class: 'text-base font-semibold text-slate-900' }, '携程＋美团真实字段闭环'),
                        h('p', { class: 'mt-2 text-sm text-slate-500' }, this.closureLoading
                            ? '正在读取同酒店、同平台、同营业日的字段闭环合同…'
                            : (this.closureError || '尚未读取到同酒店、同平台、同营业日的字段闭环合同。')),
                        refreshControl,
                    ]);
                }

                const identity = String(closure.page_identity || '').trim();
                const businessDate = String(closure.business_date || '').trim() || '日期未返回';
                const parsedConsumableCount = finiteNumber(closure.revenue_analysis_consumable_field_count);
                const consumableCount = parsedConsumableCount !== null && parsedConsumableCount >= 0
                    ? parsedConsumableCount
                    : null;
                const visibleRows = buildVisibleRows(closure);
                const csvPreview = visibleRows.length ? buildClosureDownloadPayload(closure) : null;
                const platformSections = platformRows(closure).map(platform => {
                    const collection = platform.latest_collection || {};
                    const localRecords = closure.local_source_records?.[platform.platform];
                    const localDiagnosticsReady = localRecords?.diagnostic_only === true
                        && Number.isInteger(localRecords.record_count)
                        && localRecords.record_count >= 0
                        && Array.isArray(localRecords.records)
                        && localRecords.records.length === localRecords.record_count
                        && localRecords.state === (localRecords.record_count ? 'local_records_present' : 'no_local_records');
                    const eligibleRecordRefs = normalizeRecordRefs(platform.current_receipt_record_refs);
                    const projectedAllRecordRefs = normalizeRecordRefs(platform.current_receipt_all_record_refs);
                    const receiptRecordRefs = normalizeRecordRefs(collection.receipt_record_ids);
                    const allRecordRefs = projectedAllRecordRefs.length
                        ? projectedAllRecordRefs
                        : (receiptRecordRefs.length ? receiptRecordRefs : eligibleRecordRefs);
                    const nonEligibleRecordRefs = normalizeRecordRefs(
                        platform.current_receipt_non_eligible_record_refs
                    );
                    const semanticVetoRefs = Array.isArray(platform.semantic_veto_record_refs)
                        ? platform.semantic_veto_record_refs.join('、')
                        : '';
                    const rows = visibleRows.filter(row => row.platform === platform.platform).map(row => {
                        const field = row.field;
                        return h('tr', {
                        key: `${platform.platform}:${field.key}`,
                        class: 'border-t border-slate-100 align-top',
                        'data-testid': `dual-ota-field-row-${this.surface}-${platform.platform}-${field.key}`,
                        'data-field-key': String(field.key || ''),
                        'data-source-record-ids': Array.isArray(field.source_record_ids)
                            ? field.source_record_ids.join(',')
                            : '',
                        }, [
                        h('td', { class: 'whitespace-nowrap px-3 py-3' }, [
                            h('div', { class: 'font-medium text-slate-900' }, String(field.label || field.key || '字段')),
                            h('div', { class: 'mt-0.5 text-[10px] text-slate-400' }, String(field.key || '')),
                        ]),
                        h('td', { class: 'min-w-[150px] px-3 py-3 font-mono text-xs font-semibold text-slate-900' }, row.display),
                        h('td', { class: 'whitespace-nowrap px-3 py-3' }, badge(
                            row.status_label,
                            statusClass(field.status),
                            { 'data-testid': `dual-ota-field-status-${this.surface}-${platform.platform}-${field.key}` }
                        )),
                        h('td', { class: 'min-w-[150px] px-3 py-3 text-xs' }, [
                            field.revenue_analysis_consumable === true
                                ? badge('收益可消费', 'border-emerald-200 bg-emerald-50 text-emerald-800')
                                : badge('收益已阻断', 'border-slate-200 bg-slate-50 text-slate-600'),
                            h('div', { class: 'mt-1 text-[10px] leading-4 text-slate-500' }, field.current_receipt_binding_verified === true
                                ? '已绑定当前回执记录'
                                : (Array.isArray(field.source_record_ids) && field.source_record_ids.length
                                    ? '跨回执证据仅用于阻断'
                                    : '无当前回执记录')),
                            h('div', { class: 'text-[10px] leading-4 text-slate-500' }, field.exact_run_scope_verified === true
                                ? '整批精确回读已通过'
                                : '整批精确回读未闭合'),
                        ]),
                        h('td', { class: 'min-w-[180px] px-3 py-3 text-[11px] leading-5 text-slate-600' }, sourceRefsText(field)),
                        h('td', { class: 'min-w-[260px] px-3 py-3 text-[11px] leading-5 text-slate-600' }, [
                            h('div', { class: 'font-medium text-slate-700' }, String(field.basis || '未声明口径')),
                            h('div', { class: 'mt-1 text-slate-500' }, fieldNoteText(field)),
                            Array.isArray(field.quality_flags) && field.quality_flags.length
                                ? h('div', { class: 'mt-1 text-amber-700' }, `校验：${field.quality_flags.join('、')}`)
                                : null,
                            field.next_action
                                ? h('div', { class: 'mt-1 font-medium text-slate-700' }, `下一步：${field.next_action}`)
                                : null,
                        ]),
                        ]);
                    });

                    return h('article', {
                        key: platform.platform,
                        class: 'overflow-hidden rounded-xl border border-slate-200 bg-white',
                        'data-testid': `dual-ota-field-platform-${this.surface}-${platform.platform}`,
                    }, [
                        h('div', { class: 'flex flex-col gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 md:flex-row md:items-center md:justify-between' }, [
                            h('div', null, [
                                h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                    h('h4', { class: 'text-sm font-semibold text-slate-900' }, `${platform.platform_label || platform.platform}字段闭环表`),
                                    badge(platform.identity_status === 'verified' ? '身份一致' : '身份待核', platform.identity_status === 'verified'
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                        : 'border-amber-200 bg-amber-50 text-amber-800'),
                                    badge(platform.revenue_analysis?.status === 'ready' ? '收益可消费' : '收益门禁阻断', platform.revenue_analysis?.status === 'ready'
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                        : 'border-amber-200 bg-amber-50 text-amber-900'),
                                ]),
                                h('p', { class: 'mt-1 text-[11px] leading-5 text-slate-500' }, `最新采集：${collection.sync_task_status || collection.status || '未返回'} · P0 ${collection.p0_status || '未返回'} · 整批正式回读 ${recordRefsSummary(allRecordRefs)} · 字段可用 ${recordRefsSummary(eligibleRecordRefs)}`),
                                nonEligibleRecordRefs.length
                                    ? h('p', { class: 'mt-1 text-[10px] leading-4 text-amber-700' }, `当前回执中校验隔离或字段不可用：${recordRefsSummary(nonEligibleRecordRefs)}（保留追溯，不作为字段事实消费）`)
                                    : null,
                                semanticVetoRefs
                                    ? h('p', { class: 'mt-1 text-[10px] leading-4 text-amber-700' }, `跨回执口径否决证据：${semanticVetoRefs}（只阻断，不替代当前值）`)
                                    : null,
                            ]),
                            h('div', { class: 'text-[11px] leading-5 text-slate-500' }, [
                                h('div', null, `数据源 #${collection.data_source_id || '—'} · 同步任务 #${collection.sync_task_id || '—'}`),
                                h('div', null, `目标日 ${collection.target_date_status === 'matched' ? '一致' : (collection.target_date_status || '未验证')} · ${exactScopeText(collection.exact_run_readback_status)}`),
                            ]),
                        ]),
                        h('div', {
                            class: 'border-b border-slate-200 bg-amber-50/50 px-4 py-3 text-xs leading-5 text-slate-700',
                            'data-testid': `dual-ota-local-records-${this.surface}-${platform.platform}`,
                        }, [
                            h('p', { class: 'font-semibold' }, localDiagnosticsReady
                                ? (localRecords.record_count ? `当前范围本地记录 ${localRecords.record_count} 条（仅质量诊断）` : '当前范围本地记录 0 条（读取成功，无本地记录）')
                                : '当前范围本地记录诊断未返回，数量与质量未知，请重新读取'),
                            ...(localDiagnosticsReady ? localRecords.records.map(record => h('div', { key: record.record_ref }, [
                                h('p', null, `${record.record_ref || '记录引用未知'} · 来源登记：${localRecordOriginText(record.source_origin)} · 质量：${localRecordStatusText(record.validation_status)} · 历史：${localRecordStatusText(record.history_status)} · 数据库回读：${localRecordStatusText(record.readback_status)} · ${Array.isArray(record.requirements) && record.requirements.length
                                    ? record.requirements.map(localRecordRequirementText).join('；') : '记录元数据要件已满足，字段口径及来源身份仍以正式闭环门禁为准'}`),
                                h('p', { class: 'mt-1', 'data-testid': `dual-ota-origin-recovery-${this.surface}-${platform.platform}` }, `只读补证：${localRecordOriginRecoveryText(record.source_origin)}。先保留未验证状态，不手动改质量；可用下方刷新字段事实重新读取。`),
                            ])) : []),
                            h('p', { class: 'mt-1' }, '本地记录存在不代表字段事实可用。正式放行还须账号/Profile/门店身份一致、当前已接受回执与整批范围一致、历史成功、质量已验证和数据库回读通过。'),
                        ]),
                        h('div', { class: 'overflow-x-auto' }, [
                            h('table', { class: 'min-w-full text-left' }, [
                                h('thead', { class: 'bg-white text-[11px] font-medium uppercase tracking-wide text-slate-500' }, [
                                    h('tr', null, ['字段', '值', '闭环状态', '收益消费', '来源记录', '口径与说明'].map(label =>
                                        h('th', { class: 'px-3 py-2.5' }, label)
                                    )),
                                ]),
                                h('tbody', null, rows),
                            ]),
                        ]),
                    ]);
                });

                return h('section', {
                    class: 'rounded-2xl border border-[#dcc591]/50 bg-white p-4 shadow-sm md:p-5',
                    'data-testid': testId,
                    'data-closure-identity': identity,
                    'data-business-date': businessDate,
                    'data-read-status': 'ready',
                }, [
                    h('div', { class: 'mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between' }, [
                        h('div', null, [
                            h('div', { class: 'flex flex-wrap items-center gap-2' }, [
                                h('h3', { class: 'text-base font-semibold text-[#06110d]' }, '携程＋美团真实字段闭环'),
                                badge(closure.status === 'ready' ? '完整可消费' : '部分闭环', closure.status === 'ready'
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    : 'border-amber-200 bg-amber-50 text-amber-900'),
                            ]),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-600' }, '同一合同同时供数据健康页与收益驾驶舱使用；缺失、失败、错日期和口径不确定不会显示成 0。'),
                            h('p', { class: 'mt-1 text-xs leading-5 text-slate-600', 'data-testid': `dual-ota-readonly-recovery-${this.surface}` }, '刷新字段事实只重新读取当前范围记录，不发起采集、不改变质量状态；采集权限拒绝时仍可只读查看。读取失败请重新读取，登录或门店访问权限拒绝须先恢复授权。'),
                        ]),
                        h('div', { class: 'flex flex-col items-stretch gap-2 sm:items-end' }, [
                            h('div', { class: 'rounded-lg border border-[#dcc591]/40 bg-[#06110d] px-3 py-2 text-[11px] leading-5 text-slate-100' }, [
                                h('div', { class: 'font-semibold text-[#dcc591]' }, `${businessDate} · Hotel #${closure.hotel_id || '—'}`),
                                h('div', null, `合同 ${identity || '未生成'} · 收益可消费字段 ${consumableCount === null ? '—' : consumableCount}`),
                            ]),
                            refreshControl,
                            h('button', {
                                type: 'button',
                                class: 'rounded-lg border border-[#a88a52] bg-[#fffaf0] px-3 py-2 text-xs font-semibold text-[#6f572f] transition hover:bg-[#f8edcf]',
                                disabled: visibleRows.length === 0,
                                'data-testid': `dual-ota-field-download-${this.surface}`,
                                onClick: this.downloadClosure,
                            }, '按当前字段与顺序下载 CSV'),
                            this.closureDownloadError ? h('p', {
                                role: 'alert',
                                class: 'text-xs text-red-700',
                                'data-testid': `dual-ota-field-download-error-${this.surface}`,
                            }, this.closureDownloadError) : null,
                        ]),
                    ]),
                    closure.supplemental_evidence_read?.status === 'failed'
                        ? h('p', {
                            role: 'alert',
                            class: 'mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900',
                            'data-testid': `dual-ota-supplemental-read-error-${this.surface}`,
                        }, '当前范围的补证记录读取失败。本地来源元数据已保留，收益消费仍被阻断；请刷新字段事实重新读取补证。')
                        : null,
                    csvPreview?.ok ? h('details', { class: 'mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3' }, [
                        h('summary', { class: 'cursor-pointer text-xs font-medium text-slate-600' }, '下载未弹出？查看 CSV 文本'),
                        h('p', { class: 'mt-2 text-xs leading-5 text-slate-600' }, `可选中下方全部文本，复制并以 UTF-8 保存为 ${csvPreview.fileName}；内容与当前字段及顺序一致。`),
                        h('textarea', {
                            readOnly: true,
                            rows: 6,
                            spellcheck: false,
                            class: 'mt-2 w-full rounded-lg border border-slate-300 bg-white p-2 font-mono text-xs text-slate-700',
                            'aria-label': '当前字段 CSV 文本',
                            'data-testid': `dual-ota-field-csv-text-${this.surface}`,
                            value: csvPreview.csv,
                        }),
                    ]) : null,
                    h('div', { class: 'space-y-4' }, platformSections),
                ]);
            },
        };
    };

    return {
        contractVersion: CONTRACT_VERSION,
        statusText,
        statusClass,
        exactScopeText,
        valueText,
        sourceRefsText,
        fieldNoteText,
        normalizeRecordRefs,
        recordRefsSummary,
        platformRows,
        buildVisibleRows,
        buildClosureDownloadRows,
        buildClosureCsv,
        buildClosureDownloadPayload,
        closureRequestPath,
        resolveClosureResponse,
        createPanel,
    };
})();

if (window.Vue?.h && window.SUXI_DUAL_OTA_FIELD_CLOSURE?.createPanel) {
    const systemComponents = window.SUXI_SYSTEM_COMPONENTS
        || (window.SUXI_SYSTEM_COMPONENTS = {});
    systemComponents.DualOtaFieldClosurePanel =
        window.SUXI_DUAL_OTA_FIELD_CLOSURE.createPanel({ h: window.Vue.h });
}
