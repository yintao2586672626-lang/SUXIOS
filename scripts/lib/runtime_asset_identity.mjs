import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { extractAuthenticatedAssetReferences, stripFrontendAssetQuery } from './frontend_authenticated_assets.mjs';

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const runtimeExtension = /\.(?:js|css|woff2?|ttf|otf|eot|svg|png|jpe?g|webp|avif|gif|ico)$/i;
const traversableRuntimeExtension = /\.(?:js|css)$/i;
const quotedRuntimeReference = /["'`]([^"'`?#]+\.(?:js|css|woff2?|ttf|otf|eot|svg|png|jpe?g|webp|avif|gif|ico))(?:\?[^"'`]*)?["'`]/gi;

const safePublicPath = (publicRoot, candidate) => {
  const normalized = String(candidate || '').replaceAll('\\', '/').replace(/^\/+/, '');
  if (!normalized || !runtimeExtension.test(normalized)) return '';
  const absolute = path.resolve(publicRoot, normalized);
  const relative = path.relative(publicRoot, absolute).replaceAll('\\', '/');
  return relative && !relative.startsWith('../') && !path.isAbsolute(relative) ? relative : '';
};

const resolveLocalReference = (publicRoot, currentRelativePath, reference) => {
  const stripped = stripFrontendAssetQuery(reference).trim();
  if (!stripped || /^(?:[a-z]+:)?\/\//i.test(stripped) || stripped.startsWith('data:')) return '';
  const rootCandidate = safePublicPath(publicRoot, stripped);
  if (rootCandidate && fs.existsSync(path.join(publicRoot, rootCandidate))) return rootCandidate;
  const relativeCandidate = safePublicPath(
    publicRoot,
    path.posix.join(path.posix.dirname(currentRelativePath), stripped),
  );
  return relativeCandidate && fs.existsSync(path.join(publicRoot, relativeCandidate))
    ? relativeCandidate
    : '';
};

export function discoverRuntimeAssetPaths(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const indexPath = path.join(publicRoot, 'index.html');
  const html = fs.readFileSync(indexPath, 'utf8');
  const discovered = new Set(['index.html']);
  const queue = [];
  const add = (currentPath, reference, { required = false } = {}) => {
    const resolved = resolveLocalReference(publicRoot, currentPath, reference);
    if (!resolved) {
      if (required && runtimeExtension.test(stripFrontendAssetQuery(reference))) {
        throw new Error(`Runtime asset reference is missing: ${currentPath} -> ${reference}`);
      }
      return;
    }
    if (discovered.has(resolved)) return;
    discovered.add(resolved);
    if (traversableRuntimeExtension.test(resolved)) queue.push(resolved);
  };

  for (const match of html.matchAll(/<(?:script|link)\b[^>]*(?:src|href)=["']([^"']+)["']/gi)) {
    add('index.html', match[1], { required: true });
  }
  for (const reference of extractAuthenticatedAssetReferences(html)) add('index.html', reference, { required: true });
  for (const match of html.matchAll(quotedRuntimeReference)) add('index.html', match[1], { required: true });

  for (let index = 0; index < queue.length; index += 1) {
    const current = queue[index];
    const source = fs.readFileSync(path.join(publicRoot, current), 'utf8');
    for (const match of source.matchAll(quotedRuntimeReference)) add(current, match[1]);
    if (current.endsWith('.css')) {
      // A font-face src list contains browser-format alternatives. Requiring
      // every URL would reject a valid modern bundle that intentionally ships
      // only woff2 while retaining a truetype fallback in vendor CSS. The
      // contract is that each font-face has at least one local usable source;
      // every source that is actually present still participates in identity.
      const fontSourceGroups = new Map();
      const declarationValue = (fontFace, property, fallback) => {
        const match = fontFace.match(new RegExp(`${property}\\s*:\\s*([^;}]+)`, 'i'));
        return String(match?.[1] || fallback).trim().replace(/^(["'])(.*)\1$/, '$2').toLowerCase();
      };
      const cssWithoutFontFaces = source.replace(/@font-face\s*\{[^}]*\}/gi, (fontFace) => {
        const references = [...fontFace.matchAll(/url\(\s*(["']?)([^"')]+)\1\s*\)/gi)]
          .map((match) => match[2]);
        const localReferences = references.filter((reference) => {
          const stripped = stripFrontendAssetQuery(reference).trim();
          return stripped && !/^(?:[a-z]+:)?\/\//i.test(stripped) && !stripped.startsWith('data:')
            && runtimeExtension.test(stripped);
        });
        const resolvedReferences = localReferences.filter((reference) => (
          Boolean(resolveLocalReference(publicRoot, current, reference))
        ));
        for (const reference of resolvedReferences) add(current, reference);
        const groupKey = [
          declarationValue(fontFace, 'font-family', '__anonymous_font_family__'),
          declarationValue(fontFace, 'font-style', 'normal'),
          declarationValue(fontFace, 'font-weight', 'normal'),
        ].join('|');
        const group = fontSourceGroups.get(groupKey) || { references: [], resolvedCount: 0 };
        group.references.push(...localReferences);
        group.resolvedCount += resolvedReferences.length;
        fontSourceGroups.set(groupKey, group);
        return ' '.repeat(fontFace.length);
      });
      for (const group of fontSourceGroups.values()) {
        if (group.references.length > 0 && group.resolvedCount === 0) {
          throw new Error(
            `Runtime asset reference group is missing: ${current} -> ${[...new Set(group.references)].join(', ')}`,
          );
        }
      }
      for (const match of cssWithoutFontFaces.matchAll(/@import\s+(?:url\(\s*)?["']?([^"')\s;]+)["']?\s*\)?/gi)) {
        add(current, match[1], { required: true });
      }
      for (const match of cssWithoutFontFaces.matchAll(/url\(\s*(["']?)([^"')]+)\1\s*\)/gi)) {
        add(current, match[2], { required: true });
      }
    }
  }

  return [...discovered].sort().map((relativePath) => `public/${relativePath}`);
}

export function captureRuntimeAssetIdentity(repoRoot) {
  const files = discoverRuntimeAssetPaths(repoRoot).map((relativePath) => {
    const content = fs.readFileSync(path.join(repoRoot, relativePath));
    return { path: relativePath, bytes: content.length, sha256: sha256(content) };
  });
  return {
    schema_version: 1,
    algorithm: 'sha256',
    digest: sha256(files.map((file) => `${file.path}\0${file.bytes}\0${file.sha256}`).join('\n')),
    files,
  };
}

export async function verifyServedRuntimeAssetIdentity(
  baseUrl,
  localIdentity,
  fetchImpl = globalThis.fetch,
) {
  if (typeof fetchImpl !== 'function') throw new Error('A fetch implementation is required.');
  const base = new URL(String(baseUrl || ''));
  const failures = [];
  const remoteFiles = [];
  for (const expected of localIdentity.files || []) {
    const relativePath = expected.path === 'public/index.html'
      ? ''
      : expected.path.replace(/^public\//, '');
    const url = new URL(relativePath, base);
    url.searchParams.set('suxi_runtime_identity', localIdentity.digest.slice(0, 12));
    try {
      const response = await fetchImpl(url, { cache: 'no-store' });
      if (!response.ok) {
        failures.push(`runtime_asset_http_${response.status}:${expected.path}`);
        continue;
      }
      const content = Buffer.from(await response.arrayBuffer());
      const observed = { path: expected.path, bytes: content.length, sha256: sha256(content) };
      remoteFiles.push(observed);
      if (observed.bytes !== expected.bytes || observed.sha256 !== expected.sha256) {
        failures.push(`runtime_asset_identity_mismatch:${expected.path}`);
      }
    } catch (error) {
      failures.push(`runtime_asset_fetch_failed:${expected.path}:${error.message}`);
    }
  }
  const remoteDigest = remoteFiles.length === (localIdentity.files || []).length
    ? sha256(remoteFiles.map((file) => `${file.path}\0${file.bytes}\0${file.sha256}`).join('\n'))
    : '';
  if (remoteDigest && remoteDigest !== localIdentity.digest) {
    failures.push('runtime_asset_manifest_digest_mismatch');
  }
  return {
    failures: [...new Set(failures)].sort(),
    local_digest: localIdentity.digest,
    remote_digest: remoteDigest || null,
    expected_asset_count: (localIdentity.files || []).length,
    fetched_asset_count: remoteFiles.length,
  };
}
