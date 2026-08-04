import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { compile } from '@vue/compiler-dom';
import { minify } from 'terser';
import { readFrontendAssetVersion } from './frontend_asset_version.mjs';
import {
  AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT,
  AUTHENTICATED_ASSET_PHASE_STARTUP,
  extractAuthenticatedAssetEntries,
  requireUniqueFrontendRuntimeAssetReference,
  resolveFrontendRuntimeAssetReferences,
  stripFrontendAssetQuery,
} from './frontend_authenticated_assets.mjs';
import { withFrontendTemplateLock } from './frontend_template_lock.mjs';
import {
  loadFrontendStartupTemplateSource,
  loadFrontendTemplateSource,
} from './frontend_template_source.mjs';

const EMPTY_V_IF_ANCHOR_SOURCE = '_createCommentVNode("v-if", true)';
const EMPTY_V_IF_ANCHOR_RUNTIME = '_createCommentVNode("", true)';
const FRONTEND_RENDER_RAW_MAX_BYTES = 1_425_000;
const FRONTEND_RENDER_TO_TEMPLATE_MAX_RATIO = 0.66;
const FRONTEND_STARTUP_RENDER_RAW_MAX_BYTES = 180_000;

export const DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH = 'resources/frontend/templates/components/data-config-dialogs.html';
export const DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH = 'public/components/system/data-config-dialogs.js';
export const DATA_CONFIG_DIALOGS_COMPONENT_KEY = 'DataConfigDialogsBody';
export const BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH = 'public/components/system/business-closure-views.js';
export const BUSINESS_CLOSURE_LOADER_RELATIVE_PATH = 'public/components/system/business-closure-loader.js';

export const FRONTEND_TEMPLATE_MINIFY_OPTIONS = Object.freeze({
  ecma: 2020,
  module: false,
  toplevel: false,
  compress: Object.freeze({
    defaults: true,
    passes: 2,
    booleans_as_integers: true,
    // These transforms save a few raw bytes but make this template less gzip-friendly.
    conditionals: false,
    unused: false,
  }),
  mangle: Object.freeze({ safari10: true, toplevel: false }),
  format: Object.freeze({
    ascii_only: false,
    beautify: false,
    comments: false,
    semicolons: true,
  }),
});

export function compileFrontendTemplate(template) {
  const errors = [];
  const result = compile(String(template || ''), {
    mode: 'function',
    prefixIdentifiers: true,
    hoistStatic: false,
    comments: false,
    onError: (error) => errors.push(error),
  });
  if (errors.length) {
    const details = errors.map((error) => {
      const line = error.loc?.start?.line ? ` at line ${error.loc.start.line}` : '';
      return `${error.message}${line}`;
    }).join('; ');
    throw new Error(`Vue template compilation failed: ${details}`);
  }

  const markerCount = result.code.split(EMPTY_V_IF_ANCHOR_SOURCE).length - 1;
  if (markerCount < 1) throw new Error('Expected Vue v-if comment anchors were not generated.');
  return result.code.replaceAll(EMPTY_V_IF_ANCHOR_SOURCE, EMPTY_V_IF_ANCHOR_RUNTIME);
}

async function buildFrontendRender(template, globalName) {
  const compiled = compileFrontendTemplate(template);
  const wrapped = `window.${globalName}=(function(Vue){${compiled}})(Vue);`;
  const result = await minify(
    { 'app-render.js': wrapped },
    structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS)
  );
  if (!result.code) throw new Error('Terser returned an empty frontend render artifact.');
  return result.code;
}

export async function buildFrontendTemplateRender(template) {
  return buildFrontendRender(template, 'SUXI_APP_RENDER');
}

export async function buildFrontendStartupRender(template) {
  return buildFrontendRender(template, 'SUXI_APP_STARTUP_RENDER');
}

export async function buildDataConfigDialogsComponent(template) {
  const compiled = compileFrontendTemplate(template);
  const wrapped = `(()=>{const components=window.SUXI_SYSTEM_COMPONENTS||(window.SUXI_SYSTEM_COMPONENTS={});components.${DATA_CONFIG_DIALOGS_COMPONENT_KEY}={name:${JSON.stringify(DATA_CONFIG_DIALOGS_COMPONENT_KEY)},props:{ctx:{type:Object,required:true}},setup(props){return new Proxy({},{get(target,key){if(key==="ctx")return props.ctx;return props.ctx?.[key]??target[key]},set(target,key,value){if(props.ctx){props.ctx[key]=value;return true}target[key]=value;return true},has(target,key){return key in target||!!props.ctx},ownKeys(target){return Reflect.ownKeys(target)},getOwnPropertyDescriptor(){return{enumerable:true,configurable:true}}})},render:(function(Vue){${compiled}})(Vue)}})();`;
  const result = await minify(
    { 'data-config-dialogs.js': wrapped },
    structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS),
  );
  if (!result.code) throw new Error('Terser returned an empty data-config dialogs component artifact.');
  return result.code;
}

export async function buildBusinessClosureViewsComponent(views) {
  if (!Array.isArray(views) || views.length !== 3) {
    throw new Error('Business closure component build requires exactly three extracted views.');
  }
  const componentKeys = new Set();
  const definitions = views.map((view) => {
    const componentKey = String(view?.componentKey || '');
    if (!/^[A-Z][A-Za-z0-9]+$/.test(componentKey) || componentKeys.has(componentKey)) {
      throw new Error(`Invalid or duplicate business closure component key: ${componentKey}`);
    }
    componentKeys.add(componentKey);
    const compiled = compileFrontendTemplate(view.template);
    return `components[${JSON.stringify(componentKey)}]={name:${JSON.stringify(componentKey)},props:{ctx:{type:Object,required:true}},setup(props){return new Proxy({},{get(target,key){if(key==="ctx")return props.ctx;return props.ctx?.[key]??target[key]},set(target,key,value){if(props.ctx){props.ctx[key]=value;return true}target[key]=value;return true},has(target,key){return key in target||!!props.ctx},ownKeys(target){return Reflect.ownKeys(target)},getOwnPropertyDescriptor(){return{enumerable:true,configurable:true}}})},render:(function(Vue){${compiled}})(Vue)};`;
  }).join('');
  const wrapped = `(()=>{const components=window.SUXI_SYSTEM_COMPONENTS||(window.SUXI_SYSTEM_COMPONENTS={});${definitions}})();`;
  const result = await minify(
    { 'business-closure-views.js': wrapped },
    structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS),
  );
  if (!result.code) throw new Error('Terser returned an empty business closure component artifact.');
  return result.code;
}

const readText = (file) => (fs.existsSync(file) ? fs.readFileSync(file, 'utf8') : '');

async function inspectFrontendTemplateBuildUnlocked(repoRoot) {
  const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
  const renderPath = path.join(repoRoot, 'public/app-render.min.js');
  const startupRenderPath = path.join(repoRoot, 'public/app-startup-render.min.js');
  const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
  const compilerVuePath = path.join(repoRoot, 'public/vue.global.prod.js');
  const pinnedRuntimeVuePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
  const indexPath = path.join(repoRoot, 'public/index.html');
  const appMainPath = path.join(repoRoot, 'public/app-main.js');
  const dataConfigDialogsTemplatePath = path.join(repoRoot, DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH);
  const dataConfigDialogsArtifactPath = path.join(repoRoot, DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH);
  const businessClosureViewsArtifactPath = path.join(repoRoot, BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH);
  const businessClosureLoaderPath = path.join(repoRoot, BUSINESS_CLOSURE_LOADER_RELATIVE_PATH);
  const templateSnapshotBuffer = fs.existsSync(templatePath) ? fs.readFileSync(templatePath) : Buffer.alloc(0);
  const templateSnapshot = templateSnapshotBuffer.toString('utf8');
  const artifact = readText(renderPath);
  const startupArtifact = readText(startupRenderPath);
  const runtimeVue = readText(runtimeVuePath);
  const compilerVue = readText(compilerVuePath);
  const pinnedRuntimeVue = readText(pinnedRuntimeVuePath);
  const html = readText(indexPath);
  const appMain = readText(appMainPath);
  const dataConfigDialogsTemplate = readText(dataConfigDialogsTemplatePath);
  const dataConfigDialogsArtifact = readText(dataConfigDialogsArtifactPath);
  const businessClosureViewsArtifact = readText(businessClosureViewsArtifactPath);
  const businessClosureLoader = readText(businessClosureLoaderPath);
  const failures = [];
  let fragmentSource = null;
  let startupFragmentSource = null;
  let template = '';
  let rebuilt = '';
  let startupRebuilt = '';
  let dataConfigDialogsRebuilt = '';
  let businessClosureViewsRebuilt = '';

  if (!templateSnapshotBuffer.length) failures.push('resources/frontend/app-template.html is missing or empty.');
  try {
    fragmentSource = loadFrontendTemplateSource(repoRoot);
    startupFragmentSource = loadFrontendStartupTemplateSource(repoRoot);
    template = fragmentSource.template;
  } catch (error) {
    failures.push(error.message);
  }
  const templateSnapshotMatches = Boolean(
    fragmentSource && templateSnapshotBuffer.length && fragmentSource.templateBuffer.equals(templateSnapshotBuffer)
  );
  const templateSnapshotHash = templateSnapshotBuffer.length
    ? crypto.createHash('sha256').update(templateSnapshotBuffer).digest('hex')
    : '';
  const templateSnapshotPinMatches = Boolean(
    fragmentSource
    && fragmentSource.manifest.source_snapshot_sha256 === templateSnapshotHash
    && fragmentSource.manifest.source_snapshot_bytes === templateSnapshotBuffer.length
  );
  if (fragmentSource && templateSnapshotBuffer.length && !templateSnapshotMatches) {
    failures.push('Business template fragments do not match resources/frontend/app-template.html byte-for-byte.');
  }
  if (fragmentSource && templateSnapshotBuffer.length && !templateSnapshotPinMatches) {
    failures.push('Frontend template compatibility snapshot metadata is stale.');
  }
  if (template) {
    try {
      rebuilt = await buildFrontendTemplateRender(template);
    } catch (error) {
      failures.push(error.message);
    }
  }
  if (startupFragmentSource?.template) {
    try {
      startupRebuilt = await buildFrontendStartupRender(startupFragmentSource.template);
    } catch (error) {
      failures.push(error.message);
    }
  }
  if (!dataConfigDialogsTemplate) {
    failures.push(`${DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH} is missing or empty.`);
  } else {
    try {
      dataConfigDialogsRebuilt = await buildDataConfigDialogsComponent(dataConfigDialogsTemplate);
    } catch (error) {
      failures.push(error.message);
    }
  }
  if (fragmentSource?.businessClosureViews) {
    try {
      businessClosureViewsRebuilt = await buildBusinessClosureViewsComponent(
        fragmentSource.businessClosureViews,
      );
    } catch (error) {
      failures.push(error.message);
    }
  }
  if (!artifact) failures.push('public/app-render.min.js is missing or empty.');
  else if (rebuilt && artifact !== rebuilt) failures.push('public/app-render.min.js is stale or not generated by the pinned build contract.');
  if (!startupArtifact) failures.push('public/app-startup-render.min.js is missing or empty.');
  else if (startupRebuilt && startupArtifact !== startupRebuilt) failures.push('public/app-startup-render.min.js is stale or not generated by the pinned startup-render contract.');
  if (!dataConfigDialogsArtifact) failures.push(`${DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH} is missing or empty.`);
  else if (dataConfigDialogsRebuilt && dataConfigDialogsArtifact !== dataConfigDialogsRebuilt) {
    failures.push(`${DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH} is stale or not generated by the pinned template build contract.`);
  }
  if (!businessClosureViewsArtifact) {
    failures.push(`${BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH} is missing or empty.`);
  } else if (businessClosureViewsRebuilt && businessClosureViewsArtifact !== businessClosureViewsRebuilt) {
    failures.push(`${BUSINESS_CLOSURE_VIEWS_ARTIFACT_RELATIVE_PATH} is stale or not generated by the pinned template build contract.`);
  }
  const businessClosureViewsArtifactHash = businessClosureViewsArtifact
    ? crypto.createHash('sha256').update(businessClosureViewsArtifact).digest('hex').slice(0, 10)
    : '';
  let businessClosureViewsVersion = null;
  if (!businessClosureLoader) {
    failures.push(`${BUSINESS_CLOSURE_LOADER_RELATIVE_PATH} is missing or empty.`);
  } else {
    try {
      businessClosureViewsVersion = readFrontendAssetVersion(
        businessClosureLoader,
        'business-closure-views.js',
      );
    } catch (error) {
      failures.push(error.message);
    }
    if (businessClosureViewsArtifactHash
      && businessClosureViewsVersion?.hash !== businessClosureViewsArtifactHash) {
      failures.push('The business closure loader must reference the current business closure views content hash.');
    }
  }
  if (!runtimeVue) failures.push('public/vue.runtime.global.prod.js is missing or empty.');
  else if (runtimeVue !== pinnedRuntimeVue) failures.push('The runtime-only Vue artifact must exactly match pinned vue@3.5.32.');
  if (templateSnapshotBuffer.length < 1_000_000) failures.push('The canonical root template is unexpectedly small.');
  if (!templateSnapshot.includes('v-if="!isLoggedIn"') || !templateSnapshot.includes('v-if="toast.show"')) {
    failures.push('The canonical root template snapshot is missing login or global toast boundaries.');
  }
  if (!/<div id="app" v-cloak><\/div>/.test(html)) failures.push('public/index.html must contain only the empty #app runtime shell.');
  if (/src="vue\.global\.prod\.js/.test(html)) failures.push('public/index.html must not load the compiler-enabled Vue build.');
  if (!/const suxiActiveRender = shallowRef\(requireSuxiAppRender\(\)\)/.test(appMain)
    || !/return activeRender\.apply\(this, renderArgs\)/.test(appMain)) {
    failures.push('public/app-main.js must attach the precompiled root render through the reactive render switch.');
  }
  const dataConfigRootFragment = fragmentSource?.fragments.find((fragment) => fragment.id === 'dialogs-data-config')?.source || '';
  if (!dataConfigRootFragment.includes('<data-config-dialogs v-if="showDataConfigModal" :ctx="$root"></data-config-dialogs>')
    || dataConfigRootFragment.includes('<form @submit.prevent="saveDataConfig"')) {
    failures.push('The root render must retain only the lazy data-config dialogs wrapper.');
  }
  if (!dataConfigDialogsTemplate.includes('<form @submit.prevent="saveDataConfig"')
    || !dataConfigDialogsTemplate.includes('v-if="showDataConfigModal"')) {
    failures.push('The data-config dialogs component template is missing the preserved modal body.');
  }
  for (const wrapper of [
    '<knowledge-promotion-workbench-view :ctx="$root"></knowledge-promotion-workbench-view>',
    '<home-temporal-trial-view v-if="!dualOtaPmsSelected" :ctx="$root"></home-temporal-trial-view>',
    '<knowledge-xlsx-import-dialog-view v-if="showKnowledgeCenterImportModal" :ctx="$root"></knowledge-xlsx-import-dialog-view>',
  ]) {
    if (!templateSnapshot.includes(wrapper)) {
      failures.push(`The root render is missing the extracted business closure wrapper: ${wrapper}.`);
    }
  }
  for (const componentKey of [
    'KnowledgePromotionWorkbenchBody',
    'HomeTemporalTrialBody',
    'KnowledgeXlsxImportDialogBody',
  ]) {
    if (!businessClosureViewsArtifact.includes(componentKey)) {
      failures.push(`The business closure component artifact is missing ${componentKey}.`);
    }
    if (!businessClosureLoader.includes(componentKey)) {
      failures.push(`The business closure lazy loader is missing ${componentKey}.`);
    }
  }
  if (!appMain.includes('DataConfigDialogs, ...systemComponents')) {
    failures.push('The authenticated entry must register the lazy business closure component loader.');
  }

  let runtimeAssetReferences = [];
  let runtimeAssetEntries = [];
  let renderVersion = null;
  let startupRenderVersion = null;
  let runtimeVueVersion = null;
  try {
    runtimeAssetReferences = resolveFrontendRuntimeAssetReferences(html);
    runtimeAssetEntries = extractAuthenticatedAssetEntries(html);
    requireUniqueFrontendRuntimeAssetReference(html, 'app-render.min.js');
    requireUniqueFrontendRuntimeAssetReference(html, 'app-startup-render.min.js');
    requireUniqueFrontendRuntimeAssetReference(html, 'vue.runtime.global.prod.js');
    requireUniqueFrontendRuntimeAssetReference(html, 'components/system/business-closure-loader.js');
    renderVersion = readFrontendAssetVersion(html, 'app-render.min.js');
    startupRenderVersion = readFrontendAssetVersion(html, 'app-startup-render.min.js');
    runtimeVueVersion = readFrontendAssetVersion(html, 'vue.runtime.global.prod.js');
  } catch (error) {
    failures.push(error.message);
  }
  const runtimeAssets = runtimeAssetReferences.map(stripFrontendAssetQuery);
  const renderHash = artifact ? crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10) : '';
  const startupRenderHash = startupArtifact ? crypto.createHash('sha256').update(startupArtifact).digest('hex').slice(0, 10) : '';
  const runtimeVueHash = runtimeVue ? crypto.createHash('sha256').update(runtimeVue).digest('hex').slice(0, 10) : '';
  const businessClosureLoaderHash = businessClosureLoader
    ? crypto.createHash('sha256').update(businessClosureLoader).digest('hex').slice(0, 10)
    : '';
  if (renderHash && renderVersion?.hash !== renderHash) {
    failures.push('public/index.html must reference the current precompiled render content hash.');
  }
  if (startupRenderHash && startupRenderVersion?.hash !== startupRenderHash) {
    failures.push('public/index.html must reference the current startup render content hash.');
  }
  if (runtimeVueHash && runtimeVueVersion?.hash !== runtimeVueHash) {
    failures.push('public/index.html must reference the current runtime-only Vue content hash.');
  }
  let businessClosureLoaderVersion = null;
  try {
    businessClosureLoaderVersion = readFrontendAssetVersion(
      html,
      'components/system/business-closure-loader.js',
    );
  } catch (error) {
    failures.push(error.message);
  }
  if (businessClosureLoaderHash && businessClosureLoaderVersion?.hash !== businessClosureLoaderHash) {
    failures.push('public/index.html must reference the current business closure loader content hash.');
  }
  if (runtimeAssets[0] !== 'vue.runtime.global.prod.js'
    || runtimeAssets.at(-3) !== 'app-startup-render.min.js'
    || runtimeAssets.at(-2) !== 'app-render.min.js'
    || runtimeAssets.at(-1) !== 'app-main.min.js') {
    failures.push('Authenticated asset order must be runtime Vue -> helpers -> startup render -> deferred full render -> app entry.');
  }
  const phaseFor = (assetName) => runtimeAssetEntries.find(
    (entry) => stripFrontendAssetQuery(entry.src) === assetName,
  )?.phase;
  if (phaseFor('app-startup-render.min.js') !== AUTHENTICATED_ASSET_PHASE_STARTUP
    || phaseFor('app-main.min.js') !== AUTHENTICATED_ASSET_PHASE_STARTUP
    || phaseFor('components/system/business-closure-loader.js') !== AUTHENTICATED_ASSET_PHASE_STARTUP
    || phaseFor('app-render.min.js') !== AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT) {
    failures.push('The home startup render and app entry must load at startup while the full render waits until after first paint.');
  }
  const renderBytes = Buffer.byteLength(artifact);
  const renderGzipBytes = artifact ? gzipSync(artifact, { level: 6 }).length : 0;
  const startupRenderBytes = Buffer.byteLength(startupArtifact);
  const startupRenderGzipBytes = startupArtifact ? gzipSync(startupArtifact, { level: 6 }).length : 0;
  const dataConfigDialogsArtifactBytes = Buffer.byteLength(dataConfigDialogsArtifact);
  const dataConfigDialogsArtifactGzipBytes = dataConfigDialogsArtifact
    ? gzipSync(dataConfigDialogsArtifact, { level: 6 }).length
    : 0;
  const dataConfigDialogsArtifactHash = dataConfigDialogsArtifact
    ? crypto.createHash('sha256').update(dataConfigDialogsArtifact).digest('hex').slice(0, 10)
    : '';
  const businessClosureViewsArtifactBytes = Buffer.byteLength(businessClosureViewsArtifact);
  const businessClosureViewsArtifactGzipBytes = businessClosureViewsArtifact
    ? gzipSync(businessClosureViewsArtifact, { level: 6 }).length
    : 0;
  const renderToTemplateRatio = templateSnapshotBuffer.length > 0
    ? renderBytes / templateSnapshotBuffer.length
    : 0;
  if (artifact && renderBytes >= FRONTEND_RENDER_RAW_MAX_BYTES) {
    failures.push('The precompiled render artifact exceeded the 1.425 MB raw ceiling.');
  }
  if (artifact && renderToTemplateRatio >= FRONTEND_RENDER_TO_TEMPLATE_MAX_RATIO) {
    failures.push('The precompiled render artifact exceeded 66% of the canonical template size.');
  }
  if (startupArtifact && startupRenderBytes >= FRONTEND_STARTUP_RENDER_RAW_MAX_BYTES) {
    failures.push('The home startup render artifact exceeded the 180 KB raw ceiling.');
  }
  if (runtimeVue && compilerVue && !(Buffer.byteLength(runtimeVue) < Buffer.byteLength(compilerVue) * 0.75)) {
    failures.push('The runtime-only Vue artifact must remain materially smaller than the compiler build.');
  }

  return {
    failures,
    metrics: {
      template_bytes: templateSnapshotBuffer.length,
      fragment_count: fragmentSource?.fragments.length ?? 0,
      fragment_bytes: fragmentSource?.templateBuffer.length ?? 0,
      fragment_manifest: fragmentSource
        ? path.relative(repoRoot, fragmentSource.manifestPath).replaceAll('\\', '/')
        : '',
      template_snapshot_matches: templateSnapshotMatches,
      template_snapshot_pin_matches: templateSnapshotPinMatches,
      render_bytes: renderBytes,
      render_gzip_bytes: renderGzipBytes,
      render_to_template_ratio: renderToTemplateRatio,
      render_hash: renderHash,
      startup_render_fragment_count: startupFragmentSource?.fragments.length ?? 0,
      startup_render_bytes: startupRenderBytes,
      startup_render_gzip_bytes: startupRenderGzipBytes,
      startup_render_hash: startupRenderHash,
      data_config_dialogs_template_bytes: Buffer.byteLength(dataConfigDialogsTemplate),
      data_config_dialogs_artifact_bytes: dataConfigDialogsArtifactBytes,
      data_config_dialogs_artifact_gzip_bytes: dataConfigDialogsArtifactGzipBytes,
      data_config_dialogs_artifact_hash: dataConfigDialogsArtifactHash,
      business_closure_view_count: fragmentSource?.businessClosureViews?.length ?? 0,
      business_closure_views_artifact_bytes: businessClosureViewsArtifactBytes,
      business_closure_views_artifact_gzip_bytes: businessClosureViewsArtifactGzipBytes,
      business_closure_views_artifact_hash: businessClosureViewsArtifactHash,
      business_closure_loader_bytes: Buffer.byteLength(businessClosureLoader),
      business_closure_loader_gzip_bytes: businessClosureLoader
        ? gzipSync(businessClosureLoader, { level: 6 }).length
        : 0,
      business_closure_loader_hash: businessClosureLoaderHash,
      runtime_vue_hash: runtimeVueHash,
      runtime_vue_bytes: Buffer.byteLength(runtimeVue),
      runtime_vue_gzip_bytes: runtimeVue ? gzipSync(runtimeVue, { level: 6 }).length : 0,
      compiler_vue_bytes: Buffer.byteLength(compilerVue),
      compiler_vue_gzip_bytes: compilerVue ? gzipSync(compilerVue, { level: 6 }).length : 0,
    },
  };
}

export async function inspectFrontendTemplateBuild(repoRoot, { lockHeld = false } = {}) {
  if (lockHeld) return inspectFrontendTemplateBuildUnlocked(repoRoot);
  return withFrontendTemplateLock(
    repoRoot,
    () => inspectFrontendTemplateBuildUnlocked(repoRoot),
    { owner: 'inspect-frontend-template-build' },
  );
}
