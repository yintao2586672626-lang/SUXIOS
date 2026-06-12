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

includesAll('docs/phase1_ota_live_closure_evidence.md', 'live closure evidence scope is explicit', [
  'capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution',
  '不启动携程或美团采集',
  'online_daily_data',
  'metric_trust',
  'data_gaps',
  'evidence_sources',
  'action_items',
  'next_actions',
  'execution_intents',
  'execution_flow',
]);

includesAll('docs/phase1_ota_live_closure_evidence.md', 'live closure commands are documented', [
  'npm.cmd run inspect:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure-contract',
  '--strict',
  '--evidence=',
]);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'inspector reads live closure evidence without starting collection', [
  'online_daily_data',
  'vendor_autoload_missing',
  'source_rows_present',
  'OtaStandardEtlService',
  'OtaRevenueMetricService',
  'metric_trust',
  'data_gaps',
  'ai_diagnosis_evidence',
  'operation_execution_sample',
  'missing_requirements',
  'next_action_for_missing_requirement',
  'protected_boundary',
  'JSON_UNESCAPED_UNICODE',
]);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'inspector strict mode fails incomplete evidence only in verify mode', [
  "'strict' => false",
  '$options[\'strict\'] = true',
  "'mode' => $options['strict'] ? 'verify' : 'inspect'",
  '$strictIncomplete',
]);

packageScript('inspect:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php');
packageScript('verify:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php --strict');
packageScript('verify:phase1-live-closure-contract', 'node scripts/verify_phase1_ota_live_closure_contract.mjs');

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit references live closure evidence gate', [
  'verify:phase1-live-closure-contract',
  'inspect:phase1-live-closure',
  '真实当天携程/美团采集结果尚未完成端到端证明',
]);

includesAll('docs/release_functional_acceptance_matrix.md', 'release matrix includes live closure contract guard', [
  'verify:phase1-live-closure-contract',
  'live closure evidence',
]);

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA live closure contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-live-closure-contract] ${checks.length} checks passed`);
