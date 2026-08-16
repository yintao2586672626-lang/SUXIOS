<?php
declare(strict_types=1);

namespace app\service;

final class OtaDiagnosisRequestedPeriodGateService
{
    /** @param array<string, mixed> $runtime @return array<string, mixed> */
    public static function apply(array $runtime, bool $usedLatestAvailableData): array
    {
        if (!$usedLatestAvailableData) {
            return $runtime;
        }
        return array_replace($runtime, [
            'mode' => 'deterministic_historical_reference_only',
            'use_rules_only' => true,
            'model_allowed' => false,
            'model_called' => false,
            'fallback_reason' => 'requested_period_source_rows_missing',
            'requested_period_evidence_ready' => false,
        ]);
    }
}
