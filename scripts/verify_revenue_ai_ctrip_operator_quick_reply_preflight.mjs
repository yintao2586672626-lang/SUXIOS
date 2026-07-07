import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const CSV_HEADERS = [
  'section',
  'business_date',
  'hotel_id',
  'room_type_key',
  'room_type_name',
  'base_price',
  'min_price',
  'max_price',
  'room_count',
  'is_enabled',
  'sort_order',
  'forecast_date',
  'predicted_occupancy',
  'predicted_demand',
  'confidence_score',
  'forecast_method',
  'analysis_date',
  'competitor_name',
  'our_price',
  'competitor_price',
  'ota_platform',
  'confirmed_by',
  'confirmed_at',
  'room_type_source',
  'price_guard_source',
  'demand_forecast_source',
  'competitor_price_source',
  'source_note',
  'required_fields',
  'expected_real_input',
  'format_guard',
  'forbidden_fill',
];

const EVIDENCE_REPLY_KEYS = [
  'confirmed_by',
  'confirmed_at',
  'room_type_source',
  'price_guard_source',
  'demand_forecast_source',
  'competitor_price_source',
];

const LEGACY_ROOM_REPLY_KEYS = [
  'room_type_key',
  'room_type_name',
  'room_count',
  'base_price',
  'min_price',
  'max_price',
];

const LEGACY_COMPETITOR_REPLY_KEYS = [
  'competitor_analysis_date',
  'competitor_room_type_key',
  'competitor_name',
  'our_price',
  'competitor_price',
];

const ROOM_INDEX_FIELD_MAP = {
  key: 'room_type_key',
  name: 'room_type_name',
  count: 'room_count',
  base_price: 'base_price',
  min_price: 'min_price',
  max_price: 'max_price',
};

const COMPETITOR_INDEX_FIELD_MAP = {
  analysis_date: 'analysis_date',
  room_type_key: 'room_type_key',
  name: 'competitor_name',
  our_price: 'our_price',
  competitor_price: 'competitor_price',
};

const INDEXED_REPLY_KEY_EXAMPLES = [
  'room_type_1_key',
  'room_type_1_name',
  'room_type_1_base_price',
  'competitor_sample_1_room_type_key',
  'competitor_sample_1_competitor_price',
];

function parseArgs(argv) {
  const options = {
    dir: '',
    date: '',
    hotelId: '',
    keepTemp: false,
    writeFillable: false,
  };
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--')) {
      continue;
    }
    if (arg === '--keep-temp' || arg === '--keep-temp=1') {
      options.keepTemp = true;
      continue;
    }
    if (arg === '--write-fillable' || arg === '--write-fillable=1') {
      options.writeFillable = true;
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
    } else if (key === 'date') {
      options.date = value;
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = value;
    } else if (key === 'keep-temp' || key === 'keep_temp') {
      options.keepTemp = ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
    } else if (key === 'write-fillable' || key === 'write_fillable') {
      options.writeFillable = ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
    }
  }
  if (!options.dir) {
    throw new Error('Missing --dir=<operator-bundle-dir>.');
  }
  return options;
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
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

function extractReplyBlock(markdown) {
  const fenced = markdown.match(/##\s+Reply Block[\s\S]*?```(?:text)?\s*([\s\S]*?)```/i);
  if (fenced) {
    return fenced[1];
  }
  const start = markdown.search(/^##\s+Reply Block\s*$/im);
  if (start < 0) {
    return markdown;
  }
  const rest = markdown.slice(start);
  const nextHeading = rest.slice(1).search(/^##\s+/m);
  return nextHeading >= 0 ? rest.slice(0, nextHeading + 1) : rest;
}

function parseReplyValues(markdown) {
  const block = extractReplyBlock(markdown);
  const values = {};
  for (const line of block.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) {
      continue;
    }
    const match = trimmed.match(/^([A-Za-z0-9_]+)\s*=\s*(.*)$/);
    if (!match) {
      continue;
    }
    values[match[1]] = match[2].trim();
  }
  return values;
}

function isUnfilled(value) {
  const text = String(value ?? '').trim();
  return text === '' || /^<[^>]+>$/.test(text) || /^YYYY-MM-DD\b/i.test(text);
}

function replyKeyIsUnfilled(key, values) {
  if (key === 'competitor_room_type_key') {
    return isUnfilled(values.competitor_room_type_key) && isUnfilled(values.room_type_key);
  }
  return isUnfilled(values[key]);
}

function collectIndexedRows(values, prefix, fieldMap) {
  const rowsByIndex = new Map();
  const pattern = new RegExp(`^${prefix}_(\\d+)_([A-Za-z0-9_]+)$`);
  for (const [key, value] of Object.entries(values)) {
    const match = key.match(pattern);
    if (!match) {
      continue;
    }
    const index = Number(match[1]);
    const suffix = match[2];
    const field = fieldMap[suffix];
    if (!Number.isSafeInteger(index) || index < 1 || !field) {
      continue;
    }
    const row = rowsByIndex.get(index) || { index };
    row[field] = value;
    rowsByIndex.set(index, row);
  }
  return [...rowsByIndex.values()].sort((a, b) => a.index - b.index);
}

function roomRowsFromReply(values) {
  const indexedRows = collectIndexedRows(values, 'room_type', ROOM_INDEX_FIELD_MAP);
  if (indexedRows.length > 0) {
    return {
      format: 'indexed_multi_room',
      rows: indexedRows,
    };
  }
  return {
    format: 'legacy_single_room',
    rows: [{
      index: 1,
      room_type_key: values.room_type_key || '',
      room_type_name: values.room_type_name || '',
      room_count: values.room_count || '',
      base_price: values.base_price || '',
      min_price: values.min_price || '',
      max_price: values.max_price || '',
    }],
  };
}

function competitorRowsFromReply(values, roomRows) {
  const indexedRows = collectIndexedRows(values, 'competitor_sample', COMPETITOR_INDEX_FIELD_MAP);
  const defaultRoomTypeKey = roomRows.length === 1 ? String(roomRows[0].room_type_key || '') : '';
  if (indexedRows.length > 0) {
    return {
      format: 'indexed_multi_competitor_sample',
      rows: indexedRows.map((row) => ({
        ...row,
        room_type_key: row.room_type_key || defaultRoomTypeKey,
      })),
    };
  }
  return {
    format: 'legacy_single_competitor_sample',
    rows: [{
      index: 1,
      analysis_date: values.competitor_analysis_date || '',
      room_type_key: values.competitor_room_type_key || values.room_type_key || defaultRoomTypeKey,
      competitor_name: values.competitor_name || '',
      our_price: values.our_price || '',
      competitor_price: values.competitor_price || '',
    }],
  };
}

function missingIndexedFields(rows, prefix, fieldMap, requiredFields) {
  const suffixByField = Object.fromEntries(Object.entries(fieldMap).map(([suffix, field]) => [field, suffix]));
  const missing = [];
  for (const row of rows) {
    for (const field of requiredFields) {
      if (isUnfilled(row[field])) {
        missing.push(`${prefix}_${row.index}_${suffixByField[field] || field}`);
      }
    }
  }
  return missing;
}

function buildMissingReplyKeys(values, roomReply, competitorReply) {
  const missing = EVIDENCE_REPLY_KEYS.filter((key) => replyKeyIsUnfilled(key, values));
  if (roomReply.format === 'indexed_multi_room') {
    missing.push(...missingIndexedFields(roomReply.rows, 'room_type', ROOM_INDEX_FIELD_MAP, [
      'room_type_key',
      'room_type_name',
      'room_count',
      'base_price',
      'min_price',
      'max_price',
    ]));
  } else {
    missing.push(...LEGACY_ROOM_REPLY_KEYS.filter((key) => replyKeyIsUnfilled(key, values)));
  }

  if (competitorReply.format === 'indexed_multi_competitor_sample') {
    missing.push(...missingIndexedFields(competitorReply.rows, 'competitor_sample', COMPETITOR_INDEX_FIELD_MAP, [
      'analysis_date',
      'room_type_key',
      'competitor_name',
      'our_price',
      'competitor_price',
    ]));
  } else {
    missing.push(...LEGACY_COMPETITOR_REPLY_KEYS.filter((key) => replyKeyIsUnfilled(key, values)));
  }

  return missing;
}

function csvCell(value) {
  const text = String(value ?? '');
  if (!/[",\r\n]/.test(text)) {
    return text;
  }
  return `"${text.replace(/"/g, '""')}"`;
}

function csvRow(values) {
  return CSV_HEADERS.map((header) => csvCell(values[header] || '')).join(',');
}

function baseRow(date, hotelId) {
  const row = Object.fromEntries(CSV_HEADERS.map((header) => [header, '']));
  row.business_date = date;
  row.hotel_id = String(hotelId);
  row.forbidden_fill = 'sample, guessed, fallback, verifier-only, non-Ctrip OTA, or whole-hotel value';
  return row;
}

function buildCsv(values, date, hotelId, roomReply, competitorReply) {
  const rows = [];
  const evidence = baseRow(date, hotelId);
  evidence.section = 'evidence';
  evidence.confirmed_by = values.confirmed_by || '';
  evidence.confirmed_at = values.confirmed_at || '';
  evidence.room_type_source = values.room_type_source || '';
  evidence.price_guard_source = values.price_guard_source || '';
  evidence.demand_forecast_source = values.demand_forecast_source || '';
  evidence.competitor_price_source = values.competitor_price_source || '';
  evidence.required_fields = 'confirmed_by; confirmed_at; room_type_source; price_guard_source; demand_forecast_source; competitor_price_source';
  evidence.expected_real_input = 'Operator accountability and human-verifiable source notes for the submitted Ctrip pricing inputs.';
  evidence.format_guard = 'confirmed_at starts with YYYY-MM-DD; all source fields are non-empty evidence notes';
  rows.push(evidence);

  for (const roomInput of roomReply.rows) {
    const room = baseRow(date, hotelId);
    room.section = 'room_type';
    room.room_type_key = roomInput.room_type_key || '';
    room.room_type_name = roomInput.room_type_name || '';
    room.base_price = roomInput.base_price || '';
    room.min_price = roomInput.min_price || '';
    room.max_price = roomInput.max_price || '';
    room.room_count = roomInput.room_count || '';
    room.is_enabled = 'true';
    room.sort_order = String(roomInput.index || rows.length);
    room.source_note = values.room_type_source || '';
    room.required_fields = 'room_type_key; room_type_name; base_price; min_price; max_price; room_count; is_enabled; source_note';
    room.expected_real_input = 'Operator-verified Ctrip room type, current sell price, floor/protection price, upper guard, and sellable room count.';
    room.format_guard = 'base/min/max numeric > 0; min_price <= base_price; max_price >= base_price; room_count positive integer';
    rows.push(room);
  }

  for (const competitorInput of competitorReply.rows) {
    const competitor = baseRow(date, hotelId);
    competitor.section = 'competitor_price_sample';
    competitor.room_type_key = competitorInput.room_type_key || '';
    competitor.analysis_date = competitorInput.analysis_date || date;
    competitor.competitor_name = competitorInput.competitor_name || '';
    competitor.our_price = competitorInput.our_price || '';
    competitor.competitor_price = competitorInput.competitor_price || '';
    competitor.ota_platform = 'ctrip';
    competitor.source_note = values.competitor_price_source || '';
    competitor.required_fields = 'room_type_key; analysis_date; competitor_name; our_price; competitor_price; ota_platform; source_note';
    competitor.expected_real_input = 'Recent 7-day Ctrip competitor price sample for the same comparable room context.';
    competitor.format_guard = 'analysis_date within 7 days ending at business_date; our_price and competitor_price numeric > 0; ota_platform=ctrip';
    rows.push(competitor);
  }

  return [CSV_HEADERS.join(','), ...rows.map(csvRow)].join('\n') + '\n';
}

function cleanupTemp(tempDir, keepTemp) {
  if (!keepTemp && tempDir) {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
}

let tempDir = '';
let keepTemp = false;

try {
  const options = parseArgs(process.argv);
  keepTemp = options.keepTemp;
  const root = process.cwd();
  const bundleDir = path.resolve(root, options.dir);
  const manifestFile = path.join(bundleDir, 'manifest.json');
  const replyFile = path.join(bundleDir, 'OPERATOR_QUICK_REPLY.md');
  const bundleFillableFile = path.join(bundleDir, 'pricing-input-fillable.json');
  const php = 'C:\\xampp\\php\\php.exe';

  if (!fs.existsSync(manifestFile)) {
    throw new Error('Bundle manifest is missing: ' + manifestFile);
  }
  if (!fs.existsSync(replyFile)) {
    throw new Error('Quick reply file is missing: ' + replyFile);
  }

  const manifest = readJson(manifestFile);
  const date = options.date || String(manifest?.scope?.business_date || '');
  const hotelId = options.hotelId || String(manifest?.scope?.hotel_id || '');
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    throw new Error('Invalid or missing --date, expected YYYY-MM-DD.');
  }
  if (!/^[1-9]\d*$/.test(hotelId)) {
    throw new Error('Invalid or missing --hotel-id, expected a positive integer.');
  }
  if (manifest?.scope?.source_scope !== 'ctrip_ota_channel' || manifest?.scope?.auto_write_ota !== false) {
    throw new Error('Bundle manifest scope is not safe for Ctrip-only no-OTA-write quick reply preflight.');
  }

  const replyText = fs.readFileSync(replyFile, 'utf8');
  const replyValues = parseReplyValues(replyText);
  const roomReply = roomRowsFromReply(replyValues);
  const competitorReply = competitorRowsFromReply(replyValues, roomReply.rows);
  const missingReplyKeys = buildMissingReplyKeys(replyValues, roomReply, competitorReply);
  const csvText = buildCsv(replyValues, date, hotelId, roomReply, competitorReply);
  tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-ctrip-quick-reply-'));
  const tempCsvFile = path.join(tempDir, 'pricing-input-intake.csv');
  const tempFillableFile = path.join(tempDir, 'pricing-input-fillable.json');
  fs.writeFileSync(tempCsvFile, csvText, 'utf8');

  const buildStep = runProcess(root, 'build:revenue-ai-ctrip-pricing-input-from-csv', php, [
    'scripts/import_revenue_ai_ctrip_pricing_inputs.php',
    '--build-json-from-csv=1',
    `--csv-file=${tempCsvFile}`,
    `--output=${tempFillableFile}`,
    `--date=${date}`,
    `--hotel-id=${hotelId}`,
    '--force=1',
  ]);
  const steps = [{ code: 'build_json_from_quick_reply', ...buildStep }];

  let stoppedAt = '';
  if (buildStep.exit_code !== 0) {
    stoppedAt = 'build_json_from_quick_reply';
  } else {
    const commonArgs = [
      `--file=${tempFillableFile}`,
      `--date=${date}`,
      `--hotel-id=${hotelId}`,
    ];
    const gatedSteps = [
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
    for (const step of gatedSteps) {
      const result = runProcess(root, step.script, step.command, step.args);
      steps.push({ code: step.code, ...result });
      if (result.exit_code !== 0) {
        stoppedAt = step.code;
        break;
      }
    }
  }

  const passed = stoppedAt === '';
  let bundleFillableWritten = false;
  if (passed && options.writeFillable) {
    fs.copyFileSync(tempFillableFile, bundleFillableFile);
    bundleFillableWritten = true;
  }
  const payload = {
    status: passed ? 'passed' : 'failed',
    scope: {
      business_date: date,
      platform: 'ctrip',
      hotel_id: Number(hotelId),
      source_scope: 'ctrip_ota_channel',
      verifier_policy: 'operator_quick_reply_preflight_no_db_no_ota_write',
      database_commit_allowed: false,
      execute_allowed: false,
      auto_write_ota: false,
      bundle_file_write_allowed: options.writeFillable,
    },
    summary: {
      stopped_at: stoppedAt || null,
      quick_reply_file: replyFile,
      preflight_policy: 'temporary_local_files_no_db_no_ota_write',
      write_fillable_requested: options.writeFillable,
      write_fillable_policy: 'only_after_all_no_execute_gates_pass_no_db_no_ota_write',
      bundle_fillable_file: bundleFillableFile,
      bundle_fillable_written: bundleFillableWritten,
      parsed_key_count: Object.keys(replyValues).length,
      quick_reply_format: {
        room_types: roomReply.format,
        competitor_samples: competitorReply.format,
      },
      room_type_row_count: roomReply.rows.length,
      competitor_sample_row_count: competitorReply.rows.length,
      missing_or_unfilled_reply_keys: missingReplyKeys,
      generated_local_file_only: true,
      generated_temp_files_retained: keepTemp,
      temp_dir: keepTemp ? tempDir : null,
      generated_csv_file: keepTemp ? tempCsvFile : null,
      generated_fillable_file: keepTemp && buildStep.exit_code === 0 ? tempFillableFile : null,
      database_written: false,
      auto_write_ota: false,
      ready_for_execute_to_pending_review: passed,
      next_required_gate: passed
        ? (bundleFillableWritten
          ? 'Quick reply passed local no-execute preflight and wrote pricing-input-fillable.json. Run the protected bundle preflight before any pending-review generation.'
          : 'Quick reply passed local no-execute preflight. Rerun with --write-fillable=1 to write pricing-input-fillable.json locally, then run the protected bundle preflight before any pending-review generation.')
        : 'Fill OPERATOR_QUICK_REPLY.md with real operator-verified Ctrip values, then rerun this quick reply preflight.',
    },
    steps,
  };
  console.log(JSON.stringify(payload, null, 2));
  cleanupTemp(tempDir, keepTemp);
  process.exit(passed ? 0 : 1);
} catch (error) {
  cleanupTemp(tempDir, keepTemp);
  console.log(JSON.stringify({
    status: 'failed',
    error: error instanceof Error ? error.message : String(error),
    database_written: false,
    auto_write_ota: false,
  }, null, 2));
  process.exit(1);
}
