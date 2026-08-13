import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const page = read('resources/frontend/templates/fragments/15aac-page-automation-monitor.html');
const appMain = read('public/app-main.js');
const service = read('app/service/WindowsScheduledLoopCatalogService.php');
const component = read('public/components/operations/automation-collection-contract.js');

test('automation monitor visibly lists every required periodic-task field', () => {
  assert.match(page, /:is="automationCollectionContractBody"/);
  assert.match(component, /'data-testid': 'automation-periodic-task-list'/);
  assert.match(component, /'data-testid': 'automation-periodic-task-table'/);
  for (const label of ['任务名称', '用途', '来源', '频率', '状态', '上次运行', '下次运行', '最近结果']) {
    assert.match(component, new RegExp(label));
  }
  assert.match(component, /automationMonitor\?\.scheduled_loops/);
  assert.match(component, /已启用不等于已执行/);
  assert.match(component, /退出码 0 也不代表采集、推送或经营结果成功/);
  assert.match(component, /已暂停，仅为理论时间/);
});

test('periodic task list reuses the existing monitor load and polling without a second timer', () => {
  assert.match(appMain, /apiRequest\(`\/manual-notifications\/monitor\?/);
  assert.match(appMain, /startAutomationMonitorPolling/);
  assert.equal((component.match(/setInterval|setTimeout/g) || []).length, 0);
  assert.doesNotMatch(component, /演示任务|默认成功/);
  assert.match(service, /Windows 周期任务只读回读/);
  assert.match(component, /不创建 CMD 窗口/);
});
