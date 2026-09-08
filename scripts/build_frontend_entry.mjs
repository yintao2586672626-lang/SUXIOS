import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildFrontendEntry } from './lib/frontend_entry_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import { syncRevenueAiStaticVersion } from './lib/frontend_lazy_asset_versions.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-frontend-entry' });
try {
const sourcePath = path.join(repoRoot, 'public/app-main.js');
const artifactPath = path.join(repoRoot, 'public/app-main.min.js');
const indexPath = path.join(repoRoot, 'public/index.html');
const originalSource = fs.readFileSync(sourcePath, 'utf8');
const revenueAiStaticPath = path.join(repoRoot, 'public/revenue-ai-static.js');
const revenueAiStatic = fs.readFileSync(revenueAiStaticPath);
const revenueAiVersion = syncRevenueAiStaticVersion(originalSource, revenueAiStatic);
const source = revenueAiVersion.source;
const indexSource = fs.readFileSync(indexPath, 'utf8');
const artifact = await buildFrontendEntry(source);
const versionUpdate = updateFrontendAssetVersion(indexSource, 'app-main.min.js', artifact);

if (fs.readFileSync(sourcePath, 'utf8') !== originalSource) {
  throw new Error('public/app-main.js changed during compilation; refusing to publish a stale runtime entry.');
}
if (!fs.readFileSync(revenueAiStaticPath).equals(revenueAiStatic)) {
  throw new Error('public/revenue-ai-static.js changed during compilation; refusing to publish a stale lazy-loader version.');
}
if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
  throw new Error('public/index.html changed during entry compilation; refusing to publish mixed asset versions.');
}

function writeFileIfChanged(file, content) {
  const next = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
  if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
  writeFileAtomic(file, next);
  return true;
}

const sourceChanged = writeFileIfChanged(sourcePath, source);
const artifactChanged = writeFileIfChanged(artifactPath, artifact);
const indexChanged = writeFileIfChanged(indexPath, versionUpdate.html);
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  source_bytes: Buffer.byteLength(source),
  artifact_bytes: Buffer.byteLength(artifact),
  artifact_hash: versionUpdate.hash,
  revenue_ai_static_hash: revenueAiVersion.hash,
  source_changed: sourceChanged,
  artifact_changed: artifactChanged,
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
