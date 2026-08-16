import test from 'node:test';
import assert from 'node:assert/strict';

import { readOtaResponseTextWithTimeout } from '../../scripts/lib/ota_response_body.mjs';

test('OTA response text reader returns a completed structured response body', async () => {
  let calls = 0;
  const body = await readOtaResponseTextWithTimeout({
    async text() {
      calls += 1;
      return '{"ok":true}';
    },
  }, { timeoutMs: 100 });

  assert.equal(body, '{"ok":true}');
  assert.equal(calls, 1);
});
test('OTA response text reader rejects a non-terminating body within the requested bound', async () => {
  const startedAt = Date.now();
  await assert.rejects(
    readOtaResponseTextWithTimeout({
      text() {
        return new Promise(() => {});
      },
    }, { timeoutMs: 25 }),
    error => error?.code === 'OTA_RESPONSE_BODY_TIMEOUT'
      && error?.message === 'ota_response_body_timeout',
  );

  assert.ok(Date.now() - startedAt < 500);
});
