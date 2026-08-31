import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const service = readFileSync('app/service/RevenuePricingRecommendationService.php', 'utf8');
const fragment = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');

test('price suggestion list distinguishes frozen new decisions from legacy rows', () => {
  assert.match(service, /describePriceSuggestionDecisionAttestation/);
  assert.match(service, /'status' => 'attested'/);
  assert.match(service, /'status' => 'legacy_reconstructed'/);
  assert.match(service, /'status_label' => '决策输入已冻结'/);
  assert.match(service, /'status_label' => '历史建议·未冻结证明'/);
  assert.match(service, /'automatic_price_write' => false/);
  assert.match(fragment, /item\.decision_attestation\?\.status_label/);
  assert.match(fragment, /item\.decision_attestation\.decision_as_of_time/);
  assert.match(fragment, /item\.decision_attestation\.source_ref_count/);
});
