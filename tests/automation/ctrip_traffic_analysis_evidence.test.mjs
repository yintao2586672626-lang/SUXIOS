import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const staticSource = fs.readFileSync(new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const appMain = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const template = fs.readFileSync(new URL('../../resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', import.meta.url), 'utf8');

const modelStart = staticSource.indexOf('    const buildCtripTrafficResponseModel = ');
const modelEnd = staticSource.indexOf('    const runCtripTrafficFetchFlow = ', modelStart);
assert.ok(modelStart >= 0 && modelEnd > modelStart);
const buildModel = vm.runInNewContext(`${staticSource.slice(modelStart, modelEnd)}\nbuildCtripTrafficResponseModel`);

test('manual traffic response keeps partial analysis without inventing summary or advice', () => {
  const analysis = {
    status: 'partial', data_gaps: ['2026-07-29:competitor'], summary: null,
    rows: [], diagnosis: '证据不足', main_problem_stage: '证据不足', recommendations: [],
  };
  const model = buildModel({
    platform: 'ctrip', request_start_date: '2026-07-29', request_end_date: '2026-07-29',
    derived_analysis: analysis,
  });
  assert.equal(model.derivedAnalysis, analysis);
  assert.equal(model.onlineResult.derived_analysis, analysis);
  assert.equal(model.derivedAnalysis.summary, null);
  assert.deepEqual(Array.from(model.derivedAnalysis.recommendations), []);
});

test('analysis panel renders unavailable metrics as a dash while preserving diagnosis', () => {
  const start = appMain.indexOf('            const formatCtripTrafficAnalysisMetric = ');
  const end = appMain.indexOf('\n            };', start);
  assert.ok(start >= 0 && end > start);
  const format = vm.runInNewContext(`${appMain.slice(start, end + '\n            };'.length)}\nformatCtripTrafficAnalysisMetric`);
  assert.equal(format(null, { key: 'exposure_gap' }), '—');
  assert.match(template, /v-if="ctripTrafficAnalysis"[\s\S]*?\{\{ ctripTrafficAnalysis\.diagnosis \}\}/);
  assert.match(template, /\(ctripTrafficAnalysis\.recommendations \|\| \[\]\)\.join/);
});
