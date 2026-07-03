window.SUXI_CTRIP_STATIC = (() => {
    const ctripProfilePrimaryCategoryOptions = ['流量转化数据', '经营收益数据', '服务质量数据', '竞争力数据'];
    const ctripProfileDefaultModuleOptions = [
        { value: 'business_overview', label: '经营报告-概要-日报', page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', primary_category: '经营收益数据' },
        { value: 'business_weekly_overview', label: '经营报告-概要-周报', page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true', primary_category: '经营收益数据' },
        { value: 'sales_report', label: '经营报告-销售数据', page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', primary_category: '经营收益数据' },
        { value: 'traffic_report', label: '经营报告-流量数据', page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true', primary_category: '流量转化数据' },
        { value: 'comment_review', label: '点评数据', page_url: 'https://ebooking.ctrip.com/comment/commentList?microJump=true', primary_category: '服务质量数据' },
        { value: 'competitor_overview', label: '竞争圈动态-竞争圈概览', page_url: 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', primary_category: '竞争力数据' },
        { value: 'loss_analysis', label: '竞争圈动态-流失分析', page_url: 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/lossanalysis?microJump=true', primary_category: '竞争力数据' },
        { value: 'competitor_rank', label: '竞争圈动态-竞争圈榜单', page_url: 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionlist?microJump=true', primary_category: '竞争力数据' },
        { value: 'quality_psi', label: 'PSI服务质量', page_url: 'https://ebooking.ctrip.com/toolcenter/psi/index?microJump=true', primary_category: '服务质量数据' },
        { value: 'market_calendar', label: '市场分析-市场热度', page_url: 'https://ebooking.ctrip.com/ebkgrowth/datacenter/marketanalysis/marketheat?microJump=true', primary_category: '竞争力数据' },
        { value: 'user_profile', label: '用户行为/点评分析', page_url: 'https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true', primary_category: '流量转化数据' },
        { value: 'im_board', label: '用户行为-IM看板', page_url: 'https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im', primary_category: '服务质量数据' },
        { value: 'ads_pyramid', label: '金字塔广告', page_url: 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true', primary_category: '流量转化数据' },
    ];
    const ctripProfileForbiddenFieldKeys = ['guest_phone', 'order_phone', 'room_status', 'room_source_mapping'];
    const ctripProfileForbiddenFieldAssets = [
        { key: 'guest_phone', label: '客人手机号' },
        { key: 'order_phone', label: '订单手机号' },
        { key: 'room_status', label: '房态明细' },
        { key: 'room_source_mapping', label: '房源映射' },
    ];
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
    const ctripOverviewApiKeywords = [
        'getDayReportRealTimeDate',
        'fetchMarketOverViewV2',
        'getDayReportFlowCompete',
        'getDayReportServerQuantity',
        'fetchCurrentHotelSeqInfoV1',
        'fetchVisitorTitleV2',
        'fetchCapacityOverViewV4',
        'queryFlowTransforNewV1',
        'getReportSuggestV1',
        'getCompeteHotelReportV1',
        'getHotWordsV1',
        'getHotHotelsV1',
        'getFlowHotelsV1',
        'getHotRoomsV1',
        'getUserBehaviorV1',
        'getUserBehavorV1',
        'getTrafficReportV1',
        'getWeekSuggestionV1',
        'getLastWeekReportV1',
    ];
    const ctripFlowOverviewApiGroups = [
        { keyword: 'getDayReportRealTimeDate', scope: '经营概况', note: '日报日期与实时日期' },
        { keyword: 'getDayReportFlowCompete', scope: '竞品流量', note: '竞品流量与竞争圈概览' },
        { keyword: 'fetchCurrentHotelSeqInfoV1', scope: '当前酒店', note: '当前酒店序列与基础上下文' },
        { keyword: 'fetchCapacityOverViewV4', scope: '经营概况', note: '库存、容量与经营概览' },
        { keyword: 'fetchVisitorTitleV2', scope: '访客标题', note: '访客画像/标题类指标' },
        { keyword: 'fetchMarketOverViewV2', scope: '市场概况', note: '市场与商圈概览' },
        { keyword: 'queryFlowTransforNewV1', aliases: ['queryFlowTransforNewV1', 'queryFlowTransforNew'], scope: '流量漏斗', note: '曝光、详情、下单、成交链路' },
        { keyword: 'queryScanFlowDetailsV2', scope: '流量明细', note: '流量明细列表' },
        { keyword: 'queryHomePageRealTimeData', scope: '实时流量', note: '首页实时流量指标' },
        { keyword: 'getDayReportCompeteHotelReport', scope: '竞对日报', note: '竞对日报与榜单相关指标' },
        { keyword: 'getDayReportServerQuantity', scope: '服务质量', note: '服务质量与 PSI 相关指标' },
        { keyword: 'getFlowData', scope: '流量数据', note: '流量数据接口' },
        { keyword: 'getTrafficData', scope: '流量数据', note: '流量数据接口' },
        { keyword: 'getStatData', scope: '统计数据', note: '统计汇总接口' },
    ];
    const ctripFlowOverviewDefaultRequestUrls = [
        'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
        'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportFlowCompete',
        'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportServerQuantity',
        'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
    ];
    const defaultCtripConfigUrl = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
    const defaultCtripAdsEffectReportUrl = 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE';
    const ctripAdsApiUrlHint = '接口 URL 可留空使用默认 queryCampaignReportList；如手动填写，必须是 Network 中 CPC 广告 JSON 接口 URL';
    const isCtripAdsApiUrl = (url = '') => {
        const text = String(url || '').trim().toLowerCase();
        return text.includes('pyramidad')
            || text.includes('promotion')
            || text.includes('/toolcenter/api/cpc/')
            || text.includes('querycampaignreportlist');
    };
    const normalizeCtripAdsApiType = (value = '') => 'effect_report';
    const runPostFetchRefresh = (callback, ...args) => {
        try {
            Promise.resolve(callback(...args)).catch(error => {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[ctrip-static] post-fetch refresh failed:', error);
                }
            });
        } catch (error) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[ctrip-static] post-fetch refresh failed:', error);
            }
        }
    };
    const isCtripBackgroundAcceptedResponse = (response = {}) => {
        if (response.code !== 200) {
            return false;
        }
        const status = String(response.data?.status || '').toLowerCase();
        return ['accepted', 'running', 'queued'].includes(status);
    };
    const normalizeCtripCookieText = value => String(value || '').trim();
    const firstCtripConfigText = (...values) => {
        for (const value of values) {
            const text = String(value || '').trim();
            if (text) return text;
        }
        return '';
    };
    const hasCtripObjectValue = (value) => value && typeof value === 'object' && Object.keys(value).length > 0;
    const isSameCtripJsonObject = (left = {}, right = {}) => {
        try {
            return JSON.stringify(left || {}) === JSON.stringify(right || {});
        } catch (error) {
            return false;
        }
    };
    const isCtripRankingFormAlignedWithConfig = (form = {}, config = {}, options = {}) => {
        if (!form || !config) return false;
        const selectedHotelId = String(options.selectedHotelId || form.hotelId || '').trim();
        const configHotelId = firstCtripConfigText(config.hotel_id, config.system_hotel_id);
        if (selectedHotelId && configHotelId && selectedHotelId !== configHotelId) return false;

        const formUrl = String(form.url || '').trim();
        const configUrl = firstCtripConfigText(config.url, config.request_url, config.requestUrl);
        if (!formUrl) return false;
        if (configUrl && formUrl !== configUrl) return false;

        const formNodeId = String(form.nodeId || form.node_id || '').trim();
        const configNodeId = firstCtripConfigText(config.node_id, config.nodeId) || '24588';
        if (!formNodeId || formNodeId !== configNodeId) return false;

        const formCookies = normalizeCtripCookieText(form.cookies);
        const configCookies = normalizeCtripCookieText(config.cookies || config.cookie);
        if (!formCookies || !configCookies || formCookies !== configCookies) return false;

        if (hasCtripObjectValue(config.auth_data) && !isSameCtripJsonObject(form.auth_data || {}, config.auth_data || {})) {
            return false;
        }
        return true;
    };

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
        node_id: '24588',
        capture_sections: 'default',
        approved_mappings_path: '',
        cookies: '',
        ...overrides,
    });
    const createCtripTrafficForm = () => ({
        url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
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
    const createCtripOverviewForm = () => ({
        requestUrls: '',
        cookies: '',
        spidertoken: '',
        payloadJson: '',
        hotelId: '',
        method: 'GET',
        dataDate: '',
    });
    const createCtripFlowOverviewForm = () => ({
        requestUrls: '',
        cookies: '',
        spidertoken: '',
        payloadJson: '',
        hotelId: '',
        method: 'POST',
        dataDate: '',
    });
    const createCtripBrowserCaptureForm = () => ({
        profileId: '',
        hotelId: '',
        approvedMappingsPath: '',
        sections: 'default',
    });
    const createCtripCookieApiForm = () => ({
        profileId: '',
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
        pageUrl: 'https://ebooking.ctrip.com/comment/commentList?microJump=true',
        apiKeyword: 'getCommentList',
    });
    const buildCtripConfigSavePayload = (form = {}) => ({
        id: form.id,
        name: form.name,
        hotel_id: form.hotel_id,
        ctrip_hotel_id: form.ctrip_hotel_id,
        cookies: form.cookies,
        url: form.url,
        node_id: form.node_id,
        capture_sections: form.capture_sections,
        approved_mappings_path: form.approved_mappings_path,
    });
    const validateCtripConfigSaveInput = (form = {}) => {
        if (!form.name) {
            return { ok: false, status: 'missing_name', level: 'error', message: '请输入配置名称' };
        }
        if (!form.cookies) {
            return { ok: false, status: 'missing_cookies', level: 'error', message: '请输入临时 Cookie/API 辅助内容' };
        }
        return { ok: true, status: 'ok' };
    };
    const runCtripConfigSaveFlow = async ({
        getForm = () => ({}),
        requestSave = async () => ({}),
        notify = () => {},
        resetForm = () => {},
        reloadConfigs = () => {},
        afterSave = async () => { reloadConfigs(); },
        logError = () => {},
    } = {}) => {
        const form = getForm() || {};
        const validation = validateCtripConfigSaveInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation };
        }
        const requestBody = buildCtripConfigSavePayload(form);
        try {
            const res = await requestSave(requestBody);
            if (res.code === 200) {
                notify('配置保存成功');
                resetForm(createCtripConfigForm());
                await afterSave({ response: res, requestBody });
                return { status: 'success', response: res, requestBody };
            }

            logError('携程配置保存失败:', res?.message || res?.msg || '接口返回异常');
            notify(res.message || res.msg || '保存失败，请重试', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            logError('保存失败:', error);
            let errorMsg = error.message || '未知错误';
            if (error.response) {
                try {
                    const errData = await error.response.json();
                    errorMsg = errData.message || errData.msg || errorMsg;
                } catch (ignored) {}
            }
            notify('保存失败: ' + errorMsg, 'error');
            return { status: 'exception', error, requestBody };
        }
    };
    const runCtripManualTabSwitch = async ({
        tab = '',
        getCurrentPage = () => '',
        getCurrentTab = () => '',
        loadDataHealthPanel = async () => {},
        loadConfigList = async () => {},
        applyHotelConfig = async () => {},
        syncAdsConfig = async () => {},
        hasSelectedHotel = () => false,
    } = {}) => {
        const isActive = () => getCurrentPage() === 'ctrip-ebooking' && getCurrentTab() === tab;
        if (!isActive()) {
            return { status: 'stale_before_load', tab };
        }

        if (tab === 'data-health') {
            await loadDataHealthPanel('light');
            return isActive()
                ? { status: 'synced', tab, target: 'data-health' }
                : { status: 'stale_after_load', tab };
        }

        if (!['ctrip-flow-overview', 'ctrip-fetch-settings', 'ctrip-ads'].includes(tab)) {
            return { status: 'noop', tab };
        }

        await loadConfigList();
        if (!isActive()) {
            return { status: 'stale_after_load', tab };
        }

        if (hasSelectedHotel()) {
            await applyHotelConfig(false, {
                refreshList: false,
                skipIfAligned: true,
            });
            if (!isActive()) {
                return { status: 'stale_after_apply', tab };
            }
        }

        if (tab === 'ctrip-ads') {
            await syncAdsConfig(false);
            return isActive()
                ? { status: 'synced', tab, target: 'ads' }
                : { status: 'stale_after_sync', tab };
        }

        return { status: 'synced', tab, target: 'config' };
    };
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
    const ctripProfileSimpleHash = (value) => {
        const text = String(value || '');
        let hash = 0;
        for (let i = 0; i < text.length; i += 1) {
            hash = ((hash << 5) - hash + text.charCodeAt(i)) | 0;
        }
        return Math.abs(hash).toString(36).padStart(6, '0').slice(0, 8);
    };
    const ctripProfileFieldKeyFromText = (value) => {
        const text = String(value || '').trim().toLowerCase();
        const slug = text.replace(/[^a-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
        return slug || `custom_${ctripProfileSimpleHash(text || Date.now())}`;
    };
    const inferCtripProfileSectionByPageUrl = (pageUrl, fallback = 'business_overview') => {
        const url = String(pageUrl || '').trim().toLowerCase();
        if (!url) return fallback || 'business_overview';
        if (url.includes('/datacenter/inland/businessreport/outline')) return 'business_overview';
        if (url.includes('/datacenter/inland/businessreport/weekreport')) return 'business_weekly_overview';
        if (url.includes('/datacenter/inland/businessreport/beneficialdata')) return 'sales_report';
        if (url.includes('/datacenter/inland/businessreport/flowdata')) return 'traffic_report';
        if (url.includes('/toolcenter/cpc/') || url.includes('/advertise/cpc/') || url.includes('/pyramidad/')) return 'ads_pyramid';
        if (url.includes('/toolcenter/psi/index') || url.includes('/psi/index')) return 'quality_psi';
        return fallback || 'business_overview';
    };
    const ctripProfileEndpointFromUrl = (url) => {
        const text = String(url || '').trim();
        if (!text) return '';
        return text.split('?')[0].split('#')[0].split('/').filter(Boolean).pop() || '';
    };
    const ctripProfileSourceKeyFromPath = (value) => {
        const text = String(value || '').trim();
        if (!text) return '';
        const cleaned = text
            .replace(/\[['"]?([^'"\]]+)['"]?\]/g, '.$1')
            .replace(/\[\d+\]/g, '')
            .replace(/^\$?\.+/, '');
        return cleaned.split(/[.\s,;/|]+/).filter(Boolean).pop() || cleaned;
    };
    const inferCtripProfileValueType = (form, sourceKey) => {
        const text = [
            sourceKey,
            form.field_key,
            form.field_name,
            form.value_meaning,
            form.target_value,
        ].join(' ').toLowerCase();
        if (/(rank|排名)/i.test(text)) return 'rank';
        if (/(rate|ratio|percent|转化率|回复率|成交率|入住率)/i.test(text)) return 'percent';
        if (/(amount|price|cost|fee|收入|销售额|金额|房价|卖价|花费|成本)/i.test(text)) return 'amount';
        if (/(score|评分|分数)/i.test(text)) return 'number';
        if (/(count|num|quantity|visitor|uv|pv|订单|间夜|访客|曝光|收藏|人数|数量)/i.test(text)) return 'integer';
        return '';
    };
    const ctripProfileUnitForValueType = (valueType, form = {}) => {
        const text = [form.unit, form.field_name, form.value_meaning, form.target_value].join(' ');
        if (String(form.unit || '').trim()) return String(form.unit || '').trim();
        if (valueType === 'rank') return '名';
        if (valueType === 'percent') return '%';
        if (valueType === 'amount') return '元';
        if (/访客|人数/.test(text)) return '人';
        if (/订单/.test(text)) return '单';
        if (/间夜/.test(text)) return '间夜';
        if (/曝光|浏览/.test(text)) return '次';
        return '';
    };
    const ctripProfileStorageFieldForKey = (fieldKey, section = '') => {
        const key = String(fieldKey || '').trim();
        if (!key) return '';
        const known = {
            order_amount: 'online_daily_data.amount',
            room_nights: 'online_daily_data.quantity',
            order_count: 'online_daily_data.book_order_num',
            avg_price: 'online_daily_data.data_value',
            close_rate: 'online_daily_data.raw_data.flow_rate',
            visitor_count: 'ota_ctrip_metric_facts.metric_key=visitor_count',
            flow_rate: 'ota_ctrip_metric_facts.metric_key=flow_rate',
            list_exposure: 'ota_ctrip_metric_facts.metric_key=list_exposure',
            detail_visitor: 'ota_ctrip_metric_facts.metric_key=detail_visitor',
        };
        if (known[key]) return known[key];
        if (String(section || '').includes('traffic')) return `ota_ctrip_metric_facts.metric_key=${key}`;
        return `ota_ctrip_metric_facts.metric_key=${key}`;
    };
    const buildCtripProfileFieldSmartDefaults = (source = {}) => {
        const form = { ...(source || {}) };
        const sourceKey = String(form.target_value || form.target_field || form.source_keys || ctripProfileSourceKeyFromPath(form.json_path)).trim();
        const section = inferCtripProfileSectionByPageUrl(form.page_url, form.section || 'business_overview');
        const fieldKey = String(form.field_key || ctripProfileFieldKeyFromText(sourceKey || form.value_meaning || form.page_url)).trim();
        const endpoint = String(form.source_interface || ctripProfileEndpointFromUrl(form.request_url)).trim();
        const valueType = String(form.value_type || inferCtripProfileValueType({ ...form, field_key: fieldKey }, sourceKey)).trim();
        return {
            section,
            sourceKey,
            fieldKey,
            endpoint,
            valueType,
            unit: ctripProfileUnitForValueType(valueType, form),
            storageField: String(form.storage_field || ctripProfileStorageFieldForKey(fieldKey, section)).trim(),
        };
    };
    const buildCtripProfileFieldSavePayload = (source = {}) => {
        const form = { ...(source || {}) };
        const inferred = buildCtripProfileFieldSmartDefaults(form);
        form.section = inferred.section || form.section || 'business_overview';
        const targetValue = String(form.target_value || form.target_field || form.source_keys || '').trim();
        const valueMeaning = String(form.value_meaning || form.field_name || '').trim();
        const pageUrl = String(form.page_url || '').trim();
        const sourceKey = String(targetValue || inferred.sourceKey || '').trim();

        if (!String(form.field_key || '').trim()) {
            form.field_key = inferred.fieldKey || ctripProfileFieldKeyFromText(sourceKey || valueMeaning || pageUrl);
        }
        if (!String(form.field_name || '').trim()) {
            form.field_name = valueMeaning || sourceKey;
        }
        if (!String(form.source_interface || '').trim() && inferred.endpoint) {
            form.source_interface = inferred.endpoint;
        }
        if (!String(form.source_keys || '').trim() && sourceKey) {
            form.source_keys = sourceKey;
        }
        if (!String(form.target_value || '').trim() && sourceKey) {
            form.target_value = sourceKey;
        }
        if (!String(form.target_field || '').trim() && sourceKey) {
            form.target_field = sourceKey;
        }
        if (!String(form.value_type || '').trim() && inferred.valueType) {
            form.value_type = inferred.valueType;
        }
        if (!String(form.unit || '').trim() && inferred.unit) {
            form.unit = inferred.unit;
        }
        if (!String(form.storage_field || '').trim() && inferred.storageField) {
            form.storage_field = inferred.storageField;
        }
        if (!String(form.status || '').trim() || form.status === 'pending') {
            form.status = form.id ? form.status : 'needs_parser';
        }
        return form;
    };
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
                    .map(sample => {
                        if (sample && typeof sample === 'object') return sample;
                        return { value: sample };
                    })
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
            if (batchKey) {
                return items.filter(sample => sampleBatchKey(sample) === batchKey);
            }
            const latestBatchKey = items
                .map(sampleBatchKey)
                .filter(Boolean)[0] || '';
            if (latestBatchKey) return items.filter(sample => sampleBatchKey(sample) === latestBatchKey);
            return items.slice(0, 3);
        };
        const displaySampleItems = (field, currentBatchKey = '') => {
            const latestBatchItems = latestBatchSampleItems(field, currentBatchKey);
            if (latestBatchItems.length > 0) return latestBatchItems;
            return sampleItems(field).slice(0, 3);
        };
        const hasOnlyHistoricalSamples = (field, currentBatchKey = '') => (
            sampleItems(field).length > 0
            && latestBatchSampleItems(field, currentBatchKey).length === 0
        );
        const previewSampleItems = (field, currentBatchKey = '') => displaySampleItems(field, currentBatchKey).slice(0, 1);
        const latestBatchSampleCount = (field, currentBatchKey = '') => latestBatchSampleItems(field, currentBatchKey).length;
        const displaySampleCount = (field, currentBatchKey = '') => displaySampleItems(field, currentBatchKey).length;
        const latestSampleTime = (field, currentBatchKey = '') => {
            const times = displaySampleItems(field, currentBatchKey)
                .map(sampleCapturedAt)
                .filter(Boolean)
                .sort()
                .reverse();
            return times[0] || '';
        };
        const fieldSampleSourceText = (field, currentBatchKey = '') => {
            const sample = displaySampleItems(field, currentBatchKey)[0] || sampleItems(field)[0];
            return sampleSourceText(sample);
        };
        const sampleSelectionKey = (sample) => {
            if (!sample || typeof sample !== 'object') {
                return String(sample || '').trim();
            }
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
                key: 'standard',
                label: '标准字段',
                value: fieldVisibleCount,
                badge: fieldVisibleCount === fieldTotalCount ? '配置表' : '当前展示',
                className: 'bg-slate-100 text-slate-700',
                detail: visibleDetail || buildVisibleDetail({ visibleCount: fieldVisibleCount, totalCount: fieldTotalCount }),
            },
            {
                key: 'capture_target',
                label: '应抓字段',
                value: enabledVisibleFieldCount,
                badge: '启用',
                className: 'bg-blue-100 text-blue-700',
                detail: '当前展示中启用且非禁止采集的字段',
            },
            {
                key: 'capture_success',
                label: '已抓到',
                value: sampleLoading ? '加载中' : (samplesLoaded ? sampledVisibleFieldCount : '未加载'),
                badge: '有值',
                className: samplesLoaded ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600',
                detail: '当前展示启用字段已有历史获取值',
            },
            {
                key: 'stable',
                label: '稳定字段',
                value: stableVisibleFieldCount,
                badge: '已确认',
                className: 'bg-emerald-100 text-emerald-700',
                detail: '当前展示字段已确认或样例相符',
            },
            {
                key: 'not_returned',
                label: '未返回/失败',
                value: sampleLoading ? '加载中' : (samplesLoaded ? notReturnedVisibleFieldCount : '未加载'),
                badge: samplesLoaded ? '需复核' : '待加载',
                className: samplesLoaded ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600',
                detail: '当前展示启用字段暂无历史获取值，需区分接口未触发或字段未入库',
            },
            {
                key: 'forbidden',
                label: '禁止采集',
                value: forbiddenFieldCount,
                badge: '边界',
                className: 'bg-red-100 text-red-700',
                detail: '手机号、房态、房源映射不进表',
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
    const normalizeCtripBrowserCaptureSections = (sections, fallbackSections = 'default') => {
        const sectionSource = Array.isArray(sections)
            ? sections
            : (String(sections || '').trim()
                ? String(sections).split(/[,\s]+/)
                : (Array.isArray(fallbackSections) ? fallbackSections : String(fallbackSections || 'default').split(/[,\s]+/)));
        const normalized = (sectionSource.length ? sectionSource : ['default'])
            .map(item => String(item || '').trim())
            .filter(Boolean);
        return normalized.length ? normalized : ['default'];
    };
    const ctripBrowserProfileMissingMessage = '请填写携程登录会话标识，或先绑定携程登录会话数据源';
    const buildCtripBrowserCaptureTargetContext = ({
        selectedCtripHotelId = '',
        autoFetchHotelId = '',
        userHotelId = '',
    } = {}) => {
        const systemHotelId = selectedCtripHotelId || autoFetchHotelId || userHotelId || null;
        if (!systemHotelId) {
            return {
                ok: false,
                systemHotelId: null,
                result: { code: 400, message: '请选择目标酒店' },
            };
        }
        return { ok: true, systemHotelId };
    };
    const buildCtripBrowserCapturePayload = ({
        systemHotelId = '',
        hotelId = '',
        hotelName = '',
        profileId = '',
        cookies = '',
        dataDate = '',
        form = {},
        options = {},
    } = {}) => {
        const optionSections = options.sections || options.captureSections || '';
        return {
            system_hotel_id: systemHotelId,
            hotel_id: hotelId,
            hotel_name: hotelName,
            profile_id: profileId,
            cookies,
            data_date: dataDate,
            sections: normalizeCtripBrowserCaptureSections(optionSections, form.sections),
            login_only: Boolean(options.loginOnly),
            bind_data_source: options.bindDataSource !== false,
            approved_mappings_path: String(form.approvedMappingsPath || '').trim(),
        };
    };
    const buildCtripBrowserCaptureRequestContext = ({
        systemHotelId = '',
        activeConfig = null,
        form = {},
        overviewForm = {},
        hotelName = '',
        profileId = '',
        options = {},
    } = {}) => {
        const hotelId = String(
            form.hotelId
            || activeConfig?.ota_hotel_id
            || activeConfig?.ctrip_hotel_id
            || activeConfig?.ctripHotelId
            || overviewForm.hotelId
            || ''
        ).trim();
        if (!profileId) {
            return {
                ok: false,
                systemHotelId,
                hotelId,
                profileId: '',
                result: { code: 400, message: ctripBrowserProfileMissingMessage },
            };
        }
        const capturePayload = buildCtripBrowserCapturePayload({
            systemHotelId,
            hotelId,
            hotelName,
            profileId,
            cookies: activeConfig?.cookies || activeConfig?.cookie || '',
            dataDate: overviewForm.dataDate,
            form,
            options,
        });
        return {
            ok: true,
            systemHotelId,
            hotelId,
            profileId,
            capturePayload,
        };
    };
    const normalizeCtripBrowserCaptureErrorResult = (error) => {
        const data = error?.data?.data || {};
        const partial = data.partial_capture || {};
        if (partial && partial.available) {
            return {
                ...partial,
                error: error.message,
                stdout: data.stdout || '',
                stderr: data.stderr || '',
                partial_capture: partial,
            };
        }
        return data && Object.keys(data).length ? { ...data, error: error.message } : { error: error.message };
    };
    const runCtripBrowserCaptureFlow = async ({
        options = {},
        getSelectedCtripHotelId = () => '',
        setSelectedCtripHotelId = () => {},
        getAutoFetchHotelId = () => '',
        getUserHotelId = () => '',
        hasCtripConfigList = () => false,
        loadCtripConfigList = async () => {},
        getActiveCtripConfig = () => null,
        findCtripConfigByHotelId = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        getBrowserCaptureForm = () => ({}),
        getOverviewForm = () => ({}),
        getHotelNameById = () => '',
        resolveProfileId = () => '',
        requestCapture = async () => ({}),
        setRunning = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        setShowRawData = () => {},
        setCookieApiProfileId = () => {},
        setProfileStatus = () => {},
        notify = () => {},
        refreshLatestCtripData = async () => {},
        refreshOnlineHistory = async () => {},
        shouldRefreshDataHealthPanel = () => false,
        refreshDataHealthPanel = async () => {},
        refreshPlatformProfileStatus = async () => {},
        refreshPlatformDataSources = async () => {},
        normalizeError = normalizeCtripBrowserCaptureErrorResult,
    } = {}) => {
        const loginOnly = Boolean(options.loginOnly);
        const silent = Boolean(options.silent);
        const targetContext = buildCtripBrowserCaptureTargetContext({
            selectedCtripHotelId: getSelectedCtripHotelId(),
            autoFetchHotelId: getAutoFetchHotelId(),
            userHotelId: getUserHotelId(),
        });
        if (!targetContext.ok) {
            if (!silent) notify(targetContext.result.message, 'error');
            return targetContext.result;
        }
        const { systemHotelId } = targetContext;

        if (!getSelectedCtripHotelId()) {
            setSelectedCtripHotelId(String(systemHotelId));
        }
        if (!hasCtripConfigList()) {
            await loadCtripConfigList();
        }
        let activeConfig = getActiveCtripConfig();
        if (!activeConfig || String(activeConfig.hotel_id || activeConfig.system_hotel_id || '') !== String(systemHotelId)) {
            activeConfig = findCtripConfigByHotelId(systemHotelId);
        }
        activeConfig = await ensureCtripConfigSecret(activeConfig);
        if (activeConfig) {
            applyCtripConfigObject(activeConfig);
        }

        const requestContext = buildCtripBrowserCaptureRequestContext({
            systemHotelId,
            activeConfig,
            form: getBrowserCaptureForm(),
            overviewForm: getOverviewForm(),
            hotelName: getHotelNameById(systemHotelId),
            profileId: resolveProfileId(activeConfig),
            options,
        });
        if (!requestContext.ok) {
            if (!silent) notify(requestContext.result.message, 'error');
            return requestContext.result;
        }
        const { capturePayload, profileId } = requestContext;

        setRunning(true);
        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestCapture(capturePayload);
            if (res.code === 200) {
                setCaptureResult(res.data || {});
                setOnlineDataResult(res.data || {});
                setShowRawData(false);
                if (loginOnly) {
                    const nextProfileId = res.data?.profile_id || profileId;
                    setCookieApiProfileId(nextProfileId);
                    setProfileStatus({
                        profile_id: nextProfileId,
                        exists: true,
                        status: 'profile_found',
                        cookie_probe_requested: false,
                        next_action: '已保存 Profile，可继续检查 Cookie 或执行 Cookie API 诊断采集',
                    });
                }
                if (!silent) {
                    const savedCount = Number(res.data?.saved_count || 0);
                    const profileCaptureMessage = loginOnly
                        ? 'Profile 登录已保存'
                        : `${res.message || '携程 Profile 采集完成'}：已入库 ${savedCount} 条；字段覆盖按配置表显示，未返回字段保留为缺口`;
                    notify(profileCaptureMessage);
                }
                if (!loginOnly) {
                    runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (shouldRefreshDataHealthPanel()) {
                        runPostFetchRefresh(refreshDataHealthPanel, 'light', { force: true });
                    }
                }
                if (loginOnly || options.bindDataSource !== false) {
                    runPostFetchRefresh(refreshPlatformProfileStatus, { silent: true });
                    if (options.bindDataSource !== false) runPostFetchRefresh(refreshPlatformDataSources);
                }
                return res;
            }
            if (!silent) notify(res.message || '携程 Profile 采集失败', 'error');
            return res;
        } catch (error) {
            const detail = error?.data?.data?.stderr || error?.data?.data?.stdout || '';
            if (!silent) notify('携程 Profile 采集失败: ' + error.message + (detail ? '，请查看结果详情' : ''), 'error');
            const normalizedError = normalizeError(error);
            setCaptureResult(normalizedError);
            return { code: 500, message: error.message, data: normalizedError };
        } finally {
            setRunning(false);
            setFetching(false);
        }
    };

    const formatCtripFetchDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    const buildCtripFetchDateRange = (form = {}, now = new Date()) => {
        let startDate = form.startDate;
        let endDate = form.endDate;
        if (!startDate || !endDate) {
            const yesterday = new Date(now.getTime());
            yesterday.setDate(yesterday.getDate() - 1);
            const yesterdayStr = formatCtripFetchDate(yesterday);
            startDate = yesterdayStr;
            endDate = yesterdayStr;
        }
        return { startDate, endDate };
    };

    const buildCtripFetchRequestBody = ({
        form = {},
        cookies = '',
        nodeId = '',
        startDate = '',
        endDate = '',
        systemHotelId = null,
    } = {}) => {
        const requestUrl = String(form.url || '').trim();
        const body = {
            cookies,
            auth_data: form.auth_data || {},
            start_date: startDate,
            end_date: endDate,
            auto_save: true,
            system_hotel_id: systemHotelId || null,
        };
        if (requestUrl) {
            body.url = requestUrl;
        }
        if (nodeId) {
            body.node_id = nodeId;
        }
        return body;
    };
    const buildCtripFetchRequestContext = ({
        form = {},
        selectedCtripHotelId = '',
    } = {}) => {
        const cookies = String(form.cookies || '').trim();
        if (!cookies) {
            return {
                ok: false,
                message: '请输入临时 Cookie/API 辅助内容',
                level: 'error',
            };
        }
        const nodeId = String(form.nodeId || '').trim();
        const { startDate, endDate } = buildCtripFetchDateRange(form);
        const requestBody = buildCtripFetchRequestBody({
            form,
            cookies,
            nodeId,
            startDate,
            endDate,
            systemHotelId: selectedCtripHotelId || null,
        });
        return {
            ok: true,
            cookies,
            nodeId,
            startDate,
            endDate,
            requestBody,
            debugMeta: {
                node_id: nodeId || 'backend_default',
                start_date: startDate,
                end_date: endDate,
            },
        };
    };

    const selectCtripFetchResponsePayload = (data = {}) => {
        if (Array.isArray(data.date_results) && data.date_results.length > 1) {
            return { date_results: data.date_results };
        }
        return data.data;
    };

    const buildCtripFetchMeta = ({
        hotelId = '',
        startDate = '',
        endDate = '',
        fetchedAt = '',
        savedCount = 0,
        displayHotelCount = 0,
    } = {}) => ({
        hotel_id: hotelId || '',
        platform: 'ctrip',
        data_source: '携程 ebooking',
        status: 'success',
        status_label: '成功',
        data_date: startDate === endDate ? startDate : `${startDate} 至 ${endDate}`,
        fetched_at: fetchedAt || '',
        total_records: savedCount || displayHotelCount,
    });

    const buildCtripFetchRawFailureResult = ({
        errorMsg = '获取失败',
        rawResponse = '',
        limit = 1000,
    } = {}) => ({
        error: errorMsg,
        raw: String(rawResponse || '').substring(0, limit),
        hint: '请检查: 1.Cookie是否过期 2.API地址是否正确',
    });
    const runCtripFetchDataFlow = async ({
        isLoggedIn = () => false,
        getSelectedCtripHotelId = () => '',
        notify = () => {},
        getActiveCtripConfig = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        getForm = () => ({}),
        setFetching = () => {},
        setShowRawData = () => {},
        setFetchSuccess = () => {},
        setSavedCount = () => {},
        debugLog = () => {},
        requestFetch = async () => ({}),
        setOnlineDataResult = () => {},
        useDisplayHotels = rows => rows,
        setOnlineDataFilterDates = () => {},
        getLatestMeta = () => null,
        setLatestMeta = () => {},
        setTableTab = () => {},
        updateAiAnalysisHotelList = () => {},
        refreshOnlineHistory = async () => {},
        refreshLatestCtripData = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
        handleFetchFailure = async () => {},
        hasVisibleSnapshot = () => false,
        logError = () => {},
    } = {}) => {
        if (!isLoggedIn()) {
            notify('请先登录', 'error');
            return { status: 'not_logged_in' };
        }

        const selectedCtripHotelId = getSelectedCtripHotelId();
        const selectedConfig = selectedCtripHotelId
            ? await ensureCtripConfigSecret(getActiveCtripConfig())
            : null;
        let form = getForm() || {};
        if (selectedConfig && !isCtripRankingFormAlignedWithConfig(form, selectedConfig, { selectedHotelId: selectedCtripHotelId })) {
            applyCtripConfigObject(selectedConfig);
            form = getForm() || form;
        }

        const requestContext = buildCtripFetchRequestContext({
            form,
            selectedCtripHotelId,
        });
        if (!requestContext.ok) {
            notify(requestContext.message, requestContext.level || 'error');
            return { status: 'invalid_request', requestContext };
        }
        const { startDate, endDate } = requestContext;

        setFetching(true);
        setShowRawData(false);
        setFetchSuccess(false);
        setSavedCount(0);

        try {
            debugLog('发送携程数据请求...', requestContext.debugMeta);
            const requestBody = { ...requestContext.requestBody, async: false, background: false };
            const res = await requestFetch(requestBody);
            debugLog('携程数据响应:', res);

            const responseStatus = String(res.data?.status || '').toLowerCase();
            if (res.code === 200 && ['accepted', 'running', 'queued'].includes(responseStatus)) {
                const message = res.message || '携程手动获取已提交后台执行，完成后会更新数据列表';
                notify(message, 'info');
                setOnlineDataResult({
                    status: responseStatus,
                    message,
                    task_id: res.data?.task_id || '',
                    request_start_date: startDate,
                    request_end_date: endDate,
                });
                setSavedCount(0);
                setFetchSuccess(false);
                runPostFetchRefresh(refreshOnlineHistory);
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                if (getOnlineDataTab() === 'data') {
                    runPostFetchRefresh(refreshOnlineData);
                }
                return { status: 'accepted', response: res, requestBody };
            }

            if (res.code === 200) {
                const data = res.data || {};
                setOnlineDataResult(selectCtripFetchResponsePayload(data));
                const allHotels = useDisplayHotels(data.display_hotels || [], data.display_summary || null);
                setOnlineDataFilterDates({ startDate, endDate });
                const savedCount = data.saved_count || 0;
                setSavedCount(savedCount);
                setFetchSuccess(true);
                const currentFetchMeta = buildCtripFetchMeta({
                    hotelId: selectedCtripHotelId || '',
                    startDate,
                    endDate,
                    fetchedAt: data.fetched_at || '',
                    savedCount,
                    displayHotelCount: allHotels.length,
                });
                setLatestMeta({ ...(getLatestMeta() || {}), ...currentFetchMeta });
                setTableTab('sales');
                updateAiAnalysisHotelList();
                refreshOnlineHistory();
                refreshLatestCtripData({ silent: true });
                if (currentFetchMeta.fetched_at && (!getLatestMeta()?.fetched_at || String(getLatestMeta().fetched_at) < currentFetchMeta.fetched_at)) {
                    setLatestMeta({ ...(getLatestMeta() || {}), ...currentFetchMeta });
                }
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'success', response: res, meta: currentFetchMeta };
            }

            if (res.code === 401) {
                notify('登录已过期，请重新登录', 'error');
                return { status: 'expired', response: res };
            }

            const errorMsg = res.message || '获取失败';
            const rawResponse = res.data?.raw_response || res.data?.raw || '';
            await handleFetchFailure(errorMsg);
            if (rawResponse && !hasVisibleSnapshot()) {
                setOnlineDataResult(buildCtripFetchRawFailureResult({
                    errorMsg,
                    rawResponse,
                }));
                setShowRawData(true);
            }
            return { status: 'failed', response: res };
        } catch (error) {
            logError('携程数据请求异常:', error);
            await handleFetchFailure('请求失败: ' + error.message);
            return { status: 'error', error };
        } finally {
            setFetching(false);
        }
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
            trafficRows,
            displayTrafficRows,
            trafficDisplaySummary: traffic.display_traffic_summary || null,
            reviewRows,
            reviewResult: hasReview ? {
                data: reviewRows,
                total: review.total || reviewRows.length,
                saved_count: review.total || reviewRows.length,
            } : null,
            onlineResult: hasAnySnapshot ? {
                source: 'latest',
                metadata: payload.metadata,
                rank: payload.rank,
                traffic: payload.traffic,
                review: payload.review,
            } : null,
            hasRank,
            hasTraffic,
            hasReview,
            hasAnySnapshot,
        };
    };

    const buildCtripTrafficFetchRequestBody = ({
        form = {},
        cookies = '',
        systemHotelId = null,
    } = {}) => {
        const trafficUrl = String(form.url || '').trim();
        const body = {
            platform: form.platform,
            date_range: form.dateRange,
            start_date: form.startDate,
            end_date: form.endDate,
            cookies,
            auto_save: true,
            system_hotel_id: systemHotelId || null,
            extra_params: form.extraParams,
        };
        if (trafficUrl) {
            body.url = trafficUrl;
        }
        return body;
    };

    const buildCtripTrafficResponseModel = (data = {}) => {
        const decoded = data.decoded_data || data.data || [];
        const trafficRows = data.traffic_rows || decoded;
        const displayTrafficRows = Array.isArray(data.display_traffic_rows) ? data.display_traffic_rows : [];
        const displayTrafficSummary = data.display_traffic_summary || null;
        const derivedAnalysis = data.derived_analysis || null;
        const savedCount = data.saved_count || 0;
        return {
            decoded,
            trafficRows,
            displayTrafficRows,
            displayTrafficSummary,
            derivedAnalysis,
            savedCount,
            onlineResult: {
                http_code: data.http_code,
                saved_count: savedCount,
                platform: data.platform,
                request_start_date: data.request_start_date,
                request_end_date: data.request_end_date,
                decoded_data: decoded,
                traffic_rows: trafficRows,
                display_traffic_rows: displayTrafficRows,
                display_traffic_summary: displayTrafficSummary,
                raw_response: data.raw_response || '',
                derived_analysis: derivedAnalysis,
            },
        };
    };

    const runCtripTrafficFetchFlow = async ({
        getSelectedCtripHotelId = () => '',
        notify = () => {},
        getActiveCtripConfig = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        getForm = () => ({}),
        setFetching = () => {},
        requestFetch = async () => ({}),
        useCtripTrafficDisplayRows = rows => rows,
        setOnlineDataResult = () => {},
        refreshOnlineHistory = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
        handleFetchFailure = async () => {},
    } = {}) => {
        const selectedCtripHotelId = getSelectedCtripHotelId();
        if (!selectedCtripHotelId) {
            notify('请选择目标酒店', 'error');
            return { status: 'missing_hotel' };
        }
        const selectedConfig = await ensureCtripConfigSecret(getActiveCtripConfig());
        if (!selectedConfig) {
            notify('当前酒店未配置携程数据源', 'warning');
            return { status: 'missing_config' };
        }
        applyCtripConfigObject(selectedConfig);
        const form = getForm() || {};
        const cookies = String(form.cookies || '').trim();
        if (!cookies) {
            notify('请提供携程 Cookie', 'error');
            return { status: 'missing_cookies' };
        }
        if (form.dateRange === 'custom' && (!form.startDate || !form.endDate)) {
            notify('请选择自定义开始日期和结束日期', 'error');
            return { status: 'missing_custom_dates' };
        }

        setFetching(true);
        const requestBody = buildCtripTrafficFetchRequestBody({
            form,
            cookies,
            systemHotelId: selectedCtripHotelId || null,
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isCtripBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'ctrip',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setOnlineDataResult(runningPayload);
                notify(res.message || '携程流量手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload };
            }
            if (res.code === 200) {
                const trafficModel = buildCtripTrafficResponseModel(res.data || {});
                const rows = useCtripTrafficDisplayRows(
                    trafficModel.displayTrafficRows,
                    trafficModel.displayTrafficSummary,
                    trafficModel.trafficRows,
                    trafficModel.derivedAnalysis
                );
                setOnlineDataResult(trafficModel.onlineResult);
                const savedCount = trafficModel.savedCount;
                if (rows.length === 0) {
                    notify('当前日期范围暂无流量数据', 'warning');
                    return { status: 'empty', response: res, requestBody: directRequestBody, trafficModel, rows, savedCount };
                }

                notify(`获取成功，已保存 ${savedCount} 条流量数据`);
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'success', response: res, requestBody: directRequestBody, trafficModel, rows, savedCount };
            }

            await handleFetchFailure(res.message || '获取失败');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            await handleFetchFailure('请求失败: ' + error.message);
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const buildCtripOverviewFetchRequestBody = ({
        systemHotelId = null,
        hotelId = '',
        hotelName = '',
        cookies = '',
        requestUrls = '',
        form = {},
        defaultMethod = 'POST',
    } = {}) => ({
        system_hotel_id: systemHotelId,
        hotel_id: hotelId,
        hotel_name: hotelName,
        cookies,
        request_urls: requestUrls,
        payload_json: form.payloadJson,
        spidertoken: form.spidertoken,
        method: form.method || defaultMethod,
        data_date: form.dataDate,
    });

    const runCtripOverviewFetchFlow = async ({
        getSystemHotelId = () => null,
        notify = () => {},
        getActiveCtripConfig = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        getForm = () => ({}),
        getCtripCookies = () => '',
        getFallbackRequestUrls = () => '',
        getHotelNameById = () => '',
        setFetching = () => {},
        setGlobalFetching = () => {},
        setResult = () => {},
        setOnlineDataResult = () => {},
        setShowRawData = () => {},
        requestFetch = async () => ({}),
        refreshLatestCtripData = async () => {},
        refreshOnlineHistory = async () => {},
        defaultMethod = 'POST',
        messages = {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        if (!systemHotelId) {
            notify(messages.missingHotel || '请选择目标酒店', 'error');
            return { status: 'missing_hotel' };
        }

        const activeConfig = await ensureCtripConfigSecret(getActiveCtripConfig());
        if (!activeConfig) {
            notify(messages.missingConfig || '当前酒店未配置携程数据源', 'warning');
            return { status: 'missing_config' };
        }
        applyCtripConfigObject(activeConfig);

        const hotelId = String(activeConfig?.ota_hotel_id || activeConfig?.ctrip_hotel_id || activeConfig?.ctripHotelId || '').trim();
        const form = getForm() || {};
        form.hotelId = String(form.hotelId || hotelId || '').trim();
        form.cookies = String(form.cookies || getCtripCookies() || activeConfig.cookies || '').trim();
        form.requestUrls = String(form.requestUrls || '').trim();
        form.payloadJson = String(form.payloadJson || '').trim();
        form.spidertoken = String(form.spidertoken || '').trim();
        const requestUrls = form.requestUrls || String(getFallbackRequestUrls() || '');
        if (!requestUrls) {
            notify(messages.missingRequestUrls || '请填写接口 Request URL', 'error');
            return { status: 'missing_request_urls', form };
        }
        if (requestUrls.includes('/datacenter/inland/businessreport/outline')) {
            notify(messages.invalidPageUrl || '请填写 Network 中的 JSON 接口 URL，不是页面地址', 'error');
            return { status: 'invalid_page_url', form, requestUrls };
        }
        if (!form.cookies) {
            notify(messages.missingCookies || '请提供携程 Cookie', 'error');
            return { status: 'missing_cookies', form, requestUrls };
        }

        setFetching(true);
        setGlobalFetching(true);
        setResult(null);
        try {
            const requestBody = buildCtripOverviewFetchRequestBody({
                systemHotelId,
                hotelId: form.hotelId,
                hotelName: getHotelNameById(systemHotelId),
                cookies: form.cookies,
                requestUrls,
                form,
                defaultMethod,
            });
            const res = await requestFetch(requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setResult(data);
                setOnlineDataResult(data);
                setShowRawData(false);
                notify(res.message || `${messages.successPrefix || '携程概览获取完成'}，已入库 ${data.saved_count || 0} 条`);
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, requestBody };
            }

            notify(res.message || messages.failure || '携程概览抓取失败', 'error');
            return { status: 'failed', response: res };
        } catch (error) {
            const detail = error?.data?.data?.stderr || error?.data?.data?.stdout || '';
            notify(`${messages.exceptionPrefix || '携程概览获取失败'}: ${error.message}${detail ? '，请查看结果详情' : ''}`, 'error');
            setResult(error?.data?.data || { error: error.message });
            return { status: 'exception', error };
        } finally {
            setFetching(false);
            setGlobalFetching(false);
        }
    };

    const buildCtripAdsFetchRequestBody = ({
        systemHotelId = null,
        hotelId = '',
        hotelName = '',
        url = '',
        cookies = '',
        form = {},
    } = {}) => ({
        system_hotel_id: systemHotelId,
        hotel_id: hotelId,
        hotel_name: hotelName,
        url,
        cookies,
        api_type: normalizeCtripAdsApiType(form.apiType),
        date_range: form.dateRange,
        start_date: form.startDate,
        end_date: form.endDate,
        auto_save: true,
    });

    const runCtripAdsFetchFlow = async ({
        getSystemHotelId = () => null,
        notify = () => {},
        getActiveCtripConfig = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        syncAdsDirectConfig = async () => {},
        getForm = () => ({}),
        getCtripCookies = () => '',
        getHotelNameById = () => '',
        defaultAdsUrl = defaultCtripAdsEffectReportUrl,
        adsUrlHint = ctripAdsApiUrlHint,
        setRunning = () => {},
        setGlobalFetching = () => {},
        setResult = () => {},
        setOnlineDataResult = () => {},
        setShowRawData = () => {},
        requestFetch = async () => ({}),
        refreshLatestCtripData = async () => {},
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        if (!systemHotelId) {
            notify('请选择目标酒店', 'error');
            return { status: 'missing_hotel' };
        }

        const activeConfig = await ensureCtripConfigSecret(getActiveCtripConfig());
        if (!activeConfig) {
            notify('当前酒店未配置携程数据源', 'warning');
            return { status: 'missing_config' };
        }
        applyCtripConfigObject(activeConfig);
        await syncAdsDirectConfig(false);

        const form = getForm() || {};
        const hotelId = String(activeConfig?.ota_hotel_id || activeConfig?.ctrip_hotel_id || activeConfig?.hotel_id || '').trim();
        const url = String(form.url || defaultAdsUrl).trim();
        const cookies = String(form.cookies || getCtripCookies() || activeConfig.cookies || '').trim();
        if (url.includes('/toolcenter/cpc/pyramid')) {
            notify('请填写 Network 中 queryCampaignReportList 的 JSON 接口 URL，不是广告页面地址', 'error');
            return { status: 'invalid_page_url', url };
        }
        if (!isCtripAdsApiUrl(url)) {
            notify(adsUrlHint, 'error');
            return { status: 'invalid_api_url', url };
        }
        if (!cookies) {
            notify('请提供携程 Cookie', 'error');
            return { status: 'missing_cookies', url };
        }
        if (form.dateRange === 'custom' && (!form.startDate || !form.endDate)) {
            notify('请选择自定义开始日期和结束日期', 'error');
            return { status: 'missing_custom_dates', url };
        }

        setRunning(true);
        setGlobalFetching(true);
        setResult(null);
        try {
            const requestBody = buildCtripAdsFetchRequestBody({
                systemHotelId,
                hotelId,
                hotelName: getHotelNameById(systemHotelId),
                url,
                cookies,
                form,
            });
            const directRequestBody = { ...requestBody, async: false, background: false };
            const res = await requestFetch(directRequestBody);
            if (isCtripBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'ctrip',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setResult(runningPayload);
                setOnlineDataResult(runningPayload);
                setShowRawData(false);
                notify(res.message || '携程广告手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload };
            }
            if (res.code === 200) {
                const data = res.data || {};
                setResult(data);
                setOnlineDataResult(data);
                setShowRawData(false);
                notify(res.message || `广告数据获取完成，已入库 ${data.saved_count || 0} 条`);
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, requestBody: directRequestBody };
            }

            notify(res.message || '广告数据获取失败', 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            notify('广告数据获取失败: ' + error.message, 'error');
            setResult(error?.data?.data || { error: error.message });
            return { status: 'exception', error };
        } finally {
            setRunning(false);
            setGlobalFetching(false);
        }
    };

    const buildCtripCookieApiFetchRequestBody = ({
        systemHotelId = null,
        hotelId = '',
        hotelName = '',
        profileId = '',
        dataDate = '',
        requestUrl = '',
        form = {},
        endpointsJson = '',
        cookies = '',
    } = {}) => ({
        system_hotel_id: systemHotelId,
        hotel_id: hotelId,
        hotel_name: hotelName,
        profile_id: profileId,
        data_date: dataDate,
        request_url: requestUrl,
        method: String(form.method || 'GET').toUpperCase(),
        payload_json: String(form.payloadJson || '').trim(),
        endpoints_json: endpointsJson,
        cookies,
        auto_save: true,
    });

    const runCtripCookieApiCaptureFlow = async ({
        getSelectedCtripHotelId = () => '',
        setSelectedCtripHotelId = () => {},
        getAutoFetchHotelId = () => '',
        getUserHotelId = () => '',
        hasCtripConfigList = () => false,
        loadCtripConfigList = async () => {},
        getActiveCtripConfig = () => null,
        findCtripConfigByHotelId = () => null,
        ensureCtripConfigSecret = async config => config,
        applyCtripConfigObject = () => {},
        getForm = () => ({}),
        getOverviewForm = () => ({}),
        getHotelNameById = () => '',
        resolveProfileId = () => '',
        resolveRequestHotelId = () => '',
        requestCapture = async () => ({}),
        setProfileId = () => {},
        setRunning = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        setShowRawData = () => {},
        notify = () => {},
        refreshLatestCtripData = async () => {},
        refreshOnlineHistory = async () => {},
        shouldRefreshDataHealthPanel = () => false,
        refreshDataHealthPanel = async () => {},
    } = {}) => {
        const targetContext = buildCtripBrowserCaptureTargetContext({
            selectedCtripHotelId: getSelectedCtripHotelId(),
            autoFetchHotelId: getAutoFetchHotelId(),
            userHotelId: getUserHotelId(),
        });
        if (!targetContext.ok) {
            notify(targetContext.result.message, 'error');
            return { status: 'missing_hotel', result: targetContext.result };
        }
        const { systemHotelId } = targetContext;
        const form = getForm() || {};
        const requestUrl = String(form.requestUrl || '').trim();
        const endpointsJson = String(form.endpointsJson || '').trim();
        if (!requestUrl && !endpointsJson) {
            notify('请填写携程接口 Request URL 或批量接口 JSON', 'error');
            return { status: 'missing_request_source' };
        }

        if (!getSelectedCtripHotelId()) {
            setSelectedCtripHotelId(String(systemHotelId));
        }
        if (!hasCtripConfigList()) {
            await loadCtripConfigList();
        }
        let activeConfig = getActiveCtripConfig();
        if (!activeConfig || String(activeConfig.hotel_id || activeConfig.system_hotel_id || '') !== String(systemHotelId)) {
            activeConfig = findCtripConfigByHotelId(systemHotelId);
        }
        activeConfig = await ensureCtripConfigSecret(activeConfig);
        if (activeConfig) {
            applyCtripConfigObject(activeConfig, false);
        }

        const cookies = String(form.cookies || activeConfig?.cookies || activeConfig?.cookie || '').trim();
        const profileId = resolveProfileId(systemHotelId, activeConfig);
        if (!profileId) {
            notify('请填写携程登录会话标识，或先绑定携程登录会话数据源', 'error');
            return { status: 'missing_profile' };
        }
        setProfileId(profileId);

        const overviewForm = getOverviewForm() || {};
        const requestBody = buildCtripCookieApiFetchRequestBody({
            systemHotelId,
            hotelId: resolveRequestHotelId(systemHotelId, activeConfig),
            hotelName: getHotelNameById(systemHotelId),
            profileId,
            dataDate: overviewForm.dataDate,
            requestUrl,
            form,
            endpointsJson,
            cookies,
        });

        setRunning(true);
        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestCapture(requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setCaptureResult(data);
                setOnlineDataResult(data);
                setShowRawData(false);
                if (data.is_ready === false) {
                    notify(data.warning || data.next_action || res.message || '携程 Cookie API 未达到诊断就绪', 'warning');
                } else {
                    notify(res.message || `携程 Cookie API 采集完成，已入库 ${data.saved_count || 0} 条`);
                }
                runPostFetchRefresh(refreshLatestCtripData, { silent: true });
                runPostFetchRefresh(refreshOnlineHistory);
                if (shouldRefreshDataHealthPanel()) {
                    runPostFetchRefresh(refreshDataHealthPanel, 'light', { force: true });
                }
                return { status: 'success', response: res, requestBody };
            }

            const failureResult = res.data || { error: res.message || '携程 Cookie API 采集失败' };
            setCaptureResult(failureResult);
            const identityMessage = res.data?.identity_check?.message || res.data?.message || '';
            notify(identityMessage || res.message || '携程 Cookie API 采集失败', 'error');
            return { status: 'error_response', response: res, requestBody };
        } catch (error) {
            const errorData = error?.data?.data || error?.data || {};
            const failureResult = errorData && Object.keys(errorData).length ? errorData : { error: error.message };
            setCaptureResult(failureResult);
            const identityMessage = errorData?.identity_check?.message || errorData?.message || '';
            notify(identityMessage || ('携程 Cookie API 采集失败: ' + error.message), 'error');
            return { status: 'exception', error, requestBody };
        } finally {
            setRunning(false);
            setFetching(false);
        }
    };

    const hasVisibleCtripMetricValue = (value) => value !== undefined && value !== null && value !== '';

    const ctripSortMetricValue = (row = {}, field = '') => {
        if (field === 'amount') return row.amount || 0;
        if (field === 'quantity') return row.quantity || 0;
        if (field === 'adr') return row.adr || 0;
        if (field === 'ari') return row.ari || 0;
        if (field === 'sci') return row.sci || 0;
        if (field === 'bookOrderNum') return row.bookOrderNum || 0;
        if (field === 'aiEstimatedTotalRoomNights') return row.aiEstimatedTotalRoomNights || 0;
        if (field === 'totalOrderNum') return row.totalOrderNum || 0;
        if (field === 'commentScore') return row.commentScore || 0;
        if (field === 'qunarCommentScore') return row.qunarCommentScore || 0;
        if (field === 'totalDetailNum') return row.totalDetailNum || 0;
        if (field === 'convertionRate') return row.convertionRate || 0;
        if (field === 'qunarDetailVisitors') return row.qunarDetailVisitors || 0;
        if (field === 'qunarDetailCR') return row.qunarDetailCR || 0;
        if (field === 'bookRate') return row.bookingRate || 0;
        if (field === 'amountRank' || field === 'quantityRank' || field === 'commentScoreRank' || field === 'qunarDetailCRRank') {
            return row[field] || 99999;
        }
        return row[field] || 0;
    };

    const buildCtripSortedHotelRows = (rows = [], field = '', order = 'desc') => {
        const list = Array.isArray(rows) ? rows : [];
        if (!field) return list;
        return [...list].sort((a, b) => {
            const aVal = ctripSortMetricValue(a, field);
            const bVal = ctripSortMetricValue(b, field);
            return order === 'asc' ? aVal - bVal : bVal - aVal;
        });
    };

    const buildCtripOverviewMetricCards = (result = {}) => {
        const metrics = result?.metrics || {};
        return [
            { key: 'yesterday_uv', label: '昨日UV', value: metrics.yesterday_uv },
            { key: 'order_count', label: '订单数', value: metrics.order_count },
            { key: 'amount', label: '成交收入', value: metrics.amount, type: 'currency2' },
            { key: 'room_nights', label: '成交间夜', value: metrics.room_nights },
            { key: 'avg_price', label: '均价', value: metrics.avg_price, type: 'currency2' },
            { key: 'conversion_rate', label: '成交率', value: metrics.conversion_rate, type: 'percent' },
            { key: 'competitor_uv', label: '竞品UV', value: metrics.competitor_uv },
            { key: 'competitor_orders', label: '竞品订单', value: metrics.competitor_orders },
            { key: 'competitor_amount', label: '竞品收入', value: metrics.competitor_amount, type: 'currency2' },
            { key: 'psi', label: 'PSI', value: metrics.psi },
            { key: 'hotel_score', label: '酒店评分', value: metrics.hotel_score },
            { key: 'reply_rate', label: '回复率', value: metrics.reply_rate, type: 'percent' },
            { key: 'favorite_count', label: '收藏数', value: metrics.favorite_count },
            { key: 'visitor_rank', label: '访客排名', value: metrics.visitor_rank },
            { key: 'self_list_exposure', label: '列表曝光', value: metrics.self_list_exposure },
            { key: 'self_detail_exposure', label: '详情访客', value: metrics.self_detail_exposure },
            { key: 'self_flow_rate', label: '曝光转化率', value: metrics.self_flow_rate, type: 'percent' },
            { key: 'self_order_filling_num', label: '订单页访客', value: metrics.self_order_filling_num },
            { key: 'self_order_submit_num', label: '订单提交人数', value: metrics.self_order_submit_num },
            { key: 'self_order_fill_rate', label: '下单转化率', value: metrics.self_order_fill_rate, type: 'percent' },
            { key: 'self_deal_rate', label: '成交转化率', value: metrics.self_deal_rate, type: 'percent' },
            { key: 'competitor_list_exposure', label: '竞圈列表曝光', value: metrics.competitor_list_exposure },
            { key: 'competitor_detail_exposure', label: '竞圈详情访客', value: metrics.competitor_detail_exposure },
            { key: 'competitor_order_filling_num', label: '竞圈订单页访客', value: metrics.competitor_order_filling_num },
            { key: 'competitor_order_submit_num', label: '竞圈订单提交', value: metrics.competitor_order_submit_num },
            { key: 'competitor_order_fill_rate', label: '竞圈下单转化率', value: metrics.competitor_order_fill_rate, type: 'percent' },
            { key: 'competitor_deal_rate', label: '竞圈成交转化率', value: metrics.competitor_deal_rate, type: 'percent' },
            { key: 'compete_hotel_count', label: '竞争圈酒店数', value: metrics.compete_hotel_count },
            { key: 'amount_rank', label: '销售额排名', value: metrics.amount_rank },
            { key: 'quantity_rank', label: '在店间夜排名', value: metrics.quantity_rank },
            { key: 'book_order_num_rank', label: '预订订单排名', value: metrics.book_order_num_rank },
            { key: 'comment_score_rank', label: '点评排名', value: metrics.comment_score_rank },
            { key: 'conversion_rank', label: 'APP转化排名', value: metrics.conversion_rank },
            { key: 'flow_lost_order_num', label: '流失订单', value: metrics.flow_lost_order_num },
            { key: 'flow_lost_room_nights', label: '流失间夜', value: metrics.flow_lost_room_nights },
            { key: 'flow_lost_amount', label: '流失收入', value: metrics.flow_lost_amount, type: 'currency2' },
            { key: 'top_flow_hotel', label: '流失访客TOP1', value: metrics.top_flow_hotel, type: 'text' },
            { key: 'top_flow_hotel_browse_rate', label: 'TOP浏览率', value: metrics.top_flow_hotel_browse_rate, type: 'percent' },
            { key: 'top_flow_hotel_order_rate', label: 'TOP下单率', value: metrics.top_flow_hotel_order_rate, type: 'percent' },
            { key: 'top_hot_room', label: '热售房型TOP1', value: metrics.top_hot_room, type: 'text' },
            { key: 'top_hot_room_nights', label: '热售房型间夜', value: metrics.top_hot_room_nights },
            { key: 'top_hot_room_sale_percent', label: '热售房型占比', value: metrics.top_hot_room_sale_percent, type: 'percent' },
            { key: 'last_week_comment_score', label: '上周点评分', value: metrics.last_week_comment_score },
            { key: 'last_week_good_add', label: '新增好评数', value: metrics.last_week_good_add },
            { key: 'last_week_bad_add', label: '新增差评数', value: metrics.last_week_bad_add },
            { key: 'last_week_price_score', label: '起价竞争分', value: metrics.last_week_price_score },
            { key: 'last_week_checkout_room_nights', label: '上周离店间夜', value: metrics.last_week_checkout_room_nights },
            { key: 'last_week_checkout_sales', label: '上周离店销售额', value: metrics.last_week_checkout_sales, type: 'currency2' },
            { key: 'last_week_checkout_room_price', label: '上周离店均价', value: metrics.last_week_checkout_room_price, type: 'currency2' },
            { key: 'last_week_book_quantity', label: '上周预订订单', value: metrics.last_week_book_quantity },
            { key: 'last_week_book_room_nights', label: '上周预订间夜', value: metrics.last_week_book_room_nights },
            { key: 'last_week_book_sales', label: '上周预订销售额', value: metrics.last_week_book_sales, type: 'currency2' },
            { key: 'weekly_self_list_exposure', label: '周列表曝光', value: metrics.weekly_self_list_exposure },
            { key: 'weekly_self_detail_exposure', label: '周详情访客', value: metrics.weekly_self_detail_exposure },
            { key: 'weekly_self_order_filling_num', label: '周订单页访客', value: metrics.weekly_self_order_filling_num },
            { key: 'weekly_self_order_submit_num', label: '周订单提交', value: metrics.weekly_self_order_submit_num },
            { key: 'weekly_self_flow_rate', label: '周曝光转化率', value: metrics.weekly_self_flow_rate, type: 'percent' },
            { key: 'weekly_self_order_fill_rate', label: '周下单转化率', value: metrics.weekly_self_order_fill_rate, type: 'percent' },
            { key: 'weekly_self_deal_rate', label: '周成交转化率', value: metrics.weekly_self_deal_rate, type: 'percent' },
            { key: 'top_competitor_list_exposure', label: '标杆列表曝光', value: metrics.top_competitor_list_exposure },
            { key: 'top_competitor_detail_exposure', label: '标杆详情访客', value: metrics.top_competitor_detail_exposure },
            { key: 'top_competitor_order_filling_num', label: '标杆订单页访客', value: metrics.top_competitor_order_filling_num },
            { key: 'top_competitor_order_submit_num', label: '标杆订单提交', value: metrics.top_competitor_order_submit_num },
            { key: 'top_competitor_flow_rate', label: '标杆曝光转化率', value: metrics.top_competitor_flow_rate, type: 'percent' },
            { key: 'top_competitor_order_fill_rate', label: '标杆下单转化率', value: metrics.top_competitor_order_fill_rate, type: 'percent' },
            { key: 'top_competitor_deal_rate', label: '标杆成交转化率', value: metrics.top_competitor_deal_rate, type: 'percent' },
        ].filter(card => hasVisibleCtripMetricValue(card.value));
    };

    const normalizeCtripTopRankItems = (value) => {
        const list = Array.isArray(value) ? value : (value ? [value] : []);
        return list.map((item) => {
            if (item && typeof item === 'object') {
                return String(item.hotelName || item.hotel_name || item.name || item.title || item.keyword || item.word || '').trim();
            }
            return String(item || '').trim();
        }).filter(Boolean).slice(0, 10);
    };

    const buildCtripOverviewTopRankTables = (result = {}) => {
        const metrics = result?.metrics || {};
        const period = metrics.week_period || metrics.period || metrics.date_range || '';
        const hotels = normalizeCtripTopRankItems(metrics.top_hot_hotels);
        const words = normalizeCtripTopRankItems(metrics.top_hot_words);
        return [
            {
                key: 'hot-hotels',
                title: '同城热门酒店TOP榜',
                valueLabel: '酒店名称',
                period,
                items: hotels,
            },
            {
                key: 'hot-words',
                title: '同城热门关键词',
                valueLabel: '关键词',
                period,
                items: words,
            },
        ].filter(table => table.items.length > 0);
    };

    const buildCtripFlowOverviewMetricCards = (result = {}) => {
        const metrics = result?.metrics || {};
        return [
            { key: 'yesterday_uv', label: '昨日UV', value: metrics.yesterday_uv },
            { key: 'visitor_rank', label: '访客排名', value: metrics.visitor_rank },
            { key: 'self_list_exposure', label: '列表曝光', value: metrics.self_list_exposure },
            { key: 'self_detail_exposure', label: '详情访客', value: metrics.self_detail_exposure },
            { key: 'self_flow_rate', label: '曝光转化率', value: metrics.self_flow_rate, type: 'percent' },
            { key: 'self_order_filling_num', label: '订单页访客', value: metrics.self_order_filling_num },
            { key: 'self_order_submit_num', label: '订单提交人数', value: metrics.self_order_submit_num },
            { key: 'self_order_fill_rate', label: '下单转化率', value: metrics.self_order_fill_rate, type: 'percent' },
            { key: 'self_deal_rate', label: '成交转化率', value: metrics.self_deal_rate, type: 'percent' },
            { key: 'competitor_uv', label: '竞品UV', value: metrics.competitor_uv },
            { key: 'competitor_orders', label: '竞品订单/间夜', value: metrics.competitor_orders },
            { key: 'competitor_amount', label: '竞品收入', value: metrics.competitor_amount, type: 'currency2' },
            { key: 'competitor_list_exposure', label: '竞圈列表曝光', value: metrics.competitor_list_exposure },
            { key: 'competitor_detail_exposure', label: '竞圈详情访客', value: metrics.competitor_detail_exposure },
            { key: 'competitor_order_filling_num', label: '竞圈订单页访客', value: metrics.competitor_order_filling_num },
            { key: 'competitor_order_submit_num', label: '竞圈订单提交', value: metrics.competitor_order_submit_num },
            { key: 'competitor_order_fill_rate', label: '竞圈下单转化率', value: metrics.competitor_order_fill_rate, type: 'percent' },
            { key: 'competitor_deal_rate', label: '竞圈成交转化率', value: metrics.competitor_deal_rate, type: 'percent' },
            { key: 'amount', label: '成交收入', value: metrics.amount, type: 'currency2' },
            { key: 'room_nights', label: '成交间夜', value: metrics.room_nights },
            { key: 'order_count', label: '订单数', value: metrics.order_count },
            { key: 'avg_price', label: '均价', value: metrics.avg_price, type: 'currency2' },
            { key: 'conversion_rate', label: '成交率', value: metrics.conversion_rate, type: 'percent' },
            { key: 'psi', label: 'PSI', value: metrics.psi },
            { key: 'hotel_score', label: '酒店评分', value: metrics.hotel_score },
            { key: 'reply_rate', label: '回复率', value: metrics.reply_rate, type: 'percent' },
            { key: 'favorite_count', label: '收藏数', value: metrics.favorite_count },
        ].filter(card => hasVisibleCtripMetricValue(card.value));
    };

    const normalizeCtripFlowOverviewUrlText = (item) => {
        if (!item) return '';
        if (typeof item === 'string') return item;
        return String(item.url || item.request_url || item.requestUrl || '');
    };

    const trimCtripFlowOverviewReasonText = (text) => {
        const value = String(text || '').replace(/\s+/g, ' ').trim();
        return value.length > 120 ? `${value.slice(0, 120)}...` : value;
    };

    const buildCtripFlowOverviewInterfaceReason = (context) => {
        if (context.responseRowCount > 0) {
            return `${context.note}，解析 ${context.responseRowCount} 行`;
        }
        if (context.responseHitCount > 0) {
            if (context.hasExplicitParsedRowCount) {
                return `${context.note}；接口有响应但未解析到可入库行，检查字段映射或响应结构`;
            }
            return context.note;
        }
        if (context.errorText) {
            return `接口请求失败：${trimCtripFlowOverviewReasonText(context.errorText)}`;
        }
        if (context.requestHitCount > 0 || context.configuredCount > 0) {
            return '已配置但未收到接口响应，检查 Cookie 状态、请求方式、状态码或接口是否被拦截';
        }
        return '未在本次 Request URL 列表中配置；如需该类指标，请从 Network 补充该接口 URL 或执行 Profile 核心抓取';
    };

    const buildCtripFlowOverviewInterfaceRows = (result = {}, groups = ctripFlowOverviewApiGroups) => {
        const safeResult = result && typeof result === 'object' ? result : {};
        const requestRows = Array.isArray(safeResult.xhr_urls) ? safeResult.xhr_urls : [];
        const configuredUrls = Array.isArray(safeResult.request_urls) ? safeResult.request_urls.map(normalizeCtripFlowOverviewUrlText) : [];
        const responseRows = Array.isArray(safeResult.responses) ? safeResult.responses : [];
        const errorTexts = Array.isArray(safeResult.errors) ? safeResult.errors.map(item => String(item || '')) : [];
        return (Array.isArray(groups) ? groups : []).map((item) => {
            const aliases = Array.isArray(item.aliases) && item.aliases.length ? item.aliases : [item.keyword];
            const aliasLowers = aliases.map(alias => String(alias).toLowerCase());
            const hasKeyword = (url) => aliasLowers.some(keyword => String(url).toLowerCase().includes(keyword));
            const matchedConfiguredUrls = configuredUrls.filter(hasKeyword);
            const matchedRequestRows = requestRows.filter(row => hasKeyword(normalizeCtripFlowOverviewUrlText(row)));
            const matchedResponses = responseRows.filter(row => hasKeyword(normalizeCtripFlowOverviewUrlText(row)));
            const matchedErrors = errorTexts.filter(hasKeyword);
            const failedRequestRows = matchedRequestRows.filter(row => Number(row.status || row.http_code || 0) >= 400);
            const responseRowCount = matchedResponses.reduce((sum, row) => sum + Number(row.row_count || row.rowCount || row.standard_row_count || row.standardRowCount || 0), 0);
            const hasExplicitParsedRowCount = matchedResponses.some(row =>
                row.row_count !== undefined
                || row.rowCount !== undefined
                || row.standard_row_count !== undefined
                || row.standardRowCount !== undefined
            );
            const requestHitCount = matchedRequestRows.length || matchedConfiguredUrls.length;
            const responseHitCount = matchedResponses.length;
            const errorText = matchedErrors[0] || (failedRequestRows[0] ? `HTTP ${failedRequestRows[0].status || failedRequestRows[0].http_code}` : '');
            let status = 'not_configured';
            let statusText = '未配置';
            let statusClass = 'bg-gray-100 text-gray-500';
            if (responseHitCount > 0 || responseRowCount > 0) {
                status = 'hit';
                statusText = '已命中';
                statusClass = 'bg-green-100 text-green-700';
            } else if (errorText) {
                status = 'request_failed';
                statusText = '请求失败';
                statusClass = 'bg-red-100 text-red-700';
            } else if (requestHitCount > 0) {
                status = 'no_response';
                statusText = '无响应';
                statusClass = 'bg-orange-100 text-orange-700';
            }
            const reasonText = buildCtripFlowOverviewInterfaceReason({
                ...item,
                configuredCount: matchedConfiguredUrls.length,
                requestHitCount,
                responseHitCount,
                responseRowCount,
                hasExplicitParsedRowCount,
                errorText,
            });
            return {
                ...item,
                hit: status === 'hit',
                status,
                statusClass,
                requestHitCount,
                responseHitCount,
                rowCount: responseRowCount,
                statusText,
                reasonText,
            };
        });
    };

    const buildCtripProfileRecheckInitialState = ({
        canRecapture = false,
        targetCount = 0,
        estimatedText = '',
        startedAt = '',
        sections = [],
    } = {}) => ({
        active: true,
        type: 'running',
        stage: canRecapture ? 'capture' : 'refresh_samples',
        message: canRecapture
            ? `正在重抓 ${targetCount} 个缺口/存疑字段，浏览器会按模块触发接口；${estimatedText}。`
            : '未选目标酒店，无法启动浏览器重抓，正在刷新已有历史获取值。',
        started_at: startedAt,
        finished_at: '',
        target_count: targetCount,
        sections,
    });

    const buildCtripProfileRecheckRunContext = ({
        targets = [],
        estimatedText = '',
        startedAt = '',
        selectedCtripHotelId = '',
        autoFetchHotelId = '',
        userHotelId = '',
    } = {}) => {
        const rows = Array.isArray(targets) ? targets : [];
        const sections = Array.from(new Set(rows
            .map(field => String(field?.section || '').trim())
            .filter(Boolean)));
        const normalizedSections = sections.length ? sections : ['default'];
        const canRecapture = Boolean(selectedCtripHotelId || autoFetchHotelId || userHotelId);
        const targetCount = rows.length;
        const normalizedStartedAt = startedAt || new Date().toLocaleString('zh-CN', { hour12: false });
        return {
            sections: normalizedSections,
            canRecapture,
            targetCount,
            requestOptions: {
                method: 'POST',
                body: JSON.stringify({ sections: normalizedSections }),
            },
            initialState: buildCtripProfileRecheckInitialState({
                canRecapture,
                targetCount,
                estimatedText,
                startedAt: normalizedStartedAt,
                sections: normalizedSections,
            }),
            startMessage: `开始重抓 ${targetCount} 个缺口/存疑字段，${estimatedText}`,
        };
    };

    const buildCtripProfileRecheckCaptureRefreshState = ({
        previousState = {},
        captureSucceeded = false,
        captureMessage = '',
    } = {}) => ({
        ...previousState,
        type: captureSucceeded ? 'running' : 'warning',
        stage: 'refresh_samples',
        message: captureSucceeded
            ? '浏览器采集已返回，正在刷新字段获取值并恢复为待二次确认。'
            : `浏览器采集未完整完成：${captureMessage || '后端未返回成功状态'}；正在刷新已有字段获取值。`,
    });

    const buildCtripProfileRecheckSuccessResult = ({
        previousState = {},
        captureSucceeded = false,
        captureSkipped = false,
        result = {},
        durationText = '',
        finishedAt = '',
    } = {}) => {
        const prefix = captureSucceeded
            ? 'Profile 已重抓'
            : (captureSkipped ? '未选目标酒店，仅刷新历史获取值' : 'Profile 重抓未完成，已刷新历史获取值');
        const message = `${prefix}：待二次确认 ${result.second_confirmation_count || result.refreshed_count || 0} 个，待补解析 ${result.unresolved_count || 0} 个（耗时 ${durationText}）`;
        return {
            state: {
                ...previousState,
                active: false,
                type: captureSucceeded ? 'success' : 'warning',
                stage: captureSucceeded ? 'done' : 'partial',
                message,
                finished_at: finishedAt,
            },
            message,
            toastType: captureSucceeded ? 'success' : 'warning',
        };
    };

    const buildCtripProfileRecheckErrorResult = ({
        previousState = {},
        message = '不符字段重跑失败',
        durationText = '',
        finishedAt = '',
        prefix = '',
    } = {}) => {
        const finalMessage = `${prefix}${message}（耗时 ${durationText}）`;
        return {
            state: {
                ...previousState,
                active: false,
                type: 'error',
                stage: 'error',
                message: finalMessage,
                finished_at: finishedAt,
            },
            message: finalMessage,
        };
    };

    const buildCtripProfileRecheckInterruptedState = ({
        previousState = {},
        finishedAt = '',
    } = {}) => ({
        ...previousState,
        active: false,
        type: 'warning',
        stage: 'partial',
        message: '重抓流程已结束，但字段列表在执行中被刷新；请查看当前获取值状态或再次重抓。',
        finished_at: finishedAt,
    });
    const runCtripProfileRecheckFlow = async ({
        recheckRun = {},
        requestSeq = 0,
        getCurrentRequestSeq = () => requestSeq,
        getCurrentState = () => ({}),
        setState = () => {},
        notify = () => {},
        runBrowserCapture = async () => ({}),
        requestRecheck = async () => ({}),
        applyResponse = () => {},
        getDurationText = () => '',
        getFinishedAt = () => new Date().toLocaleString('zh-CN', { hour12: false }),
        shouldFinalize = () => true,
        onStop = () => {},
    } = {}) => {
        const sections = Array.isArray(recheckRun.sections) ? recheckRun.sections : [];
        const canRecapture = Boolean(recheckRun.canRecapture);
        const requestOptions = recheckRun.requestOptions || {};
        let captureSucceeded = false;
        let captureSkipped = false;

        setState(recheckRun.initialState || {});
        notify(recheckRun.startMessage || '', 'info');
        try {
            if (canRecapture) {
                const captureRes = await runBrowserCapture({
                    sections,
                    bindDataSource: true,
                    silent: true,
                });
                captureSucceeded = Number(captureRes?.code || 0) === 200;
                const captureMessage = captureRes?.message || captureRes?.data?.message || '';
                setState(buildCtripProfileRecheckCaptureRefreshState({
                    previousState: getCurrentState(),
                    captureSucceeded,
                    captureMessage,
                }));
            } else {
                captureSkipped = true;
            }

            const res = await requestRecheck(requestOptions);
            if (requestSeq !== getCurrentRequestSeq()) {
                return { status: 'stale' };
            }
            if (Number(res?.code || 0) === 200) {
                const data = res.data || {};
                applyResponse(data);
                const recheckResult = buildCtripProfileRecheckSuccessResult({
                    previousState: getCurrentState(),
                    captureSucceeded,
                    captureSkipped,
                    result: data.recheck_result || {},
                    durationText: getDurationText(),
                    finishedAt: getFinishedAt(),
                });
                setState(recheckResult.state);
                notify(recheckResult.message, recheckResult.toastType);
                return { status: 'success', ...recheckResult };
            }

            const recheckResult = buildCtripProfileRecheckErrorResult({
                previousState: getCurrentState(),
                message: res?.message || '不符字段重跑失败',
                durationText: getDurationText(),
                finishedAt: getFinishedAt(),
            });
            setState(recheckResult.state);
            notify(recheckResult.message, 'error');
            return { status: 'failed', ...recheckResult };
        } catch (error) {
            if (requestSeq === getCurrentRequestSeq()) {
                const recheckResult = buildCtripProfileRecheckErrorResult({
                    previousState: getCurrentState(),
                    message: error?.message || '不符字段重跑失败',
                    durationText: getDurationText(),
                    finishedAt: getFinishedAt(),
                    prefix: '不符字段重跑失败: ',
                });
                setState(recheckResult.state);
                notify(recheckResult.message, 'error');
                return { status: 'error', ...recheckResult };
            }
            return { status: 'stale_error', message: error?.message || '' };
        } finally {
            if (shouldFinalize()) {
                const currentState = getCurrentState() || {};
                if (currentState.active) {
                    setState(buildCtripProfileRecheckInterruptedState({
                        previousState: currentState,
                        finishedAt: getFinishedAt(),
                    }));
                }
            }
            onStop();
        }
    };

    const getCtripCookieApiCorePresetEndpoints = () => ([
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo',
            method: 'GET',
            section: 'market_calendar',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData',
            method: 'GET',
            section: 'homepage',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryMarketDetails',
            method: 'POST',
            section: 'sales_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1',
            method: 'POST',
            section: 'sales_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotelOccupiedRoomTrendV1',
            method: 'POST',
            section: 'sales_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryRoomTensitiesV1',
            method: 'POST',
            section: 'sales_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2',
            method: 'POST',
            section: 'traffic_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowTransformNewV1',
            method: 'POST',
            section: 'traffic_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowSource',
            method: 'POST',
            section: 'traffic_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryCityHotKeywords',
            method: 'POST',
            section: 'traffic_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/querySearchFlowDetails',
            method: 'POST',
            section: 'traffic_report',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryCampaignSummaryReport',
            method: 'POST',
            section: 'ads_pyramid',
        },
        {
            request_url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2',
            method: 'GET',
            section: 'quality_psi',
        },
        {
            request_url: 'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable',
            method: 'POST',
            section: 'biztravel_bpi',
        },
        {
            request_url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail',
            method: 'POST',
            section: 'biztravel_business_report',
        },
        {
            request_url: 'https://bbk.ctripbiz.cn/api/dataCenterComparisonReportDetail',
            method: 'POST',
            section: 'biztravel_competitor',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryUserSex',
            method: 'POST',
            section: 'user_profile',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getImIndex',
            method: 'POST',
            section: 'im_board',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getImDateDistribute',
            method: 'POST',
            section: 'im_board',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData',
            method: 'POST',
            section: 'competitor_overview',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getFlowSource',
            method: 'POST',
            section: 'competitor_overview',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getTripartiteOrderLoss',
            method: 'POST',
            section: 'loss_analysis',
        },
        {
            request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank',
            method: 'POST',
            section: 'competitor_rank',
        },
    ]);

    return {
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
        createCtripAdsBrowserCaptureForm,
        createCtripOverviewForm,
        createCtripFlowOverviewForm,
        createCtripBrowserCaptureForm,
        createCtripCookieApiForm,
        createCtripEndpointEvidenceForm,
        createCtripCommentForm,
        createCtripCommentBrowserCaptureForm,
        buildCtripConfigSavePayload,
        validateCtripConfigSaveInput,
        runCtripConfigSaveFlow,
        runCtripManualTabSwitch,
        isCtripRankingFormAlignedWithConfig,
        createCtripProfileFieldForm,
        buildCtripProfileFieldSmartDefaults,
        buildCtripProfileFieldSavePayload,
        buildCtripProfileFieldSampleHelpers,
        buildCtripProfileFieldDerivationHelpers,
        normalizeCtripBrowserCaptureSections,
        buildCtripBrowserCaptureTargetContext,
        buildCtripBrowserCapturePayload,
        buildCtripBrowserCaptureRequestContext,
        normalizeCtripBrowserCaptureErrorResult,
        runCtripBrowserCaptureFlow,
        buildCtripFetchDateRange,
        buildCtripFetchRequestBody,
        buildCtripFetchRequestContext,
        selectCtripFetchResponsePayload,
        buildCtripFetchMeta,
        buildCtripFetchRawFailureResult,
        runCtripFetchDataFlow,
        buildLatestCtripSnapshotModel,
        buildCtripTrafficFetchRequestBody,
        buildCtripTrafficResponseModel,
        runCtripTrafficFetchFlow,
        buildCtripOverviewFetchRequestBody,
        runCtripOverviewFetchFlow,
        buildCtripAdsFetchRequestBody,
        runCtripAdsFetchFlow,
        buildCtripCookieApiFetchRequestBody,
        runCtripCookieApiCaptureFlow,
        ctripSortMetricValue,
        buildCtripSortedHotelRows,
        buildCtripOverviewMetricCards,
        buildCtripOverviewTopRankTables,
        buildCtripFlowOverviewMetricCards,
        buildCtripFlowOverviewInterfaceRows,
        buildCtripProfileRecheckInitialState,
        buildCtripProfileRecheckRunContext,
        buildCtripProfileRecheckCaptureRefreshState,
        buildCtripProfileRecheckSuccessResult,
        buildCtripProfileRecheckErrorResult,
        buildCtripProfileRecheckInterruptedState,
        runCtripProfileRecheckFlow,
        getCtripCookieApiCorePresetEndpoints,
    };
})();
