import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildFrontendBootstrap,
  buildFrontendDeferredHelpers,
  buildFrontendStartupHelpers,
  FRONTEND_BOOTSTRAP_ARTIFACT,
  FRONTEND_BOOTSTRAP_SOURCE,
  FRONTEND_DEFERRED_HELPER_ARTIFACT,
  FRONTEND_DEFERRED_HELPER_SOURCES,
  FRONTEND_STARTUP_HELPER_ARTIFACT,
  FRONTEND_STARTUP_HELPER_SOURCES,
  updateFrontendStartupArtifactReferences,
} from './lib/frontend_startup_helpers_build.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const publicRoot = path.join(repoRoot, 'public');
const indexPath = path.join(publicRoot, 'index.html');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, {
  owner: 'build-frontend-startup-helpers',
});

try {
  const indexSource = fs.readFileSync(indexPath, 'utf8');
  const bootstrapPath = path.join(publicRoot, FRONTEND_BOOTSTRAP_SOURCE);
  const bootstrapSource = fs.readFileSync(bootstrapPath, 'utf8');
  const helperSources = FRONTEND_STARTUP_HELPER_SOURCES.map((name) => ({
    name,
    path: path.join(publicRoot, name),
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const deferredHelperSources = FRONTEND_DEFERRED_HELPER_SOURCES.map((name) => ({
    name,
    path: path.join(publicRoot, name),
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const [bootstrapArtifact, helperArtifact, deferredHelperArtifact] = await Promise.all([
    buildFrontendBootstrap(bootstrapSource),
    buildFrontendStartupHelpers(helperSources),
    buildFrontendDeferredHelpers(deferredHelperSources),
  ]);
  const nextIndex = updateFrontendStartupArtifactReferences(
    indexSource,
    bootstrapArtifact,
    helperArtifact,
    deferredHelperArtifact,
  );

  if (fs.readFileSync(bootstrapPath, 'utf8') !== bootstrapSource) {
    throw new Error(
      'public/app-bootstrap.js changed during compilation; refusing to publish a stale runtime bootstrap.',
    );
  }
  for (const source of [...helperSources, ...deferredHelperSources]) {
    if (fs.readFileSync(source.path, 'utf8') !== source.source) {
      throw new Error(
        `public/${source.name} changed during compilation; refusing to publish a stale startup bundle.`,
      );
    }
  }
  if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
    throw new Error(
      'public/index.html changed during startup compilation; refusing to publish mixed asset versions.',
    );
  }

  const writeFileIfChanged = (file, content) => {
    const next = Buffer.from(content, 'utf8');
    if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
    writeFileAtomic(file, next);
    return true;
  };

  const bootstrapChanged = writeFileIfChanged(
    path.join(publicRoot, FRONTEND_BOOTSTRAP_ARTIFACT),
    bootstrapArtifact,
  );
  const helpersChanged = writeFileIfChanged(
    path.join(publicRoot, FRONTEND_STARTUP_HELPER_ARTIFACT),
    helperArtifact,
  );
  const deferredHelpersChanged = writeFileIfChanged(
    path.join(publicRoot, FRONTEND_DEFERRED_HELPER_ARTIFACT),
    deferredHelperArtifact,
  );
  const indexChanged = writeFileIfChanged(indexPath, nextIndex);

  console.log(JSON.stringify({
    bootstrap_artifact: `public/${FRONTEND_BOOTSTRAP_ARTIFACT}`,
    bootstrap_artifact_bytes: Buffer.byteLength(bootstrapArtifact),
    bootstrap_changed: bootstrapChanged,
    helper_artifact: `public/${FRONTEND_STARTUP_HELPER_ARTIFACT}`,
    helper_artifact_bytes: Buffer.byteLength(helperArtifact),
    helpers_changed: helpersChanged,
    deferred_helper_artifact: `public/${FRONTEND_DEFERRED_HELPER_ARTIFACT}`,
    deferred_helper_artifact_bytes: Buffer.byteLength(deferredHelperArtifact),
    deferred_helpers_changed: deferredHelpersChanged,
    index_changed: indexChanged,
  }, null, 2));
} finally {
  releaseLock();
}
