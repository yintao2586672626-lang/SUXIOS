import { existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

import { parseJsonTextSafely, safeJsonParseErrorCode } from './lib/safe_json_parse_error.mjs';

const root = process.cwd();
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const importer = path.join(root, 'scripts', 'import_p0_ota_traffic_payload.php');
const p0Verifier = path.join(root, 'scripts', 'verify_p0_ota_field_loop_closure.php');
const childProcessMaxBuffer = 16 * 1024 * 1024;
const p0RequiredTrafficMetricKeys = [
  'detail_exposure',
  'flow_rate',
  'list_exposure',
  'order_filling_num',
  'order_submit_num',
];

const options = parseArgs(process.argv.slice(2));
const platforms = options.platform === 'all' ? ['ctrip', 'meituan'] : [options.platform];
const scanTargets = collectCandidateTargets(options, platforms);
const results = [];

for (const target of scanTargets.filter((item) => item.exists)) {
  results.push(runImporterDryRun(target, options));
}

const readyCandidates = results.filter((item) => item.status === 'ready_to_import');
const blockedCandidates = results.filter((item) => item.status !== 'ready_to_import');
const blockedIssueSummary = summarizeBlockedIssues(blockedCandidates);
const missingPayloads = scanTargets.filter((item) => !item.exists).map((item) => ({
  platform: item.platform,
  system_hotel_id: item.systemHotelId,
  payload: item.payload,
}));
const readyCandidateActions = readyCandidates.map((item) => ({
  platform: item.platform,
  system_hotel_id: item.system_hotel_id,
  payload: item.payload,
  command: `npm.cmd run import:p0-ota-traffic-payload:execute -- --platform=${item.platform} --date=${options.date} --system-hotel-id=${item.system_hotel_id} --payload=${item.payload} --format=json`,
  verification: `npm.cmd run verify:p0-ota-field-loop -- --date=${options.date} --platform=${item.platform} --system-hotel-id=${item.system_hotel_id}`,
}));
const requiredPayloadAction = {
  status: 'requires_authorized_traffic_payload',
  missing_payloads: missingPayloads,
  required_inputs: [
    'authorized Ctrip/Meituan traffic JSON payload for the target date',
    'explicit OTA platform hotel identifier',
    'row-level source_path and desensitized source_trace_id/source_url_hash evidence',
    'manual_login_state_verified when using browser profile capture',
  ],
};
const summary = {
  expected_candidate_count: scanTargets.length,
  candidate_file_count: scanTargets.filter((item) => item.exists).length,
  dry_run_count: results.length,
  ready_candidate_count: readyCandidates.length,
  blocked_candidate_count: blockedCandidates.length,
  missing_candidate_count: scanTargets.filter((item) => !item.exists).length,
};
const output = {
  script: 'scripts/scan_p0_ota_traffic_payload_candidates.mjs',
  status: summary.candidate_file_count === 0 ? 'missing_candidates' : (readyCandidates.length > 0 ? 'ready_candidates_found' : 'no_ready_candidates'),
  scope: {
    date: options.date,
    platforms,
    system_hotel_id: options.systemHotelIdExplicit ? options.systemHotelId : 'p0_verifier_hotel_scoped',
    hotel_scope_policy: options.systemHotelIdExplicit ? 'explicit_system_hotel_id' : 'read_p0_verifier_hotel_scoped_next_steps',
    execute_policy: 'dry_run_only',
    payload_policy: 'paths_and_importer_summaries_only_no_payload_content',
    storage_policy: 'does_not_write_online_daily_data',
  },
  summary,
  expected_candidate_paths: scanTargets.map((item) => item.payload),
  missing_candidate_paths: scanTargets.filter((item) => !item.exists).map((item) => item.payload),
  candidate_paths: scanTargets.filter((item) => item.exists).map((item) => item.payload),
  ready_candidates: readyCandidates,
  blocked_candidates: blockedCandidates,
  blocked_issue_summary: blockedIssueSummary,
  next_actions: [
    ...readyCandidateActions,
    ...(missingPayloads.length > 0 || readyCandidateActions.length === 0 ? [requiredPayloadAction] : []),
  ],
};

if (options.format === 'markdown') {
  console.log(renderMarkdown(output));
} else {
  console.log(JSON.stringify(output, null, 2));
}

if (options.strict && readyCandidates.length === 0) {
  process.exit(1);
}

function parseArgs(argv) {
  const today = new Date();
  const defaults = {
    date: `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`,
    systemHotelId: '7',
    systemHotelIdExplicit: false,
    platform: 'all',
    inputs: [],
    includeReports: false,
    strict: false,
    format: 'json',
  };

  for (const arg of argv) {
    if (arg === '--include-reports') {
      defaults.includeReports = true;
      continue;
    }
    if (arg === '--strict') {
      defaults.strict = true;
      continue;
    }
    if (!arg.startsWith('--') || !arg.includes('=')) {
      continue;
    }
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (key === 'date') {
      defaults.date = value;
    } else if (key === 'system-hotel-id') {
      defaults.systemHotelId = value;
      defaults.systemHotelIdExplicit = true;
    } else if (key === 'platform') {
      defaults.platform = value.toLowerCase();
    } else if (key === 'input') {
      defaults.inputs.push(value);
    } else if (key === 'format') {
      defaults.format = value.toLowerCase();
    }
  }

  if (!/^\d{4}-\d{2}-\d{2}$/.test(defaults.date)) {
    throw new Error('Invalid --date, expected YYYY-MM-DD.');
  }
  if (!/^\d+$/.test(String(defaults.systemHotelId)) || Number(defaults.systemHotelId) <= 0) {
    throw new Error('Invalid --system-hotel-id, expected a positive integer.');
  }
  if (!['all', 'ctrip', 'meituan'].includes(defaults.platform)) {
    throw new Error('Invalid --platform, expected all, ctrip, or meituan.');
  }
  if (!['json', 'markdown'].includes(defaults.format)) {
    throw new Error('Invalid --format, expected json or markdown.');
  }

  return defaults;
}

function collectCandidateTargets(scanOptions, scanPlatforms) {
  const targets = [];
  if (scanOptions.inputs.length > 0) {
    for (const input of scanOptions.inputs) {
      for (const filePath of collectInputFiles(input)) {
        targets.push(...targetsForInputFile(filePath, scanOptions, scanPlatforms, 'input'));
      }
    }
  } else if (!scanOptions.systemHotelIdExplicit) {
    targets.push(...collectVerifierScopedTargets(scanOptions, scanPlatforms));
  } else {
    for (const platform of scanPlatforms) {
      const expected = path.join(root, 'reports', `p0_traffic_${platform}_${scanOptions.systemHotelId}_${scanOptions.date.replaceAll('-', '')}.json`);
      targets.push(targetFromPath(expected, platform, scanOptions.systemHotelId, 'explicit_expected_path'));
    }
  }

  if (scanOptions.includeReports) {
    for (const filePath of collectInputFiles(path.join(root, 'reports'))) {
      targets.push(...targetsForInputFile(filePath, scanOptions, scanPlatforms, 'reports_directory'));
    }
  }

  return uniqueTargets(targets);
}

function collectInputFiles(input) {
  const resolved = path.resolve(root, input);
  if (!existsSync(resolved)) {
    return [];
  }
  const stat = statSync(resolved);
  if (stat.isDirectory()) {
    const files = [];
    for (const entry of readdirSync(resolved, { withFileTypes: true })) {
      if (entry.isFile() && entry.name.toLowerCase().endsWith('.json')) {
        files.push(path.join(resolved, entry.name));
      }
    }
    return files;
  }
  return [resolved];
}

function collectVerifierScopedTargets(scanOptions, scanPlatforms) {
  const child = spawnSync(phpBinary, [
    p0Verifier,
    `--date=${scanOptions.date}`,
    `--platform=${scanPlatforms.join(',')}`,
    '--format=json',
  ], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: childProcessMaxBuffer,
  });
  if (child.error) {
    throw new Error(`P0 verifier process failed for hotel-scoped payload candidates: ${child.error.message}`);
  }
  if (child.status === null) {
    throw new Error(`P0 verifier process ended without an exit status for hotel-scoped payload candidates (signal=${child.signal || 'unknown'})`);
  }
  const stdout = String(child.stdout || '').trim();
  let parsed = {};
  try {
    parsed = parseJsonTextSafely(stdout, 'p0_verifier_json');
  } catch (error) {
    throw new Error(`P0 verifier returned invalid JSON for hotel-scoped payload candidates: ${safeJsonParseErrorCode(error)}`);
  }

  const targets = [];
  for (const platformResult of Array.isArray(parsed.platforms) ? parsed.platforms : []) {
    const platform = String(platformResult.platform || '').toLowerCase();
    if (!scanPlatforms.includes(platform)) {
      continue;
    }
    const steps = Array.isArray(platformResult.p0_traffic_gate?.hotel_scoped_next_steps)
      ? platformResult.p0_traffic_gate.hotel_scoped_next_steps
      : [];
    for (const step of steps) {
      const payload = String(step.payload_candidate_path || '').trim();
      const systemHotelId = String(step.system_hotel_id || '').trim();
      if (payload !== '' && /^\d+$/.test(systemHotelId)) {
        targets.push(targetFromPath(path.join(root, payload), platform, systemHotelId, 'p0_verifier_hotel_scoped_next_steps'));
      }
    }
  }

  return targets;
}

function targetsForInputFile(filePath, scanOptions, scanPlatforms, source) {
  const inferred = inferP0TrafficPayloadTarget(filePath, scanOptions.date);
  if (inferred && scanPlatforms.includes(inferred.platform)) {
    return [targetFromPath(filePath, inferred.platform, inferred.systemHotelId, `${source}_filename_inferred`)];
  }

  return scanPlatforms.map((platform) => targetFromPath(filePath, platform, scanOptions.systemHotelId, source));
}

function inferP0TrafficPayloadTarget(filePath, targetDate) {
  const match = path.basename(filePath).match(/^p0_traffic_(ctrip|meituan)_(\d+)_(\d{8})\.json$/i);
  if (!match) {
    return null;
  }
  const fileDate = `${match[3].slice(0, 4)}-${match[3].slice(4, 6)}-${match[3].slice(6, 8)}`;
  if (fileDate !== targetDate) {
    return null;
  }
  return {
    platform: match[1].toLowerCase(),
    systemHotelId: match[2],
  };
}

function targetFromPath(filePath, platform, systemHotelId, source) {
  const resolved = path.resolve(root, filePath);
  return {
    filePath: resolved,
    payload: relativePath(resolved),
    platform,
    systemHotelId: String(systemHotelId),
    source,
    exists: existsSync(resolved) && resolved.toLowerCase().endsWith('.json') && isAllowedCandidateFile(resolved),
  };
}

function uniqueTargets(targets) {
  const seen = new Set();
  const result = [];
  for (const target of targets) {
    const key = `${target.platform}|${target.systemHotelId}|${target.payload}`;
    if (seen.has(key)) {
      continue;
    }
    if (!target.payload.toLowerCase().endsWith('.json') || !isAllowedCandidateFile(target.filePath)) {
      continue;
    }
    seen.add(key);
    result.push(target);
  }
  return result.sort((a, b) => `${a.platform}|${a.systemHotelId}|${a.payload}`.localeCompare(`${b.platform}|${b.systemHotelId}|${b.payload}`));
}

function isAllowedCandidateFile(filePath) {
  const name = path.basename(filePath);
  return !(name === 'ctrip_capture_catalog.json' || name.startsWith('ctrip_capture_target_') || name.startsWith('ctrip_browser_capture_'));
}

function summarizeTrafficEvidenceDiagnostics(trafficEvidence, summary = {}) {
  const rows = Array.isArray(trafficEvidence) ? trafficEvidence : [];
  const requiredMetricKeys = new Set(
    (Array.isArray(summary?.required_metric_keys) ? summary.required_metric_keys : p0RequiredTrafficMetricKeys)
      .map(String)
      .filter(Boolean),
  );
  let sourcePathRows = 0;
  let structuredSourcePathRows = 0;
  let rawDataFieldFactsRows = 0;
  let rawDataExposedRows = 0;
  let sensitiveRows = 0;
  const metricKeys = new Set();
  const missingMetricKeys = Array.isArray(summary?.missing_metric_keys)
    ? summary.missing_metric_keys.map(String).filter(Boolean)
    : [];

  for (const row of rows) {
    if (!row || typeof row !== 'object') {
      continue;
    }
    if (typeof row.source_path === 'string' && row.source_path.trim() !== '') {
      sourcePathRows += 1;
    }
    if (row.source_path_structured === true) {
      structuredSourcePathRows += 1;
    }
    if (row.raw_data_field_facts_present === true) {
      rawDataFieldFactsRows += 1;
    }
    if (row.raw_data_exposed === true) {
      rawDataExposedRows += 1;
    }
    if (row.sensitive_values_exposed === true) {
      sensitiveRows += 1;
    }
    for (const fact of Array.isArray(row.field_facts) ? row.field_facts : []) {
      if (fact && typeof fact === 'object' && typeof fact.metric_key === 'string' && fact.metric_key.trim() !== '') {
        const metricKey = fact.metric_key.trim();
        if (requiredMetricKeys.has(metricKey)) {
          metricKeys.add(metricKey);
        }
      }
    }
  }

  return {
    evidence_source_path_rows: sourcePathRows,
    evidence_structured_source_path_rows: structuredSourcePathRows,
    evidence_raw_data_field_facts_rows: rawDataFieldFactsRows,
    evidence_raw_data_exposed_rows: rawDataExposedRows,
    evidence_sensitive_value_rows: sensitiveRows,
    evidence_metric_keys: [...metricKeys].sort((left, right) => left.localeCompare(right)),
    evidence_missing_metric_keys: missingMetricKeys.sort((left, right) => left.localeCompare(right)),
  };
}

function runImporterDryRun(target, scanOptions) {
  const nextVerifierCommand = `npm.cmd run verify:p0-ota-field-loop -- --date=${scanOptions.date} --platform=${target.platform} --system-hotel-id=${target.systemHotelId}`;
  const child = spawnSync(phpBinary, [
    importer,
    `--platform=${target.platform}`,
    `--date=${scanOptions.date}`,
    `--system-hotel-id=${target.systemHotelId}`,
    `--payload=${target.filePath}`,
    '--format=json',
  ], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: childProcessMaxBuffer,
  });
  const stdout = String(child.stdout || '').trim();
  let parsed = {};
  try {
    parsed = parseJsonTextSafely(stdout, 'p0_importer_json');
  } catch (error) {
    return {
      platform: target.platform,
      system_hotel_id: target.systemHotelId,
      payload: target.payload,
      status: 'invalid_json',
      exit_code: Number(child.status ?? 0),
      issue_codes: ['importer_invalid_json'],
      required_fixes: [requiredFixForIssue('importer_invalid_json')],
      next_verifier_command: nextVerifierCommand,
      stderr: String(child.stderr || '').trim(),
      json_error: safeJsonParseErrorCode(error),
    };
  }
  const issues = Array.isArray(parsed.issues) ? parsed.issues : [];
  const issueCodes = issues.map((issue) => String(issue.code || '')).filter(Boolean);
  const trafficEvidence = Array.isArray(parsed.traffic_evidence) ? parsed.traffic_evidence : [];
  const evidenceDiagnostics = summarizeTrafficEvidenceDiagnostics(trafficEvidence, parsed.summary || {});
  return {
    platform: target.platform,
    system_hotel_id: target.systemHotelId,
    payload: target.payload,
    status: String(parsed.status || 'unknown'),
    exit_code: Number(child.status ?? 0),
    target_date_rows: Number(parsed.summary?.target_date_rows || 0),
    traffic_evidence_rows: trafficEvidence.length,
    ...evidenceDiagnostics,
    p0_completion_status: String(parsed.p0_completion_status || ''),
    issue_codes: issueCodes,
    required_fixes: issueCodes.map(requiredFixForIssue),
    next_verifier_command: String(parsed.next_verifier_command || nextVerifierCommand),
  };
}

function requiredFixForIssue(code) {
  const known = {
    browser_capture_gate_not_pass: {
      action: 'Re-run authorized browser capture only after login and capture_gate.status=pass.',
      evidence_fields: ['auth_status.ok', 'capture_gate.status', 'capture_gate.failed_check_ids', 'manual_login_state_verified'],
    },
    target_date_explicit_row_date_missing: {
      action: 'Each traffic row must carry an explicit row date equal to the target date; command/default dates are not row evidence.',
      evidence_fields: ['traffic[].date', 'traffic[].dataDate', 'traffic[].statDate', 'traffic[].date_source'],
    },
    browser_capture_row_date_source_missing: {
      action: 'Browser-captured rows must state where the row date came from in the response or request.',
      evidence_fields: ['traffic[].date_source', 'responses[].request_date_source'],
    },
    target_date_source_path_missing: {
      action: 'Each imported row must carry a structured source path to the payload row, not only a field name.',
      evidence_fields: ['traffic[]._source_path', 'traffic[].source_path', 'standard_rows[]._source_path'],
    },
    required_traffic_metric_keys_missing: {
      action: 'Traffic payload must cover every required P0 traffic metric key in the same row.',
      evidence_fields: ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
    },
    traffic_field_fact_preview_rows_incomplete: {
      action: 'Every traffic row must be able to produce complete field facts for metric key, storage field, stored value, source path, and capture evidence.',
      evidence_fields: ['raw_data.field_facts[].metric_key', 'raw_data.field_facts[].storage_field', 'raw_data.field_facts[].stored_value_present', 'raw_data.field_facts[].capture_evidence'],
    },
    browser_capture_response_evidence_missing: {
      action: 'Browser capture payload must include response-level traffic evidence matching the rows.',
      evidence_fields: ['responses[].section', 'responses[].row_count', 'responses[].source_trace_id', 'responses[].url_hash', 'responses[].request_date_source'],
    },
    desensitized_capture_evidence_missing: {
      action: 'Rows must carry desensitized capture evidence; raw URLs, cookies, and tokens are not accepted.',
      evidence_fields: ['capture_evidence.source_trace_id', 'capture_evidence.source_url_hash'],
    },
    target_date_platform_hotel_identifier_missing: {
      action: 'Rows must prove the OTA platform hotel identifier; local system_hotel_id is scope only.',
      evidence_fields: ['traffic[].hotelId', 'traffic[].hotel_id', 'traffic[].poiId', 'traffic[].poi_id'],
    },
    importer_invalid_json: {
      action: 'Payload file must be valid JSON before importer dry-run can inspect it.',
      evidence_fields: ['valid JSON document'],
    },
  };
  const detail = known[code] || {
    action: 'Review importer issue code; no automated repair guidance is defined for this code yet.',
    evidence_fields: [],
  };
  return {
    code,
    guidance_status: known[code] ? 'known' : 'unknown',
    ...detail,
  };
}

function summarizeBlockedIssues(blockedCandidates) {
  const issueMap = new Map();
  for (const candidate of blockedCandidates) {
    for (const code of new Set(candidate.issue_codes || [])) {
      const fix = requiredFixForIssue(code);
      const current = issueMap.get(code) || {
        code,
        count: 0,
        guidance_status: fix.guidance_status,
        action: fix.action,
        evidence_fields: fix.evidence_fields,
        affected_candidates: [],
      };
      current.count += 1;
      current.affected_candidates.push({
        platform: candidate.platform,
        system_hotel_id: candidate.system_hotel_id,
        payload: candidate.payload,
      });
      issueMap.set(code, current);
    }
  }

  return [...issueMap.values()].sort((left, right) => {
    if (right.count !== left.count) {
      return right.count - left.count;
    }
    return left.code.localeCompare(right.code);
  });
}

function renderMarkdown(data) {
  const lines = [
    '# P0 OTA Traffic Payload Candidates',
    '',
    `- status: \`${data.status}\``,
    `- date: \`${data.scope.date}\``,
    `- platforms: \`${data.scope.platforms.join(',')}\``,
    `- system_hotel_id: \`${data.scope.system_hotel_id}\``,
    `- hotel scope: \`${data.scope.hotel_scope_policy}\``,
    `- expected candidates: \`${data.summary.expected_candidate_count}\``,
    `- candidate files: \`${data.summary.candidate_file_count}\``,
    `- missing candidates: \`${data.summary.missing_candidate_count}\``,
    `- ready candidates: \`${data.summary.ready_candidate_count}\``,
    '',
    '| platform | system hotel | payload | status | target rows | evidence rows | source_path rows | raw_data facts rows | issues |',
    '| --- | ---: | --- | --- | ---: | ---: | ---: | ---: | --- |',
  ];
  for (const row of [...data.ready_candidates, ...data.blocked_candidates]) {
    lines.push(`| ${row.platform} | ${row.system_hotel_id} | ${row.payload} | ${row.status} | ${row.target_date_rows ?? 0} | ${row.traffic_evidence_rows ?? 0} | ${row.evidence_structured_source_path_rows ?? 0}/${row.evidence_source_path_rows ?? 0} | ${row.evidence_raw_data_field_facts_rows ?? 0} | ${(row.issue_codes || []).join(',')} |`);
  }
  const missingPayloads = (Array.isArray(data.next_actions) ? data.next_actions : [])
    .flatMap((action) => (Array.isArray(action?.missing_payloads) ? action.missing_payloads : []));
  if (missingPayloads.length > 0) {
    lines.push('', '## Missing Authorized Payloads');
    for (const item of missingPayloads) {
      lines.push(`- ${item.platform} / system_hotel_id=${item.system_hotel_id}: ${item.payload}`);
    }
    lines.push('', 'Required evidence per payload: explicit OTA platform hotel identifier, row-level source_path, metric key coverage, and desensitized source_trace_id/source_url_hash.');
  }
  if ((data.blocked_issue_summary || []).length > 0) {
    lines.push('', '## Blocked Issue Summary');
    for (const issue of data.blocked_issue_summary) {
      const affected = (issue.affected_candidates || [])
        .map((item) => `${item.platform}/system_hotel_id=${item.system_hotel_id}`)
        .join(', ');
      lines.push(`- ${issue.code} (${issue.count}): ${issue.action}`);
      if ((issue.evidence_fields || []).length > 0) {
        lines.push(`  evidence: ${(issue.evidence_fields || []).join(', ')}`);
      }
      if (affected) {
        lines.push(`  affected: ${affected}`);
      }
    }
  }
  if (data.blocked_candidates.length > 0) {
    lines.push('', '## Blocked Candidate Fixes');
    for (const candidate of data.blocked_candidates) {
      lines.push(`- ${candidate.platform} / system_hotel_id=${candidate.system_hotel_id}: ${candidate.payload}`);
      for (const fix of candidate.required_fixes || []) {
        lines.push(`  - ${fix.code}: ${fix.action}`);
        if ((fix.evidence_fields || []).length > 0) {
          lines.push(`    evidence: ${(fix.evidence_fields || []).join(', ')}`);
        }
      }
    }
  }
  if (data.ready_candidates.length > 0) {
    lines.push('', '## Ready Execute Commands');
    for (const action of data.next_actions.filter((item) => item?.command)) {
      lines.push(`- ${action.command}`);
      lines.push(`  verify: ${action.verification}`);
    }
  }
  return lines.join('\n');
}

function relativePath(filePath) {
  return path.relative(root, filePath).replaceAll(path.sep, '/');
}

function pad(value) {
  return String(value).padStart(2, '0');
}
