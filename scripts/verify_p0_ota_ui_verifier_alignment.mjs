import { spawnSync } from 'node:child_process';
import path from 'node:path';

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
      json: JSON.parse(raw),
    };
  } catch (error) {
    throw new Error(`${script} did not return valid JSON: ${error.message}`);
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
  const gate = platformResult?.p0_traffic_gate ?? {};
  const steps = asArray(gate?.hotel_scoped_next_steps);
  const counts = {};
  const paths = [];
  const issueCodes = [];
  let readyCount = 0;

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
  }

  return {
    platformResult,
    gate,
    steps,
    counts: normalizeCounts(counts),
    paths: sortedStrings(paths),
    issueCodes: sortedStrings([...new Set(issueCodes)]),
    readyCount,
    missingCount: Number(counts.missing_expected_payload ?? 0),
    unverifiedCount: Number(counts.expected_payload_present_unverified ?? 0),
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

  check(`${prefix} UI next step count matches P0 hotel scoped steps`, Number(ui.row.p0_next_step_count ?? 0) === p0.steps.length, JSON.stringify({ ui: ui.row.p0_next_step_count, p0: p0.steps.length }));
  check(`${prefix} UI managed source count matches P0 hotel scoped steps`, Number(ui.row.traffic_managed_count ?? 0) === p0.steps.length, JSON.stringify({ ui: ui.row.traffic_managed_count, p0: p0.steps.length }));
  check(`${prefix} UI gate status matches P0 gate`, String(ui.row.p0_traffic_gate_status ?? '') === String(p0.gate.status ?? ''), JSON.stringify({ ui: ui.row.p0_traffic_gate_status, p0: p0.gate.status }));
  check(`${prefix} UI target traffic rows match P0 gate`, Number(ui.row.target_date_traffic_rows ?? 0) === Number(p0.gate.traffic_rows ?? 0), JSON.stringify({ ui: ui.row.target_date_traffic_rows, p0: p0.gate.traffic_rows }));

  const allowedStatuses = ['missing_expected_payload', 'expected_payload_present_unverified', 'system_hotel_id_missing'];
  const allowedIssueCodes = ['expected_payload_file_missing', 'payload_file_present_requires_importer_dry_run', 'system_hotel_id_missing'];
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
