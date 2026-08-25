import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = readFileSync(
  path.join(repoRoot, 'public/components/system/dual-ota-field-closure-panel.js'),
  'utf8',
);
const context = { window: {}, URLSearchParams };
vm.runInNewContext(source, context, { filename: 'dual-ota-field-closure-panel.js' });
const panel = context.window.SUXI_DUAL_OTA_FIELD_CLOSURE;

test('field closure formatter keeps missing and formal zero distinct', () => {
  assert.equal(panel.valueText({ status: 'missing', value: 0, unit: 'orders' }), '—');
  assert.equal(panel.valueText({ status: 'platform_not_provided', value: 0, unit: 'orders' }), '—');
  assert.equal(panel.valueText({ status: 'strict_readback', value: 0, unit: 'orders' }), '0');
  assert.equal(panel.valueText({ status: 'strict_readback', value: 5921.18, unit: 'CNY' }), '¥5,921.18');
  assert.equal(panel.valueText({ status: 'verified_calculation', value: 19.02, unit: 'percent' }), '19.02%');
});

test('uncertain semantics display each exact candidate with its formal record ref', () => {
  const text = panel.valueText({
    status: 'caliber_uncertain',
    unit: 'CNY',
    observed_values: [
      { value: 7895.43, basis: 'business_card_amount', source_record_ref: 'online_daily_data#101874' },
      { value: 7025.14, basis: 'order_summary_amount', source_record_ref: 'online_daily_data#101926' },
    ],
  });
  assert.match(text, /¥7,895\.43/);
  assert.match(text, /online_daily_data#101874/);
  assert.match(text, /¥7,025\.14/);
  assert.match(text, /online_daily_data#101926/);
});

test('panel contract exposes all required status labels and safe source refs', () => {
  for (const [status, label] of Object.entries({
    strict_readback: '已严格回读',
    verified_calculation: '已验证计算',
    missing: '缺失',
    platform_not_provided: '平台未提供',
    collection_failed: '采集失败',
    login_expired: '登录失效',
    date_mismatch: '日期不符',
    caliber_uncertain: '口径不确定',
  })) {
    assert.equal(panel.statusText(status), label);
  }
  assert.equal(
    panel.sourceRefsText({ source_record_refs: ['online_daily_data#101518', 'online_daily_data#101519'] }),
    'online_daily_data#101518、online_daily_data#101519',
  );
  assert.equal(panel.sourceRefsText({ source_record_refs: [] }), '无正式记录');
  assert.equal(panel.recordRefsSummary([]), '0 条');
  assert.equal(panel.recordRefsSummary([101518]), '1 条（online_daily_data#101518）');
  assert.equal(
    panel.recordRefsSummary(['online_daily_data#101518', 'online_daily_data#101519']),
    '2 条（online_daily_data#101518、online_daily_data#101519）',
  );
  assert.equal(
    panel.recordRefsSummary([101518, 101519, 101810]),
    '3 条（online_daily_data#101518…online_daily_data#101810）',
  );
  assert.equal(panel.exactScopeText('verified'), '整批精确回读通过');
  assert.equal(panel.exactScopeText('exact_run_readback_scope_mismatch'), '整批精确回读未闭合');
});

test('rendered panel carries identical closure identity hooks for both surfaces', () => {
  const h = (tag, props, children) => ({ tag, props: props || {}, children });
  const component = panel.createPanel({ h });
  const closure = {
    status: 'partial',
    hotel_id: 80,
    business_date: '2026-08-23',
    page_identity: 'dual_ota_field_closure#same-contract',
    revenue_analysis_consumable_field_count: 0,
    platforms: {
      ctrip: {
        platform: 'ctrip',
        platform_label: '携程',
        identity_status: 'verified',
        formal_record_refs: ['online_daily_data#101519'],
        current_receipt_record_refs: ['online_daily_data#101519'],
        current_receipt_all_record_refs: ['online_daily_data#101519'],
        current_receipt_non_eligible_record_refs: [],
        semantic_veto_record_refs: [],
        revenue_analysis: { status: 'blocked' },
        latest_collection: { sync_task_id: 4351, data_source_id: 25, p0_status: 'blocked' },
        fields: [{
          key: 'revenue', label: '收入', unit: 'CNY', status: 'strict_readback', value: 5921.18,
          source_record_ids: [101519], source_record_refs: ['online_daily_data#101519'],
          revenue_analysis_consumable: false, quality_flags: [],
        }],
      },
      meituan: {
        platform: 'meituan',
        platform_label: '美团',
        identity_status: 'verified',
        formal_record_refs: ['online_daily_data#101917', 'online_daily_data#102432'],
        current_receipt_record_refs: ['online_daily_data#101917'],
        current_receipt_all_record_refs: ['online_daily_data#101917', 'online_daily_data#102432'],
        current_receipt_non_eligible_record_refs: ['online_daily_data#102432'],
        semantic_veto_record_refs: [],
        revenue_analysis: { status: 'blocked' },
        latest_collection: { sync_task_id: 4352, data_source_id: 101, p0_status: 'blocked' },
        fields: [{
          key: 'conversion', label: '曝光→访问转化', unit: 'percent', status: 'verified_calculation', value: 19.02,
          source_record_ids: [101917], source_record_refs: ['online_daily_data#101917'],
          revenue_analysis_consumable: false, quality_flags: ['verified_against_platform_exposure_to_browse_rate'],
        }],
      },
    },
  };
  for (const surface of ['data_health', 'revenue_cockpit']) {
    const tree = component.render.call({ closure, surface });
    assert.equal(tree.props['data-testid'], `dual-ota-field-closure-${surface}`);
    assert.equal(tree.props['data-closure-identity'], closure.page_identity);
    assert.equal(tree.props['data-business-date'], closure.business_date);
    const rendered = JSON.stringify(tree);
    assert.match(rendered, /整批正式回读 2 条/);
    assert.match(rendered, /字段可用 1 条/);
    assert.match(rendered, /当前回执中校验隔离或字段不可用/);
    assert.match(rendered, /online_daily_data#102432/);
  }
});

test('missing consumable-count metadata remains unknown instead of becoming zero', () => {
  const h = (tag, props, children) => ({ tag, props: props || {}, children });
  const component = panel.createPanel({ h });
  const closure = {
    status: 'partial',
    hotel_id: 80,
    business_date: '2026-08-23',
    page_identity: 'dual_ota_field_closure#unknown-count',
    platforms: {
      ctrip: { platform: 'ctrip', platform_label: '携程', latest_collection: {}, fields: [] },
      meituan: { platform: 'meituan', platform_label: '美团', latest_collection: {}, fields: [] },
    },
  };

  const tree = component.render.call({ closure, surface: 'data_health' });
  assert.match(JSON.stringify(tree), /收益可消费字段 —/);
  assert.doesNotMatch(JSON.stringify(tree), /收益可消费字段 0/);
});

test('cockpit read uses the exact hotel and business date and rejects scope drift', () => {
  const pathName = panel.closureRequestPath({
    hotelId: 80,
    businessDate: '2026-08-23',
    force: true,
  });
  assert.match(pathName, /^\/online-data\/collection-reliability\?/);
  const query = new URLSearchParams(pathName.split('?')[1]);
  assert.equal(query.get('hotel_id'), '80');
  assert.equal(query.get('end_date'), '2026-08-23');
  assert.equal(query.get('days'), '1');
  assert.equal(query.get('mode'), 'light');
  assert.equal(query.get('force'), '1');

  const closure = {
    contract_version: 'dual_ota_field_closure.v1',
    hotel_id: 80,
    business_date: '2026-08-23',
    platforms: { ctrip: {}, meituan: {} },
    sensitive_values_exposed: false,
  };
  assert.equal(panel.resolveClosureResponse(
    { code: 200, data: { dual_ota_field_closure: closure } },
    { hotelId: 80, businessDate: '2026-08-23' },
  ).closure, closure);
  assert.equal(panel.resolveClosureResponse(
    { code: 200, data: { dual_ota_field_closure: closure } },
    { hotelId: 81, businessDate: '2026-08-23' },
  ).reason, 'dual_ota_field_closure_scope_mismatch');
  assert.throws(
    () => panel.closureRequestPath({ hotelId: 80, businessDate: 'not-a-date' }),
    /dual_ota_field_closure_scope_invalid/,
  );
});

test('component performs a scoped read when the parent payload is unavailable', async () => {
  const h = (tag, props, children) => ({ tag, props: props || {}, children });
  const component = panel.createPanel({ h });
  const closure = {
    contract_version: 'dual_ota_field_closure.v1',
    hotel_id: 80,
    business_date: '2026-08-23',
    platforms: { ctrip: {}, meituan: {} },
    sensitive_values_exposed: false,
  };
  const instance = {
    ...component.data(),
    closure: null,
    hotelId: 80,
    businessDate: '2026-08-23',
    forceRead: true,
    request: async requestPath => {
      assert.match(requestPath, /hotel_id=80/);
      assert.match(requestPath, /end_date=2026-08-23/);
      return { code: 200, data: { dual_ota_field_closure: closure } };
    },
  };
  const result = await component.methods.refreshClosure.call(instance);
  assert.equal(result, closure);
  assert.equal(instance.fetchedClosure, closure);
  assert.equal(instance.closureError, '');
  assert.equal(instance.closureLoading, false);
});

test('startup helper registers the panel in the existing system component registry', () => {
  const registeredContext = {
    window: {
      Vue: { h: (tag, props, children) => ({ tag, props, children }) },
      SUXI_SYSTEM_COMPONENTS: {},
    },
    URLSearchParams,
  };
  vm.runInNewContext(source, registeredContext, { filename: 'dual-ota-field-closure-panel.js' });
  assert.equal(
    registeredContext.window.SUXI_SYSTEM_COMPONENTS.DualOtaFieldClosurePanel?.name,
    'DualOtaFieldClosurePanel',
  );
});

test('component source never reads platform credentials or replaces unknown with numeric zero', () => {
  assert.doesNotMatch(source, /cookie|authorization|localStorage|sessionStorage|profile_key|platform_hotel_id/i);
  assert.doesNotMatch(source, /field\?\.value\s*\|\|\s*0/);
  assert.doesNotMatch(source, /revenue_analysis_consumable_field_count\s*\|\|\s*0/);
  assert.match(source, /data-closure-identity/);
  assert.match(source, /data-source-record-ids/);
  assert.match(source, /整批正式回读/);
  assert.match(source, /字段可用/);
  assert.match(source, /保留追溯，不作为字段事实消费/);
  assert.match(source, /只阻断，不替代当前值/);
});
