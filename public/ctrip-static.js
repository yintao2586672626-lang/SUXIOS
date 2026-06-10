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

    const hasVisibleCtripMetricValue = (value) => value !== undefined && value !== null && value !== '';

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

    return {
        ctripProfilePrimaryCategoryOptions,
        ctripProfileDefaultModuleOptions,
        ctripProfileForbiddenFieldKeys,
        ctripProfileForbiddenFieldAssets,
        ctripOverviewApiKeywords,
        ctripFlowOverviewApiGroups,
        ctripFlowOverviewDefaultRequestUrls,
        buildCtripOverviewMetricCards,
        buildCtripOverviewTopRankTables,
        buildCtripFlowOverviewMetricCards,
        buildCtripFlowOverviewInterfaceRows,
    };
})();
