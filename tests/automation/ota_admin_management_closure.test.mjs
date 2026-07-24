import assert from 'node:assert/strict';
import { createHash, webcrypto } from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const hotelController = fs.readFileSync(new URL('../../app/controller/Hotel.php', import.meta.url), 'utf8');
const userController = fs.readFileSync(new URL('../../app/controller/User.php', import.meta.url), 'utf8');
const roleController = fs.readFileSync(new URL('../../app/controller/RoleController.php', import.meta.url), 'utf8');
const otaConfigConcern = fs.readFileSync(new URL('../../app/controller/concern/OtaConfigConcern.php', import.meta.url), 'utf8');
const userAdminStatic = fs.readFileSync(new URL('../../public/user-admin-static.js', import.meta.url), 'utf8');
const userAdminStaticHash = createHash('sha256').update(userAdminStatic).digest('hex').slice(0, 10);
const userAdminSandbox = { window: {}, crypto: webcrypto };
vm.runInNewContext(`${userAdminStatic}\nthis.__userAdminStatic = window.SUXI_USER_ADMIN_STATIC;`, userAdminSandbox);
const userAdminStaticApi = userAdminSandbox.__userAdminStatic;

test('hotel permanent delete is super-admin only and requires an impact preview plus exact name', () => {
  assert.match(hotelController, /public function delete\(int \$id\): Response[\s\S]*?\$this->checkPermission\(true\)/);
  assert.match(hotelController, /HotelCascadeDeletionService/);
  assert.match(hotelController, /confirmation_name/);
  assert.match(html, /v-model="hotelDeleteConfirmationName"/);
  assert.match(html, /永久删除门店/);
  assert.match(hotelController, /酒店及关联数据已删除/);
  assert.doesNotMatch(hotelController, /public function restore\(int \$id\): Response/);
  assert.doesNotMatch(html, /const restoreHotel = async/);
});

test('hotel disable wording does not claim employee accounts are disabled', () => {
  assert.match(html, /停用后该门店不可访问，OTA采集停止；员工账号不会停用，有其他门店权限时仍可登录/);
  assert.doesNotMatch(html, /该酒店关联的所有用户将无法登录系统/);
  assert.match(hotelController, /涉及\{\$affectedUsers\}个主门店归属账号/);
});

const sliceFrom = (start, end) => {
  const startIndex = html.indexOf(start);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  const endIndex = html.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return html.slice(startIndex, endIndex);
};

test('OTA manual config forms require an explicit store and separate save from verification', () => {
  const ctripForm = sliceFrom('data-testid="ctrip-config-form"', '<!-- 已保存的配置列表 -->');
  const meituanForm = sliceFrom('<!-- 新增配置表单 -->', '<!-- 配置获取办法说明 -->');

  assert.match(ctripForm, /关联门店\s*<span class="text-red-500">\*<\/span>/);
  assert.match(ctripForm, /<option value="">请选择关联门店<\/option>/);
  assert.doesNotMatch(ctripForm, /<option value="">不关联<\/option>/);
  assert.match(meituanForm, /v-model="meituanConfigForm\.hotel_id"/);
  assert.match(meituanForm, /<option value="">请选择关联门店<\/option>/);
  assert.match(ctripForm, /保存成功只表示配置已保存/);
  assert.match(meituanForm, /保存成功只表示配置已保存/);
  assert.doesNotMatch(ctripForm, /最新成功配置/);
  assert.doesNotMatch(meituanForm, /最新成功配置/);
  assert.match(otaConfigConcern, /OtaConfigVerificationService/);
  assert.match(otaConfigConcern, /configuration_verified/);
});

test('manual credential selection does not require browser Profile verification', () => {
  for (const [start, end] of [
    ['private function selectLatestSuccessfulMeituanConfig', 'private function selectLatestSuccessfulCtripConfig'],
    ['private function selectLatestSuccessfulCtripConfig', 'private function selectLatestSuccessfulCtripConfigForHotel'],
  ]) {
    const startIndex = otaConfigConcern.indexOf(start);
    const endIndex = otaConfigConcern.indexOf(end, startIndex + start.length);
    assert.notEqual(startIndex, -1);
    assert.notEqual(endIndex, -1);
    const selector = otaConfigConcern.slice(startIndex, endIndex);
    assert.doesNotMatch(selector, /configuration_verified/);
    assert.match(selector, /credential_status/);
    assert.match(selector, /has_cookies/);
  }
});

test('Meituan saved config list shows collapsed history and verified-current semantics', () => {
  const meituanConfigIndex = html.indexOf('<div v-if="onlineDataTab === \'meituan-config\'">');
  assert.notEqual(meituanConfigIndex, -1);
  const listIndex = html.indexOf('<!-- 已保存的配置列表 -->', meituanConfigIndex);
  assert.notEqual(listIndex, -1);
  const listEndIndex = html.indexOf('<div v-if="currentPage === \'agent-center\'">', listIndex);
  assert.notEqual(listEndIndex, -1);
  const list = html.slice(listIndex, listEndIndex);
  assert.match(list, /config\.history_count/);
  assert.match(list, /已折叠/);
  assert.match(list, /verification_status_label/);
  assert.match(list, /验证成功，当前使用/);
  assert.doesNotMatch(list, /当前使用最新成功配置/);
});

test('employee and hotel saves are single-flight and expose progress in their modals', () => {
  const saveHotel = sliceFrom('const saveHotel = async () => {', '\n\n            const toggleHotelStatus');
  const saveUser = sliceFrom('const saveUser = async () => {', '\n\n            const userStatusConfirmName');

  assert.match(html, /const hotelSaving = ref\(false\);/);
  assert.match(html, /const userSaving = ref\(false\);/);
  assert.match(saveHotel, /if \(hotelSaving\.value\) return;/);
  assert.match(saveHotel, /finally \{\s*hotelSaving\.value = false;/);
  assert.match(saveUser, /if \(userSaving\.value\) return;/);
  assert.match(saveUser, /await loadUsers\(\);/);
  assert.match(saveUser, /finally \{\s*userSaving\.value = false;/);
  assert.match(html, /:disabled="userSaving"/);
  assert.match(html, /:disabled="hotelSaving"/);
});

test('new hotel code and status are controlled by the server', () => {
  const hotelModal = sliceFrom('<!-- 酒店模态框 -->', '<!-- 线上数据编辑模态框 -->');
  const saveHotel = sliceFrom('const saveHotel = async () => {', '\n\n            const toggleHotelStatus');

  assert.match(hotelModal, /编号自动生成，创建后默认营业/);
  assert.match(hotelModal, /<div v-if="hotelForm\.id">\s*<label[^>]*>门店编号<\/label>[\s\S]*readonly=""/);
  assert.match(hotelModal, /<div v-if="hotelForm\.id">\s*<label[^>]*>门店状态<\/label>/);
  assert.doesNotMatch(hotelModal, /默认按录入顺序生成，可手动调整/);
  assert.match(saveHotel, /if \(!isEdit\) \{\s*delete payload\.code;\s*delete payload\.status;/);
  assert.match(hotelController, /\$hotel->code = null;/);
  assert.match(hotelController, /\$hotel->status = HotelModel::STATUS_ENABLED;/);
  assert.match(hotelController, /assignGeneratedHotelCode\(\$hotel\)/);
});

test('employee management has a mobile card view and unambiguous hotel option labels', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  assert.match(usersPage, /data-testid="employee-mobile-list"/);
  assert.match(usersPage, /class="xl:hidden space-y-3"/);
  assert.match(usersPage, /class="hidden xl:block/);
  assert.match(usersPage, /hotelSelectOptionText\(h\)/);
  assert.match(html, /const hotelSelectOptionText = \(hotel = \{\}\) =>/);
});

test('new employee usernames and status are server-controlled while passwords remain random', () => {
  const firstPassword = userAdminStaticApi.defaultIssuedPassword();
  const secondPassword = userAdminStaticApi.defaultIssuedPassword();
  assert.match(firstPassword, /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}$/);
  assert.notEqual(firstPassword, secondPassword);
  assert.match(html, new RegExp(`user-admin-static\\.js\\?v=[^"']*h${userAdminStaticHash}`));

  const openUserModal = sliceFrom('const openUserModal = (u = null) => {', '\n\n            const openUserModalWithRole');
  const saveUser = sliceFrom('const saveUser = async () => {', '\n\n            const userStatusConfirmName');
  const userModal = sliceFrom('<!-- 用户模态框 -->', '<!-- 用户登录信息重置弹框 -->');
  const roleModal = sliceFrom('<!-- 角色模态框 -->', '<!-- 权限设置模态框 -->');
  const saveRole = sliceFrom('const saveRole = async () => {', '\n\n            const deleteRole');

  assert.match(openUserModal, /username: ''/);
  assert.doesNotMatch(openUserModal, /nextVipUsername/);
  assert.match(openUserModal, /password: defaultIssuedPassword\(\)/);
  assert.match(userModal, /用户名自动生成，创建后默认正常/);
  assert.match(userModal, /<div v-if="userForm\.id">\s*<label[^>]*>用户名/);
  assert.match(userModal, /<div v-if="userForm\.id">\s*<label[^>]*>状态/);
  assert.match(saveUser, /if \(!isEdit\) \{\s*delete data\.username;\s*delete data\.status;/);
  assert.match(userController, /\$autoGenerateUsername = \$username === '';/);
  assert.match(userController, /\$user->username = \$this->nextGeneratedUsername\(\);/);
  assert.match(userController, /\$user->status = UserModel::STATUS_ENABLED;/);
  assert.doesNotMatch(userAdminStatic, /nextVipUsername/);

  assert.doesNotMatch(roleModal, /角色标识|角色等级|数字越小权限越大/);
  assert.match(roleModal, /适用账号/);
  assert.match(roleModal, /门店使用人员/);
  assert.match(roleModal, /内部运营人员/);
  assert.match(html, /roleForm = ref\(\{ id: null, name: '', display_name: '', description: '', level: 3/);
  assert.match(roleModal, /<div v-if="roleForm\.id">\s*<label[^>]*>状态/);
  assert.match(saveRole, /if \(!isEdit\) \{\s*delete data\.name;\s*delete data\.status;/);
  assert.match(roleController, /\$roleName = trim\(\(string\)\(\$data\['name'\] \?\? ''\)\);/);
  assert.match(roleController, /\$roleName = \$this->nextGeneratedRoleName\(\);/);
  assert.match(roleController, /\$role->status = Role::STATUS_ENABLED;/);
  assert.match(html, /data-testid="new-user-password"/);
  assert.match(html, /生成随机临时密码/);
  assert.doesNotMatch(html, /默认密码固定为 666666/);
  assert.doesNotMatch(html, /换一组6位数字/);
});

test('employee hotel scope uses ellipsis while preserving the full list', () => {
  assert.deepEqual(
    JSON.parse(JSON.stringify(userAdminStaticApi.summarizeUserHotelScope([
      '门店一',
      '门店二',
      '门店三',
      '门店四',
      '门店五',
    ]))),
    {
      summary: '门店一、门店二',
      full: '门店一、门店二、门店三、门店四、门店五',
      hiddenCount: 3,
      total: 5,
    }
  );
  assert.equal(userAdminStaticApi.summarizeUserHotelScope(['门店一', '门店二']).hiddenCount, 0);
  assert.equal(userAdminStaticApi.summarizeUserHotelScope([]).summary, '未分配门店');

  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  assert.match(usersPage, /userHotelScopeSummary\(u\)\.full/);
  assert.match(usersPage, /user-hotel-scope-compact/);
  assert.match(usersPage, /data-testid="employee-desktop-table"[^>]*table-fixed/);
  assert.match(usersPage, /data-testid="employee-hotel-scope-column"[^>]*width:\s*20%/);
  assert.match(usersPage, /data-testid="employee-actions-column"[^>]*width:\s*31%/);
  assert.match(usersPage, /truncate/);
  assert.doesNotMatch(usersPage, /userHotelScopeSummary\(u\)\.hiddenCount/);
  assert.doesNotMatch(usersPage, />\+\{\{ userHotelScopeSummary\(u\)\.hiddenCount \}\}家</);
  assert.doesNotMatch(usersPage, />\{\{ userHotelScopeText\(u\) \}\}<\/span>/);
});

test('employee sequence starts at zero only for administrators', () => {
  assert.equal(userAdminStaticApi.resolveUserDisplaySequence(0, true), 0);
  assert.equal(userAdminStaticApi.resolveUserDisplaySequence(1, true), 1);
  assert.equal(userAdminStaticApi.resolveUserDisplaySequence(0, false), 1);
  assert.equal(userAdminStaticApi.resolveUserDisplaySequence(1, false), 2);

  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  assert.match(usersPage, /userDisplaySequence\(index\)/);
  assert.doesNotMatch(usersPage, /\{\{ index \+ 1 \}\}/);
});

test('existing user login actions omit redundant copy action while retaining password reset', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  const loginInfoFlow = sliceFrom('const openUserLoginInfoModal = (u = {}) => {', '\n\n            const copyLastUserIssueGuide');
  const editablePasswordInput = html.match(/<input[^>]*data-testid="user-login-info-password"[^>]*>/)?.[0] || '';
  const loginAddressReferences = html.match(/`登录地址：\$\{accountHandoffLoginUrl\}`/g) || [];

  assert.doesNotMatch(usersPage, />复制账号<\/(?:span|button)>/);
  assert.match(usersPage, />重置密码<\/span>/);
  assert.match(html, /const accountHandoffLoginUrl = 'https:\/\/www\.glslsuxi\.cn\/';/);
  assert.equal(loginAddressReferences.length, 3);
  assert.match(html, /const copyUserBasicLoginInfo = async \(u = \{\}\) =>/);
  assert.match(html, /密码未修改/);
  assert.match(html, /data-testid="user-login-info-modal"/);
  assert.match(html, /当前密码无法查看/);
  assert.ok(editablePasswordInput);
  assert.match(editablePasswordInput, /v-model="userLoginInfoPassword"/);
  assert.match(editablePasswordInput, /minlength="12"/);
  assert.doesNotMatch(editablePasswordInput, /maxlength=/);
  assert.doesNotMatch(editablePasswordInput, /\sreadonly(?:=|\s|>)/);
  assert.match(html, /需符合系统密码策略：至少12位，含大小写字母、数字和特殊字符/);
  assert.match(loginInfoFlow, /method: 'PUT'/);
  assert.match(loginInfoFlow, /body: JSON\.stringify\(\{ password: issuedPassword \}\)/);
  assert.match(loginInfoFlow, /登录地址：/);
  assert.match(loginInfoFlow, /用户名：/);
  assert.match(loginInfoFlow, /密码：/);
  assert.match(loginInfoFlow, /const copied = await copyToClipboard\(text\)/);
  assert.match(loginInfoFlow, /issuedPassword\.length < 12/);
  assert.match(loginInfoFlow, /临时密码至少12位，并包含大小写字母、数字和特殊字符/);
  assert.match(loginInfoFlow, /if \(!copied\) \{[\s\S]*密码已重置，但复制失败[\s\S]*return;/);
  assert.match(loginInfoFlow, /showToast\('密码已重置，登录信息已复制', 'success'\);[\s\S]*showUserLoginInfoModal\.value = false;/);
  assert.doesNotMatch(html, /换一组6位数字/);
  assert.match(html, /重置密码并复制登录信息/);
  assert.match(html, /重置密码并复制登录地址、用户名和密码/);
  assert.match(loginInfoFlow, /密码已重置，登录信息已复制/);
  assert.doesNotMatch(loginInfoFlow, /随机临时密码已重置/);
  assert.doesNotMatch(loginInfoFlow, /u\?\.password|target\?\.password/);
});

test('employee list distinguishes loading failures from a verified empty snapshot', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  const loadUsersFlow = sliceFrom('const loadUsers = async (options = {}) => {', '\n\n            const loadRoles');

  assert.match(html, /const usersLoading = ref\(false\);/);
  assert.match(html, /const usersLoadError = ref\(''\);/);
  assert.match(html, /const usersSnapshotReady = ref\(false\);/);
  assert.match(html, /let usersRequestSeq = 0;/);
  assert.match(loadUsersFlow, /const requestSession = captureAuthSession\(\);/);
  assert.match(loadUsersFlow, /const requestSeq = \+\+usersRequestSeq;/);
  assert.match(loadUsersFlow, /requestSeq === usersRequestSeq[\s\S]*isAuthSessionCurrent\(requestSession\)/);
  assert.match(loadUsersFlow, /usersLoading\.value = true;/);
  assert.match(loadUsersFlow, /usersLoadError\.value = '';/);
  assert.match(loadUsersFlow, /await request\([\s\S]*if \(!isCurrentRequest\(\)\) return users\.value;/);
  assert.match(loadUsersFlow, /if \(!Array\.isArray\(res\.data\?\.list\)\)/);
  assert.match(loadUsersFlow, /usersSnapshotReady\.value = true;/);
  assert.match(loadUsersFlow, /catch \(error\) \{\s*if \(!isCurrentRequest\(\)\) return users\.value;/);
  assert.match(loadUsersFlow, /usersLoadError\.value = message;/);
  assert.match(loadUsersFlow, /finally \{\s*if \(isCurrentRequest\(\)\) usersLoading\.value = false;/);
  assert.match(usersPage, /data-testid="employee-list-loading"/);
  assert.match(usersPage, /data-testid="employee-list-load-error"/);
  assert.match(usersPage, /data-testid="employee-list-stale-loading"/);
  assert.match(usersPage, /data-testid="employee-list-stale-error"/);
  assert.match(usersPage, /加载失败，不代表无用户/);
  assert.match(usersPage, /加载失败，当前显示上次成功结果；不代表当前真实状态/);
  assert.match(usersPage, /@click="loadUsers\(\)"/);
  assert.match(html, /if \(!usersSnapshotReady\.value\) \{\s*return \{ total: '—'/);
  assert.match(usersPage, /v-else-if="usersSnapshotReady && !usersLoading && !usersLoadError"[\s\S]*暂无员工数据/);
});

test('tenant binding gaps are visible and block credential handoff', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  const tenantScopeFlow = sliceFrom('const userTenantScopeStatus = (u = {}) => {', '\n\n            const existingUserIssueGuideBlocker');
  const existingUserBlocker = sliceFrom('const existingUserIssueGuideBlocker = (u = {}) => {', '\n\n            const userIssueStatus');
  const copyBasicFlow = sliceFrom('const copyUserBasicLoginInfo = async (u = {}) => {', '\n\n            const toggleAllFilteredUsers');

  assert.match(tenantScopeFlow, /tenant_scope_status/);
  assert.match(tenantScopeFlow, /\['global', 'bound', 'binding_missing'\]/);
  assert.match(tenantScopeFlow, /tenant_scope_message/);
  assert.match(tenantScopeFlow, /userTenantBindingMissing/);
  assert.match(existingUserBlocker, /const tenantBlocker = userTenantScopeBlocker\(u\);/);
  assert.match(copyBasicFlow, /const tenantBlocker = userTenantScopeBlocker\(u\);[\s\S]*showToast\(tenantBlocker, 'error'\);[\s\S]*return;/);
  assert.match(usersPage, /userTenantBindingMissing\(u\)/);
  assert.match(usersPage, />租户未绑定<\/span>/);
  assert.match(usersPage, /:title="userTenantScopeMessage\(u\)"/);
});

test('employee workbench supports assignment search and previewed batch status changes', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  const batchFlow = sliceFrom('const batchUpdateUserStatus = async (status) => {', '\n\n            const closeUserLoginInfoModal');
  assert.match(html, /v-model="userHotelAssignmentSearch"/);
  assert.match(html, /userHotelAssignmentSelectedOnly/);
  assert.match(html, /filteredUserAssignmentHotels/);
  assert.match(usersPage, /v-model="selectedUserIds"/);
  assert.match(usersPage, /批量启用/);
  assert.match(usersPage, /批量暂停/);
  assert.match(batchFlow, /confirm: false/);
  assert.match(batchFlow, /window\.confirm/);
  assert.match(batchFlow, /confirm: true/);
});
