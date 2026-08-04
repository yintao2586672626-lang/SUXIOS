import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/system-static.js', 'utf8');
const start = source.indexOf('    const deferUiTask =');
const end = source.indexOf('    const scheduleDelayedPageTask =', start);

assert.notEqual(start, -1, 'deferUiTask source marker is missing');
assert.notEqual(end, -1, 'scheduleDelayedPageTask source marker is missing');

const compileDeferUiTask = ({ idle = true } = {}) => {
  const timers = [];
  const idleCallbacks = [];
  const context = {
    console: { warn() {} },
    setTimeout(callback, delay) {
      timers.push({ callback, delay });
      return timers.length;
    },
    window: idle
      ? {
          requestIdleCallback(callback, options) {
            idleCallbacks.push({ callback, options });
            return idleCallbacks.length;
          },
        }
      : {},
  };

  vm.runInNewContext(
    `${source.slice(start, end)}\n    globalThis.testDeferUiTask = deferUiTask;`,
    context,
  );

  return { deferUiTask: context.testDeferUiTask, idleCallbacks, timers };
};

test('deferUiTask keeps the requested delay before queueing Chromium idle work', () => {
  const runtime = compileDeferUiTask();
  let calls = 0;

  runtime.deferUiTask(() => { calls += 1; }, 600);

  assert.equal(runtime.idleCallbacks.length, 0, 'idle work must not be queued before the delay');
  assert.equal(runtime.timers.length, 1);
  assert.equal(runtime.timers[0].delay, 600);
  assert.equal(calls, 0);

  runtime.timers[0].callback();
  assert.equal(runtime.idleCallbacks.length, 1);
  assert.ok(runtime.idleCallbacks[0].options.timeout > 0);
  assert.equal(calls, 0);

  runtime.idleCallbacks[0].callback();
  assert.equal(calls, 1);
});

test('deferUiTask keeps the timeout fallback when requestIdleCallback is unavailable', () => {
  const runtime = compileDeferUiTask({ idle: false });
  let calls = 0;

  runtime.deferUiTask(() => { calls += 1; }, 600);

  assert.equal(runtime.timers.length, 1);
  assert.equal(runtime.timers[0].delay, 600);
  runtime.timers[0].callback();
  assert.equal(calls, 1);
});
