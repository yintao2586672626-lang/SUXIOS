import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const tempDir = mkdtempSync(path.join(tmpdir(), 'suxi-profile-source-'));
const inputPath = path.join(tempDir, 'verifier.json');

const common = {
  system_hotel_id: 80,
  profile_dir_present: true,
  platform_hotel_identifier_present: true,
  credential_metadata_status: 'not_required',
  credential_metadata_reason: 'browser_profile_vault_not_required',
  p0_verifier_command: 'verify-hotel-80',
};

writeFileSync(inputPath, JSON.stringify({
  status: 'passed',
  inspector_status: 'passed',
  scope: {
    date: '2026-07-11',
    platforms: ['ctrip'],
    system_hotel_id: 80,
  },
  summary: { platform_count: 1, platforms_ready: 1 },
  platforms: [{
    platform: 'ctrip',
    target_date_rows: 40,
    p0_traffic_gate: {
      status: 'ready',
      traffic_rows: 40,
      action_status: 'ready',
      system_hotel_ids: [80],
      system_hotel_row_counts: { 80: 40 },
      p0_next_step_count: 2,
      hotel_scoped_next_steps: [
        {
          ...common,
          data_source_id: 14,
          data_source_status: 'disabled',
          last_sync_status: 'success',
          profile_binding_status: 'blocked',
          profile_binding_reason: 'profile_binding_not_active',
          current_session_probe_performed: false,
          current_session_verified: false,
          current_session_status: 'unverified',
          profile_login_trigger: { status: 'profile_binding_blocked' },
          latest_sync_task: { target_date_rows_proved: false },
        },
        {
          ...common,
          data_source_id: 25,
          data_source_status: 'success',
          last_sync_status: 'success',
          profile_binding_status: 'ready',
          profile_binding_reason: '',
          current_session_probe_performed: true,
          current_session_verified: true,
          current_session_status: 'verified',
          profile_login_trigger: {
            status: 'ready',
            after_login_sync: { entry: '/api/online-data/data-sources/25/sync' },
          },
          latest_sync_task: { target_date_rows_proved: true },
        },
      ],
    },
  }],
}), 'utf8');

try {
  const result = spawnSync(process.execPath, [
    'scripts/report_p0_profile_next_steps.mjs',
    `--input=${inputPath}`,
    '--format=json',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  const report = JSON.parse(result.stdout);
  const summary = report.platform_summaries[0];
  const active = report.next_steps.find((step) => step.data_source_id === 25);
  const disabled = report.next_steps.find((step) => step.data_source_id === 14);

  assert.equal(active.platform_ready, true);
  assert.deepEqual(active.blocking_reason_codes, []);
  assert.equal(disabled.platform_ready, false);
  assert.equal(summary.hotel_scoped_ready, true);
  assert.equal(summary.hotel_step_incomplete_count, 0);
  assert.equal(summary.profile_flow_incomplete_count, 0);
  assert.equal(report.completion_gate.current_status, 'passed');
} finally {
  rmSync(tempDir, { recursive: true, force: true });
}

const verifierSource = readFileSync('scripts/verify_p0_ota_field_loop_closure.php', 'utf8');

assert.match(verifierSource, /profile_flow_ready'\]\) \? 64/);
assert.match(verifierSource, /current_session_verified'\]\) \? 32/);
assert.match(verifierSource, /\['ready', 'success', 'partial_success'\]/);
assert.match(verifierSource, /->limit\(30\)[\s\S]*p0_sync_task_target_date\(\$candidateStats\) === \$targetDate/);
