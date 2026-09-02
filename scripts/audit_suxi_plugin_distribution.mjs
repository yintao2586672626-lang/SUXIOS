#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { publicPath } from './lib/codex_cli_runtime.mjs';

const SCHEMA = 'suxi.plugin-distribution-audit.v1';
const DEFAULT_PLUGIN_NAME = 'suxi-os-toolkit';
const DEFAULT_STABILITY_WINDOW_MS = 1_000;
const MAX_STABILITY_WINDOW_MS = 10_000;
const DEFAULT_LIST_LIMIT = 100;
const SCRIPT_PATH = fileURLToPath(import.meta.url);

function requireValue(argv, index, argument) {
  const value = argv[index + 1];
  if (typeof value !== 'string' || !value.trim() || value.startsWith('--')) {
    throw new Error(`${argument} requires a non-empty value`);
  }
  return value;
}

export function parseArgs(argv) {
  const options = {
    activeCacheRoot: path.join(os.homedir(), '.codex', 'plugins', 'cache'),
    cwd: null,
    details: false,
    help: false,
    marketplacePath: path.join(
      os.homedir(),
      '.codex',
      'plugins',
      'cache',
      'suxi-local',
      '.agents',
      'plugins',
      'marketplace.json',
    ),
    pluginName: DEFAULT_PLUGIN_NAME,
    repoPlugin: path.join('plugins', DEFAULT_PLUGIN_NAME),
    selfTest: false,
    stabilityWindowMs: DEFAULT_STABILITY_WINDOW_MS,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--help' || argument === '-h') {
      options.help = true;
    } else if (argument === '--details') {
      options.details = true;
    } else if (argument === '--self-test') {
      options.selfTest = true;
    } else if (argument === '--active-cache-root') {
      options.activeCacheRoot = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--cwd') {
      options.cwd = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--marketplace-path') {
      options.marketplacePath = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--plugin-name') {
      options.pluginName = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--repo-plugin') {
      options.repoPlugin = requireValue(argv, index, argument);
      index += 1;
    } else if (argument === '--stability-window-ms') {
      options.stabilityWindowMs = Number.parseInt(requireValue(argv, index, argument), 10);
      index += 1;
    } else {
      throw new Error(`Unknown argument: ${argument}`);
    }
  }

  if (
    !Number.isInteger(options.stabilityWindowMs)
    || options.stabilityWindowMs < 0
    || options.stabilityWindowMs > MAX_STABILITY_WINDOW_MS
  ) {
    throw new Error(
      `--stability-window-ms must be an integer between 0 and ${MAX_STABILITY_WINDOW_MS}`,
    );
  }
  if (!/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/u.test(options.pluginName)) {
    throw new Error(`Invalid --plugin-name: ${options.pluginName}`);
  }
  return options;
}

function printHelp() {
  process.stdout.write('Read-only SUXIOS local plugin distribution drift audit.\n\n');
  process.stdout.write('Usage: node scripts/audit_suxi_plugin_distribution.mjs [options]\n\n');
  process.stdout.write('Options:\n');
  process.stdout.write('  --cwd <directory>             Workspace root. Default: current directory.\n');
  process.stdout.write(`  --repo-plugin <directory>     Candidate plugin path. Default: plugins/${DEFAULT_PLUGIN_NAME}.\n`);
  process.stdout.write('  --marketplace-path <file>      Installed local marketplace.json.\n');
  process.stdout.write(`  --plugin-name <name>           Plugin name. Default: ${DEFAULT_PLUGIN_NAME}.\n`);
  process.stdout.write('  --active-cache-root <dir>      Codex plugin cache root.\n');
  process.stdout.write(`  --stability-window-ms <ms>     Candidate resample window. Default: ${DEFAULT_STABILITY_WINDOW_MS}.\n`);
  process.stdout.write('  --details                      Include absolute roots and full path lists.\n');
  process.stdout.write('  --self-test                    Run offline tree comparison and gate tests.\n');
  process.stdout.write('  -h, --help                     Show this help.\n');
}

function assertDirectory(directory, label) {
  if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) {
    throw new Error(`${label} is not a directory: ${directory}`);
  }
}

function assertFile(file, label) {
  if (!fs.existsSync(file) || !fs.statSync(file).isFile()) {
    throw new Error(`${label} is not a file: ${file}`);
  }
}

function readJson(file, label) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    throw new Error(`Unable to parse ${label}: ${error.message}`);
  }
}

function manifestVersion(pluginRoot) {
  const manifestPath = path.join(pluginRoot, '.codex-plugin', 'plugin.json');
  assertFile(manifestPath, 'plugin manifest');
  const manifest = readJson(manifestPath, 'plugin manifest');
  if (typeof manifest.version !== 'string' || !manifest.version.trim()) {
    throw new Error(`Plugin manifest has no version: ${manifestPath}`);
  }
  return manifest.version;
}

function normalizeRelative(relativePath) {
  return relativePath.replaceAll('\\', '/');
}

function hashBuffer(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex').toUpperCase();
}

function walkTree(root, directory = root, entries = new Map()) {
  const children = fs.readdirSync(directory, { withFileTypes: true })
    .sort((left, right) => left.name.localeCompare(right.name));
  for (const child of children) {
    const absolutePath = path.join(directory, child.name);
    const relativePath = normalizeRelative(path.relative(root, absolutePath));
    if (child.isDirectory()) {
      walkTree(root, absolutePath, entries);
    } else if (child.isSymbolicLink()) {
      const target = fs.readlinkSync(absolutePath);
      entries.set(relativePath, {
        bytes: Buffer.byteLength(target, 'utf8'),
        hash: hashBuffer(Buffer.from(target, 'utf8')),
        kind: 'symlink',
      });
    } else if (child.isFile()) {
      const content = fs.readFileSync(absolutePath);
      entries.set(relativePath, {
        bytes: content.length,
        hash: hashBuffer(content),
        kind: 'file',
      });
    }
  }
  return entries;
}

export function treeDigest(entries) {
  const hash = crypto.createHash('sha256');
  for (const [relativePath, entry] of [...entries.entries()].sort(([left], [right]) => (
    left.localeCompare(right)
  ))) {
    hash.update(relativePath);
    hash.update('\0');
    hash.update(entry.kind);
    hash.update('\0');
    hash.update(String(entry.bytes));
    hash.update('\0');
    hash.update(entry.hash);
    hash.update('\0');
  }
  return hash.digest('hex').toUpperCase();
}

export function compareFileMaps(left, right) {
  const missingInRight = [];
  const extraInRight = [];
  const contentDifferences = [];
  for (const [relativePath, leftEntry] of left.entries()) {
    const rightEntry = right.get(relativePath);
    if (!rightEntry) {
      missingInRight.push(relativePath);
    } else if (
      leftEntry.hash !== rightEntry.hash
      || leftEntry.kind !== rightEntry.kind
      || leftEntry.bytes !== rightEntry.bytes
    ) {
      contentDifferences.push(relativePath);
    }
  }
  for (const relativePath of right.keys()) {
    if (!left.has(relativePath)) extraInRight.push(relativePath);
  }
  for (const list of [missingInRight, extraInRight, contentDifferences]) list.sort();
  return {
    contentDifferences,
    extraInRight,
    inSync: missingInRight.length === 0
      && extraInRight.length === 0
      && contentDifferences.length === 0,
    missingInRight,
  };
}

function skillNames(entries) {
  const names = new Set();
  for (const relativePath of entries.keys()) {
    const match = relativePath.match(/^skills\/([^/]+)\/SKILL\.md$/u);
    if (match) names.add(match[1]);
  }
  return names;
}

export function changedSkillNames(comparison) {
  const names = new Set();
  for (const relativePath of [
    ...comparison.missingInRight,
    ...comparison.extraInRight,
    ...comparison.contentDifferences,
  ]) {
    const match = relativePath.match(/^skills\/([^/]+)\//u);
    if (match) names.add(match[1]);
  }
  return [...names].sort();
}

function difference(left, right) {
  return [...left].filter((value) => !right.has(value)).sort();
}

function sleep(milliseconds) {
  if (milliseconds <= 0) return;
  const signal = new Int32Array(new SharedArrayBuffer(4));
  Atomics.wait(signal, 0, 0, milliseconds);
}

function resolveInputs(options) {
  const cwd = path.resolve(options.cwd ?? process.cwd());
  assertDirectory(cwd, 'cwd');
  const repoPlugin = path.resolve(cwd, options.repoPlugin);
  assertDirectory(repoPlugin, 'repo plugin');
  const marketplacePath = path.resolve(options.marketplacePath);
  assertFile(marketplacePath, 'marketplace');
  const marketplace = readJson(marketplacePath, 'marketplace');
  if (typeof marketplace.name !== 'string' || !marketplace.name.trim()) {
    throw new Error('Marketplace has no name');
  }
  const matchingEntries = (marketplace.plugins ?? []).filter((entry) => (
    entry?.name === options.pluginName
  ));
  if (matchingEntries.length !== 1) {
    throw new Error(
      `Expected one marketplace entry for ${options.pluginName}, got ${matchingEntries.length}`,
    );
  }
  const entry = matchingEntries[0];
  if (entry.source?.source !== 'local' || typeof entry.source?.path !== 'string') {
    throw new Error(`Marketplace entry is not a local source: ${options.pluginName}`);
  }
  const marketplaceRoot = path.dirname(path.dirname(path.dirname(marketplacePath)));
  const sourcePlugin = path.resolve(marketplaceRoot, entry.source.path);
  assertDirectory(sourcePlugin, 'marketplace source plugin');
  const sourceVersion = manifestVersion(sourcePlugin);
  const activeCacheRoot = path.resolve(options.activeCacheRoot);
  const activePlugin = path.join(
    activeCacheRoot,
    marketplace.name,
    options.pluginName,
    sourceVersion,
  );
  return {
    activeCacheRoot,
    activePlugin,
    cwd,
    marketplaceName: marketplace.name,
    marketplacePath,
    pluginName: options.pluginName,
    repoPlugin,
    sourcePlugin,
  };
}

export function deriveDistributionStatus({
  activeExists,
  candidateObservedStable,
  candidateToSource,
  sourceToActive,
  versionsAligned,
}) {
  if (!candidateObservedStable) return 'candidate_changed_during_observation';
  if (!activeExists) return 'installation_missing';
  if (!versionsAligned) return 'version_drift';
  if (!sourceToActive.inSync) return 'installation_drift';
  if (!candidateToSource.inSync) return 'candidate_ahead';
  return 'synchronized';
}

function publicList(values, details) {
  return {
    count: values.length,
    items: details ? values : values.slice(0, DEFAULT_LIST_LIMIT),
    truncated: !details && values.length > DEFAULT_LIST_LIMIT,
  };
}

function publicComparison(comparison, details) {
  return {
    inSync: comparison.inSync,
    changedSkills: changedSkillNames(comparison),
    missingInRight: publicList(comparison.missingInRight, details),
    extraInRight: publicList(comparison.extraInRight, details),
    contentDifferences: publicList(comparison.contentDifferences, details),
  };
}

export function auditDistribution(inputs, options) {
  const candidateBefore = walkTree(inputs.repoPlugin);
  const beforeDigest = treeDigest(candidateBefore);
  sleep(options.stabilityWindowMs);
  const candidate = walkTree(inputs.repoPlugin);
  const candidateDigest = treeDigest(candidate);
  const candidateObservedStable = beforeDigest === candidateDigest;
  const source = walkTree(inputs.sourcePlugin);
  const activeExists = fs.existsSync(inputs.activePlugin)
    && fs.statSync(inputs.activePlugin).isDirectory();
  const active = activeExists ? walkTree(inputs.activePlugin) : new Map();

  const candidateToSource = compareFileMaps(candidate, source);
  const sourceToActive = compareFileMaps(source, active);
  const candidateToActive = compareFileMaps(candidate, active);
  const versions = {
    candidate: manifestVersion(inputs.repoPlugin),
    source: manifestVersion(inputs.sourcePlugin),
    active: activeExists ? manifestVersion(inputs.activePlugin) : null,
  };
  const versionsAligned = activeExists
    && new Set(Object.values(versions)).size === 1;
  const status = deriveDistributionStatus({
    activeExists,
    candidateObservedStable,
    candidateToSource,
    sourceToActive,
    versionsAligned,
  });
  const candidateSkills = skillNames(candidate);
  const sourceSkills = skillNames(source);
  const activeSkills = skillNames(active);

  return {
    active,
    activeExists,
    activeSkills,
    candidate,
    candidateDigest,
    candidateObservedStable,
    candidateSkills,
    candidateToActive,
    candidateToSource,
    source,
    sourceSkills,
    sourceToActive,
    status,
    versions,
    versionsAligned,
  };
}

function buildReport(inputs, options, audit) {
  const distributionSynchronized = audit.status === 'synchronized';
  return {
    schema: SCHEMA,
    status: audit.status,
    generatedAt: new Date().toISOString(),
    readOnly: true,
    persistentConfigChanged: false,
    identity: {
      marketplace: inputs.marketplaceName,
      plugin: inputs.pluginName,
      roots: {
        candidate: publicPath(inputs.repoPlugin, inputs.cwd, options.details),
        source: publicPath(inputs.sourcePlugin, inputs.cwd, options.details),
        active: publicPath(inputs.activePlugin, inputs.cwd, options.details),
      },
    },
    stability: {
      windowMs: options.stabilityWindowMs,
      candidateDigest: audit.candidateDigest,
      observedStableDuringWindow: audit.candidateObservedStable,
      evidenceBoundary: 'No observed hash change during a short local window does not prove that no external task or process owns or may later modify the candidate.',
    },
    versions: {
      ...audit.versions,
      aligned: audit.versionsAligned,
    },
    trees: {
      candidate: { files: audit.candidate.size, skills: audit.candidateSkills.size },
      source: { files: audit.source.size, skills: audit.sourceSkills.size },
      active: { exists: audit.activeExists, files: audit.active.size, skills: audit.activeSkills.size },
      candidateOnlySkills: difference(audit.candidateSkills, audit.sourceSkills),
      sourceOnlySkills: difference(audit.sourceSkills, audit.candidateSkills),
      sourceNotActiveSkills: difference(audit.sourceSkills, audit.activeSkills),
      activeOnlySkills: difference(audit.activeSkills, audit.sourceSkills),
    },
    comparisons: {
      candidateToSource: publicComparison(audit.candidateToSource, options.details),
      sourceToActive: publicComparison(audit.sourceToActive, options.details),
      candidateToActive: publicComparison(audit.candidateToActive, options.details),
    },
    publishGate: {
      distributionSynchronized,
      automaticPublishRecommended: false,
      publishActionAuthorized: false,
      requiresExternalWriterCheck: !audit.candidateToSource.inSync,
      nextSafeAction: distributionSynchronized
        ? 'No distribution write is needed.'
        : 'Resolve writer ownership, validate the final candidate, then use the plugin cachebuster and reinstall workflow.',
    },
    evidenceBoundary: 'This audit proves file-tree and version relationships at one observed time. It does not grant publish authority, prove writer ownership, validate Skill behavior, or prove deployment and production state.',
  };
}

function selfTestCase(name, passed, actual, expected) {
  return { actual, expected, name, passed };
}

function runSelfTest() {
  const left = new Map([
    ['skills/alpha/SKILL.md', { bytes: 1, hash: 'A', kind: 'file' }],
    ['skills/beta/SKILL.md', { bytes: 1, hash: 'B', kind: 'file' }],
    ['same.txt', { bytes: 1, hash: 'S', kind: 'file' }],
  ]);
  const right = new Map([
    ['skills/alpha/SKILL.md', { bytes: 2, hash: 'X', kind: 'file' }],
    ['skills/gamma/SKILL.md', { bytes: 1, hash: 'G', kind: 'file' }],
    ['same.txt', { bytes: 1, hash: 'S', kind: 'file' }],
  ]);
  const comparison = compareFileMaps(left, right);
  const cleanComparison = compareFileMaps(left, new Map(left));
  const candidateAhead = deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: true,
    candidateToSource: comparison,
    sourceToActive: cleanComparison,
    versionsAligned: true,
  });
  const installationDrift = deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: true,
    candidateToSource: cleanComparison,
    sourceToActive: comparison,
    versionsAligned: true,
  });
  const versionDrift = deriveDistributionStatus({
    activeExists: true,
    candidateObservedStable: true,
    candidateToSource: cleanComparison,
    sourceToActive: cleanComparison,
    versionsAligned: false,
  });
  const crossVolumeRoot = process.platform === 'win32'
    ? publicPath('Z:\\private\\suxi-os-toolkit', 'C:\\workspace', false)
    : publicPath('/private/suxi-os-toolkit', '/workspace', false);
  const cases = [
    selfTestCase(
      'missing-extra-and-content-drift',
      comparison.missingInRight.join(',') === 'skills/beta/SKILL.md'
        && comparison.extraInRight.join(',') === 'skills/gamma/SKILL.md'
        && comparison.contentDifferences.join(',') === 'skills/alpha/SKILL.md',
      comparison,
      'one missing, one extra, one changed',
    ),
    selfTestCase(
      'changed-skill-names',
      changedSkillNames(comparison).join(',') === 'alpha,beta,gamma',
      changedSkillNames(comparison),
      ['alpha', 'beta', 'gamma'],
    ),
    selfTestCase('clean-tree-comparison', cleanComparison.inSync, cleanComparison.inSync, true),
    selfTestCase('candidate-ahead-status', candidateAhead === 'candidate_ahead', candidateAhead, 'candidate_ahead'),
    selfTestCase(
      'installation-drift-status',
      installationDrift === 'installation_drift',
      installationDrift,
      'installation_drift',
    ),
    selfTestCase('version-drift-status', versionDrift === 'version_drift', versionDrift, 'version_drift'),
    selfTestCase(
      'tree-digest-is-order-independent',
      treeDigest(left) === treeDigest(new Map([...left].reverse())),
      treeDigest(left),
      treeDigest(new Map([...left].reverse())),
    ),
    selfTestCase(
      'cross-volume-root-hidden-by-default',
      crossVolumeRoot === 'suxi-os-toolkit',
      crossVolumeRoot,
      'suxi-os-toolkit',
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
    const audit = auditDistribution(inputs, options);
    process.stdout.write(`${JSON.stringify(buildReport(inputs, options, audit), null, 2)}\n`);
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
