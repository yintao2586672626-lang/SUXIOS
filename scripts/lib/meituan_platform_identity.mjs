const PLATFORM_IDENTITY_KEYS = new Set(['poiid', 'storeid', 'shopid', 'mtpoiid']);

function normalizeIdentifier(value) {
  if (typeof value !== 'string' && typeof value !== 'number') return '';
  const text = String(value).trim();
  if (!text || text === '0' || text === '-1' || text.toLowerCase() === 'null') return '';
  return text;
}

function normalizeIdentityKey(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function collectFromValue(value, output) {
  if (Array.isArray(value)) {
    for (const item of value) collectFromValue(item, output);
    return;
  }
  if (!value || typeof value !== 'object') return;
  for (const [key, child] of Object.entries(value)) {
    if (PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) {
      const identifier = normalizeIdentifier(child);
      if (identifier) output.add(identifier);
    }
    collectFromValue(child, output);
  }
}

export function collectMeituanPlatformIdentifiers(value) {
  const identifiers = new Set();
  collectFromValue(value, identifiers);
  return Array.from(identifiers);
}

export function extractMeituanRequestPlatformIdentifiers(url, payload = '') {
  const identifiers = new Set();
  try {
    const parsedUrl = new URL(String(url || ''));
    for (const [key, value] of parsedUrl.searchParams.entries()) {
      if (!PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) continue;
      const identifier = normalizeIdentifier(value);
      if (identifier) identifiers.add(identifier);
    }
  } catch {
    // Invalid or redacted URLs simply contribute no identity evidence.
  }

  const payloadText = String(payload || '').trim();
  if (payloadText) {
    try {
      collectFromValue(JSON.parse(payloadText), identifiers);
    } catch {
      try {
        const params = new URLSearchParams(payloadText);
        for (const [key, value] of params.entries()) {
          if (!PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) continue;
          const identifier = normalizeIdentifier(value);
          if (identifier) identifiers.add(identifier);
        }
      } catch {
        // Non-JSON/non-form payloads are not identity evidence.
      }
    }
  }

  return Array.from(identifiers);
}

export function isMeituanOwnHotelPayloadKey(payloadKey) {
  return new Set([
    'businessData',
    'traffic',
    'flowAnalysis',
    'order_flow',
    'searchKeywords',
    'trafficForecast',
    'roomTypes',
    'orders',
    'reviews',
    'ads',
  ]).has(String(payloadKey || ''));
}

export function evaluateMeituanPlatformIdentity(expectedIdentifiers, observedIdentifiers) {
  const expected = Array.from(new Set((Array.isArray(expectedIdentifiers) ? expectedIdentifiers : [])
    .map(normalizeIdentifier)
    .filter(Boolean)));
  const observed = Array.from(new Set((Array.isArray(observedIdentifiers) ? observedIdentifiers : [])
    .map(normalizeIdentifier)
    .filter(Boolean)));
  const expectedSet = new Set(expected);
  const matched = observed.filter(identifier => expectedSet.has(identifier));
  const mismatched = observed.filter(identifier => !expectedSet.has(identifier));

  let status = 'matched';
  if (expected.length === 0) status = 'expected_missing';
  else if (observed.length === 0) status = 'unverified';
  else if (matched.length === 0) status = 'mismatch';
  else if (mismatched.length > 0 || matched.length > 1) status = 'ambiguous';

  return {
    schema_version: 1,
    status,
    source_validation: status === 'matched',
    evidence_source: 'ota_request_or_own_response',
    expected_identifier_count: expected.length,
    observed_identifier_count: observed.length,
    matched_identifier_count: matched.length,
    mismatched_identifier_count: mismatched.length,
    validated_identifier: status === 'matched' ? matched[0] : '',
  };
}
