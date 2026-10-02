import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/operation-static.js', 'utf8');
const main = fs.readFileSync('public/app-main.js', 'utf8');
const between = (start, end) => {
  const from = main.indexOf(start), to = main.indexOf(end, from);
  assert.ok(from >= 0 && to > from);
  return main.slice(from, to);
};
function setup(request) {
  const context = { window: {}, request, selectedOpeningTasks: { value: [] },
    selectedOpeningTaskIds: { value: [] }, openingLoading: { value: false },
    loadOpeningOverview: async () => {}, messages: [] };
  context.showToast = (...args) => context.messages.push(args);
  vm.createContext(context);
  vm.runInContext(source, context);
  Object.assign(context, context.window.SUXI_OPERATION_STATIC);
  Object.assign(context, { selectedOpeningProjectId: { value: '7' }, openingTasks: { value: [] }, openingOverview: { value: null },
    captureAuthSession: () => ({}), isAuthSessionCurrent: () => true, clearSelectedOpeningTasks: () => {}, pruneSelectedOpeningTaskIds: () => {} });
  context.openingProjectDataController = context.createOpeningProjectDataController(context);
  vm.runInContext(between('const handleOpeningTaskProgressInput =', 'const normalizeOpeningProjectFormForSubmit =')
    + between('const updateOpeningTask =', 'const createOpeningExecutionIntent =')
    + '\nthis.handlers = {handleOpeningTaskProgressInput, setOpeningTaskProgress, saveOpeningTaskProgress, batchUpdateOpeningTasks};', context);
  return context;
}
const task = () => ({ id: 42, project_id: 7, status: 'done', progress_percent: 100, owner_name: '合成负责人', deadline: '2026-09-30', remark: '保留验收备注' });

test('lowering a completed row using the real progress input reopens it before saving', () => {
  const s = setup(); const row = task(); row.progress_percent = 75;
  s.handlers.handleOpeningTaskProgressInput(row);
  assert.equal(row.status, 'doing');
  assert.equal(s.buildOpeningTaskUpdatePayload(row).progress_percent, 75);
  const stats = s.buildOpeningTaskStats([row]);
  assert.equal(stats.done, 0); assert.equal(stats.doing, 1); assert.equal(stats.progressHigh, 1);
  assert.equal(row.remark, '保留验收备注');
});

test('quick and batch patch paths preserve partial progress instead of resubmitting done', () => {
  const s = setup();
  for (const value of [1, 25, 50, 99]) {
    const row = task(); s.handlers.setOpeningTaskProgress(row, value);
    assert.equal(row.status, 'doing'); assert.equal(row.progress_percent, value);
    const batchRow = task(); s.applyOpeningTaskPatch(batchRow, { progress_percent: value });
    assert.equal(batchRow.status, 'doing'); assert.equal(batchRow.progress_percent, value);
  }
});

test('zero, full progress, blocked work and legacy numeric strings keep their meanings', () => {
  const s = setup();
  for (const [status, progress, expected] of [
    ['done', 0, 'todo'], ['todo', 25, 'doing'], ['doing', 99, 'doing'],
    ['blocked', 75, 'blocked'], ['blocked', 0, 'blocked'], ['blocked', 100, 'done'],
    ['done', 100, 'done'], [undefined, 10, 'doing'],
  ]) {
    const row = { status, progress_percent: progress };
    s.handlers.handleOpeningTaskProgressInput(row);
    assert.equal(row.status, expected); assert.equal(row.progress_percent, progress);
  }
  const legacy = { status: 'done', progress_percent: '75' }; s.handlers.handleOpeningTaskProgressInput(legacy);
  assert.equal(legacy.status, 'doing'); assert.equal(legacy.progress_percent, 75);
});

test('original save retries the draft and applies the exact successful task receipt', async () => {
  const sent = []; let failed = true;
  const s = setup(async (url, options) => {
    const payload = JSON.parse(options.body); sent.push({ url, payload });
    if (failed) return { code: 503, message: '合成暂时不可用' };
    return { code: 200, data: { id: 42, project_id: 7, ...payload } };
  });
  const row = task(); row.progress_percent = 75;
  await s.handlers.saveOpeningTaskProgress(row);
  assert.equal(row.status, 'doing'); assert.equal(row.progress_percent, 75);
  assert.equal(s.messages.filter(([message]) => message === '进度已保存').length, 0);
  failed = false; await s.handlers.saveOpeningTaskProgress(row);
  assert.equal(sent.length, 2); assert.equal(sent[1].url, '/opening/tasks/42');
  assert.equal(sent[1].payload.status, 'doing'); assert.equal(sent[1].payload.progress_percent, 75);
  assert.equal(row.project_id, 7); assert.equal(row.remark, '保留验收备注');
  assert.equal(s.messages.filter(([message]) => message === '进度已保存').length, 1);
});

test('original batch path keeps successful lowered progress and rolls back only the failed row', async () => {
  const s = setup(async (url, options) => url.endsWith('/43')
    ? { code: 503, message: '合成第二项失败' }
    : { code: 200, data: JSON.parse(options.body) });
  const a = task(), b = { ...task(), id: 43 };
  s.selectedOpeningTasks.value = [a, b]; s.selectedOpeningTaskIds.value = ['42', '43'];
  await s.handlers.batchUpdateOpeningTasks({ progress_percent: 50 });
  assert.equal(a.status, 'doing'); assert.equal(a.progress_percent, 50);
  assert.equal(b.status, 'done'); assert.equal(b.progress_percent, 100);
  assert.deepEqual([...s.selectedOpeningTaskIds.value], ['43']); assert.equal(s.openingLoading.value, false);
});
