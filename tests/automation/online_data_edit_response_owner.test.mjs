import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const start = main.indexOf('const showOnlineDataEditModal = ref(false);');
const end = main.indexOf('// 删除线上数据', start);
assert.ok(start >= 0 && end > start);
const source = main.slice(start, end);
const row = (id = 101) => ({ id, system_hotel_id: id === 101 ? 80 : 81, source: 'ctrip', hotel_id: `SYNTHETIC${id}`, hotel_name: `Synthetic hotel ${id}`, data_date: '2026-09-29', amount: null, quantity: 0, book_order_num: null, comment_score: null, qunar_comment_score: null });

function fixture() {
  const pending = [], notices = [], reads = [], invalidations = [];
  const auth = { epoch: 1 };
  const context = {
    ref: value => ({ value }), computed: fn => ({ get value() { return fn(); } }),
    captureAuthSession: () => ({ epoch: auth.epoch }),
    isAuthSessionCurrent: session => session.epoch === auth.epoch,
    showToast: (message, type) => notices.push({ message, type }),
    loadOnlineDataList: options => { reads.push(options); return Promise.resolve([]); },
    clearOnlineDataReadCaches: () => invalidations.push('read caches cleared'),
    request: (path, options) => {
      assert.equal(path, '/online-data/update-data');
      assert.equal(options.method, 'POST');
      return new Promise((resolve, reject) => pending.push({ payload: JSON.parse(options.body), resolve, reject }));
    },
  };
  vm.createContext(context);
  vm.runInContext(source + '\nglobalThis.edit = { editOnlineDataItem, saveOnlineDataEdit, showOnlineDataEditModal, onlineDataEditForm, onlineDataEditOriginal, saving: typeof onlineDataEditSaving === "undefined" ? { value: false } : onlineDataEditSaving };', context);
  const api = context.edit;
  const open = (id = 101, amount = 20) => { api.editOnlineDataItem(row(id)); api.onlineDataEditForm.value.amount = amount; };
  return { api, auth, open, pending, notices, reads, invalidations };
}

test('late success for record A preserves the newly opened record B draft', async () => {
  const f = fixture(); f.open();
  const run = f.api.saveOnlineDataEdit();
  f.open(102, 30); const draft = f.api.onlineDataEditForm.value;
  f.pending[0].resolve({ code: 200, message: 'Saved synthetic correction' }); await run;
  assert.equal(f.api.showOnlineDataEditModal.value, true);
  assert.equal(f.api.onlineDataEditForm.value, draft);
  assert.equal(draft.amount, 30);
  assert.equal(f.notices.length, 0);
  assert.equal(f.reads.length, 0);
  assert.equal(f.invalidations.length, 1);
});

for (const outcome of ['rejected response', 'network failure']) {
  test(`late ${outcome} for A does not show an error inside B`, async () => {
    const f = fixture(); f.open(); const run = f.api.saveOnlineDataEdit(); f.open(102);
    if (outcome === 'network failure') f.pending[0].reject(new Error('Synthetic offline'));
    else f.pending[0].resolve({ code: 422, message: 'Synthetic invalid correction' });
    await run;
    assert.equal(f.api.showOnlineDataEditModal.value, true);
    assert.equal(f.api.onlineDataEditForm.value.id, 102);
    assert.equal(f.notices.length, 0);
  });
}

test('closing the submitted draft invalidates its late modal effects', async () => {
  const f = fixture(); f.open(); const run = f.api.saveOnlineDataEdit();
  f.api.showOnlineDataEditModal.value = false;
  f.pending[0].resolve({ code: 200 }); await run;
  assert.equal(f.notices.length, 0);
  assert.equal(f.reads.length, 0);
  assert.equal(f.invalidations.length, 1);
});

test('the previous login cannot close a draft in the next login', async () => {
  const f = fixture(); f.open(); const run = f.api.saveOnlineDataEdit();
  f.auth.epoch++; f.open(101, 45);
  f.pending[0].resolve({ code: 200 }); await run;
  assert.equal(f.api.showOnlineDataEditModal.value, true);
  assert.equal(f.api.onlineDataEditForm.value.amount, 45);
  assert.equal(f.notices.length, 0);
  assert.equal(f.reads.length, 0);
  assert.equal(f.invalidations.length, 0);
});

test('the same open draft submits once while saving, then refreshes after success', async () => {
  const f = fixture(); f.open();
  const first = f.api.saveOnlineDataEdit();
  assert.equal(f.api.saving.value, true);
  const duplicate = f.api.saveOnlineDataEdit();
  assert.equal(f.pending.length, 1);
  f.pending[0].resolve({ code: 200 }); await first; await duplicate;
  assert.equal(f.api.saving.value, false);
  assert.equal(f.api.showOnlineDataEditModal.value, false);
  assert.equal(f.reads.length, 1);
  assert.equal(f.reads[0].force, true);
});

test('late A completion cannot unlock the currently saving B draft', async () => {
  const f = fixture(); f.open(); const a = f.api.saveOnlineDataEdit();
  f.open(102, 60); assert.equal(f.api.saving.value, false);
  const b = f.api.saveOnlineDataEdit(); assert.equal(f.api.saving.value, true);
  f.pending[0].resolve({ code: 200 }); await a;
  assert.equal(f.api.saving.value, true);
  assert.equal(f.api.showOnlineDataEditModal.value, true);
  assert.equal(f.notices.length, 0);
  f.pending[1].resolve({ code: 200 }); await b;
  assert.equal(f.api.saving.value, false);
  assert.equal(f.api.showOnlineDataEditModal.value, false);
  assert.equal(f.notices.length, 1);
  assert.equal(f.reads.length, 1);
});

test('an owned failed save retains the draft and can be explicitly retried', async () => {
  const f = fixture(); f.open(); const a = f.api.saveOnlineDataEdit();
  f.pending[0].resolve({ code: 422, message: 'Synthetic correction rejected' }); await a;
  assert.equal(f.api.showOnlineDataEditModal.value, true);
  assert.equal(f.api.onlineDataEditForm.value.amount, 20);
  assert.equal(f.api.saving.value, false);
  assert.equal(f.reads.length, 0);
  const b = f.api.saveOnlineDataEdit();
  f.pending[1].resolve({ code: 200 }); await b;
  assert.equal(f.api.showOnlineDataEditModal.value, false);
});

test('zero and cleared missing metrics keep their original payload semantics', async () => {
  const f = fixture(); f.open(101, 0); f.api.onlineDataEditForm.value.quantity = '';
  const run = f.api.saveOnlineDataEdit();
  assert.deepEqual(f.pending[0].payload, { id: 101, amount: 0, quantity: null });
  f.pending[0].resolve({ code: 200 }); await run;
});

test('an unchanged or closed form cannot start a new write', async () => {
  const f = fixture(); f.api.editOnlineDataItem(row());
  await f.api.saveOnlineDataEdit(); assert.equal(f.pending.length, 0);
  f.api.onlineDataEditForm.value.amount = 12; f.api.showOnlineDataEditModal.value = false;
  const run = f.api.saveOnlineDataEdit();
  const count = f.pending.length;
  for (const item of f.pending) item.resolve({ code: 200 });
  await run;
  assert.equal(count, 0);
});
