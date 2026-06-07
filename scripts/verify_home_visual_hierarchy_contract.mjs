import { readFileSync } from 'node:fs';

const publicSource = readFileSync('public/index.html', 'utf8');
const packageSource = readFileSync('package.json', 'utf8');

const checks = [
  {
    name: 'home first screen exposes a compact decision strip',
    pass: publicSource.includes('data-testid="home-decision-strip"')
      && publicSource.includes('v-for="row in homeDecisionSummaryRows"')
      && publicSource.includes('openHomeQuickEntry(row.entry)'),
  },
  {
    name: 'decision strip reuses current dashboard state instead of new fallback data',
    pass: publicSource.includes('const homeDecisionSummaryRows = computed')
      && publicSource.includes('const readiness = compassDataReadiness.value || {}')
      && publicSource.includes('const trendReady = !!homeTrendHasSamples.value')
      && publicSource.includes('const competitorReadiness = homeCompetitorReadiness.value || {}')
      && publicSource.includes('const action = homeBoardActionRows.value[0] || {}'),
  },
  {
    name: 'decision strip covers data readiness, trend samples, competitor trust and next action',
    pass: ['data-readiness', 'trend-sample', 'competitor', 'next-action'].every((key) => publicSource.includes(`key: '${key}'`)),
  },
  {
    name: 'competitor summary keeps VIP no-inference wording on the home decision strip',
    pass: publicSource.includes("note: homeCompetitorPlatformTagText.value || homeCompetitorSourceNotice.value || '不推断VIP'")
      && publicSource.includes("entry: { page: 'meituan-ebooking', tab: 'meituan-ranking' }"),
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
