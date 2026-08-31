import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('operating finance center is a discoverable lazy-loaded vertical slice', () => {
  const nav = read('public/system-static.js');
  const manifest = read('resources/frontend/templates/manifest.json');
  const fragment = read('resources/frontend/templates/fragments/19c-page-operating-finance.html');
  const components = read('public/components/system/app-main-components.js');
  const compiledComponent = read('public/components/system/operating-finance-control-center.min.js');
  const startupComponentBridge = read('public/components/system/app-main-components-loader.js');
  const app = read('public/app-main.js');
  const routes = read('route/domain/operations.php');

  assert.match(nav, /path: 'operating-finance'[\s\S]*?permissions: \['operation\.view'\]/);
  assert.match(manifest, /"id": "page-operating-finance"/);
  assert.match(fragment, /<operating-finance-control-center/);
  assert.match(components, /OperatingFinanceControlCenterBody/);
  assert.match(components, /operating-finance-control-center\.min\.js/);
  assert.match(compiledComponent, /OperatingFinanceControlCenterBody/);
  assert.match(startupComponentBridge, /'OperatingFinanceControlCenter'/);
  assert.doesNotMatch(compiledComponent, /template:/);
  assert.match(app, /OperatingFinanceControlCenter/);
  assert.match(routes, /api\/operating-finance/);
  assert.match(routes, /Route::group\('api\/operating-finance'[\s\S]*?middleware\(\\app\\middleware\\Auth::class\)/);
  assert.match(routes, /settlements\/import/);
  assert.match(routes, /settlements\/import-file/);
  assert.match(routes, /on-books-snapshots/);
  assert.match(routes, /demand-events/);
  assert.match(routes, /monthly-finance/);
  const controller = read('app/controller/OperatingFinance.php');
  assert.match(controller, /OtaSettlementFileParserService/);
  assert.match(controller, /resolveHotelScope\(.*'operation\.view'/);
  assert.match(controller, /resolveHotelScope\(.*'operation\.execute'/);
  assert.match(controller, /settlement_platform_not_supported/);
  assert.match(controller, /source_quality_status'[\s\S]*?operator_attested[\s\S]*?unverified/);
  assert.match(controller, /source_method'\] = 'manual_entry'[\s\S]*?quality_status'\][\s\S]*?manual_confirmed/);
  assert.match(controller, /source_method'\] = 'manual_reference'[\s\S]*?source_status'\] = 'reference_only'/);
  assert.match(read('public/components/system/operating-finance-control-center.js'), /\.xlsx/);
  const componentBuilder = read('scripts/build_operating_finance_component.mjs');
  assert.match(componentBuilder, /loader_cache_identity_changed/);
  assert.match(componentBuilder, /bridge_cache_identity_changed/);
  assert.match(componentBuilder, /index_cache_identity_changed/);
});

test('net revenue never becomes whole-hotel GOP', () => {
  const service = read('app/service/MonthlyOperatingFinanceService.php');
  const component = read('public/components/system/operating-finance-control-center.js');

  assert.match(service, /gop_not_calculable_from_ota_channel_scope/);
  assert.match(service, /ota_settlement_is_not_whole_hotel_revenue/);
  assert.match(service, /owner_cash_proxy_is_not_accounting_cash_flow/);
  assert.match(service, /tax_capex_financing_and_depreciation_excluded/);
  assert.match(component, /OTA渠道范围/);
  assert.match(component, /业主现金代理[\s\S]*不等于会计现金流/);
});

test('PMS demand planning uses tomorrow, three-day and seven-day decision windows', () => {
  const service = read('app/service/BookingDemandPlanningService.php');
  const controller = read('app/controller/OperatingFinance.php');
  const component = read('public/components/system/operating-finance-control-center.js');

  assert.match(service, /PLAN_CONTRACT = 'booking_demand_plan\.v1'/);
  assert.match(service, /\['tomorrow', '明天', 1\]/);
  assert.match(service, /\['next_3_days', '未来3天', 3\]/);
  assert.match(service, /\['next_7_days', '未来7天', 7\]/);
  assert.match(service, /'requested_horizons' => \[1, 3, 7\]/);
  assert.match(service, /window_snapshot_coverage_incomplete/);
  assert.match(service, /captured_at', '<=', \$asOf->format/);
  assert.match(service, /window_pickup_comparison_window_mismatch/);
  assert.match(service, /pickup_comparison_pair/);
  assert.match(service, /'automatic_pricing' => false/);
  assert.match(service, /'automatic_inventory_write' => false/);
  assert.match(controller, /->demandPlan\(/);
  assert.match(controller, /booking_demand_plan/);
  assert.match(controller, /businessDate \. ' \+1 day'/);
  assert.match(controller, /demandCalendarFromPlan\(\$demandPlan/);
  assert.doesNotMatch(controller, /->demandCalendar\(/);
  assert.match(component, /明天 \/ 未来3天 \/ 未来7天需求计划/);
  assert.match(component, /data-testid="operating-finance-booking-demand-plan"/);
  assert.match(component, /明天至未来7天本地需求事件/);
  assert.doesNotMatch(component, /未来30天本地需求事件/);
  assert.match(component, /canExecute: \{ type: Boolean, default: false \}/);
  assert.match(component, /v-if="canExecute" @submit\.prevent="saveOnBooks"/);
  assert.match(component, /v-if="canExecute" @submit\.prevent="saveEvent"/);
  assert.match(component, /v-if="canExecute" @submit\.prevent="saveFinance"/);
  assert.match(component, /data-testid="operating-finance-view-only"/);
  assert.match(read('resources/frontend/templates/fragments/19c-page-operating-finance.html'), /:can-execute="operationFinanceCanExecute"/);
});

test('one selected recovery blocker', () => {
  const service = read('app/service/OperatingBlockerRecoveryService.php');
  const controller = read('app/controller/OperatingFinance.php');
  const component = read('public/components/system/operating-finance-control-center.js');

  assert.match(service, /'selected_count' => \$selected === null \? 0 : 1/);
  assert.match(service, /'automatic_recovery' => false/);
  assert.match(service, /'automatic_login_or_verification_bypass'/);
  assert.match(service, /'prerequisite'/);
  assert.match(controller, /OperatingBlockerRecoveryService/);
  assert.match(controller, /SELECT 1 AS runtime_ready/);
  assert.doesNotMatch(controller, /SingleInstanceRuntimeReadiness/);
  assert.match(component, /唯一当前阻塞/);
  assert.match(component, /currentRecovery\.selected/);
});

test('WeCom receipt stays sender-reported only', () => {
  const service = read('app/service/WecomTaskReceiptService.php');
  const controller = read('app/controller/OperatingFinance.php');
  const component = read('public/components/system/operating-finance-control-center.js');

  assert.match(service, /sender-reported claim/);
  assert.match(service, /wecom_task_receipt_sender_not_assignee/);
  assert.match(service, /eventReader/);
  assert.match(service, /pseudonymous_sender_hash_persisted/);
  assert.match(controller, /new WecomTaskReceiptService\(\)[\s\S]*?->read\(/);
  assert.match(controller, /'readback_verified', 'persistence_status'/);
  assert.match(controller, /'approval_created' => false/);
  assert.match(controller, /'task_status_changed' => false/);
  assert.match(component, /回执不等于审批、执行成功或财务证据/);
  assert.match(component, /企微发送者哈希 → 宿析OS员工/);
});

test('settlement import separates request persistence from usable business facts', () => {
  const controller = read('app/controller/OperatingFinance.php');
  const component = read('public/components/system/operating-finance-control-center.js');
  const settlement = read('app/service/OtaSettlementReconciliationService.php');
  const recovery = read('app/service/OtaSettlementRecoveryBlockerCandidateService.php');

  assert.match(controller, /'request_status'\] = 'saved_and_readback_verified'/);
  assert.match(controller, /'business_result_status'\] = \$batchStatus/);
  assert.match(controller, /'business_success'\] = \$batchStatus === 'available'/);
  assert.match(controller, /settlement_attempt_invalid_no_usable_fact/);
  assert.match(controller, /结算失败尝试已留痕并精确回读；未形成可用净收入事实/);
  assert.match(component, /response\.data\?\.request_status !== 'saved_and_readback_verified'/);
  assert.match(component, /data-testid="operating-finance-settlement-import-notice"/);
  assert.match(component, /未形成可用净收入事实/);
  assert.match(component, /batchStatus === 'available' \? 'success' : 'warning'/);
  assert.match(settlement, /ota_settlement_financial_basis_ledger\.v1/);
  assert.match(settlement, /platform_subsidy_only/);
  assert.match(settlement, /settlement_amount_is_net_revenue' => false/);
  assert.match(recovery, /maximum_selected' => 1/);
  assert.match(recovery, /reconciliation_absolute_difference/);
  assert.match(recovery, /is_loss_claim' => false/);
  assert.match(component, /data-testid="operating-finance-settlement-recovery-candidate"/);
  assert.match(component, /结算金额不自动等于净收入/);
});

test('same-scope portfolio ranking', () => {
  const service = read('app/service/MonthlyOperatingFinanceService.php');
  const component = read('public/components/system/operating-finance-control-center.js');

  assert.match(service, /same_scope_manual_snapshot_comparable/);
  assert.match(service, /blocked_incomplete_or_mixed_scope/);
  assert.match(service, /METRIC_DEFINITION_VERSION/);
  assert.match(service, /operator_attested/);
  assert.match(service, /employee_evaluation_authorized' => false/);
  assert.match(service, /cross_tenant_data_included' => false/);
  assert.match(component, /相同含税\/不含税口径/);
  assert.match(component, /resetAllWriteDrafts/);
  assert.match(component, /编辑文本会自动取消已选文件/);
  assert.match(component, /Date\.UTC/);
});

test('append-only operating ledgers preserve source identity during controlled hotel-id migration', () => {
  const settlementMigration = read('database/migrations/20260830_create_ota_settlement_reconciliation.sql');
  const receiptMigration = read('database/migrations/20260830_z_create_wecom_task_receipts.sql');
  const planningMigration = read('database/migrations/20260830_zz_create_operating_finance_planning.sql');
  const migrationRunner = read('scripts/migrate_cloud_hotel_id.php');
  const registry = read('scripts/cloud_hotel_id_column_registry.php');

  for (const sql of [settlementMigration, receiptMigration, planningMigration]) {
    assert.match(sql, /source_hotel_id/);
    assert.match(sql, /@suxi_cloud_hotel_id_migration/);
  }
  assert.match(migrationRunner, /SET @suxi_cloud_hotel_id_migration = 1/);
  assert.match(migrationRunner, /SET @suxi_cloud_hotel_id_migration = 0/);
  assert.match(registry, /immutable_source_hotel_id_evidence/);
});
