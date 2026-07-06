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
    operatorIntakeForm: path.join(bundleDir, 'OPERATOR_INTAKE_FORM.md'),
    closureRunbook: path.join(bundleDir, 'CTRIP_CLOSURE_RUNBOOK.md'),
    realInputTodo: path.join(bundleDir, 'REAL_INPUT_TODO.md'),
    realInputChecklist: path.join(bundleDir, 'real-input-checklist.json'),
    pricingInputSchema: path.join(bundleDir, 'pricing-input.schema.json'),
    pricingInputIntakeCsv: path.join(bundleDir, 'pricing-input-intake.csv'),
    operatorInputLocatorsCsv: path.join(bundleDir, 'operator-input-locators.csv'),
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
  const realInputChecklist = readJson(files.realInputChecklist);
  const pricingInputSchema = readJson(files.pricingInputSchema);

  const expectedDate = options.date || String(manifest?.scope?.business_date || fillable?.business_date || '');
  const expectedHotelId = options.hotelId || String(manifest?.scope?.hotel_id || fillable?.hotel_id || '');
  const manifestCommands = manifest?.next_commands_after_filling_template || {};
  const commandText = JSON.stringify(manifestCommands);
  const fillableText = JSON.stringify(fillable);
  const operatorIntakeFormText = fs.existsSync(files.operatorIntakeForm) ? fs.readFileSync(files.operatorIntakeForm, 'utf8') : '';
  const closureRunbookText = fs.existsSync(files.closureRunbook) ? fs.readFileSync(files.closureRunbook, 'utf8') : '';
  const pricingInputIntakeCsvText = fs.existsSync(files.pricingInputIntakeCsv) ? fs.readFileSync(files.pricingInputIntakeCsv, 'utf8') : '';
  const pricingInputIntakeCsvHeader = String(pricingInputIntakeCsvText.split(/\r?\n/)[0] || '');
  const operatorInputLocatorsCsvText = fs.existsSync(files.operatorInputLocatorsCsv) ? fs.readFileSync(files.operatorInputLocatorsCsv, 'utf8') : '';
  const operatorInputLocatorsCsvHeader = String(operatorInputLocatorsCsvText.split(/\r?\n/)[0] || '');
  const expectedCsvHeaders = [
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
  const expectedLocatorCsvHeaders = [
    'input_code',
    'locator_status',
    'locator_count',
    'operator_use',
    'row_id',
    'data_date',
    'source',
    'data_type',
    'dimension',
    'system_hotel_id',
    'data_source_id',
    'sync_task_id',
    'ingestion_method',
    'validation_status',
    'source_trace_id',
    'matched_path_count',
    'matched_paths',
    'locator_policy',
    'raw_values_exposed',
    'database_written',
    'auto_write_ota',
    'importable_value',
  ];
  const realInputTodoText = fs.existsSync(files.realInputTodo) ? fs.readFileSync(files.realInputTodo, 'utf8') : '';

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
    'operator_input_evidence',
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
  check(checks, 'fillable_requires_operator_input_evidence', fillable.operator_input_evidence
    && typeof fillable.operator_input_evidence === 'object'
    && !Array.isArray(fillable.operator_input_evidence)
    && [
      'confirmed_by',
      'confirmed_at',
      'room_type_source',
      'price_guard_source',
      'demand_forecast_source',
      'competitor_price_source',
    ].every((key) => Object.prototype.hasOwnProperty.call(fillable.operator_input_evidence, key)), {
    operator_input_evidence: fillable.operator_input_evidence,
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
  check(checks, 'fillable_json_exposes_optional_row_source_note',
    fillable.room_types?.[0]?.source_note === ''
    && fillable.demand_forecasts?.[0]?.source_note === ''
    && fillable.competitor_price_samples?.[0]?.source_note === '', {
    room_source_note: fillable.room_types?.[0]?.source_note ?? null,
    demand_source_note: fillable.demand_forecasts?.[0]?.source_note ?? null,
    competitor_source_note: fillable.competitor_price_samples?.[0]?.source_note ?? null,
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
  check(checks, 'csv_intake_converter_command_no_db_no_ota_write',
    String(manifestCommands?.build_fillable_from_csv || '').includes('build:revenue-ai-ctrip-pricing-input-from-csv')
    && String(manifestCommands?.build_fillable_from_csv || '').includes('pricing-input-intake.csv')
    && String(manifestCommands?.build_fillable_from_csv || '').includes('pricing-input-fillable.json')
    && String(manifestCommands?.build_fillable_from_csv || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.build_fillable_from_csv || '').includes(`--hotel-id=${expectedHotelId}`)
    && manifest?.pricing_input_intake_csv?.file === 'pricing-input-intake.csv'
    && manifest?.pricing_input_intake_csv?.status === 'human_fillable_csv_not_importable_until_converted_and_linted'
    && manifest?.pricing_input_intake_csv?.source_scope === 'ctrip_ota_channel'
    && manifest?.pricing_input_intake_csv?.auto_write_ota === false
    && manifest?.pricing_input_intake_csv?.converter_command === 'build_fillable_from_csv', {
    build_fillable_from_csv: manifestCommands?.build_fillable_from_csv || null,
    pricing_input_intake_csv: manifest?.pricing_input_intake_csv || null,
  });
  check(checks, 'csv_intake_template_ctrip_only_blank_business_values',
    pricingInputIntakeCsvHeader === expectedCsvHeaders.join(',')
    && pricingInputIntakeCsvText.includes('evidence,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('room_type,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('demand_forecast,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('competitor_price_sample,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('required_fields,expected_real_input,format_guard,forbidden_fill')
    && pricingInputIntakeCsvText.includes('confirmed_by; confirmed_at; room_type_source; price_guard_source; demand_forecast_source; competitor_price_source')
    && pricingInputIntakeCsvText.includes('base/min/max numeric > 0; min_price <= base_price; max_price >= base_price; room_count positive integer')
    && pricingInputIntakeCsvText.includes('predicted_occupancy 0-100; predicted_demand numeric > 0; confidence_score 0-1')
    && pricingInputIntakeCsvText.includes('analysis_date within 7 days ending at business_date; our_price and competitor_price numeric > 0; ota_platform=ctrip')
    && pricingInputIntakeCsvText.includes('sample, guessed, fallback, verifier-only, non-Ctrip OTA, or whole-hotel value')
    && pricingInputIntakeCsvText.includes(',ctrip,')
    && !pricingInputIntakeCsvText.toLowerCase().includes('meituan')
    && !pricingInputIntakeCsvText.includes('Verified Ctrip competitor hotel name')
    && !pricingInputIntakeCsvText.includes('Verified Ctrip room type name')
    && !pricingInputIntakeCsvText.includes('transaction-only verifier'), {
    file: files.pricingInputIntakeCsv,
    header: pricingInputIntakeCsvHeader,
  });
  check(checks, 'operator_input_locators_csv_metadata_only',
    operatorInputLocatorsCsvHeader === expectedLocatorCsvHeaders.join(',')
    && operatorInputLocatorsCsvText.includes('metadata_locator_only_no_values_no_import')
    && operatorInputLocatorsCsvText.includes('raw_values_exposed')
    && operatorInputLocatorsCsvText.includes('database_written')
    && operatorInputLocatorsCsvText.includes('auto_write_ota')
    && operatorInputLocatorsCsvText.includes('importable_value')
    && operatorInputLocatorsCsvText.includes('room_types_enabled')
    && operatorInputLocatorsCsvText.includes('floor_price_or_min_rate_guard')
    && operatorInputLocatorsCsvText.includes('demand_forecast')
    && operatorInputLocatorsCsvText.includes('competitor_price_samples')
    && operatorInputLocatorsCsvText.includes('source_trace_id')
    && operatorInputLocatorsCsvText.includes('matched_paths')
    && operatorInputLocatorsCsvText.includes('false')
    && !operatorInputLocatorsCsvText.toLowerCase().includes('meituan')
    && manifest?.operator_input_locators_csv?.file === 'operator-input-locators.csv'
    && manifest?.operator_input_locators_csv?.status === 'metadata_locator_csv_not_importable'
    && manifest?.operator_input_locators_csv?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_input_locators_csv?.source_policy === 'metadata_locator_only_no_values_no_import'
    && manifest?.operator_input_locators_csv?.raw_values_exposed === false
    && manifest?.operator_input_locators_csv?.database_written === false
    && manifest?.operator_input_locators_csv?.auto_write_ota === false
    && manifest?.operator_input_locators_csv?.importable_value === false, {
    file: files.operatorInputLocatorsCsv,
    header: operatorInputLocatorsCsvHeader,
    operator_input_locators_csv: manifest?.operator_input_locators_csv || null,
  });
  check(checks, 'post_pending_commands_present_and_scoped',
    String(manifestCommands?.review_decision_template || '').includes('export:revenue-ai-ctrip-review-template')
    && String(manifestCommands?.review_decision_template || '').includes('--suggestion-id=<pending-suggestion-id>')
    && String(manifestCommands?.execute_review_and_create_intent || '').includes('run:revenue-ai-ctrip-review-decision')
    && String(manifestCommands?.execute_review_and_create_intent || '').includes('--create-intent=1')
    && String(manifestCommands?.verify_roi_boundary || '').includes('verify:revenue-ai-ctrip-operation-roi')
    && ['review_decision_template', 'execute_review_and_create_intent', 'verify_roi_boundary'].every((name) => String(manifestCommands?.[name] || '').includes(`--hotel-id=${expectedHotelId}`)), {
    post_pending_commands: {
      review_decision_template: manifestCommands?.review_decision_template || null,
      execute_review_and_create_intent: manifestCommands?.execute_review_and_create_intent || null,
      verify_roi_boundary: manifestCommands?.verify_roi_boundary || null,
    },
  });
  check(checks, 'template_keeps_context_metadata', template?.verification_commands && template?.operator_fill_required && template?.current_preflight, {});
  const realInputChecklistText = JSON.stringify(realInputChecklist);
  const checklistPaths = Array.isArray(realInputChecklist?.items)
    ? realInputChecklist.items.map((item) => String(item?.path || ''))
    : [];
  check(checks, 'real_input_checklist_metadata_only', realInputChecklist?.source_policy === 'operator_real_input_checklist_no_values_no_import'
    && realInputChecklist?.scope?.source_scope === 'ctrip_ota_channel'
    && realInputChecklist?.scope?.raw_values_exposed === false
    && realInputChecklist?.scope?.database_written === false
    && realInputChecklist?.scope?.auto_write_ota === false
    && realInputChecklist?.placeholder_count === countPlaceholders(fillable)
    && realInputChecklist?.can_generate_pending_review === false
    && manifest?.real_input_checklist?.file === 'real-input-checklist.json'
    && manifest?.real_input_checklist?.placeholder_count === realInputChecklist?.placeholder_count
    && checklistPaths.includes('$.operator_input_evidence.confirmed_by')
    && checklistPaths.includes('$.room_types.0.base_price')
    && checklistPaths.includes('$.demand_forecasts.0.predicted_occupancy')
    && checklistPaths.includes('$.competitor_price_samples.0.competitor_price')
    && realInputChecklistText.includes('sample, guessed, fallback, verifier-only, Meituan, or whole-hotel value'), {
    real_input_checklist: manifest?.real_input_checklist || null,
    placeholder_count: realInputChecklist?.placeholder_count,
  });
  check(checks, 'pricing_input_schema_editor_guidance_only', pricingInputSchema?.['$schema'] === 'https://json-schema.org/draft/2020-12/schema'
    && manifest?.pricing_input_schema?.file === 'pricing-input.schema.json'
    && manifest?.pricing_input_schema?.source_scope === 'ctrip_ota_channel'
    && manifest?.pricing_input_schema?.schema_is_authoritative === false
    && String(manifest?.pricing_input_schema?.authoritative_gate || '').includes('verify:revenue-ai-ctrip-operator-bundle-preflight')
    && pricingInputSchema?.properties?.business_date?.const === expectedDate
    && String(pricingInputSchema?.properties?.hotel_id?.const || pricingInputSchema?.properties?.hotel_id?.minimum || '').length > 0
    && pricingInputSchema?.properties?.platform?.const === 'ctrip'
    && pricingInputSchema?.properties?.source_scope?.const === 'ctrip_ota_channel'
    && pricingInputSchema?.properties?.auto_write_ota?.const === false
    && pricingInputSchema?.properties?.operator_input_evidence?.required?.includes('confirmed_by')
    && pricingInputSchema?.properties?.room_types?.items?.properties?.base_price?.exclusiveMinimum === 0
    && pricingInputSchema?.properties?.room_types?.items?.properties?.source_note?.description?.includes('operator_row_source_note')
    && pricingInputSchema?.properties?.demand_forecasts?.items?.properties?.confidence_score?.maximum === 1
    && pricingInputSchema?.properties?.demand_forecasts?.items?.properties?.source_note?.description?.includes('operator_row_source_note')
    && pricingInputSchema?.properties?.competitor_price_samples?.items?.properties?.ota_platform?.const === 'ctrip'
    && pricingInputSchema?.properties?.competitor_price_samples?.items?.properties?.source_note?.description?.includes('operator_row_source_note')
    && pricingInputSchema?.['x-suxios-policy']?.raw_values_exposed === false
    && pricingInputSchema?.['x-suxios-policy']?.database_written === false
    && pricingInputSchema?.['x-suxios-policy']?.auto_write_ota === false
    && pricingInputSchema?.['x-suxios-policy']?.schema_is_authoritative === false, {
    pricing_input_schema: manifest?.pricing_input_schema || null,
  });
  check(checks, 'operator_intake_form_human_fillable_not_importable',
    operatorIntakeFormText.includes('Ctrip Revenue AI Operator Intake Form')
    && operatorIntakeFormText.includes('business_date: `' + expectedDate + '`')
    && operatorIntakeFormText.includes('source_scope: `ctrip_ota_channel`')
    && operatorIntakeFormText.includes('auto_write_ota: `false`')
    && operatorIntakeFormText.includes('human_fillable_collection_not_importable')
    && operatorIntakeFormText.includes('Operator Confirmation')
    && operatorIntakeFormText.includes('Room Types And Price Guards')
    && operatorIntakeFormText.includes('Demand Forecasts')
    && operatorIntakeFormText.includes('Ctrip Competitor Price Samples')
    && operatorIntakeFormText.includes('pricing-input-fillable.json')
    && operatorIntakeFormText.includes('operator-input-locators.csv')
    && operatorIntakeFormText.includes('operator_row_source_note')
    && operatorIntakeFormText.includes('`source_note` does not replace `operator_input_evidence`')
    && operatorIntakeFormText.includes('not executable and not importable evidence')
    && operatorIntakeFormText.includes('ota_platform')
    && operatorIntakeFormText.includes('ctrip')
    && operatorIntakeFormText.includes('Stop if any value is a sample, guess, fallback, verifier fixture, Meituan row, or whole-hotel value.')
    && manifest?.operator_intake_form?.file === 'OPERATOR_INTAKE_FORM.md'
    && manifest?.operator_intake_form?.status === 'human_fillable_collection_not_importable'
    && manifest?.operator_intake_form?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_intake_form?.auto_write_ota === false
    && manifest?.operator_intake_form?.can_generate_pending_review === false, {
    operator_intake_form: manifest?.operator_intake_form || null,
  });
  check(checks, 'closure_runbook_covers_full_chain_no_ota_write',
    closureRunbookText.includes('Ctrip Revenue AI Closure Runbook')
    && closureRunbookText.includes('source_scope: `ctrip_ota_channel`')
    && closureRunbookText.includes('auto_write_ota: `false`')
    && closureRunbookText.includes('manual_closure_sequence_no_ota_price_write')
    && closureRunbookText.includes('Ctrip OTA evidence -> revenue analysis -> AI pricing suggestion -> manual review -> operation intent -> ROI evidence')
    && closureRunbookText.includes('pricing-input-fillable.json')
    && closureRunbookText.includes('pricing-input-intake.csv')
    && closureRunbookText.includes('operator-input-locators.csv')
    && closureRunbookText.includes('csv_issue_map.csv_row_number')
    && closureRunbookText.includes('csv_issue_map.csv_column')
    && closureRunbookText.includes('operator_row_source_note')
    && closureRunbookText.includes('build_fillable_from_csv')
    && closureRunbookText.includes('run:revenue-ai-ctrip-pricing-file-to-pending-review')
    && closureRunbookText.includes('export:revenue-ai-ctrip-review-template')
    && closureRunbookText.includes('run:revenue-ai-ctrip-review-decision')
    && closureRunbookText.includes('verify:revenue-ai-ctrip-operation-roi')
    && closureRunbookText.includes('operator_review_evidence')
    && closureRunbookText.includes('operation_execution.roi_ready')
    && closureRunbookText.includes('Stop before investment decision if ROI evidence is incomplete or outside Ctrip OTA channel scope.')
    && manifest?.closure_runbook?.file === 'CTRIP_CLOSURE_RUNBOOK.md'
    && manifest?.closure_runbook?.status === 'manual_closure_sequence_no_ota_price_write'
    && manifest?.closure_runbook?.source_scope === 'ctrip_ota_channel'
    && manifest?.closure_runbook?.auto_write_ota === false
    && manifest?.closure_runbook?.requires_operator_real_inputs === true
    && manifest?.closure_runbook?.completion_gate === 'operation_execution.roi_ready', {
    closure_runbook: manifest?.closure_runbook || null,
  });
  check(checks, 'real_input_todo_current_and_no_raw_values', realInputTodoText.includes('Operator Collection Priorities')
    && realInputTodoText.includes('Operator Evidence Locators')
    && realInputTodoText.includes('Field-Level Real Input Checklist')
    && realInputTodoText.includes('pricing_generation_preflight.status')
    && realInputTodoText.includes('skipped_by_operator_policy')
    && realInputTodoText.includes('operator_input_evidence')
    && realInputTodoText.includes('$.operator_input_evidence.confirmed_by')
    && realInputTodoText.includes('$.room_types.0.base_price')
    && realInputTodoText.includes('$.demand_forecasts.0.predicted_occupancy')
    && realInputTodoText.includes('$.competitor_price_samples.0.competitor_price')
    && realInputTodoText.includes('Fill only real operator-verified Ctrip OTA channel values')
    && realInputTodoText.includes('pricing-input-intake.csv')
    && realInputTodoText.includes('operator-input-locators.csv')
    && realInputTodoText.includes('csv_issue_map.csv_row_number')
    && realInputTodoText.includes('csv_issue_map.csv_column')
    && realInputTodoText.includes('operator_row_source_note')
    && realInputTodoText.includes('source_note`: optional row-level evidence note')
    && realInputTodoText.includes('build_fillable_from_csv')
    && realInputTodoText.includes('Ctrip source hints are not importable values')
    && realInputTodoText.includes('Locator row ids and source traces are metadata only')
    && realInputTodoText.includes('metadata-only evidence navigation')
    && realInputTodoText.includes('p0_authority_status: `not_reverified_by_bundle`')
    && !realInputTodoText.includes('Ctrip P0 目标日闭环已通过')
    && !realInputTodoText.includes('current gate: `room_types_empty`'), {
    file: files.realInputTodo,
  });

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
