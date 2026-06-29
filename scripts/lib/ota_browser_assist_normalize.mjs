import { createHash } from 'node:crypto';

import { attachOtaCaptureEvidence } from './ota_capture_standard.mjs';

const CONTRACT_VERSION = 'ota_browser_assist_collection_contract.v1';
const COLLECTION_MODE = 'browser_assist_dom';
const KNOWN_SECTION_KEYS = ['ctrip', 'ctripStats', 'meituan', 'meituanStats', 'meituanHook', 'meituanPeerHook'];

const PLATFORM_LABELS = {
  ctrip: 'Ctrip',
  meituan: 'Meituan',
  qunar: 'Qunar',
};

const DATA_TYPE_LABELS = {
  inventory: 'room inventory',
  traffic: 'realtime traffic',
  traffic_analysis: 'traffic analysis',
  traffic_forecast: 'traffic forecast',
  peer_rank: 'peer rank',
  search_keyword: 'search keyword',
};

export function normalizeBrowserAssistCapturePayload(input, options = {}) {
  const payload = unwrapPayload(input);
  const generatedAt = normalizeDateTime(options.generatedAt || options.now || new Date().toISOString());
  const context = {
    generatedAt,
    systemHotelId: toInteger(firstDefined(options.systemHotelId, options.system_hotel_id, payload.system_hotel_id, payload.systemHotelId)),
    hotelId: cleanText(firstDefined(options.hotelId, options.hotel_id, payload.hotel_id, payload.hotelId, payload.external_hotel_id)),
    hotelName: cleanText(firstDefined(options.hotelName, options.hotel_name, payload.hotel_name, payload.hotelName)),
    dataDate: normalizeDate(firstDefined(options.dataDate, options.data_date, payload.data_date, payload.dataDate)),
    snapshotTime: normalizeDateTime(firstDefined(options.snapshotTime, options.snapshot_time, payload.snapshot_time, payload.snapshotTime)),
  };

  const warnings = [];
  const rows = [
    ...normalizeInventorySection(payload.ctrip, 'ctrip', 'ctrip_inventory', context, warnings),
    ...normalizeInventorySection(payload.meituan, 'meituan', 'meituan_inventory', context, warnings),
    ...normalizeCtripStatsSection(payload.ctripStats, context, warnings),
    ...normalizeMeituanStatsSection(payload.meituanStats, context, warnings),
    ...normalizeMeituanHookSection(resolveMeituanHookSection(payload), context, warnings),
  ];

  const packages = buildImportPackages(rows, context);
  return {
    type: 'ota_browser_assist_import',
    source_contract: CONTRACT_VERSION,
    generated_at: generatedAt,
    collection_mode: COLLECTION_MODE,
    summary: {
      row_count: rows.length,
      package_count: packages.length,
      platforms: unique(rows.map((row) => row.source)).filter(Boolean),
      data_types: unique(rows.map((row) => row.data_type)).filter(Boolean),
      warning_count: warnings.length,
    },
    warnings,
    packages,
    rows,
  };
}

export function buildImportPackages(rows, context = {}) {
  const groups = new Map();
  for (const row of rows) {
    const platform = cleanText(row.source || row.platform || 'custom').toLowerCase();
    const dataType = cleanText(row.data_type || 'business').toLowerCase();
    const key = `${platform}:${dataType}`;
    if (!groups.has(key)) {
      groups.set(key, { platform, dataType, rows: [] });
    }
    groups.get(key).rows.push(row);
  }

  return Array.from(groups.values()).map((group) => ({
    name: `${PLATFORM_LABELS[group.platform] || group.platform} browser assist ${DATA_TYPE_LABELS[group.dataType] || group.dataType}`,
    platform: group.platform,
    data_type: group.dataType,
    system_hotel_id: toInteger(context.systemHotelId) || toInteger(group.rows.find((row) => row.system_hotel_id)?.system_hotel_id) || 0,
    source_contract: CONTRACT_VERSION,
    collection_mode: COLLECTION_MODE,
    import_endpoint: '/api/online-data/data-import',
    rows: group.rows,
  }));
}

function normalizeInventorySection(section, platform, moduleKey, context, warnings) {
  if (!section || typeof section !== 'object') {
    return [];
  }
  const rooms = Array.isArray(section.rooms) ? section.rooms : [];
  if (rooms.length === 0) {
    warnings.push({
      platform,
      module: moduleKey,
      code: 'rooms_missing',
      message: `${moduleKey} has no room inventory rows.`,
    });
    return [];
  }

  const rows = [];
  const snapshot = resolveSnapshot(section, context);
  rooms.forEach((room, roomIndex) => {
    const roomName = cleanText(firstDefined(room.name, room.roomName, room.room_type_name, room.productName));
    const days = Array.isArray(room.days) ? room.days : [];
    if (days.length === 0) {
      warnings.push({
        platform,
        module: moduleKey,
        code: 'room_days_missing',
        source_path: `${moduleKey}.rooms.${roomIndex}.days`,
        message: `Room inventory days are missing for room index ${roomIndex}.`,
      });
    }

    days.forEach((day, dayIndex) => {
      const sourcePath = `${moduleKey}.rooms.${roomIndex}.days.${dayIndex}`;
      const dataDate = normalizeDate(firstDefined(day.date, day.data_date, day.dataDate, section.data_date, section.dataDate, context.dataDate));
      if (!dataDate) {
        warnings.push({
          platform,
          module: moduleKey,
          code: 'data_date_missing',
          source_path: sourcePath,
          message: 'Inventory row skipped because no data_date could be proven.',
        });
        return;
      }

      const state = cleanText(firstDefined(day.state, day.status, day.saleState, day.product_status));
      const isClosed = Boolean(day.isClosed || day.closed || /closed|close|关房|满房|不可售/.test(state));
      const remain = toNumber(firstDefined(day.remain, day.available, day.stock, day.inventory_remaining, day.remainText));
      const reserved = toNumber(firstDefined(day.reserved, day.locked, day.inventory_reserved));
      const sold = toNumber(firstDefined(day.sold, day.soldOut, day.inventory_sold));
      const limitType = cleanText(firstDefined(day.limitType, day.limit_type));
      const rawText = cleanText(firstDefined(day.raw, day.rawText, day.text));
      const dimension = roomName || `room_index:${roomIndex}`;
      const row = {
        source: platform,
        platform,
        data_type: 'inventory',
        data_date: dataDate,
        data_period: 'realtime_snapshot',
        snapshot_time: snapshot.snapshotTime,
        snapshot_bucket: snapshot.snapshotBucket,
        system_hotel_id: toInteger(firstDefined(section.system_hotel_id, section.systemHotelId, context.systemHotelId)) || undefined,
        hotel_id: cleanText(firstDefined(section.hotel_id, section.hotelId, context.hotelId)) || undefined,
        hotel_name: cleanText(firstDefined(section.hotelName, section.hotel_name, context.hotelName)) || undefined,
        dimension,
        room_type_name: roomName || undefined,
        product_status: state || (isClosed ? 'closed' : undefined),
        inventory_remaining: hasValue(remain) ? remain : undefined,
        inventory_reserved: hasValue(reserved) ? reserved : undefined,
        inventory_sold: hasValue(sold) ? sold : undefined,
        inventory_remain_text: cleanText(day.remainText) || undefined,
        inventory_limit_type: limitType || undefined,
        data_value: hasValue(remain) ? remain : undefined,
        acquisition_method: COLLECTION_MODE,
        source_contract: CONTRACT_VERSION,
        raw_data: {
          collection_mode: COLLECTION_MODE,
          source_contract: CONTRACT_VERSION,
          module: moduleKey,
          snapshot_time_source: snapshot.source,
          inventory: compactObject({
            room_name: roomName || null,
            data_date: dataDate,
            state: state || null,
            is_closed: isClosed,
            remain,
            reserved,
            sold,
            remain_text: cleanText(day.remainText) || null,
            limit_type: limitType || null,
            raw_text: rawText || null,
          }),
          missing_fields: missingInventoryFields({ roomName, remain, state }),
          field_facts: [
            fieldFact('room_inventory_remaining', 'inventory', `${sourcePath}.remain`, 'online_daily_data.raw_data.inventory.remain', remain),
            fieldFact('room_inventory_reserved', 'inventory', `${sourcePath}.reserved`, 'online_daily_data.raw_data.inventory.reserved', reserved, 'optional_missing'),
            fieldFact('room_inventory_sold', 'inventory', `${sourcePath}.sold`, 'online_daily_data.raw_data.inventory.sold', sold, 'optional_missing'),
            fieldFact('room_sale_status', 'inventory', `${sourcePath}.state`, 'online_daily_data.raw_data.inventory.state', state),
          ],
        },
      };
      rows.push(attachEvidence(compactObject(row), platform, 'inventory', sourcePath, moduleKey, section));
    });
  });
  return rows;
}

function normalizeCtripStatsSection(section, context, warnings) {
  if (!section || typeof section !== 'object') {
    return [];
  }
  const metricsRoot = section.metrics && typeof section.metrics === 'object' ? section.metrics : {};
  const rows = [];
  for (const channel of ['ctrip', 'qunar']) {
    const channelMetrics = metricsRoot[channel] && typeof metricsRoot[channel] === 'object' ? metricsRoot[channel] : null;
    if (!channelMetrics) {
      continue;
    }
    const sourcePlatform = 'ctrip';
    const snapshot = resolveSnapshot(section, context);
    const dataDate = resolveRealtimeDate(section, context, snapshot);
    if (!dataDate) {
      warnings.push({
        platform: sourcePlatform,
        module: 'ctrip_stats',
        code: 'data_date_missing',
        source_path: `ctrip_stats.metrics.${channel}`,
        message: `${channel} realtime metrics skipped because no data_date could be proven.`,
      });
      continue;
    }

    const realtimeVisitors = metricNumber(channelMetrics, ['realtimeVisitors', 'visitorTotal', 'visitors', 'uv']);
    const visitorPeerAvg = metricNumber(channelMetrics, ['visitorPeerAvg', 'peerAvgVisitors', 'peer_avg_visitors']);
    const orderConversionRate = metricNumber(channelMetrics, ['orderConversionRate', 'conversionRate', 'flowRate']);
    const trafficSourcePath = `ctrip_stats.metrics.${channel}`;
    const trafficRow = compactObject({
      source: sourcePlatform,
      platform: sourcePlatform,
      data_type: 'traffic',
      data_date: dataDate,
      data_period: 'realtime_snapshot',
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(section.system_hotel_id, section.systemHotelId, context.systemHotelId)) || undefined,
      hotel_id: cleanText(firstDefined(section.hotel_id, section.hotelId, context.hotelId)) || undefined,
      hotel_name: cleanText(firstDefined(section.hotelName, section.hotel_name, context.hotelName)) || undefined,
      dimension: `realtime:${channel}`,
      detail_exposure: hasValue(realtimeVisitors) ? realtimeVisitors : undefined,
      flow_rate: hasValue(orderConversionRate) ? orderConversionRate : undefined,
      data_value: hasValue(realtimeVisitors) ? realtimeVisitors : undefined,
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: {
        collection_mode: COLLECTION_MODE,
        source_contract: CONTRACT_VERSION,
        module: 'ctrip_stats',
        channel,
        snapshot_time_source: snapshot.source,
        metrics: compactObject({
          realtime_visitors: metricRawValue(channelMetrics, ['realtimeVisitors', 'visitorTotal', 'visitors', 'uv']),
          visitor_peer_avg: metricRawValue(channelMetrics, ['visitorPeerAvg', 'peerAvgVisitors', 'peer_avg_visitors']),
          order_conversion_rate: metricRawValue(channelMetrics, ['orderConversionRate', 'conversionRate', 'flowRate']),
        }),
        field_facts: [
          fieldFact('realtime_visitors', 'traffic', `${trafficSourcePath}.realtimeVisitors`, 'online_daily_data.detail_exposure', realtimeVisitors),
          fieldFact('visitor_peer_avg', 'traffic', `${trafficSourcePath}.visitorPeerAvg`, 'online_daily_data.raw_data.metrics.visitor_peer_avg', visitorPeerAvg, 'optional_missing'),
          fieldFact('order_conversion_rate', 'traffic', `${trafficSourcePath}.orderConversionRate`, 'online_daily_data.flow_rate', orderConversionRate),
        ],
      },
    });
    if (hasObjectValue(trafficRow.raw_data.metrics)) {
      rows.push(attachEvidence(trafficRow, sourcePlatform, 'traffic', trafficSourcePath, 'ctrip_stats', section));
    }

    const realtimeRank = metricNumber(channelMetrics, ['realtimeRank', 'rank', 'ranking']);
    if (hasValue(realtimeRank)) {
      const rankSourcePath = `ctrip_stats.metrics.${channel}.realtimeRank`;
      rows.push(attachEvidence(compactObject({
        source: sourcePlatform,
        platform: sourcePlatform,
        data_type: 'peer_rank',
        data_date: dataDate,
        data_period: 'realtime_snapshot',
        snapshot_time: snapshot.snapshotTime,
        snapshot_bucket: snapshot.snapshotBucket,
        system_hotel_id: toInteger(firstDefined(section.system_hotel_id, section.systemHotelId, context.systemHotelId)) || undefined,
        hotel_id: cleanText(firstDefined(section.hotel_id, section.hotelId, context.hotelId)) || undefined,
        hotel_name: cleanText(firstDefined(section.hotelName, section.hotel_name, context.hotelName)) || undefined,
      dimension: `realtime:${channel}:rank`,
        compare_type: 'channel_realtime_rank',
        rank_type: 'realtime_rank',
        rank: realtimeRank,
        data_value: realtimeRank,
        acquisition_method: COLLECTION_MODE,
        source_contract: CONTRACT_VERSION,
        raw_data: {
          collection_mode: COLLECTION_MODE,
          source_contract: CONTRACT_VERSION,
          module: 'ctrip_stats',
          channel,
          snapshot_time_source: snapshot.source,
          rank_metrics: {
            realtime_rank: metricRawValue(channelMetrics, ['realtimeRank', 'rank', 'ranking']),
          },
          field_facts: [
            fieldFact('realtime_rank', 'peer_rank', rankSourcePath, 'online_daily_data.data_value/raw_data.rank_metrics.realtime_rank', realtimeRank),
          ],
        },
      }), sourcePlatform, 'peer_rank', rankSourcePath, 'ctrip_stats', section));
    }
  }

  if (rows.length === 0) {
    warnings.push({
      platform: 'ctrip',
      module: 'ctrip_stats',
      code: 'metrics_missing',
      message: 'Ctrip realtime metrics section had no importable values.',
    });
  }
  return rows;
}

function normalizeMeituanStatsSection(section, context, warnings) {
  if (!section || typeof section !== 'object') {
    return [];
  }
  const metrics = section.metrics && typeof section.metrics === 'object' ? section.metrics : {};
  const snapshot = resolveSnapshot(section, context);
  const dataDate = resolveRealtimeDate(section, context, snapshot);
  if (!dataDate) {
    warnings.push({
      platform: 'meituan',
      module: 'meituan_stats',
      code: 'data_date_missing',
      source_path: 'meituan_stats.metrics',
      message: 'Meituan realtime metrics skipped because no data_date could be proven.',
    });
    return [];
  }

  const exposureUsers = metricNumber(metrics, ['exposureUsers', 'listExposure', 'impressions', 'exposure_count']);
  const browseUsers = metricNumber(metrics, ['browseUsers', 'detailExposure', 'visitors', 'uv', 'clicks']);
  const paidOrders = metricNumber(metrics, ['paidOrders', 'orderSubmitNum', 'orderCount', 'orders']);
  const exposureBrowseRate = metricNumber(metrics, ['exposureBrowseRate', 'ctr']);
  const browsePayRate = metricNumber(metrics, ['browsePayRate', 'conversionRate', 'flowRate']);
  const sourcePath = 'meituan_stats.metrics';
  const row = compactObject({
    source: 'meituan',
    platform: 'meituan',
    data_type: 'traffic',
    data_date: dataDate,
    data_period: 'realtime_snapshot',
    snapshot_time: snapshot.snapshotTime,
    snapshot_bucket: snapshot.snapshotBucket,
    system_hotel_id: toInteger(firstDefined(section.system_hotel_id, section.systemHotelId, context.systemHotelId)) || undefined,
    hotel_id: cleanText(firstDefined(section.hotel_id, section.hotelId, context.hotelId)) || undefined,
    hotel_name: cleanText(firstDefined(section.hotelName, section.hotel_name, context.hotelName)) || undefined,
    dimension: 'realtime:meituan',
    list_exposure: hasValue(exposureUsers) ? exposureUsers : undefined,
    detail_exposure: hasValue(browseUsers) ? browseUsers : undefined,
    order_submit_num: hasValue(paidOrders) ? paidOrders : undefined,
    flow_rate: hasValue(browsePayRate) ? browsePayRate : undefined,
    data_value: hasValue(paidOrders) ? paidOrders : firstDefined(browseUsers, exposureUsers),
    acquisition_method: COLLECTION_MODE,
    source_contract: CONTRACT_VERSION,
    raw_data: {
      collection_mode: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      module: 'meituan_stats',
      snapshot_time_source: snapshot.source,
      metrics: compactObject({
        exposure_users: metricRawValue(metrics, ['exposureUsers', 'listExposure', 'impressions', 'exposure_count']),
        browse_users: metricRawValue(metrics, ['browseUsers', 'detailExposure', 'visitors', 'uv', 'clicks']),
        paid_orders: metricRawValue(metrics, ['paidOrders', 'orderSubmitNum', 'orderCount', 'orders']),
        exposure_browse_rate: metricRawValue(metrics, ['exposureBrowseRate', 'ctr']),
        browse_pay_rate: metricRawValue(metrics, ['browsePayRate', 'conversionRate', 'flowRate']),
      }),
      field_facts: [
        fieldFact('exposure_users', 'traffic', `${sourcePath}.exposureUsers`, 'online_daily_data.list_exposure', exposureUsers),
        fieldFact('browse_users', 'traffic', `${sourcePath}.browseUsers`, 'online_daily_data.detail_exposure', browseUsers),
        fieldFact('paid_orders', 'traffic', `${sourcePath}.paidOrders`, 'online_daily_data.order_submit_num', paidOrders, 'optional_missing'),
        fieldFact('exposure_browse_rate', 'traffic', `${sourcePath}.exposureBrowseRate`, 'online_daily_data.raw_data.metrics.exposure_browse_rate', exposureBrowseRate, 'optional_missing'),
        fieldFact('browse_pay_rate', 'traffic', `${sourcePath}.browsePayRate`, 'online_daily_data.flow_rate', browsePayRate),
      ],
    },
  });

  if (!hasObjectValue(row.raw_data.metrics)) {
    warnings.push({
      platform: 'meituan',
      module: 'meituan_stats',
      code: 'metrics_missing',
      message: 'Meituan realtime metrics section had no importable values.',
    });
    return [];
  }
  return [attachEvidence(row, 'meituan', 'traffic', sourcePath, 'meituan_stats', section)];
}

function normalizeMeituanHookSection(section, context, warnings) {
  const root = unwrapMeituanHookRoot(section);
  if (!root) {
    return [];
  }

  const rows = [];
  for (const [key, item] of Object.entries(root)) {
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      continue;
    }
    if (key === 'OWN_METRICS' || key === 'OWN_TODAY') {
      warnings.push({
        platform: 'meituan',
        module: 'meituan_hook',
        code: 'own_metrics_not_imported',
        source_path: `meituan_hook.${key}`,
        message: 'Meituan hook own metrics are retained as context only; they are not imported as confirmed revenue facts.',
      });
      continue;
    }
    if (isMeituanHookPeerItem(key, item)) {
      rows.push(...normalizeMeituanHookPeerItem(key, item, context, warnings));
      continue;
    }
    if (isMeituanHookForecastItem(key, item)) {
      rows.push(...normalizeMeituanHookForecastItem(key, item, context, warnings));
      continue;
    }
    if (isMeituanHookKeywordItem(key, item)) {
      rows.push(...normalizeMeituanHookKeywordItem(key, item, context, warnings));
      continue;
    }
    if (isMeituanHookFlowItem(key, item)) {
      rows.push(...normalizeMeituanHookFlowItem(key, item, context, warnings));
    }
  }

  if (rows.length === 0 && hasMeituanHookShape(root)) {
    warnings.push({
      platform: 'meituan',
      module: 'meituan_hook',
      code: 'hook_rows_missing',
      message: 'Meituan hook payload was detected but no importable rows were found.',
    });
  }
  return rows;
}

function normalizeMeituanHookPeerItem(key, item, context, warnings) {
  const data = hookData(item);
  const sections = Array.isArray(data.peerRankData) ? data.peerRankData : [];
  if (sections.length === 0) {
    warnings.push({
      platform: 'meituan',
      module: 'meituan_hook_peer_rank',
      code: 'peer_rank_rows_missing',
      source_path: `meituan_hook.${key}.data.peerRankData`,
      message: 'Meituan hook peer rank item has no peerRankData rows.',
    });
    return [];
  }

  const snapshot = resolveSnapshot(item, context);
  const dateRange = cleanText(firstDefined(item.dateRange, item.date_range, hookKeyPart(key, 2)));
  const rankType = cleanText(firstDefined(item.rankType, item.rank_type, hookKeyRankType(key)));
  const rows = [];
  sections.forEach((section, sectionIndex) => {
    if (!section || typeof section !== 'object' || Array.isArray(section)) {
      return;
    }
    const dimName = cleanText(firstDefined(section.dimName, section.dimension, section.metricName, section.aiMetricName));
    const rankRows = Array.isArray(section.roundRanks)
      ? section.roundRanks
      : Array.isArray(section.roundRank)
        ? section.roundRank
        : Array.isArray(section.ranks)
          ? section.ranks
          : Array.isArray(section.list)
            ? section.list
            : [];
    rankRows.forEach((rankRow, rowIndex) => {
      if (!rankRow || typeof rankRow !== 'object' || Array.isArray(rankRow)) {
        return;
      }
      const sourcePath = `meituan_hook.${key}.data.peerRankData.${sectionIndex}.roundRanks.${rowIndex}`;
      const dataDate = hookDataDate(item, context, snapshot, dateRange);
      const rank = toNumber(firstDefined(rankRow.rank, rankRow.ranking, rankRow.rankValue, rankRow.sort));
      const percent = toNumber(firstDefined(rankRow.percent, rankRow.ratio, rankRow.rank_percent, rankRow.rankPercent));
      const metricValue = toNumber(firstDefined(rankRow.dataValue, rankRow.data_value, rankRow.value, rankRow.metric_value));
      rows.push(attachEvidence(compactObject({
        source: 'meituan',
        platform: 'meituan',
        data_type: 'peer_rank',
        data_date: dataDate,
        data_period: hookPeriod(dateRange),
        snapshot_time: snapshot.snapshotTime,
        snapshot_bucket: snapshot.snapshotBucket,
        system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
        hotel_id: cleanText(firstDefined(rankRow.poiId, rankRow.poi_id, rankRow.hotelId, rankRow.hotel_id)),
        hotel_name: cleanText(firstDefined(rankRow.poiName, rankRow.poi_name, rankRow.hotelName, rankRow.hotel_name)),
        dimension: `peer_rank:${rankType || 'unknown'}:${dimName || 'unknown'}`,
        compare_type: 'competitor',
        rank_type: rankType || undefined,
        rank,
        rank_percent: percent,
        data_value: hasValue(metricValue) ? metricValue : rank,
        acquisition_method: COLLECTION_MODE,
        source_contract: CONTRACT_VERSION,
        raw_data: {
          collection_mode: COLLECTION_MODE,
          source_contract: CONTRACT_VERSION,
          module: 'meituan_hook_peer_rank',
          date_range: dateRange,
          date_range_name: cleanText(item.dateRangeName || item.date_range_name),
          rank_type_name: cleanText(item.rankTypeName || item.rank_type_name),
          dimension_name: dimName,
          snapshot_time_source: snapshot.source,
          peer_rank: compactObject({
            rank,
            percent,
            data_value: metricValue,
            poi_name: cleanText(firstDefined(rankRow.poiName, rankRow.poi_name, rankRow.hotelName, rankRow.hotel_name)),
            raw: rankRow,
          }),
          field_facts: [
            fieldFact('peer_rank', 'peer_rank', `${sourcePath}.rank`, 'online_daily_data.data_value/raw_data.peer_rank.rank', rank),
            fieldFact('peer_percent', 'peer_rank', `${sourcePath}.percent`, 'online_daily_data.raw_data.peer_rank.percent', percent, 'optional_missing'),
            fieldFact('peer_data_value', 'peer_rank', `${sourcePath}.dataValue`, 'online_daily_data.raw_data.peer_rank.data_value', metricValue, 'optional_missing'),
          ],
        },
      }), 'meituan', 'peer_rank', sourcePath, 'meituan_hook', item));
    });
  });
  return rows;
}

function normalizeMeituanHookFlowItem(key, item, context) {
  const data = hookData(item);
  const snapshot = resolveSnapshot(item, context);
  const dateRange = cleanText(firstDefined(item.dateRange, item.date_range, hookKeyPart(key, 2)));
  const dataDate = hookDataDate(item, context, snapshot, dateRange);
  const sourcePath = `meituan_hook.${key}.data`;
  const flowType = hookFlowType(key, item);
  if (flowType === 'conversion') {
    const exposeCount = toNumber(firstDefined(data.exposeCount, data.exposureCount, data.exposure));
    const visitCount = toNumber(firstDefined(data.visitCount, data.visitorCount, data.uv));
    const orderCount = toNumber(firstDefined(data.orderCount, data.payOrderCount, data.orders));
    const visitOrderRate = toNumber(firstDefined(data.visitOrderRate, data.conversionRate, data.orderConversionRate));
    return [attachEvidence(compactObject({
      source: 'meituan',
      platform: 'meituan',
      data_type: 'traffic_analysis',
      data_date: dataDate,
      data_period: hookPeriod(dateRange),
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
      hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
      dimension: 'traffic_analysis:flow_conversion',
      analysis_type: 'conversion_funnel',
      list_exposure: exposeCount,
      detail_exposure: visitCount,
      order_submit_num: orderCount,
      order_filling_num: orderCount,
      flow_rate: visitOrderRate,
      data_value: hasValue(orderCount) ? orderCount : visitOrderRate,
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: hookRawData(item, 'meituan_hook_flow_conversion', dateRange, snapshot, data),
    }), 'meituan', 'traffic_analysis', sourcePath, 'meituan_hook', item)];
  }

  const list = Array.isArray(data.list) ? data.list : [];
  if (list.length === 0) {
    return [attachEvidence(compactObject({
      source: 'meituan',
      platform: 'meituan',
      data_type: 'traffic_analysis',
      data_date: dataDate,
      data_period: hookPeriod(dateRange),
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
      hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
      dimension: `traffic_analysis:${flowType}`,
      analysis_type: flowType,
      data_value: toNumber(firstDefined(data.value, data.dataValue, data.exposeCount, data.visitCount, data.orderCount)),
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: hookRawData(item, `meituan_hook_flow_${flowType}`, dateRange, snapshot, data),
    }), 'meituan', 'traffic_analysis', sourcePath, 'meituan_hook', item)];
  }

  return list.map((row, index) => {
    const itemSourcePath = `${sourcePath}.list.${index}`;
    return attachEvidence(compactObject({
      source: 'meituan',
      platform: 'meituan',
      data_type: 'traffic_analysis',
      data_date: dataDate,
      data_period: hookPeriod(dateRange),
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
      hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
      dimension: `traffic_analysis:${flowType}:${cleanText(row.name || row.dimension || index)}`,
      analysis_type: flowType,
      list_exposure: toNumber(firstDefined(row.exposeCount, row.exposureCount, row.value)),
      detail_exposure: toNumber(firstDefined(row.visitCount, row.visitorCount, row.uv)),
      flow_rate: toNumber(firstDefined(row.visitOrderRate, row.conversionRate, row.flowRate)),
      data_value: toNumber(firstDefined(row.value, row.dataValue, row.current)),
      peer_rank: toNumber(firstDefined(row.rank, row.peerRank)),
      week_over_week: toNumber(firstDefined(row.weekOverWeek, row.wow)),
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: hookRawData(item, `meituan_hook_flow_${flowType}`, dateRange, snapshot, row),
    }), 'meituan', 'traffic_analysis', itemSourcePath, 'meituan_hook', item);
  });
}

function normalizeMeituanHookForecastItem(key, item, context) {
  const data = hookData(item);
  const snapshot = resolveSnapshot(item, context);
  const forecastType = cleanText(firstDefined(item.forecastType, item.forecast_type, hookKeyPart(key, 1), data.type));
  const detail = Array.isArray(data.detail) ? data.detail : [];
  const rows = detail.length ? detail : [data];
  return rows.map((row, index) => {
    const sourcePath = detail.length ? `meituan_hook.${key}.data.detail.${index}` : `meituan_hook.${key}.data`;
    const dataDate = normalizeDate(firstDefined(row.dateTime, row.date, row.dataDate, row.statDate))
      || hookDataDate(item, context, snapshot, '');
    return attachEvidence(compactObject({
      source: 'meituan',
      platform: 'meituan',
      data_type: 'traffic_forecast',
      data_date: dataDate,
      data_period: 'next_30_days',
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
      hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
      dimension: `traffic_forecast:${forecastType || 'flow_forecast'}`,
      forecast_type: forecastType || undefined,
      compare_type: 'forecast',
      data_value: toNumber(firstDefined(row.current, row.dataValue, row.value)),
      peer_avg: toNumber(firstDefined(row.peerAvg, row.peer_avg, row.competitorAvg, row.competitor_avg)),
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: hookRawData(item, 'meituan_hook_traffic_forecast', '', snapshot, row),
    }), 'meituan', 'traffic_forecast', sourcePath, 'meituan_hook', item);
  });
}

function normalizeMeituanHookKeywordItem(key, item, context) {
  const data = hookData(item);
  const snapshot = resolveSnapshot(item, context);
  const dataDate = hookDataDate(item, context, snapshot, '');
  const cards = Array.isArray(data.cards) ? data.cards : [];
  const rows = [];
  cards.forEach((card, cardIndex) => {
    if (!card || typeof card !== 'object' || Array.isArray(card)) {
      return;
    }
    const items = Array.isArray(card.itemList)
      ? card.itemList
      : Array.isArray(card.items)
        ? card.items
        : Array.isArray(card.list)
          ? card.list
          : [];
    items.forEach((keywordRow, rowIndex) => {
      const keyword = cleanText(firstDefined(keywordRow.name, keywordRow.keyword, keywordRow.searchKeyword, keywordRow.searchWord));
      const sourcePath = `meituan_hook.${key}.data.cards.${cardIndex}.itemList.${rowIndex}`;
      rows.push(attachEvidence(compactObject({
        source: 'meituan',
        platform: 'meituan',
        data_type: 'search_keyword',
        data_date: dataDate,
        data_period: 'snapshot',
        snapshot_time: snapshot.snapshotTime,
        snapshot_bucket: snapshot.snapshotBucket,
        system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
        hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
        dimension: keyword || 'search_keyword',
        keyword: keyword || undefined,
        keyword_group: cleanText(firstDefined(card.title, card.name)),
        data_value: toNumber(firstDefined(keywordRow.value, keywordRow.dataValue, keywordRow.heat, keywordRow.rank)),
        list_exposure: toNumber(firstDefined(keywordRow.impressions, keywordRow.exposure, keywordRow.exposureCount)),
        detail_exposure: toNumber(firstDefined(keywordRow.clicks, keywordRow.clickCount, keywordRow.detailExposure)),
        acquisition_method: COLLECTION_MODE,
        source_contract: CONTRACT_VERSION,
        raw_data: hookRawData(item, 'meituan_hook_search_keyword', '', snapshot, keywordRow),
      }), 'meituan', 'search_keyword', sourcePath, 'meituan_hook', item));
    });
  });

  if (rows.length) {
    return rows;
  }
  const list = Array.isArray(data.list) ? data.list : [];
  return list.map((keywordRow, index) => {
    const keyword = cleanText(firstDefined(keywordRow.keyword, keywordRow.searchKeyword, keywordRow.searchWord, keywordRow.name));
    const sourcePath = `meituan_hook.${key}.data.list.${index}`;
    return attachEvidence(compactObject({
      source: 'meituan',
      platform: 'meituan',
      data_type: 'search_keyword',
      data_date: dataDate,
      data_period: 'snapshot',
      snapshot_time: snapshot.snapshotTime,
      snapshot_bucket: snapshot.snapshotBucket,
      system_hotel_id: toInteger(firstDefined(item.system_hotel_id, item.systemHotelId, context.systemHotelId)) || undefined,
      hotel_name: cleanText(firstDefined(item.hotelName, item.hotel_name, context.hotelName)) || undefined,
      dimension: keyword || 'search_keyword',
      keyword: keyword || undefined,
      data_value: toNumber(firstDefined(keywordRow.value, keywordRow.dataValue, keywordRow.heat, keywordRow.rank)),
      acquisition_method: COLLECTION_MODE,
      source_contract: CONTRACT_VERSION,
      raw_data: hookRawData(item, 'meituan_hook_search_keyword', '', snapshot, keywordRow),
    }), 'meituan', 'search_keyword', sourcePath, 'meituan_hook', item);
  });
}

function hookRawData(item, module, dateRange, snapshot, data) {
  return {
    collection_mode: COLLECTION_MODE,
    source_contract: CONTRACT_VERSION,
    module,
    date_range: dateRange || undefined,
    date_range_name: cleanText(item.dateRangeName || item.date_range_name) || undefined,
    source: cleanText(item.source) || undefined,
    snapshot_time_source: snapshot.source,
    quality_status: module.includes('forecast') ? 'signal_only' : undefined,
    data,
  };
}

function resolveMeituanHookSection(payload) {
  for (const key of ['meituanHook', 'meituanPeerHook', 'meituanTrafficHook', 'meituanCompetitorHook', 'captured']) {
    if (payload?.[key] && hasMeituanHookShape(payload[key])) {
      return payload[key];
    }
  }
  return hasMeituanHookShape(payload) ? payload : null;
}

function unwrapMeituanHookRoot(section) {
  if (!section || typeof section !== 'object' || Array.isArray(section)) {
    return null;
  }
  if (section.captured && hasMeituanHookShape(section.captured)) {
    return section.captured;
  }
  return hasMeituanHookShape(section) ? section : null;
}

function hasMeituanHookShape(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return false;
  }
  return Object.entries(value).some(([key, item]) => {
    if (/^(P_[A-Z]+_\d+|FLOW_[A-Z]+_\d+|FORECAST_\d+|KEYWORDS|OWN_METRICS|OWN_TODAY)$/.test(key)) {
      return true;
    }
    if (!item || typeof item !== 'object' || Array.isArray(item)) {
      return false;
    }
    const source = cleanText(item.source).toLowerCase();
    return ['peer', 'flow', 'forecast', 'keywords'].includes(source);
  });
}

function isMeituanHookPeerItem(key, item) {
  return /^P_[A-Z]+_\d+$/.test(key)
    || cleanText(item.source).toLowerCase() === 'peer'
    || Array.isArray(hookData(item).peerRankData);
}

function isMeituanHookFlowItem(key, item) {
  return /^FLOW_[A-Z]+_\d+$/.test(key) || cleanText(item.source).toLowerCase() === 'flow';
}

function isMeituanHookForecastItem(key, item) {
  return /^FORECAST_\d+$/.test(key) || cleanText(item.source).toLowerCase() === 'forecast';
}

function isMeituanHookKeywordItem(key, item) {
  return key === 'KEYWORDS' || cleanText(item.source).toLowerCase() === 'keywords';
}

function hookData(item) {
  return item && typeof item.data === 'object' && !Array.isArray(item.data) ? item.data : item;
}

function hookKeyPart(key, index) {
  return String(key || '').split('_')[index] || '';
}

function hookKeyRankType(key) {
  const parts = String(key || '').split('_');
  return parts.length >= 3 ? `${parts[0]}_${parts[1]}` : '';
}

function hookFlowType(key, item) {
  const value = String(key || item.rankType || item.rank_type || '').toUpperCase();
  if (value.includes('CONV')) return 'conversion';
  if (value.includes('SRC')) return 'source';
  if (value.includes('TREND')) return 'trend';
  if (value.includes('DOM')) return 'dom_snapshot';
  return 'flow_analysis';
}

function hookDataDate(item, context, snapshot, dateRange) {
  return normalizeDate(firstDefined(item.data_date, item.dataDate, context.dataDate))
    || dateFromDateTime(snapshot.snapshotTime)
    || (String(dateRange || '') === '0' ? normalizeDate(context.generatedAt) : '');
}

function hookPeriod(dateRange) {
  return {
    0: 'realtime_snapshot',
    1: 'yesterday',
    7: 'last_7_days',
    30: 'last_30_days',
  }[String(dateRange || '')] || 'snapshot';
}

function attachEvidence(row, platform, section, sourcePath, captureSource, originalSection) {
  return attachOtaCaptureEvidence(row, platform, {
    section,
    sourcePath,
    captureSource: `${COLLECTION_MODE}:${captureSource}`,
    url: originalSection?.url || originalSection?.sourceUrl || '',
  });
}

function fieldFact(metricKey, dataType, sourcePath, storageField, value, missingState = 'field_missing') {
  const present = hasValue(value);
  return compactObject({
    metric_key: metricKey,
    data_type: dataType,
    source_path: sourcePath,
    storage_table: 'online_daily_data',
    storage_field: storageField,
    status: present ? 'captured' : 'missing',
    missing_state: present ? '' : missingState,
    stored_value_present: present,
    value: present ? value : undefined,
  });
}

function missingInventoryFields({ roomName, remain, state }) {
  const fields = [];
  if (!roomName) {
    fields.push({ field: 'room_name', missing_state: 'field_missing' });
  }
  if (!hasValue(remain)) {
    fields.push({ field: 'remain', missing_state: 'field_missing' });
  }
  if (!state) {
    fields.push({ field: 'state', missing_state: 'optional_missing' });
  }
  return fields;
}

function resolveSnapshot(section, context) {
  const explicit = normalizeDateTime(firstDefined(section.updatedAt, section.updated_at, section.capturedAt, section.captured_at, section.snapshot_time, section.snapshotTime, context.snapshotTime));
  if (explicit) {
    return {
      snapshotTime: explicit,
      snapshotBucket: snapshotBucket(explicit),
      source: 'source_timestamp',
    };
  }
  return {
    snapshotTime: context.generatedAt,
    snapshotBucket: snapshotBucket(context.generatedAt),
    source: 'normalizer_generated_at',
  };
}

function resolveRealtimeDate(section, context, snapshot) {
  return normalizeDate(firstDefined(section.data_date, section.dataDate, context.dataDate)) || dateFromDateTime(snapshot.snapshotTime);
}

function unwrapPayload(input) {
  if (!input || typeof input !== 'object') {
    return {};
  }
  for (const key of ['data', 'payload', 'capture']) {
    const child = input[key];
    if (!child || typeof child !== 'object' || Array.isArray(child)) {
      continue;
    }
    if (KNOWN_SECTION_KEYS.some((sectionKey) => child[sectionKey]) || hasMeituanHookShape(child)) {
      return { ...input, ...child };
    }
  }
  return input;
}

function buildMetricEntries(metrics) {
  return Object.entries(metrics || {}).map(([key, item]) => [key, item]);
}

function metricRawValue(metrics, keys) {
  for (const key of keys) {
    if (!Object.prototype.hasOwnProperty.call(metrics || {}, key)) {
      continue;
    }
    const item = metrics[key];
    if (item && typeof item === 'object' && !Array.isArray(item)) {
      const value = firstDefined(item.value, item.text, item.labelValue, item.rawValue);
      if (hasValue(value)) {
        return value;
      }
      continue;
    }
    if (hasValue(item) && (typeof item !== 'object' || item === null)) {
      return item;
    }
  }

  const loweredKeys = keys.map((key) => key.toLowerCase());
  for (const [key, item] of buildMetricEntries(metrics)) {
    if (!loweredKeys.includes(String(key).toLowerCase())) {
      continue;
    }
    if (item && typeof item === 'object' && !Array.isArray(item)) {
      const value = firstDefined(item.value, item.text, item.labelValue, item.rawValue);
      return hasValue(value) ? value : undefined;
    }
    return item;
  }
  return undefined;
}

function metricNumber(metrics, keys) {
  return toNumber(metricRawValue(metrics, keys));
}

function normalizeDate(value) {
  if (!hasValue(value)) {
    return '';
  }
  const text = String(value).trim();
  let match = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/);
  if (!match) {
    match = text.match(/^(\d{4})(\d{2})(\d{2})$/);
  }
  if (!match) {
    return '';
  }
  const month = String(Number(match[2])).padStart(2, '0');
  const day = String(Number(match[3])).padStart(2, '0');
  return `${match[1]}-${month}-${day}`;
}

function normalizeDateTime(value) {
  if (!hasValue(value)) {
    return '';
  }
  if (value instanceof Date) {
    return formatDateTime(value);
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return formatDateTime(new Date(value > 100000000000 ? value : value * 1000));
  }
  const text = String(value).trim();
  const match = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/);
  if (!match) {
    return '';
  }
  const date = normalizeDate(`${match[1]}-${match[2]}-${match[3]}`);
  const hour = String(Number(match[4] || 0)).padStart(2, '0');
  const minute = String(Number(match[5] || 0)).padStart(2, '0');
  const second = String(Number(match[6] || 0)).padStart(2, '0');
  return `${date} ${hour}:${minute}:${second}`;
}

function formatDateTime(date) {
  if (Number.isNaN(date.getTime())) {
    return '';
  }
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  const hh = String(date.getHours()).padStart(2, '0');
  const mi = String(date.getMinutes()).padStart(2, '0');
  const ss = String(date.getSeconds()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
}

function dateFromDateTime(value) {
  return normalizeDate(value);
}

function snapshotBucket(value) {
  const normalized = normalizeDateTime(value);
  if (!normalized) {
    return '';
  }
  return normalized.replace(/\D/g, '').slice(0, 12);
}

function toNumber(value) {
  if (!hasValue(value)) {
    return undefined;
  }
  if (typeof value === 'number') {
    return Number.isFinite(value) ? value : undefined;
  }
  const text = String(value).replace(/,/g, '').trim();
  const match = text.match(/-?\d+(?:\.\d+)?/);
  if (!match) {
    return undefined;
  }
  const number = Number(match[0]);
  return Number.isFinite(number) ? number : undefined;
}

function toInteger(value) {
  const number = toNumber(value);
  return Number.isFinite(number) ? Math.trunc(number) : undefined;
}

function cleanText(value) {
  if (!hasValue(value)) {
    return '';
  }
  return String(value).replace(/\s+/g, ' ').trim();
}

function firstDefined(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null && value !== '') {
      return value;
    }
  }
  return undefined;
}

function hasValue(value) {
  if (value === null || value === undefined) {
    return false;
  }
  if (typeof value === 'number') {
    return Number.isFinite(value);
  }
  if (typeof value === 'string') {
    const text = value.trim();
    return text !== '' && !['-', '--', 'null', 'undefined', '暂无', '无'].includes(text.toLowerCase());
  }
  return true;
}

function hasObjectValue(value) {
  if (!value || typeof value !== 'object') {
    return false;
  }
  return Object.values(value).some(hasValue);
}

function compactObject(value) {
  if (Array.isArray(value)) {
    return value.map(compactObject);
  }
  if (!value || typeof value !== 'object') {
    return value;
  }
  const next = {};
  for (const [key, item] of Object.entries(value)) {
    if (item === undefined) {
      continue;
    }
    next[key] = compactObject(item);
  }
  return next;
}

function unique(values) {
  return Array.from(new Set(values));
}

export function stableHash(value) {
  return createHash('sha256').update(JSON.stringify(value)).digest('hex');
}
