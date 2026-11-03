import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const resultSpan = source.match(/最近结果：<\/span>\s*<span :class="([^"]+)">([\s\S]*?)<\/span>/);
assert.ok(resultSpan, 'the actual recent-result span exists');
const messageExpression = resultSpan[2].match(/{{\s*(ctx\.autoFetchResultMessage\([^}]+\))\s*}}/)?.[1];
assert.ok(messageExpression, 'the actual result message expression exists');

function renderResult(runState, lastResult) {
  const ctx = {
    autoFetchRunState: { active: false, type: '', message: '', ...runState },
    autoFetchStatus: { last_result: lastResult },
    autoFetchResultMessage: (message) => String(message || ''),
  };
  return vm.runInNewContext(`({ className: (${resultSpan[1]}), message: (${messageExpression}) })`, { ctx });
}

for (const [type, className] of [['error', 'text-red-700'], ['status_unavailable', 'text-amber-700']]) {
  test(`current ${type} message uses its own status even after an older success`, () => {
    const output = renderResult({ type, message: '当前任务状态' }, { success: true, message: '上次成功' });
    assert.equal(output.message, '当前任务状态');
    assert.equal(output.className, className);
  });
}

test('a current success remains green after an older failure', () => {
  const output = renderResult({ type: 'success', message: '本次已完成' }, { success: false, message: '上次失败' });
  assert.equal(output.message, '本次已完成');
  assert.equal(output.className, 'text-green-700');
});

for (const [success, className] of [[true, 'text-green-700'], [false, 'text-red-700']]) {
  test(`without a current message, the displayed previous result success=${success} controls its color`, () => {
    const output = renderResult({ type: success ? 'error' : 'success', message: '' }, { success, message: '已保存历史结果' });
    assert.equal(output.message, '已保存历史结果');
    assert.equal(output.className, className);
  });
}

test('a result without a confirmed success or failure remains visibly unknown', () => {
  const output = renderResult({}, { status: 'unknown', message: '后台结果尚未确认' });
  assert.equal(output.message, '后台结果尚未确认');
  assert.equal(output.className, 'text-amber-700');
});

test('an active request retains its blue running state', () => {
  const output = renderResult({ active: true, type: 'running', message: '正在读取' }, { success: false, message: '上次失败' });
  assert.equal(output.message, '正在读取');
  assert.equal(output.className, 'text-blue-700');
});
