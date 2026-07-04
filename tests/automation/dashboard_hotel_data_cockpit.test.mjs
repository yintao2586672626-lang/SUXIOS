import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const dataHealthStatic = readFileSync('public/data-health-static.js', 'utf8');
const collectionReliabilityConcern = readFileSync('app/controller/concern/CollectionReliabilityConcern.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const onlinePageStart = html.indexOf("currentPage === 'online-data'");
const onlinePageEnd = html.indexOf('<!-- 下载中心 -->', onlinePageStart);
const onlinePage = onlinePageEnd > onlinePageStart
  ? html.slice(onlinePageStart, onlinePageEnd)
  : html.slice(onlinePageStart);

test('manual one-click fetch is the first online data surface and keeps diagnostics behind full mode', () => {
  assert.ok(onlinePageStart > 0, 'online-data page section must exist');
  assert.match(onlinePage, /数据一键获取/);
  assert.match(onlinePage, /手动一键获取/);
  assert.match(onlinePage, /manualOneClickFetchRows/);
  assert.match(onlinePage, /manualOneClickFetchCards/);
  assert.match(onlinePage, /一键获取携程/);
  assert.match(onlinePage, /一键获取美团/);
  assert.match(onlinePage, /双平台一键获取/);
  assert.match(onlinePage, /runManualOneClickFetch/);
  assert.match(onlinePage, /fetchCtripData/);
  assert.match(onlinePage, /fetchMeituanData/);
  assert.match(onlinePage, /result\?\.response\?\.data\?\.saved_count/);
  assert.match(onlinePage, /result\?\.totalSavedCount/);
  assert.match(onlinePage, /no_saved/);
  assert.match(onlinePage, /本次入库 0 条，不等于入库成功/);
  assert.doesNotMatch(onlinePage, /triggerCookieConfigAutoFetchGroup\(group\.hotelIds\)/);
  assert.doesNotMatch(onlinePage, /当前卡点/);
  assert.doesNotMatch(onlinePage, /今天先处理的问题/);
  assert.doesNotMatch(onlinePage, /优先处理动作/);
  assert.match(onlinePage, /排障诊断/);
  assert.match(onlinePage, /账号级驾驶舱/);
  assert.match(onlinePage, /单店酒店数据画像/);
  assert.match(onlinePage, /数据源状态 \/ 证据链/);
  assert.match(onlinePage, /dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded/);
  assert.match(onlinePage, /data-health-full-diagnostics-detail/);
  assert.match(onlinePage, /门店数/);
  assert.match(onlinePage, /画像完成数/);
  assert.match(onlinePage, /异常门店/);
  assert.match(onlinePage, /同步状态/);
});

test('dashboard frontend calls dedicated dashboard APIs while old collection reliability remains available', () => {
  for (const endpoint of [
    '/dashboard/account-overview',
    '/dashboard/hotel-portrait',
    '/dashboard/data-sources',
  ]) {
    assert.match(dataHealthStatic, new RegExp(endpoint.replaceAll('/', '\\/')));
  }
  assert.match(html, /\/online-data\/collection-reliability/);

  assert.match(routes, /Route::group\('api\/dashboard'/);
  assert.match(routes, /account-overview/);
  assert.match(routes, /hotel-portrait/);
  assert.match(routes, /data-sources/);
});

test('dashboard UI exposes required portrait sections, diagnostics and explicit data states', () => {
  for (const label of [
    '基础',
    '经营',
    '流量',
    '转化',
    '价格房态',
    '竞争',
    '点评服务',
    'IM',
    '广告',
    '客群',
    '数据健康',
  ]) {
    assert.match(html, new RegExp(label), `missing portrait section label: ${label}`);
  }

  for (const key of ['problem', 'evidence', 'impact', 'action']) {
    assert.match(html, new RegExp(key), `missing diagnosis key: ${key}`);
  }

  for (const state of ['zero', 'null', 'not_collected', 'auth_failed', 'request_failed', 'field_missing']) {
    assert.match(`${dataHealthStatic}\n${collectionReliabilityConcern}`, new RegExp(state), `missing explicit dashboard state: ${state}`);
  }
});
