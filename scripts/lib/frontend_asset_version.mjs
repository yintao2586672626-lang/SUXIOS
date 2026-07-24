import crypto from 'node:crypto';

export const FRONTEND_ASSET_HASH_LENGTH = 10;
export const FRONTEND_ASSET_HASH_PATTERN = /^[a-f0-9]{10}$/;

const escapeRegExp = (value) => String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

function normalizeAssetName(assetName) {
  const normalized = String(assetName || '').trim();
  if (!normalized || /[?#\s"'<>]/.test(normalized)) {
    throw new Error(`Invalid frontend asset name: ${String(assetName)}`);
  }
  return normalized;
}

function collectVersionedAssetMatches(html, assetName) {
  const source = String(html || '');
  const normalizedAssetName = normalizeAssetName(assetName);
  const pattern = new RegExp(
    `(^|["'\\s=])(${escapeRegExp(normalizedAssetName)}\\?v=)([^"'<>\\s]+)`,
    'gm',
  );
  return [...source.matchAll(pattern)].map((match) => ({
    index: Number(match.index || 0),
    matched: match[0],
    boundary: match[1],
    referencePrefix: match[2],
    version: match[3],
  }));
}

export function buildFrontendAssetHash(content) {
  const buffer = Buffer.isBuffer(content) ? content : Buffer.from(String(content ?? ''), 'utf8');
  return crypto.createHash('sha256').update(buffer).digest('hex').slice(0, FRONTEND_ASSET_HASH_LENGTH);
}

export function readFrontendAssetVersion(html, assetName) {
  const normalizedAssetName = normalizeAssetName(assetName);
  const matches = collectVersionedAssetMatches(html, normalizedAssetName);
  if (matches.length !== 1) {
    throw new Error(
      `Frontend entry must reference ${normalizedAssetName} exactly once with a version; found ${matches.length}.`,
    );
  }

  const match = matches[0];
  const suffix = match.version.match(/^(.*)-h([a-f0-9]{10})$/);
  if (!suffix || !suffix[1]) {
    throw new Error(
      `Frontend asset ${normalizedAssetName} must use a stable prefix followed by -h and exactly 10 lowercase SHA-256 characters.`,
    );
  }

  return {
    assetName: normalizedAssetName,
    reference: `${match.referencePrefix}${match.version}`,
    version: match.version,
    versionPrefix: suffix[1],
    hash: suffix[2],
    index: match.index,
  };
}

export function replaceFrontendAssetVersionHash(html, assetName, hash) {
  const source = String(html || '');
  const normalizedHash = String(hash || '');
  if (!FRONTEND_ASSET_HASH_PATTERN.test(normalizedHash)) {
    throw new Error(`Frontend asset hash must be exactly 10 lowercase hexadecimal characters: ${normalizedHash}`);
  }

  const current = readFrontendAssetVersion(source, assetName);
  const currentReference = `${current.assetName}?v=${current.version}`;
  const nextVersion = `${current.versionPrefix}-h${normalizedHash}`;
  const nextReference = `${current.assetName}?v=${nextVersion}`;
  const referenceOffset = source.indexOf(currentReference, current.index);
  if (referenceOffset < 0) {
    throw new Error(`Frontend asset reference changed while updating ${current.assetName}.`);
  }

  return {
    html: `${source.slice(0, referenceOffset)}${nextReference}${source.slice(referenceOffset + currentReference.length)}`,
    assetName: current.assetName,
    previousVersion: current.version,
    version: nextVersion,
    hash: normalizedHash,
    changed: current.hash !== normalizedHash,
  };
}

export function updateFrontendAssetVersion(html, assetName, content) {
  return replaceFrontendAssetVersionHash(html, assetName, buildFrontendAssetHash(content));
}
