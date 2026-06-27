import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
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
                  status: 'available',
                  entry: '/api/online-data/profile-login-trigger/ctrip',
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
    assert.match(result.stdout, /\/api\/online-data\/profile-login-trigger\/ctrip/);
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
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});
