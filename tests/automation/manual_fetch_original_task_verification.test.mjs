import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';

const context = { window: {}, console };
vm.runInNewContext(readSourceAggregate('public/data-health-static.js'), context);
const verify = context.window.SUXI_DATA_HEALTH_STATIC.manualOneClickFetchCanVerifyRow;

test('original task verification requires a hotel and an unresolved original task', () => {
  const row = { hotelId: 80, status: 'running', taskPending: true, taskIds: ['synthetic-original-task'] };
  assert.equal(verify(row), true);
  assert.equal(verify({ ...row, hotelId: 0 }), false);
  assert.equal(verify({ ...row, taskIds: [] }), false);
  assert.equal(verify({ ...row, taskIds: [' '] }), false);
  assert.equal(verify({ ...row, taskIds: [42] }), false);
  assert.equal(verify({ ...row, status: 'success', taskPending: false }), false);
  assert.equal(verify({ ...row, status: 'readback_unverified', taskPending: false }), true);
});
