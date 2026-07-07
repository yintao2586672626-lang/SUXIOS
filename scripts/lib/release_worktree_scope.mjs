export const gitStatusCategoryOrder = Object.freeze([
  'release-docs',
  'release-scripts',
  'frontend',
  'revenue-ai',
  'runtime-or-local',
  'other',
]);

export const releaseStagingReviewFiles = Object.freeze({
  candidate_release_scope: 'release-staging-candidate-scope.tsv',
  needs_explicit_operator_decision: 'release-staging-needs-operator-decision.tsv',
  must_remain_local_by_default: 'release-staging-must-remain-local.tsv',
});

const releaseDocPaths = new Set([
  'docs/deployment_env_checklist.md',
  'docs/formal_release_final_handoff.md',
  'docs/functional_acceptance_report.zh-CN.md',
  'docs/ota_credential_rotation_checklist.md',
  'docs/codex_security_scan_authorization.md',
  'docs/design-tokens.release.json',
  'docs/design_handoff_manifest.example.json',
  'docs/ota_credential_rotation_attestation.example.json',
  'docs/codex_security_scan_manifest.example.json',
  'docs/llm_connectivity_attestation.example.json',
]);

export function normalizeReleasePath(rawPath) {
  let normalizedPath = String(rawPath || '').trim();
  if (normalizedPath.includes(' -> ')) {
    normalizedPath = normalizedPath.split(' -> ').pop().trim();
  }
  normalizedPath = normalizedPath.replace(/^"|"$/g, '');
  return normalizedPath.replace(/\\/g, '/');
}

export function isReleaseLocalArtifactPath(rawPath) {
  const normalizedPath = normalizeReleasePath(rawPath);
  return normalizedPath === '.env'
    || normalizedPath.startsWith('.env.')
    || normalizedPath === 'storage'
    || normalizedPath.startsWith('storage/')
    || normalizedPath === 'runtime'
    || normalizedPath.startsWith('runtime/')
    || normalizedPath === 'reports'
    || normalizedPath.startsWith('reports/')
    || normalizedPath === 'output'
    || normalizedPath.startsWith('output/')
    || normalizedPath === 'test-results'
    || normalizedPath.startsWith('test-results/')
    || normalizedPath === 'database/backups'
    || normalizedPath.startsWith('database/backups/')
    || normalizedPath === 'docs/release_external_state_evidence.local.json';
}

export function categorizeReleasePath(rawPath) {
  const normalizedPath = normalizeReleasePath(rawPath);

  if (
    normalizedPath.startsWith('docs/release')
    || normalizedPath.startsWith('docs/ui-handoff/')
    || normalizedPath.startsWith('docs/security/codex-security/')
    || releaseDocPaths.has(normalizedPath)
  ) {
    return 'release-docs';
  }

  if (
    normalizedPath === 'package.json'
    || normalizedPath === 'package-lock.json'
    || normalizedPath === 'scripts/create_worktree_quarantine_bundle.mjs'
    || /^scripts\/.*release/i.test(normalizedPath)
    || /^scripts\/lib\/(release_env_checks|llm_attestation_checks|design_handoff_checks|ota_credential_checks|security_scan_checks|release_worktree_scope)\.mjs$/i.test(normalizedPath)
  ) {
    return 'release-scripts';
  }

  if (normalizedPath.startsWith('public/')) {
    return 'frontend';
  }

  if (/^scripts\/.*revenue_ai/i.test(normalizedPath)) {
    return 'revenue-ai';
  }

  if (isReleaseLocalArtifactPath(normalizedPath)) {
    return 'runtime-or-local';
  }

  return 'other';
}

export function releaseStagingBucket(category) {
  if (['release-docs', 'release-scripts'].includes(category)) {
    return 'candidate_release_scope';
  }
  if (category === 'runtime-or-local') {
    return 'must_remain_local_by_default';
  }
  return 'needs_explicit_operator_decision';
}

export function classifyReleaseWorktreeEntry(entry) {
  const normalizedPath = normalizeReleasePath(entry?.path);
  const category = categorizeReleasePath(normalizedPath);
  return {
    status: entry?.status,
    path: normalizedPath,
    category,
    bucket: releaseStagingBucket(category),
  };
}
