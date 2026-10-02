import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const cut = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
const handlers = cut('const openRevenueAiDecisionBasis =', 'const revenueAiResolveExecutionAction =')
  + cut('const openRevenueAiExecutionItem =', 'const revenueAiIsReviewActionLoading =');
function harness() {
  const ref = value => ({ value });
  const toasts = [], reads = [];
  let loaded = false;
  const env = {
    currentPage: ref('agent-center'), agentTab: ref('revenue'), revenueAgentTab: ref('analysis'),
    operationFilters: ref({ hotel_id: '80' }), revenueAiExecutionFocus: ref(null),
    revenueAiOverview: ref({ hotel_id: 80 }), filterReportHotel: ref('80'),
    nextTick: async () => {}, ensureRevenueAiStaticReady: async () => {},
    revenueAiResolveDecisionBasisNavigation: () => ({ targetPage: 'ops-track', label: '合成依据' }),
    revenueAiResolveExecutionAction: () => ({ action: 'open_execution', hotelId: 80, focus: { taskId: 901 }, actionLabel: '合成任务' }),
    loadOperationActions: async () => { reads.push(env.currentPage.value); return loaded; },
    showToast: (message, level) => toasts.push({ message, level }),
  };
  const context = vm.createContext(env);
  vm.runInContext(handlers + ';globalThis.openBasis=openRevenueAiDecisionBasis;globalThis.openExecution=openRevenueAiExecutionItem;', context);
  return { env, reads, toasts, setLoaded: value => { loaded = value; }, openBasis: context.openBasis, openExecution: context.openExecution };
}

for (const [label, open] of [['decision basis', 'openBasis'], ['execution item', 'openExecution']]) {
  test(`${label} does not announce operations success when the read fails, then recovers`, async () => {
    const h = harness();
    await h[open]({});
    assert.equal(h.reads.length, 1);
    assert.equal(h.toasts.some(toast => /已进入运营执行/.test(toast.message)), false);
    h.setLoaded(true);
    await h[open]({});
    assert.equal(h.reads.length, 2);
    assert.equal(h.toasts.filter(toast => /已进入运营执行/.test(toast.message)).length, 1);
    assert.equal(h.env.currentPage.value, 'ops-track');
  });
}
