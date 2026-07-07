import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

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

const html = readFileSync('public/index.html', 'utf8');
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');
const dataHealthStatic = readFileSync('public/data-health-static.js', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const autoFetchStatic = readFileSync('public/auto-fetch-static.js', 'utf8');
const ctripProfileFieldConfigPanel = readFileSync('public/components/online-data/ctrip-profile-field-config-panel.js', 'utf8')
  .replace(/\\"/g, '"');
const dataHealthOverviewSource = `${html}\n${dataHealthStatic}`;
const backend = readBackendSource();
const ctripPageStart = html.indexOf("currentPage === 'ctrip-ebooking'");
const ctripPageEnd = html.indexOf('<!-- 携程数据抓取设置 -->', ctripPageStart);
const ctripPage = html.slice(ctripPageStart, ctripPageEnd);
const learningDoc = readFileSync('docs/ctrip_capture_field_inventory.md', 'utf8');

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  if (start < 0) return '';
  const end = source.indexOf(endText, start);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const ctripBusinessBoard = sliceBetween(
  ctripPage,
  'data-testid="ctrip-store-overview-business-board"',
  'data-testid="ctrip-store-overview-diagnostics"'
);
const ctripDiagnosticsPanel = sliceBetween(
  ctripPage,
  'data-testid="ctrip-store-overview-diagnostics"',
  '<!-- 榜单数据获取 -->'
);
const ctripCatalogPanel = sliceBetween(
  ctripDiagnosticsPanel,
  'data-testid="ctrip-capture-catalog-health"',
  'data-testid="ctrip-cookie-health-panel"'
);
const onlineDataRecordPanel = sliceBetween(
  html,
  '<!-- 数据记录 -->',
  '<!-- 数据分析 -->'
);

test('Ctrip eBooking first tab is a business-first store data overview with diagnostics collapsed', () => {
  assert.ok(ctripPageStart > 0, 'Ctrip eBooking page section must exist');
  assert.match(ctripPage, />\s*门店数据总览\s*<\/button>/);
  assert.match(ctripPage, /<h4 class="[^"]*">门店数据总览<\/h4>/);
  assert.match(ctripPage, /携程 eBooking OTA渠道口径 · 快速获取目标门店经营、流量、竞争和广告数据/);
  assert.equal(ctripPage.includes('门店总览'), false);

  assert.ok(ctripBusinessBoard.length > 0, 'business board must be rendered');
  assert.ok(ctripDiagnosticsPanel.length > 0, 'diagnostics panel must be rendered');
  assert.ok(ctripPage.indexOf('data-testid="ctrip-store-overview-business-board"') < ctripPage.indexOf('data-testid="ctrip-store-overview-diagnostics"'));
  const detailsOpenTag = ctripPage.match(/<details[^>]*data-testid="ctrip-store-overview-diagnostics"[^>]*>/)?.[0] || '';
  assert.ok(detailsOpenTag, 'diagnostics details tag must exist');
  assert.doesNotMatch(detailsOpenTag, /\bopen\b/, 'diagnostics must be collapsed by default');

  for (const label of ['当前授权', '数据日期', '最近采集', '本轮入库', '可抓模块', '采集状态']) {
    assert.match(dataHealthOverviewSource, new RegExp(label), `missing compact status label: ${label}`);
  }
  for (const label of ['收益经营', '流量漏斗', '竞争表现', '服务质量', '广告投放']) {
    assert.match(dataHealthOverviewSource, new RegExp(label), `missing fetch module card: ${label}`);
  }
  for (const label of ['采集诊断与失败原因', '采集覆盖统计', '携程登录/Cookie 状态', '失败原因']) {
    assert.match(ctripDiagnosticsPanel, new RegExp(label), `diagnostics must retain: ${label}`);
  }
  assert.match(ctripPage, /loadDataHealthPanel/);
});

test('Ctrip store data overview exposes business sections and field-level misses only', () => {
  const requiredLabels = [
    '收益概览',
    '实时预订订单',
    '实时在店间夜',
    '离店销售额',
    '离店间夜',
    '成交率',
    '平均卖价',
    '流量概览',
    '实时访客量',
    '实时排名',
    '竞争圈排名',
    '曝光转化率',
    '下单转化率',
    '成交转化率',
    'APP 流量漏斗',
    '我的酒店',
    '竞争圈平均',
    '列表页曝光量',
    '详情页访客量',
    '订单页访客量',
    '订单提交人数',
    '竞争表现',
    '价格排名',
    '竞品均价',
    '服务质量',
    '竞争圈排名',
    'PSI服务质量分',
    '酒店点评分',
    '5分钟回复率',
    '酒店收藏数',
    '广告投放',
    '广告花费',
    '广告曝光',
    '广告点击',
    '广告订单',
    '广告金额',
    'ROAS',
  ];

  for (const label of requiredLabels) {
    assert.match(dataHealthOverviewSource, new RegExp(label.replace(/[()]/g, '\\$&')), `missing overview label: ${label}`);
  }

  assert.match(ctripBusinessBoard, /未抓到/);
  assert.doesNotMatch(ctripBusinessBoard, /本轮未抓到/);
  assert.doesNotMatch(html, /本轮未命中/);
  assert.match(ctripBusinessBoard, /OTA渠道口径，不代表全酒店经营口径/);
  assert.doesNotMatch(ctripBusinessBoard, /字段缺失 \/ 定义|采集覆盖统计|字段质量|接口响应|携程登录\/Cookie 状态|失败原因|历史回放|待采集项|待补字段/);
  assert.doesNotMatch(ctripBusinessBoard, /用户画像|IM看板|房型/);
  assert.match(html, /collectionHealthCtripPersistedRows/);
  assert.match(html, /buildCollectionHealthCtripPersistedRows/);
  assert.match(dataHealthStatic, /String\(b\?\.data_date \|\| ''\)\.localeCompare\(String\(a\?\.data_date \|\| ''\)\)/);
  assert.match(html, /collectionHealthCtripMetricFromRows/);
  assert.match(dataHealthStatic, /metric_preview/);
  assert.match(dataHealthStatic, /for \(const mapKey of \['metrics', 'raw_metrics', 'rank_metrics'\]\)/);
  assert.match(dataHealthStatic, /const canUseDirectPreviewValue = metricKeyMatched && \(previewMetricKey \|\| dimensionIncludes\.length\)/);
  assert.match(dataHealthStatic, /collectionHealthCtripMetricPreviewValue\(preview, key, \{ direct: canUseDirectPreviewValue \}\)/);
});

test('Ctrip store data overview diagnoses missing metrics and keeps supplement capture in place', () => {
  assert.match(ctripBusinessBoard, /未抓到字段补抓判断/);
  assert.match(ctripBusinessBoard, /metric\.reasonText/);
  assert.match(ctripBusinessBoard, /metric\.actionLabel/);
  assert.match(ctripBusinessBoard, /runCtripOverviewFetchAction\(metric\.actionTab\)/);
  assert.match(html, /const collectionHealthCtripMissingDiagnosis = \(sections, labels, options = {}\) =>/);
  assert.match(html, /const collectionHealthCtripMissingActionRows = computed\(\(\) =>/);
  assert.match(html, /const collectionHealthCtripModuleStats = \(sections\) =>/);
  assert.match(dataHealthStatic, /配置问题/);
  assert.match(dataHealthStatic, /抓取位置不对/);
  assert.match(dataHealthStatic, /字段映射\/入库/);
});

test('Ctrip profile field config manages modules from the same panel', () => {
  assert.ok(ctripProfileFieldConfigPanel.length > 0, 'profile field config panel must exist');
  assert.match(ctripProfileFieldConfigPanel, /模块管理/);
  assert.match(ctripProfileFieldConfigPanel, /获取值 \/ 人工核验/);
  assert.match(ctripProfileFieldConfigPanel, /ctripProfileFieldDisplaySampleLabel\(field\)/);
  assert.match(html, /最近历史获取值/);
  assert.match(ctripProfileFieldConfigPanel, /未获取到值/);
  assert.match(html, /const ctripProfileFieldSampleItems = ctripProfileFieldSampleHelpers\.sampleItems;/);
  assert.match(ctripStatic, /const sampleItems = \(field\) =>/);
  assert.match(html, /const ctripProfileFieldDisplaySampleItems = \(field\) =>/);
  assert.match(ctripStatic, /field\.latest_values/);
  assert.match(ctripStatic, /field\.latest_value/);
  assert.match(html, /include_samples=\$\{includeSamples \? 1 : 0\}/);
  assert.match(ctripProfileFieldConfigPanel, /data-testid="ctrip-profile-module-manager"/);
  assert.match(ctripProfileFieldConfigPanel, /对应网页URL/);
  assert.match(ctripProfileFieldConfigPanel, /打开对应网页/);
  assert.match(ctripProfileFieldConfigPanel, /外部打开/);
  assert.match(ctripProfileFieldConfigPanel, /一级指标/);
  assert.match(ctripProfileFieldConfigPanel, /v-for="category in ctripProfilePrimaryCategoryCards"/);
  assert.doesNotMatch(ctripProfileFieldConfigPanel, /二级分类/);
  assert.doesNotMatch(ctripProfileFieldConfigPanel, /典型指标/);
  assert.match(ctripProfileFieldConfigPanel, /lg:grid-cols-12/);
  assert.match(ctripProfileFieldConfigPanel, /table-fixed/);
  assert.match(ctripProfileFieldConfigPanel, /target="_blank"/);
  assert.match(ctripProfileFieldConfigPanel, /rel="noopener noreferrer"/);
  assert.match(html, /const ctripProfileFieldSectionOptions = computed/);
  assert.match(html, /const openCtripProfileModulePage = \(module\) =>/);
  assert.match(html, /const ctripProfileModulePageDisplay = requireCtripStatic\('ctripProfileModulePageDisplay'\);/);
  assert.match(ctripStatic, /const ctripProfileModulePageDisplay = \(module\) =>/);
  assert.match(ctripStatic, /const ctripProfilePrimaryCategoryOptions = \['流量转化数据', '经营收益数据', '服务质量数据', '竞争力数据'\]/);
  assert.match(ctripStatic, /primary_category: '流量转化数据'/);
  assert.match(ctripStatic, /primary_category: '经营收益数据'/);
  assert.match(ctripStatic, /primary_category: '服务质量数据'/);
  assert.match(ctripStatic, /primary_category: '竞争力数据'/);
  assert.match(html, /const ctripProfilePrimaryCategoryCards = computed/);
  assert.match(html, /ctripProfileModulePageUrl\(module\)/);
  assert.match(html, /\/online-data\/ctrip-profile-modules/);
  assert.match(ctripStatic, /https:\/\/ebooking\.ctrip\.com\/datacenter\/inland\/businessreport\/outline\?microJump=true/);
  assert.match(ctripStatic, /https:\/\/ebooking\.ctrip\.com\/datacenter\/inland\/businessreport\/weekReport\?microJump=true/);
  assert.match(ctripStatic, /https:\/\/ebooking\.ctrip\.com\/datacenter\/inland\/businessreport\/beneficialdata\?microJump=true/);
  assert.match(ctripStatic, /https:\/\/ebooking\.ctrip\.com\/datacenter\/inland\/businessreport\/flowdata\?microJump=true/);
  assert.match(backend, /CTRIP_PROFILE_MODULES_CONFIG_KEY/);
  assert.match(backend, /'page_url' => trim/);
  assert.match(backend, /'primary_category' => trim/);
  assert.match(backend, /resolveCtripProfileCaptureSectionsForRun/);
  assert.match(backend, /'allowed_sections' => array_keys\(\$allowedSections\)/);
  assert.match(backend, /--sections=' \. implode\(',', \$sectionsList/);
  assert.match(backend, /array_intersect_key\(array_fill_keys\(\$metricKeys, true\), \$enabledFieldKeys\)/);
  assert.match(backend, /empty\(\$matchedMetricKeys\)/);
  assert.match(readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8'), /constrainRequestedSectionsByProfileFieldConfig/);
  assert.doesNotMatch(ctripProfileFieldConfigPanel, /房型\/竞品酒店/);
  assert.doesNotMatch(html, /'ctrip-flow-overview': \(\) => runCtripOverviewCookieApiCapture\(\['business_overview', 'sales_report', 'room_type'\]/);
});

test('Online data records tab reads persisted daily data instead of task logs', () => {
  assert.ok(onlineDataRecordPanel.length > 0, 'online data record panel must exist');
  assert.match(onlineDataRecordPanel, /数据类型/);
  assert.match(onlineDataRecordPanel, /维度 \/ 字段/);
  assert.match(onlineDataRecordPanel, /暂无入库数据/);
  assert.doesNotMatch(onlineDataRecordPanel, /抓取状态/);
  assert.doesNotMatch(onlineDataRecordPanel, /暂无自动抓取记录/);

  const loader = sliceBetween(
    html,
    'const loadOnlineDataList = async (options = {}) =>',
    '// 加载数据汇总'
  );
  assert.match(loader, /\/online-data\/daily-data-list/);
  assert.doesNotMatch(loader, /\/online-data\/auto-fetch-records/);
  assert.match(loader, /system_hotel_id/);
  assert.match(loader, /data_quality_summary/);

  const batchDelete = sliceBetween(
    html,
    'const batchDeleteOnlineData = async () =>',
    'const clearAutoFetchRecordHistory = async () =>'
  );
  assert.match(batchDelete, /\/online-data\/batch-delete/);
  assert.doesNotMatch(batchDelete, /batch-delete-auto-fetch-records/);
});

test('Ctrip overview one-click core capture stays on overview and supplemental fetches execute directly', () => {
  const quickActions = sliceBetween(
    ctripPage,
    'data-testid="ctrip-overview-fetch-actions"',
    '<div v-if="collectionReliabilityLoading"'
  );
  assert.ok(quickActions.length > 0, 'overview quick fetch actions must exist');
  assert.match(quickActions, /一键抓取/);
  assert.match(dataHealthOverviewSource, /抓取竞争/);
  assert.match(dataHealthOverviewSource, /抓取经营/);
  assert.match(dataHealthOverviewSource, /抓取流量/);
  assert.match(dataHealthOverviewSource, /抓取 PSI/);
  assert.match(dataHealthOverviewSource, /抓取广告/);
  assert.match(quickActions, /runCtripOverviewCoreFetchAction/);
  assert.doesNotMatch(quickActions, /runCtripOverviewFetchAllActions/);
  assert.match(quickActions, /runCtripOverviewFetchAction\(item\.tab\)/);
  assert.match(dataHealthOverviewSource, /tab:\s*'ctrip-ranking'/);
  assert.match(dataHealthOverviewSource, /tab:\s*'ctrip-flow-overview'/);
  assert.match(dataHealthOverviewSource, /tab:\s*'ctrip-traffic'/);
  assert.match(dataHealthOverviewSource, /tab:\s*'ctrip-quality'/);
  assert.match(dataHealthOverviewSource, /tab:\s*'ctrip-ads'/);
  assert.doesNotMatch(quickActions, /openCtripOverviewFetchTab\('ctrip-/);
  assert.match(html, /const runCtripOverviewFetchAction = async \(tabName\) =>/);
  assert.match(html, /const runCtripOverviewCoreFetchAction = async \(\) =>/);
  assert.doesNotMatch(html, /const runCtripOverviewFetchAllActions = async \(\) =>/);
  assert.match(html, /const runCtripOverviewCookieApiCapture = async \(sections = \[\], label = '核心数据'\) =>/);
  assert.match(html, /const resolveCtripCookieApiRequestHotelId = \(systemHotelId = '', activeConfig = null, \{ allowForm = true \} = \{\}\) =>/);
  assert.match(html, /!isCtripPlaceholderHotelId\(value\)/);
  assert.match(html, /resolveCtripCookieApiRequestHotelId\(systemHotelId, activeConfig, \{ allowForm: false \}\)/);
  assert.doesNotMatch(html, /hotel_id:\s*ctripBrowserCaptureForm\.value\.hotelId[\s\S]*\|\|\s*ctripForm\.value\.nodeId/);
  assert.match(html, /'ctrip-ranking': \(\) => runCtripOverviewCookieApiCapture\(\['competitor_overview', 'competitor_rank'\]/);
  assert.match(html, /'ctrip-flow-overview': \(\) => runCtripOverviewCookieApiCapture\(\['business_overview', 'sales_report'\]/);
  assert.match(html, /'ctrip-traffic': \(\) => runCtripOverviewCookieApiCapture\(\['traffic_report'\]/);
  assert.match(html, /'ctrip-quality': \(\) => runCtripOverviewCookieApiCapture\(\['quality_psi'\]/);
  assert.match(html, /'ctrip-ads': \(\) => runCtripOverviewCookieApiCapture\(\['ads_pyramid'\]/);
  assert.match(ctripStatic, /const optionSections = options\.sections \|\| options\.captureSections \|\| ''/);

  const quickActionRunner = sliceBetween(
    html,
    'const runCtripOverviewFetchActionInternal = async (tabName, options = {}) =>',
    'const runCtripOverviewFetchAction = async (tabName) =>'
  );
  const coreActionRunner = sliceBetween(
    html,
    'const runCtripOverviewCoreFetchAction = async () =>',
    'const refreshCtripHotelConfigOptions = () =>'
  );
  assert.match(html, /const prepareCtripOverviewFetchAction = async \(tabName\) =>/);
  assert.match(quickActionRunner, /await prepareCtripOverviewFetchAction\(tabName\)/);
  assert.match(quickActionRunner, /scheduleDataHealthPanelRefresh\('light', \{ force: true \}\)/);
  assert.doesNotMatch(quickActionRunner, /await loadDataHealthPanel\('light', \{ force: true \}\)/);
  assert.doesNotMatch(quickActionRunner, /openCtripOverviewFetchTab/);
  assert.doesNotMatch(quickActionRunner, /onlineDataTab\.value\s*=\s*tabName/);
  assert.doesNotMatch(quickActionRunner, /onlineDataTab\.value\s*=\s*'data-health'/);
  assert.match(coreActionRunner, /await prepareCtripOverviewFetchAction\('core'\)/);
  assert.match(coreActionRunner, /const coreFetchTabs = \['ctrip-flow-overview', 'ctrip-traffic', 'ctrip-ranking', 'ctrip-quality', 'ctrip-ads'\]/);
  assert.match(coreActionRunner, /const action = ctripOverviewFetchActionMap\(\)\[tabName\]/);
  assert.match(coreActionRunner, /await action\(\)/);
  assert.match(coreActionRunner, /scheduleDataHealthPanelRefresh\('light', \{ force: true \}\)/);
  assert.doesNotMatch(coreActionRunner, /await loadDataHealthPanel\('light', \{ force: true \}\)/);
  assert.doesNotMatch(coreActionRunner, /runCtripBrowserCapture/);
  assert.doesNotMatch(coreActionRunner, /ctripBrowserCaptureForm\.value\.sections/);
  assert.doesNotMatch(coreActionRunner, /openCtripOverviewFetchTab/);
  assert.doesNotMatch(coreActionRunner, /onlineDataTab\.value\s*=\s*tabName/);
  assert.doesNotMatch(coreActionRunner, /onlineDataTab\.value\s*=\s*'data-health'/);

  const cookieApiRunner = sliceBetween(
    html,
    'const runCtripCookieApiCapture = async () =>',
    'const validateCtripEndpointEvidence = async () =>'
  );
  const profileRunner = sliceBetween(
    html,
    'const runCtripBrowserCapture = async (options = {}) =>',
    'const loadCtripDiagnosisSnapshot'
  );
  assert.match(cookieApiRunner, /refreshDataHealthPanel:\s*scheduleDataHealthPanelRefresh/);
  assert.match(profileRunner, /refreshDataHealthPanel:\s*scheduleDataHealthPanelRefresh/);
  assert.match(ctripStatic, /runPostFetchRefresh\(refreshDataHealthPanel, 'light', \{ force: true \}\)/);
});

test('Ctrip Cookie API save is guarded against cross-store hotel identity conflicts', () => {
  const cookieApiHandler = sliceBetween(
    backend,
    'public function fetchCtripCookieApiData(): Response',
    'private function parseCtripCookieApiConfigFile'
  );
  assert.match(cookieApiHandler, /validateCtripPayloadHotelIdentity\(\$payload, \(int\)\$systemHotelId, \$prepared\['config'\] \?\? \[\]\)/);
  assert.match(cookieApiHandler, /reason'\s*=>\s*'hotel_identity_mismatch'/);
  assert.match(cookieApiHandler, /saved_count'\s*=>\s*0/);
  assert.match(backend, /private function validateCtripPayloadHotelIdentity\(array \$payload, int \$systemHotelId, array \$config = \[\]\): array/);
  assert.match(backend, /private function findCtripPlatformHotelIdConflicts\(array \$platformHotelIds, int \$systemHotelId\): array/);
  assert.match(backend, /private function filterAmbiguousCtripHotelRows\(array \$rows, \?int \$hotelId\): array/);
  assert.match(backend, /private function buildCtripHotelIdentityFilterReport\(\?int \$hotelId, string \$startDate, string \$endDate, int \$limit\): array/);
  assert.match(backend, /'ctrip_hotel_identity_filter'\s*=>\s*\$ctripIdentityFilter/);
  assert.match(backend, /\$idPreview = array_slice\(\$ambiguousHotelIds, 0, 5\)/);
  assert.match(backend, /return array_slice\(OtaOperatingScope::filterOwnOperatingRows\(\$filteredRows, \$ownHotelNames\), 0, \$requestedLimit\);/);
});

test('Ctrip hotel identity safety ignores competitor rows and whitelists configured current-store ids', () => {
  assert.match(backend, /private function getCtripExpectedPlatformHotelIdsForSystemHotel\(int \$systemHotelId\): array/);
  assert.match(backend, /private function getCtripNodeResourceIdsForSystemHotel\(int \$systemHotelId\): array/);
  assert.match(backend, /private function isCtripGenericSelfHotelName\(string \$value\): bool/);
  assert.match(backend, /private function isCtripCurrentHotelIdentityRow\(array \$row, int \$systemHotelId, array \$expectedIds = \[\], array \$nodeIds = \[\]\): bool/);
  assert.match(backend, /private function shouldBlockCtripCurrentHotelIdConflict\(string \$platformHotelId, array \$expectedIds\): bool/);

  const expectedIdExtractor = sliceBetween(
    backend,
    'private function extractExpectedCtripPlatformHotelIds',
    'private function extractCtripNodeResourceIds'
  );
  assert.doesNotMatch(expectedIdExtractor, /node_id|nodeId/, 'nodeId is a request resource id, not a Ctrip hotelId');
  const cookieApiConfigBuilder = sliceBetween(
    backend,
    'private function buildCtripCookieApiCaptureConfigFromRequest',
    'private function normalizeCtripCookieApiEndpointsFromRequest'
  );
  assert.doesNotMatch(cookieApiConfigBuilder, /requestData\['node_id'\]|requestData\['nodeId'\]/, 'Cookie API hotel_id must not fall back to nodeId');

  const reportBuilder = sliceBetween(
    backend,
    'private function buildCtripHotelIdentityFilterReport',
    'private function filterAmbiguousCtripHotelRows'
  );
  assert.match(reportBuilder, /getCtripExpectedPlatformHotelIdsForSystemHotel\(\(int\)\$hotelId\)/);
  assert.match(reportBuilder, /getCtripNodeResourceIdsForSystemHotel\(\(int\)\$hotelId\)/);
  assert.match(reportBuilder, /isCtripCurrentHotelIdentityRow\(/);
  assert.match(reportBuilder, /shouldBlockCtripCurrentHotelIdConflict\(/);

  const rowFilter = sliceBetween(
    backend,
    'private function filterAmbiguousCtripHotelRows',
    'private function buildCollectionHistoryReplayRows'
  );
  assert.match(rowFilter, /shouldKeepCtripScopedHotelRow\(\$row, \(int\)\$hotelId, \$currentIds, \$expectedIds, \$nodeIds\)/);
  assert.match(rowFilter, /shouldBlockCtripCurrentHotelIdConflict\(/);
});

test('Ctrip overview and profile capture do not use nodeId as OTA hotelId', () => {
  const overviewFetch = sliceBetween(
    html,
    'const fetchCtripOverviewData',
    'const fetchCtripFlowOverviewData'
  );
  const flowFetch = sliceBetween(
    html,
    'const fetchCtripFlowOverviewData',
    'const runCtripBrowserCapture'
  );
  const configApplier = sliceBetween(
    html,
    'const applyCtripConfigObject',
    'const getActiveCtripConfig'
  );
  const profileCapture = sliceBetween(
    html,
    'const runCtripBrowserCapture = async (options = {}) =>',
    'const loadCtripDiagnosisSnapshot'
  );
  const cookieApiResolver = sliceBetween(
    html,
    'const resolveCtripCookieApiRequestHotelId',
    'const checkCtripProfileStatus'
  );

  const profileLoginPayload = sliceBetween(
    html,
    'const buildPlatformProfileLoginPayload',
    'const pollPlatformProfileLoginStatus'
  );
  const profileLoginTrigger = sliceBetween(
    html,
    'const triggerPlatformProfileLogin',
    'const platformProfileStatusLabel'
  );
  const cookieApiProfileResolver = sliceBetween(
    html,
    'const resolveCtripCookieApiProfileId',
    'const isCtripPlaceholderHotelId'
  );
  const profileLoginKeyResolver = sliceBetween(
    backend,
    'private function resolvePlatformProfileLoginProfileKey',
    'private function preparePlatformProfileLoginRequest'
  );

  for (const block of [overviewFetch, flowFetch, profileCapture, cookieApiResolver, profileLoginPayload]) {
    assert.doesNotMatch(block, /activeConfig\?\.node_id|activeConfig\?\.nodeId|ctripForm\.value\.nodeId/);
  }
  assert.match(configApplier, /const ctripHotelId = String\(config\.ota_hotel_id \|\| config\.ctrip_hotel_id \|\| config\.ctripHotelId \|\| ''\)/);
  assert.doesNotMatch(configApplier, /const ctripHotelId = String\([\s\S]*node_id|const ctripHotelId = String\([\s\S]*nodeId/);
  assert.match(html, /const defaultCtripBrowserProfileId = \(hotelId = getAutoFetchHotelId\(\)\) =>/);
  assert.match(profileLoginPayload, /const dataSourceId = Number\(item\?\.data_source_id \|\| item\?\.dataSourceId \|\| 0\)/);
  assert.match(profileLoginPayload, /const syncAfterLogin = !!\(item\?\.sync_after_login \|\| item\?\.syncAfterLogin \|\| dataSourceId > 0\)/);
  assert.match(profileLoginPayload, /const loginTargetDate = String\(item\?\.data_date \|\| item\?\.dataDate \|\| item\?\.target_date \|\| item\?\.targetDate \|\| formatDate\(new Date\(\)\)\)\.trim\(\)/);
  assert.match(profileLoginPayload, /const profileId = resolveCtripBrowserProfileId\(\{ item, hotelId, allowDefault: true \}\)/);
  assert.match(profileLoginPayload, /form\.profileId = profileId/);
  assert.match(profileLoginPayload, /data_source_id: dataSourceId \|\| undefined/);
  assert.match(profileLoginPayload, /sync_after_login: syncAfterLogin \|\| undefined/);
  assert.match(profileLoginPayload, /data_date: loginTargetDate \|\| undefined/);
  assert.match(profileLoginPayload, /\(dataSourceId \? '' : meituanForm\.value\.poiId\)/);
  assert.match(profileLoginTrigger, /buildPlatformProfileLoginPayload\(platform, item\)/);
  assert.match(profileLoginTrigger, /const hasDataSourceId = Number\(payload\.data_source_id \|\| payload\.source_id \|\| 0\) > 0/);
  assert.match(profileLoginTrigger, /platform === 'ctrip' && !payload\.profile_id && !hasDataSourceId/);
  assert.match(profileLoginTrigger, /platform === 'meituan' && !payload\.store_id && !hasDataSourceId/);
  assert.doesNotMatch(profileLoginPayload, /form\.profileId \|\| hotelIdValue \|\| hotelId/);
  assert.match(profileCapture, /resolveProfileId:\s*activeConfig => resolveCtripBrowserProfileId\(\{\s*activeConfig\s*\}\)/);
  assert.doesNotMatch(profileCapture, /form\.profileId \|\| hotelId \|\| systemHotelId/);
  assert.match(cookieApiProfileResolver, /resolveCtripBrowserProfileId\(\{\s*activeConfig,\s*includeCookieForm: true,\s*\}\)/);
  assert.doesNotMatch(cookieApiProfileResolver, /nodeId|systemHotelId\s*\|\|/);
  const ctripProfileIdLine = configApplier.match(/const ctripProfileId = String\([^\n]+\)/)?.[0] || '';
  assert.match(ctripProfileIdLine, /config\.profile_id \|\| config\.profileId \|\| config\.browser_profile_id \|\| config\.browserProfileId \|\| config\.ota_hotel_id \|\| config\.ctrip_hotel_id \|\| config\.ctripHotelId \|\| ''/);
  assert.doesNotMatch(ctripProfileIdLine, /config\.hotel_id|config\.system_hotel_id/);
  assert.match(backend, /private function resolveExistingCtripBrowserProfileKey\(int \$hotelId\): string/);
  assert.match(profileLoginKeyResolver, /\$existingProfileKey = \$this->resolveExistingCtripBrowserProfileKey\(\$hotelId\)/);
  assert.match(profileLoginKeyResolver, /\$profileKey !== '' && \$profileKey === \(string\)\$hotelId && \$existingProfileKey !== '' && \$existingProfileKey !== \$profileKey/);
  assert.match(profileLoginKeyResolver, /return \$existingProfileKey/);
});

test('Platform account badge treats browser profile login timeout as login expired', () => {
  const loginExpiredDetector = sliceBetween(
    html,
    'const isPlatformSourceLoginExpired',
    'const hasPlatformHotelMismatch'
  );
  const accountRowBuilder = sliceBetween(
    systemStatic,
    'const buildHotelPlatformAccountRow',
    'return {'
  );
  const accountRowWrapper = sliceBetween(
    html,
    'const buildHotelPlatformBindingRowsStatic',
    'const hotelAccountSummary'
  );

  assert.match(loginExpiredDetector, /browser_profile/);
  assert.match(loginExpiredDetector, /需重新登录/);
  assert.match(loginExpiredDetector, /login\\s\*timeout/);
  assert.match(accountRowBuilder, /const loginExpired = isPlatformSourceLoginExpired\(source, config\)/);
  assert.match(systemStatic, /data_source_id: profileSource\?\.id \|\| source\?\.id \|\| undefined/);
  assert.match(autoFetchStatic, /status === 'syncing_after_login' \|\| sync\?\.status === 'running'/);
  assert.match(autoFetchStatic, /登录后同步完成，目标日已入库/);
  assert.match(accountRowBuilder, /loginExpired \? 'login_expired'/);
  assert.match(accountRowBuilder, /effectiveReady \? 'logged_in'/);
  assert.match(accountRowWrapper, /buildHotelPlatformBindingRowsStatic/);
  assert.match(accountRowWrapper, /platformSourceForHotel\(hotelId, 'ctrip'\)/);
  assert.match(accountRowWrapper, /platformSourceForHotel\(hotelId, 'meituan'\)/);
});

test('Ctrip collection quality rows overfetch before identity filtering', () => {
  const qualityRowsLoader = sliceBetween(
    backend,
    'private function loadCollectionQualityRows',
    'private function buildCtripHotelIdentityFilterReport'
  );

  assert.match(qualityRowsLoader, /\$requestedLimit = max\(1, \$limit\)/);
  assert.match(qualityRowsLoader, /\$fetchLimit = \$hotelId === null \? \$requestedLimit : min\(2000, max\(\$requestedLimit, \$requestedLimit \* 20\)\)/);
  assert.match(qualityRowsLoader, /->limit\(\$fetchLimit\)/);
  assert.match(qualityRowsLoader, /\$filteredRows = \$this->filterAmbiguousCtripHotelRows\(\$rows, \$hotelId\)/);
  assert.match(qualityRowsLoader, /return array_slice\(OtaOperatingScope::filterOwnOperatingRows\(\$filteredRows, \$ownHotelNames\), 0, \$requestedLimit\)/);
});

test('Ctrip overview matches compound catalog metric keys from persisted rows', () => {
  const metricKeyMatcher = sliceBetween(
    dataHealthStatic,
    'const collectionHealthCtripMetricKeyMatches',
    'const collectionHealthCtripMissingDiagnosis'
  );

  assert.match(metricKeyMatcher, /metricKeyParts/);
  assert.match(metricKeyMatcher, /\.split\(\s*\/\[\\\+,\\\|\\s\]\+\/\s*\)/);
  assert.match(metricKeyMatcher, /collectionHealthCtripMetricKeyAliases\(key\)\.has\(part\)/);
  assert.match(dataHealthStatic, /visitor_rank/);
  assert.match(dataHealthStatic, /const calculatedValue = collectionHealthCtripCalculatedValue\(preview, key\)/);
  assert.match(dataHealthStatic, /if \(calculatedValue !== undefined\)/);
});

test('Ctrip overview funnel keeps my hotel metrics scoped to self rows', () => {
  const funnelMetricBuilder = sliceBetween(
    dataHealthStatic,
    'const buildCollectionHealthCtripOverviewFunnelRows',
    'const buildCollectionHealthCtripOverviewPanels'
  );

  assert.match(html, /buildCollectionHealthCtripOverviewFunnelRows\(collectionHealthCtripOverviewContext\(\)\)/);
  assert.match(funnelMetricBuilder, /funnelMetric\([^\\n]+,\s*\['self', 'myhotel'\]\)/);
  assert.doesNotMatch(funnelMetricBuilder, /collectionHealthCtripMetricFromRows\(keys, \{ dataTypes: \['traffic'\] \}\)/);
});

test('Ctrip store overview surfaces identity-filtered rows instead of generic uncaptured state', () => {
  assert.match(html, /collectionHealthCtripIdentityFilter/);
  assert.match(html, /collectionHealthCtripIdentityBlocked/);
  assert.match(html, /collectionHealthCtripIdentityMessage/);
  assert.match(ctripPage, /已抓到数据，但当前门店身份不安全，暂不展示经营结果。/);
  assert.match(dataHealthOverviewSource, /门店身份冲突/);
  assert.match(dataHealthOverviewSource, /已过滤 \$\{identityReport\.filtered_count \|\| 0\} 条错店风险数据/);
  assert.match(dataHealthStatic, /diagnosisType:\s*'hotel_identity_conflict'/);
  assert.match(dataHealthStatic, /已抓到携程数据，但门店身份存在冲突，系统已阻止展示错店风险数据。/);
});

test('Ctrip store overview ignores stale health responses after hotel switching', () => {
  assert.match(html, /let collectionReliabilityRequestSeq = 0/);
  assert.match(html, /const handleCtripOverviewHotelChange = async \(event = null\) =>/);
  assert.match(html, /autoFetchHotelId\.value = String\(event\.target\.value \|\| ''\)/);
  assert.match(html, /await syncCtripOverviewTargetHotel\(\{ clearDisplay: true, loadConfig: true \}\);\s*scheduleDataHealthPanelRefresh\('light', \{ force: true \}\);/);
  assert.doesNotMatch(html, /await syncCtripOverviewTargetHotel\(\{ clearDisplay: true, loadConfig: true \}\);\s*await loadDataHealthPanel\('light'\);/);
  assert.match(html, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(html, /await applyCtripHotelConfig\(false, \{\s*refreshList: false,\s*refreshLatest: false,\s*skipIfAligned: true,\s*\}\);/);
  assert.match(html, /const requestSeq = \+\+collectionReliabilityRequestSeq/);
  assert.match(html, /requestSeq !== collectionReliabilityRequestSeq \|\| String\(hotelId \|\| ''\) !== String\(getAutoFetchHotelId\(\) \|\| ''\)/);
  assert.match(html, /if \(requestSeq === collectionReliabilityRequestSeq\) \{\s*collectionReliabilityLoading\.value = false;/);
});

test('Ctrip store data overview exposes Ctrip platform authorization CRUD with traffic lights', () => {
  assert.match(ctripDiagnosticsPanel, /携程登录\/Cookie 状态/);
  assert.match(ctripDiagnosticsPanel, /collectionHealthCtripAuthorizationRows/);
  assert.match(ctripDiagnosticsPanel, /collectionHealthCookieLightClass/);
  assert.match(ctripDiagnosticsPanel, /openCtripCookieCreateFromHealth/);
  assert.match(ctripDiagnosticsPanel, /editCtripCookieFromHealth/);
  assert.match(ctripDiagnosticsPanel, /deleteCtripCookieFromHealth/);
  assert.match(ctripDiagnosticsPanel, /建议删除/);
  assert.match(dataHealthStatic, /green:\s*'bg-green-500'/);
  assert.match(dataHealthStatic, /red:\s*'bg-red-500'/);
  assert.match(dataHealthStatic, /const buildCollectionHealthFailureReasonRanking = /);
  assert.match(dataHealthStatic, /const buildDataHealthTodayWorkOrders = /);
  assert.match(dataHealthStatic, /const buildDataHealthDiagnosticBoundary = /);
  assert.match(dataHealthStatic, /const buildDataHealthQualityTaskRows = /);
  assert.match(dataHealthStatic, /const summarizePublicEndpointSecurity = /);
  assert.match(dataHealthStatic, /const publicEndpointDisplayName = /);
  assert.match(dataHealthStatic, /const publicEndpointSecurityBoundaryText = /);
  assert.match(dataHealthStatic, /const buildOtaFieldGapQueueRows = /);
  assert.match(dataHealthStatic, /const summarizeOtaFieldGapQueue = /);
  assert.match(dataHealthStatic, /const buildReleaseEvidencePanelRows = /);
  assert.match(dataHealthStatic, /const summarizeReleaseEvidencePanel = /);
  assert.match(dataHealthStatic, /daily_workbench_patrol_cron/);
  assert.match(dataHealthStatic, /const buildCollectionHealthAuthorizationRowsReadable = /);
  assert.match(dataHealthStatic, /const buildCollectionHealthPendingActionRows = /);
  assert.match(dataHealthStatic, /const buildCollectionHealthFieldAssetCards = /);
  assert.match(dataHealthStatic, /const buildCollectionHealthCtripCatalogCards = /);
  assert.match(dataHealthStatic, /const buildCollectionHealthCtripLatestCards = /);
  assert.match(dataHealthStatic, /const buildCollectionHealthCtripOverviewStatusCards = /);
  assert.match(dataHealthStatic, /const buildCtripOverviewFetchModuleCards = /);
  assert.match(html, /requireDataHealthStatic\('buildCollectionHealthFailureReasonRanking'\)/);
  assert.match(html, /requireDataHealthStatic\('buildCollectionHealthAuthorizationRowsReadable'\)/);
  assert.match(html, /requireDataHealthStatic\('buildCollectionHealthPendingActionRows'\)/);
  assert.match(html, /requireDataHealthStatic\('buildCollectionHealthFieldAssetCards'\)/);
  assert.match(html, /requireDataHealthStatic\('buildDataHealthQualityTaskRows'\)/);
  assert.match(html, /requireDataHealthStatic\('summarizePublicEndpointSecurity'\)/);
  assert.match(html, /requireDataHealthStatic\('publicEndpointDisplayName'\)/);
  assert.match(html, /requireDataHealthStatic\('publicEndpointSecurityBoundaryText'\)/);
  assert.match(html, /requireDataHealthStatic\('buildOtaFieldGapQueueRows'\)/);
  assert.match(html, /requireDataHealthStatic\('summarizeOtaFieldGapQueue'\)/);
  assert.match(html, /const otaFieldGapQueueRows = computed\(\(\) => buildOtaFieldGapQueueRows\(\{/);
  assert.match(html, /otaFieldGapQueueRows, otaFieldGapQueueSummary/);
  assert.match(html, /data-testid="ota-field-gap-queue"/);
  assert.match(html, /requireDataHealthStatic\('buildReleaseEvidencePanelRows'\)/);
  assert.match(html, /requireDataHealthStatic\('summarizeReleaseEvidencePanel'\)/);
  assert.match(html, /source_path/);
  assert.match(html, /const releaseEvidenceRows = computed\(\(\) => buildReleaseEvidencePanelRows\(releaseEvidenceStatus\.value \|\| \{\}\)\)/);
  assert.match(html, /request\('\/online-data\/release-evidence-status'\)/);
  assert.match(html, /releaseEvidenceStatus, releaseEvidenceLoading, releaseEvidenceError, releaseEvidenceRows, releaseEvidenceSummary/);
  assert.match(html, /data-testid="release-evidence-panel"/);
  assert.match(html, /不替代最终设计交付|涓嶆浛浠ｆ渶缁堣璁′氦浠?/);
  assert.match(html, /requireDataHealthStatic\('buildCollectionHealthCtripCatalogCards'\)/);
  assert.match(html, /requireDataHealthStatic\('buildCtripOverviewFetchModuleCards'\)/);
  assert.match(html, /buildDataHealthTodayWorkOrders\(\{/);
});

test('Ctrip platform authorization status supports inline view and edit', () => {
  assert.match(ctripDiagnosticsPanel, /查看\/编辑/);
  assert.match(html, /showCtripCookieEditorModal/);
  assert.match(html, /ctripCookieEditorForm/);
  assert.match(html, /openCtripCookieEditorFromHealth/);
  assert.match(html, /saveCtripCookieFromHealth/);
  assert.match(html, /const listConfig = ctripConfigList\.value\.find\(item => String\(item\.id \|\| ''\) === configId\);\s*const config = listConfig\s*\? await ensureCtripConfigSecret\(listConfig\)\s*: await loadCtripConfigDetail\(configId\);/);
  assert.doesNotMatch(html, /if \(!ctripConfigList\.value\.length\) \{\s*await loadCtripConfigList\(\);\s*\}\s*const listConfig = ctripConfigList\.value\.find\(item => String\(item\.id \|\| ''\) === configId\);/);
  assert.match(html, /loadCtripConfigList\(\);\s*scheduleDataHealthPanelRefresh\('light', \{ force: true \}\);/);
  assert.match(html, /await deleteCtripConfig\(configId\);\s*scheduleDataHealthPanelRefresh\('light', \{ force: true \}\);/);
  assert.doesNotMatch(html, /await loadCtripConfigList\(\);\s*await loadDataHealthPanel\('light', \{ force: true \}\);/);
  assert.doesNotMatch(html, /await deleteCtripConfig\(configId\);\s*await loadDataHealthPanel\('light', \{ force: true \}\);/);
  assert.match(html, /const results = await Promise\.all\(ids\.map\(async \(id\) => \{/);
  assert.match(html, /deferUiTask\(\(\) => loadCtripConfigList\(\), 80\);/);
  assert.doesNotMatch(html, /if \(deletedCount > 0\) \{\s*await loadCtripConfigList\(\);\s*\}/);
  assert.match(html, /查看 \/ 编辑携程临时 Cookie\/API 辅助/);
  assert.match(html, /v-model="ctripCookieEditorForm\.cookies"/);
});

test('Ctrip capture coverage panel hides raw diagnostic field names', () => {
  assert.ok(ctripCatalogPanel.length > 0, 'Ctrip capture coverage panel must exist');
  assert.match(ctripCatalogPanel, /采集覆盖统计/);
  assert.match(ctripCatalogPanel, /诊断口径/);
  assert.match(ctripCatalogPanel, /待补采集/);
  assert.doesNotMatch(
    ctripCatalogPanel,
    /auth_session|response_count|standard_rows|endpoint_coverage|field_coverage|capture_missing_formal_endpoint|capture_gate_status|auth_status|failed_check_ids|capture_gap_blockers|endpoint_id|默认范围|wide范围/
  );
});

test('Ctrip flow overview interface misses show actionable reasons', () => {
  assert.match(ctripPage, /说明 \/ 未命中原因/);
  assert.match(ctripStatic, /const buildCtripOverviewMetricCards = /);
  assert.match(ctripStatic, /const buildCtripOverviewTopRankTables = /);
  assert.match(ctripStatic, /const buildCtripFlowOverviewMetricCards = /);
  assert.match(ctripStatic, /const buildCtripSortedHotelRows = /);
  assert.match(ctripStatic, /const buildCtripFlowOverviewInterfaceRows = /);
  assert.match(ctripStatic, /const buildCtripFlowOverviewInterfaceReason = \(context\) =>/);
  assert.match(ctripStatic, /未在本次 Request URL 列表中配置/);
  assert.match(ctripStatic, /已配置但未收到接口响应/);
  assert.match(ctripStatic, /接口有响应但未解析到可入库行/);
  assert.match(ctripStatic, /接口请求失败/);
  assert.match(html, /requireCtripStatic\('buildCtripOverviewMetricCards'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripOverviewTopRankTables'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripFlowOverviewMetricCards'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripSortedHotelRows'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripFlowOverviewInterfaceRows'\)/);
  assert.match(html, /row\.reasonText/);
  assert.doesNotMatch(html, /const normalizeCtripTopRankItems = /);
  assert.doesNotMatch(html, /const field = ctripSortField\.value;/);
  assert.doesNotMatch(html, /本次未从响应 URL 中命中该接口/);
});

test('Ctrip learning table records scope, source, conversion, missing status and real-sample requirements', () => {
  assert.match(learningDoc, /## 核心指标学习表/);
  assert.match(learningDoc, /\| 中文名 \| 口径 \| 时间口径 \| 类型 \| 单位 \| 来源网页 \| 来源接口\/源字段名 \| 转换规则 \| 缺失状态 \| 样例值 \| 可信状态 \|/);

  const requiredRows = [
    '| 昨日浏览量 | 携程OTA渠道 | 昨日 | 整数 | 次 | 流量数据页 | `queryScanFlowDetailsV2 / pvDataList` | 取列表最后一个有效数值 | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 待确认 |',
    '| 昨日访客数 | 携程OTA渠道 | 昨日 | 整数 | 人 | 昨日概况页 | `getDayReportRealTimeDate / lastVisitorTotal` | 直接取整数 | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 已确认 |',
    '| 离店销售额 | 携程OTA渠道 | 昨日 | 金额 | 元 | 经营报告-概要-日报 | `fetchMarketOverViewV2 / amount` | 去逗号后转金额 | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 已确认 |',
    '| 曝光转化率 | 携程OTA渠道 | 昨日 | 小数 | % | 昨日概况页 | `fetchMarketOverViewV2 / orderConversionRate` | 当前代码同取下单转化率，不应自动确认 | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 待确认 |',
    '| 离店销售额竞争圈排名 | 携程OTA渠道 | 昨日 | 文本/排名 | 名 | 经营报告-概要-日报 | `fetchMarketOverViewV2 / rankOfAmount + competitorNumber` | 拼成 `排名/总数` | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 已确认 |',
    '| 竞品访客 | 携程竞争圈 | 昨日 | 整数 | 人 | 昨日概况页 | `getDayReportFlowCompete / comhtluv` | 直接取值 | `api_not_hit / field_missing / parse_failed` | 需用真实响应补 | 已确认 |',
  ];

  for (const row of requiredRows) {
    assert.ok(learningDoc.includes(row), `missing learning row: ${row}`);
  }

  for (const status of ['ok', 'page_not_loaded', 'api_not_hit', 'field_missing', 'empty_value', 'parse_failed', 'unverified_mapping']) {
    assert.match(learningDoc, new RegExp(`\\| \`${status}\` \\|`));
  }

  assert.match(learningDoc, /"raw_value": "原始接口值"/);
  assert.match(learningDoc, /"parsed_value": "宿析OS解析后的值"/);
  assert.match(learningDoc, /"captured_at": "采集时间"/);
  assert.match(learningDoc, /"store_id": "门店ID"/);
});
