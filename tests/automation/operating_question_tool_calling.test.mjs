import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const controller = read('app/controller/OperatingIntelligence.php');
const questionService = read('app/service/OperatingQuestionService.php');
const toolService = read('app/service/OperatingQuestionToolCallingService.php');
const unifiedEvidence = read('app/service/OperatingQuestionUnifiedEvidenceService.php');
const aiAnswer = read('app/service/OperatingQuestionAiAnswerService.php');
const appMain = read('public/app-main.js');
const components = read('public/components/system/operating-intelligence-components.js');

test('operating question API accepts only explicitly selected media evidence ids', () => {
  assert.match(controller, /media_evidence_ids/);
  assert.match(appMain, /media_evidence_ids:\s*mediaEvidenceIds/);
  assert.match(appMain, /local_media_extractions#/);
  assert.match(components, /仅显式勾选的记录进入下一次问答/);
  assert.match(components, /local-media-use-in-question/);
  assert.match(questionService, /array_slice\(array_unique\(array_filter\(array_map/);
});

test('model-assisted tool selection is host allowlisted and all receipts are read-only', () => {
  for (const tool of ['retrieve_knowledge', 'retrieve_operating_memory', 'retrieve_media_evidence']) {
    assert.ok(toolService.includes(`'${tool}'`), `missing allowlisted tool ${tool}`);
  }
  assert.match(toolService, /agent_tool_call_receipt\.v1/);
  assert.match(toolService, /tool_not_allowed/);
  assert.match(toolService, /'database_write' => false/);
  assert.match(toolService, /'external_write' => false/);
  assert.match(toolService, /'automatic_execution' => false/);
  assert.match(questionService, /tool_call_receipts/);
  assert.match(components, /operating-question-tool-receipts/);
});

test('knowledge memory and explicit media share one provenance preserving evidence plane', () => {
  assert.match(toolService, /operating_question_evidence_plane\.v1/);
  assert.match(unifiedEvidence, /operating_question_unified_evidence\.v1/);
  assert.match(unifiedEvidence, /hotel_scoped_user_selected_local_media/);
  assert.match(unifiedEvidence, /reference_only_until_human_confirmed/);
  assert.match(unifiedEvidence, /created_by/);
  assert.match(unifiedEvidence, /content_digest/);
  assert.match(aiAnswer, /media_context/);
  assert.match(aiAnswer, /unified_evidence_context/);
  assert.match(aiAnswer, /不能独立证明经营事实/);
  assert.match(questionService, /evidence_plane/);
  assert.match(questionService, /operating-question:v6/);
});
