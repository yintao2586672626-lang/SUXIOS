import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

const args = process.argv.slice(2);

function argValue(name, fallback = '') {
  const prefix = `--${name}=`;
  const match = args.find((arg) => arg.startsWith(prefix));
  if (match) return match.slice(prefix.length);
  const index = args.indexOf(`--${name}`);
  return index >= 0 && args[index + 1] ? args[index + 1] : fallback;
}

function hasFlag(name) {
  return args.includes(`--${name}`);
}

function outputFormat() {
  return String(argValue('format', '') || (hasFlag('json') ? 'json' : 'markdown')).trim().toLowerCase();
}

function csvArgValues(name) {
  const values = [];
  const prefix = `--${name}=`;
  for (const arg of args) {
    if (arg.startsWith(prefix)) {
      values.push(...arg.slice(prefix.length).split(','));
    }
  }
  const index = args.indexOf(`--${name}`);
  if (index >= 0 && args[index + 1]) {
    values.push(...args[index + 1].split(','));
  }
  return values
    .map((value) => String(value || '').trim().toLowerCase())
    .filter(Boolean);
}

function operatorSkippedPlatforms() {
  if (hasFlag('skip-p0') || hasFlag('allow-skip-p0')) {
    return { skipAll: true, platforms: new Set() };
  }
  return { skipAll: false, platforms: new Set(csvArgValues('skip-platform')) };
}

function extractJson(text) {
  const source = String(text || '').trim();
  const start = source.indexOf('{');
  const end = source.lastIndexOf('}');
  if (start < 0 || end <= start) {
    throw new Error('No JSON object found in verifier output.');
  }
  return JSON.parse(source.slice(start, end + 1));
}

function readVerifierOutput() {
  const input = argValue('input');
  if (input) {
    return {
      source: input,
      payload: extractJson(readFileSync(path.resolve(input), 'utf8')),
    };
  }

  const date = argValue('date', new Date().toISOString().slice(0, 10));
  const platform = argValue('platform');
  const php = argValue('php', existsSync('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : 'php');
  const verifierArgs = ['scripts\\verify_p0_ota_field_loop_closure.php', `--date=${date}`];
  if (platform) verifierArgs.push(`--platform=${platform}`);

  const result = spawnSync(php, verifierArgs, {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });

  if (!result.stdout && result.error) {
    throw result.error;
  }

  return {
    source: `${php} ${verifierArgs.join(' ')}`,
    exitCode: result.status,
    payload: extractJson(result.stdout || result.stderr || ''),
  };
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function isReadyStatus(status) {
  return ['ready', 'passed'].includes(String(status || '').trim().toLowerCase());
}

function isPlatformReady(platformPayload, gate) {
  const gateStatus = String(gate?.status || '').trim().toLowerCase();
  if (gateStatus) {
    return isReadyStatus(gateStatus);
  }
  const platformStatus = String(platformPayload?.status || '').trim().toLowerCase();
  if (platformStatus) {
    return isReadyStatus(platformStatus);
  }
  return Number(platformPayload?.target_date_rows || 0) > 0
    && Number(gate?.traffic_rows || 0) > 0
    && asArray(gate?.action_missing_inputs).length === 0;
}

function isStepReady(step, platformReady) {
  if (!platformReady) {
    return false;
  }
  const latestSyncTask = step?.latest_sync_task && typeof step.latest_sync_task === 'object'
    ? step.latest_sync_task
    : {};
  if (latestSyncTask.target_date_rows_proved === false) {
    return false;
  }
  const latestTaskDiagnosis = String(latestSyncTask.diagnosis || latestSyncTask.message_code || '').trim().toLowerCase();
  if (latestTaskDiagnosis.includes('requires_p0_target_date_verifier')) {
    return false;
  }
  return true;
}

function stepBlockingReasonCodes(step, options = {}) {
  if (options.stepReady === true) {
    return [];
  }

  const codes = [];
  const platformGateStatus = String(options.platformGateStatus || '').trim();
  if (platformGateStatus && !isReadyStatus(platformGateStatus)) {
    codes.push(platformGateStatus);
  }
  if (step?.manual_login_state_verified !== true) {
    codes.push('manual_login_state_verified');
  }

  const latestSyncTask = step?.latest_sync_task && typeof step.latest_sync_task === 'object'
    ? step.latest_sync_task
    : {};
  if (latestSyncTask.target_date_rows_proved === false) {
    codes.push('target_date_rows_unproved');
  }
  const latestTaskDiagnosis = String(latestSyncTask.diagnosis || latestSyncTask.message_code || '').trim().toLowerCase();
  if (latestTaskDiagnosis.includes('requires_p0_target_date_verifier')) {
    codes.push('requires_p0_target_date_verifier');
  }

  if (codes.length === 0) {
    codes.push('hotel_scoped_p0_step_unproved');
  }
  return Array.from(new Set(codes));
}

function compactStep(platform, step, options = {}) {
  const operatorSkipActive = options.operatorSkipActive === true;
  const platformReady = options.platformReady === true;
  const manualLoginVerified = step?.manual_login_state_verified === true;
  const skipWithVerifiedLogin = operatorSkipActive && manualLoginVerified;
  const trigger = step?.profile_login_trigger && typeof step.profile_login_trigger === 'object'
    ? step.profile_login_trigger
    : {};
  const afterLoginSync = trigger.after_login_sync && typeof trigger.after_login_sync === 'object'
    ? trigger.after_login_sync
    : {};
  return {
    platform,
    system_hotel_id: step?.system_hotel_id ?? null,
    data_source_id: step?.data_source_id ?? null,
    data_source_status: step?.data_source_status ?? '',
    last_sync_status: step?.last_sync_status ?? '',
    manual_login_state_verified: manualLoginVerified,
    login_trigger_status: platformReady
      ? 'already_ready_no_login'
      : (skipWithVerifiedLogin ? 'login_verified_reference_only' : (trigger.status ?? '')),
    login_trigger_entry: platformReady || skipWithVerifiedLogin ? '' : (trigger.entry ?? ''),
    after_login_sync_entry: operatorSkipActive || platformReady ? '' : (afterLoginSync.entry ?? ''),
    verifier_command: step?.p0_verifier_command ?? '',
    operator_skip_active: operatorSkipActive,
    platform_ready: platformReady,
    platform_gate_ready: options.platformGateReady === true,
    platform_gate_status: options.platformGateStatus || '',
    platform_action_status: options.platformActionStatus || '',
    blocking_reason_codes: stepBlockingReasonCodes(step, {
      stepReady: platformReady,
      platformGateStatus: options.platformGateStatus || '',
    }),
  };
}

function buildReport(verifier) {
  const payload = verifier.payload;
  const platforms = asArray(payload.platforms);
  const rows = [];
  const targetDate = payload.scope?.date || argValue('date', '');
  const skipped = operatorSkippedPlatforms();
  const platformSummaries = platforms.map((platformPayload) => {
    const platform = String(platformPayload?.platform || '');
    const gate = platformPayload?.p0_traffic_gate && typeof platformPayload.p0_traffic_gate === 'object'
      ? platformPayload.p0_traffic_gate
      : {};
    const operatorSkipActive = skipped.skipAll || skipped.platforms.has(platform.toLowerCase());
    const platformReady = isPlatformReady(platformPayload, gate);
    const steps = asArray(gate.hotel_scoped_next_steps).map((step) => compactStep(platform, step, {
      operatorSkipActive,
      platformReady: isStepReady(step, platformReady),
      platformGateReady: platformReady,
      platformGateStatus: gate.status || '',
      platformActionStatus: gate.action_status || '',
    }));
    const derivedManualLoginCount = steps.filter((step) => step.manual_login_state_verified).length;
    const derivedLoginTriggerCount = steps.filter((step) => step.login_trigger_entry).length;
    const derivedAfterLoginSyncCount = steps.filter((step) => step.after_login_sync_entry).length;
    const hotelStepReadyCount = steps.filter((step) => step.platform_ready).length;
    const hotelStepIncompleteCount = steps.filter((step) => !step.platform_ready && !step.operator_skip_active).length;
    const hotelStepBlockingReasonCodes = Array.from(new Set(steps.flatMap((step) => asArray(step.blocking_reason_codes))));
    rows.push(...steps);
    return {
      platform,
      target_date_rows: Number(platformPayload?.target_date_rows || 0),
      traffic_rows: Number(gate.traffic_rows || 0),
      action_entry: operatorSkipActive ? '' : (gate.action_entry || ''),
      action_status: operatorSkipActive ? 'skipped_by_operator_no_capture' : (gate.action_status || ''),
      p0_traffic_gate_status: gate.status || '',
      platform_ready: platformReady,
      hotel_scoped_ready: platformReady && hotelStepIncompleteCount === 0,
      hotel_step_count: steps.length,
      hotel_step_ready_count: hotelStepReadyCount,
      hotel_step_incomplete_count: hotelStepIncompleteCount,
      hotel_step_blocking_reason_codes: hotelStepBlockingReasonCodes,
      missing_inputs: asArray(gate.action_missing_inputs),
      next_step_count: Number(gate.p0_next_step_count || steps.length),
      manual_login_state_verified_count: Math.max(
        Number(gate.p0_manual_login_state_verified_count || 0),
        derivedManualLoginCount,
      ),
      operator_skip_active: operatorSkipActive,
      operator_skip_policy: operatorSkipActive ? 'p0_skipped_by_operator_reference_only_no_collection' : '',
      profile_login_trigger_available_count: Math.max(
        Number(gate.p0_profile_login_trigger_available_count || 0),
        derivedLoginTriggerCount,
      ),
      after_login_sync_available_count: Math.max(
        Number(gate.p0_after_login_sync_available_count || 0),
        derivedAfterLoginSyncCount,
      ),
    };
  });
  const hotelScopedReady = platformSummaries.every((item) => item.hotel_scoped_ready);
  const operatorSkipped = platformSummaries.some((item) => item.operator_skip_active);
  const reportReady = p0VerifierReady(payload.status) && hotelScopedReady && !operatorSkipped;
  const completionGate = {
    command: targetDate
      ? `npm.cmd run verify:p0-ota-field-loop -- --date=${targetDate}`
      : 'npm.cmd run verify:p0-ota-field-loop -- --date=<target-date>',
    required_status: 'ready',
    current_status: reportReady ? (payload.status || '') : (p0VerifierReady(payload.status) ? 'incomplete_hotel_scoped_steps' : (payload.status || '')),
    boundary: 'Completion requires target-date OTA rows and P0 field-loop evidence; this report is not completion proof.',
  };

  return {
    generated_at: new Date().toISOString(),
    source: verifier.source,
    verifier_exit_code: verifier.exitCode ?? null,
    status: payload.status || '',
    inspector_status: payload.inspector_status || '',
    scope: payload.scope || {},
    summary: payload.summary || {},
    sensitive_values_policy: 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
    collection_policy: buildCollectionPolicy(),
    platform_summaries: platformSummaries,
    next_steps: rows,
    operator_sequence: buildOperatorSequence(rows),
    completion_gate: completionGate,
    downstream_gate: buildDownstreamGate(payload, completionGate, platformSummaries),
  };
}

function buildCollectionPolicy() {
  return {
    mainline_mode: 'browser_profile',
    mainline_label: '浏览器 Profile 登录态采集',
    temporary_mode: 'manual_cookie_api',
    temporary_mode_policy: 'temporary_only',
    temporary_mode_allowed_for: [
      '临时补数',
      '首次接入',
      '平台改版排障',
      '自动 Profile 采集失效后的补录',
    ],
    mainline_required_gates: [
      'authorized_browser_profile',
      'manual_login_state_verified',
      'target_date_ota_rows',
      'target_date_traffic_rows',
      'p0_field_loop_verifier_ready',
    ],
    forbidden_claims_before_ready: [
      'manual_cookie_api_as_default_mainline',
      'profile_directory_as_login_verified',
      'sync_task_success_as_p0_closure',
      'historical_rows_as_target_date_closure',
    ],
  };
}

function buildOperatorSequence(rows) {
  const sequence = [];
  for (const step of rows) {
    if (step.platform_ready) {
      sequence.push({
        type: 'already_ready',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        status: 'p0_traffic_gate_ready',
        boundary: 'Target-date OTA rows and traffic field evidence are already ready; do not start login or after-login sync from this report.',
      });
      sequence.push({
        type: 'single_scope_verifier',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        command: step.verifier_command,
        required_result: 'status=ready for this platform/hotel traffic gate',
        boundary: 'Read-only verifier remains the evidence gate for ready platforms.',
      });
      continue;
    }
    if (step.operator_skip_active) {
      sequence.push({
        type: 'operator_skip',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        status: 'p0_skipped_by_operator',
        boundary: 'No OTA collection or after-login sync should be started for this platform while the operator skip is active.',
        completion_effect: 'P0 remains incomplete; downstream reports may use reference-only wording only.',
      });
      sequence.push({
        type: 'single_scope_verifier',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        command: step.verifier_command,
        required_result: 'status=ready for this platform/hotel traffic gate',
        boundary: 'Read-only verifier remains the evidence gate; skip status is not completion proof.',
      });
      continue;
    }
    sequence.push({
      type: 'manual_login',
      platform: step.platform,
      system_hotel_id: step.system_hotel_id,
      data_source_id: step.data_source_id,
      entry: step.login_trigger_entry,
      status: step.login_trigger_status,
      required_human_action: 'Complete authorized OTA login, captcha/SMS/human verification, and permission confirmation in the opened browser Profile.',
      sensitive_values_policy: 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
    });
    sequence.push({
      type: 'after_login_sync',
      platform: step.platform,
      system_hotel_id: step.system_hotel_id,
      data_source_id: step.data_source_id,
      entry: step.after_login_sync_entry,
      requires: 'manual_login_state_verified=true',
      boundary: 'Run only after the human login step succeeds; does not bypass OTA controls.',
    });
    sequence.push({
      type: 'single_scope_verifier',
      platform: step.platform,
      system_hotel_id: step.system_hotel_id,
      data_source_id: step.data_source_id,
      command: step.verifier_command,
      required_result: 'status=ready for this platform/hotel traffic gate',
      boundary: 'Verifier output is the evidence gate; sync task success alone is not closure.',
    });
  }
  return sequence;
}

function p0VerifierReady(status) {
  return ['ready', 'passed'].includes(String(status || '').trim());
}

function buildDownstreamGate(payload, completionGate, platformSummaries = []) {
  const status = String(payload?.status || '');
  const blockingMissingInputs = new Set();
  const operatorSkipped = asArray(platformSummaries)
    .filter((item) => item?.operator_skip_active === true)
    .map((item) => String(item.platform || '').trim())
    .filter(Boolean);
  const hotelScopedIncomplete = asArray(platformSummaries)
    .filter((item) => Number(item?.hotel_step_incomplete_count || 0) > 0);
  const isReady = p0VerifierReady(status)
    && operatorSkipped.length === 0
    && hotelScopedIncomplete.length === 0;
  const stageDefinitions = [
    ['revenue_analysis', '收益分析'],
    ['ai_decision_advice', 'AI 决策建议'],
    ['operation_closure', '运营闭环'],
    ['investment_judgment', '投资判断'],
  ];

  for (const platformPayload of asArray(payload?.platforms)) {
    const gate = platformPayload?.p0_traffic_gate && typeof platformPayload.p0_traffic_gate === 'object'
      ? platformPayload.p0_traffic_gate
      : {};
    for (const item of asArray(gate.action_missing_inputs)) {
      if (item) blockingMissingInputs.add(String(item));
    }
    if (!isPlatformReady(platformPayload, gate)) {
      const gateStatus = String(gate.status || '').trim();
      if (gateStatus) {
        blockingMissingInputs.add(gateStatus);
      }
    }
    if (Number(platformPayload?.target_date_rows || 0) <= 0) {
      blockingMissingInputs.add('target_date_ota_rows');
    }
    if (Number(gate.traffic_rows || 0) <= 0) {
      blockingMissingInputs.add('target_date_traffic_rows');
    }
  }
  if (operatorSkipped.length > 0) {
    blockingMissingInputs.add('p0_skipped_by_operator');
  }
  for (const item of hotelScopedIncomplete) {
    const platform = String(item.platform || '').trim();
    blockingMissingInputs.add(platform ? `${platform}_hotel_scoped_p0_steps_unproved` : 'hotel_scoped_p0_steps_unproved');
    for (const code of asArray(item.hotel_step_blocking_reason_codes)) {
      if (code) blockingMissingInputs.add(String(code));
    }
  }

  return {
    status: isReady ? 'open' : 'blocked_by_p0_ota_gate',
    current_upstream_status: status || 'unknown',
    required_upstream_status: completionGate.required_status,
    required_gate_command: completionGate.command,
    scope_policy: 'ota_channel_gate_before_downstream_claims',
    blocking_missing_inputs: isReady ? [] : Array.from(blockingMissingInputs).sort(),
    operator_skip_platforms: isReady ? [] : operatorSkipped,
    operator_skip_policy: operatorSkipped.length > 0
      ? 'operator_skip_is_reference_only_and_does_not_complete_p0'
      : '',
    blocked_stage_keys: isReady ? [] : stageDefinitions.map(([key]) => key),
    stages: stageDefinitions.map(([key, label]) => ({
      key,
      label,
      status: isReady ? 'open' : 'blocked_by_p0_ota_gate',
      boundary: isReady
        ? 'P0 OTA gate is ready; downstream still must keep OTA channel scope separate from whole-hotel scope.'
        : 'Do not claim this downstream stage as truly closed until the P0 OTA field-loop verifier is ready.',
    })),
    allowed_claims: isReady
      ? ['ota_channel_downstream_checks_may_continue_with_scope_boundary']
      : ['structure_ready_or_reference_only', 'historical_rows_reference_only', 'no_whole_hotel_or_downstream_closure_claim'],
  };
}

function platformLabel(platform) {
  return platform === 'ctrip' ? '携程' : platform === 'meituan' ? '美团' : platform;
}

function renderMarkdown(report) {
  const date = report.scope?.date || '';
  const lines = [
    '# P0 OTA Profile 下一步清单',
    '',
    `- 日期: ${date || 'unknown'}`,
    `- P0 状态: ${report.status || 'unknown'}`,
    `- 取证来源: ${report.source}`,
    `- 脱敏策略: ${report.sensitive_values_policy}`,
    `- 默认采集主线: ${report.collection_policy.mainline_label} (${report.collection_policy.mainline_mode})`,
    `- 手动 Cookie/API: ${report.collection_policy.temporary_mode_policy}`,
    '',
    '## 平台状态',
    '',
    '| 平台 | 目标日行 | 流量行 | 主线入口 | 状态 | operator_skip_active | 缺口 | 登录入口数 | 登录后同步数 |',
    '| --- | ---: | ---: | --- | --- | --- | --- | ---: | ---: |',
  ];

  for (const item of report.platform_summaries) {
    lines.push([
      platformLabel(item.platform),
      item.target_date_rows,
      item.traffic_rows,
      item.action_entry || '-',
      item.action_status || '-',
      item.operator_skip_active ? item.operator_skip_policy || 'p0_skipped_by_operator' : '-',
      item.missing_inputs.length ? item.missing_inputs.join(', ') : '-',
      item.profile_login_trigger_available_count,
      item.after_login_sync_available_count,
    ].join(' | ').replace(/^/, '| ').replace(/$/, ' |'));
  }

  lines.push('', '## 酒店级执行顺序', '');

  if (report.next_steps.length === 0) {
    lines.push('- 当前 verifier 未暴露酒店级 Profile 步骤。');
  } else {
    report.next_steps.forEach((step, index) => {
      lines.push(
        `${index + 1}. ${platformLabel(step.platform)} system_hotel_id=${step.system_hotel_id} / data_source_id=${step.data_source_id}`,
        `   - 当前状态: source=${step.data_source_status || '-'}, last_sync=${step.last_sync_status || '-'}, manual_login_state_verified=${step.manual_login_state_verified ? 'true' : 'false'}`,
        `   - platform_ready=${step.platform_ready ? 'true' : 'false'}`,
        `   - operator_skip_active=${step.operator_skip_active ? 'true' : 'false'}`,
        `   - 登录触发: ${step.platform_ready ? 'already_ready_no_login' : (step.operator_skip_active && step.manual_login_state_verified ? 'login_verified_reference_only' : (step.login_trigger_entry || '-'))} (${step.login_trigger_status || '-'})`,
        `   - 登录后同步: ${step.platform_ready ? 'already_ready_no_sync' : (step.operator_skip_active ? 'skipped_by_operator_no_sync' : (step.after_login_sync_entry || '-'))}`,
        `   - 复验命令: ${step.verifier_command || '-'}`,
      );
    });
  }

  lines.push('', '## 执行门禁', '');
  for (const item of report.operator_sequence) {
    if (item.type === 'manual_login') {
      lines.push(`- 登录: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.entry || '-'} (${item.status || '-'})`);
    } else if (item.type === 'after_login_sync') {
      lines.push(`- 同步: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.entry || '-'}，前置=${item.requires}`);
    } else if (item.type === 'already_ready') {
      lines.push(`- already_ready: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.status}; ${item.boundary}`);
    } else if (item.type === 'operator_skip') {
      lines.push(`- operator_skip: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.status}; ${item.boundary}`);
    } else if (item.type === 'single_scope_verifier') {
      lines.push(`- 复验: ${item.command || '-'}`);
    }
  }
  lines.push(
    `- 全局完成门禁: ${report.completion_gate.command}`,
    `- 当前状态: ${report.completion_gate.current_status || 'unknown'}；要求状态: ${report.completion_gate.required_status}`,
  );

  lines.push('', '## 下游推进门禁', '');
  lines.push(
    `- 状态: ${report.downstream_gate.status}`,
    `- 要求上游门禁: ${report.downstream_gate.required_gate_command}`,
    `- 阻断缺口: ${report.downstream_gate.blocking_missing_inputs.length ? report.downstream_gate.blocking_missing_inputs.join(', ') : '-'}`,
    `- operator_skip_platforms: ${report.downstream_gate.operator_skip_platforms.length ? report.downstream_gate.operator_skip_platforms.join(', ') : '-'}`,
    `- 受限阶段: ${report.downstream_gate.blocked_stage_keys.length ? report.downstream_gate.blocked_stage_keys.join(', ') : '-'}`,
    `- 允许结论: ${report.downstream_gate.allowed_claims.join(', ')}`,
  );

  lines.push(
    '',
    '## 边界',
    '',
    '- 该报告只读取 P0 verifier 的脱敏元数据，不触发 OTA 登录、采集或入库。',
    '- Profile 目录存在不等于登录态已验证；必须由人工完成平台登录/验证码/短信/权限确认。',
    '- 手动 Cookie/API 只用于临时补数或排障，不作为默认运营主线。',
  );

  return `${lines.join('\n')}\n`;
}

try {
  const report = buildReport(readVerifierOutput());
  if (outputFormat() === 'json') {
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  } else {
    process.stdout.write(renderMarkdown(report));
  }
} catch (error) {
  console.error(`[report:p0-profile-next-steps] ${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
}
