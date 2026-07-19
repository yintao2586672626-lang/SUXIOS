import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildDataConfigDialogsComponent,
  buildFrontendStartupRender,
  buildFrontendTemplateRender,
  DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH,
  DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH,
} from './lib/frontend_template_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';
import {
  loadFrontendStartupTemplateSource,
  loadFrontendTemplateSource,
} from './lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-frontend-template' });
try {
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const indexPath = path.join(repoRoot, 'public/index.html');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const startupRenderPath = path.join(repoRoot, 'public/app-startup-render.min.js');
const runtimeVueSourcePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const dataConfigDialogsTemplatePath = path.join(repoRoot, DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH);
const dataConfigDialogsArtifactPath = path.join(repoRoot, DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH);
const templateSnapshotBuffer = fs.readFileSync(templatePath);
const dataConfigDialogsTemplateBuffer = fs.readFileSync(dataConfigDialogsTemplatePath);
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
const startupSource = loadFrontendStartupTemplateSource(repoRoot);
const startupRender = await buildFrontendStartupRender(startupSource.template);
const dataConfigDialogsArtifact = await buildDataConfigDialogsComponent(dataConfigDialogsTemplateBuffer.toString('utf8'));
const runtimeVue = fs.readFileSync(runtimeVueSourcePath);
const indexSource = fs.readFileSync(indexPath, 'utf8');
const renderVersionUpdate = updateFrontendAssetVersion(indexSource, 'app-render.min.js', render);
const startupRenderVersionUpdate = updateFrontendAssetVersion(
  renderVersionUpdate.html,
  'app-startup-render.min.js',
  startupRender,
);
const runtimeVueVersionUpdate = updateFrontendAssetVersion(
  startupRenderVersionUpdate.html,
  'vue.runtime.global.prod.js',
  runtimeVue,
);
const currentTemplateSnapshotBuffer = fs.readFileSync(templatePath);
const currentDataConfigDialogsTemplateBuffer = fs.readFileSync(dataConfigDialogsTemplatePath);
const currentSource = loadFrontendTemplateSource(repoRoot);
if (!currentTemplateSnapshotBuffer.equals(templateSnapshotBuffer)
  || !currentSource.templateBuffer.equals(source.templateBuffer)
  || !currentDataConfigDialogsTemplateBuffer.equals(dataConfigDialogsTemplateBuffer)) {
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
const startupRenderChanged = writeFileIfChanged(startupRenderPath, startupRender);
const runtimeVueChanged = writeFileIfChanged(runtimeVuePath, runtimeVue);
const dataConfigDialogsArtifactChanged = writeFileIfChanged(dataConfigDialogsArtifactPath, dataConfigDialogsArtifact);
const indexChanged = writeFileIfChanged(indexPath, runtimeVueVersionUpdate.html);
console.log(JSON.stringify({
  template: path.relative(repoRoot, templatePath),
  fragment_manifest: path.relative(repoRoot, source.manifestPath),
  render: path.relative(repoRoot, renderPath),
  startup_render: path.relative(repoRoot, startupRenderPath),
  runtime_vue: path.relative(repoRoot, runtimeVuePath),
  data_config_dialogs_template: path.relative(repoRoot, dataConfigDialogsTemplatePath),
  data_config_dialogs_artifact: path.relative(repoRoot, dataConfigDialogsArtifactPath),
  fragment_count: source.fragments.length,
  template_bytes: source.templateBuffer.length,
  render_bytes: Buffer.byteLength(render),
  startup_render_bytes: Buffer.byteLength(startupRender),
  runtime_vue_bytes: runtimeVue.length,
  data_config_dialogs_artifact_bytes: Buffer.byteLength(dataConfigDialogsArtifact),
  render_changed: renderChanged,
  startup_render_changed: startupRenderChanged,
  runtime_vue_changed: runtimeVueChanged,
  data_config_dialogs_artifact_changed: dataConfigDialogsArtifactChanged,
  render_hash: renderVersionUpdate.hash,
  startup_render_hash: startupRenderVersionUpdate.hash,
  runtime_vue_hash: runtimeVueVersionUpdate.hash,
  data_config_dialogs_artifact_hash: crypto.createHash('sha256').update(dataConfigDialogsArtifact).digest('hex').slice(0, 10),
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
