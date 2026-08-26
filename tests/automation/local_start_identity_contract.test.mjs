import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/start_local_stack.ps1', 'utf8');

test('local startup prints and verifies exact worktree and public-entry identity', () => {
  assert.match(source, /\[IDENTITY\] repo=.*head=.*worktree=.*public_app_main_sha256=/);
  assert.match(source, /gitCommand\.Source -C \$RepoRoot rev-parse HEAD/);
  assert.match(source, /gitCommand\.Source -C \$RepoRoot status --porcelain=v1/);
  assert.match(source, /function Get-Sha256FileDigest/);
  assert.match(source, /\[System\.IO\.File\]::OpenRead\(\$LiteralPath\)/);
  assert.match(source, /Get-Sha256FileDigest -LiteralPath \$PublicEntryPath/);
  assert.doesNotMatch(source, /Get-FileHash -LiteralPath \$PublicEntryPath/);
  assert.match(source, /DownloadData\("\$\{BaseUrl\}app-main\.min\.js\?v=startup-identity-probe"\)/);
  assert.match(source, /if \(-not \(Test-RuntimeIdentity\)\)[\s\S]*different public\/app-main\.min\.js digest/);
  assert.match(source, /Local stack ready: \$BaseUrl identity=/);
});

test('database-only startup does not require or hash a frontend artifact', () => {
  const databaseOnlyGate = source.indexOf('if ($DatabaseOnly) {');
  const projectIdentityCall = source.indexOf('$ProjectIdentity = Get-ProjectIdentity');
  assert.ok(databaseOnlyGate >= 0);
  assert.ok(projectIdentityCall > databaseOnlyGate);
  assert.ok(source.indexOf('return', databaseOnlyGate) < projectIdentityCall);
});
