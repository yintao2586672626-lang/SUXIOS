import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(path, 'utf8');

test('super-admin security monitoring has a discoverable route, menu and evidence panels', () => {
  const route = read('route/app.php');
  const controller = read('app/controller/OperationLogController.php');
  const appMain = read('public/app-main.js');
  const systemStatic = read('public/system-static.js');
  const template = read('resources/frontend/templates/fragments/30-page-operation-logs.html');

  assert.match(route, /operation-logs[\s\S]*security-overview[\s\S]*securityOverview/);
  assert.match(controller, /function securityOverview[\s\S]*requireSuperAdminAccess\(\)[\s\S]*SecurityMonitoringService/);
  assert.match(systemStatic, /name: '安全监测', path: 'operation-logs'.*requireSuper: true/);
  assert.match(appMain, /\/operation-logs\/security-overview/);
  assert.match(template, /data-testid="security-monitor-overview"/);
  assert.match(template, /data-testid="account-login-activity"/);
  assert.match(template, /账号登录活跃度/);
  assert.match(template, /本期活跃/);
  assert.match(template, /本期未活跃/);
  assert.match(template, /其中从未成功登录/);
  assert.match(template, /需要核查的账号/);
  assert.match(template, /登录频率排行/);
  assert.match(template, /最新风险事件/);
  assert.match(template, /完整操作审计/);
  assert.match(template, /不会自动封号、改密或处罚/);
});

test('security monitoring UI states that IP and malice are evidence-quality decisions', () => {
  const template = read('resources/frontend/templates/fragments/30-page-operation-logs.html');
  const service = read('app/service/SecurityMonitoringService.php');

  assert.match(template, /风险信号不等同于恶意结论/);
  assert.match(template, /代理未透传真实IP/);
  assert.match(template, /本名单不返回 IP 地址/);
  assert.match(service, /无法据此区分真实登录地址/);
  assert.match(service, /不自动认定主观恶意/);
  assert.match(service, /所选时间范围内至少一次成功登录/);
  assert.match(service, /仅超级管理员可见；本名单不返回IP地址/);
  const activityBlock = service.match(/private function buildAccountActivity[\s\S]*?(?=\n\s*\/\*\*)/)?.[0] || '';
  assert.doesNotMatch(activityBlock, /['"]ip['"]\s*=>/);
  assert.doesNotMatch(service, /->password|password_hash|verifyPassword/);
});

test('account activity uses uncapped grouped login-only evidence and truthful incomplete states', () => {
  const service = read('app/service/SecurityMonitoringService.php');
  const template = read('resources/frontend/templates/fragments/30-page-operation-logs.html');
  const start = service.indexOf('private function loadAccountLoginActivityEvidence');
  const end = service.indexOf('\n    /**', start + 20);
  assert.ok(start >= 0 && end > start);
  const queryBlock = service.slice(start, end);

  assert.ok((queryBlock.match(/->where\('action', 'login'\)/g) || []).length >= 2);
  assert.match(queryBlock, /->whereBetween\('created_at', \[\$startAt, \$endAt\]\)/);
  assert.ok((queryBlock.match(/->group\('user_id,username'\)/g) || []).length >= 2);
  assert.doesNotMatch(queryBlock, /->limit\(/);
  assert.match(service, /limit\(self::MAX_LOGIN_ROWS \+ 1\)/);
  assert.match(service, /login_logs_truncated/);
  assert.match(service, /matchesSuperAdminIdentity[\s\S]*Role::normalizePermissions[\s\S]*role_level/);
  assert.match(template, /account_activity\?\.complete === false/);
  assert.match(template, /账号登录聚合不可用，暂不生成活跃或未活跃结论/);
});

test('frontend security and hotel-scoped requests reject stale state instead of showing synthetic zeroes', () => {
  const appMain = read('public/app-main.js');
  const securityTemplate = read('resources/frontend/templates/fragments/30-page-operation-logs.html');
  const dialogTemplate = read('resources/frontend/templates/fragments/46-global-toast.html');

  assert.match(appMain, /const SUPER_ADMIN_ONLY_PAGES = new Set\([\s\S]*'operation-logs'[\s\S]*guardSuperAdminPageAccess/);
  assert.match(appMain, /watch\(currentPage, \(newPage\) => \{\s*if \(requestSuxiFullRenderForPage\(newPage\)\) return;\s*if \(!guardSuperAdminPageAccess\(newPage\)\) return;/);
  assert.match(securityTemplate, /currentPage === 'operation-logs' &amp;&amp; user\?\.is_super_admin/);
  assert.match(appMain, /const requestSeq = \+\+securityOverviewRequestSeq[\s\S]*securityOverview\.value = createEmptySecurityOverview\(\)/);
  assert.match(appMain, /const requestSeq = \+\+homeTemporalRequestSeq[\s\S]*hotelId === String\(filterReportHotel\.value/);
  assert.match(appMain, /const requestSeq = \+\+operationLogsRequestSeq[\s\S]*operationLogs\.value = \[\][\s\S]*operationLogsError\.value/);
  assert.match(appMain, /homeTemporalError\.value = res\.message \|\| '数据进度与预测加载失败/);
  assert.match(securityTemplate, /完整操作审计[\s\S]*v-if="operationLogsError"[\s\S]*重新加载/);
  assert.match(securityTemplate, /v-else-if="operationLogsLoading"/);
  assert.match(appMain, /logModules\.value = \[\][\s\S]*operationLogsError\.value = e\?\.message/);
  assert.match(appMain, /const requestSeq = \+\+compassRequestSeq[\s\S]*compassHotelId === String\(filterReportHotel\.value/);
  assert.doesNotMatch(securityTemplate, /securityOverview[^\n]*(?:\|\| 0|\?\? 0)/);
  assert.match(dialogTemplate, /data-testid="workflow-form-dialog"/);
  assert.doesNotMatch(appMain, /(?:window\.)?prompt\(/);
});
