import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, basename } from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';
import { summarizeCapture } from './summarize_ctrip_capture_result.mjs';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

function parseSnapshotArgs(argv) {
  const result = {
    inputs: [],
    output: '',
    markdown: '',
  };
  for (const arg of argv) {
    if (arg.startsWith('--input=')) {
      result.inputs.push(...splitInputs(arg.slice('--input='.length)));
    } else if (arg.startsWith('--inputs=')) {
      result.inputs.push(...splitInputs(arg.slice('--inputs='.length)));
    } else if (arg.startsWith('--output=')) {
      result.output = arg.slice('--output='.length);
    } else if (arg.startsWith('--markdown=')) {
      result.markdown = arg.slice('--markdown='.length);
    } else if (arg && !arg.startsWith('--')) {
      result.inputs.push(...splitInputs(arg));
    }
  }
  result.inputs = [...new Set(result.inputs)];
  return result;
}

function splitInputs(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
}

export function loadCtripDiagnosisSummary(input) {
  if (!existsSync(input)) {
    throw new Error(`input file not found: ${input}`);
  }
  const payload = parseJsonTextSafely(
    readFileSync(input, 'utf8').replace(/^\uFEFF/, ''),
    'ctrip_diagnosis_json',
  );
  if (Array.isArray(payload.diagnosis_groups) && payload.counts && payload.sections) {
    return { ...payload, source: payload.source || input, input_path: input };
  }
  return { ...summarizeCapture(payload, input), input_path: input };
}

export function buildCtripDiagnosisSnapshot(summaries) {
  const groups = new Map();
  const sections = new Map();
  const metrics = new Map();
  const endpoints = new Map();
  const counts = {
    responses: 0,
    catalog_facts: 0,
    standard_rows: 0,
    endpoint_candidates: 0,
    p3_evidence_drafts: 0,
  };

  for (const summary of summaries) {
    counts.responses += Number(summary.counts?.responses || 0);
    counts.catalog_facts += Number(summary.counts?.catalog_facts || 0);
    counts.standard_rows += Number(summary.counts?.standard_rows || 0);
    counts.endpoint_candidates += Number(summary.counts?.endpoint_candidates || 0);
    counts.p3_evidence_drafts += Number(summary.counts?.p3_evidence_drafts || 0);

    for (const metric of summary.captured_metrics || []) {
      metrics.set(metric.key, metric);
    }
    for (const endpoint of summary.captured_endpoints || []) {
      endpoints.set(endpoint.id, endpoint);
    }
    for (const group of summary.diagnosis_groups || []) {
      const current = groups.get(group.name) || {
        name: group.name,
        expected_count: Number(group.expected_count || 0),
        captured_metric_keys: new Set(),
        captured_metrics: new Map(),
      };
      current.expected_count = Math.max(current.expected_count, Number(group.expected_count || 0));
      for (const key of group.captured_metric_keys || []) {
        current.captured_metric_keys.add(key);
      }
      for (const metric of group.captured_metrics || []) {
        current.captured_metrics.set(metric.key, metric);
      }
      groups.set(group.name, current);
    }
    for (const [sectionKey, section] of Object.entries(summary.sections || {})) {
      const current = sections.get(sectionKey) || {
        key: sectionKey,
        label: section.label || sectionKey,
        response_count: 0,
        standard_row_count: 0,
        catalog_fact_count: 0,
        endpoint_ids: new Set(),
        metric_keys: new Set(),
        missing_endpoint_ids: new Set(),
        status: 'missing_or_not_triggered',
        sources: new Set(),
      };
      current.response_count += Number(section.response_count || 0);
      current.standard_row_count += Number(section.standard_row_count || 0);
      current.catalog_fact_count += Number(section.catalog_fact_count || 0);
      for (const id of section.endpoint_ids || []) current.endpoint_ids.add(id);
      for (const key of section.metric_keys || []) current.metric_keys.add(key);
      for (const id of section.missing_endpoint_ids || []) current.missing_endpoint_ids.add(id);
      current.sources.add(summary.input_path || summary.source || '');
      if (section.status === 'captured') {
        current.status = 'captured';
      } else if (section.status === 'response_only' && current.status !== 'captured') {
        current.status = 'response_only';
      }
      sections.set(sectionKey, current);
    }
  }

  const diagnosisGroups = [...groups.values()].map((group) => {
    const capturedMetricKeys = [...group.captured_metric_keys].sort();
    return {
      name: group.name,
      status: capturedMetricKeys.length > 0 ? 'available' : 'missing',
      captured_count: capturedMetricKeys.length,
      expected_count: group.expected_count,
      captured_metric_keys: capturedMetricKeys,
      captured_metrics: [...group.captured_metrics.values()].sort((a, b) => a.key.localeCompare(b.key)),
    };
  });
  const availableGroups = diagnosisGroups.filter((group) => group.status === 'available').map((group) => group.name);
  const missingGroups = diagnosisGroups.filter((group) => group.status !== 'available').map((group) => group.name);

  return {
    status: counts.standard_rows > 0 && availableGroups.length > 0 ? 'ready' : 'not_ready',
    generated_at: new Date().toISOString(),
    inputs: summaries.map((summary) => ({
      path: summary.input_path || summary.source,
      source: summary.source,
      profile_id: summary.profile_id || '',
      auth_status: summary.auth_status || {},
      counts: summary.counts || {},
    })),
    counts,
    available_groups: availableGroups,
    missing_groups: missingGroups,
    diagnosis_groups: diagnosisGroups,
    captured_metric_keys: [...metrics.keys()].sort(),
    captured_metrics: [...metrics.values()].sort((a, b) => a.key.localeCompare(b.key)),
    captured_endpoint_ids: [...endpoints.keys()].sort(),
    captured_endpoints: [...endpoints.values()].sort((a, b) => a.id.localeCompare(b.id)),
    sections: Object.fromEntries([...sections.entries()].map(([key, section]) => [key, {
      label: section.label,
      status: section.status,
      response_count: section.response_count,
      standard_row_count: section.standard_row_count,
      catalog_fact_count: section.catalog_fact_count,
      endpoint_ids: [...section.endpoint_ids].sort(),
      metric_keys: [...section.metric_keys].sort(),
      missing_endpoint_ids: [...section.missing_endpoint_ids].sort(),
      sources: [...section.sources].filter(Boolean).map((item) => basename(item)),
    }])),
  };
}

export function renderCtripDiagnosisSnapshotMarkdown(snapshot) {
  const lines = [];
  lines.push('# 携程诊断数据快照');
  lines.push('');
  lines.push(`- 状态：${snapshot.status}`);
  lines.push(`- 输入文件：${snapshot.inputs.length}`);
  lines.push(`- 响应数：${snapshot.counts.responses}`);
  lines.push(`- 字段事实：${snapshot.counts.catalog_facts}`);
  lines.push(`- 标准行：${snapshot.counts.standard_rows}`);
  lines.push('');
  lines.push('## 诊断方向');
  lines.push('');
  lines.push('| 方向 | 状态 | 已命中字段 |');
  lines.push('|---|---|---|');
  for (const group of snapshot.diagnosis_groups) {
    const metrics = group.captured_metrics.map((item) => `${item.label} (${item.key})`).join(', ') || '-';
    lines.push(`| ${group.name} | ${group.status} | ${metrics} |`);
  }
  lines.push('');
  lines.push('## 模块覆盖');
  lines.push('');
  lines.push('| 模块 | 状态 | 响应 | 标准行 | 字段事实 | 来源 |');
  lines.push('|---|---|---:|---:|---:|---|');
  for (const section of Object.values(snapshot.sections)) {
    lines.push(`| ${section.label} | ${section.status} | ${section.response_count} | ${section.standard_row_count} | ${section.catalog_fact_count} | ${section.sources.join(', ') || '-'} |`);
  }
  lines.push('');
  lines.push('## 输入文件');
  lines.push('');
  for (const input of snapshot.inputs) {
    lines.push(`- ${input.path}: ${input.auth_status?.status || 'unknown'}, 标准行 ${input.counts?.standard_rows || 0}`);
  }
  return `${lines.join('\n')}\n`;
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function stringValue(value) {
  return String(value ?? '').trim();
}

function main() {
  const args = parseSnapshotArgs(process.argv.slice(2));
  const inputs = args.inputs;
  const output = stringValue(args.output || 'reports/ctrip_diagnosis_snapshot.json');
  const markdown = stringValue(args.markdown || 'docs/ctrip_diagnosis_snapshot.md');

  if (inputs.length === 0) {
    throw new Error('Missing --input=<capture.json>. Repeat --input or separate paths with comma.');
  }

  const summaries = inputs.map((input) => loadCtripDiagnosisSummary(input));
  const snapshot = buildCtripDiagnosisSnapshot(summaries);
  ensureParent(output);
  ensureParent(markdown);
  writeFileSync(output, `${JSON.stringify(snapshot, null, 2)}\n`, 'utf8');
  writeFileSync(markdown, renderCtripDiagnosisSnapshotMarkdown(snapshot), 'utf8');

  console.log(JSON.stringify({
    status: snapshot.status,
    output,
    markdown,
    input_count: snapshot.inputs.length,
    available_groups: snapshot.available_groups,
    missing_groups: snapshot.missing_groups,
    counts: snapshot.counts,
  }, null, 2));
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
