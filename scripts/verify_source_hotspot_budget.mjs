#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectSourceHotspotBudget } from './lib/source_hotspot_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = inspectSourceHotspotBudget(repoRoot);
const strictTargets = process.argv.includes('--strict-targets');

console.log(JSON.stringify(result, null, 2));
if (result.failures.length > 0 || (strictTargets && result.debts.length > 0)) {
  process.exit(1);
}
