import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.ok(startIndex >= 0, `missing start marker: ${start}`);
  assert.ok(endIndex > startIndex, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const assertLocatorOnly = (source, label) => {
  assert.match(source, /config_id:\s*String\(configId \|\| ''\)\.trim\(\)/, `${label} must carry config_id`);
  assert.match(source, /system_hotel_id:\s*systemHotelId/, `${label} must carry system_hotel_id`);
  assert.doesNotMatch(
    source,
    /\b(?:cookies?|auth_data|authorization|headers?|headers_json|payload|payload_json|spidertoken|token)\s*:/i,
    `${label} must not carry plaintext credentials`,
  );
};

test('Ctrip execution payloads use config_id plus system_hotel_id without plaintext credentials', () => {
  const readiness = sliceBetween(ctripStatic, 'const resolveCtripExecutionConfigId =', 'const normalizeCtripExecutionRequestUrls =');
  assert.match(readiness, /config\?\.config_id \|\| config\?\.id/);
  assert.match(readiness, /config\?\.has_cookies === true/);
  assert.match(readiness, /credential_status \|\| ''\) === 'ready'/);

  const builders = [
    ['rank', 'const buildCtripFetchRequestBody =', 'const buildCtripFetchFormFromConfig ='],
    ['traffic', 'const buildCtripTrafficFetchRequestBody =', 'const buildCtripTrafficResponseModel ='],
    ['overview', 'const buildCtripOverviewFetchRequestBody =', 'const runCtripOverviewFetchFlow ='],
    ['ads', 'const buildCtripAdsFetchRequestBody =', 'const runCtripAdsFetchFlow ='],
    ['cookie-api', 'const buildCtripCookieApiFetchRequestBody =', 'const runCtripCookieApiCaptureFlow ='],
  ];
  for (const [label, start, end] of builders) {
    assertLocatorOnly(sliceBetween(ctripStatic, start, end), label);
  }
});

test('Ctrip generic data configuration is metadata-only and strips credential fields', () => {
  const sensitiveFields = sliceBetween(html, 'const dataConfigSensitiveFieldNames = new Set([', 'const stripDataConfigCredentialFields =');
  for (const key of ['cookies', 'auth_data', 'authorization', 'token', 'headers_json', 'payload_json', 'spidertoken']) {
    assert.match(sensitiveFields, new RegExp(`'${key}'`), `missing sensitive field: ${key}`);
  }
  assert.match(html, /const buildDataConfigForSave = \(\) => stripDataConfigCredentialFields\(/);
  assert.match(html, /const resolveDataConfigCredentialMetadata = \(type, form = \{\}\) =>/);
  assert.match(html, /config_id:\s*String\(firstDataConfigValue\(matches\[0\]\.config_id, matches\[0\]\.id\)\)/);
  assert.match(html, /String\(item\?\.credential_status \|\| ''\) === 'ready'/);
  assert.match(html, /item\?\.has_cookies === true/);
  assert.match(html, /<textarea v-model="dataConfigForm\.cookies"[^>]*\bdisabled\b[^>]*>/);
  assert.match(html, /<textarea v-model="dataConfigForm\.headers_json"[^>]*\bdisabled\b[^>]*>/);
  assert.match(html, /<textarea v-model="dataConfigForm\.payload_json"[^>]*\bdisabled\b[^>]*>/);
  assert.match(html, /请求体不在通用配置中保存/);
  assert.match(html, /请求头由凭据保险库管理/);

  for (const model of ['ctripForm', 'ctripFlowOverviewForm', 'ctripTrafficForm', 'ctripAdsBrowserCaptureForm', 'ctripOverviewForm']) {
    assert.match(
      html,
      new RegExp(`<textarea v-model="${model}\\.cookies"[^>]*\\bdisabled\\b[^>]*>`),
      `${model} Cookie execution input must stay disabled`,
    );
  }
});

test('Ctrip Profile capture transports profile metadata but no Cookie or authorization material', () => {
  const profilePayload = sliceBetween(ctripStatic, 'const buildCtripBrowserCapturePayload =', 'const buildCtripBrowserCaptureRequestContext =');
  assert.match(profilePayload, /system_hotel_id:\s*systemHotelId/);
  assert.match(profilePayload, /profile_id:\s*profileId/);
  assert.match(profilePayload, /login_only:\s*Boolean\(options\.loginOnly\)/);
  assert.match(profilePayload, /bind_data_source:\s*options\.bindDataSource !== false/);
  assert.doesNotMatch(profilePayload, /\b(?:cookies?|auth_data|authorization|headers?|payload|token)\s*:/i);
  assert.match(html, /runCtripBrowserCapture\(\{ loginOnly:\s*true, bindDataSource:\s*true \}\)/);
  assert.match(html, /openPlatformSourcesTab/);
  assert.doesNotMatch(html, /@click="checkCtripProfileStatus/);
  assert.doesNotMatch(html, /v-model="ctripCookieApiForm\.(?:profileId|cookies)"/);
  assert.doesNotMatch(html, /v-model="ctripEndpointEvidenceForm\./);
  assert.doesNotMatch(html, /@click="validateCtripEndpointEvidence/);
});

test('legacy generic Cookie storage is disabled, zeroed, and routes to platform sources', () => {
  const legacyPanel = sliceBetween(html, '<!-- 凭据安全入口 -->', '<!-- 携程ebooking -->');
  assert.match(legacyPanel, /凭据统一由平台配置保管/);
  assert.match(legacyPanel, /旧 Cookie 列表、明文详情和快速保存入口已停用/);
  assert.match(legacyPanel, /浏览器不会再读取已保存的完整 Cookie/);
  assert.match(legacyPanel, /@click="openPlatformSourcesTab"/);
  assert.match(legacyPanel, /前往平台采集源/);

  const listLoader = sliceBetween(html, 'const loadCookiesList = async () => {', 'const loadCookieDetail = async');
  const detailLoader = sliceBetween(html, 'const loadCookieDetail = async', 'const cookieStatusClass =');
  const saveAction = sliceBetween(html, 'const saveCookiesConfig = async () => {', 'const deleteCookiesConfig = async');
  const deleteAction = sliceBetween(html, 'const deleteCookiesConfig = async', 'const batchDeleteCookiesConfig = async');
  const batchDeleteAction = sliceBetween(html, 'const batchDeleteCookiesConfig = async', 'const useCookies = async');
  const useAction = sliceBetween(html, 'const useCookies = async', '// AI智能分析相关函数');

  assert.match(listLoader, /cookiesList\.value = \[\]/);
  assert.match(listLoader, /selectedCookieKeys\.value = \[\]/);
  assert.match(detailLoader, /旧 Cookie 明文详情已停用/);
  assert.match(saveAction, /newCookies\.value = \{ name: '', cookies: '', hotel_id: '' \}/);
  for (const action of [saveAction, deleteAction, batchDeleteAction, useAction]) {
    assert.match(action, /openPlatformSourcesTab\(\)/);
    assert.doesNotMatch(action, /request\(/);
  }
  assert.doesNotMatch(html, /request\(\s*['"]\/online-data\/(?:save-cookies|cookies-list|cookies-detail|delete-cookies|batch-delete-cookies)/);
});

test('Ctrip metadata application and replacement editor never hydrate stored plaintext', () => {
  const configApply = sliceBetween(html, 'const applyCtripConfigObject =', 'const getActiveCtripConfig =');
  assert.match(configApply, /selectedCtripConfigId\.value = config\.config_id \|\| config\.id \|\| ''/);
  assert.match(configApply, /selectedCtripHotelId\.value = String\(config\.hotel_id \|\| config\.system_hotel_id/);
  assert.match(configApply, /ctripForm\.value\.cookies = ''/);
  assert.match(configApply, /ctripForm\.value\.auth_data = \{\}/);
  assert.match(configApply, /ctripCookieApiForm\.value\.cookies = ''/);

  const editorFill = sliceBetween(html, 'const fillCtripCookieEditorForm =', 'const openCtripCookieEditorFromHealth =');
  const editorOpen = sliceBetween(html, 'const openCtripCookieEditorFromHealth =', 'const editCtripCookieFromHealth =');
  assert.match(editorFill, /cookies:\s*''/);
  assert.match(editorFill, /has_cookies:\s*config\?\.has_cookies === true/);
  assert.match(editorFill, /credential_status:\s*config\?\.credential_status \|\| ''/);
  assert.match(editorOpen, /const configId = String\(row\?\.config_id \|\| ''\)\.trim\(\)/);
  assert.match(editorOpen, /resolveCtripConfigMetadata\(listConfig \|\| findCtripConfigMetadataById\(configId\)\)/);
  assert.doesNotMatch(editorOpen, /ensureCtripConfigSecret|loadCtripConfigDetail|get-ctrip-config-detail/);
  assert.doesNotMatch(html, /request\(\s*['"]\/online-data\/get-ctrip-config-detail/);
});
