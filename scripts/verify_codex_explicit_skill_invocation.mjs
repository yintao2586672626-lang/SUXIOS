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

const SCHEMA = 'suxi.codex-explicit-skill-invocation.v1';
const DEFAULT_TIMEOUT_MS = 180_000;
const DEFAULT_SYNTHETIC_PROMPT = '只验证显式 Skill 是否加载，不执行实际任务。';
const SCRIPT_PATH = fileURLToPath(import.meta.url);
const EXPLICIT_SKILL_PATTERN = /\$(?:[a-z0-9][a-z0-9_-]*:)*[a-z0-9][a-z0-9_-]*/giu;
const INVOCATION_NAME_PATTERN = /^[a-z0-9][a-z0-9_-]*(?::[a-z0-9][a-z0-9_-]*)*$/u;

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
    help: false,
    invocationName: null,
    markers: [],
    prompt: null,
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
    } else if (argument === '--invocation-name') {
      options.invocationName = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--marker') {
      options.markers.push(requireValue(argv, index, argument));
      index += 1;
    } else if (argument === '--prompt') {
      options.prompt = requireValue(argv, index, argument);
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
  if (!options.help && !options.selfTest) {
    if (!options.skillRoot) throw new Error('--skill-root is required');
    if (options.markers.length < 2) throw new Error('At least two --marker values are required');
  }
  if (new Set(options.markers).size !== options.markers.length) {
    throw new Error('--marker values must be unique');
  }
  return options;
}

function printHelp() {
  process.stdout.write('Read-only Codex explicit $skill prompt-input verifier.\n\n');
  process.stdout.write('Usage: node scripts/verify_codex_explicit_skill_invocation.mjs --skill-root <dir> --marker <text> --marker <text> [options]\n\n');
  process.stdout.write('Options:\n');
  process.stdout.write('  --cwd <directory>       Project cwd used by codex debug prompt-input.\n');
  process.stdout.write('  --skill-root <dir>      Explicit-only Skill folder containing SKILL.md and agents/openai.yaml.\n');
  process.stdout.write('  --invocation-name <id>  Actual $skill token name; use plugin:skill for plugin-provided Skills.\n');
  process.stdout.write('  --prompt <text>         Optional low-sensitivity base prompt. Default: synthetic loading probe.\n');
  process.stdout.write('  --marker <text>         Unique SKILL.md marker; repeat at least twice.\n');
  process.stdout.write(`  --timeout-ms <ms>        Timeout per prompt-input run. Default: ${DEFAULT_TIMEOUT_MS}.\n`);
  process.stdout.write('  --codex <executable>     Override the Codex executable.\n');
  process.stdout.write('  --details                Include absolute path, marker text, and marker match vector.\n');
  process.stdout.write('  --self-test              Run offline classification and privacy tests.\n');
  process.stdout.write('  -h, --help               Show this help.\n');
}

export function parseSkillName(skillMarkdown) {
  const normalized = skillMarkdown.replace(/^\uFEFF/u, '').replaceAll('\r\n', '\n');
  const frontmatter = normalized.match(/^---\n([\s\S]*?)\n---/u)?.[1];
  const rawName = frontmatter?.match(/^name:\s*(.+)$/mu)?.[1]?.trim();
  if (!rawName) throw new Error('SKILL.md frontmatter has no name');
  return rawName.replace(/^["']|["']$/gu, '');
}

export function validateInvocationName(skillName, invocationName) {
  if (!INVOCATION_NAME_PATTERN.test(invocationName)) {
    throw new Error(`Invalid explicit invocation name: ${invocationName}`);
  }
  const terminalName = invocationName.split(':').at(-1);
  if (terminalName !== skillName) {
    throw new Error(
      `Explicit invocation name must end with the SKILL.md name: expected ${skillName}, got ${invocationName}`,
    );
  }
  return invocationName;
}

function parseImplicitPolicy(openaiYaml) {
  const match = openaiYaml.match(/^\s*allow_implicit_invocation:\s*(true|false)\s*$/mu);
  if (!match) throw new Error('agents/openai.yaml has no allow_implicit_invocation policy');
  return match[1] === 'true';
}

function markerDigest(marker) {
  return crypto.createHash('sha256').update(marker, 'utf8').digest('hex').toUpperCase();
}

function fileSnapshot(filePath) {
  if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
    return { exists: false, sha256: null };
  }
  return {
    exists: true,
    sha256: crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex').toUpperCase(),
  };
}

export function discoveryInputSnapshot(skillPath, openaiYamlPath) {
  const skill = fileSnapshot(skillPath);
  const policy = fileSnapshot(openaiYamlPath);
  const combined = crypto.createHash('sha256')
    .update(`SKILL.md\0${skill.exists}\0${skill.sha256 ?? ''}\0`)
    .update(`agents/openai.yaml\0${policy.exists}\0${policy.sha256 ?? ''}\0`)
    .digest('hex')
    .toUpperCase();
  return { combinedSha256: combined, policy, skill };
}

function resolveInputs(options) {
  const cwd = path.resolve(options.cwd ?? process.cwd());
  if (!fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
    throw new Error(`--cwd is not a directory: ${cwd}`);
  }
  const skillRoot = path.resolve(cwd, options.skillRoot);
  if (!fs.existsSync(skillRoot) || !fs.statSync(skillRoot).isDirectory()) {
    throw new Error(`--skill-root is not a directory: ${skillRoot}`);
  }
  const skillPath = path.join(skillRoot, 'SKILL.md');
  const openaiYamlPath = path.join(skillRoot, 'agents', 'openai.yaml');
  for (const [file, label] of [[skillPath, 'SKILL.md'], [openaiYamlPath, 'agents/openai.yaml']]) {
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
      throw new Error(`${label} is not a file: ${file}`);
    }
  }
  const skillMarkdown = fs.readFileSync(skillPath, 'utf8');
  const openaiYaml = fs.readFileSync(openaiYamlPath, 'utf8');
  const skillName = parseSkillName(skillMarkdown);
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/u.test(skillName)) {
    throw new Error(`Invalid Skill name: ${skillName}`);
  }
  const invocationName = validateInvocationName(
    skillName,
    options.invocationName ?? skillName,
  );
  const allowImplicitInvocation = parseImplicitPolicy(openaiYaml);
  if (allowImplicitInvocation) {
    throw new Error(`Skill is not explicit-only: ${skillName}`);
  }
  const prompt = options.prompt ?? DEFAULT_SYNTHETIC_PROMPT;
  const promptMentions = prompt.match(EXPLICIT_SKILL_PATTERN) ?? [];
  if (promptMentions.length > 0) {
    throw new Error(`--prompt must not contain explicit Skill mentions: ${promptMentions.join(', ')}`);
  }
  for (const marker of options.markers) {
    if (!skillMarkdown.includes(marker)) {
      throw new Error(`Marker is not present in SKILL.md: sha256=${markerDigest(marker)}`);
    }
  }
  return {
    allowImplicitInvocation,
    customPromptUsed: options.prompt !== null,
    cwd,
    invocationName,
    markers: options.markers,
    openaiYamlPath,
    prompt,
    skillMarkdown,
    skillName,
    skillPath,
    skillRoot,
    initialDiscoverySnapshot: discoveryInputSnapshot(skillPath, openaiYamlPath),
  };
}

function warningSummary(stderr) {
  const lines = stderr.split(/\r?\n/u).map((line) => line.trim()).filter(Boolean);
  return {
    modelCacheWarning: lines.some((line) => line.includes('models cache') || line.includes('base_instructions')),
    networkWarning: lines.some((line) => line.includes('request') && (line.includes('failed') || line.includes('error'))),
    remotePluginCatalogWarning: lines.some((line) => line.includes('remote plugin catalog')),
    shellSnapshotWarning: lines.some((line) => line.includes('shell snapshot')),
    warningLineCount: lines.filter((line) => /\bWARN\b|\bERROR\b|^warning:/u.test(line)).length,
  };
}

function runPromptInput(codexInvocation, cwd, prompt, timeoutMs) {
  const result = spawnSync(codexInvocation.command, [
    ...codexInvocation.prefixArgs,
    '-c',
    'mcp_servers={}',
    '-C',
    cwd,
    'debug',
    'prompt-input',
    prompt,
  ], {
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    timeout: timeoutMs,
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `codex debug prompt-input exited ${result.status}; stdoutChars=${result.stdout.length}; stderrChars=${result.stderr.length}`,
    );
  }
  let items;
  try {
    items = JSON.parse(result.stdout);
  } catch (error) {
    throw new Error(`Unable to parse prompt-input JSON: ${error.message}`);
  }
  if (!Array.isArray(items)) throw new Error('prompt-input root must be an array');
  const messages = items.filter((item) => item?.type === 'message');
  const text = messages
    .flatMap((message) => Array.isArray(message.content) ? message.content : [])
    .filter((item) => item?.type === 'input_text' && typeof item.text === 'string')
    .map((item) => item.text)
    .join('\n');
  return {
    messageCount: messages.length,
    runtimeWarnings: warningSummary(result.stderr ?? ''),
    text,
  };
}

function escapeRegex(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&');
}

export function observePromptInput(run, markers, skillName, invocationName = skillName) {
  const markerMatches = markers.map((marker) => run.text.includes(marker));
  const escapedName = escapeRegex(skillName);
  const escapedInvocationName = escapeRegex(invocationName);
  const catalogPattern = invocationName === skillName
    ? `^- (?:[^ ]+:)?${escapedName}:`
    : `^- ${escapedInvocationName}:`;
  return {
    allMarkersMatched: markerMatches.every(Boolean),
    catalogEntryObserved: new RegExp(catalogPattern, 'mu').test(run.text),
    explicitTokenPreserved: run.text.includes(`$${invocationName}`),
    markerMatches,
    matchedMarkers: markerMatches.filter(Boolean).length,
    messageCount: run.messageCount,
    runtimeWarnings: run.runtimeWarnings,
    skillPathReferenceObserved: run.text.includes(`${skillName}/SKILL.md`)
      || run.text.includes(`${skillName}\\SKILL.md`),
    totalMarkers: markers.length,
  };
}

export function classifyExplicitInvocation({ explicit, implicit, inputStable = true }) {
  if (!inputStable) return 'input_changed_during_verification';
  if (implicit.matchedMarkers > 0) return 'implicit_leak';
  if (explicit.allMarkersMatched) return 'pass';
  if (explicit.matchedMarkers > 0) return 'explicit_partial_load';
  if (explicit.explicitTokenPreserved) return 'explicit_not_loaded';
  return 'explicit_token_missing';
}

export function promptInputConclusion(status) {
  const conclusions = {
    pass: 'explicit_body_observed_in_prompt_input',
    implicit_leak: 'invalid_implicit_body_leak',
    explicit_partial_load: 'indeterminate_partial_prompt_input_expansion',
    explicit_not_loaded: 'indeterminate_no_prompt_input_expansion',
    explicit_token_missing: 'indeterminate_explicit_token_not_observed',
    input_changed_during_verification: 'indeterminate_discovery_input_changed',
  };
  return conclusions[status] ?? 'indeterminate_unknown_status';
}

function publicObservation(observation, details) {
  return {
    allMarkersMatched: observation.allMarkersMatched,
    catalogEntryObserved: observation.catalogEntryObserved,
    explicitTokenPreserved: observation.explicitTokenPreserved,
    matchedMarkers: observation.matchedMarkers,
    messageCount: observation.messageCount,
    runtimeWarnings: observation.runtimeWarnings,
    skillPathReferenceObserved: observation.skillPathReferenceObserved,
    totalMarkers: observation.totalMarkers,
    ...(details ? { markerMatches: observation.markerMatches } : {}),
  };
}

function buildReport(inputs, options, codexInvocation, implicit, explicit, finalDiscoverySnapshot) {
  const inputStable = inputs.initialDiscoverySnapshot.combinedSha256
    === finalDiscoverySnapshot.combinedSha256;
  const status = classifyExplicitInvocation({ explicit, implicit, inputStable });
  return {
    schema: SCHEMA,
    status,
    promptInputConclusion: promptInputConclusion(status),
    generatedAt: new Date().toISOString(),
    readOnly: true,
    persistentConfigChanged: false,
    identity: {
      invocationName: inputs.invocationName,
      skillName: inputs.skillName,
      skillRoot: publicPath(inputs.skillRoot, inputs.cwd, options.details),
      allowImplicitInvocation: inputs.allowImplicitInvocation,
    },
    protocol: {
      codexKind: codexInvocation.kind,
      codexVersion: readCodexVersion(codexInvocation),
      promptInputOnly: true,
      modelExecuted: false,
      modelLoadConclusion: 'not_evaluated',
      mcpServersDisabled: true,
      implicitControlPromptHasSkillToken: false,
      explicitPromptPrependsSkillToken: true,
      explicitSkillToken: `$${inputs.invocationName}`,
      promptSource: inputs.customPromptUsed ? 'custom_low_sensitivity' : 'synthetic_default',
      promptTransport: 'positional_child_process_argument',
      stdinPromptSupported: false,
      sensitivePromptAllowed: false,
    },
    markers: {
      required: inputs.markers.length,
      sha256: inputs.markers.map(markerDigest),
      ...(options.details ? { values: inputs.markers } : {}),
    },
    inputStability: {
      scope: ['SKILL.md', 'agents/openai.yaml'],
      stable: inputStable,
      before: inputs.initialDiscoverySnapshot,
      after: finalDiscoverySnapshot,
      evidenceBoundary: 'This snapshot covers only the provided Skill root discovery files. It does not prove that other project Skills, plugin caches, tasks, or external writers were stable.',
    },
    observations: {
      implicitControl: publicObservation(implicit, options.details),
      explicitInvocation: publicObservation(explicit, options.details),
    },
    evidenceBoundary: 'status=pass proves only that the provided SKILL.md and agents/openai.yaml hashes stayed stable, codex debug prompt-input excluded all required Skill markers from the implicit control, and explicit invocation included every marker. A non-pass status proves only that this debug prompt-input surface did not expose all markers; it does not prove that an actual model run will fail to inject the Skill. Verify actual injection with scripts/verify_codex_explicit_skill_model_load.mjs, using a synthetic ephemeral model run and requiring zero tool events. This Codex CLI version ignores stdin for debug prompt-input, so the verifier defaults to a synthetic non-sensitive positional prompt; a custom --prompt is visible in the child process command line and must not contain sensitive data. The verifier does not prove other plugin or project inputs were stable, execute the model, prove Skill behavior, authorize writes, or prove deployment and production state.',
  };
}

function selfTestCase(name, passed, actual, expected) {
  return { actual, expected, name, passed };
}

function observation({ all = false, matched = 0, token = false }) {
  return {
    allMarkersMatched: all,
    explicitTokenPreserved: token,
    matchedMarkers: matched,
  };
}

function runSelfTest() {
  const cleanImplicit = observation({});
  const observedCatalog = observePromptInput({
    messageCount: 3,
    runtimeWarnings: {},
    text: '- bundle:suxi-product-decision: explicit route\n# marker one\nmarker two',
  }, ['# marker one', 'marker two'], 'suxi-product-decision');
  const observedNamespaced = observePromptInput({
    messageCount: 3,
    runtimeWarnings: {},
    text: '- suxi-os-toolkit:suxi-product-decision: explicit route\n$suxi-os-toolkit:suxi-product-decision\n# marker one\nmarker two',
  }, ['# marker one', 'marker two'], 'suxi-product-decision', 'suxi-os-toolkit:suxi-product-decision');
  const cases = [
    selfTestCase(
      'explicit-full-load-pass',
      classifyExplicitInvocation({
        implicit: cleanImplicit,
        explicit: observation({ all: true, matched: 2, token: false }),
      }) === 'pass',
      'pass',
      'pass',
    ),
    selfTestCase(
      'implicit-marker-leak-fails',
      classifyExplicitInvocation({
        implicit: observation({ matched: 1 }),
        explicit: observation({ all: true, matched: 2 }),
      }) === 'implicit_leak',
      'implicit_leak',
      'implicit_leak',
    ),
    selfTestCase(
      'explicit-token-without-load',
      classifyExplicitInvocation({
        implicit: cleanImplicit,
        explicit: observation({ token: true }),
      }) === 'explicit_not_loaded',
      'explicit_not_loaded',
      'explicit_not_loaded',
    ),
    selfTestCase(
      'partial-marker-load',
      classifyExplicitInvocation({
        implicit: cleanImplicit,
        explicit: observation({ matched: 1, token: true }),
      }) === 'explicit_partial_load',
      'explicit_partial_load',
      'explicit_partial_load',
    ),
    selfTestCase(
      'missing-token-and-markers',
      classifyExplicitInvocation({
        implicit: cleanImplicit,
        explicit: observation({}),
      }) === 'explicit_token_missing',
      'explicit_token_missing',
      'explicit_token_missing',
    ),
    selfTestCase(
      'input-change-overrides-marker-results',
      classifyExplicitInvocation({
        implicit: cleanImplicit,
        explicit: observation({ all: true, matched: 2 }),
        inputStable: false,
      }) === 'input_changed_during_verification',
      'input_changed_during_verification',
      'input_changed_during_verification',
    ),
    selfTestCase(
      'marker-digests-distinguish-markers',
      markerDigest('marker one') !== markerDigest('marker two'),
      [markerDigest('marker one'), markerDigest('marker two')],
      'two distinct hashes',
    ),
    selfTestCase(
      'cross-volume-path-hidden-by-default',
      (process.platform === 'win32'
        ? publicPath('Z:\\private\\skill', 'C:\\workspace', false)
        : publicPath('/private/skill', '/workspace', false)) === 'skill',
      'skill',
      'skill',
    ),
    selfTestCase(
      'dynamic-catalog-regex-is-valid',
      observedCatalog.catalogEntryObserved && observedCatalog.allMarkersMatched,
      {
        catalogEntryObserved: observedCatalog.catalogEntryObserved,
        allMarkersMatched: observedCatalog.allMarkersMatched,
      },
      { catalogEntryObserved: true, allMarkersMatched: true },
    ),
    selfTestCase(
      'namespaced-invocation-is-valid',
      validateInvocationName(
        'suxi-product-decision',
        'suxi-os-toolkit:suxi-product-decision',
      ) === 'suxi-os-toolkit:suxi-product-decision',
      'suxi-os-toolkit:suxi-product-decision',
      'suxi-os-toolkit:suxi-product-decision',
    ),
    selfTestCase(
      'namespaced-token-and-catalog-are-observed',
      observedNamespaced.catalogEntryObserved
        && observedNamespaced.explicitTokenPreserved
        && observedNamespaced.allMarkersMatched,
      {
        allMarkersMatched: observedNamespaced.allMarkersMatched,
        catalogEntryObserved: observedNamespaced.catalogEntryObserved,
        explicitTokenPreserved: observedNamespaced.explicitTokenPreserved,
      },
      {
        allMarkersMatched: true,
        catalogEntryObserved: true,
        explicitTokenPreserved: true,
      },
    ),
    selfTestCase(
      'mismatched-invocation-name-is-rejected',
      (() => {
        try {
          validateInvocationName('suxi-product-decision', 'suxi-os-toolkit:suxi-user-research');
          return false;
        } catch {
          return true;
        }
      })(),
      'rejected',
      'rejected',
    ),
    selfTestCase(
      'debug-nonexpansion-is-indeterminate-not-a-model-load-failure',
      promptInputConclusion('explicit_not_loaded') === 'indeterminate_no_prompt_input_expansion',
      promptInputConclusion('explicit_not_loaded'),
      'indeterminate_no_prompt_input_expansion',
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
    const implicitRun = runPromptInput(
      codexInvocation,
      inputs.cwd,
      inputs.prompt,
      options.timeoutMs,
    );
    const explicitRun = runPromptInput(
      codexInvocation,
      inputs.cwd,
      `$${inputs.invocationName} ${inputs.prompt}`,
      options.timeoutMs,
    );
    const implicit = observePromptInput(
      implicitRun,
      inputs.markers,
      inputs.skillName,
      inputs.invocationName,
    );
    const explicit = observePromptInput(
      explicitRun,
      inputs.markers,
      inputs.skillName,
      inputs.invocationName,
    );
    const finalDiscoverySnapshot = discoveryInputSnapshot(inputs.skillPath, inputs.openaiYamlPath);
    const report = buildReport(
      inputs,
      options,
      codexInvocation,
      implicit,
      explicit,
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
