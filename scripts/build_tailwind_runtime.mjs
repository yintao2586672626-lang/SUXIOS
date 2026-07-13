import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildTailwindRuntimeCss,
  collectTailwindContentFiles,
} from './lib/frontend_tailwind_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(repoRoot, 'public/tailwind.full.css');
const artifactPath = path.join(repoRoot, 'public/tailwind.min.css');
const source = fs.readFileSync(sourcePath, 'utf8');
const contentFiles = collectTailwindContentFiles(repoRoot);
const artifact = await buildTailwindRuntimeCss(source, contentFiles);

fs.writeFileSync(artifactPath, artifact, 'utf8');
console.log(JSON.stringify({
  source: path.relative(repoRoot, sourcePath),
  artifact: path.relative(repoRoot, artifactPath),
  content_file_count: contentFiles.length,
  source_bytes: Buffer.byteLength(source),
  artifact_bytes: Buffer.byteLength(artifact),
}, null, 2));
