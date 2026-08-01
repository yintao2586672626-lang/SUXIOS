export function extractGithubActionsJob(workflow, jobId) {
  const source = String(workflow || '');
  const startMatch = new RegExp(`(?:^|\\n)  ${jobId}:\\r?\\n`).exec(source);
  if (!startMatch) throw new Error(`GitHub Actions job not found: ${jobId}`);
  const start = startMatch.index + (startMatch[0].startsWith('\n') ? 1 : 0);
  const bodyStart = start + startMatch[0].length - (startMatch[0].startsWith('\n') ? 1 : 0);
  const remainder = source.slice(bodyStart);
  const nextMatch = /\n  [A-Za-z0-9_-]+:\r?\n/.exec(remainder);
  return nextMatch ? source.slice(start, bodyStart + nextMatch.index + 1) : source.slice(start);
}
