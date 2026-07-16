import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildFrontendTemplateRender } from './lib/frontend_template_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';
import { loadFrontendTemplateSource } from './lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-frontend-template' });
try {
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const indexPath = path.join(repoRoot, 'public/index.html');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const runtimeVueSourcePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const templateSnapshotBuffer = fs.readFileSync(templatePath);
const source = loadFrontendTemplateSource(repoRoot);
if (!source.templateBuffer.equals(templateSnapshotBuffer)) {
  throw new Error('Business template fragments do not match resources/frontend/app-template.html; refusing to write runtime artifacts.');
}
const templateSnapshotHash = crypto.createHash('sha256').update(templateSnapshotBuffer).digest('hex');
if (source.manifest.source_snapshot_sha256 !== templateSnapshotHash
  || source.manifest.source_snapshot_bytes !== templateSnapshotBuffer.length) {
  throw new Error('Frontend template compatibility snapshot metadata is stale; run sync_frontend_template_snapshot.mjs first.');
}

const render = await buildFrontendTemplateRender(source.template);
const runtimeVue = fs.readFileSync(runtimeVueSourcePath);
const indexSource = fs.readFileSync(indexPath, 'utf8');
const renderVersionUpdate = updateFrontendAssetVersion(indexSource, 'app-render.min.js', render);
const runtimeVueVersionUpdate = updateFrontendAssetVersion(
  renderVersionUpdate.html,
  'vue.runtime.global.prod.js',
  runtimeVue,
);
const currentTemplateSnapshotBuffer = fs.readFileSync(templatePath);
const currentSource = loadFrontendTemplateSource(repoRoot);
if (!currentTemplateSnapshotBuffer.equals(templateSnapshotBuffer)
  || !currentSource.templateBuffer.equals(source.templateBuffer)) {
  throw new Error('Frontend template source changed during compilation; refusing to write runtime artifacts.');
}
if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
  throw new Error('public/index.html changed during template compilation; refusing to publish mixed asset versions.');
}

function writeFileIfChanged(file, content) {
  const next = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
  if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
  writeFileAtomic(file, next);
  return true;
}

const renderChanged = writeFileIfChanged(renderPath, render);
const runtimeVueChanged = writeFileIfChanged(runtimeVuePath, runtimeVue);
const indexChanged = writeFileIfChanged(indexPath, runtimeVueVersionUpdate.html);
console.log(JSON.stringify({
  template: path.relative(repoRoot, templatePath),
  fragment_manifest: path.relative(repoRoot, source.manifestPath),
  render: path.relative(repoRoot, renderPath),
  runtime_vue: path.relative(repoRoot, runtimeVuePath),
  fragment_count: source.fragments.length,
  template_bytes: source.templateBuffer.length,
  render_bytes: Buffer.byteLength(render),
  runtime_vue_bytes: runtimeVue.length,
  render_changed: renderChanged,
  runtime_vue_changed: runtimeVueChanged,
  render_hash: renderVersionUpdate.hash,
  runtime_vue_hash: runtimeVueVersionUpdate.hash,
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
