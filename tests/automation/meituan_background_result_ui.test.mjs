import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const meituanStatic = readFileSync('public/meituan-static.js', 'utf8');

const sliceFrom = (source, needle, endNeedle) => {
  const start = source.indexOf(needle);
  assert.ok(start >= 0, `missing start marker: ${needle}`);
  const end = endNeedle ? source.indexOf(endNeedle, start) : -1;
  return end > start ? source.slice(start, end) : source.slice(start);
};

test('Meituan ranking fetch uses a vault locator in direct mode and keeps truthful background-response compatibility', () => {
  const resultPanel = sliceFrom(html, '<!-- 获取结果显示 -->', '<!-- 原始JSON数据 -->');
  const taskBuilder = sliceFrom(meituanStatic, 'const buildMeituanBatchFetchTasks = ({', 'const buildMeituanBatchFetchResultEntry');
  const fetchFlow = sliceFrom(meituanStatic, 'const runMeituanBatchFetchFlow = async ({', 'const buildMeituanRankDisplayRows');
  const pendingSetup = sliceFrom(meituanStatic, 'const results = fetchTasks.map', 'let totalSavedCount = 0;');
  const acceptedLoopUpdate = sliceFrom(meituanStatic, 'const bestEntry = buildMeituanBatchFetchResultEntry', '            }));');
  const acceptedBranch = sliceFrom(meituanStatic, 'if (acceptedCount > 0) {', 'const modelRes = await requestDisplayModel');
  const failedBranch = sliceFrom(meituanStatic, 'if (fetchTasks.length > 0 && failedCount === fetchTasks.length) {', 'const modelRes = await requestDisplayModel');

  assert.match(meituanStatic, /const buildMeituanBatchFetchPendingEntry = \(task\) => \(\{/);
  assert.match(meituanStatic, /status: 'fetching'/);
  assert.match(meituanStatic, /const isMeituanRankingFormAlignedWithConfig = \(form = \{\}, config = \{\}\) => \{/);
  assert.match(fetchFlow, /let form = getForm\(\) \|\| \{\};/);
  assert.match(fetchFlow, /if \(!isMeituanRankingFormAlignedWithConfig\(form, selectedMeituanConfig\)\) \{/);
  assert.match(fetchFlow, /skipIfAligned: true/);
  assert.match(fetchFlow, /form = getForm\(\) \|\| form;/);
  assert.doesNotMatch(fetchFlow, /await applyMeituanHotelConfig\(false, \{ resolvedConfig: selectedMeituanConfig, refreshList: false \}\);/);
  assert.match(taskBuilder, /config_id: String\(configId \|\| ''\)\.trim\(\)/);
  assert.match(taskBuilder, /system_hotel_id: form\.hotelId/);
  assert.doesNotMatch(taskBuilder, /\b(?:cookies?|auth_data|authorization|token|spidertoken|mtgsig|headers)\s*:/i);
  assert.match(fetchFlow, /await Promise\.all\(fetchTasks\.map\(async \(task, index\) => \{/);
  assert.match(fetchFlow, /const requestBody = \{ \.\.\.task\.body, async: false, background: false \};/);
  assert.doesNotMatch(fetchFlow, /const requestBody = \{ \.\.\.task\.body, async: true, background: true \};/);
  assert.doesNotMatch(fetchFlow, /setHotelsList\(\[\]\);/);
  assert.doesNotMatch(pendingSetup, /setBusinessSummary\(getEmptyBusinessSummary\(\)\);/);
  assert.match(failedBranch, /setBusinessSummary\(getEmptyBusinessSummary\(\)\);/);
  assert.match(failedBranch, /return \{ status: loginFailed \? 'login_required' : 'failed'/);
  assert.match(pendingSetup, /setOnlineDataResult\(\[\.\.\.results\]\);/);
  assert.doesNotMatch(pendingSetup, /setFetchSuccess\(true\);/);

  assert.match(meituanStatic, /const isMeituanPendingResult = \(result = \{\}\)/);
  assert.match(meituanStatic, /const isMeituanBackgroundResult = \(result = \{\}\)/);
  assert.match(html, /const isMeituanPendingResult = requireMeituanStatic\('isMeituanPendingResult'\);/);
  assert.match(html, /const isMeituanBackgroundResult = requireMeituanStatic\('isMeituanBackgroundResult'\);/);
  assert.match(html, /const meituanFetchInProgress = computed/);
  assert.match(html, /const meituanFetchBackgroundAccepted = computed/);
  assert.match(resultPanel, /meituanFetchSuccess \|\| meituanFetchInProgress \|\| meituanFetchBackgroundAccepted/);
  assert.match(resultPanel, /meituanFetchBackgroundAccepted \? '美团手动获取已提交后台执行'/);
  assert.match(resultPanel, /isMeituanPendingResult\(result\)/);
  assert.match(resultPanel, /isMeituanBackgroundResult\(result\)/);
  assert.match(resultPanel, /后台执行中/);

  assert.match(meituanStatic, /message: response\.message \|\| ''/);
  assert.match(meituanStatic, /taskId: responseData\.task_id \|\| ''/);
  assert.match(meituanStatic, /status: responseData\.status \|\| responseStatus \|\| 'running'/);
  assert.match(meituanStatic, /return \['accepted', 'running', 'queued'\]\.includes\(status\);/);
  assert.match(fetchFlow, /const scheduleResultUpdate = \(\) => \{/);
  assert.match(fetchFlow, /requestAnimationFrame\(commit\)/);
  assert.match(fetchFlow, /const flushResultUpdate = \(\) => \{/);
  assert.match(acceptedLoopUpdate, /scheduleResultUpdate\(\);/);
  assert.match(fetchFlow, /flushResultUpdate\(\);/);
  assert.match(acceptedBranch, /notify\(/);
  assert.match(acceptedBranch, /'info'/);
  assert.match(acceptedBranch, /runPostFetchRefresh\(refreshOnlineHistory\)/);
  assert.match(acceptedBranch, /refreshOnlineData\(\)/);
  assert.match(acceptedBranch, /return \{ status: 'accepted', results, acceptedCount, totalSavedCount \};/);
  assert.doesNotMatch(acceptedBranch, /unexpected_background/);
});

test('Meituan ranking production flow commits deferred candidates through the authenticated endpoint', () => {
  const productionFlow = sliceFrom(html, 'const fetchMeituanData = async () => {', 'const useCtripTrafficDisplayRows');

  assert.match(
    productionFlow,
    /requestCommit:\s*body\s*=>\s*request\('\/online-data\/meituan\/rank-candidates\/commit'/
  );
});

test('Meituan production flow invalidates stale runs when the hotel changes', () => {
  const productionFlow = sliceFrom(html, 'const fetchMeituanData = async () => {', 'const useCtripTrafficDisplayRows');
  const hotelWatcher = sliceFrom(html, 'watch(() => meituanForm.value.hotelId, () => {', 'watch(() => meituanForm.value.dateRanges');

  assert.match(html, /let meituanFetchRunToken = 0;/);
  assert.match(productionFlow, /const runToken = \+\+meituanFetchRunToken;/);
  assert.match(productionFlow, /const isActive = \(\) => runToken === meituanFetchRunToken;/);
  assert.match(productionFlow, /isActive,/);
  assert.match(productionFlow, /if \(preparingConfig && isActive\(\)\)/);
  assert.match(hotelWatcher, /meituanFetchRunToken \+= 1;/);
});

test('Meituan ranking candidate flow ships with a fresh browser cache key', () => {
  assert.match(
    html,
    /meituan-static\.js\?v=20260712-meituan-truthful-partial-h[a-f0-9]{10}/
  );
});
