import fs from 'node:fs';
import path from 'node:path';

const requiredArtifacts = [
  'scan_manifest.json',
  'report.md',
  'report.html',
  'artifacts/01_context/threat_model.md',
  'artifacts/02_discovery/finding_discovery_report.md',
  'artifacts/03_coverage/repository_coverage_ledger.md',
  'artifacts/03_coverage/reviewed_surfaces.md',
  'artifacts/05_findings/validation_summary.md',
  'artifacts/05_findings/attack_path_analysis_report.md',
];

const requiredCompletedPhases = [
  'threat_model',
  'finding_discovery',
  'validation',
  'attack_path_analysis',
  'final_report',
];

function resolveScanPath(repoRoot, scanDir, relativePath) {
  const base = path.isAbsolute(scanDir) ? scanDir : path.join(repoRoot, scanDir);
  return path.join(base, relativePath);
}

export function checkSecurityScanReports({ repoRoot, configuredScanDir }) {
  const failures = [];
  const passes = [];
  const candidateDirs = [
    configuredScanDir,
    'docs/security/codex-security/latest',
    'security/codex-security/latest',
  ].filter(Boolean);

  const scanDir = candidateDirs.find((candidate) => {
    return fs.existsSync(path.isAbsolute(candidate) ? candidate : path.join(repoRoot, candidate));
  });

  if (!scanDir) {
    failures.push('Formal Codex Security scan reports were not found. Set CODEX_SECURITY_SCAN_DIR to a completed scan directory containing scan_manifest.json, report.md, report.html, validation summary, attack-path analysis report, and coverage artifacts before release.');
    return { passes, failures };
  }

  const missingArtifacts = requiredArtifacts.filter((relativePath) => {
    return !fs.existsSync(resolveScanPath(repoRoot, scanDir, relativePath));
  });

  if (missingArtifacts.length > 0) {
    failures.push(`Formal Codex Security scan is incomplete; missing artifacts: ${missingArtifacts.join(', ')}`);
    return { passes, failures };
  }

  let manifest = null;
  try {
    manifest = JSON.parse(fs.readFileSync(resolveScanPath(repoRoot, scanDir, 'scan_manifest.json'), 'utf8'));
  } catch (error) {
    failures.push(`Formal Codex Security scan manifest is not valid JSON: ${error.message}`);
    return { passes, failures };
  }

  const phases = manifest.phases || {};
  const incompletePhases = requiredCompletedPhases.filter((phase) => phases[phase] !== 'completed');
  if (manifest.scan_mode !== 'repository-wide') {
    failures.push('Formal Codex Security scan manifest scan_mode must be repository-wide.');
  }
  if (manifest.subagents_authorized !== true) {
    failures.push('Formal Codex Security scan manifest must confirm subagents_authorized=true.');
  }
  if (manifest.final_report_validated !== true) {
    failures.push('Formal Codex Security scan manifest must confirm final_report_validated=true.');
  }
  if (manifest.report_html_rendered !== true) {
    failures.push('Formal Codex Security scan manifest must confirm report_html_rendered=true.');
  }
  if (incompletePhases.length > 0) {
    failures.push(`Formal Codex Security scan manifest has incomplete phases: ${incompletePhases.join(', ')}`);
  }

  if (failures.length === 0) {
    passes.push('Formal Codex Security scan reports and core coverage artifacts are present.');
  }

  return { passes, failures };
}
