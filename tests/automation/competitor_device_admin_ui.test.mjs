import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const read = (path) => readFileSync(path, 'utf8');

test('superadmin data config closes the competitor device lifecycle without persisting one-time tokens', () => {
  const fragment = read('resources/frontend/templates/fragments/34-page-data-config.html');
  const appMain = read('public/app-main.js');
  const component = read('public/components/admin/competitor-device-management.js');
  const routes = read('route/app.php');
  const controller = read('app/controller/admin/CompetitorDeviceController.php');
  const competitorApi = read('app/controller/CompetitorApi.php');
  const deviceLifecycle = appMain.slice(
    appMain.indexOf('const saveCompetitorDevice = async'),
    appMain.indexOf('const loadCompetitorRobots = async'),
  );

  assert.match(fragment, /<competitor-device-management v-if="user && user\.is_super_admin" :ctx="\$root">/);
  assert.match(appMain, /components\/admin\/competitor-device-management\.js\?v=20260719-device-lifecycle-v3/);
  assert.match(appMain, /ensureCompetitorDeviceManagementReady[\s\S]*CompetitorDeviceManagementBody/);
  assert.match(appMain, /script\.onerror[\s\S]*script\.remove\(\)/);
  assert.match(appMain, /data-testid': 'competitor-device-load-error'[\s\S]*retryCompetitorDeviceManagement/);
  for (const testId of [
    'competitor-device-loading',
    'competitor-device-error',
    'competitor-device-empty',
    'competitor-device-list',
    'competitor-device-refreshing',
    'competitor-device-pagination',
    'competitor-device-credential-modal',
    'competitor-device-one-time-token',
  ]) {
    assert.match(component, new RegExp(`['"]data-testid['"]?: ['"]${testId}['"]|data-testid="${testId}"`));
  }
  assert.match(component, /binding_missing[\s\S]*不能仅凭“已创建”判断采集可用/);
  assert.match(component, /const online = enabled && item\.is_online === true/);
  assert.doesNotMatch(component, /token_hash/);

  assert.match(deviceLifecycle, /const payload = \{[\s\S]*device_id:[\s\S]*name:[\s\S]*platform:[\s\S]*store_id:[\s\S]*user_id:/);
  assert.match(deviceLifecycle, /request\(editingId > 0 \? `\/admin\/competitor-devices\/\$\{editingId\}\/rebind` : '\/admin\/competitor-devices', \{[\s\S]*method: editingId > 0 \? 'PUT' : 'POST'/);
  assert.match(deviceLifecycle, /request\(`\/admin\/competitor-devices\/\$\{item\.id\}\/rotate-token`, \{[\s\S]*method: 'POST'[\s\S]*expected_token_version: Number\(item\.token_version/);
  assert.match(deviceLifecycle, /`\/admin\/competitor-devices\/\$\{editingId\}\/rebind`[\s\S]*method: editingId > 0 \? 'PUT' : 'POST'/);
  assert.match(deviceLifecycle, /request\(`\/admin\/competitor-devices\/\$\{item\.id\}\/status`, \{[\s\S]*method: 'PUT'/);
  assert.match(deviceLifecycle, /expected_token_version: Number\(competitorDeviceForm\.value\.token_version[\s\S]*expected_token_version: Number\(item\.token_version/);
  assert.match(deviceLifecycle, /res\.code === 409 && res\.data\?\.reason === 'binding_changed'/);
  assert.match(deviceLifecycle, /confirm\(`确认轮换设备[\s\S]*旧 Token 将立即失效/);
  assert.match(deviceLifecycle, /competitorDeviceCredential\.value = \{[\s\S]*device_token: res\.data\.device_token[\s\S]*await loadCompetitorDevices\(\)/);
  assert.doesNotMatch(deviceLifecycle, /localStorage|sessionStorage|console\.(?:log|debug)\([^)]*device_token|URLSearchParams\([^)]*device_token/);
  assert.doesNotMatch(component, /localStorage|sessionStorage|console\.(?:log|debug)\([^)]*device_token|URLSearchParams\([^)]*device_token/);

  assert.match(routes, /api\/admin\/competitor-devices[\s\S]*\/:id\/rebind[\s\S]*rotate-token[\s\S]*\/status/);
  assert.match(controller, /token_visible_once[\s\S]*Cache-Control'[\s\S]*no-store/);
  assert.match(controller, /function rebind[\s\S]*Db::transaction[\s\S]*issueCredential[\s\S]*last_time = null/);
  assert.match(controller, /function rotateToken[\s\S]*Db::transaction[\s\S]*lock\(true\)[\s\S]*assertExpectedTokenVersion/);
  assert.match(controller, /function updateStatus[\s\S]*Db::transaction[\s\S]*assertExpectedTokenVersion/);
  assert.match(controller, /status === 1[\s\S]*revoked_at[\s\S]*启用前必须先轮换Token/);
  assert.match(controller, /token_hash = ''[\s\S]*token_hint = ''/);
  assert.match(controller, /field\([\s\S]*token_hint[\s\S]*\)->order/);
  assert.doesNotMatch(controller.slice(controller.indexOf('public function index'), controller.indexOf('public function create')), /token_hash|device_token/);
  assert.match(competitorApi, /bindingSessionIsCurrent\(\$device\)[\s\S]*bindingSessionMatches\(\$device, \$currentBinding\)/);
});

test('competitor device component executes truthful failure, pagination, rebind and one-time token branches', () => {
  const source = read('public/components/admin/competitor-device-management.js');
  const registry = {};
  const Vue = {
    Fragment: Symbol('Fragment'),
    h: (type, props = null, children = null) => ({ type, props: props || {}, children }),
  };
  runInNewContext(source, {
    window: { SUXI_ONLINE_DATA_COMPONENTS: registry },
    Vue,
  }, { filename: 'competitor-device-management.js' });
  const component = registry.CompetitorDeviceManagementBody;
  assert.ok(component && typeof component.render === 'function');

  const calls = { reload: 0, page: [], edit: [] };
  const baseContext = () => ({
    competitorDevices: [],
    competitorDevicePlatforms: [{ value: 'xc', label: '携程' }],
    competitorStores: [{ id: 7, name: '测试门店' }],
    competitorDeviceEligibleUsers: () => [{ id: 9, status: 1 }],
    competitorDevicePagination: { total: 0, page: 1, page_size: 20, total_page: 1 },
    competitorDevicesError: '',
    competitorDevicesLoading: false,
    competitorDevicesStale: false,
    competitorDeviceActionId: 0,
    competitorDeviceSaving: false,
    competitorDeviceEditingId: 0,
    competitorDeviceTokenCopied: false,
    showCompetitorDeviceModal: false,
    competitorDeviceCredential: null,
    competitorDeviceForm: { device_id: '', name: '', platform: 'xc', store_id: '7', user_id: '9', token_version: 0 },
    loadCompetitorDeviceWorkbench: () => { calls.reload += 1; },
    changeCompetitorDevicePage: page => { calls.page.push(page); },
    openCompetitorDeviceModal: item => { calls.edit.push(item); },
    closeCompetitorDeviceModal: () => {},
    saveCompetitorDevice: () => {},
    clearCompetitorDeviceCredential: () => {},
    copyCompetitorDeviceToken: () => {},
    rotateCompetitorDeviceToken: () => {},
    updateCompetitorDeviceStatus: () => {},
    competitorDevicePlatformLabel: value => value,
    competitorDeviceUserLabel: value => `用户 #${value}`,
    competitorDeviceLastSeenText: () => '从未成功握手',
    getCompetitorStoreName: value => `门店 #${value}`,
  });
  const nodes = root => {
    const result = [];
    const visit = value => {
      if (Array.isArray(value)) return value.forEach(visit);
      if (!value || typeof value !== 'object') return;
      result.push(value);
      visit(value.children);
    };
    visit(root);
    return result;
  };
  const textOf = value => {
    if (Array.isArray(value)) return value.map(textOf).join('');
    if (value === null || value === undefined || value === false) return '';
    if (typeof value !== 'object') return String(value);
    return textOf(value.children);
  };
  const render = ctx => component.render.call({ ctx });

  const failed = baseContext();
  failed.competitorDevicesError = '设备列表失败';
  let rendered = render(failed);
  let renderedNodes = nodes(rendered);
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-error'));
  assert.ok(!renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-empty'));
  const reloadButton = renderedNodes.find(node => node.type === 'button' && textOf(node) === '重新加载');
  reloadButton.props.onClick();
  assert.equal(calls.reload, 1);

  const empty = baseContext();
  renderedNodes = nodes(render(empty));
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-empty'));

  const listed = baseContext();
  listed.competitorDevices = [{ id: 3, device_id: 'desk-3', name: '前台', platform: 'xc', store_id: 7, user_id: 9, status: 1, token_hint: '…12345678', token_version: 2 }];
  listed.competitorDevicesLoading = true;
  listed.competitorDevicesStale = true;
  listed.competitorDevicePagination = { total: 21, page: 1, page_size: 20, total_page: 2 };
  renderedNodes = nodes(render(listed));
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-refreshing'));
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-list'));
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-pagination'));
  const nextButton = renderedNodes.find(node => node.type === 'button' && textOf(node) === '下一页');
  nextButton.props.onClick();
  assert.deepEqual(calls.page, [2]);
  const rebindButton = renderedNodes.find(node => node.type === 'button' && textOf(node) === '重新绑定');
  rebindButton.props.onClick();
  assert.equal(calls.edit[0].id, 3);

  const disabled = baseContext();
  disabled.competitorDevices = [{ id: 4, device_id: 'desk-4', platform: 'xc', store_id: 7, user_id: 9, status: 0, token_hint: '', token_version: 3, revoked_at: '2026-07-19 12:00:00' }];
  renderedNodes = nodes(render(disabled));
  const blockedEnable = renderedNodes.find(node => node.type === 'button' && textOf(node) === '待轮换后启用');
  assert.equal(blockedEnable.props.disabled, true);

  listed.showCompetitorDeviceModal = true;
  listed.competitorDeviceEditingId = 3;
  listed.competitorDeviceForm.device_id = 'desk-3';
  listed.competitorDeviceCredential = { device_id: 'desk-3', device_token: 'secret-once', token_version: 3, platform: 'xc', store_id: 7, user_id: 9, status: 1 };
  listed.competitorDeviceTokenCopied = true;
  rendered = render(listed);
  renderedNodes = nodes(rendered);
  assert.match(textOf(rendered), /重新绑定竞对采集设备/);
  assert.ok(renderedNodes.some(node => node.type === 'input' && node.props.value === 'desk-3' && node.props.disabled === true));
  assert.ok(renderedNodes.some(node => node.props['data-testid'] === 'competitor-device-credential-modal'));
  assert.ok(renderedNodes.some(node => node.type === 'button' && textOf(node) === '已复制'));
});

test('competitor device bindings use explicit fetch permission and authoritative hotel tenant scope', () => {
  const authService = read('app/service/CompetitorDeviceAuthService.php');
  const hotelController = read('app/controller/admin/CompetitorHotelController.php');
  const migration = read('database/migrations/20260719_bind_competitor_devices_to_hotel_scope.sql');
  const initFull = read('database/init_full.sql');

  assert.match(authService, /authorize\(\$user, 'ota\.collect', \$storeId\)/);
  assert.match(authService, /\$userTenantId[\s\S]*\$userTenantId !== \$tenantId/);
  assert.match(authService, /bindingSessionIsCurrent[\s\S]*bindingSessionMatches/);
  assert.doesNotMatch(authService, /(?:canAccessHotel|hotelPermissionAllows)\(\$user, \$storeId, 'can_fetch_online_data'\)/);
  assert.match(initFull, /SOURCE \.\/database\/migrations\/20260719_bind_competitor_devices_to_hotel_scope\.sql;/);
  assert.ok(
    initFull.indexOf('20260529_add_tenant_security_fields.sql')
      < initFull.indexOf('20260719_bind_competitor_devices_to_hotel_scope.sql'),
    'device scope migration must run after the base tenant fields exist',
  );
  assert.match(migration, /JOIN `hotels` AS `h` ON `h`\.`id` = `ch`\.`store_id`/);
  assert.match(migration, /SET `ch`\.`tenant_id` = `h`\.`tenant_id`/);
  assert.match(migration, /LEFT JOIN `hotels` AS `h`[\s\S]*SET `ch`\.`tenant_id` = NULL,[\s\S]*`ch`\.`status` = 0/);
  assert.doesNotMatch(migration, /SET `tenant_id` = `store_id`/);
  assert.match(hotelController, /resolveStoreTenantId\(int \$storeId\): int/);
  assert.match(hotelController, /Db::name\('hotels'\)[\s\S]*value\('tenant_id'\)/);
  assert.doesNotMatch(hotelController, /\$hotel->tenant_id = \(int\)\$data\['store_id'\]/);
});
