<?php
declare(strict_types=1);

namespace app\service;

/**
 * Converts a verified operating-target deviation into one human-approved
 * execution intent. It never writes to an OTA platform and never invents
 * missing facts.
 */
final class OperatingTargetAutomationService
{
    public function __construct(
        private readonly ?OperatingTargetService $targets = null,
        private readonly ?OperationManagementService $operations = null
    ) {
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function createTaskDraft(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $targetDate,
        array $options = []
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('operating_target_automation_scope_invalid');
        }

        $current = ($this->targets ?? new OperatingTargetService())
            ->current($tenantId, $hotelId, $targetDate);
        $record = is_array($current['record'] ?? null) ? $current['record'] : null;
        if ($record === null) {
            throw new \RuntimeException('operating_target_record_missing');
        }
        if ((int)($record['tenant_id'] ?? 0) !== $tenantId
            || (int)($record['hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new \RuntimeException('operating_target_scope_mismatch');
        }

        $facts = is_array($record['facts'] ?? null) ? $record['facts'] : [];
        $calculation = is_array($record['calculation'] ?? null) ? $record['calculation'] : [];
        $metrics = is_array($calculation['metrics'] ?? null) ? $calculation['metrics'] : [];
        $qualityStatus = strtolower(trim((string)($facts['quality_status'] ?? 'unverified')));
        if (!in_array($qualityStatus, ['verified', 'manual_confirmed'], true)
            || (string)($calculation['status'] ?? '') === 'blocked'
        ) {
            throw new \RuntimeException('operating_target_facts_not_actionable');
        }

        $deviations = $this->deviations($facts, $metrics);
        if ($deviations === []) {
            throw new \RuntimeException('operating_target_no_actionable_deviation');
        }

        $analysis = [
            'analysis_type' => 'deterministic_target_deviation',
            'fact_basis' => [
                'scope' => (string)($facts['fact_scope'] ?? ''),
                'source_type' => (string)($facts['source_type'] ?? ''),
                'source_reference' => (string)($facts['source_reference'] ?? ''),
                'quality_status' => $qualityStatus,
                'captured_at' => $facts['fact_captured_at'] ?? null,
            ],
            'deviations' => $deviations,
            'confidence' => $qualityStatus === 'verified' ? 'high' : 'medium',
            'unknowns' => array_values(array_map(
                static fn(array $gap): string => (string)($gap['code'] ?? ''),
                array_values(array_filter((array)($calculation['gaps'] ?? []), 'is_array'))
            )),
            'conclusion' => '经营事实低于已设置目标，建议建立人工审批任务并记录执行证据。',
            'causality_note' => '当前只证明目标偏差，不自动断言具体原因。',
        ];
        $sourceDigest = (new OperatingTargetExecutionProvenanceService())->digest($record);

        $primary = $deviations[0];
        $targetValue = [
            'target_revenue' => $facts['target_revenue'] ?? null,
            'target_occupancy_rate_percent' => $facts['target_occupancy_rate_percent'] ?? null,
            'target_revpar' => $facts['target_revpar'] ?? null,
            'target_metric' => (string)$primary['metric'],
            'assignee_id' => $this->positiveIntOrNull($options['assignee_id'] ?? null),
            'due_at' => $this->textOrNull($options['due_at'] ?? null, 32),
            'review_at' => $this->textOrNull($options['review_at'] ?? null, 32),
        ];
        $targetValue = array_filter($targetValue, static fn(mixed $value): bool => $value !== null && $value !== '');

        $intentInput = [
            'source_module' => 'operating_target',
            'source_record_id' => (int)$record['id'],
            'hotel_id' => $hotelId,
            'platform' => '',
            'object_type' => 'operating_target',
            'action_type' => 'close_target_gap',
            'date_start' => (string)$record['target_date'],
            'date_end' => (string)$record['target_date'],
            'current_value' => [
                'actual_revenue' => $facts['actual_revenue'] ?? null,
                'actual_occupancy_rate_percent' => $metrics['actual_occupancy_rate_percent'] ?? null,
                'actual_revpar' => $metrics['actual_revpar'] ?? null,
                'remaining_revenue' => $metrics['remaining_revenue'] ?? null,
            ],
            'target_value' => $targetValue,
            'evidence' => [
                'operating_target_revision' => (int)($record['revision_no'] ?? 0),
                'operating_target_provenance_contract' =>
                    OperatingTargetExecutionProvenanceService::CONTRACT_VERSION,
                'operating_target_source_digest' => $sourceDigest,
                'analysis' => $analysis,
                'source_policy' => 'PMS/whole-hotel operating facts only; no OTA write',
                'auto_write_ota' => false,
            ],
            'expected_metric' => (string)$primary['metric'],
            'expected_delta' => abs((float)$primary['gap']),
            'risk_level' => count($deviations) >= 2 ? 'high' : 'medium',
            'status' => 'pending_approval',
        ];

        $idempotencyKey = 'operating_target_' . md5($sourceDigest);
        $intent = ($this->operations ?? new OperationManagementService())
            ->createExecutionIntent(
                [$hotelId],
                $hotelId,
                $intentInput,
                $userId,
                false,
                $idempotencyKey,
                true
            );

        return [
            'status' => 'task_draft_ready',
            'target' => [
                'record_id' => (int)$record['id'],
                'revision_no' => (int)($record['revision_no'] ?? 0),
                'target_date' => (string)$record['target_date'],
            ],
            'analysis' => $analysis,
            'execution_intent' => $intent,
            'reused_existing_intent' => ($intent['idempotent_replay'] ?? false) === true,
            'execution_policy' => 'pending_human_approval_no_automatic_ota_write',
        ];
    }

    /** @param array<string,mixed> $facts @param array<string,mixed> $metrics */
    private function deviations(array $facts, array $metrics): array
    {
        $deviations = [];
        $remainingRevenue = $this->numberOrNull($metrics['remaining_revenue'] ?? null);
        if ($remainingRevenue !== null && $remainingRevenue > 0) {
            $deviations[] = [
                'metric' => 'revenue',
                'target' => $this->numberOrNull($facts['target_revenue'] ?? null),
                'actual' => $this->numberOrNull($facts['actual_revenue'] ?? null),
                'gap' => -$remainingRevenue,
                'unit' => 'CNY',
            ];
        }
        $occupancyGap = $this->numberOrNull($metrics['occupancy_gap_points'] ?? null);
        if ($occupancyGap !== null && $occupancyGap < 0) {
            $deviations[] = [
                'metric' => 'occupancy_rate',
                'target' => $this->numberOrNull($facts['target_occupancy_rate_percent'] ?? null),
                'actual' => $this->numberOrNull($metrics['actual_occupancy_rate_percent'] ?? null),
                'gap' => $occupancyGap,
                'unit' => 'percentage_point',
            ];
        }
        $revparGap = $this->numberOrNull($metrics['revpar_gap'] ?? null);
        if ($revparGap !== null && $revparGap < 0) {
            $deviations[] = [
                'metric' => 'revpar',
                'target' => $this->numberOrNull($facts['target_revpar'] ?? null),
                'actual' => $this->numberOrNull($metrics['actual_revpar'] ?? null),
                'gap' => $revparGap,
                'unit' => 'CNY',
            ];
        }
        return $deviations;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? round($number, 2) : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($number) && $number > 0 ? $number : null;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
