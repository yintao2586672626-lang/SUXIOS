import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context, {
  filename: 'public/revenue-ai-static.js',
});

const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const appMain = readFileSync('public/app-main.js', 'utf8');
const alertPage = readFileSync('resources/frontend/templates/fragments/15c-page-ops-insight.html', 'utf8');
const competitorPage = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const controller = readFileSync('app/controller/OperationManagement.php', 'utf8');
const service = readFileSync('app/service/OperationManagementService.php', 'utf8');
const competitorModel = readFileSync('app/model/CompetitorAnalysis.php', 'utf8');
const agentController = readFileSync('app/controller/Agent.php', 'utf8');

test('threshold alerts expose an idempotent pending-task bridge without automatic OTA execution', () => {
  assert.match(routes, /Route::post\('\/alerts\/:id\/execution-intent', 'OperationManagement\/alertExecutionIntent'\)/);
  assert.match(controller, /createExecutionIntentFromAlert/);
  assert.match(service, /operation_alert_.*md5\('v1\|'/s);
  assert.match(service, /pending_human_approval_no_automatic_ota_write/);
  assert.match(service, /'object_type' => 'operation_checklist'/);
  assert.match(service, /'auto_write_ota' => false/);
  assert.match(appMain, /apiRequest\(`\/operation\/alerts\/\$\{alertId\}\/execution-intent`/);
  assert.match(alertPage, /data-testid="operation-alert-create-task"/);
  assert.match(alertPage, /直接转任务/);
  assert.match(alertPage, /查看待审批任务/);
});

test('competitor microscope prioritizes the largest absolute current gap and preserves source truth', () => {
  const result = helpers.buildCompetitorMicroscope({
    price_matrix: {
      大床房: {
        竞对甲: {
          id: 1,
          competitor_hotel_id: 11,
          competitor_name: '竞对甲',
          room_type_name: '大床房',
          our_price: 360,
          competitor_price: 300,
          diff_percent: 20,
          competitor_data: { evidence_status: 'operator_provided' },
        },
        竞对乙: {
          id: 2,
          competitor_hotel_id: 12,
          competitor_name: '竞对乙',
          room_type_name: '大床房',
          our_price: 310,
          competitor_price: 300,
          diff_percent: 3.33,
          competitor_data: {
            validation_status: 'verified',
            readback_verified: true,
            source_method: 'browser_profile',
          },
        },
      },
    },
    trends: {
      11: [
        { analysis_date: '2026-07-18', competitor_hotel_id: 11, competitor_name: '竞对甲', our_price: 350, competitor_price: 300 },
        { analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', our_price: 360, competitor_price: 300 },
      ],
    },
  });

  assert.equal(result.status, 'ready');
  assert.equal(result.selectedKey, 'id:11');
  assert.equal(result.detail.name, '竞对甲');
  assert.equal(result.detail.sourceStatus, 'operator_provided');
  assert.equal(result.detail.priceGap, 60);
  assert.equal(result.detail.priceGapPercent, 20);
  assert.equal(result.detail.trend.length, 2);
  assert.equal(result.detail.trendChange, 3.33);
  assert.ok(result.detail.dataGaps.includes('source_operator_provided'));
});

test('competitor microscope filters unknown-id trends by competitor name instead of mixing samples', () => {
  const result = helpers.buildCompetitorMicroscope({
    price_matrix: {
      双床房: {
        竞对甲: { competitor_hotel_id: 0, competitor_name: '竞对甲', our_price: 200, competitor_price: 180 },
      },
    },
    trends: {
      0: [
        { analysis_date: '2026-07-18', competitor_hotel_id: 0, competitor_data: { competitor_name: '竞对甲' }, our_price: 190, competitor_price: 180 },
        { analysis_date: '2026-07-18', competitor_hotel_id: 0, competitor_data: { competitor_name: '竞对乙' }, our_price: 190, competitor_price: 100 },
      ],
    },
  });

  assert.equal(result.detail.trend.length, 1);
  assert.equal(result.detail.trend[0].sampleCount, 1);
  assert.equal(result.detail.trend[0].competitorPrice, 180);
});

test('competitor microscope UI and backend trend use the selected analysis date', () => {
  assert.match(competitorPage, /data-testid="competitor-microscope"/);
  assert.match(competitorPage, /data-testid="competitor-microscope-selector"/);
  assert.match(competitorPage, /同日房型证据/);
  assert.match(competitorPage, /不会用 0 或旧数据代替/);
  assert.match(appMain, /revenueAiBuildCompetitorMicroscope/);
  assert.match(competitorModel, /getPriceTrend\(int \$hotelId, int \$competitorId, int \$roomTypeId = 0, \?string \$endDate = null\)/);
  assert.match(agentController, /getPriceTrend\(\$hotelId, \$competitorId, 0, \$date\)/);
});
