import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context);
const helpers = context.window.SUXI_DATA_HEALTH_STATIC;
const detail = subject => helpers.onlineTruthMetaRows(subject).find(row => row.key === 'field_facts')?.value;

test('original order field evidence names the missing provenance without changing truth or scope', () => {
  const subject = Object.freeze({
    truth: Object.freeze({ status: 'unverified', data_date: '2026-09-30', platform: 'ctrip', hotel: { system_hotel_id: 64, name: '测试门店' } }),
    field_fact_status: Object.freeze({ status: 'partial', captured_count: 0, missing_count: 3, desensitized_capture_evidence_count: 0, missing_metric_keys: ['order_amount', 'room_nights', 'order_count'] }),
  });
  assert.equal(detail(subject), '来源凭证已捕获 0 项；缺失 3 项；待补字段：订单金额、间夜、订单数；脱敏来源凭证 0 项。这些数量表示凭证状态，不代表经营数值。');
  const rows = helpers.onlineTruthMetaRows(subject);
  assert.equal(rows.find(row => row.key === 'status').value, '未验证');
  assert.equal(rows.find(row => row.key === 'hotel').value, '测试门店（ID 64）');
  assert.equal(rows.find(row => row.key === 'platform').value, '携程');
  assert.equal(rows.find(row => row.key === 'date').value, '2026-09-30');
});

test('legacy records without field diagnostics keep their existing detail rows', () => {
  for (const facts of [undefined, null, [], 'ready']) {
    assert.equal(detail({ status: 'unverified', field_fact_status: facts }), undefined);
  }
});

test('missing evidence counts remain unknown instead of being filled with zero', () => {
  const text = detail({ field_fact_status: { status: 'not_loaded' } });
  assert.equal(text, '来源凭证已捕获 未返回；缺失 未返回；脱敏来源凭证 未返回。这些数量表示凭证状态，不代表经营数值。');
  assert.doesNotMatch(text, /0 项/);
});

test('invalid counts cannot appear as captured or missing numeric facts', () => {
  for (const value of [null, '', ' ', false, true, -1, 1.5, NaN, Infinity, 'invalid', {}, Number.MAX_SAFE_INTEGER + 1]) {
    const text = detail({ field_fact_status: { captured_count: value, missing_count: value, desensitized_capture_evidence_count: value } });
    assert.match(text, /已捕获 未返回；缺失 未返回；脱敏来源凭证 未返回/);
  }
});

test('returned integer counts and genuine zero evidence counts are preserved', () => {
  const text = detail({ field_fact_status: { captured_count: '2', missing_count: '0', desensitized_capture_evidence_count: 1 } });
  assert.match(text, /已捕获 2 项；缺失 0 项；脱敏来源凭证 1 项/);
});

test('unknown field keys remain bounded labels and raw diagnostic material is not rendered', () => {
  const text = detail({ field_fact_status: { missing_metric_keys: ['order_amount', 'order_amount', 'opaque_field', 'another_field', null, {}], source_trace_id: 'private-fixture-trace', source_url: 'private-fixture-url' } });
  assert.match(text, /待补字段：订单金额、其他指标 2 项/);
  assert.doesNotMatch(text, /opaque_field|another_field|private-fixture|source_trace_id|source_url/);
  assert.match(text, /缺失 未返回/);
});

test('field evidence readiness does not upgrade an unverified source', () => {
  const subject = { status: 'unverified', source: { method: 'manual' }, field_fact_status: { status: 'ready', captured_count: 3, missing_count: 0, desensitized_capture_evidence_count: 3 } };
  assert.equal(helpers.onlineTruthStatusText(subject), '未验证');
  assert.match(detail(subject), /已捕获 3 项；缺失 0 项/);
  assert.equal(helpers.onlineTruthSourceText(subject), '人工来源（未验证）');
});
