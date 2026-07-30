<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

final class OperationOptimizationReviewService
{
    public function __construct(
        private readonly OtaStandardEtlService $etlService = new OtaStandardEtlService(),
        private readonly OperationOptimizationWorkbenchService $workbenchService = new OperationOptimizationWorkbenchService(),
        private readonly LongitudinalEvidenceLearningService $learningService = new LongitudinalEvidenceLearningService()
    ) {
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $intent
     * @return array<string, mixed>|null
     */
    public function buildSourceVerifiedMetricReadbackPayload(array $task, array $intent): ?array
    {
        if (strtolower(trim((string)($intent['source_module'] ?? '')))
            !== OperationOptimizationExecutionBridgeService::SOURCE_MODULE
        ) {
            return null;
        }

        $taskId = (int)($task['id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $objectType = strtolower(trim((string)($intent['object_type'] ?? '')));
        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $executedAt = trim((string)($task['executed_at'] ?? ''));
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? $dateStart)), 0, 10);
        $timezone = new DateTimeZone('Asia/Shanghai');
        try {
            $executedDateTime = $executedAt !== '' ? new DateTimeImmutable($executedAt, $timezone) : null;
        } catch (\Throwable) {
            $executedDateTime = null;
        }
        if ($taskId <= 0
            || $hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !in_array($objectType, ['campaign', 'room_product'], true)
            || $expectedMetric === ''
            || $executedDateTime === null
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateEnd) !== 1
        ) {
            return null;
        }

        $periodLengthDays = $this->periodLengthDays($dateStart, $dateEnd);
        if ($periodLengthDays === null) {
            return null;
        }
        $reviewStart = $executedDateTime->setTime(0, 0)->modify('+1 day');
        $reviewEnd = $reviewStart->modify('+' . ($periodLengthDays - 1) . ' days');
        $reviewStartDate = $reviewStart->format('Y-m-d');
        $reviewEndDate = $reviewEnd->format('Y-m-d');
        if ((new DateTimeImmutable('today', $timezone)) < $reviewEnd) {
            return null;
        }

        $baselineDataset = $this->etlService->buildDataset([
            'system_hotel_id' => $hotelId,
            'source' => $platform,
            'start_date' => $dateStart,
            'end_date' => $dateEnd,
            'limit' => 5000,
        ]);
        $baselineWorkbench = $this->workbenchService->build($baselineDataset, [
            'hotel_id' => $hotelId,
            'start_date' => $dateStart,
            'end_date' => $dateEnd,
        ]);
        $baselineSnapshot = $this->metricSnapshot($baselineWorkbench, $intent);

        $followupDataset = $this->etlService->buildDataset([
            'system_hotel_id' => $hotelId,
            'source' => $platform,
            'start_date' => $reviewStartDate,
            'end_date' => $reviewEndDate,
            'limit' => 5000,
        ]);
        $followupWorkbench = $this->workbenchService->build($followupDataset, [
            'hotel_id' => $hotelId,
            'start_date' => $reviewStartDate,
            'end_date' => $reviewEndDate,
        ]);
        $followupSnapshot = $this->metricSnapshot($followupWorkbench, $intent);
        $intentBeforeValue = $this->baselineMetricValue($intent);
        if ($baselineSnapshot === null
            || $followupSnapshot === null
            || $intentBeforeValue === null
            || !$this->sameMetricValue((float)$baselineSnapshot['value'], $intentBeforeValue)
        ) {
            return null;
        }

        $baselineSourceIds = $this->onlineDailyDataIds((array)($baselineSnapshot['evidence_refs'] ?? []));
        $followupSourceIds = $this->onlineDailyDataIds((array)($followupSnapshot['evidence_refs'] ?? []));
        if ($baselineSourceIds === [] || $followupSourceIds === []) {
            return null;
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $declaredBaselineSourceIds = $this->onlineDailyDataIds((array)($evidence['evidence_refs'] ?? []));
        if ($declaredBaselineSourceIds !== $baselineSourceIds
            || (string)($evidence['identity_status'] ?? '') !== 'matched'
            || (string)($evidence['metric_scope'] ?? '') !== 'ota_channel'
        ) {
            return null;
        }
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $expectedDirection = strtolower(trim((string)(
            $targetValue['expected_direction']
            ?? $evidence['expected_direction']
            ?? ''
        )));
        $baselineEvidence = $this->learningSnapshot(
            $hotelId,
            $platform,
            $objectType,
            $expectedMetric,
            $dateStart,
            $dateEnd,
            $baselineSnapshot,
            $evidence
        );
        $followupEvidence = $this->learningSnapshot(
            $hotelId,
            $platform,
            $objectType,
            $expectedMetric,
            $reviewStartDate,
            $reviewEndDate,
            $followupSnapshot,
            $evidence
        );
        $longitudinalReview = $this->learningService->reviewAction(
            $baselineEvidence,
            $followupEvidence,
            [
                'action_ref' => 'operation_execution_task#' . $taskId,
                'action_type' => strtolower(trim((string)($intent['action_type'] ?? ''))),
                'execution_status' => 'executed',
                'executed_at' => $executedAt,
                'evidence_refs' => ['operation_execution_task#' . $taskId],
                'expected_direction' => $expectedDirection,
            ],
            'same_length_period'
        );
        if (($longitudinalReview['status'] ?? '') !== 'verified'
            || ($longitudinalReview['learning_stage'] ?? '') !== 'action_reviewed'
        ) {
            return null;
        }

        $baselineSourceRef = 'online_daily_data#' . implode(',', $baselineSourceIds);
        $followupSourceRef = 'online_daily_data#' . implode(',', $followupSourceIds);
        return [
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => (float)$baselineSnapshot['value']],
            'after' => [$expectedMetric => (float)$followupSnapshot['value']],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => $followupSourceRef,
                'baseline_source_ref' => $baselineSourceRef,
                'followup_source_ref' => $followupSourceRef,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date_start' => $dateStart,
                'baseline_date_end' => $dateEnd,
                'review_date_start' => $reviewStartDate,
                'review_date' => $reviewEndDate,
                'next_review_date' => $reviewEndDate,
                'comparison_window_days' => $periodLengthDays,
                'metric_key' => $expectedMetric,
                'subject' => (string)($followupSnapshot['subject'] ?? ''),
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($followupSourceIds),
                'readback_at' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'measurement_policy' => 'same_hotel_platform_object_metric_same_length_period_readback',
                'learning_stage' => 'action_reviewed',
                'comparison_key' => (string)($longitudinalReview['comparison_key'] ?? ''),
                'expectation_status' => (string)($longitudinalReview['action']['expectation_status'] ?? ''),
                'candidate_sop_eligible' => false,
                'longitudinal_review' => $longitudinalReview,
            ],
            'remark' => '',
            'created_by' => 0,
            'created_at' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $workbench
     * @param array<string, mixed> $intent
     * @return array{
     *   value:float,
     *   subject:string,
     *   evidence_refs:array<int, string>,
     *   data_date:string,
     *   captured_at:string,
     *   platform_hotel_id:string,
     *   fact_scope:string,
     *   source_method:string,
     *   quality_status:string
     * }|null
     */
    public function metricSnapshot(array $workbench, array $intent): ?array
    {
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $objectType = strtolower(trim((string)($intent['object_type'] ?? '')));
        $metric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $subject = trim((string)($objectType === 'campaign'
            ? ($targetValue['keyword'] ?? '')
            : ($targetValue['room_type_key'] ?? '')));
        if ($platform === '' || $subject === '' || $metric === '') {
            return null;
        }

        $moduleKey = $objectType === 'campaign' ? 'keyword_workbench' : 'room_product_mix';
        $subjectKey = $objectType === 'campaign' ? 'keyword' : 'room_type';
        $metricKey = match ($metric) {
            'advertising_roas' => 'roas',
            'keyword_ctr' => 'ctr',
            'competitor_price_gap' => 'competitor_price_gap',
            'room_type_conversion' => 'conversion',
            'room_type_cancel_rate' => 'cancel_rate',
            default => '',
        };
        if ($metricKey === '') {
            return null;
        }

        foreach ((array)($workbench[$moduleKey]['rows'] ?? []) as $row) {
            if (!is_array($row)
                || strtolower(trim((string)($row['platform'] ?? ''))) !== $platform
                || mb_strtolower(trim((string)($row[$subjectKey] ?? ''))) !== mb_strtolower($subject)
                || (string)($row['quality_status'] ?? '') !== 'verified'
                || !is_numeric($row[$metricKey] ?? null)
            ) {
                continue;
            }

            return [
                'value' => (float)$row[$metricKey],
                'subject' => (string)$row[$subjectKey],
                'evidence_refs' => array_values(array_filter(
                    array_map('strval', (array)($row['evidence_refs'] ?? [])),
                    static fn(string $reference): bool => trim($reference) !== ''
                )),
                'data_date' => (string)($row['latest_date'] ?? ''),
                'captured_at' => (string)($row['latest_captured_at'] ?? ''),
                'platform_hotel_id' => (string)($row['platform_hotel_id'] ?? ''),
                'fact_scope' => (string)($row['fact_scope'] ?? ''),
                'source_method' => (string)($row['source_method'] ?? ''),
                'quality_status' => (string)($row['quality_status'] ?? 'unverified'),
            ];
        }

        return null;
    }

    /** @param array<string, mixed> $intent */
    private function baselineMetricValue(array $intent): ?float
    {
        $metric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $key = match ($metric) {
            'advertising_roas' => 'roas',
            'keyword_ctr' => 'ctr',
            'competitor_price_gap' => 'competitor_price_gap',
            'room_type_conversion' => 'conversion',
            'room_type_cancel_rate' => 'cancel_rate',
            default => '',
        };
        return $key !== '' && is_numeric($currentValue[$key] ?? null)
            ? (float)$currentValue[$key]
            : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $intentEvidence
     * @return array<string, mixed>
     */
    private function learningSnapshot(
        int $hotelId,
        string $platform,
        string $objectType,
        string $metricKey,
        string $periodStart,
        string $periodEnd,
        array $snapshot,
        array $intentEvidence
    ): array {
        $subject = trim((string)($snapshot['subject'] ?? ''));
        $platformHotelId = trim((string)($snapshot['platform_hotel_id'] ?? ''));
        $declaredPlatformHotelId = trim((string)($intentEvidence['platform_hotel_id'] ?? ''));
        if ($declaredPlatformHotelId === ''
            || $platformHotelId === ''
            || !hash_equals($declaredPlatformHotelId, $platformHotelId)
        ) {
            $platformHotelId = '';
        }

        $factScope = strtolower(trim((string)($snapshot['fact_scope'] ?? '')));
        $declaredFactScope = strtolower(trim((string)($intentEvidence['fact_scope'] ?? '')));
        if ($declaredFactScope === '' || $factScope !== $declaredFactScope) {
            $factScope = '';
        }
        $sourceMethod = strtolower(trim((string)($snapshot['source_method'] ?? '')));
        $declaredSourceMethod = strtolower(trim((string)($intentEvidence['source_method'] ?? '')));
        if ($declaredSourceMethod === '' || $sourceMethod !== $declaredSourceMethod) {
            $sourceMethod = '';
        }
        $businessModule = strtolower(trim((string)($intentEvidence['business_module'] ?? '')));
        $expectedBusinessModule = $objectType === 'campaign'
            ? 'keyword_workbench'
            : ($objectType === 'room_product' ? 'room_product_mix' : '');
        if ($businessModule !== $expectedBusinessModule) {
            $businessModule = '';
        }
        $dateRole = strtolower(trim((string)($intentEvidence['date_role'] ?? '')));
        if ($dateRole !== 'business_date') {
            $dateRole = '';
        }
        $metricUnit = strtolower(trim((string)($intentEvidence['metric_unit'] ?? '')));
        if ($metricUnit !== $this->metricUnit($metricKey)) {
            $metricUnit = '';
        }

        return [
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'platform_hotel_id' => $platformHotelId,
            'business_module' => $businessModule,
            'subject' => $subject,
            'metric_key' => $metricKey,
            'unit' => $metricUnit,
            'source_method' => $sourceMethod,
            'date_role' => $dateRole,
            'fact_scope' => $factScope,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'captured_at' => (string)($snapshot['captured_at'] ?? ''),
            'quality_status' => (string)($snapshot['quality_status'] ?? 'unverified'),
            'readback_status' => 'readback_verified',
            'value' => $snapshot['value'] ?? null,
            'evidence_refs' => (array)($snapshot['evidence_refs'] ?? []),
        ];
    }

    private function metricUnit(string $metric): string
    {
        return match ($metric) {
            'keyword_ctr', 'room_type_conversion', 'room_type_cancel_rate' => 'percent',
            'competitor_price_gap' => 'cny',
            'advertising_roas' => 'ratio',
            default => '',
        };
    }

    private function periodLengthDays(string $startDate, string $endDate): ?int
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        if ($start === false
            || $end === false
            || $start->format('Y-m-d') !== $startDate
            || $end->format('Y-m-d') !== $endDate
            || $end < $start
        ) {
            return null;
        }
        return (int)$start->diff($end)->format('%a') + 1;
    }

    private function sameMetricValue(float $left, float $right): bool
    {
        return abs($left - $right) <= max(0.0001, abs($left) * 0.000001);
    }

    /**
     * @param array<int, mixed> $references
     * @return array<int, int>
     */
    private function onlineDailyDataIds(array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            if (preg_match('/^online_daily_data[#:](\d+)$/D', trim((string)$reference), $matches) === 1) {
                $ids[] = (int)$matches[1];
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
