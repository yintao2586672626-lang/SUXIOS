import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const html = readFileSync('public/index.html', 'utf8');
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');
const meituanStatic = readFileSync('public/meituan-static.js', 'utf8');
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

test('Ctrip manual ranking and traffic use platform authorization as the daily credential', () => {
  const fetchCtripData = sliceFrom('const fetchCtripData = async () => {', 'const fetchMeituanData = async () => {');
  const fetchCtripTrafficData = sliceFrom('const fetchCtripTrafficData = async () => {', 'const fetchCtripComments = async () => {');
  const ctripManualFetchConfigGuard = sliceFrom('const ctripManualFetchConfigProofPending = () => {', '\n\n            const saveCtripConfig');
  const loadCtripConfigList = sliceFrom('const loadCtripConfigList = async (options = {}) => {', '\n\n            const ctripManualFetchConfigProofPending');
  const returnToCtripRankingAfterConfigSave = constSlice(
    'const returnToCtripRankingAfterConfigSave = async (hotelId) => {',
    '\n\n            const saveCtripConfig'
  );
  const saveCtripConfig = constSlice(
    'const saveCtripConfig = async () => runCtripConfigSaveFlow({',
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
  assert.match(html, /requireCtripStatic\('buildCookieConfigRowKey'\)/);
  assert.match(html, /requireCtripStatic\('buildCookieConfigDeleteSuccessState'\)/);
  assert.match(html, /requireCtripStatic\('buildCookieConfigDeleteFailureState'\)/);
  assert.match(html, /requireCtripStatic\('buildCookieConfigBatchDeleteSuccessState'\)/);
  assert.match(html, /requireCtripStatic\('buildCookieConfigBatchDeleteFailureState'\)/);
  assert.match(ctripStatic, /const buildCtripBookmarkletSuccessState = \(response = \{\}\) => \(\{/);
  assert.match(ctripStatic, /const buildCtripBookmarkletFailureState = \(\{/);
  assert.match(ctripStatic, /const buildCtripBatchDeleteConfigResultState = \(results = \[\]\) => \{/);
  assert.match(ctripStatic, /const buildCookieConfigRowKey = \(item = \{\}\) =>/);
  assert.match(ctripStatic, /const buildCookieConfigDeleteSuccessState = \(\{/);
  assert.match(ctripStatic, /const buildCookieConfigBatchDeleteSuccessState = \(\{/);
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
  assert.equal(ctripStaticApi.buildCookieConfigRowKey({ hotel_id: '', name: 'global-cookie' }), 'global::global-cookie');
  const cookieDeleteSuccessState = ctripStaticApi.buildCookieConfigDeleteSuccessState({ name: 'cookie-a', hotelId: 7 });
  assert.equal(cookieDeleteSuccessState.selectedKeyToRemove, '7::cookie-a');
  assert.equal(cookieDeleteSuccessState.shouldReloadList, true);
  assert.equal(cookieDeleteSuccessState.toastLevel, 'success');
  assert.equal(JSON.stringify(ctripStaticApi.buildCookieConfigDeleteFailureState({ response: { message: 'denied' } })), JSON.stringify({
    toastMessage: 'denied',
    toastLevel: 'error',
  }));
  const cookieBatchDeleteSuccessState = ctripStaticApi.buildCookieConfigBatchDeleteSuccessState({
    response: { data: { deleted_count: 3 } },
    rows: [{}, {}, {}, {}],
  });
  assert.equal(cookieBatchDeleteSuccessState.deletedCount, 3);
  assert.equal(JSON.stringify(cookieBatchDeleteSuccessState.selectedCookieKeys), JSON.stringify([]));
  assert.equal(cookieBatchDeleteSuccessState.shouldReloadList, true);
  assert.equal(cookieBatchDeleteSuccessState.toastLevel, 'success');
  assert.match(deleteCookiesConfig, /const deleteSuccessState = buildCookieConfigDeleteSuccessState\(\{ name, hotelId \}\);/);
  assert.match(deleteCookiesConfig, /selectedCookieKeys\.value = selectedCookieKeys\.value\.filter\(key => key !== deleteSuccessState\.selectedKeyToRemove\);/);
  assert.match(deleteCookiesConfig, /const deleteFailureState = buildCookieConfigDeleteFailureState\(\{ response: res \}\);/);
  assert.match(batchDeleteCookiesConfig, /const batchDeleteSuccessState = buildCookieConfigBatchDeleteSuccessState\(\{ response: res, rows \}\);/);
  assert.match(batchDeleteCookiesConfig, /selectedCookieKeys\.value = batchDeleteSuccessState\.selectedCookieKeys;/);
  assert.match(batchDeleteCookiesConfig, /const batchDeleteFailureState = buildCookieConfigBatchDeleteFailureState\(\{ response: res \}\);/);
  assert.doesNotMatch(deleteCookiesConfig, /showToast\('删除成功'\);/);
  assert.doesNotMatch(deleteCookiesConfig, /selectedCookieKeys\.value = selectedCookieKeys\.value\.filter\(key => key !== `\$\{hotelId \|\| 'global'\}::\$\{name\}`\);/);
  assert.doesNotMatch(batchDeleteCookiesConfig, /const deletedCount = res\.data\?\.deleted_count \?\? rows\.length;/);
  assert.doesNotMatch(batchDeleteCookiesConfig, /selectedCookieKeys\.value = \[\];/);
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
  assert.match(fetchCtripData, /ensureCtripConfigSecret: async config => ensureCtripConfigSecret\(await resolveCtripManualFetchConfig\(config\)\)/);
  assert.match(fetchCtripData, /finally \{\s*if \(preparingConfig\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
  assert.match(fetchCtripData, /body: JSON\.stringify\(requestBody\)/);
  assert.match(ctripStatic, /const isCtripRankingFormAlignedWithConfig = \(form = \{\}, config = \{\}, options = \{\}\) =>/);
  assert.match(ctripStatic, /if \(selectedConfig && !isCtripRankingFormAlignedWithConfig\(form, selectedConfig, \{ selectedHotelId: selectedCtripHotelId \}\)\) \{/);
  assert.match(ctripStatic, /const activeConfig = await ensureCtripConfigSecret\(getActiveCtripConfig\(\)\);/);
  assert.match(ctripStatic, /const requestForm = !selectedCtripHotelId && activeConfig\s*\?\s*buildCtripFetchFormFromConfig\(form, activeConfig\)\s*:\s*form;/);
  assert.match(ctripStatic, /const requestBody = \{ \.\.\.requestContext\.requestBody, async: false, background: false \};/);
  assert.match(ctripStatic, /const requestContext = buildCtripFetchRequestContext\(\{/);
  assert.match(ctripStatic, /const nodeId = String\(form\.nodeId \|\| ''\)\.trim\(\)/);
  assert.match(html, /requireCtripStatic\('runCtripTrafficFetchFlow'\)/);
  assert.match(fetchCtripTrafficData, /runCtripTrafficFetchFlow\(\{/);
  assert.match(fetchCtripTrafficData, /const preparingConfig = ctripManualFetchConfigProofPending\(\);/);
  assert.match(fetchCtripTrafficData, /ensureCtripConfigSecret: async config => ensureCtripConfigSecret\(await resolveCtripManualFetchConfig\(config\)\)/);
  assert.match(ctripStatic, /const requestBody = buildCtripTrafficFetchRequestBody\(\{/);
  assert.match(html, /:disabled="fetchingData \|\| !canFetchCtripManualData\(\)"/);
  assert.match(ctripManualFetchConfigGuard, /return !!ctripConfigListLoadingPromise\s*\|\| \(!ctripConfigListLoaded\.value && !ctripConfigListLoadFailed\.value\);/);
  assert.match(ctripManualFetchConfigGuard, /const ctripManualFetchConfigCandidate = \(\) => \{/);
  assert.match(ctripManualFetchConfigGuard, /const canFetchCtripManualData = \(\) => \{/);
  assert.match(ctripManualFetchConfigGuard, /if \(String\(activeCookies \|\| ''\)\.trim\(\)\) return true;/);
  assert.match(ctripManualFetchConfigGuard, /if \(ctripManualFetchConfigCandidate\(\)\) return true;/);
  assert.match(ctripManualFetchConfigGuard, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(ctripManualFetchConfigGuard, /return ctripManualFetchConfigCandidate\(\);/);
  assert.match(loadCtripConfigList, /const force = options\.force === true;/);
  assert.match(loadCtripConfigList, /!force\s*&& ctripConfigListLoaded\.value/);
  assert.match(loadCtripConfigList, /if \(!force\) \{\s*return ctripConfigListLoadingPromise;\s*\}/);
  assert.match(loadCtripConfigList, /await ctripConfigListLoadingPromise\.catch\(\(\) => \[\]\);/);
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
  assert.match(html, /已保存 Cookie\/API 辅助；入库归属由返回酒店ID自动匹配/);
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

test('Meituan ranking uses selected hotel config without exposing temporary fields', () => {
  const rankingPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const fetchMeituanData = sliceFrom('const fetchMeituanData = async () => {', 'const useCtripTrafficDisplayRows');
  const meituanFetchFlow = meituanStatic.slice(
    meituanStatic.indexOf('const runMeituanBatchFetchFlow = async ({'),
    meituanStatic.indexOf('const useMeituanDisplayModel')
  );

  assert.match(rankingPanel, /v-model="meituanForm\.hotelId"/);
  assert.match(rankingPanel, /请选择目标酒店/);
  assert.match(rankingPanel, /默认建议只取昨日/);
  assert.match(rankingPanel, /历史自定义/);
  assert.match(rankingPanel, /px-4 py-3 text-base/);
  assert.match(rankingPanel, /style="width: 20px; height: 20px;"/);
  assert.match(rankingPanel, /bg-cyan-50 border border-cyan-200/);
  assert.match(rankingPanel, /border-blue-600 bg-blue-600 text-white/);
  assert.match(rankingPanel, /border-gray-200 bg-gray-100 text-gray-500 cursor-not-allowed/);
  assert.match(rankingPanel, /:max="meituanRankMaxDate"/);
  assert.match(rankingPanel, /远期预测\/未来入住不属于此接口/);
  assert.match(html, /const meituanRankMaxDate = computed\(\(\) => formatDate\(new Date\(\)\)\);/);
  assert.match(html, /meituanRankMaxDate,/);
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
  assert.match(meituanStatic, /需补充一次性门店标识/);
  assert.doesNotMatch(meituanStatic, /请在本页临时填写/);
  assert.match(meituanStatic, /请先在酒店管理中保存后再获取美团榜单/);
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
  assert.match(meituanFetchFlow, /const selectedMeituanConfig = form\.hotelId\s*\?\s*await ensureMeituanConfigSecret\(getSelectedConfig\(\)\)\s*:\s*null;/);
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
  assert.match(html, /const meituanRankInsightCards = computed\(\(\) => buildMeituanRankInsightCards\(meituanBusinessSummary\.value\)\);/);
  assert.match(html, /const meituanVisibleRankInsightCards = computed\(\(\) => buildMeituanVisibleRankInsightCards\(meituanRankInsightCards\.value\)\);/);
  assert.match(html, /const meituanRankHealthRows = computed\(\(\) => buildMeituanRankHealthRows\(meituanBusinessSummary\.value\)\);/);
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
  assert.match(meituanStatic, /const createEmptyMeituanBusinessSummary = \(\) => \(\{ status: 'empty', metrics: \{\}, cards: \[\] \}\);/);
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
  assert.deepEqual(normalizedBusinessSummary, { status: 'empty', metrics: {}, cards: [] });
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
  assert.match(runMeituanBrowserCaptureForSections, /await runMeituanBrowserCapture\(options\);/);
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

  assert.match(failureBuilder, /\['303', '401', '403'\]/);
  assert.match(failureBuilder, /login_required/);
  assert.match(failureBuilder, /credential_status/);
  assert.match(failureBuilder, /美团登录态已失效/);
  assert.match(onlineDataManualFetchConcern, /'reason'\s*=>\s*\$result\['reason'\]\s*\?\?\s*'meituan_request_failed'/);
  assert.match(onlineDataManualFetchConcern, /'credential_status'\s*=>\s*\$result\['credential_status'\]\s*\?\?\s*''/);
  assert.match(onlineDataManualFetchConcern, /'business_code'\s*=>\s*\$result\['business_code'\]\s*\?\?\s*null/);
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
  assert.match(summaryBuilder, /'totalRoomRevenue', '总房费收入', '¥'/);
  assert.match(summaryBuilder, /'avgRoomPrice', '商圈平均房价'/);
  assert.match(summaryBuilder, /'totalSalesRoomNights', '总销售间夜'/);
  assert.match(summaryBuilder, /'totalSales', '总销售额', '¥'/);
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
  assert.match(html, /const meituanBusinessSummaryCards = computed\(\(\) => resolveMeituanBusinessSummaryCards\(\{/);
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
      hotel_id: 58,
      partner_id: '4517495',
      poi_id: '1022727174',
      cookies: 'token=ok',
    }),
    ensureMeituanConfigSecret: async config => config,
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
  assert.match(fetchTasks, /include_self_trade_metrics:\s*includeSelfMetrics/);
  assert.match(fetchTasks, /include_self_traffic_metrics:\s*includeSelfMetrics/);
  assert.match(fetchTasks, /include_self_business_metrics:\s*includeSelfMetrics/);
});

test('Meituan batch fetch only requests self metric supplements once per date range', () => {
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
    cookies: 'token=ok',
  });

  assert.equal(tasks.length, 12);
  ['1', '7', 'custom'].forEach(dateRange => {
    const rangeTasks = tasks.filter(task => task.dateRange === dateRange);
    assert.equal(rangeTasks.length, 4);
    assert.equal(rangeTasks.filter(task => task.body.include_self_trade_metrics === true).length, 1);
    assert.equal(rangeTasks.filter(task => task.body.include_self_traffic_metrics === true).length, 1);
    assert.equal(rangeTasks.filter(task => task.body.include_self_business_metrics === true).length, 1);
    assert.equal(rangeTasks.find(task => task.body.include_self_trade_metrics === true)?.rankType, 'P_RZ');
    assert.equal(rangeTasks.filter(task => task.body.include_self_trade_metrics === false).length, 3);
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
    cookies: 'token=ok',
  });

  assert.equal(validation.ok, false);
  assert.equal(validation.level, 'warning');
  assert.match(validation.message, /不支持未来日期/);
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

  assert.match(saveMeituanConfigItem, /const cookieState = resolveMeituanConfigSaveCookieState\(meituanConfigForm\.value\.cookies\);/);
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
  assert.match(meituanStatic, /const resolveMeituanConfigSaveCookieState = \(cookies = ''\) => \{/);
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
    cookies: 'cookie',
    auth_data: { token: 'masked' },
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
      cookies: 'cookie',
      auth_data: { token: 'masked' },
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
    hotel_room_count: '88',
    competitor_room_count: '188',
  })), JSON.stringify({
    id: 9,
    name: 'config',
    hotel_id: '58',
    partner_id: 'partner',
    poi_id: 'poi',
    cookies: 'cookie',
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
      cookies: 'cookie',
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
  const ensureHotelOtaConfigLists = constSlice(
    'const ensureHotelOtaConfigLists = async (options = {}) => {',
    '\n\n            const openHotelManagementForOta'
  );
  const saveHotelOtaConfig = constSlice(
    'const saveHotelOtaConfig = async (hotelId, hotelName) => {',
    '\n\n            const hasPartialMeituanOtaConfig'
  );
  const saveHotel = functionSlice('saveHotel');

  assert.match(refreshHotelBindingPanelLight, /loadHotels\(\{ force: true, includeInactive: true \}\)/);
  assert.match(refreshHotelBindingPanelLight, /ensureHotelOtaConfigLists\(\{ force: true \}\)/);
  assert.match(refreshHotelBindingPanelLight, /loadCompetitorSummary\(\{[\s\S]*includeByHotel: true,[\s\S]*force: true/);
  assert.doesNotMatch(refreshHotelBindingPanelLight, /loadPlatformDataSources\(\{ force: true \}\)/);
  assert.doesNotMatch(refreshHotelBindingPanelLight, /loadPlatformSyncLogs\(\{ force: true \}\)/);
  assert.match(refreshHotelBindingPanel, /loadHotels\(\{ force: true, includeInactive: true \}\)/);
  assert.match(refreshHotelBindingPanel, /ensureHotelOtaConfigLists\(\{ force: true \}\)/);
  assert.match(refreshHotelBindingPanel, /loadPlatformDataSources\(\{ force: true \}\)/);
  assert.match(refreshHotelBindingPanel, /loadCompetitorSummary\(\{[\s\S]*force: true/);
  assert.match(ensureHotelOtaConfigLists, /const force = options\.force === true;/);
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
  const tailwindOffset = head.indexOf('href="tailwind.min.css?v=20260628-static-router-fix"');

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

  assert.match(html, /style\.css\?v=20260704-suxios-login-brand/);
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
  assert.match(html, /const otaDiagnosisStaticScript = 'ota-diagnosis-static\.js\?v=20260627-decision-closure-v2';/);
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
  assert.match(currentPageWatcher, /homeSecondaryPanelsReady\.value = false;\s*scheduleHomeSecondaryPanelsReady\(\);\s*runPageLoadOnce\(newPage, 'main', \(\) => loadCompassData\(\)\);/);
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

  assert.match(html, /const EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS = 45000;/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleDelayedPageTask\(\(\) => \{\s*if \(!isCtripEbookingDataHealthVisible\(\)\) return null;\s*scheduleDataHealthPanelRefresh\('light'\);\s*return null;\s*\}, CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS\);\s*scheduleCtripEbookingDeferredStartupRefresh\(\);\s*\}, \{ ttlMs: EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS \}\);/);
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
  assert.match(formOperationLoader, /script\.src = formOperationSupportScript;/);
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
  const fetchMeituanData = sliceFrom('const fetchMeituanData = async () => {', '\n\n            const useCtripTrafficDisplayRows');
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
  assert.match(loadCompetitorSummary, /if \(requestSeq !== competitorSummaryRequestSeq\) return;/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /scheduleDelayedPageTask\(async \(\) => \{/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /await loadCompetitorSummary\(\{ includeByHotel: false \}\);/);
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
    hotelId: '99',
    hotelName: '凯曼未来酒店（巢湖万达广场店）',
    configs: [
      { hotel_name: '凯曼未来酒店（巢湖万达广场店）', name: 'name match' },
    ],
  })?.name, 'name match');
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
  }), true);
  assert.equal(meituanStaticApi.resolveCanFetchMeituanRankingData({
    form: { hotelId: '58' },
    selectedConfig: { id: 1 },
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
    selectedConfig: { id: 1 },
  }), true);
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigProofPending({
    form: { hotelId: '58' },
    selectedConfig: null,
  }), false);
  const explicitMeituanConfig = { id: 12 };
  assert.equal(meituanStaticApi.resolveMeituanManualFetchConfigCandidate({
    config: explicitMeituanConfig,
    form: { hotelId: '58' },
    selectedConfig: { id: 1 },
  }), explicitMeituanConfig);
  const selectedMeituanConfig = { id: 1 };
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
  assert.match(fetchMeituanData, /ensureMeituanConfigSecret: async config => ensureMeituanConfigSecret\(await resolveMeituanManualFetchConfig\(config\)\)/);
  assert.match(fetchMeituanData, /finally \{\s*if \(preparingConfig\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
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
  assert.match(loadMeituanConfigDetail, /const meituanConfigDetailCache = new Map\(\);/);
  assert.match(loadMeituanConfigDetail, /const clearMeituanConfigDetailCache = \(id = ''\) => \{/);
  assert.match(loadMeituanConfigDetail, /const clearTarget = resolveMeituanConfigDetailClearTarget\(id\);/);
  assert.match(loadMeituanConfigDetail, /if \(!clearTarget\.clearAll\) \{/);
  assert.match(loadMeituanConfigDetail, /const cacheKey = clearTarget\.cacheKey;/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailCache\.delete\(cacheKey\);/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailLoadingPromises\.delete\(cacheKey\);/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailCache\.clear\(\);/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailLoadingPromises\.clear\(\);/);
  assert.match(loadMeituanConfigDetail, /const loadTarget = resolveMeituanConfigDetailLoadTarget\(\{ id, loadingPromises: meituanConfigDetailLoadingPromises \}\);/);
  assert.match(loadMeituanConfigDetail, /if \(loadTarget\.status === 'missing_key'\) return null;/);
  assert.match(loadMeituanConfigDetail, /if \(loadTarget\.status === 'loading'\) \{/);
  assert.match(loadMeituanConfigDetail, /return loadTarget\.promise;/);
  assert.match(loadMeituanConfigDetail, /const cacheKey = loadTarget\.cacheKey;/);
  assert.match(loadMeituanConfigDetail, /request\(buildMeituanConfigDetailRequestUrl\(cacheKey\)\)/);
  assert.match(loadMeituanConfigDetail, /const detailResult = resolveMeituanConfigDetailResponse\(res\);/);
  assert.match(loadMeituanConfigDetail, /if \(!detailResult\.ok\) \{/);
  assert.match(loadMeituanConfigDetail, /throw new Error\(detailResult\.message\);/);
  assert.match(loadMeituanConfigDetail, /return detailResult\.data;/);
  assert.doesNotMatch(loadMeituanConfigDetail, /get-meituan-config-detail\?id=\$\{encodeURIComponent\(cacheKey\)\}/);
  assert.doesNotMatch(loadMeituanConfigDetail, /res\.code !== 200/);
  assert.doesNotMatch(loadMeituanConfigDetail, /const cacheKey = buildMeituanConfigDetailCacheKey\(config\.id\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /const listVersion = getMeituanConfigDetailVersion\(config\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /const cached = cacheKey \? meituanConfigDetailCache\.get\(cacheKey\) : null;/);
  assert.doesNotMatch(loadMeituanConfigDetail, /const cachedResult = resolveMeituanConfigDetailCachedResult\(\{ cached, listVersion \}\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /if \(meituanConfigDetailLoadingPromises\.has\(cacheKey\)\) \{/);
  assert.doesNotMatch(loadMeituanConfigDetail, /return meituanConfigDetailLoadingPromises\.get\(cacheKey\);/);
  assert.match(loadMeituanConfigDetail, /const cacheLookup = resolveMeituanConfigDetailCacheLookup\(\{ config, cache: meituanConfigDetailCache \}\);/);
  assert.match(loadMeituanConfigDetail, /if \(cacheLookup\.cachedResult\.hit\) \{/);
  assert.match(loadMeituanConfigDetail, /return cacheLookup\.cachedResult\.data;/);
  assert.match(loadMeituanConfigDetail, /const cacheEntry = buildMeituanConfigDetailCacheEntry\(\{ detail, listVersion: cacheLookup\.listVersion \}\);/);
  assert.match(loadMeituanConfigDetail, /const storePlan = resolveMeituanConfigDetailCacheStorePlan\(\{ cacheKey: cacheLookup\.cacheKey, cacheEntry \}\);/);
  assert.match(loadMeituanConfigDetail, /if \(storePlan\.shouldStore\) \{/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailCache\.set\(storePlan\.cacheKey, storePlan\.cacheEntry\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /if \(cacheEntry && cacheLookup\.cacheKey\) \{/);
  assert.doesNotMatch(loadMeituanConfigDetail, /meituanConfigDetailCache\.set\(cacheLookup\.cacheKey, cacheEntry\);/);
  assert.match(loadMeituanConfigDetail, /const failureAction = resolveMeituanConfigDetailFailureAction\(\{ error: e, silent: options\.silent \}\);/);
  assert.match(loadMeituanConfigDetail, /if \(failureAction\.type === 'log'\) \{/);
  assert.match(loadMeituanConfigDetail, /console\.error\(failureAction\.label, failureAction\.error\);/);
  assert.match(loadMeituanConfigDetail, /showToast\(failureAction\.message, failureAction\.level\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /console\.error\('\[Meituan\] 预热完整配置失败:', e\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /showToast\(e\.message \|\| '加载美团完整配置失败', 'error'\);/);
  assert.match(loadMeituanConfigDetail, /if \(shouldSkipMeituanConfigDetailLoad\(config\)\) return config;/);
  assert.match(loadMeituanConfigDetail, /const prewarmPlan = resolveMeituanConfigDetailPrewarmPlan\(\{ config, delayMs: 80 \}\);/);
  assert.match(loadMeituanConfigDetail, /if \(!prewarmPlan\.shouldPrewarm\) return;/);
  assert.match(loadMeituanConfigDetail, /deferUiTask\(\(\) => ensureMeituanConfigSecret\(prewarmPlan\.config, \{ silent: true \}\), prewarmPlan\.delayMs\);/);
  assert.doesNotMatch(loadMeituanConfigDetail, /if \(shouldSkipMeituanConfigDetailLoad\(config\)\) return;\s*deferUiTask\(\(\) => ensureMeituanConfigSecret\(config, \{ silent: true \}\), 80\);/);
  assert.match(html, /clearMeituanConfigDetailCache\(saveSuccessState\.clearConfigDetailId\);/);
  assert.match(html, /clearMeituanConfigDetailCache\(deleteSuccessState\.clearConfigDetailId\);/);
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
  assert.match(loadMeituanConfigList, /deferUiTask\(\(\) => applyMeituanHotelConfig\(false, \{ refreshList: false \}\), 80\);/);
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
    'const triggerAutoFetch = async () => {',
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
  assert.match(html, /const platformAutoPanelsScript = 'components\/online-data\/platform-auto-settings-panels\.js\?v=20260708-local-auth-copy';/);
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
  assert.match(autoFetchTriggerGuard, /hasAnyPlatformFetchConfigByHotelId\(hotelId\) \|\| autoFetchConfigProofPendingForHotelId\(hotelId\)/);
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
  assert.match(html, /const meituanDownloadData = computed\(\(\) => buildMeituanDownloadData\(onlineDataList\.value\)\);/);
  assert.match(html, /switchToMeituanDownloadCenter, meituanDownloadData,/);
  assert.doesNotMatch(downloadCenterScheduler, /await refreshOnlineHistory\(\);\s*return null;/);
  assert.doesNotMatch(
    downloadCenterScheduler,
    /Promise\.allSettled\(\[\s*loadOnlineDataList\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\),\s*loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\),?\s*\]\)/
  );
});
