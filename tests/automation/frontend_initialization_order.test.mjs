import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');

test('report hotel state is initialized before dual-OTA computed values read it', () => {
  const declaration = appMain.indexOf("const filterReportHotel = ref('');");
  const firstComputedRead = appMain.indexOf('const dualOtaSelectedHotel = computed(() => {');

  assert.ok(declaration >= 0, 'filterReportHotel declaration is missing');
  assert.ok(firstComputedRead >= 0, 'dualOtaSelectedHotel computed declaration is missing');
  assert.ok(
    declaration < firstComputedRead,
    'filterReportHotel must be initialized before dualOtaSelectedHotel is evaluated',
  );
});
