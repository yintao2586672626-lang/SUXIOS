#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectSourceHotspotBudget } from './lib/source_hotspot_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = inspectSourceHotspotBudget(repoRoot);

console.log(JSON.stringify(result, null, 2));
if (result.failures.length > 0) {
  process.exit(1);
}
