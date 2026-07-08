import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

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
            action_missing_inputs: ['manual_login_state_verified'],
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
    assert.match(result.stdout, /\/api\/online-data\/data-sources\/14\/sync/);
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
    assert(payload.collection_policy.mainline_required_gates.includes('manual_login_state_verified'));
    assert(payload.collection_policy.mainline_required_gates.includes('p0_field_loop_verifier_ready'));
    assert(payload.collection_policy.forbidden_claims_before_ready.includes('manual_cookie_api_as_default_mainline'));
    assert(payload.collection_policy.forbidden_claims_before_ready.includes('sync_task_success_as_p0_closure'));
    assert.equal(payload.downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert.equal(payload.downstream_gate.required_gate_command, payload.completion_gate.command);
    assert.deepEqual(payload.downstream_gate.blocked_stage_keys, [
      'revenue_analysis',
      'ai_decision_advice',
      'operation_closure',
      'investment_judgment',
    ]);
    assert(payload.downstream_gate.blocking_missing_inputs.includes('manual_login_state_verified'));
    assert(payload.downstream_gate.allowed_claims.includes('no_whole_hotel_or_downstream_closure_claim'));
    assert.equal(payload.operator_sequence.length, 3);
    assert.deepEqual(payload.operator_sequence.map(item => item.type), ['manual_login', 'after_login_sync', 'single_scope_verifier']);
    assert.equal(payload.operator_sequence[1].requires, 'manual_login_state_verified=true');
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
      scope: { date: '2026-06-28' },
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
    assert.deepEqual(payload.next_steps.map(step => step.login_trigger_status), ['client_local_authorization_required', 'client_local_authorization_required']);
    assert.deepEqual(payload.next_steps.map(step => step.after_login_sync_entry), [
      '/api/online-data/data-sources/14/sync',
      '/api/online-data/data-sources/15/sync',
    ]);
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'manual_login',
      'after_login_sync',
      'single_scope_verifier',
      'manual_login',
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
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('manual_login_state_verified'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('profile_login_trigger_not_available'));
    assert(payload.platform_summaries[0].profile_flow_blocking_reason_codes.includes('missing_platform_data_source_or_hotel_scope'));
    assert.equal(payload.next_steps[0].platform_ready, true);
    assert.equal(payload.next_steps[0].profile_flow_ready, false);
    assert.equal(payload.collection_flow_gate.status, 'blocked_by_profile_flow_gap');
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('ctrip_profile_flow_unproved'));
    assert(payload.collection_flow_gate.blocking_missing_inputs.includes('data_source_not_registered'));

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
      scope: { date: '2026-07-08' },
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
    assert.equal(payload.next_steps[0].login_trigger_status, 'client_local_authorization_required');
    assert.equal(payload.next_steps[0].login_trigger_entry, 'https://ebooking.ctrip.com/home/mainland');
    assert.equal(payload.next_steps[0].after_login_sync_entry, '/api/online-data/data-sources/14/sync');
    assert.equal(payload.completion_gate.current_status, 'incomplete_hotel_scoped_steps');
    assert.equal(payload.downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert(payload.downstream_gate.blocking_missing_inputs.includes('ctrip_hotel_scoped_p0_steps_unproved'));
    assert(payload.downstream_gate.blocking_missing_inputs.includes('target_date_rows_unproved'));
    assert.deepEqual(payload.operator_sequence.map(item => item.type), [
      'manual_login',
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
