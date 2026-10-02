import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');

test('online analysis exposes a visible fail-closed notice for heterogeneous metric scopes', () => {
  assert.match(
    appMain,
    /const onlineAnalysisAggregationNotice = computed\(\(\) => buildOnlineAnalysisAggregationNotice\(/,
  );
  assert.match(systemStatic, /const buildOnlineAnalysisAggregationNotice = \(gate = \{\}\) => \{/);
  assert.match(systemStatic, /heterogeneous_metric_scope/);
  assert.match(systemStatic, /duplicate_canonical_grain/);
  assert.match(systemStatic, /aggregation_identity_incomplete/);
  assert.match(systemStatic, /source_ownership_unverified/);
  assert.match(systemStatic, /已阻断不可加口径/);
  assert.match(template, /onlineAnalysisAggregationNotice/);
  assert.match(template, /暂无可绘制趋势/);
});

const helperContext = { window: {} };
vm.runInNewContext(systemStatic, helperContext);
const aggregationNotice = helperContext.window.SUXI_SYSTEM_STATIC.buildOnlineAnalysisAggregationNotice;

test('aggregation notice distinguishes scope, duplicate, identity and ownership failures', () => {
  assert.equal(aggregationNotice({ status: 'ok' }), '');
  assert.equal(aggregationNotice({ status: 'blocked', blocker: 'heterogeneous_metric_scope', group_count: 2 }).includes('2'), true);
  for (const [blocker, text] of [
    ['heterogeneous_metric_scope', '停止总额、趋势和排名'],
    ['duplicate_canonical_grain', '重复快照'],
    ['aggregation_identity_incomplete', '身份'],
    ['source_ownership_unverified', '归属校验'],
  ]) {
    assert.match(aggregationNotice({ status: 'blocked', blocker }), new RegExp(text));
  }
  assert.equal(aggregationNotice({ status: 'blocked', blocked_reason: 'backend scope failure' }), 'backend scope failure');
});
