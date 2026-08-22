import { randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { startLocalOriginServer } from '../../scripts/local_origin_server.mjs';
import { formatHealthFailure } from './e2e-health-diagnostics.mjs';

const root = process.cwd();
const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
const helper = path.join(root, 'tests', 'automation', 'e2e-isolation-helper.php');
const performanceOnly = process.argv.includes('--performance-only');
const e2eHelperTimeoutMs = 10_000;

function loopbackBaseURL(value) {
  const parsed = new URL(value);
  const hostname = parsed.hostname.toLowerCase();
  const allowedHosts = new Set(['127.0.0.1', 'localhost', '[::1]', '::1']);
  if (parsed.protocol !== 'http:' || !allowedHosts.has(hostname) || parsed.username || parsed.password) {
    throw new Error('Isolated E2E only permits an unauthenticated http loopback base URL');
  }
  return parsed.toString();
}

const configuredDedicatedDatabase = String(process.env.SUXI_E2E_DB_NAME || '').trim();
const dedicatedDatabaseName = configuredDedicatedDatabase !== ''
  ? configuredDedicatedDatabase
  : performanceOnly ? 'hotelx_performance_e2e' : 'hotelx_e2e';
if (!/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/i.test(dedicatedDatabaseName)) {
  throw new Error('Isolated E2E requires a dedicated *_test/*_testing/*_e2e database name');
}
const selfHosted = true;
const appPort = Number(process.env.SUXI_E2E_APP_PORT || 18080);
if (!Number.isInteger(appPort) || appPort < 1024 || appPort > 65535) {
  throw new Error('SUXI_E2E_APP_PORT must be an integer between 1024 and 65535');
}
const backendPort = Number(process.env.SUXI_E2E_BACKEND_PORT || (appPort < 65535 ? appPort + 1 : appPort - 1));
if (!Number.isInteger(backendPort)
  || backendPort < 1024
  || backendPort > 65535
  || backendPort === appPort) {
  throw new Error('SUXI_E2E_BACKEND_PORT must be a different integer between 1024 and 65535');
}
const baseURL = loopbackBaseURL(
  process.env.E2E_BASE_URL || `http://127.0.0.1:${selfHosted ? appPort : 8080}/`,
);
const baseURLDetails = new URL(baseURL);
if (selfHosted) {
  if (!['127.0.0.1', 'localhost'].includes(baseURLDetails.hostname.toLowerCase())) {
    throw new Error('Self-hosted isolated E2E only permits 127.0.0.1 or localhost');
  }
  const effectivePort = Number(baseURLDetails.port || 80);
  if (effectivePort !== appPort || baseURLDetails.pathname !== '/' || baseURLDetails.search || baseURLDetails.hash) {
    throw new Error('E2E_BASE_URL must match SUXI_E2E_APP_PORT and use the loopback root path');
  }
}
const objectPrefix = `codex_e2e_${Date.now().toString(36)}_${randomBytes(6).toString('hex')}`;
const isolatedStateParent = path.resolve(root, 'runtime', 'e2e-state');
const isolatedStateRoot = path.resolve(isolatedStateParent, objectPrefix);
if (path.dirname(isolatedStateRoot) !== isolatedStateParent) {
  throw new Error('Refusing to use an E2E state path outside runtime/e2e-state');
}
const isolatedCachePath = path.join(isolatedStateRoot, 'cache');
const isolatedLockPath = path.join(isolatedStateRoot, 'locks');
mkdirSync(isolatedCachePath, { recursive: true });
mkdirSync(isolatedLockPath, { recursive: true });
const e2eProcessEnv = selfHosted
  ? {
      ...process.env,
      DB_NAME: dedicatedDatabaseName,
      SUXI_E2E_DB_NAME: dedicatedDatabaseName,
      SUXI_E2E_DB_OVERRIDE: '1',
      SUXIOS_CACHE_PATH: isolatedCachePath,
      SUXIOS_LOCAL_LOCK_PATH: isolatedLockPath,
    }
  : { ...process.env };
const password = `${randomBytes(36).toString('base64url')}Aa1!`;
const businessOnly = process.argv.includes('--business-only');
const dailyOnly = process.argv.includes('--daily-only');
const otaOnly = process.argv.includes('--ota-only');
const temporalOnly = process.argv.includes('--temporal-only');
const preflightOnly = process.argv.includes('--preflight-only');
const asyncOnly = process.argv.includes('--async-only');
const edgeOnly = process.argv.includes('--edge-only');
const uiOnly = process.argv.includes('--ui-only');
const moduleOnly = process.argv.includes('--module-only');
const publicPageOnly = process.argv.includes('--public-page-only');
const operatingQuestionOnly = process.argv.includes('--operating-question-only');
const transitionOnly = process.argv.includes('--transition-only');
const stabilityOnly = process.argv.includes('--stability-only');
const fullClick = process.argv.includes('--full-click') || process.argv.includes('--full-click-bounded');
const fullClickBounded = process.argv.includes('--full-click-bounded');
const codexProfileArg = process.argv.find((arg) => arg.startsWith('--codex-profile='));
const codexIterationsArg = process.argv.find((arg) => arg.startsWith('--codex-iterations='));
const performanceIterationsArg = process.argv.find((arg) => arg.startsWith('--performance-iterations='));
const performanceNetworkArg = process.argv.find((arg) => arg.startsWith('--performance-network='));
const performanceLabelArg = process.argv.find((arg) => arg.startsWith('--performance-label='));
const performanceEnforceBudgetArg = process.argv.find((arg) => arg.startsWith('--performance-enforce-budget='));
const codexProfile = codexProfileArg ? codexProfileArg.slice('--codex-profile='.length).trim() : '';
const codexIterations = codexIterationsArg ? codexIterationsArg.slice('--codex-iterations='.length).trim() : '';
const performanceIterations = performanceIterationsArg
  ? performanceIterationsArg.slice('--performance-iterations='.length).trim()
  : '5';
const performanceNetwork = performanceNetworkArg
  ? performanceNetworkArg.slice('--performance-network='.length).trim()
  : 'none';
const performanceLabel = performanceLabelArg
  ? performanceLabelArg.slice('--performance-label='.length).trim()
  : 'isolated-authenticated-baseline';
const performanceEnforceBudget = performanceEnforceBudgetArg
  ? performanceEnforceBudgetArg.slice('--performance-enforce-budget='.length).trim()
  : '1';
if (codexProfile && !['quick', 'extreme'].includes(codexProfile)) {
  throw new Error('--codex-profile must be quick or extreme');
}
if (codexIterations && (!/^\d+$/.test(codexIterations) || Number(codexIterations) < 1)) {
  throw new Error('--codex-iterations must be a positive integer');
}
if (!/^\d+$/.test(performanceIterations)
  || Number(performanceIterations) < 1
  || Number(performanceIterations) > 30) {
  throw new Error('--performance-iterations must be an integer between 1 and 30');
}
if (!['none', 'slow-4g'].includes(performanceNetwork)) {
  throw new Error('--performance-network must be none or slow-4g');
}
if (!/^[a-zA-Z0-9._-]+$/.test(performanceLabel)) {
  throw new Error('--performance-label must contain only letters, numbers, dot, underscore, or hyphen');
}
if (!['0', '1'].includes(performanceEnforceBudget)) {
  throw new Error('--performance-enforce-budget must be 0 or 1');
}
const specs = operatingQuestionOnly
  ? [
      'tests/automation/operating_question_action_card.spec.js',
      'tests/automation/operating_question_floating.spec.js',
    ]
  : stabilityOnly
  ? [
      'tests/automation/ota-auth-strong-reminder.spec.js',
      'tests/automation/security_monitoring_page.spec.js',
    ]
  : transitionOnly
  ? ['tests/automation/frontend_full_render_transition.spec.js']
  : fullClick
  ? ['tests/automation/full-click-coverage.spec.js']
  : publicPageOnly
    ? ['tests/automation/public-page-task-bridge.spec.js']
  : moduleOnly
    ? ['tests/automation/module-smoke.spec.js']
    : asyncOnly
      ? ['tests/automation/async-page-guard.spec.js']
      : edgeOnly
        ? ['tests/automation/edge-input-guard.spec.js']
        : uiOnly
          ? ['tests/automation/daily-regression.spec.js', 'tests/automation/edge-input-guard.spec.js']
          : temporalOnly
  ? ['tests/automation/temporal-axis.spec.js']
  : businessOnly
  ? ['tests/automation/business-chains.spec.js']
  : dailyOnly
    ? ['tests/automation/daily-regression.spec.js']
    : [
        'tests/automation/daily-regression.spec.js',
        'tests/automation/business-chains.spec.js',
      ];
let seededHotelId = 0;
let seededUserId = 0;
let seededTenantId = 0;

function parseHelperOutput(action, result) {
  if (result.error) {
    throw new Error(`E2E isolation helper ${action} could not start: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const detail = String(result.stderr || result.stdout || '').trim().slice(0, 1000);
    throw new Error(`E2E isolation helper ${action} failed${detail ? `: ${detail}` : ''}`);
  }
  try {
    return JSON.parse(String(result.stdout || '').trim());
  } catch {
    throw new Error(`E2E isolation helper ${action} returned invalid JSON`);
  }
}

function runHelper(action) {
  const env = {
    ...e2eProcessEnv,
    SUXI_E2E_PREFIX: objectPrefix,
  };
  if (seededHotelId > 0) {
    env.SUXI_E2E_HOTEL_ID = String(seededHotelId);
  }
  if (seededUserId > 0) {
    env.SUXI_E2E_USER_ID = String(seededUserId);
  }
  if (seededTenantId > 0) {
    env.SUXI_E2E_TENANT_ID = String(seededTenantId);
  }
  if (action === 'seed') {
    env.SUXI_E2E_PASSWORD = password;
  }
  const result = spawnSync(php, [helper, action], {
    cwd: root,
    env,
    encoding: 'utf8',
    windowsHide: true,
    timeout: e2eHelperTimeoutMs,
  });
  return parseHelperOutput(action, result);
}

function formatCounts(stage, report) {
  const counts = report.counts || {};
  const summary = Object.entries(counts).map(([key, value]) => `${key}=${value}`).join(' ');
  console.log(`[e2e-isolation] ${stage} prefix=${objectPrefix} ${summary} total=${report.total ?? 0}`);
}

function appendServerLog(server, chunk) {
  server.suxiLog = `${server.suxiLog || ''}${String(chunk || '')}`.slice(-3000);
}

async function startIsolatedServer() {
  const occupiedPorts = [];
  for (const port of [appPort, backendPort]) {
    if (await isolatedServerPortReachable(port)) occupiedPorts.push(port);
  }
  if (occupiedPorts.length > 0) {
    throw new Error(`Isolated E2E requires free origin/backend ports: ${occupiedPorts.join('/')}`);
  }
  const server = spawn(php, ['-S', `127.0.0.1:${backendPort}`, '-t', 'public', 'public/router.php'], {
    cwd: root,
    env: e2eProcessEnv,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  server.suxiLog = '';
  server.suxiSpawnError = null;
  server.suxiClosed = false;
  server.suxiOriginServer = null;
  server.stdout?.on('data', (chunk) => appendServerLog(server, chunk));
  server.stderr?.on('data', (chunk) => appendServerLog(server, chunk));
  server.on('error', (error) => {
    server.suxiSpawnError = error;
  });
  server.once('close', () => {
    server.suxiClosed = true;
  });
  try {
    server.suxiOriginServer = await startLocalOriginServer({
      host: '127.0.0.1',
      port: appPort,
      publicRoot: path.join(root, 'public'),
      backendUrl: `http://127.0.0.1:${backendPort}`,
    });
  } catch (error) {
    try {
      await stopIsolatedBackend(server);
      await waitForIsolatedPortsReleased([backendPort], 'backend');
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        `Local origin failed to start and PHP backend cleanup also failed: ${cleanupError.message}`,
      );
    }
    throw error;
  }
  return server;
}

function isolatedServerProcessAlive(server) {
  return Boolean(server) && server.suxiClosed !== true;
}

function signalIsolatedServer(server, signal) {
  try {
    server.kill(signal);
  } catch (error) {
    if (error?.code !== 'ESRCH') throw error;
  }
}

async function isolatedServerPortReachable(port) {
  return new Promise((resolve) => {
    const socket = net.createConnection({ host: '127.0.0.1', port });
    let settled = false;
    const finish = (reachable) => {
      if (settled) return;
      settled = true;
      socket.destroy();
      resolve(reachable);
    };
    socket.setTimeout(250);
    socket.once('connect', () => finish(true));
    socket.once('timeout', () => finish(true));
    socket.once('error', (error) => finish(error?.code !== 'ECONNREFUSED'));
  });
}

async function stopIsolatedBackend(server) {
  if (!server) return;
  if (isolatedServerProcessAlive(server)) signalIsolatedServer(server, 'SIGTERM');
  for (let attempt = 0; attempt < 30 && isolatedServerProcessAlive(server); attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  if (isolatedServerProcessAlive(server)) {
    signalIsolatedServer(server, 'SIGKILL');
    for (let attempt = 0; attempt < 20 && isolatedServerProcessAlive(server); attempt += 1) {
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
  }
  server.stdout?.destroy();
  server.stderr?.destroy();
  if (isolatedServerProcessAlive(server)) {
    throw new Error('Isolated E2E PHP backend did not stop within 5 seconds');
  }
}

async function waitForIsolatedPortsReleased(ports, label = 'ports') {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const reachable = await Promise.all(ports.map((port) => isolatedServerPortReachable(port)));
    if (reachable.every((value) => !value)) return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error(`Isolated E2E ${label} ports ${ports.join('/')} remained bound after process shutdown`);
}

async function stopIsolatedServer(server) {
  if (!server) return;
  if (server.suxiOriginServer) {
    await new Promise((resolve) => {
      let settled = false;
      const finish = () => {
        if (settled) return;
        settled = true;
        clearTimeout(timeout);
        resolve();
      };
      const timeout = setTimeout(finish, 2_000);
      server.suxiOriginServer.close(finish);
      server.suxiOriginServer.closeIdleConnections?.();
      server.suxiOriginServer.closeAllConnections?.();
    });
  }
  await stopIsolatedBackend(server);
  await waitForIsolatedPortsReleased([appPort, backendPort], 'origin/backend');
  server.unref();
}

async function verifyHealth(server = null) {
  let lastError = null;
  for (let attempt = 0; attempt < 40; attempt += 1) {
    if (server?.suxiSpawnError) {
      throw new Error(`Isolated E2E server could not start: ${server.suxiSpawnError.message}`);
    }
    if (server && server.exitCode !== null) {
      const detail = String(server.suxiLog || '').trim().slice(-1000);
      throw new Error(`Isolated E2E server exited with code ${server.exitCode}${detail ? `: ${detail}` : ''}`);
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 1000);
    try {
      const response = await fetch(new URL('api/health', baseURL), { signal: controller.signal });
      if (response.ok) {
        return;
      }
      const body = await response.json().catch(() => null);
      lastError = new Error(formatHealthFailure(response.status, body));
    } catch (error) {
      lastError = error;
    } finally {
      clearTimeout(timeout);
    }

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new Error(`E2E target is not healthy at ${baseURL}: ${lastError?.message || 'timeout'}`);
}

async function responseJson(response, label) {
  const body = await response.json().catch(() => null);
  if (!response.ok || !body || Number(body.code) !== 200) {
    throw new Error(`${label} failed with HTTP ${response.status}`);
  }
  return body.data;
}

async function verifyIsolatedIdentity(seed) {
  const loginResponse = await fetch(new URL('api/auth/login', baseURL), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: seed.username, password }),
  });
  const loginData = await responseJson(loginResponse, 'Isolated E2E login preflight');
  const token = String(loginData?.token || '');
  if (!token) {
    throw new Error('Isolated E2E login preflight returned no token');
  }

  const infoResponse = await fetch(new URL('api/auth/info', baseURL), {
    headers: { Authorization: token },
  });
  const info = await responseJson(infoResponse, 'Isolated E2E auth-info preflight');
  const permitted = Array.isArray(info?.permitted_hotels) ? info.permitted_hotels : [];
  const onlyHotel = permitted[0] || null;
  const hotelScopeIds = Array.isArray(info?.hotel_scope?.hotel_ids)
    ? info.hotel_scope.hotel_ids.map(Number)
    : [];
  const capabilities = Array.isArray(info?.capabilities) ? info.capabilities.map(String) : [];
  const checks = {
    non_super_admin: info?.is_super_admin === false,
    user_id: Number(info?.id || 0) === Number(seed.user_id),
    role_id: Number(info?.role_id || 0) === Number(seed.role_id),
    primary_hotel_id: Number(info?.hotel_id || 0) === Number(seed.hotel_id),
    permitted_hotel_count: permitted.length === 1,
    permitted_hotel_id: Number(onlyHotel?.id || 0) === Number(seed.hotel_id),
    permitted_hotel_name: String(onlyHotel?.name || '') === seed.hotel_name,
    permitted_hotel_tenant: Number(onlyHotel?.tenant_id || 0) === Number(seed.tenant_id),
    hotel_scope: hotelScopeIds.length === 1 && hotelScopeIds[0] === Number(seed.hotel_id),
    context_hotel_id: Number(info?.context?.hotelId || 0) === Number(seed.hotel_id),
    context_tenant_id: Number(info?.context?.tenantId || 0) === Number(seed.tenant_id),
    permission_status: info?.context?.permissionStatus === 'allowed',
    all_capabilities: capabilities.includes('all'),
  };
  const failedChecks = Object.entries(checks)
    .filter(([, passed]) => !passed)
    .map(([name]) => name);
  if (failedChecks.length > 0) {
    throw new Error(`Temporary E2E identity isolation failed: ${failedChecks.join(', ')}`);
  }

  const protectedResponse = await fetch(new URL(`api/operation/execution-flow?hotel_id=${seed.hotel_id}`, baseURL), {
    headers: { Authorization: token },
  });
  await responseJson(protectedResponse, 'Isolated E2E protected-capability preflight');
  for (const [label, endpoint] of [
    ['collection health', `api/online-data/auto-fetch-status?system_hotel_id=${seed.hotel_id}&include_detail=0`],
    ['platform profile', `api/online-data/platform-profile-status?system_hotel_id=${seed.hotel_id}`],
  ]) {
    const response = await fetch(new URL(endpoint, baseURL), { headers: { Authorization: token } });
    await responseJson(response, `Isolated E2E ${label} read preflight`);
  }
  console.log(`[e2e-isolation] preflight role_id=${seed.role_id} is_super_admin=false permissions=all permitted_hotels=1 tenant_id=${seed.tenant_id}`);
}

let activeNodeChild = null;
let activeNodeChildKillTimer = null;
let requestedShutdownSignal = null;
let controllerShutdownRequested = false;
let controllerShutdownReleaseResolver = null;
let normalControllerDisconnectInitiated = false;
let cleanupSequenceFinished = false;
const controllerShutdownRequestType = 'suxi-isolated-shutdown-request';
const controllerShutdownCompleteType = 'suxi-isolated-shutdown-complete';
const controllerShutdownReleaseType = 'suxi-isolated-shutdown-release';

function forceStopNodeChildTree(child) {
  if (!child || !Number.isInteger(child.pid)) return;
  if (process.platform === 'win32') {
    if (child.exitCode !== null) return;
    spawnSync('taskkill.exe', ['/PID', String(child.pid), '/T', '/F'], {
      windowsHide: true,
      stdio: 'ignore',
    });
    return;
  }
  try {
    process.kill(-child.pid, 'SIGKILL');
  } catch (error) {
    if (error?.code !== 'ESRCH') throw error;
  }
}

function requestActiveNodeChildShutdown() {
  const child = activeNodeChild;
  if (!child || child.exitCode !== null) return;
  if (process.platform === 'win32') {
    forceStopNodeChildTree(child);
    return;
  }
  try {
    process.kill(-child.pid, 'SIGTERM');
  } catch (error) {
    if (error?.code !== 'ESRCH') throw error;
  }
  if (activeNodeChildKillTimer === null) {
    activeNodeChildKillTimer = setTimeout(() => forceStopNodeChildTree(child), 5_000);
  }
}

function handleProcessShutdownSignal(signal) {
  requestedShutdownSignal ||= signal;
  requestActiveNodeChildShutdown();
}

process.on('SIGINT', () => handleProcessShutdownSignal('SIGINT'));
process.on('SIGTERM', () => handleProcessShutdownSignal('SIGTERM'));
process.on('message', (message) => {
  if (message?.type === controllerShutdownRequestType) {
    controllerShutdownRequested = true;
    handleProcessShutdownSignal(message.signal === 'SIGINT' ? 'SIGINT' : 'SIGTERM');
    return;
  }
  if (message?.type === controllerShutdownReleaseType) {
    controllerShutdownReleaseResolver?.();
  }
});
process.on('disconnect', () => {
  if (!normalControllerDisconnectInitiated && !cleanupSequenceFinished) {
    controllerShutdownRequested = true;
    handleProcessShutdownSignal('SIGTERM');
  }
});

async function acknowledgeControllerShutdown(cleanupComplete) {
  if (!process.connected) return;
  if (!controllerShutdownRequested || typeof process.send !== 'function') {
    normalControllerDisconnectInitiated = true;
    process.disconnect();
    return;
  }
  await new Promise((resolve) => {
    let settled = false;
    const onDisconnect = () => finish();
    const finish = () => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      process.off('disconnect', onDisconnect);
      if (controllerShutdownReleaseResolver === finish) controllerShutdownReleaseResolver = null;
      resolve();
    };
    const timeout = setTimeout(finish, 5_000);
    controllerShutdownReleaseResolver = finish;
    process.once('disconnect', onDisconnect);
    try {
      process.send({
        type: controllerShutdownCompleteType,
        cleanup_complete: cleanupComplete,
        origin_port: appPort,
        backend_port: backendPort,
      }, (error) => {
        if (error) finish();
      });
    } catch (error) {
      finish();
    }
  });
  if (process.connected) {
    normalControllerDisconnectInitiated = true;
    process.disconnect();
  }
}

function runNodeChild(args, env) {
  return new Promise((resolve) => {
    if (requestedShutdownSignal) {
      resolve({ error: null, status: null, signal: requestedShutdownSignal });
      return;
    }
    if (activeNodeChild) {
      resolve({
        error: new Error('Isolated E2E runner already owns an active Node child'),
        status: null,
        signal: null,
      });
      return;
    }
    const child = spawn(process.execPath, args, {
      cwd: root,
      stdio: 'inherit',
      detached: process.platform !== 'win32',
      windowsHide: true,
      env,
    });
    activeNodeChild = child;
    let settled = false;
    const finish = (result) => {
      if (settled) return;
      settled = true;
      if (requestedShutdownSignal && process.platform !== 'win32') {
        forceStopNodeChildTree(child);
      }
      if (activeNodeChild === child) activeNodeChild = null;
      if (activeNodeChildKillTimer !== null) {
        clearTimeout(activeNodeChildKillTimer);
        activeNodeChildKillTimer = null;
      }
      resolve(result);
    };
    child.once('error', (error) => finish({ error, status: null, signal: null }));
    child.once('close', (status, signal) => finish({ error: null, status, signal }));
    if (requestedShutdownSignal) requestActiveNodeChildShutdown();
  });
}

async function runPlaywright(seed) {
  const isolatedEnv = {
    ...e2eProcessEnv,
    E2E_BASE_URL: baseURL,
    E2E_USERNAME: seed.username,
    E2E_PASSWORD: password,
    E2E_HOTEL_ID: String(seed.hotel_id),
    E2E_HOTEL_NAME: seed.hotel_name,
    E2E_OBJECT_PREFIX: objectPrefix,
    E2E_RUN_ID: objectPrefix,
    SUXI_E2E_ISOLATED_RUNNER: '1',
  };
  if (codexProfile) {
    return runNodeChild([
      'scripts/codex_automation_runner.mjs',
      `--profile=${codexProfile}`,
      `--iterations=${codexIterations || (codexProfile === 'quick' ? '1' : '10')}`,
    ], isolatedEnv);
  }
  if (performanceOnly) {
    return runNodeChild([
      'scripts/measure_frontend_performance.mjs',
      `--url=${baseURL}`,
      `--label=${performanceLabel}`,
      '--authenticated=1',
      `--iterations=${performanceIterations}`,
      `--network=${performanceNetwork}`,
      '--require-verified=1',
      `--enforce-budget=${performanceEnforceBudget}`,
    ], isolatedEnv);
  }

  const cli = path.join(root, 'node_modules', '@playwright', 'test', 'cli.js');
  const focusArgs = otaOnly ? ['--grep', 'OTA import'] : [];
  const fullClickEnv = fullClick ? {
    E2E_MUTATE: '1',
    E2E_ALLOW_DESTRUCTIVE: '0',
    E2E_DB_BACKUP: '0',
    E2E_DB_RESTORE: '0',
    ...(fullClickBounded ? {
      E2E_FULL_MIN_LOOP: process.env.E2E_FULL_MIN_LOOP || '1',
      E2E_FULL_MAX_LOOP: process.env.E2E_FULL_MAX_LOOP || '3',
      E2E_LOOP: process.env.E2E_LOOP || process.env.E2E_FULL_MAX_LOOP || '3',
    } : {}),
  } : {};
  return runNodeChild(
    [cli, 'test', ...specs, ...focusArgs, '--workers=1', '--reporter=list'],
    {
      ...isolatedEnv,
      ...fullClickEnv,
    },
  );
}

class ControlledShutdownError extends Error {}

function assertRunContinues() {
  if (requestedShutdownSignal) {
    throw new ControlledShutdownError(`shutdown requested by ${requestedShutdownSignal}`);
  }
}

let exitCode = 1;
let primaryError = null;
let databaseGuardPassed = false;
let isolatedServer = null;
let databaseCleanupVerified = false;
let serverCleanupVerified = !selfHosted;
let localStateCleanupVerified = false;
try {
  const databaseSafety = runHelper('guard');
  databaseGuardPassed = true;
  console.log(`[e2e-isolation] database-guard mode=${databaseSafety.mode} host_scope=${databaseSafety.database_host_scope} schema=${databaseSafety.schema_contract}`);
  assertRunContinues();
  if (selfHosted) {
    isolatedServer = await startIsolatedServer();
    console.log(`[e2e-isolation] server database=${dedicatedDatabaseName} base_url=${baseURL} backend_port=${backendPort}`);
  }
  assertRunContinues();
  await verifyHealth(isolatedServer);
  assertRunContinues();
  const baseline = runHelper('count');
  formatCounts('baseline', baseline);
  if (Number(baseline.total || 0) !== 0) {
    throw new Error('Fresh E2E prefix unexpectedly matches existing data');
  }
  assertRunContinues();

  const seed = runHelper('seed');
  seededHotelId = Number(seed.hotel_id || 0);
  seededUserId = Number(seed.user_id || 0);
  seededTenantId = Number(seed.tenant_id || 0);
  console.log(`[e2e-isolation] seeded prefix=${objectPrefix} tenant_id=${seed.tenant_id} user_id=${seed.user_id} hotel_id=${seed.hotel_id}`);
  assertRunContinues();
  await verifyIsolatedIdentity(seed);
  assertRunContinues();
  if (preflightOnly) {
    exitCode = 0;
  } else {
    // Keep the parent event loop live while the child runs because the local
    // origin server is hosted by this process.
    const result = await runPlaywright(seed);
    if (result.error) {
      throw new Error(`Playwright could not start: ${result.error.message}`);
    }
    if (result.signal) {
      if (requestedShutdownSignal) {
        exitCode = requestedShutdownSignal === 'SIGINT' ? 130 : 143;
      } else {
        throw new Error(`Playwright stopped by signal ${result.signal}`);
      }
    } else {
      exitCode = Number.isInteger(result.status) ? result.status : 1;
    }
  }
} catch (error) {
  if (error instanceof ControlledShutdownError) {
    exitCode = requestedShutdownSignal === 'SIGINT' ? 130 : 143;
    console.log(`[e2e-isolation] ${error.message}`);
  } else {
    primaryError = error;
    console.error(`[e2e-isolation] ${error.message}`);
  }
} finally {
  if (!databaseGuardPassed) {
    console.error('[e2e-isolation] cleanup skipped because the database safety guard did not pass');
  } else {
  try {
    const beforeCleanup = runHelper('count');
    formatCounts('before-cleanup', beforeCleanup);
  } catch (error) {
    primaryError ||= error;
    exitCode = 1;
    console.error(`[e2e-isolation] pre-cleanup count failed: ${error.message}`);
  }

  try {
    const cleanup = runHelper('cleanup');
    const deleted = cleanup.deleted || {};
    const summary = Object.entries(deleted).map(([key, value]) => `${key}=${value}`).join(' ');
    console.log(`[e2e-isolation] cleanup prefix=${objectPrefix} ${summary || 'deleted=0'}`);
  } catch (error) {
    primaryError ||= error;
    exitCode = 1;
    console.error(`[e2e-isolation] cleanup failed: ${error.message}`);
  }

  try {
    const afterCleanup = runHelper('count');
    formatCounts('after-cleanup', afterCleanup);
    if (Number(afterCleanup.total || 0) !== 0) {
      throw new Error(`Cleanup left ${afterCleanup.total} prefixed object(s)`);
    }
    databaseCleanupVerified = true;
  } catch (error) {
    primaryError ||= error;
    exitCode = 1;
    console.error(`[e2e-isolation] post-cleanup verification failed: ${error.message}`);
  }
  }

  if (isolatedServer) {
    try {
      await stopIsolatedServer(isolatedServer);
      serverCleanupVerified = true;
    } catch (error) {
      primaryError ||= error;
      exitCode = 1;
      console.error(`[e2e-isolation] server cleanup failed: ${error.message}`);
    }
  }

  try {
    if (path.dirname(isolatedStateRoot) !== isolatedStateParent) {
      throw new Error('Refusing to remove an E2E state path outside runtime/e2e-state');
    }
    rmSync(isolatedStateRoot, { recursive: true, force: true });
    localStateCleanupVerified = true;
    console.log(`[e2e-isolation] local-state-cleanup prefix=${objectPrefix} removed=1`);
  } catch (error) {
    primaryError ||= error;
    exitCode = 1;
    console.error(`[e2e-isolation] local-state cleanup failed: ${error.message}`);
  }
}

cleanupSequenceFinished = true;

await acknowledgeControllerShutdown(
  databaseCleanupVerified
    && serverCleanupVerified
    && localStateCleanupVerified
    && activeNodeChild === null,
);

if (primaryError) {
  exitCode = 1;
} else if (requestedShutdownSignal) {
  exitCode = requestedShutdownSignal === 'SIGINT' ? 130 : 143;
}
process.exitCode = exitCode;
