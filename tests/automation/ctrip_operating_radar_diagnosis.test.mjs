import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = (file) => readFileSync(file, 'utf8');
const staticSource = read('public/ota-diagnosis-static.js');
const appMain = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const controller = read('app/controller/Agent.php');
const persistence = read('app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php');
const service = read('app/service/CtripOperatingRadarDiagnosisService.php');

const sandbox = { window: {} };
vm.runInNewContext(`${staticSource}\nthis.api = window.SUXI_OTA_DIAGNOSIS_STATIC;`, sandbox);
const api = sandbox.api;

test('Ctrip operating radar statuses are explicit and visually distinct', () => {
  assert.equal(api.otaDiagnosisDecisionStatusText('observed_channel_signal'), '已取得渠道信号');
  assert.equal(api.otaDiagnosisDecisionStatusText('partial_evidence'), '部分证据');
  assert.equal(api.otaDiagnosisDecisionStatusText('reference_only'), '仅供参考');
  assert.match(api.otaDiagnosisDecisionStatusClass('observed_channel_signal'), /emerald/);
  assert.match(api.otaDiagnosisDecisionStatusClass('partial_evidence'), /amber/);
  assert.match(api.otaDiagnosisDecisionStatusClass('reference_only'), /slate/);
});

test('Agent Center exposes a saved Ctrip five-dimension evidence radar without a fake score chart', () => {
  assert.match(template, /生成OTA\/雷达诊断/);
  assert.match(template, /v-html="otaDiagnosisOperatingRadarHtml"/);
  assert.match(staticSource, /data-testid="ctrip-operating-radar-diagnosis"/);
  assert.match(staticSource, /携程经营雷达诊断/);
  assert.match(staticSource, /五维证据视图/);
  assert.match(staticSource, /官方分数：<strong[^>]*>未提供<\/strong>/);
  assert.match(staticSource, /知识版本/);
  assert.match(staticSource, /已保存并精确回读/);
  assert.match(staticSource, /待核验派生信号/);
  assert.match(staticSource, /不可用于自动调价、改房态、调佣\/服务费、购买流量、创建任务或写入 OTA\/PMS/);
  assert.doesNotMatch(template, /<svg[^>]*data-testid="ctrip-operating-radar-diagnosis"/);
  assert.match(appMain, /ota-diagnosis-static\.js\?v=20260811-ctrip-operating-radar-v2/);
  assert.match(appMain, /const otaDiagnosisOperatingRadarHtml = computed/);
  assert.match(appMain, /otaDiagnosisResultSections, otaDiagnosisOperatingRadarHtml, otaDiagnosisDecisionClosureCards/);
});

test('radar renderer escapes persisted text while preserving saved-readback and five-card output', () => {
  const html = api.renderCtripOperatingRadarHtml({
    saved_record: { id: 9, saved: true, readback_verified: true },
    operating_radar: {
      status: 'partial_evidence',
      message: '<img src=x onerror=alert(1)>',
      knowledge: { truth_profile_version: '2026-08-11.4' },
      summary: { observed_count: 1, partial_count: 3, blocked_count: 1 },
      dimensions: Array.from({ length: 5 }, (_, index) => ({
        key: `d${index}`,
        label: `维度${index + 1}`,
        stage: '阶段',
        status: 'partial_evidence',
        official_score: null,
        metrics: [],
        missing_facts: [],
        evidence_refs: [],
        next_check: '核验',
      })),
    },
  });
  assert.match(html, /已保存并精确回读 #9/);
  assert.equal((html.match(/<article /g) || []).length, 5);
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.doesNotMatch(html, /<img src=x/);
});

test('backend attaches the radar to both data and no-data Ctrip diagnoses', () => {
  const calls = controller.match(/\$result\['operating_radar'\] = \(new CtripOperatingRadarDiagnosisService\(\)\)->build\(\$result\);/g) || [];
  assert.equal(calls.length, 2);
  assert.match(controller, /if \(\$platform === 'ctrip'\)/);
  assert.match(service, /'信息分'/);
  assert.match(service, /'友好度'/);
  assert.match(service, /'品质度'/);
  assert.match(service, /'欢迎度'/);
  assert.match(service, /'服务费'/);
  assert.match(service, /'official_formula_available' => false/);
  assert.match(service, /'composite_score' => null/);
  assert.match(service, /'automatic_ota_write' => false/);
  assert.match(service, /'automatic_pms_write' => false/);
});

test('schema v4 persists and integrity-binds the complete radar while rejecting stale radar contracts', () => {
  assert.match(persistence, /\$schemaVersion = is_array\(\$result\['operating_radar'\] \?\? null\) \? 4 : 2/);
  assert.match(persistence, /'operating_radar',/);
  assert.match(persistence, /if \(\$schemaVersion >= 3\)/);
  assert.match(persistence, /'operating_radar_digest'/);
  assert.match(persistence, /\[1, 2, 3, 4\]/);
  assert.match(persistence, /if \(\$schemaVersion >= 3 && is_array\(\$snapshot\['operating_radar'\]/);
  assert.match(persistence, /assertCtripOperatingRadarScope/);
  assert.match(persistence, /commission rate must not substitute for technical service fee/);
  assert.match(persistence, /non-blocked dimension requires a Ctrip channel root row/);
});
