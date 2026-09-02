import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { gzipSync } from 'node:zlib';
import { minify } from 'terser';
import {
  buildFrontendAssetHash,
  readFrontendAssetVersion,
  updateFrontendAssetVersion,
} from './frontend_asset_version.mjs';
import { FRONTEND_ENTRY_MINIFY_OPTIONS } from './frontend_entry_build.mjs';
import { captureRuntimeAssetIdentity } from './runtime_asset_identity.mjs';

export const FRONTEND_BOOTSTRAP_SOURCE = 'app-bootstrap.js';
export const FRONTEND_BOOTSTRAP_ARTIFACT = 'app-bootstrap.min.js';
export const FRONTEND_STARTUP_HELPER_ARTIFACT = 'app-startup-helpers.min.js';
export const FRONTEND_DEFERRED_HELPER_ARTIFACT = 'app-deferred-helpers.min.js';
export const FRONTEND_STARTUP_HELPER_SOURCES = Object.freeze([
  'shared-components.js',
  'ctrip-static-loader.js',
  'system-static.js',
  'compass-static.js',
  'home-static.js',
  'dual-ota-home-static.js',
  'ota-profile-static.js',
  'components/system/dual-ota-field-closure-loader.js',
  'components/system/app-main-components-loader.js',
  'components/system/operating-intelligence-loader.js',
]);
export const FRONTEND_DEFERRED_HELPER_SOURCES = Object.freeze([
  'ctrip-static.js',
  'hotel-three-source-onboarding-static.js',
  'manual-notification-orchestration-static.js',
  'revenue-overview-contract-static.js',
  'meituan-static.js',
  'review-match-static.js',
  'data-health-static.js',
  'platform-profile-login-static.js',
  'competition-download-static.js',
  'ai-daily-report-static.js',
  'components/meituan-future-flow.js',
]);

async function minifyFrontendScripts(sources) {
  const result = await minify(sources, structuredClone(FRONTEND_ENTRY_MINIFY_OPTIONS));
  if (!result.code) throw new Error('Terser returned an empty frontend startup artifact.');
  return `${result.code}\n`;
}

export async function buildFrontendBootstrap(source) {
  return minifyFrontendScripts({ [FRONTEND_BOOTSTRAP_SOURCE]: String(source || '') });
}

export async function buildFrontendStartupHelpers(sourceEntries) {
  const sources = Object.fromEntries(sourceEntries.map(({ name, source }) => [
    name,
    String(source || ''),
  ]));
  return minifyFrontendScripts(sources);
}

export async function buildFrontendDeferredHelpers(sourceEntries) {
  const sources = Object.fromEntries(sourceEntries.map(({ name, source }) => [
    name,
    String(source || ''),
  ]));
  return minifyFrontendScripts(sources);
}

function createFrontendContractSandbox() {
  return {
    window: {},
    console,
    URL,
    URLSearchParams,
    Intl,
    Date,
    setTimeout,
    clearTimeout,
  };
}

export function inspectCtripStartupFacadeContract(loaderSource, fullSource) {
  const failures = [];
  const loaderSandbox = createFrontendContractSandbox();
  const fullSandbox = createFrontendContractSandbox();
  try {
    vm.runInNewContext(String(loaderSource || ''), loaderSandbox, {
      filename: 'public/ctrip-static-loader.js',
    });
    vm.runInNewContext(String(fullSource || ''), fullSandbox, {
      filename: 'public/ctrip-static.js',
    });
  } catch (error) {
    return {
      failures: [`Ctrip startup facade inspection failed: ${error.message}`],
      metrics: {
        facade_export_count: 0,
        full_export_count: 0,
        missing_exports: [],
        unexpected_exports: [],
        type_mismatches: [],
      },
    };
  }

  const facade = loaderSandbox.window.SUXI_CTRIP_STATIC;
  const full = fullSandbox.window.SUXI_CTRIP_STATIC;
  if (!facade || typeof facade !== 'object') {
    failures.push('public/ctrip-static-loader.js must expose window.SUXI_CTRIP_STATIC.');
  }
  if (!full || typeof full !== 'object') {
    failures.push('public/ctrip-static.js must expose window.SUXI_CTRIP_STATIC.');
  }
  const facadeKeys = Object.keys(facade || {}).sort();
  const fullKeys = Object.keys(full || {}).sort();
  const facadeKeySet = new Set(facadeKeys);
  const fullKeySet = new Set(fullKeys);
  const missingExports = fullKeys.filter((key) => !facadeKeySet.has(key));
  const unexpectedExports = facadeKeys.filter((key) => !fullKeySet.has(key));
  const typeMismatches = fullKeys.filter(
    (key) => facadeKeySet.has(key) && typeof facade[key] !== typeof full[key],
  );
  if (missingExports.length > 0 || unexpectedExports.length > 0) {
    failures.push(
      'Ctrip startup facade exports must exactly match the deferred full module; '
      + `missing=${missingExports.join(',') || 'none'}; `
      + `unexpected=${unexpectedExports.join(',') || 'none'}.`,
    );
  }
  if (typeMismatches.length > 0) {
    failures.push(
      'Ctrip startup facade export types must match the deferred full module; '
      + `mismatched=${typeMismatches.join(',')}.`,
    );
  }

  if (facade && full) {
    try {
      vm.runInNewContext(String(fullSource || ''), loaderSandbox, {
        filename: 'public/ctrip-static.js',
      });
      if (loaderSandbox.window.SUXI_CTRIP_STATIC !== loaderSandbox.window.SUXI_CTRIP_STATIC_FULL
        || loaderSandbox.window.SUXI_CTRIP_STATIC === facade) {
        failures.push('The deferred Ctrip module must replace the startup facade with its full export object.');
      }
    } catch (error) {
      failures.push(`Deferred Ctrip facade replacement inspection failed: ${error.message}`);
    }
  }

  return {
    failures,
    metrics: {
      facade_export_count: facadeKeys.length,
      full_export_count: fullKeys.length,
      missing_exports: missingExports,
      unexpected_exports: unexpectedExports,
      type_mismatches: typeMismatches,
    },
  };
}

const escapeRegExp = (value) => String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const referenceCount = (html, assetName) => (
  String(html || '').match(
    new RegExp(`(?:^|["'\\s=])${escapeRegExp(assetName)}\\?v=`, 'gm'),
  ) || []
).length;

function promoteSingleAssetReference(html, sourceName, artifactName, artifact) {
  let nextHtml = String(html || '');
  const sourceCount = referenceCount(nextHtml, sourceName);
  const artifactCount = referenceCount(nextHtml, artifactName);

  if (sourceCount === 1 && artifactCount === 0) {
    const current = readFrontendAssetVersion(nextHtml, sourceName);
    nextHtml = nextHtml.replace(
      `${sourceName}?v=${current.version}`,
      `${artifactName}?v=${current.version}`,
    );
  } else if (sourceCount !== 0 || artifactCount !== 1) {
    throw new Error(
      `Frontend entry must reference exactly one of ${sourceName} or ${artifactName}; `
      + `found source=${sourceCount}, artifact=${artifactCount}.`,
    );
  }

  return updateFrontendAssetVersion(nextHtml, artifactName, artifact).html;
}

export function updateFrontendStartupArtifactReferences(
  html,
  bootstrapArtifact,
  startupHelperArtifact,
  deferredHelperArtifact,
) {
  let nextHtml = promoteSingleAssetReference(
    html,
    FRONTEND_BOOTSTRAP_SOURCE,
    FRONTEND_BOOTSTRAP_ARTIFACT,
    bootstrapArtifact,
  );

  const firstSource = FRONTEND_STARTUP_HELPER_SOURCES[0];
  const bundleCount = referenceCount(nextHtml, FRONTEND_STARTUP_HELPER_ARTIFACT);
  const sourceCounts = FRONTEND_STARTUP_HELPER_SOURCES.map((source) => referenceCount(nextHtml, source));

  if (bundleCount === 0 && sourceCounts.every((count) => count === 1)) {
    const firstReferencePattern = new RegExp(
      `${escapeRegExp(firstSource)}\\?v=[^"'<>\\s]+`,
    );
    nextHtml = nextHtml.replace(
      firstReferencePattern,
      `${FRONTEND_STARTUP_HELPER_ARTIFACT}?v=20260725-startup-bundle-h0000000000`,
    );
    for (const source of FRONTEND_STARTUP_HELPER_SOURCES.slice(1)) {
      const sourceLine = new RegExp(
        `^[\\t ]*"${escapeRegExp(source)}\\?v=[^"]+",?\\r?\\n`,
        'm',
      );
      if (!sourceLine.test(nextHtml)) {
        throw new Error(
          `Frontend startup source ${source} must remain a standalone JSON string before bundling.`,
        );
      }
      nextHtml = nextHtml.replace(sourceLine, '');
    }
  } else if (bundleCount !== 1 || sourceCounts.some((count) => count !== 0)) {
    throw new Error(
      `Frontend entry must reference ${FRONTEND_STARTUP_HELPER_ARTIFACT} once `
      + 'or every canonical startup helper once before promotion.',
    );
  }

  nextHtml = updateFrontendAssetVersion(
    nextHtml,
    FRONTEND_STARTUP_HELPER_ARTIFACT,
    startupHelperArtifact,
  ).html;

  const deferredBundleCount = referenceCount(nextHtml, FRONTEND_DEFERRED_HELPER_ARTIFACT);
  const deferredSourceCounts = FRONTEND_DEFERRED_HELPER_SOURCES.map(
    (source) => referenceCount(nextHtml, source),
  );
  if (deferredBundleCount !== 1 || deferredSourceCounts.some((count) => count !== 0)) {
    throw new Error(
      `Frontend entry must reference ${FRONTEND_DEFERRED_HELPER_ARTIFACT} once `
      + 'and must not load its canonical deferred sources directly.',
    );
  }

  return updateFrontendAssetVersion(
    nextHtml,
    FRONTEND_DEFERRED_HELPER_ARTIFACT,
    deferredHelperArtifact,
  ).html;
}

export async function inspectFrontendStartupHelpers(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const html = fs.readFileSync(path.join(publicRoot, 'index.html'), 'utf8');
  const bootstrapSource = fs.readFileSync(path.join(publicRoot, FRONTEND_BOOTSTRAP_SOURCE), 'utf8');
  const helperSources = FRONTEND_STARTUP_HELPER_SOURCES.map((name) => ({
    name,
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const deferredHelperSources = FRONTEND_DEFERRED_HELPER_SOURCES.map((name) => ({
    name,
    source: fs.readFileSync(path.join(publicRoot, name), 'utf8'),
  }));
  const bootstrapArtifactPath = path.join(publicRoot, FRONTEND_BOOTSTRAP_ARTIFACT);
  const helperArtifactPath = path.join(publicRoot, FRONTEND_STARTUP_HELPER_ARTIFACT);
  const deferredHelperArtifactPath = path.join(publicRoot, FRONTEND_DEFERRED_HELPER_ARTIFACT);
  const bootstrapArtifact = fs.existsSync(bootstrapArtifactPath)
    ? fs.readFileSync(bootstrapArtifactPath, 'utf8')
    : '';
  const helperArtifact = fs.existsSync(helperArtifactPath)
    ? fs.readFileSync(helperArtifactPath, 'utf8')
    : '';
  const deferredHelperArtifact = fs.existsSync(deferredHelperArtifactPath)
    ? fs.readFileSync(deferredHelperArtifactPath, 'utf8')
    : '';
  const expectedBootstrapArtifact = await buildFrontendBootstrap(bootstrapSource);
  const expectedHelperArtifact = await buildFrontendStartupHelpers(helperSources);
  const expectedDeferredHelperArtifact = await buildFrontendDeferredHelpers(deferredHelperSources);
  const failures = [];
  const ctripFacadeInspection = inspectCtripStartupFacadeContract(
    helperSources.find(({ name }) => name === 'ctrip-static-loader.js')?.source || '',
    deferredHelperSources.find(({ name }) => name === 'ctrip-static.js')?.source || '',
  );
  failures.push(...ctripFacadeInspection.failures);
  const runtimeAssetIdentity = captureRuntimeAssetIdentity(repoRoot);
  if (runtimeAssetIdentity.files.length < 20
    || !/^[a-f0-9]{64}$/.test(runtimeAssetIdentity.digest)) {
    failures.push('Runtime asset identity must cover the authenticated and action-gated frontend graph.');
  }

  if (bootstrapArtifact !== expectedBootstrapArtifact) {
    failures.push(
      `public/${FRONTEND_BOOTSTRAP_ARTIFACT} is stale or was not generated with the pinned build contract.`,
    );
  }
  if (helperArtifact !== expectedHelperArtifact) {
    failures.push(
      `public/${FRONTEND_STARTUP_HELPER_ARTIFACT} is stale or was not generated with the pinned build contract.`,
    );
  }
  if (deferredHelperArtifact !== expectedDeferredHelperArtifact) {
    failures.push(
      `public/${FRONTEND_DEFERRED_HELPER_ARTIFACT} is stale or was not generated with the pinned build contract.`,
    );
  }

  for (const source of [
    FRONTEND_BOOTSTRAP_SOURCE,
    ...FRONTEND_STARTUP_HELPER_SOURCES,
    ...FRONTEND_DEFERRED_HELPER_SOURCES,
  ]) {
    if (referenceCount(html, source) !== 0) {
      failures.push(`public/index.html must not load canonical source ${source} at runtime.`);
    }
  }

  for (const [artifactName, artifact] of [
    [FRONTEND_BOOTSTRAP_ARTIFACT, bootstrapArtifact],
    [FRONTEND_STARTUP_HELPER_ARTIFACT, helperArtifact],
    [FRONTEND_DEFERRED_HELPER_ARTIFACT, deferredHelperArtifact],
  ]) {
    let version = null;
    try {
      version = readFrontendAssetVersion(html, artifactName);
    } catch (error) {
      failures.push(error.message);
    }
    if (!version || version.hash !== buildFrontendAssetHash(artifact)) {
      failures.push(`public/index.html must reference the current ${artifactName} content hash.`);
    }
    if (artifact) {
      try {
        new Function(artifact);
      } catch (error) {
        failures.push(`public/${artifactName} is not valid JavaScript: ${error.message}`);
      }
    }
  }

  const bootstrapSourceGzipBytes = gzipSync(bootstrapSource, { level: 6 }).length;
  const bootstrapArtifactGzipBytes = gzipSync(bootstrapArtifact, { level: 6 }).length;
  const helperSourceGzipBytes = helperSources.reduce(
    (total, item) => total + gzipSync(item.source, { level: 6 }).length,
    0,
  );
  const helperArtifactGzipBytes = gzipSync(helperArtifact, { level: 6 }).length;
  const deferredHelperSourceGzipBytes = deferredHelperSources.reduce(
    (total, item) => total + gzipSync(item.source, { level: 6 }).length,
    0,
  );
  const deferredHelperArtifactGzipBytes = gzipSync(deferredHelperArtifact, { level: 6 }).length;
  if (!(bootstrapArtifactGzipBytes < bootstrapSourceGzipBytes)) {
    failures.push(`public/${FRONTEND_BOOTSTRAP_ARTIFACT} must reduce bootstrap gzip bytes.`);
  }
  if (!(helperArtifactGzipBytes < helperSourceGzipBytes)) {
    failures.push(`public/${FRONTEND_STARTUP_HELPER_ARTIFACT} must reduce startup helper gzip bytes.`);
  }
  if (!(deferredHelperArtifactGzipBytes < deferredHelperSourceGzipBytes)) {
    failures.push(`public/${FRONTEND_DEFERRED_HELPER_ARTIFACT} must reduce deferred helper gzip bytes.`);
  }

  return {
    failures,
    metrics: {
      bootstrap_source_gzip_bytes: bootstrapSourceGzipBytes,
      bootstrap_artifact_gzip_bytes: bootstrapArtifactGzipBytes,
      helper_source_gzip_bytes: helperSourceGzipBytes,
      helper_artifact_gzip_bytes: helperArtifactGzipBytes,
      deferred_helper_source_gzip_bytes: deferredHelperSourceGzipBytes,
      deferred_helper_artifact_gzip_bytes: deferredHelperArtifactGzipBytes,
      startup_gzip_savings_bytes: (bootstrapSourceGzipBytes - bootstrapArtifactGzipBytes)
        + (helperSourceGzipBytes - helperArtifactGzipBytes),
      gzip_savings_bytes: (bootstrapSourceGzipBytes - bootstrapArtifactGzipBytes)
        + (helperSourceGzipBytes - helperArtifactGzipBytes)
        + (deferredHelperSourceGzipBytes - deferredHelperArtifactGzipBytes),
      request_savings: FRONTEND_STARTUP_HELPER_SOURCES.length
        + FRONTEND_DEFERRED_HELPER_SOURCES.length - 2,
      bootstrap_artifact_hash: buildFrontendAssetHash(bootstrapArtifact),
      helper_artifact_hash: buildFrontendAssetHash(helperArtifact),
      deferred_helper_artifact_hash: buildFrontendAssetHash(deferredHelperArtifact),
      ctrip_facade_export_count: ctripFacadeInspection.metrics.facade_export_count,
      ctrip_full_export_count: ctripFacadeInspection.metrics.full_export_count,
      ctrip_facade_type_mismatch_count: ctripFacadeInspection.metrics.type_mismatches.length,
      runtime_asset_count: runtimeAssetIdentity.files.length,
      runtime_asset_digest: runtimeAssetIdentity.digest,
    },
  };
}
