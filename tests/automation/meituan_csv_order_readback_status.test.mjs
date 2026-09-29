import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const csvText = '订单号,房型,入住日期,离店日期,购买时间,底价元\nsynthetic-1,标准间,2026-09-27,2026-09-28,2026-09-26,100';
const runImport = async response => {
  const notices = [];
  const result = await sandbox.api.runMeituanOrderCsvImportFlow({
    getForm: () => ({ csvText, poiId: 'poi-1', startDate: '2026-09-27', endDate: '2026-09-27' }),
    getConfigId: () => 'config-80',
    getSystemHotelId: () => 80,
    getHotelNameById: id => id === 80 ? 'Synthetic Hotel' : '',
    requestSave: async body => {
      assert.equal(body.system_hotel_id, 80);
      assert.equal(body.payload.default_data_date, '2026-09-27');
      assert.equal(body.payload.poi_id, 'poi-1');
      assert.equal(body.parsed_count, 1);
      return response;
    },
    notify: (message, level) => notices.push({ message, level }),
  });
  return { result, notice: notices.at(-1) };
};

test('CSV order import count without exact readback remains unverified', async () => {
  const { result, notice } = await runImport({ code: 200, data: { saved_count: 1 } });
  assert.equal(result.status, 'readback_unverified');
  assert.equal(result.readback_verified, false);
  assert.equal(notice.level, 'warning');
  assert.match(notice.message, /回读/);
});

test('CSV order import exact readback is success and business failure remains explicit', async () => {
  const verified = await runImport({ code: 200, data: { saved_count: 1, readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal(verified.result.status, 'success');
  assert.equal(verified.notice.level, 'success');

  const failed = await runImport({ code: 200, data: { saved_count: 0, status: 'failed' } });
  assert.equal(failed.result.status, 'business_failed');
  assert.equal(failed.notice.level, 'error');
});
