import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';
import { checkOtaCredentialRotationAttestation } from './lib/ota_credential_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const draftDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DRAFT_DIR || path.join(evidenceDir, 'drafts'));
const resultPath = path.resolve(
  repoRoot,
  process.env.RELEASE_EVIDENCE_DRAFT_REVIEW_RESULT_FILE
    || path.join(evidenceDir, 'release-evidence-draft-review-result.json'),
);

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function stringifyAsciiJson(value) {
  return JSON.stringify(value, null, 2).replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function resultSkeleton(id, draftFile, finalFile, acceptanceCommand) {
  return {
    id,
    draft_file: draftFile,
    final_file: finalFile,
    acceptance_command: acceptanceCommand,
    can_promote: false,
    blocking_fields: [],
    required_operator_inputs: [],
    passes: [],
    warnings: [],
    failures: [],
  };
}

function fieldHint(fieldPath) {
  const hints = {
    _draft_notice: 'Remove this draft-only marker before promotion.',
    owner: 'Use a real accountable design owner.',
    last_reviewed_at: 'Use a real YYYY-MM-DD review date inside the 30-day release evidence window.',
    figma_url: 'Use an accessible https://figma.com source URL.',
    canva_url: 'Use an accessible https://canva.com design URL.',
    brand_kit_url: 'Use an accessible https://canva.com Brand Kit URL.',
    notes: 'Replace draft notes with reviewed handoff notes or remove placeholder text.',
    'source_review.review_method': 'Use connector_verified, manual_access_review, or independent_design_audit.',
    'source_review.evidence_ref': 'Reference a controlled connector result, ticket, or audit record proving accessible sources.',
    'source_review.figma_source_verified': 'Set true only after the Figma source is accessible and reviewed.',
    'source_review.canva_source_verified': 'Set true only after the Canva source is accessible and reviewed.',
    'source_review.brand_kit_source_verified': 'Set true only after the Canva Brand Kit source is accessible and reviewed.',
    'source_review.design_tokens_reviewed': 'Set true only after design tokens are reviewed.',
    'source_review.required_flows_reviewed': 'Set true only after every required flow is reviewed.',
    reviewed_at: 'Use a real YYYY-MM-DD review date inside the 30-day release evidence window.',
    reviewer: 'Use a real accountable reviewer.',
    scope: 'Describe the real credential scope covered by the rotation or invalidation.',
    action: 'Use rotated or invalidated only for platform credentials; backup cleanup actions belong in backup_cleanup.database_backups_action.',
    evidence_ref: 'Reference a redacted ticket, audit record, or controlled evidence location.',
    credential_types: 'Each Ctrip and Meituan entry must cover cookie, token/usertoken, signature/usersign, and authorization material.',
    'backup_cleanup.database_backups_action': 'Use deleted, encrypted_archive, or sanitized.',
    'backup_cleanup.git_tracking_check': 'Record the git ls-files database/backups check result.',
    'backup_cleanup.release_readiness_check': 'Record review:release-ota-credentials or review:release-readiness rerun evidence.',
  };
  const key = String(fieldPath || '').replace(/^platforms\.\d+\./, '');
  return hints[fieldPath] || hints[key] || 'Replace placeholder text with real reviewed evidence.';
}

function pushField(fields, fieldPath, failure, action = 'replace_or_complete') {
  if (!fieldPath) {
    return;
  }
  const normalizedPath = String(fieldPath).trim();
  if (fields.some((entry) => entry.path === normalizedPath && entry.action === action)) {
    return;
  }
  fields.push({
    path: normalizedPath,
    action,
    hint: fieldHint(normalizedPath),
    source_failure: failure,
  });
}

function summarizeBlockingFields(failures, label) {
  const fields = [];
  const escapedLabel = label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const draftResiduePattern = new RegExp(`^${escapedLabel} (.+) still contains draft or placeholder text\\.$`);
  const draftNoticePattern = new RegExp(`^${escapedLabel} (.+) must be removed before release\\.$`);
  const incompletePattern = new RegExp(`^${escapedLabel} is incomplete: (.+)$`);
  const platformIncompletePattern = new RegExp(`^${escapedLabel} platform entry (\\d+) is incomplete: (.+)$`);
  const platformCredentialTypesPattern = new RegExp(`^${escapedLabel} platform entry (\\d+) credential_types must .+\\.$`);
  const platformActionPattern = new RegExp(`^${escapedLabel} platform entry (\\d+) has unsupported action: .+$`);
  const sourceReviewPattern = new RegExp(`^${escapedLabel} (source_review(?:\\.[A-Za-z0-9_]+)?) must .+\\.$`);

  for (const failure of failures) {
    const text = String(failure || '');
    let match = text.match(draftResiduePattern);
    if (match) {
      pushField(fields, match[1], text);
      continue;
    }
    match = text.match(draftNoticePattern);
    if (match) {
      pushField(fields, match[1], text, 'remove');
      continue;
    }
    match = text.match(incompletePattern);
    if (match) {
      for (const field of match[1].split(',').map((item) => item.trim()).filter(Boolean)) {
        pushField(fields, field, text);
      }
      continue;
    }
    match = text.match(platformIncompletePattern);
    if (match) {
      const index = Number(match[1]) - 1;
      for (const field of match[2].split(',').map((item) => item.trim()).filter(Boolean)) {
        pushField(fields, `platforms.${index}.${field}`, text);
      }
      continue;
    }
    match = text.match(sourceReviewPattern);
    if (match) {
      pushField(fields, match[1], text);
      continue;
    }
    match = text.match(platformCredentialTypesPattern);
    if (match) {
      pushField(fields, `platforms.${Number(match[1]) - 1}.credential_types`, text);
      continue;
    }
    match = text.match(platformActionPattern);
    if (match) {
      pushField(fields, `platforms.${Number(match[1]) - 1}.action`, text);
      continue;
    }
    if (/must include Ctrip and Meituan platform entries; missing:/i.test(text)) {
      pushField(fields, 'platforms', text);
    } else if (/must confirm redaction_checked=true/i.test(text)) {
      pushField(fields, 'redaction_checked', text);
    } else if (/backup_cleanup\.paths_reviewed must include database\/backups/i.test(text)) {
      pushField(fields, 'backup_cleanup.paths_reviewed', text);
    } else if (/backup_cleanup\.git_tracking_check is missing or placeholder/i.test(text)) {
      pushField(fields, 'backup_cleanup.git_tracking_check', text);
    } else if (/backup_cleanup\.release_readiness_check is missing or placeholder/i.test(text)) {
      pushField(fields, 'backup_cleanup.release_readiness_check', text);
    }
  }

  return fields;
}

function designOperatorInputs() {
  return [
    'Accessible Figma source URL.',
    'Accessible Canva design URL and Brand Kit URL.',
    'Real design owner and review date inside the 30-day release evidence window.',
    'Design tokens path and covered flows for login, OTA data, revenue analysis, AI decision, operations management, and investment decision.',
    'source_review with review method, evidence reference, and true source-review flags for Figma, Canva, Brand Kit, tokens, and required flows.',
    'Empty open_issues array before promotion.',
  ];
}

function otaOperatorInputs() {
  return [
    'Real accountable reviewer and review date inside the 30-day release evidence window.',
    'Each Ctrip and Meituan entry covering Cookie, Token/usertoken, signature/usersign, and Authorization material.',
    'Rotation or invalidation action per platform; encrypted_archive and sanitized are backup cleanup actions only.',
    'Redacted evidence references only; no reusable credential values.',
    'database/backups cleanup action plus git ls-files database/backups and release verifier rerun evidence.',
  ];
}

function withPathGuards(section) {
  if (isPathInsideRepo(draftDir)) {
    section.failures.push(`Release evidence draft directory must be outside the repository: ${draftDir}`);
  }
  if (isPathInsideRepo(resultPath)) {
    section.failures.push(`Release evidence draft review result must be outside the repository: ${resultPath}`);
  }
  if (!fs.existsSync(section.draft_file)) {
    section.failures.push(`Draft file was not found: ${section.draft_file}`);
  }
  return section;
}

function reviewDesignDraft() {
  const section = withPathGuards(resultSkeleton(
    'design-handoff-missing',
    path.join(draftDir, 'design_handoff_manifest.draft.json'),
    path.join(evidenceDir, 'design_handoff_manifest.json'),
    'npm run review:release-design',
  ));
  if (section.failures.length === 0) {
    const check = checkDesignHandoff({
      repoRoot,
      manifestPath: section.draft_file,
      requireOutsideRepo: true,
    });
    section.passes.push(...check.passes);
    section.warnings.push(...check.warnings);
    section.failures.push(...check.failures);
  }
  section.can_promote = section.failures.length === 0;
  section.blocking_fields = summarizeBlockingFields(section.failures, 'Design handoff manifest');
  section.required_operator_inputs = designOperatorInputs();
  return section;
}

function reviewOtaDraft() {
  const section = withPathGuards(resultSkeleton(
    'ota-credential-rotation-attestation-missing',
    path.join(draftDir, 'ota_credential_rotation_attestation.draft.json'),
    path.join(evidenceDir, 'ota_credential_rotation_attestation.json'),
    'npm run review:release-ota-credentials',
  ));
  if (section.failures.length === 0) {
    const check = checkOtaCredentialRotationAttestation({
      repoRoot,
      attestationPath: section.draft_file,
      requireOutsideRepo: true,
    });
    section.passes.push(...check.passes);
    section.warnings.push(...check.warnings);
    section.failures.push(...check.failures);
  }
  section.can_promote = section.failures.length === 0;
  section.blocking_fields = summarizeBlockingFields(section.failures, 'OTA credential rotation attestation');
  section.required_operator_inputs = otaOperatorInputs();
  return section;
}

const sections = [
  reviewDesignDraft(),
  reviewOtaDraft(),
];

const summary = {
  promotable_sections: sections.filter((section) => section.can_promote).length,
  warnings: sections.reduce((total, section) => total + section.warnings.length, 0),
  failures: sections.reduce((total, section) => total + section.failures.length, 0),
  blocking_fields: sections.reduce((total, section) => total + section.blocking_fields.length, 0),
};

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-evidence-drafts',
  release_ready: false,
  can_promote_all: sections.every((section) => section.can_promote),
  evidence_dir: evidenceDir,
  draft_dir: draftDir,
  result_file: resultPath,
  summary,
  sections,
  next_commands: sections.every((section) => section.can_promote)
    ? [
      'npm run promote:release-evidence-drafts',
      'npm run review:release-design',
      'npm run review:release-ota-credentials',
      'npm run review:release-readiness',
    ]
    : [
      'Fill the external drafts with real reviewed evidence.',
      'Rerun npm run review:release-evidence-drafts.',
    ],
  forbidden_closure: [
    'Do not treat this draft review result as final release evidence.',
    'Do not copy drafts to final evidence paths while this command reports failures.',
  ],
};

if (isPathInsideRepo(resultPath)) {
  console.error(`Release evidence draft review result must be outside the repository: ${resultPath}`);
  process.exit(1);
}

fs.mkdirSync(path.dirname(resultPath), { recursive: true });
fs.writeFileSync(resultPath, `${stringifyAsciiJson(result)}\n`, 'utf8');

console.log(`Wrote release evidence draft review result to ${resultPath}`);
console.log(`Draft review summary: promotable=${summary.promotable_sections}/${sections.length}, warnings=${summary.warnings}, failures=${summary.failures}`);
for (const section of sections) {
  for (const failure of section.failures) {
    console.error(`FAIL: ${section.id}: ${failure}`);
  }
}

if (!result.can_promote_all) {
  process.exit(1);
}
