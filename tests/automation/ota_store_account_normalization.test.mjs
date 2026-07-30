import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const scriptPath = 'scripts/normalize_ota_store_accounts.php';

test('OTA store account normalization is dry-run by default and transactional on execute', () => {
  assert.equal(existsSync(scriptPath), true, 'normalization script must exist');
  const source = readFileSync(scriptPath, 'utf8');
  assert.match(source, /--execute/);
  assert.match(source, /HotelDataMergeService/);
  assert.match(source, /MERGE 113 -> 125/);
  assert.match(source, /Db::transaction/);
  assert.match(source, /if \(!\$alreadyConsolidated\)/);
  assert.match(source, /binding_status[^\n]+revoked/);
  assert.match(source, /enabled[^\n]+0/);
  assert.match(source, /json_encode/);
  assert.doesNotMatch(source, /secret_json|cookie_value|password/);
});
