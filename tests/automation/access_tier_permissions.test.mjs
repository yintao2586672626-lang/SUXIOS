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
assert.match(indexHtml, />需处理<\/span>/, 'hotel account filter should explain actionable stores without vague todo copy');
assert.match(indexHtml, />竞对可用<\/span>/, 'hotel account filter should describe competitor data as usable data');
assert.match(indexHtml, /说明：需处理=营业中门店还有账号绑定、采集配置或竞对数据问题；竞对可用=已有美团竞对榜单入库/, 'hotel account filter should include visible business definitions');
assert.match(indexHtml, /todo:\s*'需处理'/, 'hotel account health tag should use actionable copy');
assert.match(indexHtml, /OTA采集接入状态 · \{\{ filteredHotels\.length \}\}/, 'hotel account ledger should become OTA collection access status');
assert.match(indexHtml, />门店信息<\/th>/, 'hotel table should expose a clear store information column');
assert.match(indexHtml, />携程<\/th>[\s\S]*>美团<\/th>/, 'hotel table should expose Ctrip and Meituan as separate channel columns');
assert.match(indexHtml, /hotelOwnerText\(hotel\)/, 'hotel table should show the responsible person');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'ctrip'\)/, 'hotel table should render the Ctrip channel row directly');
assert.match(indexHtml, /hotelPlatformRow\(hotel,\s*'meituan'\)/, 'hotel table should render the Meituan channel row directly');
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

assert.match(hotelController, /created_by/, '酒店控制器必须使用 created_by 创建人隔离');
assert.match(hotelController, /can_force_delete'\s*=>\s*\$canForceDelete/, '酒店强制删除能力必须按当前角色返回');

assert.match(migration, /ADD COLUMN IF NOT EXISTS `created_by`/, '迁移必须补 hotels.created_by');
assert.match(migration, /'beta_user'/, '迁移必须写入内测用户角色');
assert.match(migration, /'normal_user'/, '迁移必须写入普通用户角色');
assert.match(initFull, /20260614_add_access_tier_hotel_owner_scope\.sql/, '完整初始化必须包含权限分层迁移');

assert.match(hotelController, /owner_user_id/, 'hotel controller must write owner_user_id');
assert.match(migration, /ADD COLUMN IF NOT EXISTS `owner_user_id`/, 'migration must add hotels.owner_user_id');
assert.match(migration, /can_fetch_online_data` = CASE WHEN u\.`role_id` = 2 THEN 1 ELSE 0 END/, 'normal users must not collect OTA by default');

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
