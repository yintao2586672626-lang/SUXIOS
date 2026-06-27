window.SUXI_MEITUAN_STATIC = (() => {
    const meituanDisplayMetricLabel = (field) => ({
        roomNights: '入住间夜',
        roomRevenue: '房费收入',
        avgRoomPrice: '平均房价',
        salesRoomNights: '销售间夜',
        sales: '销售额',
        avgSalesPrice: '平均销售房价',
        exposure: '曝光',
        views: '浏览',
        orderCount: '订单量',
        viewConversion: '浏览转化',
        payConversion: '支付转化',
        absoluteConversion: '绝对转化',
    }[field] || field);

    const meituanSortMetricValue = (row, field) => {
        const value = Number(row?.[field] || 0);
        return Number.isFinite(value) ? value : 0;
    };

    const formatMeituanSortGapValue = (value, field) => {
        const numeric = Math.max(0, Number(value) || 0);
        if (['roomRevenue', 'sales', 'avgRoomPrice', 'avgSalesPrice'].includes(field)) {
            return `￥${Math.round(numeric).toLocaleString()}`;
        }
        if (['viewConversion', 'payConversion', 'absoluteConversion'].includes(field)) {
            return `${(numeric * 100).toFixed(2)}%`;
        }
        return Math.round(numeric).toLocaleString();
    };

    const meituanDisplayRowKey = (row, index) => String(row?.poiId || row?.hotelName || index);

    const getOnlineDataMetricNumber = (item, keys) => {
        for (const key of keys) {
            const value = item?.[key];
            if (value !== undefined && value !== null && value !== '') {
                const number = Number(String(value).replace(/[,，%￥¥元\s]/g, ''));
                if (Number.isFinite(number)) {
                    return number;
                }
            }
        }
        return 0;
    };

    const safeDivideMetric = (numerator, denominator) => {
        const top = Number(numerator);
        const bottom = Number(denominator);
        if (!Number.isFinite(top) || !Number.isFinite(bottom) || bottom === 0) {
            return 0;
        }
        return top / bottom;
    };

    const getMeituanExposureMetric = (item) => getOnlineDataMetricNumber(item, ['list_exposure', 'exposure_count', 'exposure', 'data_value']);
    const getMeituanClickMetric = (item) => getOnlineDataMetricNumber(item, ['detail_exposure', 'click_count', 'clicks', 'order_filling_num']);
    const getMeituanVisitorMetric = (item) => getOnlineDataMetricNumber(item, ['order_filling_num', 'visitor_count', 'unique_visitors', 'detail_exposure']);
    const getMeituanSubmitMetric = (item) => getOnlineDataMetricNumber(item, ['order_submit_num', 'submit_users', 'book_order_num']);
    const getMeituanFlowRateMetric = (item) => {
        const explicit = getOnlineDataMetricNumber(item, ['flow_rate', 'conversion_rate', 'conversionRate']);
        if (explicit > 0) {
            return explicit;
        }
        return safeDivideMetric(getMeituanClickMetric(item), getMeituanExposureMetric(item)) * 100;
    };
    const isMeituanTrafficDataRow = (item) => item?.source === 'meituan' && (item?.data_type === 'traffic' || getMeituanExposureMetric(item) > 0);
    const isMeituanOrderDataRow = (item) => item?.source === 'meituan' && item?.data_type === 'order';
    const isMeituanAdsDataRow = (item) => item?.source === 'meituan' && item?.data_type === 'advertising';

    const buildMeituanDownloadData = (rows = []) => {
        const overviewRows = [];
        const trafficRows = [];
        const orderRows = [];
        const adsRows = [];

        let overviewBookOrder = 0;
        let overviewAmount = 0;
        let overviewQuantity = 0;
        let trafficExposure = 0;
        let trafficClick = 0;
        let trafficFlowRateSum = 0;
        let trafficFlowRateCount = 0;
        let orderBookOrder = 0;
        let orderQuantity = 0;
        let orderAmount = 0;
        let adsExposure = 0;
        let adsClick = 0;

        for (const item of Array.isArray(rows) ? rows : []) {
            if (item?.source !== 'meituan') {
                continue;
            }
            overviewRows.push(item);
            overviewBookOrder += Number(item?.book_order_num || 0);
            overviewAmount += Number(item?.amount || 0);
            overviewQuantity += Number(item?.quantity || 0);

            if (isMeituanTrafficDataRow(item)) {
                trafficRows.push(item);
                const exposure = getMeituanExposureMetric(item);
                const click = getMeituanClickMetric(item);
                trafficExposure += exposure;
                trafficClick += click;
                const flowRate = getMeituanFlowRateMetric(item);
                if (flowRate > 0) {
                    trafficFlowRateSum += flowRate;
                    trafficFlowRateCount++;
                }
            }

            if (isMeituanOrderDataRow(item)) {
                orderRows.push(item);
                orderBookOrder += Number(item?.book_order_num || 0);
                orderQuantity += Number(item?.quantity || 0);
                orderAmount += Number(item?.amount || 0);
            }

            if (isMeituanAdsDataRow(item)) {
                adsRows.push(item);
                adsExposure += getMeituanExposureMetric(item);
                adsClick += getMeituanClickMetric(item);
            }
        }

        const trafficAvgFlowRate = trafficFlowRateCount > 0 ? trafficFlowRateSum / trafficFlowRateCount : 0;

        return {
            overviewRows,
            trafficRows,
            orderRows,
            adsRows,
            overviewRowsCount: overviewRows.length,
            overviewBookOrder,
            overviewAmount,
            overviewQuantity,
            trafficExposure,
            trafficClick,
            trafficAvgFlowRate,
            trafficClickRate: safeDivideMetric(trafficClick, trafficExposure) * 100,
            orderRowsCount: orderRows.length,
            orderBookOrder,
            orderQuantity,
            orderAmount,
            adsRowsCount: adsRows.length,
            adsExposure,
            adsClick,
            adsClickRate: safeDivideMetric(adsClick, adsExposure) * 100,
        };
    };

    const defaultMeituanAdsUrl = () => 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry?continueUrl=/app/peon-merchant-product-menu/html/index.html';
    const runPostFetchRefresh = (callback, ...args) => {
        try {
            Promise.resolve(callback(...args)).catch(error => {
                if (typeof console !== 'undefined' && console.error) {
                    console.error('[meituan-static] post-fetch refresh failed:', error);
                }
            });
        } catch (error) {
            if (typeof console !== 'undefined' && console.error) {
                console.error('[meituan-static] post-fetch refresh failed:', error);
            }
        }
    };

    const createMeituanRankingForm = () => ({
        url: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
        hotelId: '',
        partnerId: '',
        poiId: '',
        rankType: 'P_RZ',
        rankTypes: ['P_RZ', 'P_XS', 'P_ZH', 'P_LL_EXPOSE'],
        dateRanges: ['1'],
        startDate: '',
        endDate: '',
        cookies: '',
        auth_data: {},
        hotelRoomCount: '',
        competitorRoomCount: '',
    });

    const createMeituanTrafficForm = () => ({
        url: '',
        partnerId: '',
        poiId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        extraParams: '',
    });

    const createMeituanOrderForm = () => ({
        url: '',
        method: 'GET',
        partnerId: '',
        poiId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        payloadJson: '',
        extraParams: '',
        csvText: '',
    });

    const createMeituanAdsForm = () => ({
        url: '',
        method: 'GET',
        partnerId: '',
        poiId: '',
        shopId: '',
        startDate: '',
        endDate: '',
        cookies: '',
        payloadJson: '',
        extraParams: '',
    });

    const createMeituanBrowserCaptureForm = () => ({
        storeId: '',
        poiId: '',
        poiName: '',
        adsUrl: defaultMeituanAdsUrl(),
        captureSections: ['traffic'],
        payloadJson: '',
    });

    const getMeituanBrowserCaptureSupplementModules = () => ([
        { key: 'peer_rank', label: '同行排名', endpoint: 'peer/rank/data/detail' },
        { key: 'traffic_analysis', label: '流量分析', endpoint: 'flowConversion / flowTrend / flowTrendDetail' },
        { key: 'search_keywords', label: '搜索关键词', endpoint: 'searchKeyWords' },
        { key: 'traffic_forecast', label: '未来30天预测', endpoint: 'flowForecast' },
    ]);

    const meituanBrowserCaptureCountValue = (value) => {
        if (Array.isArray(value)) return value.length;
        const numeric = Number(value);
        return Number.isFinite(numeric) && numeric > 0 ? numeric : 0;
    };

    const firstMeituanBrowserCaptureCount = (sources, keys) => {
        for (const source of sources) {
            if (!source || typeof source !== 'object') continue;
            for (const key of keys) {
                if (Object.prototype.hasOwnProperty.call(source, key)) {
                    const count = meituanBrowserCaptureCountValue(source[key]);
                    if (count > 0) return count;
                }
            }
        }
        return 0;
    };

    const buildMeituanBrowserCaptureSupplementCounts = (result = {}) => {
        const payload = result?.payload && typeof result.payload === 'object' ? result.payload : {};
        const sources = [
            result?.payload_counts,
            result?.payloadCounts,
            result?.counts,
            result?.sync_summary,
            result?.syncSummary,
            payload?.payload_counts,
            payload?.counts,
            payload?.sync_summary,
            payload,
            result,
        ];
        return [
            {
                key: 'peer_rank',
                label: '同行排名',
                count: firstMeituanBrowserCaptureCount(sources, ['peer_rank', 'peerRank', 'peer_rank_count', 'peerRankCount']),
            },
            {
                key: 'traffic_analysis',
                label: '流量分析',
                count: firstMeituanBrowserCaptureCount(sources, ['traffic_analysis', 'flowAnalysis', 'flow_analysis', 'flow_analysis_count', 'traffic_analysis_count']),
            },
            {
                key: 'search_keywords',
                label: '搜索关键词',
                count: firstMeituanBrowserCaptureCount(sources, ['search_keywords', 'searchKeywords', 'search_keyword', 'search_keyword_count']),
            },
            {
                key: 'traffic_forecast',
                label: '未来30天预测',
                count: firstMeituanBrowserCaptureCount(sources, ['traffic_forecast', 'trafficForecast', 'flowForecast', 'traffic_forecast_count']),
            },
            {
                key: 'responses',
                label: '监听响应',
                count: firstMeituanBrowserCaptureCount(sources, ['responses', 'response_count', 'responseCount']),
            },
        ];
    };

    const normalizeMeituanCaptureSections = (sections) => {
        const aliases = {
            review: 'reviews',
            reviews: 'reviews',
            comment: 'reviews',
            comments: 'reviews',
            traffic: 'traffic',
            flow: 'traffic',
            peer_rank: 'traffic',
            peerrank: 'traffic',
            peer: 'traffic',
            competitor_rank: 'traffic',
            competitorrank: 'traffic',
            ranking: 'traffic',
            flowanalysis: 'traffic',
            flow_analysis: 'traffic',
            traffic_analysis: 'traffic',
            trafficanalysis: 'traffic',
            flowconversion: 'traffic',
            flowtrend: 'traffic',
            flowtrenddetail: 'traffic',
            flowforecast: 'traffic',
            flow_forecast: 'traffic',
            trafficforecast: 'traffic',
            traffic_forecast: 'traffic',
            searchkeyword: 'traffic',
            searchkeywords: 'traffic',
            search_keyword: 'traffic',
            search_keywords: 'traffic',
            ads: 'ads',
            ad: 'ads',
            advertising: 'ads',
            orders: 'orders',
            order: 'orders',
        };
        const raw = Array.isArray(sections) ? sections : String(sections || '').split(/[,\s]+/);
        const normalized = raw
            .map(item => aliases[String(item || '').trim().toLowerCase()] || '')
            .filter(Boolean);
        return Array.from(new Set(normalized));
    };

    const buildMeituanBrowserCaptureRequestContext = ({
        form = {},
        systemHotelId = null,
        fallbackPoiId = '',
        partnerId = '',
        hotelName = '',
        options = {},
    } = {}) => {
        const loginOnly = Boolean(options.loginOnly);
        const bindDataSource = options.bindDataSource !== false;
        const storeId = String(form.storeId || form.poiId || fallbackPoiId || '').trim();
        const sections = normalizeMeituanCaptureSections(form.captureSections);
        if (!systemHotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        if (!storeId) {
            return { ok: false, status: 'missing_store_id', level: 'error', message: '请填写美团门店标识' };
        }
        if (sections.includes('ads') && !String(form.adsUrl || '').trim()) {
            return { ok: false, status: 'missing_ads_url', level: 'error', message: '请填写推广通广告入口 URL' };
        }
        return {
            ok: true,
            status: 'ok',
            loginOnly,
            bindDataSource,
            requestBody: {
                system_hotel_id: systemHotelId,
                store_id: storeId,
                poi_id: form.poiId || storeId,
                poi_name: form.poiName || hotelName,
                partner_id: partnerId || '',
                ads_url: sections.includes('ads') ? (form.adsUrl || '') : '',
                sections,
                login_only: loginOnly,
                bind_data_source: bindDataSource,
            },
        };
    };

    const runMeituanBrowserCaptureFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getFallbackPoiId = () => '',
        getPartnerId = () => '',
        getHotelNameById = () => '',
        options = {},
        notify = () => {},
        setRunning = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        requestCapture = async () => ({}),
        refreshOnlineHistory = async () => {},
        refreshPlatformProfileStatus = async () => {},
        refreshPlatformDataSources = async () => {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        const requestContext = buildMeituanBrowserCaptureRequestContext({
            form: getForm() || {},
            systemHotelId,
            fallbackPoiId: getFallbackPoiId(),
            partnerId: getPartnerId(),
            hotelName: getHotelNameById(systemHotelId),
            options,
        });
        if (!requestContext.ok) {
            notify(requestContext.message, requestContext.level);
            return { status: requestContext.status, requestContext };
        }

        setRunning(true);
        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestCapture(requestContext.requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setCaptureResult(data);
                setOnlineDataResult(data);
                notify(res.message || (requestContext.loginOnly ? '美团 Profile 登录状态已保存' : `抓取完成，已入库 ${data?.saved_count || 0} 条`));
                if (!requestContext.loginOnly) {
                    runPostFetchRefresh(refreshOnlineHistory);
                }
                if (requestContext.loginOnly || requestContext.bindDataSource) {
                    runPostFetchRefresh(refreshPlatformProfileStatus, { silent: true });
                    if (requestContext.bindDataSource) {
                        runPostFetchRefresh(refreshPlatformDataSources);
                    }
                }
                return { status: 'success', response: res, requestContext, data };
            }

            notify(res.message || '抓取失败', 'error');
            return { status: 'failed', response: res, requestContext };
        } catch (error) {
            const detail = error?.data?.data?.stderr || error?.data?.data?.stdout || '';
            notify('抓取失败: ' + error.message + (detail ? '，请查看结果详情' : ''), 'error');
            const errorResult = error?.data?.data || { error: error.message };
            setCaptureResult(errorResult);
            return { status: 'exception', error, requestContext, errorResult };
        } finally {
            setRunning(false);
            setFetching(false);
        }
    };

    const buildMeituanCapturedPayloadSaveContext = ({
        form = {},
        systemHotelId = null,
        hotelName = '',
    } = {}) => {
        if (!systemHotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        const rawJson = String(form.payloadJson || '').trim();
        if (!rawJson) {
            return { ok: false, status: 'missing_payload_json', level: 'error', message: '请粘贴抓取结果 JSON' };
        }

        let payload;
        try {
            payload = JSON.parse(rawJson);
        } catch (error) {
            return { ok: false, status: 'invalid_json', level: 'error', message: '抓取结果 JSON 格式不正确: ' + error.message };
        }
        if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
            return { ok: false, status: 'invalid_payload_object', level: 'error', message: '抓取结果必须是 JSON 对象' };
        }

        const storeId = String(form.storeId || '').trim();
        const poiId = String(form.poiId || storeId || '').trim();
        const poiName = String(form.poiName || hotelName || '').trim();
        const enrichedPayload = { ...payload };
        enrichedPayload.store_id = enrichedPayload.store_id || storeId || poiId;
        enrichedPayload.poi_id = enrichedPayload.poi_id || poiId || storeId;
        enrichedPayload.poi_name = enrichedPayload.poi_name || poiName;
        enrichedPayload.system_hotel_id = enrichedPayload.system_hotel_id || Number(systemHotelId);

        return {
            ok: true,
            status: 'ok',
            payload: enrichedPayload,
            requestBody: {
                system_hotel_id: systemHotelId,
                payload: enrichedPayload,
            },
        };
    };

    const runMeituanCapturedPayloadSaveFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setCaptureResult = () => {},
        setOnlineDataResult = () => {},
        requestSave = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const systemHotelId = getSystemHotelId();
        const saveContext = buildMeituanCapturedPayloadSaveContext({
            form: getForm() || {},
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        if (!saveContext.ok) {
            notify(saveContext.message, saveContext.level);
            return { status: saveContext.status, saveContext };
        }

        setFetching(true);
        setCaptureResult(null);
        try {
            const res = await requestSave(saveContext.requestBody);
            if (res.code === 200) {
                const data = res.data || {};
                setCaptureResult(data);
                setOnlineDataResult(data);
                notify(`保存成功，已入库 ${data?.saved_count || 0} 条`);
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, saveContext, data };
            }

            notify(res.message || '保存失败', 'error');
            return { status: 'failed', response: res, saveContext };
        } catch (error) {
            notify('保存失败: ' + error.message, 'error');
            return { status: 'exception', error, saveContext };
        } finally {
            setFetching(false);
        }
    };

    const meituanBatchRankTypes = ['P_RZ', 'P_XS', 'P_ZH', 'P_LL'];
    const meituanBatchRankTypeNames = {
        P_RZ: '入住榜（入住间夜+房费收入）',
        P_XS: '销售榜（销售间夜+销售额）',
        P_ZH: '转化榜（浏览转化+支付转化）',
        P_LL: '流量榜（曝光+浏览）',
    };
    const meituanBatchDateRangeNames = {
        0: '今日实时',
        1: '昨日',
        7: '近7天',
        30: '近30天',
        custom: '自定义时间',
    };
    const validateMeituanBatchFetchInput = ({
        form = {},
        cookies = '',
        partnerId = '',
        poiId = '',
    } = {}) => {
        if (!form.hotelId) {
            return { ok: false, status: 'missing_hotel', level: 'error', message: '请选择目标酒店' };
        }
        if (!String(cookies || '').trim()) {
            return { ok: false, level: 'error', message: '平台授权缺失：请提供美团平台授权内容' };
        }
        const missingResourceFields = [];
        if (!String(partnerId || '').trim()) {
            missingResourceFields.push('平台接口标识');
        }
        if (!String(poiId || '').trim()) {
            missingResourceFields.push('平台门店标识');
        }
        if (missingResourceFields.length > 0) {
            return {
                ok: false,
                level: 'warning',
                message: `需补充一次性门店标识：${missingResourceFields.join(' / ')}。请在本页临时填写，或在酒店管理中保存后再获取美团榜单。`,
            };
        }
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        if (dateRanges.length === 0) {
            return { ok: false, level: 'error', message: '请至少选择一个时间维度' };
        }
        if (dateRanges.includes('custom') && (!form.startDate || !form.endDate)) {
            return { ok: false, level: 'error', message: '请填写自定义时间的开始和结束日期' };
        }
        return {
            ok: true,
            level: 'success',
            cookies: String(cookies || '').trim(),
            partnerId: String(partnerId || '').trim(),
            poiId: String(poiId || '').trim(),
        };
    };

    const buildMeituanBatchFetchTasks = ({
        form = {},
        partnerId = '',
        poiId = '',
        cookies = '',
    } = {}) => {
        const dateRanges = Array.isArray(form.dateRanges) ? form.dateRanges : [];
        const tasks = [];
        dateRanges.forEach(dateRange => {
            meituanBatchRankTypes.forEach(rankType => {
                const rangeName = meituanBatchDateRangeNames[dateRange] || dateRange;
                const rankName = meituanBatchRankTypeNames[rankType] || rankType;
                const body = {
                    url: form.url,
                    partner_id: partnerId,
                    poi_id: poiId,
                    rank_type: rankType,
                    date_range: dateRange,
                    cookies,
                    auth_data: form.auth_data,
                    auto_save: true,
                    system_hotel_id: form.hotelId,
                };
                if (dateRange === 'custom') {
                    body.start_date = form.startDate;
                    body.end_date = form.endDate;
                }
                tasks.push({
                    rankType,
                    rankName,
                    dateRange,
                    dateRangeName: rangeName,
                    toastText: `正在获取 ${rangeName} - ${rankName}...`,
                    body,
                });
            });
        });
        return tasks;
    };
    const buildMeituanBatchFetchResultEntry = (task, response = {}) => {
        const base = {
            rankType: task.rankType,
            rankName: task.rankName,
            dateRange: task.dateRange,
            dateRangeName: task.dateRangeName,
        };
        if (response.code === 200) {
            const responseData = response.data || {};
            const responseStatus = String(responseData.status || '').toLowerCase();
            if (['accepted', 'running', 'queued'].includes(responseStatus)) {
                return {
                    ...base,
                    message: response.message || '',
                    status: responseData.status || responseStatus || 'running',
                    taskId: responseData.task_id || '',
                    platform: responseData.platform || 'meituan',
                    async: responseData.async !== false,
                    savedCount: responseData.saved_count || 0,
                    displayHotels: [],
                    displaySummary: null,
                    displayCount: 0,
                };
            }
            return {
                ...base,
                message: response.message || '',
                status: responseData.status || 'success',
                taskId: responseData.task_id || '',
                data: responseData.data,
                savedCount: responseData.saved_count || 0,
                displayHotels: responseData.display_hotels || [],
                displaySummary: responseData.display_summary || null,
                displayCount: responseData.display_hotel_count || (responseData.display_hotels || []).length,
            };
        }
        return {
            ...base,
            error: response.message || '获取失败',
        };
    };
    const buildMeituanBatchFetchPendingEntry = (task) => ({
        rankType: task.rankType,
        rankName: task.rankName,
        dateRange: task.dateRange,
        dateRangeName: task.dateRangeName,
        status: 'fetching',
        message: '正在请求平台接口',
        taskId: '',
        savedCount: 0,
        displayHotels: [],
        displaySummary: null,
        displayCount: 0,
    });
    const isMeituanBackgroundAcceptedResponse = (response = {}) => {
        if (response.code !== 200) {
            return false;
        }
        const status = String(response.data?.status || '').toLowerCase();
        return ['accepted', 'running', 'queued'].includes(status);
    };
    const buildMeituanDisplayModelPayload = ({ results = [], form = {} } = {}) => ({
        display_hotels: (Array.isArray(results) ? results : []).flatMap(result => Array.isArray(result.displayHotels) ? result.displayHotels : []),
        competitor_room_count: form.competitorRoomCount,
        target_poi_id: form.poiId,
        date_ranges: form.dateRanges,
        start_date: form.startDate,
        end_date: form.endDate,
    });

    const normalizeMeituanCookieText = (value) => String(value || '').replace(/^[\s\n]+|[\s\n]+$/g, '').replace(/\n/g, '');

    const firstMeituanConfigText = (...values) => {
        const value = values.find(item => item !== undefined && item !== null && String(item).trim() !== '');
        return value === undefined ? '' : String(value).trim();
    };

    const hasObjectValue = (value) => value && typeof value === 'object' && Object.keys(value).length > 0;

    const isSameJsonObject = (left = {}, right = {}) => {
        try {
            return JSON.stringify(left || {}) === JSON.stringify(right || {});
        } catch (error) {
            return false;
        }
    };

    const isMeituanRankingFormAlignedWithConfig = (form = {}, config = {}) => {
        if (!form || !config) return false;
        const formHotelId = String(form.hotelId || '').trim();
        const configHotelId = firstMeituanConfigText(config.hotel_id, config.system_hotel_id);
        if (formHotelId && configHotelId && formHotelId !== configHotelId) return false;

        const formPartnerId = String(form.partnerId || '').trim();
        const formPoiId = String(form.poiId || '').trim();
        const formCookies = normalizeMeituanCookieText(form.cookies);
        if (!formPartnerId || !formPoiId || !formCookies) return false;

        const configPartnerId = firstMeituanConfigText(config.partner_id, config.partnerId);
        const configPoiId = firstMeituanConfigText(config.poi_id, config.poiId, config.store_id, config.storeId);
        if (!configPartnerId || !configPoiId) return false;
        if (configPartnerId && formPartnerId !== configPartnerId) return false;
        if (configPoiId && formPoiId !== configPoiId) return false;

        const configCookies = normalizeMeituanCookieText(config.cookies);
        if (!configCookies) return false;
        if (configCookies && formCookies !== configCookies) return false;

        if (hasObjectValue(config.auth_data) && !isSameJsonObject(form.auth_data || {}, config.auth_data || {})) {
            return false;
        }
        return true;
    };

    const runMeituanManualTabSwitch = async ({
        tab = '',
        getCurrentPage = () => '',
        getCurrentTab = () => '',
        loadConfigList = async () => {},
        syncTrafficConfig = async () => {},
        syncOrderConfig = async () => {},
        syncAdsConfig = async () => {},
        applyRankingConfig = async () => {},
    } = {}) => {
        const isActive = () => getCurrentPage() === 'meituan-ebooking' && getCurrentTab() === tab;
        if (!isActive()) return { status: 'stale_before_load', tab };

        await loadConfigList();
        if (!isActive()) return { status: 'stale_after_load', tab };

        if (tab === 'meituan-traffic') {
            await syncTrafficConfig();
            return { status: 'synced', tab, target: 'traffic' };
        }
        if (tab === 'meituan-orders') {
            await syncOrderConfig();
            return { status: 'synced', tab, target: 'orders' };
        }
        if (tab === 'meituan-ads') {
            await syncAdsConfig();
            return { status: 'synced', tab, target: 'ads' };
        }
        await applyRankingConfig();
        return { status: 'synced', tab, target: 'ranking' };
    };

    const normalizeMeituanTrafficFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        form.cookies = normalizeMeituanCookieText(form.cookies);
        return form;
    };

    const validateMeituanTrafficFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请输入接口地址' };
        }
        if (!form.partnerId) {
            return { ok: false, status: 'missing_partner_id', level: 'error', message: '需一次性平台接口标识：请输入平台接口标识' };
        }
        if (!form.poiId) {
            return { ok: false, status: 'missing_poi_id', level: 'error', message: '需一次性平台门店标识：请输入平台门店标识' };
        }
        if (!form.cookies) {
            return { ok: false, status: 'missing_cookies', level: 'error', message: '平台授权缺失：请输入平台授权内容' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanTrafficFetchRequestBody = ({
        form = {},
        systemHotelId = null,
    } = {}) => ({
        url: form.url,
        partner_id: form.partnerId,
        poi_id: form.poiId,
        cookies: form.cookies,
        start_date: form.startDate,
        end_date: form.endDate,
        auto_save: true,
        extra_params: form.extraParams,
        system_hotel_id: systemHotelId,
    });

    const runMeituanTrafficFetchFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        notify = () => {},
        setFetching = () => {},
        setOnlineDataResult = () => {},
        setLatestTrafficData = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
    } = {}) => {
        const form = normalizeMeituanTrafficFetchForm(getForm() || {});
        const validation = validateMeituanTrafficFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setOnlineDataResult(null);
        const requestBody = buildMeituanTrafficFetchRequestBody({
            form,
            systemHotelId: getSystemHotelId(),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setOnlineDataResult(runningPayload);
                setLatestTrafficData(runningPayload);
                notify(res.message || '美团流量手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                const trafficData = data.data;
                setOnlineDataResult(trafficData);
                setLatestTrafficData(trafficData);
                const savedCount = data.saved_count || 0;
                if (savedCount > 0) {
                    notify(`获取成功！已保存 ${savedCount} 条流量数据`);
                    runPostFetchRefresh(refreshOnlineHistory);
                    if (getOnlineDataTab() === 'data') {
                        refreshOnlineData();
                    }
                } else {
                    notify('获取成功，但未解析到有效流量数据');
                }
                return { status: 'success', response: res, requestBody: directRequestBody, data: trafficData, savedCount };
            }

            notify(res.message || '获取失败', 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            notify('请求失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const normalizeMeituanOrderFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.method = String(form.method || 'GET').toUpperCase();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        form.cookies = normalizeMeituanCookieText(form.cookies);
        form.payloadJson = String(form.payloadJson || '').trim();
        form.extraParams = String(form.extraParams || '').trim();
        return form;
    };

    const validateMeituanOrderFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请填写订单接口 Request URL' };
        }
        if (form.url.includes('/order-eb/index.html')) {
            return { ok: false, status: 'invalid_page_url', level: 'error', message: '请填写 Network 中 /orders/list 的接口 URL，不是订单页面 URL' };
        }
        if (!form.partnerId) {
            return { ok: false, status: 'missing_partner_id', level: 'error', message: '请输入平台接口标识' };
        }
        if (!form.poiId) {
            return { ok: false, status: 'missing_poi_id', level: 'error', message: '请输入平台门店标识' };
        }
        if (!form.cookies) {
            return { ok: false, status: 'missing_cookies', level: 'error', message: '请输入 Cookies' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanOrderFetchRequestBody = ({
        form = {},
        systemHotelId = null,
        hotelName = '',
    } = {}) => ({
        url: form.url,
        method: form.method,
        partner_id: form.partnerId,
        poi_id: form.poiId,
        cookies: form.cookies,
        start_date: form.startDate,
        end_date: form.endDate,
        payload_json: form.payloadJson,
        extra_params: form.extraParams,
        auto_save: true,
        system_hotel_id: systemHotelId,
        hotel_name: hotelName,
    });

    const buildMeituanOrderDomCollectorScript = () => String.raw`// ==UserScript==
// @name         SUXIOS 美团订单页 CSV 导出
// @namespace    suxios.hotel.ota
// @version      1.0.0
// @description  在已授权登录的美团 eBooking 订单页导出订单号、房型、入住日期、离店日期、购买时间、底价 CSV，用于宿析OS临时补录。
// @match        https://eb.meituan.com/ebooking/order-eb/*
// @match        https://me.meituan.com/ebooking/merchant/ebIframe*
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
  'use strict';

  var PANEL_ID = 'suxi-meituan-order-dom-panel';
  var rows = [];

  function status(text) {
    var el = document.querySelector('#' + PANEL_ID + ' [data-role="status"]');
    if (el) el.textContent = text;
  }

  function currentYearDate(mmdd) {
    if (!/^\d{2}-\d{2}$/.test(mmdd || '')) return mmdd || '';
    return new Date().getFullYear() + '-' + mmdd;
  }

  function nearestOrderContainer(anchor) {
    var node = anchor;
    for (var i = 0; i < 12 && node && node.parentElement; i++) {
      node = node.parentElement;
      var text = node.innerText || '';
      if (/订单号/.test(text) && /购买时间/.test(text) && /底价/.test(text)) return node;
    }
    return anchor;
  }

  function roomTypeFrom(container) {
    var roomEl = container.querySelector('.order-room-info-wrapper');
    if (!roomEl) return '';
    for (var i = 0; i < roomEl.childNodes.length; i++) {
      var child = roomEl.childNodes[i];
      if (child.nodeType === Node.TEXT_NODE && child.textContent.trim()) {
        return child.textContent.trim().split('+')[0].trim();
      }
    }
    return (roomEl.innerText || '').split('+')[0].split('\n')[0].trim();
  }

  function extractPageRows() {
    var result = [];
    var seen = new Set();
    var dateNodes = Array.prototype.slice.call(document.querySelectorAll('.order-date-wrapper'));
    dateNodes.forEach(function (dateEl) {
      var dateText = dateEl.innerText || '';
      var dateMatch = dateText.match(/(\d{2}-\d{2})\s+至\s+(\d{2}-\d{2})/);
      var container = nearestOrderContainer(dateEl);
      var text = container.innerText || '';
      var orderMatch = text.match(/订单号[：:]\s*(\d{10,30})/);
      var orderNo = orderMatch ? orderMatch[1] : '';
      if (!orderNo || seen.has(orderNo)) return;
      seen.add(orderNo);
      var priceMatch = text.match(/底价[：:]\s*([0-9.]+)/);
      var buyMatch = text.match(/购买时间[：:]\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?)/);
      result.push({
        orderNo: orderNo,
        roomType: roomTypeFrom(container),
        checkIn: dateMatch ? currentYearDate(dateMatch[1]) : '',
        checkOut: dateMatch ? currentYearDate(dateMatch[2]) : '',
        buyTime: buyMatch ? buyMatch[1] : '',
        bottomPrice: priceMatch ? priceMatch[1] : ''
      });
    });
    return result;
  }

  function csvEscape(value) {
    return '"' + String(value || '').replace(/"/g, '""') + '"';
  }

  function exportCsv(data) {
    var headers = ['订单号', '房型', '入住日期', '离店日期', '购买时间', '底价(元)'];
    var body = data.map(function (row) {
      return [row.orderNo, row.roomType, row.checkIn, row.checkOut, row.buyTime, row.bottomPrice].map(csvEscape).join(',');
    });
    var csv = '\uFEFF' + headers.join(',') + '\n' + body.join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = '美团订单_订单页导出_' + new Date().toISOString().slice(0, 10) + '_共' + data.length + '条.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  function collectCurrentPage() {
    var pageRows = extractPageRows();
    var known = new Set(rows.map(function (row) { return row.orderNo; }));
    pageRows.forEach(function (row) {
      if (!known.has(row.orderNo)) rows.push(row);
    });
    status(pageRows.length > 0 ? '当前页解析 ' + pageRows.length + ' 条，累计 ' + rows.length + ' 条' : '当前页未找到可解析订单，请确认在美团订单列表页');
  }

  function mountPanel() {
    if (document.getElementById(PANEL_ID)) return;
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.style.cssText = 'position:fixed;right:16px;top:72px;z-index:2147483647;width:300px;padding:14px;background:#fff;border:2px solid #10b981;border-radius:10px;box-shadow:0 8px 28px rgba(16,185,129,.18);font:13px/1.5 Arial,Microsoft YaHei,sans-serif;color:#1f2937;';
    panel.innerHTML = '<div style="font-weight:700;color:#047857;margin-bottom:8px">SUXIOS 美团订单 CSV 导出</div>' +
      '<div style="font-size:12px;color:#4b5563;margin-bottom:10px">仅读取当前已授权订单页可见内容，导出后回宿析OS导入。</div>' +
      '<button data-role="collect" style="width:100%;padding:8px 0;margin-bottom:8px;border:0;border-radius:7px;background:#10b981;color:#fff;cursor:pointer">采集当前页</button>' +
      '<button data-role="export" style="width:100%;padding:8px 0;margin-bottom:8px;border:1px solid #10b981;border-radius:7px;background:#ecfdf5;color:#047857;cursor:pointer">导出 CSV</button>' +
      '<button data-role="clear" style="width:100%;padding:7px 0;margin-bottom:8px;border:1px solid #e5e7eb;border-radius:7px;background:#f9fafb;color:#4b5563;cursor:pointer">清空累计</button>' +
      '<div data-role="status" style="font-size:12px;color:#047857;word-break:break-all">就绪：先采集当前页，再翻页重复采集。</div>';
    document.body.appendChild(panel);
    panel.querySelector('[data-role="collect"]').onclick = collectCurrentPage;
    panel.querySelector('[data-role="export"]').onclick = function () {
      if (!rows.length) {
        status('暂无数据，请先采集当前页');
        return;
      }
      exportCsv(rows);
    };
    panel.querySelector('[data-role="clear"]').onclick = function () {
      rows = [];
      status('已清空累计数据');
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { setTimeout(mountPanel, 800); });
  } else {
    setTimeout(mountPanel, 800);
  }
})();`;

    const parseCsvTextRows = (text = '') => {
        const rows = [];
        let row = [];
        let cell = '';
        let inQuotes = false;
        const source = String(text || '').replace(/^\uFEFF/, '');
        for (let i = 0; i < source.length; i++) {
            const ch = source[i];
            if (ch === '"') {
                if (inQuotes && source[i + 1] === '"') {
                    cell += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
                continue;
            }
            if (ch === ',' && !inQuotes) {
                row.push(cell);
                cell = '';
                continue;
            }
            if ((ch === '\n' || ch === '\r') && !inQuotes) {
                if (ch === '\r' && source[i + 1] === '\n') {
                    i++;
                }
                row.push(cell);
                if (row.some(value => String(value || '').trim() !== '')) {
                    rows.push(row);
                }
                row = [];
                cell = '';
                continue;
            }
            cell += ch;
        }
        row.push(cell);
        if (row.some(value => String(value || '').trim() !== '')) {
            rows.push(row);
        }
        return rows;
    };

    const normalizeMeituanOrderCsvHeader = (value) => String(value || '')
        .replace(/^\uFEFF/, '')
        .replace(/\s+/g, '')
        .replace(/[（）()]/g, '')
        .toLowerCase();

    const buildMeituanOrderCsvIndex = (headers = []) => {
        const index = new Map();
        headers.forEach((header, position) => {
            const key = normalizeMeituanOrderCsvHeader(header);
            if (key && !index.has(key)) {
                index.set(key, position);
            }
        });
        return index;
    };

    const readMeituanOrderCsvValue = (row = [], index, aliases = []) => {
        for (const alias of aliases) {
            const position = index.get(normalizeMeituanOrderCsvHeader(alias));
            if (position === undefined) {
                continue;
            }
            const value = String(row[position] ?? '').trim();
            if (value !== '') {
                return value;
            }
        }
        return '';
    };

    const parseMeituanOrderCsvText = (csvText = '') => {
        const rows = parseCsvTextRows(csvText);
        if (rows.length <= 1) {
            return [];
        }
        const index = buildMeituanOrderCsvIndex(rows[0]);
        return rows.slice(1).map((row, rowIndex) => {
            const orderNo = readMeituanOrderCsvValue(row, index, ['订单号', 'orderNo', 'order_no', 'orderId', 'order_id']);
            const roomType = readMeituanOrderCsvValue(row, index, ['房型', 'roomType', 'room_type', 'roomName', 'room_name']);
            const checkIn = readMeituanOrderCsvValue(row, index, ['入住日期', 'checkIn', 'check_in', 'checkInDate', 'check_in_date']);
            const checkOut = readMeituanOrderCsvValue(row, index, ['离店日期', 'checkOut', 'check_out', 'checkOutDate', 'check_out_date']);
            const buyTime = readMeituanOrderCsvValue(row, index, ['购买时间', 'buyTime', 'buy_time', 'purchaseTime', 'purchase_time', 'createTime']);
            const bottomPrice = readMeituanOrderCsvValue(row, index, ['底价元', '底价', '底价(元)', 'basePrice', 'base_price', 'bottomPrice', 'bottom_price', 'price']);
            if (!orderNo && !roomType && !checkIn && !checkOut && !buyTime && !bottomPrice) {
                return null;
            }
            return {
                orderNo,
                roomType,
                checkIn,
                checkOut,
                buyTime,
                bottomPrice,
                _ingestion_method: 'manual_dom_csv',
                _source_path: `manual_dom_csv.orders.${rowIndex}`,
            };
        }).filter(Boolean);
    };

    const buildMeituanOrderCsvImportRequestBody = ({
        csvText = '',
        form = {},
        systemHotelId = null,
        hotelName = '',
    } = {}) => {
        const orders = parseMeituanOrderCsvText(csvText);
        const poiId = String(form.poiId || '').trim();
        return {
            system_hotel_id: systemHotelId,
            hotel_id: systemHotelId,
            payload: {
                store_id: poiId,
                poi_id: poiId,
                poi_name: hotelName,
                system_hotel_id: systemHotelId,
                default_data_date: form.endDate || form.startDate || new Date().toISOString().slice(0, 10),
                data_period: 'manual_dom_csv',
                orders,
            },
            parsed_count: orders.length,
        };
    };

    const runMeituanOrderCsvImportFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setOrderResult = () => {},
        setOnlineDataResult = () => {},
        requestSave = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanOrderFetchForm(getForm() || {});
        const csvText = String(form.csvText || '').trim();
        const systemHotelId = getSystemHotelId();
        if (!systemHotelId) {
            notify('请选择目标酒店后再导入 CSV', 'error');
            return { status: 'missing_system_hotel_id', form };
        }
        if (!csvText) {
            notify('请先粘贴美团订单 CSV 内容', 'error');
            return { status: 'missing_csv_text', form };
        }
        const requestBody = buildMeituanOrderCsvImportRequestBody({
            csvText,
            form,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        if ((requestBody.parsed_count || 0) <= 0) {
            notify('未解析到可导入的美团订单 CSV 行', 'warning');
            return { status: 'empty_csv_rows', requestBody };
        }

        setFetching(true);
        setOrderResult(null);
        setOnlineDataResult(null);
        try {
            const res = await requestSave(requestBody);
            if (res.code === 200) {
                const data = { ...(res.data || {}), import_row_count: requestBody.parsed_count };
                setOrderResult(data);
                setOnlineDataResult(data);
                const savedCount = data.saved_count || 0;
                notify(
                    savedCount > 0 ? `CSV订单导入成功，已入库 ${savedCount} 条` : 'CSV已解析，但未形成可入库订单行',
                    savedCount > 0 ? 'success' : 'warning'
                );
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, requestBody, data, savedCount };
            }
            notify(res.message || 'CSV订单导入失败', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            notify('CSV订单导入失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody };
        } finally {
            setFetching(false);
        }
    };

    const runMeituanOrderFetchFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setOrderResult = () => {},
        setOnlineDataResult = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanOrderFetchForm(getForm() || {});
        const validation = validateMeituanOrderFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setOrderResult(null);
        setOnlineDataResult(null);
        const systemHotelId = getSystemHotelId();
        const requestBody = buildMeituanOrderFetchRequestBody({
            form,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setOrderResult(runningPayload);
                setOnlineDataResult(runningPayload);
                notify(res.message || '美团订单手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                setOrderResult(data);
                setOnlineDataResult(data);
                const savedCount = data.saved_count || 0;
                notify(
                    savedCount > 0 ? `订单数据获取成功，已入库 ${savedCount} 条` : '订单接口请求成功，但未解析到可入库数据',
                    savedCount > 0 ? 'success' : 'warning'
                );
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, requestBody: directRequestBody, data, savedCount };
            }

            notify(res.message || '订单数据获取失败', 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            notify('订单数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const normalizeMeituanAdsFetchForm = (form = {}) => {
        form.url = String(form.url || '').trim();
        form.method = String(form.method || 'GET').toUpperCase();
        form.partnerId = String(form.partnerId || '').trim();
        form.poiId = String(form.poiId || '').trim();
        form.shopId = String(form.shopId || '').trim();
        form.cookies = normalizeMeituanCookieText(form.cookies);
        form.payloadJson = String(form.payloadJson || '').trim();
        form.extraParams = String(form.extraParams || '').trim();
        return form;
    };

    const validateMeituanAdsFetchInput = (form = {}) => {
        if (!form.url) {
            return { ok: false, status: 'missing_url', level: 'error', message: '需 Network 请求信息：请填写广告接口 Request URL' };
        }
        if (form.url.includes('/shopdiy/account/pcCpcEntry')) {
            return { ok: false, status: 'invalid_page_url', level: 'error', message: '请填写 Network 中 cureShops 的接口 URL，不是推广通入口页面 URL' };
        }
        if (!form.shopId && !form.poiId) {
            return { ok: false, status: 'missing_shop_or_poi_id', level: 'error', message: '请输入推广店铺或门店标识' };
        }
        if (!form.cookies) {
            return { ok: false, status: 'missing_cookies', level: 'error', message: '请输入 Cookies' };
        }
        return { ok: true, status: 'ok' };
    };

    const buildMeituanAdsFetchRequestBody = ({
        form = {},
        systemHotelId = null,
        hotelName = '',
    } = {}) => ({
        url: form.url,
        method: form.method,
        partner_id: form.partnerId,
        poi_id: form.poiId || form.shopId,
        shop_id: form.shopId || form.poiId,
        cookies: form.cookies,
        start_date: form.startDate,
        end_date: form.endDate,
        payload_json: form.payloadJson,
        extra_params: form.extraParams,
        auto_save: true,
        system_hotel_id: systemHotelId,
        hotel_name: hotelName,
    });

    const runMeituanAdsFetchFlow = async ({
        getForm = () => ({}),
        getSystemHotelId = () => null,
        getHotelNameById = () => '',
        notify = () => {},
        setFetching = () => {},
        setAdsResult = () => {},
        setOnlineDataResult = () => {},
        requestFetch = async () => ({}),
        refreshOnlineHistory = async () => {},
    } = {}) => {
        const form = normalizeMeituanAdsFetchForm(getForm() || {});
        const validation = validateMeituanAdsFetchInput(form);
        if (!validation.ok) {
            notify(validation.message, validation.level);
            return { status: validation.status, validation, form };
        }

        setFetching(true);
        setAdsResult(null);
        setOnlineDataResult(null);
        const systemHotelId = getSystemHotelId();
        const requestBody = buildMeituanAdsFetchRequestBody({
            form,
            systemHotelId,
            hotelName: getHotelNameById(systemHotelId),
        });
        const directRequestBody = { ...requestBody, async: false, background: false };
        try {
            const res = await requestFetch(directRequestBody);
            if (isMeituanBackgroundAcceptedResponse(res)) {
                const data = res.data || {};
                const runningPayload = {
                    status: data.status || 'running',
                    task_id: data.task_id || '',
                    platform: data.platform || 'meituan',
                    async: true,
                    saved_count: data.saved_count || 0,
                    request_start_date: data.request_start_date || requestBody.start_date || '',
                    request_end_date: data.request_end_date || requestBody.end_date || '',
                };
                setAdsResult(runningPayload);
                setOnlineDataResult(runningPayload);
                notify(res.message || '美团广告手动获取已提交后台执行，完成后会更新数据列表和通知', 'info');
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'accepted', response: res, requestBody: directRequestBody, data: runningPayload, savedCount: 0 };
            }
            if (res.code === 200) {
                const data = res.data || {};
                setAdsResult(data);
                setOnlineDataResult(data);
                const savedCount = data.saved_count || 0;
                notify(
                    savedCount > 0 ? `广告数据获取成功，已入库 ${savedCount} 条` : '广告接口请求成功，但未解析到可入库数据',
                    savedCount > 0 ? 'success' : 'warning'
                );
                runPostFetchRefresh(refreshOnlineHistory);
                return { status: 'success', response: res, requestBody: directRequestBody, data, savedCount };
            }

            notify(res.message || '广告数据获取失败', 'error');
            return { status: 'failed', response: res, requestBody: directRequestBody };
        } catch (error) {
            notify('广告数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody: directRequestBody };
        } finally {
            setFetching(false);
        }
    };

    const runMeituanBatchFetchFlow = async ({
        getForm = () => ({}),
        getSelectedConfig = () => null,
        ensureMeituanConfigSecret = async config => config,
        applyMeituanHotelConfig = async () => {},
        notify = () => {},
        setFetching = () => {},
        setOnlineDataResult = () => {},
        setFetchSuccess = () => {},
        setHotelsList = () => {},
        getEmptyBusinessSummary = () => ({}),
        setBusinessSummary = () => {},
        requestFetch = async () => ({}),
        requestDisplayModel = async () => ({}),
        useDisplayModel = rows => rows,
        setSavedCount = () => {},
        setDataFetchTime = () => {},
        getFetchTime = () => new Date().toLocaleString('zh-CN'),
        updateAiAnalysisHotelList = () => {},
        refreshOnlineHistory = async () => {},
        getOnlineDataTab = () => '',
        refreshOnlineData = () => {},
    } = {}) => {
        let form = getForm() || {};
        const selectedMeituanConfig = form.hotelId
            ? await ensureMeituanConfigSecret(getSelectedConfig())
            : null;
        if (!isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {
            if (selectedMeituanConfig) {
                await applyMeituanHotelConfig(false, {
                    resolvedConfig: selectedMeituanConfig,
                    refreshList: false,
                    skipIfAligned: true,
                });
                form = getForm() || form;
            }
        }
        const meituanCookies = String(form.cookies || '').trim();
        const partnerId = String(form.partnerId || '').trim();
        const poiId = String(form.poiId || '').trim();
        const batchInput = validateMeituanBatchFetchInput({
            form,
            cookies: meituanCookies,
            partnerId,
            poiId,
        });
        if (!batchInput.ok) {
            notify(batchInput.message, batchInput.level);
            return { status: batchInput.status || 'invalid_input', batchInput };
        }

        setFetching(true);
        setOnlineDataResult(null);
        setFetchSuccess(false);
        const fetchTasks = buildMeituanBatchFetchTasks({
            form,
            partnerId,
            poiId,
            cookies: meituanCookies,
        });
        const results = fetchTasks.map(task => buildMeituanBatchFetchPendingEntry(task));
        let resultUpdateTimer = null;
        let cancelResultUpdate = null;
        const scheduleResultUpdate = () => {
            if (resultUpdateTimer) return;
            const commit = () => {
                resultUpdateTimer = null;
                cancelResultUpdate = null;
                setOnlineDataResult([...results]);
            };
            if (typeof requestAnimationFrame === 'function') {
                resultUpdateTimer = requestAnimationFrame(commit);
                cancelResultUpdate = () => {
                    if (typeof cancelAnimationFrame === 'function') cancelAnimationFrame(resultUpdateTimer);
                };
                return;
            }
            if (typeof setTimeout === 'function') {
                resultUpdateTimer = setTimeout(commit, 0);
                cancelResultUpdate = () => {
                    if (typeof clearTimeout === 'function') clearTimeout(resultUpdateTimer);
                };
                return;
            }
            commit();
        };
        const flushResultUpdate = () => {
            if (resultUpdateTimer && typeof cancelResultUpdate === 'function') {
                cancelResultUpdate();
            }
            resultUpdateTimer = null;
            cancelResultUpdate = null;
            setOnlineDataResult([...results]);
        };
        if (results.length > 0) {
            setOnlineDataResult([...results]);
            setFetchSuccess(true);
            setDataFetchTime(getFetchTime());
        }
        let totalSavedCount = 0;
        let acceptedCount = 0;

        try {
            await Promise.all(fetchTasks.map(async (task, index) => {
                notify(task.toastText);
                const requestBody = { ...task.body, async: false, background: false };
                const res = await requestFetch(requestBody);
                const accepted = isMeituanBackgroundAcceptedResponse(res);
                if (accepted) {
                    acceptedCount += 1;
                }
                results[index] = buildMeituanBatchFetchResultEntry(task, res);
                scheduleResultUpdate();
                if (res.code === 200 && !accepted) {
                    totalSavedCount += res.data.saved_count || 0;
                }
            }));

            flushResultUpdate();
            setSavedCount(totalSavedCount);
            if (acceptedCount > 0) {
                setFetchSuccess(true);
                setDataFetchTime(getFetchTime());
                notify(
                    acceptedCount === fetchTasks.length
                        ? `美团手动获取已提交后台执行（${acceptedCount} 个任务），完成后会更新数据列表和通知`
                        : `美团手动获取已提交 ${acceptedCount} 个后台任务，其余任务已返回结果`,
                    'info'
                );
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
                return { status: 'accepted', results, acceptedCount, totalSavedCount };
            }
            const modelRes = await requestDisplayModel(buildMeituanDisplayModelPayload({ results, form }));
            if (modelRes.code !== 200) {
                throw new Error(modelRes.message || '构建美团展示模型失败');
            }
            const allHotels = useDisplayModel(modelRes.data || {});
            setDataFetchTime(getFetchTime());
            setBusinessSummary(null);
            updateAiAnalysisHotelList();

            if (totalSavedCount > 0) {
                notify(`批量获取完成！共保存 ${totalSavedCount} 条数据`);
                runPostFetchRefresh(refreshOnlineHistory);
                if (getOnlineDataTab() === 'data') {
                    refreshOnlineData();
                }
            } else if (allHotels.length > 0) {
                notify(`获取成功！共 ${allHotels.length} 家酒店数据`);
            } else {
                notify('获取完成，但未找到有效数据');
            }
            return { status: 'success', results, totalSavedCount, allHotels };
        } catch (error) {
            notify('请求失败: ' + error.message, 'error');
            return { status: 'error', error, results, totalSavedCount };
        } finally {
            setFetching(false);
        }
    };

    const buildMeituanRankDisplayRows = (rows, field) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const metricLabel = meituanDisplayMetricLabel(field);
        const ranked = sourceRows
            .map((row, index) => ({ row, index, value: meituanSortMetricValue(row, field) }))
            .sort((a, b) => b.value - a.value || a.index - b.index);
        const total = ranked.length;
        const leaderValue = ranked[0]?.value || 0;
        const rowMap = new Map();
        ranked.forEach((item, rankIndex) => {
            const prev = ranked[rankIndex - 1] || null;
            const next = ranked[rankIndex + 1] || null;
            const gapToPrev = prev ? Math.max(0, prev.value - item.value) : 0;
            const gapToNext = next ? Math.max(0, item.value - next.value) : 0;
            const gapToLeader = Math.max(0, leaderValue - item.value);
            const gapToPrevText = prev
                ? `距前一名 ${formatMeituanSortGapValue(gapToPrev, field)}`
                : '当前表内领先';
            const gapToLeaderText = rankIndex === 0
                ? '当前TOP1'
                : `距TOP1 ${formatMeituanSortGapValue(gapToLeader, field)}`;
            const row = {
                ...item.row,
                currentPlatformRank: rankIndex + 1,
                circlePositionText: total > 0 ? `第 ${rankIndex + 1} / ${total} 名` : '平台未返回',
                gapMetric: field,
                gapMetricLabel: metricLabel,
                gapToPrev,
                gapToNext,
                gapToLeader,
                gapToPrevText,
                gapToNextText: next
                    ? `领先后一名 ${formatMeituanSortGapValue(gapToNext, field)}`
                    : '尾部酒店',
                gapToLeaderText,
                rankGapSummaryText: [gapToPrevText, gapToLeaderText, item.row?.rank30RangeText || '近30天未返回'].filter(Boolean).join(' / '),
            };
            rowMap.set(meituanDisplayRowKey(item.row, item.index), row);
        });
        return sourceRows.map((row, index) => rowMap.get(meituanDisplayRowKey(row, index)) || row);
    };

    const buildCompetitorSummaryCoreCards = ({
        summary = null,
        rows = [],
        insightByKey = () => null,
        limit = 5,
    } = {}) => {
        const sourceRows = Array.isArray(rows) ? rows : [];
        const selfRow = sourceRows.find(row => row?.isSelf);
        const topRow = sourceRows[0] || null;
        const selfInsight = insightByKey('self-position');
        const topInsight = insightByKey('top-summary');
        const healthInsight = insightByKey('rank-health');
        const rankGapInsight = insightByKey('rank-gap');
        const tagInsight = insightByKey('platform-tags');
        const tagMetricInsight = insightByKey('tag-metric-link');
        const missing = !summary || summary.data_status === 'missing';
        const baseEntry = { page: 'meituan-ebooking', tab: 'meituan-ranking' };
        return [
            {
                key: 'self-position',
                label: '本店第几',
                value: selfRow?.circlePositionText || selfInsight?.value || (missing ? '待同步' : '未返回'),
                note: selfRow?.gapToLeaderText || selfInsight?.note || '目标 POI 未配置或未出现在本次榜单',
                className: selfRow ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'top-summary',
                label: 'TOP1 是谁',
                value: topRow?.hotelName || topInsight?.value || (missing ? '待同步' : '-'),
                note: topRow ? `${topRow.circlePositionText || '榜首'} · ${topRow.gapToNextText || topRow.rankTrendText || '暂无差距'}` : (topInsight?.note || '暂无可展示榜首'),
                className: 'bg-indigo-50 text-indigo-700 border-indigo-100',
                entry: baseEntry,
            },
            {
                key: 'gap-to-prev',
                label: '与前一名差多少',
                value: rankGapInsight?.value || selfRow?.gapToPrevText || selfRow?.gapToLeaderText || (missing ? '待同步' : '未返回'),
                note: rankGapInsight?.note || (selfRow?.gapMetricLabel ? `按${selfRow.gapMetricLabel}口径计算` : '缺少本店行时不计算差距'),
                className: selfRow ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'platform-tags',
                label: 'VIP/平台标签',
                value: tagInsight?.value || (missing ? '待同步' : '未返回'),
                note: tagMetricInsight?.value && tagMetricInsight.value !== '未返回'
                    ? `${tagInsight?.note || '平台标签已返回'}；${tagMetricInsight.value}`
                    : (tagInsight?.note || '平台未返回标签时不判断权益差距'),
                className: tagInsight?.className || 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            },
            {
                key: 'rank-trend',
                label: '榜单升降',
                value: selfRow?.rankTrendText || healthInsight?.value || (missing ? '待同步' : '暂无变化'),
                note: selfRow?.rankSummaryText || healthInsight?.note || '旧数据缺少时间维度时不倒推升降',
                className: selfRow?.rankTrendClass ? `bg-white border-gray-200 ${selfRow.rankTrendClass}` : (healthInsight?.className || 'bg-gray-50 text-gray-500 border-gray-200'),
                entry: baseEntry,
            },
        ].slice(0, limit);
    };

    const buildHomeCompetitorSummaryCards = ({
        competitorSummary = null,
        coreCards = [],
        insightCards = [],
        healthRows = [],
        defaultRankTypes = [],
        sourceNotice = '仅展示美团榜单已返回字段；未返回字段保留缺失状态。',
        limit = 5,
    } = {}) => {
        const baseEntry = { page: 'meituan-ebooking', tab: 'meituan-ranking' };
        if (competitorSummary) {
            return (Array.isArray(coreCards) ? coreCards : []).slice(0, limit);
        }
        const safeInsightCards = Array.isArray(insightCards) ? insightCards : [];
        if (safeInsightCards.length) {
            return safeInsightCards.slice(0, limit).map(card => ({
                key: card.key,
                label: card.key === 'platform-tags' ? 'VIP/平台标签' : card.label,
                value: card.value || '-',
                note: card.note || sourceNotice,
                className: card.className || 'bg-white text-gray-700 border-gray-200',
                entry: baseEntry,
            }));
        }
        const safeHealthRows = Array.isArray(healthRows) ? healthRows : [];
        return (Array.isArray(defaultRankTypes) ? defaultRankTypes : []).map(item => {
            const row = safeHealthRows.find(health => health.key === item.key);
            const returned = row?.status === 'ok';
            return {
                key: item.key,
                label: item.label,
                value: row?.statusText || '待同步',
                note: row?.sourceLabel || '进入美团排名采集后返回榜单状态',
                className: returned ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-500 border-gray-200',
                entry: baseEntry,
            };
        }).slice(0, limit);
    };

    return {
        meituanDisplayMetricLabel,
        meituanSortMetricValue,
        formatMeituanSortGapValue,
        meituanDisplayRowKey,
        getOnlineDataMetricNumber,
        getMeituanExposureMetric,
        getMeituanClickMetric,
        getMeituanVisitorMetric,
        getMeituanSubmitMetric,
        getMeituanFlowRateMetric,
        isMeituanTrafficDataRow,
        isMeituanOrderDataRow,
        isMeituanAdsDataRow,
        buildMeituanDownloadData,
        defaultMeituanAdsUrl,
        createMeituanRankingForm,
        createMeituanTrafficForm,
        createMeituanOrderForm,
        createMeituanAdsForm,
        createMeituanBrowserCaptureForm,
        getMeituanBrowserCaptureSupplementModules,
        buildMeituanBrowserCaptureSupplementCounts,
        normalizeMeituanCaptureSections,
        buildMeituanBrowserCaptureRequestContext,
        runMeituanBrowserCaptureFlow,
        buildMeituanCapturedPayloadSaveContext,
        runMeituanCapturedPayloadSaveFlow,
        validateMeituanBatchFetchInput,
        buildMeituanBatchFetchTasks,
        buildMeituanBatchFetchResultEntry,
        buildMeituanDisplayModelPayload,
        normalizeMeituanCookieText,
        isMeituanRankingFormAlignedWithConfig,
        runMeituanManualTabSwitch,
        normalizeMeituanTrafficFetchForm,
        validateMeituanTrafficFetchInput,
        buildMeituanTrafficFetchRequestBody,
        runMeituanTrafficFetchFlow,
        normalizeMeituanOrderFetchForm,
        validateMeituanOrderFetchInput,
        buildMeituanOrderFetchRequestBody,
        buildMeituanOrderDomCollectorScript,
        parseMeituanOrderCsvText,
        buildMeituanOrderCsvImportRequestBody,
        runMeituanOrderCsvImportFlow,
        runMeituanOrderFetchFlow,
        normalizeMeituanAdsFetchForm,
        validateMeituanAdsFetchInput,
        buildMeituanAdsFetchRequestBody,
        runMeituanAdsFetchFlow,
        runMeituanBatchFetchFlow,
        buildMeituanRankDisplayRows,
        buildCompetitorSummaryCoreCards,
        buildHomeCompetitorSummaryCards,
    };
})();
