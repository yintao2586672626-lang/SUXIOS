import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const frontend = readFileSync(new URL('../../public/index.html', import.meta.url), 'utf8');
const backend = readFileSync(new URL('../../app/controller/concern/OnlineDataRequestConcern.php', import.meta.url), 'utf8');
const autoFetchConcern = readFileSync(new URL('../../app/controller/concern/AutoFetchConcern.php', import.meta.url), 'utf8');
const profileLoginCommand = readFileSync(new URL('../../app/command/PlatformProfileLogin.php', import.meta.url), 'utf8');

const extractPhpMethod = (source, name) => {
  const start = source.indexOf(`private function ${name}`);
  assert.notEqual(start, -1, `missing PHP method: ${name}`);
  const bodyStart = source.indexOf('{', start);
  assert.notEqual(bodyStart, -1, `missing PHP method body: ${name}`);
  let depth = 0;
  for (let index = bodyStart; index < source.length; index += 1) {
    if (source[index] === '{') depth += 1;
    if (source[index] === '}') depth -= 1;
    if (depth === 0) return source.slice(start, index + 1);
  }
  throw new Error(`unterminated PHP method: ${name}`);
};

test('platform Profile login launches only from the account owner loopback browser', () => {
  assert.match(frontend, /const canLaunchLocalPlatformProfileBrowser = \(\) =>/);
  assert.match(frontend, /\['127\.0\.0\.1', 'localhost', '::1'\]\.includes\(hostname\)/);
  assert.match(frontend, /if \(!canLaunchLocalPlatformProfileBrowser\(\)\)/);
  assert.match(frontend, /const platformProfileLoginSubmitting = \{ ctrip: false, meituan: false \}/);
  assert.match(frontend, /platformProfileLoginSubmitting\[platform\] \|\| platformProfileLoginRunning\(platform\)/);
  assert.match(frontend, /platformProfileLoginSubmitting\[platform\] = false/);
  assert.match(frontend, /\/online-data\/profile-login-trigger\/\$\{platform\}/);
  assert.match(frontend, /pollPlatformProfileLoginStatus\(platform, task\.task_id\)/);

  assert.match(backend, /private function isLocalPlatformProfileLoginRequest\(\): bool/);
  assert.match(backend, /\['127\.0\.0\.1', '::1', '::ffff:127\.0\.0\.1'\]/);
  assert.match(backend, /client_local_authorization_required/);
  assert.match(backend, /server_browser_launch_disabled/);
  assert.match(backend, /launchPlatformProfileLoginTask\(\$task\)/);
  assert.match(backend, /resource_busy_login/);
  assert.match(backend, /allow_existing_local_profile_rebind/);
  assert.match(backend, /专用 Profile 浏览器正在打开/);
});

test('Windows Profile login launcher confirms the background task really started', () => {
  const powershellLauncher = extractPhpMethod(autoFetchConcern, 'launchWindowsBatchFileWithPowerShell');
  const profileLauncher = extractPhpMethod(autoFetchConcern, 'launchPlatformProfileLoginTask');
  assert.match(autoFetchConcern, /launchWindowsBatchFileWithPowerShell\(\$batPath\)/);
  assert.match(powershellLauncher, /Start-Process -FilePath/);
  assert.match(powershellLauncher, /"@\('\/d', '\/c', 'call', "/);
  assert.match(powershellLauncher, /-WindowStyle Hidden -PassThru/);
  assert.match(profileLauncher, /waitForPlatformProfileLoginTaskStart\(\$taskId\)/);
  assert.match(autoFetchConcern, /\$status !== '' && \$status !== 'queued'/);
  assert.match(profileLauncher, /Profile login process did not leave queued state after launch/);
});

test('Windows auto-fetch launcher does not wait on the unrelated Profile login task cache', () => {
  const launcher = extractPhpMethod(autoFetchConcern, 'launchAutoFetchBackgroundTask');
  assert.match(launcher, /return \$this->launchWindowsBatchFile\(\$batPath\);/);
  assert.doesNotMatch(launcher, /waitForPlatformProfileLoginTaskStart/);
  assert.doesNotMatch(launcher, /Profile login process did not leave queued state/);
});

test('Profile login cannot report success when verified proof persistence fails', () => {
  assert.match(profileLoginCommand, /profile_login_persistence_failed/);
  assert.match(profileLoginCommand, /if \(\$bindDataSourceRequested && !is_array\(\$dataSource\)\)/);
  assert.match(profileLoginCommand, /登录页验证已通过，但 Profile 绑定或登录证明保存失败/);
});

test('successful local Profile login keeps only a five second flush window', () => {
  assert.match(autoFetchConcern, /post_login_wait_ms[^\n]+\?\? 5000/);
  assert.doesNotMatch(autoFetchConcern, /post_login_wait_ms[^\n]+\?\? 120000/);
  assert.match(profileLoginCommand, /post_login_wait_ms[^\n]+\?\? 5000/);
  assert.doesNotMatch(profileLoginCommand, /post_login_wait_ms[^\n]+\?\? 120000/);
  assert.match(autoFetchConcern, /浏览器已打开，自动检测登录中/);
  assert.match(profileLoginCommand, /系统每 3 秒自动检测；登录成功后通常 10–15 秒内自动保存/);
  assert.match(frontend, /登录成功后通常 10–15 秒内自动保存/);
});

test('Profile login monitoring survives a page refresh and resumes the active hotel task', () => {
  assert.match(autoFetchConcern, /platformProfileLoginHotelCurrentCacheKey/);
  assert.match(autoFetchConcern, /readPlatformProfileLoginHotelCurrentTask/);
  assert.match(autoFetchConcern, /'login_tasks'\s*=>\s*\$loginTasks/);
  assert.match(profileLoginCommand, /profileLoginHotelCurrentTaskKey/);
  assert.match(profileLoginCommand, /Cache::set\(\$this->profileLoginHotelCurrentTaskKey\(\$platform, \$hotelId\), \$merged, 86400\)/);
  assert.match(frontend, /resumePlatformProfileLoginTasks\(nextStatus\)/);
  assert.match(frontend, /const resumePlatformProfileLoginTasks = \(status\) =>/);
  assert.match(frontend, /pollPlatformProfileLoginStatus\(platform, task\.task_id\)/);
});
