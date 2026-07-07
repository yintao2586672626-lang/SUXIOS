import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  categorizeReleasePath,
  gitStatusCategoryOrder,
  normalizeReleasePath,
  releaseStagingBucket,
} from './lib/release_worktree_scope.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const outputPath = process.env.RELEASE_STAGED_SCOPE_RESULT_FILE
  ? resolveInputPath(process.env.RELEASE_STAGED_SCOPE_RESULT_FILE)
  : path.join(evidenceDir, 'release-staged-scope-result.json');
const allowOperatorDecision = process.env.RELEASE_STAGED_SCOPE_ALLOW_OPERATOR_DECISION === '1';

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

function runGit(args) {
  return spawnSync('git', ['-c', 'core.quotePath=false', ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
    shell: false,
  });
}

function parseNameStatus(stdout) {
  const lines = String(stdout || '').split(/\r?\n/).filter(Boolean);
  return lines.map((line) => {
    const parts = line.split('\t');
    const status = String(parts[0] || '').trim();
    const rawPath = parts.length > 2 ? parts[2] : parts[1];
    const normalizedPath = normalizeReleasePath(rawPath);
    const category = categorizeReleasePath(normalizedPath);
    const bucket = releaseStagingBucket(category);
    return {
      status,
      path: normalizedPath,
      category,
      bucket,
    };
  });
}

function summarize(entries) {
  const byCategory = {};
  const byBucket = {
    candidate_release_scope: 0,
    needs_explicit_operator_decision: 0,
    must_remain_local_by_default: 0,
  };
  for (const category of gitStatusCategoryOrder) {
    const count = entries.filter((entry) => entry.category === category).length;
    if (count > 0) {
      byCategory[category] = count;
    }
  }
  for (const entry of entries) {
    byBucket[entry.bucket] += 1;
  }
  return {
    staged_entries: entries.length,
    by_category: byCategory,
    by_bucket: byBucket,
  };
}

function review(entries) {
  const passes = [];
  const warnings = [];
  const failures = [];
  const localEntries = entries.filter((entry) => entry.bucket === 'must_remain_local_by_default');
  const operatorDecisionEntries = entries.filter((entry) => entry.bucket === 'needs_explicit_operator_decision');
  const releaseScopeEntries = entries.filter((entry) => entry.bucket === 'candidate_release_scope');

  if (entries.length === 0) {
    passes.push('No staged files are present; staged-scope review has nothing to reject.');
    warnings.push('This does not prove the local worktree is clean and does not close the release external-state gate.');
  } else {
    passes.push(`Staged-scope review inspected ${entries.length} staged entries.`);
  }

  if (releaseScopeEntries.length > 0) {
    passes.push(`${releaseScopeEntries.length} staged entries are release-docs or release-scripts candidates.`);
  }

  if (localEntries.length > 0) {
    failures.push(`Staged files include ${localEntries.length} runtime/local entries that must remain local by default.`);
  } else {
    passes.push('No runtime/local staged entries were found.');
  }

  if (operatorDecisionEntries.length > 0 && !allowOperatorDecision) {
    failures.push(`Staged files include ${operatorDecisionEntries.length} entries requiring explicit operator decision; set RELEASE_STAGED_SCOPE_ALLOW_OPERATOR_DECISION=1 only after approval.`);
  } else if (operatorDecisionEntries.length > 0) {
    warnings.push(`${operatorDecisionEntries.length} staged entries require explicit operator decision and were allowed by RELEASE_STAGED_SCOPE_ALLOW_OPERATOR_DECISION=1.`);
  } else {
    passes.push('No staged entries require explicit operator decision.');
  }

  return { passes, warnings, failures };
}

function main() {
  if (isPathInsideRepo(outputPath)) {
    console.error(`Release staged-scope result output must be outside the repository: ${outputPath}`);
    process.exit(1);
  }

  const diffResult = runGit(['diff', '--cached', '--name-status', '--find-renames']);
  const entries = parseNameStatus(diffResult.stdout);
  const { passes, warnings, failures } = review(entries);
  const result = {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    command: 'npm run review:release-staged-scope',
    release_ready: false,
    status: failures.length === 0 ? 'passed' : 'failed',
    allow_operator_decision: allowOperatorDecision,
    summary: {
      passed: passes.length,
      warnings: warnings.length,
      failures: failures.length,
      ...summarize(entries),
    },
    passes,
    warnings,
    failures,
    staged_entries: entries,
    buckets: {
      candidate_release_scope: entries.filter((entry) => entry.bucket === 'candidate_release_scope'),
      needs_explicit_operator_decision: entries.filter((entry) => entry.bucket === 'needs_explicit_operator_decision'),
      must_remain_local_by_default: entries.filter((entry) => entry.bucket === 'must_remain_local_by_default'),
    },
    close_condition: 'Before final release PR handoff, staged changes must contain only reviewed release scope, no runtime/local artifacts, and no non-release entries without explicit operator approval.',
    does_not_close_release_readiness: true,
  };

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, `${stringifyAsciiJson(result)}\n`, 'utf8');

  console.log(`Release staged-scope summary: ${passes.length} passed, ${warnings.length} warnings, ${failures.length} failures, ${entries.length} staged entries.`);
  console.log(`Wrote release staged-scope result to ${outputPath}`);
  if (warnings.length > 0) {
    for (const warning of warnings) {
      console.warn(`WARN: ${warning}`);
    }
  }
  if (failures.length > 0) {
    for (const failure of failures) {
      console.error(`FAIL: ${failure}`);
    }
    process.exit(1);
  }
}

main();
