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
        const closure = response?.code === 200
            && response?.data
            && typeof response.data === 'object'
            ? response.data.dual_ota_field_closure
            : null;
        if (!closure || typeof closure !== 'object') {
            return { ok: false, reason: response?.message || 'dual_ota_field_closure_missing', closure: null };
        }
        if (closure.contract_version !== 'dual_ota_field_closure.v1'
            || Number(closure.hotel_id || 0) !== expectedHotelId
            || String(closure.business_date || '') !== expectedDate
            || !closure.platforms?.ctrip
            || !closure.platforms?.meituan
            || closure.sensitive_values_exposed === true
        ) {
            return { ok: false, reason: 'dual_ota_field_closure_scope_mismatch', closure: null };
        }
        return { ok: true, reason: '', closure };
    };

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
                closureRequestSeq: 0,
                closureRequestScope: '',
            }),
            computed: {
                activeClosure() {
                    const expectedHotelId = Number(this.hotelId || this.closure?.hotel_id || 0);
                    const expectedDate = String(this.businessDate || this.closure?.business_date || '').trim();
                    const candidates = [this.fetchedClosure, this.closure];
                    return candidates.find(candidate => candidate
                        && typeof candidate === 'object'
                        && Number(candidate.hotel_id || 0) === expectedHotelId
                        && String(candidate.business_date || '') === expectedDate
                    ) || null;
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
                async refreshClosure() {
                    const hotelId = Number(this.hotelId || this.closure?.hotel_id || 0);
                    const businessDate = String(this.businessDate || this.closure?.business_date || '').trim();
                    if (this.closure
                        && Number(this.closure.hotel_id || 0) === hotelId
                        && String(this.closure.business_date || '') === businessDate
                    ) {
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
                    const requestScope = `${hotelId}|${businessDate}|${this.forceRead === true ? 'force' : 'normal'}`;
                    if (this.closureLoading && this.closureRequestScope === requestScope) {
                        return this.fetchedClosure;
                    }
                    const requestSeq = ++this.closureRequestSeq;
                    this.closureRequestScope = requestScope;
                    this.closureLoading = true;
                    this.closureError = '';
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
                            this.closureError = resolved.reason || '字段闭环读取失败';
                            return null;
                        }
                        this.fetchedClosure = resolved.closure;
                        return resolved.closure;
                    } catch (error) {
                        if (requestSeq !== this.closureRequestSeq) return null;
                        this.fetchedClosure = null;
                        this.closureError = error?.message || '字段闭环读取失败';
                        return null;
                    } finally {
                        if (requestSeq === this.closureRequestSeq) {
                            this.closureLoading = false;
                            this.closureRequestScope = '';
                        }
                    }
                },
                downloadClosure() {
                    const closure = this.activeClosure || this.closure;
                    const payload = buildClosureDownloadPayload(closure);
                    if (!payload.ok) {
                        this.closureError = payload.message;
                        return payload;
                    }
                    const anchor = document.createElement('a');
                    anchor.href = `data:text/csv;charset=utf-8,${encodeURIComponent(payload.csv)}`;
                    anchor.download = payload.fileName;
                    document.body.appendChild(anchor);
                    anchor.click();
                    window.setTimeout(() => {
                        anchor.remove();
                    }, 5000);
                    return payload;
                },
            },
            render() {
                const candidate = this.activeClosure;
                const closure = candidate && typeof candidate === 'object'
                    ? candidate
                    : null;
                const testId = `dual-ota-field-closure-${String(this.surface || 'unknown')}`;
                if (!closure || !Array.isArray(platformRows(closure)) || platformRows(closure).length === 0) {
                    return h('section', {
                        class: 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm',
                        'data-testid': testId,
                    }, [
                        h('h3', { class: 'text-base font-semibold text-slate-900' }, '携程＋美团真实字段闭环'),
                        h('p', { class: 'mt-2 text-sm text-slate-500' }, this.closureLoading
                            ? '正在读取同酒店、同平台、同营业日的字段闭环合同…'
                            : (this.closureError || '尚未读取到同酒店、同平台、同营业日的字段闭环合同。')),
                    ]);
                }

                const identity = String(closure.page_identity || '').trim();
                const businessDate = String(closure.business_date || '').trim() || '日期未返回';
                const parsedConsumableCount = finiteNumber(closure.revenue_analysis_consumable_field_count);
                const consumableCount = parsedConsumableCount !== null && parsedConsumableCount >= 0
                    ? parsedConsumableCount
                    : null;
                const visibleRows = buildVisibleRows(closure);
                const platformSections = platformRows(closure).map(platform => {
                    const collection = platform.latest_collection || {};
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
                        ]),
                        h('div', { class: 'flex flex-col items-stretch gap-2 sm:items-end' }, [
                            h('div', { class: 'rounded-lg border border-[#dcc591]/40 bg-[#06110d] px-3 py-2 text-[11px] leading-5 text-slate-100' }, [
                                h('div', { class: 'font-semibold text-[#dcc591]' }, `${businessDate} · Hotel #${closure.hotel_id || '—'}`),
                                h('div', null, `合同 ${identity || '未生成'} · 收益可消费字段 ${consumableCount === null ? '—' : consumableCount}`),
                            ]),
                            h('button', {
                                type: 'button',
                                class: 'rounded-lg border border-[#a88a52] bg-[#fffaf0] px-3 py-2 text-xs font-semibold text-[#6f572f] transition hover:bg-[#f8edcf]',
                                'data-testid': `dual-ota-field-download-${this.surface}`,
                                onClick: this.downloadClosure,
                            }, '按当前字段与顺序下载 CSV'),
                        ]),
                    ]),
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
