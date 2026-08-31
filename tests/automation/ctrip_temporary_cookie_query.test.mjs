import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
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
          persistence_status: 'display_only',
          persisted: false,
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

test('selected-hotel response with rows but no persistence stays non-success and does not refresh snapshots', async () => {
  const refreshes = [];
  const notifications = [];
  const successStates = [];

  const result = await api.runCtripFetchDataFlow({
    isLoggedIn: () => true,
    getSelectedCtripHotelId: () => '80',
    getActiveCtripConfig: () => ({
      id: 'ctrip-80',
      config_id: 'ctrip-80',
      credential_status: 'ready',
      has_cookies: true,
      configuration_verified: true,
      system_hotel_id: 80,
    }),
    getForm: () => ({ nodeId: '24588', startDate: '2026-07-11', endDate: '2026-07-11' }),
    requestFetch: async () => ({
      code: 200,
      message: '携程数据已获取，但没有真实入库数据。',
      data: {
        saved_count: 0,
        save_status: 'saved_or_empty',
        persistence_status: 'not_persisted',
        persisted: false,
        display_hotel_count: 1,
        display_hotels: [{ hotelId: '1', hotelName: '展示结果' }],
      },
    }),
    notify: (message, level) => notifications.push({ message, level }),
    setFetchSuccess: value => successStates.push(value),
    useDisplayHotels: rows => rows,
    refreshOnlineHistory: () => refreshes.push('history'),
    refreshLatestCtripData: () => refreshes.push('latest'),
    refreshOnlineData: () => refreshes.push('online'),
  });

  assert.equal(result.status, 'no_saved');
  assert.equal(successStates.at(-1), false);
  assert.deepEqual(refreshes, []);
  assert.equal(notifications.at(-1).level, 'warning');
});

test('date-unverified 422 response stays visible for audit without refreshing trusted downstream state', async () => {
  const refreshes = [];
  const notifications = [];
  const successStates = [];
  const savedCounts = [];
  const visibleRows = [];
  const latestMeta = [];
  const failures = [];
  const tableTabs = [];

  const result = await api.runCtripFetchDataFlow({
    isLoggedIn: () => true,
    getSelectedCtripHotelId: () => '121',
    getActiveCtripConfig: () => ({
      id: 'ctrip-121',
      config_id: 'ctrip-121',
      credential_status: 'ready',
      has_cookies: true,
      configuration_verified: true,
      system_hotel_id: 121,
    }),
    getForm: () => ({ nodeId: '24588', startDate: '2026-08-30', endDate: '2026-08-30' }),
    requestFetch: async () => ({
      code: 422,
      message: '携程请求日期 2026-08-30 与平台返回业务日 2026-08-29 不一致；本次仅展示响应，未入库。',
      data: {
        saved_count: 0,
        save_status: 'target_date_unverified',
        persistence_status: 'blocked',
        persisted: false,
        readback_verified: false,
        display_hotel_count: 1,
        display_hotels: [{ hotelId: '5488189', hotelName: '审计展示行', amount: 26941.76 }],
        fetched_at: '2026-08-31 03:20:13',
        request_start_date: '2026-08-30',
        request_end_date: '2026-08-30',
        source_business_date: '2026-08-29',
        response_date_status: 'target_date_mismatch',
      },
    }),
    notify: (message, level) => notifications.push({ message, level }),
    setFetchSuccess: value => successStates.push(value),
    setSavedCount: value => savedCounts.push(value),
    useDisplayHotels: rows => { visibleRows.push(...rows); return rows; },
    getLatestMeta: () => null,
    setLatestMeta: value => latestMeta.push(value),
    setTableTab: value => tableTabs.push(value),
    updateAiAnalysisHotelList: () => refreshes.push('ai'),
    refreshOnlineHistory: () => refreshes.push('history'),
    refreshLatestCtripData: () => refreshes.push('latest'),
    refreshOnlineData: () => refreshes.push('online'),
    handleFetchFailure: message => failures.push(message),
  });

  assert.equal(result.status, 'source_unverified');
  assert.equal(successStates.at(-1), false);
  assert.equal(savedCounts.at(-1), 0);
  assert.equal(visibleRows.length, 1);
  assert.equal(latestMeta.at(-1).status, 'source_unverified');
  assert.equal(latestMeta.at(-1).request_date, '2026-08-30');
  assert.equal(latestMeta.at(-1).source_business_date, '2026-08-29');
  assert.equal(latestMeta.at(-1).data_date, '');
  assert.equal(tableTabs.at(-1), 'sales');
  assert.deepEqual(refreshes, []);
  assert.deepEqual(failures, []);
  assert.equal(notifications.at(-1).level, 'warning');
});
