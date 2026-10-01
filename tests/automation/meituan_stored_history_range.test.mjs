import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('const openMeituanStoredBusinessDate = async () =>');
const end = source.indexOf('const openMeituanStoredDataTab =', start);
const handler = source.slice(start, end);
const row = (date = '2026-08-01', extra = {}) => ({
  id: 1, system_hotel_id: 80, source: 'meituan', data_date: date,
  history_status: 'partial', ...extra,
});
function harness(overrides = {}) {
  const calls = [], messages = [];
  const env = {
    Date, Array, Number,
    meituanForm: { value: { hotelId: '80', startDate: '2026-08-01', endDate: '2026-08-03' } },
    onlineDataTab: { value: 'meituan-ranking' }, downloadCenterTab: { value: '' },
    onlineDataFilter: { value: {} }, onlineDataList: { value: [row('2025-01-01')] },
    onlineDataQualitySummary: { value: null },
    captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    applyMeituanStoredDataFilter: (_tab, options) => {
      env.onlineDataFilter.value.hotel_id = options.hotelId;
      env.onlineDataFilter.value.source = 'meituan';
    },
    showToast: (message, level) => messages.push({ message, level }),
    loadOnlineDataList: async () => { calls.push('load'); return [row(), row('2026-08-03', { id: 2 })]; },
    readStoredOtaTrafficGate: async () => { calls.push('gate'); return { status: 'blocked' }; },
    ...overrides,
  };
  return { env, calls, messages, run: vm.runInNewContext(`(() => { ${handler}; return openMeituanStoredBusinessDate; })()`, env) };
}

test('custom history accepts a real multi-day range without a live or single-day trust gate', async () => {
  const h = harness();
  assert.equal(await h.run(), true);
  assert.deepEqual(h.calls, ['load']);
  assert.equal(h.env.onlineDataFilter.value.end_date, '2026-08-03');
  assert.match(h.messages.at(-1).message, /2026-08-01.*2026-08-03.*当前页.*2/);
  assert.doesNotMatch(h.messages.at(-1).message, /可信流量事实|完整覆盖/);
});

test('single-day partial records remain browsable without being promoted to trusted facts', async () => {
  const h = harness({ loadOnlineDataList: async () => [row()] });
  h.env.meituanForm.value.endDate = '2026-08-01';
  assert.equal(await h.run(), true);
  assert.equal(h.calls.includes('gate'), false);
});

test('impossible and reversed dates fail before reads', async () => {
  for (const [startDate, endDate] of [['2026-02-30', '2026-02-30'], ['2026-08-03', '2026-08-01']]) {
    const h = harness();
    Object.assign(h.env.meituanForm.value, { startDate, endDate });
    assert.equal(await h.run(), false);
    assert.deepEqual(h.calls, []);
  }
});

test('failed requests cannot validate stale rows left from an earlier query', async () => {
  const h = harness({ loadOnlineDataList: async () => null });
  h.env.onlineDataList.value = [row()];
  assert.equal(await h.run(), false);
  assert.match(h.messages.at(-1).message, /失败/);
  assert.equal(h.env.onlineDataList.value.length, 0);
});

test('an explicit empty response remains distinct from a failed request', async () => {
  const h = harness({ loadOnlineDataList: async () => [] });
  assert.equal(await h.run(), false);
  assert.match(h.messages.at(-1).message, /未发现已保存/);
  assert.doesNotMatch(h.messages.at(-1).message, /失败/);
});

test('wrong hotel, platform or dates cannot pass historical readback', async () => {
  for (const wrong of [row('2026-08-01', { system_hotel_id: 81 }), row('2026-08-01', { source: 'ctrip' }), row('2026-07-31')]) {
    const h = harness({ loadOnlineDataList: async () => [wrong] });
    assert.equal(await h.run(), false);
    assert.match(h.messages.at(-1).message, /范围.*不一致/);
  }
});

test('late results after a date or account switch do not confirm the previous query', async () => {
  let finish;
  const h = harness({ loadOnlineDataList: () => new Promise(resolve => { finish = resolve; }) });
  const pending = h.run();
  h.env.onlineDataFilter.value.end_date = '2026-08-10';
  finish([row()]);
  assert.equal(await pending, false);
  assert.equal(h.messages.filter(item => item.level === 'success').length, 0);
});
