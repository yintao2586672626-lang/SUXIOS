import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';

const indexPath = 'public/index.html';
const appMainPath = 'public/app-main.js';
const appMainRuntimePath = 'public/app-main.min.js';

test('main Vue bootstrap is external, deferred, ordered, and content-versioned', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  assert.equal(fs.existsSync(appMainPath), true, 'public/app-main.js must exist');
  assert.equal(fs.existsSync(appMainRuntimePath), true, 'public/app-main.min.js must exist');
  const appMain = fs.readFileSync(appMainPath, 'utf8');
  const appMainRuntime = fs.readFileSync(appMainRuntimePath, 'utf8');
  assert.doesNotMatch(html, /const\s+suxiApp\s*=\s*createApp\(/);
  assert.match(appMain, /const\s+suxiApp\s*=\s*createApp\(/);

  const deferredSources = [...html.matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1]);
  assert.equal(deferredSources[0]?.split('?')[0], 'vue.global.prod.js');
  assert.equal(deferredSources.at(-1)?.split('?')[0], 'app-main.min.js');
  assert.ok(deferredSources.every((source) => source.endsWith('.js') || source.includes('.js?')));

  const appReference = deferredSources.at(-1);
  const versionHash = appReference.match(/-h([a-f0-9]{10})(?:$|&)/)?.[1];
  const contentHash = crypto.createHash('sha256').update(appMainRuntime).digest('hex').slice(0, 10);
  assert.equal(versionHash, contentHash);
});

test('asset failure renderer survives app-main load failure without changing success DOM', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  assert.match(html, /window\.SUXI_RENDER_ASSET_LOAD_ERROR\s*=/);
  assert.match(html, /onerror="window\.SUXI_RENDER_ASSET_LOAD_ERROR\('app-main\.min\.js'\)"/);
  assert.match(html, /<div id="app" v-cloak>/);
});
