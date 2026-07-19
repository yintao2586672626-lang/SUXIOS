import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');

test('superadmin data config closes the competitor device lifecycle without persisting one-time tokens', () => {
  const fragment = read('resources/frontend/templates/fragments/34-page-data-config.html');
  const appMain = read('public/app-main.js');
  const component = read('public/components/admin/competitor-device-management.js');
  const routes = read('route/app.php');
  const controller = read('app/controller/admin/CompetitorDeviceController.php');
  const deviceLifecycle = appMain.slice(
    appMain.indexOf('const saveCompetitorDevice = async'),
    appMain.indexOf('const loadCompetitorRobots = async'),
  );

  assert.match(fragment, /<competitor-device-management v-if="user && user\.is_super_admin" :ctx="\$root">/);
  assert.match(appMain, /components\/admin\/competitor-device-management\.js\?v=20260719-device-lifecycle-v1/);
  assert.match(appMain, /ensureCompetitorDeviceManagementReady[\s\S]*CompetitorDeviceManagementBody/);
  for (const testId of [
    'competitor-device-loading',
    'competitor-device-error',
    'competitor-device-empty',
    'competitor-device-list',
    'competitor-device-credential-modal',
    'competitor-device-one-time-token',
  ]) {
    assert.match(component, new RegExp(`['"]data-testid['"]?: ['"]${testId}['"]|data-testid="${testId}"`));
  }
  assert.match(component, /binding_missing[\s\S]*不能仅凭“已创建”判断采集可用/);
  assert.match(component, /const online = enabled && item\.is_online === true/);
  assert.doesNotMatch(component, /token_hash/);

  assert.match(deviceLifecycle, /const payload = \{[\s\S]*device_id:[\s\S]*name:[\s\S]*platform:[\s\S]*store_id:[\s\S]*user_id:/);
  assert.match(deviceLifecycle, /request\('\/admin\/competitor-devices', \{[\s\S]*method: 'POST'/);
  assert.match(deviceLifecycle, /request\(`\/admin\/competitor-devices\/\$\{item\.id\}\/rotate-token`, \{ method: 'POST' \}\)/);
  assert.match(deviceLifecycle, /request\(`\/admin\/competitor-devices\/\$\{item\.id\}\/status`, \{[\s\S]*method: 'PUT'/);
  assert.match(deviceLifecycle, /confirm\(`确认轮换设备[\s\S]*旧 Token 将立即失效/);
  assert.match(deviceLifecycle, /competitorDeviceCredential\.value = \{[\s\S]*device_token: res\.data\.device_token[\s\S]*await loadCompetitorDevices\(\)/);
  assert.doesNotMatch(deviceLifecycle, /localStorage|sessionStorage|console\.(?:log|debug)\([^)]*device_token|URLSearchParams\([^)]*device_token/);
  assert.doesNotMatch(component, /localStorage|sessionStorage|console\.(?:log|debug)\([^)]*device_token|URLSearchParams\([^)]*device_token/);

  assert.match(routes, /api\/admin\/competitor-devices[\s\S]*rotate-token[\s\S]*\/status/);
  assert.match(controller, /token_visible_once[\s\S]*Cache-Control'[\s\S]*no-store/);
  assert.match(controller, /field\([\s\S]*token_hint[\s\S]*\)->order/);
  assert.doesNotMatch(controller.slice(controller.indexOf('public function index'), controller.indexOf('public function create')), /token_hash|device_token/);
});
