import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = file => readFileSync(file, 'utf8');

const template = read('resources/frontend/templates/fragments/16-page-ai-daily-report.html');
const appMain = read('public/app-main.js');
const systemStatic = read('public/system-static.js');
const deliveryClient = read('public/components/system/ai-daily-report-delivery.js');
const templateSource = read('scripts/lib/frontend_template_source.mjs');
const templateBuild = read('scripts/lib/frontend_template_build.mjs');
const closureLoader = read('public/components/system/business-closure-loader.js');
const routes = readRouteContractSource(process.cwd());
const controller = read('app/controller/AiDailyReportBroadcast.php');
const service = read('app/service/AiDailyReportBroadcastSnapshotService.php');
const factService = read('app/service/AiDailyReportBroadcastFactService.php');
const migration = read('database/migrations/20260825_create_ai_daily_report_broadcast_snapshots.sql');

const savedSnapshot = Object.freeze({
  snapshot_id: 321,
  version_no: 1,
  tenant_id: 80,
  hotel_id: 80,
  hotel_name: '敦煌漠蓝新',
  business_date: '2026-08-23',
  generated_at: '2026-08-26 00:15:00',
  data_cutoff_at: '2026-08-24 23:17:33',
  facts_broadcast_status: 'facts_broadcast_ready',
  analysis_status: 'analysis_blocked',
  status_label: '严格事实可播报',
  status_message: '',
  template_version: 'trusted_operations_broadcast.v1',
  view_status: 'ready',
  generation_trigger: 'manual',
  facts_fingerprint: 'a'.repeat(64),
  snapshot_fingerprint: 'b'.repeat(64),
  final_text_sha256: 'c'.repeat(64),
  facts: [
    { metric_key: 'exposure', value: 1422, source_refs: ['online_daily_data#102476'] },
    { metric_key: 'visits', value: 206, source_refs: ['online_daily_data#102476'] },
    { metric_key: 'conversion', value: 14.49, source_refs: ['online_daily_data#102476'] },
  ],
  fact_refs: ['online_daily_data#102476'],
  missing_items: [
    { code: 'ctrip_exposure_missing', message: '携程曝光事实缺失' },
    { code: 'meituan_revenue_caliber_uncertain', message: '收入口径未确认' },
  ],
  source_status: {
    meituan: { selected_fact_count: 3 },
    ctrip: { selected_fact_count: 0 },
  },
  final_text: [
    '可信经营播报',
    '门店：敦煌漠蓝新（Hotel 80）',
    '业务日期：2026-08-23',
    '已确认事实：美团曝光 1,422、商详访客 206、曝光到访率 14.49%。',
    '异常/缺失：携程曝光事实缺失、收入口径未确认，因此暂不生成双平台竞争和收益结论。',
    '今天最需要关注的一件事：优先补齐携程曝光事实并核对收入口径。',
    '数据截止时间：2026-08-24 23:17:33。',
    '来源状态：美团严格回读事实可用；携程关键事实不完整；竞争分析仍受阻。',
  ].join('\n'),
  can_generate: true,
  can_use: true,
  persisted: true,
  readback_verified: true,
});

test('trusted broadcast is an always-visible page entry independent from a saved analysis report', () => {
  const broadcastIndex = template.indexOf('data-testid="ai-daily-operations-broadcast"');
  const reportOnlyIndex = template.indexOf('<template v-if="aiDailyReport">');
  assert.ok(broadcastIndex > 0);
  assert.ok(reportOnlyIndex > broadcastIndex);
  for (const marker of [
    'data-testid="ai-daily-operations-broadcast-generate"',
    '生成可信播报',
    'data-testid="ai-daily-operations-broadcast-snapshot-id"',
    'data-testid="ai-daily-operations-broadcast-text"',
    '页面显示、复制和语音朗读始终读取同一已保存快照文本',
    '没有严格事实时仅显示等待数据或采集失败，不生成经营建议',
  ]) {
    assert.ok(template.includes(marker), marker);
  }
  assert.doesNotMatch(template, /aiDailyOperationsBroadcast\(\)/);
});

test('trusted broadcast route and storage contracts are separate from analysis and external delivery', () => {
  for (const marker of [
    "Route::get('/broadcast-snapshots/latest', 'AiDailyReportBroadcast/latest')",
    "Route::get('/broadcast-snapshots/:snapshotId', 'AiDailyReportBroadcast/read')",
    "Route::post('/broadcast-snapshots', 'AiDailyReportBroadcast/generate')",
  ]) {
    assert.ok(routes.includes(marker), marker);
  }
  assert.ok(controller.includes("'manual'"));
  assert.ok(controller.includes('ApiExceptionMapper::response'));
  assert.doesNotMatch(controller, /safeErrorMessage|getMessage\(\)/);
  assert.doesNotMatch(controller, /send-wecom|deliverSavedDailyReport|CloudAutomationService/);
  assert.ok(service.includes("'facts_broadcast_ready'"));
  assert.ok(service.includes("'analysis_blocked'"));
  assert.ok(service.includes("'wecom_send_authorized' => false"));
  assert.doesNotMatch(service, /LlmClient|sendWecom|deliverSavedDailyReport/);
  for (const requiredColumn of [
    '`hotel_id`',
    '`business_date`',
    '`version_no`',
    '`generated_at`',
    '`data_cutoff_at`',
    '`fact_refs_json`',
    '`missing_items_json`',
    '`template_version`',
    '`final_text`',
  ]) {
    assert.ok(migration.includes(requiredColumn), requiredColumn);
  }
});

test('trusted broadcast production facts are projected from the canonical OTA closure', () => {
  assert.match(factService, /new DualOtaFieldClosureService\(\)/);
  assert.match(factService, /projectCanonicalClosure/);
  assert.match(factService, /trusted_ota_daily_fact_consumer\.v1/);
  assert.match(factService, /source_closure_identity/);
  assert.match(factService, /metric_values_recalculated' => false/);
});

test('display, clipboard and speech consume the exact same saved snapshot text', async () => {
  let copiedText = '';
  let spokenUtterance = null;
  let replacedUrl = '';
  const calls = [];
  const notices = [];
  class SpeechSynthesisUtterance {
    constructor(text) {
      this.text = text;
    }
  }
  const request = async (url, options = {}) => {
    calls.push({ url, options });
    if (url.includes('/latest?')) {
      return {
        code: 200,
        data: {
          ...savedSnapshot,
          snapshot_id: null,
          version_no: null,
          final_text: '',
          final_text_sha256: '',
          snapshot_fingerprint: '',
          persisted: false,
          readback_verified: false,
          can_use: false,
          status_message: '已找到严格回读事实，点击“生成可信播报”后正式保存。',
        },
      };
    }
    if (url === '/ai-daily-reports/broadcast-snapshots' && options.method === 'POST') {
      return { code: 200, data: { ...savedSnapshot } };
    }
    if (url === '/ai-daily-reports/broadcast-snapshots/321') {
      return { code: 200, data: { ...savedSnapshot } };
    }
    throw new Error(`unexpected request ${url}`);
  };
  const sandbox = {
    window: {
      location: { href: 'http://127.0.0.1:8080/' },
      history: {
        state: null,
        replaceState: (_state, _title, url) => { replacedUrl = String(url); },
      },
      SpeechSynthesisUtterance,
      speechSynthesis: {
        speak: utterance => { spokenUtterance = utterance; },
        cancel: () => {},
      },
    },
    navigator: {
      clipboard: {
        writeText: async text => { copiedText = text; },
      },
    },
    Vue: {
      ref: value => ({ __v_isRef: true, value }),
      watch: () => {},
      onBeforeUnmount: () => {},
    },
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setupBroadcast({
    ctx: {
      aiDailyReport: null,
      aiDailyReportForm: { hotel_id: 80, report_date: '2026-08-23' },
      aiDailyReportDeliveryRequest: request,
      showToast: (message, type) => notices.push({ message, type }),
    },
  });

  await state.loadAiDailyTrustedBroadcast();
  assert.equal(state.aiDailyTrustedBroadcast.persisted, false);
  assert.equal(state.aiDailyTrustedBroadcast.can_generate, true);
  assert.equal(state.aiDailyTrustedBroadcast.final_text, '');

  assert.equal(await state.generateAiDailyTrustedBroadcast(), true);
  assert.equal(state.aiDailyTrustedBroadcast.snapshot_id, 321);
  assert.equal(state.aiDailyTrustedBroadcast.readback_verified, true);
  assert.equal(state.aiDailyTrustedBroadcast.final_text, savedSnapshot.final_text);
  assert.equal(await state.copyAiDailyTrustedBroadcast(), true);
  assert.equal(state.toggleAiDailyTrustedBroadcast(), true);
  assert.equal(copiedText, savedSnapshot.final_text);
  assert.equal(spokenUtterance.text, savedSnapshot.final_text);
  assert.equal(spokenUtterance.lang, 'zh-CN');
  assert.equal(spokenUtterance.rate, 0.95);
  assert.match(replacedUrl, /page=ai-daily-report/);
  assert.match(replacedUrl, /broadcast_hotel_id=80/);
  assert.match(replacedUrl, /broadcast_date=2026-08-23/);
  assert.match(replacedUrl, /broadcast_snapshot_id=321/);
  assert.match(replacedUrl, new RegExp(`broadcast_snapshot_fingerprint=${'b'.repeat(64)}`));
  assert.match(replacedUrl, new RegExp(`broadcast_text_sha256=${'c'.repeat(64)}`));
  assert.ok(notices.some(item => item.message.includes('快照 #321 已保存并精确回读')));
  assert.equal(calls.filter(call => call.options.method === 'POST').length, 1);
  assert.ok(calls.every(call => !call.url.includes('send-wecom')));
});

test('hard refresh restores the exact immutable broadcast snapshot instead of latest', async () => {
  const calls = [];
  const form = { hotel_id: '', report_date: '' };
  const query = new URLSearchParams({
    page: 'ai-daily-report',
    broadcast_hotel_id: '80',
    broadcast_date: '2026-08-23',
    broadcast_snapshot_id: '321',
    broadcast_snapshot_fingerprint: savedSnapshot.snapshot_fingerprint,
    broadcast_text_sha256: savedSnapshot.final_text_sha256,
  });
  const sandbox = {
    window: {
      location: { href: `http://127.0.0.1:8080/?${query}` },
      history: { state: null, replaceState: () => {} },
    },
    navigator: {},
    Vue: {
      ref: value => ({ __v_isRef: true, value }),
      watch: () => {},
      onBeforeUnmount: () => {},
    },
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setupBroadcast({
    ctx: {
      aiDailyReport: null,
      aiDailyReportForm: form,
      aiDailyReportDeliveryRequest: async url => {
        calls.push(url);
        if (url === '/ai-daily-reports/broadcast-snapshots/321') {
          return { code: 200, data: { ...savedSnapshot } };
        }
        if (url.includes('/latest?')) {
          return {
            code: 200,
            data: {
              ...savedSnapshot,
              snapshot_id: 322,
              version_no: 2,
              snapshot_fingerprint: 'd'.repeat(64),
              final_text_sha256: 'e'.repeat(64),
              final_text: 'newer text that must not replace the deep-linked snapshot',
            },
          };
        }
        throw new Error(`unexpected request ${url}`);
      },
    },
  });

  assert.deepEqual(form, { hotel_id: '80', report_date: '2026-08-23' });
  await state.loadAiDailyTrustedBroadcast();
  assert.deepEqual(calls, ['/ai-daily-reports/broadcast-snapshots/321']);
  assert.equal(state.aiDailyTrustedBroadcast.snapshot_id, 321);
  assert.equal(state.aiDailyTrustedBroadcast.final_text, savedSnapshot.final_text);
});

test('broadcast lazy component uses its dedicated server-snapshot setup', () => {
  assert.ok(templateSource.includes("componentKey: 'AiDailyTrustedBroadcastBody'"));
  assert.ok(templateSource.includes('<ai-daily-trusted-broadcast-view :ctx="$root"></ai-daily-trusted-broadcast-view>'));
  assert.ok(templateBuild.includes("componentKey === 'AiDailyTrustedBroadcastBody'"));
  assert.ok(templateBuild.includes('SUXI_AI_DAILY_REPORT_DELIVERY?.setupBroadcast'));
  assert.ok(closureLoader.includes("['AiDailyTrustedBroadcastView', 'AiDailyTrustedBroadcastBody']"));
  assert.ok(deliveryClient.includes('/ai-daily-reports/broadcast-snapshots/latest'));
  assert.ok(deliveryClient.includes('/ai-daily-reports/broadcast-snapshots/${saved.snapshot_id}'));
  assert.ok(deliveryClient.includes("url.searchParams.set('page', 'ai-daily-report')"));
  assert.ok(deliveryClient.includes("url.searchParams.get('broadcast_date')"));
  assert.ok(deliveryClient.includes("url.searchParams.get('broadcast_snapshot_id')"));
  assert.ok(deliveryClient.includes("url.searchParams.get('broadcast_snapshot_fingerprint')"));
  assert.ok(deliveryClient.includes("url.searchParams.get('broadcast_text_sha256')"));
  assert.ok(deliveryClient.includes('/ai-daily-reports/broadcast-snapshots/${requestedSnapshot.snapshotId}'));
  assert.ok(systemStatic.includes("new URLSearchParams(String(search || '')).get('page')"));
  assert.ok(appMain.includes("requireAppSystemStatic('resolveInitialPageRequest')(window.SUXI_INITIAL_PAGE_OVERRIDE, window.location.search)"));
});
