import test from 'node:test';
import assert from 'node:assert/strict';
import {
  isImportableMeituanTrafficRow,
  normalizeMeituanTrafficCardRows,
} from '../../scripts/lib/meituan_browser_capture_normalize.mjs';

test('Meituan traffic card response maps to P0 traffic fields', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      data: {
        rtDataUpdateTime: 'updated at 2026/06/15 04:30',
        cards: [
          { id: 'EXPOSE_PV_CNT', title: 'exposure', value: '120' },
          { id: 'INTENTION_UV', title: 'visitors', value: '40' },
          { id: 'PAY_ORDER_CNT_UV', title: 'conversion', value: '12.5' },
          { id: 'PAY_ORDER_CNT', title: 'orders', value: '5' },
        ],
      },
    },
  }, {
    defaultDataDate: '2026-06-15',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].dataDate, '2026-06-15');
  assert.equal(rows[0].date_source, 'data.data.cards.rtDataUpdateTime');
  assert.equal(rows[0].listExposure, 120);
  assert.equal(rows[0].detailExposure, 40);
  assert.equal(rows[0].flowRate, 12.5);
  assert.equal(rows[0].orderSubmitNum, 5);
  assert.equal(rows[0].orderFillingNum, 5);
  assert.equal(rows[0]._order_filling_source_policy, 'meituan_metric_cards_no_separate_order_filling_step_pay_order_count_used');
  assert.equal(rows[0]._source_path, 'data.data.cards');
  assert.equal(rows[0]._meituan_card_metric_sources.list_exposure.card_id, 'EXPOSE_PV_CNT');
  assert.equal(isImportableMeituanTrafficRow(rows[0]), true);
});

test('Meituan traffic card placeholders remain non-importable', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      cards: [
        { id: 'EXPOSE_PV_CNT', title: 'exposure', value: '-' },
        { id: 'INTENTION_UV', title: 'visitors', value: 'data updating' },
        { id: 'PAY_ORDER_CNT_UV', title: 'conversion', value: '--' },
        { id: 'PAY_ORDER_CNT', title: 'orders', value: '' },
      ],
    },
  }, {
    requestDateEvidence: { date: '2026-06-15', date_source: 'request.query.date' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].dataDate, '2026-06-15');
  assert.equal(rows[0].date_source, 'request.query.date');
  assert.equal(rows[0]._meituan_card_metric_missing.length, 4);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), false);
});

test('Meituan traffic importability requires every P0 field group', () => {
  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    flowRate: 20,
    orderFillingNum: 10,
  }), false);

  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    flowRate: 20,
    orderFillingNum: 10,
    orderSubmitNum: 3,
  }), true);
});
