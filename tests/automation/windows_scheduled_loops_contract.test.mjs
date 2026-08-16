import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const inspector = read('scripts/inspect_suxios_scheduled_tasks.ps1');
const controller = read('app/controller/ManualNotification.php');
const service = read('app/service/WindowsScheduledLoopCatalogService.php');

test('scheduled-loop inspector is strictly read-only and emits one sanitized receipt', () => {
  assert.equal(inspector.match(/SUXIOS_SCHEDULED_LOOPS=/g)?.length, 1);
  assert.match(inspector, /Get-ScheduledTask\b/);
  assert.match(inspector, /Get-ScheduledTaskInfo\b/);
  assert.doesNotMatch(
    inspector,
    /\b(?:Register|Unregister|Start|Stop|Enable|Disable|Set)-ScheduledTask\b/,
  );
  assert.doesNotMatch(inspector, /\b(?:Start-Process|Invoke-Expression)\b/);
  assert.match(inspector, /sensitive_values_exposed\s*=\s*\$false/);
  assert.match(inspector, /actions\s*=\s*\$actionFiles/);
  assert.match(service, /ArrayNotHasKey|arguments|publicItem/);
});

test('existing authenticated monitor response owns the periodic-task catalog', () => {
  assert.match(controller, /WindowsScheduledLoopCatalogService/);
  assert.match(controller, /\$overview\['scheduled_loops'\]/);
  assert.match(controller, /->overview\(\$hotels, \$this->currentUser->isSuperAdmin\(\)\)/);
  assert.match(service, /windows_task_scheduler_unsupported_platform/);
  assert.match(service, /windows_task_scheduler_read_failed/);
  assert.match(service, /create_new_console'\s*=>\s*false/);
  assert.match(service, /CACHE_SECONDS\s*=\s*600/);
  assert.doesNotMatch(service, /Enable-ScheduledTask|Disable-ScheduledTask|Start-ScheduledTask/);
});
