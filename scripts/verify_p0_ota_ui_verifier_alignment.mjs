import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

const root = process.cwd();
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const checks = [];

function todayShanghai() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const byType = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${byType.year}-${byType.month}-${byType.day}`;
}

function readArgs(argv) {
  const options = {
    date: todayShanghai(),
    platform: '',
  };
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) continue;
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (Object.hasOwn(options, key)) {
      options[key] = value.trim();
    }
  }
  return options;
}

function check(label, ok, detail = '') {
  checks.push({ label, ok: Boolean(ok), detail });
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sortedStrings(value) {
  return asArray(value)
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
    .sort((left, right) => left.localeCompare(right));
}

function normalizeCounts(value) {
  if (!isObject(value)) return {};
  return Object.fromEntries(Object.entries(value)
    .map(([key, count]) => [String(key), Number(count ?? 0)])
    .sort(([left], [right]) => left.localeCompare(right)));
}

function sameJson(left, right) {
  return JSON.stringify(left) === JSON.stringify(right);
}

function runPhp(script, args, acceptedStatuses) {
  const result = spawnSync(phpBinary, [path.join(root, script), ...args], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
  });

  if (result.error) {
    throw new Error(`${script} failed to start: ${result.error.message}`);
  }

  const status = Number(result.status ?? 0);
  if (!acceptedStatuses.includes(status)) {
    throw new Error(`${script} exited ${status}: ${String(result.stderr ?? '').trim()}`);
  }

  const raw = String(result.stdout ?? '').replace(/^\uFEFF/, '').trim();
  try {
    return {
      status,
      stderr: String(result.stderr ?? ''),
      json: parseJsonTextSafely(raw, 'p0_ui_verifier_json'),
    };
  } catch (error) {
    throw new Error(`${script} did not return valid JSON: parse_error`);
  }
}

function expectedPlatforms(options, p0Result) {
  const requested = options.platform
    .split(',')
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);
  if (requested.length > 0) return requested;
  const scopePlatforms = sortedStrings(p0Result?.scope?.platforms);
  if (scopePlatforms.length > 0) return scopePlatforms;
  return ['ctrip', 'meituan'];
}

function p0PayloadSummary(p0Result, platform) {
  const platformResult = asArray(p0Result?.platforms)
    .find((row) => String(row?.platform ?? '').toLowerCase() === platform);
  const availability = asArray(p0Result?.traffic_evidence_availability)
    .find((row) => String(row?.platform ?? '').toLowerCase() === platform);
  const gate = platformResult?.p0_traffic_gate ?? {};
  const steps = asArray(gate?.hotel_scoped_next_steps);
  const counts = {};
  const paths = [];
  const issueCodes = [];
  const diagnostics = {
    targetDateRows: 0,
    trafficEvidenceRows: 0,
    sourcePathRows: 0,
    structuredSourcePathRows: 0,
    rawDataFieldFactsRows: 0,
    rawDataExposedRows: 0,
    sensitiveValueRows: 0,
    metricKeys: [],
    missingMetricKeys: [],
  };
  let readyCount = 0;
  let profileLoginTriggerAvailableCount = 0;
  let profileLoginTriggerUnavailableCount = 0;
  let afterLoginSyncAvailableCount = 0;
  let manualLoginStateVerifiedCount = 0;

  for (const step of steps) {
    const status = String(step?.payload_candidate_status ?? '').trim();
    if (status !== '') counts[status] = Number(counts[status] ?? 0) + 1;
    if (step?.payload_candidate_ready_to_execute === true) readyCount++;
    const payloadPath = String(step?.payload_candidate_path ?? '').trim();
    if (payloadPath !== '') paths.push(payloadPath);
    for (const issueCode of asArray(step?.payload_candidate_issue_codes)) {
      const normalized = String(issueCode ?? '').trim();
      if (normalized !== '') issueCodes.push(normalized);
    }
    diagnostics.targetDateRows += Number(step?.payload_candidate_target_date_rows ?? 0);
    diagnostics.trafficEvidenceRows += Number(step?.payload_candidate_traffic_evidence_rows ?? 0);
    diagnostics.sourcePathRows += Number(step?.payload_candidate_evidence_source_path_rows ?? 0);
    diagnostics.structuredSourcePathRows += Number(step?.payload_candidate_evidence_structured_source_path_rows ?? 0);
    diagnostics.rawDataFieldFactsRows += Number(step?.payload_candidate_evidence_raw_data_field_facts_rows ?? 0);
    diagnostics.rawDataExposedRows += Number(step?.payload_candidate_evidence_raw_data_exposed_rows ?? 0);
    diagnostics.sensitiveValueRows += Number(step?.payload_candidate_evidence_sensitive_value_rows ?? 0);
    diagnostics.metricKeys.push(...sortedStrings(step?.payload_candidate_evidence_metric_keys));
    diagnostics.missingMetricKeys.push(...sortedStrings(step?.payload_candidate_evidence_missing_metric_keys));
    const trigger = isObject(step?.profile_login_trigger) ? step.profile_login_trigger : {};
    if (String(trigger.status ?? '') === 'available') {
      profileLoginTriggerAvailableCount++;
    } else {
      profileLoginTriggerUnavailableCount++;
    }
    const afterLoginSync = isObject(trigger.after_login_sync) ? trigger.after_login_sync : {};
    if (String(afterLoginSync.entry ?? '').trim() !== '') afterLoginSyncAvailableCount++;
    if (step?.manual_login_state_verified === true) manualLoginStateVerifiedCount++;
  }
  diagnostics.metricKeys = sortedStrings([...new Set(diagnostics.metricKeys)]);
  diagnostics.missingMetricKeys = sortedStrings([...new Set(diagnostics.missingMetricKeys)]);

  return {
    platformResult,
    gate,
    steps,
    managedSourceCount: Number(availability?.registered_sources?.traffic_managed_count ?? 0),
    counts: normalizeCounts(counts),
    paths: sortedStrings(paths),
    issueCodes: sortedStrings([...new Set(issueCodes)]),
    readyCount,
    missingCount: Number(counts.missing_expected_payload ?? 0),
    unverifiedCount: Number(counts.expected_payload_present_unverified ?? 0),
    diagnostics,
    entryDiagnostics: {
      profileLoginTriggerAvailableCount,
      profileLoginTriggerUnavailableCount,
      afterLoginSyncAvailableCount,
      manualLoginStateVerifiedCount,
    },
    standardFact: {
      policy: String(gate?.p0_standard_fact_policy ?? ''),
      status: String(gate?.p0_standard_fact_status ?? ''),
      rawDataPolicy: String(gate?.p0_standard_fact_raw_data_policy ?? ''),
      requiredMetricCount: Number(gate?.p0_standard_fact_required_metric_count ?? 0),
      completeMetricCount: Number(gate?.p0_standard_fact_complete_metric_count ?? 0),
      missingMetricCount: Number(gate?.p0_standard_fact_missing_metric_count ?? 0),
      incompleteMetricCount: Number(gate?.p0_standard_fact_incomplete_metric_count ?? 0),
      storageFieldCount: Number(gate?.p0_standard_fact_storage_field_count ?? 0),
      statusCounts: normalizeCounts(gate?.p0_standard_fact_status_counts),
      completeMetricKeys: sortedStrings(gate?.p0_standard_fact_complete_metric_keys),
      missingMetricKeys: sortedStrings(gate?.p0_standard_fact_missing_metric_keys),
      incompleteMetricKeys: sortedStrings(gate?.p0_standard_fact_incomplete_metric_keys),
    },
  };
}

function uiReadinessRows(inspection) {
  const rows = [];
  for (const question of asArray(inspection?.employee_questions)) {
    for (const row of asArray(question?.evidence?.traffic_source_readiness)) {
      rows.push(row);
    }
  }
  return rows;
}

function uiPayloadSummary(inspection, platform) {
  const row = uiReadinessRows(inspection)
    .find((candidate) => String(candidate?.platform ?? '').toLowerCase() === platform);
  return {
    row,
    counts: normalizeCounts(row?.p0_payload_candidate_status_counts),
    paths: sortedStrings(row?.p0_payload_candidate_paths),
    issueCodes: sortedStrings(row?.p0_payload_candidate_issue_codes),
    readyCount: Number(row?.p0_payload_candidate_ready_count ?? 0),
    missingCount: Number(row?.p0_payload_candidate_missing_count ?? 0),
    unverifiedCount: Number(row?.p0_payload_candidate_unverified_count ?? 0),
    diagnostics: {
      targetDateRows: Number(row?.p0_payload_candidate_target_date_rows ?? 0),
      trafficEvidenceRows: Number(row?.p0_payload_candidate_traffic_evidence_rows ?? 0),
      sourcePathRows: Number(row?.p0_payload_candidate_evidence_source_path_rows ?? 0),
      structuredSourcePathRows: Number(row?.p0_payload_candidate_evidence_structured_source_path_rows ?? 0),
      rawDataFieldFactsRows: Number(row?.p0_payload_candidate_evidence_raw_data_field_facts_rows ?? 0),
      rawDataExposedRows: Number(row?.p0_payload_candidate_evidence_raw_data_exposed_rows ?? 0),
      sensitiveValueRows: Number(row?.p0_payload_candidate_evidence_sensitive_value_rows ?? 0),
      metricKeys: sortedStrings(row?.p0_payload_candidate_evidence_metric_keys),
      missingMetricKeys: sortedStrings(row?.p0_payload_candidate_evidence_missing_metric_keys),
    },
    entryDiagnostics: {
      profileLoginTriggerAvailableCount: Number(row?.p0_profile_login_trigger_available_count ?? 0),
      profileLoginTriggerUnavailableCount: Number(row?.p0_profile_login_trigger_unavailable_count ?? 0),
      afterLoginSyncAvailableCount: Number(row?.p0_after_login_sync_available_count ?? 0),
      manualLoginStateVerifiedCount: Number(row?.p0_manual_login_state_verified_count ?? 0),
    },
    standardFact: {
      policy: String(row?.p0_standard_fact_policy ?? ''),
      status: String(row?.p0_standard_fact_status ?? ''),
      rawDataPolicy: String(row?.p0_standard_fact_raw_data_policy ?? ''),
      requiredMetricCount: Number(row?.p0_standard_fact_required_metric_count ?? 0),
      completeMetricCount: Number(row?.p0_standard_fact_complete_metric_count ?? 0),
      missingMetricCount: Number(row?.p0_standard_fact_missing_metric_count ?? 0),
      incompleteMetricCount: Number(row?.p0_standard_fact_incomplete_metric_count ?? 0),
      storageFieldCount: Number(row?.p0_standard_fact_storage_field_count ?? 0),
      statusCounts: normalizeCounts(row?.p0_standard_fact_status_counts),
      completeMetricKeys: sortedStrings(row?.p0_standard_fact_complete_metric_keys),
      missingMetricKeys: sortedStrings(row?.p0_standard_fact_missing_metric_keys),
      incompleteMetricKeys: sortedStrings(row?.p0_standard_fact_incomplete_metric_keys),
    },
  };
}

function isPayloadCandidatePathSafe(value) {
  const text = String(value ?? '');
  return /^reports\/p0_traffic_(ctrip|meituan)_\d+_\d{8}\.json$/.test(text)
    && !text.includes('://')
    && !/cookie|token|profile/i.test(text);
}

function validatePlatform(platform, p0Result, inspection) {
  const p0 = p0PayloadSummary(p0Result, platform);
  const ui = uiPayloadSummary(inspection, platform);
  const prefix = `${platform} P0 payload candidate UI/verifier alignment`;

  check(`${prefix} P0 verifier platform exists`, Boolean(p0.platformResult), JSON.stringify(p0Result?.scope ?? {}));
  check(`${prefix} UI traffic_source_readiness row exists`, Boolean(ui.row), JSON.stringify(uiReadinessRows(inspection)));
  if (!p0.platformResult || !ui.row) return;

  check(`${prefix} UI policy is metadata only`, String(ui.row.p0_payload_candidate_policy ?? '') === 'ui_metadata_only_no_import', JSON.stringify(ui.row));
  check(`${prefix} UI payload policy exposes paths only`, String(ui.row.p0_payload_candidate_payload_policy ?? '') === 'path_metadata_only_no_payload_content', JSON.stringify(ui.row));
  check(`${prefix} UI storage policy is read-only`, String(ui.row.p0_payload_candidate_storage_policy ?? '') === 'does_not_write_online_daily_data', JSON.stringify(ui.row));
  check(`${prefix} command policy hides sensitive commands`, String(ui.row.next_command_policy ?? '') === 'metadata_only_no_sensitive_commands', JSON.stringify(ui.row));

  check(`${prefix} status counts match hotel_scoped_next_steps`, sameJson(ui.counts, p0.counts), JSON.stringify({ ui: ui.counts, p0: p0.counts }));
  check(`${prefix} missing count matches missing_expected_payload`, ui.missingCount === p0.missingCount, JSON.stringify({ ui: ui.missingCount, p0: p0.missingCount }));
  check(`${prefix} unverified count matches expected_payload_present_unverified`, ui.unverifiedCount === p0.unverifiedCount, JSON.stringify({ ui: ui.unverifiedCount, p0: p0.unverifiedCount }));
  check(`${prefix} ready count matches P0 ready_to_execute`, ui.readyCount === p0.readyCount, JSON.stringify({ ui: ui.readyCount, p0: p0.readyCount }));
  check(`${prefix} path set matches P0 verifier`, sameJson(ui.paths, p0.paths), JSON.stringify({ ui: ui.paths, p0: p0.paths }));
  check(`${prefix} issue code set matches P0 verifier`, sameJson(ui.issueCodes, p0.issueCodes), JSON.stringify({ ui: ui.issueCodes, p0: p0.issueCodes }));
  check(`${prefix} payload dry-run evidence diagnostics match P0 verifier`, sameJson(ui.diagnostics, p0.diagnostics), JSON.stringify({ ui: ui.diagnostics, p0: p0.diagnostics }));
  check(`${prefix} profile login entry diagnostics match P0 verifier`, sameJson(ui.entryDiagnostics, p0.entryDiagnostics), JSON.stringify({ ui: ui.entryDiagnostics, p0: p0.entryDiagnostics }));
  check(`${prefix} standard fact diagnostics match P0 verifier`, sameJson(ui.standardFact, p0.standardFact), JSON.stringify({ ui: ui.standardFact, p0: p0.standardFact }));

  check(`${prefix} UI next step count matches P0 hotel scoped steps`, Number(ui.row.p0_next_step_count ?? 0) === p0.steps.length, JSON.stringify({ ui: ui.row.p0_next_step_count, p0: p0.steps.length }));
  check(`${prefix} UI managed source count matches P0 registered sources`, Number(ui.row.traffic_managed_count ?? 0) === p0.managedSourceCount, JSON.stringify({ ui: ui.row.traffic_managed_count, p0: p0.managedSourceCount }));
  check(`${prefix} UI gate status matches P0 gate`, String(ui.row.p0_traffic_gate_status ?? '') === String(p0.gate.status ?? ''), JSON.stringify({ ui: ui.row.p0_traffic_gate_status, p0: p0.gate.status }));
  check(`${prefix} UI target traffic rows match P0 gate`, Number(ui.row.target_date_traffic_rows ?? 0) === Number(p0.gate.traffic_rows ?? 0), JSON.stringify({ ui: ui.row.target_date_traffic_rows, p0: p0.gate.traffic_rows }));
  const requiredPayloadDiagnosticKeys = [
    'payload_candidate_target_date_rows',
    'payload_candidate_traffic_evidence_rows',
    'payload_candidate_evidence_source_path_rows',
    'payload_candidate_evidence_structured_source_path_rows',
    'payload_candidate_evidence_raw_data_field_facts_rows',
    'payload_candidate_evidence_raw_data_exposed_rows',
    'payload_candidate_evidence_sensitive_value_rows',
    'payload_candidate_evidence_metric_keys',
    'payload_candidate_evidence_missing_metric_keys',
  ];
  check(`${prefix} P0 next steps expose payload dry-run evidence diagnostics`, p0.steps.every((step) => requiredPayloadDiagnosticKeys.every((key) => Object.hasOwn(step, key))), JSON.stringify(p0.steps));
  check(`${prefix} P0 next step metric diagnostics are arrays`, p0.steps.every((step) => Array.isArray(step.payload_candidate_evidence_metric_keys) && Array.isArray(step.payload_candidate_evidence_missing_metric_keys)), JSON.stringify(p0.steps));

  const allowedStatuses = ['missing_expected_payload', 'expected_payload_present_unverified', 'ready_to_import', 'blocked', 'importer_invalid_json', 'system_hotel_id_missing'];
  const allowedIssueCodes = [
    'expected_payload_file_missing',
    'payload_file_present_requires_importer_dry_run',
    'system_hotel_id_missing',
    'importer_invalid_json',
    'browser_capture_gate_not_pass',
    'target_date_explicit_row_date_missing',
    'browser_capture_row_date_source_missing',
    'target_date_source_path_missing',
    'required_traffic_metric_keys_missing',
    'traffic_field_fact_preview_rows_incomplete',
    'browser_capture_response_evidence_missing',
    'desensitized_capture_evidence_missing',
    'target_date_platform_hotel_identifier_missing',
    'system_hotel_id_mismatch',
    'sensitive_payload_keys_detected',
  ];
  check(`${prefix} statuses stay known`, Object.keys(ui.counts).every((status) => allowedStatuses.includes(status)), JSON.stringify(ui.counts));
  check(`${prefix} issue codes stay known`, ui.issueCodes.every((code) => allowedIssueCodes.includes(code)), JSON.stringify(ui.issueCodes));
  check(`${prefix} paths are metadata only`, ui.paths.every(isPayloadCandidatePathSafe), JSON.stringify(ui.paths));

  if (ui.missingCount > 0) {
    check(`${prefix} missing state keeps expected_payload_file_missing`, ui.issueCodes.includes('expected_payload_file_missing'), JSON.stringify(ui.issueCodes));
  }
  if (ui.unverifiedCount > 0) {
    check(`${prefix} present state still requires importer dry-run`, ui.readyCount === 0 && ui.issueCodes.includes('payload_file_present_requires_importer_dry_run'), JSON.stringify(ui));
  }
}

const options = readArgs(process.argv);
if (!/^\d{4}-\d{2}-\d{2}$/.test(options.date)) {
  throw new Error('Invalid --date, expected YYYY-MM-DD.');
}

const platformArgs = options.platform !== '' ? [`--platform=${options.platform}`] : [];
const p0Run = runPhp('scripts/verify_p0_ota_field_loop_closure.php', [
  `--date=${options.date}`,
  '--format=json',
  ...platformArgs,
], [0, 2]);
const inspectionRun = runPhp('scripts/inspect_phase1_ota_live_closure.php', [
  `--date=${options.date}`,
  '--format=json',
], [0]);

check('P0 verifier exposes platforms array', Array.isArray(p0Run.json?.platforms), JSON.stringify(p0Run.json?.scope ?? {}));
check('employee inspector exposes employee_questions array', Array.isArray(inspectionRun.json?.employee_questions), JSON.stringify(inspectionRun.json?.scope ?? {}));

for (const platform of expectedPlatforms(options, p0Run.json)) {
  validatePlatform(platform, p0Run.json, inspectionRun.json);
}

const failures = checks.filter((checkItem) => !checkItem.ok);
if (failures.length > 0) {
  console.error('P0 OTA UI/verifier alignment failed:');
  for (const failure of failures) {
    console.error(`- ${failure.label}`);
    if (failure.detail) {
      console.error(`  detail: ${failure.detail}`);
    }
  }
  process.exit(1);
}

console.log(`[verify:p0-ota-ui-verifier-alignment] ${checks.length} checks passed; date=${options.date}; p0_status=${String(p0Run.json?.status ?? 'unknown')}`);
