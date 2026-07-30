import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const controller = fs.readFileSync('app/controller/CloudBrowserAuthorization.php', 'utf8');
const routes = fs.readFileSync('route/app.php', 'utf8');

test('cloud authorization panel reads its own authorization state and creates only a pending login entry', () => {
  assert.match(appMain, /request\(`\/cloud-browser-profiles\/status\?hotel_id=/);
  assert.match(appMain, /request\('\/cloud-browser-profiles\/request-login'/);
  assert.match(appMain, /当前不代表浏览器已打开或已登录/);
  assert.match(appMain, /awaiting_login/);
  assert.match(appMain, /ready_to_collect/);
  assert.match(appMain, /session_expired/);
  assert.match(routes, /Route::group\('api\/cloud-browser-profiles'[\s\S]+?->middleware\(\\app\\middleware\\Auth::class\)/);
  assert.match(controller, /hasHotelPermission\(\$hotelId, 'can_fetch_online_data'\)/);
});

test('cloud authorization panel exposes plain-language status without browser secrets or internals', () => {
  const start = template.indexOf('data-testid="cloud-browser-authorization-panel"');
  const end = template.indexOf('</section>', start);
  const panel = template.slice(start, end);
  assert.ok(start >= 0 && end > start, 'cloud authorization panel is missing');
  assert.match(panel, /v-for="row in cloudAuthorizationRows"/);
  assert.match(panel, /openCloudAuthorizationGuide\(row\)/);
  assert.match(panel, /最近采集/);
  assert.match(panel, /数据缺口/);
  assert.match(panel, /当前未出现平台页面时/);
  assert.doesNotMatch(panel, /profile_id|cookie|cdp|port/i);
});
