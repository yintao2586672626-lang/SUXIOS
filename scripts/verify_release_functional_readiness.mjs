import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const passes = [];

function readText(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function fail(message) {
  failures.push(message);
}

function pass(message) {
  passes.push(message);
}

function requireFile(relativePath) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    fail(`${relativePath} is missing`);
    return false;
  }
  pass(`${relativePath} exists`);
  return true;
}

function requireText(relativePath, needle, label) {
  if (!requireFile(relativePath)) return;
  const text = readText(relativePath);
  if (!text.includes(needle)) {
    fail(`${relativePath} missing ${label}: ${needle}`);
    return;
  }
  pass(`${relativePath} covers ${label}`);
}

function requirePackageScript(scriptName) {
  const packageJson = JSON.parse(readText('package.json'));
  if (typeof packageJson.scripts?.[scriptName] !== 'string') {
    fail(`package.json scripts is missing ${scriptName}`);
    return;
  }
  pass(`package.json exposes ${scriptName}`);
}

function requireWorkflowCommand(command) {
  const workflow = readText('.github/workflows/php.yml');
  if (!workflow.includes(command)) {
    fail(`.github/workflows/php.yml must run ${command}`);
    return;
  }
  pass(`workflow runs ${command}`);
}

for (const doc of [
  'docs/release_functional_acceptance_matrix.md',
  'docs/release_verification_command_matrix.md',
  'docs/five_modules_business_loop_field_inventory.md',
  'docs/p0_decision_execution_closed_loop.md',
  'docs/revenue_metric_standard_fact_table.md',
  'docs/system_design_logic.md',
  'docs/system_beauty_ai_stability_review.md',
  'docs/ui-handoff/README.md',
]) {
  requireFile(doc);
}

for (const scriptName of [
  'review:functional-readiness',
  'verify:e2e-contracts',
  'verify:ota-data-batch',
  'verify:transfer-p2',
  'verify:opening-batch-actions',
  'test:e2e:business',
  'test:e2e:quick',
]) {
  requirePackageScript(scriptName);
}

requireWorkflowCommand('npm run review:functional-readiness');
requireWorkflowCommand('npm run verify:release-status');

const routeChecks = [
  ["Route::post('/save-daily-data', 'OnlineData/saveDailyData')", 'OTA daily save route'],
  ["Route::get('/daily-data-list', 'OnlineData/dailyDataList')", 'OTA daily list route'],
  ["Route::get('/data-analysis', 'OnlineData/dataAnalysis')", 'revenue analysis route'],
  ["Route::post('/simulate', 'StrategySimulation/simulate')", 'strategy simulation route'],
  ["Route::post('/calculate', 'Simulation/calculate')", 'quant simulation route'],
  ["Route::post('/market-evaluation', 'Expansion/marketEvaluation')", 'market evaluation route'],
  ["Route::post('/pricing', 'TransferDecision/pricing')", 'transfer pricing route'],
  ["Route::post('/dashboard', 'TransferDecision/dashboard')", 'transfer dashboard route'],
  ["Route::post('/feasibility-report/generate', 'Agent/feasibilityReportGenerate')", 'feasibility report route'],
  ["Route::post('/execution-intents', 'OperationManagement/createExecutionIntent')", 'operation execution intent route'],
  ["Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence')", 'operation evidence route'],
  ["Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask')", 'operation review route'],
  ["Route::get('/execution-flow', 'OperationManagement/executionFlow')", 'operation execution flow route'],
];
for (const [needle, label] of routeChecks) {
  requireText('route/app.php', needle, label);
}

const codeChecks = [
  ['app/controller/OnlineData.php', "'ota_channel_supplement' =>", 'OTA supplement summary output'],
  ['app/controller/OnlineData.php', "'scope' => 'ota_channel'", 'OTA channel scope marker'],
  ['scripts/lib/ota_data_validator.mjs', 'validateMetricFormulas', 'OTA metric formula validator'],
  ['app/service/LlmClient.php', 'decision_impact', 'AI decision impact governance'],
  ['app/service/LlmClient.php', 'human_confirmation_required', 'AI human confirmation governance'],
  ['app/service/LlmClient.php', 'sendWithRetry', 'AI retry wrapper'],
  ['app/service/LlmClient.php', 'retryReason', 'AI retry classification'],
  ['app/service/LlmClient.php', 'retryDelayMs', 'AI retry backoff'],
  ['app/model/AiModelConfig.php', 'ai_model_configs', 'AI model config model'],
  ['database/migrations/20260527_create_ai_governance_tables.sql', 'ai_model_call_logs', 'AI call log table'],
  ['app/service/OperationManagementService.php', 'buildExecutionIntentPayload', 'operation intent builder'],
  ['app/service/OperationManagementService.php', 'buildExecutionTaskUpdate', 'operation task update builder'],
  ['app/service/OperationManagementService.php', 'buildExecutionFlowSummary', 'operation execution summary'],
  ['database/migrations/20260526_create_operation_execution_loop_tables.sql', 'operation_execution_intents', 'operation intent table'],
  ['database/migrations/20260526_create_operation_execution_loop_tables.sql', 'operation_execution_evidence', 'operation evidence table'],
  ['public/index.html', 'operationExecutionFlow', 'operation execution UI'],
  ['app/service/ExpansionService.php', 'saveRecord', 'expansion persistence'],
  ['app/service/TransferDecisionService.php', 'saveRecord', 'transfer persistence'],
  ['app/service/QuantSimulationService.php', 'quant_simulation_records', 'quant simulation persistence'],
  ['app/service/FeasibilityReportService.php', 'feasibility_reports', 'feasibility report persistence'],
];
for (const [file, needle, label] of codeChecks) {
  requireText(file, needle, label);
}

const testChecks = [
  ['tests/automation/business-chains.spec.js', 'business chain: OTA import to revenue', 'OTA to operation E2E chain'],
  ['tests/automation/business-chains.spec.js', 'business chain: market evaluation to transfer', 'market to transfer E2E chain'],
  ['tests/automation/business-chains.spec.js', 'business chain: strategy, quant simulation, feasibility', 'investment E2E chain'],
  ['tests/OperationExecutionLoopTest.php', 'testExecutedTaskWithoutEvidenceIsBlocked', 'execution evidence cannot be skipped'],
  ['tests/OperationExecutionLoopTest.php', 'testExecutionFlowSummaryExposesMoneyAndConversionRates', 'execution ROI summary'],
  ['tests/TransferDecisionServiceTest.php', 'TransferDecisionService', 'transfer decision service tests'],
  ['tests/QuantSimulationServiceTest.php', 'QuantSimulationService', 'quant simulation service tests'],
  ['tests/ExpansionServiceTest.php', 'ExpansionService', 'expansion service tests'],
  ['tests/LlmClientTest.php', 'testRetryPolicyTargetsTransientNetworkAndHttpFailures', 'LLM retry classification test'],
  ['tests/LlmClientTest.php', 'testDebugIncludesRetryMetadataWithoutMaskingFailure', 'LLM retry metadata test'],
];
for (const [file, needle, label] of testChecks) {
  requireText(file, needle, label);
}

const matrix = readText('docs/release_functional_acceptance_matrix.md');
for (const phrase of [
  'OTA channel data',
  'Revenue analysis',
  'AI decision',
  'Operations management',
  'Investment decision',
  '@github',
  '@openai-developers',
  '@codex-security',
  '@figma',
  '@canva',
  'does not close the external release blockers',
  'npm run test:e2e:business',
]) {
  if (!matrix.includes(phrase)) {
    fail(`docs/release_functional_acceptance_matrix.md must mention ${phrase}`);
  }
}

if (failures.length > 0) {
  console.error('Release functional readiness failed:');
  for (const item of failures) {
    console.error(`- ${item}`);
  }
  process.exit(1);
}

console.log(`Release functional readiness passed (${passes.length} structural checks).`);
