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
    const createCtripFetchForm = () => ({
        url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
        nodeId: '24588',
        startDate: '',
        endDate: '',
        cookies: '',
        auth_data: {},
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

    const hasVisibleCtripMetricValue = (value) => value !== undefined && value !== null && value !== '';

    const ctripSortMetricValue = (row = {}, field = '') => {
        if (field === 'amount') return row.amount || 0;
        if (field === 'quantity') return row.quantity || 0;
        if (field === 'adr') return row.adr || 0;
        if (field === 'ari') return row.ari || 0;
        if (field === 'sci') return row.sci || 0;
        if (field === 'bookOrderNum') return row.bookOrderNum || 0;
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
        ctripOverviewApiKeywords,
        ctripFlowOverviewApiGroups,
        ctripFlowOverviewDefaultRequestUrls,
        createCtripFetchForm,
        createCtripTrafficForm,
        createCtripAdsBrowserCaptureForm,
        createCtripOverviewForm,
        createCtripFlowOverviewForm,
        createCtripBrowserCaptureForm,
        createCtripCookieApiForm,
        createCtripEndpointEvidenceForm,
        createCtripCommentForm,
        createCtripCommentBrowserCaptureForm,
        normalizeCtripBrowserCaptureSections,
        buildCtripBrowserCapturePayload,
        normalizeCtripBrowserCaptureErrorResult,
        buildCtripFetchDateRange,
        buildCtripFetchRequestBody,
        selectCtripFetchResponsePayload,
        buildCtripFetchMeta,
        buildCtripFetchRawFailureResult,
        buildLatestCtripSnapshotModel,
        ctripSortMetricValue,
        buildCtripSortedHotelRows,
        buildCtripOverviewMetricCards,
        buildCtripOverviewTopRankTables,
        buildCtripFlowOverviewMetricCards,
        buildCtripFlowOverviewInterfaceRows,
        buildCtripProfileRecheckInitialState,
        buildCtripProfileRecheckCaptureRefreshState,
        buildCtripProfileRecheckSuccessResult,
        buildCtripProfileRecheckErrorResult,
        buildCtripProfileRecheckInterruptedState,
        getCtripCookieApiCorePresetEndpoints,
    };
})();
