import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const staticSource = readFileSync('public/auto-fetch-static.js', 'utf8');
const panels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const appMain = `${readFileSync('public/components/system/app-main-components.js', 'utf8')}\n${readFileSync('public/app-main.js', 'utf8')}`;
const concern = readFileSync('app/controller/concern/AutoFetchConcern.php', 'utf8');
const controller = readFileSync('app/controller/ota/SyncController.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const script = readFileSync('scripts/manage_ota_dispatcher_task.ps1', 'utf8');

const sandbox = { console, window: {}, Intl, Date };
vm.runInNewContext(`${staticSource}\nthis.__static = window.SUXI_AUTO_FETCH_STATIC;`, sandbox);
const build = sandbox.__static.buildWindowsOtaSchedulerStatus;

const receipt = (overrides = {}) => ({
  schema_version: 'suxios_windows_ota_dispatcher.v1',
  visible: true,
  status: 'blocked',
  reason_code: 'scheduler_disabled',
  local_only: true,
  production_ready: false,
  hotel_id: 80,
  task_name: 'SUXIOS OTA Dispatcher H80',
  task_exists: true,
  task_state: 'Disabled',
  enabled: false,
  scope: { hotel_id: 80, source_ids: [25, 68], platforms: ['ctrip', 'meituan'], mode: 'Daily' },
  action_verified: true,
  trigger_verified: true,
  principal_verified: true,
  settings_verified: true,
  scope_verified: true,
  control_state_verified: true,
  catch_up_disabled: false,
  safe_enable_transition_required: true,
  task_state_active: false,
  trigger: { count: 1, retry_interval: 'PT14M', retry_duration: 'PT1H25M' },
  last_run_time: '2026-08-11T09:54:54+08:00',
  last_task_result: 78,
  next_run_time: '2026-08-12T08:30:30+08:00',
  contract_digest: 'a'.repeat(64),
  can_enable: true,
  task_started: false,
  starts_task_immediately: false,
  sensitive_values_exposed: false,
  binding_gate: { status: 'ready', ready: true, reason_codes: [] },
  ...overrides,
});

test('real Windows disabled state stays blocked even if application plan state is enabled elsewhere', () => {
  const actual = build(receipt({ application_plan_enabled: true }));
  assert.equal(actual.visible, true);
  assert.equal(actual.status, 'blocked');
  assert.equal(actual.enabled, false);
  assert.equal(actual.can_enable, true);
  assert.match(actual.status_text, /Windows 已禁用/);
  assert.match(actual.boundary_text, /不能替代自然回执/);
});

test('scope drift and binding failures fail closed', () => {
  const drift = build(receipt({
    scope: { hotel_id: 80, source_ids: [25, 101], platforms: ['ctrip', 'meituan'], mode: 'Daily' },
  }));
  assert.equal(drift.status, 'blocked');
  assert.equal(drift.reason, 'scheduler_scope_mismatch');
  assert.equal(drift.can_enable, false);

  const loginRequired = build(receipt({
    binding_gate: { status: 'blocked', ready: false, reason_codes: ['login_required'] },
  }));
  assert.equal(loginRequired.status, 'blocked');
  assert.equal(loginRequired.can_enable, false);
  assert.match(loginRequired.binding_reason_text, /重新登录/);
});

test('enabled task is only waiting for a natural run, never accepted as collection success', () => {
  const actual = build(receipt({
    enabled: true,
    task_state: 'Ready',
    catch_up_disabled: true,
    safe_enable_transition_required: false,
    status: 'ready',
    reason_code: 'scheduler_ready',
    can_enable: false,
  }));
  assert.equal(actual.status, 'enabled_waiting_natural_run');
  assert.equal(actual.can_enable, false);
  assert.match(actual.status_text, /等待自然运行/);
  assert.doesNotMatch(actual.status_text, /采集成功|验收通过/);
});

test('page exposes an independent status card and a safe enable exact readback action', () => {
  assert.match(panels, /data-testid="windows-ota-dispatcher-status"/);
  assert.match(panels, /data-testid="windows-ota-dispatcher-enable"/);
  assert.match(panels, /安全启用（关闭补跑，不立即运行）/);
  assert.match(panels, /状态未验证，无法启用/);
  assert.match(panels, /data-testid="natural-daily-acceptance-status"/);
  assert.match(appMain, /\/online-data\/enable-windows-ota-dispatcher/);
  assert.match(appMain, /platform-auto-settings-panels\.js\?v=20260811-windows-scheduler-h80-v3/);
  assert.match(appMain, /autoFetchStaticVersion = '20260811-windows-scheduler-h80-v3'/);
  assert.match(appMain, /expected_contract_digest: currentStatus\.contract_digest/);
  assert.match(appMain, /res\.data\?\.catch_up_disabled !== true/);
  assert.match(appMain, /res\.data\?\.control_state_verified !== true/);
  assert.match(appMain, /res\.data\?\.task_state_active !== false/);
  assert.match(appMain, /loadAutoFetchStatus\(\{ detail: false, force: true \}\)/);
  assert.match(appMain, /freshScheduler\.status !== 'enabled_waiting_natural_run'/);
  assert.match(concern, /'windows_scheduler_receipt' => \$this->windowsOtaDispatcherReceipt\(null\)/);
  assert.match(concern, /\$status\['windows_scheduler_receipt'\] = \$this->windowsOtaDispatcherReceipt\(/);
  assert.match(concern, /\$binding\['execution_owner_user_id'\]/);
  assert.match(concern, /ota_execution_owner_conflict/);
  assert.match(concern, /\$receipt\['catch_up_disabled'\]/);
  assert.match(concern, /\$receipt\['task_state_active'\]/);
  assert.match(concern, /\$schedulerMutationPerformed/);
  assert.match(concern, /'settings_action_performed'/);
  assert.match(concern, /request_enable_windows_ota_dispatcher/);
  assert.match(concern, /'outcome' => 'partial'/);
  assert.match(concern, /is_bool\(\$receipt\['task_started'\] \?\? null\)/);
  assert.match(concern, /is_bool\(\$receipt\['enable_action_performed'\] \?\? null\)/);
  assert.match(concern, /is_bool\(\$receipt\['settings_action_performed'\] \?\? null\)/);
  assert.ok(
    concern.indexOf('request_enable_windows_ota_dispatcher')
      < concern.indexOf('(new WindowsOtaDispatcherControlService())->enable'),
    'sanitized intent audit must be written before the external scheduler mutation attempt',
  );
  assert.match(controller, /public function enableWindowsOtaDispatcher\(\): Response/);
  assert.match(routes, /Route::post\('\/enable-windows-ota-dispatcher', 'ota\.SyncController\/enableWindowsOtaDispatcher'\)/);
});

test('PowerShell bridge can only enable the fixed task and contains no task-start command', () => {
  assert.match(script, /Enable-ScheduledTask -TaskName \$taskName -TaskPath \$taskPath/);
  assert.doesNotMatch(script, /Start-ScheduledTask/i);
  assert.match(script, /\$expectedHotelId = 80/);
  assert.match(script, /\$expectedSourceIds = @\(25, 68\)/);
  assert.match(script, /\$expectedPlatforms = @\('ctrip', 'meituan'\)/);
  assert.match(script, /\$arguments\.Equals\(\s*\$expectedArguments,/);
  assert.match(script, /\$workingDirectoryVerified/);
  assert.match(script, /\[int\]\$trigger\.DaysInterval -eq 1/);
  assert.match(script, /\[string\]\$trigger\.EndBoundary -eq ''/);
  assert.match(script, /\[string\]\$trigger\.RandomDelay -eq ''/);
  assert.match(script, /-not \[bool\]\$trigger\.Repetition\.StopAtDurationEnd/);
  assert.match(script, /\[bool\]\$task\.Settings\.Hidden/);
  assert.match(script, /-not \[bool\]\$task\.Settings\.RunOnlyIfIdle/);
  assert.match(script, /-not \[bool\]\$task\.Settings\.RunOnlyIfNetworkAvailable/);
  assert.match(script, /\[int\]\$task\.Settings\.RestartCount -eq 0/);
  assert.match(script, /\$safeSettings\.StartWhenAvailable = \$false/);
  assert.ok(
    script.indexOf('Set-ScheduledTask -TaskName $taskName')
      < script.indexOf('Enable-ScheduledTask -TaskName $taskName'),
    'catch-up must be disabled and read back before the task is enabled',
  );
  assert.match(script, /\$lastRunUnchanged = \$lastRunBefore -eq \$lastRunAfter/);
  assert.match(script, /starts_task_immediately = \$false/);
  assert.match(script, /control_state_verified = \$false/);
  assert.match(script, /task_started = \$null/);
  assert.match(script, /last_run_unchanged = \$null/);
});

test('catch-up and active task states fail closed', () => {
  const enabledCatchUp = build(receipt({ enabled: true, task_state: 'Ready' }));
  assert.equal(enabledCatchUp.status, 'blocked');
  assert.equal(enabledCatchUp.reason, 'scheduler_catch_up_enabled');

  const running = build(receipt({ task_state: 'Running', task_state_active: true }));
  assert.equal(running.status, 'blocked');
  assert.equal(running.reason, 'scheduler_task_active');
  assert.equal(running.can_enable, false);

  const unexpectedRun = build(receipt({
    enabled: true,
    task_state: 'Running',
    task_state_active: true,
    catch_up_disabled: true,
    safe_enable_transition_required: false,
    task_started: true,
    starts_task_immediately: true,
    status: 'blocked',
    reason_code: 'scheduler_enable_triggered_unexpected_run',
  }));
  assert.equal(unexpectedRun.status, 'blocked');
  assert.equal(unexpectedRun.reason, 'scheduler_enable_triggered_unexpected_run');
  assert.equal(unexpectedRun.task_started, true);
  assert.match(unexpectedRun.status_text, /意外运行/);

  const unavailable = build(receipt({
    status: 'blocked',
    reason_code: 'scheduler_receipt_unavailable',
    control_state_verified: false,
    task_exists: null,
    enabled: null,
    catch_up_disabled: null,
    safe_enable_transition_required: null,
    task_state_active: null,
    last_run_unchanged: null,
    task_started: null,
    starts_task_immediately: null,
  }));
  assert.equal(unavailable.status, 'blocked');
  assert.equal(unavailable.reason, 'scheduler_receipt_unavailable');
  assert.equal(unavailable.control_state_verified, false);
  assert.equal(unavailable.enabled, null);
  assert.equal(unavailable.catch_up_disabled, null);
  assert.equal(unavailable.safe_enable_transition_required, null);
  assert.equal(unavailable.task_state_active, null);
  assert.equal(unavailable.task_started, null);
  assert.match(unavailable.status_text, /状态未验证/);
});
