import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';

const require = createRequire(new URL('../../package.json', import.meta.url));
const { ref, watch, effectScope } = require('vue');
const source = fs.readFileSync(process.env.SUXIOS_PUBLIC_EVIDENCE_SOURCE || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const section = (start, end, optional = false) => {
  const a = source.indexOf(start);
  if (a < 0 && optional) return '';
  const b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `${start} / ${end}`);
  return source.slice(a, b);
};
const saveSource = section('const parseOtaPublicPageJsonObject =', 'const handleOtaPublicPageEvidenceAction =');
const sequenceSource = section('let otaPublicPageEvidenceSeq =', 'let otaPublicPageDiagnosisRequestSeq =', true);
const scopeWatchSource = section('const invalidateOtaPublicPageEvidence =', source.includes('const invalidateCtripPublicProfileScope =') ? 'const invalidateCtripPublicProfileScope =' : 'watch(platformHotelContext, clearPlatformHotelSearch);', true);
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const drain = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
const values = () => ({
  role: 'self', ota_hotel_id: '1001', collected_at: '2026-09-20T09:00',
  source_url: 'https://hotel.meituan.com/hotel/1001', screenshot_ref: 'synthetic-shot',
  fields_json: '{"rating":0,"room_count":0}',
  evidence_paths_json: '{"rating":"评分区域","room_count":"基础信息"}',
});

function fixture(t, { deferRefresh = false } = {}) {
  const dialogs = [], posts = [], refreshes = [], notices = [];
  const state = {
    selectedCtripHotelId: ref('7'),
    otaPublicPageDiagnosisFilter: ref({ platform: 'meituan', business_date: '2026-09-20' }),
    otaPublicPageEvidenceSaving: ref(false), otaPublicPageDiagnosisError: ref(''), otaPublicPageDiagnosisExecutionLoading: ref(false),
    authContext: ref({ tenantId: 42 }), user: ref({ id: 11, tenant_id: 42 }),
    permission: ref(true), session: ref(1),
  };
  const scopeValue = () => ({
    hotel: state.selectedCtripHotelId.value,
    platform: state.otaPublicPageDiagnosisFilter.value.platform,
    date: state.otaPublicPageDiagnosisFilter.value.business_date,
  });
  const sandbox = {
    ...state, ref, watch, Date, JSON,
    captureAuthSession: () => ({ epoch: state.session.value }),
    isAuthSessionCurrent: owner => owner?.epoch === state.session.value,
    canMaintainOtaConfig: () => state.permission.value,
    findMeituanConfigByHotelId: hotel => ({ poi_id: String(hotel) === '7' ? '1001' : '2001' }),
    ctripCompetitiveLocalDate: () => '2026-09-20',
    showToast: (message, type = 'success') => notices.push({ message, type }),
    openWorkflowFormDialog: options => {
      const pending = deferred(); dialogs.push({ options, ...pending }); return pending.promise;
    },
    request: (url, options = {}) => {
      const pending = deferred();
      posts.push({ url, options, body: JSON.parse(options.body), ...pending });
      return pending.promise;
    },
    loadOtaPublicPageDiagnosis: options => {
      const pending = deferred();
      refreshes.push({ options, scope: scopeValue(), ...pending });
      if (!deferRefresh) pending.resolve({ code: 200, data: { dimensions: [] } });
      return pending.promise;
    },
  };
  const context = vm.createContext(sandbox), effects = effectScope();
  effects.run(() => vm.runInContext(`let otaPublicPageDiagnosisExecutionRequestSeq = 0;\n${sequenceSource}\n${scopeWatchSource}\n${saveSource}\nglobalThis.saveEvidence = saveMeituanPublicPageEvidence;`, context));
  t.after(() => effects.stop());
  return { state, dialogs, posts, refreshes, notices, scopeValue, save: context.saveEvidence };
}

test('refreshing the same account objects keeps the pending save current', async t => {
  const p = fixture(t), {pending, post} = await beginPost(p);
  p.state.authContext.value = {...p.state.authContext.value};
  p.state.user.value = {...p.state.user.value, display_name: '更新的显示名称'};
  p.state.otaPublicPageDiagnosisFilter.value = {...p.state.otaPublicPageDiagnosisFilter.value};
  assert.equal(p.state.otaPublicPageEvidenceSaving.value, true);
  post.resolve(saved(post));
  assert.equal((await pending)?.status, 'saved_readback_verified');
  assert.equal(p.refreshes.length, 1);
  assert.equal(p.notices.at(-1)?.type, 'success');
});

function saved(post) {
  return {
    code: 200, message: '合成公开页证据已保存并回读',
    data: {
      status: 'saved_readback_verified', system_hotel_id: post.body.system_hotel_id, platform: 'meituan',
      profile: {
        system_hotel_id: post.body.system_hotel_id, platform: 'meituan',
        data_date: post.body.business_date, ota_hotel_id: post.body.ota_hotel_id,
        fields: post.body.fields, persistence_readback_verified: true, source_validation_status: 'source_observed',
      },
    },
  };
}

async function beginPost(p, submittedValues = values()) {
  const pending = p.save();
  assert.ok(p.dialogs.length, 'Current scope must open an editable observation form.');
  p.dialogs.at(-1).resolve(submittedValues);
  await drain();
  assert.ok(p.posts.length, 'Valid current form must submit.');
  return { pending, post: p.posts.at(-1) };
}

const changes = {
  date: p => { p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-09-21'; },
  platform: p => { p.state.otaPublicPageDiagnosisFilter.value.platform = 'ctrip'; },
  hotel: p => { p.state.selectedCtripHotelId.value = '8'; },
  session: p => { p.state.session.value++; },
  tenant: p => { p.state.authContext.value.tenantId = 43; },
  user: p => { p.state.user.value.id = 12; },
  permission: p => { p.state.permission.value = false; },
};

// Authentication already cancels an unsubmitted global dialog in the full app.
// These cases exercise other scope invalidations without pretending to test that reset.
for (const [label, change] of Object.entries(changes).filter(([label]) => label !== 'session')) {
  test(`an unsubmitted old form cannot POST after ${label} changes`, async t => {
    const p = fixture(t), pending = p.save();
    change(p); p.dialogs[0].resolve(values()); await drain();
    const submitted = p.posts.length;
    p.posts.forEach(post => post.resolve(saved(post)));
    await pending;
    assert.equal(submitted, 0);
    assert.equal(p.refreshes.length, 0);
    assert.equal(p.notices.length, 0);
    assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
  });
}

for (const [label, change] of Object.entries(changes)) {
  for (const outcome of ['success', 'failure']) {
    test(`old POST ${outcome} cannot affect the current view after ${label} changes`, async t => {
      const p = fixture(t), { pending, post } = await beginPost(p);
      change(p);
      const currentScope = p.scopeValue();
      p.state.otaPublicPageDiagnosisError.value = '当前范围自己的提示';
      if (outcome === 'success') post.resolve(saved(post));
      else post.reject(new Error('旧范围合成 HTTP 失败'));
      await pending;
      assert.deepEqual(p.scopeValue(), currentScope, 'An old receipt must not write its business date back.');
      assert.equal(p.refreshes.length, 0);
      assert.equal(p.notices.length, 0);
      assert.equal(p.state.otaPublicPageDiagnosisError.value, '当前范围自己的提示');
      assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
    });
  }
}

test('synchronous A to B to A scope changes invalidate an old POST', async t => {
  const p = fixture(t), { pending, post } = await beginPost(p);
  p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-09-21';
  p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-09-20';
  post.resolve(saved(post)); await pending;
  assert.equal(p.refreshes.length, 0);
  assert.equal(p.notices.length, 0);
  assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
});

test('an old finally cannot release a new operation lock after a scope change', async t => {
  const p = fixture(t), old = await beginPost(p);
  changes.hotel(p);
  const next = p.save();
  const dialogCount = p.dialogs.length;
  old.post.resolve(saved(old.post)); await old.pending;
  const newLockWhileDialogPending = p.state.otaPublicPageEvidenceSaving.value;
  if (dialogCount > 1) p.dialogs[1].resolve({ ...values(), ota_hotel_id: '2001', source_url: 'https://hotel.meituan.com/hotel/2001' });
  await drain();
  const nextPost = p.posts[1];
  if (nextPost) nextPost.resolve(saved(nextPost));
  await next;
  assert.equal(dialogCount, 2, 'Changing scope must permit a new current form.');
  assert.equal(newLockWhileDialogPending, true, 'Old finally must leave the new dialog locked.');
  assert.equal(nextPost?.body.system_hotel_id, 8);
  assert.equal(p.refreshes.length, 1);
  assert.deepEqual(p.refreshes[0].scope, { hotel: '8', platform: 'meituan', date: '2026-09-20' });
  assert.equal(p.notices.length, 1);
  assert.equal(p.notices[0].type, 'success');
  assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
});

for (const label of ['date', 'session']) {
  test(`scope change during the post-save refresh suppresses an old success notice (${label})`, async t => {
    const p = fixture(t, { deferRefresh: true }), { pending, post } = await beginPost(p);
    post.resolve(saved(post)); await drain();
    assert.equal(p.refreshes.length, 1);
    changes[label](p);
    const currentScope = p.scopeValue();
    p.refreshes[0].resolve(null); await pending;
    assert.deepEqual(p.scopeValue(), currentScope);
    assert.equal(p.notices.length, 0);
    assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
  });
}

test('a failed diagnosis refresh keeps the saved receipt without claiming the view refreshed successfully', async t => {
  const p = fixture(t, { deferRefresh: true }), { pending, post } = await beginPost(p);
  const response = saved(post);
  post.resolve(response); await drain();
  assert.equal(p.refreshes.length, 1);
  p.state.otaPublicPageDiagnosisError.value = '合成公开页诊断读取失败';
  p.refreshes[0].resolve(null);
  const result = await pending;
  assert.equal(result, response.data, 'A read failure must not pretend the completed save was lost.');
  assert.equal(p.state.otaPublicPageDiagnosisError.value, '合成公开页诊断读取失败');
  assert.equal(p.notices.filter(notice => notice.type === 'success').length, 0);
  assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
});

const invalidEntries = {
  'missing hotel': p => { p.state.selectedCtripHotelId.value = ''; },
  'missing date': p => { p.state.otaPublicPageDiagnosisFilter.value.business_date = ''; },
  'malformed date': p => { p.state.otaPublicPageDiagnosisFilter.value.business_date = '2026-9-20'; },
  'non-Meituan platform': changes.platform,
  'missing permission': changes.permission,
};
for (const [label, change] of Object.entries(invalidEntries)) {
  test(`invalid entry ${label} cannot open or submit a form`, async t => {
    const p = fixture(t); change(p);
    const pending = p.save();
    const opened = p.dialogs.length;
    p.dialogs.forEach(dialog => dialog.resolve(null)); await pending;
    assert.equal(opened, 0);
    assert.equal(p.posts.length, 0);
    assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
  });
}

test('one operation owns the dialog and POST and preserves exact scope and real zero fields', async t => {
  const p = fixture(t), pending = p.save(), duplicateDialog = p.save();
  const dialogCount = p.dialogs.length;
  p.dialogs[0].resolve(values());
  p.dialogs.slice(1).forEach(dialog => dialog.resolve(null)); await drain();
  const duplicatePost = p.save();
  const extraDialogs = p.dialogs.length - dialogCount;
  p.dialogs.slice(dialogCount).forEach(dialog => dialog.resolve(null));
  const post = p.posts[0];
  post.resolve(saved(post)); await Promise.all([pending, duplicateDialog, duplicatePost]);
  assert.equal(dialogCount, 1);
  assert.equal(extraDialogs, 0);
  assert.equal(p.posts.length, 1);
  assert.equal(post.url, '/online-data/public-page-evidence');
  assert.deepEqual({ ...post.options.businessContext }, { hotelId: '7', platform: 'meituan' });
  assert.equal(post.body.system_hotel_id, 7);
  assert.equal(post.body.business_date, '2026-09-20');
  assert.equal(post.body.platform, 'meituan');
  assert.equal(post.body.ota_hotel_id, '1001');
  assert.deepEqual(post.body.fields, { rating: 0, room_count: 0 });
  assert.deepEqual(post.body.evidence_paths, { rating: '评分区域', room_count: '基础信息' });
  assert.equal(p.refreshes.length, 1);
  assert.equal(p.notices.length, 1);
  assert.equal(p.notices[0].type, 'success');
  assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
});

for (const reason of ['cancel', 'invalid JSON', 'HTTP failure', 'missing readback']) {
  test(`${reason} releases the operation and a current retry can save`, async t => {
    const p = fixture(t), failed = p.save();
    p.dialogs[0].resolve(reason === 'cancel' ? null : reason === 'invalid JSON' ? { ...values(), fields_json: '{broken' } : values());
    await drain();
    if (reason === 'HTTP failure') p.posts[0].reject(new Error('合成 HTTP 保存失败'));
    if (reason === 'missing readback') p.posts[0].resolve({ code: 200, data: { status: 'saved_without_readback' } });
    await failed;
    assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
    assert.equal(p.refreshes.length, 0);
    assert.equal(p.notices.length, reason === 'cancel' ? 0 : 1);
    if (reason !== 'cancel') assert.equal(p.notices[0].type, 'error');
    const retry = await beginPost(p);
    retry.post.resolve(saved(retry.post)); await retry.pending;
    assert.equal(p.refreshes.length, 1);
    assert.equal(p.notices.at(-1).type, 'success');
    assert.equal(p.state.otaPublicPageDiagnosisError.value, '');
    assert.equal(p.state.otaPublicPageEvidenceSaving.value, false);
  });
}

test('scope watcher registration follows the actual hotel and filter ref declarations', () => {
  const registration = source.indexOf('const invalidateOtaPublicPageEvidence =');
  if (registration < 0) return; // The unfixed source remains runnable for the red baseline.
  for (const dependency of ['const selectedCtripHotelId =', 'const otaPublicPageDiagnosisFilter =']) {
    const declaration = source.indexOf(dependency);
    assert.ok(declaration >= 0 && registration > declaration, `${dependency} must exist before watcher setup.`);
  }
});
