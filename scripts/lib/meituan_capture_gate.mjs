const SECTION_NAMES = ['traffic', 'orders', 'ads', 'reviews'];
const CUMULATIVE_PAYLOAD_KEYS = [
  'traffic',
  'ads',
  'businessData',
  'business_data',
  'peerRank',
  'peer_rank',
  'flowAnalysis',
  'flow_analysis',
  'searchKeywords',
  'search_keywords',
  'roomTypes',
  'room_types',
];

export function evaluateMeituanCaptureGate(data, requestedSections = [], options = {}) {
  const requested = Array.from(new Set(Array.from(requestedSections || [])
    .map(section => String(section || '').trim().toLowerCase())
    .filter(section => SECTION_NAMES.includes(section))));
  const failed = [];
  const sectionCounts = Object.fromEntries(SECTION_NAMES.map(section => [
    section,
    Array.isArray(data?.[section]) ? data[section].length : 0,
  ]));
  const responses = Array.isArray(data?.responses) ? data.responses : [];
  const importableResponseCount = responses.filter(item => item && Number(item.row_count || 0) > 0).length;
  const successfulResponses = responses.filter(isSuccessfulResponse);
  const capturedResponseCount = successfulResponses.length;
  const sectionStatuses = {};

  if (!data?.auth_status?.ok) {
    failed.push('auth_login_required');
  }
  if (responses.length === 0) {
    failed.push('xhr_not_captured');
  } else if (capturedResponseCount === 0) {
    failed.push('xhr_without_importable_rows');
  }

  for (const section of requested) {
    if (sectionCounts[section] > 0) {
      sectionStatuses[section] = 'captured';
      continue;
    }
    if (successfulResponses.some(item => responseSection(item) === section && Number(item.row_count || 0) === 0)) {
      sectionStatuses[section] = 'empty_confirmed';
      continue;
    }
    sectionStatuses[section] = 'not_captured';
    failed.push(`section_${section}_not_captured`);
  }

  const targetDate = String(options?.targetDate || options?.dataDate || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(targetDate)) {
    const targetDateEvidence = validateTargetDateEvidence(data, targetDate);
    failed.push(...targetDateEvidence.failed_check_ids);
  }

  return {
    status: failed.length ? 'fail' : 'pass',
    failed_check_ids: Array.from(new Set(failed)),
    section_counts: sectionCounts,
    response_count: responses.length,
    captured_response_count: capturedResponseCount,
    importable_response_count: importableResponseCount,
    requested_sections: requested,
    section_statuses: sectionStatuses,
  };
}

function isSuccessfulResponse(item) {
  if (!item || typeof item !== 'object') return false;
  const status = Number(item.status || 0);
  return !hasPlatformError(item.data)
    && (Number(item.row_count || 0) > 0 || (status >= 200 && status < 400 && !item.error));
}

function hasPlatformError(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return false;
  if (data.success === false || data.ok === false) return true;
  const code = data.code ?? data.resultCode ?? data.status;
  if (code === undefined || code === null || code === '') return Boolean(data.error);
  return !['0', '200', 'success', 'ok'].includes(String(code).trim().toLowerCase());
}

function responseSection(item) {
  return String(item?.section || item?.capture_section || '').trim().toLowerCase();
}

function validateTargetDateEvidence(data, targetDate) {
  const failed = [];
  for (const key of CUMULATIVE_PAYLOAD_KEYS) {
    const rows = Array.isArray(data?.[key]) ? data[key] : [];
    for (const row of rows) {
      if (!row || typeof row !== 'object') continue;
      const rowDate = normalizeDate(firstValue(row, [
        'data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'reportDate', 'day',
      ]));
      if (rowDate && rowDate !== targetDate) {
        failed.push('target_date_mismatch');
        continue;
      }
      const explicitSource = String(row.date_source || row.dateSource || '').trim();
      const inferredRowSource = hasOwnDate(row) && !explicitSource ? 'row' : explicitSource;
      if (!rowDate || !isAuthoritativeDateSource(inferredRowSource)) {
        failed.push('target_date_unverified');
      }
    }
  }
  return { failed_check_ids: Array.from(new Set(failed)) };
}

function isAuthoritativeDateSource(source) {
  const value = String(source || '').trim().toLowerCase();
  return value === 'row'
    || value.startsWith('row.')
    || value.startsWith('request.')
    || value.startsWith('response.')
    || value.startsWith('page.');
}

function hasOwnDate(row) {
  return ['data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'reportDate', 'day']
    .some(key => Object.prototype.hasOwnProperty.call(row, key) && String(row[key] ?? '').trim() !== '');
}

function firstValue(row, keys) {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(row, key) && String(row[key] ?? '').trim() !== '') {
      return row[key];
    }
  }
  return '';
}

function normalizeDate(value) {
  const match = String(value || '').match(/(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})/);
  return match ? `${match[1]}-${String(match[2]).padStart(2, '0')}-${String(match[3]).padStart(2, '0')}` : '';
}
