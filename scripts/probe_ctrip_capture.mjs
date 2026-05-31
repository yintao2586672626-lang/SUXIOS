import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import process from 'node:process';
import { parseArgs, safeName, timestamp } from './lib/shared_helpers.mjs';

const args = parseArgs(process.argv.slice(2));
const profileId = stringValue(args.profileId || args.hotelId || args.systemHotelId || 'hotel_001');
const sections = stringValue(args.sections || args.captureSections || 'wide');
const loginOnly = boolArg(args.loginOnly) || boolArg(args.authOnly) || boolArg(args.prepareProfile);
const output = resolve(stringValue(args.output) || `runtime/ctrip_capture/ctrip_probe_${safeName(profileId)}_${timestamp()}.json`);
const summaryOutput = resolve(stringValue(args.summaryOutput) || output.replace(/\.json$/i, '.summary.json'));
const summaryMarkdown = resolve(stringValue(args.summaryMarkdown) || output.replace(/\.json$/i, '.summary.md'));

ensureParent(output);
ensureParent(summaryOutput);
ensureParent(summaryMarkdown);

const captureArgs = [
  'scripts/ctrip_browser_capture.mjs',
  `--profile-id=${profileId}`,
  `--sections=${sections}`,
  `--output=${output}`,
  `--login-timeout-ms=${stringValue(args.loginTimeoutMs || 300000)}`,
];
if (loginOnly) {
  captureArgs.push('--login-only=true');
}
for (const [sourceKey, targetKey] of [
  ['hotelId', 'hotel-id'],
  ['systemHotelId', 'system-hotel-id'],
  ['dataDate', 'data-date'],
  ['hotelName', 'hotel-name'],
  ['cookiesFile', 'cookies-file'],
  ['cookieFile', 'cookie-file'],
  ['chromePath', 'chrome-path'],
  ['headless', 'headless'],
]) {
  const value = stringValue(args[sourceKey]);
  if (value) {
    captureArgs.push(`--${targetKey}=${value}`);
  }
}

const captureResult = spawnSync(process.execPath, captureArgs, {
  cwd: process.cwd(),
  encoding: 'utf8',
  windowsHide: false,
  stdio: ['inherit', 'pipe', 'pipe'],
});
if (captureResult.stdout) {
  process.stdout.write(captureResult.stdout);
}
if (captureResult.stderr) {
  process.stderr.write(captureResult.stderr);
}

if (!existsSync(output)) {
  console.log(JSON.stringify({
    status: 'failed',
    reason: 'capture_output_missing',
    output,
    capture_exit_code: captureResult.status,
  }, null, 2));
  process.exitCode = 2;
  process.exit();
}

const summaryResult = spawnSync(process.execPath, [
  'scripts/summarize_ctrip_capture_result.mjs',
  `--input=${output}`,
  `--output=${summaryOutput}`,
  `--markdown=${summaryMarkdown}`,
], {
  cwd: process.cwd(),
  encoding: 'utf8',
  windowsHide: true,
});
if (summaryResult.stderr) {
  process.stderr.write(summaryResult.stderr);
}

const capturePayload = readJson(output);
const summary = readJson(summaryOutput);
const authOk = Boolean(summary.auth_status?.ok);
const standardRows = Number(summary.counts?.standard_rows || 0);
const finalStatus = loginOnly && authOk
  ? 'login_prepared'
  : authOk && standardRows > 0
    ? 'ready'
    : 'not_ready';

console.log(JSON.stringify({
  status: finalStatus,
  output,
  summary_output: summaryOutput,
  summary_markdown: summaryMarkdown,
  capture_exit_code: captureResult.status,
  auth_status: summary.auth_status,
  capture_gate: capturePayload.capture_gate || null,
  counts: summary.counts,
  diagnosis_groups: summary.diagnosis_groups,
}, null, 2));

process.exitCode = finalStatus === 'ready' || finalStatus === 'login_prepared' ? 0 : 2;

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function readJson(path) {
  return JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
}

function boolArg(value) {
  if (value === true) {
    return true;
  }
  return ['1', 'true', 'yes', 'y', 'on'].includes(String(value ?? '').trim().toLowerCase());
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value).trim();
}
