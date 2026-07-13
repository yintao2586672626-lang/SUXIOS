import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const userController = fs.readFileSync(new URL('../../app/controller/User.php', import.meta.url), 'utf8');
const hotelController = fs.readFileSync(new URL('../../app/controller/Hotel.php', import.meta.url), 'utf8');
const routes = fs.readFileSync(new URL('../../route/app.php', import.meta.url), 'utf8');
const publicEntry = readFrontendContractSource();
const systemStatic = fs.readFileSync(new URL('../../public/system-static.js', import.meta.url), 'utf8');

test('user list includes secondary hotel assignments and whitelist sorting', () => {
  assert.match(userController, /private function userIdsForHotelScope\(array \$hotelIds\): array/);
  assert.match(userController, /Db::name\('user_hotel_permissions'\)->whereIn\('hotel_id', \$hotelIds\)/);
  assert.match(userController, /\$allowedSorts = \['id', 'username', 'realname', 'status', 'last_login_time', 'create_time'\]/);
  assert.match(userController, /\$query->order\(\$sortBy, \$sortOrder\);/);
  assert.match(userController, /user_hotel_permissions', 'can_view'/);
});

test('batch status endpoints require preview and explicit confirmation', () => {
  assert.match(routes, /Route::post\('\/batch-status', 'User\/batchStatus'\)/);
  assert.match(routes, /Route::post\('\/batch-status', 'Hotel\/batchStatus'\)/);
  assert.match(userController, /public function batchStatus\(\): Response/);
  assert.match(hotelController, /public function batchStatus\(\): Response/);
  assert.match(userController, /if \(!\$confirmed\)/);
  assert.match(hotelController, /if \(!\$confirmed\)/);
  assert.match(userController, /BatchStatusPreviewService/);
  assert.match(hotelController, /BatchStatusPreviewService/);
  assert.match(userController, /'preview_id'\s*=>/);
  assert.match(hotelController, /'preview_id'\s*=>/);
  assert.match(userController, /consume\(\$previewId, 'user_batch_status'/);
  assert.match(hotelController, /consume\(\$previewId, 'hotel_batch_status'/);
  assert.match(publicEntry, /user_ids:\s*ids,\s*status,\s*confirm:\s*true,\s*preview_id:/);
  assert.match(publicEntry, /hotel_ids:\s*ids,\s*status,\s*confirm:\s*true,\s*preview_id:/);
  assert.match(userController, /批量操作不能包含当前登录账号/);
  assert.match(hotelController, /包含无权管理的门店/);
});

test('hotel batch preview deduplicates users across stores without per-store queries', () => {
  assert.match(hotelController, /User::whereIn\('hotel_id', \$hotelIds\)/);
  assert.match(hotelController, /Db::name\('user_hotel_permissions'\)->whereIn\('hotel_id', \$hotelIds\)/);
  assert.match(hotelController, /\$affectedUserIdSet\[/);
  assert.match(hotelController, /'affected_users'\s*=>\s*count\(\$affectedUserIdSet\)/);
  assert.doesNotMatch(hotelController, /foreach \(\$hotels as \$hotel\)[\s\S]{0,900}User::where\('hotel_id'/);
});

test('duplicate hotel names are blocked and handed to the existing merge flow', () => {
  assert.match(systemStatic, /const normalizeHotelIdentityName = \(value = ''\) => String\(value \?\? ''\)\.trim\(\);/);
  assert.match(publicEntry, /system-static\.js\?v=[^"']+/);
  assert.match(hotelController, /private function duplicateHotelByName\(string \$name, \?int \$excludeId = null\): \?HotelModel/);
  assert.match(hotelController, /'duplicate_hotels'\s*=>/);
  assert.match(hotelController, /酒店名称已存在，请先核对并合并/);
  assert.match(publicEntry, /发现同名酒店，请先核对并合并/);
  assert.match(publicEntry, /openHotelMergeModal\(duplicateHotel\)/);
});
