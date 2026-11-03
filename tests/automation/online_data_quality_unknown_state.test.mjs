import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context);
const api = context.window.SUXI_DATA_HEALTH_STATIC;

test('absent and unknown OTA quality summaries never imply complete or green quality', () => {
    for (const quality of [undefined, null, {}, { status: '' }, { status: 'future_status' }]) {
        assert.equal(api.onlineDataQualityStatusText(quality), '未验证');
        assert.equal(api.onlineDataQualityStatusClass(quality), 'bg-gray-50 text-gray-600 border-gray-200');
    }
});

test('only an explicit ok quality status yields complete quality while warnings and errors remain visible', () => {
    assert.equal(api.onlineDataQualityStatusText({ status: 'ok' }), '完整');
    assert.equal(api.onlineDataQualityStatusText({ status: 'warning' }), '需复核');
    assert.equal(api.onlineDataQualityStatusText({ status: 'error' }), '异常');
    assert.match(api.onlineDataQualityStatusClass({ status: 'ok' }), /emerald/);
    assert.match(api.onlineDataQualityStatusClass({ status: 'warning' }), /amber/);
    assert.match(api.onlineDataQualityStatusClass({ status: 'error' }), /red/);
});
