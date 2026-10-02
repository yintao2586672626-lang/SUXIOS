import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { verifyPhase3FrontendSemantics } from '../../scripts/verify_phase3_operation_effect_loop_contract.mjs';

const actual = {
  appMain: fs.readFileSync('public/app-main.js', 'utf8')
    + '\n' + fs.readFileSync('public/system-page-projections.js', 'utf8'),
  onlineTemplate: fs.readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8'),
  knowledgeTemplate: fs.readFileSync('resources/frontend/templates/fragments/20-page-knowledge-center.html', 'utf8'),
};
const replaceOnce = (source, before, after) => {
  assert.equal(source.split(before).length - 1, 1, 'Mutation must target exactly one current source expression.');
  return source.replace(before, after);
};
const reject = async (input, key, detail) => {
  const checks = await verifyPhase3FrontendSemantics(input);
  assert.deepEqual(checks.filter(check => !check.ok).map(check => [check.key, check.detail]), [[key, detail]]);
};

test('original adapter and mounted SOP/replication entries preserve missing and candidate-only behavior', async () => {
  const checks = await verifyPhase3FrontendSemantics(actual);
  assert.deepEqual(checks.map(check => [check.key, check.ok, check.detail]), [['adapter', true, ''], ['visible', true, '']]);
});

test('removing missing-evidence explanations fails on the rendered empty state', async () => {
  await reject({ ...actual, knowledgeTemplate: replaceOnce(actual.knowledgeTemplate,
    '当前租户的其他可访问酒店没有有效且已人工验证的正式 SOP，不会用候选或历史版本替代。', '') },
  'visible', 'replication_gap_explanation_missing');
  await reject({ ...actual, knowledgeTemplate: replaceOnce(actual.knowledgeTemplate,
    '尚无可比候选，或接入前置条件未完成。', '') }, 'visible', 'comparable_gap_explanation_missing');
});

test('unverified reviews cannot count as ready SOP evidence', async () => {
  await reject({ ...actual, appMain: replaceOnce(actual.appMain,
    'const eligibleRows = scopedRows.filter(memory => {', 'const eligibleRows = scopedRows.filter(memory => { return true;') },
  'visible', 'unverified_review_ready_state_mismatch');
});

test('incomplete SOP options cannot become selectable', async () => {
  await reject({ ...actual, knowledgeTemplate: replaceOnce(actual.knowledgeTemplate,
    ':disabled="sop.replication_eligibility !== \'eligible_for_validation_draft\'"', '') },
  'visible', 'incomplete_sop_selectable');
});

test('generation cannot be enabled when there is no source SOP', async () => {
  await reject({ ...actual, knowledgeTemplate: replaceOnce(actual.knowledgeTemplate,
    ':disabled="!operatingNetworkReplicationForm.source_sop_version_id || !!operatingNetworkAction || operatingNetworkData?.data_status !== \'ok\'"', '') },
  'visible', 'missing_sop_generation_enabled');
});

test('a selected eligible SOP must allow generating its validation draft', async () => {
  await reject({ ...actual, knowledgeTemplate: replaceOnce(actual.knowledgeTemplate,
    ':disabled="!operatingNetworkReplicationForm.source_sop_version_id || !!operatingNetworkAction || operatingNetworkData?.data_status !== \'ok\'"', ':disabled="true"') },
  'visible', 'eligible_sop_generation_disabled');
});

test('screening-only peers cannot be advertised as eligible for replication', async () => {
  await reject({ ...actual, appMain: replaceOnce(actual.appMain,
    'replicationText: targetHotels.length ?', "replicationText: replication.status === 'screening_only' ? '可复制门店' : targetHotels.length ?") },
  'adapter', 'screening_promoted_to_replication');
});

test('equivalent missing and candidate wording does not fail the semantic contract', async () => {
  let appMain = replaceOnce(actual.appMain, '暂无同问题筛选线索', '还没有同类问题候选，等待补充线索');
  appMain = replaceOnce(appMain, '尚无已核验的同范围效果复盘', '同口径复盘证据尚待核验');
  const onlineTemplate = actual.onlineTemplate.replace('继续积累真实复盘', '证据不足，先补齐复盘')
    .replace('可进入候选验证', '仅进入候选核验，并不代表可以复制');
  const knowledgeTemplate = actual.knowledgeTemplate
    .replace('可比酒店候选', '同画像门店线索')
    .replace('尚无可比候选，或接入前置条件未完成。', '当前没有可比酒店，请先完善接入资料。')
    .replace('当前租户的其他可访问酒店没有有效且已人工验证的正式 SOP，不会用候选或历史版本替代。', '当前没有符合要求的正式 SOP，请先完成来源核验。')
    .replace('标为“资料不全”的 SOP 缺少来源店权威经营闭环、适用画像、动作参数、成功/失败/停止条件或当前有效证据，不能选择用于复制。', 'SOP 资料尚未齐全，暂不能用于复制。');
  const checks = await verifyPhase3FrontendSemantics({ appMain, onlineTemplate, knowledgeTemplate });
  assert.deepEqual(checks.map(check => [check.key, check.ok, check.detail]), [['adapter', true, ''], ['visible', true, '']]);
});
