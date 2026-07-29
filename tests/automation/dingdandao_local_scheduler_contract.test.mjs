import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const scheduledRunner = readFileSync(
  new URL('../../scripts/run_dingdandao_local_scheduled.ps1', import.meta.url),
  'utf8',
);
const registration = readFileSync(
  new URL('../../scripts/register_dingdandao_local_task.ps1', import.meta.url),
  'utf8',
);

test('scheduled runner requires explicit hotel and sandbox scope and stores a safe receipt', () => {
  assert.match(scheduledRunner, /\[int\]\$HotelId/);
  assert.match(scheduledRunner, /\[int\]\$OwnerUserId/);
  assert.match(scheduledRunner, /\^sbx_/);
  assert.match(scheduledRunner, /--require-sandbox/);
  assert.match(scheduledRunner, /--sandbox-id=\$SandboxId/);
  assert.match(scheduledRunner, /\$CollectionMode\s*=\s*'operating_indicators'/);
  assert.match(scheduledRunner, /--collection-mode=\$CollectionMode/);
  assert.match(scheduledRunner, /\$payloadCollectionMode\s+-eq\s+\$CollectionMode/);
  assert.match(scheduledRunner, /collection_mode\s*=\s*\$CollectionMode/);
  assert.match(scheduledRunner, /runtime\\dingdandao_local_scheduler/);
  assert.match(scheduledRunner, /latest\.json/);
  assert.match(scheduledRunner, /sandbox_selection\s*=\s*'explicit_marker'/);
  assert.match(scheduledRunner, /raw_response_exposed\s*=\s*\$false/);
  assert.match(scheduledRunner, /session_material_exposed\s*=\s*\$false/);
  assert.doesNotMatch(scheduledRunner, /cookie|authorization\s*:/i);
});

test('task registration is plan-only by default and never starts the task', () => {
  assert.match(registration, /\[switch\]\$Enable/);
  assert.match(registration, /if\s*\(\s*-not\s+\$Enable\s*\)/);
  assert.match(registration, /starts_task_immediately\s*=\s*\$false/);
  assert.match(registration, /registered_not_started/);
  assert.match(registration, /MultipleInstances IgnoreNew/);
  assert.match(registration, /--mode=inspect/);
  assert.match(registration, /explicit isolated BrowserContext marker found/);
  assert.match(registration, /trigger_count/);
  assert.match(registration, /\[switch\]\$Push/);
  assert.match(registration, /\$CollectionMode\s*=\s*'operating_indicators'/);
  assert.match(registration, /-CollectionMode "\{7\}"/);
  assert.match(registration, /collection_mode\s*=\s*\$CollectionMode/);
  assert.match(registration, /credential_free_arguments/);
  assert.match(registration, /\$actionArguments\s+-notmatch\s+\$credentialPattern/);
  assert.doesNotMatch(registration, /Start-ScheduledTask/);
});
