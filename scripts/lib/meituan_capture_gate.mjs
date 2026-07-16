const SECTION_NAMES = ['traffic', 'order_flow', 'orders', 'ads', 'reviews'];
const CUMULATIVE_PAYLOAD_KEYS = [
  'traffic',
  'ads',
  'businessData',
  'business_data',
  'peerRank',
  'peer_rank',
  'flowAnalysis',
  'flow_analysis',
  'order_flow',
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
  const notApplicableSections = [];
  const targetDate = String(options?.targetDate || options?.dataDate || '').trim();

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
      if (
        section === 'orders'
        && !successfulResponses.some(item => responseSection(item) === 'orders' && Number(item.row_count || 0) > 0)
        && !hasAuthoritativeOrderDomAggregate(data, targetDate)
      ) {
        sectionStatuses[section] = 'not_captured';
        failed.push('section_orders_not_captured');
        continue;
      }
      sectionStatuses[section] = 'captured';
      continue;
    }
    if (hasAuthoritativeNotApplicableEvidence(data, section)) {
      sectionStatuses[section] = 'not_applicable';
      notApplicableSections.push(section);
      continue;
    }
    const authoritativeEmptyResponses = successfulResponses.filter(
      item => responseSection(item) === section && Number(item.row_count || 0) === 0
    );
    if (authoritativeEmptyResponses.length > 0) {
      if (
        section === 'orders'
        && /^\d{4}-\d{2}-\d{2}$/.test(targetDate)
        && !hasTargetDateQueryEvidence(data, section, targetDate, authoritativeEmptyResponses)
      ) {
        sectionStatuses[section] = 'not_captured';
        failed.push('section_orders_not_captured');
        continue;
      }
      sectionStatuses[section] = 'empty_confirmed';
      continue;
    }
    sectionStatuses[section] = 'not_captured';
    failed.push(`section_${section}_not_captured`);
  }

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
    not_applicable_sections: notApplicableSections,
  };
}

export function filterMeituanCumulativeRowsByTargetDate(data, targetDate) {
  const next = { ...(data || {}) };
  const normalizedTargetDate = normalizeDate(targetDate);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(normalizedTargetDate)) {
    return next;
  }

  const droppedCounts = {};
  for (const key of CUMULATIVE_PAYLOAD_KEYS) {
    if (!Array.isArray(data?.[key])) continue;
    const rows = data[key];
    next[key] = rows.filter(row => isVerifiedTargetDateRow(row, normalizedTargetDate));
    const dropped = rows.length - next[key].length;
    if (dropped > 0) {
      droppedCounts[key] = dropped;
    }
  }
  next.target_date_filter = {
    target_date: normalizedTargetDate,
    dropped_counts: droppedCounts,
  };
  return next;
}

export function filterMeituanEventRowsByTargetDate(data, targetDate, requestedSections = []) {
  const next = { ...(data || {}) };
  const normalizedTargetDate = normalizeDate(targetDate);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(normalizedTargetDate)) {
    return next;
  }

  const requested = new Set(Array.from(requestedSections || []).map(section => String(section || '').trim().toLowerCase()));
  const droppedCounts = { ...(data?.target_date_filter?.dropped_counts || {}) };
  for (const key of ['orders', 'reviews']) {
    if (!requested.has(key) || !Array.isArray(data?.[key])) continue;
    const rows = data[key];
    next[key] = rows.filter(row => isVerifiedTargetDateRow(row, normalizedTargetDate));
    const dropped = rows.length - next[key].length;
    if (dropped > 0) {
      droppedCounts[key] = (droppedCounts[key] || 0) + dropped;
    }
  }
  next.target_date_filter = {
    ...(data?.target_date_filter || {}),
    target_date: normalizedTargetDate,
    dropped_counts: droppedCounts,
  };
  return next;
}

function isSuccessfulResponse(item) {
  if (!item || typeof item !== 'object') return false;
  const status = Number(item.status || 0);
  const section = responseSection(item);
  if (section === 'orders' && !hasAuthoritativeOrderBusinessStructure(item)) {
    return false;
  }
  return !hasPlatformError(item.data, section)
    && (Number(item.row_count || 0) > 0 || (status >= 200 && status < 400 && !item.error));
}

function hasAuthoritativeOrderBusinessStructure(item) {
  const status = Number(item.status || 0);
  const resourceType = String(item.resource_type || item.resourceType || item.request_type || item.requestType || '').trim().toLowerCase();
  const contentType = String(item.content_type || item.contentType || '').trim().toLowerCase();
  if (
    status < 200
    || status >= 400
    || item.error
    || (resourceType && !['xhr', 'fetch'].includes(resourceType))
    || (contentType && !contentType.includes('json'))
  ) {
    return false;
  }

  const data = item.data;
  if (!data || typeof data !== 'object' || hasRawDocumentPayload(data)) {
    return false;
  }

  const signals = collectOrderBusinessSignals(data);
  if (signals.length === 0) {
    return false;
  }
  const rowCount = Number(item.row_count || 0);
  if (rowCount > 0) {
    return signals.some(value => value > 0);
  }
  return signals.every(value => value === 0);
}

function hasRawDocumentPayload(data) {
  if (Array.isArray(data)) return false;
  return String(data._raw_text || data.raw_text || '').trim() !== '';
}

function collectOrderBusinessSignals(data) {
  if (Array.isArray(data)) {
    return [data.length];
  }
  const signals = [];
  const containers = [data, data.data, data.result, data.data?.result, data.result?.data]
    .filter(value => value && typeof value === 'object' && !Array.isArray(value));
  const listKeys = ['results', 'orders', 'orderList', 'list'];
  const aggregateKeys = [
    'orderCount', 'order_count', 'orderNum', 'order_num',
    'totalCount', 'total_count', 'total', 'count',
    'unhandledCount', 'unhandled_count', 'pendingCount', 'pending_count',
  ];
  const aggregateMapKeys = ['orderNumWithType', 'order_num_with_type'];

  for (const container of containers) {
    for (const key of listKeys) {
      if (Array.isArray(container[key])) {
        signals.push(container[key].length);
      }
    }
    for (const key of aggregateKeys) {
      if (!Object.prototype.hasOwnProperty.call(container, key)) continue;
      const value = Number(container[key]);
      if (Number.isFinite(value) && value >= 0) {
        signals.push(value);
      }
    }
    for (const key of aggregateMapKeys) {
      const map = container[key];
      if (!map || typeof map !== 'object' || Array.isArray(map)) continue;
      const values = Object.values(map);
      const numericValues = values.map(value => Number(value));
      if (values.length > 0 && numericValues.every(value => Number.isFinite(value) && value >= 0)) {
        signals.push(...numericValues);
      }
    }
  }
  return signals;
}

function hasTargetDateQueryEvidence(data, section, targetDate, responses) {
  const evidence = data?.section_evidence?.[section] || data?.sectionEvidence?.[section];
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) return false;
  const queryEpoch = Number(evidence.query_epoch || evidence.queryEpoch || 0);
  const evidenceMatches = String(evidence.status || '').trim().toLowerCase() === 'target_date_queried'
    && normalizeDate(evidence.target_date || evidence.targetDate) === targetDate
    && String(evidence.evidence_source || evidence.evidenceSource || '').trim().toLowerCase() === 'page.form_readback'
    && String(evidence.marker || '').trim().toLowerCase() === 'meituan_orders_purchase_date_query'
    && Number.isInteger(queryEpoch)
    && queryEpoch > 0;
  if (!evidenceMatches) return false;
  return Array.from(responses || []).some(item => (
    Number(item?.query_epoch || item?.queryEpoch || 0) === queryEpoch
    && normalizeDate(item?.query_target_date || item?.queryTargetDate) === targetDate
    && String(item?.query_date_source || item?.queryDateSource || '').trim().toLowerCase() === 'page.orders.purchase_date_input.readback'
  ));
}

function hasAuthoritativeOrderDomAggregate(data, targetDate) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(targetDate)) return false;
  const evidence = data?.section_evidence?.orders || data?.sectionEvidence?.orders;
  const queryEpoch = Number(evidence?.query_epoch || evidence?.queryEpoch || 0);
  if (
    !evidence
    || String(evidence.status || '').trim().toLowerCase() !== 'target_date_queried'
    || normalizeDate(evidence.target_date || evidence.targetDate) !== targetDate
    || String(evidence.evidence_source || evidence.evidenceSource || '').trim().toLowerCase() !== 'page.form_readback'
    || String(evidence.marker || '').trim().toLowerCase() !== 'meituan_orders_purchase_date_query'
    || !Number.isInteger(queryEpoch)
    || queryEpoch <= 0
  ) {
    return false;
  }

  return Array.from(data?.orders || []).some(row => {
    const orderCount = Number(row?.order_count ?? row?.orderCount ?? row?.orders);
    const visibleDateCount = Number(row?.visible_order_date_count ?? row?.visibleOrderDateCount);
    return row
      && String(row._capture_source || '').trim().toLowerCase() === 'dom:orders:target_date_summary'
      && String(row._source_path || '').trim().toLowerCase() === 'dom.orders.target_date_summary'
      && String(row.page_summary_marker || row.pageSummaryMarker || '').trim().toLowerCase() === 'meituan_orders_target_date_summary'
      && normalizeDate(row.data_date || row.dataDate || row.date) === targetDate
      && String(row.date_source || row.dateSource || '').trim().toLowerCase() === 'page.orders.purchase_date_input.readback'
      && String(row.compare_type || row.compareType || '').trim().toLowerCase() === 'self'
      && row.is_self === true
      && Number(row.query_epoch || row.queryEpoch || 0) === queryEpoch
      && Number.isInteger(orderCount)
      && orderCount >= 0
      && Number.isInteger(visibleDateCount)
      && visibleDateCount === orderCount
      && row.visible_order_dates_match_target === true;
  });
}

function hasPlatformError(data, section = '') {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return false;
  if (data.success === false || data.ok === false) return true;
  const code = data.code ?? data.resultCode ?? data.status;
  if (code === undefined || code === null || code === '') return Boolean(data.error);
  const normalizedCode = String(code).trim().toLowerCase();
  if (section === 'orders' && normalizedCode === '10000') {
    return Boolean(data.error);
  }
  return !['0', '200', 'success', 'ok'].includes(normalizedCode);
}

function responseSection(item) {
  return String(item?.section || item?.capture_section || '').trim().toLowerCase();
}

function hasAuthoritativeNotApplicableEvidence(data, section) {
  if (section !== 'ads') return false;
  const evidence = data?.section_evidence?.[section] || data?.sectionEvidence?.[section];
  if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) return false;
  return String(evidence.status || '').trim().toLowerCase() === 'not_applicable'
    && String(evidence.reason || '').trim().toLowerCase() === 'ads_not_enabled'
    && String(evidence.evidence_source || evidence.evidenceSource || '').trim().toLowerCase() === 'page.dom'
    && String(evidence.marker || '').trim().toLowerCase() === 'meituan_ads_onboarding';
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

function isVerifiedTargetDateRow(row, targetDate) {
  if (!row || typeof row !== 'object') return false;
  const rowDate = normalizeDate(firstValue(row, [
    'data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'reportDate', 'day',
  ]));
  const explicitSource = String(row.date_source || row.dateSource || '').trim();
  const inferredRowSource = hasOwnDate(row) && !explicitSource ? 'row' : explicitSource;
  return rowDate === targetDate && isAuthoritativeDateSource(inferredRowSource);
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
