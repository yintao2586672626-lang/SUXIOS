<?php
declare(strict_types=1);

namespace app\controller\concern;

/**
 * Canonical OTA diagnosis snapshot and exact-readback identity contract.
 */
trait AgentOtaDiagnosisReadbackConcern
{
    private function normalizeOtaDiagnosisScopeDateRange(array $dateRange): array
    {
        $startDate = trim((string)($dateRange['start_date'] ?? $dateRange['start'] ?? ''));
        $endDate = trim((string)($dateRange['end_date'] ?? $dateRange['end'] ?? $startDate));

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    private function buildOtaDiagnosisSnapshot(array $result): array
    {
        $allowed = [
            'hotel', 'platform', 'date_range', 'effective_date_range', 'requested_date_range',
            'coverage', 'evidence_refs', 'platform_summaries', 'metric_comparability',
            'data_summary', 'metrics',
            'derived_metric_lineage', 'data_gaps', 'blocking_data_gaps', 'optional_data_gaps',
            'diagnosis', 'diagnosis_sections', 'core_conclusion', 'main_problems', 'possible_reasons',
            'recommended_actions', 'priority', 'source_policy', 'source_summary', 'evidence_sources',
            'action_items', 'ai_governance', 'decision_status', 'decision_closure', 'execution_policy',
            'evidence_report', 'no_action_reason', 'saved_record', 'record_status', 'superseded_by',
            'validation_status', 'invalid_reason', 'analysis_runtime', 'decision_route',
            'workflow_status', 'missing_fact_codes', 'reference_only_history',
            'operating_radar',
        ];
        $snapshot = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $result)) {
                $snapshot[$field] = $result[$field];
            }
        }
        if (is_array($snapshot['diagnosis'] ?? null)) {
            unset($snapshot['diagnosis']['raw_text']);
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function otaDiagnosisReadbackIdentity(
        array $snapshot,
        int $hotelId,
        string $platform,
        int $schemaVersion = 1
    ): array
    {
        $requestedRange = $this->normalizeOtaDiagnosisScopeDateRange(
            is_array($snapshot['requested_date_range'] ?? null)
                ? $snapshot['requested_date_range']
                : (array)($snapshot['date_range'] ?? [])
        );
        $effectiveRange = $this->normalizeOtaDiagnosisScopeDateRange(
            is_array($snapshot['effective_date_range'] ?? null)
                ? $snapshot['effective_date_range']
                : (array)($snapshot['date_range'] ?? [])
        );
        $evidenceRefs = is_array($snapshot['evidence_refs'] ?? null) ? $snapshot['evidence_refs'] : [];
        if ($evidenceRefs === []) {
            foreach ((array)($snapshot['evidence_sources'] ?? []) as $source) {
                if (!is_array($source) || ($source['decision_eligible'] ?? false) !== true) {
                    continue;
                }
                $ref = trim((string)($source['ref'] ?? ''));
                $sourcePlatform = strtolower(trim((string)($source['platform'] ?? $platform)));
                if ($ref !== '' && $sourcePlatform !== '') {
                    $evidenceRefs[$sourcePlatform][] = $ref;
                }
            }
        }
        foreach ($evidenceRefs as $sourcePlatform => $refs) {
            $normalizedRefs = array_values(array_unique(array_filter(array_map(
                'strval',
                is_array($refs) ? $refs : []
            ))));
            sort($normalizedRefs, SORT_STRING);
            $evidenceRefs[(string)$sourcePlatform] = $normalizedRefs;
        }
        ksort($evidenceRefs, SORT_STRING);

        $identity = [
            'hotel_id' => $hotelId,
            'platform' => strtolower(trim($platform)),
            'requested_date_range' => $requestedRange,
            'effective_date_range' => $effectiveRange,
            'coverage' => is_array($snapshot['coverage'] ?? null) ? $snapshot['coverage'] : [],
            'evidence_refs' => $evidenceRefs,
        ];
        if ($schemaVersion >= 2) {
            $identity['decision_route'] = $this->otaDiagnosisDecisionRouteReadbackIdentity(
                is_array($snapshot['decision_route'] ?? null) ? $snapshot['decision_route'] : []
            );
        }
        if ($schemaVersion >= 3) {
            $canonicalRadar = $this->canonicalizeOtaDiagnosisReadbackIdentity(
                is_array($snapshot['operating_radar'] ?? null) ? $snapshot['operating_radar'] : []
            );
            $identity['operating_radar_digest'] = hash('sha256', json_encode(
                $canonicalRadar,
                // AgentLog JSON storage normalizes integer-valued floats
                // (for example 200.0 -> 200). Mirror that representation so
                // an unchanged radar survives the database round trip.
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        }

        return $this->canonicalizeOtaDiagnosisReadbackIdentity($identity);
    }

    /** @return array<string,mixed> */
    private function otaDiagnosisDecisionRouteReadbackIdentity(array $decisionRoute): array
    {
        $stages = [];
        foreach ((array)($decisionRoute['stages'] ?? []) as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            $stages[] = [
                'key' => (string)($stage['key'] ?? ''),
                'status' => (string)($stage['status'] ?? ''),
                'status_label' => (string)($stage['status_label'] ?? ''),
                'detail' => (string)($stage['detail'] ?? ''),
                'refs' => array_values(array_map(
                    'strval',
                    is_array($stage['refs'] ?? null) ? $stage['refs'] : []
                )),
            ];
        }

        return [
            'version' => (string)($decisionRoute['version'] ?? ''),
            'policy' => (string)($decisionRoute['policy'] ?? ''),
            'final_status' => (string)($decisionRoute['final_status'] ?? ''),
            'stages' => $stages,
        ];
    }

    private function otaDiagnosisReadbackIdentityDigest(array $identity): string
    {
        return hash('sha256', json_encode(
            $identity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private function isStoredOtaDiagnosisReadbackVerified(
        array $context,
        array $snapshot,
        int $hotelId,
        string $platform,
        array $requestedDateRange
    ): bool {
        $storedDigest = trim((string)($context['readback_identity_digest'] ?? ''));
        $schemaVersion = (int)($context['schema_version'] ?? 0);
        if ($storedDigest === ''
            || !in_array($schemaVersion, [1, 2, 3, 4], true)
            || (string)($context['record_status'] ?? '') !== 'active'
            || strtolower(trim((string)($context['platform'] ?? ''))) !== strtolower(trim($platform))
            || $this->normalizeOtaDiagnosisScopeDateRange((array)($context['requested_date_range'] ?? []))
                !== $this->normalizeOtaDiagnosisScopeDateRange($requestedDateRange)
        ) {
            return false;
        }

        if ($schemaVersion >= 3 && is_array($snapshot['operating_radar'] ?? null)) {
            try {
                $this->assertCtripOperatingRadarScope(
                    $snapshot['operating_radar'],
                    $snapshot,
                    $hotelId,
                    $platform,
                    $requestedDateRange
                );
            } catch (\Throwable) {
                return false;
            }
        }

        $identity = $this->otaDiagnosisReadbackIdentity($snapshot, $hotelId, $platform, $schemaVersion);
        return hash_equals($storedDigest, $this->otaDiagnosisReadbackIdentityDigest($identity))
            && ($snapshot['saved_record']['saved'] ?? false) === true
            && ($snapshot['saved_record']['readback_verified'] ?? false) === true;
    }

    private function canonicalizeOtaDiagnosisReadbackIdentity(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeOtaDiagnosisReadbackIdentity($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeOtaDiagnosisReadbackIdentity($item);
        }
        return $value;
    }
}
