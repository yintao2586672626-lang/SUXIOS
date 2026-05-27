import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function requireText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

requireText('public/index.html', 'data-testid="login-username"', 'login username has stable selector');
requireText('public/index.html', 'data-testid="login-password"', 'login password has stable selector');
requireText('public/index.html', 'data-testid="login-submit"', 'login submit has stable selector');
requireText('public/index.html', 'data-testid="app-nav"', 'sidebar nav has stable selector');
requireText('public/index.html', 'data-testid="app-main"', 'main app surface has stable selector');
requireText('public/index.html', ':data-current-page="currentPage"', 'main app surface exposes current page state');
requireText('public/index.html', ':data-testid="menuTestId(item)"', 'top-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(child)"', 'second-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(grandChild)"', 'third-level menu uses test id helper');
requireText('public/index.html', ':data-testid="pageTestId(currentPage)"', 'active page container exposes current page test id');
requireText('public/index.html', 'assignPageControlTestIds', 'page controls receive generated stable test ids');
requireText('public/index.html', 'history-strategy-reuse', 'strategy history reuse button has stable selector');
requireText('public/index.html', 'history-simulation-reuse', 'simulation history reuse button has stable selector');
requireText('public/index.html', 'history-expansion-reuse', 'expansion history reuse button has stable selector');
requireText('public/index.html', 'history-transfer-reuse', 'transfer history reuse button has stable selector');
requireText('public/index.html', 'field-strategy-city', 'strategy city field has stable selector');
requireText('public/index.html', 'field-simulation-adr', 'simulation ADR field has stable selector');
requireText('public/index.html', 'field-market-business-area', 'market business area field has stable selector');
requireText('public/index.html', 'field-transfer-pricing-', 'transfer pricing fields have stable selectors');

requireText('tests/automation/e2e-helpers.js', 'function modulePath', 'helpers expose module path mapping');
requireText('tests/automation/e2e-helpers.js', 'function testIdForModule', 'helpers expose module test id selector');
requireText('tests/automation/e2e-helpers.js', 'function semanticInputValue', 'helpers generate field-semantic input values');
requireText('tests/automation/e2e-helpers.js', 'async function waitForApiOrState', 'helpers wait by API response or state assertion');
requireText('tests/automation/e2e-helpers.js', 'function classifyApiStatus', 'helpers classify API status by failure type');
requireText('tests/automation/e2e-helpers.js', "status === 400 || status === 422", 'helpers classify validation failures as invalid test data');

requireText('tests/automation/full-click-coverage.spec.js', 'backupDatabase', 'full click test backs up database before mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'restoreDatabase', 'full click test can restore database after mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'semanticInputValue', 'full click test uses semantic input generator');
requireText('tests/automation/full-click-coverage.spec.js', "category: 'safe-skip'", 'full click report classifies safe skips');
requireText('tests/automation/full-click-coverage.spec.js', "'test-data-invalid'", 'full click report classifies invalid test data');
requireText('tests/automation/full-click-coverage.spec.js', "'product-bug'", 'full click report classifies product bugs');
requireText('tests/automation/full-click-coverage.spec.js', 'summary.json', 'full click test writes classified summary');
requireText('tests/automation/full-click-coverage.spec.js', 'MIN_KEY_FUNCTION_LOOPS = 50', 'full click key-function validation starts at 50 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'MAX_KEY_FUNCTION_LOOPS = 100', 'full click key-function validation caps at 100 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'parseKeyFunctionLoopCount', 'full click clamps key-function loop count');
requireNoText('tests/automation/full-click-coverage.spec.js', 'waitForTimeout', 'full click test avoids fixed sleeps');

requireText('tests/automation/edge-input-guard.spec.js', 'edgeCasesForField', 'edge input guard generates boundary input cases');
requireText('tests/automation/edge-input-guard.spec.js', 'installEdgeApiMocks', 'edge input guard mocks mutating APIs by default');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_EDGE_LIVE_API', 'edge input guard can opt into live API mode');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_USERNAME', 'edge input guard uses E2E username override');
requireText('tests/automation/edge-input-guard.spec.js', 'mocked-response', 'edge input guard records mocked validation responses');
requireText('tests/automation/edge-input-guard.spec.js', 'classifyConsoleEvent', 'edge input guard classifies expected console validation errors');
requireText('tests/automation/edge-input-guard.spec.js', "row.category === 'page-error'", 'edge input guard fails on real page-error diagnostics');
requireText('tests/automation/edge-input-guard.spec.js', 'script-like-text', 'edge input guard covers script-like text safely as field input');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.USERNAME', 'edge input guard avoids OS username environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.PASSWORD', 'edge input guard avoids generic password environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'waitForTimeout', 'edge input guard avoids fixed sleeps');

requireText('tests/automation/module-smoke.spec.js', 'goModule', 'module smoke reuses stable module navigation helper');
requireText('tests/automation/module-smoke.spec.js', 'semanticInputValue', 'module smoke uses semantic input generator');
requireText('tests/automation/module-smoke.spec.js', 'waitForApiOrState', 'module smoke waits by API response or state assertion');
requireText('tests/automation/module-smoke.spec.js', 'category: classifyError(error)', 'module smoke classifies failures in report');
requireNoText('tests/automation/module-smoke.spec.js', 'waitForTimeout', 'module smoke avoids fixed sleeps');
requireNoText('tests/automation/module-smoke.spec.js', 'getByText', 'module smoke avoids text-only navigation selectors');

requireText('tests/automation/async-page-guard.spec.js', 'installHistoryFixtures', 'async guard uses deterministic history fixtures');
requireText('tests/automation/async-page-guard.spec.js', 'waitForResponse', 'async guard waits for delayed detail response');
requireNoText('tests/automation/async-page-guard.spec.js', 'waitForTimeout', 'async guard avoids fixed sleeps');

requireText('tests/automation/business-chains.spec.js', 'business chain: OTA import to revenue', 'business chain covers OTA to operation');
requireText('tests/automation/business-chains.spec.js', 'business chain: market evaluation to transfer', 'business chain covers market to transfer');
requireText('tests/automation/business-chains.spec.js', 'business chain: strategy, quant simulation, feasibility', 'business chain covers investment decision');
requireText('tests/automation/business-chains.spec.js', '/api/online-data/save-daily-data', 'business chain imports OTA data through API');
requireText('tests/automation/business-chains.spec.js', '/api/operation/action-tracking', 'business chain asserts operation action tracking');
requireText('tests/automation/business-chains.spec.js', '/api/transfer/dashboard', 'business chain asserts transfer dashboard reads upstream results');
requireText('tests/automation/business-chains.spec.js', '/api/agent/feasibility-report/generate', 'business chain asserts feasibility report persistence');
requireText('tests/automation/business-chains.spec.js', 'E2E_API_REQUEST_TIMEOUT_MS', 'business chain API client has configurable timeout');
requireText('tests/automation/business-chains.spec.js', 'timeout: apiRequestTimeout', 'business chain applies API timeout to auth and business calls');
requireText('tests/automation/business-chains.spec.js', "'test-data-invalid'", 'business chain classifies invalid test data');
requireText('tests/automation/business-chains.spec.js', "'product-bug'", 'business chain classifies product bugs');
requireNoText('tests/automation/business-chains.spec.js', 'waitForTimeout', 'business chain avoids fixed sleeps');

requireText('tests/automation/README.md', 'test:e2e:business', 'README documents business-chain test command');
requireText('tests/automation/README.md', 'test:e2e:edge', 'README documents edge input guard command');
requireText('tests/automation/README.md', 'E2E_EDGE_LIVE_API=0', 'README documents edge test safe mocked API mode');
requireText('tests/automation/README.md', '`product-bug`', 'README documents product bug category');
requireText('tests/automation/README.md', '`test-data-invalid`', 'README documents invalid test data category');

requireText('package.json', 'test:e2e:quick', 'package exposes quick CI e2e command');
requireText('package.json', 'test:e2e:business', 'package exposes business chain e2e command');
requireText('package.json', 'test:e2e:edge', 'package exposes edge input e2e command');
requireText('package.json', 'test:e2e:ui', 'package exposes UI automation e2e command');

const failures = checks.filter((check) => !check.ok);
if (failures.length) {
  console.error('E2E contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`E2E contract verification passed (${checks.length} checks).`);
