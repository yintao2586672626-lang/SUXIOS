<?php
declare(strict_types=1);

// Pure P0 identity, coverage, and source/date/quality projection; no I/O.
function business_chain_business_date_valid(string $date): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) === 1
        && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
}

/** @return array<string, string> */
function business_chain_p0_date_identity(string $requestedDate, mixed $verifierDate): array
{
    $requestedDate = trim($requestedDate);
    $verifierDate = is_string($verifierDate) ? trim($verifierDate) : '';
    $reason = '';
    if (!business_chain_business_date_valid($requestedDate)) {
        $reason = $requestedDate === '' ? 'requested_business_date_missing' : 'requested_business_date_invalid';
    } elseif ($verifierDate === '') {
        $reason = 'verifier_business_date_missing';
    } elseif (!business_chain_business_date_valid($verifierDate)) {
        $reason = 'verifier_business_date_invalid';
    } elseif ($requestedDate !== $verifierDate) {
        $reason = 'verifier_business_date_mismatch';
    }
    return [
        'status' => $reason === '' ? 'ready' : 'blocked',
        'reason' => $reason,
        'requested_target_date' => $requestedDate,
        'verifier_target_date' => $verifierDate,
    ];
}

function business_chain_p0_date_scope_ready(array $scope): bool
{
    $identity = is_array($scope['date_identity'] ?? null) ? $scope['date_identity'] : [];
    $requestedDate = trim((string)($scope['target_date'] ?? ''));
    return ($identity['status'] ?? '') === 'ready'
        && ($identity['requested_target_date'] ?? null) === $requestedDate
        && business_chain_p0_date_identity($requestedDate, $identity['verifier_target_date'] ?? null)['status'] === 'ready';
}

/** Keep the verifier's observed scope separate from the requested hotel. */
function business_chain_p0_hotel_identity(?int $requestedHotelId, array $verifierScope): array
{
    $observed = $verifierScope['system_hotel_id'] ?? null;
    $policy = (string)($verifierScope['hotel_scope_policy'] ?? '');
    $reason = '';
    if ($requestedHotelId !== null && $requestedHotelId <= 0) {
        $reason = 'requested_system_hotel_invalid';
    } elseif (!array_key_exists('system_hotel_id', $verifierScope)) {
        $reason = 'verifier_system_hotel_scope_missing';
    } elseif ($observed === null) {
        if ($policy !== 'platform_date') {
            $reason = 'verifier_system_hotel_scope_unverified';
        }
    } elseif (filter_var($observed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        $reason = 'verifier_system_hotel_scope_invalid';
    } elseif ($requestedHotelId !== (int)$observed) {
        $reason = 'verifier_system_hotel_mismatch';
    } elseif ($policy !== 'system_hotel_id') {
        $reason = 'verifier_system_hotel_scope_unverified';
    }
    return [
        'status' => $reason === '' ? 'ready' : 'blocked',
        'reason' => $reason,
        'requested_system_hotel_id' => $requestedHotelId,
        'verifier_system_hotel_id' => $observed,
        'verifier_scope_policy' => $policy,
    ];
}

function business_chain_p0_hotel_scope_ready(array $scope): bool
{
    $identity = is_array($scope['hotel_identity'] ?? null) ? $scope['hotel_identity'] : [];
    $hotelId = isset($scope['system_hotel_id']) ? (int)$scope['system_hotel_id'] : null;
    return ($identity['status'] ?? '') === 'ready'
        && array_key_exists('requested_system_hotel_id', $identity)
        && $identity['requested_system_hotel_id'] === $hotelId
        && array_key_exists('verifier_system_hotel_id', $identity)
        && business_chain_p0_hotel_identity($hotelId, [
            'system_hotel_id' => $identity['verifier_system_hotel_id'],
            'hotel_scope_policy' => $identity['verifier_scope_policy'] ?? '',
        ])['status'] === 'ready';
}

function business_chain_p0_selected_hotel_ready(array $summary, ?int $hotelId): bool
{
    $coverage = business_chain_p0_readback_coverage($summary);
    if ($coverage['status'] !== 'ready') {
        return false;
    }
    if ($hotelId === null) {
        return ($summary['platform_ready'] ?? false) === true;
    }
    $hotelRows = business_chain_p0_row_count($summary['hotel_scope_evidence']['system_hotel_row_counts'][$hotelId] ?? null);
    return ($summary['selected_system_hotel_id'] ?? null) === $hotelId
        && ($summary['selected_hotel_ready'] ?? false) === true
        && $hotelRows !== null && $hotelRows <= $coverage['stored_target_date_traffic_rows']
        && business_chain_p0_hotel_ready(
            is_array($summary['hotel_scope_evidence'] ?? null) ? $summary['hotel_scope_evidence'] : [],
            $hotelId,
            false
        );
}

/** Missing or malformed counts cannot be coerced into zero or a verified row. */
function business_chain_p0_row_count(mixed $value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
        return null;
    }
    $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $count === false ? null : $count;
}

/** Readback counts refer to all stored traffic rows, even for an external matched subset. */
function business_chain_p0_readback_coverage(array $evidence): array
{
    $counts = [];
    $issues = [];
    $reason = '';
    foreach ([
        'stored_target_date_traffic_rows' => 'readback_population',
        'traffic_rows' => 'traffic_rows',
        'readback_verified_rows' => 'readback_verified_rows',
        'readback_unverified_rows' => 'readback_unverified_rows',
    ] as $key => $label) {
        $counts[$key] = business_chain_p0_row_count($evidence[$key] ?? null);
        if ($counts[$key] === null) {
            $issues[$key] = ($evidence['readback_count_issues'][$key] ?? '') === 'invalid'
                || (array_key_exists($key, $evidence) && $evidence[$key] !== null) ? 'invalid' : 'missing';
            $reason = $reason !== '' ? $reason : $label . '_' . $issues[$key];
        }
    }
    if ($reason === '') {
        if ($counts['stored_target_date_traffic_rows'] === 0) {
            $reason = 'readback_population_empty';
        } elseif ($counts['traffic_rows'] === 0 || $counts['traffic_rows'] > $counts['stored_target_date_traffic_rows']) {
            $reason = 'traffic_rows_outside_readback_population';
        } elseif ($counts['readback_verified_rows'] < $counts['stored_target_date_traffic_rows']) {
            $reason = 'readback_coverage_incomplete';
        } elseif ($counts['readback_verified_rows'] > $counts['stored_target_date_traffic_rows']) {
            $reason = 'readback_coverage_count_mismatch';
        } elseif ($counts['readback_unverified_rows'] !== 0) {
            $reason = 'readback_unverified_rows_present';
        } elseif (($evidence['readback_check_supported'] ?? false) !== true) {
            $reason = 'readback_check_unavailable';
        } elseif (($evidence['readback_status'] ?? '') !== 'ready') {
            $reason = 'readback_status_not_ready';
        }
    }
    $hotelCounts = $evidence['system_hotel_row_counts'] ?? $evidence['hotel_scope_evidence']['system_hotel_row_counts'] ?? null;
    if ($reason === '' && is_array($hotelCounts)) {
        $remainingRows = $counts['stored_target_date_traffic_rows'];
        foreach ($hotelCounts as $hotelCount) {
            $hotelRows = business_chain_p0_row_count($hotelCount);
            if ($hotelRows === null) {
                $reason = 'hotel_row_count_invalid';
                break;
            }
            if ($hotelRows > $remainingRows) {
                $reason = 'hotel_row_counts_exceed_readback_population';
                break;
            }
            $remainingRows -= $hotelRows;
        }
    }
    return $counts + [
        'status' => $reason === '' ? 'ready' : 'blocked',
        'reason' => $reason,
        'readback_count_issues' => $issues,
        'population_policy' => 'all_stored_target_date_traffic_rows_not_external_matched_subset',
    ];
}

/** @param array<string, mixed> $plan */
function business_chain_p0_execution_plan_ready(array $plan): bool
{
    $status = strtolower(trim((string)($plan['status'] ?? '')));
    if (!in_array($status, ['passed', 'incomplete'], true)) {
        return false;
    }

    $scope = is_array($plan['scope'] ?? null) ? $plan['scope'] : [];
    if (!business_chain_p0_date_scope_ready($scope) || !business_chain_p0_hotel_scope_ready($scope)) {
        return false;
    }
    $selectedHotelId = isset($scope['system_hotel_id']) && (int)$scope['system_hotel_id'] > 0
        ? (int)$scope['system_hotel_id']
        : null;
    if (array_key_exists('system_hotel_identity', $scope)) {
        $registeredIdentity = is_array($scope['system_hotel_identity']) ? $scope['system_hotel_identity'] : [];
        if (($registeredIdentity['status'] ?? '') !== 'ready'
            || ($registeredIdentity['system_hotel_id'] ?? null) !== $selectedHotelId) {
            return false;
        }
    }
    $summariesByPlatform = [];
    foreach (business_chain_list($plan['platform_summaries'] ?? []) as $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $platform = strtolower(trim((string)($summary['platform'] ?? '')));
        if ($platform !== '') {
            $summariesByPlatform[$platform] = $summary;
        }
    }

    $requestedPlatforms = array_values(array_unique(array_filter(array_map(
        static fn(mixed $platform): string => strtolower(trim((string)$platform)),
        business_chain_list($scope['platforms'] ?? [])
    ))));
    if ($requestedPlatforms === []) {
        $requestedPlatforms = array_keys($summariesByPlatform);
    }
    if ($requestedPlatforms === []) {
        return false;
    }

    foreach ($requestedPlatforms as $platform) {
        $summary = $summariesByPlatform[$platform] ?? null;
        if (!is_array($summary)
            || ($summary['operator_skip_active'] ?? false) === true
            || (int)($summary['target_date_rows'] ?? 0) <= 0
            || business_chain_p0_readback_coverage($summary)['status'] !== 'ready') {
            return false;
        }

        $scopeReady = business_chain_p0_selected_hotel_ready($summary, $selectedHotelId);
        if (!$scopeReady) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $payload
 * @param array<int, string> $operatorSkippedPlatforms
 * @return array<string, mixed>
 */
function business_chain_compact_p0_execution_plan(
    array $payload,
    string $targetDate,
    ?int $systemHotelId,
    int $exitCode,
    array $operatorSkippedPlatforms = [],
    array $platforms = ['ctrip', 'meituan']
): array
{
    $targetDate = trim($targetDate);
    $dateIdentity = business_chain_p0_date_identity($targetDate, $payload['scope']['date'] ?? null);
    $dateReady = $dateIdentity['status'] === 'ready';
    $hotelIdentity = business_chain_p0_hotel_identity(
        $systemHotelId,
        is_array($payload['scope'] ?? null) ? $payload['scope'] : []
    );
    $hotelScopeReady = $hotelIdentity['status'] === 'ready';
    $scopeReady = $dateReady && $hotelScopeReady;
    $selectedHotelEvidenceMissing = false;
    $readbackIncomplete = false;
    $platformArg = business_chain_platform_scope_arg($platforms);
    $verifierCommand = business_chain_business_date_valid($targetDate)
        ? 'npm.cmd run verify:p0-ota-field-loop -- --date=' . $targetDate
            . ($platformArg !== '' ? ' --platform=' . $platformArg : '')
            . ($systemHotelId !== null ? ' --system-hotel-id=' . $systemHotelId : '')
        : '';
    $platformSummaries = [];
    $operatorSequence = [];
    $operatorSkippedLookup = array_fill_keys(array_map('strtolower', $operatorSkippedPlatforms), true);
    foreach (business_chain_list($payload['platforms'] ?? []) as $platformPayload) {
        if (!is_array($platformPayload)) {
            continue;
        }
        $platform = (string)($platformPayload['platform'] ?? '');
        $gate = is_array($platformPayload['p0_traffic_gate'] ?? null) ? $platformPayload['p0_traffic_gate'] : [];
        $readbackCoverage = business_chain_p0_readback_coverage($gate);
        $readbackReady = $readbackCoverage['status'] === 'ready';
        $readbackIncomplete = $readbackIncomplete || !$readbackReady;
        $platformReady = $scopeReady && $readbackReady && business_chain_p0_platform_ready($platformPayload, $gate);
        $selectedHotelFactReady = $scopeReady && business_chain_p0_hotel_ready($gate, $systemHotelId, $platformReady);
        $selectedHotelReady = $selectedHotelFactReady && $readbackReady;
        $selectedHotelEvidenceMissing = $selectedHotelEvidenceMissing || ($systemHotelId !== null && !$selectedHotelFactReady);
        $operatorSkipActive = isset($operatorSkippedLookup[strtolower($platform)]);
        $activeHotelScopeDeclared = array_key_exists('profile_scope_system_hotel_ids', $gate);
        $activeHotelLookup = business_chain_p0_hotel_id_lookup($gate['profile_scope_system_hotel_ids'] ?? []);
        $steps = [];
        foreach (business_chain_list($gate['hotel_scoped_next_steps'] ?? []) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepHotelId = isset($step['system_hotel_id']) ? (int)$step['system_hotel_id'] : null;
            if (!$hotelScopeReady || ($systemHotelId !== null && $stepHotelId !== $systemHotelId)) {
                continue;
            }
            if ($activeHotelScopeDeclared && ($stepHotelId === null || !isset($activeHotelLookup[$stepHotelId]))) {
                continue;
            }
            $trigger = is_array($step['profile_login_trigger'] ?? null) ? $step['profile_login_trigger'] : [];
            $afterLoginSync = is_array($trigger['after_login_sync'] ?? null) ? $trigger['after_login_sync'] : [];
            $manualLoginVerified = ($step['manual_login_state_verified'] ?? false) === true;
            $skipWithVerifiedLogin = $operatorSkipActive && $manualLoginVerified;
            $hotelFactReady = $scopeReady && business_chain_p0_hotel_ready($gate, $stepHotelId, $platformReady);
            $hotelReady = $hotelFactReady && $readbackReady;
            $readbackOnly = $hotelFactReady && !$readbackReady;
            $compact = [
                'platform' => $platform,
                'system_hotel_id' => $stepHotelId,
                'data_source_id' => isset($step['data_source_id']) ? (int)$step['data_source_id'] : null,
                'data_source_status' => (string)($step['data_source_status'] ?? ''),
                'last_sync_status' => (string)($step['last_sync_status'] ?? ''),
                'manual_login_state_verified' => $manualLoginVerified,
                'login_trigger_entry' => (!$dateReady || $hotelReady || $readbackOnly || $skipWithVerifiedLogin) ? '' : (string)($trigger['entry'] ?? ''),
                'login_trigger_status' => !$dateReady ? 'blocked_by_business_date_identity' : ($readbackOnly ? 'blocked_by_readback_coverage' : ($hotelReady
                    ? 'already_ready_no_login'
                    : ($skipWithVerifiedLogin ? 'login_verified_reference_only' : (string)($trigger['status'] ?? '')))),
                'after_login_sync_entry' => (!$dateReady || $hotelReady || $readbackOnly || $operatorSkipActive) ? '' : (string)($afterLoginSync['entry'] ?? ''),
                'after_login_sync_status' => !$dateReady ? 'blocked_by_business_date_identity' : ($readbackOnly ? 'blocked_by_readback_coverage' : ($hotelReady
                    ? 'already_ready_no_sync'
                    : ($operatorSkipActive ? 'skipped_by_operator_no_sync' : ''))),
                'verifier_command' => $dateReady ? (string)($step['p0_verifier_command'] ?? '') : '',
                'platform_ready' => $platformReady,
                'hotel_ready' => $hotelReady,
                'operator_skip_active' => $operatorSkipActive,
            ];
            $steps[] = $compact;
            if (!$dateReady) {
                continue;
            }
            if ($hotelReady) {
                $operatorSequence[] = [
                    'type' => 'already_ready',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'status' => $platformReady ? 'p0_traffic_gate_ready' : 'p0_hotel_scope_ready',
                    'boundary' => 'Target-date OTA rows and traffic field evidence are already ready for this hotel scope; do not start login or after-login sync from this report.',
                ];
                $operatorSequence[] = [
                    'type' => 'single_scope_verifier',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'command' => $compact['verifier_command'],
                    'required_result' => 'ready',
                ];
                continue;
            }
            if ($operatorSkipActive) {
                $operatorSequence[] = [
                    'type' => 'operator_skip',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'status' => 'p0_skipped_by_operator',
                    'boundary' => 'No OTA collection or after-login sync should be started for this platform while the operator skip is active.',
                ];
                $operatorSequence[] = [
                    'type' => 'single_scope_verifier',
                    'platform' => $platform,
                    'system_hotel_id' => $compact['system_hotel_id'],
                    'data_source_id' => $compact['data_source_id'],
                    'command' => $compact['verifier_command'],
                    'required_result' => 'ready',
                ];
                continue;
            }
            if ($readbackOnly) {
                continue;
            }
            $operatorSequence[] = [
                'type' => 'manual_login',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'entry' => $compact['login_trigger_entry'],
                'status' => $compact['login_trigger_status'],
                'required_human_action' => 'Complete authorized OTA login, captcha/SMS/human verification, and permission confirmation in the opened browser Profile.',
            ];
            $operatorSequence[] = [
                'type' => 'after_login_sync',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'entry' => $compact['after_login_sync_entry'],
                'requires' => 'manual_login_state_verified=true',
            ];
            $operatorSequence[] = [
                'type' => 'single_scope_verifier',
                'platform' => $platform,
                'system_hotel_id' => $compact['system_hotel_id'],
                'data_source_id' => $compact['data_source_id'],
                'command' => $compact['verifier_command'],
                'required_result' => 'ready',
            ];
        }
        if ($scopeReady && !$readbackReady && !$operatorSkipActive) {
            $operatorSequence[] = [
                'type' => 'verify_target_date_readback_coverage', 'platform' => $platform,
                'system_hotel_id' => $systemHotelId, 'status' => 'blocked',
                'reason' => $readbackCoverage['reason'], 'command' => $verifierCommand,
                'required_result' => 'all_stored_target_date_traffic_rows_readback_verified',
                'boundary' => '核对同门店、同渠道、同业务日的全部存储交通行与读回计数；缺少或不一致的读回证明不能进入收益分析和AI建议。',
            ];
        }
        $platformSummaries[] = [
            'platform' => $platform,
            'target_date_rows' => (int)($platformPayload['target_date_rows'] ?? 0),
            'latest_available_date' => (string)($platformPayload['latest_available']['date'] ?? ''),
            'latest_available_rows' => (int)($platformPayload['latest_available']['rows'] ?? 0),
            'field_fact_status' => (string)($platformPayload['field_fact_status'] ?? ''),
            'traffic_gate_status' => (string)($gate['status'] ?? ''),
            'traffic_rows' => $readbackCoverage['traffic_rows'],
            'stored_target_date_traffic_rows' => $readbackCoverage['stored_target_date_traffic_rows'],
            'readback_check_supported' => ($gate['readback_check_supported'] ?? false) === true,
            'readback_verified_rows' => $readbackCoverage['readback_verified_rows'],
            'readback_unverified_rows' => $readbackCoverage['readback_unverified_rows'],
            'readback_count_issues' => $readbackCoverage['readback_count_issues'],
            'readback_coverage' => $readbackCoverage,
            'readback_status' => (string)($gate['readback_status'] ?? 'not_loaded'),
            'action_entry' => !$scopeReady || !$readbackReady || $operatorSkipActive
                || ($systemHotelId !== null && !isset($activeHotelLookup[$systemHotelId]))
                ? '' : (string)($gate['action_entry'] ?? ''),
            'action_status' => !$dateReady ? 'blocked_by_business_date_identity' : (!$hotelScopeReady
                ? 'blocked_by_system_hotel_identity' : ($operatorSkipActive ? 'skipped_by_operator_no_capture' : (string)($gate['action_status'] ?? ''))),
            'platform_ready' => $platformReady,
            'selected_system_hotel_id' => $systemHotelId,
            'selected_hotel_ready' => $selectedHotelReady,
            'hotel_scope_evidence' => array_intersect_key($gate, array_flip([
                'profile_scope_system_hotel_ids', 'system_hotel_row_counts',
                'profile_scope_missing_profile_source_hotel_ids', 'profile_scope_missing_traffic_source_hotel_ids',
                'profile_scope_missing_target_date_traffic_hotel_ids', 'traffic_field_fact_status',
                'p0_standard_fact_status', 'required_metric_value_status', 'platform_hotel_identifier_status',
            ])),
            'operator_skip_active' => $operatorSkipActive,
            'operator_skip_policy' => $operatorSkipActive ? 'p0_skipped_by_operator_reference_only_no_collection' : '',
            'missing_inputs' => array_values(array_unique(array_merge(
                $dateReady ? [] : [$dateIdentity['reason']],
                $hotelScopeReady ? [] : [$hotelIdentity['reason']],
                $readbackReady ? [] : [$readbackCoverage['reason']],
                $systemHotelId !== null && !$selectedHotelReady ? ['selected_system_hotel_evidence_missing'] : [],
                array_map('strval', (array)($gate['action_missing_inputs'] ?? $gate['required_next_inputs'] ?? []))
            ))),
            'next_steps' => $steps,
        ];
    }

    if (!$dateReady) {
        $operatorSequence = [[
            'type' => 'verify_requested_business_date',
            'status' => 'blocked',
            'reason' => $dateIdentity['reason'],
            'command' => $verifierCommand,
            'required_result' => 'same_requested_business_date',
            'boundary' => '先核对原请求业务日期与验证结果；日期身份未通过，不启动登录、同步或采集。',
        ]];
    } elseif (!$hotelScopeReady || $selectedHotelEvidenceMissing) {
        $operatorSequence[] = [
            'type' => 'verify_requested_system_hotel',
            'status' => 'blocked',
            'reason' => !$hotelScopeReady ? $hotelIdentity['reason'] : 'selected_system_hotel_evidence_missing',
            'command' => $verifierCommand,
            'required_result' => 'same_requested_system_hotel_with_profile_and_row_evidence',
            'boundary' => '核对请求门店与验证范围，并补齐该门店的Profile、目标日期行数和字段证据；其他门店的就绪状态不能代替。',
        ];
    }
    return [
        'status' => (string)($payload['status'] ?? 'unknown'),
        'verifier_exit_code' => $exitCode,
        'source_policy' => 'read_p0_verifier_metadata_only_no_ota_collection',
        'sensitive_values_policy' => 'metadata_only_no_cookie_token_profile_path_or_raw_payload',
        'scope' => [
            'target_date' => $targetDate,
            'date_identity' => $dateIdentity,
            'hotel_identity' => $hotelIdentity,
            'system_hotel_id' => $systemHotelId,
            'platforms' => array_values(array_unique(array_map(
                static fn(string $platform): string => strtolower(trim($platform)),
                $platforms
            ))),
            'metric_scope' => (string)($payload['scope']['metric_scope'] ?? 'ota_channel'),
        ],
        'summary' => is_array($payload['summary'] ?? null) ? $payload['summary'] : [],
        'platform_summaries' => $platformSummaries,
        'operator_sequence' => $operatorSequence,
        'authorization_options' => [
            [
                'mode' => 'browser_profile_tiancheng_account',
                'status' => 'allowed_with_human_login',
                'scope_policy' => 'authorized_ota_account_only',
                'required_inputs' => ['manual_login_state_verified', 'authorized_browser_profile', 'selected_system_hotel_match'],
                'completion_gate' => 'p0_field_loop_verifier_ready',
            ],
            [
                'mode' => 'authorized_cookie_api_temporary',
                'status' => 'temporary_only',
                'scope_policy' => 'authorized_cookie_or_headers_may_seed_collection_but_must_not_become_default_mainline',
                'required_inputs' => ['authorized_cookie_or_headers', 'traffic_request_url_or_cdp_endpoint_evidence', 'target_date_traffic_response_captured'],
                'forbidden_outputs' => ['raw_cookie_value_in_report', 'raw_token_value_in_report', 'cookie_api_as_default_mainline'],
                'completion_gate' => 'target_date_rows_ingested_and_p0_field_loop_verifier_ready',
            ],
        ],
        'completion_gate' => [
            'command' => $verifierCommand,
            'required_status' => 'ready',
            'current_status' => $scopeReady && !$selectedHotelEvidenceMissing && !$readbackIncomplete ? (string)($payload['status'] ?? 'unknown') : 'blocked',
        ],
    ];
}

/**
 * Add the stable source/date/quality contract required by the unified report.
 * Missing rows, bindings, and readback evidence remain explicit and never
 * become zero-valued business facts.
 *
 * @param array<int, array<string, mixed>> $sourceRows
 * @param array<string, mixed> $p0ExecutionPlan
 * @return array<int, array<string, mixed>>
 */
function business_chain_attach_source_date_quality(array $sourceRows, array $p0ExecutionPlan): array
{
    $scope = is_array($p0ExecutionPlan['scope'] ?? null) ? $p0ExecutionPlan['scope'] : [];
    $requestedPlatforms = array_key_exists('platforms', $scope)
        ? array_values(array_filter(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($scope['platforms']) ? $scope['platforms'] : []
        ))) : ['ctrip', 'meituan'];
    $systemHotelId = isset($scope['system_hotel_id']) && (int)$scope['system_hotel_id'] > 0
        ? (int)$scope['system_hotel_id']
        : null;
    $hotelIdentity = is_array($scope['system_hotel_identity'] ?? null)
        ? $scope['system_hotel_identity']
        : business_chain_system_hotel_identity($systemHotelId);
    $hotelIdentityStatus = (string)($hotelIdentity['status'] ?? 'missing');
    if ($hotelIdentityStatus === 'ready'
        && ($hotelIdentity['system_hotel_id'] ?? null) !== $systemHotelId) {
        $hotelIdentityStatus = 'scope_mismatch';
    }
    $hotelIdentityReady = $hotelIdentityStatus === 'ready';
    $systemHotelName = trim((string)($hotelIdentity['system_hotel_name'] ?? ''));
    $expectedHotelName = trim((string)($hotelIdentity['expected_hotel_name'] ?? ''));
    $summaries = [];
    foreach (business_chain_list($p0ExecutionPlan['platform_summaries'] ?? []) as $summary) {
        if (!is_array($summary)) {
            continue;
        }
        $platform = strtolower(trim((string)($summary['platform'] ?? '')));
        if ($platform !== '') {
            $summaries[$platform] = $summary;
        }
    }

    foreach ($sourceRows as &$row) {
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $observedDate = trim((string)($row['target_date'] ?? ''));
        $requestedDate = array_key_exists('target_date', $scope)
            ? trim((string)$scope['target_date']) : $observedDate;
        $sourceIdentityReady = in_array($source, ['ctrip', 'meituan'], true)
            && in_array($source, $requestedPlatforms, true);
        $dateIdentityValid = business_chain_business_date_valid($requestedDate) && business_chain_business_date_valid($observedDate);
        $dateIdentityReady = $dateIdentityValid && $requestedDate === $observedDate;
        $verifierDateReady = !array_key_exists('date_identity', $scope) || business_chain_p0_date_scope_ready($scope);
        $verifierHotelReady = business_chain_p0_hotel_scope_ready($scope);
        $scopeIdentityReady = $sourceIdentityReady && $dateIdentityReady && $verifierDateReady && $verifierHotelReady;
        $summary = is_array($summaries[$source] ?? null) ? $summaries[$source] : [];
        $readbackCoverage = business_chain_p0_readback_coverage($summary);
        $readbackReady = $readbackCoverage['status'] === 'ready';
        $missingInputs = array_values(array_unique(array_map(
            'strval',
            (array)($summary['missing_inputs'] ?? [])
        )));
        if (!$readbackReady) {
            $missingInputs[] = $readbackCoverage['reason'];
        }
        $trafficGateStatus = (string)($summary['traffic_gate_status'] ?? 'not_loaded');
        $fieldFactStatus = (string)($summary['field_fact_status'] ?? 'not_loaded');
        $targetDateRows = (int)($summary['target_date_rows'] ?? $row['target_counts']['accepted'] ?? 0);
        $trafficRows = array_key_exists('traffic_rows', $summary)
            ? $readbackCoverage['traffic_rows'] : business_chain_p0_row_count($row['target_counts']['traffic'] ?? null);
        $readbackStatus = (string)($summary['readback_status'] ?? 'not_loaded');
        $platformReady = $hotelIdentityReady
            && $scopeIdentityReady
            && business_chain_p0_selected_hotel_ready($summary, $systemHotelId)
            && ($summary['operator_skip_active'] ?? false) !== true
            && (!array_key_exists('status', $p0ExecutionPlan) || in_array($p0ExecutionPlan['status'], ['passed', 'incomplete'], true))
            && $readbackReady
            && (string)($row['target_status'] ?? '') === 'ready';
        $bindingMissing = array_values(array_filter(
            $missingInputs,
            static function (string $item): bool {
                $item = strtolower(trim($item));
                foreach ([
                    'data_source',
                    'profile_dir',
                    'platform_hotel',
                    'poi_id',
                    'profile_binding',
                    'same_source_profile',
                ] as $marker) {
                    if (str_contains($item, $marker)) {
                        return true;
                    }
                }
                return false;
            }
        )) !== [];
        $permissionDenied = str_contains(strtolower($trafficGateStatus), 'permission')
            || array_values(array_filter(
                $missingInputs,
                static fn(string $item): bool => str_contains(strtolower($item), 'permission')
            )) !== [];

        $qualityStatus = 'unverified';
        if (!$hotelIdentityReady) {
            $qualityStatus = 'binding_missing';
        } elseif (!$scopeIdentityReady) {
            $qualityStatus = 'unverified';
        } elseif ($platformReady) {
            $qualityStatus = 'available';
        } elseif ($bindingMissing) {
            $qualityStatus = 'binding_missing';
        } elseif ($permissionDenied) {
            $qualityStatus = 'permission_denied';
        } elseif ($targetDateRows > 0 || $trafficRows > 0) {
            $qualityStatus = $fieldFactStatus === 'partial' ? 'partial' : 'unverified';
        }

        $qualityFlags = $missingInputs;
        $scopeIdentityFlags = [];
        if (!$verifierHotelReady) {
            $scopeIdentityFlags[] = (string)($scope['hotel_identity']['reason'] ?? '') ?: 'verifier_system_hotel_scope_unverified';
        }
        if (!$verifierDateReady) {
            $scopeIdentityFlags[] = (string)($scope['date_identity']['reason'] ?? '') ?: 'verifier_business_date_unverified';
        }
        if (!$sourceIdentityReady) {
            $scopeIdentityFlags[] = in_array($source, ['ctrip', 'meituan'], true)
                ? 'source_identity_mismatch' : 'source_identity_invalid';
        }
        if (!$dateIdentityReady) {
            $scopeIdentityFlags[] = $dateIdentityValid
                ? 'business_date_identity_mismatch' : 'business_date_identity_invalid';
        }
        $qualityFlags = array_merge($qualityFlags, $scopeIdentityFlags);
        if (!$hotelIdentityReady) {
            array_unshift($qualityFlags, 'system_hotel_identity_' . $hotelIdentityStatus);
        }
        if ($trafficGateStatus !== '' && $trafficGateStatus !== 'ready') {
            $qualityFlags[] = $trafficGateStatus;
        }
        if ($source === 'meituan' && $readbackStatus !== 'ready') {
            $qualityFlags[] = $readbackStatus === 'not_loaded'
                ? 'target_date_readback_not_loaded'
                : 'target_date_' . $readbackStatus;
        }
        $qualityFlags = array_values(array_unique(array_filter(array_map(
            static fn(string $item): string => strtolower(trim($item)),
            $qualityFlags
        ), static fn(string $item): bool => $item !== '')));
        $status = $platformReady
            ? 'ready'
            : (!$hotelIdentityReady || !$scopeIdentityReady || ($targetDateRows <= 0 && $trafficRows <= 0) ? 'blocked' : 'partial');

        $contract = [
            'contract_version' => 'ota-source-date-quality-v1',
            'source' => $source,
            'target_date' => $requestedDate,
            'system_hotel_id' => $systemHotelId,
            'system_hotel_name' => $systemHotelName !== '' ? $systemHotelName : null,
            'expected_hotel_name' => $expectedHotelName !== '' ? $expectedHotelName : null,
            'metric_scope' => 'ota_channel',
            'status' => $status,
            'quality_status' => $qualityStatus,
            'quality_flags' => $qualityFlags,
            'claim_allowed' => $platformReady,
            'evidence' => [
                'requested_platforms' => $requestedPlatforms,
                'observed_source' => $source,
                'requested_target_date' => $requestedDate,
                'observed_target_date' => $observedDate,
                'target_date_rows' => $targetDateRows,
                'traffic_rows' => $trafficRows,
                'stored_target_date_traffic_rows' => $readbackCoverage['stored_target_date_traffic_rows'],
                'readback_coverage' => $readbackCoverage,
                'system_hotel_identity_status' => $hotelIdentityStatus,
                'verifier_system_hotel_id' => $scope['hotel_identity']['verifier_system_hotel_id'] ?? null,
                'verifier_hotel_scope_status' => $verifierHotelReady ? 'ready' : 'blocked',
                'selected_hotel_ready' => business_chain_p0_selected_hotel_ready($summary, $systemHotelId),
                'selected_system_hotel_row_count' => $systemHotelId !== null
                    ? ($summary['hotel_scope_evidence']['system_hotel_row_counts'][$systemHotelId] ?? null) : null,
                'verifier_row_count_scope_policy' => $scope['hotel_identity']['verifier_scope_policy'] ?? 'unverified',
                'expected_name_status' => (string)($hotelIdentity['expected_name_status'] ?? 'not_requested'),
                'same_name_system_hotel_ids' => array_values(array_map(
                    'intval',
                    (array)($hotelIdentity['same_name_system_hotel_ids'] ?? [])
                )),
                'field_fact_status' => $fieldFactStatus,
                'readback_check_supported' => ($summary['readback_check_supported'] ?? false) === true,
                'readback_verified_rows' => $readbackCoverage['readback_verified_rows'],
                'readback_unverified_rows' => $readbackCoverage['readback_unverified_rows'],
                'readback_status' => $readbackStatus,
                'p0_traffic_gate_status' => $trafficGateStatus,
            ],
            'next_action' => [
                'entry' => $platformReady || !$hotelIdentityReady || !$scopeIdentityReady || !$readbackReady
                    ? ''
                    : (string)($summary['action_entry'] ?? ''),
                'readback_verification_command' => !$readbackReady && $hotelIdentityReady && $scopeIdentityReady
                    && ($summary['operator_skip_active'] ?? false) !== true
                    ? (string)($p0ExecutionPlan['completion_gate']['command'] ?? '') : '',
                'missing_inputs' => $platformReady
                    ? []
                    : array_values(array_unique(array_merge(
                        $hotelIdentityReady ? [] : ['system_hotel_identity'],
                        $scopeIdentityFlags,
                        $missingInputs
                    ))),
            ],
            'forbidden_fallbacks' => [
                'zero_as_missing_data',
                'historical_date_as_target_date',
                'cross_hotel_profile_reuse',
                'cross_platform_data_substitution',
            ],
            'sensitive_values_exposed' => false,
        ];
        $row['quality_status'] = $qualityStatus;
        $row['quality_flags'] = $qualityFlags;
        $row['system_hotel_id'] = $systemHotelId;
        $row['system_hotel_name'] = $systemHotelName !== '' ? $systemHotelName : null;
        $row['expected_hotel_name'] = $expectedHotelName !== '' ? $expectedHotelName : null;
        $row['source_date_quality'] = $contract;
    }
    unset($row);

    return $sourceRows;
}
