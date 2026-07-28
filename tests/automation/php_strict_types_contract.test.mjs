import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const PHP_ROOTS = ['app', 'config', 'route', 'scripts', 'tests'];

function phpFiles(directory) {
  return readdirSync(directory, { withFileTypes: true })
    .flatMap((entry) => {
      const target = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        return phpFiles(target);
      }
      return entry.isFile() && entry.name.endsWith('.php') ? [target] : [];
    });
}

test('all project PHP sources enable strict scalar type checking', () => {
  const missing = PHP_ROOTS
    .flatMap(phpFiles)
    .filter((file) => !/^(?:\uFEFF)?(?:#![^\r\n]*\r?\n)?<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/u.test(
      readFileSync(file, 'utf8').slice(0, 256),
    ));

  assert.deepEqual(missing, []);
});
