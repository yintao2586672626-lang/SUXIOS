import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const DEFAULT_ITERATIONS = 10;
const DEFAULT_BASE_URL = 'http://localhost:8080/';
const DEFAULT_USERNAME = 'admin';
const DEFAULT_PASSWORD = 'admin123';

const SUITES = [
  {
    name: 'e2e-contracts',
    profiles: ['quick', 'extreme'],
    repeat: 'once',
    command: ['scripts/verify_e2e_contracts.mjs'],
    timeoutMs: 120000,
  },
  {
    name: 'type-check',
    profiles: ['quick', 'extreme'],
    repeat: 'once',
    command: ['scripts/verify_typecheck_or_skip.mjs'],
    timeoutMs: 120000,
  },
  {
    name: 'module-smoke',
    profiles: ['quick', 'extreme'],
    repeat: 'each',
    outputSuite: 'module-smoke',
    command: ['node_modules/@playwright/test/cli.js', 'test', 'tests/automation/module-smoke.spec.js', '--workers=1', '--reporter=list'],
    env: { E2E_MUTATE: '1', E2E_ITERATIONS: '1' },
    timeoutMs: 600000,
  },
  {
    name: 'daily-regression',
    profiles: ['quick', 'extreme'],
    repeat: 'each',
    outputSuite: 'daily-regression',
    command: ['node_modules/@playwright/test/cli.js', 'test', 'tests/automation/daily-regression.spec.js', '--workers=1', '--reporter=list'],
    timeoutMs: 300000,
  },
  {
    name: 'business-chains',
    profiles: ['quick', 'extreme'],
    repeat: 'each',
    outputSuite: 'business-chains',
    command: ['node_modules/@playwright/test/cli.js', 'test', 'tests/automation/business-chains.spec.js', '--workers=1', '--reporter=list'],
    env: { E2E_API_REQUEST_TIMEOUT_MS: '15000' },
    timeoutMs: 600000,
  },
  {
    name: 'async-page-guard',
    profiles: ['quick', 'extreme'],
    repeat: 'each',
    outputSuite: 'async-page-guard',
    command: ['node_modules/@playwright/test/cli.js', 'test', 'tests/automation/async-page-guard.spec.js', '--workers=1', '--reporter=list'],
    timeoutMs: 600000,
  },
  {
    name: 'full-click',
    profiles: ['extreme'],
    repeat: 'once',
    outputSuite: 'full-click',
    command: ['node_modules/@playwright/test/cli.js', 'test', 'tests/automation/full-click-coverage.spec.js', '--workers=1', '--reporter=list'],
    env: {
      E2E_MUTATE: '1',
      E2E_ALLOW_DESTRUCTIVE: '0',
      E2E_DB_BACKUP: '1',
      E2E_DB_RESTORE: '0',
      E2E_MAX_BUTTONS_PER_MODULE: '30',
      E2E_MAX_FIELDS_PER_MODULE: '40',
      E2E_LOOP: '50',
    },
    timeoutMs: 3600000,
  },
];

function parseArgs(argv) {
  const options = {
    dryRun: false,
    profile: 'extreme',
    iterations: DEFAULT_ITERATIONS,
    runId: new Date().toISOString().replace(/[:.]/g, '-'),
    outputDir: path.join(root, 'output', 'codex-runner'),
    baseUrl: process.env.E2E_BASE_URL || DEFAULT_BASE_URL,
    username: process.env.E2E_USERNAME || DEFAULT_USERNAME,
    password: process.env.E2E_PASSWORD || DEFAULT_PASSWORD,
    continueOnFailure: true,
    suites: null,
  };

  for (const arg of argv) {
    if (arg === '--dry-run') {
      options.dryRun = true;
    } else if (arg === '--fail-fast') {
      options.continueOnFailure = false;
    } else if (arg.startsWith('--profile=')) {
      options.profile = arg.slice('--profile='.length).trim();
    } else if (arg.startsWith('--iterations=')) {
      options.iterations = Number(arg.slice('--iterations='.length));
    } else if (arg.startsWith('--run-id=')) {
      options.runId = safeFileName(arg.slice('--run-id='.length));
    } else if (arg.startsWith('--output-dir=')) {
      options.outputDir = path.resolve(root, arg.slice('--output-dir='.length));
    } else if (arg.startsWith('--base-url=')) {
      options.baseUrl = arg.slice('--base-url='.length);
    } else if (arg.startsWith('--username=')) {
      options.username = arg.slice('--username='.length);
    } else if (arg.startsWith('--password=')) {
      options.password = arg.slice('--password='.length);
    } else if (arg.startsWith('--suites=')) {
      options.suites = arg.slice('--suites='.length).split(',').map((item) => item.trim()).filter(Boolean);
    } else if (arg === '--help' || arg === '-h') {
      printHelp();
      process.exit(0);
    } else {
      throw new Error(`Unknown argument: ${arg}`);
    }
  }

  if (!['quick', 'extreme'].includes(options.profile)) {
    throw new Error('--profile must be quick or extreme');
  }
  if (!Number.isFinite(options.iterations) || options.iterations < 1) {
    throw new Error('--iterations must be a positive number');
  }
  options.iterations = Math.floor(options.iterations);
  return options;
}

function printHelp() {
  console.log(`Codex automation runner

Usage:
  node scripts/codex_automation_runner.mjs [options]

Options:
  --profile=extreme|quick     extreme runs all suites, quick skips full-click
  --iterations=10             repeat iterative E2E suites
  --suites=a,b                run selected suite names only
  --dry-run                   write planned commands without executing
  --fail-fast                 stop after first failed command
  --base-url=http://...       E2E_BASE_URL override
  --username=admin            E2E_USERNAME override
  --password=admin123         E2E_PASSWORD override
  --output-dir=output/path    report root
  --run-id=name               deterministic report folder name`);
}

function safeFileName(value) {
  return String(value || 'run').replace(/[^\w.-]+/g, '_');
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function commandText(command) {
  return [process.execPath, ...command].map((item) => (/\s/.test(item) ? `"${item}"` : item)).join(' ');
}

function appendLog(file, text) {
  fs.appendFileSync(file, text, 'utf8');
}

function writeJson(file, value) {
  fs.writeFileSync(file, JSON.stringify(value, null, 2), 'utf8');
}

function selectedSuites(options) {
  const suites = SUITES.filter((suite) => suite.profiles.includes(options.profile));
  if (!options.suites) return suites;
  const known = new Set(SUITES.map((suite) => suite.name));
  for (const name of options.suites) {
    if (!known.has(name)) throw new Error(`Unknown suite: ${name}`);
  }
  return suites.filter((suite) => options.suites.includes(suite.name));
}

function plannedCommands(options, suites) {
  const commands = [];
  for (const suite of suites) {
    const iterations = suite.repeat === 'each' ? options.iterations : 1;
    for (let iteration = 1; iteration <= iterations; iteration += 1) {
      commands.push({ suite, iteration, iterationCount: iterations });
    }
  }
  return commands;
}

function buildEnv(options, suite, iteration) {
  const env = {
    ...process.env,
    E2E_BASE_URL: options.baseUrl,
    E2E_USERNAME: options.username,
    E2E_PASSWORD: options.password,
    E2E_RUN_ID: `${options.runId}-${safeFileName(suite.name)}-${String(iteration).padStart(2, '0')}`,
    E2E_INPUT_PROFILE: options.profile === 'extreme' ? 'extreme' : (process.env.E2E_INPUT_PROFILE || 'normal'),
    E2E_EXTREME_INPUTS: options.profile === 'extreme' ? '1' : (process.env.E2E_EXTREME_INPUTS || '0'),
    ...(suite.env || {}),
  };
  return env;
}

function collectLatestRun(suite) {
  if (!suite.outputSuite) return null;
  const latestPath = path.join(root, 'output', 'playwright', suite.outputSuite, 'latest-run.json');
  if (!fs.existsSync(latestPath)) return null;
  try {
    const latest = JSON.parse(fs.readFileSync(latestPath, 'utf8'));
    const summaryPath = latest.outputDir ? path.join(latest.outputDir, 'summary.json') : '';
    return {
      latestRun: latest,
      summaryPath: fs.existsSync(summaryPath) ? summaryPath : null,
    };
  } catch {
    return null;
  }
}

function runCommand({ options, suite, iteration, runDir, commandLog, exceptionLog }) {
  const startedAt = new Date();
  const env = buildEnv(options, suite, iteration);
  const logName = `${String(iteration).padStart(2, '0')}_${safeFileName(suite.name)}.log`;
  const logPath = path.join(runDir, 'logs', logName);
  const base = {
    suite: suite.name,
    iteration,
    repeat: suite.repeat,
    command: commandText(suite.command),
    logPath,
    startedAt: startedAt.toISOString(),
    status: 'running',
    exitCode: null,
    durationMs: 0,
    artifact: null,
  };

  ensureDir(path.dirname(logPath));
  appendLog(commandLog, `[${base.startedAt}] ${options.dryRun ? 'DRY ' : ''}RUN ${suite.name} iteration ${iteration}\n`);
  appendLog(logPath, `suite=${suite.name}\niteration=${iteration}\ncommand=${base.command}\n`);

  if (options.dryRun) {
    appendLog(logPath, `dryRun=true\nprofile=${options.profile}\nbaseUrl=${options.baseUrl}\n`);
    return {
      ...base,
      status: 'dry-run',
      exitCode: 0,
      durationMs: Date.now() - startedAt.getTime(),
      artifact: null,
    };
  }

  const result = spawnSync(process.execPath, suite.command, {
    cwd: root,
    env,
    encoding: 'utf8',
    shell: false,
    timeout: suite.timeoutMs,
    maxBuffer: 50 * 1024 * 1024,
  });
  const durationMs = Date.now() - startedAt.getTime();
  const exitCode = result.status ?? (result.error ? 1 : 0);
  const stdout = result.stdout || '';
  const stderr = result.stderr || '';
  const error = result.error ? String(result.error.stack || result.error.message || result.error) : '';

  appendLog(logPath, `exitCode=${exitCode}\ndurationMs=${durationMs}\n\n[stdout]\n${stdout}\n\n[stderr]\n${stderr}\n`);
  if (error) appendLog(logPath, `\n[spawnError]\n${error}\n`);

  const commandResult = {
    ...base,
    status: exitCode === 0 ? 'passed' : 'failed',
    exitCode,
    durationMs,
    artifact: collectLatestRun(suite),
  };

  if (exitCode !== 0 || error) {
    appendLog(exceptionLog, `${JSON.stringify({
      timestamp: new Date().toISOString(),
      suite: suite.name,
      iteration,
      exitCode,
      error,
      stderrTail: stderr.slice(-2000),
      logPath,
    })}\n`);
  }

  appendLog(commandLog, `[${new Date().toISOString()}] ${commandResult.status.toUpperCase()} ${suite.name} iteration ${iteration} exit=${exitCode}\n`);
  return commandResult;
}

function summarize(options, runDir, commands) {
  const totals = commands.reduce((acc, item) => {
    acc.total += 1;
    acc.passed += item.status === 'passed' ? 1 : 0;
    acc.failed += item.status === 'failed' ? 1 : 0;
    acc.dryRun += item.status === 'dry-run' ? 1 : 0;
    return acc;
  }, { total: 0, passed: 0, failed: 0, dryRun: 0 });

  const summary = {
    runId: options.runId,
    mode: options.dryRun ? 'dry-run' : 'execute',
    profile: options.profile,
    iterations: options.iterations,
    baseUrl: options.baseUrl,
    startedAt: commands[0]?.startedAt || new Date().toISOString(),
    finishedAt: new Date().toISOString(),
    status: totals.failed > 0 ? 'failed' : 'passed',
    totals,
    commands: commands.map((item) => ({
      suite: item.suite,
      iteration: item.iteration,
      repeat: item.repeat,
      status: item.status,
      exitCode: item.exitCode,
      durationMs: item.durationMs,
      command: item.command,
      logPath: path.relative(runDir, item.logPath).replace(/\\/g, '/'),
      artifact: item.artifact,
    })),
  };

  writeJson(path.join(runDir, 'summary.json'), summary);
  fs.writeFileSync(path.join(runDir, 'summary.md'), toMarkdown(summary), 'utf8');
  return summary;
}

function toMarkdown(summary) {
  const rows = summary.commands.map((item) => [
    item.suite,
    String(item.iteration),
    item.status,
    String(item.exitCode ?? ''),
    `${item.durationMs}ms`,
    item.logPath,
  ]);
  return [
    '# Codex Automation Runner Report',
    '',
    `- Run ID: ${summary.runId}`,
    `- Mode: ${summary.mode}`,
    `- Profile: ${summary.profile}`,
    `- Iterations: ${summary.iterations}`,
    `- Status: ${summary.status}`,
    `- Base URL: ${summary.baseUrl}`,
    `- Finished: ${summary.finishedAt}`,
    '',
    '| Suite | Iteration | Status | Exit | Duration | Log |',
    '| --- | ---: | --- | ---: | ---: | --- |',
    ...rows.map((row) => `| ${row.join(' | ')} |`),
    '',
  ].join('\n');
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  const suites = selectedSuites(options);
  const plan = plannedCommands(options, suites);
  const runDir = path.join(options.outputDir, options.runId);
  const commandLog = path.join(runDir, 'runner.log');
  const exceptionLog = path.join(runDir, 'exceptions.ndjson');

  fs.rmSync(runDir, { recursive: true, force: true });
  ensureDir(path.join(runDir, 'logs'));
  fs.writeFileSync(commandLog, '', 'utf8');
  fs.writeFileSync(exceptionLog, '', 'utf8');
  writeJson(path.join(runDir, 'plan.json'), {
    runId: options.runId,
    profile: options.profile,
    iterations: options.iterations,
    dryRun: options.dryRun,
    suites: suites.map((suite) => suite.name),
    commands: plan.map(({ suite, iteration }) => ({
      suite: suite.name,
      iteration,
      repeat: suite.repeat,
      command: commandText(suite.command),
    })),
  });

  const results = [];
  for (const item of plan) {
    const result = runCommand({
      options,
      suite: item.suite,
      iteration: item.iteration,
      runDir,
      commandLog,
      exceptionLog,
    });
    results.push(result);
    if (result.status === 'failed' && !options.continueOnFailure) break;
  }

  const summary = summarize(options, runDir, results);
  console.log(`Codex automation runner ${summary.status}: ${path.join(runDir, 'summary.md')}`);
  process.exit(summary.status === 'passed' ? 0 : 1);
}

try {
  main();
} catch (error) {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
}
