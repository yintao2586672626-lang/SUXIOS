import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  assessStartupGzipBudget,
  collectFrontendEntryMetrics,
  DEFAULT_FRONTEND_BUDGET,
  evaluateFrontendBudget,
} from './lib/frontend_performance_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const metrics = collectFrontendEntryMetrics(repoRoot);
const startupBudget = assessStartupGzipBudget(metrics);
const failures = evaluateFrontendBudget(metrics);
console.log(JSON.stringify({ metrics, budget: DEFAULT_FRONTEND_BUDGET, startup_budget: startupBudget, failures }, null, 2));
if (failures.length) process.exit(1);
