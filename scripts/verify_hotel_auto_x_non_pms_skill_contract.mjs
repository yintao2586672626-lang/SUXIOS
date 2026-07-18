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

function requireTextBefore(file, beforeNeedle, afterNeedle, label) {
  const source = exists(file) ? read(file) : '';
  const beforeIndex = source.indexOf(beforeNeedle);
  const afterIndex = source.indexOf(afterNeedle);
  add(file, label, beforeIndex !== -1 && afterIndex !== -1 && beforeIndex < afterIndex, `${beforeNeedle} before ${afterNeedle}`);
}

function requireFunctionBodyNoText(file, functionName, needle, label) {
  const source = exists(file) ? read(file) : '';
  const start = source.indexOf(`function ${functionName}`);
  const open = start === -1 ? -1 : source.indexOf('{', start);
  let depth = 0;
  let end = -1;
  if (open !== -1) {
    for (let index = open; index < source.length; index += 1) {
      if (source[index] === '{') depth += 1;
      if (source[index] === '}') {
        depth -= 1;
        if (depth === 0) {
          end = index;
          break;
        }
      }
    }
  }
  const body = start === -1 || end === -1 ? '' : source.slice(start, end + 1);
  add(file, label, body !== '' && !body.includes(needle), `${functionName}: ${needle}`);
}

function requireFunctionBodyText(file, functionName, needle, label) {
  const source = exists(file) ? read(file) : '';
  const start = source.indexOf(`function ${functionName}`);
  const open = start === -1 ? -1 : source.indexOf('{', start);
  let depth = 0;
  let end = -1;
  if (open !== -1) {
    for (let index = open; index < source.length; index += 1) {
      if (source[index] === '{') depth += 1;
      if (source[index] === '}') {
        depth -= 1;
        if (depth === 0) {
          end = index;
          break;
        }
      }
    }
  }
  const body = start === -1 || end === -1 ? '' : source.slice(start, end + 1);
  add(file, label, body.includes(needle), `${functionName}: ${needle}`);
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
requireFile('scripts/verify_hotel_auto_x_ctrip_collector_contract.php', 'Ctrip collector contract verifier is tracked');
requireFile('scripts/verify_hotel_auto_x_meituan_collector_contract.php', 'Meituan collector contract verifier is tracked');
requireFile('scripts/verify_hotel_ota_login_eligibility.php', 'hotel OTA login eligibility verifier is tracked');
requireFile('scripts/verify_hotel_ota_login_eligibility_behavior.mjs', 'hotel OTA login eligibility behavior verifier is tracked');
requireText('package.json', 'node scripts/verify_hotel_ota_login_eligibility_behavior.mjs', 'non-PMS skill verification runs eligibility behavior checks');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'scope' => 'ota_channel_only'", 'eligibility verifier keeps OTA scope explicit');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'source_policy' => 'read_platform_data_sources_metadata_only'", 'eligibility verifier reads platform metadata only');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'sensitive_values_exposed' => false", 'eligibility verifier does not expose sensitive values');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_applicable_platforms", 'eligibility verifier respects hotel OTA channel strategy');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "can_fetch_online_data", 'eligibility verifier checks store fetch permission');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "profile_login_verified", 'eligibility verifier checks Profile login verification');
requireText('scripts/verify_hotel_ota_login_eligibility.php', '$manualVerified && $statusVerified && $lastVerifiedAt !== \'\'', 'eligibility verifier requires manual login verification, logged-in status, and timestamp together');
requireText('scripts/verify_hotel_ota_login_eligibility.php', '$sourceProfileVerified = hotel_ota_profile_verified($config)', 'eligibility verifier derives timestamp evidence only after Profile verification');
requireNoText('scripts/verify_hotel_ota_login_eligibility.php', "auth_status", 'eligibility verifier does not treat auth_status alone as runnable Profile evidence');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "sync_task_running", 'eligibility verifier blocks manual login while same platform task is running');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "orphan_platform_data_source", 'eligibility verifier reports orphan platform data-source rows');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'next_action' => $nextAction", 'eligibility verifier emits a concrete next action per platform');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'recheck_command' => $recheckCommand", 'eligibility verifier emits a single-store recheck command');
requireText('scripts/verify_hotel_ota_login_eligibility.php', '--format=json --strict', 'eligibility verifier emits strict single-store recheck commands');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'manual_login_entry' => $status === 'ready_for_manual_login'", 'eligibility verifier only emits login entry for manual-login-ready rows');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_next_action", 'eligibility verifier maps blockers to operator actions');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'hotel_lifecycle_state' => $hotelLifecycleState", 'eligibility verifier exposes hotel lifecycle state');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'inactive_hotel_blocks_ota_flow' => $inactiveHotelBlocksOtaFlow", 'eligibility verifier marks inactive hotels as OTA-flow blockers');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'downstream_setup_suppressed' => $inactiveHotelBlocksOtaFlow", 'eligibility verifier suppresses downstream setup for inactive hotels');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_primary_blocker", 'eligibility verifier maps final status to a primary blocker');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'primary_blocker' => hotel_ota_primary_blocker($status, $blockers)", 'eligibility verifier emits status-aligned primary blocker');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_strategy_candidate", 'eligibility verifier separates missing source from OTA strategy candidates');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'strategy_candidates' => $strategyCandidates", 'eligibility verifier emits store-level strategy candidates');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'strategy_adjustment_candidate' => false", 'eligibility verifier marks platform rows before any strategy candidate override');
requireText('scripts/verify_hotel_ota_login_eligibility.php', 'candidate only; confirm business OTA channel before updating strategy.', 'eligibility verifier requires business confirmation before strategy updates');
requireText('scripts/verify_hotel_ota_login_eligibility.php', 'Strategy candidate checks need same-hotel sibling platform metadata even for --platform output filters.', 'eligibility verifier keeps sibling platform context for single-platform checks');
requireText('scripts/verify_hotel_ota_login_eligibility.php', '$visibleStrategyCandidate', 'eligibility verifier only exposes strategy candidates relevant to the requested platform');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'platform_filter' => $platformFilter", 'eligibility verifier reports requested platform filter');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'rollup_scope' => $rollupScope", 'eligibility verifier labels whether rollup is all-platform or requested-platform only');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'strategy_context_scope' => 'same_hotel_all_applicable_platforms'", 'eligibility verifier labels sibling platform context as read-only strategy evidence');
requireText('scripts/verify_hotel_ota_login_eligibility.php', '$hasNotApplicableSpecificRequest', 'eligibility verifier tracks explicit single-store platform requests excluded by strategy');
requireText('scripts/verify_hotel_ota_login_eligibility.php', 'platform_not_applicable_to_strategy', 'eligibility verifier reports requested platforms excluded by OTA strategy');
requireText('scripts/verify_hotel_ota_login_eligibility.php', 'do not treat an empty platform report as login-ready', 'eligibility verifier prevents empty not-applicable platform reports from reading as login-ready');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "($hotelIdFilter !== '' && $hotels === []) || $hasNotApplicableSpecificRequest", 'eligibility verifier exits non-zero for invalid explicit hotel or platform requests');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_task_evidence", 'eligibility verifier exposes task evidence for running and stale-running blockers');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "function hotel_ota_task_activity_reference_at", 'eligibility verifier uses latest task activity for stale-running checks');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "$reference = hotel_ota_task_activity_reference_at($task);", 'eligibility verifier stale-running check uses activity reference instead of start time only');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "return hotel_ota_task_activity_reference_at($task);", 'eligibility verifier stale task evidence reports the same activity reference');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "Task blockers stay ahead of permission/source blockers", 'eligibility verifier keeps collector-lock blockers ahead of setup blockers');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'blocking_task_ids' => $taskEvidence['blocking_task_ids']", 'eligibility verifier includes blocking sync task ids');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'oldest_stale_task_at' => $taskEvidence['oldest_stale_task_at']", 'eligibility verifier includes oldest stale task timestamp');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'missing_task_id_count' => $taskEvidence['missing_task_id_count']", 'eligibility verifier reports incomplete task id evidence');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'task_id_evidence_complete' => $taskEvidence['task_id_evidence_complete']", 'eligibility verifier labels whether task id evidence is complete');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "不能凭状态批量清理", 'eligibility verifier forbids cleanup when stale task ids are incomplete');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "LOWER(p.`platform`) IN ('ctrip', 'meituan')", 'eligibility verifier keeps orphan source scope to Ctrip and Meituan');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'orphan_source_scope' => $platformFilter === 'all' ? 'ctrip_meituan_only' : $platformFilter", 'eligibility verifier labels orphan source scope');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "$row['flow_included'] = false", 'eligibility verifier excludes orphan sources from OTA login and collection flow');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "未确认前不得进入 OTA 登录/采集流程", 'eligibility verifier gives a safe action for orphan sources');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "hotel_ota_permission_blocker_reason", 'eligibility verifier explains fetch-permission blockers');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'permission_user_count' => $permissionEvidence['permission_user_count']", 'eligibility verifier reports total hotel permission users');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'fetch_permission_user_count' => $permissionEvidence['fetch_permission_user_count']", 'eligibility verifier reports users granted OTA fetch permission');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "'permission_blocker_reason' => $permissionEvidence['permission_blocker_reason']", 'eligibility verifier reports permission blocker reason');
requireText('scripts/verify_hotel_ota_login_eligibility.php', "&& !in_array('inactive_hotel', $blockers, true)", 'eligibility verifier does not suggest permission repair as the next action for inactive hotels');
requireRegex('scripts/verify_p0_ota_field_loop_closure.php', /function p0_traffic_current_session_verified\(array \$row, array \$config\): bool[\s\S]*?OtaProfileSessionProofService[\s\S]*?isCurrentVerified\(\$source\)/, 'P0 field-loop verifier delegates same-source current-session proof to the runtime service');
requireRegex('app/controller/concern/Phase1EmployeeConsoleConcern.php', /function phase1TrafficProfileLoginStateVerified\(array \$source\): bool[\s\S]*?OtaProfileSessionProofService[\s\S]*?isCurrentVerified\(\$source\);\n    }/, 'employee console requires same-source current-session Profile proof');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'filterCollectableBrowserProfileDataSources', 'profileReuseState($source)', 'AutoFetch only collects reusable browser Profile sources');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'filterCollectableBrowserProfileDataSources', "['is_reusable']", 'AutoFetch checks the Profile reuse verdict');
requireText('public/app-main.js', 'bindingContract.current_session_verified === true', 'frontend Profile flow requires explicit current-session verification when a binding contract exists');
requireText('public/app-main.js', "const loginVerified = currentSessionVerified && statusCode === 'logged_in';", 'frontend Profile flow requires both current-session proof and logged_in status');
for (const [file, functionName] of [
  ['scripts/verify_p0_ota_field_loop_closure.php', 'p0_traffic_current_session_verified'],
  ['app/controller/concern/Phase1EmployeeConsoleConcern.php', 'phase1TrafficProfileLoginStateVerified'],
  ['app/controller/concern/AutoFetchConcern.php', 'filterCollectableBrowserProfileDataSources'],
]) {
  requireFunctionBodyNoText(file, functionName, 'profile_status', `${functionName} does not use profile_status as login proof`);
  requireFunctionBodyNoText(file, functionName, 'login_status', `${functionName} does not use login_status as login proof`);
  requireFunctionBodyNoText(file, functionName, 'auth_status', `${functionName} does not use auth_status as login proof`);
}

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
requireText('app/command/PlatformProfileLogin.php', "$authStatusCode = strtolower(trim((string)($authStatus['status'] ?? '')));", 'Profile login success extracts explicit auth status code');
requireText('app/command/PlatformProfileLogin.php', "&& !empty($authStatus['ok'])", 'Profile login success requires explicit auth_status.ok evidence');
requireText('app/command/PlatformProfileLogin.php', "&& in_array($authStatusCode, ['logged_in', 'authorized'], true)", 'Profile login success requires a verified logged-in auth status');
requireText('app/command/PlatformProfileLogin.php', 'isCollectableProfileLoginSessionProbe($sessionProbe)', 'Profile login requires account-level Session collectability');
requireText('app/command/PlatformProfileLogin.php', 'isStrongProfileLoginSessionProbe($sessionProbe)', 'Profile login writes hotel-level proof only after identity verification');
requireText('app/service/OtaProfileSessionProofService.php', 'recordProfileLoginVerified(', 'Profile proof service has a strong Session-probe entrypoint');
requireText('app/service/OtaProfileSessionProofService.php', 'assertStrongProfileLoginSessionProbe($sessionProbe)', 'Profile proof service rejects weak Session evidence');
requireText('app/service/OtaProfileSessionProofService.php', 'private function recordVerified(', 'Low-level authoritative proof writer is not a public weak-evidence bypass');
requireText('app/service/OtaProfileSessionProofService.php', "'identity_status' => 'matched'", 'Authoritative Session proof requires matched hotel identity');
requireText('scripts/ctrip_browser_capture.mjs', 'const sessionProbeOnly = booleanArg(args.sessionProbeOnly)', 'Ctrip supports a non-interactive Session probe mode');
requireText('scripts/meituan_browser_capture.mjs', 'const sessionProbeOnly = booleanArg(args.sessionProbeOnly)', 'Meituan supports a non-interactive Session probe mode');
requireText('scripts/ctrip_browser_capture.mjs', "classifyOtaSessionProbeResponse('ctrip'", 'Ctrip Session probe classifies protected endpoint metadata and bounded drift signals');
requireText('scripts/meituan_browser_capture.mjs', "classifyOtaSessionProbeResponse('meituan'", 'Meituan Session probe classifies protected endpoint metadata and bounded drift signals');
requireText('app/controller/concern/AutoFetchConcern.php', 'assertPlatformProfileLoginBackoffClear', 'Profile login enforces anti-bot backoff before relaunch');
requireNoText('app/command/PlatformProfileLogin.php', "$authStatus === [] || !empty($authStatus['ok'])", 'Profile login does not treat missing auth_status as logged in');
requireNoText('app/command/PlatformProfileLogin.php', "['ok' => true, 'status' => 'logged_in']", 'Profile login does not synthesize logged_in auth_status when auth evidence is missing');
requireText('app/command/PlatformProfileLogin.php', "'ok' => (bool)($authStatus['ok'] ?? false)", 'Profile login auth status compression defaults missing ok to false');
requireText('app/command/PlatformProfileLogin.php', "'status' => $status !== '' ? $status : 'unknown'", 'Profile login auth status compression defaults missing status to unknown');
requireText('app/command/PlatformProfileLogin.php', "'manual_login_state_verified' => true", 'Profile login records manual login verification');
requireText('app/command/PlatformProfileLogin.php', "$config['profile_status'] = 'logged_in';", 'Profile login records logged-in Profile status');
requireText('app/command/PlatformProfileLogin.php', "$config['last_login_verified_at'] = $now;", 'Profile login records last verification time');
requireText('app/command/PlatformProfileLogin.php', "$config['profile_login_verification_scope'] = 'browser_profile_session_only';", 'Profile login records verification scope');
requireText('app/service/PlatformDataSyncService.php', 'OTA account password custody is not supported', 'OTA password custody is rejected');
requireFunctionBodyText('app/service/PlatformDataSyncService.php', 'browserProfileBackgroundSyncLoginMissingRequirements', 'profileReuseState($source)', 'background Profile sync uses the Profile reuse contract');
requireFunctionBodyText('app/service/PlatformDataSyncService.php', 'browserProfileBackgroundSyncLoginMissingRequirements', "['is_reusable']", 'background Profile sync requires a reusable Profile session');
requireFunctionBodyText('app/service/PlatformDataSyncService.php', 'browserProfileBackgroundSyncLoginMissingRequirements', 'browserProfileRiskControlReviewRequired($source)', 'background Profile sync stops after anti-bot until manual review');
requireText('scripts/lib/ota_session_probe.mjs', 'findCtripEndpointByUrl(`${parsed.origin}${parsed.pathname}`)', 'Ctrip Session endpoint matching ignores query and fragment spoofing');
requireNoText('scripts/lib/ota_session_probe.mjs', 'cookie?.httpOnly === true ||', 'Session proof does not promote arbitrary HttpOnly cookies');
requireText('scripts/lib/ota_session_probe.mjs', 'const protectedApiEvidencePresent = successfulApiResponseCount > 0;', 'recognized protected business HTTP evidence is explicit');
requireText('scripts/lib/ota_session_probe.mjs', '} else if (!protectedApiEvidencePresent) {', 'page and Cookie hints cannot replace protected business HTTP evidence');
requireNoText('scripts/lib/ota_session_probe.mjs', 'verification[_-]?(?:code|challenge)', 'ordinary SMS verification is not classified as anti-bot risk control');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'evaluateBrowserProfileLoginPayload($payload)', 'legacy browser login endpoints reuse the strict Session contract');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'bindVerifiedBrowserProfileDataSource(', 'legacy browser login binds only through verified hotel proof');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'recordVerifiedBrowserProfileCollectionProof(', 'successful legacy browser capture can persist a verified collection preflight proof');
requireFunctionBodyText('app/controller/concern/OnlineDataRequestConcern.php', 'recordVerifiedBrowserProfileCollectionProof', 'recordBrowserProfileCollectionProofOutcome(', 'legacy collection proof compatibility delegates to the structured outcome gate');
requireFunctionBodyText('app/controller/concern/OnlineDataRequestConcern.php', 'recordBrowserProfileCollectionProofOutcome', 'browserProfileCollectionProofBlocker(', 'collection proof persistence delegates to one blocker gate');
requireFunctionBodyText('app/controller/concern/OnlineDataRequestConcern.php', 'browserProfileCollectionProofBlocker', '$savedCount <= 0', 'confirmed-empty browser captures never promote Session proof');
requireText('app/controller/concern/AutoFetchConcern.php', "$rawData['hotel_id_source_key']", 'legacy Ctrip save identity uses an observed source key instead of normalized fallback hotel_id');
requireText('app/service/platform/CtripBrowserProfileDataSourceAdapter.php', "if ($identityStatus !== 'matched')", 'Ctrip data-source capture refuses rows until hotel identity is matched');
requireFunctionBodyText('app/service/PlatformDataSyncService.php', 'recordBrowserProfileCollectionPreflight', 'recordProfileSessionBlocked(', 'failed collection identity or risk evidence invalidates prior Profile proof');
requireText('app/controller/concern/PlatformDataSourceConcern.php', 'sanitizePublicDataSourceSyncOptions($this->requestData())', 'public data-source sync strips internal Profile-login bypass options');
requireText('app/service/OtaProfileSessionProofService.php', 'clearStaleAuthenticationFailureConfig(', 'fresh authoritative proof clears stale authentication failure state');
for (const legacyProofField of ['manual_login_state_verified', 'profile_status_logged_in', 'last_login_verified_at']) {
  requireFunctionBodyNoText(
    'app/service/PlatformDataSyncService.php',
    'browserProfileBackgroundSyncLoginMissingRequirements',
    legacyProofField,
    `background Profile sync does not authorize from legacy ${legacyProofField}`
  );
}
requireText('app/service/PlatformDataSyncService.php', "return self::isStaleRunningSyncTask($task) ? 'stale_running' : $status;", 'stale running sync tasks stay explicit');
requireText('app/service/PlatformDataSyncService.php', "private static function syncTaskLatestTimestamp", 'runtime sync task freshness uses latest valid timestamp helper');
requireText('app/service/PlatformDataSyncService.php', "self::syncTaskLatestTimestamp($task, ['update_time', 'updated_at', 'started_at', 'create_time', 'created_at'])", 'runtime stale-running age prefers latest update timestamp over started_at');
requireText('app/service/PlatformDataSyncService.php', "self::syncTaskLatestTimestamp($task, ['finished_at', 'update_time', 'updated_at', 'started_at', 'create_time', 'created_at'])", 'runtime latest task ordering uses latest known activity timestamp');
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
requireNoText('public/index.html', '美团 Profile 采集', 'removed Meituan manual Profile panel stays out of the slimmed UI');
requireNoText('public/index.html', 'runMeituanBrowserCapturePreset(preset)', 'removed Meituan manual Profile preset actions stay out of the slimmed UI');
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
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeCtripBrowserProfileAutoFetch', 'profileReuseState($profileSource ?? [])', 'auto-fetch Ctrip browser Profile execution requires reusable Profile evidence');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeCtripBrowserProfileAutoFetch', "'profile_session_unverified'", 'auto-fetch Ctrip keeps unverified Profile failure explicit');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeCtripBrowserProfileAutoFetch', "'profile_session_expired'", 'auto-fetch Ctrip keeps expired Profile failure explicit');
requireText('app/controller/concern/AutoFetchConcern.php', "'capture_sections' => 'comment_review'", 'auto-fetch Ctrip comments use comment_review section');
requireText('app/controller/concern/AutoFetchConcern.php', "'ctrip:comments' => $this->executeCtripBrowserProfileAutoFetch(", 'auto-fetch runs Ctrip comments through browser Profile');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeMeituanBrowserProfileAutoFetch', 'profileReuseState($profileSource ?? [])', 'auto-fetch Meituan browser Profile execution requires reusable Profile evidence');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeMeituanBrowserProfileAutoFetch', "'profile_session_unverified'", 'auto-fetch Meituan keeps unverified Profile failure explicit');
requireFunctionBodyText('app/controller/concern/AutoFetchConcern.php', 'executeMeituanBrowserProfileAutoFetch', "'profile_session_expired'", 'auto-fetch Meituan keeps expired Profile failure explicit');
requireText('app/controller/concern/AutoFetchConcern.php', "'capture_sections' => 'reviews'", 'auto-fetch Meituan comments use reviews section');
requireText('app/controller/concern/AutoFetchConcern.php', "'meituan:comments' => $this->executeMeituanBrowserProfileAutoFetch(", 'auto-fetch runs Meituan comments through browser Profile');
requireNoText('app/controller/concern/AutoFetchConcern.php', 'commentCollectionDisabledResponse($platform', 'auto-fetch does not disable aggregate comment modules by policy');

requireText('public/app-main.js', "const defaultCtripLoginUrl = 'https://ebooking.ctrip.com/home/mainland';", 'frontend Ctrip login opens China eBooking entry');
requireText('public/app-main.js', "const defaultMeituanLoginUrl = 'https://me.meituan.com/ebooking/';", 'frontend Meituan login opens eBooking entry');
requireText('public/app-main.js', 'clientLocalAuthorizationRequired', 'frontend labels account-owner local authorization requirement');
requireText('public/app-main.js', 'openTargetSite(localPlatformAuthorizationUrl(platform))', 'frontend opens platform authorization on current client computer');
requireText('public/app-main.js', 'server_browser_launch_disabled', 'frontend records server-side browser launch disabled');
requireText('public/app-main.js', 'account_owner_local_computer_only', 'frontend records account-owner local computer authorization policy');
requireText('public/app-main.js', 'const canLaunchLocalPlatformProfileBrowser = () =>', 'frontend detects account-owner loopback access before launching Profile browser');
requireText('public/app-main.js', "['127.0.0.1', 'localhost', '::1'].includes(hostname)", 'frontend limits Profile browser launch to loopback hosts');
requireText('public/app-main.js', "`/online-data/profile-login-trigger/${platform}`", 'frontend triggers the local Profile login task');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'private function isLocalPlatformProfileLoginRequest(): bool', 'backend has a loopback guard for Profile login launch');
requireText('app/controller/concern/OnlineDataRequestConcern.php', "['127.0.0.1', '::1', '::ffff:127.0.0.1']", 'backend accepts only loopback client IPs for Profile login launch');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'client_local_authorization_required', 'backend blocks non-local Profile login launch');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'server_browser_launch_disabled', 'backend reports browser launch disabled for non-local access');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'launchPlatformProfileLoginTask($task)', 'backend launches the existing Profile login task from local access');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'private function currentUserCanViewOnlineDataHotel(int $hotelId): bool', 'Profile login status has a hotel-scoped OTA view permission helper');
requireText('app/controller/concern/OnlineDataRequestConcern.php', "$this->currentUser->hasHotelPermission($hotelId, 'can_view_online_data')", 'Profile login status helper checks can_view_online_data for the target hotel');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'if (!$this->currentUserCanViewOnlineDataHotel((int)$systemHotelId))', 'Profile login current-task lookup checks target hotel OTA view permission');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'if ($taskHotelId <= 0)', 'Profile login task-id lookup rejects tasks without hotel scope');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'Profile login task is missing hotel scope', 'Profile login task-id lookup exposes missing hotel scope as an explicit blocker');
requireText('app/controller/concern/OnlineDataRequestConcern.php', 'if (!$this->currentUserCanViewOnlineDataHotel($taskHotelId))', 'Profile login task-id lookup checks task hotel OTA view permission');
requireTextBefore('app/controller/concern/OnlineDataRequestConcern.php', 'if (!$this->currentUserCanViewOnlineDataHotel($taskHotelId))', "if (($task['platform'] ?? '') !== $platform)", 'Profile login task-id lookup checks hotel permission before exposing platform mismatch');
requireText('public/app-main.js', 'fetchCtripComments', 'frontend exposes Ctrip comment fetch');
requireText('public/app-main.js', 'runCtripCommentBrowserCapture', 'frontend exposes Ctrip browser comment capture');
requireText('public/app-main.js', 'fetchMeituanComments', 'frontend exposes Meituan comment fetch');
requireText('public/app-main.js', "`/online-data/profile-login-status/${platform}?${params.toString()}`", 'frontend polls Profile login task');
requireText('public/app-main.js', "`/online-data/collection-status?${params.toString()}`", 'frontend refreshes collection status');
requireText('public/app-main.js', "stale_running: '任务运行超时'", 'frontend labels stale running collection tasks');
requireText('public/app-main.js', "'session_expired', 'login_expired', 'anti_bot', 'resource_busy_login', 'cookies_incomplete', 'capture_failed', 'permission_denied', 'hotel_mismatch'", 'frontend blocks collection on explicit Profile failure states');
requireText('public/auto-fetch-static.js', "if (statusCode === 'cookies_incomplete')", 'auto-fetch UI handles cookies_incomplete');
requireText('public/auto-fetch-static.js', "if (statusCode === 'anti_bot')", 'auto-fetch UI handles anti_bot');
requireText('public/auto-fetch-static.js', "if (statusCode === 'resource_busy_login')", 'auto-fetch UI handles resource_busy_login');
requireText('public/auto-fetch-static.js', "cookies_incomplete: 'bg-red-50 text-red-700 border-red-200'", 'auto-fetch UI badges cookies_incomplete');
requireText('scripts/verify_ota_diagnosis_auto_fetch.mjs', 'Ctrip aggregate comments stay on browser Profile collection and legacy Cookie config is disabled', 'existing diagnosis verifier covers Ctrip aggregate comments');
requireText('scripts/verify_ota_diagnosis_auto_fetch.mjs', 'Meituan aggregate comments stay on browser Profile collection and reject Cookie/API config', 'existing diagnosis verifier covers Meituan aggregate comments');
requireText('package.json', '"verify:hotel-auto-x-non-pms-skills": "node scripts/verify_hotel_auto_x_non_pms_skill_contract.mjs && node scripts/verify_hotel_ota_login_eligibility_behavior.mjs"', 'package script runs static and behavior eligibility verifiers');
requireText('package.json', '"verify:hotel-ota-login-eligibility": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_hotel_ota_login_eligibility.php"', 'package script exposes hotel OTA login eligibility verifier');
requireText('package.json', '"verify:hotel-auto-x-ctrip-collector": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_hotel_auto_x_ctrip_collector_contract.php"', 'package script exposes Ctrip collector contract verifier');
requireText('package.json', '"verify:hotel-auto-x-meituan-collector": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_hotel_auto_x_meituan_collector_contract.php"', 'package script exposes Meituan collector contract verifier');

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
