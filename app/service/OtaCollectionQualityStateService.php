<?php
declare(strict_types=1);

namespace app\service;

final class OtaCollectionQualityStateService
{
    private const BINDING_REQUIREMENTS = [
        'system_hotel_id',
        'data_source_id',
        'browser_profile_data_source',
        'ota_store_id',
        'profile_id',
        'profile_exists',
    ];

    private const PERMISSION_PROFILE_STATUSES = [
        'permission_denied',
        'no_permission',
        'unauthorized',
    ];

    private const COLLECTION_FAILURE_STATUSES = [
        'failed',
        'capture_failed',
        'capture_success_not_stored',
        'normalized_not_stored',
        'not_stored',
    ];

    private const STALE_STATUSES = [
        'stale',
        'stale_running',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $bindingContractStatus = $this->status($input['binding_contract_status'] ?? '');
        $bindingCheckStatus = $this->status($input['binding_check_status'] ?? '');
        if ($bindingCheckStatus === 'complete') {
            $bindingCheckStatus = 'ok';
            if ($bindingContractStatus === '') {
                $bindingContractStatus = 'complete';
            }
        }
        if ($bindingCheckStatus === '' && $bindingContractStatus === 'complete') {
            $bindingCheckStatus = 'ok';
        }
        $bindingMissingRequirements = $this->stringList($input['binding_missing_requirements'] ?? []);
        $profileStatus = $this->status($input['profile_status'] ?? '');
        $collectionStatus = $this->status($input['collection_status'] ?? '');
        $targetDate = $this->dateValue($input['target_date'] ?? '');
        $dataAsOf = $this->dateValue($input['latest_data_date'] ?? $input['data_as_of'] ?? '');
        $collectedAt = $this->text($input['latest_collected_at'] ?? $input['collected_at'] ?? '');
        $targetDateRows = $this->nonNegativeInt($input['target_date_rows'] ?? 0);
        $targetDateTrafficRows = $this->nonNegativeInt($input['target_date_traffic_rows'] ?? 0);
        $fieldFactStatus = $this->status($input['field_fact_status'] ?? '');
        $hasStoredData = array_key_exists('has_stored_data', $input)
            ? $this->truthy($input['has_stored_data'])
            : ($collectionStatus === 'collected' || $targetDateRows > 0 || $targetDateTrafficRows > 0);
        $hasSourceCount = array_key_exists('source_count', $input);
        $sourceCount = $this->nonNegativeInt($input['source_count'] ?? 0);
        $failureCode = $this->safeFailureCode($input['failure_reason'] ?? '');
        $historicalForTargetDate = $this->isHistoricalForTargetDate($targetDate, $dataAsOf);

        $flags = [];
        $state = 'unverified';
        $nextAction = 'verify_target_date_evidence';

        if ($profileStatus === 'hotel_mismatch') {
            $flags[] = 'hotel_mismatch';
            $state = 'binding_missing';
            $nextAction = 'verify_hotel_poi_binding';
        } elseif ($this->hasBindingRequirementMissing($bindingMissingRequirements)
            || in_array($bindingContractStatus, ['missing', 'incomplete'], true)
            || in_array($bindingCheckStatus, ['missing', 'incomplete'], true)
            || ($hasSourceCount && $sourceCount === 0)
            || in_array($profileStatus, ['unconfigured', 'not_configured', 'profile_missing', 'binding_missing'], true)) {
            $flags[] = 'binding_incomplete';
            $state = 'binding_missing';
            $nextAction = 'complete_hotel_poi_binding';
        } elseif (in_array($profileStatus, self::PERMISSION_PROFILE_STATUSES, true)
            || $collectionStatus === 'permission_denied') {
            $flags[] = 'platform_permission_denied';
            $state = 'permission_denied';
            $nextAction = 'restore_platform_permission';
        } elseif (in_array($collectionStatus, self::COLLECTION_FAILURE_STATUSES, true)
            || $profileStatus === 'capture_failed') {
            if ($failureCode !== '') {
                $flags[] = $failureCode;
            } else {
                $flags[] = 'collection_execution_failed';
            }
            $state = 'collection_failed';
            $nextAction = 'inspect_collection_failure';
        } elseif ($targetDate === '') {
            $flags[] = 'target_date_missing';
            $state = 'unverified';
            $nextAction = 'select_target_date';
        } elseif (in_array($profileStatus, ['waiting_login', 'login_expired', 'session_expired', 'anti_bot'], true)) {
            $flags[] = $this->profileVerificationFlag($profileStatus);
            $state = 'unverified';
            $nextAction = 'verify_platform_login_state';
        } elseif ($targetDateTrafficRows > 0 && in_array($fieldFactStatus, ['missing', 'not_loaded', ''], true)) {
            $flags[] = 'target_date_field_facts_missing';
            $state = 'unverified';
            $nextAction = 'verify_target_date_field_facts';
        } elseif (!$hasStoredData || $dataAsOf === '' || (!$historicalForTargetDate && ($targetDateRows <= 0 || $targetDateTrafficRows <= 0))) {
            if (!$historicalForTargetDate && ($targetDateRows <= 0 || $targetDateTrafficRows <= 0)) {
                $flags[] = $targetDateRows <= 0 ? 'target_date_rows_missing' : 'target_date_traffic_rows_missing';
            }
            if ($dataAsOf === '') {
                $flags[] = 'data_as_of_missing';
            }
            if (!$hasStoredData) {
                $flags[] = 'stored_rows_missing';
            }
            $state = 'unverified';
            $nextAction = 'collect_target_date_data';
        } elseif ($bindingContractStatus !== 'complete' || $bindingCheckStatus !== 'ok') {
            $flags[] = 'profile_binding_not_fully_verified';
            $state = 'unverified';
            $nextAction = 'verify_hotel_poi_binding';
        } elseif ($profileStatus !== 'logged_in') {
            $flags[] = 'platform_session_not_verified';
            $state = 'unverified';
            $nextAction = 'verify_platform_login_state';
        } elseif (in_array($collectionStatus, ['collecting', 'not_collected', 'login_expired', ''], true)) {
            $flags[] = 'collection_state_not_verified';
            $state = 'unverified';
            $nextAction = 'verify_target_date_evidence';
        } elseif ($targetDateTrafficRows > 0 && !in_array($fieldFactStatus, ['ready', 'partial'], true)) {
            $flags[] = 'target_date_field_facts_not_verified';
            $state = 'unverified';
            $nextAction = 'verify_target_date_field_facts';
        } elseif (in_array($collectionStatus, self::STALE_STATUSES, true) || $historicalForTargetDate) {
            if ($historicalForTargetDate) {
                $flags[] = 'target_date_not_current';
            }
            if ($collectionStatus === 'stale_running') {
                $flags[] = 'stale_running_task';
            }
            $state = 'stale';
            $nextAction = 'refresh_target_date_data';
        } elseif ($collectionStatus === 'partial' || $fieldFactStatus === 'partial') {
            if ($fieldFactStatus === 'partial') {
                $flags[] = 'target_date_field_facts_partial';
            } else {
                $flags[] = 'collection_partial';
            }
            $state = 'partial';
            $nextAction = 'complete_missing_target_date_evidence';
        } elseif ($collectionStatus !== 'collected' || $fieldFactStatus !== 'ready') {
            $flags[] = 'collection_state_not_verified';
            $state = 'unverified';
            $nextAction = 'verify_target_date_evidence';
        } else {
            $state = 'available';
            $nextAction = '';
        }

        return [
            'primary_quality_state' => $state,
            'quality_flags' => array_values(array_unique($flags)),
            'metric_scope' => 'ota_channel',
            'target_date' => $targetDate,
            'data_as_of' => $dataAsOf,
            'collected_at' => $collectedAt,
            'evidence' => [
                'binding_contract_status' => $bindingContractStatus,
                'binding_check_status' => $bindingCheckStatus,
                'profile_status' => $profileStatus,
                'collection_status' => $collectionStatus,
                'target_date_rows' => $targetDateRows,
                'target_date_traffic_rows' => $targetDateTrafficRows,
                'field_fact_status' => $fieldFactStatus,
                'has_stored_data' => $hasStoredData,
                'source_count' => $sourceCount,
            ],
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, string> $requirements
     */
    private function hasBindingRequirementMissing(array $requirements): bool
    {
        return array_intersect($requirements, self::BINDING_REQUIREMENTS) !== [];
    }

    private function isHistoricalForTargetDate(string $targetDate, string $dataAsOf): bool
    {
        return $targetDate !== '' && $dataAsOf !== '' && $dataAsOf < $targetDate;
    }

    private function profileVerificationFlag(string $profileStatus): string
    {
        return match ($profileStatus) {
            'login_expired', 'session_expired' => 'platform_session_not_verified',
            'anti_bot' => 'manual_verification_required',
            default => 'manual_login_state_not_verified',
        };
    }

    private function safeFailureCode(mixed $value): string
    {
        $value = $this->status($value);
        $known = [
            'sync_completed_without_saved_rows',
            'target_date_traffic_rows_missing',
            'traffic_field_facts_missing',
            'platform_response_invalid',
            'snapshot_not_saved',
            'capture_success_not_stored',
            'normalized_not_stored',
            'stale_running_task',
        ];

        return in_array($value, $known, true) ? $value : '';
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = $this->status($item);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return array_values(array_unique($items));
    }

    private function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int)$value) : 0;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        return in_array($this->status($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function dateValue(mixed $value): string
    {
        $text = $this->text($value);
        if ($text === '') {
            return '';
        }

        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function status(mixed $value): string
    {
        return strtolower(trim((string)$value));
    }

    private function text(mixed $value): string
    {
        return trim((string)$value);
    }
}
