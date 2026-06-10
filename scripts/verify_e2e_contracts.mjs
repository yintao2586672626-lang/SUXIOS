import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function requireText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

function requireTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

requireText('public/index.html', 'data-testid="login-username"', 'login username has stable selector');
requireText('public/index.html', 'data-testid="login-password"', 'login password has stable selector');
requireText('public/index.html', 'data-testid="login-submit"', 'login submit has stable selector');
requireText('public/index.html', 'data-testid="open-register"', 'login page exposes self-registration entry selector');
requireText('public/index.html', 'data-testid="register-submit"', 'login page exposes self-registration submit');
requireText('public/index.html', 'data-testid="register-username"', 'login page exposes self-registration fields');
requireText('public/index.html', "request('/auth/register'", 'frontend calls public self-registration API');
requireText('public/index.html', 'data-testid="app-nav"', 'sidebar nav has stable selector');
requireText('public/index.html', 'data-testid="app-main"', 'main app surface has stable selector');
requireText('public/index.html', ':data-current-page="currentPage"', 'main app surface exposes current page state');
requireText('public/index.html', ':data-testid="menuTestId(item)"', 'top-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(child)"', 'second-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(grandChild)"', 'third-level menu uses test id helper');
requireText('public/index.html', 'filterVisibleMenuItems(menuItems.value, user.value)', 'entry uses extracted visible menu filter');
requireText('public/system-static.js', 'const resolveMenuItems', 'system static module resolves menu config keys');
requireText('public/system-static.js', 'const filterVisibleMenuItems', 'system static module filters visible menu items');
requireText('public/index.html', 'buildHotelPlatformAccountRowStatic', 'entry uses extracted hotel platform account row builder');
requireText('public/system-static.js', 'const buildHotelPlatformAccountRow', 'system static builds hotel platform account rows');
requireText('public/system-static.js', "target: 'profile-login'", 'system static keeps profile login direct target metadata');
requireText('public/system-static.js', "target: 'sync-logs'", 'system static keeps sync logs direct target metadata');
requireText('public/index.html', "requireCtripStatic('buildCtripBrowserCaptureTargetContext')", 'entry uses extracted Ctrip browser capture target context builder');
requireText('public/index.html', "requireCtripStatic('buildCtripBrowserCaptureRequestContext')", 'entry uses extracted Ctrip browser capture request context builder');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureTargetContext', 'Ctrip static builds browser capture target context');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCapturePayload', 'Ctrip static builds browser capture payloads');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureRequestContext', 'Ctrip static builds browser capture request context');
requireText('public/ctrip-static.js', 'const normalizeCtripBrowserCaptureErrorResult', 'Ctrip static normalizes browser capture errors');
requireText('public/index.html', "requireCtripStatic('buildCtripFetchRequestContext')", 'entry uses extracted Ctrip fetch request context builder');
requireText('public/ctrip-static.js', 'const buildCtripFetchDateRange', 'Ctrip static builds fetch date ranges');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestBody', 'Ctrip static builds fetch request bodies');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestContext', 'Ctrip static builds fetch request context');
requireText('public/index.html', "requireCtripStatic('buildLatestCtripSnapshotModel')", 'entry uses extracted Ctrip latest snapshot model builder');
requireText('public/ctrip-static.js', 'const buildLatestCtripSnapshotModel', 'Ctrip static builds latest snapshot models');
requireText('public/index.html', "requireCtripStatic('buildCtripTrafficFetchRequestBody')", 'entry uses extracted Ctrip traffic fetch request builder');
requireText('public/ctrip-static.js', 'const buildCtripTrafficFetchRequestBody', 'Ctrip static builds traffic fetch request bodies');
requireText('public/index.html', "requireCtripStatic('buildCtripOverviewFetchRequestBody')", 'entry uses extracted Ctrip overview fetch request builder');
requireText('public/ctrip-static.js', 'const buildCtripOverviewFetchRequestBody', 'Ctrip static builds overview fetch request bodies');
requireText('public/index.html', "requireCtripStatic('buildCtripAdsFetchRequestBody')", 'entry uses extracted Ctrip ads fetch request builder');
requireText('public/ctrip-static.js', 'const buildCtripAdsFetchRequestBody', 'Ctrip static builds ads fetch request bodies');
requireText('public/index.html', "requireCtripStatic('buildCtripCookieApiFetchRequestBody')", 'entry uses extracted Ctrip Cookie API fetch request builder');
requireText('public/ctrip-static.js', 'const buildCtripCookieApiFetchRequestBody', 'Ctrip static builds Cookie API fetch request bodies');
requireText('public/index.html', "requireCtripStatic('isCtripAdsApiUrl')", 'entry uses extracted Ctrip ads URL guard');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileRecheckRunContext')", 'entry uses extracted Ctrip Profile recheck run context builder');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckInitialState', 'Ctrip static builds Profile recheck initial state');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckRunContext', 'Ctrip static builds Profile recheck run context');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckSuccessResult', 'Ctrip static builds Profile recheck success result');
requireText('public/index.html', "requireMeituanStatic('buildMeituanBatchFetchTasks')", 'entry uses extracted Meituan batch fetch task builder');
requireText('public/index.html', "requireMeituanStatic('validateMeituanBatchFetchInput')", 'entry uses extracted Meituan batch fetch input validator');
requireText('public/meituan-static.js', 'const buildMeituanBatchFetchTasks', 'Meituan static builds batch fetch tasks');
requireText('public/meituan-static.js', 'const buildMeituanDisplayModelPayload', 'Meituan static builds display model payloads');
requireText('public/meituan-static.js', 'const validateMeituanBatchFetchInput', 'Meituan static validates batch fetch inputs');
requireText('public/index.html', "requireSystemStatic('getDefaultDataConfigForm')", 'entry uses extracted data config default form');
requireText('public/system-static.js', 'const getDefaultDataConfigForm', 'system static builds data config default form');
requireText('public/index.html', ':data-testid="pageTestId(currentPage)"', 'active page container exposes current page test id');
requireText('public/index.html', '<script src="testid-static.js"></script>', 'frontend loads extracted test id helper');
requireText('public/index.html', 'createPageTestIdController', 'entry wires extracted page test id controller');
requireText('public/testid-static.js', 'assignPageControlTestIds', 'page controls receive generated stable test ids');
requireText('public/testid-static.js', 'normalizeTestIdSegment', 'test id helper keeps stable segment normalization');
requireText('public/index.html', 'buildGlobalNotifications({', 'entry uses extracted global notification builder');
requireText('public/notification-static.js', 'const buildGlobalNotifications', 'notification static builds global notification rows');
requireNoText('public/index.html', 'const isItemVisible = (item) => {', 'visible menu permission filter is not re-inlined');
requireNoText('public/index.html', 'const platformNextActionMeta =', 'platform next action metadata is not re-inlined');
requireNoText('public/index.html', 'const platformAccountStoreText =', 'platform account store text is not re-inlined');
requireNoText('public/index.html', 'const hotelId = String(\n                    form.hotelId', 'Ctrip browser capture hotel id resolution is not re-inlined');
requireNoText('public/index.html', "cookies: activeConfig?.cookies || activeConfig?.cookie || '',", 'Ctrip browser capture cookie payload is not re-inlined');
requireNoText('public/index.html', 'const optionSections = options.sections || options.captureSections ||', 'Ctrip browser capture section normalization is not re-inlined');
requireNoText('public/index.html', 'const normalizeCtripBrowserCaptureErrorResult = (error) => {', 'Ctrip browser capture error normalization is not re-inlined');
requireNoText('public/index.html', 'const cookies = ctripForm.value.cookies.trim();', 'Ctrip fetch credential trim is not re-inlined');
requireNoText('public/index.html', 'const nodeId = String(ctripForm.value.nodeId || \'\').trim();', 'Ctrip fetch node id normalization is not re-inlined');
requireNoText('public/index.html', 'const { startDate, endDate } = buildCtripFetchDateRange(ctripForm.value);', 'Ctrip fetch date range construction is not re-inlined');
requireNoText('public/index.html', 'const yesterday = new Date();', 'Ctrip fetch default date calculation is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = {', 'Ctrip fetch request body is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = buildCtripFetchRequestBody({', 'Ctrip fetch request body helper call is not re-inlined');
requireNoText('public/index.html', 'raw: rawResponse.substring(0, 1000)', 'Ctrip fetch raw failure result is not re-inlined');
requireNoText('public/index.html', 'const rankRows = payload?.rank?.rows || [];', 'Ctrip latest snapshot row slicing is not re-inlined');
requireNoText('public/index.html', 'const trafficUrl = String(form.url || \'\').trim();', 'Ctrip traffic request URL trimming is not re-inlined');
requireNoText('public/index.html', 'const ctripTrafficFetchBody = {', 'Ctrip traffic request body is not re-inlined');
requireNoText('public/index.html', 'decoded_data: decoded,', 'Ctrip traffic response model is not re-inlined');
requireNoText('public/index.html', 'request_urls: form.requestUrls,', 'Ctrip overview request body is not re-inlined');
requireNoText('public/index.html', 'request_urls: requestUrls,', 'Ctrip flow overview request body is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'POST',", 'Ctrip overview request method fallback is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'GET',", 'Ctrip flow overview request method fallback is not re-inlined');
requireNoText('public/index.html', "const defaultCtripAdsEffectReportUrl = 'https://", 'Ctrip ads default URL is not re-inlined');
requireNoText('public/index.html', 'const isCtripAdsApiUrl = (url = \'\') => {', 'Ctrip ads URL guard is not re-inlined');
requireNoText('public/index.html', 'api_type: normalizeCtripAdsApiType(form.apiType),', 'Ctrip ads request body is not re-inlined');
requireNoText('public/index.html', 'profile_id: cookieApiProfileId,', 'Ctrip Cookie API request body is not re-inlined');
requireNoText('public/index.html', "method: String(ctripCookieApiForm.value.method || 'GET').toUpperCase(),", 'Ctrip Cookie API request method normalization is not re-inlined');
requireNoText('public/index.html', "payload_json: String(ctripCookieApiForm.value.payloadJson || '').trim(),", 'Ctrip Cookie API payload trimming is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileFieldRecheckSections = (fields = []) => {', 'Ctrip Profile recheck section builder is not re-inlined');
requireNoText('public/index.html', 'const canRecapture = Boolean(selectedCtripHotelId.value || autoFetchHotelId.value || user.value?.hotel_id);', 'Ctrip Profile recheck recapture guard is not re-inlined');
requireNoText('public/index.html', 'body: JSON.stringify({\n                            sections,', 'Ctrip Profile recheck request options are not re-inlined');
requireNoText('public/index.html', 'const prefix = captureSucceeded', 'Ctrip Profile recheck result message is not re-inlined');
requireNoText('public/index.html', "message: '重抓流程已结束，但字段列表在执行中被刷新；请查看当前获取值状态或再次重抓。'", 'Ctrip Profile recheck interrupted state is not re-inlined');
requireNoText('public/index.html', 'const allRankTypes = [', 'Meituan batch rank type list is not re-inlined');
requireNoText('public/index.html', 'const rankTypeNames = {', 'Meituan batch rank labels are not re-inlined');
requireNoText('public/index.html', 'const missingResourceFields = [];', 'Meituan batch fetch input validation is not re-inlined');
requireNoText('public/index.html', "meituanForm.value.dateRanges.includes('custom')", 'Meituan batch custom-date validation is not re-inlined');
requireNoText('public/index.html', 'display_hotels: results.flatMap', 'Meituan display model payload is not re-inlined');
requireNoText('public/index.html', 'const getDefaultDataConfigForm = () => ({', 'data config default form is not re-inlined');
requireNoText('public/index.html', 'const rows = [...globalNotificationBackendItems.value];', 'global notification row aggregation is not re-inlined');
requireNoText('public/index.html', 'autoFetchRecentRuns.value.slice(0, 3).forEach', 'global notification recent-run loop is not re-inlined');
requireNoText('public/index.html', 'const readSet = new Set(globalNotificationReadIds.value);', 'global notification read-set mapping is not re-inlined');
requireText('public/index.html', 'history-strategy-reuse', 'strategy history reuse button has stable selector');
requireText('public/index.html', 'history-simulation-reuse', 'simulation history reuse button has stable selector');
requireText('public/index.html', 'history-expansion-reuse', 'expansion history reuse button has stable selector');
requireText('public/index.html', 'history-transfer-reuse', 'transfer history reuse button has stable selector');
requireText('public/index.html', 'field-strategy-city', 'strategy city field has stable selector');
requireText('public/index.html', 'field-simulation-adr', 'simulation ADR field has stable selector');
requireText('public/index.html', 'field-market-business-area', 'market business area field has stable selector');
requireText('public/index.html', 'field-transfer-pricing-', 'transfer pricing fields have stable selectors');
requireTextInFiles(['public/index.html', 'public/ota-diagnosis-static.js'], 'result.diagnosis_sections', 'OTA diagnosis UI renders backend-provided diagnosis sections');
requireText('public/index.html', "requireOtaDiagnosisStatic('buildOtaDiagnosisFetchContext')", 'entry uses extracted OTA diagnosis fetch context builder');
requireText('public/index.html', "requireOtaDiagnosisStatic('buildOtaDiagnosisFetchTasks')", 'entry uses extracted OTA diagnosis fetch task builder');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchContext', 'OTA diagnosis static builds fetch context');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchTasks', 'OTA diagnosis static builds fetch tasks');
requireText('public/index.html', '<script src="ai-analysis-static.js"></script>', 'frontend loads extracted AI analysis static helper');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryRequestBody')", 'entry uses extracted AI analysis summary request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisStartContext')", 'entry uses extracted AI analysis start context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisRunContext')", 'entry uses extracted AI analysis run context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaGroupOutcome')", 'entry uses extracted AI analysis group outcome builder');
requireText('public/index.html', "requireAiAnalysisStatic('applyCapturedOtaGroupRunState')", 'entry uses extracted AI analysis group state updater');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryContext')", 'entry uses extracted AI analysis summary context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryResponseResult')", 'entry uses extracted AI analysis summary response builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisCompletion')", 'entry uses extracted AI analysis completion builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCtripAiAnalysisHotelSelection')", 'entry uses extracted Ctrip AI analysis hotel selection builder');
requireText('public/index.html', "requireAiAnalysisStatic('sanitizeAiReportHtml')", 'entry uses extracted AI report sanitizer');
requireText('public/index.html', "requireAiAnalysisStatic('aiReportHtmlToText')", 'entry uses extracted AI report text converter');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHotelList')", 'entry uses extracted Meituan AI hotel list builder');
requireText('public/index.html', "requireAiAnalysisStatic('resolveMeituanAiSelectedData')", 'entry uses extracted Meituan AI selection resolver');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisRequestBody')", 'entry uses extracted Meituan AI request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHistoryRecord')", 'entry uses extracted Meituan AI history builder');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaHotelPayload', 'AI analysis static builds captured OTA hotel payloads');
requireText('public/ai-analysis-static.js', 'const buildCtripAiAnalysisHotelSelection', 'AI analysis static builds Ctrip hotel selections');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunPlan', 'AI analysis static builds captured OTA run plans');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisStartContext', 'AI analysis static builds captured OTA start context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunContext', 'AI analysis static builds captured OTA run context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaGroupOutcome', 'AI analysis static builds captured OTA group outcomes');
requireText('public/ai-analysis-static.js', 'const applyCapturedOtaGroupRunState', 'AI analysis static applies captured OTA group state updates');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryRequestBody', 'AI analysis static builds captured OTA summary requests');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryContext', 'AI analysis static builds captured OTA summary context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryResponseResult', 'AI analysis static builds captured OTA summary response results');
requireText('public/ai-analysis-static.js', 'const buildCapturedFallbackSummaryReport', 'AI analysis static builds fallback summary reports');
requireText('public/ai-analysis-static.js', 'const resolveAiSelectedData', 'AI analysis static resolves selected hotel rows');
requireText('public/ai-analysis-static.js', 'const validateCapturedOtaAiAnalysisStart', 'AI analysis static validates analysis start inputs');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisCompletion', 'AI analysis static builds captured OTA completion state');
requireText('public/ai-analysis-static.js', 'const sanitizeAiReportHtml', 'AI analysis static sanitizes report HTML');
requireText('public/ai-analysis-static.js', 'const aiReportHtmlToText', 'AI analysis static converts report HTML to text');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHotelList', 'AI analysis static builds Meituan hotel selections');
requireText('public/ai-analysis-static.js', 'const resolveMeituanAiSelectedData', 'AI analysis static resolves Meituan selected hotels');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisRequestBody', 'AI analysis static builds Meituan AI request bodies');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHistoryRecord', 'AI analysis static builds Meituan AI history records');
requireNoText('public/index.html', 'const pushOtaDiagnosisFetchTask = (tasks, task) => {', 'OTA diagnosis task push helper is not re-inlined');
requireNoText('public/index.html', "['P_RZ', 'P_XS', 'P_ZH', 'P_LL'].forEach(rankType => {", 'OTA diagnosis Meituan task list is not re-inlined');
requireNoText('public/index.html', 'const aiAnalysisStatusText = (status) => {', 'AI analysis status text helper is not re-inlined');
requireNoText('public/index.html', 'const chunkArray = (items, size) => {', 'AI analysis chunk helper is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaHotelPayload = (hotel) => {', 'AI analysis captured payload builder is not re-inlined');
requireNoText('public/index.html', "const key = (h.hotelId || h.id) + '_' + (h.hotelName || h.name);", 'Ctrip AI analysis hotel selection is not re-inlined');
requireNoText('public/index.html', "const key = h.poiId + '_' + h.hotelName;", 'Meituan AI analysis hotel key building is not re-inlined');
requireNoText('public/index.html', 'existing.amountRank = existing.amountRank === 0 ?', 'Ctrip AI analysis rank merge is not re-inlined');
requireNoText('public/index.html', 'const hotelsPayload = selectedData.map(buildCapturedOtaHotelPayload)', 'AI analysis run plan is not re-inlined');
requireNoText('public/index.html', 'const groupSize = isDeepSeekProAnalysisModel() ? 3 : 5;', 'AI analysis group sizing is not re-inlined');
requireNoText('public/index.html', 'const selectedData = resolveAiSelectedData(aiSelectedHotels.value, aiAnalysisHotelList.value);', 'AI analysis selected data resolution is not re-inlined');
requireNoText('public/index.html', 'const startValidation = validateCapturedOtaAiAnalysisStart({', 'AI analysis start validation context is not re-inlined');
requireNoText('public/index.html', 'const runPlan = buildCapturedOtaAnalysisRunPlan({', 'AI analysis run plan context is not re-inlined');
requireNoText('public/index.html', 'aiSelectedHotels.value.map(key => {', 'AI selected hotel lookup is not re-inlined');
requireNoText('public/index.html', 'if (aiSelectedHotels.value.length === 0) {', 'AI selected hotel start validation is not re-inlined');
requireNoText('public/index.html', 'if (!onlineDataFilter.value.start_date || !onlineDataFilter.value.end_date) {', 'AI date range start validation is not re-inlined');
requireNoText('public/index.html', 'if (onlineDataFilter.value.start_date > onlineDataFilter.value.end_date) {', 'AI date order start validation is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisHistory.value.unshift(buildAiAnalysisHistoryRecord({', 'AI analysis completion history is not re-inlined');
requireNoText('public/index.html', 'if (aiAnalysisHistory.value.length > 10) {', 'AI analysis history trim is not re-inlined');
requireNoText('public/index.html', "item.status === 'success' && item.result", 'AI group success filtering is not re-inlined');
requireNoText('public/index.html', "item.status === 'failed' || item.error", 'AI group failure filtering is not re-inlined');
requireNoText('public/index.html', 'failedGroups.map(item => `第 ${item.group_index} 组：', 'AI group failure reason is not re-inlined');
requireNoText('public/index.html', 'groupState.result = result.result;', 'AI group success result update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += group.length;', 'AI group success count update is not re-inlined');
requireNoText('public/index.html', 'groupState.error = result.error;', 'AI group failure state update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += retryResult.successCount;', 'AI retry completed count update is not re-inlined');
requireNoText('public/index.html', 'if (summaryRes.code === 200) {', 'AI summary success response handling is not re-inlined');
requireNoText('public/index.html', 'const summaryData = summaryRes.data || {};', 'AI summary data extraction is not re-inlined');
requireNoText('public/index.html', "reason: summaryRes.message || '汇总失败'", 'AI summary fallback response handling is not re-inlined');
requireNoText('public/index.html', 'selectedCount: hotelsPayload.length,', 'AI summary selected count context is not re-inlined');
requireNoText('public/index.html', 'groupCount: aiAnalysisBatchResults.value.length,', 'AI summary group count context is not re-inlined');
requireNoText('public/index.html', 'completedHotels: aiAnalysisProgress.value.completedHotels,', 'AI summary completed count context is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaSummaryRequestBody = ({', 'AI analysis summary request builder is not re-inlined');
requireNoText('public/index.html', 'total_hotels: selectedData.length,', 'Meituan AI analysis request body is not re-inlined');
requireNoText('public/index.html', 'selectedData.slice(0, 3).map(h => h.hotelName)', 'Meituan AI analysis history naming is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedFallbackSummaryReport = ({', 'AI analysis fallback summary builder is not re-inlined');
requireNoText('public/index.html', 'const sanitizeAiReportHtml = (value) => {', 'AI report sanitizer is not re-inlined');
requireNoText('public/index.html', 'const aiReportHtmlToText = (value) => {', 'AI report text converter is not re-inlined');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireNoText('public/index.html', "openDataConfigModal('ctrip-comments')", 'Ctrip comment capture card is not exposed in UI');
requireNoText('public/index.html', "openDataConfigModal('meituan-comments')", 'Meituan comment capture card is not exposed in UI');
requireNoText('public/index.html', '<option value="comment">评价</option>', 'platform data source form does not offer comment data type');
requireNoText('public/index.html', '<option value="review">点评数据</option>', 'online data history filter does not offer review data type');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'service-quality'", 'revenue research exposes service-quality product instead of review-topic');
requireNoTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'review-topic'", 'revenue research does not expose review-topic product');
requireText('app/service/RevenueResearchService.php', "'service-quality' =>", 'revenue research backend supports service-quality product');
requireNoText('app/service/RevenueResearchService.php', "'review-topic' =>", 'revenue research backend does not support review-topic product');
requireTextInFiles(['public/index.html', 'public/operation-static.js'], 'service_quality', 'operation dashboard renders service quality data');
requireText('public/operation-static.js', 'buildOperationSourceBrief', 'operation source brief builder lives in operation static module');
requireText('public/operation-static.js', 'buildOperationDecisionCards', 'operation decision card builder lives in operation static module');
requireText('public/index.html', 'buildOperationDecisionCards(operationFullData.value || {}, operationDisplayFormatters)', 'operation dashboard uses extracted decision card builder');
requireNoText('public/index.html', 'operationFullData.reviews', 'operation dashboard does not render disabled review data');
requireText('app/service/OperationManagementService.php', "'service_quality' => $serviceQuality", 'operation full data returns service quality summary');
requireNoText('app/service/OperationManagementService.php', "'reviews' => $reviews", 'operation full data does not depend on review summary');
requireNoText('public/index.html', "onlineDataTab === 'ctrip-review'", 'Ctrip hidden review tab is removed from frontend');
requireNoText('public/index.html', "onlineDataTab === 'meituan-review'", 'Meituan hidden review tab is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'ctrip-comments'", 'Ctrip comment config modal is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'meituan-comments'", 'Meituan comment config modal is removed from frontend');
requireNoText('public/index.html', "/online-data/fetch-ctrip-comments", 'frontend does not call Ctrip comment fetch endpoint');
requireNoText('public/index.html', "/online-data/capture-ctrip-comments-browser", 'frontend does not call Ctrip browser comment capture endpoint');
requireNoText('public/index.html', "/online-data/fetch-meituan-comments", 'frontend does not call Meituan comment fetch endpoint');
requireText('public/index.html', 'online-data-ota-supplement', 'online data page renders daily OTA supplement summary panel');
requireText('public/index.html', 'ota_channel_supplement', 'frontend consumes OTA supplement summary from daily data summary');
requireText('app/controller/OnlineData.php', "'ota_channel_supplement' =>", 'daily data summary returns OTA supplement summary');
requireText('app/controller/OnlineData.php', "'scope' => 'ota_channel'", 'OTA supplement summary is explicitly scoped to OTA channel');

requireText('tests/automation/e2e-helpers.js', 'function modulePath', 'helpers expose module path mapping');
requireText('tests/automation/e2e-helpers.js', 'function testIdForModule', 'helpers expose module test id selector');
requireText('tests/automation/e2e-helpers.js', 'function semanticInputValue', 'helpers generate field-semantic input values');
requireText('tests/automation/e2e-helpers.js', 'async function waitForApiOrState', 'helpers wait by API response or state assertion');
requireText('tests/automation/e2e-helpers.js', 'function classifyApiStatus', 'helpers classify API status by failure type');
requireText('tests/automation/e2e-helpers.js', "status === 400 || status === 422", 'helpers classify validation failures as invalid test data');

requireText('tests/automation/full-click-coverage.spec.js', 'backupDatabase', 'full click test backs up database before mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'restoreDatabase', 'full click test can restore database after mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'semanticInputValue', 'full click test uses semantic input generator');
requireText('tests/automation/full-click-coverage.spec.js', "category: 'safe-skip'", 'full click report classifies safe skips');
requireText('tests/automation/full-click-coverage.spec.js', "'test-data-invalid'", 'full click report classifies invalid test data');
requireText('tests/automation/full-click-coverage.spec.js', "'product-bug'", 'full click report classifies product bugs');
requireText('tests/automation/full-click-coverage.spec.js', 'summary.json', 'full click test writes classified summary');
requireText('tests/automation/full-click-coverage.spec.js', 'MIN_KEY_FUNCTION_LOOPS = 50', 'full click key-function validation starts at 50 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'MAX_KEY_FUNCTION_LOOPS = 100', 'full click key-function validation caps at 100 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'parseKeyFunctionLoopCount', 'full click clamps key-function loop count');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MIN_LOOP', 'full click can lower loop floor for bounded runs');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MAX_LOOP', 'full click can cap loop count for bounded runs');
requireNoText('tests/automation/full-click-coverage.spec.js', 'waitForTimeout', 'full click test avoids fixed sleeps');

requireText('tests/automation/edge-input-guard.spec.js', 'edgeCasesForField', 'edge input guard generates boundary input cases');
requireText('tests/automation/edge-input-guard.spec.js', 'installEdgeApiMocks', 'edge input guard mocks mutating APIs by default');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_EDGE_LIVE_API', 'edge input guard can opt into live API mode');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_USERNAME', 'edge input guard uses E2E username override');
requireText('tests/automation/edge-input-guard.spec.js', 'mocked-response', 'edge input guard records mocked validation responses');
requireText('tests/automation/edge-input-guard.spec.js', 'classifyConsoleEvent', 'edge input guard classifies expected console validation errors');
requireText('tests/automation/edge-input-guard.spec.js', "row.category === 'page-error'", 'edge input guard fails on real page-error diagnostics');
requireText('tests/automation/edge-input-guard.spec.js', 'script-like-text', 'edge input guard covers script-like text safely as field input');
requireText('tests/automation/edge-input-guard.spec.js', 'maxFieldsPerModule: clampInt(process.env.E2E_EDGE_MAX_FIELDS_PER_MODULE, 12', 'edge input guard has bounded default field scan');
requireText('tests/automation/edge-input-guard.spec.js', 'maxActionsPerModule: clampInt(process.env.E2E_EDGE_MAX_ACTIONS_PER_MODULE, 8', 'edge input guard has bounded default action scan');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.USERNAME', 'edge input guard avoids OS username environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.PASSWORD', 'edge input guard avoids generic password environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'waitForTimeout', 'edge input guard avoids fixed sleeps');

requireText('tests/automation/module-smoke.spec.js', 'goModule', 'module smoke reuses stable module navigation helper');
requireText('tests/automation/module-smoke.spec.js', 'semanticInputValue', 'module smoke uses semantic input generator');
requireText('tests/automation/module-smoke.spec.js', 'waitForApiOrState', 'module smoke waits by API response or state assertion');
requireText('tests/automation/module-smoke.spec.js', 'category: classifyError(error)', 'module smoke classifies failures in report');
requireNoText('tests/automation/module-smoke.spec.js', 'waitForTimeout', 'module smoke avoids fixed sleeps');
requireNoText('tests/automation/module-smoke.spec.js', 'getByText', 'module smoke avoids text-only navigation selectors');

requireText('tests/automation/async-page-guard.spec.js', 'installHistoryFixtures', 'async guard uses deterministic history fixtures');
requireText('tests/automation/async-page-guard.spec.js', 'waitForResponse', 'async guard waits for delayed detail response');
requireNoText('tests/automation/async-page-guard.spec.js', 'waitForTimeout', 'async guard avoids fixed sleeps');

requireText('tests/automation/business-chains.spec.js', 'business chain: OTA import to revenue', 'business chain covers OTA to operation');
requireText('tests/automation/business-chains.spec.js', 'business chain: market evaluation to transfer', 'business chain covers market to transfer');
requireText('tests/automation/business-chains.spec.js', 'business chain: strategy, quant simulation, feasibility', 'business chain covers investment decision');
requireText('tests/automation/business-chains.spec.js', '/api/online-data/save-daily-data', 'business chain imports OTA data through API');
requireText('tests/automation/business-chains.spec.js', '/api/operation/action-tracking', 'business chain asserts operation action tracking');
requireText('tests/automation/business-chains.spec.js', '/api/transfer/dashboard', 'business chain asserts transfer dashboard reads upstream results');
requireText('tests/automation/business-chains.spec.js', '/api/agent/feasibility-report/generate', 'business chain asserts feasibility report persistence');
requireText('tests/automation/business-chains.spec.js', 'E2E_API_REQUEST_TIMEOUT_MS', 'business chain API client has configurable timeout');
requireText('tests/automation/business-chains.spec.js', 'timeout: apiRequestTimeout', 'business chain applies API timeout to auth and business calls');
requireText('tests/automation/business-chains.spec.js', "'test-data-invalid'", 'business chain classifies invalid test data');
requireText('tests/automation/business-chains.spec.js', "'product-bug'", 'business chain classifies product bugs');
requireNoText('tests/automation/business-chains.spec.js', 'waitForTimeout', 'business chain avoids fixed sleeps');

requireText('tests/automation/README.md', 'test:e2e:business', 'README documents business-chain test command');
requireText('tests/automation/README.md', 'test:e2e:edge', 'README documents edge input guard command');
requireText('tests/automation/README.md', 'E2E_EDGE_LIVE_API=0', 'README documents edge test safe mocked API mode');
requireText('tests/automation/README.md', '`product-bug`', 'README documents product bug category');
requireText('tests/automation/README.md', '`test-data-invalid`', 'README documents invalid test data category');

requireText('package.json', 'test:e2e:quick', 'package exposes quick CI e2e command');
requireText('package.json', 'test:e2e:business', 'package exposes business chain e2e command');
requireText('package.json', 'test:e2e:edge', 'package exposes edge input e2e command');
requireText('package.json', 'test:e2e:ui', 'package exposes UI automation e2e command');
requireText('package.json', 'test:e2e:full:bounded', 'package exposes bounded full-click e2e command');

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/meituan-static.js'), context, {
    filename: 'public/meituan-static.js',
  });
  const meituanStatic = context.window.SUXI_MEITUAN_STATIC || {};
  const buildMeituanBatchFetchTasks = meituanStatic.buildMeituanBatchFetchTasks;
  const buildMeituanBatchFetchResultEntry = meituanStatic.buildMeituanBatchFetchResultEntry;
  const buildMeituanDisplayModelPayload = meituanStatic.buildMeituanDisplayModelPayload;
  const validateMeituanBatchFetchInput = meituanStatic.validateMeituanBatchFetchInput;
  if (typeof buildMeituanBatchFetchTasks !== 'function'
    || typeof buildMeituanBatchFetchResultEntry !== 'function'
    || typeof buildMeituanDisplayModelPayload !== 'function'
    || typeof validateMeituanBatchFetchInput !== 'function') {
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan static exports batch fetch builders',
      ok: false,
      detail: 'batch fetch builders',
    });
  } else {
    const tasks = buildMeituanBatchFetchTasks({
      form: {
        url: 'https://example.test/rank',
        hotelId: '10',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'demo' },
      },
      partnerId: 'partner-1',
      poiId: 'poi-1',
      cookies: 'mt-cookie',
    });
    const missingCookieValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: '',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const missingResourceValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: 'mt-cookie',
      partnerId: '',
      poiId: '',
    });
    const missingCustomDateValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '', endDate: '' },
      cookies: 'mt-cookie',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const validBatchInput = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '2026-06-01', endDate: '2026-06-10' },
      cookies: ' mt-cookie ',
      partnerId: ' partner-1 ',
      poiId: ' poi-1 ',
    });
    const customTask = tasks.find(task => task.rankType === 'P_LL' && task.dateRange === 'custom');
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch input validator keeps missing-state signals explicit',
      ok: missingCookieValidation.ok === false
        && missingCookieValidation.level === 'error'
        && missingCookieValidation.message.includes('平台授权缺失')
        && missingResourceValidation.ok === false
        && missingResourceValidation.level === 'warning'
        && missingResourceValidation.message.includes('平台接口标识 / 平台门店标识')
        && missingCustomDateValidation.ok === false
        && missingCustomDateValidation.message.includes('自定义时间')
        && validBatchInput.ok === true
        && validBatchInput.cookies === 'mt-cookie'
        && validBatchInput.partnerId === 'partner-1'
        && validBatchInput.poiId === 'poi-1',
      detail: 'validateMeituanBatchFetchInput sample',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch task builder covers four rank types and custom dates',
      ok: tasks.length === 8
        && tasks.some(task => task.rankType === 'P_RZ' && task.dateRange === '1')
        && tasks.some(task => task.rankType === 'P_ZH' && task.dateRange === 'custom')
        && customTask?.body?.start_date === '2026-06-01'
        && customTask?.body?.end_date === '2026-06-10'
        && customTask?.body?.partner_id === 'partner-1'
        && customTask?.body?.poi_id === 'poi-1'
        && customTask?.body?.cookies === 'mt-cookie'
        && customTask?.body?.system_hotel_id === '10',
      detail: 'buildMeituanBatchFetchTasks sample',
    });
    const successEntry = buildMeituanBatchFetchResultEntry(tasks[0], {
      code: 200,
      data: {
        data: [{ rank: 1 }],
        saved_count: 3,
        display_hotels: [{ poiId: 'poi-1', hotelName: 'Demo' }],
        display_summary: { total: 1 },
        display_hotel_count: 1,
      },
    });
    const failedEntry = buildMeituanBatchFetchResultEntry(tasks[1], { code: 500, message: 'upstream failed' });
    const modelPayload = buildMeituanDisplayModelPayload({
      results: [successEntry, failedEntry],
      form: {
        competitorRoomCount: '20',
        poiId: 'poi-1',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch result and display payload builders preserve response evidence',
      ok: successEntry.savedCount === 3
        && successEntry.displayCount === 1
        && failedEntry.error === 'upstream failed'
        && Array.isArray(modelPayload.display_hotels)
        && modelPayload.display_hotels.length === 1
        && modelPayload.target_poi_id === 'poi-1'
        && modelPayload.competitor_room_count === '20',
      detail: 'Meituan batch result sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/meituan-static.js',
    label: 'Meituan static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ota-diagnosis-static.js'), context, {
    filename: 'public/ota-diagnosis-static.js',
  });
  const otaDiagnosisStatic = context.window.SUXI_OTA_DIAGNOSIS_STATIC || {};
  const buildOtaDiagnosisFetchContext = otaDiagnosisStatic.buildOtaDiagnosisFetchContext;
  const buildOtaDiagnosisFetchTasks = otaDiagnosisStatic.buildOtaDiagnosisFetchTasks;
  if (typeof buildOtaDiagnosisFetchContext !== 'function' || typeof buildOtaDiagnosisFetchTasks !== 'function') {
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis static exports fetch task builders',
      ok: false,
      detail: 'buildOtaDiagnosisFetchContext/buildOtaDiagnosisFetchTasks',
    });
  } else {
    const fetchContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '10', hotel_id: '10' },
      form: { hotel_id: '10', start_date: '2026-06-01', end_date: '2026-06-10' },
      ctripConfig: { url: 'ctrip-url', node_id: '24588', cookies: 'ctrip-cookie', auth_data: { ok: true }, ctrip_hotel_id: 'ctrip-10', name: 'Ctrip Demo' },
      ctripTrafficConfig: { url: 'traffic-url', cookies: 'traffic-cookie', platform: 'Ctrip', extra_params: 'foo=1' },
      ctripCookieApiConfig: { endpoints_json: '[{"request_url":"u"}]', headers_json: 'Cookie: header-cookie', profile_id: 'profile-10', method: 'POST', system_hotel_id: '10', ctrip_hotel_id: 'hotel-10' },
      meituanConfig: { url: 'meituan-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'meituan-cookie', data_scope: 'vpoi' },
      meituanTrafficConfig: { url: 'meituan-traffic-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'mt-cookie', system_hotel_id: '10' },
    });
    const tasks = buildOtaDiagnosisFetchTasks({ context: fetchContext });
    const taskLabels = tasks.map(task => task.label);
    const cookieApiTask = tasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps Ctrip and Meituan task coverage',
      ok: fetchContext.systemHotelId === '10'
        && fetchContext.ctripCookieApiCookies === 'header-cookie'
        && fetchContext.hasCtripCookieApiRequests === true
        && taskLabels.includes('ctrip-business')
        && taskLabels.includes('ctrip-traffic')
        && taskLabels.includes('ctrip-cookie-api')
        && taskLabels.includes('meituan-P_RZ')
        && taskLabels.includes('meituan-P_LL')
        && taskLabels.includes('meituan-traffic')
        && cookieApiTask?.body?.request_source === 'saved_config',
      detail: 'buildOtaDiagnosisFetchTasks saved config sample',
    });
    const coreContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '20' },
      form: { hotel_id: '20', start_date: '2026-06-02', end_date: '2026-06-02' },
      ctripCookieApiConfig: { profile_id: 'profile-20' },
    });
    const coreTasks = buildOtaDiagnosisFetchTasks({
      context: coreContext,
      genericCtripCookie: { cookies: 'generic-cookie' },
      useCtripCorePresetForDiagnosis: true,
      ctripCorePresetReason: 'generic_cookie',
      ctripCorePresetJson: '[{"request_url":"core"}]',
    });
    const coreTask = coreTasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps core preset source explicit',
      ok: coreTask?.body?.request_source === 'core_preset:generic_cookie'
        && coreTask?.body?.cookies === 'generic-cookie'
        && coreTask?.body?.endpoints_json === '[{"request_url":"core"}]',
      detail: 'core_preset',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ota-diagnosis-static.js',
    label: 'OTA diagnosis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ai-analysis-static.js'), context, {
    filename: 'public/ai-analysis-static.js',
  });
  const aiAnalysisStatic = context.window.SUXI_AI_ANALYSIS_STATIC || {};
  const requiredKeys = [
    'getAiAnalysisHotelKey',
    'sanitizeAiReportHtml',
    'aiReportHtmlToText',
    'aiAnalysisStatusText',
    'aiAnalysisPriorityText',
    'normalizeAiAnalysisList',
    'normalizeAiProblemHotels',
    'maskAiAnalysisError',
    'chunkArray',
    'resolveAiSelectedData',
    'validateCapturedOtaAiAnalysisStart',
    'buildCapturedOtaHotelPayload',
    'buildCtripAiAnalysisHotelSelection',
    'buildAiAnalysisProgress',
    'buildAiAnalysisBatchResults',
    'buildCapturedOtaAnalysisRunPlan',
    'buildCapturedOtaAnalysisStartContext',
    'buildCapturedOtaAnalysisRunContext',
    'buildCapturedOtaGroupOutcome',
    'applyCapturedOtaGroupRunState',
    'buildCapturedOtaSummaryRequestBody',
    'buildCapturedOtaSummaryContext',
    'buildCapturedOtaSummaryResponseResult',
    'buildCapturedFallbackSummaryReport',
    'buildAiAnalysisHistoryRecord',
    'buildCapturedOtaAnalysisCompletion',
    'getMeituanAiAnalysisHotelKey',
    'buildMeituanAiAnalysisHotelList',
    'resolveMeituanAiSelectedData',
    'buildMeituanAiAnalysisRequestBody',
    'buildMeituanAiAnalysisHistoryRecord',
  ];
  const missingKeys = requiredKeys.filter(key => typeof aiAnalysisStatic[key] !== 'function');
  if (missingKeys.length > 0) {
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static exports required builders',
      ok: false,
      detail: missingKeys.join(', '),
    });
  } else {
    const hotelPayload = aiAnalysisStatic.buildCapturedOtaHotelPayload({
      poiId: 'ctrip-10',
      hotelName: '示例酒店',
      roomNights: '2',
      roomRevenue: '360',
      exposure: '1200',
      views: '88',
      totalOrderNum: '6',
      viewConversion: '7.5',
      payConversion: '3.2',
      amountRank: '5',
      quantityRank: '3',
      commentScore: '4.8',
    });
    const groups = aiAnalysisStatic.chunkArray([hotelPayload, { hotel_name: 'B' }, { hotel_name: 'C' }], 2);
    const hotelSelection = aiAnalysisStatic.buildCtripAiAnalysisHotelSelection({
      ctripHotels: [
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          quantity: 2,
          amount: 300,
          views: 10,
          exposure: 100,
          amountRank: 5,
        },
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          roomNights: 3,
          roomRevenue: 480,
          salesRoomNights: 4,
          sales: 620,
          totalDetailNum: 20,
          exposure: 200,
          amountRank: 2,
          quantityRank: 4,
        },
        {
          id: 'h2',
          name: 'Beta',
          convertionRate: '6.5',
          qunarDetailCRRank: 3,
        },
      ],
      selectedKeys: ['h1_Alpha', 'missing_Key'],
    });
    const progress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 3, groupCount: groups.length });
    const batchResults = aiAnalysisStatic.buildAiAnalysisBatchResults(groups, 12345);
    const runPlan = aiAnalysisStatic.buildCapturedOtaAnalysisRunPlan({
      selectedData: [
        {
          poiId: 'r1',
          hotelName: 'Run One',
          roomNights: 2,
          roomRevenue: 500,
        },
        {
          poiId: 'r2',
          hotelName: 'Run Two',
          roomNights: 1,
          sales: 260,
        },
        {
          poiId: 'r3',
          hotelName: 'Run Three',
          roomNights: 1,
          sales: 220,
        },
        {
          poiId: 'r4',
          hotelName: 'Run Four',
          roomNights: 1,
          sales: 180,
        },
      ],
      isDeepSeekPro: true,
      timestamp: 67890,
    });
    const startContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: ['r1_Run One'],
      hotels: [
        { poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 },
        { poiId: 'r2', hotelName: 'Run Two', roomNights: 1, sales: 260 },
      ],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingStartContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: [],
      hotels: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const runContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: startContext.selectedData,
      isDeepSeekPro: false,
      timestamp: 24680,
    });
    const emptyRunContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: [],
      isDeepSeekPro: false,
      timestamp: 13579,
    });
    const selectedRows = aiAnalysisStatic.resolveAiSelectedData(
      ['r1_Run One', 'missing_Key'],
      [
        { poiId: 'r1', hotelName: 'Run One' },
        { poiId: 'r2', hotelName: 'Run Two' },
      ],
    );
    const missingSelectedValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: [],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDataValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '',
      endDate: '',
    });
    const invalidDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-10',
      endDate: '2026-06-01',
    });
    const validStartValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const successGroup = {
      ...batchResults[0],
      status: 'success',
      result: {
        overall_conclusion: '订单转化偏弱',
        key_findings: ['曝光充足'],
        competitor_insights: ['竞对价格更稳'],
        problem_hotels: ['酒店：示例酒店；问题：转化偏低；关键指标：曝光、订单；建议：复核价格'],
        recommended_actions: ['调整促销'],
        priority: 'high',
        data_anomalies: [],
      },
    };
    const summaryBody = aiAnalysisStatic.buildCapturedOtaSummaryRequestBody({
      platform: 'ctrip',
      modelKey: 'deepseek_chat',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      selectedHotelCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const fallback = aiAnalysisStatic.buildCapturedFallbackSummaryReport({
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
      reason: 'Bearer token-secret',
    });
    const summaryContext = aiAnalysisStatic.buildCapturedOtaSummaryContext({
      hotelsPayload: runPlan.hotelsPayload,
      progress: { completedHotels: '3', failedHotels: '1' },
      batchResults: runPlan.batchResults,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const summarySuccessResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: {
        code: 200,
        data: {
          report: { overall_conclusion: '汇总成功' },
          process: { steps: ['汇总'] },
        },
      },
      successGroups: [successGroup],
      failedGroups: [],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const summaryFallbackResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: { code: 500, message: 'Bearer token-secret' },
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const history = aiAnalysisStatic.buildAiAnalysisHistoryRecord({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成' },
      completedHotels: 2,
      failedHotels: 1,
      reportHtml: '<section>ok</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const completion = aiAnalysisStatic.buildCapturedOtaAnalysisCompletion({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成', key_findings: ['曝光充足'] },
      completedHotels: 2,
      failedHotels: 1,
      existingHistory: [{ id: 1 }, { id: 2 }, { id: 3 }],
      historyLimit: 3,
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const groupOutcome = aiAnalysisStatic.buildCapturedOtaGroupOutcome([
      { groupIndex: 1, hotelCount: 2, status: 'success', result: { priority: 'medium' } },
      { groupIndex: 2, hotelCount: 1, status: 'failed', error: 'model failed' },
      { groupIndex: 3, hotelCount: 1, status: 'pending', error: 'timeout' },
    ]);
    const groupStateSuccess = { status: 'running', result: null };
    const progressStateSuccess = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateSuccess,
      progressState: progressStateSuccess,
      group: [{ hotel_name: 'A' }, { hotel_name: 'B' }],
      result: { ok: true, result: { overall_conclusion: '成功' } },
    });
    const groupStateFailure = { status: 'running', error: '', errorDetails: null };
    const progressStateFailure = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateFailure,
      result: { ok: false, error: 'failed', errorDetails: { error_type: 'model_error' } },
    });
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      progressState: progressStateFailure,
      result: { ok: false },
      retryResult: { successCount: '1', failedCount: '2' },
    });
    const meituanHotels = aiAnalysisStatic.buildMeituanAiAnalysisHotelList([
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '2', roomRevenue: '300', views: '40' },
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '5', roomRevenue: '800', views: '80' },
      { poiId: 'm2', hotelName: 'Meituan Two', sales: '260', exposure: '900' },
    ]);
    const meituanSelectedData = aiAnalysisStatic.resolveMeituanAiSelectedData(['m1_Meituan One', 'missing_Key'], meituanHotels);
    const meituanRequestBody = aiAnalysisStatic.buildMeituanAiAnalysisRequestBody(meituanSelectedData);
    const meituanHistory = aiAnalysisStatic.buildMeituanAiAnalysisHistoryRecord({
      selectedData: [...meituanSelectedData, { hotelName: 'Meituan Extra A' }, { hotelName: 'Meituan Extra B' }],
      summary: 'Meituan summary',
      report: '<section>meituan</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA payload and batch state',
      ok: hotelPayload.hotel_id === 'ctrip-10'
        && hotelPayload.price === 180
        && hotelPayload.exposure === 1200
        && hotelPayload.tags.includes('最好排名3')
        && groups.length === 2
        && progress.totalHotels === 3
        && progress.totalGroups === 2
        && batchResults[0].key === 'group_12345_0'
        && batchResults[0].hotelNames.includes('示例酒店'),
      detail: 'captured payload batch sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA run plans with model-aware group sizing',
      ok: runPlan.hotelsPayload.length === 4
        && runPlan.groups.length === 2
        && runPlan.groups[0].length === 3
        && runPlan.groups[1].length === 1
        && runPlan.progress.totalHotels === 4
        && runPlan.progress.totalGroups === 2
        && runPlan.batchResults[0].key === 'group_67890_0'
        && runPlan.batchResults[0].hotelNames.includes('Run One')
        && runPlan.batchResults[1].hotelCount === 1
        && startContext.ok === true
        && startContext.selectedData.length === 1
        && startContext.selectedData[0].hotelName === 'Run One'
        && missingStartContext.ok === false
        && runContext.ok === true
        && runContext.message.includes('开始分析 1 家酒店')
        && runContext.batchResults[0].key === 'group_24680_0'
        && emptyRunContext.ok === false
        && emptyRunContext.message === '暂无抓取数据',
      detail: 'captured OTA run plan sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static resolves selections and group outcomes',
      ok: selectedRows.length === 1
        && selectedRows[0].hotelName === 'Run One'
        && groupOutcome.successGroups.length === 1
        && groupOutcome.failedGroups.length === 2
        && groupOutcome.failedGroups[0].group_index === 2
        && groupOutcome.failedGroups[1].hotel_count === 1
        && groupOutcome.failedReason.includes('第 2 组：model failed')
        && groupOutcome.failedReason.includes('第 3 组：timeout')
        && groupStateSuccess.status === 'success'
        && groupStateSuccess.result.overall_conclusion === '成功'
        && progressStateSuccess.completedHotels === 2
        && groupStateFailure.error === 'failed'
        && groupStateFailure.errorDetails.error_type === 'model_error'
        && progressStateFailure.completedHotels === 1
        && progressStateFailure.failedHotels === 2,
      detail: 'selected data and group outcome sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static validates captured OTA start inputs',
      ok: missingSelectedValidation.ok === false
        && missingSelectedValidation.message === '请先选择要分析的酒店'
        && missingDataValidation.ok === false
        && missingDataValidation.message === '未找到选中的酒店数据'
        && missingDateValidation.ok === false
        && missingDateValidation.message === '请选择分析日期范围'
        && invalidDateValidation.ok === false
        && invalidDateValidation.message === '开始日期不能晚于结束日期'
        && validStartValidation.ok === true
        && validStartValidation.level === 'success',
      detail: 'captured OTA start validation sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Ctrip hotel selections without losing merged metrics',
      ok: hotelSelection.hotels.length === 2
        && hotelSelection.selectedKeys.length === 1
        && hotelSelection.selectedKeys[0] === 'h1_Alpha'
        && hotelSelection.hotels[0].poiId === 'h1'
        && hotelSelection.hotels[0].hotelName === 'Alpha'
        && hotelSelection.hotels[0].roomNights === 3
        && hotelSelection.hotels[0].roomRevenue === 480
        && hotelSelection.hotels[0].salesRoomNights === 4
        && hotelSelection.hotels[0].sales === 620
        && hotelSelection.hotels[0].views === 20
        && hotelSelection.hotels[0].exposure === 200
        && hotelSelection.hotels[0].amountRank === 2
        && hotelSelection.hotels[0].quantityRank === 4
        && hotelSelection.hotels[1].poiId === 'h2'
        && hotelSelection.hotels[1].convertionRate === 6.5,
      detail: 'Ctrip AI hotel selection sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Meituan hotel selections and request bodies',
      ok: meituanHotels.length === 2
        && meituanHotels[0].poiId === 'm1'
        && meituanHotels[0].roomNights === '2'
        && meituanSelectedData.length === 1
        && meituanRequestBody.total_hotels === 1
        && meituanRequestBody.source === 'meituan'
        && meituanRequestBody.include_suggestions === true
        && meituanHistory.hotel_count === 3
        && meituanHistory.hotel_names === 'Meituan One、Meituan Extra A、Meituan Extra B'
        && meituanHistory.summary === 'Meituan summary',
      detail: 'Meituan AI selection request history sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds summary and fallback payloads with explicit failures',
      ok: summaryBody.model_key === 'deepseek_chat'
        && summaryBody.group_summaries[0].report.priority === 'high'
        && summaryBody.group_summaries[0].report.problem_hotels[0].problem === '转化偏低'
        && summaryBody.failed_groups.length === 1
        && summaryContext.selectedHotelCount === 4
        && summaryContext.selectedCount === 4
        && summaryContext.completedHotels === 3
        && summaryContext.failedHotels === 1
        && summaryContext.groupCount === 2
        && summaryContext.successGroups.length === 1
        && fallback.fallback === true
        && fallback.summary.failed_hotel_count === 1
        && fallback.fallback_reason === 'Bearer ****'
        && summarySuccessResult.report.overall_conclusion === '汇总成功'
        && summarySuccessResult.process.steps[0] === '汇总'
        && summaryFallbackResult.report.fallback === true
        && summaryFallbackResult.report.fallback_reason === 'Bearer ****'
        && summaryFallbackResult.process === null,
      detail: 'summary fallback sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA completion state',
      ok: completion.reportHtml.includes('已完成')
        && completion.reportHtml.includes('曝光充足')
        && completion.history.length === 3
        && completion.history[0].hotel_names === 'A、B、C等'
        && completion.history[0].summary === '已完成'
        && completion.history[1].id === 1,
      detail: 'captured OTA completion state sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static keeps display labels and sensitive error masking',
      ok: aiAnalysisStatic.aiAnalysisStatusText('running') === '分析中'
        && aiAnalysisStatic.aiAnalysisPriorityText('high') === '高优先级'
        && aiAnalysisStatic.normalizeAiAnalysisList([{ 指标: '曝光', 结论: '偏低' }])[0] === '指标: 曝光；结论: 偏低'
        && aiAnalysisStatic.maskAiAnalysisError('api_key=abc123 sk-abcdefghijk').includes('api_key=****')
        && history.hotel_names === 'A、B、C等'
        && history.summary === '已完成',
      detail: 'labels masks history sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ai-analysis-static.js',
    label: 'AI analysis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ctrip-static.js'), context, {
    filename: 'public/ctrip-static.js',
  });
  const ctripStatic = context.window.SUXI_CTRIP_STATIC || {};
  const buildCtripBrowserCaptureTargetContext = ctripStatic.buildCtripBrowserCaptureTargetContext;
  const buildCtripBrowserCapturePayload = ctripStatic.buildCtripBrowserCapturePayload;
  const buildCtripBrowserCaptureRequestContext = ctripStatic.buildCtripBrowserCaptureRequestContext;
  const normalizeCtripBrowserCaptureErrorResult = ctripStatic.normalizeCtripBrowserCaptureErrorResult;
  const buildCtripFetchDateRange = ctripStatic.buildCtripFetchDateRange;
  const buildCtripFetchRequestBody = ctripStatic.buildCtripFetchRequestBody;
  const buildCtripFetchRequestContext = ctripStatic.buildCtripFetchRequestContext;
  const selectCtripFetchResponsePayload = ctripStatic.selectCtripFetchResponsePayload;
  const buildCtripFetchMeta = ctripStatic.buildCtripFetchMeta;
  const buildCtripFetchRawFailureResult = ctripStatic.buildCtripFetchRawFailureResult;
  const buildLatestCtripSnapshotModel = ctripStatic.buildLatestCtripSnapshotModel;
  const buildCtripTrafficFetchRequestBody = ctripStatic.buildCtripTrafficFetchRequestBody;
  const buildCtripTrafficResponseModel = ctripStatic.buildCtripTrafficResponseModel;
  const buildCtripOverviewFetchRequestBody = ctripStatic.buildCtripOverviewFetchRequestBody;
  const buildCtripAdsFetchRequestBody = ctripStatic.buildCtripAdsFetchRequestBody;
  const buildCtripCookieApiFetchRequestBody = ctripStatic.buildCtripCookieApiFetchRequestBody;
  const defaultCtripAdsEffectReportUrl = ctripStatic.defaultCtripAdsEffectReportUrl;
  const isCtripAdsApiUrl = ctripStatic.isCtripAdsApiUrl;
  const normalizeCtripAdsApiType = ctripStatic.normalizeCtripAdsApiType;
  const buildCtripProfileRecheckInitialState = ctripStatic.buildCtripProfileRecheckInitialState;
  const buildCtripProfileRecheckRunContext = ctripStatic.buildCtripProfileRecheckRunContext;
  const buildCtripProfileRecheckCaptureRefreshState = ctripStatic.buildCtripProfileRecheckCaptureRefreshState;
  const buildCtripProfileRecheckSuccessResult = ctripStatic.buildCtripProfileRecheckSuccessResult;
  const buildCtripProfileRecheckErrorResult = ctripStatic.buildCtripProfileRecheckErrorResult;
  const buildCtripProfileRecheckInterruptedState = ctripStatic.buildCtripProfileRecheckInterruptedState;
  if (typeof buildCtripBrowserCaptureTargetContext !== 'function'
    || typeof buildCtripBrowserCapturePayload !== 'function'
    || typeof buildCtripBrowserCaptureRequestContext !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture context builders',
      ok: false,
      detail: 'buildCtripBrowserCaptureTargetContext/buildCtripBrowserCapturePayload/buildCtripBrowserCaptureRequestContext',
    });
  } else {
    const missingTarget = buildCtripBrowserCaptureTargetContext({});
    const selectedTarget = buildCtripBrowserCaptureTargetContext({
      selectedCtripHotelId: '',
      autoFetchHotelId: '58',
      userHotelId: '99',
    });
    const payload = buildCtripBrowserCapturePayload({
      systemHotelId: '10',
      hotelId: '24588',
      hotelName: 'Demo Hotel',
      profileId: 'profile-1',
      cookies: 'sid=secret',
      dataDate: '2026-06-10',
      form: { sections: 'default traffic', approvedMappingsPath: '  approved.json  ' },
      options: { captureSections: 'ads reviews', loginOnly: true, bindDataSource: false },
    });
    const fallbackPayload = buildCtripBrowserCapturePayload({
      form: { sections: '' },
      options: {},
    });
    const requestContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: {
        ota_hotel_id: 'ota-58',
        ctrip_hotel_id: 'ctrip-ignored',
        cookies: 'sid=request-context',
      },
      form: { hotelId: '', sections: 'business_overview', approvedMappingsPath: ' approved.json ' },
      overviewForm: { hotelId: 'overview-58', dataDate: '2026-06-10' },
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-58',
      options: { loginOnly: false, bindDataSource: true },
    });
    const missingProfileContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: { ota_hotel_id: 'ota-58' },
      form: {},
      profileId: '',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture context keeps target and request fields explicit',
      ok: missingTarget.ok === false
        && missingTarget.result.message === '请选择目标酒店'
        && selectedTarget.ok === true
        && selectedTarget.systemHotelId === '58'
        && requestContext.ok === true
        && requestContext.capturePayload.system_hotel_id === '58'
        && requestContext.capturePayload.hotel_id === 'ota-58'
        && requestContext.capturePayload.hotel_name === 'Tiancheng Hotel'
        && requestContext.capturePayload.profile_id === 'profile-58'
        && requestContext.capturePayload.cookies === 'sid=request-context'
        && requestContext.capturePayload.data_date === '2026-06-10'
        && requestContext.capturePayload.sections[0] === 'business_overview'
        && missingProfileContext.ok === false
        && missingProfileContext.result.message.includes('携程登录会话标识')
        && payload.system_hotel_id === '10'
        && payload.hotel_id === '24588'
        && payload.hotel_name === 'Demo Hotel'
        && payload.profile_id === 'profile-1'
        && payload.cookies === 'sid=secret'
        && payload.data_date === '2026-06-10'
        && payload.login_only === true
        && payload.bind_data_source === false
        && payload.approved_mappings_path === 'approved.json'
        && Array.isArray(payload.sections)
        && payload.sections.join(',') === 'ads,reviews',
      detail: 'buildCtripBrowserCapturePayload sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture payload defaults to default section',
      ok: Array.isArray(fallbackPayload.sections) && fallbackPayload.sections.length === 1 && fallbackPayload.sections[0] === 'default',
      detail: 'sections default',
    });
  }
  if (typeof normalizeCtripBrowserCaptureErrorResult !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture error normalizer',
      ok: false,
      detail: 'normalizeCtripBrowserCaptureErrorResult',
    });
  } else {
    const errorResult = normalizeCtripBrowserCaptureErrorResult({
      message: 'capture failed',
      data: {
        data: {
          stdout: 'out',
          stderr: 'err',
          partial_capture: { available: true, saved_count: 2 },
        },
      },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture error normalizer preserves partial capture evidence',
      ok: errorResult.available === true
        && errorResult.saved_count === 2
        && errorResult.error === 'capture failed'
        && errorResult.stdout === 'out'
        && errorResult.stderr === 'err'
        && errorResult.partial_capture?.available === true,
      detail: 'partial_capture',
    });
  }
  if (typeof buildCtripFetchDateRange !== 'function'
    || typeof buildCtripFetchRequestBody !== 'function'
    || typeof buildCtripFetchRequestContext !== 'function'
    || typeof selectCtripFetchResponsePayload !== 'function'
    || typeof buildCtripFetchMeta !== 'function'
    || typeof buildCtripFetchRawFailureResult !== 'function'
    || typeof buildLatestCtripSnapshotModel !== 'function'
    || typeof buildCtripTrafficFetchRequestBody !== 'function'
    || typeof buildCtripOverviewFetchRequestBody !== 'function'
    || typeof buildCtripAdsFetchRequestBody !== 'function'
    || typeof buildCtripCookieApiFetchRequestBody !== 'function'
    || typeof isCtripAdsApiUrl !== 'function'
    || typeof normalizeCtripAdsApiType !== 'function'
    || typeof buildCtripTrafficResponseModel !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports fetch request builders',
      ok: false,
      detail: 'Ctrip fetch context, latest snapshot, traffic, overview, ads, and Cookie API builders',
    });
  } else {
    const defaultRange = buildCtripFetchDateRange({}, new Date('2026-06-10T12:00:00Z'));
    const explicitRange = buildCtripFetchDateRange({ startDate: '2026-06-01', endDate: '2026-06-10' });
    const fetchBody = buildCtripFetchRequestBody({
      form: { url: ' https://ebooking.ctrip.test/api ', auth_data: { token: 'demo' } },
      cookies: 'sid=abc',
      nodeId: '24588',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      systemHotelId: '58',
    });
    const fallbackBody = buildCtripFetchRequestBody({
      form: { url: '   ' },
      cookies: 'sid=abc',
      startDate: '2026-06-09',
      endDate: '2026-06-09',
    });
    const fetchContext = buildCtripFetchRequestContext({
      form: {
        url: ' https://ebooking.ctrip.test/api ',
        cookies: ' sid=context ',
        nodeId: '24588',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'ctx' },
      },
      selectedCtripHotelId: '58',
    });
    const missingCredentialContext = buildCtripFetchRequestContext({
      form: { cookies: '   ' },
      selectedCtripHotelId: '58',
    });
    const multiDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }, { date: '2026-06-10' }],
      data: [{ ignored: true }],
    });
    const singleDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }],
      data: [{ kept: true }],
    });
    const fetchMeta = buildCtripFetchMeta({
      hotelId: '58',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      fetchedAt: '2026-06-10 14:00:00',
      savedCount: 0,
      displayHotelCount: 7,
    });
    const rawFailure = buildCtripFetchRawFailureResult({
      errorMsg: '授权过期',
      rawResponse: 'x'.repeat(1200),
    });
    const latestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'success', data_date: '2026-06-09' },
      rank: {
        rows: [{ row_id: 'rank-1' }],
        display_hotels: [{ hotelId: 'h1' }],
        display_summary: { cards: [{ key: 'amount' }] },
        total: 3,
        data_date: '2026-06-09',
      },
      traffic: {
        rows: [{ date: '2026-06-09' }],
        display_traffic_rows: [{ date: '2026-06-09', compareType: 'self' }],
        display_traffic_summary: { status: 'ok' },
      },
      review: {
        rows: [{ review_id: 'r1' }],
        total: 2,
      },
    });
    const emptyLatestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'missing', status_label: '暂无入库快照' },
      rank: { rows: [], display_hotels: [] },
      traffic: { rows: [], display_traffic_rows: [] },
      review: { rows: [] },
    });
    const trafficBody = buildCtripTrafficFetchRequestBody({
      form: {
        platform: 'ctrip',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        url: ' https://ebooking.ctrip.test/traffic ',
        extraParams: '{"scope":"self"}',
      },
      cookies: 'sid=traffic',
      systemHotelId: '58',
    });
    const trafficBodyWithoutUrl = buildCtripTrafficFetchRequestBody({
      form: { platform: 'qunar', dateRange: 'yesterday', url: '   ' },
      cookies: 'sid=traffic',
    });
    const overviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=overview',
      requestUrls: 'https://ebooking.ctrip.test/overview',
      form: {
        payloadJson: '{"page":1}',
        spidertoken: 'spider',
        method: 'POST',
        dataDate: '2026-06-09',
      },
      defaultMethod: 'GET',
    });
    const flowOverviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=flow',
      requestUrls: 'https://ebooking.ctrip.test/flow',
      form: {
        payloadJson: '',
        spidertoken: '',
        dataDate: '2026-06-10',
      },
      defaultMethod: 'GET',
    });
    const adsBody = buildCtripAdsFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      url: 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
      cookies: 'sid=ads',
      form: {
        apiType: 'custom_ignored',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    const cookieApiBody = buildCtripCookieApiFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-1',
      dataDate: '2026-06-10',
      requestUrl: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHomePageRealTimeData',
      form: { method: 'post', payloadJson: ' {"scope":"core"} ' },
      endpointsJson: '[{"section":"homepage"}]',
      cookies: 'sid=cookie-api',
    });
    const trafficModel = buildCtripTrafficResponseModel({
      http_code: 200,
      saved_count: 4,
      platform: 'ctrip',
      request_start_date: '2026-06-01',
      request_end_date: '2026-06-10',
      decoded_data: [{ decoded: true }],
      traffic_rows: [{ row_id: 'traffic-1' }],
      display_traffic_rows: [{ date: '2026-06-01', compareType: 'self' }],
      display_traffic_summary: { status: 'ok' },
      raw_response: '{"ok":true}',
      derived_analysis: { conversion: 'stable' },
    });
    const trafficFallbackModel = buildCtripTrafficResponseModel({
      data: [{ decoded: 'fallback' }],
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep request fields and date defaults',
      ok: defaultRange.startDate === '2026-06-09'
        && defaultRange.endDate === '2026-06-09'
        && explicitRange.startDate === '2026-06-01'
        && explicitRange.endDate === '2026-06-10'
        && fetchContext.ok === true
        && fetchContext.requestBody.cookies === 'sid=context'
        && fetchContext.requestBody.node_id === '24588'
        && fetchContext.requestBody.system_hotel_id === '58'
        && fetchContext.requestBody.start_date === '2026-06-01'
        && fetchContext.requestBody.end_date === '2026-06-10'
        && fetchContext.debugMeta.node_id === '24588'
        && missingCredentialContext.ok === false
        && missingCredentialContext.message.includes('平台授权内容')
        && fetchBody.url === 'https://ebooking.ctrip.test/api'
        && fetchBody.node_id === '24588'
        && fetchBody.system_hotel_id === '58'
        && fetchBody.cookies === 'sid=abc'
        && fallbackBody.url === undefined
        && fallbackBody.node_id === undefined
        && fallbackBody.system_hotel_id === null,
      detail: 'Ctrip fetch request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep response and failure evidence explicit',
      ok: Array.isArray(multiDatePayload.date_results)
        && multiDatePayload.date_results.length === 2
        && Array.isArray(singleDatePayload)
        && singleDatePayload[0].kept === true
        && fetchMeta.data_date === '2026-06-01 至 2026-06-10'
        && fetchMeta.total_records === 7
        && rawFailure.error === '授权过期'
        && rawFailure.raw.length === 1000
        && rawFailure.hint.includes('Cookie是否过期'),
      detail: 'Ctrip fetch response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip latest snapshot model keeps payload slices explicit',
      ok: latestModel.metadata.status === 'success'
        && latestModel.hasRank === true
        && latestModel.rankRows.length === 1
        && latestModel.rankDisplayHotels.length === 1
        && latestModel.rankDisplaySummary.cards.length === 1
        && latestModel.rankTotal === 3
        && latestModel.rankDataDate === '2026-06-09'
        && latestModel.hasTraffic === true
        && latestModel.trafficRows.length === 1
        && latestModel.displayTrafficRows.length === 1
        && latestModel.trafficDisplaySummary.status === 'ok'
        && latestModel.hasReview === true
        && latestModel.reviewResult.saved_count === 2
        && latestModel.onlineResult.source === 'latest'
        && emptyLatestModel.metadata.status === 'missing'
        && emptyLatestModel.hasAnySnapshot === false
        && emptyLatestModel.onlineResult === null,
      detail: 'Ctrip latest snapshot sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic builders keep request and display model fields',
      ok: trafficBody.url === 'https://ebooking.ctrip.test/traffic'
        && trafficBody.platform === 'ctrip'
        && trafficBody.date_range === 'custom'
        && trafficBody.start_date === '2026-06-01'
        && trafficBody.end_date === '2026-06-10'
        && trafficBody.cookies === 'sid=traffic'
        && trafficBody.system_hotel_id === '58'
        && trafficBody.extra_params === '{"scope":"self"}'
        && trafficBodyWithoutUrl.url === undefined
        && trafficBodyWithoutUrl.system_hotel_id === null
        && trafficModel.savedCount === 4
        && trafficModel.trafficRows[0].row_id === 'traffic-1'
        && trafficModel.displayTrafficRows[0].compareType === 'self'
        && trafficModel.onlineResult.decoded_data[0].decoded === true
        && trafficModel.onlineResult.raw_response === '{"ok":true}'
        && trafficModel.onlineResult.derived_analysis.conversion === 'stable'
        && trafficFallbackModel.trafficRows[0].decoded === 'fallback'
        && trafficFallbackModel.onlineResult.display_traffic_rows.length === 0,
      detail: 'Ctrip traffic builder sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview builder keeps request fields and method defaults',
      ok: overviewBody.system_hotel_id === '58'
        && overviewBody.hotel_id === 'ctrip-hotel-1'
        && overviewBody.hotel_name === 'Tiancheng Hotel'
        && overviewBody.cookies === 'sid=overview'
        && overviewBody.request_urls === 'https://ebooking.ctrip.test/overview'
        && overviewBody.payload_json === '{"page":1}'
        && overviewBody.spidertoken === 'spider'
        && overviewBody.method === 'POST'
        && overviewBody.data_date === '2026-06-09'
        && flowOverviewBody.cookies === 'sid=flow'
        && flowOverviewBody.request_urls === 'https://ebooking.ctrip.test/flow'
        && flowOverviewBody.payload_json === ''
        && flowOverviewBody.spidertoken === ''
        && flowOverviewBody.method === 'GET'
        && flowOverviewBody.data_date === '2026-06-10',
      detail: 'Ctrip overview request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads builders keep request fields and URL guard',
      ok: defaultCtripAdsEffectReportUrl.includes('queryCampaignReportList')
        && isCtripAdsApiUrl(defaultCtripAdsEffectReportUrl) === true
        && isCtripAdsApiUrl('https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true') === false
        && normalizeCtripAdsApiType('anything') === 'effect_report'
        && adsBody.system_hotel_id === '58'
        && adsBody.hotel_id === 'ctrip-hotel-1'
        && adsBody.hotel_name === 'Tiancheng Hotel'
        && adsBody.url.includes('queryCampaignReportList')
        && adsBody.cookies === 'sid=ads'
        && adsBody.api_type === 'effect_report'
        && adsBody.date_range === 'custom'
        && adsBody.start_date === '2026-06-01'
        && adsBody.end_date === '2026-06-10'
        && adsBody.auto_save === true,
      detail: 'Ctrip ads request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API builder keeps request fields and normalized payload',
      ok: cookieApiBody.system_hotel_id === '58'
        && cookieApiBody.hotel_id === 'ctrip-hotel-1'
        && cookieApiBody.hotel_name === 'Tiancheng Hotel'
        && cookieApiBody.profile_id === 'profile-1'
        && cookieApiBody.data_date === '2026-06-10'
        && cookieApiBody.request_url.includes('queryHomePageRealTimeData')
        && cookieApiBody.method === 'POST'
        && cookieApiBody.payload_json === '{"scope":"core"}'
        && cookieApiBody.endpoints_json === '[{"section":"homepage"}]'
        && cookieApiBody.cookies === 'sid=cookie-api'
        && cookieApiBody.auto_save === true,
      detail: 'Ctrip Cookie API request sample',
    });
  }
  if (typeof buildCtripProfileRecheckInitialState !== 'function'
    || typeof buildCtripProfileRecheckRunContext !== 'function'
    || typeof buildCtripProfileRecheckCaptureRefreshState !== 'function'
    || typeof buildCtripProfileRecheckSuccessResult !== 'function'
    || typeof buildCtripProfileRecheckErrorResult !== 'function'
    || typeof buildCtripProfileRecheckInterruptedState !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports Profile recheck state builders',
      ok: false,
      detail: 'Profile recheck state builders',
    });
  } else {
    const initialState = buildCtripProfileRecheckInitialState({
      canRecapture: true,
      targetCount: 3,
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:00:00',
      sections: ['business_overview'],
    });
    const runContext = buildCtripProfileRecheckRunContext({
      targets: [
        { section: 'business_overview' },
        { section: 'business_overview' },
        { section: 'traffic_report' },
      ],
      estimatedText: '预计 2 分钟',
      startedAt: '2026-06-10 14:01:00',
      selectedCtripHotelId: 'hotel_001',
    });
    const defaultRunContext = buildCtripProfileRecheckRunContext({
      targets: [{ section: '' }],
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:02:00',
    });
    const refreshState = buildCtripProfileRecheckCaptureRefreshState({
      previousState: initialState,
      captureSucceeded: false,
      captureMessage: '',
    });
    const successResult = buildCtripProfileRecheckSuccessResult({
      previousState: refreshState,
      captureSucceeded: false,
      captureSkipped: true,
      result: { refreshed_count: 2, unresolved_count: 1 },
      durationText: '12秒',
      finishedAt: '2026-06-10 14:00:12',
    });
    const errorResult = buildCtripProfileRecheckErrorResult({
      previousState: initialState,
      message: '接口失败',
      durationText: '8秒',
      finishedAt: '2026-06-10 14:00:08',
      prefix: '不符字段重跑失败: ',
    });
    const interruptedState = buildCtripProfileRecheckInterruptedState({
      previousState: initialState,
      finishedAt: '2026-06-10 14:00:20',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep capture and refresh states explicit',
      ok: initialState.stage === 'capture'
        && initialState.target_count === 3
        && initialState.sections.includes('business_overview')
        && runContext.canRecapture === true
        && runContext.targetCount === 3
        && runContext.sections.length === 2
        && runContext.requestOptions.method === 'POST'
        && JSON.parse(runContext.requestOptions.body).sections.join(',') === 'business_overview,traffic_report'
        && runContext.initialState.stage === 'capture'
        && runContext.startMessage.includes('开始重抓 3 个')
        && defaultRunContext.canRecapture === false
        && defaultRunContext.sections[0] === 'default'
        && defaultRunContext.initialState.stage === 'refresh_samples'
        && refreshState.type === 'warning'
        && refreshState.stage === 'refresh_samples'
        && refreshState.message.includes('后端未返回成功状态')
        && successResult.state.stage === 'partial'
        && successResult.toastType === 'warning'
        && successResult.message.includes('仅刷新历史获取值')
        && successResult.message.includes('待补解析 1 个'),
      detail: 'Profile recheck state sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep error and interruption states visible',
      ok: errorResult.state.type === 'error'
        && errorResult.message === '不符字段重跑失败: 接口失败（耗时 8秒）'
        && interruptedState.type === 'warning'
        && interruptedState.stage === 'partial'
        && interruptedState.message.includes('字段列表在执行中被刷新'),
      detail: 'Profile recheck error sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ctrip-static.js',
    label: 'Ctrip static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const getDefaultDataConfigForm = context.window.SUXI_SYSTEM_STATIC?.getDefaultDataConfigForm;
  if (typeof getDefaultDataConfigForm !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports data config default form builder',
      ok: false,
      detail: 'getDefaultDataConfigForm',
    });
  } else {
    const first = getDefaultDataConfigForm();
    const second = getDefaultDataConfigForm();
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form keeps OTA config defaults',
      ok: first.platform === 'Ctrip'
        && first.rank_type === 'P_RZ'
        && Array.isArray(first.rank_types)
        && first.rank_types.includes('P_ZH')
        && first.api_type === 'effect_report'
        && first.reply_type === '2',
      detail: 'getDefaultDataConfigForm sample',
    });
    first.rank_types.push('mutated');
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form returns fresh mutable arrays',
      ok: Array.isArray(second.rank_types) && !second.rank_types.includes('mutated'),
      detail: 'rank_types',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/system-static.js',
    label: 'system static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/notification-static.js'), context, {
    filename: 'public/notification-static.js',
  });
  const buildGlobalNotifications = context.window.SUXI_NOTIFICATION_STATIC?.buildGlobalNotifications;
  if (typeof buildGlobalNotifications !== 'function') {
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification static exports global notification builder',
      ok: false,
      detail: 'buildGlobalNotifications',
    });
  } else {
    const rows = buildGlobalNotifications({
      backendItems: [{ id: 'backend-1', backend_id: 1, source: 'backend', is_read: false }],
      autoFetchRunState: { active: true, message: 'token=abc123 13800138000' },
      autoFetchRunElapsedLabel: '10秒',
      autoFetchStatus: {
        last_run_time: '2026-06-10 10:00:00',
        last_result: { success: true, saved_count: 3 },
      },
      autoFetchRecentRuns: [
        { success: false, run_at: '2026-06-09 08:00:00', data_date: '2026-06-09', message: 'cookie=expired' },
      ],
      dataHealthTodayWorkOrders: [
        { priority: 'high', action_type: 'cookie', key: 'auth', title: '授权过期', detail: 'spidertoken=secret', source_label: '携程', platform_label: 'Ctrip' },
      ],
      readIds: ['auto-fetch-running'],
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps active auto-fetch notification readable',
      ok: rows.some(row => row.id === 'auto-fetch-running' && row.is_read === true && /token=\*\*\*\*/.test(row.detail) && row.detail.includes('138****8000')),
      detail: 'auto-fetch-running',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps data-health action target',
      ok: rows.some(row => row.category === 'cookie_alert' && row.severity === 'error' && row.target_page === 'online-data' && row.target_tab === 'data-health'),
      detail: 'cookie_alert',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder deduplicates rows',
      ok: rows.length === new Set(rows.map(row => row.id)).size,
      detail: 'unique ids',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/notification-static.js',
    label: 'notification static runtime validation',
    ok: false,
    detail: error.message,
  });
}

const failures = checks.filter((check) => !check.ok);
if (failures.length) {
  console.error('E2E contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`E2E contract verification passed (${checks.length} checks).`);
