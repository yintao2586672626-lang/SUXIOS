import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

test('manual online-data edits become unverified overrides and preserve null values', () => {
  const controller = read('app/controller/concern/OnlineDataRecordConcern.php');
  const frontend = read('public/app-main.js');

  assert.match(controller, /'ingestion_method'\] = 'manual_override'/);
  assert.match(controller, /'validation_status'\] = 'unverified'/);
  assert.match(controller, /'code' => 'manual_override_unverified'/);
  assert.match(controller, /'updated_fields' => \$updatedFields/);
  assert.match(controller, /复核前不进入可信收益/);
  assert.match(frontend, /amount: item\.amount \?\? null/);
  assert.match(frontend, /const payload = \{ id: onlineDataEditForm\.value\.id \}/);
  assert.match(frontend, /if \(current !== original\) payload\[field\] = current/);
  assert.doesNotMatch(frontend.slice(frontend.indexOf('const saveOnlineDataEdit'), frontend.indexOf('// 删除线上数据')), /JSON\.stringify\(onlineDataEditForm\.value\)/);
});

test('legacy Meituan AI entry is an unverified competitor preview, not a decision source', () => {
  const controller = read('app/controller/concern/OnlineDataRecordConcern.php');
  const frontend = read('public/ai-analysis-static.js');
  const template = read('resources/frontend/templates/fragments/26-page-meituan-ebooking.html');

  assert.match(controller, /'trust_status' => 'unverified_client_preview'/);
  assert.match(controller, /'metric_scope' => 'ota_competitor_sample'/);
  assert.match(controller, /'revenue_analysis' => false/);
  assert.match(controller, /'ai_decision_support' => false/);
  assert.match(controller, /\], false\);/);
  assert.doesNotMatch(controller, /floatval\(\$hotel\['roomNights'\] \?\? 0\)/);

  assert.match(frontend, /analysis_type: 'competitor_preview'/);
  assert.match(frontend, /include_suggestions: false/);
  assert.match(frontend, /roomNights: pickNullableNumber\(hotel\.roomNights\)/);
  assert.match(frontend, /isUnverifiedPreview/);
  assert.match(template, /竞对数据预览/);
  assert.match(template, /不生成本店经营、定价或运营建议/);
});
