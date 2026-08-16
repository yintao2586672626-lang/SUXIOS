import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const loadHelpers = async () => {
  const source = await readFile(new URL('../../public/ai-daily-report-static.js', import.meta.url), 'utf8');
  const sandbox = { window: {}, console };
  vm.runInNewContext(source, sandbox, { filename: 'ai-daily-report-static.js' });
  return sandbox.window.SUXI_AI_DAILY_REPORT_STATIC;
};

const exportInput = () => ({
  reportId: 23,
  fallbackReportDate: '2026-08-16',
  qualityText: '证据不足',
  editionText: '界面版',
  bundle: {
    bundle_id: 'bundle_abcdefghijklmnop',
    source_fingerprint: 'fingerprint-a',
  },
  report: {
    schema_version: 'suxios.ota_competition_report.v1',
    status: 'blocked',
    title: '<阻断报告>',
    scope: { data_date: '2026-08-16' },
    render_contract: {
      bundle_id: 'bundle_abcdefghijklmnop',
      source_fingerprint: 'fingerprint-a',
      commercial_boundary: '非商业旗舰长报告',
    },
    management_snapshot: { platforms_ready: 0, platforms_total: 2, action_count: 0 },
    platform_sections: {},
    actions: [],
    data_gaps: [{ code: 'meituan_source_missing', message: '美团来源缺失' }],
  },
  platforms: [],
  groups: [],
});

test('competition report export binds report id, bundle id, and fingerprint', async () => {
  const helpers = await loadHelpers();
  const result = helpers.buildAiDailyCompetitionReportExport(exportInput());

  assert.equal(result.ok, true);
  assert.match(result.filename, /-r23-/);
  assert.match(result.html, /data-report-id="23"/);
  assert.match(result.html, /data-bundle-id="bundle_abcdefghijklmnop"/);
  assert.match(result.html, /data-source-fingerprint="fingerprint-a"/);
  assert.match(result.html, /meituan_source_missing/);
  assert.doesNotMatch(result.html, /<阻断报告>/);
  assert.match(result.html, /&lt;阻断报告&gt;/);
});

test('competition report export fails closed on identity mismatch', async () => {
  const helpers = await loadHelpers();
  const input = exportInput();
  input.report.render_contract.source_fingerprint = 'fingerprint-b';

  const result = helpers.buildAiDailyCompetitionReportExport(input);

  assert.equal(result.ok, false);
  assert.equal(result.code, 'competition_report_identity_mismatch');
});

test('xiaohongshu text remains withheld until ready for human review', async () => {
  const helpers = await loadHelpers();

  assert.equal(helpers.buildXiaohongshuDraftText({ status: 'withheld' }), '');
  assert.match(helpers.buildXiaohongshuDraftText({
    status: 'ready_for_human_review',
    topic: '竞争商圈复盘',
    titles_10: ['标题一'],
    cover_titles_5: ['封面一'],
    pages_8: [{ page: 1, title: '证据', points: '只写已验证事实' }],
    post_text: '待人工审核',
    tags_10: ['#酒店运营'],
    comments_3: ['你最关注哪个指标？'],
    human_review_checklist: ['核对来源'],
  }), /待人工审核/);
});

test('competition report bindings preserve saved document and withheld status', async () => {
  const helpers = await loadHelpers();
  const state = {
    report_document: { status: 'blocked' },
    content_drafts: { xiaohongshu: { status: 'withheld' } },
  };
  const computed = factory => ({ get value() { return factory(); } });
  const bindings = helpers.createCompetitionReportBindings({ computed, getBundle: () => state });

  assert.equal(bindings.reportDocument.value.status, 'blocked');
  assert.equal(bindings.reportReady.value, false);
  assert.equal(bindings.xiaohongshuDraft.value.status, 'withheld');
  assert.equal(bindings.xiaohongshuDraftText.value, '');
});
