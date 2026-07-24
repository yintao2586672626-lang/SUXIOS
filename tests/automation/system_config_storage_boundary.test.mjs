import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('system configuration tables have one explicit non-overlapping storage boundary', () => {
  const verifier = readFileSync('scripts/verify_system_config_storage_boundary.php', 'utf8');
  const migration = readFileSync('database/migrations/20260717_reconcile_system_config_table_boundary.sql', 'utf8');
  const initialization = readFileSync('database/init_full.sql', 'utf8');
  const documentation = readFileSync('docs/system_config_storage_boundary.md', 'utf8');

  assert.match(verifier, /duplicate_keys/);
  assert.match(verifier, /ota_keys_in_system_config/);
  assert.match(verifier, /general_keys_in_system_configs/);
  assert.match(verifier, /keys_only_no_values_printed/);
  assert.match(migration, /general_copy\.`config_key` IN \('ctrip_config_list', 'meituan_config_list'\)/);
  assert.match(migration, /ota_copy\.`config_key` COLLATE utf8mb4_unicode_ci\s*= general_copy\.`config_key` COLLATE utf8mb4_unicode_ci/);
  assert.match(migration, /COALESCE\(general_copy\.`config_value`, ''\) COLLATE utf8mb4_unicode_ci\s*= COALESCE\(ota_copy\.`config_value`, ''\) COLLATE utf8mb4_unicode_ci/);
  assert.match(migration, /general_copy\.`config_key` COLLATE utf8mb4_unicode_ci\s*= ota_copy\.`config_key` COLLATE utf8mb4_unicode_ci/);
  assert.match(migration, /COALESCE\(ota_copy\.`config_value`, ''\) COLLATE utf8mb4_unicode_ci\s*= COALESCE\(general_copy\.`config_value`, ''\) COLLATE utf8mb4_unicode_ci/);
  assert.match(initialization, /20260717_reconcile_system_config_table_boundary\.sql/);
  assert.match(documentation, /同一个 `config_key` 不得同时存在于两张表/);
  assert.match(documentation, /禁止静默覆盖/);
});
