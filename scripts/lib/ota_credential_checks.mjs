import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { isPlaceholder } from './release_env_checks.mjs';

const RELEASE_EVIDENCE_MAX_AGE_DAYS = 30;

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(repoRoot, filePath) {
  const resolved = resolveInputPath(repoRoot, filePath);
  const relative = path.relative(repoRoot, resolved);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function readTextIfSafe(repoRoot, relativePath, warnings) {
  const buffer = fs.readFileSync(resolveInputPath(repoRoot, relativePath));
  if (buffer.includes(0)) {
    warnings.push(`Skipped binary-looking backup file during credential scan: ${relativePath}`);
    return null;
  }
  return buffer.toString('utf8');
}

function walkFiles(repoRoot, dir, warnings, output = []) {
  const absolute = path.join(repoRoot, dir);
  if (!fs.existsSync(absolute)) {
    return output;
  }

  let entries = [];
  try {
    entries = fs.readdirSync(absolute, { withFileTypes: true });
  } catch {
    warnings.push(`Skipped unreadable local path during release scan: ${dir}`);
    return output;
  }

  for (const entry of entries) {
    const relative = path.join(dir, entry.name).replace(/\\/g, '/');
    if (entry.isDirectory()) {
      if (['vendor', 'node_modules', '.git', 'runtime', '.pytest_cache'].includes(entry.name)) {
        continue;
      }
      walkFiles(repoRoot, relative, warnings, output);
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
    warnings.push(`git ls-files database/backups could not run: ${result.error.message}`);
    return;
  }
  if (result.status !== 0) {
    const detail = String(result.stderr || result.stdout || '').trim();
    warnings.push(`git ls-files database/backups did not complete; verify backup tracking manually${detail ? `: ${detail}` : '.'}`);
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

function isRedactedSecretValue(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /redacted|masked|not stored|not included|secure record|internal ticket/i.test(text)
    || /^\*+$/.test(text)
    || /^<[^>]*redact[^>]*>$/i.test(text);
}

function findSensitiveFieldValues(value, sensitiveKeys, pathParts = []) {
  const findings = [];
  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      findings.push(...findSensitiveFieldValues(item, sensitiveKeys, [...pathParts, String(index)]));
    }
    return findings;
  }
  if (!value || typeof value !== 'object') {
    return findings;
  }
  for (const [key, child] of Object.entries(value)) {
    const childPath = [...pathParts, key];
    const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/g, '');
    if (sensitiveKeys.has(normalizedKey) && typeof child === 'string' && !isRedactedSecretValue(child)) {
      findings.push(childPath.join('.'));
    }
    findings.push(...findSensitiveFieldValues(child, sensitiveKeys, childPath));
  }
  return findings;
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

function collectDraftResidue(value, failures, pathParts = []) {
  const pathLabel = pathParts.length > 0 ? pathParts.join('.') : '<root>';
  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      collectDraftResidue(item, failures, [...pathParts, String(index)]);
    }
    return;
  }

  if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      if (key === '_draft_notice') {
        failures.push(`OTA credential rotation attestation ${[...pathParts, key].join('.')} must be removed before release.`);
        continue;
      }
      collectDraftResidue(child, failures, [...pathParts, key]);
    }
    return;
  }

  if (typeof value !== 'string') {
    return;
  }

  if (/\bTODO\b|CHANGE_ME|placeholder|Draft only|Do not rename or copy|example\.com/i.test(value)) {
    failures.push(`OTA credential rotation attestation ${pathLabel} still contains draft or placeholder text.`);
  }
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
    return { passes, warnings, failures };
  }

  const credentialPattern = /usertoken|usersign|cookie\s*[:=]|authorization\s*[:=]|Bearer\s+\S+|access[_-]?token|refresh[_-]?token|session[_-]?token|api[_-]?key/gi;
  let credentialMatches = 0;
  const credentialFiles = [];
  for (const file of walkFiles(repoRoot, 'database/backups', warnings)) {
    const text = readTextIfSafe(repoRoot, file, warnings);
    if (text === null) {
      continue;
    }
    const matches = text.match(credentialPattern);
    if (matches) {
      credentialFiles.push({ file, matches: matches.length });
      credentialMatches += matches.length;
    }
  }

  if (credentialMatches > 0) {
    const fileSummary = credentialFiles
      .map(({ file, matches }) => `${file} (${matches})`)
      .join(', ');
    failures.push(`database/backups contains OTA credential-shaped fields (${credentialMatches} matches across ${credentialFiles.length} files: ${fileSummary}). Rotate real credentials and exclude backups from release packages.`);
  } else {
    passes.push('No OTA credential-shaped fields were found in database/backups text files.');
  }

  return { passes, warnings, failures };
}

export function checkOtaCredentialRotationAttestation({ repoRoot, attestationPath, requireOutsideRepo = false }) {
  const failures = [];
  const warnings = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);

  if (!fs.existsSync(resolvedPath)) {
    failures.push(`OTA credential rotation attestation was not found: ${attestationPath}. Set OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE to a controlled attestation JSON before release.`);
    return { passes, warnings, failures };
  }
  if (requireOutsideRepo && isPathInsideRepo(repoRoot, attestationPath)) {
    failures.push(`OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE must point to a controlled location outside the repository, not ${attestationPath}.`);
  }

  let attestation = null;
  let raw = '';
  try {
    raw = fs.readFileSync(resolvedPath, 'utf8').replace(/^\uFEFF/, '');
    attestation = JSON.parse(raw);
  } catch (error) {
    failures.push(`OTA credential rotation attestation is not valid JSON: ${error.message}`);
    return { passes, warnings, failures };
  }
  collectDraftResidue(attestation, failures);

  if (/("?usertoken"?\s*[:=]\s*['"]?[^'",\s]{8,}|"?usersign"?\s*[:=]\s*['"]?[^'",\s]{8,}|"?cookie"?\s*[:=]\s*['"]?[^'",\s]{16,}|"?authorization"?\s*[:=]\s*['"]?[^'",\s]{8,}|Bearer\s+(?!redacted|masked|<redacted>)\S+)/i.test(raw)) {
    failures.push('OTA credential rotation attestation appears to contain credential material; store only redacted evidence references.');
  }
  const otaSensitiveFields = findSensitiveFieldValues(
    attestation,
    new Set(['cookie', 'authorization', 'token', 'usertoken', 'usersign', 'signature', 'secret']),
  );
  if (otaSensitiveFields.length > 0) {
    failures.push(`OTA credential rotation attestation contains unredacted sensitive fields: ${otaSensitiveFields.join(', ')}`);
  }

  const requiredStringFields = ['reviewed_at', 'reviewer'];
  const missingFields = requiredStringFields.filter((field) => isPlaceholder(attestation[field]));
  if (missingFields.length > 0) {
    failures.push(`OTA credential rotation attestation is incomplete: ${missingFields.join(', ')}`);
    return { passes, warnings, failures };
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

  return { passes, warnings, failures };
}

export function checkOtaCredentialRelease({ repoRoot, attestationPath, requireOutsideRepo = false }) {
  const backup = checkBackupCredentialFields({ repoRoot });
  const attestation = checkOtaCredentialRotationAttestation({ repoRoot, attestationPath, requireOutsideRepo });
  return {
    passes: [...backup.passes, ...attestation.passes],
    warnings: [...backup.warnings, ...attestation.warnings],
    failures: [...backup.failures, ...attestation.failures],
  };
}
