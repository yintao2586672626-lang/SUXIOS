import { buildFrontendAssetHash, updateFrontendAssetVersion } from './frontend_asset_version.mjs';

export const STARTUP_LAZY_COMPONENTS = Object.freeze({
  'components/system/app-main-components-loader.js': ['components/system/app-main-components.js'],
  'components/system/dual-ota-field-closure-loader.js': ['components/system/dual-ota-field-closure-panel.js'],
  'components/system/operating-intelligence-loader.js': [
    'components/system/operating-intelligence-components.js',
    'components/system/hotel-data-analyst-components.js',
  ],
});

function syncQuotedLazyReference(source, name, bytes) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp('([\'"`])(' + escapedName + '\\?v=([^\'"`\\s]+))\\1', 'g');
  const matches = [...source.matchAll(pattern)];
  if (matches.length !== 1) throw new Error(`Startup lazy reference must occur exactly once: ${name}`);
  const match = matches[0];
  // Older component loaders used 12 hash characters. Migrate that established
  // format through the canonical version writer without weakening its contract.
  const reference = match[2].replace(/-h([a-f0-9]{10})[a-f0-9]{2}$/, '-h$1');
  const updated = updateFrontendAssetVersion(`"${reference}"`, name, bytes).html.slice(1, -1);
  return source.slice(0, match.index) + match[1] + updated + match[1] + source.slice(match.index + match[0].length);
}

// The startup bundle contains these loaders. Pin dependencies before building
// that bundle, and retain their bytes so callers can reject concurrent changes.
export function syncStartupLazyComponentVersions(entries, readAsset) {
  const dependencies = new Map();
  const sources = entries.map(entry => {
    let source = entry.source;
    for (const name of STARTUP_LAZY_COMPONENTS[entry.name] || []) {
      if (!dependencies.has(name)) dependencies.set(name, readAsset(name));
      source = syncQuotedLazyReference(source, name, dependencies.get(name));
    }
    return { ...entry, originalSource: entry.source, source };
  });
  for (const name of Object.keys(STARTUP_LAZY_COMPONENTS)) {
    if (sources.filter(entry => entry.name === name).length !== 1) {
      throw new Error(`Startup lazy loader must occur exactly once: ${name}`);
    }
  }
  return { sources, dependencies };
}

// The operation helper is loaded after mount, so its URL is versioned inside app-main.
export function syncRevenueAiStaticVersion(source, revenueAiStatic) {
  const pattern = /\bconst revenueAiStaticVersion = '([^'\r\n]+)-h[a-f0-9]{10}';/g;
  const matches = [...String(source).matchAll(pattern)];
  if (matches.length !== 1) throw new Error('Expected exactly one versioned revenue AI static loader.');
  const hash = buildFrontendAssetHash(revenueAiStatic);
  return {
    source: String(source).replace(pattern, () => `const revenueAiStaticVersion = '${matches[0][1]}-h${hash}';`),
    hash,
  };
}

export function syncOperationStaticVersion(source, operationStatic) {
  const pattern = /\bconst operationStaticScriptVersion = '([^'\r\n]+)-h[a-f0-9]{10}';/g;
  const matches = [...String(source).matchAll(pattern)];
  if (matches.length !== 1) throw new Error('Expected exactly one versioned operation static loader.');
  const hash = buildFrontendAssetHash(operationStatic);
  return {
    source: String(source).replace(pattern, () => `const operationStaticScriptVersion = '${matches[0][1]}-h${hash}';`),
    hash,
  };
}
