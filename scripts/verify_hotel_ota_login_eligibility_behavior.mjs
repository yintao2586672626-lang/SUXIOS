import { spawnSync } from 'node:child_process';

const php = process.env.PHP_BIN || 'C:\\xampp\\php\\php.exe';
const result = spawnSync(
  php,
  ['scripts\\verify_hotel_ota_login_eligibility.php', '--platform=all', '--format=json'],
  { cwd: process.cwd(), encoding: 'utf8' },
);

if (result.error) {
  console.error(`[hotel-ota-login-eligibility-behavior] failed to start PHP: ${result.error.message}`);
  process.exit(1);
}

if (result.status !== 0) {
  console.error('[hotel-ota-login-eligibility-behavior] verifier exited non-zero');
  console.error(result.stderr || result.stdout);
  process.exit(result.status ?? 1);
}

let report;
try {
  report = JSON.parse(result.stdout);
} catch (error) {
  console.error('[hotel-ota-login-eligibility-behavior] verifier did not emit valid JSON');
  console.error(error.message);
  console.error(result.stdout.slice(0, 1000));
  process.exit(1);
}

const checks = [];
function check(label, ok, detail = '') {
  checks.push({ label, ok: Boolean(ok), detail });
}

const missingHotelResult = spawnSync(
  php,
  ['scripts\\verify_hotel_ota_login_eligibility.php', '--hotel-id=999999999', '--platform=ctrip', '--format=json'],
  { cwd: process.cwd(), encoding: 'utf8' },
);

let missingHotelReport = null;
try {
  missingHotelReport = JSON.parse(missingHotelResult.stdout || '{}');
} catch {
  missingHotelReport = null;
}

const rows = Array.isArray(report.platform_eligibility) ? report.platform_eligibility : [];
const orphans = Array.isArray(report.orphan_sources) ? report.orphan_sources : [];
const strategies = Array.isArray(report.strategy_candidates) ? report.strategy_candidates : [];
const rollups = Array.isArray(report.hotel_rollup) ? report.hotel_rollup : [];

const rowsByStatus = (status) => rows.filter((row) => row.status === status);
const readyForManualLogin = rowsByStatus('ready_for_manual_login');
const readyToCollect = rowsByStatus('ready_to_collect');
const staleTaskRows = rowsByStatus('blocked_stale_running_task');
const inactiveRows = rowsByStatus('blocked_inactive_hotel');
const permissionRows = rowsByStatus('blocked_permission');
const blockedRows = rows.filter((row) => String(row.status || '').startsWith('blocked_'));
const activeBlockedRows = blockedRows.filter((row) => Number(row.hotel_status || 0) === 1);

let strictBlockedResult = null;
if (activeBlockedRows.length > 0) {
  const row = activeBlockedRows[0];
  strictBlockedResult = spawnSync(
    php,
    [
      'scripts\\verify_hotel_ota_login_eligibility.php',
      `--hotel-id=${row.hotel_id}`,
      `--platform=${row.platform}`,
      '--format=json',
      '--strict',
    ],
    { cwd: process.cwd(), encoding: 'utf8' },
  );
}

check('summary keeps OTA-only scope', report.summary?.scope === 'ota_channel_only', report.summary?.scope);
check('summary keeps platform metadata read-only policy', report.summary?.source_policy === 'read_platform_data_sources_metadata_only', report.summary?.source_policy);
check('summary does not expose sensitive values', report.summary?.sensitive_values_exposed === false, String(report.summary?.sensitive_values_exposed));
check('manual login policy does not touch profiles', report.manual_login_policy?.profile_directories_touched === false, String(report.manual_login_policy?.profile_directories_touched));
check('manual login policy does not clear cookies or localStorage', report.manual_login_policy?.cookies_or_local_storage_cleared === false, String(report.manual_login_policy?.cookies_or_local_storage_cleared));
check('summary platform row count matches emitted rows', Number(report.summary?.platform_rows || 0) === rows.length, `${report.summary?.platform_rows || 0} vs ${rows.length}`);
check('summary hotel count matches emitted rollups', Number(report.summary?.hotel_count || 0) === rollups.length, `${report.summary?.hotel_count || 0} vs ${rollups.length}`);
check('summary manual-login count matches emitted rows', Number(report.summary?.ready_for_manual_login_platforms || 0) === readyForManualLogin.length, `${report.summary?.ready_for_manual_login_platforms || 0} vs ${readyForManualLogin.length}`);
check('summary collect-ready count matches emitted rows', Number(report.summary?.ready_to_collect_platforms || 0) === readyToCollect.length, `${report.summary?.ready_to_collect_platforms || 0} vs ${readyToCollect.length}`);
check('summary blocked count matches emitted rows', Number(report.summary?.blocked_platforms || 0) === blockedRows.length, `${report.summary?.blocked_platforms || 0} vs ${blockedRows.length}`);
check('summary strategy candidate count matches emitted rows', Number(report.summary?.strategy_candidate_hotels || 0) === strategies.length, `${report.summary?.strategy_candidate_hotels || 0} vs ${strategies.length}`);
check('summary orphan count matches emitted rows', Number(report.summary?.orphan_source_groups || 0) === orphans.length, `${report.summary?.orphan_source_groups || 0} vs ${orphans.length}`);
check('missing requested hotel exits non-zero', missingHotelResult.status !== 0, String(missingHotelResult.status));
check('missing requested hotel emits hotel_not_found', Array.isArray(missingHotelReport?.issues) && missingHotelReport.issues.some((issue) => issue.code === 'hotel_not_found'), missingHotelResult.stdout.slice(0, 500));
check('missing requested hotel keeps zero platform rows explicit', Number(missingHotelReport?.summary?.platform_rows || 0) === 0, String(missingHotelReport?.summary?.platform_rows));
check('row recheck commands use strict mode', rows.every((row) => String(row.recheck_command || '').includes('--strict')), rows.map((row) => row.recheck_command).slice(0, 3).join(' | '));
check('strict single-store check exits non-zero for active blocked rows', activeBlockedRows.length === 0 || (strictBlockedResult && strictBlockedResult.status !== 0), strictBlockedResult ? String(strictBlockedResult.status) : 'no active blocked rows');

for (const row of readyForManualLogin) {
  check(`manual login row ${row.hotel_id}/${row.platform} is active`, row.hotel_lifecycle_state === 'active', row.hotel_lifecycle_state);
  check(`manual login row ${row.hotel_id}/${row.platform} has no collector task`, row.task_state === 'none', row.task_state);
  check(`manual login row ${row.hotel_id}/${row.platform} has no blockers`, String(row.blockers || '') === '', String(row.blockers || ''));
  check(`manual login row ${row.hotel_id}/${row.platform} has entry URL`, String(row.manual_login_entry || '').startsWith('https://'), String(row.manual_login_entry || ''));
  check(`manual login row ${row.hotel_id}/${row.platform} does not expose verified-login timestamp`, String(row.last_login_verified_at || '') === '', String(row.last_login_verified_at || ''));
}

for (const row of readyToCollect) {
  check(`collect row ${row.hotel_id}/${row.platform} has no manual login URL`, String(row.manual_login_entry || '') === '', String(row.manual_login_entry || ''));
  check(`collect row ${row.hotel_id}/${row.platform} has verified profile`, Number(row.profile_verified_count || 0) > 0, String(row.profile_verified_count || 0));
  check(`collect row ${row.hotel_id}/${row.platform} has login verification timestamp`, String(row.last_login_verified_at || '') !== '', String(row.last_login_verified_at || ''));
  check(`collect row ${row.hotel_id}/${row.platform} has no blockers`, String(row.blockers || '') === '', String(row.blockers || ''));
}

for (const row of blockedRows) {
  check(`blocked row ${row.hotel_id}/${row.platform} has no manual login URL`, String(row.manual_login_entry || '') === '', String(row.manual_login_entry || ''));
}

for (const row of inactiveRows) {
  check(`inactive row ${row.hotel_id}/${row.platform} has inactive lifecycle`, row.hotel_lifecycle_state === 'inactive', row.hotel_lifecycle_state);
  check(`inactive row ${row.hotel_id}/${row.platform} blocks OTA flow`, row.inactive_hotel_blocks_ota_flow === true, String(row.inactive_hotel_blocks_ota_flow));
  check(`inactive row ${row.hotel_id}/${row.platform} suppresses downstream setup`, row.downstream_setup_suppressed === true, String(row.downstream_setup_suppressed));
  check(`inactive row ${row.hotel_id}/${row.platform} primary blocker is lifecycle`, row.primary_blocker === 'inactive_hotel', row.primary_blocker);
  check(`inactive row ${row.hotel_id}/${row.platform} does not suggest permission repair`, !String(row.next_action || '').includes('permission_blocker_reason'), String(row.next_action || ''));
}

for (const row of staleTaskRows) {
  check(`stale task row ${row.hotel_id}/${row.platform} primary blocker is task`, row.primary_blocker === 'sync_task_stale_running', row.primary_blocker);
  check(`stale task row ${row.hotel_id}/${row.platform} lists task blocker first`, String(row.blockers || '').startsWith('sync_task_stale_running'), String(row.blockers || ''));
  check(`stale task row ${row.hotel_id}/${row.platform} has blocking task ids`, String(row.blocking_task_ids || '') !== '', String(row.blocking_task_ids || ''));
  check(`stale task row ${row.hotel_id}/${row.platform} action names blocking task ids`, String(row.next_action || '').includes(`blocking_task_ids=${row.blocking_task_ids}`), String(row.next_action || ''));
}

for (const row of permissionRows) {
  check(`permission row ${row.hotel_id}/${row.platform} is active`, row.hotel_lifecycle_state === 'active', row.hotel_lifecycle_state);
  check(`permission row ${row.hotel_id}/${row.platform} primary blocker is permission`, row.primary_blocker === 'missing_fetch_permission', row.primary_blocker);
  check(`permission row ${row.hotel_id}/${row.platform} action gives permission reason`, String(row.next_action || '').includes('permission_blocker_reason='), String(row.next_action || ''));
}

for (const row of orphans) {
  check(`orphan source ${row.missing_hotel_id}/${row.platform} is excluded from flow`, row.flow_included === false, String(row.flow_included));
  check(`orphan source ${row.missing_hotel_id}/${row.platform} has safe action`, String(row.next_action || '').includes('不得进入 OTA 登录/采集流程'), String(row.next_action || ''));
}

for (const row of strategies) {
  check(`strategy candidate ${row.hotel_id} is confirmation-only`, String(row.confirmation_required || '').includes('candidate only'), String(row.confirmation_required || ''));
  check(`strategy candidate ${row.hotel_id} has explicit available platform`, ['ctrip', 'meituan'].includes(String(row.available_platform || '')), String(row.available_platform || ''));
  check(`strategy candidate ${row.hotel_id} has explicit blocked platform`, ['ctrip', 'meituan'].includes(String(row.blocked_platform || '')), String(row.blocked_platform || ''));
}

const failed = checks.filter((item) => !item.ok);
if (failed.length > 0) {
  console.error('[hotel-ota-login-eligibility-behavior] failed checks:');
  for (const item of failed) {
    console.error(`- ${item.label}: ${item.detail}`);
  }
  process.exit(1);
}

console.log(`hotel OTA login eligibility behavior passed (${checks.length} checks).`);
