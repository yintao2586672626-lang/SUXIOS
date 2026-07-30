import fs from 'node:fs';
import path from 'node:path';
import {
  CREDENTIAL_SENSITIVE_NORMALIZED_KEYS,
  credentialRiskSignals,
  findSensitiveFieldCategories,
} from './ota_credential_checks.mjs';
import { isPlaceholder } from './release_env_checks.mjs';
import { safeJsonParseErrorCode } from './safe_json_parse_error.mjs';

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

export function checkLlmConnectivityAttestation({ repoRoot, attestationPath }) {
  const failures = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);

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
    'evidence_ref',
  ];
  const missingFields = requiredStringFields.filter((field) => isPlaceholder(attestation[field]));
  if (missingFields.length > 0) {
    failures.push(`Production LLM connectivity attestation is incomplete: ${missingFields.join(', ')}`);
    return { passes, failures };
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

  const result = attestation.result || {};
  const responseStatus = Number(result.response_status ?? 0);
  if (result.status !== 'passed' || responseStatus < 200 || responseStatus >= 300) {
    failures.push('Production LLM connectivity attestation result must be passed with a 2xx response_status.');
  } else {
    passes.push('Production LLM connectivity attestation is present and passed.');
  }

  return { passes, failures };
}
