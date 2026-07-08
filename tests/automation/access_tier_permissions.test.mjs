import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (path) => readFileSync(resolve(root, path), 'utf8');

const systemStatic = read('public/system-static.js');
const indexHtml = read('public/index.html');
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
const permissionService = read('app/service/PermissionService.php');
const compassController = read('app/controller/admin/Compass.php');
const migration = read('database/migrations/20260614_add_access_tier_hotel_owner_scope.sql');
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
assert.match(indexHtml, /v-if="canManageOwnHotels\(\)" class="grid grid-cols-2 gap-1\.5"[\s\S]*openHotelManualFetchConfig\(hotel, 'ctrip'\)[\s\S]*openHotelManualFetchConfig\(hotel, 'meituan'\)/, '门店管理账号必须能打开携程和美团手动配置入口');
assert.match(indexHtml, /onlineDataTab === 'ctrip-config' && canManageOwnHotels\(\)/, '携程配置面板不能只限制超级管理员，门店管理账号也要能补 Cookie');
assert.match(indexHtml, /v-if="canManageOwnHotels\(\)" type="button" @click="showHotelModal = false; openHotelManualFetchConfig\(hotelFormAccountHotel\(\), account\.platform\)"/, '门店弹窗内的手动配置入口必须对门店管理账号可见');
assert.doesNotMatch(hotelController, /private function currentUserCanManageHotelRecord\(HotelModel \$hotel\): bool[\s\S]*!\$this->currentUserOwnsHotel\(\$hotel\)/, 'assigned beta users must be able to manage granted hotels, not only hotels they created');
assert.match(indexHtml, /type:\s*'source',\s*sourcePath:\s*'hotels'[\s\S]*testid:\s*'nav-lean-hotel-management'/, '门店管理必须作为一级菜单直接进入 hotels 页面');
assert.doesNotMatch(indexHtml, /testid:\s*'nav-lean-hotel-knowledge'[\s\S]{0,160}children:\s*\[/, '门店管理不能再作为单子项下拉分组');
assert.match(indexHtml, />门店总数<\/span>/, 'hotel account filter should use clear total-store copy');
assert.match(indexHtml, />待办门店<\/span>/, 'hotel account filter should explain actionable stores without vague todo copy');
assert.match(indexHtml, />竞对可用<\/span>/, 'hotel account filter should describe competitor data as usable data');
assert.match(indexHtml, /说明：待办门店=营业中门店还有账号绑定、采集配置或竞对数据问题；竞对可用=已有美团竞对榜单入库/, 'hotel account filter should include visible business definitions');
assert.doesNotMatch(indexHtml, /待办：\$\{action\}/, 'hotel account health tag should not repeat the concrete next action in the store info cell');
assert.doesNotMatch(indexHtml, /return '待办：补齐账号\/采集'/, 'hotel account health fallback should not show vague todo copy in the store info cell');
assert.match(indexHtml, /OTA采集接入状态 · \{\{ filteredHotels\.length \}\}/, 'hotel account ledger should become OTA collection access status');
assert.match(indexHtml, />门店信息<\/th>/, 'hotel table should expose a clear store information column');
assert.doesNotMatch(indexHtml, />处理事项<\/th>/, 'hotel table should not expose a duplicated action-items column');
assert.match(indexHtml, />携程<\/th>[\s\S]*>美团<\/th>/, 'hotel table should expose Ctrip and Meituan as separate channel columns');
assert.match(indexHtml, /hotelOwnerText\(hotel\)/, 'hotel table should show the responsible person');
assert.match(indexHtml, /v-for="\((hotel, hotelIndex)\) in filteredHotels"[\s\S]*编码 \{\{ formatHotelCode\(hotelIndex \+ 1\) \}\}/, 'hotel table should display a visible 0001-based sequence code');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'ctrip'\)/, 'hotel table should render the Ctrip channel row directly');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'meituan'\)/, 'hotel table should render the Meituan channel row directly');
assert.match(indexHtml, /@click="openHotelPlatformCardLogin\(hotel, account\)"/, 'hotel channel cards should open the platform login action when clicked');
assert.match(indexHtml, /const openHotelPlatformCardLogin = async \(hotel, account = \{\}\) => \{[\s\S]*await openHotelPlatformAccountAction\(hotel, account\);/, 'hotel channel card login must reuse the existing platform account action');
assert.match(indexHtml, /const buildHotelPlatformLoginItem = \(hotel = \{\}, account = \{\}\) => \{[\s\S]*system_hotel_id: hotelId[\s\S]*profile_id: profileId/, 'hotel channel card login must carry explicit hotel-scoped Ctrip profile context');
assert.match(indexHtml, /const buildHotelPlatformLoginItem = \(hotel = \{\}, account = \{\}\) => \{[\s\S]*partner_id: partnerId[\s\S]*system_hotel_id: hotelId/, 'hotel channel card login must carry explicit hotel-scoped Meituan identity context');
assert.match(indexHtml, /openHotelPlatformCardLogin, openHotelPlatformAccountAction/, 'hotel channel card login function must be exposed to the Vue template');
assert.match(indexHtml, /点击登录\/刷新会话/, 'hotel channel cards should tell operators that the card is a login entry');
assert.match(indexHtml, />绑定<\/span>[\s\S]*hotelPlatformBindingText\(account\)/, 'hotel channel cards should show binding state');
assert.match(indexHtml, />登录<\/span>[\s\S]*hotelPlatformLoginText\(account\)/, 'hotel channel cards should show login state');
assert.match(indexHtml, /平台门店：\{\{ account\.accountStoreText \|\| '-' \}\}/, 'hotel channel cards should show the mapped platform store');
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

assert.match(authController, /private const TOKEN_TTL_SECONDS = 86400;/, 'website login token must expire after 24 hours');
assert.match(authMiddleware, /private const TOKEN_MAX_AGE_SECONDS = 86400;/, 'auth middleware must reject tokens older than the 24-hour session limit');
assert.match(authMiddleware, /if \(!is_array\(\$tokenData\)\) \{\s*return false;\s*\}/, 'auth middleware must preserve legacy scalar token cache entries until cache TTL');
assert.match(authMiddleware, /\$createdAt = \(int\)\(\$tokenData\['created_at'\] \?\? 0\);[\s\S]*if \(\$createdAt <= 0\) \{\s*return false;\s*\}/, 'auth middleware must preserve legacy token payloads without created_at until cache TTL');
assert.match(cookieEndpointConcern, /recordPublicEndpointFailure\('receive_cookies', 'legacy_bookmarklet_disabled', 410/, 'legacy receive-cookies endpoint must be disabled instead of accepting current-session tokens');
assert.match(cookieEndpointConcern, /return \$this->corsError\('旧版 Cookie 书签入口已禁用[\s\S]*410\)/, 'legacy receive-cookies endpoint must return a traceable 410 disabled response');
assert.doesNotMatch(cookieEndpointConcern, /UserModel::find\(\$userId\)|cache\('token_' \. \$token\)|Authorization Bearer token from current login session/, 'disabled receive-cookies endpoint must not save cookies from the current login token');
assert.match(indexHtml, /const writeAuthToken = \(value\) =>[\s\S]*sessionStorage\.setItem\(AUTH_TOKEN_STORAGE_KEY, normalized\)[\s\S]*localStorage\.removeItem\(AUTH_TOKEN_STORAGE_KEY\)/, 'front-end must keep auth tokens in sessionStorage and clear legacy localStorage tokens');
assert.doesNotMatch(indexHtml, /localStorage\.setItem\('token'/, 'front-end must not persist auth tokens in localStorage');
assert.match(authController, /private const BETA_HOTEL_BINDING_CUTOFF_DATE = '2026-07-05';/, 'beta users must see a concrete hotel binding deadline');
assert.match(authController, /private function buildLoginNotices\(User \$user, array \$permittedHotels\): array/, 'auth payload must include login notices');
assert.match(authController, /!\$user->isBetaUser\(\)/, 'hotel binding deadline notice must target beta users');
assert.match(authController, /未绑定或未分配的门店将无法查看/, 'beta notice must state that unbound stores will not be visible after the deadline');
assert.match(indexHtml, /const showAuthNotices = \(payload = \{\}\) =>/, 'front-end must render auth notices from login and auth info payloads');
assert.match(indexHtml, /setTimeout\(\(\) => showAuthNotices\(res\.data\), 600\)/, 'login success should show beta binding notice after the welcome message');
assert.match(authController, /cache\('token_' \. \$token, \$tokenData, self::TOKEN_TTL_SECONDS\)/, 'token cache TTL must use the 24-hour constant');
assert.match(authController, /'expires_in'\s*=>\s*self::TOKEN_TTL_SECONDS/, 'login response must expose the 24-hour token expiry');
assert.match(hotelScopeService, /private function ownedOrGrantedHotelIds\(User \$user, \?string \$capability = null\): array/, 'non-super hotel scope must be centralized');
assert.match(hotelScopeService, /\$this->primaryHotelIds\(\$user\)[\s\S]*\$this->ownedHotelIds\(\$user\)[\s\S]*\$this->grantedHotelIds\(\$user, \$capability\)/, 'non-super users must only see primary, owned, or explicitly granted hotels');
assert.doesNotMatch(hotelScopeService, /if \(\$this->isVipUser\(\$user\)\)[\s\S]{0,160}return \$this->ownedHotelIds\(\$user\);/, 'VIP role alone must not bypass explicit hotel scope');
assert.match(hotelScopeService, /'hotel\.delete' => \['can_edit'\]/, 'assigned hotel deletion must still pass through the per-hotel can_edit permission layer');
assert.match(userController, /private function syncUserHotelPermissions\(UserModel \$targetUser, array \$hotelIds, Role \$targetRole\): void/, 'super admin user saves must sync user_hotel_permissions');
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
assert.match(rolePermissionCardsSlice, /内测用户[\s\S]*可信内测方/, 'role permission cards must name the beta account audience clearly');
assert.match(rolePermissionCardsSlice, /普通用户[\s\S]*必须分配门店/, 'role permission cards must name normal external users and their hotel-scope requirement');
assert.doesNotMatch(rolePermissionCardsSlice, />门店管理员<\/div>|>店员<\/div>/, 'role permission cards must not keep ambiguous legacy role labels for external issuance');
assert.match(indexHtml, /const roleIssueGuideCards = computed\(\(\) =>/, 'user management must expose beta/normal role issue guide cards');
assert.match(indexHtml, /handoffType:\s*'内测发放'/, 'beta issue profile must expose an explicit handoff type');
assert.match(indexHtml, /handoffType:\s*'普通外发'/, 'normal issue profile must expose an explicit handoff type');
assert.match(indexHtml, /dataBoundary:\s*'仅授权门店的 OTA 渠道数据和内测功能，不代表全酒店经营数据。'/, 'beta issue profile must show the OTA-only data boundary');
assert.match(indexHtml, /dataBoundary:\s*'仅授权门店的 OTA 渠道只读数据；不代表全酒店经营数据，也不开放采集执行。'/, 'normal issue profile must show the read-only OTA boundary');
assert.match(indexHtml, />发放类型：<\/span>\{\{ card\.handoffType \}\}/, 'role issue cards must render the handoff type');
assert.match(indexHtml, />数据范围：<\/span>\{\{ card\.dataBoundary \}\}/, 'role issue cards must render the data boundary');
assert.match(indexHtml, />外发角色矩阵<\/h3>[\s\S]*roleIssueGuideCards/, 'user management must provide a beta/normal external issuance matrix before admins send accounts');
assert.match(indexHtml, /密码仍走单独安全渠道/, 'external issuance matrix must keep passwords outside the copied account message');
assert.match(indexHtml, /const rolePermissionTags = \(profile = \{\}\) =>/, 'role issue cards must expose permission tags for beta and normal account issuance');
assert.match(indexHtml, /const normalExternalDeniedPermissionGroups = \[/, 'role issue cards must use an explicit denied-capability checklist for normal external accounts');
assert.match(indexHtml, /const roleUnsafeExternalCapabilityLabels = \(role = \{\}\) =>/, 'role issue cards must translate unsafe normal-user permissions into reviewable labels');
assert.match(indexHtml, /const issueStatusForRoleProfile = \(profile = \{\}\) =>/, 'role issue cards must expose a clear issuance conclusion');
assert.match(indexHtml, /普通用户角色含高风险权限：\$\{unsafeExternalCapabilityLabels\.join\('、'\)\}/, 'normal-user issuance blockers must name the risky permission groups');
assert.match(indexHtml, /withRolePermissionTags\(roleIssueProfile\(role\)\)/, 'role issue cards must attach permission tags to role profiles');
assert.match(indexHtml, /filter\(item => \['beta_user', 'normal_user'\]\.includes\(item\.profile\?\.key\)\)/, 'role issue cards must include level-based beta and normal profiles instead of fixed role IDs only');
assert.doesNotMatch(indexHtml, /filter\(role => \['beta_user', 'normal_user'\]\.includes\(String\(role\?\.name \|\| ''\)\.trim\(\)\) \|\| \[2, 3\]\.includes\(Number\(role\?\.id \|\| 0\)\)\)/, 'role issue cards must not depend only on legacy role id/name pairs');
assert.match(indexHtml, /roleId === 3 \|\| roleName === 'normal_user' \|\| level >= 3/, 'front-end role profiles must treat staff-level roles as normal external accounts');
assert.match(indexHtml, /openUserModalWithRole\(card\.roleId\)/, 'user management must support issuing beta/normal roles from guide cards');
assert.match(indexHtml, /roleIssueActionText\(card\)/, 'role issue cards must use explicit beta or normal issue actions');
assert.match(indexHtml, /发放结论：\{\{ card\.issueStatusText \}\}/, 'role issue cards must show the issuance conclusion before admins create external users');
assert.match(indexHtml, /可用功能：<\/span>\{\{ card\.allowed \}\}/, 'role issue cards must show allowed capabilities before admins issue beta or normal accounts');
assert.match(indexHtml, /不可用功能：<\/span>\{\{ selectedUserRoleGuide\.denied \}\}/, 'user modal must show denied capabilities for the selected beta or normal role');
assert.match(indexHtml, /发送前确认：\$\{profile\?\.sendChecklist \|\| '-'\}/, 'copied issuance guidance must include the pre-send checklist');
assert.match(indexHtml, /高风险待修/, 'normal external role tags must flag high-risk permission blockers clearly');
assert.match(indexHtml, /:title="card\.issueBlocked \? card\.issueStatusDetail : roleIssueActionText\(card\)"/, 'blocked role issue cards must expose the blocker detail on the disabled action');
assert.match(indexHtml, /:disabled="card\.issueBlocked"/, 'unsafe normal-user role cards must not present an enabled external issuance action');
assert.match(indexHtml, /if \(profile\.issueBlocked\) return '先修角色权限'/, 'blocked normal-user role cards must direct admins to repair the role first');
assert.match(indexHtml, />权限摘要<\/th>[\s\S]*rolePermissionTags\(roleIssueProfile\(r\)\)/, 'role management table must show reviewable permission summary tags');
assert.match(indexHtml, /已选 \{\{ rolePermissionList\(r\)\.length \}\} 项权限/, 'role management table must show the selected permission count');
assert.match(indexHtml, /selectedUserRoleGuide/, 'user modal must show the selected role issue boundary');
assert.match(indexHtml, /selectedUserRoleGuide\.permissionTags/, 'user modal must show selected role permission tags');
assert.match(indexHtml, /const userIssueChecklistRows = computed\(\(\) =>/, 'user modal must show an issuance checklist before external accounts are saved');
assert.match(indexHtml, /const userRoleBoundaryText = \(u = \{\}\) =>/, 'user table must show per-account issue boundary text');
assert.match(indexHtml, /userRoleBoundaryText\(u\)/, 'user table role column must render per-account issue boundary text');
assert.match(indexHtml, />发放状态<\/th>[\s\S]*userIssueStatus\(u\)/, 'user management table must expose a row-level issuance status');
assert.match(indexHtml, /const userIssueStatus = \(u = \{\}\) =>/, 'user table issuance status must be derived from the same blocker rules as copy guidance');
assert.match(indexHtml, /existingUserIssueGuideBlocker\(u\)[\s\S]*label: '暂不可发'/, 'row-level issuance status must surface blocker details instead of allowing blind external sends');
assert.match(indexHtml, /userRoleBoundaryText, userIssueStatus, selectedUserRoleGuide/, 'row-level issuance status helper must be returned to the Vue template');
assert.match(indexHtml, /const validateUserIssueBeforeSave = \(data = \{\}, assignedHotelIds = \[\]\) =>/, 'user saves must validate issuance boundaries before calling the API');
assert.match(indexHtml, /profile\.key === 'normal_user' && profile\.canCollectOta/, 'normal-user issuance must flag OTA collection as unsafe for external accounts');
assert.match(indexHtml, /profile\.requiresHotelAssignment && assignedHotelIds\.length === 0/, 'external account issuance must block missing hotel scope');
assert.match(indexHtml, /const issueError = validateUserIssueBeforeSave\(data, assignedHotelIds\)/, 'user save must run the issuance validator before request submission');
assert.match(indexHtml, /const buildUserIssueGuideText = \(\) =>/, 'user modal must build a copyable issuance handoff message');
assert.ok(indexHtml.includes("`发放类型：${profile?.handoffType || profile?.title || '-'}`"), 'copied issuance guidance must include the handoff type');
assert.ok(indexHtml.includes("`数据范围：${profile?.dataBoundary || '-'}`"), 'copied issuance guidance must include the data boundary');
assert.match(indexHtml, /const copyUserIssueGuide = \(\) =>/, 'user modal must expose a safe copy action for issuance guidance');
assert.match(indexHtml, /applyUserRoleQuickFilter\(card\)/, 'user management must support one-click beta and normal account filtering');
assert.match(indexHtml, /const resetUserFilters = \(\) =>/, 'user management filters must be reset through a shared helper');
assert.match(indexHtml, /@click="openUserAuthorization\(\)"/, 'hotel management header must expose a super-admin user authorization entry');
assert.match(indexHtml, /@click="openUserAuthorization\(hotel\)"/, 'hotel rows must expose per-store user authorization entry');
assert.match(indexHtml, /const pendingUserAuthorizationHotel = ref\(null\);/, 'hotel authorization must keep the target store as context instead of hiding it in a list filter');
assert.match(indexHtml, /const betaUserRoleIdForFilter = \(\) => \{[\s\S]*key === 'beta_user'[\s\S]*roleIssueProfile\(role\)\.key === 'beta_user'/, 'hotel authorization must locate beta users by role profile');
assert.match(indexHtml, /const openUserAuthorization = async \(hotel = null\) => \{[\s\S]*user\.value\?\.is_super_admin[\s\S]*filterUserHotelId\.value = '';[\s\S]*pendingUserAuthorizationHotel\.value = hotel\?\.id[\s\S]*currentPage\.value = 'users'[\s\S]*loadUsers\(\)[\s\S]*loadRoles\(\)[\s\S]*loadHotels\(\)[\s\S]*filterUserRoleId\.value = betaRoleId/, 'hotel authorization entry must navigate to existing beta users instead of filtering users already assigned to the store');
assert.match(indexHtml, /pendingUserAuthorizationHotel[\s\S]*请选择已有内测用户点“编辑”/, 'user management must tell admins to assign stores through existing beta users');
assert.match(indexHtml, /const pendingHotelId = pendingUserAuthorizationHotel\.value\?\.id[\s\S]*userRoleIssueProfile\(u\)\?\.key === 'beta_user'[\s\S]*normalizeUserHotelIds\(\[\.\.\.hotelIds, pendingHotelId\]\)/, 'editing an existing beta user from store authorization must prefill the target store');
assert.doesNotMatch(indexHtml, /filterUserHotelId\.value = hotel\?\.id \? String\(hotel\.id\) : '';/, 'hotel authorization must not filter to users already assigned to the store');
assert.match(indexHtml, /openUserModal, openUserAuthorization, openUserModalWithRole/, 'hotel authorization entry must be returned to the Vue template');
assert.match(indexHtml, /const existingUserIssueGuideBlocker = \(u = \{\}\) =>/, 'row-level issuance copy must have explicit blocker checks');
assert.match(indexHtml, /copyUserIssueGuideForUser\(u\)/, 'existing beta and normal users must expose a copyable issuance guide action');
assert.match(indexHtml, /String\(u\?\.status\) !== '1'/, 'row-level issuance copy must block pending or paused accounts');
assert.match(indexHtml, /const lastUserIssueGuideText = ref\(''\);/, 'user management must preserve the latest beta or normal issuance guide after the modal closes');
assert.match(indexHtml, /const copyLastUserIssueGuide = \(\) =>/, 'user management must allow copying the latest issuance guide from the user list screen');
assert.match(indexHtml, /copyUserIssueGuide, isExternalIssueUser, existingUserIssueGuideBlocker, copyUserIssueGuideForUser, lastUserIssueGuideText, copyLastUserIssueGuide, clearLastUserIssueGuide,/, 'latest issuance guide state and row copy actions must be returned to the Vue template');
assert.match(indexHtml, /roleIssueProfile, rolePermissionTags, rolePermissionList, roleIssueActionText/, 'role issue permission helpers must be returned to the Vue template');
assert.match(indexHtml, /lastUserIssueGuideText\.value = nextIssueGuideText/, 'successful beta or normal user saves must expose the latest issuance guide');
assert.match(indexHtml, /\['beta_user', 'normal_user'\]\.includes\(issueGuideProfile\.key\)/, 'latest issuance guide must only be generated for beta or normal external roles');
assert.match(indexHtml, /初始密码请通过单独安全渠道发送/, 'copied issuance guidance must not mix the initial password into the normal message');
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
