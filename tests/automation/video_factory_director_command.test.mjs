import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const candidates = [
  process.env.SUXI_PHP,
  process.env.PHP_BINARY,
  'C:\\xampp\\php\\php.exe',
  'D:\\xampp\\php\\php.exe',
  'C:\\php\\php.exe',
  'php',
].filter(Boolean);

function canRun(candidate) {
  if (candidate.includes('\\') || candidate.includes('/')) {
    return existsSync(candidate);
  }
  const result = spawnSync(candidate, ['-v'], { stdio: 'ignore', windowsHide: true });
  return !result.error && result.status === 0;
}

const php = candidates.find(canRun);

function runDirector(payload) {
  const result = spawnSync(php, ['think', 'video-factory:director'], {
    cwd: process.cwd(),
    input: JSON.stringify(payload),
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.error, undefined);
  const output = result.stdout.trim();
  assert.doesNotThrow(() => JSON.parse(output), `Expected one JSON object, got:\n${output}`);
  return { result, payload: JSON.parse(output) };
}

test('video factory director is registered as an internal console command', (t) => {
  if (!php) {
    t.skip('PHP is not available');
    return;
  }

  const consoleConfig = readFileSync('config/console.php', 'utf8');
  assert.match(consoleConfig, /'video-factory:director'\s*=>\s*'app\\command\\VideoFactoryDirector'/);

  const result = spawnSync(php, ['think', 'list', '--raw'], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, 0, result.stderr || result.stdout);
  assert.match(result.stdout, /^video-factory:director\s/m);
});

test('video factory director rejects malformed typed inputs before database or network work', (t) => {
  if (!php) {
    t.skip('PHP is not available');
    return;
  }

  const cases = [
    {
      input: { prompt: [], schema: { type: 'object' } },
      error: 'Video director prompt must be a string.',
    },
    {
      input: { prompt: 'valid', schema: { type: 'object' }, modelKey: [] },
      error: 'Video director modelKey must be a string.',
    },
    {
      input: { prompt: 'valid', schema: ['not-an-object'] },
      error: 'Video director JSON schema must be a non-empty object.',
    },
    {
      input: { prompt: 'valid', schema: { type: 'object' }, modelKey: 'x'.repeat(97) },
      error: 'Video director modelKey is empty or too large.',
    },
  ];

  for (const item of cases) {
    const execution = runDirector(item.input);
    assert.equal(execution.result.status, 1);
    assert.deepEqual(execution.payload, { ok: false, error: item.error });
  }
});
