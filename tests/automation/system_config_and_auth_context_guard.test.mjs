import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const systemConfigController = readFileSync('app/controller/SystemConfigController.php', 'utf8');
const configDialog = readFileSync('resources/frontend/templates/fragments/43-dialogs-system-config.html', 'utf8');
const configPage = readFileSync('resources/frontend/templates/fragments/31-page-system-config.html', 'utf8');

const methodSlice = (start, end) => {
  const from = appMain.indexOf(start);
  const to = appMain.indexOf(end, from);
  assert.ok(from >= 0 && to > from, `missing source slice: ${start}`);
  return appMain.slice(from, to);
};

test('system config modal requires a full readback and only saves changed fields', () => {
  const open = methodSlice('const openSystemConfigModal = async () => {', 'const saveSystemConfig = async () => {');
  const save = methodSlice('const saveSystemConfig = async () => {', '// 导出系统配置');

  assert.match(open, /await request\('\/system-config', \{ withBusinessContext: false \}\)/);
  assert.match(open, /systemConfigFormBaseline = \{ \.\.\.systemConfigForm\.value \}/);
  assert.match(open, /showSystemConfigModal\.value = false/);
  assert.match(save, /if \(!systemConfigFormBaseline\)/);
  assert.match(save, /const changedConfig = Object\.fromEntries/);
  assert.match(save, /body: JSON\.stringify\(changedConfig\)/);
  assert.doesNotMatch(save, /body: JSON\.stringify\(systemConfigForm\.value\)/);
});

test('session lifetime is displayed as the fixed 72-hour authentication policy', () => {
  assert.doesNotMatch(configDialog, /v-model="systemConfigForm\.session_timeout"/);
  assert.match(configDialog, /固定 72 小时（4320 分钟）/);
  assert.match(configPage, /固定 72 小时/);
  assert.match(systemStatic, /session_timeout: '4320'/);
  assert.match(systemConfigController, /array_key_exists\(SystemConfig::KEY_SESSION_TIMEOUT, \$data\)/);
  assert.match(systemConfigController, /会话有效期固定为72小时，不支持在线修改', 422/);
});

test('denied or explicitly cleared auth context cannot leak an old hotel into requests', () => {
  const apply = methodSlice('const applyAuthContext = (context = {}) => {', 'const BUSINESS_CONTEXT_ENDPOINT_PREFIXES = [');
  const requestContext = methodSlice('const currentBusinessRequestContext = (overrides = {}) => {', 'const appendContextToRequestUrl =');

  assert.match(apply, /const denied = nextPermissionStatus === 'denied'/);
  assert.match(apply, /hotelId: denied \? null/);
  assert.match(apply, /hasHotelContext \? \(normalizedHotelId \|\| null\)/);
  assert.match(apply, /tenantId: denied \? null/);
  assert.match(requestContext, /if \(permissionStatus !== 'allowed'\) return \{\}/);
});
