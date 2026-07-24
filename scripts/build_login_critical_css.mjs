import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildLoginCriticalCss } from './lib/frontend_login_critical_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const artifactPath = path.join(repoRoot, 'public/login-critical.css');
const indexPath = path.join(repoRoot, 'public/index.html');
const bootstrapPath = path.join(repoRoot, 'public/app-bootstrap.js');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-login-critical-css' });

try {
  const indexSource = fs.readFileSync(indexPath, 'utf8');
  const bootstrap = fs.readFileSync(bootstrapPath);
  const artifact = await buildLoginCriticalCss(repoRoot);
  const versionUpdate = updateFrontendAssetVersion(indexSource, 'login-critical.css', artifact);
  const bootstrapVersionUpdate = updateFrontendAssetVersion(
    versionUpdate.html,
    'app-bootstrap.js',
    bootstrap,
  );

  if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
    throw new Error('public/index.html changed during login CSS compilation; refusing to publish mixed asset versions.');
  }

  const writeFileIfChanged = (file, content) => {
    const next = Buffer.from(content, 'utf8');
    if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
    writeFileAtomic(file, next);
    return true;
  };

  const artifactChanged = writeFileIfChanged(artifactPath, artifact);
  const indexChanged = writeFileIfChanged(indexPath, bootstrapVersionUpdate.html);
  console.log(JSON.stringify({
    artifact: path.relative(repoRoot, artifactPath),
    artifact_bytes: Buffer.byteLength(artifact),
    artifact_hash: versionUpdate.hash,
    bootstrap_hash: bootstrapVersionUpdate.hash,
    artifact_changed: artifactChanged,
    index_changed: indexChanged,
  }, null, 2));
} finally {
  releaseLock();
}
