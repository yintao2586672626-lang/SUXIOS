import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = relativePath => readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8');

const loadWindowApi = (source, apiName) => {
  const sandbox = { window: {} };
  vm.runInNewContext(`${source}\nthis.__api = window.${apiName};`, sandbox);
  return sandbox.__api;
};

const ctripStatic = read('public/ctrip-static.js');
const meituanStatic = read('public/meituan-static.js');
const ctripTemplate = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const platformPanels = read('public/components/online-data/platform-auto-settings-panels.js');
const ctripApi = loadWindowApi(ctripStatic, 'SUXI_CTRIP_STATIC');
const meituanApi = loadWindowApi(meituanStatic, 'SUXI_MEITUAN_STATIC');

test('capture notices surface a missing Session proof without turning saved data into failure', () => {
  const payload = {
    session_proof_status: 'not_recorded',
    session_proof_reason_code: 'data_source_scope_missing',
    session_proof_message: '数据已保存，但当前 Profile 尚未绑定。',
    session_proof_next_action: '绑定当前门店数据源后重试。',
  };
  const fallback = { message: '采集成功', level: 'success' };

  for (const notice of [
    ctripApi.buildCtripSessionProofNotice(payload, fallback),
    meituanApi.buildMeituanSessionProofNotice(payload, fallback),
  ]) {
    assert.equal(notice.level, 'warning');
    assert.equal(notice.sessionProofMissing, true);
    assert.match(notice.message, /数据已保存/);
    assert.match(notice.message, /下一步：绑定当前门店数据源后重试/);
  }
});

test('confirmed-empty proof notices stay informational and verified responses preserve normal success copy', () => {
  const empty = ctripApi.buildCtripSessionProofNotice({
    session_proof_status: 'not_recorded',
    session_proof_reason_code: 'no_persisted_rows',
    session_proof_message: '本次没有目标日期入库行。',
    session_proof_next_action: '核对目标日期后重试。',
  }, { message: '平台确认无数据', level: 'success' });
  assert.equal(empty.level, 'info');

  const verified = meituanApi.buildMeituanSessionProofNotice({
    session_proof_status: 'verified',
  }, { message: '采集成功', level: 'success' });
  assert.equal(verified.message, '采集成功');
  assert.equal(verified.level, 'success');
});

test('both OTA result panels render the proof reason and next action for operators', () => {
  assert.match(ctripTemplate, /session_proof_status === 'not_recorded'/);
  assert.match(ctripTemplate, /br v-if="ctripBrowserCaptureResult.warning"/);

  assert.match(platformPanels, /data-testid="meituan-session-proof-not-recorded"/);
  assert.match(platformPanels, /session_proof_status === 'not_recorded'/);
  assert.match(platformPanels, /session_proof_message/);
  assert.match(platformPanels, /session_proof_next_action/);
  assert.match(platformPanels, /下一步：/);
});

test('capture flows explicitly route success notices through the Session proof warning mapper', () => {
  assert.match(ctripStatic, /const sessionProofNotice = buildCtripSessionProofNotice\(res\.data \|\| \{\}, notice\)/);
  assert.match(ctripStatic, /warning: resultProofNotice\.message/);
  assert.match(ctripStatic, /notify\(`\$\{sessionProofNotice\.message\}\$\{suffix\}`/);
  assert.match(meituanStatic, /const sessionProofNotice = buildMeituanSessionProofNotice\(data, notice\)/);
  assert.match(meituanStatic, /notify\(sessionProofNotice\.message, sessionProofNotice\.level\)/);
});
