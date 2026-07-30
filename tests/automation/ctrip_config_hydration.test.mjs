import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const sandbox = { console, URL, window: {} };
vm.runInNewContext(
  `${ctripStaticSource}\nthis.__api = window.SUXI_CTRIP_STATIC;`,
  sandbox,
  { filename: 'public/ctrip-static.js' },
);
const ctripStatic = sandbox.__api;

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  assert.notEqual(start, -1, `missing start marker: ${startNeedle}`);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  assert.notEqual(end, -1, `missing end marker: ${endNeedle}`);
  return source.slice(start, end);
};

test('Ctrip form reuses stable fields for the selected hotel and keeps secrets blank', () => {
  const form = ctripStatic.buildCtripConfigFormForHotel({
    hotelId: '58',
    hotelName: '测试酒店',
    configs: [
      {
        config_id: 'ctrip-58',
        hotel_id: 58,
        name: '已保存携程配置',
        ctrip_hotel_id: 'ctrip-hotel-58',
        hotel_room_count: 37,
        competitor_room_count: 200,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
      {
        config_id: 'ctrip-59',
        hotel_id: 59,
        ctrip_hotel_id: 'ctrip-hotel-59',
        hotel_room_count: 99,
        competitor_room_count: 999,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
    ],
  });

  assert.equal(form.id, 'ctrip-58');
  assert.equal(form.hotel_id, '58');
  assert.equal(form.ctrip_hotel_id, 'ctrip-hotel-58');
  assert.equal(form.hotel_room_count, 37);
  assert.equal(form.competitor_room_count, 200);
  assert.equal(form.has_cookies, true);
  assert.equal(form.cookies, '');
});

test('hotel-management Ctrip entry loads persisted configuration and fails closed on read errors', () => {
  const openManualConfig = sliceBetween(
    appMain,
    'const openHotelManualFetchConfig = async',
    'const buildHotelPlatformLoginItem =',
  );
  assert.match(openManualConfig, /await loadCtripConfigList\(\{ force: true, applySelectedConfig: false \}\);/);
  assert.match(openManualConfig, /if \(ctripConfigListLoadFailed\.value\)/);
  assert.match(openManualConfig, /hydrateCtripConfigFormForHotel\(hotelId\);/);
  assert.doesNotMatch(openManualConfig, /ctripConfigForm\.value\s*=\s*createCtripConfigForm/);
});
