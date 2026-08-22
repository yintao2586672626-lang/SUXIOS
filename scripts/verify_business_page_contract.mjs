import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const registryPath = path.join(repoRoot, 'rules', 'business-page-contract-registry.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
const automationRoot = path.join(repoRoot, 'tests', 'automation');

const regressionPaths = registry.surfaces.flatMap((surface) =>
  (surface.regression_checks || []).map((check) => check.path),
);
const testPaths = [
  'tests/automation/business_page_contract.test.mjs',
  ...new Set(regressionPaths),
];

for (const relativePath of testPaths) {
  const absolutePath = path.resolve(repoRoot, relativePath);
  const relativeToAutomation = path.relative(automationRoot, absolutePath);
  if (
    relativeToAutomation === ''
    || relativeToAutomation.startsWith(`..${path.sep}`)
    || path.isAbsolute(relativeToAutomation)
    || !relativeToAutomation.endsWith('.test.mjs')
  ) {
    throw new Error(`Business page regression must be a Node test under tests/automation: ${relativePath}`);
  }
  if (!fs.existsSync(absolutePath)) {
    throw new Error(`Business page regression is missing: ${relativePath}`);
  }
}

console.log(`[business-page-contract] running ${testPaths.length} contract and referenced regression files`);
const result = spawnSync(process.execPath, ['--test', ...testPaths], {
  cwd: repoRoot,
  stdio: 'inherit',
});

if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
