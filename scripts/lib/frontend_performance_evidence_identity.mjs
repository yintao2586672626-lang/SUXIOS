import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

export const FRONTEND_PERFORMANCE_IDENTITY_FILES = Object.freeze([
  'public/index.html',
  'public/app-bootstrap.js',
  'public/app-bootstrap.min.js',
  'public/app-main.js',
  'public/app-main.min.js',
  'public/app-startup-render.min.js',
  'public/app-render.min.js',
]);

const sha256 = (value) => createHash('sha256').update(value).digest('hex');

export function captureFrontendPerformanceIdentity(
  root = process.cwd(),
  relativePaths = FRONTEND_PERFORMANCE_IDENTITY_FILES,
) {
  const files = relativePaths.map((relativePath) => {
    const normalizedPath = String(relativePath).replaceAll('\\', '/');
    const absolutePath = path.resolve(root, normalizedPath);
    if (!fs.existsSync(absolutePath) || !fs.statSync(absolutePath).isFile()) {
      throw new Error(`Frontend performance identity input is missing: ${normalizedPath}`);
    }
    const content = fs.readFileSync(absolutePath);
    return {
      path: normalizedPath,
      bytes: content.length,
      sha256: sha256(content),
    };
  });
  const digest = sha256(files.map((file) => `${file.path}\0${file.bytes}\0${file.sha256}`).join('\n'));
  return { schema_version: 1, algorithm: 'sha256', digest, files };
}

export function evaluateFrontendPerformanceEvidence(
  report = {},
  {
    currentIdentity = null,
    now = Date.now(),
    maxAgeMinutes = 360,
  } = {},
) {
  const failures = [];
  const fail = (reason, actual = null, expected = null) => failures.push({ reason, actual, expected });
  const identity = report?.artifact_identity || {};
  const reportDigest = String(identity?.digest || '');
  const completedDigest = String(report?.artifact_identity_completed_digest || '');
  const currentDigest = String(currentIdentity?.digest || '');
  const completedAt = String(report?.completed_at || '');
  const completedTimestamp = Date.parse(completedAt);
  const ageMinutes = Number.isFinite(completedTimestamp)
    ? Math.round(((Number(now) - completedTimestamp) / 60_000) * 100) / 100
    : null;

  if (!/^[a-f0-9]{64}$/.test(reportDigest)) fail('artifact_identity_missing', reportDigest || null, 'sha256');
  if (report?.artifact_identity_stable !== true) fail('artifact_identity_changed_during_measurement', false, true);
  if (completedDigest !== reportDigest) fail('artifact_identity_completion_mismatch', completedDigest || null, reportDigest || null);
  if (!/^[a-f0-9]{64}$/.test(currentDigest)) fail('current_artifact_identity_missing', currentDigest || null, 'sha256');
  if (currentDigest && reportDigest && currentDigest !== reportDigest) {
    fail('artifact_identity_stale', reportDigest, currentDigest);
  }
  if (!Number.isFinite(completedTimestamp)) {
    fail('completed_at_missing_or_invalid', completedAt || null, 'RFC3339 timestamp');
  } else {
    const maximumAge = Number(maxAgeMinutes);
    if (!Number.isFinite(maximumAge) || maximumAge <= 0) {
      fail('max_age_invalid', maxAgeMinutes, 'positive minutes');
    } else if (ageMinutes > maximumAge) {
      fail('performance_report_stale', ageMinutes, maximumAge);
    } else if (ageMinutes < -5) {
      fail('performance_report_from_future', ageMinutes, '>= -5 minutes');
    }
  }

  return {
    observed: {
      report_digest: reportDigest || null,
      current_digest: currentDigest || null,
      artifact_identity_stable: report?.artifact_identity_stable === true,
      completed_at: completedAt || null,
      age_minutes: ageMinutes,
      max_age_minutes: Number(maxAgeMinutes),
    },
    failures,
  };
}
