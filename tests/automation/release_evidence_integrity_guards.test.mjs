import assert from 'node:assert/strict';
import {
  mkdirSync,
  mkdtempSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { checkDesignHandoff } from '../../scripts/lib/design_handoff_checks.mjs';
import { checkSecurityScanReports } from '../../scripts/lib/security_scan_checks.mjs';

const CURRENT_DATE = new Date().toISOString().slice(0, 10);
const SCAN_COMMIT = 'a'.repeat(40);

const scanArtifactContents = {
  'report.md': `# Security Review: HOTEL\n\n## Scope\n\nCommit reviewed: \`${SCAN_COMMIT}\`.\n\n## Findings\n\nNo open finding.\n\n## Reviewed Surfaces\n\nAll required surfaces were reviewed.\n`,
  'report.html': '<!doctype html><html><head><title>HOTEL Security Review</title></head><body><main><h1>Security Review: HOTEL</h1><h2>Scope</h2><h2>Findings</h2></main></body></html>',
  'artifacts/01_context/threat_model.md': '# Repository Threat Model\n\n## Trust Boundaries\n\nExternal and internal trust boundaries.\n\n## Required Invariants\n\nTenant and credential invariants.\n',
  'artifacts/02_discovery/finding_discovery_report.md': `# Finding Discovery Report\n\nCommit reviewed: \`${SCAN_COMMIT}\`.\n\n## Commands And Evidence\n\nFocused verifier evidence.\n\n## Discovery Results\n\nNo unresolved candidate.\n`,
  'artifacts/03_coverage/repository_coverage_ledger.md': `# Repository Coverage Ledger\n\nCommit reviewed: \`${SCAN_COMMIT}\`.\n\n| Row ID | Surface | Risk Area | Evidence | Disposition |\n|---|---|---|---|---|\n| R1 | route | auth | direct review | no_issue_found |\n`,
  'artifacts/03_coverage/reviewed_surfaces.md': '# Reviewed Surfaces\n\n| Surface | Risk Area | Outcome | Notes |\n|---|---|---|---|\n| Routes | Authentication | No issue found | Direct review. |\n',
  'artifacts/05_findings/validation_summary.md': `# Validation Summary\n\nCommit reviewed: \`${SCAN_COMMIT}\`.\n\n## Validation Rubric\n\nDirect source evidence.\n\n## Result\n\nNo unresolved candidate.\n`,
  'artifacts/05_findings/attack_path_analysis_report.md': '# Attack Path Analysis Report\n\n## Reportability Decision\n\nNo reportable path.\n\n## Counterevidence Summary\n\nControls were directly reviewed.\n',
};

function writeScanFixture(repoRoot, { emptyArtifacts = false, commit = SCAN_COMMIT } = {}) {
  const scanDir = path.join(repoRoot, 'scan');
  for (const relativePath of Object.keys(scanArtifactContents)) {
    const target = path.join(scanDir, relativePath);
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, emptyArtifacts ? '' : scanArtifactContents[relativePath], 'utf8');
  }
  writeFileSync(path.join(scanDir, 'scan_manifest.json'), JSON.stringify({
    schema_version: 1,
    scan_mode: 'repository-wide',
    target: 'HOTEL',
    reviewed_at: CURRENT_DATE,
    reviewer: 'Security Review Owner',
    commit,
    subagents_authorized: true,
    phases: {
      threat_model: 'completed',
      finding_discovery: 'completed',
      validation: 'completed',
      attack_path_analysis: 'completed',
      final_report: 'completed',
    },
    artifacts: {
      report_md: 'report.md',
      report_html: 'report.html',
      threat_model: 'artifacts/01_context/threat_model.md',
      finding_discovery_report: 'artifacts/02_discovery/finding_discovery_report.md',
      repository_coverage_ledger: 'artifacts/03_coverage/repository_coverage_ledger.md',
      reviewed_surfaces: 'artifacts/03_coverage/reviewed_surfaces.md',
      validation_summary: 'artifacts/05_findings/validation_summary.md',
      attack_path_analysis_report: 'artifacts/05_findings/attack_path_analysis_report.md',
    },
    final_report_validated: true,
    report_html_rendered: true,
  }, null, 2), 'utf8');
  return scanDir;
}

function validDesignManifest(designTokensPath) {
  return {
    owner: 'Design Operations Owner',
    last_reviewed_at: CURRENT_DATE,
    figma_url: 'https://www.figma.com/design/release-source',
    canva_url: 'https://www.canva.com/design/release-source',
    brand_kit_url: 'https://www.canva.com/brand/release-kit',
    design_tokens_path: designTokensPath,
    covered_flows: [
      'login',
      'home-dashboard',
      'ota-data',
      'revenue-analysis',
      'ai-decision',
      'operations-management',
      'investment-decision',
    ],
    open_issues: [],
    source_review: {
      review_method: 'independent_design_audit',
      evidence_ref: 'DESIGN-REVIEW-20260722',
      figma_source_verified: true,
      canva_source_verified: true,
      brand_kit_source_verified: true,
      design_tokens_reviewed: true,
      required_flows_reviewed: true,
    },
  };
}

test('security scan gate rejects present but empty report and coverage artifacts', (t) => {
  const repoRoot = mkdtempSync(path.join(os.tmpdir(), 'suxi-security-empty-'));
  t.after(() => rmSync(repoRoot, { recursive: true, force: true }));
  const scanDir = writeScanFixture(repoRoot, { emptyArtifacts: true });

  const result = checkSecurityScanReports({
    repoRoot,
    configuredScanDir: scanDir,
    expectedCommit: SCAN_COMMIT,
  });

  assert.ok(result.failures.length > 0);
  assert.match(result.failures.join('\n'), /substantive regular file/i);
});

test('security scan gate binds the manifest to the release checkout commit', (t) => {
  const repoRoot = mkdtempSync(path.join(os.tmpdir(), 'suxi-security-commit-'));
  t.after(() => rmSync(repoRoot, { recursive: true, force: true }));
  const scanDir = writeScanFixture(repoRoot);

  const result = checkSecurityScanReports({
    repoRoot,
    configuredScanDir: scanDir,
    expectedCommit: 'b'.repeat(40),
  });

  assert.match(result.failures.join('\n'), /must match the current release checkout commit/i);
});

test('design token evidence rejects a parent-directory escape even when the target exists', (t) => {
  const tempRoot = mkdtempSync(path.join(os.tmpdir(), 'suxi-design-escape-'));
  t.after(() => rmSync(tempRoot, { recursive: true, force: true }));
  const repoRoot = path.join(tempRoot, 'repo');
  mkdirSync(repoRoot, { recursive: true });
  writeFileSync(path.join(tempRoot, 'outside.tokens.json'), '{}', 'utf8');
  const manifestPath = path.join(tempRoot, 'design-manifest.json');
  writeFileSync(manifestPath, JSON.stringify(validDesignManifest('../outside.tokens.json')), 'utf8');

  const result = checkDesignHandoff({ repoRoot, manifestPath, requireOutsideRepo: true });

  assert.match(result.failures.join('\n'), /repo-relative existing token file/i);
});

test('design token evidence rejects directories', (t) => {
  const tempRoot = mkdtempSync(path.join(os.tmpdir(), 'suxi-design-directory-'));
  t.after(() => rmSync(tempRoot, { recursive: true, force: true }));
  const repoRoot = path.join(tempRoot, 'repo');
  mkdirSync(path.join(repoRoot, 'tokens'), { recursive: true });
  const manifestPath = path.join(tempRoot, 'design-manifest.json');
  writeFileSync(manifestPath, JSON.stringify(validDesignManifest('tokens')), 'utf8');

  const result = checkDesignHandoff({ repoRoot, manifestPath, requireOutsideRepo: true });

  assert.match(result.failures.join('\n'), /repo-relative existing token file/i);
});
