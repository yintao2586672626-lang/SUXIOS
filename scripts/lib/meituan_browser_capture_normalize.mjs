const CARD_METRIC_MAP = new Map([
  ['EXPOSE_PV_CNT', { fields: ['listExposure', 'list_exposure'], label: 'list_exposure' }],
  ['INTENTION_UV', { fields: ['detailExposure', 'detail_exposure'], label: 'detail_exposure' }],
  ['PAY_ORDER_CNT_UV', { fields: ['flowRate', 'flow_rate'], label: 'flow_rate' }],
  ['PAY_ORDER_CNT', { fields: ['orderSubmitNum', 'order_submit_num'], label: 'order_submit_num' }],
]);

const MEITUAN_ORDER_FLOW_ENDPOINT_PATH = '/api/v1/ebooking/peerRank/order/loss/query';

export function buildMeituanOrderFlowReplayUrls(value) {
  try {
    const source = new URL(String(value || '').trim());
    if (source.protocol !== 'https:'
      || source.hostname !== 'eb.meituan.com'
      || source.pathname !== MEITUAN_ORDER_FLOW_ENDPOINT_PATH
      || !source.searchParams.get('startDate')
      || !source.searchParams.get('endDate')) {
      return [];
    }
    return ['0', '1'].map(lossType => {
      const target = new URL(source.toString());
      target.searchParams.set('lossType', lossType);
      return target.toString();
    });
  } catch {
    return [];
  }
}

const CARD_METRIC_ID_ALIASES = [
  {
    aliases: ['EXPOSE_PV_CNT', 'EXPOSE_UV_CNT', 'EXPOSURE_COUNT', 'EXPOSURE_CNT', 'IMPRESSION_CNT', 'LIST_EXPOSURE', 'LIST_EXPOSURE_CNT'],
    config: { fields: ['listExposure', 'list_exposure'], label: 'list_exposure' },
  },
  {
    aliases: ['INTENTION_UV', 'DETAIL_UV', 'DETAIL_PV', 'VISITOR_UV', 'VISIT_UV', 'BROWSE_UV', 'DETAIL_EXPOSURE'],
    config: { fields: ['detailExposure', 'detail_exposure'], label: 'detail_exposure' },
  },
  {
    aliases: ['PAY_ORDER_CNT_UV', 'PAY_CVR', 'ORDER_CVR', 'CONVERSION_RATE', 'ORDER_CONVERSION_RATE', 'FLOW_RATE', 'CVR'],
    config: { fields: ['flowRate', 'flow_rate'], label: 'flow_rate' },
  },
  {
    aliases: ['ORDER_FILLING_NUM', 'ORDER_FILLING_UV', 'ORDER_SUBMIT_UV', 'SUBMIT_ORDER_UV', 'SUBMIT_ORDER_CNT'],
    config: { fields: ['orderFillingNum', 'order_filling_num'], label: 'order_filling_num' },
  },
  {
    aliases: ['PAY_ORDER_CNT', 'PAY_ORDER_COUNT', 'ORDER_COUNT', 'ORDER_CNT', 'ORDERS', 'SUBMIT_ORDER_COUNT'],
    config: { fields: ['orderSubmitNum', 'order_submit_num'], label: 'order_submit_num' },
  },
];

const CARD_METRIC_TITLE_RULES = [
  {
    config: { fields: ['listExposure', 'list_exposure'], label: 'list_exposure' },
    patterns: [
      /\u66dd\u5149/,
      /exposure/i,
      /impression/i,
      /list\s*pv/i,
    ],
  },
  {
    config: { fields: ['detailExposure', 'detail_exposure'], label: 'detail_exposure' },
    patterns: [
      /\u8be6\u60c5.*\u6d4f\u89c8/,
      /\u6d4f\u89c8\u4eba\u6570/,
      /\u8bbf\u5ba2/,
      /\u610f\u5411/,
      /detail.*(uv|pv|view|visitor)/i,
      /visitor/i,
      /\buv\b/i,
    ],
  },
  {
    config: { fields: ['flowRate', 'flow_rate'], label: 'flow_rate' },
    patterns: [
      /\u8f6c\u5316\u7387/,
      /conversion.*rate/i,
      /\bcvr\b/i,
      /flow.*rate/i,
    ],
  },
  {
    config: { fields: ['orderFillingNum', 'order_filling_num'], label: 'order_filling_num' },
    patterns: [
      /\u586b\u5199.*\u8ba2\u5355/,
      /\u63d0\u4ea4.*\u8ba2\u5355/,
      /\u4e0b\u5355\u4eba/,
      /submit.*order/i,
      /fill.*order/i,
    ],
  },
  {
    config: { fields: ['orderSubmitNum', 'order_submit_num'], label: 'order_submit_num' },
    patterns: [
      /\u652f\u4ed8.*\u8ba2\u5355/,
      /\u6210\u4ea4.*\u8ba2\u5355/,
      /\u8ba2\u5355\u6570/,
      /\u8ba2\u5355\u91cf/,
      /pay.*order/i,
      /paid.*order/i,
      /\border(s)?\b/i,
    ],
  },
];

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
    data_period: 'next_30_days',
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
    data_period: 'next_30_days',
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

export function normalizeMeituanOrderFlowRows(value, options = {}) {
  const data = firstObjectAtPath(value, [
    ['data'],
    [],
  ]);
  const requiredSummaryKeys = ['lossTotalCnt', 'lossTotalPayRoomNight', 'lossTotalPayAmount'];
  if (!requiredSummaryKeys.every(key => Object.prototype.hasOwnProperty.call(data, key))) {
    return [];
  }

  const direction = String(options.orderFlowDirection || options.flowDirection || '').trim().toLowerCase();
  if (!['loss', 'inflow'].includes(direction)) {
    return [];
  }
  const periodStart = normalizeDateLike(options.periodStart || options.startDate || '');
  const periodEnd = normalizeDateLike(options.periodEnd || options.endDate || '');
  if (!periodStart || !periodEnd) {
    return [];
  }
  const period = String(options.orderFlowPeriod || '').trim() || resolveMeituanOrderFlowPeriod(periodStart, periodEnd);
  const base = {
    dataDate: periodEnd,
    date_source: 'request.query.endDate',
    data_period: 'historical_daily',
    order_flow_direction: direction,
    order_flow_period: period,
    period_start: periodStart,
    period_end: periodEnd,
  };
  const summary = decorateSupplementalRow({
    ...base,
    order_flow_row_type: 'summary',
    dimension: `order_flow:${period}:${direction}:summary`,
    order_count: numberish(data.lossTotalCnt),
    room_nights: numberish(data.lossTotalPayRoomNight),
    amount: numberish(data.lossTotalPayAmount),
    poi_star: data.poiStar ?? '',
  }, 'order_flow', 'data', options);

  const details = asRowList(data.orderLossPeerDetails).map((item, index) => {
    const poiId = String(item.poiId ?? item.poi_id ?? '').trim();
    const row = decorateSupplementalRow({
      ...item,
      ...base,
      order_flow_row_type: 'hotel_detail',
      dimension: `order_flow:${period}:${direction}:hotel:${poiId || index + 1}`,
      order_count: numberish(item.lossOrderCount),
      order_ratio: numberish(item.lossOrderRatio),
      amount: numberish(item.lossSinglePayAmount),
      compare_type: 'competitor',
    }, 'order_flow', `data.orderLossPeerDetails.${index}`, options);
    return { ...row, _capture_source: 'xhr:order_flow:hotel_detail' };
  });

  return [{ ...summary, _capture_source: 'xhr:order_flow:summary' }, ...details];
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
        value_state: hasTrafficMetricValue(cardMetricValue(card).value) ? 'present' : 'missing',
      })),
  };
  let recognizedMetricCount = 0;

  cards.forEach((card, index) => {
    if (!card || typeof card !== 'object' || Array.isArray(card)) {
      return;
    }
    const config = resolveCardMetricConfig(card);
    if (!config) {
      return;
    }
    recognizedMetricCount += 1;
    const metricValue = cardMetricValue(card);
    const numericValue = trafficMetricNumber(metricValue.value);
    if (numericValue === null) {
      row._meituan_card_metric_missing.push({
        card_id: String(card.id || card.key || card.metricId || '').trim(),
        card_title: String(card.title || card.name || '').trim(),
        metric_key: config.label,
        value_state: normalizeMissingValueState(metricValue.value),
      });
      return;
    }
    for (const field of config.fields) {
      row[field] = numericValue;
    }
    row._meituan_card_metric_sources[config.label] = {
      card_id: String(card.id || card.key || card.metricId || '').trim(),
      card_title: String(card.title || card.name || '').trim(),
      source_path: `${row._source_path}.${index}.${metricValue.key}`,
    };
  });

  if (recognizedMetricCount === 0) {
    return null;
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

function resolveCardMetricConfig(card) {
  const idCandidates = [
    card.id,
    card.key,
    card.metricId,
    card.metric_id,
    card.metricCode,
    card.metric_code,
    card.code,
  ];
  for (const candidate of idCandidates) {
    const exact = String(candidate || '').trim();
    if (!exact) {
      continue;
    }
    const direct = CARD_METRIC_MAP.get(exact);
    if (direct) {
      return direct;
    }
    const normalized = normalizeMetricToken(exact);
    for (const rule of CARD_METRIC_ID_ALIASES) {
      if (rule.aliases.some(alias => normalizeMetricToken(alias) === normalized)) {
        return rule.config;
      }
    }
  }

  const title = [
    card.title,
    card.name,
    card.label,
    card.metricName,
    card.metric_name,
    card.displayName,
    card.display_name,
  ].map(value => String(value || '').trim()).filter(Boolean).join(' ');
  if (!title) {
    return null;
  }
  for (const rule of CARD_METRIC_TITLE_RULES) {
    if (rule.patterns.some(pattern => pattern.test(title))) {
      return rule.config;
    }
  }
  return null;
}

function normalizeMetricToken(value) {
  return String(value || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
}

function cardMetricValue(card) {
  const keys = [
    'value',
    'valueText',
    'value_text',
    'displayValue',
    'display_value',
    'current',
    'currentValue',
    'current_value',
    'dataValue',
    'data_value',
    'num',
    'count',
    'metricValue',
    'metric_value',
  ];
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(card, key)) {
      return { key, value: card[key] };
    }
  }
  return { key: 'value', value: undefined };
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
  if (hasOwnDate(next)) {
    if (!next.date_source && !next.dateSource) {
      next.date_source = supplementalRowDateSource(next);
    }
  } else {
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

function supplementalRowDateSource(row) {
  const keys = [
    'forecastDate', 'forecast_date', 'targetDate', 'target_date', 'dateTime',
    'dataDate', 'data_date', 'date', 'statDate', 'stat_date', 'reportDate', 'day',
  ];
  const key = keys.find(candidate => String(row?.[candidate] ?? '').trim() !== '');
  return key ? `row.${key}` : 'row';
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
  return String(options.dateRange || '') === '0'
    ? 'request.query.dateRange=0'
    : 'capture_context.default_data_date';
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

function resolveMeituanOrderFlowPeriod(startDate, endDate) {
  const start = Date.parse(`${startDate}T00:00:00Z`);
  const end = Date.parse(`${endDate}T00:00:00Z`);
  if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) {
    return 'custom';
  }
  const inclusiveDays = Math.round((end - start) / 86400000) + 1;
  if (inclusiveDays === 1) return 'yesterday';
  if (inclusiveDays === 7) return 'last_7_days';
  if (inclusiveDays === 30) return 'last_30_days';
  return 'custom';
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
