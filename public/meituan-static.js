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

    const normalizeMeituanCaptureSections = (sections) => {
        const aliases = {
            review: 'reviews',
            reviews: 'reviews',
            comment: 'reviews',
            comments: 'reviews',
            traffic: 'traffic',
            flow: 'traffic',
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
            return { ok: false, level: 'error', message: '请选择目标酒店' };
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
                message: `需补充一次性门店标识：${missingResourceFields.join(' / ')}。请在酒店管理中补充后再获取美团榜单。`,
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
            return {
                ...base,
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
        try {
            const res = await requestFetch(requestBody);
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
                return { status: 'success', response: res, requestBody, data: trafficData, savedCount };
            }

            notify(res.message || '获取失败', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            notify('请求失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody };
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
        try {
            const res = await requestFetch(requestBody);
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
                return { status: 'success', response: res, requestBody, data, savedCount };
            }

            notify(res.message || '订单数据获取失败', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            notify('订单数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody };
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
        try {
            const res = await requestFetch(requestBody);
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
                return { status: 'success', response: res, requestBody, data, savedCount };
            }

            notify(res.message || '广告数据获取失败', 'error');
            return { status: 'failed', response: res, requestBody };
        } catch (error) {
            notify('广告数据获取失败: ' + error.message, 'error');
            return { status: 'exception', error, requestBody };
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
        const form = getForm() || {};
        if (!form.hotelId) {
            notify('请选择目标酒店', 'error');
            return { status: 'missing_hotel' };
        }
        const selectedMeituanConfig = await ensureMeituanConfigSecret(getSelectedConfig());
        if (!selectedMeituanConfig) {
            notify('当前酒店未配置美团数据源', 'warning');
            return { status: 'missing_config' };
        }
        await applyMeituanHotelConfig(false);
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
            return { status: 'invalid_input', batchInput };
        }

        setFetching(true);
        setOnlineDataResult(null);
        setFetchSuccess(false);
        setHotelsList([]);
        setBusinessSummary(getEmptyBusinessSummary());
        const results = [];
        let totalSavedCount = 0;
        let acceptedCount = 0;
        const fetchTasks = buildMeituanBatchFetchTasks({
            form,
            partnerId,
            poiId,
            cookies: meituanCookies,
        });

        try {
            for (const task of fetchTasks) {
                notify(task.toastText);
                const requestBody = { ...task.body, async: true };
                const res = await requestFetch(requestBody);
                const accepted = isMeituanBackgroundAcceptedResponse(res);
                if (accepted) {
                    acceptedCount += 1;
                }
                results.push(buildMeituanBatchFetchResultEntry(task, res));
                if (res.code === 200 && !accepted) {
                    totalSavedCount += res.data.saved_count || 0;
                }
            }

            setOnlineDataResult(results);
            setSavedCount(totalSavedCount);
            if (acceptedCount > 0) {
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
        defaultMeituanAdsUrl,
        createMeituanRankingForm,
        createMeituanTrafficForm,
        createMeituanOrderForm,
        createMeituanAdsForm,
        createMeituanBrowserCaptureForm,
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
        normalizeMeituanTrafficFetchForm,
        validateMeituanTrafficFetchInput,
        buildMeituanTrafficFetchRequestBody,
        runMeituanTrafficFetchFlow,
        normalizeMeituanOrderFetchForm,
        validateMeituanOrderFetchInput,
        buildMeituanOrderFetchRequestBody,
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
