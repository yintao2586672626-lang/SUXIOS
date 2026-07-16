import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';
import { buildFrontendEntry } from '../../scripts/lib/frontend_entry_build.mjs';
import {
  extractAuthenticatedAssetReferences,
  stripFrontendAssetQuery,
} from '../../scripts/lib/frontend_authenticated_assets.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourcePath = path.join(repoRoot, 'public/app-main.js');
const artifactPath = path.join(repoRoot, 'public/app-main.min.js');
const indexPath = path.join(repoRoot, 'public/index.html');

test('browser entry is the deterministic minified form of the canonical source', async () => {
  const source = fs.readFileSync(sourcePath, 'utf8');
  const artifact = fs.readFileSync(artifactPath, 'utf8');
  const rebuilt = await buildFrontendEntry(source);

  assert.equal(artifact, rebuilt);
  assert.ok(Buffer.byteLength(artifact) < Buffer.byteLength(source) * 0.7);
  assert.ok(gzipSync(artifact, { level: 1 }).length < gzipSync(source, { level: 1 }).length * 0.8);
  assert.doesNotThrow(() => new Function(artifact));
});

test('authenticated asset manifest loads only the hashed minified entry at the end of the application chain', () => {
  const html = fs.readFileSync(indexPath, 'utf8');
  const artifact = fs.readFileSync(artifactPath, 'utf8');
  const hash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const authenticatedReferences = extractAuthenticatedAssetReferences(html);
  const authenticatedAssets = authenticatedReferences.map(stripFrontendAssetQuery);
  const entryReference = authenticatedReferences.find(
    (reference) => stripFrontendAssetQuery(reference) === 'app-main.min.js',
  );

  assert.match(entryReference, new RegExp(`^app-main\\.min\\.js\\?v=[^"]*-h${hash}$`));
  assert.doesNotMatch(html, /(?:src="|\")app-main\.js\?/);
  assert.equal(authenticatedAssets[0], 'vue.runtime.global.prod.js');
  assert.equal(authenticatedAssets.at(-2), 'app-render.min.js');
  assert.equal(authenticatedAssets.at(-1), 'app-main.min.js');
});
