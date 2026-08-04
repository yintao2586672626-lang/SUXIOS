import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const refreshStart = appMain.indexOf('const refreshCoreOperationsLoop = async');
const refreshEnd = appMain.indexOf('const loadPhase3OperationEffectLoop = async', refreshStart);
const refreshCoreOperationsLoop = appMain.slice(refreshStart, refreshEnd);
const defaultHotelStart = appMain.indexOf('const applyDefaultReportHotel =');
const defaultHotelEnd = appMain.indexOf('watch(compassHotelOptions', defaultHotelStart);
const applyDefaultReportHotel = appMain.slice(defaultHotelStart, defaultHotelEnd);

test('dashboard hotel preference is restored inside the current account scope', () => {
  assert.match(
    appMain,
    /suxios_dual_ota_workbench_preferences_\$\{user\.value\?\.id \|\| 'guest'\}_v1/,
  );
  assert.ok(defaultHotelStart >= 0, 'applyDefaultReportHotel must exist');
  assert.ok(defaultHotelEnd > defaultHotelStart, 'applyDefaultReportHotel must have a bounded source slice');
  assert.match(applyDefaultReportHotel, /readDualOtaWorkbenchPreferences\(\)/);
  assert.match(applyDefaultReportHotel, /filterReportHotel\.value = defaultHotelId/);
});

test('yesterday operations inherits only the explicit ordinary hotel context', () => {
  assert.ok(refreshStart >= 0, 'refreshCoreOperationsLoop must exist');
  assert.ok(refreshEnd > refreshStart, 'refreshCoreOperationsLoop must have a bounded source slice');
  assert.match(
    refreshCoreOperationsLoop,
    /options\.hotelId\s*\|\|\s*coreOperationsHotelId\.value\s*\|\|\s*filterReportHotel\.value/,
  );
  assert.doesNotMatch(refreshCoreOperationsLoop, /getAutoFetchHotelId\(\)/);
  assert.match(
    refreshCoreOperationsLoop,
    /operationHotelOptions\.value\.some\([\s\S]*String\(hotel\?\.id \|\| ''\)\.trim\(\) === hotelId/,
    'the inherited hotel must still pass the current account access check',
  );
});
