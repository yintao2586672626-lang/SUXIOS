import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.api = window.SUXI_MEITUAN_STATIC;`, sandbox);

const runCapture = async (response, options = {}) => {
  const notices = [];
  const visible = [];
  const result = await sandbox.api.runMeituanBrowserCaptureFlow({
    getForm: () => ({ storeId: 'poi-1', captureSections: ['traffic'] }),
    getSystemHotelId: () => 80,
    getHotelNameById: id => id === 80 ? 'Synthetic Hotel' : '',
    options,
    requestCapture: async body => {
      assert.equal(body.system_hotel_id, 80);
      assert.equal(body.store_id, 'poi-1');
      assert.equal(body.login_only, Boolean(options.loginOnly));
      return response;
    },
    setCaptureResult: value => visible.push(value),
    notify: (message, level) => notices.push({ message, level }),
  });
  return { result, visible: visible.at(-1), notice: notices.at(-1) };
};

test('Profile capture processing count without exact readback is unverified', async () => {
  const { result, notice } = await runCapture({ code: 200, data: { saved_count: 2 } });
  assert.equal(result.status, 'readback_unverified');
  assert.equal(result.readback_verified, false);
  assert.equal(notice.level, 'warning');
  assert.match(notice.message, /回读/);
});

test('Profile capture display-only rows do not imply persistence', async () => {
  const displayOnly = await runCapture({ code: 200, data: { saved_count: 0, rows: [{ id: 'synthetic-row' }] } });
  assert.equal(displayOnly.result.status, 'display_only');
  assert.equal(displayOnly.notice.level, 'warning');

  const verified = await runCapture({ code: 200, data: { saved_count: 2, readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal(verified.result.status, 'success');
  assert.equal(verified.notice.level, 'success');
});

test('Profile login-only request completion remains separate from data readback', async () => {
  const loginOnly = await runCapture({ code: 200, data: { saved_count: 0 } }, { loginOnly: true });
  assert.equal(loginOnly.result.status, 'success');
  assert.equal(loginOnly.result.readback_verified, false);
  assert.equal(loginOnly.notice.level, 'info');
  assert.match(loginOnly.notice.message, /刷新状态确认/);
});
