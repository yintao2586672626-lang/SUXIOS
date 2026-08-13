import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const launcher = readFileSync(
  new URL('../../scripts/open_local_browser_sandbox.ps1', import.meta.url),
  'utf8',
);
const runner = readFileSync(
  new URL('../../scripts/run_dingdandao_fast_local.ps1', import.meta.url),
  'utf8',
);

test('local browser host is loopback-only and creates an explicit isolated sandbox', () => {
  assert.match(launcher, /--remote-debugging-address=127\.0\.0\.1/);
  assert.match(launcher, /--remote-debugging-port=\$Port/);
  assert.match(launcher, /runtime\\local_browser_host/);
  assert.match(launcher, /--headless=new/);
  assert.match(launcher, /WindowStyle/);
  assert.match(launcher, /RedirectStandardOutput = \$browserStdoutPath/);
  assert.match(launcher, /RedirectStandardError = \$browserStderrPath/);
  assert.match(launcher, /browser\.stdout\.log/);
  assert.match(launcher, /browser\.stderr\.log/);
  assert.match(launcher, /bind-process-profile/);
  assert.match(launcher, /InteractiveLogin/);
  assert.match(launcher, /SwitchMode/);
  assert.match(launcher, /Get-DedicatedBrowserHost/);
  assert.match(launcher, /local_browser_sandbox_foreign_cdp_process/);
  assert.match(launcher, /local_browser_sandbox_mode_switch_required/);
  assert.match(launcher, /--mode=close-process-profile/);
  assert.match(launcher, /local_browser_sandbox_graceful_close_failed/);
  assert.match(launcher, /local_browser_sandbox_profile_process_shutdown_failed/);
  assert.match(launcher, /Get-Process[\s\S]*?\$browserHost\.ProcessId/);
  assert.match(launcher, /mode_switch_performed/);
  assert.match(launcher, /bind_local_browser_sandbox\.mjs/);
  assert.match(launcher, /--sandbox-id=\$SandboxId/);
  assert.match(launcher, /--platform=\$Platform/);
  assert.match(launcher, /--mode=bind-process-profile/);
  assert.match(launcher, /--mode=handoff/);
  assert.match(launcher, /Target|window_target_activated/);
  assert.match(launcher, /activated_target_scope/);
  assert.match(launcher, /'exact_start', 'pms_manage', 'login_entry'/);
  assert.match(launcher, /WScript\.Shell/);
  assert.match(launcher, /AppActivate\(/);
  assert.match(launcher, /window_foreground_requested/);
  assert.match(launcher, /profile_material_copied = \$false/);
  assert.match(launcher, /automatic_device_substitution = \$false/);
  assert.match(launcher, /browser_process_exposed = \$false/);
  assert.match(launcher, /ConvertTo-Json -Depth 5 -Compress/);
  assert.match(launcher, /sbx_dingdandao_h80_primary/);
  assert.match(launcher, /session_status/);
  assert.match(launcher, /run_fast_collection_to_verify_session/);
  assert.match(
    launcher,
    /if \(\$InteractiveLogin\) \{\s*\$browserArguments \+= '--new-window'\s*\} else \{\s*\$browserArguments \+= '--headless=new'/,
  );
  assert.match(
    launcher,
    /if \(-not \$SwitchMode\) \{\s*throw 'local_browser_sandbox_mode_switch_required'\s*\}[\s\S]*?close-process-profile/,
  );
  assert.doesNotMatch(launcher, /Stop-Process|taskkill|Remove-Item/);
});

test('fast Dingdandao entry captures hotel 80 through the structured CDP runner', () => {
  assert.match(runner, /\[int\]\$HotelId = 80/);
  assert.match(runner, /\[int\]\$OwnerUserId = 1/);
  assert.match(runner, /http:\/\/127\.0\.0\.1:9223/);
  assert.match(runner, /run_dingdandao_local_collection\.php/);
  assert.match(runner, /--sandbox-id=\$SandboxId/);
  assert.match(runner, /\[ValidateSet\('operating_indicators', 'full_diagnostic'\)\]/);
  assert.match(runner, /\[string\]\$CollectionMode = 'operating_indicators'/);
  assert.match(runner, /--collection-mode=\$CollectionMode/);
  assert.match(runner, /--require-sandbox/);
  assert.match(runner, /loopback_cdp_structured_api/);
  assert.match(runner, /collection_mode = \$CollectionMode/);
  assert.match(runner, /fast_path = \$CollectionMode -eq 'operating_indicators'/);
  assert.match(runner, /duration_ms/);
  assert.match(runner, /summary/);
  assert.match(runner, /detail_row_count/);
  assert.match(runner, /trend_point_counts/);
  assert.match(runner, /regional_benchmark/);
  assert.match(runner, /forward_room_status/);
  assert.match(runner, /business_data_persisted = \$success/);
  assert.match(runner, /push_requested = \$false/);
  assert.doesNotMatch(runner, /--push|dispatchVerifiedCapture/);
});
