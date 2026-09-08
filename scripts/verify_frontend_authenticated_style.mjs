import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendAuthenticatedStyle } from './lib/frontend_authenticated_style_build.mjs';
import { buildFrontendAssetHash, readFrontendAssetVersion } from './lib/frontend_asset_version.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectFrontendAuthenticatedStyle(repoRoot);
try {
  const dashboardStyleName = 'compass-authority-polish.css';
  const expected = buildFrontendAssetHash(fs.readFileSync(path.join(repoRoot, 'public', dashboardStyleName)));
  const actual = readFrontendAssetVersion(fs.readFileSync(path.join(repoRoot, 'public/index.html'), 'utf8'), dashboardStyleName).hash;
  result.dashboard_style = { expected_hash: expected, entry_hash: actual };
  if (actual !== expected) result.failures.push('Dashboard stylesheet asset version is stale; run npm run build:authenticated-style.');
} catch (error) {
  result.failures.push(`Dashboard stylesheet verification failed: ${error.message}`);
}
console.log(JSON.stringify(result, null, 2));
if (result.failures.length > 0) process.exit(1);
