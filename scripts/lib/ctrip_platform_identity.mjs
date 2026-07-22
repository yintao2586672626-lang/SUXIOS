const PLATFORM_IDENTITY_KEYS = new Set([
  'masterhotelid',
  'hotelid',
  'ctriphotelid',
  'otahotelid',
]);

function normalizeIdentifier(value) {
  if (typeof value !== 'string' && typeof value !== 'number') return '';
  const text = String(value).trim();
  if (!text || text === '0' || text === '-1' || text.toLowerCase() === 'null') return '';
  return text;
}

function normalizeIdentityKey(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function normalizeIdentityName(value) {
  if (typeof value !== 'string') return '';
  return value.replace(/\s+/gu, ' ').trim();
}

function collectFromValue(value, output, depth = 0, seen = new Set()) {
  if (depth > 8) return;
  if (typeof value === 'string') {
    const text = value.trim();
    if (!text || text.length > 1_000_000 || seen.has(text)) return;
    seen.add(text);
    if ((text.startsWith('{') && text.endsWith('}')) || (text.startsWith('[') && text.endsWith(']'))) {
      try {
        collectFromValue(JSON.parse(text), output, depth + 1, seen);
        return;
      } catch {
        // Continue with form-style parsing below.
      }
    }
    if (text.includes('=')) {
      try {
        for (const [key, child] of new URLSearchParams(text).entries()) {
          if (PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) {
            const identifier = normalizeIdentifier(child);
            if (identifier) output.add(identifier);
          } else {
            collectFromValue(child, output, depth + 1, seen);
          }
        }
      } catch {
        // Non-form strings are not identity evidence.
      }
    }
    return;
  }
  if (Array.isArray(value)) {
    for (const item of value) collectFromValue(item, output, depth + 1, seen);
    return;
  }
  if (!value || typeof value !== 'object') return;
  for (const [key, child] of Object.entries(value)) {
    if (PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) {
      const identifier = normalizeIdentifier(child);
      if (identifier) output.add(identifier);
    }
    collectFromValue(child, output, depth + 1, seen);
  }
}

export function extractCtripRequestPlatformIdentifiers(url, payload = '', metadata = {}) {
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

  const headers = metadata && typeof metadata === 'object' && metadata.headers && typeof metadata.headers === 'object'
    ? metadata.headers
    : {};
  for (const [key, value] of Object.entries(headers)) {
    if (!PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) continue;
    const identifier = normalizeIdentifier(value);
    if (identifier) identifiers.add(identifier);
  }

  const payloadText = String(payload || '').trim();
  if (payloadText) {
    try {
      collectFromValue(JSON.parse(payloadText), identifiers);
    } catch {
      try {
        const params = new URLSearchParams(payloadText);
        for (const [key, value] of params.entries()) {
          if (PLATFORM_IDENTITY_KEYS.has(normalizeIdentityKey(key))) {
            const identifier = normalizeIdentifier(value);
            if (identifier) identifiers.add(identifier);
          } else {
            collectFromValue(value, identifiers);
          }
        }
      } catch {
        // Non-JSON/non-form payloads are not identity evidence.
      }
    }
  }

  return Array.from(identifiers);
}

export function evaluateCtripPlatformIdentity(expectedIdentifiers, observedIdentifiers, options = {}) {
  const expected = Array.from(new Set((Array.isArray(expectedIdentifiers) ? expectedIdentifiers : [])
    .map(normalizeIdentifier)
    .filter(Boolean)));
  const observed = Array.from(new Set((Array.isArray(observedIdentifiers) ? observedIdentifiers : [])
    .map(normalizeIdentifier)
    .filter(Boolean)));
  const expectedSet = new Set(expected);
  const matched = observed.filter(identifier => expectedSet.has(identifier));
  const mismatched = observed.filter(identifier => !expectedSet.has(identifier));
  const pageStateObserved = Array.from(new Set((Array.isArray(options.pageStateIdentifiers) ? options.pageStateIdentifiers : [])
    .map(normalizeIdentifier)
    .filter(Boolean)));
  const pageStateMatched = pageStateObserved.filter(identifier => expectedSet.has(identifier));
  const pageStateMismatched = pageStateObserved.filter(identifier => !expectedSet.has(identifier));

  const expectedNames = Array.from(new Set((Array.isArray(options.expectedNames) ? options.expectedNames : [])
    .map(normalizeIdentityName)
    .filter(Boolean)));
  const observedNames = Array.from(new Set((Array.isArray(options.observedNames) ? options.observedNames : [])
    .map(normalizeIdentityName)
    .filter(Boolean)));
  const expectedNameSet = new Set(expectedNames);
  const matchedNames = observedNames.filter(name => expectedNameSet.has(name));
  const mismatchedNames = observedNames.filter(name => !expectedNameSet.has(name));

  const base = {
    schema_version: 1,
    expected_identifier_count: expected.length,
    observed_identifier_count: observed.length,
    matched_identifier_count: matched.length,
    mismatched_identifier_count: mismatched.length,
    observed_page_state_identifier_count: pageStateObserved.length,
    matched_page_state_identifier_count: pageStateMatched.length,
    mismatched_page_state_identifier_count: pageStateMismatched.length,
    expected_name_count: expectedNames.length,
    observed_name_count: observedNames.length,
    matched_name_count: matchedNames.length,
    mismatched_name_count: mismatchedNames.length,
    sensitive_values_exposed: false,
  };

  if (expected.length === 0) {
    return {
      ...base,
      status: 'expected_missing',
      source_validation: false,
      evidence_source: 'ota_request',
      validated_identifier: '',
      validated_name: '',
    };
  }

  if (observed.length > 0) {
    let status = 'matched';
    if (matched.length === 0) status = 'mismatch';
    else if (mismatched.length > 0 || matched.length > 1 || observed.length > 1) status = 'ambiguous';

    return {
      ...base,
      status,
      source_validation: status === 'matched',
      evidence_source: 'ota_request',
      validated_identifier: status === 'matched' ? matched[0] : '',
      validated_name: '',
    };
  }

  if (pageStateObserved.length > 0) {
    let status = 'matched';
    if (pageStateMatched.length === 0 || matchedNames.length === 0) status = 'mismatch';
    else if (pageStateMismatched.length > 0
      || pageStateMatched.length > 1
      || pageStateObserved.length > 1
      || mismatchedNames.length > 0
      || matchedNames.length > 1
      || observedNames.length > 1) status = 'ambiguous';

    return {
      ...base,
      status,
      source_validation: status === 'matched',
      evidence_source: 'trusted_ota_page_state',
      validated_identifier: status === 'matched' ? pageStateMatched[0] : '',
      validated_name: status === 'matched' ? matchedNames[0] : '',
    };
  }

  const trustedPageHeaderAllowed = options.allowTrustedPageHeader === true && expectedNames.length === 1;
  if (!trustedPageHeaderAllowed || observedNames.length === 0) {
    return {
      ...base,
      status: 'unverified',
      source_validation: false,
      evidence_source: trustedPageHeaderAllowed ? 'trusted_ota_page_header' : 'ota_request',
      validated_identifier: '',
      validated_name: '',
    };
  }

  let status = 'unverified';
  if (matchedNames.length === 0) status = 'mismatch';
  else if (mismatchedNames.length > 0 || matchedNames.length > 1 || observedNames.length > 1) status = 'ambiguous';

  return {
    ...base,
    status,
    source_validation: false,
    evidence_source: 'trusted_ota_page_header',
    validated_identifier: '',
    validated_name: status === 'unverified' ? matchedNames[0] : '',
  };
}
