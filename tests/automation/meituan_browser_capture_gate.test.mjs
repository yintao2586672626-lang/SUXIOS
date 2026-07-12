import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  evaluateMeituanCaptureGate,
  filterMeituanCumulativeRowsByTargetDate,
  filterMeituanEventRowsByTargetDate,
} from '../../scripts/lib/meituan_capture_gate.mjs';

test('capture normalizer applies traffic-card parsing only to traffic and date evidence to ads rows', () => {
  const source = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  const normalizeBlock = source.slice(source.indexOf('function normalizeCapturedList'), source.indexOf('function joinSourcePath'));
  const decorateBlock = source.slice(source.indexOf('function decorateCapturedRow'), source.indexOf('async function collectMeituanTrafficDomRows'));

  assert.match(normalizeBlock, /if \(section === 'traffic'\) \{/);
  assert.doesNotMatch(normalizeBlock, /section === 'traffic' \|\| section === 'ads'/);
  assert.match(decorateBlock, /section === 'traffic' \|\| section === 'ads'/);
  assert.match(source, /runMeituanOrderInteractionPlan/);
  assert.match(source, /runMeituanReviewInteractionPlan/);
  assert.match(source, /\['data', 'results'\]/);
});

test('drops untargeted event summaries while keeping target-date review evidence', () => {
  const payload = filterMeituanEventRowsByTargetDate({
    reviews: [
      { commentCount: 10, commentScore: 5 },
      { dataDate: '2026-07-11', date_source: 'request.query.startTime', commentCount: 5, commentScore: 5 },
    ],
    orders: [
      { dataDate: '2026-07-10', date_source: 'row.purchaseTime', order_id_hash: 'hash-1' },
    ],
  }, '2026-07-11', ['reviews']);

  assert.equal(payload.reviews.length, 1);
  assert.equal(payload.reviews[0].commentCount, 5);
  assert.equal(payload.orders.length, 1);
  assert.deepEqual(payload.target_date_filter.dropped_counts, { reviews: 1 });
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

test('accepts an explicitly detected Meituan ads onboarding page as not applicable', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    section_evidence: {
      ads: {
        status: 'not_applicable',
        reason: 'ads_not_enabled',
        evidence_source: 'page.dom',
        marker: 'meituan_ads_onboarding',
      },
    },
    responses: [
      { section: 'reviews', status: 200, row_count: 1 },
      { section: 'traffic', status: 200, row_count: 0 },
      { section: 'orders', status: 200, row_count: 0 },
    ],
    traffic: [],
    orders: [],
    reviews: [{ dataDate: '2026-07-11', commentCount: 1 }],
    ads: [],
  }, ['traffic', 'orders', 'reviews', 'ads']);

  assert.equal(gate.status, 'pass');
  assert.equal(gate.section_statuses.ads, 'not_applicable');
  assert.deepEqual(gate.not_applicable_sections, ['ads']);
  assert.equal(gate.failed_check_ids.includes('section_ads_not_captured'), false);
});

test('collector and UI expose Meituan ads-not-enabled evidence without treating generic empty ads as success', () => {
  const collector = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  const panel = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
  const controller = readFileSync('app/controller/concern/OnlineDataRequestConcern.php', 'utf8');
  const successResponseBlock = controller.slice(
    controller.indexOf('$savedCount = empty($rows)'),
    controller.indexOf("return $this->success($responsePayload", controller.indexOf('$savedCount = empty($rows)'))
  );

  assert.match(collector, /detectMeituanAdsSectionEvidence/);
  assert.match(collector, /meituan_ads_onboarding/);
  assert.match(collector, /ads_not_enabled/);
  assert.match(panel, /section_statuses\?\.ads === 'not_applicable'/);
  assert.match(panel, /pages\?\.some/);
  assert.match(panel, /section_evidence\?\.reason === 'ads_not_enabled'/);
  assert.match(panel, /广告未开通/);
  assert.match(successResponseBlock, /'auth_status'\s*=>\s*\$payload\['auth_status'\]/);
  assert.match(successResponseBlock, /'capture_gate'\s*=>\s*\$gate/);
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

test('keeps only authoritative target-date cumulative rows while preserving future forecast rows', () => {
  const payload = filterMeituanCumulativeRowsByTargetDate({
    traffic: [
      { dataDate: '2026-07-12', date_source: 'response.rtDataUpdateTime', exposure_count: 100 },
    ],
    peerRank: [
      { dataDate: '2026-07-12', date_source: 'request.query.startDate', rank: 3 },
      { dataDate: '2026-07-11', date_source: 'request.query.startDate', rank: 4 },
      { dataDate: '2026-07-12', date_source: 'capture_context.default_data_date', rank: 5 },
    ],
    searchKeywords: [
      { dataDate: '2026-07-12', date_source: 'capture_context.default_data_date', keyword: 'hotel' },
    ],
    trafficForecast: [
      { dataDate: '2026-07-13', date_source: 'row.dataDate', forecast_value: 120 },
    ],
  }, '2026-07-12');

  assert.equal(payload.traffic.length, 1);
  assert.equal(payload.peerRank.length, 1);
  assert.equal(payload.peerRank[0].rank, 3);
  assert.equal(payload.searchKeywords.length, 0);
  assert.equal(payload.trafficForecast.length, 1);
  assert.deepEqual(payload.target_date_filter.dropped_counts, {
    peerRank: 2,
    searchKeywords: 1,
  });
});
