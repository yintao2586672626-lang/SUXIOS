import fs from 'node:fs';
import path from 'node:path';
import { isPlaceholder } from './release_env_checks.mjs';

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
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

export function checkLlmConnectivityAttestation({ repoRoot, attestationPath }) {
  const failures = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, attestationPath);

  if (!fs.existsSync(resolvedPath)) {
    failures.push(`Production LLM connectivity attestation was not found: ${attestationPath}. Set LLM_CONNECTIVITY_ATTESTATION_FILE to a controlled attestation JSON before release.`);
    return { passes, failures };
  }

  let attestation = null;
  let raw = '';
  try {
    raw = fs.readFileSync(resolvedPath, 'utf8');
    attestation = JSON.parse(raw);
  } catch (error) {
    failures.push(`Production LLM connectivity attestation is not valid JSON: ${error.message}`);
    return { passes, failures };
  }

  if (/(sk-[A-Za-z0-9_-]{8,}|Bearer\s+(?!redacted|masked|<redacted>)\S+|"api[_-]?key"\s*:|"authorization"\s*:|"cookie"\s*:)/i.test(raw)) {
    failures.push('Production LLM connectivity attestation appears to contain secret material; store only redacted evidence references.');
  }
  const llmSensitiveFields = findSensitiveFieldValues(
    attestation,
    new Set(['apikey', 'authorization', 'cookie', 'token', 'secret', 'clientsecret']),
  );
  if (llmSensitiveFields.length > 0) {
    failures.push(`Production LLM connectivity attestation contains unredacted sensitive fields: ${llmSensitiveFields.join(', ')}`);
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
