import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const slice = (start, end) => {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from);
  return source.slice(from, to);
};
const entry = slice('const openAutoFetchRecordAnalysis = async (item) => {', '// 切换自动获取开关');
const dateHelper = slice('const otaDiagnosisStrictBusinessDate = value => {', 'const otaDiagnosisFormatLocalDate');
const createHarness = (tab = 'data') => {
  const filter = { value: { hotel_id: '7', source: 'meituan', start_date: '2026-09-29', end_date: '2026-09-29', data_type: 'order', status: 'success' } };
  const origin = JSON.stringify(filter.value);
  const record = { value: { id: 'previous' } };
  const calls = [];
  const errors = [];
  const ctx = vm.createContext({
    onlineDataFilter: filter, onlineAnalysisSourceRecord: record,
    onlineDataTab: { value: tab },
    permittedHotels: { value: [{ id: 7, name: 'Hotel 7' }, { id: 64, name: 'Hotel 64' }] },
    openOnlineDataTab: (tab, options) => calls.push({ tab, options, query: { ...filter.value } }),
    nextTick: async () => {}, showToast: (message, type) => errors.push({ message, type }),
  });
  vm.runInContext(`let onlineAnalysisDefaultDateApplied = false; ${dateHelper} ${entry}; this.openEntry = openAutoFetchRecordAnalysis;`, ctx);
  return { open: ctx.openEntry, filter, record, calls, errors, origin };
};
const row = (overrides = {}) => ({ system_hotel_id: 64, hotel_id: 'OTA-33828', hotel_name: 'Hotel 64', data_date: '2026-09-30', platform: 'ctrip', ...overrides });

test('record analysis uses the system hotel even when the OTA identifier is another permitted hotel', async () => {
  const h = createHarness();
  assert.equal(await h.open(row({ hotel_id: 7 })), true);
  assert.equal(h.filter.value.hotel_id, '64');
  assert.equal(h.filter.value.source, 'ctrip');
  assert.equal(h.filter.value.start_date, '2026-09-30');
  assert.equal(h.filter.value.end_date, '2026-09-30');
  assert.equal(h.filter.value.status, '');
  assert.equal(h.filter.value.data_type, '');
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].query.hotel_id, '64');
});

test('legacy internal identity requires a matching permitted hotel name', async () => {
  const h = createHarness();
  assert.equal(await h.open(row({ system_hotel_id: undefined, hotel_id: 64 })), true);
  assert.equal(h.filter.value.hotel_id, '64');
});

test('legacy OTA numeric identity cannot silently select another permitted hotel', async () => {
  const h = createHarness();
  assert.equal(await h.open(row({ system_hotel_id: undefined, hotel_id: 7 })), false);
  assert.equal(JSON.stringify(h.filter.value), h.origin);
  assert.equal(h.record.value.id, 'previous');
  assert.equal(h.calls.length, 0);
  assert.equal(h.errors[0].type, 'error');
});

test('unknown or malformed explicit system identity never falls back to a valid OTA identifier', async () => {
  for (const system_hotel_id of [0, -1, 64.5, 'OTA-64', 999, true]) {
    const h = createHarness();
    assert.equal(await h.open(row({ system_hotel_id, hotel_id: 7 })), false);
    assert.equal(JSON.stringify(h.filter.value), h.origin);
    assert.equal(h.calls.length, 0);
  }
});

test('missing legacy name cannot establish an internal hotel identity', async () => {
  const h = createHarness();
  assert.equal(await h.open(row({ system_hotel_id: undefined, hotel_id: 64, hotel_name: '' })), false);
  assert.equal(h.calls.length, 0);
});

test('missing and invalid business dates never reuse the old query date', async () => {
  for (const data_date of ['', null, '2026-02-30', '2026-09-30T12:00:00']) {
    const h = createHarness();
    assert.equal(await h.open(row({ data_date })), false);
    assert.equal(JSON.stringify(h.filter.value), h.origin);
    assert.equal(h.calls.length, 0);
  }
});

test('unsupported or missing platforms never reuse the old platform', async () => {
  for (const platform of ['', 'unknown', 'qunar']) {
    const h = createHarness();
    assert.equal(await h.open(row({ platform })), false);
    assert.equal(JSON.stringify(h.filter.value), h.origin);
    assert.equal(h.calls.length, 0);
  }
});

test('opening another record inside analysis refreshes the exact new scope', async () => {
  const h = createHarness('analysis');
  assert.equal(await h.open(row({ platform: 'meituan', data_date: '2026-09-28' })), true);
  assert.equal(h.calls[0].options.force, true);
  assert.equal(h.calls[0].query.hotel_id, '64');
  assert.equal(h.calls[0].query.start_date, '2026-09-28');
  assert.equal(h.calls[0].query.source, 'meituan');
});

test('the record-entry banner reports record quality without inventing a task write count', () => {
  const banner = template.slice(template.indexOf('v-if="onlineAnalysisSourceRecord"'), template.indexOf('data-testid="competitor-event-feed-panel"'));
  assert.match(banner, /onlineStorageStatusText\(onlineAnalysisSourceRecord\)/);
  assert.doesNotMatch(banner, /saved_count\s*\|\|\s*0/);
});
