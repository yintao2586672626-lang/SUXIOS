window.SUXI_OPERATING_GROWTH_STATIC = (() => {
    const EVENT_TYPES = [
        { key: 'all', label: '全部' },
        { key: 'fact', label: '事实' },
        { key: 'analysis', label: '分析' },
        { key: 'owner_judgment', label: '老板判断' },
        { key: 'decision', label: '经营决策' },
        { key: 'execution', label: '执行动作' },
        { key: 'review', label: '效果复盘' },
        { key: 'milestone', label: '里程碑' },
    ];

    const EVENT_TYPE_LABELS = Object.fromEntries(EVENT_TYPES.map(item => [item.key, item.label]));
    const QUALITY_LABELS = {
        verified: '已核验',
        partial: '部分核验',
        unverified: '未核验',
        conflict: '存在冲突',
        invalid: '已失效',
        failed: '读取失败',
        missing: '可信状态未取得',
    };
    const QUALITY_CLASSES = {
        verified: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        partial: 'border-amber-200 bg-amber-50 text-amber-700',
        unverified: 'border-slate-200 bg-slate-50 text-slate-600',
        conflict: 'border-rose-200 bg-rose-50 text-rose-700',
        invalid: 'border-slate-200 bg-slate-100 text-slate-500',
        failed: 'border-red-200 bg-red-50 text-red-700',
        missing: 'border-slate-200 bg-slate-50 text-slate-500',
    };
    const TYPE_CLASSES = {
        fact: 'border-sky-200 bg-sky-50 text-sky-700',
        analysis: 'border-violet-200 bg-violet-50 text-violet-700',
        owner_judgment: 'border-amber-200 bg-amber-50 text-amber-800',
        decision: 'border-indigo-200 bg-indigo-50 text-indigo-700',
        execution: 'border-cyan-200 bg-cyan-50 text-cyan-700',
        review: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        milestone: 'border-yellow-300 bg-yellow-50 text-yellow-800',
    };

    const hasText = value => String(value ?? '').trim() !== '';
    const firstText = (...values) => values.map(value => String(value ?? '').trim()).find(Boolean) || '';
    const normalizedId = value => String(value ?? '').trim();
    const finiteNonNegative = value => Number.isFinite(Number(value)) && Number(value) >= 0;

    const normalizeGrowthMetric = (metric, label) => {
        const safeMetric = metric && typeof metric === 'object' ? metric : {};
        const available = safeMetric.available === true || Number(safeMetric.evidence_count || 0) > 0;
        const failed = safeMetric.status === 'failed' || safeMetric.error === true;
        if (failed) {
            return { label, valueText: '读取失败', available: false, status: 'failed' };
        }
        if (!available || !finiteNonNegative(safeMetric.value)) {
            return { label, valueText: '未取得', available: false, status: 'missing' };
        }
        return {
            label,
            valueText: String(Number(safeMetric.value)),
            available: true,
            status: 'ready',
        };
    };

    const normalizeGrowthEvent = (record = {}, selectedHotelId = '') => {
        const hotelId = normalizedId(record.hotel_id ?? record.system_hotel_id);
        const rawType = firstText(record.type, record.event_kind);
        const type = ({ judgement: 'owner_judgment', execution_review: 'review' }[rawType] || rawType);
        const rawQuality = firstText(record.quality_status);
        const quality = ({ conflicted: 'conflict', expired: 'invalid' }[rawQuality] || rawQuality);
        const evidenceCountValue = record.evidence_count ?? record.context?.evidence_count
            ?? (Array.isArray(record.evidence_refs) ? record.evidence_refs.length : null);
        const evidenceCountKnown = finiteNonNegative(evidenceCountValue)
            && (evidenceCountValue !== '' && evidenceCountValue !== null && evidenceCountValue !== undefined);
        const sourceRecordId = Number(record.source_reference?.record_id ?? record.source_record_id ?? 0);
        const sourceRecordType = firstText(record.source_reference?.record_type, record.source_record_type);
        const sourceRef = firstText(
            record.source_ref,
            record.source_id,
            record.source_url,
            sourceRecordId > 0 ? `${sourceRecordType || '来源记录'} #${sourceRecordId}` : ''
        );
        const occurredAt = firstText(record.occurred_at);
        const businessDate = firstText(record.business_date, record.date);
        const usageLevel = firstText(record.usage_level);
        const isMilestone = record.is_milestone === true || type === 'milestone';
        return {
            id: normalizedId(record.id),
            hotelId,
            hotelName: firstText(record.hotel_name) || '酒店名称未取得',
            hotelMatches: hasText(selectedHotelId) && hotelId === normalizedId(selectedHotelId),
            date: businessDate || (occurredAt ? occurredAt.slice(0, 10) : '日期未取得'),
            occurredAt,
            dateTimeLabel: occurredAt || businessDate || '发生时间未取得',
            type: type || 'unknown',
            typeLabel: EVENT_TYPE_LABELS[type] || '事件类型未取得',
            typeClass: TYPE_CLASSES[type] || 'border-slate-200 bg-slate-50 text-slate-600',
            title: firstText(record.title) || '标题未取得',
            summary: firstText(record.summary, record.fact_description) || '摘要未取得',
            ownerJudgment: firstText(
                record.owner_judgment,
                record.owner_judgement,
                record.context?.owner_judgement,
                record.is_owner_annotation ? record.summary : ''
            ),
            result: firstText(record.result_summary),
            scopeLabel: firstText(record.scope_label, record.data_scope, record.source_scope, record.platform) || '平台/范围未取得',
            sourceModule: firstText(record.source_reference?.module, record.source_module) || '来源模块未取得',
            sourceRef,
            sourceAvailable: hasText(sourceRef),
            quality,
            qualityLabel: QUALITY_LABELS[quality] || QUALITY_LABELS.missing,
            qualityClass: QUALITY_CLASSES[quality] || QUALITY_CLASSES.missing,
            evidenceCount: evidenceCountKnown ? Number(evidenceCountValue) : null,
            evidenceText: evidenceCountKnown ? `证据 ${Number(evidenceCountValue)} 条` : '证据数未取得',
            usageLevel,
            usageLabel: usageLevel === 'archive_only' ? '仅用于档案' : '',
            isMilestone,
            canAnnotate: hasText(record.id) && record.permissions?.can_annotate !== false,
            canSetMilestone: hasText(record.id) && record.permissions?.can_set_milestone !== false,
            raw: record,
        };
    };

    const buildOperatingGrowthArchiveModel = ({
        hotel = {},
        selectedHotelId = '',
        hotelOptions = [],
        dateRangeKey = '90d',
        dateRangeLabel = '近 90 天',
        rangeOptions = [],
        records = [],
        summary = {},
        activeType = 'all',
        loading = false,
        error = '',
        dataStatus = '',
        gaps = [],
        migrationReady = true,
        canRead = true,
        permissions = {},
        lastReadAt = '',
    } = {}) => {
        const scopedHotelId = normalizedId(selectedHotelId || hotel.id);
        const scopedHotelName = firstText(hotel.name) || (scopedHotelId ? `酒店 #${scopedHotelId}（名称未取得）` : '酒店未选择');
        const safeRecords = Array.isArray(records) ? records : [];
        const normalizedRecords = safeRecords.map(record => normalizeGrowthEvent(record, scopedHotelId));
        const rejectedIdentityCount = normalizedRecords.filter(record => !record.hotelMatches).length;
        const acceptedRecords = normalizedRecords.filter(record => record.hotelMatches);
        const safeActiveType = EVENT_TYPE_LABELS[activeType] ? activeType : 'all';
        const visibleRecords = acceptedRecords.filter(record => (
            safeActiveType === 'all'
            || record.type === safeActiveType
            || (safeActiveType === 'milestone' && record.isMilestone)
        ));
        const explicitStatus = String(dataStatus || '').trim().toLowerCase();
        const hasReadback = ['ok', 'ready', 'partial', 'empty'].includes(explicitStatus);
        let stateCode = 'waiting';
        let stateLabel = '读取状态未取得';
        let notice = '尚未取得本次档案读取结果，不能显示为暂无档案。';

        if (!canRead || explicitStatus === 'permission_denied') {
            stateCode = 'permission_denied';
            stateLabel = '无查看权限';
            notice = '当前账号没有这家酒店经营档案的查看权限。';
        } else if (!migrationReady || explicitStatus === 'migration_missing') {
            stateCode = 'migration_missing';
            stateLabel = '功能尚未启用';
            notice = '经营档案所需的数据迁移尚未完成，当前不是暂无档案。';
        } else if (!scopedHotelId) {
            stateCode = 'hotel_missing';
            stateLabel = '酒店未选择';
            notice = '请先明确酒店范围，再读取经营档案。';
        } else if (hasText(error)) {
            stateCode = acceptedRecords.length ? 'refresh_failed' : 'failed';
            stateLabel = acceptedRecords.length ? '刷新失败' : '读取失败';
            notice = acceptedRecords.length
                ? `刷新失败：${String(error).trim()}。下方仅保留上次成功回读，不能视为当前最新状态。`
                : `读取失败：${String(error).trim()}。`;
        } else if (loading) {
            stateCode = acceptedRecords.length ? 'refreshing' : 'loading';
            stateLabel = acceptedRecords.length ? '正在刷新' : '正在读取';
            notice = acceptedRecords.length ? '正在刷新；下方为上次成功回读。' : '正在读取经营档案。';
        } else if (rejectedIdentityCount > 0 && acceptedRecords.length === 0) {
            stateCode = 'identity_mismatch';
            stateLabel = '酒店身份不一致';
            notice = `已拒绝 ${rejectedIdentityCount} 条酒店身份缺失或不一致的档案，未展示任何跨酒店记录。`;
        } else if (explicitStatus === 'partial' || rejectedIdentityCount > 0 || (Array.isArray(gaps) && gaps.length > 0)) {
            stateCode = 'partial';
            stateLabel = '部分数据';
            notice = rejectedIdentityCount > 0
                ? `已拒绝 ${rejectedIdentityCount} 条酒店身份缺失或不一致的档案；其余记录按当前证据展示。`
                : '部分档案、来源或证据未完整返回；已显示内容仍按各自可信状态呈现。';
        } else if (hasReadback && acceptedRecords.length === 0) {
            stateCode = 'empty';
            stateLabel = '暂无档案';
            notice = '本次酒店与时间范围已完成读取，但没有匹配的经营档案。';
        } else if (hasReadback) {
            stateCode = 'ready';
            stateLabel = `已读取 ${acceptedRecords.length} 条`;
            notice = '';
        }

        const metrics = [
            normalizeGrowthMetric(summary.archive_count, '经营事件'),
            normalizeGrowthMetric(summary.reviewed_count, '已完成复盘'),
            normalizeGrowthMetric(summary.observing_count, '仍在观察'),
            normalizeGrowthMetric(summary.repeated_issue_count, '反复问题'),
        ];
        const stateClass = {
            ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            empty: 'border-slate-200 bg-slate-50 text-slate-600',
            loading: 'border-sky-200 bg-sky-50 text-sky-700',
            refreshing: 'border-sky-200 bg-sky-50 text-sky-700',
            partial: 'border-amber-200 bg-amber-50 text-amber-800',
            waiting: 'border-slate-200 bg-slate-50 text-slate-600',
            failed: 'border-red-200 bg-red-50 text-red-700',
            refresh_failed: 'border-red-200 bg-red-50 text-red-700',
            migration_missing: 'border-amber-200 bg-amber-50 text-amber-800',
            permission_denied: 'border-red-200 bg-red-50 text-red-700',
            hotel_missing: 'border-slate-200 bg-slate-50 text-slate-600',
            identity_mismatch: 'border-red-200 bg-red-50 text-red-700',
        }[stateCode] || 'border-slate-200 bg-slate-50 text-slate-600';

        return {
            scopeHotelId: scopedHotelId,
            scopeHotelName: scopedHotelName,
            hotelOptions: Array.isArray(hotelOptions) ? hotelOptions : [],
            dateRangeKey: String(dateRangeKey || ''),
            dateRangeLabel: firstText(dateRangeLabel) || '时间范围未取得',
            rangeOptions: Array.isArray(rangeOptions) ? rangeOptions : [],
            activeType: safeActiveType,
            eventTypes: EVENT_TYPES,
            records: acceptedRecords,
            visibleRecords,
            rejectedIdentityCount,
            metrics,
            stateCode,
            stateLabel,
            stateClass,
            notice,
            lastReadAt: firstText(lastReadAt),
            canCreate: permissions.can_create !== false && canRead && migrationReady && Boolean(scopedHotelId),
            canOpenSource: permissions.can_open_source !== false,
            isInitialBlocking: ['permission_denied', 'migration_missing', 'hotel_missing', 'failed', 'loading', 'identity_mismatch', 'waiting'].includes(stateCode),
            isEmpty: stateCode === 'empty',
        };
    };

    const surfaceStyle = {
        background: '#ffffff',
        border: '1px solid #e2e8f0',
        borderRadius: '18px',
        boxShadow: '0 12px 28px rgba(15, 23, 42, 0.06)',
    };
    const responsiveGridStyle = {
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 220px), 1fr))',
        gap: '12px',
        minWidth: 0,
    };
    const pill = (h, label, className, extra = {}) => h('span', {
        class: ['inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium', className],
        ...extra,
    }, label);

    const OperatingGrowthArchive = {
        name: 'OperatingGrowthArchive',
        props: {
            model: { type: Object, default: () => ({}) },
            showEventForm: { type: Boolean, default: false },
            eventDraft: { type: Object, default: () => ({}) },
            saving: { type: Boolean, default: false },
            busyActionId: { type: [String, Number], default: '' },
        },
        emits: [
            'refresh',
            'change-hotel',
            'change-date-range',
            'change-filter',
            'open-event-form',
            'close-event-form',
            'update:eventDraft',
            'submit-event',
            'open-source',
            'add-note',
            'set-milestone',
        ],
        render() {
            const h = window.Vue?.h;
            if (typeof h !== 'function') return null;
            const model = this.model || {};
            const records = Array.isArray(model.visibleRecords) ? model.visibleRecords : [];
            const metrics = Array.isArray(model.metrics) ? model.metrics : [];
            const rangeOptions = Array.isArray(model.rangeOptions) ? model.rangeOptions : [];
            const hotelOptions = Array.isArray(model.hotelOptions) ? model.hotelOptions : [];
            const eventTypes = Array.isArray(model.eventTypes) && model.eventTypes.length ? model.eventTypes : EVENT_TYPES;
            const isBusy = record => String(this.busyActionId || '') === String(record.id || '');
            const emitDraft = (key, value) => this.$emit('update:eventDraft', { ...(this.eventDraft || {}), [key]: value });
            const actionButton = (label, onClick, disabled = false, primary = false) => h('button', {
                type: 'button',
                disabled,
                class: [
                    'rounded-lg border px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50',
                    primary
                        ? 'border-[#8b7040] bg-[#143a31] text-white hover:bg-[#0d2a23]'
                        : 'border-slate-200 bg-white text-slate-700 hover:border-[#a88a52] hover:text-[#6f572f]',
                ],
                onClick,
            }, label);

            const header = h('header', {
                class: 'overflow-hidden rounded-[18px] border border-[#263d35] text-white',
                style: { background: 'linear-gradient(135deg, #06110d 0%, #143a31 72%, #2f3e31 100%)' },
                'data-testid': 'operating-growth-header',
            }, [
                h('div', { class: 'flex min-w-0 flex-wrap items-end justify-between gap-5 p-5 sm:p-6' }, [
                    h('div', { class: 'min-w-0 flex-1' }, [
                        h('p', { class: 'mb-2 text-xs font-semibold tracking-[0.2em] text-[#dcc591]' }, 'OPERATING ARCHIVE'),
                        h('h1', { class: 'text-2xl font-bold tracking-tight sm:text-3xl' }, '经营成长档案'),
                        h('p', { class: 'mt-2 break-words text-sm text-slate-300' }, [
                            `酒店 ${model.scopeHotelName || '酒店未选择'}`,
                            ' · ',
                            `时间 ${model.dateRangeLabel || '时间范围未取得'}`,
                        ]),
                    ]),
                    h('div', { class: 'flex min-w-0 flex-wrap items-center gap-2' }, [
                        h('select', {
                            value: model.scopeHotelId || '',
                            class: 'max-w-full rounded-xl border border-white/20 bg-white/10 px-3 py-2.5 text-sm text-white outline-none focus:border-[#dcc591]',
                            'aria-label': '经营档案酒店',
                            'data-testid': 'operating-growth-hotel',
                            onChange: event => this.$emit('change-hotel', event.target.value),
                        }, [
                            h('option', { value: '', class: 'text-slate-900' }, '请选择酒店'),
                            ...hotelOptions.map(option => h('option', {
                                value: String(option.id || ''),
                                class: 'text-slate-900',
                            }, option.name || `酒店 #${option.id || '-'}`)),
                        ]),
                        rangeOptions.length
                            ? h('select', {
                                value: model.dateRangeKey || '',
                                class: 'max-w-full rounded-xl border border-white/20 bg-white/10 px-3 py-2.5 text-sm text-white outline-none focus:border-[#dcc591]',
                                'aria-label': '档案时间范围',
                                onChange: event => this.$emit('change-date-range', event.target.value),
                            }, rangeOptions.map(option => h('option', {
                                value: option.key,
                                class: 'text-slate-900',
                            }, option.label)))
                            : pill(h, model.dateRangeLabel || '时间范围未取得', 'border-white/20 bg-white/10 text-slate-100'),
                        h('button', {
                            type: 'button',
                            disabled: !model.canCreate,
                            class: 'rounded-xl border border-[#d4bc83] bg-gradient-to-b from-[#a88a52] to-[#6f572f] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50',
                            'data-testid': 'operating-growth-create',
                            onClick: () => this.$emit('open-event-form'),
                        }, '记录一件事'),
                    ]),
                ]),
            ]);

            const stateBanner = h('div', {
                class: ['flex min-w-0 flex-wrap items-start justify-between gap-3 rounded-xl border px-4 py-3 text-sm', model.stateClass],
                'data-testid': `operating-growth-state-${model.stateCode || 'unknown'}`,
            }, [
                h('div', { class: 'min-w-0 flex-1' }, [
                    h('strong', { class: 'block' }, model.stateLabel || '状态未取得'),
                    model.notice ? h('p', { class: 'mt-1 break-words leading-6' }, model.notice) : null,
                    model.lastReadAt ? h('small', { class: 'mt-1 block opacity-75' }, `最近回读 ${model.lastReadAt}`) : null,
                ]),
                actionButton('重新读取', () => this.$emit('refresh')),
            ]);

            const overview = h('section', {
                class: 'p-5 sm:p-6',
                style: surfaceStyle,
                'aria-labelledby': 'operating-growth-overview-title',
            }, [
                h('div', { class: 'mb-4 flex min-w-0 flex-wrap items-end justify-between gap-2' }, [
                    h('div', null, [
                        h('h2', { id: 'operating-growth-overview-title', class: 'text-base font-bold text-slate-900' }, '成长概览'),
                        h('p', { class: 'mt-1 text-xs text-slate-500' }, '只统计已回读且带有可用证据的状态'),
                    ]),
                    model.rejectedIdentityCount > 0
                        ? pill(h, `已拒绝 ${model.rejectedIdentityCount} 条身份不一致记录`, 'border-red-200 bg-red-50 text-red-700')
                        : null,
                ]),
                h('div', { style: responsiveGridStyle }, metrics.map(metric => h('article', {
                    class: 'min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3',
                }, [
                    h('p', { class: 'text-xs font-medium text-slate-500' }, metric.label || '指标未命名'),
                    h('strong', {
                        class: ['mt-2 block text-2xl', metric.available ? 'text-slate-900' : (metric.status === 'failed' ? 'text-red-700' : 'text-slate-400')],
                    }, metric.valueText || '未取得'),
                ]))),
            ]);

            const filters = h('nav', {
                class: 'flex max-w-full flex-wrap gap-2',
                'aria-label': '档案事件分类',
                'data-testid': 'operating-growth-filters',
            }, eventTypes.map(item => h('button', {
                type: 'button',
                class: [
                    'rounded-full border px-3 py-1.5 text-sm transition',
                    model.activeType === item.key
                        ? 'border-[#a88a52] bg-[#143a31] text-white'
                        : 'border-slate-200 bg-white text-slate-600 hover:border-[#a88a52] hover:text-[#6f572f]',
                ],
                'aria-pressed': model.activeType === item.key ? 'true' : 'false',
                onClick: () => this.$emit('change-filter', item.key),
            }, item.label)));

            const eventCard = record => h('article', {
                key: record.id || `${record.date}-${record.title}`,
                class: ['min-w-0 border-l-4 p-5 sm:p-6', record.isMilestone ? 'border-l-[#a88a52]' : 'border-l-slate-300'],
                style: surfaceStyle,
                'data-testid': 'operating-growth-event',
                'data-event-id': record.id || '',
            }, [
                h('div', { class: 'flex min-w-0 flex-wrap items-start justify-between gap-3' }, [
                    h('div', { class: 'min-w-0 flex-1' }, [
                        h('div', { class: 'mb-2 flex min-w-0 flex-wrap items-center gap-2' }, [
                            pill(h, record.typeLabel, record.typeClass),
                            pill(h, record.qualityLabel, record.qualityClass),
                            record.usageLabel ? pill(h, record.usageLabel, 'border-slate-200 bg-slate-50 text-slate-600') : null,
                            record.isMilestone ? pill(h, '里程碑', 'border-[#d4bc83] bg-[#fff8e7] text-[#6f572f]') : null,
                        ]),
                        h('h3', { class: 'break-words text-lg font-bold leading-7 text-slate-900' }, record.title),
                        h('p', { class: 'mt-1 text-sm font-medium text-slate-500' }, record.dateTimeLabel),
                    ]),
                    pill(h, record.evidenceText, record.evidenceCount === null
                        ? 'border-slate-200 bg-slate-50 text-slate-500'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-700'),
                ]),
                h('p', { class: 'mt-4 break-words text-sm leading-7 text-slate-700' }, record.summary),
                record.ownerJudgment
                    ? h('div', { class: 'mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3' }, [
                        h('strong', { class: 'text-xs text-amber-800' }, '老板判断'),
                        h('p', { class: 'mt-1 break-words text-sm leading-6 text-amber-950' }, record.ownerJudgment),
                    ])
                    : null,
                record.result
                    ? h('div', { class: 'mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3' }, [
                        h('strong', { class: 'text-xs text-emerald-800' }, '实际结果'),
                        h('p', { class: 'mt-1 break-words text-sm leading-6 text-emerald-950' }, record.result),
                    ])
                    : null,
                h('dl', { class: 'mt-4', style: responsiveGridStyle }, [
                    ['酒店', record.hotelName],
                    ['平台/范围', record.scopeLabel],
                    ['来源模块', record.sourceModule],
                    ['来源记录', record.sourceRef || '来源记录未取得'],
                ].map(([term, description]) => h('div', { class: 'min-w-0' }, [
                    h('dt', { class: 'text-xs text-slate-400' }, term),
                    h('dd', { class: 'mt-1 break-words text-sm font-medium text-slate-700' }, description),
                ]))),
                h('div', { class: 'mt-5 flex min-w-0 flex-wrap gap-2 border-t border-slate-100 pt-4' }, [
                    actionButton(
                        record.sourceAvailable ? '查看来源' : '来源未取得',
                        () => this.$emit('open-source', record),
                        !record.sourceAvailable || !model.canOpenSource || isBusy(record),
                    ),
                    actionButton('补充批注', () => this.$emit('add-note', record), !record.canAnnotate || isBusy(record)),
                    record.isMilestone
                        ? pill(h, '已设为里程碑', 'border-[#d4bc83] bg-[#fff8e7] text-[#6f572f]')
                        : actionButton('设为里程碑', () => this.$emit('set-milestone', record), !record.canSetMilestone || isBusy(record)),
                ]),
            ]);

            const timelineBody = model.isInitialBlocking
                ? h('div', {
                    class: 'rounded-[18px] border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-sm text-slate-500',
                    'data-testid': 'operating-growth-blocked',
                }, model.stateLabel || '档案暂不可用')
                : (model.isEmpty
                    ? h('div', {
                        class: 'rounded-[18px] border border-dashed border-slate-300 bg-white px-6 py-12 text-center',
                        'data-testid': 'operating-growth-empty',
                    }, [
                        h('strong', { class: 'text-slate-700' }, '暂无档案'),
                        h('p', { class: 'mt-2 text-sm text-slate-500' }, '当前酒店与时间范围已完成读取，没有匹配记录。'),
                    ])
                    : (records.length
                        ? h('div', { class: 'grid min-w-0 gap-4' }, records.map(eventCard))
                        : h('div', {
                            class: 'rounded-[18px] border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500',
                            'data-testid': 'operating-growth-filter-empty',
                        }, '当前分类没有匹配记录。')));

            const form = !this.showEventForm ? null : h('section', {
                class: 'p-5 sm:p-6',
                style: surfaceStyle,
                'data-testid': 'operating-growth-event-form',
            }, [
                h('div', { class: 'mb-5 flex min-w-0 flex-wrap items-start justify-between gap-3' }, [
                    h('div', null, [
                        h('h2', { class: 'text-lg font-bold text-slate-900' }, '记录一件事'),
                        h('p', { class: 'mt-1 text-sm text-slate-500' }, '保存结果由父页面按记录 ID 严格回读后确认。'),
                    ]),
                    actionButton('关闭', () => this.$emit('close-event-form'), this.saving),
                ]),
                h('div', { style: responsiveGridStyle }, [
                    h('label', { class: 'min-w-0 text-sm text-slate-700' }, [
                        h('span', { class: 'mb-1.5 block font-medium' }, '酒店（当前上下文锁定）'),
                        h('input', {
                            value: model.scopeHotelName || '酒店未选择',
                            disabled: true,
                            class: 'w-full min-w-0 rounded-xl border border-slate-200 bg-slate-100 px-3 py-2.5 text-slate-600',
                        }),
                    ]),
                    h('label', { class: 'min-w-0 text-sm text-slate-700' }, [
                        h('span', { class: 'mb-1.5 block font-medium' }, '发生日期'),
                        h('input', {
                            type: 'date',
                            value: this.eventDraft?.date || '',
                            required: true,
                            class: 'w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                            onInput: event => emitDraft('date', event.target.value),
                        }),
                    ]),
                    h('label', { class: 'min-w-0 text-sm text-slate-700' }, [
                        h('span', { class: 'mb-1.5 block font-medium' }, '发生时间'),
                        h('input', {
                            type: 'time',
                            value: this.eventDraft?.time || '',
                            required: true,
                            class: 'w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                            onInput: event => emitDraft('time', event.target.value),
                        }),
                    ]),
                    h('label', { class: 'min-w-0 text-sm text-slate-700' }, [
                        h('span', { class: 'mb-1.5 block font-medium' }, '事件类型'),
                        h('select', {
                            value: this.eventDraft?.type || '',
                            required: true,
                            class: 'w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                            onChange: event => emitDraft('type', event.target.value),
                        }, [
                            h('option', { value: '', disabled: true }, '请选择事件类型'),
                            ...EVENT_TYPES.filter(item => item.key !== 'all').map(item => h('option', { value: item.key }, item.label)),
                        ]),
                    ]),
                    h('label', { class: 'min-w-0 text-sm text-slate-700' }, [
                        h('span', { class: 'mb-1.5 block font-medium' }, '数据范围'),
                        h('select', {
                            value: this.eventDraft?.dataScope || '',
                            required: true,
                            class: 'w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                            onChange: event => emitDraft('dataScope', event.target.value),
                        }, [
                            ['', '请选择数据范围'],
                            ['ctrip', '携程'],
                            ['meituan', '美团'],
                            ['pms', 'PMS'],
                            ['whole_hotel', '全酒店'],
                            ['manual_context', '人工背景'],
                            ['other', '其他'],
                        ].map(([value, label]) => h('option', { value, disabled: value === '' }, label))),
                    ]),
                ]),
                h('label', { class: 'mt-4 block min-w-0 text-sm text-slate-700' }, [
                    h('span', { class: 'mb-1.5 block font-medium' }, '标题'),
                    h('input', {
                        value: this.eventDraft?.title || '',
                        required: true,
                        maxlength: 120,
                        class: 'w-full min-w-0 rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                        onInput: event => emitDraft('title', event.target.value),
                    }),
                ]),
                h('label', { class: 'mt-4 block min-w-0 text-sm text-slate-700' }, [
                    h('span', { class: 'mb-1.5 block font-medium' }, '事实描述'),
                    h('textarea', {
                        value: this.eventDraft?.factDescription || '',
                        required: true,
                        rows: 4,
                        maxlength: 2000,
                        class: 'w-full min-w-0 resize-y rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                        onInput: event => emitDraft('factDescription', event.target.value),
                    }),
                ]),
                h('label', { class: 'mt-4 block min-w-0 text-sm text-slate-700' }, [
                    h('span', { class: 'mb-1.5 block font-medium' }, '老板判断（可空，不自动补写）'),
                    h('textarea', {
                        value: this.eventDraft?.ownerJudgment || '',
                        rows: 3,
                        maxlength: 1200,
                        class: 'w-full min-w-0 resize-y rounded-xl border border-slate-300 bg-white px-3 py-2.5',
                        onInput: event => emitDraft('ownerJudgment', event.target.value),
                    }),
                ]),
                h('div', { class: 'mt-5 flex min-w-0 flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4' }, [
                    h('p', { class: 'min-w-0 flex-1 break-words text-xs text-slate-500' }, '未附证据的人工记录应由保存接口标记为“人工记录、未核验”。'),
                    actionButton(this.saving ? '正在保存并回读…' : '保存并严格回读', () => this.$emit('submit-event'), this.saving || !model.canCreate, true),
                ]),
            ]);

            return h('main', {
                class: 'operating-growth-archive mx-auto grid w-full min-w-0 max-w-7xl gap-5 overflow-x-hidden px-3 py-4 text-slate-900 sm:px-5 sm:py-6',
                style: { fontFamily: 'Microsoft YaHei, PingFang SC, Segoe UI, system-ui, sans-serif' },
                'data-testid': 'operating-growth-archive',
            }, [
                header,
                stateBanner,
                form,
                !model.isInitialBlocking ? overview : null,
                h('section', { class: 'grid min-w-0 gap-4', 'aria-labelledby': 'operating-growth-timeline-title' }, [
                    h('div', { class: 'flex min-w-0 flex-wrap items-end justify-between gap-3' }, [
                        h('div', null, [
                            h('h2', { id: 'operating-growth-timeline-title', class: 'text-lg font-bold text-slate-900' }, '经营时间线'),
                            h('p', { class: 'mt-1 text-xs text-slate-500' }, `酒店 ${model.scopeHotelName || '未选择'} · ${model.dateRangeLabel || '时间范围未取得'}`),
                        ]),
                        filters,
                    ]),
                    timelineBody,
                ]),
            ]);
        },
    };

    return {
        EVENT_TYPES,
        normalizeGrowthMetric,
        normalizeGrowthEvent,
        buildOperatingGrowthArchiveModel,
        OperatingGrowthArchive,
    };
})();
