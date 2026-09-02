import { readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const scriptPath = fileURLToPath(import.meta.url);
const projectRoot = path.resolve(path.dirname(scriptPath), '..');
const partialRuntimeFlag = '--allow-runtime-skip';
const runtimeRequirementEnv = 'SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME';
const fileTimeoutEnv = 'SUXI_NODE_TEST_FILE_TIMEOUT_MS';
const batchSizeEnv = 'SUXI_NODE_TEST_BATCH_SIZE';
const portableSkillStatusTest = 'tests/automation/suxi_skill_evidence_status.test.mjs';
export const portableSkillStatusSkipPattern = 'current authoritative state|status reporter CLI exit codes';
// The dispatcher registration contract intentionally exercises several real
// PowerShell child-process/lock lifecycles and can exceed two minutes on the
// supported Windows workstation. Keep the runner bounded without turning a
// healthy, deterministic file into a false timeout.
const defaultFileTimeoutMs = 300_000;

function enabled(value) {
  return ['1', 'true'].includes(String(value || '').trim().toLowerCase());
}

export function isBusinessChainRuntimeRequired(env = process.env) {
  return enabled(env.CI) || enabled(env[runtimeRequirementEnv]);
}

export function discoverNodeTests(root) {
  const tests = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        visit(entryPath);
      } else if (entry.isFile() && entry.name.endsWith('.test.mjs')) {
        tests.push(entryPath);
      }
    }
  };

  visit(root);
  return tests.sort();
}

export function buildNodeTestArgs(testFiles) {
  const normalizedFiles = testFiles.map(file => String(file).replaceAll('\\', '/'));
  const portableArgs = normalizedFiles.includes(portableSkillStatusTest)
    ? [`--test-skip-pattern=${portableSkillStatusSkipPattern}`]
    : [];
  return ['--test', '--test-concurrency=1', ...portableArgs, ...testFiles];
}

function boundedPositiveInteger(value, fallback, minimum, maximum) {
  const parsed = Number.parseInt(String(value || '').trim(), 10);
  return Number.isSafeInteger(parsed) && parsed >= minimum && parsed <= maximum
    ? parsed
    : fallback;
}

export function resolveNodeTestFileTimeoutMs(env = process.env) {
  return boundedPositiveInteger(env[fileTimeoutEnv], defaultFileTimeoutMs, 1_000, 900_000);
}

export function resolveNodeTestBatchSize(env = process.env) {
  return boundedPositiveInteger(env[batchSizeEnv], 1, 1, 20);
}

export function buildNodeTestBatches(testFiles, batchSize = 1) {
  const boundedSize = boundedPositiveInteger(batchSize, 1, 1, 20);
  const batches = [];
  for (let index = 0; index < testFiles.length; index += boundedSize) {
    batches.push(testFiles.slice(index, index + boundedSize));
  }
  return batches;
}

export function buildPhpBinaryCandidates(env = process.env, platform = process.platform) {
  const configured = String(env.PHP_BINARY || env.SUXI_PHP || '').trim();
  if (configured) return [configured];
  return platform === 'win32'
    ? ['php', 'C:\\xampp\\php\\php.exe']
    : ['php'];
}

export function isRuntimeSkipAllowed(args = process.argv.slice(2)) {
  return args.includes(partialRuntimeFlag);
}

export function buildNodeTestEnv(
  baseEnv = process.env,
  phpBinary = '',
  { allowRuntimeSkip = false } = {},
) {
  const env = { ...baseEnv };
  if (phpBinary) env.PHP_BINARY = phpBinary;
  if (!allowRuntimeSkip) env[runtimeRequirementEnv] = '1';
  return env;
}

function phpBinaryWorks(binary) {
  const result = spawnSync(binary, ['-v'], {
    cwd: projectRoot,
    stdio: 'ignore',
    windowsHide: true,
  });
  return !result.error && result.status === 0;
}

export function resolvePhpBinary(candidates, probe = phpBinaryWorks) {
  return candidates.find((candidate) => probe(candidate)) || '';
}

export function runNodeTestBatches({
  testFiles,
  cwd = projectRoot,
  env = process.env,
  timeoutMs = resolveNodeTestFileTimeoutMs(env),
  batchSize = resolveNodeTestBatchSize(env),
  spawn = spawnSync,
  log = console.log,
  logError = console.error,
}) {
  const batches = buildNodeTestBatches(testFiles, batchSize);
  let lastCompleted = 'none';
  for (let index = 0; index < batches.length; index += 1) {
    const batch = batches[index];
    const label = batch.join(', ');
    log(`[NODE TEST START ${index + 1}/${batches.length}] ${label}`);
    const result = spawn(process.execPath, buildNodeTestArgs(batch), {
      cwd,
      stdio: 'inherit',
      env,
      timeout: timeoutMs,
      killSignal: 'SIGTERM',
      windowsHide: true,
    });
    if (result.error) {
      const timedOut = result.error.code === 'ETIMEDOUT';
      logError(
        timedOut
          ? `[NODE TEST TIMEOUT] ${label} exceeded ${timeoutMs}ms; last_completed=${lastCompleted}`
          : `[NODE TEST ERROR] ${label}: ${result.error.message}; last_completed=${lastCompleted}`,
      );
      return { status: 1, failedBatch: batch, lastCompleted, timedOut };
    }
    if (result.status !== 0) {
      logError(
        `[NODE TEST FAILED] ${label}; exit=${result.status ?? 'null'}; signal=${result.signal || 'none'}; last_completed=${lastCompleted}`,
      );
      return { status: result.status ?? 1, failedBatch: batch, lastCompleted, timedOut: false };
    }
    lastCompleted = label;
    log(`[NODE TEST COMPLETE ${index + 1}/${batches.length}] ${label}`);
  }
  return { status: 0, failedBatch: [], lastCompleted, timedOut: false };
}

function run() {
  const automationRoot = path.join(projectRoot, 'tests', 'automation');
  const testFiles = discoverNodeTests(automationRoot)
    .map(file => path.relative(projectRoot, file).split(path.sep).join('/'));
  if (testFiles.length === 0) {
    console.error('No tests/automation/*.test.mjs files found.');
    process.exit(2);
  }

  const partialRequested = isRuntimeSkipAllowed();
  const allowRuntimeSkip = partialRequested && !enabled(process.env.CI);
  const phpCandidates = buildPhpBinaryCandidates();
  const phpBinary = resolvePhpBinary(phpCandidates);
  if (!phpBinary && !allowRuntimeSkip) {
    console.error('[BLOCKED] Complete Node verification requires an available PHP CLI executable.');
    console.error(`Checked: ${phpCandidates.join(', ')}`);
    console.error("PowerShell: $env:PHP_BINARY='C:\\xampp\\php\\php.exe'; npm run test:node");
    console.error('For an explicitly partial local check only: npm run test:node:partial');
    process.exit(2);
  }

  if (allowRuntimeSkip) {
    console.warn('[PARTIAL VERIFICATION] Business-chain runtime tests may be skipped.');
  } else {
    console.log(`[COMPLETE VERIFICATION] Business-chain runtime tests are required; PHP_BINARY=${phpBinary}`);
  }

  const timeoutMs = resolveNodeTestFileTimeoutMs();
  const batchSize = resolveNodeTestBatchSize();
  console.log(
    `Running ${testFiles.length} Node automation test files in bounded serial batches; batch_size=${batchSize}; timeout_ms=${timeoutMs}.`,
  );
  const result = runNodeTestBatches({
    testFiles,
    cwd: projectRoot,
    env: buildNodeTestEnv(process.env, phpBinary, { allowRuntimeSkip }),
    timeoutMs,
    batchSize,
  });
  process.exit(result.status);
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  run();
}
