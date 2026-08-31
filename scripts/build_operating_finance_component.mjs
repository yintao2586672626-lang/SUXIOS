import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import {
  compileFrontendTemplate,
  FRONTEND_TEMPLATE_MINIFY_OPTIONS,
} from './lib/frontend_template_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(repoRoot, 'public/components/system/operating-finance-control-center.js');
const artifactPath = path.join(repoRoot, 'public/components/system/operating-finance-control-center.min.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const startMarker = '        template: `';
const closingMarker = '\n        `,';
const endMarker = `${closingMarker}\n    };`;
const markerStart = source.indexOf(startMarker);
if (markerStart < 0) throw new Error('Operating-finance component source template marker is missing.');
const templateStart = markerStart + startMarker.length;
const templateEnd = source.indexOf(endMarker, templateStart);
if (templateEnd < 0) throw new Error('Operating-finance component source template end marker is missing.');
const template = source.slice(templateStart, templateEnd);
const compiled = compileFrontendTemplate(template);
const compiledSource = source.slice(0, markerStart)
  + `        render: (function(Vue){${compiled}})(Vue),`
  + source.slice(templateEnd + closingMarker.length);
const result = await minify(
  { 'operating-finance-control-center.js': compiledSource },
  structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS),
);
if (!result.code) throw new Error('Operating-finance component minification returned empty output.');
const artifact = `${result.code}\n`;
const existing = fs.existsSync(artifactPath) ? fs.readFileSync(artifactPath, 'utf8') : '';
if (existing !== artifact) fs.writeFileSync(artifactPath, artifact, 'utf8');
const artifactSha256 = crypto.createHash('sha256').update(artifact).digest('hex');
const loaderPath = path.join(repoRoot, 'public/components/system/app-main-components.js');
const loaderSource = fs.readFileSync(loaderPath, 'utf8');
const loaderPattern = /components\/system\/operating-finance-control-center\.min\.js\?v=20260830-operating-finance-h[0-9a-f]{10}/;
if (!loaderPattern.test(loaderSource)) throw new Error('Operating-finance component cache identity is missing.');
const nextLoaderSource = loaderSource.replace(
  loaderPattern,
  `components/system/operating-finance-control-center.min.js?v=20260830-operating-finance-h${artifactSha256.slice(0, 10)}`,
);
if (nextLoaderSource !== loaderSource) fs.writeFileSync(loaderPath, nextLoaderSource, 'utf8');
const fullComponentSha256 = crypto.createHash('sha256').update(nextLoaderSource).digest('hex');
const fullComponentPattern = /components\/system\/app-main-components\.js\?v=20260830-(?:review-fixes|operating-finance)-h[0-9a-f]{10}/g;
const fullComponentReference = `components/system/app-main-components.js?v=20260830-operating-finance-h${fullComponentSha256.slice(0, 10)}`;
const bridgePath = path.join(repoRoot, 'public/components/system/app-main-components-loader.js');
const bridgeSource = fs.readFileSync(bridgePath, 'utf8');
if (!/'OperatingFinanceControlCenter'/.test(bridgeSource)) {
  throw new Error('App-main component bridge does not expose OperatingFinanceControlCenter.');
}
if (!fullComponentPattern.test(bridgeSource)) throw new Error('App-main component bridge cache identity is missing.');
const nextBridgeSource = bridgeSource.replace(fullComponentPattern, fullComponentReference);
if (nextBridgeSource !== bridgeSource) fs.writeFileSync(bridgePath, nextBridgeSource, 'utf8');
const indexPath = path.join(repoRoot, 'public/index.html');
const indexSource = fs.readFileSync(indexPath, 'utf8');
fullComponentPattern.lastIndex = 0;
if (!fullComponentPattern.test(indexSource)) throw new Error('Authenticated app-main component cache identity is missing.');
const nextIndexSource = indexSource.replace(fullComponentPattern, fullComponentReference);
if (nextIndexSource !== indexSource) fs.writeFileSync(indexPath, nextIndexSource, 'utf8');
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  source_sha256: crypto.createHash('sha256').update(source).digest('hex'),
  artifact_sha256: artifactSha256,
  artifact_bytes: Buffer.byteLength(artifact),
  changed: existing !== artifact,
  loader_cache_identity_changed: nextLoaderSource !== loaderSource,
  app_main_components_sha256: fullComponentSha256,
  bridge_cache_identity_changed: nextBridgeSource !== bridgeSource,
  index_cache_identity_changed: nextIndexSource !== indexSource,
}, null, 2));
