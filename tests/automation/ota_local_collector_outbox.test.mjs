import assert from 'node:assert/strict';
import { mkdtemp, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { executeTask, extractSanitizedRows, runOneCycle, classifyCaptureFailure, parseArgs } from '../../scripts/ota_local_collector.mjs';
import {
  collectorApiRequest, deliverPendingResult, hashResultJson, MAX_RESULT_ENVELOPE_BYTES,
  persistPendingResult, readPendingResults, retryBlockedResult,
} from '../../scripts/lib/ota_local_collector_outbox.mjs';

const device = {
  server: 'http://collector.test', device_public_id: 'test-device', device_token: 'synthetic-device-secret',
};
const task = {
  id: 42, attempt: 1, account_id: 6, system_hotel_id: 12, platform: 'meituan',
  platform_hotel_id: 'MT-12', data_date: '2026-09-01', task_type: 'collect',
  account_alias: '合成测试账户', lease_token: 'synthetic-original-lease', profile_key_hash: 'synthetic-private-profile',
};
const logger = { log() {} };
const result = { success: true, rows: [{ id: '1', visitors: 18, platform_hotel_id: 'MT-12' }], capture_summary: {} };
test('redirects remain unverified and retry-result selects the original task without a new capture command', () => {
  for (const status of ['http_301', 'http_302', '302', 'redirect_unverified', 'http_307', 'http_308']) {
    assert.equal(classifyCaptureFailure({ error_code: status }, 1), 'redirect_unverified');
  }
  assert.equal(classifyCaptureFailure({ error_code: 'login_expired' }, 1), 'login_required');
  assert.deepEqual(parseArgs(['retry-result', '--task-id=42', '--result-id=synthetic-result']), { _: ['retry-result'], taskId: '42', resultId: 'synthetic-result' });
});
const uploadReady = entry => ({
  status: 'upload_ready', task_id: entry.task_id, lease_token: 'synthetic-upload-lease',
  lease_expires_at: '2026-09-05 15:30:00', result_id: entry.result_id, result_hash: entry.result_hash, attempt: entry.attempt,
});
const accepted = (entry, status = 'success') => ({
  status, summary: { saved_count: 1 },
  delivery: { status: 'accepted', task_id: entry.task_id, attempt: entry.attempt, result_id: entry.result_id, result_hash: entry.result_hash },
});

test('an accepted historical receipt with an unknown current readback is retained without another upload', async t => {
  const options = await directories(t);
  const entry = await persistPendingResult(device, task, result, options);
  let calls = 0;
  await assert.rejects(deliverPendingResult(device, entry, { ...options, request: async (server, path) => {
    calls++;
    assert.match(path, /resume-upload$/);
    return { ...accepted(entry), status: 'result_unknown', reconciliation: { status: 'unknown' } };
  } }), { code: 'result_ack_invalid' });
  assert.equal(calls, 1);
  assert.equal((await onlyEntry(options.directory)).result_hash, entry.result_hash);
});

test('explicit blocked recovery revalidates the same result without editing its identity', async t => {
  const options = await directories(t);
  const entry = await persistPendingResult(device, task, result, { ...options, blocked: { code: 'http_403' } });
  const response = await retryBlockedResult(device, task.id, { ...options, request: async (server, path, request) => {
    assert.match(path, /resume-upload$/);
    assert.equal(request.body.result_hash, entry.result_hash);
    assert.equal(request.body.attempt, entry.attempt);
    return accepted(entry);
  } });
  assert.equal(response.status, 'success');
  assert.deepEqual(await readPendingResults(options.directory), []);
});

test('wrong attempt upload permission never sends the business body', async t => {
  const options = await directories(t);
  const entry = await persistPendingResult(device, task, result, options);
  let calls = 0;
  await assert.rejects(deliverPendingResult(device, entry, { ...options, request: async () => {
    calls++;
    return { ...uploadReady(entry), attempt: 2 };
  } }), { code: 'result_ack_invalid' });
  assert.equal(calls, 1);
  assert.equal((await readPendingResults(options.directory)).length, 1);
});
async function directories(t) {
  const root = await mkdtemp(join(tmpdir(), 'suxios-outbox-test-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  return { root, directory: join(root, 'outbox'), resultsDirectory: join(root, 'results'), logger };
}
async function onlyEntry(directory) {
  const entries = await readPendingResults(directory);
  assert.equal(entries.length, 1);
  return entries[0];
}
function payloadWithRows(count) {
  return { traffic: Array.from({ length: count }, (_, index) => ({
    id: `row-${index}`, visitors: index, platform_hotel_id: task.platform_hotel_id,
  })) };
}

test('outbox persists complete sanitized business evidence without device, lease, or Profile secrets', async t => {
  const options = await directories(t);
  const business = {
    ...result,
    rows: [{ ...result.rows[0], note: '真实业务中文'.repeat(5_000), cookie: 'synthetic-cookie-secret',
      sessionToken: 'synthetic-session-secret', profilePath: 'synthetic-profile-path',
      request_headers: { Authorization: 'synthetic-authorization' } }],
  };
  const pending = await persistPendingResult(device, task, business, options);
  const recovered = await onlyEntry(options.directory);
  assert.equal(recovered.result_json, pending.result_json);
  assert.equal(hashResultJson(recovered.result_json), recovered.result_hash);
  assert.equal(JSON.parse(recovered.result_json).rows[0].note, business.rows[0].note);
  assert.deepEqual(recovered.scope, {
    account_id: 6, system_hotel_id: 12, platform: 'meituan', platform_hotel_id: 'MT-12', data_date: '2026-09-01',
  });
  const text = await readFile(join(options.directory, (await readdir(options.directory))[0]), 'utf8');
  for (const forbidden of [device.device_token, task.lease_token, task.profile_key_hash, 'synthetic-cookie-secret',
    'synthetic-session-secret', 'synthetic-profile-path', 'synthetic-authorization', 'lease_token', 'device_token']) {
    assert.equal(text.includes(forbidden), false, `${forbidden} must not be persisted`);
  }
});

test('upload failure survives restart and recovery precedes heartbeat without a second capture', async t => {
  const options = await directories(t);
  const calls = [];
  let captureCount = 0;
  let failUpload = true;
  let originalResultJson;
  const capture = async (_task, outputPath) => {
    captureCount += 1;
    await writeFile(outputPath, JSON.stringify(payloadWithRows(2)));
    return { exitCode: 0 };
  };
  const request = async (_server, path, input) => {
    calls.push(path);
    if (path.endsWith('/heartbeat')) return {};
    if (path.endsWith('/next')) return { status: 'leased', task };
    if (path.endsWith('/progress')) return { status: 'running' };
    const entry = await onlyEntry(options.directory);
    if (path.endsWith('/resume-upload')) {
      assert.deepEqual(input.body, { result_id: entry.result_id, result_hash: entry.result_hash, attempt: 1 });
      return uploadReady(entry);
    }
    assert.equal(path, '/api/ota-local-collector/tasks/42/result');
    assert.equal(input.body.lease_token, 'synthetic-upload-lease');
    assert.equal(input.body.attempt, 1);
    assert.equal(hashResultJson(input.body.result_json), input.body.result_hash);
    originalResultJson ??= input.body.result_json;
    assert.equal(input.body.result_json, originalResultJson);
    if (failUpload) throw new Error('synthetic network interruption');
    return accepted(entry);
  };
  await assert.rejects(runOneCycle(device, { ...options, request, capture }), /synthetic network interruption/);
  assert.equal(captureCount, 1);
  assert.equal((await readPendingResults(options.directory)).length, 1);
  calls.length = 0;
  failUpload = false;
  const recovered = await runOneCycle(device, { ...options, request, capture });
  assert.equal(recovered.restored, true);
  assert.deepEqual(calls, ['/api/ota-local-collector/tasks/42/resume-upload', '/api/ota-local-collector/tasks/42/result']);
  assert.equal(captureCount, 1);
  assert.deepEqual(await readPendingResults(options.directory), []);
});

test('a lost committed response uses the same receipt without reuploading a field-gap result', async t => {
  const options = await directories(t);
  const pending = await persistPendingResult(device, task, result, options);
  let committed = null;
  let uploads = 0;
  const request = async (_server, path) => {
    if (path.endsWith('/resume-upload')) return committed || uploadReady(pending);
    uploads += 1;
    committed = accepted(pending, 'field_gap');
    throw new Error('response lost after commit');
  };
  await assert.rejects(deliverPendingResult(device, pending, { ...options, request }), /response lost/);
  const response = await deliverPendingResult(device, await onlyEntry(options.directory), { ...options, request });
  assert.equal(response.status, 'field_gap');
  assert.equal(uploads, 1);
  assert.deepEqual(await readPendingResults(options.directory), []);
});

test('a mismatched or absent receipt never acknowledges or deletes pending evidence', async t => {
  for (const change of [
    response => ({ status: 'success' }),
    response => ({ ...response, delivery: { ...response.delivery, task_id: 99 } }),
    response => ({ ...response, delivery: { ...response.delivery, attempt: 2 } }),
    response => ({ ...response, delivery: { ...response.delivery, result_id: 'wrong' } }),
    response => ({ ...response, delivery: { ...response.delivery, result_hash: '0'.repeat(64) } }),
  ]) {
    const options = await directories(t);
    const pending = await persistPendingResult(device, task, result, options);
    const request = async (_server, path) => path.endsWith('/resume-upload')
      ? uploadReady(pending) : change(accepted(pending));
    await assert.rejects(deliverPendingResult(device, pending, { ...options, request }), { code: 'result_ack_invalid' });
    assert.equal((await onlyEntry(options.directory)).result_hash, pending.result_hash);
  }
});

test('stale attempt conflict stays blocked on restart and never requests another task', async t => {
  const options = await directories(t);
  await persistPendingResult(device, task, result, options);
  let requests = 0;
  const request = async () => {
    requests += 1;
    const error = new Error('synthetic expired attempt');
    error.status = 409;
    throw error;
  };
  await assert.rejects(runOneCycle(device, { ...options, request }), { code: 'http_409', retryable: false });
  assert.equal((await onlyEntry(options.directory)).blocked.code, 'http_409');
  await assert.rejects(runOneCycle(device, { ...options, request }), { code: 'http_409', retryable: false });
  assert.equal(requests, 1);
});

test('pending evidence cannot be sent through a different paired device or server', async t => {
  const options = await directories(t);
  const pending = await persistPendingResult(device, task, result, options);
  let calls = 0;
  for (const wrongDevice of [{ ...device, device_public_id: 'other-device' }, { ...device, server: 'http://other.test' }]) {
    await assert.rejects(deliverPendingResult(wrongDevice, pending, {
      ...options, request: async () => { calls += 1; },
    }), { code: 'outbox_device_mismatch', retryable: false });
  }
  assert.equal(calls, 0);
  assert.equal((await onlyEntry(options.directory)).result_hash, pending.result_hash);
});

test('request timeout aborts both a stalled connection and a stalled response body', async () => {
  for (const stage of ['connection', 'body']) {
    let aborted = false;
    const fetchFn = (_url, { signal }) => {
      const waitForAbort = () => new Promise((_, reject) => signal.addEventListener('abort', () => {
        aborted = true;
        reject(new Error('synthetic abort'));
      }, { once: true }));
      return stage === 'connection' ? waitForAbort() : Promise.resolve({ ok: true, status: 200, text: waitForAbort });
    };
    await assert.rejects(collectorApiRequest(device.server, '/test', { timeoutMs: 10, fetchFn }), { code: 'request_timeout' });
    assert.equal(aborted, true);
  }
});

test('HTTP success with invalid JSON is not accepted and rejected bodies do not leak credentials', async () => {
  for (const body of ['', '<html>synthetic secret</html>', 'null', '[]']) {
    await assert.rejects(collectorApiRequest(device.server, '/test', {
      fetchFn: async () => ({ ok: true, status: 200, text: async () => body }),
    }), { code: 'invalid_response' });
  }
  await assert.rejects(collectorApiRequest(device.server, '/test', {
    fetchFn: async () => ({ ok: false, status: 409, text: async () => 'synthetic credential in error page' }),
  }), error => error.status === 409 && error.retryable === false && !error.message.includes('synthetic credential'));
});

test('an incomplete legacy login acknowledgement retains its local capture evidence', async t => {
  const options = await directories(t);
  const loginTask = { ...task, task_type: 'login' };
  await assert.rejects(executeTask(device, loginTask, {
    ...options,
    capture: async (_task, outputPath) => {
      await writeFile(outputPath, JSON.stringify({ session_probe: { collectable: true } }));
      return { exitCode: 0 };
    },
    request: async (_server, path) => path.endsWith('/progress') ? { status: 'waiting_user_login' } : {},
  }), { code: 'result_ack_invalid' });
  assert.equal((await readdir(options.resultsDirectory)).length, 1);
});

test('2000 unique rows are allowed but the 2001st is an explicit integrity failure', () => {
  assert.equal(extractSanitizedRows(payloadWithRows(2_000), task).length, 2_000);
  const duplicatePayload = payloadWithRows(2_000);
  duplicatePayload.traffic.push(duplicatePayload.traffic[0]);
  assert.equal(extractSanitizedRows(duplicatePayload, task).length, 2_000);
  assert.throws(() => extractSanitizedRows(payloadWithRows(2_001), task), error => (
    error.code === 'row_limit_exceeded' && error.limit === 2_000 && error.observed_count_at_least === 2_001
  ));
});

test('row overflow preserves all local sanitized evidence and does not upload a truncated success', async t => {
  const options = await directories(t);
  const calls = [];
  await assert.rejects(executeTask(device, task, {
    ...options,
    capture: async (_task, outputPath) => {
      await writeFile(outputPath, JSON.stringify(payloadWithRows(2_001)));
      return { exitCode: 0 };
    },
    request: async (_server, path) => { calls.push(path); return { status: 'running' }; },
  }), { code: 'row_limit_exceeded', retryable: false });
  const pending = await onlyEntry(options.directory);
  assert.equal(pending.blocked.code, 'row_limit_exceeded');
  const evidence = JSON.parse(pending.result_json);
  assert.equal(evidence.success, false);
  assert.equal(evidence.collection_evidence.traffic.length, 2_001);
  assert.deepEqual(calls, ['/api/ota-local-collector/tasks/42/progress']);
});

test('the 3 MB limit measures the complete UTF-8 envelope and keeps oversized evidence intact', async t => {
  const options = await directories(t);
  // Escaping the result_json string makes the envelope larger than the result itself.
  const longBusinessText = '"'.repeat(Math.floor(MAX_RESULT_ENVELOPE_BYTES / 3));
  const pending = await persistPendingResult(device, task, {
    ...result, rows: [{ ...result.rows[0], note: longBusinessText }],
  }, options);
  assert.ok(Buffer.byteLength(pending.result_json, 'utf8') < MAX_RESULT_ENVELOPE_BYTES);
  let uploadCalls = 0;
  const request = async (_server, path) => {
    if (path.endsWith('/resume-upload')) return uploadReady(pending);
    uploadCalls += 1;
    return accepted(pending);
  };
  await assert.rejects(deliverPendingResult(device, pending, { ...options, request }), { code: 'result_envelope_too_large', retryable: false });
  const recovered = await onlyEntry(options.directory);
  assert.equal(recovered.blocked.code, 'result_envelope_too_large');
  assert.equal(JSON.parse(recovered.result_json).rows[0].note, longBusinessText);
  assert.equal(uploadCalls, 0);
  await assert.rejects(deliverPendingResult(device, recovered, { ...options, request }), { code: 'result_envelope_too_large', retryable: false });
});

test('a synced temporary outbox is recovered and corrupt pending evidence blocks a new capture', async t => {
  const options = await directories(t);
  const pending = await persistPendingResult(device, task, result, options);
  const path = join(options.directory, `task_${task.id}_${pending.result_id}.json`);
  await rename(path, `${path}.tmp`);
  assert.equal((await onlyEntry(options.directory)).result_hash, pending.result_hash);
  assert.equal(await readFile(path, 'utf8').then(Boolean), true);
  await writeFile(path, '{unfinished');
  let requests = 0;
  await assert.rejects(runOneCycle(device, {
    ...options, request: async () => { requests += 1; },
  }), { code: 'outbox_invalid', retryable: false });
  assert.equal(requests, 0);
});
