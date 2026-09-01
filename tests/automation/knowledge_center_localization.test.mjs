import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = [
  readFileSync('public/components/system/knowledge-center-domain.js', 'utf8'),
  readFileSync('public/app-main.js', 'utf8'),
].join('\n');
const pageTemplate = readFileSync('resources/frontend/templates/fragments/20-page-knowledge-center.html', 'utf8');
const dialogTemplate = readFileSync('resources/frontend/templates/fragments/38-dialogs-knowledge-center.html', 'utf8');
const tailwindCss = readFileSync('public/tailwind.min.css', 'utf8');

test('knowledge center keeps internal codes but presents Chinese business labels', () => {
  assert.match(appMain, /external_public_reference_reviewed:\s*'外部公开资料·已复核'/);
  assert.match(appMain, /collection_unverified:\s*'采集结果未验证'/);
  assert.match(appMain, /manual_review_only:\s*'仅限人工复核'/);
  assert.match(appMain, /ota_channel:\s*'OTA渠道范围'/);
  assert.match(appMain, /vanilla_kd:\s*'通用知识蒸馏'/);
  assert.match(appMain, /const knowledgeCenterTagGroups = \(value\) =>/);
  assert.match(pageTemplate, /knowledgeCenterTagGroups\(unit\.tags\)/);
  assert.match(pageTemplate, />来源与边界</);
  assert.doesNotMatch(pageTemplate, />\{\{ tag \}\}<\/span>/);
});

test('knowledge chunk details localize evidence, scope, platform and type codes', () => {
  assert.match(appMain, /label: '平台', value: knowledgeCenterDisplayList/);
  assert.match(appMain, /label: '证据级别', value: knowledgeCenterDisplayLabel/);
  assert.match(appMain, /label: '适用边界', value: knowledgeCenterDisplayLabel/);
  assert.match(dialogTemplate, /knowledgeCenterDisplayLabel\(chunk\.type \|\| 'manual'/);
});

test('knowledge center status chips only use selectors shipped by production CSS', () => {
  [
    'border-yellow-200',
    'bg-yellow-50',
    'text-yellow-800',
    'border-blue-200',
    'bg-blue-50',
    'text-blue-700',
    'border-indigo-200',
    'bg-indigo-50',
    'text-indigo-700',
    'border-purple-200',
    'bg-purple-50',
    'text-purple-700',
    'border-gray-200',
    'bg-gray-50',
    'text-gray-600',
  ].forEach((className) => {
    assert.ok(tailwindCss.includes(`.${className}{`), `${className} must exist in production CSS`);
  });
});
