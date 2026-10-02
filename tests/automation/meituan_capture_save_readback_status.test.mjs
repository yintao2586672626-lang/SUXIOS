import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const runSave = async response => {
  const notices = [];
  const result = await sandbox.api.runMeituanCapturedPayloadSaveFlow({
    getForm: () => ({ payloadJson: JSON.stringify({ store_id: 'poi-1', business_date: '2026-09-27', rows: [] }) }),
    getSystemHotelId: () => 80,
    getHotelNameById: id => id === 80 ? 'Synthetic Hotel' : '',
    requestSave: async body => {
      assert.equal(body.system_hotel_id, 80);
      assert.equal(body.profile_key, 'poi-1');
      assert.equal(body.payload.business_date, '2026-09-27');
      assert.equal(body.payload.system_hotel_id, 80);
      return response;
    },
    notify: (message, level) => notices.push({ message, level }),
  });
  return { result, notice: notices.at(-1) };
};

test('captured payload processing count without exact readback is unverified', async () => {
  const { result, notice } = await runSave({ code: 200, data: { saved_count: 2 } });
  assert.equal(result.status, 'readback_unverified');
  assert.equal(result.readback_verified, false);
  assert.equal(notice.level, 'warning');
  assert.match(notice.message, /回读/);
});

test('captured payload exact readback is success and business failure is explicit', async () => {
  const verified = await runSave({ code: 200, data: { saved_count: 2, readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal(verified.result.status, 'success');
  assert.equal(verified.notice.level, 'success');

  const failed = await runSave({ code: 200, data: { saved_count: 0, status: 'failed' } });
  assert.equal(failed.result.status, 'business_failed');
  assert.equal(failed.notice.level, 'error');
});
