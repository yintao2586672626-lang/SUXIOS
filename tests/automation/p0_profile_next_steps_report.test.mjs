import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const currentSessionProfileState = {
  profile_dir_present: true,
  platform_hotel_identifier_present: true,
  current_session_probe_performed: true,
  current_session_verified: true,
  current_session_status: 'verified',
  profile_binding_status: 'ready',
  profile_binding_reason: '',
  credential_metadata_status: 'not_required',
  credential_metadata_reason: 'browser_profile_vault_not_required',
};

const sessionProbeProfileState = {
  ...currentSessionProfileState,
  current_session_probe_performed: false,
  current_session_verified: false,
  current_session_status: 'unverified',
};

test('P0 Profile next-step report exposes only sanitized login and verifier actions', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      inspector_status: 'incomplete',
      scope: { date: '2026-06-27' },
      summary: { p0_platforms_ready: 0, p0_platforms_incomplete: 2 },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 0,
          p0_traffic_gate: {
            traffic_rows: 0,
            action_entry: '/api/online-data/capture-ctrip-browser',
            action_status: 'missing_inputs',
            action_missing_inputs: ['current_session_probe_verified'],
            p0_next_step_count: 1,
            p0_profile_login_trigger_available_count: 1,
            p0_after_login_sync_available_count: 1,
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'waiting_config',
                last_sync_status: 'waiting_config',
                manual_login_state_verified: false,
                ...sessionProbeProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  request_body: {
                    raw_cookie: 'SECRET_COOKIE_VALUE',
                    token: 'SECRET_TOKEN_VALUE',
                  },
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/14/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27 --platform=ctrip --system-hotel-id=60',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /P0 OTA Profile 下一步清单/);
    assert.match(result.stdout, /https:\/\/ebooking\.ctrip\.com\/home\/mainland/);
    assert.doesNotMatch(result.stdout, /\/api\/online-data\/profile-login-trigger\/ctrip/);
    assert.doesNotMatch(result.stdout, /\/api\/online-data\/data-sources\/14\/sync/);
    assert.match(result.stdout, /manual_login_state_verified=false/);
    assert.match(result.stdout, /verify:p0-ota-field-loop/);
    assert.match(result.stdout, /执行门禁/);
    assert.match(result.stdout, /全局完成门禁: npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27/);
    assert.match(result.stdout, /默认采集主线: 浏览器 Profile 登录态采集 \(browser_profile\)/);
    assert.match(result.stdout, /手动 Cookie\/API: temporary_only/);
    assert.match(result.stdout, /下游推进门禁/);
    assert.match(result.stdout, /blocked_by_p0_ota_gate/);
    assert.doesNotMatch(result.stdout, /SECRET_COOKIE_VALUE/);
    assert.doesNotMatch(result.stdout, /SECRET_TOKEN_VALUE/);
    assert.doesNotMatch(result.stdout, /raw_cookie/);

    const jsonResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });
    assert.equal(jsonResult.status, 0, jsonResult.stderr);
    const payload = JSON.parse(jsonResult.stdout);
    assert.equal(payload.completion_gate.command, 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27');
    assert.equal(payload.completion_gate.required_status, 'ready');
    assert.equal(payload.collection_policy.mainline_mode, 'browser_profile');
    assert.equal(payload.collection_policy.temporary_mode, 'manual_cookie_api');
    assert.equal(payload.collection_policy.temporary_mode_policy, 'temporary_only');
    assert(payload.collection_policy.mainline_required_gates.includes('current_session_probe_verified'));
    assert(payload.collection_policy.mainline_required_gates.includes('p0_field_loop_verifier_ready'));
    assert(payload.collection_policy.forbidden_claims_before_ready.includes('manual_cookie_api_as_default_mainline'));
    assert(payload.collection_policy.forbidden_claims_before_ready.includes('sync_task_success_as_p0_closure'));
    assert.equal(payload.downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert.equal(payload.downstream_gate.required_gate_command, payload.completion_gate.command);
    assert.deepEqual(payload.downstream_gate.blocked_stage_keys, [
      'revenue_analysis',
      'ai_decision_advice',
      'operation_closure',
    ]);
    assert(payload.downstream_gate.blocking_missing_inputs.includes('current_session_probe_verified'));
    assert(payload.downstream_gate.allowed_claims.includes('no_whole_hotel_or_downstream_closure_claim'));
    assert.equal(payload.operator_sequence.length, 2);
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['session_probe', 'single_scope_verifier']);
    assert.doesNotMatch(jsonResult.stdout, /SECRET_COOKIE_VALUE/);
    assert.doesNotMatch(jsonResult.stdout, /SECRET_TOKEN_VALUE/);

    const formatJsonResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--format=json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });
    assert.equal(formatJsonResult.status, 0, formatJsonResult.stderr);
    assert.equal(JSON.parse(formatJsonResult.stdout).completion_gate.command, payload.completion_gate.command);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report derives verified login count from hotel-scoped steps', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-count-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-06-28', platforms: ['ctrip'] },
      platforms: [
        {
          platform: 'meituan',
          target_date_rows: 0,
          p0_traffic_gate: {
            traffic_rows: 0,
            action_entry: '/api/online-data/capture-meituan-browser',
            action_status: 'ready_to_attempt',
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 7,
                data_source_id: 18,
                data_source_status: 'ready',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://me.meituan.com/ebooking/',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/18/sync',
                  },
                },
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].manual_login_state_verified_count, 1);
    assert.equal(payload.next_steps[0].manual_login_state_verified, true);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report suppresses sync actions for ready platforms', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-ready-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-06-28', platforms: ['ctrip'] },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 27,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 22,
            action_status: 'ready',
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/14/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-28 --platform=ctrip --system-hotel-id=60',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].platform_ready, true);
    assert.equal(payload.next_steps[0].platform_ready, true);
    assert.equal(payload.next_steps[0].login_trigger_entry, '');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '');
    assert.equal(payload.completion_gate.current_status, 'passed');
    assert.equal(payload.downstream_gate.status, 'open');
    assert.deepEqual(payload.downstream_gate.blocked_stage_keys, []);
    assert.deepEqual(payload.downstream_gate.blocking_missing_inputs, []);
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['already_ready', 'single_scope_verifier']);
    assert.doesNotMatch(result.stdout, /"type": "after_login_sync"/);

    const markdownResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /platform_ready=true/);
    assert.match(markdownResult.stdout, /already_ready_no_login/);
    assert.match(markdownResult.stdout, /already_ready_no_sync/);
    assert.match(markdownResult.stdout, /already_ready: .*system_hotel_id=60 data_source_id=14/);
    assert.doesNotMatch(markdownResult.stdout, /\/api\/online-data\/data-sources\/14\/sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report keeps hotel steps actionable when platform gate is incomplete', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-partial-platform-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-08' },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'traffic_field_fact_closure_incomplete',
            traffic_rows: 3,
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/14/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=60',
              },
              {
                system_hotel_id: 64,
                data_source_id: 15,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/15/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=64',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].platform_ready, false);
    assert.deepEqual(payload.next_steps.map(step => step.platform_ready), [false, false]);
    assert.deepEqual(payload.next_steps.map(step => step.login_trigger_status), ['current_session_verified', 'current_session_verified']);
    assert.deepEqual(payload.next_steps.map(step => step.after_login_sync_entry), [
      '/api/online-data/data-sources/14/sync',
      '/api/online-data/data-sources/15/sync',
    ]);
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'after_login_sync',
      'single_scope_verifier',
      'after_login_sync',
      'single_scope_verifier',
    ]);
    assert(payload.downstream_gate.blocking_missing_inputs.includes('traffic_field_fact_closure_incomplete'));
    assert.doesNotMatch(result.stdout, /already_ready_no_login/);

    const markdownResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /platform_ready=false/);
    assert.match(markdownResult.stdout, /\/api\/online-data\/data-sources\/14\/sync/);
    assert.match(markdownResult.stdout, /\/api\/online-data\/data-sources\/15\/sync/);
    assert.doesNotMatch(markdownResult.stdout, /already_ready_no_sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report separates P0 data readiness from missing Profile flow registration', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-flow-gap-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-07-08', platforms: ['ctrip'], system_hotel_id: 107 },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 3,
            system_hotel_ids: [107],
            system_hotel_row_counts: { 107: 3 },
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 107,
                data_source_id: null,
                data_source_status: 'not_registered',
                last_sync_status: '',
                manual_login_state_verified: false,
                profile_login_trigger: {
                  status: 'not_available',
                  reason: 'missing_platform_data_source_or_hotel_scope',
                },
                latest_sync_task: {
                  status: 'not_available',
                  reason: 'missing_data_source_id',
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=107',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.completion_gate.command, 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=107');
    assert.equal(payload.completion_gate.current_status, 'passed');
    assert.equal(payload.downstream_gate.status, 'open');
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_system_hotel_ids, [107]);
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_system_hotel_row_counts, { 107: 3 });
    assert.deepEqual(payload.platform_summaries[0].hotel_step_system_hotel_ids, [107]);
    assert.equal(payload.platform_summaries[0].target_date_traffic_step_scope_status, 'matched_or_not_provided');
    assert.equal(payload.platform_summaries[0].profile_flow_ready, false);
    assert.equal(payload.platform_summaries[0].profile_flow_incomplete_count, 1);
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('missing_data_source_id'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('data_source_not_registered'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('current_session_probe_required'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('profile_login_trigger_not_available'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('missing_platform_data_source_or_hotel_scope'));
    assert.equal(payload.next_steps[0].platform_ready, true);
    assert.equal(payload.next_steps[0].profile_flow_ready, false);
    assert.equal(payload.collection_flow_gate.status, 'blocked_by_profile_flow_gap');
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('ctrip_profile_flow_unproved'));
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('data_source_not_registered'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['already_ready', 'profile_flow_gap', 'single_scope_verifier']);
    assert.equal(payload.operator_sequence[1].system_hotel_id, 107);
    assert.equal(payload.operator_sequence[1].data_source_id, null);
    assert.equal(payload.operator_sequence[1].status, 'profile_flow_unproved');
    assert(payload.operator_sequence[1].blocking_reason_codes.includes('missing_data_source_id'));
    assert(payload.operator_sequence[1].blocking_reason_codes.includes('data_source_not_registered'));

    const markdownResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });
    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /Profile Flow Gate/);
    assert.match(markdownResult.stdout, /blocked_by_profile_flow_gap/);
    assert.match(markdownResult.stdout, /target_traffic_hotels=107/);
    assert.match(markdownResult.stdout, /profile_flow_ready=false/);
    assert.match(markdownResult.stdout, /profile_flow_gap: .*system_hotel_id=107 data_source_id=null/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report marks waiting-config data sources as Profile flow gaps', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-waiting-config-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-07-08', platforms: ['ctrip'], system_hotel_id: 107 },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 2,
            system_hotel_ids: [107],
            system_hotel_row_counts: { 107: 2 },
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 107,
                data_source_id: 24,
                data_source_status: 'waiting_config',
                last_sync_status: 'waiting_config',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: { entry: '/api/online-data/data-sources/24/sync' },
                },
                latest_sync_task: { status: 'no_sync_task' },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=107',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.completion_gate.current_status, 'passed');
    assert.equal(payload.downstream_gate.status, 'open');
    assert.equal(payload.platform_summaries[0].profile_flow_ready, false);
    assert.equal(payload.platform_summaries[0].profile_flow_incomplete_count, 1);
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('data_source_waiting_config'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('last_sync_waiting_config'));
    assert.equal(payload.collection_flow_gate.status, 'blocked_by_profile_flow_gap');
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('data_source_waiting_config'));
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('last_sync_waiting_config'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['already_ready', 'profile_flow_gap', 'single_scope_verifier']);
    assert.equal(payload.operator_sequence[1].data_source_id, 24);
    assert(payload.operator_sequence[1].blocking_reason_codes.includes('data_source_waiting_config'));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report exposes target-date traffic and Profile step hotel mismatch', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-scope-mismatch-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-07-08', platforms: ['ctrip'] },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 3,
            system_hotel_ids: [107],
            system_hotel_row_counts: { 107: 3 },
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: { entry: '/api/online-data/data-sources/14/sync' },
                },
              },
              {
                system_hotel_id: 64,
                data_source_id: 15,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: { entry: '/api/online-data/data-sources/15/sync' },
                },
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].target_date_traffic_step_scope_status, 'mismatch');
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_hotels_missing_steps, [107]);
    assert.deepEqual(payload.platform_summaries[0].step_hotels_missing_target_date_traffic, [60, 64]);
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_scope_blocking_reason_codes, ['target_date_traffic_without_profile_step']);
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_scope_reference_reason_codes, ['profile_step_without_target_date_traffic']);
    assert.equal(payload.platform_summaries[0].profile_flow_ready, false);
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('target_date_traffic_without_profile_step'));
    assert.equal(payload.collection_flow_gate.status, 'blocked_by_profile_flow_gap');
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('ctrip_target_date_traffic_step_scope_mismatch'));
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('ctrip_target_date_traffic_without_profile_step'));
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report allows extra reference steps when target traffic hotel is covered', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-extra-reference-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-07-08', platforms: ['ctrip'] },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 3,
            system_hotel_ids: [107],
            system_hotel_row_counts: { 107: 3 },
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 107,
                data_source_id: 14,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: { entry: '/api/online-data/data-sources/14/sync' },
                },
              },
              {
                system_hotel_id: 60,
                data_source_id: 15,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: { entry: '/api/online-data/data-sources/15/sync' },
                },
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].target_date_traffic_step_scope_status, 'target_covered_with_extra_reference_steps');
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_hotels_missing_steps, []);
    assert.deepEqual(payload.platform_summaries[0].step_hotels_missing_target_date_traffic, [60]);
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_scope_blocking_reason_codes, []);
    assert.deepEqual(payload.platform_summaries[0].target_date_traffic_scope_reference_reason_codes, ['profile_step_without_target_date_traffic']);
    assert.equal(payload.platform_summaries[0].profile_flow_ready, true);
    assert.equal(payload.collection_flow_gate.status, 'open');
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report passes system_hotel_id through to the verifier command', () => {
  const source = readFileSync('scripts/report_p0_profile_next_steps.mjs', 'utf8');
  assert.match(source, /argValue\('system-hotel-id', argValue\('system_hotel_id'\)\)/);
  assert.match(source, /verifierArgs\.push\(`--system-hotel-id=\$\{systemHotelId\}`\)/);
});

test('P0 Profile next-step report does not inherit platform ready for unproved hotel steps', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-unproved-step-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      scope: { date: '2026-07-08', platforms: ['ctrip'] },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 4,
          p0_traffic_gate: {
            status: 'ready',
            traffic_rows: 3,
            action_status: 'ready',
            action_missing_inputs: [],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'success',
                last_sync_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                latest_sync_task: {
                  status: 'success',
                  diagnosis: 'sync_task_saved_rows_but_requires_p0_target_date_verifier',
                  target_date_rows_proved: false,
                },
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/14/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-08 --platform=ctrip --system-hotel-id=60',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].platform_ready, true);
    assert.equal(payload.platform_summaries[0].hotel_scoped_ready, false);
    assert.equal(payload.platform_summaries[0].hotel_step_count, 1);
    assert.equal(payload.platform_summaries[0].hotel_step_ready_count, 0);
    assert.equal(payload.platform_summaries[0].hotel_step_incomplete_count, 1);
    assert(payload.platform_summaries[0].hotel_step_blocking_reason_codes.includes('target_date_rows_unproved'));
    assert(payload.platform_summaries[0].hotel_step_blocking_reason_codes.includes('requires_p0_target_date_verifier'));
    assert.equal(payload.next_steps[0].platform_ready, false);
    assert.equal(payload.next_steps[0].platform_gate_ready, true);
    assert(payload.next_steps[0].blocking_reason_codes.includes('target_date_rows_unproved'));
    assert.equal(payload.next_steps[0].login_trigger_status, 'current_session_verified');
    assert.equal(payload.next_steps[0].login_trigger_entry, '');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '/api/online-data/data-sources/14/sync');
    assert.equal(payload.completion_gate.current_status, 'incomplete_hotel_scoped_steps');
    assert.equal(payload.downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert(payload.downstream_gate.blocking_missing_inputs.includes('ctrip_hotel_scoped_p0_steps_unproved'));
    assert(payload.downstream_gate.blocking_missing_inputs.includes('target_date_rows_unproved'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'after_login_sync',
      'single_scope_verifier',
    ]);
    assert.doesNotMatch(result.stdout, /already_ready_no_login/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report marks operator-skipped platform without sync action', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-skip-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-06-28' },
      platforms: [
        {
          platform: 'meituan',
          target_date_rows: 0,
          p0_traffic_gate: {
            traffic_rows: 0,
            action_entry: '/api/online-data/capture-meituan-browser',
            action_status: 'ready_to_attempt',
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 7,
                data_source_id: 18,
                data_source_status: 'ready',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://me.meituan.com/ebooking/',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/18/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-28 --platform=meituan --system-hotel-id=7',
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, [
      'scripts/report_p0_profile_next_steps.mjs',
      `--input=${input}`,
      '--skip-platform=meituan',
      '--json',
    ], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].operator_skip_active, true);
    assert.equal(payload.platform_summaries[0].action_entry, '');
    assert.equal(payload.platform_summaries[0].action_status, 'skipped_by_operator_no_capture');
    assert.equal(payload.next_steps[0].operator_skip_active, true);
    assert.equal(payload.next_steps[0].manual_login_state_verified, true);
    assert.equal(payload.next_steps[0].login_trigger_status, 'login_verified_reference_only');
    assert.equal(payload.next_steps[0].login_trigger_entry, '');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '');
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['operator_skip', 'single_scope_verifier']);
    assert(payload.downstream_gate.blocking_missing_inputs.includes('p0_skipped_by_operator'));
    assert.deepEqual(payload.downstream_gate.operator_skip_platforms, ['meituan']);
    assert.doesNotMatch(result.stdout, /capture-meituan-browser/);
    assert.doesNotMatch(result.stdout, /profile-login-trigger\/meituan/);
    assert.doesNotMatch(result.stdout, /"type": "after_login_sync"/);

    const markdownResult = spawnSync(process.execPath, [
      'scripts/report_p0_profile_next_steps.mjs',
      `--input=${input}`,
      '--skip-platform=meituan',
    ], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /operator_skip_active/);
    assert.match(markdownResult.stdout, /p0_skipped_by_operator_reference_only_no_collection/);
    assert.match(markdownResult.stdout, /skipped_by_operator_no_capture/);
    assert.match(markdownResult.stdout, /login_verified_reference_only/);
    assert.match(markdownResult.stdout, /skipped_by_operator_no_sync/);
    assert.match(markdownResult.stdout, /operator_skip: .*system_hotel_id=7 data_source_id=18/);
    assert.match(markdownResult.stdout, /operator_skip_platforms: meituan/);
    assert.doesNotMatch(markdownResult.stdout, /capture-meituan-browser/);
    assert.doesNotMatch(markdownResult.stdout, /profile-login-trigger\/meituan/);
    assert.doesNotMatch(markdownResult.stdout, /\/api\/online-data\/data-sources\/18\/sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report stops login actions for credential metadata migration and exposes only safe reason codes', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-credential-migration-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-09' },
      platforms: [
        {
          platform: 'meituan',
          target_date_rows: 3,
          p0_traffic_gate: {
            status: 'missing_target_date_traffic_rows',
            traffic_rows: 0,
            action_status: 'missing_inputs',
            action_missing_inputs: ['ota_credential_metadata_migration_required'],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 7,
                data_source_id: 18,
                data_source_status: 'success',
                last_sync_status: 'success',
                capture_sections_has_traffic: true,
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                credential_metadata_status: 'migration_required',
                credential_metadata_reason: 'credential_reference_missing',
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://me.meituan.com/ebooking/',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/18/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-09 --platform=meituan --system-hotel-id=7',
              },
            ],
          },
        },
      ],
    }));

    const jsonResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(jsonResult.status, 0, jsonResult.stderr);
    const payload = JSON.parse(jsonResult.stdout);
    assert.equal(payload.next_steps[0].credential_metadata_status, 'migration_required');
    assert.equal(payload.next_steps[0].credential_metadata_reason, 'credential_reference_missing');
    assert.equal(payload.next_steps[0].login_trigger_entry, '');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '');
    assert(payload.next_steps[0].blocking_reason_codes.includes('ota_credential_metadata_migration_required'));
    assert(payload.next_steps[0].blocking_reason_codes.includes('credential_reference_missing'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'credential_metadata_migration',
      'single_scope_verifier',
    ]);
    assert.equal(payload.operator_sequence[0].reason_code, 'credential_reference_missing');
    assert.equal(payload.operator_sequence[0].status, 'migration_required');
    assert(payload.operator_sequence[0].blocking_reason_codes.includes('ota_credential_metadata_migration_required'));
    assert.match(payload.operator_sequence[0].required_action, /before starting a session probe or data-source sync/);
    assert.equal(Object.hasOwn(payload.operator_sequence[0], 'entry'), false);
    assert.doesNotMatch(jsonResult.stdout, /"type": "manual_login"/);
    assert.doesNotMatch(jsonResult.stdout, /"type": "after_login_sync"/);

    const markdownResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /credential_metadata=migration_required \/ credential_reference_missing/);
    assert.match(markdownResult.stdout, /credential_metadata_migration: .*credential_reference_missing/);
    assert.doesNotMatch(markdownResult.stdout, /\/api\/online-data\/data-sources\/18\/sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report preserves browser Profile credential-not-required status without blocking actions', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-profile-no-vault-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-10' },
      platforms: [{
        platform: 'meituan',
        p0_traffic_gate: {
          status: 'missing_target_date_traffic_rows',
          traffic_rows: 0,
          hotel_scoped_next_steps: [{
            system_hotel_id: 7,
            data_source_id: 18,
            data_source_status: 'success',
            last_sync_status: 'success',
            manual_login_state_verified: true,
            ...sessionProbeProfileState,
            credential_required: false,
            credential_metadata_status: 'not_required',
            credential_metadata_reason: 'browser_profile_vault_not_required',
            profile_login_trigger: {
              status: 'client_local_authorization_required',
              entry: 'https://me.meituan.com/ebooking/',
              after_login_sync: { entry: '/api/online-data/data-sources/18/sync' },
            },
            p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-10 --platform=meituan --system-hotel-id=7',
          }],
        },
      }],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    const step = payload.next_steps[0];
    assert.equal(step.credential_metadata_status, 'not_required');
    assert.equal(step.credential_metadata_reason, 'browser_profile_vault_not_required');
    assert.equal(step.login_trigger_entry, 'https://me.meituan.com/ebooking/');
    assert.equal(step.after_login_sync_entry, '');
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['session_probe', 'single_scope_verifier']);
    assert(!step.blocking_reason_codes.includes('ota_credential_metadata_migration_required'));
    assert(!step.blocking_reason_codes.includes('ota_credential_metadata_blocked'));
    assert.doesNotMatch(result.stdout, /credential_metadata_migration/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report suppresses unrecognized credential metadata reasons', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-credential-reason-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-09' },
      platforms: [
        {
          platform: 'meituan',
          target_date_rows: 0,
          p0_traffic_gate: {
            traffic_rows: 0,
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 7,
                data_source_id: 18,
                data_source_status: 'success',
                manual_login_state_verified: true,
                ...currentSessionProfileState,
                credential_metadata_status: 'migration_required',
                credential_metadata_reason: 'unrecognized_metadata_payload',
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://me.meituan.com/ebooking/',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/18/sync',
                  },
                },
              },
            ],
          },
        },
      ],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.next_steps[0].credential_metadata_status, 'migration_required');
    assert.equal(payload.next_steps[0].credential_metadata_reason, '');
    assert.doesNotMatch(result.stdout, /unrecognized_metadata_payload/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report keeps blocked credential metadata out of login and sync actions', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-credential-blocked-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-09' },
      platforms: [
        {
          platform: 'ctrip',
          target_date_rows: 0,
          p0_traffic_gate: {
            traffic_rows: 0,
            action_status: 'missing_inputs',
            action_missing_inputs: ['ota_credential_metadata_blocked'],
            hotel_scoped_next_steps: [
              {
                system_hotel_id: 60,
                data_source_id: 14,
                data_source_status: 'waiting_config',
                manual_login_state_verified: false,
                credential_metadata_status: 'blocked',
                credential_metadata_reason: 'credential_not_ready',
                profile_login_trigger: {
                  status: 'client_local_authorization_required',
                  entry: 'https://ebooking.ctrip.com/home/mainland',
                  after_login_sync: {
                    entry: '/api/online-data/data-sources/14/sync',
                  },
                },
                p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-09 --platform=ctrip --system-hotel-id=60',
              },
            ],
          },
        },
      ],
    }));

    const jsonResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(jsonResult.status, 0, jsonResult.stderr);
    const payload = JSON.parse(jsonResult.stdout);
    assert.equal(payload.next_steps[0].credential_metadata_status, 'blocked');
    assert.equal(payload.next_steps[0].credential_metadata_reason, 'credential_not_ready');
    assert.equal(payload.next_steps[0].login_trigger_entry, '');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '');
    assert(payload.next_steps[0].blocking_reason_codes.includes('ota_credential_metadata_blocked'));
    assert(payload.next_steps[0].blocking_reason_codes.includes('credential_not_ready'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'credential_metadata_blocked',
      'single_scope_verifier',
    ]);
    assert.equal(payload.operator_sequence[0].status, 'blocked');
    assert.equal(payload.operator_sequence[0].reason_code, 'credential_not_ready');
    assert.match(payload.operator_sequence[0].required_action, /before starting a session probe or data-source sync/);
    assert.equal(Object.hasOwn(payload.operator_sequence[0], 'entry'), false);
    assert.doesNotMatch(jsonResult.stdout, /"type": "manual_login"/);
    assert.doesNotMatch(jsonResult.stdout, /"type": "after_login_sync"/);

    const markdownResult = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(markdownResult.status, 0, markdownResult.stderr);
    assert.match(markdownResult.stdout, /credential_metadata=blocked \/ credential_not_ready/);
    assert.match(markdownResult.stdout, /credential_metadata_blocked: .*credential_not_ready/);
    assert.doesNotMatch(markdownResult.stdout, /https:\/\/ebooking\.ctrip\.com\/home\/mainland/);
    assert.doesNotMatch(markdownResult.stdout, /\/api\/online-data\/data-sources\/14\/sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report treats historical login metadata as session-probe preparation only', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-historical-login-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-10' },
      platforms: [{
        platform: 'ctrip',
        p0_traffic_gate: {
          status: 'missing_target_date_traffic_rows',
          traffic_rows: 0,
          action_status: 'ready_to_attempt',
          p0_manual_login_state_verified_count: 9,
          hotel_scoped_next_steps: [{
            system_hotel_id: 60,
            data_source_id: 14,
            data_source_status: 'success',
            last_sync_status: 'success',
            profile_dir_present: true,
            platform_hotel_identifier_present: true,
            manual_login_state_verified: true,
            historical_login_metadata_present: true,
            current_session_probe_performed: false,
            current_session_verified: false,
            current_session_status: 'unverified',
            profile_binding_status: 'ready',
            profile_binding_reason: '',
            credential_metadata_status: 'not_required',
            credential_metadata_reason: 'browser_profile_vault_not_required',
            profile_login_trigger: {
              status: 'client_local_authorization_required',
              entry: 'https://ebooking.ctrip.com/home/mainland',
              after_login_sync: { entry: '/api/online-data/data-sources/14/sync' },
            },
            p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-10 --platform=ctrip --system-hotel-id=60',
          }],
        },
      }],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    const step = payload.next_steps[0];
    assert.equal(step.manual_login_state_verified, false);
    assert.equal(payload.platform_summaries[0].manual_login_state_verified_count, 0);
    assert.equal(step.historical_login_metadata_present, true);
    assert.equal(step.current_session_probe_performed, false);
    assert.equal(step.current_session_verified, false);
    assert.equal(step.login_trigger_status, 'ready_for_session_probe');
    assert.equal(step.login_trigger_entry, 'https://ebooking.ctrip.com/home/mainland');
    assert.equal(step.after_login_sync_entry, '');
    assert.equal(step.profile_flow_ready, false);
    assert(step.blocking_reason_codes.includes('current_session_probe_required'));
    assert.deepEqual(payload.operator_sequence.map((item) => item.type), [
      'session_probe',
      'single_scope_verifier',
    ]);
    assert.doesNotMatch(result.stdout, /"type": "after_login_sync"/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report never stitches Profile preparation and current session across sources', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-same-source-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-10' },
      platforms: [{
        platform: 'ctrip',
        p0_traffic_gate: {
          status: 'missing_target_date_traffic_rows',
          traffic_rows: 0,
          action_entry: '/api/online-data/capture-ctrip-browser',
          hotel_scoped_next_steps: [
            {
              system_hotel_id: 60,
              data_source_id: 21,
              data_source_status: 'success',
              last_sync_status: 'success',
              profile_dir_present: true,
              platform_hotel_identifier_present: false,
              current_session_probe_performed: true,
              current_session_verified: true,
              current_session_status: 'verified',
              profile_binding_status: 'ready',
              credential_metadata_status: 'not_required',
              credential_metadata_reason: 'browser_profile_vault_not_required',
              profile_login_trigger: {
                entry: 'https://ebooking.ctrip.com/home/mainland',
                after_login_sync: { entry: '/api/online-data/data-sources/21/sync' },
              },
              p0_verifier_command: 'verify-source-21',
            },
            {
              system_hotel_id: 64,
              data_source_id: 22,
              data_source_status: 'success',
              last_sync_status: 'success',
              profile_dir_present: false,
              platform_hotel_identifier_present: true,
              current_session_probe_performed: false,
              current_session_verified: false,
              current_session_status: 'unverified',
              profile_binding_status: 'ready',
              credential_metadata_status: 'not_required',
              credential_metadata_reason: 'browser_profile_vault_not_required',
              profile_login_trigger: {
                entry: 'https://ebooking.ctrip.com/home/mainland',
                after_login_sync: { entry: '/api/online-data/data-sources/22/sync' },
              },
              p0_verifier_command: 'verify-source-22',
            },
          ],
        },
      }],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_summaries[0].action_status, 'missing_inputs');
    assert.equal(payload.platform_summaries[0].action_entry, '');
    assert(payload.next_steps.every((step) => step.profile_preparation_ready === false));
    assert(payload.next_steps.every((step) => step.operational_actions_allowed === false));
    assert(payload.next_steps.every((step) => step.login_trigger_entry === ''));
    assert(payload.next_steps.every((step) => step.after_login_sync_entry === ''));
    assert.deepEqual(payload.operator_sequence.map((item) => item.type), [
      'profile_preparation_blocked',
      'single_scope_verifier',
      'profile_preparation_blocked',
      'single_scope_verifier',
    ]);
    assert.doesNotMatch(result.stdout, /\/api\/online-data\/data-sources\/(21|22)\/sync/);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report fails closed for unknown credential metadata', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-credential-unverified-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'incomplete',
      scope: { date: '2026-07-10' },
      platforms: [{
        platform: 'meituan',
        p0_traffic_gate: {
          status: 'missing_target_date_traffic_rows',
          traffic_rows: 0,
          hotel_scoped_next_steps: [{
            system_hotel_id: 7,
            data_source_id: 18,
            data_source_status: 'success',
            last_sync_status: 'success',
            profile_dir_present: true,
            platform_hotel_identifier_present: true,
            current_session_probe_performed: true,
            current_session_verified: true,
            current_session_status: 'verified',
            credential_metadata_status: 'unexpected_status',
            credential_metadata_reason: 'raw_unknown_reason',
            profile_login_trigger: {
              status: 'client_local_authorization_required',
              entry: 'https://me.meituan.com/ebooking/',
              after_login_sync: { entry: '/api/online-data/data-sources/18/sync' },
            },
            p0_verifier_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-10 --platform=meituan --system-hotel-id=7',
          }],
        },
      }],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    const step = payload.next_steps[0];
    assert.equal(step.credential_metadata_status, 'unverified');
    assert.equal(step.credential_metadata_reason, '');
    assert.equal(step.login_trigger_entry, '');
    assert.equal(step.after_login_sync_entry, '');
    assert(step.blocking_reason_codes.includes('ota_credential_metadata_unverified'));
    assert.deepEqual(payload.operator_sequence.map((item) => item.type), [
      'credential_metadata_unverified',
      'single_scope_verifier',
    ]);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

test('P0 Profile next-step report defaults verifier date in Asia Shanghai', () => {
  const source = readFileSync('scripts/report_p0_profile_next_steps.mjs', 'utf8');
  assert.match(source, /timeZone:\s*'Asia\/Shanghai'/);
  assert.doesNotMatch(source, /new Date\(\)\.toISOString\(\)\.slice\(0, 10\)/);
});

test('P0 Profile next-step report blocks an empty or mismatched platform scope', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'p0-profile-next-steps-empty-scope-'));
  const input = path.join(dir, 'verifier.json');
  try {
    writeFileSync(input, JSON.stringify({
      status: 'passed',
      inspector_status: 'passed',
      scope: { date: '2026-07-10', platforms: [] },
      summary: { platform_count: 0, p0_platforms_ready: 0, p0_platforms_incomplete: 0 },
      platforms: [],
    }));

    const result = spawnSync(process.execPath, ['scripts/report_p0_profile_next_steps.mjs', `--input=${input}`, '--json'], {
      cwd: process.cwd(),
      encoding: 'utf8',
      windowsHide: true,
    });

    assert.equal(result.status, 0, result.stderr);
    const payload = JSON.parse(result.stdout);
    assert.equal(payload.platform_scope.status, 'invalid');
    assert.equal(payload.completion_gate.current_status, 'invalid_platform_scope');
    assert.equal(payload.downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert(payload.downstream_gate.blocking_missing_inputs.includes('platform_scope_missing_or_mismatch'));
    assert.notDeepEqual(payload.downstream_gate.blocked_stage_keys, []);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
