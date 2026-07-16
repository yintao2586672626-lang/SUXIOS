import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const service = readFileSync('app/service/OperationManagementService.php', 'utf8');
const controller = readFileSync('app/controller/OperationManagement.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const dailyReport = readFileSync('app/service/AiDailyReportService.php', 'utf8');
const frontend = readFileSync('public/app-main.js', 'utf8');

const block = (source, start, end) => {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `missing block start: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing block end: ${end}`);
  return source.slice(startIndex, endIndex);
};

const assertBefore = (source, earlier, later, message) => {
  const earlierIndex = source.indexOf(earlier);
  const laterIndex = source.indexOf(later);
  assert.notEqual(earlierIndex, -1, `missing: ${earlier}`);
  assert.notEqual(laterIndex, -1, `missing: ${later}`);
  assert.ok(earlierIndex < laterIndex, message);
};

const assertMatches = (source, pattern, message) => {
  assert.ok(pattern.test(source), message);
};

test('service and controller expose hotel-scoped intent/task resource reads', () => {
  assertMatches(service, /public function readExecutionIntent\s*\(/, 'service intent resource reader is missing');
  assertMatches(service, /public function readExecutionTask\s*\(/, 'service task resource reader is missing');
  assertMatches(controller, /public function readExecutionIntent\s*\(/, 'controller intent resource reader is missing');
  assertMatches(controller, /public function readExecutionTask\s*\(/, 'controller task resource reader is missing');
});

test('resource GET routes exist before the collection route', () => {
  const intentRead = "Route::get('/execution-intents/:id', 'OperationManagement/readExecutionIntent')";
  const taskRead = "Route::get('/execution-tasks/:id', 'OperationManagement/readExecutionTask')";
  const collectionRead = "Route::get('/execution-intents', 'OperationManagement/executionIntents')";

  assertBefore(routes, intentRead, collectionRead, 'intent resource route must precede the collection route');
  assertBefore(routes, taskRead, collectionRead, 'task resource route must precede the collection route');
});

test('controller maps validation to 422 and missing scoped resources to 404', () => {
  assertMatches(
    controller,
    /\$e instanceof \\InvalidArgumentException\)\s*\{\s*return 422;/s,
    'InvalidArgumentException must be an HTTP 422 response'
  );
  assertMatches(
    controller,
    /not found[\s\S]{0,240}return 404;/,
    'a missing or out-of-scope intent/task must be an HTTP 404 response'
  );
});

test('AI daily report stores an intent id only after strict post-create verification', () => {
  const method = block(
    dailyReport,
    'public function createExecutionIntentFromAction',
    'public function enrichReportRows'
  );
  assertMatches(method, /\(int\)\(\$intent\['id'\][\s\S]*?> 0/, 'AI bridge must require a positive intent id');
  assertMatches(method, /\$intent\['status'\][\s\S]*?pending_approval/, 'AI bridge must verify pending_approval');
  assertMatches(method, /\$intent\['blocked_reason'\][\s\S]*?=== ''/, 'AI bridge must verify an empty blocked_reason');
  assertBefore(
    method,
    "'pending_approval'",
    "['execution_intent_id']",
    'the pending/unblocked postcondition must be checked before persisting execution_intent_id'
  );
});

test('approval and execution persist state transitions with compare-and-set guards', () => {
  const approval = block(service, 'public function approveExecutionIntent', 'public function executeExecutionTask');
  const execution = block(service, 'public function executeExecutionTask', 'public function addExecutionEvidence');

  assertBefore(approval, "->where('status', 'pending_approval')", '->update([', 'approval update must compare the pending state');
  assertMatches(approval, /\$affected\s*!==\s*1/, 'approval must reject a lost compare-and-set race');
  assertBefore(execution, "->where('status', $expectedTaskStatus)", '->update($dbUpdate)', 'execution update must compare the previously read task state');
  assertMatches(execution, /\$affected\s*!==\s*1/, 'execution must reject a lost compare-and-set race');
});

test('daily report source references derive platform labels instead of hard-coding Ctrip', () => {
  const snapshot = block(dailyReport, 'private function buildSnapshot', 'private function sanitizeExecutionFlowForSnapshot');
  assertMatches(snapshot, /evidenceOtaPlatform\(\$evidence\)/, 'snapshot source platform must come from its evidence');
  assertMatches(snapshot, /'platform'\s*=>\s*\(string\)\(\$evidence\['platform'\]/, 'snapshot source reference must retain platform');
  assertMatches(snapshot, /default\s*=>\s*'OTA channel fact \(platform unknown\)'/, 'unknown platform must remain generic OTA');
});

test('frontend defines strict resource readback helpers', () => {
  assertMatches(frontend, /const readOperationExecutionIntent\s*=\s*async/, 'intent readback helper is missing');
  assertMatches(frontend, /const readOperationExecutionTask\s*=\s*async/, 'task readback helper is missing');
  assertMatches(frontend, /\/operation\/execution-intents\/\$\{intentId\}/, 'intent resource URL is missing');
  assertMatches(frontend, /\/operation\/execution-tasks\/\$\{taskId\}/, 'task resource URL is missing');
});

test('AI daily create verifies pending and unblocked intent before success toast', () => {
  const fn = block(frontend, 'const createAiDailyExecutionIntent', 'const loadOperationActions');
  assertBefore(fn, 'await readOperationExecutionIntent', "showToast('已生成执行意图", 'create readback must happen before success toast');
  assertMatches(fn, /pending_approval/, 'create flow must verify pending_approval');
  assertMatches(fn, /blocked_reason/, 'create flow must verify blocked_reason');
});

test('approval verifies intent status and generated task before success toast', () => {
  const fn = block(frontend, 'const approveOperationExecutionIntent', 'const recordOperationExecutionEvidence');
  assertBefore(fn, 'await readOperationExecutionIntent', 'showToast(', 'approval readback must happen before success toast');
  assertMatches(fn, /approved/, 'approval flow must verify approved state');
  assertMatches(fn, /tasks/, 'approval flow must verify the generated task');
});

test('both execution submit paths verify executed task with evidence before success toast', () => {
  const priceFn = block(frontend, 'const recordOperationExecutionEvidence', 'const submitOperationExecutionEvidence');
  const generalFn = block(frontend, 'const submitOperationExecutionEvidence', 'const recordOperationRoiEvidence');
  for (const fn of [priceFn, generalFn]) {
    assertBefore(fn, 'await readOperationExecutionTask', 'showToast(', 'execution readback must happen before success toast');
    assertMatches(fn, /executed/, 'execution flow must verify executed state');
    assertMatches(fn, /evidence/, 'execution flow must verify evidence');
  }
});

test('review verifies persisted result status before success toast', () => {
  const fn = block(frontend, 'const submitOperationExecutionReview', 'const finishOperationAction');
  assertBefore(fn, 'await readOperationExecutionTask', 'showToast(', 'review readback must happen before success toast');
  assertMatches(fn, /result_status/, 'review flow must verify result_status');
});

test('mutation readbacks use and cross-check the resource id returned by POST', () => {
  const approval = block(frontend, 'const approveOperationExecutionIntent', 'const recordOperationExecutionEvidence');
  const priceExecution = block(frontend, 'const recordOperationExecutionEvidence', 'const submitOperationExecutionEvidence');
  const generalExecution = block(frontend, 'const submitOperationExecutionEvidence', 'const recordOperationRoiEvidence');
  const roiEvidence = block(frontend, 'const recordOperationRoiEvidence', 'const reviewOperationExecutionTask');
  const review = block(frontend, 'const submitOperationExecutionReview', 'const finishOperationAction');

  assertMatches(approval, /res\.data\?\.id[\s\S]*?readOperationExecutionIntent\(responseIntentId\)/, 'approval must read the returned intent id');
  for (const fn of [priceExecution, generalExecution, roiEvidence, review]) {
    assertMatches(fn, /res\.data\?\.id[\s\S]*?readOperationExecutionTask\(responseTaskId\)/, 'task mutation must read the returned task id');
    assertMatches(fn, /responseTaskId\s*!==\s*taskId/, 'task mutation must reject a mismatched returned id');
  }
});
