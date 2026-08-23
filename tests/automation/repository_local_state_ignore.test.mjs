import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const gitignore = readFileSync('.gitignore', 'utf8').split(/\r?\n/);

test('repository ignores local runtime, browser state, and automation worktrees', () => {
  for (const required of [
    '/storage/',
    '/.automation-worktrees/',
    '/browser_profiles/',
    '/profiles/',
    '/Profile/',
    '/user-data/',
    '/cookies/',
    '/localStorage/',
  ]) {
    assert.ok(gitignore.includes(required), `missing local-state ignore boundary: ${required}`);
  }
});
