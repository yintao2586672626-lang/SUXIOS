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

test('Ctrip keeps only its startup facade on the authenticated first paint', () => {
  assert.ok(FRONTEND_STARTUP_HELPER_SOURCES.includes('ctrip-static-loader.js'));
  assert.ok(!FRONTEND_STARTUP_HELPER_SOURCES.includes('ctrip-static.js'));
  assert.equal(FRONTEND_DEFERRED_HELPER_SOURCES[0], 'ctrip-static.js');

  const createCtripSandbox = () => ({
    window: {},
    console,
    URL,
    URLSearchParams,
    Intl,
    Date,
    setTimeout,
    clearTimeout,
  });
  const loaderSandbox = createCtripSandbox();
  const fullSandbox = createCtripSandbox();
  const loaderSource = fs.readFileSync(path.join(publicRoot, 'ctrip-static-loader.js'), 'utf8');
  const fullSource = fs.readFileSync(path.join(publicRoot, 'ctrip-static.js'), 'utf8');
  vm.runInNewContext(loaderSource, loaderSandbox, { filename: 'public/ctrip-static-loader.js' });
  vm.runInNewContext(fullSource, fullSandbox, { filename: 'public/ctrip-static.js' });

  const facade = loaderSandbox.window.SUXI_CTRIP_STATIC;
  const full = fullSandbox.window.SUXI_CTRIP_STATIC;
  assert.deepEqual(Object.keys(facade).sort(), Object.keys(full).sort());
  assert.equal(facade.createCtripFetchForm().nodeId, '24588');
  assert.equal(facade.buildLatestCtripSnapshotModel({ rank: { rows: [{}] } }).hasRank, true);
  assert.throws(
    () => facade.runCtripFetchDataFlow(),
    /携程完整静态能力尚未加载：runCtripFetchDataFlow/,
  );

  vm.runInNewContext(fullSource, loaderSandbox, { filename: 'public/ctrip-static.js' });
  assert.equal(
    loaderSandbox.window.SUXI_CTRIP_STATIC,
    loaderSandbox.window.SUXI_CTRIP_STATIC_FULL,
    'the deferred full module must replace the startup facade before full-page remount',
  );
  assert.notEqual(loaderSandbox.window.SUXI_CTRIP_STATIC, facade);
});

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
  assert.ok(
    FRONTEND_STARTUP_HELPER_SOURCES.includes('ota-profile-static.js'),
    'OTA Profile login helpers must load before app-main initializes authenticated state',
  );
  assert.ok(
    !FRONTEND_DEFERRED_HELPER_SOURCES.includes('ota-profile-static.js'),
    'OTA Profile login helpers cannot race app-main in the deferred phase',
  );
  assert.ok(inspection.metrics.gzip_savings_bytes >= 50_000);
  assert.equal(
    inspection.metrics.request_savings,
    FRONTEND_STARTUP_HELPER_SOURCES.length + FRONTEND_DEFERRED_HELPER_SOURCES.length - 2,
  );

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
  const startupOnlySandbox = createSandbox();
  const artifactSandbox = createSandbox();
  vm.runInNewContext(
    [...sourceEntries, ...deferredSourceEntries].map((item) => item.source).join('\n;\n'),
    sourceSandbox,
    { filename: 'canonical-startup-helpers.js' },
  );
  vm.runInNewContext(artifact, artifactSandbox, {
    filename: 'public/app-startup-helpers.min.js',
  });
  vm.runInNewContext(artifact, startupOnlySandbox, {
    filename: 'public/app-startup-helpers.min.js',
  });
  assert.equal(
    typeof startupOnlySandbox.window.SUXI_OTA?.preferredBrowserProfileDataSource,
    'function',
    'the startup artifact must expose OTA Profile selection before deferred assets load',
  );
  vm.runInNewContext(deferredArtifact, artifactSandbox, {
    filename: 'public/app-deferred-helpers.min.js',
  });

  for (const exportName of [
    'SUXI_SHARED_COMPONENTS',
    'SUXI_CTRIP_STATIC',
    'SUXI_MEITUAN_STATIC',
    'SUXI_OTA',
    'SUXI_SYSTEM_STATIC',
    'SUXI_COMPASS_STATIC',
    'SUXI_HOME_STATIC',
    'SUXI_DUAL_OTA_HOME',
    'SUXI_DATA_HEALTH_STATIC',
    'SUXI_AI_DAILY_REPORT_STATIC',
    'SUXI_MEITUAN_FUTURE_FLOW',
  ]) {
    assert.deepEqual(
      Object.keys(artifactSandbox.window[exportName] || {}).sort(),
      Object.keys(sourceSandbox.window[exportName] || {}).sort(),
      `${exportName} exports must be preserved by the startup bundle`,
    );
  }

  const aiDailyReportStatic = artifactSandbox.window.SUXI_AI_DAILY_REPORT_STATIC;
  const competitionExportInput = {
    reportId: 23,
    readbackReceipt: { status: 'exact_readback_verified', exact_readback_verified: true },
    requestedEdition: 'flagship',
    fallbackReportDate: '2026-08-16',
    qualityText: '可信',
    editionText: '旗舰版',
    bundle: { bundle_id: 'bundle_abcdefghijklmnop', source_fingerprint: 'fingerprint-a' },
    report: {
      schema_version: 1,
      title: '<可信报告>',
      render_contract: {
        bundle_id: 'bundle_abcdefghijklmnop',
        source_fingerprint: 'fingerprint-a',
      },
      actions: JSON.stringify([null, { title: '<复核>', action: '人工确认' }]),
      data_gaps: JSON.stringify([null, 'legacy gap']),
    },
    platforms: [],
    groups: [],
  };
  const competitionExport = aiDailyReportStatic.buildAiDailyCompetitionReportExport(
    competitionExportInput,
  );
  assert.equal(competitionExport.ok, true);
  assert.equal(
    competitionExport.filename,
    'suxios-ota-competition-flagship-2026-08-16-r23-efghijklmnop.html',
  );
  assert.match(competitionExport.html, /&lt;可信报告&gt;/);
  assert.match(competitionExport.html, /legacy gap/);
  assert.equal(
    aiDailyReportStatic.buildAiDailyCompetitionReportExport({
      ...competitionExportInput,
      bundle: { ...competitionExportInput.bundle, source_fingerprint: 'fingerprint-b' },
    }).code,
    'competition_report_identity_mismatch',
  );

  const sourceAiDailyReportStatic = sourceSandbox.window.SUXI_AI_DAILY_REPORT_STATIC;
  const metricTruthInput = {
    metric: {
      key: 'revenue',
      value: 1888,
      metric_scope: 'ota_channel',
      source_ref: 'online_daily_data#41',
    },
    report: {
      hotel_id: 80,
      report_date: '2026-08-15',
      source_refs: [{
        ref: 'online_daily_data#41',
        source: 'online_daily_data',
        platform: 'ctrip',
        metric_keys: ['revenue'],
        data_date: '2026-08-15',
        quality_status: 'normal',
        persistence_status: 'stored',
        readback_verified: true,
      }],
    },
    permittedHotels: [{ id: 80, name: '宿析测试酒店' }],
  };
  const shareInput = {
    audience: 'training',
    report: { report_date: '2026-08-15', summary: '脱敏摘要' },
    contract: { result_version: 'caseversion123456789', contract_version: 'v1' },
    resultLayers: {
      source_facts: '[{"key":"revenue","value":1888,"source_ref":"online_daily_data#41"}]',
      derived_metrics: '[{"key":"adr","value":320}]',
    },
    humanJudgments: [{ decision: 'accepted', actor_user_id: 9 }],
  };
  const normalizeVmValue = value => JSON.parse(JSON.stringify(value));
  assert.deepEqual(
    normalizeVmValue(aiDailyReportStatic.list('{"first":"a","second":"b"}')),
    normalizeVmValue(sourceAiDailyReportStatic.list('{"first":"a","second":"b"}')),
  );
  assert.deepEqual(
    normalizeVmValue(aiDailyReportStatic.buildMetricTruth(metricTruthInput)),
    normalizeVmValue(sourceAiDailyReportStatic.buildMetricTruth(metricTruthInput)),
  );
  assert.deepEqual(
    normalizeVmValue(aiDailyReportStatic.buildSharePackage(shareInput)),
    normalizeVmValue(sourceAiDailyReportStatic.buildSharePackage(shareInput)),
  );
  const trainingShare = aiDailyReportStatic.buildSharePackage(shareInput);
  assert.equal(trainingShare.source_facts[0].result_layer, 'source_fact');
  assert.equal(trainingShare.derived_metrics[0].result_layer, 'derived_metric');

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
