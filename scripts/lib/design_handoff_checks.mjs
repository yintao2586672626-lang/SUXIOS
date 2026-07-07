import fs from 'node:fs';
import path from 'node:path';

const RELEASE_EVIDENCE_MAX_AGE_DAYS = 30;

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

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(repoRoot, filePath) {
  const resolved = resolveInputPath(repoRoot, filePath);
  const relative = path.relative(repoRoot, resolved);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
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

function isWeakDesignOwner(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /TODO|CHANGE_ME|example|placeholder/i.test(text)
    || /^Codex release handoff$/i.test(text)
    || /\b(test|fixture|dummy)\b/i.test(text);
}

function isPlaceholderString(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /TODO|CHANGE_ME|example|placeholder|Draft only|Do not rename or copy/i.test(text);
}

const sourceReviewRequiredTrueFields = [
  'figma_source_verified',
  'canva_source_verified',
  'brand_kit_source_verified',
  'design_tokens_reviewed',
  'required_flows_reviewed',
];

function collectNonClosingSourceEvidenceFailures(repoRoot, evidenceRef) {
  const failures = [];
  const text = String(evidenceRef ?? '');
  for (const evidencePath of [
    'docs/release_figma_handoff_evidence.json',
    'docs/release_canva_handoff_evidence.json',
  ]) {
    if (!text.includes(evidencePath)) {
      continue;
    }
    const absolutePath = path.join(repoRoot, evidencePath);
    if (!fs.existsSync(absolutePath)) {
      continue;
    }
    try {
      const evidence = JSON.parse(fs.readFileSync(absolutePath, 'utf8').replace(/^\uFEFF/, ''));
      if (evidence?.does_not_close_release_design_gate === true) {
        failures.push(`Design handoff manifest source_review.evidence_ref must not reference non-closing connector blocker evidence: ${evidencePath}.`);
      }
    } catch (error) {
      failures.push(`Design handoff manifest source_review.evidence_ref references unreadable connector evidence ${evidencePath}: ${error.message}`);
    }
  }
  return failures;
}

function collectSourceReviewFailures(repoRoot, sourceReview) {
  const failures = [];
  if (!sourceReview || typeof sourceReview !== 'object' || Array.isArray(sourceReview)) {
    return [
      'Design handoff manifest source_review must record how Figma, Canva, Brand Kit, design tokens, and required flows were verified.',
      'Design handoff manifest source_review.review_method must record connector_verified, manual_access_review, or independent_design_audit.',
      'Design handoff manifest source_review.evidence_ref must reference a controlled connector result, ticket, or audit record.',
      ...sourceReviewRequiredTrueFields.map((field) => `Design handoff manifest source_review.${field} must be true before release.`),
    ];
  }

  if (isPlaceholderString(sourceReview.review_method)) {
    failures.push('Design handoff manifest source_review.review_method must record connector_verified, manual_access_review, or independent_design_audit.');
  } else if (!/^(connector_verified|manual_access_review|independent_design_audit)$/i.test(String(sourceReview.review_method).trim())) {
    failures.push('Design handoff manifest source_review.review_method must be connector_verified, manual_access_review, or independent_design_audit.');
  }

  if (isPlaceholderString(sourceReview.evidence_ref)) {
    failures.push('Design handoff manifest source_review.evidence_ref must reference a controlled connector result, ticket, or audit record.');
  } else {
    failures.push(...collectNonClosingSourceEvidenceFailures(repoRoot, sourceReview.evidence_ref));
  }

  for (const field of sourceReviewRequiredTrueFields) {
    if (sourceReview[field] !== true) {
      failures.push(`Design handoff manifest source_review.${field} must be true before release.`);
    }
  }

  return failures;
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
        failures.push(`Design handoff manifest ${[...pathParts, key].join('.')} must be removed before release.`);
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
    failures.push(`Design handoff manifest ${pathLabel} still contains draft or placeholder text.`);
  }
}

export function checkDesignHandoff({ repoRoot, manifestPath = 'docs/design_handoff_manifest.json', requireOutsideRepo = false } = {}) {
  const failures = [];
  const warnings = [];
  const passes = [];
  const designPatterns = [
    /\.(fig|sketch|xd|canva)$/i,
    /(^|\/)design-tokens\.json$/i,
    /(^|\/).*\.tokens\.json$/i,
  ];
  const requiredFlows = [
    'login',
    'home-dashboard',
    'ota-data',
    'revenue-analysis',
    'ai-decision',
    'operations-management',
    'investment-decision',
  ];
  const matches = walkFiles(repoRoot, '.', warnings).filter((file) => designPatterns.some((pattern) => pattern.test(file)));
  const resolvedManifestPath = resolveInputPath(repoRoot, manifestPath);

  if (!fs.existsSync(resolvedManifestPath)) {
    failures.push(`Design handoff manifest was not found: ${manifestPath}. Set DESIGN_HANDOFF_MANIFEST_FILE to a controlled manifest JSON before release. Standalone design-token files or screenshots do not prove Figma/Canva source handoff.`);
    if (matches.length > 0) {
      warnings.push(`Design source/token artifacts were found (${matches.length}), but a valid design handoff manifest is still required.`);
    }
    return { passes, warnings, failures };
  }
  if (requireOutsideRepo && isPathInsideRepo(repoRoot, manifestPath)) {
    failures.push(`DESIGN_HANDOFF_MANIFEST_FILE must point to a controlled location outside the repository, not ${manifestPath}.`);
  }

  let manifest = null;
  try {
    manifest = JSON.parse(fs.readFileSync(resolvedManifestPath, 'utf8').replace(/^\uFEFF/, ''));
  } catch (error) {
    failures.push(`Design handoff manifest is not valid JSON: ${error.message}`);
    return { passes, warnings, failures };
  }
  collectDraftResidue(manifest, failures);

  const requiredStringFields = [
    'owner',
    'last_reviewed_at',
    'figma_url',
    'canva_url',
    'brand_kit_url',
    'design_tokens_path',
  ];
  const missingFields = requiredStringFields.filter((field) => {
    const value = String(manifest[field] ?? '').trim();
    if (field === 'owner') {
      return false;
    }
    return value === '' || /TODO|CHANGE_ME|example\.com/i.test(value);
  });
  const coveredFlows = Array.isArray(manifest.covered_flows) ? manifest.covered_flows : [];
  const missingFlows = requiredFlows.filter((flow) => !coveredFlows.includes(flow));
  const openIssues = Array.isArray(manifest.open_issues) ? manifest.open_issues : null;
  const tokenPath = String(manifest.design_tokens_path ?? '').trim();
  const tokenPathIsUrl = /^https:\/\/\S+$/i.test(tokenPath);
  const tokenPathExists = tokenPath !== '' && !path.isAbsolute(tokenPath) && fs.existsSync(path.join(repoRoot, tokenPath));
  const sourceReviewFailures = collectSourceReviewFailures(repoRoot, manifest.source_review);
  failures.push(...sourceReviewFailures);

  if (missingFields.length > 0) {
    failures.push(`Design handoff manifest is incomplete: ${missingFields.join(', ')}`);
  } else if (isWeakDesignOwner(manifest.owner)) {
    failures.push('Design handoff manifest owner must be a real accountable design owner, not a placeholder, test owner, or script identity.');
  } else if (!isDateOnly(manifest.last_reviewed_at)) {
    failures.push('Design handoff manifest last_reviewed_at must be a real YYYY-MM-DD date.');
  } else if (isFutureDateOnly(manifest.last_reviewed_at)) {
    failures.push('Design handoff manifest last_reviewed_at must not be in the future.');
  } else if (isOlderThanReleaseEvidenceWindow(manifest.last_reviewed_at)) {
    failures.push(`Design handoff manifest last_reviewed_at must be within the ${RELEASE_EVIDENCE_MAX_AGE_DAYS}-day release evidence window.`);
  } else if (!/^https:\/\/(www\.)?figma\.com\//.test(String(manifest.figma_url))) {
    failures.push('Design handoff manifest figma_url must be a figma.com URL.');
  } else if (!/^https:\/\/(www\.)?canva\.com\//.test(String(manifest.canva_url))) {
    failures.push('Design handoff manifest canva_url must be a canva.com URL.');
  } else if (!/^https:\/\/(www\.)?canva\.com\//.test(String(manifest.brand_kit_url))) {
    failures.push('Design handoff manifest brand_kit_url must be a canva.com URL.');
  } else if (!tokenPathIsUrl && !tokenPathExists) {
    failures.push('Design handoff manifest design_tokens_path must be an HTTPS URL or a repo-relative existing token file.');
  } else if (missingFlows.length > 0) {
    failures.push(`Design handoff manifest covered_flows is incomplete: ${missingFlows.join(', ')}`);
  } else if (!openIssues) {
    failures.push('Design handoff manifest open_issues must be an array.');
  } else if (openIssues.length > 0) {
    failures.push('Design handoff manifest open_issues must be empty before release.');
  } else if (failures.length === 0) {
    passes.push('Design handoff manifest is present with Figma, Canva, brand-kit, token, flow coverage, source review, and no open design issues.');
  }

  if (matches.length > 0) {
    passes.push(`Design source/token artifacts found: ${matches.length}.`);
  }

  return { passes, warnings, failures };
}
