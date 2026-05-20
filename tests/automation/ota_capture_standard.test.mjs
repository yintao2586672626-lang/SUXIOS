import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildCapturePlan,
  buildCookieInjectionPlan,
  classifyOtaResponse,
  normalizeCaptureSections,
  parseCookieHeader,
} from '../../scripts/lib/ota_capture_standard.mjs';

test('normalizes OTA capture sections per platform', () => {
  assert.deepEqual(normalizeCaptureSections('meituan', 'comment,flow,order'), ['reviews', 'traffic', 'orders']);
  assert.deepEqual(normalizeCaptureSections('ctrip', 'business,review'), ['business', 'reviews']);
  assert.deepEqual(normalizeCaptureSections('meituan', ''), ['reviews', 'traffic', 'orders']);
});

test('builds a capture plan with profile and cookie injection metadata', () => {
  const plan = buildCapturePlan({
    platform: 'meituan',
    storeId: '68471',
    sections: 'comment,flow,order',
    profileDir: 'storage/custom_meituan_profile',
    cookies: 'token=abc; poi=68471',
  });

  assert.equal(plan.platform, 'meituan');
  assert.equal(plan.profile.id, '68471');
  assert.equal(plan.profile.storageDir, 'storage/custom_meituan_profile');
  assert.deepEqual(plan.sections, ['reviews', 'traffic', 'orders']);
  assert.deepEqual(plan.cookies.domains, ['me.meituan.com', 'eb.meituan.com', '.meituan.com', '.dianping.com']);
  assert.equal(plan.cookies.cookies.length, 8);
});

test('parses cookie headers without accepting malformed names', () => {
  assert.deepEqual(parseCookieHeader('a=1; empty; b=two=2; bad name=x'), [
    { name: 'a', value: '1' },
    { name: 'b', value: 'two=2' },
  ]);

  assert.throws(() => buildCookieInjectionPlan('ctrip', 'broken'), /empty or invalid Cookie header/);
});

test('classifies OTA JSON responses by platform and section', () => {
  const meituanReview = classifyOtaResponse('meituan', 'https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo', {
    status: 200,
    resourceType: 'xhr',
    contentType: 'application/json',
  });
  assert.equal(meituanReview.capture, true);
  assert.equal(meituanReview.section, 'reviews');

  const ctripTraffic = classifyOtaResponse('ctrip', 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1', {
    status: 200,
    resourceType: 'fetch',
    contentType: 'application/json',
  });
  assert.equal(ctripTraffic.capture, true);
  assert.equal(ctripTraffic.section, 'traffic');

  const asset = classifyOtaResponse('meituan', 'https://p0.meituan.net/assets/logo.png', {
    status: 200,
    resourceType: 'image',
    contentType: 'image/png',
  });
  assert.equal(asset.capture, false);
  assert.equal(asset.reason, 'non_business_resource');
});
