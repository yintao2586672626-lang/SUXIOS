import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, setTimeout };
vm.runInNewContext(readStaticContractSource('public/data-health-static.js'), context, {
  filename: 'public/data-health-static.js',
});
const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

test('manual fetch task ids are extracted from Ctrip and Meituan results without duplicates', () => {
  const ids = helpers.manualFetchTaskIdsFromResult({
    response: { data: { task_id: 'manual_ctrip_fetch_7_20260715120000_aaaaaaaa' } },
    results: [
      { taskId: 'manual_meituan_fetch_7_20260715120000_bbbbbbbb' },
      { task_id: 'manual_meituan_fetch_7_20260715120000_bbbbbbbb' },
      { response: { data: { task_id: 'manual_meituan_fetch_7_20260715120000_cccccccc' } } },
      { taskId: '' },
    ],
  });

  assert.deepEqual(Array.from(ids), [
    'manual_ctrip_fetch_7_20260715120000_aaaaaaaa',
    'manual_meituan_fetch_7_20260715120000_bbbbbbbb',
    'manual_meituan_fetch_7_20260715120000_cccccccc',
  ]);
});

test('manual fetch polling follows queued to running to verified success without real timers', async () => {
  const taskId = 'manual_ctrip_fetch_7_20260715120000_aaaaaaaa';
  const responses = [
    { code: 200, data: { task_id: taskId, status: 'queued', progress_percent: 5, done: false } },
    { code: 200, data: { task_id: taskId, status: 'running', progress_percent: 30, done: false } },
    { code: 200, data: { task_id: taskId, status: 'success', progress_percent: 100, saved_count: 3, readback_count: 3, readback_verified: true, done: true } },
  ];
  const progress = [];
  const waits = [];
  const status = await helpers.pollManualFetchTaskStatus({
    taskId,
    requestStatus: async () => responses.shift(),
    wait: async delay => { waits.push(delay); },
    intervalMs: 25,
    maxAttempts: 5,
    onProgress: row => progress.push(row.status),
  });

  assert.deepEqual(progress, ['queued', 'running', 'success']);
  assert.deepEqual(waits, [25, 25]);
  assert.equal(status.status, 'success');
  assert.equal(status.savedCount, 3);
  assert.equal(status.readbackCount, 3);
  assert.equal(status.readbackVerified, true);
});

test('manual fetch polling retries transient HTTP status errors until the same task reaches its terminal state', async () => {
  const taskId = 'manual_meituan_fetch_9_20260715120000_cccccccc';
  const responses = [
    { code: 503, message: '状态服务暂不可用' },
    { code: 200, data: { task_id: taskId, status: 'queued', progress_percent: 5, done: false } },
    { code: 200, data: { task_id: taskId, status: 'success', progress_percent: 100, saved_count: 2, readback_count: 2, readback_verified: true, done: true } },
  ];
  const waits = [];
  const observed = [];
  let requestCount = 0;

  const status = await helpers.pollManualFetchTaskStatus({
    taskId,
    requestStatus: async requestedTaskId => {
      assert.equal(requestedTaskId, taskId);
      requestCount += 1;
      return responses.shift();
    },
    wait: async delay => { waits.push(delay); },
    intervalMs: 25,
    maxAttempts: 5,
    onProgress: row => observed.push([row.taskId, row.status]),
  });

  assert.equal(status.taskId, taskId);
  assert.equal(status.status, 'success');
  assert.equal(status.readbackVerified, true);
  assert.equal(requestCount, 3);
  assert.deepEqual(waits, [25, 25]);
  assert.deepEqual(observed, [[taskId, 'queued'], [taskId, 'success']]);
});

test('manual fetch polling treats a transient status transport exception as a missed poll', async () => {
  const taskId = 'manual_ctrip_fetch_10_20260715120000_dddddddd';
  const responses = [
    new TypeError('临时连接中断'),
    { code: 200, data: { task_id: taskId, status: 'running', progress_percent: 40, done: false } },
    { code: 200, data: { task_id: taskId, status: 'partial_success', progress_percent: 100, saved_count: 1, readback_count: 1, readback_verified: false, done: true } },
  ];
  const waits = [];
  let requestCount = 0;
  const status = await helpers.pollManualFetchTaskStatus({
    taskId,
    requestStatus: async () => {
      const response = responses.shift();
      requestCount += 1;
      if (response instanceof Error) throw response;
      return response;
    },
    wait: async delay => { waits.push(delay); },
    intervalMs: 40,
    maxAttempts: 5,
  });

  assert.equal(status.taskId, taskId);
  assert.equal(status.status, 'partial_success');
  assert.equal(status.readbackVerified, false);
  assert.equal(requestCount, 3);
  assert.deepEqual(waits, [40, 40]);
});

test('manual fetch polling retries a retryable HTTP exception and returns the same task result', async () => {
  const taskId = 'manual_meituan_fetch_13_20260715120000_11111111';
  const serverError = new Error('HTTP错误: 503');
  serverError.status = 503;
  const responses = [
    serverError,
    { code: 200, data: { task_id: taskId, status: 'success', progress_percent: 100, saved_count: 1, readback_count: 1, readback_verified: true, done: true } },
  ];
  const waits = [];
  let requestCount = 0;

  const status = await helpers.pollManualFetchTaskStatus({
    taskId,
    requestStatus: async requestedTaskId => {
      assert.equal(requestedTaskId, taskId);
      requestCount += 1;
      const response = responses.shift();
      if (response instanceof Error) throw response;
      return response;
    },
    wait: async delay => { waits.push(delay); },
    intervalMs: 35,
    maxAttempts: 3,
  });

  assert.equal(status.status, 'success');
  assert.equal(status.taskId, taskId);
  assert.equal(requestCount, 2);
  assert.deepEqual(waits, [35]);
});

test('manual fetch polling bounds repeated transient failures by maxAttempts', async () => {
  const networkError = new TypeError('网络暂不可用');
  const waits = [];
  let requestCount = 0;

  await assert.rejects(
    helpers.pollManualFetchTaskStatus({
      taskId: 'manual_ctrip_fetch_14_20260715120000_22222222',
      requestStatus: async () => {
        requestCount += 1;
        throw networkError;
      },
      wait: async delay => { waits.push(delay); },
      intervalMs: 45,
      maxAttempts: 2,
    }),
    error => error === networkError,
  );

  assert.equal(requestCount, 2);
  assert.deepEqual(waits, [45]);
});

test('manual fetch polling stops immediately when its status request is cancelled', async () => {
  const abortError = new Error('页面请求已取消');
  abortError.name = 'AbortError';
  let requestCount = 0;
  const waits = [];

  await assert.rejects(
    helpers.pollManualFetchTaskStatus({
      taskId: 'manual_ctrip_fetch_11_20260715120000_eeeeeeee',
      requestStatus: async () => {
        requestCount += 1;
        throw abortError;
      },
      wait: async delay => { waits.push(delay); },
      intervalMs: 30,
      maxAttempts: 5,
    }),
    error => error === abortError,
  );

  assert.equal(requestCount, 1);
  assert.deepEqual(waits, []);
});

test('manual fetch polling does not retry a non-transient status request error', async () => {
  const permissionError = new Error('当前账号无权读取任务状态');
  permissionError.status = 403;
  let requestCount = 0;
  const waits = [];

  await assert.rejects(
    helpers.pollManualFetchTaskStatus({
      taskId: 'manual_meituan_fetch_12_20260715120000_ffffffff',
      requestStatus: async () => {
        requestCount += 1;
        throw permissionError;
      },
      wait: async delay => { waits.push(delay); },
      intervalMs: 30,
      maxAttempts: 5,
    }),
    error => error === permissionError,
  );

  assert.equal(requestCount, 1);
  assert.deepEqual(waits, []);
});

test('manual fetch polling stops immediately on a terminal failure', async () => {
  let requestCount = 0;
  let waitCount = 0;
  const status = await helpers.pollManualFetchTaskStatus({
    taskId: 'manual_meituan_fetch_8_20260715120000_aaaaaaaa',
    requestStatus: async taskId => {
      requestCount += 1;
      return { code: 200, data: { task_id: taskId, status: 'failed', message: '登录态失效', done: true } };
    },
    wait: async () => { waitCount += 1; },
  });

  assert.equal(status.status, 'failed');
  assert.equal(requestCount, 1);
  assert.equal(waitCount, 0);
});

test('manual fetch polling rejects missing or mismatched task identities', async () => {
  const taskId = 'manual_ctrip_fetch_7_20260715120000_aaaaaaaa';
  await assert.rejects(
    helpers.pollManualFetchTaskStatus({
      taskId,
      requestStatus: async () => ({ code: 200, data: { status: 'running', done: false } }),
      wait: async () => {},
      maxAttempts: 1,
    }),
    /任务状态与请求 ID 不匹配/,
  );
  await assert.rejects(
    helpers.pollManualFetchTaskStatus({
      taskId,
      requestStatus: async () => ({ code: 200, data: { task_id: 'manual_ctrip_fetch_8_20260715120000_bbbbbbbb', status: 'running' } }),
      wait: async () => {},
      maxAttempts: 1,
    }),
    /任务状态与请求 ID 不匹配/,
  );
});

test('mixed accepted and immediate Meituan failures remain partial after polling', () => {
  const immediate = helpers.manualFetchImmediateStatusesFromResult({
    results: [
      { status: 'running', taskId: 'manual_meituan_fetch_7_20260715120000_aaaaaaaa' },
      { status: 'login_required', error: '登录态失效' },
      { status: 'processed', savedCount: 2, readbackVerified: true, message: '已完成' },
      { status: 'response_received', savedCount: 1, readbackVerified: false, message: '待回读' },
    ],
  });

  assert.deepEqual(immediate.map(row => row.status), ['failed', 'success', 'partial_success']);
  const summary = helpers.summarizeManualFetchTaskStatuses([
    ...immediate,
    { status: 'success', saved_count: 3, readback_count: 3, readback_verified: true, done: true },
  ]);
  assert.equal(summary.status, 'partial');
  assert.equal(summary.failedCount, 1);
  assert.equal(summary.savedCount, 6);
  assert.equal(summary.readbackVerified, false);
});

test('manual fetch task summaries preserve partial and failed truth states', () => {
  const allSuccess = helpers.summarizeManualFetchTaskStatuses([
    { status: 'success', saved_count: 2, readback_count: 2, readback_verified: true, done: true },
    { status: 'success', saved_count: 3, readback_count: 3, readback_verified: true, done: true },
  ]);
  assert.equal(allSuccess.status, 'success');
  assert.equal(allSuccess.savedCount, 5);
  assert.equal(allSuccess.readbackCount, 5);
  assert.equal(allSuccess.readbackVerified, true);

  const partial = helpers.summarizeManualFetchTaskStatuses([
    { status: 'success', saved_count: 2, done: true },
    { status: 'partial_success', saved_count: 1, done: true },
    { status: 'failed', message: '接口失败', done: true },
  ]);
  assert.equal(partial.status, 'partial');
  assert.equal(partial.savedCount, 3);
  assert.equal(partial.failedCount, 1);

  const failed = helpers.summarizeManualFetchTaskStatuses([
    { status: 'failed', message: '登录态失效', done: true },
    { status: 'failed', message: '请求失败', done: true },
  ]);
  assert.equal(failed.status, 'failed');
  assert.equal(failed.savedCount, null);
  assert.equal(failed.savedCountKnown, false, 'a failed read without persistence counts cannot prove zero saved rows');

  const pending = helpers.summarizeManualFetchTaskStatuses([
    { status: 'running', progress_percent: 30, done: false },
    { status: 'success', saved_count: 1, done: true },
  ]);
  assert.equal(pending.status, 'queued');
  assert.equal(pending.pendingCount, 1);
});

test('manual fetch result pagination limits mounted rows and clamps page bounds', () => {
  const rows = Array.from({ length: 53 }, (_, index) => ({ id: index + 1 }));
  const lastPage = helpers.paginateManualOneClickFetchRows(rows, 3, 20);
  assert.equal(lastPage.rows.length, 13);
  assert.equal(lastPage.page, 3);
  assert.equal(lastPage.totalPages, 3);
  assert.equal(lastPage.start, 41);
  assert.equal(lastPage.end, 53);

  const clamped = helpers.paginateManualOneClickFetchRows(rows, 99, 20);
  assert.equal(clamped.page, 3);
  assert.equal(clamped.rows.length, 13);

  const empty = helpers.paginateManualOneClickFetchRows([], 5, 20);
  assert.equal(empty.total, 0);
  assert.equal(empty.page, 1);
  assert.equal(empty.totalPages, 1);
  assert.equal(empty.start, 0);
  assert.equal(empty.end, 0);

  const invalid = helpers.paginateManualOneClickFetchRows(rows, 'not-a-page', 'not-a-size');
  assert.equal(invalid.page, 1);
  assert.equal(invalid.pageSize, 20);
  assert.equal(invalid.rows.length, 20);
});
