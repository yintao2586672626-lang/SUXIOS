import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const backend = readFileSync(new URL('../../app/controller/concern/OnlineDataManualFetchConcern.php', import.meta.url), 'utf8');
const validator = backend.match(/private function validateCtripManualBusinessHotelIdentity[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);

test('Ctrip manual fetch auto-binds only when returned self hotelId is trusted', () => {
  assert.ok(validator, 'identity validator should be present');
  const body = validator[0];

  assert.match(body, /expected_platform_hotel_id_missing/);
  assert.match(body, /\$expectedIds === \[\]/);
  assert.match(body, /resolveMissingCtripPlatformHotelIdFromCapturedData\(\$capturedIds, \$systemHotelId, \$targetHotelName\)/);
  assert.match(body, /private function resolveMissingCtripPlatformHotelIdFromCapturedData\(array \$capturedIds, int \$systemHotelId, string \$targetHotelName\): array/);
  assert.match(body, /\$capturedIds === \[\]/);
  assert.match(body, /count\(\$capturedIds\) > 1/);
  assert.match(body, /captured_platform_hotel_id_ambiguous/);
  assert.match(body, /findCtripSystemHotelMatchesByPlatformIds\(\$capturedIds\)/);
  assert.match(body, /findCtripPlatformHotelIdConflicts\(\$capturedIds, \$systemHotelId\)/);
  assert.match(body, /admin_allowed_platform_hotel_history_conflict/);
  assert.match(body, /admin_resolution/);
  assert.match(body, /HotelDataMergeService/);
  assert.match(body, /merge_preview_endpoint/);
  assert.match(body, /can_continue_current_fetch/);
  assert.match(body, /persistCtripResolvedPlatformHotelIdForSystemHotel\(\$systemHotelId, \$platformHotelId\)/);
  assert.match(body, /platform_hotel_id_auto_bind_failed/);
  assert.match(body, /auto_bound_platform_hotel_id/);
  assert.match(body, /array_intersect\(\$expectedIds, \$capturedIds\) === \[\]/);
  assert.doesNotMatch(body, /cookie_only_without_platform_hotel_id/);
  assert.doesNotMatch(body, /cookie_only_platform_hotel_unconfigured/);
});
