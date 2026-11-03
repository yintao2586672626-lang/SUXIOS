import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inflateRawSync } from 'node:zlib';

const fixtureRoot = new URL('../../fixtures/', import.meta.url);
export const retiredFrontendManifest = JSON.parse(readFileSync(
  new URL('frontend-retired-20261002.json', fixtureRoot), 'utf8',
));
const archive = readFileSync(new URL(retiredFrontendManifest.archive, fixtureRoot));
const sha256 = buffer => createHash('sha256').update(buffer).digest('hex');
if (sha256(archive) !== retiredFrontendManifest.archive_sha256) {
  throw new Error('Retired frontend fixture archive SHA-256 mismatch');
}

// Read the local, pinned historical fixture without extracting files or adding
// a ZIP dependency. This helper is used by static regression tests only.
const entries = new Map();
const footer = archive.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
if (footer < 0) throw new Error('Retired frontend fixture ZIP footer is missing');
let cursor = archive.readUInt32LE(footer + 16);
for (let index = 0; index < archive.readUInt16LE(footer + 10); index += 1) {
  if (archive.readUInt32LE(cursor) !== 0x02014b50) throw new Error('Invalid fixture ZIP directory');
  const flags = archive.readUInt16LE(cursor + 8);
  const method = archive.readUInt16LE(cursor + 10);
  const compressedBytes = archive.readUInt32LE(cursor + 20);
  const nameBytes = archive.readUInt16LE(cursor + 28);
  const extraBytes = archive.readUInt16LE(cursor + 30);
  const commentBytes = archive.readUInt16LE(cursor + 32);
  const local = archive.readUInt32LE(cursor + 42);
  const name = archive.subarray(cursor + 46, cursor + 46 + nameBytes).toString('utf8');
  if ((flags & 1) || ![0, 8].includes(method) || archive.readUInt32LE(local) !== 0x04034b50) {
    throw new Error(`Unsupported fixture ZIP entry: ${name}`);
  }
  const start = local + 30 + archive.readUInt16LE(local + 26) + archive.readUInt16LE(local + 28);
  const compressed = archive.subarray(start, start + compressedBytes);
  const buffer = method === 8 ? inflateRawSync(compressed) : compressed;
  const metadata = retiredFrontendManifest.fragments.find(fragment => fragment.fixture_entry === name);
  if (!metadata || entries.has(name) || buffer.length !== metadata.bytes || sha256(buffer) !== metadata.sha256) {
    throw new Error(`Retired frontend fixture content mismatch: ${name}`);
  }
  entries.set(name, buffer);
  cursor += 46 + nameBytes + extraBytes + commentBytes;
}
if (entries.size !== retiredFrontendManifest.fragments.length) {
  throw new Error('Retired frontend fixture archive is incomplete');
}

export function readRetiredFrontendFragment(name) {
  const source = entries.get(name);
  if (source === undefined) throw new Error(`Unknown retired frontend fragment: ${name}`);
  return source.toString('utf8');
}

export function readFrontendTestSource(relativePath) {
  const retired = retiredFrontendManifest.fragments.find(fragment =>
    relativePath === `resources/frontend/templates/${fragment.path}`);
  return retired
    ? readRetiredFrontendFragment(retired.fixture_entry)
    : readFileSync(new URL(`../../../${relativePath}`, import.meta.url), 'utf8');
}

export function readFrontendTestFileSync(file, options) {
  const repoRoot = fileURLToPath(new URL('../../../', import.meta.url));
  const requested = file instanceof URL ? fileURLToPath(file) : String(file);
  const relativePath = (path.isAbsolute(requested) ? path.relative(repoRoot, requested) : requested)
    .replaceAll('\\', '/');
  const retired = retiredFrontendManifest.fragments.find(fragment =>
    relativePath === `resources/frontend/templates/${fragment.path}`);
  if (!retired) return readFileSync(file, options);
  const buffer = entries.get(retired.fixture_entry);
  const encoding = typeof options === 'string' ? options : options?.encoding;
  return encoding ? buffer.toString(encoding) : Buffer.from(buffer);
}
