import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const controllerSource = read('app/controller/OnlineData.php');
const publicSource = read('public/index.html');
const ctripProfileFieldComponentSource = read('public/components/online-data/ctrip-profile-field-config-panel.js');
const ctripProfileFieldComponentTemplateSource = ctripProfileFieldComponentSource
  .replace(/\\n/g, '\n')
  .replace(/\\"/g, '"');
const profileUiSource = `${publicSource}\n${ctripProfileFieldComponentTemplateSource}`;
const packageSource = read('package.json');

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  if (start < 0) return '';
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const fieldDefinitions = sliceBetween(
  controllerSource,
  'private function buildOtaCollectionFieldDefinitions',
  'private function normalizeOtaCollectionFieldAssetStatus'
);
const fieldSummary = sliceBetween(
  controllerSource,
  'private function summarizeOtaCollectionFieldDefinitions',
  'private function loadCollectionQualityRows'
);
const dataHealthFieldPanel = sliceBetween(
  publicSource,
  'data-testid="field-asset-summary-panel"',
  'data-testid="ctrip-capture-catalog-health"'
);
const profileFieldPanel = `${sliceBetween(
  publicSource,
  'data-testid="ctrip-profile-field-config-panel"',
  '<!-- 展开数据 -->'
) }\n${ctripProfileFieldComponentTemplateSource}`;
const profileFieldComputeds = sliceBetween(
  publicSource,
  'const ctripProfileForbiddenFieldKeys = new Set',
  'const refreshSelectedCtripProfileSampleField'
);
const profileFieldFilterLogic = sliceBetween(
  publicSource,
  'const ctripProfileFieldMatchesFilters = (field',
  'const platformAccountBindingStatusRows = computed'
);
const publicReturn = sliceBetween(
  publicSource,
  'aiProject, aiStrategyParams',
  'quickCookiesName'
);
const platformPlaceholder = sliceBetween(
  publicSource,
  'if (platformDataSourceForm.value.platform === \'meituan\'',
  'return \'非敏感配置 JSON'
);
const ctripAutoFetchSuccess = sliceBetween(
  controllerSource,
  'if ($savedCount > 0) {',
  'private function executeAutoFetchTask'
);

const forbiddenFields = ['guest_phone', 'order_phone', 'room_status', 'room_source_mapping'];

const checks = [
  {
    name: 'backend field definitions classify stable, not-returned-visible and forbidden assets',
    pass: fieldDefinitions.includes("'asset_status' => 'not_returned_visible'")
      && forbiddenFields.every((field) => fieldDefinitions.includes(`'field' => '${field}'`))
      && fieldDefinitions.includes("'asset_status' => 'forbidden'")
      && fieldDefinitions.includes("'storage_table' => 'not_collected'"),
  },
  {
    name: 'backend summary returns field asset status counts and lists',
    pass: fieldSummary.includes('normalizeOtaCollectionFieldAssetStatus')
      && fieldSummary.includes("'stable_field_count'")
      && fieldSummary.includes("'not_returned_field_count'")
      && fieldSummary.includes("'forbidden_field_count'")
      && fieldSummary.includes("'collectable_field_count'")
      && fieldSummary.includes("'stable_fields'")
      && fieldSummary.includes("'not_returned_fields'")
      && fieldSummary.includes("'forbidden_fields'")
      && fieldSummary.includes("'status_counts'"),
  },
  {
    name: 'data health panel surfaces stable, not returned and forbidden field asset buckets',
    pass: dataHealthFieldPanel.includes('稳定字段')
      && dataHealthFieldPanel.includes('未返回字段')
      && dataHealthFieldPanel.includes('禁止采集')
      && dataHealthFieldPanel.includes('collectionHealthFieldAssetListText')
      && publicSource.includes('const collectionHealthFieldAssetListText = (rows) =>'),
  },
  {
    name: 'Ctrip profile field configuration exposes field asset ledger cards',
    pass: profileFieldPanel.includes('data-testid="ctrip-profile-field-asset-ledger"')
      && profileFieldPanel.includes('ctripProfileFieldAssetLedgerCards')
      && profileFieldPanel.includes('ctripProfileForbiddenFieldAssets')
      && profileFieldPanel.includes('ctripProfileCaptureResultText')
      && profileFieldComputeds.includes('应抓 ${ctripProfileEnabledFieldCount.value} / 抓到 ${ctripProfileEnabledSampledFieldCount.value} / 未返回 ${ctripProfileEnabledMissingFieldCount.value}')
      && profileFieldComputeds.includes('未返回/失败')
      && profileFieldComputeds.includes('当前展示启用字段暂无历史获取值，需区分接口未触发或字段未入库')
      && profileFieldComputeds.includes('当前筛选 ${visible} / 配置表 ${total}'),
  },
  {
    name: 'profile field ledger keeps forbidden privacy fields explicit',
    pass: forbiddenFields.every((field) => profileFieldComputeds.includes(`key: '${field}'`))
      && profileFieldComputeds.includes('isCtripProfileFieldCollectable')
      && profileFieldComputeds.includes('ctripProfileNotReturnedVisibleFieldCount')
      && profileFieldComputeds.includes('ctripProfileStableVisibleFieldCount')
      && profileFieldComputeds.includes('不进表'),
  },
  {
    name: 'profile field filters expose missing sample values with the same ledger rule',
    pass: profileFieldPanel.includes('<option value="not_returned">未返回获取值</option>')
      && profileFieldFilterLogic.includes("filters.sample === 'not_returned'")
      && profileFieldFilterLogic.includes('isCtripProfileFieldEnabled(field)')
      && profileFieldFilterLogic.includes('!sampleText'),
  },
  {
    name: 'profile field recheck includes enabled fields with no returned sample',
    pass: profileUiSource.includes('recheckCtripProfileMismatchedFields')
      && profileUiSource.includes('!ctripProfileFieldSamplesLoaded')
      && publicSource.includes('const noReturnedValue = ctripProfileFieldSamplesLoaded.value')
      && publicSource.includes("String(field.latest_value || '').trim() === ''")
      && publicReturn.includes('ctripProfileFieldRecheckTargetCount'),
  },
  {
    name: 'Meituan Profile placeholder no longer nudges order capture',
    pass: platformPlaceholder.includes('"capture_sections": "traffic"')
      && !platformPlaceholder.includes('traffic,orders'),
  },
  {
    name: 'Ctrip auto-fetch success separates stored rows from field coverage',
    pass: ctripAutoFetchSuccess.includes('已入库 {$savedCount} 条')
      && ctripAutoFetchSuccess.includes('字段覆盖按配置表显示')
      && ctripAutoFetchSuccess.includes('未返回字段保留为缺口'),
  },
  {
    name: 'new field asset ledger bindings are returned from setup',
    pass: publicReturn.includes('ctripProfileForbiddenFieldAssets')
      && publicReturn.includes('ctripProfileFieldAssetLedgerCards')
      && publicReturn.includes('ctripProfileCaptureResultText')
      && publicReturn.includes('ctripProfileEnabledMissingFieldCount')
      && publicSource.includes('collectionHealthFieldAssetCards, collectionHealthFieldAssetListText'),
  },
  {
    name: 'npm script exposes field asset ledger verifier',
    pass: packageSource.includes('"verify:field-asset-ledger": "node scripts/verify_field_asset_ledger_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:field-asset-ledger] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:field-asset-ledger] ${checks.length} checks passed`);
