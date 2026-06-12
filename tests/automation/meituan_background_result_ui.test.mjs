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

test('Meituan ranking fetch requests platform data directly and shows visible progress', () => {
  const resultPanel = sliceFrom(html, '<!-- 获取结果显示 -->', '<!-- 原始JSON数据 -->');
  const fetchFlow = sliceFrom(meituanStatic, 'const runMeituanBatchFetchFlow = async ({', 'const buildMeituanRankDisplayRows');
  const pendingSetup = sliceFrom(meituanStatic, 'const results = fetchTasks.map', 'let totalSavedCount = 0;');
  const acceptedLoopUpdate = sliceFrom(meituanStatic, 'results[index] = buildMeituanBatchFetchResultEntry', 'if (res.code === 200 && !accepted)');
  const acceptedBranch = sliceFrom(meituanStatic, 'if (acceptedCount > 0) {', 'return { status: \'unexpected_background\'');

  assert.match(meituanStatic, /const buildMeituanBatchFetchPendingEntry = \(task\) => \(\{/);
  assert.match(meituanStatic, /status: 'fetching'/);
  assert.match(fetchFlow, /const requestBody = \{ \.\.\.task\.body, async: false, background: false \};/);
  assert.doesNotMatch(fetchFlow, /\.\.\.task\.body, async: true/);
  assert.match(pendingSetup, /setOnlineDataResult\(\[\.\.\.results\]\);/);
  assert.match(pendingSetup, /setFetchSuccess\(true\);/);
  assert.match(html, /const isMeituanPendingResult = \(result = \{\}\)/);
  assert.match(html, /const meituanFetchInProgress = computed/);
  assert.doesNotMatch(html, /const meituanFetchBackgroundAccepted = computed/);
  assert.match(resultPanel, /meituanFetchSuccess \|\| meituanFetchInProgress/);
  assert.match(resultPanel, /美团手动获取正在请求平台接口/);
  assert.match(resultPanel, /接口返回后直接显示本次榜单结果/);
  assert.doesNotMatch(resultPanel, /美团手动获取已提交后台执行/);
  assert.match(resultPanel, /isMeituanPendingResult\(result\)/);
  assert.doesNotMatch(resultPanel, /isMeituanBackgroundResult\(result\)/);
  assert.match(resultPanel, /正在请求平台接口/);
  assert.doesNotMatch(resultPanel, /后台执行中/);
  assert.match(meituanStatic, /status: 'unexpected_background'/);
  assert.match(meituanStatic, /未直接返回平台结果/);
  assert.match(meituanStatic, /message: response\.message \|\| ''/);
  assert.match(acceptedLoopUpdate, /setOnlineDataResult\(\[\.\.\.results\]\);/);
  assert.match(acceptedBranch, /平台授权是否有效/);
});
