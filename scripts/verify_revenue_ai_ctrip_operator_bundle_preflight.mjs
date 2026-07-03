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

function quoteCmdArg(value) {
  return `"${String(value).replace(/"/g, '""')}"`;
}

function runNpm(root, script, args) {
  const command = ['npm.cmd', 'run', script, '--', ...args.map(quoteCmdArg)].join(' ');
  const run = spawnSync('cmd.exe', ['/d', '/s', '/c', command], {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
  });
  const stdout = run.stdout || '';
  const stderr = run.stderr || '';
  const parsed = parseJsonFromOutput(stdout);
  return {
    script,
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
      args: [`--dir=${bundleArgDir}`, `--date=${options.date}`, `--hotel-id=${options.hotelId}`],
    },
    {
      code: 'lint_only',
      script: 'lint:revenue-ai-ctrip-pricing-inputs',
      args: commonArgs,
    },
    {
      code: 'validate_only_rollback',
      script: 'validate:revenue-ai-ctrip-pricing-inputs',
      args: commonArgs,
    },
    {
      code: 'dry_run_rollback',
      script: 'import:revenue-ai-ctrip-pricing-inputs',
      args: commonArgs,
    },
    {
      code: 'pre_execute_gate_rollback',
      script: 'verify:revenue-ai-ctrip-pricing-file',
      args: commonArgs,
    },
  ];

  const results = [];
  let stoppedAt = '';
  for (const step of steps) {
    const result = runNpm(root, step.script, step.args);
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
