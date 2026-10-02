import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
const component = fs.readFileSync(process.env.MEDIA_COMPONENT_SOURCE || 'public/components/system/operating-intelligence-components.js', 'utf8');
const main = fs.readFileSync('public/app-main.js', 'utf8');
const start = main.indexOf('const updateOperatingQuestionScope =');
const updateSource = main.slice(start, main.indexOf('\n            watch(', start));
const system = fs.readFileSync('public/system-static.js', 'utf8');
const stateSource = system.slice(system.indexOf('const createOperatingQuestionState ='), system.indexOf('const operatingQuestionScopeCooldown ='));
const makeState = new Function(stateSource + ';return createOperatingQuestionState;')();
const clone = x => JSON.parse(JSON.stringify(x));
const tick = async () => { for (let i = 0; i < 14; i++) await Promise.resolve(); };
const gate = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const record = (hotel = 7, id = hotel * 10) => ({ id, hotel_id: hotel, created_by: 17, original_name: `hotel-${hotel}.png`, extraction_status: 'ready', source_sha256: 'a'.repeat(64), content_digest: 'b'.repeat(64), source_retention: 'discarded_after_extraction', boundaries: { source_file_retained: false, hotel_fact_created: false } });
const ok = data => ({ code: 200, data });
const list = (hotel, id) => ok({ list: [record(hotel, id)] });
const find = (node, id) => {
  if (!node || typeof node !== 'object') return null;
  if (node.props?.['data-testid'] === id) return node;
  return (Array.isArray(node.children) ? node.children : [node.children]).map(child => find(child, id)).find(Boolean) || null;
};
class SyntheticFile { constructor() { this.name = 'fixture.png'; } }
function harness(handler) {
  const state = { value: makeState() }, form = { value: { hotel_id: '7', platform: 'ctrip', date_start: '2026-08-12', date_end: '2026-08-12' } }, epoch = { value: 1 };
  const calls = [], mounted = [];
  const scopeContext = { operatingQuestionScopeRequestId:0,operatingQuestionPanelIsActive:()=>true,operatingQuestionState: state, operatingQuestionForm: form, reportHotelOptionExists: () => true, invalidateOperatingQuestionHistory: () => {}, operatingQuestionHistoryOpenRequestId: 0, operatingQuestionScopeCooldown: () => {}, loadOperatingQuestionScopeOptions: async () => {}, loadOperatingQuestionHistory: async () => {} };
  vm.createContext(scopeContext); vm.runInContext(updateSource + ';globalThis.updateScope=updateOperatingQuestionScope;', scopeContext);
  const ui = { state, form, user: { value: { id: 17 } }, sessionEpoch: () => epoch.value, ensureScope: () => Number(form.value.hotel_id), updateScope: scopeContext.updateScope, hotels: { value: [{ id: 7, name: '甲店' }, { id: 8, name: '乙店' }] }, request: async (url, options = {}) => {
    if (!url.startsWith('/agent/local-media-extractions')) return { code: 503, message: 'isolated auxiliary service' };
    const call = { url, method: options.method || 'GET', epoch: epoch.value, options }; calls.push(call);
    return handler(call, calls.length);
  } };
  const runtime = { ref: value => ({ value }), computed: fn => ({ get value() { return fn(); } }), inject: () => ui, h: (type, props, children) => ({ type, props, children }), nextTick: async () => {}, onMounted: fn => mounted.push(fn), onUnmounted: () => {} };
  const sandbox = { window: {}, console, File: SyntheticFile, FormData: class { values = []; append(...args) { this.values.push(args); } } };
  vm.runInNewContext(fs.readFileSync('public/components/system/hotel-data-analyst-components.js', 'utf8'), sandbox);
  vm.runInNewContext(component, sandbox);
  const render = sandbox.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create(runtime).operatingQuestionPanel.setup();
  const node = id => find(render(), id);
  return { state, form, epoch, calls, node, render,
    mount() { mounted.forEach(fn => fn()); },
    switchHotel(value) { return node('operating-question-hotel').props.onChange({ target: { value: String(value) } }); },
    file() { node('local-media-file').props.onChange({ target: { files: [new SyntheticFile()] } }); },
    extract() { return node('local-media-extract').props.onClick(); },
  };
}

for (const outcome of ['success', 'failure']) {
  test(`hotel switch ignores old history ${outcome} after current store history`, async () => {
    const old = gate();
    const h = harness((call, n) => n === 1 ? old.promise : list(8));
    h.mount(); h.switchHotel(8); await tick();
    assert.equal(h.state.value.media_history[0].hotel_id, 8);
    if (outcome === 'success') old.resolve(list(7)); else old.reject(new Error('old failure'));
    await tick();
    assert.equal(h.state.value.media_history[0]?.hotel_id, 8);
    assert.equal(h.state.value.media_error, '');
    assert.ok(h.node('local-media-evidence-80'));
    assert.equal(h.node('local-media-evidence-70'), null);
  });
}

test('hotel A to B to A cannot restore the first A history over the later A readback', async () => {
  const old = gate(); const h = harness((call, n) => n === 1 ? old.promise : list(Number(new URL(call.url, 'http://fixture').searchParams.get('hotel_id')), n * 100));
  h.mount(); h.switchHotel(8); h.switchHotel(7); await tick();
  assert.equal(h.state.value.media_history[0].id, 300);
  old.resolve(list(7, 100)); await tick();
  assert.equal(h.state.value.media_history[0].id, 300);
});

for (const mutation of ['state', 'epoch']) {
  test(`initial history is discarded when ${mutation} changes`, async () => {
    const old = gate(); const h = harness(() => old.promise); h.mount();
    const captured = h.state.value;
    if (mutation === 'state') h.state.value = makeState(); else h.epoch.value++;
    old.resolve(list(7)); await tick();
    assert.deepEqual(clone(captured.media_history), []);
    assert.deepEqual(clone(h.state.value.media_history), []);
  });
}

for (const stage of ['POST', 'exact']) {
  for (const mutation of ['hotel', 'state', 'epoch']) {
    test(`extraction ${stage} wait then ${mutation} change cannot continue old readback`, async () => {
      const held = gate();
      const saved = { ...record(), persistence_status: 'readback_verified' };
      const h = harness(call => call.method === 'POST' ? (stage === 'POST' ? held.promise : ok(saved)) : /\/\d+$/.test(call.url) ? held.promise : list(Number(new URL(call.url, 'http://fixture').searchParams.get('hotel_id'))));
      h.file(); const pending = h.extract(); await tick();
      if (mutation === 'hotel') { h.switchHotel(8); await tick(); }
      else if (mutation === 'state') { h.state.value = makeState(); h.state.value.media_loading = true; }
      else h.epoch.value++;
      const count = h.calls.length;
      held.resolve(ok(saved));
      assert.equal(await pending, null);
      assert.equal(h.calls.length, count);
      assert.equal(h.state.value.media_result, null);
      assert.equal(h.state.value.media_error, '');
      if (mutation === 'state') assert.equal(h.state.value.media_loading, true);
    });
  }
}

test('current extraction preserves exact readback, refresh and explicit evidence selection', async () => {
  const saved = { ...record(), persistence_status: 'readback_verified' };
  const h = harness(call => call.url.includes('?') ? list(7) : ok(saved));
  h.file(); const result = await h.extract();
  assert.equal(result.id, 70);
  assert.deepEqual(h.calls.map(x => x.method), ['POST', 'GET', 'GET']);
  assert.deepEqual(clone(h.state.value.media_selected_ids), []);
  h.node('local-media-use-in-question').props.onClick();
  assert.deepEqual(clone(h.state.value.media_selected_ids), [70]);
  assert.equal(result.boundaries.hotel_fact_created, false);
  assert.equal(result.source_retention, 'discarded_after_extraction');
});

test('failed history can be retried without re-uploading a file', async () => {
  const h = harness((call, n) => n === 1 ? { code: 503, message: 'temporary history failure' } : list(7));
  h.mount(); await tick();
  const retry = h.node('local-media-history-refresh'); assert.ok(retry);
  await retry.props.onClick();
  assert.equal(h.state.value.media_history[0].id, 70);
  assert.ok(h.calls.every(x => x.method === 'GET'));
});

test('malformed history response is not accepted as empty success', async () => {
  const h = harness(() => ok({ list: null })); h.mount(); await tick();
  assert.ok(h.node('local-media-history-error'));
  assert.deepEqual(clone(h.state.value.media_history), []);
});

test('current history loading and retry errors stay separate from extraction failure', async () => {
  const pending = gate();
  const h = harness((call, n) => n === 1 ? pending.promise : ok({ list: [] }));
  h.state.value.media_error = '原提取失败仍需处理';
  h.mount();
  assert.equal(h.node('local-media-history-refresh').props.disabled, true);
  pending.reject(new Error('当前历史连接中断')); await tick();
  assert.ok(h.node('local-media-history-error'));
  assert.equal(h.state.value.media_error, '原提取失败仍需处理');
  assert.equal(h.node('local-media-history-refresh').props.disabled, false);
  await h.node('local-media-history-refresh').props.onClick();
  assert.equal(h.node('local-media-history-error'), null);
  assert.deepEqual(clone(h.state.value.media_history), []);
});

test('failed extraction remains visible but cannot be selected as usable evidence', async () => {
  const failed = { ...record(), extraction_status: 'failed', error_code: 'model_unavailable', persistence_status: 'readback_verified' };
  const h = harness(call => call.url.includes('?') ? ok({ list: [failed] }) : ok(failed));
  h.file(); await h.extract();
  assert.ok(h.node('local-media-readback'));
  assert.equal(h.node('local-media-evidence-70').props.disabled, true);
  assert.equal(h.node('local-media-use-in-question').props.disabled, true);
  h.node('local-media-use-in-question').props.onClick();
  assert.deepEqual(clone(h.state.value.media_selected_ids), []);
});

test('stale rendered history selection cannot bind another hotel to the new question', async () => {
  const h = harness(() => list(8));
  h.state.value.media_history = [record(7)];
  const oldClick = h.node('local-media-evidence-70').props.onClick;
  h.switchHotel(8); await tick();
  oldClick();
  assert.deepEqual(clone(h.state.value.media_selected_ids), []);
});
