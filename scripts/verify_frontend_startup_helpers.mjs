import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendStartupHelpers } from './lib/frontend_startup_helpers_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectFrontendStartupHelpers(repoRoot);

console.log(JSON.stringify(result, null, 2));
if (result.failures.length) process.exit(1);
