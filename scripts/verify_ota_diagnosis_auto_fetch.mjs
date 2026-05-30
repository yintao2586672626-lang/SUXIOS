import { existsSync, readFileSync } from 'node:fs';

const source = readFileSync('public/index.html', 'utf8');
const controllerSource = readFileSync('app/controller/OnlineData.php', 'utf8');
const ctripBrowserScriptPath = 'scripts/ctrip_browser_capture.mjs';
const ctripBrowserScript = existsSync(ctripBrowserScriptPath) ? readFileSync(ctripBrowserScriptPath, 'utf8') : '';

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
    name: 'Ctrip browser capture defaults to overview and traffic only',
    pass: ctripBrowserScript.includes('businessreport/outline')
      && ctripBrowserScript.includes('businessreport/flowdata')
      && ctripBrowserScript.includes("args.sections || 'business,traffic'")
      && ctripBrowserScript.includes('requestedSections.includes(section)')
      && ctripBrowserScript.includes('target.business.push')
      && ctripBrowserScript.includes('target.traffic.push')
      && !ctripBrowserScript.includes("args.sections || 'business,traffic,reviews'")
      && controllerSource.includes('--sections=business,traffic'),
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
