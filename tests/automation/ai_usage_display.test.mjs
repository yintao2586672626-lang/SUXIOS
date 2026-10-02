import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const fragment = readFileSync('resources/frontend/templates/fragments/33-page-ai-governance.html', 'utf8');
const marker = '<section v-if="aiGovernanceSelectedLog.usage_observation"';
const start = fragment.indexOf(marker);
assert.ok(start >= 0);
const template = fragment.slice(start, fragment.indexOf('</section>', start) + '</section>'.length);
const render = async (usage) => renderToString(createSSRApp({
  template,
  data: () => ({ aiGovernanceSelectedLog: { usage_observation: usage } }),
}));

test('usage details preserve measured zero without presenting a free bill', async () => {
  const html = await render({ status: 'reported', prompt_tokens: 0, completion_tokens: 0,
    total_tokens: 0, cached_tokens: 0, elapsed_ms: 0, dispatched_count: 1, reported_count: 1, observed_total_tokens: 0 });
  assert.match(html, /供应商已返回完整用量/);
  assert.match(html, />0 \/ 0</);
  assert.match(html, />0 ms</);
  assert.match(html, /未知：尚无已核实的计价依据/);
  assert.doesNotMatch(html, /￥0|¥0|undefined|NaN/);
});

test('partial retries display known subtotal separately from unknown total', async () => {
  const html = await render({ status: 'partial', total_tokens: null, elapsed_ms: 350,
    dispatched_count: 2, reported_count: 1, observed_total_tokens: 12 });
  assert.match(html, /部分请求缺少用量，完整总量未知/);
  assert.match(html, />未知 \/ 未知</);
  assert.match(html, /请求 2 次，收到有效用量 1 次；已知部分合计 12 Token/);
  assert.match(html, /切换供应商分别记录/);
});

test('legacy and skipped model calls have distinct visible states', async () => {
  const legacy = await render({ status: 'legacy_unknown' });
  const skipped = await render({ status: 'not_called', dispatched_count: 0, reported_count: 0 });
  assert.match(legacy, /旧记录未保存用量/);
  assert.match(legacy, />未记录</);
  assert.doesNotMatch(legacy, /请求 0 次/);
  assert.match(skipped, /本条记录未调用模型/);
  assert.match(skipped, /请求 0 次/);
});

test('invalid provider usage is visible without invented consumption', async () => {
  const html = await render({ status: 'unavailable', invalid_count: 1, dispatched_count: 1, reported_count: 0 });
  assert.match(html, /供应商未提供有效用量/);
  assert.match(html, /存在无效用量回执/);
  assert.match(html, /已知部分合计 未知 Token/);
});
