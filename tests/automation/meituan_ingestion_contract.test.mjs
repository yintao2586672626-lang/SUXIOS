import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.window = {};
await import('../../public/meituan-static.js');

const {
  buildMeituanCapturedPayloadSaveContext,
  buildMeituanOrderCsvImportRequestBody,
} = window.SUXI_MEITUAN_STATIC;

test('pasted Profile payload must carry its own Meituan platform identity', () => {
  const context = buildMeituanCapturedPayloadSaveContext({
    form: { storeId: 'form-store', payloadJson: JSON.stringify({ traffic: [{ exposure: 1 }] }) },
    systemHotelId: 80,
  });

  assert.equal(context.ok, false);
  assert.equal(context.status, 'missing_payload_identity');
});

test('manual order CSV request carries explicit config locator and manual ingestion marker', () => {
  const body = buildMeituanOrderCsvImportRequestBody({
    csvText: 'orderNo,roomType\nORDER-1,King',
    form: { poiId: 'poi-80', startDate: '2026-07-11' },
    configId: 'config-80',
    systemHotelId: 80,
  });

  assert.equal(body.config_id, 'config-80');
  assert.equal(body.payload.config_id, 'config-80');
  assert.equal(body.payload.data_period, 'manual_dom_csv');
  assert.equal(body.payload.orders[0]._ingestion_method, 'manual_dom_csv');
});
