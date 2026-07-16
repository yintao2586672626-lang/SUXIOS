import { randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
const helper = path.join(root, 'tests', 'automation', 'e2e-isolation-helper.php');

function loopbackBaseURL(value) {
  const parsed = new URL(value);
  const hostname = parsed.hostname.toLowerCase();
  const allowedHosts = new Set(['127.0.0.1', 'localhost', '[::1]', '::1']);
  if (parsed.protocol !== 'http:' || !allowedHosts.has(hostname) || parsed.username || parsed.password) {
    throw new Error('Isolated E2E only permits an unauthenticated http loopback base URL');
  }
  return parsed.toString();
}

const sharedDatabaseOptIn = String(process.env.SUXI_E2E_ALLOW_SHARED_DB || '').trim() === '1';
const configuredDedicatedDatabase = String(process.env.SUXI_E2E_DB_NAME || '').trim();
const dedicatedDatabaseName = configuredDedicatedDatabase !== ''
  ? configuredDedicatedDatabase
  : (sharedDatabaseOptIn ? '' : 'hotelx_e2e');
const selfHosted = dedicatedDatabaseName !== '';
const appPort = Number(process.env.SUXI_E2E_APP_PORT || 18080);
if (!Number.isInteger(appPort) || appPort < 1024 || appPort > 65535) {
  throw new Error('SUXI_E2E_APP_PORT must be an integer between 1024 and 65535');
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
const e2eProcessEnv = selfHosted
  ? {
      ...process.env,
      DB_NAME: dedicatedDatabaseName,
      SUXI_E2E_DB_NAME: dedicatedDatabaseName,
      SUXI_E2E_DB_OVERRIDE: '1',
    }
  : { ...process.env };
const objectPrefix = `codex_e2e_${Date.now().toString(36)}_${randomBytes(6).toString('hex')}`;
const password = `${randomBytes(36).toString('base64url')}Aa1!`;
const businessOnly = process.argv.includes('--business-only');
const dailyOnly = process.argv.includes('--daily-only');
const otaOnly = process.argv.includes('--ota-only');
const temporalOnly = process.argv.includes('--temporal-only');
const preflightOnly = process.argv.includes('--preflight-only');
const specs = temporalOnly
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
  if (action === 'seed') {
    env.SUXI_E2E_PASSWORD = password;
  }
  const result = spawnSync(php, [helper, action], {
    cwd: root,
    env,
    encoding: 'utf8',
    windowsHide: true,
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

function startIsolatedServer() {
  const server = spawn(php, ['-S', `127.0.0.1:${appPort}`, '-t', 'public', 'public/router.php'], {
    cwd: root,
    env: e2eProcessEnv,
    windowsHide: true,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  server.suxiLog = '';
  server.suxiSpawnError = null;
  server.stdout?.on('data', (chunk) => appendServerLog(server, chunk));
  server.stderr?.on('data', (chunk) => appendServerLog(server, chunk));
  server.on('error', (error) => {
    server.suxiSpawnError = error;
  });
  return server;
}

async function stopIsolatedServer(server) {
  if (!server || server.exitCode !== null) {
    return;
  }
  server.kill();
  for (let attempt = 0; attempt < 30 && server.exitCode === null; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  if (server.exitCode === null) {
    server.kill('SIGKILL');
  }
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
      lastError = new Error(`HTTP ${response.status}`);
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
    permitted_hotel_tenant: Number(onlyHotel?.tenant_id || 0) === Number(seed.hotel_id),
    hotel_scope: hotelScopeIds.length === 1 && hotelScopeIds[0] === Number(seed.hotel_id),
    context_hotel_id: Number(info?.context?.hotelId || 0) === Number(seed.hotel_id),
    context_tenant_id: Number(info?.context?.tenantId || 0) === Number(seed.hotel_id),
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
  console.log(`[e2e-isolation] preflight role_id=${seed.role_id} is_super_admin=false permissions=all permitted_hotels=1 tenant_id=${seed.hotel_id}`);
}

function runPlaywright(seed) {
  const cli = path.join(root, 'node_modules', '@playwright', 'test', 'cli.js');
  const focusArgs = otaOnly ? ['--grep', 'OTA import'] : [];
  return spawnSync(process.execPath, [cli, 'test', ...specs, ...focusArgs, '--workers=1', '--reporter=list'], {
    cwd: root,
    stdio: 'inherit',
    windowsHide: true,
    env: {
      ...e2eProcessEnv,
      E2E_BASE_URL: baseURL,
      E2E_USERNAME: seed.username,
      E2E_PASSWORD: password,
      E2E_HOTEL_ID: String(seed.hotel_id),
      E2E_HOTEL_NAME: seed.hotel_name,
      E2E_OBJECT_PREFIX: objectPrefix,
      E2E_RUN_ID: objectPrefix,
    },
  });
}

let exitCode = 1;
let primaryError = null;
let databaseGuardPassed = false;
let isolatedServer = null;
try {
  const databaseSafety = runHelper('guard');
  databaseGuardPassed = true;
  console.log(`[e2e-isolation] database-guard mode=${databaseSafety.mode} host_scope=${databaseSafety.database_host_scope}`);
  if (selfHosted) {
    isolatedServer = startIsolatedServer();
    console.log(`[e2e-isolation] server database=${dedicatedDatabaseName} base_url=${baseURL}`);
  }
  await verifyHealth(isolatedServer);
  const baseline = runHelper('count');
  formatCounts('baseline', baseline);
  if (Number(baseline.total || 0) !== 0) {
    throw new Error('Fresh E2E prefix unexpectedly matches existing data');
  }

  const seed = runHelper('seed');
  seededHotelId = Number(seed.hotel_id || 0);
  seededUserId = Number(seed.user_id || 0);
  console.log(`[e2e-isolation] seeded prefix=${objectPrefix} user_id=${seed.user_id} hotel_id=${seed.hotel_id}`);
  await verifyIsolatedIdentity(seed);
  if (preflightOnly) {
    exitCode = 0;
  } else {
    const result = runPlaywright(seed);
    if (result.error) {
      throw new Error(`Playwright could not start: ${result.error.message}`);
    }
    if (result.signal) {
      throw new Error(`Playwright stopped by signal ${result.signal}`);
    }
    exitCode = Number.isInteger(result.status) ? result.status : 1;
  }
} catch (error) {
  primaryError = error;
  console.error(`[e2e-isolation] ${error.message}`);
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
  } catch (error) {
    primaryError ||= error;
    exitCode = 1;
    console.error(`[e2e-isolation] post-cleanup verification failed: ${error.message}`);
  }
  }

  if (isolatedServer) {
    await stopIsolatedServer(isolatedServer);
  }
}

if (primaryError) {
  exitCode = 1;
}
process.exitCode = exitCode;
