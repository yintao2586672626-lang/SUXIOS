import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import test from 'node:test';
import {
  buildFrontendAssetHash,
  readFrontendAssetVersion,
  replaceFrontendAssetVersionHash,
  updateFrontendAssetVersion,
} from '../../scripts/lib/frontend_asset_version.mjs';

test('frontend asset hash uses the exact final bytes and 10 lowercase SHA-256 characters', () => {
  const content = Buffer.from('artifact with trailing newline\n', 'utf8');
  const expected = crypto.createHash('sha256').update(content).digest('hex').slice(0, 10);
  assert.equal(buildFrontendAssetHash(content), expected);
  assert.match(expected, /^[a-f0-9]{10}$/);
});

test('version updater preserves a traditional tag prefix and surrounding attributes', () => {
  const html = '<script defer src="app-main.min.js?v=20260716-login-flow-h0000000000" onerror="failed()"></script>';
  const result = replaceFrontendAssetVersionHash(html, 'app-main.min.js', '123456789a');

  assert.equal(
    result.html,
    '<script defer src="app-main.min.js?v=20260716-login-flow-h123456789a" onerror="failed()"></script>',
  );
  assert.equal(result.changed, true);
  assert.equal(readFrontendAssetVersion(result.html, 'app-main.min.js').versionPrefix, '20260716-login-flow');
});

test('version updater supports the authenticated JSON asset manifest and is idempotent', () => {
  const html = `
    <script type="application/json" id="suxi-authenticated-assets">
      ["app-render.min.js?v=release-h0000000000"]
    </script>
  `;
  const first = updateFrontendAssetVersion(html, 'app-render.min.js', 'render artifact\n');
  const second = updateFrontendAssetVersion(first.html, 'app-render.min.js', 'render artifact\n');

  assert.match(first.html, /app-render\.min\.js\?v=release-h[a-f0-9]{10}/);
  assert.equal(second.html, first.html);
  assert.equal(second.changed, false);
});

test('version updater fails closed when the reference is missing or duplicated', () => {
  assert.throws(
    () => replaceFrontendAssetVersionHash('<html></html>', 'app-main.min.js', '123456789a'),
    /exactly once.*found 0/,
  );
  assert.throws(
    () => replaceFrontendAssetVersionHash(`
      <script defer src="app-main.min.js?v=release-h0000000000"></script>
      <script type="application/json">["app-main.min.js?v=release-h0000000000"]</script>
    `, 'app-main.min.js', '123456789a'),
    /exactly once.*found 2/,
  );
});

test('version updater rejects malformed versions and hashes', () => {
  assert.throws(
    () => replaceFrontendAssetVersionHash(
      '<script defer src="app-main.min.js?v=release-hABCDEF1234"></script>',
      'app-main.min.js',
      '123456789a',
    ),
    /exactly 10 lowercase/,
  );
  assert.throws(
    () => replaceFrontendAssetVersionHash(
      '<script defer src="app-main.min.js?v=release-h0000000000"></script>',
      'app-main.min.js',
      'short',
    ),
    /exactly 10 lowercase hexadecimal/,
  );
});
