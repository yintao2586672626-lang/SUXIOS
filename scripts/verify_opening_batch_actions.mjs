import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const html = readFileSync(resolve(root, 'public/index.html'), 'utf8');

const requiredSnippets = [
  'selectedOpeningTaskIds',
  'selectedOpeningTasks',
  'toggleSelectAllFilteredOpeningTasks',
  'isAllFilteredOpeningTasksSelected',
  'batchUpdateOpeningTasks',
  'clearSelectedOpeningTasks',
  'aria-label="选择当前筛选检查项"',
  '批量处理',
  "batchUpdateOpeningTasks({ status: 'done'",
  "batchUpdateOpeningTasks({ status: 'doing'",
  "batchUpdateOpeningTasks({ status: 'blocked'",
  'batchUpdateOpeningTasks({ progress_percent: value',
];

const missing = requiredSnippets.filter((snippet) => !html.includes(snippet));

if (missing.length) {
  console.error('Opening batch action contract missing:');
  for (const snippet of missing) {
    console.error(`- ${snippet}`);
  }
  process.exit(1);
}

console.log('Opening batch action contract OK');
