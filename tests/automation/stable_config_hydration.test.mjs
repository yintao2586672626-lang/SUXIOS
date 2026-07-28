import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const meituanStaticSource = readFileSync('public/meituan-static.js', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const sandbox = { console, window: {} };
vm.runInNewContext(
  `${meituanStaticSource}\nthis.__api = window.SUXI_MEITUAN_STATIC;`,
  sandbox,
  { filename: 'public/meituan-static.js' },
);
const meituanStatic = sandbox.__api;
const ctripSandbox = { console, URL, window: {} };
vm.runInNewContext(
  `${ctripStaticSource}\nthis.__api = window.SUXI_CTRIP_STATIC;`,
  ctripSandbox,
  { filename: 'public/ctrip-static.js' },
);
const ctripStatic = ctripSandbox.__api;

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  assert.notEqual(start, -1, `missing start marker: ${startNeedle}`);
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  assert.notEqual(end, -1, `missing end marker: ${endNeedle}`);
  return source.slice(start, end);
};

test('Meituan form reuses stable fields for the selected hotel and keeps secrets blank', () => {
  const form = meituanStatic.buildMeituanConfigFormForHotel({
    hotelId: '58',
    hotelName: '测试酒店',
    configs: [
      {
        config_id: 'meituan-58',
        hotel_id: 58,
        name: '已保存美团配置',
        partner_id: 'partner-58',
        poi_id: 'poi-58',
        hotel_room_count: 37,
        competitor_room_count: 200,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
      {
        config_id: 'meituan-59',
        hotel_id: 59,
        partner_id: 'partner-59',
        poi_id: 'poi-59',
        hotel_room_count: 99,
        competitor_room_count: 999,
        has_cookies: true,
        credential_status: 'ready',
        config_status: 'active',
      },
    ],
  });

  assert.equal(form.id, 'meituan-58');
  assert.equal(form.hotel_id, '58');
  assert.equal(form.partner_id, 'partner-58');
  assert.equal(form.poi_id, 'poi-58');
  assert.equal(form.hotel_room_count, 37);
  assert.equal(form.competitor_room_count, 200);
  assert.equal(form.has_cookies, true);
  assert.equal(form.cookies, '');
});

test('hotel-management Meituan entry loads persisted configuration and fails closed on read errors', () => {
  const openManualConfig = sliceBetween(
    appMain,
    'const openHotelManualFetchConfig = async',
    'const buildHotelPlatformLoginItem =',
  );
  assert.match(openManualConfig, /await loadMeituanConfigList\(\{ force: true, applySelectedConfig: false \}\);/);
  assert.match(openManualConfig, /if \(meituanConfigListLoadFailed\.value\)/);
  assert.match(openManualConfig, /hydrateMeituanConfigFormForHotel\(hotelId\);/);
  assert.doesNotMatch(openManualConfig, /partner_id:\s*''[\s\S]*poi_id:\s*''/);
});

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

test('switching enterprise WeChat hotels resets the non-secret name before scoped readback', () => {
  const changeHotel = sliceBetween(
    appMain,
    'const changeWechatNotificationHotel = () => {',
    'const saveWechatNotificationBinding = async',
  );
  assert.match(changeHotel, /wechatNotificationForm\.value\.name = DEFAULT_WECHAT_NOTIFICATION_NAME;/);
  assert.match(changeHotel, /wechatNotificationForm\.value\.webhook = '';/);
  assert.match(changeHotel, /return loadWechatNotificationStatus\(\);/);
});
