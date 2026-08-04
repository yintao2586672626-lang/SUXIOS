import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildBusinessClosureViewsComponent,
  buildDataConfigDialogsComponent,
  buildFrontendStartupRender,
  buildFrontendTemplateRender,
  BUSINESS_CLOSURE_LOADER_RELATIVE_PATH,
  BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH,
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
const businessClosureViewsArtifactPath = path.join(repoRoot, BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH);
const businessClosureLoaderPath = path.join(repoRoot, BUSINESS_CLOSURE_LOADER_RELATIVE_PATH);
const templateSnapshotBuffer = fs.readFileSync(templatePath);
const dataConfigDialogsTemplateBuffer = fs.readFileSync(dataConfigDialogsTemplatePath);
const businessClosureLoaderBuffer = fs.readFileSync(businessClosureLoaderPath);
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
const businessClosureViewsArtifact = await buildBusinessClosureViewsComponent(source.businessClosureViews);
const businessClosureViewsVersionUpdate = updateFrontendAssetVersion(
  businessClosureLoaderBuffer.toString('utf8'),
  'business-closure-views.js',
  businessClosureViewsArtifact,
);
const businessClosureLoaderArtifact = businessClosureViewsVersionUpdate.html;
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
const businessClosureLoaderVersionUpdate = updateFrontendAssetVersion(
  runtimeVueVersionUpdate.html,
  'components/system/business-closure-loader.js',
  businessClosureLoaderArtifact,
);
const currentTemplateSnapshotBuffer = fs.readFileSync(templatePath);
const currentDataConfigDialogsTemplateBuffer = fs.readFileSync(dataConfigDialogsTemplatePath);
const currentSource = loadFrontendTemplateSource(repoRoot);
if (!currentTemplateSnapshotBuffer.equals(templateSnapshotBuffer)
  || !currentSource.templateBuffer.equals(source.templateBuffer)
  || JSON.stringify(currentSource.businessClosureViews) !== JSON.stringify(source.businessClosureViews)
  || !currentDataConfigDialogsTemplateBuffer.equals(dataConfigDialogsTemplateBuffer)
  || !fs.readFileSync(businessClosureLoaderPath).equals(businessClosureLoaderBuffer)) {
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
const businessClosureViewsArtifactChanged = writeFileIfChanged(
  businessClosureViewsArtifactPath,
  businessClosureViewsArtifact,
);
const businessClosureLoaderChanged = writeFileIfChanged(
  businessClosureLoaderPath,
  businessClosureLoaderArtifact,
);
const indexChanged = writeFileIfChanged(indexPath, businessClosureLoaderVersionUpdate.html);
console.log(JSON.stringify({
  template: path.relative(repoRoot, templatePath),
  fragment_manifest: path.relative(repoRoot, source.manifestPath),
  render: path.relative(repoRoot, renderPath),
  startup_render: path.relative(repoRoot, startupRenderPath),
  runtime_vue: path.relative(repoRoot, runtimeVuePath),
  data_config_dialogs_template: path.relative(repoRoot, dataConfigDialogsTemplatePath),
  data_config_dialogs_artifact: path.relative(repoRoot, dataConfigDialogsArtifactPath),
  business_closure_views_artifact: path.relative(repoRoot, businessClosureViewsArtifactPath),
  business_closure_loader: path.relative(repoRoot, businessClosureLoaderPath),
  fragment_count: source.fragments.length,
  template_bytes: source.templateBuffer.length,
  render_bytes: Buffer.byteLength(render),
  startup_render_bytes: Buffer.byteLength(startupRender),
  runtime_vue_bytes: runtimeVue.length,
  data_config_dialogs_artifact_bytes: Buffer.byteLength(dataConfigDialogsArtifact),
  business_closure_views_artifact_bytes: Buffer.byteLength(businessClosureViewsArtifact),
  business_closure_loader_bytes: Buffer.byteLength(businessClosureLoaderArtifact),
  render_changed: renderChanged,
  startup_render_changed: startupRenderChanged,
  runtime_vue_changed: runtimeVueChanged,
  data_config_dialogs_artifact_changed: dataConfigDialogsArtifactChanged,
  business_closure_views_artifact_changed: businessClosureViewsArtifactChanged,
  business_closure_loader_changed: businessClosureLoaderChanged,
  render_hash: renderVersionUpdate.hash,
  startup_render_hash: startupRenderVersionUpdate.hash,
  runtime_vue_hash: runtimeVueVersionUpdate.hash,
  data_config_dialogs_artifact_hash: crypto.createHash('sha256').update(dataConfigDialogsArtifact).digest('hex').slice(0, 10),
  business_closure_views_artifact_hash: crypto.createHash('sha256').update(businessClosureViewsArtifact).digest('hex').slice(0, 10),
  business_closure_views_loader_version_hash: businessClosureViewsVersionUpdate.hash,
  business_closure_loader_hash: businessClosureLoaderVersionUpdate.hash,
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
