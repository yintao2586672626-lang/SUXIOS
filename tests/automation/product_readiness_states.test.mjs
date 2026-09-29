import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const context = vm.createContext({ window: {}, URLSearchParams });
vm.runInContext(readFileSync('public/system-static.js', 'utf8'), context);
vm.runInContext(readFileSync('public/operation-static.js', 'utf8'), context);
const system = context.window.SUXI_SYSTEM_STATIC;
const operation = context.window.SUXI_OPERATION_STATIC;
test('missing or unverified sources never produce an analysis-ready brief even with no flags', () => {
  for (const status of ['partial', 'missing', 'stale', 'unverified', 'error', 'read_failed']) {
    const brief = operation.buildOperationSourceBrief({
      summary: { data_status: 'ok' }, ota: { data_status: status },
      competitors: { data_status: 'ok' }, service_quality: { data_status: 'ok' },
      abnormal_flags: [],
    });
    assert.notEqual(brief.status, '可分析');
    assert.match(brief.summary, /OTA/);
    assert.doesNotMatch(brief.summary, /真实原因/);
  }
});
test('malformed source flags cannot turn into a one-character warning', () => {
  const brief = operation.buildOperationSourceBrief({ abnormal_flags: 'legacy', ota: { data_status: 'missing' } });
  assert.notEqual(brief.summary, 'l');
  assert.notEqual(brief.status, '可分析');
});
test('read failures, unverified input, old data and unknown states remain distinct', () => {
  const statuses = ['read_failed', 'unverified', 'stale', 'partial', 'not_applicable'];
  const labels = statuses.map(status => system.operationDataStatusText(status));
  assert.equal(new Set(labels).size, statuses.length);
  for (const label of labels) {
    assert.match(label, /[\u3400-\u9fff]/);
    assert.doesNotMatch(label, /已形成闭环|经营正常|已核验保存/);
  }
  assert.equal(system.operationDataStatusText('unknown_future_status'), '状态待核对');
});
test('returned records, saved verification and review readiness do not claim business improvement', () => {
  assert.notEqual(system.operationDataStatusText('ok'), system.operationDataStatusText('readback_verified'));
  assert.doesNotMatch(system.operationEffectStatusLabel('ready'), /已形成闭环|有效|改善/);
  const cards = operation.buildOperationOtaCards({ exposure: 0, visitors: null, orders: 0 });
  assert.equal(cards.find(row => row.label === '曝光').value, '0');
  assert.equal(cards.find(row => row.label === '访客').value, '-');
});
