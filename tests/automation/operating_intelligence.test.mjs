import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const migration = read('database/migrations/20260802_extend_operating_intelligence.sql');
const questions = read('app/service/OperatingQuestionService.php');
const sops = read('app/service/OperatingSopService.php');
const controller = read('app/controller/OperatingIntelligence.php');
const routes = read('route/app.php');
const frontend = read('public/app-main.js');
const agentPage = read('resources/frontend/templates/fragments/27-page-agent-center.html');

test('unified Agent operating question saves and performs an exact second readback', () => {
  assert.match(routes, /agent[\s\S]*operating-questions/);
  assert.match(controller, /OperatingQuestionService/);
  assert.match(questions, /deterministic_saved_evidence/);
  assert.match(questions, /readback_verified/);
  assert.match(questions, /blocked_by_missing_facts/);
  assert.match(frontend, /\/agent\/operating-questions/);
  assert.match(frontend, /operating-question-readback-error/);
  assert.match(frontend, /content_digest/);
  assert.match(agentPage, /<oq><\/oq>/);
  assert.match(frontend, /data-testid="operating-question-entry"/);
});

test('question evidence keeps facts, memory, knowledge, Agent and execution references separate', () => {
  for (const marker of [
    'fact_refs_json',
    'memory_refs_json',
    'knowledge_refs_json',
    'execution_refs_json',
  ]) {
    assert.ok(migration.includes(marker), `migration missing ${marker}`);
    assert.ok(questions.includes(marker.replace('_json', '')) || questions.includes(marker), `service missing ${marker}`);
  }
  assert.match(questions, /saved_verified_fact_missing/);
  assert.match(questions, /external_llm_called' => false/);
  assert.match(questions, /'ota_write' => false/);
  assert.match(questions, /'external_message' => false/);
});

test('SOP versions require repeated positive review memories and remain immutable', () => {
  assert.match(sops, /MIN_VERIFICATION_MEMORIES = 3/);
  assert.match(sops, /positive_outcome_verified/);
  assert.match(sops, /sop_candidate_ready/);
  assert.match(sops, /count\(\$businessDates\) < 2/);
  assert.match(sops, /previous_version_id/);
  assert.match(sops, /expected_candidate_digest/);
  assert.match(sops, /候选SOP已被处理或已不是当前有效候选/);
  assert.match(sops, /leaves the last verified/);
  assert.match(sops, /versionContent\(\$version\)/);
  assert.match(sops, /validation_status' => \$decision === 'verify' \? 'verified' : 'rejected'/);
  assert.match(routes, /operating-sops\/:id\/validate/);
});

test('cross-hotel replication is same-tenant draft-only and never reuses source facts', () => {
  assert.match(sops, /assertHotelIdentity\(\$tenantId, \$targetHotelId\)/);
  assert.match(sops, /reference_only_not_reused_as_target_fact/);
  assert.match(sops, /draft_pending_target_validation/);
  assert.match(sops, /blocked_missing_target_facts/);
  assert.match(sops, /target_hotel_comparable_fact_missing/);
  assert.match(sops, /whereBetween\('data_date'/);
  assert.match(sops, /whereIn\('data_type'/);
  assert.match(sops, /'target_verified' => false/);
  assert.match(sops, /'automatic_execution' => false/);
  assert.match(routes, /operating-sops\/:id\/replications/);
  assert.doesNotMatch(sops, /manual-notification|wecom|price-write|auto-fetch/i);
});
