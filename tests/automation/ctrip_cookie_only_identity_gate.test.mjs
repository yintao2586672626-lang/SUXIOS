import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const backend = readFileSync(new URL('../../app/controller/concern/OnlineDataManualFetchConcern.php', import.meta.url), 'utf8');
const validator = backend.match(/private function validateCtripManualBusinessHotelIdentity[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);

test('Ctrip manual fetch blocks saving when platform hotelId is missing', () => {
  assert.ok(validator, 'identity validator should be present');
  const body = validator[0];

  assert.match(body, /expected_platform_hotel_id_missing/);
  assert.match(body, /\$expectedIds === \[\]/);
  assert.match(body, /\$capturedIds === \[\]/);
  assert.match(body, /array_intersect\(\$expectedIds, \$capturedIds\) === \[\]/);
  assert.doesNotMatch(body, /cookie_only_without_platform_hotel_id/);
  assert.doesNotMatch(body, /cookie_only_platform_hotel_unconfigured/);
});
