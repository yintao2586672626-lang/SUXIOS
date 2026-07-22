import fs from 'node:fs';
import path from 'node:path';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

function parseArgs(argv) {
  const options = {
    dir: '',
    date: '',
    hotelId: '',
    format: 'json',
  };

  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) {
      continue;
    }
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (key === 'dir' || key === 'bundle-dir' || key === 'bundle_dir') {
      options.dir = String(value || '').trim();
    } else if (key === 'date' || key === 'business-date' || key === 'business_date') {
      options.date = String(value || '').trim();
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = String(value || '').trim();
    } else if (key === 'format') {
      options.format = String(value || '').trim().toLowerCase();
    }
  }

  if (options.dir === '') {
    throw new Error('Missing --dir pointing to a Ctrip operator bundle.');
  }
  if (!['json', 'markdown'].includes(options.format)) {
    throw new Error('Invalid --format, expected json or markdown.');
  }

  return options;
}

function readJson(file) {
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
    throw new Error(`Missing required bundle file: ${file}`);
  }
  return parseJsonTextSafely(fs.readFileSync(file, 'utf8'), 'revenue_ai_ctrip_bundle_json');
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function countImportable(items) {
  return asArray(items).filter((item) => item && item.importable_value === true).length;
}

function firstSourceNote(items) {
  const item = asArray(items).find((candidate) => typeof candidate?.source_note === 'string' && candidate.source_note !== '');
  return item?.source_note || null;
}

function compact(values) {
  return values.filter((value) => value !== null && value !== undefined && value !== '');
}

function boolCount(value) {
  const numberValue = Number(value || 0);
  return Number.isFinite(numberValue) ? numberValue : 0;
}

function buildPayload(options) {
  const bundleDir = path.resolve(options.dir);
  const files = {
    inputReadiness: path.join(bundleDir, 'input-readiness.json'),
    pricingEvidence: path.join(bundleDir, 'pricing-evidence-candidates.json'),
    externalInput: path.join(bundleDir, 'external-input-candidates.json'),
    demandTrend: path.join(bundleDir, 'demand-trend-draft.json'),
  };
  const inputReadiness = readJson(files.inputReadiness);
  const pricingEvidence = readJson(files.pricingEvidence);
  const externalInput = readJson(files.externalInput);
  const demandTrend = readJson(files.demandTrend);

  const target = inputReadiness?.summary?.target_date_candidate || inputReadiness?.summary?.best_available_candidate || {};
  const pricingCandidates = pricingEvidence?.candidates || {};
  const externalCandidates = externalInput?.candidates || {};
  const roomTypeCandidates = asArray(pricingCandidates.room_type_candidates);
  const priceObservationCandidates = asArray(pricingCandidates.price_observation_candidates);
  const competitorAggregateCandidates = asArray(pricingCandidates.competitor_aggregate_candidates);
  const externalRoomCountCandidates = asArray(externalCandidates.room_count_candidates);
  const externalPriceAssumptions = asArray(externalCandidates.price_assumption_candidates);
  const externalCompetitorReferences = asArray(externalCandidates.competitor_reference_candidates);
  const externalMarketDistributions = asArray(externalCandidates.market_distribution_candidates);

  const businessDate = options.date || String(target.date || pricingEvidence?.scope?.business_date || demandTrend?.scope?.business_date || '');
  const hotelId = options.hotelId || String(target.hotel_id || pricingEvidence?.scope?.hotel_id || demandTrend?.scope?.hotel_id || '');
  const demandSourceIsTrafficTrend = String(target.demand_forecast_source || '') === 'ctrip_historical_traffic_trend'
    || String(demandTrend?.trend_source || '') === 'ctrip_historical_traffic_trend';
  const demandTrendReady = demandSourceIsTrafficTrend && String(demandTrend?.status || '') === 'passed';
  const roomTypesEnabled = boolCount(target.room_types_enabled);
  const roomPriceGuards = boolCount(target.room_price_guards);
  const competitorSamples = boolCount(target.competitor_samples_recent_7d);

  const blockers = [
    {
      code: 'room_types_enabled',
      operator_question: 'Which Ctrip room type is enabled for pricing, and what is its sellable room count?',
      reply_keys: ['room_type_source', 'room_type_key', 'room_type_name', 'room_count', 'room_type_1_key', 'room_type_1_name', 'room_type_1_count'],
      auto_close_eligible: roomTypesEnabled > 0,
      current_verified_count: roomTypesEnabled,
      candidate_count: roomTypeCandidates.length + externalRoomCountCandidates.length,
      importable_candidate_count: countImportable(roomTypeCandidates) + countImportable(externalRoomCountCandidates),
      strongest_source_note: firstSourceNote(roomTypeCandidates) || firstSourceNote(externalRoomCountCandidates),
      reason: roomTypesEnabled > 0
        ? 'existing_enabled_room_type_rows_present'
        : 'room_name_or_room_count_candidates_do_not_prove_operator_enabled_room_type_key_and_sellable_room_count',
      still_required: roomTypesEnabled > 0 ? [] : [
        'operator-confirmed Ctrip room_type_key',
        'operator-confirmed Ctrip room_type_name',
        'operator-confirmed sellable room_count',
      ],
    },
    {
      code: 'floor_price_or_min_rate_guard',
      operator_question: 'What are the operator-approved base, floor/protection, and upper guard prices for that Ctrip room type?',
      reply_keys: ['price_guard_source', 'base_price', 'min_price', 'max_price', 'room_type_1_base_price', 'room_type_1_min_price', 'room_type_1_max_price'],
      auto_close_eligible: roomPriceGuards > 0,
      current_verified_count: roomPriceGuards,
      candidate_count: priceObservationCandidates.length + externalPriceAssumptions.length,
      importable_candidate_count: countImportable(priceObservationCandidates) + countImportable(externalPriceAssumptions),
      strongest_source_note: firstSourceNote(priceObservationCandidates) || firstSourceNote(externalPriceAssumptions),
      reason: roomPriceGuards > 0
        ? 'existing_room_type_price_guard_rows_present'
        : 'observed_average_prices_and_350_450_assumptions_are_not_operator_approved_base_min_max_guards',
      still_required: roomPriceGuards > 0 ? [] : [
        'operator-approved base_price',
        'operator-approved floor/protection min_price',
        'operator-approved max_price',
      ],
    },
    {
      code: 'competitor_price_samples',
      operator_question: 'Which recent same-window named Ctrip competitor sample should be used for this room type?',
      reply_keys: ['competitor_price_source', 'competitor_analysis_date', 'competitor_room_type_key', 'competitor_name', 'our_price', 'competitor_price', 'competitor_sample_1_analysis_date', 'competitor_sample_1_room_type_key', 'competitor_sample_1_name', 'competitor_sample_1_our_price', 'competitor_sample_1_competitor_price'],
      auto_close_eligible: competitorSamples > 0,
      current_verified_count: competitorSamples,
      candidate_count: competitorAggregateCandidates.length + externalCompetitorReferences.length + externalMarketDistributions.length,
      importable_candidate_count: countImportable(competitorAggregateCandidates) + countImportable(externalCompetitorReferences) + countImportable(externalMarketDistributions),
      strongest_source_note: firstSourceNote(competitorAggregateCandidates) || firstSourceNote(externalCompetitorReferences) || firstSourceNote(externalMarketDistributions),
      reason: competitorSamples > 0
        ? 'existing_recent_named_ctrip_competitor_price_samples_present'
        : 'competitor_aggregates_and_historical_market_references_are_not_recent_named_ctrip_price_samples',
      still_required: competitorSamples > 0 ? [] : [
        'recent-7-day named Ctrip competitor_name',
        'our_price from the same comparable Ctrip sample',
        'competitor_price from the same comparable Ctrip sample',
        'room_type_key mapping reused from room type input',
      ],
    },
    {
      code: 'demand_forecast_source',
      operator_question: 'Should the Ctrip historical traffic trend remain the demand forecast source?',
      reply_keys: ['demand_forecast_source'],
      auto_close_eligible: demandTrendReady,
      current_verified_count: demandTrendReady ? 1 : 0,
      candidate_count: demandTrendReady ? 1 : 0,
      importable_candidate_count: 0,
      strongest_source_note: demandTrend?.forecast_draft?.import_row_draft?.source_note || null,
      reason: demandTrendReady
        ? 'ctrip_historical_traffic_trend_is_accepted_as_demand_forecast_source'
        : 'missing_accepted_demand_forecast_source',
      still_required: demandTrendReady ? [] : ['accepted demand_forecast_source or manual forecast row'],
    },
  ];
  const blockingCodes = blockers
    .filter((item) => !item.auto_close_eligible && item.code !== 'demand_forecast_source')
    .map((item) => item.code);
  const fullAutoCloseEligible = blockingCodes.length === 0 && demandTrendReady;

  return {
    status: fullAutoCloseEligible ? 'auto_close_eligible' : 'blocked_by_operator_real_inputs',
    scope: {
      business_date: businessDate,
      platform: 'ctrip',
      enabled_channels: ['ctrip'],
      hotel_id: hotelId === '' ? null : Number(hotelId),
      source_scope: 'ctrip_ota_channel',
      meituan_scope_included: false,
    },
    source_policy: 'read_operator_bundle_evidence_only_no_db_no_ota_write',
    bundle_dir: bundleDir,
    raw_rows_exposed: false,
    database_written: false,
    auto_write_ota: false,
    importable_value: false,
    auto_import_allowed: false,
    summary: {
      full_auto_close_eligible: fullAutoCloseEligible,
      can_generate_pending_review: fullAutoCloseEligible,
      demand_forecast_source_status: demandTrendReady ? 'ready_from_ctrip_historical_traffic_trend' : 'missing',
      current_required_real_inputs_before_execute: blockingCodes,
      operator_reply_file: 'OPERATOR_QUICK_REPLY.md',
      operator_reply_keys_are_not_importable_until_preflight_passes: true,
      candidate_values_are_review_aids_only: true,
      no_ota_price_write: true,
    },
    blockers,
    stop_conditions: [
      'Do not convert room name candidates into room_types without operator-confirmed room_type_key and room_count.',
      'Do not convert observed average prices or 350/450 assumptions into base/min/max guards without operator approval.',
      'Do not convert competitor aggregates or historical references into named recent Ctrip competitor price samples.',
      'Do not generate pending review suggestions until every non-demand blocker is auto_close_eligible or operator-filled.',
      'Do not write OTA prices.',
    ],
    files_read: files,
  };
}

function markdownCell(value) {
  return String(value ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');
}

function toMarkdown(payload) {
  const lines = [];
  lines.push('# Ctrip Auto Input Eligibility');
  lines.push('');
  lines.push(`- status: \`${payload.status}\``);
  lines.push(`- business_date: \`${payload.scope.business_date}\``);
  lines.push(`- hotel_id: \`${payload.scope.hotel_id ?? 'unknown'}\``);
  lines.push(`- source_scope: \`${payload.scope.source_scope}\``);
  lines.push(`- source_policy: \`${payload.source_policy}\``);
  lines.push('- database_written: `false`');
  lines.push('- auto_write_ota: `false`');
  lines.push('- auto_import_allowed: `false`');
  lines.push(`- full_auto_close_eligible: \`${payload.summary.full_auto_close_eligible}\``);
  lines.push(`- demand_forecast_source_status: \`${payload.summary.demand_forecast_source_status}\``);
  lines.push(`- current_required_real_inputs_before_execute: \`${payload.summary.current_required_real_inputs_before_execute.join('; ') || 'none'}\``);
  lines.push(`- operator_reply_file: \`${payload.summary.operator_reply_file}\``);
  lines.push('- operator_reply_keys_are_not_importable_until_preflight_passes: `true`');
  lines.push('');
  lines.push('## Eligibility By Input');
  lines.push('');
  lines.push('| input | auto_close_eligible | verified_count | candidate_count | importable_candidate_count | reason | reply_keys | still_required |');
  lines.push('|---|---:|---:|---:|---:|---|---|---|');
  for (const blocker of payload.blockers) {
    lines.push(`| \`${markdownCell(blocker.code)}\` | ${blocker.auto_close_eligible ? 'true' : 'false'} | ${blocker.current_verified_count} | ${blocker.candidate_count} | ${blocker.importable_candidate_count} | ${markdownCell(blocker.reason)} | ${markdownCell(blocker.reply_keys.join('; '))} | ${markdownCell(blocker.still_required.join('; '))} |`);
  }
  lines.push('');
  lines.push('## Operator Questions');
  lines.push('');
  for (const blocker of payload.blockers) {
    if (blocker.auto_close_eligible && blocker.code !== 'demand_forecast_source') {
      continue;
    }
    lines.push(`- \`${markdownCell(blocker.code)}\`: ${markdownCell(blocker.operator_question)} Reply keys: \`${markdownCell(blocker.reply_keys.join('; '))}\`.`);
  }
  lines.push('');
  lines.push('## Stop Conditions');
  lines.push('');
  for (const condition of payload.stop_conditions) {
    lines.push(`- ${condition}`);
  }

  return `${lines.join('\n')}\n`;
}

try {
  const options = parseArgs(process.argv);
  const payload = buildPayload(options);
  if (options.format === 'markdown') {
    process.stdout.write(toMarkdown(payload));
  } else {
    process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`);
  }
} catch (error) {
  process.stderr.write(`${error?.stack || error?.message || String(error)}\n`);
  process.exit(1);
}
