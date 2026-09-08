import { readFile } from 'node:fs/promises';

export async function recoverySources() {
  const app = await readFile(new URL('../../../public/app-main.js', import.meta.url), 'utf8');
  const projection = app.slice(app.indexOf('const localCollectorReceiptObject ='), app.indexOf('// End pure local collection receipt projections.'));
  const action = app.slice(app.indexOf('const runLocalCollectorRecovery ='), app.indexOf('const loadLocalCollectorTaskEvidence ='));
  return { projection, action };
}

export function recoveryTask(state = 'partial', id = 42) {
  const saved = ['success', 'partial', 'recovered_success'].includes(state);
  const hash = saved || state === 'unknown' ? 'a'.repeat(64) : '';
  const scope = { tenant_id: 12, system_hotel_id: 101, account_id: 6, platform: 'meituan', platform_hotel_id: 'SYNTHETIC-MT-101',
    business_date: '2026-09-01', data_type: 'business', source_method: 'local_account_profile' };
  const receipt = { ...scope, task_id: id, result_id: `synthetic-result-${id}`, result_hash: hash, attempt: 2, status: 'accepted',
    business_status: state === 'partial' ? 'field_gap' : 'success' };
  return {
    id, tenant_id: 12, system_hotel_id: 101, account_id: 6, platform: 'meituan', platform_hotel_id: scope.platform_hotel_id,
    data_date: scope.business_date, data_type: 'business', task_type: 'backfill', attempt: 2,
    status: saved ? 'success' : state === 'unknown' ? 'result_unknown' : 'failed',
    request_summary: { result_delivery: hash ? { ...receipt, status: saved ? 'accepted' : 'upload_pending' } : {} },
    result_summary: saved ? {
      scope_identity: scope, result_delivery: receipt, saved_count: 2, readback_count: 2, readback_verified: true,
      run_readback_scope_verified: true, run_readback: { ...scope, readback_verified: true, row_ids: [11, 12] },
      data_source_id: 8, sync_task_id: 9,
      canonical_history: { tenant_id: 12, hotel_id: 101, target_date: scope.business_date, status: 'blocked', platform_results: {} },
      dual_ota_authority: { ready: false, status: 'awaiting_other_platform', missing_platforms: ['ctrip'] },
    } : {},
    recovery_item: {
      schema_version: 'ota_collection_recovery.v1', task_id: id, attempt: 2, scope, state,
      reason: state === 'unknown' ? '请求中断，保存结果尚未明确。先核对原回执与精确记录；只恢复同一结果。'
        : saved ? '当前平台已有保存回读证据；缺口与另一平台状态仍分别显示。' : '采集失败，核对原记录后补采原业务日。',
      actions: ['reconcile', ...(['partial', 'failed'].includes(state) ? ['backfill'] : [])],
      missing_field_keys: state === 'partial' ? ['list_exposure', 'detail_exposure', 'flow_rate'] : [],
      source_receipt: { task_id: id, attempt: 2, result_id: hash ? receipt.result_id : '', result_hash: hash },
      stages: { capture: saved || state === 'unknown' ? 'received' : 'failed', save: saved ? 'saved' : 'unknown', exact_readback: saved ? 'verified_at_receipt' : 'unknown' },
      last_check: null, recovery_task_id: null,
    },
  };
}
