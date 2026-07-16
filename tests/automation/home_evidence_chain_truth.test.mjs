import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const fragment = readFileSync('resources/frontend/templates/fragments/23c-page-compass-detail.html', 'utf8');
const compassStatic = readFileSync('public/compass-static.js', 'utf8');
const publicIndex = readFileSync('public/index.html', 'utf8');

test('home evidence chain uses returned-field states instead of causal claims or invented progress', () => {
  assert.match(fragment, />经营证据链</);
  assert.match(fragment, /不代表已证明因果关系/);
  assert.match(fragment, /node\.ready \? '已返回' : '未返回 · 待补证'/);
  assert.doesNotMatch(fragment, />经营因果链</);
  assert.doesNotMatch(fragment, /node\.ready \? '72%' : '18%'/);
  assert.match(compassStatic, /可能影响因素与证据/);
  assert.doesNotMatch(compassStatic, /定位获客和收入原因/);
});

test('compass static cache key matches the shipped asset content', () => {
  const hash = createHash('sha256').update(compassStatic).digest('hex').slice(0, 10);
  assert.match(publicIndex, new RegExp(`compass-static\\.js\\?v=[^"']*h${hash}`));
});
