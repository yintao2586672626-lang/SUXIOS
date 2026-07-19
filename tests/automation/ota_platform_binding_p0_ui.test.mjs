import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  assert.ok(start >= 0, `missing start marker: ${startText}`);
  const end = source.indexOf(endText, start);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const flowPanel = sliceBetween(
  html,
  'data-testid="platform-profile-p0-flow"',
  'platformCollectionStatusError'
);
const failureMapper = sliceBetween(
  html,
  'const platformCollectionFailureReasonText = (reason, row = null) => {',
  'const platformCollectionFailureReasonClass = (reason, row = null) => {'
);
const flowBuilder = sliceBetween(
  html,
  'const platformProfileFlowRows = computed(() => {',
  'const meituanPlatformProfileStatusRow = computed'
);
const requestContextLayer = sliceBetween(
  html,
  'const BUSINESS_CONTEXT_ENDPOINT_PREFIXES = [',
  'const userHasPermission = (key) =>'
);
const statusMapper = sliceBetween(
  html,
  'const platformCollectionStatusText = (status) => ({',
  'const platformReviewCollectionText = (row) => {'
);
const typeBreakdown = sliceBetween(
  html,
  'data-testid="platform-collection-type-breakdown"',
  'data-testid="platform-profile-p0-flow"'
);
const postFetchSchedulers = sliceBetween(
  html,
  'const scheduleOnlineDataRefresh =',
  'const PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS ='
);
const profileProbeAction = sliceBetween(
  html,
  'const probePlatformProfileStatus = async (item) => {',
  'const loadCollectionReliability = async'
);
const reviewAutomation = sliceBetween(
  html,
  'const runCtripReviewMatchAutomation =',
  'const bindCtripReviewOrderMatch ='
);
const ctripManualFetchAction = sliceBetween(
  html,
  "requestFetch: requestBody => request('/online-data/fetch-ctrip'",
  'setOnlineDataResult: value =>'
);
const accountCollectionCenter = sliceBetween(
  html,
  'data-testid="platform-account-collection-center"',
  'data-testid="platform-account-advanced-tools"'
);
const hotelAccountSummary = sliceBetween(
  html,
  'data-testid="hotel-account-summary-table"',
  '<!-- 空状态 -->'
);

test('OTA platform status page exposes the P0 Profile login flow without credential custody', () => {
  assert.match(flowPanel, /Profile 主线，不托管 OTA 账号密码/);
  assert.match(flowPanel, /目标日期真实入库/);
  assert.match(flowPanel, /platformProfileFlowRows/);
  assert.match(flowPanel, /platformProfileFlowStepClass/);
  assert.match(flowPanel, /platformProfileFlowStepDotClass/);
  assert.doesNotMatch(flowPanel, /ctripPassword|meituanPassword|operator-request|full-phone|hasAppSession|App 会话/);
});

test('browser assist import panel posts supplemental captures through the contract endpoint', () => {
  assert.match(html, /ota-browser-assist-static\.js/);
  assert.match(html, /data-testid="browser-assist-import-panel"/);
  assert.match(html, /browserAssistImportForm/);
  assert.match(html, /browserAssistImportResult/);
  assert.match(html, /readBrowserAssistCaptureFile/);
  assert.match(html, /copyBrowserAssistCollectorScript/);
  assert.match(html, /buildOtaBrowserAssistCollectorScript/);
  assert.match(html, /复制轻量采集脚本/);
  assert.match(html, /importBrowserAssistCaptureFromText/);
  assert.match(html, /\/online-data\/browser-assist-import/);

  const browserAssistImportAction = sliceBetween(
    html,
    'const importBrowserAssistCaptureFromText = async () => {',
    'const platformSourceStatusClass ='
  );
  assert.match(browserAssistImportAction, /system_hotel_id: systemHotelId/);
  assert.match(browserAssistImportAction, /capture,/);
  assert.match(browserAssistImportAction, /schedulePlatformCollectionStatusRefresh\(\)/);
  assert.match(browserAssistImportAction, /scheduleDataHealthPanelRefresh\('light', \{ force: true \}\)/);
  assert.doesNotMatch(browserAssistImportAction, /bottomPrice|confirmed_revenue|revenue_amount/);
});

test('Ctrip review order advanced panel does not expose main-token page assist scripts', () => {
  const advancedPanel = sliceBetween(
    html,
    'showCtripReviewMatchManualPanel',
    '<h5 class="font-semibold text-gray-900">1. 评价信息</h5>'
  );

  assert.match(advancedPanel, /copyCtripReviewMatchPayloadTemplate/);
  assert.match(html, /const copyCtripReviewMatchPayloadTemplate = \(\) =>/);
  assert.match(advancedPanel, /不会复制宿析登录 token 到 OTA 页面/);
  assert.doesNotMatch(html, /buildCtripReviewOrdererAssistScript/);
  assert.doesNotMatch(html, /copyCtripReviewOrdererAssistScript/);
  assert.doesNotMatch(html, /token:\s*authToken/);
  assert.doesNotMatch(html, /Authorization:\s*String\(config\.token \|\| ''\)/);
  assert.doesNotMatch(html, /suxiCtripReviewOrdererAssist/);
});

test('OTA platform failure reasons are mapped to user-visible blockers', () => {
  assert.match(html, /platformCollectionFailureReasonText\(row\.failureReason, row\)/);
  assert.match(html, /platformCollectionFailureReasonClass\(row\.failureReason, row\)/);
  for (const marker of [
    'sync_completed_without_saved_rows',
    'no_collected_ota_rows',
    'captcha_required',
    'sms_code_required',
    'slider_requires_manual',
    'human_verification_required',
    'login_expired',
    'missing_profile',
    'field_missing',
    'browser_runtime_error',
    'platform_api_error',
    'profile_reused_no_target_date_traffic_rows',
    'target_date_traffic_rows_missing',
    'traffic_field_facts_missing',
    'hotel_mismatch',
    'permission_denied',
    'endpoint_not_triggered',
  ]) {
    assert.match(failureMapper, new RegExp(marker), `failure mapper must handle ${marker}`);
  }
  for (const text of [
    '同步完成但没有真实入库数据',
    '目标日期无入库行',
    '验证码或短信未完成',
    '人机验证未完成',
    '缺少浏览器 Profile',
    '登录态或授权已失效',
    '字段缺失或未解析到业务行',
    '浏览器运行环境异常',
    '平台接口返回异常',
  ]) {
    assert.match(failureMapper, new RegExp(text), `failure mapper must display ${text}`);
  }
});

test('OTA platform Profile flow uses login-state and target-date evidence as the closure standard', () => {
  assert.match(flowBuilder, /platformCollectionStatusRows\.value/);
  assert.match(flowBuilder, /platformProfileStatusRows\.value/);
  for (const label of ['打开平台登录', '等待用户验证', '确认登录态', '同步目标日期数据', '验证数据完整性']) {
    assert.match(flowBuilder, new RegExp(label), `missing P0 flow label: ${label}`);
  }
  assert.match(flowBuilder, /账号使用者先在自己的电脑完成平台授权/);
  assert.match(flowBuilder, /账号使用者自己的浏览器内完成人工登录/);
  assert.match(flowBuilder, /current_session_verified/);
  assert.match(flowBuilder, /binding_contract/);
  assert.match(flowBuilder, /currentSessionVerified/);
  assert.match(flowBuilder, /bindingContract\.current_session_verified === true/);
  assert.match(flowBuilder, /profile\.current_session_verified === true/);
  assert.doesNotMatch(flowBuilder, /profile\.currentSessionVerified === true/);
  assert.match(flowBuilder, /const loginVerified = currentSessionVerified && statusCode === 'logged_in';/);
  assert.doesNotMatch(flowBuilder, /manual_login_state_verified\|logged_in/);
  assert.doesNotMatch(flowBuilder, /manual_login_state_verified\/i\.test\(currentStatus\)/);
  assert.match(html, /current_session_verified=/);
  assert.match(html, /historical_manual_login_state_verified=/);
  assert.match(html, /item\.binding_checks \|\| item\.checks/);
  assert.match(flowBuilder, /const collectionDone = collectionStatus === 'collected'/);
  assert.match(flowBuilder, /targetTrafficRows > 0/);
  assert.match(flowBuilder, /fieldFactStatus === 'ready'/);
  assert.match(flowBuilder, /targetDateText/);
  assert.doesNotMatch(flowBuilder, /ctripPassword|meituanPassword|operator-request|full-phone|hasAppSession|App 会话/);
});

test('business request layer carries hotel tenant and platform context only for scoped modules', () => {
  assert.match(requestContextLayer, /BUSINESS_CONTEXT_ENDPOINT_PREFIXES/);
  for (const path of ['/online-data/', '/dashboard/', '/revenue-ai/', '/revenue-research/', '/operation/']) {
    assert.match(requestContextLayer, new RegExp(path.replaceAll('/', '\\/')), `missing business context prefix ${path}`);
  }
  for (const marker of ['system_hotel_id', 'tenant_id', 'platform', 'withBusinessContext', 'businessContext']) {
    assert.match(requestContextLayer, new RegExp(marker), `request context layer must handle ${marker}`);
  }
  assert.match(requestContextLayer, /appendContextToRequestUrl/);
  assert.match(requestContextLayer, /appendContextToJsonBody/);
});

test('strict manual OTA execution endpoints never receive generic business context fields', () => {
  for (const path of [
    '/online-data/fetch-ctrip',
    '/online-data/fetch-meituan',
    '/online-data/fetch-ctrip-traffic',
    '/online-data/ctrip/traffic',
    '/online-data/fetch-ctrip-cookie-api',
    '/online-data/fetch-ctrip-overview',
    '/online-data/fetch-ctrip-ads',
    '/online-data/fetch-meituan-traffic',
    '/online-data/fetch-meituan-order-flow',
    '/online-data/fetch-meituan-orders',
    '/online-data/fetch-meituan-ads',
  ]) {
    assert.ok(requestContextLayer.includes(`'${path}'`), `missing strict manual endpoint ${path}`);
  }
  assert.match(requestContextLayer, /STRICT_OTA_MANUAL_EXECUTION_PATHS\.has\(path\)/);
  assert.ok(
    requestContextLayer.indexOf('STRICT_OTA_MANUAL_EXECUTION_PATHS.has(path)')
      < requestContextLayer.indexOf('BUSINESS_CONTEXT_ENDPOINT_PREFIXES.some'),
    'strict endpoint exclusion must run before prefix-based context injection'
  );
});

test('Ctrip manual fetch keeps strict execution payload free of injected business context fields', () => {
  assert.match(ctripManualFetchAction, /withBusinessContext:\s*false/);
});

test('collection status vocabulary exposes explicit user-visible states', () => {
  for (const marker of [
    'not_loaded',
    'not_collected',
    'collecting',
    'stale_running',
    'failed',
    'login_expired',
    'unauthorized',
    'no_permission',
    'policy_disabled',
    'strategy_disabled',
    'data_empty',
    'collected',
    'partial',
    'stale',
  ]) {
    assert.match(statusMapper, new RegExp(marker), `status mapper must include ${marker}`);
  }
  for (const text of ['未加载', '未采集', '采集中', '采集失败', '登录过期', '无权限', '策略禁用', '数据为空', '已采集', '部分模块成功', '已过期']) {
    assert.match(statusMapper, new RegExp(text), `status mapper must display ${text}`);
  }
});

test('platform status page separates data types and operational next actions', () => {
  assert.match(html, /data-testid="platform-context-card"/);
  assert.match(typeBreakdown, /platformCollectionTypeRows/);
  for (const text of ['数据类型', '发生了什么', '为什么重要', '下一步', '负责人']) {
    assert.match(typeBreakdown, new RegExp(text), `type breakdown must show ${text}`);
  }
  const typeRowBehavior = sliceBetween(
    html,
    'const platformCollectionTypeWhyText = (row = {}) => {',
    'const buildDefaultOnlineHistoryFilter = () =>'
  );
  for (const text of ['点评采集默认策略禁用', '收益/运营负责人', '运营人员', '系统管理员', '数据管理员']) {
    assert.match(typeRowBehavior, new RegExp(text), `type rows must include ${text}`);
  }
});

test('platform account page is the detailed collection-readiness center', () => {
  for (const marker of ['自动可采集', '手动可采集', '最近采集结果', '阻塞原因', '下一步', 'filteredPlatformAccountCenterRows']) {
    assert.match(accountCollectionCenter, new RegExp(marker), `account center must expose ${marker}`);
  }
  assert.match(accountCollectionCenter, /platformAccountCenterPlatform/);
  assert.match(accountCollectionCenter, /platformAccountCenterReadiness/);
  assert.match(accountCollectionCenter, /platformAccountCenterSearch/);
  assert.match(html, /data-testid="platform-account-advanced-tools"/);
});

test('platform account center renders one store row with Ctrip and Meituan channel cards', () => {
  assert.match(accountCollectionCenter, /data-testid="platform-store-row"/);
  assert.match(accountCollectionCenter, /data-testid="platform-store-channel-ctrip"/);
  assert.match(accountCollectionCenter, /data-testid="platform-store-channel-meituan"/);
  assert.match(accountCollectionCenter, /row\.channels\.ctrip/);
  assert.match(accountCollectionCenter, /row\.channels\.meituan/);
  assert.doesNotMatch(accountCollectionCenter, /<th[^>]*>门店 \/ 平台<\/th>/);

  const groupedRows = sliceBetween(
    html,
    'const platformStoreAccountCenterRows = computed(() => {',
    'const filteredPlatformAccountCenterRows = computed(() => {'
  );
  assert.match(groupedRows, /platformAccountCenterRows\.value/);
  assert.match(groupedRows, /channels:\s*\{\s*ctrip:\s*null,\s*meituan:\s*null\s*\}/);
  assert.match(groupedRows, /grouped\.get\(hotelId\)/);
});

test('hotel management remains a store-level account summary', () => {
  assert.doesNotMatch(hotelAccountSummary, /手动Cookie|采集配置|自动化采集/);
  assert.match(hotelAccountSummary, /最近采集/);
  assert.match(hotelAccountSummary, /下一步/);
});

test('post collection actions refresh the unified collection-status panel', () => {
  assert.match(postFetchSchedulers, /const schedulePlatformCollectionStatusRefresh = \(\) =>/);
  assert.match(postFetchSchedulers, /loadPlatformCollectionStatus\(\{ force: true, cacheMs: 0 \}\)/);
  assert.match(postFetchSchedulers, /scheduleAutoFetchStatusPanelRefresh[\s\S]*schedulePlatformCollectionStatusRefresh\(\)/);
  const syncAction = sliceBetween(
    html,
    'const syncPlatformDataSource = async (source) => {',
    'const importPlatformDataRowsFromText = async () => {'
  );
  assert.match(syncAction, /schedulePlatformCollectionStatusRefresh\(\)/);
  assert.match(syncAction, /daily_profile_reuse/);
  assert.match(syncAction, /body\.interactive_browser = false/);
  assert.match(syncAction, /body\.target_date = targetDate/);
  assert.match(syncAction, /target_date_traffic_rows/);
  assert.match(syncAction, /schedulePlatformProfileStatusRefresh\(\{ silent: true, force: true \}\)/);
  assert.match(syncAction, /schedulePlatformSyncLogPanelRefresh\(\{ force: true \}\)/);
  assert.match(syncAction, /scheduleDataHealthPanelRefresh\('light', \{ force: true \}\)/);
});

test('Ctrip Profile probe uses login-state endpoint instead of starting a capture action', () => {
  assert.match(profileProbeAction, /\/online-data\/ctrip-profile-status/);
  assert.match(profileProbeAction, /probe_login:\s*'1'/);
  assert.doesNotMatch(profileProbeAction, /runCtripBrowserCapture\(\{ loginOnly: true \}\)/);
});

test('Ctrip review order matching does not trigger default live review collection', () => {
  assert.match(html, /携程点评-订单匹配台/);
  assert.match(reviewAutomation, /review_collection_policy:\s*'explicit_review_match_only'/);
  assert.match(reviewAutomation, /匹配动作只读取已授权点评\/IM\/订单数据/);
  assert.doesNotMatch(reviewAutomation, /capture-ctrip-browser|comment_review|capture_sections/);
});
