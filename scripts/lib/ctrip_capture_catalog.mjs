const sectionUrl = (url, confidence = 'observed') => ({ url, confidence });

const field = (id, label, sourceKeys, description = '', extra = {}) => ({
  id,
  label,
  sourceKeys,
  description,
  scope: extra.scope || 'ota_channel',
  unit: extra.unit || '',
  required: Boolean(extra.required),
  valueType: extra.valueType || '',
  timeScope: extra.timeScope || '',
  sourcePage: extra.sourcePage || '',
  sourceInterface: extra.sourceInterface || '',
  transformRule: extra.transformRule || '',
  missingStatus: extra.missingStatus || '',
  sampleValue: extra.sampleValue || '',
  confidenceStatus: extra.confidenceStatus || '',
});

export const CTRIP_CAPTURE_SECTIONS = {
  homepage: {
    label: '首页实时概览',
    dataType: 'business',
    aliases: ['home', 'realtime', 'real_time'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/home/mainland?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/home?microJump=true', 'inferred'),
    ],
  },
  business_overview: {
    label: '经营报告-概要-日报',
    dataType: 'business',
    aliases: ['business', 'overview', 'report', 'outline'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true'),
    ],
  },
  business_weekly_overview: {
    label: '经营报告-概要-周报',
    dataType: 'business',
    aliases: ['weekly', 'week_report', 'week', 'business_weekly', 'weekly_overview'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?micro=true&microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/weekReport?microJump=true', 'observed_from_cached_router'),
    ],
  },
  sales_report: {
    label: '经营报告-销售数据',
    dataType: 'business',
    aliases: ['sales', 'sale', 'sales_data'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', 'observed_from_user'),
    ],
  },
  room_type: {
    label: '经营报告-房型',
    dataType: 'business',
    aliases: ['room', 'rooms', 'room_type', 'roomtype'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true', 'sales_data_subtab'),
    ],
  },
  traffic_report: {
    label: '经营报告-流量数据',
    dataType: 'traffic',
    aliases: ['traffic', 'flow', 'flow_data'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true', 'observed_from_user'),
    ],
  },
  comment_review: {
    label: '订单点评-点评聚合',
    dataType: 'quality',
    aliases: ['comment', 'comments', 'review', 'reviews', 'comment_review', 'order_comment', 'order_reviews'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/comment/commentList?microJump=true', 'observed_from_user'),
    ],
  },
  competitor_overview: {
    label: '竞争圈动态-竞争圈概览',
    dataType: 'business',
    aliases: ['competitor', 'compete', 'competitor_overview', 'marketanalysis'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'sidebar_navigation'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/marketanalysis/overview?microJump=true', 'inferred'),
    ],
  },
  loss_analysis: {
    label: '竞争圈动态-流失分析',
    dataType: 'business',
    aliases: ['loss', 'loss_analysis', 'lost_orders'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/lossanalysis?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'sidebar_navigation'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/marketanalysis/flowanalysis?microJump=true', 'observed_from_response'),
    ],
  },
  competitor_rank: {
    label: '竞争圈动态-竞争圈榜单',
    dataType: 'traffic',
    aliases: ['rank', 'ranking', 'competitor_rank'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionlist?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'sidebar_navigation'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/marketanalysis/rank?microJump=true', 'inferred'),
    ],
  },
  user_profile: {
    label: '用户行为-用户分析',
    dataType: 'business',
    aliases: ['user', 'user_behavior', 'user_profile', 'profile', 'comment_analysis', 'review_analysis'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'sidebar_navigation'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/userbehavior/index?microJump=true', 'inferred'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true', 'observed_from_user'),
    ],
  },
  im_board: {
    label: '用户行为-IM看板',
    dataType: 'quality',
    aliases: ['im', 'im_board', 'customer_service'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'sidebar_navigation'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?goto=im', 'observed_from_cache'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/userbehavior/im?microJump=true', 'inferred'),
    ],
  },
  ads_pyramid: {
    label: '金字塔推广',
    dataType: 'advertising',
    aliases: ['ads', 'ad', 'advertising', 'campaign', 'pyramid', 'cpc'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/toolcenter/cpc/pyramid', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/toolcenter/cpc/dataReport', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/toolcenter/cpc/dataReport?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/toolcenter/cpc/comparison?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/advertise/cpc/dataReport?micro=true&microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/advertise/cpc/comparison?micro=true&microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/advertise/cpc/diagnosisReport?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/pyramidad/dataReport?micro=true', 'observed'),
      sectionUrl('https://ebooking.ctrip.com/pyramidad/diagnosisReport?micro=true', 'observed'),
    ],
  },
  ladder_simulate_rank: {
    label: '云梯排名预测',
    dataType: 'advertising_forecast',
    aliases: ['ladder', 'ladder_rank', 'simulate_rank', 'rank_forecast', 'yunti'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/toolcenter/ladder/home', 'observed_from_live_probe'),
    ],
  },
  quality_psi: {
    label: 'PSI服务质量分',
    dataType: 'quality',
    aliases: ['psi', 'quality', 'service_quality'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/toolcenter/psi/index?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/toolcenter/psi/index?fromType=menu&microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/psi/index?micro=true&fromType=menu&microJump=true', 'observed'),
    ],
  },
  market_calendar: {
    label: '市场分析-市场热度',
    dataType: 'business',
    aliases: ['calendar', 'hot_calendar', 'events', 'market', 'market_analysis', 'market_heat', 'marketanalysis'],
    pageUrls: [
      sectionUrl('https://ebooking.ctrip.com/ebkgrowth/datacenter/marketanalysis/marketheat?microJump=true', 'observed_from_user'),
      sectionUrl('https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true', 'observed_from_endpoint'),
    ],
  },
  biztravel_bpi: {
    label: '携程商旅-BPI分',
    dataType: 'quality',
    aliases: ['bpi', 'biztravel_bpi', 'bbk_bpi'],
    pageUrls: [
      sectionUrl('https://bbk.ctripbiz.cn/bpi', 'observed_from_screenshot'),
      sectionUrl('https://bbk.ctripbiz.com/bpi', 'fallback'),
    ],
  },
  biztravel_business_report: {
    label: '携程商旅-经营报告',
    dataType: 'business',
    aliases: ['biztravel_report', 'bbk_report', 'business_travel_report'],
    pageUrls: [
      sectionUrl('https://bbk.ctripbiz.cn/datacenter/businessReport', 'observed_from_screenshot'),
      sectionUrl('https://bbk.ctripbiz.com/datacenter/businessReport', 'fallback'),
    ],
  },
  biztravel_competitor: {
    label: '携程商旅-竞争圈概览',
    dataType: 'business',
    aliases: ['biztravel_competitor', 'bbk_competitor', 'business_travel_competitor'],
    pageUrls: [
      sectionUrl('https://bbk.ctripbiz.cn/datacenter/comparatorReport', 'observed_from_screenshot'),
      sectionUrl('https://bbk.ctripbiz.com/datacenter/comparatorReport', 'fallback'),
    ],
  },
};

const commonFields = [
  field('hotel_id', '酒店ID', ['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'hotelID']),
  field('hotel_name', '酒店名称', ['hotelName', 'hotel_name', 'hotelname', 'name']),
  field('date', '日期', ['date', 'dataDate', 'effectDate', 'effectTime', 'statDate', 'startDate', 'endDate', 'updateTime']),
];

const revenueFields = [
  field('order_count', '预订订单数', ['orderQuantity', 'synchronizationOrderQuantity', 'bookOrderNum', 'orderCount', 'orderNum', 'ordquantity', 'bookingCount'], 'OTA预订订单量'),
  field('room_nights', '间夜量', ['quantity', 'bookQuantity', 'synchronizationBookQuantity', 'roomNights', 'nightNum', 'occupiedRooms', 'checkOutQuantity'], 'OTA间夜或在店间夜'),
  field('order_amount', '预订销售额', ['amount', 'bookAmount', 'synchronizationBookAmount', 'orderAmount', 'saleAmount', 'ordamount', 'totalAmount', 'bookingAmount'], 'OTA预订或离店销售额', { unit: 'CNY' }),
  field('avg_price', '平均卖价', ['averagePrice', 'synchronizationAveragePrice', 'avgPrice', 'adr', 'minPrice'], 'OTA均价或起价', { unit: 'CNY' }),
  field('conversion_rate', '成交/下单转化率', ['orderConversionRate', 'closeRate', 'conversionRate', 'convertionRate', 'cvr'], '从流量到订单的转化'),
  field('occupancy_rate', '出租率', ['rentalRate', 'occupancyRate']),
  field('tensity', '紧张度', ['tensityScore', 'tensity', 'Tensity', 'nowTensityDetail']),
  field('rank', '竞争圈排名', ['rank', 'rank2', 'visitorRank', 'rankOfAmount', 'rankOfOrderQuantity', 'competitorRank', 'ranking']),
  field('competitor_average', '竞争圈平均值', ['competitorsAverageOrderQuantity', 'competitorsAverageOccupiedRooms', 'competitorAvgNumber', 'competitorTensityScore']),
];

const weeklyLossOrderFields = [
  field('loss_order_count', '流失订单量', ['ordernum']),
  field('loss_room_nights', '流失间夜量', ['ordquantity']),
  field('loss_order_amount', '流失订单金额', ['ordamount'], '', { unit: 'CNY' }),
];

const lossOrderSummaryFields = [
  field('loss_order_count', '流失订单量', ['lossOrderCount', 'lossOrderNum', 'orderCount', 'orderNum', 'ordernum']),
  field('loss_room_nights', '流失间夜量', ['lossRoomNight', 'lossNightCount', 'roomNights', 'nightNum', 'ordquantity']),
  field('loss_order_amount', '流失订单金额', ['lossOrderAmount', 'amount', 'ordamount'], '', { unit: 'CNY' }),
  field('common_view_rate', '共同浏览率', ['commonViewRate', 'browseRate', 'proportion'], '', { unit: '%' }),
  field('order_conversion_rate', '下单转化率', ['orderConversionRate', 'conversionRate', 'orderPro'], '', { unit: '%' }),
  field('competitor_hotel_name', '流失酒店名称', ['hotelName', 'competeHotelName']),
];

const lossCompeteHotelFields = [
  field('competitor_hotel_name', '流失酒店名称', ['hotelName', 'competeHotelName']),
  field('common_view_rate', '共同浏览率', ['commonViewRate', 'browseRate', 'proportion'], '', { unit: '%' }),
  field('order_conversion_rate', '下单转化率', ['orderConversionRate', 'conversionRate', 'orderPro'], '', { unit: '%' }),
  field('loss_order_count', '流失订单数', ['lossOrderCount', 'lossOrderNum', 'orderCount', 'orderNum', 'ordernum']),
  field('follow_status', '关注状态', ['followStatus', 'isFollow']),
];

const marketOverviewFields = [
  field('order_amount', '离店销售额', ['amount'], '经营报告概要日报卡片主值', { unit: 'CNY' }),
  field('order_amount_last_week', '离店销售额上周同期', ['synchronizationAmount'], '上周同期对比值，不覆盖本期销售额', { unit: 'CNY' }),
  field('amount_rank', '离店销售额竞争圈排名', ['rankOfAmount']),
  field('room_nights', '离店间夜量', ['quantity'], '经营报告概要日报卡片主值'),
  field('room_nights_last_week', '离店间夜量上周同期', ['synchronizationQuantity'], '上周同期对比值，不覆盖本期间夜'),
  field('quantity_rank', '离店间夜量竞争圈排名', ['rankOfQuantity']),
  field('close_rate', '成交率', ['closeRate'], '经营报告概要日报卡片主值', { unit: '%' }),
  field('close_rate_last_week', '成交率上周同期', ['synchronizationCloseRate'], '上周同期对比值，不覆盖本期成交率', { unit: '%' }),
  field('close_rate_rank', '成交率竞争圈排名', ['rankOfCloseRate']),
  field('avg_price', '平均卖价', ['averagePrice'], '经营报告概要日报卡片主值', { unit: 'CNY' }),
  field('avg_price_last_week', '平均卖价上周同期', ['synchronizationAveragePrice'], '上周同期对比值，不覆盖本期平均卖价', { unit: 'CNY' }),
  field('avg_price_rank', '平均卖价竞争圈排名', ['rankOfAveragePrice']),
];

const visitorTitleFields = [
  field('visitor_count', '实时访客量', ['visitorTotal']),
  field('visitor_rank', '实时访客量竞争圈排名', ['visitorRank']),
  field('visitor_count_last_week', '实时访客量上周同期', ['lastVisitorTotal']),
  field('competitor_avg_visitor', '携程竞对平均访客数', ['competitorAvgNumber']),
  field('qunar_visitor_count', '去哪儿实时访客量', ['qunarVisitorTotal']),
  field('qunar_visitor_rank', '去哪儿实时访客量竞争圈排名', ['qunarCompetitorRank']),
  field('qunar_visitor_count_last_week', '去哪儿实时访客量上周同期', ['lastQunarVisitorTotal']),
  field('qunar_competitor_avg_visitor', '去哪儿竞对平均访客数', ['qunarCompetitorAvgNumber']),
];

const capacityOverviewFields = [
  field('occupied_rooms', '已售间夜 / 已入住房间数', ['occupiedRooms']),
  field('occupied_rooms_sync', '同步后已售间夜', ['synchronizationOccupiedRooms']),
  field('occupied_rooms_rank', '已售间夜竞争圈排名', ['rankOfOccupiedRooms']),
  field('competitor_avg_occupied_rooms', '竞对平均已售间夜', ['competitorsAverageOccupiedRooms']),
  field('occupancy_rate', '平台入住率', ['occupancyRate'], '', { unit: '%' }),
  field('occupancy_rate_sync', '同步后入住率', ['synchronizationOccupancyRate'], '', { unit: '%' }),
  field('occupancy_rate_rank', '入住率竞争圈排名', ['rankOfOccupancyRate']),
  field('order_count', '总订单量', ['orderQuantity']),
  field('order_count_sync', '同步后总订单量', ['synchronizationOrderQuantity']),
  field('order_count_rank', '订单量竞争圈排名', ['rankOfOrderQuantity']),
  field('competitor_avg_orders', '竞对平均订单量', ['competitorsAverageOrderQuantity']),
  field('ctrip_order_count', '携程订单量', ['ctripOrderQuantity']),
  field('ctrip_order_count_sync', '携程同步后订单量', ['ctripSynchronizationOrderQuantity']),
  field('ctrip_order_count_rank', '携程订单量竞争圈排名', ['ctripRankOfOrderQuantity']),
  field('qunar_order_count', '去哪儿订单量', ['qunarOrderQuantity']),
  field('qunar_order_count_sync', '去哪儿同步后订单量', ['qunarSynchronizationOrderQuantity']),
  field('qunar_order_count_rank', '去哪儿订单量竞争圈排名', ['qunarRankOfOrderQuantity']),
  field('elong_order_count', '艺龙订单量', ['elongOrderQuantity']),
  field('elong_order_count_sync', '艺龙同步后订单量', ['elongSynchronizationOrderQuantity']),
  field('elong_order_count_rank', '艺龙订单量竞争圈排名', ['elongRankOfOrderQuantity']),
];

const trafficFields = [
  field('page_views', '列表页曝光量旧映射', ['listExposure', 'pvDataList', 'pageViewDataList', 'PV', 'pv', 'pageViews'], 'legacy field_key：优先取 queryFlowTransforNewV1 本店行 listExposure；主字段使用 list_exposure'),
  field('visitor_count', '访客量', ['lastVisitorTotal', 'visitorTotal', 'UV', 'uv', 'visitorCount', 'pageViews']),
  field('list_exposure', '列表页曝光量', ['listExposure', 'exposure', 'exposureCount', 'impressions'], '取 queryFlowTransforNewV1 本店行 listExposure'),
  field('competitor_list_exposure', '竞争圈平均列表页曝光量', ['listExposure', 'competitorListExposure', 'competitorExposure', 'avgListExposure'], '取 queryFlowTransforNewV1 中 hotelId=-1 的 listExposure'),
  field('detail_visitor', '详情页访客量', ['detailExposure', 'detailUv', 'detailVisitors'], '取 queryFlowTransforNewV1 本店行 detailExposure'),
  field('competitor_detail_visitor', '竞争圈平均详情页访客量', ['detailExposure', 'competitorDetailUv', 'competitorDetailVisitors', 'avgDetailUv'], '取 queryFlowTransforNewV1 中 hotelId=-1 的 detailExposure'),
  field('order_page_visitor', '订单页访客量', ['orderFillingNum', 'orderVisitors', 'fillUsers'], '取 queryFlowTransforNewV1 本店行 orderFillingNum'),
  field('competitor_order_page_visitor', '竞争圈平均订单页访客量', ['orderFillingNum', 'competitorOrderFillingNum', 'avgOrderFillingNum', 'competitorOrderVisitors'], '取 queryFlowTransforNewV1 中 hotelId=-1 的 orderFillingNum'),
  field('order_fill_rate', '下单转化率', ['orderFillingNum/detailExposure'], '本店行 orderFillingNum / detailExposure * 100', { unit: '%' }),
  field('competitor_order_fill_rate', '竞争圈平均下单转化率', ['hotelId=-1.orderFillingNum/detailExposure'], '取 queryFlowTransforNewV1 中 hotelId=-1；orderFillingNum / detailExposure * 100', { unit: '%' }),
  field('order_submit_user', '订单提交人数', ['orderSubmitNum', 'submitUsers', 'submitNum'], '取 queryFlowTransforNewV1 本店行 orderSubmitNum'),
  field('competitor_order_submit_user', '竞争圈平均订单提交人数', ['orderSubmitNum', 'competitorOrderSubmitNum', 'avgOrderSubmitNum', 'competitorSubmitUsers'], '取 queryFlowTransforNewV1 中 hotelId=-1 的 orderSubmitNum'),
  field('deal_rate', '成交转化率', ['orderSubmitNum/orderFillingNum'], '本店行 orderSubmitNum / orderFillingNum * 100', { unit: '%' }),
  field('competitor_deal_rate', '竞争圈平均成交转化率', ['hotelId=-1.orderSubmitNum/orderFillingNum'], '取 queryFlowTransforNewV1 中 hotelId=-1；orderSubmitNum / orderFillingNum * 100', { unit: '%' }),
  field('qunar_list_exposure', '去哪儿列表页曝光量', ['platform=Qunar.listExposure'], 'platform=Qunar 本店行 listExposure'),
  field('qunar_competitor_list_exposure', '去哪儿竞争圈平均列表页曝光量', ['platform=Qunar.hotelId=-1.listExposure'], 'platform=Qunar 且 hotelId=-1 的 listExposure'),
  field('qunar_detail_visitor', '去哪儿详情页访客量', ['platform=Qunar.detailExposure'], 'platform=Qunar 本店行 detailExposure'),
  field('qunar_competitor_detail_visitor', '去哪儿竞争圈平均详情页访客量', ['platform=Qunar.hotelId=-1.detailExposure'], 'platform=Qunar 且 hotelId=-1 的 detailExposure'),
  field('qunar_flow_rate', '去哪儿曝光转化率', ['platform=Qunar.flowRate'], 'platform=Qunar 本店行 flowRate；可用 detailExposure / listExposure * 100 复核', { unit: '%' }),
  field('qunar_competitor_flow_rate', '去哪儿竞争圈平均曝光转化率', ['platform=Qunar.hotelId=-1.flowRate'], 'platform=Qunar 且 hotelId=-1 的 flowRate；可用 detailExposure / listExposure * 100 复核', { unit: '%' }),
  field('qunar_order_page_visitor', '去哪儿订单页访客量', ['platform=Qunar.orderFillingNum'], 'platform=Qunar 本店行 orderFillingNum'),
  field('qunar_competitor_order_page_visitor', '去哪儿竞争圈平均订单页访客量', ['platform=Qunar.hotelId=-1.orderFillingNum'], 'platform=Qunar 且 hotelId=-1 的 orderFillingNum'),
  field('qunar_order_fill_rate', '去哪儿下单转化率', ['platform=Qunar.orderFillingNum/detailExposure'], 'platform=Qunar 本店行 orderFillingNum / detailExposure * 100', { unit: '%' }),
  field('qunar_competitor_order_fill_rate', '去哪儿竞争圈平均下单转化率', ['platform=Qunar.hotelId=-1.orderFillingNum/detailExposure'], 'platform=Qunar 且 hotelId=-1；orderFillingNum / detailExposure * 100', { unit: '%' }),
  field('qunar_order_submit_user', '去哪儿订单提交人数', ['platform=Qunar.orderSubmitNum'], 'platform=Qunar 本店行 orderSubmitNum'),
  field('qunar_competitor_order_submit_user', '去哪儿竞争圈平均订单提交人数', ['platform=Qunar.hotelId=-1.orderSubmitNum'], 'platform=Qunar 且 hotelId=-1 的 orderSubmitNum'),
  field('qunar_deal_rate', '去哪儿成交转化率', ['platform=Qunar.orderSubmitNum/orderFillingNum'], 'platform=Qunar 本店行 orderSubmitNum / orderFillingNum * 100', { unit: '%' }),
  field('qunar_competitor_deal_rate', '去哪儿竞争圈平均成交转化率', ['platform=Qunar.hotelId=-1.orderSubmitNum/orderFillingNum'], 'platform=Qunar 且 hotelId=-1；orderSubmitNum / orderFillingNum * 100', { unit: '%' }),
  field('weekly_order_page_visitor', '周报订单页访客', ['weeklyOrderFillingNum', 'weekOrderFillingNum', 'orderFillingNum']),
  field('weekly_competitor_avg_order_page_visitor', '周报订单页访客竞圈均值', ['avgOrderFillingNum', 'competitorAvgOrderVisitors']),
  field('weekly_top_competitor_order_page_visitor', '周报订单页访客最高竞品', ['topOrderFillingNum', 'topCompetitorOrderVisitors']),
  field('weekly_submit_user', '周报提交人数', ['weeklyOrderSubmitNum', 'weekOrderSubmitNum', 'orderSubmitNum']),
  field('weekly_competitor_avg_submit_user', '周报提交人数竞圈均值', ['avgOrderSubmitNum', 'competitorAvgSubmitUsers']),
  field('weekly_top_competitor_submit_user', '周报提交人数最高竞品', ['topOrderSubmitNum', 'topCompetitorSubmitUsers']),
  field('flow_rate', '曝光转化率', ['flowRate', 'conversionsRatesDataList', 'transforRate', 'transferRate', 'convertRate'], '取 queryFlowTransforNewV1 本店行 flowRate；可用 detailExposure / listExposure * 100 复核', { unit: '%' }),
  field('competitor_flow_rate', '竞争圈平均曝光转化率', ['hotelId=-1.flowRate', 'competitorFlowRate', 'avgFlowRate', 'competitorConversionRate'], '取 queryFlowTransforNewV1 中 hotelId=-1 的 flowRate；可用 detailExposure / listExposure * 100 复核', { unit: '%' }),
  field('visitor_rank', '访客排名', ['visitorRank']),
  field('competitor_avg_visitor', '竞争圈平均访客', ['competitorAvgNumber']),
  field('qunar_visitor_rank', '去哪儿访客排名', ['qunarCompetitorRank', 'qunarVisitorRank']),
  field('qunar_competitor_avg_visitor', '去哪儿竞争圈平均访客', ['qunarCompetitorAvgNumber']),
  field('source_name', '流量来源', ['sourceName', 'sourceNameTag']),
  field('keyword', '搜索关键词', ['keyword', 'searchKeyword', 'filterWords']),
];

const trafficFlowSourceFields = [
  field('source_name', '流量来源', ['sourceName']),
  field('source_rank_tag', '流量来源排名标签', ['sourceNameTag'], 'queryFlowSource 返回的页面图标标签，只保留为来源行辅助事实。'),
  field('source_proportion', '我的酒店流量来源占比', ['proportion'], 'queryFlowSource flowSourceDetails[].proportion；来源结构占比，只保留 raw fact，不写入流量转化率。', { unit: '%' }),
  field('competitor_avg_source_proportion', '竞争圈平均流量来源占比', ['competitorAvgProportion'], 'queryFlowSource flowSourceDetails[].competitorAvgProportion；竞争圈平均来源结构占比，只保留 raw fact。', { unit: '%' }),
  field('source_pv', '流量来源PV', ['pv'], 'queryFlowSource flowSourceDetails[].pv；样例中与 allpv 推导竞圈占比，不等同于本店 page_views。', { unit: '次' }),
  field('source_all_pv', '流量来源PV分母', ['allpv'], 'queryFlowSource flowSourceDetails[].allpv；用于解释来源占比口径，不写入曝光主列。', { unit: '次' }),
  field('keyword', '搜索关键词', ['keyword', 'searchKeyword', 'keywords', 'filterWords']),
];

const trafficCityHotSearchFields = [
  field('city_hot_search_keyword', '城市热搜关键词', ['keyword'], 'queryCityHotKeywords / queryQunarCityHotSearch 返回的关键词项；只作为关键词维度事实。'),
  field('city_hot_search_uv', '城市热搜关键词UV', ['uv', 'UV'], '关键词级 UV，不覆盖酒店整体访客量。'),
  field('city_hot_search_pv', '城市热搜关键词PV', ['pv', 'PV'], '关键词级 PV，不覆盖酒店列表页曝光量。'),
];

const qualityFields = [
  field('psi_score', 'PSI服务质量分', ['psi', 'PSI', 'psiScore', 'qualityscore', 'totalScore']),
  field('service_score', '服务质量分', ['serviceScore']),
  field('service_score_rank', '服务质量排名', ['serviceScoreRank']),
  field('base_score', '基础分', ['baseScore', 'basicScore']),
  field('reward_score', '奖励分', ['rewardScore', 'bonusScore']),
  field('deduct_score', '减分项', ['deductScore', 'penaltyScore']),
  field('reply_rate', '5分钟回复率', ['replyrate5m', 'replyRate', 'fiveMinuteReplyRate']),
  field('reply_rank', '回复排名', ['imScoreHtlrank', 'replyrate5mRank']),
  field('im_score', 'IM评分', ['imScore']),
  field('hotel_collect', '酒店收藏数', ['hotelCollect', 'favoriteCount', 'collectCount']),
  field('hotel_collect_rank', '酒店收藏排名', ['hotelCollectRank']),
  field('comment_count', '点评数量', ['commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount'], '只采集点评/评论数量，不保存点评明文'),
  field('ctrip_comment_count', '携程点评数量', ['ctripCommentCount', 'ctripCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('qunar_comment_count', '去哪儿点评数量', ['qunarCommentCount', 'qunarCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('elong_comment_count', '艺龙点评数量', ['elongCommentCount', 'elongCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('zx_comment_count', '智行点评数量', ['zxCommentCount', 'zhixingCommentCount', 'zxCount.commentCount', 'zhixingCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('comment_score_summary', '点评分汇总', ['ctripRatingall', 'qunarRatingall', 'HotelRating', 'ratingall']),
  field('ctrip_rating', '携程评分', ['ctripRatingall']),
  field('qunar_rating', '去哪儿评分', ['qunarRatingall']),
  field('elong_rating', '艺龙评分', ['elongRatingall']),
  field('ctrip_rating_rank', '携程评分排名', ['ctripRatingAllRanking']),
  field('qunar_rating_rank', '去哪儿评分排名', ['qunarRatingAllRanking']),
  field('comment_response_rate', '点评回复率', ['responseRate'], '点评/评论回复率，不等同于 IM 5分钟回复率', { unit: '%' }),
  field('comment_unreply_count', '未回复点评数', ['unReplyCount', 'unreplyCount', 'unRepliedCount', 'unrepliedCount'], '只保存未回复点评聚合计数，不保存点评明文'),
  field('comment_good_rate', '点评好评率', ['goodRate'], '点评/评论好评率聚合值，不保存点评明文', { unit: '%' }),
  field('review_environment_score', '点评环境评分', ['ratingLocation', 'environmentScore', 'envScore', 'surroundingScore', 'surroundingsScore', 'ambienceScore'], '点评环境子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingLocation，不采集点评明文', { unit: '分' }),
  field('review_facility_score', '点评设施评分', ['ratingFacility', 'facilityScore', 'facilitiesScore', 'equipmentScore'], '点评设施子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingFacility，不采集点评明文', { unit: '分' }),
  field('review_service_score', '点评服务评分', ['ratingService', 'reviewServiceScore', 'commentServiceScore', 'serviceRating', 'serviceCommentScore'], '点评服务子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingService，不与 PSI service_score 混用', { unit: '分' }),
  field('review_cleanliness_score', '点评卫生评分', ['ratingRoom', 'cleanlinessScore', 'cleanScore', 'hygieneScore', 'sanitationScore'], '点评卫生子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingRoom，不采集点评明文', { unit: '分' }),
  field('review_photo_count', '带图点评数', ['hasPicCount', 'photoCommentCount', 'pictureCommentCount', 'imageCommentCount'], '带图点评数量；只保存聚合计数，不保存图片或点评明文'),
  field('review_photo_rate', '带图点评率', ['hasPicCount/commentCount'], '按 hasPicCount / commentCount * 100 派生；缺少分子或分母时保持缺失', { unit: '%' }),
  field('rating_competitor_total', '点评竞争圈酒店数', ['competitorHotelTotal']),
  field('bad_review_tag', '差评标签', ['dingPingEntityList', 'tag']),
];

const psiBasicScoreDetailFields = [
  field('psi_basic_item_id', 'PSI基础分明细ID', ['id']),
  field('psi_basic_item_type', 'PSI基础分明细指标类型', ['__psiBasicItemType']),
  field('psi_basic_item_code', 'PSI基础分明细编码', ['code']),
  field('psi_basic_item_name', 'PSI基础分明细指标', ['name']),
  field('psi_basic_item_weight', 'PSI基础分明细项目权重', ['weight']),
  field('psi_basic_item_score', 'PSI基础分明细得分', ['score']),
  field('psi_basic_item_rank', 'PSI基础分明细命中规则', ['rank']),
  field('psi_basic_item_score_gap', 'PSI基础分明细差距值', ['scoreGap']),
  field('psi_basic_item_score_gap_unit', 'PSI基础分明细差距单位', ['scoreGapUnit']),
  field('psi_basic_item_start_date', 'PSI基础分明细开始日期', ['startDate']),
  field('psi_basic_item_end_date', 'PSI基础分明细结束日期', ['endDate']),
  field('psi_basic_item_tips', 'PSI基础分明细计算说明', ['tips']),
  field('psi_basic_item_activity_name', 'PSI基础分明细建议动作', ['activityName']),
  field('psi_basic_item_activity_url', 'PSI基础分明细建议入口', ['activityUrl']),
];

const dailyServiceQualityFields = [
  field('psi_score', 'PSI服务质量分', ['serviceScore'], '经营日报固定取 data.serviceScore'),
  field('service_score_rank', 'PSI服务质量分竞争圈排名', ['serviceScoreRank']),
  field('comment_score_summary', '酒店点评分', ['ctripRatingall']),
  field('ctrip_rating', '酒店点评分', ['ctripRatingall']),
  field('reply_rate', '5分钟回复率', ['replyrate5m']),
  field('reply_rank', '5分钟回复率竞争圈排名', ['imScoreHtlrank']),
  field('hotel_collect', '酒店收藏数', ['hotelCollect']),
  field('hotel_collect_rank', '酒店收藏数竞争圈排名', ['hotelCollectRank']),
  field('im_score', 'IM评分', ['imScore']),
  field('deduct_score', '减分项', ['penaltyScore']),
  field('bad_review_tag', '差评标签', ['dingPingEntityList', 'tag']),
];

const competitorFields = [
  field('competitor_visitor', '竞品访客', ['comhtluv']),
  field('competitor_orders', '竞品订单', ['ordquantity']),
  field('competitor_revenue', '竞品收入', ['ordamount'], '', { unit: 'CNY' }),
  field('competitor_number', '竞争圈酒店数', ['competitorNumber']),
];

const adsFields = [
  field('ad_impressions', '广告曝光', ['impression', 'impressions', 'exposure', 'showCount']),
  field('ad_clicks', '广告点击', ['click', 'clicks', 'clickCount']),
  field('ad_cost', '广告花费', ['todayCost', 'cashCost', 'bonusCost', 'cost', 'charge', 'yesterdayCharge', 'spend', 'amount'], '', { unit: 'CNY' }),
  field('ad_order_amount', '广告预订金额', ['orderAmount', 'saleAmount', 'revenue'], '', { unit: 'CNY' }),
  field('ad_orders', '广告预订订单', ['orderCount', 'bookingCount', 'bookings']),
  field('ad_room_nights', '广告预订间夜', ['roomNights', 'nights', 'quantity']),
  field('ctr', '点击率', ['ctr', 'clickRate']),
  field('cvr', '转化率', ['cvr', 'conversionRate']),
  field('roas', '广告投产比ROAS', ['roas', 'roi']),
  field('campaign_id', '推广计划ID', ['campaignId', 'campaign_id']),
  field('diagnosis_text', '诊断建议', ['diagnosis', 'suggestion', 'interpretation', 'tasktext']),
];

const ladderSimulateRankFields = [
  field('ladder_participating', '云梯参与状态', ['participating'], '是否正在参与云梯推广', { valueType: 'boolean', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_range_participating', '云梯范围参与状态', ['rangeParticipating'], '平台返回的范围参与状态，具体业务口径仍需页面文案复核', { valueType: 'boolean', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_effect_date', '云梯预测生效日期', ['effectDate'], '云梯预测列表的生效日期', { valueType: 'date', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_current_rank', '云梯当前排名', ['currentRank'], '接口给出的当前排名字段；是否已含当前云梯效果需结合页面文案复核', { unit: '名', valueType: 'integer', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_predicted_rank', '云梯预测排名', ['predicateRank'], '接口字段名为 predicateRank，按页面语义记为预测排名', { unit: '名', valueType: 'integer', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_origin_rank', '云梯原始排名', ['originRank'], '云梯推广前的原始或基准排名', { unit: '名', valueType: 'integer', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_strategy_ratio', '云梯策略比例', ['effectValue'], '样例页面显示为策略比例 15%', { unit: '%', valueType: 'number', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_estimated_traffic_lift', '云梯预估流量提升', ['increaseRatioValue'], '样例与页面流量提升预测折线相关，精确标签待页面文案复核', { unit: '%', valueType: 'number', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
  field('ladder_participate_promote_lift', '云梯参与推广提升', ['participatePromoteValue'], '样例与页面推广提升折线相关，精确标签待页面文案复核', { unit: '%', valueType: 'number', missingStatus: 'field_missing', confidenceStatus: 'live_probe_observed' }),
];

const userProfileFields = [
  field('user_sex', '用户性别', ['sex', 'gender', 'userSex']),
  field('user_age', '年龄段', ['age', 'ageRange', 'userAge']),
  field('avg_user_age', '平均年龄', ['avgUserAge'], 'queryUserAge.data.avg，单位岁', { unit: '岁' }),
  field('user_source', '客源来源', ['source', 'userSource', 'cityName']),
  field('user_source_scope', '客源范围', ['userSourceScope']),
  field('source_region', '客源省份/地区', ['sourceRegion']),
  field('source_city', '客源城市', ['sourceCity']),
  field('user_type', '用户类型', ['userType', 'travelType', 'type']),
  field('travel_time', '出行时间', ['traveltime', 'travelTime']),
  field('booking_hour', '24小时预订时段', ['bookingHour', 'orderHour', 'hour', 'time']),
  field('avg_booking_days', '平均提前预订天数', ['avgBookingDays'], 'queryUserBookingDays.data.avg，单位天', { unit: '天' }),
  field('booking_days', '提前预订天数区间', ['bookingDays', 'bookingdays', 'advanceDays', 'leadTime']),
  field('avg_stay_days', '平均入住天数', ['avgStayDays'], 'queryUserStayDays.data.avg，单位天', { unit: '天' }),
  field('stay_days', '入住天数区间', ['stayDays', 'staydays', 'stayLength']),
  field('hotel_star_preference', '酒店星级偏好', ['star', 'starLevel', 'hotelStar', 'userStar']),
  field('price_band', '消费档位', ['price', 'priceInfo', 'priceBand', 'consumer']),
  field('consumption_power', '消费能力', ['userPrice', 'consumptionPower', 'consumerPower', 'priceRange']),
  field('price_sensitivity', '价格敏感度', ['priceSensitivity']),
  field('booking_method', '预订方式', ['orderType', 'bookingMethod', 'bookingChannel', 'orderMethod']),
  field('order_hotel_count', '订购酒店次数', ['userOrders', 'hotelOrderCount', 'orderHotelCount', 'orders']),
  field('order_preference', '订购偏好', ['orderPreference']),
  field('preference_frequency', '偏好频次', ['preferenceFrequency']),
  field('distribution_share', '分布占比', ['distributionShare', 'percent'], '用户行为分布图表占比，来自对应接口 value / valueList / percent / rate 字段', { unit: '%' }),
  field('strategy', '提升策略', ['strategy', 'suggestion', 'imageList']),
];

const bizTravelFields = [
  field('bpi_score', 'BPI总分', ['bpiScore', 'score', 'totalScore']),
  field('basis_score', '基础分', ['baseScore', 'basicScore']),
  field('plus_score', '加分', ['plusScore', 'bonusScore', 'rewardScore']),
  field('minus_score', '减分', ['minusScore', 'deductScore']),
  field('agreement_accept_rate', '协议酒店接单率', ['acceptRate', 'orderReceivingRate']),
  field('business_room_nights', '商旅间夜', ['roomNights', 'occupiedRooms', 'nightNum']),
  field('business_amount', '商旅营业额', ['amount', 'orderAmount', 'businessAmount', 'saleAmount'], '', { unit: 'CNY' }),
  field('business_commission_rate', '商旅佣金率', ['commissionRate', 'commission_rate']),
];

const labelFields = [
  field('hotel_label', '酒店标签', ['label', 'labelName', 'tagName', 'hotelLabel', 'labelValue']),
];

const benefitFields = [
  field('benefit_name', '权益名称', ['benefitName', 'name', 'title']),
  field('benefit_status', '权益状态', ['benefitStatus', 'status']),
  field('benefit_text', '权益说明', ['content', 'description', 'desc']),
  field('target_url', '页面跳转地址', ['targetUrl', 'url']),
];

const supportNoticeFields = [
  field('notice_count', '通知数量', ['messageList', 'notices', 'notifications', 'totalCount']),
  field('notice_title', '提示标题', ['title', 'name', 'noticeTitle']),
  field('notice_text', '提示内容', ['content', 'message', 'text', 'tips', 'tip', 'description', 'desc']),
  field('config_name', '配置名称', ['configName', 'configKey', 'key', 'code']),
  field('config_value', '配置值', ['configValue', 'value']),
  field('target_url', '页面跳转地址', ['targetUrl', 'url']),
];

const commentAggregateFields = [
  field('comment_store_name', '点评门店', ['hotelName', 'masterHotelName', 'storeName', 'hotel_name'], '点评数据所属门店；不从点评正文推断'),
  field('comment_date', '点评日期', ['date', 'dataDate', 'statDate', 'commentTime', 'createTime', 'submitTime'], '点评统计或评论发生日期；不保存点评明文'),
  field('comment_channel', '点评渠道', ['channel', 'channelName', 'platform', 'source', 'commentChannel', 'bizType'], '只保留点评渠道维度，不保存用户身份或点评内容'),
  field('comment_score', '点评分', ['score', 'commentScore', 'rating', 'ratingall', 'HotelRating', 'ctripRatingall', 'totalScore', 'overallScore'], '只采集评分聚合值，不保存点评明文', { unit: '分' }),
  field('comment_count', '点评数量', ['commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount'], '只采集点评/评论数量，不保存点评明文'),
  field('ctrip_comment_count', '携程点评数量', ['ctripCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('qunar_comment_count', '去哪儿点评数量', ['qunarCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('elong_comment_count', '艺龙点评数量', ['elongCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('zx_comment_count', '智行点评数量', ['zxCount.commentCount', 'zhixingCount.commentCount'], '只采集点评数量，不保存点评明文'),
  field('bad_review_count', '差评数', ['badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount'], '优先聚合接口差评/不推荐计数；列表评分仅通过显式聚合计算，不保存点评明文'),
  field('comment_unreply_count', '未回复点评数', ['unReplyCount', 'unreplyCount', 'unRepliedCount', 'unrepliedCount'], '只保存未回复点评聚合计数，不保存点评明文'),
  field('comment_good_rate', '点评好评率', ['goodRate'], '点评/评论好评率聚合值，不保存点评明文', { unit: '%' }),
  field('comment_response_rate', '点评回复率', ['responseRate'], '点评/评论回复率，不等同于 IM 5分钟回复率', { unit: '%' }),
  field('target_url', '点评跳转地址', ['jumpUrl'], '只保留平台点评页跳转地址，不保存点评明文'),
  field('review_environment_score', '点评环境评分', ['ratingLocation', 'environmentScore', 'envScore', 'surroundingScore', 'surroundingsScore', 'ambienceScore'], '点评环境子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingLocation，不采集点评明文', { unit: '分' }),
  field('review_facility_score', '点评设施评分', ['ratingFacility', 'facilityScore', 'facilitiesScore', 'equipmentScore'], '点评设施子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingFacility，不采集点评明文', { unit: '分' }),
  field('review_service_score', '点评服务评分', ['ratingService', 'reviewServiceScore', 'commentServiceScore', 'serviceRating', 'serviceCommentScore'], '点评服务子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingService，不与 PSI service_score 混用', { unit: '分' }),
  field('review_cleanliness_score', '点评卫生评分', ['ratingRoom', 'cleanlinessScore', 'cleanScore', 'hygieneScore', 'sanitationScore'], '点评卫生子评分；getHotelRating 取 ratingInfo/ctripRatings/elongRatings.ratingRoom，不采集点评明文', { unit: '分' }),
  field('review_photo_count', '带图点评数', ['hasPicCount', 'photoCommentCount', 'pictureCommentCount', 'imageCommentCount'], '带图点评数量；只保存聚合计数，不保存图片或点评明文'),
  field('review_photo_rate', '带图点评率', ['hasPicCount/commentCount'], '按 hasPicCount / commentCount * 100 派生；缺少分子或分母时保持缺失', { unit: '%' }),
];

const FACT_ONLY_FIELD_IDS = new Set([
  'advice_text',
  'announcement',
  'campaign_id',
  'competitor_hotel_name',
  'course_title',
  'course_url',
  'diagnosis_text',
  'end_date',
  'follow_status',
  'benefit_name',
  'benefit_status',
  'benefit_text',
  'comment_channel',
  'hot_spot_name',
  'hotel_label',
  'notice_title',
  'notice_text',
  'config_name',
  'config_value',
  'city_hot_search_keyword',
  'city_hot_search_uv',
  'city_hot_search_pv',
  'keyword',
  'rank_metric',
  'room_type_id',
  'room_type_name',
  'sale_status',
  'source_name',
  'source_proportion',
  'competitor_avg_source_proportion',
  'source_pv',
  'source_all_pv',
  'source_rank_tag',
  'start_date',
  'strategy',
  'suggest_action',
  'target_url',
  'task_action',
  'task_name',
  'booking_hour',
  'booking_method',
  'consumption_power',
  'hotel_star_preference',
  'order_hotel_count',
  'order_preference',
  'preference_frequency',
  'user_age',
  'booking_days',
  'price_band',
  'source_city',
  'source_region',
  'stay_days',
  'travel_time',
  'user_sex',
  'user_source',
  'user_source_scope',
  'user_type',
  'price_sensitivity',
  'bad_review_tag',
  'psi_basic_item_id',
  'psi_basic_item_type',
  'psi_basic_item_code',
  'psi_basic_item_name',
  'psi_basic_item_weight',
  'psi_basic_item_score',
  'psi_basic_item_rank',
  'psi_basic_item_score_gap',
  'psi_basic_item_score_gap_unit',
  'psi_basic_item_start_date',
  'psi_basic_item_end_date',
  'psi_basic_item_tips',
  'top_hot_words',
  'top_hot_hotels',
  'ladder_participating',
  'ladder_range_participating',
  'ladder_effect_date',
  'ladder_current_rank',
  'ladder_predicted_rank',
  'ladder_origin_rank',
  'ladder_strategy_ratio',
  'ladder_estimated_traffic_lift',
  'ladder_participate_promote_lift',
  'psi_basic_item_activity_name',
  'psi_basic_item_activity_url',
]);

const CTRIP_COMPETITOR_RANK_FIELD_IDS = new Set([
  'seq_rank',
  'order_rank',
  'amount_rank',
  'quantity_rank',
  'room_nights_rank',
  'avg_price_rank',
  'close_rate_rank',
  'occupancy_rate_rank',
  'comment_score_rank',
  'visitor_rank',
  'conversion_rate_rank',
  'traffic_rank',
  'competition_rank_order_count',
  'competition_rank_order_amount',
  'competition_rank_room_nights',
  'competition_rank_occupancy_rate',
  'competition_rank_app_detail_visitor',
  'competition_rank_app_conversion_rate',
  'competition_rank_psi_score',
  'competition_rank_ctrip_rating',
  'competition_rank_qunar_rating',
  'competition_rank_tongcheng_rating',
  'competition_rank_zhixing_rating',
]);

const CTRIP_RANKING_ENDPOINT_IDS = new Set([
  'business_hotel_seq',
  'traffic_hotel_seq',
  'competitor_rank',
  'weekly_compete_report',
]);

export const CTRIP_FIELD_MISSING_STATUS_VALUES = [
  ['ok', '字段正常采集并解析'],
  ['page_not_loaded', '页面未打开或登录态失效'],
  ['api_not_hit', '页面打开了，但接口没有被触发'],
  ['field_missing', '接口有响应，但源字段不存在'],
  ['empty_value', '字段存在但为空'],
  ['parse_failed', '字段存在但转换失败'],
  ['unverified_mapping', '字段映射还没通过真实样例确认'],
];

const metricLearningRow = (
  label,
  scope,
  timeScope,
  valueType,
  unit,
  sourcePage,
  sourceField,
  transformRule,
  confidenceStatus,
) => ({
  label,
  scope,
  timeScope,
  valueType,
  unit,
  sourcePage,
  sourceField,
  transformRule,
  missingStatus: 'api_not_hit / field_missing / parse_failed',
  sampleValue: '需用真实响应补',
  confidenceStatus,
});

export const CTRIP_CORE_METRIC_LEARNING_ROWS = [
  metricLearningRow('昨日浏览量', '携程OTA渠道', '昨日', '整数', '次', '流量数据页', 'queryScanFlowDetailsV2 / pvDataList', '取列表最后一个有效数值', '待确认'),
  metricLearningRow('昨日访客数', '携程OTA渠道', '昨日', '整数', '人', '昨日概况页', 'getDayReportRealTimeDate / lastVisitorTotal', '直接取整数', '已确认'),
  metricLearningRow('昨日订单数', '携程OTA渠道', '昨日', '整数', '单', '昨日概况页', 'getDayReportRealTimeDate / synchronizationOrderQuantity', '直接取整数', '已确认'),
  metricLearningRow('昨日转化率', '携程OTA渠道', '昨日', '小数', '%', '流量数据页', 'queryScanFlowDetailsV2 / conversionsRatesDataList', '去掉 % 后转小数', '待确认'),
  metricLearningRow('离店销售额', '携程OTA渠道', '昨日', '金额', '元', '经营报告-概要-日报', 'fetchMarketOverViewV2 / amount', '去逗号后转金额', '已确认'),
  metricLearningRow('离店间夜量', '携程OTA渠道', '昨日', '整数', '间夜', '经营报告-概要-日报', 'fetchMarketOverViewV2 / quantity', '转整数', '已确认'),
  metricLearningRow('平均卖价', '携程OTA渠道', '昨日', '金额', '元', '经营报告-概要-日报', 'fetchMarketOverViewV2 / averagePrice', '转金额', '已确认'),
  metricLearningRow('成交率', '携程OTA渠道', '昨日', '小数', '%', '经营报告-概要-日报', 'fetchMarketOverViewV2 / closeRate', '去掉 % 后转小数', '已确认'),
  metricLearningRow('下单转化率', '携程OTA渠道', '昨日', '小数', '%', '昨日概况页', 'fetchMarketOverViewV2 / orderConversionRate', '去掉 % 后转小数', '已确认'),
  metricLearningRow('曝光转化率', '携程OTA渠道', '昨日', '小数', '%', '昨日概况页', 'fetchMarketOverViewV2 / orderConversionRate', '当前代码同取下单转化率，不应自动确认', '待确认'),
  metricLearningRow('离店销售额竞争圈排名', '携程OTA渠道', '昨日', '文本/排名', '名', '经营报告-概要-日报', 'fetchMarketOverViewV2 / rankOfAmount + competitorNumber', '拼成 `排名/总数`', '已确认'),
  metricLearningRow('上周同期离店销售额', '携程OTA渠道', '上周同期', '金额', '元', '经营报告-概要-日报', 'fetchMarketOverViewV2 / synchronizationAmount', '去逗号后转金额', '已确认'),
  metricLearningRow('上周同期离店间夜量', '携程OTA渠道', '上周同期', '整数', '间夜', '经营报告-概要-日报', 'fetchMarketOverViewV2 / synchronizationQuantity', '转整数', '已确认'),
  metricLearningRow('上周同期平均卖价', '携程OTA渠道', '上周同期', '金额', '元', '经营报告-概要-日报', 'fetchMarketOverViewV2 / synchronizationAveragePrice', '转金额', '已确认'),
  metricLearningRow('酒店点评分', '携程OTA渠道', '当前/昨日概况', '小数', '分', '经营报告-概要-日报', 'getDayReportServerQuantity / ctripRatingall', '转 0-5 分', '已确认'),
  metricLearningRow('携程差评数', '携程OTA渠道', '当前点评列表', '整数', '条', '点评页', 'getCommentList / score', '0 < score < 4.0 计数，不保存点评明文', '已确认'),
  metricLearningRow('同程评分', '携程关联渠道', '当前点评列表', '小数', '分', '点评页', 'getCommentList / channel=同程 + score', '按渠道筛选后计算评分', '待确认'),
  metricLearningRow('同程差评数', '携程关联渠道', '当前点评列表', '整数', '条', '点评页', 'getCommentList / channel=同程 + score', 'channel=同程 且 0 < score < 4.0 计数', '待确认'),
  metricLearningRow('去哪儿评分', '携程关联渠道', '当前点评列表', '小数', '分', '点评页', 'getCommentList / channel=去哪儿 + score', '按渠道筛选后计算评分', '待确认'),
  metricLearningRow('去哪儿差评数', '携程关联渠道', '当前点评列表', '整数', '条', '点评页', 'getCommentList / channel=去哪儿 + score', 'channel=去哪儿 且 0 < score < 4.0 计数', '待确认'),
  metricLearningRow('智行评分', '携程关联渠道', '当前点评列表', '小数', '分', '点评页', 'getCommentList / channel=智行 + score', '按渠道筛选后计算评分', '待确认'),
  metricLearningRow('智行差评数', '携程关联渠道', '当前点评列表', '整数', '条', '点评页', 'getCommentList / channel=智行 + score', 'channel=智行 且 0 < score < 4.0 计数', '待确认'),
  metricLearningRow('PSI服务质量分', '携程OTA渠道', '昨日概况', '小数', '分', '经营报告-概要-日报', 'getDayReportServerQuantity / serviceScore', '直接取值', '已确认'),
  metricLearningRow('PSI服务质量分竞争圈排名', '携程OTA渠道', '昨日概况', '整数', '名', '经营报告-概要-日报', 'getDayReportServerQuantity / serviceScoreRank', '直接取值', '已确认'),
  metricLearningRow('5分钟回复率', '携程OTA渠道', '昨日概况', '小数', '%', '经营报告-概要-日报', 'getDayReportServerQuantity / replyrate5m', '去掉 % 后转小数', '已确认'),
  metricLearningRow('5分钟回复率竞争圈排名', '携程OTA渠道', '昨日概况', '整数', '名', '经营报告-概要-日报', 'getDayReportServerQuantity / imScoreHtlrank', '直接取值', '已确认'),
  metricLearningRow('酒店收藏数', '携程OTA渠道', '昨日概况', '整数', '次', '经营报告-概要-日报', 'getDayReportServerQuantity / hotelCollect', '转整数', '已确认'),
  metricLearningRow('酒店收藏数竞争圈排名', '携程OTA渠道', '昨日概况', '整数', '名', '经营报告-概要-日报', 'getDayReportServerQuantity / hotelCollectRank', '直接取值', '已确认'),
  metricLearningRow('差评标签', '携程OTA渠道', '昨日概况', 'JSON', '标签次数', '昨日概况页', 'getDayReportServerQuantity / dingPingEntityList[].tag', '按标签聚合计数', '已确认'),
  metricLearningRow('竞品访客', '携程竞争圈', '昨日', '整数', '人', '昨日概况页', 'getDayReportFlowCompete / comhtluv', '直接取值', '已确认'),
  metricLearningRow('竞品订单', '携程竞争圈', '昨日', '整数', '单', '昨日概况页', 'getDayReportFlowCompete / ordquantity', '直接取值', '已确认'),
  metricLearningRow('竞品收入', '携程竞争圈', '昨日', '金额', '元', '昨日概况页', 'getDayReportFlowCompete / ordamount', '转金额', '已确认'),
  metricLearningRow('实时访客量', '携程OTA渠道', '实时', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / visitorTotal', '直接取值', '已确认'),
  metricLearningRow('实时访客量排名', '携程竞争圈', '实时', '整数', '名', '经营报告-概要-日报', 'fetchVisitorTitleV2 / visitorRank', '直接取值', '已确认'),
  metricLearningRow('实时访客量上周同期', '携程OTA渠道', '上周同期', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / lastVisitorTotal', '直接取值', '已确认'),
  metricLearningRow('携程竞对平均访客数', '携程竞争圈', '实时', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / competitorAvgNumber', '直接取值', '已确认'),
  metricLearningRow('去哪实时访客量', '去哪儿OTA渠道', '实时', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / qunarVisitorTotal', '直接取值', '已确认'),
  metricLearningRow('去哪实时访客量排名', '去哪儿竞争圈', '实时', '整数', '名', '经营报告-概要-日报', 'fetchVisitorTitleV2 / qunarCompetitorRank', '直接取值', '已确认'),
  metricLearningRow('去哪实时访客量上周同期', '去哪儿OTA渠道', '上周同期', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / lastQunarVisitorTotal', '直接取值', '已确认'),
  metricLearningRow('去哪儿竞对平均访客数', '去哪儿竞争圈', '实时', '整数', '人', '经营报告-概要-日报', 'fetchVisitorTitleV2 / qunarCompetitorAvgNumber', '直接取值', '已确认'),
];

const endpoint = (id, section, keywords, fields, extra = {}) => ({
  id,
  section,
  label: extra.label || CTRIP_CAPTURE_SECTIONS[section]?.label || section,
  dataType: extra.dataType || CTRIP_CAPTURE_SECTIONS[section]?.dataType || 'business',
  keywords,
  fields: [...commonFields, ...fields],
  status: extra.status || 'observed',
  notes: extra.notes || '',
});

export const CTRIP_CAPTURE_ENDPOINTS = [
  endpoint('homepage_realtime', 'homepage', ['queryHomePageRealTimeData'], [
    ...revenueFields,
    ...trafficFields,
    ...qualityFields,
    field('loss_order_count', '流失订单数', ['lossOrderCount']),
    field('target_url', '页面跳转地址', ['targetUrl']),
  ]),
  endpoint('platform_resource_popups', 'business_overview', ['getEbkResourcePopups'], [...supportNoticeFields], { status: 'supporting' }),
  endpoint('platform_notifications', 'business_overview', ['getMultiNotifyMessage', 'queryEPush'], [...supportNoticeFields], { status: 'supporting' }),
  endpoint('hotel_advice', 'business_overview', ['getHotelAdvice'], [
    field('diagnosis_score', '数据诊断分', ['score']),
    field('diagnosis_level', '评级', ['scorelevel', 'level']),
    field('advice_count', '经营提醒数量', ['goodhotelAdviceEntityList', 'badhotelAdviceEntityList']),
    field('advice_text', '经营建议', ['tasktext', 'taskname', 'taskbutton']),
  ], { dataType: 'quality' }),
  endpoint('business_realtime', 'business_overview', ['getDayReportRealTimeDate'], [...revenueFields, ...trafficFields]),
  endpoint('business_capacity', 'business_overview', ['fetchCapacityOverViewV4'], [...capacityOverviewFields]),
  endpoint('business_market_overview', 'business_overview', ['fetchMarketOverViewV2'], [...marketOverviewFields]),
  endpoint('business_flow_compete', 'business_overview', ['getDayReportFlowCompete'], [...trafficFields, ...revenueFields, ...competitorFields]),
  endpoint('business_visitor_title', 'business_overview', ['fetchVisitorTitleV2'], [...visitorTitleFields]),
  endpoint('business_hotel_seq', 'business_overview', ['fetchCurrentHotelSeqInfoV1'], [field('seq_rank', '实时排名', ['rank', 'qunarRank', 'competitorRank', 'qunarCompetitorRank'])]),
  endpoint('business_flow_transform', 'business_overview', ['queryFlowTransformNewV1', 'queryFlowTransforNewV1', 'queryFlowTransferNewV1'], [...trafficFields], { dataType: 'traffic' }),
  endpoint('business_service_quantity', 'business_overview', ['getDayReportServerQuantity'], [...dailyServiceQualityFields]),
  endpoint('weekly_compete_report', 'business_weekly_overview', ['getCompeteHotelReportV1'], [
    field('amount_rank', '预订销售额排名', ['amount']),
    field('room_nights_rank', '在店间夜排名', ['quantity']),
    field('order_rank', '预订订单量排名', ['bookOrderNum']),
    field('comment_score_rank', '点评分排名', ['commentScore']),
    field('visitor_rank', 'APP访客量排名', ['totalDetailNum']),
    field('conversion_rate_rank', 'APP转化率排名', ['convertionRate', 'conversionRate']),
  ]),
  endpoint('weekly_report', 'business_weekly_overview', ['getReportSuggestV1', 'getLastWeekReportV1', 'getWeekSuggestionV1', 'getTrafficReportV1', 'getUserBehaviorV1', 'getUserBehavorV1', 'getHotRoomsV1', 'getFlowHotelsV1', 'getHotHotelsV1', 'getHotWordsV1'], [...weeklyLossOrderFields, ...revenueFields, ...trafficFields, ...userProfileFields]),

  endpoint('sales_market_detail', 'sales_report', ['queryMarketDetails', 'queryMarketDetailsV1'], [...revenueFields]),
  endpoint('sales_tensity_overview', 'sales_report', ['fetchTensityOverViewV1'], [...revenueFields]),
  endpoint('sales_order_trend', 'sales_report', ['queryOrderTrendV1'], [...revenueFields]),
  endpoint('sales_occupied_room_trend', 'sales_report', ['queryHotelOccupiedRoomTrendV1', 'getRoomOccupiedRoomTrend'], [...revenueFields]),
  endpoint('sales_tensities', 'sales_report', ['queryHotelTensitiesV1', 'queryRoomTensitiesV1'], [...revenueFields]),
  endpoint('sales_min_price', 'sales_report', ['queryHotelMinPriceV1'], [field('min_price', '实时起价', ['minPrice']), field('min_price_rank', '起价排名', ['minPriceRank'])]),
  endpoint('sales_market_room_tensity', 'sales_report', ['queryMarketRoomTensity', 'queryRoomOccupiedTrend'], [...revenueFields]),
  endpoint('sales_capacity_overview', 'sales_report', ['fetchCapacityOverViewV4'], [...capacityOverviewFields], {
    status: 'supporting',
    notes: 'Observed on the beneficialdata sales page; keep field ownership evidence-bound to the active page context.',
  }),
  endpoint('sales_resource_popups', 'sales_report', ['getEbkResourcePopups'], [...supportNoticeFields], {
    status: 'supporting',
    notes: 'Sales-page support notice only; do not treat popup content as revenue metrics.',
  }),

  endpoint('room_type_info', 'room_type', ['queryRoomTypeInfo'], [
    field('room_type_id', '房型ID', ['roomId', 'roomTypeId', 'basicRoomTypeId']),
    field('room_type_name', '房型名称', ['roomName', 'roomTypeName']),
    field('cancel_rate', '取消率', ['cancelRate']),
    field('available_room', '可用房量', ['canUseBlockRoom', 'availableRoom']),
    field('total_room', '房型房量', ['totalBlockRoom', 'roomCount']),
  ]),
  endpoint('room_competing_hotels', 'room_type', ['queryCompetingHotelsV2'], [
    field('competitor_hotel_name', '竞品酒店名称', ['hotelName']),
    field('distance', '距离', ['distance']),
    field('star_level', '星级', ['starLevel']),
    field('zone_name', '商圈', ['zoneName']),
  ]),
  endpoint('room_competitive_market', 'room_type', ['fetchCompetitiveMarket'], [...revenueFields]),
  endpoint('room_venderbility', 'room_type', ['queryVendibilityRoom', 'queryVenderbilityRoom'], [field('sale_status', '售卖状态', ['saleStatus', 'status']), field('suggest_action', '建议操作', ['suggestAction', 'action'])], { status: 'screenshot_only' }),

  endpoint('traffic_scan_flow', 'traffic_report', ['queryScanFlowDetailsV2'], [...trafficFields]),
  endpoint('traffic_hotel_seq', 'traffic_report', ['fetchCurrentHotelSeqInfoV1'], [
    field('traffic_rank', '实时流量排名', ['rank', 'seqRank', 'trafficRank', 'appDetailUvRank', 'qunarRank', 'competitorRank', 'qunarCompetitorRank']),
  ], { dataType: 'traffic' }),
  endpoint('traffic_flow_transform', 'traffic_report', ['queryFlowTransformNewV1', 'queryFlowTransforNewV1', 'queryFlowTransferNewV1'], [...trafficFields], { dataType: 'traffic' }),
  endpoint('traffic_order_overview', 'traffic_report', ['fetchOrderOverView'], [...revenueFields, ...trafficFields]),
  endpoint('traffic_order_trend', 'traffic_report', ['queryOrderTrendV1'], [...revenueFields, ...trafficFields]),
  endpoint('traffic_flow_source_popups', 'traffic_report', ['queryFlowSourcePopups'], [
    field('source_name', '流量来源弹窗', ['sourceName', 'sourceNameTag', 'title', 'name']),
    ...supportNoticeFields,
  ], {
    status: 'supporting',
    notes: '流量来源辅助弹窗，只保留来源/提示信息，不作为核心流量指标。',
  }),
  endpoint('traffic_flow_source', 'traffic_report', ['queryFlowSource', 'getRealTimeVisitorSourceV1'], [...trafficFlowSourceFields]),
  endpoint('traffic_menu_key', 'traffic_report', ['queryMenuKey'], [...supportNoticeFields], {
    status: 'supporting',
    notes: '流量页菜单/权限辅助接口，只用于判断页面上下文，不作为经营指标。',
  }),
  endpoint('traffic_city_keywords', 'traffic_report', ['queryCityHotKeywords', 'queryQunarCityHotSearch'], [...trafficCityHotSearchFields], {
    dataType: 'traffic',
    status: 'fact_only',
    notes: '城市/同城热门搜索关键词榜单；keyword/uv/pv 是关键词级聚合事实，不写入酒店整体访客量、曝光量或转化率。',
  }),
  endpoint('traffic_search_details', 'traffic_report', ['querySearchFlowDetails'], [...trafficFields]),
  endpoint('traffic_hotel_min_price', 'traffic_report', ['queryHotelMinPriceV1'], [field('min_price', '实时起价', ['minPrice']), field('min_price_rank', '起价排名', ['minPriceRank'])]),
  endpoint('traffic_picture_quality', 'traffic_report', ['getPictureQualityScore'], [...qualityFields]),
  endpoint('traffic_comment_score_summary', 'traffic_report', ['getCommentsScoreV2'], [...qualityFields], { notes: '只采集评分汇总，不采集点评明文。' }),
  endpoint('comment_hotel_rating', 'comment_review', ['getHotelRating'], [...commentAggregateFields], {
    dataType: 'quality',
    status: 'aggregate_only',
    notes: '携程酒店评分汇总接口，只采集总评分和环境/设施/服务/卫生子评分；标签列表不进入默认经营指标。',
  }),
  endpoint('comment_review_aggregate', 'comment_review', ['getCommentNumV2', 'getCommentList'], [...commentAggregateFields], {
    dataType: 'quality',
    status: 'aggregate_only',
    notes: '优先 getCommentNumV2 聚合；getCommentList 仅用于列表评分聚合，不保存点评明文。',
  }),

  endpoint('competitor_management', 'competitor_overview', ['getManagementData'], [...revenueFields]),
  endpoint('competitor_hotel_label', 'competitor_overview', ['getMasterHotelLabel'], [...labelFields], { status: 'supporting' }),
  endpoint('competitor_flow', 'competitor_overview', ['getFlowData'], [...trafficFields]),
  endpoint('competitor_service', 'competitor_overview', ['getServiceData'], [...qualityFields]),
  endpoint('competitor_flow_source', 'competitor_overview', ['getFlowSource'], [...trafficFields]),
  endpoint('loss_order_summary', 'loss_analysis', ['getTripartiteOrderLoss'], [...lossOrderSummaryFields]),
  endpoint('loss_compete_hotel', 'loss_analysis', ['getLossOrderCompeteHotel'], [...lossCompeteHotelFields]),
  endpoint('competitor_rank', 'competitor_rank', ['getCompetingRank'], [
    field('rank_metric', '榜单指标', ['rankType', 'metric', 'rankName']),
    field('competition_rank_order_count', '竞争圈榜单-预订订单量排名', ['bookingOrdersrank', 'orderRank', 'orderQuantityRank', 'bookOrderNum']),
    field('competition_rank_order_amount', '竞争圈榜单-预订销售额排名', ['bookingGMVrank', 'amountRank', 'orderAmountRank', 'amount']),
    field('competition_rank_room_nights', '竞争圈榜单-在店间夜排名', ['stayInRNrank', 'quantity']),
    field('competition_rank_occupancy_rate', '竞争圈榜单-出租率排名', ['rentalRaterank']),
    field('competition_rank_app_detail_visitor', '竞争圈榜单-APP详情页访客量排名', ['totalDetailNum', 'detailVisitorRank', 'appDetailUvRank']),
    field('competition_rank_app_conversion_rate', '竞争圈榜单-APP详情页转化率排名', ['convertionRate', 'conversionRate', 'detailConversionRateRank']),
    field('competition_rank_psi_score', '竞争圈榜单-PSI分排名', ['serviceScoreRank', 'psiScoreRank', 'psiRank']),
    field('competition_rank_ctrip_rating', '竞争圈榜单-携程点评分排名', ['commentScore', 'commentScoreRank', 'ctripCommentScoreRank', 'ctripRatingRank']),
    field('competition_rank_qunar_rating', '竞争圈榜单-去哪儿点评分排名', ['qunarCommentScoreRank', 'qunarRatingRank']),
    field('competition_rank_tongcheng_rating', '竞争圈榜单-同程点评分排名', ['tongchengCommentScoreRank', 'tongChengCommentScoreRank', 'tongchengRatingRank']),
    field('competition_rank_zhixing_rating', '竞争圈榜单-智行点评分排名', ['zhixingCommentScoreRank', 'zhiXingCommentScoreRank', 'zhixingRatingRank']),
    field('order_rank', '预订订单量排名', ['orderRank', 'orderQuantityRank', 'bookingOrdersrank', 'bookOrderNum']),
    field('amount_rank', '预订销售额排名', ['amountRank', 'orderAmountRank', 'bookingGMVrank', 'amount']),
    field('room_nights_rank', '在店间夜排名', ['stayInRNrank', 'quantity']),
    field('occupancy_rate_rank', '出租率排名', ['rentalRaterank']),
    field('comment_score_rank', '点评分排名', ['commentScore']),
    field('visitor_rank', 'APP访客量排名', ['totalDetailNum']),
    field('conversion_rate_rank', 'APP转化率排名', ['convertionRate', 'conversionRate']),
    field('traffic_rank', '流量排名', ['trafficRank', 'appDetailUvRank']),
  ]),

  endpoint('user_profile_features', 'user_profile', ['queryUserFeatures', 'getUserImageList'], [...userProfileFields]),
  endpoint('user_profile_dimensions', 'user_profile', ['queryUserSex', 'queryUserType', 'queryUserPriceInfo', 'queryUserSource', 'queryUserBookingDays', 'queryUserStayDays', 'queryUserAge', 'queryUserPoint', 'queryUserTravelTime', 'queryUserStar', 'queryUserPrice', 'queryOrderType', 'queryUserOrders', 'getOrderDistribution'], [...userProfileFields, ...revenueFields]),
  endpoint('im_index', 'im_board', ['getImIndex'], [
    field('five_min_reply_rate', '5分钟回复率', ['replyRate5m', 'fiveMinReplyRate', 'replyRate']),
    field('manual_reply_rate', '5分钟人工回复率', ['manualReplyRate', 'humanReplyRate', 'manualreplyrate5m', 'manualreplyRate2m']),
    field('robot_resolution_rate', '机器人解决率', ['robotResolutionRate', 'robotResolveRate', 'aisolverate']),
    field('im_rank', 'IM竞争圈排名', ['rank', 'rank2', 'replyrate5mRank', 'manualreplyrate5mRank', 'aisolverateRank']),
  ]),
  endpoint('im_trend', 'im_board', ['getImDateDistribute', 'getImSessionDistribute', 'getImOrderConversionRateByDay', 'getImOrderConversionDetail'], [
    field('session_count', '会话量', ['sessionCount', 'totalSession', 'totalSessions', 'totalsessions', 'conversationCount', 'validsnum']),
    field('manual_session_count', '人工会话量', ['manualSessionCount', 'humanSessionCount', 'manualreplyin5mnum', 'replynum']),
    field('robot_session_count', '机器人会话量', ['robotSessionCount', 'aisolveratenum']),
    field('im_order_conversion_rate', 'IM客人转化率', ['orderConversionRate', 'conversionRate', 'htlreplyCr']),
  ]),

  endpoint('ads_summary_report', 'ads_pyramid', ['queryCampaignSummaryReport'], [...adsFields]),
  endpoint('ads_report_list', 'ads_pyramid', ['queryCampaignReportList'], [...adsFields]),
  endpoint('ads_click_live', 'ads_pyramid', ['queryCpcClickLive'], [...adsFields]),
  endpoint('ads_diagnosis', 'ads_pyramid', ['queryPyramidCpcDiagnosis', 'fetchPyramidCpcDiagnosis'], [...adsFields]),
  endpoint('ads_diagnostic_details', 'ads_pyramid', ['getCpcDiagnosticDetails'], [...adsFields]),
  endpoint('ads_interpretation', 'ads_pyramid', ['fetchCpcDataReportInterpretation'], [...adsFields]),
  endpoint('ads_peer_comparison', 'ads_pyramid', ['getPeerComparisonInfoDetail', 'getHotelZoneName'], [...adsFields, field('peer_avg', '同行平均', ['peerAvg', 'avg']), field('peer_top', '同行头部', ['peerTop', 'top'])]),
  endpoint('ads_filters', 'ads_pyramid', ['queryBasePremiumFilterList', 'queryPromotionKeywords', 'getDspAccounts', 'getCpcCampaignList'], [...adsFields]),
  endpoint('ads_resource_yellow_bar', 'ads_pyramid', ['getEbkResourceYellowBar'], [...supportNoticeFields], { status: 'supporting' }),
  endpoint('ads_dynamic_config', 'ads_pyramid', ['getDynamicConfig'], [...supportNoticeFields], { status: 'supporting' }),
  endpoint('ads_report_injection', 'ads_pyramid', ['reportInjectFnInfo'], [...supportNoticeFields], { status: 'supporting' }),
  endpoint('ladder_simulate_rank', 'ladder_simulate_rank', ['getHotelSimulateRank'], [...ladderSimulateRankFields], {
    status: 'live_probe_observed',
    notes: '云梯预测只代表携程 OTA 推广预测；不得当作实际成交、实际排名结果、全酒店经营数据或投资判断依据。',
  }),

  endpoint('psi_overview', 'quality_psi', ['getHotelPsiV2'], [...qualityFields, ...psiBasicScoreDetailFields]),
  endpoint('psi_growth_task', 'quality_psi', ['queryPsiGrowthTaskList', 'queryRewardScoreActivityList'], [...qualityFields, field('task_name', '提分任务', ['taskName', 'title', 'name']), field('task_action', '行动入口', ['action', 'targetUrl', 'activityUrl', 'url'])]),
  endpoint('psi_history', 'quality_psi', ['queryHistPsiScoreList'], [...qualityFields]),
  endpoint('psi_course', 'quality_psi', ['getRecommendedCourseBy'], [field('course_title', '推荐课程', ['title', 'courseTitle']), field('course_url', '课程链接', ['url', 'targetUrl'])]),

  endpoint('hot_calendar', 'market_calendar', ['queryHotCalendarInfo'], [
    field('hot_spot_name', '热点名称', ['hotSpotName']),
    field('start_date', '开始日期', ['startDate']),
    field('end_date', '结束日期', ['endDate']),
  ]),

  endpoint('biztravel_bpi_overview', 'biztravel_bpi', ['searchBpiOverview'], [...bizTravelFields]),
  endpoint('biztravel_bpi_benefit', 'biztravel_bpi', ['benefitInfoList'], [...benefitFields], { status: 'supporting' }),
  endpoint('biztravel_bpi_table', 'biztravel_bpi', ['getBbkComprehensiveTable'], [...bizTravelFields]),
  endpoint('biztravel_bpi_rank', 'biztravel_bpi', ['searchBpiHotelRank'], [...bizTravelFields]),
  endpoint('biztravel_bpi_trend', 'biztravel_bpi', ['searchBpiScoreTrend', 'bpiScoreTrendFilterList'], [...bizTravelFields]),
  endpoint('biztravel_business_report', 'biztravel_business_report', ['dataCenterBusinessReportDetail'], [...bizTravelFields, ...revenueFields]),
  endpoint('biztravel_competitor_report', 'biztravel_competitor', ['dataCenterComparisonReportDetail', 'dataCenterComparatorReportDetail'], [...bizTravelFields, ...revenueFields, ...trafficFields]),
  endpoint('biztravel_notice', 'biztravel_business_report', ['announcementInfoGet'], [field('announcement', '公告提示', ['content', 'message', 'title'])], { status: 'supporting' }),
];

export const CTRIP_ENDPOINT_CANDIDATE_RULES = [
  {
    id: 'traffic_report',
    label: '经营报告-流量数据',
    priority: 'P0',
    dataType: 'traffic',
    keywords: ['queryflowtransfornewv1', 'queryflowtransformnewv1', 'queryflowtransfernewv1', 'fetchcurrenthotelseqinfov1', 'queryscanflowdetailsv2', 'queryflowsource', 'flowdata', 'traffic', 'listexposure', 'detailexposure'],
  },
  {
    id: 'orders_detail',
    label: '订单明细',
    priority: 'P3',
    dataType: 'order',
    keywords: ['orderdetail', 'orderdetails', 'orderdetailsearch', 'orderlist', 'ordersearch', 'searchorder', 'queryorder', 'orderquery', 'bookingorder', 'reservationorder'],
  },
  {
    id: 'price_inventory',
    label: '价格房态',
    priority: 'P3',
    dataType: 'business',
    keywords: ['ratecalendar', 'ratecalendarprice', 'pricequery', 'pricecalendar', 'roomstatus', 'inventory', 'stock', 'available', 'availability', 'roomrate', 'rateplan'],
  },
  {
    id: 'promotion',
    label: '促销活动',
    priority: 'P3',
    dataType: 'advertising',
    keywords: ['promotion', 'campaign', 'coupon', 'benefit', 'discount', 'activity', 'marketing'],
  },
  {
    id: 'settlement_finance',
    label: '结算财务',
    priority: 'P3',
    dataType: 'finance',
    keywords: ['settlement', 'settle', 'bill', 'billing', 'invoice', 'finance', 'payment', 'accountstatement', 'statement', 'balance'],
  },
  {
    id: 'contract_mice_rfp',
    label: '合同 / MICE / RFP',
    priority: 'P3',
    dataType: 'contract',
    keywords: ['contract', 'contractpre', 'termssearch', 'termsearch', 'mice', 'rfp', 'meeting', 'venue', 'quote', 'quotation', 'agreement'],
  },
];

const SUPPORTING_ENDPOINT_KEYWORDS = [
  'collect',
  'saveloginfo',
  'getappconfig',
  'getebkresourcepopups',
  'querymenukey',
  'reportrecentusedkey',
  'navigationheader',
  'createtuid',
  'downgrade.json',
];

const SECTION_ALIAS_MAP = Object.fromEntries(
  Object.entries(CTRIP_CAPTURE_SECTIONS).flatMap(([id, config]) => [
    [id, id],
    ...(config.aliases || []).map((alias) => [alias, id]),
  ]),
);

export const DEFAULT_CTRIP_CAPTURE_SECTIONS = [
  'business_overview',
  'business_weekly_overview',
  'traffic_report',
];
export const CTRIP_CAPTURE_SECTION_PRESETS = {
  default: [...DEFAULT_CTRIP_CAPTURE_SECTIONS],
  core: ['homepage', 'business_overview', 'business_weekly_overview', 'sales_report', 'traffic_report'],
  wide: [
    'homepage',
    'business_overview',
    'business_weekly_overview',
    'sales_report',
    'traffic_report',
    'comment_review',
    'competitor_overview',
    'loss_analysis',
    'competitor_rank',
    'user_profile',
    'im_board',
    'ads_pyramid',
    'ladder_simulate_rank',
    'quality_psi',
    'market_calendar',
    'biztravel_bpi',
    'biztravel_business_report',
    'biztravel_competitor',
  ],
};

const C = {
  salesData: '\u9500\u552e\u6570\u636e',
  hotel: '\u9152\u5e97',
  roomType: '\u623f\u578b',
  ctrip: '\u643a\u7a0b',
  qunar: '\u53bb\u54ea\u513f',
  tongcheng: '\u540c\u7a0b\u65c5\u884c',
  totalPlatform: '\u603b\u5e73\u53f0',
  realtime: '\u5b9e\u65f6',
  yesterday: '\u6628\u65e5',
  lastWeek: '\u4e0a\u5468',
  lastMonth: '\u4e0a\u6708',
  daily: '\u6309\u65e5',
  weekly: '\u6309\u5468',
  monthly: '\u6309\u6708',
  quarterly: '\u6309\u5b63',
  custom: '\u81ea\u5b9a\u4e49',
  flowData: '\u6d41\u91cf\u6570\u636e',
  all: '\u5168\u90e8',
  app: '\u624b\u673aAPP',
  h5: '\u624b\u673a\u7248H5',
  pc: '\u7535\u8111\u7f51\u9875\u7248',
  wechatMini: '\u5fae\u4fe1\u5c0f\u7a0b\u5e8f',
  last7Days: '\u8fc7\u53bb7\u5929',
  last30Days: '\u8fc7\u53bb30\u5929',
  competitorOverview: '\u7ade\u4e89\u5708\u6982\u89c8',
  lossAnalysis: '\u6d41\u5931\u5206\u6790',
  competitorRank: '\u7ade\u4e89\u5708\u699c\u5355',
  salesRank: '\u9500\u552e\u6392\u540d',
  trafficRank: '\u6d41\u91cf\u6392\u540d',
  orderComment: '\u8ba2\u5355\u70b9\u8bc4',
  commentList: '\u70b9\u8bc4\u5217\u8868',
  dataCenter: '\u6570\u636e\u4e2d\u5fc3',
  userBehavior: '\u7528\u6237\u884c\u4e3a',
  userProfile: '\u7528\u6237\u5206\u6790',
  imBoard: 'IM\u770b\u677f',
  dataReport: '\u6570\u636e\u62a5\u544a',
  myData: '\u6211\u7684\u6570\u636e',
  peerComparison: '\u540c\u884c\u5bf9\u6bd4',
  diagnosisReport: '\u8bca\u65ad\u62a5\u544a',
  accountDimension: '\u8d26\u6237\u7ef4\u5ea6',
  planDimension: '\u8ba1\u5212\u7ef4\u5ea6',
  historyScore: '\u5386\u53f2\u5f97\u5206',
  businessReport: '\u7ecf\u8425\u62a5\u544a',
  competitorDynamic: '\u7ade\u4e89\u5708\u52a8\u6001',
  baseScoreDetail: '\u57fa\u7840\u5206\u8be6\u60c5',
  plusScoreDetail: '\u52a0\u5206\u8be6\u60c5',
  minusScoreDetail: '\u51cf\u5206\u8be6\u60c5',
};

const clickText = (text, reason = '') => ({
  action: 'click_text',
  text,
  reason,
  optional: true,
});

export const CTRIP_SECTION_INTERACTION_PLANS = {
  business_overview: [
    clickText(C.realtime, 'trigger real-time overview cards'),
    clickText(C.daily, 'trigger daily overview charts'),
  ],
  business_weekly_overview: [
    clickText(C.weekly, 'trigger weekly overview charts'),
  ],
  sales_report: [
    clickText(C.salesData, 'open sales report tab'),
    clickText(C.hotel, 'trigger hotel sales scope'),
    clickText(C.totalPlatform, 'trigger total-platform split'),
    clickText(C.ctrip, 'trigger Ctrip split'),
    clickText(C.tongcheng, 'trigger Tongcheng split'),
    clickText(C.qunar, 'trigger Qunar split'),
    clickText(C.realtime, 'trigger real-time sales trend'),
    clickText(C.daily, 'trigger daily sales trend'),
    clickText(C.weekly, 'trigger weekly sales trend'),
    clickText(C.monthly, 'trigger monthly sales trend'),
    clickText(C.quarterly, 'trigger quarterly sales trend'),
    clickText(C.custom, 'trigger custom-date sales trend'),
  ],
  room_type: [
    clickText(C.salesData, 'open sales report tab'),
    clickText(C.roomType, 'trigger room-type sales scope'),
  ],
  traffic_report: [
    clickText(C.flowData, 'open traffic report tab'),
    clickText(C.ctrip, 'trigger Ctrip traffic'),
    clickText(C.qunar, 'trigger Qunar traffic'),
    clickText(C.yesterday, 'trigger yesterday traffic'),
    clickText(C.last7Days, 'trigger 7-day traffic'),
    clickText(C.last30Days, 'trigger 30-day traffic'),
    clickText(C.all, 'trigger all terminal traffic'),
    clickText(C.app, 'trigger app traffic'),
    clickText(C.h5, 'trigger mobile H5 traffic'),
    clickText(C.pc, 'trigger desktop traffic'),
    clickText(C.wechatMini, 'trigger mini-program traffic'),
  ],
  comment_review: [
    clickText(C.orderComment, 'open order review page'),
    clickText(C.commentList, 'trigger review list'),
    clickText(C.all, 'trigger all review filters'),
  ],
  competitor_overview: [
    clickText(C.dataCenter, 'expand data center sidebar'),
    clickText(C.competitorDynamic, 'open competitor dynamic sidebar module'),
    clickText(C.competitorOverview, 'open competitor overview tab'),
    clickText(C.realtime, 'trigger real-time competitor comparison'),
    clickText(C.yesterday, 'trigger yesterday competitor comparison'),
    clickText(C.lastWeek, 'trigger weekly competitor comparison'),
    clickText(C.lastMonth, 'trigger monthly competitor comparison'),
  ],
  loss_analysis: [
    clickText(C.dataCenter, 'expand data center sidebar'),
    clickText(C.competitorDynamic, 'open competitor dynamic sidebar module'),
    clickText(C.lossAnalysis, 'open loss analysis tab'),
    clickText(C.ctrip, 'trigger Ctrip loss analysis'),
    clickText(C.qunar, 'trigger Qunar loss analysis'),
    clickText(C.yesterday, 'trigger yesterday loss analysis'),
    clickText(C.lastWeek, 'trigger weekly loss analysis'),
    clickText(C.lastMonth, 'trigger monthly loss analysis'),
  ],
  competitor_rank: [
    clickText(C.dataCenter, 'expand data center sidebar'),
    clickText(C.competitorDynamic, 'open competitor dynamic sidebar module'),
    clickText(C.competitorRank, 'open competitor rank tab'),
    clickText(C.salesRank, 'trigger sales ranking'),
    clickText(C.trafficRank, 'trigger traffic ranking'),
    clickText(C.ctrip, 'trigger Ctrip ranking'),
    clickText(C.qunar, 'trigger Qunar ranking'),
    clickText(C.realtime, 'trigger real-time ranking'),
    clickText(C.yesterday, 'trigger yesterday ranking'),
    clickText(C.lastWeek, 'trigger weekly ranking'),
    clickText(C.lastMonth, 'trigger monthly ranking'),
  ],
  user_profile: [
    clickText(C.dataCenter, 'expand data center sidebar'),
    clickText(C.userBehavior, 'open user behavior sidebar module'),
    clickText(C.userProfile, 'open user profile tab'),
  ],
  im_board: [
    clickText(C.dataCenter, 'expand data center sidebar'),
    clickText(C.userBehavior, 'open user behavior sidebar module'),
    clickText(C.imBoard, 'open IM dashboard tab'),
  ],
  ads_pyramid: [
    clickText(C.dataReport, 'open pyramid data report'),
    clickText(C.myData, 'trigger own ad data'),
    clickText(C.peerComparison, 'trigger peer ad comparison'),
    clickText(C.last7Days, 'trigger 7-day ad report'),
    clickText(C.last30Days, 'trigger 30-day ad report'),
    clickText(C.diagnosisReport, 'open pyramid diagnosis report'),
    clickText(C.accountDimension, 'trigger account diagnosis'),
    clickText(C.planDimension, 'trigger plan diagnosis'),
  ],
  quality_psi: [
    clickText(C.historyScore, 'trigger PSI history'),
  ],
  biztravel_bpi: [
    clickText(C.baseScoreDetail, 'trigger BPI base score detail'),
    clickText(C.plusScoreDetail, 'trigger BPI plus score detail'),
    clickText(C.minusScoreDetail, 'trigger BPI minus score detail'),
  ],
  biztravel_business_report: [
    clickText(C.businessReport, 'open biztravel business report'),
  ],
  biztravel_competitor: [
    clickText(C.competitorDynamic, 'open biztravel competitor module'),
    clickText(C.competitorOverview, 'trigger biztravel competitor overview'),
    clickText(C.lossAnalysis, 'trigger biztravel loss analysis'),
    clickText(C.competitorRank, 'trigger biztravel competitor ranking'),
  ],
};

export function getCtripSectionInteractionPlan(section = '') {
  return (CTRIP_SECTION_INTERACTION_PLANS[section] || []).map((step) => ({ ...step }));
}

export function normalizeCtripCaptureSections(value = '') {
  const raw = String(value || '').trim().toLowerCase();
  if (!raw) {
    return [...CTRIP_CAPTURE_SECTION_PRESETS.default];
  }
  if (Object.prototype.hasOwnProperty.call(CTRIP_CAPTURE_SECTION_PRESETS, raw)) {
    return [...CTRIP_CAPTURE_SECTION_PRESETS[raw]];
  }
  if (raw === '*' || raw === 'all') {
    return Object.keys(CTRIP_CAPTURE_SECTIONS);
  }

  const selected = [];
  const invalid = [];
  for (const token of raw.split(/[,\s]+/).filter(Boolean)) {
    if (['review', 'reviews', 'comment', 'comments'].includes(token)) {
      throw new Error('Comment/review capture is disabled by policy');
    }
    const section = SECTION_ALIAS_MAP[token] || '';
    if (!section) {
      invalid.push(token);
      continue;
    }
    if (!selected.includes(section)) {
      selected.push(section);
    }
  }

  if (invalid.length > 0) {
    throw new Error(`Unsupported Ctrip capture section: ${invalid.join(', ')}`);
  }
  return selected.length ? selected : [...DEFAULT_CTRIP_CAPTURE_SECTIONS];
}

export function buildCtripPageUrls() {
  return Object.fromEntries(
    Object.entries(CTRIP_CAPTURE_SECTIONS).map(([id, config]) => [id, config.pageUrls || []]),
  );
}

export function buildCtripKeywordMap({ detailed = true } = {}) {
  const result = {};
  for (const endpoint of CTRIP_CAPTURE_ENDPOINTS) {
    const key = detailed ? endpoint.section : endpoint.dataType;
    result[key] ||= [];
    result[key].push(...endpoint.keywords);
  }
  for (const key of Object.keys(result)) {
    result[key] = [...new Set(result[key])];
  }
  return result;
}

export function findCtripEndpointByUrl(url, options = {}) {
  const lower = String(url || '').toLowerCase();
  if (!lower) {
    return null;
  }
  const preferredSections = Array.isArray(options.preferredSections)
    ? options.preferredSections
    : [options.preferredSection].filter(Boolean);
  const contextSections = inferCtripSectionsFromContext(options);
  const orderedPreferredSections = [...new Set([...preferredSections, ...contextSections])];
  const matches = CTRIP_CAPTURE_ENDPOINTS.filter((endpoint) => (
    endpoint.keywords.some((keyword) => lower.includes(String(keyword).toLowerCase()))
  ));
  if (orderedPreferredSections.length > 0) {
    const preferred = matches.find((endpoint) => orderedPreferredSections.includes(endpoint.section));
    if (preferred) {
      return preferred;
    }
  }
  return matches[0] || null;
}

function inferCtripSectionsFromContext(options = {}) {
  const context = options.pageContext || options.page_context || {};
  const sections = [];
  for (const value of [
    context.active_section,
    context.activeSection,
    context.capture_section,
    context.captureSection,
    context.section,
    context.module,
  ]) {
    const section = SECTION_ALIAS_MAP[String(value || '').trim().toLowerCase()];
    if (section) {
      sections.push(section);
    }
  }

  const pageUrl = String(
    options.pageUrl
      || options.page_url
      || context.page_url
      || context.pageUrl
      || context.url
      || '',
  ).toLowerCase();
  if (pageUrl) {
    for (const [section, config] of Object.entries(CTRIP_CAPTURE_SECTIONS)) {
      if ((config.pageUrls || []).some((item) => pageUrl.includes(String(item.url || '').split('?')[0].toLowerCase()))) {
        sections.push(section);
        break;
      }
    }
  }

  return [...new Set(sections)];
}

export function buildCtripEndpointCandidates(entries = []) {
  const items = Array.isArray(entries) ? entries : [entries];
  const candidates = [];
  const seen = new Set();

  for (const item of items) {
    const url = typeof item === 'string'
      ? item
      : String(item?.url || item?.request_url || item?.requestUrl || '');
    if (!url || findCtripEndpointByUrl(url)) {
      continue;
    }

    const canonical = canonicalCandidateUrl(url);
    if (!canonical || seen.has(canonical)) {
      continue;
    }
    seen.add(canonical);

    const lower = canonical.toLowerCase();
    if (!isCtripCatalogUrl(lower)) {
      continue;
    }
    if (SUPPORTING_ENDPOINT_KEYWORDS.some((keyword) => lower.includes(keyword))) {
      continue;
    }

    let matchedRule = null;
    let matchedKeyword = '';
    for (const rule of CTRIP_ENDPOINT_CANDIDATE_RULES) {
      matchedKeyword = rule.keywords.find((keyword) => lower.includes(String(keyword).toLowerCase())) || '';
      if (matchedKeyword) {
        matchedRule = rule;
        break;
      }
    }
    if (!matchedRule) {
      continue;
    }

    const parsed = parseCandidateUrl(url);
    candidates.push({
      url,
      canonical_url: canonical,
      host: parsed?.host || '',
      path: parsed?.pathname || '',
      endpoint_name: endpointNameFromUrl(parsed, url),
      candidate_section: matchedRule.id,
      candidate_label: matchedRule.label,
      priority: matchedRule.priority,
      data_type: matchedRule.dataType,
      reason: `keyword:${matchedKeyword}`,
      evidence_status: 'needs_payload_response',
      safe_to_catalog: false,
      required_evidence: ['Request URL', 'Payload', 'Preview / Response', 'page/tab context', 'hotel/date parameters'],
      status: typeof item === 'object' && item ? item.status ?? null : null,
      request_type: typeof item === 'object' && item ? item.request_type || '' : '',
      method: typeof item === 'object' && item ? item.method || '' : '',
    });
  }

  return candidates;
}

function isCtripCatalogUrl(url) {
  const lower = String(url || '').toLowerCase();
  return lower.includes('ctrip.com')
    || lower.includes('ctripbiz.cn')
    || lower.includes('ctripbiz.com');
}

export function classifyCtripUrl(url, { detailed = true } = {}) {
  const endpoint = findCtripEndpointByUrl(url);
  if (!endpoint) {
    return '';
  }
  return detailed ? endpoint.section : endpoint.dataType;
}

export function sectionDataType(section) {
  return CTRIP_CAPTURE_SECTIONS[section]?.dataType || 'business';
}

export function sectionLabel(section) {
  return CTRIP_CAPTURE_SECTIONS[section]?.label || section;
}

export function ctripCatalogSummary() {
  const fieldIds = new Set();
  for (const endpoint of CTRIP_CAPTURE_ENDPOINTS) {
    for (const item of endpoint.fields || []) {
      fieldIds.add(item.id);
    }
  }
  return {
    platform: 'ctrip',
    section_count: Object.keys(CTRIP_CAPTURE_SECTIONS).length,
    endpoint_count: CTRIP_CAPTURE_ENDPOINTS.length,
    field_count: fieldIds.size,
    default_sections: [...DEFAULT_CTRIP_CAPTURE_SECTIONS],
    presets: Object.fromEntries(
      Object.entries(CTRIP_CAPTURE_SECTION_PRESETS).map(([key, sections]) => [key, [...sections]]),
    ),
    interaction_plan_section_count: Object.values(CTRIP_SECTION_INTERACTION_PLANS).filter((steps) => steps.length > 0).length,
    interaction_plan_step_count: Object.values(CTRIP_SECTION_INTERACTION_PLANS).reduce((sum, steps) => sum + steps.length, 0),
  };
}

function ctripHotelIdFromNode(node, fallback = '') {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return String(fallback || '').trim();
  }
  for (const key of ['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID']) {
    if (Object.prototype.hasOwnProperty.call(node, key) && node[key] !== null && node[key] !== '') {
      return String(node[key]).trim();
    }
  }
  return String(fallback || '').trim();
}

function ctripHotelIdSourcePriority(sourceKey) {
  const key = String(sourceKey || '').toLowerCase();
  if (key === 'masterhotelid' || key === 'master_hotel_id') {
    return 1;
  }
  if (key === 'hotelid' || key === 'hotel_id') {
    return 2;
  }
  return 10;
}

export function extractCtripCatalogFacts(value, context = {}) {
  const endpointInfo = context.endpoint || null;
  const fields = endpointInfo?.fields || [];
  if (!value || typeof value !== 'object' || fields.length === 0) {
    return [];
  }

  const fieldsBySourceKey = new Map();
  for (const item of fields) {
    for (const key of item.sourceKeys || []) {
      const normalized = String(key).toLowerCase();
      if (!fieldsBySourceKey.has(normalized)) {
        fieldsBySourceKey.set(normalized, []);
      }
      fieldsBySourceKey.get(normalized).push(item);
    }
  }

  const facts = [];
  const walk = (node, path = [], scopedContext = context) => {
    if (facts.length >= 2000 || node === null || node === undefined) {
      return;
    }
    if (Array.isArray(node)) {
      node.forEach((item, index) => walk(item, [...path, String(index)], scopedContext));
      return;
    }
    if (typeof node !== 'object') {
      return;
    }
    const nodeHotelId = ctripHotelIdFromNode(node, scopedContext.hotelId || '');
    const nodeContext = nodeHotelId !== String(scopedContext.hotelId || '').trim()
      ? { ...scopedContext, hotelId: nodeHotelId }
      : scopedContext;
    facts.push(...extractEndpointSpecificFacts(node, path, fields, nodeContext, endpointInfo));
    facts.push(...extractMetricPairFacts(node, path, fields, nodeContext));
    const metricPairLike = isMetricPairObject(node);
    for (const [key, child] of Object.entries(node)) {
      if (endpointInfo?.id === 'comment_review_aggregate' && isCommentReviewListKey(key)) {
        continue;
      }
      const matchedFields = filterCtripCatalogFieldsBySourceContext(
        fieldsBySourceKey.get(String(key).toLowerCase()) || [],
        key,
        path,
      );
      if (matchedFields.length > 0 && isScalar(child)) {
        for (const item of matchedFields) {
          if (metricPairLike && key === 'name' && item.id === 'hotel_name') {
            continue;
          }
          facts.push({
            platform: normalizeCtripCapturePlatform(context.platform),
            section: context.section || endpointInfo?.section || '',
            endpoint_id: endpointInfo?.id || '',
            endpoint_label: endpointInfo?.label || '',
            data_type: endpointInfo?.dataType || context.dataType || '',
            metric_key: item.id,
            metric_label: item.label,
            metric_scope: item.scope,
            unit: item.unit,
            source_key: key,
            source_path: [...path, key].join('.'),
            value: normalizeFactValue(child),
            value_type: typeof child,
            hotel_id: nodeContext.hotelId || '',
            data_date: nodeContext.dataDate || '',
            captured_at: nodeContext.capturedAt || '',
            source_url: nodeContext.url || '',
            source_parent_path: path.join('.'),
          });
        }
      }
      walk(child, [...path, key], nodeContext);
    }
  };
  walk(value);
  return facts;
}

function isCommentReviewListKey(key) {
  return ['commentlist', 'comments', 'reviews', 'list', 'rows'].includes(String(key || '').toLowerCase());
}

function filterCtripCatalogFieldsBySourceContext(fields, sourceKey, path = []) {
  if (!Array.isArray(fields) || fields.length === 0) {
    return [];
  }
  const key = String(sourceKey || '').toLowerCase();
  const parentSegments = path.map((item) => String(item || '').toLowerCase());
  const parent = parentSegments.join('.');
  const inPsiBasicScoreItem = parentSegments.includes('basicscoreextlist')
    && !parentSegments.includes('ruleconfiglist');
  let result = fields;

  if (inPsiBasicScoreItem) {
    if (['startdate', 'enddate'].includes(key)) {
      result = result.filter((item) => String(item.id || '') !== 'date');
    }
    if (key === 'name') {
      result = result.filter((item) => String(item.id || '') !== 'hotel_name');
    }
  } else {
    result = result.filter((item) => !String(item.id || '').startsWith('psi_basic_item_'));
  }

  const inListRow = ['commentlist', 'comments', 'reviews', 'list', 'rows']
    .some((segment) => parentSegments.includes(segment));
  if (inListRow && ['score', 'commentscore', 'rating', 'rate'].includes(key)) {
    result = result.filter((item) => !['comment_score', 'bad_review_count'].includes(String(item.id || '')));
  }

  if (
    parentSegments.includes('subscores')
    && ['score', 'scoresimple', 'commentcount', 'commentscore', 'badreviewcount'].includes(key)
  ) {
    result = result.filter((item) => !['comment_count', 'comment_score', 'bad_review_count'].includes(String(item.id || '')));
  }

  const inHotelRatingAggregate = parentSegments.includes('ratinginfo')
    || parentSegments.includes('ctripratings')
    || parentSegments.includes('elongratings');
  if (inHotelRatingAggregate && ['ratingall', 'ratinglocation', 'ratingfacility', 'ratingservice', 'ratingroom'].includes(key)) {
    return result.filter((item) => ![
      'comment_score',
      'review_environment_score',
      'review_facility_score',
      'review_service_score',
      'review_cleanliness_score',
    ].includes(String(item.id || '')));
  }

  const inLossOrderVo = parentSegments.includes('lossordervo');
  if (!inLossOrderVo || !['ordernum', 'ordquantity', 'ordamount'].includes(key)) {
    return result;
  }
  return result.filter((item) => !['order_count', 'order_amount'].includes(String(item.id || '')));
}

const COMPETITOR_INDEX_FIELD_IDS = new Map([
  [0, 'order_count'],
  [1, 'order_amount'],
  [2, 'room_nights'],
  [3, 'occupancy_rate'],
  [4, 'avg_price'],
  [5, 'conversion_rate'],
  [6, 'visitor_count'],
  [7, 'detail_visitor'],
  [8, 'order_submit_user'],
  [9, 'flow_rate'],
  [10, 'conversion_rate'],
  [11, 'comment_score_summary'],
  [12, 'psi_score'],
]);

const REVIEW_PHOTO_COUNT_KEYS = ['hasPicCount', 'photoCommentCount', 'pictureCommentCount', 'imageCommentCount'];
const REVIEW_COMMENT_COUNT_KEYS = ['commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount', 'allCount'];
const COMMENT_CHANNEL_CONTAINERS = new Map([
  ['ctripCount', { channel: '携程', commentCountMetric: 'ctrip_comment_count' }],
  ['qunarCount', { channel: '去哪儿', commentCountMetric: 'qunar_comment_count' }],
  ['elongCount', { channel: '艺龙', commentCountMetric: 'elong_comment_count' }],
  ['zxCount', { channel: '智行', commentCountMetric: 'zx_comment_count' }],
  ['zhixingCount', { channel: '智行', commentCountMetric: 'zx_comment_count' }],
]);

const WEEKLY_REPORT_FIELD_DEFS = new Map([
  ['last_week_checkout_room_nights', field('last_week_checkout_room_nights', '周报离店间夜量', [], '', { unit: '间夜' })],
  ['last_week_checkout_sales', field('last_week_checkout_sales', '周报离店销售额', [], '', { unit: '元' })],
  ['last_week_checkout_room_price', field('last_week_checkout_room_price', '周报离店平均房价', [], '', { unit: '元' })],
  ['last_week_book_quantity', field('last_week_book_quantity', '周报预订订单', [], '', { unit: '单' })],
  ['last_week_book_room_nights', field('last_week_book_room_nights', '周报预订间夜量', [], '', { unit: '间夜' })],
  ['last_week_book_sales', field('last_week_book_sales', '周报预订销售额', [], '', { unit: '元' })],
  ['weekly_self_list_exposure', field('weekly_self_list_exposure', '周报本店列表页曝光', [], '', { unit: '次' })],
  ['weekly_self_detail_exposure', field('weekly_self_detail_exposure', '周报本店详情页访客', [], '', { unit: '人' })],
  ['weekly_self_order_filling_num', field('weekly_self_order_filling_num', '周报本店订单填写页访客', [], '', { unit: '人' })],
  ['weekly_self_order_submit_num', field('weekly_self_order_submit_num', '周报本店订单提交人数', [], '', { unit: '人' })],
  ['weekly_self_flow_rate', field('weekly_self_flow_rate', '周报本店曝光转化率', [], '', { unit: '%' })],
  ['weekly_self_order_fill_rate', field('weekly_self_order_fill_rate', '周报本店下单转化率', [], '', { unit: '%' })],
  ['weekly_self_deal_rate', field('weekly_self_deal_rate', '周报本店成交转化率', [], '', { unit: '%' })],
  ['weekly_competitor_list_exposure', field('weekly_competitor_list_exposure', '周报竞争圈列表页曝光', [], '', { unit: '次' })],
  ['weekly_competitor_detail_exposure', field('weekly_competitor_detail_exposure', '周报竞争圈详情页访客', [], '', { unit: '人' })],
  ['weekly_competitor_order_filling_num', field('weekly_competitor_order_filling_num', '周报竞争圈订单填写页访客', [], '', { unit: '人' })],
  ['weekly_competitor_order_submit_num', field('weekly_competitor_order_submit_num', '周报竞争圈订单提交人数', [], '', { unit: '人' })],
  ['weekly_competitor_flow_rate', field('weekly_competitor_flow_rate', '周报竞争圈曝光转化率', [], '', { unit: '%' })],
  ['weekly_competitor_order_fill_rate', field('weekly_competitor_order_fill_rate', '周报竞争圈下单转化率', [], '', { unit: '%' })],
  ['weekly_competitor_deal_rate', field('weekly_competitor_deal_rate', '周报竞争圈成交转化率', [], '', { unit: '%' })],
  ['top_competitor_list_exposure', field('top_competitor_list_exposure', '周报同城标杆列表页曝光', [], '', { unit: '次' })],
  ['top_competitor_detail_exposure', field('top_competitor_detail_exposure', '周报同城标杆详情页访客', [], '', { unit: '人' })],
  ['top_competitor_order_filling_num', field('top_competitor_order_filling_num', '周报同城标杆订单填写页访客', [], '', { unit: '人' })],
  ['top_competitor_order_submit_num', field('top_competitor_order_submit_num', '周报同城标杆订单提交人数', [], '', { unit: '人' })],
  ['top_competitor_flow_rate', field('top_competitor_flow_rate', '周报同城标杆曝光转化率', [], '', { unit: '%' })],
  ['top_competitor_order_fill_rate', field('top_competitor_order_fill_rate', '周报同城标杆下单转化率', [], '', { unit: '%' })],
  ['top_competitor_deal_rate', field('top_competitor_deal_rate', '周报同城标杆成交转化率', [], '', { unit: '%' })],
  ['last_week_comment_score', field('last_week_comment_score', '周报点评分', [], '', { unit: '分' })],
  ['last_week_good_add', field('last_week_good_add', '周报新增好评数', [], '', { unit: '条' })],
  ['last_week_bad_add', field('last_week_bad_add', '周报新增差评数', [], '', { unit: '条' })],
  ['last_week_price_score', field('last_week_price_score', '周报起价竞争分', [], '', { unit: '分' })],
  ['flow_lost_order_num', field('flow_lost_order_num', '周报流失订单量', [], '', { unit: '单' })],
  ['flow_lost_room_nights', field('flow_lost_room_nights', '周报流失间夜量', [], '', { unit: '间夜' })],
  ['flow_lost_amount', field('flow_lost_amount', '周报流失订单金额', [], '', { unit: '元' })],
  ['top_flow_hotel', field('top_flow_hotel', '周报流失访客榜首酒店', [])],
  ['top_flow_hotel_browse_rate', field('top_flow_hotel_browse_rate', '周报榜首酒店同时浏览率', [], '', { unit: '%' })],
  ['top_flow_hotel_order_rate', field('top_flow_hotel_order_rate', '周报榜首酒店下单转化率', [], '', { unit: '%' })],
  ['top_hot_room', field('top_hot_room', '周报热售房型TOP1', [])],
  ['top_hot_room_nights', field('top_hot_room_nights', '周报热售房型TOP1间夜', [], '', { unit: '间夜' })],
  ['top_hot_room_sale_percent', field('top_hot_room_sale_percent', '周报热售房型TOP1销售占比', [], '', { unit: '%' })],
  ['hot_words_count', field('hot_words_count', '周报同城热门关键词数量', [], '', { unit: '个' })],
  ['top_hot_words', field('top_hot_words', '周报同城热门关键词TOP10', [])],
  ['hot_hotels_count', field('hot_hotels_count', '周报同城热门酒店数量', [], '', { unit: '家' })],
  ['top_hot_hotels', field('top_hot_hotels', '周报同城热门酒店TOP10', [])],
]);

function extractEndpointSpecificFacts(node, path, fields, context, endpointInfo) {
  const endpointId = endpointInfo?.id || '';
  if (endpointId === 'weekly_report') {
    return extractWeeklyReportFacts(node, path, context, endpointInfo);
  }
  if (endpointId === 'comment_review_aggregate') {
    return [
      ...extractCommentChannelContainerFacts(node, path, fields, context, endpointInfo),
      ...extractCommentReviewAggregateFacts(node, path, fields, context, endpointInfo),
      ...extractReviewPhotoRateFacts(node, path, fields, context, endpointInfo),
    ];
  }
  if (endpointId === 'comment_hotel_rating') {
    return extractHotelRatingFacts(node, path, fields, context, endpointInfo);
  }
  if (endpointId === 'psi_overview') {
    return extractPsiBasicScoreItemFacts(node, path, fields, context, endpointInfo);
  }
  if (endpointId === 'user_profile_dimensions') {
    return extractUserProfileDistributionFacts(node, path, fields, context, endpointInfo);
  }
  if (endpointId === 'traffic_comment_score_summary') {
    return extractReviewPhotoRateFacts(node, path, fields, context, endpointInfo);
  }
  if (endpointId === 'traffic_flow_source') {
    return extractTrafficFlowSourceKeywordFacts(node, path, fields, context, endpointInfo);
  }
  if (['competitor_management', 'competitor_flow', 'competitor_service'].includes(endpointId)) {
    return extractCompetitorIndexFacts(node, path, fields, context, endpointInfo);
  }
  return [];
}

function extractTrafficFlowSourceKeywordFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }

  const fieldInfo = fields.find((item) => item.id === 'keyword');
  if (!fieldInfo) {
    return [];
  }

  const facts = [];
  for (const sourceKey of ['keywords', 'filterWords']) {
    const items = node[sourceKey];
    if (!Array.isArray(items)) {
      continue;
    }
    items.forEach((item, index) => {
      if (!isScalar(item) || String(item).trim() === '') {
        return;
      }
      facts.push(buildEndpointSpecificFact({
        context,
        endpointInfo,
        fieldInfo,
        sourceKey: `${sourceKey}[]`,
        sourcePath: [...path, sourceKey, String(index)],
        sourceParentPath: [...path, sourceKey],
        value: item,
        derived_from: 'queryFlowSource_keyword_array',
      }));
    });
  }

  return facts;
}

function extractWeeklyReportFacts(node, path, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }

  const facts = [];
  const url = String(context.url || '').toLowerCase();
  const parentPath = path.map((item) => String(item));
  const pathKey = parentPath.join('.');
  const push = (metricKey, sourceKey, sourcePath, value, sourceParentPath = sourcePath.slice(0, -1)) => {
    if (value === undefined || value === null || (typeof value === 'string' && value.trim() === '')) {
      return;
    }
    const fieldInfo = WEEKLY_REPORT_FIELD_DEFS.get(metricKey);
    if (!fieldInfo) {
      return;
    }
    facts.push(buildEndpointSpecificFact({
      context,
      endpointInfo,
      fieldInfo,
      sourceKey,
      sourcePath,
      sourceParentPath,
      value,
    }));
  };

  if (url.includes('getlastweekreportv1') && pathKey === 'data') {
    for (const [metricKey, sourceKey] of [
      ['last_week_checkout_room_nights', 'lastWeekCheckoutRoomNights'],
      ['last_week_checkout_sales', 'lastWeekCheckoutSales'],
      ['last_week_checkout_room_price', 'lastWeekCheckoutRoomPrice'],
      ['last_week_book_quantity', 'lastWeekBookQuantity'],
      ['last_week_book_room_nights', 'lastWeekBookRoomNights'],
      ['last_week_book_sales', 'lastWeekBookSales'],
    ]) {
      push(metricKey, sourceKey, [...parentPath, sourceKey], node[sourceKey], parentPath);
    }
  }

  if (url.includes('gettrafficreportv1')) {
    const trafficGroups = {
      'data.myHotel': 'weekly_self',
      'data.competeHotelAvg': 'weekly_competitor',
      'data.topCompeteHotel': 'top_competitor',
    };
    const prefix = trafficGroups[pathKey];
    if (prefix) {
      for (const [suffix, sourceKey] of [
        ['list_exposure', 'totalListExposure'],
        ['detail_exposure', 'totalDetailExposure'],
        ['order_filling_num', 'orderFillingNum'],
        ['order_submit_num', 'orderSubmitNum'],
        ['flow_rate', 'listTransforDetailRate'],
        ['order_fill_rate', 'detailTransforOrderFillRate'],
        ['deal_rate', 'orderFillTransforOrderSubmitRate'],
      ]) {
        push(`${prefix}_${suffix}`, sourceKey, [...parentPath, sourceKey], node[sourceKey], parentPath);
      }
    }
  }

  if ((url.includes('getuserbehaviorv1') || url.includes('getuserbehavorv1')) && pathKey === 'data') {
    for (const [metricKey, sourceKey] of [
      ['last_week_comment_score', 'lastWeekCommentScore'],
      ['last_week_good_add', 'lastWeekGoodAdd'],
      ['last_week_bad_add', 'lastWeekBadAdd'],
      ['last_week_price_score', 'lastWeekPriceScore'],
    ]) {
      push(metricKey, sourceKey, [...parentPath, sourceKey], node[sourceKey], parentPath);
    }
  }

  if (url.includes('getflowhotelsv1')) {
    if (pathKey === 'data.lossOrderVo') {
      for (const [metricKey, sourceKey] of [
        ['flow_lost_order_num', 'ordernum'],
        ['flow_lost_room_nights', 'ordquantity'],
        ['flow_lost_amount', 'ordamount'],
      ]) {
        push(metricKey, sourceKey, [...parentPath, sourceKey], node[sourceKey], parentPath);
      }
    }
    if (pathKey === 'data.flowHotelItemVos.0') {
      for (const [metricKey, sourceKey] of [
        ['top_flow_hotel', 'hotelName'],
        ['top_flow_hotel_browse_rate', 'proportion'],
        ['top_flow_hotel_order_rate', 'orderPro'],
      ]) {
        push(metricKey, sourceKey, [...parentPath, sourceKey], node[sourceKey], parentPath);
      }
    }
  }

  if (url.includes('gethotroomsv1') && pathKey === 'data.hotRooms.0') {
    const roomName = node.roomShortName ?? node.roomName;
    push('top_hot_room', node.roomShortName !== undefined ? 'roomShortName' : 'roomName', [...parentPath, node.roomShortName !== undefined ? 'roomShortName' : 'roomName'], roomName, parentPath);
    push('top_hot_room_nights', 'saleRoomNights', [...parentPath, 'saleRoomNights'], node.saleRoomNights, parentPath);
    push('top_hot_room_sale_percent', 'salePercent', [...parentPath, 'salePercent'], node.salePercent, parentPath);
  }

  if (url.includes('gethotwordsv1') && pathKey === '' && Array.isArray(node.data)) {
    const items = node.data.filter((item) => item !== null && item !== undefined && String(item).trim() !== '');
    push('hot_words_count', 'data[]', ['data'], items.length, []);
    push('top_hot_words', 'data[0:10]', ['data'], items.slice(0, 10), []);
  }

  if (url.includes('gethothotelsv1') && pathKey === '' && Array.isArray(node.data)) {
    const items = node.data.filter((item) => item !== null && item !== undefined && String(item).trim() !== '');
    push('hot_hotels_count', 'data[]', ['data'], items.length, []);
    push('top_hot_hotels', 'data[0:10]', ['data'], items.slice(0, 10), []);
  }

  return facts;
}

function extractCompetitorIndexFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)
    || !Object.prototype.hasOwnProperty.call(node, 'indexType')
    || !Object.prototype.hasOwnProperty.call(node, 'val')) {
    return [];
  }

  const indexType = Number(node.indexType);
  const metricFieldId = COMPETITOR_INDEX_FIELD_IDS.get(indexType);
  if (!metricFieldId) {
    return [];
  }

  const facts = [];
  pushEndpointSpecificFact(facts, {
    node,
    path,
    fields,
    context,
    endpointInfo,
    metricFieldId,
    sourceKey: 'val',
  });
  pushEndpointSpecificFact(facts, {
    node,
    path,
    fields,
    context,
    endpointInfo,
    metricFieldId: 'competitor_average',
    sourceKey: 'avgComp',
  });
  pushEndpointSpecificFact(facts, {
    node,
    path,
    fields,
    context,
    endpointInfo,
    metricFieldId: 'rank',
    sourceKey: 'rankComp',
  });
  return facts;
}

const HOTEL_RATING_FIELD_PATHS = [
  ['comment_score', 'ratingAll', ['ratingAll']],
  ['review_environment_score', 'ratingLocation', ['ratingLocation']],
  ['review_facility_score', 'ratingFacility', ['ratingFacility']],
  ['review_service_score', 'ratingService', ['ratingService']],
  ['review_cleanliness_score', 'ratingRoom', ['ratingRoom']],
];

const HOTEL_RATING_SUB_SCORE_TYPES = new Map([
  ['ratinglocation', 'review_environment_score'],
  ['环境', 'review_environment_score'],
  ['ratingfacility', 'review_facility_score'],
  ['设施', 'review_facility_score'],
  ['ratingservice', 'review_service_score'],
  ['服务', 'review_service_score'],
  ['ratingroom', 'review_cleanliness_score'],
  ['卫生', 'review_cleanliness_score'],
]);

function extractHotelRatingFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node) || path.length > 0) {
    return [];
  }

  const roots = [
    ['ratingInfo', node.ratingInfo],
    ['ctripRatings', node.ctripRatings],
    ['elongRatings', node.elongRatings],
    ['data', node.data],
    ['', node],
  ].filter(([, value]) => value && typeof value === 'object' && !Array.isArray(value));
  const facts = [];
  const seen = new Set();
  const aggregateParentPath = ['getHotelRating'];
  const push = (metricFieldId, sourceKey, sourcePath, value, sourceParentPath = aggregateParentPath) => {
    const fieldInfo = fields.find((item) => item.id === metricFieldId);
    if (!fieldInfo || !isScalar(value) || value === '') {
      return;
    }
    const dedupeKey = `${metricFieldId}:${sourcePath.join('.')}:${String(value)}`;
    if (seen.has(dedupeKey)) {
      return;
    }
    seen.add(dedupeKey);
    facts.push(buildEndpointSpecificFact({
      context,
      endpointInfo,
      fieldInfo,
      sourceKey,
      sourcePath,
      sourceParentPath,
      value,
      derived_from: 'getHotelRating',
    }));
  };

  for (const [rootName, root] of roots) {
    const rootPath = rootName ? [rootName] : [];
    for (const [metricFieldId, sourceKey, relativePath] of HOTEL_RATING_FIELD_PATHS) {
      const value = getNestedValue(root, relativePath);
      if (value !== undefined && value !== null) {
        push(metricFieldId, sourceKey, [...rootPath, ...relativePath], value);
      }
    }

    const scoreInfo = root.scoreInfo && typeof root.scoreInfo === 'object' ? root.scoreInfo : null;
    const subScores = Array.isArray(scoreInfo?.subScores) ? scoreInfo.subScores : [];
    subScores.forEach((item, index) => {
      if (!item || typeof item !== 'object' || Array.isArray(item)) {
        return;
      }
      const typeKey = String(item.type || item.name || '').trim();
      const metricFieldId = HOTEL_RATING_SUB_SCORE_TYPES.get(typeKey.toLowerCase()) || HOTEL_RATING_SUB_SCORE_TYPES.get(typeKey);
      if (!metricFieldId) {
        return;
      }
      const valueKey = item.scoreSimple !== undefined && item.scoreSimple !== null ? 'scoreSimple' : 'score';
      push(
        metricFieldId,
        `${typeKey}.${valueKey}`,
        [...rootPath, 'scoreInfo', 'subScores', String(index), valueKey],
        item[valueKey],
        aggregateParentPath,
      );
    });
  }

  return facts;
}

function getNestedValue(root, path) {
  let value = root;
  for (const segment of path) {
    if (!value || typeof value !== 'object' || !Object.prototype.hasOwnProperty.call(value, segment)) {
      return undefined;
    }
    value = value[segment];
  }
  return value;
}

function extractCommentChannelContainerFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }

  const facts = [];
  for (const [containerKey, meta] of COMMENT_CHANNEL_CONTAINERS.entries()) {
    const container = node[containerKey];
    if (!container || typeof container !== 'object' || Array.isArray(container)) {
      continue;
    }

    const parentPath = [...path, containerKey];
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'comment_channel', containerKey, parentPath, meta.channel);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'comment_count', 'commentCount', [...parentPath, 'commentCount'], container.commentCount, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, meta.commentCountMetric, 'commentCount', [...parentPath, 'commentCount'], container.commentCount, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'bad_review_count', 'noRecommendCount', [...parentPath, 'noRecommendCount'], container.noRecommendCount, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'comment_unreply_count', 'unReplyCount', [...parentPath, 'unReplyCount'], container.unReplyCount, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'review_photo_count', 'hasPicCount', [...parentPath, 'hasPicCount'], container.hasPicCount, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'comment_good_rate', 'goodRate', [...parentPath, 'goodRate'], container.goodRate, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'comment_response_rate', 'responseRate', [...parentPath, 'responseRate'], container.responseRate, parentPath);
    pushCommentContainerFact(facts, fields, context, endpointInfo, 'target_url', 'jumpUrl', [...parentPath, 'jumpUrl'], container.jumpUrl, parentPath);
  }

  return facts;
}

function pushCommentContainerFact(target, fields, context, endpointInfo, metricFieldId, sourceKey, sourcePath, value, sourceParentPath = sourcePath) {
  const fieldInfo = fields.find((item) => item.id === metricFieldId);
  if (!fieldInfo || !isScalar(value) || value === '') {
    return;
  }

  target.push(buildEndpointSpecificFact({
    context,
    endpointInfo,
    fieldInfo,
    sourceKey,
    sourcePath,
    sourceParentPath,
    value,
    derived_from: 'comment_channel_container',
  }));
}

function extractReviewPhotoRateFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }
  const photoCount = firstNumericNodeValue(node, REVIEW_PHOTO_COUNT_KEYS);
  const commentCount = firstNumericNodeValue(node, REVIEW_COMMENT_COUNT_KEYS);
  if (!photoCount || !commentCount || photoCount.number < 0 || commentCount.number <= 0) {
    return [];
  }

  const fieldInfo = fields.find((item) => item.id === 'review_photo_rate');
  if (!fieldInfo) {
    return [];
  }

  const rate = Math.round((photoCount.number / commentCount.number) * 1000) / 10;
  return [buildEndpointSpecificFact({
    context,
    endpointInfo,
    fieldInfo,
    sourceKey: `${photoCount.key}/${commentCount.key}`,
    sourcePath: [...path, photoCount.key],
    sourceParentPath: path,
    value: rate,
    derived_from: 'hasPicCount/commentCount',
  })];
}

function firstNumericNodeValue(node, keys) {
  for (const key of keys) {
    if (!Object.prototype.hasOwnProperty.call(node, key)) {
      continue;
    }
    const number = numericFactValue(node[key]);
    if (number !== null) {
      return { key, number };
    }
  }
  return null;
}

function extractCommentReviewAggregateFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }

  const entries = [
    ['commentList', node.commentList],
    ['comments', node.comments],
    ['reviews', node.reviews],
    ['rows', node.rows],
    ['list', node.list],
  ].filter(([, value]) => Array.isArray(value));

  if (entries.length === 0) {
    return [];
  }

  const facts = [];
  for (const [sourceKey, rows] of entries) {
    const sourcePath = [...path, sourceKey];
    pushDerivedCommentFact(facts, fields, context, endpointInfo, 'comment_count', 'commentList.length', sourcePath, rows.length, path);

    const scores = rows
      .map((row) => commentReviewScore(row))
      .filter((score) => score !== null && score > 0);
    if (scores.length > 0) {
      const averageScore = Math.round((scores.reduce((sum, score) => sum + score, 0) / scores.length) * 10) / 10;
      pushDerivedCommentFact(facts, fields, context, endpointInfo, 'comment_score', 'score_average', sourcePath, averageScore, path);
      pushDerivedCommentFact(facts, fields, context, endpointInfo, 'bad_review_count', 'score_lt_4_count', sourcePath, scores.filter((score) => score > 0 && score < 4).length, path);
    }
  }

  return facts;
}

function pushDerivedCommentFact(target, fields, context, endpointInfo, metricFieldId, sourceKey, sourcePath, value, sourceParentPath = sourcePath) {
  const fieldInfo = fields.find((item) => item.id === metricFieldId);
  if (!fieldInfo || !isScalar(value)) {
    return;
  }
  target.push(buildEndpointSpecificFact({
    context,
    endpointInfo,
    fieldInfo,
    sourceKey,
    sourcePath,
    sourceParentPath,
    value,
    derived_from: 'comment_review_aggregate',
  }));
}

function commentReviewScore(row) {
  if (!row || typeof row !== 'object') {
    return null;
  }
  for (const key of ['score', 'commentScore', 'rating', 'rate', 'totalScore', 'overallScore', 'star']) {
    if (!Object.prototype.hasOwnProperty.call(row, key)) {
      continue;
    }
    const number = Number(String(row[key]).replace(/,/g, '').trim());
    if (Number.isFinite(number)) {
      return number;
    }
  }
  return null;
}

function extractUserProfileDistributionFacts(node, path, fields, context, endpointInfo) {
  const lowerUrl = String(context.url || '').toLowerCase();
  if (!lowerUrl.includes('/userbehavior/')) {
    return [];
  }

  if (lowerUrl.includes('queryuserpriceinfo')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'price_sensitivity',
      syntheticPathName: 'priceSensitivity',
    });
  }

  if (lowerUrl.includes('getorderdistribution')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'booking_hour',
      syntheticPathName: 'bookingHour',
    });
  }

  if (lowerUrl.includes('queryusertraveltime')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'travel_time',
      syntheticPathName: 'travelTime',
    });
  }

  if (lowerUrl.includes('queryuserstar')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'hotel_star_preference',
      syntheticPathName: 'hotelStar',
    });
  }

  if (lowerUrl.includes('queryuserprice')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'consumption_power',
      syntheticPathName: 'consumptionPower',
    });
  }

  if (lowerUrl.includes('queryordertype')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'booking_method',
      syntheticPathName: 'bookingMethod',
    });
  }

  if (lowerUrl.includes('queryuserorders')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      dimensionFieldId: 'order_hotel_count',
      syntheticPathName: 'orderHotelCount',
    });
  }

  if (lowerUrl.includes('queryuserage')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      avgFieldId: 'avg_user_age',
      dimensionFieldId: 'user_age',
      syntheticPathName: 'userAge',
    });
  }

  if (lowerUrl.includes('queryuserpoint')) {
    return extractUserPointPreferenceFacts(node, path, fields, context, endpointInfo);
  }

  if (lowerUrl.includes('queryuserbookingdays')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      avgFieldId: 'avg_booking_days',
      dimensionFieldId: 'booking_days',
      syntheticPathName: 'bookingDays',
    });
  }

  if (lowerUrl.includes('queryuserstaydays')) {
    return extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, {
      avgFieldId: 'avg_stay_days',
      dimensionFieldId: 'stay_days',
      syntheticPathName: 'stayDays',
    });
  }

  if (lowerUrl.includes('queryusersource')) {
    return extractUserSourceDistributionFacts(node, path, fields, context, endpointInfo);
  }

  const metricFieldId = userProfileNameValueMetricFieldId(lowerUrl);
  if (!metricFieldId || !isNameValueDistributionNode(node)) {
    return [];
  }

  return buildUserDistributionFactPair({
    fields,
    context,
    endpointInfo,
    path,
    dimensionFieldId: metricFieldId,
    dimensionSourceKey: 'name',
    dimensionValue: node.name,
    shareSourceKey: 'value',
    shareValue: node.value,
  });
}

function userProfileNameValueMetricFieldId(lowerUrl) {
  if (lowerUrl.includes('queryusersex')) {
    return 'user_sex';
  }
  if (lowerUrl.includes('queryusertype')) {
    return 'user_type';
  }
  if (lowerUrl.includes('getorderdistribution')) {
    return 'booking_hour';
  }
  if (lowerUrl.includes('queryusertraveltime')) {
    return 'travel_time';
  }
  if (lowerUrl.includes('queryuserstar')) {
    return 'hotel_star_preference';
  }
  if (lowerUrl.includes('queryuserprice') && !lowerUrl.includes('queryuserpriceinfo')) {
    return 'consumption_power';
  }
  if (lowerUrl.includes('queryordertype')) {
    return 'booking_method';
  }
  if (lowerUrl.includes('queryuserorders')) {
    return 'order_hotel_count';
  }
  return '';
}

function isNameValueDistributionNode(node) {
  return Boolean(
    node
    && typeof node === 'object'
    && !Array.isArray(node)
    && isScalar(node.name)
    && userDistributionShareKey(node)
  );
}

function extractUserTitleListDistributionFacts(node, path, fields, context, endpointInfo, options = {}) {
  const shareList = userDistributionShareList(node);
  if (!node || typeof node !== 'object' || Array.isArray(node) || !Array.isArray(node.titleList) || !shareList) {
    return [];
  }

  const facts = [];
  const avgFieldId = String(options.avgFieldId || '');
  if (avgFieldId && isScalar(node.avg)) {
    const avgField = fields.find((item) => item.id === avgFieldId);
    if (avgField) {
      facts.push(buildEndpointSpecificFact({
        context,
        endpointInfo,
        fieldInfo: avgField,
        sourceKey: 'avg',
        sourcePath: [...path, 'avg'],
        sourceParentPath: path,
        value: node.avg,
      }));
    }
  }

  const dimensionFieldId = String(options.dimensionFieldId || '');
  const syntheticPathName = String(options.syntheticPathName || dimensionFieldId || 'distribution');
  if (!dimensionFieldId) {
    return facts;
  }

  node.titleList.forEach((title, index) => {
    const share = shareList.values[index];
    if (!isScalar(title) || !isScalar(share)) {
      return;
    }
    facts.push(...buildUserDistributionFactPair({
      fields,
      context,
      endpointInfo,
      path: [...path, syntheticPathName, String(index)],
      dimensionFieldId,
      dimensionSourceKey: 'titleList',
      dimensionSourcePath: [...path, 'titleList', String(index)],
      dimensionValue: title,
      shareSourceKey: shareList.key,
      shareSourcePath: [...path, shareList.key, String(index)],
      shareValue: share,
    }));
  });
  return facts;
}

function extractUserSourceDistributionFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return [];
  }

  const facts = [];
  if (path.length === 1 && String(path[0] || '').toLowerCase() === 'data') {
    for (const [sourceKey, label] of [
      ['localCityRate', '本地'],
      ['otherCityRate', '异地'],
      ['topCityRate', 'TOP5城市'],
    ]) {
      if (!isScalar(node[sourceKey])) {
        continue;
      }
      facts.push(...buildUserDistributionFactPair({
        fields,
        context,
        endpointInfo,
        path: [...path, sourceKey],
        dimensionFieldId: 'user_source_scope',
        dimensionSourceKey: sourceKey,
        dimensionSourcePath: [...path, sourceKey],
        dimensionValue: label,
        shareSourceKey: sourceKey,
        shareSourcePath: [...path, sourceKey],
        shareValue: node[sourceKey],
      }));
    }
    return facts;
  }

  const parent = path.map((item) => String(item || '').toLowerCase());
  const lastParent = parent[parent.length - 2] || '';
  if (!isNameValueDistributionNode(node) || !['provinces', 'cities'].includes(lastParent)) {
    return [];
  }

  const shareKey = userDistributionShareKey(node);
  return buildUserDistributionFactPair({
    fields,
    context,
    endpointInfo,
    path,
    dimensionFieldId: lastParent === 'cities' ? 'source_city' : 'source_region',
    dimensionSourceKey: 'name',
    dimensionValue: node.name,
    shareSourceKey: shareKey,
    shareValue: node[shareKey],
  });
}

function userDistributionShareList(node) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return null;
  }
  for (const key of ['valueList', 'percentList', 'rateList', 'ratioList', 'shareList', 'proportionList']) {
    if (Array.isArray(node[key])) {
      return { key, values: node[key] };
    }
  }
  return null;
}

function userDistributionShareKey(node) {
  if (!node || typeof node !== 'object' || Array.isArray(node)) {
    return '';
  }
  for (const key of ['value', 'percent', 'rate', 'ratio', 'share', 'proportion']) {
    if (isMeaningfulScalar(node[key])) {
      return key;
    }
  }
  return '';
}

function extractUserPointPreferenceFacts(node, path, fields, context, endpointInfo) {
  if (!node || typeof node !== 'object' || Array.isArray(node)
    || !Array.isArray(node.titleList)
    || !Array.isArray(node.userColumnBos)) {
    return [];
  }
  if (path.length !== 1 || String(path[0] || '').toLowerCase() !== 'data') {
    return [];
  }

  const preferenceField = fields.find((item) => item.id === 'order_preference');
  const frequencyField = fields.find((item) => item.id === 'preference_frequency');
  const shareField = fields.find((item) => item.id === 'distribution_share');
  if (!preferenceField || !frequencyField || !shareField) {
    return [];
  }

  const facts = [];
  node.userColumnBos.forEach((column, frequencyIndex) => {
    if (!column || typeof column !== 'object' || Array.isArray(column)
      || !Array.isArray(column.titleList)
      || !Array.isArray(column.valueList)) {
      return;
    }
    const frequency = column.titleList[frequencyIndex];
    if (!isScalar(frequency)) {
      return;
    }
    node.titleList.forEach((preference, preferenceIndex) => {
      const share = column.valueList[preferenceIndex];
      if (!isScalar(preference) || !isScalar(share)) {
        return;
      }
      const cellPath = [...path, 'userPoint', String(frequencyIndex), String(preferenceIndex)];
      facts.push(buildEndpointSpecificFact({
        context,
        endpointInfo,
        fieldInfo: preferenceField,
        sourceKey: 'titleList',
        sourcePath: [...path, 'titleList', String(preferenceIndex)],
        sourceParentPath: cellPath,
        value: preference,
      }));
      facts.push(buildEndpointSpecificFact({
        context,
        endpointInfo,
        fieldInfo: frequencyField,
        sourceKey: 'titleList',
        sourcePath: [...path, 'userColumnBos', String(frequencyIndex), 'titleList', String(frequencyIndex)],
        sourceParentPath: cellPath,
        value: frequency,
      }));
      facts.push(buildEndpointSpecificFact({
        context,
        endpointInfo,
        fieldInfo: shareField,
        sourceKey: 'valueList',
        sourcePath: [...path, 'userColumnBos', String(frequencyIndex), 'valueList', String(preferenceIndex)],
        sourceParentPath: cellPath,
        value: share,
      }));
    });
  });
  return facts;
}

function buildUserDistributionFactPair({
  fields,
  context,
  endpointInfo,
  path,
  dimensionFieldId,
  dimensionSourceKey,
  dimensionSourcePath = null,
  dimensionValue,
  shareSourceKey,
  shareSourcePath = null,
  shareValue,
}) {
  const dimensionField = fields.find((item) => item.id === dimensionFieldId);
  const shareField = fields.find((item) => item.id === 'distribution_share');
  if (!dimensionField || !shareField || !isScalar(dimensionValue) || !isScalar(shareValue)) {
    return [];
  }

  return [
    buildEndpointSpecificFact({
      context,
      endpointInfo,
      fieldInfo: dimensionField,
      sourceKey: dimensionSourceKey,
      sourcePath: dimensionSourcePath || [...path, dimensionSourceKey],
      sourceParentPath: path,
      value: dimensionValue,
    }),
    buildEndpointSpecificFact({
      context,
      endpointInfo,
      fieldInfo: shareField,
      sourceKey: shareSourceKey,
      sourcePath: shareSourcePath || [...path, shareSourceKey],
      sourceParentPath: path,
      value: shareValue,
    }),
  ];
}

function extractPsiBasicScoreItemFacts(node, path, fields, context, endpointInfo) {
  const parentSegments = path.map((item) => String(item || '').toLowerCase());
  if (!parentSegments.includes('basicscoreextlist')
    || parentSegments.includes('ruleconfiglist')
    || !Object.prototype.hasOwnProperty.call(node, 'code')) {
    return [];
  }

  const itemType = psiBasicScoreItemType(node.code);
  if (!itemType) {
    return [];
  }
  const fieldInfo = fields.find((item) => item.id === 'psi_basic_item_type');
  if (!fieldInfo) {
    return [];
  }

  return [buildEndpointSpecificFact({
    context,
    endpointInfo,
    fieldInfo,
    sourceKey: 'code',
    sourcePath: [...path, 'code'],
    sourceParentPath: path,
    value: itemType,
    derived_from: 'psi_basic_item_code',
  })];
}

function psiBasicScoreItemType(code) {
  const value = String(code || '').trim().toUpperCase();
  if (['A', 'B', 'C'].includes(value)) {
    return '经营产能';
  }
  if (['D', 'E', 'F'].includes(value)) {
    return '房源保障';
  }
  if (['G', 'H', 'I'].includes(value)) {
    return '客户服务';
  }
  return '';
}

function pushEndpointSpecificFact(target, { node, path, fields, context, endpointInfo, metricFieldId, sourceKey }) {
  if (!Object.prototype.hasOwnProperty.call(node, sourceKey)) {
    return;
  }
  const fieldInfo = fields.find((item) => item.id === metricFieldId) || endpointSpecificFallbackField(metricFieldId);
  if (!fieldInfo || !isScalar(node[sourceKey])) {
    return;
  }
  target.push(buildEndpointSpecificFact({
    context,
    endpointInfo,
    fieldInfo,
    sourceKey,
    sourcePath: [...path, sourceKey],
    sourceParentPath: path,
    value: node[sourceKey],
  }));
}

function buildEndpointSpecificFact({
  context,
  endpointInfo,
  fieldInfo,
  sourceKey,
  sourcePath,
  sourceParentPath,
  value,
  derived_from = '',
}) {
  return {
    platform: normalizeCtripCapturePlatform(context.platform),
    section: context.section || endpointInfo?.section || '',
    endpoint_id: endpointInfo?.id || '',
    endpoint_label: endpointInfo?.label || '',
    data_type: endpointInfo?.dataType || context.dataType || '',
    metric_key: fieldInfo.id,
    metric_label: fieldInfo.label,
    metric_scope: fieldInfo.scope,
    unit: fieldInfo.unit,
    source_key: sourceKey,
    source_path: sourcePath.map((item) => String(item)).join('.'),
    value: normalizeFactValue(value),
    value_type: typeof value,
    hotel_id: context.hotelId || '',
    data_date: context.dataDate || '',
    captured_at: context.capturedAt || '',
    source_url: context.url || '',
    source_parent_path: sourceParentPath.map((item) => String(item)).join('.'),
    ...(derived_from ? { derived_from } : {}),
  };
}

function endpointSpecificFallbackField(metricFieldId) {
  if (metricFieldId === 'competitor_average') {
    return field('competitor_average', '竞争圈平均值', []);
  }
  if (metricFieldId === 'rank') {
    return field('rank', '竞争圈排名', []);
  }
  return null;
}

export function buildCtripStandardRowsFromFacts(facts = [], context = {}) {
  if (!Array.isArray(facts) || facts.length === 0) {
    return [];
  }

  const groups = new Map();
  for (const fact of facts) {
    if (!fact || typeof fact !== 'object') {
      continue;
    }
    const key = standardRowGroupKey(fact);
    if (!groups.has(key)) {
      groups.set(key, []);
    }
    groups.get(key).push(fact);
  }

  const rows = [];
  for (const groupFacts of groups.values()) {
    const row = buildStandardRow(groupFacts, context);
    if (row) {
      rows.push(row);
    }
  }
  return rows;
}

export function generateCtripCaptureMarkdown({ i18nReference = null } = {}) {
  const lines = [
    '# 携程数据基石：项目口径、字段目录与采集逻辑',
    '',
    '> 口径：本目录只描述携程 eBooking / 携程商旅后台中，酒店授权账号可见的 OTA 或商旅渠道数据；不等同于 PMS 全渠道经营数据。',
    '',
    '## 项目定位',
    '',
    '宿析OS 的携程数据基石不是单个接口清单，也不是把页面数值简单搬进系统；它是把平台可见经营事实接入“诊断、动作、复盘、沉淀”的经营反馈系统输入层。',
    '',
    '```text',
    '携程 / 携程商旅可见数据',
    '-> 采集证据与字段目录',
    '-> 标准事实与质量校验',
    '-> 收益 / 流量 / 竞对 / 服务 / 广告 / 商旅诊断',
    '-> AI 运营建议',
    '-> 运营动作跟踪',
    '-> 效果复盘',
    '-> 投资和扩张判断',
    '```',
    '',
    '项目文字描述统一为：宿析OS 以授权 OTA 可见数据为经营输入，用采集证据、字段标准化和质量校验保证口径，再把收益、流量、转化、竞争圈、服务质量、广告和商旅数据转成可解释诊断、待确认 AI 建议、运营动作与效果复盘。',
    '',
    '这句话对应的系统逻辑：',
    '',
    '1. 先证明数据来源：保留页面、接口、Payload、Response、酒店、日期、采集方式和失败原因。',
    '2. 再统一字段口径：中文名优先参考页面和 i18n 语言包，但翻译包不是业务数据，入库字段必须绑定 source path 和证据状态。',
    '3. 再形成标准事实：数值事实进入 `standard_rows`，非数值事实保留在 `raw_data`，不伪造金额、订单或间夜。',
    '4. 再进入经营诊断：收益、流量、转化、竞对、服务、广告、商旅分开分析，不混用 OTA、竞争圈、商旅和全酒店口径。',
    '5. 最后进入动作复盘：AI 只给建议和解释，关键动作必须人工确认、记录执行证据，并用下一轮数据复盘。',
    '',
    '本目录用于固定三类信息：',
    '',
    '- 可抓页面、接口规则和字段命名。',
    '- 字段来源、模块归属、渠道口径和观察状态。',
    '- 后续保存、回显、编辑、质量监控和分析映射的统一依据。',
    '- i18n 翻译包和前端埋点代码只作为页面语义、术语命名和触发线索，不进入经营指标事实。',
    '',
    '## 字段到业务动作的链路',
    '',
    '| 环节 | 输入 | 宿析OS处理 | 输出 |',
    '|---|---|---|---|',
    '| 采集证据 | Request URL、Payload、Preview / Response、页面上下文、酒店和日期参数 | 校验来源、脱敏敏感字段、标记 observed / inferred / P3 候选 | 可审计证据包和接口候选矩阵 |',
    '| 字段目录 | i18n 术语、页面展示名、接口 source path | 统一标准字段、中文名、来源字段和口径；翻译包不作为业务数据，无法确认时保留原始字段名 | catalog_facts |',
    '| 标准事实 | 订单、间夜、销售额、曝光、访客、转化、排名、PSI、BPI、广告等字段 | 按 OTA 渠道口径写入 rows；非数值事实只进 raw_data | standard_rows / online_daily_data |',
    '| 经营诊断 | 标准事实、竞争圈均值、趋势和缺失状态 | 拆分收益、流量、转化、竞对、服务质量、广告和商旅原因 | 可解释诊断和阻塞原因 |',
    '| 运营动作 | AI 建议、人工确认、执行条件和目标指标 | 记录动作、负责人、观察期、执行证据和风险 | 可追踪动作单 |',
    '| 效果复盘 | 下一轮 OTA 数据、执行前后指标、失败原因 | 对比目标和实际结果，沉淀有效策略或暴露无效动作 | 复盘结论和投资/扩张参考 |',
    '',
    '## 采集逻辑',
    '',
    '```text',
    '授权门店账号 / 浏览器 Profile',
    '-> 打开携程或携程商旅后台页面',
    '-> 页面触发 XHR / Fetch 业务接口',
    '-> 按接口目录匹配模块和字段',
    '-> 解析 JSON 响应',
    '-> 脱敏、去重、字段抽取',
    '-> 输出 catalog_facts / rows',
    '-> 标准导入、质量校验和经营分析',
    '```',
    '',
    '- `observed` 表示已由页面截图或接口名称确认；`inferred` 表示导航地址按模块推断，仍需实测复核。',
    '- 优先解析结构化 JSON；DOM 页面值只作为可见值补充，不替代接口口径。',
    '- i18n 翻译包中的功能、按钮、指标、提示语、节假日、国家地区和埋点上报代码只用于理解页面，不作为订单、流量、收益、竞争圈或服务质量事实。',
    '- 采集失败、字段缺失、口径不明必须显式记录状态，不用默认值覆盖问题。',
    '- Profile 采集会按模块执行 `interaction_plan`，尝试点击页签、平台、周期和维度按钮；结果写入 `pages[].interactions` 作为触发证据。',
    '- 未点击到的页面控件会记录为 `not_visible` 或 `disabled`，不会把未触发接口伪装成已采集字段。',
    '',
    '## 字段进入系统的判定顺序',
    '',
    '| 顺序 | 判断点 | 通过条件 | 不通过处理 |',
    '|---|---|---|---|',
    '| 1 | 页面与接口来源 | URL 属于携程 / 携程商旅授权后台，且能关联酒店、日期或当前门店上下文 | 标记为非业务接口或待补上下文 |',
    '| 2 | 证据完整度 | 有 Request URL、Payload、Preview / Response 或可复现的页面触发记录 | 保留为 P3 候选，不进入正式字段目录 |',
    '| 3 | 字段口径 | 能在页面、i18n 术语、source path 或接口值中确认含义，且业务值来自接口/页面展示而非翻译包 | 保留原字段名和 source path，不改写成确定指标 |',
    '| 4 | 数据类型 | 能判断为收益、流量、转化、竞争圈、服务质量、广告、商旅或辅助事实 | 仅进入 `raw_data`，不参与指标计算 |',
    '| 5 | 入库方式 | 数值事实可映射到标准行；文本、标签、建议、日历等事实只做来源记录 | 标记 `fact_only` 或 `review_required` |',
    '| 6 | 分析使用 | 字段有来源、口径、时间和采集状态 | 缺字段或采集失败时显式阻塞，不使用默认值替代 |',
    '',
    '## 数据分层',
    '',
    '| 层级 | 作用 | 输出形态 |',
    '|---|---|---|',
    '| source_page | 后台页面和页签入口 | 页面 URL、模块、观察状态 |',
    '| endpoint_rule | 可识别接口规则 | endpoint id、关键词、数据类型 |',
    '| raw_response | 原始接口响应 | 脱敏后的 JSON 摘要 |',
    '| catalog_facts | 字段目录抽取结果 | 标准字段、中文名、来源字段、值、来源路径 |',
    '| standard_rows | 后续导入行 | 酒店、平台、日期、指标、数值、采集状态；非数值事实只进 raw_data |',
    '| analysis_subject | 经营分析对象 | 收益、流量、转化、竞争圈、服务质量、广告、商旅 |',
    '',
    '- 热度日历、用户画像、课程/策略等非数值事实会标记 `raw_data.fact_only=true`，不伪造 `amount`、`quantity`、`book_order_num`。',
    '- 竞争圈卡片中的 `myValue / competitorAvg / rank` 会按单卡片成行，分别保留本店值、竞争圈平均和排名。',
    '- Profile 采集结果会返回 `standard_data_type_counts`、`standard_section_counts`、`endpoint_candidate_counts`、`p3_evidence_counts` 和 `p3_evidence_status_counts`，用于判断标准事实命中情况、P3 候选接口缺口和证据草稿完整度。',
    '',
    '## 模块优先级',
    '',
    '| 优先级 | 范围 | 用途 |',
    '|---|---|---|',
    '| P0 | 首页实时、经营报告概要、销售数据、流量数据 | 收益分析、日报、流量漏斗和核心运营诊断 |',
    '| P1 | 订单点评聚合、竞争圈概览、流失分析、竞争圈榜单、PSI、热点日历、用户分析 | 点评聚合、竞对对比、流失去向、服务质量、市场热度和客群结构判断 |',
    '| P2 | 金字塔推广、云梯排名预测、PSI 服务质量分、IM 看板、BPI 分、携程商旅经营报告 | 广告投放、推广预测、服务质量、客服响应、商旅渠道和企业客户表现 |',
    '| P3 | 订单明细、价格房态、促销活动、结算财务、合同/MICE/RFP | 仍需补充真实接口 Payload / Response 后再进入字段目录 |',
    '',
    '## 采集范围预设',
    '',
    '| 预设 | 覆盖范围 | 使用场景 |',
    '|---|---|---|',
    '| `default` | 经营报告概要、流量数据 | 日常低成本自动抓取 |',
    '| `core` | 首页实时、经营报告概要、销售数据、流量数据 | P0 核心经营诊断 |',
    '| `wide` | P0 + 竞争圈、流失、榜单、用户行为、IM、金字塔、云梯、PSI、商旅 BPI/经营/竞争圈 | 周期性全量经营复核 |',
    '| `all` | 字段目录内全部非点评明文模块 | 手动盘点或接口变更复核 |',
    '',
    '- Profile 采集参数可传 `--sections=core`、`--sections=wide`、`--sections=all`，或逗号分隔的模块 ID / alias。',
    '',
    '## 口径边界',
    '',
    '- OTA 指标只代表携程或携程商旅渠道表现，不能直接写成全酒店出租率、ADR、RevPAR 或全渠道收入。',
    '- 竞争圈指标代表平台定义的同圈对比，不代表市场全量。',
    '- 携程 / Trip.com eBooking 中文前端翻译包是语言资源和前端线索，不是业务数据；埋点代码不进入经营诊断。',
    '- 点评明文、住客隐私、账号 Cookie、token、签名和授权头不进入字段目录、日志或报告。',
    '- 点评相关接口默认不采集明文内容；仅保留点评分、PSI、回复率等经营质量指标。',
    '',
    '## 核心指标学习表',
    '',
    '- 学习表必须同时记录口径、时间口径、类型、单位、来源网页、来源接口/源字段、转换规则、缺失状态、样例值和可信状态。',
    '- 样例值必须来自真实携程响应，不允许编造；未抓到真实响应前统一写“需用真实响应补”。',
    '- 点评页只允许进入评分和好评/差评聚合计数，不保存点评明文、住客姓名、手机号等隐私内容。',
    '',
    '| 中文名 | 口径 | 时间口径 | 类型 | 单位 | 来源网页 | 来源接口/源字段名 | 转换规则 | 缺失状态 | 样例值 | 可信状态 |',
    '|---|---|---|---|---|---|---|---|---|---|---|',
    ...CTRIP_CORE_METRIC_LEARNING_ROWS.map((item) => `| ${markdownCell(item.label)} | ${markdownCell(item.scope)} | ${markdownCell(item.timeScope)} | ${markdownCell(item.valueType)} | ${markdownCell(item.unit)} | ${markdownCell(item.sourcePage)} | ${markdownCodeCell(item.sourceField)} | ${markdownCell(item.transformRule)} | ${markdownCodeCell(item.missingStatus)} | ${markdownCell(item.sampleValue)} | ${markdownCell(item.confidenceStatus)} |`),
    '',
    '### 缺失状态枚举',
    '',
    '| 状态值 | 含义 |',
    '|---|---|',
    ...CTRIP_FIELD_MISSING_STATUS_VALUES.map(([status, meaning]) => `| \`${status}\` | ${meaning} |`),
    '',
    '### 真实样例值结构',
    '',
    '```json',
    '{',
    '  "raw_value": "原始接口值",',
    '  "parsed_value": "宿析OS解析后的值",',
    '  "captured_at": "采集时间",',
    '  "store_id": "门店ID"',
    '}',
    '```',
    '',
    '## 汇总',
    '',
    `- 模块数：${Object.keys(CTRIP_CAPTURE_SECTIONS).length}`,
    `- 接口规则数：${CTRIP_CAPTURE_ENDPOINTS.length}`,
    `- 去重字段数：${ctripCatalogSummary().field_count}`,
    `- 页面交互计划：${ctripCatalogSummary().interaction_plan_section_count} 个模块 / ${ctripCatalogSummary().interaction_plan_step_count} 个触发动作`,
    '- 点评明文采集：默认禁用；Profile 仅保留评分汇总、回复率、点评条数和好评/差评聚合等非点评明文指标。',
    '',
  ];

  lines.push('## 未归档接口候选');

  lines.push('');
  lines.push('| 候选方向 | 优先级 | 数据类型 | 触发关键词 | 入目录条件 |');
  lines.push('|---|---|---|---|---|');
  for (const rule of CTRIP_ENDPOINT_CANDIDATE_RULES) {
    lines.push(`| ${rule.label} | ${rule.priority} | ${rule.dataType} | ${rule.keywords.slice(0, 8).join(', ')} | 必须补齐 Request URL、Payload、Preview / Response 后才能转为正式字段 |`);
  }
  lines.push('');
  lines.push('## 真实采集结果审计');
  lines.push('');
  lines.push('- 使用方式：`npm run audit:ctrip-capture -- --input=<ctrip_browser_capture_output.json>`。');
  lines.push('- 门禁方式：`npm run audit:ctrip-capture -- --input=<ctrip_browser_capture_output.json> --fail-on-gate`。');
  lines.push('- 字段覆盖门禁：`--min-field-coverage-rate=<0-100>`、`--max-missing-fields=<n>`、`--require-field-coverage`，用于防止接口命中但核心字段缺失的采集被误判为成功。');
  lines.push('- `Capture Gate` 会把登录页、空业务响应、无标准行、正式接口缺失标记为失败，不能作为宿析OS数据分析基石。');
  lines.push('- 输出文件：`reports/ctrip_capture_audit.json` 和 `docs/ctrip_capture_audit.md`。');
  lines.push('- 审计脚本只汇总已归档字段、标准行和未归档接口候选；不会把候选接口自动升级为正式字段。');
  lines.push('- 候选接口进入字段目录前，仍必须补齐 Request URL、Payload、Preview / Response、页面上下文和酒店/日期参数。');
  lines.push('- Profile 抓取会对命中 P3 关键词的未归档接口生成脱敏 `p3_evidence_drafts`；完整状态为 `complete_redacted` 时也只代表可进入人工映射审核，不会自动启用入库字段。');
  lines.push('- 审计报告会展示 `P3 证据草稿覆盖`，按候选方向汇总 `ready_for_review`、`incomplete_evidence` 和 `missing_evidence`。');
  lines.push('');
  lines.push('## DevTools 证据包校验');
  lines.push('');
  lines.push('- 证据模板：`npm run generate:ctrip-evidence-templates` 会输出 `docs/ctrip_endpoint_evidence_templates.md` 和 `reports/ctrip_endpoint_evidence_templates.json`。');
  lines.push('- 使用方式：`npm run validate:ctrip-endpoint-evidence -- --input=<endpoint_evidence.json>`。');
  lines.push('- 批量方式：重复传入多个 `--input=<endpoint_evidence.json>` 会输出 P3 证据覆盖矩阵。');
  lines.push('- 输入内容：Request URL、method、headers、Payload、Preview / Response、page_context、hotel/date params。');
  lines.push('- 输出文件：`reports/ctrip_endpoint_evidence.json` 和 `docs/ctrip_endpoint_evidence.md`。');
  lines.push('- 校验结果为 `complete_redacted` 才能进入字段映射；缺少任一证据时只能保留为 P3 候选。');
  lines.push('- 完整证据包会生成 `field_mapping_draft`，用于人工确认 source path、标准字段名、入库列和隐私处理方式。');
  lines.push('- `field_mapping_draft.safe_to_auto_apply` 固定为 `false`；正式写入字段目录前必须人工复核。');
  lines.push('- 草案转候选映射：`npm run promote:ctrip-mapping-draft -- --input=<ctrip_endpoint_evidence.json> --output=<approved_mapping.candidate.json>`。');
  lines.push('- Profile 批量候选映射：`npm run promote:ctrip-mapping-draft -- --input=<ctrip_browser_capture_output.json> --output=<approved_mapping.candidate.json>` 会从 `complete_redacted` 的 `p3_evidence_drafts` 生成待审核映射。');
  lines.push('- 转换结果固定为 `review_required` / `approved: false`，只用于人工审核，不会被自动采集流程启用。');
  lines.push('- 输出会移除 Cookie、Authorization、Token、密码、签名等敏感字段，并对订单住客信息做 hash 或掩码。');
  lines.push('');
  lines.push('## 已审核 P3 映射采集');
  lines.push('');
  lines.push('- 模板文件：`docs/ctrip_approved_mapping.example.json`。');
  lines.push('- 使用方式：`node scripts/ctrip_browser_capture.mjs ... --approved-mappings=<approved_mapping.json>`。');
  lines.push('- 后端入口：手动采集请求或自动 Profile 配置可传 `approved_mappings_path` / `approved_mapping_path` / `p3_mappings_path`，文件必须位于项目目录内且为 JSON。');
  lines.push('- 离线验证：`npm run dry-run:ctrip-approved-mapping -- --evidence=<endpoint_evidence.json> --mapping=<approved_mapping.json>`。');
  lines.push('- 只有 `approved: true` 的映射会参与 P3 响应提取；未审核草案不会自动生效。');
  lines.push('- 候选文件转正式规则前，必须人工确认 mapping 和字段级 `approved`，确认 source path、隐私处理和入库列后再启用。');
  lines.push('- 提取结果写入 `standard_rows`，隐私字段只保留 hash 或掩码，不保存订单号、住客姓名、手机号明文。');
  lines.push('');

  if (i18nReference) {
    lines.push('## 字段命名参考');
    lines.push('');
    lines.push(`- 来源：${i18nReference.source || 'i18n_translations.json'}`);
    lines.push(`- 模块数：${i18nReference.total_modules ?? '-'}`);
    lines.push(`- 词条数：${i18nReference.total_entries ?? '-'}`);
    lines.push(`- 已命中核心词：${(i18nReference.matched_terms || []).join('、') || '-'}`);
    lines.push('- 使用方式：i18n 只作为命名和页面语义参考；中文名优先采用语言包和页面展示中的既有术语，正式字段仍以接口证据、source path 和可复现上下文为准。');
    lines.push('- 数据边界：翻译包本身不是业务数据；功能、按钮、指标、提示语、节假日、国家地区和前端埋点上报代码不能直接生成经营指标。');
    lines.push('- 边界：无法确认时保留接口字段名和来源路径，不自行改写为未验证口径；竞争圈、商旅、广告和 OTA 零售渠道必须分开表达。');
    if (Array.isArray(i18nReference.metric_definitions) && i18nReference.metric_definitions.length > 0) {
      lines.push('');
      lines.push('### 指标口径速查');
      lines.push('');
      lines.push('| 术语 | 语言包口径/说明 | 来源Key |');
      lines.push('|---|---|---|');
      for (const item of i18nReference.metric_definitions) {
        lines.push(`| ${markdownCell(item.term)} | ${markdownCell(item.definition)} | ${markdownCell(item.source_key)} |`);
      }
    }
    lines.push('');
  }

  lines.push('## 模块');
  lines.push('');
  lines.push('| 模块ID | 模块 | 数据类型 | 导航地址口径 |');
  lines.push('|---|---|---|---|');
  for (const [id, config] of Object.entries(CTRIP_CAPTURE_SECTIONS)) {
    const confidences = [...new Set((config.pageUrls || []).map((item) => item.confidence))].join(', ') || '-';
    lines.push(`| ${id} | ${config.label} | ${config.dataType} | ${confidences} |`);
  }

  lines.push('');
  lines.push('## 接口与字段');
  lines.push('');
  for (const endpointInfo of CTRIP_CAPTURE_ENDPOINTS) {
    lines.push(`### ${endpointInfo.id}`);
    lines.push('');
    lines.push(`- 模块：${sectionLabel(endpointInfo.section)}`);
    lines.push(`- 数据类型：${endpointInfo.dataType}`);
    lines.push(`- URL关键词：${endpointInfo.keywords.join(' / ')}`);
    if (endpointInfo.notes) {
      lines.push(`- 备注：${endpointInfo.notes}`);
    }
    lines.push('');
    lines.push('| 标准字段 | 中文名 | 来源字段 | 说明 |');
    lines.push('|---|---|---|---|');
    for (const item of endpointInfo.fields) {
      lines.push(`| ${item.id} | ${item.label} | ${item.sourceKeys.join(', ')} | ${item.description || '-'} |`);
    }
    lines.push('');
  }

  return lines.join('\n');
}

function markdownCell(value) {
  if (value === undefined || value === null || value === '') {
    return '-';
  }
  return String(value).replace(/\|/g, '\\|').replace(/\r?\n/g, '<br>');
}

function markdownCodeCell(value) {
  const text = markdownCell(value);
  return text === '-' ? text : `\`${text.replace(/`/g, '\\`')}\``;
}

function isScalar(value) {
  return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function normalizeFactValue(value) {
  if (typeof value !== 'string') {
    return value;
  }
  const trimmed = value.trim();
  return trimmed.length > 500 ? `${trimmed.slice(0, 500)}...` : trimmed;
}

function normalizeCtripCapturePlatform(value) {
  const key = String(value || '').trim().toLowerCase();
  if (key === 'qunar' || key === 'qunaer' || key.includes('去哪')) {
    return 'Qunar';
  }
  return 'Ctrip';
}

function sourceForCtripCapturePlatform(value) {
  return normalizeCtripCapturePlatform(value) === 'Qunar' ? 'qunar' : 'ctrip';
}

function extractMetricPairFacts(node, path, fields, context) {
  if (!isMetricPairObject(node)) {
    return [];
  }

  const valueKey = metricPairValueKeys()
    .find((key) => Object.prototype.hasOwnProperty.call(node, key) && isMeaningfulScalar(node[key]));
  if (!valueKey) {
    return [];
  }

  const metricText = ['key', 'name', 'metric', 'title', 'label', 'remark', 'indexName', 'rankName']
    .map((key) => String(node[key] ?? '').trim())
    .filter(Boolean)
    .join(' ');
  const fieldInfo = metricPairField(fields, metricText);
  if (!fieldInfo) {
    return [];
  }

  const result = [];
  const resolvedValue = resolveMetricPairPrimaryValue(node, path, valueKey, fieldInfo);
  if (resolvedValue) {
    result.push({
      platform: normalizeCtripCapturePlatform(context.platform),
      section: context.section || context.endpoint?.section || '',
      endpoint_id: context.endpoint?.id || '',
      endpoint_label: context.endpoint?.label || '',
      data_type: context.endpoint?.dataType || context.dataType || '',
      metric_key: fieldInfo.id,
      metric_label: fieldInfo.label,
      metric_scope: fieldInfo.scope,
      unit: fieldInfo.unit,
      source_key: String(node.key || node.name || resolvedValue.sourceKey),
      source_path: resolvedValue.sourcePath,
      source_parent_path: metricPairGroupPath(node, path),
      value: normalizeFactValue(resolvedValue.value),
      value_type: resolvedValue.valueType,
      hotel_id: context.hotelId || '',
      data_date: context.dataDate || '',
      captured_at: context.capturedAt || '',
      source_url: context.url || '',
      metric_pair: true,
      metric_pair_label: metricText,
      ...resolvedValue.meta,
    });
  }

  const comparisonFields = [
    ['competitor_average', ['competitorAvg', 'competitorAverage', 'competeAvg', 'competeAverage', 'peerAvg', 'peerAverage', 'avgValue', 'averageValue', 'circleAvg']],
    ['rank', ['rank', 'rank2', 'ranking', 'rankValue', 'rankNo']],
  ];
  for (const [fieldId, keys] of comparisonFields) {
    const comparisonField = fields.find((item) => item.id === fieldId);
    if (!comparisonField) {
      continue;
    }
    const comparisonKey = keys.find((key) => Object.prototype.hasOwnProperty.call(node, key) && isScalar(node[key]));
    if (!comparisonKey) {
      continue;
    }
    result.push({
      platform: normalizeCtripCapturePlatform(context.platform),
      section: context.section || context.endpoint?.section || '',
      endpoint_id: context.endpoint?.id || '',
      endpoint_label: context.endpoint?.label || '',
      data_type: context.endpoint?.dataType || context.dataType || '',
      metric_key: comparisonField.id,
      metric_label: comparisonField.label,
      metric_scope: comparisonField.scope,
      unit: comparisonField.unit,
      source_key: comparisonKey,
      source_path: [...path, comparisonKey].join('.'),
      source_parent_path: metricPairGroupPath(node, path),
      value: normalizeFactValue(node[comparisonKey]),
      value_type: typeof node[comparisonKey],
      hotel_id: context.hotelId || '',
      data_date: context.dataDate || '',
      captured_at: context.capturedAt || '',
      source_url: context.url || '',
      metric_pair: true,
      metric_pair_label: metricText,
    });
  }

  return result;
}

function isMetricPairObject(node) {
  return Boolean(
    node
    && typeof node === 'object'
    && !Array.isArray(node)
    && ['key', 'name', 'metric', 'title', 'label', 'remark', 'indexName', 'rankName'].some((key) => Object.prototype.hasOwnProperty.call(node, key))
    && metricPairValueKeys().some((key) => Object.prototype.hasOwnProperty.call(node, key) && isMeaningfulScalar(node[key])),
  );
}

function isMeaningfulScalar(value) {
  return isScalar(value) && value !== null && value !== '';
}

function metricPairValueKeys() {
  return ['value', 'realValue', 'num', 'count', 'score', 'rate', 'myValue', 'yourValue', 'hotelValue', 'selfValue', 'currentValue', 'ownValue', 'percent'];
}

function resolveMetricPairPrimaryValue(node, path, valueKey, fieldInfo) {
  if (valueKey !== 'percent' || isPercentMetricField(fieldInfo)) {
    return {
      sourceKey: valueKey,
      sourcePath: [...path, valueKey].join('.'),
      value: node[valueKey],
      valueType: typeof node[valueKey],
      meta: {},
    };
  }

  const percent = normalizePercentNumber(numericFactValue(node[valueKey]));
  const denominator = metricPairPercentDenominator(node);
  if (percent === null || !denominator || denominator.value <= 0) {
    return null;
  }

  return {
    sourceKey: valueKey,
    sourcePath: [...path, valueKey].join('.'),
    value: Math.round(denominator.value * percent) / 100,
    valueType: 'number',
    meta: {
      derived_from: 'percent_of_total',
      derived_percent: percent,
      derived_total: denominator.value,
      denominator_source_key: denominator.key,
      denominator_source_path: [...path, denominator.key].join('.'),
    },
  };
}

function isPercentMetricField(fieldInfo) {
  const id = String(fieldInfo?.id || '').toLowerCase();
  const unit = String(fieldInfo?.unit || '').trim();
  return unit === '%'
    || /(rate|ratio|percent|share|proportion)$/.test(id)
    || /(rate|ratio|percent|share|proportion)/.test(id);
}

function metricPairPercentDenominator(node) {
  for (const key of [
    'total',
    'totalValue',
    'totalNum',
    'totalCount',
    'totalAmount',
    'sum',
    'sumValue',
    'base',
    'baseValue',
    'baseCount',
    'sampleSize',
    'sampleCount',
    'all',
    'allValue',
    'allCount',
    'overall',
    'overallValue',
    'overallCount',
  ]) {
    if (!Object.prototype.hasOwnProperty.call(node, key)) {
      continue;
    }
    const value = numericFactValue(node[key]);
    if (value !== null && Number.isFinite(value)) {
      return { key, value };
    }
  }
  return null;
}

function normalizePercentNumber(number) {
  if (number === null || !Number.isFinite(number) || number < 0) {
    return null;
  }
  return number > 0 && number <= 1 ? Math.round(number * 10000) / 100 : Math.round(number * 100) / 100;
}

function metricPairField(fields, metricText) {
  const text = normalizeMetricText(metricText);
  if (!text) {
    return null;
  }

  const patterns = [
    ['order_amount', /orderamount|saleamount|ordamount|销售额|订单金额|预订金额|营业额|收益/],
    ['order_count', /orderquantity|bookordernum|ordercount|ordquantity|预订订单|订单数|订单量|订单/],
    ['room_nights', /occupiedrooms|roomnight|nightnum|间夜|房晚|在店间夜/],
    ['avg_price', /minprice|averageprice|avgprice|adr|起价|均价|平均卖价/],
    ['page_views', /pvdata|pageview|浏览量/],
    ['visitor_count', /^uv$|访客量|访客|访问量|visitor/],
    ['list_exposure', /曝光|listexposure|impression|showcount/],
    ['detail_visitor', /详情页访客|详情页浏览|detailuv|detailvisitor/],
    ['order_page_visitor', /订单页访客|填写页|orderfilling|ordervisitor/],
    ['order_submit_user', /订单提交|提交人数|submituser|submitnum/],
    ['order_fill_rate', /下单转化|订单页访客转化|orderfillrate|orderconversionrate/],
    ['deal_rate', /成交转化|成交率|submitrate|dealrate/],
    ['flow_rate', /曝光转化|列表页曝光.*转化|流量转化|flowrate|transfor|transfer|convertrate/],
    ['conversion_rate', /conversion|cvr/],
    ['tensity', /tensity|紧张度/],
    ['visitor_rank', /访客排名|visitorrank/],
    ['competitor_avg_visitor', /竞争圈平均访客|竞圈平均访客|competitoravgnumber/],
    ['qunar_visitor_rank', /去哪儿访客排名|qunarvisitorkrank|qunarvisitorrank|qunarcompetitorrank/],
    ['qunar_competitor_avg_visitor', /去哪儿竞争圈平均访客|qunarcompetitoravgnumber/],
    ['rank', /rank|排名/],
    ['competitor_average', /竞争圈平均|竞圈平均|同行平均|平均/],
    ['comment_score_summary', /hotelrating|rating|点评分|评分/],
    ['ctrip_rating', /携程评分|ctripratingall/],
    ['psi_score', /psi|服务质量分|servicescore/],
    ['service_score_rank', /服务质量排名|servicescorerank/],
    ['reply_rate', /回复率|replyrate/],
    ['reply_rank', /回复排名|imscorehtlrank|replyrate5mrank/],
    ['hotel_collect', /酒店收藏|hotelcollect/],
    ['hotel_collect_rank', /收藏排名|hotelcollectrank/],
    ['bpi_score', /bpi|总分/],
    ['ad_impressions', /广告曝光|曝光/],
    ['ad_clicks', /广告点击|点击/],
    ['ad_cost', /花费|成本|cost|spend/],
    ['ad_orders', /广告.*订单|预订订单/],
    ['ad_room_nights', /广告.*间夜|间夜/],
    ['roas', /roas|投产比|roi/],
  ];

  for (const [fieldId, pattern] of patterns) {
    if (pattern.test(text)) {
      const fieldInfo = fields.find((item) => item.id === fieldId);
      if (fieldInfo) {
        return fieldInfo;
      }
    }
  }

  for (const fieldInfo of fields) {
    const fieldTokens = [fieldInfo.id, fieldInfo.label, ...(fieldInfo.sourceKeys || [])]
      .map(normalizeMetricText)
      .filter(Boolean);
    if (fieldTokens.some((token) => token && text.includes(token))) {
      return fieldInfo;
    }
  }
  return null;
}

function normalizeMetricText(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '');
}

function metricPairGroupPath(node, path) {
  const comparisonCard = [
    'myValue',
    'yourValue',
    'hotelValue',
    'selfValue',
    'currentValue',
    'ownValue',
    'competitorAvg',
    'competitorAverage',
    'competeAvg',
    'peerAvg',
    'avgValue',
    'averageValue',
  ].some((key) => Object.prototype.hasOwnProperty.call(node, key));
  if (comparisonCard) {
    return path.join('.');
  }
  const hasOwnDate = ['date', 'dataDate', 'statDate', 'startDate', 'endDate']
    .some((key) => Object.prototype.hasOwnProperty.call(node, key));
  return hasOwnDate ? path.join('.') : '__endpoint__';
}

function standardRowGroupKey(fact) {
  return [
    fact.source_url || '',
    fact.endpoint_id || '',
    fact.section || '',
    fact.platform || '',
    fact.data_date || '',
    fact.hotel_id || '',
    fact.source_parent_path || parentPath(fact.source_path || ''),
  ].join('|');
}

function buildStandardRow(facts, context) {
  const first = facts[0] || {};
  const dataDate = normalizeFactDate(first.data_date || context.dataDate || context.defaultDataDate || '');
  if (!dataDate) {
    return null;
  }

  const platform = normalizeCtripCapturePlatform(first.platform || context.platform);
  const contextHotelId = String(
    context.hotelId
      || context.masterHotelId
      || context.master_hotel_id
      || context.requestHotelId
      || context.otaHotelId
      || context.ota_hotel_id
      || context.ctripHotelId
      || context.ctrip_hotel_id
      || ''
  ).trim();
  const row = {
    hotel_id: String(first.hotel_id || contextHotelId || '').trim(),
    hotel_name: String(context.hotelName || '').trim(),
    system_hotel_id: context.systemHotelId ? Number(context.systemHotelId) : null,
    data_date: dataDate,
    source: sourceForCtripCapturePlatform(platform),
    platform,
    data_type: standardDataTypeForFacts(facts),
    dimension: standardDimension(first, facts),
    amount: 0,
    quantity: 0,
    book_order_num: 0,
    comment_score: 0,
    qunar_comment_score: 0,
    data_value: 0,
    compare_type: compareTypeForFacts(facts, context),
    list_exposure: 0,
    detail_exposure: 0,
    flow_rate: 0,
    order_filling_num: 0,
    order_submit_num: 0,
    ingestion_method: 'browser_profile',
    capture_section: first.section || '',
    endpoint_id: first.endpoint_id || '',
    raw_data: {
      source: 'ctrip_catalog_facts',
      endpoint_id: first.endpoint_id || '',
      endpoint_label: first.endpoint_label || '',
      section: first.section || '',
      section_label: sectionLabel(first.section || ''),
      source_url: first.source_url || '',
      captured_at: first.captured_at || context.capturedAt || '',
      facts: facts.map((fact) => ({
        metric_key: fact.metric_key,
        metric_label: fact.metric_label,
        value: fact.value,
        source_key: fact.source_key,
        source_path: fact.source_path,
        ...ctripStandardFactStorage(fact),
      })),
    },
  };

  for (const fact of facts) {
    applyFactToStandardRow(row, fact);
  }
  finalizeTrafficStandardRow(row);

  if (!row.hotel_id) {
    row.hotel_id = contextHotelId;
  }
  if (!row.hotel_name) {
    row.hotel_name = String(row.raw_data.metric_hotel_name || context.hotelName || '').trim();
  }

  if (hasStandardMetricValue(row)) {
    return row;
  }
  if (hasCtripRankingValue(facts)) {
    row.raw_data.fact_only = true;
    row.raw_data.metric_status = 'rank_fact';
    return row;
  }
  if (hasFactOnlyValue(facts)) {
    row.raw_data.fact_only = true;
    row.raw_data.metric_status = 'non_numeric_fact';
    return row;
  }
  return null;
}

function ctripStandardFactStorage(fact) {
  const id = String(fact?.metric_key || '').trim();
  if (!id) {
    return {
      storage_field: '',
      storage_field_source: '',
    };
  }

  const structuredField = ctripStandardStructuredStorageField(id);
  if (structuredField) {
    return {
      storage_field: `online_daily_data.${structuredField}`,
      storage_field_source: 'standard_row_column',
    };
  }

  if (isCtripRankingFact(fact)) {
    return {
      storage_field: `online_daily_data.raw_data.rank_metrics.${id}`,
      storage_field_source: 'raw_data_rank_metrics',
    };
  }

  return {
    storage_field: `online_daily_data.raw_data.facts.metric_key=${id}`,
    storage_field_source: 'raw_data_facts',
  };
}

function ctripStandardStructuredStorageField(id) {
  return {
    hotel_id: 'hotel_id',
    hotel_name: 'hotel_name',
    comment_store_name: 'hotel_name',
    date: 'data_date',
    start_date: 'data_date',
    comment_date: 'data_date',
    order_amount: 'amount',
    business_amount: 'amount',
    loss_order_amount: 'amount',
    ad_cost: 'amount',
    room_nights: 'quantity',
    business_room_nights: 'quantity',
    loss_room_nights: 'quantity',
    ad_room_nights: 'quantity',
    occupied_rooms: 'quantity',
    order_count: 'book_order_num',
    loss_order_count: 'book_order_num',
    ad_orders: 'book_order_num',
    visitor_count: 'detail_exposure',
    detail_visitor: 'detail_exposure',
    competitor_detail_visitor: 'detail_exposure',
    qunar_detail_visitor: 'detail_exposure',
    qunar_competitor_detail_visitor: 'detail_exposure',
    list_exposure: 'list_exposure',
    competitor_list_exposure: 'list_exposure',
    qunar_list_exposure: 'list_exposure',
    qunar_competitor_list_exposure: 'list_exposure',
    ad_impressions: 'list_exposure',
    order_page_visitor: 'order_filling_num',
    competitor_order_page_visitor: 'order_filling_num',
    qunar_order_page_visitor: 'order_filling_num',
    qunar_competitor_order_page_visitor: 'order_filling_num',
    order_submit_user: 'order_submit_num',
    competitor_order_submit_user: 'order_submit_num',
    qunar_order_submit_user: 'order_submit_num',
    qunar_competitor_order_submit_user: 'order_submit_num',
    flow_rate: 'flow_rate',
    competitor_flow_rate: 'flow_rate',
    qunar_flow_rate: 'flow_rate',
    qunar_competitor_flow_rate: 'flow_rate',
    conversion_rate: 'flow_rate',
    order_conversion_rate: 'flow_rate',
    common_view_rate: 'flow_rate',
    ctr: 'flow_rate',
    cvr: 'flow_rate',
    reply_rate: 'flow_rate',
    five_min_reply_rate: 'flow_rate',
    manual_reply_rate: 'flow_rate',
    im_order_conversion_rate: 'flow_rate',
    agreement_accept_rate: 'flow_rate',
    business_commission_rate: 'flow_rate',
    comment_response_rate: 'flow_rate',
    comment_score_summary: 'comment_score',
    comment_score: 'comment_score',
    ctrip_rating: 'comment_score',
    qunar_rating: 'qunar_comment_score',
    avg_price: 'data_value',
    close_rate: 'data_value',
    occupancy_rate: 'data_value',
    tensity: 'data_value',
    comment_count: 'data_value',
    bad_review_count: 'data_value',
    comment_unreply_count: 'data_value',
    ctrip_comment_count: 'data_value',
    qunar_comment_count: 'data_value',
    elong_comment_count: 'data_value',
    zx_comment_count: 'data_value',
    avg_user_age: 'data_value',
    avg_booking_days: 'data_value',
    avg_stay_days: 'data_value',
    ad_order_amount: 'data_value',
  }[id] || '';
}

function standardDataTypeForFacts(facts) {
  const ids = facts.map((fact) => String(fact.metric_key || ''));
  const endpointId = String(facts[0]?.endpoint_id || '');
  const declaredType = String(facts[0]?.data_type || '').trim().toLowerCase();
  if (CTRIP_RANKING_ENDPOINT_IDS.has(endpointId)) {
    return 'ranking';
  }
  if (ids.some((id) => id.startsWith('ad_') || ['ctr', 'cvr', 'roas', 'campaign_id', 'diagnosis_text', 'peer_avg', 'peer_top'].includes(id))) {
    return 'advertising';
  }
  if (ids.some((id) => [
    'order_amount', 'business_amount', 'loss_order_amount', 'order_count', 'loss_order_count',
    'room_nights', 'business_room_nights', 'loss_room_nights', 'avg_price', 'tensity', 'occupancy_rate',
    'order_amount_last_week', 'room_nights_last_week', 'avg_price_last_week', 'close_rate_last_week',
    'amount_rank', 'quantity_rank', 'avg_price_rank', 'close_rate_rank',
    'occupied_rooms', 'occupied_rooms_sync', 'occupied_rooms_rank', 'competitor_avg_occupied_rooms',
    'occupancy_rate_sync', 'occupancy_rate_rank', 'order_count_sync', 'order_count_rank', 'competitor_avg_orders',
    'ctrip_order_count', 'ctrip_order_count_sync', 'ctrip_order_count_rank',
    'qunar_order_count', 'qunar_order_count_sync', 'qunar_order_count_rank',
    'elong_order_count', 'elong_order_count_sync', 'elong_order_count_rank',
    'competitor_orders', 'competitor_revenue', 'competitor_number',
  ].includes(id))) {
    return 'business';
  }
  if (ids.some((id) => [
    'psi_score', 'service_score', 'service_score_rank', 'base_score', 'reward_score', 'deduct_score',
    'reply_rate', 'reply_rank', 'im_score', 'hotel_collect', 'hotel_collect_rank', 'ctrip_rating',
    'comment_store_name', 'comment_date', 'comment_channel', 'comment_score', 'comment_count', 'bad_review_count',
    'ctrip_comment_count', 'qunar_comment_count', 'elong_comment_count', 'zx_comment_count', 'comment_score_summary',
    'ctrip_rating', 'qunar_rating', 'elong_rating',
    'ctrip_rating_rank', 'qunar_rating_rank', 'comment_response_rate', 'rating_competitor_total',
    'comment_unreply_count', 'comment_good_rate',
    'review_environment_score', 'review_facility_score', 'review_service_score', 'review_cleanliness_score',
    'review_photo_count', 'review_photo_rate',
    'five_min_reply_rate', 'manual_reply_rate', 'robot_resolution_rate', 'im_rank',
    'session_count', 'manual_session_count', 'robot_session_count', 'im_order_conversion_rate',
    'bpi_score', 'basis_score', 'plus_score', 'minus_score',
  ].includes(id))) {
    return 'quality';
  }
  if (ids.some((id) => [
    'page_views', 'visitor_count', 'list_exposure', 'detail_visitor', 'order_page_visitor', 'order_submit_user',
    'flow_rate', 'competitor_flow_rate', 'order_fill_rate', 'competitor_order_fill_rate', 'deal_rate', 'competitor_deal_rate',
    'qunar_list_exposure', 'qunar_competitor_list_exposure', 'qunar_detail_visitor', 'qunar_competitor_detail_visitor',
    'qunar_flow_rate', 'qunar_competitor_flow_rate', 'qunar_order_page_visitor', 'qunar_competitor_order_page_visitor',
    'qunar_order_fill_rate', 'qunar_competitor_order_fill_rate', 'qunar_order_submit_user', 'qunar_competitor_order_submit_user',
    'qunar_deal_rate', 'qunar_competitor_deal_rate',
    'visitor_rank', 'visitor_count_last_week', 'competitor_avg_visitor',
    'qunar_visitor_count', 'qunar_visitor_rank', 'qunar_visitor_count_last_week', 'qunar_competitor_avg_visitor',
    'competitor_visitor', 'source_name', 'source_rank_tag', 'source_proportion', 'competitor_avg_source_proportion',
    'source_pv', 'source_all_pv', 'keyword', 'traffic_rank',
  ].includes(id))) {
    return 'traffic';
  }
  if (declaredType === 'advertising') {
    return 'advertising';
  }
  return declaredType || 'business';
}

function standardDataTypeForField(fieldId) {
  if ([
    'order_amount', 'order_amount_last_week', 'amount_rank',
    'room_nights', 'room_nights_last_week', 'quantity_rank',
    'occupied_rooms', 'occupied_rooms_sync', 'occupied_rooms_rank', 'competitor_avg_occupied_rooms',
    'avg_price', 'avg_price_last_week', 'avg_price_rank',
    'close_rate', 'close_rate_last_week', 'close_rate_rank',
    'order_count', 'order_count_sync', 'order_count_rank', 'competitor_avg_orders',
    'ctrip_order_count', 'ctrip_order_count_sync', 'ctrip_order_count_rank',
    'qunar_order_count', 'qunar_order_count_sync', 'qunar_order_count_rank',
    'elong_order_count', 'elong_order_count_sync', 'elong_order_count_rank',
    'occupancy_rate', 'occupancy_rate_sync', 'occupancy_rate_rank', 'tensity',
  ].includes(fieldId)) {
    return 'business';
  }
  if ([
    'page_views', 'visitor_count', 'list_exposure', 'detail_visitor', 'order_page_visitor', 'order_submit_user',
    'flow_rate', 'competitor_flow_rate', 'order_fill_rate', 'competitor_order_fill_rate', 'deal_rate', 'competitor_deal_rate',
    'qunar_list_exposure', 'qunar_competitor_list_exposure', 'qunar_detail_visitor', 'qunar_competitor_detail_visitor',
    'qunar_flow_rate', 'qunar_competitor_flow_rate', 'qunar_order_page_visitor', 'qunar_competitor_order_page_visitor',
    'qunar_order_fill_rate', 'qunar_competitor_order_fill_rate', 'qunar_order_submit_user', 'qunar_competitor_order_submit_user',
    'qunar_deal_rate', 'qunar_competitor_deal_rate',
    'visitor_rank', 'visitor_count_last_week', 'competitor_avg_visitor',
    'qunar_visitor_count', 'qunar_visitor_rank', 'qunar_visitor_count_last_week', 'qunar_competitor_avg_visitor',
    'competitor_visitor', 'source_name', 'source_rank_tag', 'source_proportion', 'competitor_avg_source_proportion',
    'source_pv', 'source_all_pv', 'keyword', 'traffic_rank',
  ].includes(fieldId)) {
    return 'traffic';
  }
  if ([
    'psi_score', 'service_score', 'service_score_rank', 'base_score', 'reward_score', 'deduct_score',
    'reply_rate', 'reply_rank', 'im_score', 'hotel_collect', 'hotel_collect_rank', 'ctrip_rating',
    'comment_store_name', 'comment_date', 'comment_channel', 'comment_score', 'comment_count', 'bad_review_count',
    'ctrip_comment_count', 'qunar_comment_count', 'elong_comment_count', 'zx_comment_count', 'comment_score_summary',
    'ctrip_rating', 'qunar_rating', 'elong_rating',
    'ctrip_rating_rank', 'qunar_rating_rank', 'comment_response_rate', 'rating_competitor_total',
    'comment_unreply_count', 'comment_good_rate',
    'review_environment_score', 'review_facility_score', 'review_service_score', 'review_cleanliness_score',
    'review_photo_count', 'review_photo_rate',
    'five_min_reply_rate', 'manual_reply_rate', 'robot_resolution_rate', 'im_rank',
    'session_count', 'manual_session_count', 'robot_session_count', 'im_order_conversion_rate',
    'bpi_score', 'basis_score', 'plus_score', 'minus_score',
  ].includes(fieldId)) {
    return 'quality';
  }
  if (fieldId.startsWith('ad_') || ['ctr', 'cvr', 'roas', 'campaign_id', 'diagnosis_text', 'peer_avg', 'peer_top'].includes(fieldId)) {
    return 'advertising';
  }
  return '';
}

function applyFactToStandardRow(row, fact) {
  const id = String(fact.metric_key || '');
  const number = numericFactValue(fact.value);
  const rawMetrics = row.raw_data.metrics ||= {};

  if (id === 'hotel_id') {
    const nextPriority = ctripHotelIdSourcePriority(fact.source_key);
    const currentPriority = Number(row.raw_data.hotel_id_source_priority ?? Number.MAX_SAFE_INTEGER);
    if (!row.hotel_id || nextPriority <= currentPriority) {
      row.hotel_id = String(fact.value || row.hotel_id || '').trim();
      row.raw_data.hotel_id_source_key = fact.source_key || '';
      row.raw_data.hotel_id_source_priority = nextPriority;
      rawMetrics[id] = number === null ? fact.value : number;
    }
    return;
  }
  if (id === 'hotel_name' || id === 'comment_store_name') {
    row.hotel_name = String(fact.value || row.hotel_name || '').trim();
    row.raw_data.metric_hotel_name = row.hotel_name;
    return;
  }
  if (id === 'date' || id === 'start_date' || id === 'comment_date') {
    const date = normalizeFactDate(fact.value);
    if (date) {
      row.data_date = date;
    }
    return;
  }
  if (id === 'comment_channel') {
    appendDimensionValue(row, 'comment_channel', fact.value);
    return;
  }
  rawMetrics[id] = number === null ? fact.value : number;
  if (FACT_ONLY_FIELD_IDS.has(id)) {
    appendDimensionValue(row, id, fact.value);
    return;
  }
  if (isCtripRankingFact(fact)) {
    const rankMetrics = row.raw_data.rank_metrics ||= {};
    rankMetrics[id] = number === null ? fact.value : number;
    appendDimensionValue(row, id, fact.value);
    return;
  }
  if (number === null) {
    appendDimensionValue(row, id, fact.value);
    return;
  }

  switch (id) {
    case 'order_amount':
    case 'business_amount':
    case 'loss_order_amount':
      row.amount = number;
      break;
    case 'ad_cost':
      row.amount = number;
      break;
    case 'room_nights':
    case 'business_room_nights':
    case 'loss_room_nights':
    case 'ad_room_nights':
    case 'occupied_rooms':
      row.quantity = Math.round(number);
      break;
    case 'order_count':
    case 'loss_order_count':
    case 'ad_orders':
      row.book_order_num = Math.round(number);
      if (row.order_submit_num === 0) {
        row.order_submit_num = Math.round(number);
      }
      break;
    case 'visitor_count':
    case 'detail_visitor':
    case 'competitor_detail_visitor':
    case 'qunar_detail_visitor':
    case 'qunar_competitor_detail_visitor':
      row.detail_exposure = Math.round(number);
      if (row.data_value === 0) {
        row.data_value = Math.round(number);
      }
      break;
    case 'list_exposure':
    case 'competitor_list_exposure':
    case 'qunar_list_exposure':
    case 'qunar_competitor_list_exposure':
    case 'ad_impressions':
      row.list_exposure = Math.round(number);
      if (row.data_value === 0) {
        row.data_value = Math.round(number);
      }
      break;
    case 'order_page_visitor':
    case 'competitor_order_page_visitor':
    case 'qunar_order_page_visitor':
    case 'qunar_competitor_order_page_visitor':
      row.order_filling_num = Math.round(number);
      break;
    case 'order_submit_user':
    case 'competitor_order_submit_user':
    case 'qunar_order_submit_user':
    case 'qunar_competitor_order_submit_user':
      row.order_submit_num = Math.round(number);
      break;
    case 'ad_clicks':
      row.detail_exposure = Math.round(number);
      row.order_filling_num = Math.round(number);
      break;
    case 'flow_rate':
    case 'competitor_flow_rate':
    case 'qunar_flow_rate':
    case 'qunar_competitor_flow_rate':
    case 'conversion_rate':
    case 'order_conversion_rate':
    case 'common_view_rate':
    case 'ctr':
    case 'cvr':
    case 'reply_rate':
    case 'five_min_reply_rate':
    case 'manual_reply_rate':
    case 'im_order_conversion_rate':
    case 'agreement_accept_rate':
    case 'business_commission_rate':
      row.flow_rate = normalizeFactPercent(number);
      if (row.data_value === 0) {
        row.data_value = row.flow_rate;
      }
      break;
    case 'comment_score_summary':
    case 'comment_score':
      if (row.comment_score === 0) {
        row.comment_score = number;
      }
      if (row.data_value === 0) {
        row.data_value = number;
      }
      break;
    case 'ctrip_rating':
      row.comment_score = number;
      row.data_value = number;
      break;
    case 'qunar_rating':
      row.qunar_comment_score = number;
      break;
    case 'elong_rating':
      break;
    case 'comment_response_rate':
      row.flow_rate = normalizeCommentResponseRate(number);
      break;
    case 'comment_good_rate':
      rawMetrics[id] = normalizeFactPercent(number);
      if (row.data_value === 0) {
        row.data_value = rawMetrics[id];
      }
      break;
    case 'review_photo_rate':
      rawMetrics[id] = normalizeFactPercent(number);
      if (row.data_value === 0) {
        row.data_value = rawMetrics[id];
      }
      break;
    case 'comment_count':
      row.data_value = Math.round(number);
      break;
    case 'bad_review_count':
    case 'comment_unreply_count':
      if (row.data_value === 0) {
        row.data_value = Math.round(number);
      }
      break;
    case 'ctrip_comment_count':
    case 'qunar_comment_count':
    case 'elong_comment_count':
    case 'zx_comment_count':
      if (row.data_value === 0) {
        row.data_value = Math.round(number);
      }
      break;
    case 'ctrip_rating_rank':
    case 'qunar_rating_rank':
      {
        const rankMetrics = row.raw_data.rank_metrics ||= {};
        rankMetrics[id] = number === null ? fact.value : number;
      }
      break;
    case 'rating_competitor_total':
      break;
    case 'avg_user_age':
    case 'avg_booking_days':
    case 'avg_stay_days':
      row.data_value = number;
      break;
    case 'order_amount_last_week':
    case 'room_nights_last_week':
    case 'occupied_rooms_sync':
    case 'competitor_avg_occupied_rooms':
    case 'avg_price_last_week':
    case 'close_rate_last_week':
    case 'visitor_count_last_week':
    case 'competitor_avg_visitor':
    case 'qunar_visitor_count':
    case 'qunar_visitor_count_last_week':
    case 'qunar_competitor_avg_visitor':
    case 'occupancy_rate_sync':
    case 'order_count_sync':
    case 'competitor_avg_orders':
    case 'ctrip_order_count':
    case 'ctrip_order_count_sync':
    case 'qunar_order_count':
    case 'qunar_order_count_sync':
    case 'elong_order_count':
    case 'elong_order_count_sync':
      break;
    case 'amount_rank':
    case 'seq_rank':
    case 'quantity_rank':
    case 'occupied_rooms_rank':
    case 'avg_price_rank':
    case 'close_rate_rank':
    case 'visitor_rank':
    case 'qunar_visitor_rank':
    case 'occupancy_rate_rank':
    case 'order_count_rank':
    case 'ctrip_order_count_rank':
    case 'qunar_order_count_rank':
    case 'elong_order_count_rank':
    case 'service_score_rank':
    case 'reply_rank':
    case 'hotel_collect_rank':
      {
        const rankMetrics = row.raw_data.rank_metrics ||= {};
        rankMetrics[id] = number === null ? fact.value : number;
      }
      break;
    case 'ad_order_amount':
      if (row.data_value === 0) {
        row.data_value = number;
      }
      break;
    default:
      if (row.data_value === 0) {
        row.data_value = normalizeFactPercent(number);
      }
      break;
  }
}

function finalizeTrafficStandardRow(row) {
  if (row.data_type !== 'traffic') {
    return;
  }
  const listExposure = Number(row.list_exposure || 0);
  const detailExposure = Number(row.detail_exposure || 0);
  if (listExposure > 0 && detailExposure >= 0) {
    row.flow_rate = Number(((detailExposure / listExposure) * 100).toFixed(2));
    if (row.data_value === 0) {
      row.data_value = row.flow_rate;
    }
  }
}

function ctripFactValue(facts, metricKey) {
  const fact = facts.find((item) => String(item.metric_key || '') === metricKey);
  return fact ? String(fact.value || '').trim() : '';
}

function normalizeCtripHotelName(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[\s\-_.|()（）·]/g, '');
}

function isCtripGenericSelfHotelName(value) {
  const normalized = normalizeCtripHotelName(value);
  return normalized === 'myhotel'
    || normalized === 'currenthotel'
    || normalized === 'selfhotel'
    || normalized === '\u6211\u7684\u9152\u5e97'
    || normalized === '\u672c\u5e97'
    || normalized === '\u672c\u9152\u5e97';
}

function ctripHotelNameMatches(candidate, target) {
  const left = normalizeCtripHotelName(candidate);
  const right = normalizeCtripHotelName(target);
  if (!left || !right) {
    return false;
  }
  return left === right || (left.length >= 3 && right.includes(left)) || (right.length >= 3 && left.includes(right));
}

function compareTypeForFacts(facts, context = {}) {
  const endpointId = String(facts[0]?.endpoint_id || '');
  const hotelId = ctripFactValue(facts, 'hotel_id');
  if (String(hotelId).trim() === '-1') {
    return 'competitor';
  }
  const contextHotelIds = new Set([
    context.hotelId,
    context.requestHotelId,
    context.masterHotelId,
    context.master_hotel_id,
  ].map((value) => String(value || '').trim()).filter(Boolean));
  if (hotelId && contextHotelIds.has(String(hotelId).trim())) {
    return 'self';
  }
  if (endpointId === 'weekly_compete_report') {
    const hotelName = ctripFactValue(facts, 'hotel_name');
    if (isCtripGenericSelfHotelName(hotelName)
      || ctripHotelNameMatches(hotelName, context.hotelName)) {
      return 'self';
    }
    return 'competitor';
  }
  const text = facts.map((fact) => `${fact.metric_label || ''} ${fact.metric_pair_label || ''} ${fact.value || ''}`).join(' ');
  if (/竞争圈|竞圈|同行|peer|compet/i.test(text)) {
    return 'competitor';
  }
  return 'self';
}

function standardDimension(first, facts) {
  const section = first.section || 'unknown';
  const endpoint = first.endpoint_id || 'unknown';
  const groupPath = first.source_parent_path || parentPath(first.source_path || '');
  const metricIds = [...new Set(facts.map((fact) => fact.metric_key).filter(Boolean))].slice(0, 3).join('+');
  return `catalog:${section}:${endpoint}:${metricIds || 'fact'}:${safeDimensionPart(groupPath || 'root')}`;
}

function appendDimensionValue(row, key, value) {
  if (value === null || value === undefined || value === '') {
    return;
  }
  const values = row.raw_data.dimension_values ||= {};
  values[key] = value;
}

function hasStandardMetricValue(row) {
  return Number(row.amount || 0) !== 0
    || Number(row.quantity || 0) !== 0
    || Number(row.book_order_num || 0) !== 0
    || Number(row.comment_score || 0) !== 0
    || Number(row.data_value || 0) !== 0
    || Number(row.list_exposure || 0) !== 0
    || Number(row.detail_exposure || 0) !== 0
    || Number(row.flow_rate || 0) !== 0
    || Number(row.order_filling_num || 0) !== 0
    || Number(row.order_submit_num || 0) !== 0;
}

function hasFactOnlyValue(facts) {
  return facts.some((fact) => (
    FACT_ONLY_FIELD_IDS.has(String(fact.metric_key || ''))
    && String(fact.value ?? '').trim() !== ''
  ));
}

function hasCtripRankingValue(facts) {
  return facts.some((fact) => (
    isCtripRankingFact(fact)
    && String(fact.value ?? '').trim() !== ''
  ));
}

function isCtripRankingFact(fact) {
  return CTRIP_RANKING_ENDPOINT_IDS.has(String(fact?.endpoint_id || ''))
    && CTRIP_COMPETITOR_RANK_FIELD_IDS.has(String(fact?.metric_key || ''));
}

function numericFactValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  let text = String(value ?? '').trim();
  if (!text || text === '--' || text === '-') {
    return null;
  }
  const multiplier = /万/.test(text) ? 10000 : 1;
  text = text.replace(/[,%￥¥元个次间夜分\s]/g, '').replace(/万/g, '');
  if (!text || !Number.isFinite(Number(text))) {
    return null;
  }
  return Number(text) * multiplier;
}

function normalizeFactPercent(number) {
  if (number > 0 && number <= 1) {
    return Math.round(number * 10000) / 100;
  }
  return Math.round(number * 100) / 100;
}

function normalizeCommentResponseRate(number) {
  if (number > 0 && number <= 2) {
    return Math.round(number * 10000) / 100;
  }
  return normalizeFactPercent(number);
}

function normalizeFactDate(value) {
  const text = String(value ?? '').trim();
  if (!text) {
    return '';
  }
  let match = text.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
  if (!match) {
    match = text.match(/^(\d{4})(\d{2})(\d{2})$/);
  }
  if (!match) {
    return '';
  }
  return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
}

function parentPath(path) {
  const parts = String(path || '').split('.').filter(Boolean);
  parts.pop();
  return parts.join('.');
}

function safeDimensionPart(value) {
  return String(value || 'root').replace(/[^a-zA-Z0-9_.:-]+/g, '_').slice(0, 80);
}

function parseCandidateUrl(url) {
  try {
    return new URL(url);
  } catch {
    return null;
  }
}

function canonicalCandidateUrl(url) {
  const parsed = parseCandidateUrl(url);
  if (!parsed) {
    return '';
  }
  const ignored = new Set(['_fxpcqlniredt', 'x-traceid', 'metasender', 'contextts', 'sid', 'pvid', 'appid', 'v']);
  const params = [...parsed.searchParams.entries()]
    .filter(([key]) => !ignored.has(String(key).toLowerCase()))
    .sort(([a], [b]) => a.localeCompare(b));
  const query = params.map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`).join('&');
  return `${parsed.origin}${parsed.pathname}${query ? `?${query}` : ''}`;
}

function endpointNameFromUrl(parsed, fallback) {
  if (parsed) {
    const parts = parsed.pathname.split('/').filter(Boolean);
    return parts[parts.length - 1] || parsed.pathname || '';
  }
  return String(fallback || '').split(/[/?#]/).filter(Boolean).pop() || '';
}
