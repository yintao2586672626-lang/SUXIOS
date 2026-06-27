import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, needles) {
  const source = read(file);
  for (const needle of needles) {
    check(file, `${label}: ${needle}`, source.includes(needle), needle);
  }
}

function excludesAll(file, label, needles) {
  const source = read(file);
  for (const needle of needles) {
    check(file, `${label}: excludes ${needle}`, !source.includes(needle), needle);
  }
}

const packageJson = JSON.parse(read('package.json'));
check(
  'package.json',
  'package exposes Revenue AI closure verifier',
  packageJson.scripts?.['verify:revenue-ai-closure'] === 'node scripts/verify_revenue_ai_closure_contract.mjs',
  'verify:revenue-ai-closure'
);
check(
  'package.json',
  'p0 guards include Revenue AI closure verifier',
  String(packageJson.scripts?.['verify:p0-guards'] || '').includes('npm run verify:revenue-ai-closure'),
  'verify:p0-guards'
);

includesAll('route/app.php', 'Revenue AI and operation routes are authenticated and complete', [
  "Route::group('api/revenue-ai'",
  "Route::get('/overview', 'RevenueAi/overview')",
  "Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion')",
  "Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent')",
  "Route::post('/execution-intents/:id/approve', 'OperationManagement/approveExecutionIntent')",
  "Route::post('/execution-tasks/:id/execute', 'OperationManagement/executeExecutionTask')",
  "Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence')",
  "Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask')",
  '->middleware(\\app\\middleware\\Auth::class)',
]);

includesAll('app/controller/RevenueAi.php', 'Revenue AI review keeps versioned audit and no OTA write boundary', [
  'recordPriceSuggestionManualReview(',
  'buildManualReviewState(',
  "'manual_review_versions'",
  "'manual_review'",
  "'status_after'",
  "'reviewed_by'",
  "'reviewed_at'",
  "'auto_write_ota' => false",
  "'local_price_updated' => false",
  "'ota_write' => false",
  "'forbidden_actions' => ['apply_price', 'ota_write', 'update_room_type_base_price']",
  'PriceSuggestion::STATUS_PENDING',
  'PriceSuggestion::STATUS_APPROVED',
  'OperationManagementService',
  'createExecutionIntent(',
]);

excludesAll('app/controller/RevenueAi.php', 'Revenue AI endpoints do not directly apply prices', [
  '->apply(',
  "Db::name('room_types')",
  '$roomType->base_price',
  'update_room_type_base_price(',
]);

includesAll('app/service/OperationManagementService.php', 'operation bridge carries approved suggestion into execution and ROI closure', [
  'buildPriceSuggestionExecutionIntentInput',
  'latestManualReviewFromFactors',
  'manualApprovedPriceFromReview',
  "'source_module' => 'price_suggestion'",
  "'manual_review_storage' =>",
  'createExecutionIntent(',
  'approveExecutionIntent(',
  'executeExecutionTask(',
  'addExecutionEvidence(',
  'reviewExecutionTask(',
  'buildExecutionRoi(',
  'after_revenue - before_revenue',
]);

includesAll('app/service/RevenueAiOverviewService.php', 'Revenue AI overview separates process progress, review input, and ROI truth', [
  'buildPriceSuggestionReviewQueue',
  'buildExecutionSummaryFromFlow',
  'sortExecutionEffectReviewInputs',
  'executionEffectReviewInputAction',
  "'input_action_key' =>",
  "'record_execution_evidence'",
  "'record_roi_evidence'",
  "'record_effect_review'",
  'operationFeedbackInputGate',
  "'operation_feedback_input'",
  "'next_day_input_ready'",
  'priceSuggestionExpectedRevparImpact',
  'expected_revpar_impact_missing',
]);

includesAll('public/revenue-ai-static.js', 'Revenue AI helper exposes manual review and effect review actions without fake closure', [
  'buildRevenueAiReviewQueueItems',
  'canApproveWithChanges',
  'canCreateExecutionIntent',
  'actionEntry',
  'autoWriteOta',
  'buildRevenueAiExecutionRows',
  'buildRevenueAiEffectReviewRows',
  'inputActionKey: item.input_action_key ||',
  'nextActionKey: item.input_action_key || item.target_action ||',
  'revparImpactReason',
  'expected_revpar_impact_missing',
  'resolveRevenueAiReviewActionDraft',
  'const endpoint = revenueAiReviewEndpoint(item, normalizedAction);',
  "endpoint.startsWith('/revenue-ai/price-suggestions/')",
]);

includesAll('public/index.html', 'Revenue AI homepage can execute the manual closure path only through local evidence routes', [
  '@click="submitRevenueAiReviewAction(item, \'approve\')"',
  '@click="submitRevenueAiReviewAction(item, \'approve_with_changes\')"',
  '@click="submitRevenueAiReviewAction(item, \'reject\')"',
  '@click="submitRevenueAiReviewAction(item, \'execution_intent\')"',
  "if (normalizedAction === 'execution_intent') {",
  "const revenueAiResolveReviewActionDraft = requireRevenueAiStatic('resolveRevenueAiReviewActionDraft');",
  'const draft = revenueAiResolveReviewActionDraft({ item, action });',
  'const body = revenueAiBuildReviewRequestBody({',
  'openRevenueAiExecutionItem',
  'recordOperationExecutionEvidence(taskItem)',
  'recordOperationRoiEvidence(taskItem)',
  'reviewOperationExecutionTask(taskItem)',
  "`/operation/execution-tasks/${taskId}/execute`",
  "`/operation/execution-tasks/${taskId}/evidence`",
  "`/operation/execution-tasks/${taskId}/review`",
  "evidence_type: 'manual_price_execution'",
  "evidence_type: 'manual_roi_evidence'",
  "evidence_boundary: 'local_manual_evidence_no_ota_write'",
  "evidence_boundary: 'local_manual_roi_evidence_no_ota_write'",
  '{{ item.impactLine }}',
]);

includesAll('tests/RevenueAiControllerTest.php', 'controller tests prove versioned manual review and no OTA write', [
  'testManualReviewStateVersionsPlainApproveWithoutOtaWrite',
  'testManualReviewStateVersionsRejectWithoutApprovedPrice',
  'testExecutionIntentPayloadDoesNotClaimPriceApplication',
  "self::assertFalse($review['auto_write_ota']);",
  "self::assertFalse($payload['ota_write']);",
]);

includesAll('tests/RevenueAiOverviewServiceTest.php', 'overview tests prove review queue, RevPAR impact, and ROI input truth', [
  'testPriceSuggestionReviewQueueSummarizesManualReviewState',
  'testPriceSuggestionReviewQueueExposesExplicitExpectedRevparImpactOnly',
  'testExecutionSummarySeparatesProcessProgressFromEffectReview',
  'testExecutionSummaryPrioritizesEffectReviewInputsWithDataGaps',
  'testExecutionSummaryFiltersByBusinessDateAndMarksReviewedEffectReady',
  "self::assertSame('record_roi_evidence'",
  "self::assertSame('record_execution_evidence'",
  "self::assertSame('use_next_day_input'",
]);

includesAll('tests/automation/revenue_ai_static.test.mjs', 'static helper tests prove homepage action contract', [
  'Revenue AI action rows expose readonly price suggestion review queue',
  'Revenue AI execution helpers keep process and effect review separate',
  'Revenue AI effect review rows expose next-day inputs without fake ROI',
  "assert.equal(rows[0].reviewQueueItems[0].canApproveWithChanges, true)",
  "assert.equal(partialRows[0].inputActionKey, 'record_roi_evidence')",
]);

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/revenue-ai-static.js'), context, {
    filename: 'public/revenue-ai-static.js',
  });
  const helpers = context.window.SUXI_REVENUE_AI_STATIC || {};
  const actionRows = helpers.buildRevenueAiActionRows({
    overview: {
      actions: [{
        key: 'pricing_review',
        title: '待人工审核调价建议',
        status: 'pending_review',
        review_queue: {
          status: 'pending_review',
          display: '待审核 1 / 已批准 1 / 已拒绝 0 / 已应用 0',
          pending_count: 1,
          pending_items: [{
            id: 11,
            room_type_id: 3,
            suggestion_type_label: '竞对跟价',
            status: 'pending_review',
            status_label: '待审核',
            suggestion_date: '2026-06-25',
            current_price: 280,
            current_price_display: '280元',
            suggested_price: 318,
            suggested_price_display: '318元',
            min_price: 220,
            min_price_display: '220元',
            expected_revpar_impact_display: '+12.5元',
            manual_review_required: true,
            auto_write_ota: false,
            can_review: true,
            action_entry: {
              allowed_endpoints: {
                review: '/api/revenue-ai/price-suggestions/11/review',
                execution_intent: '/api/revenue-ai/price-suggestions/11/execution-intent',
              },
              manual_actions: ['approve', 'approve_with_changes', 'reject'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
          recent_items: [{
            id: 12,
            room_type_id: 3,
            suggestion_type_label: '竞对跟价',
            status: 'approved',
            status_label: '已批准',
            current_price_display: '280元',
            suggested_price_display: '318元',
            min_price_display: '220元',
            manual_review_required: true,
            auto_write_ota: false,
            action_entry: {
              allowed_endpoint: '/api/revenue-ai/price-suggestions/12/execution-intent',
              allowed_endpoints: {
                execution_intent: '/api/revenue-ai/price-suggestions/12/execution-intent',
              },
              manual_actions: ['create_execution_intent'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
        },
      }],
    },
  });
  const pending = actionRows[0]?.reviewQueueItems?.[0] || {};
  const approved = actionRows[0]?.reviewQueueItems?.[1] || {};
  check(
    'public/revenue-ai-static.js',
    'runtime helper exposes approve/approve_with_changes/reject but no OTA write',
    pending.canApprove === true
      && pending.canApproveWithChanges === true
      && pending.canReject === true
      && pending.autoWriteOta === false
      && pending.allowedEndpoints.review === '/api/revenue-ai/price-suggestions/11/review'
      && pending.impactLine === '预计RevPAR影响 +12.5元',
    JSON.stringify(pending)
  );
  check(
    'public/revenue-ai-static.js',
    'runtime helper exposes approved suggestion only as execution intent',
    approved.canCreateExecutionIntent === true
      && approved.canApprove === false
      && approved.allowedEndpoint === '/api/revenue-ai/price-suggestions/12/execution-intent',
    JSON.stringify(approved)
  );

  const effectRows = helpers.buildRevenueAiEffectReviewRows({
    overview: {
      hotel_id: 7,
      execution_summary: {
        business_date: '2026-06-25',
        effect_review: {
          input_status: 'partial',
          input_reason: 'operation_roi_missing',
          inputs: [{
            id: 72,
            intent_id: 72,
            hotel_id: 7,
            task_id: 92,
            input_status: 'partial',
            input_reason: 'operation_roi_missing',
            input_action_key: 'record_roi_evidence',
            input_action_label: '补录ROI证据',
            input_next_action: '补齐执行前后收入、成本或平台回执后再判断效果。',
            platform: 'meituan',
            platform_label: '美团',
            action_type: 'price_adjust',
            date_start: '2026-06-25',
            date_end: '2026-06-25',
            roi_status: 'data_gap',
            roi_display: '--',
            target_page: 'ops-track',
            target_action: 'review_effect',
            target_id: 92,
            target_kind: 'task',
          }],
        },
      },
    },
  });
  check(
    'public/revenue-ai-static.js',
    'runtime helper routes missing ROI to local evidence capture',
    effectRows[0]?.nextActionKey === 'record_roi_evidence'
      && effectRows[0]?.canOpenExecution === true
      && effectRows[0]?.roiDisplay === '--',
    JSON.stringify(effectRows[0])
  );
} catch (error) {
  check('public/revenue-ai-static.js', 'runtime helper validation failed', false, error.message);
}

const failures = checks.filter((item) => !item.ok);
if (failures.length) {
  console.error('Revenue AI closure contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`Revenue AI closure contract passed (${checks.length} checks).`);
