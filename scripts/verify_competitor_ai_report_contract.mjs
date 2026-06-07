import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

const operationSource = read('app/service/OperationManagementService.php');
const aiReportSource = read('app/service/AiDailyReportService.php');
const publicSource = read('public/index.html');
const packageSource = read('package.json');

const checks = [
  {
    name: 'operation snapshot exposes Meituan competitor summary for AI daily reports',
    pass: /'meituan_rank_summary'\s*=>\s*\$this->buildMeituanRankSummary\(\$hotelIds,\s*\$date\)/.test(operationSource)
      && operationSource.includes('private function buildMeituanRankSummary')
      && operationSource.includes('private function resolveMeituanTargetPoiId')
      && operationSource.includes("$base['source_ref'] = 'online_daily_data.raw_data.platformTags/platformTagStatus/rank'"),
  },
  {
    name: 'Meituan competitor summary keeps missing rank, gap, trend and VIP evidence explicit',
    pass: operationSource.includes("'rank_missing_reason'")
      && operationSource.includes("'rank_gap_metric_status' => 'missing'")
      && operationSource.includes("'rank_trend_status' => 'missing'")
      && operationSource.includes("'platform_tag_text' => '平台标签未返回，不推断VIP'")
      && operationSource.includes('Platform hotel tags and ranking aggregates only; excludes guest privacy, order phone, room status and room-source mapping.'),
  },
  {
    name: 'AI daily report consumes TOP1, self position, gap, VIP and ranking trend',
    pass: aiReportSource.includes("'label' => 'Meituan competitor summary'")
      && aiReportSource.includes("'top_hotel' => (string)($meituan['top_hotel_name'] ?? '')")
      && aiReportSource.includes("'self_position' => (string)($meituan['self_position_text'] ?? '')")
      && aiReportSource.includes("'gap_to_previous' => (string)($meituan['gap_to_previous_text'] ?? '')")
      && aiReportSource.includes("'vip_signal' => (string)($meituan['platform_tag_text'] ?? '')")
      && aiReportSource.includes("'rank_trend' => (string)($meituan['rank_trend_text'] ?? '')"),
  },
  {
    name: 'AI daily report turns competitor evidence into guarded actions',
    pass: aiReportSource.includes('private function buildMeituanCompetitorRecommendedAction')
      && aiReportSource.includes("'source_refs' => ['operation.full_data.competitors.meituan_rank_summary']")
      && aiReportSource.includes('Competitor evidence repair must be completed before creating an OTA execution order.')
      && aiReportSource.includes('Review TOP1, self position, gap, VIP/platform tags and rank trend'),
  },
  {
    name: 'frontend renders Meituan competitor summary fields in AI daily report',
    pass: publicSource.includes('item.top_hotel || item.self_position || item.vip_signal')
      && publicSource.includes('TOP1 {{ item.top_hotel')
      && publicSource.includes('item.gap_to_previous || item.top1_gap')
      && publicSource.includes("item.vip_signal || 'VIP标签未返回，不推断VIP'"),
  },
  {
    name: 'npm script exposes competitor AI report verifier',
    pass: packageSource.includes('"verify:competitor-ai-report": "node scripts/verify_competitor_ai_report_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:competitor-ai-report] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:competitor-ai-report] ${checks.length} checks passed`);
