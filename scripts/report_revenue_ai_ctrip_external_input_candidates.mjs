import fs from 'node:fs';
import path from 'node:path';

const defaultRoot = process.env.SUXIOS_CTRIP_EXTERNAL_INPUT_ROOT || 'E:\\杂项\\重庆香格里拉项目';
const allowlistedFiles = [
  '项目说明.txt',
  path.join('outputs', 'feasibility_project_plan.md'),
  path.join('outputs', 'feasibility_project_plan_v2.md'),
  path.join('outputs', 'feasibility_project_plan_v3_extended.md'),
  path.join('outputs', '重庆解放碑区位与品牌沟通输出稿.md'),
  path.join('outputs', 'reference_final4_2_text.txt'),
  path.join('outputs', 'feasibility_work', 'texts', '最终4.2.txt'),
];

function parseArgs(argv) {
  const options = {
    date: '',
    hotelId: '',
    format: 'json',
    root: defaultRoot,
    dir: '',
    scopeSource: 'cli_or_default',
    bundleDir: '',
    bundleManifestPath: '',
    bundleManifestScope: null,
    explicitDate: false,
    explicitHotelId: false,
  };

  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) {
      continue;
    }
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (key === 'date' || key === 'business-date' || key === 'business_date') {
      options.date = String(value || '').trim();
      options.explicitDate = true;
    } else if (key === 'hotel-id' || key === 'hotel_id') {
      options.hotelId = String(value || '').trim();
      options.explicitHotelId = true;
    } else if (key === 'format') {
      options.format = String(value || '').trim().toLowerCase();
    } else if (key === 'root') {
      options.root = String(value || '').trim();
    } else if (key === 'dir' || key === 'bundle-dir' || key === 'bundle_dir') {
      options.dir = String(value || '').trim();
    }
  }

  if (options.dir !== '') {
    const bundleDir = path.resolve(options.dir);
    const manifestPath = path.join(bundleDir, 'manifest.json');
    if (!fs.existsSync(manifestPath) || !fs.statSync(manifestPath).isFile()) {
      throw new Error('Missing operator bundle manifest.json under --dir.');
    }
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    const scope = manifest && typeof manifest === 'object' ? manifest.scope || {} : {};
    options.bundleDir = bundleDir;
    options.bundleManifestPath = manifestPath;
    options.bundleManifestScope = scope;
    options.scopeSource = options.explicitDate || options.explicitHotelId
      ? 'bundle_manifest_with_cli_overrides'
      : 'bundle_manifest';
    if (!options.explicitDate && typeof scope.business_date === 'string') {
      options.date = scope.business_date.trim();
    }
    if (!options.explicitHotelId && scope.hotel_id !== undefined && scope.hotel_id !== null && String(scope.hotel_id).trim() !== '') {
      options.hotelId = String(scope.hotel_id).trim();
    }
  }

  if (options.date === '') {
    options.date = new Date().toISOString().slice(0, 10);
  }

  if (!/^\d{4}-\d{2}-\d{2}$/.test(options.date)) {
    throw new Error('Invalid --date, expected YYYY-MM-DD.');
  }
  if (options.hotelId !== '' && !/^\d+$/.test(options.hotelId)) {
    throw new Error('Invalid --hotel-id, expected a positive integer.');
  }
  if (!['json', 'markdown', 'csv'].includes(options.format)) {
    throw new Error('Invalid --format, expected json, markdown, or csv.');
  }

  return options;
}

function readAllowlistedSources(root) {
  return allowlistedFiles.map((relativePath) => {
    const filePath = path.resolve(root, relativePath);
    if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
      return {
        relative_path: relativePath.replaceAll(path.sep, '/'),
        absolute_path: filePath,
        exists: false,
        lines: [],
      };
    }
    const text = fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, '');
    return {
      relative_path: relativePath.replaceAll(path.sep, '/'),
      absolute_path: filePath,
      exists: true,
      lines: text.split(/\r?\n/),
    };
  });
}

function sourceRef(source, index) {
  return `${source.relative_path}:${index + 1}`;
}

function normalizeLine(line) {
  return String(line || '').replace(/\s+/g, ' ').trim();
}

function candidateBase(source, index, line) {
  return {
    candidate_status: 'operator_confirmation_required',
    source_ref: sourceRef(source, index),
    source_note: `external_project_materials:${sourceRef(source, index)}`,
    source_excerpt: normalizeLine(line),
    source_scope: 'external_project_materials_for_ctrip_operator_review',
    source_policy: 'candidate_hint_only_no_import_no_db_no_ota_write',
    importable_value: false,
    importable_without_operator_review: false,
    operator_confirmation_required: true,
    database_written: false,
    auto_write_ota: false,
  };
}

function roomCountCandidates(source, index, line) {
  const text = normalizeLine(line);
  const candidates = [];
  const push = (value, label) => {
    candidates.push({
      ...candidateBase(source, index, text),
      candidate_type: 'room_count_candidate',
      observed_value: value,
      interpretation: label,
      not_room_type_mapping: true,
      missing_for_import: [
        'operator-confirmed Ctrip room_type_key',
        'operator-verified Ctrip room_type_name',
        'operator-confirmed sellable room_count by room type',
        'operator-approved base/min/max price guard',
      ],
      operator_action: 'Use only as a room inventory clue; operator must confirm Ctrip room type mapping and sellable room count before import.',
    });
  };

  if (text.includes('客房总数137间') || text.includes('合计137间') || /房量.*137间/.test(text)) {
    push('137', 'project-level total room count candidate');
  }
  if (text.includes('120-137') || text.includes('120或137间') || text.includes('120间客房') || text.includes('房间数减少到120间')) {
    push('120', 'brand/pre-review room count scenario');
  }

  return candidates;
}

function priceAssumptionCandidates(source, index, line) {
  const text = normalizeLine(line);
  const candidates = [];
  const push = (value, label) => {
    candidates.push({
      ...candidateBase(source, index, text),
      candidate_type: 'price_assumption_candidate',
      observed_value: value,
      interpretation: label,
      not_floor_price_guard: true,
      not_current_sell_price: true,
      missing_for_import: [
        'operator-approved Ctrip base_price by room type',
        'operator-approved floor/protection min_price by room type',
        'operator-approved max_price by room type',
        'source note proving current Ctrip price guard',
      ],
      operator_action: 'Use only as feasibility context; do not copy as base_price, min_price, or max_price unless the operator approves it as a current Ctrip price guard.',
    });
  };

  if (text.includes('均价350元') || text.includes('350元') && text.includes('入住率')) {
    push('350', 'feasibility ADR assumption');
  }
  if (text.includes('RP450') || text.includes('450元')) {
    push('450', text.includes('RevPAR') || text.includes('RP') ? 'RP/RevPAR or ADR scenario needing clarification' : 'price scenario needing clarification');
  }

  return candidates;
}

function competitorReferenceCandidates(source, index, line) {
  const text = normalizeLine(line);
  const candidates = [];
  const push = (name, priceRange) => {
    candidates.push({
      ...candidateBase(source, index, text),
      candidate_type: 'historical_competitor_reference',
      competitor_name_candidate: name,
      historical_ctrip_price_reference: priceRange,
      not_current_ctrip_price_sample: true,
      not_recent_7d_sample: true,
      missing_for_import: [
        'current analysis_date within 7 days ending at business_date',
        'operator-confirmed room_type_key',
        'current our_price from Ctrip sample',
        'current competitor_price from named Ctrip sample',
      ],
      operator_action: 'Use only to choose or review competitor targets; collect a current named Ctrip competitor price sample before import.',
    });
  };

  if (text.includes('重庆来福士洲际酒店') && text.includes('1609-3755')) {
    push('重庆来福士洲际酒店', '1609-3755');
  }
  if (text.includes('重庆雅诗阁来福士服务公寓') && text.includes('834-3170')) {
    push('重庆雅诗阁来福士服务公寓', '834-3170');
  }

  return candidates;
}

function roomConceptCandidates(source, index, line) {
  const text = normalizeLine(line);
  if (!text.includes('高空江景房') || !text.includes('商务办公长住房')) {
    return [];
  }

  return [{
    ...candidateBase(source, index, text),
    candidate_type: 'historical_room_concept_candidate',
    observed_value: '高空江景房; 商务办公长住房; 网红直播房; 游戏电竞房',
    interpretation: 'historical project room concept only',
    old_project_context: true,
    not_room_type_mapping: true,
    missing_for_import: [
      'operator-confirmed current Ctrip room_type_key',
      'operator-verified current Ctrip room_type_name',
      'operator-confirmed sellable room_count by room type',
      'operator-approved base/min/max price guard',
    ],
    operator_action: 'Use only as a historical room concept clue; operator must confirm current Ctrip room type mapping, room count, and price guard before import.',
  }];
}

function marketDistributionCandidates(source) {
  const candidates = [];
  source.lines.forEach((line, index) => {
    const text = normalizeLine(line);
    if (!text.includes('按价格分布如下')) {
      return;
    }
    const start = Math.max(0, index - 24);
    const end = Math.min(source.lines.length, index + 4);
    const windowText = source.lines.slice(start, end).map(normalizeLine).filter(Boolean).join(' ');
    const hasCtripSource = source.lines
      .slice(index, Math.min(source.lines.length, index + 8))
      .map(normalizeLine)
      .some((item) => item.includes('数据来源：携程网'));
    const hasExpectedBins = /800\s*元以上\s*3\s*家.*2\.1/.test(windowText)
      && /500\s*元以上\s*19\s*家.*13\.6/.test(windowText)
      && /300-500\s*元\s*51\s*家.*36\.4/.test(windowText)
      && /300\s*以下\s*67\s*家.*47\.9/.test(windowText);
    if (!hasCtripSource || !hasExpectedBins) {
      return;
    }
    candidates.push({
      ...candidateBase(source, index, text),
      candidate_type: 'historical_ctrip_market_price_distribution',
      observed_value: '800元以上:3家/2.1%; 500元以上:19家/13.6%; 300-500元:51家/36.4%; 300元以下:67家/47.9%',
      interpretation: 'historical Jiefangbei Ctrip market price-bin distribution',
      market_scope_candidate: '解放碑周边酒店',
      not_floor_price_guard: true,
      not_current_ctrip_price_sample: true,
      not_recent_7d_sample: true,
      not_named_competitor_sample: true,
      missing_for_import: [
        'current analysis_date within 7 days ending at business_date',
        'operator-confirmed room_type_key',
        'current named competitor_name',
        'current our_price from Ctrip sample',
        'current competitor_price from named Ctrip sample',
      ],
      operator_action: 'Use only as historical market context; collect current recent-7-day named Ctrip competitor price samples before import.',
    });
  });
  return candidates;
}

function collectCandidates(sources) {
  const candidates = {
    room_count_candidates: [],
    price_assumption_candidates: [],
    competitor_reference_candidates: [],
    room_concept_candidates: [],
    market_distribution_candidates: [],
  };

  for (const source of sources) {
    if (!source.exists) {
      continue;
    }
    source.lines.forEach((line, index) => {
      candidates.room_count_candidates.push(...roomCountCandidates(source, index, line));
      candidates.price_assumption_candidates.push(...priceAssumptionCandidates(source, index, line));
      candidates.competitor_reference_candidates.push(...competitorReferenceCandidates(source, index, line));
      candidates.room_concept_candidates.push(...roomConceptCandidates(source, index, line));
    });
    candidates.market_distribution_candidates.push(...marketDistributionCandidates(source));
  }

  for (const key of Object.keys(candidates)) {
    candidates[key] = collapseCandidates(candidates[key]);
  }

  return candidates;
}

function collapseCandidates(items) {
  const byKey = new Map();
  for (const item of items) {
    const value = item.observed_value || item.historical_ctrip_price_reference || '';
    const name = item.competitor_name_candidate || '';
    const key = `${item.candidate_type}:${name}:${value}`;
    if (!byKey.has(key)) {
      byKey.set(key, {
        ...item,
        supporting_source_refs: [item.source_ref],
        supporting_source_count: 1,
      });
      continue;
    }
    const current = byKey.get(key);
    if (!current.supporting_source_refs.includes(item.source_ref)) {
      current.supporting_source_refs.push(item.source_ref);
      current.supporting_source_count = current.supporting_source_refs.length;
    }
  }

  return Array.from(byKey.values());
}

function buildPayload(options) {
  const sources = readAllowlistedSources(options.root);
  const candidates = collectCandidates(sources);
  const candidateCount = Object.values(candidates).reduce((sum, items) => sum + items.length, 0);
  const missingSources = sources.filter((source) => !source.exists);

  return {
    status: candidateCount > 0 ? 'passed' : 'blocked_by_no_external_input_candidates',
    scope: {
      business_date: options.date,
      platform: 'ctrip',
      enabled_channels: ['ctrip'],
      hotel_id: options.hotelId === '' ? null : Number(options.hotelId),
      scope_source: options.scopeSource,
      bundle_dir: options.bundleDir || null,
      bundle_manifest_path: options.bundleManifestPath || null,
      source_scope: 'external_project_materials_for_ctrip_operator_review',
      ctrip_ota_channel_scope_preserved: true,
      meituan_scope_included: false,
    },
    source_policy: 'read_allowlisted_external_project_materials_candidates_only_no_db_no_ota_write',
    source_root: path.resolve(options.root),
    allowlisted_files: sources.map((source) => ({
      relative_path: source.relative_path,
      exists: source.exists,
      line_count: source.lines.length,
    })),
    missing_allowlisted_files: missingSources.map((source) => source.relative_path),
    raw_rows_exposed: false,
    database_written: false,
    auto_write_ota: false,
    importable_value: false,
    importable_without_operator_review: false,
    operator_confirmation_required: true,
    summary: {
      candidate_count: candidateCount,
      room_count_candidate_count: candidates.room_count_candidates.length,
      price_assumption_candidate_count: candidates.price_assumption_candidates.length,
      competitor_reference_candidate_count: candidates.competitor_reference_candidates.length,
      room_concept_candidate_count: candidates.room_concept_candidates.length,
      market_distribution_candidate_count: candidates.market_distribution_candidates.length,
      current_required_real_inputs_before_execute: [
        'room_types_enabled',
        'floor_price_or_min_rate_guard',
        'competitor_price_samples',
      ],
      explicit_non_goals: [
        'does_not_create_room_types',
        'does_not_create_price_guards',
        'does_not_create_competitor_price_samples',
        'does_not_write_ota_prices',
      ],
    },
    candidates,
  };
}

function markdownCell(value) {
  return String(value ?? '').replaceAll('|', '\\|').replace(/\r?\n/g, ' ');
}

function toMarkdown(payload) {
  const lines = [];
  lines.push('# Ctrip External Input Candidates');
  lines.push('');
  lines.push(`- status: \`${payload.status}\``);
  lines.push(`- business_date: \`${payload.scope.business_date}\``);
  lines.push(`- hotel_id: \`${payload.scope.hotel_id ?? 'unknown'}\``);
  lines.push(`- scope_source: \`${payload.scope.scope_source}\``);
  lines.push(`- source_scope: \`${payload.scope.source_scope}\``);
  lines.push(`- source_policy: \`${payload.source_policy}\``);
  lines.push('- database_written: `false`');
  lines.push('- auto_write_ota: `false`');
  lines.push('- importable_value: `false`');
  lines.push('- operator_confirmation_required: `true`');
  lines.push('');
  lines.push('## Summary');
  lines.push('');
  lines.push(`- candidate_count: \`${payload.summary.candidate_count}\``);
  lines.push(`- room_count_candidate_count: \`${payload.summary.room_count_candidate_count}\``);
  lines.push(`- price_assumption_candidate_count: \`${payload.summary.price_assumption_candidate_count}\``);
  lines.push(`- competitor_reference_candidate_count: \`${payload.summary.competitor_reference_candidate_count}\``);
  lines.push(`- room_concept_candidate_count: \`${payload.summary.room_concept_candidate_count}\``);
  lines.push(`- market_distribution_candidate_count: \`${payload.summary.market_distribution_candidate_count}\``);
  lines.push('- current_required_real_inputs_before_execute: `room_types_enabled; floor_price_or_min_rate_guard; competitor_price_samples`');
  lines.push('');
  lines.push('## Candidate Rows');
  lines.push('');
  lines.push('| group | value | source_note | operator_action | importable |');
  lines.push('|---|---|---|---|---|');
  for (const [group, items] of Object.entries(payload.candidates)) {
    for (const item of items) {
      const value = item.observed_value || item.historical_ctrip_price_reference || '';
      const name = item.competitor_name_candidate ? `${item.competitor_name_candidate} ${value}` : value;
      lines.push(`| ${markdownCell(group)} | ${markdownCell(name)} | \`${markdownCell(item.source_note)}\` | ${markdownCell(item.operator_action)} | false |`);
    }
  }
  lines.push('');
  lines.push('## Stop Conditions');
  lines.push('');
  lines.push('- Do not copy these candidates directly into `pricing-input-fillable.json`.');
  lines.push('- Room count candidates are not Ctrip room type mappings.');
  lines.push('- 350/450 price assumptions are not floor/protection prices.');
  lines.push('- Historical competitor references are not current recent-7-day named Ctrip competitor samples.');
  lines.push('- Historical room concept candidates are not current Ctrip room type mappings.');
  lines.push('- Historical market price distributions are not current named Ctrip competitor price samples.');
  lines.push('- This report never writes database rows or OTA prices.');

  return `${lines.join('\n')}\n`;
}

function csvEscape(value) {
  const text = String(value ?? '');
  if (/[",\r\n]/.test(text)) {
    return `"${text.replaceAll('"', '""')}"`;
  }
  return text;
}

function toCsv(payload) {
  const rows = [[
    'candidate_group',
    'candidate_status',
    'candidate_type',
    'value',
    'source_note',
    'source_excerpt',
    'operator_action',
    'importable_value',
    'auto_write_ota',
  ]];
  for (const [group, items] of Object.entries(payload.candidates)) {
    for (const item of items) {
      rows.push([
        group,
        item.candidate_status,
        item.candidate_type,
        item.observed_value || item.historical_ctrip_price_reference || '',
        item.source_note,
        item.source_excerpt,
        item.operator_action,
        'false',
        'false',
      ]);
    }
  }
  return rows.map((row) => row.map(csvEscape).join(',')).join('\n') + '\n';
}

try {
  const options = parseArgs(process.argv);
  const payload = buildPayload(options);
  if (options.format === 'markdown') {
    process.stdout.write(toMarkdown(payload));
  } else if (options.format === 'csv') {
    process.stdout.write(toCsv(payload));
  } else {
    process.stdout.write(JSON.stringify(payload, null, 2) + '\n');
  }
} catch (error) {
  process.stdout.write(JSON.stringify({
    status: 'failed',
    error: error instanceof Error ? error.message : String(error),
    database_written: false,
    auto_write_ota: false,
  }, null, 2) + '\n');
  process.exit(1);
}
