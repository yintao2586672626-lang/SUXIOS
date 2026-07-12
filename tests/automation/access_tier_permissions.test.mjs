import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const systemStatic = read('public/system-static.js');
const userAdminStatic = read('public/user-admin-static.js');
const indexHtml = read('public/index.html');
const hotelAccountSummary = indexHtml.slice(
  indexHtml.indexOf('data-testid="hotel-account-summary-table"'),
  indexHtml.indexOf('<!-- 空状态 -->', indexHtml.indexOf('data-testid="hotel-account-summary-table"'))
);
const hotelController = read('app/controller/Hotel.php');
const userModel = read('app/model/User.php');
const roleModel = read('app/model/Role.php');
const userController = read('app/controller/User.php');
const roleController = read('app/controller/RoleController.php');
const authController = read('app/controller/Auth.php');
const authMiddleware = read('app/middleware/Auth.php');
const cookieEndpointConcern = read('app/controller/concern/CookieEndpointConcern.php');
const initDatabaseCommand = read('app/command/InitDatabase.php');
const routes = read('route/app.php');
const hotelScopeService = read('app/service/HotelScopeService.php');
const hotelDataMergeService = read('app/service/HotelDataMergeService.php');
const permissionService = read('app/service/PermissionService.php');
const compassController = read('app/controller/admin/Compass.php');
const migration = read('database/migrations/20260614_add_access_tier_hotel_owner_scope.sql');
const hotelOtaStrategyMigration = read('database/migrations/20260709_add_hotel_ota_channel_strategy.sql');
const hotelOtaLoginEligibilityVerifier = read('scripts/verify_hotel_ota_login_eligibility.php');
const initFull = read('database/init_full.sql');
const seedSql = read('database/hotel_admin_mysql.sql');
const seedNormalRoleLine = seedSql.split(/\r?\n/).find(line => line.includes("'normal_user'")) || '';

assert.match(
  systemStatic,
  /path:\s*'hotels'[\s\S]*permissions:\s*\['can_manage_own_hotels'\]/,
  '门店管理菜单必须依赖 can_manage_own_hotels'
);
assert.match(
  systemStatic,
  /name:\s*'团队管理'[\s\S]*requireSuper:\s*true/,
  '团队管理菜单必须仅管理员可见'
);
assert.match(
  systemStatic,
  /if \(currentUser\.is_super_admin\) return Array\.isArray\(items\) \? items : \[\];/,
  '超级管理员必须看到完整系统菜单'
);
assert.match(
  systemStatic,
  /if \(!isItemVisible\(item\)\) \{\s*return null;\s*\}/,
  'parent menu permission gates must apply before child recursion'
);

const systemStaticSandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(systemStatic, systemStaticSandbox, { filename: 'public/system-static.js' });
const systemStaticApi = systemStaticSandbox.window.SUXI_SYSTEM_STATIC;
assert.equal(typeof systemStaticApi.hotelMergeFlowState, 'function', 'hotel merge UI must expose a testable step-state helper');
const hotelMergeSelectState = systemStaticApi.hotelMergeFlowState({
  form: { source_hotel_id: '', target_hotel_id: '', confirmation_text: '' },
});
assert.equal(hotelMergeSelectState.step, 1, 'hotel merge starts at store selection');
assert.equal(hotelMergeSelectState.can_preview, false, 'hotel merge preview stays disabled until both stores are selected');
const hotelMergePreviewState = systemStaticApi.hotelMergeFlowState({
  form: { source_hotel_id: '61', target_hotel_id: '80', confirmation_text: '' },
});
assert.equal(hotelMergePreviewState.step, 2, 'selected stores advance the hotel merge flow to preview');
assert.equal(hotelMergePreviewState.can_preview, true, 'distinct selected stores enable the preview action');
assert.match(hotelMergePreviewState.preview_label, /生成迁移预览/, 'the primary action must name the preview step');
const hotelMergeConfirmState = systemStaticApi.hotelMergeFlowState({
  preview: {
    can_execute: true,
    source_hotel: { id: 61 },
    target_hotel: { id: 80 },
    confirmation_text: 'MERGE 61 -> 80',
  },
  form: { source_hotel_id: '61', target_hotel_id: '80', confirmation_text: '' },
});
assert.equal(hotelMergeConfirmState.step, 3, 'an executable preview advances the hotel merge flow to confirmation');
assert.equal(hotelMergeConfirmState.can_execute, false, 'exact confirmation text remains required');
assert.match(hotelMergeConfirmState.execute_hint, /MERGE 61 -> 80/, 'the disabled execution state must explain the exact confirmation text');
const hotelMergeReadyState = systemStaticApi.hotelMergeFlowState({
  preview: {
    can_execute: true,
    source_hotel: { id: 61 },
    target_hotel: { id: 80 },
    confirmation_text: 'MERGE 61 -> 80',
  },
  form: { source_hotel_id: '61', target_hotel_id: '80', confirmation_text: 'MERGE 61 -> 80' },
});
assert.equal(hotelMergeReadyState.can_execute, true, 'exact confirmation text enables execution');
assert.equal(hotelMergeReadyState.execute_label, '确认执行迁移', 'ready state must expose the final execution action');
const createdHotelForm = systemStaticApi.createHotelForm({ operatorName: '管理员', code: '0001' });
const editedHotelForm = systemStaticApi.createHotelForm({
  hotel: { id: 60, name: '敦煌漠蓝', code: '0001', status: 1, ota_channel_strategy: 'ctrip_only' },
  operatorName: '管理员',
});
const invalidStrategyPayload = systemStaticApi.buildHotelSavePayload({
  form: { name: '敦煌漠蓝', status: '1', ota_channel_strategy: 'invalid' },
  normalizedCode: '0001',
  operatorName: '管理员',
  description: '',
});
assert.equal(createdHotelForm.ota_channel_strategy, 'none', 'new hotel forms must not claim an OTA channel before the user selects one');
assert.equal(editedHotelForm.ota_channel_strategy, 'ctrip_only', 'hotel edit forms must echo the saved OTA channel strategy');
assert.equal(invalidStrategyPayload.ota_channel_strategy, 'none', 'invalid UI OTA channel strategy must not silently claim dual channels');
assert.deepEqual(Array.from(systemStaticApi.selectedHotelOtaPlatforms('dual')), ['ctrip', 'meituan'], 'dual selection must map to both OTA platforms');
assert.deepEqual(Array.from(systemStaticApi.selectedHotelOtaPlatforms('none')), [], 'none selection must map to no OTA platform');
assert.equal(systemStaticApi.hotelOtaStrategyFromPlatforms(['ctrip']), 'ctrip_only');
assert.equal(systemStaticApi.hotelOtaStrategyFromPlatforms(['meituan']), 'meituan_only');
assert.equal(systemStaticApi.hotelOtaStrategyFromPlatforms(['ctrip', 'meituan']), 'dual');
assert.equal(systemStaticApi.hotelOtaStrategyFromPlatforms([]), 'none');
assert.deepEqual(
  JSON.parse(JSON.stringify(systemStaticApi.buildHotelVerifiedOtaState([
    { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
    { platform: 'meituan', level: 'partial', sessionVerified: false, storeIdentitySaved: true },
  ]))),
  { key: 'ctrip', text: '携程', visible: true, className: 'bg-blue-50 text-blue-700 border-blue-100' },
  'a planned dual strategy must only show the channel whose current login is actually verified'
);
assert.equal(systemStaticApi.buildHotelVerifiedOtaState([
  { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
  { platform: 'meituan', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
]).text, '双渠道', 'dual-channel status requires both platform logins to be verified');
assert.equal(systemStaticApi.buildHotelVerifiedOtaState([]).visible, false, 'no verified OTA login must hide the channel status badge');
assert.deepEqual(
  Array.from(systemStaticApi.buildHotelOtaStatusBadges([
    { platform: 'ctrip', level: 'partial', sessionVerified: false, storeIdentitySaved: false },
    { platform: 'meituan', level: 'partial', sessionVerified: false, storeIdentitySaved: true },
  ]), badge => badge.text),
  ['待登录'],
  'selected channels must use one compact truthful pending-login badge'
);
assert.deepEqual(
  Array.from(systemStaticApi.buildHotelOtaStatusBadges([
    { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
    { platform: 'meituan', level: 'partial', sessionVerified: false, storeIdentitySaved: true },
  ]), badge => badge.text),
  ['携程'],
  'the compact badge must show the only currently verified platform'
);
assert.deepEqual(
  Array.from(systemStaticApi.buildHotelOtaStatusBadges([
    { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
    { platform: 'meituan', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
  ]), badge => badge.text),
  ['双平台'],
  'two verified selected channels must collapse into one dual-platform badge'
);
assert.deepEqual(Array.from(systemStaticApi.buildHotelOtaStatusBadges([])), [], 'unselected channels must render no badge');
const flattenMenu = (items = []) => items.flatMap(item => [item, ...flattenMenu(item.children || [])]);
const visiblePathsFor = (currentUser) => flattenMenu(systemStaticApi.filterVisibleMenuItems(systemStaticApi.menuItemDefinitions, currentUser))
  .map(item => item.path)
  .filter(Boolean);
assert.equal(
  visiblePathsFor({ is_super_admin: false, role_id: 3, is_hotel_manager: false, permissions: { can_view_online_data: true } }).includes('users'),
  false,
  'normal external users must not see the employee-management route'
);
assert.equal(
  visiblePathsFor({ is_super_admin: false, role_id: 2, is_hotel_manager: true, permissions: { can_view_online_data: true, can_manage_own_hotels: true } }).includes('users'),
  false,
  'beta or hotel-manager users must not see the employee-management route'
);
assert.equal(
  visiblePathsFor({ is_super_admin: true, role_id: 1, is_hotel_manager: false, permissions: {} }).includes('users'),
  true,
  'super admins must still see employee management'
);

assert.match(indexHtml, /const canManageOwnHotels = \(\) =>/, '前端必须提供统一门店管理权限判断');
assert.match(indexHtml, /v-if="canManageOwnHotels\(\)"/, '门店管理按钮必须使用 canManageOwnHotels()');
assert.match(indexHtml, /openHotelManualFetchConfig\(hotel, 'ctrip'\)"[\s\S]{0,180}>手动配置<\/button>/, '门店管理账号必须能在携程卡片打开手动配置入口');
assert.match(indexHtml, /openHotelManualFetchConfig\(hotel, 'meituan'\)"[\s\S]{0,180}>手动配置<\/button>/, '门店管理账号必须能在美团卡片打开手动配置入口');
assert.match(indexHtml, /当前配置门店：[\s\S]{0,120}hotelConfigTargetText\(ctripConfigForm\)/, '携程新增配置表单必须展示当前关联门店');
assert.match(indexHtml, /当前配置门店：[\s\S]{0,120}hotelConfigTargetText\(meituanConfigForm\)/, '美团新增配置表单必须展示当前关联门店');
assert.match(indexHtml, /const hotelConfigTargetText = \(form = \{\}\) => \{[\s\S]*未关联门店[\s\S]*门店ID/, '配置表单门店展示必须明确区分未关联和仅有门店ID的状态');
assert.match(indexHtml, /form\?\.hotel_id \|\| form\?\.hotelId \|\| form\?\.system_hotel_id \|\| form\?\.systemHotelId/, '配置表单门店展示必须兼容旧配置的 system_hotel_id 字段');
assert.match(indexHtml, /getHotelNameById,[\s\S]*hotelConfigTargetText,[\s\S]*userHotelScopeText/, '配置表单门店展示函数必须暴露给 Vue 模板');
assert.doesNotMatch(indexHtml, /openHotelManualFetchConfig\(hotel, 'ctrip'\)"[\s\S]{0,220}>[\s\S]{0,20}携程配置[\s\S]{0,20}<\/button>/, '右侧操作列不应重复展示携程配置');
assert.doesNotMatch(indexHtml, /openHotelManualFetchConfig\(hotel, 'meituan'\)"[\s\S]{0,220}>[\s\S]{0,20}美团配置[\s\S]{0,20}<\/button>/, '右侧操作列不应重复展示美团配置');
assert.match(indexHtml, /onlineDataTab === 'ctrip-config' && canManageOwnHotels\(\)/, '携程配置面板不能只限制超级管理员，门店管理账号也要能补 Cookie');
assert.match(indexHtml, /v-if="canManageOwnHotels\(\)" type="button" @click="showHotelModal = false; openHotelManualFetchConfig\(hotelFormAccountHotel\(\), account\.platform\)"/, '门店弹窗内的手动配置入口必须对门店管理账号可见');
assert.doesNotMatch(hotelController, /private function currentUserCanManageHotelRecord\(HotelModel \$hotel\): bool[\s\S]*!\$this->currentUserOwnsHotel\(\$hotel\)/, 'assigned beta users must be able to manage granted hotels, not only hotels they created');
assert.match(indexHtml, /type:\s*'source',\s*sourcePath:\s*'hotels'[\s\S]*testid:\s*'nav-lean-hotel-management'/, '门店管理必须作为一级菜单直接进入 hotels 页面');
assert.doesNotMatch(indexHtml, /testid:\s*'nav-lean-hotel-knowledge'[\s\S]{0,160}children:\s*\[/, '门店管理不能再作为单子项下拉分组');
assert.match(indexHtml, />全部门店<\/span>/, 'hotel account filter should distinguish total stores from active-store metrics');
assert.match(indexHtml, /营业中 \{\{ hotelBindingOverview\.active \}\}/, 'hotel account filter should expose active-store count beside total stores');
assert.match(indexHtml, />账号待补<\/span>/, 'hotel account filter should keep pending work scoped to account collection readiness');
assert.match(indexHtml, /说明：账号待补只统计已勾选的适用渠道；未勾选的平台不展示、不计入异常。/, 'hotel account filter should keep pending work scoped to selected OTA platforms');
assert.match(indexHtml, /const todo = activeHotels\.filter\(h => hotelAccountHealthKey\(h\) === 'todo'\)\.length;/, 'todo count should only reflect account collection readiness');
assert.match(indexHtml, /适用 OTA 渠道（可多选）[\s\S]*hotelFormChannelSelected\('ctrip'\)[\s\S]*toggleHotelFormChannel\('ctrip'\)[\s\S]*携程[\s\S]*hotelFormChannelSelected\('meituan'\)[\s\S]*toggleHotelFormChannel\('meituan'\)[\s\S]*美团/, 'hotel form must expose independently selectable OTA channels');
assert.match(indexHtml, /const hotelPlatformApplicable = \(hotel = \{\}, platform = ''\) => \{[\s\S]*ctrip_only[\s\S]*key === 'ctrip'[\s\S]*meituan_only[\s\S]*key === 'meituan'/, 'OTA strategy must define which platform is applicable for account readiness');
assert.match(indexHtml, /const hotelApplicablePlatformBindingRows = \(hotel = \{\}\) => \{[\s\S]*hotelPlatformBindingRows\(hotel\)\.filter\(row => hotelPlatformApplicable\(hotel, row\.platform\)\)/, 'account health rows must filter out non-applicable OTA platforms');
assert.match(indexHtml, /const hotelAccountHealthKey = \(hotel = \{\}\) => \{[\s\S]*if \(rows\.length === 0\) return 'todo';/, 'empty applicable OTA platform rows must not be treated as ready');
assert.match(indexHtml, /const hotelNextAction = \(hotel = \{\}\) => \{[\s\S]*if \(rows\.length === 0\) \{[\s\S]*核对OTA策略/, 'empty applicable OTA platform rows must expose an explicit next action');
assert.match(indexHtml, /const hotelAccountSummary = \(hotel = \{\}\) => \{[\s\S]*if \(rows\.length === 0\) \{[\s\S]*策略待确认/, 'empty applicable OTA platform rows must not show a ready account summary');
assert.match(indexHtml, /const fullBound = activeHotels\.filter\(h => \{[\s\S]*return rows\.length > 0 && rows\.every\(row => row\.level === 'ready'\);[\s\S]*\}\)\.length;/, 'full-bound KPI must not count empty applicable platform rows as ready');
assert.match(indexHtml, /const currentSessionVerified = hasBindingContract[\s\S]*bindingContract\.current_session_verified === true[\s\S]*profile\.current_session_verified === true/, 'Profile flow must require explicit current_session_verified evidence from the binding contract or profile payload');
assert.doesNotMatch(indexHtml, /profile\.currentSessionVerified === true/, 'Profile flow compatibility must require the explicit current_session_verified field name');
assert.match(indexHtml, /const loginVerified = currentSessionVerified && statusCode === 'logged_in';/, 'Profile flow must require current-session proof and the current logged_in status together');
assert.doesNotMatch(indexHtml, /manual_login_state_verified\|logged_in/, 'Profile flow must not infer logged_in from historical status copy');
assert.doesNotMatch(indexHtml, /manual_login_state_verified\/i\.test\(currentStatus\)/, 'historical manual-login text must not authorize the Profile flow');
assert.match(indexHtml, /\{\{ hotelBindingOverview\.ctripBound \}\}\/\{\{ hotelBindingOverview\.ctripApplicable \}\}/, 'Ctrip readiness KPI must show applicable denominator');
assert.match(indexHtml, /\{\{ hotelBindingOverview\.meituanBound \}\}\/\{\{ hotelBindingOverview\.meituanApplicable \}\}/, 'Meituan readiness KPI must show applicable denominator');
assert.match(indexHtml, /v-for="badge in hotelOtaStatusBadges\(hotel\)"[^>]*data-testid="hotel-ota-strategy"/, 'store identity must expose every selected OTA channel with its truthful login state');
assert.doesNotMatch(indexHtml, />携程不适用<\/div>/, 'unselected Ctrip must be hidden instead of rendered as a placeholder');
assert.doesNotMatch(indexHtml, />美团不适用<\/div>/, 'unselected Meituan must be hidden instead of rendered as a placeholder');
assert.match(indexHtml, /v-for="account in hotelApplicablePlatformBindingRows\(hotel\)"/, 'mobile hotel platform cards must omit non-applicable OTA platforms');
assert.match(indexHtml, /v-for="account in hotelApplicablePlatformBindingRows\(hotelFormAccountHotel\(\)\)"/, 'hotel modal platform cards must omit non-applicable OTA platforms');
assert.match(indexHtml, /hotel && !hotelPlatformApplicable\(hotel, 'meituan'\)[\s\S]*status: 'not_applicable'/, 'Meituan competitor readiness must not create work for Ctrip-exclusive hotels');
assert.match(indexHtml, /\['ok', 'success', 'not_applicable'\]\.includes\(status\)/, 'not-applicable competitor readiness must not become a next-action item');
assert.doesNotMatch(indexHtml, /applyHotelQuickFilter\('competitor', '1'\)/, 'competitor ranking should not be a top-level hotel-management filter card');
assert.doesNotMatch(indexHtml, /美团竞对 \{\{ hotelCompetitorReadiness\(hotel\)\.label \}\}/, 'competitor readiness should not appear in the store identity cell');
assert.match(indexHtml, /const hotelCompetitorActionMeta = \(hotel = \{\}\) => \{[\s\S]*openHomeQuickEntry\(\{ page: 'meituan-ebooking', tab: 'meituan-ranking' \}\)/, 'competitor readiness should remain reachable through the next-action flow');
assert.match(indexHtml, /@click="refreshHotelBindingPanelLight"/, 'top-level status refresh should use the light refresh path');
assert.match(indexHtml, />受控操作<\/span>[\s\S]*>深度刷新<\/span>[\s\S]*>数据迁移<\/span>[\s\S]*>用户授权<\/span>/, 'migration and authorization actions should be grouped as controlled admin operations');
assert.doesNotMatch(indexHtml, /hotelAccountHealthText\(hotel\)/, 'store identity cell should not repeat account-health summary already shown by platform cards');
assert.doesNotMatch(indexHtml, /hotelAccountHealthClass\(hotel\)/, 'store identity cell should not keep a duplicate account-health badge');
assert.doesNotMatch(indexHtml, /待办：\$\{action\}/, 'hotel account health tag should not repeat the concrete next action in the store info cell');
assert.doesNotMatch(indexHtml, /return '待办：补齐账号\/采集'/, 'hotel account health fallback should not show vague todo copy in the store info cell');
assert.match(indexHtml, /OTA采集接入状态 · \{\{ filteredHotels\.length \}\}/, 'hotel account ledger should become OTA collection access status');
assert.match(indexHtml, />门店信息<\/th>/, 'hotel table should expose a clear store information column');
assert.doesNotMatch(indexHtml, />处理事项<\/th>/, 'hotel table should not expose a duplicated action-items column');
assert.match(indexHtml, />携程<\/th>[\s\S]*>美团<\/th>/, 'hotel table should expose Ctrip and Meituan as separate channel columns');
assert.match(indexHtml, /hotelOwnerText\(hotel\)/, 'hotel table should show the responsible person');
assert.doesNotMatch(indexHtml, /编码 \{\{ formatHotelCode\(hotelIndex \+ 1\) \}\}/, 'hotel identity should not show a duplicate visible sequence code beside database ID');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'ctrip'\)/, 'hotel table should render the Ctrip channel row directly');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'meituan'\)/, 'hotel table should render the Meituan channel row directly');
assert.match(indexHtml, /@click="openHotelPlatformCardLogin\(hotel, account\)"[\s\S]*授权登录/, 'hotel channel cards should expose an explicit platform login action');
assert.match(indexHtml, /const openHotelPlatformCardLogin = async \(hotel, account = \{\}\) => \{[\s\S]*await openHotelPlatformAccountAction\(hotel, account, \{ forceLogin: true \}\);/, 'hotel channel card login must reuse the existing platform account action as an explicit login');
assert.match(indexHtml, /const buildHotelPlatformLoginItem = \(hotel = \{\}, account = \{\}\) => \{[\s\S]*system_hotel_id: hotelId[\s\S]*profile_id: profileId/, 'hotel channel card login must carry explicit hotel-scoped Ctrip profile context');
assert.match(indexHtml, /const buildHotelPlatformLoginItem = \(hotel = \{\}, account = \{\}\) => \{[\s\S]*partner_id: partnerId[\s\S]*system_hotel_id: hotelId/, 'hotel channel card login must carry explicit hotel-scoped Meituan identity context');
assert.match(indexHtml, /openHotelPlatformCardLogin, openHotelPlatformAccountAction/, 'hotel channel card login function must be exposed to the Vue template');
assert.doesNotMatch(indexHtml, /点击登录\/刷新会话|点击卡片登录\/刷新会话/, 'hotel channel cards should not hide login behind whole-card click copy');
assert.doesNotMatch(hotelAccountSummary, /手动Cookie|采集配置|自动化采集/, 'hotel channel cards should stay a concise store-level summary');
assert.match(hotelAccountSummary, />登录状态<\/div>[\s\S]*hotelPlatformLoginText\(account\)/, 'hotel channel cards should show current login state');
assert.match(hotelAccountSummary, />最近采集<\/div>[\s\S]*account\.captureStatusText/, 'hotel channel cards should show the recent collection result');
assert.match(hotelAccountSummary, /下一步：\{\{ account\.nextActionText/, 'hotel channel cards should keep the concrete next action');
assert.match(indexHtml, /data-testid="platform-account-advanced-tools"/, 'technical account configuration should remain available in the platform account detail page');
assert.match(indexHtml, /@click="openHotelSyncLogs\(hotel, account\.platform\)"[\s\S]*采集日志/, 'hotel channel cards should expose collection logs with a clear label');
assert.doesNotMatch(indexHtml, /@click="openHotelSyncLogs\(hotel, account\.platform\)"[\s\S]{0,140}查看日志/, 'hotel channel log action should not use vague view-log copy');
assert.match(indexHtml, /平台门店：\{\{ account\.accountStoreText \|\| '-' \}\}/, 'hotel channel cards should show the mapped platform store');
assert.doesNotMatch(indexHtml, /处理：\{\{ hotelNextAction\(hotel\)\.text \}\}/, 'operation column should not duplicate platform-card next actions');
assert.doesNotMatch(indexHtml, />维护账号<\/button>/, 'operation column should not duplicate platform-card account maintenance');
assert.doesNotMatch(indexHtml, /@click(?:\.stop)?="openHotelNextAction\(hotel\)"/, 'hotel management should not keep a duplicated next-action button outside platform cards');
assert.doesNotMatch(indexHtml, /hotelIssueRows\(hotel\)/, 'hotel table should rely on channel cards and the action column instead of a duplicated issues column');
assert.doesNotMatch(indexHtml, />总<\/span>/, 'hotel account filter should not use ambiguous single-character total copy');
assert.doesNotMatch(indexHtml, />待办<\/span>/, 'hotel account filter should not use unclear todo copy');
assert.doesNotMatch(indexHtml, />竞对可信<\/span>/, 'hotel account filter should not use unclear competitor-trust copy');
assert.doesNotMatch(indexHtml, /todo:\s*'待办'/, 'hotel account health text should not use unclear todo copy');
assert.doesNotMatch(indexHtml, /user\?\.role_id\s*<=\s*2/, '用户管理入口不能继续用 role_id <= 2 放开内测用户');

assert.match(userModel, /public function canManageUser\(\): bool[\s\S]*return \$this->isSuperAdmin\(\);/, '用户管理必须仅管理员可用');
assert.match(userModel, /public function canManageOwnHotels\(\): bool[\s\S]*new PermissionService\(\)\)->roleAllows\(\$this, 'can_manage_own_hotels'\)/, 'hotel creation must follow the centralized runtime role policy instead of role-id shortcuts');
assert.match(userModel, /public function isBetaUser\(\): bool[\s\S]*Role::HOTEL_MANAGER/, 'beta-user checks must recognize custom level-2 roles consistently with the front-end issue guide');
assert.match(userModel, /private function enabledRole\(\): \?Role[\s\S]*Role::STATUS_ENABLED/, 'role identity helpers must require an enabled role record');
assert.doesNotMatch(userModel, /public function isHotelManager\(\): bool[\s\S]*if \(\(int\)\$this->role_id === Role::HOTEL_MANAGER\) \{\s*return true;\s*\}/, 'hotel-manager identity must not bypass disabled role records by fixed role id');
assert.doesNotMatch(userModel, /public function isBetaUser\(\): bool[\s\S]*if \(\(int\)\$this->role_id === Role::BETA_USER\) \{\s*return true;\s*\}/, 'beta identity must not bypass disabled role records by fixed role id');
assert.doesNotMatch(userModel, /public function isStaff\(\): bool[\s\S]*if \(\(int\)\$this->role_id === Role::HOTEL_STAFF\) \{\s*return true;\s*\}/, 'staff identity must not bypass disabled role records by fixed role id');
assert.match(userModel, /public function isSuperAdmin\(\): bool[\s\S]*role_id === Role::SUPER_ADMIN[\s\S]*role->hasPermission\('all'\)[\s\S]*Role::BETA_USER[\s\S]*Role::NORMAL_USER[\s\S]*roleName === 'admin'[\s\S]*roleLevel === 1/, 'super-admin checks must not promote beta or normal roles just because a dirty role contains all');
assert.match(roleModel, /'user\.role_change'\s*=>\s*\['can_manage_users'\][\s\S]*'can_manage_users'\s*=>\s*\['user\.role_change'\]/, 'role permission aliases must keep legacy can_manage_users and user.role_change interchangeable');
assert.match(userController, /public function index\(\): Response[\s\S]*canManageUser\(\)/, '用户列表接口必须检查用户管理权限');

assert.match(userController, /public function roles\(\): Response[\s\S]*canManageUser\(\)/, 'user role metadata endpoint must require user-management permission');

assert.match(authController, /private const TOKEN_TTL_SECONDS = 259200;/, 'website login token must expire after 72 hours');
assert.match(authMiddleware, /private const TOKEN_MAX_AGE_SECONDS = 259200;/, 'auth middleware must reject tokens older than the 72-hour session limit');
assert.match(authMiddleware, /if \(!is_array\(\$tokenData\)\) \{\s*return false;\s*\}/, 'auth middleware must preserve legacy scalar token cache entries until cache TTL');
assert.match(authMiddleware, /\$createdAt = \(int\)\(\$tokenData\['created_at'\] \?\? 0\);[\s\S]*if \(\$createdAt <= 0\) \{\s*return false;\s*\}/, 'auth middleware must preserve legacy token payloads without created_at until cache TTL');
assert.match(cookieEndpointConcern, /recordPublicEndpointFailure\('receive_cookies', 'legacy_bookmarklet_disabled', 410/, 'legacy receive-cookies endpoint must be disabled instead of accepting current-session tokens');
assert.match(cookieEndpointConcern, /return \$this->corsError\('旧版 Cookie 书签入口已禁用[\s\S]*410\)/, 'legacy receive-cookies endpoint must return a traceable 410 disabled response');
assert.doesNotMatch(cookieEndpointConcern, /UserModel::find\(\$userId\)|cache\('token_' \. \$token\)|Authorization Bearer token from current login session/, 'disabled receive-cookies endpoint must not save cookies from the current login token');
assert.match(indexHtml, /const writeAuthToken = \(value\) =>[\s\S]*sessionStorage\.setItem\(AUTH_TOKEN_STORAGE_KEY, normalized\)[\s\S]*localStorage\.removeItem\(AUTH_TOKEN_STORAGE_KEY\)/, 'front-end must keep auth tokens in sessionStorage and clear legacy localStorage tokens');
assert.doesNotMatch(indexHtml, /localStorage\.setItem\('token'/, 'front-end must not persist auth tokens in localStorage');
assert.match(authController, /private function buildLoginNotices\(User \$user, array \$permittedHotels\): array/, 'auth payload must include login notices');
assert.match(authController, /!\$user->isBetaUser\(\)/, 'hotel binding deadline notice must target beta users');
assert.match(authController, /未绑定或未分配的门店将无法查看/, 'beta notice must state that unbound stores will not be visible');
assert.doesNotMatch(authController, /BETA_HOTEL_BINDING_CUTOFF_DATE|2026-07-05|请在 \{\$deadline\} 前|之后将无法查看门店数据/, 'beta notice must not keep the expired binding deadline copy');
assert.match(indexHtml, /const showAuthNotices = \(payload = \{\}\) =>/, 'front-end must render auth notices from login and auth info payloads');
assert.match(indexHtml, /setTimeout\(\(\) => showAuthNotices\(res\.data\), 600\)/, 'login success should show beta binding notice after the welcome message');
assert.match(authController, /cache\('token_' \. \$token, \$tokenData, self::TOKEN_TTL_SECONDS\)/, 'token cache TTL must use the 72-hour constant');
assert.match(authController, /'expires_in'\s*=>\s*self::TOKEN_TTL_SECONDS/, 'login response must expose the 72-hour token expiry');
assert.match(hotelScopeService, /private function ownedOrGrantedHotelIds\(User \$user, \?string \$capability = null\): array/, 'non-super hotel scope must be centralized');
assert.match(hotelScopeService, /\$this->primaryHotelIds\(\$user\)[\s\S]*\$this->ownedHotelIds\(\$user\)[\s\S]*\$this->grantedHotelIds\(\$user, \$capability\)/, 'non-super users must only see primary, owned, or explicitly granted hotels');
assert.doesNotMatch(hotelScopeService, /if \(\$this->isVipUser\(\$user\)\)[\s\S]{0,160}return \$this->ownedHotelIds\(\$user\);/, 'VIP role alone must not bypass explicit hotel scope');
assert.match(hotelScopeService, /'hotel\.delete' => \['can_edit'\]/, 'assigned hotel deletion must still pass through the per-hotel can_edit permission layer');
assert.match(userController, /private function syncUserHotelPermissions\(UserModel \$targetUser, array \$hotelIds, Role \$targetRole, array \$hotelTenantIds = \[\]\): void[\s\S]*\$payload\['tenant_id'\] = \$tenantId;/, 'super admin user saves must sync user_hotel_permissions with authoritative tenant ids');
assert.match(userController, /private function normalizeAssignedHotelIds\(array \$data\): array/, 'user API must accept multi-hotel assignments');
assert.match(userController, /\$data\['hotel_ids'\]\s*=/, 'user API responses must expose assigned hotel ids for editing');
assert.match(userController, /private function validateExternalUserIssueBoundary\(Role \$role, array \$hotelIds\): \?Response/, 'user API must validate external-account issue boundaries');
assert.match(userController, /private function isNormalExternalRole\(Role \$role\): bool/, 'user API must identify normal external roles explicitly');
assert.match(userController, /getAttr\('level'\) >= Role::HOTEL_STAFF/, 'user API must treat staff-level roles as normal external roles');
assert.match(userController, /normalExternalUnsafeCapabilities\(\$role->getPermissionList\(\)\)/, 'normal external user issuance must reject roles with denied high-risk permissions at the API layer');
assert.match(userController, /\$hotelIds = \[\(int\)\$hotelId\];[\s\S]{0,240}\$issueBoundaryResponse = \$this->validateExternalUserIssueBoundary\(\$targetRole, \$hotelIds\);/, 'non-super user issuance must reuse the normal external OTA collection boundary');
assert.match(userController, /普通用户角色不能包含 OTA 采集权限或其他高风险权限/, 'normal external user API rejection must explain the unsafe high-risk permissions');
assert.match(userController, /普通用户必须先分配门店/, 'normal external users must be blocked without an assigned hotel scope');
assert.match(roleController, /private function validateRolePermissionBoundary\(string \$roleName, array \$permissions, \?Role \$existingRole = null, \?int \$roleLevel = null\): \?Response/, 'role API must validate external role permission boundaries with the submitted or existing role identity');
assert.match(roleController, /validateBuiltInExternalRoleIdentity\(\$role, \$data\)/, 'role update must reject identity changes for built-in external roles');
assert.match(roleController, /private function validateBuiltInExternalRoleIdentity\(Role \$role, array \$data\): \?Response[\s\S]*Role::BETA_USER[\s\S]*Role::NORMAL_USER[\s\S]*内置外发角色的标识和等级不能修改/, 'built-in beta and normal roles must keep immutable names and levels');
assert.match(roleController, /validateRolePermissionBoundary\(\(string\)\$data\['name'\], \$permissions, null, \$nextLevel\)/, 'role create must apply normal-user boundaries to submitted level-3 roles');
assert.match(roleController, /validateRolePermissionBoundary\(\$nextName, \$permissions, \$role, \$nextLevel\)/, 'role update must preserve normal-user boundaries after role renaming or level changes');
assert.match(roleController, /private function isNormalExternalRoleIdentity\(string \$roleName, \?Role \$existingRole = null, \?int \$roleLevel = null\): bool[\s\S]*\$roleLevel !== null && \$roleLevel >= Role::HOTEL_STAFF[\s\S]*Role::NORMAL_USER[\s\S]*normal_user/, 'role API must identify staff-level roles by submitted level, current name, existing id, or existing name');
assert.match(roleController, /isNormalExternalRoleIdentity\(\$roleName, \$existingRole, \$roleLevel\)[\s\S]*normalExternalUnsafeCapabilities\(\$permissions\)/, 'normal_user role saves must reject denied high-risk permissions');
assert.match(roleController, /普通用户角色不能包含 OTA 采集权限或其他高风险权限/, 'normal_user role API rejection must explain the unsafe high-risk permissions');
assert.match(permissionService, /NORMAL_EXTERNAL_DENIED_CAPABILITIES[\s\S]*'hotel\.update'[\s\S]*'ota\.collect'[\s\S]*'ota\.delete'[\s\S]*'ota\.export'[\s\S]*'report\.export'[\s\S]*'ai\.execute'/, 'runtime permission service must centralize denied high-risk capabilities for normal external users');
assert.match(permissionService, /'can_manage_users'\s*=>\s*'user\.role_change'/, 'runtime permission service must normalize user-management payload grants to the protected role-change capability');
assert.match(permissionService, /isNormalExternalUser\(\$user\)[\s\S]*isNormalExternalCapabilityDenied\(\$capability\)/, 'runtime permission service must apply the centralized normal external denial set');
assert.match(permissionService, /getAttr\('name'\) === 'normal_user'/, 'runtime permission service must recognize normal external roles by role name as well as id');
assert.match(permissionService, /getAttr\('level'\) >= Role::HOTEL_STAFF/, 'runtime permission service must recognize staff-level roles as normal external users');
assert.match(authController, /\$allows = fn\(string \$permission\): bool => \$user->hasPermission\(\$permission\) && \$this->roleAllows\(\$user, \$permission\);/, 'login payload must gate permission booleans through the central runtime role policy');
assert.match(authController, /getAttr\('level'\) >= Role::HOTEL_STAFF/, 'registration and login helpers must treat staff-level roles as normal external users');
assert.match(authController, /'can_manage_users'\s*=>\s*\$user->canManageUser\(\) && \$this->roleAllows\(\$user,\s*'can_manage_users'\)/, 'login payload must not expose user-management grants unless runtime role policy also allows it');
assert.match(authController, /'can_fetch_online_data'\s*=>\s*\$allows\('can_fetch_online_data'\)/, 'login payload must not expose legacy OTA collection grants unless runtime role policy also allows it');
assert.match(authController, /'can_delete_online_data'\s*=>\s*\$allows\('can_delete_online_data'\)/, 'login payload must not expose legacy OTA deletion grants unless runtime role policy also allows it');
assert.match(authController, /'can_manage_own_hotels'\s*=>\s*\$user->canManageOwnHotels\(\) && \$this->roleAllows\(\$user,\s*'can_manage_own_hotels'\)/, 'login payload must not expose legacy hotel-management grants to normal external users');
assert.match(authController, /private function roleAllows\(User \$user, string \$permission\): bool\s*\{\s*return \(new PermissionService\(\)\)->roleAllows\(\$user, \$permission\);\s*\}/, 'auth payload permission helpers must reuse the central permission service');
assert.match(userController, /private function validateUsernamePolicy\(string \$username\): \?string/, 'admin-created users must share the public registration username policy');
assert.match(userController, /\^\[A-Za-z0-9_\]\{3,50\}\$/, 'admin-created users must allow underscores and the same length as public registration');
assert.doesNotMatch(userController, /alphaNum\|min:3\|max:20/, 'admin-created users must not keep the stricter legacy alphaNum username rule');
assert.match(userController, /private function canEditUserUsername\(UserModel \$targetUser\): bool[\s\S]*\$this->currentUser->isSuperAdmin\(\) && \$targetUser->isBetaUser\(\)/, 'backend username edits must be limited to super-admin edits of existing beta users');
assert.match(userController, /if \(!\$this->canEditUserUsername\(\$user\)\) \{[\s\S]*return \$this->error\([^;]+403\);[\s\S]*\$usernameError = \$this->validateUsernamePolicy\(\$nextUsername\)/, 'user update must enforce the beta username edit boundary before saving a changed username');
assert.match(indexHtml, /const canEditUserUsername = computed\(\(\) => \{[\s\S]*!userForm\.value\?\.id[\s\S]*user\.value\?\.is_super_admin[\s\S]*selectedUserRoleGuide\.value\?\.key === 'beta_user'[\s\S]*\}\);/, 'super admin must be able to edit existing beta-user usernames');
assert.match(indexHtml, /:disabled="!canEditUserUsername"/, 'user modal username input must use the beta-user editability guard');
assert.match(initDatabaseCommand, /login_count INT UNSIGNED DEFAULT 0/, 'MySQL init command must create users.login_count used by login');
assert.match(initDatabaseCommand, /login_count INTEGER DEFAULT 0/, 'SQLite init command must create users.login_count used by login');
assert.match(indexHtml, /v-model="userForm\.hotel_ids"/, 'user modal must edit multiple hotel assignments');
assert.match(indexHtml, /const userHotelScopeText = \(u = \{\}\) =>/, 'user list must show effective hotel scope');
assert.match(indexHtml, />上次登录<\/th>[\s\S]*userLastLoginText\(u\)/, 'user management table must show the last login time after status');
assert.match(indexHtml, /const userLastLoginText = \(u = \{\}\) =>/, 'user management must format empty last-login values explicitly');
const userManagementToolbarSlice = indexHtml.slice(
  indexHtml.indexOf('<!-- 用户统计卡片 -->'),
  indexHtml.indexOf('<!-- 员工数据表格 -->')
);
const rolePermissionCardsSliceStart = indexHtml.indexOf('<!-- 角色权限说明卡片 -->');
const rolePermissionCardsSlice = indexHtml.slice(
  rolePermissionCardsSliceStart,
  indexHtml.indexOf('<div class="card">', rolePermissionCardsSliceStart)
);
assert.match(indexHtml, /const userSummary = computed\(\(\) =>/, 'user management summary must be derived from current users instead of fixed role IDs');
assert.match(indexHtml, /beta:\s*rows\.filter\(u => userRoleIdentityKey\(u\) === 'beta_user'\)\.length/, 'user management summary must count beta issue accounts by role identity');
assert.match(indexHtml, /normal:\s*rows\.filter\(u => userRoleIdentityKey\(u\) === 'normal_user'\)\.length/, 'user management summary must count normal external accounts by role identity');
assert.ok(indexHtml.includes("match(/^VIP(\\d+)$/i)"), 'user management must parse VIP sequence numbers from usernames');
assert.match(indexHtml, /const compareUserDisplayOrder = \(a = \{\}, b = \{\}\) => \{[\s\S]*userRoleIdentityKey\(a\) === 'admin'[\s\S]*userVipSequenceNumber\(a\)[\s\S]*return vipA - vipB/, 'user management must keep admins first and sort VIP accounts by numeric sequence');
assert.match(indexHtml, /\.sort\(compareUserDisplayOrder\)/, 'filtered user list must use the VIP sequence display order');
assert.match(rolePermissionCardsSlice, /内测用户[\s\S]*可信内测方/, 'role permission cards must name the beta account audience clearly');
assert.match(rolePermissionCardsSlice, /普通用户[\s\S]*必须分配门店/, 'role permission cards must name normal external users and their hotel-scope requirement');
assert.doesNotMatch(rolePermissionCardsSlice, />门店管理员<\/div>|>店员<\/div>/, 'role permission cards must not keep ambiguous legacy role labels for external issuance');
assert.match(indexHtml, /const roleIssueGuideCards = computed\(\(\) =>/, 'user management must expose beta/normal role issue guide cards');
assert.match(indexHtml, /user-admin-static\.js\?v=20260711-vip-credential-helper/, 'user admin issue helpers must load as a dedicated static bundle');
assert.match(indexHtml, /const requireUserAdminStatic = \(key\) =>/, 'index must require user admin static helpers explicitly');
assert.match(userAdminStatic, /window\.SUXI_USER_ADMIN_STATIC = \(\(\) =>/, 'user admin static helpers must expose a stable bundle namespace');
assert.match(userAdminStatic, /handoffType:\s*'内测发放'/, 'beta issue profile must expose an explicit handoff type');
assert.match(userAdminStatic, /handoffType:\s*'普通外发'/, 'normal issue profile must expose an explicit handoff type');
assert.match(userAdminStatic, /dataBoundary:\s*'仅授权门店的 OTA 渠道数据和内测功能，不代表全酒店经营数据。'/, 'beta issue profile must show the OTA-only data boundary');
assert.match(userAdminStatic, /dataBoundary:\s*'仅授权门店的 OTA 渠道只读数据；不代表全酒店经营数据，也不开放采集执行。'/, 'normal issue profile must show the read-only OTA boundary');
assert.match(indexHtml, /@click="applyUserSummaryFilter\('total'\)"[\s\S]*@click="applyUserSummaryFilter\('active'\)"[\s\S]*@click="applyUserSummaryFilter\('beta'\)"[\s\S]*@click="applyUserSummaryFilter\('normal'\)"[\s\S]*@click="applyUserSummaryFilter\('disabled'\)"[\s\S]*@click="applyUserSummaryFilter\('unassigned'\)"/, 'user summary cards must be clickable filters');
assert.match(indexHtml, /const userSummaryCardClass = \(key = ''\) =>/, 'user summary cards must expose an active filter style');
assert.match(indexHtml, /const applyUserSummaryFilter = \(key = ''\) =>/, 'user summary cards must share a filter handler');
assert.doesNotMatch(indexHtml, />外发角色矩阵<\/h3>/, 'user management must not show the external issuance matrix block');
assert.match(indexHtml, /初始密码请通过单独安全渠道发送/, 'copied issuance guidance must keep passwords outside the normal account message');
assert.match(userAdminStatic, /const rolePermissionTags = \(profile = \{\}\) =>/, 'role issue cards must expose permission tags for beta and normal account issuance');
assert.match(userAdminStatic, /const normalExternalDeniedPermissionGroups = \[/, 'role issue cards must use an explicit denied-capability checklist for normal external accounts');
assert.match(userAdminStatic, /const roleUnsafeExternalCapabilityLabels = \(role = \{\}\) =>/, 'role issue cards must translate unsafe normal-user permissions into reviewable labels');
assert.match(userAdminStatic, /const issueStatusForRoleProfile = \(profile = \{\}\) =>/, 'role issue cards must expose a clear issuance conclusion');
assert.match(userAdminStatic, /const buildUserIssueChecklistRows = \(profile = \{\}, assignedHotelIds = \[\], status = 1\) =>/, 'user admin static bundle must own the issuance checklist row contract');
assert.match(userAdminStatic, /const validateUserIssueProfile = \(profile = \{\}, assignedHotelIds = \[\]\) =>/, 'user admin static bundle must own external account issuance validation');
assert.match(userAdminStatic, /const userIssueStatusFromProfile = \(profile = \{\}, blocker = ''\) =>/, 'user admin static bundle must own row-level issuance status copy');
assert.match(userAdminStatic, /普通用户角色含高风险权限：\$\{unsafeExternalCapabilityLabels\.join\('、'\)\}/, 'normal-user issuance blockers must name the risky permission groups');
assert.match(userAdminStatic, /roleIssueProfile,[\s\S]*rolePermissionTags,[\s\S]*withRolePermissionTags[\s\S]*buildUserIssueChecklistRows,[\s\S]*validateUserIssueProfile,[\s\S]*userIssueStatusFromProfile/, 'user admin static bundle must export role issue helpers used by the Vue setup');
assert.match(indexHtml, /withRolePermissionTags\(roleIssueProfile\(role\)\)/, 'role issue cards must attach permission tags to role profiles');
assert.match(indexHtml, /filter\(item => \['beta_user', 'normal_user'\]\.includes\(item\.profile\?\.key\)\)/, 'role issue cards must include level-based beta and normal profiles instead of fixed role IDs only');
assert.doesNotMatch(indexHtml, /filter\(role => \['beta_user', 'normal_user'\]\.includes\(String\(role\?\.name \|\| ''\)\.trim\(\)\) \|\| \[2, 3\]\.includes\(Number\(role\?\.id \|\| 0\)\)\)/, 'role issue cards must not depend only on legacy role id/name pairs');
assert.match(userAdminStatic, /roleId === 3 \|\| roleName === 'normal_user' \|\| level >= 3/, 'front-end role profiles must treat staff-level roles as normal external accounts');
assert.match(indexHtml, /const issueRoleIdForFilter = \(key = ''\) =>/, 'user summary role filters must resolve beta and normal roles through the issue profile');
assert.match(indexHtml, /filterUserRoleId\.value = issueRoleIdForFilter\('beta_user'\)/, 'beta summary card must filter beta issue accounts');
assert.match(indexHtml, /filterUserRoleId\.value = issueRoleIdForFilter\('normal_user'\)/, 'normal summary card must filter normal external accounts');
assert.match(indexHtml, /filterUserHotelId\.value = 'unassigned'/, 'unassigned summary card must filter users without hotel scope');
assert.match(indexHtml, /selectedUserRoleGuide\.denied\]\.filter\(Boolean\)\.join\('\\n'\)/, 'user modal must retain denied capabilities in the compact role summary title');
assert.match(indexHtml, /发送前确认：\$\{profile\?\.sendChecklist \|\| '-'\}/, 'copied issuance guidance must include the pre-send checklist');
assert.match(userAdminStatic, /高风险待修/, 'normal external role tags must flag high-risk permission blockers clearly');
assert.match(indexHtml, /:title="userIssueBlockingReasons\.length \? userIssueBlockingReasons\.join\('；'\) : '检查通过'"/, 'compact user modal must expose blocker detail on the issuance check row');
assert.match(indexHtml, /:disabled="userIssueBlockingReasons\.length > 0"/, 'unsafe external role states must not present an enabled copy action');
assert.match(userAdminStatic, /if \(profile\.issueBlocked\) return '先修角色权限'/, 'blocked normal-user role cards must direct admins to repair the role first');
assert.match(indexHtml, />权限摘要<\/th>[\s\S]*rolePermissionTags\(roleIssueProfile\(r\)\)/, 'role management table must show reviewable permission summary tags');
assert.match(indexHtml, /已选 \{\{ rolePermissionList\(r\)\.length \}\} 项权限/, 'role management table must show the selected permission count');
assert.match(indexHtml, /selectedUserRoleGuide/, 'user modal must show the selected role issue boundary');
assert.match(indexHtml, /selectedUserRoleGuide\.permissionTags/, 'user modal must show selected role permission tags');
assert.match(indexHtml, /const userIssueChecklistRows = computed\(\(\) =>/, 'user modal must keep the issuance checklist validation contract before external accounts are saved');
assert.match(indexHtml, /:title="userIssueBlockingReasons\.length \? userIssueBlockingReasons\.join\('；'\) : '检查通过'"/, 'user modal must expose compact issuance blockers without printing the full checklist');
assert.match(indexHtml, /const userRoleBoundaryText = \(u = \{\}\) =>/, 'user table keeps per-account issue boundary text available for non-table guidance');
assert.doesNotMatch(indexHtml, /userRoleBoundaryText\(u\)/, 'user table role column must not render per-account issue boundary text');
assert.doesNotMatch(indexHtml, />发放状态<\/th>/, 'user management table must not render the issuance status column');
assert.doesNotMatch(indexHtml, /userIssueStatus\(u\)/, 'user management table must not render row-level issuance status badges');
assert.match(indexHtml, /const userIssueStatus = \(u = \{\}\) =>/, 'issuance status helper must stay derived from the same blocker rules as copy guidance');
assert.match(indexHtml, /existingUserIssueGuideBlocker\(u\)[\s\S]*userIssueStatusFromProfile\(profile, blocker\)/, 'existing user copy guidance must surface blocker details instead of allowing blind external sends');
assert.match(indexHtml, /userRoleBoundaryText, userIssueStatus, selectedUserRoleGuide/, 'issuance helper state must remain returned for non-table guidance');
assert.match(indexHtml, /const validateUserIssueBeforeSave = \(data = \{\}, assignedHotelIds = \[\]\) =>/, 'user saves must validate issuance boundaries before calling the API');
assert.match(userAdminStatic, /profile\.key === 'normal_user' && profile\.canCollectOta/, 'normal-user issuance must flag OTA collection as unsafe for external accounts');
assert.match(userAdminStatic, /profile\.requiresHotelAssignment && normalizedHotelIds\.length === 0/, 'external account issuance must block missing hotel scope');
assert.match(indexHtml, /const issueError = validateUserIssueBeforeSave\(data, assignedHotelIds\)/, 'user save must run the issuance validator before request submission');
assert.match(indexHtml, /const buildUserIssueGuideText = \(\) =>/, 'user modal must build a copyable issuance handoff message');
assert.ok(indexHtml.includes("`发放类型：${profile?.handoffType || profile?.title || '-'}`"), 'copied issuance guidance must include the handoff type');
assert.ok(indexHtml.includes("`数据范围：${profile?.dataBoundary || '-'}`"), 'copied issuance guidance must include the data boundary');
assert.match(indexHtml, /const copyUserIssueGuide = \(\) =>/, 'user modal must expose a safe copy action for issuance guidance');
assert.match(indexHtml, /applyUserRoleQuickFilter\(card\)/, 'user management must support one-click beta and normal account filtering');
assert.match(indexHtml, /const resetUserFilters = \(\) =>/, 'user management filters must be reset through a shared helper');
assert.match(indexHtml, /@click="openUserAuthorization\(\)"/, 'hotel management header must expose a super-admin user authorization entry');
assert.match(indexHtml, /@click="openUserAuthorization\(hotel\)"/, 'hotel rows must expose per-store user authorization entry');
assert.match(indexHtml, /@click="openHotelMergeModal\(\)"/, 'hotel management header must expose a super-admin hotel data merge entry');
assert.match(indexHtml, /v-if="showHotelMergeModal"/, 'hotel data merge must use an explicit preview and execution modal');
assert.match(indexHtml, /hotelMergeFlowState\.step[\s\S]*选择门店[\s\S]*核对预览[\s\S]*确认执行/, 'hotel merge modal must expose a clear three-step flow');
assert.match(indexHtml, /不迁移 Cookie、Profile 或登录凭证/, 'hotel merge modal must disclose the OTA credential boundary');
assert.match(indexHtml, /:disabled="!hotelMergeFlowState\.can_preview \|\| hotelMergeLoading \|\| hotelMergeExecuting"/, 'hotel merge preview action must be enabled only after valid store selection');
assert.match(indexHtml, /hotelMergeFlowState\.preview_label/, 'hotel merge preview action must explain the next step');
assert.match(indexHtml, /hotelMergeFlowState\.execute_hint/, 'hotel merge disabled execution state must explain what is missing');
assert.match(indexHtml, /ref="hotelMergeConfirmationInput"/, 'hotel merge confirmation field must expose a focus target');
assert.match(indexHtml, /hotelMergeConfirmationInput\.value\?\.scrollIntoView/, 'hotel merge preview completion must reveal the confirmation field');
assert.match(indexHtml, /\/hotels\/merge-preview\?source_hotel_id=/, 'hotel data merge preview must call the dedicated preview endpoint');
assert.match(indexHtml, /\/hotels\/merge-execute/, 'hotel data merge execution must call the dedicated execute endpoint');
assert.match(indexHtml, /online_daily_data\.hotel_id[\s\S]*OTA 平台酒店ID，不会被改写/, 'hotel data merge UI must state that OTA platform hotel_id is not migrated');
assert.match(indexHtml, /const createHotelMergeForm = requireSystemStatic\('createHotelMergeForm'\)/, 'hotel merge form defaults must be owned by system-static.js');
assert.match(indexHtml, /const hotelMergeCanExecuteStatic = requireSystemStatic\('hotelMergeCanExecute'\)[\s\S]*computed\(\(\) => hotelMergeCanExecuteStatic\(\{[\s\S]*preview: hotelMergePreview\.value,[\s\S]*form: hotelMergeForm\.value/, 'hotel merge execution must delegate current-preview confirmation checks to system-static.js');
assert.match(systemStatic, /const hotelMergeCanExecute = \(\{ preview = null, form = \{\} \} = \{\}\) => \{[\s\S]*preview\?\.source_hotel\?\.id[\s\S]*preview\?\.target_hotel\?\.id[\s\S]*actual === expected/, 'hotel merge static helper must require a current preview and exact confirmation text');
assert.match(indexHtml, /hotelMergeSkippableConflictCount[\s\S]*\{\{ hotelMergeSkippableConflictCount \}\}/, 'hotel merge UI must disclose the duplicate user-permission conflict policy');
assert.match(systemStatic, /const hotelMergeSkippableConflictCount = \(preview = null\) => \{[\s\S]*skippable_conflict_count/, 'hotel merge static helper must count skippable duplicate user-permission conflicts');
assert.match(routes, /Route::get\('\/merge-preview', 'Hotel\/mergePreview'\);[\s\S]*Route::post\('\/merge-execute', 'Hotel\/mergeExecute'\);[\s\S]*Route::get\('\/:id', 'Hotel\/read'\);/, 'hotel merge routes must be registered before the dynamic hotel id route');
assert.match(hotelController, /public function mergePreview\(\): Response[\s\S]*\$this->checkPermission\(true\)/, 'hotel merge preview must be super-admin only');
assert.match(hotelController, /public function mergeExecute\(\): Response[\s\S]*\$this->checkPermission\(true\)/, 'hotel merge execute must be super-admin only');
assert.match(hotelController, /expectedConfirmation = \$service->confirmationText\(\$sourceHotelId, \$targetHotelId\)/, 'hotel merge execute must derive its confirmation text from the service contract');
assert.match(hotelController, /execute\(\$sourceHotelId,\s*\$targetHotelId,\s*\$actualConfirmation,\s*\$deactivateSource\)/, 'hotel merge execute must pass confirmation text into the service');
assert.match(hotelDataMergeService, /'table'\s*=>\s*'online_daily_data',\s*'column'\s*=>\s*'system_hotel_id'/, 'hotel data merge must move online daily data by system hotel scope');
assert.doesNotMatch(hotelDataMergeService, /'table'\s*=>\s*'online_daily_data',\s*'column'\s*=>\s*'hotel_id'/, 'hotel data merge must not rewrite OTA platform hotel_id');
assert.match(hotelDataMergeService, /'online_daily_data_hotel_id_kept'\s*=>\s*true/, 'hotel data merge preview must expose the OTA platform hotel id preservation rule');
assert.match(hotelDataMergeService, /'tenant_id_retargeted'\s*=>\s*true/, 'hotel data merge preview must expose tenant_id retargeting');
assert.match(hotelDataMergeService, /\$payload\['tenant_id'\]\s*=\s*\$targetTenantId/, 'hotel data merge must retarget tenant_id when available');
assert.match(hotelDataMergeService, /'expected_update_rows'/, 'hotel data merge preview must separate expected updates from duplicate user grants');
assert.match(hotelDataMergeService, /'merged_conflict_total'/, 'hotel data merge execution must report merged duplicate user grants');
assert.match(hotelDataMergeService, /private function isSkippableConflictPlan\(array \$plan\): bool[\s\S]*user_hotel_permissions[\s\S]*hotel_id/, 'hotel data merge may only auto-merge duplicate user hotel permissions');
assert.match(hotelDataMergeService, /'merges_duplicate_user_permissions'\s*=>\s*true/, 'hotel data merge preview must expose the duplicate permission merge policy');
assert.match(hotelDataMergeService, /merge_then_remove_source_duplicate_permission/, 'hotel data merge duplicate user grants must merge before removing source duplicates');
assert.match(hotelDataMergeService, /duplicatePermissionMergeAssignments[\s\S]*GREATEST\(COALESCE\(t\./, 'hotel data merge duplicate user grants must preserve stronger permission flags');
assert.doesNotMatch(hotelDataMergeService, /skip_source_duplicate_permission/, 'hotel data merge must not treat duplicate user grants as a silent skip');
assert.match(indexHtml, /expected_update_rows/, 'hotel data merge UI must show effective update rows separately from duplicate grants');
assert.match(indexHtml, /hotelMergeSuccessMessage\(res\.data \|\| \{\}\)/, 'hotel data merge UI must delegate execution success copy to system-static.js');
assert.match(systemStatic, /const hotelMergeSuccessMessage = \(data = \{\}\) => \{[\s\S]*merged_conflict_total/, 'hotel merge static helper must report merged duplicate grants after execution');
assert.match(systemStatic, /const createHotelMergeForm = \(\) => \(\{[\s\S]*deactivate_source:\s*false/, 'hotel data merge UI must not deactivate the source hotel by default');
assert.match(indexHtml, /const pendingUserAuthorizationHotel = ref\(null\);/, 'hotel authorization must keep the target store as context instead of hiding it in a list filter');
assert.match(indexHtml, /const issueRoleIdForFilter = \(key = ''\) => \{[\s\S]*card\?\.key === normalizedKey[\s\S]*roleIssueProfile\(role\)\.key === normalizedKey[\s\S]*const betaUserRoleIdForFilter = \(\) => issueRoleIdForFilter\('beta_user'\)/, 'hotel authorization must locate beta users by role profile');
assert.match(indexHtml, /const openUserAuthorization = async \(hotel = null\) => \{[\s\S]*user\.value\?\.is_super_admin[\s\S]*filterUserHotelId\.value = '';[\s\S]*pendingUserAuthorizationHotel\.value = hotel\?\.id[\s\S]*currentPage\.value = 'users'[\s\S]*loadUsers\(\)[\s\S]*loadRoles\(\)[\s\S]*loadHotels\(\)[\s\S]*filterUserRoleId\.value = betaRoleId/, 'hotel authorization entry must navigate to existing beta users instead of filtering users already assigned to the store');
assert.match(indexHtml, /pendingUserAuthorizationHotel[\s\S]*请选择已有内测用户点“编辑”/, 'user management must tell admins to assign stores through existing beta users');
assert.match(indexHtml, /const pendingHotelId = pendingUserAuthorizationHotel\.value\?\.id[\s\S]*userRoleIssueProfile\(u\)\?\.key === 'beta_user'[\s\S]*normalizeUserHotelIds\(\[\.\.\.hotelIds, pendingHotelId\]\)/, 'editing an existing beta user from store authorization must prefill the target store');
assert.doesNotMatch(indexHtml, /filterUserHotelId\.value = hotel\?\.id \? String\(hotel\.id\) : '';/, 'hotel authorization must not filter to users already assigned to the store');
assert.match(indexHtml, /openUserModal, openUserAuthorization, openUserModalWithRole/, 'hotel authorization entry must be returned to the Vue template');
assert.match(indexHtml, /const existingUserIssueGuideBlocker = \(u = \{\}\) =>/, 'row-level issuance copy must have explicit blocker checks');
assert.match(indexHtml, /openUserLoginInfoModal\(u\)/, 'existing beta and normal users must expose a login-info reset and copy action');
assert.match(indexHtml, /data-testid="user-login-info-modal"/, 'existing user login-info flow must use an explicit reset confirmation modal');
assert.match(indexHtml, /confirmUserLoginInfoReset/, 'existing user login-info flow must require reset confirmation before copying credentials');
assert.match(indexHtml, /String\(u\?\.status\) !== '1'/, 'row-level issuance copy must block pending or paused accounts');
assert.match(indexHtml, /const lastUserIssueGuideText = ref\(''\);/, 'user management must preserve the latest beta or normal issuance guide after the modal closes');
assert.match(indexHtml, /const copyLastUserIssueGuide = \(\) =>/, 'user management must allow copying the latest issuance guide from the user list screen');
assert.match(indexHtml, /copyUserIssueGuide, isExternalIssueUser, existingUserIssueGuideBlocker, copyUserIssueGuideForUser, copyUserBasicLoginInfo, lastUserIssueGuideText, showLastUserIssueGuideText, copyLastUserIssueGuide, clearLastUserIssueGuide, toggleAllUserHotels,/, 'latest issuance guide state, non-destructive login copy, compact display state, and row copy actions must be returned to the Vue template');
assert.match(indexHtml, /const allUserHotelIds = computed\(\(\) => normalizeUserHotelIds/, 'user modal must derive a selectable full hotel list');
assert.match(indexHtml, /const toggleAllUserHotels = \(\) => \{[\s\S]*areAllUserHotelsSelected\.value \? \[\] : \[\.\.\.allUserHotelIds\.value\]/, 'user modal must support all-select and clear for assigned hotels');
assert.match(indexHtml, /roleIssueProfile, rolePermissionTags, rolePermissionList, roleIssueActionText/, 'role issue permission helpers must be returned to the Vue template');
assert.match(indexHtml, /lastUserIssueGuideText\.value = nextIssueGuideText/, 'successful beta or normal user saves must expose the latest issuance guide');
assert.match(indexHtml, /\['beta_user', 'normal_user'\]\.includes\(issueGuideProfile\.key\)/, 'latest issuance guide must only be generated for beta or normal external roles');
assert.match(indexHtml, /初始密码请通过单独安全渠道发送/, 'copied issuance guidance must not mix the initial password into the normal message');
assert.match(indexHtml, /const filterUserStatus = ref\(''\);/, 'user management must expose a status filter');
assert.match(indexHtml, /const filterUserHotelId = ref\(''\);/, 'user management must expose a hotel-scope filter');
assert.doesNotMatch(userManagementToolbarSlice, /users\.filter\(u => u\.role_id === [123]\)/, 'user management summary must not hard-code legacy role IDs 1/2/3');

assert.match(hotelController, /created_by/, '酒店控制器必须使用 created_by 创建人隔离');
assert.match(hotelController, /can_force_delete'\s*=>\s*\$canForceDelete/, '酒店永久删除确认能力必须按当前角色返回');
assert.doesNotMatch(routes, /Route::post\('\/:id\/restore', 'Hotel\/restore'\)/, 'permanent deletion must not expose an archive restore route');

assert.match(migration, /ADD COLUMN IF NOT EXISTS `created_by`/, '迁移必须补 hotels.created_by');
assert.match(migration, /'beta_user'/, '迁移必须写入内测用户角色');
assert.match(migration, /'normal_user'/, '迁移必须写入普通用户角色');
assert.match(initFull, /20260614_add_access_tier_hotel_owner_scope\.sql/, '完整初始化必须包含权限分层迁移');
assert.match(routes, /Route::get\('api\/hotels\/', 'Hotel\/index'\)->middleware\(\\app\\middleware\\Auth::class\);/, 'hotel list route must accept the trailing slash used by E2E clients');
assert.match(routes, /Route::post\('api\/hotels\/', 'Hotel\/create'\)->middleware\(\\app\\middleware\\Auth::class\);/, 'hotel create route must accept the trailing slash used by E2E clients');

assert.match(hotelController, /owner_user_id/, 'hotel controller must write owner_user_id');
assert.match(migration, /ADD COLUMN IF NOT EXISTS `owner_user_id`/, 'migration must add hotels.owner_user_id');
assert.match(hotelController, /normalizeOtaChannelStrategy/, 'hotel controller must validate OTA channel strategy before save');
assert.match(hotelController, /OTA_CHANNEL_STRATEGIES\s*=\s*\['none', 'ctrip_only', 'dual', 'meituan_only'\]/, 'hotel controller must accept an explicit no-channel selection');
assert.match(hotelController, /ota_channel_strategy/, 'hotel controller must persist OTA channel strategy when the column exists');
assert.match(hotelController, /normalizeOtaChannelStrategy\(\$data, \(string\)\(\$hotel->ota_channel_strategy \?\? 'none'\)\)/, 'hotel updates must preserve an existing OTA channel strategy when old clients omit the new field');
assert.match(hotelController, /if \(\$value === ''\) \{[\s\S]*return in_array\(\$default, self::OTA_CHANNEL_STRATEGIES, true\) \? \$default : 'none';[\s\S]*\}/, 'blank OTA channel strategy updates must fail closed to no OTA channel when no valid persisted strategy exists');
assert.match(hotelOtaStrategyMigration, /ADD COLUMN IF NOT EXISTS `ota_channel_strategy`/, 'migration must add hotels.ota_channel_strategy');
assert.match(hotelOtaStrategyMigration, /DEFAULT 'none'/, 'new hotel records must not default to a false dual-channel claim');
assert.match(hotelOtaStrategyMigration, /NOT IN \('none', 'ctrip_only', 'dual', 'meituan_only'\)/, 'migration must preserve the explicit no-channel strategy');
assert.match(hotelOtaLoginEligibilityVerifier, /'none'\s*=>\s*\[\]/, 'eligibility verification must treat no selected OTA channel as an empty applicable scope');
assert.match(initFull, /20260709_add_hotel_ota_channel_strategy\.sql/, 'full initialization must include hotel OTA channel strategy migration');
assert.doesNotMatch(initFull, /20260712_add_hotel_archiving\.sql/, 'permanent deletion must not require reversible hotel archiving fields');
assert.match(migration, /can_fetch_online_data` = CASE WHEN u\.`role_id` = 2 THEN 1 ELSE 0 END/, 'normal users must not collect OTA by default');
assert.doesNotMatch(seedNormalRoleLine, /can_fetch_online_data/, 'legacy MySQL seed normal_user role must not include OTA collection permission');
assert.match(
  migration,
  /DELETE uhp\s+FROM `user_hotel_permissions` uhp[\s\S]*LEFT JOIN `users` u ON u\.`id` = uhp\.`user_id`[\s\S]*LEFT JOIN `hotels` h ON h\.`id` = uhp\.`hotel_id`[\s\S]*WHERE u\.`id` IS NULL[\s\S]*OR h\.`id` IS NULL/,
  'permission migration must remove orphaned user-hotel permission rows'
);

const loginPayload = authController.slice(
  authController.indexOf('public function login(): Response'),
  authController.indexOf('private function buildUserPermissions')
);
assert.match(loginPayload, /'is_hotel_manager'\s*=>\s*\$user->isHotelManager\(\)/, 'login payload must expose is_hotel_manager for first-paint menu filtering');
assert.match(authController, /buildSelfRegistrationHotelPermissionDefaults\(Role \$role\)/, 'self-registration hotel permissions must be derived from role capabilities');
assert.match(authController, /\$user->status\s*=\s*User::STATUS_DISABLED;/, 'self-registration accounts must wait for super-admin approval before login');
assert.doesNotMatch(authController, /can_fetch_online_data = 1;/, 'self-registration must not hard-code OTA collection permission');
assert.match(indexHtml, /注册后需超级管理员审核启用，审核通过后才能登录。/, 'register form must tell users that approval is required before login');
assert.match(indexHtml, /待审核\/暂停/, 'user management must expose pending approval or paused state for disabled self-registration accounts');
assert.match(indexHtml, /@click="approveUser\(u\)"/, 'user management must expose a direct approval action for pending accounts');
assert.match(indexHtml, /@click="deactivateUser\(u\)"/, 'user management must expose a direct deactivate action for active accounts');
assert.match(indexHtml, /暂停账户/, 'user management operation column must expose a clear pause account action');
assert.match(indexHtml, /<span>删除<\/span>/, 'user management operation column must expose a clear delete account action');
assert.match(indexHtml, /v-if="showUserStatusConfirmModal"/, 'user status changes must use an in-app confirmation modal');
assert.match(indexHtml, /v-if="showUserDeleteModal"/, 'user deletion must use an in-app confirmation modal');
assert.match(indexHtml, /const nextStatus = isApprove \? 1 : 0;/, 'approval must enable and deactivation must disable the user status');
assert.match(indexHtml, /body:\s*JSON\.stringify\(\{\s*status:\s*nextStatus\s*\}\)/, 'approval and deactivate actions must only update user status');
const userStatusActionSlice = indexHtml.slice(
  indexHtml.indexOf('const confirmUserStatusChange = async () => {'),
  indexHtml.indexOf('const approveUser = (u) =>')
);
assert.doesNotMatch(userStatusActionSlice, /confirm\(/, 'user approval/deactivation must not use the native browser confirm dialog');
const userDeleteActionSlice = indexHtml.slice(
  indexHtml.indexOf('const confirmDeleteUser = async () => {'),
  indexHtml.indexOf('// 角色操作')
);
assert.match(userDeleteActionSlice, /body:\s*JSON\.stringify\(\{\s*force:\s*true\s*\}\)/, 'user deletion must expose force delete for associated records');
assert.doesNotMatch(userDeleteActionSlice, /confirm\(/, 'user deletion must not use the native browser confirm dialog');

assert.match(
  indexHtml,
  /entry\.requireManager && user\.value\?\.role_id !== 2 && !user\.value\?\.is_hotel_manager && !user\.value\?\.is_super_admin/,
  'front-end manager-only entries must honor is_hotel_manager'
);
assert.match(compassController, /private function layoutConfigKey\(\): string/, 'compass layout writes must resolve scoped config keys');
assert.match(compassController, /SystemConfig::setValue\(\$this->layoutConfigKey\(\)/, 'compass layout save must not always write the global layout key');

console.log('access tier permission checks passed');
