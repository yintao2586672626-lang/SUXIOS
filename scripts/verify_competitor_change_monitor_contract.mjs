import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

const operationSource = read('app/service/OperationManagementService.php');
const packageSource = read('package.json');

const checks = [
  {
    name: 'Meituan summary exposes previous batch and change-monitor fields',
    pass: operationSource.includes("'previous_data_date'")
      && operationSource.includes("'previous_fetched_at'")
      && operationSource.includes("'change_monitor_status'")
      && operationSource.includes("'change_missing_reason'")
      && operationSource.includes("'change_alerts'"),
  },
  {
    name: 'Meituan change monitor compares one current batch with one previous comparable batch',
    pass: operationSource.includes('private function previousMeituanRankBatchRows')
      && operationSource.includes('$latestDataDate')
      && operationSource.includes('$latestFetchedAt')
      && operationSource.includes('private function summarizeMeituanRankBatchSnapshot')
      && operationSource.includes('private function summarizeMeituanRankBatchChanges'),
  },
  {
    name: 'Meituan change signals cover TOP1, self rank, VIP count and platform tag return status',
    pass: operationSource.includes("'type' => 'top1_changed'")
      && operationSource.includes("'type' => 'self_rank_changed'")
      && operationSource.includes("'type' => 'vip_count_changed'")
      && operationSource.includes("'type' => 'platform_tag_status_changed'"),
  },
  {
    name: 'Meituan missing rank/tag evidence stays explicit and does not infer VIP',
    pass: operationSource.includes('No comparable previous Meituan ranking batch found.')
      && operationSource.includes('TOP1 rank fields are not comparable.')
      && operationSource.includes('Self rank fields are not comparable.')
      && operationSource.includes('VIP/platform tag fields are not comparable; no VIP inference is made.')
      && operationSource.includes('missing tags do not imply non-VIP')
      && operationSource.includes('Platform hotel tags and ranking aggregates only; excludes guest privacy, order phone, room status and room-source mapping.'),
  },
  {
    name: 'Operation alerts are generated from Meituan change signals without storing sensitive source data',
    pass: operationSource.includes('private function meituanCompetitorChangeRuleAlerts')
      && operationSource.includes("'meituan_competitor_' . $signalType")
      && operationSource.includes("'change_signal_type' => $signalType")
      && operationSource.includes('Review Meituan TOP1, self rank, VIP/platform tags and batch evidence; keep missing fields explicit and do not infer VIP.')
      && !operationSource.includes("'guest_phone'")
      && !operationSource.includes("'order_phone'"),
  },
  {
    name: 'npm script exposes competitor change monitor verifier',
    pass: packageSource.includes('"verify:competitor-change-monitor": "node scripts/verify_competitor_change_monitor_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:competitor-change-monitor] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:competitor-change-monitor] ${checks.length} checks passed`);
