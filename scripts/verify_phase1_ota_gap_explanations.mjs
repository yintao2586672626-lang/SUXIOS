import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function packageScript(name, command) {
  let actual = '';
  try {
    actual = JSON.parse(read('package.json')).scripts?.[name] ?? '';
  } catch {
    actual = '';
  }
  check('package.json', `package script ${name}`, actual === command, `${name}: ${command}`);
}

const gapCodes = [
  'available_room_nights_missing',
  'commission_fields_missing',
  'net_revenue_fields_missing',
  'cancellation_fields_missing',
  'competitor_price_fields_missing',
];

includesAll('docs/phase1_ota_gap_explanation_matrix.md', 'P0 gap codes are documented', gapCodes);

includesAll('docs/phase1_ota_gap_explanation_matrix.md', 'limited metrics and remaining usable metrics are explicit', [
  'OCC',
  'RevPAR',
  'Net RevPAR',
  '取消率',
  '竞品价差',
  '渠道净收入',
  '价格竞争力',
  '已采收入',
  '间夜',
  'ADR',
]);

includesAll('docs/phase1_ota_gap_explanation_matrix.md', 'gap display rules prevent fallback masking', [
  'data_gaps',
  'metric_trust',
  'not_calculable_when',
  '不能用 0、空值、默认成功、绿色状态或本地兜底分析掩盖缺口',
  '需补证据',
]);

includesAll('docs/phase1_ota_gap_explanation_matrix.md', 'gap matrix preserves acquisition boundaries', [
  '不改变携程/美团手动获取逻辑',
  '不改变自动获取逻辑',
  '不改变获取字段',
  '不改变 `online_daily_data` 入库结构',
]);

includesAll('app/service/OtaRevenueMetricService.php', 'revenue service exposes P0 gaps and non-calculable rules', [
  ...gapCodes,
  'not_calculable_when',
  "'data_gaps' => $dataGaps",
  "'metric_trust' => $metricTrust",
]);

includesAll('scripts/verify_ota_revenue_metrics_smoke.php', 'revenue smoke requires P0 gaps through data_gaps', [
  'require_gap',
  ...gapCodes,
]);

includesAll('docs/phase1_ota_trusted_loop_goal.md', 'phase-one goal references gap explanation verifier', [
  'npm.cmd run verify:phase1-gap-explanations',
]);

includesAll('docs/phase1_ota_employee_console_acceptance.md', 'employee console references gap explanation matrix', [
  'phase1_ota_gap_explanation_matrix.md',
  'verify:phase1-gap-explanations',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit references gap explanation matrix', [
  'phase1_ota_gap_explanation_matrix.md',
  'verify:phase1-gap-explanations',
]);

packageScript('verify:phase1-gap-explanations', 'node scripts/verify_phase1_ota_gap_explanations.mjs');

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA gap explanation contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-gap-explanations] ${checks.length} checks passed`);
