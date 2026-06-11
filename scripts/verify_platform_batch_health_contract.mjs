import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const publicSource = read('public/index.html');
const dataHealthSource = read('public/data-health-static.js');
const packageSource = read('package.json');

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  if (start < 0) return '';
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const batchMarkup = sliceBetween(
  publicSource,
  'data-testid="platform-batch-health-check"',
  '<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">'
);
const batchState = sliceBetween(
  publicSource,
  'const platformBatchHealthBadgeClass =',
  'const platformImportForm = ref'
);
const loadPanel = sliceBetween(
  publicSource,
  'const loadPlatformDataSourcePanel = async () =>',
  'const savePlatformDataSource = async () =>'
);
const competitorLoader = sliceBetween(
  publicSource,
  'const loadCompetitorSummary = async (options = {}) =>',
  'const loadCompassData = async'
);
const setupReturn = sliceBetween(
  publicSource,
  'platformAccountBindingStatusRows,',
  'applyPlatformAccountBindingGuide'
);

const checks = [
  {
    name: 'platform account panel exposes multi-store batch health table',
    pass: batchMarkup.includes('platformBatchHealthSummaryCards')
      && batchMarkup.includes('platformBatchHealthRows')
      && batchMarkup.includes('绑定状态')
      && batchMarkup.includes('采集状态')
      && batchMarkup.includes('竞对可信度')
      && batchMarkup.includes('待动作'),
  },
  {
    name: 'batch health scope remains OTA-only and does not imply whole-hotel truth',
    pass: batchMarkup.includes('仅代表 OTA 渠道状态，不代表全酒店经营口径'),
  },
  {
    name: 'batch health rows derive from existing hotels, platform data sources and competitor summaries',
    pass: batchState.includes('platformDataSourceHotelOptions.value')
      && batchState.includes('dashboardHotelOptions.value')
      && batchState.includes('platformDataSources.value')
      && batchState.includes('hotelCompetitorSummaries.value')
      && batchState.includes('buildPlatformBatchHealthRows({')
      && batchState.includes('competitorSummaryReadiness'),
  },
  {
    name: 'batch health keeps missing and failed collection states explicit',
    pass: dataHealthSource.includes("bindingText = '待绑定'")
      && dataHealthSource.includes("collectionText = '未采集'")
      && dataHealthSource.includes("collectionText = '采集失败'")
      && dataHealthSource.includes("collectionText = '待试采'")
      && dataHealthSource.includes('缺少最近采集证据'),
  },
  {
    name: 'batch health next actions cover binding, retry, trial capture and competitor review',
    pass: dataHealthSource.includes("nextAction = '配置平台账号绑定'")
      && dataHealthSource.includes("nextAction = '查看同步日志并重试采集'")
      && dataHealthSource.includes("nextAction = '执行一次试采集'")
      && dataHealthSource.includes("nextAction = competitorReadiness.next_action || '复核竞对榜单'"),
  },
  {
    name: 'platform source panel refresh loads competitor by-hotel summaries for the batch health table',
    pass: loadPanel.includes('loadCompetitorSummary({ includeByHotel: true })')
      && competitorLoader.includes("params.append('include_by_hotel', '1')")
      && competitorLoader.includes('hotelCompetitorSummaries.value'),
  },
  {
    name: 'batch health setup state is returned to the Vue template',
    pass: setupReturn.includes('platformBatchHealthRows')
      && setupReturn.includes('platformBatchHealthSummaryCards')
      && setupReturn.includes('platformBatchHealthBadgeClass'),
  },
  {
    name: 'batch health row and summary builders live in data-health static helper',
    pass: batchState.includes("requirePlatformBatchHealthStatic('buildPlatformBatchHealthRows')")
      && batchState.includes("requirePlatformBatchHealthStatic('buildPlatformBatchHealthSummaryCards')")
      && dataHealthSource.includes('const buildPlatformBatchHealthRows = ({')
      && dataHealthSource.includes('const buildPlatformBatchHealthSummaryCards = (rows = [])')
      && dataHealthSource.includes('platformBatchHealthBadgeClass')
      && !batchState.includes('const sourceMap = new Map()'),
  },
  {
    name: 'npm script exposes platform batch health verifier',
    pass: packageSource.includes('"verify:platform-batch-health": "node scripts/verify_platform_batch_health_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:platform-batch-health] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:platform-batch-health] ${checks.length} checks passed`);
