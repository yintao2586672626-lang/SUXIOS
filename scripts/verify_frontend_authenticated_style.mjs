import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendAuthenticatedStyle } from './lib/frontend_authenticated_style_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectFrontendAuthenticatedStyle(repoRoot);
console.log(JSON.stringify(result, null, 2));
if (result.failures.length > 0) process.exit(1);
