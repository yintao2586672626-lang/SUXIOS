import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const sandbox = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), sandbox, {
  filename: 'public/data-health-static.js',
});
const helper = sandbox.window.SUXI_DATA_HEALTH_STATIC;

test('real quality helper keeps missing and unrecognized states unverified', () => {
  for (const quality of [undefined, null, {}, { status: null }, { status: '' }, { status: 'future_status' }]) {
    assert.equal(helper.onlineDataQualityStatusText(quality), '未验证');
    assert.match(helper.onlineDataQualityStatusClass(quality), /gray/);
    assert.doesNotMatch(helper.onlineDataQualityStatusClass(quality), /emerald/);
  }
});

test('real quality helper preserves explicit status and observed zero without changing the input', () => {
  for (const [status, text, color] of [['error', '异常', 'red'], ['warning', '需复核', 'amber'], ['ok', '完整', 'emerald']]) {
    const quality = Object.freeze({ status, current_value: 0, missing_value: null });
    assert.equal(helper.onlineDataQualityStatusText(quality), text);
    assert.match(helper.onlineDataQualityStatusClass(quality), new RegExp(color));
    assert.equal(quality.current_value, 0);
    assert.equal(quality.missing_value, null);
  }
});
