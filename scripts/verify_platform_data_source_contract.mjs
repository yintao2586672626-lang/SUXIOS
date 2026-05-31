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
]) {
  check('app/service/PlatformDataSyncService.php', label, (source) => source.includes(needle), needle);
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

check(
  'scripts/meituan_browser_capture.mjs',
  'Meituan browser capture sanitizes responses before writing output',
  (source) => source.includes('sanitizeOtaPayloadForStorage(body, section)') && source.includes("if (section === 'orders')"),
  'sanitizeOtaPayloadForStorage(body, section)'
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

check(
  'database/init_full.sql',
  'full init imports platform data-source migration',
  (source) => source.includes('20260528_create_platform_data_sync_tables.sql'),
  'SOURCE migrations/20260528_create_platform_data_sync_tables.sql'
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
