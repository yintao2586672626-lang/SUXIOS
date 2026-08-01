import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendTemplateBuild } from './lib/frontend_template_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectFrontendTemplateBuild(repoRoot);
console.log(JSON.stringify(result, null, 2));
if (result.failures.length) process.exit(1);
