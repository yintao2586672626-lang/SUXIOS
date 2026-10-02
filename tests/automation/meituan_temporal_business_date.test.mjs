import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const main = readFileSync('public/app-main.js', 'utf8');
const system = readFileSync('public/system-static.js', 'utf8');
const extract = (source, start, end) => {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, `missing source slice: ${start}`);
  return source.slice(from, to);
};
const formatDateSource = extract(system, 'const formatDate =', 'const formatConfigDate =');
const asOfDateSource = extract(main, 'const meituanTemporalAsOfDate =', 'let meituanTemporalLoadSeq =');

const dateFromDeviceZone = (timeZone, instant) => {
  const script = `
    const vm = require('node:vm');
    const fixedInstant = ${JSON.stringify(instant)};
    const fixedDate = class extends Date {
      constructor(...args) { super(...(args.length ? args : [fixedInstant])); }
    };
    const result = vm.runInNewContext(
      ${JSON.stringify(`${formatDateSource}\nconst appTimeZone = 'Asia/Shanghai';\n${asOfDateSource}\nmeituanTemporalAsOfDate()`)} ,
      { Date: fixedDate, Intl }
    );
    process.stdout.write(result);
  `;
  const run = spawnSync(process.execPath, ['-e', script], {
    encoding: 'utf8',
    env: { ...process.env, TZ: timeZone },
  });
  assert.equal(run.status, 0, run.stderr);
  return run.stdout;
};

test('Meituan summary and refresh use the Shanghai business date across device time zones', () => {
  assert.match(main, /as_of_date: meituanTemporalAsOfDate\(\)/);
  assert.equal(dateFromDeviceZone('America/Los_Angeles', '2026-09-25T17:00:00Z'), '2026-09-26');
  assert.equal(dateFromDeviceZone('Pacific/Auckland', '2026-09-26T15:30:00Z'), '2026-09-26');
});
