import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');

test('unbound opening projects stay explicitly blocked from execution tracking', () => {
  const service = read('app/service/OpeningService.php');
  const template = read('resources/frontend/templates/fragments/13-page-opening-overview.html');
  const appMain = read('public/app-main.js');

  assert.match(service, /\$hotelId <= 0 => 'binding_missing'/);
  assert.match(service, /'binding_missing' => '未绑定门店'/);
  assert.match(service, /hotel_id is required for opening execution tracking/);
  assert.match(template, /data-testid="opening-binding-missing"[\s\S]*binding_missing：项目尚未绑定系统门店/);
  assert.match(template, /:disabled="openingLoading \|\| !openingExecutionReady \|\| openingExecutionIntentId"/);
  assert.match(template, /openingProjectBindingDirty[\s\S]*尚未保存/);
  assert.match(appMain, /const openingOverviewProject = computed[\s\S]*Number\(project\.id\) === Number\(selectedOpeningProjectId\.value\)/);
  assert.match(appMain, /const openingPersistedHotelId = computed/);
  assert.match(appMain, /const openingProjectBindingDirty = computed/);
  assert.match(appMain, /const openingExecutionReady = computed/);
  assert.match(appMain, /createOpeningExecutionIntent = async[\s\S]*if \(!openingExecutionReady\.value\)[\s\S]*门店绑定尚未保存/);
  assert.match(appMain, /selectOpeningProject = async[\s\S]*openingOverview\.value = null[\s\S]*loadOpeningOverview/);
});
