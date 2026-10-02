import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { closeSync, existsSync, mkdtempSync, openSync, readFileSync, readdirSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = process.cwd();
const php = 'C:\\xampp\\php\\php.exe';
const pwsh = process.env.SUXIOS_LOCAL_BACKUP_PWSH || 'pwsh.exe';
const producer = path.join(root, 'scripts/backup_local_database_encrypted.php');
const wrapper = path.join(root, 'scripts/backup_local_database_encrypted.ps1');
const verifier = path.join(root, 'scripts/verify_local_database_encrypted_backup.php');
const supported = process.platform === 'win32' && existsSync(php);
const cleanEnv = () => Object.fromEntries(Object.entries(process.env).filter(([name]) => !name.startsWith('SUXIOS_LOCAL_BACKUP_')));
const psQuote = value => "'" + value.replaceAll("'", "''") + "'";

function cleanup(directory) {
  const temporaryRoot = realpathSync(tmpdir());
  const resolved = realpathSync(directory);
  const relative = path.relative(temporaryRoot, resolved);
  assert.ok(relative && !relative.startsWith('..') && !path.isAbsolute(relative));
  assert.equal(path.dirname(resolved).toLowerCase(), temporaryRoot.toLowerCase());
  assert.match(path.basename(resolved), /^suxios-backup-(fixture|direct)-[A-Za-z0-9]+$/);
  rmSync(resolved, { recursive: true, force: true });
}

function runWrapper(mode, timeout = 10, idle = 5) {
  const directory = mkdtempSync(path.join(tmpdir(), 'suxios-backup-fixture-'));
  const start = Date.now();
  const result = spawnSync(pwsh, ['-NoLogo', '-NoProfile', '-NonInteractive', '-File', wrapper,
    '-BackupDirectory', directory, '-FixtureMode', mode, '-TimeoutSeconds', String(timeout), '-IdleTimeoutSeconds', String(idle)],
    { cwd: root, env: cleanEnv(), encoding: 'utf8', timeout: 15000, windowsHide: true });
  const metadataPath = readdirSync(directory).find(name => name.endsWith('.json'));
  assert.ok(metadataPath, 'wrapper must persist a safe result');
  const metadata = JSON.parse(readFileSync(path.join(directory, metadataPath), 'utf8').replace(/^\uFEFF/, ''));
  return { directory, result, metadata, elapsed: Date.now() - start };
}

function verifyWithProtectedKey(directory, backupPath, expectedHash) {
  const keyPath = path.join(directory, readdirSync(directory).find(name => name.endsWith('.key.dpapi')));
  const script = `$ErrorActionPreference='Stop';$taskKey=$null;try{
    $taskKey=[Security.Cryptography.ProtectedData]::Unprotect([IO.File]::ReadAllBytes(${psQuote(keyPath)}),$null,[Security.Cryptography.DataProtectionScope]::CurrentUser)
    $env:SUXIOS_LOCAL_BACKUP_TARGET=${psQuote(backupPath)}
    $env:SUXIOS_LOCAL_BACKUP_KEY=[Convert]::ToHexString($taskKey).ToLowerInvariant()
    $env:SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256=${psQuote(expectedHash)}
    $taskResult=& ${psQuote(php)} ${psQuote(verifier)} 2>$null
    if($LASTEXITCODE-ne0){throw 'fixture_verification_failed'}
    $taskResult
  }catch{[Console]::Out.WriteLine('{"status":"failed"}');exit 1}finally{
    Remove-Item Env:SUXIOS_LOCAL_BACKUP_TARGET,Env:SUXIOS_LOCAL_BACKUP_KEY,Env:SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256 -ErrorAction SilentlyContinue
    if($null-ne$taskKey){[Array]::Clear($taskKey,0,$taskKey.Length)}
  }`;
  return spawnSync(pwsh, ['-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64')],
    { env: cleanEnv(), encoding: 'utf8', timeout: 10000, windowsHide: true });
}

test('wrapper encrypts only synthetic pipe data and authenticates the exact bytes', { skip: !supported }, () => {
  const fixture = runWrapper('success');
  try {
    assert.equal(fixture.result.status, 0, fixture.result.stderr);
    assert.equal(fixture.metadata.status, 'verified');
    assert.equal(fixture.metadata.backup.database, 'synthetic_fixture');
    assert.equal(fixture.metadata.usable_for_migration, true);
    assert.equal(fixture.metadata.restore_tested, false);
    assert.equal(fixture.metadata.key_protection, 'Windows CurrentUser DPAPI');
    const payload = Buffer.from('fixture-row-0123456789\n'.repeat(50000));
    const expected = createHash('sha256').update(payload).digest('hex');
    assert.equal(fixture.metadata.backup.plaintext_bytes, payload.length);
    assert.equal(fixture.metadata.verification.plaintext_sha256, expected);
    assert.equal(fixture.metadata.backup.plaintext_sha256, expected);
    assert.equal(fixture.metadata.backup.process_tree_exit_confirmed, true);
    assert.equal(fixture.metadata.verification.plaintext_written_to_disk, false);
    const encrypted = readFileSync(fixture.metadata.backup.backup_path);
    assert.equal(encrypted.includes(payload.subarray(0, 100)), false);
    assert.deepEqual(readdirSync(fixture.directory).map(name => path.extname(name)).sort(), ['.dpapi', '.enc', '.json']);
    // Recompute the outer identity so these failures exercise authenticated chunks, not only the file hash.
    for (const [name, data] of [['corrupt', Buffer.from(encrypted)], ['truncated', encrypted.subarray(0, encrypted.length - 1)]]) {
      if (name === 'corrupt') data[data.length - 1] ^= 1;
      const invalidPath = path.join(fixture.directory, `${name}.sql.enc`);
      writeFileSync(invalidPath, data);
      const result = verifyWithProtectedKey(fixture.directory, invalidPath, createHash('sha256').update(data).digest('hex'));
      assert.equal(result.status, 1);
      assert.deepEqual(JSON.parse(result.stdout), { status: 'failed' });
      assert.equal(result.stderr, '');
    }
  } finally { cleanup(fixture.directory); }
});

for (const [mode, timeout, idle, failure] of [
  ['stall', 5, 1, 'backup_process_idle_timeout'],
  ['child_stall', 5, 1, 'backup_process_idle_timeout'],
  ['heartbeat', 1, 5, 'backup_process_timeout'],
  ['stderr_failure', 10, 5, 'backup_process_failed'],
]) {
  test(`wrapper fails safely for ${mode} and confirms this process tree exits`, { skip: !supported }, () => {
    const fixture = runWrapper(mode, timeout, idle);
    try {
      assert.equal(fixture.result.status, 1, fixture.result.stderr);
      assert.equal(fixture.metadata.status, 'failed');
      assert.equal(fixture.metadata.usable_for_migration, false);
      assert.equal(fixture.metadata.verification, null);
      assert.equal(fixture.metadata.failure, failure);
      assert.equal(fixture.metadata.process_tree_exit_confirmed, true);
      assert.ok(fixture.elapsed < 10000);
      assert.doesNotMatch(fixture.result.stdout + fixture.result.stderr + JSON.stringify(fixture.metadata), /synthetic-private-error|fixture-row|MYSQL_PWD/);
    } finally { cleanup(fixture.directory); }
  });
}

test('bare stream and fixture invocations are rejected before producing data or loading configuration', { skip: !supported }, () => {
  for (const args of [['--stream'], ['--fixture', 'success']]) {
    const result = spawnSync(php, [producer, ...args], { env: cleanEnv(), encoding: 'utf8', timeout: 2000, windowsHide: true });
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.match(result.stderr, /^local_encrypted_backup_failed\r?\n$/);
  }
  const directory = mkdtempSync(path.join(tmpdir(), 'suxios-backup-fixture-'));
  const outputPath = path.join(directory, 'blocked.stdout');
  const descriptor = openSync(outputPath, 'wx');
  try {
    const result = spawnSync(php, [producer, '--fixture', 'success'], {
      env: { ...cleanEnv(), SUXIOS_LOCAL_BACKUP_CONTROLLED_STREAM: '12'.repeat(32), SUXIOS_LOCAL_BACKUP_TARGET: path.join(directory, 'blocked.sql.enc') },
      stdio: ['ignore', descriptor, 'pipe'], encoding: 'utf8', timeout: 2000, windowsHide: true,
    });
    assert.equal(result.status, 1);
    assert.equal(readFileSync(outputPath).length, 0);
  } finally { closeSync(descriptor); cleanup(directory); }
});

test('the original direct PHP environment/JSON API delegates without recursion and never overwrites a target', { skip: !supported }, () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'suxios-backup-direct-'));
  try {
    const target = path.join(directory, 'direct.sql.enc');
    const key = 'ab'.repeat(32); // Synthetic fixture key, never a database credential.
    const environment = { ...cleanEnv(), SUXIOS_LOCAL_BACKUP_TARGET: target, SUXIOS_LOCAL_BACKUP_KEY: key, SUXIOS_LOCAL_BACKUP_FIXTURE_MODE: 'success', SUXIOS_LOCAL_BACKUP_PWSH: pwsh };
    const result = spawnSync(php, [producer], { env: environment, encoding: 'utf8', timeout: 10000, windowsHide: true });
    assert.equal(result.status, 0, result.stderr);
    const backup = JSON.parse(result.stdout);
    assert.equal(backup.status, 'encrypted_local_backup_complete');
    assert.equal(backup.database, 'synthetic_fixture');
    const verification = spawnSync(php, [verifier], { env: { ...environment, SUXIOS_LOCAL_BACKUP_EXPECTED_SHA256: backup.sha256 }, encoding: 'utf8', timeout: 3000, windowsHide: true });
    assert.equal(verification.status, 0);
    assert.equal(JSON.parse(verification.stdout).plaintext_sha256, backup.plaintext_sha256);
    const originalHash = createHash('sha256').update(readFileSync(target)).digest('hex');
    const duplicate = spawnSync(php, [producer], { env: environment, encoding: 'utf8', timeout: 2000, windowsHide: true });
    assert.equal(duplicate.status, 1);
    assert.equal(duplicate.stdout, '');
    assert.equal(createHash('sha256').update(readFileSync(target)).digest('hex'), originalHash);
    const stalled = spawnSync(php, [producer], { env: { ...environment, SUXIOS_LOCAL_BACKUP_TARGET: path.join(directory, 'stall.sql.enc'), SUXIOS_LOCAL_BACKUP_FIXTURE_MODE: 'stall', SUXIOS_LOCAL_BACKUP_TIMEOUT_SECONDS: '5', SUXIOS_LOCAL_BACKUP_IDLE_TIMEOUT_SECONDS: '1' }, encoding: 'utf8', timeout: 5000, windowsHide: true });
    assert.equal(stalled.status, 1);
    assert.equal(stalled.stdout, '');
  } finally { cleanup(directory); }
});
