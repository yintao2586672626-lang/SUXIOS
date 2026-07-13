import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectTailwindRuntimeBuild } from './lib/frontend_tailwind_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const report = await inspectTailwindRuntimeBuild(repoRoot);

console.log(JSON.stringify(report, null, 2));
if (report.failures.length) process.exit(1);
