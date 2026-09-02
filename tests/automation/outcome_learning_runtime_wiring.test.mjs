import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const runtime = readFileSync('app/service/OperatingOutcomeLearningRuntimeService.php', 'utf8');
const lab = readFileSync('app/service/OperatingOpportunityLabService.php', 'utf8');
const weekly = readFileSync('app/service/WeeklyOperatingPlanSnapshotService.php', 'utf8');
const daily = readFileSync('app/service/DailyOneThingService.php', 'utf8');

test('real execution-flow reviews are wired into daily and weekly selection without writes', () => {
  assert.match(runtime, /OperationManagementService\(\).*executionFlow/s);
  assert.match(runtime, /evidence'\]\['longitudinal_review'/);
  assert.match(runtime, /usable_for_tie_break/);
  assert.match(runtime, /automatic_sop_promotion' => false/);
  assert.match(runtime, /external_write_count' => 0/);

  assert.match(lab, /OperatingOutcomeLearningRuntimeService/);
  assert.match(lab, /bindDailyCandidates/);
  assert.match(lab, /->select\(\$candidates, \$businessDate, \$reviewedObservations\)/);
  assert.match(lab, /outcome_learning_runtime/);

  assert.match(weekly, /OperatingOutcomeLearningRuntimeService\(\).*->load\(\$tenantId, \$hotelId\)/s);
  assert.match(weekly, /'reviewed_observations' => \$reviewedObservations/);
  assert.match(weekly, /'outcome_learning_runtime' => \$outcomeLearningRuntime/);
  assert.match(daily, /outcome_learning_position' => 'after_exact_base_rank_tie_before_candidate_key'/);
  assert.match(daily, /three_or_more_independent_aligned_same_scope_reviews/);
});
