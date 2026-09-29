import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

test('the real daily presentation bridge recovers from delayed registration and follows report changes', () => {
  const source = readFileSync('public/app-main.js', 'utf8');
  const slice = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
  const sandbox = {
    window: {}, computed: Vue.computed, revenueAiStaticRevision: Vue.ref(0), revenueAiStaticReady: Vue.ref(false),
    requireRevenueAiStatic: null,
    aiDailyReport: Vue.ref(null), aiDailyReportForm: Vue.ref({ hotel_id: '80', report_date: '2026-09-25' }),
    aiDailyReportSendScopeCurrent: () => Number(sandbox.aiDailyReport.value?.hotel_id) === Number(sandbox.aiDailyReportForm.value.hotel_id)
      && sandbox.aiDailyReport.value?.report_date === sandbox.aiDailyReportForm.value.report_date,
    aiDailyFactGateLoading: Vue.ref(false), aiDailyFactGateState: Vue.ref({}),
    aiDailyReportAiInterpretation: Vue.ref({}), hotels: Vue.ref([]), permittedHotels: Vue.ref([{ id: 80, name: '合成门店' }]),
    aiDailyReportActionIsInvestigationOnly: () => false, aiDailyReportModelIsLimited: value => value === 'invalid_output',
    aiDailyReportReadinessClass: stage => `stage-${stage}`, onlineTruthDetailText: () => '待核验',
    operationMoney: value => `¥${value}`, operationValue: (value, suffix = '') => `${value}${suffix}`,
    revenueAiBuildDailyFactGate: () => ({ platformRows: [] }), revenueAiDailyReportActionExecutionReady: () => false,
  };
  vm.createContext(sandbox);
  vm.runInContext([
    slice('            const aiDailyReportList =', '            const aiDailyReportGenerationOutcome ='),
    slice('            let aiDailyReportPresentation =', '            const aiDailyReportEvidenceTarget ='),
    slice('            const aiDailyReportModelText = computed', '            const aiDailyReportTaskReturn ='),
    'this.display = { model: aiDailyReportModelText, metrics: aiDailyReportMetricCards, actions: aiDailyReportActions, sources: aiDailyReportSourceCount, factory: readAiDailyReportPresentation, value: aiDailyReportMetricValue, actionClass: aiDailyReportActionStatusClass };',
  ].join('\n'), sandbox);
  assert.equal(sandbox.display.model.value, '');
  assert.equal(sandbox.display.metrics.value.length, 0);
  vm.runInContext(readFileSync('public/ai-daily-report-static.js', 'utf8'), sandbox);
  const helper = sandbox.window.SUXI_AI_DAILY_REPORT_STATIC;
  sandbox.revenueAiStaticReady.value = true;
  sandbox.revenueAiStaticRevision.value++;
  assert.equal(sandbox.display.model.value, '未生成');
  const factory = sandbox.display.factory();
  for (const value of Object.values(factory)) if (Vue.isRef(value)) assert.doesNotThrow(() => value.value);
  sandbox.aiDailyReport.value = {
    id: 510, hotel_id: 80, report_date: '2026-09-25', model_status: 'invalid_output',
    yesterday_result: { metrics: [{ key: 'revenue', value: null, platform: 'ctrip' }] },
    recommended_actions: JSON.stringify([{ title: '核对来源', action_readiness: { stage: 'blocked' } }]),
    source_refs: [{ ref: 'online_daily_data#41', platform: 'ctrip', data_date: '2026-09-25' }],
  };
  assert.equal(sandbox.display.model.value, '数据或模型受限，规则版仅供核验');
  assert.equal(sandbox.display.metrics.value[0].calculationStatus, 'missing');
  assert.equal(sandbox.display.value(sandbox.display.metrics.value[0]), '—');
  assert.equal(sandbox.display.actions.value[0].title, '核对来源');
  assert.equal(sandbox.display.actionClass(sandbox.display.actions.value[0]), 'stage-blocked');
  assert.equal(sandbox.display.sources.value, 1);
  assert.equal(sandbox.display.factory(), factory);
  sandbox.aiDailyReport.value = null;
  assert.equal(sandbox.display.model.value, '未生成');
  assert.equal(sandbox.display.metrics.value.length, 0);
  assert.equal(sandbox.display.actions.value.length, 0);
});
