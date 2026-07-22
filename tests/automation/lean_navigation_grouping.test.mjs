import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataPage = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const appShellFragment = readFileSync('resources/frontend/templates/fragments/00-app-shell.html', 'utf8');
const navigationStart = appMain.indexOf('const BOSS_VISIBLE_NAVIGATION_CONFIG');
const navigationEnd = appMain.indexOf('const buildLeanNavigationEntry', navigationStart);
const navigation = navigationStart >= 0 && navigationEnd > navigationStart
  ? appMain.slice(navigationStart, navigationEnd)
  : '';

function section(startMarker, endMarker = '') {
  const start = navigation.indexOf(startMarker);
  assert.ok(start >= 0, `missing navigation marker: ${startMarker}`);
  if (!endMarker) return navigation.slice(start);
  const end = navigation.indexOf(endMarker, start + startMarker.length);
  assert.ok(end > start, `missing navigation end marker: ${endMarker}`);
  return navigation.slice(start, end);
}

test('boss navigation separates analysis, OTA collection, operations and system tools', () => {
  assert.ok(navigation, 'boss navigation config must exist');

  const analysis = section("name: '经营分析'", "name: 'OTA数据与采集'");
  assert.match(analysis, /name: '经营分析'/);
  assert.match(analysis, /sourcePath: 'online-data',[\s\S]*sourceTab: 'data-health'/);
  assert.match(analysis, /sourcePath: 'revenue-research-center'/);
  assert.match(analysis, /sourcePath: 'ai-daily-report'/);

  const ota = section("name: 'OTA数据与采集'", "name: '运营执行'");
  assert.match(ota, /name: 'OTA数据与采集'/);
  assert.match(ota, /sourcePath: 'ctrip-ebooking'/);
  assert.match(ota, /sourcePath: 'meituan-ebooking'/);
  assert.match(ota, /sourceTab: 'platform-auto',[\s\S]*name: '自动采集任务'/);
  assert.doesNotMatch(ota, /nav-platform-account-config|nav-ota-collection-records/);
  assert.doesNotMatch(ota, /tab: 'platform-sources'|tab: 'data'/);
  assert.match(onlineDataPage, /openPlatformSourcesTab\(\)[\s\S]*配置：平台账号/);
  assert.match(onlineDataPage, /openOnlineDataTab\('data'\)[\s\S]*记录与下载/);

  const operations = section("name: '运营执行'", "sourcePath: 'hotels'");
  assert.match(operations, /name: '运营执行'/);
  assert.match(operations, /sourcePath: 'ops-track'/);
  assert.doesNotMatch(operations, /sourcePath: 'ai-daily-report'/);
  assert.doesNotMatch(operations, /sourcePath: 'ai-governance'/);

  const systemTools = section("name: '系统与工具'");
  assert.match(systemTools, /name: '系统与工具'/);
  assert.match(systemTools, /sourcePath: 'ai-governance',[\s\S]*name: 'AI决策审计'/);
  assert.match(systemTools, /name: '系统与权限'/);
});

test('sidebar highlights only the exact active leaf, including online-data tabs', () => {
  assert.match(appMain, /const isSidebarMenuItemActive = \(item = \{\}\) =>/);
  assert.match(appMain, /item\.path !== 'online-data'/);
  assert.match(appMain, /pendingOnlineDataEntryTab \|\| onlineDataTab\.value \|\| 'data-health'/);
  assert.match(appShellFragment, /'active': isSidebarMenuItemActive\(child\)/);
  assert.match(appShellFragment, /'active': isSidebarMenuItemActive\(grandChild\)/);
  assert.match(appShellFragment, /'active': isSidebarMenuItemActive\(item\)/);
  assert.doesNotMatch(appShellFragment, /'active': item\.children\.some/);
  assert.doesNotMatch(appShellFragment, /'active': child\.children\.some/);
  assert.match(appShellFragment, /aria-current="isSidebarMenuItemActive\(child\) \? 'page'/);
});
