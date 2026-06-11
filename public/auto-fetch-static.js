window.SUXI_AUTO_FETCH_STATIC = (() => {
    const autoFetchModeOptions = [
        { value: 'hybrid_auto', label: '接口直连自动' },
        { value: 'cookie_config', label: '授权配置自动' },
        { value: 'profile_browser', label: '登录会话自动采集' },
    ];
    const autoFetchCollectionBlueprintRows = [
        { label: '采集对象', value: '授权 OTA 门店指标' },
        { label: '业务日期', value: '历史固定默认昨日；实时快照默认今日' },
        { label: '数据层', value: '原始证据 + 标准行 + 指标行' },
        { label: '入库规则', value: '历史按日更新；实时按小时快照更新' },
    ];
    const autoFetchFieldScopeGroups = [
        {
            category: 'OTA经营',
            metric: '经营概况',
            fields: [
                '今日APP访客', '预订销售额', '实时起价', '点评分', '在店间夜',
                '订单数', '紧张度', '昨日访客', '转化率', '离店销售额',
                '离店间夜', '平均卖价', '实时预订订单', '入住率', '实时排名', '竞争圈排名',
            ],
            source: '携程经营概要、销售报告、流量报告和房态价格页面；美团按已授权流量/订单模块补齐。',
            status: 'ready',
            statusText: '已归档路径',
            action: '默认优先跑经营概要、销售和流量；起价、入住率等以真实响应字段为准。',
        },
        {
            category: '服务质量',
            metric: '服务 / 点评',
            fields: [
                'PSI服务质量分', '点评分', '5分钟回复率', '收藏数',
                '正面标签', '负面标签', '好评率',
            ],
            source: 'PSI、评分、回复率、收藏数已有候选路径；点评标签和好评率涉及点评内容，需显式授权与样例核验。',
            status: 'partial',
            statusText: '需显式验证',
            action: '先保留服务质量指标；点评标签只在明确启用并通过采集门禁后接入。',
        },
        {
            category: '竞争对比',
            metric: '竞争圈',
            fields: [
                '竞对酒店', '距离', '商圈', '订单占比', '转化率',
                '订单数', '销售榜', '流量榜', '服务榜',
                '流失订单', '流失间夜', '流失金额',
            ],
            source: '携程竞争圈概览、榜单、流失分析和竞品酒店接口；美团排名按平台接口独立表达。',
            status: 'ready',
            statusText: '已归档路径',
            action: '使用 wide/all 采集，不把竞争圈数据当成全市场或全酒店经营口径。',
        },
        {
            category: '广告投放',
            metric: '金字塔',
            fields: [
                '广告曝光', '点击', '点击率', '预订', '转化率',
                '花费', '订单金额', '同行TOP对比', '同行平均对比', '自身排名对比',
            ],
            source: '携程金字塔 CPC 页面/接口已归档；费用、订单金额和同行对比依赖广告账号权限。',
            status: 'partial',
            statusText: '需广告授权',
            action: '广告数据独立进入 advertising 口径，不和自然流量、自然订单混算。',
        },
    ];
    const firstDataConfigValue = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && item !== '');
        return value === undefined ? '' : value;
    };
    const parseDataConfigValue = (value) => {
        if (!value) return {};
        if (typeof value === 'string') {
            try {
                return JSON.parse(value) || {};
            } catch (e) {
                return {};
            }
        }
        return typeof value === 'object' ? value : {};
    };
    const normalizeDataConfigForForm = (config = {}) => {
        const normalized = { ...config };
        normalized.node_id = firstDataConfigValue(normalized.node_id, normalized.nodeId);
        normalized.nodeId = firstDataConfigValue(normalized.nodeId, normalized.node_id);
        normalized.partner_id = firstDataConfigValue(normalized.partner_id, normalized.partnerId);
        normalized.partnerId = firstDataConfigValue(normalized.partnerId, normalized.partner_id);
        normalized.poi_id = firstDataConfigValue(normalized.poi_id, normalized.poiId);
        normalized.poiId = firstDataConfigValue(normalized.poiId, normalized.poi_id);
        normalized.rank_type = firstDataConfigValue(normalized.rank_type, normalized.rankType, 'P_RZ');
        normalized.rankType = firstDataConfigValue(normalized.rankType, normalized.rank_type);
        normalized.start_date = firstDataConfigValue(normalized.start_date, normalized.startDate);
        normalized.startDate = firstDataConfigValue(normalized.startDate, normalized.start_date);
        normalized.end_date = firstDataConfigValue(normalized.end_date, normalized.endDate);
        normalized.endDate = firstDataConfigValue(normalized.endDate, normalized.end_date);
        normalized.extra_params = firstDataConfigValue(normalized.extra_params, normalized.extraParams);
        normalized.extraParams = firstDataConfigValue(normalized.extraParams, normalized.extra_params);
        normalized.payload_json = firstDataConfigValue(normalized.payload_json, normalized.payloadJson);
        normalized.payloadJson = firstDataConfigValue(normalized.payloadJson, normalized.payload_json);
        normalized.request_urls = firstDataConfigValue(normalized.request_urls, normalized.requestUrls);
        normalized.requestUrls = firstDataConfigValue(normalized.requestUrls, normalized.request_urls);
        normalized.endpoints_json = firstDataConfigValue(normalized.endpoints_json, normalized.endpointsJson);
        normalized.endpointsJson = firstDataConfigValue(normalized.endpointsJson, normalized.endpoints_json);
        normalized.headers_json = firstDataConfigValue(normalized.headers_json, normalized.headersJson);
        normalized.headersJson = firstDataConfigValue(normalized.headersJson, normalized.headers_json);
        normalized.profile_id = firstDataConfigValue(normalized.profile_id, normalized.profileId);
        normalized.profileId = firstDataConfigValue(normalized.profileId, normalized.profile_id);
        normalized.hotel_id = firstDataConfigValue(normalized.hotel_id, normalized.ctrip_hotel_id, normalized.ctripHotelId);
        normalized.ctrip_hotel_id = firstDataConfigValue(normalized.ctrip_hotel_id, normalized.hotel_id);
        normalized.ctripHotelId = firstDataConfigValue(normalized.ctripHotelId, normalized.ctrip_hotel_id);
        normalized.cookies = firstDataConfigValue(normalized.cookies, normalized.cookie);
        normalized.cookie = firstDataConfigValue(normalized.cookie, normalized.cookies);
        normalized.system_hotel_id = firstDataConfigValue(normalized.system_hotel_id, normalized.hotelId);
        normalized.hotelId = firstDataConfigValue(normalized.hotelId, normalized.system_hotel_id);
        return normalized;
    };
    const compactDataConfigBody = (body = {}) => {
        const compacted = {};
        Object.keys(body).forEach(key => {
            const value = body[key];
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value) && value.length === 0) return;
            compacted[key] = value;
        });
        return compacted;
    };
    const normalizeCtripAdsApiType = () => 'effect_report';
    const buildDataConfigRequestBody = (type, input = {}) => {
        const form = normalizeDataConfigForForm(input || {});
        const startDate = firstDataConfigValue(form.start_date, form.startDate);
        const endDate = firstDataConfigValue(form.end_date, form.endDate);
        const systemHotelId = firstDataConfigValue(form.system_hotel_id, form.hotelId);
        const body = { auto_save: false };

        switch (type) {
            case 'ctrip-ebooking':
                Object.assign(body, {
                    url: form.url,
                    node_id: firstDataConfigValue(form.node_id, form.nodeId),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    auth_data: form.auth_data,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-ebooking':
                Object.assign(body, {
                    url: form.url,
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    rank_type: firstDataConfigValue(form.rank_type, form.rankType, 'P_RZ'),
                    data_scope: form.data_scope,
                    date_range: form.date_range,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    auth_data: form.auth_data,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-traffic':
                Object.assign(body, {
                    url: form.url,
                    platform: form.platform || 'Ctrip',
                    date_range: form.date_range || 'yesterday',
                    start_date: startDate,
                    end_date: endDate,
                    spiderkey: form.spiderkey,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-cookie-api':
                Object.assign(body, {
                    request_urls: firstDataConfigValue(form.request_urls, form.requestUrls),
                    endpoints_json: firstDataConfigValue(form.endpoints_json, form.endpointsJson),
                    request_url: firstDataConfigValue(form.request_url, form.url),
                    method: String(form.method || 'GET').toUpperCase(),
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    headers_json: firstDataConfigValue(form.headers_json, form.headersJson),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    profile_id: firstDataConfigValue(form.profile_id, form.profileId),
                    hotel_id: firstDataConfigValue(form.hotel_id, form.ctrip_hotel_id, form.ctripHotelId),
                    node_id: firstDataConfigValue(form.node_id, form.nodeId),
                    data_date: firstDataConfigValue(startDate, endDate),
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-traffic':
                Object.assign(body, {
                    url: form.url,
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    start_date: startDate,
                    end_date: endDate,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'booking-ota':
            case 'agoda-ota':
            case 'expedia-ota':
                Object.assign(body, {
                    platform: form.platform,
                    url: form.url,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-comments':
                Object.assign(body, {
                    request_url: firstDataConfigValue(form.request_url, form.url),
                    hotel_id: firstDataConfigValue(form.hotel_id, form.hotelId),
                    master_hotel_id: form.master_hotel_id,
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    spidertoken: form.spidertoken,
                    page_index: form.page_index,
                    page_size: form.page_size,
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    _fxpcqlniredt: form._fxpcqlniredt,
                    x_trace_id: form.x_trace_id,
                    tag_type: form.tag_type,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-comments':
                Object.assign(body, {
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    mtgsig: form.mtgsig,
                    _mtsi_eb_u: form._mtsi_eb_u,
                    reply_type: form.reply_type,
                    tag: form.tag,
                    limit: form.limit,
                    offset: form.offset,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'ctrip-ads':
                Object.assign(body, {
                    url: form.url,
                    api_type: normalizeCtripAdsApiType(form.api_type),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson, form.extra_params, form.extraParams),
                    date_range: form.date_range,
                    start_date: startDate,
                    end_date: endDate,
                    system_hotel_id: systemHotelId,
                });
                break;
            case 'meituan-ads':
                Object.assign(body, {
                    url: form.url,
                    method: form.method || 'GET',
                    partner_id: firstDataConfigValue(form.partner_id, form.partnerId),
                    poi_id: firstDataConfigValue(form.poi_id, form.poiId, form.shop_id),
                    shop_id: firstDataConfigValue(form.shop_id, form.shopId, form.poi_id),
                    cookies: firstDataConfigValue(form.cookies, form.cookie),
                    start_date: firstDataConfigValue(form.begin_date, startDate),
                    end_date: endDate,
                    payload_json: firstDataConfigValue(form.payload_json, form.payloadJson),
                    extra_params: firstDataConfigValue(form.extra_params, form.extraParams),
                    system_hotel_id: systemHotelId,
                });
                break;
            default:
                break;
        }

        return compactDataConfigBody(body);
    };

    const buildAutoFetchTriggerRequestBody = ({
        hotelId = '',
        browserHeadless = false,
        modePayload = {},
    } = {}) => ({
        system_hotel_id: hotelId,
        data_period: 'realtime_snapshot',
        interactive_browser: !browserHeadless,
        browser_headless: browserHeadless,
        ...(modePayload || {}),
    });

    const buildAutoFetchRunStartState = ({
        startedAt = '',
        ctripExecutionText = '',
        modePayload = {},
        modeLabel = value => value,
        browserHeadless = false,
    } = {}) => ({
        active: true,
        type: 'running',
        message: `已提交后端执行。${ctripExecutionText}；美团使用${modeLabel(modePayload?.meituan_auto_fetch_mode)}；浏览器${browserHeadless ? '无头运行' : '可视运行'}。`,
        started_at: startedAt,
        finished_at: '',
    });

    const runAutoFetchTriggerFlow = async ({
        getHotelId = () => '',
        hasPlatformFetchConfig = () => false,
        setFetching = () => {},
        startTimer = () => {},
        stopTimer = () => {},
        getTimestamp = () => new Date().toLocaleString('zh-CN', { hour12: false }),
        getBrowserHeadless = () => false,
        getCtripExecutionText = () => '',
        buildModePayload = () => ({}),
        modeLabel = value => value,
        getCtripSectionConcurrency = () => '',
        notify = () => {},
        setRunState = () => {},
        requestAutoFetch = async () => ({}),
        getDurationText = () => '',
        updateLastResult = () => {},
        refreshOnlineData = async () => {},
        refreshOnlineHistory = async () => {},
        refreshLatestCtripData = async () => {},
        openCtripProfileFieldsForReview = async () => {},
        loadAutoFetchStatus = async () => {},
        loadBackendGlobalNotifications = async () => {},
    } = {}) => {
        const hotelId = getHotelId();
        if (!hotelId) {
            notify('请先选择酒店', 'error');
            return { status: 'missing_hotel' };
        }
        if (!hasPlatformFetchConfig(hotelId)) {
            notify('请先在酒店管理中为该酒店保存并关联携程或美团配置', 'error');
            return { status: 'missing_config' };
        }

        setFetching(true);
        startTimer();
        const startedAt = getTimestamp();
        const browserHeadless = !!getBrowserHeadless();
        const modePayload = buildModePayload() || {};
        setRunState(buildAutoFetchRunStartState({
            startedAt,
            ctripExecutionText: getCtripExecutionText(),
            modePayload,
            modeLabel,
            browserHeadless,
        }));
        notify(`正在启动平台抓取：携程 ${getCtripSectionConcurrency()} 页并发 / ${browserHeadless ? '无头' : '可视'}浏览器`, 'info');

        const requestBody = buildAutoFetchTriggerRequestBody({
            hotelId,
            browserHeadless,
            modePayload,
        });
        try {
            const res = await requestAutoFetch(requestBody);
            const finishedAt = getTimestamp();
            const durationText = getDurationText();
            if (res.code === 200) {
                const message = `采集完成并入库 ${res.data?.saved_count || 0} 条 OTA 指标行（耗时 ${durationText}）`;
                updateLastResult(res, true, res.message || message);
                setRunState({
                    active: false,
                    type: 'success',
                    message,
                    started_at: startedAt,
                    finished_at: finishedAt,
                });
                notify(message);
                await refreshOnlineData();
                await refreshOnlineHistory();
                await refreshLatestCtripData({ silent: true });
                await openCtripProfileFieldsForReview();
                await loadAutoFetchStatus();
                await loadBackendGlobalNotifications().catch(() => null);
                return { status: 'success', response: res, requestBody };
            }

            const message = `${res.message || '获取失败'}（耗时 ${durationText}）`;
            updateLastResult(res, false, message);
            setRunState({
                active: false,
                type: 'error',
                message,
                started_at: startedAt,
                finished_at: finishedAt,
            });
            notify(message, 'error');
            await loadAutoFetchStatus();
            await loadBackendGlobalNotifications().catch(() => null);
            return { status: 'error_response', response: res, requestBody };
        } catch (error) {
            const finishedAt = getTimestamp();
            const durationText = getDurationText();
            const message = '获取失败: ' + error.message + `（耗时 ${durationText}）`;
            setRunState({
                active: false,
                type: 'error',
                message,
                started_at: startedAt,
                finished_at: finishedAt,
            });
            notify(message, 'error');
            await loadAutoFetchStatus().catch(() => null);
            await loadBackendGlobalNotifications().catch(() => null);
            return { status: 'exception', error, requestBody };
        } finally {
            stopTimer();
            setFetching(false);
        }
    };

    return {
        autoFetchModeOptions,
        autoFetchCollectionBlueprintRows,
        autoFetchFieldScopeGroups,
        parseDataConfigValue,
        normalizeDataConfigForForm,
        compactDataConfigBody,
        buildDataConfigRequestBody,
        buildAutoFetchTriggerRequestBody,
        buildAutoFetchRunStartState,
        runAutoFetchTriggerFlow,
    };
})();
