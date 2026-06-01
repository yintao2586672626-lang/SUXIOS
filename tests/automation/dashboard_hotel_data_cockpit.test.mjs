import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const onlinePageStart = html.indexOf("currentPage === 'online-data'");
const onlinePageEnd = html.indexOf('<!-- 下载中心 -->', onlinePageStart);
const onlinePage = onlinePageEnd > onlinePageStart
  ? html.slice(onlinePageStart, onlinePageEnd)
  : html.slice(onlinePageStart);

test('hotel data cockpit is the first online data health surface and keeps evidence chain module', () => {
  assert.ok(onlinePageStart > 0, 'online-data page section must exist');
  assert.match(onlinePage, /宿析OS · 酒店数据驾驶舱/);
  assert.match(onlinePage, /账号级驾驶舱/);
  assert.match(onlinePage, /单店酒店数据画像/);
  assert.match(onlinePage, /数据源状态 \/ 证据链/);
  assert.match(onlinePage, /门店数/);
  assert.match(onlinePage, /画像完成数/);
  assert.match(onlinePage, /异常门店/);
  assert.match(onlinePage, /同步状态/);
  assert.match(onlinePage, /今日行动建议/);
});

test('dashboard frontend calls dedicated dashboard APIs while old collection reliability remains available', () => {
  for (const endpoint of [
    '/dashboard/account-overview',
    '/dashboard/hotel-portrait',
    '/dashboard/data-sources',
  ]) {
    assert.match(html, new RegExp(endpoint.replaceAll('/', '\\/')));
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
    assert.match(html, new RegExp(state), `missing explicit dashboard state: ${state}`);
  }
});
