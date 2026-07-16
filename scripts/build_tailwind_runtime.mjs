import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildTailwindRuntimeCss,
  collectTailwindContentFiles,
} from './lib/frontend_tailwind_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-tailwind-runtime' });
try {
const sourcePath = path.join(repoRoot, 'public/tailwind.full.css');
const artifactPath = path.join(repoRoot, 'public/tailwind.min.css');
const indexPath = path.join(repoRoot, 'public/index.html');
const source = fs.readFileSync(sourcePath, 'utf8');
const indexSource = fs.readFileSync(indexPath, 'utf8');
const contentFiles = collectTailwindContentFiles(repoRoot);
const artifact = await buildTailwindRuntimeCss(source, contentFiles);
const versionUpdate = updateFrontendAssetVersion(indexSource, 'tailwind.min.css', artifact);

if (fs.readFileSync(sourcePath, 'utf8') !== source) {
  throw new Error('public/tailwind.full.css changed during compilation; refusing to publish a stale runtime stylesheet.');
}
if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
  throw new Error('public/index.html changed during Tailwind compilation; refusing to publish mixed asset versions.');
}

function writeFileIfChanged(file, content) {
  const next = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
  if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
  writeFileAtomic(file, next);
  return true;
}

const artifactChanged = writeFileIfChanged(artifactPath, artifact);
const indexChanged = writeFileIfChanged(indexPath, versionUpdate.html);
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  content_file_count: contentFiles.length,
  source_bytes: Buffer.byteLength(source),
  artifact_bytes: Buffer.byteLength(artifact),
  artifact_hash: versionUpdate.hash,
  artifact_changed: artifactChanged,
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
