import fs from 'node:fs';
import path from 'node:path';
import { safeJsonParseErrorCode } from './safe_json_parse_error.mjs';

const SECURITY_SCAN_MAX_AGE_DAYS = 30;

const artifactRequirements = [
  {
    manifestKey: 'report_md',
    relativePath: 'report.md',
    minimumBytes: 160,
    requiredPatterns: [/^# .*Security Review/im, /^## Scope/im, /^## Findings/im],
    bindCommit: true,
  },
  {
    manifestKey: 'report_html',
    relativePath: 'report.html',
    minimumBytes: 160,
    requiredPatterns: [/<html[\s>]/i, /<main[\s>]/i, /Security Review/i],
  },
  {
    manifestKey: 'threat_model',
    relativePath: 'artifacts/01_context/threat_model.md',
    minimumBytes: 100,
    requiredPatterns: [/^# .*Threat Model/im, /^## Trust Boundaries/im, /^## Required Invariants/im],
  },
  {
    manifestKey: 'finding_discovery_report',
    relativePath: 'artifacts/02_discovery/finding_discovery_report.md',
    minimumBytes: 120,
    requiredPatterns: [/^# Finding Discovery Report/im, /^## Commands And Evidence/im, /^## Discovery Results/im],
    bindCommit: true,
  },
  {
    manifestKey: 'repository_coverage_ledger',
    relativePath: 'artifacts/03_coverage/repository_coverage_ledger.md',
    minimumBytes: 120,
    requiredPatterns: [/^# Repository Coverage Ledger/im, /\|\s*Row ID\s*\|\s*Surface\s*\|/i],
    bindCommit: true,
  },
  {
    manifestKey: 'reviewed_surfaces',
    relativePath: 'artifacts/03_coverage/reviewed_surfaces.md',
    minimumBytes: 100,
    requiredPatterns: [/^# Reviewed Surfaces/im, /\|\s*Surface\s*\|\s*Risk Area\s*\|\s*Outcome\s*\|/i],
  },
  {
    manifestKey: 'validation_summary',
    relativePath: 'artifacts/05_findings/validation_summary.md',
    minimumBytes: 120,
    requiredPatterns: [/^# Validation Summary/im, /^## Validation Rubric/im, /^## Result/im],
    bindCommit: true,
  },
  {
    manifestKey: 'attack_path_analysis_report',
    relativePath: 'artifacts/05_findings/attack_path_analysis_report.md',
    minimumBytes: 100,
    requiredPatterns: [/^# Attack Path Analysis Report/im, /^## Reportability Decision/im, /^## Counterevidence Summary/im],
  },
];

const requiredArtifacts = [
  'scan_manifest.json',
  ...artifactRequirements.map(({ relativePath }) => relativePath),
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

function isDateOnly(value) {
  const text = String(value ?? '').trim();
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) {
    return false;
  }
  const parsed = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  return parsed.getUTCFullYear() === Number(match[1])
    && parsed.getUTCMonth() === Number(match[2]) - 1
    && parsed.getUTCDate() === Number(match[3]);
}

function isFutureDateOnly(value) {
  const [year, month, day] = String(value ?? '').trim().split('-').map(Number);
  const reviewedDate = Date.UTC(year, month - 1, day);
  const now = new Date();
  const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
  return reviewedDate > today;
}

function isOlderThanScanWindow(value) {
  const [year, month, day] = String(value ?? '').trim().split('-').map(Number);
  const reviewedDate = Date.UTC(year, month - 1, day);
  const now = new Date();
  const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
  return today - reviewedDate > SECURITY_SCAN_MAX_AGE_DAYS * 24 * 60 * 60 * 1000;
}

function resolveGitDirectory(repoRoot) {
  const dotGitPath = path.join(repoRoot, '.git');
  const stat = fs.statSync(dotGitPath);
  if (stat.isDirectory()) {
    return dotGitPath;
  }
  if (!stat.isFile()) {
    return '';
  }
  const marker = fs.readFileSync(dotGitPath, 'utf8').trim();
  const match = marker.match(/^gitdir:\s*(.+)$/i);
  return match ? path.resolve(repoRoot, match[1].trim()) : '';
}

function readCurrentGitCommit(repoRoot) {
  try {
    const gitDir = resolveGitDirectory(repoRoot);
    if (!gitDir) {
      return '';
    }
    const head = fs.readFileSync(path.join(gitDir, 'HEAD'), 'utf8').trim();
    if (/^[a-f0-9]{40}$/i.test(head)) {
      return head.toLowerCase();
    }
    const refMatch = head.match(/^ref:\s*(.+)$/i);
    if (!refMatch) {
      return '';
    }
    const ref = refMatch[1].trim();
    const refRoots = [gitDir];
    const commonDirFile = path.join(gitDir, 'commondir');
    if (fs.existsSync(commonDirFile)) {
      refRoots.push(path.resolve(gitDir, fs.readFileSync(commonDirFile, 'utf8').trim()));
    }
    for (const refRoot of refRoots) {
      const looseRefPath = path.join(refRoot, ...ref.split('/'));
      if (fs.existsSync(looseRefPath)) {
        const commit = fs.readFileSync(looseRefPath, 'utf8').trim();
        if (/^[a-f0-9]{40}$/i.test(commit)) {
          return commit.toLowerCase();
        }
      }
      const packedRefsPath = path.join(refRoot, 'packed-refs');
      if (!fs.existsSync(packedRefsPath)) {
        continue;
      }
      const packedMatch = fs.readFileSync(packedRefsPath, 'utf8')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .find((line) => line.endsWith(` ${ref}`));
      const commit = packedMatch?.split(/\s+/)[0] || '';
      if (/^[a-f0-9]{40}$/i.test(commit)) {
        return commit.toLowerCase();
      }
    }
  } catch {
    return '';
  }
  return '';
}

function validateArtifact(scanPath, requirement, manifestCommit) {
  const failures = [];
  let stat = null;
  try {
    stat = fs.statSync(scanPath);
  } catch {
    return [`Formal Codex Security scan artifact is unreadable: ${requirement.relativePath}`];
  }
  if (!stat.isFile()) {
    return [`Formal Codex Security scan artifact must be a substantive regular file: ${requirement.relativePath}`];
  }

  let content = '';
  try {
    content = fs.readFileSync(scanPath, 'utf8').replace(/^\uFEFF/, '');
  } catch {
    return [`Formal Codex Security scan artifact is unreadable: ${requirement.relativePath}`];
  }
  if (Buffer.byteLength(content, 'utf8') < requirement.minimumBytes
    || requirement.requiredPatterns.some((pattern) => !pattern.test(content))) {
    failures.push(`Formal Codex Security scan artifact must be a substantive regular file with the required structure: ${requirement.relativePath}`);
  }
  if (requirement.bindCommit && manifestCommit && !content.toLowerCase().includes(manifestCommit.toLowerCase())) {
    failures.push(`Formal Codex Security scan artifact is not bound to manifest commit ${manifestCommit}: ${requirement.relativePath}`);
  }
  return failures;
}

export function checkSecurityScanReports({ repoRoot, configuredScanDir, expectedCommit } = {}) {
  const failures = [];
  const passes = [];
  const candidateDirs = [
    configuredScanDir,
    'docs/security/codex-security/latest',
    'security/codex-security/latest',
  ].filter(Boolean);

  const scanDir = candidateDirs.find((candidate) => {
    const candidatePath = path.isAbsolute(candidate) ? candidate : path.join(repoRoot, candidate);
    try {
      return fs.statSync(candidatePath).isDirectory();
    } catch {
      return false;
    }
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

  const nonFileArtifacts = requiredArtifacts.filter((relativePath) => {
    try {
      return !fs.statSync(resolveScanPath(repoRoot, scanDir, relativePath)).isFile();
    } catch {
      return true;
    }
  });
  if (nonFileArtifacts.length > 0) {
    failures.push(`Formal Codex Security scan artifacts must be regular files: ${nonFileArtifacts.join(', ')}`);
    return { passes, failures };
  }

  let manifest = null;
  try {
    manifest = JSON.parse(fs.readFileSync(resolveScanPath(repoRoot, scanDir, 'scan_manifest.json'), 'utf8'));
  } catch (error) {
    failures.push(`Formal Codex Security scan manifest is not valid JSON (${safeJsonParseErrorCode(error)}).`);
    return { passes, failures };
  }

  const phases = manifest.phases || {};
  const incompletePhases = requiredCompletedPhases.filter((phase) => phases[phase] !== 'completed');
  const manifestCommit = String(manifest.commit ?? '').trim().toLowerCase();
  const releaseCommit = String(expectedCommit || readCurrentGitCommit(repoRoot)).trim().toLowerCase();
  const artifactMap = manifest.artifacts && typeof manifest.artifacts === 'object'
    ? manifest.artifacts
    : {};
  const mismatchedArtifactMappings = artifactRequirements.filter(({ manifestKey, relativePath }) => {
    return String(artifactMap[manifestKey] ?? '').replace(/\\/g, '/') !== relativePath;
  });
  if (manifest.schema_version !== 1) {
    failures.push('Formal Codex Security scan manifest schema_version must be 1.');
  }
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
  if (String(manifest.target ?? '').trim() !== 'HOTEL') {
    failures.push('Formal Codex Security scan manifest target must be HOTEL.');
  }
  if (!isDateOnly(manifest.reviewed_at)) {
    failures.push('Formal Codex Security scan manifest reviewed_at must be a real YYYY-MM-DD date.');
  } else if (isFutureDateOnly(manifest.reviewed_at)) {
    failures.push('Formal Codex Security scan manifest reviewed_at must not be in the future.');
  } else if (isOlderThanScanWindow(manifest.reviewed_at)) {
    failures.push(`Formal Codex Security scan manifest reviewed_at must be within the ${SECURITY_SCAN_MAX_AGE_DAYS}-day release evidence window.`);
  }
  if (!String(manifest.reviewer ?? '').trim() || /TODO|CHANGE_ME|example|placeholder/i.test(String(manifest.reviewer))) {
    failures.push('Formal Codex Security scan manifest reviewer must identify an accountable reviewer.');
  }
  if (!/^[a-f0-9]{40}$/i.test(manifestCommit)) {
    failures.push('Formal Codex Security scan manifest commit must be a full 40-character Git commit.');
  }
  if (!/^[a-f0-9]{40}$/i.test(releaseCommit)) {
    failures.push('Formal Codex Security scan could not determine the current release checkout commit.');
  } else if (manifestCommit !== releaseCommit) {
    failures.push('Formal Codex Security scan manifest commit must match the current release checkout commit.');
  }
  if (mismatchedArtifactMappings.length > 0) {
    failures.push(`Formal Codex Security scan manifest artifact mappings are invalid: ${mismatchedArtifactMappings.map(({ manifestKey }) => manifestKey).join(', ')}`);
  }

  for (const requirement of artifactRequirements) {
    failures.push(...validateArtifact(
      resolveScanPath(repoRoot, scanDir, requirement.relativePath),
      requirement,
      manifestCommit,
    ));
  }

  if (failures.length === 0) {
    passes.push('Formal Codex Security scan reports and core coverage artifacts are present.');
  }

  return { passes, failures };
}
