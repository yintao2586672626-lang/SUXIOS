import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const source = readFileSync('scripts/deploy_tencent_cloud.ps1', 'utf8');
const tarBinary = process.platform === 'win32' ? 'tar.exe' : 'tar';

test('Tencent Cloud release archive excludes local sensitive and runtime paths', () => {
  assert.match(source, /git -C \$hotelRoot archive --format=tar\.gz/);
  assert.match(source, /Dirty-worktree deployment is disabled/);
  assert.match(source, /Test-ForbiddenArchiveEntry/);
  assert.match(source, /tar\.exe -tzf \$archivePath/);
  assert.match(source, /Upload was refused/);
  assert.match(source, /\(dump\|backup\).*\\\.sql/);
  assert.match(source, /Automatic production migrations are disabled/);
  assert.match(source, /StrictHostKeyChecking=yes/);
  assert.match(source, /UserKnownHostsFile=\$KnownHostsPath/);
  assert.match(source, /Server or SSH user contains unsupported characters/);
});

test('git archive keeps ignored backups and runtime data out while retaining migrations', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'suxios-cloud-archive-guard-'));
  const archivePath = join(fixtureRoot, 'release.tar.gz');
  try {
    mkdirSync(join(fixtureRoot, 'app'), { recursive: true });
    mkdirSync(join(fixtureRoot, 'database', 'migrations'), { recursive: true });
    mkdirSync(join(fixtureRoot, '.codex-tmp'), { recursive: true });
    mkdirSync(join(fixtureRoot, '.playwright-cli'), { recursive: true });
    mkdirSync(join(fixtureRoot, 'storage', 'ctrip_profile_store_1'), { recursive: true });
    writeFileSync(join(fixtureRoot, 'app', 'index.php'), '<?php echo "ok";\n', 'utf8');
    writeFileSync(join(fixtureRoot, 'database', 'migrations', '001_safe.sql'), 'SELECT 1;\n', 'utf8');
    writeFileSync(join(fixtureRoot, 'hotelx_dump.sql'), 'test-only fixture\n', 'utf8');
    writeFileSync(join(fixtureRoot, 'hotelx_backup_before_test.sql'), 'test-only fixture\n', 'utf8');
    writeFileSync(join(fixtureRoot, '.codex-tmp', 'scratch.txt'), 'test-only fixture\n', 'utf8');
    writeFileSync(join(fixtureRoot, '.playwright-cli', 'session.json'), 'test-only fixture\n', 'utf8');
    writeFileSync(join(fixtureRoot, '.env'), 'DB_PASS=test-only\n', 'utf8');
    writeFileSync(join(fixtureRoot, 'storage', 'ctrip_profile_store_1', 'Cookies'), 'test-only fixture\n', 'utf8');
    writeFileSync(join(fixtureRoot, '.gitignore'), [
      '/*_dump.sql',
      '/*_backup*.sql',
      '/.codex-tmp/',
      '/.playwright-cli/',
      '/.env',
      '/storage/',
      '',
    ].join('\n'), 'utf8');

    for (const args of [
      ['init'],
      ['config', 'user.name', 'SUXIOS Test'],
      ['config', 'user.email', 'suxios-test@example.invalid'],
      ['add', '.'],
      ['commit', '-m', 'fixture'],
    ]) {
      const git = spawnSync('git', args, { cwd: fixtureRoot, encoding: 'utf8', windowsHide: true });
      assert.equal(git.status, 0, git.stderr || git.stdout);
    }

    const build = spawnSync('git', [
      'archive', '--format=tar.gz', `--output=${archivePath}`, 'HEAD',
    ], { cwd: fixtureRoot, encoding: 'utf8', windowsHide: true });
    assert.equal(build.status, 0, build.stderr || build.stdout);

    const list = spawnSync(tarBinary, ['-tzf', archivePath], {
      encoding: 'utf8',
      windowsHide: true,
    });
    assert.equal(list.status, 0, list.stderr || list.stdout);
    const entries = String(list.stdout || '').replaceAll('\\', '/');
    assert.match(entries, /app\/index\.php/);
    assert.match(entries, /database\/migrations\/001_safe\.sql/);
    assert.doesNotMatch(entries, /hotelx_dump\.sql/);
    assert.doesNotMatch(entries, /hotelx_backup_before_test\.sql/);
    assert.doesNotMatch(entries, /\.codex-tmp/);
    assert.doesNotMatch(entries, /\.playwright-cli/);
    assert.doesNotMatch(entries, /(^|\/)\.env(\r?$|\/)/m);
    assert.doesNotMatch(entries, /ctrip_profile_store_1/);
  } finally {
    rmSync(fixtureRoot, { recursive: true, force: true });
  }
});

test('stage mode stops before backup and every database command', () => {
  const installer = readFileSync('deploy/cloud/install_release.sh', 'utf8');
  const stageExit = installer.indexOf('if [[ $NO_SWITCH -eq 1 ]]');
  const backupRun = installer.indexOf('"$BACKUP_CMD"', stageExit);
  const databaseCheck = installer.indexOf('php think db:check', stageExit);
  assert.ok(stageExit >= 0);
  assert.ok(backupRun > stageExit);
  assert.ok(databaseCheck > stageExit);
  assert.match(installer, /Automatic production migrations are disabled/);
  assert.match(installer, /rollback_and_verify/);
  assert.match(installer, /previous release restored and health verified/);
});
