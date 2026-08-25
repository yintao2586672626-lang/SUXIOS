import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const authorityComponents = readFileSync('public/components/system/app-main-components.js', 'utf8');

const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.notEqual(start, -1, `missing start marker: ${startMarker}`);
  assert.notEqual(end, -1, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};

test('passive dashboard entry remains read-only and never schedules OTA collection', () => {
  assert.doesNotMatch(appMain, /scheduleDualOtaWorkbenchAutoFetch/);

  const loginActivation = sliceBetween(
    appMain,
    'const activateCoreOperationsAfterLogin = () => {',
    'const isVisibleOnlineDataTab = isOnlineDataTabVisible;'
  );
  assert.match(loginActivation, /loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)/);
  assert.doesNotMatch(loginActivation, /triggerAutoFetch|allowFetch:\s*true/);

  const pageWatcher = sliceBetween(
    appMain,
    'watch(currentPage, (newPage) => {',
    'watch(isLoggedIn, (loggedIn) => {'
  );
  assert.match(pageWatcher, /loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)/);
  assert.doesNotMatch(pageWatcher, /triggerAutoFetch|allowFetch:\s*true/);

  assert.match(appMain, /\/\/ 手动触发自动获取\s+const triggerAutoFetch = async/);
  assert.match(appMain, /refreshDualOtaWorkbenchData\(\{ allowFetch: true, silent: false \}\)/);
});

test('operating-loop authority uses the selected hotel name and localized result status', () => {
  assert.match(authorityComponents, /typeof ctx\.getHotelNameById === 'function'/);
  assert.match(authorityComponents, /pending: '待回读'/);
  assert.match(authorityComponents, /supported: '已验证达到'/);
  assert.match(authorityComponents, /answer\('昨天动作有没有结果', yesterdayResultStatusLabel,/);
  assert.doesNotMatch(authorityComponents, /answer\('昨天动作有没有结果', result\.status \|\| 'pending'/);
});

test('dashboard never fabricates a local mock execution record', () => {
  assert.doesNotMatch(appMain, /recordDualOtaExecution/);
  assert.doesNotMatch(appMain, /mock-execution|mock 复盘记忆|执行记录已写入复盘记忆库（mock）/);
});
