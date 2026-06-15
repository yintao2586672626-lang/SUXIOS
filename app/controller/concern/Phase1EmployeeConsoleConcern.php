<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OperationManagementService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use think\facade\Db;

trait Phase1EmployeeConsoleConcern
{
    private function phase1TopActionSourceSnapshot(?array $topAction, array $collectionSourceSummary): array
    {
        if (!is_array($topAction)) {
            return [];
        }
        $platform = strtolower(trim((string)($topAction['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            $actionCode = strtolower((string)($topAction['action_code'] ?? ''));
            foreach (['ctrip', 'meituan'] as $candidate) {
                if (str_contains($actionCode, $candidate)) {
                    $platform = $candidate;
                    break;
                }
            }
        }
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return [];
        }
        foreach ($collectionSourceSummary as $row) {
            if (!is_array($row) || strtolower((string)($row['platform'] ?? '')) !== $platform) {
                continue;
            }
            $latest = is_array($row['latest_available'] ?? null) ? $row['latest_available'] : null;
            return [
                'platform' => $platform,
                'target_date' => (string)($row['target_date'] ?? ''),
                'storage_table' => (string)($row['storage_table'] ?? 'online_daily_data'),
                'source_policy' => (string)($row['source_policy'] ?? 'read_existing_online_daily_data_only'),
                'target_date_rows' => max(0, (int)($row['target_date_rows'] ?? 0)),
                'target_date_data_types' => $this->phase1TargetDateDataTypes($row),
                'latest_available' => $latest,
                'latest_available_reference_only' => (bool)($row['latest_available_reference_only'] ?? true),
                'proof_requirement' => 'source_date_evidence.platforms 中该平台 target_date_rows > 0',
                'reference_policy' => 'latest_available 只能作参考，不能替代目标日入库行证明。',
            ];
        }
        return [];
    }

    private function phase1CollectionSourceSummary(array $reliability): array
    {
        $sourceDateEvidence = is_array($reliability['source_date_evidence'] ?? null) ? $reliability['source_date_evidence'] : [];
        $targetDate = (string)($sourceDateEvidence['target_date'] ?? ($reliability['period']['end_date'] ?? ''));
        $rows = [];
        foreach ((array)($sourceDateEvidence['platforms'] ?? []) as $platformEvidence) {
            if (!is_array($platformEvidence)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformEvidence['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            $targetRows = max(0, (int)($platformEvidence['target_date_rows'] ?? 0));
            $targetDataTypes = array_values(array_filter(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                (array)($platformEvidence['target_date_data_types'] ?? [])
            ), static fn(string $value): bool => $value !== ''));
            $latest = is_array($platformEvidence['latest_available'] ?? null) ? $platformEvidence['latest_available'] : [];
            $dateRelation = trim((string)($platformEvidence['date_relation'] ?? ($latest['date_relation'] ?? 'none')));
            if ($dateRelation === '') {
                $dateRelation = 'none';
            }
            $latestAvailable = $latest === [] ? null : [
                'date' => $latest['date'] ?? null,
                'date_relation' => $dateRelation,
                'rows' => max(0, (int)($latest['rows'] ?? $latest['count'] ?? 0)),
                'data_types' => array_values(array_filter(array_map(
                    static fn($value): string => strtolower(trim((string)$value)),
                    (array)($latest['data_types'] ?? [])
                ), static fn(string $value): bool => $value !== '')),
            ];

            $rows[] = [
                'platform' => $platform,
                'target_date' => $targetDate,
                'storage_table' => 'online_daily_data',
                'source_policy' => 'read_existing_online_daily_data_only',
                'metric_scope' => 'ota_channel',
                'target_date_rows' => $targetRows,
                'target_date_data_types' => $targetDataTypes,
                'latest_available' => $latestAvailable,
                'latest_available_reference_only' => $dateRelation !== 'target_date',
                'evidence_status' => $targetRows > 0 ? 'target_date_present' : ($latestAvailable !== null ? 'reference_only' : 'missing'),
                'collection_logic_changed' => false,
            ];
        }
        return $rows;
    }

    private function phase1SourceDateEvidenceStatus(array $platforms): string
    {
        $platformCount = 0;
        $coveredCount = 0;
        foreach ($platforms as $platform) {
            if (!is_array($platform) || trim((string)($platform['platform'] ?? '')) === '') {
                continue;
            }
            $platformCount++;
            if ((int)($platform['target_date_rows'] ?? 0) > 0) {
                $coveredCount++;
            }
        }
        if ($platformCount === 0 || $coveredCount === 0) {
            return 'target_date_missing';
        }
        return $coveredCount === $platformCount ? 'target_date_complete' : 'target_date_partial';
    }

    private function buildCollectionSourceDateEvidence(?int $hotelId, string $targetDate): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $required = ['source', 'data_date'];
        foreach ($required as $field) {
            if (!isset($columns[$field])) {
                return [
                    'status' => 'unavailable',
                    'target_date' => $targetDate,
                    'source_policy' => 'read_online_daily_data_aggregate_only',
                    'reason' => 'online_daily_data_column_missing:' . $field,
                    'platforms' => [],
                ];
            }
        }

        $platforms = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $platforms[] = $this->buildCollectionSourceDateEvidenceForPlatform($platform, $hotelId, $targetDate, $columns);
        }

        $targetRows = array_sum(array_map(static fn(array $row): int => (int)($row['target_date_rows'] ?? 0), $platforms));
        $status = $this->phase1SourceDateEvidenceStatus($platforms);
        return [
            'status' => $status,
            'legacy_status' => $targetRows > 0 ? 'target_date_present' : 'target_date_missing',
            'target_date' => $targetDate,
            'source_policy' => 'read_online_daily_data_aggregate_only',
            'protected_boundary' => '不改变携程/美团手动或自动获取逻辑，不改变获取字段和字段映射；历史或未来日期数据不能替代目标日证据。',
            'summary' => [
                'target_date_rows' => $targetRows,
                'coverage_status' => $status,
                'platform_count' => count($platforms),
                'missing_platforms' => array_values(array_map(
                    static fn(array $row): string => (string)$row['platform'],
                    array_filter($platforms, static fn(array $row): bool => (int)($row['target_date_rows'] ?? 0) === 0)
                )),
            ],
            'platforms' => $platforms,
        ];
    }

    private function buildCollectionSourceDateEvidenceForPlatform(string $platform, ?int $hotelId, string $targetDate, array $columns): array
    {
        $targetQuery = Db::name('online_daily_data')->whereIn('source', $this->collectionSourceAliases($platform))->where('data_date', $targetDate);
        if (!$this->applyCollectionHotelScope($targetQuery, $hotelId, $columns)) {
            return [
                'platform' => $platform,
                'target_date' => $targetDate,
                'target_date_rows' => 0,
                'target_date_data_types' => [],
                'latest_available' => null,
                'date_relation' => 'scope_denied',
            ];
        }
        $targetRows = (int)$targetQuery->count();
        $targetTypes = [];
        if (isset($columns['data_type'])) {
            $targetTypeQuery = Db::name('online_daily_data')
                ->field('data_type')
                ->whereIn('source', $this->collectionSourceAliases($platform))
                ->where('data_date', $targetDate)
                ->group('data_type')
                ->order('data_type', 'asc');
            if ($this->applyCollectionHotelScope($targetTypeQuery, $hotelId, $columns)) {
                $targetTypes = array_values(array_filter(array_map(
                    static fn(array $row): string => (string)($row['data_type'] ?? ''),
                    $targetTypeQuery->select()->toArray()
                ), static fn(string $value): bool => $value !== ''));
            }
        }

        $latestQuery = Db::name('online_daily_data')
            ->field('MAX(data_date) AS latest_data_date')
            ->whereIn('source', $this->collectionSourceAliases($platform));
        if (!$this->applyCollectionHotelScope($latestQuery, $hotelId, $columns)) {
            return [
                'platform' => $platform,
                'target_date' => $targetDate,
                'target_date_rows' => $targetRows,
                'target_date_data_types' => $targetTypes,
                'latest_available' => null,
                'date_relation' => 'scope_denied',
            ];
        }
        $latestRow = $latestQuery->find();
        $latestDate = (string)($latestRow['latest_data_date'] ?? '');
        $latestCount = 0;
        $latestTypes = [];
        if ($latestDate !== '') {
            $latestCountQuery = Db::name('online_daily_data')->whereIn('source', $this->collectionSourceAliases($platform))->where('data_date', $latestDate);
            if ($this->applyCollectionHotelScope($latestCountQuery, $hotelId, $columns)) {
                $latestCount = (int)$latestCountQuery->count();
            }
            if (isset($columns['data_type'])) {
                $typeQuery = Db::name('online_daily_data')
                    ->field('data_type')
                    ->whereIn('source', $this->collectionSourceAliases($platform))
                    ->where('data_date', $latestDate)
                    ->group('data_type')
                    ->order('data_type', 'asc');
                if ($this->applyCollectionHotelScope($typeQuery, $hotelId, $columns)) {
                    $latestTypes = array_values(array_filter(array_map(
                        static fn(array $row): string => (string)($row['data_type'] ?? ''),
                        $typeQuery->select()->toArray()
                    ), static fn(string $value): bool => $value !== ''));
                }
            }
        }

        return [
            'platform' => $platform,
            'target_date' => $targetDate,
            'target_date_rows' => $targetRows,
            'target_date_data_types' => $targetTypes,
            'latest_available' => $latestDate !== '' ? [
                'date' => $latestDate,
                'rows' => $latestCount,
                'data_types' => $latestTypes,
            ] : null,
            'date_relation' => $this->collectionDateRelation($targetDate, $latestDate),
        ];
    }

    private function collectionSourceAliases(string $platform): array
    {
        return match (strtolower($platform)) {
            'ctrip' => ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'],
            'meituan' => ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile'],
            default => [$platform],
        };
    }

    private function collectionDateRelation(string $targetDate, string $latestDate): string
    {
        if ($latestDate === '') {
            return 'none';
        }
        if ($latestDate === $targetDate) {
            return 'target_date';
        }
        return strcmp($latestDate, $targetDate) > 0 ? 'future_dated_for_target' : 'stale_before_target';
    }

    private function withPhase1EmployeeQuestions(array $reliability): array
    {
        $reliability['phase1_revenue_metric_evidence'] = $this->phase1RevenueMetricEvidence($reliability);
        $reliability['phase1_operation_execution_evidence'] = $this->phase1OperationExecutionEvidence($reliability);
        $rows = $this->buildPhase1EmployeeQuestionRows($reliability);
        $nextRequiredActions = $this->buildPhase1NextRequiredActions($rows);
        $rows = $this->withPhase1EmployeeQuestionActionCodes($rows, $nextRequiredActions);
        $collectionSourceSummary = $this->phase1CollectionSourceSummary($reliability);
        $closureSummary = $this->phase1EmployeeClosureSummary($rows, $nextRequiredActions, $collectionSourceSummary, $reliability);
        $reliability['collection_source_summary'] = $collectionSourceSummary;

        $reliability['phase1_employee_questions'] = [
            'scope' => [
                'metric_scope' => 'ota_channel',
                'hotel_id' => $reliability['hotel_id'] ?? null,
                'period' => $reliability['period'] ?? [],
                'mode' => $reliability['mode'] ?? 'full',
            ],
            'source_policy' => 'read_existing_collection_reliability_only',
            'protected_boundary' => 'Do not change Ctrip/Meituan manual or automatic acquisition logic, fields, mappings, or storage; do not generate conclusive operating conclusions when evidence is insufficient.',
            'collection_source_summary' => $collectionSourceSummary,
            'revenue_metric_evidence' => $reliability['phase1_revenue_metric_evidence'] ?? [],
            'operation_execution_evidence' => $reliability['phase1_operation_execution_evidence'] ?? [],
            'rows' => $rows,
            'next_required_actions' => $nextRequiredActions,
            'summary' => $closureSummary,
            'closure_summary' => $closureSummary,
        ];

        return $reliability;
    }

    private function phase1EmployeeClosureSummary(array $rows, array $nextRequiredActions, array $collectionSourceSummary, array $reliability): array
    {
        $provedRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') === 'proved'));
        $missingRows = array_values(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? '') !== 'proved'));
        $topAction = null;
        foreach ($nextRequiredActions as $action) {
            if (!is_array($action)) {
                continue;
            }
            if ((string)($action['status'] ?? '') !== 'blocked') {
                $topAction = $action;
                break;
            }
            $topAction ??= $action;
        }
        $topActionEntryOptions = array_values(array_filter(array_map(
            static function ($option): ?array {
                if (!is_array($option)) {
                    return null;
                }
                $entry = (string)($option['entry'] ?? '');
                if ($entry === '') {
                    return null;
                }
                $entryOption = [
                    'mode' => (string)($option['mode'] ?? $option['type'] ?? ''),
                    'label' => (string)($option['label'] ?? ''),
                    'entry' => $entry,
                    'use_when' => (string)($option['use_when'] ?? ''),
                    'requires' => (string)($option['requires'] ?? ''),
                    'boundary' => (string)($option['boundary'] ?? ''),
                ];
                if (is_array($option['readiness'] ?? null)) {
                    $entryOption['readiness'] = $option['readiness'];
                }
                return $entryOption;
            },
            (array)($topAction['entry_options'] ?? [])
        )));
        $topActionSourceSnapshot = $this->phase1TopActionSourceSnapshot($topAction, $collectionSourceSummary);

        return [
            'status' => $missingRows === [] ? 'complete' : 'incomplete',
            'metric_scope' => 'ota_channel',
            'employee_question_count' => count($rows),
            'proved_count' => count($provedRows),
            'missing_count' => count($missingRows),
            'missing_questions' => array_values(array_map(static fn(array $row): string => (string)$row['question'], $missingRows)),
            'missing_question_keys' => array_values(array_map(static fn(array $row): string => (string)$row['key'], $missingRows)),
            'collection_source_platform_count' => count($collectionSourceSummary),
            'operation_evidence_status' => (string)($reliability['phase1_operation_execution_evidence']['operation_evidence_status'] ?? 'missing'),
            'next_action_count' => count($nextRequiredActions),
            'high_priority_action_count' => count(array_filter($nextRequiredActions, static fn(array $row): bool => ($row['priority'] ?? '') === 'high')),
            'top_action_code' => (string)($topAction['action_code'] ?? ''),
            'top_action' => (string)($topAction['action'] ?? ''),
            'top_action_entry' => (string)($topAction['entry'] ?? ''),
            'top_action_entry_options' => $topActionEntryOptions,
            'top_action_success_criteria' => (string)($topAction['success_criteria'] ?? ''),
            'top_action_status' => (string)($topAction['status'] ?? ''),
            'top_action_priority' => (string)($topAction['priority'] ?? ''),
            'top_action_owner' => (string)($topAction['owner'] ?? ''),
            'top_action_related_question_keys' => array_values(array_filter(array_map('strval', (array)($topAction['related_question_keys'] ?? [])))),
            'top_action_resolves_missing_codes' => array_values(array_filter(array_map('strval', (array)($topAction['resolves_missing_codes'] ?? [])))),
            'top_action_live_closure_gap_codes' => array_values(array_filter(array_map('strval', (array)($topAction['live_closure_gap_codes'] ?? [])))),
            'top_action_blocked_by_action_codes' => array_values(array_filter(array_map('strval', (array)($topAction['blocked_by_action_codes'] ?? [])))),
            'top_action_source_snapshot' => $topActionSourceSnapshot,
            'source_policy' => 'read_existing_phase1_employee_question_rows_only',
            'reference_policy' => 'latest_available_and_history_rows_are_reference_only_not_target_date_proof',
            'protected_boundary' => 'Do not change Ctrip/Meituan manual or automatic acquisition logic, fields, mappings, or storage; do not generate conclusive operating conclusions when evidence is insufficient.',
        ];
    }

    private function phase1RevenueMetricEvidence(array $reliability): array
    {
        foreach (['revenue_metric_evidence', 'phase1_revenue_metric_evidence'] as $key) {
            if (is_array($reliability[$key] ?? null)) {
                return $this->normalizePhase1RevenueMetricEvidence($reliability[$key]);
            }
        }

        $sourceDateEvidence = is_array($reliability['source_date_evidence'] ?? null) ? $reliability['source_date_evidence'] : [];
        $targetDate = trim((string)($sourceDateEvidence['target_date'] ?? ($reliability['period']['end_date'] ?? '')));
        if ($targetDate === '') {
            return $this->normalizePhase1RevenueMetricEvidence([
                'status' => 'missing_target_date',
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
            ]);
        }

        $filters = [
            'start_date' => $targetDate,
            'end_date' => $targetDate,
            'limit' => 5000,
        ];
        $hotelId = (int)($reliability['hotel_id'] ?? 0);
        if ($hotelId > 0) {
            $filters['system_hotel_id'] = $hotelId;
        }

        try {
            $dataset = (new OtaStandardEtlService())->buildDataset($filters);
            $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            return $this->normalizePhase1RevenueMetricEvidence([
                'status' => (string)($metrics['status'] ?? $dataset['status'] ?? 'unknown'),
                'source' => '/api/ota-standard/revenue-metrics',
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
                'metric_scope' => 'ota_channel',
                'filters' => $filters,
                'metric_trust' => $metrics['metric_trust'] ?? [],
                'data_gaps' => $metrics['data_gaps'] ?? [],
                'traffic_rows' => $metrics['traffic']['rows'] ?? 0,
                'etl_status' => $dataset['status'] ?? 'unknown',
                'raw_data_exposed' => false,
            ]);
        } catch (\Throwable $e) {
            return $this->normalizePhase1RevenueMetricEvidence([
                'status' => 'unavailable',
                'source' => '/api/ota-standard/revenue-metrics',
                'source_policy' => 'read_existing_ota_standard_revenue_metrics_only',
                'metric_scope' => 'ota_channel',
                'filters' => $filters,
                'error_type' => $e::class,
                'raw_data_exposed' => false,
            ]);
        }
    }

    private function normalizePhase1RevenueMetricEvidence(array $evidence): array
    {
        $metricTrust = is_array($evidence['metric_trust'] ?? null) ? $evidence['metric_trust'] : [];
        $reportedMetricTrustKeys = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            (array)($evidence['metric_trust_keys'] ?? array_keys($metricTrust))
        ), static fn(string $value): bool => $value !== '')));
        $metricTrustKeys = $metricTrust !== []
            ? $this->phase1TrustedMetricTrustKeys($metricTrust)
            : $reportedMetricTrustKeys;
        $dataGaps = is_array($evidence['data_gaps'] ?? null) ? array_values($evidence['data_gaps']) : [];
        $dataGapCodes = array_values(array_unique(array_filter(array_map(
            static function ($value): string {
                if (is_array($value)) {
                    return trim((string)($value['code'] ?? $value['field'] ?? $value['metric_key'] ?? ''));
                }
                return trim((string)$value);
            },
            (array)($evidence['data_gap_codes'] ?? $dataGaps)
        ), static fn(string $value): bool => $value !== '')));
        $safeDataGaps = array_values(array_map(static function ($gap): array {
            $row = is_array($gap) ? $gap : ['code' => (string)$gap];
            return array_filter([
                'code' => isset($row['code']) ? (string)$row['code'] : null,
                'field' => isset($row['field']) ? (string)$row['field'] : null,
                'metric_key' => isset($row['metric_key']) ? (string)$row['metric_key'] : null,
                'severity' => isset($row['severity']) ? (string)$row['severity'] : null,
                'message' => isset($row['message']) ? (string)$row['message'] : null,
            ], static fn($value): bool => $value !== null && $value !== '');
        }, $dataGaps));

        return [
            'status' => (string)($evidence['status'] ?? 'unknown'),
            'source' => (string)($evidence['source'] ?? '/api/ota-standard/revenue-metrics'),
            'source_policy' => (string)($evidence['source_policy'] ?? 'read_existing_ota_standard_revenue_metrics_only'),
            'metric_scope' => (string)($evidence['metric_scope'] ?? 'ota_channel'),
            'filters' => is_array($evidence['filters'] ?? null) ? $evidence['filters'] : [],
            'metric_trust_key_count' => count($metricTrustKeys),
            'metric_trust_keys' => $metricTrustKeys,
            'reported_metric_trust_key_count' => count($reportedMetricTrustKeys),
            'data_gap_count' => count($dataGapCodes),
            'data_gap_codes' => $dataGapCodes,
            'data_gaps' => $safeDataGaps,
            'traffic_rows' => max(0, (int)($evidence['traffic_rows'] ?? 0)),
            'etl_status' => (string)($evidence['etl_status'] ?? 'unknown'),
            'raw_data_exposed' => false,
            'error_type' => (string)($evidence['error_type'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $metricTrust
     * @return array<int, string>
     */
    private function phase1TrustedMetricTrustKeys(array $metricTrust): array
    {
        $keys = [];
        foreach ($metricTrust as $key => $trust) {
            $keyText = trim((string)$key);
            if ($keyText === '') {
                continue;
            }
            if (is_array($trust)) {
                if (($trust['saved_success'] ?? false) === true) {
                    $keys[$keyText] = true;
                }
                continue;
            }
            if ($trust === true) {
                $keys[$keyText] = true;
            }
        }

        return array_values(array_keys($keys));
    }

    private function phase1OperationExecutionEvidence(array $reliability): array
    {
        foreach (['operation_execution_evidence', 'phase1_operation_execution_evidence'] as $key) {
            if (is_array($reliability[$key] ?? null)) {
                return $this->normalizePhase1OperationExecutionEvidence($reliability[$key]);
            }
        }

        if (is_array($reliability['operation_execution_flow'] ?? null)) {
            return $this->phase1OperationExecutionEvidenceFromFlow($reliability['operation_execution_flow'], [
                'source' => '/api/operation/execution-flow',
                'source_policy' => 'read_existing_operation_execution_state_only',
            ]);
        }

        $user = $this->currentUser ?? null;
        if (!$user) {
            return $this->normalizePhase1OperationExecutionEvidence([
                'status' => 'missing',
                'operation_evidence_status' => 'missing',
                'source' => '/api/operation/execution-flow',
                'source_policy' => 'read_existing_operation_execution_state_only',
                'data_gaps' => [[
                    'code' => 'operation_execution_context_missing',
                    'message' => 'operation execution flow requires current user scope',
                ]],
            ]);
        }

        $hotelId = (int)($reliability['hotel_id'] ?? 0);
        $hotelIds = [];
        if ($hotelId > 0) {
            $hotelIds = [$hotelId];
        } elseif (method_exists($user, 'getPermittedHotelIds')) {
            $hotelIds = array_values(array_unique(array_filter(
                array_map('intval', (array)$user->getPermittedHotelIds()),
                static fn(int $id): bool => $id > 0
            )));
        }

        try {
            $flow = (new OperationManagementService())->executionFlow(
                $hotelIds,
                $hotelId > 0 ? $hotelId : null,
                []
            );

            return $this->phase1OperationExecutionEvidenceFromFlow($flow, [
                'source' => '/api/operation/execution-flow',
                'source_policy' => 'read_existing_operation_execution_state_only',
                'filters' => array_filter([
                    'hotel_id' => $hotelId > 0 ? $hotelId : null,
                    'hotel_ids' => $hotelId > 0 ? null : $hotelIds,
                ], static fn($value): bool => $value !== null && $value !== []),
            ]);
        } catch (\Throwable $e) {
            return $this->normalizePhase1OperationExecutionEvidence([
                'status' => 'unavailable',
                'operation_evidence_status' => 'missing',
                'source' => '/api/operation/execution-flow',
                'source_policy' => 'read_existing_operation_execution_state_only',
                'data_gaps' => [[
                    'code' => 'operation_execution_flow_unavailable',
                    'message' => 'operation execution flow unavailable',
                ]],
                'error_type' => $e::class,
            ]);
        }
    }

    private function normalizePhase1OperationExecutionEvidence(array $evidence): array
    {
        if (isset($evidence['summary']) || isset($evidence['list'])) {
            return $this->phase1OperationExecutionEvidenceFromFlow($evidence, [
                'source' => (string)($evidence['source'] ?? '/api/operation/execution-flow'),
                'source_policy' => (string)($evidence['source_policy'] ?? 'read_existing_operation_execution_state_only'),
            ]);
        }

        $dataGaps = is_array($evidence['data_gaps'] ?? null) ? array_values($evidence['data_gaps']) : [];
        $dataGapCodes = array_values(array_unique(array_filter(array_map(
            static function ($gap): string {
                if (is_array($gap)) {
                    return trim((string)($gap['code'] ?? $gap['field'] ?? ''));
                }
                return trim((string)$gap);
            },
            (array)($evidence['data_gap_codes'] ?? $dataGaps)
        ), static fn(string $value): bool => $value !== '')));
        $status = (string)($evidence['operation_evidence_status'] ?? $evidence['status'] ?? 'missing');
        $executionIntentCount = max(0, (int)($evidence['execution_intent_count'] ?? 0));
        $executionFlowItemCount = max(0, (int)($evidence['execution_flow_item_count'] ?? 0));
        $linkedIntentCount = max(0, (int)($evidence['ota_diagnosis_linked_intent_count'] ?? 0));
        $linkedFlowItemCount = max(0, (int)($evidence['ota_diagnosis_linked_flow_item_count'] ?? 0));
        $linkedCount = max($linkedIntentCount, $linkedFlowItemCount);
        $payloadSignalCount = max($executionIntentCount, $executionFlowItemCount);
        $completionSignalCount = max(0, (int)($evidence['completion_signal_count'] ?? 0));
        $blockingMissingCodes = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($evidence['blocking_missing_codes'] ?? [])
        ))));
        if ($blockingMissingCodes === [] && $status !== 'proved') {
            $blockingMissingCodes = $linkedCount > 0
                ? ['operation_execution_evidence_incomplete']
                : ($payloadSignalCount > 0 ? ['operation_execution_ai_action_link_missing'] : ['operation_execution_sample_missing']);
        }

        return [
            'status' => $status,
            'operation_evidence_status' => $status,
            'source' => (string)($evidence['source'] ?? '/api/operation/execution-flow'),
            'source_policy' => (string)($evidence['source_policy'] ?? 'read_existing_operation_execution_state_only'),
            'metric_scope' => (string)($evidence['metric_scope'] ?? 'ota_channel'),
            'filters' => is_array($evidence['filters'] ?? null) ? $evidence['filters'] : [],
            'execution_intent_count' => $executionIntentCount,
            'execution_flow_item_count' => $executionFlowItemCount,
            'ota_diagnosis_linked_intent_count' => $linkedIntentCount,
            'ota_diagnosis_linked_flow_item_count' => $linkedFlowItemCount,
            'approved_count' => max(0, (int)($evidence['approved_count'] ?? 0)),
            'executed_count' => max(0, (int)($evidence['executed_count'] ?? 0)),
            'evidence_ready_count' => max(0, (int)($evidence['evidence_ready_count'] ?? 0)),
            'execution_evidence_count' => max(0, (int)($evidence['execution_evidence_count'] ?? 0)),
            'reviewed_count' => max(0, (int)($evidence['reviewed_count'] ?? 0)),
            'roi_ready_count' => max(0, (int)($evidence['roi_ready_count'] ?? 0)),
            'blocked_execution_count' => max(0, (int)($evidence['blocked_execution_count'] ?? 0)),
            'completion_signal_count' => $completionSignalCount,
            'missing_signals' => array_values(array_filter(array_map('strval', (array)($evidence['missing_signals'] ?? [])))),
            'blocking_missing_codes' => $blockingMissingCodes,
            'data_gap_codes' => $dataGapCodes,
            'data_gaps' => $this->normalizePhase1OperationDataGaps($dataGaps),
            'raw_data_exposed' => false,
            'error_type' => (string)($evidence['error_type'] ?? ''),
        ];
    }

    private function phase1OperationExecutionEvidenceFromFlow(array $flow, array $meta = []): array
    {
        $summary = is_array($flow['summary'] ?? null) ? $flow['summary'] : [];
        $items = array_values(array_filter((array)($flow['list'] ?? []), static fn($item): bool => is_array($item)));
        $stageCounts = is_array($summary['stage_counts'] ?? null) ? $summary['stage_counts'] : [];
        $linkedItems = array_values(array_filter($items, fn(array $item): bool => $this->phase1OperationItemHasOtaDiagnosisEvidence($item)));
        $total = max((int)($summary['total'] ?? 0), count($items));
        $approved = $this->phase1CountOperationItems($linkedItems, static fn(array $item): bool => (string)($item['approval']['status'] ?? '') === 'approved');
        $executed = $this->phase1CountOperationItems($linkedItems, static fn(array $item): bool => (string)($item['execution']['status'] ?? '') === 'executed');
        $evidenceReady = $this->phase1CountOperationItems($linkedItems, static fn(array $item): bool => (int)($item['evidence']['count'] ?? 0) > 0);
        $executionEvidenceCount = array_reduce($linkedItems, static function (int $sum, array $item): int {
            return $sum + max(0, (int)($item['evidence']['count'] ?? 0));
        }, 0);
        $reviewed = $this->phase1CountOperationItems($linkedItems, static function (array $item): bool {
            $reviewStatus = (string)($item['review']['status'] ?? '');
            return (string)($item['stage'] ?? '') === 'reviewed'
                || in_array($reviewStatus, ['success', 'near_success', 'failed'], true);
        });
        $roiReady = $this->phase1CountOperationItems($linkedItems, static fn(array $item): bool => (string)($item['roi']['status'] ?? '') === 'ready');
        $blocked = max(
            (int)($stageCounts['blocked'] ?? 0) + (int)($stageCounts['rejected'] ?? 0) + (int)($stageCounts['failed'] ?? 0),
            $this->phase1CountOperationItems($items, static function (array $item): bool {
                return in_array((string)($item['stage'] ?? ''), ['blocked', 'rejected', 'failed'], true)
                    || in_array((string)($item['approval']['status'] ?? ''), ['blocked', 'rejected'], true)
                    || in_array((string)($item['execution']['status'] ?? ''), ['blocked', 'failed'], true);
            })
        );
        $completionSignalCount = $approved + $executed + $evidenceReady + $reviewed + $roiReady;
        $linkedCount = count($linkedItems);
        $status = $completionSignalCount > 0 ? 'proved' : ($linkedCount > 0 || $total > 0 ? 'warning' : 'missing');
        $blockingMissingCodes = $status === 'proved'
            ? []
            : ($linkedCount > 0
                ? ['operation_execution_evidence_incomplete']
                : ($total > 0 ? ['operation_execution_ai_action_link_missing'] : ['operation_execution_sample_missing']));
        $missingSignals = $status === 'proved'
            ? []
            : ($linkedCount > 0
                ? ['approval.status=approved', 'execution.status=executed', 'evidence.count>0', 'review.status', 'roi.status=ready']
                : ($total > 0 ? ['ota_diagnosis_action_item_link'] : ['execution_intent']));

        return $this->normalizePhase1OperationExecutionEvidence([
            'status' => $status,
            'operation_evidence_status' => $status,
            'source' => (string)($meta['source'] ?? '/api/operation/execution-flow'),
            'source_policy' => (string)($meta['source_policy'] ?? 'read_existing_operation_execution_state_only'),
            'metric_scope' => 'ota_channel',
            'filters' => is_array($meta['filters'] ?? null) ? $meta['filters'] : [],
            'execution_intent_count' => $total,
            'execution_flow_item_count' => count($items),
            'ota_diagnosis_linked_intent_count' => $linkedCount,
            'ota_diagnosis_linked_flow_item_count' => $linkedCount,
            'approved_count' => $approved,
            'executed_count' => $executed,
            'evidence_ready_count' => $evidenceReady,
            'execution_evidence_count' => $executionEvidenceCount,
            'reviewed_count' => $reviewed,
            'roi_ready_count' => $roiReady,
            'blocked_execution_count' => $blocked,
            'completion_signal_count' => $completionSignalCount,
            'missing_signals' => $missingSignals,
            'blocking_missing_codes' => $blockingMissingCodes,
            'data_gaps' => is_array($flow['data_gaps'] ?? null) ? $flow['data_gaps'] : [],
            'raw_data_exposed' => false,
        ]);
    }

    private function phase1OperationItemHasOtaDiagnosisEvidence(array $item): bool
    {
        $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
        $evidence = is_array($recommendation['evidence'] ?? null) ? $recommendation['evidence'] : [];
        $sourceModule = strtolower((string)($recommendation['source_module'] ?? ''));
        if ($sourceModule !== 'ota_diagnosis') {
            return false;
        }

        return !empty($evidence['evidence_refs'])
            || !empty($evidence['data_gaps'])
            || array_key_exists('action_item_id', $evidence)
            || array_key_exists('action_item_status', $evidence)
            || array_key_exists('diagnosis_summary', $evidence);
    }

    private function phase1CountOperationItems(array $items, callable $predicate): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (is_array($item) && $predicate($item)) {
                $count++;
            }
        }
        return $count;
    }

    private function normalizePhase1OperationDataGaps(array $dataGaps): array
    {
        return array_values(array_map(static function ($gap): array {
            $row = is_array($gap) ? $gap : ['code' => (string)$gap];
            return array_filter([
                'code' => isset($row['code']) ? (string)$row['code'] : null,
                'field' => isset($row['field']) ? (string)$row['field'] : null,
                'severity' => isset($row['severity']) ? (string)$row['severity'] : null,
                'message' => isset($row['message']) ? (string)$row['message'] : null,
            ], static fn($value): bool => $value !== null && $value !== '');
        }, $dataGaps));
    }

    private function withPhase1EmployeeQuestionActionCodes(array $rows, array $actions): array
    {
        // Row contract fields: primary_next_action_code, direct_next_action_code, linked_action_count.
        $actionsByQuestion = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $actionCode = (string)($action['action_code'] ?? '');
            if ($actionCode === '') {
                continue;
            }
            $directQuestionKey = (string)($action['question_key'] ?? '');
            $questionKeys = array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge([$directQuestionKey], (array)($action['related_question_keys'] ?? []))
            ))));
            foreach ($questionKeys as $questionKey) {
                if (!isset($actionsByQuestion[$questionKey])) {
                    $actionsByQuestion[$questionKey] = [
                        'codes' => [],
                        'primary_action' => null,
                        'direct_action' => null,
                        'blocked_action_codes' => [],
                    ];
                }
                $actionsByQuestion[$questionKey]['codes'][] = $actionCode;
                if (!is_array($actionsByQuestion[$questionKey]['primary_action'])) {
                    $actionsByQuestion[$questionKey]['primary_action'] = $action;
                }
                if ($directQuestionKey === $questionKey && !is_array($actionsByQuestion[$questionKey]['direct_action'])) {
                    $actionsByQuestion[$questionKey]['direct_action'] = $action;
                }
                if ((string)($action['status'] ?? '') === 'blocked') {
                    $actionsByQuestion[$questionKey]['blocked_action_codes'][] = $actionCode;
                }
            }
        }

        return array_values(array_map(function (array $row) use ($actionsByQuestion): array {
            $key = (string)($row['key'] ?? '');
            $summary = is_array($actionsByQuestion[$key] ?? null) ? $actionsByQuestion[$key] : [];
            $actionCodes = array_values(array_unique((array)($summary['codes'] ?? [])));
            $row['next_action_codes'] = $actionCodes;
            $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
            $evidence['linked_action_count'] = count($actionCodes);
            $directAction = is_array($summary['direct_action'] ?? null)
                ? $summary['direct_action']
                : ($summary['primary_action'] ?? null);
            foreach ([
                'primary_next_action' => $summary['primary_action'] ?? null,
                'direct_next_action' => $directAction,
            ] as $prefix => $action) {
                if (!is_array($action)) {
                    continue;
                }
                $fields = [
                    $prefix . '_code' => (string)($action['action_code'] ?? ''),
                    $prefix . '_family' => (string)($action['action_family'] ?? ''),
                    $prefix . '_entry' => (string)($action['entry'] ?? ''),
                    $prefix . '_success_criteria' => (string)($action['success_criteria'] ?? ''),
                    $prefix . '_status' => (string)($action['status'] ?? ''),
                ];
                foreach ($fields as $field => $value) {
                    if ($value === '') {
                        continue;
                    }
                    $row[$field] = $value;
                    $evidence[$field] = $value;
                }
                $entryOptions = array_values(array_filter(
                    (array)($action['entry_options'] ?? []),
                    static fn($option): bool => is_array($option) && (string)($option['entry'] ?? '') !== ''
                ));
                if ($entryOptions !== []) {
                    $row[$prefix . '_entry_options'] = $entryOptions;
                    $evidence[$prefix . '_entry_options'] = $entryOptions;
                }
                foreach ([
                    $prefix . '_related_question_keys' => $action['related_question_keys'] ?? [],
                    $prefix . '_resolves_missing_codes' => $action['resolves_missing_codes'] ?? [],
                    $prefix . '_live_closure_gap_codes' => $action['live_closure_gap_codes'] ?? [],
                ] as $field => $values) {
                    $normalizedValues = array_values(array_unique(array_filter(array_map('strval', (array)$values))));
                    if ($normalizedValues === []) {
                        continue;
                    }
                    $row[$field] = $normalizedValues;
                    $evidence[$field] = $normalizedValues;
                }
            }
            $blockedActionCodes = array_values(array_unique((array)($summary['blocked_action_codes'] ?? [])));
            if ($blockedActionCodes !== []) {
                $row['blocked_action_codes'] = $blockedActionCodes;
                $evidence['blocked_action_codes'] = $blockedActionCodes;
            }
            $blockingGapCodes = [];
            foreach ([
                $evidence['blocking_missing_codes'] ?? [],
                $evidence['operation_blocking_missing_codes'] ?? [],
                $evidence['metric_domain_gap_codes'] ?? [],
                $evidence['direct_next_action_resolves_missing_codes'] ?? [],
                $evidence['primary_next_action_resolves_missing_codes'] ?? [],
            ] as $values) {
                foreach ((array)$values as $value) {
                    $code = trim((string)$value);
                    if ($code !== '') {
                        $blockingGapCodes[] = $code;
                    }
                }
            }
            $blockingGapCodes = array_values(array_unique($blockingGapCodes));
            if (!in_array((string)($row['status'] ?? ''), ['proved', 'no_gap_reported'], true) && $blockingGapCodes !== []) {
                $row['blocking_gap_codes'] = $blockingGapCodes;
                $evidence['blocking_gap_codes'] = $blockingGapCodes;
            }
            $rawEmployeeDetail = (string)($row['employee_detail'] ?? $row['detail'] ?? '');
            $row['employee_detail'] = $this->phase1EmployeeReadableCopy($rawEmployeeDetail !== ''
                ? $rawEmployeeDetail
                : $this->phase1EmployeeQuestionDetail($row));
            $row['employee_next_action'] = $this->phase1EmployeeReadableCopy((string)($row['employee_next_action'] ?? $row['next_action'] ?? ''));
            $row['evidence'] = $evidence;
            return $row;
        }, $rows));
    }

    private function phase1EmployeeReadableCopy(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return strtr($text, [
            'CTRIP' => '携程',
            'MEITUAN' => '美团',
            'ctrip_source_rows_missing' => '携程目标日源数据缺失',
            'meituan_source_rows_missing' => '美团目标日源数据缺失',
            'ctrip_target_date_source_rows_missing' => '携程目标日源数据缺失',
            'meituan_target_date_source_rows_missing' => '美团目标日源数据缺失',
            'ctrip_etl_not_ready' => '携程标准事实层未就绪',
            'meituan_etl_not_ready' => '美团标准事实层未就绪',
            'ctrip_revenue_metrics_not_ready' => '携程收益指标未就绪',
            'meituan_revenue_metrics_not_ready' => '美团收益指标未就绪',
            'ctrip_traffic_facts_missing' => '携程流量/转化事实缺失',
            'meituan_traffic_facts_missing' => '美团流量/转化事实缺失',
            'ai_diagnosis_evidence_sample_missing' => 'AI 诊断证据样例缺失',
            'ai_diagnosis_action_items_blocked' => 'AI 动作项被上游缺口阻断',
            'ai_action_items_blocked' => 'AI 动作项被上游缺口阻断',
            'ai_action_items_missing' => 'AI 动作项缺失',
            'operation_execution_sample_missing' => '运营执行样例缺失',
            'operation_execution_ai_action_link_missing' => '运营执行未关联 OTA 诊断动作',
            'operation_execution_evidence_incomplete' => '运营执行证据不完整',
            '按 metric_trust 判断' => '按指标可信证据判断',
            '按 data_gaps 处理' => '按数据缺口处理',
            '按 数据缺口 处理' => '按数据缺口处理',
            '非 blocked action_items' => '非阻断动作项',
            'non blocked action_items' => '非阻断动作项',
            '非阻断 action_items' => '非阻断动作项',
            '非阻断 动作项' => '非阻断动作项',
            '非 blocked 动作项' => '非阻断动作项',
            'blocked action_items' => '阻断动作项',
            'blocked 动作项' => '阻断动作项',
            '为 blocked' => '为阻断',
            'blocked_by 上游缺口' => '上游阻断缺口',
            'blocked_by' => '上游阻断',
            'OTA 诊断 action_items' => 'OTA 诊断动作项',
            'AI action_items' => 'AI 动作项',
            'collection-reliability.source_date_evidence' => '采集可靠性里的目标日来源证据',
            'source_date_evidence' => '目标日来源证据',
            'evidence_sources/data_gaps/action_items' => '证据来源、数据缺口和动作项',
            'evidence_sources、data_gaps、action_items' => '证据来源、数据缺口、动作项',
            'evidence_sources、data_gaps 和 action_items' => '证据来源、数据缺口和动作项',
            'evidence_sources' => '证据来源',
            'evidence_refs' => '证据引用',
            'source_module=ota_diagnosis' => '来源模块=OTA 诊断',
            'source=ota_diagnosis#action_item' => '来源=OTA 诊断动作项',
            'latest_available' => '最近可用数据',
            'ETL status=ready' => '标准化状态已就绪',
            'revenue_status=ready' => '收益状态已就绪',
            'traffic_status=ready' => '流量状态已就绪',
            'conversion_status=ready' => '转化状态已就绪',
            'status=ready' => '状态已就绪',
            'OTA diagnosis' => 'OTA 诊断',
            'source_trace_id' => '来源追踪标识',
            'sync_task_id' => '同步任务标识',
            'data_source_id' => '数据来源标识',
            'data_gaps' => '数据缺口',
            'action_items' => '动作项',
            'action_item_id' => '动作项标识',
            'approval.status=approved' => '审批已通过',
            'execution.status=executed' => '执行已完成',
            'evidence.count>0' => '已有执行证据',
            'review.status' => '复盘状态',
            'execution_intents' => '执行意图',
            'execution_flow' => '执行流程',
            'metric_trust' => '指标可信证据',
            'raw_data' => '脱敏原始响应追踪',
            'data_type' => '数据类型',
            'accepted_rows' => '已接收行数',
            'rejected_rows' => '已拒绝行数',
            'validation_flags' => '校验标记',
            'online_daily_data' => 'OTA 日数据表',
            'source_date_evidence.platforms' => '目标日来源证据平台列表',
            'target_date_rows' => '目标日入库行数',
            'target_date_data_types' => '目标日数据类型',
            'revenue_metrics' => '收益指标',
            'amount' => '收入金额',
            'quantity' => '间夜数量',
            'room_nights' => '间夜数',
            'book_order_num' => '订单数',
            'order_count' => '订单数',
            'list_exposure' => '列表曝光',
            'detail_exposure' => '详情曝光',
            'flow_rate' => '流量转化率',
            'order_filling_num' => '填单数',
            'order_submit_num' => '提交订单数',
            'totals' => '收益汇总',
        ]);
    }

    private function phase1EmployeeQuestionDetail(array $row): string
    {
        $key = (string)($row['key'] ?? $row['question'] ?? '');
        $status = (string)($row['status'] ?? '');
        $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
        $missingPlatforms = $this->phase1EmployeePlatformListText((array)($evidence['missing_platforms'] ?? []));
        $revenueReadyPlatforms = $this->phase1EmployeePlatformListText((array)($evidence['revenue_ready_platforms'] ?? []));

        return match ($key) {
            'today_ota_collected' => in_array($status, ['proved'], true)
                ? '目标日携程和美团 OTA 数据均有入库证据；最近可用数据只作参考。'
                : '目标日 OTA 数据尚未完整证明' . ($missingPlatforms !== '' ? '，缺失平台：' . $missingPlatforms : '') . '；最近可用或历史数据不能替代目标日入库。',
            'trusted_fields' => in_array($status, ['proved'], true)
                ? '字段可信度已有目标日入库、字段资产、数据质量和指标可信证据支撑。'
                : '字段可信度仍受目标日源数据、字段资产、数据质量或指标可信证据缺口影响；未证明字段不能写成可信。',
            'missing_fields' => ((array)($evidence['data_gap_codes'] ?? [])) !== [] || ((array)($evidence['missing_field_codes'] ?? [])) !== []
                ? '字段缺口已显式列出；按数据缺口处理，不用 0 或空值兜底。'
                : '当前未返回字段缺口；仍以目标日采集和指标可信证据为准，不代表所有平台字段完备。',
            'revenue_traffic_conversion' => in_array($status, ['proved'], true)
                ? '收益、流量、转化均可基于目标日 OTA 事实复核。'
                : (($revenueReadyPlatforms !== '' ? '收益可先复核：' . $revenueReadyPlatforms . '；' : '') . '流量或转化事实不足的平台不得输出确定漏斗判断。'),
            'ai_evidence' => in_array($status, ['proved'], true)
                ? 'AI 建议已有证据来源、数据缺口和可执行动作项支撑。'
                : 'AI 依据被上游 OTA 证据缺口阻断；当前只能定位缺口，不能当作可执行经营建议。',
            'next_operation_action' => in_array($status, ['proved'], true)
                ? '运营动作已追溯到 OTA 诊断，并有审批、执行证据、复盘或 ROI 信号。'
                : '执行闭环尚未证明；必须先有可执行 AI 动作项，再保留审批、执行证据和复盘。',
            default => '当前员工问题尚未形成完整说明；按动作队列补齐证据后重新巡检。',
        };
    }

    private function phase1EmployeePlatformListText(array $platforms): string
    {
        $labels = [];
        foreach ($platforms as $platform) {
            $value = strtolower(trim((string)$platform));
            $label = match ($value) {
                'ctrip' => '携程',
                'meituan' => '美团',
                default => $this->phase1EmployeeReadableCopy((string)$platform),
            };
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        return implode('、', array_values(array_unique($labels)));
    }

    private function phase1MissingFieldSummary(array $dataGapCodes, array $missingFieldCodes): array
    {
        $sourcesByCode = [];
        foreach ($dataGapCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $sourcesByCode[$code]['data_gap_codes'] = true;
            }
        }
        foreach ($missingFieldCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $sourcesByCode[$code]['missing_field_codes'] = true;
            }
        }

        $summary = [];
        foreach ($sourcesByCode as $code => $sources) {
            $sourceKeys = array_keys($sources);
            $summary[] = [
                'code' => $code,
                'label' => $this->phase1MissingFieldLabel($code),
                'source_keys' => $sourceKeys,
                'source_text' => $this->phase1MissingFieldSourceText($sourceKeys),
                'business_impact' => $this->phase1MissingFieldBusinessImpact($code),
                'next_action' => $this->phase1MissingFieldNextAction($code, $sourceKeys),
                'policy' => '显式保留缺口；不使用 0、空值或成功状态替代。',
            ];
        }

        return $summary;
    }

    private function phase1MissingFieldLabel(string $code): string
    {
        return [
            'available_room_nights_missing' => '可售房晚缺失',
            'commission_fields_missing' => '佣金字段缺失',
            'net_revenue_fields_missing' => '净收入字段缺失',
            'lead_time_fields_missing' => '提前预订字段缺失',
            'cancellation_fields_missing' => '取消字段缺失',
            'cancel_room_nights_missing' => '取消房晚缺失',
            'competitor_price_fields_missing' => '竞品价格字段缺失',
        ][$code] ?? ($code !== '' ? '未识别字段缺口' : '未命名缺口');
    }

    private function phase1MissingFieldBusinessImpact(string $code): string
    {
        return [
            'available_room_nights_missing' => '缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准。',
            'commission_fields_missing' => '缺佣金金额或佣金率，暂不能核算净收入和渠道成本。',
            'net_revenue_fields_missing' => '缺净收入输入，暂不能输出净 RevPAR 或真实到手收入。',
            'lead_time_fields_missing' => '缺提前预订天数，暂不能判断提前期结构和临近入住风险。',
            'cancellation_fields_missing' => '缺取消订单或取消金额，暂不能判断取消对收入的影响。',
            'cancel_room_nights_missing' => '缺取消房晚，暂不能计算房晚取消率。',
            'competitor_price_fields_missing' => '缺竞品价格，暂不能做竞品价差和调价判断。',
        ][$code] ?? '该缺口需要补齐字段定义或目标日样本后再判断。';
    }

    private function phase1MissingFieldNextAction(string $code, array $sourceKeys): string
    {
        if (preg_match('/available_room_nights|net_revenue|commission|lead_time|cancellation|cancel_room_nights|competitor_price/i', $code)) {
            return '按字段资产核对平台返回和入库字段，再重跑收益指标核验。';
        }

        if (in_array('missing_field_codes', $sourceKeys, true)) {
            return '按字段缺口清单补齐字段定义或样本证据。';
        }

        return '按数据缺口清单补齐目标日证据后复跑诊断。';
    }

    private function phase1MissingFieldSourceText(array $sourceKeys): string
    {
        $hasDataGap = in_array('data_gap_codes', $sourceKeys, true);
        $hasFieldGap = in_array('missing_field_codes', $sourceKeys, true);
        if ($hasDataGap && $hasFieldGap) {
            return '数据缺口 / 字段缺口';
        }
        if ($hasFieldGap) {
            return '字段缺口';
        }
        return '数据缺口';
    }

    private function phase1MetricDomainSummary(array $metricDomainReadiness, array $trafficSourceReadiness = []): array
    {
        $trafficSourceByPlatform = [];
        foreach ($trafficSourceReadiness as $source) {
            if (!is_array($source)) {
                continue;
            }
            $platformKey = strtolower(trim((string)($source['platform'] ?? '')));
            if ($platformKey !== '') {
                $trafficSourceByPlatform[$platformKey] = $source;
            }
        }

        $summary = [];
        foreach ($metricDomainReadiness as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            $sourceRows = max(0, (int)($row['source_rows'] ?? $row['target_date_rows'] ?? 0));
            $trafficRows = max(0, (int)($row['traffic_rows'] ?? 0));
            $revenueReady = (string)($row['revenue_status'] ?? '') === 'ready';
            $trafficReady = (string)($row['traffic_status'] ?? '') === 'ready';
            $conversionReady = (string)($row['conversion_status'] ?? '') === 'ready';
            $targetTypes = array_values(array_filter(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                (array)($row['target_date_data_types'] ?? $row['data_types'] ?? [])
            ), static fn(string $value): bool => $value !== ''));
            $dataTypeText = $this->phase1MetricDomainDataTypeListText($targetTypes);
            $missingDomains = array_values(array_unique(array_filter(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                (array)($row['missing_domains'] ?? [])
            ), static fn(string $value): bool => $value !== '')));
            $missingText = $this->phase1MetricDomainMissingListText($missingDomains);
            $trafficSource = $trafficSourceByPlatform[$platform] ?? [];

            $summary[] = [
                'platform' => $platform,
                'platform_label' => $this->phase1MetricDomainPlatformText($platform),
                'revenue_text' => $this->phase1MetricDomainStatusText((string)($row['revenue_status'] ?? 'missing')),
                'traffic_text' => $this->phase1MetricDomainStatusText((string)($row['traffic_status'] ?? 'missing')),
                'conversion_text' => $this->phase1MetricDomainStatusText((string)($row['conversion_status'] ?? 'missing')),
                'missing_text' => $missingText,
                'source_text' => '目标日源数据 ' . $sourceRows . ' 行 / 流量事实 ' . $trafficRows . ' 行',
                'traffic_source_text' => $trafficSource !== [] ? $this->phase1TrafficSourceReadinessText($trafficSource) : '',
                'traffic_source_next_action' => $trafficSource !== [] ? $this->phase1TrafficSourceNextActionText($trafficSource) : '',
                'problem' => $this->phase1MetricDomainProblemText($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
                'next_action' => $this->phase1MetricDomainNextActionText($revenueReady, $trafficReady, $conversionReady, $sourceRows, $trafficRows),
                'policy' => '只读目标日 OTA 指标域' . ($dataTypeText !== '' ? ' / ' . $dataTypeText : '') . '；缺失时不输出确定结论。',
            ];
        }

        return $summary;
    }

    private function phase1MetricDomainPlatformText(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => $platform !== '' ? 'OTA 平台' : 'OTA',
        };
    }

    private function phase1MetricDomainStatusText(string $status): string
    {
        return strtolower(trim($status)) === 'ready' ? '可复核' : '缺失';
    }

    private function phase1MetricDomainDataTypeListText(array $types): string
    {
        $labels = [];
        foreach ($types as $type) {
            $raw = strtolower(trim((string)$type));
            $labels[] = match (true) {
                in_array($raw, ['business', 'business_overview', 'revenue', 'order', 'orders'], true) => '经营/收益',
                in_array($raw, ['traffic', 'flow', 'flow_data'], true) => '流量/转化',
                in_array($raw, ['advertising', 'ads'], true) => '广告',
                in_array($raw, ['quality', 'quality_psi'], true) => '服务质量',
                in_array($raw, ['review', 'comment'], true) => '点评',
                $raw !== '' => '未识别数据类型',
                default => '',
            };
        }

        return implode('、', array_values(array_unique(array_filter($labels))));
    }

    private function phase1MetricDomainMissingListText(array $domains): string
    {
        $labels = [];
        foreach ($domains as $domain) {
            $labels[] = match (strtolower(trim((string)$domain))) {
                'revenue' => '收益',
                'traffic' => '流量',
                'conversion' => '转化',
                default => '',
            };
        }

        return implode('、', array_values(array_unique(array_filter($labels))));
    }

    private function phase1MetricDomainProblemText(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
    {
        if ($revenueReady && $trafficReady && $conversionReady) {
            return '收益、流量、转化均可复核。';
        }
        if ($sourceRows <= 0) {
            return '目标日源数据缺失，收益、流量、转化都不能证明。';
        }
        if ($revenueReady && (!$trafficReady || !$conversionReady || $trafficRows <= 0)) {
            return '收益可先复核；流量/转化缺失，不能判断曝光到下单漏斗。';
        }
        if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
            return '流量/转化缺失，不能判断曝光、访问或下单转化问题。';
        }
        return '收益指标缺失，不能输出收入问题结论。';
    }

    private function phase1MetricDomainNextActionText(bool $revenueReady, bool $trafficReady, bool $conversionReady, int $sourceRows, int $trafficRows): string
    {
        if ($revenueReady && $trafficReady && $conversionReady) {
            return '可进入 OTA 经营诊断。';
        }
        if ($sourceRows <= 0) {
            return '先补目标日 OTA 源数据，再复跑收益指标核验。';
        }
        if (!$revenueReady) {
            return '复核标准事实层和收益指标输入。';
        }
        if (!$trafficReady || !$conversionReady || $trafficRows <= 0) {
            return '补齐流量/转化事实，再复核漏斗诊断。';
        }
        return '按缺口补齐目标日证据后复跑诊断。';
    }

    private function phase1TrafficSourceReadinessText(array $source): string
    {
        $sourceCount = max(0, (int)($source['traffic_source_count'] ?? 0));
        $readyCount = max(0, (int)($source['traffic_ready_count'] ?? 0));
        $waitingCount = max(0, (int)($source['traffic_waiting_config_count'] ?? 0));
        $trafficRows = max(0, (int)($source['target_date_traffic_rows'] ?? 0));
        if ($trafficRows > 0) {
            return '目标日流量事实已入库';
        }
        if ($sourceCount <= 0) {
            return '流量采集源未登记';
        }
        if ($waitingCount > 0) {
            return '流量采集源已登记，仍待授权或配置';
        }
        if ($readyCount > 0) {
            return '流量采集源已就绪，但目标日流量事实未入库';
        }
        return '流量采集源已登记，但状态未就绪';
    }

    private function phase1TrafficSourceNextActionText(array $source): string
    {
        $sourceCount = max(0, (int)($source['traffic_source_count'] ?? 0));
        $readyCount = max(0, (int)($source['traffic_ready_count'] ?? 0));
        $waitingCount = max(0, (int)($source['traffic_waiting_config_count'] ?? 0));
        $trafficRows = max(0, (int)($source['target_date_traffic_rows'] ?? 0));
        if ($trafficRows > 0) {
            return '继续复核流量字段、来源路径和入库字段。';
        }
        if ($sourceCount <= 0) {
            return '先登记对应平台流量采集源，再补授权上下文。';
        }
        if ($waitingCount > 0) {
            return '补齐授权 Profile 或真实 Payload 后重新采集流量。';
        }
        if ($readyCount > 0) {
            return '运行对应平台流量采集并确认目标日入库行。';
        }
        return '检查采集源状态，修复后再执行流量采集。';
    }

    private function buildPhase1EmployeeQuestionRows(array $reliability): array
    {
        $sourceRowCount = $this->phase1CollectionEvidenceRowCount($reliability);
        $sourceDateEvidence = is_array($reliability['source_date_evidence'] ?? null) ? $reliability['source_date_evidence'] : [];
        $targetDateSourceRows = $this->phase1HasSourceDatePlatformEvidence($sourceDateEvidence)
            ? $this->phase1TargetDateSourceRowCount($sourceDateEvidence)
            : 0;
        $targetDatePlatformCoverage = $this->phase1TargetDatePlatformCoverage($sourceDateEvidence, $targetDateSourceRows, $sourceRowCount);
        $targetDateCoverageStatus = (string)($targetDatePlatformCoverage['status'] ?? 'missing');
        $targetDateMissingPlatforms = array_values(array_filter(array_map('strval', (array)($targetDatePlatformCoverage['missing_platforms'] ?? []))));
        $targetDateMissingPlatformText = implode('、', array_map('strtoupper', $targetDateMissingPlatforms));
        $sourceDateEvidenceMissing = (bool)($targetDatePlatformCoverage['source_date_evidence_missing'] ?? false);
        $fieldDefinitionCount = $this->phase1FieldDefinitionCount($reliability);
        $quality = is_array($reliability['data_quality'] ?? null) ? $reliability['data_quality'] : [];
        $qualityStatus = strtolower((string)($quality['status'] ?? ''));
        $missingFieldCount = (int)($quality['missing_count'] ?? 0);
        $fieldPendingCount = $this->phase1PendingActionCount($reliability, 'field');
        $fieldDefinitionKeys = $this->phase1FieldDefinitionKeys($reliability);
        $fieldPendingActionCodes = $this->phase1PendingActionCodes($reliability, 'field');
        $missingFieldCodes = $this->phase1DataQualityMissingFieldCodes($quality);
        $revenueMetricEvidence = is_array($reliability['phase1_revenue_metric_evidence'] ?? null)
            ? $reliability['phase1_revenue_metric_evidence']
            : $this->phase1RevenueMetricEvidence($reliability);
        $metricTrustKeys = array_values(array_filter(array_map('strval', (array)($revenueMetricEvidence['metric_trust_keys'] ?? []))));
        $dataGapCodes = array_values(array_filter(array_map('strval', (array)($revenueMetricEvidence['data_gap_codes'] ?? []))));
        $missingFieldSummary = $this->phase1MissingFieldSummary($dataGapCodes, $missingFieldCodes);
        $fieldTrustProved = $targetDateCoverageStatus === 'complete'
            && $fieldDefinitionCount > 0
            && $qualityStatus === 'ok'
            && $metricTrustKeys !== []
            && $missingFieldCount === 0
            && $fieldPendingCount === 0;
        $fieldTrustPartial = $fieldDefinitionCount > 0 || $targetDateSourceRows > 0;
        $pendingActionCount = count(is_array($reliability['pending_actions'] ?? null) ? $reliability['pending_actions'] : []);
        $hasFailure = $this->phase1HasCollectionFailure($reliability);
        $todayOtaStatus = match ($targetDateCoverageStatus) {
            'complete' => 'proved',
            'partial' => 'warning',
            'unknown' => $sourceRowCount > 0 ? 'warning' : 'not_proved',
            default => $hasFailure ? 'missing' : 'not_proved',
        };
        $todayOtaDetail = match ($targetDateCoverageStatus) {
            'complete' => '携程和美团目标日 OTA 数据均有入库证据。',
            'partial' => '目标日 OTA 数据只覆盖部分平台，缺失平台：' . ($targetDateMissingPlatformText !== '' ? $targetDateMissingPlatformText : '未识别'),
            'unknown' => '已有入库/回放参考，但缺少 source_date_evidence，不能证明目标日携程/美团均已采到。',
            default => '目标日尚未看到可证明已入库的携程/美团 OTA 数据。',
        };
        $todayOtaNextAction = match ($targetDateCoverageStatus) {
            'complete' => '继续复核字段可信度、收益指标和 AI 依据。',
            'partial' => '使用现有入口补齐缺失平台的同日数据后重新检查：' . ($targetDateMissingPlatformText !== '' ? $targetDateMissingPlatformText : '携程/美团'),
            'unknown' => '先补齐 collection-reliability.source_date_evidence；仍缺目标日平台行时，再使用现有携程/美团入口补数。',
            default => '使用现有携程/美团手动或自动获取入口补齐同日数据后重新检查。',
        };
        $metricDomainReadiness = $this->phase1MetricDomainReadiness($sourceDateEvidence, $targetDatePlatformCoverage);
        $trafficSourceReadiness = $this->phase1TrafficSourceReadiness($metricDomainReadiness);
        $platformFieldTrust = $this->phase1PlatformFieldTrust($metricDomainReadiness);
        $revenueReadyPlatforms = $this->phase1ReadyMetricPlatforms($metricDomainReadiness, 'revenue_status');
        $trafficReadyPlatforms = $this->phase1ReadyMetricPlatforms($metricDomainReadiness, 'traffic_status');
        $conversionReadyPlatforms = $this->phase1ReadyMetricPlatforms($metricDomainReadiness, 'conversion_status');
        $revenueMissingPlatforms = $this->phase1MissingMetricPlatforms($metricDomainReadiness, 'revenue_status');
        $trafficMissingPlatforms = $this->phase1MissingMetricPlatforms($metricDomainReadiness, 'traffic_status');
        $conversionMissingPlatforms = $this->phase1MissingMetricPlatforms($metricDomainReadiness, 'conversion_status');
        $metricDomainGapCodes = $this->phase1MetricDomainGapCodes($metricDomainReadiness);
        $metricDomainSummary = $this->phase1MetricDomainSummary($metricDomainReadiness, $trafficSourceReadiness);
        $revenueReadyText = implode('、', array_map('strtoupper', $revenueReadyPlatforms));
        $metricTrustReady = $metricTrustKeys !== [];
        $allMetricDomainsReady = $targetDateCoverageStatus === 'complete'
            && $metricDomainReadiness !== []
            && $metricTrustReady
            && count($revenueReadyPlatforms) === count($metricDomainReadiness)
            && count($trafficReadyPlatforms) === count($metricDomainReadiness)
            && count($conversionReadyPlatforms) === count($metricDomainReadiness);
        $metricProblemStatus = $allMetricDomainsReady
            ? 'proved'
            : ($targetDateSourceRows > 0 ? 'warning' : 'not_proved');
        $metricProblemDetail = match (true) {
            $allMetricDomainsReady => '收益、流量、转化均有目标日事实，可进入经营诊断。',
            !$metricTrustReady && $targetDateSourceRows > 0 => '已有目标日 OTA 数据样本，但 metric_trust 未输出时不能证明收益、流量或转化指标可信。',
            $revenueReadyPlatforms !== [] => '收益指标可先复核：' . ($revenueReadyText !== '' ? $revenueReadyText : '部分平台') . '；流量/转化事实不足时，不输出流量或转化确定结论。',
            $targetDateSourceRows > 0 => '已有目标日 OTA 数据样本，但收益、流量、转化指标域尚未全部证明。',
            default => '没有目标日入库样本时，不生成收入、流量或转化结论。',
        };
        $metricProblemNextAction = match (true) {
            $allMetricDomainsReady => '进入经营诊断，逐项引用 metric_trust、data_gaps 和目标日指标域证据。',
            !$metricTrustReady && $targetDateSourceRows > 0 => '打开收益指标，复核 metric_trust 和 data_gaps；未输出前不生成确定指标结论。',
            $revenueReadyPlatforms !== [] => '先复核收益指标；流量/转化结论必须等待目标日 traffic 事实补齐。',
            $targetDateSourceRows > 0 => '打开收益指标，复核 totals、traffic、metric_trust 和 data_gaps。',
            default => '先补齐同日 OTA 源数据和标准事实层。',
        };
        $aiEvidenceBlockers = $this->phase1AiEvidenceBlockers($targetDatePlatformCoverage);
        $aiEvidenceBlockerText = implode('、', array_map('strtoupper', $aiEvidenceBlockers));
        $aiQuestionBlockingCodes = $aiEvidenceBlockers !== [] ? $aiEvidenceBlockers : ['ai_diagnosis_evidence_sample_missing'];
        $aiActionGapCode = $aiEvidenceBlockers !== [] ? 'ai_action_items_blocked' : 'ai_action_items_missing';
        $operationExecutionEvidence = is_array($reliability['phase1_operation_execution_evidence'] ?? null)
            ? $reliability['phase1_operation_execution_evidence']
            : $this->phase1OperationExecutionEvidence($reliability);
        $operationEvidenceStatus = (string)($operationExecutionEvidence['operation_evidence_status'] ?? 'missing');
        $operationEvidenceBlockingCodes = array_values(array_filter(array_map(
            'strval',
            (array)($operationExecutionEvidence['blocking_missing_codes'] ?? [])
        )));
        $operationHasOtaDiagnosisLink = (int)($operationExecutionEvidence['ota_diagnosis_linked_intent_count'] ?? 0) > 0
            || (int)($operationExecutionEvidence['ota_diagnosis_linked_flow_item_count'] ?? 0) > 0;
        $operationBlockers = array_values(array_unique(array_merge(
            $aiEvidenceBlockers !== [] ? $aiEvidenceBlockers : [],
            $operationHasOtaDiagnosisLink ? [] : [$aiActionGapCode],
            $operationEvidenceBlockingCodes
        )));
        $operationQuestionStatus = $operationEvidenceStatus === 'proved' && $operationBlockers === []
            ? 'proved'
            : ($pendingActionCount > 0 || $operationEvidenceStatus === 'warning' || $operationEvidenceStatus === 'proved' ? 'warning' : 'missing');
        $operationQuestionDetail = match (true) {
            $operationQuestionStatus === 'proved' => '可执行 AI action_items 已进入执行意图，并已有审批、执行证据、复盘或 ROI 信号。',
            $operationEvidenceStatus === 'warning' => '已有执行意图或执行流记录，但还缺少 OTA 诊断关联、审批通过、执行证据或复盘信号。',
            $pendingActionCount > 0 => '已有采集或字段待办，但还不能等同于运营执行闭环完成。',
            default => '尚未看到基于 AI action_items 的执行意图、审批、执行证据或复盘样例。',
        };
        $operationNextAction = match (true) {
            $operationQuestionStatus === 'proved' => '继续跟进执行结果、复盘状态和 ROI 证据。',
            $operationEvidenceStatus === 'warning' => '补齐 OTA 诊断 action_items 关联、审批通过、执行证据或复盘状态；未补齐前不标记运营闭环完成。',
            $pendingActionCount > 0 => '先处理待办；真实经营动作需创建执行意图并保留审批、执行证据和复盘。',
            default => '先取得真实 OTA 诊断 action_items，再创建执行意图并保留审批、执行证据和复盘。',
        };

        return [
            [
                'key' => 'today_ota_collected',
                'question' => '今天 OTA 数据有没有采到',
                'status' => $todayOtaStatus,
                'detail' => $todayOtaDetail,
                'evidence' => [
                    'source_rows' => $sourceRowCount,
                    'target_date_source_rows' => $targetDateSourceRows,
                    'target_date_platform_coverage' => $targetDatePlatformCoverage,
                    'source_date_evidence_available' => !$sourceDateEvidenceMissing,
                    'source_date_evidence_missing' => $sourceDateEvidenceMissing,
                    'analysis_rows_reference_only' => 0,
                    'source_date_evidence' => $sourceDateEvidence,
                    'evidence_refs' => [
                        '/api/online-data/collection-reliability.collection_logs',
                        '/api/online-data/collection-reliability.history_replay',
                        '/api/online-data/collection-reliability.data_quality',
                        '/api/online-data/collection-reliability.source_date_evidence',
                    ],
                ],
                'next_action' => $todayOtaNextAction,
            ],
            [
                'key' => 'trusted_fields',
                'question' => '哪些字段可信',
                'status' => $fieldTrustProved ? 'proved' : ($fieldTrustPartial ? 'warning' : 'not_proved'),
                'detail' => $fieldTrustProved
                    ? '字段资产、目标日样例和数据质量状态均可用于判断字段可信度。'
                    : ($fieldDefinitionCount > 0
                        ? '字段资产和口径可查看，但仍需结合目标日入库样例、metric_trust 和数据质量状态复核。'
                        : '字段资产未加载时不能判定字段可信。'),
                'evidence' => [
                    'field_definition_count' => $fieldDefinitionCount,
                    'field_definition_keys' => $fieldDefinitionKeys,
                    'source_rows' => $sourceRowCount,
                    'target_date_source_rows' => $targetDateSourceRows,
                    'target_date_platform_coverage' => $targetDatePlatformCoverage,
                    'platform_field_trust' => $platformFieldTrust,
                    'data_quality_status' => $qualityStatus !== '' ? $qualityStatus : 'unknown',
                    'missing_field_count' => $missingFieldCount,
                    'field_pending_action_count' => $fieldPendingCount,
                    'field_pending_action_codes' => $fieldPendingActionCodes,
                    'revenue_metric_status' => (string)($revenueMetricEvidence['status'] ?? 'unknown'),
                    'metric_trust_key_count' => count($metricTrustKeys),
                    'metric_trust_keys' => $metricTrustKeys,
                    'data_gap_codes' => $dataGapCodes,
                    'revenue_metric_evidence_policy' => (string)($revenueMetricEvidence['source_policy'] ?? 'read_existing_ota_standard_revenue_metrics_only'),
                    'metric_trust_required' => true,
                    'field_trust_policy' => 'requires_target_date_rows_field_definitions_metric_trust_and_data_quality',
                    'evidence_refs' => [
                        '/api/online-data/collection-reliability.field_definitions',
                        '/api/online-data/collection-reliability.data_quality',
                        '/api/ota-standard/revenue-metrics.metric_trust',
                    ],
                ],
                'next_action' => $fieldTrustProved
                    ? '按字段资产、来源路径、metric_trust 和入库样例逐项复核。'
                    : ($targetDateCoverageStatus === 'complete'
                        ? '打开收益指标的 metric_trust 和数据质量缺口，逐项确认字段可信度。'
                        : '先补齐携程/美团同日源数据，再按字段资产、metric_trust 和数据质量状态判断可信度。'),
            ],
            [
                'key' => 'missing_fields',
                'question' => '哪些字段缺失',
                'status' => $missingFieldCount > 0 || $fieldPendingCount > 0 || $dataGapCodes !== [] ? 'proved' : ($qualityStatus === 'ok' ? 'warning' : 'not_proved'),
                'detail' => $missingFieldCount > 0 || $fieldPendingCount > 0 || $dataGapCodes !== [] ? '字段缺口已显式暴露，不能用 0 或空值代替。' : '当前没有字段缺口样例；未加载完整诊断时不能等同于无缺口。',
                'evidence' => [
                    'missing_field_count' => $missingFieldCount,
                    'missing_field_codes' => $missingFieldCodes,
                    'data_gap_count' => count($dataGapCodes),
                    'data_gap_codes' => $dataGapCodes,
                    'missing_field_summary' => $missingFieldSummary,
                    'data_gaps' => $revenueMetricEvidence['data_gaps'] ?? [],
                    'field_pending_action_count' => $fieldPendingCount,
                    'field_pending_action_codes' => $fieldPendingActionCodes,
                    'data_quality_status' => $qualityStatus !== '' ? $qualityStatus : 'unknown',
                    'revenue_metric_evidence_policy' => (string)($revenueMetricEvidence['source_policy'] ?? 'read_existing_ota_standard_revenue_metrics_only'),
                    'evidence_refs' => ['/api/online-data/collection-reliability.data_quality', '/api/ota-standard/revenue-metrics.data_gaps'],
                ],
                'next_action' => '按 data_gaps、字段资产和质量任务处理缺口，不写兜底值。',
            ],
            [
                'key' => 'revenue_traffic_conversion',
                'question' => '收入/流量/转化出了什么问题',
                'status' => $metricProblemStatus,
                'detail' => $metricProblemDetail,
                'evidence' => [
                    'source_rows' => $sourceRowCount,
                    'target_date_source_rows' => $targetDateSourceRows,
                    'target_date_platform_coverage' => $targetDatePlatformCoverage,
                    'metric_domain_readiness' => $metricDomainReadiness,
                    'metric_domain_summary' => $metricDomainSummary,
                    'traffic_source_readiness' => $trafficSourceReadiness,
                    'revenue_ready_platforms' => $revenueReadyPlatforms,
                    'traffic_ready_platforms' => $trafficReadyPlatforms,
                    'conversion_ready_platforms' => $conversionReadyPlatforms,
                    'revenue_missing_platforms' => $revenueMissingPlatforms,
                    'traffic_missing_platforms' => $trafficMissingPlatforms,
                    'conversion_missing_platforms' => $conversionMissingPlatforms,
                    'metric_domain_gap_codes' => $metricDomainGapCodes,
                    'metric_trust_key_count' => count($metricTrustKeys),
                    'metric_trust_keys' => $metricTrustKeys,
                    'data_gap_count' => count($dataGapCodes),
                    'data_gap_codes' => $dataGapCodes,
                    'revenue_metric_status' => (string)($revenueMetricEvidence['status'] ?? 'unknown'),
                    'metric_trust_required' => true,
                    'metric_domain_policy' => 'read_target_date_online_daily_data_types_only',
                    'traffic_source_policy' => 'read_platform_data_sources_metadata_only',
                    'analysis_rows_reference_only' => 0,
                    'evidence_refs' => ['/api/ota-standard/revenue-metrics', 'platform_data_sources.metadata'],
                ],
                'next_action' => $metricProblemNextAction,
            ],
            [
                'key' => 'ai_evidence',
                'question' => 'AI 建议依据是什么',
                'status' => $aiEvidenceBlockers !== [] ? 'warning' : 'missing',
                'detail' => $aiEvidenceBlockers !== [] ? '上游 OTA 证据未闭合，不能生成确定 AI 建议依据。' : '采集健康接口不包含真实 OTA 诊断响应，不能替代 AI evidence_sources。',
                'evidence' => [
                    'upstream_blockers' => $aiEvidenceBlockers,
                    'blocking_missing_codes' => $aiQuestionBlockingCodes,
                    'diagnosis_status' => $aiEvidenceBlockers !== [] ? 'blocked_by_verified_ota_gaps' : 'missing_real_api_response',
                    'action_item_status' => $aiEvidenceBlockers !== [] ? 'blocked_by_verified_ota_gaps' : 'missing',
                    'source_policy' => $aiEvidenceBlockers !== [] ? 'read_existing_ota_gap_evidence_only' : 'missing_real_ota_diagnosis_response',
                    'target_date_platform_coverage' => $targetDatePlatformCoverage,
                    'evidence_refs' => ['/api/agent/ota-diagnosis.evidence_sources', '/api/agent/ota-diagnosis.data_gaps', '/api/agent/ota-diagnosis.action_items'],
                ],
                'next_action' => $aiEvidenceBlockers !== []
                    ? '先处理当前阻断项后再调用 OTA 诊断：' . ($aiEvidenceBlockerText !== '' ? $aiEvidenceBlockerText : 'OTA 证据缺口')
                    : '调用现有 OTA 诊断接口，并确认返回 evidence_sources、data_gaps、action_items。',
            ],
            [
                'key' => 'next_operation_action',
                'question' => '下一步该执行什么动作',
                'status' => $operationQuestionStatus,
                'detail' => $operationQuestionDetail,
                'evidence' => array_merge($operationExecutionEvidence, [
                    'pending_action_count' => $pendingActionCount,
                    'upstream_blockers' => $operationBlockers,
                    'operation_blocking_missing_codes' => $operationEvidenceBlockingCodes,
                    'blocking_missing_codes' => $operationBlockers,
                    'evidence_refs' => ['/api/online-data/collection-reliability.pending_actions', '/api/operation/execution-intents', '/api/operation/execution-flow'],
                ]),
                'next_action' => $operationNextAction,
            ],
        ];
    }

    private function buildPhase1NextRequiredActions(array $rows): array
    {
        $rowsByKey = [];
        foreach ($rows as $row) {
            if (is_array($row) && (string)($row['key'] ?? '') !== '') {
                $rowsByKey[(string)$row['key']] = $row;
            }
        }

        $actions = [];
        $todayEvidence = is_array($rowsByKey['today_ota_collected']['evidence'] ?? null) ? $rowsByKey['today_ota_collected']['evidence'] : [];
        $coverage = is_array($todayEvidence['target_date_platform_coverage'] ?? null) ? $todayEvidence['target_date_platform_coverage'] : [];
        if ((bool)($coverage['source_date_evidence_missing'] ?? false)) {
            $actions[] = $this->phase1NextRequiredAction([
                'action_code' => 'phase1_confirm_source_date_evidence',
                'type' => 'evidence_gap',
                'priority' => 'high',
                'status' => 'missing',
                'platform' => 'ota',
                'question_key' => 'today_ota_collected',
                'reason' => '缺少 source_date_evidence，入库/回放参考不能证明目标日携程/美团均已采到。',
                'action' => '重新加载 collection-reliability.source_date_evidence；若仍缺平台目标日行，再使用现有携程/美团手动或自动获取入口补齐。',
                'entry' => '/api/online-data/collection-reliability',
                'owner' => '产品/技术',
                'evidence_needed' => ['source_date_evidence.platforms', 'target_date_rows', 'target_date_data_types'],
                'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
            ]);
        }
        $missingPlatforms = array_values(array_filter(array_map('strval', (array)($coverage['missing_platforms'] ?? []))));
        if ($missingPlatforms === [] && (string)($coverage['status'] ?? 'missing') !== 'complete' && !(bool)($coverage['source_date_evidence_missing'] ?? false)) {
            $missingPlatforms = ['ctrip', 'meituan'];
        }
        foreach ($missingPlatforms as $platform) {
            $platform = strtolower(trim($platform));
            if ($platform === '') {
                continue;
            }
            $actions[] = $this->phase1NextRequiredAction([
                'action_code' => 'phase1_collect_' . $platform . '_target_date_source_rows',
                'type' => 'collection_gap',
                'priority' => 'high',
                'status' => 'missing',
                'platform' => $platform,
                'question_key' => 'today_ota_collected',
                'reason' => strtoupper($platform) . ' 目标日 OTA 源数据未闭合。',
                'action' => '使用现有' . strtoupper($platform) . '手动或自动获取入口补齐目标日 OTA 数据后重新巡检。',
                'entry' => $this->phase1TargetDateSourceRowsActionEntry($platform),
                'entry_options' => $this->phase1TargetDateSourceRowsActionEntryOptions($platform),
                'owner' => '酒店运营人员',
                'evidence_needed' => ['online_daily_data 同日期源数据行', 'data_source_id 或 sync_task_id', 'source_trace_id 或 raw_data 追踪证据'],
                'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
            ]);
        }

        $metricEvidence = is_array($rowsByKey['revenue_traffic_conversion']['evidence'] ?? null) ? $rowsByKey['revenue_traffic_conversion']['evidence'] : [];
        $metricTrustMissing = (int)($metricEvidence['metric_trust_key_count'] ?? 0) <= 0;
        foreach ((array)($metricEvidence['metric_domain_readiness'] ?? []) as $domain) {
            if (!is_array($domain)) {
                continue;
            }
            $platform = strtolower(trim((string)($domain['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            $targetRows = max(0, (int)($domain['target_date_rows'] ?? 0));
            $blockedBy = $targetRows > 0 ? [] : [$platform . '_target_date_source_rows_missing'];
            $revenueMetricInputMissing = ($domain['revenue_status'] ?? '') !== 'ready' || $metricTrustMissing;
            if ($revenueMetricInputMissing && $targetRows > 0) {
                $actions[] = $this->phase1NextRequiredAction([
                    'action_code' => 'phase1_check_' . $platform . '_revenue_metric_inputs',
                    'type' => 'metric_gap',
                    'priority' => 'medium',
                    'status' => 'missing',
                    'platform' => $platform,
                    'question_key' => 'revenue_traffic_conversion',
                    'reason' => strtoupper($platform) . ($metricTrustMissing ? ' 目标日源数据存在，但 metric_trust 未输出。' : ' 目标日源数据存在，但收益指标域未就绪。'),
                    'action' => '复核目标日标准事实层是否包含 amount、quantity、book_order_num、metric_trust 和 data_gaps。',
                    'entry' => '/api/ota-standard/revenue-metrics',
                    'owner' => '收益运营人员',
                    'evidence_needed' => ['amount', 'quantity 或 room_nights', 'book_order_num 或 order_count', 'metric_trust', 'data_gaps'],
                    'resolves_missing_codes' => array_values(array_filter([
                        $platform . '_revenue_metric_inputs_missing',
                        $metricTrustMissing ? $platform . '_metric_trust_missing' : null,
                    ])),
                    'protected_boundary' => '只检查下游标准化和指标证据，不使用 0 或伪成功值填补缺失指标。',
                ]);
            }
            if (($domain['traffic_status'] ?? '') !== 'ready' || ($domain['conversion_status'] ?? '') !== 'ready') {
                $actions[] = $this->phase1NextRequiredAction([
                    'action_code' => 'phase1_confirm_' . $platform . '_traffic_conversion_facts',
                    'type' => 'metric_gap',
                    'priority' => $targetRows > 0 ? 'high' : 'medium',
                    'status' => $targetRows > 0 ? 'missing' : 'blocked',
                    'platform' => $platform,
                    'question_key' => 'revenue_traffic_conversion',
                    'reason' => strtoupper($platform) . ' 目标日流量/转化事实不足。',
                    'action' => '确认目标日流量数据是否已通过现有入口采到；未采到时，流量/转化诊断保持不可用。',
                    'entry' => $this->phase1TrafficConversionFactsActionEntry($platform),
                    'entry_options' => $this->phase1TrafficConversionFactsActionEntryOptions($platform),
                    'owner' => 'OTA 运营人员',
                    'evidence_needed' => ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num 或 order_submit_num'],
                    'blocked_by' => $blockedBy,
                    'protected_boundary' => '不从只有收益的数据行推断流量或转化问题，不改变采集字段和字段映射。',
                ]);
            }
        }

        $aiEvidence = is_array($rowsByKey['ai_evidence']['evidence'] ?? null) ? $rowsByKey['ai_evidence']['evidence'] : [];
        if (($rowsByKey['ai_evidence']['status'] ?? '') !== 'proved') {
            $aiActionBlockers = array_values(array_filter(array_map('strval', (array)($aiEvidence['upstream_blockers'] ?? []))));
            $actions[] = $this->phase1NextRequiredAction([
                'action_code' => 'phase1_collect_ai_diagnosis_evidence',
                'type' => 'ai_evidence_gap',
                'priority' => 'high',
                'status' => $aiActionBlockers === [] ? 'missing' : 'blocked',
                'platform' => 'ctrip,meituan',
                'question_key' => 'ai_evidence',
                'reason' => 'OTA 诊断 evidence_sources、data_gaps 或 action_items 尚未闭合。',
                'action' => $aiActionBlockers === []
                    ? '调用现有 OTA 诊断并保留脱敏 evidence_sources、data_gaps 和 action_items。'
                    : '先处理上游 OTA 数据阻断项，再调用现有 OTA 诊断并保留脱敏证据。',
                'entry' => '/api/agent/ota-diagnosis',
                'owner' => 'AI 运营人员',
                'evidence_needed' => ['evidence_sources', 'data_gaps', 'action_items'],
                'blocked_by' => $aiActionBlockers,
                'protected_boundary' => 'AI 建议必须引用 OTA 证据，不能把缺失数据写成确定结论。',
            ]);
        }

        $nextActionEvidence = is_array($rowsByKey['next_operation_action']['evidence'] ?? null) ? $rowsByKey['next_operation_action']['evidence'] : [];
        if (($rowsByKey['next_operation_action']['status'] ?? '') !== 'proved') {
            $operationGapCodes = array_values(array_filter(array_map(
                'strval',
                (array)($nextActionEvidence['operation_blocking_missing_codes'] ?? [])
            )));
            if ($operationGapCodes === []) {
                $operationGapCodes = array_values(array_intersect(
                    array_values(array_filter(array_map('strval', (array)($nextActionEvidence['blocking_missing_codes'] ?? [])))),
                    ['operation_execution_sample_missing', 'operation_execution_ai_action_link_missing', 'operation_execution_evidence_incomplete']
                ));
            }
            if ($operationGapCodes === []) {
                $operationGapCodes = ['operation_execution_sample_missing'];
            }
            $operationActionBlockers = array_values(array_diff(
                array_values(array_filter(array_map('strval', (array)($nextActionEvidence['upstream_blockers'] ?? ['ai_action_items_missing'])))),
                $operationGapCodes
            ));
            $operationHasAiActionLinkGap = in_array('operation_execution_ai_action_link_missing', $operationGapCodes, true);
            $operationHasEvidenceIncompleteGap = in_array('operation_execution_evidence_incomplete', $operationGapCodes, true);
            $actions[] = $this->phase1NextRequiredAction([
                'action_code' => 'phase1_create_operation_execution_evidence',
                'type' => 'operation_execution_gap',
                'priority' => 'medium',
                'status' => $operationActionBlockers === [] ? 'missing' : 'blocked',
                'platform' => 'ctrip,meituan',
                'question_key' => 'next_operation_action',
                'reason' => $operationHasAiActionLinkGap
                    ? '已有执行意图或执行流程，但还不能追溯到 OTA 诊断 action_items。'
                    : ($operationHasEvidenceIncompleteGap
                        ? '已有 OTA 诊断关联执行流，但审批、执行证据、复盘或 ROI 信号未闭合。'
                        : '尚未看到基于真实 AI action_items 的执行意图、审批、执行证据或复盘。'),
                'action' => $operationHasAiActionLinkGap
                    ? '补齐执行意图或执行流程的 source_module=ota_diagnosis、source、evidence_refs 或 action_item_id 关联。'
                    : ($operationHasEvidenceIncompleteGap
                        ? '补齐审批通过、执行完成、执行证据、复盘状态或 ROI 任一完成信号。'
                        : '取得真实 OTA 诊断 action_items 后，创建执行意图并保留审批、执行证据和复盘状态。'),
                'entry' => '/api/operation/execution-intents',
                'owner' => '运营负责人',
                'evidence_needed' => $operationHasAiActionLinkGap
                    ? ['source_module=ota_diagnosis 或 source=ota_diagnosis#action_item', 'evidence_refs 或 action_item_id', 'approval.status=approved、execution.status=executed、evidence.count>0 或 review.status']
                    : ['execution_intents 或 execution_flow', 'approval.status=approved', 'execution.status=executed 或 evidence.count>0', 'review.status 或 ROI 复盘状态'],
                'blocked_by' => $operationActionBlockers,
                'resolves_missing_codes' => $operationGapCodes,
                'protected_boundary' => $operationHasAiActionLinkGap
                    ? '只补齐执行证据关联，不改携程/美团采集字段和采集逻辑。'
                    : '动作可以处于待审批状态；不能只凭 AI 建议卡片标记闭环完成。',
            ]);
        }

        return $this->sortPhase1NextRequiredActions($this->dedupePhase1NextRequiredActions($actions));
    }

    private function phase1TargetDateSourceRowsActionEntry(string $platform): string
    {
        return match (strtolower($platform)) {
            'ctrip' => '/api/online-data/fetch-ctrip-overview',
            'meituan' => '/api/online-data/fetch-meituan',
            default => '/api/online-data/collection-reliability',
        };
    }

    private function phase1TrafficConversionFactsActionEntry(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'ctrip' => '/api/online-data/fetch-ctrip-traffic',
            'meituan' => '/api/online-data/fetch-meituan-traffic',
            default => '/api/online-data/collection-reliability',
        };
    }

    private function phase1TrafficConversionFactsActionEntryOptions(string $platform): array
    {
        $platform = strtolower(trim($platform));
        $options = match ($platform) {
            'ctrip' => [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => 'Ctrip traffic manual Cookie/API',
                    'entry' => '/api/online-data/fetch-ctrip-traffic',
                    'use_when' => 'Use when authorized traffic URL, payload/query params, auth context, and platform hotel id are available.',
                    'requires' => 'target_date, system_hotel_id, ctrip hotel/node id, authorized Cookie/headers, traffic payload/query params.',
                    'boundary' => 'Does not auto-login to Ctrip, does not infer missing traffic fields, and does not expose raw Cookie/token values.',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => 'Ctrip browser Profile',
                    'entry' => '/api/online-data/capture-ctrip-browser',
                    'use_when' => 'Use when an authorized local Ctrip browser Profile exists and the page must trigger traffic JSON responses.',
                    'requires' => 'target_date, system_hotel_id, authorized Ctrip Profile, manually verified login state, traffic response listener.',
                    'boundary' => 'Does not bypass captcha, SMS, human verification, or platform permissions.',
                ],
                [
                    'mode' => 'status_check',
                    'label' => 'Status check',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => 'Verify target-date rows, latest available date, and missing reasons only.',
                    'requires' => 'Existing collection reliability and online_daily_data state.',
                    'boundary' => 'Read-only; does not write OTA data or alter field mappings.',
                ],
            ],
            'meituan' => [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => 'Meituan traffic manual Cookie/API',
                    'entry' => '/api/online-data/fetch-meituan-traffic',
                    'use_when' => 'Use when authorized traffic URL/CDP endpoint evidence, payload/query params, auth context, and POI id are available.',
                    'requires' => 'target_date, system_hotel_id, Meituan POI/partner id, authorized Cookie/headers, traffic payload/query params.',
                    'boundary' => 'Does not auto-login to Meituan, does not infer missing traffic fields, and does not expose raw Cookie/token values.',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => 'Meituan browser Profile',
                    'entry' => '/api/online-data/capture-meituan-browser',
                    'use_when' => 'Use when an authorized local Meituan browser Profile exists and the page must trigger traffic JSON responses.',
                    'requires' => 'target_date, system_hotel_id, authorized Meituan Profile, manually verified login state, traffic response listener.',
                    'boundary' => 'Does not bypass captcha, SMS, human verification, or platform permissions.',
                ],
                [
                    'mode' => 'status_check',
                    'label' => 'Status check',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => 'Verify target-date rows, latest available date, and missing reasons only.',
                    'requires' => 'Existing collection reliability and online_daily_data state.',
                    'boundary' => 'Read-only; does not write OTA data or alter field mappings.',
                ],
            ],
            default => [],
        };

        return array_values(array_map(function (array $option) use ($platform): array {
            $mode = (string)($option['mode'] ?? '');
            $contract = $this->phase1TrafficInputContract($platform, $mode);
            if ($contract !== []) {
                $option['input_contract'] = $contract;
                $option['acceptance_contract'] = $this->phase1TrafficAcceptanceContract();
            }
            $option['readiness'] = $this->phase1TargetDateEntryOptionReadiness($platform, $mode);
            return $option;
        }, $options));
    }

    private function phase1TrafficInputContract(string $platform, string $mode): array
    {
        $platform = strtolower(trim($platform));
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['manual_cookie_api', 'browser_profile'], true)) {
            return [];
        }

        $contract = [
            'scope_policy' => 'ota_channel_only',
            'target_storage_table' => 'online_daily_data',
            'target_data_type' => 'traffic',
            'required_metric_keys' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ],
            'required_storage_fields' => [
                'online_daily_data.list_exposure',
                'online_daily_data.detail_exposure',
                'online_daily_data.flow_rate',
                'online_daily_data.order_filling_num',
                'online_daily_data.order_submit_num',
            ],
            'required_field_fact_keys' => [
                'capture_evidence',
                'source_path',
                'metric_key',
                'storage_field',
                'stored_value_present',
            ],
            'sensitive_values_allowed' => false,
        ];

        if ($mode === 'manual_cookie_api') {
            $contract['required_inputs'] = [
                'target_date',
                'system_hotel_id',
                $platform === 'ctrip' ? 'ctrip_hotel_id_or_node_id' : 'meituan_poi_id_or_partner_id',
                'authorized_cookie_or_headers',
                'traffic_request_url_or_cdp_endpoint_evidence',
                'traffic_payload_or_query_params',
                'desensitized_traffic_response_sample_or_source_trace_id',
            ];
            return $contract;
        }

        $contract['required_inputs'] = [
            'target_date',
            'system_hotel_id',
            'authorized_' . $platform . '_profile_dir',
            'manual_login_state_verified',
            'traffic_response_listener',
            'desensitized_traffic_response_sample_or_source_trace_id',
        ];
        return $contract;
    }

    private function phase1TrafficAcceptanceContract(): array
    {
        return [
            'target_date_traffic_rows' => '>0',
            'field_facts_status' => 'ready',
            'required_chain' => [
                'capture_evidence',
                'source_path',
                'metric_key',
                'storage_field',
                'stored_value',
                'ui_status',
                'verifier',
            ],
        ];
    }

    private function phase1TargetDateSourceRowsActionEntryOptions(string $platform): array
    {
        $platform = strtolower($platform);
        $options = match ($platform) {
            'ctrip' => [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动 Cookie/API',
                    'entry' => '/api/online-data/fetch-ctrip-overview',
                    'use_when' => '已取得携程 Cookie、Payload 或必要参数，需要临时补齐目标日经营概况。',
                    'requires' => '用户提供授权上下文、平台酒店标识和目标日期。',
                    'boundary' => '不自动登录携程后台，不启动浏览器 Profile，不改变采集字段。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-ctrip-browser',
                    'use_when' => '门店携程浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                    'requires' => '本地 Profile 存在且携程账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ],
            'meituan' => [
                [
                    'mode' => 'manual_cookie_api',
                    'label' => '手动 Cookie/API',
                    'entry' => '/api/online-data/fetch-meituan',
                    'use_when' => '已取得美团 Cookie、Session、POI 或必要 Payload，需要临时补齐目标日数据。',
                    'requires' => '用户提供授权上下文、门店/POI 标识和目标日期。',
                    'boundary' => '不代登录美团后台，不启动浏览器 Profile，不改变采集字段。',
                ],
                [
                    'mode' => 'browser_profile',
                    'label' => '浏览器 Profile',
                    'entry' => '/api/online-data/capture-meituan-browser',
                    'use_when' => '门店美团浏览器 Profile 已登录授权，需要走现有自动采集路径。',
                    'requires' => '本地 Profile 存在且美团账号登录态有效。',
                    'boundary' => '不绕过验证码、短信或人机验证，不改变自动采集逻辑。',
                ],
                [
                    'mode' => 'status_check',
                    'label' => '状态核对',
                    'entry' => '/api/online-data/collection-reliability',
                    'use_when' => '只核对目标日是否已有入库行、最近可用日期和失败原因。',
                    'requires' => '读取现有采集可靠性和 online_daily_data 状态。',
                    'boundary' => '只读状态，不写 OTA 数据，不改变字段映射。',
                ],
            ],
            default => [],
        };
        return array_values(array_map(
            fn(array $option): array => array_merge($option, [
                'readiness' => $this->phase1TargetDateEntryOptionReadiness($platform, (string)($option['mode'] ?? '')),
            ]),
            $options
        ));
    }

    private function phase1TargetDateEntryOptionReadiness(string $platform, string $mode): array
    {
        $platform = strtolower(trim($platform));
        $mode = strtolower(trim($mode));
        if ($mode === 'status_check') {
            return [
                'status' => 'ready',
                'label' => '可直接只读核对',
                'can_run_now' => true,
                'reason' => '只读取 collection-reliability 和 online_daily_data 状态，不写 OTA 数据。',
                'evidence' => 'read_existing_collection_reliability_only',
            ];
        }
        if ($mode === 'browser_profile') {
            $profileCount = $this->phase1BrowserProfileDirectoryCount($platform);
            return [
                'status' => $profileCount > 0 ? 'profile_found_login_unverified' : 'profile_missing',
                'label' => $profileCount > 0 ? '发现 Profile，登录态需复核' : '未发现本机 Profile',
                'can_run_now' => false,
                'reason' => $profileCount > 0
                    ? '本机存在 Profile 目录，但仍需人工确认平台账号登录态有效。'
                    : '未发现对应平台 Profile 目录，需先按现有自动采集流程完成授权登录。',
                'evidence' => 'storage_profile_directory_count',
                'profile_count' => $profileCount,
                'source_policy' => 'read_local_profile_directory_names_only',
            ];
        }
        return [
            'status' => 'requires_user_context',
            'label' => '需提供授权上下文',
            'can_run_now' => false,
            'reason' => '需要用户提供 Cookie/Payload/门店标识等授权上下文后才能调用现有手动入口。',
            'evidence' => 'user_supplied_cookie_or_payload_required',
        ];
    }

    private function phase1BrowserProfileDirectoryCount(string $platform): int
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return 0;
        }
        $pattern = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $platform . '_profile_*';
        $dirs = glob($pattern, GLOB_ONLYDIR);
        return is_array($dirs) ? count($dirs) : 0;
    }

    private function phase1NextRequiredAction(array $action): array
    {
        $action['next_action'] = (string)($action['next_action'] ?? $action['action'] ?? '');
        $action['action_family'] = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));
        $action['related_question_keys'] = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($action['related_question_keys'] ?? $this->phase1NextRequiredActionRelatedQuestionKeys($action))
        ))));
        $action['success_criteria'] = (string)($action['success_criteria'] ?? $this->phase1NextRequiredActionSuccessCriteria($action));
        $action['priority'] = (string)($action['priority'] ?? 'medium');
        $action['status'] = (string)($action['status'] ?? 'missing');
        $action['blocked_by'] = array_values(array_filter(array_map('strval', (array)($action['blocked_by'] ?? []))));
        $action['resolves_missing_codes'] = array_values(array_filter(array_map(
            'strval',
            (array)($action['resolves_missing_codes'] ?? $this->phase1NextRequiredActionResolvesMissingCodes($action))
        )));
        $action['live_closure_gap_codes'] = $this->phase1NextRequiredActionLiveClosureGapCodes($action);
        $action['blocked_by_action_codes'] = $this->phase1NextRequiredActionBlockedByActionCodes(
            $action['blocked_by'],
            (string)($action['action_code'] ?? '')
        );
        $explanation = $this->phase1NextRequiredActionEmployeeExplanation($action);
        if ($explanation !== []) {
            $action['employee_explanation'] = (string)($explanation['employee_explanation'] ?? '');
            $action['limited_conclusions'] = array_values(array_filter(
                array_map('strval', (array)($explanation['limited_conclusions'] ?? [])),
                static fn(string $value): bool => $value !== ''
            ));
            $action['still_usable_metrics'] = array_values(array_filter(
                array_map('strval', (array)($explanation['still_usable_metrics'] ?? [])),
                static fn(string $value): bool => $value !== ''
            ));
            $action['explanation_next_action'] = (string)($explanation['explanation_next_action'] ?? '');
        }
        $action['employee_action'] = $this->phase1EmployeeReadableCopy((string)($action['employee_action'] ?? $action['next_action'] ?? $action['action'] ?? ''));
        $action['employee_evidence_needed'] = array_values(array_filter(array_map(
            fn($value): string => $this->phase1EmployeeReadableCopy((string)$value),
            (array)($action['employee_evidence_needed'] ?? $action['evidence_needed'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        $action['employee_success_criteria'] = $this->phase1EmployeeReadableCopy((string)($action['employee_success_criteria'] ?? $action['success_criteria'] ?? ''));
        $action['employee_explanation_next_action'] = $this->phase1EmployeeReadableCopy((string)($action['employee_explanation_next_action'] ?? $action['explanation_next_action'] ?? ''));
        $action['employee_verification_steps'] = array_values(array_filter(array_map(
            fn($value): string => $this->phase1EmployeeReadableCopy((string)$value),
            (array)($action['employee_verification_steps'] ?? $this->phase1NextRequiredActionEmployeeVerificationSteps($action))
        ), static fn(string $value): bool => $value !== ''));
        if ((string)($action['action_code'] ?? '') === 'resolve_ai_diagnosis_blocked_action_items') {
            $action['employee_action'] = 'AI 动作项被上游缺口阻断；先处理上游 OTA 缺口后重新生成非阻断动作项。';
        }
        return $action;
    }

    private function phase1NextRequiredActionEmployeeVerificationSteps(array $action): array
    {
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));
        $platformLabel = $this->phase1NextRequiredActionPlatformLabel($action);

        return match ($family) {
            'target_date_source_rows' => [
                '刷新数据健康页的员工六问闭环。',
                '确认' . $platformLabel . '目标日入库行数大于 0。',
                '确认相关未完成问题和巡检缺口减少。',
            ],
            'standard_facts' => [
                '刷新员工六问里的收入、流量和转化问题。',
                '确认' . $platformLabel . '标准事实层变为可复核。',
                '确认字段可信或收益指标不再被该项阻断。',
            ],
            'revenue_metric_inputs' => [
                '刷新收入、流量和转化问题。',
                '确认' . $platformLabel . '收益输入变为可复核。',
                '确认 AI 依据不再因为该收益缺口被阻断。',
            ],
            'traffic_conversion_facts' => [
                '刷新收入、流量和转化问题。',
                '确认' . $platformLabel . '流量/转化事实变为可复核。',
                '确认漏斗判断不再显示该平台流量/转化缺口。',
            ],
            'ai_diagnosis_evidence' => [
                '重新运行现有 OTA 诊断。',
                '确认 AI 动作项不再被上游缺口阻断。',
                '确认 AI 依据仍保留证据来源和数据缺口说明。',
            ],
            'operation_execution_evidence' => [
                '刷新运营执行闭环摘要。',
                '确认执行意图能追溯到 OTA 诊断动作。',
                '确认出现审批、执行证据、复盘或 ROI 信号。',
            ],
            'evidence_scope' => [
                '刷新员工六问闭环。',
                '确认目标日期和证据日期一致。',
                '确认最近可用数据没有替代目标日证明。',
            ],
            default => [
                '刷新员工六问闭环。',
                '确认该动作对应缺口从未证明变为可复核。',
            ],
        };
    }

    private function phase1NextRequiredActionLiveClosureGapCodes(array $action): array
    {
        $code = (string)($action['action_code'] ?? '');
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));
        $platform = strtolower(trim((string)($action['platform'] ?? '')));
        $singlePlatform = in_array($platform, ['ctrip', 'meituan'], true) ? $platform : '';

        if ($singlePlatform !== '') {
            return match ($family) {
                'target_date_source_rows' => [$singlePlatform . '_source_rows_missing'],
                'revenue_metric_inputs' => [$singlePlatform . '_revenue_metrics_not_ready', $singlePlatform . '_metric_trust_missing'],
                'traffic_conversion_facts' => [$singlePlatform . '_traffic_facts_missing'],
                default => [],
            };
        }

        if ($code === 'phase1_collect_ai_diagnosis_evidence') {
            return $action['blocked_by'] === []
                ? ['ai_diagnosis_evidence_sample_missing']
                : ['ai_diagnosis_action_items_blocked'];
        }

        if ($code === 'phase1_create_operation_execution_evidence') {
            $codes = array_values(array_intersect(
                array_values(array_filter(array_map('strval', (array)($action['resolves_missing_codes'] ?? [])))),
                ['operation_execution_sample_missing', 'operation_execution_ai_action_link_missing', 'operation_execution_evidence_incomplete']
            ));
            return $codes !== [] ? $codes : ['operation_execution_sample_missing'];
        }

        return [];
    }

    private function phase1NextRequiredActionPlatformLabel(array $action): string
    {
        $platform = strtolower(trim((string)($action['platform'] ?? '')));
        if (str_contains($platform, ',')) {
            return '携程/美团';
        }

        return match ($platform) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => $platform !== '' ? strtoupper($platform) : 'OTA',
        };
    }

    private function phase1NextRequiredActionEmployeeExplanation(array $action): array
    {
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));
        $platformLabel = $this->phase1NextRequiredActionPlatformLabel($action);

        return match ($family) {
            'target_date_source_rows' => [
                'employee_explanation' => $platformLabel . '目标日 OTA 源数据未闭合，不能证明今天对应平台数据已采到。',
                'limited_conclusions' => [
                    $platformLabel . '目标日收入',
                    $platformLabel . '目标日流量',
                    $platformLabel . '目标日转化',
                    $platformLabel . '字段可信度',
                    'AI 诊断确定结论',
                ],
                'still_usable_metrics' => [
                    '最近可用历史数据只能作参考，不能替代目标日数据。',
                    '其它已采到平台的同日 OTA 指标可按平台单独复核。',
                ],
                'explanation_next_action' => '使用现有' . $platformLabel . '手动或自动获取入口补齐目标日源数据后复跑员工六问。',
            ],
            'revenue_metric_inputs' => [
                'employee_explanation' => $platformLabel . '收益指标输入未闭合，不能判断收入、间夜、订单或客单等收益结论。',
                'limited_conclusions' => [
                    $platformLabel . '收益',
                    $platformLabel . 'ADR',
                    $platformLabel . '订单',
                    $platformLabel . '间夜',
                    $platformLabel . '相关 AI 建议',
                ],
                'still_usable_metrics' => [
                    '已存在的目标日源数据行。',
                    '其它已 ready 平台的收益指标可单独复核。',
                ],
                'explanation_next_action' => '复核目标日标准事实层、metric_trust 和 data_gaps，不使用 0 或伪成功值填补缺失指标。',
            ],
            'traffic_conversion_facts' => [
                'employee_explanation' => $platformLabel . '目标日缺少流量/转化事实，不能判断曝光、访问、下单链路是否异常。',
                'limited_conclusions' => [
                    $platformLabel . '流量',
                    $platformLabel . '转化率',
                    $platformLabel . '漏斗诊断',
                    'AI 对流量问题的确定结论',
                ],
                'still_usable_metrics' => [
                    $platformLabel . '已采到且 metric_trust 明确可信的收益事实（如存在）。',
                    '其它平台已就绪的同日指标。',
                ],
                'explanation_next_action' => '使用现有' . $platformLabel . '流量获取入口补齐目标日流量事实，复跑员工六问。',
            ],
            'ai_diagnosis_evidence' => [
                'employee_explanation' => 'AI 诊断证据未闭合，不能把当前 action_items 当作可执行经营建议。',
                'limited_conclusions' => [
                    'AI 自动建议',
                    '执行意图创建',
                    '运营闭环完成判断',
                ],
                'still_usable_metrics' => [
                    '阻断原因。',
                    '证据来源。',
                    'data_gaps 补证据清单。',
                ],
                'explanation_next_action' => '先解除上游 OTA 缺口，再调用现有 OTA 诊断并保留脱敏 evidence_sources、data_gaps、action_items。',
            ],
            'operation_execution_evidence' => [
                'employee_explanation' => '尚无能追溯到 OTA 诊断的执行意图、审批、执行证据或复盘样例。',
                'limited_conclusions' => [
                    '运营执行闭环',
                    '动作完成',
                    '复盘和 ROI 判断',
                ],
                'still_usable_metrics' => [
                    '下一步动作和阻断链可见。',
                    '已验证的 OTA 诊断缺口可继续作为待处理清单。',
                ],
                'explanation_next_action' => '取得可执行 AI action_items 后，创建或附上执行意图和证据。',
            ],
            default => [],
        };
    }

    private function phase1NextRequiredActionRelatedQuestionKeys(array $action): array
    {
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));
        $questionKey = (string)($action['question_key'] ?? '');

        $keys = match ($family) {
            'target_date_source_rows' => ['today_ota_collected', 'trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
            'standard_facts' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
            'revenue_metric_inputs' => ['trusted_fields', 'revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
            'traffic_conversion_facts' => ['revenue_traffic_conversion', 'ai_evidence', 'next_operation_action'],
            'ai_diagnosis_evidence' => ['ai_evidence', 'next_operation_action'],
            'operation_execution_evidence' => ['next_operation_action'],
            default => [],
        };
        if ($questionKey !== '') {
            array_unshift($keys, $questionKey);
        }
        return array_values(array_unique($keys));
    }

    private function phase1NextRequiredActionFamily(array $action): string
    {
        $code = (string)($action['action_code'] ?? '');
        $questionKey = (string)($action['question_key'] ?? '');

        if ($code === 'phase1_confirm_source_date_evidence' || str_contains($code, '_target_date_source_rows')) {
            return 'target_date_source_rows';
        }
        if (str_contains($code, '_revenue_metric_inputs')) {
            return 'revenue_metric_inputs';
        }
        if (str_contains($code, '_traffic_conversion_facts')) {
            return 'traffic_conversion_facts';
        }
        if ($code === 'phase1_collect_ai_diagnosis_evidence' || $questionKey === 'ai_evidence') {
            return 'ai_diagnosis_evidence';
        }
        if ($code === 'phase1_create_operation_execution_evidence' || $questionKey === 'next_operation_action') {
            return 'operation_execution_evidence';
        }

        return $questionKey !== '' ? $questionKey : 'evidence_gap';
    }

    private function phase1NextRequiredActionFamilyRank(array $action): int
    {
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));

        return match ($family) {
            'evidence_scope' => 0,
            'target_date_source_rows' => 1,
            'standard_facts' => 2,
            'revenue_metric_inputs' => 3,
            'traffic_conversion_facts' => 4,
            'ai_diagnosis_evidence' => 5,
            'operation_execution_evidence' => 6,
            default => 9,
        };
    }

    private function phase1NextRequiredActionSuccessCriteria(array $action): string
    {
        $family = (string)($action['action_family'] ?? $this->phase1NextRequiredActionFamily($action));

        return match ($family) {
            'target_date_source_rows' => 'source_date_evidence.platforms 中对应平台 target_date_rows > 0；latest_available 仅作最近可用参考，不能替代或否定目标日行数。',
            'revenue_metric_inputs' => '对应平台 revenue_status=ready，且 revenue_metrics 输出 metric_trust 与 data_gaps。',
            'traffic_conversion_facts' => '对应平台 traffic_status=ready；未采到时必须保留 data_gaps，不用收益行推断流量或转化。',
            'ai_diagnosis_evidence' => 'OTA 诊断响应包含 evidence_sources、data_gaps 和至少一个非 blocked action_items。',
            'operation_execution_evidence' => '执行意图或执行流程可追溯到 OTA diagnosis action_items，并出现审批通过、执行证据、复盘或 ROI 任一完成信号。',
            default => '补齐所需证据后重新运行员工六问巡检，相关问题不再处于缺失状态。',
        };
    }

    private function phase1NextRequiredActionResolvesMissingCodes(array $action): array
    {
        $code = (string)($action['action_code'] ?? '');

        if ($code === 'phase1_confirm_source_date_evidence') {
            return ['source_date_evidence_missing'];
        }
        if (preg_match('/^phase1_collect_(ctrip|meituan)_target_date_source_rows$/', $code, $matches)) {
            return [$matches[1] . '_target_date_source_rows_missing'];
        }
        if (preg_match('/^phase1_(?:confirm|check)_(ctrip|meituan)_revenue_metric_inputs$/', $code, $matches)) {
            return [$matches[1] . '_revenue_metric_inputs_missing'];
        }
        if (preg_match('/^phase1_confirm_(ctrip|meituan)_traffic_conversion_facts$/', $code, $matches)) {
            return [$matches[1] . '_traffic_conversion_facts_missing'];
        }
        if ($code === 'phase1_collect_ai_diagnosis_evidence') {
            return ['ai_evidence_sources_missing', 'ai_data_gaps_missing', 'ai_action_items_missing', 'ai_action_items_blocked'];
        }
        if ($code === 'phase1_create_operation_execution_evidence') {
            return ['operation_execution_sample_missing', 'operation_execution_ai_action_link_missing', 'operation_execution_evidence_incomplete'];
        }

        return [];
    }

    private function phase1NextRequiredActionForBlockerCode(string $code): string
    {
        if ($code === 'source_date_evidence_missing' || $code === 'target_date_rows_missing') {
            return 'phase1_confirm_source_date_evidence';
        }
        if (preg_match('/^(ctrip|meituan)_target_date_source_rows_missing$/', $code, $matches)) {
            return 'phase1_collect_' . $matches[1] . '_target_date_source_rows';
        }
        if (preg_match('/^(ctrip|meituan)_revenue_metric_inputs_missing$/', $code, $matches)) {
            return 'phase1_check_' . $matches[1] . '_revenue_metric_inputs';
        }
        if (preg_match('/^(ctrip|meituan)_metric_trust_missing$/', $code, $matches)) {
            return 'phase1_check_' . $matches[1] . '_revenue_metric_inputs';
        }
        if (preg_match('/^(ctrip|meituan)_traffic_conversion_facts_missing$/', $code, $matches)) {
            return 'phase1_confirm_' . $matches[1] . '_traffic_conversion_facts';
        }
        if ($code === 'ai_evidence_sources_missing'
            || $code === 'ai_data_gaps_missing'
            || $code === 'ai_action_items_missing'
            || $code === 'ai_action_items_blocked'
        ) {
            return 'phase1_collect_ai_diagnosis_evidence';
        }
        if ($code === 'operation_execution_sample_missing'
            || $code === 'operation_execution_ai_action_link_missing'
            || $code === 'operation_execution_evidence_incomplete'
        ) {
            return 'phase1_create_operation_execution_evidence';
        }

        return '';
    }

    private function phase1NextRequiredActionBlockedByActionCodes(array $blockedBy, string $currentActionCode = ''): array
    {
        $actions = [];
        foreach ($blockedBy as $blocker) {
            $actionCode = $this->phase1NextRequiredActionForBlockerCode((string)$blocker);
            if ($actionCode !== '' && $actionCode !== $currentActionCode) {
                $actions[] = $actionCode;
            }
        }
        return array_values(array_unique($actions));
    }

    private function dedupePhase1NextRequiredActions(array $actions): array
    {
        $deduped = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $key = (string)($action['action_code'] ?? '');
            if ($key === '') {
                continue;
            }
            $deduped[$key] = $action;
        }
        return array_values($deduped);
    }

    private function sortPhase1NextRequiredActions(array $actions): array
    {
        $priorityRank = ['high' => 0, 'medium' => 1, 'low' => 2];
        $statusRank = ['missing' => 0, 'not_collected' => 0, 'warning' => 1, 'blocked' => 2];
        usort($actions, function (array $a, array $b) use ($priorityRank, $statusRank): int {
            $statusA = $statusRank[(string)($a['status'] ?? 'missing')] ?? 9;
            $statusB = $statusRank[(string)($b['status'] ?? 'missing')] ?? 9;
            if ($statusA !== $statusB) {
                return $statusA <=> $statusB;
            }
            $familyA = $this->phase1NextRequiredActionFamilyRank($a);
            $familyB = $this->phase1NextRequiredActionFamilyRank($b);
            if ($familyA !== $familyB) {
                return $familyA <=> $familyB;
            }
            $rankA = $priorityRank[(string)($a['priority'] ?? 'medium')] ?? 9;
            $rankB = $priorityRank[(string)($b['priority'] ?? 'medium')] ?? 9;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp((string)($a['action_code'] ?? ''), (string)($b['action_code'] ?? ''));
        });
        return array_slice($actions, 0, 12);
    }

    private function phase1CollectionEvidenceRowCount(array $reliability): int
    {
        $quality = is_array($reliability['data_quality'] ?? null) ? $reliability['data_quality'] : [];
        $count = max(0, (int)($quality['checked_records'] ?? 0));

        foreach ((array)($reliability['collection_logs'] ?? []) as $log) {
            if (!is_array($log)) {
                continue;
            }
            $count = max($count, (int)($log['saved_count'] ?? 0), (int)($log['total_saved_count'] ?? 0), (int)($log['row_count'] ?? 0));
        }

        foreach ((array)($reliability['history_replay'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $count = max($count, (int)($row['saved_count'] ?? 0), (int)($row['row_count'] ?? 0), (int)($row['record_count'] ?? 0));
        }

        $latest = is_array($reliability['ctrip_latest_capture'] ?? null) ? $reliability['ctrip_latest_capture'] : [];
        return max($count, (int)($latest['standard_row_count'] ?? 0), (int)($latest['persisted_row_count'] ?? 0));
    }

    private function phase1TargetDateSourceRowCount(array $sourceDateEvidence): int
    {
        $count = 0;
        foreach ((array)($sourceDateEvidence['platforms'] ?? []) as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            $count += max(0, (int)($platform['target_date_rows'] ?? 0));
        }
        return $count;
    }

    private function phase1HasSourceDatePlatformEvidence(array $sourceDateEvidence): bool
    {
        foreach ((array)($sourceDateEvidence['platforms'] ?? []) as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            if (trim((string)($platform['platform'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function phase1TargetDatePlatformCoverage(array $sourceDateEvidence, int $targetDateSourceRows, int $referenceSourceRows = 0): array
    {
        $platformRows = [];
        foreach ((array)($sourceDateEvidence['platforms'] ?? []) as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            $name = strtolower(trim((string)($platform['platform'] ?? '')));
            if ($name === '') {
                continue;
            }
            $rows = max(0, (int)($platform['target_date_rows'] ?? 0));
            $platformRows[] = [
                'platform' => $name,
                'target_date_rows' => $rows,
                'date_relation' => (string)($platform['date_relation'] ?? 'unknown'),
                'latest_available' => $platform['latest_available'] ?? null,
            ];
        }

        if ($platformRows === []) {
            return [
                'status' => $referenceSourceRows > 0 ? 'unknown' : 'missing',
                'target_date' => (string)($sourceDateEvidence['target_date'] ?? ''),
                'platform_count' => 0,
                'covered_platform_count' => 0,
                'missing_platforms' => [],
                'platforms' => [],
                'source_date_evidence_available' => false,
                'source_date_evidence_missing' => true,
                'reference_source_rows' => max(0, $referenceSourceRows),
                'reference_rows_only' => true,
            ];
        }

        $missingPlatforms = array_values(array_map(
            static fn(array $row): string => (string)$row['platform'],
            array_filter($platformRows, static fn(array $row): bool => (int)($row['target_date_rows'] ?? 0) === 0)
        ));
        $coveredCount = count($platformRows) - count($missingPlatforms);

        return [
            'status' => $coveredCount === count($platformRows) ? 'complete' : ($coveredCount > 0 ? 'partial' : 'missing'),
            'target_date' => (string)($sourceDateEvidence['target_date'] ?? ''),
            'platform_count' => count($platformRows),
            'covered_platform_count' => $coveredCount,
            'missing_platforms' => $missingPlatforms,
            'platforms' => $platformRows,
            'source_date_evidence_available' => true,
            'source_date_evidence_missing' => false,
        ];
    }

    private function phase1MetricDomainReadiness(array $sourceDateEvidence, array $targetDatePlatformCoverage): array
    {
        $platformEvidence = [];
        $targetDate = trim((string)($targetDatePlatformCoverage['target_date'] ?? $sourceDateEvidence['target_date'] ?? ''));
        foreach ((array)($sourceDateEvidence['platforms'] ?? []) as $platform) {
            if (!is_array($platform)) {
                continue;
            }
            $name = strtolower(trim((string)($platform['platform'] ?? '')));
            if ($name !== '') {
                $platformEvidence[$name] = $platform;
            }
        }

        $platformRows = (array)($targetDatePlatformCoverage['platforms'] ?? []);
        if ($platformRows === []) {
            return [];
        }

        $readiness = [];
        foreach ($platformRows as $platformRow) {
            if (!is_array($platformRow)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformRow['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            $targetRows = max(0, (int)($platformRow['target_date_rows'] ?? 0));
            $evidence = is_array($platformEvidence[$platform] ?? null) ? $platformEvidence[$platform] : [];
            $targetTypes = $this->phase1TargetDateDataTypes($evidence);
            $revenueReady = $targetRows > 0 && $this->phase1HasAnyDataType($targetTypes, ['business', 'order', 'orders', 'revenue']);
            $trafficReady = $targetRows > 0 && $this->phase1HasAnyDataType($targetTypes, ['traffic', 'flow', 'flow_data']);
            $missingDomains = [];
            if (!$revenueReady) {
                $missingDomains[] = 'revenue';
            }
            if (!$trafficReady) {
                $missingDomains[] = 'traffic';
                $missingDomains[] = 'conversion';
            }

            $readiness[] = [
                'platform' => $platform,
                'target_date' => $targetDate,
                'target_date_rows' => $targetRows,
                'target_date_data_types' => $targetTypes,
                'revenue_status' => $revenueReady ? 'ready' : 'missing',
                'traffic_status' => $trafficReady ? 'ready' : 'missing',
                'conversion_status' => $trafficReady ? 'ready' : 'missing',
                'missing_domains' => array_values(array_unique($missingDomains)),
                'source_policy' => 'read_target_date_online_daily_data_types_only',
            ];
        }

        return $readiness;
    }

    private function phase1TableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        try {
            return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function phase1TableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !$this->phase1TableExists($table)) {
            $cache[$table] = [];
            return [];
        }

        $columns = [];
        try {
            foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[$field] = true;
                }
            }
        } catch (\Throwable $e) {
            $columns = [];
        }
        $cache[$table] = $columns;
        return $columns;
    }

    private function phase1ExistingColumns(string $table, array $fields): array
    {
        $columns = $this->phase1TableColumns($table);
        return array_values(array_filter(
            $fields,
            static fn(string $field): bool => isset($columns[$field])
        ));
    }

    private function phase1P0TrafficRequiredMetricKeys(): array
    {
        return [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
    }

    private function phase1P0TrafficRequiredStorageFields(): array
    {
        return [
            'online_daily_data.list_exposure',
            'online_daily_data.detail_exposure',
            'online_daily_data.flow_rate',
            'online_daily_data.order_filling_num',
            'online_daily_data.order_submit_num',
        ];
    }

    private function phase1P0TrafficRequiredFieldFactKeys(): array
    {
        return [
            'capture_evidence',
            'source_path',
            'metric_key',
            'storage_field',
            'stored_value_present',
        ];
    }

    private function phase1P0TrafficPayloadCandidatePath(string $platform, string $targetDate, int $systemHotelId): string
    {
        $platform = strtolower(trim($platform));
        $targetDate = trim($targetDate);
        if (!in_array($platform, ['ctrip', 'meituan'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) || $systemHotelId <= 0) {
            return '';
        }

        return 'reports/p0_traffic_' . $platform . '_' . $systemHotelId . '_' . str_replace('-', '', $targetDate) . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function phase1P0TrafficPayloadCandidate(string $platform, string $targetDate, int $systemHotelId): array
    {
        $payloadPath = $this->phase1P0TrafficPayloadCandidatePath($platform, $targetDate, $systemHotelId);
        if ($payloadPath === '') {
            return [
                'status' => 'system_hotel_id_missing',
                'ready_to_execute' => false,
                'payload_path' => '',
                'issue_codes' => ['system_hotel_id_missing'],
            ];
        }

        $root = dirname(__DIR__, 3);
        $absolutePayloadPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $payloadPath);
        $present = is_file($absolutePayloadPath);

        return [
            'status' => $present ? 'expected_payload_present_unverified' : 'missing_expected_payload',
            'ready_to_execute' => false,
            'payload_path' => $payloadPath,
            'issue_codes' => $present ? ['payload_file_present_requires_importer_dry_run'] : ['expected_payload_file_missing'],
        ];
    }

    private function phase1TrafficSourceReadiness(array $metricDomainReadiness): array
    {
        $platforms = [];
        foreach ($metricDomainReadiness as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $targetDateDataTypes = array_values(array_filter(array_map(
                    static fn($value): string => strtolower(trim((string)$value)),
                    (array)($row['target_date_data_types'] ?? $row['data_types'] ?? [])
                ), static fn(string $value): bool => $value !== ''));
                $platforms[$platform] = [
                    'target_date' => trim((string)($row['target_date'] ?? '')),
                    'target_date_rows' => max(0, (int)($row['target_date_rows'] ?? $row['source_rows'] ?? 0)),
                    'target_date_traffic_rows' => max(0, (int)($row['traffic_rows'] ?? 0)),
                    'target_date_data_types' => array_values(array_unique($targetDateDataTypes)),
                ];
            }
        }
        if ($platforms === []) {
            return [];
        }

        $result = [];
        foreach ($platforms as $platform => $context) {
            $result[] = $this->phase1TrafficSourceReadinessForPlatform($platform, $context);
        }
        return $result;
    }

    private function phase1TrafficSourceReadinessForPlatform(string $platform, array $context): array
    {
        $requiredMetricKeys = $this->phase1P0TrafficRequiredMetricKeys();
        $requiredStorageFields = $this->phase1P0TrafficRequiredStorageFields();
        $requiredFieldFactKeys = $this->phase1P0TrafficRequiredFieldFactKeys();
        $targetDate = trim((string)($context['target_date'] ?? ''));
        $targetDateRows = max(0, (int)($context['target_date_rows'] ?? 0));
        $targetDateTrafficRows = max(0, (int)($context['target_date_traffic_rows'] ?? 0));
        $targetDateDataTypes = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($context['target_date_data_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        $targetDateDataTypes = array_values(array_unique($targetDateDataTypes));
        $targetDateTrafficDataTypes = $this->phase1TrafficDataTypes($targetDateDataTypes);
        $sourceChainReferenceOnly = $targetDateRows > 0
            && $targetDateTrafficRows <= 0
            && $targetDateDataTypes !== []
            && $targetDateTrafficDataTypes === [];
        if ($targetDateRows <= 0) {
            $sourceChainScope = 'no_target_date_source_rows';
            $sourceChainPolicy = 'No target-date source rows are loaded; P0 closure still requires target-date traffic rows and ready verifier status.';
        } elseif ($sourceChainReferenceOnly) {
            $sourceChainScope = 'reference_only_non_traffic_source_rows';
            $sourceChainPolicy = 'Target-date source rows without traffic/flow/conversion data types are reference only; P0 closure still requires target-date traffic rows and ready verifier status.';
        } else {
            $sourceChainScope = 'traffic_source_rows';
            $sourceChainPolicy = 'Target-date source rows include traffic/flow/conversion data types; P0 closure still requires ready verifier status.';
        }
        $p0FieldLoopMatrix = $this->phase1P0TrafficFieldLoopMatrix($requiredMetricKeys, $requiredStorageFields, $targetDateTrafficRows, $platform, $targetDate);
        $p0PlatformHotelIdentifierSource = $platform === 'meituan' ? 'poi_id_family' : 'hotel_id_family';
        $p0PlatformHotelIdentifierStatus = $targetDateTrafficRows > 0 ? 'requires_p0_verifier' : 'no_target_date_traffic_rows';
        $base = [
            'platform' => $platform,
            'target_date' => $targetDate,
            'target_date_rows' => $targetDateRows,
            'target_date_traffic_rows' => $targetDateTrafficRows,
            'target_date_data_types' => $targetDateDataTypes,
            'traffic_source_count' => 0,
            'traffic_enabled_count' => 0,
            'traffic_ready_count' => 0,
            'traffic_waiting_config_count' => 0,
            'traffic_managed_count' => 0,
            'traffic_secret_configured_count' => 0,
            'traffic_last_sync_status_counts' => [],
            'required_next_inputs' => [],
            'recommended_collection_mode' => 'status_check',
            'action_entry' => '/api/online-data/collection-reliability',
            'status' => 'not_registered',
            'source_policy' => 'read_platform_data_sources_metadata_only',
            'sensitive_values_exposed' => false,
            'p0_traffic_gate_status' => 'missing_target_date_traffic_rows',
            'p0_next_action_mode' => 'status_check',
            'p0_next_action_entry' => '/api/online-data/collection-reliability',
            'p0_next_step_count' => 0,
            'next_command_policy' => 'metadata_only_no_sensitive_commands',
            'p0_external_evidence_status' => 'not_provided',
            'p0_pre_import_evidence_status' => 'not_provided',
            'p0_pre_import_evidence_policy' => 'External traffic evidence is source proof only; P0 closure still requires target-date traffic rows and ready verifier status.',
            'p0_traffic_field_fact_status' => $targetDateTrafficRows > 0 ? 'requires_p0_verifier' : 'no_target_date_traffic_rows',
            'p0_payload_candidate_policy' => 'ui_metadata_only_no_import',
            'p0_payload_candidate_payload_policy' => 'path_metadata_only_no_payload_content',
            'p0_payload_candidate_storage_policy' => 'does_not_write_online_daily_data',
            'p0_payload_candidate_status_counts' => [],
            'p0_payload_candidate_ready_count' => 0,
            'p0_payload_candidate_missing_count' => 0,
            'p0_payload_candidate_unverified_count' => 0,
            'p0_payload_candidate_paths' => [],
            'p0_payload_candidate_issue_codes' => [],
            'p0_required_metric_keys' => $requiredMetricKeys,
            'p0_required_storage_fields' => $requiredStorageFields,
            'p0_required_field_fact_keys' => $requiredFieldFactKeys,
            'p0_missing_metric_keys' => $targetDateTrafficRows > 0 ? [] : $requiredMetricKeys,
            'p0_field_loop_matrix' => $p0FieldLoopMatrix,
            'p0_traffic_closure_chain' => $this->phase1P0TrafficClosureChain($p0FieldLoopMatrix, $targetDateTrafficRows, $p0PlatformHotelIdentifierStatus, $p0PlatformHotelIdentifierSource),
            'p0_traffic_closure_chain_policy' => 'Every chain item is OTA-channel evidence only and remains incomplete until the P0 field-loop verifier returns ready.',
            'p0_platform_hotel_identifier_source' => $p0PlatformHotelIdentifierSource,
            'p0_platform_hotel_identifier_status' => $p0PlatformHotelIdentifierStatus,
            'p0_platform_hotel_identifier_policy' => 'P0 traffic rows must prove the OTA platform hotel identifier through importer/verifier checks; UI exposes only status and source family, not raw IDs.',
            'p0_target_traffic_data_types' => $targetDateTrafficDataTypes,
            'p0_source_chain_reference_only' => $sourceChainReferenceOnly,
            'p0_source_chain_scope' => $sourceChainScope,
            'p0_source_chain_policy' => $sourceChainPolicy,
        ];

        if (!$this->phase1TableExists('platform_data_sources')) {
            $base['status'] = 'source_table_missing';
            $base['required_next_inputs'] = $this->phase1TrafficSourceRequiredNextInputs($platform, $base);
            return $base;
        }

        $fields = $this->phase1ExistingColumns('platform_data_sources', [
            'id',
            'platform',
            'data_type',
            'ingestion_method',
            'status',
            'enabled',
            'system_hotel_id',
            'last_sync_status',
            'last_sync_time',
            'last_error',
            'config_json',
            'secret_json',
        ]);
        if ($fields === []) {
            $base['status'] = 'source_schema_missing';
            $base['required_next_inputs'] = $this->phase1TrafficSourceRequiredNextInputs($platform, $base);
            return $base;
        }

        try {
            $rows = Db::name('platform_data_sources')
                ->field(implode(',', $fields))
                ->where('platform', $platform)
                ->whereIn('data_type', ['traffic', 'flow', 'conversion'])
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $base['status'] = 'source_read_failed';
            $base['required_next_inputs'] = $this->phase1TrafficSourceRequiredNextInputs($platform, $base);
            return $base;
        }

        $lastSyncCounts = [];
        foreach ($rows as $row) {
            $base['traffic_source_count']++;
            $enabled = (int)($row['enabled'] ?? 0) === 1;
            $status = strtolower(trim((string)($row['status'] ?? 'unknown')));
            $lastSyncStatus = strtolower(trim((string)($row['last_sync_status'] ?? '')));
            if ($enabled) {
                $base['traffic_enabled_count']++;
            }
            if ($status === 'ready') {
                $base['traffic_ready_count']++;
            }
            if ($status === 'waiting_config') {
                $base['traffic_waiting_config_count']++;
            }
            if ($lastSyncStatus !== '') {
                $lastSyncCounts[$lastSyncStatus] = ($lastSyncCounts[$lastSyncStatus] ?? 0) + 1;
            }

            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            if (($config['registered_by'] ?? '') === 'p0_ota_field_loop') {
                $base['traffic_managed_count']++;
                $candidate = $this->phase1P0TrafficPayloadCandidate($platform, $targetDate, (int)($row['system_hotel_id'] ?? 0));
                $candidateStatus = (string)($candidate['status'] ?? '');
                if ($candidateStatus !== '') {
                    $base['p0_payload_candidate_status_counts'][$candidateStatus] = ((int)($base['p0_payload_candidate_status_counts'][$candidateStatus] ?? 0)) + 1;
                }
                if (!empty($candidate['ready_to_execute'])) {
                    $base['p0_payload_candidate_ready_count']++;
                }
                if ($candidateStatus === 'missing_expected_payload') {
                    $base['p0_payload_candidate_missing_count']++;
                }
                if ($candidateStatus === 'expected_payload_present_unverified') {
                    $base['p0_payload_candidate_unverified_count']++;
                }
                if (($candidate['payload_path'] ?? '') !== '') {
                    $base['p0_payload_candidate_paths'][] = (string)$candidate['payload_path'];
                }
                foreach ((array)($candidate['issue_codes'] ?? []) as $issueCode) {
                    $issueCode = trim((string)$issueCode);
                    if ($issueCode !== '') {
                        $base['p0_payload_candidate_issue_codes'][] = $issueCode;
                    }
                }
            }
            $secret = json_decode((string)($row['secret_json'] ?? ''), true);
            if (is_array($secret) ? $secret !== [] : trim((string)($row['secret_json'] ?? '')) !== '') {
                $base['traffic_secret_configured_count']++;
            }
        }

        ksort($lastSyncCounts);
        $base['traffic_last_sync_status_counts'] = $lastSyncCounts;
        ksort($base['p0_payload_candidate_status_counts']);
        $base['p0_payload_candidate_paths'] = array_values(array_unique($base['p0_payload_candidate_paths']));
        $base['p0_payload_candidate_issue_codes'] = array_values(array_unique($base['p0_payload_candidate_issue_codes']));
        $trafficRows = (int)$base['target_date_traffic_rows'];
        if ($trafficRows > 0) {
            $base['status'] = 'target_date_traffic_ready';
        } elseif ((int)$base['traffic_source_count'] <= 0) {
            $base['status'] = 'not_registered';
        } elseif ((int)$base['traffic_waiting_config_count'] > 0) {
            $base['status'] = 'registered_waiting_config';
        } elseif ((int)$base['traffic_ready_count'] > 0) {
            $base['status'] = 'registered_ready_without_target_date_traffic';
        } else {
            $base['status'] = 'registered_not_ready';
        }
        $recommendedMode = $this->phase1TrafficSourceRecommendedMode($platform, $base);
        $base['recommended_collection_mode'] = $recommendedMode;
        $base['action_entry'] = $this->phase1TrafficSourceActionEntryForMode($platform, $recommendedMode);
        $base['p0_traffic_gate_status'] = $trafficRows > 0 ? 'requires_p0_verifier' : 'missing_target_date_traffic_rows';
        $base['p0_next_action_mode'] = $recommendedMode;
        $base['p0_next_action_entry'] = $base['action_entry'];
        $base['p0_next_step_count'] = max(0, (int)$base['traffic_managed_count']);
        $base['required_next_inputs'] = $this->phase1TrafficSourceRequiredNextInputs($platform, $base);

        return $base;
    }

    private function phase1P0TrafficFieldLoopMatrix(array $requiredMetricKeys, array $requiredStorageFields, int $targetDateTrafficRows, string $platform = '', string $targetDate = ''): array
    {
        $targetDateTrafficRows = max(0, $targetDateTrafficRows);
        if ($targetDateTrafficRows <= 0) {
            return array_values($this->phase1P0TrafficFieldLoopMatrixIndex($requiredMetricKeys, $requiredStorageFields, $targetDateTrafficRows, 'no_target_date_traffic_rows'));
        }

        $platform = strtolower(trim($platform));
        $targetDate = trim($targetDate);
        $matrix = $this->phase1P0TrafficFieldLoopMatrixIndex($requiredMetricKeys, $requiredStorageFields, $targetDateTrafficRows, 'requires_p0_verifier');
        if (!in_array($platform, ['ctrip', 'meituan'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return array_values($matrix);
        }

        $rows = $this->phase1P0TrafficRows($platform, $targetDate);
        if ($rows === []) {
            return array_values($matrix);
        }

        $storageMap = [];
        foreach (array_values($requiredMetricKeys) as $index => $metricKey) {
            $storageMap[(string)$metricKey] = (string)($requiredStorageFields[$index] ?? '');
        }

        foreach ($rows as $row) {
            $raw = $this->phase1P0DecodeRawData($row['raw_data'] ?? null);
            $facts = $this->phase1P0ExtractFieldFacts($row, $raw);
            $rowEvidence = $this->phase1P0DesensitizedEvidence($raw);
            $rowSourceTraceId = trim((string)($row['source_trace_id'] ?? $raw['source_trace_id'] ?? $rowEvidence['source_trace_id'] ?? ''));
            $rowSourceUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? ''));
            $uiStatus = method_exists($this, 'buildOnlineDataFieldFactStatus')
                ? $this->buildOnlineDataFieldFactStatus($row, $raw)
                : [];
            $uiReady = (string)($uiStatus['status'] ?? $uiStatus['field_fact_status'] ?? '') === 'ready'
                && ($uiStatus['raw_data_exposed'] ?? null) === false
                && (int)($uiStatus['missing_count'] ?? -1) === 0
                && (int)($uiStatus['stored_value_missing_count'] ?? -1) === 0;

            foreach ($facts as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = trim((string)($fact['metric_key'] ?? $fact['field_key'] ?? $fact['field'] ?? ''));
                if (!isset($matrix[$metricKey])) {
                    continue;
                }
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                $storageField = trim((string)($fact['storage_field'] ?? $fact['storage_target'] ?? ''));
                $expectedStorageField = $storageMap[$metricKey] ?? '';
                $captureEvidence = is_array($fact['capture_evidence'] ?? null) ? (array)$fact['capture_evidence'] : [];
                $desensitizedEvidence = $this->phase1P0DesensitizedEvidence($captureEvidence);
                $sourcePathStructured = $this->phase1P0SourcePathStructured($sourcePath);
                $storageMatches = $storageField !== '' && $storageField === $expectedStorageField;
                $storedValuePresent = $this->phase1P0StoredValueState($fact, $row, $raw, $storageField, $metricKey) === true;
                $captureEvidenceMatches = $this->phase1P0CaptureEvidenceMatchesRow($desensitizedEvidence, $rowSourceTraceId, $rowSourceUrlHash);
                $complete = $sourcePathStructured
                    && $storageMatches
                    && $storedValuePresent
                    && $captureEvidenceMatches
                    && $uiReady;

                $entry = $matrix[$metricKey];
                $entry['row_count'] = (int)($entry['row_count'] ?? 0) + 1;
                $entry['complete_row_count'] = (int)($entry['complete_row_count'] ?? 0) + ($complete ? 1 : 0);
                if (($entry['sample_row_id'] ?? null) === null) {
                    $entry['sample_row_id'] = $row['id'] ?? null;
                }
                $entry['capture_evidence_present'] = (bool)($entry['capture_evidence_present'] ?? false) || $captureEvidence !== [];
                $entry['desensitized_capture_evidence_present'] = (bool)($entry['desensitized_capture_evidence_present'] ?? false) || $desensitizedEvidence !== [];
                $entry['capture_evidence_matches_row'] = (bool)($entry['capture_evidence_matches_row'] ?? false) || $captureEvidenceMatches;
                $entry['source_path_structured'] = (bool)($entry['source_path_structured'] ?? false) || $sourcePathStructured;
                $entry['storage_field_matches_expected'] = (bool)($entry['storage_field_matches_expected'] ?? false) || $storageMatches;
                $entry['stored_value_present'] = (bool)($entry['stored_value_present'] ?? false) || $storedValuePresent;
                $entry['ui_status_ready'] = (bool)($entry['ui_status_ready'] ?? false) || $uiReady;
                $entry['status'] = $complete ? 'complete' : 'incomplete';
                $matrix[$metricKey] = $entry;
            }
        }

        foreach ($matrix as &$entry) {
            if ((int)($entry['row_count'] ?? 0) <= 0) {
                $entry['status'] = 'missing';
            }
        }
        unset($entry);

        return array_values($matrix);
    }

    private function phase1P0TrafficClosureChain(array $fieldLoopMatrix, int $targetDateTrafficRows, string $platformHotelIdentifierStatus, string $platformHotelIdentifierSource): array
    {
        $targetDateTrafficRows = max(0, $targetDateTrafficRows);
        $chainStatus = static function (bool $ready) use ($targetDateTrafficRows): string {
            if ($targetDateTrafficRows <= 0) {
                return 'no_target_date_traffic_rows';
            }
            return $ready ? 'ready' : 'incomplete';
        };
        $all = static function (string $key) use ($fieldLoopMatrix): bool {
            if ($fieldLoopMatrix === []) {
                return false;
            }
            foreach ($fieldLoopMatrix as $item) {
                if (!is_array($item) || empty($item[$key])) {
                    return false;
                }
            }
            return true;
        };
        $allMetricRowsPresent = static function () use ($fieldLoopMatrix): bool {
            if ($fieldLoopMatrix === []) {
                return false;
            }
            foreach ($fieldLoopMatrix as $item) {
                if (!is_array($item) || (int)($item['row_count'] ?? 0) <= 0) {
                    return false;
                }
            }
            return true;
        };

        return [
            'capture_evidence' => [
                'status' => $chainStatus($all('capture_evidence_present') && $all('desensitized_capture_evidence_present') && $all('capture_evidence_matches_row')),
                'required' => 'desensitized source_trace_id plus source_url_hash matched to each traffic row and field fact',
            ],
            'source_path' => [
                'status' => $chainStatus($all('source_path_structured')),
                'required' => 'structured source_path for every required traffic metric',
            ],
            'metric_key' => [
                'status' => $chainStatus($allMetricRowsPresent()),
                'required' => 'required traffic metric keys are present in field facts',
            ],
            'storage_field' => [
                'status' => $chainStatus($all('storage_field_matches_expected')),
                'required' => 'expected online_daily_data storage field for every required metric',
            ],
            'stored_value' => [
                'status' => $chainStatus($all('stored_value_present')),
                'required' => 'stored value present for every required traffic metric',
            ],
            'ui_status' => [
                'status' => $chainStatus($all('ui_status_ready')),
                'required' => 'ready UI field_fact_status with no raw_data exposure',
            ],
            'platform_hotel_identifier' => [
                'status' => $platformHotelIdentifierStatus,
                'required' => $platformHotelIdentifierSource,
            ],
            'verifier' => [
                'status' => $targetDateTrafficRows > 0 ? 'requires_p0_verifier' : 'incomplete',
                'required' => 'P0 field-loop verifier returns ready',
            ],
        ];
    }

    private function phase1P0TrafficFieldLoopMatrixIndex(array $requiredMetricKeys, array $requiredStorageFields, int $targetDateTrafficRows, string $status): array
    {
        $matrix = [];
        foreach (array_values($requiredMetricKeys) as $index => $metricKey) {
            $metricKey = (string)$metricKey;
            $matrix[$metricKey] = [
                'metric_key' => $metricKey,
                'expected_storage_field' => (string)($requiredStorageFields[$index] ?? ''),
                'status' => $status,
                'target_date_traffic_rows' => max(0, $targetDateTrafficRows),
                'row_count' => 0,
                'complete_row_count' => 0,
                'sample_row_id' => null,
                'capture_evidence_present' => false,
                'desensitized_capture_evidence_present' => false,
                'capture_evidence_matches_row' => false,
                'source_path_structured' => false,
                'storage_field_matches_expected' => false,
                'stored_value_present' => false,
                'ui_status_ready' => false,
            ];
        }

        return $matrix;
    }

    private function phase1P0TrafficRows(string $platform, string $targetDate): array
    {
        if (!$this->phase1TableExists('online_daily_data')) {
            return [];
        }
        $columns = $this->phase1TableColumns('online_daily_data');
        foreach (['source', 'data_date', 'data_type', 'raw_data'] as $required) {
            if (!isset($columns[$required])) {
                return [];
            }
        }
        $fields = array_values(array_filter([
            'id',
            'source',
            'data_date',
            'data_type',
            'raw_data',
            isset($columns['list_exposure']) ? 'list_exposure' : '',
            isset($columns['detail_exposure']) ? 'detail_exposure' : '',
            isset($columns['flow_rate']) ? 'flow_rate' : '',
            isset($columns['order_filling_num']) ? 'order_filling_num' : '',
            isset($columns['order_submit_num']) ? 'order_submit_num' : '',
            isset($columns['source_trace_id']) ? 'source_trace_id' : '',
            isset($columns['sync_task_id']) ? 'sync_task_id' : '',
        ], static fn(string $field): bool => $field !== ''));

        try {
            return Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where('source', $platform)
                ->where('data_date', $targetDate)
                ->whereIn('data_type', ['traffic', 'flow', 'conversion'])
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function phase1P0DecodeRawData(mixed $rawData): array
    {
        if (is_array($rawData)) {
            return $rawData;
        }
        if (!is_string($rawData) || trim($rawData) === '') {
            return [];
        }
        $decoded = json_decode($rawData, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function phase1P0ExtractFieldFacts(array $row, array $raw): array
    {
        foreach ([
            $row['field_facts'] ?? null,
            $row['raw_data']['field_facts'] ?? null,
            $raw['field_facts'] ?? null,
            $raw['row']['field_facts'] ?? null,
            $raw['raw_data']['field_facts'] ?? null,
            $row['facts'] ?? null,
            $row['raw_data']['facts'] ?? null,
            $raw['facts'] ?? null,
            $raw['row']['facts'] ?? null,
            $raw['raw_data']['facts'] ?? null,
        ] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $facts = array_values(array_filter($candidate, static fn($item): bool => is_array($item)));
            if ($facts !== []) {
                return $facts;
            }
        }
        return [];
    }

    private function phase1P0DesensitizedEvidence(array $source): array
    {
        $evidence = [];
        $aliases = [
            'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
        ];
        foreach ($aliases as $target => $keys) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $evidence[$target] = mb_substr((string)$value, 0, 300);
                    break;
                }
            }
        }
        $nested = $source['capture_evidence'] ?? null;
        if (is_array($nested)) {
            foreach ($this->phase1P0DesensitizedEvidence($nested) as $key => $value) {
                if (!isset($evidence[$key])) {
                    $evidence[$key] = $value;
                }
            }
        }

        return $evidence;
    }

    private function phase1P0CaptureEvidenceMatchesRow(array $desensitizedEvidence, string $rowSourceTraceId, string $rowSourceUrlHash): bool
    {
        $factSourceTraceId = trim((string)($desensitizedEvidence['source_trace_id'] ?? ''));
        $factSourceUrlHash = trim((string)($desensitizedEvidence['source_url_hash'] ?? ''));
        if ($factSourceTraceId === '' || $factSourceUrlHash === '') {
            return false;
        }
        if ($rowSourceTraceId !== '' && $factSourceTraceId !== $rowSourceTraceId) {
            return false;
        }
        if ($rowSourceUrlHash !== '' && $factSourceUrlHash !== $rowSourceUrlHash) {
            return false;
        }
        return true;
    }

    private function phase1P0SourcePathStructured(string $sourcePath): bool
    {
        $sourcePath = trim($sourcePath);
        return $sourcePath !== ''
            && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
    }

    private function phase1P0StoredValueState(array $fact, array $row, array $raw, string $storageField, string $metricKey): ?bool
    {
        $explicit = $this->phase1P0BoolState($fact['stored_value_present'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $storageField = trim($storageField);
        if ($storageField === '') {
            return null;
        }
        $rawPrefix = 'online_daily_data.raw_data.';
        if (str_starts_with($storageField, $rawPrefix)) {
            return $this->phase1P0ValuePresent($this->phase1P0ReadPath($raw, substr($storageField, strlen($rawPrefix))));
        }
        $rowPrefix = 'online_daily_data.';
        if (str_starts_with($storageField, $rowPrefix)) {
            $field = substr($storageField, strlen($rowPrefix));
            return array_key_exists($field, $row) ? $this->phase1P0ValuePresent($row[$field]) : null;
        }
        if (str_starts_with($storageField, 'online_daily_data.raw_data.facts.metric_key=')) {
            return $this->phase1P0ValuePresent($fact['value'] ?? null);
        }
        return null;
    }

    private function phase1P0BoolState(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }
        return null;
    }

    private function phase1P0ValuePresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && $value === []) {
            return false;
        }
        return true;
    }

    private function phase1P0ReadPath(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private function phase1TrafficSourceRecommendedMode(string $platform, array $source): string
    {
        $platform = strtolower(trim($platform));
        if ((int)($source['target_date_traffic_rows'] ?? 0) > 0) {
            return 'status_check';
        }
        if ($platform === 'meituan' && (int)($source['traffic_source_count'] ?? 0) > 0) {
            return 'browser_profile';
        }
        return 'manual_cookie_api';
    }

    private function phase1TrafficSourceActionEntryForMode(string $platform, string $mode): string
    {
        $platform = strtolower(trim($platform));
        $mode = strtolower(trim($mode));
        if ($mode === 'status_check') {
            return '/api/online-data/collection-reliability';
        }
        if ($mode === 'browser_profile') {
            return $platform === 'meituan'
                ? '/api/online-data/capture-meituan-browser'
                : '/api/online-data/capture-ctrip-browser';
        }
        return $this->phase1TrafficConversionFactsActionEntry($platform);
    }

    private function phase1TrafficSourceRequiredNextInputs(string $platform, array $source): array
    {
        $platform = strtolower(trim($platform));
        if ((int)($source['target_date_traffic_rows'] ?? 0) > 0) {
            return [];
        }

        $status = (string)($source['status'] ?? '');
        if ($status === 'source_table_missing') {
            return ['platform_data_sources_table'];
        }
        if ($status === 'source_schema_missing') {
            return ['platform_data_sources_schema'];
        }
        if ($status === 'source_read_failed') {
            return ['platform_data_sources_readable'];
        }

        $inputs = $platform === 'meituan'
            ? ['traffic_request_url_or_cdp_endpoint_evidence', 'traffic_payload_or_query_params', 'authorized_meituan_profile_dir', 'manual_login_state_verified']
            : ['traffic_payload_or_query_params', 'authorized_ctrip_profile_dir', 'manual_login_state_verified'];

        if ((int)($source['traffic_source_count'] ?? 0) <= 0) {
            array_unshift($inputs, 'registered_traffic_data_source');
        } elseif ((int)($source['traffic_ready_count'] ?? 0) > 0 && (int)($source['traffic_waiting_config_count'] ?? 0) === 0) {
            $inputs = ['traffic_collection_run_and_target_date_rows'];
        } elseif ((int)($source['traffic_waiting_config_count'] ?? 0) === 0) {
            array_unshift($inputs, 'traffic_data_source_ready_state');
        }

        return array_values(array_unique($inputs));
    }

    private function phase1PlatformFieldTrust(array $metricDomainReadiness): array
    {
        $rows = [];
        foreach ($metricDomainReadiness as $item) {
            if (!is_array($item)) {
                continue;
            }
            $platform = strtolower(trim((string)($item['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            $targetRows = max(0, (int)($item['target_date_rows'] ?? 0));
            $targetTypes = array_values(array_filter(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                (array)($item['target_date_data_types'] ?? $item['data_types'] ?? [])
            ), static fn(string $value): bool => $value !== ''));
            $revenueReady = (string)($item['revenue_status'] ?? '') === 'ready';
            $fieldTrustStatus = match (true) {
                $targetRows <= 0 => 'target_date_source_missing',
                $revenueReady => 'target_date_revenue_sample_present',
                default => 'target_date_metric_inputs_missing',
            };
            $reasonCodes = [];
            if ($targetRows <= 0) {
                $reasonCodes[] = $platform . '_target_date_source_rows_missing';
            }
            if (!$revenueReady) {
                $reasonCodes[] = $platform . '_revenue_metric_inputs_missing';
            }
            $rows[] = [
                'platform' => $platform,
                'target_date_rows' => $targetRows,
                'target_date_data_types' => $targetTypes,
                'field_trust_status' => $fieldTrustStatus,
                'reason_codes' => array_values(array_unique($reasonCodes)),
                'metric_trust_required' => true,
                'source_policy' => 'target_date_rows_field_definitions_metric_trust_required',
            ];
        }
        return $rows;
    }

    private function phase1TargetDateDataTypes(array $platformEvidence): array
    {
        $types = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($platformEvidence['target_date_data_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));

        if ($types !== []) {
            return array_values(array_unique($types));
        }

        $types = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($platformEvidence['data_types'] ?? [])
        ), static fn(string $value): bool => $value !== ''));

        if ($types !== []) {
            return array_values(array_unique($types));
        }

        if ((string)($platformEvidence['date_relation'] ?? '') !== 'target_date') {
            return [];
        }

        $latest = is_array($platformEvidence['latest_available'] ?? null) ? $platformEvidence['latest_available'] : [];
        return array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($latest['data_types'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
    }

    private function phase1TrafficDataTypes(array $types): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            $types
        ), static fn(string $value): bool => in_array($value, ['traffic', 'flow', 'flow_data', 'conversion'], true))));
    }

    private function phase1HasAnyDataType(array $types, array $needles): bool
    {
        $typeSet = array_fill_keys(array_map('strtolower', $types), true);
        foreach ($needles as $needle) {
            if (isset($typeSet[strtolower($needle)])) {
                return true;
            }
        }
        return false;
    }

    private function phase1ReadyMetricPlatforms(array $metricDomainReadiness, string $statusField): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string)$row['platform'],
            array_filter($metricDomainReadiness, static fn(array $row): bool => (string)($row[$statusField] ?? '') === 'ready')
        ));
    }

    private function phase1MissingMetricPlatforms(array $metricDomainReadiness, string $statusField): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string)$row['platform'],
            array_filter($metricDomainReadiness, static fn(array $row): bool => (string)($row[$statusField] ?? '') !== 'ready')
        ));
    }

    private function phase1MetricDomainGapCodes(array $metricDomainReadiness): array
    {
        $codes = [];
        foreach ($metricDomainReadiness as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platform = strtolower(trim((string)($row['platform'] ?? '')));
            if ($platform === '') {
                continue;
            }
            if ((string)($row['revenue_status'] ?? '') !== 'ready') {
                $codes[$platform . '_revenue_metric_inputs_missing'] = true;
            }
            if ((string)($row['traffic_status'] ?? '') !== 'ready') {
                $codes[$platform . '_traffic_conversion_facts_missing'] = true;
            }
        }

        return array_values(array_keys($codes));
    }

    private function phase1AiEvidenceBlockers(array $targetDatePlatformCoverage): array
    {
        $blockers = [];
        $coverageStatus = (string)($targetDatePlatformCoverage['status'] ?? 'missing');
        if ($coverageStatus !== 'complete') {
            if ((bool)($targetDatePlatformCoverage['source_date_evidence_missing'] ?? false)) {
                $blockers[] = 'source_date_evidence_missing';
            }
            $missingPlatforms = array_values(array_filter(array_map('strval', (array)($targetDatePlatformCoverage['missing_platforms'] ?? []))));
            if ($missingPlatforms !== []) {
                foreach ($missingPlatforms as $platform) {
                    $blockers[] = strtolower($platform) . '_target_date_source_rows_missing';
                }
            } elseif (!(bool)($targetDatePlatformCoverage['source_date_evidence_missing'] ?? false)) {
                $blockers[] = 'target_date_source_rows_missing';
            }
        }

        return array_values(array_unique($blockers));
    }

    private function phase1FieldDefinitionCount(array $reliability): int
    {
        $definitions = is_array($reliability['field_definitions'] ?? null) ? $reliability['field_definitions'] : [];
        $count = 0;
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
            $count += $fields !== [] ? count($fields) : 1;
        }
        return $count;
    }

    private function phase1FieldDefinitionKeys(array $reliability, int $limit = 40): array
    {
        $definitions = is_array($reliability['field_definitions'] ?? null) ? $reliability['field_definitions'] : [];
        $keys = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $source = strtolower(trim((string)($definition['source'] ?? $definition['platform'] ?? '')));
            $module = strtolower(trim((string)($definition['module'] ?? $definition['section'] ?? $definition['data_type'] ?? '')));
            $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
            if ($fields === []) {
                $field = $this->phase1FieldDefinitionFieldKey($definition);
                $key = implode('.', array_values(array_filter([$source, $module, $field], static fn(string $value): bool => $value !== '')));
                if ($key !== '') {
                    $keys[$key] = true;
                }
                continue;
            }
            foreach ($fields as $fieldRow) {
                $field = is_array($fieldRow)
                    ? $this->phase1FieldDefinitionFieldKey($fieldRow)
                    : strtolower(trim((string)$fieldRow));
                $key = implode('.', array_values(array_filter([$source, $module, $field], static fn(string $value): bool => $value !== '')));
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_slice(array_values(array_keys($keys)), 0, max(1, $limit));
    }

    private function phase1FieldDefinitionFieldKey(array $row): string
    {
        foreach (['field', 'key', 'id', 'name', 'metric_key'] as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return strtolower(trim((string)$value));
            }
        }
        return '';
    }

    private function phase1PendingActionCodes(array $reliability, string $needle): array
    {
        $codes = [];
        foreach ((array)($reliability['pending_actions'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $haystack = strtolower(implode(' ', [
                (string)($item['type'] ?? ''),
                (string)($item['action_code'] ?? ''),
                (string)($item['reason'] ?? ''),
                (string)($item['action'] ?? ''),
            ]));
            if (!str_contains($haystack, strtolower($needle))) {
                continue;
            }
            $code = trim((string)($item['action_code'] ?? $item['code'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
        return array_values(array_keys($codes));
    }

    private function phase1DataQualityMissingFieldCodes(array $quality): array
    {
        $codes = [];
        foreach (['missing_field_codes', 'missing_fields', 'field_missing_codes'] as $key) {
            foreach ((array)($quality[$key] ?? []) as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $codes[(string)$value] = true;
                }
            }
        }
        foreach ((array)($quality['top_prompts'] ?? []) as $prompt) {
            if (!is_array($prompt)) {
                continue;
            }
            $field = $prompt['field'] ?? $prompt['field_key'] ?? $prompt['metric_key'] ?? null;
            if (is_scalar($field) && trim((string)$field) !== '') {
                $codes[(string)$field] = true;
            }
        }
        return array_values(array_keys($codes));
    }

    private function phase1PendingActionCount(array $reliability, string $needle): int
    {
        $count = 0;
        foreach ((array)($reliability['pending_actions'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $haystack = strtolower(implode(' ', [
                (string)($item['type'] ?? ''),
                (string)($item['action_code'] ?? ''),
                (string)($item['reason'] ?? ''),
                (string)($item['action'] ?? ''),
            ]));
            if (str_contains($haystack, strtolower($needle))) {
                $count++;
            }
        }
        return $count;
    }

    private function phase1HasCollectionFailure(array $reliability): bool
    {
        foreach ((array)($reliability['collection_logs'] ?? []) as $log) {
            if (is_array($log) && in_array(strtolower((string)($log['status'] ?? '')), ['failed', 'auth_failed', 'request_failed'], true)) {
                return true;
            }
        }
        return !empty($reliability['failure_reasons']);
    }
}
