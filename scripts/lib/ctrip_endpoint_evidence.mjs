import {
  CTRIP_ENDPOINT_CANDIDATE_RULES,
  buildCtripStandardRowsFromFacts,
  buildCtripEndpointCandidates,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
} from './ctrip_capture_catalog.mjs';
import { sanitizeOtaPayloadForStorage } from './ota_capture_standard.mjs';

export const CTRIP_ENDPOINT_EVIDENCE_REQUIRED = ['Request URL', 'Payload', 'Preview / Response', 'page/tab context', 'hotel/date parameters'];

export function buildCtripEndpointEvidenceTemplates(options = {}) {
  const generatedAt = options.generatedAt || new Date().toISOString();
  const templates = CTRIP_ENDPOINT_CANDIDATE_RULES.map((rule) => buildCtripEndpointEvidenceTemplate(rule));
  return {
    platform: 'ctrip',
    generated_at: generatedAt,
    status: 'missing_evidence_templates',
    summary: {
      candidate_section_count: templates.length,
      required_evidence: [...CTRIP_ENDPOINT_EVIDENCE_REQUIRED],
    },
    privacy_boundary: [
      '不要保存 Cookie、Authorization、Token、密码、签名、住客姓名、手机号、证件号或完整订单号明文。',
      'Request URL 可保留路径和非敏感查询参数；动态 trace、sid、token 类参数可保留为脱敏占位。',
      'Payload / Response 用于字段映射前必须先经过 validate:ctrip-endpoint-evidence 脱敏校验。',
    ],
    templates,
  };
}

function buildCtripEndpointEvidenceTemplate(rule) {
  return {
    candidate_section: rule.id,
    candidate_label: rule.label,
    priority: rule.priority,
    data_type: rule.dataType,
    evidence_status: 'missing_evidence',
    safe_to_catalog: false,
    devtools_filter_keywords: [...rule.keywords],
    required_evidence: [...CTRIP_ENDPOINT_EVIDENCE_REQUIRED],
    request_template: {
      request_url: '',
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        cookie: '<redacted: do not paste real Cookie into repo>',
        authorization: '<redacted if present>',
      },
      payload: {
        hotelId: '<hotel or node id>',
        startDate: 'YYYY-MM-DD',
        endDate: 'YYYY-MM-DD',
      },
      response: {
        '<copy DevTools Preview or Response JSON here after desensitization>': '',
      },
      page_context: {
        page: '',
        tab: '',
        url: '',
        operation: 'open page, switch tab/date, then capture the XHR/fetch request',
      },
      params: {
        hotel_id: '<system hotel id or OTA hotel id>',
        data_date: 'YYYY-MM-DD',
        start_date: 'YYYY-MM-DD',
        end_date: 'YYYY-MM-DD',
      },
    },
    collection_steps: [
      '打开对应携程或携程商旅后台页面，并确认当前门店是授权门店。',
      `在 DevTools Network 中过滤这些关键词：${rule.keywords.slice(0, 8).join(', ')}。`,
      '触发一次真实查询：切换日期、页签、搜索或滚动加载。',
      '复制 Request URL、method、脱敏 headers、Payload、Preview/Response、页面上下文和酒店/日期参数。',
      '运行 validate:ctrip-endpoint-evidence，状态为 complete_redacted 后再进入字段映射审核。',
    ],
    acceptance_criteria: [
      'request_url 命中候选方向关键词。',
      'payload 或 params 中能确认酒店和日期范围。',
      'response 中包含可解释的业务字段，而不是纯日志、菜单、资源弹窗或埋点。',
      '证据包无 Cookie、Authorization、Token、住客隐私和完整订单号明文。',
    ],
  };
}

export function renderCtripEndpointEvidenceTemplatesMarkdown(templateSet) {
  const lines = [
    '# 携程 P3 接口证据采样模板',
    '',
    `- 生成时间：${templateSet.generated_at || '-'}`,
    `- 候选方向数：${templateSet.summary?.candidate_section_count ?? 0}`,
    `- 必要证据：${(templateSet.summary?.required_evidence || CTRIP_ENDPOINT_EVIDENCE_REQUIRED).join(' / ')}`,
    '',
    '## 安全边界',
    '',
  ];

  for (const item of templateSet.privacy_boundary || []) {
    lines.push(`- ${item}`);
  }

  lines.push('');
  lines.push('## 候选方向');
  lines.push('');
  lines.push('| 候选方向 | 数据类型 | 状态 | 过滤关键词 |');
  lines.push('|---|---|---|---|');
  for (const template of templateSet.templates || []) {
    lines.push(`| ${markdownCell(template.candidate_label)} (${markdownCell(template.candidate_section)}) | ${markdownCell(template.data_type)} | ${markdownCell(template.evidence_status)} | ${markdownCell((template.devtools_filter_keywords || []).slice(0, 8).join(', '))} |`);
  }

  for (const template of templateSet.templates || []) {
    lines.push('');
    lines.push(`## ${template.candidate_label}`);
    lines.push('');
    lines.push(`- 候选ID：${template.candidate_section}`);
    lines.push(`- 优先级：${template.priority}`);
    lines.push(`- 数据类型：${template.data_type}`);
    lines.push(`- 当前状态：${template.evidence_status}`);
    lines.push(`- 可进入正式字段目录：${template.safe_to_catalog ? '是' : '否'}`);
    lines.push('');
    lines.push('### 采样步骤');
    lines.push('');
    for (const step of template.collection_steps || []) {
      lines.push(`- ${step}`);
    }
    lines.push('');
    lines.push('### 验收条件');
    lines.push('');
    for (const rule of template.acceptance_criteria || []) {
      lines.push(`- ${rule}`);
    }
    lines.push('');
    lines.push('### JSON 模板');
    lines.push('');
    lines.push('```json');
    lines.push(JSON.stringify(template.request_template, null, 2));
    lines.push('```');
  }

  lines.push('');
  lines.push('## 使用命令');
  lines.push('');
  lines.push('```powershell');
  lines.push('npm run validate:ctrip-endpoint-evidence -- --input=<endpoint_evidence.json>');
  lines.push('npm run promote:ctrip-mapping-draft -- --input=<endpoint_evidence.json> --output=<approved_mapping.candidate.json>');
  lines.push('```');
  lines.push('');
  lines.push('> 模板只用于采样和校验，不会把 P3 候选接口自动升级为正式字段。');

  return `${lines.join('\n')}\n`;
}

function markdownCell(value) {
  if (value === undefined || value === null || value === '') {
    return '-';
  }
  return String(value).replace(/\|/g, '\\|').replace(/\r?\n/g, '<br>');
}

export function validateCtripEndpointEvidenceBundle(bundle = {}, options = {}) {
  const requestUrl = stringValue(bundle.request_url || bundle.requestUrl || bundle.url);
  const payload = bundle.payload ?? bundle.request_payload ?? bundle.requestPayload ?? null;
  const response = bundle.response ?? bundle.preview ?? bundle.response_preview ?? bundle.responsePreview ?? null;
  const pageContext = bundle.page_context ?? bundle.pageContext ?? {};
  const params = bundle.params ?? bundle.parameters ?? {};

  const formalEndpoint = findCtripEndpointByUrl(requestUrl);
  const candidate = buildCtripEndpointCandidates([{ url: requestUrl }])[0] || null;
  const candidateSection = candidate?.candidate_section || formalEndpoint?.section || '';
  const dataType = candidate?.data_type || formalEndpoint?.dataType || 'business';
  const sanitizerSection = dataType === 'order' ? 'orders' : dataType;

  const missingEvidence = [];
  if (!requestUrl) {
    missingEvidence.push('Request URL');
  }
  if (!hasMeaningfulValue(payload)) {
    missingEvidence.push('Payload');
  }
  if (!hasMeaningfulValue(response)) {
    missingEvidence.push('Preview / Response');
  }
  if (!hasPageContext(pageContext)) {
    missingEvidence.push('page/tab context');
  }
  if (!hasHotelDateParams(params, payload)) {
    missingEvidence.push('hotel/date parameters');
  }

  const responseKeys = collectObjectKeys(response);
  const responsePaths = collectScalarPaths(response);
  const catalogReady = Boolean(requestUrl && candidateSection && missingEvidence.length === 0);
  const fieldMappingDraft = buildEndpointFieldMappingDraft({
    catalogReady,
    candidateSection,
    dataType,
    responsePaths,
  });

  const redactedBundle = {
    request_url: requestUrl,
    method: stringValue(bundle.method || 'GET').toUpperCase(),
    headers: sanitizeOtaPayloadForStorage(bundle.headers || {}, sanitizerSection),
    payload: hasMeaningfulValue(payload) ? sanitizeOtaPayloadForStorage(payload, sanitizerSection) : null,
    response: hasMeaningfulValue(response) ? sanitizeOtaPayloadForStorage(response, sanitizerSection) : null,
    page_context: sanitizeOtaPayloadForStorage(pageContext || {}, sanitizerSection),
    params: sanitizeOtaPayloadForStorage(params || {}, sanitizerSection),
  };
  const catalogPreview = buildCtripCatalogPreviewFromEvidence({
    endpoint: formalEndpoint,
    redactedBundle,
    params,
    payload,
    requestUrl,
    capturedAt: options.capturedAt,
  });

  return {
    platform: 'ctrip',
    generated_at: options.capturedAt || new Date().toISOString(),
    request_url: requestUrl,
    method: redactedBundle.method,
    endpoint_id: formalEndpoint?.id || '',
    endpoint_label: formalEndpoint?.label || '',
    candidate_section: candidateSection,
    candidate_label: candidate?.candidate_label || formalEndpoint?.label || '',
    data_type: dataType,
    evidence_status: catalogReady ? 'complete_redacted' : 'incomplete',
    catalog_ready: catalogReady,
    safe_to_catalog: catalogReady,
    missing_evidence: missingEvidence,
    required_evidence: [...CTRIP_ENDPOINT_EVIDENCE_REQUIRED],
    detected_response_keys: responseKeys,
    detected_response_paths: responsePaths,
    field_mapping_draft: fieldMappingDraft,
    catalog_preview: catalogPreview,
    redacted_bundle: redactedBundle,
  };
}

function buildCtripCatalogPreviewFromEvidence({ endpoint, redactedBundle, params, payload, requestUrl, capturedAt }) {
  const empty = {
    formal_endpoint: Boolean(endpoint),
    catalog_fact_count: 0,
    standard_row_count: 0,
    metric_keys: [],
    standard_rows: [],
  };
  if (!endpoint || !hasMeaningfulValue(redactedBundle.response)) {
    return empty;
  }

  const context = {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: pickEvidenceValue(params, payload, ['hotel_id', 'hotelId', 'masterHotelId', 'nodeId', 'ota_hotel_id']),
    hotelName: pickEvidenceValue(params, payload, ['hotel_name', 'hotelName']),
    systemHotelId: numericEvidenceValue(pickEvidenceValue(params, payload, ['system_hotel_id', 'systemHotelId'])),
    dataDate: pickEvidenceValue(params, payload, ['data_date', 'dataDate', 'date', 'statDate', 'startDate', 'beginDate']),
    capturedAt: stringValue(capturedAt),
    url: requestUrl,
  };
  const facts = extractCtripCatalogFacts(redactedBundle.response, context);
  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: context.systemHotelId,
    hotelName: context.hotelName,
    profileId: context.hotelId,
    dataDate: context.dataDate,
    capturedAt: context.capturedAt,
  });

  return {
    formal_endpoint: true,
    catalog_fact_count: facts.length,
    standard_row_count: rows.length,
    metric_keys: [...new Set(facts.map((fact) => fact.metric_key).filter(Boolean))].sort(),
    standard_rows: rows,
  };
}

export function buildCtripEndpointEvidenceDraftsFromCapture(entries = [], context = {}) {
  const items = (Array.isArray(entries) ? entries : [entries]).filter((item) => item && typeof item === 'object');
  const drafts = [];

  for (const item of items) {
    const requestUrl = stringValue(item.request_url || item.requestUrl || item.url);
    const candidate = buildCtripEndpointCandidates([item])[0] || null;
    if (!requestUrl || !candidate) {
      continue;
    }

    const pageContext = buildCapturePageContext(item, context);
    const params = {
      profile_id: stringValue(context.profileId || item.profile_id || item.profileId),
      hotel_id: stringValue(item.hotel_id || item.hotelId || context.hotelId || context.profileId),
      data_date: stringValue(item.data_date || item.dataDate || context.defaultDataDate || context.dataDate),
      start_date: stringValue(item.start_date || item.startDate || context.startDate || item.payload?.startDate || item.payload?.start_date),
      end_date: stringValue(item.end_date || item.endDate || context.endDate || item.payload?.endDate || item.payload?.end_date),
      ...parseEvidenceDraftObject(item.params || item.parameters || context.params || {}),
    };

    const result = validateCtripEndpointEvidenceBundle({
      request_url: requestUrl,
      method: stringValue(item.method || item.request_method || item.requestMethod || 'GET').toUpperCase(),
      headers: parseEvidenceDraftObject(item.headers || item.request_headers || item.requestHeaders || {}),
      payload: parseEvidenceDraftObject(item.payload ?? item.request_payload ?? item.requestPayload ?? item.post_data ?? item.postData ?? {}),
      response: parseEvidenceDraftObject(item.response ?? item.preview ?? item.response_preview ?? item.responsePreview ?? item.body ?? {}),
      page_context: pageContext,
      params,
    }, {
      capturedAt: item.captured_at || item.capturedAt || context.capturedAt,
    });

    drafts.push({
      ...result,
      capture_source: 'browser_profile_candidate',
      review_required: true,
      safe_to_auto_apply: false,
      source_status: item.status ?? null,
      request_type: stringValue(item.request_type || item.requestType),
      candidate_reason: candidate.reason || '',
    });
  }

  return drafts;
}

function buildCapturePageContext(item, context) {
  const supplied = parseEvidenceDraftObject(item.page_context || item.pageContext || {});
  return {
    source: 'browser_profile',
    page_url: stringValue(item.page_url || item.pageUrl || context.pageUrl),
    active_section: stringValue(item.section || item.capture_section || item.captureSection || context.activeSection),
    module: stringValue(item.section || item.capture_section || item.captureSection || context.activeSection),
    captured_at: stringValue(item.captured_at || item.capturedAt || context.capturedAt),
    ...supplied,
  };
}

function parseEvidenceDraftObject(value) {
  if (value === null || value === undefined || value === '') {
    return {};
  }
  if (Array.isArray(value) || typeof value === 'object') {
    return value;
  }
  if (typeof value !== 'string') {
    return value;
  }

  const raw = value.trim();
  if (!raw) {
    return {};
  }
  if (raw.startsWith('{') || raw.startsWith('[')) {
    try {
      return JSON.parse(raw);
    } catch {
      return { _raw_text: raw.slice(0, 2000) };
    }
  }
  if (raw.includes('=') && !raw.includes('\n')) {
    return Object.fromEntries(new URLSearchParams(raw));
  }
  if (raw.includes(':')) {
    const headers = {};
    for (const line of raw.split(/\r?\n/)) {
      const index = line.indexOf(':');
      if (index <= 0) {
        continue;
      }
      const key = line.slice(0, index).trim();
      if (key) {
        headers[key] = line.slice(index + 1).trim();
      }
    }
    if (Object.keys(headers).length > 0) {
      return headers;
    }
  }
  return { _raw_text: raw.slice(0, 2000) };
}

export function renderCtripEndpointEvidenceMarkdown(result) {
  const lines = [
    '# 携程接口证据包校验',
    '',
    `- 生成时间：${result.generated_at || '-'}`,
    `- Request URL：${result.request_url || '-'}`,
    `- 候选方向：${result.candidate_section || '-'}`,
    `- 数据类型：${result.data_type || '-'}`,
    `- 证据状态：${result.evidence_status || '-'}`,
    `- 可进入字段映射：${result.catalog_ready ? '是' : '否'}`,
    '',
    '## 缺失证据',
    '',
  ];

  if ((result.missing_evidence || []).length === 0) {
    lines.push('- 无');
  } else {
    for (const item of result.missing_evidence) {
      lines.push(`- ${item}`);
    }
  }

  lines.push('');
  lines.push('## 字段映射候选');
  lines.push('');
  lines.push('| 类型 | 内容 |');
  lines.push('|---|---|');
  lines.push(`| response keys | ${(result.detected_response_keys || []).join(', ') || '-'} |`);
  lines.push(`| response paths | ${(result.detected_response_paths || []).slice(0, 80).join(', ') || '-'} |`);
  lines.push('');
  lines.push('### 映射草案');
  lines.push('');
  lines.push('| source path | source key | suggested field | standard row column | privacy | business metric |');
  lines.push('|---|---|---|---|---|---|');
  const draftFields = result.field_mapping_draft?.fields || [];
  if (draftFields.length === 0) {
    lines.push('| - | - | - | - | - | - |');
  } else {
    for (const field of draftFields) {
      lines.push(`| ${field.source_path} | ${field.source_key} | ${field.suggested_field_id} | ${field.standard_row_column || '-'} | ${field.privacy_handling} | ${field.include_in_business_metrics ? 'yes' : 'no'} |`);
    }
  }
  lines.push('');
  lines.push('## 安全边界');
  lines.push('');
  lines.push('- 输出仅保存脱敏后的 headers / payload / response。');
  lines.push('- Cookie、Authorization、Token、密码、签名字段不会进入报告。');
  lines.push('- 订单号、住客姓名、手机号等订单 PII 只保留 hash 或掩码。');

  return `${lines.join('\n')}\n`;
}

export function buildCtripEndpointEvidenceMatrix(results = [], options = {}) {
  const items = (Array.isArray(results) ? results : [results]).filter((item) => item && typeof item === 'object');
  const sections = Object.fromEntries(CTRIP_ENDPOINT_CANDIDATE_RULES.map((rule) => [rule.id, {
    id: rule.id,
    label: rule.label,
    priority: rule.priority,
    data_type: rule.dataType,
    status: 'missing_evidence',
    ready_count: 0,
    incomplete_count: 0,
    bundle_count: 0,
    field_draft_count: 0,
    missing_evidence: [],
    request_urls: [],
  }]));

  for (const result of items) {
    const sectionId = result.candidate_section || 'unknown';
    sections[sectionId] ||= {
      id: sectionId,
      label: result.candidate_label || '',
      priority: '',
      data_type: result.data_type || '',
      status: 'missing_evidence',
      ready_count: 0,
      incomplete_count: 0,
      bundle_count: 0,
      field_draft_count: 0,
      missing_evidence: [],
      request_urls: [],
    };
    const section = sections[sectionId];
    section.bundle_count += 1;
    if (result.request_url) {
      section.request_urls.push(result.request_url);
    }
    if (result.catalog_ready) {
      section.ready_count += 1;
      section.field_draft_count += result.field_mapping_draft?.fields?.length || 0;
      section.status = 'ready_for_review';
    } else {
      section.incomplete_count += 1;
      section.missing_evidence.push(...(result.missing_evidence || []));
      if (section.status !== 'ready_for_review') {
        section.status = 'incomplete_evidence';
      }
    }
  }

  for (const section of Object.values(sections)) {
    section.missing_evidence = [...new Set(section.missing_evidence)].sort();
    section.request_urls = [...new Set(section.request_urls)].sort();
  }

  const readyBundleCount = items.filter((item) => item.catalog_ready).length;
  const missingSections = Object.values(sections)
    .filter((section) => section.status === 'missing_evidence')
    .map((section) => section.id);
  const incompleteSections = Object.values(sections)
    .filter((section) => section.status === 'incomplete_evidence')
    .map((section) => section.id);

  return {
    platform: 'ctrip',
    generated_at: options.generatedAt || new Date().toISOString(),
    summary: {
      total_bundles: items.length,
      ready_bundle_count: readyBundleCount,
      incomplete_bundle_count: items.length - readyBundleCount,
      ready_section_count: Object.values(sections).filter((section) => section.status === 'ready_for_review').length,
      missing_section_count: missingSections.length,
      incomplete_section_count: incompleteSections.length,
    },
    sections,
    missing_sections: missingSections,
    incomplete_sections: incompleteSections,
    next_actions: buildMatrixNextActions(missingSections, incompleteSections),
  };
}

export function renderCtripEndpointEvidenceMatrixMarkdown(matrix) {
  const summary = matrix.summary || {};
  const lines = [
    '# 携程 P3 接口证据覆盖',
    '',
    `- 生成时间：${matrix.generated_at || '-'}`,
    `- 证据包数量：${summary.total_bundles ?? 0}`,
    `- 完整证据包：${summary.ready_bundle_count ?? 0}`,
    `- 不完整证据包：${summary.incomplete_bundle_count ?? 0}`,
    `- 已可审核方向：${summary.ready_section_count ?? 0}`,
    `- 缺证据方向：${summary.missing_section_count ?? 0}`,
    '',
    '## P3 证据覆盖矩阵',
    '',
    '| 候选方向 | 状态 | 完整证据包 | 不完整证据包 | 字段草案数 | 缺失证据 |',
    '|---|---|---:|---:|---:|---|',
  ];

  for (const section of Object.values(matrix.sections || {})) {
    lines.push(`| ${section.id} | ${section.status} | ${section.ready_count || 0} | ${section.incomplete_count || 0} | ${section.field_draft_count || 0} | ${(section.missing_evidence || []).join(', ') || '-'} |`);
  }

  lines.push('');
  lines.push('## 下一步');
  lines.push('');
  for (const item of matrix.next_actions || []) {
    lines.push(`- ${item}`);
  }
  return `${lines.join('\n')}\n`;
}

function hasMeaningfulValue(value) {
  if (value === null || value === undefined || value === '') {
    return false;
  }
  if (Array.isArray(value)) {
    return value.length > 0;
  }
  if (typeof value === 'object') {
    return Object.keys(value).length > 0;
  }
  return true;
}

function hasPageContext(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  return Boolean(stringValue(value.page || value.tab || value.module || value.title));
}

function hasHotelDateParams(params, payload) {
  const merged = { ...(payload && typeof payload === 'object' ? payload : {}), ...(params && typeof params === 'object' ? params : {}) };
  const keys = Object.keys(merged).reduce((result, key) => {
    result[String(key).toLowerCase()] = merged[key];
    return result;
  }, {});
  const hasHotel = ['hotel_id', 'hotelid', 'master_hotel_id', 'masterhotelid', 'node_id', 'nodeid', 'ota_hotel_id', 'supplierid'].some((key) => hasMeaningfulValue(keys[key]));
  const hasDate = ['data_date', 'date', 'statdate', 'start_date', 'startdate', 'end_date', 'enddate', 'begindate', 'begin_date', 'fromdate', 'from_date', 'todate', 'to_date'].some((key) => hasMeaningfulValue(keys[key]));
  return hasHotel && hasDate;
}

function pickEvidenceValue(params, payload, keys) {
  const sources = [params, payload].filter((item) => item && typeof item === 'object' && !Array.isArray(item));
  for (const key of keys) {
    const normalizedKey = String(key).toLowerCase();
    for (const source of sources) {
      for (const [sourceKey, value] of Object.entries(source)) {
        if (String(sourceKey).toLowerCase() === normalizedKey && hasMeaningfulValue(value)) {
          return stringValue(value);
        }
      }
    }
  }
  return '';
}

function numericEvidenceValue(value) {
  const text = stringValue(value);
  if (!text) {
    return null;
  }
  const number = Number(text);
  return Number.isFinite(number) ? number : null;
}

function collectObjectKeys(value, result = new Set()) {
  if (!value || typeof value !== 'object') {
    return [...result].sort();
  }
  if (Array.isArray(value)) {
    for (const item of value) {
      collectObjectKeys(item, result);
    }
    return [...result].sort();
  }
  for (const [key, child] of Object.entries(value)) {
    result.add(key);
    collectObjectKeys(child, result);
  }
  return [...result].sort();
}

function collectScalarPaths(value, path = [], result = []) {
  if (result.length >= 500) {
    return result;
  }
  if (Array.isArray(value)) {
    value.forEach((item, index) => collectScalarPaths(item, [...path, String(index)], result));
    return result;
  }
  if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      collectScalarPaths(child, [...path, key], result);
    }
    return result;
  }
  if (path.length > 0) {
    result.push(path.join('.'));
  }
  return [...new Set(result)].sort();
}

function buildEndpointFieldMappingDraft({ catalogReady, candidateSection, dataType, responsePaths }) {
  if (!catalogReady) {
    return {
      status: 'incomplete_evidence',
      ready_for_mapping: false,
      safe_to_auto_apply: false,
      candidate_section: candidateSection || '',
      data_type: dataType || '',
      fields: [],
    };
  }

  const fields = [];
  const seen = new Set();
  for (const path of responsePaths || []) {
    const sourceKey = lastNonIndexPathPart(path);
    if (!sourceKey || seen.has(`${path}:${sourceKey}`)) {
      continue;
    }
    seen.add(`${path}:${sourceKey}`);
    fields.push(buildFieldDraft(path, sourceKey, dataType));
  }

  return {
    status: 'draft_pending_review',
    ready_for_mapping: true,
    safe_to_auto_apply: false,
    candidate_section: candidateSection || '',
    data_type: dataType || '',
    fields,
  };
}

function buildFieldDraft(sourcePath, sourceKey, dataType) {
  const hint = fieldHintForSourceKey(sourceKey, dataType);
  return {
    source_path: sourcePath,
    source_key: sourceKey,
    suggested_field_id: hint.id,
    suggested_label: hint.label,
    standard_row_column: hint.standardRowColumn,
    value_kind: hint.valueKind,
    privacy_handling: hint.privacyHandling,
    include_in_business_metrics: hint.includeInBusinessMetrics,
    mapping_status: 'draft_pending_review',
  };
}

function fieldHintForSourceKey(sourceKey, dataType) {
  const key = String(sourceKey || '').toLowerCase();
  if (/order.*id|orderid|booking.*id|bookingid|reservation.*id/.test(key)) {
    return fieldHint('order_id', '订单ID', '', 'identifier', 'hash', false);
  }
  if (/guest.*name|customer.*name|contact.*name|username|user_name/.test(key)) {
    return fieldHint('guest_name', '住客姓名', '', 'text', 'mask', false);
  }
  if (/guest.*phone|mobile|phone|telephone|tel/.test(key)) {
    return fieldHint('guest_phone', '住客手机号', '', 'text', 'mask', false);
  }
  if (/idcard|identity|certificate|passport/.test(key)) {
    return fieldHint('guest_identity', '住客证件', '', 'text', 'drop', false);
  }
  if (/room.*night|roomnights|night.*count|quantity|countnight/.test(key)) {
    return fieldHint('room_nights', '间夜量', 'quantity', 'number', 'none', true);
  }
  if (/order.*count|ordercount|order.*num|ordernum|book.*order|booking.*count/.test(key)) {
    return fieldHint('order_count', '订单数', 'book_order_num', 'number', 'none', true);
  }
  if (/order.*amount|total.*amount|amount|sale.*amount|settle.*amount|bill.*amount/.test(key)) {
    return fieldHint(dataType === 'finance' ? 'settlement_amount' : 'order_amount', dataType === 'finance' ? '结算金额' : '订单金额', 'amount', 'number', 'none', true);
  }
  if (/avg.*price|average.*price|sell.*price|price/.test(key)) {
    return fieldHint('avg_price', '均价/价格', 'data_value', 'number', 'none', true);
  }
  if (/order.*status|status|state/.test(key)) {
    return fieldHint('order_status', '订单状态', '', 'enum', 'none', false);
  }
  if (/checkin|arrival|startdate|begin.*date/.test(key)) {
    return fieldHint('checkin_date', '入住/开始日期', '', 'date', 'none', false);
  }
  if (/checkout|departure|enddate|leave.*date/.test(key)) {
    return fieldHint('checkout_date', '离店/结束日期', '', 'date', 'none', false);
  }
  return fieldHint(toSnakeCase(sourceKey), sourceKey, '', 'unknown', 'none', true);
}

function fieldHint(id, label, standardRowColumn, valueKind, privacyHandling, includeInBusinessMetrics) {
  return {
    id,
    label,
    standardRowColumn,
    valueKind,
    privacyHandling,
    includeInBusinessMetrics,
  };
}

function lastNonIndexPathPart(path) {
  const parts = String(path || '').split('.').filter((part) => part && !/^\d+$/.test(part));
  return parts[parts.length - 1] || '';
}

function toSnakeCase(value) {
  return String(value || '')
    .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
    .replace(/[^a-zA-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .toLowerCase() || 'unknown_field';
}

function buildMatrixNextActions(missingSections, incompleteSections) {
  const actions = [];
  for (const section of missingSections) {
    actions.push(`${section}: 还没有证据包，需补 Request URL / Payload / Preview-Response / 页面上下文 / 酒店日期参数。`);
  }
  for (const section of incompleteSections) {
    actions.push(`${section}: 已有证据包但不完整，先补齐 missing_evidence 后再进入字段映射审核。`);
  }
  if (actions.length === 0) {
    actions.push('所有 P3 候选方向已有完整证据包，下一步人工审核 field_mapping_draft 后转正式目录规则。');
  }
  return actions;
}

function stringValue(value) {
  return String(value ?? '').trim();
}
