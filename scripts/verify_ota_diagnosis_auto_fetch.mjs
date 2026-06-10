import { existsSync, readFileSync } from 'node:fs';

const source = readFileSync('public/index.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const controllerSource = readFileSync('app/controller/OnlineData.php', 'utf8');
const routeSource = readFileSync('route/app.php', 'utf8');
const ctripBrowserScriptPath = 'scripts/ctrip_browser_capture.mjs';
const ctripBrowserScript = existsSync(ctripBrowserScriptPath) ? readFileSync(ctripBrowserScriptPath, 'utf8') : '';
const ctripCookieApiScriptPath = 'scripts/ctrip_cookie_api_capture.mjs';
const ctripCookieApiScript = existsSync(ctripCookieApiScriptPath) ? readFileSync(ctripCookieApiScriptPath, 'utf8') : '';
const chromiumCookieExtractorPath = 'scripts/extract_chromium_cookie_header.php';
const chromiumCookieExtractor = existsSync(chromiumCookieExtractorPath) ? readFileSync(chromiumCookieExtractorPath, 'utf8') : '';
const ctripCatalogSource = existsSync('scripts/lib/ctrip_capture_catalog.mjs') ? readFileSync('scripts/lib/ctrip_capture_catalog.mjs', 'utf8') : '';

const functionMatch = source.match(/const generateOtaDiagnosis = async \(\) => \{[\s\S]*?\n            \};/);
const generateBody = functionMatch ? functionMatch[0] : '';
const triggerMatch = source.match(/const triggerAutoFetch = async \(\) => \{[\s\S]*?\n            \};/);
const triggerBody = triggerMatch ? triggerMatch[0] : '';
const runFetchMatch = source.match(/const runOtaDiagnosisHotelFetch = async \([\s\S]*?\n            \};/);
const runFetchBody = runFetchMatch ? runFetchMatch[0] : '';
const autoFetchTaskPlanMatch = controllerSource.match(/private function buildAutoFetchConfigTaskPlan[\s\S]*?\n    private function executeCtripAutoFetch/);
const autoFetchTaskPlanBody = autoFetchTaskPlanMatch ? autoFetchTaskPlanMatch[0] : '';

const checks = [
  {
    name: 'diagnosis auto-fetch helper exists',
    pass: /const runOtaDiagnosisHotelFetch = async \(/.test(source),
  },
  {
    name: 'generate waits for auto-fetch before diagnosis API call',
    pass: generateBody.includes('await runOtaDiagnosisHotelFetch(selectedHotel, form)')
      && generateBody.indexOf('await runOtaDiagnosisHotelFetch(selectedHotel, form)') < generateBody.indexOf("request('/agent/ota-diagnosis'"),
  },
  {
    name: 'auto-fetch includes Ctrip business data',
    pass: source.includes("url: '/online-data/fetch-ctrip'"),
  },
  {
    name: 'auto-fetch includes Ctrip traffic data',
    pass: source.includes("url: '/online-data/ctrip/traffic'"),
  },
  {
    name: 'diagnosis auto-fetch includes Ctrip Cookie API request list',
    pass: runFetchBody.includes("readSavedOtaDataConfig('ctrip-cookie-api')")
      && runFetchBody.includes("label: 'ctrip-cookie-api'")
      && runFetchBody.includes("url: '/online-data/fetch-ctrip-cookie-api'")
      && runFetchBody.includes('request_urls:')
      && runFetchBody.includes('endpoints_json:')
      && runFetchBody.includes('profile_id:')
      && runFetchBody.includes('ctripCookieApiProfileId'),
  },
  {
    name: 'diagnosis auto-fetch can use Ctrip core preset from Cookie or saved Profile',
    pass: runFetchBody.includes('useCtripCorePresetForDiagnosis')
      && runFetchBody.includes('getCtripCookieApiCorePresetJson()')
      && runFetchBody.includes('/online-data/ctrip-profile-status')
      && runFetchBody.includes('ctripCookieApiConfig.ota_hotel_id')
      && runFetchBody.includes('ctripConfig?.ota_hotel_id')
      && runFetchBody.includes("request_source: hasCtripCookieApiRequests ? 'saved_config' : `core_preset:${ctripCorePresetReason || 'unknown'}`"),
  },
  {
    name: 'Ctrip Cookie API accepts pasted Cookie header formats',
    pass: controllerSource.includes('readCtripCookieHeaderFromRequest')
      && controllerSource.includes('normalizeCtripCookieHeaderText')
      && controllerSource.includes('cleanCtripCookieHeaderCandidate')
      && runFetchBody.includes("readHeaderValue(ctripCookieApiConfig.headers_json, 'cookie')")
      && source.includes('可粘贴 Cookie、Cookie: ... 或完整 Request Headers'),
  },
  {
    name: 'diagnosis auto-fetch can reuse hotel-scoped generic Cookie',
    pass: source.includes('readSavedGenericCookieForDiagnosis')
      && source.includes('/online-data/cookies-list?hotel_id=')
      && source.includes('loadCookieDetail(ctripLike)')
      && runFetchBody.includes('genericCtripCookie')
      && runFetchBody.includes('diagnosisCtripCookie')
      && runFetchBody.includes("ctripCorePresetReason = genericCtripCookie ? 'generic_cookie' : 'cookie'")
      && runFetchBody.includes('cookies: diagnosisCtripCookie'),
  },
  {
    name: 'Ctrip Cookie API exposes not-ready diagnosis state',
    pass: controllerSource.includes('buildCtripCookieApiReadiness')
      && controllerSource.includes("'status' => 'not_ready'")
      && controllerSource.includes("'next_action' => $nextAction")
      && source.includes('ctripBrowserCaptureResult.warning')
      && source.includes('ctripBrowserCaptureResult.is_ready === false')
      && source.includes('携程 Cookie API 未达到诊断就绪'),
  },
  {
    name: 'auto-fetch includes Meituan ranking data',
    pass: source.includes("url: '/online-data/fetch-meituan'"),
  },
  {
    name: 'auto-fetch includes Meituan traffic data',
    pass: source.includes("url: '/online-data/fetch-meituan-traffic'"),
  },
  {
    name: 'auto-fetch skips Ctrip comments data by default',
    pass: !/label'\s*=>\s*'ctrip-comments'/.test(autoFetchTaskPlanBody)
      && !/private function executeCtripCommentsAutoFetchTask/.test(controllerSource)
      && controllerSource.includes("'ctrip:comments',")
      && controllerSource.includes('Comment/review data collection is disabled by policy.'),
  },
  {
    name: 'auto-fetch skips Meituan comments data by default',
    pass: !/label'\s*=>\s*'meituan-comments'/.test(autoFetchTaskPlanBody)
      && !/private function executeMeituanCommentsAutoFetchTask/.test(controllerSource)
      && controllerSource.includes("'meituan:comments' =>")
      && !runFetchBody.includes("url: '/online-data/fetch-meituan-comments'"),
  },
  {
    name: 'auto-fetch writes fetched rows to selected system hotel',
    pass: /system_hotel_id:\s*systemHotelId/.test(source) && /auto_save:\s*true/.test(source),
  },
  {
    name: 'manual auto-fetch stays in app while backend browser capture runs',
    pass: triggerBody.includes("request('/online-data/auto-fetch'")
      && triggerBody.includes('await request')
      && !triggerBody.includes('window.location.assign')
      && !triggerBody.includes('window.open(')
      && !triggerBody.includes('keepalive'),
  },
  {
    name: 'manual auto-fetch passes browser headless preference',
    pass: triggerBody.includes('browserHeadless')
      && /interactive_browser:\s*!browserHeadless/.test(triggerBody)
      && /browser_headless:\s*browserHeadless/.test(triggerBody)
      && /interactive_browser/.test(controllerSource)
      && /--headless=false/.test(controllerSource),
  },
  {
    name: 'Ctrip auto-fetch uses full browser capture script',
    pass: controllerSource.includes('ctrip_browser_capture.mjs')
      && controllerSource.includes('browser_profile')
      && !/executeCtripBrowserProfileAutoFetch[\s\S]*ctrip_comment_browser_capture\.mjs[\s\S]*private function executeMeituanAutoFetch/.test(controllerSource),
  },
  {
    name: 'Ctrip browser Profile login opens China eBooking login entry',
    pass: ctripBrowserScript.includes("const CTRIP_LOGIN_URL = 'https://ebooking.ctrip.com/login/index'")
      && ctripBrowserScript.includes('page.goto(ctripLoginEntryUrl()')
      && controllerSource.includes("'entry_url' => 'https://ebooking.ctrip.com/login/index'")
      && source.includes("const defaultCtripLoginUrl = 'https://ebooking.ctrip.com/login/index'")
      && source.includes('openTargetSite(defaultCtripLoginUrl)'),
  },
  {
    name: 'Ctrip browser capture supports catalog presets and diagnosis summary',
    pass: ctripCatalogSource.includes('businessreport/outline')
      && ctripCatalogSource.includes('businessreport/flowdata')
      && ctripCatalogSource.includes('sales_report')
      && ctripCatalogSource.includes('room_type')
      && ctripCatalogSource.includes('competitor_overview')
      && ctripBrowserScript.includes("args.sections || args.captureSections || args.only || 'default'")
      && ctripBrowserScript.includes('requestedSections.includes(section)')
      && ctripBrowserScript.includes('target.business.push')
      && ctripBrowserScript.includes('target.traffic.push')
      && !ctripBrowserScript.includes("args.sections || 'business,traffic,reviews'")
      && controllerSource.includes("'core'")
      && controllerSource.includes('buildCtripCaptureDiagnosisSummary')
      && source.includes('diagnosis_summary?.groups'),
  },
  {
    name: 'Ctrip manual browser capture can inject configured Cookie',
    pass: controllerSource.includes("trim((string)($requestData['cookies'] ?? $requestData['cookie'] ?? ''))")
      && controllerSource.includes("'ctrip',")
      && controllerSource.includes("$args[] = '--cookies-file=' . $cookieFile")
      && controllerSource.includes('$this->removeAutoFetchCookieFile($cookieFile)')
      && source.includes("cookies: activeConfig?.cookies || activeConfig?.cookie || ''"),
  },
  {
    name: 'Ctrip diagnosis snapshot is available in app without rerunning browser',
    pass: routeSource.includes("Route::get('/ctrip-diagnosis-snapshot', 'OnlineData/ctripDiagnosisSnapshot')")
      && controllerSource.includes('public function ctripDiagnosisSnapshot')
      && controllerSource.includes('buildLatestCtripDiagnosisSnapshot')
      && controllerSource.includes('aggregateCtripDiagnosisSnapshot')
      && source.includes("request(`/online-data/ctrip-diagnosis-snapshot")
      && source.includes('loadCtripDiagnosisSnapshot')
      && source.includes('读取诊断快照'),
  },
  {
    name: 'Ctrip overview top-level UI is hidden while backend fetch remains available',
    pass: source.indexOf("onlineDataTab = 'ctrip-ads'") > -1
      && source.indexOf('switchToDownloadCenter') > source.indexOf("onlineDataTab = 'ctrip-ads'")
      && !source.includes("onlineDataTab = 'ctrip-overview'; loadCtripConfigList()")
      && !source.includes("{ label: '携程概况', page: 'ctrip-ebooking', tab: 'ctrip-overview'")
      && source.includes("request('/online-data/fetch-ctrip-overview'"),
  },
  {
    name: 'Ctrip Cookie API capture can fetch catalog endpoints without Chromium',
    pass: routeSource.includes("Route::post('/fetch-ctrip-cookie-api', 'OnlineData/fetchCtripCookieApiData')")
      && controllerSource.includes('public function fetchCtripCookieApiData')
      && controllerSource.includes('ctrip_cookie_api_capture.mjs')
      && controllerSource.includes('prepareCtripCookieApiCaptureFiles')
      && controllerSource.includes('$this->removeAutoFetchCookieFile($cookieFile)')
      && controllerSource.includes('@unlink($prepared[\'input_path\'])')
      && ctripCookieApiScript.includes('runCtripCookieApiCapture')
      && ctripCookieApiScript.includes('findCtripEndpointByUrl')
      && ctripCookieApiScript.includes('extractCtripCatalogFacts')
      && ctripCookieApiScript.includes('buildCtripStandardRowsFromFacts')
      && ctripCookieApiScript.includes("source: 'ctrip_cookie_api'")
      && ctripCookieApiScript.includes('redactHeaders')
      && source.includes('ctripCookieApiForm')
      && source.includes('ctripCookieApiRunning')
      && source.includes('runCtripCookieApiCapture')
      && source.includes('endpoints_json: endpointsJson'),
  },
  {
    name: 'Ctrip Cookie API exposes a core diagnosis endpoint preset',
    pass: source.includes('getCtripCookieApiCorePresetEndpoints')
      && source.includes('getCtripCookieApiCorePresetJson')
      && source.includes('fillCtripCookieApiCorePreset')
      && source.includes('填入核心诊断接口')
      && source.includes('queryHotCalendarInfo')
      && source.includes('queryHomePageRealTimeData')
      && source.includes('queryCampaignSummaryReport')
      && source.includes('getHotelPsiV2')
      && source.includes('getBbkComprehensiveTable')
      && source.includes('dataCenterBusinessReportDetail')
      && source.includes('queryScanFlowDetailsV2')
    && source.includes('market_calendar')
    && source.includes('homepage')
    && source.includes('traffic_report')
    && source.includes('ads_pyramid')
    && source.includes('quality_psi')
    && source.includes('biztravel_bpi')
    && source.includes('biztravel_business_report')
    && source.includes('biztravel_competitor')
    && source.includes('user_profile')
    && source.includes('im_board')
    && source.includes('competitor_overview')
    && source.includes('loss_analysis')
    && source.includes('competitor_rank')
    && source.includes('queryUserSex')
    && source.includes('getImIndex')
    && source.includes('getManagementData')
    && source.includes('getTripartiteOrderLoss')
    && source.includes('getCompetingRank')
    && source.includes('fillCtripCookieApiCorePreset')
    && source.includes('runCtripCookieApiCapture'),
  },
  {
    name: 'Ctrip Cookie API config can be saved and tested from data config modal',
    pass: source.includes("openDataConfigModal('ctrip-cookie-api')")
      && source.includes("'ctrip-cookie-api': {")
      && source.includes("currentDataConfigType === 'ctrip-cookie-api'")
      && source.includes("case 'ctrip-cookie-api':")
      && source.includes("apiUrl = '/online-data/fetch-ctrip-cookie-api'")
      && source.includes('request_urls')
      && source.includes('endpoints_json')
      && source.includes('profile_id')
      && source.includes('fillDataConfigCtripCookieApiCorePreset')
      && source.includes('dataConfigForm.value.endpoints_json = ctripCookieApiForm.value.endpointsJson'),
  },
  {
    name: 'Ctrip Cookie API can reuse an already logged-in browser Profile',
    pass: controllerSource.includes('createCtripCookieApiCookieFileFromProfile')
      && controllerSource.includes('extract_chromium_cookie_header.php')
      && controllerSource.includes("'cookie_source' => $cookies !== '' ? 'request' : 'browser_profile'")
      && source.includes('读取已登录 Profile')
      && source.includes('const resolveCtripCookieApiProfileId = (systemHotelId = \'\', activeConfig = null) => String(')
      && source.includes('/online-data/ctrip-profile-status')
      && source.includes('checkCtripProfileStatus')
      && source.includes('activeConfig?.profile_id')
      && source.includes('activeConfig?.browserProfileId')
      && source.includes('activeConfig?.ota_hotel_id')
      && source.includes('activeConfig?.nodeId')
      && source.includes('profile_id: cookieApiProfileId')
      && !source.includes("showToast('请填写 Cookie，或先保存当前酒店的携程配置'")
      && chromiumCookieExtractor.includes('decrypt_chrome_master_key')
      && chromiumCookieExtractor.includes('ProtectedData')
      && chromiumCookieExtractor.includes('pdo_sqlite')
      && chromiumCookieExtractor.includes('openssl_decrypt')
      && chromiumCookieExtractor.includes('cookie_count')
      && !chromiumCookieExtractor.includes('echo implode'),
  },
  {
    name: 'Ctrip overview manual fetch uses cookies and API URLs without browser capture',
    pass: controllerSource.includes('fetchCtripOverviewData')
      && controllerSource.includes('sendCtripOverviewRequest')
      && controllerSource.includes('queryFlowTransforNewV1')
      && controllerSource.includes('getCompeteHotelReportV1')
      && controllerSource.includes('getLastWeekReportV1')
      && source.includes('ctripOverviewForm.requestUrls')
      && source.includes('ctripOverviewForm.cookies')
      && (source + ctripStaticSource).includes('queryFlowTransforNewV1')
      && (source + ctripStaticSource).includes('getTrafficReportV1')
      && !source.includes("request('/online-data/capture-ctrip-overview-browser'")
      && !controllerSource.includes('captureCtripOverviewBrowserData'),
  },
  {
    name: 'manual auto-fetch shows persistent in-panel progress and result',
    pass: /const autoFetchRunState = ref\(\{/.test(source)
      && /autoFetchRunState\.value = \{[\s\S]*active:\s*true/.test(triggerBody)
      && source.includes("autoFetchRunState.active ? '正在执行平台抓取' : '本次抓取已返回'"),
  },
  {
    name: 'Meituan auto-fetch config requires Partner ID, POI ID and Cookies',
    pass: (/const meituanConfigMissingFields = \(config\) => \{[\s\S]*Partner ID[\s\S]*POI ID[\s\S]*Cookies/.test(source)
      || /const meituanConfigMissingFields = \(config\) => \{[\s\S]*平台接口标识[\s\S]*平台门店标识[\s\S]*平台授权/.test(source))
      && /const hasMeituanFetchConfigByHotelId = \(hotelId\) => \{[\s\S]*meituanConfigMissingFields\(config\)\.length === 0/.test(source),
  },
  {
    name: 'Meituan auto-fetch missing fields are visible in page',
    pass: source.includes('美团配置缺失')
      && source.includes('platform.missingText')
      && controllerSource.includes("'missing_fields' => $meituanApiStatus['missing_fields']"),
  },
  {
    name: 'auto-fetch sends dedicated Ctrip browser Profile mode and preserves Meituan mode',
    pass: source.includes('buildAutoFetchModePayload')
      && source.includes('ctrip_auto_fetch_mode')
      && source.includes('meituan_auto_fetch_mode')
      && source.includes('profile_browser')
      && controllerSource.includes('platformAutoFetchModeOptionsFromRequest')
      && controllerSource.includes('ctrip_auto_fetch_mode')
      && controllerSource.includes('meituan_auto_fetch_mode')
      && controllerSource.includes('profile_browser'),
  },
  {
    name: 'auto-fetch exposes realtime interval schedule and browser headless settings',
    pass: source.includes('autoFetchScheduleMinute')
      && source.includes('autoFetchRealtimeIntervalHours')
      && source.includes('autoFetchBrowserHeadless')
      && source.includes('buildAutoFetchSchedulePayload')
      && controllerSource.includes('schedule_minute')
      && controllerSource.includes('realtime_schedule_interval_hours')
      && controllerSource.includes('normalizeAutoFetchScheduleIntervalHours')
      && controllerSource.includes('browser_headless')
      && controllerSource.includes('normalizeAutoFetchScheduleMinute'),
  },
];

const failed = checks.filter(check => !check.pass);
for (const check of checks) {
  console.log(`${check.pass ? 'PASS' : 'FAIL'} ${check.name}`);
}

if (failed.length > 0) {
  process.exit(1);
}
