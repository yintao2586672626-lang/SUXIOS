import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  evaluateCtripPlatformIdentity,
  extractCtripRequestPlatformIdentifiers,
} from '../../scripts/lib/ctrip_platform_identity.mjs';

test('extracts only Ctrip hotel identifiers from an observed OTA request', () => {
  assert.deepEqual(
    extractCtripRequestPlatformIdentifiers(
      'https://ebooking.ctrip.com/api/report?masterHotelId=130079194&token=secret',
      JSON.stringify({ query: { hotel_id: '130079194' }, system_hotel_id: 80, profile_id: '6866634' }),
    ),
    ['130079194'],
  );
  assert.deepEqual(
    extractCtripRequestPlatformIdentifiers('https://ebooking.ctrip.com/api/report', 'hotelId=130079194&date=2026-07-21'),
    ['130079194'],
  );
  assert.deepEqual(
    extractCtripRequestPlatformIdentifiers(
      'https://ebooking.ctrip.com/api/report',
      'request=%7B%22context%22%3A%7B%22masterHotelId%22%3A%22130079194%22%7D%7D',
    ),
    ['130079194'],
  );
  assert.deepEqual(
    extractCtripRequestPlatformIdentifiers(
      'https://ebooking.ctrip.com/api/report',
      JSON.stringify({ request: JSON.stringify({ hotel_id: '130079194' }) }),
      { headers: { authorization: 'Bearer must-not-be-read', 'hotel-id': '130079194' } },
    ),
    ['130079194'],
  );
});

test('requires an exact and unambiguous observed Ctrip hotel match', () => {
  const matched = evaluateCtripPlatformIdentity(['130079194'], ['130079194']);
  assert.equal(matched.status, 'matched');
  assert.equal(matched.source_validation, true);
  assert.equal(matched.validated_identifier, '130079194');
  assert.equal(matched.evidence_source, 'ota_request');
  assert.equal(matched.sensitive_values_exposed, false);

  assert.equal(evaluateCtripPlatformIdentity(['130079194'], []) .status, 'unverified');
  assert.equal(evaluateCtripPlatformIdentity(['130079194'], ['999']) .status, 'mismatch');
  assert.equal(evaluateCtripPlatformIdentity(['130079194'], ['130079194', '999']) .status, 'ambiguous');
  assert.equal(evaluateCtripPlatformIdentity([], ['130079194']) .status, 'expected_missing');
});

test('keeps an exact Ctrip page-header name unverified when request identifiers are absent', () => {
  const exactName = 'Dunhuang Molan Club Wild Luxury Homestay (Mingsha Mountain & Crescent Spring Branch)';
  const matched = evaluateCtripPlatformIdentity(['130079194'], [], {
    expectedNames: [exactName],
    observedNames: [exactName],
    allowTrustedPageHeader: true,
  });
  assert.equal(matched.status, 'unverified');
  assert.equal(matched.source_validation, false);
  assert.equal(matched.evidence_source, 'trusted_ota_page_header');
  assert.equal(matched.validated_identifier, '');
  assert.equal(matched.validated_name, exactName);
  assert.equal(matched.observed_identifier_count, 0);
  assert.equal(matched.observed_name_count, 1);

  const caseMismatch = evaluateCtripPlatformIdentity(['130079194'], [], {
    expectedNames: [exactName],
    observedNames: [exactName.toLowerCase()],
    allowTrustedPageHeader: true,
  });
  assert.equal(caseMismatch.status, 'mismatch');

  const requestMismatchWins = evaluateCtripPlatformIdentity(['130079194'], ['999'], {
    expectedNames: [exactName],
    observedNames: [exactName],
    allowTrustedPageHeader: true,
  });
  assert.equal(requestMismatchWins.status, 'mismatch');
  assert.equal(requestMismatchWins.evidence_source, 'ota_request');

  assert.equal(evaluateCtripPlatformIdentity(['130079194'], [], {
    expectedNames: [exactName],
    observedNames: [exactName],
    allowTrustedPageHeader: false,
  }).status, 'unverified');

  const pageStateMatched = evaluateCtripPlatformIdentity(['130079194'], [], {
    expectedNames: [exactName],
    observedNames: [exactName],
    pageStateIdentifiers: ['130079194'],
    allowTrustedPageHeader: true,
  });
  assert.equal(pageStateMatched.status, 'matched');
  assert.equal(pageStateMatched.source_validation, true);
  assert.equal(pageStateMatched.evidence_source, 'trusted_ota_page_state');
  assert.equal(pageStateMatched.validated_identifier, '130079194');

  const ambiguousPageState = evaluateCtripPlatformIdentity(['130079194'], [], {
    expectedNames: [exactName],
    observedNames: [exactName],
    pageStateIdentifiers: ['130079194', '999'],
    allowTrustedPageHeader: true,
  });
  assert.equal(ambiguousPageState.status, 'ambiguous');
});

test('capture script wires request identity into login and collection proof', () => {
  const source = readFileSync(new URL('../../scripts/ctrip_browser_capture.mjs', import.meta.url), 'utf8');
  assert.match(source, /extractCtripRequestPlatformIdentifiers/);
  assert.match(source, /observeTrustedCtripPageHeaderIdentity/);
  assert.match(source, /probeTrustedCtripBusinessPageIdentity/);
  assert.match(source, /observeTrustedCtripPageStateIdentity/);
  assert.match(source, /PAGE_URLS\.business_overview\?\.\[0\]\?\.url/);
  assert.match(source, /platform_identity_validation\s*=\s*evaluateCtripPlatformIdentity/);
  assert.match(source, /identity_status:\s*platformIdentityValidation\?\.status/);
});
