import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildFrontendEntry } from './lib/frontend_entry_build.mjs';
import { updateFrontendAssetVersion } from './lib/frontend_asset_version.mjs';
import { syncOperationStaticVersion, syncRevenueStaticVersions, syncKnowledgeDomainVersion, syncSimulationStaticVersion } from './lib/frontend_lazy_asset_versions.mjs';
import {
  acquireFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(repoRoot, { owner: 'build-frontend-entry' });
try {
const sourcePath = path.join(repoRoot, 'public/app-main.js');
const artifactPath = path.join(repoRoot, 'public/app-main.min.js');
const indexPath = path.join(repoRoot, 'public/index.html');
const originalSource = fs.readFileSync(sourcePath, 'utf8');
const operationStaticPath = path.join(repoRoot, 'public/operation-static.js');
const operationStatic = fs.readFileSync(operationStaticPath);
const lazyVersion = syncOperationStaticVersion(originalSource, operationStatic);
const revenueAiPath = path.join(repoRoot, 'public/revenue-ai-static.js');
const cockpitPath = path.join(repoRoot, 'public/revenue-cockpit-static.js');
const originalRevenueAi = fs.readFileSync(revenueAiPath, 'utf8');
const cockpitSource = fs.readFileSync(cockpitPath, 'utf8');
const revenueVersion = syncRevenueStaticVersions(lazyVersion.source, originalRevenueAi, cockpitSource);
const knowledgeDomainPath = path.join(repoRoot, 'public/components/system/knowledge-center-domain.js');
const knowledgeDomain = fs.readFileSync(knowledgeDomainPath);
const knowledgeSource = syncKnowledgeDomainVersion(revenueVersion.appMain, knowledgeDomain);
const simulationStaticPath = path.join(repoRoot, 'public/simulation-static.js');
const simulationStatic = fs.readFileSync(simulationStaticPath);
const simulationVersion = syncSimulationStaticVersion(knowledgeSource, simulationStatic);
const source = simulationVersion.source;
const indexSource = fs.readFileSync(indexPath, 'utf8');
const artifact = await buildFrontendEntry(source);
const versionUpdate = updateFrontendAssetVersion(indexSource, 'app-main.min.js', artifact);

if (fs.readFileSync(sourcePath, 'utf8') !== originalSource) {
  throw new Error('public/app-main.js changed during compilation; refusing to publish a stale runtime entry.');
}
if (!fs.readFileSync(operationStaticPath).equals(operationStatic)) {
  throw new Error('public/operation-static.js changed during compilation; refusing to publish a stale lazy helper version.');
}
if (fs.readFileSync(revenueAiPath, 'utf8') !== originalRevenueAi || fs.readFileSync(cockpitPath, 'utf8') !== cockpitSource) {
  throw new Error('Revenue helper changed during compilation; refusing stale loader versions.');
}
if (!fs.readFileSync(knowledgeDomainPath).equals(knowledgeDomain)) throw new Error('Knowledge domain changed during compilation.');
if (!fs.readFileSync(simulationStaticPath).equals(simulationStatic)) {
  throw new Error('simulation-static.js changed during compilation; refusing a stale loader.');
}
if (fs.readFileSync(indexPath, 'utf8') !== indexSource) {
  throw new Error('public/index.html changed during entry compilation; refusing to publish mixed asset versions.');
}

function writeFileIfChanged(file, content) {
  const next = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
  if (fs.existsSync(file) && fs.readFileSync(file).equals(next)) return false;
  writeFileAtomic(file, next);
  return true;
}

const sourceChanged = writeFileIfChanged(sourcePath, source);
const revenueAiChanged = writeFileIfChanged(revenueAiPath, revenueVersion.revenueAi);
const artifactChanged = writeFileIfChanged(artifactPath, artifact);
const indexChanged = writeFileIfChanged(indexPath, versionUpdate.html);
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  source_bytes: Buffer.byteLength(source),
  artifact_bytes: Buffer.byteLength(artifact),
  artifact_hash: versionUpdate.hash,
  operation_static_hash: lazyVersion.hash,
  revenue_cockpit_hash: revenueVersion.cockpitHash,
  revenue_ai_hash: revenueVersion.revenueAiHash,
  revenue_ai_loader_changed: revenueAiChanged,
  simulation_static_hash: simulationVersion.hash,
  revenue_ai_static_hash: revenueVersion.revenueAiHash,
  source_version_changed: sourceChanged,
  artifact_changed: artifactChanged,
  index_changed: indexChanged,
}, null, 2));
} finally {
  releaseLock();
}
