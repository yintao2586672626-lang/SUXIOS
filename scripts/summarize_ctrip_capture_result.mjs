import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { pathToFileURL } from 'node:url';
import { CTRIP_CAPTURE_ENDPOINTS } from './lib/ctrip_capture_catalog.mjs';
import { buildCtripCaptureAudit } from './lib/ctrip_capture_audit.mjs';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

const ENDPOINT_BY_ID = new Map(CTRIP_CAPTURE_ENDPOINTS.map((endpoint) => [endpoint.id, endpoint]));

const SECTION_LABELS = {
  homepage: '首页实时概览',
  business_overview: '经营报告-概要',
  sales_report: '经营报告-销售数据',
  room_type: '经营报告-房型',
  traffic_report: '经营报告-流量数据',
  competitor_overview: '竞争圈动态-概览',
  loss_analysis: '竞争圈动态-流失分析',
  competitor_rank: '竞争圈动态-竞争圈榜单',
  user_profile: '用户行为-用户分析',
  im_board: '用户行为-IM看板',
  ads_pyramid: '金字塔推广',
  quality_psi: 'PSI服务质量分',
  market_calendar: '热点日历',
  biztravel_bpi: '携程商旅-BPI分',
  biztravel_business_report: '携程商旅-经营报告',
  biztravel_competitor: '携程商旅-竞争圈概况',
};

const METRIC_LABELS = {
  order_count: '预订订单数',
  room_nights: '间夜/在店间夜',
  order_amount: '预订销售额',
  avg_price: '平均卖价/起价',
  occupancy_rate: '出租率',
  tensity: '紧张度',
  visitor_count: '访客量',
  list_exposure: '列表页曝光',
  detail_visitor: '详情页访客',
  order_page_visitor: '订单页访客',
  order_submit_user: '订单提交人数',
  flow_rate: '流量转化率',
  conversion_rate: '成交/下单转化率',
  rank: '竞争圈排名',
  competitor_average: '竞争圈平均值',
  common_view_rate: '共同浏览率',
  loss_order_count: '流失订单数',
  loss_room_nights: '流失间夜',
  loss_order_amount: '流失订单金额',
  psi_score: 'PSI服务质量分',
  base_score: '基础分',
  reward_score: '奖励分',
  deduct_score: '减分项',
  reply_rate: '回复率',
  im_score: 'IM指标',
  five_min_reply_rate: '5分钟回复率',
  manual_reply_rate: '5分钟人工回复率',
  robot_resolution_rate: '机器人解决率',
  im_rank: 'IM竞争圈排名',
  session_count: '会话量',
  manual_session_count: '人工会话量',
  robot_session_count: '机器人会话量',
  im_order_conversion_rate: 'IM客人转化率',
  hotel_collect: '酒店收藏数',
  comment_score_summary: '点评分',
  ad_impressions: '广告曝光',
  ad_clicks: '广告点击',
  ad_cost: '广告花费',
  ad_order_amount: '广告预订金额',
  ad_orders: '广告预订订单',
  ad_room_nights: '广告预订间夜',
  ctr: '点击率',
  cvr: '转化率',
  roas: 'ROAS',
  bpi_score: 'BPI总分',
  basis_score: 'BPI基础分',
  plus_score: 'BPI加分',
  minus_score: 'BPI减分',
  agreement_accept_rate: '协议接单率',
  business_room_nights: '商旅间夜',
  business_amount: '商旅营业额',
  hot_spot_name: '热点名称',
  start_date: '开始日期',
  end_date: '结束日期',
  user_sex: '用户性别',
  user_age: '用户年龄',
  user_source: '客源来源',
  user_type: '用户类型',
  strategy: '提升策略',
  benefit_name: '权益名称',
  notice_title: '公告/提示',
};

const METRIC_GROUPS = [
  ['收益销售', ['order_count', 'room_nights', 'order_amount', 'avg_price', 'occupancy_rate', 'tensity']],
  ['流量转化', ['visitor_count', 'list_exposure', 'detail_visitor', 'order_page_visitor', 'order_submit_user', 'flow_rate', 'conversion_rate']],
  ['竞争圈', ['rank', 'competitor_average', 'common_view_rate', 'loss_order_count', 'loss_room_nights', 'loss_order_amount']],
  ['服务质量/IM', ['psi_score', 'base_score', 'reward_score', 'deduct_score', 'reply_rate', 'five_min_reply_rate', 'manual_reply_rate', 'robot_resolution_rate', 'im_rank', 'session_count', 'manual_session_count', 'robot_session_count', 'im_order_conversion_rate', 'im_score', 'hotel_collect', 'comment_score_summary']],
  ['广告推广', ['ad_impressions', 'ad_clicks', 'ad_cost', 'ad_order_amount', 'ad_orders', 'ad_room_nights', 'ctr', 'cvr', 'roas']],
  ['商旅BPI', ['bpi_score', 'basis_score', 'plus_score', 'minus_score', 'agreement_accept_rate', 'business_room_nights', 'business_amount']],
  ['辅助事实', ['hot_spot_name', 'start_date', 'end_date', 'user_sex', 'user_age', 'user_source', 'user_type', 'strategy', 'benefit_name', 'notice_title']],
];

function parseArgs(argv) {
  const args = {
    input: '',
    output: 'reports/ctrip_capture_summary.json',
    markdown: 'docs/ctrip_capture_summary.md',
  };
  for (const item of argv) {
    if (item.startsWith('--input=')) {
      args.input = item.slice('--input='.length);
    } else if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--markdown=')) {
      args.markdown = item.slice('--markdown='.length);
    } else if (item && !item.startsWith('--') && !args.input) {
      args.input = item;
    }
  }
  return args;
}

function readCapture(path) {
  if (!path) {
    throw new Error('Missing --input=<ctrip_browser_capture.json>');
  }
  if (!existsSync(path)) {
    throw new Error(`input file not found: ${path}`);
  }
  return parseJsonTextSafely(
    readFileSync(path, 'utf8').replace(/^\uFEFF/, ''),
    'ctrip_capture_summary_json',
  );
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function unique(values) {
  return [...new Set(values.map((value) => String(value || '').trim()).filter(Boolean))].sort();
}

function splitMetricKeys(value) {
  return String(value || '')
    .split(/[+,|]/)
    .map((item) => item.trim())
    .filter(Boolean);
}

function metricKeysFromFact(fact) {
  return splitMetricKeys(fact?.metric_key);
}

function metricKeysFromRow(row) {
  const keys = [
    ...splitMetricKeys(row?.metric_key),
    ...splitMetricKeys(metricFromDimension(row?.dimension)),
  ];
  const rawMetrics = row?.raw_data?.metrics;
  if (rawMetrics && typeof rawMetrics === 'object' && !Array.isArray(rawMetrics)) {
    keys.push(...Object.keys(rawMetrics));
  }
  return unique(keys);
}

function sectionFromRow(row) {
  return String(row?.capture_section || row?.section || row?.raw_data?.capture_section || sectionFromDimension(row?.dimension) || 'unknown');
}

function sectionFromDimension(value) {
  const match = String(value || '').match(/^catalog:([^:]+):/);
  return match ? match[1] : '';
}

function metricFromDimension(value) {
  const match = String(value || '').match(/^catalog:[^:]+:[^:]+:([^:]+):/);
  return match ? match[1] : '';
}

function sectionName(section) {
  return SECTION_LABELS[section] || section;
}

function metricLabel(metricKey) {
  return METRIC_LABELS[metricKey] || metricKey;
}

function endpointLabel(endpointId) {
  return endpointId || ENDPOINT_BY_ID.get(endpointId)?.label || '';
}

function metricItems(metricKeys) {
  return metricKeys.map((key) => ({
    key,
    label: metricLabel(key),
  }));
}

function endpointItems(endpointIds) {
  return endpointIds.map((id) => {
    const endpoint = ENDPOINT_BY_ID.get(id);
    return {
      id,
      label: id,
      section: endpoint?.section || '',
      data_type: endpoint?.dataType || '',
    };
  });
}

export function summarizeCapture(payload, inputPath) {
  const responses = Array.isArray(payload.responses) ? payload.responses : [];
  const facts = Array.isArray(payload.catalog_facts) ? payload.catalog_facts : [];
  const rows = Array.isArray(payload.standard_rows) ? payload.standard_rows : [];
  const pages = Array.isArray(payload.pages) ? payload.pages : [];
  const requestedSections = unique(Array.isArray(payload.requested_sections) ? payload.requested_sections : []);
  const audit = buildCtripCaptureAudit([{ path: inputPath, payload }], { generatedAt: payload.captured_at || undefined });
  const observedSections = unique([
    ...requestedSections,
    ...responses.map((item) => item.section),
    ...facts.map((item) => item.section),
    ...rows.map(sectionFromRow),
    ...Object.keys(audit.by_section || {}),
  ]);
  const bySection = {};

  for (const section of observedSections) {
    const sectionResponses = responses.filter((item) => String(item.section || '') === section);
    const sectionFacts = facts.filter((item) => String(item.section || '') === section);
    const sectionRows = rows.filter((item) => sectionFromRow(item) === section);
    const auditSection = audit.by_section?.[section] || {};
    const missingEndpointIds = audit.endpoint_coverage?.sections?.[section]?.missing_endpoint_ids || [];
    const missingFieldIds = audit.field_coverage?.sections?.[section]?.missing_field_ids || [];
    const endpointIds = unique([
      ...sectionResponses.map((item) => item.endpoint_id),
      ...sectionFacts.map((item) => item.endpoint_id),
      ...sectionRows.map((item) => item.endpoint_id),
    ]);
    const metricKeys = unique([
      ...sectionFacts.flatMap(metricKeysFromFact),
      ...sectionRows.flatMap(metricKeysFromRow),
    ]);

    bySection[section] = {
      label: sectionName(section),
      requested: requestedSections.includes(section),
      page_count: pages.filter((page) => String(page.name || page.section || '') === section).length,
      response_count: sectionResponses.length,
      standard_row_count: sectionRows.length,
      catalog_fact_count: sectionFacts.length,
      endpoint_ids: endpointIds,
      endpoints: endpointItems(endpointIds),
      metric_keys: metricKeys,
      metrics: metricItems(metricKeys),
      data_types: unique([
        ...sectionResponses.map((item) => item.data_type),
        ...sectionRows.map((item) => item.data_type),
      ]),
      missing_endpoint_ids: unique(missingEndpointIds),
      missing_field_ids: unique(missingFieldIds).slice(0, 40),
      status: sectionRows.length > 0 || sectionFacts.length > 0
        ? 'captured'
        : sectionResponses.length > 0 || Number(auditSection.response_count || 0) > 0
          ? 'response_only'
          : 'missing_or_not_triggered',
    };
  }

  const allMetrics = unique([
    ...facts.flatMap(metricKeysFromFact),
    ...rows.flatMap(metricKeysFromRow),
  ]);
  const capturedEndpointIds = unique(responses.map((item) => item.endpoint_id).filter(Boolean));

  return {
    source: inputPath,
    generated_at: new Date().toISOString(),
    profile_id: String(payload.profile_id || ''),
    mode: String(payload.mode || 'capture'),
    auth_status: {
      ok: Boolean(payload.auth_status?.ok),
      status: String(payload.auth_status?.status || 'unknown'),
      message: String(payload.auth_status?.message || ''),
    },
    capture_gate: payload.capture_gate || null,
    cookie_injection: {
      attempted: Boolean(payload.cookie_injection?.attempted),
      injected_count: Number(payload.cookie_injection?.injected_count || 0),
      domains: unique(payload.cookie_injection?.domains || []),
    },
    counts: {
      pages: pages.length,
      xhr_urls: Array.isArray(payload.xhr_urls) ? payload.xhr_urls.length : 0,
      responses: responses.length,
      catalog_facts: facts.length,
      standard_rows: rows.length,
      endpoint_candidates: Array.isArray(payload.endpoint_candidates) ? payload.endpoint_candidates.length : 0,
      p3_evidence_drafts: Array.isArray(payload.p3_evidence_drafts) ? payload.p3_evidence_drafts.length : 0,
    },
    diagnosis_groups: METRIC_GROUPS.map(([name, metricIds]) => {
      const captured = metricIds.filter((id) => allMetrics.includes(id));
      return {
        name,
        captured_count: captured.length,
        expected_count: metricIds.length,
        captured_metric_keys: captured,
        captured_metrics: metricItems(captured),
        status: captured.length > 0 ? 'available' : 'missing',
      };
    }),
    sections: bySection,
    captured_endpoint_ids: capturedEndpointIds,
    captured_endpoints: endpointItems(capturedEndpointIds),
    captured_metric_keys: allMetrics,
    captured_metrics: metricItems(allMetrics),
    audit_summary: audit.summary,
    capture_gap_report: audit.capture_gap_report,
  };
}

export function renderMarkdown(summary) {
  const lines = [];
  lines.push('# 携程采集结果摘要');
  lines.push('');
  lines.push(`- 来源：${summary.source}`);
  lines.push(`- Profile：${summary.profile_id || '-'}`);
  lines.push(`- 模式：${summary.mode}`);
  lines.push(`- 登录状态：${summary.auth_status.status}${summary.auth_status.ok ? ' / ok' : ''}`);
  if (summary.auth_status.message) {
    lines.push(`- 登录说明：${summary.auth_status.message}`);
  }
  lines.push(`- Cookie 注入：${summary.cookie_injection.attempted ? `是，注入 ${summary.cookie_injection.injected_count} 条` : '否'}`);
  lines.push(`- 响应数：${summary.counts.responses}`);
  lines.push(`- 标准行：${summary.counts.standard_rows}`);
  lines.push(`- 字段事实：${summary.counts.catalog_facts}`);
  lines.push('');
  lines.push('## 诊断能力');
  lines.push('');
  lines.push('| 方向 | 状态 | 已命中字段 |');
  lines.push('|---|---|---|');
  for (const group of summary.diagnosis_groups) {
    const labels = (group.captured_metrics || []).map((item) => `${item.label} (${item.key})`);
    lines.push(`| ${group.name} | ${group.status} | ${labels.join(', ') || '-'} |`);
  }
  lines.push('');
  lines.push('## 模块命中');
  lines.push('');
  lines.push('| 模块 | 状态 | 接口数 | 标准行 | 字段事实 | 命中接口 | 缺失接口 |');
  lines.push('|---|---|---:|---:|---:|---|---|');
  for (const [section, item] of Object.entries(summary.sections)) {
    const endpointNames = (item.endpoints || []).map((endpoint) => `${endpoint.label} (${endpoint.id})`);
    const missingNames = (item.missing_endpoint_ids || []).map((id) => `${endpointLabel(id)} (${id})`);
    lines.push(`| ${item.label || section} | ${item.status} | ${item.response_count} | ${item.standard_row_count} | ${item.catalog_fact_count} | ${endpointNames.join(', ') || '-'} | ${missingNames.join(', ') || '-'} |`);
  }
  lines.push('');
  lines.push('## 结论');
  lines.push('');
  if (!summary.auth_status.ok) {
    lines.push('- 当前结果不能作为真实业务诊断依据：登录态未通过。');
  } else if (summary.counts.standard_rows <= 0) {
    lines.push('- 已通过登录态，但没有解析到标准经营事实，需要检查页面触发或接口字段映射。');
  } else {
    lines.push('- 已解析到标准经营事实，可以进入携程经营诊断。');
  }
  return `${lines.join('\n')}\n`;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const payload = readCapture(args.input);
  const summary = summarizeCapture(payload, args.input);
  ensureParent(args.output);
  ensureParent(args.markdown);
  writeFileSync(args.output, `${JSON.stringify(summary, null, 2)}\n`, 'utf8');
  writeFileSync(args.markdown, renderMarkdown(summary), 'utf8');
  console.log(JSON.stringify({
    status: summary.auth_status.ok && summary.counts.standard_rows > 0 ? 'ready' : 'not_ready',
    output: args.output,
    markdown: args.markdown,
    auth_status: summary.auth_status,
    counts: summary.counts,
    diagnosis_groups: summary.diagnosis_groups,
  }, null, 2));
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
