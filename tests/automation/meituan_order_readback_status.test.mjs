import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const runOrder = async data => {
  const notices = [];
  const visible = [];
  const result = await sandbox.api.runMeituanOrderFetchFlow({
    getForm: () => ({
      url: 'https://eb.meituan.com/api/orders/list', partnerId: 'partner-1', poiId: 'poi-1',
      startDate: '2026-09-27', endDate: '2026-09-27',
    }),
    getConfigId: () => 'config-80',
    getSystemHotelId: () => 80,
    requestFetch: async body => {
      assert.equal(body.system_hotel_id, 80);
      assert.equal(body.start_date, '2026-09-27');
      assert.equal(body.end_date, '2026-09-27');
      return { code: 200, data };
    },
    setOrderResult: value => visible.push(value),
    notify: (message, level) => notices.push({ message, level }),
  });
  return { result, visible: visible.at(-1), notice: notices.at(-1) };
};

test('order interface count without exact database readback remains unverified', async () => {
  const { result, visible, notice } = await runOrder({ saved_count: 2 });
  assert.equal(result.status, 'readback_unverified');
  assert.equal(visible.ui_flow_status, 'readback_unverified');
  assert.equal(visible.readback_verified, false);
  assert.equal(notice.level, 'warning');
  assert.match(notice.message, /回读/);
});

test('order exact readback can report success and visible rows alone remain display only', async () => {
  const verified = await runOrder({ saved_count: 2, readback_verified: true, persistence_status: 'readback_verified' });
  assert.equal(verified.result.status, 'success');
  assert.equal(verified.visible.readback_verified, true);
  assert.equal(verified.notice.level, 'success');

  const displayOnly = await runOrder({ saved_count: 0, rows: [{ id: 'synthetic-row' }] });
  assert.equal(displayOnly.result.status, 'display_only');
  assert.equal(displayOnly.visible.ui_flow_status, 'display_only');
  assert.equal(displayOnly.notice.level, 'warning');
});
