import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const hotelTemplate = readFileSync('resources/frontend/templates/fragments/18-page-hotels.html', 'utf8');
const authController = readFileSync('app/controller/Auth.php', 'utf8');
const defaultHotelService = readFileSync('app/service/UserDefaultHotelService.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

test('hotel management exposes a visible default-main-hotel control and platform boundary', () => {
  assert.match(hotelTemplate, /data-testid="default-main-hotel-summary"/);
  assert.match(hotelTemplate, /data-testid="default-main-hotel-badge"/);
  assert.match(hotelTemplate, /data-testid="set-default-main-hotel"/);
  assert.match(hotelTemplate, /尚未设置可用主门店/);
  assert.match(hotelTemplate, /普通板块将保持未选择/);
  assert.match(hotelTemplate, /普通板块首次进入时默认使用主门店/);
  assert.match(hotelTemplate, /携程、美团仅在该门店已配置对应平台时继承/);
  assert.match(hotelTemplate, /各平台的门店选择互不覆盖/);
});

test('missing or invalid main hotel remains unselected instead of choosing an arbitrary visible hotel', () => {
  const resolver = sliceBetween(
    appMain,
    'const resolveDefaultReportHotelId = () => {',
    '\n            let suppressNextReportHotelDashboardRefresh',
  );
  const applyDefault = sliceBetween(
    appMain,
    'const applyDefaultReportHotel = (options = {}) => {',
    '\n            watch(compassHotelOptions',
  );

  assert.match(resolver, /boundHotelId && reportHotelOptionExists\(boundHotelId\)/);
  assert.match(resolver, /return '';/);
  assert.doesNotMatch(resolver, /permittedHotels\.value.*\[0\]|hotels\.value.*\[0\]|getAutoFetchHotelId/);
  const autoFetchResolver = sliceBetween(
    appMain,
    'const getAutoFetchHotelId = () => {',
    'const collectionReliabilityHasCurrentSnapshot = computed',
  );
  const autoFetchPanelLoader = sliceBetween(
    appMain,
    'const loadAutoFetchPanel = async (options = {}) => {',
    'const autoFetchStatusRequestPromises = new Map();',
  );
  assert.doesNotMatch(autoFetchResolver, /hotels\.value\?\.\[0\]|hotels\.value\[0\]/);
  assert.doesNotMatch(autoFetchPanelLoader, /hotels\.value\s*\[\s*0\s*\]/);
  assert.match(autoFetchPanelLoader, /if \(!autoFetchHotelId\.value\)[\s\S]*autoFetchStatus\.value = null;[\s\S]*return;/);
  assert.match(applyDefault, /const mainHotelId = resolveDefaultReportHotelId\(\)/);
  assert.match(applyDefault, /if \(!mainHotelId\)[\s\S]*filterReportHotel\.value = ''/);
  assert.match(applyDefault, /notifyMissingDefaultReportHotel\(\)/);
});

test('default hotel save is accepted only after exact authenticated readback', () => {
  const setter = sliceBetween(
    appMain,
    'const setDefaultMainHotel = async',
    '\n\n            const saveHotel = async',
  );
  const writeIndex = setter.indexOf("apiRequest('/auth/default-hotel'");
  const readbackIndex = setter.indexOf("apiRequest('/auth/info'");
  const exactMatchIndex = setter.indexOf('readbackHotelId !== hotelId');
  const cacheIndex = setter.indexOf('saveCachedAuthUser(readback.data)');
  const commonContextIndex = setter.indexOf('filterReportHotel.value = hotelId');
  const successIndex = setter.indexOf("showToast(`已将");

  assert.ok(writeIndex >= 0);
  assert.ok(readbackIndex > writeIndex);
  assert.ok(exactMatchIndex > readbackIndex);
  assert.ok(cacheIndex > exactMatchIndex);
  assert.ok(commonContextIndex > cacheIndex);
  assert.ok(successIndex > commonContextIndex);
  assert.match(setter, /已提交，但尚未完成回读确认/);
  assert.doesNotMatch(setter, /selectedCtripHotelId/);
  assert.doesNotMatch(setter, /meituanForm\.value\.hotelId/);
});

test('self-service endpoint changes only the authorized active default hotel', () => {
  const hotelLockIndex = defaultHotelService.indexOf("$lockedHotel = Db::name('hotels')");
  const userLockIndex = defaultHotelService.indexOf("$lockedUserRow = Db::name('users')");
  const tenantLockIndex = defaultHotelService.indexOf("Db::name('tenants')");
  const grantLockIndex = defaultHotelService.indexOf("Db::name('user_hotel_permissions')");

  assert.match(routes, /Route::put\('default-hotel', 'Auth\/setDefaultHotel'\)/);
  assert.match(authController, /public function setDefaultHotel\(\): Response/);
  assert.ok(hotelLockIndex >= 0);
  assert.ok(userLockIndex > hotelLockIndex);
  assert.ok(tenantLockIndex > userLockIndex);
  assert.ok(grantLockIndex > tenantLockIndex);
  assert.match(defaultHotelService, /lockedUserCanAccessHotel\([\s\S]*\$hotelId/);
  assert.match(defaultHotelService, /isActiveGrant\(\(array\)\$row, \$tenantId\)/);
  assert.match(defaultHotelService, /Db::name\('users'\)->where\('id', \$userId\)->update/);
  assert.match(defaultHotelService, /'default_hotel_id'\s*=>\s*\$hotelId/);
  assert.doesNotMatch(defaultHotelService, /user_hotel_permissions[\s\S]*?->insert/);
});
