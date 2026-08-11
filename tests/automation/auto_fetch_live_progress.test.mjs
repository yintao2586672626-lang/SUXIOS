import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const autoFetchStaticSource = readFileSync('public/auto-fetch-static.js', 'utf8');
const appMainSource = readFileSync('public/app-main.js', 'utf8');
const html = readFrontendContractSource();
const panels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const autoFetchConcern = readSourceAggregate('app/controller/concern/AutoFetchConcern.php');

const sandbox = { console, Promise, window: {} };
vm.runInNewContext(
  `${autoFetchStaticSource}\nthis.__autoFetchStatic = window.SUXI_AUTO_FETCH_STATIC;`,
  sandbox,
);
const autoFetchStatic = sandbox.__autoFetchStatic;

const slice = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  assert.ok(start >= 0, `missing start marker: ${startNeedle}`);
  const end = endNeedle ? source.indexOf(endNeedle, start) : -1;
  return end > start ? source.slice(start, end) : source.slice(start);
};

const createAutoFetchStatusHarness = () => {
  const state = { hotelId: 'A', epoch: 1, token: 'session-1' };
  const requests = [];
  const errors = [];
  const syncMarkers = [];
  const refs = {
    autoFetchEnabled: { value: false },
    autoFetchStatus: { value: {} },
    autoFetchScheduleTime: { value: '' },
    autoFetchScheduleMinute: { value: 0 },
    autoFetchRealtimeIntervalHours: { value: 0 },
    autoFetchBrowserHeadless: { value: true },
    autoFetchCtripSectionConcurrency: { value: 0 },
    autoFetchMode: { value: '' },
    autoFetchBackfillDate: { value: '' },
    autoFetchMaxBackfillDate: { value: '2026-08-09' },
  };
  const loaderSource = slice(
    appMainSource,
    'const autoFetchStatusRequestPromises = new Map();',
    'const platformProfileStatusRequestPromises = new Map();',
  );
  const sandbox = {
    URLSearchParams,
    AUTO_FETCH_PANEL_CACHE_TTL_MS: 45_000,
    ...refs,
    console: {
      error: (...args) => errors.push(args),
    },
    getAutoFetchHotelId: () => state.hotelId,
    captureAuthSession: () => ({ epoch: state.epoch, token: state.token }),
    isAuthSessionCurrent: session => Number(session?.epoch) === state.epoch
      && String(session?.token || '') === state.token,
    request: url => new Promise((resolve, reject) => {
      const parsed = new URL(url, 'http://localhost');
      requests.push({
        url,
        hotelId: parsed.searchParams.get('hotel_id') || '',
        includeDetail: parsed.searchParams.get('include_detail') !== '0',
        resolve,
        reject,
      });
    }),
    syncAutoFetchRunStateFromStatus: status => syncMarkers.push(status?.marker || ''),
  };

  vm.runInNewContext(
    `${loaderSource}\nthis.__autoFetchStatusHarness = { loadAutoFetchStatus, autoFetchStatusRequestPromises, autoFetchStatusResultCache };`,
    sandbox,
    { filename: 'public/app-main.js#loadAutoFetchStatus' },
  );

  return {
    ...sandbox.__autoFetchStatusHarness,
    state,
    requests,
    errors,
    refs,
    syncMarkers,
    setHotel: hotelId => { state.hotelId = hotelId; },
    setSession: (epoch, token) => {
      state.epoch = epoch;
      state.token = token;
    },
  };
};

const autoFetchStatusResponse = (marker, overrides = {}) => ({
  code: 200,
  data: {
    marker,
    enabled: true,
    detail_loaded: true,
    historical_schedule_time: '08:30',
    realtime_schedule_minute: 5,
    realtime_schedule_interval_hours: 2,
    browser_headless: true,
    ctrip_section_concurrency: 3,
    auto_fetch_mode: 'hybrid_auto',
    missed_dates: [],
    failed_records: [],
    platforms: {},
    ...overrides,
  },
});

test('accepted background auto-fetch keeps the live timer active and starts progress monitoring', async () => {
  const events = [];
  const result = await autoFetchStatic.runAutoFetchTriggerFlow({
    getHotelId: () => '80',
    hasPlatformFetchConfig: () => true,
    setFetching: value => events.push(['fetching', value]),
    startTimer: startedAt => events.push(['start-timer', startedAt]),
    stopTimer: () => events.push(['stop-timer']),
    startMonitor: context => events.push(['start-monitor', context]),
    getTimestamp: () => '2026-07-11 20:32:18',
    getCtripExecutionText: () => '携程板块 3 页并发',
    buildModePayload: () => ({ meituan_auto_fetch_mode: 'profile_browser' }),
    modeLabel: () => '浏览器 Profile',
    getCtripSectionConcurrency: () => 3,
    requestAutoFetch: async () => ({
      code: 200,
      message: '自动获取已提交后台执行',
      data: { status: 'accepted', task_id: 'auto_fetch_80_test' },
    }),
  });
  await Promise.resolve();

  assert.equal(result.status, 'accepted');
  assert.deepEqual(events[0], ['fetching', true]);
  assert.deepEqual(events[1], ['start-timer', '2026-07-11 20:32:18']);
  assert.equal(events.some(([name]) => name === 'start-monitor'), true);
  assert.equal(events.some(([name]) => name === 'stop-timer'), false);
  assert.equal(events.some(([name, value]) => name === 'fetching' && value === false), false);
});

test('terminal synchronous auto-fetch still releases the timer and loading state', async () => {
  const events = [];
  const result = await autoFetchStatic.runAutoFetchTriggerFlow({
    getHotelId: () => '80',
    hasPlatformFetchConfig: () => true,
    setFetching: value => events.push(['fetching', value]),
    startTimer: startedAt => events.push(['start-timer', startedAt]),
    stopTimer: () => events.push(['stop-timer']),
    getTimestamp: () => '2026-07-11 20:32:18',
    getCtripExecutionText: () => '携程板块 3 页并发',
    buildModePayload: () => ({}),
    getCtripSectionConcurrency: () => 3,
    requestAutoFetch: async () => ({ code: 200, data: { status: 'success', saved_count: 1 } }),
  });

  assert.equal(result.status, 'success');
  assert.equal(events.some(([name]) => name === 'stop-timer'), true);
  assert.equal(events.some(([name, value]) => name === 'fetching' && value === false), true);
});

test('automatic collection panel restores backend timing, polls progress, and loads saved Profile state', () => {
  const timerBlock = slice(
    html,
    'const startAutoFetchRunTimer =',
    'const autoFetchMaxBackfillDate',
  );
  const panelLoader = slice(
    html,
    'const loadAutoFetchPanel = async (options = {}) => {',
    'const autoFetchStatusRequestPromises',
  );
  const statusLoader = slice(
    html,
    'const loadAutoFetchStatus = async (options = {}) => {',
    'const platformProfileStatusRequestPromises',
  );

  assert.match(timerBlock, /const startAutoFetchRunTimer = \(startedAt = ''\) =>/);
  assert.match(html, /Date\.now\(\) - autoFetchRunStartedAtMs/);
  assert.match(html, /const startAutoFetchProgressMonitor = \(context = \{\}\) =>/);
  assert.match(html, /const syncAutoFetchRunStateFromStatus = \(status = autoFetchStatus\.value\) =>/);
  assert.match(statusLoader, /syncAutoFetchRunStateFromStatus\(autoFetchStatus\.value\)/);
  assert.match(panelLoader, /loadPlatformProfileStatus\(\{\s*silent: true,/);
  assert.match(html, /if \(autoFetchRunState\.value\.active\) return \[\];/);
  assert.match(panels, /ctx\.autoFetchPlatformProgressRows/);
});

test('canonical daily operation status is a separate exact-readback receipt, not collection success', () => {
  const verified = autoFetchStatic.buildCanonicalDailyOperationStatus({
    success: true,
    trust_receipt: {
      canonical_operation_finalization: {
        status: 'verified',
        analysis_status: 'verified',
        stage: 'completed',
        scope: {
          tenant_id: 80,
          hotel_id: 80,
          data_source_id: 25,
          task_id: 3096,
          row_id: 81818,
          platform: 'ctrip',
          target_date: '2026-08-08',
          data_period: 'historical_daily',
        },
        draft_count: 4,
        trusted_operational_check_count: 4,
        trusted_external_operation_count: 0,
        draft_readback_verified: true,
        db_readback_verified: true,
        operation_flow_readback_verified: true,
        external_action_triggered: false,
        business_outcome_claimed: false,
        causality_claimed: false,
      },
    },
  });

  assert.deepEqual(
    { visible: verified.visible, status: verified.status, scope: verified.scope_text },
    { visible: true, status: 'verified', scope: '酒店 #80 · 2026-08-08 · 渠道 携程 · 来源 #25 · 任务 #3096 · 源行 #81818' },
  );
  assert.match(verified.status_text, /4/);
  assert.match(verified.boundary_text, /不触发 OTA\/外部动作/);

  const meituanVerified = autoFetchStatic.buildCanonicalDailyOperationStatus({
    trust_receipt: {
      canonical_operation_finalization: {
        ...verified,
        status: 'verified',
        analysis_status: 'verified',
        scope: {
          tenant_id: 80,
          hotel_id: 80,
          data_source_id: 68,
          task_id: 3105,
          row_id: 81866,
          platform: 'meituan',
          target_date: '2026-08-08',
          data_period: 'historical_daily',
        },
        draft_count: 4,
        trusted_operational_check_count: 4,
        trusted_external_operation_count: 0,
        draft_readback_verified: true,
        db_readback_verified: true,
        operation_flow_readback_verified: true,
        external_action_triggered: false,
        business_outcome_claimed: false,
        causality_claimed: false,
      },
    },
  });
  assert.equal(meituanVerified.status, 'verified');
  assert.match(meituanVerified.scope_text, /渠道 美团/);
  assert.match(meituanVerified.boundary_text, /仅当前 OTA 渠道分析/);
  assert.doesNotMatch(meituanVerified.boundary_text, /仅携程/);

  const blocked = autoFetchStatic.buildCanonicalDailyOperationStatus({
    success: true,
    trust_receipt: {
      canonical_operation_finalization: {
        status: 'blocked',
        stage: 'action_persist',
        reason: 'canonical_daily_operation_draft_saved_action_blocked',
        scope: {
          tenant_id: 80,
          hotel_id: 80,
          data_source_id: 25,
          task_id: 3096,
          row_id: 81818,
          platform: 'ctrip',
          target_date: '2026-08-08',
          data_period: 'historical_daily',
        },
      },
    },
  });

  assert.equal(blocked.status, 'blocked');
  assert.equal(blocked.stage, 'action_persist');
  assert.equal(blocked.reason, 'canonical_daily_operation_draft_saved_action_blocked');
  assert.match(blocked.status_text, /0\/4/);
  assert.match(panels, /data-testid="canonical-daily-operation-status"/);
  assert.match(panels, /ctx\.autoFetchCanonicalOperationStatus/);
});

test('natural daily acceptance reports 0/3, daily verified, and 3/3 without borrowing collection success', () => {
  const verifiedReceipt = {
    schema_version: 'suxios_ota_daily_natural_acceptance.v1',
    receipt_available: true,
    receipt_readback_verified: true,
    status: 'verified',
    stage: 'stability',
    reason_codes: [],
    hotel_id: 80,
    target_date: '2026-08-09',
    expected_target_date: '2026-08-09',
    freshness_status: 'current',
    data_period: 'historical_daily',
    expected_platforms: ['ctrip', 'meituan'],
    natural_dispatch_status: 'verified',
    collection_status: 'verified',
    continuous_trust_status: 'verified',
    operations_status: 'verified',
    selected_platform: 'meituan',
    operation_scope: {
      tenant_id: 80,
      hotel_id: 80,
      data_source_id: 68,
      task_id: 3105,
      row_id: 81866,
      platform: 'meituan',
      target_date: '2026-08-09',
      data_period: 'historical_daily',
    },
    action_types: [
      'meituan_list_detail_count_order_check',
      'meituan_list_detail_rate_check',
      'meituan_observed_flow_rate_alignment_check',
      'same_scope_recollection_eligibility_check',
    ],
    trusted_analysis_check_count: 4,
    trusted_external_operation_count: 0,
    analysis_only: true,
    operation_readback_verified: true,
    external_action_triggered: false,
    business_outcome_claimed: false,
    causality_claimed: false,
    sensitive_values_exposed: false,
    stability: {
      status: 'collecting_evidence',
      consecutive_verified_natural_days: 1,
      required_days: 3,
      stable: false,
      dates: ['2026-08-09'],
    },
  };
  const verified = autoFetchStatic.buildNaturalDailyAcceptanceStatus(verifiedReceipt);

  assert.equal(verified.visible, true);
  assert.equal(verified.status, 'verified');
  assert.equal(verified.status_text, '当日通过 1/3');
  assert.match(verified.scope_text, /酒店 #80/);
  assert.match(verified.operation_text, /美团.*4 条 analysis-only.*外部动作 0 条/);
  assert.match(verified.operation_text, /列表与详情量级核查.*观测流量转化率对齐核查/);
  assert.match(verified.operation_scope_text, /来源 #68.*任务 #3105.*源行 #81866/);
  assert.match(verified.boundary_text, /独立于“最近采集成功”/);

  const stable = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...verifiedReceipt,
    stability: {
      status: 'stable',
      consecutive_verified_natural_days: 3,
      required_days: 3,
      stable: true,
      dates: ['2026-08-07', '2026-08-08', '2026-08-09'],
    },
  });
  assert.equal(stable.status, 'stable');
  assert.equal(stable.status_text, '连续稳定 3/3');

  const staleStable = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...verifiedReceipt,
    expected_target_date: '2026-08-10',
    freshness_status: 'stale',
    stability: {
      status: 'stable',
      consecutive_verified_natural_days: 3,
      required_days: 3,
      stable: true,
      dates: ['2026-08-07', '2026-08-08', '2026-08-09'],
    },
  });
  assert.equal(staleStable.status, 'blocked');
  assert.notEqual(staleStable.status_text, '连续稳定 3/3');

  const nonConsecutive = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...verifiedReceipt,
    stability: {
      status: 'stable',
      consecutive_verified_natural_days: 3,
      required_days: 3,
      stable: true,
      dates: ['2026-08-05', '2026-08-07', '2026-08-09'],
    },
  });
  assert.equal(nonConsecutive.status, 'verified');
  assert.notEqual(nonConsecutive.status_text, '连续稳定 3/3');

  const blockedDespiteCollectionSuccess = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...verifiedReceipt,
    status: 'blocked',
    stage: 'collection',
    reason_codes: ['daily_trust_receipt_not_ready', 'dual_ota_continuous_trust_not_ready'],
    collection_status: 'blocked',
    trusted_analysis_check_count: 0,
    analysis_only: false,
    operation_readback_verified: false,
    stability: {
      status: 'collecting_evidence',
      consecutive_verified_natural_days: 0,
      required_days: 3,
      stable: false,
      dates: [],
    },
  });
  assert.equal(blockedDespiteCollectionSuccess.status, 'blocked');
  assert.equal(blockedDespiteCollectionSuccess.status_text, '本日阻塞 0/3');
  assert.match(blockedDespiteCollectionSuccess.reason_text, /采集、保存或严格回读/);
  assert.match(blockedDespiteCollectionSuccess.reason_details_text, /携程与美团当日可信数据尚未同时通过/);
  assert.doesNotMatch(blockedDespiteCollectionSuccess.operation_text, /已固定/);

  const databasePreflightBlocked = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...blockedDespiteCollectionSuccess,
    reason_codes: ['dispatcher_database_preflight_blocked'],
  });
  assert.equal(databasePreflightBlocked.status, 'blocked');
  assert.match(databasePreflightBlocked.reason_text, /数据库预检/);

  const noEvidence = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    schema_version: 'suxios_ota_daily_natural_acceptance.v1',
    receipt_available: false,
    receipt_readback_verified: false,
    status: 'no_evidence',
    stage: 'natural_dispatch',
    reason_codes: ['natural_dispatch_receipt_missing'],
    hotel_id: 80,
    target_date: '',
    data_period: 'historical_daily',
    stability: {
      consecutive_verified_natural_days: 0,
      required_days: 3,
      stable: false,
      dates: [],
    },
  });
  assert.equal(noEvidence.status, 'no_evidence');
  assert.equal(noEvidence.status_text, '等待首日 0/3');
  assert.match(noEvidence.reason_text, /首次自然触发/);

  const staleNoEvidence = autoFetchStatic.buildNaturalDailyAcceptanceStatus({
    ...noEvidence,
    target_date: '2026-08-10',
    expected_target_date: '2026-08-10',
    latest_observed_target_date: '2026-08-09',
    freshness_status: 'stale',
    stage: 'freshness',
    reason_codes: ['latest_natural_business_date_missing'],
  });
  assert.equal(staleNoEvidence.status, 'no_evidence');
  assert.match(staleNoEvidence.reason_text, /上海时区昨日/);

  assert.match(autoFetchConcern, /'natural_daily_acceptance' => \$this->naturalDailyAcceptanceStatus\(null\)/);
  assert.match(autoFetchConcern, /\$status\['natural_daily_acceptance'\] = \$this->naturalDailyAcceptanceStatus\(/);
  assert.match(panels, /data-testid="natural-daily-acceptance-status"/);
  assert.match(panels, /builder\(this\.ctx\?\.autoFetchStatus\?\.natural_daily_acceptance\)/);
  assert.match(panels, /最近一次单次核查（不计入自然稳定性）/);
  assert.doesNotMatch(
    panels,
    /ctx\.autoFetchRunState\.active \|\| ctx\.autoFetchRunState\.message \|\| ctx\.autoFetchStatus\?\.last_result \|\| naturalDailyAcceptanceStatus/,
  );
});

test('backend publishes truthful per-platform stages while a background task is running', () => {
  assert.match(autoFetchConcern, /private function updateAutoFetchRunningPlatformProgress\(/);
  assert.match(autoFetchConcern, /'platforms' => \[/);
  assert.match(autoFetchConcern, /updateAutoFetchRunningPlatformProgress\(\$hotelId, 'ctrip', 'running'/);
  assert.match(autoFetchConcern, /updateAutoFetchRunningPlatformProgress\(\$hotelId, 'meituan', 'running'/);
  assert.match(autoFetchConcern, /'saved_count' => \(int\)\(\$result\['saved_count'\] \?\? 0\)/);
});

test('auto-fetch result copy labels saved_count as write operations instead of unique facts', () => {
  assert.match(panels, /写入操作 \{\{ row\.saved_count \|\| 0 \}\} 次/);
  assert.doesNotMatch(panels, /入库 \{\{ row\.saved_count \|\| 0 \}\} 条/);
  assert.match(html, /const autoFetchResultMessage = \(message, savedCount = 0\) =>/);
  assert.match(autoFetchConcern, /完成 \{\$savedCount\} 次写入并验证本次任务核心指标回执/);
  assert.match(autoFetchConcern, /已发生 \{\$savedCount\} 次写入，但本次任务、入库行、来源追踪与收入\/间夜\/ADR 回执未完整绑定/);
  assert.doesNotMatch(autoFetchStaticSource, /采集完成并入库 \$\{res\.data\?\.saved_count \|\| 0\} 条 OTA 指标行/);
});

test('automatic collection remembers the selected hotel across page reloads', () => {
  assert.match(html, /const AUTO_FETCH_HOTEL_STORAGE_KEY = 'suxios_auto_fetch_hotel_id_v1';/);
  assert.match(html, /const autoFetchHotelId = ref\(readStoredAutoFetchHotelId\(\)\);/);
  assert.match(html, /watch\(autoFetchHotelId, value => \{/);
  assert.match(html, /localStorage\.setItem\(AUTO_FETCH_HOTEL_STORAGE_KEY, normalized\)/);
  assert.doesNotMatch(html, /alignCtripTargetHotelToAccountPrimary\(\{ syncAutoFetch: true \}\)/);
});

test('Profile status cache is hotel-scoped and stale hotel responses cannot overwrite the selection', () => {
  const profileStatusLoader = slice(
    html,
    'const loadPlatformProfileStatus = async (options = {}) => {',
    'const rawPlatformProfileLoginTask =',
  );

  assert.match(profileStatusLoader, /const requestSession = captureAuthSession\(\);/);
  assert.match(profileStatusLoader, /const requestHotelId = String\(hotelId \|\| ''\);/);
  assert.match(profileStatusLoader, /const requestKey = `\$\{requestSession\.epoch\}:\$\{requestHotelId\}`;/);
  assert.match(profileStatusLoader, /const isCurrentHotel = \(\) => isAuthSessionCurrent\(requestSession\)\s*&& String\(getAutoFetchHotelId\(\) \|\| ''\) === requestHotelId;/);
  assert.match(profileStatusLoader, /cached\.data/);
  assert.match(profileStatusLoader, /platformProfileStatus\.value = cached\.data/);
  assert.match(profileStatusLoader, /platformProfileStatusResultCache\.set\(requestKey, \{\s*expiresAt: Date\.now\(\) \+ cacheMs,\s*data: nextStatus,/);
  assert.match(profileStatusLoader, /if \(!isCurrentHotel\(\)\) return;/);
});

test('auto-fetch status keeps B selected when the older A response finishes last', async () => {
  const harness = createAutoFetchStatusHarness();

  const initialA = harness.loadAutoFetchStatus({ detail: true });
  harness.requests[0].resolve(autoFetchStatusResponse('A-full', {
    missed_dates: ['2026-08-08'],
    missed_count: 1,
    has_config: true,
    platforms: { ctrip: { configured: true } },
  }));
  await initialA;

  const slowA = harness.loadAutoFetchStatus({ detail: false, force: true });
  const duplicateA = harness.loadAutoFetchStatus({ detail: false });
  assert.equal(harness.requests.length, 2, 'same-hotel light requests must share one in-flight request');

  harness.setHotel('B');
  const fastB = harness.loadAutoFetchStatus({ detail: false });
  assert.equal(harness.requests.length, 3);
  harness.requests[2].resolve(autoFetchStatusResponse('B-light', {
    enabled: false,
    detail_loaded: false,
    historical_schedule_time: '09:45',
    missed_count: null,
    has_config: false,
    platforms: { meituan: { configured: false } },
  }));
  await fastB;

  assert.equal(harness.refs.autoFetchStatus.value.marker, 'B-light');
  assert.equal(harness.refs.autoFetchEnabled.value, false);
  assert.equal(harness.refs.autoFetchScheduleTime.value, '09:45');

  harness.requests[1].resolve(autoFetchStatusResponse('A-light', {
    detail_loaded: false,
    historical_schedule_time: '07:15',
    missed_count: null,
    has_config: true,
    platforms: {},
  }));
  await Promise.all([slowA, duplicateA]);

  assert.equal(harness.refs.autoFetchStatus.value.marker, 'B-light');
  assert.deepEqual(Array.from(harness.refs.autoFetchStatus.value.missed_dates), []);
  assert.equal(harness.refs.autoFetchEnabled.value, false);
  assert.equal(harness.refs.autoFetchScheduleTime.value, '09:45');
  assert.equal(harness.syncMarkers[harness.syncMarkers.length - 1], 'B-light');

  const requestCountBeforeCacheHit = harness.requests.length;
  harness.setHotel('A');
  await harness.loadAutoFetchStatus({ detail: false });
  assert.equal(harness.requests.length, requestCountBeforeCacheHit, 'A cache hit must not issue another request');
  assert.equal(harness.refs.autoFetchStatus.value.marker, 'A-light');
  assert.deepEqual(Array.from(harness.refs.autoFetchStatus.value.missed_dates), ['2026-08-08']);
  assert.equal(harness.refs.autoFetchScheduleTime.value, '07:15');
});

test('stale-session catch and finally preserve the newer same-hotel request', async () => {
  const harness = createAutoFetchStatusHarness();
  const oldSessionRequest = harness.loadAutoFetchStatus({ detail: true });

  harness.setSession(2, 'session-2');
  const currentSessionRequest = harness.loadAutoFetchStatus({ detail: true });
  assert.equal(harness.requests.length, 2, 'a new session must not reuse the old session request');

  harness.requests[0].reject(new Error('old session failed'));
  await oldSessionRequest;
  assert.equal(harness.errors.length, 0, 'a stale-session failure must not mutate the current error channel');

  const duplicateCurrentRequest = harness.loadAutoFetchStatus({ detail: true });
  assert.equal(harness.requests.length, 2, 'stale finally must not clear the current session loading entry');

  harness.requests[1].resolve(autoFetchStatusResponse('A-session-2'));
  await Promise.all([currentSessionRequest, duplicateCurrentRequest]);
  assert.equal(harness.refs.autoFetchStatus.value.marker, 'A-session-2');
});
