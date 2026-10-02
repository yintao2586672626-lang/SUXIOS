import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const runTraffic = async response => {
  const notices = [];
  const visible = [];
  const latest = [];
  const result = await sandbox.api.runMeituanTrafficFetchFlow({
    getForm: () => ({
      url: 'https://eb.meituan.com/api/traffic/list', partnerId: 'partner-1', poiId: 'poi-1',
      startDate: '2026-09-27', endDate: '2026-09-27',
    }),
    getConfigId: () => 'config-80',
    getSystemHotelId: () => 80,
    requestFetch: async body => {
      assert.equal(body.system_hotel_id, 80);
      assert.equal(body.start_date, '2026-09-27');
      assert.equal(body.end_date, '2026-09-27');
      assert.equal(body.poi_id, 'poi-1');
      return response;
    },
    setOnlineDataResult: value => visible.push(value),
    setLatestTrafficData: value => latest.push(value),
    notify: (message, level) => notices.push({ message, level }),
  });
  return { result, visible: visible.at(-1), latest: latest.at(-1), notice: notices.at(-1) };
};

test('traffic saved count without exact readback remains unverified', async () => {
  const { result, notice } = await runTraffic({ code: 200, data: { saved_count: 2 } });
  assert.equal(result.status, 'readback_unverified');
  assert.equal(result.readback_verified, false);
  assert.equal(notice.level, 'warning');
  assert.match(notice.message, /回读/);
});

test('traffic exact readback is success while unsaved rows are display only', async () => {
  const verified = await runTraffic({ code: 200, data: { saved_count: 2, readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal(verified.result.status, 'success');
  assert.equal(verified.result.readback_verified, true);
  assert.equal(verified.notice.level, 'success');

  const displayOnly = await runTraffic({ code: 200, data: { saved_count: 0, data: [{ id: 'synthetic-traffic' }] } });
  assert.equal(displayOnly.result.status, 'display_only');
  assert.equal(displayOnly.visible[0].id, 'synthetic-traffic');
  assert.equal(displayOnly.latest[0].id, 'synthetic-traffic');
  assert.equal(displayOnly.notice.level, 'warning');
});

test('traffic true zero completion and background acceptance stay distinct', async () => {
  const empty = await runTraffic({ code: 200, data: { saved_count: 0, business_status: 'completed' } });
  assert.equal(empty.result.status, 'success');
  assert.equal(empty.result.savedCount, 0);

  const accepted = await runTraffic({ code: 200, data: { status: 'running', task_id: 'synthetic-task' } });
  assert.equal(accepted.result.status, 'accepted');
  assert.equal(accepted.latest.status, 'running');
});
