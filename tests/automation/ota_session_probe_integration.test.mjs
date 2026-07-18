import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const ctrip = readFileSync(new URL('../../scripts/ctrip_browser_capture.mjs', import.meta.url), 'utf8');
const meituan = readFileSync(new URL('../../scripts/meituan_browser_capture.mjs', import.meta.url), 'utf8');
const profileConcern = readFileSync(new URL('../../app/controller/concern/PlatformProfileCaptureConcern.php', import.meta.url), 'utf8');
const profileLogin = readFileSync(new URL('../../app/command/PlatformProfileLogin.php', import.meta.url), 'utf8');
const sessionProofService = readFileSync(new URL('../../app/service/OtaProfileSessionProofService.php', import.meta.url), 'utf8');
const autoFetch = readFileSync(new URL('../../app/controller/concern/AutoFetchConcern.php', import.meta.url), 'utf8');
const publicAutoFetch = readFileSync(new URL('../../public/auto-fetch-static.js', import.meta.url), 'utf8');
const onlineDataRequest = readFileSync(new URL('../../app/controller/concern/OnlineDataRequestConcern.php', import.meta.url), 'utf8');
const otaCaptureStandard = readFileSync(new URL('../../scripts/lib/ota_capture_standard.mjs', import.meta.url), 'utf8');

const extractFunction = (source, name) => {
  const start = source.indexOf(`function ${name}`);
  assert.notEqual(start, -1, `missing function ${name}`);
  const open = source.indexOf('{', start);
  let depth = 0;
  for (let index = open; index < source.length; index += 1) {
    if (source[index] === '{') depth += 1;
    if (source[index] === '}') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }
  throw new Error(`unterminated function ${name}`);
};

test('Ctrip and Meituan expose a non-interactive session-probe mode without cookie injection', () => {
  for (const [name, source] of [['Ctrip', ctrip], ['Meituan', meituan]]) {
    assert.match(source, /const sessionProbeOnly = booleanArg\(args\.sessionProbeOnly\)/, `${name} must parse sessionProbeOnly`);
    assert.match(source, /const authOnly = sessionProbeOnly \|\| loginOnly/, `${name} must keep probe and login as explicit auth-only modes`);
    assert.match(source, /sessionProbeOnly \? 'session_probe_only'/, `${name} payload must identify probe-only mode`);
    assert.match(source, /sessionProbeOnly\s*\? \{ attempted: false, injected_count: 0, domains: \[\], reason: 'session_probe_only' \}/, `${name} probe must not inject cookies`);
    assert.match(source, /ensureLoggedIn\(page, \{ interactive: !sessionProbeOnly \}\)/, `${name} probe must be non-interactive`);
    assert.match(source, /evaluateOtaSessionProbe\(/, `${name} must use the shared evidence contract`);
  }
});

test('Ctrip writes the probe result even when the preliminary auth heuristic fails', () => {
  const failureBranch = ctrip.indexOf('if (!loginStatus.ok)');
  const finalizeBranch = ctrip.indexOf('if (authOnly) {\n    await finalizeLoginOnlyPayload();');
  assert.notEqual(failureBranch, -1);
  assert.notEqual(finalizeBranch, -1);
  assert.ok(finalizeBranch > failureBranch, 'auth-only finalization must run after either preliminary auth outcome');
  assert.match(ctrip, /payload\.session_probe\.status === 'anti_bot'[\s\S]*retry_after_seconds/);
  assert.match(ctrip, /status: probePassed \? 'pass' : 'fail'[\s\S]*reason: 'session_probe_only'/);
  assert.match(ctrip, /probePassed && payload\.auth_status\?\.ok !== true[\s\S]*status: 'authorized'/);
  assert.match(ctrip, /process\.exitCode = probePassed \? 0 : 2/);
  assert.match(meituan, /payload\.session_probe\.collectable === true && payload\.auth_status\?\.ok !== true[\s\S]*status: 'authorized'/);
  assert.match(meituan, /payload\.capture_gate\.status !== 'pass'[\s\S]*process\.exitCode = 2;[\s\S]*else if \(authOnly\)[\s\S]*process\.exitCode = 0/);
  assert.doesNotMatch(ctrip, /configured_url:\s*ctripLoginEntryUrl\(\)/, 'custom login URLs must never be persisted with query material');
  assert.match(ctrip, /configured_url:\s*sanitizeObservedPageUrl\(ctripLoginEntryUrl\(\)\)/);
});

test('session probes observe only response metadata and never collect response bodies', () => {
  for (const [name, source] of [['Ctrip', ctrip], ['Meituan', meituan]]) {
    assert.match(source, /if \(authOnly\) \{\s*registerSessionProbeResponseObserver\(page\);\s*\} else \{\s*registerResponseCapture\(page, payload/);
    const observer = extractFunction(source, 'registerSessionProbeResponseObserver');
    assert.match(observer, /classifyOtaSessionProbeResponse\(/, `${name} probe must use the diagnostic protected-endpoint classifier`);
    assert.match(observer, /candidate_drift_response_count/, `${name} probe must retain bounded drift counts`);
    assert.doesNotMatch(observer, /response\.text\(|postData\(|responses\.push|rows\.push|data:/, `${name} probe observer must not retain business payloads`);
  }
});

test('capture Cookie domains and Ctrip host checks reuse the shared Session contract', () => {
  assert.match(otaCaptureStandard, /cookieDomains: otaSessionCookieInjectionDomains\('meituan'\)/);
  assert.match(otaCaptureStandard, /cookieDomains: otaSessionCookieInjectionDomains\('ctrip'\)/);
  assert.match(ctrip, /return platform === 'ctrip' \? otaSessionCookieInjectionDomains\('ctrip'\) : \[\]/);
  assert.match(ctrip, /return isTrustedOtaPlatformUrl\('ctrip', url\)/);
  assert.doesNotMatch(extractFunction(ctrip, 'isCtripCaptureUrl'), /includes\('ctrip\.com'\)/);
});

test('status checks use session-probe-only while visible manual login remains interactive', () => {
  const probeFlags = profileConcern.match(/'--session-probe-only=true'/g) || [];
  assert.equal(probeFlags.length, 2, 'both OTA status probes must use the non-interactive mode');
  assert.match(profileLogin, /'--login-only=true'/, 'manual Profile login must retain its visible login mode');
  assert.match(profileLogin, /'--headless=false'/, 'manual Profile login must remain visible');
  assert.match(profileLogin, /isCollectableProfileLoginSessionProbe\(\$sessionProbe\)/, 'manual login must require account-level collectability');
  assert.match(profileLogin, /isStrongProfileLoginSessionProbe\(\$sessionProbe\)/, 'hotel-level login proof must require standardized identity evidence');
});

test('PHP bridge preserves anti-bot backoff and never treats it as a login action', () => {
  assert.match(profileLogin, /'retry_after_seconds' => max\(0, \(int\)\(\$probe\['retry_after_seconds'\]/);
  assert.match(profileLogin, /foreach \(\['status', 'mode', 'reason', 'next_retry_at'\] as \$key\)/);
  assert.match(autoFetch, /assertPlatformProfileLoginBackoffClear/);
  assert.match(autoFetch, /'anti_bot' => \['wait_platform_risk_control', '查看风控与退避状态', 'sync-logs'\]/);
  assert.doesNotMatch(autoFetch, /'anti_bot' => \['login_platform_profile'/);
  assert.match(autoFetch, /profile_risk_control_manual_review_required|wait_platform_risk_control/);
});

test('platform contract drift remains a distinct manual-calibration state across PHP and UI actions', () => {
  assert.match(profileConcern, /\$probeStatus === 'platform_contract_drift'[\s\S]*return 'platform_contract_drift'/);
  assert.match(profileLogin, /\$probeStatus === 'platform_contract_drift'[\s\S]*return 'platform_contract_drift'/);
  assert.match(profileLogin, /'drift_diagnostics'/);
  assert.match(autoFetch, /'platform_contract_drift' => '平台规则疑似变化'/);
  assert.match(autoFetch, /'platform_contract_drift' => \['open_sync_logs', '查看规则漂移证据', 'sync-logs'\]/);
  assert.doesNotMatch(autoFetch, /'platform_contract_drift' => \['login_platform_profile'/);
  assert.match(publicAutoFetch, /platform_contract_drift: '平台规则疑似变化'/);
  assert.match(publicAutoFetch, /statusCode === 'platform_contract_drift'\) return '查看规则漂移证据'/);
  assert.match(publicAutoFetch, /statusCode === 'platform_contract_drift'\) return '平台返回与当前识别规则不一致；先查看同步日志并校准规则，不要反复登录。'/);
});

test('permission denial remains distinct from login expiry across probe, PHP and UI', () => {
  for (const [name, source] of [['Ctrip', ctrip], ['Meituan', meituan]]) {
    assert.match(source, /authentication_required_response_count/, `${name} must count HTTP 401 separately`);
    assert.match(source, /permission_denied_response_count/, `${name} must count HTTP 403 separately`);
  }
  assert.match(profileConcern, /\$probeStatus === 'permission_denied'[\s\S]*return 'permission_denied'/);
  assert.match(profileLogin, /\$probeStatus === 'permission_denied'[\s\S]*return 'permission_denied'/);
  assert.match(autoFetch, /'permission_denied' => '平台权限不足'/);
  assert.match(autoFetch, /'permission_denied' => \['open_sync_logs', '查看权限诊断', 'sync-logs'\]/);
  assert.doesNotMatch(autoFetch, /'permission_denied' => \['login_platform_profile'/);
  assert.match(publicAutoFetch, /permission_denied: '无权限'/);
});

test('legacy probe contracts are diagnosed as drift instead of login expiry', () => {
  assert.match(sessionProofService, /profileLoginSessionProbeContractStatus/);
  assert.match(profileConcern, /profileLoginSessionProbeContractStatus\(\$sessionProbe\) === 'platform_contract_drift'/);
  assert.match(profileLogin, /\$sessionContractStatus === 'platform_contract_drift'/);
  assert.match(profileLogin, /\$contractDrift \? 'platform_contract_drift' : ''/);
});

test('Ctrip status cache keeps the structured probe instead of overwriting it', () => {
  assert.match(onlineDataRequest, /cachePlatformProfileStatus\('ctrip'[\s\S]*'session_probe' => \$status\['session_probe'\] \?\? null/);
});

test('weak evidence cannot become a verified Profile proof', () => {
  assert.match(sessionProofService, /\$evidenceLevel === 'strong'/);
  assert.match(sessionProofService, /\(\$sessionProbe\['sensitive_values_exposed'\] \?\? true\) === false/);
  assert.match(sessionProofService, /\$identitySignal\['hotel_scope_verified'\]/);
  assert.match(profileLogin, /in_array\(\$probeStatus, \['weak_evidence', 'probe_failed'\], true\)/);
  assert.match(profileConcern, /isStrongProfileLoginSessionProbe\(\$sessionProbe\)/);
  assert.match(onlineDataRequest, /evaluateBrowserProfileLoginPayload\(\$payload\)/, 'legacy capture endpoints must use the same session contract');
  assert.match(onlineDataRequest, /hotel_identity_unverified[\s\S]*bindVerifiedBrowserProfileDataSource/, 'identity-unverified login must not bind a data source');
});
