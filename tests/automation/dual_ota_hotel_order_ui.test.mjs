import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const template = loadFrontendTemplateSource(repoRoot).template;
const frozenAiWorkbenchTemplate = fs.readFileSync(
  path.join(repoRoot, 'resources/frontend/templates/fragments/23b-page-ai-workbench.html'),
  'utf8',
);
const sliceBetween = (start, end) => {
  const startIndex = appMain.indexOf(start);
  const endIndex = appMain.indexOf(end, startIndex + start.length);
  assert.ok(startIndex >= 0 && endIndex > startIndex, `missing source slice: ${start} -> ${end}`);
  return appMain.slice(startIndex, endIndex);
};

test('current user can save and restore an explicit hotel picker order', () => {
  assert.match(appMain, /suxios_dual_ota_hotel_order_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  assert.match(appMain, /const normalizeDualOtaHotelOrderIds = \(orderIds = \[\], options = \[\]\) =>/);
  assert.match(appMain, /const dualOtaHasCustomHotelOrder = computed\(\(\) => dualOtaHotelOrderIds\.value\.length > 0\)/);
  assert.match(appMain, /if \(dualOtaHasCustomHotelOrder\.value\)[\s\S]*normalizeDualOtaHotelOrderIds\(dualOtaHotelOrderIds\.value, options\)/);
  assert.match(appMain, /localStorage\.setItem\(dualOtaHotelOrderStorageKey\(\), JSON\.stringify\(normalizedOrder\)\)/);
  assert.match(appMain, /localStorage\.removeItem\(dualOtaHotelOrderStorageKey\(\)\)/);
  assert.match(appMain, /dualOtaHotelOrderIds\.value = readDualOtaHotelOrderIds\(\)/);
});

test('frozen hotel picker source retains its accessible order editor', () => {
  assert.doesNotMatch(template, /data-testid="dual-ota-hotel-order-dialog"/);
  assert.match(frozenAiWorkbenchTemplate, /<option value="">全部门店<\/option>[\s\S]*v-for="hotel in dualOtaCurrentHotelOptions"/);
  assert.match(frozenAiWorkbenchTemplate, /data-testid="dual-ota-hotel-order-open"/);
  assert.match(frozenAiWorkbenchTemplate, /data-testid="dual-ota-hotel-order-dialog"[\s\S]*role="dialog"[\s\S]*aria-modal="true"/);
  assert.match(frozenAiWorkbenchTemplate, /v-for="\(hotel, index\) in dualOtaHotelOrderRows"/);
  assert.match(frozenAiWorkbenchTemplate, /moveDualOtaHotelOrderToTop\(hotel\.id\)/);
  assert.match(frozenAiWorkbenchTemplate, /moveDualOtaHotelOrder\(hotel\.id, 'up'\)/);
  assert.match(frozenAiWorkbenchTemplate, /moveDualOtaHotelOrder\(hotel\.id, 'down'\)/);
  assert.match(frozenAiWorkbenchTemplate, /@click="saveDualOtaHotelOrder"/);
  assert.match(frozenAiWorkbenchTemplate, /@click="resetDualOtaHotelOrder"/);
  assert.match(frozenAiWorkbenchTemplate, /只改变当前账号的下拉顺序，不改变门店权限或经营数据/);
});

test('current account restores workbench filters and keeps all-hotels as an explicit preference', () => {
  assert.match(appMain, /suxios_dual_ota_workbench_preferences_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  assert.match(appMain, /const normalizeDualOtaWorkbenchPreferences = \(preferences = \{\}\) =>/);
  assert.match(appMain, /Object\.prototype\.hasOwnProperty\.call\(source, 'hotel_id'\)/);
  assert.match(appMain, /const dualOtaSelectedPlatform = ref\(dualOtaDefaultPlatform\)/);
  assert.match(appMain, /const dualOtaSelectedRange = ref\(dualOtaDefaultRange\)/);
  assert.match(appMain, /const dualOtaCompareEnabled = ref\(false\)/);
  assert.match(appMain, /const dualOtaSelectedStoreScope = ref\(dualOtaDefaultStoreScope\)/);
  assert.match(appMain, /onMounted\(\(\) => \{\s*restoreDualOtaWorkbenchPreferences\(\)/);
  assert.match(appMain, /const dualOtaHasAvailableHotels = ref\(false\)/);
  assert.match(appMain, /const dualOtaHasConnectedPlatforms = computed\(\(\) => \(\s*!!dualOtaSelectedHotel\.value \|\| dualOtaHasAvailableHotels\.value/);
  assert.match(appMain, /watch\(compassHotelOptions, \(options\) => \{\s*dualOtaHasAvailableHotels\.value = Array\.isArray\(options\) && options\.length > 0;\s*applyDefaultReportHotel\(\)/);

  const defaultHotelFlow = sliceBetween('const applyDefaultReportHotel = (options = {}) => {', 'watch(compassHotelOptions');
  assert.match(defaultHotelFlow, /Object\.prototype\.hasOwnProperty\.call\(storedPreferences, 'hotel_id'\)/);
  assert.match(defaultHotelFlow, /if \(!storedHotelId\) \{\s*defaultHotelId = '';/);
  assert.match(defaultHotelFlow, /reportHotelOptionExists\(storedHotelId\)/);
  assert.match(defaultHotelFlow, /else if \(!availableOptions\.length\) \{\s*return;/);
});

test('workbench controls persist validated selections and reload them after an account switch', () => {
  const persistence = sliceBetween('const persistDualOtaWorkbenchPreferences = (patch = {}) => {', 'let dualOtaCurrentLossNodes');
  assert.match(persistence, /localStorage\.setItem\(dualOtaWorkbenchPreferenceStorageKey\(\), JSON\.stringify/);
  assert.match(persistence, /platform: dualOtaSelectedPlatform\.value/);
  assert.match(persistence, /range: dualOtaSelectedRange\.value/);
  assert.match(persistence, /compare_enabled: dualOtaCompareEnabled\.value/);
  assert.match(persistence, /store_scope: dualOtaSelectedStoreScope\.value/);

  const compareSetter = sliceBetween('const toggleDualOtaCompare = () => {', 'const dualOtaMetricNoteText');
  assert.match(compareSetter, /persistDualOtaWorkbenchPreferences\(\)/);

  const controlSetters = sliceBetween('const setDualOtaPlatform = (platform, options = {}) => {', 'const switchDualOtaConnection');
  assert.match(controlSetters, /persistDualOtaWorkbenchPreferences\(\{ platform: value \}\)/);
  assert.match(controlSetters, /persistDualOtaWorkbenchPreferences\(\{ range: value \}\)/);
  assert.match(controlSetters, /persistDualOtaWorkbenchPreferences\(\{ store_scope: value, platform: value \}\)/);

  const accountSwitch = sliceBetween('watch(() => user.value?.id, (newUserId, previousUserId) => {', 'watch(filterReportHotel');
  assert.match(accountSwitch, /restoreDualOtaWorkbenchPreferences\(\)/);

  const hotelSwitch = sliceBetween('watch(filterReportHotel, (newHotelId, previousHotelId) => {', 'watch(weatherLocationName');
  assert.match(hotelSwitch, /user\.value\?\.id && token\.value/);
  assert.match(hotelSwitch, /reportHotelOptionExists\(normalizedHotelId\)/);
  assert.match(hotelSwitch, /persistDualOtaWorkbenchPreferences\(\{ hotel_id: normalizedHotelId \}\)/);
});
