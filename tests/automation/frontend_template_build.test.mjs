import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import crypto from 'node:crypto';
import { once } from 'node:events';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import {
  buildDataConfigDialogsComponent,
  buildFrontendStartupRender,
  buildFrontendTemplateRender,
  DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH,
  DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH,
  FRONTEND_TEMPLATE_MINIFY_OPTIONS,
  inspectFrontendTemplateBuild,
} from '../../scripts/lib/frontend_template_build.mjs';
import {
  FRONTEND_TEMPLATE_LOCK_RELATIVE_PATH,
  acquireFrontendTemplateLock,
  withFrontendTemplateLock,
  writeFileAtomic,
} from '../../scripts/lib/frontend_template_lock.mjs';
import {
  extractAuthenticatedAssetEntries,
  resolveFrontendRuntimeAssetReferences,
  stripFrontendAssetQuery,
} from '../../scripts/lib/frontend_authenticated_assets.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const fragmentManifestPath = path.join(repoRoot, 'resources/frontend/templates/manifest.json');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const startupRenderPath = path.join(repoRoot, 'public/app-startup-render.min.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const compilerVuePath = path.join(repoRoot, 'public/vue.global.prod.js');
const pinnedRuntimeVuePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const indexPath = path.join(repoRoot, 'public/index.html');
const appMainPath = path.join(repoRoot, 'public/app-main.js');
const dataConfigDialogsTemplatePath = path.join(repoRoot, DATA_CONFIG_DIALOGS_TEMPLATE_RELATIVE_PATH);
const dataConfigDialogsArtifactPath = path.join(repoRoot, DATA_CONFIG_DIALOGS_ARTIFACT_RELATIVE_PATH);

async function withTemplateTestLock(owner, action) {
  const release = await acquireFrontendTemplateLock(repoRoot, { owner });
  try {
    return await action();
  } finally {
    release();
  }
}

test('frontend template lock serializes writers and atomic writes replace complete files', async () => {
  const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-template-lock-'));
  try {
    const release = await acquireFrontendTemplateLock(tempRoot, { owner: 'first-writer' });
    await assert.rejects(
      acquireFrontendTemplateLock(tempRoot, {
        owner: 'second-writer',
        waitTimeoutMs: 60,
        pollIntervalMs: 10,
      }),
      /locked by pid/,
    );

    const lockModuleUrl = pathToFileURL(path.join(repoRoot, 'scripts/lib/frontend_template_lock.mjs')).href;
    const childSource = `
      import { acquireFrontendTemplateLock } from ${JSON.stringify(lockModuleUrl)};
      const release = await acquireFrontendTemplateLock(process.argv[1], {
        owner: 'child-writer',
        waitTimeoutMs: 2000,
        pollIntervalMs: 10,
      });
      console.log('child-acquired');
      release();
    `;
    const child = spawn(process.execPath, [
      '--input-type=module',
      '--eval',
      childSource,
      tempRoot,
    ], { stdio: ['ignore', 'pipe', 'pipe'] });
    let childOutput = '';
    let childError = '';
    child.stdout.on('data', (chunk) => { childOutput += chunk; });
    child.stderr.on('data', (chunk) => { childError += chunk; });
    await new Promise((resolve) => setTimeout(resolve, 100));
    assert.equal(child.exitCode, null, 'child writer must wait while the first writer owns the lock');
    release();
    const [childExitCode] = await once(child, 'exit');
    assert.equal(childExitCode, 0, childError);
    assert.match(childOutput, /child-acquired/);

    const releaseAfter = await acquireFrontendTemplateLock(tempRoot, { owner: 'after-release' });
    releaseAfter();

    await assert.rejects(
      withFrontendTemplateLock(
        tempRoot,
        async () => { throw new Error('expected action failure'); },
        { owner: 'failing-action' },
      ),
      /expected action failure/,
    );
    const releaseAfterFailure = await acquireFrontendTemplateLock(tempRoot, { owner: 'after-failure' });
    releaseAfterFailure();

    const staleLockPath = path.join(tempRoot, FRONTEND_TEMPLATE_LOCK_RELATIVE_PATH);
    writeFileAtomic(staleLockPath, `${JSON.stringify({
      schema_version: 1,
      token: 'stale-token',
      pid: 2_147_483_647,
      owner: 'stale-writer',
      acquired_at: '2026-01-01T00:00:00.000Z',
    })}\n`);
    const releaseRecovered = await acquireFrontendTemplateLock(tempRoot, { owner: 'stale-recovery' });
    releaseRecovered();

    const target = path.join(tempRoot, 'output.txt');
    writeFileAtomic(target, 'first');
    writeFileAtomic(target, 'second');
    assert.equal(fs.readFileSync(target, 'utf8'), 'second');
  } finally {
    fs.rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('business template fragments assemble byte-for-byte to the canonical template', async () => {
  return withTemplateTestLock('fragment-assembly-test', async () => {
  assert.equal(
    fs.existsSync(fragmentManifestPath),
    true,
    'resources/frontend/templates/manifest.json must define the ordered business fragments',
  );

  const { loadFrontendTemplateSource } = await import('../../scripts/lib/frontend_template_source.mjs');
  const source = loadFrontendTemplateSource(repoRoot);
  const canonicalBuffer = fs.readFileSync(templatePath);
  const canonical = canonicalBuffer.toString('utf8');
  const canonicalHash = crypto.createHash('sha256').update(canonicalBuffer).digest('hex');
  const ids = source.manifest.fragments.map((fragment) => fragment.id);

  assert.ok(Buffer.isBuffer(source.templateBuffer));
  assert.equal(Buffer.compare(source.templateBuffer, canonicalBuffer), 0);
  assert.equal(source.template, canonical);
  assert.equal(source.manifest.source_snapshot_sha256, canonicalHash);
  assert.equal(source.manifest.source_snapshot_bytes, canonicalBuffer.length);
  assert.ok(source.manifest.fragments.length >= 30);
  assert.equal(new Set(ids).size, ids.length);
  assert.deepEqual(ids.slice(0, 3), ['app-shell', 'page-ai-strategy', 'page-ai-simulation']);
  const homeFragmentIds = [
    'home-shell-open',
    'page-compass-summary',
    'page-ai-workbench',
    'page-compass-detail',
    'home-shell-card-close',
    'home-shared-secondary',
  ];
  const homeFragmentStart = ids.indexOf(homeFragmentIds[0]);
  assert.notEqual(homeFragmentStart, -1, 'missing split home shell fragment');
  assert.deepEqual(
    ids.slice(homeFragmentStart, homeFragmentStart + homeFragmentIds.length),
    homeFragmentIds,
    'home fragments must stay adjacent and preserve their original DOM order',
  );
  const homeFragments = new Map(source.fragments.map((fragment) => [fragment.id, fragment.source]));
  assert.match(homeFragments.get('page-compass-summary'), /data-testid="home-executive-answer"/);
  assert.doesNotMatch(homeFragments.get('page-compass-summary'), /data-testid="home-ai-workbench"/);
  assert.match(homeFragments.get('page-ai-workbench'), /data-testid="home-ai-workbench"/);
  assert.doesNotMatch(homeFragments.get('page-ai-workbench'), /data-testid="home-full-detail-fold"/);
  assert.match(homeFragments.get('page-compass-detail'), /data-testid="home-full-detail-fold"/);
  assert.match(homeFragments.get('home-shared-secondary'), /data-testid="home-secondary-detail-fold"/);
  assert.match(
    homeFragments.get('dialogs-data-config'),
    /<data-config-dialogs v-if="showDataConfigModal" :ctx="\$root"><\/data-config-dialogs>/,
  );
  assert.doesNotMatch(homeFragments.get('dialogs-data-config'), /<form @submit\.prevent="saveDataConfig"/);
  const dataConfigDialogsTemplate = fs.readFileSync(dataConfigDialogsTemplatePath, 'utf8');
  assert.match(dataConfigDialogsTemplate, /<form @submit\.prevent="saveDataConfig"/);
  assert.match(dataConfigDialogsTemplate, /v-if="showDataConfigModal"/);
  for (const requiredId of [
    'shared-expansion-history',
    'shared-transfer-context',
    'shared-transfer-history',
    ...homeFragmentIds,
    'page-ctrip-ebooking',
    'page-meituan-ebooking',
    'page-agent-center',
    'page-online-data',
    'app-shell-close',
    'dialog-ctrip-cookie-editor',
    'dialogs-knowledge-center',
    'dialog-operation-log-detail',
    'dialogs-data-config',
    'global-toast',
  ]) {
    assert.ok(ids.includes(requiredId), `missing business fragment: ${requiredId}`);
  }
  });
});

test('root template render and runtime-only Vue are deterministic pinned artifacts', async () => {
  return withTemplateTestLock('render-determinism-test', async () => {
  const template = fs.readFileSync(templatePath, 'utf8');
  const artifact = fs.readFileSync(renderPath, 'utf8');
  const startupArtifact = fs.readFileSync(startupRenderPath, 'utf8');
  const runtimeVue = fs.readFileSync(runtimeVuePath, 'utf8');
  const dataConfigDialogsTemplate = fs.readFileSync(dataConfigDialogsTemplatePath, 'utf8');
  const dataConfigDialogsArtifact = fs.readFileSync(dataConfigDialogsArtifactPath, 'utf8');
  const pinnedRuntimeVue = fs.readFileSync(pinnedRuntimeVuePath, 'utf8');
  const rebuilt = await buildFrontendTemplateRender(template);
  const { loadFrontendStartupTemplateSource } = await import('../../scripts/lib/frontend_template_source.mjs');
  const startupSource = loadFrontendStartupTemplateSource(repoRoot);
  const startupRebuilt = await buildFrontendStartupRender(startupSource.template);
  const dataConfigDialogsRebuilt = await buildDataConfigDialogsComponent(dataConfigDialogsTemplate);

  assert.equal(artifact, rebuilt);
  assert.equal(startupArtifact, startupRebuilt);
  assert.equal(dataConfigDialogsArtifact, dataConfigDialogsRebuilt);
  assert.ok(Buffer.byteLength(startupArtifact) < Buffer.byteLength(artifact) * 0.2);
  assert.match(startupSource.template, /data-testid="home-executive-answer"/);
  assert.match(startupSource.template, /data-testid="deferred-page-loading"/);
  assert.doesNotMatch(startupSource.template, /currentPage === 'ctrip-ebooking'/);
  assert.equal(runtimeVue, pinnedRuntimeVue);
  assert.ok(Buffer.byteLength(template) > 1_000_000);
  assert.ok(Buffer.byteLength(runtimeVue) < Buffer.byteLength(fs.readFileSync(compilerVuePath)) * 0.75);
  assert.doesNotMatch(artifact, /\bwith\s*\(/);
  assert.doesNotThrow(() => new Function('Vue', artifact));
  assert.doesNotThrow(() => new Function('Vue', 'window', dataConfigDialogsArtifact));
  assert.match(dataConfigDialogsArtifact, /SUXI_SYSTEM_COMPONENTS/);
  assert.match(dataConfigDialogsArtifact, /DataConfigDialogsBody/);
  assert.doesNotMatch(dataConfigDialogsArtifact, /template:/);
  const componentWindow = {};
  const VueRuntime = await import('vue');
  new Function('window', 'Vue', dataConfigDialogsArtifact)(componentWindow, VueRuntime);
  const dataConfigDialogsComponent = componentWindow.SUXI_SYSTEM_COMPONENTS?.DataConfigDialogsBody;
  assert.equal(typeof dataConfigDialogsComponent?.render, 'function');
  assert.equal(typeof dataConfigDialogsComponent?.setup, 'function');
  const componentContext = { dataConfigTitle: '数据配置', showDataConfigModal: true };
  const componentSetup = dataConfigDialogsComponent.setup({ ctx: componentContext });
  assert.equal(componentSetup.dataConfigTitle, '数据配置');
  componentSetup.showDataConfigModal = false;
  assert.equal(componentContext.showDataConfigModal, false);
  assert.equal(FRONTEND_TEMPLATE_MINIFY_OPTIONS.compress.booleans_as_integers, true);
  assert.equal(FRONTEND_TEMPLATE_MINIFY_OPTIONS.compress.conditionals, false);
  assert.equal(FRONTEND_TEMPLATE_MINIFY_OPTIONS.compress.unused, false);
  assert.equal(artifact.endsWith('\n'), false);
  });
});

test('authenticated asset manifest loads the hashed runtime Vue and render before the minified setup entry', async () => {
  return withTemplateTestLock('runtime-entry-test', async () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  const artifact = fs.readFileSync(renderPath, 'utf8');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  const hash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const authenticatedReferences = resolveFrontendRuntimeAssetReferences(html);
  const authenticatedEntries = extractAuthenticatedAssetEntries(html);
  const authenticatedAssets = authenticatedReferences.map(stripFrontendAssetQuery);
  const renderReference = authenticatedReferences.find(
    (reference) => stripFrontendAssetQuery(reference) === 'app-render.min.js',
  );

  assert.match(html, /<div id="app" v-cloak><\/div>/);
  assert.doesNotMatch(html, /src="vue\.global\.prod\.js/);
  assert.equal(authenticatedAssets[0], 'vue.runtime.global.prod.js');
  assert.equal(authenticatedAssets.at(-3), 'app-startup-render.min.js');
  assert.equal(authenticatedAssets.at(-2), 'app-render.min.js');
  assert.equal(authenticatedAssets.at(-1), 'app-main.min.js');
  assert.match(renderReference, new RegExp(`^app-render\\.min\\.js\\?v=[^"]*-h${hash}$`));
  assert.equal(authenticatedEntries.find((entry) => stripFrontendAssetQuery(entry.src) === 'app-render.min.js')?.phase, 'after-first-paint');
  assert.match(appMain, /const suxiActiveRender = shallowRef\(requireSuxiAppRender\(\)\)/);
  assert.match(appMain, /return activeRender\.apply\(this, renderArgs\)/);

  const report = await inspectFrontendTemplateBuild(repoRoot, { lockHeld: true });
  assert.deepEqual(report.failures, []);
  assert.equal(report.metrics.render_hash, hash);
  assert.ok(report.metrics.render_gzip_headroom_bytes >= 2_000);
  assert.ok(report.metrics.data_config_dialogs_artifact_bytes > 0);
  assert.ok(report.metrics.data_config_dialogs_artifact_gzip_bytes > 0);
  assert.ok(report.metrics.startup_render_gzip_bytes < 35_000);
  assert.ok(report.metrics.fragment_count >= 30);
  assert.equal(report.metrics.template_snapshot_matches, true);
  assert.equal(report.metrics.template_snapshot_pin_matches, true);
  assert.ok(report.metrics.runtime_vue_bytes < report.metrics.compiler_vue_bytes * 0.75);
  });
});
