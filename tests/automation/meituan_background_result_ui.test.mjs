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

test('Meituan ranking fetch submits quickly and shows visible background progress', () => {
  const resultPanel = sliceFrom(html, '<!-- 获取结果显示 -->', '<!-- 原始JSON数据 -->');
  const fetchFlow = sliceFrom(meituanStatic, 'const runMeituanBatchFetchFlow = async ({', 'const buildMeituanRankDisplayRows');
  const pendingSetup = sliceFrom(meituanStatic, 'const results = fetchTasks.map', 'let totalSavedCount = 0;');
  const acceptedLoopUpdate = sliceFrom(meituanStatic, 'results[index] = buildMeituanBatchFetchResultEntry', 'if (res.code === 200 && !accepted)');
  const acceptedBranch = sliceFrom(meituanStatic, 'if (acceptedCount > 0) {', 'const modelRes = await requestDisplayModel');

  assert.match(meituanStatic, /const buildMeituanBatchFetchPendingEntry = \(task\) => \(\{/);
  assert.match(meituanStatic, /status: 'fetching'/);
  assert.match(fetchFlow, /const requestBody = \{ \.\.\.task\.body, async: true \};/);
  assert.doesNotMatch(fetchFlow, /async: false,\s*background: false/);
  assert.doesNotMatch(fetchFlow, /setHotelsList\(\[\]\);/);
  assert.doesNotMatch(fetchFlow, /setBusinessSummary\(getEmptyBusinessSummary\(\)\);/);
  assert.match(pendingSetup, /setOnlineDataResult\(\[\.\.\.results\]\);/);
  assert.match(pendingSetup, /setFetchSuccess\(true\);/);

  assert.match(html, /const isMeituanPendingResult = \(result = \{\}\)/);
  assert.match(html, /const isMeituanBackgroundResult = \(result = \{\}\)/);
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
