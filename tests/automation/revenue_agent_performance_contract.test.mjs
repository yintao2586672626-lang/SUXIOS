import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const agentController = readFileSync('app/controller/Agent.php', 'utf8');
const competitorModel = readFileSync('app/model/CompetitorAnalysis.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');

test('Revenue Agent workbench replaces authenticated endpoint fan-out with one bundle plus Meituan source', () => {
  assert.match(routes, /Route::get\('\/revenue-bundle', 'Agent\/revenueBundle'\)/);
  const start = appMain.indexOf('const loadRevenueAnalysisBundle = async (options = {}) => {');
  const end = appMain.indexOf('\n            const switchAgentTab', start);
  const loader = appMain.slice(start, end);
  assert(start >= 0 && end > start, 'Revenue Agent bundle loader must exist');
  assert.match(loader, /request\(`\/agent\/revenue-bundle\?\$\{params\}`\)/);
  assert.match(loader, /loadCompetitorAnalysis\(\{[\s\S]*priceResponsePromise:/);
  assert.doesNotMatch(loader, /loadRevenueAnalysis\(|loadRevenueDashboard\(|loadDemandForecasts\(|loadRoomTypes\(|loadPriceSuggestions\(/);
});

test('Revenue Agent backend returns the complete workbench payload and memoizes repeated forecast reads', () => {
  assert.match(agentController, /public function revenueBundle\(\): Response/);
  for (const key of ['overview', 'analysis', 'dashboard', 'forecasts', 'competitor', 'room_types', 'price_suggestions']) {
    assert.match(agentController, new RegExp(`'${key}'\\s*=>`));
  }
  assert.match(agentController, /private array \$revenueForecastRangeCache = \[\]/);
  assert.match(agentController, /private array \$revenueForecastAccuracyCache = \[\]/);
  assert.match(agentController, /private array \$revenueHighDemandDatesCache = \[\]/);
});

test('competitor trends use one grouped batch query instead of a per-competitor query loop', () => {
  assert.match(competitorModel, /public static function getPriceTrends\(/);
  assert.match(competitorModel, /\$trends\[\$competitorId\]\[\] = \$row/);
  assert.match(agentController, /CompetitorAnalysis::getPriceTrends\(\$hotelId, \[\], 0, \$date\)/);
  assert.doesNotMatch(agentController, /foreach \(\$competitors as \$competitorId\)[\s\S]*getPriceTrend/);
});
