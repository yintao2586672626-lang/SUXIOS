import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = relativePath => fs.readFileSync(relativePath, 'utf8');

test('default browser capture UI submits a background task and polls to a terminal state', () => {
  const source = read('public/app-main.js');
  const helper = read('public/browser-capture-task-static.js');
  const index = read('public/index.html');
  assert.match(source, /window\.SUXI_BROWSER_CAPTURE_TASK\.request/);
  assert.match(helper, /source\.sync === true \|\| source\.async === false/);
  assert.match(helper, /async: !explicitSync,[\s\S]*background: !explicitSync/);
  assert.match(helper, /Number\(accepted\?\.code \|\| 0\) !== 202/);
  assert.match(helper, /manual-fetch-task-status\?task_id=/);
  assert.match(helper, /intervalMs: 1500,[\s\S]*maxAttempts: 800/);
  assert.match(helper, /terminal\.status === 'success'[\s\S]*readbackCount === savedCount[\s\S]*terminal\.readbackVerified === true/);
  assert.match(source, /requestBrowserCaptureTask\([^\n]*capture-ctrip-browser/);
  assert.match(source, /requestBrowserCaptureTask\([^\n]*capture-meituan-browser/);
  assert.doesNotMatch(
    source,
    /requestCapture: capturePayload => request\('\/online-data\/capture-ctrip-browser'/,
  );
  assert.doesNotMatch(
    source,
    /requestCapture: body => request\('\/online-data\/capture-meituan-browser'/,
  );
  assert.ok(index.indexOf('browser-capture-task-static.js') > 0);
  assert.ok(index.indexOf('browser-capture-task-static.js') < index.indexOf('app-main.min.js'));
  const helperHash = crypto.createHash('sha256').update(helper).digest('hex').slice(0, 10);
  assert.match(index, new RegExp(`browser-capture-task-static\\.js\\?v=[^"']*-h${helperHash}`));
});

test('browser capture helper converts HTTP 202 plus bounded polling into terminal readback state', async () => {
  const sandbox = { window: {}, console, URL, setTimeout, clearTimeout };
  vm.runInNewContext(read('public/browser-capture-task-static.js'), sandbox);
  const calls = [];
  const notices = [];
  const result = await sandbox.window.SUXI_BROWSER_CAPTURE_TASK.request({
    endpoint: '/online-data/capture-ctrip-browser',
    payload: { system_hotel_id: 7, profile_id: 'client-spoofed-profile' },
    request: async (url, options = {}) => {
      calls.push({ url, options });
      if (url.includes('manual-fetch-task-status')) {
        return { code: 200, data: { task_id: 'manual_ctrip_fetch_7_20260827120000_aaaaaaaa' } };
      }
      return { code: 202, data: { task_id: 'manual_ctrip_fetch_7_20260827120000_aaaaaaaa' } };
    },
    poll: async options => {
      assert.equal(options.intervalMs, 1500);
      assert.equal(options.maxAttempts, 800);
      await options.requestStatus(options.taskId);
      return {
        taskId: options.taskId,
        taskKind: 'ctrip_browser_profile',
        status: 'success',
        stage: 'completed',
        statusText: '已入库',
        message: '后台任务已完成并通过数据库回读',
        progressPercent: 100,
        savedCount: 2,
        readbackCount: 2,
        readbackVerified: true,
        qualityStatus: 'available',
        qualitySummary: {
          server_identity: {
            verified: true,
            platform: 'ctrip',
            profile_id: 'server-bound-profile',
            store_id: null,
          },
        },
        done: true,
      };
    },
    notify: (...args) => notices.push(args),
    wait: async () => {},
  });

  const submitted = JSON.parse(calls[0].options.body);
  assert.equal(submitted.async, true);
  assert.equal(submitted.background, true);
  assert.equal(result.code, 200);
  assert.equal(result.data.saved_count, 2);
  assert.equal(result.data.readback_verified, true);
  assert.equal(result.data.task_kind, 'ctrip_browser_profile');
  assert.equal(result.data.identity_verified, true);
  assert.equal(result.data.profile_id, 'server-bound-profile');
  assert.equal(result.data.store_id, null);
  assert.notEqual(result.data.profile_id, 'client-spoofed-profile');
  assert.equal(notices.length, 1);
  assert.match(calls[1].url, /manual-fetch-task-status\?task_id=/);
});

test('browser capture helper keeps explicit synchronous compatibility without polling', async () => {
  const sandbox = { window: {}, console, URL, setTimeout, clearTimeout };
  vm.runInNewContext(read('public/browser-capture-task-static.js'), sandbox);
  let pollCalls = 0;
  let requestBody = null;
  const result = await sandbox.window.SUXI_BROWSER_CAPTURE_TASK.request({
    endpoint: '/online-data/capture-meituan-browser',
    payload: { system_hotel_id: 8, store_id: 'store-fixture', sync: true },
    request: async (url, options) => {
      requestBody = JSON.parse(options.body);
      return { code: 200, data: { saved_count: 1 } };
    },
    poll: async () => { pollCalls += 1; },
  });

  assert.equal(result.code, 200);
  assert.equal(requestBody.async, false);
  assert.equal(requestBody.background, false);
  assert.equal(Object.hasOwn(requestBody, 'sync'), false);
  assert.equal(pollCalls, 0);
});

test('browser capture helper never wraps partial no-data or unverified terminals as code 200', async () => {
  for (const [status, savedCount, readbackCount, readbackVerified] of [
    ['partial_success', 2, 1, true],
    ['no_data', 0, 0, false],
    ['unverified', 2, 2, false],
    ['success', 2, 1, true],
  ]) {
    const sandbox = { window: {}, console, URL, setTimeout, clearTimeout };
    vm.runInNewContext(read('public/browser-capture-task-static.js'), sandbox);
    const result = await sandbox.window.SUXI_BROWSER_CAPTURE_TASK.request({
      endpoint: '/online-data/capture-ctrip-browser',
      payload: { system_hotel_id: 7, profile_id: 'profile-fixture' },
      request: async () => ({
        code: 202,
        data: { task_id: 'manual_ctrip_fetch_7_20260827120000_aaaaaaaa' },
      }),
      poll: async options => ({
        taskId: options.taskId,
        taskKind: 'ctrip_browser_profile',
        status,
        savedCount,
        readbackCount,
        readbackVerified,
        done: true,
      }),
      wait: async () => {},
    });
    assert.notEqual(result.code, 200, status);
    assert.equal(result.data.readback_verified, false, status);
    assert.equal(result.data.identity_verified, false, status);
    assert.equal(result.data.profile_id, null, status);
    assert.equal(result.data.store_id, null, status);
  }
});

test('queued browser capture reuses the existing terminal save-readback task contract', () => {
  const controller = read('app/controller/concern/OnlineDataRequestConcern.php');
  const taskService = read('app/service/ManualOnlineFetchTaskService.php');
  const command = read('app/command/ManualFetchOnlineDataOnce.php');

  assert.match(controller, /浏览器 Profile 采集已提交后台执行', 202\)->code\(202\)/u);
  assert.match(controller, /'task_id' => \$taskId/);
  assert.match(controller, /'url' => '\/api\/online-data\/manual-fetch-task-status\?task_id='/);
  assert.match(controller, /backgroundRequested[\s\S]*background_task/);
  assert.match(taskService, /'status' => 'queued'[\s\S]*'done' => false/);
  assert.match(taskService, /'saved_count' => \$savedCount/);
  assert.match(taskService, /'readback_count' => \$readbackCount/);
  assert.match(taskService, /'readback_verified' => \$exactReadbackVerified/);
  assert.match(command, /new BrowserCaptureTaskExecutionService\(\)\)->execute/);
  assert.ok(command.indexOf('claimTaskForExecution(') < command.indexOf('new BrowserCaptureTaskExecutionService()'));
  const meituanMethod = controller.slice(
    controller.indexOf('public function captureMeituanBrowserData'),
    controller.indexOf('public function captureCtripBrowserData'),
  );
  const ctripMethod = controller.slice(controller.indexOf('public function captureCtripBrowserData'));
  assert.ok(meituanMethod.indexOf('queueBrowserProfileCaptureIfRequested') < meituanMethod.indexOf('runMeituanCaptureProcess'));
  assert.ok(ctripMethod.indexOf('queueBrowserProfileCaptureIfRequested') < ctripMethod.indexOf('runMeituanCaptureProcess'));
});
