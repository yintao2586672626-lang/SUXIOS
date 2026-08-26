import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');

const service = read('app/service/OperatingMemoryService.php');
const controller = read('app/controller/OperationManagement.php');
const routes = readRouteContractSource(process.cwd());
const migration = read('database/migrations/20260802_create_hotel_operating_memories.sql');
const frontend = read('public/app-main.js');
const fragment = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const snapshot = read('resources/frontend/app-template.html');
const charter = read('docs/product_collaboration_charter.md');
const design = read('docs/system_design_logic.md');

test('stage one keeps the product mainline and defines five memory layers with graded use', () => {
  assert.match(charter, /真实携程\/美团 OTA 数据 -> 收益分析 -> 运营管理/);
  for (const marker of ['事实记忆', '分析记忆', '决策记忆', '执行与复盘记忆', 'SOP记忆']) {
    assert.ok(charter.includes(marker), `charter missing ${marker}`);
  }
  for (const marker of ['仅归档', '参考', '决策支持', 'SOP模板']) {
    assert.ok(charter.includes(marker), `charter missing usage level ${marker}`);
  }
  assert.match(design, /经营记忆的保存、检索和使用是三个独立动作/);
  assert.match(design, /经营记忆本身不写 OTA、不外发消息/);
});

test('migration is tenant-hotel scoped, versioned and explicitly reversible', () => {
  for (const marker of [
    '`tenant_id`', '`hotel_id`', '`memory_layer`', '`quality_status`', '`usage_level`',
    '`evidence_refs_json`', '`content_digest`', '`previous_memory_id`',
    'uniq_operating_memory_identity', 'DROP TABLE IF EXISTS `hotel_operating_memories`',
  ]) {
    assert.ok(migration.includes(marker), `migration missing ${marker}`);
  }
});

test('backend exposes only local memory save, list and exact readback routes', () => {
  assert.match(routes, /execution-tasks\/:id\/operating-memory/);
  assert.match(routes, /operating-memories\/:id/);
  assert.match(routes, /operating-memories'/);
  assert.match(controller, /createFromExecutionTask/);
  assert.match(service, /persistence_status' => 'readback_verified'/);
  assert.match(service, /'ota_write' => false/);
  assert.match(service, /'external_message' => false/);
  assert.match(service, /source_record_type' => 'operation_execution_task'/);
});

test('existing operation page saves then performs a second exact readback and shows failures', () => {
  const start = frontend.indexOf('const saveOperationExecutionMemory = async');
  const end = frontend.indexOf('let operationActionsRequestSeq = 0;', start);
  assert.ok(start > 0 && end > start, 'memory save function slice missing');
  const saveSlice = frontend.slice(start, end);
  assert.match(saveSlice, /execution-tasks\/\$\{taskId\}\/operating-memory/);
  assert.match(saveSlice, /operating-memories\/\$\{memoryId\}/);
  assert.match(saveSlice, /source_record_id/);
  assert.match(saveSlice, /content_digest/);
  assert.doesNotMatch(saveSlice, /wecom|manual-notification|auto-fetch|price-write/i);

  for (const marker of [
    'data-testid="operating-memory-panel"',
    'data-testid="save-operating-memory"',
    '不触发OTA/外发',
  ]) {
    assert.ok(fragment.includes(marker), `operation page missing ${marker}`);
    assert.ok(snapshot.includes(marker), `frontend snapshot missing ${marker}`);
  }
  assert.ok(frontend.includes('operating-memory-readback-error'));
  assert.ok(frontend.includes('operating-memory-data-gap'));
});
