import { spawnSync } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const output = path.join(root, 'output/long-goal/logs');
await mkdir(output, { recursive: true });
const php = 'C:/xampp/php/php.exe';
const phpTests = [
  'ComposerWorktreeAutoload', 'PhpunitBootstrapIsolation', 'OtaLocalCollectorRecovery',
  'OtaLocalCollectorService', 'OtaLocalCollectorRealImport', 'OtaLocalCollectorEvidenceStore',
  'OtaLocalCollectorPrivacyBoundary', 'LocalCollectorControllerSecurity', 'HotelCollectionPlanService',
  'HotelCollectionRunReceiptService', 'HotelCollectionBindingReceiptService', 'HotelCollectionQualityJudgmentService',
  'CollectionResultContractService', 'StoredOtaHistoryLocator', 'PlatformDataSyncLocalCollectorP0', 'OtaPercentUnitEvidence',
].map(name => `tests/${name}Test.php`);
const nodeTests = ['recovery', 'receipts', 'outbox', 'contract'].map(name => `tests/automation/ota_local_collector_${name}.test.mjs`)
  .concat('tests/automation/hotel_collection_plan_ui.test.mjs');
const steps = [
  ['php-focused', php, ['vendor/bin/phpunit', '--bootstrap', 'tests/bootstrap.php', ...phpTests, '--colors=never']],
  ['node-focused', process.execPath, ['--test', ...nodeTests]],
  ...['sync_frontend_template_snapshot', 'build_frontend_template', 'build_frontend_entry',
    'verify_frontend_template_build', 'verify_frontend_entry_build', 'verify_tailwind_runtime_build',
    'verify_frontend_startup_helpers', 'verify_ota_collection_recovery_ui']
    .map(name => [name, process.execPath, [`scripts/${name}.mjs`]]),
];
const results = [];
for (const [name, command, args] of steps) {
  const result = spawnSync(command, args, { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, windowsHide: true });
  const text = `${result.stdout || ''}${result.stderr || ''}${result.error ? '\n' + result.error.message : ''}`;
  const log = `output/long-goal/logs/${name}.log`;
  await writeFile(path.join(root, log), text);
  results.push({ name, command: [command, ...args], exit_code: result.status, log,
    summary: text.match(/OK \([^\r\n]+\)|ℹ tests \d+/u)?.[0] || (result.status === 0 ? 'passed' : 'failed') });
  console.log(`${name}: ${result.status === 0 ? 'PASS' : 'FAIL'} (${log})`);
  if (result.status !== 0) break;
}
const passed = results.length === steps.length && results.every(result => result.exit_code === 0);
await writeFile(path.join(root, 'output/long-goal/verification.json'), JSON.stringify({
  evidence: 'synthetic_isolated_local', status: passed ? 'passed' : 'failed', results,
  real_ota_accessed: false, shared_8080_accessed: false,
}, null, 2));
if (!passed) process.exitCode = 1;
