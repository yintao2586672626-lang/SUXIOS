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

const captureStartMs = Date.now();
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
const captureElapsedMs = Date.now() - captureStartMs;
const captureFailure = extractSpawnFailure(captureResult);

if (!existsSync(output)) {
  console.log(JSON.stringify({
    status: 'failed',
    reason: 'capture_output_missing',
    output,
    capture_exit_code: captureResult.status,
    capture_error: captureFailure,
    capture_elapsed_ms: captureElapsedMs,
    capture_stdout_tail: captureResult.stdout
      ? String(captureResult.stdout).slice(-2048)
      : '',
    capture_stderr_tail: captureResult.stderr
      ? String(captureResult.stderr).slice(-2048)
      : '',
  }, null, 2));
  process.exitCode = 2;
  process.exit();
}

const summaryStartMs = Date.now();
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
const summaryElapsedMs = Date.now() - summaryStartMs;
if (summaryResult.stderr) {
  process.stderr.write(summaryResult.stderr);
}
const summaryFailure = extractSpawnFailure(summaryResult);

if (!existsSync(summaryOutput)) {
  console.log(JSON.stringify({
    status: 'failed',
    reason: 'summary_output_missing',
    output,
    summary_output: summaryOutput,
    capture_exit_code: captureResult.status,
    capture_error: captureFailure,
    summary_exit_code: summaryResult.status,
    summary_error: summaryFailure,
    capture_elapsed_ms: captureElapsedMs,
    summary_elapsed_ms: summaryElapsedMs,
  }, null, 2));
  process.exitCode = 2;
  process.exit();
}

let capturePayload;
let summary;
try {
  capturePayload = readJson(output);
  summary = readJson(summaryOutput);
} catch (error) {
  console.log(JSON.stringify({
    status: 'failed',
    reason: 'output_parse_error',
    output,
    summary_output: summaryOutput,
    error: {
      message: error?.message || String(error),
      name: error?.name,
    },
  }, null, 2));
  process.exitCode = 2;
  process.exit();
}
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
  capture_error: captureFailure,
  capture_elapsed_ms: captureElapsedMs,
  auth_status: summary.auth_status,
  capture_gate: capturePayload.capture_gate || null,
  counts: summary.counts,
  diagnosis_groups: summary.diagnosis_groups,
  summary_exit_code: summaryResult.status,
  summary_error: summaryFailure,
  summary_elapsed_ms: summaryElapsedMs,
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

function extractSpawnFailure(result) {
  if (!result || !result.error) {
    return null;
  }
  const error = result.error;
  if (error instanceof Error) {
    return {
      message: error.message || String(error),
      name: error.name || 'Error',
      code: error.code,
      errno: error.errno,
      syscall: error.syscall,
    };
  }
  return { message: String(error) };
}
