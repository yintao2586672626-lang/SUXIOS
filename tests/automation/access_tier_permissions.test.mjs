import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = process.cwd();
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const systemStatic = read('public/system-static.js');
const indexHtml = read('public/index.html');
const hotelController = read('app/controller/Hotel.php');
const userModel = read('app/model/User.php');
const userController = read('app/controller/User.php');
const authController = read('app/controller/Auth.php');
const initDatabaseCommand = read('app/command/InitDatabase.php');
const routes = read('route/app.php');
const hotelScopeService = read('app/service/HotelScopeService.php');
const compassController = read('app/controller/admin/Compass.php');
const migration = read('database/migrations/20260614_add_access_tier_hotel_owner_scope.sql');
const initFull = read('database/init_full.sql');

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

assert.match(indexHtml, /const canManageOwnHotels = \(\) =>/, '前端必须提供统一门店管理权限判断');
assert.match(indexHtml, /v-if="canManageOwnHotels\(\)"/, '门店管理按钮必须使用 canManageOwnHotels()');
assert.match(indexHtml, /cloneMenuItem\(hotelEntry,\s*\{[\s\S]*testid:\s*'nav-lean-hotel-management'/, '门店管理必须作为一级菜单直接进入 hotels 页面');
assert.doesNotMatch(indexHtml, /testid:\s*'nav-lean-hotel-knowledge'[\s\S]{0,160}children:\s*\[/, '门店管理不能再作为单子项下拉分组');
assert.match(indexHtml, />门店总数<\/span>/, 'hotel account filter should use clear total-store copy');
assert.match(indexHtml, />待办门店<\/span>/, 'hotel account filter should explain actionable stores without vague todo copy');
assert.match(indexHtml, />竞对可用<\/span>/, 'hotel account filter should describe competitor data as usable data');
assert.match(indexHtml, /说明：待办门店=营业中门店还有账号绑定、采集配置或竞对数据问题；竞对可用=已有美团竞对榜单入库/, 'hotel account filter should include visible business definitions');
assert.match(indexHtml, /待办：\$\{action\}/, 'hotel account health tag should include the concrete next action');
assert.match(indexHtml, /OTA采集接入状态 · \{\{ filteredHotels\.length \}\}/, 'hotel account ledger should become OTA collection access status');
assert.match(indexHtml, />门店信息<\/th>/, 'hotel table should expose a clear store information column');
assert.match(indexHtml, />处理事项<\/th>/, 'hotel table should expose a clear action-items column');
assert.match(indexHtml, />携程<\/th>[\s\S]*>美团<\/th>/, 'hotel table should expose Ctrip and Meituan as separate channel columns');
assert.match(indexHtml, /hotelOwnerText\(hotel\)/, 'hotel table should show the responsible person');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'ctrip'\)/, 'hotel table should render the Ctrip channel row directly');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'meituan'\)/, 'hotel table should render the Meituan channel row directly');
assert.match(indexHtml, /@click="openHotelPlatformCardLogin\(hotel, account\)"/, 'hotel channel cards should open the platform login action when clicked');
assert.match(indexHtml, /const openHotelPlatformCardLogin = async \(hotel, account = \{\}\) => \{[\s\S]*await openHotelPlatformAccountAction\(hotel, account\);/, 'hotel channel card login must reuse the existing platform account action');
assert.match(indexHtml, /openHotelPlatformCardLogin, openHotelPlatformAccountAction/, 'hotel channel card login function must be exposed to the Vue template');
assert.match(indexHtml, /点击登录\/刷新会话/, 'hotel channel cards should tell operators that the card is a login entry');
assert.match(indexHtml, />绑定<\/span>[\s\S]*hotelPlatformBindingText\(account\)/, 'hotel channel cards should show binding state');
assert.match(indexHtml, />登录<\/span>[\s\S]*hotelPlatformLoginText\(account\)/, 'hotel channel cards should show login state');
assert.match(indexHtml, /平台门店：\{\{ account\.accountStoreText \|\| '-' \}\}/, 'hotel channel cards should show the mapped platform store');
assert.match(indexHtml, /hotelIssueRows\(hotel\)/, 'hotel table should show current issues from real channel state');
assert.doesNotMatch(indexHtml, />总<\/span>/, 'hotel account filter should not use ambiguous single-character total copy');
assert.doesNotMatch(indexHtml, />待办<\/span>/, 'hotel account filter should not use unclear todo copy');
assert.doesNotMatch(indexHtml, />竞对可信<\/span>/, 'hotel account filter should not use unclear competitor-trust copy');
assert.doesNotMatch(indexHtml, /todo:\s*'待办'/, 'hotel account health text should not use unclear todo copy');
assert.doesNotMatch(indexHtml, /user\?\.role_id\s*<=\s*2/, '用户管理入口不能继续用 role_id <= 2 放开内测用户');

assert.match(userModel, /public function canManageUser\(\): bool[\s\S]*return \$this->isSuperAdmin\(\);/, '用户管理必须仅管理员可用');
assert.match(userController, /public function index\(\): Response[\s\S]*canManageUser\(\)/, '用户列表接口必须检查用户管理权限');

assert.match(authController, /private const TOKEN_TTL_SECONDS = 259200;/, 'website login token must last 72 hours');
assert.match(authController, /private const BETA_HOTEL_BINDING_CUTOFF_DATE = '2026-07-05';/, 'beta users must see a concrete hotel binding deadline');
assert.match(authController, /private function buildLoginNotices\(User \$user, array \$permittedHotels\): array/, 'auth payload must include login notices');
assert.match(authController, /!\$user->isBetaUser\(\)/, 'hotel binding deadline notice must target beta users');
assert.match(authController, /未绑定或未分配的门店将无法查看/, 'beta notice must state that unbound stores will not be visible after the deadline');
assert.match(indexHtml, /const showAuthNotices = \(payload = \{\}\) =>/, 'front-end must render auth notices from login and auth info payloads');
assert.match(indexHtml, /setTimeout\(\(\) => showAuthNotices\(res\.data\), 600\)/, 'login success should show beta binding notice after the welcome message');
assert.match(authController, /cache\('token_' \. \$token, \$tokenData, self::TOKEN_TTL_SECONDS\)/, 'token cache TTL must use the 72-hour constant');
assert.match(authController, /'expires_in'\s*=>\s*self::TOKEN_TTL_SECONDS/, 'login response must expose the 72-hour token expiry');
assert.match(hotelScopeService, /private function ownedOrGrantedHotelIds\(User \$user, \?string \$capability = null\): array/, 'non-super hotel scope must be centralized');
assert.match(hotelScopeService, /\$this->primaryHotelIds\(\$user\)[\s\S]*\$this->ownedHotelIds\(\$user\)[\s\S]*\$this->grantedHotelIds\(\$user, \$capability\)/, 'non-super users must only see primary, owned, or explicitly granted hotels');
assert.doesNotMatch(hotelScopeService, /if \(\$this->isVipUser\(\$user\)\)[\s\S]{0,160}return \$this->ownedHotelIds\(\$user\);/, 'VIP role alone must not bypass explicit hotel scope');
assert.match(userController, /private function syncUserHotelPermissions\(UserModel \$targetUser, array \$hotelIds, Role \$targetRole\): void/, 'super admin user saves must sync user_hotel_permissions');
assert.match(userController, /private function normalizeAssignedHotelIds\(array \$data\): array/, 'user API must accept multi-hotel assignments');
assert.match(userController, /\$data\['hotel_ids'\]\s*=/, 'user API responses must expose assigned hotel ids for editing');
assert.match(userController, /private function validateUsernamePolicy\(string \$username\): \?string/, 'admin-created users must share the public registration username policy');
assert.match(userController, /\^\[A-Za-z0-9_\]\{3,50\}\$/, 'admin-created users must allow underscores and the same length as public registration');
assert.doesNotMatch(userController, /alphaNum\|min:3\|max:20/, 'admin-created users must not keep the stricter legacy alphaNum username rule');
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
assert.match(indexHtml, /const userSummary = computed\(\(\) =>/, 'user management summary must be derived from current users instead of fixed role IDs');
assert.match(indexHtml, /const filterUserStatus = ref\(''\);/, 'user management must expose a status filter');
assert.match(indexHtml, /const filterUserHotelId = ref\(''\);/, 'user management must expose a hotel-scope filter');
assert.doesNotMatch(userManagementToolbarSlice, /users\.filter\(u => u\.role_id === [123]\)/, 'user management summary must not hard-code legacy role IDs 1/2/3');

assert.match(hotelController, /created_by/, '酒店控制器必须使用 created_by 创建人隔离');
assert.match(hotelController, /can_force_delete'\s*=>\s*\$canForceDelete/, '酒店强制删除能力必须按当前角色返回');

assert.match(migration, /ADD COLUMN IF NOT EXISTS `created_by`/, '迁移必须补 hotels.created_by');
assert.match(migration, /'beta_user'/, '迁移必须写入内测用户角色');
assert.match(migration, /'normal_user'/, '迁移必须写入普通用户角色');
assert.match(initFull, /20260614_add_access_tier_hotel_owner_scope\.sql/, '完整初始化必须包含权限分层迁移');
assert.match(routes, /Route::get\('api\/hotels\/', 'Hotel\/index'\)->middleware\(\\app\\middleware\\Auth::class\);/, 'hotel list route must accept the trailing slash used by E2E clients');
assert.match(routes, /Route::post\('api\/hotels\/', 'Hotel\/create'\)->middleware\(\\app\\middleware\\Auth::class\);/, 'hotel create route must accept the trailing slash used by E2E clients');

assert.match(hotelController, /owner_user_id/, 'hotel controller must write owner_user_id');
assert.match(migration, /ADD COLUMN IF NOT EXISTS `owner_user_id`/, 'migration must add hotels.owner_user_id');
assert.match(migration, /can_fetch_online_data` = CASE WHEN u\.`role_id` = 2 THEN 1 ELSE 0 END/, 'normal users must not collect OTA by default');
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
