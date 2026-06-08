import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  CTRIP_CAPTURE_ENDPOINTS,
  CTRIP_CAPTURE_SECTIONS,
  CTRIP_ENDPOINT_CANDIDATE_RULES,
  CTRIP_SECTION_INTERACTION_PLANS,
  buildCtripEndpointCandidates,
  buildCtripStandardRowsFromFacts,
  classifyCtripUrl,
  ctripCatalogSummary,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
  generateCtripCaptureMarkdown,
  normalizeCtripCaptureSections,
} from './lib/ctrip_capture_catalog.mjs';

function parseArgs(argv) {
  const args = { i18n: '', json: false, write: true };
  for (const item of argv) {
    if (item === '--json') {
      args.json = true;
    } else if (item === '--no-write') {
      args.write = false;
    } else if (item.startsWith('--i18n=')) {
      args.i18n = item.slice('--i18n='.length);
    }
  }
  return args;
}

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function catalogFieldIds() {
  const ids = new Set();
  for (const endpoint of CTRIP_CAPTURE_ENDPOINTS) {
    for (const field of endpoint.fields || []) {
      ids.add(field.id);
    }
  }
  return ids;
}

function readFirstJson(path) {
  const raw = readFileSync(path, 'utf8').replace(/^\uFEFF/, '');
  const start = raw.search(/[\[{]/);
  assertContract(start >= 0, 'i18n file has no JSON start');
  const open = raw[start];
  const close = open === '{' ? '}' : ']';
  let depth = 0;
  let inString = false;
  let escaped = false;
  for (let index = start; index < raw.length; index += 1) {
    const char = raw[index];
    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (char === '\\') {
        escaped = true;
      } else if (char === '"') {
        inString = false;
      }
      continue;
    }
    if (char === '"') {
      inString = true;
      continue;
    }
    if (char === open) {
      depth += 1;
    } else if (char === close) {
      depth -= 1;
      if (depth === 0) {
        return JSON.parse(raw.slice(start, index + 1));
      }
    }
  }
  throw new Error('i18n file has no complete JSON object');
}

const I18N_METRIC_REFERENCE_KEYS = [
  {
    term: '预订订单数',
    keys: [
      'Key.DataCenter.IndexType.Order.HoverText',
      'Key.DataCenter.IndexType.Order.HoverText.RealTime',
    ],
  },
  {
    term: '预订销售额',
    keys: ['Key.DataCenter.IndexType.Sale.HoverText'],
  },
  {
    term: '在店间夜',
    keys: [
      'Key.DataCenter.IndexType.ThrowNight.HoverText',
      'Key.DataCenter.IndexType.ThrowNight.HoverText.RealTime',
    ],
  },
  {
    term: '列表页曝光量',
    keys: [
      'Key.DataCenter.IndexType.ListExposure.HoverText',
      'Key.DataCenter.IndexType.ListExposure.Title',
    ],
  },
  {
    term: '详情页访客量',
    keys: [
      'Key.DataCenter.IndexType.DetailVisitor.HoverText',
      'Key.DataCenter.IndexType.APPDetailVisitor.HoverText',
      'Key.FlowAnalysis.149',
    ],
  },
  {
    term: '订单页访客量',
    keys: [
      'Key.DataCenter.IndexType.OrderVisitor.HoverText',
      'Key.DataCenter.IndexType.OrderVisitor.Title',
      'Key.FlowAnalysis.150',
    ],
  },
  {
    term: '订单提交人数',
    keys: [
      'Key.FlowAnalysis.151',
      'Key.DataCenter.Mainland.Natinal.019',
      'Key.DataCenter.Group.Overview.Title4',
    ],
  },
  {
    term: '出租率',
    keys: ['Key.DataCenter.IndexType.RentRate.HoverText'],
  },
  {
    term: '下单转化率',
    keys: ['Key.DataCenter.IndexType.Transfer.HoverText'],
  },
  {
    term: '列表页曝光转化',
    keys: ['Key.DataCenter.IndexType.ListExposureTransfer.HoverText'],
  },
  {
    term: '订单页访客转化',
    keys: ['Key.DataCenter.IndexType.OrderTransfer.HoverText'],
  },
  {
    term: '流失订单量',
    keys: [
      'Key.Oversea.DataCenter.BookingInsights.Overview.Props.2',
      'Key.DataCenter.LossCard.Order',
    ],
  },
  {
    term: '平均卖价',
    keys: [
      'Key.DataCenter.Group.Overview.card3.title',
      'Key.MarketOverview.028',
      'DataCenter.Group.Overview.Indicator27',
    ],
  },
  {
    term: '紧张度',
    keys: [
      'Key.MarketOverview.041',
      'Key.RealTimeData.093',
      'Key.RealTimeData.094',
    ],
  },
  {
    term: 'PSI服务质量分',
    keys: [
      'Key.DataCenter.Group.StockQuality.Psi.Prop1',
      'Key.DataCenter.Group.StockQuality.Download.Indicator.Prop8',
    ],
  },
  {
    term: 'PSI基础分',
    keys: [
      'Key.DataCenter.Group.StockQuality.Psi.Prop2',
      'Key.DataCenter.Group.StockQuality.Download.Psi.Prop1',
    ],
  },
  {
    term: '回复率',
    keys: [
      'Key.DataCenter.Userbehavior.CommentOverview.ReplyRate',
      'Key.DataCenter.Userbehavior.CommentOverview.ReplyRateHover',
    ],
  },
];

function inspectI18n(path) {
  if (!path) {
    return null;
  }
  const data = readFirstJson(path);
  const entries = [];
  const entriesByKey = new Map();
  for (const module of Object.values(data.modules || {})) {
    if (module && typeof module === 'object' && module.entries && typeof module.entries === 'object') {
      for (const [key, value] of Object.entries(module.entries)) {
        if (typeof value !== 'string') {
          continue;
        }
        entries.push(value);
        entriesByKey.set(key, value);
      }
    }
  }
  const terms = ['预订订单数', '间夜量', '销售额', '访客量', '列表页曝光量', '详情页访客量', '紧张度', 'PSI', '竞争圈平均', '订单提交人数'];
  const matched = terms.filter((term) => entries.some((value) => value.includes(term)));
  const metricDefinitions = I18N_METRIC_REFERENCE_KEYS.map((item) => {
    const sourceKey = item.keys.find((key) => entriesByKey.has(key));
    if (sourceKey) {
      return {
        term: item.term,
        definition: entriesByKey.get(sourceKey),
        source_key: sourceKey,
      };
    }
    const fallback = entries.find((value) => value.includes(item.term) && value.length <= 180);
    return fallback ? {
      term: item.term,
      definition: fallback,
      source_key: '',
    } : null;
  }).filter(Boolean);
  return {
    source: data.meta?.source || 'i18n_translations.json',
    total_modules: data.meta?.total_modules ?? Object.keys(data.modules || {}).length,
    total_entries: data.meta?.total_entries ?? entries.length,
    matched_terms: matched,
    metric_definitions: metricDefinitions,
  };
}

function resolveI18nPath(explicitPath) {
  if (explicitPath) {
    return explicitPath;
  }

  for (const candidate of [
    process.env.SUXIOS_CTRIP_I18N_FILE,
    process.env.CTRIP_I18N_FILE,
    'docs/i18n_translations.json',
    'reports/i18n_translations.json',
  ]) {
    if (candidate && existsSync(candidate)) {
      return candidate;
    }
  }

  return '';
}

function verifyCatalog() {
  assertContract(Object.keys(CTRIP_CAPTURE_SECTIONS).length >= 12, 'catalog must cover core Ctrip sections');
  assertContract(CTRIP_CAPTURE_ENDPOINTS.length >= 40, 'catalog must cover observed endpoint rules');

  const sectionIds = new Set(Object.keys(CTRIP_CAPTURE_SECTIONS));
  const endpointIds = new Set();
  for (const endpoint of CTRIP_CAPTURE_ENDPOINTS) {
    assertContract(!endpointIds.has(endpoint.id), `duplicate endpoint id: ${endpoint.id}`);
    endpointIds.add(endpoint.id);
    assertContract(sectionIds.has(endpoint.section), `endpoint ${endpoint.id} references missing section ${endpoint.section}`);
    assertContract(Array.isArray(endpoint.keywords) && endpoint.keywords.length > 0, `endpoint ${endpoint.id} has no keywords`);
    assertContract(Array.isArray(endpoint.fields) && endpoint.fields.length > 0, `endpoint ${endpoint.id} has no fields`);
    for (const field of endpoint.fields) {
      assertContract(field.id && field.label, `endpoint ${endpoint.id} has invalid field`);
      assertContract(Array.isArray(field.sourceKeys) && field.sourceKeys.length > 0, `field ${field.id} has no source keys`);
    }
  }

  const samples = [
    ['https://ebooking.ctrip.com/restapi/soa2/24306/queryHomePageRealTimeData', 'homepage'],
    ['https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2', 'business_overview'],
    ['https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/competitor/queryCompetingHotelsV2', 'room_type'],
    ['https://ebooking.ctrip.com/comment/api/getCommentNumV2', 'comment_review'],
    ['https://ebooking.ctrip.com/comment/api/getCommentList', 'comment_review'],
    ['https://ebooking.ctrip.com/datacenter/api/getTripartiteOrderLoss', 'loss_analysis'],
    ['https://ebooking.ctrip.com/datacenter/api/getCompetingRank', 'competitor_rank'],
    ['https://ebooking.ctrip.com/userbehavior/getImIndex?hostType=Ebooking&v=0.4544692596916936', 'im_board'],
    ['https://ebooking.ctrip.com/userbehavior/getImDateDistribute?hostType=Ebooking&v=0.889990888095976', 'im_board'],
    ['https://ebooking.ctrip.com/userbehavior/getImSessionDistribute?hostType=Ebooking&v=0.3581193674166786', 'im_board'],
    ['https://ebooking.ctrip.com/userbehavior/getImOrderConversionRateByDay?hostType=Ebooking&v=0.7937016081022331', 'im_board'],
    ['https://ebooking.ctrip.com/userbehavior/getImOrderConversionDetail?hostType=Ebooking&v=0.3644864469238388', 'im_board'],
    ['https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport', 'ads_pyramid'],
    ['https://ebooking.ctrip.com/psi/api/getHotelPsiV2', 'quality_psi'],
    ['https://ebooking.ctrip.com/toolcenter/api/psiV2/getHotelPsiV2?hostType=HE&v=0.14653639846260236', 'quality_psi'],
    ['https://ebooking.ctrip.com/toolcenter/api/psi/queryHistPsiScoreList?hostType=HE&v=0.8928221408368409', 'quality_psi'],
    ['https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo', 'market_calendar'],
    ['https://bbk.ctripbiz.cn/api/searchBpiOverview', 'biztravel_bpi'],
    ['https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail', 'biztravel_business_report'],
    ['https://bbk.ctripbiz.cn/api/dataCenterComparatorReportDetail', 'biztravel_competitor'],
    ['https://bbk.ctripbiz.com/api/searchBpiOverview', 'biztravel_bpi'],
  ];
  for (const [url, expected] of samples) {
    assertContract(classifyCtripUrl(url) === expected, `classify ${url} as ${expected}`);
  }

  for (const [section, url] of [
    ['competitor_overview', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionprofile?microJump=true'],
    ['loss_analysis', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/lossanalysis?microJump=true'],
    ['competitor_rank', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/competition/competitionlist?microJump=true'],
    ['comment_review', 'https://ebooking.ctrip.com/comment/commentList?microJump=true'],
    ['quality_psi', 'https://ebooking.ctrip.com/toolcenter/psi/index?fromType=menu&microJump=true'],
    ['ads_pyramid', 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true'],
    ['ads_pyramid', 'https://ebooking.ctrip.com/toolcenter/cpc/dataReport?microJump=true'],
    ['ads_pyramid', 'https://ebooking.ctrip.com/toolcenter/cpc/comparison?microJump=true'],
    ['ads_pyramid', 'https://ebooking.ctrip.com/advertise/cpc/diagnosisReport?microJump=true'],
    ['market_calendar', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/marketanalysis/marketheat?microJump=true'],
    ['user_profile', 'https://ebooking.ctrip.com/datacenter/inland/userbehavior/user?microJump=true'],
    ['user_profile', 'https://ebooking.ctrip.com/ebkgrowth/datacenter/userbehavior/user?microJump=true'],
  ]) {
    const sectionUrls = new Set((CTRIP_CAPTURE_SECTIONS[section]?.pageUrls || []).map((item) => item.url));
    assertContract(sectionUrls.has(url), `${section} must include page URL: ${url}`);
  }

  assertContract(JSON.stringify(normalizeCtripCaptureSections('business,traffic')) === JSON.stringify(['business_overview', 'traffic_report']), 'legacy business/traffic aliases must work');
  assertContract(!normalizeCtripCaptureSections('default').includes('room_type'), 'default Profile capture must not include room_type');
  assertContract(!normalizeCtripCaptureSections('core').includes('room_type'), 'core Profile capture must not include room_type');
  for (const section of ['business_overview', 'traffic_report']) {
    assertContract(normalizeCtripCaptureSections('default').includes(section), `default Profile capture must include ${section}`);
  }
  for (const section of ['comment_review', 'competitor_overview', 'loss_analysis', 'competitor_rank', 'quality_psi', 'ads_pyramid', 'market_calendar', 'user_profile']) {
    assertContract(!normalizeCtripCaptureSections('default').includes(section), `default Profile capture must not include optional section ${section}`);
    assertContract(normalizeCtripCaptureSections('wide').includes(section), `wide Profile capture must include ${section}`);
  }
  assertContract(normalizeCtripCaptureSections('marketanalysis')[0] === 'market_calendar', 'marketanalysis alias must route to market_calendar');
  assertContract(normalizeCtripCaptureSections('all').length === Object.keys(CTRIP_CAPTURE_SECTIONS).length, 'all sections must expand');
  assertContract(CTRIP_ENDPOINT_CANDIDATE_RULES.length >= 5, 'P3 candidate rules must cover remaining capture directions');
  assertContract(buildCtripEndpointCandidates([{ url: 'https://bbk.ctripbiz.cn/api/contractPre' }]).some((item) => item.candidate_section === 'contract_mice_rfp'), 'contractPre must remain a P3 candidate until payload is verified');

  const visitorTitleEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'business_visitor_title');
  for (const [fieldId, sourceKey] of [
    ['visitor_count', 'visitorTotal'],
    ['visitor_rank', 'visitorRank'],
    ['visitor_count_last_week', 'lastVisitorTotal'],
    ['competitor_avg_visitor', 'competitorAvgNumber'],
    ['qunar_visitor_count', 'qunarVisitorTotal'],
    ['qunar_visitor_rank', 'qunarCompetitorRank'],
    ['qunar_visitor_count_last_week', 'lastQunarVisitorTotal'],
    ['qunar_competitor_avg_visitor', 'qunarCompetitorAvgNumber'],
  ]) {
    const field = visitorTitleEndpoint?.fields.find((item) => item.id === fieldId);
    assertContract(field?.sourceKeys.includes(sourceKey), `business_visitor_title ${fieldId} must include source key: ${sourceKey}`);
  }

  const competitorRankEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'competitor_rank');
  for (const [fieldId, sourceKey] of [
    ['competition_rank_order_count', 'bookingOrdersrank'],
    ['competition_rank_order_amount', 'bookingGMVrank'],
    ['competition_rank_room_nights', 'stayInRNrank'],
    ['competition_rank_occupancy_rate', 'rentalRaterank'],
    ['competition_rank_app_detail_visitor', 'totalDetailNum'],
    ['competition_rank_app_conversion_rate', 'convertionRate'],
    ['competition_rank_psi_score', 'serviceScoreRank'],
    ['competition_rank_ctrip_rating', 'commentScore'],
    ['competition_rank_qunar_rating', 'qunarCommentScoreRank'],
    ['competition_rank_tongcheng_rating', 'tongchengCommentScoreRank'],
    ['competition_rank_zhixing_rating', 'zhixingCommentScoreRank'],
  ]) {
    const field = competitorRankEndpoint?.fields.find((item) => item.id === fieldId);
    assertContract(field?.sourceKeys.includes(sourceKey), `competitor_rank ${fieldId} must include source key: ${sourceKey}`);
  }

  const adsPyramidUrls = new Set((CTRIP_CAPTURE_SECTIONS.ads_pyramid?.pageUrls || []).map((item) => item.url));
  assertContract(adsPyramidUrls.has('https://ebooking.ctrip.com/toolcenter/cpc/pyramid'), 'ads_pyramid must include observed CPC pyramid homepage');
  const adsReportEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'ads_report_list');
  const adCostField = adsReportEndpoint?.fields.find((field) => field.id === 'ad_cost');
  for (const sourceKey of ['todayCost', 'cashCost', 'bonusCost', 'charge', 'yesterdayCharge']) {
    assertContract(adCostField?.sourceKeys.includes(sourceKey), `ad_cost must include source key: ${sourceKey}`);
  }
  const trafficFlowEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'traffic_flow_transform');
  const trafficSeqEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'traffic_hotel_seq');
  assertContract(trafficSeqEndpoint?.section === 'traffic_report', 'fetchCurrentHotelSeqInfoV1 must be available under traffic_report');
  assertContract(trafficSeqEndpoint?.dataType === 'traffic', 'traffic_hotel_seq must remain traffic data');
  const trafficRankField = trafficSeqEndpoint?.fields.find((field) => field.id === 'traffic_rank');
  for (const sourceKey of ['rank', 'seqRank', 'trafficRank', 'qunarRank', 'qunarCompetitorRank']) {
    assertContract(trafficRankField?.sourceKeys.includes(sourceKey), `traffic_rank must include source key: ${sourceKey}`);
  }
  assertContract(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCurrentHotelSeqInfoV1',
      { pageUrl: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true' },
    )?.id === 'traffic_hotel_seq',
    'flowdata page context must route fetchCurrentHotelSeqInfoV1 to traffic_hotel_seq',
  );
  assertContract(
    findCtripEndpointByUrl(
      'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCurrentHotelSeqInfoV1',
      { preferredSection: 'business_overview' },
    )?.id === 'business_hotel_seq',
    'business overview context must keep fetchCurrentHotelSeqInfoV1 on business_hotel_seq',
  );
  const flowSourcePopupEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'traffic_flow_source_popups');
  assertContract(flowSourcePopupEndpoint?.status === 'supporting', 'queryFlowSourcePopups must stay a supporting traffic endpoint');
  assertContract(flowSourcePopupEndpoint?.fields.some((field) => field.id === 'source_name'), 'queryFlowSourcePopups must expose source_name as fact-only context');
  const trafficMenuKeyEndpoint = CTRIP_CAPTURE_ENDPOINTS.find((endpoint) => endpoint.id === 'traffic_menu_key');
  assertContract(trafficMenuKeyEndpoint?.status === 'supporting', 'queryMenuKey must stay a supporting traffic endpoint');
  assertContract(findCtripEndpointByUrl('https://ebooking.ctrip.com/api/collect2?metaSender=1.3.81') === null, 'collect2 must not become a Ctrip capture endpoint');
  const trafficPlanTexts = (CTRIP_SECTION_INTERACTION_PLANS.traffic_report || []).map((step) => String(step.text || ''));
  assertContract(trafficPlanTexts.includes('携程'), 'traffic_report interaction plan must click Ctrip traffic tab');
  assertContract(trafficPlanTexts.includes('去哪儿'), 'traffic_report interaction plan must click Qunar traffic tab');
  const pageViewsField = trafficFlowEndpoint?.fields.find((field) => field.id === 'page_views');
  assertContract(pageViewsField?.sourceKeys.includes('listExposure'), 'page_views legacy field must prefer queryFlowTransforNewV1 listExposure');
  assertContract(String(pageViewsField?.description || '').includes('legacy field_key'), 'page_views must be documented as a legacy-compatible field key');
  for (const [fieldId, sourceKey] of [
    ['competitor_list_exposure', 'listExposure'],
    ['competitor_detail_visitor', 'detailExposure'],
    ['competitor_order_page_visitor', 'orderFillingNum'],
    ['competitor_order_submit_user', 'orderSubmitNum'],
  ]) {
    const field = trafficFlowEndpoint?.fields.find((item) => item.id === fieldId);
    assertContract(field?.sourceKeys.includes(sourceKey), `${fieldId} must include queryFlowTransforNewV1 source key: ${sourceKey}`);
    assertContract(String(field?.description || '').includes('hotelId=-1'), `${fieldId} must document hotelId=-1 competitor average row`);
  }
  const trafficFormulaContracts = [
    ['flow_rate', 'flowRate', 'detailExposure / listExposure'],
    ['competitor_flow_rate', 'hotelId=-1.flowRate', 'hotelId=-1'],
    ['order_fill_rate', 'orderFillingNum/detailExposure', 'orderFillingNum / detailExposure'],
    ['competitor_order_fill_rate', 'hotelId=-1.orderFillingNum/detailExposure', 'hotelId=-1'],
    ['deal_rate', 'orderSubmitNum/orderFillingNum', 'orderSubmitNum / orderFillingNum'],
    ['competitor_deal_rate', 'hotelId=-1.orderSubmitNum/orderFillingNum', 'hotelId=-1'],
  ];
  for (const [fieldId, sourceKey, descriptionNeedle] of trafficFormulaContracts) {
    const field = trafficFlowEndpoint?.fields.find((item) => item.id === fieldId);
    assertContract(field?.sourceKeys.includes(sourceKey), `${fieldId} must include Ctrip APP funnel source key/formula: ${sourceKey}`);
    assertContract(String(field?.description || '').includes(descriptionNeedle), `${fieldId} must document Ctrip APP funnel formula: ${descriptionNeedle}`);
  }
  const qunarTrafficContracts = [
    ['qunar_list_exposure', 'platform=Qunar.listExposure'],
    ['qunar_competitor_list_exposure', 'platform=Qunar.hotelId=-1.listExposure'],
    ['qunar_detail_visitor', 'platform=Qunar.detailExposure'],
    ['qunar_competitor_detail_visitor', 'platform=Qunar.hotelId=-1.detailExposure'],
    ['qunar_flow_rate', 'platform=Qunar.flowRate'],
    ['qunar_competitor_flow_rate', 'platform=Qunar.hotelId=-1.flowRate'],
    ['qunar_order_page_visitor', 'platform=Qunar.orderFillingNum'],
    ['qunar_competitor_order_page_visitor', 'platform=Qunar.hotelId=-1.orderFillingNum'],
    ['qunar_order_fill_rate', 'platform=Qunar.orderFillingNum/detailExposure'],
    ['qunar_competitor_order_fill_rate', 'platform=Qunar.hotelId=-1.orderFillingNum/detailExposure'],
    ['qunar_order_submit_user', 'platform=Qunar.orderSubmitNum'],
    ['qunar_competitor_order_submit_user', 'platform=Qunar.hotelId=-1.orderSubmitNum'],
    ['qunar_deal_rate', 'platform=Qunar.orderSubmitNum/orderFillingNum'],
    ['qunar_competitor_deal_rate', 'platform=Qunar.hotelId=-1.orderSubmitNum/orderFillingNum'],
  ];
  for (const [fieldId, sourceKey] of qunarTrafficContracts) {
    const field = trafficFlowEndpoint?.fields.find((item) => item.id === fieldId);
    assertContract(field?.sourceKeys.includes(sourceKey), `${fieldId} must include Qunar APP funnel source key/formula: ${sourceKey}`);
    assertContract(String(field?.description || '').includes('platform=Qunar'), `${fieldId} must document platform=Qunar storage boundary`);
  }
  const platformSample = [
    { date: '2026-06-01', hotelId: 134396668, listExposure: 1297, detailExposure: 231, flowRate: 17.81, orderFillingNum: 9, orderSubmitNum: 7 },
    { date: '2026-06-01', hotelId: -1, listExposure: 799, detailExposure: 172, flowRate: 21.5, orderFillingNum: 10, orderSubmitNum: 6 },
  ];
  for (const platform of ['Ctrip', 'Qunar']) {
    const facts = extractCtripCatalogFacts(platformSample, {
      endpoint: trafficFlowEndpoint,
      section: 'traffic_report',
      dataType: 'traffic',
      platform,
      hotelId: '134396668',
      dataDate: '2026-06-01',
      url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
    });
    const rows = buildCtripStandardRowsFromFacts(facts, {
      platform,
      hotelId: '134396668',
      profileId: '134396668',
      dataDate: '2026-06-01',
      defaultDataDate: '2026-06-01',
    });
    assertContract(rows.length >= 2, `${platform} APP funnel sample must build self and competitor rows`);
    assertContract(rows.every((row) => row.platform === platform), `${platform} APP funnel rows must preserve platform`);
    assertContract(rows.some((row) => row.compare_type === 'competitor'), `${platform} APP funnel rows must preserve hotelId=-1 competitor average`);
    const selfRow = rows.find((row) => row.compare_type === 'self');
    const competitorRow = rows.find((row) => row.compare_type === 'competitor');
    assertContract(selfRow?.list_exposure === 1297, `${platform} self APP funnel row must store listExposure`);
    assertContract(selfRow?.detail_exposure === 231, `${platform} self APP funnel row must store detailExposure`);
    assertContract(selfRow?.order_filling_num === 9, `${platform} self APP funnel row must store orderFillingNum`);
    assertContract(selfRow?.order_submit_num === 7, `${platform} self APP funnel row must store orderSubmitNum`);
    assertContract(Math.abs(Number(selfRow?.flow_rate) - 17.81) < 0.001, `${platform} self APP funnel row must store exposure conversion rate`);
    assertContract(competitorRow?.list_exposure === 799, `${platform} competitor APP funnel row must store listExposure`);
    assertContract(competitorRow?.detail_exposure === 172, `${platform} competitor APP funnel row must store detailExposure`);
    assertContract(competitorRow?.order_filling_num === 10, `${platform} competitor APP funnel row must store orderFillingNum`);
    assertContract(competitorRow?.order_submit_num === 6, `${platform} competitor APP funnel row must store orderSubmitNum`);
    assertContract(Math.abs(Number(competitorRow?.flow_rate) - 21.53) < 0.001, `${platform} competitor APP funnel row must store computed exposure conversion rate`);
  }
  const onlineDataSource = readFileSync('app/controller/OnlineData.php', 'utf8');
  for (const fieldKey of [
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
  ]) {
    assertContract(onlineDataSource.includes(`'${fieldKey}'`), `OnlineData default Profile fields must include ${fieldKey}`);
    assertContract(
      onlineDataSource.includes(`raw_data.rank_metrics.${fieldKey}`),
      `OnlineData field meta must store ${fieldKey} in raw_data.rank_metrics`,
    );
  }
  assertContract(
    onlineDataSource.includes('榜单名次口径：字段值均为第几名') && onlineDataSource.includes('不是携程点评分'),
    'OnlineData competitor_rank fields must be documented as rank positions, not business metric values',
  );
  assertContract(
    onlineDataSource.includes("'competitor_rank', '竞争圈动态-竞争圈榜单'"),
    'OnlineData competitor_rank module label must match the Ctrip competition list page',
  );
  assertContract(
    onlineDataSource.includes("'im_board', '用户行为-IM看板'"),
    'OnlineData default Profile modules must expose user behavior IM board',
  );
  const publicIndexSource = readFileSync('public/index.html', 'utf8');
  assertContract(
    publicIndexSource.includes("label: '竞争圈动态-竞争圈榜单'"),
    'Profile field-management UI must expose competitor_rank as 竞争圈动态-竞争圈榜单',
  );
  assertContract(
    publicIndexSource.includes("value: 'im_board', label: '用户行为-IM看板'"),
    'Profile field-management UI must expose user behavior IM board',
  );
  const saveStandardRowsMatch = onlineDataSource.match(/private function saveCtripStandardRows[\s\S]*?private function extractCtripCapturedResponseData/);
  assertContract(saveStandardRowsMatch, 'saveCtripStandardRows function must be present');
  assertContract(
    saveStandardRowsMatch[0].includes("->where('source', (string)($row['source'] ?? 'ctrip'))"),
    'saveCtripStandardRows must use row source so Ctrip and Qunar APP funnels do not overwrite each other'
  );

  const fieldIds = catalogFieldIds();
  for (const fieldId of [
    'avg_booking_days',
    'avg_user_age',
    'avg_stay_days',
    'booking_hour',
    'booking_days',
    'booking_method',
    'comment_response_rate',
    'consumption_power',
    'ctrip_comment_count',
    'ctrip_comment_id',
    'ctrip_rating_rank',
    'distribution_share',
    'elong_comment_count',
    'elong_comment_id',
    'elong_rating',
    'hotel_star_preference',
    'order_hotel_count',
    'order_preference',
    'preference_frequency',
    'price_sensitivity',
    'qunar_comment_count',
    'qunar_comment_id',
    'qunar_rating',
    'qunar_rating_rank',
    'rating_competitor_total',
    'source_city',
    'source_region',
    'stay_days',
    'travel_time',
    'user_source_scope',
    'competitor_list_exposure',
    'competitor_detail_visitor',
    'competitor_flow_rate',
    'competitor_order_page_visitor',
    'order_fill_rate',
    'competitor_order_fill_rate',
    'competitor_order_submit_user',
    'deal_rate',
    'competitor_deal_rate',
    'qunar_list_exposure',
    'qunar_competitor_list_exposure',
    'qunar_detail_visitor',
    'qunar_competitor_detail_visitor',
    'qunar_flow_rate',
    'qunar_competitor_flow_rate',
    'qunar_order_page_visitor',
    'qunar_competitor_order_page_visitor',
    'qunar_order_fill_rate',
    'qunar_competitor_order_fill_rate',
    'qunar_order_submit_user',
    'qunar_competitor_order_submit_user',
    'qunar_deal_rate',
    'qunar_competitor_deal_rate',
    'weekly_competitor_avg_order_page_visitor',
    'weekly_top_competitor_submit_user',
    'notice_count',
    'comment_store_name',
    'comment_date',
    'comment_channel',
    'comment_score',
    'comment_count',
    'bad_review_count',
    'five_min_reply_rate',
    'manual_reply_rate',
    'robot_resolution_rate',
    'im_rank',
    'session_count',
    'manual_session_count',
    'robot_session_count',
    'im_order_conversion_rate',
  ]) {
    assertContract(fieldIds.has(fieldId), `latest task field must exist in catalog: ${fieldId}`);
  }
  for (const fieldKey of [
    'avg_booking_days',
    'avg_user_age',
    'avg_stay_days',
    'booking_hour',
    'booking_days',
    'booking_method',
    'comment_response_rate',
    'comment_store_name',
    'comment_date',
    'comment_channel',
    'comment_score',
    'comment_count',
    'bad_review_count',
    'consumption_power',
    'ctrip_comment_count',
    'ctrip_comment_id',
    'ctrip_rating_rank',
    'distribution_share',
    'elong_comment_count',
    'elong_comment_id',
    'elong_rating',
    'hotel_star_preference',
    'order_hotel_count',
    'order_preference',
    'preference_frequency',
    'price_sensitivity',
    'qunar_comment_count',
    'qunar_comment_id',
    'qunar_rating',
    'qunar_rating_rank',
    'rating_competitor_total',
    'source_city',
    'source_region',
    'stay_days',
    'travel_time',
    'user_source_scope',
    'five_min_reply_rate',
    'manual_reply_rate',
    'robot_resolution_rate',
    'im_rank',
    'session_count',
    'manual_session_count',
    'robot_session_count',
    'im_order_conversion_rate',
  ]) {
    assertContract(onlineDataSource.includes(`'${fieldKey}'`), `OnlineData default Profile fields must include ${fieldKey}`);
  }
}

function writeReports(i18nReference, options = {}) {
  const markdown = generateCtripCaptureMarkdown({ i18nReference });
  const summary = {
    ...ctripCatalogSummary(),
    sections: CTRIP_CAPTURE_SECTIONS,
    endpoints: CTRIP_CAPTURE_ENDPOINTS,
    p3_candidate_rules: CTRIP_ENDPOINT_CANDIDATE_RULES,
    i18n_reference: i18nReference,
  };
  if (options.write !== false) {
    mkdirSync('docs', { recursive: true });
    mkdirSync('reports', { recursive: true });
    writeFileSync(join('docs', 'ctrip_capture_field_inventory.md'), `${markdown.trimEnd()}\n`, 'utf8');
    writeFileSync(join('reports', 'ctrip_capture_catalog.json'), `${JSON.stringify(summary, null, 2)}\n`, 'utf8');
  }
  return summary;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  verifyCatalog();
  const i18nReference = inspectI18n(resolveI18nPath(args.i18n));
  const summary = writeReports(i18nReference, { write: args.write });
  if (args.json) {
    console.log(JSON.stringify(summary, null, 2));
  } else {
    console.log(JSON.stringify({
      status: 'pass',
      docs: 'docs/ctrip_capture_field_inventory.md',
      report: 'reports/ctrip_capture_catalog.json',
      summary: ctripCatalogSummary(),
      i18n_reference: i18nReference,
    }, null, 2));
  }
}

main();
