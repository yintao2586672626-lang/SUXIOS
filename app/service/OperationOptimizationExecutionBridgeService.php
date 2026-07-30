<?php
declare(strict_types=1);

namespace app\service;

final class OperationOptimizationExecutionBridgeService
{
    public const SOURCE_MODULE = 'operation_optimizer';

    public function __construct(
        private readonly OperationManagementService $operationService = new OperationManagementService(),
        private readonly LongitudinalEvidenceLearningService $learningService = new LongitudinalEvidenceLearningService()
    ) {
    }

    /**
     * @param array<string, mixed> $workbench
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    public function hydrate(array $workbench, array $hotelIds, int $hotelId): array
    {
        $flowsByActionId = [];
        $longitudinalReviews = [];
        if ($hotelId > 0 && $hotelIds !== []) {
            $flow = $this->operationService->executionFlow($hotelIds, $hotelId, [
                'source_module' => self::SOURCE_MODULE,
                'limit' => 500,
            ]);
            foreach ((array)($flow['list'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $longitudinalReview = is_array($item['evidence']['longitudinal_review'] ?? null)
                    ? $item['evidence']['longitudinal_review']
                    : [];
                $latestEvidence = is_array($item['evidence']['latest'] ?? null)
                    ? $item['evidence']['latest']
                    : [];
                $platformResponse = is_array($latestEvidence['platform_response'] ?? null)
                    ? $latestEvidence['platform_response']
                    : [];
                if ($longitudinalReview === [] && is_array($platformResponse['longitudinal_review'] ?? null)) {
                    $longitudinalReview = $platformResponse['longitudinal_review'];
                }
                if ($longitudinalReview !== []) {
                    $longitudinalReviews[] = $longitudinalReview;
                }
                $evidence = is_array($item['recommendation']['evidence'] ?? null)
                    ? $item['recommendation']['evidence']
                    : [];
                $actionId = trim((string)($evidence['optimizer_action_id'] ?? ''));
                if (preg_match('/^[a-f0-9]{32}$/D', $actionId) === 1) {
                    $flowsByActionId[$actionId] = $item;
                }
            }
        }

        $actionableCount = 0;
        $linkedCount = 0;
        $executedCount = 0;
        $reviewedCount = 0;
        foreach (['keyword_workbench', 'room_product_mix'] as $moduleKey) {
            $rows = is_array($workbench[$moduleKey]['rows'] ?? null)
                ? $workbench[$moduleKey]['rows']
                : [];
            foreach ($rows as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $recommendation = is_array($row['recommendation'] ?? null)
                    ? $row['recommendation']
                    : [];
                $actionId = trim((string)($recommendation['id'] ?? ''));
                $executionFlow = $flowsByActionId[$actionId] ?? null;
                $recommendation['execution_flow'] = $executionFlow;
                $row['recommendation'] = $recommendation;

                if (($recommendation['can_create_task'] ?? false) !== true) {
                    continue;
                }
                $actionableCount++;
                if (!is_array($executionFlow)) {
                    continue;
                }
                $linkedCount++;
                if ((string)($executionFlow['execution']['status'] ?? '') === 'executed') {
                    $executedCount++;
                }
                $reviewStatus = (string)($executionFlow['review']['reported_status'] ?? '');
                if (in_array($reviewStatus, ['success', 'near_success', 'failed'], true)
                    && ($executionFlow['evidence_truth']['source_verified'] ?? false) === true
                ) {
                    $reviewedCount++;
                }
            }
            unset($row);
            $workbench[$moduleKey]['rows'] = $rows;
        }
        $learningSummary = $this->learningService->summarizeReviews($longitudinalReviews);

        $loopStatus = 'blocked';
        $nextAction = '先补齐同门店、同平台、同日期的关键词或房型可信事实。';
        if ($actionableCount > 0) {
            $loopStatus = 'ready_for_task';
            $nextAction = '把可信建议转成待审批任务。';
        }
        if ($linkedCount > 0) {
            $loopStatus = 'partial';
            $nextAction = $executedCount < $linkedCount
                ? '进入任务执行与复盘完成审批和人工执行留证。'
                : '等待并回读执行后的同长度、同口径 OTA 事实窗口。';
        }
        if ($actionableCount > 0 && $reviewedCount === $actionableCount) {
            $loopStatus = 'closed';
            $nextAction = '本批建议已完成任务、执行证据和同长度来源复盘。';
        }

        $workbench['loop_summary'] = [
            'status' => $loopStatus,
            'actionable_recommendation_count' => $actionableCount,
            'linked_intent_count' => $linkedCount,
            'executed_task_count' => $executedCount,
            'source_verified_review_count' => $reviewedCount,
            'reviewed_observation_count' => (int)($learningSummary['reviewed_observation_count'] ?? 0),
            'pattern_candidate_count' => (int)($learningSummary['pattern_candidate_count'] ?? 0),
            'next_action' => $nextAction,
            'truth_status' => $reviewedCount > 0
                ? 'source_verified'
                : ($linkedCount > 0 ? 'execution_pending_or_unverified' : 'no_linked_execution'),
        ];
        $workbench['learning_summary'] = $learningSummary;

        return $workbench;
    }

    /**
     * @param array<string, mixed> $workbench
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    public function createFromWorkbench(
        array $workbench,
        string $recommendationId,
        array $hotelIds,
        int $hotelId,
        int $userId
    ): array {
        $recommendation = (new OperationOptimizationWorkbenchService())
            ->findRecommendation($workbench, $recommendationId);
        if ($recommendation === null) {
            throw new \InvalidArgumentException('operation optimizer recommendation is not available in the requested scope');
        }
        if (($recommendation['can_create_task'] ?? false) !== true
            || !is_array($recommendation['task_payload'] ?? null)
        ) {
            throw new \InvalidArgumentException(
                (string)($recommendation['blocked_reason'] ?? 'verified recommendation evidence is required')
            );
        }

        $payload = $recommendation['task_payload'];
        if ((int)($payload['hotel_id'] ?? 0) !== $hotelId) {
            throw new \InvalidArgumentException('recommendation hotel scope does not match the requested hotel');
        }
        $payload['source_module'] = self::SOURCE_MODULE;
        $payload['source_record_id'] = 0;
        $payload['status'] = 'pending_approval';
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        $evidence['optimizer_action_id'] = $recommendationId;
        $payload['evidence'] = $evidence;
        $idempotencyKey = 'operation_optimizer_' . md5('v1|' . $recommendationId);

        return $this->operationService->createExecutionIntent(
            $hotelIds,
            $hotelId,
            $payload,
            $userId,
            false,
            $idempotencyKey,
            true
        );
    }
}
