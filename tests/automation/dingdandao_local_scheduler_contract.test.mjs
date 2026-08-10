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
  assert.match(scheduledRunner, /--target-date=\$targetDate/);
  assert.match(scheduledRunner, /\[int\]\$TargetDateOffsetDays\s*=\s*0/);
  assert.match(scheduledRunner, /AddDays\(\$TargetDateOffsetDays\)/);
  assert.match(scheduledRunner, /historical_offset_/);
  assert.match(scheduledRunner, /historical_business_date/);
  assert.match(scheduledRunner, /\$CollectionMode\s*=\s*'operating_indicators'/);
  assert.match(scheduledRunner, /--collection-mode=\$CollectionMode/);
  assert.match(scheduledRunner, /\$payloadCollectionMode\s+-ne\s+\$CollectionMode/);
  assert.match(scheduledRunner, /collection_mode\s*=\s*\$CollectionMode/);
  assert.match(scheduledRunner, /open_local_browser_sandbox\.ps1/);
  assert.match(scheduledRunner, /\$TimeoutSeconds\s*=\s*300/);
  assert.match(scheduledRunner, /WaitForExit\(\$TimeoutSeconds\s*\*\s*1000\)/);
  assert.match(scheduledRunner, /\$process\.WaitForExit\(\)/);
  assert.match(scheduledRunner, /RedirectStandardOutput\s*=\s*\$true/);
  assert.match(scheduledRunner, /ReadToEndAsync\(\)/);
  assert.match(scheduledRunner, /dingdandao_scheduled_collector_output_invalid/);
  assert.doesNotMatch(scheduledRunner, /Start-Process/);
  assert.match(scheduledRunner, /dingdandao_scheduled_collector_timeout/);
  assert.match(scheduledRunner, /-Platform 'dingdandao'/);
  assert.match(scheduledRunner, /local_browser_sandbox_mode_switch_required/);
  assert.match(scheduledRunner, /-InteractiveLogin/);
  assert.match(scheduledRunner, /browser_headless/);
  assert.match(scheduledRunner, /browser_visible_reused\s*=\s*\$browserVisibleReused/);
  assert.match(scheduledRunner, /process_profile/);
  assert.match(scheduledRunner, /runtime\\dingdandao_local_scheduler/);
  assert.match(scheduledRunner, /"hotel_\$HotelId"/);
  assert.match(scheduledRunner, /"user_\$OwnerUserId"/);
  assert.match(scheduledRunner, /latest\.json/);
  assert.match(scheduledRunner, /sandbox_selection\s*=\s*'explicit_marker'/);
  assert.match(scheduledRunner, /raw_response_exposed\s*=\s*\$false/);
  assert.match(scheduledRunner, /session_material_exposed\s*=\s*\$false/);
  assert.match(scheduledRunner, /\$collectionSuccess\s*=/);
  assert.match(scheduledRunner, /\$downstreamSatisfied\s*=/);
  assert.match(scheduledRunner, /downstream_satisfied\s*=\s*\$downstreamSatisfied/);
  assert.match(scheduledRunner, /\$payloadHotelId\s*=/);
  assert.match(scheduledRunner, /\$payloadTargetDate\s*=/);
  assert.match(scheduledRunner, /target_date\s*=\s*\$targetDate/);
  assert.match(scheduledRunner, /sandbox_id\s*=\s*\$SandboxId/);
  assert.match(scheduledRunner, /sandbox_selection\s*=\s*'explicit_marker'/);
  assert.match(scheduledRunner, /collection_mode\s*=\s*\$CollectionMode/);
  assert.match(scheduledRunner, /\$payloadTargetDate\s+-ne\s+\$targetDate/);
  assert.match(scheduledRunner, /\$payloadCaptureId\s*=/);
  assert.match(scheduledRunner, /scope_mismatch_codes\s*=\s*\$scopeMismatchCodes/);
  assert.match(scheduledRunner, /diagnostic_satisfied\s*=\s*\$diagnosticSatisfied/);
  assert.match(scheduledRunner, /component_coverage\s*=\s*\$componentCoverage/);
  assert.match(scheduledRunner, /\$deliverySatisfied\s*=/);
  assert.match(scheduledRunner, /\$partial\s*=/);
  assert.match(scheduledRunner, /status\s*=\s*if\s*\(\$success\)\s*\{\s*'success'\s*\}\s*elseif\s*\(\$partial\)\s*\{\s*'partial'/);
  assert.match(scheduledRunner, /collector_exit_code\s*=\s*\$exitCode/);
  assert.match(scheduledRunner, /exit_code\s*=\s*\$finalExitCode/);
  assert.match(scheduledRunner, /exit\s+\$finalExitCode/);
  assert.doesNotMatch(scheduledRunner, /cookie|authorization\s*:/i);
});

test('task registration is plan-only by default and never starts the task', () => {
  assert.match(registration, /\[switch\]\$Enable/);
  assert.match(registration, /if\s*\(\s*-not\s+\$Enable\s*\)/);
  assert.match(registration, /starts_task_immediately\s*=\s*\$false/);
  assert.match(registration, /registered_not_started/);
  assert.match(registration, /MultipleInstances IgnoreNew/);
  assert.match(registration, /-WindowStyle Hidden/);
  assert.match(registration, /-Hidden/);
  assert.match(registration, /--mode=inspect/);
  assert.match(registration, /dedicated process Profile marker found/);
  assert.match(registration, /visible_window_expected\s*=\s*\$false/);
  assert.match(registration, /browser_host_auto_start\s*=\s*'headless'/);
  assert.match(registration, /trigger_count/);
  assert.match(registration, /\[switch\]\$Push/);
  assert.match(registration, /\$CollectionMode\s*=\s*'operating_indicators'/);
  assert.match(registration, /\[int\]\$TargetDateOffsetDays\s*=\s*0/);
  assert.match(registration, /Historical D/);
  assert.match(registration, /-TargetDateOffsetDays \{8\}/);
  assert.match(registration, /historical_business_date/);
  assert.match(registration, /historical_offset_/);
  assert.match(registration, /\[int\[\]\]\$Hours\s*=\s*@\(\)/);
  assert.match(registration, /Dingdandao H\$HotelId \$modeLabel/);
  assert.match(registration, /'Diagnostic'/);
  assert.match(registration, /'Core'/);
  assert.match(registration, /\$normalizedHours\s*=\s*@\(\$Hours\)/);
  assert.match(registration, /\$scheduleHours\s*=\s*if\s*\(\$normalizedHours\.Count\s+-gt\s+0\)/);
  assert.match(registration, /\$scheduleHours\s*=\s*@\(\$scheduleHours\)/);
  assert.match(registration, /-CollectionMode "\{7\}"/);
  assert.match(registration, /collection_mode\s*=\s*\$CollectionMode/);
  assert.match(registration, /hotel_\$HotelId\/user_\$OwnerUserId\/\$CollectionMode\/latest\.json/);
  assert.match(registration, /credential_free_arguments/);
  assert.match(registration, /\$actionArguments\s+-notmatch\s+\$credentialPattern/);
  assert.doesNotMatch(registration, /Start-ScheduledTask/);
});
