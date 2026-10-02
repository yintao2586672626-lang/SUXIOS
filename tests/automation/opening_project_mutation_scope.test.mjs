import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';

// Run only this transport fixture: node --test tests/automation/opening_project_mutation_scope.test.mjs
// Set OPENING_MUTATION_SOURCE_DIR to a preserved public directory to reproduce the original behavior.
// No application bootstrap, browser, HTTP transport, or database is used.
const sourceDirectory = process.env.OPENING_MUTATION_SOURCE_DIR || 'public';
const main = fs.readFileSync(path.join(sourceDirectory, 'app-main.js'), 'utf8');
const operation = fs.readFileSync(path.join(sourceDirectory, 'operation-static.js'), 'utf8');
const from = main.indexOf('const handleOpeningTaskProgressInput =');
const to = main.indexOf('const renderHomeTrendChart =', from);
assert.ok(from >= 0 && to > from, 'the real opening project handlers must be present');
const handlersSource = main.slice(from, to);
const copy = value => JSON.parse(JSON.stringify(value));
const ref = value => ({ value });
const nextTurn = () => new Promise(resolve => setImmediate(resolve));
function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
const project = id => ({ id, hotel_id: 7, project_name: `Project ${id}`, hotel_name: `Hotel ${id}`, opening_date: '2026-10-15', status: 'planning' });
const task = (id, projectId) => ({ id, project_id: projectId, status: 'doing', progress_percent: 50, progress_percent_known: true, owner_name: 'Synthetic owner', deadline: '2026-10-01', remark: 'Saved remark' });

function setup(intercept = () => undefined) {
  const calls = [];
  const messages = [];
  const session = { revision: 1 };
  const s = {
    window: {}, token: ref('synthetic-token'), openingProjects: ref([project(1), project(2)]),
    selectedOpeningProjectId: ref('1'), openingOverview: ref({ project: project(1) }),
    openingTasks: ref([task(101, 1), task(102, 1)]), selectedOpeningTaskIds: ref(['101', '102']),
    openingProjectForm: ref(project(1)), openingLoading: ref(false), operationHotelOptions: ref([{ id: 7, name: 'Synthetic hotel' }]),
    openingExecutionReady: ref(true), openingProjectBindingDirty: ref(false), openingExecutionIntentId: ref(0),
    currentPage: ref('opening-checklist'), nextTick: async () => {}, confirm: () => true,
    captureAuthSession: () => session.revision,
    isAuthSessionCurrent: owner => owner === session.revision,
    showToast: (...args) => messages.push(args),
    clearSelectedOpeningTasks: () => { s.selectedOpeningTaskIds.value = []; },
    pruneSelectedOpeningTaskIds: () => {
      const available = new Set(s.openingTasks.value.map(row => String(row.id)));
      s.selectedOpeningTaskIds.value = s.selectedOpeningTaskIds.value.filter(id => available.has(String(id)));
    },
    request: async (url, options = {}) => {
      const call = { url, method: options.method || 'GET', payload: options.body ? JSON.parse(options.body) : null };
      calls.push(call);
      const intercepted = intercept(call, s);
      if (intercepted !== undefined) return intercepted;
      if (url === '/opening/projects' && call.method === 'GET') return { code: 200, data: { list: [project(1), project(2)] } };
      const read = url.match(/^\/opening\/projects\/(\d+)\/(overview|tasks)$/);
      if (read) {
        const id = Number(read[1]);
        return { code: 200, data: read[2] === 'overview' ? { project: project(id) } : { list: [task(id * 100 + 1, id), task(id * 100 + 2, id)] } };
      }
      if (url.endsWith('/generate-tasks')) {
        const id = Number(url.split('/')[3]);
        return { code: 200, data: { tasks: [task(id * 100 + 1, id)], overview: { project: project(id) } } };
      }
      throw new Error(`unexpected synthetic request: ${call.method} ${url}`);
    },
  };
  s.selectedOpeningProject = { get value() { return s.openingProjects.value.find(row => String(row.id) === String(s.selectedOpeningProjectId.value)) || null; } };
  s.selectedOpeningTasks = { get value() { return s.openingTasks.value.filter(row => s.selectedOpeningTaskIds.value.some(id => String(id) === String(row.id))); } };
  vm.createContext(s);
  vm.runInContext(operation, s);
  Object.assign(s, s.window.SUXI_OPERATION_STATIC);
  s.normalizeOpeningProjectFormForSubmitModel = s.normalizeOpeningProjectFormForSubmit;
  s.resetOpeningProjectForm = () => s.buildOpeningProjectFormDefaults();
  s.openingProjectDataController = typeof s.createOpeningProjectDataController === 'function'
    ? s.createOpeningProjectDataController(s) : null;
  s.ensureOperationStaticReady = async () => true;
  vm.runInContext(handlersSource + '\nthis.handlers = {loadOpeningProjects, saveOpeningProject, startOpeningProjectCreate, archiveOpeningProject, selectOpeningProject, createOpeningExecutionIntent, updateOpeningTask, saveOpeningTaskProgress, batchUpdateOpeningTasks};', s);
  s.calls = calls;
  s.messages = messages;
  s.session = session;
  return s;
}

const view = s => copy({
  selected: s.selectedOpeningProjectId.value, projects: s.openingProjects.value,
  overview: s.openingOverview.value, tasks: s.openingTasks.value,
  selectedTasks: s.selectedOpeningTaskIds.value, form: s.openingProjectForm.value,
});
async function selectB(s) {
  s.selectedOpeningProjectId.value = '2';
  await s.handlers.selectOpeningProject();
  s.selectedOpeningTaskIds.value = ['201'];
  s.openingProjectForm.value.project_name = 'B current unsaved draft';
  assert.equal(s.openingOverview.value?.project.id, 2, 'the current selection must actually finish loading');
  assert.equal(s.openingTasks.value[0]?.project_id, 2);
  return { view: view(s), callCount: s.calls.length, messageCount: s.messages.length };
}
function assertBUnchanged(s, before, { additionalCalls = 0 } = {}) {
  assert.deepEqual(view(s), before.view, 'an old project receipt must preserve the current project, draft, rows, and selection');
  assert.equal(s.calls.length, before.callCount + additionalCalls, 'an old project receipt must not initiate a refresh or generation for the current view');
  assert.equal(s.messages.length, before.messageCount, 'an old project receipt must not publish feedback into the current view');
}

test('a delayed project list cannot replace B selection or its unsaved draft', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/projects' && call.method === 'GET' ? pending.promise : undefined);
  const work = s.handlers.loadOpeningProjects();
  await nextTurn();
  assert.equal(s.calls[0]?.url, '/opening/projects');
  const before = await selectB(s);
  pending.resolve({ code: 200, data: { list: [project(1)] } });
  await work;
  assertBUnchanged(s, before);
});

test('a delayed save of A cannot select A again or reload the current B view', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/projects/1' && call.method === 'PUT' ? pending.promise : undefined);
  const work = s.handlers.saveOpeningProject();
  await nextTurn();
  assert.equal(s.calls[0]?.url, '/opening/projects/1');
  const before = await selectB(s);
  pending.resolve({ code: 200, data: project(1) });
  await work;
  assertBUnchanged(s, before);
});

test('a delayed project creation cannot replace B or generate tasks for B', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/projects' && call.method === 'POST' ? pending.promise : undefined);
  s.handlers.startOpeningProjectCreate();
  s.openingProjectForm.value = project(3);
  const work = s.handlers.saveOpeningProject();
  await nextTurn();
  assert.equal(s.calls[0]?.method, 'POST');
  const before = await selectB(s);
  pending.resolve({ code: 200, data: project(3) });
  await work;
  assert.equal(s.calls.some(call => call.url === '/opening/projects/2/generate-tasks'), false);
  assertBUnchanged(s, before);
});

test('a delayed archive of A cannot clear B or reset its draft and task selection', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/projects/1' && call.method === 'DELETE' ? pending.promise : undefined);
  const work = s.handlers.archiveOpeningProject();
  await nextTurn();
  assert.equal(s.calls[0]?.method, 'DELETE');
  const before = await selectB(s);
  pending.resolve({ code: 200, data: { id: 1 } });
  await work;
  assertBUnchanged(s, before);
});

for (const outcome of ['success', 'error']) {
  test(`a delayed execution-intent ${outcome} for A cannot refresh or notify B`, async () => {
    const pending = deferred();
    const s = setup(call => call.url === '/opening/projects/1/execution-intent' ? pending.promise : undefined);
    const work = s.handlers.createOpeningExecutionIntent();
    await nextTurn();
    assert.equal(s.calls[0]?.url, '/opening/projects/1/execution-intent');
    const before = await selectB(s);
    pending.resolve(outcome === 'success' ? { code: 200, data: { project: project(1), execution_intent: { id: 71 } } } : { code: 503, message: 'Synthetic A execution failure' });
    await work;
    assertBUnchanged(s, before);
  });

  test(`a delayed task-progress ${outcome} for A cannot refresh or notify B`, async () => {
    const pending = deferred();
    const s = setup(call => call.url === '/opening/tasks/101' ? pending.promise : undefined);
    const oldTask = s.openingTasks.value[0];
    oldTask.progress_percent = 75;
    const work = s.handlers.saveOpeningTaskProgress(oldTask);
    await nextTurn();
    assert.equal(s.calls[0]?.payload.progress_percent, 75);
    const before = await selectB(s);
    pending.resolve(outcome === 'success' ? { code: 200, data: { ...task(101, 1), progress_percent: 75 } } : { code: 503, message: 'Synthetic A task failure' });
    await work;
    assertBUnchanged(s, before);
  });
}

test('an authorized batch keeps writing its original A rows while late failure IDs and feedback stay out of B', async () => {
  const pending = deferred();
  const s = setup(call => {
    if (call.url === '/opening/tasks/101') return pending.promise;
    if (call.url === '/opening/tasks/102') return { code: 503, message: 'Synthetic second A row failure' };
    return undefined;
  });
  const work = s.handlers.batchUpdateOpeningTasks({ progress_percent: 75 });
  await nextTurn();
  assert.equal(s.calls[0]?.url, '/opening/tasks/101');
  const before = await selectB(s);
  pending.resolve({ code: 200, data: { ...task(101, 1), progress_percent: 75 } });
  await work;
  assert.deepEqual(s.calls.filter(call => call.method === 'PUT').map(call => [call.url, call.payload.progress_percent]), [
    ['/opening/tasks/101', 75], ['/opening/tasks/102', 75],
  ], 'switching view must preserve the remaining authorized A batch writes');
  assertBUnchanged(s, before, { additionalCalls: 1 });
});

test('current project task save still refreshes the current overview and reports success after the receipt', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/tasks/101' ? pending.promise : undefined);
  const oldTask = s.openingTasks.value[0];
  oldTask.progress_percent = 75;
  const work = s.handlers.saveOpeningTaskProgress(oldTask);
  await nextTurn();
  assert.equal(s.messages.length, 0, 'pending writes are not successful saves');
  assert.equal(s.calls.length, 1);
  pending.resolve({ code: 200, data: { ...task(101, 1), progress_percent: 75 } });
  await work;
  assert.equal(oldTask.progress_percent, 75);
  assert.equal(s.calls.filter(call => call.url === '/opening/projects/1/overview').length, 1);
  assert.equal(s.messages.filter(([message]) => message === '进度已保存').length, 1);
});

test('current project creation reads back the new project and automatically generates only its checklist', async () => {
  const created = deferred();
  const generated = deferred();
  const s = setup(call => {
    if (call.url === '/opening/projects' && call.method === 'POST') return created.promise;
    if (call.url === '/opening/projects' && call.method === 'GET') return { code: 200, data: { list: [project(1), project(2), project(3)] } };
    if (call.url === '/opening/projects/3/generate-tasks') return generated.promise;
    return undefined;
  });
  s.handlers.startOpeningProjectCreate();
  s.openingProjectForm.value = project(3);
  const work = s.handlers.saveOpeningProject();
  await nextTurn();
  assert.equal(s.openingLoading.value, true);
  assert.equal(s.messages.length, 0);
  assert.deepEqual(s.calls.map(call => [call.method, call.url]), [['POST', '/opening/projects']]);
  created.resolve({ code: 200, data: project(3) });
  await nextTurn();
  assert.equal(s.selectedOpeningProjectId.value, '3');
  assert.equal(s.openingProjectForm.value.project_name, 'Project 3');
  assert.equal(s.openingOverview.value.project.id, 3);
  assert.equal(s.openingTasks.value[0].project_id, 3);
  assert.deepEqual(s.calls.map(call => [call.method, call.url]), [
    ['POST', '/opening/projects'], ['GET', '/opening/projects'],
    ['GET', '/opening/projects/3/overview'], ['GET', '/opening/projects/3/tasks'],
    ['POST', '/opening/projects/3/generate-tasks'],
  ]);
  assert.equal(s.openingLoading.value, true, 'automatic generation keeps the operation busy until its receipt');
  assert.equal(s.messages.length, 0);
  generated.resolve({ code: 200, data: { tasks: [{ ...task(303, 3), remark: 'Generated receipt' }], overview: { project: project(3), synthetic_generation: 'complete' } } });
  await work;
  assert.equal(s.openingTasks.value[0].id, 303);
  assert.equal(s.openingTasks.value[0].remark, 'Generated receipt');
  assert.equal(s.openingOverview.value.synthetic_generation, 'complete');
  assert.equal(s.openingLoading.value, false);
  assert.deepEqual(s.messages, [['开业项目已创建，检查清单已生成', 'success']]);
});

test('current execution-intent success refreshes the same project list, overview, and tasks and releases busy', async () => {
  const pending = deferred();
  const linked = { ...project(1), execution_intent_id: 71 };
  const s = setup(call => {
    if (call.url === '/opening/projects/1/execution-intent') return pending.promise;
    if (call.url === '/opening/projects') return { code: 200, data: { list: [linked, project(2)] } };
    if (call.url === '/opening/projects/1/overview') return { code: 200, data: { project: linked, execution_intent: { id: 71 } } };
    if (call.url === '/opening/projects/1/tasks') return { code: 200, data: { list: [{ ...task(101, 1), progress_percent: 66, remark: 'Fresh execution readback' }] } };
    return undefined;
  });
  const work = s.handlers.createOpeningExecutionIntent();
  await nextTurn();
  assert.equal(s.openingLoading.value, true);
  assert.equal(s.messages.length, 0);
  pending.resolve({ code: 200, data: { project: linked, execution_intent: { id: 71 } } });
  await work;
  assert.equal(s.selectedOpeningProjectId.value, '1');
  assert.equal(s.openingProjects.value.find(row => row.id === 1).execution_intent_id, 71);
  assert.equal(s.openingOverview.value.execution_intent.id, 71);
  assert.equal(s.openingTasks.value.length, 1);
  assert.equal(s.openingTasks.value[0].remark, 'Fresh execution readback');
  assert.equal(s.openingTasks.value[0].progress_percent, 66);
  assert.deepEqual(s.calls.map(call => [call.method, call.url]), [
    ['POST', '/opening/projects/1/execution-intent'], ['GET', '/opening/projects'],
    ['GET', '/opening/projects/1/overview'], ['GET', '/opening/projects/1/tasks'],
  ]);
  assert.equal(s.openingLoading.value, false);
  assert.deepEqual(s.messages, [['开业执行跟踪意图已创建']]);
});

test('current partial batch retains successful receipts, rolls back the failed row, and reports retry IDs', async () => {
  const pending = deferred();
  const s = setup(call => {
    if (call.url === '/opening/tasks/101') return pending.promise;
    if (call.url === '/opening/tasks/102') return { code: 503, message: 'Synthetic second A row failure' };
    return undefined;
  });
  const secondBefore = copy(s.openingTasks.value[1]);
  const work = s.handlers.batchUpdateOpeningTasks({ progress_percent: 75 });
  await nextTurn();
  assert.equal(s.openingLoading.value, true);
  assert.equal(s.messages.length, 0);
  pending.resolve({ code: 200, data: { ...task(101, 1), progress_percent: 75, remark: 'Exact successful batch receipt' } });
  await work;
  assert.equal(s.openingTasks.value[0].progress_percent, 75);
  assert.equal(s.openingTasks.value[0].remark, 'Exact successful batch receipt');
  assert.deepEqual(copy(s.openingTasks.value[1]), secondBefore);
  assert.deepEqual([...s.selectedOpeningTaskIds.value], ['102']);
  assert.deepEqual(s.calls.map(call => [call.method, call.url]), [
    ['PUT', '/opening/tasks/101'], ['PUT', '/opening/tasks/102'], ['GET', '/opening/projects/1/overview'],
  ]);
  assert.equal(s.openingLoading.value, false);
  assert.deepEqual(s.messages.at(-1), ['批量处理完成：成功 1 项，失败 1 项', 'error']);
});

test('a session change stops the remaining authorized batch before another task request and preserves the new view', async () => {
  const pending = deferred();
  const s = setup(call => call.url === '/opening/tasks/101' ? pending.promise : undefined);
  const work = s.handlers.batchUpdateOpeningTasks({ progress_percent: 75 });
  await nextTurn();
  assert.equal(s.calls[0]?.url, '/opening/tasks/101');
  s.session.revision += 1;
  const before = await selectB(s);
  pending.resolve({ code: 200, data: { ...task(101, 1), progress_percent: 75 } });
  await work;
  assert.deepEqual(s.calls.filter(call => call.method === 'PUT').map(call => call.url), ['/opening/tasks/101'],
    'the remaining A task must not be submitted using the new authenticated session');
  assertBUnchanged(s, before);
});

test('cold-start creation preserves the draft with a warning and allows retry after the controller is ready', async () => {
  const s = setup(call => {
    if (call.url === '/opening/projects' && call.method === 'POST') return { code: 200, data: project(3) };
    if (call.url === '/opening/projects' && call.method === 'GET') return { code: 200, data: { list: [project(1), project(2), project(3)] } };
    return undefined;
  });
  s.handlers.startOpeningProjectCreate();
  s.openingProjectForm.value = { ...project(3), city: 'Synthetic draft city', manager_name: 'Synthetic draft manager' };
  const readyController = s.openingProjectDataController;
  s.openingProjectDataController = null;
  const before = view(s);
  await assert.doesNotReject(s.handlers.saveOpeningProject(), 'an unfinished static load must not throw from the visible create form');
  assert.equal(s.calls.length, 0, 'no create request may be sent before the controller is ready');
  assert.deepEqual(view(s), before, 'the entire entered draft must survive the loading warning');
  assert.equal(s.openingLoading.value, false);
  assert.equal(s.messages.length, 1);
  assert.equal(s.messages[0][1], 'warning');
  assert.match(s.messages[0][0], /加载|就绪|准备/);
  s.openingProjectDataController = readyController;
  s.messages.length = 0;
  await s.handlers.saveOpeningProject();
  assert.equal(s.selectedOpeningProjectId.value, '3');
  assert.equal(s.openingOverview.value.project.id, 3);
  assert.equal(s.openingTasks.value[0].project_id, 3);
  assert.deepEqual(s.calls.filter(call => call.method === 'POST').map(call => call.url), ['/opening/projects', '/opening/projects/3/generate-tasks']);
  assert.equal(s.calls[0].payload.city, 'Synthetic draft city');
  assert.equal(s.calls[0].payload.manager_name, 'Synthetic draft manager');
  assert.equal(s.openingLoading.value, false);
  assert.deepEqual(s.messages, [['开业项目已创建，检查清单已生成', 'success']]);
});
