import { readFileSync } from 'node:fs';

export const SOURCE_FIELD_MAPPINGS = {
  ctrip_business: {
    label: 'Ctrip business report',
    source: 'ctrip',
    listPaths: ['data.hotelList', 'data', 'hotelList', 'Response.hotelList'],
    requiredFields: ['hotel_id', 'hotel_name', 'amount', 'quantity', 'book_order_num'],
    fields: {
      hotel_id: ['hotelId', 'hotel_id', 'HotelId', 'hotelID'],
      hotel_name: ['hotelName', 'hotel_name', 'HotelName', 'name'],
      data_date: ['dataDate', 'date', 'data_date', 'statDate', 'stat_date', 'bizDate', 'businessDate', 'reportDate'],
      amount: ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount'],
      quantity: ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity'],
      book_order_num: ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count'],
      comment_score: ['commentScore', 'comment_score', 'score', 'avgScore'],
      qunar_comment_score: ['qunarCommentScore', 'qunar_comment_score', 'qunarScore'],
      conversion_rate: ['convertionRate', 'convertion_rate', 'conversionRate'],
      detail_visitors: ['totalDetailNum', 'total_detail_num', 'detailVisitors', 'visitorCount', 'uv'],
    },
  },
  meituan_rank: {
    label: 'Meituan peer rank',
    source: 'meituan',
    listPaths: ['data.peerRankData.roundRanks', 'data.roundrank', 'data.rankList', 'data.list', 'data', 'list', 'roundrank'],
    requiredFields: ['hotel_id', 'hotel_name', 'data_value'],
    fields: {
      hotel_id: ['poiId', 'poi_id', 'shopId', 'shop_id', 'hotelId'],
      hotel_name: ['poiName', 'poi_name', 'shopName', 'shop_name', 'hotelName', 'name'],
      data_date: ['date', 'dataDate', 'statDate', 'stat_date'],
      data_value: ['dataValue', 'data_value', 'monthRoomNights', 'month_room_nights'],
      rank_type: ['rankType', 'rank_type'],
      dimension: ['_dimName', 'dimension', 'dimName'],
      metric_name: ['_aiMetricName', 'aiMetricName'],
    },
  },
};

export function normalizeSourceKey(sourceKey) {
  const normalized = String(sourceKey || '').trim().toLowerCase();
  if (normalized === 'meituan_business') {
    return 'meituan_rank';
  }
  return normalized;
}

const FORMULAS = {
  ADR: {
    storedKeys: ['adr', 'ADR', 'average_daily_rate', 'avg_room_price'],
    numeratorKeys: ['amount', 'room_revenue', 'revenue'],
    denominatorKeys: ['quantity', 'room_nights', 'rooms'],
    tolerance: 0.01,
    min: 0,
    warnMax: 5000,
  },
  ARI: {
    storedKeys: ['ari', 'ARI', 'average_rate_index'],
    numeratorKeys: ['hotel_adr', 'own_adr', 'adr', 'ADR'],
    denominatorKeys: ['comp_set_adr', 'market_adr', 'competitor_adr', 'competitor_avg_adr'],
    tolerance: 0.1,
    min: 0,
    warnMax: 500,
  },
  SCI: {
    storedKeys: ['sci', 'SCI', 'sales_conversion_index'],
    numeratorKeys: ['conversion_rate', 'convertion_rate', 'hotel_conversion_rate', 'order_conversion_rate'],
    denominatorKeys: ['comp_set_conversion_rate', 'market_conversion_rate', 'competitor_conversion_rate'],
    tolerance: 0.1,
    min: 0,
    warnMax: 500,
    normalizeRate: true,
  },
  MPI: {
    storedKeys: ['mpi', 'MPI', 'market_penetration_index'],
    numeratorKeys: ['occupancy_rate', 'occ_rate', 'hotel_occupancy_rate'],
    denominatorKeys: ['comp_set_occupancy_rate', 'market_occupancy_rate', 'competitor_occupancy_rate'],
    tolerance: 0.1,
    min: 0,
    warnMax: 500,
    normalizeRate: true,
  },
};

function makeIssue(level, scope, message, extra = {}) {
  return {
    level,
    scope,
    message,
    ...extra,
  };
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function decodeRawData(rawData) {
  if (rawData === null || rawData === undefined || rawData === '') {
    return { value: {}, error: null };
  }
  if (isObject(rawData)) {
    return { value: rawData, error: null };
  }
  if (typeof rawData !== 'string') {
    return { value: {}, error: 'raw_data must be JSON object or JSON string' };
  }

  try {
    const parsed = JSON.parse(rawData);
    return { value: isObject(parsed) ? parsed : {}, error: isObject(parsed) ? null : 'raw_data JSON is not an object' };
  } catch (error) {
    return { value: {}, error: error.message };
  }
}

function getByPath(source, path) {
  const parts = path.split('.');
  let current = source;
  for (const part of parts) {
    if (Array.isArray(current)) {
      current = current.flatMap((item) => (isObject(item) && item[part] !== undefined ? item[part] : []));
      continue;
    }
    if (!isObject(current) || current[part] === undefined) {
      return undefined;
    }
    current = current[part];
  }
  return current;
}

function firstPresent(source, keys) {
  if (!isObject(source)) {
    return { found: false, key: '', value: undefined };
  }

  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(source, key) && source[key] !== '' && source[key] !== null && source[key] !== undefined) {
      return { found: true, key, value: source[key] };
    }
  }

  return { found: false, key: '', value: undefined };
}

function firstNumberFrom(row, raw, keys) {
  const direct = firstPresent(row, keys);
  if (direct.found) {
    const number = toNumber(direct.value);
    if (number !== null) {
      return { value: number, key: direct.key };
    }
  }

  const rawValue = firstPresent(raw, keys);
  if (rawValue.found) {
    const number = toNumber(rawValue.value);
    if (number !== null) {
      return { value: number, key: `raw_data.${rawValue.key}` };
    }
  }

  return { value: null, key: '' };
}

function toNumber(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'string') {
    const normalized = value.trim().replace(/,/g, '').replace(/%$/, '');
    if (normalized === '') {
      return null;
    }
    const number = Number(normalized);
    return Number.isFinite(number) ? number : null;
  }
  return null;
}

function normalizeRate(value) {
  if (value === null) {
    return null;
  }
  if (value > 1 && value <= 100) {
    return value / 100;
  }
  return value;
}

function round(value, decimals = 2) {
  const scale = 10 ** decimals;
  return Math.round((value + Number.EPSILON) * scale) / scale;
}

function rowLabel(row, index) {
  return row.id ?? row.hotel_id ?? row.hotel_name ?? `row_${index + 1}`;
}

function normalizeRows(rows) {
  return rows.map((row) => (isObject(row) ? row : {}));
}

function validateStorageFields(row, raw, index, errors, warnings) {
  const label = rowLabel(row, index);
  for (const field of ['source', 'hotel_id', 'hotel_name', 'data_date']) {
    if (row[field] === null || row[field] === undefined || row[field] === '') {
      const target = field === 'hotel_id' || field === 'source' ? errors : warnings;
      target.push(makeIssue(target === errors ? 'error' : 'warning', 'row', `${field} is missing`, { row: label, field }));
    }
  }

  for (const field of ['amount', 'quantity', 'book_order_num', 'data_value']) {
    if (row[field] === null || row[field] === undefined || row[field] === '') {
      continue;
    }
    const value = toNumber(row[field]);
    if (value === null) {
      errors.push(makeIssue('error', 'row', `${field} must be numeric`, { row: label, field, actual: row[field] }));
    } else if (value < 0) {
      errors.push(makeIssue('error', 'row', `${field} must not be negative`, { row: label, field, actual: value }));
    }
  }

  const source = String(row.source ?? '').toLowerCase();
  const mappingKey = source === 'ctrip' ? 'ctrip_business' : source === 'meituan' ? 'meituan_rank' : '';
  if (!mappingKey || !Object.keys(raw).length) {
    return;
  }

  const mapping = SOURCE_FIELD_MAPPINGS[mappingKey];
  for (const field of mapping.requiredFields) {
    if (!firstPresent(raw, mapping.fields[field] ?? []).found) {
      warnings.push(makeIssue('warning', 'source_mapping', `${mapping.label} raw_data misses ${field} aliases`, {
        row: label,
        field,
        aliases: mapping.fields[field] ?? [],
      }));
    }
  }
}

function validateFormula(row, raw, index, metric, formula, errors, warnings) {
  const label = rowLabel(row, index);
  const stored = firstNumberFrom(row, raw, formula.storedKeys);
  let numerator = firstNumberFrom(row, raw, formula.numeratorKeys);
  const denominator = firstNumberFrom(row, raw, formula.denominatorKeys);

  if (metric === 'ARI' && numerator.value === null) {
    const adr = calculateAdr(row, raw);
    if (adr !== null) {
      numerator = { value: adr, key: 'derived.ADR' };
    }
  }

  if (stored.value !== null && stored.value < formula.min) {
    errors.push(makeIssue('error', 'formula', `${metric} must not be negative`, { row: label, metric, actual: stored.value }));
  }
  if (stored.value !== null && stored.value > formula.warnMax) {
    warnings.push(makeIssue('warning', 'formula', `${metric} is outside normal range`, { row: label, metric, actual: stored.value, max: formula.warnMax }));
  }

  if (numerator.value === null || denominator.value === null) {
    if (stored.value !== null) {
      warnings.push(makeIssue('warning', 'formula', `${metric} stored value has incomplete formula inputs`, {
        row: label,
        metric,
        stored: stored.value,
        missing: [
          numerator.value === null ? 'numerator' : '',
          denominator.value === null ? 'denominator' : '',
        ].filter(Boolean),
      }));
    }
    return null;
  }

  let numeratorValue = numerator.value;
  let denominatorValue = denominator.value;
  if (formula.normalizeRate) {
    numeratorValue = normalizeRate(numeratorValue);
    denominatorValue = normalizeRate(denominatorValue);
  }

  if (denominatorValue === 0) {
    const target = numeratorValue > 0 || stored.value !== null ? warnings : [];
    target.push?.(makeIssue('warning', 'formula', `${metric} denominator is zero`, { row: label, metric }));
    return null;
  }

  const expected = metric === 'ADR'
    ? numeratorValue / denominatorValue
    : (numeratorValue / denominatorValue) * 100;
  const roundedExpected = round(expected, metric === 'ADR' ? 2 : 2);

  if (roundedExpected < formula.min) {
    errors.push(makeIssue('error', 'formula', `${metric} computed value is negative`, { row: label, metric, expected: roundedExpected }));
  } else if (roundedExpected > formula.warnMax) {
    warnings.push(makeIssue('warning', 'formula', `${metric} computed value is outside normal range`, {
      row: label,
      metric,
      expected: roundedExpected,
      max: formula.warnMax,
      numerator: numerator.key,
      denominator: denominator.key,
    }));
  }

  if (stored.value !== null && Math.abs(stored.value - roundedExpected) > formula.tolerance) {
    errors.push(makeIssue('error', 'formula', `${metric} mismatch`, {
      row: label,
      metric,
      expected: roundedExpected,
      actual: stored.value,
      tolerance: formula.tolerance,
      numerator: numerator.key,
      denominator: denominator.key,
    }));
  }

  return roundedExpected;
}

function calculateAdr(row, raw) {
  const numerator = firstNumberFrom(row, raw, FORMULAS.ADR.numeratorKeys);
  const denominator = firstNumberFrom(row, raw, FORMULAS.ADR.denominatorKeys);
  if (numerator.value === null || denominator.value === null || denominator.value === 0) {
    return null;
  }
  return round(numerator.value / denominator.value, 2);
}

export function validateMetricFormulas(inputRows) {
  const rows = normalizeRows(Array.isArray(inputRows) ? inputRows : []);
  const errors = [];
  const warnings = [];
  const recordResults = [];
  const metricCounts = {
    ADR: 0,
    ARI: 0,
    SCI: 0,
    MPI: 0,
  };

  rows.forEach((row, index) => {
    const rowErrors = [];
    const rowWarnings = [];
    const calculatedMetrics = {};
    const rawResult = decodeRawData(row.raw_data);
    if (rawResult.error) {
      rowErrors.push(makeIssue('error', 'row', `raw_data JSON parse failed: ${rawResult.error}`, { row: rowLabel(row, index), field: 'raw_data' }));
    }

    validateStorageFields(row, rawResult.value, index, rowErrors, rowWarnings);

    const amount = firstNumberFrom(row, rawResult.value, FORMULAS.ADR.numeratorKeys);
    const quantity = firstNumberFrom(row, rawResult.value, FORMULAS.ADR.denominatorKeys);
    if (amount.value !== null && amount.value > 0 && quantity.value === 0) {
      rowWarnings.push(makeIssue('warning', 'formula', 'ADR amount exists but room nights are zero', { row: rowLabel(row, index), metric: 'ADR' }));
    }

    for (const [metric, formula] of Object.entries(FORMULAS)) {
      const calculated = validateFormula(row, rawResult.value, index, metric, formula, rowErrors, rowWarnings);
      if (calculated !== null) {
        metricCounts[metric]++;
        calculatedMetrics[metric] = calculated;
      }
    }

    errors.push(...rowErrors);
    warnings.push(...rowWarnings);
    recordResults.push({
      index: index + 1,
      row: rowLabel(row, index),
      source: row.source ?? '',
      hotel_id: row.hotel_id ?? '',
      hotel_name: row.hotel_name ?? '',
      data_date: row.data_date ?? '',
      status: rowErrors.length > 0 ? 'fail' : 'pass',
      errors: rowErrors,
      warnings: rowWarnings,
      calculated_metrics: calculatedMetrics,
    });
  });

  const failedRecords = recordResults.filter((record) => record.status === 'fail').length;
  const warningRecords = recordResults.filter((record) => record.status === 'pass' && record.warnings.length > 0).length;

  return {
    checkedRows: rows.length,
    summary: {
      checked_rows: rows.length,
      passed_records: rows.length - failedRecords,
      failed_records: failedRecords,
      warning_records: warningRecords,
    },
    record_results: recordResults,
    errors,
    warnings,
    details: {
      metric_counts: metricCounts,
    },
  };
}

export function validateSourceMappingCompleteness(mappings = SOURCE_FIELD_MAPPINGS) {
  const errors = [];
  const warnings = [];
  const details = {};

  for (const [key, mapping] of Object.entries(mappings)) {
    const mappingDetails = {
      required_fields: mapping.requiredFields ?? [],
      field_count: Object.keys(mapping.fields ?? {}).length,
      missing_required_fields: [],
    };

    if (!mapping.source) {
      errors.push(makeIssue('error', 'source_mapping', `${key} source is missing`, { mapping: key }));
    }
    if (!Array.isArray(mapping.listPaths) || mapping.listPaths.length === 0) {
      errors.push(makeIssue('error', 'source_mapping', `${key} list paths are missing`, { mapping: key }));
    }
    if (!isObject(mapping.fields)) {
      errors.push(makeIssue('error', 'source_mapping', `${key} fields are missing`, { mapping: key }));
      details[key] = mappingDetails;
      continue;
    }

    for (const field of mapping.requiredFields ?? []) {
      const aliases = mapping.fields[field];
      if (!Array.isArray(aliases) || aliases.length === 0) {
        mappingDetails.missing_required_fields.push(field);
        errors.push(makeIssue('error', 'source_mapping', `${key}.${field} aliases are missing`, { mapping: key, field }));
      }
    }

    for (const [field, aliases] of Object.entries(mapping.fields)) {
      if (!Array.isArray(aliases) || aliases.some((alias) => typeof alias !== 'string' || alias.trim() === '')) {
        errors.push(makeIssue('error', 'source_mapping', `${key}.${field} aliases must be non-empty strings`, { mapping: key, field }));
      }
    }

    if (!mapping.fields.data_date) {
      warnings.push(makeIssue('warning', 'source_mapping', `${key} has no data_date aliases; request date fallback is required`, { mapping: key }));
    }

    details[key] = mappingDetails;
  }

  return { errors, warnings, details };
}

function extractCtripRows(payload, mapping) {
  for (const path of mapping.listPaths) {
    const value = getByPath(payload, path);
    if (Array.isArray(value) && value.every((item) => isObject(item))) {
      return value;
    }
  }
  return [];
}

function extractMeituanRows(payload, mapping) {
  const peerRankData = getByPath(payload, 'data.peerRankData');
  if (Array.isArray(peerRankData)) {
    const rows = [];
    for (const group of peerRankData) {
      if (!isObject(group) || !Array.isArray(group.roundRanks)) {
        continue;
      }
      for (const item of group.roundRanks) {
        if (isObject(item)) {
          rows.push({
            ...item,
            _dimName: item._dimName ?? group.dimName ?? '',
            _aiMetricName: item._aiMetricName ?? group.aiMetricName ?? '',
          });
        }
      }
    }
    if (rows.length > 0) {
      return rows;
    }
  }

  for (const path of mapping.listPaths.filter((path) => path !== 'data.peerRankData.roundRanks')) {
    const value = getByPath(payload, path);
    if (Array.isArray(value) && value.every((item) => isObject(item))) {
      return value;
    }
  }
  return [];
}

function extractPayloadRows(payload, sourceKey) {
  const mapping = SOURCE_FIELD_MAPPINGS[sourceKey];
  if (!mapping) {
    return [];
  }
  if (sourceKey === 'meituan_rank') {
    return extractMeituanRows(payload, mapping);
  }
  return extractCtripRows(payload, mapping);
}

export function validateRawPayloadMapping(payload, sourceKey) {
  const errors = [];
  const warnings = [];
  const normalizedSourceKey = normalizeSourceKey(sourceKey);
  const mapping = SOURCE_FIELD_MAPPINGS[normalizedSourceKey];

  if (!mapping) {
    return {
      errors: [makeIssue('error', 'source_mapping', `unknown source mapping: ${sourceKey}`, { mapping: sourceKey })],
      warnings,
      details: { source: normalizedSourceKey || sourceKey, requested_source: sourceKey, rows: 0 },
    };
  }

  const rows = extractPayloadRows(payload, normalizedSourceKey);
  if (rows.length === 0) {
    errors.push(makeIssue('error', 'source_mapping', `${mapping.label} payload has no mapped rows`, { mapping: normalizedSourceKey }));
  }

  rows.forEach((row, index) => {
    for (const field of mapping.requiredFields) {
      if (!firstPresent(row, mapping.fields[field] ?? []).found) {
        errors.push(makeIssue('error', 'source_mapping', `${mapping.label} payload misses ${field}`, {
          mapping: normalizedSourceKey,
          row: index + 1,
          field,
          aliases: mapping.fields[field] ?? [],
        }));
      }
    }
    if (mapping.fields.data_date && !firstPresent(row, mapping.fields.data_date).found) {
      warnings.push(makeIssue('warning', 'source_mapping', `${mapping.label} payload misses data_date; request date fallback must be used`, {
        mapping: normalizedSourceKey,
        row: index + 1,
        aliases: mapping.fields.data_date,
      }));
    }
  });

  return {
    errors,
    warnings,
    details: {
      source: normalizedSourceKey,
      requested_source: sourceKey,
      rows: rows.length,
    },
  };
}

export function validateSourceParserContracts(paths) {
  const errors = [];
  const warnings = [];
  const details = {};
  const controllerPath = paths.controllerPath;
  const commandPath = paths.commandPath;

  const requiredControllerTokens = [
    'extractCtripBusinessDataList',
    'parseAndSaveData',
    'parseAndSaveMeituanData',
    'hotelId',
    'hotelName',
    'totalAmount',
    'roomNights',
    'orderCount',
    'convertionRate',
    'poiId',
    'poiName',
    'dataValue',
    'rankType',
    'dimension',
  ];

  try {
    const controller = readFileSync(controllerPath, 'utf8');
    const missing = requiredControllerTokens.filter((token) => !controller.includes(token));
    details.controller = { path: controllerPath, missing_tokens: missing };
    for (const token of missing) {
      errors.push(makeIssue('error', 'parser_contract', `OnlineData parser token missing: ${token}`, { file: controllerPath, token }));
    }
  } catch (error) {
    errors.push(makeIssue('error', 'parser_contract', `cannot read ${controllerPath}: ${error.message}`, { file: controllerPath }));
  }

  if (commandPath) {
    const recommendedCommandTokens = ['parseAndSaveData', 'hotelId', 'totalAmount', 'roomNights', 'bookOrderNum', 'raw_data'];
    try {
      const command = readFileSync(commandPath, 'utf8');
      const missing = recommendedCommandTokens.filter((token) => !command.includes(token));
      details.command = { path: commandPath, missing_tokens: missing };
      for (const token of missing) {
        warnings.push(makeIssue('warning', 'parser_contract', `AutoFetch parser token missing: ${token}`, { file: commandPath, token }));
      }
    } catch (error) {
      warnings.push(makeIssue('warning', 'parser_contract', `cannot read ${commandPath}: ${error.message}`, { file: commandPath }));
    }
  }

  return { errors, warnings, details };
}

export function loadRowsFromJson(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (!isObject(value)) {
    return [];
  }

  for (const key of ['rows', 'items', 'list', 'records']) {
    if (Array.isArray(value[key])) {
      return value[key];
    }
  }

  if (Array.isArray(value.data)) {
    return value.data;
  }
  if (isObject(value.data)) {
    for (const key of ['rows', 'items', 'list', 'records', 'hotelList']) {
      if (Array.isArray(value.data[key])) {
        return value.data[key];
      }
    }
  }

  return [];
}

function escapeTable(value) {
  return String(value ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');
}

function issueTable(title, issues) {
  if (issues.length === 0) {
    return `## ${title}\n\n无`;
  }

  const rows = issues.slice(0, 20).map((issue) => {
    const row = issue.row ?? '';
    const metric = issue.metric ?? issue.field ?? issue.token ?? issue.mapping ?? '';
    return `| ${escapeTable(issue.level)} | ${escapeTable(row)} | ${escapeTable(metric)} | ${escapeTable(issue.message)} |`;
  });
  const suffix = issues.length > 20 ? `\n\n> 仅展示前 20 条，共 ${issues.length} 条。` : '';
  return `## ${title}\n\n| 级别 | 行/对象 | 指标/字段 | 问题 |\n|---|---:|---|---|\n${rows.join('\n')}${suffix}`;
}

function legacyFormatValidationReport(result, options = {}) {
  const title = options.title ?? 'OTA data validation';
  const lines = [
    `# ${title}`,
    '',
    `- checked_rows: ${result.checkedRows ?? 0}`,
    `- errors: ${result.errors?.length ?? 0}`,
    `- warnings: ${result.warnings?.length ?? 0}`,
  ];

  if (result.details?.metric_summary?.metric_counts) {
    const counts = result.details.metric_summary.metric_counts;
    lines.push(`- metric_checks: ADR=${counts.ADR ?? 0}, ARI=${counts.ARI ?? 0}, SCI=${counts.SCI ?? 0}, MPI=${counts.MPI ?? 0}`);
  }

  lines.push('', issueTable('Errors', result.errors ?? []));
  lines.push('', issueTable('Warnings', result.warnings ?? []));

  if (result.details?.parser_contracts) {
    lines.push('', '## Source Parser Contracts', '');
    const controllerMissing = result.details.parser_contracts.controller?.missing_tokens ?? [];
    const commandMissing = result.details.parser_contracts.command?.missing_tokens ?? [];
    lines.push(`- OnlineData.php missing_tokens: ${controllerMissing.length ? controllerMissing.join(', ') : '无'}`);
    lines.push(`- AutoFetchOnlineData.php missing_tokens: ${commandMissing.length ? commandMissing.join(', ') : '无'}`);
  }

  return lines.join('\n');
}

function validationIssueTable(title, issues, emptyText = '无') {
  if (issues.length === 0) {
    return `## ${title}\n\n${emptyText}`;
  }

  const rows = issues.slice(0, 20).map((issue) => {
    const row = issue.row ?? '';
    const metric = issue.metric ?? issue.field ?? issue.token ?? issue.mapping ?? '';
    return `| ${escapeTable(issue.level)} | ${escapeTable(row)} | ${escapeTable(metric)} | ${escapeTable(issue.message)} |`;
  });
  const suffix = issues.length > 20 ? `\n\n> 仅展示前 20 条，共 ${issues.length} 条。` : '';
  return `## ${title}\n\n| 级别 | 行/对象 | 指标/字段 | 问题 |\n|---|---:|---|---|\n${rows.join('\n')}${suffix}`;
}

function abnormalRecordTable(records) {
  const abnormal = records.filter((record) => record.status === 'fail' || record.warnings.length > 0);
  if (abnormal.length === 0) {
    return '## 异常记录明细\n\n无';
  }

  const rows = abnormal.slice(0, 50).map((record) => {
    const issueText = [...record.errors, ...record.warnings]
      .map((issue) => `${issue.metric ?? issue.field ?? issue.scope}: ${issue.message}`)
      .join('; ');
    return `| ${escapeTable(record.index)} | ${escapeTable(record.status)} | ${escapeTable(record.hotel_id)} | ${escapeTable(record.hotel_name)} | ${escapeTable(issueText)} |`;
  });
  const suffix = abnormal.length > 50 ? `\n\n> 仅展示前 50 条，共 ${abnormal.length} 条。` : '';
  return `## 异常记录明细\n\n| 序号 | 状态 | 酒店ID | 酒店名称 | 异常 |\n|---:|---|---|---|---|\n${rows.join('\n')}${suffix}`;
}

export function formatValidationReport(result, options = {}) {
  const title = options.title ?? 'OTA data validation';
  const summary = result.summary ?? {};
  const lines = [
    `# ${title}`,
    '',
    '## 校验汇总',
    '',
    `- 校验总数: ${summary.checked_rows ?? result.checkedRows ?? 0}`,
    `- 校验通过: ${summary.passed_records ?? 0}`,
    `- 校验失败: ${summary.failed_records ?? 0}`,
    `- 仅告警记录: ${summary.warning_records ?? 0}`,
    `- 错误数: ${summary.error_count ?? result.errors?.length ?? 0}`,
    `- 告警数: ${summary.warning_count ?? result.warnings?.length ?? 0}`,
  ];

  if (result.details?.metric_summary?.metric_counts) {
    const counts = result.details.metric_summary.metric_counts;
    lines.push(`- 指标校验: ADR=${counts.ADR ?? 0}, ARI=${counts.ARI ?? 0}, SCI=${counts.SCI ?? 0}, MPI=${counts.MPI ?? 0}`);
  }

  lines.push('', abnormalRecordTable(result.record_results ?? []));
  lines.push('', validationIssueTable('Errors', result.errors ?? []));
  lines.push('', validationIssueTable('Warnings', result.warnings ?? []));

  if (result.details?.parser_contracts) {
    lines.push('', '## Source Parser Contracts', '');
    const controllerMissing = result.details.parser_contracts.controller?.missing_tokens ?? [];
    const commandMissing = result.details.parser_contracts.command?.missing_tokens ?? [];
    lines.push(`- OnlineData.php missing_tokens: ${controllerMissing.length ? controllerMissing.join(', ') : '无'}`);
    lines.push(`- AutoFetchOnlineData.php missing_tokens: ${commandMissing.length ? commandMissing.join(', ') : '无'}`);
  }

  return lines.join('\n');
}
