import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const summaryFragment = readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const workbenchFragment = readFileSync('resources/frontend/templates/fragments/23b-page-ai-workbench.html', 'utf8');
const detailFragment = readFileSync('resources/frontend/templates/fragments/23c-page-compass-detail.html', 'utf8');
const homeStatic = readFileSync('public/home-static.js', 'utf8');
const compassStatic = readFileSync('public/compass-static.js', 'utf8');
const publicIndex = readFileSync('public/index.html', 'utf8');

test('home evidence hierarchy uses exact facts and source boundaries instead of causal claims', () => {
  assert.match(summaryFragment, /<home-yesterday-operating-facts/);
  assert.match(homeStatic, /'home-yesterday-facts'/);
  assert.match(homeStatic, /全酒店口径（PMS）/);
  assert.match(homeStatic, /OTA 渠道口径（携程 \+ 美团）/);
  assert.match(homeStatic, /'home-reconciliation-facts'/);
  assert.match(homeStatic, /'home-whole-hotel-scope'/);
  assert.match(homeStatic, /'home-ota-channel-scope'/);
  assert.match(summaryFragment, /昨天事实 \/ 今天状态 \/ 未来 AI 研判/);
  assert.match(detailFragment, /data-testid="home-evidence-fold"/);
  assert.match(homeStatic, /不回退旧日期/);
  assert.match(homeStatic, /不把进行中快照写成日终经营结果/);
  assert.match(homeStatic, /不使用 OTA 数据外推全酒店 OCC、RevPAR 或总营收/);
  assert.doesNotMatch(detailFragment, />经营因果链</);
  assert.doesNotMatch(detailFragment, /node\.ready \? '72%' : '18%'/);
  assert.match(compassStatic, /可能影响因素与证据/);
  assert.doesNotMatch(compassStatic, /定位获客和收入原因/);
});

test('default AI workbench shows both scopes and reconciliation before source mode switches', () => {
  const factsIndex = workbenchFragment.indexOf('<home-yesterday-operating-facts');
  const temporalIndex = workbenchFragment.indexOf('data-testid="home-temporal-axis"');

  assert.ok(factsIndex >= 0, 'default homepage must expose yesterday operating facts');
  assert.ok(temporalIndex > factsIndex, 'dual-scope facts must be visible before the switchable temporal area');
  assert.match(workbenchFragment, /:show-header="true"/);
  assert.match(homeStatic, /全酒店口径（PMS）/);
  assert.match(homeStatic, /OTA 渠道口径（携程 \+ 美团）/);
  assert.match(homeStatic, /PMS 与 OTA 对账/);
  assert.doesNotMatch(
    workbenchFragment.slice(factsIndex, temporalIndex),
    /v-if="dualOtaPmsSelected"|v-if="!dualOtaPmsSelected"/,
  );
});

test('compass static cache key matches the shipped asset content', () => {
  const runtime = readFileSync('public/app-startup-helpers.min.js');
  const hash = createHash('sha256').update(runtime).digest('hex').slice(0, 10);
  assert.match(publicIndex, new RegExp(`app-startup-helpers\\.min\\.js\\?v=[^"']*h${hash}`));
});
