import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/start_local_stack.ps1', 'utf8');

test('local startup prints and verifies exact worktree and full runtime-asset identity', () => {
  assert.match(source, /\[IDENTITY\] repo=.*head=.*worktree=.*public_app_main_sha256=.*runtime_asset_sha256=.*runtime_asset_count=/);
  assert.match(source, /gitCommand\.Source -C \$RepoRoot rev-parse HEAD/);
  assert.match(source, /gitCommand\.Source -C \$RepoRoot status --porcelain=v1/);
  assert.match(source, /function Get-Sha256FileDigest/);
  assert.match(source, /\[System\.IO\.File\]::OpenRead\(\$LiteralPath\)/);
  assert.match(source, /Get-Sha256FileDigest -LiteralPath \$PublicEntryPath/);
  assert.doesNotMatch(source, /Get-FileHash -LiteralPath \$PublicEntryPath/);
  assert.match(source, /verify_runtime_asset_identity\.mjs/);
  assert.match(source, /"--base-url=\$BaseUrl"/);
  assert.match(source, /expected_asset_count[\s\S]*RuntimeAssetCount/);
  assert.match(source, /fetched_asset_count[\s\S]*RuntimeAssetCount/);
  assert.match(source, /if \(-not \(Test-RuntimeIdentity\)\)[\s\S]*different runtime asset manifest/);
  assert.match(source, /Local stack ready: \$BaseUrl identity=/);
});

test('database-only startup does not require or hash a frontend artifact', () => {
  const databaseOnlyGate = source.indexOf('if ($DatabaseOnly) {');
  const projectIdentityCall = source.indexOf('$ProjectIdentity = Get-ProjectIdentity');
  assert.ok(databaseOnlyGate >= 0);
  assert.ok(projectIdentityCall > databaseOnlyGate);
  assert.ok(source.indexOf('return', databaseOnlyGate) < projectIdentityCall);
});
