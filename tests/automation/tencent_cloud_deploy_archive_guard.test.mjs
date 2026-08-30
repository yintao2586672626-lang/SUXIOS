import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
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
  assert.match(source, /\[switch\]\$ActivateStaged/);
  assert.match(source, /StageOnly and ActivateStaged are separate release phases/);
  assert.match(source, /"--source-commit", \$sourceCommit/);
  assert.match(source, /\$remoteArgs \+= "--activate-existing"/);
  assert.match(source, /if \(-not \$ActivateStaged\) \{\s*& \$scp @sshOptions \$archivePath/s);
  assert.match(source, /StrictHostKeyChecking=yes/);
  assert.match(source, /UserKnownHostsFile=\$KnownHostsPath/);
  assert.match(source, /Server or SSH user contains unsupported characters/);
  assert.match(source, /\[string\]\$HealthHost = "www\.glslsuxi\.cn"/);
  assert.match(source, /Health-check host contains unsupported characters/);
  assert.match(source, /"--health-host", \$HealthHost/);
  assert.doesNotMatch(source, /"--health-host", \$Server/);
});

test('release refuses an archive missing a component referenced by the public index', () => {
  const installer = readFileSync('deploy/cloud/install_release.sh', 'utf8');
  const guardDefinition = installer.indexOf('verify_public_component_assets()');
  const guardCalls = [...installer.matchAll(/^\s+verify_public_component_assets$/gm)]
    .map((match) => match.index);
  const currentSwitch = installer.indexOf('mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"');

  assert.ok(guardDefinition >= 0);
  assert.equal(guardCalls.length, 2);
  assert.ok(guardCalls.every((guardCall) => guardCall > guardDefinition));
  assert.ok(guardCalls.every((guardCall) => currentSwitch > guardCall));
  assert.match(installer, /components\/\[A-Za-z0-9\._\/-\]\+\\\.js/);
  assert.match(installer, /Release is missing index component asset/);

  const index = readFileSync('public/index.html', 'utf8');
  const referencedComponents = [...index.matchAll(/components\/[^"?\s]+\.js/g)]
    .map((match) => match[0]);
  assert.ok(referencedComponents.length > 0);
  for (const component of referencedComponents) {
    assert.doesNotThrow(() => readFileSync(`public/${component}`, 'utf8'));
  }
});

test('git archive keeps ignored backups and runtime data out while retaining migrations', () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), 'suxios-cloud-archive-guard-'));
  const archivePath = join(fixtureRoot, 'release.tar.gz');
  const repeatedArchivePath = join(fixtureRoot, 'release-repeat.tar.gz');
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
    const repeatedBuild = spawnSync('git', [
      'archive', '--format=tar.gz', `--output=${repeatedArchivePath}`, 'HEAD',
    ], { cwd: fixtureRoot, encoding: 'utf8', windowsHide: true });
    assert.equal(repeatedBuild.status, 0, repeatedBuild.stderr || repeatedBuild.stdout);
    const archiveSha256 = (filePath) => createHash('sha256')
      .update(readFileSync(filePath))
      .digest('hex');
    assert.equal(archiveSha256(repeatedArchivePath), archiveSha256(archivePath));

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

test('staged activation is identity-bound and rejoins the guarded switch and rollback path', () => {
  const installer = readFileSync('deploy/cloud/install_release.sh', 'utf8');
  const localArchiveBuild = source.indexOf('git -C $hotelRoot archive --format=tar.gz');
  const localArchiveHash = source.indexOf('Get-FileHash -LiteralPath $archivePath -Algorithm SHA256');
  const activateRemoteArgument = source.indexOf('$remoteArgs += "--activate-existing"');
  const manifestVerification = installer.indexOf('verify_staged_release_manifest');
  const activationBranch = installer.indexOf('if [[ $ACTIVATE_EXISTING -eq 1 ]]');
  const manifestVerificationCall = installer.indexOf('verify_staged_release_manifest', activationBranch);
  const appSmoke = installer.indexOf('sudo -u www-data php think list --raw');
  const stageManifestWrite = installer.indexOf('write_release_manifest', manifestVerificationCall);
  const stageExit = installer.indexOf('if [[ $NO_SWITCH -eq 1 ]]', stageManifestWrite);
  const backupRun = installer.indexOf('backup_output=', stageExit);
  const databaseCheck = installer.indexOf('php think db:check', backupRun);
  const currentSwitch = installer.indexOf('mv -Tf "$ROLLBACK_LINK" "$CURRENT_LINK"', databaseCheck);
  const rollback = installer.indexOf('rollback_and_verify()', currentSwitch);

  assert.ok(localArchiveBuild >= 0);
  assert.ok(localArchiveHash > localArchiveBuild);
  assert.ok(activateRemoteArgument > localArchiveHash);
  assert.ok(manifestVerification >= 0);
  assert.ok(activationBranch > manifestVerification);
  assert.ok(manifestVerificationCall > activationBranch);
  assert.ok(stageManifestWrite > appSmoke);
  assert.ok(stageManifestWrite > manifestVerificationCall);
  assert.ok(stageExit > stageManifestWrite);
  assert.ok(backupRun > stageExit);
  assert.ok(databaseCheck > backupRun);
  assert.ok(currentSwitch > databaseCheck);
  assert.ok(rollback > currentSwitch);

  assert.match(installer, /--source-commit\) SOURCE_COMMIT="\$2"/);
  assert.match(installer, /--activate-existing\) ACTIVATE_EXISTING=1/);
  assert.match(installer, /Invalid source commit/);
  assert.match(installer, /Activation of an existing staged release cannot be combined with --no-switch/);
  assert.match(installer, /MANIFEST_FILE="\$RELEASE_DIR\/\.suxios-release-manifest"/);
  assert.match(installer, /! -f "\$MANIFEST_FILE" \|\| -L "\$MANIFEST_FILE"/);
  assert.match(installer, /manifest_commit" != "\$SOURCE_COMMIT"/);
  assert.match(installer, /manifest_sha256" != "\$EXPECTED_SHA256"/);
  assert.match(installer, /Staged release identity does not match the requested clean local commit and archive/);
  assert.match(installer, /test -L "\$RELEASE_DIR\/\.env"/);
  assert.match(installer, /source_commit=%s sha256=%s/);
  assert.match(installer, /mode=%s/);
});

test('opt-in cloud OTA runtime is reproducible and refuses an unsupported Node version', () => {
  const installer = readFileSync('deploy/cloud/install_release.sh', 'utf8');
  assert.match(installer, /SUXIOS_CLOUD_NODE_RUNTIME/);
  assert.match(installer, /node_major/);
  assert.match(installer, /node_major < 20/);
  assert.match(installer, /npm ci --omit=dev --ignore-scripts --no-audit --no-fund/);
  assert.match(installer, /await import\('playwright-core'\); await import\('cloakbrowser'\)/);
});
