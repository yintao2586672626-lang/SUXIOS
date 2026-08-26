import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');
const systemStatic = read('public/system-static.js');
const routes = readRouteContractSource();
const modelConfigTemplate = read('resources/frontend/templates/fragments/32-page-ai-model-config.html');
const governanceTemplate = read('resources/frontend/templates/fragments/33-page-ai-governance.html');

const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0, `missing start marker: ${startMarker}`);
  assert.ok(end > start, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};

const operatingQuestionFormSource = sliceBetween(
  appMain,
  'const createOperatingQuestionForm = () => ({',
  'const createOperatingQuestionState = () => ({',
);
const operatingQuestionProvideSource = sliceBetween(
  appMain,
  "provide('operatingQuestionUi', {",
  '// 加载Agent概览',
);
const modelConfigRuntime = `${sliceBetween(
  appMain,
  'const aiModelConfigs = ref([]);',
  "const aiGovernanceLoading = ref(false);",
)}\n${sliceBetween(
  appMain,
  'const loadAiModelConfigs = async () => {',
  'const openAiModelConfigModal = (model = null) => {',
)}`;
const governanceSaveFunction = sliceBetween(
  appMain,
  'const saveAiGovernanceEvaluationCase = async () => {',
  'const runAiGovernanceEvaluation = async (execute = false) => {',
);
const governanceRunFunction = sliceBetween(
  appMain,
  'const runAiGovernanceEvaluation = async (execute = false) => {',
  'const loadAiGovernance = async () => {',
);
const aiGovernanceRuntime = `${governanceSaveFunction}\n${governanceRunFunction}\n${systemStatic}`;
const operatingQuestionRequestAdapter = sliceBetween(
  component,
  'const request = (...args) => {',
  'const loadLocalAiCapabilities = async () => {',
);
const localCapabilityFunction = sliceBetween(
  component,
  'const loadLocalAiCapabilities = async () => {',
  'const setMediaFile = (file) => {',
);
const mediaFunction = sliceBetween(
  component,
  'const extractLocalMedia = async () => {',
  'const loadWecom = async () => {',
);
const councilReadbackMatcher = sliceBetween(
  appMain,
  'const operatingQuestionCouncilReadbackMatches = (exact, questionId, hotelId) => {',
  'const loadLatestOperatingQuestionCouncil = async (questionId) => {',
);
const latestCouncilFunction = sliceBetween(
  appMain,
  'const loadLatestOperatingQuestionCouncil = async (questionId) => {',
  'const runOperatingQuestionCouncil = async () => {',
);
const runCouncilFunction = sliceBetween(
  appMain,
  'const runOperatingQuestionCouncil = async () => {',
  'const loadOperatingQuestionHistory = async (options = {}) => {',
);
const councilRenderSource = sliceBetween(
  component,
  'const council = state.council_run || null;',
  'const actionDrafts = Array.isArray(result.answer?.action_drafts)',
);
const councilRuntime = `${councilReadbackMatcher}\n${latestCouncilFunction}\n${runCouncilFunction}`;
const operatingQuestionSecondBrain = [
  operatingQuestionFormSource,
  operatingQuestionProvideSource,
  operatingQuestionRequestAdapter,
  localCapabilityFunction,
  mediaFunction,
  councilRuntime,
  councilRenderSource,
].join('\n');

test('Ollama is available without an API key and local_second_brain is the UI default', () => {
  assert.match(modelConfigTemplate, /<option value="ollama">本机 Ollama（第二大脑）<\/option>/);
  assert.match(modelConfigTemplate, /v-if="aiQuickSetupForm\.provider !== 'ollama'"/);
  assert.match(modelConfigTemplate, /:disabled="aiQuickSetupForm\.provider === 'ollama'"/);
  assert.match(modelConfigTemplate, /http:\/\/127\.0\.0\.1:11434\/v1/);
  assert.match(systemStatic, /ollama:\s*\[[^\]\r\n]+\]/);
  assert.match(systemStatic, /本机 Ollama 固定使用 127\.0\.0\.1/);
  assert.match(appMain, /const aiQuickSetupForm = ref\(\{\s*provider:\s*'ollama'/);
  assert.match(appMain, /if \(provider !== 'ollama' && !apiKey\)/);
  assert.match(modelConfigRuntime, /request\('\/ai-config\/models'\)/);
  assert.match(modelConfigRuntime, /aiModelConfigs\.value = Array\.isArray\(res\.data\) \? res\.data : \[\]/);
  assert.match(modelConfigRuntime, /for \(const item of aiModelConfigs\.value \|\| \[\]\)/);
  assert.match(modelConfigRuntime, /options\.set\(item\.model_key, \{/);
  assert.match(operatingQuestionFormSource, /model_key:\s*'local_second_brain'/);
  assert.match(component, /\.\.\.textList\(ctx\.availableAiModelOptions\)\.filter/);
  assert.match(component, /value\.includes\('local_second_brain'\) \|\| label\.includes\('ollama'\)/);
  assert.match(component, /value: model\?\.value \|\| '',\s*label: model\?\.label \|\| model\?\.value \|\| '模型'/);
  assert.match(component, /form\.model_key = 'local_second_brain'/);
});

test('AI evaluation workbench saves, runs locally and performs exact GET readback', () => {
  assert.match(governanceTemplate, /data-testid="ai-evaluation-workbench"/);
  assert.match(governanceTemplate, /saveAiGovernanceEvaluationCase/);
  assert.match(governanceTemplate, /runAiGovernanceEvaluation\(false\)/);
  assert.match(governanceTemplate, /runAiGovernanceEvaluation\(true\)/);
  assert.match(governanceTemplate, /data-testid="ai-evaluation-run-readback"/);
  assert.match(aiGovernanceRuntime, /request\('\/ai-governance\/evaluation-cases',\s*\{/);
  assert.match(aiGovernanceRuntime, /request\('\/ai-governance\/evaluation-cases\/replay',\s*\{/);
  assert.match(aiGovernanceRuntime, /model_key:\s*'local_second_brain'/);
  assert.match(aiGovernanceRuntime, /allow_external_model_call:\s*false/);
  assert.match(aiGovernanceRuntime, /persistence_status !== 'readback_verified'/);
  assert.match(aiGovernanceRuntime, /request\(`\/ai-governance\/evaluation-runs\/\$\{runId\}`\)/);
  assert.match(aiGovernanceRuntime, /Number\(exact\.id \|\| 0\) !== caseId/);
  assert.match(aiGovernanceRuntime, /exact\.dry_run !== !execute/);
  assert.match(aiGovernanceRuntime, /exact\?\.result\?\.allow_external_model_call !== false/);
  assert.match(aiGovernanceRuntime, /String\(exact\.result_digest \|\| ''\) !== String\(savedRun\.result_digest \|\| ''\)/);
  assert.match(aiGovernanceRuntime, /exact\.readback_verified !== true/);
});

test('local capability and multipart media calls use the injected request and strict readback boundaries', () => {
  assert.match(operatingQuestionProvideSource, /provide\('operatingQuestionUi',[\s\S]{0,180}state:\s*operatingQuestionState,\s*request,/);
  assert.match(appMain, /const headers = typeof FormData !== 'undefined' && rawOptions\.body instanceof FormData\s*\? \{\}\s*:\s*\{ 'Content-Type': 'application\/json' \}/);
  assert.match(operatingQuestionRequestAdapter, /typeof ui\?\.request !== 'function'/);
  assert.match(localCapabilityFunction, /request\('\/agent\/local-ai\/capabilities'\)/);
  for (const marker of [
    /boundaries\?\.local_only !== true/,
    /boundaries\?\.external_message !== false/,
    /boundaries\?\.automatic_execution !== false/,
    /boundaries\?\.ota_write !== false/,
  ]) assert.match(localCapabilityFunction, marker);
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
  assert.match(latestCouncilFunction, /request\(`\/agent\/operating-questions\/\$\{id\}\/council-runs\/latest`\)/);
  assert.match(latestCouncilFunction, /if \(exact === null\)/);
  assert.doesNotMatch(latestCouncilFunction, /method:\s*'POST'/);
  assert.match(runCouncilFunction, /request\(`\/agent\/operating-questions\/\$\{questionId\}\/council-runs`, \{/);
  assert.match(runCouncilFunction, /saved\.persistence_status !== 'readback_verified'/);
  assert.match(runCouncilFunction, /String\(saved\.request_key \|\| ''\) !== `council:\$\{clientRunKey\}`/);
  assert.match(runCouncilFunction, /request\(`\/agent\/operating-questions\/\$\{questionId\}\/council-runs\/\$\{Number\(saved\.id \|\| 0\)\}`\)/);
  assert.match(runCouncilFunction, /String\(exact\.content_digest \|\| ''\) !== String\(saved\.content_digest \|\| ''\)/);
  assert.match(councilRuntime, /Number\(state\.result\?\.id \|\| 0\) !== id/);
  assert.match(councilReadbackMatcher, /boundaries\?\.action_creation_allowed === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.user_trigger_required === true/);
  assert.match(councilReadbackMatcher, /boundaries\?\.external_message === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.automatic_execution === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.ota_write === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.primary_answer_mutated === false/);
  assert.match(councilRenderSource, /data-testid': 'operating-question-council-readback/);
  assert.match(councilRenderSource, /data-testid': 'operating-question-council-run/);
  assert.match(councilRenderSource, /onClick: \(\) => ui\?\.runCouncil\?\.\(\)/);
  assert.match(councilRenderSource, /165视角经营顾问团/);
  assert.match(councilRenderSource, /由同一本机模型分别审视，不等于165位真人在线或独立专家共识/);
  assert.match(councilRenderSource, /只有你主动点击后才调用本机模型并保存回读/);
});

test('operating-question second-brain frontend excludes WeCom and independent action-review additions', () => {
  assert.doesNotMatch(operatingQuestionSecondBrain, /wecom_|wecom-inbound/i);
  assert.doesNotMatch(operatingQuestionSecondBrain, /AI 行动草案 · 独立评审|提交独立评审|独立评审并回读中|AI 独立评审已通过/);
});
