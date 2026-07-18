import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  evaluateMeituanCaptureGate,
  filterMeituanCumulativeRowsByTargetDate,
  filterMeituanEventRowsByTargetDate,
} from '../../scripts/lib/meituan_capture_gate.mjs';
import {
  collectMeituanPlatformIdentifiers,
  evaluateMeituanPlatformIdentity,
  extractMeituanRequestPlatformIdentifiers,
  isMeituanOwnHotelPayloadKey,
} from '../../scripts/lib/meituan_platform_identity.mjs';

test('Meituan source identity requires one OTA-observed identifier matching the bound store', () => {
  const requestIdentifiers = extractMeituanRequestPlatformIdentifiers(
    'https://example.test/traffic?poiId=poi-1',
    '{"filters":{"shopId":"poi-1"}}',
  );
  assert.deepEqual(requestIdentifiers, ['poi-1']);
  assert.deepEqual(collectMeituanPlatformIdentifiers({ data: { poi_id: 'poi-1' } }), ['poi-1']);
  assert.equal(isMeituanOwnHotelPayloadKey('traffic'), true);
  assert.equal(isMeituanOwnHotelPayloadKey('peerRank'), false);

  const matched = evaluateMeituanPlatformIdentity(['store-1', 'poi-1'], requestIdentifiers);
  assert.equal(matched.status, 'matched');
  assert.equal(matched.source_validation, true);
  assert.equal(matched.validated_identifier, 'poi-1');

  const mismatch = evaluateMeituanPlatformIdentity(['poi-1'], ['poi-2']);
  assert.equal(mismatch.status, 'mismatch');
  assert.equal(mismatch.source_validation, false);

  const ambiguous = evaluateMeituanPlatformIdentity(['poi-1'], ['poi-1', 'poi-2']);
  assert.equal(ambiguous.status, 'ambiguous');
  assert.equal(ambiguous.source_validation, false);
});

test('collector never promotes the configured Profile key into OTA source identity', () => {
  const source = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  assert.match(source, /platform_identity_validation/);
  assert.match(source, /evaluateMeituanPlatformIdentity/);
  assert.doesNotMatch(source, /next\.storeId\s*=\s*storeId/);
  assert.doesNotMatch(source, /next\.store_id\s*=\s*storeId/);
});

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
  assert.match(source, /meituan_orders_purchase_date_query/);
  assert.match(source, /payload\.responses = payload\.responses\.filter/);
  assert.match(source, /requestQueryEvidence/);
  assert.match(source, /waitForPendingResponseCaptures/);
  assert.match(source, /inputValue\(\)/);
  assert.match(source, /collectMeituanOrderDomAggregate/);
  assert.match(source, /meituan_orders_target_date_summary/);
  assert.match(source, /compare_type: 'self'/);
  assert.match(source, /is_self: true/);
  assert.doesNotMatch(source, /dom:orders:target_date_summary'[\s\S]{0,500}_dom_text/);
});

test('headless login check retries the neutral eBooking entry before reporting login required', () => {
  const source = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  const ensureBlock = source.slice(source.indexOf('async function ensureLoggedIn'), source.indexOf('async function bringLoginPageToFront'));

  assert.match(ensureBlock, /if \(isHeadlessMode\(\)\) \{[\s\S]*page\.goto\(URLS\.login/);
  assert.match(ensureBlock, /page\.waitForLoadState\('networkidle'/);
  assert.match(ensureBlock, /if \(await looksLoggedIn\(page\)\) \{[\s\S]*status: 'logged_in'/);
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
    responses: [
      { section: 'traffic', status: 200, row_count: 1 },
      {
        section: 'orders',
        status: 200,
        row_count: 1,
        resource_type: 'xhr',
        content_type: 'application/json',
        data: { code: 0, data: { orderList: [{ orderId: 'hashed-in-production' }] } },
      },
    ],
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
      { section: 'orders', status: 200, row_count: 0, data: { code: 0, data: { orderList: [] } } },
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

test('accepts Meituan code 10000 all-zero order summary only with target-date query evidence', () => {
  const response = {
    section: 'orders',
    status: 200,
    row_count: 0,
    data: {
      code: 10000,
      error: null,
      data: {
        orderNumWithType: { 5: 0, 10: 0, 20: 0 },
        orderMarkTypes: [],
      },
    },
  };
  const staleResponseWithClickEvidence = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    section_evidence: {
      orders: {
        status: 'target_date_queried',
        target_date: '2026-07-14',
        evidence_source: 'page.form_readback',
        marker: 'meituan_orders_purchase_date_query',
        query_epoch: 2,
      },
    },
    responses: [{ ...response, query_epoch: 1, query_target_date: '2026-07-14', query_date_source: 'page.orders.purchase_date_input.readback' }],
    orders: [],
  }, ['orders'], { targetDate: '2026-07-14' });
  assert.equal(staleResponseWithClickEvidence.status, 'fail');
  assert.equal(staleResponseWithClickEvidence.section_statuses.orders, 'not_captured');

  const withDateEvidence = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    section_evidence: {
      orders: {
        status: 'target_date_queried',
        target_date: '2026-07-14',
        evidence_source: 'page.form_readback',
        marker: 'meituan_orders_purchase_date_query',
        query_epoch: 2,
      },
    },
    responses: [{ ...response, query_epoch: 2, query_target_date: '2026-07-14', query_date_source: 'page.orders.purchase_date_input.readback' }],
    orders: [],
  }, ['orders'], { targetDate: '2026-07-14' });
  assert.equal(withDateEvidence.status, 'pass');
  assert.equal(withDateEvidence.section_statuses.orders, 'empty_confirmed');
});

test('does not confirm an empty order day when Meituan summary counts are positive but rows were not parsed', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    section_evidence: {
      orders: {
        status: 'target_date_queried',
        target_date: '2026-07-14',
        evidence_source: 'page.form_readback',
        marker: 'meituan_orders_purchase_date_query',
        query_epoch: 3,
      },
    },
    responses: [{
      section: 'orders',
      status: 200,
      row_count: 0,
      query_epoch: 3,
      query_target_date: '2026-07-14',
      query_date_source: 'page.orders.purchase_date_input.readback',
      data: { code: 10000, error: null, data: { orderNumWithType: { 20: 1 } } },
    }],
    orders: [],
  }, ['orders'], { targetDate: '2026-07-14' });

  assert.equal(gate.status, 'fail');
  assert.equal(gate.section_statuses.orders, 'not_captured');
});

test('accepts only a privacy-safe target-date DOM order aggregate with matching visible purchase dates', () => {
  const base = {
    auth_status: { ok: true },
    section_evidence: {
      orders: {
        status: 'target_date_queried',
        target_date: '2026-07-14',
        evidence_source: 'page.form_readback',
        marker: 'meituan_orders_purchase_date_query',
        query_epoch: 4,
      },
    },
    responses: [{ section: 'traffic', status: 200, row_count: 0 }],
  };
  const row = {
    order_count: 6,
    orders: 6,
    visible_order_date_count: 6,
    visible_order_dates_match_target: true,
    dataDate: '2026-07-14',
    date_source: 'page.orders.purchase_date_input.readback',
    compare_type: 'self',
    is_self: true,
    query_epoch: 4,
    page_summary_marker: 'meituan_orders_target_date_summary',
    _capture_source: 'dom:orders:target_date_summary',
    _source_path: 'dom.orders.target_date_summary',
  };
  const gate = evaluateMeituanCaptureGate({ ...base, orders: [row] }, ['orders'], { targetDate: '2026-07-14' });
  assert.equal(gate.status, 'pass');
  assert.equal(gate.section_statuses.orders, 'captured');

  const mismatchedVisibleDates = evaluateMeituanCaptureGate({
    ...base,
    orders: [{ ...row, visible_order_date_count: 5 }],
  }, ['orders'], { targetDate: '2026-07-14' });
  assert.equal(mismatchedVisibleDates.status, 'fail');
  assert.equal(mismatchedVisibleDates.section_statuses.orders, 'not_captured');
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
      { section: 'orders', status: 200, row_count: 0, data: { code: 0, data: { orderCount: 0 } } },
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

test('does not treat an HTTP 200 Meituan order iframe HTML document as authoritative empty', () => {
  const gate = evaluateMeituanCaptureGate({
    auth_status: { ok: true },
    responses: [{
      section: 'orders',
      status: 200,
      row_count: 0,
      resource_type: 'document',
      content_type: 'text/html; charset=utf-8',
      data: { _raw_text: '<!doctype html><html><body>order iframe</body></html>' },
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
