import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

test('public entry references the current dual-OTA asset content hash', () => {
  const asset = readFileSync('public/dual-ota-home-static.js');
  const hash = createHash('sha256').update(asset).digest('hex').slice(0, 10);
  const index = read('public/index.html');

  assert.match(
    index,
    new RegExp(`dual-ota-home-static\\.js\\?v=[^"']*h${hash}["']`),
    'dual-ota-home-static.js query version must include its current content hash',
  );
});

test('correction-ledger routes keep permission, hotel scope, and restore readback contracts', () => {
  const routes = read('route/app.php');
  const controller = read('app/controller/concern/OnlineDataRecordConcern.php');
  const service = read('app/service/OnlineDataCorrectionLedgerService.php');

  assert.match(routes, /get\('\/?correction-ledger', 'OnlineData\/correctionLedger'\)/);
  assert.match(routes, /post\('\/?restore-data', 'OnlineData\/restoreData'\)/);
  assert.match(controller, /function correctionLedger\(\): Response[\s\S]*checkActionPermission\('can_delete_online_data'\)/);
  assert.match(controller, /function restoreData\(\): Response[\s\S]*checkActionPermission\('can_delete_online_data'\)/);
  assert.match(controller, /permittedHotelIdsForAction\('can_delete_online_data'\)/);
  assert.match(controller, /\$row\['can_restore'\]/);
  assert.match(service, /snapshotMatches\(\$before, \$restored\)/);
  assert.match(service, /online_data_restore_readback_mismatch/);
  assert.match(service, /\$ledgerId = \(int\)Db::name\(self::LEDGER_TABLE\)->insertGetId\(/);
});

test('data-record page exposes a permission-gated ledger with truthful states', () => {
  const frontend = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/35-page-online-data.html');

  assert.match(frontend, /user\.value\?\.is_super_admin === true \|\| userHasPermission\('can_delete_online_data'\)/);
  assert.match(frontend, /request\(`\/online-data\/correction-ledger\?\$\{params\.toString\(\)\}`\)/);
  assert.match(frontend, /!Array\.isArray\(res\?\.data\?\.list\)/);
  assert.match(frontend, /onlineDataCorrectionLedgerError\.value = String\(error\?\.message/);
  assert.match(template, /v-if="canUseOnlineDataCorrectionLedger"[^>]*data-testid="online-data-correction-ledger-toggle"/);
  assert.match(template, /data-testid="online-data-correction-ledger-panel"/);
  assert.match(template, /不代表 OTA 平台数据已变化；恢复不会触发 OTA 采集或写入 OTA/);
  assert.match(template, /onlineDataCorrectionLedgerError/);
  assert.match(template, /row\.can_restore === true/);
});

test('restore requires the in-app confirmation form and verifies ledger readback before success', () => {
  const frontend = read('public/app-main.js');
  const start = frontend.indexOf('const restoreOnlineDataCorrectionLedger');
  const end = frontend.indexOf('const loadOnlineDataList', start);
  assert.ok(start >= 0 && end > start, 'restore implementation block must exist');
  const restoreBlock = frontend.slice(start, end);

  assert.match(restoreBlock, /openWorkflowFormDialog\(/);
  assert.match(restoreBlock, /confirmationText = `恢复 \$\{ledgerId\}`/);
  assert.doesNotMatch(restoreBlock, /\b(?:prompt|confirm)\s*\(/);
  assert.match(restoreBlock, /request\('\/online-data\/restore-data'/);
  assert.match(restoreBlock, /JSON\.stringify\(\{ ledger_id: ledgerId \}\)/);
  assert.match(restoreBlock, /await loadOnlineDataCorrectionLedger/);
  assert.match(restoreBlock, /readback\.can_restore !== false/);
  assert.match(restoreBlock, /readback\.restored_at/);
  assert.match(restoreBlock, /Number\(readback\.online_data_id \|\| 0\) !== restoredId/);
  assert.ok(
    restoreBlock.indexOf('showToast(`数据记录 #${restoredId} 已恢复') > restoreBlock.indexOf('readback.can_restore !== false'),
    'success toast must be emitted only after readback checks',
  );
});
