import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const hasOuterAgents = (candidate) => (
  fs.existsSync(path.join(candidate, 'AGENTS.md'))
);

const gitCommonDirectory = (repoRoot) => {
  const value = execFileSync(
    'git',
    ['rev-parse', '--git-common-dir'],
    {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
    },
  ).trim();
  if (!value) return '';
  return path.isAbsolute(value) ? value : path.resolve(repoRoot, value);
};

export function resolveOuterContextRoot(repoRoot) {
  const directParent = path.dirname(repoRoot);
  if (hasOuterAgents(directParent)) return directParent;

  try {
    const commonDirectory = gitCommonDirectory(repoRoot);
    if (commonDirectory) {
      const mainWorktreeRoot = path.dirname(commonDirectory);
      const mainOuterRoot = path.dirname(mainWorktreeRoot);
      if (hasOuterAgents(mainOuterRoot)) return mainOuterRoot;
    }
  } catch {
    // Keep the direct parent fallback so the caller emits the existing,
    // actionable "outer AGENTS.md is missing" verification failure.
  }

  return directParent;
}
