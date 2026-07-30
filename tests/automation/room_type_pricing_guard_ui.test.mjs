import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const template = fs.readFileSync(
  path.join(repoRoot, 'resources', 'frontend', 'templates', 'fragments', '27-page-agent-center.html'),
  'utf8',
);

test('room type pricing guard lets an operator explicitly enable or disable a room type', () => {
  assert.match(
    template,
    /<select[^>]*v-model\.number="roomTypeConfigForm\.is_enabled"[\s\S]*?<option :value="1">启用<\/option>[\s\S]*?<option :value="0">停用<\/option>[\s\S]*?<\/select>/,
  );
});

test('room type pricing guard uses native positive-price validation before saving', () => {
  assert.match(template, /<form[^>]*@submit\.prevent="saveRoomTypeConfig"/);
  assert.match(
    template,
    /v-model\.number="roomTypeConfigForm\.base_price"[^>]*min="0\.01"[^>]*step="0\.01"[^>]*required/,
  );
  assert.match(
    template,
    /v-model\.number="roomTypeConfigForm\.min_price"[^>]*min="0\.01"[^>]*step="0\.01"[^>]*required/,
  );
  assert.match(
    template,
    /v-model\.number="roomTypeConfigForm\.max_price"[^>]*min="0\.01"[^>]*step="0\.01"[^>]*required/,
  );
  assert.match(template, /<button type="submit"[^>]*:disabled="roomTypeConfigSaving"/);
});

test('missing room type prices remain visibly missing instead of becoming zero', () => {
  assert.doesNotMatch(template, /item\.(?:base_price|min_price|max_price) \|\| 0/);
  assert.match(template, /item\.min_price > 0 \? '¥' \+ item\.min_price : '--'/);
});
