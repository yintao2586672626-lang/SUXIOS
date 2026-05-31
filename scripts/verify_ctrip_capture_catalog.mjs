import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import {
  CTRIP_CAPTURE_ENDPOINTS,
  CTRIP_CAPTURE_SECTIONS,
  CTRIP_ENDPOINT_CANDIDATE_RULES,
  buildCtripEndpointCandidates,
  classifyCtripUrl,
  ctripCatalogSummary,
  generateCtripCaptureMarkdown,
  normalizeCtripCaptureSections,
} from './lib/ctrip_capture_catalog.mjs';

function parseArgs(argv) {
  const args = { i18n: '', json: false };
  for (const item of argv) {
    if (item === '--json') {
      args.json = true;
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
    ['https://ebooking.ctrip.com/datacenter/api/getTripartiteOrderLoss', 'loss_analysis'],
    ['https://ebooking.ctrip.com/datacenter/api/getCompetingRank', 'competitor_rank'],
    ['https://ebooking.ctrip.com/userbehavior/getImIndex?hostType=Ebooking', 'im_board'],
    ['https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport', 'ads_pyramid'],
    ['https://ebooking.ctrip.com/psi/api/getHotelPsiV2', 'quality_psi'],
    ['https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo', 'market_calendar'],
    ['https://bbk.ctripbiz.cn/api/searchBpiOverview', 'biztravel_bpi'],
    ['https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail', 'biztravel_business_report'],
    ['https://bbk.ctripbiz.cn/api/dataCenterComparatorReportDetail', 'biztravel_competitor'],
    ['https://bbk.ctripbiz.com/api/searchBpiOverview', 'biztravel_bpi'],
  ];
  for (const [url, expected] of samples) {
    assertContract(classifyCtripUrl(url) === expected, `classify ${url} as ${expected}`);
  }

  assertContract(JSON.stringify(normalizeCtripCaptureSections('business,traffic')) === JSON.stringify(['business_overview', 'traffic_report']), 'legacy business/traffic aliases must work');
  assertContract(normalizeCtripCaptureSections('all').length === Object.keys(CTRIP_CAPTURE_SECTIONS).length, 'all sections must expand');
  assertContract(CTRIP_ENDPOINT_CANDIDATE_RULES.length >= 5, 'P3 candidate rules must cover remaining capture directions');
  assertContract(buildCtripEndpointCandidates([{ url: 'https://bbk.ctripbiz.cn/api/contractPre' }]).some((item) => item.candidate_section === 'contract_mice_rfp'), 'contractPre must remain a P3 candidate until payload is verified');
}

function writeReports(i18nReference) {
  mkdirSync('docs', { recursive: true });
  mkdirSync('reports', { recursive: true });
  const markdown = generateCtripCaptureMarkdown({ i18nReference });
  const summary = {
    ...ctripCatalogSummary(),
    sections: CTRIP_CAPTURE_SECTIONS,
    endpoints: CTRIP_CAPTURE_ENDPOINTS,
    p3_candidate_rules: CTRIP_ENDPOINT_CANDIDATE_RULES,
    i18n_reference: i18nReference,
  };
  writeFileSync(join('docs', 'ctrip_capture_field_inventory.md'), `${markdown}\n`, 'utf8');
  writeFileSync(join('reports', 'ctrip_capture_catalog.json'), `${JSON.stringify(summary, null, 2)}\n`, 'utf8');
  return summary;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  verifyCatalog();
  const i18nReference = inspectI18n(resolveI18nPath(args.i18n));
  const summary = writeReports(i18nReference);
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
