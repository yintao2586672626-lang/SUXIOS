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

export function normalizeMeituanPeerRankRows(value, options = {}) {
  const peerRankData = firstArrayAtPath(value, [
    ['data', 'peerRankData'],
    ['data', 'data', 'peerRankData'],
    ['peerRankData'],
  ]);
  if (peerRankData.length) {
    const rows = [];
    peerRankData.forEach((section, sectionIndex) => {
      if (!section || typeof section !== 'object' || Array.isArray(section)) {
        return;
      }
      const rankRows = asRowList(section.roundRanks || section.roundRank || section.ranks || section.list);
      rankRows.forEach((item, itemIndex) => {
        rows.push(decorateSupplementalRow({
          ...item,
          _dimName: section.dimName || section.dimension || '',
          dimension: section.dimName || section.dimension || item.dimension || '',
          _aiMetricName: section.aiMetricName || section.metricName || '',
          rankType: item.rankType || options.rankType || '',
          rank_type: item.rank_type || item.rankType || options.rankType || '',
        }, 'peer_rank', `data.peerRankData.${sectionIndex}.roundRanks.${itemIndex}`, options));
      });
    });
    return rows;
  }

  const fallbackRows = firstArrayAtPath(value, [
    ['data', 'roundrank'],
    ['data', 'rankList'],
    ['data', 'list'],
    ['roundrank'],
    ['rankList'],
    ['list'],
  ]);
  return fallbackRows.map((item, index) => decorateSupplementalRow({
    ...item,
    rankType: item.rankType || options.rankType || '',
    rank_type: item.rank_type || item.rankType || options.rankType || '',
  }, 'peer_rank', `rank_rows.${index}`, options));
}

export function normalizeMeituanSearchKeywordRows(value, options = {}) {
  const cardRows = [];
  const cards = firstArrayAtPath(value, [
    ['data', 'cards'],
    ['data', 'data', 'cards'],
    ['cards'],
  ]);
  cards.forEach((card, cardIndex) => {
    if (!card || typeof card !== 'object' || Array.isArray(card)) {
      return;
    }
    const items = asRowList(card.itemList || card.items || card.list || card.keywords);
    items.forEach((item, itemIndex) => {
      const keyword = String(item.name || item.keyword || item.searchKeyword || item.searchWord || '').trim();
      cardRows.push(decorateSupplementalRow({
        ...item,
        keyword,
        dimension: keyword,
        keyword_group: card.title || card.name || '',
        data_value: item.value ?? item.dataValue ?? item.heat ?? null,
      }, 'search_keyword', `data.cards.${cardIndex}.itemList.${itemIndex}`, options));
    });
  });
  if (cardRows.length) {
    return cardRows;
  }

  const rows = firstArrayAtPath(value, [
    ['data', 'searchKeywords'],
    ['data', 'searchKeyWords'],
    ['data', 'keywords'],
    ['data', 'list'],
    ['searchKeywords'],
    ['searchKeyWords'],
    ['keywords'],
    ['list'],
  ]);
  return rows.map((item, index) => {
    const keyword = String(item.keyword || item.searchKeyword || item.searchWord || item.name || '').trim();
    return decorateSupplementalRow({
      ...item,
      keyword,
      dimension: keyword || item.dimension || '',
      data_value: item.value ?? item.dataValue ?? item.heat ?? null,
    }, 'search_keyword', `search_keywords.${index}`, options);
  });
}

export function normalizeMeituanTrafficForecastRows(value, options = {}) {
  const details = firstArrayAtPath(value, [
    ['data', 'detail'],
    ['data', 'data', 'detail'],
    ['detail'],
  ]);
  const forecastType = String(options.forecastType || readPath(value, ['data', 'type']) || value?.type || '').trim();
  const rows = details.map((item, index) => decorateSupplementalRow({
    ...item,
    data_type: 'traffic_forecast',
    forecast_type: forecastType,
    dimension: forecastType ? `flow_forecast_${forecastType}` : 'flow_forecast',
    data_value: item.current ?? item.value ?? null,
    peer_avg: item.peerAvg ?? item.peer_avg ?? null,
    dataDate: normalizeDateLike(item.dateTime || item.date || item.dataDate || item.statDate || ''),
  }, 'traffic_forecast', `data.detail.${index}`, options));
  if (rows.length) {
    return rows;
  }

  return [decorateSupplementalRow({
    ...(value && typeof value === 'object' && !Array.isArray(value) ? value : { value }),
    data_type: 'traffic_forecast',
    forecast_type: forecastType,
    dimension: forecastType ? `flow_forecast_${forecastType}` : 'flow_forecast',
  }, 'traffic_forecast', '$', options)];
}

export function normalizeMeituanFlowAnalysisRows(value, options = {}) {
  const analysisType = String(options.analysisType || '').trim() || 'flow_analysis';
  const data = firstObjectAtPath(value, [
    ['data', 'data'],
    ['data'],
    [],
  ]);
  if (analysisType === 'conversion') {
    return [decorateSupplementalRow({
      ...(data || {}),
      data_type: 'traffic_analysis',
      analysis_type: 'conversion_funnel',
      dimension: 'flow_conversion',
      listExposure: numberish(data.exposeCount ?? data.exposureCount ?? data.exposure),
      detailExposure: numberish(data.visitCount ?? data.visitorCount ?? data.uv),
      orderSubmitNum: numberish(data.orderCount ?? data.payOrderCount ?? data.orders),
      orderFillingNum: numberish(data.orderCount ?? data.payOrderCount ?? data.orders),
      flowRate: numberish(data.visitOrderRate ?? data.conversionRate ?? data.orderConversionRate),
      expose_visit_rate: numberish(data.exposeVisitRate),
    }, 'traffic_analysis', 'data', options)];
  }

  const list = firstArrayAtPath(value, [
    ['data', 'list'],
    ['data', 'data', 'list'],
    ['data', 'rows'],
    ['list'],
    ['rows'],
  ]);
  if (list.length) {
    return list.map((item, index) => decorateSupplementalRow({
      ...item,
      data_type: 'traffic_analysis',
      analysis_type: analysisType,
      dimension: item.name || item.dimension || analysisType,
      data_value: item.value ?? item.dataValue ?? null,
      week_over_week: item.weekOverWeek ?? item.wow ?? null,
      peer_rank: item.rank ?? item.peerRank ?? null,
    }, 'traffic_analysis', `data.list.${index}`, options));
  }

  return [decorateSupplementalRow({
    ...(data || {}),
    data_type: 'traffic_analysis',
    analysis_type: analysisType,
    dimension: analysisType,
  }, 'traffic_analysis', 'data', options)];
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

function decorateSupplementalRow(row, dataType, sourcePath, options = {}) {
  const next = {
    ...(row || {}),
    data_type: dataType,
    _capture_source: `xhr:traffic:${dataType}`,
    _source_path: sourcePath || '$',
  };
  if (options.dateRange !== undefined && options.dateRange !== '') {
    next.dateRange = String(options.dateRange);
    next.date_range = String(options.dateRange);
  }
  if (options.rankType && !next.rankType) {
    next.rankType = String(options.rankType);
    next.rank_type = String(options.rankType);
  }
  if (options.forecastType && !next.forecast_type) {
    next.forecast_type = String(options.forecastType);
  }
  if (!hasOwnDate(next)) {
    const date = supplementalDate(next, options);
    if (date) {
      next.dataDate = date;
      next.date_source = supplementalDateSource(options);
    }
  }
  if (String(options.dateRange || '') === '0' && !next.data_period) {
    next.data_period = 'realtime_snapshot';
  }
  return next;
}

function supplementalDate(row, options = {}) {
  const rowDate = normalizeDateLike(row.dataDate || row.data_date || row.date || row.statDate || row.stat_date || row.dateTime || '');
  if (rowDate) {
    return rowDate;
  }
  if (options.requestDateEvidence?.date) {
    return options.requestDateEvidence.date;
  }
  const range = String(options.dateRange || '');
  if (range === '0') {
    return normalizeDateLike(options.capturedAt || new Date().toISOString());
  }
  return normalizeDateLike(options.defaultDataDate || '');
}

function supplementalDateSource(options = {}) {
  if (options.requestDateEvidence?.date_source) {
    return options.requestDateEvidence.date_source;
  }
  return String(options.dateRange || '') === '0' ? 'capture_context.captured_at' : 'capture_context.default_data_date';
}

function hasOwnDate(row) {
  return [row.dataDate, row.data_date, row.date, row.statDate, row.stat_date].some(value => String(value ?? '').trim() !== '');
}

function firstArrayAtPath(value, paths) {
  for (const path of paths) {
    const rows = asRowList(readPath(value, path));
    if (rows.length) {
      return rows;
    }
  }
  return [];
}

function firstObjectAtPath(value, paths) {
  for (const path of paths) {
    const candidate = path.length ? readPath(value, path) : value;
    if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
      return candidate;
    }
  }
  return {};
}

function asRowList(value) {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.filter(item => item && typeof item === 'object' && !Array.isArray(item));
}

function numberish(value) {
  const num = trafficMetricNumber(value);
  return num === null ? undefined : num;
}

function normalizeDateLike(value) {
  const text = String(value || '').trim();
  if (!text) {
    return '';
  }
  let match = text.match(/^(\d{4})(\d{2})(\d{2})$/);
  if (match) {
    return `${match[1]}-${match[2]}-${match[3]}`;
  }
  match = text.match(/(\d{4})[/-](\d{1,2})[/-](\d{1,2})/);
  if (match) {
    return `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}`;
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
