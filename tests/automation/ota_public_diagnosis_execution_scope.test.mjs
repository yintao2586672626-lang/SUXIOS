import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';

const { ref, computed, watch, effectScope } = createRequire(new URL('../../package.json', import.meta.url))('vue');
const source = fs.readFileSync(process.env.SUXIOS_PUBLIC_EVIDENCE_SOURCE || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const section = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `${start} / ${end}`);
  return source.slice(a, b);
};
const methodsSource = [
  section('const findVisibleOperationIntentRow =', 'let revenueAiOverviewRequestSeq ='),
  section('const otaPublicPageDiagnosisTaskCanRetry =', 'const otaPublicPageDiagnosisTaskActionText ='),
  section('const otaPublicPageDiagnosisTaskBridgeNoticeText =', 'const otaPublicPageDiagnosisError ='),
  section('const resetOtaPublicPageDiagnosisTaskSchedule =', 'const parseOtaPublicPageJsonObject ='),
  section('const openOtaPublicPageDiagnosisExecutionIntent =', 'const loadCtripCompetitiveOperations ='),
].join('\n');
const watcherSource = section('const invalidateOtaPublicPageEvidence =', source.includes('const invalidateCtripPublicProfileScope =') ? 'const invalidateCtripPublicProfileScope =' : 'watch(platformHotelContext, clearPlatformHotelSearch);');
const plain = value => JSON.parse(JSON.stringify(value));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const drain = async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); };
const schedule = () => ({ assignee_id: '11', due_at: '2099-07-18T18:00', review_at: '2099-07-19T10:00' });
const editedSchedule = () => ({ assignee_id: '12', due_at: '2099-07-20T18:00', review_at: '2099-07-21T10:00' });
const diagnosis = (hotel = '7', platform = 'meituan', date = '2026-09-20') => ({
  system_hotel_id: Number(hotel), platform, business_date: date, status: 'insufficient_evidence',
});
const intent = (mode = 'open', hotel = 7, id = 101) => ({
  id, hotel_id: hotel, platform: 'meituan',
  status: mode === 'reschedule' ? 'pending_approval' : mode === 'retry' ? 'rejected' : 'approved',
  lifecycle_status: mode === 'reschedule' ? 'pending_approval' : mode === 'retry' ? 'rejected' : 'pending_execute',
  target_value: { workflow_schedule: schedule() },
});

function fixture(t, { mode = 'create', deferOperations = false, deferRefresh = false, deferTick = false } = {}) {
  const posts = [], dialogs = [], operationLoads = [], refreshes = [], ticks = [], frames = [], scrolls = [], notices = [], bridgeWrites = [];
  const existing = ['open', 'retry', 'reschedule'].includes(mode) ? intent(mode) : null;
  const state = {
    selectedCtripHotelId: ref('7'), otaPublicPageDiagnosisFilter: ref({ platform: 'meituan', business_date: '2026-09-20' }),
    otaPublicPageDiagnosisPayload: ref(diagnosis()), otaPublicPageDiagnosisExecutionLoading: ref(false),
    otaPublicPageEvidenceSaving: ref(false), otaPublicPageDiagnosisError: ref(''),
    otaPublicPageDiagnosisExecutionIntent: ref(existing),
    otaPublicPageDiagnosisTaskBridge: ref({ state: mode === 'reread' ? 'readback_mismatch' : existing ? 'existing_intent' : 'no_intent' }),
    otaPublicPageDiagnosisTaskSchedule: ref(schedule()),
    otaPublicPageDiagnosisOperationSurfaceAccessible: ref(mode !== 'reread'),
    otaPublicPageDiagnosisOperationSurfaceStatus: ref('available'),
    otaPublicPageDiagnosisOperationSurfaceText: ref('可进入运营任务'),
    otaPublicPageDiagnosisTaskStatusText: ref('待审批'),
    otaPublicPageDiagnosisTaskReadbackText: ref('当前任务已回读'),
    operationFilters: ref({ hotel_id: '7' }), revenueAiExecutionFocus: ref(null),
    operationError: ref({ actions: '' }), operationExecutionItems: ref([]), currentPage: ref('ctrip-ebooking'),
    authContext: ref({ tenantId: 42 }), user: ref({ id: 11, tenant_id: 42 }),
    permission: ref(true), session: ref(1), actionVisible: ref(true),
  };
  const scopeValue = () => ({
    hotel: state.selectedCtripHotelId.value,
    platform: state.otaPublicPageDiagnosisFilter.value.platform,
    date: state.otaPublicPageDiagnosisFilter.value.business_date,
  });
  const sandbox = {
    ...state, ref, computed, watch, Date, JSON,
    captureAuthSession: () => ({ epoch: state.session.value }),
    isAuthSessionCurrent: owner => owner?.epoch === state.session.value,
    canMaintainOtaConfig: () => state.permission.value,
    otaPublicPageTaskDateTime: offset => offset === 1 ? '2099-07-18T18:00' : '2099-07-19T10:00',
    showToast: (message, type = 'success') => notices.push({ message, type }),
    openWorkflowFormDialog: options => {
      const d = deferred(); dialogs.push({ options, ...d }); return d.promise;
    },
    request: (url, options = {}) => {
      const d = deferred(); posts.push({ url, options, body: JSON.parse(options.body), ...d }); return d.promise;
    },
    loadOperationActions: options => {
      const d = deferred(); operationLoads.push({ options, scope: scopeValue(), ...d });
      state.operationExecutionItems.value = state.actionVisible.value ? [{ id: options.focusIntentId }] : [];
      if (!deferOperations) d.resolve();
      return d.promise;
    },
    loadOtaPublicPageDiagnosis: options => {
      const d = deferred(); refreshes.push({ options, scope: scopeValue(), ...d });
      if (!deferRefresh) d.resolve({ status: 'insufficient_evidence' });
      return d.promise;
    },
    nextTick: () => {
      const d = deferred(); ticks.push(d); if (!deferTick) d.resolve(); return d.promise;
    },
    requestAnimationFrame: callback => { frames.push(callback); return frames.length; },
    document: { querySelectorAll: selector => state.actionVisible.value ? [{ getClientRects: () => [{}], scrollIntoView: options => scrolls.push({ selector, options }) }] : [] },
  };
  const context = vm.createContext(sandbox), effects = effectScope();
  effects.run(() => {
    watch(state.otaPublicPageDiagnosisTaskBridge, value => bridgeWrites.push(plain(value)), { flush: 'sync' });
    vm.runInContext(`let otaPublicPageEvidenceSeq = 0; let otaPublicPageDiagnosisExecutionRequestSeq = 0;\n${watcherSource}\n${methodsSource}\nglobalThis.methods = { create: createOtaPublicPageDiagnosisExecutionIntent, edit: editOtaPublicPageDiagnosisTaskSchedule, open: openOtaPublicPageDiagnosisExecutionIntent };`, context);
  });
  t.after(() => effects.stop());
  return { state, posts, dialogs, operationLoads, refreshes, ticks, frames, scrolls, notices, bridgeWrites, scopeValue,
    ...context.methods, flushFrames: () => { while (frames.length) frames.shift()(); } };
}

function receipt(post, overrides = {}) {
  const body = post.body;
  return { code: 200, data: {
    create_performed: true,
    task_bridge: {
      state: 'existing_intent', create_status: 'available', identity_version: 'public_page_v3', readback_status: 'readback_verified',
      operation_surface: { accessible: true, status: 'available' },
      execution_intent: {
        id: 201, hotel_id: body.system_hotel_id, platform: body.platform, business_date: body.business_date,
        approval_status: 'pending_approval', lifecycle_status: 'pending_approval', identity_version: 'public_page_v3', intent_attempt: 1,
        workflow_schedule: { assignee_id: body.assignee_id, due_at: body.due_at, review_at: body.review_at },
      },
    }, ...overrides,
  } };
}

const changes = {
  hotel: p => { p.state.selectedCtripHotelId.value = '8'; },
  platform: p => { p.state.otaPublicPageDiagnosisFilter.value.platform = 'ctrip'; },
  date: p => { p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-09-21'; },
  session: p => { p.state.session.value++; },
  tenant: p => { p.state.authContext.value.tenantId = 43; },
  user: p => { p.state.user.value.id = 12; },
  permission: p => { p.state.permission.value = false; },
};

for (const [label, change] of Object.entries(changes)) {
  for (const outcome of ['success', 'error']) {
    test(`old task POST ${outcome} cannot write bridge, notice or navigation after ${label} changes`, async t => {
      const p = fixture(t), pending = p.create();
      assert.equal(p.posts.length, 1);
      change(p); p.state.otaPublicPageDiagnosisError.value = '新范围自己的提示';
      const before = plain(p.state.otaPublicPageDiagnosisTaskBridge.value);
      if (outcome === 'success') p.posts[0].resolve(receipt(p.posts[0]));
      else p.posts[0].reject(new Error('旧任务 HTTP 失败'));
      await pending;
      assert.deepEqual(plain(p.state.otaPublicPageDiagnosisTaskBridge.value), before);
      assert.equal(p.bridgeWrites.length, 0);
      assert.equal(p.state.otaPublicPageDiagnosisError.value, '新范围自己的提示');
      assert.equal(p.operationLoads.length, 0);
      assert.equal(p.notices.length, 0);
      assert.equal(p.state.currentPage.value, 'ctrip-ebooking');
      assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
    });
  }
}

test('A to B to A dates invalidate an old task response even when final fields match', async t => {
  const p = fixture(t), pending = p.create();
  changes.date(p); p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-09-20';
  p.posts[0].resolve(receipt(p.posts[0])); await pending;
  assert.equal(p.bridgeWrites.length, 0);
  assert.equal(p.operationLoads.length, 0);
  assert.equal(p.notices.length, 0);
  assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
});

test('an old POST finally cannot unlock a new current task operation', async t => {
  const p = fixture(t), old = p.create();
  changes.hotel(p); p.state.otaPublicPageDiagnosisPayload.value = diagnosis('8');
  const current = p.create(), currentPost = p.posts[1];
  p.posts[0].reject(new Error('旧范围延迟失败')); await old;
  const currentLock = p.state.otaPublicPageDiagnosisExecutionLoading.value;
  if (currentPost) currentPost.resolve(receipt(currentPost));
  await current;
  assert.ok(currentPost, 'Scope invalidation must allow a new task operation.');
  assert.equal(currentLock, true);
  assert.equal(currentPost.body.system_hotel_id, 8);
  assert.equal(p.notices.length, 1);
  assert.equal(p.notices[0].type, 'success');
  assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
});

for (const label of ['hotel', 'date', 'session', 'tenant']) {
  test(`obsolete reschedule dialog cannot change the new schedule or POST after ${label} changes`, async t => {
    const p = fixture(t, { mode: 'reschedule' }), pending = p.create();
    assert.equal(p.dialogs.length, 1);
    changes[label](p);
    const currentSchedule = { assignee_id: '21', due_at: '2099-08-01T18:00', review_at: '2099-08-02T10:00' };
    p.state.otaPublicPageDiagnosisTaskSchedule.value = { ...currentSchedule };
    p.dialogs[0].resolve(editedSchedule()); await drain();
    const submitted = p.posts.length;
    p.posts.forEach(post => post.resolve(receipt(post))); await pending;
    assert.equal(submitted, 0);
    assert.deepEqual(plain(p.state.otaPublicPageDiagnosisTaskSchedule.value), currentSchedule);
    assert.equal(p.notices.length, 0);
    assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
  });
}

test('only one create call may own the reschedule dialog and cancellation releases its lock', async t => {
  const p = fixture(t, { mode: 'reschedule' }), first = p.create(), second = p.create();
  const dialogs = p.dialogs.length, busy = p.state.otaPublicPageDiagnosisExecutionLoading.value;
  p.dialogs.forEach(dialog => dialog.resolve(null)); await Promise.all([first, second]);
  assert.equal(dialogs, 1); assert.equal(busy, true);
  assert.equal(p.posts.length, 0); assert.equal(p.notices.length, 0);
  assert.deepEqual(plain(p.state.otaPublicPageDiagnosisTaskSchedule.value), schedule());
  assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
  const retry = p.create(); p.dialogs.at(-1).resolve(editedSchedule()); await drain();
  p.posts[0].resolve(receipt(p.posts[0], { create_performed: false, schedule_updated: true })); await retry;
  assert.equal(p.notices.at(-1).type, 'success');
});

for (const label of ['hotel', 'session']) {
  for (const outcome of ['visible', 'list failure']) {
    test(`old operation-list ${outcome} cannot navigate or overwrite state after ${label} changes`, async t => {
      const p = fixture(t, { mode: 'open', deferOperations: true }), pending = p.create();
      assert.equal(p.operationLoads.length, 1);
      changes[label](p);
      p.state.otaPublicPageDiagnosisOperationSurfaceStatus.value = 'current_scope_state';
      p.state.otaPublicPageDiagnosisOperationSurfaceAccessible.value = false;
      p.state.revenueAiExecutionFocus.value = { intentId: 999 };
      if (outcome === 'list failure') p.state.operationError.value.actions = '旧列表读取失败';
      p.operationLoads[0].resolve(); await pending;
      p.flushFrames();
      assert.equal(p.state.currentPage.value, 'ctrip-ebooking');
      assert.equal(p.state.otaPublicPageDiagnosisOperationSurfaceStatus.value, 'current_scope_state');
      assert.equal(p.state.otaPublicPageDiagnosisOperationSurfaceAccessible.value, false);
      assert.equal(p.state.revenueAiExecutionFocus.value.intentId, 999);
      assert.equal(p.notices.length, 0); assert.equal(p.scrolls.length, 0);
      assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
    });
  }
}

test('session change while nextTick is pending suppresses old success and animation work', async t => {
  const p = fixture(t, { mode: 'open', deferTick: true }), pending = p.create();
  await drain(); assert.equal(p.ticks.length, 1);
  changes.session(p); p.state.currentPage.value = 'ctrip-ebooking';
  p.ticks[0].resolve(); await pending; p.flushFrames();
  assert.equal(p.notices.length, 0); assert.equal(p.scrolls.length, 0);
  assert.equal(p.state.currentPage.value, 'ctrip-ebooking');
  assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
});

test('an animation callback cannot scroll an old task after its owner changes', async t => {
  const p = fixture(t, { mode: 'open' }), pending = p.create();
  await drain(); assert.equal(p.frames.length, 1);
  changes.session(p); p.state.currentPage.value = 'ctrip-ebooking';
  p.flushFrames(); await pending;
  assert.equal(p.scrolls.length, 0);
  assert.equal(p.state.currentPage.value, 'ctrip-ebooking');
});

for (const label of ['hotel', 'session']) {
  test(`obsolete bridge reread cannot claim success after ${label} changes`, async t => {
    const p = fixture(t, { mode: 'reread', deferRefresh: true }), pending = p.create();
    assert.equal(p.refreshes.length, 1);
    changes[label](p); p.state.otaPublicPageDiagnosisError.value = '当前范围提示';
    p.refreshes[0].resolve({ status: 'insufficient_evidence' }); await pending;
    assert.equal(p.posts.length, 0); assert.equal(p.operationLoads.length, 0);
    assert.equal(p.notices.length, 0);
    assert.equal(p.state.otaPublicPageDiagnosisError.value, '当前范围提示');
    assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
  });
}

for (const mode of ['create', 'retry', 'reschedule']) {
  test(`current ${mode} keeps exact task scope, readback and navigation`, async t => {
    const p = fixture(t, { mode }), pending = p.create();
    if (mode === 'reschedule') { p.dialogs[0].resolve(editedSchedule()); await drain(); }
    assert.equal(p.posts.length, 1);
    const post = p.posts[0];
    assert.equal(post.url, '/online-data/public-page-diagnosis/execution-intent');
    assert.deepEqual({ ...post.options.businessContext }, { hotelId: '7', platform: 'meituan' });
    assert.equal(post.body.system_hotel_id, 7); assert.equal(post.body.platform, 'meituan');
    assert.equal(post.body.business_date, '2026-09-20');
    assert.equal(post.body.assignee_id, mode === 'reschedule' ? 12 : 11);
    assert.equal(post.body.due_at, mode === 'reschedule' ? editedSchedule().due_at : schedule().due_at);
    post.resolve(receipt(post, { create_performed: mode !== 'reschedule', retry_performed: mode === 'retry', schedule_updated: mode === 'reschedule', intent_attempt: mode === 'retry' ? 2 : 1 }));
    const result = await pending; p.flushFrames();
    assert.equal(result.id, 201); assert.equal(p.state.otaPublicPageDiagnosisExecutionIntent.value.id, 201);
    assert.equal(p.operationLoads.length, 1); assert.equal(p.operationLoads[0].options.focusIntentId, 201);
    assert.equal(p.state.currentPage.value, 'ops-track');
    assert.equal(p.scrolls.length, 1); assert.match(p.scrolls[0].selector, /201/);
    assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, 'success');
    assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
  });
}

test('current existing-task open and bridge reread keep their distinct read-only branches', async t => {
  const opened = fixture(t, { mode: 'open' }); await opened.create(); opened.flushFrames();
  assert.equal(opened.posts.length, 0); assert.equal(opened.operationLoads.length, 1);
  assert.equal(opened.state.currentPage.value, 'ops-track'); assert.equal(opened.notices[0].type, 'success');
  const reread = fixture(t, { mode: 'reread' }); await reread.create();
  assert.equal(reread.posts.length, 0); assert.equal(reread.refreshes.length, 1);
  assert.equal(reread.operationLoads.length, 0); assert.equal(reread.notices[0].type, 'success');
  assert.equal(reread.state.otaPublicPageDiagnosisExecutionLoading.value, false);
});

for (const failure of ['HTTP error', 'readback mismatch']) {
  test(`current ${failure} remains visible and permits recovery`, async t => {
    const p = fixture(t), failed = p.create(), post = p.posts[0];
    if (failure === 'HTTP error') post.reject(new Error('当前任务合成保存失败'));
    else {
      const wrong = receipt(post); wrong.data.task_bridge.execution_intent.hotel_id = 8; post.resolve(wrong);
    }
    await failed;
    assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, 'error');
    assert.notEqual(p.state.otaPublicPageDiagnosisError.value, '');
    assert.equal(p.operationLoads.length, 0); assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
    p.state.otaPublicPageDiagnosisTaskBridge.value = { state: 'no_intent' };
    p.state.otaPublicPageDiagnosisExecutionIntent.value = null;
    const retry = p.create(), retryPost = p.posts.at(-1); retryPost.resolve(receipt(retryPost)); await retry;
    assert.equal(p.notices.at(-1).type, 'success');
    assert.equal(p.state.otaPublicPageDiagnosisError.value, '');
    assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
  });
}

test('current invisible task does not navigate and becomes openable after list recovery', async t => {
  const p = fixture(t, { mode: 'open' }); p.state.actionVisible.value = false;
  await p.create();
  assert.equal(p.state.currentPage.value, 'ctrip-ebooking');
  assert.equal(p.state.otaPublicPageDiagnosisOperationSurfaceStatus.value, 'task_not_visible_in_operation_list');
  assert.equal(p.notices[0].type, 'warning');
  p.state.actionVisible.value = true; p.state.otaPublicPageDiagnosisOperationSurfaceAccessible.value = true;
  await p.create(); p.flushFrames();
  assert.equal(p.state.currentPage.value, 'ops-track'); assert.equal(p.scrolls.length, 1);
  assert.equal(p.notices.at(-1).type, 'success');
});

test('unchanged user and tenant object replacements do not discard a valid task response', async t => {
  const p = fixture(t), pending = p.create();
  p.state.authContext.value = { ...p.state.authContext.value };
  p.state.user.value = { ...p.state.user.value };
  p.posts[0].resolve(receipt(p.posts[0])); await pending;
  assert.equal(p.state.otaPublicPageDiagnosisExecutionIntent.value.id, 201);
  assert.equal(p.operationLoads.length, 1); assert.equal(p.notices[0].type, 'success');
});

test('the existing initial payload identity guard still prevents cross-scope task creation', async t => {
  const p = fixture(t); p.state.otaPublicPageDiagnosisPayload.value = diagnosis('8');
  await p.create();
  assert.equal(p.posts.length, 0); assert.equal(p.dialogs.length, 0); assert.equal(p.operationLoads.length, 0);
  assert.equal(p.notices[0].type, 'warning'); assert.match(p.notices[0].message, /重新读取诊断/);
  assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false);
});

test('blocked task capabilities retain a readable warning and never POST or open a task', async t => {
  const cases = [
    ['module_not_entitled', /未开通/],
    ['permission_denied', /无权/],
    ['role_permission_denied', /角色|无权/],
    ['hotel_permission_denied', /门店|无权/],
    ['operation_access_check_failed', /校验失败/],
    ['operation_capability_unavailable', /不可用/],
    ['task_bridge_read_failed', /读取失败|不可用/],
    ['unknown_status', /无法创建|不可用/],
  ];
  for (const [status, message] of cases) {
    const p = fixture(t);
    p.state.otaPublicPageDiagnosisTaskBridge.value = { state: 'create_blocked', create_status: status };
    await p.create();
    assert.equal(p.posts.length, 0, status);
    assert.equal(p.dialogs.length, 0, status);
    assert.equal(p.operationLoads.length, 0, status);
    assert.equal(p.notices.length, 1, status);
    assert.equal(p.notices[0].type, 'warning', status);
    assert.match(p.notices[0].message, message, status);
    assert.equal(p.state.otaPublicPageDiagnosisError.value, '', status);
    assert.equal(p.state.otaPublicPageDiagnosisExecutionLoading.value, false, status);
  }
});
