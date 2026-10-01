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

test('all six summary cards retain their metric field, format, source truth and missing reasons', () => {
    const summary = {
        total_amount: 120, total_quantity: 6, avg_quantity: 2, total_orders: 3,
        avg_score: 4.5, total_data_value: 9, total_record_count: 7, hotel_count: 1,
        latest_data_date: '2026-09-30', truth_context: { status: 'verified', hotel_id: 80 },
    };
    const cards = buildOnlineAnalysisSummaryCards(summary, 'week', value => `(${value})`);
    assert.deepEqual(Array.from(cards, card => [card.key, card.value, card.sub]), [
        ['amount', '¥(120)', '周维度汇总'], ['quantity', '(6)', '均值 (2)'],
        ['orders', '(3)', '评分 (4.5)'], ['metric_value', '(9)', '流量/排名/服务等扩展指标'],
        ['records', '(7)', 'OTA 入库记录'], ['hotels', '(1)', '最新 2026-09-30'],
    ]);
    assert.ok(cards.every(card => card.truth.status === 'verified' && card.truth.hotel_id === 80));
    const missing = buildOnlineAnalysisSummaryCards({ truth_context: { status: 'verified', hotel_id: 80 } });
    assert.ok(missing.every(card => card.value === '-' && card.truth.status === 'partial'));
    assert.deepEqual(Array.from(missing, card => card.truth.failure_reason), [
        '销售额字段缺失', '间夜字段缺失', '订单字段缺失', '指标值字段缺失', '入库事实行数缺失', '覆盖门店数缺失',
    ]);
});
