import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (relative) => readFileSync(relative, 'utf8');

test('advisory-council documentation uses configured and response readback model identity', () => {
  const document = read('docs/capability-absorption/2026-08-23-master-perspectives-advisory-council.md');
  const migration = read('database/migrations/20260823_create_local_second_brain_runtime.sql');

  assert.match(migration, /qwen3:4b/);
  assert.match(document, /当前注册配置的文本模型是 Ollama `qwen3:4b`/);
  assert.match(document, /`configured_model`、`response_model`、运行状态和精确回读/);
  assert.doesNotMatch(document, /当前复用本机已安装并启用的 Ollama `qwen3:8b`/);
});
