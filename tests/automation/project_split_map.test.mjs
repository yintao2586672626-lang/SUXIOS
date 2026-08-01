import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('scripts/project_split_map.mjs', 'utf8');

test('project split map discovers current actionable hotspots instead of hard-coded legacy targets', () => {
  const result = spawnSync(process.execPath, [
    'scripts/project_split_map.mjs',
    '--json',
    '--min-lines=500',
    '--top=12',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  const report = JSON.parse(result.stdout);
  assert.equal(report.schema_version, 2);
  assert.equal(report.selection.mode, 'dynamic_actionable_hotspots');
  assert.equal(report.selection.generated_and_vendored_assets_excluded, true);
  assert.ok(report.targets.length > 0);
  assert.ok(report.targets.every((target) => target.lines >= 500));

  const lineCounts = report.targets.map((target) => target.lines);
  assert.deepEqual(lineCounts, [...lineCounts].sort((left, right) => right - left));
  assert.ok(report.targets.some((target) => target.type === 'php_controller' || target.type === 'php_service'));
  assert.ok(report.targets.some((target) => target.type === 'frontend_script'));
  assert.ok(report.targets.every((target) => !/\.min\.(?:js|css)$/i.test(target.path)));
  assert.ok(report.targets.every((target) => target.path !== 'resources/frontend/app-template.html'));

  assert.doesNotMatch(source, /analyzePublicIndex\('public\/index\.html'\)/);
  assert.doesNotMatch(source, /analyzePhpController\('app\/controller\/OnlineData\.php'\)/);
});

test('project split map rejects invalid numeric tuning by falling back to bounded defaults', () => {
  const result = spawnSync(process.execPath, [
    'scripts/project_split_map.mjs',
    '--json',
    '--min-lines=not-a-number',
    '--top=not-a-number',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });

  assert.equal(result.status, 0, result.stderr || result.stdout);
  const report = JSON.parse(result.stdout);
  assert.equal(report.selection.min_lines, 2_500);
  assert.equal(report.selection.target_limit, 12);
});
