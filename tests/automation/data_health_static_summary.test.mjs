import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context, {
  filename: 'public/data-health-static.js',
});

const helpers = context.window.SUXI_DATA_HEALTH_STATIC;

test('data health field-gap summary stays read-only and source-aware', () => {
  assert.equal(typeof helpers.summarizeDataHealthFieldGapActions, 'function');

  const rows = [{
    status: 'missing',
    sourceRef: 'missing_field_codes',
  }, {
    status: 'forbidden',
    sourceRef: 'field_asset_summary.forbidden_fields',
  }, {
    status: 'not_returned_visible',
    sourceRef: 'field_asset_summary.not_returned_fields',
  }];

  const summary = helpers.summarizeDataHealthFieldGapActions(rows);
  assert.equal(summary.countText, '3 项缺口');
  assert.match(summary.detailText, /待补 2/);
  assert.match(summary.detailText, /禁止采集 1/);
  assert.match(summary.detailText, /来源 3/);
  assert.match(summary.boundaryText, /未返回字段不按成功处理/);
  assert.equal(summary.hasForbidden, true);
});

test('release evidence panel rows keep release readiness blockers non-closing', () => {
  assert.equal(typeof helpers.buildReleaseEvidencePanelRows, 'function');
  assert.equal(typeof helpers.summarizeReleaseEvidencePanel, 'function');

  const gapPack = {
    release_ready: false,
    blocking_requirements: [
      {
        id: 'design-handoff-missing',
        status: 'missing',
        acceptance_command: 'npm run review:release-design',
        evidence: 'controlled design handoff manifest is missing',
      },
      {
        id: 'ota-credential-rotation-attestation-missing',
        status: 'missing',
        acceptance_command: 'npm run review:release-ota-credentials',
      },
      {
        id: 'local-git-state-open',
        status: 'open',
        acceptance_command: 'npm run review:release-external-state',
      },
    ],
    operator_intake_packet: {
      does_not_close_release_readiness: true,
      required_external_inputs: [
        {
          id: 'design_handoff_manifest',
          required_file: '../release-evidence-temp/design_handoff_manifest.json',
          creation_command: 'npm run release:create-design-manifest',
          isolated_review_command: 'npm run review:release-design',
        },
        {
          id: 'ota_credential_rotation_attestation',
          required_file: '../release-evidence-temp/ota_credential_rotation_attestation.json',
          creation_command: 'npm run release:create-ota-attestation',
          isolated_review_command: 'npm run review:release-ota-credentials',
        },
        {
          id: 'final_release_pr_and_local_state',
          required_result_file: '../release-evidence-temp/release-external-state-result.json',
          selection_command: 'npm run review:release-pr-candidates',
          isolated_review_command: 'npm run review:release-external-state',
        },
      ],
    },
    source_status: {
      local_worktree_close_plan: {
        status: 'blocked_until_clean_or_isolated',
        changed_entries: 3,
      },
    },
  };

  const rows = helpers.buildReleaseEvidencePanelRows(gapPack);
  assert.equal(rows.length, 3);
  assert.equal(rows.map(row => row.id).join(','), 'design_handoff_manifest,ota_credential_rotation_attestation,final_release_pr_and_local_state');
  assert.equal(rows.every(row => row.priority === 'high'), true);
  assert.equal(rows.find(row => row.id === 'final_release_pr_and_local_state').statusText, '未关闭');
  assert.match(rows.find(row => row.id === 'design_handoff_manifest').acceptanceCommand, /review:release-design/);

  const summary = helpers.summarizeReleaseEvidencePanel(gapPack);
  assert.equal(summary.releaseReady, false);
  assert.equal(summary.doesNotCloseReleaseReadiness, true);
  assert.equal(summary.blockerCount, 3);
  assert.equal(summary.worktreeStatus, 'blocked_until_clean_or_isolated');
  assert.equal(summary.changedEntries, 3);
  assert.match(summary.boundaryText, /不替代最终设计交付/);
  assert.match(summary.boundaryText, /review:release-readiness/);
});
