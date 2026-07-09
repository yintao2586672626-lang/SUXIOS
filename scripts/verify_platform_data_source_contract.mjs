import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function check(file, label, predicate, detail) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: predicate(source),
    detail,
  });
}

function count(source, needle) {
  return source.split(needle).length - 1;
}

function checkSources(files, label, predicate, detail) {
  const source = files.map((file) => read(file)).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: predicate(source),
    detail,
  });
}

check(
  'route/app.php',
  'public login route is declared once',
  (source) => count(source, "Route::post('api/auth/login', 'Auth/login');") === 1,
  "Route::post('api/auth/login', 'Auth/login');"
);

check(
  'route/app.php',
  'disabled self-registration route is declared once',
  (source) => count(source, "Route::post('api/auth/register', 'Auth/register');") === 1,
  "Route::post('api/auth/register', 'Auth/register');"
);

check(
  'route/app.php',
  'public auth routes are outside protected auth group',
  (source) => {
    const login = source.indexOf("Route::post('api/auth/login', 'Auth/login');");
    const register = source.indexOf("Route::post('api/auth/register', 'Auth/register');");
    const protectedGroup = source.indexOf("Route::group('api/auth'");
    return login !== -1 && register !== -1 && protectedGroup !== -1 && login < protectedGroup && register < protectedGroup;
  },
  'auth route order'
);

check(
  'app/controller/Auth.php',
  'self-registration endpoint is explicitly disabled',
  (source) => source.includes("return $this->error('系统已关闭自助注册，请联系管理员创建账号', 403);"),
  'disabled register response'
);

check(
  'package.json',
  'platform data source contract runs runtime field fact verifier',
  (source) => source.includes('"verify:platform-data-source-contract": "node scripts/verify_platform_data_source_contract.mjs && C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_online_data_field_fact_status.php"')
    && source.includes('"verify:online-data-field-fact-status": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_online_data_field_fact_status.php"'),
  'verify:platform-data-source-contract + verify:online-data-field-fact-status'
);

check(
  'scripts/verify_online_data_field_fact_status.php',
  'runtime verifier covers field fact status behavior',
  (source) => source.includes('legacy_facts_infer_storage_without_hiding_missing')
    && source.includes('source_path_required_for_closure')
    && source.includes('structured_source_path_required_for_ready_status')
    && source.includes('structured_source_path_count')
    && source.includes('meituan_persistence_field_facts_ready')
    && source.includes('meituan_rank_source_path_field_facts_ready')
    && source.includes('capture_evidence_required_for_closure')
    && source.includes('capture_evidence_count')
    && source.includes('generic_traffic_extraction_source_paths_ready')
    && source.includes('stored_value_required_for_ready_status')
    && source.includes('generic_traffic_persistence_structured_fields_ready')
    && source.includes('raw_data.facts.metric_key=custom_fact')
    && source.includes('source_url_hash')
    && source.includes('meituan:generic-traffic-demo')
    && source.includes('raw_data_exposed'),
  'legacy_facts_infer_storage_without_hiding_missing/source_path_required_for_closure/structured_source_path_required_for_ready_status/capture_evidence_required_for_closure/meituan_persistence_field_facts_ready/meituan_rank_source_path_field_facts_ready/generic_traffic_extraction_source_paths_ready/stored_value_required_for_ready_status/generic_traffic_persistence_structured_fields_ready/source_url_hash'
);

check(
  'route/app.php',
  'data-source sync route is ordered before generic create route',
  (source) => {
    const sync = source.indexOf("Route::post('/data-sources/:id/sync', 'OnlineData/syncDataSource');");
    const create = source.indexOf("Route::post('/data-sources', 'OnlineData/saveDataSource');");
    return sync !== -1 && create !== -1 && sync < create;
  },
  '/data-sources/:id/sync before /data-sources'
);

for (const [route, label] of [
  ["Route::get('/data-sources', 'OnlineData/dataSourceList');", 'data-source list route exists'],
  ["Route::delete('/data-sources/:id', 'OnlineData/deleteDataSource');", 'data-source delete route exists'],
  ["Route::post('/data-import', 'OnlineData/importDataSourceRows');", 'data import route exists'],
  ["Route::get('/sync-tasks', 'OnlineData/syncTaskList');", 'sync task list route exists'],
  ["Route::get('/sync-logs', 'OnlineData/syncLogList');", 'sync log list route exists'],
  ["Route::get('/platform-profile-status', 'OnlineData/platformProfileStatus');", 'platform Profile status route exists'],
  ["Route::post('/profile-binding-unbind', 'OnlineData/deletePlatformProfileBinding');", 'platform Profile unbind route exists'],
  ["Route::post('/profile-login-trigger/:platform', 'OnlineData/triggerPlatformProfileLogin');", 'platform Profile login trigger route exists'],
  ["Route::get('/profile-login-status/:platform', 'OnlineData/platformProfileLoginStatus');", 'platform Profile login status route exists'],
  ["Route::get('/meituan-profile-status', 'OnlineData/meituanProfileStatus');", 'Meituan Profile status route exists'],
]) {
  check('route/app.php', label, (source) => source.includes(route), route);
}

for (const [method, label] of [
  ['function dataSourceList', 'controller exposes data-source list'],
  ['function saveDataSource', 'controller exposes data-source save'],
  ['function syncDataSource', 'controller exposes data-source sync'],
  ['function importDataSourceRows', 'controller exposes data import'],
  ['function syncTaskList', 'controller exposes sync task list'],
  ['function syncLogList', 'controller exposes sync log list'],
  ['function platformProfileStatus', 'controller exposes unified platform Profile status'],
  ['function deletePlatformProfileBinding', 'controller exposes platform Profile unbind fallback'],
  ['function triggerPlatformProfileLogin', 'controller exposes local-authorization guard for legacy Profile login trigger'],
  ['function platformProfileLoginStatus', 'controller exposes async platform Profile login status'],
  ['function meituanProfileStatus', 'controller exposes Meituan Profile status probe'],
  ['function bindBrowserProfileDataSource', 'controller binds successful login-only Profile to data source'],
  ['function findBrowserProfileDataSourceForUnbind', 'controller resolves browser Profile binding without frontend data-source id'],
  ['function clearBrowserProfileStatusCacheForSource', 'controller clears stale Profile status when data source is unbound'],
]) {
  checkSources([
    'app/controller/OnlineData.php',
    'app/controller/concern/AutoFetchConcern.php',
    'app/controller/concern/OnlineDataRequestConcern.php',
    'app/controller/concern/PlatformDataSourceConcern.php',
    'app/controller/concern/PlatformProfileCaptureConcern.php',
  ], label, (source) => source.includes(method), method);
}

check(
  'app/controller/concern/OnlineDataRequestConcern.php',
  'legacy platform Profile login trigger blocks server-side browser launch',
  (source) => source.includes('client_local_authorization_required')
    && source.includes('account_owner_local_computer_only')
    && source.includes('server_browser_launch_disabled')
    && source.includes('open_platform_on_account_owner_computer_and_import_browser_assist_json')
    && !source.includes('launchPlatformProfileLoginTask($task)'),
  'client_local_authorization_required + server_browser_launch_disabled'
);

for (const [needle, label] of [
  ['function commentCollectionDisabledResponse', 'controller keeps a legacy disabled response helper for disallowed review-detail flows'],
  ['return $this->captureCtripCommentsBrowserData();', 'Ctrip comment endpoint delegates to aggregate browser capture'],
  ['return $this->captureMeituanBrowserData($requestData);', 'Meituan comment endpoint delegates to aggregate browser capture'],
  ["'capture_sections' => 'comment_review'", 'Ctrip comment capture forces aggregate comment_review section'],
  ["'capture_sections' => 'reviews'", 'Meituan comment capture forces aggregate reviews section'],
  ["saveOtaDataConfigValue('ctrip-comments'", 'Ctrip comment config persists aggregate capture config'],
  ["saveOtaDataConfigValue('meituan-comments'", 'Meituan comment config persists aggregate capture config'],
  ['aggregate_metrics_only_no_review_text', 'comment config documents aggregate-only privacy boundary'],
  ['function sanitizeOnlineOrderRawData', 'controller sanitizes browser-captured order raw data before storage'],
  ['function sanitizeOnlineReviewRawData', 'controller sanitizes browser-captured review raw data before storage'],
  ['function hashOnlineOrderIdentifier', 'controller hashes browser-captured order identifiers'],
  ['order_id_hash', 'controller keeps only hashed order identifiers in captured raw data'],
  ["$item['field_fact_status'] = $this->buildOnlineDataFieldFactStatus", 'controller daily data list exposes field fact status'],
  ['function buildOnlineDataFieldFactStatus', 'controller keeps field fact UI status compatibility wrapper'],
  ['return OnlineDataFieldFactService::buildStatus($row, $raw);', 'controller delegates field fact UI status to service'],
  ['OnlineDataFieldFactService::attachToOnlineDailyRow($row, $item)', 'Meituan browser-captured rows attach field facts before storage'],
  ['OnlineDataFieldFactService::attachToOnlineDailyRow($row)', 'Meituan save path repairs rows that arrive without field facts'],
]) {
  checkSources([
    'app/controller/OnlineData.php',
    'app/controller/concern/OnlineDataSupportConcern.php',
    'app/controller/concern/OnlineDataQualityConcern.php',
    'app/controller/concern/OnlineDataManualFetchConcern.php',
    'app/controller/concern/OnlineDataRequestConcern.php',
    'app/controller/concern/CtripCommentsConcern.php',
    'app/controller/concern/MeituanConfigConcern.php',
    'app/controller/concern/OtaConfigConcern.php',
    'app/controller/concern/MeituanCapturedDataConcern.php',
  ], label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['final class OnlineDataFieldFactService', 'shared online data field fact service exists'],
  ['public static function buildStatus', 'field fact service builds UI field fact status'],
  ['function extractFieldFacts', 'field fact service extracts field facts from raw_data wrappers'],
  ['function inferFieldFactStorageField', 'field fact service infers storage field for legacy raw_data facts'],
  ['function fieldFactStorageFieldSource', 'field fact service marks inferred storage field source explicitly'],
  ['function fieldFactStructuredStorageField', 'field fact service limits inferred storage fields to explicit metric map'],
  ["'storage_field_inferred' => $storageFieldInferred", 'field fact service marks inferred storage fields explicitly'],
  ["'inferred_storage_field_count' => $inferredStorageFieldCount", 'field fact service exposes inferred storage field count'],
  ["'stored_value_present_count' => $storedValuePresentCount", 'field fact service exposes stored value evidence count'],
  ['function fieldFactStoredValueState', 'field fact service resolves stored values from declared storage fields'],
  ["return 'raw_data_facts';", 'field fact service distinguishes raw_data facts storage from structured field mapping'],
  ["'online_daily_data.raw_data.facts.metric_key=' . $metricKey", 'field fact service can point legacy Ctrip facts to raw_data fact storage'],
  ["'raw_data_exposed' => false", 'field fact service status does not expose raw data'],
  ["'metric_key' => 'list_exposure'", 'field fact service maps Meituan list exposure'],
  ['orderVisitors', 'field fact service covers order filling traffic aliases used by Ctrip extraction'],
  ['clickNum', 'field fact service covers click traffic aliases used by Ctrip and Meituan extraction'],
  ['submitNum', 'field fact service covers order submission traffic aliases used by Ctrip extraction'],
  ['bookOrderNum', 'field fact service covers booking-order traffic aliases used by Ctrip extraction'],
  ['listTransforDetailRate', 'field fact service covers traffic conversion rate aliases used by Meituan extraction'],
  ["'storage_field' => 'online_daily_data.'", 'field fact service emits online_daily_data storage targets'],
  ["'source_path' => self::sourcePath", 'field fact service emits source paths'],
  ['source_trace_id', 'field fact service preserves desensitized source trace ids'],
  ["'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash']", 'field fact service preserves desensitized source URL hash aliases'],
  ['payload_hash', 'field fact service preserves desensitized payload hashes'],
  ['desensitized_capture_evidence_count', 'field fact service summarizes P0-grade desensitized evidence'],
  ['hasDesensitizedCaptureEvidence', 'field fact service requires trace and source hash for strict evidence'],
]) {
  check('app/service/OnlineDataFieldFactService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ["$item['_source_path'] = 'data.peerRankData.'", 'Meituan peer-rank extraction keeps row source paths'],
  ['private static function withSourcePaths', 'Meituan generic rank lists keep row source paths'],
]) {
  check('app/service/MeituanRankDataExtractionService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ["$rawData['_source_path'] = $sourcePath;", 'Meituan rank persistence writes source path into raw_data'],
  ["$rankDataType = 'peer_rank';", 'Meituan rank persistence stores peerRankData as peer_rank, not business metrics'],
  ['OnlineDataFieldFactService::attachToOnlineDailyRow($data, $item)', 'Meituan rank persistence attaches field facts before storage'],
]) {
  check('app/service/MeituanOnlineDataPersistenceService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['OnlineDataFieldFactService::attachToOnlineDailyRow($data, $item)', 'traffic persistence attaches field facts before storage'],
  ['traffic_data_persistence_failed', 'traffic persistence exposes storage failures instead of returning zero rows'],
  ['extractGenericTrafficMetrics', 'traffic persistence writes normalized traffic fields'],
  ["'list_exposure' => $trafficMetrics['list_exposure']", 'traffic persistence stores normalized list exposure'],
  ["'flow_rate' => $trafficMetrics['flow_rate']", 'traffic persistence stores normalized conversion rate'],
  ['attachListSourcePaths', 'traffic persistence preserves source paths for direct list responses'],
  ["['data', 'flowData']", 'traffic persistence handles Meituan flowData source path'],
]) {
  check('app/service/OnlineDailyDataPersistenceService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['extractGenericTrafficRows($data, array $path = [])', 'generic traffic extraction tracks source path state'],
  ['self::withSourcePath($value, $itemPath)', 'generic traffic extraction writes row source paths'],
  ['self::withSourcePath($item, $itemPath)', 'Ctrip traffic extraction writes row source paths'],
  ['private static function sourcePathString', 'traffic extraction formats source paths'],
]) {
  check('app/service/OnlineTrafficDataExtractionService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ["'module' => 'comments'", 'controller auto-fetch planner does not enqueue comment modules'],
  ["extractMeituanCapturedSection($payload, 'reviews')", 'Meituan browser payload saver does not normalize reviews'],
]) {
  check('app/controller/OnlineData.php', label, (source) => !source.includes(needle), needle);
}

for (const [needle, label] of [
  ['data-testid="platform-data-sources-panel"', 'frontend exposes platform data-source panel'],
  ["request('/online-data/data-sources'", 'frontend can list data sources'],
  ["request('/online-data/data-import'", 'frontend can import rows'],
  ["`/online-data/data-sources/${source.id}/sync`", 'frontend can trigger immediate sync'],
  ['platformSyncLogs', 'frontend renders sync logs'],
  ['binding_contract', 'frontend renders machine-readable platform Profile binding contract'],
  ['manual_login_state_verified=', 'frontend shows manual login verification state in Profile card'],
  ['item.binding_checks || item.checks', 'frontend renders backend binding checks'],
  ['bindingContract.manual_login_state_verified === true', 'frontend flow requires explicit manual login verification when contract exists'],
  ["requireAutoFetchStatic('normalizeDataConfigForForm')", 'frontend reads data-config normalizer from auto-fetch static module'],
  ["requireAutoFetchStatic('buildDataConfigRequestBody')", 'frontend reads data-config request builder from auto-fetch static module'],
]) {
  check('public/index.html', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['[$otaHotelId] = self::otaStoreIdFromConfig($platform, $config);', 'Profile binding checks resolve Ctrip OTA store ID through the contract helper'],
  ['[$profileId] = self::profileIdFromConfig($config, $profileKey);', 'Profile binding checks resolve Profile ID through the contract helper'],
  ["if ($profileId !== '' && $otaHotelId !== '')", 'Profile binding checks require Ctrip OTA store ID and Profile ID together'],
  ["} elseif (!$partnerConfigured) {", 'Profile binding checks do not require Meituan Partner ID for P0 Profile identity'],
  ['Partner ID 仅影响 Cookie/API 快速路径', 'Profile binding checks keep Meituan Partner ID scoped to Cookie/API fast path'],
]) {
  check('app/service/PlatformProfileBindingReadinessService.php', label, (source) => source.includes(needle), needle);
}

check(
  'app/service/PlatformProfileBindingReadinessService.php',
  'Profile binding checks do not accept Profile ID or OTA store ID alone for Ctrip identity',
  (source) => !source.includes("if ($explicitProfileId !== '' || $otaHotelId !== '')"),
  "if ($explicitProfileId !== '' || $otaHotelId !== '')"
);

for (const [needle, label] of [
  ['manual_login_state_verified', 'frontend traffic readiness keeps manual login verification visible'],
  ['人工确认登录态', 'frontend traffic readiness labels manual login verification for operators'],
]) {
  check('public/data-health-static.js', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['const normalizeDataConfigForForm', 'auto-fetch static module owns data-config form normalization'],
  ['const buildDataConfigRequestBody', 'auto-fetch static module owns data-config request body mapping'],
  ['case \'ctrip-cookie-api\'', 'data-config request builder preserves Ctrip Cookie API payload mapping'],
  ['case \'meituan-ads\'', 'data-config request builder preserves Meituan ads payload mapping'],
]) {
  check('public/auto-fetch-static.js', label, (source) => source.includes(needle), needle);
}

check(
  'public/index.html',
  'frontend does not generate fake randomized business signals',
  (source) => !source.includes('Math.random'),
  'Math.random'
);

for (const [needle, label] of [
  ['function parseImportFile', 'service parses uploaded import files'],
  ['strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION))', 'import parser validates by file extension'],
  ['filesize($path) > 5 * 1024 * 1024', 'import parser enforces upload size limit'],
  ["'csv' => $this->parseCsvImportFile($path)", 'import parser supports CSV'],
  ["'json' => $this->parseJsonImportFile($path)", 'import parser supports JSON'],
  ["'xlsx' => $this->parseXlsxImportFile($path)", 'import parser supports XLSX'],
  ["unset($row['config_json']);", 'data-source list removes raw config JSON'],
  ["unset($row['secret_json']);", 'data-source list removes raw secret JSON'],
  ["unset($data['secret_json']);", 'data-source update preserves existing secret when blank'],
  ["'cookies_preview'", 'data-source list only exposes cookie preview'],
  ['function isReviewCollectionAllowed', 'platform sync gates review payloads explicitly'],
  ['function payloadRequestsReviewDetailStorage', 'platform sync only requires explicit authorization for review-detail storage'],
  ['review_detail_collection', 'platform sync recognizes explicit review-detail storage requests'],
  ['function sanitizePayloadForStorage', 'platform sync sanitizes raw payload before storage'],
  ['function sanitizeReviewPayloadForStorage', 'platform sync sanitizes review payloads before storage'],
  ['order_id_hash', 'platform sync hashes order identifiers'],
  ['function maskName', 'platform sync masks guest names'],
  ['function maskPhone', 'platform sync masks phone numbers'],
  ['checkoutRevenue', 'platform sync maps Ctrip business revenue fields'],
  ['todayCost', 'platform sync maps advertising cost fields'],
  ['orderList', 'platform sync extracts order list envelopes'],
  ['campaignList', 'platform sync extracts campaign envelopes'],
  ['CtripBrowserProfileDataSourceAdapter', 'platform sync registers Ctrip browser Profile adapter'],
  ['MeituanBrowserProfileDataSourceAdapter', 'platform sync registers Meituan browser Profile adapter'],
  ['function assertBrowserProfileBackgroundSyncLoginVerified', 'platform sync gates background Profile capture by verified manual login state'],
  ['function browserProfileBackgroundSyncLoginMissingRequirements', 'platform sync reports missing Profile login gate requirements'],
  ["'compare_type' => $this->stringValue($row, ['compare_type', 'compareType', 'rank_type', 'rankType'])", 'platform sync maps Meituan rank_type into compare_type for peer-rank field facts'],
  ["$missing[] = 'manual_login_state_verified';", 'platform sync requires explicit manual login verification before background Profile capture'],
  ["$missing[] = 'profile_status_logged_in';", 'platform sync requires logged-in Profile status before background Profile capture'],
  ["$missing[] = 'last_login_verified_at';", 'platform sync requires last login verification time before background Profile capture'],
  ['browser_profile background sync requires manual_login_state_verified', 'platform sync exposes explicit Profile login gate failure'],
  ['function refreshDatabaseConnectionAfterExternalFetch', 'platform sync refreshes DB connection after long external capture'],
  ['$this->refreshDatabaseConnectionAfterExternalFetch();', 'platform sync calls DB refresh before post-capture writes'],
  ['function resolveDataPeriodMetadata', 'platform sync classifies historical and realtime rows'],
  ["'data_period' => $periodMeta['data_period']", 'platform sync writes data period metadata to normalized rows'],
  ["'snapshot_bucket' => $periodMeta['snapshot_bucket']", 'platform sync writes realtime snapshot bucket'],
  ['capture_elapsed_ms', 'platform sync records capture elapsed time'],
  ['raw_store_elapsed_ms', 'platform sync records raw-store elapsed time'],
  ['normalize_elapsed_ms', 'platform sync records normalization elapsed time'],
  ['daily_rows_save_elapsed_ms', 'platform sync records daily-row save elapsed time'],
  ['finish_task_elapsed_ms', 'platform sync records task-result write elapsed time'],
  ['total_elapsed_ms', 'platform sync records total elapsed time'],
  ['NORMALIZED_FIELD_FACT_DEFINITIONS', 'platform sync defines normalized field fact contracts'],
  ['function buildNormalizedFieldFacts', 'platform sync builds field facts for normalized rows'],
  ["$raw['field_facts'] = $fieldFacts;", 'platform sync stores field facts in raw_data'],
  ["$raw['field_fact_summary'] = $this->summarizeNormalizedFieldFacts($fieldFacts);", 'platform sync stores field fact summary in raw_data'],
  ["'metric_key' => (string)$definition['metric_key']", 'platform sync field facts keep metric keys'],
  ["'source_path' => $sourceKey !== '' ? $this->fieldFactSourcePath($row, $sourceKey) : ''", 'platform sync field facts keep source paths'],
  ["'storage_field' => (string)$definition['storage_field']", 'platform sync field facts keep storage fields'],
  ["'status' => $status", 'platform sync field facts keep captured or missing status'],
  ["'missing_state' => (string)$definition['missing_state']", 'platform sync field facts keep explicit missing states'],
  ['appendSafeFieldFactCaptureEvidence', 'platform sync preserves desensitized field fact capture evidence'],
  ['safeFieldFactCaptureEvidenceValue', 'platform sync filters sensitive evidence strings'],
  ["'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash']", 'platform sync accepts source URL hash aliases from capture rows'],
  ["'payload_hash' => ['payload_hash', '_payload_hash']", 'platform sync accepts payload hashes from capture rows'],
  ["'profile_id'", 'platform sync accepts Ctrip browser Profile config fields'],
  ["'store_id'", 'platform sync accepts Meituan browser Profile config fields'],
  ["'source_trace_id' => $traceId", 'platform sync preserves browser Profile trace ids'],
  ['desensitized_capture_evidence_count', 'platform sync summarizes P0-grade desensitized evidence'],
  ['fieldFactHasDesensitizedCaptureEvidence', 'platform sync requires trace and source hash for strict evidence'],
]) {
  check('app/service/PlatformDataSyncService.php', label, (source) => source.includes(needle), needle);
}

check(
  'app/service/PlatformDataSyncService.php',
  'platform sync verifies background Profile login state before adapter fetch',
  (source) => {
    const gate = source.indexOf('$this->assertBrowserProfileBackgroundSyncLoginVerified($source, $options);');
    const fetch = source.indexOf('$result = $adapter->fetch($source, $options);');
    return gate !== -1 && fetch !== -1 && gate < fetch;
  },
  'assertBrowserProfileBackgroundSyncLoginVerified before adapter->fetch'
);

for (const [needle, label] of [
  ['function assertProfileCookieSourceLoginVerified', 'Profile-derived Cookie extraction has a verified-login gate'],
  ['function profileCookieSourceLoginMissingRequirements', 'Profile-derived Cookie extraction reports missing login verification requirements'],
  ["$missing[] = 'manual_login_state_verified';", 'Profile-derived Cookie extraction requires manual login verification'],
  ["$missing[] = 'profile_status_logged_in';", 'Profile-derived Cookie extraction requires logged-in Profile status'],
  ["$missing[] = 'last_login_verified_at';", 'Profile-derived Cookie extraction requires last login verification time'],
]) {
  check('app/controller/concern/PlatformProfileCaptureConcern.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['$this->profileCookieSourceLoginVerified($cookieApiSourceConfig)', 'Ctrip Cookie/API task planning only uses verified Profile Cookie sources'],
  ['$this->profileCookieSourceLoginVerified($meituanConfig)', 'Meituan ranking task planning only uses verified Profile Cookie sources'],
  ['$this->profileCookieSourceLoginVerified($meituanTrafficSourceConfig)', 'Meituan traffic task planning only uses verified Profile Cookie sources'],
  ["'profile_cookie_missing_requirements' => $profileCookieMissing", 'Meituan Cookie/API readiness exposes Profile Cookie verification gaps'],
]) {
  check('app/controller/concern/AutoFetchConcern.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['export function buildOtaCaptureEvidence', 'OTA capture helper builds desensitized evidence'],
  ['export function attachOtaCaptureEvidence', 'OTA capture helper attaches evidence to rows'],
  ['source_url_hash', 'OTA capture helper emits URL hashes instead of raw URL evidence'],
  ['source_trace_id', 'OTA capture helper emits trace ids'],
  ['delete evidence.source_url;', 'OTA capture helper removes raw source URLs from nested evidence'],
  ['delete evidence.url;', 'OTA capture helper removes raw URL aliases from nested evidence'],
  ['delete next._source_url;', 'OTA capture helper removes raw source URLs from row output'],
  ['delete next.url;', 'OTA capture helper removes raw URL aliases from row output'],
]) {
  check('scripts/lib/ota_capture_standard.mjs', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['CARD_METRIC_ID_ALIASES', 'Meituan traffic card normalization supports stable metric id aliases'],
  ['CARD_METRIC_TITLE_RULES', 'Meituan traffic card normalization supports title-based metric aliases'],
  ['function resolveCardMetricConfig', 'Meituan traffic card normalization resolves metric config from id or title'],
  ['function cardMetricValue', 'Meituan traffic card normalization reads non-value display fields'],
  ['valueText', 'Meituan traffic card normalization accepts valueText cards'],
  ['displayValue', 'Meituan traffic card normalization accepts displayValue cards'],
  ['dataValue', 'Meituan traffic card normalization accepts dataValue cards'],
  ['currentValue', 'Meituan traffic card normalization accepts currentValue cards'],
]) {
  check('scripts/lib/meituan_browser_capture_normalize.mjs', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['online_data_historical_executed_', 'cron command dedupes historical runs by business date'],
  ['online_data_realtime_executed_', 'cron command dedupes realtime runs by scheduled hour'],
  ['realtime_schedule_interval_hours', 'cron command supports realtime interval hours'],
  ['isRealtimeScheduleHourDue', 'cron command gates realtime runs by configured interval'],
  ['online_data_profile_lock_', 'cron command serializes tasks per Profile'],
  ['skipped_locked', 'cron command reports Profile lock skips explicitly'],
  ["'data_period' => $dataPeriod", 'cron command passes data period into Profile sync'],
  ["'snapshot_time' => $snapshotTime", 'cron command passes snapshot time into Profile sync'],
  ['ctrip_section_concurrency', 'cron command passes Ctrip section concurrency into Profile sync'],
]) {
  check('app/command/AutoFetchOnlineData.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ["'browser_profile'", 'Ctrip browser Profile adapter supports browser_profile ingestion method'],
  ["'ctrip_profile_'", 'Ctrip browser Profile adapter checks local Profile directory'],
  ['ctrip_browser_capture.mjs', 'Ctrip browser Profile adapter reuses existing browser capture script'],
  ['auth_status', 'Ctrip browser Profile adapter exposes login state failures'],
  ['capture_gate', 'Ctrip browser Profile adapter exposes capture gate failures'],
  ['--section-concurrency=', 'Ctrip browser Profile adapter passes internal section concurrency'],
  ['parallel_capture_fallback', 'Ctrip browser Profile adapter keeps sequential fallback diagnostics'],
  ["'acquisition_method' => 'browser_profile'", 'Ctrip browser Profile adapter labels acquisition method'],
]) {
  check('app/service/platform/CtripBrowserProfileDataSourceAdapter.php', label, (source) => source.includes(needle), needle);
}

check(
  'app/service/platform/CtripBrowserProfileDataSourceAdapter.php',
  'Ctrip browser Profile adapter does not use Profile ID as platform hotel ID fallback',
  (source) => source.includes("['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId']")
    && source.includes("$args[] = '--hotel-id=' . $hotelId;")
    && source.includes('$rows = $this->buildRows($payload, $source, $systemHotelId, $dataDate, $hotelId);')
    && source.includes("$row['hotel_id'] = $row['hotel_id'] ?? $row['hotelId'] ?? $platformHotelId;")
    && !source.includes('$hotelId !== \'\' ? $hotelId : $profileId')
    && !source.includes('$fallbackHotelId'),
  'Ctrip Profile ID cannot replace OTA platform hotel identity'
);

checkSources(
  [
    'app/controller/OnlineData.php',
    'app/controller/concern/AutoFetchConcern.php',
    'app/controller/concern/OnlineDataRequestConcern.php',
  ],
  'Ctrip browser Profile save paths do not use Profile ID as request hotel ID fallback',
  (source) => source.includes("$requestHotelId = $hotelId !== '' ? $hotelId : (string)($payload['hotel_id'] ?? '');")
    && source.includes("$requestHotelId = $ctripHotelId !== '' ? $ctripHotelId : (string)($payload['hotel_id'] ?? '');")
    && !source.includes("(string)($payload['hotel_id'] ?? $profileId)"),
  'requestHotelId must come from platform hotel evidence, not Profile ID'
);

checkSources(
  [
    'app/controller/OnlineData.php',
    'app/controller/concern/CtripAdsConcern.php',
  ],
  'Ctrip captured advertising rows keep Profile ID out of platform hotel ID and raw context',
  (source) => source.includes("['hotel_id', 'hotelId', 'masterHotelId', 'master_hotel_id', 'hotelID', 'ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id', 'otaHotelId', 'node_id', 'nodeId']")
    && source.includes("'hotel_id' => $context['hotel_id'] ?? ''")
    && !source.includes("['hotel_id', 'hotelId', 'profile_id', 'profileId']")
    && !source.includes("'profile_id' => $context['hotel_id'] ?? ''"),
  'Ctrip advertising rows cannot use Profile ID as OTA hotel identity'
);

for (const [needle, label] of [
  ["'browser_profile'", 'Meituan browser Profile adapter supports browser_profile ingestion method'],
  ["'meituan_profile_'", 'Meituan browser Profile adapter checks local Profile directory'],
  ['meituan_browser_capture.mjs', 'Meituan browser Profile adapter reuses existing browser capture script'],
  ['auth_status', 'Meituan browser Profile adapter exposes login state failures'],
  ['capture_gate', 'Meituan browser Profile adapter exposes capture gate failures'],
  ["'--data-date=' . $dataDate", 'Meituan browser Profile adapter passes target date to capture script'],
  ["'acquisition_method' => 'browser_profile'", 'Meituan browser Profile adapter labels acquisition method'],
]) {
  check('app/service/platform/MeituanBrowserProfileDataSourceAdapter.php', label, (source) => source.includes(needle), needle);
}

check(
  'app/service/platform/MeituanBrowserProfileDataSourceAdapter.php',
  'Meituan browser Profile adapter does not use Profile ID as platform store/poi ID fallback',
  (source) => source.includes("['store_id', 'storeId', 'poi_id', 'poiId']")
    && source.includes("$rows = $this->buildRows($payload, $source, $systemHotelId, $dataDate, $poiId !== '' ? $poiId : $storeId);")
    && source.includes("private function buildRows(array $payload, array $source, int $systemHotelId, string $dataDate, string $platformHotelId): array")
    && source.includes("$row['hotel_id'] = $this->firstRowString($row, ['hotel_id', 'hotelId', 'poi_id', 'poiId', 'store_id', 'storeId'], $platformHotelId);")
    && !source.includes("['store_id', 'storeId', 'profile_id', 'profileId', 'poi_id', 'poiId']")
    && !source.includes('$fallbackHotelId'),
  'Meituan Profile ID cannot replace OTA platform store/poi identity'
);

for (const [needle, label] of [
  ['value="browser_profile"', 'frontend data-source form exposes Ctrip browser Profile method'],
  ['platformDataSourceConfigPlaceholder', 'frontend shows Ctrip browser Profile config example'],
  ['platformDataSourceSecretPlaceholder', 'frontend keeps optional cookies in secret config'],
  ['platformProfileStatus', 'frontend renders platform Profile account status'],
  ['clientLocalAuthorizationRequired', 'frontend labels account-owner local authorization requirement'],
  ['defaultMeituanLoginUrl', 'frontend opens Meituan eBooking entry in the current client browser'],
  ['openTargetSite(localPlatformAuthorizationUrl(platform))', 'frontend opens platform authorization on the current client computer'],
  ['server_browser_launch_disabled', 'frontend records that server-side browser launch is disabled'],
  ['account_owner_local_computer_only', 'frontend records the account-owner local computer authorization policy'],
  ['profile-login-status', 'frontend polls async platform Profile login status'],
  ['platformProfileLoginTasks', 'frontend tracks async platform Profile login tasks'],
  ['triggerPlatformProfileLogin', 'frontend reuses Profile login action as local authorization guide'],
  ['loginPlatformProfile', 'frontend can start first-login Profile binding flow'],
  ['probePlatformProfileStatus', 'frontend can probe Profile login status'],
  ['deletePlatformProfileBinding', 'frontend can unbind a wrong platform Profile from the selected hotel'],
  ['profile-binding-unbind', 'frontend calls Profile unbind fallback endpoint'],
  ['解绑', 'frontend exposes visible Profile unbind action on the status card'],
  ['ctrip_auto_fetch_mode', 'frontend sends Ctrip-specific auto-fetch mode'],
  ['meituan_auto_fetch_mode', 'frontend keeps Meituan auto-fetch mode separate'],
  ['historical_schedule_time', 'frontend sends historical fixed-data schedule time'],
  ['realtime_schedule_minute', 'frontend sends realtime schedule minute'],
  ['realtime_schedule_interval_hours', 'frontend sends realtime interval hours'],
  ['autoFetchCtripSectionConcurrency', 'frontend exposes Ctrip internal section concurrency'],
  ['ctrip_section_concurrency', 'frontend sends Ctrip section concurrency'],
  ["data_period: 'historical_daily'", 'frontend backfill requests historical fixed data'],
  ['autoFetchTimingRows', 'frontend renders auto-fetch timing breakdown'],
]) {
  check('public/index.html', label, (source) => source.includes(needle), needle);
}

check(
  'public/index.html',
  'frontend does not call legacy server-side platform Profile login trigger',
  (source) => !source.includes('`/online-data/profile-login-trigger/${platform}`')
    && !source.includes("'/online-data/profile-login-trigger/")
    && !source.includes('"/online-data/profile-login-trigger/'),
  'no frontend profile-login-trigger request'
);

check(
  'public/auto-fetch-static.js',
  'frontend manual trigger requests realtime snapshot data',
  (source) => source.includes("data_period: 'realtime_snapshot'"),
  "data_period: 'realtime_snapshot'"
);

check(
  'public/index.html',
  'frontend analysis panel does not request all online_daily_data rows',
  (source) => !source.includes("page_size: 'all'") && source.includes('page_size: String(onlineAnalysisPageSize)'),
  "no page_size: 'all' and uses onlineAnalysisPageSize"
);

check(
  'public/index.html',
  'frontend login startup defers non-home admin and cookie requests',
  (source) => {
    const loadData = source.match(/const loadData = async \(\) => \{[\s\S]*?\n            \};/);
    const loadHotels = source.match(/const loadHotels = async \(options = \{\}\) => \{[\s\S]*?\n            \};/);
    if (!loadData) return false;
    return loadData[0].includes('scheduleStartupHotelListLoad();')
      && source.includes('const loadSystemConfig = async (options = {}) => {')
      && source.includes("publicOnly ? '/system-config?scope=public' : '/system-config'")
      && loadData[0].includes('schedulePublicSystemConfigRefresh(1800);')
      && !loadData[0].includes('loadRoles();')
      && !loadData[0].includes('loadUsers();')
      && !loadData[0].includes('loadRolesList();')
      && !loadData[0].includes('loadCookiesList();')
      && !loadData[0].includes('loadBookmarklet();')
      && source.includes("if (newPage === 'users')")
      && source.includes("if (newPage === 'roles')")
      && source.includes('loadBookmarklet()')
      && loadHotels
      && source.includes('const HOTEL_LIST_CACHE_TTL_MS = 30000;')
      && source.includes('const scheduleStartupHotelListLoad = (delayMs = null) => {')
      && source.includes('if (!hasKnownHotelOptions()) {')
      && source.includes('if (!isLoggedIn.value || !token.value || isCoreOtaPageVisible()) return null;')
      && loadHotels[0].includes('const cacheMs = Number(options.cacheMs || 0);')
      && loadHotels[0].includes('readRequestCache(hotelListResultCache, requestKey, cacheMs)')
      && loadHotels[0].includes("options.includeInactive === true || currentPage.value === 'hotels'")
      && loadHotels[0].includes("user.value?.is_super_admin && includeInactive ? '/hotels?page=1&page_size=1000' : '/hotels/all'")
      && source.includes('loadHotels({ includeInactive: true })');
  },
  'loadData defers system config, roles/users/cookies/bookmarklet and schedules hotels full list'
);

check(
  'app/controller/Hotel.php',
  'lightweight hotel option list carries status for dashboard counts',
  (source) => source.includes("$fields = 'id, name, code, status';")
    && source.includes('->field($fields)'),
  "$fields = 'id, name, code, status'; ... ->field($fields)"
);

check(
  'public/index.html',
  'frontend analysis detail wording does not imply a 20-row cap',
  (source) => !source.includes('展示最近 {{ onlineAnalysisRows.length }} 条'),
  '展示最近 {{ onlineAnalysisRows.length }} 条'
);

checkSources([
  'app/controller/OnlineData.php',
  'app/controller/concern/OnlineDataQualityConcern.php',
],
  'daily data list marks legacy all requests as limited compatibility',
  (source) => source.includes("['all', '全部']") && source.includes("'all_requested' => $fetchAllRequested"),
  "['all', '全部'] and all_requested"
);

for (const [needle, label] of [
  ["'online-data:profile-login' => 'app\\command\\PlatformProfileLogin'", 'console registers async platform Profile login command'],
]) {
  check('config/console.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['--login-only=true', 'async login command runs browser capture in login-only mode'],
  ['--headless=false', 'async login command always opens a visible browser for manual verification'],
  ['--post-login-wait-ms=', 'async login command keeps the visible login browser open after auth checks'],
  ['?? 120000', 'async login command defaults to a visible two-minute manual verification window'],
  ['platform_profile_login_task_', 'async login command writes task status cache'],
  ['current_key', 'async login command updates current task cache for polling'],
  ['PlatformDataSyncService', 'async login command binds successful Profile to platform data source'],
  ["'capture_sections' => $this->safeSections", 'async login command normalizes array capture sections before binding'],
]) {
  check('app/command/PlatformProfileLogin.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['function commentFact', 'standard ETL builds aggregate comment/review facts'],
  ["'fact_ota_advertising' => $advertisingFacts", 'standard ETL separates advertising facts from daily revenue'],
  ["'fact_ota_quality' => $qualityFacts", 'standard ETL separates quality facts from daily revenue'],
  ["'fact_ota_comment' => $commentFacts", 'standard ETL exposes aggregate comment facts'],
]) {
  check('app/service/OtaStandardEtlService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['function advertisingSummary', 'revenue metrics expose advertising summary'],
  ['function qualitySummary', 'revenue metrics expose quality summary'],
  ["'advertising' => $this->advertisingSummary($advertising)", 'revenue metrics return advertising section'],
  ["'quality' => $this->qualitySummary($quality)", 'revenue metrics return quality section'],
]) {
  check('app/service/OtaRevenueMetricService.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ["allowedSections: ['traffic', 'ads', 'orders', 'reviews']", 'Meituan browser capture allows aggregate review section'],
  ["comment_review: 'reviews'", 'Ctrip browser capture maps comment_review alias to reviews'],
  ['comment-manage', 'browser capture classifies Meituan comment-manage responses as aggregate reviews'],
  ['function sanitizeOtaPayloadForStorage', 'browser capture exposes reusable payload sanitizer'],
  ['order_id_hash', 'browser capture hashes order identifiers before writing output'],
  ['function sanitizeReviewPayloadNode', 'browser capture removes review detail text before writing output'],
  ['querycampaignsummaryreport', 'browser capture classifies Ctrip campaign report responses'],
]) {
  check('scripts/lib/ota_capture_standard.mjs', label, (source) => source.includes(needle), needle);
}

check(
  'scripts/ctrip_browser_capture.mjs',
  'Ctrip browser capture can collect aggregate comment_review endpoints',
  (source) => source.includes('normalizeCtripCaptureSections')
    && source.includes("endpoint?.section === 'comment_review'")
    && source.includes('xhr:getCommentList'),
  'normalizeCtripCaptureSections with comment_review sanitizer and getCommentList extraction'
);

for (const [needle, label] of [
  ['captureSectionsWithConcurrency', 'Ctrip browser capture can run sections with bounded page concurrency'],
  ['sectionHasUsableData', 'Ctrip browser capture detects empty parallel section results'],
  ['parallel_failed_sections', 'Ctrip browser capture records parallel section gaps'],
  ['fallback_sections', 'Ctrip browser capture records sequential fallback sections'],
  ['normalizeSectionConcurrency', 'Ctrip browser capture clamps section concurrency'],
  ['compactCaptureOutputPayload', 'Ctrip browser capture compacts response bodies in daily output'],
  ['includeResponseDataInOutput', 'Ctrip browser capture requires an explicit flag for full response bodies'],
]) {
  check('scripts/ctrip_browser_capture.mjs', label, (source) => source.includes(needle), needle);
}

check(
  'scripts/meituan_browser_capture.mjs',
  'Meituan browser capture has login status and capture gate before writing output',
  (source) => source.includes('auth_status')
    && source.includes('capture_gate')
    && source.includes('login_required')
    && source.includes('evaluateCaptureGate')
    && source.includes('sanitizeOtaPayloadForStorage(body, section)')
    && source.includes("if (section === 'orders')"),
  'auth_status/capture_gate/evaluateCaptureGate'
);

check(
  'scripts/meituan_browser_capture.mjs',
  'Meituan browser capture preserves row source paths for field facts',
  (source) => source.includes('function decorateCapturedRow')
    && source.includes('function joinSourcePath')
    && source.includes('_source_path')
    && source.includes('normalizeCapturedList(nested, section, joinSourcePath(sourcePath, path), requestDateEvidence)')
    && source.includes('row._capture_source || `dom:${sectionName}`')
    && source.includes('attachOtaCaptureEvidence(row, \'meituan\'')
    && source.includes('buildOtaCaptureEvidence(\'meituan\'')
    && source.includes('source_trace_id')
    && source.includes('date_source: \'row\'')
    && source.includes('capture_context.default_data_date')
    && source.includes('request_date_source')
    && source.includes('url_hash'),
  'decorateCapturedRow/_source_path/joinSourcePath/attachOtaCaptureEvidence'
);

check(
  'scripts/ctrip_browser_capture.mjs',
  'Ctrip browser capture attaches desensitized evidence to captured rows',
  (source) => source.includes('attachCtripCaptureEvidence')
    && source.includes('attachOtaCaptureEvidence(row, \'ctrip\'')
    && source.includes('buildOtaCaptureEvidence(\'ctrip\'')
    && source.includes('source_trace_id')
    && source.includes('url_hash')
    && source.includes('annotateCtripStandardRowDateSource')
    && source.includes('capture_context.default_data_date')
    && source.includes('row.source_trace_id || row.source_url_hash'),
  'attachCtripCaptureEvidence/buildOtaCaptureEvidence/source_trace_id/url_hash'
);

check(
  'scripts/ctrip_browser_capture.mjs',
  'Ctrip browser capture keeps Profile ID separate from OTA platform hotel ID',
  (source) => source.includes('profile_id: profileId')
    && source.includes('hotel_id: hotelId')
    && source.includes('hotelId,')
    && !source.includes('hotelId || profileId')
    && !source.includes('hotel_id: hotelId || profileId')
    && !source.includes('ctripPlatformHotelId(row, hotelId || profileId'),
  'profile_id is not accepted as hotel_id/platform hotel identity'
);

check(
  'scripts/ctrip_cookie_api_capture.mjs',
  'Ctrip Cookie/API capture keeps Profile ID separate from OTA platform hotel ID',
  (source) => source.includes('profile_id: profileId')
    && source.includes('hotel_id: hotelId')
    && source.includes('config.ctrip_hotel_id || config.ctripHotelId')
    && source.includes('config.ota_hotel_id || config.otaHotelId')
    && !source.includes('config.profile_id || options.hotelId')
    && !source.includes('hotelId || profileId')
    && !source.includes('hotel_id: hotelId || profileId'),
  'Cookie/API capture cannot use profile_id as hotel_id/platform hotel identity'
);

check(
  'scripts/ctrip_quick_endpoint_probe.mjs',
  'Ctrip quick endpoint probe keeps Profile ID separate from OTA platform hotel ID',
  (source) => source.includes('profile_id: profileId')
    && source.includes('hotel_id: hotelId')
    && source.includes('args.hotelId || args.ctripHotelId || args.otaHotelId || args.masterHotelId')
    && !source.includes('args.hotelId || profileId')
    && !source.includes('hotelId || profileId')
    && !source.includes('hotel_id: hotelId || profileId'),
  'Quick endpoint probe cannot use profile_id as hotel_id/platform hotel identity'
);

check(
  'scripts/lib/ctrip_capture_catalog.mjs',
  'Ctrip catalog standard rows preserve storage fields in raw facts',
  (source) => source.includes('function ctripStandardFactStorage')
    && source.includes('function ctripStandardStructuredStorageField')
    && source.includes('storage_field_source')
    && source.includes('online_daily_data.raw_data.facts.metric_key='),
  'ctripStandardFactStorage/storage_field_source'
);

checkSources(
  [
    'public/index.html',
    'public/data-health-static.js',
  ],
  'frontend field-quality evidence points to raw_data field facts and storage mapping',
  (source) => source.includes('raw_data.field_facts')
    && source.includes('source_path')
    && source.includes('metric_key')
    && source.includes('storage_field'),
  'raw_data.field_facts/source_path/metric_key/storage_field'
);

checkSources(
  [
    'public/index.html',
    'public/data-health-static.js',
  ],
  'frontend analysis rows render field fact status without raw payload',
  (source) => source.includes('onlineAnalysisFieldFactStatusText(item)')
    && source.includes('onlineAnalysisFieldFactStatusClass(item)')
    && source.includes('onlineAnalysisFieldFactDetailText(item)')
    && source.includes('onlineAnalysisP0CaptureEvidenceStatusText(item)')
    && source.includes('onlineAnalysisP0CaptureEvidenceStatusClass(item)')
    && source.includes('onlineAnalysisP0CaptureEvidenceDetailText(item)')
    && source.includes('field_fact_status')
    && source.includes('desensitized_capture_evidence_count')
    && source.includes('stored_value_present_count')
    && source.includes('stored_value_missing_count')
    && source.includes('not_loaded'),
  'onlineAnalysisFieldFactStatusText/Class/Detail'
);

for (const [needle, label] of [
  ['CREATE TABLE IF NOT EXISTS `platform_data_sources`', 'migration creates data sources table'],
  ['CREATE TABLE IF NOT EXISTS `platform_data_sync_tasks`', 'migration creates sync tasks table'],
  ['CREATE TABLE IF NOT EXISTS `platform_data_raw_records`', 'migration creates raw records table'],
  ['CREATE TABLE IF NOT EXISTS `platform_data_sync_logs`', 'migration creates sync logs table'],
  ['ALTER TABLE `online_daily_data`', 'migration extends normalized daily data table'],
  ['ADD COLUMN IF NOT EXISTS `data_source_id`', 'normalized data keeps source id'],
  ['ADD COLUMN IF NOT EXISTS `sync_task_id`', 'normalized data keeps sync task id'],
  ['ADD COLUMN IF NOT EXISTS `ingestion_method`', 'normalized data keeps ingestion method'],
  ['ADD COLUMN IF NOT EXISTS `source_trace_id`', 'normalized data keeps trace id'],
]) {
  check('database/migrations/20260528_create_platform_data_sync_tables.sql', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['ADD COLUMN IF NOT EXISTS `data_period`', 'period migration adds data_period'],
  ['ADD COLUMN IF NOT EXISTS `snapshot_time`', 'period migration adds snapshot_time'],
  ['ADD COLUMN IF NOT EXISTS `snapshot_bucket`', 'period migration adds snapshot_bucket'],
  ['ADD COLUMN IF NOT EXISTS `is_final`', 'period migration adds is_final'],
  ['idx_online_daily_period_hotel', 'period migration adds period-aware hotel index'],
  ["SET `data_period` = 'historical_daily'", 'period migration keeps old rows as historical final data'],
]) {
  check('database/migrations/20260606_add_online_daily_data_period_fields.sql', label, (source) => source.includes(needle), needle);
}

check(
  'database/init_full.sql',
  'full init imports platform data-source migration',
  (source) => source.includes('20260528_create_platform_data_sync_tables.sql'),
  'SOURCE migrations/20260528_create_platform_data_sync_tables.sql'
);

check(
  'database/init_full.sql',
  'full init imports online daily period migration',
  (source) => source.includes('20260606_add_online_daily_data_period_fields.sql'),
  'SOURCE migrations/20260606_add_online_daily_data_period_fields.sql'
);

const failures = checks.filter((item) => !item.ok);
if (failures.length) {
  console.error('Platform data-source contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`Platform data-source contract verification passed (${checks.length} checks).`);
