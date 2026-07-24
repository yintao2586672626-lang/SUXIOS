import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const capture = readFileSync(
  new URL('../../scripts/ctrip_comment_browser_capture.mjs', import.meta.url),
  'utf8',
);

test('Ctrip capture reports per-source collection failures', () => {
  for (const source of [
    'ctrip_reviews',
    'ctrip_orders',
    'ctrip_im_sessions',
    'ctrip_im_order_links',
  ]) {
    assert.match(capture, new RegExp(`${source}: buildSourceStatus`));
  }
  assert.match(capture, /capture_status/);
  assert.match(capture, /missing_sources/);
  assert.match(capture, /reasons:/);
});

test('Ctrip capture output removes guest identity fields before storage', () => {
  assert.match(capture, /sanitizeCapturePayloadForStorage\(payload\)/);
  assert.match(capture, /members: \[\]/);
  assert.match(capture, /identity_fields_stored: false/);

  const sanitizer = capture.match(/function sanitizeCapturePayloadForStorage[\s\S]*$/)?.[0] ?? '';
  assert.ok(sanitizer, 'capture sanitizer must exist');
  assert.doesNotMatch(sanitizer, /guestName:/);
  assert.doesNotMatch(sanitizer, /guestUid:/);
  assert.doesNotMatch(sanitizer, /avatar:/);
});
