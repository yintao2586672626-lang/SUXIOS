<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class P0FieldLoopVerifierContractTest extends TestCase
{
    public function testBroadInspectorIssuesCannotOverrideExactAuthoritativeTrafficGates(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_partition_issues_by_authoritative_gates')
            && !function_exists('p0_partition_issues_by_authoritative_gates')
        ) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_partition_issues_by_authoritative_gates');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        $readyCtrip = [[
            'platform' => 'ctrip',
            'p0_traffic_gate' => ['status' => 'ready'],
        ]];
        $broadIssues = [
            [
                'severity' => 'incomplete',
                'code' => 'live_closure_incomplete',
                'details' => ['missing_codes' => [
                    'ctrip_field_fact_closure_incomplete',
                    'ai_diagnosis_action_items_blocked',
                    'operation_execution_sample_missing',
                ]],
            ],
            ['severity' => 'incomplete', 'code' => 'runtime_field_fact_summary_ready'],
            ['severity' => 'incomplete', 'code' => 'ctrip_field_fact_closure_incomplete'],
        ];
        $ready = p0_partition_issues_by_authoritative_gates($broadIssues, $readyCtrip, ['ctrip']);
        self::assertTrue($ready['all_authoritative_gates_ready']);
        self::assertSame([], $ready['blocking_issues']);
        self::assertCount(3, $ready['reference_issues']);
        foreach ($ready['reference_issues'] as $reference) {
            self::assertSame('reference', $reference['disposition'] ?? null);
            self::assertSame('broad_source_summary', $reference['authority'] ?? null);
            self::assertSame(['ctrip:p0_traffic_gate.ready'], $reference['covered_by'] ?? null);
        }

        $incompleteCtrip = [[
            'platform' => 'ctrip',
            'p0_traffic_gate' => ['status' => 'traffic_field_fact_closure_incomplete'],
        ]];
        $notReady = p0_partition_issues_by_authoritative_gates($broadIssues, $incompleteCtrip, ['ctrip']);
        self::assertFalse($notReady['all_authoritative_gates_ready']);
        self::assertCount(3, $notReady['blocking_issues']);
        self::assertSame([], $notReady['reference_issues']);

        $hardIssues = [
            ['severity' => 'incomplete', 'code' => 'ctrip_traffic_field_fact_closure_incomplete'],
            ['severity' => 'incomplete', 'code' => 'ctrip_p0_traffic_gate_incomplete'],
            ['severity' => 'incomplete', 'code' => 'ctrip_synthetic_normalization_provenance_missing'],
            ['severity' => 'failed', 'code' => 'ctrip_raw_data_exposed'],
        ];
        $hard = p0_partition_issues_by_authoritative_gates($hardIssues, $readyCtrip, ['ctrip']);
        self::assertCount(4, $hard['blocking_issues']);
        self::assertSame([], $hard['reference_issues']);

        $mixedPlatforms = [
            ['platform' => 'ctrip', 'p0_traffic_gate' => ['status' => 'ready']],
            ['platform' => 'meituan', 'p0_traffic_gate' => ['status' => 'traffic_field_fact_closure_incomplete']],
        ];
        $mixed = p0_partition_issues_by_authoritative_gates($broadIssues, $mixedPlatforms, ['ctrip', 'meituan']);
        self::assertFalse($mixed['all_authoritative_gates_ready']);
        self::assertCount(2, $mixed['blocking_issues']);
        self::assertCount(1, $mixed['reference_issues']);
        self::assertSame('ctrip_field_fact_closure_incomplete', $mixed['reference_issues'][0]['code'] ?? null);

        $unknownLiveIssue = [[
            'severity' => 'incomplete',
            'code' => 'live_closure_incomplete',
            'details' => ['missing_codes' => ['unknown_core_gap']],
        ]];
        $unknown = p0_partition_issues_by_authoritative_gates($unknownLiveIssue, $readyCtrip, ['ctrip']);
        self::assertCount(1, $unknown['blocking_issues']);
        self::assertSame([], $unknown['reference_issues']);

        $emptyLiveIssue = [[
            'severity' => 'incomplete',
            'code' => 'live_closure_incomplete',
            'details' => ['missing_codes' => []],
        ]];
        $empty = p0_partition_issues_by_authoritative_gates($emptyLiveIssue, $readyCtrip, ['ctrip']);
        self::assertCount(1, $empty['blocking_issues']);
        self::assertSame([], $empty['reference_issues']);

        foreach ([
            'ctrip_traffic_field_fact_closure_incomplete',
            'ctrip_unknown_field_fact_integrity_gap',
            'ctrip_raw_data_field_fact_exposed',
        ] as $nestedHardCode) {
            $nestedHard = p0_partition_issues_by_authoritative_gates([[
                'severity' => 'incomplete',
                'code' => 'live_closure_incomplete',
                'details' => ['missing_codes' => [$nestedHardCode]],
            ]], $readyCtrip, ['ctrip']);
            self::assertCount(1, $nestedHard['blocking_issues'], $nestedHardCode);
            self::assertSame([], $nestedHard['reference_issues'], $nestedHardCode);
        }

        $unexpectedSeverity = p0_partition_issues_by_authoritative_gates([[
            'severity' => 'warning',
            'code' => 'ctrip_field_fact_closure_incomplete',
        ]], $readyCtrip, ['ctrip']);
        self::assertCount(1, $unexpectedSeverity['blocking_issues']);
        self::assertSame([], $unexpectedSeverity['reference_issues']);

        $externalOrInspectorFailure = [
            ['severity' => 'failed', 'code' => 'live_closure_inspector_failed'],
            ['severity' => 'incomplete', 'code' => 'external_traffic_evidence_not_valid'],
        ];
        $failures = p0_partition_issues_by_authoritative_gates(
            $externalOrInspectorFailure,
            $readyCtrip,
            ['ctrip']
        );
        self::assertCount(2, $failures['blocking_issues']);
        self::assertSame([], $failures['reference_issues']);
    }

    public function testTrafficStorageSourceSelectionUsesExactTargetDateReadbackEvidence(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_select_traffic_storage_source_candidates')
            && !function_exists('p0_select_traffic_storage_source_candidates')
        ) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_select_traffic_storage_source_candidates');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        $selected = p0_select_traffic_storage_source_candidates([
            [
                'source' => ['id' => 25, 'tenant_id' => 80, 'system_hotel_id' => 80, 'platform' => 'ctrip'],
                'latest_sync_task' => [
                    'task_id' => 3034,
                    'status' => 'success',
                    'target_date_matches_task' => true,
                ],
                'target_date_readback_traffic_rows' => 2,
            ],
            [
                'source' => ['id' => 343, 'tenant_id' => 80, 'system_hotel_id' => 80, 'platform' => 'ctrip'],
                'latest_sync_task' => ['task_id' => 0],
                'target_date_readback_traffic_rows' => 0,
            ],
        ]);

        self::assertSame('ready', $selected['status']);
        self::assertSame(25, $selected['source_id']);
        self::assertSame(3034, $selected['sync_task_id']);
        self::assertSame('target_date_readback_traffic_rows', $selected['selection_basis']);

        $reobservedHistoricalRow = p0_select_traffic_storage_source_candidates([[
            'source' => ['id' => 25],
            'latest_sync_task' => [
                'task_id' => 3084,
                'status' => 'success',
                'target_date_matches_task' => true,
            ],
            'evidence_sync_task' => [
                'task_id' => 3085,
                'status' => 'partial_success',
                'target_date_matches_task' => false,
                'row_target_date_matches' => true,
            ],
            'target_date_readback_traffic_rows' => 1,
        ]]);
        self::assertSame('ready', $reobservedHistoricalRow['status']);
        self::assertSame(3085, $reobservedHistoricalRow['sync_task_id']);
        self::assertFalse($reobservedHistoricalRow['latest_sync_task']['target_date_matches_task']);
        self::assertTrue($reobservedHistoricalRow['latest_sync_task']['row_target_date_matches']);

        $ambiguous = p0_select_traffic_storage_source_candidates([
            [
                'source' => ['id' => 25],
                'latest_sync_task' => ['task_id' => 3034, 'status' => 'success', 'target_date_matches_task' => true],
                'target_date_readback_traffic_rows' => 2,
            ],
            [
                'source' => ['id' => 343],
                'latest_sync_task' => ['task_id' => 3035, 'status' => 'success', 'target_date_matches_task' => true],
                'target_date_readback_traffic_rows' => 1,
            ],
        ]);
        self::assertSame('scope_missing', $ambiguous['status']);
        self::assertSame('traffic_data_source_ambiguous', $ambiguous['reason']);

        $emptyButExact = p0_select_traffic_storage_source_candidates([
            [
                'source' => ['id' => 68],
                'latest_sync_task' => ['task_id' => 3017, 'status' => 'partial_success', 'target_date_matches_task' => true],
                'target_date_readback_traffic_rows' => 0,
            ],
            [
                'source' => ['id' => 101],
                'latest_sync_task' => ['task_id' => 0],
                'target_date_readback_traffic_rows' => 0,
            ],
        ]);
        self::assertSame('ready', $emptyButExact['status']);
        self::assertSame(68, $emptyButExact['source_id']);
        self::assertSame('unique_target_date_sync_task', $emptyButExact['selection_basis']);

        $failedTask = p0_select_traffic_storage_source_candidates([[
            'source' => ['id' => 68],
            'latest_sync_task' => ['task_id' => 3016, 'status' => 'failed', 'target_date_matches_task' => true],
            'target_date_readback_traffic_rows' => 1,
        ]]);
        self::assertSame('scope_missing', $failedTask['status']);
        self::assertSame('sync_task_identity_missing', $failedTask['reason']);
    }

    public function testTrafficTaskSelectionIsNotShadowedByLaterOrderCompetitorOrFailedTasks(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_select_latest_eligible_sync_task_candidate')
            && !function_exists('p0_select_latest_eligible_sync_task_candidate')
        ) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_select_latest_eligible_sync_task_candidate');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        $selected = p0_select_latest_eligible_sync_task_candidate([
            ['task_id' => 3034, 'data_type' => 'traffic', 'status' => 'success', 'task_target_date' => '2026-08-09'],
            ['task_id' => 3035, 'data_type' => 'order', 'status' => 'success', 'task_target_date' => '2026-08-09'],
            ['task_id' => 3036, 'data_type' => 'competitor', 'status' => 'success', 'task_target_date' => '2026-08-09'],
            ['task_id' => 3037, 'data_type' => 'traffic', 'status' => 'failed', 'task_target_date' => '2026-08-09'],
            ['task_id' => 3038, 'data_type' => 'traffic', 'status' => 'success', 'task_target_date' => '2026-08-08'],
        ], '2026-08-09', ['traffic', 'business', 'flow', 'conversion'], ['success', 'partial_success']);

        self::assertSame(3034, $selected['task_id']);
        self::assertSame('traffic', $selected['data_type']);
    }

    public function testTrafficStorageSourceSelectionRejectsCrossScopeCandidates(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_select_traffic_storage_source_candidates')
            && !function_exists('p0_select_traffic_storage_source_candidates')
        ) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_select_traffic_storage_source_candidates');
            self::assertNotSame('', $definition);
            eval($definition);
        }
        $candidate = static fn(int $sourceId, int $tenantId, int $hotelId, string $platform, int $taskId): array => [
            'source' => [
                'id' => $sourceId,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
            ],
            'latest_sync_task' => [
                'task_id' => $taskId,
                'status' => 'success',
                'target_date_matches_task' => true,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
            ],
            'target_date_readback_traffic_rows' => 1,
        ];

        $selected = p0_select_traffic_storage_source_candidates([
            $candidate(25, 80, 80, 'ctrip', 3034),
            $candidate(26, 81, 80, 'ctrip', 3035),
            $candidate(27, 80, 81, 'ctrip', 3036),
            $candidate(28, 80, 80, 'meituan', 3037),
        ], 80, 80, 'ctrip');

        self::assertSame('ready', $selected['status']);
        self::assertSame(25, $selected['source_id']);
        self::assertSame(3034, $selected['sync_task_id']);

        $onlyWrongScope = p0_select_traffic_storage_source_candidates([
            $candidate(26, 81, 80, 'ctrip', 3035),
        ], 80, 80, 'ctrip');
        self::assertSame('scope_missing', $onlyWrongScope['status']);
        self::assertSame('sync_task_identity_missing', $onlyWrongScope['reason']);

        $receiptMismatch = $candidate(25, 80, 80, 'ctrip', 3085);
        $receiptMismatch['exact_run_readback_required'] = true;
        $receiptMismatch['exact_run_readback_ready'] = false;
        $receiptMismatch['exact_run_readback_reason'] = 'exact_run_readback_scope_mismatch';
        $blocked = p0_select_traffic_storage_source_candidates(
            [$receiptMismatch],
            80,
            80,
            'ctrip'
        );
        self::assertSame('scope_missing', $blocked['status']);
        self::assertSame('exact_run_readback_scope_mismatch', $blocked['reason']);
        self::assertSame('authoritative_rows_without_exact_run_readback', $blocked['selection_basis']);
    }

    public function testExactRunReadbackMembershipRequiresEveryRowAndReceiptIdentityField(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_validate_exact_run_readback_membership')
            && !function_exists('p0_validate_exact_run_readback_membership')
        ) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_validate_exact_run_readback_membership');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        $expected = [
            'tenant_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3085,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
        ];
        $row = static fn(int $id): array => [
            'id' => $id,
            'tenant_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3085,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'readback_verified' => 1,
        ];
        $rows = [$row(81818), $row(81819)];
        $receipt = [
            'sync_task_id' => 3085,
            'data_source_id' => 25,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'realtime_snapshot',
            'readback_verified' => true,
            'p0_status' => 'ready',
            'row_ids' => [81818, 81819, 81820],
        ];

        $ready = p0_validate_exact_run_readback_membership($receipt, $rows, $expected);
        self::assertSame('ready', $ready['status']);
        self::assertSame([], $ready['mismatch_codes']);
        self::assertSame([81818, 81819, 81820], $ready['run_readback']['row_ids']);
        self::assertFalse($ready['sensitive_values_exposed']);

        $wrongTaskReceipt = array_merge($receipt, [
            'row_ids' => [81820],
        ]);
        $blocked = p0_validate_exact_run_readback_membership($wrongTaskReceipt, $rows, $expected);
        self::assertSame('blocked', $blocked['status']);
        self::assertSame('exact_run_readback_scope_mismatch', $blocked['reason']);
        self::assertContains('authoritative_row_not_in_run_readback', $blocked['mismatch_codes']);

        foreach ([
            'sync_task_id' => [3084, 'run_readback_sync_task_mismatch'],
            'data_source_id' => [26, 'run_readback_data_source_mismatch'],
            'system_hotel_id' => [81, 'run_readback_hotel_mismatch'],
            'platform' => ['meituan', 'run_readback_platform_mismatch'],
            'target_date' => ['2026-08-08', 'run_readback_target_date_mismatch'],
            'data_period' => ['historical_daily', 'run_readback_data_period_mismatch'],
            'readback_verified' => [false, 'run_readback_not_verified'],
            'p0_status' => ['blocked', 'run_readback_p0_status_not_ready'],
        ] as $field => [$value, $code]) {
            $candidateReceipt = array_merge($receipt, [$field => $value]);
            $candidate = p0_validate_exact_run_readback_membership($candidateReceipt, $rows, $expected);
            self::assertSame('blocked', $candidate['status'], $field);
            self::assertContains($code, $candidate['mismatch_codes'], $field);
        }

        foreach ([
            'tenant_id' => [81, 'authoritative_row_tenant_mismatch'],
            'data_source_id' => [26, 'authoritative_row_data_source_mismatch'],
            'sync_task_id' => [3084, 'authoritative_row_sync_task_mismatch'],
            'system_hotel_id' => [81, 'authoritative_row_hotel_mismatch'],
            'source' => ['meituan', 'authoritative_row_platform_mismatch'],
            'data_date' => ['2026-08-08', 'authoritative_row_target_date_mismatch'],
            'data_period' => ['historical_daily', 'authoritative_row_data_period_not_exact'],
            'readback_verified' => [0, 'authoritative_row_readback_unverified'],
        ] as $field => [$value, $code]) {
            $candidateRows = $rows;
            $candidateRows[0][$field] = $value;
            $candidate = p0_validate_exact_run_readback_membership($receipt, $candidateRows, $expected);
            self::assertSame('blocked', $candidate['status'], $field);
            self::assertContains($code, $candidate['mismatch_codes'], $field);
        }

        self::assertGreaterThanOrEqual(
            3,
            substr_count($verifier, 'p0_validate_exact_run_readback_membership('),
            'The exact receipt check must run both while selecting storage scope and after reading final authoritative rows.'
        );
        self::assertStringContainsString("'status' => 'blocked'", $verifier);
        self::assertStringContainsString("'exact_run_readback_scope_mismatch'", $verifier);
        self::assertStringContainsString('count($evidenceTaskIds) === 1', $verifier);
        self::assertStringContainsString("\$status = 'exact_run_readback_scope_mismatch';", $verifier);
    }

    public function testExactRunReadbackMismatchRemainsAStableBlockingTrafficGateStatus(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_array',
            'p0_platform_traffic_gate_next_steps',
            'p0_external_evidence_db_scope',
            'p0_platform_traffic_gate',
        ] as $functionName) {
            if (!function_exists(__NAMESPACE__ . '\\' . $functionName) && !function_exists($functionName)) {
                $definition = $this->extractFunctionDefinition($verifier, $functionName);
                self::assertNotSame('', $definition, $functionName);
                eval($definition);
            }
        }

        $gate = p0_platform_traffic_gate([
            'platform' => 'ctrip',
            'status' => 'ready',
            'target_date' => ['traffic_rows' => 2],
            'traffic_field_fact_closure' => [
                'status' => 'scope_missing',
                'scope_block_reason' => 'exact_run_readback_scope_mismatch',
                'run_readback_membership_status' => 'not_loaded',
                'traffic_row_count' => 0,
            ],
            'profile_scope_traffic_closure' => ['status' => 'ready'],
            'hotel_scoped_sources' => [],
            'hotel_scoped_commands' => [],
            'hotel_scoped_capture_bridges' => [],
            'sensitive_values_exposed' => false,
        ]);

        self::assertSame('exact_run_readback_scope_mismatch', $gate['status']);
        self::assertSame('exact_run_readback_scope_mismatch', $gate['run_readback_scope_block_reason']);
        self::assertSame('not_loaded', $gate['run_readback_membership_status']);
    }

    public function testStorageSourceEvidenceExcludesCompetitorAuxiliaryForecastAndQuarantineRows(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_required_traffic_metric_keys',
            'p0_observed_traffic_metric_provenance',
            'p0_authoritative_storage_evidence_rows',
        ] as $functionName) {
            if (!function_exists(__NAMESPACE__ . '\\' . $functionName) && !function_exists($functionName)) {
                $definition = $this->extractFunctionDefinition($verifier, $functionName);
                self::assertNotSame('', $definition);
                eval($definition);
            }
        }
        $observedMetricKeys = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $canonical = [
            'data_type' => 'business',
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'data_period' => 'day',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'dimension' => 'catalog:ctrip:business_flow_transform',
            'raw_data' => json_encode([
                'row' => [
                    'endpoint_id' => 'business_flow_transform',
                    '_observed_traffic_metric_keys' => $observedMetricKeys,
                ],
            ], JSON_UNESCAPED_SLASHES),
        ];

        $rows = p0_authoritative_storage_evidence_rows([
            $canonical,
            array_merge($canonical, ['compare_type' => 'competitor']),
            array_merge($canonical, ['dimension' => 'catalog:qunar:business_flow_transform']),
            array_merge($canonical, ['dimension' => 'catalog:ctrip:auxiliary_endpoint']),
            array_merge($canonical, ['data_period' => 'forecast']),
            array_merge($canonical, ['validation_status' => 'quarantined']),
            array_merge($canonical, ['readback_verified' => 0]),
        ], 'ctrip');

        self::assertCount(1, $rows);
        self::assertSame($canonical, $rows[0]);

        $meituanBusiness = array_merge($canonical, [
            'platform' => 'meituan',
            'dimension' => 'flow_conversion',
        ]);
        self::assertSame([], p0_authoritative_storage_evidence_rows([$meituanBusiness], 'meituan'));
    }

    public function testStoredTrafficVerifierExcludesQuarantinedValidationRows(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('OnlineDataTrustStatusService::blockingValidationStatuses()', $verifier);
        self::assertStringContainsString('LOWER(TRIM(`validation_status`)) NOT IN', $verifier);
    }

    public function testStoredTrafficVerifierSeparatesExplicitZeroFromMissingOrDefaultZero(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString(
            'function p0_has_explicit_zero_required_traffic_confirmation',
            $verifier
        );
        self::assertStringContainsString("array_key_exists(\$sourceKey, \$sourceRow)", $verifier);
        self::assertStringContainsString("'explicit_zero_confirmed_rows' => 0", $verifier);
        self::assertStringContainsString("'zero_value_unconfirmed_rows' => 0", $verifier);
        self::assertStringContainsString("&& (int)\$base['zero_value_unconfirmed_rows'] === 0", $verifier);
        self::assertStringContainsString('default_data_date', $verifier);
        self::assertStringContainsString('p0_field_fact_capture_evidence_matches_row', $verifier);
        self::assertStringContainsString('p0_observed_traffic_metric_provenance($raw, $platform)', $verifier);
        self::assertStringContainsString('synthetic_normalization_provenance_missing', $verifier);
        self::assertStringContainsString("raw_data.row._observed_traffic_metric_keys", $verifier);
    }

    public function testObservedTrafficMarkerRequiresSnakeCaseMembershipForEveryPlatformMetric(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach (['p0_required_traffic_metric_keys', 'p0_observed_traffic_metric_provenance'] as $functionName) {
            if (!function_exists($functionName)) {
                $definition = $this->extractFunctionDefinition($verifier, $functionName);
                self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
                eval($definition);
            }
        }

        $required = p0_required_traffic_metric_keys('ctrip');
        $ready = p0_observed_traffic_metric_provenance([
            'row' => ['_observed_traffic_metric_keys' => array_reverse($required)],
        ], 'ctrip');
        self::assertSame('ready', $ready['status']);
        self::assertSame([], $ready['missing_metric_keys']);

        $missing = p0_observed_traffic_metric_provenance([
            'row' => ['_observed_traffic_metric_keys' => array_values(array_diff($required, ['flow_rate']))],
        ], 'ctrip');
        self::assertSame('synthetic_normalization_provenance_missing', $missing['status']);
        self::assertSame(['flow_rate'], $missing['missing_metric_keys']);

        $camelCase = p0_observed_traffic_metric_provenance([
            'row' => ['_observed_traffic_metric_keys' => ['listExposure']],
        ], 'ctrip');
        self::assertSame('synthetic_normalization_provenance_missing', $camelCase['status']);

        $topLevelOnly = p0_observed_traffic_metric_provenance([
            '_observed_traffic_metric_keys' => $required,
        ], 'ctrip');
        self::assertSame('synthetic_normalization_provenance_missing', $topLevelOnly['status']);
    }

    public function testStoredValueReadinessOnlyRequiresCompleteFactsToHaveStoredValues(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $liveActionQueueVerifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_phase1_live_action_queue_runtime.mjs');

        self::assertStringContainsString('(int)($facts[\'stored_value_present_count\'] ?? 0) < $completeCount', $p0Verifier);
        self::assertStringContainsString('$storedValuePresentCount >= $completeFactCount', $p0Verifier);
        self::assertStringContainsString('Number(fieldFacts.stored_value_present_count ?? 0) >= Number(fieldFacts.complete_fact_count ?? 0)', $liveActionQueueVerifier);

        self::assertStringNotContainsString("|| (int)(\$facts['stored_value_missing_count'] ?? 0) !== 0", $p0Verifier);
        self::assertStringNotContainsString('$storedValuePresentCount >= $completeFactCount && $storedValueMissingCount === 0', $p0Verifier);
        self::assertStringNotContainsString('&& Number(fieldFacts.stored_value_missing_count ?? -1) === 0', $liveActionQueueVerifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelTargetDate(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$rowDate = trim((string)($row[\'target_date\'] ?? \'\'));', $p0Verifier);
        self::assertStringContainsString('target_date_missing', $p0Verifier);
        self::assertStringNotContainsString('$rowDate = trim((string)($row[\'target_date\'] ?? $scope[\'date\'] ?? \'\'));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelSystemHotelIdWhenScoped(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$rowSystemHotelId = (int)($row[\'system_hotel_id\'] ?? 0);', $p0Verifier);
        self::assertStringContainsString('system_hotel_id_missing', $p0Verifier);
        self::assertStringNotContainsString('$rowSystemHotelId = (int)($row[\'system_hotel_id\'] ?? $scope[\'system_hotel_id\'] ?? $data[\'system_hotel_id\'] ?? 0);', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelPlatform(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$platform = strtolower(trim((string)($row[\'platform\'] ?? \'\')));', $p0Verifier);
        self::assertStringContainsString('platform_missing', $p0Verifier);
        self::assertStringNotContainsString('$platform = strtolower(trim((string)($row[\'platform\'] ?? $scope[\'platform\'] ?? \'\')));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceCollectorDoesNotSynthesizeRowPlatformFromContainerKey(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringNotContainsString('if ($platformHint !== null && !isset($value[\'platform\'])) {', $p0Verifier);
        self::assertStringNotContainsString('$value[\'platform\'] = $platformHint;', $p0Verifier);
    }

    public function testExternalTrafficEvidenceRequiresRowLevelScopePolicy(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$scopePolicy = trim((string)($row[\'scope_policy\'] ?? $row[\'source_scope\'] ?? \'\'));', $p0Verifier);
        self::assertStringContainsString('scope_policy_missing', $p0Verifier);
        self::assertStringNotContainsString('$scopePolicy = trim((string)($row[\'scope_policy\'] ?? $row[\'source_scope\'] ?? $scope[\'source_scope\'] ?? $scope[\'scope_policy\'] ?? \'\'));', $p0Verifier);
    }

    public function testExternalTrafficEvidenceUnknownRowsMakeOverallEvidenceInvalid(): void
    {
        $p0Verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('$base[\'status\'] = $unknownIssues !== [] ? \'invalid\' : (', $p0Verifier);
        self::assertStringNotContainsString('$base[\'status\'] = $validPlatforms === count($platforms) && $unknownIssues === [] ? \'valid\' : ($validPlatforms > 0 ? \'partial\' : \'invalid\');', $p0Verifier);
    }

    public function testExternalTrafficEvidenceUnknownRowsKeepSensitiveFlagAndRowIssues(): void
    {
        $evidencePath = tempnam(sys_get_temp_dir(), 'p0_external_evidence_');
        self::assertIsString($evidencePath);

        $payload = [
            'traffic_evidence' => [[
                'target_date' => '2026-06-25',
                'system_hotel_id' => 7,
                'scope_policy' => 'ota_channel_only',
                'sensitive_values_exposed' => false,
                'capture_evidence' => [
                    'source_trace_id' => 'trace-unknown-platform',
                    'source_url_hash' => 'hash-unknown-platform',
                ],
                'debug_url' => 'https://example.invalid/raw-sensitive-url',
                'field_facts' => [],
                'ui_status' => [
                    'field_fact_status' => 'ready',
                    'raw_data_exposed' => false,
                    'missing_count' => 0,
                    'stored_value_missing_count' => 0,
                ],
                'traffic_closure_chain' => [],
                'traffic_closure_chain_policy' => 'pre-import source proof only; P0 remains incomplete until target-date rows are ingested',
            ]],
        ];

        file_put_contents($evidencePath, json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($script)
                . ' --date=2026-06-25 --platform=ctrip --system-hotel-id=7 '
                . escapeshellarg('--traffic-evidence=' . $evidencePath)
                . ' 2>&1';
            exec($command, $output, $exitCode);

            self::assertSame(2, $exitCode, implode("\n", $output));
            $decoded = json_decode(implode("\n", $output), true);
            self::assertIsArray($decoded);

            $external = $decoded['external_traffic_evidence'] ?? null;
            self::assertIsArray($external);
            self::assertSame('invalid', $external['status'] ?? null);
            self::assertTrue($external['sensitive_values_exposed'] ?? null);

            $issues = $external['issues'] ?? [];
            self::assertIsArray($issues);
            self::assertSame('traffic_evidence_platform_not_selected', $issues[0]['code'] ?? null);
            self::assertIsArray($issues[0]['row_issues'] ?? null);

            $rowIssueCodes = array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                $issues[0]['row_issues']
            );
            self::assertContains('platform_missing', $rowIssueCodes);
            self::assertContains('sensitive_material_present', $rowIssueCodes);
        } finally {
            if (is_file($evidencePath)) {
                unlink($evidencePath);
            }
        }
    }

    public function testExternalTrafficEvidenceMalformedRowsAreNotSilentlyIgnored(): void
    {
        $evidencePath = tempnam(sys_get_temp_dir(), 'p0_external_evidence_');
        self::assertIsString($evidencePath);

        $payload = [
            'traffic_evidence' => [[
                'platform' => 'ctrip',
                'target_date' => '2026-06-25',
                'system_hotel_id' => 7,
                'scope_policy' => 'ota_channel_only',
                'sensitive_values_exposed' => false,
                'debug_url' => 'https://example.invalid/raw-sensitive-url',
            ]],
        ];

        file_put_contents($evidencePath, json_encode($payload, JSON_UNESCAPED_SLASHES));

        try {
            $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($script)
                . ' --date=2026-06-25 --platform=ctrip --system-hotel-id=7 '
                . escapeshellarg('--traffic-evidence=' . $evidencePath)
                . ' 2>&1';
            exec($command, $output, $exitCode);

            self::assertSame(2, $exitCode, implode("\n", $output));
            $decoded = json_decode(implode("\n", $output), true);
            self::assertIsArray($decoded);

            $external = $decoded['external_traffic_evidence'] ?? null;
            self::assertIsArray($external);
            self::assertSame('invalid', $external['status'] ?? null);
            self::assertTrue($external['sensitive_values_exposed'] ?? null);

            $platform = $external['platforms']['ctrip'] ?? null;
            self::assertIsArray($platform);
            self::assertSame(1, $platform['evidence_rows'] ?? null);
            self::assertSame('invalid', $platform['status'] ?? null);

            $rowIssueCodes = array_map(
                static fn(array $issue): string => (string)($issue['code'] ?? ''),
                $platform['issues'] ?? []
            );
            self::assertContains('field_facts_missing', $rowIssueCodes);
            self::assertContains('source_trace_id_missing', $rowIssueCodes);
            self::assertContains('sensitive_material_present', $rowIssueCodes);
        } finally {
            if (is_file($evidencePath)) {
                unlink($evidencePath);
            }
        }
    }

    public function testHotelScopedNextStepsProjectSafeCredentialMetadataFailureCodes(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $start = strpos($verifier, 'function p0_platform_traffic_gate_next_steps');
        $end = strpos($verifier, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($verifier, $start, $end - $start);

        self::assertStringContainsString("'credential_metadata_status' =>", $method);
        self::assertStringContainsString("'credential_metadata_reason' =>", $method);
        self::assertStringNotContainsString("'credential_ref' =>", $method);
        self::assertStringNotContainsString("'credential_status' =>", $method);
    }

    public function testBrowserProfileCredentialResolutionKeepsScopeChecksButDoesNotRequireVault(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $start = strpos($verifier, 'function p0_resolve_source_credential_metadata');
        $end = strpos($verifier, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($verifier, $start, $end - $start);

        self::assertStringContainsString('p0_is_browser_profile_ingestion_method', $method);
        self::assertStringContainsString("'reason' => 'data_source_tenant_scope_mismatch'", $method);
        self::assertStringContainsString("'reason' => 'source_config_projection_conflict'", $method);
        self::assertStringContainsString("'status' => 'not_required'", $method);
        self::assertStringContainsString("'reason' => 'browser_profile_vault_not_required'", $method);

        $tenantCheck = strpos($method, "'reason' => 'data_source_tenant_scope_mismatch'");
        $identityCheck = strpos($method, "'reason' => 'source_config_projection_conflict'");
        $profileReturn = strpos($method, "'reason' => 'browser_profile_vault_not_required'");
        self::assertIsInt($tenantCheck);
        self::assertIsInt($identityCheck);
        self::assertIsInt($profileReturn);
        self::assertLessThan($profileReturn, $tenantCheck);
        self::assertLessThan($profileReturn, $identityCheck);
    }

    public function testBrowserProfileSourceReadinessRequiresProfileDirectoryLoginAndPlatformIdentity(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString("in_array(\$method, ['browser_profile', 'profile_browser'], true)", $verifier);
        self::assertStringContainsString('p0_traffic_profile_dir_present($platform, $config)', $verifier);
        self::assertStringContainsString("\$platform === 'meituan'", $verifier);
        self::assertStringContainsString("['store_id', 'storeId', 'profile_id', 'profileId']", $verifier);
        self::assertStringContainsString("return 'profile_not_prepared';", $verifier);
        self::assertStringContainsString("return 'platform_hotel_identifier_missing';", $verifier);
        self::assertStringContainsString("'traffic_profile_dir_present_count' =>", $verifier);
        self::assertStringContainsString("'traffic_profile_platform_hotel_identifier_count' =>", $verifier);
    }

    public function testBrowserProfileClosureDoesNotInheritManualCredentialRequirements(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $pathStart = strpos($verifier, 'function p0_traffic_closure_path_options');
        $pathEnd = strpos($verifier, "\n/**", is_int($pathStart) ? $pathStart : 0);
        $availabilityStart = strpos($verifier, 'function p0_traffic_availability_status');
        $availabilityEnd = strpos($verifier, "\n/**", is_int($availabilityStart) ? $availabilityStart : 0);

        self::assertIsInt($pathStart);
        self::assertIsInt($pathEnd);
        self::assertIsInt($availabilityStart);
        self::assertIsInt($availabilityEnd);

        $pathMethod = substr($verifier, $pathStart, $pathEnd - $pathStart);
        $availabilityMethod = substr($verifier, $availabilityStart, $availabilityEnd - $availabilityStart);
        self::assertStringContainsString('$profilePreparationMissing = [];', $pathMethod);
        self::assertStringNotContainsString('$profileMissing = array_values(array_filter(', $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ota_credential_metadata_blocked';", $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ota_credential_metadata_migration_required';", $pathMethod);
        self::assertStringContainsString("\$manualMissing[] = 'ready_ota_credential_metadata';", $pathMethod);

        $profileMissingStart = strpos($pathMethod, '$profilePreparationMissing = [];');
        $profileMissingEnd = strpos($pathMethod, '$evidenceMissing = [];');
        self::assertIsInt($profileMissingStart);
        self::assertIsInt($profileMissingEnd);
        $profileMissingBlock = substr($pathMethod, $profileMissingStart, $profileMissingEnd - $profileMissingStart);
        self::assertStringNotContainsString('ota_credential_metadata_', $profileMissingBlock);
        self::assertStringNotContainsString('ready_ota_credential_metadata', $profileMissingBlock);
        self::assertStringContainsString("'traffic_browser_profile_count'", $availabilityMethod);
        self::assertStringContainsString("'traffic_profile_dir_present_count'", $availabilityMethod);

        $profileBranch = strpos($availabilityMethod, "'traffic_browser_profile_count'");
        $credentialBranch = strpos($availabilityMethod, "'credential_metadata_status'");
        self::assertIsInt($profileBranch);
        self::assertIsInt($credentialBranch);
        self::assertLessThan($credentialBranch, $profileBranch);
    }

    public function testLiveInspectorLatestAvailableExcludesDatesAfterCurrentShanghaiDate(): void
    {
        $inspector = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php');
        $start = strpos($inspector, 'function query_latest_available_source_rows');
        $end = strpos($inspector, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($inspector, $start, $end - $start);

        self::assertStringContainsString("new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))", $method);
        self::assertStringContainsString("->where('data_date', '<=', \$currentDate)", $method);

        $builder = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'build_phase1_ota_live_closure_evidence.php');
        $builderStart = strpos($builder, 'function query_latest_available_source_rows');
        $builderEnd = strpos($builder, "\nfunction ", is_int($builderStart) ? $builderStart + 1 : 0);

        self::assertIsInt($builderStart);
        self::assertIsInt($builderEnd);
        $builderMethod = substr($builder, $builderStart, $builderEnd - $builderStart);

        self::assertStringContainsString("new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))", $builderMethod);
        self::assertStringContainsString("->where('data_date', '<=', \$currentDate)", $builderMethod);
    }

    public function testBrowserProfileReadinessRequiresCurrentSessionProofOnTheSameSource(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString('function p0_traffic_current_session_verified', $verifier);
        self::assertStringContainsString('function p0_traffic_current_session_status', $verifier);
        self::assertStringContainsString("'current_session_blocked_probe'", $verifier);
        self::assertStringContainsString("'login_required'", $verifier);
        self::assertStringContainsString("'current_session_probe_performed'", $verifier);
        self::assertStringContainsString("'current_session_verified'", $verifier);
        self::assertStringContainsString("'historical_login_metadata_present'", $verifier);
        self::assertStringContainsString('$profilePreparedForProbe = $isBrowserProfileSource', $verifier);
        self::assertStringContainsString('&& $profileDirPresent', $verifier);
        self::assertStringContainsString('&& $platformHotelIdentifierPresent', $verifier);
        self::assertStringContainsString('$credentialMetadataAllowsActions = $credentialReady || $credentialNotRequired;', $verifier);
        self::assertStringContainsString('&& $credentialMetadataAllowsActions', $verifier);
        self::assertStringContainsString('$profileFlowReady = $profilePreparedForProbe && $currentSessionVerified;', $verifier);
        self::assertStringContainsString("'traffic_profile_flow_ready_count' =>", $verifier);

        $pathStart = strpos($verifier, 'function p0_traffic_closure_path_options');
        $pathEnd = strpos($verifier, "\n/**", is_int($pathStart) ? $pathStart : 0);
        self::assertIsInt($pathStart);
        self::assertIsInt($pathEnd);
        $pathMethod = substr($verifier, $pathStart, $pathEnd - $pathStart);
        self::assertStringContainsString("(int)(\$sources['traffic_profile_flow_ready_count'] ?? 0)", $pathMethod);
        self::assertStringContainsString("(\$profileFlowReady ? 'ready_for_sync' : 'ready_for_session_probe')", $pathMethod);
        $profileBranchEnd = strpos($pathMethod, "'mode' => 'manual_cookie_api'");
        self::assertIsInt($profileBranchEnd);
        self::assertStringNotContainsString('ready_to_attempt', substr($pathMethod, 0, $profileBranchEnd));
    }

    public function testProfileBindingOwnershipIsAuthoritativeAndNeverExposesTheProfileKeyHash(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        foreach ([$verifier, $register] as $script) {
            self::assertStringContainsString('ota_profile_bindings', $script);
            self::assertStringContainsString('profile_binding_table_missing', $script);
            self::assertStringContainsString('profile_binding_missing', $script);
            self::assertStringContainsString('profile_binding_scope_mismatch', $script);
            self::assertStringContainsString('profile_scope_conflict_across_hotel_or_tenant', $script);
            self::assertStringContainsString("'binding_status', 'active'", $script);
        }

        $rowStart = strpos($verifier, '$trafficSourceRow = [');
        $rowEnd = strpos($verifier, '];', is_int($rowStart) ? $rowStart : 0);
        self::assertIsInt($rowStart);
        self::assertIsInt($rowEnd);
        self::assertStringNotContainsString("'profile_key_hash' =>", substr($verifier, $rowStart, $rowEnd - $rowStart));
    }

    public function testProfileKeyCanonicalizationUsesTheRuntimeProfilePathContractEverywhere(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        $verifierHashStart = strpos($verifier, 'function p0_profile_key_hash');
        $verifierHashEnd = strpos($verifier, "\n}", is_int($verifierHashStart) ? $verifierHashStart : 0);
        self::assertIsInt($verifierHashStart);
        self::assertIsInt($verifierHashEnd);
        $verifierHash = substr($verifier, $verifierHashStart, $verifierHashEnd - $verifierHashStart);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::safeFilePart($profileKey)', $verifierHash);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_.-]+'", $verifierHash);

        $profileDirStart = strpos($register, 'function browser_profile_dir_present');
        $profileDirEnd = strpos($register, "\n}", is_int($profileDirStart) ? $profileDirStart : 0);
        self::assertIsInt($profileDirStart);
        self::assertIsInt($profileDirEnd);
        $profileDir = substr($register, $profileDirStart, $profileDirEnd - $profileDirStart);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::safeFilePart($profileKey)', $profileDir);
        self::assertStringNotContainsString("preg_replace('/[^a-zA-Z0-9_.-]+'", $profileDir);
    }

    public function testP0RegistrationUsesStrictShanghaiDateAndExcludesForecastOnlyHotels(): void
    {
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');

        self::assertStringContainsString("DateTimeImmutable::createFromFormat('!Y-m-d'", $register);
        self::assertStringContainsString('Invalid --date, expected a real calendar date in YYYY-MM-DD.', $register);
        self::assertStringContainsString('--date must not be later than the current Asia/Shanghai date.', $register);
        self::assertStringContainsString('set_exception_handler(', $register);
        self::assertStringContainsString('exit(2);', $register);
        self::assertStringContainsString("->whereNotIn('data_type', ['traffic_forecast'])", $register);
    }

    public function testP0RegistrationTreatsHistoricalProfileMetadataAsProbePreparationOnly(): void
    {
        $register = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php');
        $start = strpos($register, 'function build_source_spec');
        $end = strpos($register, "\n/**", is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($register, $start, $end - $start);
        self::assertStringContainsString("'manual_login_state_verified' => false", $method);
        self::assertStringContainsString("'current_session_probe_performed' => false", $method);
        self::assertStringContainsString("'current_session_verified' => false", $method);
        self::assertStringContainsString("'session_probe_status' => 'ready_for_session_probe'", $method);
        self::assertStringContainsString("'login_evidence_scope' => 'historical_metadata_only'", $method);
        self::assertStringNotContainsString('apply_profile_login_inheritance(', $method);
    }

    public function testP0VerifierRejectsAnEmptyNormalizedPlatformSelection(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-07-10 ' . escapeshellarg('--platform=,')
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        self::assertIsArray($decoded);
        self::assertSame('failed', $decoded['status'] ?? null);
        self::assertSame('p0_field_loop_verifier_runtime_error', $decoded['issues'][0]['code'] ?? null);
        self::assertStringContainsString('at least one', (string)($decoded['issues'][0]['message'] ?? ''));
    }

    public function testP0RegistrationRejectsAnEmptyNormalizedPlatformSelection(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'register_p0_ota_traffic_data_sources.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-07-10 ' . escapeshellarg('--platform=,')
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(2, $exitCode, implode("\n", $output));
        self::assertStringContainsString('at least one', implode("\n", $output));
        self::assertStringNotContainsString('"status": "ready"', implode("\n", $output));
    }

    public function testP0VerifierRejectsInvalidCalendarDates(): void
    {
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --date=2026-02-31 --platform=ctrip 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(1, $exitCode, implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        self::assertIsArray($decoded);
        self::assertSame('failed', $decoded['status'] ?? null);
        self::assertStringContainsString('real calendar date', (string)($decoded['issues'][0]['message'] ?? ''));
    }

    public function testStoredTrafficClosureIsPerHotelAllRowsAndExcludesForecastPeriods(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');

        self::assertStringContainsString("isset(\$columns['data_period'])", $verifier);
        self::assertStringContainsString("->whereOr('data_period', 'not in', ['next_7_days', 'next_30_days', 'forecast', 'future_forecast'])", $verifier);
        self::assertStringContainsString("(int)\$base['ui_status_incomplete_rows'] === 0", $verifier);
        self::assertStringContainsString("'auxiliary_traffic_row_count' => 0", $verifier);
        self::assertStringContainsString('every authoritative target-date traffic snapshot row', $verifier);
        self::assertStringContainsString("'hotel_scoped_field_fact_closures' => []", $verifier);
        self::assertStringContainsString("'hotel_scoped_closure_status'", $verifier);
        self::assertStringContainsString("p0_traffic_field_fact_closure(\$platform, \$targetDate, \$hotelId)", $verifier);
        self::assertStringContainsString("\$base['status'] = 'hotel_scoped_incomplete';", $verifier);
    }

    public function testCtripTrafficClosureSeparatesCanonicalSnapshotsFromAuxiliaryEndpoints(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_traffic_row_endpoint_id',
            'p0_traffic_row_date_scope_is_authoritative',
            'p0_traffic_row_scope',
        ] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName) || function_exists($functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
            eval($definition);
        }

        $canonical = p0_traffic_row_scope([
            'dimension' => 'catalog:business_overview:business_flow_transform:list_exposure+detail_exposure:0',
        ], 'ctrip');
        self::assertTrue($canonical['authoritative']);
        self::assertSame('business_flow_transform', $canonical['endpoint_id']);

        $matchingNestedCanonical = p0_traffic_row_scope([
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'raw_data' => json_encode([
                'row' => ['endpoint_id' => 'traffic_flow_transform'],
            ], JSON_UNESCAPED_SLASHES),
        ], 'ctrip');
        self::assertTrue($matchingNestedCanonical['authoritative']);
        self::assertSame('traffic_flow_transform', $matchingNestedCanonical['endpoint_id']);

        foreach (['traffic_hotel_seq', 'traffic_flow_source'] as $auxiliaryEndpoint) {
            $nestedAuxiliary = p0_traffic_row_scope([
                'dimension' => '',
                'raw_data' => json_encode([
                    'row' => ['endpoint_id' => $auxiliaryEndpoint],
                ], JSON_UNESCAPED_SLASHES),
            ], 'ctrip');
            self::assertFalse($nestedAuxiliary['authoritative']);
            self::assertSame($auxiliaryEndpoint, $nestedAuxiliary['endpoint_id']);
        }

        foreach ([
            ['row' => ['capture' => ['endpoint_id' => 'traffic_hotel_seq']]],
            ['source_row' => ['endpoint_id' => 'traffic_flow_source']],
            ['source_row' => ['capture' => ['endpointId' => 'traffic_hotel_seq']]],
        ] as $nestedAuxiliaryRaw) {
            $nestedAuxiliary = p0_traffic_row_scope([
                'dimension' => '',
                'raw_data' => json_encode($nestedAuxiliaryRaw, JSON_UNESCAPED_SLASHES),
            ], 'ctrip');
            self::assertFalse($nestedAuxiliary['authoritative']);
        }

        foreach ([
            [
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
                'raw_data' => json_encode([
                    'row' => ['endpoint_id' => 'traffic_hotel_seq'],
                ], JSON_UNESCAPED_SLASHES),
            ],
            [
                'dimension' => '',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_flow_transform',
                    'row' => ['endpoint_id' => 'traffic_flow_source'],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ] as $conflictingEndpointRow) {
            $conflict = p0_traffic_row_scope($conflictingEndpointRow, 'ctrip');
            self::assertFalse($conflict['authoritative']);
            self::assertSame('__endpoint_conflict__', $conflict['endpoint_id']);
            self::assertSame('ctrip_traffic_endpoint_conflict', $conflict['reason']);
        }

        $futureSearch = p0_traffic_row_scope([
            'dimension' => 'catalog:traffic_report:traffic_search_details:future_search:2026-07-25',
        ], 'ctrip');
        self::assertFalse($futureSearch['authoritative']);
        self::assertSame('traffic_search_details', $futureSearch['endpoint_id']);

        $weekly = p0_traffic_row_scope([
            'raw_data' => json_encode(['endpoint_id' => 'weekly_report'], JSON_UNESCAPED_SLASHES),
        ], 'ctrip');
        self::assertFalse($weekly['authoritative']);
        self::assertSame('weekly_report', $weekly['endpoint_id']);

        $legacy = p0_traffic_row_scope(['dimension' => ''], 'ctrip');
        self::assertTrue($legacy['authoritative']);
        self::assertSame('legacy_dimensionless_core_snapshot', $legacy['reason']);

        $unclassifiedDimensioned = p0_traffic_row_scope([
            'dimension' => 'realtime:ctrip',
        ], 'ctrip');
        self::assertFalse($unclassifiedDimensioned['authoritative']);
        self::assertSame(
            'ctrip_unclassified_dimensioned_traffic_row',
            $unclassifiedDimensioned['reason']
        );

        $meituan = p0_traffic_row_scope(['dimension' => 'flow_conversion'], 'meituan');
        self::assertTrue($meituan['authoritative']);

        $meituanRefreshTimestamp = p0_traffic_row_scope([
            'dimension' => 'flow_conversion',
            'raw_data' => json_encode(['date_source' => 'response.rtDataUpdateTime']),
        ], 'meituan');
        self::assertFalse($meituanRefreshTimestamp['authoritative']);
        self::assertSame(
            'meituan_refresh_timestamp_not_business_date_evidence',
            $meituanRefreshTimestamp['reason']
        );
    }

    public function testCtripCatalogProjectionIsReferenceOnlyAfterExactRawTupleProof(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_required_traffic_metric_keys',
            'p0_observed_traffic_metric_provenance',
            'p0_required_traffic_metric_values',
            'p0_traffic_row_endpoint_id',
            'p0_row_raw_data',
            'p0_ctrip_query_flow_row_proof_ready',
            'p0_ctrip_query_flow_metric_signature',
            'p0_reduce_ctrip_query_flow_projection_rows',
        ] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName) || function_exists($functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
            eval($definition);
        }

        $metricKeys = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $row = static function (bool $observed, string $dimension, float $listExposure = 510.0, string $hashSeed = 'a') use ($metricKeys): array {
            $sourceHash = str_repeat($hashSeed, 64);
            return [
                'sync_task_id' => 3085,
                'system_hotel_id' => 80,
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'data_date' => '2026-08-08',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'dimension' => $dimension,
                'list_exposure' => $listExposure,
                'detail_exposure' => 96,
                'flow_rate' => 18.82,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'raw_data' => json_encode([
                    'sync_task_id' => 3085,
                    'date_source' => $dimension === '' ? 'row' : 'request.payload.startDate',
                    'source_url_hash' => $sourceHash,
                    'capture_evidence' => [
                        'source_url_hash' => $sourceHash,
                        'response_evidence_type' => 'structured_json',
                    ],
                    'row' => array_filter([
                        'date' => '2026-08-08',
                        'endpoint_id' => $dimension === '' ? 'business_flow_transform' : 'traffic_flow_transform',
                        '_observed_traffic_metric_keys' => $observed ? $metricKeys : null,
                    ], static fn(mixed $value): bool => $value !== null),
                ], JSON_UNESCAPED_SLASHES),
            ];
        };

        $canonical = $row(true, '', 510.0, 'a');
        $matchingProjection = $row(false, 'catalog:traffic_report:traffic_flow_transform:list_exposure', 510.0, 'b');
        $ready = p0_reduce_ctrip_query_flow_projection_rows(
            [$canonical, $matchingProjection],
            '2026-08-08',
            '2026-08-09'
        );
        self::assertCount(1, $ready['authoritative_rows']);
        self::assertCount(1, $ready['reference_rows']);
        self::assertSame(0, $ready['unresolved_projection_rows']);

        $markerCopiedProjection = $matchingProjection;
        $markerCopiedRaw = json_decode(
            (string)$markerCopiedProjection['raw_data'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $markerCopiedRaw['row']['_observed_traffic_metric_keys'] = $metricKeys;
        $markerCopiedProjection['raw_data'] = json_encode(
            $markerCopiedRaw,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $copiedMarker = p0_reduce_ctrip_query_flow_projection_rows(
            [$canonical, $markerCopiedProjection],
            '2026-08-08',
            '2026-08-09'
        );
        self::assertCount(1, $copiedMarker['authoritative_rows']);
        self::assertCount(1, $copiedMarker['reference_rows']);

        $copiedMarkerOnly = p0_reduce_ctrip_query_flow_projection_rows(
            [$markerCopiedProjection],
            '2026-08-08',
            '2026-08-09'
        );
        self::assertCount(1, $copiedMarkerOnly['authoritative_rows']);
        self::assertSame(1, $copiedMarkerOnly['unresolved_projection_rows']);

        $catalogOnly = p0_reduce_ctrip_query_flow_projection_rows(
            [$matchingProjection],
            '2026-08-08',
            '2026-08-09'
        );
        self::assertCount(1, $catalogOnly['authoritative_rows']);
        self::assertSame(1, $catalogOnly['unresolved_projection_rows']);

        $mismatch = p0_reduce_ctrip_query_flow_projection_rows(
            [$canonical, $row(false, 'catalog:traffic_report:traffic_flow_transform:list_exposure', 509.0, 'b')],
            '2026-08-08',
            '2026-08-09'
        );
        self::assertCount(2, $mismatch['authoritative_rows']);
        self::assertCount(0, $mismatch['reference_rows']);
        self::assertSame(1, $mismatch['unresolved_projection_rows']);

        $notFinal = $canonical;
        $notFinal['data_period'] = 'realtime_snapshot';
        $notFinal['is_final'] = 0;
        self::assertFalse(p0_ctrip_query_flow_row_proof_ready(
            $notFinal,
            '2026-08-08',
            '2026-08-09'
        ));
    }

    public function testStoredTrafficIdentifierMatchesTheAuthoritativeProfileSourceWithoutRawOutput(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $rawIdentifier = 'raw-ctrip-hotel-7001';
        $authority = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 91,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => ['hotelId' => $rawIdentifier],
        ]]);
        $comparison = p0_compare_row_platform_hotel_identifier(
            ['capture' => ['hotel_id' => $rawIdentifier]],
            'ctrip',
            $authority
        );

        self::assertSame('ready', $authority['status'] ?? null);
        self::assertSame('matched', $comparison['status'] ?? null);
        self::assertTrue($comparison['matched'] ?? false);
        self::assertSame(1, $comparison['row_identifier_count'] ?? null);
        self::assertNotSame('', $comparison['expected_identifier_hash'] ?? '');
        self::assertSame($comparison['expected_identifier_hash'] ?? null, $comparison['row_identifier_hash'] ?? null);
        self::assertStringNotContainsString($rawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficIdentifierMismatchFailsClosedWithoutRawOutput(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $expectedRawIdentifier = 'raw-meituan-poi-7001';
        $storedRawIdentifier = 'raw-meituan-poi-7002';
        $authority = p0_authoritative_profile_identifier_resolution('meituan', 7, 70, [[
            'id' => 92,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'profile_browser',
            'status' => 'success',
            'enabled' => 1,
            'profile_binding_status' => 'ready',
            'config' => ['poiId' => $expectedRawIdentifier],
        ]]);
        $comparison = p0_compare_row_platform_hotel_identifier(
            ['source_row' => ['poi_id' => $storedRawIdentifier]],
            'meituan',
            $authority
        );

        self::assertSame('mismatch', $comparison['status'] ?? null);
        self::assertSame('platform_hotel_identifier_mismatch', $comparison['reason'] ?? null);
        self::assertFalse($comparison['matched'] ?? true);
        self::assertStringNotContainsString($expectedRawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString($storedRawIdentifier, json_encode([$authority, $comparison], JSON_UNESCAPED_SLASHES));
    }

    public function testCanonicalHotelIdentifierTakesPriorityOverAComplementaryNodeIdentifier(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $authority = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 95,
            'tenant_id' => 70,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => [
                'hotel_id' => 'raw-canonical-hotel',
                'node_id' => 'raw-complementary-node',
            ],
        ]]);

        self::assertSame('ready', $authority['status'] ?? null);
        self::assertSame(1, $authority['identifier_count'] ?? null);
        self::assertStringNotContainsString('raw-canonical-hotel', json_encode($authority, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('raw-complementary-node', json_encode($authority, JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficIdentifierRejectsMissingOrAmbiguousAuthoritativeProfileSources(): void
    {
        $this->loadPlatformIdentifierHelpers();

        $missing = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, []);
        self::assertSame('missing', $missing['status'] ?? null);
        self::assertSame('authoritative_profile_source_missing', $missing['reason'] ?? null);

        $wrongTenant = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [[
            'id' => 90,
            'tenant_id' => 71,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => true,
            'profile_binding_status' => 'ready',
            'config' => ['hotel_id' => 'raw-cross-tenant-hotel'],
        ]]);
        self::assertSame('blocked', $wrongTenant['status'] ?? null);
        self::assertSame('profile_source_tenant_scope_mismatch', $wrongTenant['reason'] ?? null);
        self::assertStringNotContainsString('raw-cross-tenant-hotel', json_encode($wrongTenant, JSON_UNESCAPED_SLASHES));

        $source = static function (int $id, string $identifier): array {
            return [
                'id' => $id,
                'tenant_id' => 70,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'status' => 'ready',
                'enabled' => true,
                'profile_binding_status' => 'ready',
                'config' => ['hotel_id' => $identifier],
            ];
        };
        $ambiguous = p0_authoritative_profile_identifier_resolution('ctrip', 7, 70, [
            $source(93, 'raw-ctrip-hotel-a'),
            $source(94, 'raw-ctrip-hotel-b'),
        ]);

        self::assertSame('ambiguous', $ambiguous['status'] ?? null);
        self::assertSame('authoritative_profile_identifier_ambiguous', $ambiguous['reason'] ?? null);
        self::assertSame(2, $ambiguous['identifier_count'] ?? null);
        self::assertArrayNotHasKey('expected_identifier_hash', $ambiguous);
        self::assertStringNotContainsString('raw-ctrip-hotel-a', json_encode($ambiguous, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('raw-ctrip-hotel-b', json_encode($ambiguous, JSON_UNESCAPED_SLASHES));
    }

    public function testStoredTrafficClosureRequiresEveryRowIdentifierToMatchAProfileAuthority(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $closureStart = strpos($verifier, 'function p0_traffic_field_fact_closure');
        $closureEnd = strpos($verifier, "\n/**", is_int($closureStart) ? $closureStart + 1 : 0);
        $resolverStart = strpos($verifier, 'function p0_authoritative_profile_identifier_from_db');
        $resolverEnd = strpos($verifier, "\n/**", is_int($resolverStart) ? $resolverStart + 1 : 0);

        self::assertIsInt($closureStart);
        self::assertIsInt($closureEnd);
        self::assertIsInt($resolverStart);
        self::assertIsInt($resolverEnd);
        $closure = substr($verifier, $closureStart, $closureEnd - $closureStart);
        $resolver = substr($verifier, $resolverStart, $resolverEnd - $resolverStart);

        self::assertStringContainsString('p0_authoritative_profile_identifier_from_db($platform, $rowSystemHotelId)', $closure);
        self::assertStringContainsString('p0_compare_row_platform_hotel_identifier($raw, $platform, $identifierAuthority)', $closure);
        self::assertStringContainsString("'platform_hotel_identifier_matched_rows' => 0", $closure);
        self::assertStringContainsString("'platform_hotel_identifier_mismatch_rows' => 0", $closure);
        self::assertStringContainsString("'platform_hotel_identifier_match_reason_counts' => []", $closure);
        self::assertStringContainsString("\$base['platform_hotel_identifier_matched_rows'] === (int)\$base['traffic_row_count']", $closure);
        self::assertStringContainsString("\$base['platform_hotel_identifier_match_status'] = \$allIdentifiersMatched ? 'matched' : 'unmatched';", $closure);
        self::assertStringContainsString("\$base['status'] = 'platform_hotel_identifier_mismatch';", $closure);

        self::assertStringContainsString("->where('system_hotel_id', \$systemHotelId)", $resolver);
        self::assertStringContainsString("->where('enabled', 1)", $resolver);
        self::assertStringContainsString("->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])", $resolver);
        self::assertStringContainsString('p0_safe_platform_config_projection', $resolver);
        self::assertStringContainsString('p0_profile_binding_scope_status', $resolver);
        self::assertStringNotContainsString('secret_json', $resolver);
        self::assertStringNotContainsString('ota_credentials', $resolver);
    }

    public function testRequiredTrafficMetricsRespectPlatformSemantics(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach (['p0_required_traffic_metric_keys', 'p0_required_traffic_storage_field_map'] as $functionName) {
            if (!function_exists($functionName)) {
                $definition = $this->extractFunctionDefinition($verifier, $functionName);
                self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
                eval($definition);
            }
        }

        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate'],
            p0_required_traffic_metric_keys('meituan')
        );
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'],
            p0_required_traffic_metric_keys('ctrip')
        );
        self::assertSame(
            [
                'list_exposure' => 'online_daily_data.list_exposure',
                'detail_exposure' => 'online_daily_data.detail_exposure',
                'flow_rate' => 'online_daily_data.flow_rate',
            ],
            p0_required_traffic_storage_field_map('meituan')
        );
        self::assertStringContainsString('p0_required_traffic_metric_keys($platform)', $verifier);
        self::assertStringContainsString('p0_required_traffic_storage_field_map($platform)', $verifier);
    }

    public function testFailedSyncTasksPreserveActionableSafeMessageCodes(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_normalize_task_date',
            'p0_sync_task_target_date',
            'p0_sync_task_is_stale_running',
            'p0_sync_task_message_looks_like_login_blocker',
            'p0_sync_task_message_code',
            'p0_sync_task_diagnosis',
        ] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName) || function_exists($functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
            eval($definition);
        }

        self::assertSame('2026-08-04', p0_normalize_task_date('2026-08-03T16:00:00Z'));
        self::assertSame('2026-08-03', p0_normalize_task_date('2026-08-03'));
        self::assertSame('', p0_normalize_task_date('2026-08-03 not-a-timestamp'));

        $profileCode = p0_sync_task_message_code([
            'status' => 'failed',
            'message' => 'profile_session_unverified',
        ], [], '2026-07-21');
        self::assertSame('profile_session_unverified', $profileCode);
        self::assertSame('current_profile_session_not_verified', p0_sync_task_diagnosis($profileCode));

        $executionCode = p0_sync_task_message_code([
            'status' => 'failed',
            'message' => 'credential_execution_failed',
        ], [], '2026-07-21');
        self::assertSame('credential_execution_failed', $executionCode);
        self::assertSame('capture_execution_failed', p0_sync_task_diagnosis($executionCode));
    }

    public function testLiveClosureInspectorRequiresDesensitizedEvidenceForCompleteFacts(): void
    {
        $inspector = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php');
        $definition = $this->extractFunctionDefinition($inspector, 'field_fact_closure_summary');

        self::assertNotSame('', $definition);
        self::assertStringContainsString(
            '$complete = !$explicitMissing && !$storedValueMissing && $hasCaptureEvidence && $hasDesensitizedCaptureEvidence',
            $definition
        );
        self::assertStringNotContainsString(
            '$complete = !$explicitMissing && !$storedValueMissing && $hasCaptureEvidence && $metricKey',
            $definition
        );
    }

    public function testLiveClosureTreatsExplicitlyMissingStoredValuesAsMissingFacts(): void
    {
        foreach ([
            'inspect_phase1_ota_live_closure.php',
            'build_phase1_ota_live_closure_evidence.php',
        ] as $script) {
            $source = (string)file_get_contents(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script
            );
            $definition = $this->extractFunctionDefinition($source, 'field_fact_closure_summary');

            self::assertNotSame('', $definition, 'Missing field fact closure helper in ' . $script);
            self::assertStringContainsString(
                '$explicitMissing = $storedValueMissing',
                $definition,
                'A field that explicitly has no stored value must stay missing instead of becoming an incomplete captured fact.'
            );
        }
    }

    public function testLiveClosureCliAcceptsHyphenatedHotelScopeOptions(): void
    {
        foreach ([
            'inspect_phase1_ota_live_closure.php',
            'build_phase1_ota_live_closure_evidence.php',
        ] as $script) {
            $source = (string)file_get_contents(
                dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $script
            );

            self::assertStringContainsString(
                '$key = str_replace(\'-\', \'_\', $key);',
                $source,
                'The documented --system-hotel-id form must not be silently ignored by ' . $script
            );
        }
    }

    public function testTrafficFieldLoopDoesNotRequirePlatformRevenueMetrics(): void
    {
        $verifier = (string)file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php'
        );
        if (!function_exists(__NAMESPACE__ . '\\p0_inspector_missing_codes_block_field_loop')) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_inspector_missing_codes_block_field_loop');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        self::assertFalse(p0_inspector_missing_codes_block_field_loop([
            'ctrip_etl_not_ready',
            'ctrip_revenue_metrics_not_ready',
            'ai_diagnosis_action_items_blocked',
            'operation_execution_sample_missing',
        ], ['ctrip', 'meituan']));
        self::assertTrue(p0_inspector_missing_codes_block_field_loop([
            'ctrip_traffic_facts_missing',
        ], ['ctrip', 'meituan']));
    }

    public function testLiveClosureInspectorAcceptsExactRootJsonPropertyPaths(): void
    {
        $inspector = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\field_fact_source_path_structured')
            && !function_exists('field_fact_source_path_structured')) {
            $definition = $this->extractFunctionDefinition($inspector, 'field_fact_source_path_structured');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        self::assertTrue(field_fact_source_path_structured('visitorTotal'));
        self::assertTrue(field_fact_source_path_structured('data.visitorTotal'));
        self::assertTrue(field_fact_source_path_structured('$[0].visitorTotal'));
        self::assertFalse(field_fact_source_path_structured('visitor total'));
        self::assertFalse(field_fact_source_path_structured(''));
    }

    public function testLiveClosureInspectorKeepsOptionalReferenceGapsOutOfP0CoreEtlGate(): void
    {
        $inspector = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'inspect_phase1_ota_live_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\inspection_p0_core_etl_readiness')
            && !function_exists('inspection_p0_core_etl_readiness')) {
            $definition = $this->extractFunctionDefinition($inspector, 'inspection_p0_core_etl_readiness');
            self::assertNotSame('', $definition);
            eval($definition);
        }

        $trusted = ['source_trace' => ['saved_success' => true]];
        $untrusted = ['source_trace' => ['saved_success' => false]];

        self::assertSame(
            [
                'status' => 'ready',
                'fact_count' => 2,
                'trusted_fact_count' => 2,
                'untrusted_fact_count' => 0,
            ],
            inspection_p0_core_etl_readiness([$trusted], [$trusted])
        );
        self::assertSame('blocked', inspection_p0_core_etl_readiness([$trusted], [$untrusted])['status']);
        self::assertSame('empty', inspection_p0_core_etl_readiness([], [])['status']);
    }

    public function testAlreadyIngestedTrafficDoesNotRequireTemporaryPayloadFile(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        if (!function_exists(__NAMESPACE__ . '\\p0_payload_candidate_scan_for_next_step')) {
            $definition = $this->extractFunctionDefinition($verifier, 'p0_payload_candidate_scan_for_next_step');
            self::assertNotSame('', $definition, 'Missing pure verifier helper: p0_payload_candidate_scan_for_next_step');
            eval($definition);
        }

        $ready = p0_payload_candidate_scan_for_next_step([
            'action_mode' => 'already_ingested',
            'target_date' => ['traffic_rows' => 1],
            'traffic_field_fact_closure' => ['status' => 'ready'],
        ], [
            'status' => 'missing_expected_payload',
            'ready_to_execute' => false,
            'issue_codes' => ['expected_payload_file_missing'],
        ]);
        self::assertSame('not_required_already_ingested', $ready['status']);
        self::assertSame([], $ready['issue_codes']);

        $incomplete = p0_payload_candidate_scan_for_next_step([
            'action_mode' => 'already_ingested',
            'target_date' => ['traffic_rows' => 1],
            'traffic_field_fact_closure' => ['status' => 'partial'],
        ], [
            'status' => 'missing_expected_payload',
            'issue_codes' => ['expected_payload_file_missing'],
        ]);
        self::assertSame('missing_expected_payload', $incomplete['status']);
        self::assertSame(['expected_payload_file_missing'], $incomplete['issue_codes']);
    }

    private function loadPlatformIdentifierHelpers(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach ([
            'p0_platform_hotel_identifier_keys',
            'p0_platform_hotel_identifier_hashes',
            'p0_authoritative_profile_identifier_resolution',
            'p0_compare_row_platform_hotel_identifier',
        ] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName) || function_exists($functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing pure verifier helper: ' . $functionName);
            eval($definition);
        }
    }

    private function extractFunctionDefinition(string $source, string $functionName): string
    {
        $start = strpos($source, 'function ' . $functionName . '(');
        if (!is_int($start)) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if (!is_int($brace)) {
            return '';
        }
        $depth = 0;
        $length = strlen($source);
        for ($index = $brace; $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }
        return '';
    }
}
