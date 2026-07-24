import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

function parseArgs(argv) {
  const options = {
    dir: '',
    date: '',
    hotelId: '',
    execute: false,
  };
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--')) {
      continue;
    }
    if (arg === '--execute' || arg === '--execute=1') {
      options.execute = true;
      continue;
    }
    if (!arg.includes('=')) {
      continue;
    }
    const eq = arg.indexOf('=');
    const key = arg.slice(2, eq);
    const value = arg.slice(eq + 1).trim();
    if (key === 'dir') {
      options.dir = value;
    } else if (key === 'date' || key === 'business-date' || key === 'business_date') {
      options.date = value;
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = value;
    } else if (key === 'execute') {
      options.execute = ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
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

function readJson(file) {
  return parseJsonTextSafely(fs.readFileSync(file, 'utf8'), 'revenue_ai_operator_json');
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
    scope: parsed?.scope || null,
    input_status: parsed?.input_status || null,
    error: parsed?.error || null,
    spawn_error: run.error ? run.error.message : null,
    stderr: stderr.trim(),
  };
}

function pushStep(results, root, step) {
  const result = runProcess(root, step.script, step.command, step.args);
  results.push({ code: step.code, ...result });
  return result;
}

try {
  const options = parseArgs(process.argv);
  const root = process.cwd();
  const bundleDir = path.resolve(root, options.dir);
  const manifestFile = path.join(bundleDir, 'manifest.json');
  const fillableFile = path.join(bundleDir, 'pricing-input-fillable.json');
  const php = 'C:\\xampp\\php\\php.exe';

  if (!fs.existsSync(manifestFile)) {
    throw new Error('Bundle manifest is missing: ' + manifestFile);
  }
  const manifest = readJson(manifestFile);
  if (manifest?.scope?.source_scope !== 'ctrip_ota_channel' || manifest?.scope?.auto_write_ota !== false) {
    throw new Error('Bundle manifest scope is not safe for Ctrip-only quick reply execution.');
  }

  const steps = [];
  let stoppedAt = '';
  const quickReplyArgs = [
    'scripts/verify_revenue_ai_ctrip_operator_quick_reply_preflight.mjs',
    `--dir=${options.dir}`,
    `--date=${options.date}`,
    `--hotel-id=${options.hotelId}`,
  ];
  if (options.execute) {
    quickReplyArgs.push('--write-fillable=1');
  }
  const quickReplyStep = pushStep(steps, root, {
    code: options.execute ? 'quick_reply_apply_to_fillable' : 'quick_reply_preflight_only',
    script: 'verify:revenue-ai-ctrip-operator-quick-reply-preflight',
    command: process.execPath,
    args: quickReplyArgs,
  });
  if (quickReplyStep.exit_code !== 0) {
    stoppedAt = options.execute ? 'quick_reply_apply_to_fillable' : 'quick_reply_preflight_only';
  }

  let bundlePreflightStep = null;
  let executeStep = null;
  if (!stoppedAt && options.execute) {
    bundlePreflightStep = pushStep(steps, root, {
      code: 'operator_bundle_preflight',
      script: 'verify:revenue-ai-ctrip-operator-bundle-preflight',
      command: process.execPath,
      args: [
        'scripts/verify_revenue_ai_ctrip_operator_bundle_preflight.mjs',
        `--dir=${options.dir}`,
        `--date=${options.date}`,
        `--hotel-id=${options.hotelId}`,
      ],
    });
    if (bundlePreflightStep.exit_code !== 0) {
      stoppedAt = 'operator_bundle_preflight';
    }
  }

  if (!stoppedAt && options.execute) {
    executeStep = pushStep(steps, root, {
      code: 'execute_to_pending_review',
      script: 'run:revenue-ai-ctrip-pricing-file-to-pending-review',
      command: php,
      args: [
        'scripts/execute_revenue_ai_ctrip_pricing_file.php',
        `--file=${fillableFile}`,
        `--date=${options.date}`,
        `--hotel-id=${options.hotelId}`,
        '--execute=1',
        '--generate=1',
      ],
    });
    if (executeStep.exit_code !== 0) {
      stoppedAt = 'execute_to_pending_review';
    }
  }

  const passed = stoppedAt === '';
  const executeSummary = executeStep?.summary?.execute_summary || {};
  const pendingReviewCreated = Boolean(
    options.execute
      && passed
      && executeSummary?.committed === true
      && executeStep?.summary?.post_execute_review_queue?.status === 'pending_review'
  );
  const payload = {
    status: passed ? 'passed' : 'failed',
    mode: options.execute ? 'quick_reply_to_pending_review_explicit_execute' : 'quick_reply_preflight_only_no_persistence',
    scope: {
      business_date: options.date,
      platform: 'ctrip',
      enabled_channels: ['ctrip'],
      hotel_id: Number(options.hotelId),
      source_scope: 'ctrip_ota_channel',
      source_policy: 'quick_reply_gate_then_pending_review_no_ota_write',
      execute_requested: options.execute,
      database_commit_allowed: options.execute,
      database_written: pendingReviewCreated,
      auto_write_ota: false,
    },
    summary: {
      stopped_at: stoppedAt || null,
      quick_reply_file: path.join(bundleDir, 'OPERATOR_QUICK_REPLY.md'),
      fillable_file: fillableFile,
      bundle_fillable_write_requested: options.execute,
      ready_for_pending_review: options.execute ? pendingReviewCreated : passed,
      pending_review_created: pendingReviewCreated,
      next_required_gate: passed
        ? (options.execute
          ? 'Inspect the pending Ctrip AI review packet, then perform manual review before operation intent.'
          : 'Rerun with --execute=1 to write local pricing-input-fillable.json and create pending Ctrip AI review items.')
        : 'Fix the returned gate issues, then rerun this quick reply to pending-review runner.',
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
