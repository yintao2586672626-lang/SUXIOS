import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const inspector = readFileSync('scripts/inspect_phase1_ota_live_closure.php', 'utf8');
const evidenceBuilder = readFileSync('scripts/build_phase1_ota_live_closure_evidence.php', 'utf8');

test('Phase1 inspector and evidence builder canonicalize AI blocker order', () => {
  assert.match(
    inspector,
    /function inspection_ai_blocking_missing_codes[\s\S]*usort\(\$codes,[\s\S]*inspection_next_action_family_rank/,
  );
  assert.match(
    inspector,
    /\$aiBlockingCodes = inspection_ai_blocking_missing_codes\(\$missingCodes\);/,
  );
  assert.match(
    evidenceBuilder,
    /function ai_blocking_codes[\s\S]*usort\(\$codes,[\s\S]*next_action_family_rank/,
  );
});
