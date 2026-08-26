import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

test('large verifier scripts have executable domain and no-growth governance', () => {
  const registry = JSON.parse(readFileSync('rules/verifier-domain-contract-registry.json', 'utf8'));
  const packageJson = JSON.parse(readFileSync('package.json', 'utf8'));
  assert.equal(registry.schema_version, 'suxios.verifier_domain_registry.v1');
  assert.equal(registry.policy.unregistered_large_script, 'fail_closed');
  assert.equal(registry.policy.maximum_default_claim, 'contract_only');
  assert.ok(registry.domains.length >= 7);
  assert.equal(
    packageJson.scripts['verify:verifier-registry'],
    'node scripts/verify_verifier_domain_registry.mjs',
  );

  const result = spawnSync(process.execPath, ['scripts/verify_verifier_domain_registry.mjs'], {
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, `${result.stdout}\n${result.stderr}`);
  assert.match(result.stdout, /governed large scripts/);
});

test('verifier registry policies drive validation instead of acting as comments', () => {
  const verifier = readFileSync('scripts/verify_verifier_domain_registry.mjs', 'utf8');
  assert.match(verifier, /unregisteredLargeScriptPolicy !== 'fail_closed'/);
  assert.match(verifier, /domain\.claim_limit \|\| maximumDefaultClaim/);
  assert.match(verifier, /!registeredEntrypoints\.has\(script\) && unregisteredLargeScriptPolicy === 'fail_closed'/);
});
