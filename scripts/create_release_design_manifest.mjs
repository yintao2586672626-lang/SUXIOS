import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');

function resolveOutputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveOutputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function defaultOutputPath() {
  return path.join(releaseEvidenceDir, 'design_handoff_manifest.json');
}

function defaultResultPath() {
  return path.join(releaseEvidenceDir, 'release-design-manifest-create-result.json');
}

function readJson(relativePath) {
  const filePath = path.join(repoRoot, relativePath);
  if (!fs.existsSync(filePath)) {
    throw new Error(`Missing required evidence file: ${relativePath}`);
  }
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readJsonPath(filePath) {
  const resolvedPath = resolveOutputPath(filePath);
  if (!fs.existsSync(resolvedPath)) {
    throw new Error(`Design handoff manifest input file was not found: ${resolvedPath}.`);
  }
  return JSON.parse(fs.readFileSync(resolvedPath, 'utf8').replace(/^\uFEFF/, ''));
}

function requireString(value, label) {
  const text = String(value ?? '').trim();
  if (text === '' || /TODO|CHANGE_ME|placeholder|example\.com/i.test(text)) {
    throw new Error(`Missing or placeholder ${label}.`);
  }
  return text;
}

function assertUrl(value, label, pattern) {
  const text = requireString(value, label);
  if (!pattern.test(text)) {
    throw new Error(`${label} must match ${pattern}. Received: ${text}`);
  }
  return text;
}

function requireDateOnly(value, label) {
  const text = requireString(value, label);
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    throw new Error(`${label} must use YYYY-MM-DD.`);
  }
  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const parsed = new Date(Date.UTC(year, month - 1, day));
  if (
    parsed.getUTCFullYear() !== year
    || parsed.getUTCMonth() !== month - 1
    || parsed.getUTCDate() !== day
  ) {
    throw new Error(`${label} must be a real YYYY-MM-DD date.`);
  }
  return text;
}

function assertClosesReleaseDesignGate(evidence, label) {
  if (evidence?.does_not_close_release_design_gate === true) {
    const remaining = Array.isArray(evidence.remaining_design_gate_inputs)
      ? ` Remaining inputs: ${evidence.remaining_design_gate_inputs.join(', ')}.`
      : '';
    throw new Error(`${label} explicitly does not close the release design gate.${remaining}`);
  }

  const status = String(evidence?.status ?? '').trim();
  if (/pending|missing|incomplete|does_not_close|not_close/i.test(status)) {
    throw new Error(`${label} status is not release-closing: ${status}`);
  }

  requireDateOnly(evidence?.reviewed_at, `${label} reviewed_at`);
  requireString(evidence?.reviewer, `${label} reviewer`);
}

function latestReviewedAt(...dates) {
  const reviewedDates = dates.map((date) => requireDateOnly(date, 'source evidence reviewed_at'));
  return reviewedDates.sort().at(-1);
}

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

function stringifyAsciiJson(value) {
  return JSON.stringify(value, null, 2).replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function writeResult(filePath, payload) {
  if (isPathInsideRepo(filePath)) {
    console.error(`WARN: Design manifest creation result was not written because the result path is inside the repository: ${filePath}`);
    return false;
  }
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${stringifyAsciiJson(payload)}\n`, 'utf8');
  return true;
}

function fileSha256(filePath) {
  if (!filePath) {
    return null;
  }
  const resolvedPath = resolveOutputPath(filePath);
  if (!fs.existsSync(resolvedPath)) {
    return null;
  }
  return createHash('sha256').update(fs.readFileSync(resolvedPath)).digest('hex');
}

function printResult(prefix, result) {
  for (const message of result.passes) {
    console.log(`PASS: ${prefix}: ${message}`);
  }
  for (const message of result.warnings) {
    console.warn(`WARN: ${prefix}: ${message}`);
  }
  for (const message of result.failures) {
    console.error(`FAIL: ${prefix}: ${message}`);
  }
}

function assertDesignManifestPasses(manifestPath, prefix) {
  const result = checkDesignHandoff({
    repoRoot,
    manifestPath,
    requireOutsideRepo: true,
  });
  printResult(prefix, result);
  if (result.failures.length > 0) {
    throw new Error(`${prefix} did not pass the release design verifier.`);
  }
}

const resultPath = resolveOutputPath(process.env.DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE || defaultResultPath());
const inputPath = String(process.env.DESIGN_HANDOFF_MANIFEST_INPUT_FILE || '').trim()
  ? resolveOutputPath(process.env.DESIGN_HANDOFF_MANIFEST_INPUT_FILE)
  : null;
let outputPath = null;
let candidatePath = null;

function resultPayload(status, failures = []) {
  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    command: 'npm run release:create-design-manifest',
    release_ready: false,
    does_not_close_release_readiness: true,
    status,
    can_create_manifest: status === 'passed',
    result_file: resultPath,
    evidence_dir: releaseEvidenceDir,
    input_file: inputPath,
    input_file_sha256: fileSha256(inputPath),
    output_file: outputPath,
    output_file_sha256: fileSha256(outputPath),
    candidate_file: candidatePath,
    candidate_file_sha256: fileSha256(candidatePath),
    source_mode: inputPath ? 'external_manifest_input' : 'connector_evidence_compilation',
    source_evidence: {
      figma: 'docs/release_figma_handoff_evidence.json',
      canva: 'docs/release_canva_handoff_evidence.json',
    },
    summary: {
      failure_count: failures.length,
      final_manifest_written: status === 'passed',
    },
    failures,
    next_commands: status === 'passed'
      ? [
        'npm run review:release-design',
        'npm run review:release-readiness',
      ]
      : [
        'Provide release-closing Figma and Canva handoff evidence, or set DESIGN_HANDOFF_MANIFEST_INPUT_FILE to a controlled external manifest JSON.',
        'For connector compilation mode, set DESIGN_HANDOFF_OWNER and CANVA_BRAND_KIT_URL.',
        'npm run release:create-design-manifest',
      ],
    forbidden_closure: [
      'Do not treat this creation result as release-ready evidence.',
      'Do not use connector errors, screenshots, or draft files as release design handoff evidence.',
      'Do not close release readiness until npm run review:release-readiness passes.',
    ],
  };
}

try {
  outputPath = resolveOutputPath(
    process.env.DESIGN_HANDOFF_MANIFEST_OUTPUT
    || process.env.DESIGN_HANDOFF_MANIFEST_FILE
    || defaultOutputPath(),
  );
  if (isPathInsideRepo(outputPath)) {
    throw new Error(`Design handoff manifest output must be outside the repository: ${outputPath}.`);
  }
  if (isPathInsideRepo(resultPath)) {
    throw new Error(`Design manifest creation result must be outside the repository: ${resultPath}.`);
  }
  if (inputPath) {
    if (isPathInsideRepo(inputPath)) {
      throw new Error(`DESIGN_HANDOFF_MANIFEST_INPUT_FILE must be outside the repository: ${inputPath}.`);
    }
    if (path.resolve(inputPath) === path.resolve(outputPath)) {
      throw new Error('Design handoff manifest input and output paths must be different.');
    }

    const inputManifest = readJsonPath(inputPath);
    assertDesignManifestPasses(inputPath, 'input');

    candidatePath = path.join(path.dirname(outputPath), '.design-manifest-candidates', 'design_handoff_manifest.candidate.json');
    writeJson(candidatePath, inputManifest);
    assertDesignManifestPasses(candidatePath, 'candidate');

    writeJson(outputPath, inputManifest);
    assertDesignManifestPasses(outputPath, 'output');

    writeResult(resultPath, resultPayload('passed'));
    console.log('PASS: External design handoff manifest input passed release design verifier.');
    console.log(`Creation result: ${resultPath}`);
    console.log(`Wrote ${outputPath}`);
    process.exit(0);
  }

  const brandKitUrl = assertUrl(
    process.env.CANVA_BRAND_KIT_URL,
    'CANVA_BRAND_KIT_URL',
    /^https:\/\/(www\.)?canva\.com\//i,
  );
  const designHandoffOwner = requireString(
    process.env.DESIGN_HANDOFF_OWNER,
    'DESIGN_HANDOFF_OWNER',
  );
  const sourceReviewMethod = requireString(
    process.env.DESIGN_HANDOFF_SOURCE_REVIEW_METHOD || 'connector_verified',
    'DESIGN_HANDOFF_SOURCE_REVIEW_METHOD',
  );
  if (!/^(connector_verified|manual_access_review|independent_design_audit)$/i.test(sourceReviewMethod)) {
    throw new Error('DESIGN_HANDOFF_SOURCE_REVIEW_METHOD must be connector_verified, manual_access_review, or independent_design_audit.');
  }
  const sourceReviewRef = requireString(
    process.env.DESIGN_HANDOFF_SOURCE_REVIEW_REF
      || 'docs/release_figma_handoff_evidence.json; docs/release_canva_handoff_evidence.json',
    'DESIGN_HANDOFF_SOURCE_REVIEW_REF',
  );

  const figmaEvidence = readJson('docs/release_figma_handoff_evidence.json');
  const canvaEvidence = readJson('docs/release_canva_handoff_evidence.json');

  assertClosesReleaseDesignGate(figmaEvidence, 'Figma handoff evidence');
  assertClosesReleaseDesignGate(canvaEvidence, 'Canva handoff evidence');
  if (canvaEvidence.brand_kits_available !== true) {
    throw new Error('Canva handoff evidence must confirm brand_kits_available=true before creating a release manifest.');
  }

  const requiredFlows = [
    'login',
    'home-dashboard',
    'ota-data',
    'revenue-analysis',
    'ai-decision',
    'operations-management',
    'investment-decision',
  ];

  const coveredFlows = Array.isArray(figmaEvidence.covered_flows)
    ? figmaEvidence.covered_flows
    : [];
  const missingFlows = requiredFlows.filter((flow) => !coveredFlows.includes(flow));
  if (missingFlows.length > 0) {
    throw new Error(`Figma evidence is missing required flows: ${missingFlows.join(', ')}`);
  }

  const designTokensPath = requireString(figmaEvidence.design_tokens_path, 'design token path');
  const designTokensFullPath = path.join(repoRoot, designTokensPath);
  if (!/^https:\/\//i.test(designTokensPath) && !fs.existsSync(designTokensFullPath)) {
    throw new Error(`Design token path does not exist: ${designTokensPath}`);
  }

  const manifest = {
    owner: designHandoffOwner,
    last_reviewed_at: latestReviewedAt(figmaEvidence.reviewed_at, canvaEvidence.reviewed_at),
    figma_url: assertUrl(
      figmaEvidence.figma_url,
      'figma_url',
      /^https:\/\/(www\.)?figma\.com\//i,
    ),
    canva_url: assertUrl(
      canvaEvidence.canva_edit_url || canvaEvidence.canva_view_url,
      'canva_url',
      /^https:\/\/(www\.)?canva\.com\//i,
    ),
    brand_kit_url: brandKitUrl,
    design_tokens_path: designTokensPath,
    covered_flows: requiredFlows,
    source_review: {
      review_method: sourceReviewMethod,
      evidence_ref: sourceReviewRef,
      figma_source_verified: true,
      canva_source_verified: true,
      brand_kit_source_verified: true,
      design_tokens_reviewed: true,
      required_flows_reviewed: true,
    },
    open_issues: [],
  };

  candidatePath = path.join(path.dirname(outputPath), '.design-manifest-candidates', 'design_handoff_manifest.candidate.json');
  writeJson(candidatePath, manifest);
  assertDesignManifestPasses(candidatePath, 'candidate');

  writeJson(outputPath, manifest);
  assertDesignManifestPasses(outputPath, 'output');

  writeResult(resultPath, resultPayload('passed'));
  console.log('PASS: Generated design handoff manifest passed release design verifier.');
  console.log(`Creation result: ${resultPath}`);
  console.log(`Wrote ${outputPath}`);
} catch (error) {
  const message = String(error?.message || error);
  writeResult(resultPath, resultPayload('failed', [message]));
  console.error(`FAIL: ${message}`);
  console.error(`Creation result: ${resultPath}`);
  console.error('No final release design handoff manifest should be used unless this command passes.');
  process.exit(1);
}
