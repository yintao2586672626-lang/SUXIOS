import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const draftDir = path.join(evidenceDir, 'drafts');
const allowDraftOverwrite = /^(1|true|yes)$/i.test(String(process.env.RELEASE_EVIDENCE_DRAFT_OVERWRITE || ''));

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function readJson(relativePath) {
  return JSON.parse(fs.readFileSync(path.join(repoRoot, relativePath), 'utf8'));
}

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

function writeText(filePath, content) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function draftPayload(template, notice) {
  return {
    _draft_notice: notice,
    ...template,
  };
}

function fail(message) {
  console.error(`FAIL: ${message}`);
  process.exit(1);
}

function assertWritableDraft(filePath, label) {
  if (!fs.existsSync(filePath) || allowDraftOverwrite) {
    return;
  }
  fail(`${label} already exists; set RELEASE_EVIDENCE_DRAFT_OVERWRITE=1 to replace it: ${filePath}`);
}

if (isPathInsideRepo(draftDir)) {
  fail(`Release evidence draft output must be outside the repository: ${draftDir}`);
}

const designDraftPath = path.join(draftDir, 'design_handoff_manifest.draft.json');
const otaDraftPath = path.join(draftDir, 'ota_credential_rotation_attestation.draft.json');
const indexPath = path.join(draftDir, 'release-evidence-draft-index.json');
const readmePath = path.join(draftDir, 'README.md');

assertWritableDraft(designDraftPath, 'Design handoff draft');
assertWritableDraft(otaDraftPath, 'OTA credential rotation draft');

const designTemplate = readJson('docs/design_handoff_manifest.example.json');
const otaTemplate = readJson('docs/ota_credential_rotation_attestation.example.json');

const designDraft = draftPayload(
  designTemplate,
  'Draft only. Do not rename or copy to design_handoff_manifest.json until every TODO is replaced with real reviewed Figma, Canva, Brand Kit, token, flow, owner, date inside the 30-day release evidence window, and empty open_issues evidence.',
);
const otaDraft = draftPayload(
  otaTemplate,
  'Draft only. Do not rename or copy to ota_credential_rotation_attestation.json until real OTA credential rotation or invalidation is completed and no credential material is present.',
);

writeJson(designDraftPath, designDraft);
writeJson(otaDraftPath, otaDraft);

const index = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run prepare:release-evidence-drafts',
  release_ready: false,
  overwrite_allowed: allowDraftOverwrite,
  draft_dir: draftDir,
  drafts: [
    {
      id: 'design-handoff-missing',
      draft_file: designDraftPath,
      final_file: path.join(evidenceDir, 'design_handoff_manifest.json'),
      acceptance_command: 'npm run review:release-design',
      required_before_copying: [
        'real accessible Figma source link',
        'real accessible Canva source link',
        'real accessible Canva Brand Kit link',
        'real accountable owner',
        'YYYY-MM-DD review date inside the 30-day release evidence window',
        'reviewed design token path',
        'required covered flows',
        'source_review proving Figma, Canva, Brand Kit, token, and flow review',
        'empty open_issues array',
      ],
    },
    {
      id: 'ota-credential-rotation-attestation-missing',
      draft_file: otaDraftPath,
      final_file: path.join(evidenceDir, 'ota_credential_rotation_attestation.json'),
      acceptance_command: 'npm run review:release-ota-credentials',
      required_before_copying: [
        'real Ctrip and Meituan platform credential rotation or invalidation action',
        'each platform credential_types covers cookie, token/usertoken, signature/usersign, and authorization material',
        'backup cleanup action, if needed, is recorded separately under backup_cleanup.database_backups_action as deleted, encrypted_archive, or sanitized',
        'real accountable reviewer',
        'YYYY-MM-DD review date inside the 30-day release evidence window',
        'redaction_checked=true after verifying no credential values are present',
        'git ls-files database/backups result',
        'release readiness or OTA credential verifier rerun result',
      ],
    },
  ],
  next_gate_order: [
    'Fill drafts with real evidence outside the repository.',
    'Run npm run review:release-evidence-drafts and resolve every blocking field.',
    'Run npm run promote:release-evidence-drafts; do not manually copy drafts to final evidence paths.',
    'Run npm run review:release-design.',
    'Run npm run review:release-ota-credentials.',
    'Select the final open PR with RELEASE_PR_NUMBER, run npm run review:release-staged-scope, then run npm run review:release-external-state.',
    'Run npm run review:release-readiness.',
  ],
  forbidden_closure: [
    'Do not leave TODO, CHANGE_ME, placeholder, example.com, or connector error values in final evidence.',
    'Do not include Cookie, token, usertoken, usersign, signature, Authorization, password, or reusable login state values.',
    'Do not treat this draft index as release evidence.',
  ],
};
writeJson(indexPath, index);

writeText(readmePath, `# Release Evidence Drafts

Generated by \`npm run prepare:release-evidence-drafts\`.

These files are operator-fillable drafts only. They are intentionally placed under \`${draftDir}\` and are not consumed by \`npm run review:release-readiness\`.

Existing draft JSON files are not overwritten by default. Set \`RELEASE_EVIDENCE_DRAFT_OVERWRITE=1\` only when the existing drafts are intentionally discarded.

Final evidence paths:

- Design handoff: \`${path.join(evidenceDir, 'design_handoff_manifest.json')}\`
- OTA credential rotation: \`${path.join(evidenceDir, 'ota_credential_rotation_attestation.json')}\`

Do not manually copy a draft to its final path. Replace every placeholder with real reviewed evidence inside the 30-day release evidence window, confirm no secret material is present, run \`npm run review:release-evidence-drafts\`, and let \`npm run promote:release-evidence-drafts\` write the final evidence files.

Validation order:

1. \`npm run review:release-evidence-drafts\`
2. \`npm run promote:release-evidence-drafts\`
3. \`npm run review:release-design\`
4. \`npm run review:release-ota-credentials\`
5. \`npm run review:release-staged-scope\`
6. \`npm run review:release-external-state\`
7. \`npm run review:release-readiness\`
`);

console.log(`Wrote release evidence drafts to ${draftDir}`);
console.log(`Design draft: ${designDraftPath}`);
console.log(`OTA credential draft: ${otaDraftPath}`);
console.log('Drafts are not release-ready evidence.');
