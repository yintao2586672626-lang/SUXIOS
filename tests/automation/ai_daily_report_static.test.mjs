import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

async function loadHelper() {
  const source = await readFile(new URL('../../public/ai-daily-report-static.js', import.meta.url), 'utf8');
  const sandbox = { window: {} };
  vm.runInNewContext(source, sandbox, { filename: 'ai-daily-report-static.js' });
  return sandbox.window.SUXI_AI_DAILY_REPORT_STATIC;
}

const plain = value => JSON.parse(JSON.stringify(value));

test('AI daily static lists preserve legacy JSON and scalar compatibility', async () => {
  const helper = await loadHelper();

  assert.deepEqual(plain(helper.list('{"first":{"code":"one"},"second":"two"}')), [
    { code: 'one' },
    'two',
  ]);
  assert.deepEqual(plain(helper.objectList('[null,"legacy gap",{"code":"ready"}]')), [
    {
      code: 'raw_1',
      message: 'legacy gap',
      label: 'legacy gap',
      data_status: '结构待核验',
    },
    { code: 'ready' },
  ]);
  assert.deepEqual(plain(helper.actionList('[null,"legacy action"]')), [{
    title: '建议2',
    action: 'legacy action',
    reason: '建议结构不完整，需核验来源字段',
    can_create_execution_intent: false,
    blocked_reason: '建议结构不完整',
  }]);
});

test('AI daily metric truth stays bound to source scope and exact readback', async () => {
  const helper = await loadHelper();
  const otaSource = {
    ref: 'online_daily_data#41',
    source: 'online_daily_data',
    platform: 'ctrip',
    metric_keys: ['revenue'],
    data_date: '2026-08-15',
    collected_at: '2026-08-16 02:31:00',
    ingestion_method: 'network_response',
    persistence_status: 'stored',
    quality_status: 'normal',
    readback_verified: true,
  };
  const input = {
    metric: {
      key: 'revenue',
      value: 1888,
      metric_scope: 'ota_channel',
      source_ref: otaSource.ref,
    },
    report: {
      hotel_id: 80,
      report_date: '2026-08-15',
      source_refs: [otaSource],
    },
    permittedHotels: [{ id: 80, name: '宿析测试酒店' }],
  };
  const verified = helper.buildMetricTruth(input);

  assert.equal(verified.truth.status, 'verified');
  assert.equal(verified.scopeCode, 'ota_channel');
  assert.equal(verified.sourceRefsText, 'online_daily_data#41');
  assert.equal(verified.detail.hotelText, '宿析测试酒店（ID 80）');
  assert.equal(verified.detail.platformText, '携程');
  assert.equal(verified.detail.dateText, '2026-08-15');
  assert.equal(verified.detail.persistenceText, '已入库 1/1；回读 1/1');

  const partial = helper.buildMetricTruth({
    ...input,
    report: {
      ...input.report,
      source_refs: [otaSource, {
        ref: 'daily_reports#9',
        source: 'daily_reports',
        data_type: 'whole_hotel_daily_report',
        metric_keys: ['revenue'],
        data_date: '2026-08-15',
        quality_status: 'stale',
        readback_verified: false,
      }],
    },
    metric: { ...input.metric, metric_scopes: ['ota_channel', 'whole_hotel_daily_report'], source_ref: '' },
  });
  assert.equal(partial.truth.status, 'partial');
  assert.equal(partial.scopeCode, 'mixed');
  assert.match(partial.detail.platformText, /OTA部分/);

  const failed = helper.buildMetricTruth({
    ...input,
    report: {
      ...input.report,
      source_refs: [{ ...otaSource, quality_status: 'collection_failed', readback_verified: false, failure_reason: 'upstream timeout' }],
    },
  });
  assert.equal(failed.truth.status, 'collection_failed');
  assert.equal(failed.truth.failure_reason, 'upstream timeout');

  const proofless = helper.buildMetricTruth({
    metric: {
      key: 'revenue',
      value: 99,
      truth: { status: 'verified', persistence: { record_count: 1, readback_verified_count: 0 } },
    },
  });
  assert.equal(proofless.truth.status, 'unverified');

  const missingValue = helper.buildMetricTruth({
    ...input,
    metric: { ...input.metric, value: null },
  });
  assert.equal(missingValue.truth.status, 'partial');
  assert.equal(helper.metricCalculation({ value: null, result_layer: 'derived_metric' }).code, 'missing');
});

test('AI daily competition and share models keep blocked, synthetic, and training boundaries', async () => {
  const helper = await loadHelper();
  const presentation = helper.buildCompetitionPresentation({
    schema_version: 1,
    source: { dataset_kind: 'synthetic' },
    render_contract: { requested_edition: 'flagship' },
    quality: {
      status: 'synthetic',
      decision_eligible: false,
      data_gaps: '[{"code":"ctrip_rate_missing","message":"缺少实时房价"}]',
    },
    facts: {
      ctrip: { self: { adr: 320 }, competitor_average: { adr: 350 }, competitor_count: 3 },
      meituan: { self_position_text: '第 4 名', top_hotel_name: '标杆酒店', top1_gap_text: '差 3 位' },
    },
    analysis: { ctrip: {}, meituan: {} },
    candidate_competitors: { ctrip: { direct: '[{"hotel_name":"酒店A"}]' } },
    report_document: { status: 'blocked' },
    content_drafts: {
      xiaohongshu: {
        status: 'ready_for_human_review',
        topic: '渠道复盘',
        titles_10: '["标题一"]',
        pages_8: '[{"page":1,"title":"首页","points":"只讲已验证事实"}]',
        tags_10: '["#酒店运营"]',
        comments_3: '["欢迎人工复核"]',
        human_review_checklist: '["核对来源"]',
      },
    },
  });

  assert.equal(presentation.editionText, '旗舰版');
  assert.equal(presentation.qualityText, '模拟测试');
  assert.equal(presentation.reportReady, false);
  assert.equal(presentation.groups[0].namesText, '酒店A');
  assert.match(presentation.summaryText, /synthetic 模拟测试/);
  assert.match(presentation.summaryText, /未通过，不生成执行建议/);
  assert.match(presentation.xiaohongshuDraftText, /标题一/);
  assert.match(presentation.xiaohongshuDraftText, /只讲已验证事实/);

  const commonInput = {
    report: {
      hotel_id: 80,
      report_date: '2026-08-15',
      summary: 'HOTEL_PRIVATE_80 DATE_PRIVATE_2026_08_15',
      source_refs: [{ ref: 'SOURCE_ROW_PRIVATE_41' }],
      workflow_gaps: [{ code: 'approval_pending' }],
      trial_validation: { reviewed_by: 'OPERATOR_PRIVATE_9', note: 'COOKIE_PRIVATE_VALUE' },
    },
    contract: {
      result_version: 'RESULT_VERSION_PRIVATE_123456789',
      contract_version: 'v1',
      metric_version: 'm1',
      reference_version: 'r1',
      boundary: 'OTA only',
    },
    resultReadiness: { stage: 'partial', detail: 'HOTEL_PRIVATE_80' },
    aiInterpretation: { status: 'available', confidence: 'medium', boundary: 'TOKEN_PRIVATE_VALUE' },
    resultLayers: {
      source_facts: [{ key: 'revenue', label: 'HOTEL_PRIVATE_80', value: 1888, source_ref: 'SOURCE_ROW_PRIVATE_41' }],
      derived_metrics: [{ key: 'adr', value: 320 }],
    },
    competitorChanges: [{ label: '竞品变化' }],
    dataGaps: [{ code: 'missing_rate', message: 'DATE_PRIVATE_2026_08_15' }],
    workflowReadiness: { stage: 'blocked' },
    humanJudgments: [{ decision: 'accepted', actor_user_id: 'OPERATOR_PRIVATE_9' }],
    metricCards: [{ key: 'revenue', value: 1888 }],
    abnormalMetrics: [{
      type: 'signal',
      label: 'HOTEL_PRIVATE_80',
      level: 'high',
      evidence: 'SOURCE_ROW_PRIVATE_41',
      signal_status: 'partial',
      reference_basis: { status: 'missing' },
    }],
  };
  const owner = helper.buildSharePackage({ ...commonInput, audience: 'owner' });
  const expert = helper.buildSharePackage({ ...commonInput, audience: 'expert' });
  const training = helper.buildSharePackage({ ...commonInput, audience: 'training' });

  assert.equal(owner.latest_human_judgment.actor_user_id, 'OPERATOR_PRIVATE_9');
  assert.equal(expert.source_refs[0].ref, 'SOURCE_ROW_PRIVATE_41');
  assert.equal(training.report_date, '');
  assert.equal(training.case_id, 'training-case');
  assert.equal(training.source_facts[0].source_ref, undefined);
  assert.equal(training.source_facts[0].result_layer, 'source_fact');
  assert.equal(training.derived_metrics[0].result_layer, 'derived_metric');
  assert.equal(training.human_judgments, undefined);
  assert.equal(training.result_contract.result_version, undefined);
  assert.equal(training.summary, '脱敏训练样本：仅保留结构化指标与枚举状态。');
  assert.equal(training.data_gap_count, 1);
  assert.deepEqual(plain(training.ai_assistance), { status: 'available', confidence: 'medium' });
  const trainingJson = JSON.stringify(training);
  for (const forbiddenValue of [
    'HOTEL_PRIVATE_80',
    'DATE_PRIVATE_2026_08_15',
    'SOURCE_ROW_PRIVATE_41',
    'OPERATOR_PRIVATE_9',
    'COOKIE_PRIVATE_VALUE',
    'TOKEN_PRIVATE_VALUE',
    'RESULT_VERSION_PRIVATE_123456789',
  ]) {
    assert.doesNotMatch(trainingJson, new RegExp(forbiddenValue));
  }
  for (const forbiddenKey of [
    'hotel_id',
    'source_ref',
    'actor_user_id',
    'generated_from_result_version',
    'trial_validation',
    'result_version',
    'evidence',
    'message',
  ]) {
    assert.doesNotMatch(trainingJson, new RegExp(`"${forbiddenKey}"`));
  }
  assert.equal(helper.wecomPartStatusText({ delivery_status: 'sent', idempotent_replay: true }), '已送达（重复请求已拦截）');
  assert.equal(helper.wecomPartStatusText({ delivery_status: 'render_failed' }), '图卡生成失败');
});

test('AI daily competition export stays identity-bound and escapes rendered facts', async () => {
  const helper = await loadHelper();
  const build = helper.buildAiDailyCompetitionReportExport;

  assert.deepEqual(
    { ...build({}) },
    {
      ok: false,
      code: 'competition_report_missing',
      message: '当前日报没有可导出的竞争商圈报告',
      level: 'warning',
    },
  );

  const input = {
    reportId: 17,
    readbackReceipt: { status: 'exact_readback_verified', exact_readback_verified: true },
    requestedEdition: 'flagship',
    fallbackReportDate: '2026-08-16',
    qualityText: '可信 <待复核>',
    editionText: '旗舰版',
    bundle: { bundle_id: 'bundle_abcdefghijklmnop', source_fingerprint: 'fingerprint-a' },
    report: {
      schema_version: 1,
      title: '<script>alert(1)</script>',
      scope: { data_date: '2026-08-15' },
      render_contract: {
        bundle_id: 'bundle_abcdefghijklmnop',
        source_fingerprint: 'fingerprint-a',
        commercial_boundary: '只限 OTA & 渠道事实',
      },
      platform_sections: {
        ctrip: {
          status: 'ready_for_review',
          channel_role: '引流 <事实>',
          first_conflict: '曝光 & 转化',
        },
      },
      management_snapshot: { platforms_ready: 1, platforms_total: 2, action_count: 1 },
      actions: JSON.stringify([null, 'legacy action', { platform: 'ctrip', title: '<复核>', action: '人工确认' }]),
      data_gaps: JSON.stringify([null, 'legacy gap', { code: 'missing_rate', message: '<缺口>' }]),
    },
    platforms: [{ platform: 'ctrip', label: '<携程>', factText: '事实 & 证据', gapText: '<待补>' }],
    groups: [{ label: '<核心组>', namesText: '酒店 A & B' }],
  };
  const result = build(input);

  assert.equal(result.ok, true);
  assert.equal(result.edition, 'flagship');
  assert.equal(result.filename, 'suxios-ota-competition-flagship-2026-08-15-r17-efghijklmnop.html');
  assert.match(result.html, /data-report-edition="flagship"/);
  assert.match(result.html, /竞品分组/);
  assert.match(result.html, /渠道角色/);
  assert.match(result.html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.match(result.html, /&lt;携程&gt;/);
  assert.match(result.html, /事实 &amp; 证据/);
  assert.match(result.html, /legacy gap/);
  assert.match(result.html, /&lt;缺口&gt;/);
  assert.doesNotMatch(result.html, /<script>alert\(1\)<\/script>/);

  const lite = build({ ...input, requestedEdition: 'lite', editionText: '旗舰版' });
  assert.equal(lite.ok, true);
  assert.equal(lite.edition, 'lite');
  assert.equal(lite.filename, 'suxios-ota-competition-lite-2026-08-15-r17-efghijklmnop.html');
  assert.match(lite.html, /data-report-edition="lite"/);
  assert.match(lite.html, /优先动作/);
  assert.match(lite.html, /关键数据缺口/);
  assert.doesNotMatch(lite.html, /<h3>竞品分组<\/h3>/);
  assert.doesNotMatch(lite.html, /渠道角色/);

  const legacy = build({
    ...input,
    readbackReceipt: { status: 'legacy_unverified', exact_readback_verified: false },
  });
  assert.equal(legacy.ok, false);
  assert.equal(legacy.code, 'competition_bundle_exact_readback_required');

  const mismatch = build({
    ...input,
    bundle: { ...input.bundle, source_fingerprint: 'fingerprint-b' },
  });
  assert.equal(mismatch.ok, false);
  assert.equal(mismatch.code, 'competition_report_identity_mismatch');
});

test('AI daily competition export caller forwards the persisted exact-readback receipt', async () => {
  const source = await readFile(new URL('../../public/app-main.js', import.meta.url), 'utf8');
  const start = source.indexOf('const downloadAiDailyCompetitionReportHtml =');
  const end = source.indexOf('const copyAiDailyCompetitionXiaohongshuDraft =', start);
  assert.ok(start >= 0 && end > start, 'competition export caller must stay discoverable');
  const caller = source.slice(start, end);
  assert.match(
    caller,
    /readbackReceipt:\s*aiDailyReport\.value\?\.competition_bundle_readback\s*\|\|\s*\{\}/,
  );
});
