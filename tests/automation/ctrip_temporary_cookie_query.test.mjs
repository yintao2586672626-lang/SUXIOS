import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const html = readFileSync('public/index.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const sandbox = { console, window: {} };
vm.runInNewContext(`${ctripStaticSource}\nthis.__api = window.SUXI_CTRIP_STATIC;`, sandbox);
const api = sandbox.__api;

test('Ctrip ranking exposes an unsaved one-shot Cookie query only when no hotel is selected', () => {
  assert.match(
    html,
    /const shouldShowCtripRankingManualAuxiliary = computed\(\(\) => onlineDataTab\.value === 'ctrip-ranking' && !selectedCtripHotelId\.value\);/
  );
  assert.match(html, /v-model="ctripForm\.cookies"(?![^>]*\bdisabled\b)[^>]*rows="3"/s);
  assert.match(html, /仅用于本次查询，不保存 Cookie、不创建门店、不入库/);
  assert.match(html, /if \(selectedCtripHotelId\.value\) return selectedCtripManualCredentialState\.value\.canFetch;/);
  assert.match(html, /return normalizeCtripTemporaryCookie\(ctripForm\.value\) !== '';/);
});

test('temporary Cookie request is display-only while selected-hotel execution keeps vault credentials isolated', () => {
  const temporary = api.buildCtripFetchRequestContext({
    form: {
      url: 'https://ebooking.ctrip.com/api/report',
      nodeId: '24588',
      startDate: '2026-07-11',
      endDate: '2026-07-11',
      cookies: 'session=temp-cookie-value',
    },
  });

  assert.equal(temporary.ok, true);
  assert.equal(temporary.temporaryCookieQuery, true);
  assert.equal(temporary.requestBody.cookies, 'session=temp-cookie-value');
  assert.equal(temporary.requestBody.auto_save, false);
  assert.equal('system_hotel_id' in temporary.requestBody, false);
  assert.equal('config_id' in temporary.requestBody, false);
  assert.doesNotMatch(JSON.stringify(temporary.debugMeta), /temp-cookie-value/);

  const selectedHotel = api.buildCtripFetchRequestContext({
    form: {
      nodeId: '24588',
      cookies: 'must-not-leave-the-browser',
    },
    configId: 'ctrip-80',
    selectedCtripHotelId: 80,
    platformHotelId: '880080',
  });

  assert.equal(selectedHotel.ok, true);
  assert.equal(selectedHotel.temporaryCookieQuery, false);
  assert.equal(selectedHotel.requestBody.config_id, 'ctrip-80');
  assert.equal('cookies' in selectedHotel.requestBody, false);
});

test('temporary Cookie flow uses the dedicated endpoint and never refreshes persisted snapshots', async () => {
  const calls = [];
  const refreshes = [];
  const notifications = [];

  const result = await api.runCtripFetchDataFlow({
    isLoggedIn: () => true,
    getSelectedCtripHotelId: () => '',
    getActiveCtripConfig: () => ({
      id: 'unrelated-saved-config',
      config_id: 'unrelated-saved-config',
      credential_status: 'ready',
      has_cookies: true,
      configuration_verified: true,
    }),
    getForm: () => ({
      nodeId: '24588',
      startDate: '2026-07-11',
      endDate: '2026-07-11',
      cookies: 'session=one-shot',
    }),
    requestFetch: async body => {
      calls.push({ endpoint: 'saved', body });
      throw new Error('saved endpoint must not be used');
    },
    requestTemporaryFetch: async body => {
      calls.push({ endpoint: 'temporary', body });
      return {
        code: 200,
        message: '临时 Cookie 查询成功；结果仅本页展示，未保存 Cookie、未创建门店、未入库。',
        data: {
          saved_count: 0,
          save_status: 'display_only',
          display_hotel_count: 1,
          display_hotels: [{ hotelId: '1', hotelName: '临时结果' }],
        },
      };
    },
    notify: (message, level) => notifications.push({ message, level }),
    useDisplayHotels: rows => rows,
    refreshOnlineHistory: () => refreshes.push('history'),
    refreshLatestCtripData: () => refreshes.push('latest'),
    refreshOnlineData: () => refreshes.push('online'),
  });

  assert.equal(result.status, 'display_only');
  assert.equal(calls.length, 1);
  assert.equal(calls[0].endpoint, 'temporary');
  assert.equal(calls[0].body.auto_save, false);
  assert.deepEqual(refreshes, []);
  assert.deepEqual(notifications.at(-1), {
    message: '临时 Cookie 查询成功；结果仅本页展示，未保存 Cookie、未创建门店、未入库。',
    level: 'info',
  });
});
