import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';

const main = readFileSync('public/app-main.js', 'utf8');
const start = main.indexOf('            const operationAiDailyApprovalDateWarning =');
const end = main.indexOf('            const operationExecutionAssignedToCurrentUser =', start);
assert.ok(start >= 0 && end > start);
const warning = vm.runInNewContext(
    `${main.slice(start, end)}\noperationAiDailyApprovalDateWarning`,
    { shanghaiToday: () => '2026-09-28' }
);
const intent = (source, startDate, endDate, status = 'pending_approval') => ({
    approval: { status },
    recommendation: { source_module: source, date_start: startDate, date_end: endDate },
});

test('AI daily approval shows expired and inverted dates while preserving valid and other sources', () => {
    assert.match(warning(intent('ai_daily_report', '2026-09-27', '2026-09-27')), /已过期/);
    assert.match(warning(intent('ai_daily_report', '2026-09-29', '2026-09-28')), /倒置/);
    assert.match(warning(intent('ai_daily_report', '', '')), /缺失或无效/);
    assert.equal(warning(intent('ai_daily_report', '2026-09-27', '2026-09-28')), '');
    assert.equal(warning(intent('ai_daily_report', '2026-09-29', '2026-09-29')), '');
    assert.match(warning(intent('revenue_research', '2026-09-27', '2026-09-27')), /已过期.*重新运行收益研究/);
    assert.match(warning(intent('revenue_research', '2026-09-29', '2026-09-28')), /倒置/);
    assert.match(warning(intent('revenue_research', '', '')), /缺失或无效/);
    assert.equal(warning(intent('revenue_research', '2026-09-28', '2026-09-28')), '');
    assert.equal(warning(intent('price_suggestion', '2026-09-27', '2026-09-27')), '');
    assert.equal(warning(intent('ai_daily_report', '2026-09-27', '2026-09-27', 'approved')), '');
});

test('operation table binds the warning to approve only and keeps rejection available', () => {
    const template = readFileSync('resources/frontend/templates/fragments/17-page-ops-track.html', 'utf8');
    const approve = template.match(/<button v-if="operationCanApproveExecution\(item\)" data-testid="operation-approve"[^>]+>/)?.[0] || '';
    const reject = template.match(/<button v-if="operationCanApproveExecution\(item\)" data-testid="operation-reject"[^>]+>/)?.[0] || '';
    assert.match(approve, /:disabled="operationLoading\.actions \|\| !!operationAiDailyApprovalDateWarning\(item\)"/);
    assert.doesNotMatch(reject, /operationAiDailyApprovalDateWarning/);
    assert.match(template, /\{\{ operationAiDailyApprovalDateWarning\(item\) \}\}/);
    assert.ok(compile(template, { mode: 'function' }).code.length > 0);
});
