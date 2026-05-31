import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
  buildCtripDiagnosisSnapshot,
  loadCtripDiagnosisSummary,
  renderCtripDiagnosisSnapshotMarkdown,
} from './build_ctrip_diagnosis_snapshot.mjs';

const dir = mkdtempSync(join(tmpdir(), 'ctrip-diagnosis-snapshot-'));
const first = join(dir, 'first.json');
const second = join(dir, 'second.json');

writeFileSync(first, JSON.stringify({
  profile_id: 'hotel_001',
  auth_status: { ok: true, status: 'logged_in' },
  requested_sections: ['homepage'],
  responses: [{ section: 'homepage', endpoint_id: 'homepage_realtime', data_type: 'business' }],
  catalog_facts: [
    { section: 'homepage', endpoint_id: 'homepage_realtime', metric_key: 'order_amount' },
    { section: 'homepage', endpoint_id: 'homepage_realtime', metric_key: 'visitor_count' },
  ],
  standard_rows: [
    { capture_section: 'homepage', endpoint_id: 'homepage_realtime', data_type: 'business', dimension: 'catalog:homepage:homepage_realtime:order_amount:root' },
  ],
}), 'utf8');

writeFileSync(second, JSON.stringify({
  profile_id: 'hotel_001',
  auth_status: { ok: true, status: 'logged_in' },
  requested_sections: ['im_board'],
  responses: [{ section: 'im_board', endpoint_id: 'im_index', data_type: 'quality' }],
  catalog_facts: [
    { section: 'im_board', endpoint_id: 'im_index', metric_key: 'five_min_reply_rate' },
  ],
  standard_rows: [
    { capture_section: 'im_board', endpoint_id: 'im_index', data_type: 'quality', dimension: 'catalog:im_board:im_index:five_min_reply_rate:root' },
  ],
}), 'utf8');

const snapshot = buildCtripDiagnosisSnapshot([
  loadCtripDiagnosisSummary(first),
  loadCtripDiagnosisSummary(second),
]);
const md = renderCtripDiagnosisSnapshotMarkdown(snapshot);

assert(snapshot.status === 'ready', 'snapshot must report ready');
assert(snapshot.available_groups.includes('收益销售'), 'snapshot must include revenue diagnosis group');
assert(snapshot.available_groups.includes('流量转化'), 'snapshot must include traffic diagnosis group');
assert(snapshot.available_groups.includes('服务质量/IM'), 'snapshot must include quality or IM diagnosis group');
assert(snapshot.sections.homepage.status === 'captured', 'snapshot must keep homepage section status');
assert(snapshot.sections.im_board.status === 'captured', 'snapshot must keep IM section status');
assert(md.includes('携程诊断数据快照'), 'markdown must be readable Chinese');
assert(!/Cookie=/i.test(md), 'markdown must not expose raw Cookie text');

const realWide = 'runtime/ctrip_capture/hotel_001_wide.json';
if (existsAsFile(realWide)) {
  const realSnapshot = buildCtripDiagnosisSnapshot([loadCtripDiagnosisSummary(realWide)]);
  assert(realSnapshot.available_groups.includes('收益销售'), 'real wide capture must expose revenue diagnosis group');
  assert(realSnapshot.available_groups.includes('流量转化'), 'real wide capture must expose traffic diagnosis group');
}

console.log(JSON.stringify({
  status: 'pass',
  available_groups: snapshot.available_groups,
}, null, 2));

function existsAsFile(path) {
  try {
    readFileSync(path);
    return true;
  } catch {
    return false;
  }
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}
