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
  assert.match(runner, /env\.SUXI_E2E_USER_ID = String\(seededUserId\);/);
  assert.match(helper, /getenv\('SUXI_E2E_USER_ID'\)/);
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
