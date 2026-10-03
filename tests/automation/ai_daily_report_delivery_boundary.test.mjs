import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

test('training delivery emits only the anonymized package with a date-free filename', async () => {
  const [appMain, deliveryClient, presentationService, rendererService] = await Promise.all([
    readFile(new URL('../../public/app-main.js', import.meta.url), 'utf8'),
    readFile(new URL('../../public/components/system/ai-daily-report-delivery.js', import.meta.url), 'utf8'),
    readFile(new URL('../../app/service/AiDailyReportPresentationSpecService.php', import.meta.url), 'utf8'),
    readFile(new URL('../../app/service/AiDailyReportPresentationRendererService.php', import.meta.url), 'utf8'),
  ]);
  const start = deliveryClient.indexOf('const downloadAiDailyReportPackage = async (');
  const end = deliveryClient.indexOf('\n\n        const buildSharePackage', start);
  assert.notEqual(start, -1, 'lazy AI daily presentation delivery method must exist');
  assert.notEqual(end, -1, 'lazy AI daily presentation delivery boundary must exist');
  const delivery = deliveryClient.slice(start, end);
  const jsonStart = deliveryClient.indexOf('const downloadAiDailyReportJsonPackage = async () => {');
  const jsonEnd = deliveryClient.indexOf('\n\n        watch(', jsonStart);
  assert.notEqual(jsonStart, -1, 'lazy AI daily JSON delivery method must exist');
  assert.notEqual(jsonEnd, -1, 'lazy AI daily JSON delivery boundary must exist');
  const jsonDelivery = deliveryClient.slice(jsonStart, jsonEnd);

  assert.match(appMain, /loadAiDailyReportDelivery/);
  assert.match(appMain, /aiDailyReportDeliveryRequest: apiRequest/);
  assert.match(delivery, /body: JSON\.stringify\(\{ audience: identity\.audience \}\)/);
  assert.match(delivery, /artifact\.artifact_readback_verified !== true/);
  assert.match(delivery, /Number\(artifact\.hotel_id \|\| 0\) !== identity\.hotelId/);
  assert.match(delivery, /String\(artifact\.audience \|\| ''\) !== identity\.audience/);
  assert.match(delivery, /String\(artifact\.spec_fingerprint \|\| ''\)\.trim\(\)\.toLowerCase\(\) !== expectedSpecFingerprint/);
  assert.match(delivery, /const proposedFilename = safeFilename\(\s*artifact\.filename,/);
  assert.doesNotMatch(delivery, /downloadAiDailyCompetitionReportHtml|copyAiDailyCompetitionXiaohongshuDraft|report_date/);

  assert.match(jsonDelivery, /const includeCompetition = audience !== 'training'/);
  assert.match(jsonDelivery, /if \(includeCompetition && !downloadAiDailyCompetitionReportHtml\(\)\) return/);
  assert.match(jsonDelivery, /audience === 'training'\s*\? `case-\$\{payload\.case_id \|\| 'unversioned'\}`/);
  assert.match(jsonDelivery, /if \(includeCompetition && context\(\)\.aiDailyReportCompetitionXiaohongshuDraftText\)/);
  assert.doesNotMatch(jsonDelivery, /hasCompetitionReport/);

  assert.match(presentationService, /if \(\$audience !== 'training'\) \{/);
  assert.match(presentationService, /'tenant_id' => \$audience === 'training' \? null : \$tenantId/);
  assert.match(presentationService, /'report_id' => \$audience === 'training' \? null : \$reportId/);
  assert.match(presentationService, /'hotel_id' => \$audience === 'training' \? null : \$hotelId/);
  assert.match(presentationService, /'business_date' => \$audience === 'training' \? null : \$reportDate/);
  assert.match(presentationService, /if \(\$audience === 'training'\) \{\s*\$spec = \$this->sanitizeTrainingSpec\(\$spec, \$reportDate\);/);
  assert.doesNotMatch(presentationService, /competition_report|competition_bundle|xiaohongshu/i);
  assert.match(rendererService, /\$date = preg_match\('\/\^\\d\{4\}-\\d\{2\}-\\d\{2\}\$\/', \$date\) \? \$date : 'training-case';/);
});

test('actual training JSON export downloads one anonymous case without competition output clipboard or requests', async () => {
  const [source, staticSource] = await Promise.all([
    readFile(new URL('../../public/components/system/ai-daily-report-delivery.js', import.meta.url), 'utf8'),
    readFile(new URL('../../public/ai-daily-report-static.js', import.meta.url), 'utf8'),
  ]);
  const downloads = [];
  let emittedBlob;
  let competitionExports = 0;
  let clipboardWrites = 0;
  let requests = 0;
  const privateValue = 'private-hotel-source-operator-sentinel';
  const sandbox = {
    window: {},
    Vue: { ref: value => ({ __v_isRef: true, value }), watch() {}, onBeforeUnmount() {} },
    document: { createElement: () => ({ click() { downloads.push(this.download); } }), body: { appendChild() {}, removeChild() {} } },
    URL: { createObjectURL: blob => { emittedBlob = blob; return 'blob:test-only'; }, revokeObjectURL() {} },
    navigator: { clipboard: { writeText: async () => { clipboardWrites += 1; } } },
    Blob,
    console,
  };
  vm.runInNewContext(staticSource, sandbox, { filename: 'public/ai-daily-report-static.js' });
  sandbox.window.SUXI_AI_DAILY_REPORT_STATIC = {
    ...sandbox.window.SUXI_AI_DAILY_REPORT_STATIC,
    buildAiDailyCompetitionReportExport: () => { competitionExports += 1; return {}; },
  };
  vm.runInNewContext(source, sandbox, { filename: 'public/components/system/ai-daily-report-delivery.js' });
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({ ctx: {
    aiDailyReport: { id: 88, hotel_id: 7, tenant_id: 3, report_date: '2026-10-02', summary: privateValue, trial_validation: { operator_name: privateValue } },
    aiDailyReportResultContract: { result_version: '2026-10-02-private-version', contract_version: 'result.v1', boundary: 'ota_channel' },
    aiDailyReportResultLayers: { source_facts: [{ key: 'ota_exposure', value: 0, source_ref: privateValue, hotel_id: 7, business_date: '2026-10-02' }], derived_metrics: [] },
    aiDailyReportAiInterpretation: { status: 'available', confidence: 'medium', summary: privateValue, data_basis: privateValue },
    aiDailyReportHumanJudgments: [{ operator_name: privateValue }],
    aiDailyReportCompetitionReportDocument: { schema_version: 'competition.v1', hotel_name: privateValue },
    aiDailyReportCompetitionXiaohongshuDraftText: privateValue,
    aiDailyReportDeliveryRequest: async () => { requests += 1; throw new Error('unexpected external request'); },
    showToast() {},
  } });
  state.aiDailyReportAudience = 'training';
  await state.downloadAiDailyReportJsonPackage();
  assert.equal(downloads.length, 1);
  assert.equal(competitionExports, 0);
  assert.equal(clipboardWrites, 0);
  assert.equal(requests, 0);
  const emitted = await emittedBlob.text();
  const payload = JSON.parse(emitted);
  assert.equal(payload.audience, 'training');
  assert.equal(payload.report_date, '');
  assert.equal(payload.source_facts[0].value, 0, 'observed zero survives anonymization');
  assert.equal(payload.source_facts[0].source_ref, undefined);
  assert.equal(payload.source_facts[0].hotel_id, undefined);
  assert.equal(payload.human_judgments, undefined);
  assert.doesNotMatch(emitted, new RegExp(privateValue));
  assert.doesNotMatch(emitted, /2026-10-02/);
  assert.doesNotMatch(downloads[0], /2026-10-02/);
});

test('training export explicitly blocks when the formal anonymous helper is missing while internal owner and expert packages retain scope', async () => {
  const source = await readFile(new URL('../../public/components/system/ai-daily-report-delivery.js', import.meta.url), 'utf8');
  const downloads = [];
  const notifications = [];
  let emittedBlob;
  let requests = 0;
  const privateValue = 'private-hotel-source-operator-sentinel';
  const sandbox = {
    window: {},
    Vue: { ref: value => ({ __v_isRef: true, value }), watch() {}, onBeforeUnmount() {} },
    document: { createElement: () => ({ click() { downloads.push(this.download); } }), body: { appendChild() {}, removeChild() {} } },
    URL: { createObjectURL: blob => { emittedBlob = blob; return 'blob:test-only'; }, revokeObjectURL() {} },
    navigator: {}, Blob, console,
  };
  vm.runInNewContext(source, sandbox, { filename: 'public/components/system/ai-daily-report-delivery.js' });
  const state = sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({ ctx: {
    aiDailyReport: { id: 88, hotel_id: 7, tenant_id: 3, report_date: '2026-10-02', summary: privateValue, trial_validation: { operator_name: privateValue }, source_refs: [{ tenant_id: 3, hotel_id: 7, source_ref: privateValue }] },
    aiDailyReportResultContract: { result_version: '2026-10-02-private-version', boundary: 'ota_channel' },
    aiDailyReportResultLayers: { source_facts: [{ value: 0, tenant_id: 3, hotel_id: 7, source_ref: privateValue, business_date: '2026-10-02' }] },
    aiDailyReportAiInterpretation: { status: 'available', summary: privateValue },
    aiDailyReportHumanJudgments: [{ operator_name: privateValue }],
    aiDailyReportDeliveryRequest: async () => { requests += 1; throw new Error('unexpected external request'); },
    showToast: (message, level) => notifications.push({ message, level }),
  } });
  state.aiDailyReportAudience = 'training';
  await state.downloadAiDailyReportJsonPackage();
  assert.equal(downloads.length, 0, 'missing anonymization helper cannot fall back to private JSON');
  assert.equal(requests, 0);
  assert.equal(notifications.length, 1);
  assert.equal(notifications[0].level, 'error');
  assert.match(notifications[0].message, /匿名训练包组件未就绪.*阻断导出/);

  for (const audience of ['owner', 'expert']) {
    state.aiDailyReportAudience = audience;
    await state.downloadAiDailyReportJsonPackage();
    const payload = JSON.parse(await emittedBlob.text());
    assert.equal(payload.audience, audience);
    assert.equal(payload.report_date, '2026-10-02');
    assert.equal(payload.summary, privateValue);
    assert.equal(payload.generated_from_result_version, '2026-10-02-private-version');
    assert.equal(payload.trial_validation.operator_name, privateValue);
    if (audience === 'expert') {
      assert.equal(payload.source_refs[0].tenant_id, 3);
      assert.equal(payload.source_refs[0].hotel_id, 7);
      assert.equal(payload.result_layers.source_facts[0].source_ref, privateValue);
      assert.equal(payload.result_layers.source_facts[0].business_date, '2026-10-02');
      assert.equal(payload.human_judgments[0].operator_name, privateValue);
    }
  }
  assert.equal(downloads.length, 2);
  assert.equal(requests, 0);
});
