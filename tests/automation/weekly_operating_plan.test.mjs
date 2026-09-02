import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const service = read('app/service/WeeklyOperatingPlanSnapshotService.php');
const migration = read('database/migrations/20260829_create_weekly_operating_plan_snapshots.sql');
const controller = read('app/controller/OperatingOpportunity.php');
const routes = read('route/domain/operations.php');
const cloud = read('app/service/CloudAutomationService.php');
const delivery = read('app/service/WechatRobotDeliveryService.php');
const home = read('public/home-static.js');
const appMain = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/23a-page-compass-summary.html');

test('weekly plan is immutable, source-backed and selects exactly one focus', () => {
  assert.match(service, /weekly_operating_plan\.v2/);
  assert.match(service, /repeated_data_gap/);
  assert.match(service, /oldest_pending_approval/);
  assert.match(service, /review_pending/);
  assert.match(service, /coverage_gap/);
  assert.match(service, /selected_focus/);
  assert.match(service, /source_digest/);
  assert.match(service, /snapshot_fingerprint/);
  assert.match(service, /final_text_sha256/);
  assert.match(service, /external_write_count' => 0/);
  assert.match(service, /external_message_count' => 0/);
  assert.match(service, /automatic_execution' => false/);
  assert.doesNotMatch(service, /LLM|LlmClient|price_suggestions/);
  assert.match(migration, /uk_weekly_plan_source/);
  assert.doesNotMatch(migration.toUpperCase(), /\bUPDATE\b|\bDELETE\b/);
});

test('weekly cloud run saves and rereads the plan before building the digest', () => {
  const planIndex = cloud.indexOf('weeklyOperatingPlanSnapshotService->generateAndReadback(');
  const payloadIndex = cloud.indexOf('buildWeeklyDigestPayload(', planIndex);
  assert.ok(planIndex > 0);
  assert.ok(payloadIndex > planIndex);
  assert.match(cloud, /weekly_plan_snapshot_id/);
  assert.match(cloud, /weekly_plan_source_digest/);
  assert.match(cloud, /weekly_plan_readback_verified/);
  assert.match(delivery, /下周唯一重点/);
  assert.match(delivery, /周计划尚未完成保存与精确回读/);
});

test('weekly plan has authenticated exact APIs and a truthful home readback', () => {
  assert.match(controller, /weeklyPlanLatest/);
  assert.match(controller, /weeklyPlanRead/);
  assert.match(controller, /new \\DateTimeImmutable\('now', new \\DateTimeZone\('Asia\/Shanghai'\)\)/);
  assert.match(controller, /format\('N'\)/);
  assert.match(routes, /weekly-plan\/latest/);
  assert.match(routes, /weekly-plan\/snapshots\/:id/);
  assert.match(template, /:weekly-plan="homeWeeklyOperatingPlan"/);
  assert.match(home, /home-weekly-operating-plan/);
  assert.match(home, /周度经营计划尚未生成/);
  assert.match(appMain, /createHomeWeeklyOperatingPlanController/);
  assert.match(home, /operating-opportunities\/weekly-plan\/latest/);
  assert.match(home, /readback_verified !== true/);
  assert.match(home, /周度经营计划返回的酒店或周期身份不一致/);
  assert.match(home, /latestCompletedWeekEnd/);
  assert.match(home, /getUTCDay\(\) === 0 \? 7/);
});
