import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');

function readJson(relativePath) {
  const filePath = path.join(repoRoot, relativePath);
  if (!fs.existsSync(filePath)) {
    throw new Error(`Missing required evidence file: ${relativePath}`);
  }
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
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

const brandKitUrl = assertUrl(
  process.env.CANVA_BRAND_KIT_URL,
  'CANVA_BRAND_KIT_URL',
  /^https:\/\/(www\.)?canva\.com\//i,
);

const figmaEvidence = readJson('docs/release_figma_handoff_evidence.json');
const canvaEvidence = readJson('docs/release_canva_handoff_evidence.json');

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
  owner: 'Codex release handoff',
  last_reviewed_at: new Date().toISOString().slice(0, 10),
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
  open_issues: [],
};

const outputPath = path.join(repoRoot, 'docs/design_handoff_manifest.json');
fs.writeFileSync(outputPath, `${JSON.stringify(manifest, null, 2)}\n`);

console.log(`Wrote ${path.relative(repoRoot, outputPath).replace(/\\/g, '/')}`);
