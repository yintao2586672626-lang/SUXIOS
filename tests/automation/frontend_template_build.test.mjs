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
  buildFrontendTemplateRender,
  inspectFrontendTemplateBuild,
} from '../../scripts/lib/frontend_template_build.mjs';
import {
  FRONTEND_TEMPLATE_LOCK_RELATIVE_PATH,
  acquireFrontendTemplateLock,
  withFrontendTemplateLock,
  writeFileAtomic,
} from '../../scripts/lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const fragmentManifestPath = path.join(repoRoot, 'resources/frontend/templates/manifest.json');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const compilerVuePath = path.join(repoRoot, 'public/vue.global.prod.js');
const pinnedRuntimeVuePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const indexPath = path.join(repoRoot, 'public/index.html');
const appMainPath = path.join(repoRoot, 'public/app-main.js');

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
  const runtimeVue = fs.readFileSync(runtimeVuePath, 'utf8');
  const pinnedRuntimeVue = fs.readFileSync(pinnedRuntimeVuePath, 'utf8');
  const rebuilt = await buildFrontendTemplateRender(template);

  assert.equal(artifact, rebuilt);
  assert.equal(runtimeVue, pinnedRuntimeVue);
  assert.ok(Buffer.byteLength(template) > 1_000_000);
  assert.ok(Buffer.byteLength(runtimeVue) < Buffer.byteLength(fs.readFileSync(compilerVuePath)) * 0.75);
  assert.doesNotMatch(artifact, /\bwith\s*\(/);
  assert.doesNotThrow(() => new Function('Vue', artifact));
  });
});

test('HTML loads the hashed runtime Vue and render before the minified setup entry', async () => {
  return withTemplateTestLock('runtime-entry-test', async () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  const artifact = fs.readFileSync(renderPath, 'utf8');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  const hash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const deferredScripts = [...html.matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1].split('?')[0]);

  assert.match(html, /<div id="app" v-cloak><\/div>/);
  assert.doesNotMatch(html, /src="vue\.global\.prod\.js/);
  assert.equal(deferredScripts[0], 'vue.runtime.global.prod.js');
  assert.equal(deferredScripts.at(-2), 'app-render.min.js');
  assert.equal(deferredScripts.at(-1), 'app-main.min.js');
  assert.match(html, new RegExp(`<script defer src="app-render\\.min\\.js\\?v=[^"]*-h${hash}"`));
  assert.match(appMain, /render:\s*requireSuxiAppRender\(\)/);

  const report = await inspectFrontendTemplateBuild(repoRoot, { lockHeld: true });
  assert.deepEqual(report.failures, []);
  assert.equal(report.metrics.render_hash, hash);
  assert.ok(report.metrics.fragment_count >= 30);
  assert.equal(report.metrics.template_snapshot_matches, true);
  assert.equal(report.metrics.template_snapshot_pin_matches, true);
  assert.ok(report.metrics.runtime_vue_bytes < report.metrics.compiler_vue_bytes * 0.75);
  });
});
