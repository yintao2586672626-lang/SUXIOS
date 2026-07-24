import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../public/data-health-static.js', import.meta.url), 'utf8');
const context = { window: {} };
vm.runInNewContext(source, context, { filename: 'public/data-health-static.js' });
const { buildOnlineAnalysisSummaryCards } = context.window.SUXI_DATA_HEALTH_STATIC;

test('online analysis cards distinguish missing metrics from real zero', () => {
    const missing = buildOnlineAnalysisSummaryCards({}, 'day', String);
    const zero = buildOnlineAnalysisSummaryCards({
        total_amount: 0,
        total_quantity: 0,
        avg_quantity: 0,
        total_orders: 0,
        avg_score: 0,
        total_data_value: 0,
    }, 'day', String);

    assert.equal(missing.find(card => card.key === 'amount').value, '-');
    assert.equal(missing.find(card => card.key === 'quantity').value, '-');
    assert.equal(missing.find(card => card.key === 'orders').sub, '评分 -');
    assert.equal(zero.find(card => card.key === 'amount').value, '¥0');
    assert.equal(zero.find(card => card.key === 'quantity').value, '0');
    assert.equal(zero.find(card => card.key === 'orders').sub, '评分 0');
});
