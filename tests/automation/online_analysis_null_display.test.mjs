import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../../public/data-health-static.js', import.meta.url), 'utf8');

test('online analysis cards distinguish missing metrics from real zero', () => {
    const metricHelper = source.match(/const onlineAnalysisMetricText = [\s\S]*?\n\s*};/)?.[0] || '';
    const cardBuilder = source.match(/const buildOnlineAnalysisSummaryCards = [\s\S]*?\n\s*];/)?.[0] || '';
    assert.ok(metricHelper);
    assert.ok(cardBuilder);

    const { buildOnlineAnalysisSummaryCards } = Function(
        `${metricHelper}\n${cardBuilder}\nreturn { buildOnlineAnalysisSummaryCards };`,
    )();
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
