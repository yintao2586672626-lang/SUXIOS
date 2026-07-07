import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context, {
  filename: 'public/data-health-static.js',
});

const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

test('data health field-gap summary stays read-only and source-aware', () => {
  assert.equal(typeof helpers.summarizeDataHealthFieldGapActions, 'function');

  const rows = [{
    status: 'missing',
    sourceRef: 'missing_field_codes',
  }, {
    status: 'forbidden',
    sourceRef: 'field_asset_summary.forbidden_fields',
  }, {
    status: 'not_returned_visible',
    sourceRef: 'field_asset_summary.not_returned_fields',
  }];

  const summary = helpers.summarizeDataHealthFieldGapActions(rows);
  assert.equal(summary.countText, '3 项缺口');
  assert.match(summary.detailText, /待补 2/);
  assert.match(summary.detailText, /禁止采集 1/);
  assert.match(summary.detailText, /来源 3/);
  assert.match(summary.boundaryText, /未返回字段不按成功处理/);
  assert.equal(summary.hasForbidden, true);
});
