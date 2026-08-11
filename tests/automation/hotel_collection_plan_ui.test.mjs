import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const appMain = read('public/app-main.js');
const template = read(
  'resources/frontend/templates/fragments/15aac-page-automation-monitor.html'
);
const contractComponent = read(
  'public/components/operations/automation-collection-contract.js'
);
const hotelController = read('app/controller/Hotel.php');

test('automation monitor exposes one hotel-scoped binding and plan surface', () => {
  assert.match(template, /:is="automationCollectionContractBody"/);
  assert.match(contractComponent, /'data-testid': 'automation-collection-contract'/);
  assert.match(contractComponent, /'data-testid': 'automation-contract-hotel'/);
  assert.match(contractComponent, /`automation-contract-\$\{platform\}`/);
  assert.match(contractComponent, /otaCard\(ctx, binding, plan, 'ctrip'\)/);
  assert.match(contractComponent, /otaCard\(ctx, binding, plan, 'meituan'\)/);
  assert.match(contractComponent, /'data-testid': 'automation-contract-pms'/);
  assert.match(contractComponent, /系统酒店、携程、美团、主 PMS 和原执行设备/);
  assert.match(contractComponent, /复制门槛：/);
});

test('binding read pins the selected hotel, date and persisted source ids', () => {
  assert.match(appMain, /\/hotels\/\$\{hotelId\}\/collection-plan\?/);
  assert.match(appMain, /\/hotels\/\$\{hotelId\}\/collection-binding-receipt\?/);
  assert.match(appMain, /bindingParams\.set\('ctrip_source_id', String\(ctripSourceId\)\)/);
  assert.match(appMain, /bindingParams\.set\('meituan_source_id', String\(meituanSourceId\)\)/);
  assert.match(hotelController, /\['ctrip', 'meituan'\] as \$platform/);
  assert.match(hotelController, /\$designatedSourceIds\[\$platform\] = \(int\)\$rawSourceId/);
  assert.match(hotelController, /\$designatedSourceIds\s*\n\s*\)/);
});

test('late contract responses cannot overwrite the current hotel and business date', () => {
  const start = appMain.indexOf('const loadAutomationMonitorContract');
  const end = appMain.indexOf('const saveAutomationMonitorPlan', start);
  const loader = appMain.slice(start, end);
  assert.ok(start > 0 && end > start);
  assert.match(loader, /const requestSeq = \+\+automationMonitorContractRequestSeq/);
  assert.match(loader, /requestSeq === automationMonitorContractRequestSeq/);
  assert.match(loader, /String\(automationMonitorContractHotelId\.value \|\| ''\)\.trim\(\) === String\(hotelId\)/);
  assert.match(loader, /String\(automationMonitorDate\.value \|\| ''\)\.trim\(\) === businessDate/);
  const finalScopeGuardAt = loader.lastIndexOf('if (!scopeIsCurrent())');
  const commitAt = loader.indexOf('automationMonitorContract.value = { binding, plan }');
  assert.ok(finalScopeGuardAt > 0 && commitAt > finalScopeGuardAt);
});

test('plan save requires exact sources and signed readback before reporting success', () => {
  assert.match(contractComponent, /'data-testid': 'automation-plan-ctrip-source'/);
  assert.match(contractComponent, /'data-testid': 'automation-plan-meituan-source'/);
  assert.match(contractComponent, /'data-testid': 'automation-plan-pms-provider'/);
  assert.match(contractComponent, /'data-testid': 'automation-plan-save-draft'/);
  assert.match(contractComponent, /'data-testid': 'automation-plan-activate'/);
  assert.match(appMain, /sources:\s*\{\s*ctrip: \{ data_source_id: ctripSourceId \},\s*meituan: \{ data_source_id: meituanSourceId \},\s*pms: \{ provider: pmsProvider \}/);
  assert.match(appMain, /res\.data\?\.readback_verified !== true \|\| res\.data\?\.save_verified !== true/);
  assert.match(appMain, /activate && !automationMonitorContractCanActivate\.value/);
});

test('plan read exposes one exact hotel-date run receipt on the existing operator surface', () => {
  assert.match(contractComponent, /'data-testid': 'automation-collection-run-receipt'/);
  assert.match(contractComponent, /`automation-run-\$\{platform\}`/);
  assert.match(contractComponent, /plan\.latest_run_receipt/);
  assert.match(contractComponent, /run\.dispatcher_run_id/);
  assert.match(contractComponent, /run\.collection_anchor_hash/);
  assert.match(contractComponent, /run\.pms_receipt/);
  assert.match(contractComponent, /run\.page_acceptance/);
});

test('operator login recovery is original-device only and never represented as a cookie pool', () => {
  const surface = `${contractComponent}\n${appMain}`;
  assert.match(surface, /原账号、原设备、原酒店、原平台恢复/);
  assert.match(surface, /不串酒店、不自动换设备代采/);
  assert.match(surface, /不保存 Cookie、验证码或 Profile 路径/);
  assert.doesNotMatch(surface, /central(?:ized)?[_ -]cookie[_ -]pool\s*[:=]\s*true/i);
  assert.doesNotMatch(surface, /automatic_device_substitution\s*[:=]\s*true/i);
});

test('recovery action opens the local collector with the exact selected hotel pinned', () => {
  assert.match(contractComponent, /'data-testid': 'automation-contract-open-device-onboarding'/);
  assert.match(contractComponent, /ctx\.openHotelCollectionDeviceOnboarding\(\s*ctx\.automationMonitorContractHotelId/);
  assert.match(appMain, /const openHotelCollectionDeviceOnboarding = \(hotelId = automationMonitorContractHotelId\.value\)/);
  assert.match(appMain, /localCollectorAccountForm\.value = \{[\s\S]*?system_hotel_id: scopedHotelId/);
  assert.match(appMain, /localCollectorBindingForm\.value = \{[\s\S]*?system_hotel_id: scopedHotelId/);
  assert.match(appMain, /openOnlineDataEntryTab\('platform-sources', \{ force: true \}\)/);
  assert.match(appMain, /ota_local_collector_source_binding_proof_missing/);
  assert.match(appMain, /ota_local_collector_source_execution_mismatch/);
  assert.match(appMain, /ota_execution_owner_permission_unverified/);
});

test('lazy collection contract component renders the exact hotel plan without a template compiler', () => {
  const h = (type, props = {}, children = []) => ({ type, props: props || {}, children });
  const sandbox = { window: { Vue: { h } } };
  runInNewContext(contractComponent, sandbox);
  const component = sandbox.window.SUXI_ONLINE_DATA_COMPONENTS?.AutomationCollectionContractBody;
  assert.ok(component);

  let openedHotelId = '';
  const ctx = {
    automationMonitorContractHotelId: '80',
    automationMonitorDate: '2026-08-09',
    automationMonitorContractHotelOptions: [{ id: 80, name: '敦煌漠蓝新' }],
    automationMonitorContractLoading: false,
    automationMonitorContractSaving: false,
    automationMonitorContractError: '',
    automationMonitorContractCanActivate: false,
    automationMonitorContractReasons: [
      { platform: 'meituan', code: 'ota_platform_hotel_id_canonical_missing' },
    ],
    automationMonitorContractBinding: {
      status: 'blocked',
      system_hotel: { system_hotel_id: 80, tenant_id: 80, hotel_name: '敦煌漠蓝新' },
      bindings: {
        ctrip: { status: 'blocked', source_id: 25, platform_hotel_id: '130079194', profile_binding: { status: 'active' }, execution_device_binding: { status: 'missing' } },
        meituan: { status: 'blocked', source_id: 68, platform_hotel_id: null, profile_binding: { status: 'active' }, execution_device_binding: { status: 'missing' } },
        pms: { status: 'ready', provider: 'dingdandao_pms', provider_hotel_id: '5206408', provider_hotel_name: '敦煌漠蓝' },
      },
      replication_gate: { ready: false },
    },
    automationMonitorContractPlan: {
      status: 'draft',
      plan_version: 2,
      readback_verified: true,
      sources: {
        ctrip: { data_source_id: 25 },
        meituan: { data_source_id: 68 },
        pms: { provider: 'dingdandao_pms' },
      },
      latest_run_receipt: {
        status: 'succeeded',
        system_hotel_id: 80,
        business_date: '2026-08-09',
        dispatcher_run_id: '11111111-1111-4111-8111-111111111111',
        collection_anchor_hash: 'a'.repeat(64),
        failure_code: '',
        pms_receipt: { status: 'verified', capture_id: '9', readback_verified: true },
        page_acceptance: { status: 'verified', receipt_id: 12 },
        source_receipts: [
          { platform: 'ctrip', data_source_id: 25, status: 'success', saved_row_count: 1, readback_row_count: 1 },
          { platform: 'meituan', data_source_id: 68, status: 'success', saved_row_count: 1, readback_row_count: 1 },
        ],
      },
    },
    automationMonitorPlanForm: {
      ctrip_source_id: '25', meituan_source_id: '68', pms_provider: 'dingdandao_pms',
      schedule_time: '08:30', retry_interval_minutes: 14, max_attempts: 7,
    },
    automationMonitorContractStatusClass: () => 'status-class',
    automationMonitorContractStatusText: status => String(status || 'missing'),
    automationMonitorContractReasonText: issue => issue.code,
    automationMonitorContractSourceOptions: platform => platform === 'ctrip' ? [25] : [68, 101],
    loadAutomationMonitorContract: () => {},
    saveAutomationMonitorPlan: () => {},
    openHotelCollectionDeviceOnboarding: hotelId => { openedHotelId = String(hotelId); },
  };
  const tree = component.setup({ ctx })();
  const testIds = [];
  let onboardingButton = null;
  const visit = node => {
    if (!node || typeof node !== 'object') return;
    if (node.props?.['data-testid']) {
      testIds.push(node.props['data-testid']);
      if (node.props['data-testid'] === 'automation-contract-open-device-onboarding') {
        onboardingButton = node;
      }
    }
    const children = Array.isArray(node.children) ? node.children : [node.children];
    children.forEach(visit);
  };
  visit(tree);
  assert.equal(tree.type, 'section');
  assert.ok(testIds.includes('automation-collection-contract'));
  assert.ok(testIds.includes('automation-contract-ctrip'));
  assert.ok(testIds.includes('automation-contract-meituan'));
  assert.ok(testIds.includes('automation-plan-save-draft'));
  assert.ok(testIds.includes('automation-plan-activate'));
  assert.ok(testIds.includes('automation-contract-open-device-onboarding'));
  assert.ok(testIds.includes('automation-collection-run-receipt'));
  assert.ok(testIds.includes('automation-run-ctrip'));
  assert.ok(testIds.includes('automation-run-meituan'));
  onboardingButton.props.onClick();
  assert.equal(openedHotelId, '80');

  ctx.automationMonitorDate = '2026-08-10';
  const staleScopeTree = component.setup({ ctx })();
  const staleScopeTestIds = [];
  const visitStaleScope = node => {
    if (!node || typeof node !== 'object') return;
    if (node.props?.['data-testid']) staleScopeTestIds.push(node.props['data-testid']);
    const children = Array.isArray(node.children) ? node.children : [node.children];
    children.forEach(visitStaleScope);
  };
  visitStaleScope(staleScopeTree);
  assert.ok(staleScopeTestIds.includes('automation-collection-run-receipt'));
  assert.ok(staleScopeTestIds.includes('automation-collection-run-empty'));
  assert.ok(!staleScopeTestIds.includes('automation-run-ctrip'));
  assert.ok(!staleScopeTestIds.includes('automation-run-meituan'));
});
