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

function shanghaiCalendarDate(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
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

  const date = argValue('date', shanghaiCalendarDate());
  const platform = argValue('platform');
  const systemHotelId = argValue('system-hotel-id', argValue('system_hotel_id'));
  const php = argValue('php', existsSync('C:\\xampp\\php\\php.exe') ? 'C:\\xampp\\php\\php.exe' : 'php');
  const verifierArgs = ['scripts\\verify_p0_ota_field_loop_closure.php', `--date=${date}`];
  if (platform) verifierArgs.push(`--platform=${platform}`);
  if (systemHotelId) verifierArgs.push(`--system-hotel-id=${systemHotelId}`);

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

const supportedP0Platforms = new Set(['ctrip', 'meituan']);

function platformScopeStatus(payload) {
  const scopeHasPlatformArray = Array.isArray(payload?.scope?.platforms);
  const resultHasPlatformArray = Array.isArray(payload?.platforms);
  const expectedRaw = asArray(payload?.scope?.platforms)
    .map((item) => String(item || '').trim().toLowerCase())
    .filter(Boolean);
  const resultRaw = asArray(payload?.platforms)
    .map((item) => String(item?.platform || '').trim().toLowerCase())
    .filter(Boolean);
  const expected = Array.from(new Set(expectedRaw)).sort();
  const actual = Array.from(new Set(resultRaw)).sort();
  const reasonCodes = [];

  if (!scopeHasPlatformArray || !resultHasPlatformArray) {
    reasonCodes.push('platform_scope_schema_missing');
  }
  if (expected.length === 0 || actual.length === 0) {
    reasonCodes.push('platform_scope_empty');
  }
  if (expectedRaw.length !== expected.length || resultRaw.length !== actual.length) {
    reasonCodes.push('platform_scope_duplicate');
  }
  if ([...expected, ...actual].some((platform) => !supportedP0Platforms.has(platform))) {
    reasonCodes.push('platform_scope_unsupported');
  }
  if (expected.length !== actual.length || expected.some((platform, index) => platform !== actual[index])) {
    reasonCodes.push('platform_scope_mismatch');
  }

  return {
    status: reasonCodes.length === 0 ? 'valid' : 'invalid',
    expected_platforms: expected,
    result_platforms: actual,
    reason_codes: Array.from(new Set(reasonCodes)),
  };
}

const safeCredentialMetadataStatuses = new Set(['ready', 'not_required', 'migration_required', 'blocked']);
const safeCredentialMetadataReasonCodes = new Set([
  'ota_credentials_table_missing',
  'ota_credentials_metadata_schema_incomplete',
  'hotels_table_missing',
  'hotel_tenant_metadata_missing',
  'data_source_tenant_scope_mismatch',
  'source_config_projection_conflict',
  'credential_reference_missing',
  'credential_metadata_not_found',
  'credential_metadata_ambiguous',
  'credential_reference_config_mismatch',
  'platform_data_sources_table_missing',
  'platform_data_sources_metadata_schema_incomplete',
  'credential_source_projection_missing',
  'credential_metadata_missing',
  'credential_not_ready',
  'credential_tenant_scope_mismatch',
  'registered_traffic_data_source_missing',
  'browser_profile_vault_not_required',
]);
const safeProfileBindingStatuses = new Set(['ready', 'blocked', 'migration_required']);
const safeProfileBindingReasonCodes = new Set([
  'profile_binding_table_missing',
  'profile_binding_schema_incomplete',
  'profile_binding_missing',
  'profile_binding_not_active',
  'profile_binding_ambiguous',
  'profile_binding_scope_mismatch',
  'profile_scope_conflict_across_hotel_or_tenant',
  'profile_key_missing',
]);

function safeCredentialMetadata(step) {
  const candidateStatus = String(step?.credential_metadata_status || '').trim().toLowerCase();
  const status = safeCredentialMetadataStatuses.has(candidateStatus) ? candidateStatus : 'unverified';
  const candidateReason = String(step?.credential_metadata_reason || '').trim().toLowerCase();
  return {
    status,
    reason: status === 'ready' || !safeCredentialMetadataReasonCodes.has(candidateReason)
      ? ''
      : candidateReason,
  };
}

function credentialMetadataBlockingReasonCodes(step) {
  const metadata = safeCredentialMetadata(step);
  if (['ready', 'not_required'].includes(metadata.status)) {
    return [];
  }
  if (metadata.status === 'migration_required') {
    return ['ota_credential_metadata_migration_required', ...(metadata.reason ? [metadata.reason] : [])];
  }
  if (metadata.status === 'blocked') {
    return ['ota_credential_metadata_blocked', ...(metadata.reason ? [metadata.reason] : [])];
  }
  return ['ota_credential_metadata_unverified'];
}

function safeProfileBinding(step) {
  const candidateStatus = String(step?.profile_binding_status || '').trim().toLowerCase();
  const status = safeProfileBindingStatuses.has(candidateStatus) ? candidateStatus : 'unverified';
  const candidateReason = String(step?.profile_binding_reason || '').trim().toLowerCase();
  return {
    status,
    reason: status === 'ready' || !safeProfileBindingReasonCodes.has(candidateReason) ? '' : candidateReason,
  };
}

function isReadyStatus(status) {
  return ['ready', 'passed'].includes(String(status || '').trim().toLowerCase());
}

function positiveInt(value) {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : 0;
}

function uniquePositiveInts(values) {
  return Array.from(new Set(asArray(values)
    .map((value) => positiveInt(value))
    .filter((value) => value > 0))).sort((a, b) => a - b);
}

function intRecord(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }
  return Object.fromEntries(Object.entries(value)
    .map(([key, count]) => [String(positiveInt(key)), Number(count || 0)])
    .filter(([key]) => key !== '0'));
}

function difference(left, right) {
  const rightSet = new Set(right);
  return left.filter((value) => !rightSet.has(value));
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

function profileStepAuthorityScore(step) {
  const dataSourceStatus = String(step?.data_source_status || '').trim().toLowerCase();
  const bindingStatus = safeProfileBinding(step).status;
  const sessionVerified = step?.current_session_probe_performed === true
    && step?.current_session_verified === true;
  return (['success', 'ready'].includes(dataSourceStatus) ? 100 : 0)
    + (bindingStatus === 'ready' ? 50 : 0)
    + (sessionVerified ? 25 : 0)
    + (positiveInt(step?.data_source_id) > 0 ? 5 : 0);
}

function isStepReady(step, platformReady, options = {}) {
  if (!platformReady) {
    return false;
  }
  if (options.authoritative === false) {
    return false;
  }
  if (options.multiSourceHotel === true && options.authoritative === true) {
    return true;
  }
  const latestSyncTask = step?.latest_sync_task && typeof step.latest_sync_task === 'object'
    ? step.latest_sync_task
    : {};
  if (latestSyncTask.target_date_rows_proved === false) {
    return false;
  }
  const diagnosis = String(latestSyncTask.diagnosis || latestSyncTask.message_code || '').trim().toLowerCase();
  return !diagnosis.includes('requires_p0_target_date_verifier');
}

function profileFlowBlockingReasonCodes(step) {
  const codes = [];
  const dataSourceId = positiveInt(step?.data_source_id);
  const dataSourceStatus = String(step?.data_source_status || '').trim().toLowerCase();
  const lastSyncStatus = String(step?.last_sync_status || '').trim().toLowerCase();
  const trigger = step?.profile_login_trigger && typeof step.profile_login_trigger === 'object'
    ? step.profile_login_trigger
    : {};
  const triggerStatus = String(trigger.status || '').trim().toLowerCase();
  const afterLoginSync = trigger.after_login_sync && typeof trigger.after_login_sync === 'object'
    ? trigger.after_login_sync
    : {};

  if (!dataSourceId) codes.push('missing_data_source_id');
  if (dataSourceStatus === 'not_registered') codes.push('data_source_not_registered');
  if (dataSourceId && dataSourceStatus && !['success', 'ready'].includes(dataSourceStatus)) {
    codes.push(`data_source_${dataSourceStatus}`);
  }
  if (dataSourceId && lastSyncStatus && !['success', 'ready'].includes(lastSyncStatus)) {
    codes.push(`last_sync_${lastSyncStatus}`);
  }
  if (step?.profile_dir_present !== true) codes.push('authorized_profile_dir');
  if (step?.platform_hotel_identifier_present !== true) codes.push('platform_hotel_or_poi_id');
  const binding = safeProfileBinding(step);
  const bindingStatus = binding.status;
  if (bindingStatus !== 'ready') {
    codes.push(binding.reason || 'profile_binding_unverified');
  }
  if (step?.current_session_probe_performed !== true) {
    codes.push('current_session_probe_required');
  } else if (step?.current_session_verified !== true) {
    codes.push('current_session_not_verified');
  }
  if (triggerStatus && !['available', 'ready', 'ready_for_session_probe', 'client_local_authorization_required'].includes(triggerStatus)) {
    codes.push(`profile_login_trigger_${triggerStatus}`);
  }
  if (trigger.reason) codes.push(String(trigger.reason));
  if (dataSourceId && step?.current_session_verified === true && !afterLoginSync.entry) {
    codes.push('after_login_sync_entry_missing');
  }

  return Array.from(new Set(codes));
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
  codes.push(...profileFlowBlockingReasonCodes(step));
  codes.push(...credentialMetadataBlockingReasonCodes(step));

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
  const currentSessionProbePerformed = step?.current_session_probe_performed === true;
  const currentSessionStatus = String(step?.current_session_status || '').trim().toLowerCase();
  const currentSessionVerified = currentSessionProbePerformed
    && step?.current_session_verified === true
    && ['verified', 'logged_in', 'ready'].includes(currentSessionStatus);
  const manualLoginVerified = currentSessionVerified;
  const historicalLoginMetadataPresent = step?.historical_login_metadata_present === true
    || (step?.manual_login_state_verified === true && !currentSessionVerified);
  const skipWithVerifiedLogin = operatorSkipActive && manualLoginVerified;
  const trigger = step?.profile_login_trigger && typeof step.profile_login_trigger === 'object'
    ? step.profile_login_trigger
    : {};
  const afterLoginSync = trigger.after_login_sync && typeof trigger.after_login_sync === 'object'
    ? trigger.after_login_sync
    : {};
  const credentialMetadata = safeCredentialMetadata(step);
  const credentialMetadataAllowsActions = ['ready', 'not_required'].includes(credentialMetadata.status);
  const profileBinding = safeProfileBinding(step);
  const profileBindingStatus = profileBinding.status;
  const profileBindingReason = profileBinding.reason;
  const profileBindingAllowsActions = profileBindingStatus === 'ready';
  const dataSourceId = positiveInt(step?.data_source_id);
  const profileDirPresent = step?.profile_dir_present === true;
  const platformHotelIdentifierPresent = step?.platform_hotel_identifier_present === true;
  const profilePreparationReady = dataSourceId > 0
    && profileDirPresent
    && platformHotelIdentifierPresent
    && profileBindingAllowsActions;
  const actionsAllowed = credentialMetadataAllowsActions && profilePreparationReady;
  const profileFlowBlockers = profileFlowBlockingReasonCodes(step);
  return {
    platform,
    system_hotel_id: step?.system_hotel_id ?? null,
    data_source_id: step?.data_source_id ?? null,
    data_source_status: step?.data_source_status ?? '',
    last_sync_status: step?.last_sync_status ?? '',
    manual_login_state_verified: manualLoginVerified,
    historical_login_metadata_present: historicalLoginMetadataPresent,
    login_evidence_scope: currentSessionVerified ? 'current_session_probe' : 'historical_metadata_only',
    current_session_probe_performed: currentSessionProbePerformed,
    current_session_verified: currentSessionVerified,
    current_session_status: currentSessionVerified ? currentSessionStatus : 'unverified',
    profile_dir_present: profileDirPresent,
    platform_hotel_identifier_present: platformHotelIdentifierPresent,
    profile_binding_status: profileBindingStatus,
    profile_binding_reason: profileBindingReason,
    profile_preparation_ready: profilePreparationReady,
    operational_actions_allowed: actionsAllowed,
    credential_metadata_status: credentialMetadata.status,
    credential_metadata_reason: credentialMetadata.reason,
    login_trigger_status: platformReady
      ? 'already_ready_no_login'
      : (!credentialMetadataAllowsActions
        ? `credential_metadata_${credentialMetadata.status}`
        : (!profileBindingAllowsActions
          ? `profile_binding_${profileBindingStatus}`
          : (!profilePreparationReady
            ? 'profile_preparation_missing'
            : (skipWithVerifiedLogin
              ? 'login_verified_reference_only'
              : (currentSessionVerified ? 'current_session_verified' : 'ready_for_session_probe'))))),
    login_trigger_entry: platformReady || skipWithVerifiedLogin || !actionsAllowed || currentSessionVerified ? '' : (trigger.entry ?? ''),
    after_login_sync_entry: operatorSkipActive || platformReady || !actionsAllowed || !currentSessionVerified ? '' : (afterLoginSync.entry ?? ''),
    verifier_command: step?.p0_verifier_command ?? '',
    operator_skip_active: operatorSkipActive,
    platform_ready: platformReady,
    platform_gate_ready: options.platformGateReady === true,
    platform_gate_status: options.platformGateStatus || '',
    platform_action_status: options.platformActionStatus || '',
    profile_flow_ready: profileFlowBlockers.length === 0 && actionsAllowed && currentSessionVerified,
    profile_flow_blocking_reason_codes: profileFlowBlockers,
    blocking_reason_codes: stepBlockingReasonCodes(step, {
      stepReady: platformReady,
      platformGateStatus: options.platformGateStatus || '',
    }),
  };
}

function scopedVerifierCommand(scope, fallbackDate) {
  const date = scope?.date || fallbackDate || '<target-date>';
  const parts = ['npm.cmd run verify:p0-ota-field-loop', '--', `--date=${date}`];
  const platforms = asArray(scope?.platforms).map((item) => String(item || '').trim()).filter(Boolean);
  if (platforms.length === 1) {
    parts.push(`--platform=${platforms[0]}`);
  }
  const systemHotelId = positiveInt(scope?.system_hotel_id);
  if (systemHotelId > 0) {
    parts.push(`--system-hotel-id=${systemHotelId}`);
  }
  return parts.join(' ');
}

function buildReport(verifier) {
  const payload = verifier.payload;
  const platforms = asArray(payload.platforms);
  const platformScope = platformScopeStatus(payload);
  const verifierProcessReady = verifier.exitCode == null || verifier.exitCode === 0;
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
    const rawSteps = asArray(gate.hotel_scoped_next_steps);
    const stepIndexesByHotel = new Map();
    rawSteps.forEach((step, index) => {
      const hotelId = positiveInt(step?.system_hotel_id);
      if (!stepIndexesByHotel.has(hotelId)) stepIndexesByHotel.set(hotelId, []);
      stepIndexesByHotel.get(hotelId).push(index);
    });
    const authoritativeStepIndexes = new Set();
    for (const indexes of stepIndexesByHotel.values()) {
      const selectedIndex = [...indexes].sort((left, right) => (
        profileStepAuthorityScore(rawSteps[right]) - profileStepAuthorityScore(rawSteps[left])
        || positiveInt(rawSteps[right]?.data_source_id) - positiveInt(rawSteps[left]?.data_source_id)
      ))[0];
      if (selectedIndex !== undefined) authoritativeStepIndexes.add(selectedIndex);
    }
    const steps = rawSteps.map((step, index) => {
      const hotelId = positiveInt(step?.system_hotel_id);
      const sameHotelIndexes = stepIndexesByHotel.get(hotelId) || [];
      const authoritative = authoritativeStepIndexes.has(index);
      return compactStep(platform, step, {
        operatorSkipActive,
        platformReady: isStepReady(step, platformReady, {
          authoritative,
          multiSourceHotel: sameHotelIndexes.length > 1,
        }),
        platformGateReady: platformReady,
        platformGateStatus: gate.status || '',
        platformActionStatus: gate.action_status || '',
      });
    });
    const targetDateTrafficSystemHotelRowCounts = intRecord(gate.system_hotel_row_counts);
    const targetDateTrafficSystemHotelIds = uniquePositiveInts(
      asArray(gate.system_hotel_ids).length > 0
        ? gate.system_hotel_ids
        : Object.keys(targetDateTrafficSystemHotelRowCounts),
    );
    const stepSystemHotelIds = uniquePositiveInts(steps.map((step) => step.system_hotel_id));
    const targetHotelsMissingSteps = difference(targetDateTrafficSystemHotelIds, stepSystemHotelIds);
    const stepHotelsMissingTargetTraffic = difference(stepSystemHotelIds, targetDateTrafficSystemHotelIds);
    const targetTrafficScopeBlockingReasonCodes = targetHotelsMissingSteps.length > 0
      ? ['target_date_traffic_without_profile_step']
      : [];
    const targetTrafficScopeReferenceReasonCodes = stepHotelsMissingTargetTraffic.length > 0
      ? ['profile_step_without_target_date_traffic']
      : [];
    let scopeStatus = 'matched_or_not_provided';
    if (targetDateTrafficSystemHotelIds.length > 0 && stepSystemHotelIds.length > 0) {
      scopeStatus = targetHotelsMissingSteps.length > 0
        ? 'mismatch'
        : (stepHotelsMissingTargetTraffic.length > 0 ? 'target_covered_with_extra_reference_steps' : 'matched_or_not_provided');
    }
    const derivedManualLoginCount = steps.filter((step) => step.manual_login_state_verified).length;
    const derivedLoginTriggerCount = steps.filter((step) => step.login_trigger_entry).length;
    const derivedAfterLoginSyncCount = steps.filter((step) => step.after_login_sync_entry).length;
    const hotelStepReadyCount = steps.filter((step) => step.platform_ready).length;
    const readyHotelIds = new Set(uniquePositiveInts(
      steps.filter((step) => step.platform_ready).map((step) => step.system_hotel_id),
    ));
    const profileFlowReadyHotelIds = new Set(uniquePositiveInts(
      steps.filter((step) => step.profile_flow_ready).map((step) => step.system_hotel_id),
    ));
    const targetHotelIds = new Set(
      targetDateTrafficSystemHotelIds.length > 0 ? targetDateTrafficSystemHotelIds : stepSystemHotelIds,
    );
    const hotelStepIncompleteSteps = steps.filter((step) => {
      const hotelId = positiveInt(step.system_hotel_id);
      return !step.platform_ready
        && !step.operator_skip_active
        && targetHotelIds.has(hotelId)
        && !readyHotelIds.has(hotelId);
    });
    const hotelStepIncompleteCount = hotelStepIncompleteSteps.length;
    const hotelStepBlockingReasonCodes = Array.from(new Set(
      hotelStepIncompleteSteps.flatMap((step) => asArray(step.blocking_reason_codes)),
    ));
    const profileFlowIncompleteSteps = steps.filter((step) => {
      const hotelId = positiveInt(step.system_hotel_id);
      return !step.profile_flow_ready
        && !step.operator_skip_active
        && targetHotelIds.has(hotelId)
        && !profileFlowReadyHotelIds.has(hotelId);
    });
    const profileFlowBlockingReasonCodes = Array.from(new Set([
      ...profileFlowIncompleteSteps.flatMap((step) => asArray(step.profile_flow_blocking_reason_codes)),
      ...targetTrafficScopeBlockingReasonCodes,
    ]));
    const currentSessionReadyCount = steps.filter(
      (step) => step.current_session_verified && step.operational_actions_allowed,
    ).length;
    const probeReadyCount = steps.filter((step) => step.login_trigger_status === 'ready_for_session_probe' && step.login_trigger_entry).length;
    const safeActionStatus = operatorSkipActive
      ? 'skipped_by_operator_no_capture'
      : (platformReady
        ? 'ready'
        : (currentSessionReadyCount > 0 ? 'ready_for_sync' : (probeReadyCount > 0 ? 'ready_for_session_probe' : 'missing_inputs')));
    rows.push(...steps);
    return {
      platform,
      target_date_rows: Number(platformPayload?.target_date_rows || 0),
      traffic_rows: Number(gate.traffic_rows || 0),
      target_date_traffic_system_hotel_ids: targetDateTrafficSystemHotelIds,
      target_date_traffic_system_hotel_row_counts: targetDateTrafficSystemHotelRowCounts,
      hotel_step_system_hotel_ids: stepSystemHotelIds,
      target_date_traffic_step_scope_status: scopeStatus,
      target_date_traffic_hotels_missing_steps: targetHotelsMissingSteps,
      step_hotels_missing_target_date_traffic: stepHotelsMissingTargetTraffic,
      target_date_traffic_scope_blocking_reason_codes: targetTrafficScopeBlockingReasonCodes,
      target_date_traffic_scope_reference_reason_codes: targetTrafficScopeReferenceReasonCodes,
      action_entry: !operatorSkipActive && !platformReady && currentSessionReadyCount > 0 ? (gate.action_entry || '') : '',
      action_status: safeActionStatus,
      p0_traffic_gate_status: gate.status || '',
      platform_ready: platformReady,
      hotel_scoped_ready: platformReady && hotelStepIncompleteCount === 0,
      hotel_step_count: steps.length,
      hotel_step_ready_count: hotelStepReadyCount,
      hotel_step_incomplete_count: hotelStepIncompleteCount,
      hotel_step_blocking_reason_codes: hotelStepBlockingReasonCodes,
      profile_flow_ready: profileFlowIncompleteSteps.length === 0 && targetTrafficScopeBlockingReasonCodes.length === 0,
      profile_flow_ready_count: steps.filter((step) => step.profile_flow_ready).length,
      profile_flow_incomplete_count: profileFlowIncompleteSteps.length,
      profile_flow_blocking_reason_codes: profileFlowBlockingReasonCodes,
      missing_inputs: asArray(gate.action_missing_inputs),
      next_step_count: Number(gate.p0_next_step_count || steps.length),
      manual_login_state_verified_count: derivedManualLoginCount,
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
  const hotelScopedReady = platformSummaries.length > 0
    && platformSummaries.every((item) => item.hotel_scoped_ready);
  const operatorSkipped = platformSummaries.some((item) => item.operator_skip_active);
  const reportReady = p0VerifierReady(payload.status)
    && hotelScopedReady
    && !operatorSkipped
    && platformScope.status === 'valid'
    && verifierProcessReady;
  const completionStatus = platformScope.status !== 'valid'
    ? 'invalid_platform_scope'
    : (!verifierProcessReady
      ? 'verifier_process_failed'
      : (reportReady
        ? (payload.status || '')
        : (p0VerifierReady(payload.status) ? 'incomplete_hotel_scoped_steps' : (payload.status || ''))));
  const completionGate = {
    command: scopedVerifierCommand(payload.scope || {}, targetDate),
    required_status: 'ready',
    current_status: completionStatus,
    boundary: 'Completion requires target-date OTA rows and P0 field-loop evidence; this report is not completion proof.',
  };

  return {
    generated_at: new Date().toISOString(),
    source: verifier.source,
    verifier_exit_code: verifier.exitCode ?? null,
    status: payload.status || '',
    inspector_status: payload.inspector_status || '',
    scope: payload.scope || {},
    platform_scope: platformScope,
    summary: payload.summary || {},
    sensitive_values_policy: 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
    collection_policy: buildCollectionPolicy(),
    platform_summaries: platformSummaries,
    next_steps: rows,
    operator_sequence: buildOperatorSequence(rows),
    completion_gate: completionGate,
    collection_flow_gate: buildCollectionFlowGate(platformSummaries),
    downstream_gate: buildDownstreamGate(payload, completionGate, platformSummaries, platformScope, verifier.exitCode),
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
      'active_hotel_scoped_profile_binding',
      'current_session_probe_verified',
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
      if (!step.profile_flow_ready) {
        sequence.push({
          type: 'profile_flow_gap',
          platform: step.platform,
          system_hotel_id: step.system_hotel_id,
          data_source_id: step.data_source_id,
          status: 'profile_flow_unproved',
          blocking_reason_codes: step.profile_flow_blocking_reason_codes,
          required_action: 'Register or repair the hotel-scoped browser Profile traffic data source; do not treat ready target-date rows as reusable login/collection flow proof.',
          boundary: 'P0 target-date rows can be ready while reusable Profile/data-source flow is incomplete.',
        });
      }
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
    if (!['ready', 'not_required'].includes(step.credential_metadata_status)) {
      const metadataBlocked = step.credential_metadata_status === 'blocked';
      const metadataMigration = step.credential_metadata_status === 'migration_required';
      const metadataActionType = metadataBlocked
        ? 'credential_metadata_blocked'
        : (metadataMigration ? 'credential_metadata_migration' : 'credential_metadata_unverified');
      const metadataDefaultReason = metadataBlocked
        ? 'ota_credential_metadata_blocked'
        : (metadataMigration ? 'ota_credential_metadata_migration_required' : 'ota_credential_metadata_unverified');
      sequence.push({
        type: metadataActionType,
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        status: step.credential_metadata_status,
        reason_code: step.credential_metadata_reason || metadataDefaultReason,
        blocking_reason_codes: step.blocking_reason_codes,
        required_action: `${metadataBlocked ? 'Resolve and verify' : (metadataMigration ? 'Migrate and verify' : 'Provide and verify')} the hotel-scoped OTA credential metadata before starting a session probe or data-source sync.`,
        boundary: 'Only credential_metadata_status=ready or not_required permits an operational action; unknown metadata fails closed.',
      });
      sequence.push({
        type: 'single_scope_verifier',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        command: step.verifier_command,
        required_result: 'credential_metadata_status=ready|not_required and status=ready for this platform/hotel traffic gate',
        boundary: `Session probe and sync remain blocked while credential metadata is ${step.credential_metadata_status}.`,
      });
      continue;
    }
    if (step.profile_binding_status !== 'ready') {
      sequence.push({
        type: 'profile_binding_blocked',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        status: step.profile_binding_status,
        reason_code: step.profile_binding_reason || 'profile_binding_unverified',
        blocking_reason_codes: step.profile_flow_blocking_reason_codes,
        boundary: 'The active ota_profile_bindings tuple must match this platform, tenant, and hotel before any session probe or sync action is emitted.',
      });
    } else if (!step.profile_preparation_ready) {
      sequence.push({
        type: 'profile_preparation_blocked',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        status: 'missing_inputs',
        blocking_reason_codes: step.profile_flow_blocking_reason_codes,
        required_action: 'Prepare the Profile directory and platform hotel identity on this same bound data source before starting a session probe or sync.',
        boundary: 'Profile directory, platform identity, binding, and current-session proof cannot be stitched across different sources.',
      });
    } else if (!step.current_session_verified) {
      sequence.push({
        type: 'session_probe',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        entry: step.login_trigger_entry,
        status: 'ready_for_session_probe',
        required_human_action: 'Account owner opens the OTA backend on their own computer and completes the current-session probe, including any captcha/SMS/human verification.',
        sensitive_values_policy: 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
      });
    } else if (step.after_login_sync_entry) {
      sequence.push({
        type: 'after_login_sync',
        platform: step.platform,
        system_hotel_id: step.system_hotel_id,
        data_source_id: step.data_source_id,
        entry: step.after_login_sync_entry,
        requires: 'current_session_probe_performed=true and current_session_verified=true on this data_source_id',
        boundary: 'Run only after the same hotel-scoped source passes the current-session probe; does not bypass OTA controls.',
      });
    }
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

function buildCollectionFlowGate(platformSummaries) {
  const blockers = new Set();
  const blockedPlatforms = [];

  for (const item of asArray(platformSummaries)) {
    const platform = String(item?.platform || '').trim();
    const prefix = platform || 'platform';
    if (item?.target_date_traffic_step_scope_status === 'mismatch') {
      blockers.add(`${prefix}_target_date_traffic_step_scope_mismatch`);
      blockedPlatforms.push(prefix);
    }
    const scopeBlockingCodes = new Set(asArray(item?.target_date_traffic_scope_blocking_reason_codes).map((code) => String(code)));
    for (const code of asArray(item?.target_date_traffic_scope_blocking_reason_codes)) {
      if (code) {
        blockers.add(`${prefix}_${String(code)}`);
        blockedPlatforms.push(prefix);
      }
    }
    if (Number(item?.profile_flow_incomplete_count || 0) > 0) {
      blockers.add(`${prefix}_profile_flow_unproved`);
      blockedPlatforms.push(prefix);
    }
    for (const code of asArray(item?.profile_flow_blocking_reason_codes)) {
      if (scopeBlockingCodes.has(String(code))) continue;
      if (code) blockers.add(String(code));
    }
  }

  return {
    status: blockers.size === 0 ? 'open' : 'blocked_by_profile_flow_gap',
    scope_policy: 'profile_login_and_data_source_flow_separate_from_p0_data_gate',
    blocking_missing_inputs: Array.from(blockers).sort(),
    blocked_platforms: Array.from(new Set(blockedPlatforms)).sort(),
    boundary: 'P0 data evidence can be ready while the reusable Profile/data-source flow is still incomplete for a hotel.',
  };
}

function p0VerifierReady(status) {
  return ['ready', 'passed'].includes(String(status || '').trim());
}

function buildDownstreamGate(payload, completionGate, platformSummaries = [], platformScope = {}, verifierExitCode = null) {
  const status = String(payload?.status || '');
  const blockingMissingInputs = new Set();
  const operatorSkipped = asArray(platformSummaries)
    .filter((item) => item?.operator_skip_active === true)
    .map((item) => String(item.platform || '').trim())
    .filter(Boolean);
  const hotelScopedIncomplete = asArray(platformSummaries)
    .filter((item) => Number(item?.hotel_step_incomplete_count || 0) > 0);
  const isReady = p0VerifierReady(status)
    && platformScope?.status === 'valid'
    && (verifierExitCode == null || verifierExitCode === 0)
    && operatorSkipped.length === 0
    && hotelScopedIncomplete.length === 0;
  const stageDefinitions = [
    ['revenue_analysis', '收益分析'],
    ['ai_decision_advice', 'AI 决策建议'],
    ['operation_closure', '运营闭环'],
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
  if (platformScope?.status !== 'valid') {
    blockingMissingInputs.add('platform_scope_missing_or_mismatch');
  }
  if (verifierExitCode != null && verifierExitCode !== 0) {
    blockingMissingInputs.add('verifier_process_nonzero_exit');
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

  lines.push('', '## Profile Flow Gate', '');
  lines.push(
    `- status: ${report.collection_flow_gate.status}`,
    `- blockers: ${report.collection_flow_gate.blocking_missing_inputs.length ? report.collection_flow_gate.blocking_missing_inputs.join(', ') : '-'}`,
    `- blocked_platforms: ${report.collection_flow_gate.blocked_platforms.length ? report.collection_flow_gate.blocked_platforms.join(', ') : '-'}`,
    `- boundary: ${report.collection_flow_gate.boundary}`,
  );
  for (const item of report.platform_summaries) {
    lines.push(
      `- ${platformLabel(item.platform)}: profile_flow_ready=${item.profile_flow_ready ? 'true' : 'false'}, target_traffic_hotels=${item.target_date_traffic_system_hotel_ids.join(',') || '-'}, step_hotels=${item.hotel_step_system_hotel_ids.join(',') || '-'}, scope_status=${item.target_date_traffic_step_scope_status}, scope_blockers=${item.target_date_traffic_scope_blocking_reason_codes.length ? item.target_date_traffic_scope_blocking_reason_codes.join(',') : '-'}`,
    );
  }

  lines.push('', '## 酒店级执行顺序', '');

  if (report.next_steps.length === 0) {
    lines.push('- 当前 verifier 未暴露酒店级 Profile 步骤。');
  } else {
    report.next_steps.forEach((step, index) => {
      lines.push(
        `${index + 1}. ${platformLabel(step.platform)} system_hotel_id=${step.system_hotel_id} / data_source_id=${step.data_source_id}`,
        `   - 当前状态: source=${step.data_source_status || '-'}, last_sync=${step.last_sync_status || '-'}, manual_login_state_verified=${step.manual_login_state_verified ? 'true' : 'false'}`,
        `   - session_probe: performed=${step.current_session_probe_performed ? 'true' : 'false'}, verified=${step.current_session_verified ? 'true' : 'false'}, status=${step.current_session_status || 'unverified'}, evidence_scope=${step.login_evidence_scope || 'historical_metadata_only'}`,
        `   - profile_binding=${step.profile_binding_status || 'unverified'} / ${step.profile_binding_reason || '-'}`,
        `   - platform_ready=${step.platform_ready ? 'true' : 'false'}`,
        `   - profile_flow_ready=${step.profile_flow_ready ? 'true' : 'false'}, profile_flow_blockers=${step.profile_flow_blocking_reason_codes.length ? step.profile_flow_blocking_reason_codes.join(',') : '-'}`,
        `   - credential_metadata=${step.credential_metadata_status || 'unverified'} / ${step.credential_metadata_reason || '-'}`,
        `   - operator_skip_active=${step.operator_skip_active ? 'true' : 'false'}`,
        `   - 本机授权: ${step.platform_ready ? 'already_ready_no_login' : (step.operator_skip_active && step.manual_login_state_verified ? 'login_verified_reference_only' : (step.login_trigger_entry || '-'))} (${step.login_trigger_status || '-'})`,
        `   - 登录后同步: ${step.platform_ready ? 'already_ready_no_sync' : (step.operator_skip_active ? 'skipped_by_operator_no_sync' : (step.after_login_sync_entry || '-'))}`,
        `   - 复验命令: ${step.verifier_command || '-'}`,
      );
    });
  }

  lines.push('', '## 执行门禁', '');
  for (const item of report.operator_sequence) {
    if (item.type === 'session_probe') {
      lines.push(`- 会话探测: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.entry || '-'} (${item.status || '-'})`);
    } else if (item.type === 'after_login_sync') {
      lines.push(`- 同步: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.entry || '-'}，前置=${item.requires}`);
    } else if (item.type === 'already_ready') {
      lines.push(`- already_ready: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.status}; ${item.boundary}`);
    } else if (item.type === 'profile_flow_gap') {
      lines.push(`- profile_flow_gap: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.status}; blockers=${item.blocking_reason_codes?.length ? item.blocking_reason_codes.join(',') : '-'}; ${item.boundary}`);
    } else if (item.type === 'operator_skip') {
      lines.push(`- operator_skip: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.status}; ${item.boundary}`);
    } else if (['credential_metadata_migration', 'credential_metadata_blocked', 'credential_metadata_unverified', 'profile_binding_blocked', 'profile_preparation_blocked'].includes(item.type)) {
      lines.push(`- ${item.type}: ${platformLabel(item.platform)} system_hotel_id=${item.system_hotel_id} data_source_id=${item.data_source_id} -> ${item.reason_code || item.status}; ${item.boundary}`);
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
