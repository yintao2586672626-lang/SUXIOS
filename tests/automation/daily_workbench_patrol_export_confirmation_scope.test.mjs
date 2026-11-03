import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';

const dir = path.dirname(fileURLToPath(import.meta.url));
const mainSource = readFileSync('public/app-main.js', 'utf8');
function between(start, end) {
  const a = mainSource.indexOf(start); const b = mainSource.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `production markers ${start}`);
  return mainSource.slice(a, b);
}
const exportStates = [...mainSource.matchAll(/^\s*let dailyWorkbenchPatrolReportExport(?:ing|Sequence|Scope) = [^\n]+;$/gm)]
  .map(match => match[0]).join('\n');
assert.match(exportStates, /dailyWorkbenchPatrolReportExporting/);
const production = [
  exportStates,
  between('let authSessionEpoch = 0;', 'const createDefaultAuthContext'),
  between('const createWorkflowFormDialogState =', 'let runtimeErrorRecoveryQueued'),
  between('const exportDailyWorkbenchPatrolReport =', 'const dailyWorkbenchPatrolActionKey ='),
].join('\n');
const results = [];
const ref = value => ({ value });
const oldSnapshot = () => ({
  run_id: 'synthetic-h80-20260908-A',
  scope: { hotel_id: '80', start_date: '2026-09-08', end_date: '2026-09-08' },
});
const nextSnapshot = (hotel = '80', date = '2026-09-22', suffix = 'B') => ({
  run_id: `synthetic-h${hotel}-${date.replaceAll('-', '')}-${suffix}`,
  scope: { hotel_id: hotel, start_date: date, end_date: date },
});
const confirmationText = '导出只读取已有巡检快照，但会写入一条导出审计日志；不会触发 OTA 采集，也不会改写业务表。确认继续？';

function harness() {
  const state = {
    token: ref('synthetic-session-A'), hotel: ref('80'), date: '2026-09-08',
    patrol: ref({ latest: oldSnapshot() }), pageMarker: 'page-A',
    requests: [], downloads: [], toasts: [], errors: [],
  };
  const gates = [];
  const clearCalls = [];
  const error = ref('');
  const context = vm.createContext({
    ref, URLSearchParams,
    token: state.token,
    coreOperationsHotelId: state.hotel,
    dailyWorkbenchPatrolLatest: { get value() { return state.patrol.value.latest; } },
    dailyWorkbenchWriteBoundary: ref({ export: { confirmText: confirmationText } }),
    dailyWorkbenchPatrolError: error,
    API_BASE: 'https://synthetic.invalid/api',
    fetch: (url, options) => {
      const parsed = new URL(url);
      state.requests.push({
        run_id: parsed.searchParams.get('run_id'), hotel_id: parsed.searchParams.get('hotel_id'),
        session: options.headers.Authorization === 'synthetic-session-A' ? 'A' : 'B',
        method: options.method, cache: options.cache,
      });
      return new Promise(resolve => { gates.push(resolve); });
    },
    downloadBlob: (blob, filename) => state.downloads.push({ content: blob.content, filename }),
    showToast: (message, type) => state.toasts.push({ message, type }),
    console: { error: (label, errorValue) => state.errors.push({ label, message: errorValue.message }) },
    normalizeTokenStatusFromReason: () => 'synthetic-token-revoked',
    clearAuthSessionIfCurrent: (...args) => { clearCalls.push('synthetic-clear'); return true; },
  });
  vm.runInContext(`${production}\n
    this.exportReport = exportDailyWorkbenchPatrolReport;
    this.confirm = submitWorkflowFormDialog;
    this.cancel = () => closeWorkflowFormDialog(null);
    this.dialog = workflowFormDialog;
    this.changeSession = () => { token.value = 'synthetic-session-B'; authSessionEpoch += 1; };
    this.exporting = () => dailyWorkbenchPatrolReportExporting;
  `, context, { filename: 'captured-production-functions.js' });
  return { state, context, release: (value, index = 0) => gates[index]?.(value), error, clearCalls };
}

function response({ status = 200, run = 'synthetic-h80-20260908-A' } = {}) {
  return {
    ok: status === 200, status,
    headers: { get: name => name === 'content-type' ? 'text/markdown; charset=utf-8' : '' },
    json: async () => ({ message: status === 404 ? 'Synthetic hotel scope rejected' : 'Synthetic account scope rejected' }),
    blob: async () => ({ content: `# Synthetic snapshot ${run}\nSynthetic test-only report` }),
  };
}

async function settleConfirmation() {
  await Promise.resolve();
  await Promise.resolve();
}

function switchPage(h, hotel, date = '2026-09-22', suffix = 'B') {
  h.state.hotel.value = hotel;
  h.state.date = date;
  h.state.patrol.value = { latest: nextSnapshot(hotel, date, suffix) };
  h.state.pageMarker = `page-${suffix}`;
}

function record(id, h, expected, predicate, extra = {}) {
  const result = {
    id, expected, status: predicate ? 'PASS' : 'FAIL',
    actual: {
      requests: h.state.requests, downloads: h.state.downloads, toasts: h.state.toasts,
      error: h.error.value, dialog_visible: h.context.dialog.value.visible,
      page: { marker: h.state.pageMarker, hotel_id: h.state.hotel.value, date: h.state.date,
        run_id: h.state.patrol.value.latest.run_id },
      exporting: h.context.exporting(),
    }, ...extra,
  };
  results.push(result);
  if (process.env.SUXI_EXPORT_CASE_RESULTS) writeFileSync(process.env.SUXI_EXPORT_CASE_RESULTS, JSON.stringify(results, null, 2) + '\n');
  assert.equal(predicate, true, `${id}: ${expected}; actual=${JSON.stringify(result.actual)}`);
}

function begin(h) {
  const operation = h.context.exportReport();
  assert.equal(h.context.dialog.value.visible, true);
  assert.equal(h.context.dialog.value.description, confirmationText);
  assert.equal(h.state.requests.length, 0);
  return operation;
}

test('C01 confirmation pending date switch retains the initiated snapshot', async () => {
  const h = harness(); const operation = begin(h);
  switchPage(h, '80');
  const dialogAfterSwitch = h.context.dialog.value.visible;
  h.context.confirm(); await settleConfirmation(); h.release(response()); await operation;
  const request = h.state.requests[0];
  record('C01', h, 'original run A / hotel 80 / session A, or safe stop; changed page stays intact',
    (h.state.requests.length === 0 || (request.run_id === oldSnapshot().run_id && request.hotel_id === '80' && request.session === 'A'))
    && h.state.downloads.every(item => item.content.includes(oldSnapshot().run_id))
    && h.state.pageMarker === 'page-B' && h.state.patrol.value.latest.run_id === nextSnapshot().run_id,
    { dialog_after_switch: dialogAfterSwitch });
});

test('C02 confirmation pending hotel switch must not mix old run with new hotel', async () => {
  const h = harness(); const operation = begin(h);
  switchPage(h, '81');
  const dialogAfterSwitch = h.context.dialog.value.visible;
  h.context.confirm(); await settleConfirmation();
  // The stub rejects an invalid hotel/run pair; no real server or audit write is invoked.
  h.release(response({ status: 404 })); await operation;
  const request = h.state.requests[0];
  record('C02', h, 'hotel changes during confirmation safely stop with zero requests',
    h.state.requests.length === 0
    && h.state.pageMarker === 'page-B',
    { dialog_after_switch: dialogAfterSwitch, stub_policy: 'reject hotel/run mismatch with 404' });
});

test('C03 confirmation pending account switch must stop the prior-account confirmation', async () => {
  const h = harness(); const operation = begin(h);
  h.context.changeSession(); switchPage(h, '80', '2026-09-22', 'account-B');
  const dialogAfterSwitch = h.context.dialog.value.visible;
  const dialogIdentityFields = h.context.dialog.value.fields.length;
  h.context.confirm(); await settleConfirmation();
  // A denied synthetic response avoids inventing a cross-account successful download.
  h.release(response({ status: 403 })); await operation;
  record('C03', h, 'old account confirmation safely stops; account B requires explicit fresh snapshot confirmation',
    h.state.requests.length === 0 && h.state.downloads.length === 0
    && h.state.pageMarker === 'page-account-B',
    { dialog_after_switch: dialogAfterSwitch, dialog_identity_fields: dialogIdentityFields,
      expected_basis: 'user requirement plus identity contract interpretation; no explicit automatic-modal-cancellation rule',
      stub_policy: 'reject prior-account snapshot with 403; no authorization bypass claim' });
});

test('C04 cancel confirmation sends no request and creates no download', async () => {
  const h = harness(); const operation = begin(h);
  h.context.cancel(); await operation;
  record('C04', h, 'no request, download, error toast or export-in-progress after cancel',
    h.state.requests.length === 0 && h.state.downloads.length === 0 && h.state.toasts.length === 0
    && h.error.value === '' && !h.context.dialog.value.visible && !h.context.exporting());
});

test('C05 hotel switch while response pending retains already confirmed original snapshot', async () => {
  const h = harness(); const operation = begin(h);
  h.context.confirm(); await settleConfirmation();
  assert.equal(h.state.requests.length, 1);
  switchPage(h, '81'); h.release(response()); await operation;
  const request = h.state.requests[0];
  record('C05', h, 'confirmed run A / hotel 80 can finish in same session; hotel 81 page stays intact',
    request.run_id === oldSnapshot().run_id && request.hotel_id === '80' && request.session === 'A'
    && h.state.downloads.length === 1 && h.state.downloads[0].content.includes(oldSnapshot().run_id)
    && h.state.pageMarker === 'page-B' && h.state.hotel.value === '81'
    && h.state.patrol.value.latest.run_id === nextSnapshot('81').run_id);
});

test('C06 account switch while response pending stops the old-account download', async () => {
  const h = harness(); const operation = begin(h);
  h.context.confirm(); await settleConfirmation();
  assert.equal(h.state.requests.length, 1);
  h.context.changeSession(); switchPage(h, '80', '2026-09-22', 'account-B');
  h.release(response()); await operation;
  record('C06', h, 'old account response causes no download, toast or error; new account page stays intact',
    h.state.requests[0].session === 'A' && h.state.downloads.length === 0
    && h.state.toasts.length === 0 && h.error.value === ''
    && h.state.pageMarker === 'page-account-B' && !h.context.exporting());
});


test('C07 replacement confirmation cancels the old dialog without sending its request', async () => {
  const h = harness(); const first = begin(h);
  switchPage(h, '81'); const second = h.context.exportReport();
  await settleConfirmation();
  const newDialogVisible = h.context.dialog.value.visible;
  if (newDialogVisible) h.context.confirm();
  await settleConfirmation();
  h.release(response({ run: nextSnapshot('81').run_id }));
  await Promise.all([first, second]);
  record('C07', h, 'replacement dialog sends only current hotel 81 / snapshot B; canceled old dialog sends nothing',
    newDialogVisible && h.state.requests.length === 1
    && h.state.requests[0].hotel_id === '81' && h.state.requests[0].run_id === nextSnapshot('81').run_id
    && h.state.downloads.length === 1 && !h.context.exporting());
});

for (const [id, account] of [['C08', false], ['C09', true]]) {
  test(`${id} old finally cannot clear a newer export after ${account ? 'account' : 'hotel'} switch`, async () => {
    const h = harness(); const first = begin(h);
    h.context.confirm(); await settleConfirmation();
    if (account) h.context.changeSession();
    switchPage(h, account ? '80' : '81', '2026-09-22', 'new-owner');
    const second = h.context.exportReport(); await settleConfirmation();
    const reopened = h.context.dialog.value.visible;
    if (reopened) h.context.confirm();
    await settleConfirmation();
    const newRequestStarted = h.state.requests.length === 2;
    h.release(response({ status: account ? 403 : 200 }), 0); await first;
    const afterOld = { exporting: h.context.exporting(), error: h.error.value,
      downloads: h.state.downloads.length, toasts: h.state.toasts.length };
    const newerRun = h.state.patrol.value.latest.run_id;
    if (newRequestStarted) h.release(response({ run: newerRun }), 1);
    await second;
    record(id, h, 'new scope can confirm while old export waits; old settlement cannot clear busy state or add results',
      reopened && newRequestStarted && afterOld.exporting && afterOld.error === ''
      && afterOld.downloads === 0 && afterOld.toasts === 0
      && h.state.downloads.length === 1 && h.state.downloads[0].content.includes(newerRun)
      && !h.context.exporting(), { after_old_settlement: afterOld });
  });
}
