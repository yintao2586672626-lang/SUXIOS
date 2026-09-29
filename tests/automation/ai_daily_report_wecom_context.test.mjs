import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const main = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/16-page-ai-daily-report.html', 'utf8');
const start = main.indexOf('const aiDailyReportSendScopeCurrent = () =>');
const end = main.indexOf('const submitAiDailyReportJudgment = async () => {', start);
assert.ok(start >= 0 && end > start);
assert.match(template, /@click="sendAiDailyReportToWecom"/);
assert.match(template, /:disabled="aiDailyReportWecomSending \|\| !aiDailyReportSendScopeCurrent\(\)"/);
assert.match(template, /@click="confirmAiDailyReportWecomSend"/);

const report = (id, hotel, date = '2026-09-15') => ({ id, hotel_id: hotel, report_date: date });
const pending = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
function harness() {
  const requests = [], notices = [];
  const sandbox = {
    aiDailyReport: Vue.ref(report(11, 80)),
    aiDailyReportForm: Vue.ref({ hotel_id: '80', report_date: '2026-09-15' }),
    user: Vue.ref({ is_super_admin: true }),
    aiDailyReportWecomSending: Vue.ref(false),
    aiDailyReportWecomEdition: Vue.ref('lite'),
    aiDailyReportWecomLastResult: Vue.ref(null),
    aiDailyReportWecomConfirmOpen: Vue.ref(false),
    aiDailyReportWecomPendingEdition: Vue.ref('lite'),
    aiDailyReportWecomPendingEditionText: { get value() { return sandbox.aiDailyReportWecomPendingEdition.value === 'flagship' ? '旗舰版' : '简版'; } },
    watch: Vue.watch,
    apiRequest: (path, options) => { const request = pending(); requests.push({ path, options, ...request }); return request.promise; },
    showToast: (message, type) => notices.push({ message, type }),
    operationErrorMessage: error => error.message,
  };
  vm.runInNewContext(`${main.slice(start, end)}
    globalThis.actions = { sendAiDailyReportToWecom, closeAiDailyReportWecomConfirm, confirmAiDailyReportWecomSend };`, sandbox);
  return { ...sandbox, ...sandbox.actions, requests, notices };
}

test('changing the selected daily report invalidates the pending send confirmation', async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, true);
  ui.aiDailyReport.value = report(12, 81);
  const confirmation = ui.confirmAiDailyReportWecomSend();
  if (ui.requests[0]) ui.requests[0].resolve({ code: 200, message: 'sent B', data: {} });
  await confirmation;
  assert.equal(ui.requests.length, 0, 'confirming A must never submit B');
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, false);
});

for (const outcome of ['success', 'failure']) test(`late ${outcome} for another report does not become its send receipt`, async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  const sending = ui.confirmAiDailyReportWecomSend();
  assert.equal(ui.requests[0].path, '/ai-daily-reports/11/send-wecom');
  ui.aiDailyReport.value = report(12, 81);
  if (outcome === 'success') ui.requests[0].resolve({ code: 200, message: 'sent A', data: {} });
  else ui.requests[0].reject(new Error('failed A'));
  await sending;
  assert.equal(ui.aiDailyReportWecomLastResult.value, null);
  assert.equal(ui.notices.length, 0);
  assert.equal(ui.aiDailyReportWecomSending.value, false);
});

test('same-report confirmation still sends its exact ID and displays the receipt', async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  const sending = ui.confirmAiDailyReportWecomSend();
  assert.equal(ui.requests[0].path, '/ai-daily-reports/11/send-wecom');
  ui.requests[0].resolve({ code: 200, message: 'sent A', data: {} });
  await sending;
  assert.equal(ui.aiDailyReportWecomLastResult.value.status, 'sent');
  assert.equal(ui.notices[0].type, 'success');
  ui.aiDailyReport.value = report(12, 81);
  assert.equal(ui.aiDailyReportWecomLastResult.value, null);
});

test('an unexpected delivery code cannot show a sent receipt', async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  const sending = ui.confirmAiDailyReportWecomSend();
  ui.requests[0].resolve({ code: 202, message: 'delivery pending', data: {} });
  await sending;
  assert.equal(ui.aiDailyReportWecomLastResult.value.status, 'failed');
  assert.equal(ui.notices[0].type, 'error');
});

test('changing the form date invalidates a confirmation for the previously selected date', async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  ui.aiDailyReportForm.value.report_date = '2026-09-16';
  const confirmation = ui.confirmAiDailyReportWecomSend();
  await confirmation;
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, false);
});

test('an older report cannot be sent while another business date is selected', async () => {
  const ui = harness();
  ui.aiDailyReportForm.value.report_date = '2026-09-16';
  ui.sendAiDailyReportToWecom();
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, false);
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.notices[0]?.type, 'warning');
});

test('a report from another hotel cannot open a send confirmation', () => {
  const ui = harness();
  ui.aiDailyReport.value = report(11, 81);
  ui.sendAiDailyReportToWecom();
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, false);
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.notices[0]?.type, 'warning');
});

test('changing the loaded report date after confirmation prevents the send', async () => {
  const ui = harness();
  ui.sendAiDailyReportToWecom();
  ui.aiDailyReport.value = report(11, 80, '2026-09-16');
  await ui.confirmAiDailyReportWecomSend();
  assert.equal(ui.aiDailyReportWecomConfirmOpen.value, false);
  assert.equal(ui.requests.length, 0);
});
