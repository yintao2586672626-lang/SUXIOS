const METRIC_ALIASES = Object.freeze({
  listExposure: [
    'listExposure',
  ],
  detailExposure: [
    'detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount',
    'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views', 'pageViews',
  ],
  flowRate: [
    'flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate',
    'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr',
  ],
  orderFillingNum: [
    'orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount',
    'click_count', 'clicks', 'clickNum', 'fillUsers',
  ],
  orderSubmitNum: [
    'orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount',
    'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders',
  ],
});

const CANONICAL_METRIC_KEYS = Object.freeze({
  listExposure: 'list_exposure',
  detailExposure: 'detail_exposure',
  flowRate: 'flow_rate',
  orderFillingNum: 'order_filling_num',
  orderSubmitNum: 'order_submit_num',
});

function observedValue(row, aliases) {
  for (const alias of aliases) {
    if (!Object.prototype.hasOwnProperty.call(row, alias)) continue;
    const value = row[alias];
    if (value !== null && value !== undefined && String(value).trim() !== '') {
      return value;
    }
  }
  return null;
}

function finiteNumber(value) {
  if (typeof value === 'string') {
    value = value.replace(/[,%\s]/g, '').trim();
  }
  return Number.isFinite(Number(value)) ? Number(value) : null;
}

/**
 * Normalize only fields that were present in the captured response row.
 * A rank-only endpoint must never acquire zero-valued funnel fields merely
 * because the storage schema contains those columns.
 */
export function normalizeObservedCtripTrafficMetrics(row = {}) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) return {};

  const normalized = {};
  for (const [target, aliases] of Object.entries(METRIC_ALIASES)) {
    const raw = observedValue(row, aliases);
    if (raw === null) continue;
    const value = finiteNumber(raw);
    if (value === null) continue;
    if (target === 'flowRate') {
      const percent = value > 0 && value <= 1 ? value * 100 : value;
      normalized[target] = Math.round(percent * 100) / 100;
    } else {
      normalized[target] = Math.round(value);
    }
  }
  return normalized;
}

/**
 * Preserve which canonical funnel fields were actually present in the
 * protected response row. This marker is deliberately derived from the
 * presence-aware normalized object, never from storage defaults.
 */
export function observedCtripTrafficMetricKeys(normalized = {}) {
  if (!normalized || typeof normalized !== 'object' || Array.isArray(normalized)) return [];

  return Object.keys(CANONICAL_METRIC_KEYS)
    .filter(key => Object.prototype.hasOwnProperty.call(normalized, key))
    .map(key => CANONICAL_METRIC_KEYS[key])
    .sort();
}
