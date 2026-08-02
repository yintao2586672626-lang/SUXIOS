import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('knowledge SOP bridge requires an explicit target and reads the persisted intent back', () => {
  const page = read('resources/frontend/templates/fragments/20-page-knowledge-center.html');
  const dialog = read('resources/frontend/templates/fragments/38-dialogs-knowledge-center.html');
  const app = read('public/app-main.js');

  assert.match(page, /v-model="knowledgeCenterTargetHotelId"/);
  assert.match(page, /运营任务目标门店/);
  assert.match(dialog, /knowledgeSopTaskCreatingChunkId === Number\(chunk\.chunk_id\)/);
  assert.match(dialog, /生成并回读中/);

  assert.match(app, /该SOP适用于多个平台，请先在平台筛选中选择具体平台/);
  assert.match(app, /const persistedIntent = await readOperationExecutionIntent\(intentId\)/);
  assert.match(app, /String\(persistedIntent\.source_module \|\| ''\) !== 'knowledge_sop'/);
  assert.match(app, /Number\(provenance\.target_hotel_id \|\| 0\) !== hotelId/);
  assert.match(app, /任务草稿数据库回读与知识来源、门店或平台不一致/);
});

test('knowledge SOP bridge is idempotent and remains approval-gated', () => {
  const service = readSourceAggregate('app/service/OperationManagementService.php', { repoRoot });
  const controller = read('app/controller/Knowledge.php');
  const route = read('route/app.php');

  assert.match(service, /source_module'\] === 'knowledge_sop'/);
  assert.match(service, /knowledgeSopExecutionIntentIdempotencyKey\(\$payload\)/);
  assert.match(service, /replayTrustedExecutionIntent\(\$idempotencyKey, \$payload, \$hotelIds\)/);
  assert.match(controller, /'status' => 'pending_approval'/);
  assert.match(controller, /'auto_write_ota' => false/);
  assert.match(controller, /KnowledgeSopExecutionProvenanceService/);
  assert.match(route, /chunks\/:chunk_id\/execution-intent/);
});
