import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import { acquireFrontendTemplateLock, writeFileAtomic } from './lib/frontend_template_lock.mjs';

const { values } = parseArgs({
  options: {
    check: { type: 'boolean', default: false },
    root: { type: 'string' },
  },
});
const root = values.root
  ? path.resolve(values.root)
  : path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const panelAsset = 'components/online-data/ctrip-order-analysis-panel.js';
const orderLoaderAsset = 'components/online-data/ctrip-order-analysis-loader.js';
const componentsAsset = 'components/system/app-main-components.js';
const componentsLoaderAsset = 'components/system/app-main-components-loader.js';
const assets = [panelAsset, orderLoaderAsset, componentsAsset, componentsLoaderAsset, 'index.html'];

// Check mode is entirely read-only, including the shared lock directory.
const releaseLock = values.check ? null : await acquireFrontendTemplateLock(root, {
  owner: 'sync-ctrip-order-analysis-assets',
});
try {
  const files = new Map(assets.map((asset) => [asset, path.join(root, 'public', asset)]));
  const before = new Map(assets.map((asset) => [asset, fs.readFileSync(files.get(asset))]));
  const after = new Map(before);
  const update = (target, asset) => {
    const result = updateFrontendAssetVersion(after.get(target).toString('utf8'), asset, after.get(asset));
    after.set(target, Buffer.from(result.html, 'utf8'));
  };

  // Resolve the whole dependency chain before publishing any changed file.
  update(orderLoaderAsset, panelAsset);
  update(componentsAsset, panelAsset);
  update(componentsLoaderAsset, componentsAsset);
  update('index.html', componentsAsset);
  update('index.html', orderLoaderAsset);

  for (const asset of assets) {
    if (!fs.readFileSync(files.get(asset)).equals(before.get(asset))) {
      throw new Error(`Frontend input changed while planning Ctrip order analysis assets: ${asset}`);
    }
  }
  const changed = assets.filter((asset) => !after.get(asset).equals(before.get(asset)));
  if (!values.check) {
    for (const asset of changed) writeFileAtomic(files.get(asset), after.get(asset));
  }
  console.log(JSON.stringify({
    status: changed.length ? (values.check ? 'stale' : 'updated') : 'current',
    check: values.check,
    changed: changed.map((asset) => `public/${asset}`),
  }));
  if (values.check && changed.length) process.exitCode = 1;
} finally {
  releaseLock?.();
}
