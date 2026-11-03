import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const sandbox = { window: {} };
vm.runInNewContext(readFileSync('public/ctrip-static.js', 'utf8'), sandbox);
const helpers = sandbox.window.SUXI_CTRIP_STATIC.buildCtripProfileFieldSampleHelpers();

test('legacy numeric zero remains a returned sample and renders zero', () => {
  const field = { field_key: 'order_amount', latest_value: 0, sample_verification_status: 'unverified' };
  assert.equal(helpers.sampleText(field), '0');
  assert.equal(helpers.displaySampleCount(field), 1);
  assert.equal(field.sample_verification_status, 'unverified');
});

test('missing, null, empty and whitespace remain without any sample', () => {
  for (const latest_value of [undefined, null, '', '   ']) {
    const field = { latest_value };
    assert.equal(helpers.sampleItems(field).length, 0);
    assert.equal(helpers.sampleText(field), '');
  }
});

test('list zero keeps its source scope and missing list entries stay missing', () => {
  const field = { latest_values: [{ value: 0, unit: '元', data_date: '2026-10-01', hotel_name: '隔离门店' }, { value: null }] };
  assert.equal(helpers.sampleItems(field).length, 1);
  assert.equal(helpers.sampleText(field), '0元 日期 2026-10-01 · 门店 隔离门店');
});

test('legacy string values and separators remain compatible', () => {
  const field = { latest_value: '0 / 25' };
  assert.equal(helpers.sampleItems(field).length, 2);
  assert.equal(helpers.sampleText(field), '0 / 25');
});

test('returned-sample filters distinguish numeric zero from absence', () => {
  const derivation = sandbox.window.SUXI_CTRIP_STATIC.buildCtripProfileFieldDerivationHelpers({ sampleTextForField: helpers.sampleText });
  const zero = { field_key: 'order_amount', enabled: true, latest_value: 0 };
  const absent = { field_key: 'order_amount', enabled: true, latest_value: null };
  for (const sample of ['not_returned', 'without_sample']) {
    assert.equal(derivation.matchesFilters(zero, { sample }), false);
    assert.equal(derivation.matchesFilters(absent, { sample }), true);
  }
  assert.equal(derivation.matchesFilters(zero, { sample: 'with_sample' }), true);
  assert.equal(derivation.matchesFilters(absent, { sample: 'with_sample' }), false);
});
