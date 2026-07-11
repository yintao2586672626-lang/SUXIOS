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
const requestConcernSource = readFileSync('app/controller/concern/OnlineDataRequestConcern.php', 'utf8');
const cookieEndpointSource = readFileSync('app/controller/concern/CookieEndpointConcern.php', 'utf8');
const ctripCommentsSource = readFileSync('app/controller/concern/CtripCommentsConcern.php', 'utf8');
const meituanConfigSource = readFileSync('app/controller/concern/MeituanConfigConcern.php', 'utf8');
const routeSource = readFileSync('route/app.php', 'utf8');
const ctripBrowserScriptPath = 'scripts/ctrip_browser_capture.mjs';
const ctripBrowserScript = existsSync(ctripBrowserScriptPath) ? readFileSync(ctripBrowserScriptPath, 'utf8') : '';
const ctripCookieApiScriptPath = 'scripts/ctrip_cookie_api_capture.mjs';
const ctripCookieApiScript = existsSync(ctripCookieApiScriptPath) ? readFileSync(ctripCookieApiScriptPath, 'utf8') : '';
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
const cookieApiSanitizerBody = sliceBetween(
  requestConcernSource,
  'private function sanitizeCtripCookieApiExecutionRequestData',
  'private function sanitizeCtripOverviewExecutionRequestData'
);
const cookieApiEndpointBody = sliceBetween(
  requestConcernSource,
  'public function fetchCtripCookieApiData',
  'private function executeCtripCookieApiDataFetch'
);
const ctripBrowserCaptureBody = sliceBetween(
  requestConcernSource,
  'public function captureCtripBrowserData',
  'public function validateCtripEndpointEvidence'
);
const ctripBrowserCapturePayloadBody = sliceBetween(
  ctripStaticSource,
  'const buildCtripBrowserCapturePayload = ({',
  'const buildCtripBrowserCaptureRequestContext = ({'
);
const ctripOverviewRequestBody = sliceBetween(
  ctripStaticSource,
  'const buildCtripOverviewFetchRequestBody = ({',
  'const runCtripOverviewFetchFlow = async ({'
);
const reusableOtaSecretTaskField = /['"](?:cookies?|auth_data|authorization|token|access_token|refresh_token|spidertoken|spider_token|spiderkey|spider_key|mtgsig|headers|headers_json|password|api_key)['"]\s*:/i;

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
    pass: runFetchBody.includes("label: 'ctrip-business'")
      && runFetchBody.includes("url: '/online-data/fetch-ctrip'")
      && runFetchBody.includes("required: ['config_id', 'node_id']")
      && runFetchBody.includes('config_id: ctripConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId'),
  },
  {
    name: 'diagnosis fallback fetch includes Ctrip traffic data',
    pass: runFetchBody.includes("label: 'ctrip-traffic'")
      && runFetchBody.includes("url: '/online-data/ctrip/traffic'")
      && runFetchBody.includes("required: ['config_id']")
      && runFetchBody.includes('config_id: ctripConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId'),
  },
  {
    name: 'diagnosis Ctrip Cookie API task uses saved request metadata and the vault locator only',
    pass: runFetchBody.includes('context.hasCtripCookieApiRequests && isOtaDiagnosisCredentialReady(ctripConfig)')
      && runFetchBody.includes("label: 'ctrip-cookie-api'")
      && runFetchBody.includes("url: '/online-data/fetch-ctrip-cookie-api'")
      && runFetchBody.includes("required: ['config_id']")
      && runFetchBody.includes('config_id: ctripConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId')
      && runFetchBody.includes('request_urls:')
      && runFetchBody.includes("request_source: 'saved_metadata'")
      && !reusableOtaSecretTaskField.test(runFetchBody),
  },
  {
    name: 'diagnosis fetch resolves hotel-scoped platform metadata without generic Cookie fallback',
    pass: runFetchBody.includes('findCtripConfigByHotelId(initialSystemHotelId)')
      && runFetchBody.includes('findMeituanConfigByHotelId(initialSystemHotelId)')
      && runFetchBody.includes('isOtaDiagnosisCredentialReady')
      && !runFetchBody.includes('readSavedOtaDataConfig')
      && !runFetchBody.includes('readSavedGenericCookieForDiagnosis')
      && !runFetchBody.includes('loadCookieDetail'),
  },
  {
    name: 'Ctrip Cookie API execution rejects inline Cookie and header fields before vault access',
    pass: cookieApiSanitizerBody.includes("'config_id'")
      && cookieApiSanitizerBody.includes("'system_hotel_id'")
      && !cookieApiSanitizerBody.includes("'cookies'")
      && !cookieApiSanitizerBody.includes("'cookie'")
      && !cookieApiSanitizerBody.includes("'headers'")
      && cookieApiEndpointBody.includes('sanitizeCtripCookieApiExecutionRequestData($rawRequestData)')
      && cookieApiEndpointBody.includes('$this->withOtaCredentialForExecution('),
  },
  {
    name: 'legacy generic Cookie storage fails closed and frontend never calls it',
    pass: cookieEndpointSource.includes('public function saveCookies(): Response')
      && cookieEndpointSource.includes('Legacy Cookie storage is disabled.')
      && cookieEndpointSource.includes('public function getCookiesDetail(): Response')
      && cookieEndpointSource.includes('Legacy Cookie detail access is disabled.')
      && cookieEndpointSource.includes('410')
      && !source.includes('/online-data/save-cookies')
      && !source.includes('/online-data/cookies-list')
      && !source.includes('/online-data/cookies-detail'),
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
    pass: runFetchBody.includes("url: '/online-data/fetch-meituan'")
      && runFetchBody.includes("required: ['config_id', 'partner_id', 'poi_id']")
      && runFetchBody.includes('config_id: meituanConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId'),
  },
  {
    name: 'diagnosis fallback fetch includes Meituan traffic data',
    pass: runFetchBody.includes("label: 'meituan-traffic'")
      && runFetchBody.includes("url: '/online-data/fetch-meituan-traffic'")
      && runFetchBody.includes("required: ['config_id', 'url', 'partner_id', 'poi_id']")
      && runFetchBody.includes('config_id: meituanConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId'),
  },
  {
    name: 'Ctrip aggregate comments stay on browser Profile collection and legacy Cookie config is disabled',
    pass: controllerSource.includes("'ctrip:comments' => $this->executeCtripBrowserProfileAutoFetch(")
      && controllerSource.includes("array_merge($body, ['capture_sections' => 'comment_review'])")
      && ctripCommentsSource.includes('Legacy Ctrip comment Cookie/API config storage is disabled.')
      && ctripCommentsSource.includes('410'),
  },
  {
    name: 'Meituan aggregate comments stay on browser Profile collection and reject Cookie/API config',
    pass: controllerSource.includes("'meituan:comments' => $this->executeMeituanBrowserProfileAutoFetch(")
      && controllerSource.includes("array_merge($body, ['capture_sections' => 'reviews'])")
      && meituanConfigSource.includes('美团点评聚合仅支持受控浏览器 Profile 采集，不接受 Cookie/API 配置。')
      && meituanConfigSource.includes('422'),
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
    name: 'Ctrip manual browser capture is Profile-bound and never carries reusable credentials',
    pass: ctripBrowserCapturePayloadBody.includes('system_hotel_id: systemHotelId')
      && ctripBrowserCapturePayloadBody.includes('profile_id: profileId')
      && ctripBrowserCapturePayloadBody.includes('bind_data_source: options.bindDataSource !== false')
      && !reusableOtaSecretTaskField.test(ctripBrowserCapturePayloadBody)
      && ctripBrowserCaptureBody.includes('BrowserProfileCaptureRequestService::buildCtripBasePlan(')
      && !ctripBrowserCaptureBody.includes('--cookies-file='),
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
    name: 'Ctrip Cookie API capture uses the vault locator and redacted catalog pipeline',
    pass: routeSource.includes("Route::post('/fetch-ctrip-cookie-api', 'OnlineData/fetchCtripCookieApiData')")
      && cookieApiEndpointBody.includes('sanitizeCtripCookieApiExecutionRequestData($rawRequestData)')
      && cookieApiEndpointBody.includes('$this->withOtaCredentialForExecution(')
      && requestConcernSource.includes('ctrip_cookie_api_capture.mjs')
      && requestConcernSource.includes('prepareCtripCookieApiCaptureFiles')
      && requestConcernSource.includes('$this->removeAutoFetchCookieFile($cookieFile)')
      && requestConcernSource.includes('@unlink($prepared[\'input_path\'])')
      && ctripCookieApiScript.includes('runCtripCookieApiCapture')
      && ctripCookieApiScript.includes('findCtripEndpointByUrl')
      && ctripCookieApiScript.includes('extractCtripCatalogFacts')
      && ctripCookieApiScript.includes('buildCtripStandardRowsFromFacts')
      && ctripCookieApiScript.includes("source: 'ctrip_cookie_api'")
      && ctripCookieApiScript.includes('redactHeaders')
      && runFetchBody.includes('config_id: ctripConfigId')
      && runFetchBody.includes('system_hotel_id: systemHotelId')
      && !reusableOtaSecretTaskField.test(runFetchBody),
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
    name: 'Ctrip Cookie API request metadata can be tested only with a ready platform credential locator',
    pass: source.includes("openDataConfigModal('ctrip-cookie-api')")
      && source.includes("case 'ctrip-cookie-api':")
      && source.includes("currentDataConfigType === 'ctrip-cookie-api'")
      && source.includes('dataConfigTestEndpointMap')
      && source.includes("'ctrip-cookie-api': '/online-data/fetch-ctrip-cookie-api'")
      && autoFetchStaticSource.includes('const buildDataConfigTestRequest = ({')
      && autoFetchStaticSource.includes("status: 'credential_not_ready'")
      && autoFetchStaticSource.includes("!String(body.config_id || '').trim() || !String(body.system_hotel_id || '').trim()")
      && indexSource.includes('const buildDataConfigForSave = () => stripDataConfigCredentialFields('),
  },
  {
    name: 'Ctrip Cookie API execution does not extract browser Profile secrets or accept profile-derived Cookie payloads',
    pass: cookieApiEndpointBody.includes('$this->withOtaCredentialForExecution(')
      && !cookieApiEndpointBody.includes('createCtripCookieApiCookieFileFromProfile')
      && !cookieApiEndpointBody.includes('readCtripCookieHeaderFromRequest')
      && !runFetchBody.includes('profile_id:')
      && !runFetchBody.includes('probe_cookie')
      && !runFetchBody.includes('cookie_extractable'),
  },
  {
    name: 'Ctrip overview manual fetch uses vault locator plus API URLs without browser capture',
    pass: requestConcernSource.includes('fetchCtripOverviewData')
      && requestConcernSource.includes('sanitizeCtripOverviewExecutionRequestData')
      && requestConcernSource.includes('$this->withOtaCredentialForExecution(')
      && requestConcernSource.includes('sendCtripOverviewRequest')
      && ctripOverviewRequestBody.includes("config_id: String(configId || '').trim()")
      && ctripOverviewRequestBody.includes('system_hotel_id: systemHotelId')
      && ctripOverviewRequestBody.includes('request_urls: normalizeCtripExecutionRequestUrls(requestUrls)')
      && !reusableOtaSecretTaskField.test(ctripOverviewRequestBody)
      && (source + ctripStaticSource).includes('queryFlowTransforNewV1')
      && (source + ctripStaticSource).includes('getTrafficReportV1')
      && !source.includes("request('/online-data/capture-ctrip-overview-browser'")
      && !requestConcernSource.includes('captureCtripOverviewBrowserData'),
  },
  {
    name: 'manual auto-fetch shows persistent in-panel progress and result',
    pass: /const autoFetchRunState = ref\(\{/.test(source)
      && triggerBody.includes('setRunState(buildAutoFetchRunStartState')
      && /setRunState\(\{[\s\S]*active:\s*true/.test(triggerBody)
      && source.includes('autoFetchRunState.active'),
  },
  {
    name: 'Meituan auto-fetch requires metadata-ready vault configuration',
    pass: meituanStaticSource.includes('const isMeituanExecutionConfigReady = (config = null) => Boolean(')
      && meituanStaticSource.includes('resolveMeituanExecutionConfigId(config)')
      && meituanStaticSource.includes("String(config?.credential_status || '') === 'ready'")
      && meituanStaticSource.includes('config?.has_cookies === true')
      && runFetchBody.includes('isOtaDiagnosisCredentialReady(meituanConfig)')
      && runFetchBody.includes('config_id: meituanConfigId')
      && !reusableOtaSecretTaskField.test(runFetchBody),
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
