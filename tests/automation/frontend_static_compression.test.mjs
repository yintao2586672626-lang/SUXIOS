import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { gunzipSync, gzipSync } from 'node:zlib';

test('static router caches level-6 gzip output under a level-specific identity', () => {
  const router = fs.readFileSync('public/router.php', 'utf8');

  assert.match(router, /const SUXI_STATIC_GZIP_LEVEL = 6;/);
  assert.match(router, /gzencode\(\$responseContent \?\? '', SUXI_STATIC_GZIP_LEVEL\)/);
  assert.match(router, /'-gzip-l' \. SUXI_STATIC_GZIP_LEVEL \. '\.gz'/);
  assert.doesNotMatch(router, /gzencode\(\$responseContent \?\? '', 1\)/);
});

test('level-6 gzip materially reduces current entry bytes without changing payloads', () => {
  const assets = [
    'public/index.html',
    'public/tailwind.min.css',
    'public/vue.runtime.global.prod.js',
    'public/app-render.min.js',
    'public/app-main.min.js',
  ].map((file) => fs.readFileSync(file));
  const level1Bytes = assets.reduce((total, source) => total + gzipSync(source, { level: 1 }).length, 0);
  const level6Bytes = assets.reduce((total, source) => total + gzipSync(source, { level: 6 }).length, 0);

  assert.ok(level6Bytes < level1Bytes * 0.87);
  for (const source of assets) {
    assert.deepEqual(gunzipSync(gzipSync(source, { level: 6 })), source);
  }
});
