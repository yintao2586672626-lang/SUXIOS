import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');

const systemStatic = read('public/system-static.js');
const appMain = read('public/app-main.js');
const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
const fragment = read('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html');

test('operating growth archive has a findable operations entry and a mounted page fragment', () => {
  assert.match(systemStatic, /name:\s*['"]经营成长档案['"],\s*path:\s*['"]operating-growth-archive['"]/);
  assert.ok(manifest.fragments.some(item => (
    item.id === 'page-operating-growth-archive'
      && item.path === 'fragments/17a-page-operating-growth-archive.html'
  )));
  assert.match(fragment, /currentPage === 'operating-growth-archive'/);
  assert.match(fragment, /v-bind="operatingGrowthArchiveBindings"/);
  assert.match(fragment, /v-on="operatingGrowthArchiveListeners"/);
  assert.match(appMain, /'change-hotel':\s*changeOperatingGrowthHotel/);
  assert.match(appMain, /'submit-event':\s*submitOperatingGrowthEvent/);
  assert.match(appMain, /'add-note':\s*addOperatingGrowthAnnotation/);
  assert.match(appMain, /'set-milestone':\s*setOperatingGrowthMilestone/);
});

test('timeline read is scoped by exact hotel, system hotel and date range', () => {
  assert.match(appMain, /params\.set\('hotel_id',\s*hotelId\)/);
  assert.match(appMain, /params\.set\('system_hotel_id',\s*hotelId\)/);
  assert.match(appMain, /params\.set\('date_start',\s*range\.dateStart\)/);
  assert.match(appMain, /params\.set\('date_end',\s*range\.dateEnd\)/);
  assert.match(appMain, /\/operation\/growth-archive\/timeline\?\$\{params\.toString\(\)\}/);
  assert.match(appMain, /Number\(payload\.hotel_id[^\n]+Number\(hotelId\)/);
  assert.match(appMain, /crossHotel[^\n]+row[^\n]+hotel_id/);
});

test('every archive write requires persistence boundaries and an exact GET readback', () => {
  assert.match(appMain, /persistence_status[^\n]+readback_verified/);
  assert.match(appMain, /write_boundaries\?\.ota_write !== false/);
  assert.match(appMain, /write_boundaries\?\.external_message !== false/);
  assert.match(appMain, /\/operation\/operating-memories\/\$\{memoryId\}\?\$\{params\.toString\(\)\}/);
  assert.match(appMain, /readback\.content_digest[^\n]+saved\.content_digest/);

  for (const endpoint of [
    "'/operation/growth-archive/events'",
    '`/operation/growth-archive/${memoryId}/annotations`',
    '`/operation/growth-archive/${memoryId}/milestone`',
  ]) {
    assert.ok(appMain.includes(endpoint), `missing archive write endpoint ${endpoint}`);
  }

  const strictReadbackCalls = appMain.match(/await verifyOperatingGrowthWriteReadback\(res\.data, hotelId\)/g) || [];
  assert.ok(strictReadbackCalls.length >= 3, 'all three write paths must perform strict readback');
});

test('manual archive writes preserve source scope and never claim OTA execution', () => {
  assert.match(appMain, /ctrip:\s*\{\s*platform:\s*'ctrip',\s*source_scope:\s*'ota_channel'\s*\}/);
  assert.match(appMain, /meituan:\s*\{\s*platform:\s*'meituan',\s*source_scope:\s*'ota_channel'\s*\}/);
  assert.match(appMain, /whole_hotel:\s*\{\s*platform:\s*'manual',\s*source_scope:\s*'whole_hotel'\s*\}/);
  assert.match(appMain, /business_date:\s*draft\.date/);
  assert.match(appMain, /occurred_at:\s*`\$\{draft\.date\} \$\{draft\.time\}:00`/);
  assert.match(appMain, /evidence_refs:\s*\[\]/);
});
