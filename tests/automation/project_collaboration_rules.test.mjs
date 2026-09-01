import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const agentRules = fs.readFileSync('AGENTS.md', 'utf8');
const charter = fs.readFileSync('docs/product_collaboration_charter.md', 'utf8');

test('full Gauntlet is evidence-triggered while ordinary work keeps a compact delivery loop', () => {
  assert.match(agentRules, /默认不运行完整试炼循环/);
  assert.match(
    agentRules,
    /只有用户明确要求比较、标杆或全面审查，或者当前产物存在真正可比的标杆且预期证据收益高于流程成本时（包括比较会改变决定的高影响开放式取舍）/,
  );
  assert.match(agentRules, /其他任务按[\s\S]*codex-execution-status-contract\.md[\s\S]*直接完成一个闭环/);
  assert.doesNotMatch(agentRules, /对有可检查产物的非简单目标自动使用/);
  assert.match(charter, /普通功能不得先扩成全仓审计、完整门禁或发布工程/);
});

test('dirty worktrees are resolved by target overlap instead of a global clean requirement', () => {
  assert.match(agentRules, /public\/index\.html[\s\S]*关联生成物的 Git 状态与差异/);
  assert.match(agentRules, /不要求全局工作区干净/);
  assert.doesNotMatch(agentRules, /public\/index\.html[^\n]*确认工作区干净/);
  assert.match(charter, /工作树不要求全局干净/);
});
