import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  buildFrontendTemplateRender,
  inspectFrontendTemplateBuild,
} from '../../scripts/lib/frontend_template_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const fragmentManifestPath = path.join(repoRoot, 'resources/frontend/templates/manifest.json');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const compilerVuePath = path.join(repoRoot, 'public/vue.global.prod.js');
const pinnedRuntimeVuePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const indexPath = path.join(repoRoot, 'public/index.html');
const appMainPath = path.join(repoRoot, 'public/app-main.js');

test('business template fragments assemble byte-for-byte to the canonical template', async () => {
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
  for (const requiredId of [
    'shared-expansion-history',
    'shared-transfer-context',
    'shared-transfer-history',
    'page-compass-ai-workbench',
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

test('root template render and runtime-only Vue are deterministic pinned artifacts', async () => {
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

test('HTML loads the hashed runtime Vue and render before the minified setup entry', async () => {
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

  const report = await inspectFrontendTemplateBuild(repoRoot);
  assert.deepEqual(report.failures, []);
  assert.equal(report.metrics.render_hash, hash);
  assert.ok(report.metrics.fragment_count >= 30);
  assert.equal(report.metrics.template_snapshot_matches, true);
  assert.equal(report.metrics.template_snapshot_pin_matches, true);
  assert.ok(report.metrics.runtime_vue_bytes < report.metrics.compiler_vue_bytes * 0.75);
});
