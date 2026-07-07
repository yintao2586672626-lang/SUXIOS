import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

function parseArgs(argv) {
  const options = {
    dir: '',
    date: '',
    hotelId: '',
  };
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) {
      continue;
    }
    const eq = arg.indexOf('=');
    const key = arg.slice(2, eq);
    const value = arg.slice(eq + 1).trim();
    if (key === 'dir') {
      options.dir = value;
    } else if (key === 'date') {
      options.date = value;
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = value;
    }
  }
  if (!options.dir) {
    throw new Error('Missing --dir=<operator-bundle-dir>.');
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(options.date)) {
    throw new Error('Invalid or missing --date, expected YYYY-MM-DD.');
  }
  if (!/^[1-9]\d*$/.test(options.hotelId)) {
    throw new Error('Invalid or missing --hotel-id, expected a positive integer.');
  }
  return options;
}

function parseJsonFromOutput(text) {
  const start = text.indexOf('{');
  const end = text.lastIndexOf('}');
  if (start < 0 || end < start) {
    return null;
  }
  try {
    return JSON.parse(text.slice(start, end + 1));
  } catch {
    return null;
  }
}

function runProcess(root, script, command, args) {
  const run = spawnSync(command, args, {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
  });
  const stdout = run.stdout || '';
  const stderr = run.stderr || '';
  const parsed = parseJsonFromOutput(stdout);
  return {
    script,
    command,
    exit_code: typeof run.status === 'number' ? run.status : 1,
    status: parsed?.status || null,
    mode: parsed?.mode || null,
    summary: parsed?.summary || null,
    csv_issue_map: Array.isArray(parsed?.csv_issue_map) ? parsed.csv_issue_map.slice(0, 20) : [],
    error: parsed?.error || null,
    spawn_error: run.error ? run.error.message : null,
    stderr: stderr.trim(),
  };
}

try {
  const options = parseArgs(process.argv);
  const root = process.cwd();
  const bundleDir = path.resolve(root, options.dir);
  const bundleArgDir = options.dir;
  const manifestFile = path.join(bundleDir, 'manifest.json');
  const csvFile = path.join(bundleDir, 'pricing-input-intake.csv');
  const fillableFile = path.join(bundleDir, 'pricing-input-fillable.json');
  const csvArgFile = path.join(bundleArgDir, 'pricing-input-intake.csv');
  const fillableArgFile = path.join(bundleArgDir, 'pricing-input-fillable.json');
  const php = 'C:\\xampp\\php\\php.exe';

  if (!fs.existsSync(manifestFile)) {
    throw new Error('Bundle manifest is missing: ' + manifestFile);
  }
  if (!fs.existsSync(csvFile)) {
    throw new Error('Operator CSV intake file is missing: ' + csvFile);
  }

  const buildStep = runProcess(root, 'build:revenue-ai-ctrip-pricing-input-from-csv', php, [
    'scripts/import_revenue_ai_ctrip_pricing_inputs.php',
    '--build-json-from-csv=1',
    `--csv-file=${csvArgFile}`,
    `--output=${fillableArgFile}`,
    `--date=${options.date}`,
    `--hotel-id=${options.hotelId}`,
    '--force=1',
  ]);
  const steps = [{ code: 'build_json_from_csv', ...buildStep }];

  let stoppedAt = '';
  let preflightStep = null;
  if (buildStep.exit_code !== 0) {
    stoppedAt = 'build_json_from_csv';
  } else {
    preflightStep = runProcess(root, 'verify:revenue-ai-ctrip-operator-bundle-preflight', process.execPath, [
      'scripts/verify_revenue_ai_ctrip_operator_bundle_preflight.mjs',
      `--dir=${bundleArgDir}`,
      `--date=${options.date}`,
      `--hotel-id=${options.hotelId}`,
    ]);
    steps.push({ code: 'operator_bundle_preflight', ...preflightStep });
    if (preflightStep.exit_code !== 0) {
      stoppedAt = 'operator_bundle_preflight';
    }
  }

  const passed = stoppedAt === '';
  const csvIssues = buildStep.csv_issue_map || [];
  const payload = {
    status: passed ? 'passed' : 'failed',
    scope: {
      business_date: options.date,
      platform: 'ctrip',
      hotel_id: Number(options.hotelId),
      source_scope: 'ctrip_ota_channel',
      verifier_policy: 'operator_csv_to_json_preflight_no_db_no_ota_write',
      database_commit_allowed: false,
      execute_allowed: false,
      auto_write_ota: false,
    },
    summary: {
      stopped_at: stoppedAt || null,
      csv_file: csvFile,
      generated_fillable_file: buildStep.exit_code === 0 ? fillableFile : null,
      generated_local_file_only: buildStep.exit_code === 0,
      database_written: false,
      auto_write_ota: false,
      ready_for_execute_to_pending_review: passed,
      csv_issue_map_count: Number(buildStep.summary?.csv_issue_map_count || csvIssues.length || 0),
      first_csv_issues: csvIssues,
      preflight_summary: preflightStep?.summary || null,
      next_required_gate: passed
        ? 'CSV built and no-execute preflight passed. Execute/generate is still a separate explicit command after operator confirmation.'
        : (stoppedAt === 'build_json_from_csv'
          ? 'Fill pricing-input-intake.csv with real operator-verified Ctrip values, then rerun this CSV preflight.'
          : 'Fix the generated pricing-input-fillable.json or source CSV, then rerun this CSV preflight.'),
    },
    steps,
  };
  console.log(JSON.stringify(payload, null, 2));
  process.exit(passed ? 0 : 1);
} catch (error) {
  console.log(JSON.stringify({
    status: 'failed',
    error: error instanceof Error ? error.message : String(error),
    database_written: false,
    auto_write_ota: false,
  }, null, 2));
  process.exit(1);
}
