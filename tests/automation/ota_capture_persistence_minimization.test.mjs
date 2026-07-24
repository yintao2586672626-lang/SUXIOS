import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { runCtripCookieApiCapture } from '../../scripts/ctrip_cookie_api_capture.mjs';

const commentCaptureSource = readFileSync(
  new URL('../../scripts/ctrip_comment_browser_capture.mjs', import.meta.url),
  'utf8',
);
const quickProbeSource = readFileSync(
  new URL('../../scripts/ctrip_quick_endpoint_probe.mjs', import.meta.url),
  'utf8',
);
const browserCaptureSource = readFileSync(
  new URL('../../scripts/ctrip_browser_capture.mjs', import.meta.url),
  'utf8',
);

test('Ctrip Cookie/API capture persists only redacted URL and sanitized response evidence', async () => {
  let observedRequestUrl = '';
  const payload = await runCtripCookieApiCapture({
    hotel_id: 'ctrip-58',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/custom/api/not-cataloged?fixed=1',
      method: 'GET',
      payload: {
        dataDate: '2026-07-22',
        token: 'query-secret-must-not-persist',
      },
      headers: {
        'X-Api-Key': 'header-secret-must-not-persist',
      },
    }],
  }, {
    cookies: 'session=cookie-secret-must-not-persist',
    fetchImpl: async (url) => {
      observedRequestUrl = url;
      return {
        ok: true,
        status: 200,
        async text() {
          return JSON.stringify({
            ok: true,
            data: { count: 1 },
            access_token: 'response-secret-must-not-persist',
          });
        },
      };
    },
    capturedAt: '2026-07-22T08:00:00.000Z',
  });

  assert.match(observedRequestUrl, /query-secret-must-not-persist/);
  assert.equal(payload.xhr_urls[0].url, 'https://ebooking.ctrip.com/custom/api/not-cataloged');
  assert.match(payload.xhr_urls[0].source_url_hash, /^[a-f0-9]{64}$/);
  assert.equal(payload.responses[0].url, 'https://ebooking.ctrip.com/custom/api/not-cataloged');
  assert.match(payload.responses[0].source_url_hash, /^[a-f0-9]{64}$/);

  const persisted = JSON.stringify(payload);
  for (const secret of [
    'query-secret-must-not-persist',
    'header-secret-must-not-persist',
    'cookie-secret-must-not-persist',
    'response-secret-must-not-persist',
  ]) {
    assert.doesNotMatch(persisted, new RegExp(secret));
  }
});

test('Ctrip comment capture drops raw response bodies before writing output', () => {
  assert.doesNotMatch(commentCaptureSource, /row_count:\s*rows\.length,\s*data:\s*body/);
  assert.match(commentCaptureSource, /function sanitizeCapturedResponseMetadata/);
  const sanitizer = commentCaptureSource.match(/function sanitizeCapturePayloadForStorage[\s\S]*?\n}\n\nfunction redactEvidenceUrl/)?.[0] ?? '';
  assert.ok(sanitizer, 'comment capture storage sanitizer must exist');
  assert.match(sanitizer, /sanitizeCapturedResponseMetadata/);
  assert.doesNotMatch(sanitizer, /\.\.\.response/);
});

test('Ctrip quick endpoint probe stores no raw query strings or request bodies', () => {
  assert.match(quickProbeSource, /sanitizeOtaObservedUrl/);
  assert.match(quickProbeSource, /source_url_hash/);
  assert.match(quickProbeSource, /source_trace_id/);
  assert.match(quickProbeSource, /persistRawSourceUrl:\s*false/);
  assert.match(quickProbeSource, /captureEvidence:\s*\{/);
  assert.match(quickProbeSource, /request_payload_present/);
  assert.doesNotMatch(quickProbeSource, /post_data:\s*request\.postData\(\)/);
  assert.doesNotMatch(quickProbeSource, /target\.xhr_urls\.push\(\{\s*url,/);
});

test('Ctrip Profile catalog rows persist only desensitized response evidence', () => {
  assert.doesNotMatch(browserCaptureSource, /target\.xhr_urls\.push\(\{\s*url,/);
  assert.match(browserCaptureSource, /persistRawSourceUrl:\s*false/);
  assert.match(browserCaptureSource, /captureEvidence:\s*responseEvidence/);
  assert.match(browserCaptureSource, /sourceTraceId:\s*responseEvidence\.source_trace_id/);
  assert.match(browserCaptureSource, /sourceUrlHash:\s*responseEvidence\.source_url_hash/);
  assert.match(browserCaptureSource, /next\.raw_data\.field_facts\s*=\s*next\.raw_data\.field_facts\.filter/);
});
