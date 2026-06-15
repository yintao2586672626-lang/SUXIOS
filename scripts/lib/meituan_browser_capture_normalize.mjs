const CARD_METRIC_MAP = new Map([
  ['EXPOSE_PV_CNT', { fields: ['listExposure', 'list_exposure'], label: 'list_exposure' }],
  ['INTENTION_UV', { fields: ['detailExposure', 'detail_exposure'], label: 'detail_exposure' }],
  ['PAY_ORDER_CNT_UV', { fields: ['flowRate', 'flow_rate'], label: 'flow_rate' }],
  ['PAY_ORDER_CNT', { fields: ['orderSubmitNum', 'order_submit_num'], label: 'order_submit_num' }],
]);

const REQUIRED_TRAFFIC_FIELD_GROUPS = [
  ['listExposure', 'list_exposure', 'exposure_count', 'exposureCount'],
  ['detailExposure', 'detail_exposure', 'page_views', 'pageViews', 'unique_visitors', 'uniqueVisitors'],
  ['flowRate', 'flow_rate', 'conversion_rate', 'conversionRate', 'cvr'],
  ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'click_count', 'clickCount', 'clicks'],
  ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'orderCount', 'order_count', 'orders'],
];

export function normalizeMeituanTrafficCardRows(value, options = {}) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return [];
  }

  const candidates = [
    { path: ['data', 'data', 'cards'], metaPath: ['data', 'data'] },
    { path: ['data', 'cards'], metaPath: ['data'] },
    { path: ['cards'], metaPath: [] },
  ];
  for (const candidate of candidates) {
    const cards = readPath(value, candidate.path);
    if (!Array.isArray(cards) || !cards.some(card => card && typeof card === 'object')) {
      continue;
    }
    const meta = candidate.metaPath.length > 0 ? readPath(value, candidate.metaPath) : value;
    const row = buildCardMetricRow(cards, {
      ...options,
      sourcePath: joinSourcePath(options.sourcePath || '', candidate.path),
      meta: meta && typeof meta === 'object' && !Array.isArray(meta) ? meta : {},
    });
    return row ? [row] : [];
  }

  return [];
}

export function isImportableMeituanTrafficRow(row) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) {
    return false;
  }
  return REQUIRED_TRAFFIC_FIELD_GROUPS.every(keys => keys.some(key => hasTrafficMetricValue(row[key])));
}

function buildCardMetricRow(cards, options = {}) {
  const row = {
    _capture_source: 'xhr:traffic:metric_cards',
    _source_path: options.sourcePath || 'data.cards',
    _meituan_card_metric_sources: {},
    _meituan_card_metric_missing: [],
    _meituan_cards: cards
      .filter(card => card && typeof card === 'object' && !Array.isArray(card))
      .map(card => ({
        id: String(card.id || card.key || card.metricId || '').trim(),
        title: String(card.title || card.name || '').trim(),
        value_state: hasTrafficMetricValue(card.value) ? 'present' : 'missing',
      })),
  };

  for (const card of cards) {
    if (!card || typeof card !== 'object' || Array.isArray(card)) {
      continue;
    }
    const id = String(card.id || card.key || card.metricId || '').trim();
    const config = CARD_METRIC_MAP.get(id);
    if (!config) {
      continue;
    }
    const numericValue = trafficMetricNumber(card.value);
    if (numericValue === null) {
      row._meituan_card_metric_missing.push({
        card_id: id,
        metric_key: config.label,
        value_state: normalizeMissingValueState(card.value),
      });
      continue;
    }
    for (const field of config.fields) {
      row[field] = numericValue;
    }
    row._meituan_card_metric_sources[config.label] = {
      card_id: id,
      source_path: `${row._source_path}.${cards.indexOf(card)}.value`,
    };
  }

  const dataDate = extractDateFromMeta(options.meta || {});
  if (dataDate) {
    row.dataDate = dataDate;
    row.date_source = `${row._source_path}.rtDataUpdateTime`;
  } else if (options.requestDateEvidence?.date) {
    row.dataDate = options.requestDateEvidence.date;
    row.date_source = options.requestDateEvidence.date_source || 'request';
  } else if (options.defaultDataDate) {
    row.dataDate = options.defaultDataDate;
    row.date_source = 'capture_context.default_data_date';
  }

  if (hasTrafficMetricValue(row.orderSubmitNum) && !hasTrafficMetricValue(row.orderFillingNum)) {
    row.orderFillingNum = row.orderSubmitNum;
    row.order_filling_num = row.order_submit_num;
    row._order_filling_source_policy = 'meituan_metric_cards_no_separate_order_filling_step_pay_order_count_used';
  }

  return row;
}

function hasTrafficMetricValue(value) {
  return trafficMetricNumber(value) !== null;
}

function trafficMetricNumber(value) {
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : null;
  }
  const text = String(value).trim();
  if (text === '' || text === '-' || text === '--') {
    return null;
  }
  if (/数据更新中|暂无|无数据|not\s*available/i.test(text)) {
    return null;
  }
  const multiplier = text.includes('万') ? 10000 : 1;
  const normalized = text.replace(/,/g, '').replace(/%/g, '').replace(/万/g, '').trim();
  const match = normalized.match(/-?\d+(?:\.\d+)?/);
  if (!match) {
    return null;
  }
  const num = Number(match[0]);
  return Number.isFinite(num) ? num * multiplier : null;
}

function normalizeMissingValueState(value) {
  const text = String(value ?? '').trim();
  return text === '' ? 'empty' : text;
}

function extractDateFromMeta(meta) {
  const candidates = [
    meta.rtDataUpdateTime,
    meta.updateTime,
    meta.updatedAt,
    meta.dataDate,
    meta.date,
  ];
  for (const candidate of candidates) {
    const match = String(candidate || '').match(/(\d{4})[/-](\d{1,2})[/-](\d{1,2})/);
    if (match) {
      return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
    }
  }
  return '';
}

function readPath(value, parts) {
  let current = value;
  for (const part of parts) {
    if (!current || typeof current !== 'object' || !(part in current)) {
      return undefined;
    }
    current = current[part];
  }
  return current;
}

function joinSourcePath(prefix, parts) {
  const suffix = parts.map(part => String(part)).join('.');
  return prefix ? `${prefix}.${suffix}` : suffix;
}
