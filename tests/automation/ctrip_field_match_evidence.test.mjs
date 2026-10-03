import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync('public/app-main.js', 'utf8');
const from = source.indexOf('const setCtripProfileFieldVerification = async (field, status) => {');
const to = source.indexOf('const deleteCtripProfileField = async', from);
assert.ok(from >= 0 && to > from);
function harness() {
  const calls = [], toasts = [], summaries = [], verifying = { value: '' };
  const box = vm.createContext({
    normalizeCtripProfileFieldVerificationStatus: value => value || 'unverified',
    ctripProfileFieldVerifyingId: verifying,
    request: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); return { code: 200, data: {} }; },
    clearCtripProfileFieldCache() {}, mergeCtripProfileFieldUpdate() {},
    updateCtripProfileFieldVerificationSummary: (...args) => summaries.push(args),
    showToast: (message, type) => toasts.push({ message, type }),
  });
  vm.runInContext(`${source.slice(from, to)};this.verify=setCtripProfileFieldVerification;`, box);
  return { verify: box.verify, calls, toasts, summaries, verifying };
}

test('missing selected values cannot POST or increase matched counts', async () => {
  for (const value of [undefined, null, '', '   ']) {
    const h = harness();
    await h.verify({ id: 'fixture-field', verified_sample_value: value }, 'matched');
    assert.equal(h.calls.length, 0);
    assert.equal(h.summaries.length, 0);
    assert.equal(h.verifying.value, '');
    assert.equal(h.toasts[0].type, 'error');
  }
});

test('selected numeric zero is allowed and busy state clears after the response', async () => {
  const h = harness();
  await h.verify({ id: 'fixture-field', verified_sample_value: 0 }, 'matched');
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].body.sample_verification_status, 'matched');
  assert.equal(h.summaries.length, 1);
  assert.equal(h.verifying.value, '');
});

test('explicit mismatch without a selected value remains usable', async () => {
  const h = harness();
  await h.verify({ id: 'fixture-field' }, 'mismatched');
  assert.equal(h.calls.length, 1);
  assert.equal(h.calls[0].body.sample_verification_status, 'mismatched');
});
