import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');

test('unbound opening projects stay explicitly blocked from execution tracking', () => {
  const service = read('app/service/OpeningService.php');
  const template = read('resources/frontend/templates/fragments/13-page-opening-overview.html');

  assert.match(service, /\$hotelId <= 0 => 'binding_missing'/);
  assert.match(service, /'binding_missing' => '未绑定门店'/);
  assert.match(service, /hotel_id is required for opening execution tracking/);
  assert.match(template, /data-testid="opening-binding-missing"[\s\S]*binding_missing：项目尚未绑定系统门店/);
  assert.match(template, /:disabled="openingLoading \|\| !selectedOpeningProjectId \|\| openingExecutionIntentId \|\| !openingProjectForm\.hotel_id"/);
});
