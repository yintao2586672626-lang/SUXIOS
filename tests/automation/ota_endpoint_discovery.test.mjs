import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
  buildOtaEndpointDiscoveryCandidate,
  classifyOtaEndpointDiscoveryResponse,
  upsertOtaEndpointDiscoveryCandidate,
} from '../../scripts/lib/ota_endpoint_discovery.mjs';

test('auto-discovers a Meituan traffic JSON response without retaining query values', () => {
  const candidate = buildOtaEndpointDiscoveryCandidate('meituan', {
    url: 'https://api-eb.meituan.com/api/v2/merchant/insight/overview?poiId=123&token=do-not-store',
    method: 'POST',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json; charset=utf-8',
    requestedSections: ['traffic'],
    body: {
      code: 0,
      data: {
        businessData: {
          listExposure: 120,
          detailExposure: 60,
          conversionRate: 0.08,
        },
      },
    },
  });

  assert.equal(candidate.candidate_section, 'traffic');
  assert.equal(candidate.confidence, 'high');
  assert.equal(candidate.auto_capture, true);
  assert.equal(candidate.safe_route, 'api-eb.meituan.com/api/v2/merchant/insight/overview');
  assert.equal(candidate.requested_sections[0], 'traffic');
  assert.doesNotMatch(JSON.stringify(candidate), /do-not-store|poiId=123|token=/i);
});

test('auto-discovers Ctrip competition-circle operating fields on the active business surface', () => {
  const candidate = buildOtaEndpointDiscoveryCandidate('ctrip', {
    url: 'https://ebooking.ctrip.com/datacenter/inland/market/v3/compare?hotelId=987&ticket=secret',
    method: 'POST',
    status: 200,
    resourceType: 'fetch',
    contentType: 'application/json',
    requestedSections: ['competitor_overview'],
    body: {
      data: {
        competitorHotelList: [{
          competitorHotel: 'peer-a',
          competitorRank: 2,
          competitorHotelTotal: 18,
          saleAmount: 2300,
        }],
      },
    },
  });

  assert.equal(candidate.candidate_section, 'business');
  assert.equal(candidate.confidence, 'high');
  assert.equal(candidate.auto_capture, true);
  assert.ok(candidate.matched_key_terms.includes('competitorhotellist'));
  assert.doesNotMatch(JSON.stringify(candidate), /987|secret|ticket=/i);
});

test('supports first-party Meituan gateway drift but only when business payload keys are present', () => {
  const candidate = buildOtaEndpointDiscoveryCandidate('meituan', {
    url: 'https://merchant-gateway.sankuai.com/v4/review/stream',
    method: 'GET',
    status: 200,
    resourceType: 'xhr',
    contentType: 'text/plain',
    body: {
      data: {
        commentList: [{
          commentId: 'comment-1',
          commentContent: 'clean room',
          commentScore: 5,
        }],
      },
    },
  });

  assert.equal(candidate.candidate_section, 'reviews');
  assert.equal(candidate.auto_capture, true);

  const irrelevant = buildOtaEndpointDiscoveryCandidate('meituan', {
    url: 'https://merchant-gateway.sankuai.com/v4/bootstrap',
    method: 'GET',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
    body: {
      code: 0,
      data: {
        menuList: ['home'],
        theme: 'dark',
      },
    },
  });
  assert.equal(irrelevant, null);
});

test('rejects account, permission, non-AJAX, and third-party responses', () => {
  assert.equal(classifyOtaEndpointDiscoveryResponse('meituan', {
    url: 'https://me.meituan.com/api/account/query',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  }).eligible, false);

  assert.equal(classifyOtaEndpointDiscoveryResponse('ctrip', {
    url: 'https://ebooking.ctrip.com/api/permissions/list',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  }).eligible, false);

  assert.equal(classifyOtaEndpointDiscoveryResponse('meituan', {
    url: 'https://eb.meituan.com/api/business/traffic',
    status: 200,
    resourceType: 'document',
    contentType: 'application/json',
  }).eligible, false);

  assert.equal(buildOtaEndpointDiscoveryCandidate('ctrip', {
    url: 'https://evil.example/api/business/traffic',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
    body: { data: { listExposure: 1, detailExposure: 1 } },
  }), null);
});

test('never persists secret field names or values in endpoint candidates', () => {
  const candidate = buildOtaEndpointDiscoveryCandidate('meituan', {
    url: 'https://eb.meituan.com/api/v2/merchant/reservation/search',
    method: 'POST',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
    body: {
      accessToken: 'secret-token-value',
      data: {
        orderList: [{
          orderId: 'raw-order-id',
          orderNo: 'raw-order-number',
        }],
      },
    },
  });
  const serialized = JSON.stringify(candidate);

  assert.equal(candidate.candidate_section, 'orders');
  assert.equal(candidate.auto_capture, true);
  assert.doesNotMatch(serialized, /accessToken|secret-token-value|raw-order-id|raw-order-number/);
});

test('deduplicates endpoint candidates by platform, URL hash, and section', () => {
  const candidate = buildOtaEndpointDiscoveryCandidate('meituan', {
    url: 'https://eb.meituan.com/api/v2/merchant/traffic/overview',
    method: 'GET',
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
    body: { data: { listExposure: 12, detailExposure: 4 } },
  });
  const once = upsertOtaEndpointDiscoveryCandidate([], candidate);
  const twice = upsertOtaEndpointDiscoveryCandidate(once, candidate);

  assert.equal(twice.length, 1);
  assert.equal(twice[0].observed_count, 2);
});

test('both browser collectors route discovered business responses into their existing capture paths', () => {
  const meituanSource = readFileSync(new URL('../../scripts/meituan_browser_capture.mjs', import.meta.url), 'utf8');
  const ctripSource = readFileSync(new URL('../../scripts/ctrip_browser_capture.mjs', import.meta.url), 'utf8');

  assert.match(meituanSource, /buildOtaEndpointDiscoveryCandidate\('meituan'/);
  assert.match(meituanSource, /xhr:auto_discovered:\$\{section\}/);
  assert.match(meituanSource, /autoDiscovered && rows\.length === 0/);
  assert.match(ctripSource, /buildOtaEndpointDiscoveryCandidate\('ctrip'/);
  assert.match(ctripSource, /resolveCtripDiscoveredSection/);
  assert.match(ctripSource, /allowDiscoveredBusiness/);
  assert.match(ctripSource, /autoDiscovered[\s\S]*rows\.length === 0/);
});
