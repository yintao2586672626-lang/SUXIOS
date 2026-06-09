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
  ['function triggerPlatformProfileLogin', 'controller exposes async platform Profile login trigger'],
  ['function platformProfileLoginStatus', 'controller exposes async platform Profile login status'],
  ['function meituanProfileStatus', 'controller exposes Meituan Profile status probe'],
  ['function bindBrowserProfileDataSource', 'controller binds successful login-only Profile to data source'],
  ['function findBrowserProfileDataSourceForUnbind', 'controller resolves browser Profile binding without frontend data-source id'],
  ['function clearBrowserProfileStatusCacheForSource', 'controller clears stale Profile status when data source is unbound'],
]) {
  check('app/controller/OnlineData.php', label, (source) => source.includes(method), method);
}

for (const [needle, label] of [
  ['function commentCollectionDisabledResponse', 'controller has a single disabled comment-collection response'],
  ['return $this->commentCollectionDisabledResponse();', 'controller comment endpoints short-circuit before OTA requests'],
  ["'Comment/review data collection is disabled by policy.'", 'controller auto-fetch comment tasks are skipped by policy'],
  ['function sanitizeOnlineOrderRawData', 'controller sanitizes browser-captured order raw data before storage'],
  ['function hashOnlineOrderIdentifier', 'controller hashes browser-captured order identifiers'],
  ['order_id_hash', 'controller keeps only hashed order identifiers in captured raw data'],
]) {
  check('app/controller/OnlineData.php', label, (source) => source.includes(needle), needle);
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
]) {
  check('public/index.html', label, (source) => source.includes(needle), needle);
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
  ['Comment/review data collection is disabled by policy.', 'platform sync rejects comment/review sources'],
  ['function sanitizePayloadForStorage', 'platform sync sanitizes raw payload before storage'],
  ['order_id_hash', 'platform sync hashes order identifiers'],
  ['function maskName', 'platform sync masks guest names'],
  ['function maskPhone', 'platform sync masks phone numbers'],
  ['checkoutRevenue', 'platform sync maps Ctrip business revenue fields'],
  ['todayCost', 'platform sync maps advertising cost fields'],
  ['orderList', 'platform sync extracts order list envelopes'],
  ['campaignList', 'platform sync extracts campaign envelopes'],
  ['CtripBrowserProfileDataSourceAdapter', 'platform sync registers Ctrip browser Profile adapter'],
  ['MeituanBrowserProfileDataSourceAdapter', 'platform sync registers Meituan browser Profile adapter'],
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
  ["'profile_id'", 'platform sync accepts Ctrip browser Profile config fields'],
  ["'store_id'", 'platform sync accepts Meituan browser Profile config fields'],
  ["'source_trace_id' => $traceId", 'platform sync preserves browser Profile trace ids'],
]) {
  check('app/service/PlatformDataSyncService.php', label, (source) => source.includes(needle), needle);
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

for (const [needle, label] of [
  ["'browser_profile'", 'Meituan browser Profile adapter supports browser_profile ingestion method'],
  ["'meituan_profile_'", 'Meituan browser Profile adapter checks local Profile directory'],
  ['meituan_browser_capture.mjs', 'Meituan browser Profile adapter reuses existing browser capture script'],
  ['auth_status', 'Meituan browser Profile adapter exposes login state failures'],
  ['capture_gate', 'Meituan browser Profile adapter exposes capture gate failures'],
  ["'acquisition_method' => 'browser_profile'", 'Meituan browser Profile adapter labels acquisition method'],
]) {
  check('app/service/platform/MeituanBrowserProfileDataSourceAdapter.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['value="browser_profile"', 'frontend data-source form exposes Ctrip browser Profile method'],
  ['platformDataSourceConfigPlaceholder', 'frontend shows Ctrip browser Profile config example'],
  ['platformDataSourceSecretPlaceholder', 'frontend keeps optional cookies in secret config'],
  ['platformProfileStatus', 'frontend renders platform Profile account status'],
  ['profile-login-trigger', 'frontend starts async platform Profile login task'],
  ['profile-login-status', 'frontend polls async platform Profile login status'],
  ['platformProfileLoginTasks', 'frontend tracks async platform Profile login tasks'],
  ['triggerPlatformProfileLogin', 'frontend uses async platform Profile login trigger'],
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
  ["data_period: 'realtime_snapshot'", 'frontend manual trigger requests realtime snapshot data'],
  ["data_period: 'historical_daily'", 'frontend backfill requests historical fixed data'],
  ['autoFetchTimingRows', 'frontend renders auto-fetch timing breakdown'],
  ["page_size: 'all'", 'frontend analysis panel requests all filtered online_daily_data rows'],
]) {
  check('public/index.html', label, (source) => source.includes(needle), needle);
}

check(
  'public/index.html',
  'frontend analysis detail wording does not imply a 20-row cap',
  (source) => !source.includes('展示最近 {{ onlineAnalysisRows.length }} 条'),
  '展示最近 {{ onlineAnalysisRows.length }} 条'
);

check(
  'app/controller/OnlineData.php',
  'daily data list supports all filtered rows for analysis detail',
  (source) => source.includes("['all', '全部']") && source.includes("'all' => $fetchAll"),
  "['all', '全部'] and 'all' => $fetchAll"
);

for (const [needle, label] of [
  ["'online-data:profile-login' => 'app\\command\\PlatformProfileLogin'", 'console registers async platform Profile login command'],
]) {
  check('config/console.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['--login-only=true', 'async login command runs browser capture in login-only mode'],
  ['--headless=false', 'async login command always opens a visible browser for manual verification'],
  ['platform_profile_login_task_', 'async login command writes task status cache'],
  ['current_key', 'async login command updates current task cache for polling'],
  ['PlatformDataSyncService', 'async login command binds successful Profile to platform data source'],
  ["'capture_sections' => $this->safeSections", 'async login command normalizes array capture sections before binding'],
]) {
  check('app/command/PlatformProfileLogin.php', label, (source) => source.includes(needle), needle);
}

for (const [needle, label] of [
  ['comment_collection_disabled', 'standard ETL rejects comment/review facts'],
  ["'fact_ota_advertising' => $advertisingFacts", 'standard ETL separates advertising facts from daily revenue'],
  ["'fact_ota_quality' => $qualityFacts", 'standard ETL separates quality facts from daily revenue'],
  ["'fact_ota_comment' => $commentFacts", 'standard ETL keeps empty compatibility key for comment facts'],
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
  ['DISABLED_COMMENT_SECTION_ALIASES', 'browser capture rejects comment/review section aliases'],
  ['comment_collection_disabled', 'browser capture classifies comment endpoints as disabled'],
  ['function sanitizeOtaPayloadForStorage', 'browser capture exposes reusable payload sanitizer'],
  ['order_id_hash', 'browser capture hashes order identifiers before writing output'],
  ['querycampaignsummaryreport', 'browser capture classifies Ctrip campaign report responses'],
]) {
  check('scripts/lib/ota_capture_standard.mjs', label, (source) => source.includes(needle), needle);
}

check(
  'scripts/ctrip_browser_capture.mjs',
  'Ctrip browser capture sections exclude reviews',
  (source) => source.includes('normalizeCtripCaptureSections') && !source.includes("'getCommentList'"),
  'normalizeCtripCaptureSections without getCommentList'
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
