import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const backup = readFileSync('deploy/backup/suxios-db-backup', 'utf8');
const restore = readFileSync('deploy/backup/suxios-db-restore-verify', 'utf8');
const installer = readFileSync('deploy/cloud/install_release.sh', 'utf8');
const pull = readFileSync('scripts/pull_tencent_cloud_backup.ps1', 'utf8');

test('backup reads and validates DB_NAME instead of hard-coding a database', () => {
  assert.match(backup, /DB_NAME/);
  assert.match(backup, /\^\[A-Za-z0-9_\]\{1,64\}\$/);
  assert.doesNotMatch(backup, /database='hotelx_cloud'/);
  assert.match(backup, /backup_failed=already_running/);
  assert.match(backup, /exit 75/);
});

test('release activation requires a fresh verified backup artifact', () => {
  assert.match(installer, /backup_file=/);
  assert.match(installer, /sha256sum -c/);
  assert.match(installer, /gzip -t "\$backup_file"/);
  assert.ok(installer.indexOf('backup_file=') < installer.indexOf('php think db:check'));
});

test('restore verification checks users and hotels only inside the temporary restore database', () => {
  assert.match(restore, /table_name IN \('users', 'hotels'\)/);
  assert.ok(restore.includes('FROM \\`${verify_database}\\`.users'));
  assert.ok(restore.includes('FROM \\`${verify_database}\\`.hotels'));
  assert.ok(restore.includes('DROP DATABASE IF EXISTS \\`${verify_database}\\`'));
  assert.doesNotMatch(restore, /DROP DATABASE IF EXISTS hotelx/);
});

test('backup pull preserves a matching checksum for already-downloaded files', () => {
  assert.match(pull, /\$finalChecksumExists = Test-Path -LiteralPath \$finalChecksum -PathType Leaf/);
  assert.match(pull, /existing local checksum does not match the verified cloud backup/);
  assert.match(pull, /if \(-not \$finalChecksumExists\) \{\s*Move-Item -LiteralPath \$stagedChecksum -Destination \$finalChecksum/);
  assert.match(pull, /Verified backup checksum was not persisted locally/);
});
