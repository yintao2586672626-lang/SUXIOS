import {
  CTRIP_CAPTURE_ENDPOINTS,
  CTRIP_CAPTURE_SECTIONS,
  CTRIP_CORE_METRIC_LEARNING_ROWS,
  buildCtripStandardRowsFromFacts,
  ctripCatalogSummary,
} from './ctrip_capture_catalog.mjs';
import { PLATFORM_CONFIGS } from './ota_capture_standard.mjs';

const TRUTH_REQUIREMENTS = [
  'source_platform',
  'system_hotel_id',
  'platform_hotel_id',
  'target_date',
  'data_period',
  'capture_trace',
  'persistence',
  'database_readback',
  'field_fact_status',
];

const COMMON_STORAGE_CONTRACT = {
  primary_table: 'online_daily_data',
  raw_table: 'platform_data_raw_records',
  required_anchors: [
    'system_hotel_id',
    'platform_hotel_id',
    'data_date',
    'data_period',
    'platform',
    'source',
    'data_source_id',
    'sync_task_id',
    'source_trace_id',
    'readback_verified',
  ],
  field_fact_path: 'online_daily_data.raw_data.field_facts',
  raw_fact_path: 'online_daily_data.raw_data.facts',
  missing_policy: '缺失必须保留缺失状态，不得用 0、空数组、旧记录或默认值代替。',
};

const UI_USES_BY_DATA_TYPE = {
  business: ['昨日经营闭环', '收益分析输入', '经营日报'],
  traffic: ['昨日经营闭环', '流量转化诊断', '经营日报'],
  order: ['昨日经营闭环', '收益分析输入', '订单汇总'],
  order_flow: ['流失订单诊断', '经营日报'],
  peer_rank: ['竞对异常诊断', '经营日报'],
  ranking: ['竞对异常诊断', '经营日报'],
  advertising: ['广告诊断', '经营日报'],
  quality: ['点评与质量诊断', '经营日报'],
  review: ['点评与质量诊断', '经营日报'],
  search_keyword: ['搜索词诊断', '经营日报'],
  traffic_forecast: ['未来信号', 'AI研判输入'],
  traffic_analysis: ['流量转化诊断', '经营日报'],
  room_type: ['房型产品诊断', '收益分析输入'],
  platform_identity: ['账户绑定', '数据来源与缺口'],
};

// Labels alone cannot prove a field is consumed by the product. Keep a small,
// source-backed consumer map beside the human-facing page names so the field
// catalog can distinguish implemented consumption from a future idea.
const CONSUMER_CONTRACTS_BY_DATA_TYPE = {
  business: [
    { surface: '昨日经营闭环', source: 'app/controller/Agent.php', usage_status: 'implemented' },
    { surface: '经营日报', source: 'app/controller/DailyReport.php', usage_status: 'implemented' },
  ],
  traffic: [
    { surface: '流量转化诊断', source: 'app/controller/Agent.php', usage_status: 'implemented' },
    { surface: '经营日报', source: 'app/controller/DailyReport.php', usage_status: 'implemented' },
  ],
  order: [
    { surface: '收益分析输入', source: 'app/service/RevenueAiOverviewService.php', usage_status: 'implemented' },
    { surface: '经营日报', source: 'app/controller/DailyReport.php', usage_status: 'implemented' },
  ],
  order_flow: [
    { surface: '流失订单诊断', source: 'app/controller/concern/MeituanCapturedDataConcern.php', usage_status: 'partial' },
  ],
  peer_rank: [
    { surface: '竞对异常诊断', source: 'app/controller/Agent.php', usage_status: 'partial' },
  ],
  ranking: [
    { surface: '竞对异常诊断', source: 'app/controller/Agent.php', usage_status: 'partial' },
  ],
  advertising: [
    { surface: '广告诊断', source: 'app/controller/Agent.php', usage_status: 'not_wired' },
  ],
  quality: [
    { surface: '点评与质量诊断', source: 'app/service/AiDailyReportService.php', usage_status: 'partial' },
  ],
  review: [
    { surface: '点评与质量诊断', source: 'app/service/AiDailyReportService.php', usage_status: 'partial' },
  ],
  search_keyword: [
    { surface: '搜索词诊断', source: 'app/controller/Agent.php', usage_status: 'partial' },
  ],
  traffic_forecast: [
    { surface: '未来AI研判输入', source: 'app/service/AiDailyReportService.php', usage_status: 'partial' },
  ],
  traffic_analysis: [
    { surface: '流量转化诊断', source: 'app/controller/Agent.php', usage_status: 'implemented' },
  ],
  room_type: [
    { surface: '房型产品诊断', source: 'app/service/RevenueAiOverviewService.php', usage_status: 'partial' },
  ],
  platform_identity: [
    { surface: '账户绑定与数据来源', source: 'app/service/OtaLocalCollectorService.php', usage_status: 'implemented' },
  ],
};

function consumerContractsFor(dataType) {
  return (CONSUMER_CONTRACTS_BY_DATA_TYPE[dataType] || [
    { surface: '数据来源与缺口', source: 'app/service/PlatformDataSyncService.php', usage_status: 'implemented' },
  ]).map((consumer) => ({ ...consumer }));
}

// The full catalog is not the default collection scope. These are the only
// sections allowed to run automatically for the verified yesterday loop.
const CORE_COLLECTION_SECTIONS = {
  ctrip: ['business_overview', 'traffic_report'],
  // Prefer the exact-date order summary before traffic.  This keeps the
  // bounded daily plan from stopping after a traffic-only success while the
  // revenue facts required by downstream analysis are still absent.
  meituan: ['orders', 'traffic'],
};

const CORE_COLLECTION_MODULE_IDS = {
  ctrip: ['business_overview', 'traffic_report'],
  meituan: ['business', 'traffic', 'orders'],
};

function collectionPlanDescriptor(platform, moduleId, captureSection, contractStatus) {
  const core = (CORE_COLLECTION_MODULE_IDS[platform] || []).includes(moduleId);
  return {
    priority: core ? 'core' : 'optional',
    primary_path: core ? 'scheduled_yesterday_profile_capture' : 'explicit_gap_or_operator_request',
    fallback: core ? 'targeted_gap_recovery_for_missing_field_keys' : 'keep_gap_visible_without_unbounded_capture',
    stop_condition: core
      ? 'identity_binding + exact_date + raw_save + normalized_save + database_readback + field_facts all verified'
      : 'capture only when an explicit business use and a verified field contract exist',
    execution_status: core && ['contract_closed', 'cataloged'].includes(contractStatus)
      ? 'eligible_when_profile_ready'
      : 'not_default_enabled',
    module_id: moduleId,
  };
}

const STRUCTURED_STORAGE_FIELDS = new Set([
  'hotel_id',
  'hotel_name',
  'data_date',
  'amount',
  'quantity',
  'book_order_num',
  'comment_score',
  'qunar_comment_score',
  'data_value',
  'list_exposure',
  'detail_exposure',
  'flow_rate',
  'order_filling_num',
  'order_submit_num',
]);

function unique(values = []) {
  return [...new Set(values.filter((value) => value !== null && value !== undefined && String(value).trim() !== ''))];
}

function ctripFieldStorage(endpoint, field) {
  const standardRows = buildCtripStandardRowsFromFacts([{
    metric_key: field.id,
    metric_label: field.label,
    value: 1,
    value_type: field.valueType || 'number',
    source_key: field.sourceKeys?.[0] || field.id,
    source_path: `data.${field.sourceKeys?.[0] || field.id}`,
    source_parent_path: 'data',
    endpoint_id: endpoint.id,
    endpoint_label: endpoint.label,
    section: endpoint.section,
    data_type: endpoint.dataType || CTRIP_CAPTURE_SECTIONS[endpoint.section]?.dataType || '',
    platform: 'ctrip',
    data_date: '2000-01-01',
    hotel_id: 'field-map-contract',
    captured_at: '2000-01-01T00:00:00.000Z',
    source_url: 'https://ebooking.ctrip.com/field-map-contract',
  }], {
    hotelId: 'field-map-contract',
    dataDate: '2000-01-01',
  });
  const fieldFact = standardRows[0]?.raw_data?.field_facts?.find(
    (item) => item.metric_key === field.id,
  );
  if (fieldFact?.storage_field) {
    return {
      storage_table: fieldFact.storage_table || 'online_daily_data',
      storage_field: fieldFact.storage_field,
      storage_kind: fieldFact.storage_field_source || 'raw_data_facts',
    };
  }
  return {
    storage_table: 'online_daily_data',
    storage_field: `online_daily_data.raw_data.facts.metric_key=${field.id}`,
    storage_kind: 'raw_data_facts',
  };
}

function buildCtripFieldRows(endpoint) {
  return (endpoint.fields || []).map((field) => {
    const storage = ctripFieldStorage(endpoint, field);
    const column = storage.storage_field.startsWith('online_daily_data.')
      ? storage.storage_field.slice('online_daily_data.'.length)
      : storage.storage_field;
    return {
      metric_key: field.id,
      metric_label: field.label,
      source_keys: unique(field.sourceKeys || []),
      source_key_count: unique(field.sourceKeys || []).length,
      source_path_contract: '真实响应命中后写入 field_facts.source_path；目录阶段不伪造固定路径。',
      scope: field.scope || 'ota_channel',
      unit: field.unit || '',
      value_type: field.valueType || '',
      time_scope: field.timeScope || '',
      required: Boolean(field.required),
      missing_state: field.missingStatus || 'field_missing',
      ...storage,
      structured_column: STRUCTURED_STORAGE_FIELDS.has(column),
      // `hotel_id` is ambiguous between the system key and a platform POI.
      // Every field-fact consumer must use the same exact identity tuple as
      // PlatformDataSyncService, otherwise one platform store can be read
      // back as another hotel's data.
      readback_contract: 'system_hotel_id + platform_hotel_id + data_date + data_period + platform + source + data_source_id + sync_task_id + source_trace_id + readback_verified=1',
      page_uses: UI_USES_BY_DATA_TYPE[
        endpoint.dataType || CTRIP_CAPTURE_SECTIONS[endpoint.section]?.dataType || ''
      ] || ['数据来源与缺口'],
      consumer_contracts: consumerContractsFor(
        endpoint.dataType || CTRIP_CAPTURE_SECTIONS[endpoint.section]?.dataType || '',
      ),
    };
  });
}

function buildCtripModules() {
  const endpointsBySection = new Map();
  for (const endpoint of CTRIP_CAPTURE_ENDPOINTS) {
    const section = endpoint.section || 'unknown';
    const items = endpointsBySection.get(section) || [];
    items.push({
      endpoint_id: endpoint.id,
      endpoint_label: endpoint.label,
      source_match_keywords: unique(endpoint.keywords || []),
      source_status: endpoint.status || 'unverified',
      notes: endpoint.notes || '',
      fields: buildCtripFieldRows(endpoint),
    });
    endpointsBySection.set(section, items);
  }

  return Object.entries(CTRIP_CAPTURE_SECTIONS).map(([sectionId, section]) => {
    const endpoints = endpointsBySection.get(sectionId) || [];
    const fields = endpoints.flatMap((endpoint) => endpoint.fields);
    return {
      module_id: sectionId,
      module_label: section.label,
      data_type: section.dataType,
      time_grain: 'endpoint_declared_or_response_date',
      source_pages: (section.pageUrls || []).map((item) => ({
        url: item.url,
        confidence: item.confidence,
      })),
      endpoint_count: endpoints.length,
      field_occurrence_count: fields.length,
      unique_metric_count: unique(fields.map((field) => field.metric_key)).length,
      source_alias_count: fields.reduce((sum, field) => sum + field.source_key_count, 0),
      storage: COMMON_STORAGE_CONTRACT,
      readback_contract: 'online_daily_data 精确门店/日期/来源回读，field_facts 保留捕获或缺失状态。',
      page_uses: UI_USES_BY_DATA_TYPE[section.dataType] || ['数据来源与缺口'],
      consumer_contracts: consumerContractsFor(section.dataType),
      contract_status: endpoints.length > 0 ? 'cataloged' : 'catalog_gap',
      collection_plan: collectionPlanDescriptor(
        'ctrip',
        sectionId,
        sectionId,
        endpoints.length > 0 ? 'cataloged' : 'catalog_gap',
      ),
      endpoints,
    };
  });
}

function meituanEndpointCatalog(module) {
  const endpointId = `meituan.${module.module_id}.browser_response`;
  return [{
    endpoint_id: endpointId,
    endpoint_label: `${module.module_label}浏览器业务响应`,
    source_match_keywords: unique(module.source_match_keywords || []),
    // A static catalogue must not invent a URL or JSON path that has not been
    // seen in an authorized response. The collector uses these match keywords
    // to locate a response, then persists the actual source_path in field_facts.
    source_status: module.contract_status === 'contract_closed'
      ? 'normalizer_contract_defined'
      : 'runtime_response_confirmation_required',
    fields: (module.source_fields || []).map((sourceField) => ({
      metric_key: sourceField,
      source_field: sourceField,
      source_path_contract: '命中授权浏览器响应后写入 field_facts.source_path；目录不伪造固定JSON路径。',
      storage_table: 'online_daily_data',
      storage_field: `online_daily_data.raw_data.field_facts.metric_key=${sourceField}`,
      readback_contract: 'system_hotel_id + platform_hotel_id + data_date + data_period + source + sync_task_id + readback_verified=1',
      field_fact_status: module.field_fact_contract === 'defined' ? 'contract_defined' : 'contract_missing',
      missing_state: '接口未命中、字段未命中、解析失败、未保存和未回读必须分别呈现。',
      consumer_contracts: consumerContractsFor(module.data_type),
    })),
  }];
}

const MEITUAN_MODULES = [
  {
    module_id: 'business',
    module_label: '经营汇总',
    capture_section: 'traffic',
    data_type: 'business',
    source_match_keywords: ['businessdata'],
    normalizers: ['normalizeMeituanTrafficCardRows'],
    source_fields: ['amount', 'quantity', 'book_order_num', 'data_value'],
    field_fact_contract: 'defined',
    default_enabled: true,
    contract_status: 'contract_closed',
  },
  {
    module_id: 'traffic',
    module_label: '流量漏斗',
    capture_section: 'traffic',
    data_type: 'traffic',
    source_match_keywords: ['traffic', 'weighttraffic', 'flowconversion'],
    normalizers: ['normalizeMeituanTrafficCardRows'],
    source_fields: ['list_exposure', 'detail_exposure', 'flow_rate', 'order_submit_num'],
    field_fact_contract: 'defined',
    default_enabled: true,
    contract_status: 'contract_closed',
  },
  {
    module_id: 'peer_rank',
    module_label: '同行排名',
    capture_section: 'traffic',
    data_type: 'peer_rank',
    source_match_keywords: ['peer/rank', 'peertrends'],
    normalizers: ['normalizeMeituanPeerRankRows'],
    source_fields: ['rank', 'rank_type', 'hotel_name', 'vip_status'],
    field_fact_contract: 'defined',
    default_enabled: true,
    contract_status: 'contract_closed',
  },
  {
    module_id: 'search_keyword',
    module_label: '搜索词',
    capture_section: 'traffic',
    data_type: 'search_keyword',
    source_match_keywords: ['searchkeyword', 'search-keyword'],
    normalizers: ['normalizeMeituanSearchKeywordRows'],
    source_fields: ['keyword', 'exposure', 'clicks'],
    field_fact_contract: 'defined',
    default_enabled: true,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'traffic_forecast',
    module_label: '流量预测',
    capture_section: 'traffic',
    data_type: 'traffic_forecast',
    source_match_keywords: ['flowforecast'],
    normalizers: ['normalizeMeituanTrafficForecastRows'],
    source_fields: ['forecast_type', 'current', 'peer_avg'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'traffic_analysis',
    module_label: '流量分析',
    capture_section: 'traffic',
    data_type: 'traffic_analysis',
    source_match_keywords: ['flowanalysis', 'flowtrend', 'flowtrenddetail'],
    normalizers: ['normalizeMeituanFlowAnalysisRows'],
    source_fields: ['analysis_type', 'data_value', 'peer_rank'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'order_flow',
    module_label: '流失订单',
    capture_section: 'order_flow',
    data_type: 'order_flow',
    source_match_keywords: ['/peerRank/order/loss/query'],
    normalizers: ['normalizeMeituanOrderFlowRows'],
    source_fields: ['order_flow_direction', 'order_flow_period', 'order_count', 'room_nights', 'amount', 'order_ratio'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'orders',
    module_label: '订单汇总',
    capture_section: 'orders',
    data_type: 'order',
    source_match_keywords: ['/api/v1/ebooking/orders', '/order/unhandled/count', '/order-eb/'],
    normalizers: ['normalizeMeituanOrderRows'],
    source_fields: ['book_order_num', 'quantity', 'amount'],
    field_fact_contract: 'defined',
    default_enabled: true,
    contract_status: 'contract_closed',
  },
  {
    module_id: 'advertising',
    module_label: '广告投放',
    capture_section: 'ads',
    data_type: 'advertising',
    source_match_keywords: ['cureshops'],
    normalizers: [],
    source_fields: ['ad_cost', 'ad_impressions', 'ad_clicks', 'ad_orders', 'roas'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'reviews',
    module_label: '点评汇总',
    capture_section: 'reviews',
    data_type: 'review',
    source_match_keywords: ['querygeneralcommentinfo', 'commentsinfo', 'comments/statistics', 'comment-manage'],
    normalizers: [],
    source_fields: ['comment_score', 'quantity', 'tags'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'room_types',
    module_label: '房型产品',
    // The merchant page is shared with traffic, but this is an optional
    // product catalog capture. Keeping a separate section prevents room-type
    // rows from being mislabelled as traffic facts.
    capture_section: 'room_types',
    data_type: 'room_type',
    source_match_keywords: ['roomtype', 'room-type', 'product'],
    normalizers: [],
    source_fields: ['room_type_name', 'price', 'product_status'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
  {
    module_id: 'platform_identity',
    module_label: '平台门店身份',
    capture_section: 'traffic',
    data_type: 'platform_identity',
    source_match_keywords: ['partner_id', 'poi_id'],
    normalizers: [],
    source_fields: ['partner_id', 'poi_id'],
    field_fact_contract: 'defined',
    default_enabled: false,
    contract_status: 'contract_partial',
  },
].map((module) => ({
  ...module,
  platform: 'meituan',
  scope: module.data_type === 'peer_rank' ? 'ota_channel_competition' : 'ota_channel',
  time_grain: ['traffic_forecast'].includes(module.module_id)
    ? 'future_signal'
    : (['traffic', 'peer_rank', 'platform_identity'].includes(module.module_id) ? 'realtime_or_target_date' : 'target_date'),
  collection_plan: collectionPlanDescriptor(
    'meituan',
    module.module_id,
    module.capture_section,
    module.contract_status,
  ),
  storage: COMMON_STORAGE_CONTRACT,
  readback_contract: 'online_daily_data 精确门店/日期/来源/任务回读，raw_data 保留原始字段与缺口。',
  page_uses: UI_USES_BY_DATA_TYPE[module.data_type] || ['数据来源与缺口'],
  consumer_contracts: consumerContractsFor(module.data_type),
  endpoint_catalog: meituanEndpointCatalog(module),
  missing_state: '未命中接口、字段缺失、解析失败、未保存或未回读必须分别标记。',
}));

const CTRIP_LEARNING_SUMMARY = {
  row_count: CTRIP_CORE_METRIC_LEARNING_ROWS.length,
  confirmed_count: CTRIP_CORE_METRIC_LEARNING_ROWS.filter(
    (row) => String(row.confidenceStatus || '').includes('已确认'),
  ).length,
  pending_count: CTRIP_CORE_METRIC_LEARNING_ROWS.filter(
    (row) => !String(row.confidenceStatus || '').includes('已确认'),
  ).length,
};

export const OTA_FIELD_DATA_MAP = {
  schema_version: 'ota-field-data-map.zh-CN.v1',
  generated_from: [
    'scripts/lib/ctrip_capture_catalog.mjs',
    'scripts/lib/ota_capture_standard.mjs',
    'scripts/lib/meituan_browser_capture_normalize.mjs',
    'app/service/PlatformDataSyncService.php',
  ],
  scope: '携程/美团 OTA 渠道字段合同；不是全酒店经营事实。',
  truth_requirements: TRUTH_REQUIREMENTS,
  storage_contract: COMMON_STORAGE_CONTRACT,
  ctrip: {
    catalog_summary: ctripCatalogSummary(),
    learning_summary: CTRIP_LEARNING_SUMMARY,
    modules: buildCtripModules(),
  },
  meituan: {
    capture_config: {
      default_sections: PLATFORM_CONFIGS.meituan.defaultSections,
      full_sections: PLATFORM_CONFIGS.meituan.fullSections,
      allowed_sections: PLATFORM_CONFIGS.meituan.allowedSections,
    },
    modules: MEITUAN_MODULES,
  },
  known_gap_rules: [
    {
      gap_code: 'ctrip_metric_tables_not_wired_to_profile_capture_persistence',
      severity: 'P1',
      evidence_rule: 'ota_ctrip_metric_facts / catalog / capture_runs / capture_gaps 有表结构和读取方，但当前 app 生产代码未发现采集写入方。',
      impact: '字段元数据可能指向专用事实表，而真实 Profile 采集事实主要写入 online_daily_data.raw_data.field_facts，存在双口径风险。',
      action: '确定唯一事实来源：要么接通专用表写入与回读，要么把元数据统一指向 online_daily_data.raw_data.field_facts。',
    },
    {
      gap_code: 'meituan_endpoint_runtime_source_path_pending',
      severity: 'P2',
      evidence_rule: '美团 endpoint -> metric_key -> storage/readback 目录已建立，但部分模块尚无已授权真实响应确认 field_facts.source_path。',
      impact: '目录可约束采集顺序，但未确认的响应路径不能当作真实字段来源。',
      action: '后续按账户、门店和目标日采集真实响应，逐字段写入 source_path 并将状态升级为已确认。',
    },
    {
      gap_code: 'optional_modules_live_response_path_pending',
      severity: 'P1',
      evidence_rule: '搜索词、预测、流量分析、点评和房型已具备字段事实合同；尚未对每个模块取得已授权真实响应路径。',
      impact: '合同能保留缺失状态，但未验证响应不能被标记为已采集事实。',
      action: '按业务优先级逐模块采集真实响应，再确认 source_path、保存和回读。',
    },
    {
      gap_code: 'meituan_ads_reviews_room_types_dedicated_normalizers_missing',
      severity: 'P2',
      evidence_rule: '美团 ads / reviews / room types 主要依赖通用归一化，没有专用 normalizer。',
      impact: '接口变动时更容易静默漏字段。',
      action: '基于真实且已授权的响应样本补专用 normalizer 与缺失状态测试。',
    },
    {
      gap_code: 'ctrip_core_learning_fields_pending_confirmation',
      severity: 'P2',
      evidence_rule: `携程核心学习表仍有 ${CTRIP_LEARNING_SUMMARY.pending_count} 项待真实响应确认。`,
      impact: '这些字段只能算目录存在，不能算业务可用。',
      action: '后续真实采集时按门店/日期逐字段确认 source_path、保存和回读。',
    },
  ],
};

/**
 * Produces the collector order from the field map itself. Core verified
 * business/traffic/order sections come first; optional gaps stay excluded
 * until a task explicitly asks for them, so a full catalog cannot become an
 * uncontrolled full-platform scrape.
 */
export function buildOtaMapCollectionPlan(platform, { includeOptional = false } = {}) {
  const normalized = String(platform || '').trim().toLowerCase();
  if (!['ctrip', 'meituan'].includes(normalized)) throw new Error(`unsupported OTA platform: ${platform}`);
  const sourceModules = normalized === 'ctrip'
    ? OTA_FIELD_DATA_MAP.ctrip.modules.map((module) => ({
      module_id: module.module_id,
      data_type: module.data_type,
      sections: [module.module_id],
      contract_status: module.contract_status,
      required: module.collection_plan?.priority === 'core',
      collection_plan: module.collection_plan,
    }))
    : OTA_FIELD_DATA_MAP.meituan.modules.map((module) => ({
      module_id: module.module_id,
      data_type: module.data_type,
      sections: [module.capture_section],
      contract_status: module.contract_status,
      required: module.collection_plan?.priority === 'core',
      collection_plan: module.collection_plan,
    }));
  const selected = sourceModules.filter((module) => module.required || includeOptional);
  const sectionOrder = CORE_COLLECTION_SECTIONS[normalized] || [];
  const sections = unique(selected.flatMap((module) => module.sections))
    .sort((left, right) => {
      const leftIndex = sectionOrder.indexOf(left);
      const rightIndex = sectionOrder.indexOf(right);
      return (leftIndex === -1 ? Number.MAX_SAFE_INTEGER : leftIndex)
        - (rightIndex === -1 ? Number.MAX_SAFE_INTEGER : rightIndex);
    });
  return {
    version: 'ota_field_map_collection.v1',
    platform: normalized,
    sections,
    modules: selected.map((module) => ({
      module_id: module.module_id,
      data_type: module.data_type,
      contract_status: module.contract_status,
      priority: module.collection_plan?.priority || (module.required ? 'core' : 'optional'),
      primary_path: module.collection_plan?.primary_path || '',
      fallback: module.collection_plan?.fallback || '',
      execution_status: module.collection_plan?.execution_status || '',
    })),
    stop_condition: '核心字段已保存、精确日期回读、身份锚点与字段事实状态均通过；否则只补缺口，不扩抓可选模块。',
  };
}

export function otaFieldDataMapSummary() {
  const ctripModules = OTA_FIELD_DATA_MAP.ctrip.modules;
  const ctripEndpoints = ctripModules.flatMap((module) => module.endpoints);
  const ctripFields = ctripEndpoints.flatMap((endpoint) => endpoint.fields);
  const meituanModules = OTA_FIELD_DATA_MAP.meituan.modules;
  return {
    schema_version: OTA_FIELD_DATA_MAP.schema_version,
    scope: OTA_FIELD_DATA_MAP.scope,
    ctrip: {
      module_count: ctripModules.length,
      endpoint_count: ctripEndpoints.length,
      unique_metric_count: unique(ctripFields.map((field) => field.metric_key)).length,
      source_alias_count: ctripFields.reduce((sum, field) => sum + field.source_key_count, 0),
      learning_pending_count: OTA_FIELD_DATA_MAP.ctrip.learning_summary.pending_count,
    },
    meituan: {
      module_count: meituanModules.length,
      contract_closed_count: meituanModules.filter((module) => module.contract_status === 'contract_closed').length,
      contract_partial_count: meituanModules.filter((module) => module.contract_status === 'contract_partial').length,
      catalog_gap_count: meituanModules.filter((module) => module.contract_status === 'catalog_gap').length,
    },
    known_gap_count: OTA_FIELD_DATA_MAP.known_gap_rules.length,
  };
}
