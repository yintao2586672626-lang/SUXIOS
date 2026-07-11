import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const html = fs.readFileSync(new URL('../../public/index.html', import.meta.url), 'utf8');
const hotelController = fs.readFileSync(new URL('../../app/controller/Hotel.php', import.meta.url), 'utf8');
const otaConfigConcern = fs.readFileSync(new URL('../../app/controller/concern/OtaConfigConcern.php', import.meta.url), 'utf8');
const userAdminStatic = fs.readFileSync(new URL('../../public/user-admin-static.js', import.meta.url), 'utf8');
const userAdminSandbox = { window: {} };
vm.runInNewContext(`${userAdminStatic}\nthis.__userAdminStatic = window.SUXI_USER_ADMIN_STATIC;`, userAdminSandbox);
const userAdminStaticApi = userAdminSandbox.__userAdminStatic;

test('hotel deletion is super-admin only and removes linked data after exact-name confirmation', () => {
  assert.match(hotelController, /public function delete\(int \$id\): Response[\s\S]*?\$this->checkPermission\(true\)/);
  assert.match(hotelController, /HotelCascadeDeletionService/);
  assert.match(hotelController, /confirmation_name/);
  assert.match(html, /v-model="hotelDeleteConfirmationName"/);
  assert.match(html, /请输入完整门店名称/);
  assert.match(html, /酒店及关联数据已删除/);
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

test('employee management has a mobile card view and unambiguous hotel option labels', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  assert.match(usersPage, /data-testid="employee-mobile-list"/);
  assert.match(usersPage, /class="xl:hidden space-y-3"/);
  assert.match(usersPage, /class="hidden xl:block/);
  assert.match(usersPage, /hotelSelectOptionText\(h\)/);
  assert.match(html, /const hotelSelectOptionText = \(hotel = \{\}\) =>/);
});

test('new employee credentials use the next VIP username and fixed password 666666', () => {
  assert.equal(userAdminStaticApi.nextVipUsername([]), 'VIP001');
  assert.equal(userAdminStaticApi.nextVipUsername([
    { username: 'admin' },
    { username: 'VIP001' },
    { username: 'vip009' },
    { username: 'VIP12345' },
    { username: 'VIP-demo' },
  ]), 'VIP12346');
  assert.equal(userAdminStaticApi.defaultIssuedPassword(), '666666');
  assert.match(html, /user-admin-static\.js\?v=20260711-vip-credential-helper-export-v2/);

  const openUserModal = sliceFrom('const openUserModal = (u = null) => {', '\n\n            const openUserModalWithRole');
  assert.match(openUserModal, /username: nextVipUsername\(users\.value\)/);
  assert.match(openUserModal, /password: defaultIssuedPassword\(\)/);
  assert.match(html, /data-testid="new-user-password"/);
  assert.match(html, /默认密码固定为 666666/);
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

test('existing user login actions separate non-destructive copy from password reset', () => {
  const usersPage = sliceFrom('<!-- 用户管理 -->', '<!-- 角色管理 -->');
  const loginInfoFlow = sliceFrom('const openUserLoginInfoModal = (u = {}) => {', '\n\n            const copyLastUserIssueGuide');

  assert.match(usersPage, />复制账号<\/span>/);
  assert.match(usersPage, />重置密码<\/span>/);
  assert.match(html, /const copyUserBasicLoginInfo = \(u = \{\}\) =>/);
  assert.match(html, /密码未修改/);
  assert.match(html, /data-testid="user-login-info-modal"/);
  assert.match(html, /当前密码无法查看/);
  assert.match(loginInfoFlow, /method: 'PUT'/);
  assert.match(loginInfoFlow, /body: JSON\.stringify\(\{ password: issuedPassword \}\)/);
  assert.match(loginInfoFlow, /登录地址：/);
  assert.match(loginInfoFlow, /用户名：/);
  assert.match(loginInfoFlow, /密码：/);
  assert.match(loginInfoFlow, /copyToClipboard\(text\)/);
  assert.match(loginInfoFlow, /issuedPassword !== defaultIssuedPassword\(\)/);
  assert.doesNotMatch(html, /换一组6位数字/);
  assert.match(html, /重置为 666666 并复制登录信息/);
  assert.doesNotMatch(loginInfoFlow, /u\?\.password|target\?\.password/);
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
