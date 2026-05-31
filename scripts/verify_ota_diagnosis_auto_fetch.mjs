import { existsSync, readFileSync } from 'node:fs';

const source = readFileSync('public/index.html', 'utf8');
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
      && /executeCtripCommentsAutoFetchTask/.test(controllerSource),
  },
  {
    name: 'auto-fetch skips Meituan comments data by default',
    pass: !/label'\s*=>\s*'meituan-comments'/.test(autoFetchTaskPlanBody)
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
    name: 'manual auto-fetch requests interactive browser capture',
    pass: /interactive_browser:\s*true/.test(triggerBody)
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
    pass: source.includes('fillCtripCookieApiCorePreset')
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
    && source.includes('fillCtripCookieApiCorePreset, runCtripCookieApiCapture'),
  },
  {
    name: 'Ctrip Cookie API can reuse an already logged-in browser Profile',
    pass: controllerSource.includes('createCtripCookieApiCookieFileFromProfile')
      && controllerSource.includes('extract_chromium_cookie_header.php')
      && controllerSource.includes("'cookie_source' => $cookies !== '' ? 'request' : 'browser_profile'")
      && source.includes('没有配置时尝试读取已登录 Profile')
      && source.includes('const cookieApiProfileId = String(')
      && source.includes('activeConfig?.profile_id')
      && source.includes('activeConfig?.browserProfileId')
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
      && source.includes('queryFlowTransforNewV1')
      && source.includes('getTrafficReportV1')
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
    pass: /const meituanConfigMissingFields = \(config\) => \{[\s\S]*Partner ID[\s\S]*POI ID[\s\S]*Cookies/.test(source)
      && /const hasMeituanFetchConfigByHotelId = \(hotelId\) => \{[\s\S]*meituanConfigMissingFields\(config\)\.length === 0/.test(source),
  },
  {
    name: 'Meituan auto-fetch missing fields are visible in page',
    pass: source.includes('美团配置缺失')
      && source.includes('platform.missingText')
      && controllerSource.includes("'missing_fields' => $meituanApiStatus['missing_fields']"),
  },
  {
    name: 'hybrid auto-fetch uses direct API wording and does not auto-start Profile',
    pass: source.includes('接口直连自动')
      && source.includes('默认只使用 Cookie/接口配置')
      && controllerSource.includes('shouldRunProfileBrowserForCost')
      && controllerSource.includes('当前策略未启动 Profile'),
  },
];

const failed = checks.filter(check => !check.pass);
for (const check of checks) {
  console.log(`${check.pass ? 'PASS' : 'FAIL'} ${check.name}`);
}

if (failed.length > 0) {
  process.exit(1);
}
