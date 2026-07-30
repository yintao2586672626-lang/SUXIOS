import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { isPlaceholder } from './release_env_checks.mjs';
import { safeJsonParseErrorCode } from './safe_json_parse_error.mjs';

const RELEASE_EVIDENCE_MAX_AGE_DAYS = 30;

const OTA_SENSITIVE_KEY_SOURCE = [
  'cookies?',
  'set[_-]?cookies?',
  '(?:proxy[_-]?)?authorization(?:[_-]?header)?',
  'auth[_-]?data',
  '(?:user|access|refresh|session|spider|auth)[_-]?tokens?',
  'tokens?',
  'user[_-]?sign',
  'signature',
  'mtg[_-]?sig',
  '(?:spider|api|app|access)[_-]?keys?',
  '(?:client|app)[_-]?secrets?',
  'secrets?(?:[_-]?json)?',
  'headers?(?:[_-]?json)?',
  'payload[_-]?json',
  'encrypted[_-]?payload',
  'ciphertext',
  'credentials?',
  '(?:j[_-]?)?session[_-]?id',
  'sid',
  '_mtsi_eb_u',
  'pass(?:word|wd)',
].join('|');

export const CREDENTIAL_SENSITIVE_NORMALIZED_KEYS = Object.freeze([
  'cookie', 'cookies', 'setcookie',
  'authorization', 'authorizationheader', 'proxyauthorization', 'authdata',
  'token', 'tokens', 'usertoken', 'accesstoken', 'refreshtoken', 'sessiontoken', 'spidertoken', 'authtoken',
  'usersign', 'signature', 'mtgsig',
  'spiderkey', 'apikey', 'appkey', 'accesskey', 'clientsecret', 'appsecret',
  'secret', 'secrets', 'secretjson', 'headers', 'headersjson', 'payload', 'payloadjson',
  'encryptedpayload', 'ciphertext',
  'credential', 'credentials', 'jsessionid', 'sessionid', 'sid',
  'mtsiebu', 'password', 'passwd',
]);

const OTA_SENSITIVE_NORMALIZED_KEYS = new Set(CREDENTIAL_SENSITIVE_NORMALIZED_KEYS);

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(repoRoot, filePath) {
  const resolved = resolveInputPath(repoRoot, filePath);
  const relative = path.relative(repoRoot, resolved);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function readTextIfSafe(repoRoot, relativePath, failures) {
  let buffer = null;
  try {
    buffer = fs.readFileSync(resolveInputPath(repoRoot, relativePath));
  } catch {
    failures.push('Backup credential scan could not read a backup file (backup_file_read_error).');
    return null;
  }
  if (buffer.includes(0)) {
    failures.push('Backup credential scan could not completely scan a binary-looking backup file (backup_binary_file). Move encrypted archives to controlled storage outside database/backups and rerun the gate.');
    return null;
  }
  return {
    text: buffer.toString('utf8'),
    sha256: createHash('sha256').update(buffer).digest('hex'),
  };
}

function walkFiles(repoRoot, dir, failures, output = []) {
  const absolute = path.join(repoRoot, dir);
  if (!fs.existsSync(absolute)) {
    return output;
  }

  let entries = [];
  try {
    entries = fs.readdirSync(absolute, { withFileTypes: true });
  } catch {
    failures.push('Backup credential scan could not read a backup directory (backup_directory_read_error).');
    return output;
  }

  for (const entry of entries) {
    const relative = path.join(dir, entry.name).replace(/\\/g, '/');
    if (entry.isDirectory()) {
      walkFiles(repoRoot, relative, failures, output);
    } else {
      output.push(relative);
    }
  }
  return output;
}

function checkGitTrackedBackups(repoRoot, warnings, failures, passes) {
  const result = spawnSync('git', ['ls-files', 'database/backups'], {
    cwd: repoRoot,
    encoding: 'utf8',
  });

  if (result.error) {
    failures.push(`git ls-files database/backups could not run: ${result.error.message}`);
    return;
  }
  if (result.status !== 0) {
    const detail = String(result.stderr || result.stdout || '').trim();
    failures.push(`git ls-files database/backups did not complete; backup tracking remains unverified${detail ? `: ${detail}` : '.'}`);
    return;
  }

  const tracked = String(result.stdout || '')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (tracked.length > 0) {
    failures.push(`database/backups has git-tracked files: ${tracked.join(', ')}. Remove backup files from Git/release scope before release.`);
    return;
  }

  passes.push('database/backups has no git-tracked files.');
}

export function isRedactedSecretValue(value) {
  if (value === null || value === undefined) {
    return true;
  }
  const text = String(value ?? '').trim();
  return text === ''
    || /^(?:redacted|masked|not stored|not included|null|none|n\/a|<redacted>|<masked>|\[redacted\]|\[masked\]|\*{3,})$/i.test(text);
}

export function isSafeSensitiveFieldValue(value) {
  const text = String(value ?? '').trim();
  if (isRedactedSecretValue(value)) {
    return true;
  }
  const bearer = text.match(/^Bearer\s+(.+)$/i);
  return Boolean(bearer && isRedactedSecretValue(bearer[1]));
}

function sensitiveCategoryForKey(normalizedKey, sensitiveKeys) {
  let matched = '';
  for (const candidate of sensitiveKeys) {
    if ((normalizedKey === candidate || normalizedKey.endsWith(candidate)) && candidate.length > matched.length) {
      matched = candidate;
    }
  }
  return matched;
}

export function findSensitiveFieldCategories(value, sensitiveKeys) {
  const findings = [];
  if (Array.isArray(value)) {
    for (const item of value) {
      findings.push(...findSensitiveFieldCategories(item, sensitiveKeys));
    }
    return findings;
  }
  if (!value || typeof value !== 'object') {
    return findings;
  }
  for (const [key, child] of Object.entries(value)) {
    const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/g, '');
    const sensitiveCategory = sensitiveCategoryForKey(normalizedKey, sensitiveKeys);
    if (sensitiveCategory) {
      const emptyContainer = (Array.isArray(child) && child.length === 0)
        || (child && typeof child === 'object' && !Array.isArray(child) && Object.keys(child).length === 0);
      if (!emptyContainer && !isSafeSensitiveFieldValue(child)) {
        findings.push(sensitiveCategory);
      }
    }
    findings.push(...findSensitiveFieldCategories(child, sensitiveKeys));
  }
  return findings;
}

function isSafeCredentialLiteral(value) {
  const text = String(value ?? '').trim();
  return isSafeSensitiveFieldValue(text)
    || /^(?:true|false|undefined|nil|0|\{\}|\[\])$/i.test(text);
}

function unsafeUrlCredentialCount(value) {
  if (Array.isArray(value)) {
    return value.reduce((count, item) => count + unsafeUrlCredentialCount(item), 0);
  }
  if (value && typeof value === 'object') {
    return Object.values(value).reduce((count, item) => count + unsafeUrlCredentialCount(item), 0);
  }
  if (typeof value !== 'string') {
    return 0;
  }

  let count = 0;
  for (const match of value.matchAll(/https?:\/\/[^\s"'<>]+/gi)) {
    const candidate = String(match[0] || '').replace(/[)\].,;]+$/g, '');
    try {
      const parsed = new URL(candidate);
      if (parsed.username || parsed.password) {
        count += 1;
      }
      for (const [key, queryValue] of parsed.searchParams) {
        const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/g, '');
        if (sensitiveCategoryForKey(normalizedKey, OTA_SENSITIVE_NORMALIZED_KEYS) && !isSafeSensitiveFieldValue(queryValue)) {
          count += 1;
        }
      }
    } catch {
      // Non-URL text is handled by the key/value and Bearer scanners.
    }
  }
  return count;
}

export function credentialRiskSignals(text) {
  const identifierPattern = new RegExp(`(?<![A-Za-z0-9])(?:${OTA_SENSITIVE_KEY_SOURCE})(?![A-Za-z0-9])`, 'gi');
  const assignmentPattern = new RegExp(
    `(?<![A-Za-z0-9])["'\`]?(?:${OTA_SENSITIVE_KEY_SOURCE})["'\`]?(?![A-Za-z0-9])\\s*[:=]\\s*(?:"([^"\\r\\n]*)"|'([^'\\r\\n]*)'|([^,;\\s}\\]\\r\\n]+))`,
    'gi',
  );
  const bearerPattern = /\bBearer\s+([^"'\s,;}\]]+)/gi;
  const identifierMatches = (String(text || '').match(identifierPattern) || []).length;
  let valueBearingMatches = unsafeUrlCredentialCount(String(text || ''));

  for (const match of String(text || '').matchAll(assignmentPattern)) {
    const assigned = match[1] ?? match[2] ?? match[3] ?? '';
    if (!isSafeCredentialLiteral(assigned)) {
      valueBearingMatches += 1;
    }
  }
  for (const match of String(text || '').matchAll(bearerPattern)) {
    if (!isSafeCredentialLiteral(match[1] ?? '')) {
      valueBearingMatches += 1;
    }
  }

  return { identifierMatches, valueBearingMatches };
}

function isDateOnly(value) {
  const text = String(value ?? '').trim();
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    return false;
  }
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const parsed = new Date(Date.UTC(year, month - 1, day));
  return parsed.getUTCFullYear() === year
    && parsed.getUTCMonth() === month - 1
    && parsed.getUTCDate() === day;
}

function isFutureDateOnly(value) {
  const [year, month, day] = String(value ?? '').trim().split('-').map(Number);
  const date = Date.UTC(year, month - 1, day);
  const today = new Date();
  const todayDate = Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate());
  return date > todayDate;
}

function isOlderThanReleaseEvidenceWindow(value) {
  const [year, month, day] = String(value ?? '').trim().split('-').map(Number);
  const date = Date.UTC(year, month - 1, day);
  const today = new Date();
  const todayDate = Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate());
  return todayDate - date > RELEASE_EVIDENCE_MAX_AGE_DAYS * 24 * 60 * 60 * 1000;
}

function isCredentialTypeList(value) {
  if (!Array.isArray(value) || value.length === 0) {
    return false;
  }
  const allowed = new Set([
    'authorization',
    'cookie',
    'signature',
    'token',
    'usertoken',
    'usersign',
  ]);
  return value.every((item) => {
    const text = String(item ?? '').trim().toLowerCase();
    return allowed.has(text);
  });
}

function missingRequiredCredentialCoverage(value) {
  const types = new Set(
    (Array.isArray(value) ? value : [])
      .map((item) => String(item ?? '').trim().toLowerCase())
      .filter(Boolean),
  );
  const requiredFamilies = [
    { label: 'cookie', aliases: ['cookie'] },
    { label: 'token/usertoken', aliases: ['token', 'usertoken'] },
    { label: 'signature/usersign', aliases: ['signature', 'usersign'] },
    { label: 'authorization', aliases: ['authorization'] },
  ];

  return requiredFamilies
    .filter((family) => !family.aliases.some((alias) => types.has(alias)))
    .map((family) => family.label);
}

function isWeakAttestationReviewer(value) {
  const text = String(value ?? '').trim();
  return isPlaceholder(text)
    || /^release-check$/i.test(text)
    || /^Codex release handoff$/i.test(text)
    || /\b(test|fixture|dummy|script|bot)\b/i.test(text);
}

function normalizeOtaPlatform(value) {
  const text = String(value ?? '').trim().toLowerCase();
  if (['ctrip', 'trip', '携程'].includes(text)) {
    return 'ctrip';
  }
  if (['meituan', '美团', 'meituan-dianping', 'dianping'].includes(text)) {
    return 'meituan';
  }
  return text;
}

function collectDraftResidue(value, failures) {
  if (Array.isArray(value)) {
    for (const item of value) {
      collectDraftResidue(item, failures);
    }
    return;
  }

  if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      if (key === '_draft_notice') {
        failures.push('OTA credential rotation attestation contains a draft notice that must be removed before release.');
        continue;
      }
      collectDraftResidue(child, failures);
    }
    return;
  }

  if (typeof value !== 'string') {
    return;
  }

  if (/\bTODO\b|CHANGE_ME|placeholder|Draft only|Do not rename or copy|example\.com/i.test(value)) {
    failures.push('OTA credential rotation attestation contains draft or placeholder text.');
  }
}

function normalizeBackupEvidenceFiles(value, failures) {
  if (!Array.isArray(value) || value.length === 0) {
    failures.push('OTA credential rotation attestation sanitized backup evidence must include backup_cleanup.files with path and sha256 entries.');
    return [];
  }

  const normalized = [];
  const seenPaths = new Set();
  for (const [index, item] of value.entries()) {
    const candidatePath = String(item?.path || '').replace(/\\/g, '/').trim();
    const sha256 = String(item?.sha256 || '').trim().toLowerCase();
    const pathIsSafe = candidatePath.startsWith('database/backups/')
      && !path.posix.isAbsolute(candidatePath)
      && !candidatePath.split('/').includes('..');
    if (!pathIsSafe || !/^[a-f0-9]{64}$/.test(sha256)) {
      failures.push(`OTA credential rotation attestation backup_cleanup.files entry ${index + 1} must contain a safe database/backups path and a 64-character sha256.`);
      continue;
    }
    if (seenPaths.has(candidatePath)) {
      failures.push(`OTA credential rotation attestation backup_cleanup.files entry ${index + 1} duplicates a backup path.`);
      continue;
    }
    seenPaths.add(candidatePath);
    normalized.push({ path: candidatePath, sha256 });
  }
  return normalized;
}

export function checkBackupCredentialFields({ repoRoot }) {
  const failures = [];
  const warnings = [];
  const passes = [];

  const gitignorePath = path.join(repoRoot, '.gitignore');
  const gitignore = fs.existsSync(gitignorePath) ? fs.readFileSync(gitignorePath, 'utf8') : '';
  if (/^database\/backups\/\s*$/m.test(gitignore)) {
    passes.push('database/backups is listed in .gitignore.');
  } else {
    failures.push('database/backups is not listed in .gitignore.');
  }

  const gitattributesPath = path.join(repoRoot, '.gitattributes');
  const gitattributes = fs.existsSync(gitattributesPath) ? fs.readFileSync(gitattributesPath, 'utf8') : '';
  if (/^database\/backups\/\*\s+export-ignore\s*$/m.test(gitattributes)) {
    passes.push('database/backups is excluded from git archive exports.');
  } else {
    failures.push('database/backups is not marked export-ignore in .gitattributes.');
  }

  checkGitTrackedBackups(repoRoot, warnings, failures, passes);

  const backupDir = path.join(repoRoot, 'database/backups');
  if (!fs.existsSync(backupDir)) {
    passes.push('database/backups directory is absent.');
    return { passes, warnings, failures, files: [], discoveredFileCount: 0 };
  }

  let identifierMatches = 0;
  let valueBearingMatches = 0;
  const credentialFiles = [];
  const scannedFiles = [];
  const contentScanFailureStart = failures.length;
  const discoveredFiles = walkFiles(repoRoot, 'database/backups', failures);
  for (const file of discoveredFiles) {
    const scanned = readTextIfSafe(repoRoot, file, failures);
    if (scanned === null) {
      continue;
    }
    const signals = credentialRiskSignals(scanned.text);
    scannedFiles.push({ path: file, sha256: scanned.sha256, ...signals });
    if (signals.identifierMatches > 0 || signals.valueBearingMatches > 0) {
      credentialFiles.push({ file, sha256: scanned.sha256, ...signals });
      identifierMatches += signals.identifierMatches;
      valueBearingMatches += signals.valueBearingMatches;
    }
  }

  if (valueBearingMatches > 0) {
    const fileSummary = credentialFiles
      .map(({ file, sha256, identifierMatches: identifiers, valueBearingMatches: values }) => `${file} (sha256 ${sha256}, value-bearing ${values}, identifiers ${identifiers})`)
      .join(', ');
    failures.push(`database/backups contains value-bearing OTA credential risk signals (${valueBearingMatches} value-bearing matches; ${identifierMatches} sensitive identifiers across ${credentialFiles.length} files: ${fileSummary}). Values are never printed. Rotate or invalidate real credentials, then move, encrypt outside the repository, or sanitize the backups before release.`);
  } else if (identifierMatches > 0) {
    const fileSummary = credentialFiles
      .map(({ file, sha256, identifierMatches: identifiers }) => `${file} (sha256 ${sha256}, identifiers ${identifiers})`)
      .join(', ');
    warnings.push(`database/backups sensitive credential identifiers require review (${identifierMatches} identifiers across ${credentialFiles.length} files: ${fileSummary}). Identifier presence is not proof of stored credential values. A sanitized release claim is accepted only when backup_cleanup.files binds every current backup path to this scan's sha256.`);
  } else if (failures.length === contentScanFailureStart) {
    passes.push('No OTA credential identifiers or value-bearing credential shapes were found in fully scanned database/backups text files.');
  }

  return {
    passes,
    warnings,
    failures,
    files: scannedFiles.map(({ path: file, sha256 }) => ({ path: file, sha256 })),
    discoveredFileCount: discoveredFiles.length,
  };
}

export function checkOtaCredentialRotationAttestation({ repoRoot, attestationPath, requireOutsideRepo = false }) {
  const failures = [];
  const warnings = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);

  if (!fs.existsSync(resolvedPath)) {
    failures.push('OTA credential rotation attestation was not found at the configured path. Set OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE to a controlled attestation JSON before release.');
    return { passes, warnings, failures, cleanupEvidence: null };
  }
  if (requireOutsideRepo && isPathInsideRepo(repoRoot, attestationPath)) {
    failures.push('OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE must point to a controlled location outside the repository.');
  }

  let attestation = null;
  let raw = '';
  try {
    raw = fs.readFileSync(resolvedPath, 'utf8').replace(/^\uFEFF/, '');
    attestation = JSON.parse(raw);
  } catch (error) {
    failures.push(`OTA credential rotation attestation is not valid JSON (${safeJsonParseErrorCode(error)}).`);
    return { passes, warnings, failures, cleanupEvidence: null };
  }
  collectDraftResidue(attestation, failures);

  if (credentialRiskSignals(raw).valueBearingMatches > 0 || unsafeUrlCredentialCount(attestation) > 0) {
    failures.push('OTA credential rotation attestation appears to contain credential material; store only redacted evidence references.');
  }
  const otaSensitiveFields = findSensitiveFieldCategories(
    attestation,
    OTA_SENSITIVE_NORMALIZED_KEYS,
  );
  if (otaSensitiveFields.length > 0) {
    const categories = [...new Set(otaSensitiveFields)].sort();
    failures.push(`OTA credential rotation attestation contains ${otaSensitiveFields.length} unredacted sensitive fields in safe categories: ${categories.join(', ')}`);
  }

  const requiredStringFields = ['reviewed_at', 'reviewer'];
  const missingFields = requiredStringFields.filter((field) => isPlaceholder(attestation[field]));
  if (missingFields.length > 0) {
    failures.push(`OTA credential rotation attestation is incomplete: ${missingFields.join(', ')}`);
    return { passes, warnings, failures, cleanupEvidence: null };
  }
  if (isWeakAttestationReviewer(attestation.reviewer)) {
    failures.push('OTA credential rotation attestation reviewer must be a real accountable reviewer, not a placeholder, test owner, or script identity.');
  }
  if (!isDateOnly(attestation.reviewed_at)) {
    failures.push('OTA credential rotation attestation reviewed_at must use YYYY-MM-DD.');
  } else if (isFutureDateOnly(attestation.reviewed_at)) {
    failures.push('OTA credential rotation attestation reviewed_at must not be in the future.');
  } else if (isOlderThanReleaseEvidenceWindow(attestation.reviewed_at)) {
    failures.push(`OTA credential rotation attestation reviewed_at must be within the ${RELEASE_EVIDENCE_MAX_AGE_DAYS}-day release evidence window.`);
  }

  const platforms = Array.isArray(attestation.platforms) ? attestation.platforms : [];
  if (attestation.redaction_checked !== true) {
    failures.push('OTA credential rotation attestation must confirm redaction_checked=true.');
  }
  if (platforms.length === 0) {
    failures.push('OTA credential rotation attestation must include at least one platform entry.');
  }
  const coveredPlatforms = new Set(platforms.map((platform) => normalizeOtaPlatform(platform?.platform)));
  const missingRequiredPlatforms = ['ctrip', 'meituan'].filter((platform) => !coveredPlatforms.has(platform));
  if (missingRequiredPlatforms.length > 0) {
    failures.push(`OTA credential rotation attestation must include Ctrip and Meituan platform entries; missing: ${missingRequiredPlatforms.join(', ')}.`);
  }
  for (const [index, platform] of platforms.entries()) {
    const missingPlatformFields = ['platform', 'scope', 'action', 'evidence_ref'].filter((field) => isPlaceholder(platform?.[field]));
    if (missingPlatformFields.length > 0) {
      failures.push(`OTA credential rotation attestation platform entry ${index + 1} is incomplete: ${missingPlatformFields.join(', ')}`);
    }
    if (!isCredentialTypeList(platform?.credential_types)) {
      failures.push(`OTA credential rotation attestation platform entry ${index + 1} credential_types must be a non-empty list of known OTA credential types.`);
    } else {
      const missingCoverage = missingRequiredCredentialCoverage(platform.credential_types);
      if (missingCoverage.length > 0) {
        failures.push(`OTA credential rotation attestation platform entry ${index + 1} credential_types must cover cookie, token/usertoken, signature/usersign, and authorization material; missing: ${missingCoverage.join(', ')}.`);
      }
    }
    const action = String(platform?.action || '').trim();
    if (!['rotated', 'invalidated'].includes(action)) {
      failures.push(`OTA credential rotation attestation platform entry ${index + 1} action must be rotated or invalidated; backup cleanup actions belong in backup_cleanup.database_backups_action.`);
    }
  }

  const cleanup = attestation.backup_cleanup || {};
  const cleanupAction = String(cleanup.database_backups_action || '').trim();
  if (!['deleted', 'encrypted_archive', 'sanitized'].includes(cleanupAction)) {
    failures.push('OTA credential rotation attestation backup_cleanup.database_backups_action must be deleted, encrypted_archive, or sanitized.');
  }
  if (!Array.isArray(cleanup.paths_reviewed) || !cleanup.paths_reviewed.includes('database/backups')) {
    failures.push('OTA credential rotation attestation backup_cleanup.paths_reviewed must include database/backups.');
  }
  const cleanupFiles = cleanupAction === 'sanitized'
    ? normalizeBackupEvidenceFiles(cleanup.files, failures)
    : [];
  if (isPlaceholder(cleanup.git_tracking_check)) {
    failures.push('OTA credential rotation attestation backup_cleanup.git_tracking_check is missing or placeholder.');
  } else if (!/git\s+ls-files\s+database\/backups/i.test(String(cleanup.git_tracking_check))) {
    failures.push('OTA credential rotation attestation backup_cleanup.git_tracking_check must record `git ls-files database/backups`.');
  }
  if (isPlaceholder(cleanup.release_readiness_check)) {
    failures.push('OTA credential rotation attestation backup_cleanup.release_readiness_check is missing or placeholder.');
  } else if (!/review:release-readiness|review:release-ota-credentials/i.test(String(cleanup.release_readiness_check))) {
    failures.push('OTA credential rotation attestation backup_cleanup.release_readiness_check must record a release readiness or OTA credential verifier rerun.');
  }

  if (failures.length === 0) {
    passes.push('OTA credential rotation attestation is present and complete.');
  }

  return {
    passes,
    warnings,
    failures,
    cleanupEvidence: {
      action: cleanupAction,
      files: cleanupFiles,
    },
  };
}

export function checkOtaCredentialRelease({ repoRoot, attestationPath, requireOutsideRepo = false }) {
  const backup = checkBackupCredentialFields({ repoRoot });
  const attestation = checkOtaCredentialRotationAttestation({ repoRoot, attestationPath, requireOutsideRepo });
  const bindingFailures = [];
  const cleanup = attestation.cleanupEvidence;
  if (cleanup?.action === 'sanitized') {
    const currentFiles = new Map((backup.files || []).map((item) => [item.path, item.sha256]));
    const attestedFiles = new Map((cleanup.files || []).map((item) => [item.path, item.sha256]));
    const exactBinding = currentFiles.size === attestedFiles.size
      && [...currentFiles].every(([file, sha256]) => attestedFiles.get(file) === sha256);
    if (!exactBinding) {
      bindingFailures.push('Sanitized backup hash binding does not exactly match every current database/backups path and sha256.');
    }
  } else if (['deleted', 'encrypted_archive'].includes(cleanup?.action) && backup.discoveredFileCount > 0) {
    bindingFailures.push(`OTA backup cleanup action ${cleanup.action} contradicts backup files that are still present in database/backups.`);
  }
  return {
    passes: [...backup.passes, ...attestation.passes],
    warnings: [...backup.warnings, ...attestation.warnings],
    failures: [...backup.failures, ...attestation.failures, ...bindingFailures],
  };
}
