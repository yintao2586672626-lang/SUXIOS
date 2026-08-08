import fs from 'node:fs';
import path from 'node:path';
import { evaluateFrontendRuntimeBudget } from './lib/frontend_runtime_performance_budget.mjs';
import {
  captureFrontendPerformanceIdentity,
  evaluateFrontendPerformanceEvidence,
} from './lib/frontend_performance_evidence_identity.mjs';

const options = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...rest] = argument.replace(/^--/, '').split('=');
  return [key, rest.join('=') || '1'];
}));
const inputPath = path.resolve(
  options.input || path.join('output', 'performance', 'isolated-authenticated-baseline.json'),
);
const maxAgeMinutes = Number(options['max-age-minutes'] || 360);

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
const evidence = evaluateFrontendPerformanceEvidence(report, {
  currentIdentity: captureFrontendPerformanceIdentity(),
  maxAgeMinutes,
});
console.log(JSON.stringify({ input: inputPath, evidence, ...assessment }, null, 2));
if (assessment.failures.length || evidence.failures.length) process.exit(1);
