import { readFileSync } from 'node:fs';

const publicSource = readFileSync('public/index.html', 'utf8');
const homeStaticSource = readFileSync('public/home-static.js', 'utf8');
const packageSource = readFileSync('package.json', 'utf8');

const checks = [
  {
    name: 'home first screen exposes a compact decision strip',
    pass: publicSource.includes('data-testid="home-decision-strip"')
      && publicSource.includes('v-for="row in homeDecisionSummaryRows"')
      && publicSource.includes('openHomeQuickEntry(row.entry)'),
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
      && publicSource.includes("requireHomeStatic('buildCompassDataReadiness')")
      && publicSource.includes('buildHomeDecisionSummaryRows({')
      && publicSource.includes('trendReady: homeTrendHasSamples.value')
      && publicSource.includes('const competitorReadiness = homeCompetitorReadiness.value || {}')
      && publicSource.includes('const action = homeBoardActionRows.value[0] || {}'),
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
    name: 'home competitor summary uses dense evidence-first five-card grid',
    pass: publicSource.includes('data-testid="home-competitor-summary"')
      && publicSource.includes('data-testid="home-competitor-card-grid"')
      && publicSource.includes('lg:grid-cols-5')
      && publicSource.includes('{{ card.note || \'-\' }}')
      && publicSource.includes('homeCompetitorSourceNotice'),
  },
  {
    name: 'home action panel renders action rationale instead of only badges',
    pass: publicSource.includes('data-testid="home-action-panel"')
      && publicSource.includes('{{ action.detail || \'-\' }}')
      && publicSource.includes('v-for="action in homeBoardActionRows"'),
  },
  {
    name: 'home closed-loop builders live in explicit static helper',
    pass: publicSource.includes('<script src="home-static.js"></script>')
      && publicSource.includes("requireHomeStatic('buildHomeClosedLoopStages')")
      && publicSource.includes("requireHomeStatic('buildHomeAiTraceRows')")
      && publicSource.includes("requireHomeStatic('buildHomeBoardActionRows')")
      && publicSource.includes("requireHomeStatic('buildCompassDataReadiness')")
      && publicSource.includes("requireHomeStatic('buildHomeDecisionSummaryRows')")
      && homeStaticSource.includes('window.SUXI_HOME_STATIC')
      && homeStaticSource.includes('OTA数据可信度')
      && homeStaticSource.includes('收益分析')
      && homeStaticSource.includes('AI决策')
      && homeStaticSource.includes('运营执行')
      && homeStaticSource.includes('投资决策')
      && homeStaticSource.includes('buildHomeBoardActionRows')
      && homeStaticSource.includes('buildCompassDataReadiness')
      && homeStaticSource.includes('buildHomeDecisionSummaryRows')
      && homeStaticSource.includes('不把模型输出当作事实')
      && homeStaticSource.includes('不用 OTA 渠道数据替代全酒店口径'),
  },
  {
    name: 'home visual hierarchy verifier is exposed through npm',
    pass: packageSource.includes('"verify:home-visual-hierarchy": "node scripts/verify_home_visual_hierarchy_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:home-visual-hierarchy] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:home-visual-hierarchy] ${checks.length} checks passed`);
