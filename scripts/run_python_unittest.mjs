import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import process from 'node:process';

const testArgs = process.argv.slice(2);

if (testArgs.length === 0) {
  console.error('Usage: node scripts/run_python_unittest.mjs <test.py> [...tests]');
  process.exit(2);
}

const candidates = [
  process.env.SUXI_PYTHON
    ? { command: process.env.SUXI_PYTHON, prefix: [] }
    : null,
  process.env.PYTHON_BINARY
    ? { command: process.env.PYTHON_BINARY, prefix: [] }
    : null,
  ...(process.platform === 'win32'
    ? [
        { command: 'py', prefix: ['-3'] },
        { command: 'python', prefix: [] },
        { command: 'python3', prefix: [] },
      ]
    : [
        { command: 'python3', prefix: [] },
        { command: 'python', prefix: [] },
      ]),
].filter(Boolean);

const seen = new Set();
const uniqueCandidates = candidates.filter(({ command, prefix }) => {
  const key = `${command}\0${prefix.join('\0')}`;
  if (seen.has(key)) {
    return false;
  }
  seen.add(key);
  return true;
});

const canRun = ({ command, prefix }) => {
  if ((command.includes('\\') || command.includes('/')) && !existsSync(command)) {
    return false;
  }
  const result = spawnSync(command, [...prefix, '--version'], { stdio: 'ignore' });
  return !result.error && result.status === 0;
};

const python = uniqueCandidates.find(canRun);

if (!python) {
  console.error('Python 3 executable not found. Set SUXI_PYTHON or PYTHON_BINARY.');
  process.exit(127);
}

const result = spawnSync(
  python.command,
  [...python.prefix, '-m', 'unittest', ...testArgs],
  { stdio: 'inherit' },
);

if (result.error) {
  console.error(result.error.message);
  process.exit(1);
}

process.exit(result.status ?? 1);
