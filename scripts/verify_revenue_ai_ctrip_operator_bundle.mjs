import fs from 'node:fs';
import path from 'node:path';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

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
  return parseJsonTextSafely(fs.readFileSync(filePath, 'utf8'), 'revenue_ai_operator_bundle_json');
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

function asArray(value) {
  return Array.isArray(value) ? value : [];
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
    operatorActionSheetCsv: path.join(bundleDir, 'operator-action-sheet.csv'),
    operatorConfirmationBrief: path.join(bundleDir, 'OPERATOR_CONFIRMATION_BRIEF.md'),
    operatorConfirmationBriefCsv: path.join(bundleDir, 'operator-confirmation-brief.csv'),
    operatorQuickReply: path.join(bundleDir, 'OPERATOR_QUICK_REPLY.md'),
    operatorRealInputRequest: path.join(bundleDir, 'OPERATOR_REAL_INPUT_REQUEST.md'),
    operationRoiEvidenceRequest: path.join(bundleDir, 'OPERATION_ROI_EVIDENCE_REQUEST.md'),
    operatorInputLocatorsCsv: path.join(bundleDir, 'operator-input-locators.csv'),
    operatorReviewDraft: path.join(bundleDir, 'OPERATOR_REVIEW_DRAFT.md'),
    demandTrendDraft: path.join(bundleDir, 'demand-trend-draft.json'),
    demandTrendDraftMarkdown: path.join(bundleDir, 'demand-trend-draft.md'),
    pricingEvidenceCandidates: path.join(bundleDir, 'pricing-evidence-candidates.json'),
    pricingEvidenceCandidatesMarkdown: path.join(bundleDir, 'pricing-evidence-candidates.md'),
    pricingEvidenceCandidatesCsv: path.join(bundleDir, 'pricing-evidence-candidates.csv'),
    externalInputCandidates: path.join(bundleDir, 'external-input-candidates.json'),
    externalInputCandidatesMarkdown: path.join(bundleDir, 'external-input-candidates.md'),
    externalInputCandidatesCsv: path.join(bundleDir, 'external-input-candidates.csv'),
    inputReadiness: path.join(bundleDir, 'input-readiness.json'),
    inputReadinessMarkdown: path.join(bundleDir, 'input-readiness.md'),
    autoInputEligibility: path.join(bundleDir, 'auto-input-eligibility.json'),
    autoInputEligibilityMarkdown: path.join(bundleDir, 'auto-input-eligibility.md'),
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
  const pricingEvidenceCandidates = readJson(files.pricingEvidenceCandidates);
  const externalInputCandidates = readJson(files.externalInputCandidates);
  const inputReadiness = readJson(files.inputReadiness);
  const autoInputEligibility = readJson(files.autoInputEligibility);
  const demandTrendDraft = readJson(files.demandTrendDraft);

  const expectedDate = options.date || String(manifest?.scope?.business_date || fillable?.business_date || '');
  const expectedHotelId = options.hotelId || String(manifest?.scope?.hotel_id || fillable?.hotel_id || '');
  const manifestCommands = manifest?.next_commands_after_filling_template || {};
  const commandText = JSON.stringify(manifestCommands);
  const fillableText = JSON.stringify(fillable);
  const operatorIntakeFormText = fs.existsSync(files.operatorIntakeForm) ? fs.readFileSync(files.operatorIntakeForm, 'utf8') : '';
  const closureRunbookText = fs.existsSync(files.closureRunbook) ? fs.readFileSync(files.closureRunbook, 'utf8') : '';
  const pricingInputIntakeCsvText = fs.existsSync(files.pricingInputIntakeCsv) ? fs.readFileSync(files.pricingInputIntakeCsv, 'utf8') : '';
  const pricingInputIntakeCsvHeader = String(pricingInputIntakeCsvText.split(/\r?\n/)[0] || '');
  const operatorActionSheetCsvText = fs.existsSync(files.operatorActionSheetCsv) ? fs.readFileSync(files.operatorActionSheetCsv, 'utf8') : '';
  const operatorActionSheetCsvHeader = String(operatorActionSheetCsvText.split(/\r?\n/)[0] || '');
  const operatorConfirmationBriefText = fs.existsSync(files.operatorConfirmationBrief) ? fs.readFileSync(files.operatorConfirmationBrief, 'utf8') : '';
  const operatorConfirmationBriefCsvText = fs.existsSync(files.operatorConfirmationBriefCsv) ? fs.readFileSync(files.operatorConfirmationBriefCsv, 'utf8') : '';
  const operatorConfirmationBriefCsvHeader = String(operatorConfirmationBriefCsvText.split(/\r?\n/)[0] || '');
  const operatorQuickReplyText = fs.existsSync(files.operatorQuickReply) ? fs.readFileSync(files.operatorQuickReply, 'utf8') : '';
  const operatorRealInputRequestText = fs.existsSync(files.operatorRealInputRequest) ? fs.readFileSync(files.operatorRealInputRequest, 'utf8') : '';
  const operationRoiEvidenceRequestText = fs.existsSync(files.operationRoiEvidenceRequest) ? fs.readFileSync(files.operationRoiEvidenceRequest, 'utf8') : '';
  const operatorInputLocatorsCsvText = fs.existsSync(files.operatorInputLocatorsCsv) ? fs.readFileSync(files.operatorInputLocatorsCsv, 'utf8') : '';
  const operatorInputLocatorsCsvHeader = String(operatorInputLocatorsCsvText.split(/\r?\n/)[0] || '');
  const pricingEvidenceCandidatesText = JSON.stringify(pricingEvidenceCandidates);
  const pricingEvidenceCandidatesMarkdownText = fs.existsSync(files.pricingEvidenceCandidatesMarkdown) ? fs.readFileSync(files.pricingEvidenceCandidatesMarkdown, 'utf8') : '';
  const pricingEvidenceCandidatesCsvText = fs.existsSync(files.pricingEvidenceCandidatesCsv) ? fs.readFileSync(files.pricingEvidenceCandidatesCsv, 'utf8') : '';
  const pricingEvidenceCandidatesCsvHeader = String(pricingEvidenceCandidatesCsvText.split(/\r?\n/)[0] || '');
  const externalInputCandidatesText = JSON.stringify(externalInputCandidates);
  const externalInputCandidatesMarkdownText = fs.existsSync(files.externalInputCandidatesMarkdown) ? fs.readFileSync(files.externalInputCandidatesMarkdown, 'utf8') : '';
  const externalInputCandidatesCsvText = fs.existsSync(files.externalInputCandidatesCsv) ? fs.readFileSync(files.externalInputCandidatesCsv, 'utf8') : '';
  const externalInputCandidatesCsvHeader = String(externalInputCandidatesCsvText.split(/\r?\n/)[0] || '');
  const inputReadinessText = JSON.stringify(inputReadiness);
  const inputReadinessMarkdownText = fs.existsSync(files.inputReadinessMarkdown) ? fs.readFileSync(files.inputReadinessMarkdown, 'utf8') : '';
  const autoInputEligibilityText = JSON.stringify(autoInputEligibility);
  const autoInputEligibilityMarkdownText = fs.existsSync(files.autoInputEligibilityMarkdown) ? fs.readFileSync(files.autoInputEligibilityMarkdown, 'utf8') : '';
  const demandTrendDraftText = JSON.stringify(demandTrendDraft);
  const demandTrendDraftMarkdownText = fs.existsSync(files.demandTrendDraftMarkdown) ? fs.readFileSync(files.demandTrendDraftMarkdown, 'utf8') : '';
  const operatorReviewDraftText = fs.existsSync(files.operatorReviewDraft) ? fs.readFileSync(files.operatorReviewDraft, 'utf8') : '';
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
  const expectedActionSheetHeaders = [
    'action_group',
    'target_file',
    'target_json_path',
    'target_csv_section',
    'target_csv_column',
    'candidate_hint',
    'candidate_source_note',
    'operator_required_action',
    'importable_value',
    'auto_write_ota',
    'forbidden_fill',
  ];
  const expectedConfirmationBriefHeaders = [
    'priority',
    'input_status',
    'input_group',
    'target_file',
    'target_json_path',
    'target_csv_row',
    'target_csv_section',
    'target_csv_column',
    'candidate_hint',
    'candidate_source_note',
    'required_confirmation',
    'format_guard',
    'operator_confirmed_value',
    'importable_value',
    'auto_write_ota',
    'forbidden_fill',
  ];
  const expectedLocatorCsvHeaders = [
    'input_code',
    'locator_status',
    'locator_count',
    'operator_use',
    'capture_source_entry',
    'capture_module',
    'field_contract',
    'accepted_evidence',
    'missing_state',
    'operator_next_step',
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
  const currentPreflight = template?.current_preflight || {};
  const preflightRequiredCodes = asArray(currentPreflight?.required_input_codes).map((value) => String(value));
  const trafficTrendDemandReady = Number(currentPreflight?.ctrip_traffic_demand_forecast_count || 0) > 0
    && !preflightRequiredCodes.includes('demand_forecast');
  const demandForecastRows = Array.isArray(fillable.demand_forecasts) ? fillable.demand_forecasts : [];
  const demandForecastSourceText = String(fillable.operator_input_evidence?.demand_forecast_source || '');
  const demandForecastSourceNamesTrafficTrend = demandForecastSourceText.includes('ctrip_historical_traffic_trend')
    && demandForecastSourceText.includes('report:revenue-ai-ctrip-traffic-demand-trend');
  const p0AuthorityStatus = String(manifest?.p0_authority?.status || 'not_reverified_by_bundle');
  const p0AuthorityCommandText = String(manifest?.p0_authority?.required_gate_command || '');
  const p0AuthorityTodoMatches = realInputTodoText.includes(`p0_authority_status: \`${p0AuthorityStatus}\``)
    && manifest?.p0_authority?.source === 'current-scope.json'
    && manifest?.p0_authority?.raw_capture_read_by_bundle === false
    && manifest?.p0_authority?.database_written === false
    && manifest?.p0_authority?.auto_write_ota === false
    && (p0AuthorityStatus === 'not_reverified_by_bundle'
      || (realInputTodoText.includes('p0_authority_command:')
        && p0AuthorityCommandText.includes('verify:p0-ota-field-loop')));

  check(checks, 'scope_ctrip_only', manifest?.scope?.source_scope === 'ctrip_ota_channel'
    && manifest?.scope?.auto_write_ota === false
    && manifest?.scope?.database_written === false
    && manifest?.scope?.meituan_scope_included === false, {
    scope: manifest?.scope || null,
  });
  const refreshEvidence = manifest?.scope?.refresh_evidence === true;
  const preservedInputFiles = asArray(manifest?.scope?.preserved_input_files).map((value) => String(value));
  const manifestFiles = manifest?.files || {};
  check(checks, 'refresh_evidence_preserves_operator_inputs', !refreshEvidence || (
    preservedInputFiles.includes('pricing-input-fillable.json')
      && preservedInputFiles.includes('pricing-input-intake.csv')
      && manifestFiles?.pricing_input_fillable_json?.preserved === true
      && manifestFiles?.pricing_input_fillable_json?.overwritten === false
      && manifestFiles?.pricing_input_intake_csv?.preserved === true
      && manifestFiles?.pricing_input_intake_csv?.overwritten === false
  ), {
    refresh_evidence: refreshEvidence,
    preserved_input_files: preservedInputFiles,
    pricing_input_fillable_json: manifestFiles?.pricing_input_fillable_json || null,
    pricing_input_intake_csv: manifestFiles?.pricing_input_intake_csv || null,
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
    && (demandForecastRows.length > 0 || trafficTrendDemandReady)
    && Array.isArray(fillable.competitor_price_samples)
    && fillable.competitor_price_samples.length > 0, {
    room_types: Array.isArray(fillable.room_types) ? fillable.room_types.length : null,
    demand_forecasts: demandForecastRows.length,
    traffic_trend_demand_ready: trafficTrendDemandReady,
    competitor_price_samples: Array.isArray(fillable.competitor_price_samples) ? fillable.competitor_price_samples.length : null,
  });
  check(checks, 'fillable_json_exposes_optional_row_source_note',
    fillable.room_types?.[0]?.source_note === ''
    && (trafficTrendDemandReady || fillable.demand_forecasts?.[0]?.source_note === '')
    && fillable.competitor_price_samples?.[0]?.source_note === '', {
    room_source_note: fillable.room_types?.[0]?.source_note ?? null,
    demand_source_note: fillable.demand_forecasts?.[0]?.source_note ?? null,
    traffic_trend_demand_ready: trafficTrendDemandReady,
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
  check(checks, 'input_readiness_scan_command_read_only_and_scoped',
    String(manifestCommands?.input_readiness_scan || '').includes('report:revenue-ai-ctrip-input-readiness')
    && String(manifestCommands?.input_readiness_scan || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.input_readiness_scan || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.input_readiness_scan || '').includes('--format=markdown')
    && !String(manifestCommands?.input_readiness_scan || '').includes('--execute')
    && !String(manifestCommands?.input_readiness_scan || '').includes('write-ota'), {
    input_readiness_scan: manifestCommands?.input_readiness_scan || null,
  });
  check(checks, 'demand_trend_draft_command_read_only_and_scoped',
    String(manifestCommands?.demand_trend_draft || '').includes('report:revenue-ai-ctrip-traffic-demand-trend')
    && String(manifestCommands?.demand_trend_draft || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.demand_trend_draft || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.demand_trend_draft || '').includes('--format=markdown')
    && !String(manifestCommands?.demand_trend_draft || '').includes('--execute')
    && !String(manifestCommands?.demand_trend_draft || '').includes('write-ota'), {
    demand_trend_draft: manifestCommands?.demand_trend_draft || null,
  });
  check(checks, 'pricing_evidence_candidates_command_read_only_and_scoped',
    String(manifestCommands?.pricing_evidence_candidates || '').includes('report:revenue-ai-ctrip-pricing-evidence-candidates')
    && String(manifestCommands?.pricing_evidence_candidates || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.pricing_evidence_candidates || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.pricing_evidence_candidates || '').includes('--format=markdown')
    && !String(manifestCommands?.pricing_evidence_candidates || '').includes('--execute')
    && !String(manifestCommands?.pricing_evidence_candidates || '').includes('write-ota'), {
    pricing_evidence_candidates: manifestCommands?.pricing_evidence_candidates || null,
  });
  check(checks, 'external_input_candidates_command_read_only_and_scoped',
    String(manifestCommands?.external_input_candidates || '').includes('report:revenue-ai-ctrip-external-input-candidates')
    && String(manifestCommands?.external_input_candidates || '').includes('--dir=')
    && String(manifestCommands?.external_input_candidates || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.external_input_candidates || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.external_input_candidates || '').includes('--format=markdown')
    && !String(manifestCommands?.external_input_candidates || '').includes('--execute')
    && !String(manifestCommands?.external_input_candidates || '').includes('write-ota'), {
    external_input_candidates: manifestCommands?.external_input_candidates || null,
  });
  check(checks, 'auto_input_eligibility_command_read_only_and_scoped',
    String(manifestCommands?.auto_input_eligibility || '').includes('report:revenue-ai-ctrip-auto-input-eligibility')
    && String(manifestCommands?.auto_input_eligibility || '').includes('--dir=')
    && String(manifestCommands?.auto_input_eligibility || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.auto_input_eligibility || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.auto_input_eligibility || '').includes('--format=markdown')
    && !String(manifestCommands?.auto_input_eligibility || '').includes('--execute')
    && !String(manifestCommands?.auto_input_eligibility || '').includes('write-ota'), {
    auto_input_eligibility: manifestCommands?.auto_input_eligibility || null,
  });
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
    && manifest?.pricing_input_intake_csv?.converter_command === 'build_fillable_from_csv'
    && manifest?.pricing_input_intake_csv?.demand_forecast_row_required === !trafficTrendDemandReady, {
    build_fillable_from_csv: manifestCommands?.build_fillable_from_csv || null,
    pricing_input_intake_csv: manifest?.pricing_input_intake_csv || null,
  });
  check(checks, 'csv_to_json_preflight_command_no_db_no_ota_write',
    String(manifestCommands?.csv_to_json_preflight || '').includes('verify:revenue-ai-ctrip-operator-csv-preflight')
    && String(manifestCommands?.csv_to_json_preflight || '').includes('--dir=')
    && String(manifestCommands?.csv_to_json_preflight || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.csv_to_json_preflight || '').includes(`--hotel-id=${expectedHotelId}`)
    && !String(manifestCommands?.csv_to_json_preflight || '').includes('--execute')
    && !String(manifestCommands?.csv_to_json_preflight || '').includes('write-ota'), {
    csv_to_json_preflight: manifestCommands?.csv_to_json_preflight || null,
  });
  check(checks, 'quick_reply_preflight_command_no_db_no_ota_write',
    String(manifestCommands?.quick_reply_preflight || '').includes('verify:revenue-ai-ctrip-operator-quick-reply-preflight')
    && String(manifestCommands?.quick_reply_preflight || '').includes('--dir=')
    && String(manifestCommands?.quick_reply_preflight || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.quick_reply_preflight || '').includes(`--hotel-id=${expectedHotelId}`)
    && !String(manifestCommands?.quick_reply_preflight || '').includes('--execute')
    && !String(manifestCommands?.quick_reply_preflight || '').includes('write-ota'), {
    quick_reply_preflight: manifestCommands?.quick_reply_preflight || null,
  });
  check(checks, 'quick_reply_apply_to_fillable_command_local_file_only',
    String(manifestCommands?.quick_reply_apply_to_fillable || '').includes('verify:revenue-ai-ctrip-operator-quick-reply-preflight')
    && String(manifestCommands?.quick_reply_apply_to_fillable || '').includes('--dir=')
    && String(manifestCommands?.quick_reply_apply_to_fillable || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.quick_reply_apply_to_fillable || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.quick_reply_apply_to_fillable || '').includes('--write-fillable=1')
    && !String(manifestCommands?.quick_reply_apply_to_fillable || '').includes('--execute')
    && !String(manifestCommands?.quick_reply_apply_to_fillable || '').includes('write-ota'), {
    quick_reply_apply_to_fillable: manifestCommands?.quick_reply_apply_to_fillable || null,
  });
  check(checks, 'quick_reply_to_pending_review_command_explicit_execute_no_ota_write',
    String(manifestCommands?.quick_reply_to_pending_review || '').includes('run:revenue-ai-ctrip-quick-reply-to-pending-review')
    && String(manifestCommands?.quick_reply_to_pending_review || '').includes('--dir=')
    && String(manifestCommands?.quick_reply_to_pending_review || '').includes(`--date=${expectedDate}`)
    && String(manifestCommands?.quick_reply_to_pending_review || '').includes(`--hotel-id=${expectedHotelId}`)
    && String(manifestCommands?.quick_reply_to_pending_review || '').includes('--execute=1')
    && !String(manifestCommands?.quick_reply_to_pending_review || '').includes('write-ota'), {
    quick_reply_to_pending_review: manifestCommands?.quick_reply_to_pending_review || null,
  });
  check(checks, 'csv_intake_template_ctrip_only_blank_business_values',
    pricingInputIntakeCsvHeader === expectedCsvHeaders.join(',')
    && pricingInputIntakeCsvText.includes('evidence,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('room_type,' + expectedDate + ',' + expectedHotelId)
    && (trafficTrendDemandReady
      ? !pricingInputIntakeCsvText.includes('demand_forecast,' + expectedDate + ',' + expectedHotelId)
        && pricingInputIntakeCsvText.includes('ctrip_historical_traffic_trend')
      : pricingInputIntakeCsvText.includes('demand_forecast,' + expectedDate + ',' + expectedHotelId)
        && pricingInputIntakeCsvText.includes('predicted_occupancy 0-100; predicted_demand numeric > 0; confidence_score 0-1'))
    && pricingInputIntakeCsvText.includes('competitor_price_sample,' + expectedDate + ',' + expectedHotelId)
    && pricingInputIntakeCsvText.includes('required_fields,expected_real_input,format_guard,forbidden_fill')
    && pricingInputIntakeCsvText.includes('confirmed_by; confirmed_at; room_type_source; price_guard_source; demand_forecast_source; competitor_price_source')
    && pricingInputIntakeCsvText.includes('base/min/max numeric > 0; min_price <= base_price; max_price >= base_price; room_count positive integer')
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
  check(checks, 'operator_action_sheet_candidate_mapping_not_importable',
    operatorActionSheetCsvHeader === expectedActionSheetHeaders.join(',')
    && operatorActionSheetCsvText.includes('candidate_hints_mapped_to_fill_fields_no_values_no_import') === false
    && operatorActionSheetCsvText.includes('$.room_types.0.key')
    && operatorActionSheetCsvText.includes('$.competitor_price_samples.0.room_type_key')
    && operatorActionSheetCsvText.includes('$.room_types.0.name')
    && operatorActionSheetCsvText.includes('$.room_types.0.base_price')
    && operatorActionSheetCsvText.includes('$.room_types.0.min_price')
    && operatorActionSheetCsvText.includes('$.competitor_price_samples.0.competitor_name')
    && operatorActionSheetCsvText.includes('pricing-input-intake.csv')
    && operatorActionSheetCsvText.includes('Confirm the actual Ctrip room type name')
    && operatorActionSheetCsvText.includes('Fill operator-approved floor/protection price')
    && operatorActionSheetCsvText.includes('competitor aggregate context only; named sample still required')
    && operatorActionSheetCsvText.includes('external historical room concept')
    && operatorActionSheetCsvText.includes('external room count clues')
    && operatorActionSheetCsvText.includes('external 350/450 assumptions')
    && operatorActionSheetCsvText.includes('external historical competitor or market clues')
    && operatorActionSheetCsvText.includes(',false,false,')
    && !operatorActionSheetCsvText.toLowerCase().includes('write-ota')
    && manifest?.operator_action_sheet_csv?.file === 'operator-action-sheet.csv'
    && manifest?.operator_action_sheet_csv?.status === 'candidate_to_operator_action_sheet_not_importable'
    && manifest?.operator_action_sheet_csv?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_action_sheet_csv?.source_policy === 'candidate_hints_mapped_to_fill_fields_no_values_no_import'
    && manifest?.operator_action_sheet_csv?.candidate_values_exposed === true
    && manifest?.operator_action_sheet_csv?.database_written === false
    && manifest?.operator_action_sheet_csv?.auto_write_ota === false
    && manifest?.operator_action_sheet_csv?.importable_value === false
    && manifest?.operator_action_sheet_csv?.operator_confirmation_required === true
    && manifest?.operator_action_sheet_csv?.target_file === 'pricing-input-intake.csv', {
    file: files.operatorActionSheetCsv,
    header: operatorActionSheetCsvHeader,
    operator_action_sheet_csv: manifest?.operator_action_sheet_csv || null,
  });
  check(checks, 'operator_confirmation_brief_minimum_inputs_not_importable',
    operatorConfirmationBriefCsvHeader === expectedConfirmationBriefHeaders.join(',')
    && operatorConfirmationBriefText.includes('Ctrip Operator Confirmation Brief')
    && operatorConfirmationBriefText.includes('source_policy: `operator_confirmation_brief_no_values_no_import`')
    && operatorConfirmationBriefText.includes('database_written: `false`')
    && operatorConfirmationBriefText.includes('auto_write_ota: `false`')
    && operatorConfirmationBriefText.includes('importable_value: `false`')
    && operatorConfirmationBriefText.includes('room_type_key')
    && operatorConfirmationBriefText.includes('ctrip_historical_traffic_trend')
    && operatorConfirmationBriefText.includes('observed averages are not floor/protection guards')
    && operatorConfirmationBriefCsvText.includes('$.room_types.0.key')
    && operatorConfirmationBriefCsvText.includes('$.competitor_price_samples.0.room_type_key')
    && operatorConfirmationBriefCsvText.includes('operator_confirmed_value')
    && operatorConfirmationBriefCsvText.includes('required_manual_input')
    && operatorConfirmationBriefCsvText.includes('ready_from_ctrip_traffic_trend')
    && operatorConfirmationBriefCsvText.includes(',false,false,')
    && !operatorConfirmationBriefCsvText.toLowerCase().includes('write-ota')
    && manifest?.operator_confirmation_brief?.files?.markdown === 'OPERATOR_CONFIRMATION_BRIEF.md'
    && manifest?.operator_confirmation_brief?.files?.csv === 'operator-confirmation-brief.csv'
    && manifest?.operator_confirmation_brief?.status === 'minimum_human_confirmation_brief_not_importable'
    && manifest?.operator_confirmation_brief?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_confirmation_brief?.source_policy === 'operator_confirmation_brief_no_values_no_import'
    && manifest?.operator_confirmation_brief?.candidate_values_exposed === true
    && manifest?.operator_confirmation_brief?.database_written === false
    && manifest?.operator_confirmation_brief?.auto_write_ota === false
    && manifest?.operator_confirmation_brief?.importable_value === false
    && manifest?.operator_confirmation_brief?.operator_confirmation_required === true
    && manifest?.operator_confirmation_brief?.target_file === 'pricing-input-intake.csv'
    && Array.isArray(manifest?.operator_confirmation_brief?.required_before_execute)
    && manifest.operator_confirmation_brief.required_before_execute.includes('room_types_enabled')
    && manifest.operator_confirmation_brief.required_before_execute.includes('floor_price_or_min_rate_guard')
    && manifest.operator_confirmation_brief.required_before_execute.includes('competitor_price_samples'), {
    operator_confirmation_brief: manifest?.operator_confirmation_brief || null,
    markdown: files.operatorConfirmationBrief,
    csv: files.operatorConfirmationBriefCsv,
    csvHeader: operatorConfirmationBriefCsvHeader,
  });
  check(checks, 'operator_quick_reply_minimum_inputs_not_importable',
    operatorQuickReplyText.includes('Ctrip Operator Quick Reply')
    && operatorQuickReplyText.includes('source_policy: `operator_quick_reply_no_values_no_import`')
    && operatorQuickReplyText.includes('database_written: `false`')
    && operatorQuickReplyText.includes('auto_write_ota: `false`')
    && operatorQuickReplyText.includes('importable_value: `false`')
    && operatorQuickReplyText.includes('target_file: `pricing-input-intake.csv`')
    && operatorQuickReplyText.includes('Review Hints (Not Importable)')
    && operatorQuickReplyText.includes('Candidate hints are copied from `OPERATOR_CONFIRMATION_BRIEF.md` and `operator-confirmation-brief.csv`')
    && operatorQuickReplyText.includes('Do not paste a candidate hint into the reply unless the operator has verified it as a real current Ctrip value.')
    && operatorQuickReplyText.includes('| field | status | candidate_hint | required_confirmation |')
    && operatorQuickReplyText.includes('Quick reply preflight:')
    && operatorQuickReplyText.includes('builds temporary local intake files')
    && operatorQuickReplyText.includes('Apply quick reply to fillable JSON only after preflight passes:')
    && operatorQuickReplyText.includes('writes only local `pricing-input-fillable.json` after all no-execute gates pass')
    && operatorQuickReplyText.includes('One-step pending-review runner after real values are complete:')
    && operatorQuickReplyText.includes('creates only local pending AI review items')
    && operatorQuickReplyText.includes('confirmed_by=')
    && operatorQuickReplyText.includes('room_type_key=')
    && operatorQuickReplyText.includes('room_type_name=')
    && operatorQuickReplyText.includes('room_count=')
    && operatorQuickReplyText.includes('base_price=')
    && operatorQuickReplyText.includes('min_price=')
    && operatorQuickReplyText.includes('max_price=')
    && operatorQuickReplyText.includes('competitor_room_type_key=')
    && operatorQuickReplyText.includes('| `competitor_room_type_key` |')
    && operatorQuickReplyText.includes('Leave `competitor_room_type_key` blank only when it should reuse `room_type_key`.')
    && operatorQuickReplyText.includes('competitor_name=')
    && operatorQuickReplyText.includes('our_price=')
    && operatorQuickReplyText.includes('competitor_price=')
    && operatorQuickReplyText.includes('Optional multi-row format')
    && operatorQuickReplyText.includes('room_type_1_key=')
    && operatorQuickReplyText.includes('room_type_2_key=')
    && operatorQuickReplyText.includes('competitor_sample_1_room_type_key=')
    && operatorQuickReplyText.includes('competitor_sample_2_competitor_price=')
    && operatorQuickReplyText.includes('For multiple room types')
    && operatorQuickReplyText.includes('For multiple competitor samples')
    && operatorQuickReplyText.includes('ctrip_historical_traffic_trend')
    && operatorQuickReplyText.includes('Continue only when the no-execute preflight passes.')
    && operatorQuickReplyText.includes('Do not write OTA prices from this reply')
    && manifest?.operator_quick_reply?.file === 'OPERATOR_QUICK_REPLY.md'
    && manifest?.operator_quick_reply?.status === 'human_quick_reply_not_importable'
    && manifest?.operator_quick_reply?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_quick_reply?.source_policy === 'operator_quick_reply_no_values_no_import'
    && manifest?.operator_quick_reply?.database_written === false
    && manifest?.operator_quick_reply?.auto_write_ota === false
    && manifest?.operator_quick_reply?.importable_value === false
    && manifest?.operator_quick_reply?.target_file === 'pricing-input-intake.csv'
    && manifest?.operator_quick_reply?.preflight_command === 'quick_reply_preflight'
    && manifest?.operator_quick_reply?.apply_to_fillable_command === 'quick_reply_apply_to_fillable'
    && manifest?.operator_quick_reply?.pending_review_runner_command === 'quick_reply_to_pending_review'
    && manifest?.operator_quick_reply?.preflight_policy === 'temporary_local_files_no_db_no_ota_write'
    && manifest?.operator_quick_reply?.fillable_write_policy === 'explicit_write_fillable_flag_only_after_no_execute_preflight_passes_no_db_no_ota_write'
    && manifest?.operator_quick_reply?.pending_review_policy === 'explicit_execute_only_local_pending_review_no_ota_write_manual_review_required'
    && manifest?.operator_quick_reply?.candidate_values_exposed === true
    && manifest?.operator_quick_reply?.candidate_values_importable === false
    && Array.isArray(manifest?.operator_quick_reply?.required_before_execute)
    && manifest.operator_quick_reply.required_before_execute.includes('room_types_enabled')
    && manifest.operator_quick_reply.required_before_execute.includes('floor_price_or_min_rate_guard')
    && manifest.operator_quick_reply.required_before_execute.includes('competitor_price_samples'), {
    operator_quick_reply: manifest?.operator_quick_reply || null,
    markdown: files.operatorQuickReply,
  });
  check(checks, 'operator_real_input_request_human_request_not_importable',
    operatorRealInputRequestText.includes('携程真实经营输入请求单')
    && operatorRealInputRequestText.includes('source_policy: `operator_request_no_values_no_import`')
    && operatorRealInputRequestText.includes('database_written: `false`')
    && operatorRealInputRequestText.includes('auto_write_ota: `false`')
    && operatorRealInputRequestText.includes('importable_value: `false`')
    && operatorRealInputRequestText.includes('target_file: `OPERATOR_QUICK_REPLY.md`')
    && operatorRealInputRequestText.includes('current_required_real_inputs_before_execute')
    && operatorRealInputRequestText.includes('room_types_enabled')
    && operatorRealInputRequestText.includes('floor_price_or_min_rate_guard')
    && operatorRealInputRequestText.includes('competitor_price_samples')
    && operatorRealInputRequestText.includes('room_type_1_key=')
    && operatorRealInputRequestText.includes('room_type_2_key=')
    && operatorRealInputRequestText.includes('competitor_sample_1_room_type_key=')
    && operatorRealInputRequestText.includes('competitor_sample_2_competitor_price=')
    && operatorRealInputRequestText.includes('ctrip_historical_traffic_trend')
    && (trafficTrendDemandReady
      ? operatorRealInputRequestText.includes('需求预测已由 `ctrip_historical_traffic_trend` 覆盖')
      : operatorRealInputRequestText.includes('需求预测尚未通过 `ctrip_historical_traffic_trend` 校验'))
    && operatorRealInputRequestText.includes('复制到 OPERATOR_QUICK_REPLY.md')
    && operatorRealInputRequestText.includes('quick_reply_preflight')
    && operatorRealInputRequestText.includes('quick_reply_to_pending_review')
    && operatorRealInputRequestText.includes('不自动写 OTA 价格')
    && manifest?.operator_real_input_request?.file === 'OPERATOR_REAL_INPUT_REQUEST.md'
    && manifest?.operator_real_input_request?.status === 'human_real_input_request_not_importable'
    && manifest?.operator_real_input_request?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_real_input_request?.source_policy === 'operator_request_no_values_no_import'
    && manifest?.operator_real_input_request?.database_written === false
    && manifest?.operator_real_input_request?.auto_write_ota === false
    && manifest?.operator_real_input_request?.importable_value === false
    && manifest?.operator_real_input_request?.target_file === 'OPERATOR_QUICK_REPLY.md'
    && manifest?.operator_real_input_request?.demand_forecast_source === (trafficTrendDemandReady ? 'ctrip_historical_traffic_trend' : 'manual_or_missing')
    && Array.isArray(manifest?.operator_real_input_request?.required_before_execute)
    && manifest.operator_real_input_request.required_before_execute.includes('room_types_enabled')
    && manifest.operator_real_input_request.required_before_execute.includes('floor_price_or_min_rate_guard')
    && manifest.operator_real_input_request.required_before_execute.includes('competitor_price_samples'), {
    operator_real_input_request: manifest?.operator_real_input_request || null,
    markdown: files.operatorRealInputRequest,
  });
  check(checks, 'operation_roi_evidence_request_not_importable_not_proof',
    operationRoiEvidenceRequestText.includes('Ctrip Operation ROI Evidence Request')
    && operationRoiEvidenceRequestText.includes('source_scope: `ctrip_ota_channel_execution_evidence`')
    && operationRoiEvidenceRequestText.includes('source_policy: `human_operation_roi_evidence_request_no_values_no_import`')
    && operationRoiEvidenceRequestText.includes('database_written: `false`')
    && operationRoiEvidenceRequestText.includes('auto_write_ota: `false`')
    && operationRoiEvidenceRequestText.includes('importable_value: `false`')
    && operationRoiEvidenceRequestText.includes('proves_execution_or_roi: `false`')
    && operationRoiEvidenceRequestText.includes('required_gate: `operation_execution.roi_ready`')
    && operationRoiEvidenceRequestText.includes('do_not_promote_ctrip_ota_scope_to_whole_hotel_truth')
    && operationRoiEvidenceRequestText.includes('approve_operation_execution_intent')
    && operationRoiEvidenceRequestText.includes('record_manual_execution_result')
    && operationRoiEvidenceRequestText.includes('upload_roi_evidence')
    && operationRoiEvidenceRequestText.includes('review_execution_task_roi')
    && operationRoiEvidenceRequestText.includes('keep_investment_manual_review_only')
    && operationRoiEvidenceRequestText.includes('previous_day: `')
    && operationRoiEvidenceRequestText.includes('next_day: `')
    && operationRoiEvidenceRequestText.includes('required_metrics: `revenue; room_nights; orders; conversion; traffic`')
    && operationRoiEvidenceRequestText.includes('manual_price_execution')
    && operationRoiEvidenceRequestText.includes('manual_roi_evidence')
    && operationRoiEvidenceRequestText.includes('local_manual_evidence_no_ota_write')
    && operationRoiEvidenceRequestText.includes('local_manual_roi_evidence_no_ota_write')
    && operationRoiEvidenceRequestText.includes('execute_review_and_create_intent')
    && operationRoiEvidenceRequestText.includes('verify_roi_boundary')
    && operationRoiEvidenceRequestText.includes('Investment decision remains blocked until `operation_execution.roi_ready` is true.')
    && operationRoiEvidenceRequestText.includes('Do not treat this request sheet as execution proof or ROI proof.')
    && operationRoiEvidenceRequestText.includes('Do not write OTA prices from this request sheet.')
    && manifest?.operation_roi_evidence_request?.file === 'OPERATION_ROI_EVIDENCE_REQUEST.md'
    && manifest?.operation_roi_evidence_request?.status === 'human_operation_roi_evidence_request_not_importable'
    && manifest?.operation_roi_evidence_request?.source_scope === 'ctrip_ota_channel_execution_evidence'
    && manifest?.operation_roi_evidence_request?.source_policy === 'human_operation_roi_evidence_request_no_values_no_import'
    && manifest?.operation_roi_evidence_request?.database_written === false
    && manifest?.operation_roi_evidence_request?.auto_write_ota === false
    && manifest?.operation_roi_evidence_request?.importable_value === false
    && manifest?.operation_roi_evidence_request?.proves_execution_or_roi === false
    && manifest?.operation_roi_evidence_request?.required_gate === 'operation_execution.roi_ready'
    && manifest?.operation_roi_evidence_request?.protected_boundary === 'do_not_promote_ctrip_ota_scope_to_whole_hotel_truth'
    && Array.isArray(manifest?.operation_roi_evidence_request?.required_sequence)
    && manifest.operation_roi_evidence_request.required_sequence.includes('approve_operation_execution_intent')
    && manifest.operation_roi_evidence_request.required_sequence.includes('upload_roi_evidence'), {
    operation_roi_evidence_request: manifest?.operation_roi_evidence_request || null,
    markdown: files.operationRoiEvidenceRequest,
  });
  check(checks, 'operator_input_locators_csv_metadata_only',
    operatorInputLocatorsCsvHeader === expectedLocatorCsvHeaders.join(',')
    && operatorInputLocatorsCsvText.includes('metadata_locator_only_no_values_no_import')
    && operatorInputLocatorsCsvText.includes('Ctrip ebooking room/ARI visible pages')
    && operatorInputLocatorsCsvText.includes('queryRoomTypeInfo')
    && operatorInputLocatorsCsvText.includes('queryVendibilityRoom')
    && operatorInputLocatorsCsvText.includes('Ctrip room price/ARI/rate-plan visible pages')
    && operatorInputLocatorsCsvText.includes('do not infer from avg_price')
    && operatorInputLocatorsCsvText.includes('report:revenue-ai-ctrip-traffic-demand-trend')
    && operatorInputLocatorsCsvText.includes('not_blocking_when_ctrip_historical_traffic_trend_ready')
    && operatorInputLocatorsCsvText.includes('queryCompetingHotelsV2')
    && operatorInputLocatorsCsvText.includes('fetchCompetitiveMarket')
    && operatorInputLocatorsCsvText.includes('getLossOrderCompeteHotel')
    && operatorInputLocatorsCsvText.includes('missing_current_named_ctrip_competitor_price_sample')
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
    && manifest?.operator_input_locators_csv?.capture_contract_status === 'metadata_only_no_live_capture_no_import'
    && manifest?.operator_input_locators_csv?.raw_values_exposed === false
    && manifest?.operator_input_locators_csv?.database_written === false
    && manifest?.operator_input_locators_csv?.auto_write_ota === false
    && manifest?.operator_input_locators_csv?.importable_value === false, {
    file: files.operatorInputLocatorsCsv,
    header: operatorInputLocatorsCsvHeader,
    operator_input_locators_csv: manifest?.operator_input_locators_csv || null,
  });
  check(checks, 'input_readiness_scan_manifest_and_payload_read_only',
    manifest?.input_readiness_scan?.files?.json === 'input-readiness.json'
    && manifest?.input_readiness_scan?.files?.markdown === 'input-readiness.md'
    && manifest?.input_readiness_scan?.status === 'passed'
    && manifest?.input_readiness_scan?.source_scope === 'ctrip_ota_channel'
    && manifest?.input_readiness_scan?.source_policy === 'read_current_database_counts_and_ctrip_traffic_trend_only_no_raw_rows_no_import'
    && manifest?.input_readiness_scan?.raw_rows_exposed === false
    && manifest?.input_readiness_scan?.database_written === false
    && manifest?.input_readiness_scan?.auto_write_ota === false
    && manifest?.input_readiness_scan?.importable_value === false
    && Array.isArray(manifest?.input_readiness_scan?.target_date_missing_inputs)
    && inputReadiness?.scope?.business_date_lte === expectedDate
    && String(inputReadiness?.scope?.hotel_id || '') === expectedHotelId
    && inputReadiness?.scope?.platform === 'ctrip'
    && inputReadiness?.scope?.source_scope === 'ctrip_ota_channel'
    && inputReadiness?.scope?.meituan_scope_included === false
    && inputReadiness?.source_policy === 'read_current_database_counts_and_ctrip_traffic_trend_only_no_raw_rows_no_import'
    && inputReadiness?.raw_rows_exposed === false
    && inputReadiness?.database_written === false
    && inputReadiness?.auto_write_ota === false
    && inputReadinessText.includes('ctrip_historical_traffic_trend')
    && inputReadinessText.includes('room_types_enabled')
    && inputReadinessText.includes('floor_price_or_min_rate_guard')
    && inputReadinessText.includes('competitor_price_samples')
    && inputReadinessMarkdownText.includes('Ctrip Revenue AI Input Readiness')
    && inputReadinessMarkdownText.includes('database_written: `false`')
    && inputReadinessMarkdownText.includes('auto_write_ota: `false`'), {
    input_readiness_scan: manifest?.input_readiness_scan || null,
    input_readiness_file: files.inputReadiness,
  });
  check(checks, 'auto_input_eligibility_manifest_and_payload_read_only',
    manifest?.auto_input_eligibility?.files?.json === 'auto-input-eligibility.json'
    && manifest?.auto_input_eligibility?.files?.markdown === 'auto-input-eligibility.md'
    && ['auto_close_eligible', 'blocked_by_operator_real_inputs'].includes(String(manifest?.auto_input_eligibility?.status || ''))
    && manifest?.auto_input_eligibility?.source_scope === 'ctrip_ota_channel'
    && manifest?.auto_input_eligibility?.source_policy === 'read_operator_bundle_evidence_only_no_db_no_ota_write'
    && manifest?.auto_input_eligibility?.database_written === false
    && manifest?.auto_input_eligibility?.auto_write_ota === false
    && manifest?.auto_input_eligibility?.importable_value === false
    && manifest?.auto_input_eligibility?.auto_import_allowed === false
    && Array.isArray(manifest?.auto_input_eligibility?.current_required_real_inputs_before_execute)
    && autoInputEligibility?.scope?.business_date === expectedDate
    && String(autoInputEligibility?.scope?.hotel_id || '') === expectedHotelId
    && autoInputEligibility?.scope?.platform === 'ctrip'
    && autoInputEligibility?.scope?.source_scope === 'ctrip_ota_channel'
    && autoInputEligibility?.scope?.meituan_scope_included === false
    && autoInputEligibility?.source_policy === 'read_operator_bundle_evidence_only_no_db_no_ota_write'
    && autoInputEligibility?.raw_rows_exposed === false
    && autoInputEligibility?.database_written === false
    && autoInputEligibility?.auto_write_ota === false
    && autoInputEligibility?.importable_value === false
    && autoInputEligibility?.auto_import_allowed === false
    && autoInputEligibility?.summary?.operator_reply_file === 'OPERATOR_QUICK_REPLY.md'
    && autoInputEligibility?.summary?.operator_reply_keys_are_not_importable_until_preflight_passes === true
    && autoInputEligibilityText.includes('"operator_question"')
    && autoInputEligibilityText.includes('"reply_keys"')
    && autoInputEligibilityText.includes('"room_type_key"')
    && autoInputEligibilityText.includes('"competitor_room_type_key"')
    && autoInputEligibilityText.includes('"room_type_1_key"')
    && autoInputEligibilityText.includes('"room_type_1_base_price"')
    && autoInputEligibilityText.includes('"competitor_sample_1_room_type_key"')
    && autoInputEligibilityText.includes('room_name_or_room_count_candidates_do_not_prove_operator_enabled_room_type_key_and_sellable_room_count')
    && autoInputEligibilityText.includes('observed_average_prices_and_350_450_assumptions_are_not_operator_approved_base_min_max_guards')
    && autoInputEligibilityText.includes('competitor_aggregates_and_historical_market_references_are_not_recent_named_ctrip_price_samples')
    && autoInputEligibilityText.includes('ctrip_historical_traffic_trend_is_accepted_as_demand_forecast_source')
    && autoInputEligibilityMarkdownText.includes('Ctrip Auto Input Eligibility')
    && autoInputEligibilityMarkdownText.includes('auto_write_ota: `false`')
    && autoInputEligibilityMarkdownText.includes('auto_import_allowed: `false`')
    && autoInputEligibilityMarkdownText.includes('current_required_real_inputs_before_execute')
    && autoInputEligibilityMarkdownText.includes('operator_reply_file: `OPERATOR_QUICK_REPLY.md`')
    && autoInputEligibilityMarkdownText.includes('Operator Questions')
    && autoInputEligibilityMarkdownText.includes('reply_keys'), {
    auto_input_eligibility: manifest?.auto_input_eligibility || null,
    auto_input_eligibility_file: files.autoInputEligibility,
  });
  check(checks, 'pricing_evidence_candidates_manifest_non_importable',
    manifest?.pricing_evidence_candidates?.files?.json === 'pricing-evidence-candidates.json'
    && manifest?.pricing_evidence_candidates?.files?.markdown === 'pricing-evidence-candidates.md'
    && manifest?.pricing_evidence_candidates?.files?.csv === 'pricing-evidence-candidates.csv'
    && ['passed', 'blocked_by_no_candidate_values'].includes(String(manifest?.pricing_evidence_candidates?.status || ''))
    && manifest?.pricing_evidence_candidates?.source_scope === 'ctrip_ota_channel'
    && manifest?.pricing_evidence_candidates?.source_policy === 'read_existing_online_daily_data_ctrip_candidate_values_only'
    && manifest?.pricing_evidence_candidates?.candidate_values_exposed === true
    && manifest?.pricing_evidence_candidates?.raw_rows_exposed === false
    && manifest?.pricing_evidence_candidates?.database_written === false
    && manifest?.pricing_evidence_candidates?.auto_write_ota === false
    && manifest?.pricing_evidence_candidates?.importable_value === false
    && manifest?.pricing_evidence_candidates?.operator_review_required === true, {
    pricing_evidence_candidates: manifest?.pricing_evidence_candidates || null,
  });
  check(checks, 'external_input_candidates_manifest_non_importable',
    manifest?.external_input_candidates?.files?.json === 'external-input-candidates.json'
    && manifest?.external_input_candidates?.files?.markdown === 'external-input-candidates.md'
    && manifest?.external_input_candidates?.files?.csv === 'external-input-candidates.csv'
    && ['passed', 'blocked_by_no_external_input_candidates'].includes(String(manifest?.external_input_candidates?.status || ''))
    && manifest?.external_input_candidates?.source_scope === 'external_project_materials_for_ctrip_operator_review'
    && manifest?.external_input_candidates?.source_policy === 'read_allowlisted_external_project_materials_candidates_only_no_db_no_ota_write'
    && ['cli_or_default', 'bundle_manifest', 'bundle_manifest_with_cli_overrides'].includes(String(manifest?.external_input_candidates?.scope_source || ''))
    && manifest?.external_input_candidates?.bundle_manifest_scope_supported === true
    && manifest?.external_input_candidates?.candidate_values_exposed === true
    && manifest?.external_input_candidates?.raw_rows_exposed === false
    && manifest?.external_input_candidates?.database_written === false
    && manifest?.external_input_candidates?.auto_write_ota === false
    && manifest?.external_input_candidates?.importable_value === false
    && manifest?.external_input_candidates?.operator_review_required === true
    && manifest?.external_input_candidates?.not_floor_price_guard === true
    && manifest?.external_input_candidates?.not_current_ctrip_price_sample === true, {
    external_input_candidates: manifest?.external_input_candidates || null,
  });
  check(checks, 'external_input_candidates_payload_boundaries',
    externalInputCandidates?.scope?.business_date === expectedDate
    && String(externalInputCandidates?.scope?.hotel_id || '') === expectedHotelId
    && externalInputCandidates?.scope?.platform === 'ctrip'
    && ['cli_or_default', 'bundle_manifest', 'bundle_manifest_with_cli_overrides'].includes(String(externalInputCandidates?.scope?.scope_source || ''))
    && externalInputCandidates?.scope?.source_scope === 'external_project_materials_for_ctrip_operator_review'
    && externalInputCandidates?.scope?.ctrip_ota_channel_scope_preserved === true
    && externalInputCandidates?.scope?.meituan_scope_included === false
    && externalInputCandidates?.source_policy === 'read_allowlisted_external_project_materials_candidates_only_no_db_no_ota_write'
    && externalInputCandidates?.raw_rows_exposed === false
    && externalInputCandidates?.database_written === false
    && externalInputCandidates?.auto_write_ota === false
    && externalInputCandidates?.importable_value === false
    && externalInputCandidates?.importable_without_operator_review === false
    && externalInputCandidates?.operator_confirmation_required === true
    && Array.isArray(externalInputCandidates?.candidates?.room_count_candidates)
    && Array.isArray(externalInputCandidates?.candidates?.price_assumption_candidates)
    && Array.isArray(externalInputCandidates?.candidates?.competitor_reference_candidates)
    && Array.isArray(externalInputCandidates?.candidates?.room_concept_candidates)
    && Array.isArray(externalInputCandidates?.candidates?.market_distribution_candidates)
    && externalInputCandidatesText.includes('operator_confirmation_required')
    && externalInputCandidatesText.includes('old_project_context')
    && externalInputCandidatesText.includes('not_named_competitor_sample')
    && externalInputCandidatesText.includes('not_floor_price_guard')
    && externalInputCandidatesText.includes('not_current_ctrip_price_sample')
    && externalInputCandidatesText.includes('room_types_enabled')
    && externalInputCandidatesText.includes('floor_price_or_min_rate_guard')
    && externalInputCandidatesText.includes('competitor_price_samples')
    && !externalInputCandidatesText.includes('auto_write_ota": true'), {
    external_input_candidates_file: files.externalInputCandidates,
  });
  check(checks, 'external_input_candidates_markdown_and_csv_boundaries',
    externalInputCandidatesMarkdownText.includes('Ctrip External Input Candidates')
    && externalInputCandidatesMarkdownText.includes('source_scope: `external_project_materials_for_ctrip_operator_review`')
    && externalInputCandidatesMarkdownText.includes('database_written: `false`')
    && externalInputCandidatesMarkdownText.includes('auto_write_ota: `false`')
    && externalInputCandidatesMarkdownText.includes('importable_value: `false`')
    && externalInputCandidatesMarkdownText.includes('Room count candidates are not Ctrip room type mappings.')
    && externalInputCandidatesMarkdownText.includes('350/450 price assumptions are not floor/protection prices.')
    && externalInputCandidatesMarkdownText.includes('Historical competitor references are not current recent-7-day named Ctrip competitor samples.')
    && externalInputCandidatesMarkdownText.includes('Historical room concept candidates are not current Ctrip room type mappings.')
    && externalInputCandidatesMarkdownText.includes('Historical market price distributions are not current named Ctrip competitor price samples.')
    && externalInputCandidatesCsvHeader === 'candidate_group,candidate_status,candidate_type,value,source_note,source_excerpt,operator_action,importable_value,auto_write_ota'
    && externalInputCandidatesCsvText.includes('room_count_candidates')
    && externalInputCandidatesCsvText.includes('price_assumption_candidates')
    && externalInputCandidatesCsvText.includes('competitor_reference_candidates')
    && externalInputCandidatesCsvText.includes('room_concept_candidates')
    && externalInputCandidatesCsvText.includes('market_distribution_candidates')
    && externalInputCandidatesCsvText.includes(',false,false'), {
    markdown: files.externalInputCandidatesMarkdown,
    csv: files.externalInputCandidatesCsv,
  });
  check(checks, 'pricing_evidence_candidates_payload_boundaries',
    pricingEvidenceCandidates?.scope?.business_date === expectedDate
    && String(pricingEvidenceCandidates?.scope?.hotel_id || '') === expectedHotelId
    && pricingEvidenceCandidates?.scope?.platform === 'ctrip'
    && pricingEvidenceCandidates?.scope?.source_scope === 'ctrip_ota_channel'
    && pricingEvidenceCandidates?.scope?.meituan_scope_included === false
    && pricingEvidenceCandidates?.source_policy === 'read_existing_online_daily_data_ctrip_candidate_values_only'
    && pricingEvidenceCandidates?.raw_rows_exposed === false
    && pricingEvidenceCandidates?.candidate_values_exposed === true
    && pricingEvidenceCandidates?.database_written === false
    && pricingEvidenceCandidates?.auto_write_ota === false
    && pricingEvidenceCandidates?.importable_without_operator_review === false
    && Array.isArray(pricingEvidenceCandidates?.candidates?.room_type_candidates)
    && Array.isArray(pricingEvidenceCandidates?.candidates?.price_observation_candidates)
    && Array.isArray(pricingEvidenceCandidates?.candidates?.competitor_aggregate_candidates)
    && pricingEvidenceCandidatesText.includes('candidate_requires_operator_confirmation')
    && pricingEvidenceCandidatesText.includes('observed_ctrip_price_metric_not_floor_guard')
    && pricingEvidenceCandidatesText.includes('competitor_aggregate_not_price_sample')
    && pricingEvidenceCandidatesText.includes('operator-approved floor/protection min_price')
    && pricingEvidenceCandidatesText.includes('named Ctrip competitor price samples')
    && !pricingEvidenceCandidatesText.toLowerCase().includes('meituan rows'), {
    pricing_evidence_candidates_file: files.pricingEvidenceCandidates,
  });
  check(checks, 'pricing_evidence_candidates_markdown_and_csv_boundaries',
    pricingEvidenceCandidatesMarkdownText.includes('Ctrip Pricing Evidence Candidates')
    && pricingEvidenceCandidatesMarkdownText.includes('source_scope: `ctrip_ota_channel`')
    && pricingEvidenceCandidatesMarkdownText.includes('database_written: `false`')
    && pricingEvidenceCandidatesMarkdownText.includes('auto_write_ota: `false`')
    && pricingEvidenceCandidatesMarkdownText.includes('importable_without_operator_review: `false`')
    && pricingEvidenceCandidatesMarkdownText.includes('Room Type Candidates')
    && pricingEvidenceCandidatesMarkdownText.includes('Price Observations')
    && pricingEvidenceCandidatesMarkdownText.includes('Competitor Aggregates')
    && pricingEvidenceCandidatesMarkdownText.includes('Price observations are not floor/protection prices.')
    && pricingEvidenceCandidatesMarkdownText.includes('Competitor aggregates are not named competitor price samples.')
    && pricingEvidenceCandidatesCsvHeader === 'candidate_group,candidate_status,field,value,source_note,operator_action,importable_value,missing_for_import'
    && pricingEvidenceCandidatesCsvText.includes('room_type_candidates')
    && pricingEvidenceCandidatesCsvText.includes('price_observation_candidates')
    && pricingEvidenceCandidatesCsvText.includes('competitor_aggregate_candidates')
    && pricingEvidenceCandidatesCsvText.includes(',false,'), {
    markdown: files.pricingEvidenceCandidatesMarkdown,
    csv: files.pricingEvidenceCandidatesCsv,
  });
  check(checks, 'demand_trend_draft_manifest_non_importable',
    manifest?.demand_trend_draft?.files?.json === 'demand-trend-draft.json'
    && manifest?.demand_trend_draft?.files?.markdown === 'demand-trend-draft.md'
    && ['passed', 'blocked_by_insufficient_traffic_history'].includes(String(manifest?.demand_trend_draft?.status || ''))
    && manifest?.demand_trend_draft?.source_scope === 'ctrip_ota_channel'
    && manifest?.demand_trend_draft?.source_policy === 'read_existing_online_daily_data_ctrip_traffic_aggregates_only'
    && manifest?.demand_trend_draft?.raw_rows_exposed === false
    && manifest?.demand_trend_draft?.aggregate_values_exposed === true
    && manifest?.demand_trend_draft?.database_written === false
    && manifest?.demand_trend_draft?.auto_write_ota === false
    && manifest?.demand_trend_draft?.importable_value === false
    && manifest?.demand_trend_draft?.operator_review_required === true
    && manifest?.demand_trend_draft?.requires_room_type_key_mapping === true, {
    demand_trend_draft: manifest?.demand_trend_draft || null,
  });
  check(checks, 'demand_trend_draft_payload_boundaries',
    demandTrendDraft?.scope?.business_date === expectedDate
    && String(demandTrendDraft?.scope?.hotel_id || '') === expectedHotelId
    && demandTrendDraft?.scope?.platform === 'ctrip'
    && demandTrendDraft?.scope?.source_scope === 'ctrip_ota_channel'
    && demandTrendDraft?.scope?.meituan_scope_included === false
    && demandTrendDraft?.source_policy === 'read_existing_online_daily_data_ctrip_traffic_aggregates_only'
    && demandTrendDraft?.raw_rows_exposed === false
    && demandTrendDraft?.aggregate_values_exposed === true
    && demandTrendDraft?.database_written === false
    && demandTrendDraft?.auto_write_ota === false
    && demandTrendDraft?.importable_without_operator_review === false
    && demandTrendDraftText.includes('traffic_trend_score_0_100_for_Ctrip_channel_demand_trend_not_whole_hotel_occupancy')
    && demandTrendDraftText.includes('operator_room_type_key_required')
    && demandTrendDraftText.includes('ctrip_historical_traffic_trend')
    && demandTrendDraftMarkdownText.includes('database_written: `false`')
    && demandTrendDraftMarkdownText.includes('auto_write_ota: `false`'), {
    demand_trend_draft_file: files.demandTrendDraft,
  });
  check(checks, 'operator_review_draft_human_review_only',
    operatorReviewDraftText.includes('Ctrip Operator Review Draft')
    && operatorReviewDraftText.includes('draft_policy: `human_review_draft_not_importable`')
    && operatorReviewDraftText.includes('source_scope: `ctrip_ota_channel`')
    && operatorReviewDraftText.includes('database_written: `false`')
    && operatorReviewDraftText.includes('auto_write_ota: `false`')
    && operatorReviewDraftText.includes('importable_value: `false`')
    && operatorReviewDraftText.includes('Demand Trend Draft')
    && operatorReviewDraftText.includes('External Project-Material Clues Not Inputs')
    && operatorReviewDraftText.includes('Room Type Candidates To Confirm')
    && operatorReviewDraftText.includes('Price Observations Not Guards')
    && operatorReviewDraftText.includes('Competitor Aggregates Not Price Samples')
    && operatorReviewDraftText.includes('not whole-hotel occupancy')
    && operatorReviewDraftText.includes('Copy values only into `pricing-input-fillable.json` after operator confirmation.')
    && operatorReviewDraftText.includes('External project-material clues are not importable')
    && operatorReviewDraftText.includes('historical room concepts are not current Ctrip room mappings')
    && operatorReviewDraftText.includes('historical competitor or market references are not current Ctrip samples')
    && operatorReviewDraftText.includes('Price observations are not floor/protection prices.')
    && operatorReviewDraftText.includes('Competitor aggregates are not named competitor price samples.')
    && manifest?.operator_review_draft?.file === 'OPERATOR_REVIEW_DRAFT.md'
    && manifest?.operator_review_draft?.status === 'human_review_draft_not_importable'
    && manifest?.operator_review_draft?.source_scope === 'ctrip_ota_channel'
    && manifest?.operator_review_draft?.database_written === false
    && manifest?.operator_review_draft?.auto_write_ota === false
    && manifest?.operator_review_draft?.importable_value === false, {
    operator_review_draft: manifest?.operator_review_draft || null,
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
  const checklistItems = Array.isArray(realInputChecklist?.items) ? realInputChecklist.items : [];
  const checklistPaths = checklistItems.map((item) => String(item?.path || ''));
  const checklistCsvColumns = checklistItems.map((item) => String(item?.csv_column || ''));
  const checklistCsvSections = checklistItems.map((item) => String(item?.csv_section || ''));
  const checklistCsvRows = checklistItems.map((item) => Number(item?.csv_row_number || 0));
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
    && checklistCsvColumns.includes('confirmed_by')
    && checklistCsvColumns.includes('base_price')
    && checklistCsvColumns.includes('competitor_price')
    && checklistCsvSections.includes('evidence')
    && checklistCsvSections.includes('room_type')
    && checklistCsvSections.includes('competitor_price_sample')
    && checklistCsvRows.includes(2)
    && checklistCsvRows.includes(3)
    && checklistCsvRows.includes(trafficTrendDemandReady ? 4 : 5)
    && (trafficTrendDemandReady
      ? demandForecastSourceNamesTrafficTrend
      : checklistPaths.includes('$.demand_forecasts.0.predicted_occupancy'))
    && checklistPaths.includes('$.competitor_price_samples.0.competitor_price')
    && realInputChecklistText.includes('pricing-input-intake.csv')
    && realInputChecklistText.includes('csv_row_number')
    && realInputChecklistText.includes('csv_column')
    && realInputChecklistText.includes('sample, guessed, fallback, verifier-only, Meituan, or whole-hotel value'), {
    real_input_checklist: manifest?.real_input_checklist || null,
    placeholder_count: realInputChecklist?.placeholder_count,
    traffic_trend_demand_ready: trafficTrendDemandReady,
    demand_forecast_source: demandForecastSourceText,
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
    && pricingInputSchema?.properties?.demand_forecasts?.minItems === (trafficTrendDemandReady ? 0 : 1)
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
    && (trafficTrendDemandReady
      ? operatorIntakeFormText.includes('Demand Forecast Source')
        && operatorIntakeFormText.includes('No demand_forecasts row is required on the Ctrip traffic-trend path.')
        && operatorIntakeFormText.includes('Manual override only: add `demand_forecasts` rows only when replacing the traffic-trend demand source')
        && operatorIntakeFormText.includes('leave `demand_forecasts` empty unless recording an explicit manual override')
        && !operatorIntakeFormText.includes('|  | ' + expectedDate + ' |  |  |  |  |  |')
      : operatorIntakeFormText.includes('Demand Forecasts')
        && operatorIntakeFormText.includes('|  | ' + expectedDate + ' |  |  |  |  |  |'))
    && operatorIntakeFormText.includes('Ctrip Competitor Price Samples')
    && operatorIntakeFormText.includes('pricing-input-fillable.json')
    && operatorIntakeFormText.includes('OPERATOR_REVIEW_DRAFT.md')
    && operatorIntakeFormText.includes('operator-action-sheet.csv')
    && operatorIntakeFormText.includes('field-by-field action list')
    && operatorIntakeFormText.includes('operator-input-locators.csv')
    && operatorIntakeFormText.includes('external-input-candidates.*')
    && operatorIntakeFormText.includes('pricing-evidence-candidates.*')
    && operatorIntakeFormText.includes('candidate values without operator confirmation')
    && operatorIntakeFormText.includes('demand_trend_draft')
    && operatorIntakeFormText.includes('report:revenue-ai-ctrip-traffic-demand-trend')
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
    && manifest?.operator_intake_form?.demand_forecast_row_required === !trafficTrendDemandReady
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
    && closureRunbookText.includes('OPERATOR_REVIEW_DRAFT.md')
    && closureRunbookText.includes('operator-action-sheet.csv')
    && closureRunbookText.includes('map candidate hints to exact `pricing-input-intake.csv` fields')
    && closureRunbookText.includes('pricing-input-intake.csv')
    && closureRunbookText.includes('operator-input-locators.csv')
    && closureRunbookText.includes('input_readiness_scan')
    && closureRunbookText.includes('report:revenue-ai-ctrip-input-readiness')
    && closureRunbookText.includes('external_input_candidates')
    && closureRunbookText.includes('External project-material clues do not replace Ctrip room type mapping')
    && closureRunbookText.includes('pricing_evidence_candidates')
    && closureRunbookText.includes('Candidate price observations are not floor/protection prices')
    && closureRunbookText.includes('competitor aggregates are not named competitor price samples')
    && closureRunbookText.includes('demand_trend_draft')
    && closureRunbookText.includes('report:revenue-ai-ctrip-traffic-demand-trend')
    && closureRunbookText.includes('csv_issue_map.csv_row_number')
    && closureRunbookText.includes('csv_issue_map.csv_column')
    && closureRunbookText.includes('operator_row_source_note')
    && closureRunbookText.includes('build_fillable_from_csv')
    && closureRunbookText.includes('csv_to_json_preflight')
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
    && realInputTodoText.includes('Minimum CSV Fill Cells')
    && realInputTodoText.includes('Field-Level Real Input Checklist')
    && realInputTodoText.includes('pricing_generation_preflight.status')
    && realInputTodoText.includes('skipped_by_operator_policy')
    && realInputTodoText.includes('operator_input_evidence')
    && realInputTodoText.includes('$.operator_input_evidence.confirmed_by')
    && realInputTodoText.includes('$.room_types.0.base_price')
    && (trafficTrendDemandReady
      ? realInputTodoText.includes('ctrip_historical_traffic_trend')
      : realInputTodoText.includes('$.demand_forecasts.0.predicted_occupancy'))
    && realInputTodoText.includes('$.competitor_price_samples.0.competitor_price')
    && realInputTodoText.includes('Fill only real operator-verified Ctrip OTA channel values')
    && realInputTodoText.includes('OPERATOR_REVIEW_DRAFT.md')
    && realInputTodoText.includes('operator-action-sheet.csv')
    && realInputTodoText.includes('candidate hints to exact intake CSV fields')
    && realInputTodoText.includes('pricing-input-intake.csv')
    && realInputTodoText.includes('CSV row')
    && realInputTodoText.includes('CSV section')
    && realInputTodoText.includes('CSV column')
    && realInputTodoText.includes('competitor_price_sample')
    && realInputTodoText.includes('These cells are the current minimum spreadsheet inputs')
    && realInputTodoText.includes('operator-input-locators.csv')
    && realInputTodoText.includes('external_input_candidates')
    && realInputTodoText.includes('external project-material')
    && realInputTodoText.includes('pricing-evidence-candidates.*')
    && realInputTodoText.includes('not importable until an operator confirms')
    && realInputTodoText.includes('Do not use Ctrip price observations as floor/protection prices')
    && realInputTodoText.includes('demand_trend_draft')
    && realInputTodoText.includes('report:revenue-ai-ctrip-traffic-demand-trend')
    && realInputTodoText.includes('csv_issue_map.csv_row_number')
    && realInputTodoText.includes('csv_issue_map.csv_column')
    && realInputTodoText.includes('operator_row_source_note')
    && realInputTodoText.includes('source_note`: optional row-level evidence note')
    && realInputTodoText.includes('build_fillable_from_csv')
    && realInputTodoText.includes('csv_to_json_preflight')
    && realInputTodoText.includes('Ctrip source hints are not importable values')
    && realInputTodoText.includes('Locator row ids and source traces are metadata only')
    && realInputTodoText.includes('metadata-only evidence navigation')
    && p0AuthorityTodoMatches
    && !realInputTodoText.includes('Ctrip P0 目标日闭环已通过')
    && !realInputTodoText.includes('current gate: `room_types_empty`'), {
    file: files.realInputTodo,
    p0_authority_status: p0AuthorityStatus,
    p0_authority: manifest?.p0_authority || null,
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
