import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';

for (const artifact of ['hotel-learning-workbench.js', 'hotel-learning-workbench.min.js']) {
  test(`investment numeric failure has an actionable explanation in ${artifact}`, () => {
    const window = {};
    vm.runInNewContext(fs.readFileSync(new URL(`../../public/components/system/${artifact}`, import.meta.url), 'utf8'), { window, Vue: {}, Intl, Date, URLSearchParams });
    const display = window.SUXI_SYSTEM_COMPONENTS.HotelLearningWorkbench.methods.display;
    assert.equal(display('forward_numeric_calculation_nonfinite'), '本次参数超出可计算范围，请调整求解边界后重试');
    assert.equal(display('required_inputs_missing'), '必要输入不完整');
    assert.equal(display(null), '未取得');
  });
}

test('the learning component loads and retries the same current artifact version', () => {
  const artifact = fs.readFileSync(new URL('../../public/components/system/hotel-learning-workbench.min.js', import.meta.url));
  const digest = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const entry = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
  const references = [...entry.matchAll(/components\/system\/hotel-learning-workbench\.min\.js\?v=hotel-learning-h([a-f0-9]{10})/g)].map(match => match[1]);
  assert.deepEqual(references, [digest, digest]);
});
