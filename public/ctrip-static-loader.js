(() => {
    'use strict';

    const ctripProfilePrimaryCategoryOptions = ['流量转化数据', '经营收益数据', '服务质量数据', '竞争力数据'];
    const ctripProfileDefaultModuleOptions = [
        { value: 'business_overview', label: '经营报告-概要-日报', primary_category: '经营收益数据' },
        { value: 'business_weekly_overview', label: '经营报告-概要-周报', primary_category: '经营收益数据' },
        { value: 'sales_report', label: '经营报告-销售数据', primary_category: '经营收益数据' },
        { value: 'traffic_report', label: '经营报告-流量数据', primary_category: '流量转化数据' },
        { value: 'comment_review', label: '点评数据', primary_category: '服务质量数据' },
        { value: 'competitor_overview', label: '竞争圈动态-竞争圈概览', primary_category: '竞争力数据' },
        { value: 'loss_analysis', label: '竞争圈动态-流失分析', primary_category: '竞争力数据' },
        { value: 'competitor_rank', label: '竞争圈动态-竞争圈榜单', primary_category: '竞争力数据' },
        { value: 'quality_psi', label: 'PSI服务质量', primary_category: '服务质量数据' },
        { value: 'market_calendar', label: '市场分析-市场热度', primary_category: '竞争力数据' },
        { value: 'user_profile', label: '用户行为/点评分析', primary_category: '流量转化数据' },
        { value: 'im_board', label: '用户行为-IM看板', primary_category: '服务质量数据' },
        { value: 'ads_pyramid', label: '金字塔广告', primary_category: '流量转化数据' },
    ];
    const ctripProfileForbiddenFieldKeys = ['guest_phone', 'order_phone', 'room_status', 'room_source_mapping'];
    const ctripProfileForbiddenFieldAssets = [
        { key: 'guest_phone', label: '客人手机号' },
        { key: 'order_phone', label: '订单手机号' },
        { key: 'room_status', label: '房态明细' },
        { key: 'room_source_mapping', label: '房源映射' },
    ];
    const ctripOverviewApiKeywords = [];
    const ctripFlowOverviewApiGroups = [];
    const ctripFlowOverviewDefaultRequestUrls = [];
    const defaultCtripConfigUrl = '';
    const defaultCtripAdsEffectReportUrl = '';
    const ctripAdsApiUrlHint = '普通账户仅选择采集任务，接口目录由服务端管理。';

    const createCtripProfileModuleForm = () => ({
        id: '',
        label: '',
        page_url: '',
        primary_category: '',
        enabled: true,
        sort_order: 0,
        description: '',
    });
    const normalizeCtripProfileModuleRow = (module = {}) => ({
        id: String(module.id || module.value || '').trim(),
        label: String(module.label || module.name || module.id || '').trim(),
        enabled: module.enabled !== false && module.enabled !== 0,
        system: module.system === true || module.system === 1,
        sort_order: Number(module.sort_order || module.sortOrder || 0),
        page_url: String(module.page_url || module.pageUrl || module.url || '').trim(),
        primary_category: String(module.primary_category || module.primaryCategory || module.category || '').trim(),
        description: String(module.description || module.notes || '').trim(),
        field_count: Number(module.field_count || 0),
        enabled_field_count: Number(module.enabled_field_count || 0),
        deleted_at: String(module.deleted_at || '').trim(),
    });
    const ctripProfileModulePageUrl = (module) => String(module?.page_url || module?.pageUrl || module?.url || '').trim();
    const ctripProfileModulePageDisplay = (module) => {
        const pageUrl = ctripProfileModulePageUrl(module);
        if (!pageUrl) return '';
        try {
            const parsed = new URL(pageUrl);
            return `${parsed.pathname}${parsed.search}`;
        } catch (error) {
            return pageUrl;
        }
    };
    const normalizeCtripProfileFieldVerificationStatus = (status) => {
        const value = String(status || '').trim().toLowerCase();
        if (['matched', 'match', 'ok', 'correct'].includes(value)) return 'matched';
        if (['mismatched', 'mismatch', 'wrong', 'incorrect'].includes(value)) return 'mismatched';
        return 'unverified';
    };
    const ctripProfileFieldVerificationText = (status) => ({
        matched: '数值相符',
        mismatched: '数据不符',
        unverified: '待核验',
    }[normalizeCtripProfileFieldVerificationStatus(status)] || '待核验');
    const ctripProfileFieldVerificationBadgeClass = (status) => {
        const value = normalizeCtripProfileFieldVerificationStatus(status);
        if (value === 'matched') return 'border-emerald-100 bg-emerald-50 text-emerald-700';
        if (value === 'mismatched') return 'border-red-100 bg-red-50 text-red-700';
        return 'border-gray-200 bg-gray-50 text-gray-500';
    };
    const ctripProfileFieldVerificationLightClass = (status) => {
        const value = normalizeCtripProfileFieldVerificationStatus(status);
        if (value === 'matched') return 'bg-emerald-500';
        if (value === 'mismatched') return 'bg-red-500';
        return 'bg-gray-300';
    };
    const isCtripAdsApiUrl = (url = '') => {
        try {
            const parsed = new URL(String(url || '').trim());
            return parsed.protocol === 'https:'
                && /(^|\.)ctrip\.com$/i.test(parsed.hostname)
                && parsed.pathname.toLowerCase().includes('/api/');
        } catch (_error) {
            return false;
        }
    };
    const normalizeCtripAdsApiType = (value = '') => 'effect_report';

    const createCtripFetchForm = () => ({
        url: defaultCtripConfigUrl,
        nodeId: '24588',
        startDate: '',
        endDate: '',
        cookies: '',
        auth_data: {},
    });
    const createCtripConfigForm = (overrides = {}) => ({
        id: null,
        name: '',
        hotel_id: '',
        ctrip_hotel_id: '',
        url: defaultCtripConfigUrl,
        node_id: '',
        capture_sections: 'all',
        hotel_room_count: '',
        competitor_room_count: '',
        approved_mappings_path: '',
        cookies: '',
        has_cookies: false,
        credential_status: '',
        ...overrides,
        capture_sections: 'all',
    });
    const createCtripTrafficForm = () => ({
        url: '',
        platform: 'Ctrip',
        dateRange: 'last_30_days',
        startDate: '',
        endDate: '',
        cookies: '',
        extraParams: '',
    });
    const createCtripAdsBrowserCaptureForm = () => ({
        url: '',
        cookies: '',
        payloadJson: '',
        apiType: 'effect_report',
        dateRange: 'yesterday',
        startDate: '',
        endDate: '',
        campaignId: '',
    });
    const defaultCtripOverviewDataDate = () => {
        const date = new Date();
        date.setDate(date.getDate() - 1);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    };
    const createCtripOverviewForm = () => ({
        requestUrls: '',
        cookies: '',
        spidertoken: '',
        payloadJson: '',
        hotelId: '',
        method: 'GET',
        dataDate: defaultCtripOverviewDataDate(),
    });
    const createCtripFlowOverviewForm = () => ({
        requestUrls: '',
        cookies: '',
        spidertoken: '',
        payloadJson: '',
        hotelId: '',
        method: 'POST',
        dataDate: defaultCtripOverviewDataDate(),
    });
    const createCtripBrowserCaptureForm = () => ({
        profileId: '',
        hotelId: '',
        approvedMappingsPath: '',
        sections: 'default',
    });
    const createCtripCookieApiForm = () => ({
        profileId: '',
        requestSource: '',
        requestUrl: '',
        method: 'GET',
        payloadJson: '',
        endpointsJson: '',
        cookies: '',
    });
    const createCtripEndpointEvidenceForm = () => ({
        requestUrl: '',
        method: 'POST',
        headersText: '',
        payloadJson: '',
        responseJson: '',
        pageContextJson: '',
        paramsJson: '',
        saveStandardRows: false,
    });
    const createCtripCommentForm = () => ({
        requestUrl: '',
        hotelId: '',
        spidertoken: '',
        cookies: '',
        pageIndex: 1,
        pageSize: 50,
        payloadJson: '',
    });
    const createCtripCommentBrowserCaptureForm = () => ({
        profileId: '',
        pageUrl: '',
        apiKeyword: '',
    });
    const createCtripProfileFieldForm = () => ({
        id: '',
        field_key: '',
        field_name: '',
        section: 'business_overview',
        data_type: 'business',
        page_location: '',
        target_field: '',
        target_value: '',
        value_meaning: '',
        source_interface: '',
        source_keys: '',
        page_url: '',
        request_url: '',
        json_path: '',
        ownership_rule: '',
        storage_field: '',
        value_type: '',
        unit: '',
        transform_rule: '',
        status: 'pending',
        enabled: true,
        sample_verification_status: 'unverified',
        sample_verified_at: '',
        sample_verified_by: null,
        verified_sample_value: '',
        verified_sample_unit: '',
        verified_sample_source_key: '',
        verified_sample_source_path: '',
        verified_sample_endpoint_id: '',
        verified_sample_data_date: '',
        verified_sample_hotel_name: '',
        verified_sample_captured_at: '',
        notes: '',
        sort_order: 0,
    });

    const buildCtripProfileFieldSampleHelpers = () => {
        const sampleValueText = (sample) => {
            if (sample && typeof sample === 'object') {
                const value = sample.value ?? sample.latest_value ?? '';
                const unit = sample.unit ? String(sample.unit) : '';
                return `${value === null || value === undefined ? '' : String(value)}${unit}`.trim();
            }
            return sample === null || sample === undefined ? '' : String(sample).trim();
        };
        const sampleItems = (field) => {
            if (!field) return [];
            if (Array.isArray(field.latest_values) && field.latest_values.length > 0) {
                return field.latest_values
                    .map(sample => (sample && typeof sample === 'object' ? sample : { value: sample }))
                    .filter(sample => sampleValueText(sample));
            }
            const value = String(field.latest_value || '').trim();
            if (!value) return [];
            return value.split(' / ').map(item => ({ value: item })).filter(sample => sampleValueText(sample));
        };
        const sampleCapturedAt = (sample) => sample && typeof sample === 'object'
            ? String(sample.captured_at || '').trim()
            : '';
        const sampleBatchKey = (sample) => {
            if (!sample || typeof sample !== 'object') return '';
            const explicitKey = String(sample.sample_batch_key || '').trim();
            if (explicitKey) return explicitKey;
            const syncTaskId = Number(sample.sync_task_id || 0);
            if (Number.isFinite(syncTaskId) && syncTaskId > 0) return `sync_task:${syncTaskId}`;
            const capturedAt = sampleCapturedAt(sample);
            return capturedAt ? `captured_at:${capturedAt}` : '';
        };
        const sampleMetaText = (sample) => {
            if (!sample || typeof sample !== 'object') return '';
            return [
                sample.data_date ? `日期 ${sample.data_date}` : '',
                sample.hotel_name ? `门店 ${sample.hotel_name}` : '',
                sample.source_key ? `字段 ${sample.source_key}` : '',
                sample.source_path ? `路径 ${sample.source_path}` : '',
            ].filter(Boolean).join(' · ');
        };
        const sampleBriefMetaText = (sample) => {
            if (!sample || typeof sample !== 'object') return '';
            return [
                sample.data_date ? `日期 ${sample.data_date}` : '',
                sample.hotel_name ? `门店 ${sample.hotel_name}` : '',
            ].filter(Boolean).join(' · ');
        };
        const sampleSourceText = (sample) => {
            if (!sample || typeof sample !== 'object') return '';
            return [
                sample.endpoint_id || sample.capture_section || '',
                sample.source_key || '',
                sample.source_path || '',
            ].filter(Boolean).join(' · ');
        };
        const sampleText = (field) => sampleItems(field)
            .map(sample => `${sampleValueText(sample)} ${sampleMetaText(sample)}`.trim())
            .filter(Boolean)
            .join(' / ');
        const latestBatchSampleItems = (field, currentBatchKey = '') => {
            const items = sampleItems(field);
            const batchKey = String(currentBatchKey || '').trim();
            if (batchKey) return items.filter(sample => sampleBatchKey(sample) === batchKey);
            const latestBatchKey = items.map(sampleBatchKey).filter(Boolean)[0] || '';
            if (latestBatchKey) return items.filter(sample => sampleBatchKey(sample) === latestBatchKey);
            return items.slice(0, 3);
        };
        const displaySampleItems = (field, currentBatchKey = '') => {
            const latestBatchItems = latestBatchSampleItems(field, currentBatchKey);
            if (latestBatchItems.length > 0) return latestBatchItems;
            return sampleItems(field).slice(0, 3);
        };
        const hasOnlyHistoricalSamples = (field, currentBatchKey = '') => (
            sampleItems(field).length > 0 && latestBatchSampleItems(field, currentBatchKey).length === 0
        );
        const previewSampleItems = (field, currentBatchKey = '') => displaySampleItems(field, currentBatchKey).slice(0, 1);
        const latestBatchSampleCount = (field, currentBatchKey = '') => latestBatchSampleItems(field, currentBatchKey).length;
        const displaySampleCount = (field, currentBatchKey = '') => displaySampleItems(field, currentBatchKey).length;
        const latestSampleTime = (field, currentBatchKey = '') => {
            const times = displaySampleItems(field, currentBatchKey).map(sampleCapturedAt).filter(Boolean).sort().reverse();
            return times[0] || '';
        };
        const fieldSampleSourceText = (field, currentBatchKey = '') => {
            const sample = displaySampleItems(field, currentBatchKey)[0] || sampleItems(field)[0];
            return sampleSourceText(sample);
        };
        const sampleSelectionKey = (sample) => {
            if (!sample || typeof sample !== 'object') return String(sample || '').trim();
            return [
                sampleValueText(sample),
                sample.unit || '',
                sample.source_key || '',
                sample.source_path || '',
                sample.endpoint_id || sample.capture_section || '',
                sample.data_date || '',
                sample.hotel_name || '',
                sample.captured_at || sample.created_at || '',
            ].map(item => String(item || '').trim()).join('|');
        };
        const verifiedSampleKey = (field) => {
            if (!field?.verified_sample_value) return '';
            return [
                field.verified_sample_value,
                field.verified_sample_unit,
                field.verified_sample_source_key,
                field.verified_sample_source_path,
                field.verified_sample_endpoint_id,
                field.verified_sample_data_date,
                field.verified_sample_hotel_name,
                field.verified_sample_captured_at,
            ].map(item => String(item || '').trim()).join('|');
        };
        const sampleSourcePathCanSeedJson = (sourcePath) => {
            const value = String(sourcePath || '').trim();
            return Boolean(value && !value.startsWith('online_daily_data#') && !value.includes('#'));
        };
        return {
            sampleValueText,
            sampleItems,
            sampleCapturedAt,
            sampleBatchKey,
            sampleMetaText,
            sampleBriefMetaText,
            sampleSourceText,
            sampleText,
            latestBatchSampleItems,
            displaySampleItems,
            hasOnlyHistoricalSamples,
            previewSampleItems,
            latestBatchSampleCount,
            displaySampleCount,
            latestSampleTime,
            fieldSampleSourceText,
            sampleSelectionKey,
            verifiedSampleKey,
            sampleSourcePathCanSeedJson,
        };
    };

    const buildCtripProfileFieldDerivationHelpers = ({
        forbiddenFieldKeys = [],
        captureSectionText = value => String(value || ''),
        normalizeVerificationStatus = value => String(value || '').trim(),
        sampleTextForField = () => '',
    } = {}) => {
        const forbiddenKeyItems = forbiddenFieldKeys && typeof forbiddenFieldKeys[Symbol.iterator] === 'function'
            ? Array.from(forbiddenFieldKeys)
            : (Array.isArray(forbiddenFieldKeys) ? forbiddenFieldKeys : []);
        const forbiddenKeys = new Set(forbiddenKeyItems.map(item => String(item || '').trim()).filter(Boolean));
        const resolveSampleText = typeof sampleTextForField === 'function' ? sampleTextForField : () => '';
        const resolveSectionText = typeof captureSectionText === 'function' ? captureSectionText : value => String(value || '');
        const resolveVerificationStatus = typeof normalizeVerificationStatus === 'function'
            ? normalizeVerificationStatus
            : value => String(value || '').trim();
        const isFieldEnabled = field => (
            field?.enabled !== false
            && Number(field?.enabled ?? 1) !== 0
            && String(field?.enabled ?? '').toLowerCase() !== 'false'
        );
        const isFieldForbidden = field => (
            forbiddenKeys.has(String(field?.field_key || field?.field || '').trim())
            || String(field?.asset_status || '').trim() === 'forbidden'
            || String(field?.storage_table || '').trim() === 'not_collected'
        );
        const isFieldCollectable = field => isFieldEnabled(field) && !isFieldForbidden(field);
        const matchesFilters = (field, filters = {}) => {
            const activeFilters = filters || {};
            const keyword = String(activeFilters.keyword || '').trim().toLowerCase();
            if (activeFilters.section && String(field?.section || '') !== activeFilters.section) return false;
            if (activeFilters.status && String(field?.status || '') !== activeFilters.status) return false;
            if (activeFilters.enabled === 'enabled' && !isFieldEnabled(field)) return false;
            if (activeFilters.enabled === 'disabled' && isFieldEnabled(field)) return false;
            const sampleText = resolveSampleText(field);
            if (activeFilters.sample === 'with_sample' && !sampleText) return false;
            if (activeFilters.sample === 'not_returned' && (!isFieldCollectable(field) || sampleText)) return false;
            if (activeFilters.sample === 'without_sample' && sampleText) return false;
            if (!keyword) return true;
            return [
                field?.field_key,
                field?.field_name,
                resolveSectionText(field?.section),
                field?.page_location,
                field?.target_field,
                field?.target_value,
                field?.value_meaning,
                field?.source_interface,
                field?.source_keys,
                field?.page_url,
                field?.request_url,
                field?.json_path,
                field?.ownership_rule,
                field?.storage_field,
                field?.value_type,
                field?.unit,
                field?.transform_rule,
                field?.notes,
                sampleText,
                field?.latest_sample_note,
            ].some(item => String(item || '').toLowerCase().includes(keyword));
        };
        const filterFields = (fields, filters = {}) => (Array.isArray(fields) ? fields : [])
            .filter(field => matchesFilters(field, filters));
        const countStableFields = fields => (Array.isArray(fields) ? fields : []).filter(field => (
            String(field?.status || '').trim() === 'confirmed'
            || resolveVerificationStatus(field?.sample_verification_status) === 'matched'
        )).length;
        const buildVisibleDetail = ({ visibleCount = 0, totalCount = 0 } = {}) => (
            Number(visibleCount) === Number(totalCount)
                ? '只放已定义标准字段'
                : `当前筛选 ${visibleCount} / 配置表 ${totalCount}`
        );
        const buildCaptureResultText = ({
            sampleLoading = false,
            samplesLoaded = false,
            enabledCount = 0,
            sampledCount = 0,
            missingCount = 0,
        } = {}) => {
            if (sampleLoading) return '加载中';
            if (!samplesLoaded) return `应抓 ${enabledCount} / 抓到未加载 / 未返回未加载`;
            return `应抓 ${enabledCount} / 抓到 ${sampledCount} / 未返回 ${missingCount}`;
        };
        const buildAssetLedgerCards = ({
            fieldVisibleCount = 0,
            fieldTotalCount = 0,
            enabledVisibleFieldCount = 0,
            sampledVisibleFieldCount = 0,
            stableVisibleFieldCount = 0,
            notReturnedVisibleFieldCount = null,
            sampleLoading = false,
            samplesLoaded = false,
            forbiddenFieldCount = 0,
            visibleDetail = '',
        } = {}) => ([
            {
                key: 'standard', label: '标准字段', value: fieldVisibleCount,
                badge: fieldVisibleCount === fieldTotalCount ? '配置表' : '当前展示',
                className: 'bg-slate-100 text-slate-700',
                detail: visibleDetail || buildVisibleDetail({ visibleCount: fieldVisibleCount, totalCount: fieldTotalCount }),
            },
            {
                key: 'capture_target', label: '应抓字段', value: enabledVisibleFieldCount, badge: '启用',
                className: 'bg-blue-100 text-blue-700', detail: '当前展示中启用且非禁止采集的字段',
            },
            {
                key: 'capture_success', label: '已抓到',
                value: sampleLoading ? '加载中' : (samplesLoaded ? sampledVisibleFieldCount : '未加载'),
                badge: '有值',
                className: samplesLoaded ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600',
                detail: '当前展示启用字段已有历史获取值',
            },
            {
                key: 'stable', label: '稳定字段', value: stableVisibleFieldCount, badge: '已确认',
                className: 'bg-emerald-100 text-emerald-700', detail: '当前展示字段已确认或样例相符',
            },
            {
                key: 'not_returned', label: '未返回/失败',
                value: sampleLoading ? '加载中' : (samplesLoaded ? notReturnedVisibleFieldCount : '未加载'),
                badge: samplesLoaded ? '需复核' : '待加载',
                className: samplesLoaded ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600',
                detail: '当前展示启用字段暂无历史获取值，需区分接口未触发或字段未入库',
            },
            {
                key: 'forbidden', label: '禁止采集', value: forbiddenFieldCount, badge: '边界',
                className: 'bg-red-100 text-red-700', detail: '手机号、房态、房源映射不进表',
            },
        ]);
        return {
            isFieldEnabled,
            isFieldForbidden,
            isFieldCollectable,
            matchesFilters,
            filterFields,
            countStableFields,
            buildVisibleDetail,
            buildCaptureResultText,
            buildAssetLedgerCards,
        };
    };

    const extractCtripRealtimeTrafficSnapshot = (items = []) => {
        const rows = Array.isArray(items) ? items : [items];
        const snapshot = {
            status: 'missing', visitor_count: null, competitor_avg_visitor: null, visitor_rank: null,
            visitor_count_last_week: null, order_count: null, yesterday_order_count: null,
            rank: null, competitor_rank: null, competitor_hotel_total: null, captured_at: '',
        };
        const toMetricNumber = (value, { positiveOnly = false } = {}) => {
            if (value === null || value === undefined || value === '' || typeof value === 'boolean') return null;
            const number = Number(value);
            if (!Number.isFinite(number) || (positiveOnly && number <= 0)) return null;
            return number;
        };
        const assignFirst = (key, candidates, options = {}) => {
            if (snapshot[key] !== null) return;
            for (const candidate of candidates) {
                const value = toMetricNumber(candidate, options);
                if (value !== null) {
                    snapshot[key] = value;
                    return;
                }
            }
        };
        rows.forEach(row => {
            let raw = row?.raw_data ?? row?.rawData ?? row;
            if (typeof raw === 'string') {
                try { raw = JSON.parse(raw); } catch (_error) { return; }
            }
            if (!raw || typeof raw !== 'object') return;
            const endpointId = String(raw.endpoint_id || raw.endpointId || row?.endpoint_id || '').trim();
            const facts = Array.isArray(raw.facts) ? raw.facts : [];
            const factValue = (...sourceKeys) => {
                const normalizedKeys = sourceKeys.map(key => String(key).toLowerCase());
                return facts.find(fact => normalizedKeys.includes(String(fact?.source_key || fact?.sourceKey || '').toLowerCase()))?.value;
            };
            const responseData = raw?.data?.data && typeof raw.data.data === 'object'
                ? raw.data.data
                : (raw?.data && typeof raw.data === 'object' ? raw.data : {});
            if (!snapshot.captured_at) {
                snapshot.captured_at = String(raw.captured_at || row?.captured_at || row?.update_time || row?.create_time || '');
            }
            if (endpointId === 'business_visitor_title') {
                assignFirst('visitor_count', [factValue('visitorTotal'), responseData.visitorTotal]);
                assignFirst('competitor_avg_visitor', [factValue('competitorAvgNumber'), responseData.competitorAvgNumber]);
                assignFirst('visitor_rank', [factValue('visitorRank'), responseData.visitorRank], { positiveOnly: true });
                assignFirst('visitor_count_last_week', [factValue('lastVisitorTotal'), responseData.lastVisitorTotal]);
            }
            if (endpointId === 'business_realtime') {
                assignFirst('visitor_count', [factValue('visitorTotal'), responseData.visitorTotal]);
                assignFirst('order_count', [factValue('orderQuantity'), responseData.orderQuantity]);
                assignFirst('yesterday_order_count', [factValue('synchronizationOrderQuantity'), responseData.synchronizationOrderQuantity]);
            }
            if (['traffic_hotel_seq', 'business_hotel_seq'].includes(endpointId)) {
                assignFirst('rank', [
                    factValue('rank', 'seqRank', 'trafficRank', 'appDetailUvRank'), responseData.rank,
                    responseData.trafficRank, responseData.seqRank, responseData.appDetailUvRank,
                    raw?.metrics?.traffic_rank, raw?.rank_metrics?.traffic_rank, row?.traffic_rank,
                    row?.seq_rank, row?.app_detail_uv_rank,
                ], { positiveOnly: true });
                assignFirst('competitor_rank', [
                    factValue('competitorRank', 'qunarCompetitorRank'), responseData.competitorRank,
                    responseData.qunarCompetitorRank, raw?.metrics?.traffic_competitor_rank,
                    raw?.rank_metrics?.traffic_competitor_rank,
                ], { positiveOnly: true });
                assignFirst('competitor_hotel_total', [
                    factValue('competitorHotelTotal'), responseData.competitorHotelTotal,
                    raw?.metrics?.traffic_competitor_hotel_total,
                ]);
            }
        });
        const metricKeys = Object.keys(snapshot).filter(key => !['status', 'captured_at'].includes(key));
        snapshot.status = metricKeys.some(key => snapshot[key] !== null) ? 'available' : 'missing';
        return snapshot;
    };
    const extractCtripRealtimeTrafficRank = (row = {}) => {
        const snapshot = extractCtripRealtimeTrafficSnapshot([row]);
        if (snapshot.rank === null) return null;
        return {
            status: 'available', rank: snapshot.rank, metric_key: 'traffic_rank',
            endpoint_id: 'traffic_hotel_seq', captured_at: snapshot.captured_at,
        };
    };
    const buildLatestCtripSnapshotModel = (payload = {}) => {
        const rank = payload?.rank || {};
        const traffic = payload?.traffic || {};
        const review = payload?.review || {};
        const rankRows = Array.isArray(rank.rows) ? rank.rows : [];
        const rankDisplayHotels = Array.isArray(rank.display_hotels) ? rank.display_hotels : [];
        const trafficRows = Array.isArray(traffic.rows) ? traffic.rows : [];
        const displayTrafficRows = Array.isArray(traffic.display_traffic_rows) ? traffic.display_traffic_rows : [];
        const reviewRows = Array.isArray(review.rows) ? review.rows : [];
        const hasRank = rankRows.length > 0 || rankDisplayHotels.length > 0;
        const hasTraffic = trafficRows.length > 0 || displayTrafficRows.length > 0;
        const hasReview = reviewRows.length > 0;
        const hasAnySnapshot = hasRank || hasTraffic || hasReview;
        return {
            metadata: payload?.metadata || null,
            rankRows,
            rankDisplayHotels,
            rankDisplaySummary: rank.display_summary || null,
            rankTotal: rank.total || 0,
            rankDataDate: rank.data_date || '',
            rankFetchedAt: rank.fetched_at || '',
            trafficRows,
            displayTrafficRows,
            trafficDisplaySummary: traffic.display_traffic_summary || null,
            reviewRows,
            reviewResult: hasReview ? {
                data: reviewRows,
                total: review.total || reviewRows.length,
                record_count: review.total || reviewRows.length,
                saved_count: Number(review.saved_count || 0),
                persistence_status: String(review.persistence_status || ''),
                readback_verified: review.readback_verified === true
                    || review.database_readback?.verified === true
                    || review.database_readback?.readback_verified === true
                    || String(review.persistence_status || '').toLowerCase() === 'readback_verified',
            } : null,
            onlineResult: hasAnySnapshot ? {
                source: 'latest', metadata: payload.metadata, rank: payload.rank,
                traffic: payload.traffic, review: payload.review,
            } : null,
            hasRank,
            hasTraffic,
            hasReview,
            hasAnySnapshot,
        };
    };
    const ctripUnsupportedEstimateKeys = ['aiEstimatedTotalRoomNights', 'ai_estimated_total_room_nights'];
    const omitUnsupportedCtripEstimate = (source = {}) => {
        const result = { ...(source && typeof source === 'object' ? source : {}) };
        ctripUnsupportedEstimateKeys.forEach(key => delete result[key]);
        return result;
    };
    const buildTruthfulCtripDisplayModel = (rows = [], summary = null) => {
        const displayRows = Array.isArray(rows) ? rows.map(omitUnsupportedCtripEstimate) : [];
        if (!summary || typeof summary !== 'object') return { rows: displayRows, summary };
        const normalizedSummary = { ...summary };
        if (normalizedSummary.metrics && typeof normalizedSummary.metrics === 'object') {
            normalizedSummary.metrics = omitUnsupportedCtripEstimate(normalizedSummary.metrics);
        }
        if (Array.isArray(normalizedSummary.cards)) {
            normalizedSummary.cards = normalizedSummary.cards.filter(card => (
                !ctripUnsupportedEstimateKeys.includes(String(card?.key || ''))
            ));
        }
        return { rows: displayRows, summary: normalizedSummary };
    };
    const isCtripLatestRequestCurrent = (requestContext = {}, currentContext = {}) => (
        Number(requestContext.seq || 0) > 0
        && Number(requestContext.seq || 0) === Number(currentContext.activeSeq || 0)
        && String(requestContext.hotelId || '').trim() === String(currentContext.hotelId || '').trim()
        && String(requestContext.range || '').trim() === String(currentContext.range || '').trim()
    );

    const CtripConfigHistory = {
        name: 'CtripConfigHistory',
        props: { config: { type: Object, default: () => ({}) } },
        data: () => ({ open: false }),
        render() {
            const h = Vue.h;
            const config = this.config || {};
            const count = Math.max(0, Number(config.history_count || 0));
            if (!count) return null;
            const items = Array.isArray(config.history_items) ? config.history_items : [];
            const displayTime = (value) => {
                const text = String(value || '').trim();
                return text ? text.replace('T', ' ').slice(0, 16) : '时间未记录';
            };
            const roomText = (value) => (
                value === null || value === undefined || value === '' ? '未记录' : `${value} 间`
            );
            const toggle = h('button', {
                type: 'button',
                class: 'inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300',
                'data-testid': 'ctrip-config-history-trigger',
                'aria-expanded': this.open ? 'true' : 'false',
                'aria-label': `${this.open ? '收起' : '展开'} ${count} 条配置历史`,
                onClick: (event) => {
                    event?.stopPropagation?.();
                    this.open = !this.open;
                },
            }, [
                '历史 ',
                h('strong', { class: 'font-semibold text-slate-800' }, String(count)),
                ' 条',
                h('i', {
                    class: `fas ${this.open ? 'fa-chevron-up' : 'fa-chevron-right'} ml-1 text-[10px] text-slate-400`,
                    'aria-hidden': 'true',
                }),
                this.open ? h('span', { class: 'ml-1 text-[11px] text-slate-400' }, '收起') : null,
            ]);
            if (!this.open) return toggle;
            const rows = items.map((item, index) => h('div', {
                key: item?.id || `history-${index}`,
                class: 'grid gap-1 rounded-md bg-white px-3 py-2 sm:grid-cols-[9rem_5rem_minmax(0,1fr)]',
            }, [
                h('span', { class: 'whitespace-nowrap text-slate-500' }, displayTime(item?.update_time)),
                h('span', { class: 'font-medium text-slate-700' }, item?.status_label || '旧配置'),
                h('span', { class: 'min-w-0 text-slate-600' }, [
                    `携程酒店ID ${item?.ctrip_hotel_id || '未记录'}`,
                    ` · 本店 ${roomText(item?.hotel_room_count)}`,
                    ` · 竞争圈 ${roomText(item?.competitor_room_count)}`,
                ]),
            ]));
            return h('div', { class: 'basis-full' }, [
                toggle,
                h('section', {
                    class: 'mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs',
                    'data-testid': 'ctrip-config-history-panel',
                }, [
                    h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                        h('strong', { class: 'text-sm text-slate-800' }, `配置历史（${count} 条）`),
                        h('span', { class: 'text-slate-400' }, '仅展示非敏感摘要'),
                    ]),
                    rows.length
                        ? h('div', { class: 'max-h-64 space-y-2 overflow-y-auto' }, rows)
                        : h('p', { class: 'rounded-md bg-white px-3 py-2 text-slate-500' }, '历史明细暂不可用，请刷新后重试。'),
                ]),
            ]);
        },
    };

    const delegateToFull = key => (...args) => {
        const helper = window.SUXI_CTRIP_STATIC_FULL?.[key];
        if (typeof helper !== 'function') {
            throw new Error(`携程完整静态能力尚未加载：${key}`);
        }
        return helper(...args);
    };
    const api = {
        CtripConfigHistory,
        ctripProfilePrimaryCategoryOptions,
        ctripProfileDefaultModuleOptions,
        ctripProfileForbiddenFieldKeys,
        ctripProfileForbiddenFieldAssets,
        createCtripProfileModuleForm,
        normalizeCtripProfileModuleRow,
        ctripProfileModulePageUrl,
        ctripProfileModulePageDisplay,
        normalizeCtripProfileFieldVerificationStatus,
        ctripProfileFieldVerificationText,
        ctripProfileFieldVerificationBadgeClass,
        ctripProfileFieldVerificationLightClass,
        ctripOverviewApiKeywords,
        ctripFlowOverviewApiGroups,
        ctripFlowOverviewDefaultRequestUrls,
        defaultCtripAdsEffectReportUrl,
        ctripAdsApiUrlHint,
        isCtripAdsApiUrl,
        normalizeCtripAdsApiType,
        createCtripFetchForm,
        createCtripConfigForm,
        createCtripTrafficForm,
        extractCtripRealtimeTrafficSnapshot,
        extractCtripRealtimeTrafficRank,
        createCtripAdsBrowserCaptureForm,
        createCtripOverviewForm,
        createCtripFlowOverviewForm,
        createCtripBrowserCaptureForm,
        createCtripCookieApiForm,
        createCtripEndpointEvidenceForm,
        createCtripCommentForm,
        createCtripCommentBrowserCaptureForm,
        createCtripProfileFieldForm,
        buildCtripProfileFieldSampleHelpers,
        buildCtripProfileFieldDerivationHelpers,
        buildLatestCtripSnapshotModel,
        buildTruthfulCtripDisplayModel,
        isCtripLatestRequestCurrent,
    };
    const fullOnlyHelperKeys = [
        'buildCtripPublicProfileRoomCountPatch',
        'buildCtripConfigFormForHotel',
        'buildCtripBookmarkletSuccessState',
        'buildCtripBookmarkletFailureState',
        'buildCtripBatchDeleteConfigResultState',
        'buildCookieConfigRowKey',
        'buildCookieConfigDeleteSuccessState',
        'buildCookieConfigDeleteFailureState',
        'buildCookieConfigBatchDeleteSuccessState',
        'buildCookieConfigBatchDeleteFailureState',
        'buildCtripConfigSavePayload',
        'validateCtripConfigSaveInput',
        'runCtripConfigSaveFlow',
        'runCtripManualTabSwitch',
        'isCtripRankingFormAlignedWithConfig',
        'buildCtripProfileFieldSmartDefaults',
        'buildCtripProfileFieldSavePayload',
        'normalizeCtripBrowserCaptureSections',
        'buildCtripBrowserCaptureTargetContext',
        'buildCtripBrowserCapturePayload',
        'buildCtripBrowserCaptureRequestContext',
        'normalizeCtripBrowserCaptureErrorResult',
        'buildCtripSessionProofNotice',
        'runCtripBrowserCaptureFlow',
        'buildCtripFetchDateRange',
        'resolveCtripExecutionConfigId',
        'isCtripExecutionConfigReady',
        'buildCtripCookieApiConfigReadiness',
        'buildCtripManualCredentialState',
        'normalizeCtripExecutionRequestUrls',
        'normalizeCtripTemporaryCookie',
        'isCtripTemporaryCookieQuery',
        'buildCtripFetchRequestBody',
        'buildCtripFetchRequestContext',
        'selectCtripFetchResponsePayload',
        'resolveCtripPlatformHotelIdFromConfig',
        'buildCtripFetchMeta',
        'buildCtripFetchRawFailureResult',
        'runCtripFetchDataFlow',
        'buildCtripFullChannelRoomNightScenario',
        'attachCtripFullChannelRoomNightScenario',
        'resolveCtripChannelOrderEstimateAvailability',
        'buildCtripChannelOrderBreakdown',
        'attachCtripChannelOrderBreakdown',
        'buildCtripTrafficFetchRequestBody',
        'buildCtripTrafficResponseModel',
        'runCtripTrafficFetchFlow',
        'buildCtripOverviewFetchRequestBody',
        'runCtripOverviewFetchFlow',
        'buildCtripAdsFetchRequestBody',
        'runCtripAdsFetchFlow',
        'buildCtripCookieApiFetchRequestBody',
        'runCtripCookieApiCaptureFlow',
        'ctripSortMetricValue',
        'buildCtripSortedHotelRows',
        'buildCtripOverviewMetricCards',
        'buildCtripOverviewTopRankTables',
        'buildCtripFlowOverviewMetricCards',
        'buildCtripFlowOverviewInterfaceRows',
        'buildCtripProfileRecheckInitialState',
        'buildCtripProfileRecheckRunContext',
        'buildCtripProfileRecheckCaptureRefreshState',
        'buildCtripProfileRecheckSuccessResult',
        'buildCtripProfileRecheckErrorResult',
        'buildCtripProfileRecheckInterruptedState',
        'runCtripProfileRecheckFlow',
        'getCtripCookieApiCorePresetEndpoints',
        'buildCtripBusinessCanvas',
    ];
    fullOnlyHelperKeys.forEach(key => {
        if (!Object.prototype.hasOwnProperty.call(api, key)) api[key] = delegateToFull(key);
    });

    window.SUXI_CTRIP_STATIC = Object.freeze(api);
})();
