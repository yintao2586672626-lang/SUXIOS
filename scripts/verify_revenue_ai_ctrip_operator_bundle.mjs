import fs from 'node:fs';
import path from 'node:path';

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
    const value = arg.slice(eq + 1);
    if (key === 'dir') {
      options.dir = value.trim();
    } else if (key === 'date') {
      options.date = value.trim();
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = value.trim();
    }
  }
  if (!options.dir) {
    throw new Error('Missing --dir=<operator-bundle-dir>.');
  }
  if (options.date && !/^\d{4}-\d{2}-\d{2}$/.test(options.date)) {
    throw new Error('Invalid --date, expected YYYY-MM-DD.');
  }
  if (options.hotelId && !/^[1-9]\d*$/.test(options.hotelId)) {
    throw new Error('Invalid --hotel-id, expected a positive integer.');
  }
  return options;
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function hasPlaceholder(value) {
  if (Array.isArray(value)) {
    return value.some(hasPlaceholder);
  }
  if (value && typeof value === 'object') {
    return Object.values(value).some(hasPlaceholder);
  }
  return typeof value === 'string' && value.includes('<') && value.includes('>');
}

function countPlaceholders(value) {
  if (Array.isArray(value)) {
    return value.reduce((sum, item) => sum + countPlaceholders(item), 0);
  }
  if (value && typeof value === 'object') {
    return Object.values(value).reduce((sum, item) => sum + countPlaceholders(item), 0);
  }
  return typeof value === 'string' && value.includes('<') && value.includes('>') ? 1 : 0;
}

function check(checks, code, ok, details = {}) {
  checks.push({ code, status: ok ? 'passed' : 'failed', details });
}

try {
  const options = parseArgs(process.argv);
  const bundleDir = path.resolve(options.dir);
  const files = {
    manifest: path.join(bundleDir, 'manifest.json'),
    template: path.join(bundleDir, 'pricing-input-template.json'),
    fillable: path.join(bundleDir, 'pricing-input-fillable.json'),
    operatorPacket: path.join(bundleDir, 'operator-packet.md'),
    pendingReview: path.join(bundleDir, 'pending-review-packet.json'),
    currentScope: path.join(bundleDir, 'current-scope.json'),
  };

  const checks = [];
  check(checks, 'bundle_dir_exists', fs.existsSync(bundleDir) && fs.statSync(bundleDir).isDirectory(), { bundleDir });
  for (const [key, filePath] of Object.entries(files)) {
    check(checks, `${key}_file_exists`, fs.existsSync(filePath) && fs.statSync(filePath).isFile(), { file: filePath });
  }

  const manifest = readJson(files.manifest);
  const template = readJson(files.template);
  const fillable = readJson(files.fillable);

  const expectedDate = options.date || String(manifest?.scope?.business_date || fillable?.business_date || '');
  const expectedHotelId = options.hotelId || String(manifest?.scope?.hotel_id || fillable?.hotel_id || '');
  const manifestCommands = manifest?.next_commands_after_filling_template || {};
  const commandText = JSON.stringify(manifestCommands);
  const fillableText = JSON.stringify(fillable);

  check(checks, 'scope_ctrip_only', manifest?.scope?.source_scope === 'ctrip_ota_channel'
    && manifest?.scope?.auto_write_ota === false
    && manifest?.scope?.database_written === false
    && manifest?.scope?.meituan_scope_included === false, {
    scope: manifest?.scope || null,
  });
  check(checks, 'date_matches', !expectedDate || (fillable.business_date === expectedDate && manifest?.scope?.business_date === expectedDate), {
    expectedDate,
    fillableDate: fillable.business_date,
    manifestDate: manifest?.scope?.business_date,
  });
  check(checks, 'hotel_matches', !expectedHotelId || (String(fillable.hotel_id) === expectedHotelId && String(manifest?.scope?.hotel_id) === expectedHotelId), {
    expectedHotelId,
    fillableHotelId: fillable.hotel_id,
    manifestHotelId: manifest?.scope?.hotel_id,
  });

  const allowedFillableKeys = [
    'business_date',
    'hotel_id',
    'platform',
    'input_scope',
    'source_scope',
    'evidence_status',
    'target_workflow',
    'auto_write_ota',
    'room_types',
    'demand_forecasts',
    'competitor_price_samples',
  ];
  const fillableKeys = Object.keys(fillable).sort();
  const extraFillableKeys = fillableKeys.filter((key) => !allowedFillableKeys.includes(key));
  const missingFillableKeys = allowedFillableKeys.filter((key) => !Object.prototype.hasOwnProperty.call(fillable, key));
  check(checks, 'fillable_contains_only_real_input_fields', extraFillableKeys.length === 0 && missingFillableKeys.length === 0, {
    extraFillableKeys,
    missingFillableKeys,
  });
  check(checks, 'fillable_has_no_execution_metadata', !('verification_commands' in fillable) && !('operator_fill_required' in fillable) && !('current_preflight' in fillable), {
    fillableKeys,
  });
  check(checks, 'fillable_scope_safe', fillable.platform === 'ctrip'
    && fillable.source_scope === 'ctrip_ota_channel'
    && fillable.auto_write_ota === false
    && !fillableText.toLowerCase().includes('meituan'), {
    platform: fillable.platform,
    source_scope: fillable.source_scope,
    auto_write_ota: fillable.auto_write_ota,
  });
  check(checks, 'fillable_arrays_present', Array.isArray(fillable.room_types)
    && fillable.room_types.length > 0
    && Array.isArray(fillable.demand_forecasts)
    && fillable.demand_forecasts.length > 0
    && Array.isArray(fillable.competitor_price_samples)
    && fillable.competitor_price_samples.length > 0, {
    room_types: Array.isArray(fillable.room_types) ? fillable.room_types.length : null,
    demand_forecasts: Array.isArray(fillable.demand_forecasts) ? fillable.demand_forecasts.length : null,
    competitor_price_samples: Array.isArray(fillable.competitor_price_samples) ? fillable.competitor_price_samples.length : null,
  });

  const inputCommandNames = ['lint_only', 'validate_only', 'dry_run', 'pre_execute_gate', 'execute_to_pending_review'];
  const commandsPointToFillable = inputCommandNames.every((name) => String(manifestCommands?.[name] || '').includes('pricing-input-fillable.json'));
  const commandsAvoidTemplate = inputCommandNames.every((name) => !String(manifestCommands?.[name] || '').includes('pricing-input-template.json'));
  const commandsScoped = inputCommandNames.every((name) => String(manifestCommands?.[name] || '').includes(`--hotel-id=${expectedHotelId}`));
  check(checks, 'input_commands_use_fillable_file', commandsPointToFillable && commandsAvoidTemplate, {
    inputCommandNames,
  });
  check(checks, 'input_commands_are_hotel_scoped', !expectedHotelId || commandsScoped, {
    expectedHotelId,
  });
  check(checks, 'commands_do_not_write_ota', !commandText.includes('auto_write_ota=true') && !commandText.includes('write-ota'), {});
  check(checks, 'template_keeps_context_metadata', template?.verification_commands && template?.operator_fill_required && template?.current_preflight, {});

  const failed = checks.filter((item) => item.status !== 'passed');
  const placeholderCount = countPlaceholders(fillable);
  const payload = {
    status: failed.length === 0 ? 'passed' : 'failed',
    scope: {
      business_date: expectedDate,
      platform: 'ctrip',
      hotel_id: expectedHotelId ? Number(expectedHotelId) : null,
      source_scope: 'ctrip_ota_channel',
      verifier_policy: 'operator_bundle_structure_only_no_db_no_ota_write',
      database_touched: false,
      auto_write_ota: false,
    },
    input_status: {
      status: placeholderCount > 0 ? 'pending_operator_real_values' : 'ready_for_lint',
      placeholder_count: placeholderCount,
      can_generate_pending_review: false,
      reason: placeholderCount > 0 ? 'operator_verified_values_required' : 'run_lint_validate_dry_run_before_execute',
    },
    checks,
  };
  console.log(JSON.stringify(payload, null, 2));
  process.exit(failed.length === 0 ? 0 : 1);
} catch (error) {
  console.log(JSON.stringify({
    status: 'failed',
    error: error instanceof Error ? error.message : String(error),
  }, null, 2));
  process.exit(1);
}
