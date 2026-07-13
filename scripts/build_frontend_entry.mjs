import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildFrontendEntry } from './lib/frontend_entry_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(repoRoot, 'public/app-main.js');
const artifactPath = path.join(repoRoot, 'public/app-main.min.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const artifact = await buildFrontendEntry(source);

fs.writeFileSync(artifactPath, artifact, 'utf8');
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  source_bytes: Buffer.byteLength(source),
  artifact_bytes: Buffer.byteLength(artifact),
}, null, 2));
