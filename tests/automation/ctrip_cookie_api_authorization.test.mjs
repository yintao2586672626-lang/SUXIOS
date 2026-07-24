import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

function loadCtripStatic() {
  const source = fs.readFileSync(path.join(root, 'public/ctrip-static.js'), 'utf8');
  const context = { window: {}, console, setTimeout, clearTimeout };
  vm.createContext(context);
  vm.runInContext(source, context, { filename: 'ctrip-static.js' });
  return context.window.SUXI_CTRIP_STATIC;
}

const api = loadCtripStatic();

function cookieApiFlowOptions(config, requestCapture = async () => ({
  code: 200,
  message: 'ok',
  data: { saved_count: 1, is_ready: true },
})) {
  return {
    getSelectedCtripHotelId: () => '7',
    hasCtripConfigList: () => true,
    getActiveCtripConfig: () => config,
    findCtripConfigByHotelId: () => config,
    getForm: () => ({ requestSource: 'traffic_report' }),
    getOverviewForm: () => ({ dataDate: '2026-07-11' }),
    resolveProfileId: () => 'system_7',
    resolveRequestHotelId: () => '832085',
    requestCapture,
  };
}

test('manual Cookie API accepts a ready Cookie config without browser Profile verification', async () => {
  let requestCount = 0;
  const result = await api.runCtripCookieApiCaptureFlow(cookieApiFlowOptions({
    id: 'ctrip-ready-7',
    config_id: 'ctrip-ready-7',
    system_hotel_id: '7',
    has_cookies: true,
    credential_status: 'ready',
    configuration_verified: false,
  }, async () => {
    requestCount += 1;
    return { code: 200, message: 'ok', data: { saved_count: 1, is_ready: true } };
  }));

  assert.equal(result.status, 'success');
  assert.equal(requestCount, 1);
});

test('manual Cookie API reports the exact missing authorization item', async () => {
  const missingBinding = await api.runCtripCookieApiCaptureFlow(cookieApiFlowOptions(null));
  assert.equal(missingBinding.status, 'missing_config');
  assert.match(missingBinding.message, /未绑定携程授权配置/);
  assert.match(missingBinding.message, /数据抓取设置/);

  const missingConfigId = await api.runCtripCookieApiCaptureFlow(cookieApiFlowOptions({
    system_hotel_id: '7',
    has_cookies: true,
    credential_status: 'ready',
  }));
  assert.equal(missingConfigId.status, 'missing_config_id');
  assert.match(missingConfigId.message, /配置ID/);

  const missingCookie = await api.runCtripCookieApiCaptureFlow(cookieApiFlowOptions({
    id: 'ctrip-no-cookie-7',
    config_id: 'ctrip-no-cookie-7',
    system_hotel_id: '7',
    has_cookies: false,
    credential_status: 'ready',
  }));
  assert.equal(missingCookie.status, 'missing_cookie');
  assert.match(missingCookie.message, /Cookie/);

  const expiredCookie = await api.runCtripCookieApiCaptureFlow(cookieApiFlowOptions({
    id: 'ctrip-expired-7',
    config_id: 'ctrip-expired-7',
    system_hotel_id: '7',
    has_cookies: true,
    credential_status: 'revoked',
  }));
  assert.equal(expiredCookie.status, 'credential_not_ready');
  assert.match(expiredCookie.message, /已失效/);
});
