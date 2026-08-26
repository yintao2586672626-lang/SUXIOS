import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = file => readFileSync(file, 'utf8');

const template = read('resources/frontend/templates/fragments/16-page-ai-daily-report.html');
const appMain = read('public/app-main.js');
const routes = read('route/app.php');
const controller = read('app/controller/AiDailyReport.php');
const specService = read('app/service/AiDailyReportPresentationSpecService.php');
const renderer = read('app/service/AiDailyReportPresentationRendererService.php');
const artifactService = read('app/service/AiDailyReportPresentationArtifactService.php');
const artifactMigration = read('database/migrations/20260823_zzzz_create_ai_report_presentation_artifacts.sql');
const artifactStatusMigration = read('database/migrations/20260824_a_refine_ai_report_presentation_artifact_readback_status.sql');
const templateSource = read('scripts/lib/frontend_template_source.mjs');
const templateBuild = read('scripts/lib/frontend_template_build.mjs');
const closureLoader = read('public/components/system/business-closure-loader.js');
const deliveryClient = read('public/components/system/ai-daily-report-delivery.js');

test('AI daily report exposes separate JSON and verified presentation bundle actions', () => {
  for (const marker of [
    'data-testid="ai-daily-presentation-generate"',
    'data-testid="ai-daily-presentation-result"',
    '@click="downloadAiDailyReportJsonPackage"',
    '@click="downloadAiDailyReportPackage"',
    '可编辑 PPTX、离线 HTML、规格与验真清单',
    '培训草案（需复核）',
    '自由文本仍需人工复核后才能用于培训',
    '不发布、不发消息、不写 OTA/PMS',
    'data-testid="ai-daily-operations-broadcast"',
    'data-testid="ai-daily-operations-broadcast-copy"',
    'data-testid="ai-daily-operations-broadcast-speak"',
    '只采用有数值、已验证并带保存回读来源的指标',
    '不会自动发送企业微信，也不会写入 OTA/PMS',
  ]) {
    assert.ok(template.includes(marker), marker);
  }
});

test('operations broadcast includes only verified same-scope metrics and declares gaps', () => {
  const sandbox = {
    window: {},
    Vue: {},
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const broadcast = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.buildAiDailyOperationsBroadcast({
    aiDailyReport: {
      id: 88,
      hotel_id: 80,
      report_date: '2026-08-23',
    },
    aiDailyReportWecomHotelName: 'Hotel 80',
    aiDailyReportMetricCards: [
      {
        key: 'exposure',
        label: 'OTA曝光',
        value: 1422,
        calculationStatus: 'available',
        scopeCode: 'ota_channel',
        truth: { status: 'verified', source_refs: ['online_daily_data#102476'] },
      },
      {
        key: 'visitors',
        label: 'OTA访客',
        value: 206,
        calculationStatus: 'available',
        scopeCode: 'ota_channel',
        truth: { status: 'verified', source_refs: ['online_daily_data#102476'] },
      },
      {
        key: 'flow_rate',
        label: '曝光→详情',
        value: 14.49,
        unit: '%',
        calculationStatus: 'calculated',
        scopeCode: 'ota_channel',
        truth: { status: 'verified', source_refs: ['online_daily_data#102476'] },
      },
      {
        key: 'revenue',
        label: '营收',
        value: 9999,
        calculationStatus: 'available',
        scopeCode: 'ota_channel',
        truth: { status: 'partial', source_refs: ['online_daily_data#102477'] },
      },
      {
        key: 'manual_estimate',
        label: '人工估算',
        value: 77,
        calculationStatus: 'available',
        scopeCode: 'user_input',
        truth: { status: 'verified', source_refs: ['manual_input#1'] },
      },
    ],
    aiDailyReportDataGaps: [
      { code: 'revenue_semantics_conflict', message: '营收口径仍需核对' },
    ],
  });

  assert.equal(broadcast.status, 'ready');
  assert.equal(broadcast.verifiedMetricCount, 3);
  assert.equal(broadcast.excludedMetricCount, 2);
  assert.equal(broadcast.sourceRefCount, 1);
  assert.match(broadcast.text, /Hotel 80，2026年8月23日/);
  assert.match(broadcast.text, /OTA曝光1,422/);
  assert.match(broadcast.text, /OTA访客206/);
  assert.match(broadcast.text, /曝光→详情14\.49%/);
  assert.match(broadcast.text, /OTA渠道口径，不代表全酒店经营/);
  assert.match(broadcast.text, /营收口径仍需核对/);
  assert.doesNotMatch(broadcast.text, /营收.*9,999/);
  assert.doesNotMatch(broadcast.text, /人工估算77/);
});

test('operations broadcast can be copied and toggled through local browser speech', async () => {
  let copiedText = '';
  let spokenUtterance = null;
  let cancelCount = 0;
  const notices = [];
  class SpeechSynthesisUtterance {
    constructor(text) {
      this.text = text;
    }
  }
  const sandbox = {
    window: {
      SpeechSynthesisUtterance,
      speechSynthesis: {
        speak: utterance => { spokenUtterance = utterance; },
        cancel: () => { cancelCount += 1; },
      },
    },
    navigator: {
      clipboard: {
        writeText: async text => { copiedText = text; },
      },
    },
    Vue: {
      ref: value => ({ __v_isRef: true, value }),
      watch: (_sources, callback, options) => {
        if (options?.immediate) callback();
      },
      onBeforeUnmount: () => {},
    },
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({
    ctx: {
      aiDailyReport: { id: 89, hotel_id: 80, report_date: '2026-08-23' },
      aiDailyReportWecomHotelName: 'Hotel 80',
      aiDailyReportMetricCards: [{
        key: 'exposure',
        label: 'OTA曝光',
        value: 1422,
        calculationStatus: 'available',
        scopeCode: 'ota_channel',
        truth: { status: 'verified', source_refs: ['online_daily_data#102476'] },
      }],
      aiDailyReportDataGaps: [],
      aiDailyReportDeliveryRequest: async () => ({ code: 404 }),
      showToast: (message, type) => notices.push({ message, type }),
    },
  });

  assert.equal(await state.copyAiDailyOperationsBroadcast(), true);
  assert.match(copiedText, /OTA曝光1,422/);
  assert.equal(state.toggleAiDailyOperationsBroadcast(), true);
  assert.equal(state.aiDailyReportBroadcastSpeaking, true);
  assert.match(spokenUtterance.text, /OTA渠道口径，不代表全酒店经营/);
  assert.equal(spokenUtterance.lang, 'zh-CN');
  assert.equal(spokenUtterance.rate, 0.95);
  assert.equal(state.toggleAiDailyOperationsBroadcast(), false);
  assert.equal(state.aiDailyReportBroadcastSpeaking, false);
  assert.equal(cancelCount, 1);
  assert.ok(notices.some(item => item.message === '可信经营播报稿已复制' && item.type === 'success'));
  assert.ok(notices.some(item => item.message === '正在朗读可信经营播报' && item.type === 'success'));
});

test('browser refuses download until server readback, byte length and SHA-256 all match', () => {
  for (const marker of [
    '/presentation-artifacts',
    '/presentation-spec',
    'artifact.artifact_readback_verified !== true',
    "artifact.render_status !== 'rendered_and_readback_verified'",
    'bytes.byteLength !== expectedBytes',
    "globalThis.crypto.subtle.digest('SHA-256', bytes)",
    'actualSha !== expectedSha',
    "new Blob([bytes], { type: 'application/zip' })",
    'aiDailyReportPresentationGenerating',
    'loadAiDailyReportPresentationArtifact',
    'aiDailyReportPresentationLoading',
    'aiDailyReportPresentationResult',
    'presentationGenerationSequence',
    'identityMatches',
    'Number(artifact.report_id || 0) !== identity.reportId',
    'Number(artifact.hotel_id || 0) !== identity.hotelId',
    'String(artifact.audience || \'\') !== identity.audience',
    'Number(artifact.presentation_spec_id || 0) !== presentationSpecId',
    'String(artifact.spec_fingerprint || \'\').trim().toLowerCase() !== expectedSpecFingerprint',
  ]) {
    assert.ok(deliveryClient.includes(marker), marker);
  }
});

test('artifact API is hotel scoped, export gated and exact-spec bound', () => {
  for (const marker of [
    "Route::post('/:id/presentation-artifacts', 'AiDailyReport/savePresentationArtifact')",
    "Route::get('/:id/presentation-artifacts', 'AiDailyReport/presentationArtifact')",
    "Route::get('/:id/presentation-artifacts/:artifactId', 'AiDailyReport/presentationArtifactById')",
  ]) {
    assert.ok(routes.includes(marker), marker);
  }
  for (const marker of [
    'resolveHotelScope()',
    "'report.export'",
    'presentationSpecService->saveAndReadback',
    'presentationArtifactService->saveAndReadback',
    'presentationArtifactService->readLatest',
    'presentationSpecService->resolveTenantScope($report)',
    'presentationSpecService->readLatest',
    "input['presentation_spec_id']",
    "input['expected_spec_fingerprint']",
    'presentation spec stale',
  ]) {
    assert.ok(controller.includes(marker), marker);
  }
  for (const methodName of [
    'savePresentationSpec',
    'presentationSpec',
    'savePresentationArtifact',
    'presentationArtifact',
    'presentationArtifactById',
  ]) {
    const start = controller.indexOf(`public function ${methodName}`);
    const end = controller.indexOf('\n    public function ', start + 1);
    const method = controller.slice(start, end > start ? end : undefined);
    assert.ok(start >= 0, methodName);
    assert.match(method, /'report\.export'/, `${methodName} must require report.export`);
  }
  for (const marker of [
    'presentation_spec_id',
    'spec_fingerprint',
    'content_sha256',
    'artifact_blob',
    'rendered_and_readback_verified',
    'rendered_pending_readback',
    'isDuplicateKeyConflict',
    'Db::transaction',
    "Db::name('ai_report_presentation_specs')",
    "->where('tenant_id'",
    "->where('presentation_spec_id', $presentationSpecId)",
    "->where('spec_fingerprint', $expectedSpecFingerprint)",
    'presentation tenant scope is required',
  ]) {
    assert.ok(artifactService.includes(marker), marker);
  }
  for (const marker of [
    '`presentation_spec_id` BIGINT UNSIGNED NOT NULL',
    '`content_sha256` CHAR(64) NOT NULL',
    '`artifact_blob` MEDIUMBLOB NOT NULL',
    'uk_ai_report_presentation_artifact_renderer',
  ]) {
    assert.ok(artifactMigration.includes(marker), marker);
  }
  assert.ok(artifactStatusMigration.includes("DEFAULT 'rendered_pending_readback'"));
});

test('one validated spec drives offline HTML and macro-free editable PPTX without external writes', () => {
  for (const marker of [
    "'single_spec_consumed' => true",
    "'recalculation_during_render' => false",
    "'html_external_requests_allowed' => false",
    "'pptx_macro_enabled' => false",
    "'pptx_editable_text_and_shapes' => true",
    "'human_review_status' => 'pending'",
    "'external_write_authorized' => false",
    "'presentation-spec.json'",
    "'manifest.json'",
    "'[Sources]'",
  ]) {
    assert.ok(renderer.includes(marker), marker);
  }
  for (const marker of [
    'sourceRefIsVerified',
    '$dataSourceId <= 0',
    '$refHotelId !== $hotelId',
    '$refDate !== $reportDate',
    'sanitizeTrainingSpec',
    "'identity_fields_removed_content_review_required'",
    "'status' => $reviewable ? 'hypothesis_review_required'",
    "'raw_text_republished' => false",
    "'class' => 'UNKNOWN'",
  ]) {
    assert.ok(specService.includes(marker), marker);
  }
});

test('presentation delivery UI is extracted from the root render and loaded on demand', () => {
  assert.ok(template.includes('data-testid="ai-daily-presentation-delivery"'));
  assert.ok(templateSource.includes("componentKey: 'AiDailyPresentationDeliveryBody'"));
  assert.ok(templateSource.includes('<ai-daily-presentation-delivery-view :ctx="$root"></ai-daily-presentation-delivery-view>'));
  assert.ok(templateBuild.includes("'AiDailyPresentationDeliveryBody'"));
  assert.ok(templateBuild.includes('SUXI_AI_DAILY_REPORT_DELIVERY?.setup'));
  assert.ok(closureLoader.includes("['AiDailyPresentationDeliveryView', 'AiDailyPresentationDeliveryBody']"));
  assert.ok(closureLoader.includes('ai-daily-report-delivery.js'));
  assert.ok(closureLoader.includes('loadAiDailyReportDelivery'));
  assert.ok(deliveryClient.includes('window.SUXI_AI_DAILY_REPORT_DELIVERY'));
  assert.ok(appMain.includes('aiDailyReportDeliveryRequest: apiRequest'));
});

test('lazy delivery setup exposes unwrapped local state to the compiled Vue render', () => {
  const sandbox = {
    window: {},
    Vue: {
      ref: value => ({ __v_isRef: true, value }),
      watch: (sources, callback, options) => {
        if (options?.immediate) callback();
      },
      onBeforeUnmount: () => {},
    },
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const delivery = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY;
  const state = delivery.setup({
    ctx: {
      aiDailyReport: null,
      aiDailyReportDeliveryRequest: async () => ({ code: 404 }),
    },
  });
  assert.equal(state.aiDailyReportAudience, 'owner');
  assert.equal(state.aiDailyReportPresentationGenerating, false);
  state.aiDailyReportAudience = 'expert';
  assert.equal(state.aiDailyReportAudience, 'expert');
});

test('a delayed artifact read cannot overwrite a switched report hotel or audience', async () => {
  let watchCallback = null;
  const pending = [];
  const deferredRequest = url => new Promise(resolve => pending.push({ url, resolve }));
  const sandbox = {
    window: {},
    Vue: {
      ref: value => ({ __v_isRef: true, value }),
      watch: (_sources, callback, options) => {
        watchCallback = callback;
        if (options?.immediate) callback();
      },
      onBeforeUnmount: () => {},
    },
    console,
    URL,
    Blob,
  };
  vm.runInNewContext(deliveryClient, sandbox);
  const ctx = {
    aiDailyReport: { id: 101, hotel_id: 7 },
    aiDailyReportDeliveryRequest: deferredRequest,
  };
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({ ctx });
  assert.equal(pending.length, 1);
  assert.match(pending[0].url, /\/101\/presentation-artifacts\?audience=owner$/);

  ctx.aiDailyReport = { id: 202, hotel_id: 8 };
  state.aiDailyReportAudience = 'expert';
  watchCallback();
  assert.equal(pending.length, 2);
  assert.match(pending[1].url, /\/202\/presentation-artifacts\?audience=expert$/);

  pending[0].resolve({
    code: 200,
    data: {
      artifact_id: 1001,
      report_id: 101,
      hotel_id: 7,
      audience: 'owner',
      artifact_readback_verified: true,
      content_sha256: 'a'.repeat(64),
      content_bytes: 10,
      spec_fingerprint: 'b'.repeat(64),
    },
  });
  await Promise.resolve();
  await Promise.resolve();
  assert.equal(state.aiDailyReportPresentationResult, null);
  assert.equal(state.aiDailyReportPresentationLoading, true);

  pending[1].resolve({
    code: 200,
    data: {
      artifact_id: 2002,
      report_id: 202,
      hotel_id: 8,
      audience: 'expert',
      artifact_readback_verified: true,
      content_sha256: 'c'.repeat(64),
      content_bytes: 20,
      spec_fingerprint: 'd'.repeat(64),
    },
  });
  await Promise.resolve();
  await Promise.resolve();
  assert.equal(state.aiDailyReportPresentationResult.status, 'ready');
  assert.equal(state.aiDailyReportPresentationResult.artifactId, 2002);
  assert.equal(state.aiDailyReportPresentationLoading, false);
});
