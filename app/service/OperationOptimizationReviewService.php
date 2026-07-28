<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

final class OperationOptimizationReviewService
{
    public function __construct(
        private readonly OtaStandardEtlService $etlService = new OtaStandardEtlService(),
        private readonly OperationOptimizationWorkbenchService $workbenchService = new OperationOptimizationWorkbenchService()
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
        $timezone = new DateTimeZone('Asia/Shanghai');
        $executedDateTime = $executedAt !== '' ? new DateTimeImmutable($executedAt, $timezone) : null;
        if ($taskId <= 0
            || $hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !in_array($objectType, ['campaign', 'room_product'], true)
            || $expectedMetric === ''
            || $executedDateTime === null
        ) {
            return null;
        }

        $reviewDate = $executedDateTime->modify('+1 day')->format('Y-m-d');
        if ((new DateTimeImmutable('now', $timezone))->format('Y-m-d') < $reviewDate) {
            return null;
        }

        $dataset = $this->etlService->buildDataset([
            'system_hotel_id' => $hotelId,
            'source' => $platform,
            'start_date' => $reviewDate,
            'end_date' => $reviewDate,
            'limit' => 5000,
        ]);
        $reviewWorkbench = $this->workbenchService->build($dataset, [
            'hotel_id' => $hotelId,
            'start_date' => $reviewDate,
            'end_date' => $reviewDate,
        ]);
        $snapshot = $this->metricSnapshot($reviewWorkbench, $intent);
        $beforeValue = $this->baselineMetricValue($intent);
        if ($snapshot === null || $beforeValue === null) {
            return null;
        }

        $sourceIds = $this->onlineDailyDataIds((array)($snapshot['evidence_refs'] ?? []));
        if ($sourceIds === []) {
            return null;
        }
        $dateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($intent['date_end'] ?? $dateStart)), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateEnd) !== 1
        ) {
            return null;
        }

        return [
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => $beforeValue],
            'after' => [$expectedMetric => (float)$snapshot['value']],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . implode(',', $sourceIds),
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date_start' => $dateStart,
                'baseline_date_end' => $dateEnd,
                'review_date' => $reviewDate,
                'next_review_date' => $reviewDate,
                'metric_key' => $expectedMetric,
                'subject' => (string)($snapshot['subject'] ?? ''),
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($sourceIds),
                'readback_at' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'measurement_policy' => 'same_hotel_platform_object_metric_next_day_readback',
            ],
            'remark' => '',
            'created_by' => 0,
            'created_at' => (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $workbench
     * @param array<string, mixed> $intent
     * @return array{value:float,subject:string,evidence_refs:array<int, string>}|null
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
