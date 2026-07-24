export const AUTHENTICATED_ASSET_MANIFEST_ID = 'suxi-authenticated-assets';
export const AUTHENTICATED_ASSET_PHASE_STARTUP = 'startup';
export const AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT = 'after-first-paint';
export const AUTHENTICATED_ASSET_TYPE_SCRIPT = 'script';
export const AUTHENTICATED_ASSET_TYPE_STYLE = 'style';

const AUTHENTICATED_ASSET_PHASES = new Set([
  AUTHENTICATED_ASSET_PHASE_STARTUP,
  AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT,
]);
const AUTHENTICATED_ASSET_TYPES = new Set([
  AUTHENTICATED_ASSET_TYPE_SCRIPT,
  AUTHENTICATED_ASSET_TYPE_STYLE,
]);

const authenticatedAssetManifestPattern = () => new RegExp(
  `<script\\s+type=["']application/json["']\\s+id=["']${AUTHENTICATED_ASSET_MANIFEST_ID}["'][^>]*>([\\s\\S]*?)<\\/script>`,
  'gi',
);

export function stripFrontendAssetQuery(reference = '') {
  return String(reference || '').split(/[?#]/, 1)[0];
}

export function extractAuthenticatedAssetEntries(html = '') {
  const matches = [...String(html || '').matchAll(authenticatedAssetManifestPattern())];
  if (!matches.length) return [];
  if (matches.length !== 1) {
    throw new Error(`Authenticated frontend asset manifest must appear exactly once; found ${matches.length}.`);
  }
  const match = matches[0];

  let payload;
  try {
    payload = JSON.parse(match[1]);
  } catch (error) {
    throw new Error(`Invalid authenticated frontend asset manifest: ${error.message}`);
  }
  if (!Array.isArray(payload)) {
    throw new Error('Authenticated frontend asset manifest must be a JSON array.');
  }
  if (!payload.length) {
    throw new Error('Authenticated frontend asset manifest must not be empty.');
  }

  const entries = payload.map((item) => {
    const src = String(typeof item === 'string' ? item : item?.src || '').trim();
    const phase = String(
      typeof item === 'string' ? AUTHENTICATED_ASSET_PHASE_STARTUP : item?.phase || AUTHENTICATED_ASSET_PHASE_STARTUP,
    ).trim();
    const type = String(
      typeof item === 'string' ? AUTHENTICATED_ASSET_TYPE_SCRIPT : item?.type || AUTHENTICATED_ASSET_TYPE_SCRIPT,
    ).trim();
    return { src, phase, type };
  });
  if (entries.some((entry) => !entry.src)) {
    throw new Error('Authenticated frontend asset manifest contains an empty asset reference.');
  }
  const invalidPhase = entries.find((entry) => !AUTHENTICATED_ASSET_PHASES.has(entry.phase));
  if (invalidPhase) {
    throw new Error(`Authenticated frontend asset manifest contains an invalid phase: ${invalidPhase.phase}.`);
  }
  const invalidType = entries.find((entry) => !AUTHENTICATED_ASSET_TYPES.has(entry.type));
  if (invalidType) {
    throw new Error(`Authenticated frontend asset manifest contains an invalid type: ${invalidType.type}.`);
  }
  return entries;
}

export function extractAuthenticatedAssetReferences(html = '') {
  return extractAuthenticatedAssetEntries(html).map((entry) => entry.src);
}

export function extractAuthenticatedStartupAssetReferences(html = '') {
  return extractAuthenticatedAssetEntries(html)
    .filter((entry) => entry.phase === AUTHENTICATED_ASSET_PHASE_STARTUP)
    .map((entry) => entry.src);
}

export function extractAuthenticatedDeferredAssetReferences(html = '') {
  return extractAuthenticatedAssetEntries(html)
    .filter((entry) => entry.phase === AUTHENTICATED_ASSET_PHASE_AFTER_FIRST_PAINT)
    .map((entry) => entry.src);
}

export function resolveFrontendRuntimeAssetReferences(html = '') {
  const authenticated = extractAuthenticatedAssetEntries(html)
    .filter((entry) => entry.type === AUTHENTICATED_ASSET_TYPE_SCRIPT)
    .map((entry) => entry.src);
  if (authenticated.length) return authenticated;
  return [...String(html || '').matchAll(/<script\b[^>]*\bdefer\b[^>]*><\/script>/gi)]
    .map((match) => match[0].match(/\bsrc=(["'])([^"']+)\1/i)?.[2] || '')
    .filter(Boolean);
}

export function requireUniqueFrontendRuntimeAssetReference(html = '', assetName = '') {
  const normalizedAssetName = stripFrontendAssetQuery(assetName).trim();
  if (!normalizedAssetName) throw new Error('Frontend runtime asset name is required.');

  const matches = resolveFrontendRuntimeAssetReferences(html)
    .filter((reference) => stripFrontendAssetQuery(reference) === normalizedAssetName);
  if (matches.length !== 1) {
    throw new Error(
      `Frontend runtime chain must reference ${normalizedAssetName} exactly once; found ${matches.length}.`,
    );
  }
  return matches[0];
}
