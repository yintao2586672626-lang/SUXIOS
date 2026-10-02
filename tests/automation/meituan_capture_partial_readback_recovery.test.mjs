import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const failure = {
  code: 500,
  message: '美团采集数据数据库回读不完整；请先核对历史记录。',
  data: {
    persistence_status: 'readback_failed', saved_count: 1, row_count: 2,
    target_date: '2026-09-27', ingestion_method: 'manual_import',
  },
};

const run = async (kind, responseMode) => {
  const notices = [];
  const visible = [];
  const requestSave = async () => {
    if (responseMode === 'throw') {
      const error = new Error(failure.message);
      error.data = failure;
      throw error;
    }
    return failure;
  };
  const common = {
    getSystemHotelId: () => 80,
    getHotelNameById: () => 'Synthetic Hotel',
    requestSave,
    setOnlineDataResult: value => visible.push(value),
    notify: (message, level) => notices.push({ message, level }),
  };
  const result = kind === 'csv'
    ? await sandbox.api.runMeituanOrderCsvImportFlow({
      ...common,
      getConfigId: () => 'config-80',
      getForm: () => ({
        poiId: 'poi-1', endDate: '2026-09-27',
        csvText: '订单号,入住日期,购买时间\nsynthetic-1,2026-09-27,2026-09-26',
      }),
      setOrderResult: value => visible.push(value),
    })
    : await sandbox.api.runMeituanCapturedPayloadSaveFlow({
      ...common,
      getForm: () => ({ payloadJson: JSON.stringify({ store_id: 'poi-1', default_data_date: '2026-09-27', orders: [] }) }),
      setCaptureResult: value => visible.push(value),
    });
  return { result, visible: visible.at(-1), notice: notices.at(-1) };
};

for (const kind of ['csv', 'json']) {
  for (const responseMode of ['throw', 'return']) {
    test(`${kind} partial readback ${responseMode} keeps verified subset and recovery visible`, async () => {
      const { result, visible, notice } = await run(kind, responseMode);
      assert.equal(result.status, 'business_failed');
      assert.equal(visible.ui_flow_status, 'business_failed');
      assert.equal(visible.saved_count, 1);
      assert.equal(visible.row_count, 2);
      assert.equal(visible.readback_verified, false);
      assert.match(visible.ui_message, /1.*2.*历史记录/);
      assert.equal(notice.level, 'error');
    });
  }
}
