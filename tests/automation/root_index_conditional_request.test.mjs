import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

test('root index gives If-None-Match precedence over If-Modified-Since', () => {
  const router = fs.readFileSync('route/app.php', 'utf8');

  assert.match(
    router,
    /\$notModified = \$ifNoneMatch !== ''\s*\? \$etagMatches\s*:\s*\(\$ifModifiedSince !== '' && strtotime\(\$ifModifiedSince\) >= \$mtime\);/,
  );
  assert.match(router, /if \(\$notModified\) \{\s*return response\('', 304, \$headers\);/);
  assert.doesNotMatch(
    router,
    /if \(\$etagMatches \|\| \(\$ifModifiedSince !== '' && strtotime\(\$ifModifiedSince\) >= \$mtime\)\)/,
  );
});
