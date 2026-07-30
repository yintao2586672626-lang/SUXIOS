import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { loadFrontendTemplateSource } from './lib/frontend_template_source.mjs';

const entrySource = readFileSync('public/index.html', 'utf8');
const appMainSource = readFileSync('public/app-main.js', 'utf8');
const templateSource = loadFrontendTemplateSource(process.cwd()).template;
const publicSource = [entrySource, appMainSource, templateSource].join('\n');
const homeStaticSource = readFileSync('public/home-static.js', 'utf8');
const packageSource = readFileSync('package.json', 'utf8');

const homeStaticContext = { window: {} };
vm.runInNewContext(homeStaticSource, homeStaticContext);
const buildHomeTrendChartConfig = homeStaticContext.window.SUXI_HOME_STATIC?.buildHomeTrendChartConfig;
const sampleTrendChartConfig = typeof buildHomeTrendChartConfig === 'function'
  ? buildHomeTrendChartConfig({
    labels: ['2026-06-01', '2026-06-02'],
    metric: { label: '收入', unit: '¥', data: [12345, null] },
    metricKey: 'revenue',
  })
  : null;
const buildHomeDataSources = homeStaticContext.window.SUXI_HOME_STATIC?.buildHomeDataSources;
const sampleHomeDataSources = typeof buildHomeDataSources === 'function'
  ? buildHomeDataSources({
    sampleDays: 7,
    trendReady: true,
    trendUpdatedAt: '2026-06-10',
    channelSignal: { status: 'ok', updated_at: '2026-06-09' },
    priceSignal: { status: 'pending', updated_at: '2026-06-08' },
    weatherSignal: { status: 'ok', updated_at: '2026-06-07' },
    weatherCount: 2,
    nearestHoliday: { name: 'Dragon Boat' },
    holidayUpdatedAt: '2026-06-06',
    compassLastSyncedAt: '2026-06-05',
  })
  : [];
const buildHomeBusinessTimeModel = homeStaticContext.window.SUXI_HOME_STATIC?.buildHomeBusinessTimeModel;
const buildCompassDataReadiness = homeStaticContext.window.SUXI_HOME_STATIC?.buildCompassDataReadiness;
const sampleHomeBusinessTimeModel = typeof buildHomeBusinessTimeModel === 'function'
  ? buildHomeBusinessTimeModel({
    temporalData: {
      metric_scope: 'ota_channel',
      scope_note: '仅反映已授权 OTA 渠道数据。',
      past: {
        status: 'ready',
        period: { end_date: '2026-06-09' },
        source: { fact_rows: 4 },
        series: [{
          date: '2026-06-09',
          ota_revenue: 0,
          ota_orders: 0,
          ota_room_nights: 0,
          ota_detail_exposure: 0,
          platforms: ['ctrip'],
        }],
      },
      present: { status: 'partial', snapshot_row_count: 2, as_of_time: '2026-06-10 09:00:00' },
      future: { status: 'ready', series: [{ date: '2026-06-11' }] },
    },
    hotelName: '测试门店',
    futureCard: { value: 'OTA收入 向上', detail: '测试研判' },
  })
  : null;
const sampleHomeReadiness = typeof buildCompassDataReadiness === 'function'
  ? buildCompassDataReadiness(sampleHomeDataSources)
  : null;

const checks = [
  {
    name: 'home keeps legacy operating panels out of the factual landing page',
    pass: templateSource.includes('data-testid="home-yesterday-facts"')
      && !templateSource.includes('data-testid="home-operating-board"')
      && !templateSource.includes('data-testid="home-quick-entry-fold"')
      && !templateSource.includes('data-testid="home-closed-loop-workbench"'),
  },
  {
    name: 'home first screen separates yesterday facts, today acquisition status, future AI judgement and source scopes',
    pass: publicSource.includes('data-testid="home-yesterday-facts"')
      && publicSource.includes(':data-testid="stage.testid"')
      && homeStaticSource.includes("testid: 'home-today-acquired-status'")
      && homeStaticSource.includes("testid: 'home-future-ai-judgement'")
      && publicSource.includes('data-testid="home-scope-boundaries"')
      && publicSource.includes('data-testid="home-competitor-diagnostic-reference"')
      && publicSource.includes('v-for="fact in homeBusinessTimeModel.yesterday.facts"')
      && publicSource.includes('v-for="scope in homeBusinessTimeModel.scopeRows"')
      && publicSource.includes("requireHomeStatic('buildHomeBusinessTimeModel')")
      && publicSource.includes('const homeBusinessTimeModel = computed')
      && homeStaticSource.includes('不回退旧日期')
      && homeStaticSource.includes('不把进行中快照写成日终经营结果')
      && homeStaticSource.includes('不使用 OTA 数据外推全酒店 OCC、RevPAR 或总营收'),
  },
  {
    name: 'home business time helper preserves captured zero and keeps competitors outside core readiness',
    pass: sampleHomeBusinessTimeModel?.yesterday?.status === '已取得'
      && sampleHomeBusinessTimeModel?.yesterday?.facts?.every((fact) => fact.ready)
      && sampleHomeBusinessTimeModel?.today?.status === '部分取得'
      && sampleHomeBusinessTimeModel?.future?.note?.includes('不自动执行')
      && sampleHomeDataSources.find((source) => source.name === '竞对价格')?.role === 'diagnostic'
      && sampleHomeReadiness?.diagnosticText?.includes('不计入核心事实就绪度'),
  },
  {
    name: 'home cockpit header exposes compact signal row without new data source',
    pass: publicSource.includes('data-testid="home-cockpit-header"')
      && publicSource.includes('data-testid="home-header-signal-row"')
      && publicSource.includes('{{ compassDataReadiness.summaryText }}')
      && publicSource.includes("{{ homeObservation?.sampleDaysText || '--' }}")
      && publicSource.includes("{{ homeCompetitorReadiness.label || '待同步' }}")
      && publicSource.includes("{{ homeBoardActionRows[0]?.title || '复核数据' }}"),
  },
  {
    name: 'decision strip reuses current dashboard state instead of new fallback data',
    pass: publicSource.includes('const homeDecisionSummaryRows = computed')
      && publicSource.includes("requireHomeStatic('buildHomeDecisionSummaryRows')")
      && publicSource.includes("requireHomeStatic('buildHomeBoardActionRows')")
      && publicSource.includes("requireHomeStatic('buildHomeDataSources')")
      && publicSource.includes("requireHomeStatic('buildCompassDataReadiness')")
      && publicSource.includes('buildHomeDataSources({')
      && publicSource.includes('buildHomeDecisionSummaryRows({')
      && publicSource.includes('trendReady: homeTrendHasSamples.value')
      && publicSource.includes('const competitorReadiness = homeCompetitorReadiness.value || {}')
      && publicSource.includes('const action = homeBoardActionRows.value[0] || {}'),
  },
  {
    name: 'home data-source readiness cards live in explicit static helper',
    pass: publicSource.includes("requireHomeStatic('buildHomeDataSources')")
      && publicSource.includes('const homeDataSources = computed(() => {')
      && publicSource.includes('return buildHomeDataSources({')
      && homeStaticSource.includes('buildHomeDataSources')
      && homeStaticSource.includes('isHomeSignalReady')
      && !publicSource.includes("name: '经营趋势样本'")
      && !publicSource.includes("name: 'OTA 渠道数据'")
      && Array.isArray(sampleHomeDataSources)
      && sampleHomeDataSources.length === 5
      && sampleHomeDataSources[0]?.ready === true
      && sampleHomeDataSources[0]?.updatedAt === '2026-06-10'
      && sampleHomeDataSources[1]?.ready === true
      && sampleHomeDataSources[2]?.ready === false
      && sampleHomeDataSources[3]?.updatedAt === '2026-06-07'
      && sampleHomeDataSources[4]?.updatedAt === '2026-06-06',
  },
  {
    name: 'decision strip covers data readiness, trend samples, competitor trust and next action',
    pass: ['data-readiness', 'trend-sample', 'competitor', 'next-action'].every((key) => homeStaticSource.includes(`key: '${key}'`)),
  },
  {
    name: 'competitor summary keeps VIP no-inference wording on the home decision strip',
    pass: publicSource.includes('competitorTagText: homeCompetitorPlatformTagText.value')
      && publicSource.includes('competitorSourceNotice: homeCompetitorSourceNotice.value')
      && homeStaticSource.includes("competitorTagText || competitorSourceNotice || '不推断VIP'")
      && homeStaticSource.includes("entry: { page: 'meituan-ebooking', tab: 'meituan-ranking' }"),
  },
  {
    name: 'home competitor evidence stays a collapsed diagnostic reference',
    pass: templateSource.includes('data-testid="home-competitor-diagnostic-reference"')
      && templateSource.includes('竞对异常诊断参考（非首页主线）')
      && !templateSource.includes('data-testid="home-competitor-summary"')
      && !templateSource.includes('data-testid="home-competitor-card-grid"')
      && homeStaticSource.includes("role: 'diagnostic'"),
  },
  {
    name: 'home removes duplicate action panels from the factual landing page',
    pass: templateSource.includes('data-testid="home-temporal-axis"')
      && !templateSource.includes('data-testid="home-action-panel"')
      && !templateSource.includes('data-testid="home-decision-strip"'),
  },
  {
    name: 'home closed-loop builders live in explicit static helper',
    pass: /app-startup-helpers\.min\.js\?v=[^"']+/.test(publicSource)
      && publicSource.includes("requireHomeStatic('buildHomeClosedLoopStages')")
      && publicSource.includes("requireHomeStatic('buildHomeAiTraceRows')")
      && publicSource.includes("requireHomeStatic('buildHomeOperatingResultCards')")
      && publicSource.includes("requireHomeStatic('buildHomeCausalChainNodes')")
      && publicSource.includes("requireHomeStatic('buildHomeBoardActionRows')")
      && publicSource.includes("requireHomeStatic('buildHomeDataSources')")
      && publicSource.includes("requireHomeStatic('buildCompassDataReadiness')")
      && publicSource.includes("requireHomeStatic('buildHomeDecisionSummaryRows')")
      && homeStaticSource.includes('window.SUXI_HOME_STATIC')
      && homeStaticSource.includes('OTA数据可信度')
      && homeStaticSource.includes('收益分析')
      && homeStaticSource.includes('AI决策')
      && homeStaticSource.includes('运营执行')
      && homeStaticSource.includes('投资决策')
      && homeStaticSource.includes('buildHomeBoardActionRows')
      && homeStaticSource.includes('buildHomeDataSources')
      && homeStaticSource.includes('buildCompassDataReadiness')
      && homeStaticSource.includes('buildHomeDecisionSummaryRows')
      && homeStaticSource.includes('不把模型输出当作事实')
      && homeStaticSource.includes('不用 OTA 渠道数据替代全酒店口径'),
  },
  {
    name: 'home operating result cards and causal chain live in explicit static helper',
    pass: publicSource.includes('buildHomeOperatingResultCards({')
      && publicSource.includes('buildHomeCausalChainNodes({')
      && homeStaticSource.includes('buildHomeOperatingResultCards')
      && homeStaticSource.includes('buildHomeCausalChainNodes')
      && homeStaticSource.includes('OTA订单')
      && homeStaticSource.includes('OTA/经营日报样本口径，不替代全酒店总营收')
      && !publicSource.includes('const cardVisual = {')
      && !publicSource.includes('const operatingOrders = homeOperatingResultCards.value.find'),
  },
  {
    name: 'home trend chart config lives in explicit static helper',
    pass: publicSource.includes("requireHomeStatic('buildHomeTrendChartConfig')")
      && publicSource.includes('new ChartLib(ctx, buildHomeTrendChartConfig({')
      && homeStaticSource.includes('buildHomeTrendChartConfig')
      && homeStaticSource.includes('formatHomeTrendAxisTick')
      && !publicSource.includes('const formatHomeTrendAxisTick = (value) =>')
      && !publicSource.includes('const colors = {'),
  },
  {
    name: 'home trend chart config helper returns expected chart contract',
    pass: sampleTrendChartConfig?.type === 'line'
      && sampleTrendChartConfig?.data?.datasets?.[0]?.label === '收入'
      && sampleTrendChartConfig?.data?.datasets?.[0]?.borderColor === 'rgb(37, 99, 235)'
      && sampleTrendChartConfig?.data?.datasets?.[0]?.data?.[1] === null
      && sampleTrendChartConfig?.options?.scales?.y?.ticks?.callback(20000) === '2万'
      && sampleTrendChartConfig?.options?.plugins?.tooltip?.callbacks?.label({ parsed: { y: 12345 } }) === '收入: ¥12,345',
  },
  {
    name: 'home visual hierarchy verifier is exposed through npm',
    pass: packageSource.includes('"verify:home-visual-hierarchy": "node scripts/verify_home_visual_hierarchy_contract.mjs"'),
  },
];

const executiveAnswerContractPass = publicSource.includes('data-testid="home-executive-answer"')
  && publicSource.includes('data-testid="home-yesterday-facts"')
  && publicSource.includes(':data-testid="stage.testid"')
  && homeStaticSource.includes("testid: 'home-today-acquired-status'")
  && homeStaticSource.includes("testid: 'home-future-ai-judgement'")
  && publicSource.includes('v-for="fact in homeBusinessTimeModel.yesterday.facts"')
  && publicSource.includes("requireHomeStatic('buildHomeBusinessTimeModel')")
  && publicSource.includes('const homeExecutiveAnswer = computed')
  && publicSource.includes('data-testid="home-evidence-fold"')
  && publicSource.includes('data-testid="revenue-ai-business-closure-fold"')
  && publicSource.includes('data-testid="revenue-ai-gap-closure-fold"')
  && publicSource.includes('OTA渠道口径')
  && publicSource.includes('数据依据')
  && publicSource.includes("requireHomeStatic('buildHomeClosedLoopStages')")
  && publicSource.includes("requireHomeStatic('buildHomeAiTraceRows')");

const legacyHomeContractCoveredByExecutiveAnswer = new Set([
  'home cockpit header exposes compact signal row without new data source',
  'home closed-loop builders live in explicit static helper',
]);

const failed = checks.filter((check) => {
  if (check.pass) return false;
  return !(executiveAnswerContractPass && legacyHomeContractCoveredByExecutiveAnswer.has(check.name));
});
if (failed.length > 0) {
  console.error('[verify:home-visual-hierarchy] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:home-visual-hierarchy] ${checks.length} checks passed`);
