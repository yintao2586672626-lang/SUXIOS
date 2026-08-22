import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildFrontendAuthenticatedStyle,
  buildFrontendAuthenticatedStartupStyle,
  FRONTEND_AUTHENTICATED_STYLE_ARTIFACT,
  FRONTEND_AUTHENTICATED_STYLE_SOURCE,
  FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT,
  loadFrontendAuthenticatedStartupStyleInputs,
} from './lib/frontend_authenticated_style_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = path.join(repoRoot, 'public');
const sourcePath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_SOURCE);
const artifactPath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_ARTIFACT);
const startupArtifactPath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT);
const indexPath = path.join(publicRoot, 'index.html');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, {
  owner: 'build-frontend-authenticated-style',
});

try {
  const source = fs.readFileSync(sourcePath, 'utf8');
  const indexSource = fs.readFileSync(indexPath, 'utf8');
  const artifact = buildFrontendAuthenticatedStyle(source);
  const startupInputs = loadFrontendAuthenticatedStartupStyleInputs(repoRoot);
  const startupArtifact = await buildFrontendAuthenticatedStartupStyle(source, startupInputs.content);
  const fullVersionUpdate = updateFrontendAssetVersion(
    indexSource,
    FRONTEND_AUTHENTICATED_STYLE_ARTIFACT,
    artifact,
  );
  const versionUpdate = updateFrontendAssetVersion(
    fullVersionUpdate.html,
    FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT,
    startupArtifact,
  );

  if (fs.readFileSync(sourcePath, 'utf8') !== source) {
    throw new Error('public/style.css changed during compilation; refusing to publish a stale stylesheet.');
  }
  if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
    throw new Error('public/index.html changed during stylesheet compilation; refusing to publish mixed asset versions.');
  }
  for (const input of startupInputs.sourceFiles) {
    if (fs.readFileSync(input.filePath, 'utf8') !== input.source) {
      throw new Error(`public/${input.relativePath} changed during startup stylesheet compilation; refusing to publish a stale stylesheet.`);
    }
  }

  const nextArtifact = Buffer.from(artifact, 'utf8');
  const artifactChanged = !fs.existsSync(artifactPath) || !fs.readFileSync(artifactPath).equals(nextArtifact);
  if (artifactChanged) writeFileAtomic(artifactPath, nextArtifact);
  const nextStartupArtifact = Buffer.from(startupArtifact, 'utf8');
  const startupArtifactChanged = !fs.existsSync(startupArtifactPath)
    || !fs.readFileSync(startupArtifactPath).equals(nextStartupArtifact);
  if (startupArtifactChanged) writeFileAtomic(startupArtifactPath, nextStartupArtifact);
  const nextIndex = Buffer.from(versionUpdate.html, 'utf8');
  const indexChanged = !fs.readFileSync(indexPath).equals(nextIndex);
  if (indexChanged) writeFileAtomic(indexPath, nextIndex);

  console.log(JSON.stringify({
    source: `public/${FRONTEND_AUTHENTICATED_STYLE_SOURCE}`,
    artifact: `public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT}`,
    source_bytes: Buffer.byteLength(source),
    artifact_bytes: Buffer.byteLength(artifact),
    artifact_hash: fullVersionUpdate.hash,
    artifact_changed: artifactChanged,
    startup_artifact: `public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT}`,
    startup_artifact_bytes: Buffer.byteLength(startupArtifact),
    startup_artifact_hash: versionUpdate.hash,
    startup_artifact_changed: startupArtifactChanged,
    index_changed: indexChanged,
  }, null, 2));
} finally {
  releaseLock();
}
