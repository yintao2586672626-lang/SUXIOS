import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectLoginCriticalCss } from './lib/frontend_login_critical_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectLoginCriticalCss(repoRoot);
console.log(JSON.stringify(result, null, 2));
if (result.failures.length) process.exit(1);
