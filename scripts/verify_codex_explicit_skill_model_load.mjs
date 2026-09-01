#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
  publicPath,
  readCodexVersion,
  resolveCodexExecutable,
} from './lib/codex_cli_runtime.mjs';
import {
  discoveryInputSnapshot,
  parseSkillName,
  validateInvocationName,
} from './verify_codex_explicit_skill_invocation.mjs';

const SCHEMA = 'suxi.codex-explicit-skill-model-load.v1';
const DEFAULT_MODEL = 'gpt-5.5';
const DEFAULT_REASONING_EFFORT = 'low';
const DEFAULT_TIMEOUT_MS = 240_000;
const SCRIPT_PATH = fileURLToPath(import.meta.url);
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
const TOOL_ITEM_TYPES = new Set([
  'command_execution',
  'computer_use',
  'file_search',
  'image_generation',
  'mcp_tool_call',
  'tool_call',
  'web_search',
]);
const EXPLICIT_SKILL_PATTERN = /\$(?:[a-z0-9][a-z0-9_-]*:)*[a-z0-9][a-z0-9_-]*/giu;

function requireValue(argv, index, argument) {
  const value = argv[index + 1];
  if (typeof value !== 'string' || !value.trim() || value.startsWith('--')) {
    throw new Error(`${argument} requires a non-empty value`);
  }
  return value;
}

export function parseArgs(argv) {
  const options = {
    codex: null,
    cwd: null,
    details: false,
    expectedJson: null,
    help: false,
    invocationName: null,
    model: DEFAULT_MODEL,
    question: null,
    reasoningEffort: DEFAULT_REASONING_EFFORT,
    selfTest: false,
    skillRoot: null,
    timeoutMs: DEFAULT_TIMEOUT_MS,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--help' || argument === '-h') {
      options.help = true;
    } else if (argument === '--details') {
      options.details = true;
    } else if (argument === '--self-test') {
      options.selfTest = true;
    } else if (argument === '--codex') {
      options.codex = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--cwd') {
      options.cwd = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--expected-json') {
      options.expectedJson = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--invocation-name') {
      options.invocationName = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--model') {
      options.model = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--question') {
      options.question = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--reasoning-effort') {
      options.reasoningEffort = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--skill-root') {
      options.skillRoot = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--timeout-ms') {
      options.timeoutMs = Number.parseInt(requireValue(argv, index, argument), 10);
      index += 1;
    } else {
      throw new Error(`Unknown argument: ${argument}`);
    }
  }

  if (!Number.isInteger(options.timeoutMs) || options.timeoutMs < 10_000) {
    throw new Error('--timeout-ms must be an integer of at least 10000');
  }
  if (!REASONING_EFFORTS.has(options.reasoningEffort)) {
    throw new Error(`Unsupported --reasoning-effort: ${options.reasoningEffort}`);
  }
  if (!/^[a-z0-9][a-z0-9._-]*$/iu.test(options.model)) {
    throw new Error(`Invalid --model: ${options.model}`);
  }
  if (!options.help && !options.selfTest) {
    for (const [value, argument] of [
      [options.skillRoot, '--skill-root'],
      [options.invocationName, '--invocation-name'],
      [options.question, '--question'],
      [options.expectedJson, '--expected-json'],
    ]) {
      if (!value) throw new Error(`${argument} is required`);
    }
  }
  return options;
}

function printHelp() {
  process.stdout.write('Ephemeral zero-tool model probe for explicit Codex Skill loading.\n\n');
  process.stdout.write('Usage: node scripts/verify_codex_explicit_skill_model_load.mjs [options]\n\n');
  process.stdout.write('Options:\n');
  process.stdout.write('  --cwd <directory>       Project cwd used by the ephemeral Codex run.\n');
  process.stdout.write('  --skill-root <dir>      Skill folder containing SKILL.md and agents/openai.yaml.\n');
  process.stdout.write('  --invocation-name <id>  Actual plugin:skill token name.\n');
  process.stdout.write('  --question <text>       Low-sensitivity blind question sent after the Skill token.\n');
  process.stdout.write('  --expected-json <json>  Exact expected final JSON; retained by the verifier, not sent.\n');
  process.stdout.write(`  --model <model>          Codex model. Default: ${DEFAULT_MODEL}.\n`);
  process.stdout.write(`  --reasoning-effort <v>   Reasoning effort. Default: ${DEFAULT_REASONING_EFFORT}.\n`);
  process.stdout.write(`  --timeout-ms <ms>        Model-run timeout. Default: ${DEFAULT_TIMEOUT_MS}.\n`);
  process.stdout.write('  --codex <executable>     Override the Codex executable.\n');
  process.stdout.write('  --details                Include roots and expected/actual JSON values.\n');
  process.stdout.write('  --self-test              Run offline parser and scoring tests.\n');
  process.stdout.write('  -h, --help               Show this help.\n');
}

function sha256(value) {
  return crypto.createHash('sha256').update(value, 'utf8').digest('hex').toUpperCase();
}

function canonicalize(value) {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]),
    );
  }
  return value;
}

export function exactJsonMatch(actual, expected) {
  return JSON.stringify(canonicalize(actual)) === JSON.stringify(canonicalize(expected));
}

export function parseJsonEvents(stdout) {
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

function warningSummary(stderr) {
  const lines = stderr.split(/\r?\n/u).map((line) => line.trim()).filter(Boolean);
  return {
    modelCacheWarning: lines.some((line) => line.includes('models cache') || line.includes('base_instructions')),
    networkWarning: lines.some((line) => line.includes('request') && (line.includes('failed') || line.includes('error'))),
    remotePluginCatalogWarning: lines.some((line) => line.includes('remote plugin catalog')),
    skillInjectedTelemetryObserved: lines.some((line) => line.includes('codex.skill.injected')),
    warningLineCount: lines.filter((line) => /\bWARN\b|\bERROR\b|^warning:/u.test(line)).length,
  };
}

export function buildProbePrompt(invocationName, question) {
  return [
    `$${invocationName}`,
    'This is a synthetic Skill-loading probe. Do not read files, run commands, or call tools. Use only the Skill body loaded by the host.',
    question,
    'Return only strict JSON without Markdown.',
  ].join('\n\n');
}

function resolveInputs(options) {
  const cwd = path.resolve(options.cwd ?? process.cwd());
  if (!fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
    throw new Error(`--cwd is not a directory: ${cwd}`);
  }
  const skillRoot = path.resolve(cwd, options.skillRoot);
  const skillPath = path.join(skillRoot, 'SKILL.md');
  const openaiYamlPath = path.join(skillRoot, 'agents', 'openai.yaml');
  for (const [file, label] of [[skillPath, 'SKILL.md'], [openaiYamlPath, 'agents/openai.yaml']]) {
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
      throw new Error(`${label} is not a file: ${file}`);
    }
  }
  const skillMarkdown = fs.readFileSync(skillPath, 'utf8');
  const skillName = parseSkillName(skillMarkdown);
  const invocationName = validateInvocationName(skillName, options.invocationName);
  if (options.question.length > 2_000 || options.question.includes('\0')) {
    throw new Error('--question must be a low-sensitivity string of at most 2000 characters');
  }
  const mentions = options.question.match(EXPLICIT_SKILL_PATTERN) ?? [];
  if (mentions.length > 0) {
    throw new Error(`--question must not contain explicit Skill mentions: ${mentions.join(', ')}`);
  }
  if (options.expectedJson.length > 8_000) {
    throw new Error('--expected-json must be at most 8000 characters');
  }
  let expected;
  try {
    expected = JSON.parse(options.expectedJson);
  } catch (error) {
    throw new Error(`--expected-json is invalid JSON: ${error.message}`);
  }
  if (!expected || typeof expected !== 'object' || Array.isArray(expected)) {
    throw new Error('--expected-json must encode a JSON object');
  }
  return {
    cwd,
    expected,
    initialDiscoverySnapshot: discoveryInputSnapshot(skillPath, openaiYamlPath),
    invocationName,
    openaiYamlPath,
    prompt: buildProbePrompt(invocationName, options.question),
    question: options.question,
    skillName,
    skillPath,
    skillRoot,
  };
}

export function scoreProbe({ actual, events, expected, inputStable }) {
  const toolEvents = events.filter((event) => TOOL_ITEM_TYPES.has(event.item?.type));
  const reasons = [];
  if (!inputStable) reasons.push('discovery_input_changed');
  if (toolEvents.length > 0) reasons.push('tool_event_observed');
  if (!exactJsonMatch(actual, expected)) reasons.push('final_json_mismatch');
  return {
    exactMatch: exactJsonMatch(actual, expected),
    reasons,
    status: reasons.length === 0 ? 'pass' : 'fail',
    toolEventCount: toolEvents.length,
  };
}

function runModelProbe(codexInvocation, options, inputs) {
  const args = [
    ...codexInvocation.prefixArgs,
    'exec',
    '--json',
    '--ephemeral',
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
    inputs.cwd,
    '-',
  ];
  const result = spawnSync(codexInvocation.command, args, {
    encoding: 'utf8',
    input: inputs.prompt,
    maxBuffer: 32 * 1024 * 1024,
    timeout: options.timeoutMs,
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `Codex model probe exited ${result.status}; stdoutChars=${result.stdout.length}; stderrChars=${result.stderr.length}`,
    );
  }
  const events = parseJsonEvents(result.stdout);
  const messages = events.filter((event) => (
    event.type === 'item.completed' && event.item?.type === 'agent_message'
  ));
  if (messages.length === 0) throw new Error('Codex model probe returned no agent message');
  let actual;
  try {
    actual = JSON.parse(messages.at(-1).item.text);
  } catch (error) {
    throw new Error(`Codex model probe final message is not strict JSON: ${error.message}`);
  }
  return {
    actual,
    events,
    messageCount: messages.length,
    runtimeWarnings: warningSummary(result.stderr ?? ''),
    sessionId: events.find((event) => event.type === 'thread.started')?.thread_id ?? null,
  };
}

function buildReport(inputs, options, codexInvocation, run, finalDiscoverySnapshot) {
  const inputStable = inputs.initialDiscoverySnapshot.combinedSha256
    === finalDiscoverySnapshot.combinedSha256;
  const score = scoreProbe({
    actual: run.actual,
    events: run.events,
    expected: inputs.expected,
    inputStable,
  });
  return {
    schema: SCHEMA,
    status: score.status,
    generatedAt: new Date().toISOString(),
    readOnly: true,
    persistentConfigChanged: false,
    identity: {
      invocationName: inputs.invocationName,
      skillName: inputs.skillName,
      skillRoot: publicPath(inputs.skillRoot, inputs.cwd, options.details),
    },
    protocol: {
      codexKind: codexInvocation.kind,
      codexVersion: readCodexVersion(codexInvocation),
      ephemeralSession: true,
      expectedResponseSentToModel: false,
      mcpInitializationMayStillOccur: true,
      mcpServersOverrideRequested: true,
      model: options.model,
      modelExecuted: true,
      promptTransport: 'stdin',
      reasoningEffort: options.reasoningEffort,
      sandbox: 'read-only',
      sensitivePromptAllowed: false,
      zeroToolEventsRequired: true,
    },
    inputStability: {
      stable: inputStable,
      before: inputs.initialDiscoverySnapshot,
      after: finalDiscoverySnapshot,
    },
    blindProbe: {
      exactMatch: score.exactMatch,
      expectedSha256: sha256(JSON.stringify(canonicalize(inputs.expected))),
      finalResponseSha256: sha256(JSON.stringify(canonicalize(run.actual))),
      questionSha256: sha256(inputs.question),
      ...(options.details ? { actual: run.actual, expected: inputs.expected } : {}),
    },
    execution: {
      agentMessageCount: run.messageCount,
      reasons: score.reasons,
      runtimeWarnings: run.runtimeWarnings,
      sessionId: run.sessionId,
      toolEventCount: score.toolEventCount,
    },
    evidenceBoundary: 'status=pass proves that one ephemeral Codex model run returned the exact blinded JSON expected from the explicitly invoked Skill while emitting zero tool events and while the Skill discovery files remained stable. It does not prove implicit routing, every Skill behavior, plugin publication, Git persistence, deployment, production data, or field outcomes.',
  };
}

function selfTestCase(name, passed, actual, expected) {
  return { actual, expected, name, passed };
}

function runSelfTest() {
  const expected = { loaded: true, modes: ['plan', 'retest'] };
  const agentEvent = {
    type: 'item.completed',
    item: { type: 'agent_message', text: JSON.stringify(expected) },
  };
  const toolEvent = { type: 'item.completed', item: { type: 'command_execution' } };
  const clean = scoreProbe({ actual: expected, events: [agentEvent], expected, inputStable: true });
  const withTool = scoreProbe({ actual: expected, events: [agentEvent, toolEvent], expected, inputStable: true });
  const cases = [
    selfTestCase('canonical-object-key-order-matches', exactJsonMatch(
      { modes: ['plan', 'retest'], loaded: true },
      expected,
    ), true, true),
    selfTestCase('array-order-remains-significant', !exactJsonMatch(
      { loaded: true, modes: ['retest', 'plan'] },
      expected,
    ), true, true),
    selfTestCase('clean-zero-tool-probe-passes', clean.status === 'pass', clean.status, 'pass'),
    selfTestCase(
      'tool-event-forces-failure',
      withTool.status === 'fail' && withTool.reasons.includes('tool_event_observed'),
      withTool,
      'tool_event_observed',
    ),
    selfTestCase(
      'prompt-carries-token-but-not-expected-response',
      buildProbePrompt('bundle:skill-name', 'Return a body-derived fact.').includes('$bundle:skill-name')
        && !buildProbePrompt('bundle:skill-name', 'Return a body-derived fact.').includes(JSON.stringify(expected)),
      true,
      true,
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
    const codexInvocation = resolveCodexExecutable(options.codex);
    const run = runModelProbe(codexInvocation, options, inputs);
    const finalDiscoverySnapshot = discoveryInputSnapshot(
      inputs.skillPath,
      inputs.openaiYamlPath,
    );
    const report = buildReport(
      inputs,
      options,
      codexInvocation,
      run,
      finalDiscoverySnapshot,
    );
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
    process.exitCode = report.status === 'pass' ? 0 : 2;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({
      schema: SCHEMA,
      status: 'error',
      readOnly: true,
      persistentConfigChanged: false,
      error: {
        message: error instanceof Error ? error.message : String(error),
        ...(options?.details && error instanceof Error ? { stack: error.stack } : {}),
      },
    }, null, 2)}\n`);
    process.exitCode = 1;
  }
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : '';
const samePath = process.platform === 'win32'
  ? invokedPath.toLocaleLowerCase() === path.resolve(SCRIPT_PATH).toLocaleLowerCase()
  : invokedPath === path.resolve(SCRIPT_PATH);
if (samePath) main();
