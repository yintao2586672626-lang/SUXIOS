import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const controller = readFileSync('app/controller/concern/PlatformDataSourceConcern.php', 'utf8');

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  assert.ok(start >= 0, 'missing start marker: ' + startText);
  const end = source.indexOf(endText, start);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const platformRowBuilder = sliceBetween(
  controller,
  'private function buildCollectionStatusPlatformRow',
  'private function summarizeCollectionStatusPlatforms'
);

test('collection-status adds canonical OTA quality state without replacing legacy status fields', () => {
  assert.match(controller, /use app\\service\\OtaCollectionQualityStateService;/);
  assert.match(platformRowBuilder, /\(new OtaCollectionQualityStateService\(\)\)->evaluate\(\[/);
  assert.match(platformRowBuilder, /'quality'\s*=>\s*\$quality/);
  assert.match(platformRowBuilder, /'collectionStatus'\s*=>\s*\$collectionStatus/);
  assert.match(platformRowBuilder, /'failureReason'\s*=>\s*\$failureReason/);
});

test('collection-status quality input carries only evidence summaries', () => {
  for (const marker of [
    'binding_contract_status',
    'binding_check_status',
    'binding_missing_requirements',
    'profile_status',
    'collection_status',
    'target_date',
    'latest_data_date',
    'target_date_traffic_rows',
    'field_fact_status',
    'failure_reason',
  ]) {
    assert.match(platformRowBuilder, new RegExp("['\"]" + marker + "['\"]"), 'missing quality input ' + marker);
  }

  assert.doesNotMatch(platformRowBuilder, /raw_payload|secret_json|cookies|token|password/i);
});
