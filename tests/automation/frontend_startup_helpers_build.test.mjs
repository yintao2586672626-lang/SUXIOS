import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import {
  buildFrontendBootstrap,
  buildFrontendDeferredHelpers,
  buildFrontendStartupHelpers,
  FRONTEND_DEFERRED_HELPER_SOURCES,
  FRONTEND_STARTUP_HELPER_SOURCES,
  inspectFrontendStartupHelpers,
  updateFrontendStartupArtifactReferences,
} from '../../scripts/lib/frontend_startup_helpers_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const publicRoot = path.join(repoRoot, 'public');

test('startup artifact promotion replaces canonical runtime references and pins hashes', async () => {
  const bootstrapArtifact = await buildFrontendBootstrap(
    'window.SUXI_BOOTSTRAP_EXAMPLE = function example() { return true; };\n',
  );
  const sourceEntries = FRONTEND_STARTUP_HELPER_SOURCES.map((name, index) => ({
    name,
    source: `window.SUXI_EXAMPLE_${index} = ${index};\n`,
  }));
  const helperArtifact = await buildFrontendStartupHelpers(sourceEntries);
  const deferredSourceEntries = FRONTEND_DEFERRED_HELPER_SOURCES.map((name, index) => ({
    name,
    source: `window.SUXI_DEFERRED_EXAMPLE_${index} = ${index};\n`,
  }));
  const deferredHelperArtifact = await buildFrontendDeferredHelpers(deferredSourceEntries);
  const helperReferences = FRONTEND_STARTUP_HELPER_SOURCES
    .map((name) => `            "${name}?v=20260725-runtime-h0000000000",`)
    .join('\n');
  const html = `<script type="application/json" id="suxi-authenticated-assets">
        [
${helperReferences}
            {
                "src": "app-deferred-helpers.min.js?v=20260731-deferred-domain-h0000000000",
                "phase": "after-first-paint"
            },
            "app-main.min.js?v=20260725-runtime-h0000000000"
        ]
    </script>
    <script defer src="app-bootstrap.js?v=20260725-runtime-h0000000000"></script>`;
  const updated = updateFrontendStartupArtifactReferences(
    html,
    bootstrapArtifact,
    helperArtifact,
    deferredHelperArtifact,
  );

  assert.match(updated, /app-bootstrap\.min\.js\?v=20260725-runtime-h[a-f0-9]{10}/);
  assert.match(updated, /app-startup-helpers\.min\.js\?v=20260725-startup-bundle-h[a-f0-9]{10}/);
  assert.match(updated, /app-deferred-helpers\.min\.js\?v=20260731-deferred-domain-h[a-f0-9]{10}/);
  for (const source of FRONTEND_STARTUP_HELPER_SOURCES) {
    assert.doesNotMatch(updated, new RegExp(`${source.replaceAll('.', '\\.')}\\?v=`));
  }
});

test('startup artifacts are deterministic, current, smaller, and preserve exported helper APIs', async () => {
  const inspection = await inspectFrontendStartupHelpers(repoRoot);
  assert.deepEqual(inspection.failures, []);
  assert.ok(inspection.metrics.gzip_savings_bytes >= 50_000);
  assert.equal(inspection.metrics.request_savings, 7);

  const sourceEntries = FRONTEND_STARTUP_HELPER_SOURCES.map((name) => ({
    name,
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const artifact = fs.readFileSync(
    path.join(publicRoot, 'app-startup-helpers.min.js'),
    'utf8',
  );
  const deferredSourceEntries = FRONTEND_DEFERRED_HELPER_SOURCES.map((name) => ({
    name,
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const deferredArtifact = fs.readFileSync(
    path.join(publicRoot, 'app-deferred-helpers.min.js'),
    'utf8',
  );
  assert.equal(artifact, await buildFrontendStartupHelpers(sourceEntries));
  assert.equal(
    deferredArtifact,
    await buildFrontendDeferredHelpers(deferredSourceEntries),
  );

  const createSandbox = () => ({
    window: {
      Vue: {
        ref: (value) => ({ value }),
        computed: (factory) => ({ get value() { return factory(); } }),
        h: (...args) => ({ args }),
      },
    },
    console,
    URL,
    URLSearchParams,
    Intl,
    setTimeout,
    clearTimeout,
  });
  const sourceSandbox = createSandbox();
  const artifactSandbox = createSandbox();
  vm.runInNewContext(
    [...sourceEntries, ...deferredSourceEntries].map((item) => item.source).join('\n;\n'),
    sourceSandbox,
    { filename: 'canonical-startup-helpers.js' },
  );
  vm.runInNewContext(artifact, artifactSandbox, {
    filename: 'public/app-startup-helpers.min.js',
  });
  vm.runInNewContext(deferredArtifact, artifactSandbox, {
    filename: 'public/app-deferred-helpers.min.js',
  });

  for (const exportName of [
    'SUXI_SHARED_COMPONENTS',
    'SUXI_CTRIP_STATIC',
    'SUXI_MEITUAN_STATIC',
    'SUXI_SYSTEM_STATIC',
    'SUXI_COMPASS_STATIC',
    'SUXI_HOME_STATIC',
    'SUXI_DUAL_OTA_HOME',
    'SUXI_DATA_HEALTH_STATIC',
    'SUXI_MEITUAN_FUTURE_FLOW',
  ]) {
    assert.deepEqual(
      Object.keys(artifactSandbox.window[exportName] || {}).sort(),
      Object.keys(sourceSandbox.window[exportName] || {}).sort(),
      `${exportName} exports must be preserved by the startup bundle`,
    );
  }

  const appMain = fs.readFileSync(path.join(publicRoot, 'app-main.js'), 'utf8');
  const requiredDataHealthFunctions = new Set([
    ...appMain.matchAll(/requireDataHealthStatic\('([^']+)'\)/g),
    ...appMain.matchAll(/requirePlatformBatchHealthStatic\('([^']+)'\)/g),
  ].map((match) => match[1]));
  assert.equal(
    artifactSandbox.window.SUXI_DATA_HEALTH_STATIC?.contractVersion,
    '20260811-full-render-v1',
  );
  for (const key of requiredDataHealthFunctions) {
    assert.equal(
      typeof artifactSandbox.window.SUXI_DATA_HEALTH_STATIC?.[key],
      'function',
      `SUXI_DATA_HEALTH_STATIC must provide app-main helper ${key}`,
    );
  }

  const systemStatic = artifactSandbox.window.SUXI_SYSTEM_STATIC;
  const deferredCall = systemStatic.requireDeferredStaticFunction(
    'SUXI_DEFERRED_EXAMPLE',
    'read',
    'missing deferred example',
  );
  assert.throws(() => deferredCall(), /missing deferred example/);
  artifactSandbox.window.SUXI_DEFERRED_EXAMPLE = { read: value => `ready:${value}` };
  assert.equal(deferredCall('ok'), 'ready:ok');

  let factoryCalls = 0;
  const lazyMethods = systemStatic.createLazyFactoryMethods(() => {
    factoryCalls += 1;
    return { read: value => `lazy:${value}` };
  }, ['read']);
  assert.equal(factoryCalls, 0);
  assert.equal(lazyMethods.read('ok'), 'lazy:ok');
  assert.equal(lazyMethods.read('again'), 'lazy:again');
  assert.equal(factoryCalls, 1);
});
