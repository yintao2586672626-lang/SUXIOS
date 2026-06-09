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

    return {
        ctripProfilePrimaryCategoryOptions,
        ctripProfileDefaultModuleOptions,
        ctripProfileForbiddenFieldKeys,
        ctripProfileForbiddenFieldAssets,
        ctripOverviewApiKeywords,
        ctripFlowOverviewApiGroups,
        ctripFlowOverviewDefaultRequestUrls,
    };
})();
