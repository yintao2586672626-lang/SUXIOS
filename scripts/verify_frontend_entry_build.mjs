import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendEntryBuild } from './lib/frontend_entry_build.mjs';
import { syncOperationStaticVersion, syncRevenueAiStaticVersion } from './lib/frontend_lazy_asset_versions.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(repoRoot, 'public/app-main.js'), 'utf8');
const result = await inspectFrontendEntryBuild({
  source,
  artifact: fs.readFileSync(path.join(repoRoot, 'public/app-main.min.js'), 'utf8'),
  html: fs.readFileSync(path.join(repoRoot, 'public/index.html'), 'utf8'),
});
try {
  const lazyVersion = syncOperationStaticVersion(source, fs.readFileSync(path.join(repoRoot, 'public/operation-static.js')));
  if (lazyVersion.source !== source) result.failures.push('operation-static.js loader hash is stale; run build:frontend-entry.');
  const revenueAiVersion = syncRevenueAiStaticVersion(source, fs.readFileSync(path.join(repoRoot, 'public/revenue-ai-static.js')));
  if (revenueAiVersion.source !== source) result.failures.push('revenue-ai-static.js loader hash is stale; run build:frontend-entry.');
} catch (error) {
  result.failures.push(error.message);
}

console.log(JSON.stringify(result, null, 2));
if (result.failures.length) process.exit(1);
