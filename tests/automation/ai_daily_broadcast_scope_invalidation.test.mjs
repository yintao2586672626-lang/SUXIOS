import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/components/system/ai-daily-report-delivery.js', 'utf8');
const snapshot = JSON.parse(fs.readFileSync('tests/fixtures/ai-daily-report/broadcast-scope-snapshot.json', 'utf8'));
const identity = { hotel_id: snapshot.hotel_id, report_date: snapshot.business_date };
const deferred = () => {
  let resolve;
  const promise = new Promise(yes => { resolve = yes; });
  return { promise, resolve };
};
function fixture(form = { ...identity }) {
  const calls = [], copies = [], speech = [], notices = [];
  let handler = async () => ({ code: 200, data: snapshot });
  let sessionEpoch = 1;
  const ctx = { aiDailyReport: { ...identity }, showToast: value => notices.push(value),
    assistantSessionEpoch: () => sessionEpoch,
    aiDailyReportDeliveryRequest: (url, options = {}) => { calls.push({ url, options }); return handler(url, options); } };
  if (form !== null) ctx.aiDailyReportForm = form;
  const sandbox = { Vue: { ref: value => ({ __v_isRef: true, value }), watch() {}, onBeforeUnmount() {} },
    window: { SpeechSynthesisUtterance: class { constructor(text) { this.text = text; } },
      speechSynthesis: { speak: value => speech.push(value.text), cancel() {} } },
    navigator: { clipboard: { writeText: async text => copies.push(text) } }, URL, console };
  vm.runInNewContext(source, sandbox);
  return { state: sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setupBroadcast({ ctx }), form, calls, copies, speech, notices,
    handle: next => { handler = next; }, switchSession: () => { sessionEpoch += 1; } };
}

for (const key of ['report_date', 'hotel_id']) {
  test(`clearing ${key} invalidates the saved broadcast despite an old report`, async () => {
    const f = fixture();
    await f.state.loadAiDailyTrustedBroadcast();
    assert.equal(f.state.aiDailyTrustedBroadcast.can_use, true);
    f.form[key] = '';
    await f.state.loadAiDailyTrustedBroadcast();
    assert.equal(f.state.aiDailyTrustedBroadcast.can_use, false);
    assert.equal(f.state.aiDailyTrustedBroadcast.persisted, false);
    assert.equal(f.state.aiDailyTrustedBroadcast.final_text, '');
    assert.equal(f.calls.length, 1, 'empty explicit selection must not reload old report facts');
    assert.equal(await f.state.generateAiDailyTrustedBroadcast(), false);
    assert.equal(await f.state.copyAiDailyTrustedBroadcast(), false);
    assert.equal(f.state.toggleAiDailyTrustedBroadcast(), false);
    assert.equal(f.calls.length, 1, 'empty explicit selection must not save an old date/hotel');
    assert.deepEqual(f.copies, []);
    assert.deepEqual(f.speech, []);
    Object.assign(f.form, identity);
    await f.state.loadAiDailyTrustedBroadcast();
    assert.equal(await f.state.copyAiDailyTrustedBroadcast(), true);
    assert.equal(f.state.toggleAiDailyTrustedBroadcast(), true);
    assert.deepEqual(f.copies, [snapshot.final_text]);
    assert.deepEqual(f.speech, [snapshot.final_text]);
  });

  test(`pending generation cannot revive a cleared ${key}`, async () => {
    const f = fixture();
    await f.state.loadAiDailyTrustedBroadcast();
    const pending = deferred();
    f.handle(() => pending.promise);
    const generation = f.state.generateAiDailyTrustedBroadcast();
    f.form[key] = '';
    pending.resolve({ code: 200, data: snapshot });
    assert.equal(await generation, false, 'scope must be invalid even before the form watcher runs');
    assert.equal(f.calls.length, 2, 'no exact read after invalidation');
    assert.equal(f.notices.length, 0, 'old completion stays silent');
    await f.state.loadAiDailyTrustedBroadcast();
    assert.equal(f.state.aiDailyTrustedBroadcastGenerating, false);
    assert.equal(f.state.aiDailyTrustedBroadcastLoading, false);
    assert.equal(f.state.aiDailyTrustedBroadcast.can_use, false);
  });
}

test('explicit new date does not borrow the old report and read failure can recover', async () => {
  const f = fixture();
  await f.state.loadAiDailyTrustedBroadcast();
  f.form.report_date = '2026-08-24';
  f.handle(async () => { throw new Error('synthetic read failure'); });
  await f.state.loadAiDailyTrustedBroadcast();
  assert.match(f.calls.at(-1).url, /report_date=2026-08-24/);
  assert.equal(f.state.aiDailyTrustedBroadcast.can_use, false);
  assert.match(f.state.aiDailyTrustedBroadcastError, /synthetic read failure/);
  f.form.report_date = identity.report_date;
  f.handle(async () => ({ code: 200, data: snapshot }));
  await f.state.loadAiDailyTrustedBroadcast();
  assert.equal(f.state.aiDailyTrustedBroadcastError, '');
  assert.equal(f.state.aiDailyTrustedBroadcast.snapshot_fingerprint, snapshot.snapshot_fingerprint);
});

test('copy and speech reject an old hotel or date before the post-flush watcher reloads', async () => {
  for (const patch of [{ hotel_id: 81 }, { report_date: '2026-08-24' }]) {
    const f = fixture();
    await f.state.loadAiDailyTrustedBroadcast();
    Object.assign(f.form, patch);
    assert.equal(await f.state.copyAiDailyTrustedBroadcast(), false);
    assert.equal(f.state.toggleAiDailyTrustedBroadcast(), false);
    assert.deepEqual(f.copies, []);
    assert.deepEqual(f.speech, []);
  }
});

test('same hotel and date from a previous login cannot be copied or spoken', async () => {
  const f = fixture();
  await f.state.loadAiDailyTrustedBroadcast();
  f.switchSession();
  assert.equal(await f.state.copyAiDailyTrustedBroadcast(), false);
  assert.equal(f.state.toggleAiDailyTrustedBroadcast(), false);
  assert.deepEqual(f.copies, []);
  assert.deepEqual(f.speech, []);
  await f.state.loadAiDailyTrustedBroadcast();
  assert.equal(await f.state.copyAiDailyTrustedBroadcast(), true);
  assert.deepEqual(f.copies, [snapshot.final_text]);
});

test('late snapshot read from a previous login cannot replace the new session', async () => {
  const f = fixture();
  const pending = deferred();
  f.handle(() => pending.promise);
  const read = f.state.loadAiDailyTrustedBroadcast();
  f.switchSession();
  pending.resolve({ code: 200, data: snapshot });
  await read;
  assert.equal(f.state.aiDailyTrustedBroadcast.can_use, false);
  f.handle(async () => ({ code: 200, data: snapshot }));
  await f.state.loadAiDailyTrustedBroadcast();
  assert.equal(f.state.aiDailyTrustedBroadcast.can_use, true);
});

test('legacy report-only caller without a form can read its exact hotel and date', async () => {
  const f = fixture(null);
  await f.state.loadAiDailyTrustedBroadcast();
  assert.match(f.calls[0].url, /hotel_id=80&report_date=2026-08-23/);
  assert.equal(f.state.aiDailyTrustedBroadcast.final_text, snapshot.final_text);
  assert.equal(f.state.aiDailyTrustedBroadcast.readback_verified, true);
});
