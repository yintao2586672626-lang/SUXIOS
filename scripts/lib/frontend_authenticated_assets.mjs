export const AUTHENTICATED_ASSET_MANIFEST_ID = 'suxi-authenticated-assets';

const authenticatedAssetManifestPattern = () => new RegExp(
  `<script\\s+type=["']application/json["']\\s+id=["']${AUTHENTICATED_ASSET_MANIFEST_ID}["'][^>]*>([\\s\\S]*?)<\\/script>`,
  'gi',
);

export function stripFrontendAssetQuery(reference = '') {
  return String(reference || '').split(/[?#]/, 1)[0];
}

export function extractAuthenticatedAssetReferences(html = '') {
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

  const references = payload.map((item) => (
    typeof item === 'string' ? item : item?.src
  )).map((item) => String(item || '').trim()).filter(Boolean);
  if (references.length !== payload.length) {
    throw new Error('Authenticated frontend asset manifest contains an empty asset reference.');
  }
  return references;
}

export function resolveFrontendRuntimeAssetReferences(html = '') {
  const authenticated = extractAuthenticatedAssetReferences(html);
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
