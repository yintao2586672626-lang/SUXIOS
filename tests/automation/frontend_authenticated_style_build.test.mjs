import assert from 'node:assert/strict';
import test from 'node:test';
import postcss from 'postcss';
import {
  FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS,
  buildFrontendAuthenticatedStartupStyle,
  buildFrontendAuthenticatedStyle,
} from '../../scripts/lib/frontend_authenticated_style_build.mjs';

test('authenticated startup stylesheet scans first-paint sources only', () => {
  assert.deepEqual(FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS, [
    'index.html',
    'app-main.js',
    'compass-static.js',
    'home-static.js',
    'dual-ota-home-static.js',
  ]);
  assert.ok(!FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS.includes('system-static.js'));
  assert.ok(!FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS.includes('ctrip-static.js'));
});

test('authenticated stylesheet build removes only non-license comments and formatting whitespace', () => {
  const source = `
    /*! keep license */
    /* remove build comment */
    .card {
      color: rgb(10, 20, 30);
      background: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=");
    }
    @media (max-width: 640px) {
      .card { color: red; }
    }
  `;
  const artifact = buildFrontendAuthenticatedStyle(source);
  const root = postcss.parse(artifact);

  assert.match(artifact, /\/\*! keep license \*\//);
  assert.doesNotMatch(artifact, /remove build comment/);
  assert.match(artifact, /PHN2Zz48L3N2Zz4=/);
  assert.equal(root.nodes.filter((node) => node.type === 'rule').length, 1);
  assert.equal(root.nodes.filter((node) => node.type === 'atrule').length, 1);
  assert.ok(artifact.length < source.length);
  assert.ok(artifact.endsWith('\n'));
  assert.ok(!artifact.endsWith('\n\n'));
});

test('authenticated startup stylesheet keeps used selectors and removes deferred-page selectors', async () => {
  const source = `
    .compass-dashboard { color: rgb(10, 20, 30); }
    .deferred-admin-page { color: red; }
    @media (max-width: 640px) { .compass-dashboard { color: blue; } }
  `;
  const artifact = await buildFrontendAuthenticatedStartupStyle(source, [{
    raw: '<main class="compass-dashboard"></main>',
    extension: 'html',
  }]);

  assert.match(artifact, /\.compass-dashboard/);
  assert.doesNotMatch(artifact, /\.deferred-admin-page/);
  assert.match(artifact, /@media/);
  assert.ok(artifact.endsWith('\n'));
});
