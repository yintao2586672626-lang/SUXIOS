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
        buildCtripFlowOverviewInterfaceRows,
    };
})();
