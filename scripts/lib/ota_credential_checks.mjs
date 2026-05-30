import fs from 'node:fs';
import path from 'node:path';
import { isPlaceholder } from './release_env_checks.mjs';

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
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

function isRedactedSecretValue(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /TODO|CHANGE_ME|placeholder|redacted|masked|not stored|not included|secure record|internal ticket/i.test(text)
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

  warnings.push('Run `git ls-files database/backups` outside this script to confirm no backup file is tracked.');

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

export function checkOtaCredentialRotationAttestation({ repoRoot, attestationPath }) {
  const failures = [];
  const warnings = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);

  if (!fs.existsSync(resolvedPath)) {
    failures.push(`OTA credential rotation attestation was not found: ${attestationPath}. Set OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE to a controlled attestation JSON before release.`);
    return { passes, warnings, failures };
  }

  let attestation = null;
  let raw = '';
  try {
    raw = fs.readFileSync(resolvedPath, 'utf8');
    attestation = JSON.parse(raw);
  } catch (error) {
    failures.push(`OTA credential rotation attestation is not valid JSON: ${error.message}`);
    return { passes, warnings, failures };
  }

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

  const platforms = Array.isArray(attestation.platforms) ? attestation.platforms : [];
  if (attestation.redaction_checked !== true) {
    failures.push('OTA credential rotation attestation must confirm redaction_checked=true.');
  }
  if (platforms.length === 0) {
    failures.push('OTA credential rotation attestation must include at least one platform entry.');
  }
  for (const [index, platform] of platforms.entries()) {
    const missingPlatformFields = ['platform', 'scope', 'action', 'evidence_ref'].filter((field) => isPlaceholder(platform?.[field]));
    if (missingPlatformFields.length > 0) {
      failures.push(`OTA credential rotation attestation platform entry ${index + 1} is incomplete: ${missingPlatformFields.join(', ')}`);
    }
    const action = String(platform?.action || '').trim();
    if (!['rotated', 'invalidated', 'encrypted_archive', 'sanitized'].includes(action)) {
      failures.push(`OTA credential rotation attestation platform entry ${index + 1} has unsupported action: ${action || 'missing'}`);
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
  }
  if (isPlaceholder(cleanup.release_readiness_check)) {
    failures.push('OTA credential rotation attestation backup_cleanup.release_readiness_check is missing or placeholder.');
  }

  if (!failures.some((message) => message.includes('OTA credential rotation attestation'))) {
    passes.push('OTA credential rotation attestation is present and complete.');
  }

  return { passes, warnings, failures };
}

export function checkOtaCredentialRelease({ repoRoot, attestationPath }) {
  const backup = checkBackupCredentialFields({ repoRoot });
  const attestation = checkOtaCredentialRotationAttestation({ repoRoot, attestationPath });
  return {
    passes: [...backup.passes, ...attestation.passes],
    warnings: [...backup.warnings, ...attestation.warnings],
    failures: [...backup.failures, ...attestation.failures],
  };
}
