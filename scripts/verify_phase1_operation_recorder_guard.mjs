import fs from 'node:fs';
import path from 'node:path';

const file = 'scripts/record_phase1_ota_operation_execution.php';
const source = fs.readFileSync(path.join(process.cwd(), file), 'utf8');
const checks = [];

function check(label, ok, detail = '') {
  checks.push({ label, ok: Boolean(ok), detail });
}

function includesAll(label, needles) {
  const missing = needles.filter((needle) => !source.includes(needle));
  check(label, missing.length === 0, missing.join(', '));
}

includesAll('recorder is an explicit read-only preview', [
  "'status' => 'preview'",
  "'mode' => 'read_only'",
  "'writes_database' => false",
  "'writes_files' => false",
  "'closure_claim_allowed' => false",
  "'proof_status' => 'not_execution_proof'",
  "'legacy_output_written' => false",
]);

includesAll('execution writes are redirected to the authenticated Agent API', [
  "'direct_operation_write_disabled: use authenticated POST '",
  '/api/agent/ota-diagnoses/:id/actions/:actionIndex/execution-intent',
  'authenticated administrator session',
  'authorized assignee_id with operation.execute permission',
]);

includesAll('existing flow lookup requires saved diagnosis identity', [
  '--diagnosis-id and --action-index must be provided together.',
  "->where('source_module', 'ota_diagnosis_saved')",
  "->where('source_record_id', $diagnosisId)",
  "->where('hotel_id', (int)$options['system-hotel-id'])",
  "->where('platform', (string)$options['platform'])",
  "->where('date_start', (string)$options['date'])",
  'phase1_operation_intent_matches_action(',
  "'exact_identity_verified' => $lookupStatus === 'exact_match'",
]);

includesAll('action identity cannot be weakened by an optional item id', [
  "if ((int)($evidence['action_index'] ?? -1) !== $actionIndex)",
  "if ($actionItemId === '')",
  'hash_equals($actionItemId, $storedActionItemId)',
]);

includesAll('missing operation tables degrade to unknown metadata', [
  "'operation_execution_intents' => phase1_operation_table_exists('operation_execution_intents')",
  "'operation_execution_tasks' => phase1_operation_table_exists('operation_execution_tasks')",
  "'operation_execution_evidence' => phase1_operation_table_exists('operation_execution_evidence')",
  "'intent_table_missing'",
  "&& $operationTables['operation_execution_evidence']",
]);

const executeGuard = source.indexOf("if (($options['execute'] ?? false) === true)");
const appInitialization = source.indexOf('$app = new App();');
check(
  'execute mode is rejected before framework or database initialization',
  executeGuard >= 0 && appInitialization >= 0 && executeGuard < appInitialization,
  `executeGuard=${executeGuard}, appInitialization=${appInitialization}`,
);

const forbiddenCalls = [
  'createExecutionIntent',
  'approveExecutionIntent',
  'executeExecutionTask',
  'addExecutionEvidence',
  'reviewExecutionTask',
  'file_put_contents',
  'mkdir',
  'unlink',
  'rename',
  'copy',
  'touch',
];
for (const call of forbiddenCalls) {
  check(
    `forbidden write call absent: ${call}`,
    !new RegExp(`\\b${call}\\s*\\(`).test(source),
  );
}

const forbiddenDatabaseWrites = [
  /\bDb::execute\s*\(/,
  /->insert\s*\(/,
  /->insertGetId\s*\(/,
  /->insertAll\s*\(/,
  /->update\s*\(/,
  /->delete\s*\(/,
  /->save\s*\(/,
];
check(
  'direct database write APIs are absent',
  forbiddenDatabaseWrites.every((pattern) => !pattern.test(source)),
  forbiddenDatabaseWrites.filter((pattern) => pattern.test(source)).map(String).join(', '),
);

check(
  'writable fopen modes are absent',
  !/\bfopen\s*\([^,]+,\s*['"](?:[waxc]|r\+)/i.test(source),
);

const failed = checks.filter((item) => !item.ok);
console.log(JSON.stringify({
  status: failed.length === 0 ? 'passed' : 'failed',
  file,
  checks: checks.length,
  failures: failed,
}, null, 2));

if (failed.length > 0) {
  process.exit(1);
}
