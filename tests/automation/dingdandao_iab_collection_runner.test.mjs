import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const runnerUrl = new URL('../../scripts/run_dingdandao_iab_collection.php', import.meta.url);
const runnerPath = fileURLToPath(runnerUrl);
const repoRoot = fileURLToPath(new URL('../../', import.meta.url));
const runner = readFileSync(runnerUrl, 'utf8');
const packageJson = JSON.parse(readFileSync(
  new URL('../../package.json', import.meta.url),
  'utf8',
));
const phpBinary = process.env.SUXI_PHP_BINARY
  || (process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php');
const phpProbe = spawnSync(phpBinary, ['-v'], { encoding: 'utf8', timeout: 10_000 });
const phpAvailable = phpProbe.status === 0 && !phpProbe.error;

const runRunner = (args, input = '') => spawnSync(
  phpBinary,
  [runnerPath, ...args],
  {
    cwd: repoRoot,
    encoding: 'utf8',
    input,
    timeout: 10_000,
    windowsHide: true,
  },
);

const blockedPayload = (result) => {
  assert.equal(result.signal, null);
  assert.equal(result.stdout.trim(), '');
  assert.equal(result.error, undefined);
  return JSON.parse(result.stderr.trim());
};

test('IAB supplement runner is registered as an explicit local import command', () => {
  assert.equal(
    packageJson.scripts['import:dingdandao-iab-supplement'],
    'C:\\xampp\\php\\php.exe scripts\\run_dingdandao_iab_collection.php',
  );
});
test('registered PHP runner actually starts and rejects missing identity before database access', {
  skip: !phpAvailable,
}, () => {
  const result = runRunner([], '{}');
  assert.equal(result.status, 2);
  assert.deepEqual(blockedPayload(result), {
    status: 'blocked',
    reason: 'dingdandao_iab_hotel_id_invalid',
    raw_response_exposed: false,
    session_material_exposed: false,
    sensitive_values_exposed: false,
  });
});

test('registered PHP runner rejects an empty bounded payload before database access', {
  skip: !phpAvailable,
}, () => {
  const targetDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
  const result = runRunner([
    '--hotel-id=1',
    '--owner-user-id=1',
    `--target-date=${targetDate}`,
  ]);
  assert.equal(result.status, 2);
  assert.equal(blockedPayload(result).reason, 'dingdandao_iab_input_invalid');
});

test('IAB Dingdandao runner accepts only bounded stdin through the dedicated normalizer', () => {
  assert.match(runner, /DINGDANDAO_IAB_MAX_INPUT_BYTES\s*=\s*2_000_000/);
  assert.match(runner, /stream_get_contents\(STDIN,/);
  assert.match(runner, /normalize_dingdandao_iab_capture\.mjs/);
  assert.match(runner, /\['bypass_shell'\s*=>\s*true\]/);
  assert.match(runner, /fwrite\(\$pipes\[0\],\s*\$input\)/);
  assert.match(runner, /normalized_browser_response_supplement/);
  assert.match(runner, /record_count'\]\s*\?\?\s*0\)\s*!==\s*6/);
  assert.doesNotMatch(runner, /cdp-url|Storage\.getCookies|DOMStorage|localStorage/i);
});

test('IAB Dingdandao runner derives hotel identity and permission from local bindings', () => {
  assert.match(runner, /Db::name\('hotels'\)/);
  assert.match(runner, /User::where\('id',\s*\$ownerUserId\)->find\(\)/);
  assert.match(runner, /PermissionService\(\)\)->authorize\([\s\S]*'ota\.collect'/);
  assert.match(runner, /captureExpectation/);
  assert.match(runner, /expected_provider_hotel_name/);
  assert.match(runner, /expected_provider_hotel_id/);
  assert.match(runner, /dingdandao_iab_provider_binding_missing/);
});

test('IAB runner saves exact responses as unverified and blocks formal sync', () => {
  const normalizeIndex = runner.indexOf('iabRunNormalizer');
  const saveIndex = runner.indexOf('DingdandaoOperatingTargetCaptureService())->save');
  const readbackIndex = runner.indexOf('dingdandao_iab_readback_not_verified');
  const claimIndex = runner.indexOf('validateDingdandaoCaptureClaim');

  assert.ok(normalizeIndex >= 0);
  assert.ok(saveIndex > normalizeIndex);
  assert.ok(readbackIndex > saveIndex);
  assert.ok(claimIndex > readbackIndex);
  assert.match(runner, /capture_strategy'\]\s*\?\?\s*''\)\s*!==\s*'browser_response_supplement'/);
  assert.match(runner, /operator_supplied_browser_response/);
  assert.match(runner, /\$captureInput,\s*false,\s*\$expectedProviderHotelId,\s*false/s);
  assert.match(runner, /'quality_status'\]\s*\?\?\s*''\)\s*!==\s*'unverified'/);
  assert.match(runner, /'collection_contract_status'\s*=>\s*'supplemental_unverified'/);
  assert.match(runner, /'execution_mode'\s*=>\s*'iab_browser_response_supplement'/);
  assert.match(runner, /blocked_by_unverified_source/);
  assert.doesNotMatch(runner, /->prefill\(|syncVerifiedCapture/);
});

test('IAB Dingdandao runner never pushes or exposes session material', () => {
  assert.match(runner, /'push'\s*=>\s*\[/);
  assert.match(runner, /'requested'\s*=>\s*false/);
  assert.match(runner, /'delivery_attempted'\s*=>\s*false/);
  assert.match(runner, /raw_response_exposed'\s*=>\s*false/);
  assert.match(runner, /session_material_exposed'\s*=>\s*false/);
  assert.match(runner, /sensitive_values_exposed'\s*=>\s*false/);
  assert.doesNotMatch(runner, /dispatchVerifiedCapture|cookieHeader|Authorization:\s*Bearer|token/i);
});
