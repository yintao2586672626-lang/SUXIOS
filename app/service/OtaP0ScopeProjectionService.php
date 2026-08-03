<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

final class OtaP0ScopeProjectionService
{
    /**
     * Merge one P0 payload candidate into an existing UI/evidence summary.
     * The candidate is already metadata-only; this method never reads payload content.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $candidate
     */
    public static function accumulatePayloadCandidate(array &$summary, array $candidate): void
    {
        $status = trim((string)($candidate['status'] ?? ''));
        if ($status !== '') {
            $summary['p0_payload_candidate_status_counts'][$status]
                = (int)($summary['p0_payload_candidate_status_counts'][$status] ?? 0) + 1;
        }
        if (!empty($candidate['ready_to_execute'])) {
            $summary['p0_payload_candidate_ready_count'] = (int)($summary['p0_payload_candidate_ready_count'] ?? 0) + 1;
        }
        if ($status === 'missing_expected_payload') {
            $summary['p0_payload_candidate_missing_count'] = (int)($summary['p0_payload_candidate_missing_count'] ?? 0) + 1;
        }
        if ($status === 'expected_payload_present_unverified') {
            $summary['p0_payload_candidate_unverified_count'] = (int)($summary['p0_payload_candidate_unverified_count'] ?? 0) + 1;
        }

        $payloadPath = trim((string)($candidate['payload_path'] ?? ''));
        if ($payloadPath !== '') {
            $summary['p0_payload_candidate_paths'][] = $payloadPath;
        }

        foreach ([
            'target_date_rows' => 'p0_payload_candidate_target_date_rows',
            'traffic_evidence_rows' => 'p0_payload_candidate_traffic_evidence_rows',
            'evidence_source_path_rows' => 'p0_payload_candidate_evidence_source_path_rows',
            'evidence_structured_source_path_rows' => 'p0_payload_candidate_evidence_structured_source_path_rows',
            'evidence_raw_data_field_facts_rows' => 'p0_payload_candidate_evidence_raw_data_field_facts_rows',
            'evidence_raw_data_exposed_rows' => 'p0_payload_candidate_evidence_raw_data_exposed_rows',
            'evidence_sensitive_value_rows' => 'p0_payload_candidate_evidence_sensitive_value_rows',
        ] as $candidateKey => $summaryKey) {
            $summary[$summaryKey] = (int)($summary[$summaryKey] ?? 0) + max(0, (int)($candidate[$candidateKey] ?? 0));
        }

        foreach ((array)($candidate['evidence_metric_keys'] ?? []) as $metricKey) {
            $metricKey = trim((string)$metricKey);
            if ($metricKey !== '') {
                $summary['p0_payload_candidate_evidence_metric_keys'][] = $metricKey;
            }
        }
        foreach ((array)($candidate['evidence_missing_metric_keys'] ?? []) as $metricKey) {
            $metricKey = trim((string)$metricKey);
            if ($metricKey !== '') {
                $summary['p0_payload_candidate_evidence_missing_metric_keys'][] = $metricKey;
            }
        }
        foreach ((array)($candidate['issue_codes'] ?? []) as $issueCode) {
            $issueCode = trim((string)$issueCode);
            if ($issueCode !== '') {
                $summary['p0_payload_candidate_issue_codes'][] = $issueCode;
            }
        }

        $gateSummary = is_array($candidate['capture_gate_summary'] ?? null)
            ? $candidate['capture_gate_summary']
            : [];
        if ($gateSummary === []) {
            return;
        }

        $gateStatus = strtolower(trim((string)($gateSummary['status'] ?? '')));
        if ($gateStatus !== '') {
            $summary['p0_payload_candidate_gate_status_counts'][$gateStatus]
                = (int)($summary['p0_payload_candidate_gate_status_counts'][$gateStatus] ?? 0) + 1;
        }
        $authStatus = strtolower(trim((string)($gateSummary['auth_status'] ?? '')));
        if ($authStatus !== '') {
            $summary['p0_payload_candidate_auth_status_counts'][$authStatus]
                = (int)($summary['p0_payload_candidate_auth_status_counts'][$authStatus] ?? 0) + 1;
        }
        foreach ((array)($gateSummary['failed_check_ids'] ?? []) as $failedCheckId) {
            $failedCheckId = strtolower(trim((string)$failedCheckId));
            if ($failedCheckId !== '') {
                $summary['p0_payload_candidate_gate_failed_check_ids'][] = $failedCheckId;
            }
        }
        foreach ([
            'response_count' => 'p0_payload_candidate_response_count',
            'captured_response_count' => 'p0_payload_candidate_captured_response_count',
            'business_row_count' => 'p0_payload_candidate_business_row_count',
        ] as $gateKey => $summaryKey) {
            $summary[$summaryKey] = (int)($summary[$summaryKey] ?? 0) + max(0, (int)($gateSummary[$gateKey] ?? 0));
        }
        $capturedAt = trim((string)($gateSummary['captured_at'] ?? ''));
        if ($capturedAt !== '' && strcmp($capturedAt, (string)($summary['p0_payload_candidate_latest_captured_at'] ?? '')) > 0) {
            $summary['p0_payload_candidate_latest_captured_at'] = $capturedAt;
        }
    }

    /**
     * Builds the safe platform/date denominator used by the employee console.
     * No raw payload, platform identifier, Profile key, or credential is returned.
     *
     * @return array{status:string,own_traffic_row_count:int,stored_traffic_hotel_ids:array<int,int>,profile_binding_hotel_ids:array<int,int>,sensitive_values_exposed:bool}
     */
    public function project(
        string $platform,
        string $targetDate,
        ?int $systemHotelId = null,
        ?int $tenantId = null
    ): array
    {
        $platform = strtolower(trim($platform));
        $systemHotelId = $systemHotelId !== null ? max(0, $systemHotelId) : 0;
        $tenantId = $tenantId !== null ? max(0, $tenantId) : 0;
        if (!in_array($platform, ['ctrip', 'meituan'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) !== 1
        ) {
            return $this->emptyProjection('invalid_scope');
        }
        if ($systemHotelId > 0 && $tenantId <= 0) {
            return $this->emptyProjection('scope_unavailable');
        }

        try {
            $trafficQuery = Db::name('online_daily_data')
                ->field('platform,compare_type,dimension,system_hotel_id,raw_data')
                ->where('source', $platform)
                ->where('data_date', $targetDate)
                ->whereIn('data_type', ['traffic', 'flow', 'conversion'])
                ->where(static function ($query): void {
                    $query
                        ->whereNull('data_period')
                        ->whereOr('data_period', 'not in', ['next_7_days', 'next_30_days', 'forecast', 'future_forecast']);
                });
            if ($systemHotelId > 0) {
                $trafficQuery->where('system_hotel_id', $systemHotelId);
            }
            if ($tenantId > 0) {
                $trafficQuery->where('tenant_id', $tenantId);
            }
            $trafficRows = $trafficQuery->select()->toArray();
            $ownTrafficRows = array_values(array_filter(
                $trafficRows,
                fn(array $row): bool => OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic($row, $platform)
            ));
            $storedTrafficHotelIds = array_values(array_unique(array_filter(array_map(
                static fn(array $row): int => (int)($row['system_hotel_id'] ?? 0),
                $ownTrafficRows
            ), static fn(int $hotelId): bool => $hotelId > 0)));
            sort($storedTrafficHotelIds, SORT_NUMERIC);

            $activeHotelsQuery = Db::name('hotels')
                ->where('status', 1);
            if ($tenantId > 0) {
                $activeHotelsQuery->where('tenant_id', $tenantId);
            } else {
                $activeHotelsQuery->where('tenant_id', '>', 0);
            }
            if ($systemHotelId > 0) {
                $activeHotelsQuery->where('id', $systemHotelId);
            }
            $activeHotelIds = array_values(array_filter(array_map(
                'intval',
                $activeHotelsQuery->column('id')
            ), static fn(int $hotelId): bool => $hotelId > 0));
            $profileBindingHotelIds = [];
            if ($activeHotelIds !== []) {
                $profileBindingQuery = Db::name('ota_profile_bindings')
                    ->where('platform', $platform)
                    ->where('binding_status', 'active')
                    ->whereIn('system_hotel_id', $activeHotelIds);
                if ($tenantId > 0) {
                    $profileBindingQuery->where('tenant_id', $tenantId);
                }
                $profileBindingHotelIds = array_values(array_unique(array_filter(array_map(
                    'intval',
                    $profileBindingQuery->column('system_hotel_id')
                ), static fn(int $hotelId): bool => $hotelId > 0)));
                sort($profileBindingHotelIds, SORT_NUMERIC);
            }

            return [
                'status' => 'ready',
                'own_traffic_row_count' => count($ownTrafficRows),
                'stored_traffic_hotel_ids' => $storedTrafficHotelIds,
                'profile_binding_hotel_ids' => $profileBindingHotelIds,
                'sensitive_values_exposed' => false,
            ];
        } catch (Throwable) {
            return $this->emptyProjection('projection_unavailable');
        }
    }

    /** @param array<string, mixed> $row */
    private function rowDateScopeIsAuthoritative(array $row, string $platform): bool
    {
        return OtaTrafficAttributionService::rowDateScopeIsAuthoritative($row, $platform);
    }

    /** @return array{status:string,own_traffic_row_count:int,stored_traffic_hotel_ids:array<int,int>,profile_binding_hotel_ids:array<int,int>,sensitive_values_exposed:bool} */
    private function emptyProjection(string $status): array
    {
        return [
            'status' => $status,
            'own_traffic_row_count' => 0,
            'stored_traffic_hotel_ids' => [],
            'profile_binding_hotel_ids' => [],
            'sensitive_values_exposed' => false,
        ];
    }
}
