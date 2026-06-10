import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';

const root = resolve(import.meta.dirname, '..');
const html = readFileSync(resolve(root, 'public/index.html'), 'utf8');
const operationStatic = readFileSync(resolve(root, 'public/operation-static.js'), 'utf8');

const requiredSnippets = [
  'selectedOpeningTaskIds',
  'selectedOpeningTasks',
  'toggleSelectAllFilteredOpeningTasks',
  'isAllFilteredOpeningTasksSelected',
  'batchUpdateOpeningTasks',
  'clearSelectedOpeningTasks',
  'aria-label="选择当前筛选检查项"',
  '批量处理',
  "batchUpdateOpeningTasks({ status: 'done'",
  "batchUpdateOpeningTasks({ status: 'doing'",
  "batchUpdateOpeningTasks({ status: 'blocked'",
  'batchUpdateOpeningTasks({ progress_percent: value',
];

const missing = requiredSnippets.filter((snippet) => !html.includes(snippet));

const requiredOperationStaticSnippets = [
  'buildOpeningOverviewCards',
  'clampOpeningOverviewPercent',
  'AI建议推进率',
];

const missingOperationStatic = requiredOperationStaticSnippets.filter((snippet) => !operationStatic.includes(snippet));

const requiredIndexStaticSnippets = [
  "const buildOpeningOverviewCards = requireOperationStatic('buildOpeningOverviewCards');",
  'const openingOverviewCards = computed(() => buildOpeningOverviewCards(openingOverview.value));',
];

const missingIndexStatic = requiredIndexStaticSnippets.filter((snippet) => !html.includes(snippet));

const operationStaticContext = { window: {} };
vm.runInNewContext(operationStatic, operationStaticContext);
const buildOpeningOverviewCards = operationStaticContext.window.SUXI_OPERATION_STATIC?.buildOpeningOverviewCards;
const sampleCards = typeof buildOpeningOverviewCards === 'function'
  ? buildOpeningOverviewCards({
    project: { opening_date: '2026-07-01', risk_level: 'high', overall_score: 86 },
    metrics: {
      days_left: 12,
      completion_rate: 65,
      core_completion_rate: 80,
      ai_penetration_rate: 45,
      completed_tasks: 13,
      total_tasks: 20,
      core_completed_tasks: 4,
      core_tasks: 5,
      high_risk_count: 2,
      overdue_count: 1,
      ai_covered_tasks: 9,
    },
  })
  : [];
const missingRuntimeContracts = [];
if (typeof buildOpeningOverviewCards !== 'function') {
  missingRuntimeContracts.push('operation-static.js: buildOpeningOverviewCards runtime function');
}
if (sampleCards.length !== 8 || !sampleCards.some((card) => card.label === 'AI建议推进率' && card.value === '45%')) {
  missingRuntimeContracts.push('operation-static.js: opening overview card sample output');
}

if (missing.length || missingOperationStatic.length || missingIndexStatic.length || missingRuntimeContracts.length) {
  console.error('Opening batch action contract missing:');
  for (const snippet of missing) {
    console.error(`- ${snippet}`);
  }
  for (const snippet of missingOperationStatic) {
    console.error(`- operation-static.js: ${snippet}`);
  }
  for (const snippet of missingIndexStatic) {
    console.error(`- index.html: ${snippet}`);
  }
  for (const snippet of missingRuntimeContracts) {
    console.error(`- ${snippet}`);
  }
  process.exit(1);
}

console.log('Opening batch action contract OK');
