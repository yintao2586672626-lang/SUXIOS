import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/run_local_ai_governance_acceptance.php', 'utf8');

test('local AI governance run is bound to the exact five-case snapshot', () => {
  assert.match(source, /Db::transaction\(static function/);
  assert.match(source, /->lock\(true\)/);
  assert.match(source, /local_ai_governance_case_readback_mismatch/);
  assert.match(source, /\$attempt <= 3/);
  assert.match(source, /where\('prompt_version', \$promptVersion\)/);
  assert.match(source, /whereIn\('case_key', \$caseKeys\)/);
  assert.match(source, /\$actualCaseKeys !== \$caseKeys/);
  for (const field of ['input_json', 'expected_json', 'metric_json', 'prompt_version']) {
    assert.match(source, new RegExp(`'${field}' =>`));
  }
  assert.match(source, /'case_snapshot_digest' => \$caseDigest/);
  assert.match(source, /'case_snapshot_count' => count\(\$caseSnapshot\)/);
});
