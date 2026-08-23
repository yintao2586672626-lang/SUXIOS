import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');
const systemStatic = read('public/system-static.js');
const routes = read('route/app.php');
const modelConfigTemplate = read('resources/frontend/templates/fragments/32-page-ai-model-config.html');
const governanceTemplate = read('resources/frontend/templates/fragments/33-page-ai-governance.html');

const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0, `missing start marker: ${startMarker}`);
  assert.ok(end > start, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};

const operatingQuestionMain = sliceBetween(
  appMain,
  'const createOperatingQuestionForm = () => ({',
  '// 加载Agent概览',
);
const secondBrainRuntime = `${appMain}\n${systemStatic}`;
const mediaFunction = sliceBetween(
  component,
  'const extractLocalMedia = async () => {',
  'onMounted(() => {',
);

test('Ollama is available without an API key and local_second_brain is the UI default', () => {
  assert.match(modelConfigTemplate, /<option value="ollama">本机 Ollama（第二大脑）<\/option>/);
  assert.match(modelConfigTemplate, /v-if="aiQuickSetupForm\.provider !== 'ollama'"/);
  assert.match(modelConfigTemplate, /:disabled="aiQuickSetupForm\.provider === 'ollama'"/);
  assert.match(modelConfigTemplate, /http:\/\/127\.0\.0\.1:11434\/v1/);
  assert.match(systemStatic, /ollama:\s*\['local_second_brain · qwen3:4b'\]/);
  assert.match(systemStatic, /本机 Ollama 固定使用 127\.0\.0\.1/);
  assert.match(appMain, /const aiQuickSetupForm = ref\(\{\s*provider:\s*'ollama'/);
  assert.match(appMain, /if \(provider !== 'ollama' && !apiKey\)/);
  assert.match(operatingQuestionMain, /model_key:\s*'local_second_brain'/);
  assert.match(component, /value:\s*'local_second_brain', label:\s*'本机第二大脑（Ollama）'/);
  assert.match(component, /form\.model_key = 'local_second_brain'/);
});

test('AI evaluation workbench saves, runs locally and performs exact GET readback', () => {
  assert.match(governanceTemplate, /data-testid="ai-evaluation-workbench"/);
  assert.match(governanceTemplate, /saveAiGovernanceEvaluationCase/);
  assert.match(governanceTemplate, /runAiGovernanceEvaluation\(false\)/);
  assert.match(governanceTemplate, /runAiGovernanceEvaluation\(true\)/);
  assert.match(governanceTemplate, /data-testid="ai-evaluation-run-readback"/);
  assert.match(secondBrainRuntime, /request\('\/ai-governance\/evaluation-cases',\s*\{/);
  assert.match(secondBrainRuntime, /request\('\/ai-governance\/evaluation-cases\/replay',\s*\{/);
  assert.match(secondBrainRuntime, /model_key:\s*'local_second_brain'/);
  assert.match(secondBrainRuntime, /allow_external_model_call:\s*false/);
  assert.match(secondBrainRuntime, /persistence_status !== 'readback_verified'/);
  assert.match(secondBrainRuntime, /request\(`\/ai-governance\/evaluation-runs\/\$\{runId\}`\)/);
  assert.match(secondBrainRuntime, /Number\(exact\.id \|\| 0\) !== caseId/);
  assert.match(secondBrainRuntime, /exact\.dry_run !== !execute/);
  assert.match(secondBrainRuntime, /exact\?\.result\?\.allow_external_model_call !== false/);
  assert.match(secondBrainRuntime, /String\(exact\.result_digest \|\| ''\) !== String\(savedRun\.result_digest \|\| ''\)/);
  assert.match(secondBrainRuntime, /exact\.readback_verified !== true/);
});

test('local capability and multipart media calls use the injected request and strict readback boundaries', () => {
  assert.match(operatingQuestionMain, /provide\('operatingQuestionUi',[\s\S]{0,180}state:\s*operatingQuestionState,\s*request,/);
  assert.match(appMain, /const headers = typeof FormData !== 'undefined' && rawOptions\.body instanceof FormData\s*\? \{\}\s*:\s*\{ 'Content-Type': 'application\/json' \}/);
  assert.match(component, /typeof ui\?\.request !== 'function'/);
  assert.match(component, /request\('\/agent\/local-ai\/capabilities'\)/);
  for (const marker of [
    /boundaries\?\.local_only !== true/,
    /boundaries\?\.external_message !== false/,
    /boundaries\?\.automatic_execution !== false/,
    /boundaries\?\.ota_write !== false/,
  ]) assert.match(component, marker);
  assert.match(component, /data-testid': 'local-ai-capability-status/);
  assert.match(component, /data-testid': 'local-media-file/);
  assert.match(component, /data-testid': 'local-media-extract/);
  assert.match(component, /data-testid': 'local-media-readback/);
  assert.match(mediaFunction, /const body = new FormData\(\)/);
  assert.match(mediaFunction, /body\.append\('hotel_id', String\(hotelId\)\)/);
  assert.match(mediaFunction, /body\.append\('file', file, file\.name\)/);
  assert.match(mediaFunction, /request\('\/agent\/local-media-extractions', \{ method: 'POST', body \}\)/);
  assert.doesNotMatch(mediaFunction, /content-type|Content-Type/);
  assert.match(mediaFunction, /request\(`\/agent\/local-media-extractions\/\$\{resultId\}`\)/);
  assert.match(mediaFunction, /source_sha256/);
  assert.match(mediaFunction, /content_digest/);
  assert.match(mediaFunction, /source_retention \|\| ''\) !== 'discarded_after_extraction'/);
  assert.match(mediaFunction, /source_file_retained !== false/);
  assert.match(mediaFunction, /hotel_fact_created !== false/);
  assert.match(mediaFunction, /currentHotelId\(\) !== hotelId/);
});

test('council stays explicitly triggered, local-only and exact-readback verified', () => {
  assert.ok(
    routes.indexOf("Route::get('/operating-questions/:id/council-runs/latest'")
      < routes.indexOf("Route::get('/operating-questions/:id',"),
    'the specific council readback route must precede the generic question route',
  );
  assert.ok(
    routes.indexOf("Route::post('/operating-questions/:id/council-runs'")
      < routes.indexOf("Route::get('/operating-questions/:id',"),
    'the specific council mutation route must precede the generic question route',
  );
  assert.match(secondBrainRuntime, /request\(`\/agent\/operating-questions\/\$\{id\}\/council-runs\/latest`\)/);
  assert.match(secondBrainRuntime, /const noSavedRun = exact == null/);
  assert.match(secondBrainRuntime, /Array\.isArray\(exact\) \? exact\.length === 0/);
  assert.match(secondBrainRuntime, /Object\.keys\(exact\)\.length === 0/);
  assert.match(secondBrainRuntime, /request\(`\/agent\/operating-questions\/\$\{questionId\}\/council-runs`, \{/);
  assert.match(secondBrainRuntime, /saved\.persistence_status !== 'readback_verified'/);
  assert.match(secondBrainRuntime, /String\(saved\.request_key \|\| ''\) !== `council:\$\{clientRunKey\}`/);
  assert.match(secondBrainRuntime, /String\(exact\.content_digest \|\| ''\) !== String\(saved\.content_digest \|\| ''\)/);
  assert.match(secondBrainRuntime, /Number\(state\.result\?\.id \|\| 0\) !== id/);
  assert.match(secondBrainRuntime, /boundaries\?\.action_creation_allowed !== false/);
  assert.match(secondBrainRuntime, /boundaries\?\.external_message !== false/);
  assert.match(secondBrainRuntime, /boundaries\?\.automatic_execution !== false/);
  assert.match(secondBrainRuntime, /boundaries\?\.ota_write !== false/);
  assert.match(secondBrainRuntime, /boundaries\?\.primary_answer_mutated !== false/);
  assert.match(component, /data-testid': 'operating-question-council-readback/);
  assert.match(component, /data-testid': 'operating-question-council-run/);
  assert.match(component, /onClick: \(\) => ui\?\.runCouncil\?\.\(\)/);
  assert.match(component, /本机多角色影子复核/);
  assert.match(component, /不代表三名独立专家，不覆盖主回答、不创建行动/);
  assert.match(component, /只有你主动点击后才调用本机模型并保存回读/);
});

test('operating-question second-brain frontend excludes WeCom and independent action-review additions', () => {
  assert.doesNotMatch(operatingQuestionMain, /wecom_|wecom-inbound/i);
  assert.doesNotMatch(component, /wecom_|wecom-inbound/i);
  assert.doesNotMatch(component, /AI 行动草案 · 独立评审|提交独立评审|独立评审并回读中|AI 独立评审已通过/);
});
