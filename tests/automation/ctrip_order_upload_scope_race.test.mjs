import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref, computed, watch } from 'vue';

const appSource = fs.readFileSync('public/app-main.js', 'utf8');
const panelSource = fs.readFileSync('public/components/online-data/ctrip-order-analysis-panel.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const uploadSectionStart = template.indexOf('data-testid="ctrip-channel-order-upload"');
const uploadSectionEnd = template.indexOf('</section>', uploadSectionStart);
assert.ok(uploadSectionStart >= 0 && uploadSectionEnd > uploadSectionStart, 'locate the production upload section');
const uploadSection = template.slice(uploadSectionStart, uploadSectionEnd);
const start = appSource.indexOf('        const ctripChannelOrderUploadOpen = ref(false);');
const end = appSource.indexOf('        const ctripOrderSummaryMetricDefinitions =', start);
assert.ok(start >= 0 && end > start, 'extract the production upload/result state owner');
const uploadSource = appSource.slice(start, end);

function harness() {
  const pending = [];
  const hotelId = ref(80);
  const hotelName = ref('Synthetic hotel A');
  class FakeFormData {
    constructor() { this.rows = []; }
    append(key, value) { this.rows.push([key, value]); }
  }
  const context = vm.createContext({
    ref, watch,
    computed,
    FormData: FakeFormData,
    platformHotelSelectedId: hotelId,
    platformHotelSelectedName: hotelName,
    platformHotelContext: ref('ctrip'), authSessionEpoch: 1,
    token: ref('synthetic-token'),
    readAuthToken: () => '',
    fetch: (url, options) => new Promise((resolve, reject) => pending.push({ url, options, resolve, reject })),
  });
  const watcher = appSource.match(/watch\(\[platformHotelContext, platformHotelSelectedId, token\], resetCtripChannelOrderUploadScope[^;]*;/)?.[0];
  assert.ok(watcher, 'Production scope invalidation is exercised');
  vm.runInContext(uploadSource + watcher + `
    globalThis.upload = uploadCtripChannelOrders;
    globalThis.changeFile = handleCtripChannelOrderFileChange;
    globalThis.acknowledgePartialReview = acknowledgeCtripChannelOrderPartialReview;
    globalThis.uploadState = {
      file: ctripChannelOrderUploadFile,
      fileInput: ctripChannelOrderUploadFileInput,
      result: ctripChannelOrderUploadResult,
      partialReceipt: ctripChannelOrderUploadPartialReceipt,
      error: ctripChannelOrderUploadError,
      scope: typeof ctripChannelOrderUploadScope === 'undefined' ? null : ctripChannelOrderUploadScope,
      scopeMismatch: typeof ctripChannelOrderUploadScopeMismatch === 'undefined' ? null : ctripChannelOrderUploadScopeMismatch,
      fileScope: typeof ctripChannelOrderUploadFileScope === 'undefined' ? null : ctripChannelOrderUploadFileScope,
      fileScopeMismatch: typeof ctripChannelOrderUploadFileScopeMismatch === 'undefined' ? null : ctripChannelOrderUploadFileScopeMismatch,
    };
  `, context);
  context.changeFile({ target: { files: vm.runInContext("[{ name: 'orders.csv', size: 7 }]", context) } });
  const window = { SUXI_SYSTEM_COMPONENTS: {} };
  vm.runInContext(panelSource, vm.createContext({ window, URLSearchParams, sessionStorage: { getItem: () => '' } }));
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const view = currentId => {
    const ctx = {
      platformHotelSelectedId: currentId,
      get ctripChannelOrderUploadResult() { return context.uploadState.result.value; },
      get ctripChannelOrderUploadScope() { return context.uploadState.scope?.value || null; },
      get ctripChannelOrderUploadPreview() { return context.uploadState.result.value?.import_preview || null; },
    };
    return {
      ctx,
      systemHotelId: currentId,
      get uploadResultMatchesCurrentHotel() {
        const getter = component.computed.uploadResultMatchesCurrentHotel;
        return typeof getter === 'function' ? getter.call(this) : undefined;
      },
      get uploadPreview() { return component.computed.uploadPreview.call(this); },
      get uploadReceiptKey() { return component.computed.uploadReceiptKey.call(this); },
    };
  };
  return { context, pending, hotelId, hotelName, component, view };
}
const response = (hotelId = 80) => ({
  ok: true,
  json: async () => ({ code: 200, data: {
    task_id: 7001,
    status: 'verified',
    import_readback: { status: 'verified', readback_count: 2, value_level_verified: true },
    import_preview: {
      system_hotel_id: hotelId,
      date_from: '2026-09-01',
      date_to: '2026-09-02',
      source_file_count: 1,
      channels: [{ key: 'synthetic', label: 'Synthetic channel', orders: 2 }],
    },
  } }),
});

test('a delayed hotel A upload cannot become hotel B analysis or revive when returning to A', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  assert.equal(h.pending.length, 1, h.context.uploadState.error.value);
  assert.match(h.pending[0].options.body.rows.find(([key]) => key === 'system_hotel_id')[1], /^80$/);
  assert.equal(h.pending[0].options.body.rows.find(([key]) => key === 'hotel_name')[1], 'Synthetic hotel A');

  h.hotelId.value = 81;
  h.hotelName.value = 'Synthetic hotel B';
  assert.equal(h.context.uploadState.scopeMismatch.value, true, 'the pending request remains attributed to hotel A');
  h.pending[0].resolve(await response());
  await pendingUpload;

  const hotelB = h.view(81);
  assert.equal(hotelB.uploadPreview, null, 'the response for A must not render under the current B selection');
  assert.equal(hotelB.uploadReceiptKey, '', 'the A receipt must not refresh B panel state');
  assert.equal(hotelB.uploadResultMatchesCurrentHotel, false);
  assert.equal(h.context.uploadState.scope?.value?.systemHotelId, 80);
  assert.equal(h.context.uploadState.scopeMismatch.value, true);
  assert.match(uploadSection, /ctripChannelOrderUploadScopeMismatch/);
  assert.match(uploadSection, /ctripChannelOrderUploadScope\?\.hotelName/);
  assert.match(uploadSection, /ctripChannelOrderUploadScope\?\.submitted/);
  assert.match(uploadSection, /:disabled="ctripChannelOrderUploading \|\| ctripChannelOrderUploadPartialReceipt"/);
  assert.match(uploadSection, /ctripChannelOrderUploadFileScopeMismatch/);
  assert.match(uploadSection, /:disabled="ctripChannelOrderUploading \|\| ctripChannelOrderUploadPartialReceipt \|\| ctripChannelOrderUploadFileScopeMismatch/);
  assert.match(uploadSection, /仅切换酒店不会取消本次请求/);

  h.hotelId.value = 80;
  const hotelA = h.view(80);
  assert.equal(h.context.uploadState.scopeMismatch.value, false);
  assert.equal(hotelA.uploadResultMatchesCurrentHotel, true, 'Original submitted scope still belongs to A; accepted result remains absent');
  assert.equal(hotelA.uploadPreview, null);
  assert.equal(hotelA.uploadReceiptKey, '');
  assert.equal(h.context.uploadState.partialReceipt.value.status, 'outcome_unknown');
});

test('a same-hotel upload continues to expose its exact response preview', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  assert.equal(h.pending.length, 1, h.context.uploadState.error.value);
  h.pending[0].resolve(await response());
  await pendingUpload;
  const hotelA = h.view(80);
  assert.equal(h.context.uploadState.scopeMismatch.value, false);
  assert.equal(hotelA.uploadResultMatchesCurrentHotel, true);
  assert.equal(hotelA.uploadPreview?.source_file_count, 1);
  assert.equal(hotelA.uploadReceiptKey.includes('2'), true);
});

test('an interrupted request stays attributed to its target hotel and does not leave an analysis preview', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  assert.equal(h.pending.length, 1);
  h.hotelId.value = 81;
  h.pending[0].reject(new Error('synthetic network failure'));
  await pendingUpload;

  const hotelB = h.view(81);
  assert.equal(hotelB.uploadPreview, null);
  assert.equal(hotelB.uploadReceiptKey, '');
  assert.equal(h.context.uploadState.result.value, null);
  assert.equal(h.context.uploadState.scope?.value?.systemHotelId, 80);
  assert.equal(h.context.uploadState.scope?.value?.submitted, true);
  assert.equal(h.context.uploadState.scopeMismatch.value, true);
  assert.match(h.context.uploadState.error.value, /原酒店|切换/);
  assert.equal(h.context.uploadState.partialReceipt.value.status, 'outcome_unknown');

  h.hotelId.value = 80;
  assert.equal(h.context.uploadState.scopeMismatch.value, false);
});

test('local upload validation is scoped before send and selecting a valid file recovers cleanly', async () => {
  const h = harness();
  h.context.uploadState.file.value = vm.runInContext("[{ name: 'orders.txt', size: 7 }]", h.context);
  await h.context.upload();
  assert.equal(h.pending.length, 0, 'an invalid extension must not send a request');
  assert.match(h.context.uploadState.error.value, /仅支持/);
  assert.equal(h.context.uploadState.scope?.value?.systemHotelId, 80);
  assert.equal(h.context.uploadState.scope?.value?.submitted, false);

  h.hotelId.value = 81;
  assert.equal(h.context.uploadState.scopeMismatch.value, true);
  assert.equal(h.view(81).uploadPreview, null);
  h.hotelId.value = 80;
  h.context.changeFile({ target: { files: vm.runInContext("[{ name: 'orders.csv', size: 7 }]", h.context) } });
  assert.equal(h.context.uploadState.error.value, '');
  assert.equal(h.context.uploadState.scope.value, null);

  const retry = h.context.upload();
  assert.equal(h.pending.length, 1);
  h.pending[0].resolve(await response());
  await retry;
  assert.equal(h.view(80).uploadPreview?.date_to, '2026-09-02');
});

test('a file selected for hotel A cannot be uploaded to hotel B after switching', async () => {
  const h = harness();
  const originalUpload = h.context.upload();
  assert.equal(h.pending.length, 1);
  h.pending[0].resolve(await response());
  await originalUpload;
  assert.equal(h.view(80).uploadPreview?.date_from, '2026-09-01');

  h.hotelId.value = 81;
  h.hotelName.value = 'Synthetic hotel B';
  const rejectedSelection = h.context.upload();
  assert.equal(h.pending.length, 1, 'the A file must not be posted under hotel B');
  await rejectedSelection;
  assert.match(h.context.uploadState.error.value, /请选择/);
  assert.equal(h.view(81).uploadPreview, null);
  assert.equal(h.view(81).uploadReceiptKey, '');
  assert.equal(h.context.uploadState.fileScope.value, null);
  assert.equal(h.context.uploadState.fileScopeMismatch.value, false);
  assert.equal(h.context.uploadState.scope?.value?.systemHotelId, 80, 'rejecting the stale file selection must retain A receipt ownership');

  h.context.changeFile({ target: { files: vm.runInContext("[{ name: 'hotel-b-orders.csv', size: 7 }]", h.context) } });
  assert.equal(h.context.uploadState.fileScope?.value?.systemHotelId, 81);
  assert.equal(h.context.uploadState.fileScopeMismatch.value, false);
  const retry = h.context.upload();
  assert.equal(h.pending.length, 2);
  assert.equal(h.pending[1].options.body.rows.find(([key]) => key === 'system_hotel_id')[1], '81');
  h.pending[1].resolve(await response(81));
  await retry;
  assert.equal(h.view(81).uploadResultMatchesCurrentHotel, true);
});

test('a partial server import keeps its task receipt and blocks blind retry until history is checked', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  assert.equal(h.pending.length, 1);
  h.pending[0].resolve({
    ok: false,
    status: 422,
    json: async () => ({
      code: 422,
      message: '导入仅部分完成，未确认完整入库；请查看任务状态、数据缺口和回读结果。',
      data: {
        task_id: 7002,
        status: 'partial_success',
        saved_count: 2,
        readback_count: 1,
        import_readback: {
          status: 'unverified',
          saved_count: 2,
          readback_count: 1,
          value_level_verified: false,
          failure_reason: 'synthetic internal exception detail must not be rendered',
        },
      },
    }),
  });
  await pendingUpload;

  const receipt = h.context.uploadState.partialReceipt.value;
  assert.equal(receipt?.taskId, 7002);
  assert.equal(receipt?.savedCount, 2);
  assert.equal(receipt?.readbackCount, 1);
  assert.equal(receipt?.status, 'partial_success');
  assert.equal(receipt?.systemHotelId, 80);
  assert.equal(receipt?.hotelName, 'Synthetic hotel A');
  assert.equal(Object.hasOwn(receipt || {}, 'failureReason'), false);
  assert.equal(h.context.uploadState.result.value, null, 'a partial payload must never become a success preview');
  assert.equal(h.context.uploadState.scopeMismatch.value, false, 'the partial receipt stays attached to the current original hotel');
  assert.equal(h.view(81).uploadPreview, null);
  assert.match(h.context.uploadState.error.value, /仅部分完成/);
  assert.match(appSource, /ctripChannelOrderUploadFileInput, ctripChannelOrderUploadPartialReceipt,[\s\S]*acknowledgeCtripChannelOrderPartialReview/);
  assert.match(uploadSection, /ctripChannelOrderUploadPartialReceipt/);
  assert.match(uploadSection, /精确回读/);
  assert.match(uploadSection, /请先在目标酒店核对该任务与历史记录/);
  assert.match(uploadSection, /我已核对历史，重新选择文件/);
  assert.match(uploadSection, /ctripChannelOrderUploading \|\| ctripChannelOrderUploadPartialReceipt/);

  h.context.uploadState.fileInput.value = { value: 'orders.csv' };
  await h.context.upload();
  assert.equal(h.pending.length, 1, 'the original file cannot be resubmitted before a history review acknowledgement');
  assert.notEqual(h.context.uploadState.partialReceipt.value, null);

  h.context.acknowledgePartialReview();
  assert.equal(h.context.uploadState.partialReceipt.value, null);
  assert.equal(h.context.uploadState.file.value, null);
  assert.equal(h.context.uploadState.fileScope.value, null);
  assert.equal(h.context.uploadState.fileInput.value.value, '');
  assert.equal(h.context.uploadState.scope.value, null);
  assert.equal(h.context.uploadState.error.value, '');

  h.hotelId.value = 81;
  h.hotelName.value = 'Synthetic hotel B';
  h.context.changeFile({ target: { files: vm.runInContext("[{ name: 'orders.csv', size: 7 }]", h.context) } });
  const retryAfterReview = h.context.upload();
  assert.equal(h.pending.length, 2, 'an acknowledged history review can recover by explicitly selecting a file again');
  assert.equal(h.pending[1].options.body.rows.find(([key]) => key === 'system_hotel_id')[1], '81');
  h.pending[1].resolve(await response(81));
  await retryAfterReview;
  assert.equal(h.view(81).uploadPreview?.date_from, '2026-09-01');
});

test('partial import counts and task identity remain missing instead of being filled with zero', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  h.pending[0].resolve({
    ok: false,
    status: 422,
    json: async () => ({
      code: 422,
      message: '导入仅部分完成，未确认完整入库；请查看任务状态、数据缺口和回读结果。',
      data: { status: 'partial_success', import_readback: { status: 'unverified' } },
    }),
  });
  await pendingUpload;
  const receipt = h.context.uploadState.partialReceipt.value;
  assert.equal(receipt?.taskId, null);
  assert.equal(receipt?.savedCount, null);
  assert.equal(receipt?.readbackCount, null);
});

test('a lost upload response cannot be blindly retried before the target hotel history is checked', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  h.pending[0].reject(new Error('synthetic socket reset after dispatch'));
  await pendingUpload;

  const receipt = h.context.uploadState.partialReceipt.value;
  assert.equal(receipt?.status, 'outcome_unknown');
  assert.equal(receipt?.systemHotelId, 80);
  assert.equal(receipt?.hotelName, 'Synthetic hotel A');
  assert.equal(receipt?.taskId, null);
  assert.equal(receipt?.savedCount, null);
  assert.equal(receipt?.readbackCount, null);
  assert.equal(h.context.uploadState.result.value, null);
  assert.match(h.context.uploadState.error.value, /服务器是否保存无法确认/);
  assert.match(uploadSection, /上传结果未知，服务器是否保存无法确认/);
  assert.match(uploadSection, /ctripChannelOrderUploadPartialReceipt\.status === 'partial_success'/);
  assert.match(uploadSection, /回执计数：保存/);
  await h.context.upload();
  assert.equal(h.pending.length, 1, 'transport uncertainty must block a blind repeat request');
});

test('an HTTP 5xx without a durable result is treated as unknown rather than safe to replay', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  h.pending[0].resolve({
    ok: false,
    status: 500,
    json: async () => ({ code: 500, message: '导入数据失败，请查看任务状态和失败原因。' }),
  });
  await pendingUpload;
  assert.equal(h.context.uploadState.partialReceipt.value?.status, 'outcome_unknown');
  assert.equal(h.context.uploadState.partialReceipt.value?.systemHotelId, 80);
  assert.equal(h.context.uploadState.result.value, null);
});

test('an HTTP success without an import result is unknown and cannot masquerade as an empty success', async () => {
  const h = harness();
  const pendingUpload = h.context.upload();
  h.pending[0].resolve({ ok: true, status: 200, json: async () => ({ code: 200 }) });
  await pendingUpload;
  assert.equal(h.context.uploadState.partialReceipt.value?.status, 'outcome_unknown');
  assert.equal(h.context.uploadState.result.value, null);
  assert.match(h.context.uploadState.error.value, /服务器是否保存无法确认/);
  await h.context.upload();
  assert.equal(h.pending.length, 1, 'a success-shaped envelope without result evidence must not be replayed blindly');
});

test('an explicit client rejection without a partial receipt remains recoverable', async () => {
  const h = harness();
  const rejectedUpload = h.context.upload();
  h.pending[0].resolve({
    ok: false,
    status: 422,
    json: async () => ({ code: 422, message: '文件格式错误。', data: null }),
  });
  await rejectedUpload;
  assert.equal(h.context.uploadState.partialReceipt.value, null);
  assert.match(h.context.uploadState.error.value, /文件格式错误/);

  h.context.changeFile({ target: { files: vm.runInContext("[{ name: 'corrected-orders.xls', size: 7 }]", h.context) } });
  const retry = h.context.upload();
  assert.equal(h.pending.length, 2, 'a confirmed client rejection remains actionable after correction');
  assert.equal(h.pending[1].options.body.rows.find(([key]) => key === 'file')[1].name, 'corrected-orders.xls');
  h.pending[1].reject(new Error('synthetic cleanup'));
  await retry;
});
