import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('Ctrip legacy order upload closes through exact readback without revenue overclaim', () => {
  const importer = read('app/service/CtripOrderExportImportService.php');
  const controller = read('app/controller/concern/PlatformDataSourceConcern.php');
  const overview = read('app/service/RevenueAiOverviewService.php');
  const smoke = read('scripts/verify_ctrip_order_import_smoke.php');
  const ctripPage = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const revenuePage = read('resources/frontend/templates/fragments/23c-page-compass-detail.html');

  assert.match(importer, /IOFactory::createReaderForFile\(\$path,\s*\[/);
  assert.match(importer, /IOFactory::READER_XLS/);
  assert.match(importer, /IOFactory::READER_HTML/);
  assert.match(importer, /'biff_xls'/);
  assert.match(importer, /'html_table_xls'/);
  assert.match(importer, /throw new RuntimeException\('携程 XLS 文件内容不受支持或已损坏。', 422\)/);
  assert.doesNotMatch(importer, /\$e->getMessage\(\)/);
  assert.match(importer, /reference_bottom_price_not_confirmed_revenue/);
  assert.match(importer, /'amount'\s*=>\s*null/);
  assert.match(importer, /'bottom_price_sum'\s*=>\s*\$referenceBottomPriceTotal/);
  assert.match(importer, /ctrip_order_aggregate_v1/);
  assert.match(importer, /aggregate_only_no_guest_staff_reservation_notes/);
  assert.match(importer, /ctrip_order_export_25_columns/);
  assert.match(importer, /SAFE_IMPORT_HEADERS/);
  assert.match(importer, /bottom_price_coverage_rate/);
  assert.match(importer, /assertHotelScope/);
  assert.doesNotMatch(importer, /source_files'\s*=>\s*array_keys/);
  assert.doesNotMatch(importer, /\['_source_file'\]/);

  assert.match(controller, /value_level_verified/);
  assert.match(controller, /buildChannelOrderImportPreview\(\$readbackRows/);
  assert.match(controller, /request->file\('files'\)/);
  assert.match(controller, /count\(\$flat\) > 10/);
  assert.match(controller, /set_time_limit\(600\)/);
  assert.match(controller, /matched_to_selected_system_hotel|browser-supplied label must never be trusted/);

  assert.match(overview, /manual_order_imports/);
  assert.match(overview, /where\('readback_verified', 1\)/);
  assert.match(overview, /where\('source', 'ctrip'\)/);
  assert.match(overview, /ctrip_order_aggregate_v1/);
  assert.match(overview, /aggregate_only_no_guest_staff_reservation_notes/);
  assert.match(overview, /reference_bottom_price_not_confirmed_revenue/);
  assert.match(overview, /reference_bottom_price_coverage_rate/);
  assert.match(overview, /local_25_column_layout_and_readback_verified/);
  assert.match(overview, /'source_format'\s*=>\s*\$sourceFormat/);
  assert.match(smoke, /'source_format'\s*=>\s*\$row\['source_format'\]\s*\?\?\s*null/);
  assert.doesNotMatch(smoke, /'source_format'\s*=>\s*\$databaseEvidence\['source_format'\]/);
  assert.match(smoke, /\/api\/online-data\/data-import/);
  assert.match(smoke, /cache\('token_'\s*\.\s*\$token,\s*\[/);
  assert.match(smoke, /'user_id'\s*=>\s*\(int\)\$user->id/);
  assert.match(smoke, /'Authorization: Bearer '\s*\.\s*\$token/);
  assert.match(smoke, /'system_hotel_id'\s*=>\s*\(string\)\$hotelId/);
  assert.match(smoke, /\(int\)\(\$result\['saved_count'\]\s*\?\?\s*0\)\s*!==\s*2/);
  assert.match(smoke, /\(int\)\(\$readback\['readback_count'\]\s*\?\?\s*0\)\s*!==\s*2/);
  assert.match(smoke, /\(string\)\(\$readback\['status'\]\s*\?\?\s*''\)\s*!==\s*'verified'/);
  assert.match(smoke, /\(\$readback\['value_level_verified'\]\s*\?\?\s*false\)\s*!==\s*true/);
  assert.match(smoke, /->where\('sync_task_id',\s*\$taskId\)/);
  assert.match(smoke, /->where\('system_hotel_id',\s*\$hotelId\)/);
  assert.match(smoke, /->where\('data_date',\s*\$businessDate\)/);
  assert.match(smoke, /if\s*\(count\(\$storedRows\)\s*!==\s*2\)/);
  assert.match(smoke, /\/api\/revenue-ai\/overview/);
  assert.match(smoke, /\$verifyRevenueRows\(\$taskFixtureRows,\s*'Revenue AI service readback'\)/);
  assert.match(smoke, /\$verifyRevenueRows\(\$httpTaskFixtureRows,\s*'Revenue AI HTTP readback'\)/);
  assert.match(revenuePage, /data-testid="revenue-ai-ctrip-order-import-readback"/);
  for (const label of ['来源 / 渠道', '业务日期', '有效订单', '含取消总单', '取消单', '房晚', '取消率', '平均提前预订', '参考底价', '底价覆盖率']) {
    assert.ok(revenuePage.includes(label), `missing revenue import column: ${label}`);
  }

  assert.match(ctripPage, /accept="\.xls,\.xlsx,\.csv,\.json"/);
  assert.match(ctripPage, /multiple/);
  assert.match(ctripPage, /先合并去重/);
  assert.match(ctripPage, /正在保存并回读/);
});
