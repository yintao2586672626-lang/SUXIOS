import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (relativePath) => readFileSync(path.join(root, relativePath), 'utf8');

test('Luopan is recorded as the third PMS technical source with a fixed provenance', () => {
  const record = source(
    'docs/capability-absorption/2026-08-14-luopan-pms-technical-reserve.md',
  );

  assert.match(
    record,
    /FBADD7B9BC6566ED9913D0CB0A0FAAC5277717E02F6F1475BFBD2616F4637E0E/,
  );
  assert.match(record, /pms_source_catalog_count: 3/);
  assert.match(record, /pms_bindable_provider_count: 2/);
  assert.match(record, /pms_technical_reserve_count: 1/);
  assert.match(record, /\| `luopan_pms` \| 罗盘 PMS \| `technical_reserve`，待真实门店验证 \| 否 \|/);
  assert.match(record, /真实门店验证：`unverified`/);
  assert.match(record, /收益\/AI放行：`blocked_until_same_hotel_business_date_save_readback_verified`/);
});

test('Knowledge Center shows three PMS sources and keeps Luopan visibly unverified', () => {
  const template = source(
    'resources/frontend/templates/fragments/20-page-knowledge-center.html',
  );
  const runtimeView = source('public/components/system/business-closure-views.js');

  assert.match(template, /data-testid="knowledge-feature-pms-source-count" data-pms-source-count="3"/);
  assert.match(template, /PMS 来源 · 3项/);
  assert.equal((template.match(/data-pms-source-item(?:\s|>)/g) || []).length, 3);
  assert.equal((template.match(/data-pms-source-status="integrated"/g) || []).length, 2);
  assert.equal((template.match(/data-pms-source-status="technical-reserve"/g) || []).length, 1);
  assert.match(template, /data-pms-source-code="luopan_pms"/);
  assert.match(template, /技术储备 · 未实店验证/);
  assert.match(template, /技术储备不等于可绑定或已有真实数据/);
  assert.match(runtimeView, /knowledge-feature-pms-source-count/);
  assert.match(runtimeView, /PMS 来源 · 3项/);
  assert.match(runtimeView, /luopan_pms/);
  assert.match(runtimeView, /technical-reserve/);
});

test('Luopan reserve does not become a selectable hotel PMS provider', () => {
  const hotelDialog = source('resources/frontend/templates/fragments/40-dialog-hotel.html');
  const bindingService = source('app/service/HotelPmsBindingService.php');

  assert.doesNotMatch(hotelDialog, /value="luopan_pms"/);
  assert.match(hotelDialog, /value="dingdandao_pms"/);
  assert.match(hotelDialog, /value="meituan_cloud_pms"/);
  assert.doesNotMatch(bindingService, /PROVIDER_LUOPAN|['"]luopan_pms['"]/);
  assert.match(bindingService, /PROVIDER_DINGDANDAO = 'dingdandao_pms'/);
  assert.match(bindingService, /PROVIDER_MEITUAN_CLOUD = 'meituan_cloud_pms'/);
});

test('project and distributable semantic source inventories include the same reserve entry', () => {
  const projectInventory = source(
    '.agents/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md',
  );
  const pluginInventory = source(
    'plugins/suxi-os-toolkit/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md',
  );
  const marker = 'Luopan PMS collector sanitized snapshot v1.0';

  assert.equal((projectInventory.match(new RegExp(marker, 'g')) || []).length, 1);
  assert.equal((pluginInventory.match(new RegExp(marker, 'g')) || []).length, 1);
  assert.match(projectInventory, /Technical reserve only; no automatic PMS action and not a bindable provider/);
  assert.match(pluginInventory, /Technical reserve only; no automatic PMS action and not a bindable provider/);
});
