export const FROZEN_AI_WORKBENCH_SOURCE = 'tests/fixtures/frontend-retired-20261002.zip#23b-page-ai-workbench.html';

export function verifyFrozenAiWorkbenchContract({
  requireText,
  requirePattern,
  requireNoText,
  requireOrder,
}) {
  const template = FROZEN_AI_WORKBENCH_SOURCE;

  requireNoText('resources/frontend/templates/manifest.json', '"id": "page-ai-workbench",', 'retired AI workbench is absent from the active template manifest');
  requirePattern('tests/fixtures/frontend-retired-20261002.json', /"id": "page-ai-workbench",[\s\S]*?"runtime": false/, 'historical AI workbench fixture remains explicitly retired');
  requireNoText('resources/frontend/app-template.html', 'data-testid="home-ai-workbench"', 'runtime template does not ship the frozen AI workbench page');
  requireText(template, '<span class="dual-ota-context-item dual-ota-context-item-store">', 'frozen AI workbench source retains the current-hotel selector in the platform scope row');
  requireOrder(template, '<span class="dual-ota-context-item dual-ota-context-item-store">', '<div class="dual-ota-store-scope-list" role="list" aria-label="经营数据源选择">', 'frozen AI workbench source aligns current-hotel selector before the data-source switch buttons');
  requireText(template, '<div class="dual-ota-store-scope-list" role="list" aria-label="经营数据源选择">', 'frozen AI workbench source retains the data-source switch buttons');
  requireText(template, '<option v-for="hotel in dualOtaCurrentHotelOptions" :key="hotel.id" :value="hotel.id">{{ hotel.name }}</option>', 'frozen AI workbench source retains the ordered hotel list');
  requireText(template, '<button type="button" :class="[\'dual-ota-compare-toggle\', dualOtaCompareEnabled ? \'is-active\' : \'\']"', 'frozen AI workbench source retains the same-period comparison switch');
  requireText(template, '<small v-if="dualOtaCompareEnabled" :title="metric.note">{{ dualOtaMetricComparisonText(metric) }}</small>', 'frozen AI workbench source retains comparison footnotes');
  requireText(template, '<small v-else="" class="dual-ota-system-metric-spacer" aria-hidden="true">&nbsp;</small>', 'frozen AI workbench source retains the comparison-disabled metric spacer');
  requireText(template, 'class="dual-ota-context-select dual-ota-hotel-select"', 'frozen AI workbench source retains the readable hotel-select styling');
  requireText(template, '<h2>{{ dualOtaPlatformRevenueTitle }}</h2>', 'frozen AI workbench source retains the platform-aware revenue title');
  requireText(template, '<div v-if="platform.metrics &amp;&amp; platform.metrics.length" class="dual-ota-platform-metrics">', 'frozen AI workbench source retains the single-platform revenue metrics');
  requireText(template, 'dualOtaPlatformRevenuePlatforms.length === 1 ? \'is-single\' : \'\'', 'frozen AI workbench source retains the single-column revenue structure');
  requireText(template, '<div v-if="dualOtaPlatformRevenueHasContribution" class="dual-ota-contribution" data-testid="dual-ota-platform-contribution-bar">', 'frozen AI workbench source retains the conditional contribution bar');
}
