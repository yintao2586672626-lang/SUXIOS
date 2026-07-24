import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const runner = readFileSync('tests/automation/run-quick-e2e-isolated.mjs', 'utf8');
const helper = readFileSync('tests/automation/e2e-isolation-helper.php', 'utf8');
const databaseGuard = readFileSync('tests/automation/E2eDatabaseSafetyGuard.php', 'utf8');
const exampleEnv = readFileSync('.example.env', 'utf8');
const databaseConfig = readFileSync('config/database.php', 'utf8');

test('quick isolated E2E default stays within the currently visible phase-1 scope', () => {
  assert.match(runner, /const asyncOnly = process\.argv\.includes\('--async-only'\);/);
  assert.match(
    runner,
    /asyncOnly\s*\?\s*\['tests\/automation\/async-page-guard\.spec\.js'\]/,
  );
  assert.match(
    runner,
    /:\s*\[\s*'tests\/automation\/daily-regression\.spec\.js',\s*'tests\/automation\/business-chains\.spec\.js',?\s*\];/s,
  );
});

test('isolated E2E residue verification retains user identity after cleanup', () => {
  assert.match(runner, /let seededUserId = 0;/);
  assert.match(runner, /let seededTenantId = 0;/);
  assert.match(runner, /env\.SUXI_E2E_USER_ID = String\(seededUserId\);/);
  assert.match(runner, /env\.SUXI_E2E_TENANT_ID = String\(seededTenantId\);/);
  assert.match(helper, /getenv\('SUXI_E2E_USER_ID'\)/);
  assert.match(helper, /getenv\('SUXI_E2E_TENANT_ID'\)/);
  for (const residue of [
    'operation_logs',
    'login_logs_by_user',
    'login_logs_by_name',
    'user_hotel_permissions',
  ]) {
    assert.match(helper, new RegExp(`\\$counts\\['${residue}'\\]`));
  }
});

test('isolated E2E refuses unsafe database targets before any cleanup path', () => {
  assert.match(helper, /SELECT DATABASE\(\) AS database_name/);
  assert.match(helper, /SUXI_E2E_ALLOW_SHARED_DB/);
  assert.match(helper, /SUXI_E2E_ALLOW_REMOTE_TEST_DB/);
  assert.match(runner, /const databaseSafety = runHelper\('guard'\);/);
  assert.match(runner, /if \(!databaseGuardPassed\)/);
  assert.match(databaseGuard, /\*_test\/\*_testing\/\*_e2e database/);
});

test('isolated E2E fails before seeding when the dedicated database schema is stale', () => {
  assert.match(helper, /function e2eAssertSchemaReady\(\): array/);
  assert.match(helper, /'tenants' => \['id', 'name', 'status'\]/);
  assert.match(helper, /online_daily_data'[\s\S]*'readback_verified', 'readback_verified_at'/);
  assert.match(helper, /initialize or migrate this dedicated test database with database\/init_full\.sql/);
  assert.match(helper, /array_merge\(\$databaseSafety, e2eAssertSchemaReady\(\)\)/);
  assert.match(runner, /schema=\$\{databaseSafety\.schema_contract\}/);
});

test('isolated E2E seed follows the current tenant foreign-key model', () => {
  assert.match(helper, /'tenant_name' => \$prefix \. '_tenant'/);
  assert.match(helper, /Db::name\('tenants'\)->insertGetId/);
  assert.match(helper, /'tenant_id' => \$tenantId/);
  assert.match(helper, /e2eSetProtectedModules\(\(int\)\$seed\['tenant_id'\], true\)/);
  assert.match(helper, /Db::name\('tenants'\)[\s\S]*->where\('name', \$names\['tenant_name'\]\)[\s\S]*->delete\(\)/);
  assert.match(runner, /Number\(seed\.tenant_id\)/);
});

test('quick E2E binds helper and self-hosted app to one dedicated database', () => {
  assert.match(exampleEnv, /^SUXI_E2E_DB_NAME\s*=\s*hotelx_e2e$/m);
  assert.match(runner, /process\.env\.SUXI_E2E_DB_NAME/);
  assert.match(runner, /DB_NAME:\s*dedicatedDatabaseName/);
  assert.match(runner, /SUXI_E2E_DB_OVERRIDE:\s*'1'/);
  assert.match(databaseConfig, /getenv\('SUXI_E2E_DB_OVERRIDE'\)/);
  assert.match(databaseConfig, /\$e2eDatabaseName !== '' \? \$e2eDatabaseName/);
  assert.match(runner, /spawn\(php, \['-S'/);
  assert.match(runner, /SUXI_E2E_APP_PORT/);
  assert.match(runner, /await stopIsolatedServer\(isolatedServer\)/);
});
