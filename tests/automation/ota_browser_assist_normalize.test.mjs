import assert from 'node:assert/strict';
import test from 'node:test';

import { normalizeBrowserAssistCapturePayload } from '../../scripts/lib/ota_browser_assist_normalize.mjs';

const syntheticRealtimeCapture = () => ({
  system_hotel_id: 80,
  hotel_name: 'Synthetic date-source hotel',
  ctripStats: { metrics: { ctrip: { realtimeVisitors: 0 } } },
  meituanStats: { metrics: { browseUsers: 0 } },
});
const syntheticOptions = { generatedAt: '2026-09-15 10:00:00' };

test('undated realtime metrics never use normalizer generation time as their business day', () => {
  const result = normalizeBrowserAssistCapturePayload(syntheticRealtimeCapture(), syntheticOptions);
  assert.equal(result.rows.length, 0);
  assert.equal(result.packages.length, 0);
  assert.equal(result.generated_at, syntheticOptions.generatedAt);
  assert.deepEqual(result.warnings.filter(item => item.code === 'data_date_missing').map(item => item.platform), ['ctrip', 'meituan']);
});

test('explicit invalid or relative business dates never fall back to a valid source snapshot', () => {
  for (const date of ['2026-02-30', '2026/13/01', '20260229', '2026-09-15junk',
    '2026-09-15 +1 day', 'tomorrow', 'today', '2026-09', '2026-09-15 24:00:00']) {
    for (const placement of ['payload', 'option', 'section']) {
      const payload = syntheticRealtimeCapture();
      payload.snapshot_time = '2026-08-23 21:00:00';
      const options = { ...syntheticOptions };
      if (placement === 'payload') payload.data_date = date;
      if (placement === 'option') options.dataDate = date;
      if (placement === 'section') {
        payload.ctripStats.dataDate = date;
        payload.meituanStats.dataDate = date;
      }
      const result = normalizeBrowserAssistCapturePayload(payload, options);
      assert.equal(result.rows.length, 0, `${placement}: ${date}`);
      assert.equal(result.packages.length, 0, `${placement}: ${date}`);
    }
  }
});

test('realtime metric dates skip blank section fields but keep invalid explicit dates distinct', () => {
  const validContext = syntheticRealtimeCapture();
  validContext.data_date = '2026-08-23';
  validContext.ctripStats.dataDate = '   ';
  validContext.ctripStats.updatedAt = '2026-08-24 12:00:00';
  validContext.meituanStats.data_date = '';
  validContext.meituanStats.updatedAt = '2026-08-25 12:00:00';
  const valid = normalizeBrowserAssistCapturePayload(validContext, syntheticOptions);
  assert.deepEqual(valid.rows.map(row => row.platform), ['ctrip', 'meituan']);
  assert.deepEqual(valid.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23']);
  assert.deepEqual(valid.rows.map(row => row.snapshot_time), ['2026-08-24 12:00:00', '2026-08-25 12:00:00']);
  assert.deepEqual(valid.rows.map(row => row.detail_exposure), [0, 0]);
  assert.deepEqual(valid.warnings.filter(item => item.code.startsWith('data_date_') || item.code === 'source_timestamp_invalid'), []);
  assert.deepEqual(valid.packages.map(group => group.platform), ['ctrip', 'meituan']);

  const invalidSection = structuredClone(validContext);
  invalidSection.ctripStats.dataDate = '2026-02-30';
  invalidSection.meituanStats.data_date = '2026-02-30';
  const invalidContext = structuredClone(validContext);
  invalidContext.data_date = '2026-02-30';
  invalidContext.ctripStats.dataDate = ' ';
  invalidContext.meituanStats.data_date = '';
  for (const payload of [invalidSection, invalidContext]) {
    const result = normalizeBrowserAssistCapturePayload(payload, syntheticOptions);
    assert.deepEqual(result.rows, []);
    assert.deepEqual(result.packages, []);
    const warnings = result.warnings.filter(item => item.code.startsWith('data_date_'));
    assert.deepEqual(warnings.map(item => item.code), ['data_date_invalid', 'data_date_invalid']);
    assert.deepEqual(warnings.map(item => item.platform), ['ctrip', 'meituan']);
    assert.deepEqual(warnings.map(item => item.module), ['ctrip_stats', 'meituan_stats']);
    assert.deepEqual(warnings.map(item => item.source_path), ['ctrip_stats.metrics.ctrip', 'meituan_stats.metrics']);
    for (const warning of warnings) {
      assert.match(warning.message, /explicit business date is invalid/);
      assert.match(warning.message, /snapshot time was not used as a fallback/);
    }
  }

  const contextTimestamp = syntheticRealtimeCapture();
  contextTimestamp.snapshot_time = '2026-08-23 23:30:00';
  contextTimestamp.ctripStats.updatedAt = ' ';
  contextTimestamp.meituanStats.updatedAt = '';
  const timestampRows = normalizeBrowserAssistCapturePayload(contextTimestamp, syntheticOptions);
  assert.deepEqual(timestampRows.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23']);
  assert.deepEqual(timestampRows.rows.map(row => row.snapshot_time), ['2026-08-23 23:30:00', '2026-08-23 23:30:00']);

  const invalidTimestamp = syntheticRealtimeCapture();
  invalidTimestamp.ctripStats.updatedAt = '2026-02-30 12:00:00';
  invalidTimestamp.meituanStats.updatedAt = '2026-02-30 12:00:00';
  const timestampFailure = normalizeBrowserAssistCapturePayload(invalidTimestamp, syntheticOptions);
  assert.deepEqual(timestampFailure.rows, []);
  const warnings = timestampFailure.warnings.filter(item => item.code === 'source_timestamp_invalid');
  assert.deepEqual(warnings.map(item => item.code), ['source_timestamp_invalid', 'source_timestamp_invalid']);
  assert.deepEqual(warnings.map(item => [item.platform, item.module, item.source_path]), [
    ['ctrip', 'ctrip_stats', 'ctrip_stats.metrics.ctrip'],
    ['meituan', 'meituan_stats', 'meituan_stats.metrics'],
  ]);
  for (const warning of warnings) {
    assert.match(warning.message, /source timestamp is invalid/);
    assert.match(warning.message, /normalizer-generated time was not used as a business date/);
  }

  const topLevelTimestamp = syntheticRealtimeCapture();
  topLevelTimestamp.snapshot_time = '2026-02-30 12:00:00';
  const topLevelFailure = normalizeBrowserAssistCapturePayload(topLevelTimestamp, syntheticOptions);
  assert.deepEqual(topLevelFailure.rows, []);
  const topLevelWarnings = topLevelFailure.warnings.filter(item => item.code === 'source_timestamp_invalid');
  assert.deepEqual(topLevelWarnings.map(item => item.code), ['source_timestamp_invalid', 'source_timestamp_invalid']);
  assert.deepEqual(topLevelWarnings.map(item => item.module), ['ctrip_stats', 'meituan_stats']);

  const wrappedHook = normalizeBrowserAssistCapturePayload({ generatedAt: syntheticOptions.generatedAt, capture: {
    snapshot_time: '2026-02-30 12:00:00',
    FLOW_CONV_0: { rankType: 'FLOW_CONV', dateRange: '0', source: 'synthetic', data: { visitCount: 0 } },
  } }, syntheticOptions);
  assert.deepEqual(wrappedHook.rows, []);
  const wrappedWarnings = wrappedHook.warnings.filter(item => item.code === 'source_timestamp_invalid');
  assert.deepEqual(wrappedWarnings.map(item => [item.module, item.source_path]), [
    ['meituan_hook_flow_conversion', 'meituan_hook.FLOW_CONV_0.data'],
  ]);

  const missing = normalizeBrowserAssistCapturePayload(syntheticRealtimeCapture(), syntheticOptions);
  assert.deepEqual(missing.rows, []);
  assert.deepEqual(missing.warnings.filter(item => item.code.startsWith('data_date_')).map(item => item.code), ['data_date_missing', 'data_date_missing']);
});

test('blank business-date context defers to aliases and capture while invalid higher-priority dates fail closed', () => {
  const aliasPayload = syntheticRealtimeCapture();
  aliasPayload.data_date = '  ';
  aliasPayload.dataDate = '2026-08-23';
  const aliasResult = normalizeBrowserAssistCapturePayload(aliasPayload, syntheticOptions);
  assert.deepEqual(aliasResult.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23']);
  assert.deepEqual(aliasResult.rows.map(row => row.detail_exposure), [0, 0]);
  assert.deepEqual(aliasResult.warnings.filter(item => item.code.startsWith('data_date_')), []);

  const optionPayload = syntheticRealtimeCapture();
  optionPayload.data_date = '2026-08-23';
  const optionResult = normalizeBrowserAssistCapturePayload(optionPayload,
    { ...syntheticOptions, dataDate: '  ' });
  assert.deepEqual(optionResult.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23']);
  const invalidOption = normalizeBrowserAssistCapturePayload(optionPayload,
    { ...syntheticOptions, dataDate: '2026-02-30' });
  assert.deepEqual(invalidOption.rows, []);
  assert.deepEqual(invalidOption.warnings.filter(item => item.code.startsWith('data_date_')).map(item => item.code),
    ['data_date_invalid', 'data_date_invalid']);

  const wrappedPayload = {
    system_hotel_id: 80,
    data_date: '',
    capture: {
      data_date: '2026-08-23',
      ctripStats: { metrics: { ctrip: { realtimeVisitors: 0 } } },
      meituanStats: { metrics: { browseUsers: 0 } },
    },
  };
  const wrappedResult = normalizeBrowserAssistCapturePayload(wrappedPayload, syntheticOptions);
  assert.deepEqual(wrappedResult.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23']);
  assert.deepEqual(wrappedResult.rows.map(row => row.system_hotel_id), [80, 80]);

  wrappedPayload.data_date = '2026-02-30';
  const invalidResult = normalizeBrowserAssistCapturePayload(wrappedPayload, syntheticOptions);
  assert.deepEqual(invalidResult.rows, []);
  assert.deepEqual(invalidResult.warnings.filter(item => item.code.startsWith('data_date_')).map(item => item.code),
    ['data_date_invalid', 'data_date_invalid']);
});

test('invalid or relative source timestamps cannot create importable realtime facts', () => {
  for (const timestamp of ['2026-02-30 12:00:00', '2026-09-15 24:00:00',
    '2026-09-15 12:60', '2026-09-15 12:00:60', '2026-09-15 12:00:00junk',
    '2026-09-15 +1 day', 'tomorrow', '2026-09-15T12:00:00+08:99', new Date('invalid')]) {
    const payload = syntheticRealtimeCapture();
    payload.ctripStats.updatedAt = timestamp;
    payload.snapshot_time = timestamp;
    const result = normalizeBrowserAssistCapturePayload(payload, syntheticOptions);
    assert.equal(result.rows.length, 0, String(timestamp));
    assert.equal(result.packages.length, 0, String(timestamp));
  }
});

test('inventory rejects impossible dates and keeps valid leap day inventory zero', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: { capturedAt: '2026-08-23 12:00:00', rooms: [{ name: 'Synthetic room', days: [
      { date: '2026-02-30', remain: 4 }, { date: '2024/02/29', remain: 0 }, { remain: 3 },
    ] }] },
  }, { ...syntheticOptions, systemHotelId: 80 });
  assert.equal(result.rows.length, 1);
  assert.equal(result.rows[0].data_date, '2024-02-29');
  assert.equal(result.rows[0].inventory_remaining, 0);
  assert.equal(result.rows[0].raw_data.snapshot_time_source, 'source_timestamp');
  const dateWarnings = result.warnings.filter(item => item.code.startsWith('data_date_'));
  assert.deepEqual(dateWarnings.map(item => item.code), ['data_date_invalid', 'data_date_missing']);
  assert.deepEqual(dateWarnings.map(item => item.platform), ['ctrip', 'ctrip']);
  assert.deepEqual(dateWarnings.map(item => item.module), ['ctrip_inventory', 'ctrip_inventory']);
  assert.deepEqual(dateWarnings.map(item => item.source_path), [
    'ctrip_inventory.rooms.0.days.0', 'ctrip_inventory.rooms.0.days.2',
  ]);
  assert.match(dateWarnings[0].message, /explicit business date is invalid/);
  assert.match(dateWarnings[0].message, /snapshot time was not used as a fallback/);
  assert.match(dateWarnings[1].message, /no data_date could be proven/);
});

test('inventory inherits section dates only for blank day dates', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: { data_date: '2026-08-23', capturedAt: '2026-08-23 12:00:00', rooms: [{ name: 'Synthetic Ctrip room', days: [
      { date: '', remain: 0 }, { date: '   ', remain: 1 }, { date: '2026-02-30', remain: 4 }, { date: '2026-08-24', remain: 2 },
    ] }] },
    meituan: { dataDate: '2024-02-29', capturedAt: '2026-08-23 12:00:00', rooms: [{ name: 'Synthetic Meituan room', days: [
      { date: '', remain: 0 }, { date: '2026-02-30', remain: 5 },
    ] }] },
  }, { ...syntheticOptions, systemHotelId: 80 });

  assert.deepEqual(result.rows.map(row => row.platform), ['ctrip', 'ctrip', 'ctrip', 'meituan']);
  assert.deepEqual(result.rows.map(row => row.data_date), ['2026-08-23', '2026-08-23', '2026-08-24', '2024-02-29']);
  assert.deepEqual(result.rows.map(row => row.inventory_remaining), [0, 1, 2, 0]);
  assert.deepEqual(result.rows.map(row => row.system_hotel_id), [80, 80, 80, 80]);
  assert.deepEqual(result.packages.map(group => group.platform), ['ctrip', 'meituan']);
  assert.deepEqual(result.packages.map(group => group.rows.length), [3, 1]);
  assert.deepEqual(result.warnings.filter(item => item.code === 'data_date_invalid').map(item => item.source_path), [
    'ctrip_inventory.rooms.0.days.2', 'meituan_inventory.rooms.0.days.1',
  ]);

  const invalidParent = normalizeBrowserAssistCapturePayload({
    ctrip: { data_date: '2026-02-30', capturedAt: '2026-08-23 12:00:00', rooms: [{ name: 'Synthetic invalid-parent room', days: [
      { date: ' ', remain: 0 },
    ] }] },
  }, syntheticOptions);
  assert.equal(invalidParent.rows.length, 0);
  assert.deepEqual(invalidParent.warnings.filter(item => item.code.startsWith('data_date_')).map(item => item.code), ['data_date_invalid']);

  const missing = normalizeBrowserAssistCapturePayload({
    ctrip: { capturedAt: '2026-08-23 12:00:00', rooms: [{ name: 'Synthetic undated room', days: [
      { date: '   ', remain: 3 },
    ] }] },
  }, syntheticOptions);
  assert.equal(missing.rows.length, 0);
  assert.deepEqual(missing.warnings.filter(item => item.code.startsWith('data_date_')).map(item => item.code), ['data_date_missing']);
});

test('hook rows require a source business day and invalid forecast days never borrow the capture day', () => {
  for (const [date, capturedAt, expectedCode] of [
    [undefined, undefined, 'data_date_missing'],
    ['tomorrow', '2026-08-23 12:00:00', 'data_date_invalid'],
    [undefined, '2026-02-30 12:00:00', 'source_timestamp_invalid'],
  ]) {
    const result = normalizeBrowserAssistCapturePayload({ capture: {
      FLOW_CONV_0: { rankType: 'FLOW_CONV', dateRange: '0', data_date: date, capturedAt, source: 'flow', data: { visitCount: 0 } },
    } }, syntheticOptions);
    assert.equal(result.rows.length, 0, `${expectedCode}: ${date || capturedAt || 'missing'}`);
    assert.equal(result.packages.length, 0, expectedCode);
    const dateWarnings = result.warnings.filter(item => item.code !== 'hook_rows_missing');
    assert.equal(dateWarnings.length, 1, expectedCode);
    assert.equal(dateWarnings[0].code, expectedCode);
    assert.equal(dateWarnings[0].platform, 'meituan');
    assert.equal(dateWarnings[0].module, 'meituan_hook_flow_conversion');
    assert.equal(dateWarnings[0].source_path, 'meituan_hook.FLOW_CONV_0.data');
    assert.match(dateWarnings[0].message, expectedCode === 'data_date_invalid'
      ? /snapshot time was not used as a fallback/
      : expectedCode === 'source_timestamp_invalid'
        ? /normalizer-generated time was not used as a business date/
        : /no data_date could be proven/);
    assert.ok(result.warnings.some(item => item.code === 'hook_rows_missing'));
  }
  const result = normalizeBrowserAssistCapturePayload({ capture: {
    FORECAST_2: { rankType: 'FORECAST', forecastType: '2', capturedAt: '2026-08-23 12:00:00', data: {
      detail: [{ dateTime: '2026-02-30', current: 9 }, { dateTime: '20260916', current: 0 }],
    } },
  } }, syntheticOptions);
  assert.equal(result.rows.length, 1);
  assert.equal(result.rows[0].data_date, '2026-09-16');
  assert.equal(result.rows[0].data_value, 0);
  assert.equal(result.rows[0].raw_data.quality_status, 'signal_only');
  const dateWarnings = result.warnings.filter(item => item.code === 'data_date_invalid');
  assert.equal(dateWarnings.length, 1);
  assert.equal(dateWarnings[0].platform, 'meituan');
  assert.equal(dateWarnings[0].module, 'meituan_hook_traffic_forecast');
  assert.equal(dateWarnings[0].source_path, 'meituan_hook.FORECAST_2.data.detail.0');
  assert.match(dateWarnings[0].message, /explicit target date is invalid/);
  assert.match(dateWarnings[0].message, /snapshot time was not used as a fallback/);
});

test('forecast detail without a proven date remains a missing-date warning', () => {
  const result = normalizeBrowserAssistCapturePayload({ capture: {
    FORECAST_2: { rankType: 'FORECAST', forecastType: '2', data: { detail: [{ current: 0 }] } },
  } }, syntheticOptions);
  assert.equal(result.rows.length, 0);
  assert.equal(result.packages.length, 0);
  const dateWarnings = result.warnings.filter(item => item.code === 'data_date_missing');
  assert.equal(dateWarnings.length, 1);
  assert.equal(dateWarnings[0].platform, 'meituan');
  assert.equal(dateWarnings[0].module, 'meituan_hook_traffic_forecast');
  assert.equal(dateWarnings[0].source_path, 'meituan_hook.FORECAST_2.data.detail.0');
  assert.match(dateWarnings[0].message, /no data_date could be proven/);
});

test('nested peer keyword and flow-source rows distinguish invalid parent dates from missing dates', () => {
  for (const [dataDate, capturedAt, expectedCode] of [
    ['2026-02-30', '2026-08-23 12:00:00', 'data_date_invalid'],
    [undefined, '2026-02-30 12:00:00', 'source_timestamp_invalid'],
    [undefined, undefined, 'data_date_missing'],
  ]) {
    const result = normalizeBrowserAssistCapturePayload({ capture: {
      P_RZ_0: { rankType: 'P_RZ', source: 'peer', data_date: dataDate, capturedAt, data: {
        peerRankData: [{ dimName: 'Synthetic ranking', roundRanks: [{ poiId: 'peer-1', rank: 2, dataValue: 0 }] }],
      } },
      KEYWORDS: { rankType: 'KEYWORDS', source: 'keywords', data_date: dataDate, capturedAt, data: {
        cards: [{ title: 'Synthetic terms', itemList: [{ name: 'airport', value: 0 }] }],
      } },
      FLOW_SRC_0: { rankType: 'FLOW_SRC', source: 'flow', data_date: dataDate, capturedAt, data: {
        list: [{ name: 'Synthetic organic exposure', value: 0 }],
      } },
    } }, syntheticOptions);
    assert.equal(result.rows.length, 0, expectedCode);
    assert.equal(result.packages.length, 0, expectedCode);
    const dateWarnings = result.warnings.filter(item => item.code === expectedCode);
    assert.equal(dateWarnings.length, 3, expectedCode);
    assert.deepEqual(dateWarnings.map(item => [item.platform, item.module, item.source_path]), [
      ['meituan', 'meituan_hook_peer_rank', 'meituan_hook.P_RZ_0.data.peerRankData.0.roundRanks.0'],
      ['meituan', 'meituan_hook_search_keyword', 'meituan_hook.KEYWORDS.data.cards.0.itemList.0'],
      ['meituan', 'meituan_hook_flow_source', 'meituan_hook.FLOW_SRC_0.data.list.0'],
    ]);
    if (expectedCode === 'data_date_invalid') {
      assert.match(dateWarnings[0].message, /explicit business date is invalid/);
      assert.match(dateWarnings[0].message, /snapshot time was not used as a fallback/);
    } else if (expectedCode === 'source_timestamp_invalid') {
      assert.match(dateWarnings[0].message, /source timestamp is invalid/);
      assert.match(dateWarnings[0].message, /normalizer-generated time was not used as a business date/);
    } else {
      assert.match(dateWarnings[0].message, /no data_date could be proven/);
    }
  }
});

test('explicit dates and valid source times keep hotel channel zero and provenance independent', () => {
  for (const date of ['2024-02-29', '2024/2/29', '2024.2.29', '20240229']) {
    const payload = syntheticRealtimeCapture();
    payload.data_date = date;
    payload.ctripStats.updatedAt = '2026-08-23 21:00:00';
    const original = structuredClone(payload);
    const result = normalizeBrowserAssistCapturePayload(payload, syntheticOptions);
    assert.deepEqual(result.rows.map(row => row.data_date), ['2024-02-29', '2024-02-29']);
    assert.deepEqual(result.rows.map(row => row.raw_data.snapshot_time_source), ['source_timestamp', 'normalizer_generated_at']);
    assert.deepEqual(result.rows.map(row => row.source), ['ctrip', 'meituan']);
    for (const row of result.rows) {
      assert.equal(row.system_hotel_id, 80);
      assert.equal(row.detail_exposure, 0);
      assert.match(row.capture_evidence.capture_source, /^browser_assist_dom:/);
    }
    assert.deepEqual(payload, original);
    assert.equal(result.generated_at, syntheticOptions.generatedAt);
  }
});

test('source offsets and numeric epochs identify the same local business date without changing explicit dates', () => {
  const originalTimezone = process.env.TZ;
  try {
    for (const [timezone, expected] of [['Asia/Shanghai', '2026-08-24 07:30:00'], ['UTC', '2026-08-23 23:30:00']]) {
      process.env.TZ = timezone;
      const epoch = Date.parse('2026-08-23T23:30:00Z');
      for (const timestamp of ['2026-08-23T23:30:00.123Z', '2026-08-24T05:15:00+05:45',
        '2026-08-23T20:00:00-0330', epoch, epoch / 1000, new Date(epoch)]) {
        const payload = syntheticRealtimeCapture();
        payload.snapshot_time = timestamp;
        const rows = normalizeBrowserAssistCapturePayload(payload, syntheticOptions).rows;
        assert.deepEqual(rows.map(row => row.snapshot_time), [expected, expected]);
        assert.deepEqual(rows.map(row => row.data_date), [expected.slice(0, 10), expected.slice(0, 10)]);
        assert.ok(rows.every(row => row.raw_data.snapshot_time_source === 'source_timestamp'));
        payload.data_date = '2026-08-22';
        assert.deepEqual(normalizeBrowserAssistCapturePayload(payload, syntheticOptions).rows.map(row => row.data_date), ['2026-08-22', '2026-08-22']);
      }
    }
  } finally {
    if (originalTimezone === undefined) delete process.env.TZ;
    else process.env.TZ = originalTimezone;
  }
});

test('CLI snapshot options and captured updatedAt accept strict string epochs like numeric epochs', () => {
  for (const placement of ['option', 'updatedAt']) {
    for (const epoch of [1787527800, 1787527800000, 1787527800123, 0]) {
      const normalize = timestamp => {
        const payload = syntheticRealtimeCapture();
        const options = { ...syntheticOptions };
        if (placement === 'option') options.snapshotTime = timestamp;
        else {
          payload.ctripStats.updatedAt = timestamp;
          payload.meituanStats.updatedAt = timestamp;
        }
        return normalizeBrowserAssistCapturePayload(payload, options);
      };
      const expected = normalize(epoch);
      assert.equal(expected.rows.length, 2, `${placement}: numeric ${epoch}`);
      for (const value of [String(epoch), ` +${epoch} `]) {
        const actual = normalize(value);
        assert.equal(actual.rows.length, 2, `${placement}: string ${value}`);
        assert.deepEqual(actual.rows.map(row => [row.snapshot_time, row.snapshot_bucket, row.data_date]),
          expected.rows.map(row => [row.snapshot_time, row.snapshot_bucket, row.data_date]));
        assert.ok(actual.rows.every(row => row.raw_data.snapshot_time_source === 'source_timestamp'));
      }
    }
  }
});

test('nonfinite unrepresentable empty and mixed epoch values cannot borrow the generated business date', () => {
  for (const timestamp of [NaN, Infinity, -Infinity, 'NaN', 'Infinity', '', ' ',
    '1787527800 seconds', '1787527800000junk', '1e12', '0x6800',
    '9'.repeat(400), 8640000000000001, '8640000000000001']) {
    for (const placement of ['option', 'updatedAt']) {
      const payload = syntheticRealtimeCapture();
      const options = { ...syntheticOptions };
      if (placement === 'option') options.snapshotTime = timestamp;
      else {
        payload.ctripStats.updatedAt = timestamp;
        payload.meituanStats.updatedAt = timestamp;
      }
      const actual = normalizeBrowserAssistCapturePayload(payload, options);
      assert.equal(actual.rows.length, 0, `${placement}: ${String(timestamp).slice(0, 30)}`);
      assert.equal(actual.packages.length, 0);
      assert.equal(actual.generated_at, syntheticOptions.generatedAt);
    }
  }
});

test('normalizes browser assist OTA capture into data-import packages', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: {
      url: 'https://ebooking.ctrip.com/ebkovsroom/inventory/calendar?token=secret',
      source: '携程',
      type: 'ctrip',
      hotelName: '测试酒店',
      rooms: [
        {
          name: '大床房',
          days: [
            {
              date: '2026-06-27',
              state: '开房',
              remain: '剩余3',
              sold: 2,
              limitType: '限量',
            },
          ],
        },
      ],
    },
    ctripStats: {
      url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?token=secret',
      updatedAt: '2026-06-27 10:20:00',
      metrics: {
        ctrip: {
          realtimeVisitors: { label: '实时访客', value: '128' },
          visitorPeerAvg: { label: '同行均值', value: '96' },
          orderConversionRate: { label: '订单转化率', value: '4.5%' },
          realtimeRank: { label: '实时排名', value: '12' },
        },
        qunar: {
          realtimeVisitors: { label: '实时访客', value: '56' },
          orderConversionRate: { label: '订单转化率', value: '3.2%' },
        },
      },
    },
    meituanStats: {
      url: 'https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html?token=secret',
      updatedAt: '2026-06-27 10:25:00',
      metrics: {
        exposureUsers: { label: '曝光人数', value: '1000' },
        browseUsers: { label: '浏览人数', value: '150' },
        paidOrders: { label: '支付订单数', value: '8' },
        exposureBrowseRate: { label: '曝光浏览率', value: '15%' },
        browsePayRate: { label: '浏览支付率', value: '5.3%' },
      },
    },
  }, {
    systemHotelId: 58,
    generatedAt: '2026-06-27 10:30:00',
  });

  assert.equal(result.source_contract, 'ota_browser_assist_collection_contract.v1');
  assert.equal(result.summary.row_count, 5);
  assert.deepEqual(
    result.packages.map((item) => `${item.platform}:${item.data_type}`).sort(),
    ['ctrip:inventory', 'ctrip:peer_rank', 'ctrip:traffic', 'meituan:traffic'],
  );
  assert.equal(JSON.stringify(result).includes('https://'), false);

  for (const item of result.packages) {
    assert.equal(item.import_endpoint, '/api/online-data/data-import');
    assert.equal(item.system_hotel_id, 58);
    assert.deepEqual([...new Set(item.rows.map((row) => row.data_type))], [item.data_type]);
  }

  const ctripInventory = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'inventory');
  assert.equal(ctripInventory.dimension, '大床房');
  assert.equal(ctripInventory.inventory_remaining, 3);
  assert.equal(ctripInventory.raw_data.inventory.remain, 3);
  assert.equal(ctripInventory.raw_data.field_facts.find((fact) => fact.metric_key === 'room_inventory_remaining').status, 'captured');
  assert.match(ctripInventory.source_trace_id, /^ctrip:[a-f0-9]{64}$/);

  const qunarTraffic = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'traffic' && row.dimension === 'realtime:qunar');
  assert.equal(qunarTraffic.platform, 'ctrip');
  assert.equal(qunarTraffic.detail_exposure, 56);
  assert.equal(qunarTraffic.flow_rate, 3.2);

  const ctripRank = result.rows.find((row) => row.source === 'ctrip' && row.data_type === 'peer_rank');
  assert.equal(ctripRank.rank, 12);
  assert.equal(ctripRank.compare_type, 'channel_realtime_rank');

  const meituanTraffic = result.rows.find((row) => row.source === 'meituan' && row.data_type === 'traffic');
  assert.equal(meituanTraffic.list_exposure, 1000);
  assert.equal(meituanTraffic.detail_exposure, 150);
  assert.equal(meituanTraffic.order_submit_num, 8);
  assert.equal(meituanTraffic.flow_rate, 5.3);
});

test('keeps missing browser assist fields explicit instead of inventing values', () => {
  const result = normalizeBrowserAssistCapturePayload({
    ctrip: {
      rooms: [
        {
          days: [
            {
              date: '2026/06/27',
              state: '关房',
            },
          ],
        },
      ],
    },
  }, {
    generatedAt: '2026-06-27 10:30:00',
  });

  assert.equal(result.summary.row_count, 1);
  const row = result.rows[0];
  assert.equal(row.dimension, 'room_index:0');
  assert.equal(Object.hasOwn(row, 'inventory_remaining'), false);
  assert.deepEqual(row.raw_data.missing_fields, [
    { field: 'room_name', missing_state: 'field_missing' },
    { field: 'remain', missing_state: 'field_missing' },
  ]);
  assert.equal(
    row.raw_data.field_facts.find((fact) => fact.metric_key === 'room_inventory_remaining').missing_state,
    'field_missing',
  );
});

test('normalizes Meituan platform identity evidence without storing full request URLs', () => {
  const result = normalizeBrowserAssistCapturePayload({
    platformIdentity: {
      platform: 'meituan',
      updatedAt: '2026-06-30 10:20:00',
      partnerId: '313720',
      poiId: '888754073',
      evidence: [
        {
          source: 'performance_resource',
          host: 'eb.meituan.com',
          path: '/api/v1/ebooking/diagnosis/analysis/detail',
          fields: ['partnerId', 'poiId'],
        },
      ],
    },
  }, {
    systemHotelId: 58,
    generatedAt: '2026-06-30 10:30:00',
  });

  assert.equal(result.summary.row_count, 1);
  assert.deepEqual(result.packages.map((item) => `${item.platform}:${item.data_type}`), ['meituan:platform_identity']);

  const row = result.rows[0];
  assert.equal(row.data_type, 'platform_identity');
  assert.equal(row.hotel_id, '888754073');
  assert.equal(row.partner_id, '313720');
  assert.equal(row.poi_id, '888754073');
  assert.equal(row.data_value, 1);
  assert.equal(row.raw_data.platform_identity.evidence[0].host, 'eb.meituan.com');
  assert.equal(row.raw_data.field_facts.find((fact) => fact.metric_key === 'meituan_partner_id').status, 'captured');
  assert.equal(JSON.stringify(result).includes('diagnosisAnalysisType'), false);
  assert.equal(JSON.stringify(result).includes('Cookie'), false);
});

test('normalizes Meituan hook payload into supplemental import packages', () => {
  const result = normalizeBrowserAssistCapturePayload({
    capture: {
      P_RZ_0: {
        rankType: 'P_RZ',
        rankTypeName: '入住榜',
        dateRange: '0',
        dateRangeName: '今日实时',
        source: 'peer',
        capturedAt: '2026-06-29T08:10:00.000Z',
        data: {
          peerRankData: [
            {
              dimName: '入住间夜',
              roundRanks: [
                { poiId: 'peer-1', poiName: '同行酒店A', rank: 2, percent: '35.5', dataValue: 18 },
              ],
            },
          ],
        },
      },
      FLOW_CONV_0: {
        rankType: 'FLOW_CONV',
        dateRange: '0',
        source: 'flow',
        capturedAt: '2026-06-29T08:11:00.000Z',
        data: {
          exposeCount: 1000,
          visitCount: 200,
          orderCount: 20,
          exposeVisitRate: 20,
          visitOrderRate: 10,
        },
      },
      FLOW_SRC_0: {
        rankType: 'FLOW_SRC',
        dateRange: '0',
        source: 'flow',
        capturedAt: '2026-06-29T08:12:00.000Z',
        data: {
          list: [
            { name: '非广告曝光', value: 800, percent: 80 },
          ],
        },
      },
      FORECAST_2: {
        rankType: 'FORECAST',
        forecastType: '2',
        source: 'forecast',
        capturedAt: '2026-06-29T08:13:00.000Z',
        data: {
          detail: [
            { dateTime: '20260701', current: 88, peerAvg: 120 },
          ],
        },
      },
      KEYWORDS: {
        rankType: 'KEYWORDS',
        source: 'keywords',
        capturedAt: '2026-06-29T08:14:00.000Z',
        data: {
          cards: [
            {
              title: '热门搜索',
              itemList: [
                { name: '机场酒店', value: 320 },
              ],
            },
          ],
        },
      },
    },
  }, {
    systemHotelId: 58,
    generatedAt: '2026-06-29 08:15:00',
  });

  assert.equal(result.summary.row_count, 5);
  assert.deepEqual(
    result.packages.map((item) => `${item.platform}:${item.data_type}`).sort(),
    ['meituan:peer_rank', 'meituan:search_keyword', 'meituan:traffic_analysis', 'meituan:traffic_forecast'],
  );

  const peer = result.rows.find((row) => row.data_type === 'peer_rank');
  assert.equal(peer.dimension, 'peer_rank:P_RZ:入住间夜');
  assert.equal(peer.rank, 2);
  assert.equal(peer.rank_percent, 35.5);
  assert.equal(peer.raw_data.module, 'meituan_hook_peer_rank');

  const conversion = result.rows.find((row) => row.data_type === 'traffic_analysis' && row.analysis_type === 'conversion_funnel');
  assert.equal(conversion.list_exposure, 1000);
  assert.equal(conversion.detail_exposure, 200);
  assert.equal(conversion.order_submit_num, 20);
  assert.equal(conversion.flow_rate, 10);

  const source = result.rows.find((row) => row.dimension === 'traffic_analysis:source:非广告曝光');
  assert.equal(source.data_value, 800);

  const forecast = result.rows.find((row) => row.data_type === 'traffic_forecast');
  assert.equal(forecast.data_date, '2026-07-01');
  assert.equal(forecast.peer_avg, 120);
  assert.equal(forecast.raw_data.quality_status, 'signal_only');

  const keyword = result.rows.find((row) => row.data_type === 'search_keyword');
  assert.equal(keyword.keyword, '机场酒店');
  assert.equal(keyword.data_value, 320);
});
