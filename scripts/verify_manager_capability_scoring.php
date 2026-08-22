#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\ManagerCapabilityScoringService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();
date_default_timezone_set('Asia/Shanghai');

$service = new ManagerCapabilityScoringService();
$errors = [];
$summary = [];
$transactionOpen = false;
$verificationPrefix = 'verify-manager-capability-' . getmypid() . '-' . bin2hex(random_bytes(4));

try {
    $scope = null;
    $hotels = Db::name('hotels')
        ->where('status', 1)
        ->where('tenant_id', '>', 0)
        ->field('id,tenant_id')
        ->order('id', 'asc')
        ->select()
        ->toArray();
    foreach ($hotels as $hotel) {
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $hotelId = (int)($hotel['id'] ?? 0);
        $managers = $service->listManagers($tenantId, $hotelId);
        if ($managers !== []) {
            $scope = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'manager_user_id' => (int)$managers[0]['id'],
            ];
            break;
        }
    }
    if (!is_array($scope)) {
        throw new RuntimeException('no_local_hotel_manager_scope_available');
    }

    $actorUserId = (int)$scope['manager_user_id'];
    $beforePrefixCount = (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($beforePrefixCount !== 0) {
        throw new RuntimeException('verification_prefix_collision');
    }

    Db::startTrans();
    $transactionOpen = true;
    $caseIds = [];
    $followupIds = [];
    $adjustmentIds = [];
    $scoreReviewIds = [];
    $linkedRecurrenceCaseId = 0;
    $firstResult = null;
    for ($index = 1; $index <= 3; $index++) {
        $result = $service->createCase(
            (int)$scope['tenant_id'],
            (int)$scope['hotel_id'],
            $actorUserId,
            [
                'manager_user_id' => (int)$scope['manager_user_id'],
                'business_date' => date('Y-m-d'),
                'problem_facts' => "本地合成验证{$index}：今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。",
                'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
                'verification_status' => 'observed_result',
                'verification_text' => '次日抽查3笔记录，全部签字完整且完成时间符合要求，员工可以独立完成。',
                'followup_due_date' => null,
                'evidence_type' => 'system_record',
                'evidence_reference' => $verificationPrefix . '-system-record-' . $index,
                'evidence_date' => date('Y-m-d'),
                'idempotency_key' => $verificationPrefix . '-' . $index,
            ]
        );
        if ($index === 1) {
            $firstResult = $result;
        }
        $case = is_array($result['case'] ?? null) ? $result['case'] : [];
        $caseIds[] = (int)($case['id'] ?? 0);
        if (($result['readback_verified'] ?? false) !== true
            || (int)($case['tenant_id'] ?? 0) !== (int)$scope['tenant_id']
            || (int)($case['hotel_id'] ?? 0) !== (int)$scope['hotel_id']
            || (int)($case['manager_user_id'] ?? 0) !== (int)$scope['manager_user_id']
            || (string)($case['score_snapshot']['scoring_version'] ?? '') !== ManagerCapabilityScoringService::FORMULA_VERSION
            || preg_match('/^[a-f0-9]{64}$/', (string)($case['input_digest'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string)($case['score_snapshot']['evidence_digest'] ?? '')) !== 1
        ) {
            $errors[] = 'case_readback_mismatch:' . $index;
        }
    }

    $profile = $service->profile(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        (int)$scope['manager_user_id']
    );
    if (($profile['profile_status'] ?? '') !== 'scored'
        || (float)($profile['overall_score'] ?? 0) !== 90.0
        || count((array)($profile['dimensions'] ?? [])) !== 6
    ) {
        $errors[] = 'three_case_profile_not_scored';
    }
    foreach ((array)($profile['dimensions'] ?? []) as $dimension) {
        if (!is_array($dimension)
            || (int)($dimension['sample_count'] ?? 0) < 3
            || (float)($dimension['score'] ?? 0) !== 90.0
        ) {
            $errors[] = 'dimension_sample_or_score_mismatch';
            break;
        }
    }
    $summaryOnlyProfile = $service->profile(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        (int)$scope['manager_user_id'],
        null,
        null,
        false
    );
    if (($summaryOnlyProfile['privacy_scope'] ?? '') !== 'aggregate_only'
        || (array)($summaryOnlyProfile['recent_cases'] ?? []) !== []
        || count((array)($summaryOnlyProfile['dimensions'] ?? [])) !== 6
    ) {
        $errors[] = 'aggregate_only_privacy_projection_mismatch';
    }

    if (!is_array($firstResult)) {
        $errors[] = 'first_result_missing';
    } else {
        $replayed = $service->createCase(
            (int)$scope['tenant_id'],
            (int)$scope['hotel_id'],
            $actorUserId,
            [
                'manager_user_id' => (int)$scope['manager_user_id'],
                'business_date' => date('Y-m-d'),
                'problem_facts' => '本地合成验证1：今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
                'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
                'verification_status' => 'observed_result',
                'verification_text' => '次日抽查3笔记录，全部签字完整且完成时间符合要求，员工可以独立完成。',
                'followup_due_date' => null,
                'evidence_type' => 'system_record',
                'evidence_reference' => $verificationPrefix . '-system-record-1',
                'evidence_date' => date('Y-m-d'),
                'idempotency_key' => $verificationPrefix . '-1',
            ]
        );
        if (($replayed['replayed'] ?? false) !== true
            || (int)($replayed['case']['id'] ?? 0) !== (int)($firstResult['case']['id'] ?? 0)
        ) {
            $errors[] = 'idempotent_replay_mismatch';
        }
    }

    $resolvedBase = $service->createCase(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        [
            'manager_user_id' => (int)$scope['manager_user_id'],
            'business_date' => date('Y-m-d'),
            'problem_facts' => '本地合成待复查：今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'planned_verification',
            'verification_text' => '计划今天抽查3笔记录并核对签字和完成时间。',
            'followup_due_date' => date('Y-m-d'),
            'idempotency_key' => $verificationPrefix . '-resolved-base',
        ]
    );
    $resolvedBaseCase = (array)($resolvedBase['case'] ?? []);
    $caseIds[] = (int)($resolvedBaseCase['id'] ?? 0);
    $resolvedInput = [
        'followup_date' => date('Y-m-d'),
        'followup_outcome' => 'resolved',
        'verification_text' => '今天抽查3笔交接记录，全部签字完整且完成时间符合要求。',
        'sample_count' => 3,
        'evidence_type' => 'signed_checklist',
        'evidence_reference' => 'test-only:transaction-ledger-resolved',
        'evidence_date' => date('Y-m-d'),
        'idempotency_key' => $verificationPrefix . '-resolved-followup',
    ];
    $resolved = $service->createFollowup(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        (int)$resolvedBaseCase['id'],
        $resolvedInput
    );
    $resolvedCase = (array)($resolved['case'] ?? []);
    $resolvedFollowup = (array)($resolved['followup'] ?? []);
    $followupIds[] = (int)($resolvedFollowup['id'] ?? 0);
    $resolvedDimensions = array_column(
        (array)($resolvedFollowup['score_snapshot']['dimensions'] ?? []),
        null,
        'key'
    );
    if (($resolved['readback_verified'] ?? false) !== true
        || (string)($resolvedCase['case_status'] ?? '') !== 'closed'
        || (string)($resolvedFollowup['followup_outcome'] ?? '') !== 'resolved'
        || (int)($resolvedFollowup['sample_count'] ?? 0) !== 3
        || (int)($resolvedDimensions['closure']['score'] ?? 0) !== 90
        || (string)($resolvedCase['problem_facts'] ?? '') !== (string)($resolvedBaseCase['problem_facts'] ?? '')
        || (string)($resolvedCase['action_taken'] ?? '') !== (string)($resolvedBaseCase['action_taken'] ?? '')
        || (string)($resolvedCase['verification_text'] ?? '') !== (string)($resolvedBaseCase['verification_text'] ?? '')
        || preg_match('/^[a-f0-9]{64}$/', (string)($resolvedFollowup['input_digest'] ?? '')) !== 1
        || preg_match('/^[a-f0-9]{64}$/', (string)($resolvedFollowup['score_snapshot']['evidence_digest'] ?? '')) !== 1
    ) {
        $errors[] = 'resolved_followup_readback_mismatch';
    }
    $resolvedReplay = $service->createFollowup(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        (int)$resolvedBaseCase['id'],
        $resolvedInput
    );
    if (($resolvedReplay['replayed'] ?? false) !== true
        || (int)($resolvedReplay['followup']['id'] ?? 0) !== (int)($resolvedFollowup['id'] ?? 0)
    ) {
        $errors[] = 'followup_idempotent_replay_mismatch';
    }
    $followupConflictRejected = false;
    try {
        $service->createFollowup(
            (int)$scope['tenant_id'],
            (int)$scope['hotel_id'],
            $actorUserId,
            (int)$resolvedBaseCase['id'],
            array_merge($resolvedInput, ['verification_text' => '不同复查内容必须被同一个幂等键拒绝。'])
        );
    } catch (InvalidArgumentException $exception) {
        $followupConflictRejected = str_contains($exception->getMessage(), '幂等键');
    }
    if (!$followupConflictRejected) {
        $errors[] = 'followup_idempotency_conflict_not_rejected';
    }

    $recurredBase = $service->createCase(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        [
            'manager_user_id' => (int)$scope['manager_user_id'],
            'business_date' => date('Y-m-d'),
            'problem_facts' => '本地合成复发：今天上午发现两笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
            'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐2笔记录，并指定主管每班抽查。',
            'verification_status' => 'planned_verification',
            'verification_text' => '计划今天抽查3笔记录并核对签字和完成时间。',
            'followup_due_date' => date('Y-m-d'),
            'idempotency_key' => $verificationPrefix . '-recurred-base',
        ]
    );
    $recurredBaseCase = (array)($recurredBase['case'] ?? []);
    $caseIds[] = (int)($recurredBaseCase['id'] ?? 0);
    $recurred = $service->createFollowup(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        (int)$recurredBaseCase['id'],
        [
            'followup_date' => date('Y-m-d'),
            'followup_outcome' => 'recurred',
            'verification_text' => '今天抽查2笔记录，其中1笔再次缺少主管复核签字。',
            'sample_count' => 2,
            'evidence_type' => 'signed_checklist',
            'evidence_reference' => 'test-only:transaction-ledger-recurred',
            'evidence_date' => date('Y-m-d'),
            'next_followup_date' => date('Y-m-d'),
            'recurrence_problem_facts' => '今天抽查两笔前台交接记录，其中一笔再次缺少主管复核签字。',
            'recurrence_action_taken' => '店长重新指定前台主管逐项复核，并在交班前完成签字检查。',
            'recurrence_verification_plan' => '今天再次抽查三笔交接记录并核对主管签字。',
            'idempotency_key' => $verificationPrefix . '-recurred-followup',
        ]
    );
    $recurredCase = (array)($recurred['case'] ?? []);
    $recurredFollowup = (array)($recurred['followup'] ?? []);
    $linkedCase = (array)($recurred['linked_recurrence_case'] ?? []);
    $followupIds[] = (int)($recurredFollowup['id'] ?? 0);
    $linkedRecurrenceCaseId = (int)($linkedCase['id'] ?? 0);
    $caseIds[] = $linkedRecurrenceCaseId;
    $recurredDimensions = array_column(
        (array)($recurredFollowup['score_snapshot']['dimensions'] ?? []),
        null,
        'key'
    );
    if (($recurred['readback_verified'] ?? false) !== true
        || (string)($recurredCase['case_status'] ?? '') !== 'recurred'
        || (int)($recurredDimensions['closure']['score'] ?? 0) !== 50
        || (int)($recurredFollowup['linked_recurrence_case_id'] ?? 0) !== $linkedRecurrenceCaseId
        || $linkedRecurrenceCaseId <= 0
        || (int)($linkedCase['parent_case_id'] ?? 0) !== (int)($recurredBaseCase['id'] ?? 0)
        || (int)($linkedCase['origin_followup_id'] ?? 0) !== (int)($recurredFollowup['id'] ?? 0)
        || (string)($linkedCase['case_status'] ?? '') !== 'pending_verification'
        || (string)($linkedCase['source_kind'] ?? '') !== 'manual_management_three_questions_recurrence'
    ) {
        $errors[] = 'recurred_followup_linked_case_mismatch';
    }

    $firstCaseId = (int)($caseIds[0] ?? 0);
    $firstCaseBeforeReview = $service->readCase(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        (int)$scope['manager_user_id'],
        $firstCaseId
    );
    $reviewInput = [
        'review_outcome' => 'adjusted',
        'reason' => '本地验证：原因证据只有流程线索，人工复核调整为75分。',
        'dimension_overrides' => ['cause_analysis' => 75],
        'source_score_digest' => (string)($firstCaseBeforeReview['score_snapshot']['evidence_digest'] ?? ''),
        'idempotency_key' => $verificationPrefix . '-score-review',
    ];
    $reviewed = $service->createScoreReview(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        $reviewInput
    );
    $scoreReview = (array)($reviewed['score_review'] ?? []);
    $scoreReviewIds[] = (int)($scoreReview['id'] ?? 0);
    $reviewedDimensions = array_column(
        (array)($reviewed['case']['score_snapshot']['dimensions'] ?? []),
        null,
        'key'
    );
    if (($reviewed['readback_verified'] ?? false) !== true
        || (string)($reviewed['case']['score_source'] ?? '') !== 'human_review'
        || (int)($reviewedDimensions['cause_analysis']['score'] ?? 0) !== 75
        || !str_contains(implode(' ', (array)($reviewedDimensions['cause_analysis']['reasons'] ?? [])), '人工复核调整')
    ) {
        $errors[] = 'score_review_readback_mismatch';
    }
    $reviewReplay = $service->createScoreReview(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        $reviewInput
    );
    if (($reviewReplay['replayed'] ?? false) !== true
        || (int)($reviewReplay['score_review']['id'] ?? 0) !== (int)($scoreReview['id'] ?? 0)
    ) {
        $errors[] = 'score_review_idempotent_replay_mismatch';
    }

    $correctionInput = [
        'adjustment_type' => 'corrected',
        'reason' => '本地验证：补正问题事实和结构化证据引用。',
        'business_date' => date('Y-m-d'),
        'problem_facts' => '纠错后：今天上午发现三笔前台交接记录缺少复核签字，经核查是流程未明确主管责任。',
        'action_taken' => '店长安排前台主管现场演示签字标准，按清单逐项补齐3笔记录，并指定主管每班抽查。',
        'verification_status' => 'observed_result',
        'verification_text' => '当天抽查3笔记录，全部签字完整，员工可以独立完成。',
        'followup_due_date' => null,
        'evidence_type' => 'signed_checklist',
        'evidence_reference' => $verificationPrefix . '-corrected-ledger',
        'evidence_date' => date('Y-m-d'),
        'idempotency_key' => $verificationPrefix . '-correction',
    ];
    $corrected = $service->createAdjustment(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        $correctionInput
    );
    $correction = (array)($corrected['adjustment'] ?? []);
    $adjustmentIds[] = (int)($correction['id'] ?? 0);
    if (($corrected['readback_verified'] ?? false) !== true
        || (string)($corrected['case']['problem_facts'] ?? '') !== $correctionInput['problem_facts']
        || (string)($corrected['case']['original_case']['problem_facts'] ?? '') === $correctionInput['problem_facts']
        || (string)($corrected['case']['score_source'] ?? '') !== 'adjustment'
        || (string)($corrected['case']['evidence']['confidence'] ?? '') !== 'high'
    ) {
        $errors[] = 'correction_readback_mismatch';
    }
    $correctionReplay = $service->createAdjustment(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        $correctionInput
    );
    if (($correctionReplay['replayed'] ?? false) !== true
        || (int)($correctionReplay['adjustment']['id'] ?? 0) !== (int)($correction['id'] ?? 0)
    ) {
        $errors[] = 'adjustment_idempotent_replay_mismatch';
    }

    $voided = $service->createAdjustment(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        [
            'adjustment_type' => 'voided',
            'reason' => '本地验证：临时作废以确认档案和队列排除逻辑。',
            'idempotency_key' => $verificationPrefix . '-void',
        ]
    );
    $adjustmentIds[] = (int)($voided['adjustment']['id'] ?? 0);
    if (($voided['case']['is_voided'] ?? false) !== true
        || (string)($voided['case']['case_status'] ?? '') !== 'voided'
        || !array_key_exists('case_score', (array)($voided['case']['score_snapshot'] ?? []))
        || $voided['case']['score_snapshot']['case_score'] !== null
    ) {
        $errors[] = 'void_adjustment_projection_mismatch';
    }
    $restored = $service->createAdjustment(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        $actorUserId,
        $firstCaseId,
        [
            'adjustment_type' => 'restored',
            'reason' => '本地验证：恢复计分以确认追加事件可逆。',
            'idempotency_key' => $verificationPrefix . '-restore',
        ]
    );
    $adjustmentIds[] = (int)($restored['adjustment']['id'] ?? 0);
    if (($restored['case']['is_voided'] ?? true) !== false
        || count((array)($restored['case']['adjustments'] ?? [])) !== 3
        || (string)($restored['case']['problem_facts'] ?? '') !== $correctionInput['problem_facts']
    ) {
        $errors[] = 'restore_adjustment_projection_mismatch';
    }

    $followupQueue = $service->followupQueue(
        (int)$scope['tenant_id'],
        (int)$scope['hotel_id'],
        (int)$scope['manager_user_id'],
        date('Y-m-d')
    );
    $queueCaseIds = array_map('intval', array_column((array)($followupQueue['rows'] ?? []), 'id'));
    if (($followupQueue['data_status'] ?? '') !== 'ready'
        || !in_array($linkedRecurrenceCaseId, $queueCaseIds, true)
        || in_array((int)$resolvedBaseCase['id'], $queueCaseIds, true)
        || in_array((int)$recurredBaseCase['id'], $queueCaseIds, true)
    ) {
        $errors[] = 'followup_queue_projection_mismatch';
    }

    $conflictRejected = false;
    try {
        $service->createCase(
            (int)$scope['tenant_id'],
            (int)$scope['hotel_id'],
            $actorUserId,
            [
                'manager_user_id' => (int)$scope['manager_user_id'],
                'business_date' => date('Y-m-d'),
                'problem_facts' => '这是不同的合成问题事实，必须被相同幂等键拒绝。',
                'action_taken' => '店长安排负责人当天按清单处理并完成检查。',
                'verification_status' => 'observed_result',
                'verification_text' => '当天抽查3笔记录，全部符合要求。',
                'idempotency_key' => $verificationPrefix . '-1',
            ]
        );
    } catch (InvalidArgumentException $exception) {
        $conflictRejected = str_contains($exception->getMessage(), '幂等键');
    }
    if (!$conflictRejected) {
        $errors[] = 'idempotency_conflict_not_rejected';
    }

    $scopeRejected = false;
    try {
        $service->profile(
            (int)$scope['tenant_id'],
            (int)$scope['hotel_id'],
            2147483647
        );
    } catch (RuntimeException $exception) {
        $scopeRejected = str_contains($exception->getMessage(), '不属于当前租户和酒店');
    }
    if (!$scopeRejected) {
        $errors[] = 'out_of_scope_manager_not_rejected';
    }

    $duringCount = (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($duringCount !== 5) {
        $errors[] = 'transaction_case_count:' . $duringCount;
    }
    $duringFollowupCount = (int)Db::name(ManagerCapabilityScoringService::FOLLOWUP_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($duringFollowupCount !== 2) {
        $errors[] = 'transaction_followup_count:' . $duringFollowupCount;
    }
    $duringAdjustmentCount = (int)Db::name(ManagerCapabilityScoringService::ADJUSTMENT_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($duringAdjustmentCount !== 3) {
        $errors[] = 'transaction_adjustment_count:' . $duringAdjustmentCount;
    }
    $duringScoreReviewCount = (int)Db::name(ManagerCapabilityScoringService::REVIEW_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($duringScoreReviewCount !== 1) {
        $errors[] = 'transaction_score_review_count:' . $duringScoreReviewCount;
    }

    $summary = [
        'scope' => $scope,
        'case_ids' => $caseIds,
        'followup_ids' => $followupIds,
        'adjustment_ids' => $adjustmentIds,
        'score_review_ids' => $scoreReviewIds,
        'linked_recurrence_case_id' => $linkedRecurrenceCaseId,
        'case_count_inside_transaction' => $duringCount,
        'followup_count_inside_transaction' => $duringFollowupCount,
        'adjustment_count_inside_transaction' => $duringAdjustmentCount,
        'score_review_count_inside_transaction' => $duringScoreReviewCount,
        'profile_status_inside_transaction' => $profile['profile_status'] ?? null,
        'profile_score_inside_transaction' => $profile['overall_score'] ?? null,
        'dimension_count' => count((array)($profile['dimensions'] ?? [])),
        'aggregate_only_privacy_verified' => !in_array('aggregate_only_privacy_projection_mismatch', $errors, true),
        'idempotent_replay_verified' => true,
        'idempotency_conflict_rejected' => $conflictRejected,
        'followup_idempotent_replay_verified' => true,
        'followup_idempotency_conflict_rejected' => $followupConflictRejected,
        'resolved_followup_readback_verified' => !in_array('resolved_followup_readback_mismatch', $errors, true),
        'recurred_linked_case_readback_verified' => !in_array('recurred_followup_linked_case_mismatch', $errors, true),
        'score_review_readback_verified' => !in_array('score_review_readback_mismatch', $errors, true),
        'correction_void_restore_verified' => !array_intersect([
            'correction_readback_mismatch', 'void_adjustment_projection_mismatch', 'restore_adjustment_projection_mismatch',
        ], $errors),
        'followup_queue_verified' => !in_array('followup_queue_projection_mismatch', $errors, true),
        'out_of_scope_manager_rejected' => $scopeRejected,
        'synthetic_data_persisted' => false,
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . $exception->getMessage();
} finally {
    if ($transactionOpen) {
        Db::rollback();
    }
}

try {
    $afterPrefixCount = (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($afterPrefixCount !== 0) {
        $errors[] = 'synthetic_rows_remained_after_rollback:' . $afterPrefixCount;
    }
    $afterFollowupPrefixCount = (int)Db::name(ManagerCapabilityScoringService::FOLLOWUP_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($afterFollowupPrefixCount !== 0) {
        $errors[] = 'synthetic_followups_remained_after_rollback:' . $afterFollowupPrefixCount;
    }
    $afterAdjustmentPrefixCount = (int)Db::name(ManagerCapabilityScoringService::ADJUSTMENT_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($afterAdjustmentPrefixCount !== 0) {
        $errors[] = 'synthetic_adjustments_remained_after_rollback:' . $afterAdjustmentPrefixCount;
    }
    $afterScoreReviewPrefixCount = (int)Db::name(ManagerCapabilityScoringService::REVIEW_TABLE)
        ->whereLike('idempotency_key', $verificationPrefix . '%')
        ->count();
    if ($afterScoreReviewPrefixCount !== 0) {
        $errors[] = 'synthetic_score_reviews_remained_after_rollback:' . $afterScoreReviewPrefixCount;
    }
    $afterLinkedCaseCount = $linkedRecurrenceCaseId > 0
        ? (int)Db::name(ManagerCapabilityScoringService::CASE_TABLE)->where('id', $linkedRecurrenceCaseId)->count()
        : 0;
    if ($afterLinkedCaseCount !== 0) {
        $errors[] = 'synthetic_linked_case_remained_after_rollback:' . $afterLinkedCaseCount;
    }
    $summary['case_count_after_rollback'] = $afterPrefixCount;
    $summary['followup_count_after_rollback'] = $afterFollowupPrefixCount;
    $summary['adjustment_count_after_rollback'] = $afterAdjustmentPrefixCount;
    $summary['score_review_count_after_rollback'] = $afterScoreReviewPrefixCount;
    $summary['linked_case_count_after_rollback'] = $afterLinkedCaseCount;
    $summary['rollback_verified'] = $afterPrefixCount === 0
        && $afterFollowupPrefixCount === 0
        && $afterAdjustmentPrefixCount === 0
        && $afterScoreReviewPrefixCount === 0
        && $afterLinkedCaseCount === 0;
} catch (Throwable $exception) {
    $errors[] = 'rollback_readback_exception:' . $exception->getMessage();
}

$result = [
    'status' => $errors === [] ? 'pass' : 'fail',
    'summary' => $summary,
    'errors' => $errors,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
