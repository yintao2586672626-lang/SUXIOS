import {
  CTRIP_ENDPOINT_CANDIDATE_RULES,
  CTRIP_CAPTURE_ENDPOINTS,
  buildCtripEndpointCandidates,
  normalizeCtripCaptureSections,
} from './ctrip_capture_catalog.mjs';
import { buildCtripEndpointEvidenceMatrix } from './ctrip_endpoint_evidence.mjs';

export const CTRIP_CAPTURE_AUDIT_EVIDENCE = ['Request URL', 'Payload', 'Preview / Response', 'page/tab context', 'hotel/date parameters'];
const FIELD_COVERAGE_CONTEXT_FIELD_IDS = new Set(['hotel_id', 'hotel_name', 'date']);

export function buildCtripCaptureAudit(inputs = [], options = {}) {
  const normalizedInputs = normalizeAuditInputs(inputs);
  const bySection = {};
  const uniqueMetrics = new Set();
  const capturedFieldsBySection = {};
  const capturedEndpoints = new Set();
  const candidateByKey = new Map();
  const p3EvidenceDrafts = [];
  const interactionsBySection = {};
  const requestedSections = new Set();

  let responseCount = 0;
  let catalogFactCount = 0;
  let standardRowCount = 0;
  let unmatchedUrlCount = 0;
  let pageCount = 0;
  let interactionPlannedCount = 0;
  let interactionClickedCount = 0;
  let interactionSkippedCount = 0;
  let interactionErrorCount = 0;
  let loginPageCount = 0;
  const loginPageUrls = new Set();

  for (const input of normalizedInputs) {
    const payload = input.payload || {};
    const pages = Array.isArray(payload.pages) ? payload.pages : [];
    const responses = Array.isArray(payload.responses) ? payload.responses : [];
    const facts = Array.isArray(payload.catalog_facts) ? payload.catalog_facts : [];
    const rows = Array.isArray(payload.standard_rows) ? payload.standard_rows : [];
    const unmatched = Array.isArray(payload.unmatched_xhr_urls) ? payload.unmatched_xhr_urls : [];
    const drafts = Array.isArray(payload.p3_evidence_drafts) ? payload.p3_evidence_drafts : [];

    for (const section of expandRequestedSections(payload.requested_sections)) {
      requestedSections.add(section);
    }
    responseCount += responses.length;
    catalogFactCount += facts.length;
    standardRowCount += rows.length;
    unmatchedUrlCount += unmatched.length;
    p3EvidenceDrafts.push(...drafts);
    pageCount += pages.length;

    for (const page of pages) {
      const section = sectionFromValue(page?.name || page?.section);
      const pageUrl = String(page?.url || '').trim();
      if (isCtripLoginUrl(pageUrl)) {
        loginPageCount += 1;
        loginPageUrls.add(pageUrl);
      }
      const interactions = Array.isArray(page?.interactions) ? page.interactions : [];
      bumpInteractionPage(interactionsBySection, section);
      for (const interaction of interactions) {
        const stats = ensureInteractionSection(interactionsBySection, section);
        const actionText = String(interaction?.text || interaction?.action || '').trim() || 'unknown';
        stats.planned_count += 1;
        interactionPlannedCount += 1;
        if (interaction?.clicked === true) {
          stats.clicked_count += 1;
          stats.clicked_actions.add(actionText);
          interactionClickedCount += 1;
          continue;
        }
        if (interaction?.error) {
          stats.error_count += 1;
          stats.missed_actions.add(actionText);
          stats.skipped_reasons.add(`error:${String(interaction.error).slice(0, 80)}`);
          interactionErrorCount += 1;
          continue;
        }
        stats.skipped_count += 1;
        stats.missed_actions.add(actionText);
        stats.skipped_reasons.add(String(interaction?.skipped || 'not_clicked'));
        interactionSkippedCount += 1;
      }
    }

    for (const response of responses) {
      const section = sectionFromValue(response?.section);
      const endpointId = String(response?.endpoint_id || '').trim();
      bumpSection(bySection, section, 'response_count');
      if (endpointId) {
        capturedEndpoints.add(endpointId);
        addSectionValue(bySection, section, 'endpoint_ids', endpointId);
      }
    }

    for (const fact of facts) {
      const section = sectionFromValue(fact?.section);
      const metricKey = String(fact?.metric_key || '').trim();
      bumpSection(bySection, section, 'catalog_fact_count');
      if (metricKey) {
        uniqueMetrics.add(metricKey);
        addCapturedField(capturedFieldsBySection, section, metricKey);
        addSectionValue(bySection, section, 'metric_keys', metricKey);
      }
      const endpointId = String(fact?.endpoint_id || '').trim();
      if (endpointId) {
        capturedEndpoints.add(endpointId);
        addSectionValue(bySection, section, 'endpoint_ids', endpointId);
      }
    }

    for (const row of rows) {
      const section = sectionFromValue(row?.capture_section || row?.raw_data?.capture_section || sectionFromDimension(row?.dimension));
      const metricKey = String(row?.metric_key || fieldFromDimension(row?.dimension) || '').trim();
      if (metricKey) {
        uniqueMetrics.add(metricKey);
        addCapturedField(capturedFieldsBySection, section, metricKey);
        addSectionValue(bySection, section, 'metric_keys', metricKey);
      }
      bumpSection(bySection, section, 'standard_row_count');
    }

    for (const candidate of [
      ...normalizeCandidates(payload.endpoint_candidates),
      ...buildCtripEndpointCandidates(unmatched),
    ]) {
      const key = candidate.canonical_url || candidate.url || JSON.stringify(candidate);
      if (!candidateByKey.has(key)) {
        candidateByKey.set(key, candidate);
      }
    }
  }

  const groupedCandidates = buildCandidateGroups([...candidateByKey.values()]);
  const candidateCount = [...candidateByKey.values()].length;
  const pendingCandidateSectionCount = Object.values(groupedCandidates).filter((group) => group.count > 0).length;
  const generatedAt = options.generatedAt || new Date().toISOString();
  const p3EvidenceMatrix = buildCtripEndpointEvidenceMatrix(p3EvidenceDrafts, { generatedAt });
  const endpointCoverage = buildEndpointCoverage([...requestedSections], capturedEndpoints);
  const fieldCoverage = buildFieldCoverage([...requestedSections], capturedFieldsBySection);
  const authStatus = buildAuthStatus({
    pageCount,
    loginPageCount,
    loginPageUrls: [...loginPageUrls],
    responseCount,
    standardRowCount,
  });
  const summary = {
    input_file_count: normalizedInputs.length,
    response_count: responseCount,
    catalog_fact_count: catalogFactCount,
    unique_metric_count: uniqueMetrics.size,
    standard_row_count: standardRowCount,
    unmatched_xhr_url_count: unmatchedUrlCount,
    formal_endpoint_count: capturedEndpoints.size,
    endpoint_candidate_count: candidateCount,
    pending_candidate_section_count: pendingCandidateSectionCount,
    requested_section_count: endpointCoverage.summary.requested_section_count,
    expected_endpoint_count: endpointCoverage.summary.expected_endpoint_count,
    captured_catalog_endpoint_count: endpointCoverage.summary.captured_endpoint_count,
    missing_catalog_endpoint_count: endpointCoverage.summary.missing_endpoint_count,
    expected_field_count: fieldCoverage.summary.expected_field_count,
    captured_catalog_field_count: fieldCoverage.summary.captured_field_count,
    missing_catalog_field_count: fieldCoverage.summary.missing_field_count,
    auth_login_required_count: authStatus.status === 'login_required' ? 1 : 0,
    page_count: pageCount,
    interaction_planned_count: interactionPlannedCount,
    interaction_clicked_count: interactionClickedCount,
    interaction_skipped_count: interactionSkippedCount,
    interaction_error_count: interactionErrorCount,
    interaction_section_count: Object.keys(interactionsBySection).length,
    p3_evidence_draft_count: p3EvidenceMatrix.summary.total_bundles,
    p3_evidence_ready_count: p3EvidenceMatrix.summary.ready_bundle_count,
    p3_evidence_incomplete_count: p3EvidenceMatrix.summary.incomplete_bundle_count,
    p3_evidence_ready_section_count: p3EvidenceMatrix.summary.ready_section_count,
  };

  return {
    platform: 'ctrip',
    generated_at: generatedAt,
    input_files: normalizedInputs.map((input) => input.path || '').filter(Boolean),
    summary,
    by_section: finalizeSectionStats(bySection),
    interactions_by_section: finalizeInteractionStats(interactionsBySection),
    endpoint_coverage: endpointCoverage,
    field_coverage: fieldCoverage,
    auth_status: authStatus,
    captured_endpoint_ids: [...capturedEndpoints].sort(),
    metric_keys: [...uniqueMetrics].sort(),
    endpoint_candidates: groupedCandidates,
    p3_evidence_matrix: p3EvidenceMatrix,
    capture_gap_report: buildCaptureGapReport({
      summary,
      authStatus,
      endpointCoverage,
      fieldCoverage,
      endpointCandidates: groupedCandidates,
      p3EvidenceMatrix,
    }),
    next_evidence_required: candidateCount > 0 ? [...CTRIP_CAPTURE_AUDIT_EVIDENCE] : [],
  };
}

export function evaluateCtripCaptureAuditGate(audit = {}, options = {}) {
  const summary = audit.summary || {};
  const coverage = audit.endpoint_coverage?.summary || {};
  const fieldCoverage = audit.field_coverage?.summary || {};
  const minFieldCoverageRate = numberOption(options.minFieldCoverageRate, null);
  const configuredMaxMissingFields = numberOption(options.maxMissingFields, null);
  const requireFieldCoverageFlag = options.requireFieldCoverage === true;
  const requireFieldCoverage = requireFieldCoverageFlag
    || minFieldCoverageRate !== null
    || configuredMaxMissingFields !== null;
  const thresholds = {
    require_auth_session: options.requireAuthSession !== false,
    min_response_count: numberOption(options.minResponseCount, 1),
    min_standard_rows: numberOption(options.minStandardRows, 1),
    require_endpoint_coverage: options.requireEndpointCoverage !== false,
    require_expected_endpoints: options.requireExpectedEndpoints !== false,
    max_missing_endpoints: numberOption(options.maxMissingEndpoints, 0),
    require_field_coverage: requireFieldCoverage,
    min_field_coverage_rate: minFieldCoverageRate,
    max_missing_fields: configuredMaxMissingFields ?? (requireFieldCoverageFlag && minFieldCoverageRate === null ? 0 : null),
  };
  const checks = [];
  const authStatus = String(audit.auth_status?.status || 'unknown');
  if (thresholds.require_auth_session) {
    addGateCheck(checks, {
      id: 'auth_session',
      passed: ['logged_in', 'ok_or_unverified'].includes(authStatus),
      actual: authStatus,
      expected: 'logged_in or ok_or_unverified',
      message: 'Capture must not be a Ctrip login page or partial login redirect.',
    });
  }

  const responseCount = numberValue(summary.response_count);
  addGateCheck(checks, {
    id: 'response_count',
    passed: responseCount >= thresholds.min_response_count,
    actual: responseCount,
    expected: `>=${thresholds.min_response_count}`,
    message: 'Capture must include business XHR/fetch responses.',
  });

  const standardRowCount = numberValue(summary.standard_row_count);
  addGateCheck(checks, {
    id: 'standard_rows',
    passed: standardRowCount >= thresholds.min_standard_rows,
    actual: standardRowCount,
    expected: `>=${thresholds.min_standard_rows}`,
    message: 'Capture must produce rows that can feed SUXIOS OTA analytics.',
  });

  if (thresholds.require_endpoint_coverage) {
    const expectedEndpointCount = numberValue(coverage.expected_endpoint_count ?? summary.expected_endpoint_count);
    const capturedEndpointCount = numberValue(coverage.captured_endpoint_count ?? summary.captured_catalog_endpoint_count);
    const missingEndpointCount = numberValue(coverage.missing_endpoint_count ?? summary.missing_catalog_endpoint_count);
    const hasExpectedEndpoints = !thresholds.require_expected_endpoints || expectedEndpointCount > 0;
    addGateCheck(checks, {
      id: 'endpoint_coverage',
      passed: hasExpectedEndpoints && missingEndpointCount <= thresholds.max_missing_endpoints,
      actual: `${capturedEndpointCount}/${expectedEndpointCount}`,
      expected: `missing<=${thresholds.max_missing_endpoints}`,
      message: hasExpectedEndpoints
        ? 'Requested Ctrip sections must hit their cataloged formal endpoints.'
        : 'Requested sections did not resolve to any cataloged formal endpoint.',
    });
  }

  if (thresholds.require_field_coverage) {
    const expectedFieldCount = numberValue(fieldCoverage.expected_field_count ?? summary.expected_field_count);
    const capturedFieldCount = numberValue(fieldCoverage.captured_field_count ?? summary.captured_catalog_field_count);
    const missingFieldCount = numberValue(fieldCoverage.missing_field_count ?? summary.missing_catalog_field_count);
    const rawCoverageRate = fieldCoverage.coverage_rate ?? (
      expectedFieldCount > 0 ? Math.round((capturedFieldCount / expectedFieldCount) * 10000) / 100 : null
    );
    const fieldCoverageRate = rawCoverageRate === null || rawCoverageRate === undefined
      ? null
      : numberValue(rawCoverageRate);
    const hasExpectedFields = expectedFieldCount > 0;
    const meetsCoverageRate = thresholds.min_field_coverage_rate === null
      || fieldCoverageRate >= thresholds.min_field_coverage_rate;
    const meetsMissingFieldLimit = thresholds.max_missing_fields === null
      || missingFieldCount <= thresholds.max_missing_fields;
    addGateCheck(checks, {
      id: 'field_coverage',
      passed: hasExpectedFields && meetsCoverageRate && meetsMissingFieldLimit,
      actual: fieldCoverageRate === null ? 'n/a' : `${fieldCoverageRate}%`,
      expected: [
        thresholds.min_field_coverage_rate === null ? '' : `>=${thresholds.min_field_coverage_rate}%`,
        thresholds.max_missing_fields === null ? '' : `missing<=${thresholds.max_missing_fields}`,
      ].filter(Boolean).join('; ') || 'expected fields captured',
      message: hasExpectedFields
        ? 'Requested Ctrip sections must extract the configured catalog fields.'
        : 'Requested sections did not resolve to any cataloged fields.',
    });
  }

  const failedChecks = checks.filter((check) => check.status === 'fail');
  return {
    status: failedChecks.length > 0 ? 'fail' : 'pass',
    failed: failedChecks.length > 0,
    failed_check_ids: failedChecks.map((check) => check.id),
    thresholds,
    checks,
  };
}

export function renderCtripCaptureAuditMarkdown(audit) {
  const summary = audit.summary || {};
  const lines = [
    '# 携程采集结果审计',
    '',
    `- 生成时间：${audit.generated_at || '-'}`,
    `- 输入文件数：${summary.input_file_count ?? 0}`,
    `- 已归档接口响应数：${summary.response_count ?? 0}`,
    `- 已抽取字段事实数：${summary.catalog_fact_count ?? 0}`,
    `- 可入库标准行数：${summary.standard_row_count ?? 0}`,
    `- 正式接口覆盖：${summary.captured_catalog_endpoint_count ?? 0}/${summary.expected_endpoint_count ?? 0}`,
    `- 字段覆盖：${summary.captured_catalog_field_count ?? 0}/${summary.expected_field_count ?? 0}`,
    `- 登录状态：${audit.auth_status?.status || 'unknown'}`,
    `- 未归档接口候选数：${summary.endpoint_candidate_count ?? 0}`,
    `- 页面交互触发：${summary.interaction_clicked_count ?? 0}/${summary.interaction_planned_count ?? 0}`,
    `- 页面交互未触发/异常：${summary.interaction_skipped_count ?? 0}/${summary.interaction_error_count ?? 0}`,
    `- P3证据草稿数：${summary.p3_evidence_draft_count ?? 0}`,
    `- P3完整证据数：${summary.p3_evidence_ready_count ?? 0}`,
    '',
    '## 已归档模块覆盖',
    '',
    '| 模块 | 响应数 | 字段事实数 | 标准行数 | 已命中接口 | 已命中字段 |',
    '|---|---:|---:|---:|---|---|',
  ];

  if (audit.capture_gate) {
    lines.push('');
    lines.push('## Capture Gate');
    lines.push('');
    lines.push(`- Status: ${audit.capture_gate.status || 'unknown'}`);
    lines.push(`- Failed checks: ${(audit.capture_gate.failed_check_ids || []).join(', ') || '-'}`);
    lines.push('');
    lines.push('| Check | Status | Actual | Expected | Message |');
    lines.push('|---|---|---|---|---|');
    for (const check of audit.capture_gate.checks || []) {
      lines.push(`| ${check.id || '-'} | ${check.status || '-'} | ${check.actual ?? '-'} | ${check.expected ?? '-'} | ${check.message || '-'} |`);
    }
  }

  if (audit.capture_gap_report) {
    const report = audit.capture_gap_report;
    lines.push('');
    lines.push('## capture_gap_report');
    lines.push('');
    lines.push(`- Status: ${report.status || 'unknown'}`);
    lines.push(`- Blockers: ${(report.blockers || []).join(', ') || '-'}`);
    lines.push(`- Missing formal endpoints: ${(report.missing_formal_endpoints || []).length}`);
    lines.push(`- P3 candidate sections: ${Object.keys(report.p3_candidate_sections || {}).join(', ') || '-'}`);
    lines.push('');
    lines.push('| Action | Section | Endpoint/Field | Reason |');
    lines.push('|---|---|---|---|');
    for (const action of report.next_actions || []) {
      lines.push(`| ${action.action || '-'} | ${action.section || action.candidate_section || '-'} | ${action.endpoint_id || action.field_ids?.join(', ') || '-'} | ${action.reason || '-'} |`);
    }
  }

  for (const [section, stats] of Object.entries(audit.by_section || {})) {
    lines.push(`| ${section} | ${stats.response_count || 0} | ${stats.catalog_fact_count || 0} | ${stats.standard_row_count || 0} | ${(stats.endpoint_ids || []).join(', ') || '-'} | ${(stats.metric_keys || []).join(', ') || '-'} |`);
  }

  lines.push('');
  lines.push('## 登录状态');
  lines.push('');
  lines.push(`- 状态：${audit.auth_status?.status || 'unknown'}`);
  lines.push(`- 登录页数量：${audit.auth_status?.login_page_count ?? 0}`);
  if ((audit.auth_status?.login_page_urls || []).length > 0) {
    for (const url of audit.auth_status.login_page_urls.slice(0, 5)) {
      lines.push(`- 登录页：${url}`);
    }
  }

  lines.push('');
  lines.push('## 正式接口覆盖');
  lines.push('');
  lines.push('| 模块 | 预期接口 | 已命中 | 缺失 | 缺失接口 |');
  lines.push('|---|---:|---:|---:|---|');
  const coverageSections = Object.entries(audit.endpoint_coverage?.sections || {});
  if (coverageSections.length === 0) {
    lines.push('| - | 0 | 0 | 0 | - |');
  } else {
    for (const [section, stats] of coverageSections) {
      lines.push(`| ${section} | ${stats.expected_endpoint_count || 0} | ${stats.captured_endpoint_count || 0} | ${stats.missing_endpoint_count || 0} | ${(stats.missing_endpoint_ids || []).join(', ') || '-'} |`);
    }
  }

  lines.push('');
  lines.push('## 字段覆盖');
  lines.push('');
  lines.push('| 模块 | 预期字段 | 已命中 | 缺失 | 缺失字段 |');
  lines.push('|---|---:|---:|---:|---|');
  const fieldCoverageSections = Object.entries(audit.field_coverage?.sections || {});
  if (fieldCoverageSections.length === 0) {
    lines.push('| - | 0 | 0 | 0 | - |');
  } else {
    for (const [section, stats] of fieldCoverageSections) {
      lines.push(`| ${section} | ${stats.expected_field_count || 0} | ${stats.captured_field_count || 0} | ${stats.missing_field_count || 0} | ${(stats.missing_field_ids || []).slice(0, 30).join(', ') || '-'} |`);
    }
  }

  lines.push('');
  lines.push('## 页面交互触发覆盖');
  lines.push('');
  lines.push('| 模块 | 页面数 | 计划动作 | 已点击 | 未点击 | 异常 | 未触发动作 |');
  lines.push('|---|---:|---:|---:|---:|---:|---|');
  const interactionSections = Object.entries(audit.interactions_by_section || {});
  if (interactionSections.length === 0) {
    lines.push('| - | 0 | 0 | 0 | 0 | 0 | - |');
  } else {
    for (const [section, stats] of interactionSections) {
      lines.push(`| ${section} | ${stats.page_count || 0} | ${stats.planned_count || 0} | ${stats.clicked_count || 0} | ${stats.skipped_count || 0} | ${stats.error_count || 0} | ${(stats.missed_actions || []).join(', ') || '-'} |`);
    }
  }

  lines.push('');
  lines.push('## 未归档接口候选');
  lines.push('');
  lines.push('| 候选方向 | 数量 | 状态 | 需要补充 | 样例接口 |');
  lines.push('|---|---:|---|---|---|');
  for (const group of Object.values(audit.endpoint_candidates || {})) {
    const sample = group.items?.[0]?.url || '-';
    const required = (group.required_evidence || CTRIP_CAPTURE_AUDIT_EVIDENCE).join(' / ');
    lines.push(`| ${group.id} ${group.label ? `(${group.label})` : ''} | ${group.count || 0} | ${group.evidence_status || 'needs_payload_response'} | ${required} | ${sample} |`);
  }

  lines.push('');
  lines.push('## P3 证据草稿覆盖');
  lines.push('');
  lines.push('| 候选方向 | 状态 | 完整证据 | 不完整证据 | 字段草案 | 缺失证据 |');
  lines.push('|---|---|---:|---:|---:|---|');
  const p3Sections = Object.values(audit.p3_evidence_matrix?.sections || {});
  if (p3Sections.length === 0) {
    lines.push('| - | missing_evidence | 0 | 0 | 0 | - |');
  } else {
    for (const section of p3Sections) {
      lines.push(`| ${section.id} | ${section.status} | ${section.ready_count || 0} | ${section.incomplete_count || 0} | ${section.field_draft_count || 0} | ${(section.missing_evidence || []).join(', ') || '-'} |`);
    }
  }

  lines.push('');
  lines.push('## 下一步证据');
  lines.push('');
  if ((audit.next_evidence_required || []).length === 0) {
    lines.push('- 暂无未归档候选接口。');
  } else {
    for (const item of audit.next_evidence_required) {
      lines.push(`- ${item}`);
    }
  }

  return `${lines.join('\n')}\n`;
}

function addGateCheck(checks, { id, passed, actual, expected, message }) {
  checks.push({
    id,
    status: passed ? 'pass' : 'fail',
    actual,
    expected,
    message,
  });
}

function numberOption(value, fallback) {
  if (value === null || value === undefined || value === '') {
    return fallback;
  }
  const number = Number(value);
  return Number.isFinite(number) ? number : fallback;
}

function numberValue(value) {
  const number = Number(value);
  return Number.isFinite(number) ? number : 0;
}

function normalizeAuditInputs(inputs) {
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

function normalizeCandidates(value) {
  return Array.isArray(value) ? value.filter((item) => item && typeof item === 'object') : [];
}

function expandRequestedSections(value) {
  if (!value) {
    return [];
  }
  const raw = Array.isArray(value) ? value.join(',') : String(value);
  try {
    return normalizeCtripCaptureSections(raw);
  } catch {
    return raw.split(/[,\s]+/)
      .map((item) => sectionFromValue(item))
      .filter((item) => item && item !== 'unknown');
  }
}

function buildEndpointCoverage(requestedSections, capturedEndpoints) {
  const selectedSections = [...new Set((requestedSections || []).filter(Boolean))].sort();
  const expected = CTRIP_CAPTURE_ENDPOINTS.filter((endpoint) => selectedSections.includes(endpoint.section));
  const sections = {};
  for (const section of selectedSections) {
    const expectedForSection = expected.filter((endpoint) => endpoint.section === section);
    const capturedForSection = expectedForSection.filter((endpoint) => capturedEndpoints.has(endpoint.id));
    const missingForSection = expectedForSection.filter((endpoint) => !capturedEndpoints.has(endpoint.id));
    sections[section] = {
      expected_endpoint_count: expectedForSection.length,
      captured_endpoint_count: capturedForSection.length,
      missing_endpoint_count: missingForSection.length,
      captured_endpoint_ids: capturedForSection.map((endpoint) => endpoint.id).sort(),
      missing_endpoint_ids: missingForSection.map((endpoint) => endpoint.id).sort(),
      missing_endpoints: missingForSection.map((endpoint) => ({
        id: endpoint.id,
        label: endpoint.label,
        status: endpoint.status,
        data_type: endpoint.dataType,
      })).sort((a, b) => a.id.localeCompare(b.id)),
    };
  }
  const capturedExpected = expected.filter((endpoint) => capturedEndpoints.has(endpoint.id));
  return {
    summary: {
      requested_section_count: selectedSections.length,
      expected_endpoint_count: expected.length,
      captured_endpoint_count: capturedExpected.length,
      missing_endpoint_count: Math.max(0, expected.length - capturedExpected.length),
      coverage_rate: expected.length > 0 ? Math.round((capturedExpected.length / expected.length) * 10000) / 100 : null,
    },
    requested_sections: selectedSections,
    sections,
  };
}

function buildFieldCoverage(requestedSections, capturedFieldsBySection) {
  const selectedSections = [...new Set((requestedSections || []).filter(Boolean))].sort();
  const sections = {};
  const expectedFieldUnion = new Set();
  const capturedFieldUnion = new Set();
  const missingFieldUnion = new Set();

  for (const section of selectedSections) {
    const expectedFieldIds = uniqueSorted(CTRIP_CAPTURE_ENDPOINTS
      .filter((endpoint) => endpoint.section === section)
      .flatMap((endpoint) => endpoint.fields || [])
      .map((field) => String(field?.id || '').trim())
      .filter((fieldId) => fieldId && !FIELD_COVERAGE_CONTEXT_FIELD_IDS.has(fieldId)));
    const capturedFieldIds = uniqueSorted([...(capturedFieldsBySection[section] || [])]
      .filter((fieldId) => expectedFieldIds.includes(fieldId)));
    const unexpectedFieldIds = uniqueSorted([...(capturedFieldsBySection[section] || [])]
      .filter((fieldId) => fieldId && !FIELD_COVERAGE_CONTEXT_FIELD_IDS.has(fieldId) && !expectedFieldIds.includes(fieldId)));
    const missingFieldIds = expectedFieldIds.filter((fieldId) => !capturedFieldIds.includes(fieldId));

    for (const fieldId of expectedFieldIds) {
      expectedFieldUnion.add(fieldId);
    }
    for (const fieldId of capturedFieldIds) {
      capturedFieldUnion.add(fieldId);
    }
    for (const fieldId of missingFieldIds) {
      missingFieldUnion.add(fieldId);
    }

    sections[section] = {
      expected_field_count: expectedFieldIds.length,
      captured_field_count: capturedFieldIds.length,
      missing_field_count: missingFieldIds.length,
      unexpected_field_count: unexpectedFieldIds.length,
      captured_field_ids: capturedFieldIds,
      missing_field_ids: missingFieldIds,
      unexpected_field_ids: unexpectedFieldIds,
    };
  }

  return {
    summary: {
      requested_section_count: selectedSections.length,
      expected_field_count: expectedFieldUnion.size,
      captured_field_count: capturedFieldUnion.size,
      missing_field_count: [...expectedFieldUnion].filter((fieldId) => !capturedFieldUnion.has(fieldId)).length,
      coverage_rate: expectedFieldUnion.size > 0 ? Math.round((capturedFieldUnion.size / expectedFieldUnion.size) * 10000) / 100 : null,
    },
    requested_sections: selectedSections,
    sections,
  };
}

function buildAuthStatus({ pageCount, loginPageCount, loginPageUrls, responseCount, standardRowCount }) {
  const urls = [...new Set(loginPageUrls || [])].sort();
  if (loginPageCount > 0 && responseCount === 0 && standardRowCount === 0) {
    return {
      status: 'login_required',
      login_page_count: loginPageCount,
      page_count: pageCount,
      login_page_urls: urls,
      message: 'Ctrip capture reached login pages and did not capture business responses.',
    };
  }
  if (loginPageCount > 0) {
    return {
      status: 'partial_login_redirect',
      login_page_count: loginPageCount,
      page_count: pageCount,
      login_page_urls: urls,
      message: 'Some Ctrip pages redirected to login; inspect missing sections.',
    };
  }
  return {
    status: pageCount > 0 ? 'ok_or_unverified' : 'no_pages',
    login_page_count: 0,
    page_count: pageCount,
    login_page_urls: [],
    message: pageCount > 0 ? 'No login page detected in captured page URLs.' : 'No page evidence in capture payload.',
  };
}

function buildCaptureGapReport({
  summary = {},
  authStatus = {},
  endpointCoverage = {},
  fieldCoverage = {},
  endpointCandidates = {},
  p3EvidenceMatrix = {},
} = {}) {
  const blockers = [];
  const nextActions = [];
  const authStatusValue = String(authStatus.status || 'unknown');

  if (authStatusValue === 'login_required' || authStatusValue === 'partial_login_redirect') {
    blockers.push('auth_session');
    nextActions.push({
      action: 'login_and_rerun_capture',
      reason: authStatusValue,
      section: '',
      endpoint_id: '',
      required_evidence: ['logged-in browser profile'],
    });
  }
  if (numberValue(summary.response_count) === 0) {
    blockers.push('response_count');
    nextActions.push({
      action: 'capture_business_xhr',
      reason: 'response_count_zero',
      section: '',
      endpoint_id: '',
      required_evidence: ['business XHR/fetch response'],
    });
  }
  if (numberValue(summary.standard_row_count) === 0) {
    blockers.push('standard_rows');
    nextActions.push({
      action: 'verify_standard_row_mapping',
      reason: 'standard_row_count_zero',
      section: '',
      endpoint_id: '',
      required_evidence: ['catalog_facts or approved P3 mapping rows'],
    });
  }

  const missingFormalEndpoints = [];
  for (const [section, stats] of Object.entries(endpointCoverage.sections || {})) {
    for (const endpoint of stats.missing_endpoints || []) {
      missingFormalEndpoints.push({
        section,
        id: endpoint.id,
        label: endpoint.label,
        status: endpoint.status,
        data_type: endpoint.data_type,
      });
      if (nextActions.length < 120) {
        nextActions.push({
          action: 'capture_missing_formal_endpoint',
          reason: 'endpoint_coverage_missing',
          section,
          endpoint_id: endpoint.id,
          required_evidence: ['Request URL', 'Payload', 'Preview / Response'],
        });
      }
    }
  }

  const missingFieldsBySection = {};
  for (const [section, stats] of Object.entries(fieldCoverage.sections || {})) {
    const missingFieldIds = stats.missing_field_ids || [];
    if (missingFieldIds.length === 0) {
      continue;
    }
    missingFieldsBySection[section] = {
      expected_field_count: stats.expected_field_count || 0,
      captured_field_count: stats.captured_field_count || 0,
      missing_field_count: stats.missing_field_count || missingFieldIds.length,
      missing_field_ids: missingFieldIds,
    };
    if (nextActions.length < 120) {
      nextActions.push({
        action: 'capture_missing_fields',
        reason: 'field_coverage_missing',
        section,
        field_ids: missingFieldIds.slice(0, 40),
        required_evidence: ['source path', 'Preview / Response'],
      });
    }
  }

  const p3CandidateSections = {};
  for (const [section, group] of Object.entries(endpointCandidates || {})) {
    if (!group || numberValue(group.count) <= 0) {
      continue;
    }
    p3CandidateSections[section] = {
      count: numberValue(group.count),
      priority: group.priority || '',
      data_type: group.data_type || group.dataType || '',
      evidence_status: group.evidence_status || 'needs_payload_response',
      required_evidence: group.required_evidence || [...CTRIP_CAPTURE_AUDIT_EVIDENCE],
      sample_urls: (group.items || []).slice(0, 5).map((item) => item.url).filter(Boolean),
    };
    if (nextActions.length < 120) {
      nextActions.push({
        action: 'collect_p3_devtools_evidence',
        reason: 'p3_candidate_needs_evidence',
        candidate_section: section,
        required_evidence: group.required_evidence || [...CTRIP_CAPTURE_AUDIT_EVIDENCE],
      });
    }
  }

  const p3EvidenceSections = {};
  for (const section of Object.values(p3EvidenceMatrix.sections || {})) {
    if (!section || section.status === 'ready_for_review') {
      continue;
    }
    p3EvidenceSections[section.id] = {
      status: section.status,
      ready_count: section.ready_count || 0,
      incomplete_count: section.incomplete_count || 0,
      missing_evidence: section.missing_evidence || [],
    };
  }

  const needsEvidence = blockers.length > 0
    || missingFormalEndpoints.length > 0
    || Object.keys(missingFieldsBySection).length > 0
    || Object.keys(p3CandidateSections).length > 0
    || Object.keys(p3EvidenceSections).length > 0;
  const status = blockers.includes('auth_session')
    ? 'blocked_auth'
    : (needsEvidence ? 'needs_evidence' : 'ready');

  return {
    status,
    blockers: uniqueSorted(blockers),
    missing_formal_endpoint_count: missingFormalEndpoints.length,
    missing_formal_endpoints: missingFormalEndpoints,
    missing_fields_by_section: missingFieldsBySection,
    p3_candidate_sections: p3CandidateSections,
    p3_evidence_sections: p3EvidenceSections,
    next_actions: nextActions,
  };
}

function isCtripLoginUrl(url) {
  return /(?:ebooking\.)?ctrip\.com\/(?:login|passport|account|oauth|sso)\b|\/login\/index/i.test(String(url || ''));
}

function buildCandidateGroups(candidates) {
  const groups = Object.fromEntries(CTRIP_ENDPOINT_CANDIDATE_RULES.map((rule) => [
    rule.id,
    {
      id: rule.id,
      label: rule.label,
      priority: rule.priority,
      data_type: rule.dataType,
      count: 0,
      evidence_status: 'needs_payload_response',
      required_evidence: [...CTRIP_CAPTURE_AUDIT_EVIDENCE],
      items: [],
    },
  ]));

  for (const candidate of candidates) {
    const section = candidate.candidate_section || 'unknown';
    groups[section] ||= {
      id: section,
      label: candidate.candidate_label || '',
      priority: candidate.priority || '',
      data_type: candidate.data_type || '',
      count: 0,
      evidence_status: candidate.evidence_status || 'needs_payload_response',
      required_evidence: candidate.required_evidence || [...CTRIP_CAPTURE_AUDIT_EVIDENCE],
      items: [],
    };
    groups[section].count += 1;
    groups[section].items.push(candidate);
  }

  for (const group of Object.values(groups)) {
    group.items.sort((a, b) => String(a.url || '').localeCompare(String(b.url || '')));
  }

  return groups;
}

function bumpSection(bySection, section, key) {
  const stats = ensureSection(bySection, section);
  stats[key] += 1;
}

function addSectionValue(bySection, section, key, value) {
  const stats = ensureSection(bySection, section);
  stats[key].add(value);
}

function addCapturedField(bySection, section, fieldId) {
  const key = section || 'unknown';
  bySection[key] ||= new Set();
  bySection[key].add(fieldId);
}

function ensureSection(bySection, section) {
  const key = section || 'unknown';
  bySection[key] ||= {
    response_count: 0,
    catalog_fact_count: 0,
    standard_row_count: 0,
    endpoint_ids: new Set(),
    metric_keys: new Set(),
  };
  return bySection[key];
}

function bumpInteractionPage(bySection, section) {
  const stats = ensureInteractionSection(bySection, section);
  stats.page_count += 1;
}

function ensureInteractionSection(bySection, section) {
  const key = section || 'unknown';
  bySection[key] ||= {
    page_count: 0,
    planned_count: 0,
    clicked_count: 0,
    skipped_count: 0,
    error_count: 0,
    clicked_actions: new Set(),
    missed_actions: new Set(),
    skipped_reasons: new Set(),
  };
  return bySection[key];
}

function finalizeSectionStats(bySection) {
  return Object.fromEntries(Object.entries(bySection)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([section, stats]) => [section, {
      response_count: stats.response_count,
      catalog_fact_count: stats.catalog_fact_count,
      standard_row_count: stats.standard_row_count,
      endpoint_ids: [...stats.endpoint_ids].sort(),
      metric_keys: [...stats.metric_keys].sort(),
    }]));
}

function finalizeInteractionStats(bySection) {
  return Object.fromEntries(Object.entries(bySection)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([section, stats]) => [section, {
      page_count: stats.page_count,
      planned_count: stats.planned_count,
      clicked_count: stats.clicked_count,
      skipped_count: stats.skipped_count,
      error_count: stats.error_count,
      clicked_actions: [...stats.clicked_actions].sort(),
      missed_actions: [...stats.missed_actions].sort(),
      skipped_reasons: [...stats.skipped_reasons].sort(),
    }]));
}

function sectionFromValue(value) {
  return String(value || '').trim() || 'unknown';
}

function sectionFromDimension(value) {
  const match = String(value || '').match(/^catalog:([^:]+)/);
  return match?.[1] || '';
}

function fieldFromDimension(value) {
  const match = String(value || '').match(/^catalog:[^:]+:[^:]+:([^:]+)/);
  return match?.[1] || '';
}

function uniqueSorted(values) {
  return [...new Set(values)].sort((a, b) => a.localeCompare(b));
}
