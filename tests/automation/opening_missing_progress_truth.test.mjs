import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/operation-static.js', 'utf8');
const main = fs.readFileSync('public/app-main.js', 'utf8');
function setup(request = async () => ({ code: 200 })) {
  const ctx = { window: {}, request, selectedOpeningTasks: { value: [] }, selectedOpeningTaskIds: { value: [] },
    openingLoading: { value: false }, loadOpeningOverview: async () => {}, showToast: () => {} };
  vm.createContext(ctx); vm.runInContext(source, ctx); Object.assign(ctx, ctx.window.SUXI_OPERATION_STATIC);
  Object.assign(ctx, { selectedOpeningProjectId: { value: '7' }, openingTasks: { value: [] }, openingOverview: { value: null },
    captureAuthSession: () => ({}), isAuthSessionCurrent: () => true, clearSelectedOpeningTasks: () => {}, pruneSelectedOpeningTaskIds: () => {} });
  ctx.messages = [];
  ctx.showToast = (...args) => ctx.messages.push(args);
  ctx.openingProjectDataController = ctx.createOpeningProjectDataController(ctx);
  const take = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a); assert.ok(a >= 0 && b > a); return main.slice(a, b); };
  vm.runInContext(take('const handleOpeningTaskProgressInput =', 'const normalizeOpeningProjectFormForSubmit =')
    + take('const updateOpeningTask =', 'const createOpeningExecutionIntent =')
    + '\nthis.handlers = {updateOpeningTask, handleOpeningTaskProgressInput, handleOpeningTaskStatusChange, setOpeningTaskProgress, setOpeningTaskStatus, saveOpeningTaskProgress, batchUpdateOpeningTasks};', ctx);
  return ctx;
}
const legacy = (status = 'done') => ({ id: 42, status, progress_percent: null, progress_percent_known: false, remark: 'old' });

test('missing progress remains unknown independently of saved status; real zero and old numbers remain numeric', () => {
  const s = setup();
  for (const status of ['done', 'todo', 'doing', 'blocked']) {
    for (const progress_percent of [null, undefined, '', ' ', 'invalid']) {
      assert.equal(s.openingTaskProgressPercent({ status, progress_percent }), null);
    }
    assert.equal(s.openingTaskProgressPercent({ status, progress_percent: 0 }), 0);
  }
  assert.equal(s.openingTaskProgressStage(legacy()), '未填报');
  assert.equal(s.openingTaskProgressPercent({ progress_percent: '75' }), 75);
});

test('ordinary original row update never invents missing progress or resends done as completion intent', async () => {
  const calls = []; const s = setup(async (url, options) => { calls.push(JSON.parse(options.body)); return { code: 200 }; });
  for (const status of ['done', 'todo', 'doing', 'blocked']) {
    const row = legacy(status); row.remark = 'edited'; row.owner_name = 'owner'; row.deadline = '2026-09-30';
    assert.equal(await s.handlers.updateOpeningTask(row), true);
    const payload = calls.at(-1);
    assert.equal(Object.hasOwn(payload, 'progress_percent'), false, status);
    if (status === 'done') assert.equal(Object.hasOwn(payload, 'status'), false);
    else assert.equal(payload.status, status);
    assert.equal(payload.remark, 'edited'); assert.equal(payload.owner_name, 'owner');
    assert.equal(row.progress_percent, null); assert.equal(row.status, status);
  }
});

test('partial or entirely missing rows do not become a complete mean or zero-percent bucket', () => {
  const s = setup();
  const stats = s.buildOpeningTaskStats([legacy(), { ...legacy('doing'), progress_percent: 50 }]);
  assert.equal(stats.averageProgress, null); assert.equal(stats.recordedAverageProgress, 50);
  assert.equal(stats.progressMissing, 1); assert.equal(stats.progressRecorded, 1);
  assert.equal(stats.progressEmpty, 0); assert.equal(stats.progressDone, 0); assert.equal(stats.done, 1);
  const card = s.buildOpeningTaskProgressCards(stats)[0];
  assert.equal(card.value, '待补齐'); assert.equal(card.progress, null); assert.match(card.hint, /1\/2.*50%/);
  const stages = s.buildOpeningTaskProgressStages(stats);
  assert.equal(stages.find(x => x.label === '未填报').count, 1);
  assert.equal(stages.reduce((sum, x) => sum + x.count, 0), 2);
  const none = s.buildOpeningTaskStats([legacy('doing')]);
  assert.equal(none.averageProgress, null); assert.equal(none.recordedAverageProgress, null);
  assert.equal(s.buildOpeningTaskStats([]).averageProgress, null);
  assert.equal(s.buildOpeningTaskStats([{ progress_percent: 0 }, { progress_percent: 100 }]).averageProgress, 50);
});

test('explicit quick and status actions still establish progress, while blocked actions preserve unknown', async () => {
  const calls = []; const s = setup(async (_, options) => { calls.push(JSON.parse(options.body)); return { code: 200 }; });
  for (const value of [0, 25, 100]) {
    const row = legacy(); s.handlers.setOpeningTaskProgress(row, value); await s.handlers.saveOpeningTaskProgress(row);
    assert.equal(calls.at(-1).progress_percent, value); assert.equal(row.status, value === 0 ? 'todo' : value === 100 ? 'done' : 'doing');
  }
  const done = legacy('doing'); await s.handlers.setOpeningTaskStatus(done, 'done');
  assert.equal(calls.at(-1).status, 'done'); assert.equal(calls.at(-1).progress_percent, 100);
  const blocked = legacy('doing'); await s.handlers.setOpeningTaskStatus(blocked, 'blocked');
  assert.equal(calls.at(-1).status, 'blocked'); assert.equal(Object.hasOwn(calls.at(-1), 'progress_percent'), false);
});

test('failed ordinary save and batch status rollback keep unknown progress and allow retry', async () => {
  let fail = true; const calls = []; const s = setup(async (_, options) => { calls.push(JSON.parse(options.body)); return { code: fail ? 500 : 200 }; });
  const row = legacy(); row.remark = 'draft';
  assert.equal(await s.handlers.updateOpeningTask(row), false); assert.equal(row.progress_percent, null);
  fail = false; assert.equal(await s.handlers.updateOpeningTask(row), true); assert.equal(Object.hasOwn(calls.at(-1), 'progress_percent'), false);
  fail = true; s.selectedOpeningTasks.value = [row]; s.selectedOpeningTaskIds.value = ['42'];
  await s.handlers.batchUpdateOpeningTasks({ status: 'blocked' });
  assert.equal(row.status, 'done'); assert.equal(row.progress_percent, null); assert.equal(row.remark, 'draft');
});

test('existing recommendation rows preserve unknown progress rather than displaying null as zero', () => {
  const s = setup(); const result = s.buildOpeningAiOutputResult({ tasks: [{ ...legacy('doing'), ai_suggestion: 'synthetic suggestion' }], stats: s.buildOpeningTaskStats([legacy()]) });
  assert.equal(result.taskOutputs[0].progress, null);
});

test('save-progress action requires an explicit value and cannot report an empty progress save', async () => {
  let calls = 0;
  const s = setup(async () => { calls += 1; return { code: 200 }; });
  const row = legacy(); await s.handlers.saveOpeningTaskProgress(row);
  assert.equal(calls, 0); assert.equal(row.status, 'done'); assert.equal(row.progress_percent, null);
  assert.equal(s.messages.length, 1); assert.equal(s.messages[0][1], 'warning');
});
