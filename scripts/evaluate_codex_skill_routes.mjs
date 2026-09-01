#!/usr/bin/env node

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
  publicPath,
  readCodexVersion,
  resolveCodexExecutable,
} from './lib/codex_cli_runtime.mjs';

const SCHEMA = 'suxi.codex-skill-route-eval.v1';
const DEFAULT_MODEL = 'gpt-5.5';
const DEFAULT_REASONING_EFFORT = 'low';
const DEFAULT_RUNS = 2;
const DEFAULT_TIMEOUT_MS = 240_000;
const MAX_RUNS = 10;
const SCRIPT_PATH = fileURLToPath(import.meta.url);
const SCRIPT_DIRECTORY = path.dirname(SCRIPT_PATH);
const DEFAULT_AUDIT_SCRIPT = path.join(SCRIPT_DIRECTORY, 'audit_codex_skill_budget.mjs');
const TOOL_ITEM_TYPES = new Set([
  'command_execution',
  'computer_use',
  'file_search',
  'image_generation',
  'mcp_tool_call',
  'tool_call',
  'web_search',
]);
const REASONING_EFFORTS = new Set([
  'none',
  'minimal',
  'low',
  'medium',
  'high',
  'xhigh',
  'max',
  'ultra',
]);
const EXPLICIT_SKILL_MENTION_PATTERN = /\$[a-z0-9][a-z0-9_-]*/giu;

function requireValue(argv, index, argument) {
  const value = argv[index + 1];
  if (typeof value !== 'string' || !value.trim() || value.startsWith('--')) {
    throw new Error(`${argument} requires a non-empty value`);
  }
  return value;
}

export function parseArgs(argv) {
  const options = {
    allowCatalogGaps: false,
    auditScript: DEFAULT_AUDIT_SCRIPT,
    codex: null,
    cwd: null,
    details: false,
    evalPath: null,
    help: false,
    model: DEFAULT_MODEL,
    reasoningEffort: DEFAULT_REASONING_EFFORT,
    runs: DEFAULT_RUNS,
    selfTest: false,
    timeoutMs: DEFAULT_TIMEOUT_MS,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--help' || argument === '-h') {
      options.help = true;
    } else if (argument === '--allow-catalog-gaps') {
      options.allowCatalogGaps = true;
    } else if (argument === '--details') {
      options.details = true;
    } else if (argument === '--self-test') {
      options.selfTest = true;
    } else if (argument === '--audit-script') {
      options.auditScript = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--codex') {
      options.codex = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--cwd') {
      options.cwd = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--eval') {
      options.evalPath = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--model') {
      options.model = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--reasoning-effort') {
      options.reasoningEffort = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--runs') {
      options.runs = Number.parseInt(requireValue(argv, index, argument), 10);
      index += 1;
    } else if (argument === '--timeout-ms') {
      options.timeoutMs = Number.parseInt(requireValue(argv, index, argument), 10);
      index += 1;
    } else {
      throw new Error(`Unknown argument: ${argument}`);
    }
  }

  if (!Number.isInteger(options.runs) || options.runs < 2 || options.runs > MAX_RUNS) {
    throw new Error(`--runs must be an integer between 2 and ${MAX_RUNS}`);
  }
  if (!Number.isInteger(options.timeoutMs) || options.timeoutMs < 10_000) {
    throw new Error('--timeout-ms must be an integer of at least 10000');
  }
  if (!REASONING_EFFORTS.has(options.reasoningEffort)) {
    throw new Error(`Unsupported --reasoning-effort: ${options.reasoningEffort}`);
  }
  if (!options.model.trim()) {
    throw new Error('--model must be non-empty');
  }
  if (!options.help && !options.selfTest && !options.evalPath) {
    throw new Error('--eval is required unless --help or --self-test is used');
  }

  return options;
}

function printHelp() {
  process.stdout.write('Evaluate implicit, description-based Codex Skill routing.\n\n');
  process.stdout.write('Usage: node scripts/evaluate_codex_skill_routes.mjs --eval <trigger-evals.json> [options]\n\n');
  process.stdout.write('Options:\n');
  process.stdout.write('  --cwd <directory>       Project cwd used to resolve model-visible Skills. Default: current directory.\n');
  process.stdout.write(`  --runs <count>           Independent ephemeral sessions. Default: ${DEFAULT_RUNS}; range: 2-${MAX_RUNS}.\n`);
  process.stdout.write(`  --model <model>          Exact evaluator model. Default: ${DEFAULT_MODEL}.\n`);
  process.stdout.write(`  --reasoning-effort <v>   Codex reasoning effort. Default: ${DEFAULT_REASONING_EFFORT}.\n`);
  process.stdout.write(`  --timeout-ms <ms>        Timeout for each Codex process. Default: ${DEFAULT_TIMEOUT_MS}.\n`);
  process.stdout.write('  --allow-catalog-gaps     Exit 0 for stable current-catalog routing while still reporting gaps.\n');
  process.stdout.write('  --codex <executable>     Override the Codex executable.\n');
  process.stdout.write('  --audit-script <file>    Override the Skill budget audit script.\n');
  process.stdout.write('  --details                Include absolute paths, candidates, queries, and normalized predictions.\n');
  process.stdout.write('  --self-test              Run offline scoring, stability, privacy, and gap-policy tests.\n');
  process.stdout.write('  -h, --help               Show this help.\n');
}

function samePath(left, right) {
  const normalizedLeft = path.resolve(left);
  const normalizedRight = path.resolve(right);
  if (process.platform === 'win32') {
    return normalizedLeft.toLocaleLowerCase() === normalizedRight.toLocaleLowerCase();
  }
  return normalizedLeft === normalizedRight;
}

function resolveInputs(options) {
  const cwd = path.resolve(options.cwd ?? process.cwd());
  if (!fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
    throw new Error(`--cwd is not a directory: ${cwd}`);
  }
  const evalPath = path.resolve(cwd, options.evalPath);
  if (!fs.existsSync(evalPath) || !fs.statSync(evalPath).isFile()) {
    throw new Error(`--eval is not a file: ${evalPath}`);
  }
  const auditScript = path.resolve(options.auditScript);
  if (!fs.existsSync(auditScript) || !fs.statSync(auditScript).isFile()) {
    throw new Error(`--audit-script is not a file: ${auditScript}`);
  }
  return { auditScript, cwd, evalPath };
}

function readEvalDocument(evalPath) {
  let document;
  try {
    document = JSON.parse(fs.readFileSync(evalPath, 'utf8'));
  } catch (error) {
    throw new Error(`Unable to parse trigger eval JSON: ${error.message}`);
  }
  if (!document || typeof document !== 'object') {
    throw new Error('Trigger eval root must be an object');
  }
  if (typeof document.skill_name !== 'string' || !document.skill_name.trim()) {
    throw new Error('Trigger eval skill_name must be a non-empty string');
  }
  if (!Array.isArray(document.evals) || document.evals.length === 0) {
    throw new Error('Trigger eval evals must be a non-empty array');
  }

  const ids = new Set();
  const queries = new Set();
  for (const [index, row] of document.evals.entries()) {
    if (typeof row?.id !== 'string' || !row.id.trim()) {
      throw new Error(`Eval row ${index + 1} has no id`);
    }
    if (ids.has(row.id)) throw new Error(`Duplicate eval id: ${row.id}`);
    ids.add(row.id);
    if (typeof row.query !== 'string' || !row.query.trim()) {
      throw new Error(`Eval ${row.id} has no query`);
    }
    if (queries.has(row.query)) throw new Error(`Duplicate eval query: ${row.id}`);
    queries.add(row.query);
    if (typeof row.should_trigger !== 'boolean') {
      throw new Error(`Eval ${row.id} should_trigger must be boolean`);
    }
    if (!row.should_trigger && (typeof row.expected_route !== 'string' || !row.expected_route.trim())) {
      throw new Error(`Negative eval ${row.id} must name expected_route`);
    }
  }
  assertImplicitEvalDocument(document);
  return document;
}

export function assertImplicitEvalDocument(document) {
  for (const row of document.evals) {
    const explicitMentions = row.query.match(EXPLICIT_SKILL_MENTION_PATTERN) ?? [];
    if (explicitMentions.length > 0) {
      throw new Error(
        `Implicit route evaluator cannot score explicit Skill mention in ${row.id}: ${explicitMentions.join(', ')}`,
      );
    }
    if (!Object.hasOwn(row, 'excluded_explicit_route')) continue;
    if (typeof row.excluded_explicit_route !== 'string' || !row.excluded_explicit_route.trim()) {
      throw new Error(`Eval ${row.id} excluded_explicit_route must be a non-empty string`);
    }
    if (row.should_trigger || row.expected_route !== 'none') {
      throw new Error(
        `Eval ${row.id} excluded_explicit_route requires should_trigger=false and expected_route=none`,
      );
    }
  }
}

function requestedRoutesFor(document) {
  return new Set([
    document.skill_name,
    ...document.evals
      .filter((row) => !row.should_trigger && row.expected_route !== 'none')
      .map((row) => row.expected_route),
  ]);
}

export function normalizeCatalogRoute(catalogRoute, requestedRoutes) {
  if (catalogRoute === 'none') return 'none';
  if (requestedRoutes.has(catalogRoute)) return catalogRoute;
  const matches = [...requestedRoutes].filter((route) => catalogRoute.endsWith(`:${route}`));
  return matches.length === 1 ? matches[0] : null;
}

function runSkillAudit(inputs, requestedRoutes, codexInvocation, timeoutMs) {
  const args = [inputs.auditScript, '--cwd', inputs.cwd];
  if (path.isAbsolute(codexInvocation.source) && fs.existsSync(codexInvocation.source)) {
    args.push('--codex', codexInvocation.source);
  }
  for (const route of requestedRoutes) args.push('--match', route);

  const result = spawnSync(process.execPath, args, {
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    timeout: timeoutMs,
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `Skill budget audit exited ${result.status}; stdoutChars=${result.stdout.length}; stderrChars=${result.stderr.length}`,
    );
  }
  let report;
  try {
    report = JSON.parse(result.stdout);
  } catch (error) {
    throw new Error(`Unable to parse Skill budget audit JSON: ${error.message}`);
  }
  if (report.status !== 'ok' || report.readOnly !== true || !report.matchedSkills) {
    throw new Error('Skill budget audit did not return a read-only matchedSkills report');
  }
  return report;
}

function buildCandidates(auditReport, requestedRoutes) {
  const candidates = new Map();
  for (const entry of auditReport.matchedSkills.skills) {
    const normalizedRoute = normalizeCatalogRoute(entry.name, requestedRoutes);
    if (!normalizedRoute) continue;
    if (typeof entry.effectiveDescription !== 'string' || !entry.effectiveDescription.trim()) {
      throw new Error(`Model-visible entry has no effective description: ${entry.name}`);
    }
    candidates.set(entry.name, {
      alias: entry.alias,
      catalogRoute: entry.name,
      effectiveDescription: entry.effectiveDescription,
      effectiveDescriptionChars: entry.effectiveDescriptionChars,
      normalizedRoute,
      rootKind: entry.rootKind,
    });
  }
  return [...candidates.values()].sort((left, right) => (
    left.catalogRoute.localeCompare(right.catalogRoute)
  ));
}

export function buildCatalogPlan(document, candidates) {
  const requestedRoutes = requestedRoutesFor(document);
  const availableRoutes = new Set(candidates.map((candidate) => candidate.normalizedRoute));
  if (!availableRoutes.has(document.skill_name)) {
    throw new Error(`Target Skill is not model-visible: ${document.skill_name}`);
  }

  const gaps = [];
  const expectedCurrentCatalog = new Map();
  for (const row of document.evals) {
    const intendedRoute = row.should_trigger ? document.skill_name : row.expected_route;
    const missing = intendedRoute !== 'none' && !availableRoutes.has(intendedRoute);
    if (missing) {
      gaps.push({
        id: row.id,
        intendedRoute,
        query: row.query,
        scoringFallback: 'none',
      });
    }
    expectedCurrentCatalog.set(row.id, missing ? 'none' : intendedRoute);
  }

  const coveredCases = document.evals.length - gaps.length;
  return {
    availableRoutes,
    evalCaseCatalogCoveragePercent: Number(((100 * coveredCases) / document.evals.length).toFixed(2)),
    coveredCases,
    expectedCurrentCatalog,
    gaps,
    requestedRoutes,
    totalCases: document.evals.length,
  };
}

function buildPrompt(document, candidates) {
  const publicCandidates = candidates.map((candidate) => ({
    catalog_route: candidate.catalogRoute,
    effective_description: candidate.effectiveDescription,
  }));
  const queries = document.evals.map((row) => ({ id: row.id, query: row.query }));
  return [
    '你只做技能路由分类，不调用任何工具，不解释过程。下面是 Codex 当前模型可见的真实技能短描述；不同 catalog_route 可能是同一能力的别名。',
    '请为每条 query 选择唯一最匹配的 catalog_route；没有任何候选匹配时选择 none。不要猜测隐藏答案，只依据 query 与 effective_description。',
    `候选：${JSON.stringify(publicCandidates)}`,
    `待分类：${JSON.stringify(queries)}`,
    '只输出严格 JSON，格式为 {"predictions":[{"id":"P-001","catalog_route":"route-name"}]}。必须覆盖全部 id，保持输入顺序，不要 Markdown。',
  ].join(' ');
}

function parseJsonEvents(stdout) {
  const events = [];
  for (const line of stdout.split(/\r?\n/u)) {
    if (!line.trim()) continue;
    try {
      events.push(JSON.parse(line));
    } catch (error) {
      throw new Error(`Invalid Codex JSON event: ${error.message}`);
    }
  }
  return events;
}

function runtimeWarningSummary(stderr) {
  const lines = stderr.split(/\r?\n/u).map((line) => line.trim()).filter(Boolean);
  return {
    modelCacheWarning: lines.some((line) => line.includes('models cache') || line.includes('base_instructions')),
    networkWarning: lines.some((line) => line.includes('request') && (line.includes('failed') || line.includes('error'))),
    remotePluginCatalogWarning: lines.some((line) => line.includes('remote plugin catalog')),
    shellSnapshotWarning: lines.some((line) => line.includes('shell snapshot')),
    skillDescriptionsShortenedObserved: lines.some((line) => line.includes('Skill descriptions were shortened')),
    warningLineCount: lines.filter((line) => /\bWARN\b|\bERROR\b|^warning:/u.test(line)).length,
  };
}

function validatePredictions(predictions, document, candidates) {
  if (!Array.isArray(predictions)) throw new Error('Classifier predictions must be an array');
  if (predictions.length !== document.evals.length) {
    throw new Error(`Expected ${document.evals.length} predictions, got ${predictions.length}`);
  }
  const expectedIds = new Set(document.evals.map((row) => row.id));
  const seenIds = new Set();
  const validCatalogRoutes = new Set(['none', ...candidates.map((candidate) => candidate.catalogRoute)]);
  for (const prediction of predictions) {
    if (typeof prediction?.id !== 'string' || !expectedIds.has(prediction.id)) {
      throw new Error(`Classifier returned an unexpected id: ${prediction?.id}`);
    }
    if (seenIds.has(prediction.id)) throw new Error(`Classifier duplicated id: ${prediction.id}`);
    seenIds.add(prediction.id);
    if (typeof prediction.catalog_route !== 'string' || !validCatalogRoutes.has(prediction.catalog_route)) {
      throw new Error(`Classifier returned an invalid catalog_route for ${prediction.id}`);
    }
  }
}

export function scorePredictions(predictions, catalogPlan) {
  const normalizedPredictions = [];
  const mismatches = [];
  for (const prediction of predictions) {
    const normalizedRoute = normalizeCatalogRoute(
      prediction.catalog_route,
      catalogPlan.requestedRoutes,
    );
    if (!normalizedRoute) {
      throw new Error(`Unable to normalize catalog route: ${prediction.catalog_route}`);
    }
    const expectedRoute = catalogPlan.expectedCurrentCatalog.get(prediction.id);
    const normalized = {
      catalogRoute: prediction.catalog_route,
      id: prediction.id,
      route: normalizedRoute,
    };
    normalizedPredictions.push(normalized);
    if (normalizedRoute !== expectedRoute) {
      mismatches.push({
        actual: normalizedRoute,
        catalogRoute: prediction.catalog_route,
        expectedForCurrentCatalog: expectedRoute,
        id: prediction.id,
      });
    }
  }
  return {
    correct: predictions.length - mismatches.length,
    currentCatalogAccuracyPercent: Number((
      (100 * (predictions.length - mismatches.length)) / predictions.length
    ).toFixed(2)),
    mismatches,
    normalizedPredictions,
    total: predictions.length,
  };
}

function runClassifier({ candidates, catalogPlan, codexInvocation, document, options, prompt, run }) {
  const args = [
    ...codexInvocation.prefixArgs,
    'exec',
    '--json',
    '--ephemeral',
    '--ignore-user-config',
    '--ignore-rules',
    '--skip-git-repo-check',
    '--sandbox',
    'read-only',
    '--model',
    options.model,
    '-c',
    `model_reasoning_effort=${JSON.stringify(options.reasoningEffort)}`,
    '-c',
    'mcp_servers={}',
    '--color',
    'never',
    '-C',
    os.tmpdir(),
    '-',
  ];
  const result = spawnSync(codexInvocation.command, args, {
    encoding: 'utf8',
    input: prompt,
    maxBuffer: 32 * 1024 * 1024,
    timeout: options.timeoutMs,
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `Codex route run ${run} exited ${result.status}; stdoutChars=${result.stdout.length}; stderrChars=${result.stderr.length}`,
    );
  }

  const events = parseJsonEvents(result.stdout);
  const messages = events.filter((event) => (
    event.type === 'item.completed' && event.item?.type === 'agent_message'
  ));
  if (messages.length === 0) throw new Error(`Codex route run ${run} returned no agent message`);
  let finalMessage;
  try {
    finalMessage = JSON.parse(messages.at(-1).item.text);
  } catch (error) {
    throw new Error(`Codex route run ${run} final message is not strict JSON: ${error.message}`);
  }
  validatePredictions(finalMessage.predictions, document, candidates);

  const toolEvents = events.filter((event) => TOOL_ITEM_TYPES.has(event.item?.type));
  const score = scorePredictions(finalMessage.predictions, catalogPlan);
  const threadEvent = events.find((event) => event.type === 'thread.started');
  return {
    completedToolItems: toolEvents.filter((event) => event.type === 'item.completed').length,
    protocolToolEvents: toolEvents.length,
    run,
    runtimeWarnings: runtimeWarningSummary(result.stderr ?? ''),
    score,
    sessionId: threadEvent?.thread_id ?? null,
  };
}

function predictionSignature(run) {
  return JSON.stringify(run.score.normalizedPredictions.map((row) => [row.id, row.route]));
}

export function deriveOutcome(runs, catalogPlan, allowCatalogGaps) {
  const signatures = runs.map(predictionSignature);
  const stableAcrossRuns = signatures.every((signature) => signature === signatures[0]);
  const allRunsPerfectForCurrentCatalog = runs.every((run) => (
    run.score.mismatches.length === 0
    && run.protocolToolEvents === 0
    && run.completedToolItems === 0
  ));
  const routingPass = stableAcrossRuns && allRunsPerfectForCurrentCatalog;
  let status;
  let exitCode;
  if (!routingPass) {
    status = 'failed';
    exitCode = 1;
  } else if (catalogPlan.gaps.length > 0 && !allowCatalogGaps) {
    status = 'incomplete_catalog';
    exitCode = 2;
  } else if (catalogPlan.gaps.length > 0) {
    status = 'pass_with_catalog_gaps';
    exitCode = 0;
  } else {
    status = 'pass';
    exitCode = 0;
  }
  return {
    allRunsPerfectForCurrentCatalog,
    exitCode,
    routingPass,
    stableAcrossRuns,
    status,
  };
}

function publicCandidate(candidate, details) {
  return {
    catalogRoute: candidate.catalogRoute,
    effectiveDescription: candidate.effectiveDescription,
    effectiveDescriptionChars: candidate.effectiveDescriptionChars,
    normalizedRoute: candidate.normalizedRoute,
    ...(details ? { alias: candidate.alias, rootKind: candidate.rootKind } : {}),
  };
}

function publicRun(run, details) {
  return {
    completedToolItems: run.completedToolItems,
    correct: run.score.correct,
    currentCatalogAccuracyPercent: run.score.currentCatalogAccuracyPercent,
    mismatches: run.score.mismatches,
    protocolToolEvents: run.protocolToolEvents,
    run: run.run,
    runtimeWarnings: run.runtimeWarnings,
    sessionId: run.sessionId,
    total: run.score.total,
    ...(details ? { normalizedPredictions: run.score.normalizedPredictions } : {}),
  };
}

function buildReport({ auditReport, candidates, catalogPlan, codexInvocation, document, inputs, options, outcome, runs }) {
  const targetEntries = candidates
    .filter((candidate) => candidate.normalizedRoute === document.skill_name)
    .map((candidate) => publicCandidate(candidate, options.details));
  const gaps = catalogPlan.gaps.map((gap) => ({
    id: gap.id,
    intendedRoute: gap.intendedRoute,
    scoringFallback: gap.scoringFallback,
    ...(options.details ? { query: gap.query } : {}),
  }));
  const accuracies = runs.map((run) => run.score.currentCatalogAccuracyPercent);
  return {
    schema: SCHEMA,
    status: outcome.status,
    generatedAt: new Date().toISOString(),
    readOnly: true,
    persistentConfigChanged: false,
    protocol: {
      codexKind: codexInvocation.kind,
      codexVersion: readCodexVersion(codexInvocation),
      ephemeralSessions: true,
      ignoreProjectRules: true,
      ignoreUserConfig: true,
      invocationMode: 'implicit_description_routing',
      mcpServersDisabled: true,
      model: options.model,
      reasoningEffort: options.reasoningEffort,
      runsRequested: options.runs,
      sandbox: 'read-only',
      toolUseAllowed: false,
    },
    scope: {
      cwd: publicPath(inputs.cwd, inputs.cwd, options.details),
      eval: publicPath(inputs.evalPath, inputs.cwd, options.details),
      targetSkill: document.skill_name,
      cases: document.evals.length,
      positiveCases: document.evals.filter((row) => row.should_trigger).length,
      negativeCases: document.evals.filter((row) => !row.should_trigger).length,
      candidateCatalogEntries: candidates.length,
      normalizedRoutes: catalogPlan.availableRoutes.size,
      modelVisibleSkillCount: auditReport.effectivePrompt.skillCount,
      targetCatalogEntries: targetEntries,
      explicitOnlyExclusions: document.evals
        .filter((row) => typeof row.excluded_explicit_route === 'string')
        .map((row) => ({ id: row.id, excludedRoute: row.excluded_explicit_route })),
      ...(options.details ? { candidates: candidates.map((candidate) => publicCandidate(candidate, true)) } : {}),
    },
    catalog: {
      availableRoutes: [...catalogPlan.availableRoutes].sort(),
      fullyRoutable: gaps.length === 0,
      gapCount: gaps.length,
      gaps,
      coveredCases: catalogPlan.coveredCases,
      totalCases: catalogPlan.totalCases,
      evalCaseCatalogCoveragePercent: catalogPlan.evalCaseCatalogCoveragePercent,
      expectedRoutes: [...catalogPlan.requestedRoutes].sort(),
      defaultGapPolicy: options.allowCatalogGaps ? 'allow_with_explicit_status' : 'nonzero_exit',
    },
    routing: {
      allRunsPerfectForCurrentCatalog: outcome.allRunsPerfectForCurrentCatalog,
      currentCatalogAccuracyMaxPercent: Math.max(...accuracies),
      currentCatalogAccuracyMinPercent: Math.min(...accuracies),
      routingPass: outcome.routingPass,
      stableAcrossRuns: outcome.stableAcrossRuns,
    },
    runs: runs.map((run) => publicRun(run, options.details)),
    evidenceBoundary: 'This evaluator covers implicit description routing only and rejects explicit $skill mentions. Explicit-only Skills may be listed as excluded_explicit_route with expected_route=none; their direct invocation availability and behavior require a separate verifier. Current-catalog accuracy uses none only for explicit-only exclusions or explicitly reported unavailable expected implicit routes. evalCaseCatalogCoveragePercent covers only expected implicit routes represented by this eval file, not the whole business catalog. A true implicit catalog gap prevents status=pass by default. Model routing evidence does not prove the selected Skill body, business data, external actions, deployment, or production outcomes.',
  };
}

function selfTestCase(name, passed, actual, expected) {
  return { actual, expected, name, passed };
}

function runSelfTest() {
  const requested = new Set(['target', 'other', 'missing']);
  const normalization = normalizeCatalogRoute('bundle:target', requested);
  const document = {
    skill_name: 'target',
    evals: [
      { id: 'P-001', query: 'target query', should_trigger: true },
      { id: 'N-001', query: 'other query', should_trigger: false, expected_route: 'other' },
      { id: 'N-002', query: 'missing query', should_trigger: false, expected_route: 'missing' },
    ],
  };
  const candidates = [
    { catalogRoute: 'bundle:target', normalizedRoute: 'target' },
    { catalogRoute: 'other', normalizedRoute: 'other' },
  ];
  const plan = buildCatalogPlan(document, candidates);
  const score = scorePredictions([
    { id: 'P-001', catalog_route: 'bundle:target' },
    { id: 'N-001', catalog_route: 'other' },
    { id: 'N-002', catalog_route: 'none' },
  ], plan);
  const cleanRun = {
    completedToolItems: 0,
    protocolToolEvents: 0,
    score,
  };
  const strictOutcome = deriveOutcome([cleanRun, cleanRun], plan, false);
  const allowedOutcome = deriveOutcome([cleanRun, cleanRun], plan, true);
  const unstableRun = {
    ...cleanRun,
    score: {
      ...score,
      normalizedPredictions: score.normalizedPredictions.map((row) => (
        row.id === 'P-001' ? { ...row, route: 'other' } : row
      )),
    },
  };
  const unstableOutcome = deriveOutcome([cleanRun, unstableRun], plan, true);
  const outside = path.join(os.tmpdir(), 'outside-eval.json');
  const workspace = path.join(os.tmpdir(), 'workspace');
  const hiddenPath = publicPath(outside, workspace, false);
  const crossVolumePath = process.platform === 'win32'
    ? publicPath('Z:\\private\\trigger-evals.json', 'C:\\workspace', false)
    : publicPath('/private/trigger-evals.json', '/workspace', false);
  const validExplicitExclusion = {
    skill_name: 'target',
    evals: [
      {
        id: 'N-EXPLICIT',
        query: 'implicit product choice',
        should_trigger: false,
        expected_route: 'none',
        excluded_explicit_route: 'explicit-only-skill',
      },
    ],
  };
  let explicitExclusionAccepted = true;
  try {
    assertImplicitEvalDocument(validExplicitExclusion);
  } catch {
    explicitExclusionAccepted = false;
  }
  let explicitMentionRejected = false;
  try {
    assertImplicitEvalDocument({
      skill_name: 'target',
      evals: [{
        id: 'P-EXPLICIT',
        query: '$explicit-only-skill do the task',
        should_trigger: true,
      }],
    });
  } catch {
    explicitMentionRejected = true;
  }

  const cases = [
    selfTestCase('namespaced-route-normalization', normalization === 'target', normalization, 'target'),
    selfTestCase(
      'catalog-gap-is-explicit',
      plan.gaps.length === 1 && plan.evalCaseCatalogCoveragePercent === 66.67,
      { gapCount: plan.gaps.length, coverage: plan.evalCaseCatalogCoveragePercent },
      { gapCount: 1, coverage: 66.67 },
    ),
    selfTestCase(
      'current-catalog-score-is-separate',
      score.currentCatalogAccuracyPercent === 100 && score.mismatches.length === 0,
      { accuracy: score.currentCatalogAccuracyPercent, mismatches: score.mismatches.length },
      { accuracy: 100, mismatches: 0 },
    ),
    selfTestCase(
      'strict-gap-policy-is-nonzero',
      strictOutcome.status === 'incomplete_catalog' && strictOutcome.exitCode === 2,
      strictOutcome,
      { status: 'incomplete_catalog', exitCode: 2 },
    ),
    selfTestCase(
      'explicit-gap-allowance-retains-status',
      allowedOutcome.status === 'pass_with_catalog_gaps' && allowedOutcome.exitCode === 0,
      allowedOutcome,
      { status: 'pass_with_catalog_gaps', exitCode: 0 },
    ),
    selfTestCase(
      'cross-run-instability-fails',
      unstableOutcome.status === 'failed' && !unstableOutcome.stableAcrossRuns,
      unstableOutcome,
      { status: 'failed', stableAcrossRuns: false },
    ),
    selfTestCase(
      'absolute-path-hidden-by-default',
      hiddenPath === path.basename(outside),
      hiddenPath,
      path.basename(outside),
    ),
    selfTestCase(
      'explicit-only-exclusion-is-valid-implicit-contract',
      explicitExclusionAccepted,
      explicitExclusionAccepted,
      true,
    ),
    selfTestCase(
      'explicit-skill-mention-is-rejected',
      explicitMentionRejected,
      explicitMentionRejected,
      true,
    ),
    selfTestCase(
      'cross-volume-path-hidden-by-default',
      crossVolumePath === 'trigger-evals.json',
      crossVolumePath,
      'trigger-evals.json',
    ),
  ];
  const passed = cases.every((testCase) => testCase.passed);
  process.stdout.write(`${JSON.stringify({ schema: SCHEMA, status: passed ? 'passed' : 'failed', cases }, null, 2)}\n`);
  if (!passed) process.exitCode = 1;
}

function main() {
  let options = null;
  try {
    options = parseArgs(process.argv.slice(2));
    if (options.help) {
      printHelp();
      return;
    }
    if (options.selfTest) {
      runSelfTest();
      return;
    }

    const inputs = resolveInputs(options);
    const document = readEvalDocument(inputs.evalPath);
    const requestedRoutes = requestedRoutesFor(document);
    const codexInvocation = resolveCodexExecutable(options.codex);
    const auditReport = runSkillAudit(inputs, requestedRoutes, codexInvocation, options.timeoutMs);
    const candidates = buildCandidates(auditReport, requestedRoutes);
    const catalogPlan = buildCatalogPlan(document, candidates);
    const prompt = buildPrompt(document, candidates);
    const runs = [];
    for (let run = 1; run <= options.runs; run += 1) {
      runs.push(runClassifier({
        candidates,
        catalogPlan,
        codexInvocation,
        document,
        options,
        prompt,
        run,
      }));
    }
    const outcome = deriveOutcome(runs, catalogPlan, options.allowCatalogGaps);
    const report = buildReport({
      auditReport,
      candidates,
      catalogPlan,
      codexInvocation,
      document,
      inputs,
      options,
      outcome,
      runs,
    });
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
    process.exitCode = outcome.exitCode;
  } catch (error) {
    const payload = {
      schema: SCHEMA,
      status: 'error',
      readOnly: true,
      persistentConfigChanged: false,
      error: {
        message: error instanceof Error ? error.message : String(error),
        ...(options?.details && error instanceof Error ? { stack: error.stack } : {}),
      },
    };
    process.stderr.write(`${JSON.stringify(payload, null, 2)}\n`);
    process.exitCode = 1;
  }
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
if (invokedPath && samePath(invokedPath, SCRIPT_PATH)) main();
