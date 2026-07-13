import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from './lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const checkOnly = process.argv.includes('--check');
const force = process.argv.includes('--force');
const hash = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

const source = loadFrontendTemplateSource(repoRoot);
const manifestSourceBefore = fs.readFileSync(source.manifestPath, 'utf8');
const snapshotPath = path.resolve(repoRoot, source.manifest.source_snapshot);
const expectedSnapshotPath = path.resolve(repoRoot, 'resources/frontend/app-template.html');
if (snapshotPath !== expectedSnapshotPath) {
  throw new Error(`Unexpected frontend template compatibility snapshot: ${source.manifest.source_snapshot}`);
}

const snapshotBuffer = fs.existsSync(snapshotPath) ? fs.readFileSync(snapshotPath) : Buffer.alloc(0);
const assembledHash = hash(source.templateBuffer);
const snapshotHash = hash(snapshotBuffer);
const snapshotMatches = snapshotBuffer.equals(source.templateBuffer);
const snapshotMatchesPinnedVersion = snapshotHash === source.manifest.source_snapshot_sha256
  && snapshotBuffer.length === source.manifest.source_snapshot_bytes;
const metadataMatches = source.manifest.source_snapshot_sha256 === assembledHash
  && source.manifest.source_snapshot_bytes === source.templateBuffer.length;

if (checkOnly && (!snapshotMatches || !metadataMatches)) {
  throw new Error('Frontend template compatibility snapshot is not synchronized with the business fragments.');
}
if (!checkOnly && !snapshotMatches && !snapshotMatchesPinnedVersion && !force) {
  throw new Error(
    'Compatibility snapshot changed independently of the pinned manifest; refusing to overwrite it. '
    + 'Migrate that snapshot into fragments first, or rerun with --force only after reviewing the conflict.'
  );
}

let snapshotChanged = false;
let manifestChanged = false;
if (!checkOnly) {
  const sourceBeforeWrite = loadFrontendTemplateSource(repoRoot);
  if (fs.readFileSync(source.manifestPath, 'utf8') !== manifestSourceBefore
    || !sourceBeforeWrite.templateBuffer.equals(source.templateBuffer)) {
    throw new Error('Frontend template fragments changed while snapshot synchronization was preparing; retry.');
  }

  if (!snapshotMatches) {
    if (fs.existsSync(snapshotPath) && !fs.readFileSync(snapshotPath).equals(snapshotBuffer)) {
      throw new Error('Frontend template compatibility snapshot changed during synchronization; retry.');
    }
    fs.writeFileSync(snapshotPath, source.templateBuffer);
    snapshotChanged = true;
  }

  const sourceBeforeManifestWrite = loadFrontendTemplateSource(repoRoot);
  if (fs.readFileSync(source.manifestPath, 'utf8') !== manifestSourceBefore
    || !sourceBeforeManifestWrite.templateBuffer.equals(source.templateBuffer)) {
    throw new Error('Frontend template fragments changed during snapshot synchronization; retry.');
  }
  const nextManifest = {
    ...source.manifest,
    source_snapshot_sha256: assembledHash,
    source_snapshot_bytes: source.templateBuffer.length,
  };
  const nextManifestSource = `${JSON.stringify(nextManifest, null, 2)}\n`;
  if (nextManifestSource !== manifestSourceBefore) {
    fs.writeFileSync(source.manifestPath, nextManifestSource, 'utf8');
    manifestChanged = true;
  }
}

const verified = loadFrontendTemplateSource(repoRoot);
const verifiedSnapshot = fs.readFileSync(snapshotPath);
const verifiedHash = hash(verified.templateBuffer);
if (!verifiedSnapshot.equals(verified.templateBuffer)
  || verified.manifest.source_snapshot_sha256 !== verifiedHash
  || verified.manifest.source_snapshot_bytes !== verified.templateBuffer.length) {
  throw new Error('Frontend template compatibility snapshot verification failed after synchronization.');
}

console.log(JSON.stringify({
  status: checkOnly ? 'checked' : 'synchronized',
  fragment_count: verified.fragments.length,
  template_bytes: verified.templateBuffer.length,
  template_sha256: verifiedHash,
  snapshot_changed: snapshotChanged,
  manifest_changed: manifestChanged,
}, null, 2));
