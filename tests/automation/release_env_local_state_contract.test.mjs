import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { checkProductionEnvFile } from '../../scripts/lib/release_env_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

function productionEnv(overrides = {}) {
  const values = {
    APP_DEBUG: 'false',
    APP_TRACE: 'false',
    SUXIOS_DEPLOYMENT_MODE: 'single_instance',
    SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE: 'true',
    SUXIOS_CACHE_PATH: '/var/lib/suxios/app-cache',
    SUXIOS_LOCAL_LOCK_PATH: '/var/lib/suxios/app-locks',
    SUXIOS_FORECAST_PLAN_PATH: '/var/lib/suxios/forecast-plans',
    DB_HOST: 'prod-db.internal',
    DB_NAME: 'hotelx_prod',
    DB_USER: 'hotel_app',
    DB_PASS: 'nonempty-production-password',
    AI_CONFIG_SECRET: '12345678901234567890123456789012',
    ...overrides,
  };

  return `${Object.entries(values).map(([key, value]) => `${key}=${value}`).join('\n')}\n`;
}

function runCheck(content) {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-release-env-state-'));
  const repoRoot = path.join(tempRoot, 'repo');
  const controlledDir = path.join(tempRoot, 'controlled');
  const envFile = path.join(controlledDir, 'production.env');
  fs.mkdirSync(repoRoot, { recursive: true });
  fs.mkdirSync(controlledDir, { recursive: true });
  fs.writeFileSync(envFile, content, 'utf8');

  try {
    return checkProductionEnvFile({
      repoRoot,
      envFile,
      requireOutsideRepo: true,
    });
  } finally {
    fs.rmSync(tempRoot, { recursive: true, force: true });
  }
}

test('production env accepts external persistent paths in single-instance mode', () => {
  const result = runCheck(productionEnv({
    DB_HOST: '127.0.0.1',
  }));

  assert.deepEqual(result.failures, []);
  assert.ok(result.passes.includes('SUXIOS_DEPLOYMENT_MODE is constrained to single_instance.'));
  assert.ok(result.passes.includes('SUXIOS_CACHE_PATH is an absolute external path.'));
  assert.ok(result.passes.includes('SUXIOS_LOCAL_LOCK_PATH is an absolute external path.'));
  assert.ok(result.passes.includes('DB_HOST loopback is accepted for the single-host MariaDB deployment.'));
});

test('production env rejects multi-instance mode before distributed state exists', () => {
  const result = runCheck(productionEnv({
    SUXIOS_DEPLOYMENT_MODE: 'multi_instance',
  }));

  assert.match(
    result.failures.join('\n'),
    /SUXIOS_DEPLOYMENT_MODE must be single_instance/
  );
});

test('production env requires a dedicated external forecast plan path', () => {
  for (const value of ['', 'runtime/forecast-plans', '/var/www/suxios/releases/v1/plans', '/var/lib/suxios/app-cache/plans']) {
    assert.match(runCheck(productionEnv({ SUXIOS_FORECAST_PLAN_PATH: value })).failures.join('\n'), /SUXIOS_FORECAST_PLAN_PATH/);
  }
});

test('forecast deployment path and writable probe use the configured external directory', () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-forecast-path-probe-'));
  const env = { ...process.env, APP_DEBUG: 'false', SUXIOS_DEPLOYMENT_MODE: 'single_instance', SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE: 'true' };
  for (const [name, dir] of [['SUXIOS_CACHE_PATH', 'cache'], ['SUXIOS_LOCAL_LOCK_PATH', 'locks'], ['SUXIOS_FORECAST_PLAN_PATH', 'plans']]) {
    env[name] = path.join(tempRoot, dir); fs.mkdirSync(env[name]);
  }
  const php = process.env.SUXI_PHP || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
  try {
    const resolve = spawnSync(php, ['scripts/verify_single_instance_state_paths.php', '--forecast-path-only'], { cwd: repoRoot, env, encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(resolve.status, 0, resolve.stderr);
    assert.equal(resolve.stdout.trim(), env.SUXIOS_FORECAST_PLAN_PATH);
    const probe = spawnSync(php, ['scripts/verify_single_instance_state_paths.php'], { cwd: repoRoot, env, encoding: 'utf8', timeout: 20000, windowsHide: true });
    assert.equal(probe.status, 0, probe.stderr);
    assert.match(probe.stdout, /forecast paths are persistent, active, and writable/);
    assert.deepEqual(fs.readdirSync(env.SUXIOS_FORECAST_PLAN_PATH), []);
  } finally {
    assert.ok(fs.realpathSync(tempRoot).startsWith(fs.realpathSync(os.tmpdir()) + path.sep + 'suxios-forecast-path-probe-'));
    fs.rmSync(tempRoot, { recursive: true, force: true });
  }
  const installer = fs.readFileSync(path.join(repoRoot, 'deploy/cloud/install_release.sh'), 'utf8');
  const provision = installer.indexOf('install -d -o www-data -g www-data -m 0770 "$FORECAST_PLAN_PATH"');
  assert.ok(provision > installer.indexOf('if [[ $NO_SWITCH -eq 1 ]]'), 'staging must not create shared state');
  assert.ok(provision < installer.indexOf('sudo -u www-data php scripts/verify_single_instance_state_paths.php'));
  assert.ok(provision < installer.indexOf('ROLLBACK_LINK='));
});

test('production env rejects missing persistence enforcement and relative paths', () => {
  const result = runCheck(productionEnv({
    SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE: 'false',
    SUXIOS_CACHE_PATH: 'runtime/cache',
    SUXIOS_LOCAL_LOCK_PATH: 'runtime/locks',
  }));
  const failures = result.failures.join('\n');

  assert.match(failures, /SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE must be true/);
  assert.match(failures, /SUXIOS_CACHE_PATH must be an absolute path/);
  assert.match(failures, /SUXIOS_LOCAL_LOCK_PATH must be an absolute path/);
});

test('production env rejects the wildcard database listen address', () => {
  const result = runCheck(productionEnv({
    DB_HOST: '0.0.0.0',
  }));

  assert.match(result.failures.join('\n'), /DB_HOST must not use the wildcard listen address/);
});

test('single-instance release probes active cache, lock, and durable report schema', () => {
  const stateVerifier = fs.readFileSync(
    path.join(repoRoot, 'scripts', 'verify_single_instance_state_paths.php'),
    'utf8'
  );
  const schemaVerifier = fs.readFileSync(
    path.join(repoRoot, 'scripts', 'verify_competitor_report_idempotency_schema.php'),
    'utf8'
  );
  const competitorApi = fs.readFileSync(
    path.join(repoRoot, 'app', 'controller', 'CompetitorApi.php'),
    'utf8'
  );
  const runtimeReadiness = fs.readFileSync(
    path.join(repoRoot, 'app', 'service', 'SingleInstanceRuntimeReadiness.php'),
    'utf8'
  );
  const routes = fs.readFileSync(path.join(repoRoot, 'route', 'app.php'), 'utf8');

  assert.match(stateVerifier, /cache\(\$probeKey, \$probeValue, 30\)/);
  assert.match(stateVerifier, /flock\(\$lockHandle, LOCK_EX \| LOCK_NB\)/);
  assert.match(schemaVerifier, /information_schema\.COLUMNS/);
  assert.match(schemaVerifier, /information_schema\.STATISTICS/);
  assert.match(schemaVerifier, /uniq_competitor_report_fingerprint/);
  assert.match(competitorApi, /competitor_report_idempotency_schema_missing/);
  assert.match(competitorApi, /return \$this->apiError\('竞对报告持久化升级未完成，请先执行数据库迁移', 503\)/);
  assert.match(runtimeReadiness, /database_schema_upgrade_required/);
  assert.match(runtimeReadiness, /persistent_local_state_path_unavailable/);
  assert.match(runtimeReadiness, /SchemaVersionStatusCache/);
  assert.match(runtimeReadiness, /production_runtime_ready/);
  assert.match(runtimeReadiness, /single_instance_persistent/);
  assert.match(routes, /SingleInstanceRuntimeReadiness/);
  assert.match(routes, /'production_runtime_ready'/);
  assert.match(routes, /'runtime_mode'/);
  assert.match(routes, /'failure_codes'/);
});
