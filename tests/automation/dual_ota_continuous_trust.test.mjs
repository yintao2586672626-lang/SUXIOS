import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relative => readFileSync(path.join(repoRoot, relative), 'utf8');
const service = read('app/service/DualOtaContinuousTrustService.php');
const controller = read('app/controller/concern/CollectionReliabilityConcern.php');
const command = read('app/command/AutoFetchOnlineData.php');
const cloud = read('app/service/CloudAutomationService.php');
const app = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/35-page-online-data.html');

test('continuous trust contract requires the exact eight-step dual-OTA loop', () => {
  for (const step of ['source', 'hotel', 'date', 'field_facts', 'save', 'readback', 'page_status', 'p0']) {
    assert.match(service, new RegExp(`'${step}'`));
  }
  assert.match(service, /private const PLATFORMS = \['ctrip', 'meituan'\]/);
  assert.match(service, /\$readbackReady = \$hasReadbackColumn/);
  assert.match(service, /'collection_failed'/);
  assert.match(service, /consecutive_verified_days/);
  assert.match(controller, /'dual_ota_continuous_trust' => \$this->buildDualOtaContinuousTrust/);
});

test('scheduler and cloud report gate reject old or incomplete receipts', () => {
  assert.match(command, /machineReceiptDailyTrustReady/);
  assert.match(command, /cached receipt is incomplete, recollection remains due/);
  assert.match(command, /p0_status/);
  assert.match(cloud, /applyContinuousTrustGate/);
  assert.match(cloud, /dual_ota_collection_failed/);
  assert.match(cloud, /\$health\['can_generate_report'\] = false/);
});

test('page targets the selected date and exposes raw partial or collection_failed state', () => {
  assert.match(app, /params\.append\('end_date', String\(coreOperationsTargetDate\.value \|\| coreOperationsMaxDate\)\)/);
  assert.match(app, /dualOtaContinuousStatusText/);
  assert.match(app, /\['verified', 'partial', 'collection_failed'\]/);
  assert.match(template, /data-testid="dual-ota-continuous-trust"/);
  assert.match(template, /旧数据、空值或数值 0 不替代缺失证据/);

  const start = template.indexOf('data-testid="dual-ota-continuous-trust"');
  const end = template.indexOf('data-testid="core-operations-loop"', start);
  const panel = template.slice(start, end);
  assert.doesNotMatch(panel, /\|\|\s*0\b/);
});
