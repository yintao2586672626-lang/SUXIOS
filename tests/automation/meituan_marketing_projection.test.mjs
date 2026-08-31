import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');
const service = read('app/service/MeituanMarketingFactProjectionService.php');
const controller = read('app/controller/OtaStandard.php');
const appMain = read('public/app-main.js');
const page = read('resources/frontend/templates/fragments/19a-page-operation-optimizer.html');

test('operation optimizer exposes the strict Meituan marketing projection without auto action', () => {
  assert.match(controller, /MeituanMarketingFactProjectionService/);
  assert.match(controller, /\$workbench\['meituan_marketing'\]/);
  assert.match(controller, /Db::name\('hotels'\).*value\('tenant_id'\)/);
  assert.match(controller, /\(string\)\(\$filters\['end_date'\] \?\? ''\)/);
  assert.match(service, /history_status.*success/s);
  assert.match(service, /validation_status.*verified/s);
  assert.match(service, /readback_verified.*1/s);
  assert.match(service, /attributed_order_amount \/ spend/);
  assert.match(service, /'system_recommendation' => null/);
  assert.match(service, /'operation_intent_created' => false/);
  assert.match(service, /'auto_budget_change_allowed' => false/);
  assert.match(service, /'auto_bid_change_allowed' => false/);
  assert.match(appMain, /美团搜索词与广告严格事实/);
  assert.match(appMain, /manual_marketing_review/);
  assert.match(appMain, /待人工复核（未建任务）/);
  assert.match(page, /module\.key === 'room' \? operationOptimizerRoomMetrics\(row\) : operationOptimizerKeywordMetrics\(row\)/);
});

test('marketing facts remain one-day channel observations rather than whole-hotel claims', () => {
  assert.match(service, /'platform' => 'meituan'/);
  assert.match(service, /'business_date' => \$businessDate/);
  assert.match(service, /'metric_scope' => 'ota_channel'/);
  assert.match(service, /'decision_eligible' => false/);
  assert.match(service, /'causality_claimed' => false/);
  assert.match(service, /'external_write_count' => 0/);
});
