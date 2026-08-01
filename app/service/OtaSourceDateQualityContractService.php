<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds the stable source/date/quality contract consumed by the unified OTA
 * report. Execution remains platform-specific; this service only normalizes
 * evidence and never invents missing values.
 */
final class OtaSourceDateQualityContractService
{
    private const CONTRACT_VERSION = 'ota-source-date-quality-v1';

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $platformRow
     * @return array<string, mixed>
     */
    public function build(array $context, array $platformRow): array
    {
        $source = strtolower(trim((string)($platformRow['platform'] ?? $context['source'] ?? '')));
        $systemHotelId = max(0, (int)($context['system_hotel_id'] ?? $context['hotelId'] ?? 0));
        $systemHotelName = trim((string)($context['system_hotel_name'] ?? $context['currentHotelName'] ?? ''));
        $expectedHotelName = trim((string)($context['expected_hotel_name'] ?? $context['expectedHotelName'] ?? ''));
        $targetDate = trim((string)($platformRow['targetDate'] ?? $context['target_date'] ?? $context['targetDate'] ?? ''));

        $profile = is_array($platformRow['profile'] ?? null) ? $platformRow['profile'] : [];
        $sourceSummary = is_array($platformRow['sourceSummary'] ?? null) ? $platformRow['sourceSummary'] : [];
        $quality = is_array($platformRow['quality'] ?? null) ? $platformRow['quality'] : [];

        $sourceCount = max(0, (int)($sourceSummary['configuredCount'] ?? 0));
        $platformIdentityConfigured = ($profile['platformIdentityConfigured'] ?? false) === true;
        $bindingStatus = strtolower(trim((string)(
            $profile['bindingContractStatus']
            ?? $profile['bindingCheckStatus']
            ?? ''
        )));
        $bindingReady = in_array($bindingStatus, ['complete', 'ok', 'ready'], true);
        $profileExists = ($profile['profileExists'] ?? false) === true;
        $profileStatus = strtolower(trim((string)($profile['statusCode'] ?? $platformRow['platformLoginStatus'] ?? '')));
        $currentSessionRequired = ($profile['currentSessionProofRequired'] ?? false) === true;
        $currentSessionVerified = ($profile['currentSessionVerified'] ?? false) === true;
        $currentSessionSameSource = ($profile['currentSessionSameSource'] ?? false) === true;

        $targetRows = max(0, (int)($platformRow['targetDateRows'] ?? 0));
        $targetTrafficRows = max(0, (int)($platformRow['targetDateTrafficRows'] ?? 0));
        $fieldFactStatus = strtolower(trim((string)($platformRow['fieldFactStatus'] ?? '')));
        $missingMetricKeys = $this->stringList($platformRow['missingTrafficMetricKeys'] ?? []);
        $readbackSupported = ($platformRow['targetDateReadbackCheckSupported'] ?? false) === true;
        $readbackVerifiedRows = max(0, (int)($platformRow['targetDateReadbackVerifiedRows'] ?? 0));
        $readbackUnverifiedRows = max(0, (int)($platformRow['targetDateReadbackUnverifiedRows'] ?? 0));

        $hotelIdentityReady = $systemHotelId > 0 && $systemHotelName !== '';
        $hotelNameMatches = $expectedHotelName === ''
            || $this->normalizeHotelName($expectedHotelName) === $this->normalizeHotelName($systemHotelName);
        $sourceReady = $sourceCount > 0;
        $authorizationReady = $profileExists
            && in_array($profileStatus, ['logged_in', 'authorized'], true)
            && (!$currentSessionRequired || ($currentSessionVerified && $currentSessionSameSource));
        $fieldFactsReady = $targetTrafficRows > 0
            && $fieldFactStatus === 'ready'
            && $missingMetricKeys === [];
        $targetDateReady = $targetRows > 0 && $targetTrafficRows > 0;
        $readbackReady = $readbackSupported
            && $targetRows > 0
            && $readbackVerifiedRows === $targetRows
            && $readbackUnverifiedRows === 0;

        $stages = [
            'system_hotel_identity' => $this->stage(
                $hotelIdentityReady && $hotelNameMatches,
                $hotelIdentityReady ? ($hotelNameMatches ? '' : 'expected_hotel_name_mismatch') : 'system_hotel_identity_missing',
                [
                    'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                    'system_hotel_name' => $systemHotelName !== '' ? $systemHotelName : null,
                    'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
                ]
            ),
            'platform_identity' => $this->stage(
                $sourceReady && $platformIdentityConfigured,
                !$sourceReady ? 'browser_profile_data_source_missing' : 'platform_hotel_or_poi_id_missing',
                [
                    'configured_source_count' => $sourceCount,
                    'platform_identity_configured' => $platformIdentityConfigured,
                ]
            ),
            'hotel_profile_binding' => $this->stage(
                $sourceReady && $bindingReady && $profileExists,
                !$bindingReady ? 'hotel_scoped_profile_binding_incomplete' : 'authorized_profile_directory_missing',
                [
                    'binding_status' => $bindingStatus !== '' ? $bindingStatus : 'missing',
                    'profile_exists' => $profileExists,
                    'data_source_id' => isset($profile['dataSourceId']) ? (int)$profile['dataSourceId'] : null,
                ]
            ),
            'authorization_session' => $this->stage(
                $authorizationReady,
                $this->authorizationFailureReason($profileStatus, $currentSessionRequired, $currentSessionVerified, $currentSessionSameSource),
                [
                    'profile_status' => $profileStatus !== '' ? $profileStatus : 'unconfigured',
                    'current_session_proof_required' => $currentSessionRequired,
                    'current_session_verified' => $currentSessionVerified,
                    'current_session_same_source' => $currentSessionSameSource,
                ]
            ),
            'target_date_capture' => $this->stage(
                $targetDateReady,
                $targetRows <= 0 ? 'target_date_rows_missing' : 'target_date_traffic_rows_missing',
                [
                    'target_date' => $targetDate,
                    'target_date_rows' => $targetRows,
                    'target_date_traffic_rows' => $targetTrafficRows,
                ]
            ),
            'target_date_field_mapping' => $this->stage(
                $fieldFactsReady,
                $targetTrafficRows <= 0 ? 'target_date_traffic_rows_missing' : 'target_date_field_facts_incomplete',
                [
                    'field_fact_status' => $fieldFactStatus !== '' ? $fieldFactStatus : 'not_loaded',
                    'verified_metric_keys' => $this->stringList($platformRow['verifiedTrafficMetricKeys'] ?? []),
                    'missing_metric_keys' => $missingMetricKeys,
                ]
            ),
            'persistence_readback' => $this->stage(
                $readbackReady,
                !$readbackSupported
                    ? 'readback_verification_schema_missing'
                    : ($targetRows <= 0 ? 'target_date_rows_missing' : 'target_date_readback_unverified'),
                [
                    'storage_table' => 'online_daily_data',
                    'check_supported' => $readbackSupported,
                    'verified_rows' => $readbackVerifiedRows,
                    'unverified_rows' => $readbackUnverifiedRows,
                ]
            ),
        ];

        $requiredStagesReady = array_reduce(
            $stages,
            static fn(bool $ready, array $stage): bool => $ready && ($stage['status'] ?? '') === 'ready',
            true
        );
        $hardIdentityOrBindingBlock = !$hotelIdentityReady
            || !$hotelNameMatches
            || !$sourceReady
            || !$platformIdentityConfigured
            || !$bindingReady
            || !$profileExists;
        $overallStatus = $requiredStagesReady
            ? 'ready'
            : ($hardIdentityOrBindingBlock || $targetRows <= 0 ? 'blocked' : 'partial');
        $qualityStatus = $this->qualityStatus(
            $requiredStagesReady,
            $hotelIdentityReady && $hotelNameMatches,
            $sourceReady && $platformIdentityConfigured && $bindingReady && $profileExists,
            $profileStatus,
            $targetRows,
            $readbackReady,
            (string)($quality['primary_quality_state'] ?? '')
        );
        $qualityFlags = array_values(array_unique(array_merge(
            $this->stringList($quality['quality_flags'] ?? []),
            $this->stageFailureReasons($stages)
        )));
        $nextAction = $this->nextAction($stages, $source);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'source' => $source,
            'target_date' => $targetDate,
            'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
            'system_hotel_name' => $systemHotelName !== '' ? $systemHotelName : null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'metric_scope' => 'ota_channel',
            'status' => $overallStatus,
            'quality_status' => $qualityStatus,
            'quality_flags' => $qualityFlags,
            'claim_allowed' => $requiredStagesReady,
            'stages' => $stages,
            'next_action' => $nextAction,
            'execution' => [
                'status_entry' => '/api/online-data/collection-status',
                'capture_entry' => $source === 'meituan'
                    ? '/api/online-data/capture-meituan-browser'
                    : ($source === 'ctrip' ? '/api/online-data/capture-ctrip-browser' : null),
                'required_scope' => ['system_hotel_id', 'target_date', 'source'],
                'forbidden_fallbacks' => [
                    'zero_as_missing_data',
                    'historical_date_as_target_date',
                    'cross_hotel_profile_reuse',
                    'cross_platform_data_substitution',
                ],
            ],
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function stage(bool $ready, string $reason, array $evidence): array
    {
        return [
            'status' => $ready ? 'ready' : 'blocked',
            'reason' => $ready ? '' : $reason,
            'evidence' => $evidence,
        ];
    }

    private function authorizationFailureReason(
        string $profileStatus,
        bool $currentSessionRequired,
        bool $currentSessionVerified,
        bool $currentSessionSameSource
    ): string {
        if (in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true)) {
            return 'platform_permission_denied';
        }
        if (in_array($profileStatus, ['login_expired', 'session_expired', 'waiting_login', 'anti_bot'], true)) {
            return 'authorized_login_session_missing';
        }
        if ($currentSessionRequired && (!$currentSessionVerified || !$currentSessionSameSource)) {
            return 'current_same_source_session_proof_missing';
        }
        return 'authorized_login_session_unverified';
    }

    private function qualityStatus(
        bool $ready,
        bool $hotelIdentityReady,
        bool $bindingReady,
        string $profileStatus,
        int $targetRows,
        bool $readbackReady,
        string $existingQuality
    ): string {
        if ($ready) {
            return 'available';
        }
        if (!$hotelIdentityReady || !$bindingReady) {
            return 'binding_missing';
        }
        if (in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true)) {
            return 'permission_denied';
        }
        if ($targetRows > 0 && !$readbackReady) {
            return 'unverified';
        }
        $existingQuality = strtolower(trim($existingQuality));
        return in_array($existingQuality, [
            'binding_missing',
            'permission_denied',
            'collection_failed',
            'unverified',
            'stale',
            'partial',
        ], true) ? $existingQuality : 'unverified';
    }

    /**
     * @param array<string, array<string, mixed>> $stages
     * @return array<int, string>
     */
    private function stageFailureReasons(array $stages): array
    {
        $reasons = [];
        foreach ($stages as $stage) {
            $reason = trim((string)($stage['reason'] ?? ''));
            if (($stage['status'] ?? '') !== 'ready' && $reason !== '') {
                $reasons[] = $reason;
            }
        }
        return $reasons;
    }

    /**
     * @param array<string, array<string, mixed>> $stages
     * @return array{code:string,requires_user_action:bool}
     */
    private function nextAction(array $stages, string $source): array
    {
        $source = in_array($source, ['ctrip', 'meituan'], true) ? $source : 'ota';
        $actions = [
            'system_hotel_identity' => 'register_exact_system_hotel_identity',
            'platform_identity' => 'configure_' . $source . '_browser_profile_source',
            'hotel_profile_binding' => 'bind_profile_to_exact_system_hotel',
            'authorization_session' => 'complete_authorized_' . $source . '_login',
            'target_date_capture' => 'capture_' . $source . '_target_date',
            'target_date_field_mapping' => 'verify_' . $source . '_target_date_fields',
            'persistence_readback' => 'repair_' . $source . '_persistence_readback',
        ];
        foreach ($actions as $stageKey => $action) {
            if (($stages[$stageKey]['status'] ?? '') !== 'ready') {
                return [
                    'code' => $action,
                    'requires_user_action' => in_array($stageKey, [
                        'system_hotel_identity',
                        'platform_identity',
                        'hotel_profile_binding',
                        'authorization_session',
                    ], true),
                ];
            }
        }
        return [
            'code' => 'consume_in_unified_report',
            'requires_user_action' => false,
        ];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => strtolower(trim((string)$item)),
            $value
        ), static fn(string $item): bool => $item !== '')));
    }

    private function normalizeHotelName(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return (string)preg_replace('/\s+/u', '', $value);
    }
}
