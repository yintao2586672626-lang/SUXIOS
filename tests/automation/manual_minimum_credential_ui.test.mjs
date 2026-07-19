import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');
const meituanStatic = readFileSync('public/meituan-static.js', 'utf8');
const autoFetchStatic = readFileSync('public/auto-fetch-static.js', 'utf8');
const otaDiagnosisStatic = readFileSync('public/ota-diagnosis-static.js', 'utf8');
const onlineDataTemplateFragment = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const platformAutoSettingsPanels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const ctripProfileFieldConfigPanel = readFileSync('public/components/online-data/ctrip-profile-field-config-panel.js', 'utf8');
const businessDisplayConcern = readFileSync('app/controller/concern/BusinessDisplayConcern.php', 'utf8');
const onlineDataManualFetchConcern = readFileSync('app/controller/concern/OnlineDataManualFetchConcern.php', 'utf8');
const ctripStaticSandbox = { console, window: {} };
vm.runInNewContext(`${ctripStatic}\nthis.__ctripStatic = window.SUXI_CTRIP_STATIC;`, ctripStaticSandbox);
const ctripStaticApi = ctripStaticSandbox.__ctripStatic;
const meituanStaticSandbox = { console, window: {} };
vm.runInNewContext(`${meituanStatic}\nthis.__meituanStatic = window.SUXI_MEITUAN_STATIC;`, meituanStaticSandbox);
const meituanStaticApi = meituanStaticSandbox.__meituanStatic;
const autoFetchStaticSandbox = { console, window: {} };
vm.runInNewContext(`${autoFetchStatic}\nthis.__autoFetchStatic = window.SUXI_AUTO_FETCH_STATIC;`, autoFetchStaticSandbox);
const autoFetchStaticApi = autoFetchStaticSandbox.__autoFetchStatic;
const otaDiagnosisStaticSandbox = { console, window: {} };
vm.runInNewContext(`${otaDiagnosisStatic}\nthis.__otaDiagnosisStatic = window.SUXI_OTA_DIAGNOSIS_STATIC;`, otaDiagnosisStaticSandbox);
const otaDiagnosisStaticApi = otaDiagnosisStaticSandbox.__otaDiagnosisStatic;

const sliceFrom = (needle, endNeedle) => {
  const start = html.indexOf(needle);
  assert.ok(start >= 0, `missing start marker: ${needle}`);
  const end = endNeedle ? html.indexOf(endNeedle, start) : -1;
  return end > start ? html.slice(start, end) : html.slice(start);
};

const mainTemplateSource = () => {
  const appStart = html.indexOf('<div id="app"');
  const mainScriptMarker = 'const { createApp, ref, shallowRef, computed';
  const mainScriptStart = html.indexOf(mainScriptMarker);
  assert.ok(appStart >= 0, 'missing Vue app root');
  assert.ok(mainScriptStart > appStart, 'missing Vue main script');
  const template = html
    .slice(appStart, html.lastIndexOf('<script', mainScriptStart))
    .replace(/<script\b[\s\S]*?<\/script>/gi, '');
  const expressions = [];
  for (const match of template.matchAll(/\{\{([\s\S]*?)\}\}/g)) {
    expressions.push(match[1]);
  }
  for (const match of template.matchAll(/\s(?:@|:|v-(?:bind|else-if|for|html|if|model|on|show|text))[\w:.-]*(?:\.[\w.-]+)*="([^"]*)"/g)) {
    expressions.push(match[1]);
  }
  return expressions.join(';\n');
};

const mainSetupReturnSource = () => {
  const mainScriptMarker = 'const { createApp, ref, shallowRef, computed';
  const mainScriptStart = html.indexOf(mainScriptMarker);
  assert.ok(mainScriptStart >= 0, 'missing Vue main script');
  const script = html.slice(mainScriptStart);
  const returnNeedle = '            return {';
  const returnStart = script.lastIndexOf(returnNeedle);
  assert.ok(returnStart >= 0, 'missing setup return object');
  let depth = 1;
  let index = returnStart + returnNeedle.length;
  for (; index < script.length; index += 1) {
    const char = script[index];
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) break;
    }
  }
  assert.equal(depth, 0, 'unterminated setup return object');
  return script.slice(returnStart, index + 1);
};

const functionSlice = (name) => sliceFrom(`const ${name} = async () => {`, `\n            const `);
const constSlice = (needle, endNeedle = '\n            const ') => sliceFrom(needle, endNeedle);

const assertNoExecutionSecretFields = (body) => {
  const forbidden = [
    'cookies', 'cookie', 'auth_data', 'authData', 'spidertoken', 'mtgsig', '_mtsi_eb_u',
    'payload_json', 'extra_params', 'endpoints_json', 'authorization', 'api_key', 'token',
  ];
  forbidden.forEach(key => assert.equal(Object.prototype.hasOwnProperty.call(body, key), false, `execution body leaked ${key}`));
};

test('OTA execution request builders send config locators and never browser-held secrets', () => {
  const ctripBodies = [
    ctripStaticApi.buildCtripFetchRequestBody({
      form: { url: 'https://ebooking.ctrip.com/api/rank', cookies: 'secret', auth_data: { token: 'secret' } },
      configId: 'ctrip-58', nodeId: '24588', startDate: '2026-07-09', endDate: '2026-07-09', systemHotelId: 58,
    }),
    ctripStaticApi.buildCtripTrafficFetchRequestBody({
      form: { url: 'https://ebooking.ctrip.com/api/traffic', cookies: 'secret', extraParams: '{"token":"secret"}' },
      configId: 'ctrip-58', systemHotelId: 58,
    }),
    ctripStaticApi.buildCtripOverviewFetchRequestBody({
      configId: 'ctrip-58', systemHotelId: 58, requestUrls: 'https://ebooking.ctrip.com/api/overview',
      form: { payloadJson: '{"token":"secret"}', spidertoken: 'secret' },
    }),
    ctripStaticApi.buildCtripAdsFetchRequestBody({
      configId: 'ctrip-58', systemHotelId: 58, url: 'https://ebooking.ctrip.com/api/ads',
      form: { cookies: 'secret', apiType: 'effect_report' },
    }),
    ctripStaticApi.buildCtripCookieApiFetchRequestBody({
      configId: 'ctrip-58', systemHotelId: 58, requestUrl: 'https://ebooking.ctrip.com/api/core',
      endpointsJson: '[{"request_url":"https://ebooking.ctrip.com/api/secondary","method":"POST","payload":{"token":"must-not-leak"}}]', form: { payloadJson: 'secret' },
    }),
  ];
  ctripBodies.forEach(body => {
    assert.equal(body.config_id, 'ctrip-58');
    assert.equal(body.system_hotel_id, 58);
    assertNoExecutionSecretFields(body);
  });
  assert.equal(Array.isArray(ctripBodies[2].request_urls), true);
  assert.equal(Array.isArray(ctripBodies[4].request_urls), true);
  assert.deepEqual(Array.from(ctripBodies[4].request_urls), [
    'https://ebooking.ctrip.com/api/core',
    'https://ebooking.ctrip.com/api/secondary',
  ]);

  const meituanTasks = meituanStaticApi.buildMeituanBatchFetchTasks({
    configId: 'meituan-58', partnerId: 'partner', poiId: 'poi',
    form: { hotelId: 58, url: 'https://eb.meituan.com/api/rank', dateRanges: ['1'], cookies: 'secret', auth_data: { token: 'secret' } },
  });
  const meituanBodies = [
    ...meituanTasks.map(task => task.body),
    meituanStaticApi.buildMeituanTrafficFetchRequestBody({ configId: 'meituan-58', systemHotelId: 58, form: { url: 'https://eb.meituan.com/api/traffic', partnerId: 'partner', poiId: 'poi', cookies: 'secret', extraParams: 'secret' } }),
    meituanStaticApi.buildMeituanOrderFetchRequestBody({ configId: 'meituan-58', systemHotelId: 58, form: { url: 'https://eb.meituan.com/api/orders', method: 'GET', partnerId: 'partner', poiId: 'poi', cookies: 'secret', payloadJson: 'secret', extraParams: 'secret' } }),
    meituanStaticApi.buildMeituanAdsFetchRequestBody({ configId: 'meituan-58', systemHotelId: 58, form: { url: 'https://eb.meituan.com/api/ads', method: 'GET', partnerId: 'partner', poiId: 'poi', shopId: 'shop', cookies: 'secret', payloadJson: 'secret', extraParams: 'secret' } }),
  ];
  meituanBodies.forEach(body => {
    assert.equal(body.config_id, 'meituan-58');
    assert.equal(body.system_hotel_id, 58);
    assertNoExecutionSecretFields(body);
  });
  assert.doesNotMatch(html, /ensureCtripConfigSecret|ensureMeituanConfigSecret|loadCtripConfigDetail|loadMeituanConfigDetail/);
});

test('legacy data-config test builder resolves vault locators and strips reusable secrets', () => {
  const types = [
    'ctrip-ebooking', 'meituan-ebooking', 'ctrip-traffic', 'ctrip-cookie-api',
    'meituan-traffic', 'ctrip-comments', 'meituan-comments', 'ctrip-ads', 'meituan-ads',
  ];
  types.forEach(type => {
    const body = autoFetchStaticApi.buildDataConfigRequestBody(type, {
      id: type.startsWith('meituan-') ? 'meituan-58' : 'ctrip-58',
      system_hotel_id: 58,
      url: type.startsWith('meituan-') ? 'https://eb.meituan.com/api/test' : 'https://ebooking.ctrip.com/api/test',
      node_id: '24588', partner_id: 'partner', poi_id: 'poi', request_urls: 'https://ebooking.ctrip.com/api/a',
      cookies: 'BROWSER_SECRET', cookie: 'BROWSER_SECRET', auth_data: { token: 'BROWSER_SECRET' },
      spidertoken: 'BROWSER_SECRET', mtgsig: 'BROWSER_SECRET', headers_json: '{"Cookie":"BROWSER_SECRET"}',
      payload_json: '{"token":"BROWSER_SECRET"}', extra_params: '{"token":"BROWSER_SECRET"}',
      endpoints_json: '[{"headers":{"Cookie":"BROWSER_SECRET"}}]',
    });
    assert.equal(body.config_id, type.startsWith('meituan-') ? 'meituan-58' : 'ctrip-58');
    assert.equal(body.system_hotel_id, 58);
    assertNoExecutionSecretFields(body);
  });

  assert.equal(autoFetchStaticApi.buildDataConfigTestRequest({
    type: 'ctrip-ebooking', form: { system_hotel_id: 58 },
  }).status, 'credential_not_ready');
  assert.equal(autoFetchStaticApi.buildDataConfigTestRequest({
    type: 'ctrip-ebooking', form: { config_id: 'ctrip-58', system_hotel_id: 58, node_id: '24588' },
  }).status, 'ready');
});

test('OTA manual credential states expose migration blockers without weakening execution gates', () => {
  assert.equal(typeof ctripStaticApi.buildCtripManualCredentialState, 'function');
  assert.equal(typeof meituanStaticApi.buildMeituanManualCredentialState, 'function');

  assert.equal(JSON.stringify(ctripStaticApi.buildCtripManualCredentialState({
    id: 'ctrip-58',
    config_id: 'ctrip-58',
    credential_status: 'migration_required',
    migration_required: true,
    has_cookies: false,
  })), JSON.stringify({
    key: 'migration_required',
    canFetch: false,
    label: '旧版携程凭据待安全迁移',
    detail: '完成凭据安全迁移或重新保存授权后，才能获取数据。',
    tone: 'warning',
  }));
  assert.equal(ctripStaticApi.buildCtripManualCredentialState({
    id: 'ctrip-58',
    config_id: 'ctrip-58',
    credential_status: 'ready',
    has_cookies: true,
    configuration_verified: true,
  }).canFetch, true);

  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanManualCredentialState({
    id: 'meituan-58',
    config_id: 'meituan-58',
    credential_status: 'migration_required',
    migration_required: true,
    has_cookies: false,
  })), JSON.stringify({
    key: 'migration_required',
    canFetch: false,
    label: '旧版美团凭据待安全迁移',
    detail: '完成凭据安全迁移或重新保存授权后，才能获取数据。',
    tone: 'warning',
  }));
  assert.equal(meituanStaticApi.buildMeituanManualCredentialState({
    id: 'meituan-58',
    config_id: 'meituan-58',
    credential_status: 'ready',
    has_cookies: true,
    configuration_verified: true,
  }).canFetch, true);

  for (const [platform, buildState] of [
    ['ctrip', ctripStaticApi.buildCtripManualCredentialState],
    ['meituan', meituanStaticApi.buildMeituanManualCredentialState],
  ]) {
    const readyConfig = {
      id: `${platform}-58`,
      config_id: `${platform}-58`,
      credential_status: 'ready',
      has_cookies: true,
      configuration_verified: true,
      configuration_verified: true,
    };
    assert.equal(buildState(readyConfig).canFetch, true, `${platform} ready credential must be executable`);
    assert.equal(buildState(null).canFetch, false, `${platform} missing config must fail closed`);
    for (const status of ['pending', 'loading', 'unknown', 'revoked', 'migration_required']) {
      assert.equal(buildState({ ...readyConfig, credential_status: status }).canFetch, false, `${platform} ${status} credential must fail closed`);
    }
    assert.equal(buildState({ ...readyConfig, has_cookies: false }).canFetch, false, `${platform} ready metadata without stored Cookie must fail closed`);
    assert.equal(buildState({ credential_status: 'ready', has_cookies: true }).canFetch, false, `${platform} ready metadata without config id must fail closed`);
  }

  const ctripCredentialStateSource = ctripStatic.slice(
    ctripStatic.indexOf('const buildCtripManualCredentialState = (config = null) => {'),
    ctripStatic.indexOf('\n\n    const normalizeCtripExecutionRequestUrls', ctripStatic.indexOf('const buildCtripManualCredentialState = (config = null) => {')),
  );
  const meituanCredentialStateSource = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanManualCredentialState = (config = null) => {'),
    meituanStatic.indexOf('\n\n    const resolveCanFetchMeituanRankingData', meituanStatic.indexOf('const buildMeituanManualCredentialState = (config = null) => {')),
  );
  const forbiddenSecretRead = /config\?\.(?:cookies|cookie|auth_data|authorization|token|spidertoken|mtgsig)\b/;
  assert.doesNotMatch(ctripCredentialStateSource, forbiddenSecretRead);
  assert.doesNotMatch(meituanCredentialStateSource, forbiddenSecretRead);

  assert.match(html, /selectedCtripManualCredentialState\.label/);
  assert.match(html, /selectedCtripManualCredentialState\.detail/);
  assert.match(html, /selectedMeituanManualCredentialState\.label/);
  assert.match(html, /selectedMeituanManualCredentialState\.detail/);
  assert.match(html, /ctrip-static\.js\?v=[^"']*-h[0-9a-f]{10}/);
  assert.match(html, /meituan-static\.js\?v=[^"']*-h[0-9a-f]{10}/);
  assert.doesNotMatch(html, /携程已配置｜上次更新/);
  assert.doesNotMatch(html, /美团已配置｜上次更新/);
});

test('ready stored OTA credentials can run manual fetch before Profile verification', () => {
  for (const [platform, api] of [
    ['ctrip', ctripStaticApi],
    ['meituan', meituanStaticApi],
  ]) {
    const config = {
      id: `${platform}-61`,
      config_id: `${platform}-61`,
      credential_status: 'ready',
      has_cookies: true,
      configuration_saved: true,
      configuration_verified: false,
    };
    const isReady = platform === 'ctrip'
      ? api.isCtripExecutionConfigReady(config)
      : api.isMeituanExecutionConfigReady(config);
    const state = platform === 'ctrip'
      ? api.buildCtripManualCredentialState(config)
      : api.buildMeituanManualCredentialState(config);

    assert.equal(isReady, true, `${platform} ready stored credential must be allowed to verify by fetching`);
    assert.equal(state.canFetch, true, `${platform} pending Profile proof must not block manual credential fetch`);
    assert.match(state.detail, /可以直接获取数据验证/);
  }

  assert.doesNotMatch(html, /请完成该门店的授权登录验证后再获取数据。/);
  assert.doesNotMatch(html, /:disabled="config\.configuration_verified !== true"/);
});

test('OTA diagnosis fetch planning is metadata-only and never reads browser-held credentials', async () => {
  const ctripConfig = {
    id: 'ctrip-58', config_id: 'ctrip-58', credential_status: 'ready', has_cookies: true,
    system_hotel_id: 58, node_id: '24588', cookies: 'CTRIP_SECRET', auth_data: { token: 'CTRIP_SECRET' },
  };
  const meituanConfig = {
    id: 'meituan-58', config_id: 'meituan-58', credential_status: 'ready', has_cookies: true,
    system_hotel_id: 58, partner_id: 'partner', poi_id: 'poi', cookies: 'MEITUAN_SECRET', mtgsig: 'MEITUAN_SECRET',
  };
  const context = otaDiagnosisStaticApi.buildOtaDiagnosisFetchContext({
    selectedHotel: { system_hotel_id: 58 },
    form: { start_date: '2026-07-09', end_date: '2026-07-09' },
    ctripConfig,
    meituanConfig,
    ctripTrafficConfig: { enabled: true, system_hotel_id: 58, url: 'https://ebooking.ctrip.com/api/traffic', cookies: 'LEGACY_SECRET', spiderkey: 'LEGACY_SECRET' },
    ctripCookieApiConfig: { enabled: true, system_hotel_id: 58, request_urls: 'https://ebooking.ctrip.com/api/core', headers_json: '{"Cookie":"LEGACY_SECRET"}' },
    meituanTrafficConfig: { enabled: true, system_hotel_id: 58, url: 'https://eb.meituan.com/api/traffic', partner_id: 'partner', poi_id: 'poi', cookies: 'LEGACY_SECRET' },
  });
  const tasks = otaDiagnosisStaticApi.buildOtaDiagnosisFetchTasks({ context });
  assert.ok(tasks.length >= 7);
  tasks.forEach(task => {
    assert.equal(task.body.system_hotel_id, '58');
    assert.ok(['ctrip-58', 'meituan-58'].includes(task.body.config_id));
    assertNoExecutionSecretFields(task.body);
    assert.doesNotMatch(JSON.stringify(task.body), /SECRET/);
  });

  let legacyReadCount = 0;
  const flowResult = await otaDiagnosisStaticApi.runOtaDiagnosisHotelFetchFlow({
    selectedHotel: { system_hotel_id: 58 },
    form: { start_date: '2026-07-09', end_date: '2026-07-09' },
    findCtripConfigByHotelId: () => ctripConfig,
    findMeituanConfigByHotelId: () => meituanConfig,
    readSavedOtaDataConfig: async () => { legacyReadCount += 1; return { cookies: 'LEGACY_SECRET' }; },
    readSavedGenericCookieForDiagnosis: async () => { legacyReadCount += 1; return { cookies: 'LEGACY_SECRET' }; },
    requestTask: async task => {
      assertNoExecutionSecretFields(task.body);
      return { code: 200, data: { saved_count: 1 } };
    },
  });
  assert.equal(legacyReadCount, 0);
  assert.equal(flowResult.failed, 0);
});

test('Ctrip config defaults to all capabilities and requires both room counts', () => {
  const defaultForm = ctripStaticApi.createCtripConfigForm();
  assert.equal(defaultForm.capture_sections, 'all');
  assert.equal(defaultForm.hotel_room_count, '');
  assert.equal(defaultForm.competitor_room_count, '');

  const invalidHotelRooms = ctripStaticApi.validateCtripConfigSaveInput({
    cookies: 'cookie',
    hotel_room_count: '0',
    competitor_room_count: '120',
  });
  assert.equal(invalidHotelRooms.status, 'invalid_hotel_room_count');

  const invalidCompetitorRooms = ctripStaticApi.validateCtripConfigSaveInput({
    cookies: 'cookie',
    hotel_room_count: '88',
    competitor_room_count: '1.5',
  });
  assert.equal(invalidCompetitorRooms.status, 'invalid_competitor_room_count');

  const payload = ctripStaticApi.buildCtripConfigSavePayload({
    hotel_id: 58,
    cookies: 'cookie',
    capture_sections: 'default',
    hotel_room_count: '88',
    competitor_room_count: '360',
  });
  assert.equal(payload.capture_sections, 'all');
  assert.equal(payload.hotel_room_count, 88);
  assert.equal(payload.competitor_room_count, 360);
});

test('Ctrip config UI requires and echoes room-count fields', () => {
  const configForm = sliceFrom('data-testid="ctrip-config-form"', '<!-- 已保存的配置列表 -->');
  const configList = sliceFrom('<!-- 已保存的配置列表 -->', '<!-- 携程数据抓取设置 -->');
  const healthEditorForm = sliceFrom(
    '<form v-else="" @submit.prevent="saveCtripCookieFromHealth"',
    '<!-- 智能知识中枢：单元编辑 -->'
  );
  const editCtripConfig = constSlice(
    'const editCtripConfig = async (config) => {',
    '\n\n            const toggleSelectAllCtripConfig'
  );
  const healthEditorSource = constSlice(
    'const createCtripCookieEditorForm = () => ({',
    '\n            const showCtripCookieEditorModal'
  );

  assert.match(configForm, /v-model="ctripConfigForm\.hotel_room_count"[^>]*required/);
  assert.match(configForm, /v-model="ctripConfigForm\.competitor_room_count"[^>]*required/);
  assert.doesNotMatch(configForm, /Profile采集范围|ctripConfigForm\.capture_sections/);
  assert.match(configList, /hotel_room_count/);
  assert.match(configList, /competitor_room_count/);
  assert.match(editCtripConfig, /hotel_room_count: config\.hotel_room_count \|\| ''/);
  assert.match(editCtripConfig, /competitor_room_count: config\.competitor_room_count \|\| ''/);
  assert.match(editCtripConfig, /capture_sections: 'all'/);

  assert.match(healthEditorForm, /v-model="ctripCookieEditorForm\.hotel_room_count"[^>]*required/);
  assert.match(healthEditorForm, /v-model="ctripCookieEditorForm\.competitor_room_count"[^>]*required/);
  assert.doesNotMatch(healthEditorForm, /ctripCookieEditorForm\.capture_sections|采集范围/);
  assert.match(healthEditorSource, /hotel_room_count:/);
  assert.match(healthEditorSource, /competitor_room_count:/);
  assert.match(healthEditorSource, /capture_sections: 'all'/);
});

test('Ctrip manual execution uses platform authorization and legacy Cookie storage stays disabled', () => {
  const fetchCtripData = sliceFrom('const fetchCtripData = async (options = {}) => {', 'const fetchMeituanData = async (options = {}) => {');
  const fetchCtripTrafficData = sliceFrom('const fetchCtripTrafficData = async () => {', 'const fetchCtripComments = async () => {');
  const ctripManualFetchConfigGuard = sliceFrom('const ctripManualFetchConfigProofPending = () => {', '\n\n            const saveCtripConfig');
  const canFetchCtripManualDataSource = sliceFrom('const canFetchCtripManualData = () => {', '\n\n            const resolveCtripManualFetchConfig');
  const loadCtripConfigList = sliceFrom('const loadCtripConfigList = async (options = {}) => {', '\n\n            const ctripManualFetchConfigProofPending');
  const returnToCtripRankingAfterConfigSave = constSlice(
    'const returnToCtripRankingAfterConfigSave = async (hotelId) => {',
    '\n\n            const saveCtripConfig'
  );
  const saveCtripConfig = constSlice(
    'const saveCtripConfig = async () => {',
    '\n\n            const useCtripConfig'
  );
  const batchDeleteCtripConfigs = constSlice(
    'const batchDeleteCtripConfigs = async () => {',
    '\n\n            const generateCtripBookmarklet'
  );
  const generateCtripBookmarklet = constSlice(
    'const generateCtripBookmarklet = async () => {',
    '\n\n            // 美团配置管理方法'
  );
  const deleteCookiesConfig = constSlice(
    'const deleteCookiesConfig = async (name, hotelId) => {',
    '\n\n            const batchDeleteCookiesConfig'
  );
  const batchDeleteCookiesConfig = constSlice(
    'const batchDeleteCookiesConfig = async () => {',
    '\n\n            const useCookies'
  );
  const loadCookiesList = constSlice(
    'const loadCookiesList = async () => {',
    '\n\n            const loadCookieDetail'
  );
  const loadCookieDetail = constSlice(
    'const loadCookieDetail = async (item) => {',
    '\n\n            const cookieStatusClass'
  );
  const saveCookiesConfig = constSlice(
    'const saveCookiesConfig = async () => {',
    '\n\n            const deleteCookiesConfig'
  );
  const useCookies = constSlice(
    'const useCookies = async (item) => {',
    '\n\n            // AI智能分析相关函数'
  );
  const saveQuickCookies = constSlice(
    'const saveQuickCookies = async () => {',
    '\n\n            // 查看线上数据详情'
  );
  const ctripFetchFlow = ctripStatic.slice(
    ctripStatic.indexOf('const runCtripFetchDataFlow = async ({'),
    ctripStatic.indexOf('const buildLatestCtripSnapshotModel')
  );
  const ctripConfigSaveFlow = ctripStatic.slice(
    ctripStatic.indexOf('const runCtripConfigSaveFlow = async ({'),
    ctripStatic.indexOf('const runCtripManualTabSwitch')
  );
  const ctripManualTabSwitch = ctripStatic.slice(
    ctripStatic.indexOf('const runCtripManualTabSwitch = async ({'),
    ctripStatic.indexOf('const createCtripProfileFieldForm')
  );

  assert.doesNotMatch(fetchCtripData, /请输入节点ID/);
  assert.match(html, /requireCtripStatic\('runCtripFetchDataFlow'\)/);
  assert.match(html, /requireCtripStatic\('isCtripRankingFormAlignedWithConfig'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripBookmarkletSuccessState'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripBookmarkletFailureState'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripBatchDeleteConfigResultState'\)/);
  assert.match(ctripStatic, /const buildCtripBookmarkletSuccessState = \(response = \{\}\) => \(\{/);
  assert.match(ctripStatic, /const buildCtripBookmarkletFailureState = \(\{/);
  assert.match(ctripStatic, /const buildCtripBatchDeleteConfigResultState = \(results = \[\]\) => \{/);
  assert.equal(JSON.stringify(ctripStaticApi.buildCtripBatchDeleteConfigResultState([
    { id: '1', success: true },
    { id: '2', success: false },
  ])), JSON.stringify({
    failedIds: ['2'],
    deletedCount: 1,
    shouldRefresh: true,
    toastMessage: '已删除 1 个，1 个失败',
    toastLevel: 'warning',
  }));
  assert.equal(JSON.stringify(ctripStaticApi.buildCtripBatchDeleteConfigResultState([
    { id: '1', success: true },
  ])), JSON.stringify({
    failedIds: [],
    deletedCount: 1,
    shouldRefresh: true,
    toastMessage: '已删除 1 个配置',
    toastLevel: 'success',
  }));
  assert.match(batchDeleteCtripConfigs, /const deleteResultState = buildCtripBatchDeleteConfigResultState\(results\);/);
  assert.match(batchDeleteCtripConfigs, /selectedCtripConfigIds\.value = deleteResultState\.failedIds;/);
  assert.match(batchDeleteCtripConfigs, /if \(deleteResultState\.shouldRefresh\) \{/);
  assert.match(batchDeleteCtripConfigs, /showToast\(deleteResultState\.toastMessage, deleteResultState\.toastLevel\);/);
  assert.doesNotMatch(batchDeleteCtripConfigs, /const failedIds = results\.filter/);
  assert.doesNotMatch(batchDeleteCtripConfigs, /const deletedCount = results\.length - failedIds\.length/);
  assert.match(html, /凭据统一由平台配置保管/);
  assert.match(html, /旧 Cookie 列表、明文详情和快速保存入口已停用/);
  assert.match(html, /@click="openPlatformSourcesTab"/);
  assert.match(loadCookiesList, /cookiesList\.value = \[\];/);
  assert.match(loadCookiesList, /selectedCookieKeys\.value = \[\];/);
  assert.doesNotMatch(loadCookiesList, /request\(/);
  assert.match(loadCookieDetail, /旧 Cookie 明文详情已停用，请在平台采集源中更换凭据/);
  assert.doesNotMatch(loadCookieDetail, /request\(/);
  assert.match(saveCookiesConfig, /旧 Cookie 保存已停用，请在平台采集源中更换凭据/);
  assert.match(saveCookiesConfig, /openPlatformSourcesTab\(\);/);
  assert.doesNotMatch(saveCookiesConfig, /request\(/);
  assert.match(deleteCookiesConfig, /旧 Cookie 删除入口已停用，请在平台配置中吊销对应凭据/);
  assert.match(deleteCookiesConfig, /openPlatformSourcesTab\(\);/);
  assert.doesNotMatch(deleteCookiesConfig, /request\(/);
  assert.match(batchDeleteCookiesConfig, /selectedCookieKeys\.value = \[\];/);
  assert.match(batchDeleteCookiesConfig, /旧 Cookie 批量删除入口已停用，请在平台配置中逐项吊销凭据/);
  assert.match(batchDeleteCookiesConfig, /openPlatformSourcesTab\(\);/);
  assert.doesNotMatch(batchDeleteCookiesConfig, /request\(/);
  assert.match(useCookies, /浏览器不再读取已保存的完整 Cookie，请选择平台配置凭据/);
  assert.match(useCookies, /openPlatformSourcesTab\(\);/);
  assert.doesNotMatch(useCookies, /request\(/);
  assert.match(saveQuickCookies, /旧 Cookie 快速保存已停用，请在平台采集源中更换凭据/);
  assert.match(saveQuickCookies, /openPlatformSourcesTab\(\);/);
  assert.doesNotMatch(saveQuickCookies, /request\(/);
  assert.doesNotMatch(html, /request\(['"]\/online-data\/(?:save-cookies|cookies-list|cookies-detail|delete-cookies|batch-delete-cookies)/);
  assert.match(generateCtripBookmarklet, /const successState = buildCtripBookmarkletSuccessState\(res\);/);
  assert.match(generateCtripBookmarklet, /ctripBookmarklet\.value = successState\.bookmarklet;/);
  assert.match(generateCtripBookmarklet, /showToast\(successState\.toastMessage, successState\.toastLevel\);/);
  assert.match(generateCtripBookmarklet, /const failureState = buildCtripBookmarkletFailureState\(\{ error: e \}\);/);
  assert.match(generateCtripBookmarklet, /alert\(failureState\.alertMessage\);/);
  assert.match(generateCtripBookmarklet, /showToast\(failureState\.toastMessage, failureState\.toastLevel\);/);
  assert.doesNotMatch(generateCtripBookmarklet, /ctripBookmarklet\.value = res\.data\.bookmarklet;/);
  assert.doesNotMatch(generateCtripBookmarklet, /showToast\(res\.data\?\.message \|\| '旧版携程 Cookie 书签已禁用', 'warning'\);/);
  assert.doesNotMatch(generateCtripBookmarklet, /showToast\('生成失败: ' \+ e\.message, 'error'\);/);
  assert.match(fetchCtripData, /runCtripFetchDataFlow\(\{/);
  assert.match(fetchCtripData, /const preparingConfig = ctripManualFetchConfigProofPending\(\);/);
  assert.doesNotMatch(fetchCtripData, /ensureCtripConfigSecret|cookies|auth_data/);
  assert.match(fetchCtripData, /finally \{\s*if \(preparingConfig\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
  assert.match(fetchCtripData, /body: JSON\.stringify\(requestBody\)/);
  assert.match(ctripStatic, /const isCtripRankingFormAlignedWithConfig = \(form = \{\}, config = \{\}, options = \{\}\) =>/);
  assert.match(ctripStatic, /if \(selectedConfig && !isCtripRankingFormAlignedWithConfig\(form, selectedConfig, \{ selectedHotelId: selectedCtripHotelId \}\)\) \{/);
  assert.match(ctripStatic, /const activeConfig = selectedCtripHotelId \? getActiveCtripConfig\(\) : null;/);
  assert.match(ctripStatic, /const configId = temporaryCookieQuery \? '' : resolveCtripExecutionConfigId\(activeConfig\);/);
  assert.match(ctripStatic, /const requestForm = form;/);
  assert.match(ctripStatic, /background = false/);
  assert.match(ctripStatic, /const requestBody = requestContext\.temporaryCookieQuery\s*\? \{ \.\.\.requestContext\.requestBody \}\s*:\s*\{ \.\.\.requestContext\.requestBody, async: background === true, background: background === true \};/);
  assert.match(ctripStatic, /const requestContext = buildCtripFetchRequestContext\(\{/);
  assert.match(ctripStatic, /const nodeId = String\(form\.nodeId \|\| ''\)\.trim\(\)/);
  assert.match(html, /requireCtripStatic\('runCtripTrafficFetchFlow'\)/);
  assert.match(fetchCtripTrafficData, /runCtripTrafficFetchFlow\(\{/);
  assert.match(fetchCtripTrafficData, /const preparingConfig = ctripManualFetchConfigProofPending\(\);/);
  assert.doesNotMatch(fetchCtripTrafficData, /ensureCtripConfigSecret|cookies|auth_data/);
  assert.match(ctripStatic, /const requestBody = buildCtripTrafficFetchRequestBody\(\{/);
  assert.match(html, /:disabled="fetchingData \|\| !canFetchCtripManualData\(\)"/);
  assert.match(ctripManualFetchConfigGuard, /return !!ctripConfigListLoadingPromise\s*\|\| \(!ctripConfigListLoaded\.value && !ctripConfigListLoadFailed\.value\);/);
  assert.match(ctripManualFetchConfigGuard, /const ctripManualFetchConfigCandidate = \(\) => \{/);
  assert.match(ctripManualFetchConfigGuard, /const canFetchCtripManualData = \(\) => \{/);
  assert.doesNotMatch(ctripManualFetchConfigGuard, /activeCookies|config\.cookies|config\.cookie/);
  assert.match(ctripManualFetchConfigGuard, /return buildCtripManualCredentialState\(config\)\.canFetch;/);
  assert.match(canFetchCtripManualDataSource, /if \(selectedCtripHotelId\.value\) return selectedCtripManualCredentialState\.value\.canFetch;/);
  assert.match(canFetchCtripManualDataSource, /return normalizeCtripTemporaryCookie\(ctripForm\.value\) !== '';/);
  assert.doesNotMatch(canFetchCtripManualDataSource, /ctripManualFetchConfigProofPending|ctripConfigListLoadingPromise|ctripConfigListLoaded|ctripConfigListLoadFailed/);
  assert.match(ctripManualFetchConfigGuard, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(ctripManualFetchConfigGuard, /return ctripManualFetchConfigCandidate\(\);/);
  assert.match(loadCtripConfigList, /const force = options\.force === true;/);
  assert.match(loadCtripConfigList, /const requestSession = captureAuthSession\(\);/);
  assert.match(loadCtripConfigList, /!force\s*&& ctripConfigListLoaded\.value/);
  assert.match(loadCtripConfigList, /if \(!force\) \{\s*return ctripConfigListLoadingPromise;\s*\}/);
  assert.match(loadCtripConfigList, /await ctripConfigListLoadingPromise\.catch\(\(\) => \[\]\);/);
  assert.match(loadCtripConfigList, /isAuthSessionCurrent\(requestSession\)\s*\? applyCtripHotelConfig\(false, \{/);
  assert.match(ctripConfigSaveFlow, /afterSave = async \(\) => \{ reloadConfigs\(\); \}/);
  assert.match(ctripConfigSaveFlow, /await afterSave\(\{ response: res, requestBody \}\);/);
  assert.match(ctripManualTabSwitch, /!\['ctrip-flow-overview', 'ctrip-fetch-settings', 'ctrip-ads', 'ctrip-config'\]\.includes\(tab\)/);
  assert.match(ctripManualTabSwitch, /await loadConfigList\(\);/);
  assert.match(saveCtripConfig, /afterSave: async \(\{ response, requestBody \}\) => \{/);
  assert.match(saveCtripConfig, /await returnToCtripRankingAfterConfigSave\(savedHotelId\);/);
  assert.match(returnToCtripRankingAfterConfigSave, /currentPage\.value = 'ctrip-ebooking';/);
  assert.match(returnToCtripRankingAfterConfigSave, /onlineDataTab\.value = 'ctrip-ranking';/);
  assert.match(returnToCtripRankingAfterConfigSave, /loadHotels\(\{ force: true \}\)/);
  assert.match(returnToCtripRankingAfterConfigSave, /loadOnlineDataHotelList\(\{ force: true \}\)/);
  assert.match(returnToCtripRankingAfterConfigSave, /loadCtripConfigList\(\{ force: true, applySelectedConfig: false \}\)/);
  assert.match(returnToCtripRankingAfterConfigSave, /scheduleCtripHotelConfigApply\(null, \{[\s\S]*showMessage: false,[\s\S]*skipIfAligned: false/);
  assert.doesNotMatch(ctripFetchFlow, /notify\('请选择目标酒店', 'error'\)/);
  assert.match(ctripFetchFlow, /const selectedConfig = selectedCtripHotelId \? activeConfig : null;/);
  assert.match(ctripFetchFlow, /if \(selectedConfig && !isCtripRankingFormAlignedWithConfig/);
  assert.doesNotMatch(fetchCtripData, /scheduleOnlineHistoryRefresh\(1400\)/);
  assert.match(html, /已选门店：凭据库授权并可入库；未选门店：临时 Cookie，仅本页展示/);
});

test('Ctrip config list actions route to visible destinations', () => {
  const configForm = sliceFrom('data-testid="ctrip-config-form"', '<!-- 已保存的配置列表 -->');
  const useCtripConfig = constSlice(
    'const useCtripConfig = async (config) => {',
    '\n\n            // 在榜单数据获取页面应用选中的配置'
  );
  const editCtripConfig = constSlice(
    'const editCtripConfig = async (config) => {',
    '\n\n            const toggleSelectAllCtripConfig'
  );

  assert.match(configForm, /ctripConfigForm\.id \? '编辑携程配置' : '新增携程配置'/);
  assert.match(configForm, /ctripConfigForm\.id \? '保存修改' : '保存配置'/);
  assert.match(useCtripConfig, /currentPage\.value = 'ctrip-ebooking';/);
  assert.match(useCtripConfig, /await nextTick\(\);/);
  assert.match(useCtripConfig, /openCtripManualTab\('ctrip-ranking'\);/);
  assert.doesNotMatch(useCtripConfig, /onlineDataTab\.value = 'ctrip-ranking';/);
  assert.match(editCtripConfig, /currentPage\.value = 'ctrip-ebooking';/);
  assert.match(editCtripConfig, /onlineDataTab\.value = 'ctrip-config';/);
  assert.match(editCtripConfig, /document\.querySelector\('\[data-testid="ctrip-config-form"\]'\)/);
  assert.match(editCtripConfig, /scrollIntoView/);
  assert.match(editCtripConfig, /querySelector\?\.\('select, input, textarea'\)\?\.focus/);
});

test('OTA account blocker copy uses visible config entry names', () => {
  assert.doesNotMatch(html, /高级设置/);
  assert.match(html, /平台账号信息不完整，请在本行操作区打开对应平台配置补齐后，再由账号使用者本机重新授权。/);
  assert.match(html, /美团账号还缺平台门店确认，请点击本行右侧“美团配置”补齐平台门店标识，再由账号使用者本机重新授权。/);
  assert.doesNotMatch(html, /平台账号信息不完整，请在本行操作区打开对应平台配置补齐后，再重新登录。/);
  assert.doesNotMatch(html, /美团账号还缺平台门店确认，请点击本行右侧“美团配置”补齐平台门店标识，再重新登录。/);
  assert.match(html, /当前账号来自旧配置，不能在这里解绑；请到本行右侧对应平台配置处理，避免误删历史采集身份。/);
});

test('Meituan daily fetch keeps the advanced Profile panel out of the ranking page', () => {
  const meituanManualHeader = sliceFrom(
    '<button @click="openMeituanManualTab(\'meituan-ranking\')"',
    '<div v-if="onlineDataTab === \'meituan-ranking\'">'
  );
  const rankingPanel = sliceFrom(
    '<div v-if="onlineDataTab === \'meituan-ranking\'">',
    '<!-- \u83b7\u53d6\u7ed3\u679c\u663e\u793a -->'
  );

  assert.doesNotMatch(meituanManualHeader, /\u7f8e\u56e2 Profile \u91c7\u96c6/);
  assert.doesNotMatch(meituanManualHeader, /meituanBrowserCaptureForm/);
  assert.match(meituanManualHeader, /@click="openHotelManagementForOta"/);
  assert.match(rankingPanel, /selectedMeituanManualCredentialState/);
  assert.match(rankingPanel, /goConfigureMeituanForSelectedHotel/);
});

test('Meituan ranking uses selected hotel config without exposing temporary fields', () => {
  const rankingPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const fetchMeituanData = sliceFrom('const fetchMeituanData = async (options = {}) => {', 'const useCtripTrafficDisplayRows');
  const meituanFetchFlow = meituanStatic.slice(
    meituanStatic.indexOf('const runMeituanBatchFetchFlow = async ({'),
    meituanStatic.indexOf('const useMeituanDisplayModel')
  );
  const meituanBatchValidation = meituanStatic.slice(
    meituanStatic.indexOf('const validateMeituanBatchFetchInput = ({'),
    meituanStatic.indexOf('const buildMeituanBatchFetchTasks = ({')
  );
  const meituanTaskBuilder = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanBatchFetchTasks = ({'),
    meituanStatic.indexOf('const buildMeituanBatchFetchResultEntry')
  );

  assert.match(rankingPanel, /v-model="meituanForm\.hotelId"/);
  assert.match(rankingPanel, /请选择目标酒店/);
  assert.match(rankingPanel, /meituan-hotel-picker/);
  assert.match(rankingPanel, /id="meituan-ranking-hotel"/);
  assert.match(rankingPanel, /仅显示已绑定美团账号的酒店/);
  assert.doesNotMatch(rankingPanel, /选择酒店和时间后更新竞争圈/);
  assert.match(rankingPanel, /每次获取一个周期，默认昨日/);
  assert.match(rankingPanel, /selectMeituanRankingDateRange\('custom'\)/);
  assert.match(rankingPanel, /px-4 py-2\.5 text-sm/);
  assert.doesNotMatch(rankingPanel, /type="checkbox"/);
  assert.match(rankingPanel, /:aria-pressed="meituanForm\.dateRanges\.includes\('0'\)"/);
  assert.match(html, /const selectMeituanRankingDateRange = \(dateRange\) => \{/);
  assert.match(html, /meituanForm\.value\.dateRanges = \[normalized\];/);
  assert.match(mainSetupReturnSource(), /selectMeituanRankingDateRange/);
  assert.doesNotMatch(rankingPanel, /选择酒店和时间后更新竞争圈/);
  assert.match(rankingPanel, /border-blue-600 bg-blue-600 text-white/);
  assert.match(rankingPanel, /border-gray-200 bg-gray-100 text-gray-500 cursor-not-allowed/);
  assert.match(rankingPanel, /:max="meituanRankMaxDate"/);
  assert.doesNotMatch(rankingPanel, /远期预测\/未来入住不属于此接口/);
  assert.doesNotMatch(rankingPanel, /今日实时\*/);
  assert.doesNotMatch(rankingPanel, /\*每日9点更新前日数据/);
  assert.match(rankingPanel, /v-if="showMeituanPreviousDayUpdateNotice"/);
  assert.equal(meituanStaticApi.shouldShowMeituanPreviousDayUpdateNotice(['1'], 0), true);
  assert.equal(meituanStaticApi.shouldShowMeituanPreviousDayUpdateNotice(['1'], 8), true);
  assert.equal(meituanStaticApi.shouldShowMeituanPreviousDayUpdateNotice(['1'], 9), false);
  assert.equal(meituanStaticApi.shouldShowMeituanPreviousDayUpdateNotice(['7'], 3), false);
  assert.match(html, /const meituanRankMaxDate = computed\(\(\) => formatDate\(new Date\(\)\)\);/);
  assert.match(html, /meituanRankMaxDate,/);
  assert.match(html, /const showMeituanPreviousDayUpdateNotice = computed\(\(\) => \{/);
  assert.match(mainSetupReturnSource(), /showMeituanPreviousDayUpdateNotice/);
  assert.doesNotMatch(html, /仅展示美团榜单已返回字段；未返回字段保留缺失状态。/);
  assert.doesNotMatch(meituanStatic, /仅展示美团榜单已返回字段；未返回字段保留缺失状态。/);
  assert.doesNotMatch(businessDisplayConcern, /仅展示美团榜单已返回字段；未返回字段保留缺失状态。/);
  assert.match(html, /v-if="meituanRankSourceNotice"/);
  assert.match(html, /const resolveMeituanRankSourceNotice = requireMeituanStatic\('resolveMeituanRankSourceNotice'\);/);
  assert.match(html, /const meituanRankSourceNotice = computed\(\(\) => resolveMeituanRankSourceNotice\(meituanBusinessSummary\.value\)\);/);
  assert.match(meituanStatic, /const resolveMeituanRankSourceNotice = \(summary = \{\}\) => summary\?\.source_notice \|\| '';/);
  assert.match(meituanStatic, /resolveMeituanRankSourceNotice,/);
  assert.match(meituanStatic, /sourceNotice = '',/);
  assert.doesNotMatch(rankingPanel, /v-model="meituanForm\.partnerId"/);
  assert.doesNotMatch(rankingPanel, /v-model="meituanForm\.poiId"/);
  assert.doesNotMatch(rankingPanel, /v-model="meituanForm\.cookies"/);
  assert.doesNotMatch(rankingPanel, /临时获取可不先保存配置/);
  assert.doesNotMatch(rankingPanel, /需一次性门店标识/);
  assert.doesNotMatch(meituanBatchValidation, /partnerId|poiId|一次性门店标识/);
  assert.doesNotMatch(meituanTaskBuilder, /partner_id:|poi_id:|url: form\.url/);
  assert.doesNotMatch(meituanFetchFlow, /notify\('请选择目标酒店', 'error'\)/);
  assert.doesNotMatch(meituanFetchFlow, /return \{ status: 'missing_hotel' \}/);
  assert.doesNotMatch(meituanFetchFlow, /return \{ status: 'missing_config' \}/);
  assert.doesNotMatch(meituanFetchFlow, /setBusinessSummary\(null\)/);
  assert.match(meituanFetchFlow, /status:\s*'exception'/);
  assert.match(meituanFetchFlow, /const failedCount = results\.filter\(item => item\?\.error\)\.length;/);
  assert.match(meituanFetchFlow, /fetchTasks\.length > 0 && failedCount === fetchTasks\.length/);
  assert.match(meituanFetchFlow, /setBusinessSummary\(getEmptyBusinessSummary\(\)\)/);
  assert.match(meituanFetchFlow, /status:\s*loginFailed \? 'login_required' : 'failed'/);
  assert.match(meituanFetchFlow, /credentialStatus === 'login_required'/);
  assert.match(meituanFetchFlow, /Cookie\/API/);
  assert.match(meituanFetchFlow, /const selectedMeituanConfig = form\.hotelId\s*\?\s*getSelectedConfig\(\)\s*:\s*null;/);
  assert.match(meituanFetchFlow, /const configId = isMeituanExecutionConfigReady\(selectedMeituanConfig\)/);
  assert.doesNotMatch(meituanFetchFlow, /ensureMeituanConfigSecret|form\.cookies|auth_data/);
  assert.match(fetchMeituanData, /requestFetch:\s*body\s*=>\s*request\('\/online-data\/fetch-meituan',[\s\S]*withBusinessContext:\s*false/);
  assert.match(fetchMeituanData, /refreshOnlineHistory:\s*\(\)\s*=>\s*schedulePostFetchRefresh\('online-history',[\s\S]*,\s*1400\)/);
});

test('Meituan ranking reset state is owned by the static helper', () => {
  const resetMeituanRankingFetchState = sliceFrom(
    'const resetMeituanRankingFetchState = () => {',
    '\n\n            watch(() => meituanForm.value.hotelId'
  );
  const meituanTopSummaryRows = sliceFrom(
    'const meituanTopSummaryRows = computed(() => resolveMeituanTopSummaryRows({',
    '\n            const meituanFetchSuccess'
  );
  const sortMeituanTable = sliceFrom(
    'const sortMeituanTable = (field) => {',
    '\n            const meituanTablePage'
  );
  const changeMeituanTablePage = sliceFrom(
    'const changeMeituanTablePage = (page) => {',
    '\n            watch([ctripHotelsList'
  );
  const meituanRankDisplayComputeds = sliceFrom(
    'const meituanDynamicSelfRankRow = computed(() =>',
    '\n            // 排序函数'
  );
  assert.match(meituanStatic, /const buildMeituanTopSummaryFallbackRows = \(rankedRows = \[\], limit = 3\) => \{/);
  assert.match(meituanStatic, /const resolveMeituanTopSummaryRows = \(\{/);
  assert.match(meituanStatic, /const findMeituanDynamicSelfRankRow = \(rankedRows = \[\]\) => \{/);
  assert.match(meituanStatic, /const buildMeituanDisplayedHotelsList = \(rankedRows = \[\], sortField = 'roomNights', sortOrder = 'desc'\) => \{/);
  assert.match(meituanStatic, /const resolveMeituanSortState = \(currentField = 'roomNights', currentOrder = 'desc', nextField = ''\) => \{/);
  assert.match(meituanStatic, /const resolveMeituanTablePage = \(page = 1, totalPages = 1\) => Math\.min\(/);
  assert.match(meituanStatic, /const resolveMeituanRankSourceNotice = \(summary = \{\}\) => summary\?\.source_notice \|\| '';/);
  assert.match(meituanStatic, /const buildMeituanRankInsightCards = \(summary = \{\}\) => \{/);
  assert.match(meituanStatic, /const buildMeituanVisibleRankInsightCards = \(cards = \[\]\) => \(/);
  assert.match(meituanStatic, /const buildMeituanRankHealthRows = \(summary = \{\}\) => \{/);
  assert.match(meituanStatic, /buildMeituanTopSummaryFallbackRows,/);
  assert.match(meituanStatic, /resolveMeituanTopSummaryRows,/);
  assert.match(meituanStatic, /findMeituanDynamicSelfRankRow,/);
  assert.match(meituanStatic, /buildMeituanDisplayedHotelsList,/);
  assert.match(meituanStatic, /resolveMeituanSortState,/);
  assert.match(meituanStatic, /resolveMeituanTablePage,/);
  assert.match(meituanStatic, /resolveMeituanRankSourceNotice,/);
  assert.match(meituanStatic, /buildMeituanRankInsightCards,/);
  assert.match(meituanStatic, /buildMeituanVisibleRankInsightCards,/);
  assert.match(meituanStatic, /buildMeituanRankHealthRows,/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanTopSummaryRows'\)/);
  assert.match(html, /requireMeituanStatic\('findMeituanDynamicSelfRankRow'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanDisplayedHotelsList'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanSortState'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanTablePage'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanRankSourceNotice'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanRankInsightCards'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanVisibleRankInsightCards'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanRankHealthRows'\)/);
  assert.match(html, /const meituanRankInsightCards = computed\(\(\) => applyMeituanFetchHealthToCards\(\s*buildMeituanRankInsightCards\(meituanBusinessSummary\.value\),/);
  assert.match(html, /const meituanVisibleRankInsightCards = computed\(\(\) => buildMeituanVisibleRankInsightCards\(meituanRankInsightCards\.value\)\);/);
  assert.match(html, /const meituanRankHealthRows = computed\(\(\) => applyMeituanFetchHealthToRows\(\s*buildMeituanRankHealthRows\(meituanBusinessSummary\.value\),/);
  assert.match(meituanTopSummaryRows, /resolveMeituanTopSummaryRows\(\{/);
  assert.match(meituanTopSummaryRows, /businessSummary: meituanBusinessSummary\.value/);
  assert.match(meituanTopSummaryRows, /rankedRows: meituanRankedHotelsList\.value/);
  assert.doesNotMatch(meituanTopSummaryRows, /rankedRows\.slice\(0, 3\)\.map/);
  assert.match(meituanRankDisplayComputeds, /computed\(\(\) => findMeituanDynamicSelfRankRow\(meituanRankedHotelsList\.value\)\)/);
  assert.match(meituanRankDisplayComputeds, /computed\(\(\) => buildMeituanDisplayedHotelsList\(meituanRankedHotelsList\.value, meituanSortField\.value, meituanSortOrder\.value\)\)/);
  assert.doesNotMatch(meituanRankDisplayComputeds, /meituanSortMetricValue/);
  assert.match(sortMeituanTable, /const nextSort = resolveMeituanSortState\(meituanSortField\.value, meituanSortOrder\.value, field\);/);
  assert.match(sortMeituanTable, /meituanSortField\.value = nextSort\.field;/);
  assert.match(sortMeituanTable, /meituanSortOrder\.value = nextSort\.order;/);
  assert.doesNotMatch(sortMeituanTable, /meituanSortOrder\.value === 'asc' \? 'desc' : 'asc'/);
  assert.match(changeMeituanTablePage, /meituanTablePage\.value = resolveMeituanTablePage\(page, meituanTablePagination\.value\.totalPages\);/);
  assert.doesNotMatch(changeMeituanTablePage, /Math\.min\(Math\.max\(1, Number\(page\) \|\| 1\), meituanTablePagination\.value\.totalPages\)/);
  assert.doesNotMatch(html, /const meituanSortMetricValue = requireMeituanStatic\('meituanSortMetricValue'\);/);
  assert.match(meituanStatic, /const createEmptyMeituanBusinessSummary = \(\) => \(\{/);
  assert.match(meituanStatic, /update_policy: 'daily_09_previous_day'/);
  assert.match(meituanStatic, /const buildMeituanRankingFetchResetState = \(\) => \(\{/);
  assert.match(meituanStatic, /buildMeituanRankingFetchResetState,/);
  assert.match(meituanStatic, /const isMeituanPendingResult = \(result = \{\}\) =>/);
  assert.match(meituanStatic, /const isMeituanBackgroundResult = \(result = \{\}\) =>/);
  assert.match(meituanStatic, /const hasMeituanPendingResults = \(results = \[\]\) => Array\.isArray\(results\) && results\.some\(isMeituanPendingResult\);/);
  assert.match(meituanStatic, /const hasMeituanBackgroundResults = \(results = \[\]\) => Array\.isArray\(results\) && results\.some\(isMeituanBackgroundResult\);/);
  assert.match(meituanStatic, /isMeituanPendingResult,/);
  assert.match(meituanStatic, /isMeituanBackgroundResult,/);
  assert.match(meituanStatic, /hasMeituanPendingResults,/);
  assert.match(meituanStatic, /hasMeituanBackgroundResults,/);
  assert.match(html, /requireMeituanStatic\('createEmptyMeituanBusinessSummary'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanRankingFetchResetState'\)/);
  assert.match(html, /requireMeituanStatic\('isMeituanPendingResult'\)/);
  assert.match(html, /requireMeituanStatic\('isMeituanBackgroundResult'\)/);
  assert.match(html, /requireMeituanStatic\('hasMeituanPendingResults'\)/);
  assert.match(html, /requireMeituanStatic\('hasMeituanBackgroundResults'\)/);
  assert.match(html, /const meituanFetchInProgress = computed\(\(\) => hasMeituanPendingResults\(onlineDataResult\.value\)\);/);
  assert.match(html, /const meituanFetchBackgroundAccepted = computed\(\(\) => hasMeituanBackgroundResults\(onlineDataResult\.value\)\);/);
  assert.match(resetMeituanRankingFetchState, /Object\.assign\(meituanForm\.value, resetState\.formPatch\);/);
  assert.doesNotMatch(resetMeituanRankingFetchState, /meituanForm\.value\.partnerId = '';/);
  assert.doesNotMatch(html, /const isMeituanPendingResult = \(result = \{\}\) =>/);
  assert.doesNotMatch(html, /const isMeituanBackgroundResult = \(result = \{\}\) =>/);
  assert.doesNotMatch(html, /Array\.isArray\(onlineDataResult\.value\) && onlineDataResult\.value\.some\(isMeituanPendingResult\)/);
  assert.doesNotMatch(html, /Array\.isArray\(onlineDataResult\.value\) && onlineDataResult\.value\.some\(isMeituanBackgroundResult\)/);

  const resetState = meituanStaticApi.buildMeituanRankingFetchResetState();
  const nextResetState = meituanStaticApi.buildMeituanRankingFetchResetState();
  const normalizedFormPatch = JSON.parse(JSON.stringify(resetState.formPatch));
  const normalizedBusinessSummary = JSON.parse(JSON.stringify(resetState.businessSummary));
  assert.deepEqual(normalizedFormPatch, {
    partnerId: '',
    poiId: '',
    cookies: '',
    auth_data: {},
    hotelRoomCount: '',
    competitorRoomCount: '',
  });
  assert.equal(normalizedBusinessSummary.status, 'empty');
  assert.deepEqual(normalizedBusinessSummary.metrics, {});
  assert.deepEqual(normalizedBusinessSummary.cards, []);
  assert.equal(normalizedBusinessSummary.data_freshness.update_policy, 'daily_09_previous_day');
  assert.equal(normalizedBusinessSummary.source_notice, '每日9点更新前日数据。数据仅作经营参考，不作结算依据。');
  assert.equal(resetState.fetchSuccess, false);
  assert.equal(resetState.onlineDataResult, null);
  assert.equal(resetState.savedCount, 0);
  assert.equal(resetState.dataFetchTime, '');
  assert.notEqual(resetState.formPatch.auth_data, nextResetState.formPatch.auth_data);
  assert.notEqual(resetState.businessSummary, nextResetState.businessSummary);
  assert.equal(meituanStaticApi.isMeituanPendingResult({ status: 'fetching' }), true);
  assert.equal(meituanStaticApi.isMeituanPendingResult({ status: 'submitting' }), true);
  assert.equal(meituanStaticApi.isMeituanPendingResult({ status: 'running' }), false);
  assert.equal(meituanStaticApi.isMeituanBackgroundResult({ status: 'accepted' }), true);
  assert.equal(meituanStaticApi.isMeituanBackgroundResult({ status: 'running' }), true);
  assert.equal(meituanStaticApi.isMeituanBackgroundResult({ status: 'fetching' }), false);
  assert.equal(meituanStaticApi.hasMeituanPendingResults([{ status: 'done' }, { status: 'submitting' }]), true);
  assert.equal(meituanStaticApi.hasMeituanPendingResults([{ status: 'done' }]), false);
  assert.equal(meituanStaticApi.hasMeituanPendingResults(null), false);
  assert.equal(meituanStaticApi.hasMeituanBackgroundResults([{ status: 'queued' }]), true);
  assert.equal(meituanStaticApi.hasMeituanBackgroundResults([{ status: 'done' }]), false);
  assert.equal(meituanStaticApi.hasMeituanBackgroundResults(null), false);
  const fallbackRows = meituanStaticApi.buildMeituanTopSummaryFallbackRows([
    { poiId: 'p1', hotelName: 'A', circlePositionText: '第1', rankTrendText: '持平', platformTagText: 'VIP', roomNights: 3, sales: 120, gapToNextText: '领先' },
    { poiId: 'p2', hotelName: 'B', circlePositionText: '第2', roomNights: 0, sales: 0 },
    { poiId: 'p3', hotelName: 'C' },
    { poiId: 'p4', hotelName: 'D' },
  ], 2);
  assert.deepEqual(JSON.parse(JSON.stringify(fallbackRows)), [
    { poiId: 'p1', hotelName: 'A', positionText: '第1', rankTrendText: '持平', platformTagText: 'VIP', roomNights: 3, sales: 120, gapToNextText: '领先' },
    { poiId: 'p2', hotelName: 'B', positionText: '第2', rankTrendText: '', platformTagText: '', roomNights: 0, sales: 0, gapToNextText: '' },
  ]);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanTopSummaryFallbackRows(null))), []);
  const backendTopSummaryRows = [{ poiId: 'api-1', hotelName: 'API Row' }];
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanTopSummaryRows({
      businessSummary: { top_summary_rows: backendTopSummaryRows },
      rankedRows: [{ poiId: 'fallback-1', hotelName: 'Fallback Row' }],
    }))),
    backendTopSummaryRows
  );
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanTopSummaryRows({
      businessSummary: { top_summary_rows: [] },
      rankedRows: [
        { poiId: 'fallback-1', hotelName: 'Fallback A', circlePositionText: '第一' },
        { poiId: 'fallback-2', hotelName: 'Fallback B', circlePositionText: '第二' },
      ],
      limit: 1,
    }))),
    [{ poiId: 'fallback-1', hotelName: 'Fallback A', positionText: '第一', rankTrendText: '', platformTagText: '', roomNights: 0, sales: 0, gapToNextText: '' }]
  );
  const rankRows = [
    { hotelName: 'Self', isSelf: true, roomNights: 3, sales: 200 },
    { hotelName: 'Low', roomNights: 1, sales: 100 },
    { hotelName: 'High', roomNights: 8, sales: 300 },
  ];
  assert.equal(meituanStaticApi.findMeituanDynamicSelfRankRow(rankRows).hotelName, 'Self');
  assert.equal(meituanStaticApi.findMeituanDynamicSelfRankRow(null), null);
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanDisplayedHotelsList(rankRows, 'roomNights', 'asc').map(row => row.hotelName))),
    ['Low', 'Self', 'High']
  );
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanDisplayedHotelsList(rankRows, 'roomNights', 'desc').map(row => row.hotelName))),
    ['High', 'Self', 'Low']
  );
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSortState('roomNights', 'desc', 'roomNights'))), { field: 'roomNights', order: 'asc' });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSortState('roomNights', 'asc', 'roomNights'))), { field: 'roomNights', order: 'desc' });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSortState('roomNights', 'asc', 'sales'))), { field: 'sales', order: 'desc' });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSortState('', '', ''))), { field: 'roomNights', order: 'desc' });
  assert.equal(meituanStaticApi.resolveMeituanTablePage(0, 5), 1);
  assert.equal(meituanStaticApi.resolveMeituanTablePage(3, 5), 3);
  assert.equal(meituanStaticApi.resolveMeituanTablePage(8, 5), 5);
  assert.equal(meituanStaticApi.resolveMeituanTablePage('x', 4), 1);
  assert.equal(meituanStaticApi.resolveMeituanRankSourceNotice({ source_notice: 'source ok' }), 'source ok');
  assert.equal(meituanStaticApi.resolveMeituanRankSourceNotice(null), '');
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankInsightCards({ rank_insights: [{ key: 'rank-health' }] }))),
    [{ key: 'rank-health' }]
  );
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankInsightCards({}))), []);
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanVisibleRankInsightCards([{ key: 'tag-metric-link' }, { key: 'rank-health' }]))),
    [{ key: 'rank-health' }]
  );
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanVisibleRankInsightCards(null))), []);
  assert.deepEqual(
    JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankHealthRows({ rank_health_rows: [{ key: 'traffic', status: 'ok' }] }))),
    [{ key: 'traffic', status: 'ok' }]
  );
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankHealthRows({}))), []);
});

test('Meituan browser capture preview is owned by the static helper', () => {
  const browserCapturePreview = sliceFrom(
    'const meituanBrowserCaptureSelectedSectionsText = computed(() => (',
    '\n            // 美团差评获取表单'
  );

  const switchMeituanCaptureTab = sliceFrom(
    'const switchMeituanCaptureTab = async (tab, sections = []) => {',
    '\n\n            const runMeituanBrowserCaptureForSections'
  );
  const runMeituanBrowserCapturePreset = sliceFrom(
    'const runMeituanBrowserCapturePreset = async (preset = {}) => {',
    '\n\n            const runMeituanBrowserSupplementCapture'
  );
  const runMeituanBrowserSupplementCapture = sliceFrom(
    'const runMeituanBrowserSupplementCapture = async () => {',
    '\n\n            const copyMeituanBrowserCaptureCommand'
  );
  const copyMeituanBrowserCaptureCommand = sliceFrom(
    'const copyMeituanBrowserCaptureCommand = () => {',
    '\n\n            const clearMeituanBrowserCapturePayload'
  );
  const clearMeituanBrowserCapturePayload = sliceFrom(
    'const clearMeituanBrowserCapturePayload = () => {',
    '\n\n            const runMeituanBrowserCapture'
  );
  const runMeituanBrowserCapture = sliceFrom(
    'const runMeituanBrowserCapture = async (options = {}) => runMeituanBrowserCaptureFlow({',
    '\n\n            const runMeituanBrowserProfileLoginOnly'
  );
  const runMeituanBrowserProfileLoginOnly = sliceFrom(
    'const runMeituanBrowserProfileLoginOnly = async () => {',
    '\n\n            const saveMeituanCapturedPayload'
  );
  const saveMeituanCapturedPayload = sliceFrom(
    'const saveMeituanCapturedPayload = async () => runMeituanCapturedPayloadSaveFlow({',
    '\n\n            const goConfigureMeituanForSelectedHotel'
  );
  const goConfigureMeituanForSelectedHotel = sliceFrom(
    'const goConfigureMeituanForSelectedHotel = async () => {',
    '\n\n            const buildHotelOtaConfig'
  );
  const returnToMeituanRankingAfterConfigSave = sliceFrom(
    'const returnToMeituanRankingAfterConfigSave = async (hotelId) => {',
    '\n\n            let manualOnlineFetchConfigReadyPromise'
  );
  const syncMeituanBrowserCaptureFromSelectedConfig = sliceFrom(
    'const syncMeituanBrowserCaptureFromSelectedConfig = async (showMessage = false) => {',
    '\n\n            const switchMeituanCaptureTab'
  );
  const runMeituanBrowserCaptureForSections = sliceFrom(
    'const runMeituanBrowserCaptureForSections = async (sections = [], options = {}) => {',
    '\n\n            const runMeituanBrowserCapturePreset'
  );

  assert.match(meituanStatic, /const buildMeituanBrowserCaptureSelectedSectionsText = \(sections = \[\]\) => \{/);
  assert.match(meituanStatic, /const buildMeituanCaptureTabSwitchState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCapturePresetState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureDataPeriodApplyState = \(dataPeriod = ''\) => \{/);
  assert.match(meituanStatic, /const buildMeituanBrowserProfileLoginOnlyRunOptions = \(\) => \(\{/);
  assert.match(meituanStatic, /const resolveMeituanBrowserCaptureSystemHotelId = \(\{/);
  assert.match(meituanStatic, /const resolveMeituanSelectedHotelConfigAction = \(\{/);
  assert.match(meituanStatic, /const buildMeituanRankingReturnTargetState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserSupplementCaptureState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureCopyCommandState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureClearPayloadState = \(\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureConfigSyncState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureRunSectionsState = \(sections = \[\]\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureCommand = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureReadinessNotice = \(\{/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureSelectedSectionsText,/);
  assert.match(meituanStatic, /buildMeituanCaptureTabSwitchState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCapturePresetState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureDataPeriodApplyState,/);
  assert.match(meituanStatic, /buildMeituanBrowserProfileLoginOnlyRunOptions,/);
  assert.match(meituanStatic, /resolveMeituanBrowserCaptureSystemHotelId,/);
  assert.match(meituanStatic, /resolveMeituanSelectedHotelConfigAction,/);
  assert.match(meituanStatic, /buildMeituanRankingReturnTargetState,/);
  assert.match(meituanStatic, /buildMeituanBrowserSupplementCaptureState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureCopyCommandState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureClearPayloadState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureConfigSyncState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureRunSectionsState,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureCommand,/);
  assert.match(meituanStatic, /buildMeituanBrowserCaptureReadinessNotice,/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureSelectedSectionsText'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanCaptureTabSwitchState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCapturePresetState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureDataPeriodApplyState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserProfileLoginOnlyRunOptions'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanBrowserCaptureSystemHotelId'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanSelectedHotelConfigAction'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanRankingReturnTargetState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserSupplementCaptureState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureCopyCommandState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureClearPayloadState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureConfigSyncState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureRunSectionsState'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureCommand'\)/);
  assert.match(html, /requireMeituanStatic\('buildMeituanBrowserCaptureReadinessNotice'\)/);
  assert.match(browserCapturePreview, /buildMeituanBrowserCaptureSelectedSectionsText\(meituanBrowserCaptureForm\.value\.captureSections\)/);
  assert.match(browserCapturePreview, /buildMeituanBrowserCaptureCommand\(\{/);
  assert.match(browserCapturePreview, /buildMeituanBrowserCaptureReadinessNotice\(\{/);
  assert.match(browserCapturePreview, /rankingForm: meituanForm\.value/);
  assert.match(browserCapturePreview, /userHotelId: user\.value\?\.hotel_id/);
  assert.match(browserCapturePreview, /hotelName: getHotelNameById\(meituanForm\.value\.hotelId\)/);
  assert.doesNotMatch(browserCapturePreview, /const sectionLabels/);
  assert.doesNotMatch(browserCapturePreview, /node scripts\/meituan_browser_capture\.mjs/);
  assert.doesNotMatch(browserCapturePreview, /captureSections\.includes\('ads'\)/);
  assert.match(switchMeituanCaptureTab, /const switchState = buildMeituanCaptureTabSwitchState\(\{ tab, sections \}\);/);
  assert.match(switchMeituanCaptureTab, /onlineDataTab\.value = switchState\.tab;/);
  assert.match(switchMeituanCaptureTab, /meituanBrowserCaptureForm\.value\.captureSections = switchState\.captureSections;/);
  assert.match(switchMeituanCaptureTab, /meituanBrowserCaptureResult\.value = switchState\.captureResult;/);
  assert.match(switchMeituanCaptureTab, /if \(switchState\.shouldSyncTrafficConfig\) \{/);
  assert.doesNotMatch(switchMeituanCaptureTab, /normalizeMeituanCaptureSections\(sections\)/);
  assert.doesNotMatch(switchMeituanCaptureTab, /if \(tab === 'meituan-traffic'\)/);
  assert.match(runMeituanBrowserCapturePreset, /const presetState = buildMeituanBrowserCapturePresetState\(\{/);
  assert.match(runMeituanBrowserCapturePreset, /preset,\s*currentDataPeriod: meituanBrowserCaptureForm\.value\.dataPeriod,/);
  assert.match(runMeituanBrowserCapturePreset, /const dataPeriodApplyState = buildMeituanBrowserCaptureDataPeriodApplyState\(presetState\.dataPeriod\);/);
  assert.match(runMeituanBrowserCapturePreset, /if \(dataPeriodApplyState\.shouldApply\) \{/);
  assert.match(runMeituanBrowserCapturePreset, /meituanBrowserCaptureForm\.value\.dataPeriod = dataPeriodApplyState\.dataPeriod;/);
  assert.match(runMeituanBrowserCapturePreset, /runMeituanBrowserCaptureForSections\(presetState\.captureSections, \{ dataPeriod: dataPeriodApplyState\.dataPeriod \}\);/);
  assert.doesNotMatch(runMeituanBrowserCapturePreset, /preset\.dataPeriod \|\| preset\.data_period/);
  assert.doesNotMatch(runMeituanBrowserCapturePreset, /if \(presetState\.dataPeriod\)/);
  assert.doesNotMatch(runMeituanBrowserCapturePreset, /meituanBrowserCaptureForm\.value\.dataPeriod = presetState\.dataPeriod;/);
  assert.doesNotMatch(runMeituanBrowserCapturePreset, /runMeituanBrowserCaptureForSections\(preset\.sections \|\| \[\]/);
  assert.match(runMeituanBrowserSupplementCapture, /const supplementState = buildMeituanBrowserSupplementCaptureState\(\{/);
  assert.match(runMeituanBrowserSupplementCapture, /autoFetchHotelId: autoFetchHotelId\.value,/);
  assert.match(runMeituanBrowserSupplementCapture, /formHotelId: meituanForm\.value\.hotelId,/);
  assert.match(runMeituanBrowserSupplementCapture, /userHotelId: user\.value\?\.hotel_id,/);
  assert.match(runMeituanBrowserSupplementCapture, /showToast\(supplementState\.message, supplementState\.level\);/);
  assert.match(runMeituanBrowserSupplementCapture, /meituanForm\.value\.hotelId = supplementState\.hotelId;/);
  assert.match(runMeituanBrowserSupplementCapture, /runMeituanBrowserCaptureForSections\(supplementState\.captureSections, \{ dataPeriod: supplementState\.dataPeriod \}\);/);
  assert.doesNotMatch(runMeituanBrowserSupplementCapture, /autoFetchHotelId\.value \|\| meituanForm\.value\.hotelId \|\| user\.value\?\.hotel_id/);
  assert.doesNotMatch(runMeituanBrowserSupplementCapture, /runMeituanBrowserCaptureForSections\(\['full'\], \{ dataPeriod: 'historical_daily' \}\)/);
  assert.match(copyMeituanBrowserCaptureCommand, /const copyState = buildMeituanBrowserCaptureCopyCommandState\(\{/);
  assert.match(copyMeituanBrowserCaptureCommand, /storeId: meituanBrowserCaptureForm\.value\.storeId,/);
  assert.match(copyMeituanBrowserCaptureCommand, /formHotelId: meituanForm\.value\.hotelId,/);
  assert.match(copyMeituanBrowserCaptureCommand, /userHotelId: user\.value\?\.hotel_id,/);
  assert.match(copyMeituanBrowserCaptureCommand, /if \(!copyState\.canCopy\) \{/);
  assert.match(copyMeituanBrowserCaptureCommand, /showToast\(copyState\.message, copyState\.level\);/);
  assert.doesNotMatch(copyMeituanBrowserCaptureCommand, /!meituanBrowserCaptureForm\.value\.storeId/);
  assert.doesNotMatch(copyMeituanBrowserCaptureCommand, /!\(meituanForm\.value\.hotelId \|\| user\.value\?\.hotel_id\)/);
  assert.match(clearMeituanBrowserCapturePayload, /const clearState = buildMeituanBrowserCaptureClearPayloadState\(\);/);
  assert.match(clearMeituanBrowserCapturePayload, /meituanBrowserCaptureForm\.value\.payloadJson = clearState\.payloadJson;/);
  assert.match(clearMeituanBrowserCapturePayload, /meituanBrowserCaptureResult\.value = clearState\.captureResult;/);
  assert.doesNotMatch(clearMeituanBrowserCapturePayload, /meituanBrowserCaptureForm\.value\.payloadJson = '';/);
  assert.doesNotMatch(clearMeituanBrowserCapturePayload, /meituanBrowserCaptureResult\.value = null;/);
  assert.match(runMeituanBrowserCapture, /getSystemHotelId: \(\) => resolveMeituanBrowserCaptureSystemHotelId\(\{/);
  assert.match(runMeituanBrowserCapture, /formHotelId: meituanForm\.value\.hotelId,/);
  assert.match(runMeituanBrowserCapture, /autoFetchHotelId: autoFetchHotelId\.value,/);
  assert.match(runMeituanBrowserCapture, /userHotelId: user\.value\?\.hotel_id,/);
  assert.doesNotMatch(runMeituanBrowserCapture, /meituanForm\.value\.hotelId \|\| autoFetchHotelId\.value \|\| user\.value\?\.hotel_id/);
  assert.match(runMeituanBrowserProfileLoginOnly, /const loginOnlyOptions = buildMeituanBrowserProfileLoginOnlyRunOptions\(\);/);
  assert.match(runMeituanBrowserProfileLoginOnly, /await runMeituanBrowserCapture\(loginOnlyOptions\);/);
  assert.doesNotMatch(runMeituanBrowserProfileLoginOnly, /runMeituanBrowserCapture\(\{ loginOnly: true, bindDataSource: true \}\)/);
  assert.match(saveMeituanCapturedPayload, /getSystemHotelId: \(\) => resolveMeituanBrowserCaptureSystemHotelId\(\{/);
  assert.match(saveMeituanCapturedPayload, /formHotelId: meituanForm\.value\.hotelId,/);
  assert.match(saveMeituanCapturedPayload, /userHotelId: user\.value\?\.hotel_id,/);
  assert.doesNotMatch(saveMeituanCapturedPayload, /meituanForm\.value\.hotelId \|\| user\.value\?\.hotel_id/);
  assert.match(goConfigureMeituanForSelectedHotel, /const action = resolveMeituanSelectedHotelConfigAction\(\{/);
  assert.match(goConfigureMeituanForSelectedHotel, /hotels: hotels\.value,/);
  assert.match(goConfigureMeituanForSelectedHotel, /hotelId: meituanForm\.value\.hotelId,/);
  assert.match(goConfigureMeituanForSelectedHotel, /showToast\(action\.message, action\.level\);/);
  assert.match(goConfigureMeituanForSelectedHotel, /openHotelManualFetchConfig\(action\.hotel, action\.platform\);/);
  assert.doesNotMatch(goConfigureMeituanForSelectedHotel, /hotels\.value\.find/);
  assert.doesNotMatch(goConfigureMeituanForSelectedHotel, /openHotelManualFetchConfig\(hotel, 'meituan'\)/);
  assert.match(returnToMeituanRankingAfterConfigSave, /const returnState = buildMeituanRankingReturnTargetState\(\{/);
  assert.match(returnToMeituanRankingAfterConfigSave, /hotelId,/);
  assert.match(returnToMeituanRankingAfterConfigSave, /currentHotelId: meituanForm\.value\.hotelId,/);
  assert.match(returnToMeituanRankingAfterConfigSave, /currentPage\.value = returnState\.page;/);
  assert.match(returnToMeituanRankingAfterConfigSave, /onlineDataTab\.value = returnState\.tab;/);
  assert.match(returnToMeituanRankingAfterConfigSave, /meituanForm\.value\.hotelId = returnState\.targetHotelId;/);
  assert.match(returnToMeituanRankingAfterConfigSave, /const afterReloadState = buildMeituanRankingReturnTargetState\(\{/);
  assert.match(returnToMeituanRankingAfterConfigSave, /meituanForm\.value\.hotelId = afterReloadState\.targetHotelId;/);
  assert.doesNotMatch(returnToMeituanRankingAfterConfigSave, /String\(hotelId \|\| ''\)\.trim\(\)/);
  assert.doesNotMatch(returnToMeituanRankingAfterConfigSave, /currentPage\.value = 'meituan-ebooking';/);
  assert.doesNotMatch(returnToMeituanRankingAfterConfigSave, /onlineDataTab\.value = 'meituan-ranking';/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /const syncState = buildMeituanBrowserCaptureConfigSyncState\(\{/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /hotelId,/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /hotelName,/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /config,/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /formPoiId: meituanForm\.value\.poiId,/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /captureForm: meituanBrowserCaptureForm\.value,/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /Object\.assign\(meituanBrowserCaptureForm\.value, syncState\.formUpdates\);/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /if \(!syncState\.hasHotel\) \{/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /meituanForm\.value\.poiId = syncState\.rankingPoiId;/);
  assert.match(syncMeituanBrowserCaptureFromSelectedConfig, /showMessage === true && syncState\.shouldNotify/);
  assert.doesNotMatch(syncMeituanBrowserCaptureFromSelectedConfig, /firstNonEmptyText\(/);
  assert.doesNotMatch(syncMeituanBrowserCaptureFromSelectedConfig, /firstDataConfigValue\(/);
  assert.match(runMeituanBrowserCaptureForSections, /const runSectionsState = buildMeituanBrowserCaptureRunSectionsState\(sections\);/);
  assert.match(runMeituanBrowserCaptureForSections, /meituanBrowserCaptureForm\.value\.captureSections = runSectionsState\.captureSections;/);
  assert.match(runMeituanBrowserCaptureForSections, /const result = await runMeituanBrowserCapture\(options\);/);
  assert.match(runMeituanBrowserCaptureForSections, /return result;/);
  assert.doesNotMatch(runMeituanBrowserCaptureForSections, /normalizeMeituanCaptureSections\(sections\)/);

  assert.equal(meituanStaticApi.buildMeituanBrowserCaptureSelectedSectionsText([]), '未选择');
  assert.equal(meituanStaticApi.buildMeituanBrowserCaptureSelectedSectionsText(['traffic', 'orders']), '流量、订单');
  assert.equal(meituanStaticApi.buildMeituanBrowserCaptureSelectedSectionsText(['ads']), '广告');
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanCaptureTabSwitchState({
    tab: 'meituan-traffic',
    sections: ['flow', 'ads'],
  }))), {
    tab: 'meituan-traffic',
    captureSections: ['traffic', 'ads'],
    captureResult: null,
    shouldSyncTrafficConfig: true,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanCaptureTabSwitchState({
    tab: 'meituan-browser-capture',
    sections: 'full',
  }))), {
    tab: 'meituan-browser-capture',
    captureSections: ['traffic', 'orders', 'reviews', 'ads'],
    captureResult: null,
    shouldSyncTrafficConfig: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCapturePresetState({
    preset: { data_period: 'realtime', sections: ['flow', 'reviews'] },
    currentDataPeriod: 'historical_daily',
  }))), {
    dataPeriod: 'realtime',
    captureSections: ['traffic', 'reviews'],
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCapturePresetState({
    preset: {},
    currentDataPeriod: 'weekly',
  }))), {
    dataPeriod: 'weekly',
    captureSections: [],
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureDataPeriodApplyState(' realtime '))), {
    shouldApply: true,
    dataPeriod: 'realtime',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureDataPeriodApplyState(''))), {
    shouldApply: false,
    dataPeriod: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserProfileLoginOnlyRunOptions())), {
    loginOnly: true,
    bindDataSource: true,
  });
  assert.equal(meituanStaticApi.resolveMeituanBrowserCaptureSystemHotelId({
    formHotelId: ' 58 ',
    autoFetchHotelId: '7',
    userHotelId: '99',
  }), '58');
  assert.equal(meituanStaticApi.resolveMeituanBrowserCaptureSystemHotelId({
    formHotelId: '',
    autoFetchHotelId: ' 7 ',
    userHotelId: '99',
  }), '7');
  assert.equal(meituanStaticApi.resolveMeituanBrowserCaptureSystemHotelId({
    formHotelId: '',
    autoFetchHotelId: '',
    userHotelId: '',
  }), null);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSelectedHotelConfigAction({
    hotels: [{ id: 7, name: 'A' }, { id: 58, name: 'B' }],
    hotelId: '58',
  }))), {
    ok: true,
    hotel: { id: 58, name: 'B' },
    platform: 'meituan',
    message: '',
    level: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanSelectedHotelConfigAction({
    hotels: [{ id: 7, name: 'A' }],
    hotelId: '',
  }))), {
    ok: false,
    hotel: null,
    platform: 'meituan',
    message: '请先选择要归属数据的酒店',
    level: 'warning',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankingReturnTargetState({
    hotelId: ' 58 ',
    currentHotelId: '7',
  }))), {
    ok: true,
    targetHotelId: '58',
    page: 'meituan-ebooking',
    tab: 'meituan-ranking',
    shouldApplyHotelId: true,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankingReturnTargetState({
    hotelId: '7',
    currentHotelId: '7',
  }))), {
    ok: true,
    targetHotelId: '7',
    page: 'meituan-ebooking',
    tab: 'meituan-ranking',
    shouldApplyHotelId: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanRankingReturnTargetState({
    hotelId: '',
    currentHotelId: '7',
  }))), {
    ok: false,
    targetHotelId: '',
    page: 'meituan-ebooking',
    tab: 'meituan-ranking',
    shouldApplyHotelId: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserSupplementCaptureState({
    autoFetchHotelId: ' 7 ',
    formHotelId: '58',
    userHotelId: '99',
  }))), {
    ok: true,
    hotelId: '7',
    captureSections: ['traffic', 'orders', 'reviews', 'ads'],
    dataPeriod: 'historical_daily',
    message: '',
    level: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserSupplementCaptureState({
    autoFetchHotelId: '',
    formHotelId: '',
    userHotelId: '',
  }))), {
    ok: false,
    hotelId: '',
    captureSections: [],
    dataPeriod: '',
    message: '请先选择酒店',
    level: 'error',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureCopyCommandState({
    storeId: 'poi-1',
    formHotelId: '7',
  }))), {
    canCopy: true,
    message: '',
    level: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureCopyCommandState({
    storeId: '',
    formHotelId: '',
    userHotelId: '',
  }))), {
    canCopy: false,
    message: '请先选择酒店并填写美团门店标识',
    level: 'warning',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureClearPayloadState())), {
    payloadJson: '',
    captureResult: null,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureConfigSyncState({
    hotelId: '',
    captureForm: { storeId: 'old-store', poiId: 'old-poi', poiName: 'old-name' },
  }))), {
    hasHotel: false,
    formUpdates: {
      storeId: '',
      poiId: '',
      poiName: '',
    },
    rankingPoiId: '',
    shouldNotify: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureConfigSyncState({
    hotelId: '58',
    hotelName: '凯曼未来酒店',
    config: {
      poi_id: 'poi-config',
      name: '配置酒店',
      ads_url: ' https://ads.example.test ',
      data_period: 'realtime',
    },
    formPoiId: 'poi-form',
    captureForm: {
      storeId: 'old-store',
      poiName: '旧名称',
      adsUrl: 'old-url',
      dataPeriod: 'historical_daily',
    },
  }))), {
    hasHotel: true,
    formUpdates: {
      poiName: '配置酒店',
      storeId: 'poi-config',
      poiId: 'poi-config',
      adsUrl: 'https://ads.example.test',
      dataPeriod: 'realtime',
    },
    rankingPoiId: 'poi-config',
    shouldNotify: true,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanBrowserCaptureRunSectionsState(['flow', 'ads']))), {
    captureSections: ['traffic', 'ads'],
  });
  assert.equal(
    meituanStaticApi.buildMeituanBrowserCaptureCommand({
      form: {
        storeId: 'store-1',
        poiId: 'poi-2',
        poiName: '凯曼"测试"',
        adsUrl: 'https://ad.example.test/?q="x"',
        dataPeriod: 'historical_daily',
        captureSections: ['ads'],
      },
      rankingForm: { hotelId: '58' },
      userHotelId: '7',
    }),
    'node scripts/meituan_browser_capture.mjs --store-id=store-1 --system-hotel-id=58 --poi-id=poi-2 --poi-name="凯曼\\"测试\\"" --sections=ads --data-period=historical_daily --ads-url="https://ad.example.test/?q=\\"x\\""'
  );
  assert.equal(
    meituanStaticApi.buildMeituanBrowserCaptureCommand({
      form: {},
      rankingForm: {},
      userHotelId: '',
    }),
    '请先选择目标酒店并填写美团门店标识'
  );
  const missingIdentityNotice = meituanStaticApi.buildMeituanBrowserCaptureReadinessNotice({
    form: {},
    rankingForm: {},
    userHotelId: '',
  });
  assert.equal(missingIdentityNotice.status, 'missing_identity');
  assert.equal(missingIdentityNotice.level, 'warning');
  assert.match(missingIdentityNotice.className, /border-amber-200/);
  assert.match(missingIdentityNotice.message, /门店标识/);
  const missingAdsNotice = meituanStaticApi.buildMeituanBrowserCaptureReadinessNotice({
    form: { storeId: 'store-1', captureSections: ['ads'], adsUrl: '' },
    rankingForm: { hotelId: '58' },
  });
  assert.equal(missingAdsNotice.status, 'missing_ads_url');
  assert.equal(missingAdsNotice.level, 'error');
  assert.match(missingAdsNotice.className, /border-red-200/);
  assert.match(missingAdsNotice.message, /广告入口 URL/);
  assert.equal(meituanStaticApi.buildMeituanBrowserCaptureReadinessNotice({
    form: { storeId: 'store-1', captureSections: ['traffic'] },
    rankingForm: { hotelId: '58' },
  }), null);
});

test('Meituan API login failures stay explicit across backend and manual fetch response', () => {
  const failureBuilder = businessDisplayConcern.slice(
    businessDisplayConcern.indexOf('private function buildMeituanBusinessFailurePayload'),
    businessDisplayConcern.indexOf('private function fetchMeituanTrafficMetricsForDisplay')
  );
  const fetchResultPanel = sliceFrom('<!-- 获取结果显示 -->', '<!-- 原始JSON数据 -->');

  assert.match(failureBuilder, /\['303', '401', '403'\]/);
  assert.match(failureBuilder, /login_required/);
  assert.match(failureBuilder, /credential_status/);
  assert.match(failureBuilder, /美团登录态已失效/);
  assert.match(onlineDataManualFetchConcern, /'reason'\s*=>\s*\$result\['reason'\]\s*\?\?\s*'meituan_request_failed'/);
  assert.match(onlineDataManualFetchConcern, /'credential_status'\s*=>\s*\$result\['credential_status'\]\s*\?\?\s*''/);
  assert.match(onlineDataManualFetchConcern, /'business_code'\s*=>\s*\$result\['business_code'\]\s*\?\?\s*null/);
  assert.match(fetchResultPanel, /onlineDataResult\s*&&\s*onlineDataResult\.length\s*>\s*0/);
  assert.match(fetchResultPanel, /数据获取失败/);
});

test('Meituan business summary exposes market total and average cards', () => {
  const summaryBuilder = businessDisplayConcern.slice(
    businessDisplayConcern.indexOf('private function buildMeituanBusinessDisplaySummary'),
    businessDisplayConcern.indexOf('private function countMeituanDerivedMetrics')
  );
  const rankingTable = sliceFrom('<!-- 美团竞对排名数据表格 -->', '<!-- 竞对排名表格 -->');
  const rankTable = sliceFrom(
    '<table class="min-w-full bg-white border text-sm table-striped">',
    '<div data-testid="meituan-rank-summary-second-screen"'
  );

  assert.match(rankingTable, /商圈汇总与平均指标/);
  assert.match(rankingTable, /text-2xl/);
  assert.match(rankingTable, /text-base font-semibold/);
  assert.match(rankTable, /table-striped/);
  assert.match(rankTable, /bg-rose-50/);
  assert.match(rankTable, /bg-emerald-50/);
  assert.match(rankTable, /bg-sky-50/);
  assert.match(rankTable, /bg-violet-50/);
  assert.match(rankTable, /v-else-if="hotel\.platformTagSourceText && !\['平台返回空标签', '标签未返回'\]\.includes\(hotel\.platformTagSourceText\)"/);
  assert.match(summaryBuilder, /'totalRoomNights', '总入住间夜'/);
  assert.match(summaryBuilder, /'totalRoomRevenue', '总房费收入', \$hasDisplayableRoomRevenue \? \('¥'/);
  assert.match(summaryBuilder, /'avgRoomPrice', '商圈平均房价'/);
  assert.match(summaryBuilder, /'totalSalesRoomNights', '总销售间夜'/);
  assert.match(summaryBuilder, /'totalSales', '总销售额', \$hasDisplayableSales \? \('¥' \. number_format/);
  assert.match(summaryBuilder, /'avgSalesPrice', '商圈平均销售房价'/);
});

test('Meituan business summary fallback keeps the full market card grid', () => {
  const fallbackSummaryStart = meituanStatic.indexOf('const buildMeituanBusinessSummaryFallbackCards = ({');
  assert.ok(fallbackSummaryStart >= 0, 'missing Meituan fallback summary builder');
  const fallbackSummaryEnd = meituanStatic.indexOf('const runMeituanManualTabSwitch = async ({', fallbackSummaryStart);
  const fallbackSummary = meituanStatic.slice(fallbackSummaryStart, fallbackSummaryEnd);
  const sampleRows = [
    { roomRevenue: '￥1,000', roomNights: '10', sales: '700', views: '300', viewConversion: '0.25' },
    { avgRoomPrice: '200', roomRevenue: '400', roomNights: '2', sales: '300', views: '100', viewConversion: '12.5%' },
  ];

  const sampleTagRows = [
    { platformTags: ['VIP'] },
    { hasVipTag: true },
    { platformTags: ['member'] },
  ];

  assert.match(html, /const resolveMeituanFallbackMarketInventory = requireMeituanStatic\('resolveMeituanFallbackMarketInventory'\);/);
  assert.match(html, /const resolveMeituanBusinessSummaryCards = requireMeituanStatic\('resolveMeituanBusinessSummaryCards'\);/);
  assert.match(html, /const meituanBusinessSummaryCards = computed\(\(\) => applyMeituanFetchHealthToCards\(\s*resolveMeituanBusinessSummaryCards\(\{/);
  assert.match(meituanStatic, /const meituanFallbackMetricNumber = \(value\) => \{/);
  assert.match(meituanStatic, /const meituanFallbackPriceSigma = \(rows\) => \{/);
  assert.match(meituanStatic, /const meituanFallbackRankHealth = \(rows\) => \{/);
  assert.match(meituanStatic, /const meituanFallbackNumberText = \(value, decimals = 0, formatNumber = item => String\(item\)\) => \{/);
  assert.match(meituanStatic, /const meituanFallbackCard = \(key, label, value, valueClass, panelClass, level = '', levelClass = 'text-gray-500'\) => \(\{/);
  assert.match(meituanStatic, /const resolveMeituanFallbackMarketInventory = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBusinessSummaryFallbackCards = \(\{/);
  assert.match(meituanStatic, /const resolveMeituanBusinessSummaryCards = \(\{/);
  assert.equal(meituanStaticApi.meituanFallbackMetricNumber('￥1,234.50%'), 1234.5);
  assert.equal(meituanStaticApi.meituanFallbackHasPositiveMetric(sampleRows, 'sales'), true);
  assert.equal(meituanStaticApi.meituanFallbackHasPositiveMetric(null, 'sales'), false);
  assert.equal(meituanStaticApi.meituanFallbackSum(sampleRows, 'roomNights'), 12);
  assert.equal(meituanStaticApi.meituanFallbackAverage(sampleRows, 'viewConversion', true), 18.75);
  assert.equal(Math.round(meituanStaticApi.meituanFallbackHhi(sampleRows, 'sales')), 5800);
  assert.equal(Number(meituanStaticApi.meituanFallbackPriceSigma(sampleRows).toFixed(2)), 33.33);
  assert.equal(JSON.stringify(meituanStaticApi.meituanFallbackRankHealth(sampleRows)), JSON.stringify({ readyCount: 4, totalCount: 4 }));
  assert.equal(JSON.stringify(meituanStaticApi.meituanFallbackPlatformTags(sampleTagRows)), JSON.stringify({ returnedCount: 2, vipCount: 2 }));
  assert.equal(meituanStaticApi.resolveMeituanFallbackMarketInventory({
    form: { competitorRoomCount: ' 99 ' },
    selectedConfig: { competitor_room_count: '120' },
  }), 99);
  assert.equal(meituanStaticApi.resolveMeituanFallbackMarketInventory({
    form: {},
    selectedConfig: { competitorRoomCount: '120' },
  }), 120);
  assert.equal(meituanStaticApi.meituanFallbackMarketPriceSignal(100, 89), '销售价偏低');
  assert.equal(meituanStaticApi.meituanFallbackMarketPriceSignal(100, 111), '销售价偏高');
  assert.equal(meituanStaticApi.meituanFallbackMarketPriceSignal(100, 100), '价格稳定');
  assert.equal(meituanStaticApi.meituanFallbackNumberText(1234.56, 1, value => `N:${value}`), 'N:1234.6');
  assert.equal(meituanStaticApi.meituanFallbackNumberText(0, 1, value => `N:${value}`), '-');
  assert.equal(meituanStaticApi.meituanFallbackMoneyText(1234.56, value => `N:${value}`), '¥N:1234');
  assert.equal(meituanStaticApi.meituanFallbackPercentText(12.3456, (value, decimals) => Number(value).toFixed(decimals)), '12.35%');
  assert.equal(meituanStaticApi.meituanFallbackMetricText(sampleRows, 'roomNights', 0, value => `N:${value}`), 'N:12');
  const fallbackCards = meituanStaticApi.buildMeituanBusinessSummaryFallbackCards({
    rows: sampleRows,
    marketInventory: 120,
    formatNumber: value => `N:${value}`,
    toFixedSafe: (value, decimals) => Number(value).toFixed(decimals),
  });
  assert.equal(fallbackCards.length, 24);
  assert.equal(fallbackCards[0].key, 'fallback-hotel-count');
  assert.equal(fallbackCards.some(card => card.key === 'fallback-market-inventory' && card.value === 'N:120'), true);
  const backendCards = [{ key: 'backend-total', value: 'ready' }];
  assert.equal(meituanStaticApi.resolveMeituanBusinessSummaryCards({
    businessSummary: { cards: backendCards },
    rankedRows: sampleRows,
  }), backendCards);
  assert.equal(meituanStaticApi.resolveMeituanBusinessSummaryCards({
    businessSummary: {},
    rankedRows: sampleRows,
    marketInventory: 120,
    formatNumber: value => `N:${value}`,
    toFixedSafe: (value, decimals) => Number(value).toFixed(decimals),
  }).length, 24);
  assert.equal(JSON.stringify(meituanStaticApi.meituanFallbackCard('k', 'label', 'value', 'v', 'p', 'level', 'l')), JSON.stringify({
    key: 'k',
    label: 'label',
    value: 'value',
    level: 'level',
    panelClass: 'p',
    valueClass: 'v',
    levelClass: 'l',
  }));

  [
    'fallback-hotel-count',
    'fallback-rank-health',
    'fallback-self-position',
    'fallback-platform-vip-tags',
    'fallback-market-inventory',
    'fallback-market-vitality',
    'fallback-price-sigma',
    'fallback-market-price-signal',
    'fallback-inventory-turnover',
    'fallback-revenue-concentration',
    'fallback-visit-concentration',
    'fallback-operation-focus',
    'fallback-total-room-nights',
    'fallback-total-room-revenue',
    'fallback-avg-room-price',
    'fallback-total-sales-room-nights',
    'fallback-total-sales',
    'fallback-avg-sales-price',
    'fallback-total-exposure',
    'fallback-total-views',
    'fallback-total-order-count',
    'fallback-avg-view-conversion',
    'fallback-avg-pay-conversion',
    'fallback-avg-absolute-conversion',
  ].forEach(key => assert.match(fallbackSummary, new RegExp(key)));

  const operationFocusCard = fallbackSummary.split('\n').find(line => line.includes('fallback-operation-focus')) || '';
  assert.doesNotMatch(operationFocusCard, /meituanRankSourceNotice/);
  assert.doesNotMatch(operationFocusCard, /仅已返回字段/);
  assert.doesNotMatch(fallbackSummary, /fallback-top-hotel/);
  assert.doesNotMatch(fallbackSummary, /fallback-source-scope/);
});

test('Meituan batch fetch keeps backend display summary after model build', async () => {
  let capturedDisplaySummary = null;
  const businessSummaryWrites = [];
  const notifications = [];

  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({
      url: 'https://eb.meituan.com/api/v1/ebooking/data/rank',
      hotelId: 58,
      partnerId: '4517495',
      poiId: '1022727174',
      cookies: 'token=ok',
      dateRanges: ['1'],
    }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      partner_id: '4517495',
      poi_id: '1022727174',
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async () => ({
      code: 200,
      data: {
        saved_count: 1,
        display_hotels: [{ poiId: '1022727174', hotelName: 'Self Hotel', roomNights: 3 }],
      },
    }),
    requestDisplayModel: async () => ({
      code: 200,
      data: {
        display_hotels: [{ poiId: '1022727174', hotelName: 'Self Hotel', roomNights: 3 }],
        display_summary: {
          cards: [{ key: 'totalRoomNights', label: '总入住间夜', value: '3' }],
        },
      },
    }),
    useDisplayModel: data => {
      capturedDisplaySummary = data.display_summary;
      return data.display_hotels;
    },
    setBusinessSummary: value => {
      businessSummaryWrites.push(value);
    },
    notify: message => {
      notifications.push(message);
    },
  });

  assert.equal(result.status, 'success');
  assert.equal(capturedDisplaySummary?.cards?.[0]?.key, 'totalRoomNights');
  assert.deepEqual(businessSummaryWrites, []);
  assert.equal(notifications.length, 2);
  assert.match(notifications[0], /4/);
});

test('Meituan ranking retry attempts defer persistence', () => {
  const tasks = meituanStaticApi.buildMeituanBatchFetchTasks({
    form: { hotelId: 58, dateRanges: ['0'] },
    configId: 'meituan-58',
  });

  assert.ok(tasks.length > 0);
  tasks.forEach(task => assert.equal(task.body.auto_save, false));
});

test('Meituan ranking commits only the complete candidate for each retry task', async () => {
  const requestCounts = new Map();
  const committed = [];
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      const attempt = (requestCounts.get(body.rank_type) || 0) + 1;
      requestCounts.set(body.rank_type, attempt);
      const complete = body.rank_type !== 'P_XS' || attempt === 2;
      const requiresAbsolute = ['P_RZ', 'P_XS'].includes(body.rank_type);
      return {
        code: 200,
        data: {
          saved_count: 0,
          rank_candidate: {
            candidate_id: `${body.rank_type}-${attempt}`,
            config_id: body.config_id,
            system_hotel_id: body.system_hotel_id,
            poi_id: 'self',
            start_date: '2026-07-12',
            end_date: '2026-07-12',
            date_range: body.date_range,
            rank_type: body.rank_type,
          },
          data: {
            status: 0,
            data: {
              peerRankData: [
                {
                  aiMetricName: `${body.rank_type}_A`,
                  roundRanks: [{ poiId: 'self', rank: 1, dataValue: requiresAbsolute && complete ? 0 : null, percent: complete ? 100 : 0 }],
                },
                {
                  aiMetricName: `${body.rank_type}_B`,
                  roundRanks: [{ poiId: 'self', rank: 1, dataValue: requiresAbsolute && complete ? 80 : null, percent: complete ? 80 : 0 }],
                },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestCommit: async body => {
      committed.push({ ...body });
      return { code: 200, data: { saved_count: 20 } };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(requestCounts.get('P_XS'), 2);
  assert.deepEqual(committed.filter(item => item.rank_type === 'P_XS').map(item => item.candidate_id), ['P_XS-2']);
  assert.equal(committed.length, 4);
  assert.equal(result.totalSavedCount, 80);
});

test('Meituan ranking reports platform responses before queued database commits finish', async () => {
  const resultWrites = [];
  const commitResolvers = [];
  const flowPromise = meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => ({
      code: 200,
      data: {
        saved_count: 0,
        rank_candidate: {
          candidate_id: `${body.rank_type}-candidate`,
          config_id: body.config_id,
          system_hotel_id: body.system_hotel_id,
          poi_id: 'self',
          start_date: '2026-07-12',
          end_date: '2026-07-12',
          date_range: body.date_range,
          rank_type: body.rank_type,
        },
        data: {
          status: 0,
          data: {
            peerRankData: [
              { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: ['P_RZ', 'P_XS'].includes(body.rank_type) ? 0 : null, percent: 100 }] },
              { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: ['P_RZ', 'P_XS'].includes(body.rank_type) ? 80 : null, percent: 80 }] },
            ],
          },
        },
        display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
      },
    }),
    requestCommit: body => new Promise(resolve => { commitResolvers.push({ body, resolve }); }),
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
    setOnlineDataResult: value => resultWrites.push(JSON.parse(JSON.stringify(value))),
  });

  await new Promise(resolve => setImmediate(resolve));
  await new Promise(resolve => setImmediate(resolve));
  const observedBeforeCommit = resultWrites.at(-1) || [];
  for (const pending of commitResolvers) {
    pending.resolve({ code: 200, data: { saved_count: 20, persistence_status: 'readback_verified' } });
  }
  await flowPromise;

  assert.equal(commitResolvers.length, 4);
  assert.equal(observedBeforeCommit.filter(item => item.platformResponseReceived === true).length, 4);
  assert.ok(observedBeforeCommit.every(item => item.status === 'saving'));
  const presentation = meituanStaticApi.buildMeituanFetchPresentation(observedBeforeCommit);
  assert.equal(presentation.returnedCount, 4);
  assert.match(presentation.buttonText, /已返回4\/4榜/);
  assert.match(html, /result\.status === 'saving'.*正在保存并核对数据库/s);
});

test('Meituan complete ranking without a server-approved candidate fails visibly', async () => {
  let commitCalls = 0;
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      config_status: 'ready',
    }),
    requestFetch: async body => ({
      code: 200,
      data: {
        saved_count: 0,
        rank_candidate: null,
        rank_candidate_error: {
          reason: 'meituan_rank_candidate_invalid',
          message: 'Server rejected the target POI.',
        },
        data: {
          data: {
            peerRankData: [
              {
                dimName: `${body.rank_type}-A`,
                roundRanks: [{ poiId: 'self', dataValue: 10, percent: 50 }],
              },
              {
                dimName: `${body.rank_type}-B`,
                roundRanks: [{ poiId: 'self', dataValue: 20, percent: 60 }],
              },
            ],
          },
        },
        display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
      },
    }),
    requestCommit: async () => {
      commitCalls += 1;
      return { code: 200, data: { saved_count: 20 } };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(commitCalls, 0);
  assert.ok(result.results.every(item => item.status === 'exception'));
  assert.ok(result.results.every(item => /Server rejected the target POI/.test(item.message)));
});

test('Meituan stale ranking run cannot write UI state after the hotel changes', async () => {
  let active = true;
  let releaseRequests;
  const requestGate = new Promise(resolve => { releaseRequests = resolve; });
  const resultWrites = [];
  const fetchingWrites = [];
  const successWrites = [];
  let displayModelCalls = 0;
  const flowPromise = meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    isActive: () => active,
    requestFetch: async body => {
      await requestGate;
      const requiresAbsolute = ['P_RZ', 'P_XS'].includes(body.rank_type);
      return {
        code: 200,
        data: {
          saved_count: 0,
          data: {
            status: 0,
            data: {
              peerRankData: [
                { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: requiresAbsolute ? 0 : null, percent: 100 }] },
                { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: requiresAbsolute ? 80 : null, percent: 80 }] },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestDisplayModel: async () => {
      displayModelCalls += 1;
      return { code: 200, data: { display_hotels: [] } };
    },
    useDisplayModel: data => data.display_hotels || [],
    setOnlineDataResult: value => resultWrites.push(JSON.parse(JSON.stringify(value))),
    setFetching: value => fetchingWrites.push(value),
    setFetchSuccess: value => successWrites.push(value),
  });
  const initialResultWriteCount = resultWrites.length;

  active = false;
  releaseRequests();
  const result = await flowPromise;

  assert.equal(result.status, 'stale');
  assert.equal(resultWrites.length, initialResultWriteCount);
  assert.deepEqual(fetchingWrites, [true]);
  assert.deepEqual(successWrites, [false]);
  assert.equal(displayModelCalls, 0);
});

test('Meituan today ranking stops each rank task as soon as its data is complete', async () => {
  const requestCounts = new Map();
  const requestBodies = [];
  const makeResponse = (rankType, positive) => ({
    code: 200,
    data: {
      saved_count: 20,
      data: {
        status: 0,
        message: 'success',
        data: {
          peerRankData: [
            {
              aiMetricName: `${rankType}_A`,
              dimName: '榜单A',
              roundRanks: [
                {
                  poiId: 'self',
                  rank: 10,
                  dataValue: positive && ['P_RZ', 'P_XS'].includes(rankType) ? 0 : null,
                  percent: 0,
                },
                {
                  poiId: 'peer',
                  rank: 1,
                  dataValue: positive && ['P_RZ', 'P_XS'].includes(rankType) ? 100 : null,
                  percent: positive ? 100 : 0,
                },
              ],
            },
            {
              aiMetricName: `${rankType}_B`,
              dimName: '榜单B',
              roundRanks: [
                {
                  poiId: 'self',
                  rank: 10,
                  dataValue: positive && ['P_RZ', 'P_XS'].includes(rankType) ? 0 : null,
                  percent: 0,
                },
                {
                  poiId: 'peer',
                  rank: 1,
                  dataValue: positive && ['P_RZ', 'P_XS'].includes(rankType) ? 80 : null,
                  percent: positive ? 80 : 0,
                },
              ],
            },
          ],
        },
      },
      display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
      display_hotel_count: 2,
    },
  });

  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      requestBodies.push({ ...body });
      const count = (requestCounts.get(body.rank_type) || 0) + 1;
      requestCounts.set(body.rank_type, count);
      const completeAt = {
        P_RZ: 1,
        P_XS: 2,
        P_ZH: 1,
        P_LL: 2,
      };
      return makeResponse(body.rank_type, count >= completeAt[body.rank_type]);
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(result.status, 'success');
  assert.equal(requestCounts.get('P_RZ'), 1);
  assert.equal(requestCounts.get('P_XS'), 2);
  assert.equal(requestCounts.get('P_ZH'), 1);
  assert.equal(requestCounts.get('P_LL'), 2);
  const stayResult = result.results.find(item => item.rankType === 'P_RZ');
  assert.equal(stayResult.attemptCount, 1);
  assert.equal(stayResult.retryCount, 0);
  assert.equal(stayResult.maxAttempts, 3);
  assert.equal(stayResult.rankDataComplete, true);
  assert.equal(stayResult.retryExhausted, false);
  assert.equal(stayResult.data.data.peerRankData[0].roundRanks[1].percent, 100);
  const salesResult = result.results.find(item => item.rankType === 'P_XS');
  assert.equal(salesResult.attemptCount, 2);
  assert.equal(salesResult.maxAttempts, 3);
  assert.equal(salesResult.rankDataComplete, true);
  const conversionResult = result.results.find(item => item.rankType === 'P_ZH');
  assert.equal(conversionResult.attemptCount, 1);
  assert.equal(conversionResult.maxAttempts, 3);
  const trafficResult = result.results.find(item => item.rankType === 'P_LL');
  assert.equal(trafficResult.attemptCount, 2);
  assert.equal(trafficResult.maxAttempts, 3);
  const stayRequests = requestBodies.filter(body => body.rank_type === 'P_RZ');
  assert.equal(stayRequests[0].include_self_trade_metrics, true);
  stayRequests.slice(1).forEach(body => {
    assert.equal(body.include_self_trade_metrics, false);
    assert.equal(body.include_self_traffic_metrics, false);
    assert.equal(body.include_self_business_metrics, false);
  });
  assert.match(html, /result\.rankDataComplete/);
  assert.match(html, /result\.rankDataMode === 'derived'.*原始字段完整/s);
  assert.match(html, /result\.retryExhausted/);
  assert.match(html, /未抓到/);
});

test('Meituan today stay accepts server-approved derived data while sales stays strict', async () => {
  const requestCounts = new Map();
  const committed = [];
  const resultWrites = [];
  const normalRetryDelays = [];
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      requestCounts.set(body.rank_type, (requestCounts.get(body.rank_type) || 0) + 1);
      return {
        code: 200,
        data: {
          saved_count: 0,
          rank_candidate: body.rank_type === 'P_RZ'
            ? { candidate_id: 'P_RZ-derived', value_mode: 'derived', rank_type: 'P_RZ' }
            : (['P_ZH', 'P_LL'].includes(body.rank_type)
              ? { candidate_id: `${body.rank_type}-raw`, value_mode: 'raw', rank_type: body.rank_type }
              : null),
          data: {
            status: 0,
            data: {
              peerRankData: [
                {
                  aiMetricName: `${body.rank_type}_A`,
                  roundRanks: [{ poiId: 'peer', rank: 1, dataValue: null, percent: 100 }],
                },
                {
                  aiMetricName: `${body.rank_type}_B`,
                  roundRanks: [{ poiId: 'peer', rank: 1, dataValue: null, percent: 80 }],
                },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestCommit: async body => {
      committed.push({ ...body });
      return { code: 200, data: { saved_count: 22, persistence_status: 'readback_verified' } };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
    setOnlineDataResult: value => resultWrites.push(JSON.parse(JSON.stringify(value))),
    waitForRetry: async delayMs => { normalRetryDelays.push(delayMs); },
  });

  assert.equal(requestCounts.get('P_RZ'), 1);
  assert.equal(requestCounts.get('P_XS'), 3);
  assert.equal(requestCounts.get('P_ZH'), 1);
  assert.equal(requestCounts.get('P_LL'), 1);
  const initialStay = resultWrites.find(Array.isArray)?.find(item => item.rankType === 'P_RZ');
  assert.equal(initialStay?.attemptCount, 0);
  assert.equal(initialStay?.maxAttempts, 3);
  assert.equal(initialStay?.dateRangeName, '今日实时');
  const stayResult = result.results.find(item => item.rankType === 'P_RZ');
  assert.equal(stayResult.rankDataComplete, true);
  assert.equal(stayResult.rankDataMode, 'derived');
  assert.equal(stayResult.attemptCount, 1);
  assert.equal(stayResult.maxAttempts, 3);
  assert.equal(stayResult.savedCount, 22);
  const salesResult = result.results.find(item => item.rankType === 'P_XS');
  assert.equal(salesResult.rankDataComplete, false);
  assert.equal(salesResult.retryExhausted, true);
  assert.equal(salesResult.attemptCount, 3);
  assert.equal(salesResult.maxAttempts, 3);
  const salesProgress = resultWrites
    .flatMap(value => Array.isArray(value) ? value : [])
    .filter(item => item.rankType === 'P_XS' && item.status === 'fetching' && item.attemptCount > 0);
  assert.equal(salesProgress.at(-1)?.attemptCount, 3);
  assert.equal(salesProgress.at(-1)?.maxAttempts, 3);
  assert.equal(committed.some(item => item.value_mode === 'derived'), true);
  assert.match(html, /isMeituanPendingResult\(result\).*result\.attemptCount.*result\.maxAttempts/s);
  assert.match(html, /result\.maxAttempts.*等待第 1 轮返回/s);
  assert.match(html, /每日9点更新前日数据。数据仅作经营参考，不作结算依据。/);
  assert.doesNotMatch(html, /“今日实时”是美团页面筛选名称，不代表秒级实时/);
  assert.doesNotMatch(html, /其后台对这两列也只显名次、不显具体数字/);
  assert.match(html, /rankDataMode === 'derived'.*平台原值缺失.*本店真实值和平台百分比计算/s);
  assert.match(meituanStatic, /平台仅返回百分比，已按本店真实值和平台百分比计算/);
  assert.doesNotMatch(meituanStatic, /同行数值未开放/);
  assert.equal(normalRetryDelays.length, 0);
  assert.equal(result.status, 'partial');
});

test('Meituan fetch presentation exposes live rounds and truthful partial health', () => {
  const progress = meituanStaticApi.buildMeituanFetchPresentation([
    { rankType: 'P_RZ', status: 'fetching', attemptCount: 0, maxAttempts: 1, rankDataComplete: false },
    { rankType: 'P_XS', status: 'fetching', attemptCount: 2, maxAttempts: 3, rankDataComplete: false },
    { rankType: 'P_ZH', status: 'success', attemptCount: 1, maxAttempts: 3, rankDataComplete: true },
    { rankType: 'P_LL', status: 'success', attemptCount: 1, maxAttempts: 3, rankDataComplete: true },
  ]);
  assert.equal(progress.inProgress, true);
  assert.equal(progress.completedCount, 2);
  assert.equal(progress.returnedCount, 2);
  assert.equal(progress.totalCount, 4);
  assert.equal(progress.buttonText, '获取中 · 已返回2/4榜 · 第2/3轮');

  const partialResults = [
    { rankType: 'P_RZ', status: 'success', attemptCount: 1, maxAttempts: 1, rankDataComplete: true, rankDataMode: 'self_only' },
    { rankType: 'P_XS', status: 'incomplete', attemptCount: 3, maxAttempts: 3, rankDataComplete: false, retryExhausted: true, error: '未完整' },
    { rankType: 'P_ZH', status: 'success', attemptCount: 1, maxAttempts: 3, rankDataComplete: true },
    { rankType: 'P_LL', status: 'success', attemptCount: 1, maxAttempts: 3, rankDataComplete: true },
  ];
  const partial = meituanStaticApi.buildMeituanFetchPresentation(partialResults);
  assert.equal(partial.inProgress, false);
  assert.equal(partial.hasErrors, true);
  assert.equal(partial.isPartial, true);
  assert.equal(partial.completedCount, 3);
  assert.equal(partial.selfOnlyCount, 1);

  const cards = meituanStaticApi.applyMeituanFetchHealthToCards([
    { key: 'rankHealth', label: '榜单健康度', value: '4/4', level: '四类榜单' },
    { key: 'hotelCount', label: '酒店总数', value: '10' },
  ], partial);
  assert.equal(cards[0].value, '3/4');
  assert.equal(cards[0].level, '本次部分返回');
  assert.equal(cards[1].value, '10');

  const insights = meituanStaticApi.applyMeituanFetchHealthToCards([
    { key: 'rank-health', label: '榜单健康度', value: '4/4', note: '四类榜单均有返回', className: 'old' },
  ], partial);
  assert.equal(insights[0].value, '3/4');
  assert.equal(insights[0].note, '本次 3/4 类榜单可用，其余保持缺失');
  assert.match(insights[0].className, /amber/);

  const healthRows = meituanStaticApi.applyMeituanFetchHealthToRows([
    { key: 'P_RZ', label: '入住榜', status: 'ok', statusText: '已返回' },
    { key: 'P_XS', label: '销售榜', status: 'ok', statusText: '已返回' },
    { key: 'P_LL', label: '流量榜', status: 'ok', statusText: '已返回' },
    { key: 'P_ZH', label: '转化榜', status: 'ok', statusText: '已返回' },
  ], partialResults);
  assert.deepEqual(
    JSON.parse(JSON.stringify(healthRows.map(row => [row.key, row.status, row.statusText]))),
    [
      ['P_RZ', 'ok', '本店实时值可用'],
      ['P_XS', 'missing', '未抓到'],
      ['P_LL', 'ok', '原始完整'],
      ['P_ZH', 'ok', '原始完整'],
    ]
  );
  assert.match(html, /\{\{ meituanFetchButtonText \}\}/);
  assert.match(html, /meituanFetchPartial \? '数据获取部分完成'/);
});

test('Meituan retry preserves a non-missing self metric status from an earlier attempt', async () => {
  const requestCounts = new Map();
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      const count = (requestCounts.get(body.rank_type) || 0) + 1;
      requestCounts.set(body.rank_type, count);
      const needsAbsoluteValues = ['P_RZ', 'P_XS'].includes(body.rank_type);
      const absoluteReady = body.rank_type !== 'P_XS' || count >= 2;
      return {
        code: 200,
        data: {
          saved_count: 20,
          self_metric_values: body.rank_type === 'P_XS' && count === 1
            ? { salesRoomNights: 3, sales: 900 }
            : {},
          self_metric_status: body.rank_type === 'P_XS' && count === 1 ? 'trade_returned' : 'missing',
          data: {
            status: 0,
            data: {
              peerRankData: [
                {
                  aiMetricName: `${body.rank_type}_A`,
                  roundRanks: [{
                    poiId: 'peer',
                    rank: 1,
                    dataValue: needsAbsoluteValues && absoluteReady ? 100 : null,
                    percent: 100,
                  }],
                },
                {
                  aiMetricName: `${body.rank_type}_B`,
                  roundRanks: [{
                    poiId: 'peer',
                    rank: 1,
                    dataValue: needsAbsoluteValues && absoluteReady ? 80 : null,
                    percent: 80,
                  }],
                },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  const salesResult = result.results.find(item => item.rankType === 'P_XS');
  assert.equal(salesResult.attemptCount, 2);
  assert.equal(salesResult.rankDataComplete, true);
  assert.equal(salesResult.selfMetricStatus, 'trade_returned');
  assert.equal(salesResult.selfMetricValues.salesRoomNights, 3);
  assert.equal(salesResult.selfMetricValues.sales, 900);
});

test('Meituan today ranking retries transient platform errors with spacing', async () => {
  const requestCounts = new Map();
  const retryDelays = [];
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      const count = (requestCounts.get(body.rank_type) || 0) + 1;
      requestCounts.set(body.rank_type, count);
      if (body.rank_type === 'P_XS' && count === 1) {
        throw new Error('请求失败: 美团API返回错误: 状态码: 33202');
      }
      const requiresAbsolute = ['P_RZ', 'P_XS'].includes(body.rank_type);
      return {
        code: 200,
        data: {
          saved_count: 20,
          data: {
            status: 0,
            data: {
              peerRankData: [
                {
                  aiMetricName: `${body.rank_type}_A`,
                  roundRanks: [{ poiId: 'peer', rank: 1, dataValue: requiresAbsolute ? 100 : null, percent: 100 }],
                },
                {
                  aiMetricName: `${body.rank_type}_B`,
                  roundRanks: [{ poiId: 'peer', rank: 1, dataValue: requiresAbsolute ? 80 : null, percent: 80 }],
                },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    waitForRetry: async delayMs => { retryDelays.push(delayMs); },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(requestCounts.get('P_XS'), 2);
  assert.equal(result.results.find(item => item.rankType === 'P_XS')?.rankDataComplete, true);
  assert.equal(retryDelays.length, 1);
  assert.ok(retryDelays[0] >= 500);
});

test('Meituan today sales ranking stops after 3 incomplete attempts and reports not captured', async () => {
  const requestCounts = new Map();
  const makeResponse = (rankType, positive) => ({
    code: 200,
    data: {
      saved_count: 20,
      data: {
        status: 0,
        data: {
          peerRankData: [
            {
              aiMetricName: `${rankType}_A`,
              dimName: '榜单A',
              roundRanks: [{
                poiId: 'peer',
                rank: 1,
                dataValue: positive && rankType === 'P_RZ' ? 100 : null,
                percent: positive ? 100 : 0,
              }],
            },
            {
              aiMetricName: `${rankType}_B`,
              dimName: '榜单B',
              roundRanks: [{
                poiId: 'peer',
                rank: 1,
                dataValue: positive && rankType === 'P_RZ' ? 80 : null,
                percent: positive ? 80 : 0,
              }],
            },
          ],
        },
      },
      display_hotels: [],
    },
  });

  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['0'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      requestCounts.set(body.rank_type, (requestCounts.get(body.rank_type) || 0) + 1);
      return makeResponse(body.rank_type, body.rank_type !== 'P_XS');
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(requestCounts.get('P_RZ'), 1);
  assert.equal(requestCounts.get('P_XS'), 3);
  assert.equal(requestCounts.get('P_ZH'), 1);
  assert.equal(requestCounts.get('P_LL'), 1);
  const salesResult = result.results.find(item => item.rankType === 'P_XS');
  assert.equal(salesResult.attemptCount, 3);
  assert.equal(salesResult.rankDataComplete, false);
  assert.equal(salesResult.retryExhausted, true);
  assert.match(salesResult.error, /3.*未抓到/);
  assert.equal(result.status, 'partial');
});

test('Meituan historical ranking stops each task when complete within three attempts', async () => {
  const requestCounts = new Map();
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['1'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      const count = (requestCounts.get(body.rank_type) || 0) + 1;
      requestCounts.set(body.rank_type, count);
      const completeAt = {
        P_RZ: 2,
        P_XS: 2,
        P_ZH: 1,
        P_LL: 3,
      };
      const positive = count >= completeAt[body.rank_type];
      return {
        code: 200,
        data: {
          saved_count: 20,
          data: {
            status: 0,
            data: {
              peerRankData: [
                { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'peer', rank: 1, dataValue: positive ? 100 : null, percent: positive ? 100 : 0 }] },
                { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'peer', rank: 1, dataValue: positive ? 80 : null, percent: positive ? 80 : 0 }] },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(result.status, 'success');
  assert.equal(requestCounts.get('P_RZ'), 2);
  assert.equal(requestCounts.get('P_XS'), 2);
  assert.equal(requestCounts.get('P_ZH'), 1);
  assert.equal(requestCounts.get('P_LL'), 3);
  const stayResult = result.results.find(item => item.rankType === 'P_RZ');
  assert.equal(stayResult.maxAttempts, 3);
  assert.equal(stayResult.attemptCount, 2);
  assert.equal(stayResult.rankDataComplete, true);
  const trafficResult = result.results.find(item => item.rankType === 'P_LL');
  assert.equal(trafficResult.maxAttempts, 3);
  assert.equal(trafficResult.attemptCount, 3);
  assert.equal(trafficResult.rankDataComplete, true);
  assert.equal(trafficResult.retryExhausted, false);
  assert.match(html, /result\.rankDataMode === 'derived'.*原始字段完整/s);
  assert.match(html, /result\.retryExhausted.*未抓到/s);
});

test('Meituan historical percent-only stay and sales save as derived when the server approves self anchors', async () => {
  const requestCounts = new Map();
  const committed = [];
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['1'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      requestCounts.set(body.rank_type, (requestCounts.get(body.rank_type) || 0) + 1);
      return {
        code: 200,
        data: {
          saved_count: 0,
          rank_candidate: {
            candidate_id: `${body.rank_type}-derived-candidate`,
            value_mode: ['P_RZ', 'P_XS'].includes(body.rank_type) ? 'derived' : 'raw',
            rank_type: body.rank_type,
            date_range: body.date_range,
          },
          self_metric_values: {
            roomNights: 18,
            roomRevenue: 1588,
            salesRoomNights: 22,
            sales: 1956,
          },
          self_metric_status: 'trade_returned',
          data: {
            status: 0,
            data: {
              peerRankData: [
                { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: null, percent: 100 }] },
                { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: null, percent: 80 }] },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestCommit: async body => {
      committed.push({ ...body });
      return { code: 200, data: { saved_count: 22, persistence_status: 'readback_verified' } };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel' }] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(requestCounts.get('P_RZ'), 1);
  assert.equal(requestCounts.get('P_XS'), 1);
  assert.equal(requestCounts.get('P_ZH'), 1);
  assert.equal(requestCounts.get('P_LL'), 1);
  assert.equal(result.status, 'success');
  assert.equal(result.totalSavedCount, 88);
  assert.equal(committed.length, 4);
  for (const rankType of ['P_RZ', 'P_XS']) {
    const item = result.results.find(row => row.rankType === rankType);
    assert.equal(item.rankDataComplete, true);
    assert.equal(item.rankDataMode, 'derived');
    assert.equal(item.terminalPartial, undefined);
    assert.equal(item.savedCount, 22);
  }
  for (const rankType of ['P_ZH', 'P_LL']) {
    assert.equal(result.results.find(row => row.rankType === rankType)?.rankDataComplete, true);
  }
  const presentation = meituanStaticApi.buildMeituanFetchPresentation(result.results);
  assert.equal(presentation.completedCount, 4);
  assert.equal(presentation.derivedCount, 2);
  const salesRequest = committed.find(row => row.rank_type === 'P_XS');
  assert.equal(salesRequest?.value_mode, 'derived');
  const tasks = meituanStaticApi.buildMeituanBatchFetchTasks({
    form: { hotelId: 58, dateRanges: ['7'] },
    configId: 'meituan-58',
  });
  assert.equal(tasks.find(task => task.rankType === 'P_XS')?.body.include_self_trade_metrics, true);
  assert.match(html, /result\.rankDataMode === 'derived'.*本店真实值和平台百分比计算/s);
});

test('Meituan historical percent-only stay and sales remain partial without approved self anchors', async () => {
  const requestCounts = new Map();
  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({ hotelId: 58, poiId: 'self', dateRanges: ['1'] }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async body => {
      requestCounts.set(body.rank_type, (requestCounts.get(body.rank_type) || 0) + 1);
      return {
        code: 200,
        data: {
          saved_count: 0,
          rank_candidate: ['P_ZH', 'P_LL'].includes(body.rank_type)
            ? { candidate_id: `${body.rank_type}-candidate`, value_mode: 'raw', rank_type: body.rank_type }
            : null,
          data: {
            status: 0,
            data: {
              peerRankData: [
                { aiMetricName: `${body.rank_type}_A`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: null, percent: 100 }] },
                { aiMetricName: `${body.rank_type}_B`, roundRanks: [{ poiId: 'self', rank: 1, dataValue: null, percent: 80 }] },
              ],
            },
          },
          display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel', isSelf: true }],
        },
      };
    },
    requestDisplayModel: async () => ({ code: 200, data: { display_hotels: [{ poiId: 'self', hotelName: 'Self Hotel' }] } }),
    useDisplayModel: data => data.display_hotels || [],
  });

  assert.equal(requestCounts.get('P_RZ'), 3);
  assert.equal(requestCounts.get('P_XS'), 3);
  assert.equal(result.status, 'partial');
  for (const rankType of ['P_RZ', 'P_XS']) {
    const item = result.results.find(row => row.rankType === rankType);
    assert.equal(item.rankDataComplete, false);
    assert.equal(item.retryExhausted, true);
    assert.equal(item.attemptCount, 3);
    assert.equal(item.maxAttempts, 3);
    assert.match(item.message, /已尝试 3 轮.*本店真实值锚点不足.*未抓到/);
  }
  assert.match(html, /未抓到 · 已尝试/);
});

test('Ctrip identity conflict still displays queried rows and reports save blocked', async () => {
  const notifications = [];
  const onlineResults = [];
  const displayedRows = [];
  const fetchSuccessWrites = [];
  const savedCountWrites = [];
  const form = {
    url: 'https://ebooking.ctrip.com/api/report',
    nodeId: '24588',
    startDate: '2026-07-10',
    endDate: '2026-07-10',
  };

  const result = await ctripStaticApi.runCtripFetchDataFlow({
    isLoggedIn: () => true,
    getSelectedCtripHotelId: () => 64,
    getActiveCtripConfig: () => ({
      id: 'ctrip-64',
      config_id: 'ctrip-64',
      hotel_id: 64,
      system_hotel_id: 64,
      node_id: '24588',
      credential_status: 'ready',
      has_cookies: true,
      configuration_verified: true,
    }),
    getForm: () => form,
    requestFetch: async () => ({
      code: 200,
      message: '数据已获取，但当前配置实际返回另一家酒店，本次未入库。',
      data: {
        saved_count: 0,
        save_status: 'blocked',
        display_hotel_count: 1,
        display_hotels: [{ hotelId: '11537833', hotelName: '古镇江景' }],
        identity_check: { status: 'platform_hotel_conflict' },
      },
    }),
    notify: (message, level) => notifications.push({ message, level }),
    setOnlineDataResult: value => onlineResults.push(value),
    useDisplayHotels: rows => {
      displayedRows.push(...rows);
      return rows;
    },
    setFetchSuccess: value => fetchSuccessWrites.push(value),
    setSavedCount: value => savedCountWrites.push(value),
  });

  assert.equal(result.status, 'display_only');
  assert.equal(displayedRows[0]?.hotelName, '古镇江景');
  assert.equal(onlineResults.length, 1);
  assert.equal(fetchSuccessWrites.at(-1), true);
  assert.equal(savedCountWrites.at(-1), 0);
  assert.deepEqual(notifications.at(-1), {
    message: '数据已获取，但当前配置实际返回另一家酒店，本次未入库。',
    level: 'warning',
  });
});

test('Meituan ranking keeps rank summary on the second screen like Ctrip', () => {
  const beforeMainTable = sliceFrom('<!-- backend display summary -->', '<!-- 竞对排名表格 -->');
  const firstTable = sliceFrom('<!-- 竞对排名表格 -->', 'data-testid="meituan-rank-summary-second-screen"');
  const secondScreen = sliceFrom('data-testid="meituan-rank-summary-second-screen"', '<!-- 流量数据获取 -->');

  assert.doesNotMatch(beforeMainTable, /meituanVisibleRankInsightCards/);
  assert.doesNotMatch(beforeMainTable, /榜单与来源状态/);
  assert.doesNotMatch(firstTable, /rowspan="2">排名摘要/);
  assert.match(secondScreen, /排名摘要/);
  assert.match(secondScreen, /data-testid="meituan-rank-source-second-screen"/);
  assert.match(secondScreen, /meituanVisibleRankInsightCards/);
  assert.match(secondScreen, /榜单与来源状态/);
  assert.match(secondScreen, /v-for="\(\s*hotel,\s*index\s*\) in pagedMeituanHotelsList"/);
  assert.match(secondScreen, /hotel\.circlePositionText/);
  assert.match(secondScreen, /hotel\.gapToLeaderText/);
  assert.match(secondScreen, /hotel\.rankSummaryText/);
});

test('Meituan ranking money cells use backend source prefixes', () => {
  const rankingTable = sliceFrom('<!-- 竞对排名表格 -->', 'data-testid="meituan-rank-summary-second-screen"');
  const displayPayload = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanDisplayModelPayload = ({'),
    meituanStatic.indexOf('const normalizeMeituanCookieText')
  );
  const fetchTasks = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanBatchFetchTasks = ({'),
    meituanStatic.indexOf('const buildMeituanBatchFetchResultEntry')
  );

  assert.doesNotMatch(rankingTable, /'¥'\s*\+\s*hotel\.roomRevenueText/);
  assert.doesNotMatch(rankingTable, /'¥'\s*\+\s*hotel\.salesText/);
  assert.match(rankingTable, /\(hotel\.roomRevenuePrefix \|\| ''\) \+ hotel\.roomRevenueText/);
  assert.match(rankingTable, /\(hotel\.salesPrefix \|\| ''\) \+ hotel\.salesText/);
  assert.match(rankingTable, /\(hotel\.exposurePrefix \|\| ''\) \+ hotel\.exposureText/);
  assert.match(rankingTable, /\(hotel\.viewsPrefix \|\| ''\) \+ hotel\.viewsText/);
  assert.match(displayPayload, /const displayGroups = buildMeituanDisplayModelGroups/);
  assert.match(displayPayload, /display_hotels:\s*displayGroups\.length > 0 \? \[\] : buildMeituanDisplayModelRows/);
  assert.match(displayPayload, /display_groups:\s*displayGroups/);
  assert.match(fetchTasks, /const includeSelfMetrics = rankIndex === 0;/);
  assert.match(fetchTasks, /const includeSelfTradeMetrics = \['P_RZ', 'P_XS'\]\.includes\(rankType\);/);
  assert.match(fetchTasks, /include_self_trade_metrics:\s*includeSelfTradeMetrics/);
  assert.match(fetchTasks, /include_self_traffic_metrics:\s*includeSelfMetrics/);
  assert.match(fetchTasks, /include_self_business_metrics:\s*includeSelfMetrics/);
});

test('Meituan batch fetch requests trade anchors for stay and sales while sharing other supplements', () => {
  const tasks = meituanStaticApi.buildMeituanBatchFetchTasks({
    form: {
      url: 'https://eb.meituan.com/api/v1/ebooking/data/rank',
      hotelId: 58,
      dateRanges: ['1', '7', 'custom'],
      startDate: '2026-06-01',
      endDate: '2026-06-03',
    },
    partnerId: '4517495',
    poiId: '1022727174',
    configId: 'meituan-58',
  });

  assert.equal(tasks.length, 12);
  ['1', '7', 'custom'].forEach(dateRange => {
    const rangeTasks = tasks.filter(task => task.dateRange === dateRange);
    assert.equal(rangeTasks.length, 4);
    assert.equal(rangeTasks.filter(task => task.body.include_self_trade_metrics === true).length, 2);
    assert.equal(rangeTasks.filter(task => task.body.include_self_traffic_metrics === true).length, 1);
    assert.equal(rangeTasks.filter(task => task.body.include_self_business_metrics === true).length, 1);
    assert.deepEqual(
      JSON.parse(JSON.stringify(rangeTasks.filter(task => task.body.include_self_trade_metrics === true).map(task => task.rankType))),
      ['P_RZ', 'P_XS']
    );
    assert.equal(rangeTasks.filter(task => task.body.include_self_trade_metrics === false).length, 2);
  });
});

test('Meituan ranking execution sends only the saved config locator and report selectors', () => {
  const validation = meituanStaticApi.validateMeituanBatchFetchInput({
    form: { hotelId: 58, dateRanges: ['1'] },
    configId: 'meituan-58',
  });
  const tasks = meituanStaticApi.buildMeituanBatchFetchTasks({
    form: {
      hotelId: 58,
      url: 'https://eb.meituan.com/api/v1/ebooking/data/rank',
      dateRanges: ['1'],
    },
    configId: 'meituan-58',
    partnerId: '4517495',
    poiId: '1022727174',
  });

  assert.equal(validation.ok, true);
  assert.equal(meituanStaticApi.isMeituanRankingFormAlignedWithConfig(
    { hotelId: 58 },
    {
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }
  ), true);
  tasks.forEach(task => {
    assert.equal(task.body.config_id, 'meituan-58');
    assert.equal(task.body.system_hotel_id, 58);
    assert.equal(Object.hasOwn(task.body, 'partner_id'), false);
    assert.equal(Object.hasOwn(task.body, 'poi_id'), false);
    assert.equal(Object.hasOwn(task.body, 'url'), false);
  });
});

test('Meituan ranking rejects future custom dates before platform requests', () => {
  const validation = meituanStaticApi.validateMeituanBatchFetchInput({
    form: {
      hotelId: 58,
      dateRanges: ['custom'],
      startDate: '2999-01-01',
      endDate: '2999-01-02',
    },
    partnerId: '4517495',
    poiId: '1022727174',
    configId: 'meituan-58',
  });

  assert.equal(validation.ok, false);
  assert.equal(validation.level, 'warning');
  assert.match(validation.message, /不支持未来日期/);
});

test('Meituan ranking accepts only one period per fetch', () => {
  const validation = meituanStaticApi.validateMeituanBatchFetchInput({
    form: { hotelId: 58, dateRanges: ['1', '7'] },
    configId: 'meituan-58',
  });

  assert.equal(validation.ok, false);
  assert.equal(validation.level, 'warning');
  assert.match(validation.message, /每次只获取一个时间周期/);
});

test('Meituan batch fetch stops display model when every rank request needs login', async () => {
  const notifications = [];
  const fetchSuccessWrites = [];
  const businessSummaryWrites = [];
  let displayModelCalled = false;

  const result = await meituanStaticApi.runMeituanBatchFetchFlow({
    getForm: () => ({
      url: 'https://eb.meituan.com/api/v1/ebooking/data/rank',
      hotelId: 58,
      partnerId: '4517495',
      poiId: '1022727174',
      dateRanges: ['1'],
      cookies: 'token=expired',
    }),
    getSelectedConfig: () => ({
      id: 'meituan-58',
      config_id: 'meituan-58',
      hotel_id: 58,
      partner_id: '4517495',
      poi_id: '1022727174',
      has_cookies: true,
      credential_status: 'ready',
      configuration_verified: true,
    }),
    requestFetch: async () => ({
      code: 400,
      message: '请求失败',
      data: {
        reason: 'login_required',
        credential_status: 'login_required',
        business_code: 303,
        business_message: '您尚未登录',
      },
    }),
    requestDisplayModel: async () => {
      displayModelCalled = true;
      return { code: 200, data: {} };
    },
    notify: (message, level) => notifications.push({ message, level }),
    setFetchSuccess: value => fetchSuccessWrites.push(value),
    setBusinessSummary: value => businessSummaryWrites.push(value),
  });

  assert.equal(result.status, 'login_required');
  assert.equal(displayModelCalled, false);
  assert.equal(notifications.at(-1).level, 'error');
  assert.match(notifications.at(-1).message, /Cookie\/API/);
  assert.deepEqual(fetchSuccessWrites.slice(-1), [false]);
  assert.equal(businessSummaryWrites.length, 1);
  assert.deepEqual(Object.keys(businessSummaryWrites[0]), []);
});

test('Meituan display model keeps self metric anchors scoped by date range', () => {
  const payload = meituanStaticApi.buildMeituanDisplayModelPayload({
    form: {
      hotelId: 58,
      poiId: 'SELF',
      dateRanges: ['7', '30'],
      selfMetricValues: {},
    },
    results: [
      {
        dateRange: '7',
        displayHotels: [{ poiId: 'SELF', hotelName: 'Self Hotel', dateRange: '7' }],
        selfMetricValues: { exposure: 700, salesRoomNights: 70 },
      },
      {
        dateRange: '7',
        displayHotels: [{ poiId: 'RIVAL', hotelName: 'Rival Hotel', dateRange: '7' }],
        selfMetricValues: { exposure: 0, salesRoomNights: 0 },
      },
      {
        dateRange: '30',
        displayHotels: [{ poiId: 'SELF', hotelName: 'Self Hotel', dateRange: '30' }],
        selfMetricValues: { exposure: 3000, salesRoomNights: 300 },
      },
    ],
  });

  assert.equal(JSON.stringify(payload.display_groups.map(item => item.date_range)), JSON.stringify(['7', '30']));
  assert.equal(payload.system_hotel_id, 58);
  assert.equal(payload.display_hotels.length, 0);
  assert.equal(JSON.stringify(payload.display_groups[0].self_metric_values), JSON.stringify({ exposure: 700, salesRoomNights: 70 }));
  assert.equal(JSON.stringify(payload.display_groups[1].self_metric_values), JSON.stringify({ exposure: 3000, salesRoomNights: 300 }));
  assert.equal(payload.self_metric_values, undefined);
});

test('Meituan config saves cookie-only and no longer treats room counts as credentials', () => {
  const saveMeituanConfigItem = functionSlice('saveMeituanConfigItem');
  const meituanStaticFallback = constSlice(
    'const meituanStaticFallbackFor = (key) => {',
    '\n            const requireMeituanStatic = (key) => {'
  );
  const returnToMeituanRankingAfterConfigSave = constSlice(
    'const returnToMeituanRankingAfterConfigSave = async (hotelId) => {',
    '\n\n            let manualOnlineFetchConfigReadyPromise'
  );
  const useMeituanConfig = constSlice(
    'const useMeituanConfig = async (config) => {',
    '\n\n            const editMeituanConfig'
  );
  const editMeituanConfig = constSlice(
    'const editMeituanConfig = async (config) => {',
    '\n\n            const deleteMeituanConfigItem'
  );
  const deleteMeituanConfigItem = constSlice(
    'const deleteMeituanConfigItem = async (id) => {',
    '\n\n            const generateMeituanBookmarklet'
  );
  const generateMeituanBookmarklet = constSlice(
    'const generateMeituanBookmarklet = async () => {',
    '\n\n            const fetchCustomData'
  );

  assert.match(html, /const meituanConfigSaveHelperKeys = Object\.freeze\(\[/);
  assert.match(html, /const resolveMeituanStaticHelperAvailability = \(keys = \[\]\) => \{/);
  assert.match(saveMeituanConfigItem, /const helperAvailability = resolveMeituanStaticHelperAvailability\(meituanConfigSaveHelperKeys\);/);
  assert.match(saveMeituanConfigItem, /if \(!helperAvailability\.available\) \{/);
  assert.match(saveMeituanConfigItem, /本次未发送请求/);
  assert.ok(
    saveMeituanConfigItem.indexOf('if (!helperAvailability.available) {')
      < saveMeituanConfigItem.indexOf("request('/online-data/save-meituan-config-item', {")
  );
  assert.doesNotMatch(meituanStaticFallback, /if \(key === '(?:resolveMeituanConfigSaveCookieState|buildMeituanConfigAutoName|buildMeituanConfigSaveRequestBody|buildMeituanConfigSaveSuccessState|buildMeituanConfigSaveFailureState)'\)/);
  assert.match(saveMeituanConfigItem, /const cookieState = resolveMeituanConfigSaveCookieState\(meituanConfigForm\.value\.cookies, \{/);
  assert.match(saveMeituanConfigItem, /keepExisting: Boolean\(meituanConfigForm\.value\.id\)/);
  assert.match(saveMeituanConfigItem, /meituanConfigForm\.value\.has_cookies === true/);
  assert.doesNotMatch(saveMeituanConfigItem, /credential_status/);
  assert.match(saveMeituanConfigItem, /if \(!cookieState\.canSave\) \{/);
  assert.match(saveMeituanConfigItem, /showToast\(cookieState\.message, cookieState\.level\);/);
  assert.doesNotMatch(saveMeituanConfigItem, /String\(meituanConfigForm\.value\.cookies \|\| ''\)\.trim\(\)/);
  assert.match(html, /const buildMeituanConfigAutoName = requireMeituanStatic\('buildMeituanConfigAutoName'\);/);
  assert.match(html, /const buildMeituanConfigSaveRequestBody = requireMeituanStatic\('buildMeituanConfigSaveRequestBody'\);/);
  assert.match(html, /const resolveMeituanConfigSaveCookieState = requireMeituanStatic\('resolveMeituanConfigSaveCookieState'\);/);
  assert.match(html, /const resolveMeituanConfigSaveRequestHotelId = requireMeituanStatic\('resolveMeituanConfigSaveRequestHotelId'\);/);
  assert.match(html, /const createEmptyMeituanConfigForm = requireMeituanStatic\('createEmptyMeituanConfigForm'\);/);
  assert.match(html, /const buildMeituanConfigDeleteUrl = requireMeituanStatic\('buildMeituanConfigDeleteUrl'\);/);
  assert.match(html, /const buildMeituanConfigDeleteSuccessState = requireMeituanStatic\('buildMeituanConfigDeleteSuccessState'\);/);
  assert.match(html, /const buildMeituanConfigDeleteFailureState = requireMeituanStatic\('buildMeituanConfigDeleteFailureState'\);/);
  assert.match(html, /const meituanConfigForm = ref\(createEmptyMeituanConfigForm\(\)\);/);
  assert.match(html, /const buildMeituanConfigSaveSuccessState = requireMeituanStatic\('buildMeituanConfigSaveSuccessState'\);/);
  assert.match(html, /const buildMeituanConfigSaveFailureState = requireMeituanStatic\('buildMeituanConfigSaveFailureState'\);/);
  assert.match(html, /const buildMeituanConfigUseState = requireMeituanStatic\('buildMeituanConfigUseState'\);/);
  assert.doesNotMatch(html, /const buildMeituanRankingFormPatchFromConfig = requireMeituanStatic\('buildMeituanRankingFormPatchFromConfig'\);/);
  assert.match(html, /const buildMeituanConfigEditState = requireMeituanStatic\('buildMeituanConfigEditState'\);/);
  assert.doesNotMatch(html, /const buildMeituanConfigEditForm = requireMeituanStatic\('buildMeituanConfigEditForm'\);/);
  assert.match(html, /const buildMeituanBookmarkletSuccessState = requireMeituanStatic\('buildMeituanBookmarkletSuccessState'\);/);
  assert.match(html, /const buildMeituanBookmarkletFailureState = requireMeituanStatic\('buildMeituanBookmarkletFailureState'\);/);
  assert.match(meituanStatic, /const resolveMeituanConfigSaveCookieState = \(cookies = '', options = \{\}\) => \{/);
  assert.match(meituanStatic, /const buildMeituanConfigAutoName = \(form = \{\}, options = \{\}\) =>/);
  assert.match(meituanStatic, /const buildMeituanConfigSaveRequestBody = \(\{/);
  assert.match(meituanStatic, /const resolveMeituanConfigSaveRequestHotelId = \(\{/);
  assert.match(meituanStatic, /const createEmptyMeituanConfigForm = \(\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigDeleteUrl = \(id = ''\) => \{/);
  assert.match(meituanStatic, /const buildMeituanConfigDeleteSuccessState = \(id = ''\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigDeleteFailureState = \(\{/);
  assert.match(meituanStatic, /const resolveSavedMeituanConfigHotelId = \(\{/);
  assert.match(meituanStatic, /const resolveMeituanConfigSaveToastLevel = \(responseData = \{\}\) =>/);
  assert.match(meituanStatic, /const buildMeituanConfigSaveSuccessState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigSaveFailureState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanRankingFormPatchFromConfig = \(config = \{\}, fallbackHotelId = ''\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigUseState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigEditForm = \(config = \{\}\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanConfigEditState = \(\{/);
  assert.match(meituanStatic, /const buildMeituanBookmarkletSuccessState = \(response = \{\}\) => \(\{/);
  assert.match(meituanStatic, /const buildMeituanBookmarkletFailureState = \(\{/);
  assert.equal(meituanStaticApi.buildMeituanConfigAutoName({ name: ' 自定义配置 ' }), '自定义配置');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState(' cookie=value ').canSave, true);
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState(' cookie=value ').cookies, 'cookie=value');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState('').canSave, false);
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState('').cookies, '');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState('').level, 'error');
  assert.match(meituanStaticApi.resolveMeituanConfigSaveCookieState('').message, /Cookie\/API/);
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState('', { keepExisting: true }).canSave, true);
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveCookieState('', { keepExisting: true }).keepExisting, true);
  assert.equal(meituanStaticApi.buildMeituanConfigAutoName({}, { hotelName: '湖滨店', fallbackDate: '2026-07-08' }), '湖滨店美团Cookie');
  assert.equal(meituanStaticApi.buildMeituanConfigAutoName({ poi_id: '12345' }, { fallbackDate: '2026-07-08' }), '美团12345Cookie');
  assert.equal(meituanStaticApi.buildMeituanConfigAutoName({}, { fallbackDate: '2026-07-08' }), '美团Cookie 2026-07-08');
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigSaveRequestBody({
    form: {
      id: 7,
      partner_id: ' partner ',
      poi_id: 'poi',
      hotel_room_count: '88',
      competitor_room_count: '188',
    },
    requestHotelId: ' 58 ',
    name: ' 配置名 ',
    cookies: ' cookie=value ',
  })), JSON.stringify({
    id: 7,
    name: '配置名',
    hotel_id: '58',
    partner_id: ' partner ',
    poi_id: 'poi',
    hotel_room_count: '88',
    competitor_room_count: '188',
    cookies: 'cookie=value',
  }));
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveRequestHotelId({
    formHotelId: ' 58 ',
    rankingHotelId: '7',
    filterHotelId: '60',
    userHotelId: '99',
  }), '58');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveRequestHotelId({
    formHotelId: '',
    rankingHotelId: ' 7 ',
    filterHotelId: '60',
    userHotelId: '99',
  }), '7');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveRequestHotelId({
    formHotelId: '',
    rankingHotelId: '',
    filterHotelId: ' 60 ',
    userHotelId: '99',
  }), '60');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveRequestHotelId({
    formHotelId: '',
    rankingHotelId: '',
    filterHotelId: '',
    userHotelId: ' 99 ',
  }), '99');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveRequestHotelId({}), '');
  assert.equal(JSON.stringify(meituanStaticApi.createEmptyMeituanConfigForm()), JSON.stringify({
    id: null,
    name: '',
    hotel_id: '',
    partner_id: '',
    poi_id: '',
    cookies: '',
    has_cookies: false,
    credential_status: '',
    hotel_room_count: '',
    competitor_room_count: '',
  }));
  assert.equal(meituanStaticApi.buildMeituanConfigDeleteUrl(' 12 '), '/online-data/delete-meituan-config?id=12');
  assert.equal(meituanStaticApi.buildMeituanConfigDeleteUrl('id/with space'), '/online-data/delete-meituan-config?id=id%2Fwith%20space');
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigDeleteSuccessState(' 12 ')), JSON.stringify({
    toastMessage: '删除成功',
    toastLevel: 'success',
    clearConfigDetailId: ' 12 ',
    shouldReloadConfigList: true,
    reloadOptions: {},
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigDeleteFailureState({
    response: { message: 'delete denied' },
  })), JSON.stringify({
    toastMessage: 'delete denied',
    toastLevel: 'error',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigDeleteFailureState({
    error: new Error('network down'),
  })), JSON.stringify({
    toastMessage: '删除失败: network down',
    toastLevel: 'error',
  }));
  assert.equal(meituanStaticApi.resolveSavedMeituanConfigHotelId({
    responseData: { system_hotel_id: ' 61 ' },
    requestBody: { hotel_id: '58' },
    fallbackHotelId: '7',
  }), '61');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveToastLevel({
    credential_requirement: { credential_status: 'missing_resource_id' },
  }), 'warning');
  assert.equal(meituanStaticApi.resolveMeituanConfigSaveToastLevel({
    credential_status: 'ready',
  }), 'success');
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigSaveSuccessState({
    response: {
      message: 'saved',
      data: {
        system_hotel_id: ' 61 ',
        credential_requirement: { credential_status: 'missing_resource_id' },
      },
    },
    requestBody: { hotel_id: '58' },
    fallbackHotelId: '7',
    form: { id: 9 },
  })), JSON.stringify({
    savedHotelId: '61',
    toastMessage: 'saved',
    toastLevel: 'warning',
    clearConfigDetailId: 9,
    resetForm: {
      id: null,
      name: '',
      hotel_id: '',
      partner_id: '',
      poi_id: '',
      cookies: '',
      has_cookies: false,
      credential_status: '',
      hotel_room_count: '',
      competitor_room_count: '',
    },
    shouldReturnToRanking: true,
    shouldReloadConfigList: false,
  }));
  assert.equal(meituanStaticApi.buildMeituanConfigSaveSuccessState({
    response: { data: {} },
    requestBody: {},
    fallbackHotelId: '',
    form: {},
  }).shouldReloadConfigList, true);
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigSaveFailureState({
    response: { message: 'bad request' },
  })), JSON.stringify({
    toastMessage: 'bad request',
    toastLevel: 'error',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigSaveFailureState({
    response: {},
  })), JSON.stringify({
    toastMessage: '保存失败',
    toastLevel: 'error',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigSaveFailureState({
    error: new Error('network down'),
  })), JSON.stringify({
    toastMessage: '保存失败: network down',
    toastLevel: 'error',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanRankingFormPatchFromConfig({
    system_hotel_id: '58',
    partner_id: 'partner',
    poi_id: 'poi',
    cookies: 'cookie',
    auth_data: { token: 'masked' },
    hotel_room_count: '88',
    competitor_room_count: '188',
  }, '7')), JSON.stringify({
    hotelId: '58',
    partnerId: 'partner',
    poiId: 'poi',
    hotelRoomCount: '88',
    competitorRoomCount: '188',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigUseState({
    config: {
      name: 'config',
      system_hotel_id: '58',
      partner_id: 'partner',
      poi_id: 'poi',
      cookies: 'cookie',
      auth_data: { token: 'masked' },
      hotel_room_count: '88',
      competitor_room_count: '188',
    },
    fallbackHotelId: '7',
  })), JSON.stringify({
    formPatch: {
      hotelId: '58',
      partnerId: 'partner',
      poiId: 'poi',
      hotelRoomCount: '88',
      competitorRoomCount: '188',
    },
    toastMessage: '已应用配置: config',
    targetTab: 'meituan-ranking',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigEditForm({
    id: 9,
    name: 'config',
    system_hotel_id: '58',
    partner_id: 'partner',
    poi_id: 'poi',
    cookies: 'cookie',
    has_cookies: true,
    credential_status: 'ready',
    hotel_room_count: '88',
    competitor_room_count: '188',
  })), JSON.stringify({
    id: 9,
    name: 'config',
    hotel_id: '58',
    partner_id: 'partner',
    poi_id: 'poi',
    cookies: '',
    has_cookies: true,
    credential_status: 'ready',
    hotel_room_count: '88',
    competitor_room_count: '188',
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanConfigEditState({
    config: {
      id: 9,
      name: 'config',
      system_hotel_id: '58',
      partner_id: 'partner',
      poi_id: 'poi',
      cookies: 'cookie',
      has_cookies: true,
      credential_status: 'ready',
      hotel_room_count: '88',
      competitor_room_count: '188',
    },
  })), JSON.stringify({
    form: {
      id: 9,
      name: 'config',
      hotel_id: '58',
      partner_id: 'partner',
      poi_id: 'poi',
      cookies: '',
      has_cookies: true,
      credential_status: 'ready',
      hotel_room_count: '88',
      competitor_room_count: '188',
    },
  }));
  assert.equal(JSON.stringify(meituanStaticApi.buildMeituanBookmarkletSuccessState({
    data: {
      bookmarklet: 'javascript:alert(1)',
      message: 'custom disabled message',
    },
  })), JSON.stringify({
    bookmarklet: 'javascript:alert(1)',
    toastMessage: 'custom disabled message',
    toastLevel: 'warning',
  }));
  assert.equal(meituanStaticApi.buildMeituanBookmarkletFailureState({
    error: new Error('network down'),
  }).toastLevel, 'error');
  assert.match(meituanStaticApi.buildMeituanBookmarkletFailureState({
    error: new Error('network down'),
  }).toastMessage, /network down/);
  assert.match(saveMeituanConfigItem, /meituanConfigAutoName\(meituanConfigForm\.value\)/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入配置名称/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入Partner ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入POI ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入酒店房量/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入竞争圈总房量/);
  assert.doesNotMatch(html, /配置名称 \*/);
  assert.match(html, /缺门店标识/);
  assert.match(html, /平台接口标识（一次性配置，可后补）/);
  assert.match(html, /平台门店标识（一次性配置，可后补）/);
  assert.match(html, /酒店总房量（采集优先，可选补充）/);
  assert.match(html, /竞争圈总房量（采集优先，可选补充）/);
  assert.match(html, /留空会显示待补，不会按 0 参与分析/);
  assert.match(html, /Cookie 已保存，待采集验证/);
  assert.match(html, /仅 Cookie 失效时粘贴新内容；留空不会覆盖/);
  assert.match(html, /detail\?partnerId=\.\.\./);
  assert.match(html, /poiId=xxx/);
  assert.match(html, /partnerId=xxx/);
  assert.match(saveMeituanConfigItem, /const requestHotelId = resolveMeituanConfigSaveRequestHotelId\(\{/);
  assert.match(saveMeituanConfigItem, /formHotelId: meituanConfigForm\.value\.hotel_id,/);
  assert.match(saveMeituanConfigItem, /rankingHotelId: meituanForm\.value\.hotelId,/);
  assert.match(saveMeituanConfigItem, /filterHotelId: onlineDataFilter\.value\.hotel_id,/);
  assert.match(saveMeituanConfigItem, /userHotelId: user\.value\?\.hotel_id,/);
  assert.doesNotMatch(saveMeituanConfigItem, /const requestHotelId = String\(/);
  assert.match(saveMeituanConfigItem, /buildMeituanConfigSaveRequestBody\(\{/);
  assert.match(saveMeituanConfigItem, /requestHotelId,/);
  assert.match(saveMeituanConfigItem, /cookies: cookieState\.cookies,/);
  assert.doesNotMatch(saveMeituanConfigItem, /^\s+cookies,\s*$/m);
  assert.match(saveMeituanConfigItem, /const saveSuccessState = buildMeituanConfigSaveSuccessState\(\{/);
  assert.match(saveMeituanConfigItem, /response: res,/);
  assert.match(saveMeituanConfigItem, /form: meituanConfigForm\.value,/);
  assert.doesNotMatch(saveMeituanConfigItem, /resolveSavedMeituanConfigHotelId\(\{/);
  assert.match(saveMeituanConfigItem, /showToast\(saveSuccessState\.toastMessage, saveSuccessState\.toastLevel\);/);
  assert.match(saveMeituanConfigItem, /clearMeituanConfigDetailCache\(saveSuccessState\.clearConfigDetailId\);/);
  assert.match(saveMeituanConfigItem, /meituanConfigForm\.value = saveSuccessState\.resetForm;/);
  assert.match(saveMeituanConfigItem, /if \(saveSuccessState\.shouldReturnToRanking\) \{/);
  assert.match(saveMeituanConfigItem, /await returnToMeituanRankingAfterConfigSave\(saveSuccessState\.savedHotelId\);/);
  assert.match(saveMeituanConfigItem, /\} else if \(saveSuccessState\.shouldReloadConfigList\) \{/);
  assert.match(saveMeituanConfigItem, /await loadMeituanConfigList\(\{ force: true, applySelectedConfig: false \}\);/);
  assert.match(saveMeituanConfigItem, /const saveFailureState = buildMeituanConfigSaveFailureState\(\{ response: res \}\);/);
  assert.match(saveMeituanConfigItem, /const saveFailureState = buildMeituanConfigSaveFailureState\(\{ error: e \}\);/);
  assert.match(saveMeituanConfigItem, /showToast\(saveFailureState\.toastMessage, saveFailureState\.toastLevel\);/);
  assert.doesNotMatch(saveMeituanConfigItem, /showToast\(res\.message \|\| '保存失败', 'error'\);/);
  assert.doesNotMatch(saveMeituanConfigItem, /showToast\('保存失败: ' \+ e\.message, 'error'\);/);
  assert.match(useMeituanConfig, /const useState = buildMeituanConfigUseState\(\{/);
  assert.match(useMeituanConfig, /fallbackHotelId: meituanForm\.value\.hotelId,/);
  assert.match(useMeituanConfig, /Object\.assign\(meituanForm\.value, useState\.formPatch\);/);
  assert.match(useMeituanConfig, /showToast\(useState\.toastMessage\);/);
  assert.match(useMeituanConfig, /onlineDataTab\.value = useState\.targetTab;/);
  assert.doesNotMatch(useMeituanConfig, /buildMeituanRankingFormPatchFromConfig\(config, meituanForm\.value\.hotelId\)/);
  assert.doesNotMatch(useMeituanConfig, /onlineDataTab\.value = 'meituan-ranking';/);
  assert.doesNotMatch(useMeituanConfig, /meituanForm\.value\.partnerId = config\.partner_id/);
  assert.match(editMeituanConfig, /const editState = buildMeituanConfigEditState\(\{ config \}\);/);
  assert.match(editMeituanConfig, /meituanConfigForm\.value = editState\.form;/);
  assert.doesNotMatch(editMeituanConfig, /buildMeituanConfigEditForm\(config\)/);
  assert.doesNotMatch(editMeituanConfig, /hotel_room_count: config\.hotel_room_count/);
  assert.match(deleteMeituanConfigItem, /request\(buildMeituanConfigDeleteUrl\(id\), \{/);
  assert.match(deleteMeituanConfigItem, /const deleteSuccessState = buildMeituanConfigDeleteSuccessState\(id\);/);
  assert.match(deleteMeituanConfigItem, /showToast\(deleteSuccessState\.toastMessage, deleteSuccessState\.toastLevel\);/);
  assert.match(deleteMeituanConfigItem, /clearMeituanConfigDetailCache\(deleteSuccessState\.clearConfigDetailId\);/);
  assert.match(deleteMeituanConfigItem, /loadMeituanConfigList\(deleteSuccessState\.reloadOptions\);/);
  assert.match(deleteMeituanConfigItem, /const deleteFailureState = buildMeituanConfigDeleteFailureState\(\{ response: res \}\);/);
  assert.match(deleteMeituanConfigItem, /const deleteFailureState = buildMeituanConfigDeleteFailureState\(\{ error: e \}\);/);
  assert.match(deleteMeituanConfigItem, /showToast\(deleteFailureState\.toastMessage, deleteFailureState\.toastLevel\);/);
  assert.match(generateMeituanBookmarklet, /const successState = buildMeituanBookmarkletSuccessState\(res\);/);
  assert.match(generateMeituanBookmarklet, /meituanBookmarklet\.value = successState\.bookmarklet;/);
  assert.match(generateMeituanBookmarklet, /showToast\(successState\.toastMessage, successState\.toastLevel\);/);
  assert.match(generateMeituanBookmarklet, /const failureState = buildMeituanBookmarkletFailureState\(\{ error: e \}\);/);
  assert.match(generateMeituanBookmarklet, /showToast\(failureState\.toastMessage, failureState\.toastLevel\);/);
  assert.doesNotMatch(generateMeituanBookmarklet, /meituanBookmarklet\.value = res\.data\.bookmarklet;/);
  assert.doesNotMatch(generateMeituanBookmarklet, /showToast\(res\.data\?\.message \|\| '旧版美团 Cookie 书签已禁用', 'warning'\);/);
  assert.doesNotMatch(generateMeituanBookmarklet, /showToast\('生成失败: ' \+ e\.message, 'error'\);/);
  assert.doesNotMatch(deleteMeituanConfigItem, /showToast\('删除成功'\);/);
  assert.doesNotMatch(deleteMeituanConfigItem, /showToast\(res\.message \|\| '删除失败', 'error'\);/);
  assert.doesNotMatch(deleteMeituanConfigItem, /showToast\('删除失败: ' \+ e\.message, 'error'\);/);
  assert.match(returnToMeituanRankingAfterConfigSave, /currentPage\.value = returnState\.page;/);
  assert.match(returnToMeituanRankingAfterConfigSave, /onlineDataTab\.value = returnState\.tab;/);
  assert.match(returnToMeituanRankingAfterConfigSave, /loadHotels\(\{ force: true \}\)/);
  assert.match(returnToMeituanRankingAfterConfigSave, /loadOnlineDataHotelList\(\{ force: true \}\)/);
  assert.match(returnToMeituanRankingAfterConfigSave, /loadMeituanConfigList\(\{ force: true, applySelectedConfig: false \}\)/);
  assert.match(returnToMeituanRankingAfterConfigSave, /applyMeituanHotelConfig\(false, \{[\s\S]*refreshList: false,[\s\S]*skipIfAligned: false/);
});

test('Hotel management saves force-refresh the current management context', () => {
  const refreshHotelBindingPanelLight = functionSlice('refreshHotelBindingPanelLight');
  const refreshHotelBindingPanel = functionSlice('refreshHotelBindingPanel');
  const loadHotelManagementSnapshot = constSlice(
    'const loadHotelManagementSnapshot = async (options = {}) => {',
    '\n\n            const refreshHotelBindingPanelLight'
  );
  const ensureHotelOtaConfigLists = constSlice(
    'const ensureHotelOtaConfigLists = async (options = {}) => {',
    '\n\n            const openHotelManagementForOta'
  );
  const saveHotelOtaConfig = constSlice(
    'const saveHotelOtaConfig = async (hotelId, hotelName) => {',
    '\n\n            const hasPartialMeituanOtaConfig'
  );
  const saveHotel = functionSlice('saveHotel');

  assert.match(refreshHotelBindingPanelLight, /loadHotelManagementSnapshot\(\{[\s\S]*force: true,[\s\S]*deep: false,[\s\S]*showSuccess: true/);
  assert.match(refreshHotelBindingPanel, /loadHotelManagementSnapshot\(\{[\s\S]*force: true,[\s\S]*deep: true,[\s\S]*showSuccess: true/);
  assert.match(loadHotelManagementSnapshot, /loadHotels\(\{ force, includeInactive: true \}\)/);
  assert.match(loadHotelManagementSnapshot, /ensureHotelOtaConfigLists\(\{[\s\S]*force,[\s\S]*includeHotels: false,[\s\S]*includeDataSources: true/);
  assert.match(loadHotelManagementSnapshot, /if \(deep\) \{[\s\S]*loadPlatformSyncTasks\(\{ force: true \}\)[\s\S]*loadPlatformSyncLogs\(\{ force: true \}\)[\s\S]*loadCompetitorSummary\(\{ includeByHotel: true, force: true \}\)/);
  assert.match(loadHotelManagementSnapshot, /hotelManagementFailureLabels\(deep\)/);
  assert.match(ensureHotelOtaConfigLists, /const force = options\.force === true;/);
  assert.match(ensureHotelOtaConfigLists, /const includeHotels = options\.includeHotels !== false;/);
  assert.match(ensureHotelOtaConfigLists, /const includeDataSources = options\.includeDataSources !== false;/);
  assert.match(ensureHotelOtaConfigLists, /loadCtripConfigList\(\{[\s\S]*force,[\s\S]*cacheMs: force \? 0 : MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS/);
  assert.match(ensureHotelOtaConfigLists, /loadMeituanConfigList\(\{[\s\S]*force,[\s\S]*cacheMs: force \? 0 : MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS/);
  assert.match(saveHotelOtaConfig, /loadCtripConfigList\(\{ force: true, applySelectedConfig: false \}\)/);
  assert.match(saveHotelOtaConfig, /loadMeituanConfigList\(\{ force: true, applySelectedConfig: false \}\)/);
  assert.match(saveHotel, /await loadHotels\(\{ force: true, includeInactive: true \}\);/);
  assert.ok((html.match(/await loadHotels\(\{ force: true, includeInactive: true \}\);/g) || []).length >= 4);
});

test('FontAwesome stylesheet does not block the core shell first second', () => {
  const head = sliceFrom('<head>', '</head>');

  assert.doesNotMatch(head, /<link\s+href=["']font-awesome\.min\.css["']\s+rel=["']stylesheet["']/);
  assert.match(head, /const fontAwesomeStylesheet = 'font-awesome\.min\.css\?v=20260628-static-router-fix';/);
  assert.match(head, /link\.dataset\.suxiFontawesome = '1';/);
  assert.match(head, /window\.setTimeout\(loadFontAwesomeStylesheet, 1600\);/);
  assert.match(head, /document\.addEventListener\('DOMContentLoaded', run, \{ once: true \}\);/);
});

test('Login background preload does not compete with cached-auth shell', () => {
  const head = sliceFrom('<head>', '</head>');
  const preloadOffset = head.indexOf("const loginBackgroundPreload = 'images/login-hotel-lobby-bg.avif';");
  const tailwindOffset = head.indexOf('href="tailwind.min.css?v=');

  assert.doesNotMatch(head, /<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']/);
  assert.ok(preloadOffset >= 0 && tailwindOffset >= 0 && preloadOffset < tailwindOffset);
  assert.match(head, /const readStartupAuthToken = \(\) => \{/);
  assert.match(head, /const shouldPreloadLoginBackground = \(\) => \{/);
  assert.match(head, /return !readStartupAuthToken\(\) \|\| !localStorage\.getItem\('suxios_auth_user_cache_v1'\)/);
  assert.match(head, /link\.setAttribute\('fetchpriority', 'high'\);/);
  assert.match(head, /link\.dataset\.suxiLoginBgPreload = '1';/);
});

test('Login page uses SUXIOS brand instead of legacy Guilusuli brand', () => {
  const loginPanel = sliceFrom('<div v-if="!isLoggedIn"', '<!-- 登录表单 -->');

  assert.match(html, /style\.css\?v=[^"']+/);
  assert.match(loginPanel, /aria-label="宿析OS登录主视觉"/);
  assert.match(loginPanel, /<p class="login-brand-mark">宿析OS<\/p>/);
  assert.match(loginPanel, /src="images\/logo\.svg" alt="宿析OS"/);
  assert.match(loginPanel, /<p class="login-card-kicker">SUXIOS<\/p>/);
  assert.match(loginPanel, /<h1 class="login-title text-3xl font-bold mb-2">宿析OS<\/h1>/);
  assert.match(loginPanel, /进入宿析OS经营系统/);
  assert.doesNotMatch(loginPanel, /归鹿宿里|GUILUSULI|guilusuli-logo\.jpg/);
});

test('OTA diagnosis helper does not block the online data shell', () => {
  const head = sliceFrom('<head>', '</head>');
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const generateOtaDiagnosis = sliceFrom('const generateOtaDiagnosis = async () => {', '\n\n            // 加载Agent概览');

  assert.doesNotMatch(head, /<script src="ota-diagnosis-static\.js/);
  assert.match(html, /const otaDiagnosisStaticScript = 'ota-diagnosis-static\.js\?v=20260715-meituan-daily-loop-h1c7db7577d';/);
  assert.match(html, /const ensureOtaDiagnosisStaticReady = async \(\) => loadOtaDiagnosisStatic\(\);/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'ota-diagnosis-static', \(\) => new Promise\(resolve => setTimeout\(resolve, 420\)\)\s*\.then\(\(\) => currentPage\.value === 'agent-center' \? ensureOtaDiagnosisStaticReady\(\) : null\)\);/);
  assert.match(generateOtaDiagnosis, /const runOtaDiagnosisGenerateFlow = await getOtaDiagnosisGenerateFlow\(\);/);
  assert.match(html, /const runOtaDiagnosisHotelFetch = async \(selectedHotel, form\) => \{\s*const runOtaDiagnosisHotelFetchFlow = await getOtaDiagnosisHotelFetchFlow\(\);/);
});

test('Home lower dashboard panels mount after the first OTA navigation window', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');

  assert.match(html, /const HOME_SECONDARY_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const COMPASS_WEATHER_REFRESH_DELAY_MS = 3200;/);
  assert.match(html, /const homeSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const scheduleHomeSecondaryPanelsReady = \(delayMs = HOME_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(currentPageWatcher, /clearHomeSecondaryPanelsReadyTimer\(\);\s*homeSecondaryPanelsReady\.value = false;\s*destroyHomeTrendChart\(\);/);
  assert.match(currentPageWatcher, /homeSecondaryPanelsReady\.value = false;\s*scheduleHomeSecondaryPanelsReady\(\);[\s\S]{0,320}?runPageLoadOnce\(newPage, 'main', \(\) => loadCompassData\(\{\s*skipOtaBackground:\s*true\s*\}\)\);/);
  assert.doesNotMatch(currentPageWatcher, /runPageLoadOnce\(newPage, 'auto-fetch-static'/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="daily-ops-monitor-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="home-weather-demand-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="home-market-signal-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady && homeTrendCards\.length"/);
});

test('Page-control test ids do not block core page switching', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const isLoggedInWatcher = sliceFrom('watch(isLoggedIn, (loggedIn) => {', '\n\n            // 监听数据记录标签页切换');
  const onlineDataTabWatcher = sliceFrom('watch(onlineDataTab, (newTab) => {', '\n\n            let meituanHotelConfigApplyVersion');
  const scheduleObserverStart = sliceFrom('const schedulePageControlTestIdObserverStart = (delayMs = 520) => {', '\n\n            //');
  const pageControlGate = sliceFrom('const pageControlTestIdsEnabledForShell = () => {', '\n            const loadTestIdStatic');

  assert.match(html, /let pageControlTestIdObserverTimer = null;/);
  assert.match(pageControlGate, /params\.get\('testids'\) === '1'/);
  assert.match(pageControlGate, /params\.get\('e2e'\) === '1'/);
  assert.match(pageControlGate, /localStorage\.getItem\('enablePageTestIds'\) === '1'/);
  assert.doesNotMatch(pageControlGate, /host === 'localhost'/);
  assert.doesNotMatch(pageControlGate, /host === '127\.0\.0\.1'/);
  assert.doesNotMatch(pageControlGate, /host === '::1'/);
  assert.match(scheduleObserverStart, /clearPageControlTestIdObserverTimer\(\);/);
  assert.match(scheduleObserverStart, /const observerDelay = isCoreOtaPageVisible\(\) \? Math\.max\(normalizedDelay, 1800\) : normalizedDelay;/);
  assert.match(scheduleObserverStart, /deferUiTask\(\(\) => \{/);
  assert.match(scheduleObserverStart, /startPageControlTestIdObserver\(\);/);
  assert.match(scheduleObserverStart, /scheduleTestIdRefresh\(\);/);
  assert.match(currentPageWatcher, /schedulePageControlTestIdObserverStart\(520\);/);
  assert.doesNotMatch(currentPageWatcher, /scheduleTestIdRefresh\(\);\s*startPageControlTestIdObserver\(\);/);
  assert.doesNotMatch(currentPageWatcher, /startPageControlTestIdObserver\(\);/);
  assert.match(isLoggedInWatcher, /schedulePageControlTestIdObserverStart\(700\);/);
  assert.doesNotMatch(isLoggedInWatcher, /startPageControlTestIdObserver\(\);/);
  assert.match(onlineDataTabWatcher, /schedulePageControlTestIdObserverStart\(1800\);/);
  assert.match(html, /if \(isLoggedIn\.value\) \{\s*schedulePageControlTestIdObserverStart\(700\);/);
});

test('Public system config refresh does not compete with core OTA switching', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const systemConfigLoader = sliceFrom('const SYSTEM_CONFIG_PUBLIC_CACHE_TTL_MS = 60 * 1000;', '\n\n            //');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.match(systemConfigLoader, /let systemConfigPublicLoadPromise = null;/);
  assert.match(systemConfigLoader, /if \(publicOnly && systemConfigPublicLoadPromise\) \{/);
  assert.match(systemConfigLoader, /systemConfigPublicLoadedAt && Date\.now\(\) - systemConfigPublicLoadedAt < SYSTEM_CONFIG_PUBLIC_CACHE_TTL_MS/);
  assert.match(systemConfigLoader, /const schedulePublicSystemConfigRefresh = \(delayMs = 1800\) => \{/);
  assert.match(systemConfigLoader, /if \(isCoreOtaPageVisible\(\)\) return undefined;/);
  assert.match(currentPageWatcher, /deferUiTask\(\(\) => runPendingPublicSystemConfigRefresh\(\), 600\);/);
  assert.match(loadData, /schedulePublicSystemConfigRefresh\(1800\);/);
  assert.doesNotMatch(loadData, /deferUiTask\(\(\) => loadSystemConfig\(\{ publicOnly: true \}\), 120\)/);
});

test('eBooking startup refreshes are deduplicated during quick page returns', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const loadDataHealthPanel = sliceFrom(
    "const loadDataHealthPanel = async (mode = 'light', options = {}) => {",
    '\n\n            // 手动触发自动获取'
  );

  assert.match(html, /const EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS = 45000;/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleDelayedPageTask\(\(\) => \{\s*if \(!isCtripEbookingDataHealthVisible\(\)\) return null;\s*scheduleDataHealthPanelRefresh\('light'\);\s*return null;\s*\}, CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS\);\s*scheduleCtripEbookingDeferredStartupRefresh\(\);\s*\}, \{ ttlMs: EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS \}\);/);
  assert.match(loadDataHealthPanel, /if \(normalizedMode !== 'full' && isCtripEbookingDataHealthVisible\(\)\) \{\s*jobs\.push\(loadCollectionReliability\('light'\)\);\s*\}/);
  assert.match(currentPageWatcher, /if \(newPage === 'meituan-ebooking'\) \{\s*onlineDataTab\.value = 'meituan-ranking';\s*ensureMeituanManualHotelSelected\(\);\s*runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleMeituanEbookingDeferredStartupRefresh\(\);\s*\}, \{ ttlMs: EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS \}\);/);
});

test('Saved OTA data config reads are short-cached and deduplicated during manual tab switching', () => {
  const savedOtaConfigLoader = sliceFrom('const SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS = 30000;', '\n\n            const readSavedOtaDataConfig = async');
  const readSavedOtaDataConfig = sliceFrom('const readSavedOtaDataConfig = async (type) => {', '\n\n            const isSavedOtaDataConfigUsable');
  const loadSavedDataConfigByType = sliceFrom('const loadSavedDataConfigByType = async (type) => {', '\n\n            const applyCtripCommentManualConfig');
  const saveDataConfig = sliceFrom('const saveDataConfig = async () => {', '\n\n            const testDataConfig');

  assert.match(savedOtaConfigLoader, /const savedOtaDataConfigCache = new Map\(\);/);
  assert.match(savedOtaConfigLoader, /const savedOtaDataConfigLoadingPromises = new Map\(\);/);
  assert.match(savedOtaConfigLoader, /const getSavedOtaDataConfigKey = \(type\) => `data_config_\$\{String\(type \|\| ''\)\.replace\('-', '_'\)\}`;/);
  assert.match(savedOtaConfigLoader, /const readSavedOtaDataConfigFromSystem = async \(type\) => \{/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigCache\.get\(configKey\)/);
  assert.match(savedOtaConfigLoader, /cached && cached\.expiresAt > Date\.now\(\)/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigLoadingPromises\.has\(configKey\)/);
  assert.match(savedOtaConfigLoader, /request\(`\/system-config\?key=\$\{configKey\}`\)/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigCache\.set\(configKey, \{/);
  assert.match(savedOtaConfigLoader, /expiresAt: Date\.now\(\) \+ SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS/);
  assert.match(readSavedOtaDataConfig, /return await readSavedOtaDataConfigFromSystem\(type\) \|\| \{\};/);
  assert.match(loadSavedDataConfigByType, /return await readSavedOtaDataConfigFromSystem\(type\);/);
  assert.match(saveDataConfig, /clearSavedOtaDataConfigCache\(currentDataConfigType\.value\);/);
});

test('Ctrip profile field config tab reuses recent list and sample reads', () => {
  const profileFieldCache = sliceFrom('const CTRIP_PROFILE_FIELDS_TAB_CACHE_TTL_MS = 30000;', '\n\n            const loadCtripProfileFieldSamples');
  const loadSamples = sliceFrom('const loadCtripProfileFieldSamples = async (requestSeq, options = {}) => {', '\n\n            const loadCtripProfileFields');
  const loadFields = sliceFrom('const loadCtripProfileFields = async (options = {}) => {', '\n\n            const openCtripProfileFieldsForReview');
  const onlineDataTabScheduler = sliceFrom('const scheduleOnlineDataTabLoad = (newTab, options = {}) => {', '\n            const openOnlineDataTab');
  const saveModule = sliceFrom('const saveCtripProfileModule = async () => {', '\n\n            const deleteCtripProfileModule');
  const saveField = sliceFrom('const saveCtripProfileField = async () => {', '\n\n            const toggleCtripProfileFieldEnabled');

  assert.match(profileFieldCache, /const ctripProfileFieldResultCache = new Map\(\);/);
  assert.match(profileFieldCache, /const ctripProfileFieldRequestPromises = new Map\(\);/);
  assert.match(profileFieldCache, /const ctripProfileFieldCacheKey = \(includeSamples\) => includeSamples \? 'include-samples' : 'list-only';/);
  assert.match(profileFieldCache, /const clearCtripProfileFieldCache = \(\) => \{/);
  assert.match(profileFieldCache, /ctripProfileFieldResultCache\.clear\(\);/);
  assert.match(profileFieldCache, /const requestCtripProfileFields = async \(includeSamples, options = \{\}\) => \{/);
  assert.match(profileFieldCache, /const cached = readCtripProfileFieldCache\(key\);/);
  assert.match(profileFieldCache, /return \{ code: 200, data: cached, from_cache: true \};/);
  assert.match(profileFieldCache, /if \(ctripProfileFieldRequestPromises\.has\(key\)\) \{/);
  assert.match(profileFieldCache, /request\(`\/online-data\/ctrip-profile-fields\?include_samples=\$\{includeSamples \? 1 : 0\}`\)/);
  assert.match(profileFieldCache, /writeCtripProfileFieldCache\(key, res\.data \|\| \{\}\);/);
  assert.match(loadSamples, /requestCtripProfileFields\(true, \{ force: options\.force === true \}\)/);
  assert.match(loadFields, /const force = options\.force === true;/);
  assert.match(loadFields, /requestCtripProfileFields\(false, \{ force \}\)/);
  assert.match(loadFields, /loadCtripProfileFieldSamples\(requestSeq, \{ force \}\)/);
  assert.match(onlineDataTabScheduler, /void ensureCtripProfileFieldConfigPanelReady\(\)\.catch/);
  assert.match(onlineDataTabScheduler, /return runIfCurrent\(\(\) => loadCtripProfileFields\(options\)\);/);
  assert.match(saveModule, /clearCtripProfileFieldCache\(\);\s*await loadCtripProfileFields\(\{ force: true \}\);/);
  assert.match(saveField, /clearCtripProfileFieldCache\(\);\s*await loadCtripProfileFields\(\{ force: true \}\);/);
  assert.match(html, /clearCtripProfileFieldCache\(\);\s*mergeCtripProfileFieldUpdate\(res\.data \|\| \{\}\);/);
  assert.match(html, /const CtripProfileFieldConfigPanel = \{\s*name: 'CtripProfileFieldConfigPanel'/);
  assert.match(html, /const ensureCtripProfileFieldConfigPanelReady = async \(\) => \{/);
  assert.match(html, /requireOnlineDataComponent\('CtripProfileFieldConfigPanelBody'\)/);
  assert.match(html, /void ensureCtripProfileFieldConfigPanelReady\(\)\.catch/);
  assert.match(html, /<ctrip-profile-field-config-panel\s+v-if="onlineDataTab === 'profile-fields' && user\?\.is_super_admin"\s+:ctx="\$root">/);
  assert.match(html, /data-testid="ctrip-profile-field-config-loading"/);
  assert.match(ctripProfileFieldConfigPanel, /components\.CtripProfileFieldConfigPanelBody/);
  assert.match(ctripProfileFieldConfigPanel, /data-testid=\\?"ctrip-profile-field-config-panel\\?"/);
  assert.match(ctripProfileFieldConfigPanel, /return new Proxy\(\{\}, \{/);
  assert.match(ctripProfileFieldConfigPanel, /return props\.ctx\?\.\[key\] \?\? target\[key\];/);
  assert.match(ctripProfileFieldConfigPanel, /props\.ctx\[key\] = value;/);
  assert.match(ctripProfileFieldConfigPanel, /getOwnPropertyDescriptor\(\) \{/);
  assert.doesNotMatch(html, /携程登录会话字段配置/);
});

test('Form operation support loads after login instead of blocking the login shell', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const onlineDataTabWatcher = sliceFrom('watch(onlineDataTab, (newTab) => {', '\n\n            let meituanHotelConfigApplyVersion');
  const formOperationLoader = sliceFrom("const formOperationSupportScript = 'form-operation-support.js';", '\n            const clearAuthSession');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.doesNotMatch(html, /<script\s+src=["']form-operation-support\.js["']/);
  assert.match(formOperationLoader, /script\.src = formOperationSupportScript \+ '\?v=' \+ formOperationSupportScriptVersion;/);
  assert.match(formOperationLoader, /window\.SuxiFormOperationSupport\.init\(window\);/);
  assert.match(formOperationLoader, /const shouldDeferFormOperationSupportLoad = \(\) => isCompassDataPage\(\) \|\| isCoreOtaPageVisible\(\);/);
  assert.match(formOperationLoader, /const pageDelay = shouldDeferFormOperationSupportLoad\(\) \? 6400 : 5200;/);
  assert.match(formOperationLoader, /if \(shouldDeferFormOperationSupportLoad\(\)\) return;/);
  assert.match(currentPageWatcher, /scheduleFormOperationSupportLoad\(\);/);
  assert.match(onlineDataTabWatcher, /scheduleFormOperationSupportLoad\(\);/);
  assert.match(loadData, /scheduleFormOperationSupportLoad\(\);/);
});

test('AI daily report exposes the money formatter used by competitor changes', () => {
  assert.match(html, /const operationMoney = requireAppSystemStatic\('operationMoney'\);/);
  assert.match(html, /operationMoney\(item\.avg_price\)/);
  assert.match(html, /operationMoney\(item\.price_gap\)/);
  assert.match(html, /operationValue, operationMoney, operationPercent, operationDataStatusText/);
});

test('Vue template helper calls are exposed from setup return', () => {
  const template = mainTemplateSource();
  const setupReturn = mainSetupReturnSource();
  const returnedNames = new Set(
    [...setupReturn.matchAll(/\b([A-Za-z_$][\w$]*)\b/g)].map(match => match[1])
  );
  const browserAndPrototypeCalls = new Set([
    'Array',
    'Boolean',
    'Date',
    'JSON',
    'Math',
    'Number',
    'Object',
    'String',
    'filter',
    'find',
    'getEntriesByType',
    'includes',
    'join',
    'map',
    'reduce',
    'slice',
    'some',
    'toFixed',
    'toLowerCase',
    'toUpperCase',
    'trim',
    'values',
  ]);
  const helperCalls = new Set(
    [...template.matchAll(/\b([A-Za-z_$][\w$]*)\s*\(/g)]
      .map(match => match[1])
      .filter(name => /^[a-z][A-Za-z0-9_$]*[A-Z][A-Za-z0-9_$]*$/.test(name))
      .filter(name => !browserAndPrototypeCalls.has(name))
  );
  const missing = [...helperCalls].filter(name => !returnedNames.has(name)).sort();
  assert.deepEqual(missing, []);
});

test('nested menu clicks resolve viewport state outside Vue template expressions', () => {
  const template = readFileSync('resources/frontend/app-template.html', 'utf8');
  const handler = sliceFrom(
    'const handleNestedMenuClick = (item, parentName) => {',
    '\n\n            const isStillOnRequestPage'
  );

  assert.doesNotMatch(template, /\bwindow\.innerWidth\b/);
  assert.match(template, /handleNestedMenuClick\(grandChild, item\.name\)/);
  assert.match(template, /handleNestedMenuClick\(child, item\.name\)/);
  assert.match(handler, /handleMenuClick\(item\);/);
  assert.match(handler, /typeof window !== 'undefined' && window\.innerWidth <= 640/);
  assert.match(handler, /toggleSubmenu\(parentName\);/);
});

test('Meituan hotel matching does not wait for all-store competitor summaries', () => {
  const loadCompetitorSummary = sliceFrom('const loadCompetitorSummary = async (options = {}) => {', '\n            const loadCompassData');
  const scheduleMeituanRankingSummaryRefresh = sliceFrom('const scheduleMeituanRankingSummaryRefresh = (options = {}) => {', '\n\n            // 线上数据获取相关方法');
  const applyMeituanHotelConfig = sliceFrom('const applyMeituanHotelConfig = async (showMessage = true, options = {}) => {', '\n            const syncMeituanTrafficConfigFromSelectedConfig');
  const loadMeituanConfigDetail = sliceFrom('const meituanConfigDetailCache = new Map();', '\n            const applyCtripConfigObject');
  const loadMeituanConfigList = sliceFrom('const loadMeituanConfigList = async (options = {}) => {', '\n            const saveMeituanConfigItem');
  const findMeituanConfigByHotelId = sliceFrom('const findMeituanConfigByHotelId = (hotelId) => {', '\n\n            const selectedCtripHotelConfig');
  const openHomeQuickEntry = sliceFrom('const openHomeQuickEntry = (entry) => {', '\n\n            // 竞对价格监控');
  const meituanHotelSelectPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const meituanHotelWatcher = sliceFrom('watch(() => meituanForm.value.hotelId, () => {', '\n\n            watch(competitorTab');
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n            const handleMenuClick');
  const handleMenuClick = sliceFrom('const handleMenuClick = (item) => {', '\n\n            const isStillOnRequestPage');
  const scheduleMeituanEbookingDeferredStartupRefresh = sliceFrom('const scheduleMeituanEbookingDeferredStartupRefresh = () => {', '\n            const scheduleDefaultDashboardDeferredRefresh');
  const openMeituanManualTab = sliceFrom('const openMeituanManualTab = (tab) => {', '\n            let dataLoadTimer');
  const fetchMeituanData = sliceFrom('const fetchMeituanData = async (options = {}) => {', '\n\n            const useCtripTrafficDisplayRows');
  const meituanManualFetchConfigGuard = sliceFrom('const meituanManualFetchConfigProofPending = () => {', '\n\n            let manualOnlineFetchConfigReadyPromise');
  const resolveMeituanManualDefaultHotelId = sliceFrom('const resolveMeituanManualDefaultHotelId = () => {', '\n            const ensureMeituanManualHotelSelected');
  const ensureMeituanManualHotelSelected = sliceFrom('const ensureMeituanManualHotelSelected = () => {', '\n            const scheduleMeituanEbookingDeferredStartupRefresh');

  assert.match(loadCompetitorSummary, /const isMeituanRankingPage = currentPage\.value === 'meituan-ebooking' && onlineDataTab\.value === 'meituan-ranking';/);
  assert.match(loadCompetitorSummary, /includeByHotel = options\.includeByHotel === true;/);
  assert.match(loadCompetitorSummary, /if \(includeByHotel\) params\.append\('include_by_hotel', '1'\);/);
  assert.match(loadCompetitorSummary, /const cacheMs = force \? 0 : Number\(options\.cacheMs \|\| 0\);/);
  assert.match(loadCompetitorSummary, /readRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\)/);
  assert.match(loadCompetitorSummary, /competitorSummaryRequestPromises\.has\(requestKey\)/);
  assert.match(loadCompetitorSummary, /writeRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\);/);
  assert.match(loadCompetitorSummary, /if \(!isCurrentRequest\(\)\) return null;/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /scheduleDelayedPageTask\(async \(\) => \{/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /await loadCompetitorSummary\(\{ includeByHotel: false, force \}\);/);
  assert.doesNotMatch(openHomeQuickEntry, /await loadCompetitorSummary\(\)/);
  assert.match(openHomeQuickEntry, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.match(meituanStatic, /const findMeituanConfigForHotel = \(\{/);
  assert.match(meituanStatic, /normalizeHotelName = normalizeMeituanConfigHotelName/);
  assert.match(meituanStatic, /const normalizeName = typeof normalizeHotelName === 'function'/);
  assert.match(meituanStatic, /normalizeName\(hotelName\)/);
  assert.match(meituanStatic, /findMeituanConfigForHotel,/);
  assert.match(meituanStatic, /const resolveMeituanConfigStatus = \(\{/);
  assert.match(meituanStatic, /missingText: fields\.join\(' \/ '\)/);
  assert.match(meituanStatic, /resolveMeituanConfigStatus,/);
  assert.match(html, /requireMeituanStatic\('findMeituanConfigForHotel'\)/);
  assert.match(html, /requireMeituanStatic\('resolveMeituanConfigStatus'\)/);
  assert.match(html, /requireAppSystemStatic\('normalizeOtaConfigHotelName'\)/);
  assert.match(findMeituanConfigByHotelId, /findMeituanConfigForHotel\(\{/);
  assert.match(findMeituanConfigByHotelId, /configs: meituanConfigList\.value/);
  assert.match(findMeituanConfigByHotelId, /normalizeHotelName: normalizeOtaConfigHotelName/);
  assert.doesNotMatch(findMeituanConfigByHotelId, /meituanConfigList\.value\.find/);
  assert.match(html, /const resolveMeituanConfigStatusByHotelId = \(hotelId\) => \{/);
  assert.match(html, /resolveMeituanConfigStatus\(\{\s*config,\s*missingFields: config \? meituanConfigMissingFields\(config\) : \[\],\s*\}\)/);
  assert.match(html, /return resolveMeituanConfigStatusByHotelId\(hotelId\)\.missingText \|\| '';/);
  assert.match(html, /return resolveMeituanConfigStatusByHotelId\(hotelId\)\.name \|\| '';/);
  assert.match(html, /return resolveMeituanConfigStatusByHotelId\(hotelId\)\.configured === true;/);
  assert.equal(meituanStaticApi.findMeituanConfigForHotel({
    hotelId: '7',
    hotelName: 'ignored',
    configs: [
      { system_hotel_id: 8, name: 'other' },
      { hotel_id: 7, name: 'id match' },
    ],
  })?.name, 'id match');
  assert.equal(meituanStaticApi.findMeituanConfigForHotel({
    hotelId: '7',
    configs: [
      {
        id: 'older-ready',
        hotel_id: 7,
        name: 'older ready',
        credential_status: 'ready',
        has_cookies: true,
        configuration_verified: true,
        update_time: '2026-07-10 10:00:00',
      },
      {
        id: 'newest-not-ready',
        hotel_id: 7,
        name: 'newest not ready',
        credential_status: 'migration_required',
        has_cookies: false,
        update_time: '2026-07-12 10:00:00',
      },
      {
        id: 'newer-ready',
        hotel_id: 7,
        name: 'newer ready',
        credential_status: 'ready',
        has_cookies: true,
        configuration_verified: true,
        update_time: '2026-07-11 10:00:00',
      },
    ],
  })?.name, 'newer ready');
  assert.equal(meituanStaticApi.findMeituanConfigForHotel({
    hotelId: '99',
    hotelName: '凯曼未来酒店（巢湖万达广场店）',
    configs: [
      { hotel_name: '凯曼未来酒店（巢湖万达广场店）', name: 'name match' },
    ],
  })?.name, 'name match');
  assert.equal(meituanStaticApi.findMeituanConfigForHotel({
    hotelId: '113',
    hotelName: '敦煌莫月山',
    configs: [
      { hotel_id: 125, hotel_name: '敦煌莫月山', name: 'bound to another hotel' },
    ],
  }), null);
  assert.equal(meituanStaticApi.findMeituanConfigForHotel({
    hotelId: '',
    hotelName: '不存在',
    configs: [{ hotel_name: '其他门店', name: 'other' }],
  }), null);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigStatus({
    config: { name: '完整配置' },
    missingFields: [],
  }))), {
    hasConfig: true,
    configured: true,
    name: '完整配置',
    missingFields: [],
    missingText: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigStatus({
    config: { name: '缺项配置' },
    missingFields: ['平台接口标识', '', '平台授权'],
  }))), {
    hasConfig: true,
    configured: false,
    name: '缺项配置',
    missingFields: ['平台接口标识', '平台授权'],
    missingText: '平台接口标识 / 平台授权',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigStatus())), {
    hasConfig: false,
    configured: false,
    name: '',
    missingFields: [],
    missingText: '',
  });
  assert.match(meituanStatic, /const resolveCanFetchMeituanRankingData = \(\{/);
  assert.match(meituanStatic, /resolveCanFetchMeituanRankingData,/);
  assert.match(meituanStatic, /const resolveMeituanManualFetchConfigProofPending = \(\{/);
  assert.match(meituanStatic, /resolveMeituanManualFetchConfigProofPending,/);
  assert.match(meituanStatic, /const resolveMeituanManualFetchConfigCandidate = \(\{/);
  assert.match(meituanStatic, /resolveMeituanManualFetchConfigCandidate,/);
  assert.match(meituanStatic, /const resolveMeituanConfigListResponse = \(res = \{\}\) => \{/);
  assert.match(meituanStatic, /resolveMeituanConfigListResponse,/);
  assert.match(meituanStatic, /const resolveMeituanConfigListApplyAction = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigListApplyAction,/);
  assert.match(meituanStatic, /const resolveMeituanConfigListCachedResult = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigListCachedResult,/);
  assert.match(meituanStatic, /const resolveMeituanConfigListLoadingAction = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigListLoadingAction,/);
  assert.match(meituanStatic, /const buildMeituanConfigListSuccessState = \(\{/);
  assert.match(meituanStatic, /buildMeituanConfigListSuccessState,/);
  assert.match(meituanStatic, /const buildMeituanConfigListFailureAction = \(\{/);
  assert.match(meituanStatic, /buildMeituanConfigListFailureAction,/);
  assert.match(meituanStatic, /const buildMeituanConfigListStartState = \(\) => \(\{/);
  assert.match(meituanStatic, /buildMeituanConfigListStartState,/);
  assert.match(meituanStatic, /const buildMeituanConfigListFinishState = \(\) => \(\{/);
  assert.match(meituanStatic, /buildMeituanConfigListFinishState,/);
  assert.match(meituanStatic, /const getMeituanConfigDetailVersion = \(config = \{\}\) => String\(/);
  assert.match(meituanStatic, /getMeituanConfigDetailVersion,/);
  assert.match(meituanStatic, /const buildMeituanConfigDetailCacheKey = \(id = ''\) => \(id \? String\(id\) : ''\);/);
  assert.match(meituanStatic, /buildMeituanConfigDetailCacheKey,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailClearTarget = \(id = ''\) => \{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailClearTarget,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailLoadTarget = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailLoadTarget,/);
  assert.match(meituanStatic, /const buildMeituanConfigDetailRequestUrl = \(cacheKey = ''\) => \(/);
  assert.match(meituanStatic, /buildMeituanConfigDetailRequestUrl,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailResponse = \(res = \{\}\) => \{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailResponse,/);
  assert.match(meituanStatic, /const shouldSkipMeituanConfigDetailLoad = \(config = null\) => \(/);
  assert.match(meituanStatic, /shouldSkipMeituanConfigDetailLoad,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailCachedResult = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailCachedResult,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailCacheLookup = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailCacheLookup,/);
  assert.match(meituanStatic, /const buildMeituanConfigDetailCacheEntry = \(\{/);
  assert.match(meituanStatic, /buildMeituanConfigDetailCacheEntry,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailCacheStorePlan = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailCacheStorePlan,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailFailureAction = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailFailureAction,/);
  assert.match(meituanStatic, /const resolveMeituanConfigDetailPrewarmPlan = \(\{/);
  assert.match(meituanStatic, /resolveMeituanConfigDetailPrewarmPlan,/);
  assert.match(html, /const resolveMeituanConfigDetailPrewarmPlan = requireMeituanStatic\('resolveMeituanConfigDetailPrewarmPlan'\);/);
  assert.match(meituanStatic, /const resolveMeituanManualDefaultHotelIdFromState = \(\{/);
  assert.match(meituanStatic, /resolveMeituanManualDefaultHotelIdFromState,/);
  assert.equal(meituanStaticApi.resolveCanFetchMeituanRankingData({
    form: { cookies: 'temporary-cookie' },
    selectedConfig: null,
  }), false);
  assert.equal(meituanStaticApi.resolveCanFetchMeituanRankingData({
    form: { hotelId: '58' },
    selectedConfig: { id: 'meituan-58', config_id: 'meituan-58', has_cookies: true, credential_status: 'ready', configuration_verified: true },
  }), true);
  assert.equal(meituanStaticApi.resolveCanFetchMeituanRankingData({
    form: { hotelId: '58' },
    selectedConfig: null,
  }), false);
  assert.equal(meituanStaticApi.resolveCanFetchMeituanRankingData({
    form: null,
    selectedConfig: { id: 1 },
  }), false);
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigProofPending({
    form: { hotelId: '58' },
    selectedConfig: { id: 'meituan-58', config_id: 'meituan-58', has_cookies: true, credential_status: 'ready', configuration_verified: true },
  }), true);
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigProofPending({
    form: { hotelId: '58' },
    selectedConfig: null,
  }), false);
  const explicitMeituanConfig = { id: 'meituan-12', config_id: 'meituan-12', has_cookies: true, credential_status: 'ready', configuration_verified: true };
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigCandidate({
    config: explicitMeituanConfig,
    form: { hotelId: '58' },
    selectedConfig: { id: 'meituan-58', config_id: 'meituan-58', has_cookies: true, credential_status: 'ready', configuration_verified: true },
  }), explicitMeituanConfig);
  const selectedMeituanConfig = { id: 'meituan-58', config_id: 'meituan-58', has_cookies: true, credential_status: 'ready', configuration_verified: true };
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigCandidate({
    form: { hotelId: '58' },
    selectedConfig: selectedMeituanConfig,
  }), selectedMeituanConfig);
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigCandidate({
    form: {},
    selectedConfig: selectedMeituanConfig,
  }), null);
  const meituanConfigListRows = [{ id: 1 }];
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListResponse({
    code: 200,
    data: meituanConfigListRows,
  }))), {
    ok: true,
    list: meituanConfigListRows,
    message: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListResponse({
    code: 500,
    message: 'load failed',
  }))), {
    ok: false,
    list: [],
    message: 'load failed',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListApplyAction({
    hotelId: '58',
    shouldApplySelectedConfig: true,
  }))), {
    shouldApply: true,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListApplyAction({
    hotelId: '',
    shouldApplySelectedConfig: true,
  }))), {
    shouldApply: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListCachedResult({
    loaded: true,
    failed: false,
    cacheFresh: true,
    list: meituanConfigListRows,
  }))), {
    hit: true,
    list: meituanConfigListRows,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListCachedResult({
    force: true,
    loaded: true,
    failed: false,
    cacheFresh: true,
    list: meituanConfigListRows,
  }))), {
    hit: false,
    list: null,
  });
  const pendingMeituanConfigListPromise = Promise.resolve([]);
  const reuseMeituanConfigListAction = meituanStaticApi.resolveMeituanConfigListLoadingAction({
    force: false,
    loadingPromise: pendingMeituanConfigListPromise,
  });
  assert.equal(reuseMeituanConfigListAction.status, 'reuse');
  assert.equal(reuseMeituanConfigListAction.promise, pendingMeituanConfigListPromise);
  const awaitMeituanConfigListAction = meituanStaticApi.resolveMeituanConfigListLoadingAction({
    force: true,
    loadingPromise: pendingMeituanConfigListPromise,
  });
  assert.equal(awaitMeituanConfigListAction.status, 'await_previous');
  assert.equal(awaitMeituanConfigListAction.promise, pendingMeituanConfigListPromise);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigListLoadingAction())), {
    status: 'idle',
    promise: null,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigListSuccessState({
    list: meituanConfigListRows,
    loadedAt: 12345,
  }))), {
    list: meituanConfigListRows,
    loaded: true,
    loadedAt: 12345,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigListFailureAction({
    type: 'api',
    message: 'load failed',
  }))), {
    failed: true,
    label: '[Debug] API 返回错误:',
    detail: 'load failed',
  });
  const meituanConfigListError = new Error('network failed');
  const exceptionFailureAction = meituanStaticApi.buildMeituanConfigListFailureAction({
    type: 'exception',
    error: meituanConfigListError,
  });
  assert.equal(exceptionFailureAction.failed, true);
  assert.equal(exceptionFailureAction.label, '[Debug] 加载美团配置列表失败:');
  assert.equal(exceptionFailureAction.detail, meituanConfigListError);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigListStartState())), {
    loading: true,
    failed: false,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigListFinishState())), {
    loading: false,
    loadingPromise: null,
  });
  assert.equal(meituanStaticApi.getMeituanConfigDetailVersion({
    created_at: 'created-version',
    updated_at: 'updated-version',
    update_time: 'update-version',
  }), 'update-version');
  assert.equal(meituanStaticApi.getMeituanConfigDetailVersion({
    updated_at: 'updated-version',
    created_at: 'created-version',
  }), 'updated-version');
  assert.equal(meituanStaticApi.getMeituanConfigDetailVersion({}), '');
  assert.equal(meituanStaticApi.buildMeituanConfigDetailCacheKey('18'), '18');
  assert.equal(meituanStaticApi.buildMeituanConfigDetailCacheKey(18), '18');
  assert.equal(meituanStaticApi.buildMeituanConfigDetailCacheKey(''), '');
  assert.equal(meituanStaticApi.buildMeituanConfigDetailCacheKey(0), '');
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailClearTarget('18'))), {
    clearAll: false,
    cacheKey: '18',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailClearTarget(18))), {
    clearAll: false,
    cacheKey: '18',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailClearTarget(''))), {
    clearAll: true,
    cacheKey: '',
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailClearTarget(0))), {
    clearAll: true,
    cacheKey: '',
  });
  const pendingMeituanDetailPromise = Promise.resolve({ id: 18 });
  const meituanDetailLoadingPromises = new Map([['18', pendingMeituanDetailPromise]]);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailLoadTarget({
    id: '',
    loadingPromises: meituanDetailLoadingPromises,
  }))), {
    status: 'missing_key',
    cacheKey: '',
    promise: null,
  });
  const meituanLoadingTarget = meituanStaticApi.resolveMeituanConfigDetailLoadTarget({
    id: 18,
    loadingPromises: meituanDetailLoadingPromises,
  });
  assert.equal(meituanLoadingTarget.status, 'loading');
  assert.equal(meituanLoadingTarget.cacheKey, '18');
  assert.equal(meituanLoadingTarget.promise, pendingMeituanDetailPromise);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailLoadTarget({
    id: '19',
    loadingPromises: meituanDetailLoadingPromises,
  }))), {
    status: 'ready',
    cacheKey: '19',
    promise: null,
  });
  assert.equal(
    meituanStaticApi.buildMeituanConfigDetailRequestUrl('18 a'),
    '/online-data/get-meituan-config-detail?id=18%20a'
  );
  assert.equal(
    meituanStaticApi.buildMeituanConfigDetailRequestUrl('店#18'),
    '/online-data/get-meituan-config-detail?id=%E5%BA%97%2318'
  );
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailResponse({
    code: 200,
    data: { id: 18 },
  }))), {
    ok: true,
    message: '',
    data: { id: 18 },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailResponse({
    code: 500,
    message: 'custom failure',
  }))), {
    ok: false,
    message: 'custom failure',
    data: null,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailResponse({ code: 500 }))), {
    ok: false,
    message: '加载美团完整配置失败',
    data: null,
  });
  assert.equal(meituanStaticApi.shouldSkipMeituanConfigDetailLoad(null), true);
  assert.equal(meituanStaticApi.shouldSkipMeituanConfigDetailLoad({ id: 18, cookies: 'token=ok' }), true);
  assert.equal(meituanStaticApi.shouldSkipMeituanConfigDetailLoad({ cookies: '', has_cookies: false, id: 18 }), true);
  assert.equal(meituanStaticApi.shouldSkipMeituanConfigDetailLoad({ id: 18, has_cookies: true }), false);
  const cachedMeituanDetail = { id: 18, cookies: 'cached-cookie' };
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCachedResult({
    cached: { version: 'v1', data: cachedMeituanDetail },
    listVersion: 'v1',
  }))), { hit: true, data: cachedMeituanDetail });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCachedResult({
    cached: { version: 'v0', data: cachedMeituanDetail },
    listVersion: 'v1',
  }))), { hit: false, data: null });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCachedResult({
    cached: { version: 'v0', data: cachedMeituanDetail },
    listVersion: '',
  }))), { hit: true, data: cachedMeituanDetail });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCachedResult())), { hit: false, data: null });
  const meituanDetailCache = new Map([['18', { version: 'cached-version', data: cachedMeituanDetail }]]);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheLookup({
    config: { id: 18, update_time: 'cached-version' },
    cache: meituanDetailCache,
  }))), {
    cacheKey: '18',
    listVersion: 'cached-version',
    cachedResult: {
      hit: true,
      data: cachedMeituanDetail,
    },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheLookup({
    config: { id: 18, update_time: 'old-version' },
    cache: meituanDetailCache,
  }))), {
    cacheKey: '18',
    listVersion: 'old-version',
    cachedResult: {
      hit: false,
      data: null,
    },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheLookup({
    config: {},
    cache: meituanDetailCache,
  }))), {
    cacheKey: '',
    listVersion: '',
    cachedResult: {
      hit: false,
      data: null,
    },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigDetailCacheEntry({
    detail: { id: 18, updated_at: 'detail-version' },
    listVersion: 'list-version',
  }))), {
    version: 'detail-version',
    data: { id: 18, updated_at: 'detail-version' },
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.buildMeituanConfigDetailCacheEntry({
    detail: { id: 18 },
    listVersion: 'list-version',
  }))), {
    version: 'list-version',
    data: { id: 18 },
  });
  const meituanDetailStoreEntry = { version: 'detail-version', data: { id: 18 } };
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheStorePlan({
    cacheKey: 18,
    cacheEntry: meituanDetailStoreEntry,
  }))), {
    shouldStore: true,
    cacheKey: '18',
    cacheEntry: meituanDetailStoreEntry,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheStorePlan({
    cacheKey: '',
    cacheEntry: meituanDetailStoreEntry,
  }))), {
    shouldStore: false,
    cacheKey: '',
    cacheEntry: meituanDetailStoreEntry,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailCacheStorePlan({
    cacheKey: 18,
    cacheEntry: null,
  }))), {
    shouldStore: false,
    cacheKey: '18',
    cacheEntry: null,
  });
  const meituanFailureError = new Error('detail failure');
  const silentMeituanFailureAction = meituanStaticApi.resolveMeituanConfigDetailFailureAction({
    error: meituanFailureError,
    silent: true,
  });
  assert.equal(silentMeituanFailureAction.type, 'log');
  assert.equal(silentMeituanFailureAction.label, '[Meituan] 预热完整配置失败:');
  assert.equal(silentMeituanFailureAction.message, 'detail failure');
  assert.equal(silentMeituanFailureAction.error, meituanFailureError);
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailFailureAction({
    error: {},
    silent: false,
  }))), {
    type: 'toast',
    message: '加载美团完整配置失败',
    level: 'error',
    error: {},
  });
  const prewarmMeituanConfig = { id: 18, has_cookies: true };
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailPrewarmPlan({
    config: prewarmMeituanConfig,
    delayMs: 120,
  }))), {
    shouldPrewarm: true,
    config: prewarmMeituanConfig,
    delayMs: 120,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailPrewarmPlan({
    config: { id: 18, cookies: 'ready' },
    delayMs: 120,
  }))), {
    shouldPrewarm: false,
    config: null,
    delayMs: 0,
  });
  assert.deepEqual(JSON.parse(JSON.stringify(meituanStaticApi.resolveMeituanConfigDetailPrewarmPlan())), {
    shouldPrewarm: false,
    config: null,
    delayMs: 0,
  });
  assert.equal(meituanStaticApi.resolveMeituanManualDefaultHotelIdFromState({
    currentHotelId: '',
    autoFetchHotelId: 'auto-7',
    selectedCtripHotelId: 'ctrip-8',
    onlineDataHotelId: 'filter-9',
    userHotelId: 'user-10',
    hotelPool: [{ id: 'pool-11' }],
  }), 'auto-7');
  assert.equal(meituanStaticApi.resolveMeituanManualDefaultHotelIdFromState({
    currentHotelId: '',
    autoFetchHotelId: '',
    selectedCtripHotelId: '',
    onlineDataHotelId: '',
    userHotelId: '',
    hotelPool: [{ id: 'pool-11' }],
  }), 'pool-11');
  assert.match(html, /const resolveCanFetchMeituanRankingData = requireMeituanStatic\('resolveCanFetchMeituanRankingData'\);/);
  assert.match(html, /const resolveMeituanManualFetchConfigProofPending = requireMeituanStatic\('resolveMeituanManualFetchConfigProofPending'\);/);
  assert.match(html, /const resolveMeituanManualFetchConfigCandidate = requireMeituanStatic\('resolveMeituanManualFetchConfigCandidate'\);/);
  assert.match(html, /const resolveMeituanConfigListResponse = requireMeituanStatic\('resolveMeituanConfigListResponse'\);/);
  assert.match(html, /const resolveMeituanConfigListApplyAction = requireMeituanStatic\('resolveMeituanConfigListApplyAction'\);/);
  assert.match(html, /const resolveMeituanConfigListCachedResult = requireMeituanStatic\('resolveMeituanConfigListCachedResult'\);/);
  assert.match(html, /const resolveMeituanConfigListLoadingAction = requireMeituanStatic\('resolveMeituanConfigListLoadingAction'\);/);
  assert.match(html, /const buildMeituanConfigListSuccessState = requireMeituanStatic\('buildMeituanConfigListSuccessState'\);/);
  assert.match(html, /const buildMeituanConfigListFailureAction = requireMeituanStatic\('buildMeituanConfigListFailureAction'\);/);
  assert.match(html, /const buildMeituanConfigListStartState = requireMeituanStatic\('buildMeituanConfigListStartState'\);/);
  assert.match(html, /const buildMeituanConfigListFinishState = requireMeituanStatic\('buildMeituanConfigListFinishState'\);/);
  assert.match(html, /const getMeituanConfigDetailVersion = requireMeituanStatic\('getMeituanConfigDetailVersion'\);/);
  assert.match(html, /const buildMeituanConfigDetailCacheKey = requireMeituanStatic\('buildMeituanConfigDetailCacheKey'\);/);
  assert.match(html, /const resolveMeituanConfigDetailClearTarget = requireMeituanStatic\('resolveMeituanConfigDetailClearTarget'\);/);
  assert.match(html, /const resolveMeituanConfigDetailLoadTarget = requireMeituanStatic\('resolveMeituanConfigDetailLoadTarget'\);/);
  assert.match(html, /const buildMeituanConfigDetailRequestUrl = requireMeituanStatic\('buildMeituanConfigDetailRequestUrl'\);/);
  assert.match(html, /const resolveMeituanConfigDetailResponse = requireMeituanStatic\('resolveMeituanConfigDetailResponse'\);/);
  assert.match(html, /const shouldSkipMeituanConfigDetailLoad = requireMeituanStatic\('shouldSkipMeituanConfigDetailLoad'\);/);
  assert.match(html, /const resolveMeituanConfigDetailCachedResult = requireMeituanStatic\('resolveMeituanConfigDetailCachedResult'\);/);
  assert.match(html, /const resolveMeituanConfigDetailCacheLookup = requireMeituanStatic\('resolveMeituanConfigDetailCacheLookup'\);/);
  assert.match(html, /const buildMeituanConfigDetailCacheEntry = requireMeituanStatic\('buildMeituanConfigDetailCacheEntry'\);/);
  assert.match(html, /const resolveMeituanConfigDetailCacheStorePlan = requireMeituanStatic\('resolveMeituanConfigDetailCacheStorePlan'\);/);
  assert.match(html, /const resolveMeituanConfigDetailFailureAction = requireMeituanStatic\('resolveMeituanConfigDetailFailureAction'\);/);
  assert.match(html, /const resolveMeituanManualDefaultHotelIdFromState = requireMeituanStatic\('resolveMeituanManualDefaultHotelIdFromState'\);/);
  assert.doesNotMatch(meituanHotelSelectPanel, /meituanConfigListLoading && !selectedMeituanHotelConfig/);
  assert.doesNotMatch(meituanHotelSelectPanel, /正在匹配美团数据源/);
  assert.doesNotMatch(meituanHotelSelectPanel, /配置待读取，正在准备美团数据源匹配/);
  assert.doesNotMatch(meituanHotelSelectPanel, /!meituanConfigListLoading && !meituanConfigListLoaded && !meituanConfigListLoadFailed && !selectedMeituanHotelConfig/);
  assert.match(meituanHotelSelectPanel, /:disabled="fetchingData \|\| !canFetchMeituanRankingData\(\)"/);
  assert.match(fetchMeituanData, /const preparingConfig = meituanManualFetchConfigProofPending\(\);/);
  assert.doesNotMatch(fetchMeituanData, /ensureMeituanConfigSecret|cookies|auth_data/);
  assert.match(fetchMeituanData, /getSelectedConfig: \(\) => selectedMeituanHotelConfig\.value/);
  assert.match(fetchMeituanData, /finally \{\s*if \(preparingConfig && isActive\(\)\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
  assert.match(meituanManualFetchConfigGuard, /return resolveMeituanManualFetchConfigProofPending\(\{\s*form: meituanForm\.value,\s*selectedConfig: selectedMeituanHotelConfig\.value,\s*\}\);/);
  assert.match(meituanManualFetchConfigGuard, /const canFetchMeituanRankingData = \(\) => \{/);
  assert.match(meituanManualFetchConfigGuard, /return resolveCanFetchMeituanRankingData\(\{\s*form: meituanForm\.value,\s*selectedConfig: selectedMeituanHotelConfig\.value,\s*\}\);/);
  assert.doesNotMatch(meituanManualFetchConfigGuard, /await loadMeituanConfigList\(/);
  assert.match(meituanManualFetchConfigGuard, /return resolveMeituanManualFetchConfigCandidate\(\{\s*config,\s*form: meituanForm\.value,\s*selectedConfig: selectedMeituanHotelConfig\.value,\s*\}\);/);
  assert.doesNotMatch(meituanHotelSelectPanel, /@change="applyMeituanHotelConfig/);
  assert.match(meituanHotelWatcher, /if \(onlineDataTab\.value === 'meituan-ranking'\) \{/);
  assert.match(meituanHotelWatcher, /scheduleMeituanHotelConfigApply\(\{ delayMs: 0 \}\);/);
  assert.match(html, /let meituanHotelConfigApplyVersion = 0;/);
  assert.match(html, /requestedHotelId !== String\(meituanForm\.value\.hotelId \|\| ''\)/);
  assert.doesNotMatch(handleMenuClick, /await loadCompetitorSummary\(\)/);
  assert.match(handleMenuClick, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.match(currentPageWatcher, /scheduleMeituanEbookingDeferredStartupRefresh\(\);/);
  assert.match(currentPageWatcher, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(html, /const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS = 16;/);
  assert.match(html, /const MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS = 5200;/);
  assert.match(html, /const MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS = 6400;/);
  assert.match(html, /let suppressNextMeituanHotelConfigApply = false;/);
  assert.match(resolveMeituanManualDefaultHotelId, /return resolveMeituanManualDefaultHotelIdFromState\(\{/);
  assert.match(resolveMeituanManualDefaultHotelId, /currentHotelId: meituanForm\.value\.hotelId/);
  assert.match(resolveMeituanManualDefaultHotelId, /autoFetchHotelId\.value/);
  assert.match(resolveMeituanManualDefaultHotelId, /selectedCtripHotelId\.value/);
  assert.match(resolveMeituanManualDefaultHotelId, /onlineDataHotelId: onlineDataFilter\.value\.hotel_id/);
  assert.match(resolveMeituanManualDefaultHotelId, /userHotelId: user\.value\?\.hotel_id/);
  assert.match(resolveMeituanManualDefaultHotelId, /hotelPool,/);
  assert.match(ensureMeituanManualHotelSelected, /suppressNextMeituanHotelConfigApply = true;/);
  assert.match(ensureMeituanManualHotelSelected, /meituanForm\.value\.hotelId = hotelId;/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /applySelectedConfig: false/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /scheduleMeituanHotelConfigApply\(\{\s*delayMs: 0,\s*refreshList: false,\s*skipIfAligned: true,\s*\}\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /return null;\s*\}, 0\);\s*scheduleDelayedPageTask\(\(\) => \{/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /return loadMeituanConfig\(\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /}, 2400\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /}, 3000\);/);
  assert.doesNotMatch(applyMeituanHotelConfig, /await loadCompetitorSummary\(\)/);
  assert.match(html, /const buildMeituanBrowserCaptureConfigSyncState = requireMeituanStatic\('buildMeituanBrowserCaptureConfigSyncState'\);/);
  assert.match(meituanStatic, /const buildMeituanBrowserCaptureConfigSyncState = \(\{[\s\S]*source\.poi_id,[\s\S]*source\.poiId,[\s\S]*source\.store_id,[\s\S]*source\.storeId,[\s\S]*formPoiId,[\s\S]*captureForm\.storeId/);
  assert.match(html, /const syncState = buildMeituanBrowserCaptureConfigSyncState\(\{[\s\S]*formPoiId: meituanForm\.value\.poiId,[\s\S]*captureForm: meituanBrowserCaptureForm\.value,/);
  assert.match(applyMeituanHotelConfig, /const requestedHotelId = String\(meituanForm\.value\.hotelId \|\| ''\);/);
  assert.match(applyMeituanHotelConfig, /if \(requestedHotelId !== String\(meituanForm\.value\.hotelId \|\| ''\)\) return;/);
  assert.doesNotMatch(applyMeituanHotelConfig, /options\.refreshList !== false/);
  assert.doesNotMatch(applyMeituanHotelConfig, /await loadMeituanConfigList\(/);
  assert.doesNotMatch(applyMeituanHotelConfig, /applySelectedConfig: false/);
  assert.match(applyMeituanHotelConfig, /options\.skipIfAligned === true && config && isMeituanRankingFormAlignedWithConfig\(meituanForm\.value, config\)/);
  assert.doesNotMatch(applyMeituanHotelConfig, /scheduleMeituanRankingSummaryRefresh/);
  assert.match(openMeituanManualTab, /applySelectedConfig: false/);
  assert.match(openMeituanManualTab, /skipIfAligned: true/);
  assert.match(openMeituanManualTab, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(loadMeituanConfigList, /const configListResult = resolveMeituanConfigListResponse\(res\);/);
  assert.match(loadMeituanConfigList, /if \(configListResult\.ok\) \{/);
  assert.match(loadMeituanConfigList, /const successState = buildMeituanConfigListSuccessState\(\{/);
  assert.match(loadMeituanConfigList, /list: configListResult\.list/);
  assert.match(loadMeituanConfigList, /meituanConfigList\.value = successState\.list;/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoaded\.value = successState\.loaded;/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoadedAt = successState\.loadedAt;/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigList\.value = configListResult\.list;/);
  assert.match(loadMeituanConfigList, /const applyAction = resolveMeituanConfigListApplyAction\(\{/);
  assert.match(loadMeituanConfigList, /hotelId: meituanForm\.value\.hotelId/);
  assert.match(loadMeituanConfigList, /if \(applyAction\.shouldApply\) \{/);
  assert.match(loadMeituanConfigList, /console\.error\(failureAction\.label, failureAction\.detail\);/);
  assert.doesNotMatch(loadMeituanConfigList, /if \(res\.code === 200\) \{/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigList\.value = res\.data \|\| \[\];/);
  assert.match(meituanHotelWatcher, /if \(suppressNextMeituanHotelConfigApply\) \{[\s\S]*suppressNextMeituanHotelConfigApply = false;[\s\S]*return;/);
  assert.match(loadMeituanConfigDetail, /const findMeituanConfigMetadataById = \(id\) => \{/);
  assert.match(loadMeituanConfigDetail, /const resolveMeituanConfigMetadata = \(config\) => config \|\| null;/);
  assert.doesNotMatch(loadMeituanConfigDetail, /request\(|ensureMeituanConfigSecret|config\.cookies|auth_data/);
  assert.match(loadMeituanConfigList, /const force = options\.force === true;/);
  assert.match(loadMeituanConfigList, /const cachedResult = resolveMeituanConfigListCachedResult\(\{/);
  assert.match(loadMeituanConfigList, /loaded: meituanConfigListLoaded\.value/);
  assert.match(loadMeituanConfigList, /cacheFresh: isManualConfigListCacheFresh\(meituanConfigListLoadedAt, options\)/);
  assert.match(loadMeituanConfigList, /if \(cachedResult\.hit\) \{/);
  assert.match(loadMeituanConfigList, /return cachedResult\.list;/);
  assert.match(loadMeituanConfigList, /const loadingAction = resolveMeituanConfigListLoadingAction\(\{/);
  assert.match(loadMeituanConfigList, /loadingPromise: meituanConfigListLoadingPromise/);
  assert.match(loadMeituanConfigList, /if \(loadingAction\.status === 'reuse'\) \{/);
  assert.match(loadMeituanConfigList, /return loadingAction\.promise;/);
  assert.match(loadMeituanConfigList, /if \(loadingAction\.status === 'await_previous'\) \{/);
  assert.match(loadMeituanConfigList, /await loadingAction\.promise\.catch\(\(\) => \[\]\);/);
  assert.doesNotMatch(loadMeituanConfigList, /!force\s*&& meituanConfigListLoaded\.value/);
  assert.doesNotMatch(loadMeituanConfigList, /if \(meituanConfigListLoadingPromise\) \{/);
  assert.match(html, /const meituanConfigListLoading = ref\(false\);/);
  assert.match(loadMeituanConfigList, /const startState = buildMeituanConfigListStartState\(\);/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoading\.value = startState\.loading;/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoadFailed\.value = startState\.failed;/);
  assert.match(loadMeituanConfigList, /const finishState = buildMeituanConfigListFinishState\(\);/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoadingPromise = finishState\.loadingPromise;/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoading\.value = finishState\.loading;/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigListLoading\.value = true;\s*meituanConfigListLoadFailed\.value = false;/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigListLoadingPromise = null;\s*meituanConfigListLoading\.value = false;/);
  assert.match(loadMeituanConfigList, /const shouldApplySelectedConfig = options\.applySelectedConfig === true;/);
  assert.match(loadMeituanConfigList, /const applyAction = resolveMeituanConfigListApplyAction\(\{/);
  assert.match(loadMeituanConfigList, /if \(applyAction\.shouldApply\) \{/);
  assert.match(loadMeituanConfigList, /deferUiTask\(\(\) => \(\s*isAuthSessionCurrent\(requestSession\)\s*\? applyMeituanHotelConfig\(false, \{ refreshList: false \}\)\s*: null\s*\), 80\);/);
  assert.match(loadMeituanConfigList, /const failureAction = buildMeituanConfigListFailureAction\(\{\s*type: 'api',\s*message: configListResult\.message,/);
  assert.match(loadMeituanConfigList, /const failureAction = buildMeituanConfigListFailureAction\(\{\s*type: 'exception',\s*error: e,/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoadFailed\.value = failureAction\.failed;/);
  assert.match(loadMeituanConfigList, /console\.error\(failureAction\.label, failureAction\.detail\);/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigListLoadFailed\.value = true;\s*console\.error\('\[Debug\] API 返回错误:', configListResult\.message\);/);
  assert.doesNotMatch(loadMeituanConfigList, /meituanConfigListLoadFailed\.value = true;\s*console\.error\('\[Debug\] 加载美团配置列表失败:', e\);/);
});

test('Ctrip manual startup keeps config list responsive without first-paint blocking', () => {
  const scheduleCtripEbookingDeferredStartupRefresh = sliceFrom(
    'const scheduleCtripEbookingDeferredStartupRefresh = () => {',
    '\n            const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS'
  );
  const ctripEbookingDefaultLoader = sliceFrom(
    "if (newPage === 'ctrip-ebooking') {",
    "\n                if (newPage === 'meituan-ebooking')"
  );
  const ctripSecondaryScheduler = sliceFrom(
    'const clearCtripEbookingSecondaryPanelsReadyTimer = () => {',
    '\n            const shouldRefreshAutoFetchStatusPanel'
  );

  assert.match(html, /const CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS = 1600;/);
  assert.match(html, /const CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS = 2600;/);
  assert.match(html, /const CTRIP_EBOOKING_LATEST_DATA_DELAY_MS = 5200;/);
  assert.match(html, /const CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS = 6400;/);
  assert.match(html, /const CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS = 7600;/);
  assert.match(html, /const CTRIP_EBOOKING_MODULE_CARD_DELAY_MS = 1000;/);
  assert.match(html, /const ctripEbookingModuleCardsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const ctripEbookingSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS = 6200;/);
  assert.match(html, /const ctripEbookingDeepPanelsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS = 8200;/);
  assert.match(html, /const ctripEbookingBusinessDetailsReady = ref\(false\);/);
  assert.match(html, /const ctripEbookingDiagnosticsPanelsReady = ref\(false\);/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingModuleCardsReady = \(delayMs = CTRIP_EBOOKING_MODULE_CARD_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingSecondaryPanelsReady = \(delayMs = CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingDeepPanelsReady = \(delayMs = CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingBusinessDetailsReady = \(delayMs = CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const handleCtripEbookingDiagnosticsToggle = \(event\) => \{\s*if \(event\?\.target\?\.open\) \{\s*ctripEbookingDiagnosticsPanelsReady\.value = true;/);
  assert.match(ctripSecondaryScheduler, /currentPage\.value === 'ctrip-ebooking' && onlineDataTab\.value === 'data-health'/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingModuleCardsReady\.value = false;\s*scheduleCtripEbookingModuleCardsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingSecondaryPanelsReady\.value = false;\s*scheduleCtripEbookingSecondaryPanelsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingDeepPanelsReady\.value = false;\s*scheduleCtripEbookingDeepPanelsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingBusinessDetailsReady\.value = false;\s*scheduleCtripEbookingBusinessDetailsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(html, /<div v-if="ctripEbookingModuleCardsReady" class="px-4 py-3 border-b bg-gray-50 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-2">/);
  assert.match(html, /v-if="ctripEbookingModuleCardsReady" data-testid="ctrip-overview-module-cards" class="p-4"/);
  assert.match(html, /v-if="ctripEbookingSecondaryPanelsReady" class="space-y-4"/);
  assert.match(html, /v-if="ctripEbookingDeepPanelsReady" class="space-y-4"/);
  assert.match(html, /v-if="ctripEbookingBusinessDetailsReady" data-testid="ctrip-store-overview-business-details" class="space-y-4"/);
  assert.match(html, /data-testid="ctrip-store-overview-diagnostics"[^>]+@toggle="handleCtripEbookingDiagnosticsToggle"/);
  assert.match(html, /v-if="ctripEbookingDiagnosticsPanelsReady" class="p-4 border-t space-y-4"/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /loadLatestCtripData\(\{ silent: true, hydrateDisplay: true \}\);/);
  assert.match(ctripEbookingDefaultLoader, /setOnlineDataTabFromPage\('ctrip-ranking'\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /scheduleCtripHotelConfigApply\(null, \{\s*refreshList: false,\s*skipIfAligned: true,\s*\}\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /if \(currentPage\.value !== 'ctrip-ebooking'\) return null;/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_LATEST_DATA_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 1800\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 2400\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 3000\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 3600\);/);
});

test('Meituan orders and ads remain network-required workflows', () => {
  const fetchMeituanOrdersData = constSlice('const fetchMeituanOrdersData = async () => runMeituanOrderFetchFlow({');
  const fetchMeituanAdsData = constSlice('const fetchMeituanAdsData = async () => runMeituanAdsFetchFlow({');
  assert.match(meituanStatic, /需 Network 请求信息/);
  assert.match(meituanStatic, /请填写订单接口 Request URL/);
  assert.match(meituanStatic, /请填写广告接口 Request URL/);
  assert.match(fetchMeituanOrdersData, /runMeituanOrderFetchFlow\(\{/);
  assert.match(fetchMeituanAdsData, /runMeituanAdsFetchFlow\(\{/);
});

test('Ctrip ads only exposes the effect report workflow', () => {
  const adsPanel = sliceFrom('<div v-if="onlineDataTab === \'ctrip-ads\'">', '<div v-if="onlineDataTab === \'ctrip-overview\'">');
  const adsConfigPanel = sliceFrom('<!-- 携程广告配置 -->', '<!-- 美团广告配置 -->');

  assert.match(adsPanel, /效果报表/);
  assert.match(adsPanel, /高级排障接口地址（可选）/);
  assert.doesNotMatch(adsPanel, /推广活动列表/);
  assert.doesNotMatch(adsPanel, /推广活动报表/);
  assert.doesNotMatch(adsPanel, /广告接口 URL <span class="text-red-500">\*<\/span>/);
  assert.doesNotMatch(adsPanel, /v-model="ctripAdsBrowserCaptureForm\.apiType"/);
  const todayOptionIndex = adsPanel.indexOf('<option value="today">');
  const yesterdayOptionIndex = adsPanel.indexOf('<option value="yesterday">');
  assert.ok(todayOptionIndex >= 0, 'missing today option');
  assert.ok(yesterdayOptionIndex >= 0, 'missing yesterday option');
  assert.ok(todayOptionIndex < yesterdayOptionIndex, 'today option should appear before yesterday');
  assert.match(adsConfigPanel, /效果报表/);
  assert.match(adsConfigPanel, /效果报表接口URL（可选）/);
  assert.doesNotMatch(adsConfigPanel, /推广活动列表/);
  assert.doesNotMatch(adsConfigPanel, /推广活动报表/);
  assert.doesNotMatch(adsConfigPanel, /v-if="dataConfigForm\.api_type === 'campaign_report'"/);
  assert.match(html, /requireCtripStatic\('defaultCtripAdsEffectReportUrl'\)/);
  assert.match(html, /requireCtripStatic\('normalizeCtripAdsApiType'\)/);
  assert.match(html, /requireCtripStatic\('runCtripAdsFetchFlow'\)/);
  assert.match(ctripStatic, /const defaultCtripAdsEffectReportUrl =/);
  assert.match(ctripStatic, /const normalizeCtripAdsApiType = \(value = ''\) =>/);
  assert.match(ctripStatic, /const buildCtripAdsFetchRequestBody = \(\{/);
  assert.match(ctripStatic, /const url = String\(form\.url \|\| defaultAdsUrl\)\.trim\(\);/);
  assert.match(ctripStatic, /const requestBody = buildCtripAdsFetchRequestBody\(\{/);
  assert.doesNotMatch(html, /const ctripAdsFetchBody = buildCtripAdsFetchRequestBody\(\{/);
  assert.doesNotMatch(html, /api_type: normalizeCtripAdsApiType\(form\.apiType\)/);
});

test('Platform auto-fetch panel prewarms static helper without blocking first paint', () => {
  const loadAutoFetchPanel = sliceFrom(
    'const loadAutoFetchPanel = async (options = {}) => {',
    '\n            const autoFetchStatusRequestPromises'
  );
  const autoFetchPanelArea = sliceFrom(
    'const AUTO_FETCH_PANEL_CACHE_TTL_MS = 45000;',
    '\n            const autoFetchStatusRequestPromises'
  );
  const triggerAutoFetch = sliceFrom(
    'const triggerAutoFetch = async (options = {}) => {',
    '\n\n            const retryAutoFetchDate'
  );
  const autoFetchTriggerGuard = sliceFrom(
    'const autoFetchConfigProofPendingForHotelId = (hotelId) => {',
    '\n\n            const ensureHotelOtaConfigLists'
  );
  const loadHotels = sliceFrom('const HOTEL_LIST_CACHE_TTL_MS = 30000;', '\n\n            const getHotelNameById');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.doesNotMatch(loadAutoFetchPanel, /const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/);
  assert.match(html, /const prewarmAutoFetchStaticForPlatformAuto = \(\) => \{/);
  assert.match(html, /if \(!isVisibleOnlineDataTab\('platform-auto'\)\) return null;/);
  assert.match(html, /const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/);
  assert.match(html, /void staticReadyPromise;/);
  assert.match(autoFetchPanelArea, /const PLATFORM_AUTO_PANEL_START_DELAY_MS = 16;/);
  assert.match(html, /const AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS = AUTO_FETCH_PANEL_CACHE_TTL_MS;/);
  assert.match(html, /const PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS = 800;/);
  assert.match(html, /const platformAutoSettingsPanelsReady = ref\(false\);/);
  assert.match(html, /const platformAutoSettingsPanelsBody = shallowRef\(null\);/);
  assert.match(html, /const platformAutoPanelsScript = 'components\/online-data\/platform-auto-settings-panels\.js\?v=[^']+';/);
  assert.match(html, /const ensurePlatformAutoPanelsReady = async \(\) => \{/);
  assert.match(html, /requireOnlineDataComponent\('PlatformAutoSettingsPanelsBody'\)/);
  assert.match(html, /requireOnlineDataComponent\('PlatformAutoSecondaryPanelsBody'\)/);
  assert.doesNotMatch(html, /<script src="components\/online-data\/platform-auto-settings-panels\.js/);
  assert.match(html, /<platform-auto-settings-panels\s+v-if="platformAutoSettingsPanelsReady"\s+:ctx="\$root">/);
  assert.ok(
    html.indexOf('@click="triggerAutoFetch"') < html.indexOf('<platform-auto-settings-panels'),
    'platform-auto immediate collect button must stay above delayed settings panels'
  );
  assert.match(html, /data-testid="platform-auto-settings-panels-loading"/);
  assert.match(platformAutoSettingsPanels, /components\.PlatformAutoSettingsPanelsBody/);
  assert.match(platformAutoSettingsPanels, /data-testid="platform-auto-settings-panels"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchRealtimeIntervalHours"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchScheduleMinute"/);
  assert.match(platformAutoSettingsPanels, /v-model="ctx\.autoFetchBrowserHeadless"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchCtripSectionConcurrency"/);
  assert.doesNotMatch(html, /实时采集间隔（小时）/);
  assert.match(html, /const PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS = 2600;/);
  assert.match(html, /const platformAutoSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const platformAutoSecondaryPanelsBody = shallowRef\(null\);/);
  assert.match(html, /void ensurePlatformAutoPanelsReady\(\)\.catch/);
  assert.match(html, /prewarmAutoFetchStaticForPlatformAuto\(\);/);
  assert.match(html, /platformAutoSettingsPanelsReady\.value = false;\s*schedulePlatformAutoSettingsPanelsReady\(\);/);
  assert.match(html, /<platform-auto-secondary-panels\s+v-if="platformAutoSecondaryPanelsReady"\s+:ctx="\$root">/);
  assert.match(html, /data-testid="platform-auto-secondary-panels-loading"/);
  assert.match(platformAutoSettingsPanels, /components\.PlatformAutoSecondaryPanelsBody/);
  assert.match(platformAutoSettingsPanels, /data-testid="platform-auto-secondary-panels"/);
  assert.match(platformAutoSettingsPanels, /ctx\.autoFetchCollectionBlueprintRows/);
  assert.match(platformAutoSettingsPanels, /ctx\.meituanPlatformProfileStatusRow/);
  assert.match(platformAutoSettingsPanels, /ctx\.autoFetchPlatformResultRows/);
  assert.doesNotMatch(html, /采集闭环/);
  assert.match(html, /platformAutoSecondaryPanelsReady\.value = false;\s*schedulePlatformAutoSecondaryPanelsReady\(\);\s*return runIfCurrent\(\(\) => schedulePlatformAutoFetchPanelLoad\(options\)\);/);
  assert.match(autoFetchPanelArea, /const waitForPlatformAutoPanelStart = async \(options = \{\}\) => \{/);
  assert.match(loadAutoFetchPanel, /if \(!await waitForPlatformAutoPanelStart\(options\)\) \{\s*return;\s*\}/);
  assert.match(loadAutoFetchPanel, /const defaultAutoFetchHotelId = getAutoFetchHotelId\(\);\s*if \(!autoFetchHotelId\.value && defaultAutoFetchHotelId\) \{\s*autoFetchHotelId\.value = defaultAutoFetchHotelId;\s*\}/);
  assert.match(loadAutoFetchPanel, /let panelLoaded = false;/);
  assert.match(loadAutoFetchPanel, /const hotelsPromise = shouldLoadHotels \? loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\) : Promise\.resolve\(\);/);
  assert.match(
    loadAutoFetchPanel,
    /await Promise\.all\(\[\s*loadAutoFetchStatus\(\{ detail: false \}\),\s*hotelsPromise,\s*\]\);/
  );
  assert.match(loadAutoFetchPanel, /await loadAutoFetchStatus\(\{ detail: false \}\);/);
  assert.match(loadAutoFetchPanel, /if \(panelLoaded\) \{\s*autoFetchPanelCache = \{/);
  assert.match(loadAutoFetchPanel, /else if \(autoFetchPanelCache\.promise === run\) \{\s*autoFetchPanelCache = \{ key: '', expiresAt: 0, promise: null \};\s*\}/);
  assert.doesNotMatch(
    loadAutoFetchPanel,
    /loadAutoFetchStatus\(\{ detail: false \}\),\s*staticReadyPromise/
  );
  assert.doesNotMatch(loadAutoFetchPanel, /staticReadyPromise,\s*hotelsPromise/);
  assert.doesNotMatch(html, /\b(?:fab|far)\s+fa-/);
  assert.match(loadHotels, /const hotelListResultCache = new Map\(\);/);
  assert.match(loadHotels, /const cacheMs = Number\(options\.cacheMs \|\| 0\);/);
  assert.match(loadHotels, /readRequestCache\(hotelListResultCache, requestKey, cacheMs\)/);
  assert.match(loadHotels, /writeRequestCache\(hotelListResultCache, requestKey, cacheMs\);/);
  assert.match(loadHotels, /const scheduleStartupHotelListLoad = \(delayMs = null\) => \{/);
  assert.match(loadHotels, /if \(!hasKnownHotelOptions\(\)\) \{/);
  assert.match(loadHotels, /return loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(loadHotels, /if \(!isLoggedIn\.value \|\| !token\.value \|\| isCoreOtaPageVisible\(\)\) return null;/);
  assert.match(loadData, /scheduleStartupHotelListLoad\(\);/);
  assert.doesNotMatch(loadData, /loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(triggerAutoFetch, /await ensureAutoFetchStaticReady\(\);/);
  assert.match(triggerAutoFetch, /requireAutoFetchStatic\('runAutoFetchTriggerFlow'\)/);
  assert.match(triggerAutoFetch, /hasPlatformFetchConfig: canTriggerAutoFetchByHotelId,/);
  assert.match(autoFetchTriggerGuard, /autoFetchStatusRequestPromises\.has\(`\$\{keyPrefix\}light`\)/);
  assert.match(autoFetchTriggerGuard, /autoFetchStatusRequestPromises\.has\(`\$\{keyPrefix\}full`\)/);
  assert.match(autoFetchTriggerGuard, /const canTriggerAutoFetchByHotelId = \(hotelId\) => \{/);
  assert.match(autoFetchTriggerGuard, /hasAnyPlatformFetchConfigByHotelId\(hotelId\)\s*\|\|\s*autoFetchConfigProofPendingForHotelId\(hotelId\)/);
  assert.match(autoFetchTriggerGuard, /getBrowserProfileDataSourceByHotelAndPlatform\(hotelId, 'ctrip'\)/);
  assert.match(autoFetchTriggerGuard, /getBrowserProfileDataSourceByHotelAndPlatform\(hotelId, 'meituan'\)/);
  assert.equal((html.match(/:disabled="fetchingData \|\| !canTriggerAutoFetchByHotelId\(autoFetchHotelId\)"/g) || []).length, 2);
});

test('Platform source panel staggers secondary sync and log reads', () => {
  const loadPlatformDataSourcePanel = sliceFrom(
    'const loadPlatformDataSourcePanel = async (options = {}) => {',
    '\n            const savePlatformDataSource = async'
  );
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );

  assert.match(html, /const PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS = 3200;/);
  assert.match(html, /const PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS = 1200;/);
  assert.match(html, /const PLATFORM_SOURCE_PANEL_CACHE_TTL_MS = 30000;/);
  assert.match(html, /const platformSourceGuidePanelsReady = ref\(false\);/);
  assert.match(html, /const schedulePlatformSourceGuidePanelsReady = \(delayMs = PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS\) => \{/);
  assert.match(html, /v-if="platformSourceGuidePanelsReady" data-testid="platform-account-binding-guide"/);
  assert.match(html, /v-if="platformSourceGuidePanelsReady" data-testid="platform-batch-health-check"/);
  assert.match(html, /const competitorSummaryRequestPromises = new Map\(\);/);
  assert.match(html, /const competitorSummaryResultCache = new Map\(\);/);
  assert.match(scheduleOnlineDataTabLoad, /if \(newTab === 'platform-sources'\) \{\s*platformSourceGuidePanelsReady\.value = false;\s*schedulePlatformSourceGuidePanelsReady\(\);/);
  assert.match(loadPlatformDataSourcePanel, /await Promise\.allSettled\(\[\s*loadPlatformDataSources\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformProfileStatus\(\{\s*silent: true,\s*cacheMs: options\.force \? 0 : PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS,/);
  assert.match(loadPlatformDataSourcePanel, /scheduleDelayedPageTask\(\(\) => \{\s*if \(!shouldRefreshPlatformDataSourcesPanel\(\)\) return null;\s*return Promise\.allSettled\(\[/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformSyncTasks\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformSyncLogs\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformCollectionResources\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadCompetitorSummary\(\{\s*includeByHotel: true,\s*force: options\.force === true,\s*cacheMs: options\.force \? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,\s*\}\)/);
  assert.match(loadPlatformDataSourcePanel, /\}, PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS\);/);
  assert.match(html, /platformDataSourceHotelOptions, platformSourceGuidePanelsReady, loadPlatformDataSourcePanel/);
  assert.doesNotMatch(loadPlatformDataSourcePanel, /deferUiTask\(\(\) => \{\s*if \(!shouldRefreshPlatformDataSourcesPanel\(\)\) return null;\s*return Promise\.allSettled\(\[\s*loadPlatformSyncTasks\(\{/);
});

test('Online data health tab schedules light refresh outside the switch path', () => {
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );
  const openCtripManualTab = sliceFrom(
    'const openCtripManualTab = (tab) => {',
    '\n            const openMeituanManualTab'
  );
  const goAiDailyReportDataGap = sliceFrom(
    'const goAiDailyReportDataGap = async (gap) => {',
    '\n            const operationExecutionItems'
  );
  const onlineDataDefaultLoader = sliceFrom(
    "if (newPage === 'online-data' && token.value) {",
    "\n                if (newPage === 'operation-logs')"
  );
  const openOnlineDataEntryTab = sliceFrom(
    "const openOnlineDataEntryTab = (tab = 'data-health', options = {}) => {",
    '\n            const openOnlineDataManualEntry'
  );
  const dataHealthSecondaryScheduler = sliceFrom(
    'const clearDataHealthSecondaryPanelsReadyTimer = () => {',
    '\n            const shouldRefreshAutoFetchStatusPanel'
  );

  assert.match(
    scheduleOnlineDataTabLoad,
    /scheduleDataHealthPanelRefresh\('light', options\.force \? \{ force: true \} : \{\}\)/
  );
  assert.match(html, /const DATA_HEALTH_SECONDARY_PANEL_DELAY_MS = 900;/);
  assert.match(html, /const DATA_HEALTH_DETAIL_PANEL_DELAY_MS = 2600;/);
  assert.match(html, /const DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const dataHealthSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const dataHealthDetailPanelsReady = ref\(false\);/);
  assert.match(html, /const dataHealthEmployeePanelsReady = ref\(false\);/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthSecondaryPanelsReady = \(delayMs = DATA_HEALTH_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthDetailPanelsReady = \(delayMs = DATA_HEALTH_DETAIL_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthEmployeePanelsReady = \(delayMs = DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /currentPage\.value !== 'online-data' \|\| onlineDataTab\.value !== 'data-health'/);
  assert.match(scheduleOnlineDataTabLoad, /newTab === 'data-health'[\s\S]*dataHealthSecondaryPanelsReady\.value = false;[\s\S]*scheduleDataHealthSecondaryPanelsReady\(\);[\s\S]*dataHealthDetailPanelsReady\.value = false;[\s\S]*scheduleDataHealthDetailPanelsReady\(\);[\s\S]*dataHealthEmployeePanelsReady\.value = false;[\s\S]*scheduleDataHealthEmployeePanelsReady\(\);/);
  assert.match(onlineDataDefaultLoader, /dataHealthSecondaryPanelsReady\.value = false;\s*scheduleDataHealthSecondaryPanelsReady\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*scheduleDataHealthDetailPanelsReady\(\);\s*dataHealthEmployeePanelsReady\.value = false;\s*scheduleDataHealthEmployeePanelsReady\(\);/);
  assert.match(openOnlineDataEntryTab, /clearDataHealthSecondaryPanelsReadyTimer\(\);\s*dataHealthSecondaryPanelsReady\.value = false;\s*clearDataHealthDetailPanelsReadyTimer\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*clearDataHealthEmployeePanelsReadyTimer\(\);\s*dataHealthEmployeePanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /clearPlatformAutoSettingsPanelsReadyTimer\(\);\s*platformAutoSettingsPanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /clearPlatformAutoSecondaryPanelsReadyTimer\(\);\s*platformAutoSecondaryPanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /onlineDataTab\.value = targetTab;\s*currentPage\.value = 'online-data';/);
  assert.match(html, /v-if="dataHealthFullDiagnosticsLoaded && dataHealthEmployeePanelsReady" data-testid="phase1-employee-six-question-summary"/);
  assert.match(html, /v-if="dataHealthFullDiagnosticsLoaded && dataHealthSecondaryPanelsReady" data-testid="data-health-command-center"/);
  assert.doesNotMatch(html, /data-testid="hotel-data-cockpit-pending"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="hotel-data-cockpit"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="data-health-drilldown"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="mixed-collection-lifecycle-panel"/);
  assert.match(html, /data-testid="data-health-full-diagnostics-detail"/);
  assert.doesNotMatch(scheduleOnlineDataTabLoad, /return runIfCurrent\(\(\) => loadDataHealthPanel\('light'\)\);/);
  assert.match(
    onlineDataDefaultLoader,
    /runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleDataHealthPanelRefresh\('light'\);\s*return null;\s*\}\);/
  );
  assert.doesNotMatch(onlineDataDefaultLoader, /runPageLoadOnce\(newPage, 'main', \(\) => loadDataHealthPanel\('light'\)\);/);
  assert.match(openCtripManualTab, /loadDataHealthPanel:\s*scheduleDataHealthPanelRefresh/);
  assert.match(openCtripManualTab, /tab === 'data-health'[\s\S]*ctripEbookingSecondaryPanelsReady\.value = false;[\s\S]*scheduleCtripEbookingSecondaryPanelsReady\(\);[\s\S]*ctripEbookingDeepPanelsReady\.value = false;[\s\S]*scheduleCtripEbookingDeepPanelsReady\(\);[\s\S]*ctripEbookingBusinessDetailsReady\.value = false;[\s\S]*scheduleCtripEbookingBusinessDetailsReady\(\);[\s\S]*ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(openCtripManualTab, /clearCtripEbookingSecondaryPanelsReadyTimer\(\);[\s\S]*ctripEbookingSecondaryPanelsReady\.value = false;[\s\S]*clearCtripEbookingDeepPanelsReadyTimer\(\);[\s\S]*ctripEbookingDeepPanelsReady\.value = false;[\s\S]*clearCtripEbookingBusinessDetailsReadyTimer\(\);[\s\S]*ctripEbookingBusinessDetailsReady\.value = false;[\s\S]*ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(openCtripManualTab, /applySelectedConfig: false/);
  assert.match(openCtripManualTab, /refreshLatest: false/);
  assert.match(openCtripManualTab, /skipIfAligned: true/);
  assert.doesNotMatch(openCtripManualTab, /loadDataHealthPanel,\s*loadConfigList/);
  assert.match(goAiDailyReportDataGap, /currentPage\.value = 'online-data';\s*onlineDataTab\.value = 'data-health';\s*dataHealthSecondaryPanelsReady\.value = false;\s*scheduleDataHealthSecondaryPanelsReady\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*scheduleDataHealthDetailPanelsReady\(\);\s*dataHealthEmployeePanelsReady\.value = false;\s*scheduleDataHealthEmployeePanelsReady\(\);\s*scheduleDataHealthPanelRefresh\('light'\);/);
  assert.doesNotMatch(goAiDailyReportDataGap, /await loadDataHealthPanel\('light'\);/);
  assert.match(html, /const MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS = 60;/);
  assert.match(html, /const MANUAL_ONLINE_FETCH_CONFIG_TABS = new Set\(\['ctrip', 'meituan', 'custom'\]\);/);
  assert.match(html, /const shouldPrewarmManualOnlineFetchConfig = \(newTab\) => MANUAL_ONLINE_FETCH_CONFIG_TABS\.has\(String\(newTab \|\| ''\)\);/);
  assert.match(html, /const scheduleManualOnlineFetchConfigPrewarm = \(newTab, delayMs = MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS\) => \{[\s\S]*if \(!isVisibleOnlineDataTab\(newTab\)\) return;[\s\S]*ensureManualOnlineFetchConfigReady\(\);/);
  assert.match(scheduleOnlineDataTabLoad, /const shouldPrewarmManualConfig = shouldPrewarmManualOnlineFetchConfig\(newTab\);/);
  assert.match(scheduleOnlineDataTabLoad, /if \(!shouldPrewarmManualConfig\) \{\s*clearManualOnlineFetchConfigPrewarmTimer\(\);\s*\}/);
  assert.match(scheduleOnlineDataTabLoad, /if \(newTab === 'data'\) \{[\s\S]*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);[\s\S]*return undefined;[\s\S]*\}/);
  assert.doesNotMatch(sliceFrom("if (newTab === 'data') {", "if (shouldPrewarmManualConfig) {"), /scheduleManualOnlineFetchConfigPrewarm/);
  assert.match(scheduleOnlineDataTabLoad, /if \(shouldPrewarmManualConfig\) \{\s*scheduleManualOnlineFetchConfigPrewarm\(newTab, options\.configPrewarmDelayMs\);\s*return undefined;\s*\}/);
  assert.doesNotMatch(scheduleOnlineDataTabLoad, /ensureManualOnlineFetchConfigReady\(\);\s*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);/);
});

test('Online analysis tab reuses recent analysis and detail reads during tab returns', () => {
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );
  const analysisCache = sliceFrom(
    'const ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS = 8000;',
    '\n            const onlineAnalysisSourceText'
  );
  const loadAnalysisData = sliceFrom(
    'const loadAnalysisData = async (dimension = null, options = {}) => {',
    '\n\n            // 渲染分析图表'
  );
  const loadOnlineAnalysisRows = sliceFrom(
    'const loadOnlineAnalysisRows = async (options = {}) => {',
    '\n\n            const resolveDefaultOnlineAnalysisHotelId'
  );
  const refreshOnlineAnalysis = sliceFrom(
    'const refreshOnlineAnalysis = async (options = {}) => {',
    '\n\n            const openOnlineAnalysisTab'
  );
  const clearOnlineDataReadCaches = sliceFrom(
    'const clearOnlineDataReadCaches = () => {',
    '\n\n            const loadOnlineDataList'
  );

  assert.match(analysisCache, /const onlineAnalysisDataResultCache = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisRowsResultCache = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisDataRequestPromises = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisRowsRequestPromises = new Map\(\);/);
  assert.match(analysisCache, /const clearOnlineAnalysisReadCaches = \(\) => \{/);
  assert.match(analysisCache, /const readOnlineAnalysisResultCache = \(cache, key, cacheMs\) => \{/);
  assert.match(analysisCache, /const writeOnlineAnalysisResultCache = \(cache, key, data, cacheMs\) => \{/);
  assert.match(loadAnalysisData, /const cacheMs = Number\(options\?\.cacheMs \?\? ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS\);/);
  assert.match(loadAnalysisData, /const cached = readOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, cacheMs\);/);
  assert.match(loadAnalysisData, /if \(onlineAnalysisDataRequestPromises\.has\(requestKey\)\) \{/);
  assert.match(loadAnalysisData, /request\(`\/online-data\/data-analysis\?\$\{params\}`\)/);
  assert.match(loadAnalysisData, /writeOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, data, cacheMs\);/);
  assert.match(loadOnlineAnalysisRows, /const cached = readOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, cacheMs\);/);
  assert.match(loadOnlineAnalysisRows, /if \(onlineAnalysisRowsRequestPromises\.has\(requestKey\)\) \{/);
  assert.match(loadOnlineAnalysisRows, /request\(`\/online-data\/daily-data-list\?\$\{params\}`\)/);
  assert.match(loadOnlineAnalysisRows, /writeOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, data, cacheMs\);/);
  assert.match(refreshOnlineAnalysis, /cacheMs: ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS,/);
  assert.match(refreshOnlineAnalysis, /if \(loadOptions\.force === true\) \{\s*clearOnlineAnalysisReadCaches\(\);/);
  assert.match(refreshOnlineAnalysis, /loadAnalysisData\(null, loadOptions\)/);
  assert.match(refreshOnlineAnalysis, /loadOnlineDataSummary\(loadOptions\)/);
  assert.match(refreshOnlineAnalysis, /loadOnlineAnalysisRows\(loadOptions\)/);
  assert.match(scheduleOnlineDataTabLoad, /return refreshOnlineAnalysis\(options\);/);
  assert.match(clearOnlineDataReadCaches, /clearOnlineAnalysisReadCaches\(\);/);
  assert.match(html, /@click="loadOnlineAnalysisRows\(\{ force: true \}\)"/);
});

test('Download center defers hotel filter loading after primary data', () => {
  const downloadCenterScheduler = sliceFrom(
    'const scheduleDownloadCenterTabLoad = (tab, context = {}) => {',
    '\n            const applyOnlineHistoryDatePreset = () => {'
  );

  assert.match(downloadCenterScheduler, /await refreshOnlineHistory\(\{ refreshHotels: false \}\);/);
  assert.match(downloadCenterScheduler, /scheduleDelayedPageTask\(\(\) => \{\s*if \(!isCurrentTab\(\)\) return null;\s*return loadOnlineHistoryHotelList\(\);\s*\}, 720\);/);
  assert.match(downloadCenterScheduler, /return loadOnlineHistoryHotelList\(\);/);
  assert.match(downloadCenterScheduler, /await loadOnlineDataList\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);/);
  assert.match(downloadCenterScheduler, /scheduleDelayedPageTask\(\(\) => \{\s*if \(seq !== downloadCenterTabLoadSeq \|\| !isCurrentTab\(\)\) return null;\s*return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);\s*\}, 720\);/);
  assert.match(downloadCenterScheduler, /return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(downloadCenterScheduler, /if \(context\.source === 'meituan'\) \{\s*void loadPlatformDataSources\(\{ cacheMs: PLATFORM_SOURCE_PANEL_CACHE_TTL_MS \}\);\s*\}/);
  const primaryListIndex = downloadCenterScheduler.indexOf('await loadOnlineDataList({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });');
  const hotelFilterIndex = downloadCenterScheduler.indexOf('return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });', primaryListIndex);
  const secondarySourceIndex = downloadCenterScheduler.indexOf('void loadPlatformDataSources({ cacheMs: PLATFORM_SOURCE_PANEL_CACHE_TTL_MS });', primaryListIndex);
  assert.ok(primaryListIndex < hotelFilterIndex, 'hotel filter must be scheduled after the primary list resolves');
  assert.ok(hotelFilterIndex < secondarySourceIndex, 'secondary platform sources must not block hotel-filter scheduling');
  assert.doesNotMatch(downloadCenterScheduler, /await loadPlatformDataSources\(\{ cacheMs: PLATFORM_SOURCE_PANEL_CACHE_TTL_MS \}\);/);
  assert.match(html, /const meituanDownloadData = computed\(\(\) => buildMeituanDownloadData\(onlineDataList\.value\)\);/);
  assert.match(html, /switchToMeituanDownloadCenter, openMeituanStoredDataTab, queryMeituanStoredData, meituanDownloadData,/);
  assert.doesNotMatch(downloadCenterScheduler, /await refreshOnlineHistory\(\);\s*return null;/);
  assert.doesNotMatch(
    downloadCenterScheduler,
    /Promise\.allSettled\(\[\s*loadOnlineDataList\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\),\s*loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\),?\s*\]\)/
  );
});

test('Core operations keeps missing platform evidence unknown instead of synthetic zero', () => {
  const platformCards = sliceFrom(
    'const coreOperationsPlatformCards = computed(() => {',
    '\n            const coreOperationsMeituanComparableValue'
  );
  assert.match(platformCards, /const rawSourceRows = platformEvidence\?\.target_date_rows;/);
  assert.match(platformCards, /const sourceRows = rawSourceRows !== null[\s\S]*Number\.isInteger\(parsedSourceRows\)[\s\S]*parsedSourceRows >= 0[\s\S]*\? parsedSourceRows[\s\S]*: null;/);
  assert.match(platformCards, /sourceRowsText: sourceRows === null \? '未验证\/未知' : `\$\{sourceRows\} 行`/);
  assert.doesNotMatch(platformCards, /const sourceRows = Number\(platformEvidence\?\.target_date_rows \|\| 0\);/);
  assert.match(onlineDataTemplateFragment, /目标日源数据 \{\{ platform\.sourceRowsText \}\}/);
});

test('Core operations clears old scope values and exposes competitor request failures', () => {
  const resetScopedState = sliceFrom(
    'const resetCoreOperationsScopedState = () => {',
    '\n\n            const refreshCoreOperationsLoop'
  );
  const refreshLoop = sliceFrom(
    'const refreshCoreOperationsLoop = async (options = {}) => {',
    '\n\n            const loadPhase3OperationEffectLoop'
  );
  const competitorLoader = sliceFrom(
    'const loadCompetitorSummary = async (options = {}) => {',
    '\n\n            const loadCompassData'
  );

  for (const reset of [
    'coreOperationsMetrics.value = {',
    'coreOperationsDiagnoses.value = {',
    'dailyWorkbench.value = null;',
    'dailyWorkbenchPatrol.value = null;',
    'phase3OperationEffectLoop.value = null;',
    'ctripCompetitiveOperationsPayload.value = null;',
    'competitorSummary.value = null;',
  ]) {
    assert.ok(resetScopedState.includes(reset), `scope reset must include ${reset}`);
  }
  assert.match(refreshLoop, /const scopeChanged = hotelId !== String\(coreOperationsHotelId\.value \|\| ''\)[\s\S]*if \(scopeChanged \|\| options\.resetScope === true\) \{\s*resetCoreOperationsScopedState\(\);\s*\}[\s\S]*coreOperationsHotelId\.value = hotelId;/);
  assert.equal((onlineDataTemplateFragment.match(/refreshCoreOperationsLoop\(\{ resetScope: true \}\)/g) || []).length, 2);
  assert.match(competitorLoader, /competitorSummaryError\.value = String\(res\?\.message \|\| '美团竞品摘要读取失败'\)/);
  assert.match(competitorLoader, /competitorSummaryError\.value = String\(e\?\.message \|\| '美团竞品摘要读取失败'\)/);
  assert.match(onlineDataTemplateFragment, /data-testid="core-operations-competitor-error"/);
  assert.match(onlineDataTemplateFragment, /不会按“无竞品数据”或“无异常”处理/);
});

test('Core six-step state requires both platforms, AI-linked tasks, and a due terminal review', () => {
  const executionAndSteps = sliceFrom(
    'const coreOperationsExecutionItems = computed(() => {',
    '\n            const phase3OperationEffectLoopSummary'
  );

  assert.match(executionAndSteps, /const coreOperationsAiExecutionItems = computed[\s\S]*source_module \|\| ''\)\.toLowerCase\(\) === 'ota_diagnosis_saved'/);
  assert.match(executionAndSteps, /const requiredActionKeys = new Set[\s\S]*\$\{recordId\}:\$\{actionItemId\}/);
  assert.match(executionAndSteps, /const latestAiExecutionByActionKey = new Map\(\)/);
  assert.match(executionAndSteps, /requiredActionKeys\.size === requiredActionCount/);
  assert.match(executionAndSteps, /comparablePlatforms\.has\('ctrip'\)[\s\S]*comparablePlatforms\.has\('meituan'\)/);
  assert.match(executionAndSteps, /const dataComplete = platformReadyCount === 2;/);
  assert.match(executionAndSteps, /\['success', 'near_success', 'failed'\]\.includes\(status\)/);
  assert.match(executionAndSteps, /item\?\.review\?\.is_available === true/);
  assert.doesNotMatch(executionAndSteps, /\['success', 'near_success', 'failed', 'observing'\]/);
  assert.match(executionAndSteps, /reviewedCount === requiredActionCount/);
  assert.match(executionAndSteps, /noActionRequired \? 'no_action'/);
});

test('Operation action loads reject stale request and hotel responses', () => {
  const operationActionsLoader = sliceFrom(
    'let operationActionsRequestSeq = 0;',
    '\n            const parseOperationEvidenceNumber'
  );

  assert.match(operationActionsLoader, /const requestSeq = \+\+operationActionsRequestSeq;/);
  assert.match(operationActionsLoader, /requestSeq === operationActionsRequestSeq\s*&& requestHotelId === String\(operationFilters\.value\.hotel_id \|\| ''\)\.trim\(\)/);
  assert.match(operationActionsLoader, /const \[res, flowRes, closureRes\] = await Promise\.all\([\s\S]*if \(!isCurrentRequest\(\)\) return;/);
  assert.match(operationActionsLoader, /catch \(error\) \{\s*if \(!isCurrentRequest\(\)\) return;/);
  assert.match(operationActionsLoader, /finally \{\s*if \(requestSeq === operationActionsRequestSeq\) \{\s*operationLoading\.value\.actions = false;/);
});
