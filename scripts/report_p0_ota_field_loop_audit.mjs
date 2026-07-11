import { spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const options = parseArgs(process.argv.slice(2));

const verifier = runJsonCommand('p0_verifier', phpBinary, [
  path.join(root, 'scripts', 'verify_p0_ota_field_loop_closure.php'),
  `--date=${options.date}`,
  '--format=json',
  ...platformArgs(options.platform),
], [0, 2]);

const businessChain = runJsonCommand('business_chain_report', phpBinary, [
  path.join(root, 'scripts', 'report_business_chain_status.php'),
  `--date=${options.date}`,
  '--format=json',
  ...platformArgs(options.platform),
], [0, 2]);

const payloadScan = options.scanPayloads
  ? runJsonCommand('payload_candidate_scan', process.execPath, [
      path.join(root, 'scripts', 'scan_p0_ota_traffic_payload_candidates.mjs'),
      `--date=${options.date}`,
      `--platform=${options.platform}`,
      '--format=json',
      ...options.inputs.flatMap((input) => [`--input=${input}`]),
      ...(options.includeReports ? ['--include-reports'] : []),
    ], [0, 1])
  : {
      status: 'not_run',
      exitCode: null,
      json: payloadScanNotRun(verifier.json),
      raw: '',
    };

const report = buildReport({
  options,
  verifier: verifier.json,
  verifierExitCode: verifier.exitCode,
  businessChain: businessChain.json,
  businessChainExitCode: businessChain.exitCode,
  payloadScan: payloadScan.json,
  payloadScanExitCode: payloadScan.exitCode,
});

if (options.format === 'markdown') {
  console.log(renderMarkdown(report));
} else {
  console.log(JSON.stringify(report, null, 2));
}

if (options.strict && report.status !== 'ready') {
  process.exit(2);
}

function parseArgs(argv) {
  const parsed = {
    date: previousShanghaiDate(),
    dateExplicit: false,
    platform: 'all',
    format: 'json',
    strict: false,
    scanPayloads: false,
    includeReports: false,
    inputs: [],
  };

  for (const arg of argv) {
    if (arg === '--strict') {
      parsed.strict = true;
      continue;
    }
    if (arg === '--scan-payloads') {
      parsed.scanPayloads = true;
      continue;
    }
    if (arg === '--include-reports') {
      parsed.includeReports = true;
      parsed.scanPayloads = true;
      continue;
    }
    if (!arg.startsWith('--') || !arg.includes('=')) {
      continue;
    }
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (key === 'date') {
      parsed.date = value.trim();
      parsed.dateExplicit = true;
    } else if (key === 'platform') {
      parsed.platform = normalizePlatform(value);
    } else if (key === 'format') {
      parsed.format = value.trim().toLowerCase();
    } else if (key === 'input') {
      parsed.inputs.push(value.trim());
      parsed.scanPayloads = true;
    }
  }

  if (!/^\d{4}-\d{2}-\d{2}$/.test(parsed.date)) {
    throw new Error('Invalid --date, expected YYYY-MM-DD.');
  }
  if (!['all', 'ctrip', 'meituan'].includes(parsed.platform)) {
    throw new Error('Invalid --platform, expected all, ctrip, or meituan.');
  }
  if (!['json', 'markdown'].includes(parsed.format)) {
    throw new Error('Invalid --format, expected json or markdown.');
  }
  return parsed;
}

function normalizePlatform(value) {
  const platform = String(value || '').trim().toLowerCase();
  if (platform === '' || platform === 'ctrip,meituan' || platform === 'meituan,ctrip') {
    return 'all';
  }
  return platform;
}

function previousShanghaiDate() {
  const now = new Date();
  const shanghaiNow = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Shanghai' }));
  shanghaiNow.setDate(shanghaiNow.getDate() - 1);
  return [
    shanghaiNow.getFullYear(),
    String(shanghaiNow.getMonth() + 1).padStart(2, '0'),
    String(shanghaiNow.getDate()).padStart(2, '0'),
  ].join('-');
}

function platformArgs(platform) {
  return platform === 'all' ? [] : [`--platform=${platform}`];
}

function runJsonCommand(name, command, args, acceptedStatuses) {
  const result = spawnSync(command, args, {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 30 * 1024 * 1024,
    windowsHide: true,
  });
  if (result.error) {
    throw new Error(`${name} failed to start: ${result.error.message}`);
  }
  const exitCode = Number(result.status ?? 0);
  const raw = `${result.stdout || ''}${result.stderr || ''}`.trim();
  if (!acceptedStatuses.includes(exitCode)) {
    throw new Error(`${name} exited ${exitCode}: ${raw.slice(0, 1200)}`);
  }
  return {
    status: 'loaded',
    exitCode,
    json: parseJsonFromOutput(name, raw),
    raw,
  };
}

function parseJsonFromOutput(name, raw) {
  const start = raw.indexOf('{');
  const end = raw.lastIndexOf('}');
  if (start < 0 || end <= start) {
    throw new Error(`${name} did not return JSON.`);
  }
  return JSON.parse(raw.slice(start, end + 1));
}

function payloadScanNotRun(verifierPayload) {
  return {
    status: 'not_run',
    scan_policy: 'not_run_by_default_to_avoid_reading_local_reports',
    execute_policy: 'dry_run_only_when_enabled',
    payload_policy: 'paths_only_no_payload_content',
    expected_candidate_paths: expectedCandidatePaths(verifierPayload),
    missing_candidate_paths: expectedCandidatePaths(verifierPayload),
    ready_candidates: [],
    blocked_candidates: [],
    blocked_issue_summary: [],
    next_actions: [{
      status: 'scan_not_run',
      command: 'npm.cmd run report:p0-ota-field-loop-audit -- --date=<target-date> --scan-payloads',
      policy: 'Only run payload scan after authorized traffic payload files are intentionally placed or passed with --input.',
    }],
  };
}

function expectedCandidatePaths(verifierPayload) {
  const paths = [];
  for (const platform of arrayOf(verifierPayload?.platforms)) {
    const steps = arrayOf(platform?.p0_traffic_gate?.hotel_scoped_next_steps);
    for (const step of steps) {
      const payload = String(step?.payload_candidate_path || '').trim();
      if (payload) {
        paths.push(payload.replaceAll('\\', '/'));
      }
    }
  }
  return [...new Set(paths)].sort();
}

function buildReport(input) {
  const verifierStatus = String(input.verifier?.status || 'unknown');
  const p0Ready = verifierStatus === 'passed';
  const p0Gate = input.businessChain?.p0_downstream_gate || {};
  const platforms = arrayOf(input.verifier?.platforms).map((platform) => platformRow(platform, input.verifier));
  const issueRows = issueSummary(input.verifier?.issues);
  const expectedPayloads = expectedCandidatePaths(input.verifier);
  const payloadScanStatus = String(input.payloadScan?.status || 'unknown');

  return {
    script: 'scripts/report_p0_ota_field_loop_audit.mjs',
    status: p0Ready ? 'ready' : 'blocked_by_p0_ota_gate',
    scope: {
      date: input.options.date,
      date_policy: input.options.dateExplicit ? 'explicit_date' : 'default_yesterday_shanghai',
      platforms: input.options.platform === 'all' ? ['ctrip', 'meituan'] : [input.options.platform],
      metric_scope: 'ota_channel',
      storage_table: 'online_daily_data',
      source_policy: 'read_existing_online_daily_data_only',
    },
    verification: {
      p0_verifier_exit_code: input.verifierExitCode,
      p0_verifier_status: verifierStatus,
      business_chain_exit_code: input.businessChainExitCode,
      business_chain_status: String(input.businessChain?.status || 'unknown'),
      payload_scan_status: payloadScanStatus,
      payload_scan_exit_code: input.payloadScanExitCode,
      payload_scan_policy: input.options.scanPayloads
        ? 'dry_run_only_paths_and_importer_summaries'
        : 'not_run_by_default_no_reports_read',
    },
    p0_summary: input.verifier?.summary || {},
    platforms,
    issues: issueRows,
    payload_candidates: {
      scan_status: payloadScanStatus,
      expected_paths: expectedPayloads,
      candidate_file_count: Number(input.payloadScan?.summary?.candidate_file_count || 0),
      ready_candidate_count: Number(input.payloadScan?.summary?.ready_candidate_count || 0),
      blocked_candidate_count: Number(input.payloadScan?.summary?.blocked_candidate_count || 0),
      missing_candidate_count: input.options.scanPayloads
        ? Number(input.payloadScan?.summary?.missing_candidate_count || 0)
        : expectedPayloads.length,
      ready_candidates: sanitizeCandidates(input.payloadScan?.ready_candidates),
      blocked_issue_summary: arrayOf(input.payloadScan?.blocked_issue_summary),
    },
    downstream_gate: {
      status: String(p0Gate.status || ''),
      required_gate_command: String(p0Gate.required_gate_command || `npm.cmd run verify:p0-ota-field-loop -- --date=${input.options.date}`),
      blocking_missing_inputs: arrayOf(p0Gate.blocking_missing_inputs).map(String),
      revenue_diagnosis_status: String(input.businessChain?.downstream_reference_workflow?.revenue_diagnosis?.status || ''),
      ai_advice_draft_status: String(input.businessChain?.downstream_reference_workflow?.ai_advice_draft?.status || ''),
      operation_execution_draft_status: String(input.businessChain?.downstream_reference_workflow?.operation_execution_draft?.status || ''),
      investment_precheck_status: String(input.businessChain?.downstream_reference_workflow?.investment_precheck?.status || ''),
      decision_allowed: Boolean(input.businessChain?.downstream_reference_workflow?.investment_precheck?.decision_allowed),
    },
    next_actions: nextActions({
      date: input.options.date,
      platform: input.options.platform,
      p0Ready,
      platforms,
      expectedPayloads,
      payloadScan: input.payloadScan,
    }),
    forbidden_claims_until_ready: [
      'whole_hotel_truth_from_ota_only',
      'revenue_ai_final_decision',
      'operation_execution_completed',
    ],
  };
}

function platformRow(platform, verifierPayload) {
  const gate = platform?.p0_traffic_gate || {};
  const issueCodes = arrayOf(verifierPayload?.issues)
    .map((issue) => String(issue?.code || ''))
    .filter((code) => code === 'runtime_field_fact_summary_ready' || code.startsWith(`${platform.platform}_`));
  const p0TrafficGateStatus = String(gate.status || '');
  const fieldFactStatus = String(platform?.field_fact_status || '');
  const blockedMetricKeys = [
    ...arrayOf(gate.p0_standard_fact_missing_metric_keys),
    ...arrayOf(gate.p0_standard_fact_incomplete_metric_keys),
  ].map(String);
  return {
    platform: String(platform?.platform || ''),
    platform_p0_status: fieldFactStatus === 'ready' && p0TrafficGateStatus === 'ready' ? 'ready' : 'incomplete',
    field_fact_status: fieldFactStatus,
    target_date_rows: Number(platform?.target_date_rows || 0),
    p0_traffic_gate_status: p0TrafficGateStatus,
    p0_traffic_gate_action_status: String(gate.action_status || ''),
    target_date_traffic_rows: Number(gate.traffic_rows || 0),
    complete_metric_keys: arrayOf(gate.p0_standard_fact_complete_metric_keys).map(String),
    missing_metric_keys: arrayOf(gate.p0_standard_fact_missing_metric_keys).map(String),
    incomplete_metric_keys: arrayOf(gate.p0_standard_fact_incomplete_metric_keys).map(String),
    blocked_metric_keys: [...new Set(blockedMetricKeys)].sort(),
    gate_blockers: gateBlockers(gate),
    expected_payload_paths: arrayOf(gate.hotel_scoped_next_steps)
      .map((step) => String(step?.payload_candidate_path || '').trim())
      .filter(Boolean),
    issue_codes: [...new Set(issueCodes)],
  };
}

function gateBlockers(gate) {
  const blockers = [];
  const trafficFactStatus = String(gate?.traffic_field_fact_status || '').trim();
  if (trafficFactStatus && !isReadyStatus(trafficFactStatus)) {
    blockers.push(trafficFactStatus);
  }

  const platformIdentifierStatus = String(gate?.platform_hotel_identifier_status || '').trim();
  if (platformIdentifierStatus && !isReadyStatus(platformIdentifierStatus)) {
    blockers.push(`platform_hotel_identifier_${platformIdentifierStatus}`);
  }

  const trafficAvailabilityStatus = String(gate?.traffic_availability_status || '').trim();
  if (trafficAvailabilityStatus && !isReadyStatus(trafficAvailabilityStatus)) {
    blockers.push(`traffic_availability_${trafficAvailabilityStatus}`);
  }

  for (const step of arrayOf(gate?.hotel_scoped_next_steps)) {
    const status = String(step?.payload_candidate_status || '').trim();
    if (status && !isReadyStatus(status)) {
      blockers.push(`payload_${status}`);
    }
  }

  return [...new Set(blockers)].sort();
}

function isReadyStatus(status) {
  return ['complete', 'passed', 'ready', 'success'].includes(String(status || '').trim());
}

function issueSummary(issues) {
  return arrayOf(issues).map((issue) => ({
    severity: String(issue?.severity || ''),
    code: String(issue?.code || ''),
    message: String(issue?.message || ''),
  }));
}

function sanitizeCandidates(candidates) {
  return arrayOf(candidates).map((candidate) => ({
    platform: String(candidate?.platform || ''),
    system_hotel_id: String(candidate?.system_hotel_id || ''),
    payload: String(candidate?.payload || ''),
    status: String(candidate?.status || ''),
    target_date_rows: Number(candidate?.target_date_rows || 0),
    traffic_evidence_rows: Number(candidate?.traffic_evidence_rows || 0),
    issue_codes: arrayOf(candidate?.issue_codes).map(String),
    next_verifier_command: String(candidate?.next_verifier_command || ''),
  }));
}

function nextActions({ date, platform, p0Ready, platforms, expectedPayloads, payloadScan }) {
  if (p0Ready) {
    return [{
      action: 'p0_ready_keep_downstream_scope',
      command: `npm.cmd run report:business-chain -- --date=${date}${platform === 'all' ? '' : ` --platform=${platform}`} --format=json`,
      policy: 'Use OTA-channel evidence only; do not promote it to whole-hotel truth.',
    }];
  }

  const actions = [];
  const missingPayloads = expectedPayloads.map((payload) => ({
    payload,
    required_evidence: [
      'authorized traffic JSON for target date',
      'explicit OTA hotel identifier',
      'structured source_path',
      'metric_key coverage',
      'desensitized source_trace_id/source_url_hash',
    ],
  }));
  if (missingPayloads.length > 0) {
    actions.push({
      action: 'prepare_authorized_traffic_payloads',
      status: 'required',
      payloads: missingPayloads,
    });
  }

  if (String(payloadScan?.status || '') === 'not_run') {
    actions.push({
      action: 'run_payload_precheck_after_payloads_exist',
      command: `npm.cmd run report:p0-ota-field-loop-audit -- --date=${date}${platform === 'all' ? '' : ` --platform=${platform}`} --scan-payloads --format=markdown`,
      policy: 'Dry-run only; still does not write online_daily_data.',
    });
  }

  const readyCandidates = sanitizeCandidates(payloadScan?.ready_candidates);
  for (const candidate of readyCandidates) {
    actions.push({
      action: 'execute_ready_payload_import',
      platform: candidate.platform,
      system_hotel_id: candidate.system_hotel_id,
      command: `npm.cmd run import:p0-ota-traffic-payload:execute -- --platform=${candidate.platform} --date=${date} --system-hotel-id=${candidate.system_hotel_id} --payload=${candidate.payload} --format=json`,
      verify: candidate.next_verifier_command || `npm.cmd run verify:p0-ota-field-loop -- --date=${date} --platform=${candidate.platform} --system-hotel-id=${candidate.system_hotel_id}`,
    });
  }

  actions.push({
    action: 'rerun_p0_gate',
    command: `npm.cmd run verify:p0-ota-field-loop -- --date=${date}${platform === 'all' ? '' : ` --platform=${platform}`}`,
    current_blockers: [...new Set(platforms.flatMap((item) => item.issue_codes))],
  });
  return actions;
}

function renderMarkdown(report) {
  const lines = [
    '# P0 OTA Field Loop Audit',
    '',
    `- status: \`${report.status}\``,
    `- date: \`${report.scope.date}\` (${report.scope.date_policy})`,
    `- platforms: \`${report.scope.platforms.join(',')}\``,
    `- verifier: \`${report.verification.p0_verifier_status}\`, exit_code=\`${report.verification.p0_verifier_exit_code}\``,
    `- payload scan: \`${report.verification.payload_scan_status}\` (${report.verification.payload_scan_policy})`,
    `- downstream gate: \`${report.downstream_gate.status}\``,
    '',
    '| platform | P0 status | source rows | traffic rows | field facts | traffic gate | blocked metrics | gate blockers | issue codes |',
    '| --- | --- | ---: | ---: | --- | --- | --- | --- | --- |',
  ];
  for (const row of report.platforms) {
    lines.push(`| ${row.platform} | ${row.platform_p0_status} | ${row.target_date_rows} | ${row.target_date_traffic_rows} | ${row.field_fact_status || '-'} | ${row.p0_traffic_gate_status || '-'} | ${row.blocked_metric_keys.join(',') || '-'} | ${row.gate_blockers.join(',') || '-'} | ${row.issue_codes.join(',') || '-'} |`);
  }

  if (report.payload_candidates.expected_paths.length > 0) {
    lines.push('', '## Expected Authorized Payloads');
    for (const payload of report.payload_candidates.expected_paths) {
      lines.push(`- \`${payload}\``);
    }
  }

  if (report.issues.length > 0) {
    lines.push('', '## Blocking Issues');
    for (const issue of report.issues) {
      lines.push(`- \`${issue.code}\`: ${issue.message}`);
    }
  }

  lines.push('', '## Next Actions');
  for (const action of report.next_actions) {
    lines.push(`- \`${action.action}\``);
    if (action.command) {
      lines.push(`  command: \`${action.command}\``);
    }
    if (action.verify) {
      lines.push(`  verify: \`${action.verify}\``);
    }
    if (Array.isArray(action.payloads) && action.payloads.length > 0) {
      for (const payload of action.payloads) {
        lines.push(`  payload: \`${payload.payload}\``);
      }
    }
  }

  lines.push('', '## Downstream Boundary');
  lines.push(`- revenue_diagnosis: \`${report.downstream_gate.revenue_diagnosis_status || 'not_ready'}\``);
  lines.push(`- ai_advice_draft: \`${report.downstream_gate.ai_advice_draft_status || 'not_ready'}\``);
  lines.push(`- operation_execution_draft: \`${report.downstream_gate.operation_execution_draft_status || 'not_ready'}\``);
  lines.push(`- investment_precheck: \`${report.downstream_gate.investment_precheck_status || 'not_ready'}\`, decision_allowed=\`${report.downstream_gate.decision_allowed}\``);
  lines.push(`- required gate: \`${report.downstream_gate.required_gate_command}\``);
  lines.push('- forbidden until ready: `whole_hotel_truth_from_ota_only`, `revenue_ai_final_decision`, `operation_execution_completed`');
  return `${lines.join('\n')}\n`;
}

function arrayOf(value) {
  return Array.isArray(value) ? value : [];
}
