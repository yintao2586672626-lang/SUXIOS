import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildCtripProfileFieldConfigComponent } from './lib/frontend_template_build.mjs';
import { buildFrontendAssetHash, updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import { acquireFrontendTemplateLock, writeFileAtomic } from './lib/frontend_template_lock.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const templatePath = path.join(root, 'resources/frontend/templates/components/ctrip-profile-field-config-panel.html');
const asset = 'components/online-data/ctrip-profile-field-config-panel.js';
const artifactPath = path.join(root, 'public', asset);
const referencePaths = ['public/components/system/app-main-components.js', 'public/components/system/app-main-components-loader.js'];
const verify = process.argv.includes('--verify');
const release = await acquireFrontendTemplateLock(root, { owner: verify ? 'verify-ctrip-field-panel' : 'build-ctrip-field-panel' });
try {
  const template = fs.readFileSync(templatePath);
  const artifactBefore = fs.readFileSync(artifactPath);
  const references = referencePaths.map(relative => ({ relative, path: path.join(root, relative), source: fs.readFileSync(path.join(root, relative), 'utf8') }));
  const artifact = await buildCtripProfileFieldConfigComponent(template.toString('utf8'));
  const updates = references.map(ref => ({ ...ref, update: updateFrontendAssetVersion(ref.source, asset, artifact) }));
  if (!fs.readFileSync(templatePath).equals(template)
    || !fs.readFileSync(artifactPath).equals(artifactBefore)
    || references.some(ref => fs.readFileSync(ref.path, 'utf8') !== ref.source)) {
    throw new Error('Ctrip field-panel source or assets changed during compilation; refusing mixed output.');
  }
  const artifactChanged = !artifactBefore.equals(Buffer.from(artifact));
  if (verify && (artifactChanged || updates.some(ref => ref.update.changed))) {
    throw new Error('Ctrip field-panel runtime artifact or loader version is stale; run build:frontend-template.');
  }
  if (!verify) {
    if (artifactChanged) writeFileAtomic(artifactPath, artifact);
    for (const ref of updates) if (ref.update.changed) writeFileAtomic(ref.path, ref.update.html);
  }
  console.log(JSON.stringify({ mode: verify ? 'verify' : 'build', template: path.relative(root, templatePath), artifact: asset, template_bytes: template.length, artifact_bytes: Buffer.byteLength(artifact), artifact_hash: buildFrontendAssetHash(artifact), artifact_changed: artifactChanged, references: updates.map(ref => ({ path: ref.relative, hash: ref.update.hash, changed: ref.update.changed })), failures: [] }, null, 2));
} finally {
  release();
}
