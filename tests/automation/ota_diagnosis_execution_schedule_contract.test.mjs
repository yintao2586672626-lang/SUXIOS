import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(file, 'utf8');
const appMain = read('public/app-main.js');
const agentPage = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const opsTrackPage = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const controller = read('app/controller/Agent.php');
const userController = read('app/controller/User.php');
const operationService = read('app/service/OperationManagementService.php');

test('saved OTA diagnosis requires an assigned execution and review schedule before handoff', () => {
  assert.match(agentPage, /data-testid="ota-diagnosis-execution-schedule"/);
  assert.match(agentPage, /otaDiagnosisExecutionSchedule\.assignee_id/);
  assert.match(agentPage, /otaDiagnosisExecutionSchedule\.due_at/);
  assert.match(agentPage, /otaDiagnosisExecutionSchedule\.review_at/);

  const start = appMain.indexOf('const createOtaDiagnosisExecutionIntent = async');
  const end = appMain.indexOf('const openSavedOtaDiagnosis = async', start);
  const handoff = appMain.slice(start, end);
  assert.match(handoff, /assignee_id: assigneeId/);
  assert.match(handoff, /due_at: dueAt/);
  assert.match(handoff, /review_at: reviewAt/);
  assert.match(handoff, /复核时间不能早于截止时间/);
});

test('OTA diagnosis execution schedule is persisted in target and evidence payloads', () => {
  assert.match(controller, /normalizeOtaDiagnosisExecutionSchedule/);
  assert.match(controller, /'workflow_schedule' => \$workflowSchedule/);
  assert.match(controller, /assertOtaDiagnosisExecutionAssigneeScope/);
  assert.match(controller, /human_assigned_schedule_requires_manual_approval_and_readback_review/);
  assert.match(controller, /\$atomicIdempotencyKey = \$idempotencyKey \. ':attempt:' \. \$retryAttempt/);
  assert.match(operationService, /\?string \$trustedIdempotencyKey = null/);
  assert.match(operationService, /replayTrustedExecutionIntent/);
  assert.match(operationService, /'idempotency_key'\] = \$idempotencyKey/);
});

test('assignee options and backend validation use operation execute permission for the selected hotel', () => {
  assert.match(appMain, /candidate\.operation_execute_hotel_ids/);
  assert.doesNotMatch(appMain, /userRoleIdentityKey\(candidate\) === 'admin' \|\| !hotelId/);
  assert.match(controller, /hasHotelPermission\(\$hotelId, 'operation\.execute'\)/);
  assert.match(userController, /'operation_execute_hotel_ids'/);
  assert.match(userController, /hasHotelPermission\(\$hotelId, 'operation\.execute'\)/);
  assert.match(controller, /workflow_schedule/);
  assert.match(opsTrackPage, /data-testid="operation-execution-schedule-readback"/);
  assert.match(opsTrackPage, /item\.assignment\.assignee_id/);
  assert.match(opsTrackPage, /item\.assignment\.due_at/);
  assert.match(opsTrackPage, /item\.assignment\.review_at/);
});
