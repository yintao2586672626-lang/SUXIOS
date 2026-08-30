import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const routes = readFileSync('route/domain/operations.php', 'utf8');
const controller = readFileSync('app/controller/OperationManagement.php', 'utf8');
const service = readFileSync('app/service/OperationManagementService.php', 'utf8')
  + readFileSync('app/service/operation/OperationExecutionAssigneeConcern.php', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/17-page-ops-track.html', 'utf8');

test('my tasks API binds assignee scope to the authenticated user', () => {
  assert.match(routes, /Route::get\('\/my-tasks', 'OperationManagement\/myTasks'\)/);
  assert.match(controller, /public function myTasks\(\)/);
  assert.match(controller, /\$userId = \(int\)\(\$this->currentUser->id \?\? 0\)/);
  assert.match(controller, /myExecutionTasks\([\s\S]*\$userId/);
  assert.match(service, /unset\(\$filters\['assignee_id'\], \$filters\['user_id'\], \$filters\['_assignee_id'\]\)/);
  assert.match(service, /\$filters\['_assignee_id'\] = \$currentUserId/);
  assert.match(service, /\['assignment'\]\['assignee_id'\][\s\S]*=== \$assigneeId/);
  assert.match(service, /\['execution'\]\['task_id'\][\s\S]*> 0/);
});

test('operations page loads server-scoped my tasks instead of filtering a truncated client list', () => {
  assert.match(appMain, /const operationExecutionViewMode = ref\('all'\)/);
  assert.match(appMain, /operationExecutionViewMode\.value === 'mine'[\s\S]*'\/operation\/my-tasks'/);
  assert.match(appMain, /apiRequest\(`\$\{flowEndpoint\}\$\{flowQuery\}`\)/);
  assert.match(template, /data-testid="operation-my-tasks-tab"/);
  assert.match(template, /@change="setOperationExecutionViewMode\(\$event\.target\.value\)"/);
});

test('my tasks SQL resolves physical tables and correlates positive tenant identity before pagination', () => {
  assert.match(service, /\$intentTable = \(string\)\$query->getTable\(\)/);
  assert.match(service, /Db::name\('operation_execution_tasks'\)->getTable\(\)/);
  assert.match(service, /\$intentAlias = 'assignee_intent'/);
  assert.match(service, /\$taskAlias = 'assignee_task'/);
  assert.match(service, /table\(\[\$intentTable => \$intentAlias\]\)/);
  assert.match(service, /table\(\[\$taskTable => \$taskAlias\]\)/);
  assert.match(service, /whereColumn\(\$taskAlias \. '\.tenant_id', \$intentAlias \. '\.tenant_id'\)/);
  assert.match(service, /where\(\$taskAlias \. '\.tenant_id', '>', 0\)/);
  assert.doesNotMatch(service, /JSON_EXTRACT\(operation_execution_intents\.target_value_json/);
});
