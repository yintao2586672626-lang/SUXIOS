import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import {
  extractAuthenticatedAssetReferences,
  stripFrontendAssetQuery,
} from '../../scripts/lib/frontend_authenticated_assets.mjs';

const indexPath = 'public/index.html';
const appMainPath = 'public/app-main.js';
const appMainRuntimePath = 'public/app-main.min.js';

test('main Vue bootstrap is external, authenticated, ordered, and content-versioned', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  assert.equal(fs.existsSync(appMainPath), true, 'public/app-main.js must exist');
  assert.equal(fs.existsSync(appMainRuntimePath), true, 'public/app-main.min.js must exist');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  const appMainRuntime = fs.readFileSync(appMainRuntimePath, 'utf8');
  assert.doesNotMatch(html, /const\s+suxiApp\s*=\s*createApp\(/);
  assert.match(appMain, /const mountSuxiApp = \(\) =>/);
  assert.match(appMain, /configureSuxiApp\(createApp\(suxiRootComponent\)\)/);

  const authenticatedSources = extractAuthenticatedAssetReferences(html);
  const authenticatedAssets = authenticatedSources.map(stripFrontendAssetQuery);
  assert.equal(authenticatedAssets[0], 'vue.runtime.global.prod.js');
  assert.equal(authenticatedAssets.at(-3), 'app-startup-render.min.js');
  assert.equal(authenticatedAssets.at(-2), 'app-render.min.js');
  assert.equal(authenticatedAssets.at(-1), 'app-main.min.js');
  assert.ok(authenticatedSources.every((source) => source.endsWith('.js') || source.includes('.js?')));

  const appReference = authenticatedSources.at(-1);
  const versionHash = appReference.match(/-h([a-f0-9]{10})(?:$|&)/)?.[1];
  const contentHash = crypto.createHash('sha256').update(appMainRuntime).digest('hex').slice(0, 10);
  assert.equal(versionHash, contentHash);
});

test('asset failure renderer survives authenticated application load failure without changing success DOM', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  const bootstrap = fs.readFileSync('public/app-bootstrap.js', 'utf8');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  assert.match(html, /window\.SUXI_RENDER_ASSET_LOAD_ERROR\s*=/);
  assert.match(html, /window\.addEventListener\('error',[\s\S]*dataset\?\.suxiCriticalAsset[\s\S]*SUXI_RENDER_ASSET_LOAD_ERROR/);
  assert.match(html, /data-suxi-critical-asset="app-bootstrap\.js"/);
  assert.doesNotMatch(html, /\sonerror=/);
  assert.match(bootstrap, /window\.SUXI_RENDER_ASSET_LOAD_ERROR\?\.\(failedAsset\)/);
  assert.match(bootstrap, /await loadScript\(entry\);/);
  assert.match(appMain, /window\.SUXI_APP_RENDER \|\| window\.SUXI_APP_STARTUP_RENDER/);
  assert.match(appMain, /const suxiActiveRender = shallowRef\(requireSuxiAppRender\(\)\)/);
  assert.match(appMain, /const suxiRenderCaches = new WeakMap\(\)/);
  assert.match(appMain, /suxiActiveRender\.value = fullRender/);
  assert.match(appMain, /suxiApp\?\.unmount\(\)/);
  assert.match(appMain, /window\.SUXI_INITIAL_PAGE_OVERRIDE = targetPage/);
  assert.match(appMain, /suxi:full-render-ready/);
  assert.match(html, /<div id="app" v-cloak>/);
});
