import fs from 'node:fs';
import path from 'node:path';
import { evaluateFrontendRuntimeBudget } from './lib/frontend_runtime_performance_budget.mjs';

const options = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...rest] = argument.replace(/^--/, '').split('=');
  return [key, rest.join('=') || '1'];
}));
const inputPath = path.resolve(
  options.input || path.join('output', 'performance', 'isolated-authenticated-baseline.json'),
);

if (!fs.existsSync(inputPath)) {
  console.error(`Runtime performance report not found: ${inputPath}`);
  process.exit(1);
}

let report;
try {
  report = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
} catch (error) {
  console.error(`Runtime performance report is invalid JSON: ${error.message}`);
  process.exit(1);
}

const assessment = evaluateFrontendRuntimeBudget(report);
console.log(JSON.stringify({ input: inputPath, ...assessment }, null, 2));
if (assessment.failures.length) process.exit(1);
