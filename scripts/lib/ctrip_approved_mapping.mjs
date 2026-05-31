import { createHash } from 'node:crypto';

export function normalizeCtripApprovedMappings(config = {}) {
  const entries = Array.isArray(config) ? config : (config.mappings || []);
  return entries
    .filter((mapping) => mapping && typeof mapping === 'object' && mapping.approved === true)
    .map((mapping) => ({
      id: String(mapping.id || '').trim(),
      candidate_section: String(mapping.candidate_section || mapping.section || '').trim(),
      data_type: String(mapping.data_type || mapping.dataType || 'business').trim() || 'business',
      url_keywords: normalizeStringList(mapping.url_keywords || mapping.urlKeywords || mapping.keywords),
      fields: normalizeApprovedFields(mapping.fields || []),
    }))
    .filter((mapping) => mapping.id && mapping.candidate_section && mapping.url_keywords.length > 0 && mapping.fields.length > 0);
}

export function extractCtripApprovedMappingRows(payload = {}, context = {}) {
  const mappings = Array.isArray(context.mappings) ? context.mappings : normalizeCtripApprovedMappings(context.mappings || []);
  const url = String(context.url || '').toLowerCase();
  const rows = [];

  for (const mapping of mappings) {
    if (!mapping.url_keywords.some((keyword) => url.includes(keyword.toLowerCase()))) {
      continue;
    }
    rows.push(...extractRowsForMapping(payload, mapping, context));
  }

  return rows;
}

export function buildCtripApprovedMappingCandidateFromEvidence(evidenceResult = {}, options = {}) {
  const draft = evidenceResult.field_mapping_draft || {};
  const fields = Array.isArray(draft.fields) ? draft.fields : [];
  const mappingId = String(options.mappingId || suggestedMappingId(evidenceResult)).trim();
  const endpointName = endpointNameFromUrl(evidenceResult.request_url || '');

  return {
    version: 1,
    platform: 'ctrip',
    generated_at: options.generatedAt || new Date().toISOString(),
    status: 'review_required',
    source_request_url: evidenceResult.request_url || '',
    note: 'Generated from field_mapping_draft. Review every field and set mapping approved=true before use.',
    mappings: [{
      id: mappingId,
      approved: false,
      candidate_section: evidenceResult.candidate_section || draft.candidate_section || '',
      data_type: evidenceResult.data_type || draft.data_type || 'business',
      url_keywords: endpointName ? [endpointName] : [],
      review_required: true,
      source_evidence_status: evidenceResult.evidence_status || '',
      fields: fields.map((field) => ({
        approved: false,
        source_path: generalizeArrayIndexesInPath(field.source_path || ''),
        source_key: field.source_key || '',
        suggested_field_id: field.suggested_field_id || '',
        suggested_label: field.suggested_label || '',
        standard_row_column: field.standard_row_column || '',
        value_kind: field.value_kind || '',
        privacy_handling: field.privacy_handling || 'none',
        include_in_business_metrics: field.include_in_business_metrics !== false,
        mapping_status: 'review_required',
      })),
    }],
  };
}

export function buildCtripApprovedMappingCandidatesFromCapture(inputs = [], options = {}) {
  const normalizedInputs = normalizeCaptureInputs(inputs);
  const generatedAt = options.generatedAt || new Date().toISOString();
  const mappingIdPrefix = String(options.mappingIdPrefix || '').trim();
  const mappings = [];
  const seenIds = new Set();
  let evidenceDraftCount = 0;
  let readyEvidenceCount = 0;

  for (const input of normalizedInputs) {
    const drafts = Array.isArray(input.payload?.p3_evidence_drafts) ? input.payload.p3_evidence_drafts : [];
    evidenceDraftCount += drafts.length;
    for (const draft of drafts) {
      if (!isReadyP3EvidenceDraft(draft)) {
        continue;
      }
      const baseId = suggestedMappingId(draft);
      const mappingId = mappingIdPrefix ? `${mappingIdPrefix}_${baseId}` : baseId;
      if (seenIds.has(mappingId)) {
        continue;
      }
      const candidate = buildCtripApprovedMappingCandidateFromEvidence(draft, {
        generatedAt,
        mappingId,
      });
      const mapping = candidate.mappings?.[0];
      if (!mapping) {
        continue;
      }
      mapping.source_capture_path = input.path || '';
      mappings.push(mapping);
      seenIds.add(mappingId);
      readyEvidenceCount += 1;
    }
  }

  return {
    version: 1,
    platform: 'ctrip',
    generated_at: generatedAt,
    status: 'review_required',
    source: 'ctrip_profile_p3_evidence_drafts',
    note: 'Generated from complete_redacted Profile P3 evidence drafts. Review every mapping and field, then set approved=true before use.',
    summary: {
      capture_count: normalizedInputs.length,
      evidence_draft_count: evidenceDraftCount,
      ready_evidence_count: readyEvidenceCount,
      skipped_draft_count: evidenceDraftCount - readyEvidenceCount,
      mapping_count: mappings.length,
    },
    source_capture_files: normalizedInputs.map((input) => input.path || '').filter(Boolean),
    mappings,
  };
}

export function buildCtripApprovedMappingDryRun({ evidence = {}, mappingConfig = {}, generatedAt = '' } = {}) {
  const mappings = normalizeCtripApprovedMappings(mappingConfig);
  const response = evidence.response ?? evidence.preview ?? evidence.response_preview ?? evidence.responsePreview ?? {};
  const requestUrl = String(evidence.request_url || evidence.requestUrl || evidence.url || '');
  const params = evidence.params || evidence.parameters || {};
  const payload = evidence.payload || evidence.request_payload || evidence.requestPayload || {};
  const rows = extractCtripApprovedMappingRows(response, {
    url: requestUrl,
    mappings,
    hotelId: pickFirst(params.hotel_id, params.hotelId, payload.hotelId, payload.hotel_id),
    hotelName: pickFirst(params.hotel_name, params.hotelName, payload.hotelName, payload.hotel_name),
    systemHotelId: params.system_hotel_id ?? params.systemHotelId ?? null,
    dataDate: pickFirst(params.data_date, params.dataDate, payload.dataDate, payload.date, payload.startDate, payload.beginDate),
    capturedAt: generatedAt || new Date().toISOString(),
  });

  return {
    platform: 'ctrip',
    generated_at: generatedAt || new Date().toISOString(),
    status: rows.length > 0 ? 'pass' : 'no_rows',
    request_url: requestUrl,
    summary: {
      approved_mapping_count: mappings.length,
      matched_mapping_count: new Set(rows.map((row) => row.raw_data?.mapping_id).filter(Boolean)).size,
      standard_row_count: rows.length,
    },
    standard_rows: rows,
    notes: rows.length > 0
      ? ['Dry-run only. Rows are not inserted into online_daily_data.']
      : ['No rows extracted. Check approved mapping URL keywords and source paths.'],
  };
}

function normalizeCaptureInputs(inputs) {
  const list = Array.isArray(inputs) ? inputs : [inputs];
  return list
    .map((item) => {
      if (!item) {
        return null;
      }
      if (item.payload) {
        return { path: item.path || '', payload: item.payload };
      }
      return { path: '', payload: item };
    })
    .filter(Boolean);
}

function isReadyP3EvidenceDraft(draft) {
  if (!draft || typeof draft !== 'object') {
    return false;
  }
  const status = String(draft.evidence_status || '').trim();
  const fields = Array.isArray(draft.field_mapping_draft?.fields) ? draft.field_mapping_draft.fields : [];
  return (draft.catalog_ready === true || status === 'complete_redacted')
    && draft.field_mapping_draft?.ready_for_mapping !== false
    && fields.length > 0;
}

export function renderCtripApprovedMappingDryRunMarkdown(result) {
  const lines = [
    '# 携程已审核映射 Dry Run',
    '',
    `- 生成时间：${result.generated_at || '-'}`,
    `- Request URL：${result.request_url || '-'}`,
    `- 状态：${result.status || '-'}`,
    `- 已审核映射数：${result.summary?.approved_mapping_count ?? 0}`,
    `- 命中映射数：${result.summary?.matched_mapping_count ?? 0}`,
    `- 标准行数：${result.summary?.standard_row_count ?? 0}`,
    '',
    '## 标准行预览',
    '',
    '| mapping | data_type | amount | quantity | dimension |',
    '|---|---|---:|---:|---|',
  ];
  for (const row of result.standard_rows || []) {
    lines.push(`| ${row.raw_data?.mapping_id || '-'} | ${row.data_type || '-'} | ${row.amount || 0} | ${row.quantity || 0} | ${row.dimension || '-'} |`);
  }
  if (!result.standard_rows || result.standard_rows.length === 0) {
    lines.push('| - | - | 0 | 0 | - |');
  }
  lines.push('');
  lines.push('## 注意');
  lines.push('');
  for (const note of result.notes || []) {
    lines.push(`- ${note}`);
  }
  lines.push('- Dry-run 输出不包含订单号、住客姓名、手机号明文。');
  return `${lines.join('\n')}\n`;
}

function normalizeApprovedFields(fields) {
  return (Array.isArray(fields) ? fields : [])
    .filter((field) => field && typeof field === 'object' && field.approved !== false)
    .map((field) => ({
      source_path: String(field.source_path || field.sourcePath || '').trim(),
      source_key: String(field.source_key || field.sourceKey || '').trim(),
      suggested_field_id: String(field.suggested_field_id || field.field_id || field.id || '').trim(),
      standard_row_column: String(field.standard_row_column || field.standardRowColumn || '').trim(),
      privacy_handling: String(field.privacy_handling || field.privacyHandling || 'none').trim().toLowerCase() || 'none',
      include_in_business_metrics: field.include_in_business_metrics !== false && field.includeInBusinessMetrics !== false,
    }))
    .filter((field) => field.source_path && field.suggested_field_id);
}

function extractRowsForMapping(payload, mapping, context) {
  const grouped = new Map();
  for (const field of mapping.fields) {
    const values = extractPathValues(payload, field.source_path);
    for (const item of values) {
      const groupPath = parentPath(item.path);
      if (!grouped.has(groupPath)) {
        grouped.set(groupPath, []);
      }
      grouped.get(groupPath).push({ field, value: item.value, path: item.path });
    }
  }

  const rows = [];
  let index = 0;
  for (const [groupPath, values] of grouped.entries()) {
    const row = buildBaseRow(mapping, context, groupPath, index);
    for (const item of values) {
      applyApprovedField(row, item.field, item.value);
    }
    if (hasApprovedMetric(row)) {
      rows.push(row);
      index += 1;
    }
  }
  return rows;
}

function buildBaseRow(mapping, context, groupPath, index) {
  return {
    source: 'ctrip',
    platform: 'ctrip',
    hotel_id: String(context.hotelId || context.profileId || ''),
    hotel_name: String(context.hotelName || ''),
    system_hotel_id: context.systemHotelId ?? context.system_hotel_id ?? null,
    data_date: String(context.dataDate || context.defaultDataDate || ''),
    data_type: mapping.data_type,
    capture_section: mapping.candidate_section,
    endpoint_id: `approved:${mapping.id}`,
    endpoint_label: mapping.id,
    dimension: `approved:${mapping.candidate_section}:${mapping.id}:${safeDimensionPart(groupPath || `row_${index}`)}`,
    amount: 0,
    quantity: 0,
    book_order_num: 0,
    comment_score: 0,
    qunar_comment_score: 0,
    data_value: 0,
    list_exposure: 0,
    detail_exposure: 0,
    flow_rate: 0,
    order_filling_num: 0,
    order_submit_num: 0,
    raw_data: {
      source: 'ctrip_approved_mapping',
      mapping_id: mapping.id,
      capture_section: mapping.candidate_section,
      source_url: String(context.url || ''),
      source_group_path: groupPath,
      captured_at: String(context.capturedAt || ''),
      fields: {},
    },
  };
}

function applyApprovedField(row, field, value) {
  const fieldId = field.suggested_field_id;
  if (field.privacy_handling === 'hash') {
    const text = String(value ?? '').trim();
    if (text) {
      row.raw_data.fields[`${fieldId}_hash`] = createHash('sha256').update(`ctrip_p3|${text}`).digest('hex');
    }
    return;
  }
  if (field.privacy_handling === 'mask') {
    const masked = maskSensitiveValue(value, fieldId);
    if (masked) {
      row.raw_data.fields[`${fieldId}_masked`] = masked;
    }
    return;
  }
  if (field.privacy_handling === 'drop') {
    return;
  }

  row.raw_data.fields[fieldId] = value;
  if (!field.include_in_business_metrics) {
    return;
  }

  const number = numericValue(value);
  switch (field.standard_row_column) {
    case 'amount':
      row.amount = number ?? 0;
      break;
    case 'quantity':
      row.quantity = Math.round(number ?? 0);
      break;
    case 'book_order_num':
      row.book_order_num = Math.round(number ?? 0);
      break;
    case 'data_value':
      row.data_value = number ?? 0;
      break;
    case 'list_exposure':
      row.list_exposure = Math.round(number ?? 0);
      break;
    case 'detail_exposure':
      row.detail_exposure = Math.round(number ?? 0);
      break;
    case 'flow_rate':
      row.flow_rate = number ?? 0;
      break;
    case 'order_filling_num':
      row.order_filling_num = Math.round(number ?? 0);
      break;
    case 'order_submit_num':
      row.order_submit_num = Math.round(number ?? 0);
      break;
    default:
      if (number !== null && row.data_value === 0) {
        row.data_value = number;
      }
      break;
  }
}

function extractPathValues(root, sourcePath) {
  const parts = String(sourcePath || '').split('.').filter(Boolean);
  const results = [];
  const walk = (node, index, path) => {
    if (index >= parts.length) {
      results.push({ path: path.join('.'), value: node });
      return;
    }
    const part = parts[index];
    if (part === '*') {
      if (!Array.isArray(node)) {
        return;
      }
      node.forEach((item, itemIndex) => walk(item, index + 1, [...path, String(itemIndex)]));
      return;
    }
    if (!node || typeof node !== 'object' || !(part in node)) {
      return;
    }
    walk(node[part], index + 1, [...path, part]);
  };
  walk(root, 0, []);
  return results;
}

function hasApprovedMetric(row) {
  return Number(row.amount || 0) !== 0
    || Number(row.quantity || 0) !== 0
    || Number(row.book_order_num || 0) !== 0
    || Number(row.data_value || 0) !== 0
    || Number(row.list_exposure || 0) !== 0
    || Number(row.detail_exposure || 0) !== 0
    || Number(row.flow_rate || 0) !== 0
    || Number(row.order_filling_num || 0) !== 0
    || Number(row.order_submit_num || 0) !== 0;
}

function normalizeStringList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item || '').trim()).filter(Boolean);
  }
  return String(value || '').split(/[,\s]+/).map((item) => item.trim()).filter(Boolean);
}

function pickFirst(...values) {
  for (const value of values) {
    const text = String(value ?? '').trim();
    if (text) {
      return text;
    }
  }
  return '';
}

function numericValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  const text = String(value ?? '').replace(/[,￥¥%]/g, '').trim();
  if (!text || !Number.isFinite(Number(text))) {
    return null;
  }
  return Number(text);
}

function maskSensitiveValue(value, fieldId) {
  const text = String(value ?? '').trim();
  if (!text) {
    return '';
  }
  if (/phone|mobile|tel/i.test(fieldId)) {
    return `${'*'.repeat(Math.max(text.length - 4, 0))}${text.slice(-4)}`;
  }
  return `${text.slice(0, 1)}***`;
}

function parentPath(path) {
  const parts = String(path || '').split('.').filter(Boolean);
  parts.pop();
  return parts.join('.');
}

function generalizeArrayIndexesInPath(path) {
  return String(path || '').split('.').map((part) => (/^\d+$/.test(part) ? '*' : part)).join('.');
}

function safeDimensionPart(value) {
  return String(value || 'root').replace(/[^a-zA-Z0-9_.:-]+/g, '_').slice(0, 80);
}

function suggestedMappingId(evidenceResult) {
  const section = String(evidenceResult.candidate_section || 'ctrip_p3').trim() || 'ctrip_p3';
  const endpoint = endpointNameFromUrl(evidenceResult.request_url || '') || 'endpoint';
  return `${section}_${endpoint}`.replace(/[^a-zA-Z0-9_:-]+/g, '_').toLowerCase().slice(0, 80);
}

function endpointNameFromUrl(url) {
  try {
    const parsed = new URL(url);
    const parts = parsed.pathname.split('/').filter(Boolean);
    return parts[parts.length - 1] || '';
  } catch {
    return String(url || '').split(/[/?#]/).filter(Boolean).pop() || '';
  }
}
