import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');
const componentLoader = read('public/components/system/operating-intelligence-loader.js');
const systemStatic = read('public/system-static.js');
const routes = readRouteContractSource();
const modelConfigTemplate = read('resources/frontend/templates/fragments/32-page-ai-model-config.html');
const governanceTemplate = read('resources/frontend/templates/fragments/33-page-ai-governance.html');
const acknowledgedWorkerFields = (overrides = {}) => {
  const parentDigest = String(overrides.parentDigest || 'a'.repeat(64));
  const generation = Number(overrides.generation || 1);
  const dispatchAttemptId = String(overrides.dispatchAttemptId || 'd'.repeat(32));
  return {
    worker_dispatched: true,
    dispatch_parent_digest: parentDigest,
    dispatch_attempt_id: dispatchAttemptId,
    expected_lease_generation: generation,
    worker_receipt: {
      status: 'acknowledged',
      acknowledged: true,
      parent_digest: parentDigest,
      dispatch_parent_digest: parentDigest,
      dispatch_attempt_id: dispatchAttemptId,
      lease_generation: generation,
      expected_lease_generation: generation,
      exit_code: null,
      persisted: true,
      ...(overrides.receipt || {}),
    },
  };
};

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
  'const operatingQuestionForm = ref(createOperatingQuestionForm());',
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
  assert.match(routes, /operating-questions\/:id\/council-runs\/:runId\/resume/);
  assert.match(runCouncilFunction, /SUXI_OPERATING_INTELLIGENCE_COMPONENTS\.submitCouncilRun/);
  assert.match(runCouncilFunction, /request\(`\/agent\/operating-questions\/\$\{questionId\}\/council-runs\/\$\{Number\(saved\.id \|\| 0\)\}`\)/);
  assert.match(runCouncilFunction, /String\(exact\.request_key \|\| ''\) !== String\(saved\.request_key \|\| ''\)/);
  assert.match(runCouncilFunction, /SUXI_OPERATING_INTELLIGENCE_COMPONENTS\.pollCouncilRun/);
  assert.match(runCouncilFunction, /const generation = \+\+state\.council_generation/);
  assert.match(runCouncilFunction, /if \(!isCurrent\(\)\) return null/);
  assert.match(runCouncilFunction, /if \(isCurrent\(\)\) state\.council_loading = false/);
  assert.match(latestCouncilFunction, /const generation = \+\+state\.council_generation/);
  assert.match(appMain, /state\.council_generation \+= 1; state\.council_loading = false; state\.result = exact/);
  assert.match(componentLoader, /saved\?\.accepted !== true/);
  assert.match(componentLoader, /saved\?\.persistence_status !== 'readback_verified'/);
  assert.match(componentLoader, /receipt\.acknowledged === true/);
  assert.match(componentLoader, /receipt\.status === 'already_running'/);
  assert.match(componentLoader, /saved\?\.dispatch_parent_digest/);
  assert.match(componentLoader, /saved\?\.dispatch_attempt_id/);
  assert.match(componentLoader, /expected_lease_generation/);
  assert.match(componentLoader, /const cleanExit = exitCode == null \|\| exitCode === 0/);
  assert.match(componentLoader, /'partial', 'failed', 'blocked_by_missing_facts', 'blocked_not_configured'/);
  assert.match(componentLoader, /\['pending', 'running'\]\.includes\(currentStatus\)/);
  assert.match(componentLoader, /pollOnly: true/);
  assert.match(componentLoader, /council_terminal_fact_drift/);
  assert.match(componentLoader, /council-runs\/\$\{Number\(state\.council_run\.id \|\| 0\)\}\/resume/);
  assert.match(componentLoader, /const councilTerminalStatuses = new Set/);
  assert.match(componentLoader, /const pollCouncilRun = async/);
  assert.match(componentLoader, /10 \* 60 \* 1000/);
  assert.match(componentLoader, /attempt < 5 \? 1000 : \(attempt < 20 \? 2000 : 5000\)/);
  assert.match(componentLoader, /dispatch_failed/);
  assert.match(componentLoader, /Date\.now\(\) - unchangedSince >= 150000/);
  assert.match(componentLoader, /replace\(\/\^council:\/, ''\)/);
  assert.match(componentLoader, /checkpoint 身份或范围回读不一致/);
  assert.match(councilRuntime, /state\.council_generation === generation/);
  assert.match(councilReadbackMatcher, /boundaries\?\.action_creation_allowed === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.user_trigger_required === true/);
  assert.match(councilReadbackMatcher, /boundaries\?\.external_message === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.automatic_execution === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.ota_write === false/);
  assert.match(councilReadbackMatcher, /boundaries\?\.primary_answer_mutated === false/);
  assert.match(councilRenderSource, /data-testid': 'operating-question-council-readback/);
  assert.match(councilRenderSource, /data-testid': 'operating-question-council-run/);
  assert.match(councilRenderSource, /onClick: \(\) => ui\?\.runCouncil\?\.\(\)/);
  assert.match(councilRenderSource, /继续未完成会诊/);
  assert.match(councilRenderSource, /继续查看进度/);
  assert.match(councilRenderSource, /const councilRenderable/);
  assert.match(councilRenderSource, /请重新生成事实\/问题/);
  assert.match(councilRenderSource, /165视角经营顾问团/);
  assert.match(councilRenderSource, /由同一本机模型分别审视，不等于165位真人在线或独立专家共识/);
  assert.match(councilRenderSource, /只有你主动点击后才调用本机模型并保存回读/);
});

test('council polling follows exact pending checkpoints to one terminal readback', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array });
  const poll = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.pollCouncilRun;
  const state = { result: { id: 41, hotel_id: 20 }, council_run: null, council_error: '' };
  const responses = [
    { status: 'running', synthesis: { worker: { status: 'running' } } },
    { status: 'completed', synthesis: { worker: { status: 'completed' } } },
  ];
  let calls = 0;
  const result = await poll({
    exact: { id: 7, question_id: 41, hotel_id: 20, request_key: 'council:key', status: 'pending', synthesis: { worker: { status: 'queued' } } },
    questionId: 41,
    runId: 7,
    requestKey: 'council:key',
    state,
    request: async () => ({
      code: 200,
      data: { id: 7, question_id: 41, hotel_id: 20, request_key: 'council:key', ...responses[calls++] },
    }),
    matches: exact => Number(exact.question_id) === 41 && Number(exact.hotel_id) === 20,
    isCurrent: () => true,
  });
  assert.equal(calls, 2);
  assert.equal(result.status, 'completed');
  assert.equal(state.council_run.status, 'completed');
  assert.equal(state.council_error, '');
});

test('council polling redispatches the same reserved run after a stale checkpoint', async () => {
  let now = 0;
  const DateStub = { now: () => (now += 80000) };
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date: DateStub, Error, Number, String, Object, Array, JSON });
  const poll = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.pollCouncilRun;
  const state = { result: { id: 41, hotel_id: 20 }, council_run: null, council_error: '' };
  let getCalls = 0;
  let postCalls = 0;
  const result = await poll({
    exact: { id: 7, question_id: 41, hotel_id: 20, request_key: 'council:council:key', content_digest: 'a'.repeat(64), status: 'pending', synthesis: { worker: { status: 'queued' } } },
    questionId: 41,
    runId: 7,
    requestKey: 'council:council:key',
    state,
    request: async (url, options = {}) => {
      if (options.method === 'POST') {
        postCalls++;
        assert.equal(JSON.parse(options.body).client_run_key, 'council:key');
        return {
          code: 200,
          data: {
            id: 7,
            request_key: 'council:council:key',
            accepted: true,
            persistence_status: 'readback_verified',
            ...acknowledgedWorkerFields(),
          },
        };
      }
      getCalls++;
      return {
        code: 200,
        data: {
          id: 7,
          question_id: 41,
          hotel_id: 20,
          request_key: 'council:council:key',
          content_digest: getCalls === 1 ? 'a'.repeat(64) : 'b'.repeat(64),
          status: getCalls === 1 ? 'pending' : 'completed',
          synthesis: { worker: { status: getCalls === 1 ? 'queued' : 'completed' } },
        },
      };
    },
    matches: exact => Number(exact.question_id) === 41 && Number(exact.hotel_id) === 20,
    isCurrent: () => true,
  });
  assert.equal(postCalls, 1);
  assert.equal(getCalls, 2);
  assert.equal(result.status, 'completed');
});

test('old council poll cannot mutate state after a same-hotel question generation switch', async () => {
  let releaseReadback;
  const delayedReadback = new Promise(resolve => { releaseReadback = resolve; });
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const poll = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.pollCouncilRun;
  const state = {
    result: { id: 41, hotel_id: 20 },
    council_generation: 3,
    council_run: { id: 99, status: 'sentinel' },
    council_error: 'sentinel-error',
  };
  const isCurrent = () => state.council_generation === 3 && Number(state.result?.id || 0) === 41;
  const pending = poll({
    exact: { id: 7, question_id: 41, hotel_id: 20, request_key: 'council:key', content_digest: 'a'.repeat(64), status: 'pending', synthesis: { worker: { status: 'running' } } },
    questionId: 41,
    runId: 7,
    requestKey: 'council:key',
    state,
    request: async () => delayedReadback,
    matches: () => true,
    isCurrent,
  });
  await Promise.resolve();
  state.result = { id: 42, hotel_id: 20 };
  state.council_generation = 4;
  releaseReadback({
    code: 200,
    data: { id: 7, question_id: 41, hotel_id: 20, request_key: 'council:key', content_digest: 'b'.repeat(64), status: 'completed', synthesis: { worker: { status: 'completed' } } },
  });
  assert.equal(await pending, null);
  assert.deepEqual(state.council_run, { id: 99, status: 'sentinel' });
  assert.equal(state.council_error, 'sentinel-error');
});

test('partial council uses the authenticated same-run resume endpoint and requires worker ACK', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const submit = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.submitCouncilRun;
  const state = { council_run: { id: 7, question_id: 41, status: 'partial' } };
  let requestedUrl = '';
  const submitted = await submit({
    questionId: 41,
    clientRunKey: 'unused-new-key',
    state,
    request: async (url, options) => {
      requestedUrl = url;
      assert.equal(options.method, 'POST');
      assert.equal(options.body, undefined);
      return {
        code: 200,
        data: {
          id: 7,
          question_id: 41,
          accepted: true,
          persistence_status: 'readback_verified',
          ...acknowledgedWorkerFields(),
        },
      };
    },
    isCurrent: () => true,
  });
  assert.equal(requestedUrl, '/agent/operating-questions/41/council-runs/7/resume');
  assert.equal(submitted.resumed, true);
  assert.equal(submitted.saved.id, 7);
});

test('all backend-resumable council terminal states use the same authenticated run', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const submit = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.submitCouncilRun;
  for (const status of ['partial', 'failed', 'blocked_by_missing_facts', 'blocked_not_configured']) {
    let requestedUrl = '';
    const result = await submit({
      questionId: 41,
      clientRunKey: 'unused-key',
      state: { council_run: { id: 7, question_id: 41, status } },
      isCurrent: () => true,
      request: async (url) => {
        requestedUrl = url;
        return {
          code: 200,
          data: {
            id: 7,
            question_id: 41,
            accepted: true,
            persistence_status: 'readback_verified',
            ...acknowledgedWorkerFields(),
          },
        };
      },
    });
    assert.equal(requestedUrl, '/agent/operating-questions/41/council-runs/7/resume', status);
    assert.equal(result.resumed, true, status);
  }
});

test('an already active council polls its exact run without posting a new client key', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const submit = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.submitCouncilRun;
  let requests = 0;
  const active = { id: 7, question_id: 41, request_key: 'council:original', status: 'running' };
  const result = await submit({
    questionId: 41,
    clientRunKey: 'must-not-be-posted',
    state: { council_run: active },
    isCurrent: () => true,
    request: async () => { requests++; return { code: 500 }; },
  });
  assert.equal(requests, 0);
  assert.equal(result.pollOnly, true);
  assert.equal(result.reusedActive, true);
  assert.equal(result.saved.request_key, 'council:original');
});

test('terminal fact drift refuses same-run resume and asks for a new upstream question', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const submit = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.submitCouncilRun;
  let requests = 0;
  await assert.rejects(() => submit({
    questionId: 41,
    clientRunKey: 'must-not-be-posted',
    state: {
      council_run: {
        id: 7,
        question_id: 41,
        status: 'blocked_by_missing_facts',
        synthesis: { error_code: 'council_terminal_fact_drift' },
      },
    },
    isCurrent: () => true,
    request: async () => { requests++; return { code: 500 }; },
  }), /重新生成上游事实或经营问题/);
  assert.equal(requests, 0);
});

test('council readback rejects leaked quarantine content and unverified terminal artifacts', () => {
  const window = {};
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON, Map });
  const context = { Map, Number, String, Array, window };
  vm.runInNewContext(`${councilReadbackMatcher}\nthis.matchesCouncil = operatingQuestionCouncilReadbackMatches;`, context);
  const panelDigest = 'a'.repeat(64);
  const lensDigest = 'b'.repeat(64);
  const base = {
    id: 7,
    question_id: 41,
    hotel_id: 20,
    content_digest: 'c'.repeat(64),
    decision_effect: 'none',
    boundaries: {
      action_creation_allowed: false,
      user_trigger_required: true,
      external_message: false,
      automatic_execution: false,
      ota_write: false,
      primary_answer_mutated: false,
      real_human_consensus: false,
      source_skills_installed: false,
    },
  };
  const member = {
    key: 'evidence',
    panel_contract_digest: panelDigest,
    lens_contract_digest: lensDigest,
  };
  const completed = {
    ...base,
    status: 'completed',
    members: [member],
    evidence_refs: ['online_daily_data#1'],
    model_meta: [{}],
    synthesis: {
      advisory_source: {
        source_entry_count: 165,
        outer_zip_sha256: '32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970',
      },
      advisory_panel_contract_digest: panelDigest,
      selected_lenses: [
        { key: 'evidence', contract_digest: lensDigest },
        { key: 'risk', contract_digest: 'd'.repeat(64) },
      ],
    },
  };
  assert.equal(context.matchesCouncil(completed, 41, 20), false);
  completed.synthesis.artifact_integrity = {
    status: 'verified',
    panel_contract_digest: panelDigest,
    members_digest: 'e'.repeat(64),
    member_count: 1,
    evidence_refs_digest: 'f'.repeat(64),
    evidence_ref_count: 1,
    model_meta_count: 1,
  };
  assert.equal(context.matchesCouncil(completed, 41, 20), true);

  const leakedQuarantine = {
    ...base,
    status: 'blocked_by_missing_facts',
    members: [member],
    evidence_refs: [],
    model_meta: [],
    synthesis: { quarantine: { content_retained: false } },
  };
  assert.equal(context.matchesCouncil(leakedQuarantine, 41, 20), false);
  leakedQuarantine.members = [];
  assert.equal(context.matchesCouncil(leakedQuarantine, 41, 20), true);
});

test('worker receipt rejects exit-seven and parent drift but accepts an identified existing worker', async () => {
  const window = { setTimeout: callback => callback() };
  vm.runInNewContext(componentLoader, { window, URL, Promise, Set, Date, Error, Number, String, Object, Array, JSON });
  const submit = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.submitCouncilRun;
  const run = async (data) => submit({
    questionId: 41,
    clientRunKey: 'receipt-key',
    state: { council_run: null },
    isCurrent: () => true,
    request: async () => ({ code: 200, data }),
  });
  const base = {
    id: 7,
    question_id: 41,
    accepted: true,
    persistence_status: 'readback_verified',
  };
  await assert.rejects(() => run({
    ...base,
    ...acknowledgedWorkerFields({ receipt: { exit_code: 7 } }),
  }), /匹配本次派发/);
  await assert.rejects(() => run({
    ...base,
    ...acknowledgedWorkerFields({ receipt: { parent_digest: 'b'.repeat(64) } }),
  }), /匹配本次派发/);

  const parentDigest = 'c'.repeat(64);
  const dispatchAttemptId = 'e'.repeat(32);
  const existing = await run({
    ...base,
    worker_dispatched: false,
    dispatch_parent_digest: parentDigest,
    dispatch_attempt_id: dispatchAttemptId,
    expected_lease_generation: 3,
    worker_receipt: {
      status: 'already_running',
      acknowledged: false,
      existing_active_worker: true,
      parent_digest: parentDigest,
      dispatch_attempt_id: dispatchAttemptId,
      lease_generation: 3,
      exit_code: null,
      persisted: true,
    },
  });
  assert.equal(existing.saved.worker_dispatched, false);
  assert.equal(existing.saved.worker_receipt.status, 'already_running');
});

test('operating-question second-brain frontend excludes WeCom and independent action-review additions', () => {
  assert.doesNotMatch(operatingQuestionSecondBrain, /wecom_|wecom-inbound/i);
  assert.doesNotMatch(operatingQuestionSecondBrain, /AI 行动草案 · 独立评审|提交独立评审|独立评审并回读中|AI 独立评审已通过/);
});
