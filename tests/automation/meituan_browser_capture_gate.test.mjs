import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { evaluateMeituanCaptureGate } from '../../scripts/lib/meituan_capture_gate.mjs';

test('capture normalizer applies traffic-card parsing only to traffic and date evidence to ads rows', () => {
  const source = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  const normalizeBlock = source.slice(source.indexOf('function normalizeCapturedList'), source.indexOf('function joinSourcePath'));
  const decorateBlock = source.slice(source.indexOf('function decorateCapturedRow'), source.indexOf('async function collectMeituanTrafficDomRows'));

  assert.match(normalizeBlock, /if \(section === 'traffic'\) \{/);
  assert.doesNotMatch(normalizeBlock, /section === 'traffic' \|\| section === 'ads'/);
  assert.match(decorateBlock, /section === 'traffic' \|\| section === 'ads'/);
});

test('fails when a requested Meituan section has neither rows nor an authoritative empty response', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{ section: 'traffic', status: 200, row_count: 1 }],
    traffic: [{ data_date: '2026-07-11', exposure_count: 100 }],
    orders: [],
    ads: [],
    reviews: [],
  }, ['traffic', 'orders', 'ads', 'reviews']);

  assert.equal(gate.status, 'fail');
  assert.equal(gate.failed_check_ids.includes('section_orders_not_captured'), true);
  assert.equal(gate.failed_check_ids.includes('section_ads_not_captured'), true);
  assert.equal(gate.failed_check_ids.includes('section_reviews_not_captured'), true);
  assert.equal(gate.failed_check_ids.includes('section_traffic_not_captured'), false);
});

test('passes only after every requested Meituan section has rows', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{ row_count: 4 }],
    traffic: [{ exposure_count: 100 }],
    orders: [{ order_id: 'hashed-in-production' }],
    ads: [{ spend: 10 }],
    reviews: [{ score: 4.8 }],
  }, ['traffic', 'orders', 'ads', 'reviews']);

  assert.equal(gate.status, 'pass');
  assert.deepEqual(gate.failed_check_ids, []);
});

test('accepts an authoritative successful zero-row response without discarding other modules', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [
      { section: 'traffic', status: 200, row_count: 1 },
      { section: 'orders', status: 200, row_count: 0 },
      { section: 'reviews', status: 200, row_count: 0 },
    ],
    traffic: [{ exposure_count: 100 }],
    orders: [],
    reviews: [],
  }, ['traffic', 'orders', 'reviews']);

  assert.equal(gate.status, 'pass');
  assert.equal(gate.section_statuses.orders, 'empty_confirmed');
  assert.equal(gate.section_statuses.reviews, 'empty_confirmed');
  assert.equal(gate.section_statuses.traffic, 'captured');
});

test('does not treat an HTTP 200 platform-error body as an authoritative empty result', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{
      section: 'orders',
      status: 200,
      row_count: 0,
      data: { code: 500, message: 'platform rejected request' },
    }],
    orders: [],
  }, ['orders']);

  assert.equal(gate.status, 'fail');
  assert.equal(gate.section_statuses.orders, 'not_captured');
  assert.equal(gate.failed_check_ids.includes('section_orders_not_captured'), true);
});

test('rejects a cumulative row whose target date came only from capture fallback', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{ section: 'traffic', status: 200, row_count: 1 }],
    traffic: [{
      dataDate: '2026-07-11',
      date_source: 'capture_context.default_data_date',
      exposure_count: 100,
    }],
  }, ['traffic'], { targetDate: '2026-07-11' });

  assert.equal(gate.status, 'fail');
  assert.equal(gate.failed_check_ids.includes('target_date_unverified'), true);
});

test('accepts a cumulative target-date row backed by request or row evidence', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{ section: 'traffic', status: 200, row_count: 2 }],
    traffic: [
      { dataDate: '2026-07-11', date_source: 'request.query.dataDate', exposure_count: 100 },
      { date: '2026-07-11', exposure_count: 80 },
    ],
  }, ['traffic'], { targetDate: '2026-07-11' });

  assert.equal(gate.status, 'pass');
  assert.deepEqual(gate.failed_check_ids, []);
});
