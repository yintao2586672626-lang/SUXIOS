import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const revenueAiController = readFileSync('app/controller/RevenueAi.php', 'utf8');
const agentController = readFileSync('app/controller/Agent.php', 'utf8');
const workflowDialog = readFileSync('resources/frontend/templates/fragments/46-global-toast.html', 'utf8');

test('price execution intent asks for a non-past execution date', () => {
  const start = appMain.indexOf('const collectPriceExecutionIntentFields');
  const end = appMain.indexOf('const createAiDailyExecutionIntent', start);
  assert.ok(start >= 0 && end > start, 'price execution intent form block must exist');
  const formBlock = appMain.slice(start, end);

  assert.match(formBlock, /name:\s*'execution_date'/);
  assert.match(formBlock, /label:\s*'计划执行日期'/);
  assert.match(formBlock, /type:\s*'date'/);
  assert.match(formBlock, /min:\s*today/);
  assert.match(formBlock, /execution_date:\s*String\(formValues\.execution_date/);
  assert.match(formBlock, /fields\.execution_date\s*<\s*today/);
  assert.match(workflowDialog, /:min="field\.min \|\| undefined"/);
});

test('both price execution intent endpoints forward execution_date', () => {
  for (const source of [revenueAiController, agentController]) {
    assert.match(source, /'execution_date'\s*=>/);
  }
});
