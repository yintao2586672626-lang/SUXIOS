import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {
  captureRuntimeAssetIdentity,
  verifyServedRuntimeAssetIdentity,
} from '../../scripts/lib/runtime_asset_identity.mjs';

const write = (root, relativePath, content) => {
  const target = path.join(root, relativePath);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content);
};

test('runtime identity discovers authenticated and recursively referenced lazy assets', async (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-runtime-assets-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'public/index.html', `
    <link href="shell.css?v=h1" rel="stylesheet">
    <script src="bootstrap.js?v=h2"></script>
    <script type="application/json" id="suxi-authenticated-assets">
      ["main.js?v=h3"]
    </script>
  `);
  write(root, 'public/shell.css', 'body { background: url("images/bg.avif"); } @font-face { font-family: "Fixture"; src: url(webfonts/a.woff2) format("woff2"), url(webfonts/a.ttf) format("truetype"); }\n');
  write(root, 'public/bootstrap.js', 'window.ready = true;\n');
  write(root, 'public/main.js', 'window.lazyAsset = "lazy/feature.js?v=h4";\n');
  write(root, 'public/lazy/feature.js', 'window.feature = true;\n');
  write(root, 'public/images/bg.avif', 'fixture-image');
  write(root, 'public/webfonts/a.woff2', 'fixture-font');

  const first = captureRuntimeAssetIdentity(root);
  assert.deepEqual(first.files.map((file) => file.path), [
    'public/bootstrap.js',
    'public/images/bg.avif',
    'public/index.html',
    'public/lazy/feature.js',
    'public/main.js',
    'public/shell.css',
    'public/webfonts/a.woff2',
  ]);

  const fileByUrl = new Map(first.files.map((file) => [
    file.path === 'public/index.html' ? '/' : `/${file.path.replace(/^public\//, '')}`,
    fs.readFileSync(path.join(root, file.path)),
  ]));
  const fetchFixture = async (url) => {
    const pathname = new URL(url).pathname;
    const content = fileByUrl.get(pathname);
    return new Response(content || 'missing', { status: content ? 200 : 404 });
  };
  assert.deepEqual(
    (await verifyServedRuntimeAssetIdentity('http://127.0.0.1:8080/', first, fetchFixture)).failures,
    [],
  );

  write(root, 'public/lazy/feature.js', 'window.feature = false;\n');
  const second = captureRuntimeAssetIdentity(root);
  assert.notEqual(second.digest, first.digest);
  const mismatch = await verifyServedRuntimeAssetIdentity(
    'http://127.0.0.1:8080/',
    second,
    fetchFixture,
  );
  assert.ok(mismatch.failures.includes('runtime_asset_identity_mismatch:public/lazy/feature.js'));

  fs.unlinkSync(path.join(root, 'public/webfonts/a.woff2'));
  assert.throws(
    () => captureRuntimeAssetIdentity(root),
    /Runtime asset reference group is missing: shell\.css -> webfonts\/a\.woff2, webfonts\/a\.ttf/,
  );
});

test('runtime identity fails when a non-font CSS dependency is missing', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-runtime-assets-missing-css-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'public/index.html', '<link href="shell.css" rel="stylesheet">');
  write(root, 'public/shell.css', 'body { background: url("images/missing-bg.avif"); }');

  assert.throws(
    () => captureRuntimeAssetIdentity(root),
    /Runtime asset reference is missing: shell\.css -> images\/missing-bg\.avif/,
  );
});

test('runtime identity accepts an unavailable optional font subset when the same face has a local source', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-runtime-assets-font-fallback-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'public/index.html', '<link href="fonts.css" rel="stylesheet">');
  write(root, 'public/fonts.css', `
    @font-face { font-family: "Compat"; font-style: normal; font-weight: normal;
      src: url(webfonts/current.woff2) format("woff2"); }
    @font-face { font-family: "Compat"; font-style: normal; font-weight: normal;
      src: url(webfonts/optional-legacy.woff2) format("woff2"); unicode-range: U+F000; }
  `);
  write(root, 'public/webfonts/current.woff2', 'current-font');

  assert.deepEqual(captureRuntimeAssetIdentity(root).files.map((file) => file.path), [
    'public/fonts.css',
    'public/index.html',
    'public/webfonts/current.woff2',
  ]);
});

test('current project runtime identity covers first-paint, deferred, and action-gated assets', () => {
  const repoRoot = path.resolve(import.meta.dirname, '../..');
  const identity = captureRuntimeAssetIdentity(repoRoot);
  const paths = new Set(identity.files.map((file) => file.path));
  for (const required of [
    'public/index.html',
    'public/app-bootstrap.min.js',
    'public/app-startup-helpers.min.js',
    'public/app-deferred-helpers.min.js',
    'public/app-main.min.js',
    'public/components/system/app-main-components.js',
    'public/font-awesome.min.css',
    'public/images/login-hotel-lobby-bg.avif',
    'public/operation-static.js',
    'public/webfonts/fa-solid-900.woff2',
  ]) assert.ok(paths.has(required), `runtime identity must cover ${required}`);
  assert.ok(identity.files.length >= 25);
  assert.match(identity.digest, /^[a-f0-9]{64}$/);
});
