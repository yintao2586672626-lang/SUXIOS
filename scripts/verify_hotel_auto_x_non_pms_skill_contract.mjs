import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const cache = new Map();
const checks = [];

const resolvePath = (file) => path.join(root, file);

function exists(file) {
  return fs.existsSync(resolvePath(file));
}

function read(file) {
  if (!cache.has(file)) {
    cache.set(file, fs.readFileSync(resolvePath(file), 'utf8'));
  }
  return cache.get(file);
}

function add(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function requireFile(file, label) {
  add(file, label, exists(file), file);
}

function requireText(file, needle, label) {
  add(file, label, exists(file) && read(file).includes(needle), needle);
}

function requireNoText(file, needle, label) {
  add(file, label, exists(file) && !read(file).includes(needle), needle);
}

function requireRegex(file, regex, label) {
  add(file, label, exists(file) && regex.test(read(file)), regex.toString());
}

function readFiles(files) {
  return files.map((file) => read(file)).join('\n');
}

function requireTextInFiles(files, needle, label) {
  add(files.join(' + '), label, readFiles(files).includes(needle), needle);
}

function requireRegexInFiles(files, regex, label) {
  add(files.join(' + '), label, regex.test(readFiles(files)), regex.toString());
}

const concernDir = resolvePath('app/controller/concern');
const concernFiles = fs.existsSync(concernDir)
  ? fs.readdirSync(concernDir)
    .filter((file) => file.endsWith('.php'))
    .sort()
    .map((file) => `app/controller/concern/${file}`)
  : [];
const onlineControllerFiles = ['app/controller/OnlineData.php', ...concernFiles];

requireFile('.agents/skills/hotel-auto-x-login/SKILL.md', 'local login Skill is installed');
requireFile('.agents/skills/hotel-auto-x-ctrip-collector/SKILL.md', 'local Ctrip collector Skill is installed');
requireFile('.agents/skills/hotel-auto-x-meituan-collector/SKILL.md', 'local Meituan collector Skill is installed');
requireFile('plugins/suxi-os-toolkit/skills/hotel-auto-x-login/SKILL.md', 'toolkit login Skill is packaged');
requireFile('plugins/suxi-os-toolkit/skills/hotel-auto-x-ctrip-collector/SKILL.md', 'toolkit Ctrip collector Skill is packaged');
requireFile('plugins/suxi-os-toolkit/skills/hotel-auto-x-meituan-collector/SKILL.md', 'toolkit Meituan collector Skill is packaged');

for (const [route, label] of [
  ["Route::post('/profile-login-trigger/:platform', 'OnlineData/triggerPlatformProfileLogin');", 'Profile login trigger route exists'],
  ["Route::get('/profile-login-status/:platform', 'OnlineData/platformProfileLoginStatus');", 'Profile login status route exists'],
  ["Route::get('/collection-status', 'OnlineData/collectionStatus');", 'collection status route exists'],
  ["Route::post('/fetch-ctrip-comments', 'OnlineData/fetchCtripComments');", 'Ctrip comment fetch route exists'],
  ["Route::post('/capture-ctrip-comments-browser', 'OnlineData/captureCtripCommentsBrowserData');", 'Ctrip browser comment route exists'],
  ["Route::post('/capture-ctrip-browser', 'OnlineData/captureCtripBrowserData');", 'Ctrip full browser capture route exists'],
  ["Route::post('/fetch-ctrip-cookie-api', 'OnlineData/fetchCtripCookieApiData');", 'Ctrip Cookie API route exists'],
  ["Route::post('/fetch-ctrip-ads', 'OnlineData/fetchCtripAds');", 'Ctrip ads route exists'],
  ["Route::post('/fetch-meituan-traffic', 'OnlineData/fetchMeituanTraffic');", 'Meituan traffic route exists'],
  ["Route::post('/fetch-meituan-orders', 'OnlineData/fetchMeituanOrders');", 'Meituan orders route exists'],
  ["Route::post('/fetch-meituan-ads', 'OnlineData/fetchMeituanAds');", 'Meituan ads route exists'],
  ["Route::post('/fetch-meituan-comments', 'OnlineData/fetchMeituanComments');", 'Meituan comments route exists'],
  ["Route::post('/capture-meituan-browser', 'OnlineData/captureMeituanBrowserData');", 'Meituan browser capture route exists'],
]) {
  requireText('route/app.php', route, label);
}

requireText('config/console.php', "'online-data:profile-login' => 'app\\command\\PlatformProfileLogin'", 'Profile login command is registered');
requireRegex('app/command/PlatformProfileLogin.php', /in_array\(\$platform,\s*\['ctrip', 'meituan'\],\s*true\)/, 'Profile login command allows only Ctrip and Meituan in this non-PMS flow');
requireRegex('app/command/PlatformProfileLogin.php', /--login-only=true/, 'Profile login command opens login-only browser flow');
requireText('app/command/PlatformProfileLogin.php', "--headless=false", 'manual Profile login uses a headed browser');
requireText('app/command/PlatformProfileLogin.php', '--login-url=https://ebooking.ctrip.com/home/mainland', 'manual Ctrip login uses the China eBooking entry');
requireText('app/command/PlatformProfileLogin.php', "'status_code' => 'resource_busy_login'", 'Profile login lock conflict is explicit');
requireText('app/command/PlatformProfileLogin.php', "return 'login_expired';", 'Profile login failure defaults to login_expired');
requireText('app/command/PlatformProfileLogin.php', "return 'anti_bot';", 'Profile login preserves anti_bot failures');
requireText('app/command/PlatformProfileLogin.php', "return 'session_expired';", 'Profile login preserves session_expired failures');
requireText('app/command/PlatformProfileLogin.php', "'status_code' => 'logged_in'", 'Profile login success is explicit');
requireText('app/command/PlatformProfileLogin.php', "'manual_login_state_verified' => true", 'Profile login records manual login verification');
requireText('app/command/PlatformProfileLogin.php', "$config['profile_status'] = 'logged_in';", 'Profile login records logged-in Profile status');
requireText('app/command/PlatformProfileLogin.php', "$config['last_login_verified_at'] = $now;", 'Profile login records last verification time');
requireText('app/command/PlatformProfileLogin.php', "$config['profile_login_verification_scope'] = 'browser_profile_session_only';", 'Profile login records verification scope');
requireText('app/service/PlatformDataSyncService.php', 'OTA account password custody is not supported', 'OTA password custody is rejected');
requireText('app/service/PlatformDataSyncService.php', "$missing[] = 'manual_login_state_verified';", 'background Profile sync requires manual verification');
requireText('app/service/PlatformDataSyncService.php', "$missing[] = 'profile_status_logged_in';", 'background Profile sync requires logged-in Profile status');
requireText('app/service/PlatformDataSyncService.php', "$missing[] = 'last_login_verified_at';", 'background Profile sync requires last login verification time');
requireText('app/service/PlatformDataSyncService.php', "return self::isStaleRunningSyncTask($task) ? 'stale_running' : $status;", 'stale running sync tasks stay explicit');
requireText('app/controller/concern/PlatformDataSourceConcern.php', "'stale_running_task'", 'collection-status reports stale running task reason');
requireText('app/controller/concern/Phase1EmployeeConsoleConcern.php', "return $this->phase1SyncTaskIsStaleRunning($task) ? 'stale_running' : $status;", 'Phase1 P0 sync tasks classify stale running explicitly');
requireText('scripts/verify_p0_ota_field_loop_closure.php', "return p0_sync_task_is_stale_running($task) ? 'stale_running' : $status;", 'P0 verifier classifies stale running sync tasks explicitly');
requireText('scripts/build_phase1_ota_live_closure_evidence.php', "return traffic_source_sync_task_is_stale_running($task) ? 'stale_running' : $status;", 'Phase1 evidence builder classifies stale running sync tasks explicitly');
requireText('scripts/inspect_phase1_ota_live_closure.php', "return inspection_traffic_source_sync_task_is_stale_running($task) ? 'stale_running' : $status;", 'Phase1 inspector classifies stale running sync tasks explicitly');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'storage/ctrip_profile_'", 'Ctrip Profile directory boundary is storage/ctrip_profile_*');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'storage/meituan_profile_'", 'Meituan Profile directory boundary is storage/meituan_profile_*');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'status_code'] = 'cookies_incomplete';", 'Ctrip Cookie probe exposes cookies_incomplete');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'cookies_incomplete' => 'cookies_incomplete'", 'Ctrip Profile status text maps cookies_incomplete');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'anti_bot' => 'anti_bot'", 'Profile status text maps anti_bot');
requireText('app/controller/concern/PlatformProfileCaptureConcern.php', "'session_expired' => 'session_expired'", 'Profile status text maps session_expired');
requireText('app/controller/concern/AutoFetchConcern.php', 'platformProfileSourceHasAntiBotError', 'Profile status detects anti_bot blockers from source logs');
requireText('app/service/platform/CtripBrowserProfileDataSourceAdapter.php', "'status_code' => 'resource_busy_login'", 'Ctrip adapter exposes resource_busy_login');
requireText('app/service/platform/MeituanBrowserProfileDataSourceAdapter.php', "'status_code' => 'resource_busy_login'", 'Meituan adapter exposes resource_busy_login');

requireText('scripts/ctrip_browser_capture.mjs', "const CTRIP_LOGIN_URL = 'https://ebooking.ctrip.com/home/mainland';", 'Ctrip browser capture defaults to China eBooking entry');
requireText('scripts/ctrip_browser_capture.mjs', 'page.goto(ctripLoginEntryUrl()', 'Ctrip browser capture navigates through login entry helper');
requireText('scripts/lib/ota_capture_standard.mjs', "allowedSections: ['business', 'traffic', 'ads', 'orders', 'quality', 'search_keyword', 'reviews']", 'Ctrip standard capture supports daily/full sections');
requireText('scripts/lib/ota_capture_standard.mjs', "comment_review: 'reviews'", 'Ctrip comment_review aliases to reviews');
requireText('scripts/lib/ota_capture_standard.mjs', "const section = endpoint.section === 'comment_review'", 'Ctrip comment_review endpoint normalization is present');
requireText('scripts/lib/ota_capture_standard.mjs', 'function reviewListAggregate(rows)', 'review list aggregation is implemented');
requireText('scripts/lib/ota_capture_standard.mjs', 'badReviewCount', 'bad review counters are recognized');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'qunar', 'Ctrip catalog includes Qunar channel fields');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'tongcheng', 'Ctrip catalog includes Tongcheng channel fields');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'zhixing', 'Ctrip catalog includes Zhixing channel fields');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'zx_comment_count', 'Ctrip catalog includes Zhixing comment count');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'bad_review_count', 'Ctrip catalog includes bad review count');
requireText('scripts/lib/ctrip_capture_catalog.mjs', 'comment_score_summary', 'Ctrip catalog includes score summary');
requireText('app/service/OtaStandardEtlService.php', "'fact_ota_comment' => $commentFacts", 'standard ETL maps review rows to comment facts');
requireText('app/service/OtaStandardEtlService.php', "'fact_ota_advertising' => $advertisingFacts", 'standard ETL maps ads rows to advertising facts');
requireText('app/service/OtaStandardEtlService.php', "if (str_contains($value, 'qunar'))", 'standard ETL preserves Qunar source scope');
requireText('app/service/OtaStandardEtlService.php', "return 'review';", 'standard ETL normalizes review data type');
requireText('app/controller/DailyReport.php', 'buildDailyOtaSupplementSummary', 'daily report reads OTA supplement');
requireText('app/controller/DailyReport.php', 'buildDailyOtaAdvertisingSummary', 'daily report reads OTA advertising supplement');
requireText('app/controller/DailyReport.php', 'buildDailyOtaServiceQualitySummary', 'daily report reads OTA service-quality supplement');

requireText('scripts/meituan_browser_capture.mjs', "login: 'https://me.meituan.com/ebooking/'", 'Meituan browser capture uses eBooking entry');
requireText('scripts/meituan_browser_capture.mjs', "const captureSections = normalizeCaptureSections(args.sections || args.captureSections || args.only || 'traffic,orders');", 'Meituan browser capture has traffic/orders default sections');
requireText('scripts/meituan_browser_capture.mjs', "if (wantsSection('reviews'))", 'Meituan browser capture supports reviews');
requireText('scripts/meituan_browser_capture.mjs', "if (wantsSection('traffic'))", 'Meituan browser capture supports traffic');
requireText('scripts/meituan_browser_capture.mjs', "if (args.adsUrl && wantsSection('ads'))", 'Meituan browser capture supports ads');
requireText('scripts/meituan_browser_capture.mjs', "if (wantsSection('orders'))", 'Meituan browser capture supports orders');
requireText('scripts/meituan_browser_capture.mjs', 'trafficForecast', 'Meituan browser capture keeps traffic forecast/realtime-like data');
requireText('scripts/meituan_browser_capture.mjs', 'data_period: dataPeriod', 'Meituan browser capture preserves realtime period metadata');
requireText('app/service/BrowserProfileCaptureRequestService.php', "MEITUAN_FULL_SECTIONS = 'traffic,orders,ads,reviews'", 'Meituan Profile capture exposes a full collection section set');
requireText('app/service/BrowserProfileCaptureRequestService.php', 'normalizeMeituanProfileSections', 'Meituan Profile capture normalizes full/realtime/comment/ad section aliases');
requireText('app/service/platform/MeituanBrowserProfileDataSourceAdapter.php', "'data_type' => 'review'", 'Meituan browser Profile data-source sync persists aggregate review rows');
requireText('app/service/platform/MeituanBrowserProfileDataSourceAdapter.php', "'review_count' => count", 'Meituan browser Profile data-source sync reports aggregate review counts');
requireText('public/auto-fetch-static.js', "data_period: 'realtime_snapshot'", 'Meituan traffic fetch can request realtime snapshot data');
requireText('public/meituan-static.js', 'getMeituanBrowserCapturePresets', 'Meituan static exposes Profile capture presets for realtime/reviews/full/ads');
requireText('public/index.html', '美团 Profile 采集', 'Meituan manual UI exposes Profile capture panel');
requireText('public/index.html', 'runMeituanBrowserCapturePreset(preset)', 'Meituan manual UI runs preset Profile capture actions');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "extractMeituanCapturedSection($payload, 'reviews')", 'Meituan captured payload maps reviews');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "extractMeituanCapturedSection($payload, 'traffic')", 'Meituan captured payload maps traffic');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "extractMeituanCapturedSection($payload, 'ads')", 'Meituan captured payload maps ads');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "extractMeituanCapturedSection($payload, 'orders')", 'Meituan captured payload maps orders');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time'", 'Meituan reviews accept common review date aliases');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', 'review[_-]?id|comment[_-]?id', 'Meituan review sanitizer removes review/comment IDs');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', 'guest|customer|userName|username|nick|phone|mobile|tel', 'Meituan review sanitizer removes guest and phone fields');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'data_type' => 'review'", 'Meituan review rows use review data type');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'data_type' => 'advertising'", 'Meituan ads rows use advertising data type');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'mt_pay_orders'", 'Meituan realtime traffic rows accept pay-order aliases');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'mt_pay_rooms'", 'Meituan realtime traffic rows accept pay-room aliases');
requireText('app/controller/concern/MeituanCapturedDataConcern.php', "'spend' => $spend", 'Meituan ads rows preserve spend evidence');
requireText('app/service/OnlineDataFieldFactService.php', "'metric_key' => 'mt_exposure'", 'field facts expose Meituan realtime exposure');
requireText('app/service/OnlineDataFieldFactService.php', "'metric_key' => 'mt_pay_orders'", 'field facts expose Meituan realtime pay orders');
requireText('app/service/OnlineDataFieldFactService.php', "'metric_key' => 'ad_spend'", 'field facts expose Meituan ad spend');
requireText('app/service/PlatformDataSyncService.php', "'metric_key' => 'mt_exposure'", 'platform data-source sync exposes Meituan realtime exposure facts');
requireText('app/service/PlatformDataSyncService.php', "'metric_key' => 'mt_pay_orders'", 'platform data-source sync exposes Meituan realtime pay-order facts');
requireText('app/service/PlatformDataSyncService.php', 'review[_-]?id|comment[_-]?id', 'platform data-source sync removes review/comment identifiers from review rows');
requireText('app/controller/DailyReport.php', "'mt_exposure' => 0", 'daily report keeps Meituan exposure metric');

requireTextInFiles(onlineControllerFiles, 'function commentCollectionDisabledResponse', 'legacy disallowed review-detail response remains explicit');
requireTextInFiles(onlineControllerFiles, 'aggregate_metrics_only_no_review_text', 'comment capture documents aggregate-only no-review-text boundary');
requireTextInFiles(onlineControllerFiles, 'return $this->captureCtripCommentsBrowserData();', 'Ctrip comments route delegates to aggregate browser capture');
requireTextInFiles(onlineControllerFiles, 'return $this->captureMeituanBrowserData($requestData);', 'Meituan comments route delegates to aggregate browser capture');
requireText('app/controller/concern/AutoFetchConcern.php', "'label' => 'ctrip-comments'", 'auto-fetch includes Ctrip comments task');
requireText('app/controller/concern/AutoFetchConcern.php', "'capture_sections' => 'comment_review'", 'auto-fetch Ctrip comments use comment_review section');
requireText('app/controller/concern/AutoFetchConcern.php', "'ctrip:comments' => $this->executeCtripBrowserProfileAutoFetch(", 'auto-fetch runs Ctrip comments through browser Profile');
requireText('app/controller/concern/AutoFetchConcern.php', "'label' => 'meituan-comments'", 'auto-fetch includes Meituan comments task');
requireText('app/controller/concern/AutoFetchConcern.php', "'capture_sections' => 'reviews'", 'auto-fetch Meituan comments use reviews section');
requireText('app/controller/concern/AutoFetchConcern.php', "'meituan:comments' => $this->executeMeituanBrowserProfileAutoFetch(", 'auto-fetch runs Meituan comments through browser Profile');
requireNoText('app/controller/concern/AutoFetchConcern.php', 'commentCollectionDisabledResponse($platform', 'auto-fetch does not disable aggregate comment modules by policy');

requireText('public/index.html', "const defaultCtripLoginUrl = 'https://ebooking.ctrip.com/home/mainland';", 'frontend Ctrip login opens China eBooking entry');
requireText('public/index.html', 'fetchCtripComments', 'frontend exposes Ctrip comment fetch');
requireText('public/index.html', 'runCtripCommentBrowserCapture', 'frontend exposes Ctrip browser comment capture');
requireText('public/index.html', 'fetchMeituanComments', 'frontend exposes Meituan comment fetch');
requireText('public/index.html', "`/online-data/profile-login-trigger/${platform}`", 'frontend triggers Profile login task');
requireText('public/index.html', "`/online-data/profile-login-status/${platform}?${params.toString()}`", 'frontend polls Profile login task');
requireText('public/index.html', "`/online-data/collection-status?${params.toString()}`", 'frontend refreshes collection status');
requireText('public/index.html', "stale_running: '任务运行超时'", 'frontend labels stale running collection tasks');
requireText('public/index.html', "'session_expired', 'login_expired', 'anti_bot', 'resource_busy_login', 'cookies_incomplete', 'capture_failed', 'permission_denied', 'hotel_mismatch'", 'frontend blocks collection on explicit Profile failure states');
requireText('public/auto-fetch-static.js', "if (statusCode === 'cookies_incomplete') return 'Cookie incomplete';", 'auto-fetch UI labels cookies_incomplete');
requireText('public/auto-fetch-static.js', "if (statusCode === 'anti_bot') return 'Anti bot';", 'auto-fetch UI labels anti_bot');
requireText('public/auto-fetch-static.js', "if (statusCode === 'resource_busy_login') return 'Login busy';", 'auto-fetch UI labels resource_busy_login');
requireText('public/auto-fetch-static.js', "cookies_incomplete: 'bg-red-50 text-red-700 border-red-200'", 'auto-fetch UI badges cookies_incomplete');
requireText('scripts/verify_ota_diagnosis_auto_fetch.mjs', 'auto-fetch can queue Ctrip aggregate comments through browser Profile', 'existing diagnosis verifier covers Ctrip aggregate comments');
requireText('scripts/verify_ota_diagnosis_auto_fetch.mjs', 'auto-fetch can queue Meituan aggregate comments through browser Profile', 'existing diagnosis verifier covers Meituan aggregate comments');
requireText('package.json', '"verify:hotel-auto-x-non-pms-skills": "node scripts/verify_hotel_auto_x_non_pms_skill_contract.mjs"', 'package script exposes this contract verifier');

const failures = checks.filter((check) => !check.ok);
if (failures.length > 0) {
  console.error(`hotel-auto-x non-PMS skill contract failed (${failures.length}/${checks.length} failed)`);
  for (const failure of failures) {
    console.error(`- ${failure.label}`);
    console.error(`  file: ${failure.file}`);
    if (failure.detail) console.error(`  expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`hotel-auto-x non-PMS skill contract passed (${checks.length} checks).`);
