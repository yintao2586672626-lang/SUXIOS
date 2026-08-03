import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8');
const page = fs.readFileSync(
  path.join(root, 'resources/frontend/templates/fragments/20-page-knowledge-center.html'),
  'utf8',
);

test('formal promotion workbench uses the authenticated production API surface', () => {
  for (const endpoint of [
    '/knowledge/promotions?',
    '/knowledge/promotions/from-sop-candidate',
    '/knowledge/promotions/${candidateId}',
    '/knowledge/promotions/${candidateId}/events',
    '/knowledge/promotions/${candidate.id}/revisions',
    '/knowledge/promotions/${candidate.id}/submit',
    '/knowledge/promotions/${candidate.id}/review',
    '/knowledge/promotions/${candidate.id}/withdraw',
    '/operation/operating-sops?hotel_id=${hotelId}',
    '/operation/operating-memories?hotel_id=${hotelId}',
  ]) {
    assert.ok(appMain.includes(endpoint), `missing production endpoint ${endpoint}`);
  }
  assert.match(appMain, /method:\s*'POST'/);
  assert.match(appMain, /idempotency_key:\s*knowledgePromotionRequestId\(\)/);
});

test('every load and write is isolated by auth session, page, hotel and action epoch', () => {
  assert.match(appMain, /const captureKnowledgePromotionContext = \(kind = 'load'\)/);
  assert.match(appMain, /session:\s*captureAuthSession\(\)/);
  assert.match(appMain, /context\.page === 'knowledge-center'/);
  assert.match(appMain, /currentPage\.value === context\.page/);
  assert.match(appMain, /Number\(knowledgePromotionHotelId\.value \|\| 0\) === Number\(context\.hotelId \|\| 0\)/);
  assert.match(appMain, /knowledgePromotionActionEpoch/);
  assert.match(appMain, /knowledgePromotionLoadEpoch/);
  assert.match(appMain, /isAuthSessionCurrent\(context\.session\)/);
  assert.match(appMain, /if \(!context\.session\?\.token \|\| !isKnowledgePromotionContextCurrent\(context\)\) return null/);
});

test('POST success is closed only after strict candidate and append-only event GET readback', () => {
  assert.match(appMain, /payload\.persistence_status !== 'readback_verified'/);
  assert.match(appMain, /readKnowledgePromotionActionSnapshot/);
  assert.match(appMain, /Promise\.all\(\[\s*request\(`\/knowledge\/promotions\/\$\{candidateId\}`\),\s*request\(`\/knowledge\/promotions\/\$\{candidateId\}\/events`\)/s);
  for (const field of [
    'current_revision_id',
    'current_revision_no',
    'row_version',
    'promoted_sop_version_id',
    'promoted_knowledge_unit_id',
    'promoted_knowledge_chunk_id',
    'source_digest',
    'content_digest',
    'submitted_by',
    'submitted_at',
  ]) {
    assert.ok(appMain.includes(`'${field}'`), `strict readback omits ${field}`);
  }
  for (const field of [
    'steps',
    'stop_conditions',
    'applicability',
    'scope',
    'evidence_refs',
    'outcome_refs',
    'conflict_refs',
  ]) {
    assert.ok(appMain.includes(`'${field}'`), `revision content readback omits ${field}`);
  }
  assert.match(appMain, /actualCandidate\.event_count/);
  assert.match(appMain, /events\.some\(\(event\) => Number\(event\.candidate_id \|\| 0\) !== candidateId\)/);
  assert.match(appMain, /expectedProjection\.integrity_status !== 'verified'/);
  assert.match(appMain, /actualProjection\.is_current !== true/);
});

test('approval evidence gate mirrors the backend eligibility boundary without replacing it', () => {
  for (const condition of [
    "String(memory?.memory_layer || '') !== 'execution_review'",
    "String(memory?.quality_status || '') !== 'verified'",
    "String(memory?.usage_level || '') !== 'decision_support'",
    "String(memory?.lifecycle_status || '') !== 'active'",
    'context.outcome_verified !== true',
    'context.positive_outcome_verified !== true',
    'context.sop_candidate_ready !== true',
  ]) {
    assert.ok(appMain.includes(condition), `eligible memory gate omits ${condition}`);
  }
  assert.match(appMain, /selected\.length >= 3 && taskIds\.size >= 3 && businessDates\.size >= 2/);
  assert.match(appMain, /decision === 'approve' && !knowledgePromotionApprovalGate\.value\.ready/);
  assert.match(appMain, /evidence_memory_ids:\s*decision === 'approve'/);
  assert.match(page, /没有同门店、同平台\/范围、已核验且正向的执行复盘记忆，批准保持禁用/);
  assert.match(page, /:disabled="!!knowledgePromotionAction \|\| !knowledgePromotionApprovalGate\.ready"/);
});

test('the workbench keeps formal knowledge boundaries explicit and shows truthful zero states', () => {
  for (const boundary of [
    'runtime_json_is_formal_source',
    'causality_verified',
    'automatic_execution',
    'ota_write',
    'external_message',
    'knowledge_write_before_approval',
  ]) {
    assert.ok(appMain.includes(`'${boundary}'`), `missing write boundary ${boundary}`);
  }
  assert.match(page, /正式知识晋级审核台/);
  assert.match(page, /数据库正式台账/);
  assert.match(page, /当前门店没有有效候选SOP版本/);
  assert.match(page, /当前筛选下没有正式晋级候选/);
  assert.match(page, /不会用普通知识、运行时 JSON 或其他门店数据替代/);
  assert.match(page, /不会自动执行、不会写 OTA、不会外发消息/);
});

test('all requested human lifecycle controls are reachable on the unified page', () => {
  for (const handler of [
    'createKnowledgePromotionCandidate',
    'saveKnowledgePromotionRevision',
    'submitKnowledgePromotionCandidate',
    "reviewKnowledgePromotionCandidate('request_changes')",
    "reviewKnowledgePromotionCandidate('reject')",
    "reviewKnowledgePromotionCandidate('approve')",
    'withdrawKnowledgePromotionCandidate',
  ]) {
    assert.ok(page.includes(handler), `missing lifecycle control ${handler}`);
  }
  assert.match(page, /正式回写：SOP版本/);
  assert.match(page, /晋级事件（只追加）/);
  assert.match(page, /停用正式SOP与知识版本/);
});
