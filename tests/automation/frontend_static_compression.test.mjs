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

test('static router completes conditional cache hits before payload or gzip work', () => {
  const router = fs.readFileSync('public/router.php', 'utf8');
  const conditionalOffset = router.indexOf('$notModified = $ifNoneMatch');
  const payloadOffset = router.indexOf('$responsePayload = suxi_static_response_payload');

  assert.match(router, /\$ifNoneMatch = trim\(\(string\)\(\$_SERVER\['HTTP_IF_NONE_MATCH'\]/);
  assert.match(router, /\$ifModifiedSince = trim\(\(string\)\(\$_SERVER\['HTTP_IF_MODIFIED_SINCE'\]/);
  assert.match(router, /\$notModified = \$ifNoneMatch !== ''\s*\? \$ifNoneMatch === \$etag\s*:\s*\(\$ifModifiedSince !== '' && strtotime\(\$ifModifiedSince\) >= \$mtime\);/);
  assert.match(router, /if \(\$notModified\) \{\s*http_response_code\(304\);\s*return true;/);
  assert.doesNotMatch(router, /\$ifNoneMatch === \$etag \|\|/);
  assert.ok(conditionalOffset >= 0 && payloadOffset > conditionalOffset);
});

test('immutable caching is limited to content-hashed asset versions', () => {
  const router = fs.readFileSync('public/router.php', 'utf8');
  const htaccess = fs.readFileSync('public/.htaccess', 'utf8');

  assert.match(router, /function suxi_static_request_has_content_hash/);
  assert.match(router, /Cache-Control: public, max-age=2592000, immutable/);
  assert.match(router, /Cache-Control: public, max-age=300, must-revalidate/);
  assert.match(router, /\$contentHashedRequest\s*\?/);

  assert.match(htaccess, /SetEnvIfExpr[^\n]+SUXI_CONTENT_HASHED=1/);
  assert.match(htaccess, /immutable" env=SUXI_CONTENT_HASHED/);
  assert.match(htaccess, /must-revalidate" env=!SUXI_CONTENT_HASHED/);
  assert.doesNotMatch(htaccess, /Header set Cache-Control "public, max-age=2592000, immutable"\s*$/m);
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
