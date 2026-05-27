import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const require = createRequire(import.meta.url);
const runnerPath = path.join(root, 'scripts', 'codex_automation_runner.mjs');
const contractOutput = path.join(root, 'runtime', 'codex-runner-contract');
const runId = 'contract-dry-run';
const failures = [];

function assert(condition, message) {
  if (!condition) failures.push(message);
}

fs.rmSync(contractOutput, { recursive: true, force: true });

assert(fs.existsSync(runnerPath), 'scripts/codex_automation_runner.mjs must exist');

if (fs.existsSync(runnerPath)) {
  const result = spawnSync(process.execPath, [
    runnerPath,
    '--dry-run',
    '--profile=extreme',
    '--iterations=10',
    `--run-id=${runId}`,
    `--output-dir=${contractOutput}`,
  ], {
    cwd: root,
    encoding: 'utf8',
    shell: false,
    timeout: 30000,
  });

  assert(result.status === 0, `runner dry-run should exit 0, got ${result.status}\n${result.stderr || result.stdout}`);

  const summaryPath = path.join(contractOutput, runId, 'summary.json');
  const markdownPath = path.join(contractOutput, runId, 'summary.md');
  const logPath = path.join(contractOutput, runId, 'runner.log');
  assert(fs.existsSync(summaryPath), 'runner dry-run must generate summary.json');
  assert(fs.existsSync(markdownPath), 'runner dry-run must generate summary.md');
  assert(fs.existsSync(logPath), 'runner dry-run must generate runner.log');

  if (fs.existsSync(summaryPath)) {
    const summary = JSON.parse(fs.readFileSync(summaryPath, 'utf8'));
    assert(summary.profile === 'extreme', 'summary.profile must be extreme');
    assert(summary.iterations === 10, 'summary.iterations must be 10');
    assert(summary.mode === 'dry-run', 'summary.mode must be dry-run');
    assert(summary.commands.some((item) => item.suite === 'module-smoke'), 'summary must include module-smoke');
    assert(summary.commands.some((item) => item.suite === 'full-click'), 'summary must include full-click for extreme profile');
    assert(summary.commands.every((item) => item.status === 'dry-run'), 'dry-run commands must be marked dry-run');
  }
}

const packageJson = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
assert(packageJson.scripts?.['codex:runner'], 'package.json must expose codex:runner');
assert(packageJson.scripts?.['codex:runner:quick'], 'package.json must expose codex:runner:quick');
assert(packageJson.scripts?.['verify:codex-runner-contract'], 'package.json must expose verify:codex-runner-contract');

const runnerSource = fs.readFileSync(runnerPath, 'utf8');
const fullClickBlock = runnerSource.match(/name:\s*'full-click'[\s\S]*?timeoutMs:\s*(\d+)/);
assert(fullClickBlock, 'codex runner must define full-click suite timeout');
if (fullClickBlock) {
  const timeoutMs = Number(fullClickBlock[1]);
  assert(timeoutMs >= 14400000, 'full-click timeout must allow the documented 50-loop run to finish');
}

const helpers = require(path.join(root, 'tests', 'automation', 'e2e-helpers.js'));
const previousProfile = process.env.E2E_INPUT_PROFILE;
const previousExtreme = process.env.E2E_EXTREME_INPUTS;
delete process.env.E2E_INPUT_PROFILE;
delete process.env.E2E_EXTREME_INPUTS;
const normalNumber = helpers.semanticInputValue({ type: 'number', name: 'adr' });
const normalText = helpers.semanticInputValue({ type: 'text', name: 'project_name' });
process.env.E2E_INPUT_PROFILE = 'extreme';
process.env.E2E_EXTREME_INPUTS = '1';
const extremeNumber = helpers.semanticInputValue({ type: 'number', name: 'adr' });
const extremeText = helpers.semanticInputValue({ type: 'text', name: 'project_name' });

if (previousProfile === undefined) delete process.env.E2E_INPUT_PROFILE;
else process.env.E2E_INPUT_PROFILE = previousProfile;
if (previousExtreme === undefined) delete process.env.E2E_EXTREME_INPUTS;
else process.env.E2E_EXTREME_INPUTS = previousExtreme;

assert(normalNumber !== extremeNumber, 'extreme profile must change numeric semantic input');
assert(Number(extremeNumber) > Number(normalNumber), 'extreme numeric input should be above normal input');
assert(extremeText.length > normalText.length, 'extreme text input should be longer than normal input');
assert(/codex_extreme/.test(extremeText), 'extreme text input should be traceable by codex_extreme prefix');

if (failures.length) {
  console.error('Codex runner contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

console.log('Codex runner contract passed.');
