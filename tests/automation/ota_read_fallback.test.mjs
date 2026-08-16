import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  classifyOtaReadFallbackRequest,
  createOtaReadFallbackState,
  evaluateOtaReadFallbackEligibility,
  listOtaReadFallbackTemplates,
  observeOtaReadFallbackRequest,
  replayObservedOtaReadRequests,
} from '../../scripts/lib/ota_read_fallback.mjs';

test('allowlist accepts only known Ctrip and Meituan read endpoints', () => {
  const ctrip = classifyOtaReadFallbackRequest('ctrip', {
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
    method: 'POST',
    resourceType: 'xhr',
  }, {
    section: 'business_overview',
    endpointId: 'business_realtime',
  });
  assert.equal(ctrip.accepted, true);
  assert.equal(ctrip.endpoint_id, 'business_realtime');
  assert.equal(ctrip.section, 'business_overview');

  const meituanBusiness = classifyOtaReadFallbackRequest('meituan', {
    url: 'https://eb.meituan.com/api/v1/ebooking/home/businessData?poiId=masked',
    method: 'GET',
    resourceType: 'fetch',
  });
  assert.equal(meituanBusiness.accepted, true);
  assert.equal(meituanBusiness.endpoint_id, 'meituan_business_data');

  const meituanTraffic = classifyOtaReadFallbackRequest('meituan', {
    url: 'https://eb.meituan.com/api/v1/ebooking/dataCenter/home/traffic',
    method: 'POST',
    resourceType: 'xhr',
  });
  assert.equal(meituanTraffic.accepted, true);
  assert.equal(meituanTraffic.endpoint_id, 'meituan_traffic_home');
});

test('allowlist rejects writes, untrusted hosts, insecure URLs, and unsupported methods', () => {
  for (const [candidate, expectedReason] of [
    [{
      url: 'https://eb.meituan.com/api/delete/ebooking/home/businessData',
      method: 'POST',
      resourceType: 'xhr',
    }, 'write_like_path'],
    [{
      url: 'https://evil.example/api/v1/ebooking/home/businessData',
      method: 'GET',
      resourceType: 'fetch',
    }, 'untrusted_host'],
    [{
      url: 'http://eb.meituan.com/api/v1/ebooking/home/businessData',
      method: 'GET',
      resourceType: 'fetch',
    }, 'https_required'],
    [{
      url: 'https://eb.meituan.com/api/v1/ebooking/home/businessData',
      method: 'DELETE',
      resourceType: 'xhr',
    }, 'unsupported_method'],
    [{
      url: 'https://eb.meituan.com/api/v1/ebooking/home/businessData',
      method: 'GET',
      resourceType: 'document',
    }, 'unsupported_resource_type'],
  ]) {
    const result = classifyOtaReadFallbackRequest('meituan', candidate);
    assert.equal(result.accepted, false);
    assert.equal(result.reason, expectedReason);
  }
});

test('observed request templates dedupe and expose only sanitized metadata', () => {
  const state = createOtaReadFallbackState('meituan');
  const frame = fakeFrame('https://eb.meituan.com/newhb-sub-app/data-center-pc/home/index.html');
  const request = fakeRequest({
    url: 'https://eb.meituan.com/api/v1/ebooking/home/businessData?poiId=sensitive-poi',
    method: 'GET',
    headers: {
      Accept: 'application/json',
      Authorization: 'Bearer must-never-be-exposed',
      Cookie: 'session=must-never-be-exposed',
    },
    frame,
  });

  const first = observeOtaReadFallbackRequest(state, request);
  assert.equal(first.captured, true);
  const second = observeOtaReadFallbackRequest(state, request, {
    dateContext: {
      selected: true,
      target_date: '2026-07-29',
      relative_range: '\u6628\u65e5',
      evidence_source: 'page.business_period_selection.readback',
      marker: 'meituan_business_yesterday_tab',
      business_capture_epoch: 3,
    },
  });
  assert.equal(second.captured, false);
  assert.equal(second.reason, 'duplicate_template_refreshed');
  assert.equal(state.captured_template_count, 1);

  const templates = listOtaReadFallbackTemplates(state);
  assert.equal(templates.length, 1);
  assert.equal(templates[0].date_context.selected, true);
  assert.equal(templates[0].date_context.business_capture_epoch, 3);
  assert.equal(Object.hasOwn(templates[0], 'url'), false);
  assert.equal(Object.hasOwn(templates[0], 'body'), false);
  assert.equal(Object.hasOwn(templates[0], 'headers'), false);
  const serialized = JSON.stringify({ state, first, second, templates });
  assert.doesNotMatch(serialized, /sensitive-poi|must-never-be-exposed|Authorization|Cookie/i);
});

test('Meituan historical business replay requires matching visible period evidence', () => {
  const unverified = {
    platform: 'meituan',
    endpoint_id: 'meituan_business_data',
    request_date: '',
    date_context: {
      selected: false,
      target_date: '',
      relative_range: '',
      evidence_source: '',
      business_capture_epoch: 0,
    },
  };
  assert.deepEqual(
    evaluateOtaReadFallbackEligibility(unverified, {
      dataPeriod: 'historical_daily',
      targetDate: '2026-07-29',
      expectedRelativeRange: '\u6628\u65e5',
    }),
    { eligible: false, reason: 'target_date_unverified' },
  );

  const verified = {
    ...unverified,
    date_context: {
      selected: true,
      target_date: '2026-07-29',
      relative_range: '\u6628\u65e5',
      evidence_source: 'page.business_period_selection.readback',
      business_capture_epoch: 7,
    },
  };
  assert.deepEqual(
    evaluateOtaReadFallbackEligibility(verified, {
      dataPeriod: 'historical_daily',
      targetDate: '2026-07-29',
      expectedRelativeRange: '\u6628\u65e5',
      requiredCaptureEpoch: 7,
    }),
    { eligible: true, reason: 'verified_historical_page_selection' },
  );
  assert.equal(
    evaluateOtaReadFallbackEligibility(verified, {
      dataPeriod: 'historical_daily',
      targetDate: '2026-07-28',
      expectedRelativeRange: '\u6628\u65e5',
    }).eligible,
    false,
  );
});

test('target-date replay blocks Ctrip templates without request-date evidence', () => {
  assert.deepEqual(
    evaluateOtaReadFallbackEligibility({
      platform: 'ctrip',
      endpoint_id: 'traffic_flow_transform',
      request_date: '',
    }, {
      targetDate: '2026-08-08',
    }),
    { eligible: false, reason: 'target_date_unverified' },
  );
});

test('blocked replay diagnostics expose only the safe request business date', async () => {
  const state = createOtaReadFallbackState('ctrip');
  observeOtaReadFallbackRequest(state, fakeRequest({
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/queryFlowTransformNewV1',
    method: 'POST',
    body: '{"startDate":"2026-08-08","hotelId":"must-not-leak"}',
    headers: { 'Content-Type': 'application/json' },
    frame: fakeFrame('https://ebooking.ctrip.com/datacenter/report'),
  }), {
    section: 'traffic_report',
    endpointId: 'traffic_flow_transform',
    requestDateEvidence: {
      date: '2026-08-08',
      date_source: 'request.payload.startDate',
    },
  });

  const diagnostics = await replayObservedOtaReadRequests(
    { url: () => 'about:blank', frames: () => [] },
    state,
    { section: 'traffic_report', targetDate: '2026-08-09', shouldReplay: () => true },
  );
  assert.equal(diagnostics[0].status, 'blocked');
  assert.equal(diagnostics[0].reason, 'target_date_mismatch');
  assert.equal(diagnostics[0].request_date, '2026-08-08');
  assert.equal(diagnostics[0].request_date_source, 'request.payload.startDate');
  assert.doesNotMatch(JSON.stringify(diagnostics), /must-not-leak/);
});

test('same-origin replay reuses the observed request but diagnostics remain redacted', async () => {
  let evaluatedInput = null;
  const frame = fakeFrame(
    'https://ebooking.ctrip.com/datacenter/report',
    async (_callback, input) => {
      evaluatedInput = input;
      return { transport_ok: true, ok: true, status: 200 };
    },
  );
  const state = createOtaReadFallbackState('ctrip');
  observeOtaReadFallbackRequest(state, fakeRequest({
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/sale/fetchMarketOverViewV2?token=sensitive-query',
    method: 'POST',
    body: '{"hotelId":"sensitive-hotel-77"}',
    headers: {
      'Content-Type': 'application/json',
      Authorization: 'Bearer sensitive-authorization',
    },
    frame,
  }), {
    section: 'business_overview',
    endpointId: 'business_market_overview',
  });

  const diagnostics = await replayObservedOtaReadRequests(
    { url: () => 'about:blank', frames: () => [frame] },
    state,
    {
      section: 'business_overview',
      maxAttempts: 1,
      shouldReplay: () => true,
    },
  );
  assert.equal(evaluatedInput.body, '{"hotelId":"sensitive-hotel-77"}');
  assert.match(evaluatedInput.url, /sensitive-query/);
  assert.equal(evaluatedInput.headers.authorization, undefined);
  assert.equal(evaluatedInput.timeoutMs, 12000);
  assert.equal(diagnostics.length, 1);
  assert.equal(diagnostics[0].status, 'response_observed');
  assert.equal(diagnostics[0].replay_source, 'observed_request_same_origin');
  const serialized = JSON.stringify({ state, diagnostics });
  assert.doesNotMatch(serialized, /sensitive-query|sensitive-hotel-77|sensitive-authorization/i);
  assert.equal(diagnostics[0].sensitive_values_exposed, false);
});

test('replay is blocked when no matching same-origin page or frame remains', async () => {
  let evaluated = false;
  const observedFrame = fakeFrame('https://other.example/frame', async () => {
    evaluated = true;
    return { transport_ok: true, ok: true, status: 200 };
  });
  const state = createOtaReadFallbackState('ctrip');
  observeOtaReadFallbackRequest(state, fakeRequest({
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportRealTimeDate',
    method: 'GET',
    frame: observedFrame,
  }), {
    section: 'business_overview',
    endpointId: 'business_realtime',
  });

  const diagnostics = await replayObservedOtaReadRequests(
    {
      url: () => 'https://unrelated.example/page',
      frames: () => [],
      evaluate: async () => {
        evaluated = true;
      },
    },
    state,
    {
      section: 'business_overview',
      shouldReplay: () => true,
    },
  );
  assert.equal(evaluated, false);
  assert.equal(diagnostics[0].status, 'blocked');
  assert.equal(diagnostics[0].reason, 'same_origin_context_unavailable');
});

test('collectors invoke the shared bounded fallback and persist only diagnostics', () => {
  const ctripSource = readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8');
  const meituanSource = readFileSync('scripts/meituan_browser_capture.mjs', 'utf8');
  for (const source of [ctripSource, meituanSource]) {
    assert.match(source, /createOtaReadFallbackState/);
    assert.match(source, /observeOtaReadFallbackRequest/);
    assert.match(source, /replayObservedOtaReadRequests/);
    assert.match(source, /read_fallbacks/);
  }
  assert.match(ctripSource, /ctripEndpointNeedsReadFallback/);
  assert.match(meituanSource, /requiredCaptureEpoch: epoch/);
  assert.match(meituanSource, /business_row_count: businessRowCount/);
});

function fakeRequest({
  url,
  method = 'GET',
  resourceType = 'fetch',
  body = '',
  headers = {},
  frame = null,
}) {
  return {
    url: () => url,
    method: () => method,
    resourceType: () => resourceType,
    postData: () => body,
    headers: () => headers,
    frame: () => frame,
  };
}

function fakeFrame(url, evaluate = async () => ({ transport_ok: true, ok: true, status: 200 })) {
  return {
    url: () => url,
    evaluate,
  };
}
