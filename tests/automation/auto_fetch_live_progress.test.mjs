import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const autoFetchStaticSource = readFileSync('public/auto-fetch-static.js', 'utf8');
const html = readFrontendContractSource();
const panels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const autoFetchConcern = readFileSync('app/controller/concern/AutoFetchConcern.php', 'utf8');

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
