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
    input_status: parsed?.input_status || null,
    summary: parsed?.summary || null,
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
  const fillableFile = path.join(bundleDir, 'pricing-input-fillable.json');
  const fillableArgFile = path.join(options.dir, 'pricing-input-fillable.json');
  const manifestFile = path.join(bundleDir, 'manifest.json');
  const php = 'C:\\xampp\\php\\php.exe';

  if (!fs.existsSync(manifestFile)) {
    throw new Error('Bundle manifest is missing: ' + manifestFile);
  }
  if (!fs.existsSync(fillableFile)) {
    throw new Error('Fillable pricing input file is missing: ' + fillableFile);
  }

  const commonArgs = [
    `--file=${fillableArgFile}`,
    `--date=${options.date}`,
    `--hotel-id=${options.hotelId}`,
  ];
  const steps = [
    {
      code: 'bundle_structure',
      script: 'verify:revenue-ai-ctrip-operator-bundle',
      command: process.execPath,
      args: ['scripts/verify_revenue_ai_ctrip_operator_bundle.mjs', `--dir=${bundleArgDir}`, `--date=${options.date}`, `--hotel-id=${options.hotelId}`],
    },
    {
      code: 'lint_only',
      script: 'lint:revenue-ai-ctrip-pricing-inputs',
      command: php,
      args: ['scripts/import_revenue_ai_ctrip_pricing_inputs.php', '--lint-only=1', ...commonArgs],
    },
    {
      code: 'validate_only_rollback',
      script: 'validate:revenue-ai-ctrip-pricing-inputs',
      command: php,
      args: ['scripts/import_revenue_ai_ctrip_pricing_inputs.php', '--validate-only=1', ...commonArgs],
    },
    {
      code: 'dry_run_rollback',
      script: 'import:revenue-ai-ctrip-pricing-inputs',
      command: php,
      args: ['scripts/import_revenue_ai_ctrip_pricing_inputs.php', ...commonArgs],
    },
    {
      code: 'pre_execute_gate_rollback',
      script: 'verify:revenue-ai-ctrip-pricing-file',
      command: php,
      args: ['scripts/verify_revenue_ai_ctrip_pricing_input_pipeline.php', ...commonArgs],
    },
  ];

  const results = [];
  let stoppedAt = '';
  for (const step of steps) {
    const result = runProcess(root, step.script, step.command, step.args);
    results.push({ code: step.code, ...result });
    if (result.exit_code !== 0) {
      stoppedAt = step.code;
      break;
    }
  }

  const passed = stoppedAt === '';
  const payload = {
    status: passed ? 'passed' : 'failed',
    scope: {
      business_date: options.date,
      platform: 'ctrip',
      hotel_id: Number(options.hotelId),
      source_scope: 'ctrip_ota_channel',
      verifier_policy: 'operator_bundle_preflight_no_execute_no_ota_write',
      database_commit_allowed: false,
      execute_allowed: false,
      auto_write_ota: false,
    },
    summary: {
      stopped_at: stoppedAt || null,
      ready_for_execute_to_pending_review: passed,
      next_execute_command_allowed_only_after_pass: passed,
      fillable_file: fillableFile,
    },
    steps: results,
  };
  console.log(JSON.stringify(payload, null, 2));
  process.exit(passed ? 0 : 1);
} catch (error) {
  console.log(JSON.stringify({
    status: 'failed',
    error: error instanceof Error ? error.message : String(error),
  }, null, 2));
  process.exit(1);
}
