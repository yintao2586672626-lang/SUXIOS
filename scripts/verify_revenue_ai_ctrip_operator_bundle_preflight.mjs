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

function readJsonFile(file) {
  if (!fs.existsSync(file)) {
    return null;
  }
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

function summarizeRealInputChecklist(file) {
  const operatorActionSheetFile = path.join(path.dirname(file), 'operator-action-sheet.csv');
  const operatorConfirmationBriefFile = path.join(path.dirname(file), 'OPERATOR_CONFIRMATION_BRIEF.md');
  const operatorConfirmationBriefCsvFile = path.join(path.dirname(file), 'operator-confirmation-brief.csv');
  const checklist = readJsonFile(file);
  if (!checklist || typeof checklist !== 'object') {
    return {
      status: 'missing_or_invalid',
      checklist_file: file,
      operator_action_sheet_file: operatorActionSheetFile,
      operator_action_sheet_status: fs.existsSync(operatorActionSheetFile) ? 'candidate_to_operator_action_sheet_not_importable' : 'missing',
      operator_action_sheet_use: 'Regenerate the operator bundle before using candidate-to-field guidance.',
      operator_confirmation_brief_file: operatorConfirmationBriefFile,
      operator_confirmation_brief_csv_file: operatorConfirmationBriefCsvFile,
      operator_confirmation_brief_status: fs.existsSync(operatorConfirmationBriefFile) && fs.existsSync(operatorConfirmationBriefCsvFile)
        ? 'minimum_human_confirmation_brief_not_importable'
        : 'missing',
      operator_confirmation_brief_use: 'Regenerate the operator bundle before using the minimum confirmation brief.',
      placeholder_count: null,
      missing_input_count: null,
      first_missing_inputs: [],
      next_required_gate: 'Regenerate the operator bundle before preflight.',
    };
  }

  const items = Array.isArray(checklist.items) ? checklist.items : [];
  const missingInputGroups = {};
  for (const item of items) {
    const group = String(item?.group || 'unknown');
    if (!missingInputGroups[group]) {
      missingInputGroups[group] = {
        count: 0,
        paths: [],
      };
    }
    missingInputGroups[group].count += 1;
    if (missingInputGroups[group].paths.length < 5) {
      missingInputGroups[group].paths.push(String(item?.path || ''));
    }
  }

  return {
    status: String(checklist.status || 'unknown'),
    checklist_file: file,
    operator_action_sheet_file: operatorActionSheetFile,
    operator_action_sheet_status: fs.existsSync(operatorActionSheetFile) ? 'candidate_to_operator_action_sheet_not_importable' : 'missing',
    operator_action_sheet_use: 'Open this CSV to map Ctrip candidate hints to pricing-input-intake.csv fields; it is not importable and does not replace operator-confirmed values.',
    operator_action_sheet_target_file: 'pricing-input-intake.csv',
    operator_action_sheet_importable_value: false,
    operator_action_sheet_auto_write_ota: false,
    operator_confirmation_brief_file: operatorConfirmationBriefFile,
    operator_confirmation_brief_csv_file: operatorConfirmationBriefCsvFile,
    operator_confirmation_brief_status: fs.existsSync(operatorConfirmationBriefFile) && fs.existsSync(operatorConfirmationBriefCsvFile)
      ? 'minimum_human_confirmation_brief_not_importable'
      : 'missing',
    operator_confirmation_brief_use: 'Open this brief first for the shortest Ctrip-only list of fields that still require operator confirmation; it is not importable and carries no confirmed business values.',
    operator_confirmation_brief_target_file: 'pricing-input-intake.csv',
    operator_confirmation_brief_importable_value: false,
    operator_confirmation_brief_auto_write_ota: false,
    placeholder_count: Number.isFinite(Number(checklist.placeholder_count)) ? Number(checklist.placeholder_count) : null,
    can_generate_pending_review: checklist.can_generate_pending_review === true,
    next_required_gate: String(checklist.next_required_gate || 'Fill pricing-input-fillable.json, then rerun preflight.'),
    required_before_execute: Array.isArray(checklist.current_blocker?.required_before_execute)
      ? checklist.current_blocker.required_before_execute.map(String)
      : [],
    missing_input_count: items.length,
    missing_input_groups: missingInputGroups,
    first_missing_inputs: items.slice(0, 10).map((item) => ({
      path: String(item?.path || ''),
      group: String(item?.group || ''),
      field: String(item?.field || ''),
      csv_file: String(item?.csv_file || ''),
      csv_row_number: Number.isFinite(Number(item?.csv_row_number)) ? Number(item.csv_row_number) : null,
      csv_section: String(item?.csv_section || ''),
      csv_column: String(item?.csv_column || ''),
      expected_real_input: String(item?.expected_real_input || ''),
      format_guard: String(item?.format_guard || ''),
      forbidden_fill: String(item?.forbidden_fill || ''),
    })),
    stop_conditions: Array.isArray(checklist.stop_conditions) ? checklist.stop_conditions.map(String) : [],
  };
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
  const realInputChecklistFile = path.join(bundleDir, 'real-input-checklist.json');
  const php = 'C:\\xampp\\php\\php.exe';

  if (!fs.existsSync(manifestFile)) {
    throw new Error('Bundle manifest is missing: ' + manifestFile);
  }
  if (!fs.existsSync(fillableFile)) {
    throw new Error('Fillable pricing input file is missing: ' + fillableFile);
  }

  const realInputHandoff = summarizeRealInputChecklist(realInputChecklistFile);
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
      real_input_handoff: realInputHandoff,
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
