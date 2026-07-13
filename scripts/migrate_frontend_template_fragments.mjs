import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  FRONTEND_TEMPLATE_FRAGMENT_DEFINITIONS,
  FRONTEND_TEMPLATE_MANIFEST_RELATIVE_PATH,
  loadFrontendTemplateSource,
} from './lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const canonicalPath = path.join(repoRoot, 'resources/frontend/app-template.html');
const templatesRoot = path.join(repoRoot, 'resources/frontend/templates');
const manifestPath = path.join(repoRoot, FRONTEND_TEMPLATE_MANIFEST_RELATIVE_PATH);
const checkOnly = process.argv.includes('--check');
const force = process.argv.includes('--force');
const canonicalBuffer = fs.readFileSync(canonicalPath);

const located = FRONTEND_TEMPLATE_FRAGMENT_DEFINITIONS.map((fragment) => {
  const anchorBuffer = Buffer.from(fragment.anchor, 'utf8');
  const anchorOffset = canonicalBuffer.indexOf(anchorBuffer);
  if (anchorOffset < 0) throw new Error(`Fragment anchor not found: ${fragment.id}`);
  if (canonicalBuffer.indexOf(anchorBuffer, anchorOffset + anchorBuffer.length) >= 0) {
    throw new Error(`Fragment anchor is not unique: ${fragment.id}`);
  }
  const lineStart = fragment.id === 'app-shell'
    ? 0
    : canonicalBuffer.lastIndexOf(0x0a, anchorOffset - 1) + 1;
  return { ...fragment, start: lineStart };
});

for (let index = 1; index < located.length; index += 1) {
  if (located[index].start <= located[index - 1].start) {
    throw new Error(`Fragment anchors are out of source order: ${located[index - 1].id} -> ${located[index].id}`);
  }
}

const expectedFragments = located.map((fragment, index) => ({
  ...fragment,
  buffer: canonicalBuffer.subarray(fragment.start, located[index + 1]?.start ?? canonicalBuffer.length),
}));
const manifest = {
  schema_version: 1,
  source_snapshot: 'resources/frontend/app-template.html',
  source_snapshot_sha256: crypto.createHash('sha256').update(canonicalBuffer).digest('hex'),
  source_snapshot_bytes: canonicalBuffer.length,
  fragments: FRONTEND_TEMPLATE_FRAGMENT_DEFINITIONS.map(({ id, domain, path: fragmentPath, anchor }) => ({
    id,
    domain,
    path: fragmentPath,
    anchor,
  })),
};
const manifestSource = `${JSON.stringify(manifest, null, 2)}\n`;
const conflicts = [];

if (fs.existsSync(manifestPath) && fs.readFileSync(manifestPath, 'utf8') !== manifestSource) {
  conflicts.push(path.relative(repoRoot, manifestPath));
}
for (const fragment of expectedFragments) {
  const outputPath = path.resolve(templatesRoot, fragment.path);
  if (fs.existsSync(outputPath) && !fs.readFileSync(outputPath).equals(fragment.buffer)) {
    conflicts.push(path.relative(repoRoot, outputPath));
  }
}
if (conflicts.length && !force) {
  throw new Error(`Refusing to overwrite changed frontend template fragments: ${conflicts.join(', ')}`);
}

if (!checkOnly) {
  if (!fs.readFileSync(canonicalPath).equals(canonicalBuffer)) {
    throw new Error('Canonical frontend template changed while fragment migration was preparing; retry from the latest source.');
  }
  for (const fragment of expectedFragments) {
    const outputPath = path.resolve(templatesRoot, fragment.path);
    fs.mkdirSync(path.dirname(outputPath), { recursive: true });
    fs.writeFileSync(outputPath, fragment.buffer);
  }
  fs.mkdirSync(path.dirname(manifestPath), { recursive: true });
  fs.writeFileSync(manifestPath, manifestSource, 'utf8');
}

if (!checkOnly || fs.existsSync(manifestPath)) {
  const assembled = loadFrontendTemplateSource(repoRoot);
  if (!assembled.templateBuffer.equals(canonicalBuffer)) {
    throw new Error('Frontend template fragments do not reconstruct the canonical template byte-for-byte.');
  }
}
if (!fs.readFileSync(canonicalPath).equals(canonicalBuffer)) {
  throw new Error('Canonical frontend template changed during fragment verification; retry from the latest source.');
}

console.log(JSON.stringify({
  status: checkOnly ? 'checked' : 'migrated',
  fragment_count: expectedFragments.length,
  template_bytes: canonicalBuffer.length,
  conflicts,
}, null, 2));
