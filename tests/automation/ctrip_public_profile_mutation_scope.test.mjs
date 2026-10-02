import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';

const { ref, computed, watch, effectScope } = createRequire(new URL('../../package.json', import.meta.url))('vue');
const source = fs.readFileSync(process.env.SUXIOS_PUBLIC_PROFILE_SOURCE || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const section = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `${start} / ${end}`);
  return source.slice(a, b);
};
const mutationStart = [
  'const handleCtripPublicProfileHotelChange =',
  'const reloadCtripPublicProfileViews =',
  'const addCtripPublicProfileById =',
].find(marker => source.includes(marker));
const production = [
  section('const ctripPublicProfilePayload =', 'const ctripPublicProfileCaptureStatus ='),
  section('const ctripPublicProfileOwnBinding =', 'const ctripPublicProfileMasterData ='),
  section('const normalizeCtripPublicHotelIdInput =', 'const ctripPublicProfileRoleText ='),
  section('const ctripPublicProfileDisplayName =', 'const ctripPublicProfileSourceUrl ='),
  section('const loadCtripPublicProfiles =', 'const otaPublicPageDiagnosisStatusText ='),
  section(mutationStart, 'let ctripSearchOpportunityRequestSeq ='),
].join('\n');
const watcher = source.includes('const invalidateCtripPublicProfileScope =')
  ? section('const invalidateCtripPublicProfileScope =', 'watch(platformHotelContext, clearPlatformHotelSearch);')
  : '';
const plain = value => JSON.parse(JSON.stringify(value));
const drain = async () => { for (let i = 0; i < 18; i++) await Promise.resolve(); };
const deferred = () => {
  let yes, no;
  const result = { settled: false };
  result.promise = new Promise((resolve, reject) => { yes = resolve; no = reject; });
  result.resolve = value => { result.settled = true; yes(value); };
  result.reject = error => { result.settled = true; no(error); };
  return result;
};
const profile = (id = '1001', name = '合成公开档案') => ({
  ota_hotel_id: id, role: 'competitor', capture_status: 'available',
  source_validation_status: 'source_observed', persistence_readback_verified: true,
  fields: { name, room_count: 0, rating: 4.5 },
});
const payload = (hotel = '7', name = '合成列表回读', rows = [profile('1001', name)]) => ({
  system_hotel_id: Number(hotel), profiles: rows,
  binding: { status: 'bound', ota_hotel_id: '1000' },
});
const operations = ['add', 'all', 'single', 'archive'];

function fixture(t, { hold = [], confirm = true } = {}) {
  const calls = { posts: [], reads: [], diagnosis: [], competitive: [], workspace: [], confirms: [], notices: [] };
  const controls = { hold: new Set(hold), confirm, readOutcome: null, readData: null };
  const external = {
    selectedCtripHotelId: ref('7'), authContext: ref({ tenantId: 42 }), user: ref({ id: 11, tenant_id: 42 }),
    permission: ref(true), session: ref(1), otaPublicPageDiagnosisFilter: ref({ platform: 'ctrip', business_date: '2026-09-20' }),
    ctripCompetitiveOperationsError: ref(''), otaPublicPageDiagnosisError: ref(''), otaPublicPageDiagnosisExecutionIntent: ref(null),
  };
  const replyToRead = row => {
    const outcome = typeof controls.readOutcome === 'function' ? controls.readOutcome(row) : controls.readOutcome;
    if (outcome instanceof Error) row.reject(outcome);
    else row.resolve(outcome || { code: 200, data: controls.readData ? controls.readData(row.hotel) : payload(row.hotel) });
  };
  const dependency = kind => options => {
    const row = { options, hotel: String(external.selectedCtripHotelId.value), ...deferred() };
    calls[kind].push(row);
    if (!controls.hold.has(kind)) row.resolve({ status: 'ready', system_hotel_id: Number(row.hotel) });
    return row.promise;
  };
  const context = vm.createContext({
    ...external, ref, computed, watch, URL, Date, JSON,
    captureAuthSession: () => ({ epoch: external.session.value }),
    isAuthSessionCurrent: owner => owner?.epoch === external.session.value,
    canMaintainOtaConfig: () => external.permission.value,
    showToast: (message, type = 'success') => calls.notices.push({ message, type }),
    window: { confirm: message => { calls.confirms.push(message); return controls.confirm; } },
    request: (url, options = {}) => {
      const row = { url, options, ...deferred() };
      if (String(options.method || 'GET').toUpperCase() === 'POST') {
        row.body = JSON.parse(options.body); calls.posts.push(row);
      } else {
        row.hotel = new URL(url, 'http://synthetic.invalid').searchParams.get('system_hotel_id');
        calls.reads.push(row);
        if (!controls.hold.has('reads')) replyToRead(row);
      }
      return row.promise;
    },
    loadOtaPublicPageDiagnosis: dependency('diagnosis'),
    loadCtripCompetitiveOperations: dependency('competitive'),
    loadCtripCompetitionWorkspace: dependency('workspace'),
  });
  const effects = effectScope();
  effects.run(() => vm.runInContext(`${production}\n${watcher}\nglobalThis.subject = {
    state: { payload: ctripPublicProfilePayload, profiles: ctripPublicProfiles, loading: ctripPublicProfileLoading,
      saving: ctripPublicProfileSaving, refreshing: ctripPublicProfileRefreshing,
      single: ctripPublicProfileSingleRefreshingId, archiving: ctripPublicProfileArchivingId,
      error: ctripPublicProfileError, form: ctripPublicProfileForm, busy: ctripPublicProfileBusy },
    methods: { read: loadCtripPublicProfiles, add: addCtripPublicProfileById, all: refreshCtripPublicProfiles,
      single: refreshCtripPublicProfile, archive: archiveCtripPublicProfile }
  };`, context));
  t.after(() => effects.stop());
  const p = { ...calls, ...context.subject, external, controls, replyToRead };
  p.state.payload.value = payload(); p.state.profiles.value = [profile()];
  p.state.form.value = { ota_hotel_id: '1001', role: 'competitor' };
  p.invoke = kind => p.methods[kind](profile());
  return p;
}

function postReply(post, { status = 'complete' } = {}) {
  const archived = post.url.endsWith('/archive');
  return { code: 200, data: {
    ...payload(post.body.system_hotel_id, '合成写入回执', archived ? [] : undefined),
    status, readback_verified: true,
  } };
}

// Release unexpected baseline follow-up reads too, so an assertion fails instead of hanging.
async function finish(p, pending) {
  let settled = false, failure;
  const observed = Promise.resolve(pending).then(() => { settled = true; }, error => { settled = true; failure = error; });
  for (let i = 0; i < 30 && !settled; i++) {
    await drain();
    for (const row of p.reads) if (!row.settled) p.replyToRead(row);
    for (const kind of ['diagnosis', 'competitive', 'workspace']) {
      for (const row of p[kind]) if (!row.settled) row.resolve({ status: 'ready', system_hotel_id: Number(row.hotel) });
    }
  }
  await drain();
  assert.equal(settled, true, 'The production operation must settle after its observed dependencies settle.');
  await observed;
  if (failure) throw failure;
}

const change = {
  hotel: p => { p.external.selectedCtripHotelId.value = '8'; },
  session: p => { p.external.session.value++; },
  ABA: p => { p.external.selectedCtripHotelId.value = '8'; p.external.selectedCtripHotelId.value = '7'; },
  tenant: p => { p.external.authContext.value.tenantId = 43; },
  user: p => { p.external.user.value.id = 12; },
  permission: p => { p.external.permission.value = false; },
};
function markCurrentState(p) {
  const data = payload(p.external.selectedCtripHotelId.value, '当前范围自己的列表', [profile('2002', '当前范围自己的列表')]);
  p.state.payload.value = data; p.state.profiles.value = data.profiles;
  p.state.error.value = '当前范围自己的提示';
  p.state.form.value = { ota_hotel_id: '2002', role: 'self' };
  return { data: plain(data), form: plain(p.state.form.value) };
}
function assertCurrentState(p, expected) {
  assert.deepEqual(plain(p.state.payload.value), expected.data);
  assert.deepEqual(plain(p.state.profiles.value), expected.data.profiles);
  assert.deepEqual(plain(p.state.form.value), expected.form);
  assert.equal(p.state.error.value, '当前范围自己的提示');
}
function assertUnlocked(p) {
  assert.equal(p.state.loading.value, false); assert.equal(p.state.saving.value, false);
  assert.equal(p.state.refreshing.value, false); assert.equal(p.state.single.value, '');
  assert.equal(p.state.archiving.value, ''); assert.equal(p.state.busy.value, false);
}

for (const kind of operations) {
  for (const identity of ['hotel', 'session', 'ABA']) {
    for (const result of ['success', 'failure']) {
      test(`${kind}: old POST ${result} after ${identity} cannot affect current profiles or start follow-up reads`, async t => {
        const p = fixture(t), pending = p.invoke(kind);
        assert.equal(p.posts.length, 1);
        change[identity](p); const current = markCurrentState(p);
        if (result === 'success') p.posts[0].resolve(postReply(p.posts[0]));
        else p.posts[0].reject(new Error('旧操作合成失败'));
        await finish(p, pending);
        assertCurrentState(p, current);
        assert.equal(p.reads.length, 0); assert.equal(p.diagnosis.length, 0); assert.equal(p.competitive.length, 0);
        assert.equal(p.notices.length, 0); assertUnlocked(p);
      });
    }
  }
}

for (const identity of ['tenant', 'user', 'permission']) {
  test(`public-profile scope invalidates synchronously when ${identity} changes`, async t => {
    const p = fixture(t), pending = p.invoke('all');
    change[identity](p);
    const invalidated = { payload: p.state.payload.value, count: p.state.profiles.value.length, busy: p.state.busy.value };
    p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending);
    assert.deepEqual(invalidated, { payload: null, count: 0, busy: false });
    assert.equal(p.reads.length, 0); assert.equal(p.notices.length, 0); assertUnlocked(p);
  });
}

for (const kind of operations) {
  for (const stage of ['reads', 'diagnosis', 'competitive']) {
    if (kind === 'archive' && stage === 'diagnosis') continue;
    test(`${kind}: ownership is rechecked after the ${stage} await`, async t => {
      const p = fixture(t, { hold: [stage] }), pending = p.invoke(kind);
      p.posts[0].resolve(postReply(p.posts[0])); await drain();
      assert.equal(p[stage].length, 1, `The real ${kind} function must reach ${stage}.`);
      const before = { reads: p.reads.length, diagnosis: p.diagnosis.length, competitive: p.competitive.length };
      change[stage === 'reads' ? 'hotel' : stage === 'diagnosis' ? 'session' : 'ABA'](p);
      const current = markCurrentState(p);
      if (stage === 'reads') p.replyToRead(p.reads[0]); else p[stage][0].resolve({ status: 'ready' });
      await finish(p, pending);
      assertCurrentState(p, current);
      assert.equal(p.reads.length, before.reads);
      assert.equal(p.diagnosis.length, before.diagnosis);
      assert.equal(p.competitive.length, before.competitive);
      assert.equal(p.notices.length, 0); assertUnlocked(p);
    });
  }
}

for (const kind of operations) {
  test(`${kind}: an old finally cannot release the new hotel's mutation lock, including the same OTA id`, async t => {
    const p = fixture(t), old = p.invoke(kind);
    change.hotel(p); markCurrentState(p); p.state.form.value = { ota_hotel_id: '1001', role: 'competitor' };
    const current = p.invoke(kind), nextPost = p.posts[1];
    p.posts[0].reject(new Error('旧操作延迟失败')); await finish(p, old);
    const currentBusy = p.state.busy.value;
    if (nextPost) nextPost.resolve(postReply(nextPost));
    await finish(p, current);
    assert.ok(nextPost, 'A changed scope must permit a new operation before the obsolete one finishes.');
    assert.equal(nextPost.body.system_hotel_id, 8);
    if (kind === 'single' || kind === 'archive') assert.equal(nextPost.body.ota_hotel_id, '1001');
    assert.equal(currentBusy, true);
    assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, 'success'); assertUnlocked(p);
  });

  test(`${kind}: equal identity object refreshes do not discard the current operation`, async t => {
    const p = fixture(t), pending = p.invoke(kind);
    p.external.authContext.value = { ...p.external.authContext.value };
    p.external.user.value = { ...p.external.user.value };
    p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending);
    assert.equal(p.reads.length, 1); assert.equal(p.competitive.length, 1);
    assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, 'success'); assertUnlocked(p);
  });

  test(`${kind}: a busy public-profile operation cannot be submitted again`, async t => {
    const p = fixture(t), first = p.invoke(kind), second = p.invoke(kind);
    const submitted = p.posts.length;
    for (const post of p.posts) post.resolve(postReply(post));
    await finish(p, Promise.all([first, second]));
    assert.equal(submitted, 1); assert.equal(p.notices.length, 1); assertUnlocked(p);
  });

  test(`${kind}: missing maintenance permission cannot submit`, async t => {
    const p = fixture(t); p.external.permission.value = false;
    const pending = p.invoke(kind), submitted = p.posts.length;
    for (const post of p.posts) post.resolve(postReply(post));
    await finish(p, pending);
    assert.equal(submitted, 0); assert.equal(p.reads.length, 0);
    assert.equal(p.notices.some(row => row.type === 'success'), false); assertUnlocked(p);
  });
}

test('changing the diagnosis platform or business date does not invalidate a public-profile mutation', async t => {
  const p = fixture(t), pending = p.invoke('all');
  p.external.otaPublicPageDiagnosisFilter.value = { platform: 'meituan', business_date: '2026-09-21' };
  p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending);
  assert.equal(p.reads.length, 1); assert.equal(p.notices[0].type, 'success'); assertUnlocked(p);
});

for (const kind of operations) {
  for (const partial of kind === 'archive' ? [false] : [false, true]) {
    test(`${kind}: current ${partial ? 'partial' : 'successful'} result retains exact scope, zero and source quality`, async t => {
      const p = fixture(t), pending = p.invoke(kind), post = p.posts[0];
      assert.equal(p.posts.length, 1);
      const suffix = kind === 'add' ? 'add' : kind === 'archive' ? 'archive' : 'sync';
      assert.equal(post.url, `/online-data/ctrip/public-profiles/${suffix}`);
      assert.deepEqual(plain(post.options.businessContext), { hotelId: '7', platform: 'ctrip' });
      assert.equal(post.body.system_hotel_id, 7);
      if (kind !== 'all') assert.equal(post.body.ota_hotel_id, '1001');
      if (kind === 'all') {
        assert.equal(post.body.scope, 'all'); assert.equal(post.body.limit, 30); assert.equal(post.body.force, true);
      }
      if (kind === 'add') { assert.equal(post.body.role, 'competitor'); assert.equal(post.body.replace, false); }
      if (kind === 'archive') p.controls.readData = hotel => payload(hotel, '归档后另一家', [profile('2002')]);
      post.resolve(postReply(post, { status: partial ? kind === 'add' ? 'binding_saved_collection_failed' : 'partial' : 'complete' }));
      await finish(p, pending);
      assert.equal(p.reads.length, 1); assert.equal(p.reads[0].hotel, '7');
      assert.equal(p.diagnosis.length, kind === 'archive' ? 0 : 1); assert.equal(p.competitive.length, 1);
      assert.equal(p.state.payload.value.system_hotel_id, 7);
      assert.equal(p.state.profiles.value[0].fields.room_count, 0);
      assert.equal(p.state.profiles.value[0].source_validation_status, 'source_observed');
      assert.equal(p.state.profiles.value[0].persistence_readback_verified, true);
      if (kind === 'archive') assert.equal(p.state.profiles.value.some(row => row.ota_hotel_id === '1001'), false);
      if (kind === 'add') assert.equal(p.state.form.value.ota_hotel_id, '');
      assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, partial ? 'warning' : 'success');
      assert.equal(p.state.error.value, ''); assertUnlocked(p);
    });
  }

  test(`${kind}: a successful POST followed by failed profile readback is not presented as success and can recover`, async t => {
    const p = fixture(t); p.controls.readOutcome = new Error('合成列表回读失败');
    const pending = p.invoke(kind); p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending);
    assert.equal(p.diagnosis.length, 0); assert.equal(p.competitive.length, 0);
    assert.equal(p.notices.some(row => row.type === 'success'), false);
    assert.ok(p.notices.length >= 1); assert.match(String(p.notices.at(-1).message), /合成列表回读失败/);
    assert.match(p.state.error.value, /合成列表回读失败/); assertUnlocked(p);
    if (kind === 'add') assert.equal(p.state.form.value.ota_hotel_id, '1001');
    p.controls.readOutcome = null;
    const retry = p.invoke(kind), post = p.posts.at(-1); post.resolve(postReply(post)); await finish(p, retry);
    assert.equal(p.notices.at(-1).type, 'success'); assert.equal(p.state.error.value, ''); assertUnlocked(p);
  });
}

for (const kind of ['all', 'single', 'archive']) {
  for (const readFails of [false, true]) {
    test(`${kind}: POST failure survives ${readFails ? 'a second read failure' : 'successful recovery read'} as a nonempty original reason`, async t => {
      const p = fixture(t);
      if (readFails) p.controls.readOutcome = new Error('合成恢复读取失败');
      const pending = p.invoke(kind); p.posts[0].reject(new Error('合成写操作原始失败')); await finish(p, pending);
      assert.ok(p.reads.length >= 1); assert.equal(p.diagnosis.length, 0); assert.equal(p.competitive.length, 0);
      assert.match(p.state.error.value, /合成写操作原始失败/);
      assert.equal(p.notices.length, 1); assert.equal(p.notices[0].type, 'error');
      assert.match(String(p.notices[0].message), /合成写操作原始失败/);
      if (readFails) {
        assert.match(p.state.error.value, /合成恢复读取失败/);
        assert.match(String(p.notices[0].message), /合成恢复读取失败/);
      }
      assertUnlocked(p);
    });
  }

  test(`${kind}: an obsolete failure-recovery read cannot replace the current scope's error or toast`, async t => {
    const p = fixture(t, { hold: ['reads'] }), pending = p.invoke(kind);
    p.posts[0].reject(new Error('旧写入失败')); await drain(); assert.equal(p.reads.length, 1);
    change.hotel(p); const current = markCurrentState(p);
    p.replyToRead(p.reads[0]); await finish(p, pending);
    assertCurrentState(p, current); assert.equal(p.notices.length, 0); assertUnlocked(p);
  });
}

test('add retains its current POST failure and accepts a later explicit retry', async t => {
  const p = fixture(t), pending = p.invoke('add');
  p.posts[0].reject(new Error('合成添加失败')); await finish(p, pending);
  assert.match(p.state.error.value, /合成添加失败/); assert.equal(p.notices[0].type, 'error');
  assert.equal(p.state.form.value.ota_hotel_id, '1001'); assertUnlocked(p);
  const retry = p.invoke('add'); p.posts.at(-1).resolve(postReply(p.posts.at(-1))); await finish(p, retry);
  assert.equal(p.notices.at(-1).type, 'success'); assertUnlocked(p);
});

for (const identity of ['hotel', 'session', 'ABA']) {
  for (const result of ['success', 'failure']) {
    test(`list read: old ${result} after ${identity} cannot replace current data or error`, async t => {
      const p = fixture(t, { hold: ['reads'] }), pending = p.methods.read();
      assert.equal(p.reads.length, 1);
      change[identity](p); const current = markCurrentState(p);
      if (result === 'success') p.replyToRead(p.reads[0]); else p.reads[0].reject(new Error('旧列表请求失败'));
      await finish(p, pending);
      assertCurrentState(p, current); assert.equal(p.notices.length, 0); assertUnlocked(p);
    });
  }
}

test('list read: selecting no hotel clears loading and data immediately while the old request is pending', async t => {
  const p = fixture(t, { hold: ['reads'] }), pending = p.methods.read();
  p.external.selectedCtripHotelId.value = '';
  await p.methods.read();
  const empty = { loading: p.state.loading.value, payload: p.state.payload.value, count: p.state.profiles.value.length };
  p.replyToRead(p.reads[0]); await finish(p, pending);
  assert.deepEqual(empty, { loading: false, payload: null, count: 0 });
  assert.equal(p.reads.length, 1); assertUnlocked(p);
});

test('list read: an old finally cannot clear the new hotel read lock', async t => {
  const p = fixture(t, { hold: ['reads'] }), old = p.methods.read();
  change.hotel(p); const current = p.methods.read();
  p.replyToRead(p.reads[0]); await old;
  assert.equal(p.state.loading.value, true);
  p.replyToRead(p.reads[1]); await current;
  assert.equal(p.state.payload.value.system_hotel_id, 8); assertUnlocked(p);
});

test('list read: current failure is visible and a successful retry preserves real zero', async t => {
  const p = fixture(t); p.controls.readOutcome = new Error('当前列表读取失败');
  await p.methods.read();
  assert.equal(p.state.payload.value, null); assert.deepEqual(plain(p.state.profiles.value), []);
  assert.match(p.state.error.value, /当前列表读取失败/); assert.equal(p.notices[0].type, 'error'); assertUnlocked(p);
  p.controls.readOutcome = null; await p.methods.read();
  assert.equal(p.state.error.value, ''); assert.equal(p.state.profiles.value[0].fields.room_count, 0); assertUnlocked(p);
});

test('add accepts a numeric OTA id or its canonical public URL without changing the scoped body', async t => {
  for (const input of ['1001', ' https://hotels.ctrip.com/hotels/1001.html ']) {
    const p = fixture(t); p.state.form.value.ota_hotel_id = input;
    const pending = p.invoke('add'); assert.equal(p.posts.length, 1); assert.equal(p.posts[0].body.ota_hotel_id, '1001');
    p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending); assertUnlocked(p);
  }
  const invalid = fixture(t); invalid.state.form.value.ota_hotel_id = 'https://other.invalid/hotels/1001.html';
  await invalid.invoke('add'); assert.equal(invalid.posts.length, 0); assert.equal(invalid.notices[0].type, 'warning'); assertUnlocked(invalid);
});

test('add preserves existing own-hotel replacement confirmation and cancellation', async t => {
  const p = fixture(t, { confirm: false }); p.state.form.value.role = 'self';
  await p.invoke('add');
  assert.equal(p.confirms.length, 1); assert.equal(p.posts.length, 0); assert.equal(p.state.form.value.ota_hotel_id, '1001'); assertUnlocked(p);
  p.controls.confirm = true;
  const pending = p.invoke('add'); assert.equal(p.posts[0].body.replace, true); assert.equal(p.posts[0].body.role, 'self');
  p.posts[0].resolve(postReply(p.posts[0])); await finish(p, pending); assertUnlocked(p);
});

test('archive cannot archive self and cancellation makes no request', async t => {
  const p = fixture(t, { confirm: false });
  await p.methods.archive({ ...profile(), role: 'self' });
  assert.equal(p.confirms.length, 0); assert.equal(p.posts.length, 0);
  await p.invoke('archive'); assert.equal(p.confirms.length, 1); assert.equal(p.posts.length, 0); assertUnlocked(p);
});

for (const kind of operations) {
  test(`${kind}: caller rechecks ownership after the shared views helper returns`, async t => {
    const p = fixture(t, { hold: ['competitive'] }), pending = p.invoke(kind);
    p.posts[0].resolve(postReply(p.posts[0])); await drain();
    assert.equal(p.competitive.length, 1);
    const lastDependency = p.competitive[0];
    let current, noticesAtScopeChange;
    // This host promise first delivers its result to the VM's await. The next
    // reaction queues a scope change after the helper's continuation, before
    // its awaiting caller resumes. The real helper and owner predicate run.
    const scopeChangeQueued = lastDependency.promise.then(() => {
      queueMicrotask(() => {
        change.hotel(p);
        current = markCurrentState(p);
        noticesAtScopeChange = p.notices.length;
      });
    });
    lastDependency.resolve({ status: 'ready', system_hotel_id: 7 });
    await finish(p, pending); await scopeChangeQueued; await drain();
    assert.ok(current, 'The real microtask scope change must have run.');
    assertCurrentState(p, current);
    assert.equal(p.notices.length, noticesAtScopeChange, 'The obsolete caller must not announce success after the scope change.');
    assert.equal(p.reads.length, 1); assert.equal(p.competitive.length, 1);
    assertUnlocked(p);
  });
}
