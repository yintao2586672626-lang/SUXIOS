import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import postcss from 'postcss';
import { PurgeCSS } from 'purgecss';
import {
  buildFrontendAssetHash,
  readFrontendAssetVersion,
} from './frontend_asset_version.mjs';
import {
  AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT,
  AUTHENTICATED_ASSET_PHASE_STARTUP,
  extractAuthenticatedAssetEntries,
  stripFrontendAssetQuery,
} from './frontend_authenticated_assets.mjs';
import { FRONTEND_STARTUP_HELPER_SOURCES } from './frontend_startup_helpers_build.mjs';
import { loadFrontendStartupTemplateSource } from './frontend_template_source.mjs';

export const FRONTEND_AUTHENTICATED_STYLE_SOURCE = 'style.css';
export const FRONTEND_AUTHENTICATED_STYLE_ARTIFACT = 'style.min.css';
export const FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT = 'style-startup.min.css';
export const FRONTEND_AUTHENTICATED_STARTUP_STYLE_MAX_GZIP_BYTES = 40_000;

const FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS = Object.freeze([
  'index.html',
  'app-main.js',
  ...FRONTEND_STARTUP_HELPER_SOURCES,
]);

const semanticCssNodes = (nodes = []) => nodes
  .filter((node) => node.type !== 'comment')
  .map((node) => {
    if (node.type === 'decl') {
      return {
        type: node.type,
        prop: node.prop,
        value: String(node.value || '').trim(),
        important: node.important === true,
      };
    }
    if (node.type === 'rule') {
      return {
        type: node.type,
        selector: node.selector,
        nodes: semanticCssNodes(node.nodes),
      };
    }
    if (node.type === 'atrule') {
      return {
        type: node.type,
        name: node.name,
        params: node.params,
        nodes: semanticCssNodes(node.nodes),
      };
    }
    return {
      type: node.type,
      value: node.toString(),
    };
  });

export function buildFrontendAuthenticatedStyle(source) {
  const root = postcss.parse(String(source || ''), {
    from: FRONTEND_AUTHENTICATED_STYLE_SOURCE,
  });
  const sourceSemantics = JSON.stringify(semanticCssNodes(root.nodes));

  root.walkComments((comment) => {
    if (!String(comment.text || '').trim().startsWith('!')) comment.remove();
  });
  root.raws.after = '';
  root.walk((node) => {
    if (!node.raws) return;
    node.raws.before = '';
    if (node.type === 'rule') {
      node.raws.between = '';
      node.raws.semicolon = false;
    } else if (node.type === 'decl') {
      node.raws.between = ':';
    } else if (node.type === 'atrule') {
      node.raws.afterName = node.params ? ' ' : '';
      node.raws.between = '';
      node.raws.semicolon = false;
    }
  });

  const artifact = root.toString().trim();
  if (!artifact) throw new Error('PostCSS returned an empty authenticated stylesheet artifact.');
  const artifactRoot = postcss.parse(artifact, {
    from: FRONTEND_AUTHENTICATED_STYLE_ARTIFACT,
  });
  const artifactSemantics = JSON.stringify(semanticCssNodes(artifactRoot.nodes));
  if (artifactSemantics !== sourceSemantics) {
    throw new Error('Authenticated stylesheet minification changed CSS semantics.');
  }
  return `${artifact}\n`;
}

export function loadFrontendAuthenticatedStartupStyleInputs(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const startupTemplate = loadFrontendStartupTemplateSource(repoRoot);
  const sourceFiles = FRONTEND_AUTHENTICATED_STARTUP_STYLE_CONTENT_PATHS.map((relativePath) => {
    const filePath = path.join(publicRoot, relativePath);
    return {
      relativePath,
      filePath,
      source: fs.readFileSync(filePath, 'utf8'),
    };
  });
  return {
    content: [
      { raw: startupTemplate.template, extension: 'html' },
      ...sourceFiles.map((entry) => ({
        raw: entry.source,
        extension: path.extname(entry.relativePath).slice(1) || 'txt',
      })),
    ],
    sourceFiles,
    startupTemplate,
  };
}

export async function buildFrontendAuthenticatedStartupStyle(source, content = []) {
  const [result] = await new PurgeCSS().purge({
    content,
    css: [{ raw: String(source || '') }],
    safelist: ['v-cloak'],
  });
  if (!result?.css) throw new Error('PurgeCSS returned an empty authenticated startup stylesheet.');
  return buildFrontendAuthenticatedStyle(result.css);
}

export async function inspectFrontendAuthenticatedStyle(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const sourcePath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_SOURCE);
  const artifactPath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_ARTIFACT);
  const startupArtifactPath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT);
  const indexPath = path.join(publicRoot, 'index.html');
  const source = fs.readFileSync(sourcePath, 'utf8');
  const artifact = fs.existsSync(artifactPath) ? fs.readFileSync(artifactPath, 'utf8') : '';
  const startupArtifact = fs.existsSync(startupArtifactPath) ? fs.readFileSync(startupArtifactPath, 'utf8') : '';
  const html = fs.readFileSync(indexPath, 'utf8');
  const expected = buildFrontendAuthenticatedStyle(source);
  const startupInputs = loadFrontendAuthenticatedStartupStyleInputs(repoRoot);
  const expectedStartup = await buildFrontendAuthenticatedStartupStyle(source, startupInputs.content);
  const failures = [];

  if (artifact !== expected) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} is stale or missing.`);
  }
  if (!artifact.endsWith('\n') || artifact.endsWith('\n\n')) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} must end with exactly one newline.`);
  }
  if (startupArtifact !== expectedStartup) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} is stale or missing.`);
  }
  if (!startupArtifact.endsWith('\n') || startupArtifact.endsWith('\n\n')) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} must end with exactly one newline.`);
  }
  let version = null;
  let startupVersion = null;
  try {
    version = readFrontendAssetVersion(html, FRONTEND_AUTHENTICATED_STYLE_ARTIFACT);
    startupVersion = readFrontendAssetVersion(html, FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT);
  } catch (error) {
    failures.push(error.message);
  }
  const hash = buildFrontendAssetHash(artifact);
  const startupHash = buildFrontendAssetHash(startupArtifact);
  if (!version || version.hash !== hash) {
    failures.push(`public/index.html must reference the current ${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} hash.`);
  }
  if (!startupVersion || startupVersion.hash !== startupHash) {
    failures.push(`public/index.html must reference the current ${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} hash.`);
  }
  if (html.includes(`\"src\": \"${FRONTEND_AUTHENTICATED_STYLE_SOURCE}?`)) {
    failures.push(`public/index.html must load ${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT}, not the canonical style source.`);
  }
  let authenticatedEntries = [];
  try {
    authenticatedEntries = extractAuthenticatedAssetEntries(html);
  } catch (error) {
    failures.push(error.message);
  }
  const phaseFor = (assetName) => authenticatedEntries.find(
    (entry) => stripFrontendAssetQuery(entry.src) === assetName,
  )?.phase;
  if (phaseFor(FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT) !== AUTHENTICATED_ASSET_PHASE_STARTUP
    || phaseFor(FRONTEND_AUTHENTICATED_STYLE_ARTIFACT) !== AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT) {
    failures.push('The compact authenticated stylesheet must load at startup while the full stylesheet remains deferred.');
  }

  const sourceGzipBytes = gzipSync(source, { level: 6 }).length;
  const artifactGzipBytes = gzipSync(artifact, { level: 6 }).length;
  const startupArtifactGzipBytes = gzipSync(startupArtifact, { level: 6 }).length;
  if (!(artifactGzipBytes < sourceGzipBytes)) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} must reduce authenticated stylesheet gzip bytes.`);
  }
  if (!(startupArtifactGzipBytes < artifactGzipBytes)) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} must stay smaller than the full authenticated stylesheet.`);
  }
  if (startupArtifactGzipBytes > FRONTEND_AUTHENTICATED_STARTUP_STYLE_MAX_GZIP_BYTES) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} exceeded the ${FRONTEND_AUTHENTICATED_STARTUP_STYLE_MAX_GZIP_BYTES} byte gzip ceiling.`);
  }
  for (const selector of ['.sidebar', '.compass-dashboard', '.dual-ota-home', '.suxi-dashboard-scope']) {
    if (!startupArtifact.includes(selector)) {
      failures.push(`public/${FRONTEND_AUTHENTICATED_STARTUP_STYLE_ARTIFACT} is missing startup selector ${selector}.`);
    }
  }

  return {
    failures,
    metrics: {
      source_bytes: Buffer.byteLength(source),
      artifact_bytes: Buffer.byteLength(artifact),
      source_gzip_bytes: sourceGzipBytes,
      artifact_gzip_bytes: artifactGzipBytes,
      gzip_savings_bytes: sourceGzipBytes - artifactGzipBytes,
      artifact_hash: hash,
      startup_artifact_bytes: Buffer.byteLength(startupArtifact),
      startup_artifact_gzip_bytes: startupArtifactGzipBytes,
      startup_artifact_hash: startupHash,
      deferred_style_gzip_bytes: artifactGzipBytes,
      startup_style_gzip_savings_bytes: artifactGzipBytes - startupArtifactGzipBytes,
    },
  };
}
