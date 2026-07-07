import { existsSync, readFileSync, readdirSync } from 'node:fs';

const readBackendSource = () => {
  const paths = ['app/controller/OnlineData.php'];
  const concernDir = 'app/controller/concern';
  if (existsSync(concernDir)) {
    for (const name of readdirSync(concernDir)) {
      if (name.endsWith('.php')) paths.push(`${concernDir}/${name}`);
    }
  }
  return paths.map(path => readFileSync(path, 'utf8')).join('\n');
};

const indexSource = readFileSync('public/index.html', 'utf8');
const otaDiagnosisStaticSource = existsSync('public/ota-diagnosis-static.js') ? readFileSync('public/ota-diagnosis-static.js', 'utf8') : '';
const autoFetchStaticSource = existsSync('public/auto-fetch-static.js') ? readFileSync('public/auto-fetch-static.js', 'utf8') : '';
const platformAutoSettingsSource = existsSync('public/components/online-data/platform-auto-settings-panels.js') ? readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8') : '';
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const systemStaticSource = readFileSync('public/system-static.js', 'utf8');
const meituanStaticSource = existsSync('public/meituan-static.js') ? readFileSync('public/meituan-static.js', 'utf8') : '';
const source = [indexSource, otaDiagnosisStaticSource, autoFetchStaticSource, platformAutoSettingsSource, ctripStaticSource, systemStaticSource, meituanStaticSource].join('\n');
const controllerSource = readBackendSource();
const routeSource = readFileSync('route/app.php', 'utf8');
const ctripBrowserScriptPath = 'scripts/ctrip_browser_capture.mjs';
const ctripBrowserScript = existsSync(ctripBrowserScriptPath) ? readFileSync(ctripBrowserScriptPath, 'utf8') : '';
const ctripCookieApiScriptPath = 'scripts/ctrip_cookie_api_capture.mjs';
const ctripCookieApiScript = existsSync(ctripCookieApiScriptPath) ? readFileSync(ctripCookieApiScriptPath, 'utf8') : '';
const chromiumCookieExtractorPath = 'scripts/extract_chromium_cookie_header.php';
const chromiumCookieExtractor = existsSync(chromiumCookieExtractorPath) ? readFileSync(chromiumCookieExtractorPath, 'utf8') : '';
const ctripCatalogSource = existsSync('scripts/lib/ctrip_capture_catalog.mjs') ? readFileSync('scripts/lib/ctrip_capture_catalog.mjs', 'utf8') : '';

const sliceBetween = (text, startText, endText) => {
  const start = text.indexOf(startText);
  if (start < 0) return '';
  const end = text.indexOf(endText, start + startText.length);
  return end > start ? text.slice(start, end) : text.slice(start);
};

const functionMatch = otaDiagnosisStaticSource.match(/const runOtaDiagnosisGenerateFlow = async \(\{[\s\S]*?\n    \};\n\n    return \{/);
const generateBody = functionMatch ? functionMatch[0] : '';
const triggerMatch = autoFetchStaticSource.match(/const runAutoFetchTriggerFlow = async \(\{[\s\S]*?\n    \};\n\n    return \{/);
const triggerBody = triggerMatch ? triggerMatch[0] : '';
const runFetchBody = [
  sliceBetween(otaDiagnosisStaticSource, 'const buildOtaDiagnosisFetchContext = ({', 'const pushOtaDiagnosisFetchTask'),
  sliceBetween(otaDiagnosisStaticSource, 'const buildOtaDiagnosisFetchTasks = ({', 'const buildEmptyOtaDiagnosisFetchSummary'),
  sliceBetween(otaDiagnosisStaticSource, 'const runOtaDiagnosisHotelFetchFlow = async ({', 'const buildOtaDiagnosisGenerateRequestBody'),
].join('\n');
const autoFetchTaskPlanMatch = controllerSource.match(/private function buildAutoFetchConfigTaskPlan[\s\S]*?\n    private function executeCtripAutoFetch/);
const autoFetchTaskPlanBody = autoFetchTaskPlanMatch ? autoFetchTaskPlanMatch[0] : '';

const checks = [
  {
    name: 'diagnosis fallback fetch helper exists',
    pass: /const runOtaDiagnosisHotelFetch = async \(/.test(source),
  },
  {
    name: 'generate waits for auto-fetch before diagnosis API call',
    pass: generateBody.includes('fetchSummary = await runHotelFetch(selectedHotel, currentForm)')
      && generateBody.includes('const res = await requestDiagnosis(requestBody)')
      && generateBody.indexOf('fetchSummary = await runHotelFetch(selectedHotel, currentForm)') < generateBody.indexOf('const res = await requestDiagnosis(requestBody)'),
  },
  {
    name: 'diagnosis fallback fetch includes Ctrip business data',
    pass: source.includes("url: '/online-data/fetch-ctrip'"),
  },
  {
    name: 'diagnosis fallback fetch includes Ctrip traffic data',
    pass: source.includes("url: '/online-data/ctrip/traffic'"),
  },
  {
    name: 'diagnosis fallback fetch includes Ctrip Cookie API request list',
    pass: runFetchBody.includes("readSavedOtaDataConfig('ctrip-cookie-api')")
      && runFetchBody.includes("label: 'ctrip-cookie-api'")
      && runFetchBody.includes("url: '/online-data/fetch-ctrip-cookie-api'")
      && runFetchBody.includes('request_urls:')
      && runFetchBody.includes('endpoints_json:')
      && runFetchBody.includes('profile_id:')
      && runFetchBody.includes('ctripCookieApiProfileId'),
  },
  {
    name: 'diagnosis fallback fetch can use Ctrip core preset from Cookie or saved Profile',
    pass: runFetchBody.includes('useCtripCorePresetForDiagnosis')
      && runFetchBody.includes('getCtripCookieApiCorePresetJson()')
      && source.includes('/online-data/ctrip-profile-status')
      && runFetchBody.includes('ctripCookieApiConfig.ota_hotel_id')
      && runFetchBody.includes('ctripConfig?.ota_hotel_id')
      && runFetchBody.includes("request_source: context.hasCtripCookieApiRequests ? 'saved_config' : `core_preset:${ctripCorePresetReason || 'unknown'}`"),
  },
  {
    name: 'Ctrip Cookie API accepts pasted Cookie header formats',
    pass: controllerSource.includes('readCtripCookieHeaderFromRequest')
      && controllerSource.includes('normalizeCtripCookieHeaderText')
      && controllerSource.includes('cleanCtripCookieHeaderCandidate')
      && runFetchBody.includes("readOtaDiagnosisHeaderValue(ctripCookieApiConfig.headers_json, 'cookie')")
      && source.includes('Cookie: ...')
      && source.includes('Request Headers'),
  },
  {
    name: 'diagnosis fallback fetch can reuse hotel-scoped generic Cookie',
    pass: source.includes('readSavedGenericCookieForDiagnosis')
      && source.includes('/online-data/cookies-list?hotel_id=')
      && source.includes('loadCookieDetail(ctripLike)')
      && runFetchBody.includes('genericCtripCookie')
      && runFetchBody.includes("ctripCorePresetReason = genericCtripCookie ? 'generic_cookie' : 'cookie'")
      && runFetchBody.includes('cookies: firstOtaDiagnosisValue(context.ctripCookieApiCookies, genericCtripCookie?.cookies)'),
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
    name: 'diagnosis fallback fetch includes Meituan ranking data',
    pass: source.includes("url: '/online-data/fetch-meituan'"),
  },
  {
    name: 'diagnosis fallback fetch includes Meituan traffic data',
    pass: source.includes("url: '/online-data/fetch-meituan-traffic'"),
  },
  {
    name: 'auto-fetch can queue Ctrip aggregate comments through browser Profile',
    pass: /label'\s*=>\s*'ctrip-comments'/.test(autoFetchTaskPlanBody)
      && /module'\s*=>\s*'comments'/.test(autoFetchTaskPlanBody)
      && autoFetchTaskPlanBody.includes("'capture_sections' => 'comment_review'")
      && controllerSource.includes("'ctrip:comments' => $this->executeCtripBrowserProfileAutoFetch("),
  },
  {
    name: 'auto-fetch can queue Meituan aggregate comments through browser Profile',
    pass: /label'\s*=>\s*'meituan-comments'/.test(autoFetchTaskPlanBody)
      && /module'\s*=>\s*'comments'/.test(autoFetchTaskPlanBody)
      && autoFetchTaskPlanBody.includes("'capture_sections' => 'reviews'")
      && controllerSource.includes("'meituan:comments' => $this->executeMeituanBrowserProfileAutoFetch("),
  },
  {
    name: 'auto-fetch writes fetched rows to selected system hotel',
    pass: /system_hotel_id:\s*systemHotelId/.test(source) && /auto_save:\s*true/.test(source),
  },
  {
    name: 'manual auto-fetch stays in app while backend browser capture runs',
    pass: source.includes("request('/online-data/auto-fetch'")
      && triggerBody.includes('await requestAutoFetch(requestBody)')
      && !triggerBody.includes('window.location.assign')
      && !triggerBody.includes('window.open(')
      && !triggerBody.includes('keepalive'),
  },
  {
    name: 'manual auto-fetch passes browser headless preference',
    pass: triggerBody.includes('browserHeadless')
      && /interactive_browser:\s*!browserHeadless/.test(autoFetchStaticSource)
      && /browser_headless:\s*browserHeadless/.test(autoFetchStaticSource)
      && /interactive_browser/.test(controllerSource)
      && /browser_headless/.test(controllerSource),
  },
  {
    name: 'Ctrip auto-fetch uses full browser capture script',
    pass: controllerSource.includes('ctrip_browser_capture.mjs')
      && controllerSource.includes('browser_profile')
      && !/executeCtripBrowserProfileAutoFetch[\s\S]*ctrip_comment_browser_capture\.mjs[\s\S]*private function executeMeituanAutoFetch/.test(controllerSource),
  },
  {
    name: 'Ctrip browser Profile login opens China eBooking login entry',
    pass: ctripBrowserScript.includes("const CTRIP_LOGIN_URL = 'https://ebooking.ctrip.com/home/mainland'")
      && ctripBrowserScript.includes('page.goto(ctripLoginEntryUrl()')
      && controllerSource.includes("'entry_url' => 'https://ebooking.ctrip.com/home/mainland'")
      && source.includes("const defaultCtripLoginUrl = 'https://ebooking.ctrip.com/home/mainland'")
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
    pass: source.indexOf("onlineDataTab === 'ctrip-ads'") > -1
      && source.indexOf('switchToDownloadCenter') > source.indexOf("onlineDataTab === 'ctrip-ads'")
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
      && source.includes("requireCtripStatic('getCtripCookieApiCorePresetEndpoints')")
      && source.includes('getCtripCookieApiCorePresetJson')
      && source.includes('fillCtripCookieApiCorePreset')
      && source.includes('填入核心诊断接口')
      && ctripStaticSource.includes('getCtripCookieApiCorePresetEndpoints')
      && ctripStaticSource.includes('queryHotCalendarInfo')
      && ctripStaticSource.includes('queryHomePageRealTimeData')
      && ctripStaticSource.includes('queryCampaignSummaryReport')
      && ctripStaticSource.includes('getHotelPsiV2')
      && ctripStaticSource.includes('getBbkComprehensiveTable')
      && ctripStaticSource.includes('dataCenterBusinessReportDetail')
      && ctripStaticSource.includes('queryScanFlowDetailsV2')
      && ctripStaticSource.includes('market_calendar')
      && ctripStaticSource.includes('homepage')
      && ctripStaticSource.includes('traffic_report')
      && ctripStaticSource.includes('ads_pyramid')
      && ctripStaticSource.includes('quality_psi')
      && ctripStaticSource.includes('biztravel_bpi')
      && ctripStaticSource.includes('biztravel_business_report')
      && ctripStaticSource.includes('biztravel_competitor')
      && ctripStaticSource.includes('user_profile')
      && ctripStaticSource.includes('im_board')
      && ctripStaticSource.includes('competitor_overview')
      && ctripStaticSource.includes('loss_analysis')
      && ctripStaticSource.includes('competitor_rank')
      && ctripStaticSource.includes('queryUserSex')
      && ctripStaticSource.includes('getImIndex')
      && ctripStaticSource.includes('getManagementData')
      && ctripStaticSource.includes('getTripartiteOrderLoss')
      && ctripStaticSource.includes('getCompetingRank')
      && !indexSource.includes("request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo'")
      && source.includes('fillCtripCookieApiCorePreset')
      && source.includes('填入核心诊断接口'),
  },
  {
    name: 'Ctrip Cookie API config can be saved and tested from data config modal',
    pass: source.includes("openDataConfigModal('ctrip-cookie-api')")
      && source.includes("case 'ctrip-cookie-api':")
      && source.includes("currentDataConfigType === 'ctrip-cookie-api'")
      && source.includes('dataConfigTestEndpointMap')
      && source.includes("'ctrip-cookie-api': '/online-data/fetch-ctrip-cookie-api'")
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
      && source.includes('probe_cookie')
      && source.includes('cookie_extractable')
      && source.includes('const resolveCtripCookieApiProfileId = (systemHotelId = \'\', activeConfig = null) => String(')
      && source.includes('/online-data/ctrip-profile-status')
      && source.includes('checkCtripProfileStatus')
      && source.includes('activeConfig?.profile_id')
      && source.includes('activeConfig?.browserProfileId')
      && source.includes('activeConfig?.ota_hotel_id')
      && (source.includes('activeConfig?.nodeId') || source.includes('resolveCtripBrowserProfileId({'))
      && (source.includes('profile_id: profileId') || source.includes('profile_id: context.ctripCookieApiProfileId'))
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
      && triggerBody.includes('setRunState(buildAutoFetchRunStartState')
      && /setRunState\(\{[\s\S]*active:\s*true/.test(triggerBody)
      && source.includes('autoFetchRunState.active'),
  },
  {
    name: 'Meituan auto-fetch config accepts manual Cookie or browser Profile derived Cookie source',
    legacyPass: (/const meituanConfigMissingFields = \(config\) => \{[\s\S]*Partner ID[\s\S]*POI ID[\s\S]*Cookies/.test(source)
      || /const meituanConfigMissingFields = \(config\) => \{[\s\S]*平台接口标识[\s\S]*平台门店标识[\s\S]*Cookie\/API 辅助/.test(source))
      && /const hasMeituanFetchConfigByHotelId = \(hotelId\) => \{[\s\S]*meituanConfigMissingFields\(config\)\.length === 0/.test(source),
    pass: source.includes('const meituanConfigHasProfileCookieSource = (config) => (')
      && source.includes("String(config?.cookie_source || '').trim() === 'browser_profile'")
      && source.includes('config?.has_profile_cookie_source || config?.profile_cookie_source')
      && /const meituanConfigMissingFields = \(config\) => \{[\s\S]*meituanConfigHasCookies\(config\)/.test(source)
      && /const hasMeituanFetchConfigByHotelId = \(hotelId\) => \{[\s\S]*meituanConfigMissingFields\(config\)\.length === 0/.test(source),
  },
  {
    name: 'Meituan auto-fetch missing fields are visible in page',
    pass: source.includes('美团配置缺失')
      && source.includes('meituanConfigMissingTextByHotelId')
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
