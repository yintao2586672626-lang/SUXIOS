#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

import {
  readCodexVersion,
  resolveCodexExecutable,
} from './lib/codex_cli_runtime.mjs';

const SCHEMA = 'suxi.codex-skill-budget-audit.v1';
const TEMP_PREFIX = 'suxi-codex-skill-budget-';
const DEFAULT_TIMEOUT_MS = 180_000;

function parseArgs(argv) {
  const options = {
    cwd: null,
    codex: null,
    details: false,
    matches: [],
    selfTest: false,
    simulateExactDedup: false,
    timeoutMs: DEFAULT_TIMEOUT_MS,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--help' || argument === '-h') {
      options.help = true;
    } else if (argument === '--details') {
      options.details = true;
    } else if (argument === '--match') {
      const value = argv[++index]?.trim();
      if (!value) throw new Error('--match requires a non-empty value');
      options.matches.push(value);
    } else if (argument === '--self-test') {
      options.selfTest = true;
    } else if (argument === '--simulate-exact-dedup') {
      options.simulateExactDedup = true;
    } else if (argument === '--cwd') {
      options.cwd = argv[++index] ?? null;
    } else if (argument === '--codex') {
      options.codex = argv[++index] ?? null;
    } else if (argument === '--timeout-ms') {
      options.timeoutMs = Number.parseInt(argv[++index] ?? '', 10);
    } else {
      throw new Error(`Unknown argument: ${argument}`);
    }
  }

  if (!Number.isInteger(options.timeoutMs) || options.timeoutMs < 10_000) {
    throw new Error('--timeout-ms must be an integer of at least 10000');
  }

  return options;
}

function printHelp() {
  process.stdout.write(`Usage: node scripts/audit_codex_skill_budget.mjs [options]\n\n`);
  process.stdout.write(`Options:\n`);
  process.stdout.write(`  --cwd <directory>     Include that directory's project Skills. Default: isolated temp directory.\n`);
  process.stdout.write(`  --codex <executable>  Override the Codex executable.\n`);
  process.stdout.write(`  --timeout-ms <ms>     Codex debug timeout. Default: ${DEFAULT_TIMEOUT_MS}.\n`);
  process.stdout.write(`  --details             Include absolute Skill roots and paths.\n`);
  process.stdout.write(`  --match <text>        Include model-visible entries matching name, path, or description. Repeatable.\n`);
  process.stdout.write(`  --self-test           Test frontmatter description parsing without starting Codex.\n`);
  process.stdout.write(`  --simulate-exact-dedup  Re-run with one copy per exact SHA-256 group disabled in-memory.\n`);
  process.stdout.write(`  -h, --help            Show this help.\n`);
}

function createAuditDirectory(requestedCwd) {
  if (requestedCwd) {
    const resolved = path.resolve(requestedCwd);
    if (!fs.existsSync(resolved) || !fs.statSync(resolved).isDirectory()) {
      throw new Error(`Audit cwd is not a directory: ${resolved}`);
    }
    return { cwd: resolved, temporary: false };
  }

  const created = fs.mkdtempSync(path.join(os.tmpdir(), TEMP_PREFIX));
  return { cwd: created, temporary: true };
}

function cleanupAuditDirectory(auditDirectory) {
  if (!auditDirectory.temporary) return;
  const temporaryRoot = path.resolve(os.tmpdir());
  const resolved = path.resolve(auditDirectory.cwd);
  if (
    path.dirname(resolved) !== temporaryRoot
    || !path.basename(resolved).startsWith(TEMP_PREFIX)
  ) {
    throw new Error(`Refusing to remove unexpected temporary path: ${resolved}`);
  }
  fs.rmSync(resolved, { recursive: true, force: true });
}

function skillConfigOverride(disabledSkillPaths) {
  const rows = disabledSkillPaths.map((skillPath) => (
    `{path=${JSON.stringify(skillPath.replaceAll('\\', '/'))},enabled=false}`
  ));
  return `skills.config=[${rows.join(',')}]`;
}

function runPromptInput(codexInvocation, auditDirectory, timeoutMs, disabledSkillPaths = []) {
  const configArgs = ['-c', 'mcp_servers={}'];
  if (disabledSkillPaths.length > 0) {
    configArgs.push('-c', skillConfigOverride(disabledSkillPaths));
  }
  const result = spawnSync(codexInvocation.command, [
    ...codexInvocation.prefixArgs,
    ...configArgs,
    '-C',
    auditDirectory.cwd,
    'debug',
    'prompt-input',
    'Read-only Codex Skill budget audit.',
  ], {
    encoding: 'utf8',
    timeout: timeoutMs,
    maxBuffer: 8 * 1024 * 1024,
    windowsHide: true,
  });

  if (result.error) {
    throw result.error;
  }
  if (result.status !== 0) {
    const detail = (result.stderr || result.stdout || '').trim();
    throw new Error(`codex debug prompt-input exited ${result.status}: ${detail}`);
  }

  let promptInput;
  try {
    promptInput = JSON.parse(result.stdout);
  } catch (error) {
    throw new Error(`Unable to parse prompt-input JSON: ${error.message}`);
  }

  return {
    promptInput,
    stderr: result.stderr ?? '',
  };
}

function extractSkillsBlock(promptInput) {
  if (!Array.isArray(promptInput)) {
    throw new Error('prompt-input root must be an array');
  }

  const developer = promptInput.find((item) => item?.type === 'message' && item?.role === 'developer');
  if (!developer || !Array.isArray(developer.content)) {
    throw new Error('Developer prompt message is missing');
  }

  const developerText = developer.content
    .filter((item) => item?.type === 'input_text' && typeof item?.text === 'string')
    .map((item) => item.text)
    .join('\n');
  const match = developerText.match(/<skills_instructions>[\s\S]*?<\/skills_instructions>/);
  if (!match) {
    throw new Error('Model-visible skills_instructions block is missing');
  }

  return {
    developerChars: developerText.length,
    block: match[0],
  };
}

function classifyRoot(root) {
  const normalized = root.replaceAll('\\', '/').toLowerCase();
  if (normalized.includes('/.codex/skills/.system')) return 'system-skill';
  if (normalized.includes('/.codex/skills')) return 'user-codex-skill';
  if (normalized.includes('/.agents/skills')) return 'user-agent-skill';
  if (normalized.includes('/.codex/plugins/cache')) return 'plugin-skill';
  return 'project-or-other';
}

function stripYamlQuotes(value) {
  if (value.length >= 2 && value.startsWith('"') && value.endsWith('"')) {
    try {
      return JSON.parse(value);
    } catch {
      return value.slice(1, -1).replaceAll('\\"', '"').replaceAll('\\n', '\n');
    }
  }
  if (value.length >= 2 && value.startsWith("'") && value.endsWith("'")) {
    return value.slice(1, -1).replaceAll("''", "'");
  }
  return value;
}

function parseBlockScalar(lines, startIndex, marker, baseIndent) {
  const blockLines = [];
  for (let index = startIndex + 1; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trim() === '---' && line.length - line.trimStart().length <= baseIndent) break;
    const indent = line.length - line.trimStart().length;
    if (line.trim() !== '' && indent <= baseIndent) break;
    blockLines.push(line);
  }

  const indents = blockLines
    .filter((line) => line.trim() !== '')
    .map((line) => line.length - line.trimStart().length);
  const contentIndent = indents.length > 0 ? Math.min(...indents) : baseIndent + 2;
  const normalized = blockLines.map((line) => (
    line.trim() === '' ? '' : line.slice(Math.min(contentIndent, line.length))
  ));
  const style = marker.startsWith('>') ? 'folded-block' : 'literal-block';
  let value;
  if (style === 'folded-block') {
    value = normalized
      .join('\n')
      .split(/\n{2,}/)
      .map((paragraph) => paragraph.replaceAll('\n', ' ').trim())
      .join('\n\n');
  } else {
    value = normalized.join('\n');
  }

  if (!marker.endsWith('+')) value = value.replace(/\n+$/, '');
  return { value, style };
}

function parseFrontmatterDescription(source) {
  const normalizedSource = source.replace(/^\uFEFF/, '').replaceAll('\r\n', '\n');
  const lines = normalizedSource.split('\n');
  if (lines[0]?.trim() !== '---') {
    return { value: null, style: 'missing-frontmatter' };
  }

  for (let index = 1; index < lines.length; index += 1) {
    const line = lines[index];
    if (line.trim() === '---') break;
    const match = line.match(/^(\s*)description:\s*(.*)$/);
    if (!match) continue;
    const marker = match[2].trim();
    if (/^[|>][+-]?$/.test(marker)) {
      return parseBlockScalar(lines, index, marker, match[1].length);
    }
    const style = marker.startsWith('"')
      ? 'double-quoted'
      : marker.startsWith("'")
        ? 'single-quoted'
        : 'plain';
    return { value: stripYamlQuotes(marker), style };
  }

  return { value: null, style: 'missing-description' };
}

function parseSkillEntries(block) {
  const lines = block.split(/\r?\n/);
  const roots = new Map();
  for (const line of lines) {
    const match = line.match(/^- `(r\d+)` = `(.+)`$/);
    if (match) roots.set(match[1], match[2]);
  }

  const entries = [];
  for (const line of lines) {
    const match = line.match(/^- ([^ ]+): (.*) \(file: (.+)\)$/);
    if (!match) continue;

    const [, name, description, declaredPath] = match;
    const [alias, ...relativeSegments] = declaredPath.split('/');
    const root = roots.get(alias);
    const relativePath = relativeSegments.join('/');
    const absolutePath = root
      ? path.join(root, ...relativeSegments)
      : null;
    const exists = Boolean(absolutePath && fs.existsSync(absolutePath));
    const source = exists ? fs.readFileSync(absolutePath) : null;
    const hash = source
      ? crypto.createHash('sha256').update(source).digest('hex').toUpperCase()
      : null;
    const sourceDescription = source
      ? parseFrontmatterDescription(source.toString('utf8'))
      : { value: null, style: 'missing-file' };
    const sourceDescriptionChars = sourceDescription.value?.length ?? null;
    const descriptionExact = sourceDescription.value === description;
    const effectiveIsSourcePrefix = typeof sourceDescription.value === 'string'
      ? sourceDescription.value.startsWith(description)
      : false;

    entries.push({
      name,
      description,
      descriptionChars: description.length,
      alias,
      root: root ?? null,
      rootKind: root ? classifyRoot(root) : 'unresolved',
      declaredPath,
      relativePath,
      absolutePath,
      exists,
      hash,
      sourceDescription: sourceDescription.value,
      sourceDescriptionStyle: sourceDescription.style,
      sourceDescriptionChars,
      descriptionExact,
      effectiveIsSourcePrefix,
      descriptionTruncated: typeof sourceDescription.value === 'string' && !descriptionExact,
    });
  }

  return { roots, entries };
}

function groupDuplicates(entries, field) {
  const groups = new Map();
  for (const entry of entries) {
    const value = entry[field];
    if (!value) continue;
    if (!groups.has(value)) groups.set(value, []);
    groups.get(value).push(entry);
  }
  return [...groups.entries()]
    .filter(([, members]) => members.length > 1)
    .sort((left, right) => right[1].length - left[1].length || String(left[0]).localeCompare(String(right[0])));
}

function publicMember(entry, details) {
  const member = {
    name: entry.name,
    alias: entry.alias,
    rootKind: entry.rootKind,
    relativePath: entry.relativePath,
  };
  if (details) {
    member.root = entry.root;
    member.absolutePath = entry.absolutePath;
  }
  return member;
}

function summarizeGroups(groups, details, options = {}) {
  return groups.map(([value, members]) => ({
    copies: members.length,
    ...(options.hash ? { sha256: value } : {}),
    ...(options.description ? { description: value } : {}),
    ...(options.name ? { name: value } : {}),
    members: members.map((entry) => publicMember(entry, details)),
  }));
}

function summarizeRoots(entries, details) {
  const grouped = new Map();
  for (const entry of entries) {
    const key = `${entry.alias}|${entry.rootKind}|${entry.root ?? ''}`;
    if (!grouped.has(key)) {
      grouped.set(key, {
        alias: entry.alias,
        rootKind: entry.rootKind,
        root: entry.root,
        skills: 0,
        descriptionChars: 0,
        sourceDescriptionChars: 0,
        parsedSourceDescriptions: 0,
        truncatedSkills: 0,
      });
    }
    const summary = grouped.get(key);
    summary.skills += 1;
    summary.descriptionChars += entry.descriptionChars;
    if (Number.isInteger(entry.sourceDescriptionChars)) {
      summary.sourceDescriptionChars += entry.sourceDescriptionChars;
      summary.parsedSourceDescriptions += 1;
    }
    if (entry.descriptionTruncated) summary.truncatedSkills += 1;
  }

  return [...grouped.values()]
    .sort((left, right) => right.descriptionChars - left.descriptionChars)
    .map((summary) => {
      if (!details) delete summary.root;
      return summary;
    });
}

function warningSummary(stderr) {
  const lines = stderr.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  return {
    source: 'codex-debug-prompt-input-stderr',
    skillDescriptionsShortenedObserved: lines.some((line) => line.includes('Skill descriptions were shortened')),
    codexExecRuntimeProbed: false,
    modelCacheSchemaWarning: lines.some((line) => line.includes('missing field `base_instructions`')),
    remotePluginCatalogWarning: lines.some((line) => line.includes('remote plugin catalog')),
    warningLineCount: lines.filter((line) => /\bWARN\b|\bERROR\b/.test(line)).length,
  };
}

function summarizeDescriptionBudget(entries, details) {
  const parsed = entries.filter((entry) => Number.isInteger(entry.sourceDescriptionChars));
  const truncated = parsed.filter((entry) => entry.descriptionTruncated);
  const exact = parsed.filter((entry) => entry.descriptionExact);
  const prefixTruncated = truncated.filter((entry) => entry.effectiveIsSourcePrefix);
  const sourceDescriptionChars = parsed.reduce(
    (sum, entry) => sum + entry.sourceDescriptionChars,
    0,
  );
  const effectiveDescriptionChars = parsed.reduce(
    (sum, entry) => sum + entry.descriptionChars,
    0,
  );

  return {
    parsedSourceDescriptionCount: parsed.length,
    unparsedSourceDescriptionCount: entries.length - parsed.length,
    sourceDescriptionChars,
    effectiveDescriptionChars,
    lostDescriptionChars: Math.max(0, sourceDescriptionChars - effectiveDescriptionChars),
    effectiveToSourcePercent: sourceDescriptionChars > 0
      ? Number(((100 * effectiveDescriptionChars) / sourceDescriptionChars).toFixed(2))
      : 0,
    truncatedSkillCount: truncated.length,
    truncatedSkillPercent: parsed.length > 0
      ? Number(((100 * truncated.length) / parsed.length).toFixed(2))
      : 0,
    exactDescriptionCount: exact.length,
    effectivePrefixTruncationCount: prefixTruncated.length,
    truncationObserved: truncated.length > 0,
    topTruncatedDescriptions: [...truncated]
      .sort((left, right) => (
        (right.sourceDescriptionChars - right.descriptionChars)
        - (left.sourceDescriptionChars - left.descriptionChars)
      ))
      .slice(0, 25)
      .map((entry) => ({
        ...publicMember(entry, details),
        sourceDescriptionStyle: entry.sourceDescriptionStyle,
        sourceDescriptionChars: entry.sourceDescriptionChars,
        effectiveDescriptionChars: entry.descriptionChars,
        lostDescriptionChars: entry.sourceDescriptionChars - entry.descriptionChars,
        effectiveIsSourcePrefix: entry.effectiveIsSourcePrefix,
      })),
  };
}

function summarizeMatchedSkills(entries, queries, details) {
  const normalizedQueries = queries.map((query) => query.toLocaleLowerCase());
  const skills = entries
    .filter((entry) => {
      const searchable = [
        entry.name,
        entry.relativePath,
        entry.description,
        entry.sourceDescription,
      ]
        .filter((value) => typeof value === 'string')
        .join('\n')
        .toLocaleLowerCase();
      return normalizedQueries.some((query) => searchable.includes(query));
    })
    .sort((left, right) => (
      left.name.localeCompare(right.name)
      || left.alias.localeCompare(right.alias)
      || left.relativePath.localeCompare(right.relativePath)
    ))
    .map((entry) => ({
      ...publicMember(entry, details),
      effectiveDescription: entry.description,
      effectiveDescriptionChars: entry.descriptionChars,
      sourceDescription: entry.sourceDescription,
      sourceDescriptionChars: entry.sourceDescriptionChars,
      descriptionTruncated: entry.descriptionTruncated,
      effectiveIsSourcePrefix: entry.effectiveIsSourcePrefix,
    }));

  return {
    queries,
    count: skills.length,
    skills,
  };
}

function buildReport(promptResult, options, auditDirectory, codexInvocation) {
  const extracted = extractSkillsBlock(promptResult.promptInput);
  const parsed = parseSkillEntries(extracted.block);
  const entries = parsed.entries;
  const exactContentGroups = groupDuplicates(entries.filter((entry) => entry.hash), 'hash');
  const duplicateNameGroups = groupDuplicates(entries, 'name');
  const duplicateDescriptionGroups = groupDuplicates(entries, 'description');
  const descriptionChars = entries.reduce((sum, entry) => sum + entry.descriptionChars, 0);

  return {
    schema: SCHEMA,
    status: 'ok',
    generatedAt: new Date().toISOString(),
    scope: auditDirectory.temporary ? 'global-isolated' : 'provided-cwd',
    readOnly: true,
    codex: {
      kind: codexInvocation.kind,
      version: readCodexVersion(codexInvocation),
      ...(options.details ? { source: codexInvocation.source } : {}),
    },
    effectivePrompt: {
      developerChars: extracted.developerChars,
      skillsBlockChars: extracted.block.length,
      skillCount: entries.length,
      resolvedSkillCount: entries.filter((entry) => entry.exists).length,
      missingSkillCount: entries.filter((entry) => !entry.exists).length,
      descriptionChars,
      descriptionLengthHistogram: Object.fromEntries(
        [...new Set(entries.map((entry) => entry.descriptionChars))]
          .sort((left, right) => left - right)
          .map((length) => [String(length), entries.filter((entry) => entry.descriptionChars === length).length]),
      ),
    },
    descriptionBudget: summarizeDescriptionBudget(entries, options.details),
    ...(options.matches.length > 0
      ? { matchedSkills: summarizeMatchedSkills(entries, options.matches, options.details) }
      : {}),
    sources: summarizeRoots(entries, options.details),
    duplicates: {
      exactContentGroupCount: exactContentGroups.length,
      exactContentEntryCount: exactContentGroups.reduce((sum, [, members]) => sum + members.length, 0),
      exactContentGroups: summarizeGroups(exactContentGroups, options.details, { hash: true }),
      duplicateNameGroupCount: duplicateNameGroups.length,
      duplicateNameGroups: summarizeGroups(duplicateNameGroups, options.details, { name: true }),
      duplicateDescriptionGroupCount: duplicateDescriptionGroups.length,
      duplicateDescriptionGroups: summarizeGroups(duplicateDescriptionGroups, options.details, { description: true }),
    },
    runtimeWarnings: warningSummary(promptResult.stderr),
  };
}

function selectExactDuplicateCopies(internalReport) {
  const selected = [];
  for (const group of internalReport.duplicates.exactContentGroups) {
    const members = [...group.members].sort((left, right) => (
      left.rootKind.localeCompare(right.rootKind)
      || left.alias.localeCompare(right.alias)
      || left.relativePath.localeCompare(right.relativePath)
    ));
    for (const member of members.slice(1)) {
      selected.push({
        sha256: group.sha256,
        ...member,
      });
    }
  }
  return selected;
}

function counterfactualSnapshot(report) {
  return {
    effectiveSkills: report.effectivePrompt.skillCount,
    effectiveDescriptionChars: report.effectivePrompt.descriptionChars,
    sourceDescriptionChars: report.descriptionBudget.sourceDescriptionChars,
    lostDescriptionChars: report.descriptionBudget.lostDescriptionChars,
    effectiveToSourcePercent: report.descriptionBudget.effectiveToSourcePercent,
    truncatedSkills: report.descriptionBudget.truncatedSkillCount,
    exactContentDuplicateGroups: report.duplicates.exactContentGroupCount,
    exactContentDuplicateEntries: report.duplicates.exactContentEntryCount,
  };
}

function counterfactualDelta(before, after) {
  return Object.fromEntries(
    Object.keys(before).map((key) => [
      key,
      typeof before[key] === 'number' && typeof after[key] === 'number'
        ? Number((after[key] - before[key]).toFixed(2))
        : null,
    ]),
  );
}

function publicCounterfactualMember(member, details) {
  return {
    sha256: member.sha256,
    name: member.name,
    alias: member.alias,
    rootKind: member.rootKind,
    relativePath: member.relativePath,
    ...(details ? { root: member.root, absolutePath: member.absolutePath } : {}),
  };
}

function runSelfTest() {
  const cases = [
    {
      name: 'plain',
      source: '---\nname: plain\ndescription: Plain description\n---\n',
      expected: 'Plain description',
      style: 'plain',
    },
    {
      name: 'double-quoted',
      source: '---\nname: quoted\ndescription: "Quoted description"\n---\n',
      expected: 'Quoted description',
      style: 'double-quoted',
    },
    {
      name: 'single-quoted',
      source: "---\nname: quoted\ndescription: 'Owner''s workflow'\n---\n",
      expected: "Owner's workflow",
      style: 'single-quoted',
    },
    {
      name: 'literal-block',
      source: '---\nname: literal\ndescription: |\n  First line\n  Second line\n---\n',
      expected: 'First line\nSecond line',
      style: 'literal-block',
    },
    {
      name: 'folded-block',
      source: '---\nname: folded\ndescription: >\n  First line\n  Second line\n---\n',
      expected: 'First line Second line',
      style: 'folded-block',
    },
  ];

  const results = cases.map((testCase) => {
    const actual = parseFrontmatterDescription(testCase.source);
    return {
      name: testCase.name,
      passed: actual.value === testCase.expected && actual.style === testCase.style,
      actual,
      expected: { value: testCase.expected, style: testCase.style },
    };
  });
  const matchingFixture = [
    {
      name: 'suxi-ota-ops',
      alias: 'r1',
      relativePath: 'suxi-ota-ops/SKILL.md',
      description: 'OTA data collection',
      descriptionChars: 19,
      sourceDescription: 'OTA data collection and import',
      sourceDescriptionChars: 30,
      descriptionTruncated: true,
      effectiveIsSourcePrefix: true,
    },
    {
      name: 'unrelated',
      alias: 'r2',
      relativePath: 'unrelated/SKILL.md',
      description: 'PDF editing',
      descriptionChars: 11,
      sourceDescription: 'PDF editing',
      sourceDescriptionChars: 11,
      descriptionTruncated: false,
      effectiveIsSourcePrefix: true,
    },
  ];
  const matchResult = summarizeMatchedSkills(matchingFixture, ['ota'], false);
  const matchCase = {
    name: 'matched-skill-filter',
    passed: matchResult.count === 1
      && matchResult.skills[0]?.name === 'suxi-ota-ops'
      && matchResult.skills[0]?.effectiveDescription === 'OTA data collection'
      && !Object.hasOwn(matchResult.skills[0] ?? {}, 'absolutePath'),
    actual: matchResult,
    expected: {
      count: 1,
      name: 'suxi-ota-ops',
      effectiveDescription: 'OTA data collection',
      absolutePathExcluded: true,
    },
  };
  const passed = results.every((result) => result.passed) && matchCase.passed;
  const payload = {
    schema: SCHEMA,
    status: passed ? 'passed' : 'failed',
    cases: results,
    matchCase,
  };
  process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`);
  if (!passed) process.exitCode = 1;
}

function main() {
  let auditDirectory = null;
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

    const codexInvocation = resolveCodexExecutable(options.codex);
    auditDirectory = createAuditDirectory(options.cwd);
    const promptResult = runPromptInput(codexInvocation, auditDirectory, options.timeoutMs);
    const report = buildReport(promptResult, options, auditDirectory, codexInvocation);
    if (options.simulateExactDedup) {
      const internalReport = buildReport(
        promptResult,
        { ...options, details: true },
        auditDirectory,
        codexInvocation,
      );
      const selected = selectExactDuplicateCopies(internalReport);
      const disabledPaths = selected.map((member) => member.absolutePath);
      const simulatedPrompt = runPromptInput(
        codexInvocation,
        auditDirectory,
        options.timeoutMs,
        disabledPaths,
      );
      const simulatedReport = buildReport(
        simulatedPrompt,
        options,
        auditDirectory,
        codexInvocation,
      );
      const before = counterfactualSnapshot(report);
      const after = counterfactualSnapshot(simulatedReport);
      report.counterfactualExactDedup = {
        status: 'simulated',
        persistentConfigChanged: false,
        persistentChangeRecommended: false,
        requiresOwnershipReview: true,
        selectionPolicy: 'Keep the first deterministic member of each exact SHA-256 group; disable the remaining copies only for the second prompt-input run.',
        evidenceBoundary: 'Exact byte equality proves duplicate content, not that an alias or compatibility entry is safe to disable. prompt-input does not prove that the codex exec 2% warning disappears.',
        disabledSkillCount: selected.length,
        disabledSkills: selected.map((member) => publicCounterfactualMember(member, options.details)),
        before,
        after,
        delta: counterfactualDelta(before, after),
        runtimeWarnings: simulatedReport.runtimeWarnings,
      };
    }
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  } catch (error) {
    const payload = {
      schema: SCHEMA,
      status: 'error',
      error: {
        message: error instanceof Error ? error.message : String(error),
        ...(options?.details && error instanceof Error ? { stack: error.stack } : {}),
      },
    };
    process.stderr.write(`${JSON.stringify(payload, null, 2)}\n`);
    process.exitCode = 1;
  } finally {
    if (auditDirectory) cleanupAuditDirectory(auditDirectory);
  }
}

main();
