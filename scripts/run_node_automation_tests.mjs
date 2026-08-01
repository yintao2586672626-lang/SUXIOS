import { readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const scriptPath = fileURLToPath(import.meta.url);
const projectRoot = path.resolve(path.dirname(scriptPath), '..');
const partialRuntimeFlag = '--allow-runtime-skip';
const runtimeRequirementEnv = 'SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME';

function enabled(value) {
  return ['1', 'true'].includes(String(value || '').trim().toLowerCase());
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
  return ['--test', '--test-concurrency=1', ...testFiles];
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

  console.log(`Running ${testFiles.length} Node automation test files serially.`);
  const result = spawnSync(process.execPath, buildNodeTestArgs(testFiles), {
    cwd: projectRoot,
    stdio: 'inherit',
    env: buildNodeTestEnv(process.env, phpBinary, { allowRuntimeSkip }),
  });
  if (result.error) {
    console.error(result.error.message);
    process.exit(1);
  }
  process.exit(result.status ?? 1);
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  run();
}
