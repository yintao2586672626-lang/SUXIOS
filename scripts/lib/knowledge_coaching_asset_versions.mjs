import fs from 'node:fs';
import path from 'node:path';
import { buildFrontendAssetHash, updateFrontendAssetVersion } from './frontend_asset_version.mjs';
import { acquireFrontendTemplateLock, writeFileAtomic } from './frontend_template_lock.mjs';

// Pin these lazy children before either entry or startup bundles are compiled.
export async function syncKnowledgeCoachingAssetVersions(root) {
  const release = await acquireFrontendTemplateLock(root, { owner: 'knowledge-coaching-asset-versions' });
  try {
    for (const [parent, child] of [
      ['app-main.js', 'components/system/knowledge-center-domain.js'],
      ['components/system/app-main-components.js', 'components/system/manager-coaching-panel.js'],
    ]) {
      const file = path.join(root, 'public', parent);
      const original = fs.readFileSync(file, 'utf8');
      const bytes = fs.readFileSync(path.join(root, 'public', child));
      // Bootstrap the new child with the same canonical content hash as all existing assets.
      const prepared = original.replace(child + '?v=20260926-v1\'', child + '?v=20260926-v1-h' + buildFrontendAssetHash(bytes) + '\'');
      const next = updateFrontendAssetVersion(prepared, child, bytes).html;
      if (next !== original) writeFileAtomic(file, Buffer.from(next));
    }
    const index = path.join(root, 'public/index.html');
    const original = fs.readFileSync(index, 'utf8');
    const child = 'components/system/app-main-components.js';
    const next = updateFrontendAssetVersion(original, child, fs.readFileSync(path.join(root, 'public', child))).html;
    if (next !== original) writeFileAtomic(index, Buffer.from(next));
  } finally { release(); }
}
