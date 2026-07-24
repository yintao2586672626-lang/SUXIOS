import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  CREDENTIAL_SENSITIVE_NORMALIZED_KEYS,
  checkBackupCredentialFields,
  checkOtaCredentialRelease,
  checkOtaCredentialRotationAttestation,
  findSensitiveFieldCategories,
} from '../../scripts/lib/ota_credential_checks.mjs';
import { checkLlmConnectivityAttestation } from '../../scripts/lib/llm_attestation_checks.mjs';

const tempRoots = [];

function makeTempRoot() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-ota-credential-check-'));
  tempRoots.push(root);
  fs.mkdirSync(path.join(root, 'database', 'backups'), { recursive: true });
  fs.writeFileSync(path.join(root, '.gitignore'), 'database/backups/\n', 'utf8');
  fs.writeFileSync(path.join(root, '.gitattributes'), 'database/backups/* export-ignore\n', 'utf8');
  return root;
}

function writeBackup(root, name, content) {
  const file = path.join(root, 'database', 'backups', name);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content);
  return file;
}

function sha256(content) {
  return createHash('sha256').update(content).digest('hex');
}

function validAttestation() {
  const reviewedAt = new Date().toISOString().slice(0, 10);
  return {
    reviewed_at: reviewedAt,
    reviewer: 'Hotel Security Owner',
    redaction_checked: true,
    platforms: [
      {
        platform: 'ctrip',
        scope: 'ctrip-hotel-scope',
        credential_types: ['cookie', 'token', 'signature', 'authorization'],
        action: 'rotated',
        evidence_ref: `SEC-CTRIP-${reviewedAt}`,
      },
      {
        platform: 'meituan',
        scope: 'meituan-hotel-scope',
        credential_types: ['cookie', 'token', 'signature', 'authorization'],
        action: 'invalidated',
        evidence_ref: `SEC-MEITUAN-${reviewedAt}`,
      },
    ],
    backup_cleanup: {
      database_backups_action: 'deleted',
      paths_reviewed: ['database/backups'],
      git_tracking_check: `git ls-files database/backups returned no tracked files on ${reviewedAt}`,
      release_readiness_check: `review:release-ota-credentials rerun on ${reviewedAt}`,
    },
  };
}

function validLlmAttestation() {
  return {
    reviewed_at: new Date().toISOString().slice(0, 10),
    reviewer: 'AI Security Owner',
    environment: 'production',
    provider: 'openai',
    model_key: 'production-default',
    model_name: 'production-model',
    base_url: 'https://api.openai.com/v1',
    evidence_ref: 'AI-SEC-1234',
    ai_model_config_enabled: true,
    ai_config_secret_checked: true,
    redaction_checked: true,
    result: {
      status: 'passed',
      response_status: 200,
    },
  };
}

test.after(() => {
  for (const root of tempRoots) {
    const resolved = path.resolve(root);
    assert.ok(resolved.startsWith(path.resolve(os.tmpdir()) + path.sep));
    fs.rmSync(resolved, { recursive: true, force: true });
  }
});

test('backup scan fails closed when git tracking evidence cannot be collected', () => {
  const root = makeTempRoot();
  const result = checkBackupCredentialFields({ repoRoot: root });

  assert.match(result.failures.join('\n'), /git ls-files database\/backups/i);
  assert.doesNotMatch(result.passes.join('\n'), /no git-tracked files/i);
});

test('backup scan fails closed when a binary-looking file cannot be inspected', () => {
  const root = makeTempRoot();
  writeBackup(root, 'opaque-backup.bin', Buffer.from([0x00, 0x01, 0x02, 0x03]));

  const result = checkBackupCredentialFields({ repoRoot: root });

  assert.match(result.failures.join('\n'), /could not be completely scanned|binary-looking/i);
  assert.doesNotMatch(result.passes.join('\n'), /fully scanned/i);
});

test('backup scan covers project OTA credential aliases without printing values', () => {
  const root = makeTempRoot();
  const secretSentinels = [
    'spidertoken="SENTINEL_ONE"',
    'spiderkey="SENTINEL_TWO"',
    'mtgsig="SENTINEL_THREE"',
    'auth_data="SENTINEL_FOUR"',
    'secret_json="SENTINEL_FIVE"',
  ];
  writeBackup(root, 'credential-aliases.sql', secretSentinels.join('\n'));

  const result = checkBackupCredentialFields({ repoRoot: root });
  const output = [...result.failures, ...result.warnings, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /value-bearing OTA credential/i);
  for (const sentinel of ['SENTINEL_ONE', 'SENTINEL_TWO', 'SENTINEL_THREE', 'SENTINEL_FOUR', 'SENTINEL_FIVE']) {
    assert.doesNotMatch(output, new RegExp(sentinel));
  }
});

for (const sensitiveKey of [
  'secret',
  'passwd',
  'password',
  'app_secret',
  'app_key',
  'access_key',
  'tokens',
  'access_token',
  'refresh_token',
  'session_token',
  'credential',
  'credentials',
  'jsessionid',
  'sessionid',
]) {
  test(`backup scan detects unified sensitive key ${sensitiveKey}`, () => {
    const root = makeTempRoot();
    const sentinel = `VALUE_FOR_${sensitiveKey.toUpperCase()}`;
    writeBackup(root, `${sensitiveKey}.sql`, `${sensitiveKey}="${sentinel}"`);

    const result = checkBackupCredentialFields({ repoRoot: root });
    const output = [...result.failures, ...result.warnings, ...result.passes].join('\n');

    assert.match(result.failures.join('\n'), /value-bearing OTA credential/i);
    assert.doesNotMatch(output, new RegExp(sentinel));
  });
}

test('sensitive suffixes are detected in both backup text and nested JSON keys', () => {
  const root = makeTempRoot();
  writeBackup(root, 'suffix.sql', 'api_access_token="SUFFIX_TEXT_SENTINEL"');
  const backup = checkBackupCredentialFields({ repoRoot: root });
  assert.match(backup.failures.join('\n'), /value-bearing OTA credential/i);

  const categories = findSensitiveFieldCategories(
    { nested: { api_access_token: 'SUFFIX_JSON_SENTINEL' } },
    new Set(CREDENTIAL_SENSITIVE_NORMALIZED_KEYS),
  );
  assert.deepEqual(categories, ['accesstoken']);
});

test('backup scan traverses source-like nested directory names instead of silently skipping them', () => {
  const root = makeTempRoot();
  writeBackup(root, 'runtime/vendor/node_modules/hidden.sql', 'token="NESTED_DIRECTORY_SENTINEL"');

  const result = checkBackupCredentialFields({ repoRoot: root });
  const output = [...result.failures, ...result.warnings, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /value-bearing OTA credential/i);
  assert.doesNotMatch(result.passes.join('\n'), /fully scanned/i);
  assert.doesNotMatch(output, /NESTED_DIRECTORY_SENTINEL/);
});

test('identifier-only backup findings are warnings and are not called proven secrets', () => {
  const root = makeTempRoot();
  writeBackup(root, 'schema-only.sql', 'CREATE TABLE `sample` (`usertoken` TEXT);\nINSERT INTO `sample` (`usertoken`) VALUES (NULL);\n');

  const result = checkBackupCredentialFields({ repoRoot: root });
  const failures = result.failures.join('\n');
  const warnings = result.warnings.join('\n');

  assert.doesNotMatch(failures, /sensitive credential identifiers require review/i);
  assert.match(warnings, /sensitive credential identifiers require review/i);
  assert.match(warnings, /not proof of stored credential values/i);
  assert.doesNotMatch(failures, /value-bearing OTA credential/i);
});

test('sanitized backup attestation must bind every current backup path to its actual sha256', () => {
  const root = makeTempRoot();
  const content = 'CREATE TABLE `sample` (`usertoken` TEXT);\n';
  writeBackup(root, 'schema-only.sql', content);
  const attestation = validAttestation();
  attestation.backup_cleanup.database_backups_action = 'sanitized';
  attestation.backup_cleanup.files = [{
    path: 'database/backups/schema-only.sql',
    sha256: '0'.repeat(64),
  }];
  const file = path.join(root, 'ota-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const mismatch = checkOtaCredentialRelease({
    repoRoot: root,
    attestationPath: file,
    requireOutsideRepo: false,
  });
  assert.match(mismatch.failures.join('\n'), /sanitized backup.*sha256|hash binding/i);

  attestation.backup_cleanup.files[0].sha256 = sha256(content);
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');
  const matched = checkOtaCredentialRelease({
    repoRoot: root,
    attestationPath: file,
    requireOutsideRepo: false,
  });
  assert.doesNotMatch(matched.failures.join('\n'), /sanitized backup.*sha256|hash binding/i);
});

test('deleted or encrypted backup attestations contradict backups that are still present', () => {
  const root = makeTempRoot();
  writeBackup(root, 'still-present.sql', 'CREATE TABLE `sample` (`id` INT);\n');

  for (const action of ['deleted', 'encrypted_archive']) {
    const attestation = validAttestation();
    attestation.backup_cleanup.database_backups_action = action;
    const file = path.join(root, `${action}.json`);
    fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');
    const result = checkOtaCredentialRelease({ repoRoot: root, attestationPath: file });
    assert.match(result.failures.join('\n'), /contradicts.*backup|backup.*still present/i);
  }
});

test('attestation rejects values that merely contain a redaction keyword', () => {
  const root = makeTempRoot();
  const attestation = validAttestation();
  attestation.token = 'masked REAL_SECRET_SENTINEL';
  const file = path.join(root, 'ota-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkOtaCredentialRotationAttestation({
    repoRoot: root,
    attestationPath: file,
    requireOutsideRepo: false,
  });
  const output = [...result.failures, ...result.warnings, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /unredacted sensitive fields/i);
  assert.doesNotMatch(output, /REAL_SECRET_SENTINEL/);
});

test('attestation accepts exact redaction sentinels including Bearer redaction', () => {
  const root = makeTempRoot();
  const attestation = validAttestation();
  attestation.token = '<redacted>';
  attestation.platforms[0].evidence_ref = 'SEC Bearer <redacted>';
  const file = path.join(root, 'ota-redacted-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkOtaCredentialRotationAttestation({ repoRoot: root, attestationPath: file });

  assert.doesNotMatch(result.failures.join('\n'), /credential material|unredacted sensitive fields/i);
});

test('attestation output never includes arbitrary JSON key paths', () => {
  const root = makeTempRoot();
  const attestation = validAttestation();
  attestation.ARBITRARY_SECRET_PARENT_SENTINEL = { token: 'NESTED_SECRET_VALUE_SENTINEL' };
  const file = path.join(root, 'ota-nested-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkOtaCredentialRotationAttestation({ repoRoot: root, attestationPath: file });
  const output = [...result.failures, ...result.warnings, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /unredacted sensitive fields/i);
  assert.doesNotMatch(output, /ARBITRARY_SECRET_PARENT_SENTINEL|NESTED_SECRET_VALUE_SENTINEL/);
});

test('LLM attestation also rejects values that merely contain a redaction keyword', () => {
  const root = makeTempRoot();
  const file = path.join(root, 'llm-attestation.json');
  const attestation = validLlmAttestation();
  attestation.token = 'internal ticket REAL_LLM_SECRET_SENTINEL';
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkLlmConnectivityAttestation({ repoRoot: root, attestationPath: file });
  const output = [...result.failures, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /unredacted sensitive fields/i);
  assert.doesNotMatch(output, /REAL_LLM_SECRET_SENTINEL/);
});

test('LLM attestation detects nested aliases and URL credential material without printing values', () => {
  const root = makeTempRoot();
  const attestation = validLlmAttestation();
  attestation.details = {
    access_token: 'ACCESS_TOKEN_SENTINEL',
    refresh_token: 'REFRESH_TOKEN_SENTINEL',
    session_token: 'SESSION_TOKEN_SENTINEL',
    password: 'PASSWORD_SENTINEL',
  };
  attestation.base_url = 'https://user:URL_PASSWORD_SENTINEL@api.vendor.cn/v1?api_key=URL_QUERY_SENTINEL';
  const file = path.join(root, 'llm-nested-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkLlmConnectivityAttestation({ repoRoot: root, attestationPath: file });
  const output = [...result.failures, ...result.passes].join('\n');

  assert.match(result.failures.join('\n'), /secret material|unredacted sensitive fields/i);
  for (const sentinel of ['ACCESS_TOKEN_SENTINEL', 'REFRESH_TOKEN_SENTINEL', 'SESSION_TOKEN_SENTINEL', 'PASSWORD_SENTINEL', 'URL_PASSWORD_SENTINEL', 'URL_QUERY_SENTINEL']) {
    assert.doesNotMatch(output, new RegExp(sentinel));
  }
});

test('LLM attestation accepts exact redaction sentinels including Bearer redaction', () => {
  const root = makeTempRoot();
  const attestation = validLlmAttestation();
  attestation.api_key = '<redacted>';
  attestation.authorization = 'Bearer <redacted>';
  const file = path.join(root, 'llm-redacted-attestation.json');
  fs.writeFileSync(file, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

  const result = checkLlmConnectivityAttestation({ repoRoot: root, attestationPath: file });

  assert.doesNotMatch(result.failures.join('\n'), /secret material|unredacted sensitive fields/i);
});

test('malformed attestation errors use fixed safe codes and never echo input', () => {
  const root = makeTempRoot();
  const sentinel = 'MALFORMED_SECRET_SENTINEL_123456789';
  const file = path.join(root, 'malformed-attestation.json');
  fs.writeFileSync(file, sentinel, 'utf8');

  const ota = checkOtaCredentialRotationAttestation({ repoRoot: root, attestationPath: file });
  const llm = checkLlmConnectivityAttestation({ repoRoot: root, attestationPath: file });
  for (const result of [ota, llm]) {
    const output = [...result.failures, ...(result.warnings || []), ...result.passes].join('\n');
    assert.match(output, /parse_error/i);
    assert.doesNotMatch(output, new RegExp(sentinel.slice(0, 10)));
  }
});

test('release readiness uses the combined OTA backup and attestation verifier', () => {
  const source = fs.readFileSync(path.resolve('scripts/verify_release_readiness.mjs'), 'utf8');

  assert.match(source, /checkOtaCredentialRelease/);
  assert.doesNotMatch(source, /checkBackupCredentialFields|checkOtaAttestationFile/);
});
