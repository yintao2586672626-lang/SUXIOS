import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const hook = readFileSync('hooks/pre-commit.ps1', 'utf8');

test('public index changes must run the startup entry guard before visual checks', () => {
  const startupGuard = "if ($changed -contains 'public/index.html') {\n    npm.cmd run verify:public-entry\n}";
  const visualGuard = "if ($changed -contains 'public/index.html' -or $changed -contains 'public/style.css') {";

  assert.match(hook, new RegExp(startupGuard.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\\n/g, '\\r?\\n')));
  assert.ok(hook.indexOf('npm.cmd run verify:public-entry') < hook.indexOf(visualGuard));
});
