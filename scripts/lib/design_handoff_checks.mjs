import fs from 'node:fs';
import path from 'node:path';

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
    manifest = JSON.parse(fs.readFileSync(resolvedManifestPath, 'utf8'));
  } catch (error) {
    failures.push(`Design handoff manifest is not valid JSON: ${error.message}`);
    return { passes, warnings, failures };
  }

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
    return value === '' || value.includes('TODO') || value.includes('example.com');
  });
  const coveredFlows = Array.isArray(manifest.covered_flows) ? manifest.covered_flows : [];
  const missingFlows = requiredFlows.filter((flow) => !coveredFlows.includes(flow));
  const openIssues = Array.isArray(manifest.open_issues) ? manifest.open_issues : null;
  const tokenPath = String(manifest.design_tokens_path ?? '').trim();
  const tokenPathIsUrl = /^https:\/\/\S+$/i.test(tokenPath);
  const tokenPathExists = tokenPath !== '' && !path.isAbsolute(tokenPath) && fs.existsSync(path.join(repoRoot, tokenPath));

  if (missingFields.length > 0) {
    failures.push(`Design handoff manifest is incomplete: ${missingFields.join(', ')}`);
  } else if (!/^\d{4}-\d{2}-\d{2}$/.test(String(manifest.last_reviewed_at))) {
    failures.push('Design handoff manifest last_reviewed_at must use YYYY-MM-DD.');
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
  } else {
    passes.push('Design handoff manifest is present with Figma, Canva, brand-kit, token, flow coverage, and no open design issues.');
  }

  if (matches.length > 0) {
    passes.push(`Design source/token artifacts found: ${matches.length}.`);
  }

  return { passes, warnings, failures };
}
