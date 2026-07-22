import assert from 'node:assert/strict';
import test from 'node:test';

import { runCtripCookieApiCapture } from '../../scripts/ctrip_cookie_api_capture.mjs';

test('Ctrip Cookie/API traffic rows keep per-field desensitized response evidence', async () => {
  const payload = await runCtripCookieApiCapture({
    hotel_id: '6866634',
    system_hotel_id: 58,
    data_date: '2026-07-21',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/datacenter/api/queryFlowTransformNewV1?fixed=1',
      method: 'GET',
      payload: {
        dataDate: '2026-07-21',
        token: 'query-token-must-not-persist',
      },
    }],
  }, {
    cookies: 'session=cookie-must-not-persist',
    capturedAt: '2026-07-22T08:00:00.000Z',
    fetchImpl: async () => ({
      ok: true,
      status: 200,
      async text() {
        return JSON.stringify({
          rcode: 0,
          data: [{
            hotelId: '6866634',
            listExposure: 120,
            detailExposure: 30,
            flowRate: 25,
            orderFillingNum: 5,
            orderSubmitNum: null,
          }],
        });
      },
    }),
  });

  const row = payload.standard_rows.find((item) => (
    item.data_type === 'traffic'
    && item.compare_type === 'self'
    && item.raw_data?.field_facts?.some((fact) => fact.metric_key === 'list_exposure')
  ));
  assert.ok(row, 'cataloged traffic response must create a structured standard row');
  assert.match(row.source_trace_id, /^ctrip:[a-f0-9]{64}$/);
  assert.match(row.source_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(row.capture_evidence.source_trace_id, row.source_trace_id);
  assert.equal(row.capture_evidence.source_url_hash, row.source_url_hash);
  assert.deepEqual(row.raw_data.capture_evidence, row.capture_evidence);
  assert.equal(row.raw_data.source_trace_id, row.source_trace_id);
  assert.equal(row.raw_data.source_url_hash, row.source_url_hash);
  assert.equal(Object.hasOwn(row.raw_data, 'source_url'), false);
  assert.equal(payload.catalog_facts.some((fact) => Object.hasOwn(fact, 'source_url')), false);

  const facts = row.raw_data.field_facts;
  for (const metricKey of ['list_exposure', 'detail_visitor', 'flow_rate', 'order_page_visitor']) {
    const fact = facts.find((item) => item.metric_key === metricKey);
    assert.ok(fact, `${metricKey} must be emitted only because the response contained it`);
    assert.equal(fact.status, 'captured');
    assert.equal(fact.stored_value_present, true);
    assert.match(fact.source_path, /^data\.0\./);
    assert.match(fact.storage_field, /^online_daily_data\./);
    assert.deepEqual(fact.capture_evidence, row.capture_evidence);
  }
  const missingSubmit = facts.find((fact) => fact.metric_key === 'order_submit_user');
  assert.ok(missingSubmit, 'an explicitly null response field must stay an explicit missing fact');
  assert.equal(row.order_submit_num, null, 'a missing field must not survive as a structured zero placeholder');
  assert.equal(missingSubmit.status, 'missing');
  assert.equal(missingSubmit.missing_state, 'field_missing');
  assert.equal(missingSubmit.stored_value_present, false);
  assert.equal(Object.hasOwn(missingSubmit, 'value'), false);
  assert.deepEqual(missingSubmit.capture_evidence, row.capture_evidence);
  assert.equal(facts.some((fact) => fact.metric_key === 'visitor_rank'), false);

  const persisted = JSON.stringify(payload);
  assert.doesNotMatch(persisted, /query-token-must-not-persist/);
  assert.doesNotMatch(persisted, /cookie-must-not-persist/);
  assert.doesNotMatch(persisted, /\?fixed=1/);
  assert.doesNotMatch(persisted, /(?:cookie|authorization|token)=[^<]/i);
});

test('Ctrip Cookie/API keeps an explicitly captured zero distinct from missing', async () => {
  const payload = await runCtripCookieApiCapture({
    hotel_id: '6866634',
    system_hotel_id: 58,
    data_date: '2026-07-21',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/datacenter/api/queryFlowTransformNewV1',
      method: 'GET',
    }],
  }, {
    cookies: 'session=test-only',
    capturedAt: '2026-07-22T08:00:00.000Z',
    fetchImpl: async () => ({
      ok: true,
      status: 200,
      async text() {
        return JSON.stringify({
          rcode: 0,
          data: [{ hotelId: '6866634', listExposure: 0, orderSubmitNum: 0 }],
        });
      },
    }),
  });

  const row = payload.standard_rows.find((item) => item.data_type === 'traffic');
  assert.ok(row, 'an authoritative zero-valued response must still create a standard row');
  assert.equal(row.list_exposure, 0);
  assert.equal(row.order_submit_num, 0);
  const facts = new Map(row.raw_data.field_facts.map((fact) => [fact.metric_key, fact]));
  assert.equal(facts.get('list_exposure')?.status, 'captured');
  assert.equal(facts.get('order_submit_user')?.status, 'captured');
});
