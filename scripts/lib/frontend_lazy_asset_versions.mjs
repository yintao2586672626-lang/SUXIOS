import { buildFrontendAssetHash, updateFrontendAssetVersion } from './frontend_asset_version.mjs';

export const STARTUP_LAZY_COMPONENTS = Object.freeze({
  'components/system/app-main-components-loader.js': [
    'components/system/app-main-components.js',
    'components/operations/task-workflow-panel.js',
  ],
  'components/system/dual-ota-field-closure-loader.js': ['components/system/dual-ota-field-closure-panel.js'],
  'components/system/operating-intelligence-loader.js': [
    'components/system/operating-intelligence-components.js',
    'components/system/hotel-data-analyst-components.js',
  ],
});

export const NESTED_LAZY_COMPONENTS = Object.freeze({
  'components/system/app-main-components.js': ['components/system/manager-coaching-panel.js'],
});

export const ACTION_LAZY_HELPERS = Object.freeze({
  notificationStaticVersion: 'notification-static.js',
  autoFetchStaticVersion: 'auto-fetch-static.js',
  testIdStaticVersion: 'testid-static.js',
  operatingGrowthStaticVersion: 'operating-growth-static.js',
  revenueResearchStaticVersion: 'revenue-research-static.js',
  aiAnalysisStaticVersion: 'ai-analysis-static.js',
  expansionStaticOptionsScriptVersion: 'expansion-static-options.js',
});

export function syncActionLazyHelperVersions(source, readAsset) {
  let nextSource = source;
  const dependencies = new Map();
  for (const [variable, asset] of Object.entries(ACTION_LAZY_HELPERS)) {
    const pattern = new RegExp(`\\bconst ${variable} = '([^'\\r\\n]+)';`, 'g');
    const matches = [...nextSource.matchAll(pattern)];
    if (matches.length !== 1) throw new Error(`Expected exactly one ${variable} declaration.`);
    const bytes = readAsset(asset);
    dependencies.set(asset, bytes);
    const prefix = matches[0][1].replace(/-h[a-f0-9]{10}$/, '');
    const version = `${prefix}-h${buildFrontendAssetHash(bytes)}`;
    nextSource = nextSource.replace(pattern, () => `const ${variable} = '${version}';`);
  }
  return { source: nextSource, dependencies };
}

function syncQuotedLazyReference(source, name, bytes) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp('([\'"`])(' + escapedName + '\\?v=([^\'"`\\s]+))\\1', 'g');
  const matches = [...source.matchAll(pattern)];
  if (matches.length !== 1) throw new Error(`Startup lazy reference must occur exactly once: ${name}`);
  const match = matches[0];
  // Older component loaders used 12 hash characters. Migrate that established
  // format through the canonical version writer without weakening its contract.
  let reference = match[2].replace(/-h([a-f0-9]{10})[a-f0-9]{2}$/, '-h$1');
  // Migrate the workflow panel's original fixed release through the build once.
  if (reference === 'components/operations/task-workflow-panel.js?v=20260908-workflow-v1'
    || reference === 'components/system/manager-coaching-panel.js?v=20260926-v1') {
    reference += `-h${buildFrontendAssetHash(bytes)}`;
  }
  const updated = updateFrontendAssetVersion(`"${reference}"`, name, bytes).html.slice(1, -1);
  return source.slice(0, match.index) + match[1] + updated + match[1] + source.slice(match.index + match[0].length);
}

// The startup bundle contains these loaders. Pin dependencies before building
// that bundle, and retain their bytes so callers can reject concurrent changes.
export function syncStartupLazyComponentVersions(entries, readAsset) {
  const dependencies = new Map();
  const dependencySources = new Map();
  const resolveDependency = name => {
    if (!dependencies.has(name)) dependencies.set(name, readAsset(name));
    const originalSource = dependencies.get(name).toString('utf8');
    let source = originalSource;
    for (const child of NESTED_LAZY_COMPONENTS[name] || []) {
      source = syncQuotedLazyReference(source, child, resolveDependency(child));
    }
    if (source !== originalSource) dependencySources.set(name, { name, originalSource, source });
    return Buffer.from(source, 'utf8');
  };
  const sources = entries.map(entry => {
    let source = entry.source;
    for (const name of STARTUP_LAZY_COMPONENTS[entry.name] || []) {
      source = syncQuotedLazyReference(source, name, resolveDependency(name));
    }
    return { ...entry, originalSource: entry.source, source };
  });
  for (const name of Object.keys(STARTUP_LAZY_COMPONENTS)) {
    if (sources.filter(entry => entry.name === name).length !== 1) {
      throw new Error(`Startup lazy loader must occur exactly once: ${name}`);
    }
  }
  return { sources, dependencies, dependencySources: [...dependencySources.values()] };
}

export function syncStartupLazyHtmlVersions(html, plan) {
  let nextHtml = html;
  const rewritten = new Map(plan.dependencySources.map(entry => [entry.name, entry.source]));
  for (const [name, originalBytes] of plan.dependencies) {
    // Some lazy parents are also declared as deferred entry assets. Both URLs
    // must describe the same final bytes after nested dependencies are pinned.
    if (!nextHtml.includes(`${name}?v=`)) continue;
    nextHtml = updateFrontendAssetVersion(nextHtml, name, rewritten.get(name) ?? originalBytes).html;
  }
  return nextHtml;
}

// Revenue AI is loaded after mount, so its version is embedded in app-main.
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

// The operation helper is also loaded after mount.
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

export function syncRevenueStaticVersions(appMain, revenueAi, cockpit) {
  const sync = (source, name, bytes) => {
    const pattern = new RegExp(`\\bconst ${name} = '([^'\\r\\n]+)-h[a-f0-9]{10}';`, 'g');
    const matches = [...String(source).matchAll(pattern)];
    if (matches.length !== 1) throw new Error(`Expected exactly one versioned ${name} loader.`);
    const hash = buildFrontendAssetHash(bytes);
    return { source: String(source).replace(pattern, () => `const ${name} = '${matches[0][1]}-h${hash}';`), hash };
  };
  const child = sync(revenueAi, 'revenueCockpitStaticVersion', cockpit);
  const parent = syncRevenueAiStaticVersion(appMain, child.source);
  return { appMain: parent.source, revenueAi: child.source, cockpitHash: child.hash, revenueAiHash: parent.hash };
}


export function syncKnowledgeDomainVersion(source, domain) {
  const pattern = /(components\/system\/knowledge-center-domain\.js\?v=[^'"\r\n]*-h)[a-f0-9]{10}/g;
  if ([...String(source).matchAll(pattern)].length !== 1) throw new Error('Expected one knowledge domain loader.');
  return String(source).replace(pattern, (_, prefix) => prefix + buildFrontendAssetHash(domain));
}



export function syncSimulationStaticVersion(source, simulationStatic) {
  const pattern = /\bconst simulationStaticScriptVersion = '([^'\r\n]+)-h[a-f0-9]{10}';/g;
  const matches = [...String(source).matchAll(pattern)];
  if (matches.length !== 1) throw new Error('Expected exactly one versioned simulation static loader.');
  const hash = buildFrontendAssetHash(simulationStatic);
  return { source: String(source).replace(pattern, () => `const simulationStaticScriptVersion = '${matches[0][1]}-h${hash}';`), hash };
}

// The assistant is loaded on demand; the startup bundle must request its current bytes.
export function syncOperatingIntelligenceVersion(source, component) {
  const pattern = /\bconst fullScript = 'components\/system\/operating-intelligence-components\.js\?v=([^'\r\n]+)-h[a-f0-9]{10}';/g;
  const matches = [...String(source).matchAll(pattern)];
  if (matches.length !== 1) throw new Error('Expected exactly one versioned operating intelligence loader.');
  const hash = buildFrontendAssetHash(component);
  return {
    source: String(source).replace(pattern, () => `const fullScript = 'components/system/operating-intelligence-components.js?v=${matches[0][1]}-h${hash}';`),
    hash,
  };
}
