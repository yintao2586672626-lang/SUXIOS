import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const template = loadFrontendTemplateSource(repoRoot).template;

test('current user can save and restore an explicit hotel picker order', () => {
  assert.match(appMain, /suxios_dual_ota_hotel_order_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  assert.match(appMain, /const normalizeDualOtaHotelOrderIds = \(orderIds = \[\], options = \[\]\) =>/);
  assert.match(appMain, /const dualOtaHasCustomHotelOrder = computed\(\(\) => dualOtaHotelOrderIds\.value\.length > 0\)/);
  assert.match(appMain, /if \(dualOtaHasCustomHotelOrder\.value\)[\s\S]*normalizeDualOtaHotelOrderIds\(dualOtaHotelOrderIds\.value, options\)/);
  assert.match(appMain, /localStorage\.setItem\(dualOtaHotelOrderStorageKey\(\), JSON\.stringify\(normalizedOrder\)\)/);
  assert.match(appMain, /localStorage\.removeItem\(dualOtaHotelOrderStorageKey\(\)\)/);
  assert.match(appMain, /dualOtaHotelOrderIds\.value = readDualOtaHotelOrderIds\(\)/);
});

test('hotel picker exposes an accessible order editor without moving the all-hotels option', () => {
  assert.match(template, /<option value="">全部门店<\/option>[\s\S]*v-for="hotel in dualOtaCurrentHotelOptions"/);
  assert.match(template, /data-testid="dual-ota-hotel-order-open"/);
  assert.match(template, /data-testid="dual-ota-hotel-order-dialog"[\s\S]*role="dialog"[\s\S]*aria-modal="true"/);
  assert.match(template, /v-for="\(hotel, index\) in dualOtaHotelOrderRows"/);
  assert.match(template, /moveDualOtaHotelOrderToTop\(hotel\.id\)/);
  assert.match(template, /moveDualOtaHotelOrder\(hotel\.id, 'up'\)/);
  assert.match(template, /moveDualOtaHotelOrder\(hotel\.id, 'down'\)/);
  assert.match(template, /@click="saveDualOtaHotelOrder"/);
  assert.match(template, /@click="resetDualOtaHotelOrder"/);
  assert.match(template, /只改变当前账号的下拉顺序，不改变门店权限或经营数据/);
});
