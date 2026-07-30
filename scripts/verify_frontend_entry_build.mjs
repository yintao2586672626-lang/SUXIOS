import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectFrontendEntryBuild } from './lib/frontend_entry_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const result = await inspectFrontendEntryBuild({
  source: fs.readFileSync(path.join(repoRoot, 'public/app-main.js'), 'utf8'),
  artifact: fs.readFileSync(path.join(repoRoot, 'public/app-main.min.js'), 'utf8'),
  html: fs.readFileSync(path.join(repoRoot, 'public/index.html'), 'utf8'),
});

console.log(JSON.stringify(result, null, 2));
if (result.failures.length) process.exit(1);
