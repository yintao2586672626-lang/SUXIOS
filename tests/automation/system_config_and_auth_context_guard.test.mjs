import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

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

const resolveBusinessRequestContext = ({ authContext, selectedHotelId, hotelPool, user }, overrides = {}) => {
  const source = methodSlice(
    'const currentBusinessRequestContext = (overrides = {}) => {',
    'const appendContextToRequestUrl =',
  );
  const context = vm.createContext({
    Array,
    Object,
    String,
    authContext: { value: authContext },
    filterReportHotel: { value: selectedHotelId },
    permittedHotels: { value: hotelPool },
    user: { value: user },
  });
  vm.runInContext(`${source}\nglobalThis.resolveContext = currentBusinessRequestContext;`, context);
  return JSON.parse(JSON.stringify(context.resolveContext(overrides)));
};

test('system config modal requires a full readback and only saves changed fields', () => {
  const open = methodSlice('const openSystemConfigModal = async () => {', 'const saveSystemConfig = async () => {');
  const save = methodSlice('const saveSystemConfig = async () => {', '// 导出系统配置');

  assert.match(open, /const requestSession = captureAuthSession\(\)/);
  assert.match(
    open,
    /const requestPolicy = currentPageReadPolicy\('system-config', 'action'\);\s*requestPolicy\.force = true;/,
    'opening the modal must force an action-priority read instead of reusing cached config',
  );
  assert.match(
    open,
    /await request\('\/system-config', \{\s*withBusinessContext: false,\s*requestPolicy,\s*\}\)/,
  );
  assert.match(
    open,
    /if \(!isAuthSessionCurrent\(requestSession\)\s*\|\| currentPage\.value !== 'system-config'\s*\|\| !isPageLoadPolicyCurrent\(requestPolicy\)\s*\) \{\s*return;\s*\}/,
    'stale session, page, or request-policy results must not open the system-config modal',
  );
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

  assert.match(apply, /const denied = nextPermissionStatus === 'denied'/);
  assert.match(apply, /hotelId: denied \? null/);
  assert.match(apply, /hasHotelContext \? \(normalizedHotelId \|\| null\)/);
  assert.match(apply, /tenantId: denied \? null/);
  assert.deepEqual(
    resolveBusinessRequestContext({
      authContext: { hotelId: 7, tenantId: 7, permissionStatus: 'denied' },
      user: { hotel_id: 7 },
      selectedHotelId: 7,
      hotelPool: [{ id: 7, tenant_id: 7 }],
    }),
    {},
  );
});

test('business request context follows the permitted hotel selected in the workbench', () => {
  const input = {
    authContext: { hotelId: 7, tenantId: 7, permissionStatus: 'allowed', platform: 'unknown' },
    selectedHotelId: '80',
    hotelPool: [
      { id: 7, tenant_id: 7 },
      { id: 80, tenant_id: 80 },
    ],
    user: { hotel_id: 7 },
  };

  assert.deepEqual(
    resolveBusinessRequestContext(input),
    { system_hotel_id: '80', tenant_id: '80' },
  );
  assert.deepEqual(
    resolveBusinessRequestContext(input, { hotelId: 7, tenantId: 7 }),
    { system_hotel_id: '7', tenant_id: '7' },
  );
});
