import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  CREDENTIAL_SENSITIVE_NORMALIZED_KEYS,
  credentialRiskSignals,
  findSensitiveFieldCategories,
} from './ota_credential_checks.mjs';
import { isPlaceholder } from './release_env_checks.mjs';
import { safeJsonParseErrorCode } from './safe_json_parse_error.mjs';

const RELEASE_EVIDENCE_MAX_AGE_DAYS = 30;
const MAX_FUTURE_CLOCK_SKEW_MS = 5 * 60 * 1000;
const RELEASE_BUSINESS_TIME_ZONE = 'Asia/Shanghai';

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function normalizedConfigValue(value) {
  return String(value ?? '').trim();
}

function normalizedBaseUrl(value) {
  return normalizedConfigValue(value).replace(/\/+$/, '');
}

export function llmConfigDigest(config) {
  const canonical = [
    `provider=${normalizedConfigValue(config?.provider)}`,
    `model_key=${normalizedConfigValue(config?.model_key)}`,
    `model_name=${normalizedConfigValue(config?.model_name)}`,
    `base_url=${normalizedBaseUrl(config?.base_url)}`,
  ].join('\n');
  return createHash('sha256').update(canonical, 'utf8').digest('hex');
}

export function resolveGitHead(repoRoot) {
  const result = spawnSync('git', ['rev-parse', 'HEAD'], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  const head = String(result.stdout || '').trim();
  return result.status === 0 && /^[a-f0-9]{40}$/i.test(head) ? head.toLowerCase() : '';
}

function isDateOnly(value) {
  const text = String(value ?? '').trim();
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    return false;
  }
  const [year, month, day] = match.slice(1).map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  return date.getUTCFullYear() === year
    && date.getUTCMonth() === month - 1
    && date.getUTCDate() === day;
}

function dateOnlyTimestamp(value) {
  const [year, month, day] = String(value).split('-').map(Number);
  return Date.UTC(year, month - 1, day);
}

function currentBusinessDateTimestamp(now) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: RELEASE_BUSINESS_TIME_ZONE,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    })
      .formatToParts(now)
      .filter(({ type }) => ['year', 'month', 'day'].includes(type))
      .map(({ type, value }) => [type, Number(value)]),
  );
  return Date.UTC(parts.year, parts.month - 1, parts.day);
}

function isIsoTimestamp(value) {
  const text = String(value ?? '').trim();
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(text)
    && Number.isFinite(Date.parse(text));
}

function isWeakReviewer(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /TODO|CHANGE_ME|example|placeholder/i.test(text)
    || /\b(test|fixture|dummy|script|automation)\b/i.test(text);
}

function isValidBaseUrl(value) {
  try {
    const parsed = new URL(String(value ?? '').trim());
    return ['http:', 'https:'].includes(parsed.protocol)
      && parsed.hostname !== ''
      && parsed.username === ''
      && parsed.password === ''
      && parsed.search === ''
      && parsed.hash === '';
  } catch {
    return false;
  }
}

export function checkLlmConnectivityAttestation({
  repoRoot,
  attestationPath,
  expectedReleaseCommit = '',
  expectedConfigDigest = '',
  now = new Date(),
}) {
  const failures = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);
  const referenceTime = now instanceof Date ? now : new Date(now);

  if (!fs.existsSync(resolvedPath)) {
    failures.push('Production LLM connectivity attestation was not found at the configured path. Set LLM_CONNECTIVITY_ATTESTATION_FILE to a controlled attestation JSON before release.');
    return { passes, failures };
  }

  let attestation = null;
  let raw = '';
  try {
    raw = fs.readFileSync(resolvedPath, 'utf8');
    attestation = JSON.parse(raw);
  } catch (error) {
    failures.push(`Production LLM connectivity attestation is not valid JSON (${safeJsonParseErrorCode(error)}).`);
    return { passes, failures };
  }

  if (!attestation || typeof attestation !== 'object' || Array.isArray(attestation)) {
    failures.push('Production LLM connectivity attestation must be a JSON object.');
    return { passes, failures };
  }
  if (!Number.isFinite(referenceTime.getTime())) {
    failures.push('Production LLM connectivity verifier reference time is invalid.');
    return { passes, failures };
  }

  if (credentialRiskSignals(raw).valueBearingMatches > 0 || /sk-[A-Za-z0-9_-]{8,}/i.test(raw)) {
    failures.push('Production LLM connectivity attestation appears to contain secret material; store only redacted evidence references.');
  }
  const llmSensitiveFields = findSensitiveFieldCategories(
    attestation,
    new Set(CREDENTIAL_SENSITIVE_NORMALIZED_KEYS),
  );
  if (llmSensitiveFields.length > 0) {
    const categories = [...new Set(llmSensitiveFields)].sort();
    failures.push(`Production LLM connectivity attestation contains ${llmSensitiveFields.length} unredacted sensitive fields in safe categories: ${categories.join(', ')}`);
  }

  const requiredStringFields = [
    'reviewed_at',
    'reviewer',
    'environment',
    'provider',
    'model_key',
    'model_name',
    'base_url',
    'release_commit_sha',
    'config_digest',
    'evidence_ref',
  ];
  const missingFields = requiredStringFields.filter((field) => isPlaceholder(attestation[field]));
  if (missingFields.length > 0) {
    failures.push(`Production LLM connectivity attestation is incomplete: ${missingFields.join(', ')}`);
    return { passes, failures };
  }


  if (isWeakReviewer(attestation.reviewer)) {
    failures.push('Production LLM connectivity attestation reviewer must be a real accountable reviewer, not a placeholder, test owner, or script identity.');
  }

  if (!isDateOnly(attestation.reviewed_at)) {
    failures.push('Production LLM connectivity attestation reviewed_at must use a real YYYY-MM-DD date.');
  } else {
    const reviewedAt = dateOnlyTimestamp(attestation.reviewed_at);
    const today = currentBusinessDateTimestamp(referenceTime);
    if (reviewedAt > today) {
      failures.push('Production LLM connectivity attestation reviewed_at must not be in the future.');
    } else if (today - reviewedAt > RELEASE_EVIDENCE_MAX_AGE_DAYS * 24 * 60 * 60 * 1000) {
      failures.push(`Production LLM connectivity attestation reviewed_at must be within the ${RELEASE_EVIDENCE_MAX_AGE_DAYS}-day release evidence window.`);
    }
  }

  if (String(attestation.environment).trim().toLowerCase() !== 'production') {
    failures.push('Production LLM connectivity attestation environment must be exactly production.');
  }

  if (!isValidBaseUrl(attestation.base_url)) {
    failures.push('Production LLM connectivity attestation base_url must be a credential-free HTTP(S) origin/path without query or fragment data.');
  }

  if (attestation.ai_model_config_enabled !== true) {
    failures.push('Production LLM connectivity attestation must confirm ai_model_config_enabled=true.');
  }
  if (attestation.ai_config_secret_checked !== true) {
    failures.push('Production LLM connectivity attestation must confirm ai_config_secret_checked=true.');
  }
  if (attestation.redaction_checked !== true) {
    failures.push('Production LLM connectivity attestation must confirm redaction_checked=true.');
  }

  const request = attestation.request && typeof attestation.request === 'object'
    ? attestation.request
    : {};
  if (request.entrypoint !== 'LlmClient') {
    failures.push('Production LLM connectivity attestation request.entrypoint must be LlmClient.');
  }

  const releaseCommitSha = String(attestation.release_commit_sha || '').trim().toLowerCase();
  const expectedCommitSha = String(expectedReleaseCommit || '').trim().toLowerCase();
  if (!/^[a-f0-9]{40}$/.test(releaseCommitSha)) {
    failures.push('Production LLM connectivity attestation release_commit_sha must be a full 40-character Git commit SHA.');
  }
  if (expectedCommitSha !== '' && !/^[a-f0-9]{40}$/.test(expectedCommitSha)) {
    failures.push('Production LLM connectivity verifier expected release commit is invalid; release binding cannot be established.');
  } else if (expectedCommitSha !== '' && releaseCommitSha !== expectedCommitSha) {
    failures.push('Production LLM connectivity attestation release_commit_sha does not match the selected release commit. Rerun the production smoke test on the final release head.');
  }

  const declaredConfigDigest = String(attestation.config_digest || '').trim().toLowerCase();
  const computedConfigDigest = llmConfigDigest(attestation);
  const trustedConfigDigest = String(expectedConfigDigest || '').trim().toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(declaredConfigDigest)) {
    failures.push('Production LLM connectivity attestation config_digest must be a SHA-256 digest.');
  } else if (declaredConfigDigest !== computedConfigDigest) {
    failures.push('Production LLM connectivity attestation config_digest does not match provider, model_key, model_name, and base_url. Rerun the smoke test against the deployed model config.');
  }
  if (!/^[a-f0-9]{64}$/.test(trustedConfigDigest)) {
    failures.push('Production LLM connectivity verifier expected production model config digest is missing or invalid; trusted config binding cannot be established. Set LLM_PRODUCTION_CONFIG_DIGEST from an independently read production ai_model_configs row.');
  } else if (declaredConfigDigest !== trustedConfigDigest) {
    failures.push('Production LLM connectivity attestation config_digest does not match the independently supplied production model config digest. Rerun the smoke test against the selected deployed model config.');
  }

  const result = attestation.result && typeof attestation.result === 'object'
    ? attestation.result
    : {};
  const responseStatus = Number(result.response_status ?? 0);
  if (result.status !== 'passed' || responseStatus < 200 || responseStatus >= 300) {
    failures.push('Production LLM connectivity attestation result must be passed with a 2xx response_status.');
  }

  if (!isIsoTimestamp(result.completed_at)) {
    failures.push('Production LLM connectivity attestation result.completed_at must be a real ISO timestamp with timezone.');
  } else {
    const completedAt = Date.parse(result.completed_at);
    const ageMs = referenceTime.getTime() - completedAt;
    if (completedAt > referenceTime.getTime() + MAX_FUTURE_CLOCK_SKEW_MS) {
      failures.push('Production LLM connectivity attestation result.completed_at must not be in the future.');
    } else if (ageMs > RELEASE_EVIDENCE_MAX_AGE_DAYS * 24 * 60 * 60 * 1000) {
      failures.push(`Production LLM connectivity attestation result.completed_at must be within the ${RELEASE_EVIDENCE_MAX_AGE_DAYS}-day release evidence window.`);
    }
  }

  const resultReleaseCommit = String(result.release_commit_sha || '').trim().toLowerCase();
  if (resultReleaseCommit !== releaseCommitSha) {
    failures.push('Production LLM connectivity result.release_commit_sha must match the attested release_commit_sha.');
  }

  const resultConfigDigest = String(result.config_digest || '').trim().toLowerCase();
  if (resultConfigDigest !== declaredConfigDigest) {
    failures.push('Production LLM connectivity result.config_digest must match the attested production model config digest.');
  }

  const latencyMs = Number(result.latency_ms);
  if (!Number.isFinite(latencyMs) || latencyMs < 0) {
    failures.push('Production LLM connectivity attestation result.latency_ms must be a non-negative number.');
  }

  if (failures.length === 0) {
    passes.push('Production LLM connectivity attestation is current, production-bound, release-commit-bound, model-config-bound, redacted, and passed.');
  }

  return { passes, failures };
}
