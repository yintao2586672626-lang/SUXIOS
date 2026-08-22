<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\OperationLog;
use app\model\SystemConfig;
use app\model\AiModelConfig;
use app\model\User as UserModel;
use app\service\AgentClosureReadinessService;
use app\service\AiDecisionQualityService;
use app\service\AiModelRoutingService;
use app\service\CompetitorPriceReadinessService;
use app\service\FeasibilityReportService;
use app\service\KnowledgeDecisionGateService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\RevenueAiOverviewService;
use app\service\RevenueForecastReadinessService;
use app\service\RevenuePricingRecommendationService;
use think\Response;
use think\facade\Db;

trait AgentOtaDiagnosisPersistenceConcern
{
    private function finalizeAllOtaDiagnosisDecision(array $result): array
    {
        $coverageComplete = ($result['coverage']['complete'] ?? false) === true;
        $dataGaps = $this->normalizeOtaDiagnosisDataGaps($result['data_gaps'] ?? []);
        $blockingDataGaps = $this->blockingOtaDiagnosisDataGaps($dataGaps, $result);
        $mainProblems = array_values(array_filter(array_map(
            'strval',
            (array)($result['main_problems'] ?? $result['diagnosis']['abnormal_metrics'] ?? [])
        )));
        $recommendedActions = array_values(array_filter(array_map(
            'strval',
            (array)($result['recommended_actions'] ?? $result['diagnosis']['actions'] ?? [])
        )));
        $missingTargetFacts = !$coverageComplete || $this->otaDiagnosisHasMissingTargetFacts($result, $dataGaps);
        $status = $missingTargetFacts
            ? 'blocked_by_missing_facts'
            : ($blockingDataGaps !== []
                ? 'blocked_by_data'
                : (($mainProblems !== [] || $recommendedActions !== []) ? 'action_required' : 'no_action'));

        $result['decision_status'] = $status;
        $result['blocking_data_gaps'] = $blockingDataGaps;
        $result['optional_data_gaps'] = array_values(array_filter($dataGaps, static function (array $gap) use ($blockingDataGaps): bool {
            $code = (string)($gap['code'] ?? '');
            foreach ($blockingDataGaps as $blockingGap) {
                if ((string)($blockingGap['code'] ?? '') === $code) {
                    return false;
                }
            }
            return true;
        }));
        if (in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true)) {
            $result['priority'] = 'none';
        }
        if ($status === 'no_action') {
            $result['no_action_reason'] = [
                'codes' => ['both_ota_platforms_current', 'no_platform_threshold_breach'],
                'scope' => 'ctrip_meituan_ota_channels',
                'statement' => '无需新增行动只表示本次携程和美团各自已覆盖指标未触发阈值，不代表 PMS 或全酒店经营无问题。',
            ];
        }
        $result['decision_closure'] = [
            'status' => $status,
            'legacy_status' => in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true)
                ? 'blocked'
                : ($status === 'no_action' ? 'ready' : 'pending_platform_specific_diagnosis'),
            'scope' => 'ctrip_meituan_ota_channels',
            'chain' => 'Ctrip + Meituan OTA facts -> per-platform revenue/traffic diagnosis -> operating question',
            'data_evidence_input' => [
                'source_policy' => (string)($result['source_policy'] ?? ''),
                'source_counts' => $result['data_summary']['source_counts'] ?? [],
                'evidence_refs' => $result['evidence_refs'] ?? [],
                'coverage' => $result['coverage'] ?? [],
                'data_gaps' => $dataGaps,
                'blocking_data_gaps' => $blockingDataGaps,
                'optional_data_gaps' => $result['optional_data_gaps'],
                'enough_for_decision' => !in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true),
                'enough_for_executable_actions' => false,
            ],
            'diagnostic_conclusion' => [
                'summary' => (string)($result['core_conclusion'] ?? $result['diagnosis']['summary'] ?? ''),
                'main_problems' => $mainProblems,
                'possible_reasons' => $result['possible_reasons'] ?? [],
                'confidence_level' => (string)($result['ai_governance']['confidence_level'] ?? ''),
            ],
            'suggested_actions' => [
                'ready_count' => 0,
                'blocked_count' => count((array)($result['action_items'] ?? [])),
                'decision' => $status === 'action_required'
                    ? 'create_platform_specific_saved_diagnosis_before_execution'
                    : ($status === 'no_action' ? 'no_new_action' : 'resolve_data_gaps'),
                'items' => $result['action_items'] ?? [],
            ],
            'blocked_state' => [
                'is_blocked' => in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true),
                'blocked_reasons' => array_values(array_filter(array_map(
                    static fn(array $gap): string => (string)($gap['code'] ?? ''),
                    $blockingDataGaps
                ))),
                'blocked_items' => [],
            ],
            'human_confirmation' => [
                'required' => false,
                'status' => $status === 'action_required' ? 'platform_specific_diagnosis_required' : 'not_required',
                'reason' => $status === 'action_required'
                    ? '跨渠道诊断不直接创建执行意图；先打开对应单平台诊断，再按原人工确认策略转运营执行。'
                    : (in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true) ? '先补齐携程和美团目标日期证据。' : '本次没有达到行动阈值的逐平台信号。'),
                'ready_action_ids' => [],
                'confirm_before_execution' => false,
            ],
        ];
        $result['execution_policy'] = 'all_ota_read_only_use_platform_specific_saved_diagnosis_for_manual_execution';
        $result['evidence_report'] = $this->buildOtaEvidenceReport($result);

        return $result;
    }

    private function finalizeOtaDiagnosisDecision(array $result): array
    {
        $result['decision_closure'] = $this->buildAiDecisionClosure($result);
        $result['decision_status'] = (string)($result['decision_closure']['status'] ?? 'blocked_by_data');
        $result['blocking_data_gaps'] = $result['decision_closure']['data_evidence_input']['blocking_data_gaps'] ?? [];
        $result['optional_data_gaps'] = $result['decision_closure']['data_evidence_input']['optional_data_gaps'] ?? [];

        if ($result['decision_status'] === 'blocked_by_missing_facts') {
            $missingFactCodes = array_values(array_unique(array_filter(array_map(
                static fn(array $gap): string => trim((string)($gap['code'] ?? '')),
                (array)$result['blocking_data_gaps']
            ))));
            $result['workflow_status'] = 'blocked_by_missing_facts';
            $result['missing_fact_codes'] = $missingFactCodes;
            $result['recommended_actions'] = [];
            $result['action_items'] = [];
            if (is_array($result['diagnosis'] ?? null)) {
                $result['diagnosis']['actions'] = [];
            }
            $result['decision_closure'] = $this->buildAiDecisionClosure($result);
            $result['blocking_data_gaps'] = $result['decision_closure']['data_evidence_input']['blocking_data_gaps'] ?? [];
            $result['optional_data_gaps'] = $result['decision_closure']['data_evidence_input']['optional_data_gaps'] ?? [];
        }

        if ($result['decision_status'] === 'no_action') {
            $platformLabel = $this->otaDiagnosisPlatformLabel($result['platform'] ?? null);
            $summary = sprintf(
                '本次%s渠道已覆盖的入库核心字段通过校验，未发现达到当前诊断阈值的异常；该结论仅限本次渠道数据，“无需新增行动”，继续观察下一数据日。',
                $platformLabel
            );
            $priorityRecommendation = $this->otaDiagnosisNoActionPriorityRecommendation($result);
            $result['diagnosis']['summary'] = $summary;
            $result['diagnosis']['abnormal_metrics'] = [];
            $result['diagnosis']['actions'] = [];
            $result['diagnosis']['priority_recommendation'] = $priorityRecommendation;
            $result['core_conclusion'] = $summary;
            $result['main_problems'] = [];
            $result['recommended_actions'] = [];
            $result['priority'] = 'none';
            $result['no_action_reason'] = [
                'codes' => ['core_metrics_available', 'no_threshold_breach'],
                'scope' => 'ota_channel',
                'statement' => '无需行动只表示本次已覆盖的 OTA 渠道指标未触发行动阈值，不代表全酒店经营无问题。',
                'priority_recommendation' => $priorityRecommendation,
            ];
            $result['diagnosis_sections'] = $this->buildOtaDiagnosisSections(
                $result['diagnosis'],
                $result['missing_sections'] ?? []
            );
            $result['decision_closure'] = $this->buildAiDecisionClosure($result);
        }
        if (in_array($result['decision_status'], ['blocked_by_data', 'blocked_by_missing_facts'], true)) {
            $result['priority'] = 'none';
        }

        $result['execution_policy'] = $result['decision_status'] === 'action_required'
            ? 'saved_evidence_action_requires_manual_confirmation'
            : 'do_not_create_execution_intent';
        $result['evidence_report'] = $this->buildOtaEvidenceReport($result);

        return $result;
    }

    private function persistOtaDiagnosisResult(array $result, int $hotelId, string $platform): array
    {
        // Schema v4 binds the root-evidence-aware Ctrip operating radar to the exact readback
        // identity. Radar-less diagnoses keep v2 so existing platform flows do
        // not change their persistence contract.
        $schemaVersion = is_array($result['operating_radar'] ?? null) ? 4 : 2;
        $resolvedHotelId = $hotelId > 0 ? $hotelId : (int)($result['hotel']['id'] ?? 0);
        if ($resolvedHotelId <= 0) {
            $result['saved_record'] = [
                'saved' => false,
                'reason' => 'system_hotel_id_missing',
            ];
            return $result;
        }

        $decisionStatus = (string)($result['decision_status'] ?? $result['decision_closure']['status'] ?? 'blocked_by_data');
        $statusLabels = [
            'action_required' => '需要人工确认行动',
            'no_action' => '无需新增行动',
            'blocked_by_data' => '数据不足，暂不能行动',
            'blocked_by_missing_facts' => '目标日期可信事实缺失，已阻断建议',
        ];
        $dateRange = is_array($result['date_range'] ?? null) ? $result['date_range'] : [];
        $requestedDateRange = $this->normalizeOtaDiagnosisScopeDateRange(
            is_array($result['requested_date_range'] ?? null) ? $result['requested_date_range'] : $dateRange
        );
        $this->assertOtaDiagnosisDecisionEvidenceScope(
            $result,
            $resolvedHotelId,
            $platform,
            $requestedDateRange
        );
        $readbackIdentity = $this->otaDiagnosisReadbackIdentity(
            $result,
            $resolvedHotelId,
            $platform,
            $schemaVersion
        );
        $readbackIdentityDigest = $this->otaDiagnosisReadbackIdentityDigest($readbackIdentity);
        $platformLabel = match (strtolower($platform)) {
            'meituan' => '美团',
            'ctrip' => '携程',
            'all_ota' => '携程+美团 OTA',
            default => strtoupper($platform),
        };
        $message = sprintf(
            '%s渠道诊断已保存：%s（%s 至 %s）',
            $platformLabel,
            $statusLabels[$decisionStatus] ?? $decisionStatus,
            (string)($dateRange['start_date'] ?? ''),
            (string)($dateRange['end_date'] ?? '')
        );
        $level = in_array($decisionStatus, ['blocked_by_data', 'blocked_by_missing_facts'], true)
            ? AgentLog::LEVEL_WARNING
            : AgentLog::LEVEL_INFO;
        Db::transaction(function () use (
            &$result,
            $resolvedHotelId,
            $platform,
            $message,
            $level,
            $dateRange,
            $requestedDateRange,
            $decisionStatus,
            $readbackIdentity,
            $readbackIdentityDigest,
            $schemaVersion
        ): void {
            $log = AgentLog::record(
                $resolvedHotelId,
                AgentLog::AGENT_TYPE_REVENUE,
                'ota_diagnosis',
                $message,
                $level,
                [
                    'schema_version' => $schemaVersion,
                    'record_type' => 'ota_diagnosis',
                    'platform' => strtolower($platform),
                    'date_range' => $dateRange,
                    'decision_status' => $decisionStatus,
                    'readback_identity_digest' => $readbackIdentityDigest,
                ],
                (int)($this->currentUser->id ?? 0)
            );

            $logId = (int)$log->id;
            $result['record_status'] = 'active';
            $result['saved_record'] = [
                'saved' => false,
                'readback_verified' => false,
                'id' => $logId,
                'saved_at' => (string)($log->create_time ?? date('Y-m-d H:i:s')),
                'storage' => 'agent_logs.context_data',
                'action' => 'ota_diagnosis',
                'readback_identity_digest' => $readbackIdentityDigest,
            ];
            $context = [
                'schema_version' => $schemaVersion,
                'record_type' => 'ota_diagnosis',
                'record_status' => 'active',
                'platform' => strtolower($platform),
                'date_range' => $dateRange,
                'requested_date_range' => $requestedDateRange,
                'decision_status' => $decisionStatus,
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => $this->buildOtaDiagnosisSnapshot($result),
            ];
            $log->context_data = $context;
            $log->save();

            $stored = AgentLog::where('id', $logId)
                ->where('hotel_id', $resolvedHotelId)
                ->where('action', 'ota_diagnosis')
                ->find();
            $storedContext = $stored?->context_data ?? [];
            if (is_string($storedContext)) {
                $decoded = json_decode($storedContext, true);
                $storedContext = is_array($decoded) ? $decoded : [];
            }
            $storedSnapshot = is_array($storedContext['diagnosis_result'] ?? null)
                ? $storedContext['diagnosis_result']
                : [];
            $this->assertOtaDiagnosisDecisionEvidenceScope(
                $storedSnapshot,
                $resolvedHotelId,
                $platform,
                $requestedDateRange,
                true
            );
            if (!is_array($storedContext)
                || (int)($storedContext['schema_version'] ?? 0) !== $schemaVersion
                || (string)($storedContext['record_status'] ?? '') !== 'active'
                || strtolower((string)($storedContext['platform'] ?? '')) !== strtolower($platform)
                || $this->normalizeOtaDiagnosisScopeDateRange((array)($storedContext['requested_date_range'] ?? [])) !== $requestedDateRange
                || (string)($storedContext['readback_identity_digest'] ?? '') !== $readbackIdentityDigest
                || $this->otaDiagnosisReadbackIdentity(
                    $storedSnapshot,
                    $resolvedHotelId,
                    $platform,
                    $schemaVersion
                ) !== $readbackIdentity
                || !is_array($storedSnapshot['evidence_sources'] ?? null)
                || (string)($storedSnapshot['decision_route']['version'] ?? '') !== (string)($result['decision_route']['version'] ?? '')
            ) {
                throw new \RuntimeException('OTA diagnosis save readback verification failed');
            }

            $supersededCount = $this->supersedePriorOtaDiagnosisRecords(
                $resolvedHotelId,
                strtolower($platform),
                $requestedDateRange,
                $logId
            );
            $result['saved_record']['saved'] = true;
            $result['saved_record']['readback_verified'] = true;
            $result['saved_record']['readback_verified_at'] = date('Y-m-d H:i:s');
            $result['saved_record']['superseded_prior_count'] = $supersededCount;
            $context['diagnosis_result'] = $this->buildOtaDiagnosisSnapshot($result);
            $log->context_data = $context;
            $log->save();

            $verified = AgentLog::where('id', $logId)->where('hotel_id', $resolvedHotelId)->find();
            $verifiedContext = $verified?->context_data ?? [];
            if (is_string($verifiedContext)) {
                $decoded = json_decode($verifiedContext, true);
                $verifiedContext = is_array($decoded) ? $decoded : [];
            }
            $verifiedSnapshot = is_array($verifiedContext['diagnosis_result'] ?? null)
                ? $verifiedContext['diagnosis_result']
                : [];
            $this->assertOtaDiagnosisDecisionEvidenceScope(
                $verifiedSnapshot,
                $resolvedHotelId,
                $platform,
                $requestedDateRange,
                true
            );
            if (($verifiedContext['diagnosis_result']['saved_record']['saved'] ?? false) !== true
                || ($verifiedContext['diagnosis_result']['saved_record']['readback_verified'] ?? false) !== true
                || (string)($verifiedContext['readback_identity_digest'] ?? '') !== $readbackIdentityDigest
                || (string)($verifiedContext['diagnosis_result']['decision_route']['version'] ?? '') !== (string)($result['decision_route']['version'] ?? '')
                || $this->otaDiagnosisReadbackIdentity(
                    is_array($verifiedContext['diagnosis_result'] ?? null) ? $verifiedContext['diagnosis_result'] : [],
                    $resolvedHotelId,
                    $platform,
                    $schemaVersion
                ) !== $readbackIdentity
            ) {
                throw new \RuntimeException('OTA diagnosis final readback verification failed');
            }
        });

        return $result;
    }

    private function assertOtaDiagnosisDecisionEvidenceScope(
        array $snapshot,
        int $hotelId,
        string $platform,
        array $requestedDateRange,
        bool $lockRows = false
    ): void {
        $platform = strtolower(trim($platform));
        $startDate = trim((string)($requestedDateRange['start_date'] ?? ''));
        $endDate = trim((string)($requestedDateRange['end_date'] ?? ''));
        $expectedPlatforms = [];

        if (is_array($snapshot['operating_radar'] ?? null)) {
            $this->assertCtripOperatingRadarScope(
                $snapshot['operating_radar'],
                $snapshot,
                $hotelId,
                $platform,
                ['start_date' => $startDate, 'end_date' => $endDate]
            );
        }

        foreach ((array)($snapshot['evidence_sources'] ?? []) as $source) {
            if (!is_array($source)
                || ($source['decision_eligible'] ?? false) !== true
                || preg_match('/^online_daily_data#(\d+)$/', trim((string)($source['ref'] ?? '')), $matches) !== 1
            ) {
                continue;
            }
            $rowId = (int)($matches[1] ?? 0);
            $sourcePlatform = strtolower(trim((string)($source['platform'] ?? '')));
            if ($rowId <= 0
                || !in_array($sourcePlatform, ['ctrip', 'qunar', 'meituan'], true)
                || ($platform === 'all_ota' && !in_array($sourcePlatform, ['ctrip', 'meituan'], true))
                || ($platform !== 'all_ota' && $sourcePlatform !== $platform)
            ) {
                throw new \RuntimeException('OTA diagnosis decision evidence scope mismatch');
            }
            if (isset($expectedPlatforms[$rowId]) && $expectedPlatforms[$rowId] !== $sourcePlatform) {
                throw new \RuntimeException('OTA diagnosis decision evidence platform conflict');
            }
            $expectedPlatforms[$rowId] = $sourcePlatform;
        }

        foreach ((array)($snapshot['action_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['evidence_refs'] ?? []) as $ref) {
                if (preg_match('/^online_daily_data#(\d+)$/', trim((string)$ref), $matches) === 1
                    && !isset($expectedPlatforms[(int)($matches[1] ?? 0)])
                ) {
                    throw new \RuntimeException('OTA diagnosis action evidence is not decision eligible');
                }
            }
        }

        if ($expectedPlatforms === []) {
            return;
        }
        if ($hotelId <= 0 || $startDate === '' || $endDate === '' || !$this->tableExists('online_daily_data')) {
            throw new \RuntimeException('OTA diagnosis decision evidence cannot be read back');
        }

        $columns = $this->tableColumns('online_daily_data');
        foreach (['id', 'tenant_id', 'system_hotel_id', 'data_date', 'readback_verified', 'validation_status'] as $required) {
            if (!isset($columns[$required])) {
                throw new \RuntimeException('OTA diagnosis evidence readback contract is incomplete');
            }
        }
        if (!isset($columns['platform']) && !isset($columns['source'])) {
            throw new \RuntimeException('OTA diagnosis platform identity is unavailable');
        }

        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'data_date', 'source', 'platform',
            'data_type', 'dimension', 'raw_data', 'readback_verified', 'validation_status',
        ], array_keys($columns)));
        $rowQuery = Db::name('online_daily_data')
            ->field(implode(',', $fields))
            ->whereIn('id', array_keys($expectedPlatforms));
        if ($lockRows) {
            $rowQuery->lock(true);
        }
        $rows = $rowQuery->select()->toArray();
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int)($row['id'] ?? 0)] = $row;
        }
        $tenantId = $this->authoritativeTenantIdForHotel($hotelId);
        if ($tenantId <= 0) {
            throw new \RuntimeException('OTA diagnosis tenant identity is unavailable');
        }

        foreach ($expectedPlatforms as $rowId => $expectedPlatform) {
            $row = $rowsById[$rowId] ?? null;
            if (!is_array($row)
                || (int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($row['readback_verified'] ?? 0) !== 1
                || !$this->isOtaDiagnosisDecisionEligibleRow($row)
            ) {
                throw new \RuntimeException('OTA diagnosis decision evidence readback failed');
            }
            $actualPlatform = isset($columns['platform'])
                ? strtolower(trim((string)($row['platform'] ?? '')))
                : strtolower(trim((string)($row['source'] ?? '')));
            $rowDate = trim((string)($row['data_date'] ?? ''));
            if ($actualPlatform !== $expectedPlatform
                || $rowDate < $startDate
                || $rowDate > $endDate
            ) {
                throw new \RuntimeException('OTA diagnosis decision evidence identity mismatch');
            }
        }
    }

    /** @param array<string,mixed> $radar @param array<string,mixed> $snapshot */
    private function assertCtripOperatingRadarScope(
        array $radar,
        array $snapshot,
        int $hotelId,
        string $platform,
        array $requestedDateRange
    ): void {
        $expectedDimensionKeys = [
            'information_score',
            'friendliness',
            'quality',
            'welcome',
            'platform_technical_service_fee',
        ];
        $scope = is_array($radar['scope'] ?? null) ? $radar['scope'] : [];
        $scorePolicy = is_array($radar['score_policy'] ?? null) ? $radar['score_policy'] : [];
        $guards = is_array($radar['guards'] ?? null) ? $radar['guards'] : [];
        $dimensions = array_values(array_filter((array)($radar['dimensions'] ?? []), 'is_array'));
        $dimensionKeys = array_map(static fn(array $dimension): string => (string)($dimension['key'] ?? ''), $dimensions);
        $platform = strtolower(trim($platform));

        if ($platform !== 'ctrip'
            || (int)($radar['schema_version'] ?? 0) !== 2
            || (string)($radar['contract_version'] ?? '') !== 'ctrip_operating_radar.v2'
            || (string)($radar['knowledge']['module_id'] ?? '') !== 'ctrip_hotel_operating_radar'
            || (string)($radar['knowledge']['truth_profile_version'] ?? '') !== '2026-08-11.4'
            || (int)($scope['hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($scope['platform'] ?? ''))) !== 'ctrip'
            || (string)($scope['source_scope'] ?? '') !== 'ctrip_ota_channel_only'
            || (string)($scope['requested_start_date'] ?? '') !== (string)($requestedDateRange['start_date'] ?? '')
            || (string)($scope['requested_end_date'] ?? '') !== (string)($requestedDateRange['end_date'] ?? '')
            || $dimensionKeys !== $expectedDimensionKeys
        ) {
            throw new \RuntimeException('Ctrip operating radar identity or dimension contract mismatch');
        }

        if (($scorePolicy['official_score_available'] ?? null) !== false
            || ($scorePolicy['official_weights_available'] ?? null) !== false
            || ($scorePolicy['official_formula_available'] ?? null) !== false
            || array_key_exists('composite_score', $scorePolicy) === false
            || $scorePolicy['composite_score'] !== null
            || ($scorePolicy['single_dimension_determines_result'] ?? null) !== false
        ) {
            throw new \RuntimeException('Ctrip operating radar must not infer official scores, weights, formula, or ranking');
        }

        foreach ([
            'decision_safe',
            'task_draft_safe',
            'external_write_authorized',
            'automatic_pricing',
            'automatic_inventory_change',
            'automatic_commission_change',
            'automatic_marketing',
            'automatic_ota_write',
            'automatic_pms_write',
        ] as $guardKey) {
            if (($guards[$guardKey] ?? null) !== false) {
                throw new \RuntimeException('Ctrip operating radar safety guard mismatch: ' . $guardKey);
            }
        }

        $evidenceSources = [];
        foreach ((array)($snapshot['evidence_sources'] ?? []) as $source) {
            if (!is_array($source)) {
                continue;
            }
            $ref = trim((string)($source['ref'] ?? ''));
            if ($ref !== '') {
                $evidenceSources[$ref] = $source;
            }
        }
        foreach ($dimensions as $dimension) {
            if (!array_key_exists('official_score', $dimension) || $dimension['official_score'] !== null) {
                throw new \RuntimeException('Ctrip operating radar dimension must not expose an inferred official score');
            }
            if (!in_array((string)($dimension['status'] ?? ''), ['observed_channel_signal', 'partial_evidence', 'blocked_by_data'], true)) {
                throw new \RuntimeException('Ctrip operating radar dimension has an unsupported evidence status');
            }
            $metricKeys = [];
            foreach ((array)($dimension['metrics'] ?? []) as $metric) {
                if (is_array($metric)) {
                    $metricKeys[] = (string)($metric['key'] ?? '');
                }
            }
            if ((string)($dimension['key'] ?? '') === 'platform_technical_service_fee'
                && in_array('commission_rate', $metricKeys, true)
            ) {
                throw new \RuntimeException('Ctrip commission rate must not substitute for technical service fee');
            }
            $validRootEvidenceRefs = [];
            foreach ((array)($dimension['evidence_refs'] ?? []) as $refValue) {
                $ref = trim((string)$refValue);
                $source = $evidenceSources[$ref] ?? null;
                if ($ref === '' || !is_array($source)) {
                    throw new \RuntimeException('Ctrip operating radar evidence reference is not in the saved diagnosis snapshot');
                }
                $sourceTable = strtolower(trim((string)($source['table'] ?? '')));
                if ($ref === 'source_summary') {
                    if ($sourceTable !== 'derived') {
                        throw new \RuntimeException('Ctrip operating radar source summary must remain derived channel evidence');
                    }
                    continue;
                }
                if (in_array($ref, ['ota_no_data_scope', 'ota_latest_available_not_target_date'], true)) {
                    if ($sourceTable !== 'derived') {
                        throw new \RuntimeException('Ctrip operating radar scope marker must remain derived evidence');
                    }
                    continue;
                }
                if (!str_starts_with($ref, 'online_daily_data#')
                    || $sourceTable !== 'online_daily_data'
                    || ($source['decision_eligible'] ?? false) !== true
                    || strtolower(trim((string)($source['platform'] ?? ''))) !== 'ctrip'
                ) {
                    throw new \RuntimeException('Ctrip operating radar evidence must use only decision-eligible Ctrip channel rows');
                }
                $validRootEvidenceRefs[] = $ref;
            }
            $dimensionStatus = (string)($dimension['status'] ?? '');
            $declaredRootRefs = array_values(array_map('strval', (array)($dimension['root_evidence_refs'] ?? [])));
            sort($declaredRootRefs, SORT_STRING);
            $validRootEvidenceRefs = array_values(array_unique($validRootEvidenceRefs));
            sort($validRootEvidenceRefs, SORT_STRING);
            if ($declaredRootRefs !== $validRootEvidenceRefs
                || (string)($dimension['root_evidence_status'] ?? '') !== ($validRootEvidenceRefs === [] ? 'missing' : 'verified')
            ) {
                throw new \RuntimeException('Ctrip operating radar root evidence status mismatch');
            }
            if ($dimensionStatus !== 'blocked_by_data' && $validRootEvidenceRefs === []) {
                throw new \RuntimeException('Ctrip operating radar non-blocked dimension requires a Ctrip channel root row');
            }
        }
    }

    private function supersedePriorOtaDiagnosisRecords(int $hotelId, string $platform, array $dateRange, int $newLogId): int
    {
        $targetRange = $this->normalizeOtaDiagnosisScopeDateRange($dateRange);
        if ($hotelId <= 0 || $newLogId <= 0 || $targetRange['start_date'] === '' || $targetRange['end_date'] === '') {
            return 0;
        }

        $superseded = 0;
        $records = AgentLog::where('hotel_id', $hotelId)
            ->where('agent_type', AgentLog::AGENT_TYPE_REVENUE)
            ->where('action', 'ota_diagnosis')
            ->where('id', '<', $newLogId)
            ->order('id', 'desc')
            ->lock(true)
            ->select();
        $operationService = new OperationManagementService();
        foreach ($records as $record) {
            $context = $record->context_data;
            if (is_string($context)) {
                $decoded = json_decode($context, true);
                $context = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($context) || ($context['record_status'] ?? '') === 'superseded') {
                continue;
            }
            $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
            $recordRange = $this->normalizeOtaDiagnosisScopeDateRange(
                is_array($snapshot['requested_date_range'] ?? null)
                    ? $snapshot['requested_date_range']
                    : (is_array($context['requested_date_range'] ?? null)
                        ? $context['requested_date_range']
                        : (array)($context['date_range'] ?? []))
            );
            $recordPlatform = strtolower((string)($context['platform'] ?? $snapshot['platform'] ?? ''));
            if ($recordPlatform !== $platform
                || $recordRange !== $targetRange
            ) {
                continue;
            }
            if ($operationService->hasOtaDiagnosisExecutionReference($hotelId, (int)$record->id)) {
                continue;
            }

            $supersededAt = date('Y-m-d H:i:s');
            $context['record_status'] = 'superseded';
            $context['superseded_by_log_id'] = $newLogId;
            $context['superseded_at'] = $supersededAt;
            $context['superseded_reason'] = 'newer_same_scope_diagnosis_saved';
            if (is_array($context['diagnosis_result'] ?? null)) {
                $context['diagnosis_result']['record_status'] = 'superseded';
                $context['diagnosis_result']['superseded_by'] = [
                    'log_id' => $newLogId,
                    'superseded_at' => $supersededAt,
                    'reason' => 'newer_same_scope_diagnosis_saved',
                ];
                if (is_array($context['diagnosis_result']['saved_record'] ?? null)) {
                    $context['diagnosis_result']['saved_record']['status'] = 'superseded';
                    $context['diagnosis_result']['saved_record']['superseded_by_log_id'] = $newLogId;
                }
            }
            $record->context_data = $context;
            $record->save();
            $superseded++;
        }

        return $superseded;
    }

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

    private function buildOtaDiagnosisExecutionIntentInput(
        array $snapshot,
        array $action,
        int $recordId,
        int $hotelId,
        array $scheduleInput = []
    ): array
    {
        $platform = strtolower(trim((string)($snapshot['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            throw new \InvalidArgumentException('saved OTA diagnosis platform is invalid');
        }
        $dateRange = is_array($snapshot['date_range'] ?? null) ? $snapshot['date_range'] : [];
        $dateStart = trim((string)($dateRange['start_date'] ?? ''));
        $dateEnd = trim((string)($dateRange['end_date'] ?? $dateStart));
        if (!$this->isDateString($dateStart) || !$this->isDateString($dateEnd)) {
            throw new \InvalidArgumentException('saved OTA diagnosis date range is invalid');
        }
        if (($snapshot['decision_status'] ?? $snapshot['decision_closure']['status'] ?? '') !== 'action_required') {
            throw new \InvalidArgumentException('saved OTA diagnosis is not action_required');
        }
        if (($action['execution_ready'] ?? false) !== true
            || ($action['can_request_execution_intent'] ?? false) !== true
            || !$this->isOtaDiagnosisActionDecisionQualityExecutionReady($action)
        ) {
            throw new \InvalidArgumentException('saved OTA diagnosis action is not execution ready');
        }

        $actionText = trim((string)($action['action'] ?? ''));
        if ($actionText === '') {
            throw new \InvalidArgumentException('saved OTA diagnosis action text is missing');
        }
        $snapshotMetrics = is_array($snapshot['metrics'] ?? null) ? $snapshot['metrics'] : [];
        [$actionType, $targetMetric] = $this->classifyOtaDiagnosisExecutionAction($actionText, $snapshotMetrics);
        $savedActionType = trim((string)($action['action_type'] ?? ''));
        $savedTargetMetric = strtolower(trim((string)($action['expected_metric'] ?? '')));
        if (($savedActionType !== '' && $savedActionType !== $actionType)
            || ($savedTargetMetric !== '' && $savedTargetMetric !== $targetMetric)
        ) {
            throw new \InvalidArgumentException(
                'saved OTA diagnosis action metric binding is stale; regenerate the diagnosis before creating an execution intent'
            );
        }
        if (!array_key_exists($targetMetric, $snapshotMetrics) || !is_numeric($snapshotMetrics[$targetMetric])) {
            throw new \InvalidArgumentException(
                'saved OTA diagnosis action is missing a numeric same-criterion baseline for ' . $targetMetric
            );
        }
        $evidenceRefs = array_values(array_unique(array_filter(array_map('strval', (array)($action['evidence_refs'] ?? [])))));
        if (empty($evidenceRefs)) {
            throw new \InvalidArgumentException('saved OTA diagnosis action evidence refs are missing');
        }

        $referencedEvidence = [];
        foreach ((array)($snapshot['evidence_sources'] ?? []) as $source) {
            if (!is_array($source) || !in_array((string)($source['ref'] ?? ''), $evidenceRefs, true)) {
                continue;
            }
            $referencedEvidence[] = $source;
        }
        $metricEvidenceStatus = $this->otaDiagnosisExecutionMetricEvidenceStatus(
            $targetMetric,
            $platform,
            $evidenceRefs,
            $referencedEvidence
        );
        $metricSemantic = is_array($metricEvidenceStatus['semantic'] ?? null)
            ? $metricEvidenceStatus['semantic']
            : [];
        if (($metricEvidenceStatus['status'] ?? '') !== 'ready') {
            throw new \InvalidArgumentException(
                (string)($metricEvidenceStatus['message'] ?? 'saved OTA diagnosis metric evidence is not execution ready')
            );
        }
        if ($targetMetric === 'list_exposure') {
            $baseline = (float)$snapshotMetrics[$targetMetric];
            if ($platform !== 'ctrip'
                || $baseline < 0.0
                || floor($baseline) !== $baseline
                || $metricSemantic === []
                || !is_array($action['metric_semantic'] ?? null)
                || $action['metric_semantic'] !== $metricSemantic
            ) {
                throw new \InvalidArgumentException(
                    'saved OTA diagnosis list_exposure binding must use the frozen Ctrip unique-user integer definition'
                );
            }
        }
        $currentValue = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'adr', 'list_exposure', 'detail_visitors', 'flow_rate',
            'order_visitors', 'submit_users', 'detail_rate', 'order_rate', 'submit_rate',
            'advertising_spend', 'advertising_order_amount', 'advertising_roas', 'avg_psi_score', 'avg_service_score',
        ] as $metric) {
            $value = $snapshot['metrics'][$metric] ?? null;
            if ($value !== null && $value !== '') {
                $currentValue[$metric] = $value;
            }
        }
        $priority = strtolower(trim((string)($snapshot['priority'] ?? 'medium')));
        $workflowSchedule = $this->normalizeOtaDiagnosisExecutionSchedule($scheduleInput, $dateEnd);

        return [
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $recordId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'object_type' => 'campaign',
            'action_type' => $actionType,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => $currentValue,
            'target_value' => [
                'campaign_type' => $actionType,
                'target_metric' => $targetMetric,
                'expected_direction' => 'increase',
                'metric_semantic' => $metricSemantic,
                'action_text' => $actionText,
                'measurement_policy' => 'target_not_quantified_until_manual_confirmation',
                'assignee_id' => $workflowSchedule['assignee_id'],
                'due_at' => $workflowSchedule['due_at'],
                'review_at' => $workflowSchedule['review_at'],
                'workflow_schedule' => $workflowSchedule,
            ],
            'evidence' => [
                'evidence_refs' => $evidenceRefs,
                'evidence_sources' => $referencedEvidence,
                'derived_metric_lineage' => $snapshot['derived_metric_lineage'] ?? [],
                'data_gaps' => [],
                'optional_data_gaps' => $snapshot['optional_data_gaps'] ?? [],
                'source_policy' => 'saved_ota_diagnosis_evidence_only',
                'protected_boundary' => '人工审批和平台外执行；不自动修改 OTA 价格、库存、广告或竞争圈数据。',
                'diagnosis_log_id' => $recordId,
                'action_item_id' => (string)($action['id'] ?? ''),
                'action_item_status' => (string)($action['status'] ?? ''),
                'diagnosis_summary' => (string)($snapshot['core_conclusion'] ?? $snapshot['diagnosis']['summary'] ?? ''),
                'metric_scope' => 'ota_channel',
                'metric_semantic' => $metricSemantic,
                'expected_delta_status' => 'not_quantified',
                'expected_direction' => 'increase',
                'workflow_schedule' => $workflowSchedule,
                'decision_recommendation' => $action,
            ],
            'expected_metric' => $targetMetric,
            'expected_delta' => null,
            'risk_level' => $priority === 'high' ? 'high' : ($priority === 'low' ? 'low' : 'medium'),
            'status' => 'pending_approval',
        ];
    }

    /** @return array{assignee_id:int,due_at:string,review_at:string,source_policy:string} */
    private function normalizeOtaDiagnosisExecutionSchedule(
        array $input,
        string $baselineBusinessDate = ''
    ): array
    {
        $assigneeId = (int)($input['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('assignee_id is required before creating an execution intent');
        }

        $dueAt = $this->normalizeOtaDiagnosisExecutionDateTime((string)($input['due_at'] ?? ''), 'due_at');
        $reviewAt = $this->normalizeOtaDiagnosisExecutionDateTime((string)($input['review_at'] ?? ''), 'review_at');
        if (strtotime($reviewAt) < strtotime($dueAt)) {
            throw new \InvalidArgumentException('review_at must not be earlier than due_at');
        }
        if ($baselineBusinessDate !== '') {
            if (!$this->isDateString($baselineBusinessDate)) {
                throw new \InvalidArgumentException('diagnosis baseline business date is invalid');
            }
            $expectedReviewBusinessDate = (new \DateTimeImmutable($baselineBusinessDate))
                ->modify('+1 day')
                ->format('Y-m-d');
            if (substr($reviewAt, 0, 10) !== $expectedReviewBusinessDate) {
                throw new \InvalidArgumentException(
                    'review_at must use the diagnosis next calendar business date: '
                    . $expectedReviewBusinessDate
                );
            }
        }

        return [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
            'review_at' => $reviewAt,
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];
    }

    private function normalizeOtaDiagnosisExecutionDateTime(string $value, string $field): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' is required before creating an execution intent');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException($field . ' must be a valid date-time');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function assertOtaDiagnosisExecutionAssigneeScope(int $assigneeId, int $hotelId): void
    {
        $assignee = UserModel::where('id', $assigneeId)->where('status', UserModel::STATUS_ENABLED)->find();
        if (!$assignee) {
            throw new \InvalidArgumentException('assignee_id must reference an enabled user');
        }
        if (!$assignee->hasHotelPermission($hotelId, 'operation.execute')) {
            throw new \InvalidArgumentException('assignee_id lacks operation.execute permission for the diagnosis hotel');
        }
    }

    private function classifyOtaDiagnosisExecutionAction(string $action, array $metrics = []): array
    {
        if ($this->textContainsAny($action, ['广告', '投放', 'ROAS', 'roas'])) {
            return ['advertising_optimization', 'advertising_roas'];
        }
        if ($this->textContainsAny($action, ['服务质量', '服务分', 'PSI', 'psi'])) {
            return ['service_quality_improvement', 'avg_psi_score'];
        }
        if ($this->textContainsAny($action, ['曝光', '列表页', '可售状态', '曝光入口', 'exposure', 'listing'])
            && array_key_exists('list_exposure', $metrics)
            && is_numeric($metrics['list_exposure'])
            && (float)$metrics['list_exposure'] === 0.0
        ) {
            return ['listing_exposure_recovery', 'list_exposure'];
        }
        if ($this->textContainsAny($action, ['曝光', '列表页', '主图', '标题', 'exposure', 'listing', 'main image', 'title'])) {
            return ['listing_conversion_optimization', 'detail_rate'];
        }
        if ($this->textContainsAny($action, ['下单', '订单', '转化', '房型', '取消政策'])) {
            return ['booking_conversion_optimization', 'order_rate'];
        }

        return ['ota_operation_follow_up', 'book_order_num'];
    }

    private function buildAiDecisionClosure(array $result): array
    {
        $actionItems = array_values(array_filter((array)($result['action_items'] ?? []), 'is_array'));
        $readyItems = array_values(array_filter($actionItems, static fn(array $item): bool => ($item['execution_ready'] ?? false) === true));
        $blockedItems = array_values(array_filter($actionItems, static function (array $item): bool {
            $status = (string)($item['status'] ?? '');
            return str_starts_with($status, 'blocked_') || ($item['execution_ready'] ?? true) === false;
        }));
        $dataGaps = $this->normalizeOtaDiagnosisDataGaps($result['data_gaps'] ?? []);
        $blockingDataGaps = $this->blockingOtaDiagnosisDataGaps($dataGaps, $result);
        $blockingGapCodes = array_values(array_filter(array_map(
            static fn(array $gap): string => trim((string)($gap['code'] ?? '')),
            $blockingDataGaps
        )));
        $optionalDataGaps = array_values(array_filter($dataGaps, static function (array $gap) use ($blockingGapCodes): bool {
            return !in_array(trim((string)($gap['code'] ?? '')), $blockingGapCodes, true);
        }));
        $unresolvedProblems = array_values(array_filter(array_map(
            static fn(mixed $problem): string => trim((string)$problem),
            (array)($result['main_problems'] ?? $result['diagnosis']['abnormal_metrics'] ?? [])
        )));
        $governance = is_array($result['ai_governance'] ?? null) ? $result['ai_governance'] : [];
        $blockedReasons = [];
        foreach ($blockedItems as $item) {
            $reason = trim((string)($item['blocked_reason'] ?? ''));
            if ($reason !== '') {
                $blockedReasons[] = $reason;
            }
        }
        foreach ($blockingDataGaps as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            if ($code !== '') {
                $blockedReasons[] = $code;
            }
        }
        if (empty($actionItems) && !empty($unresolvedProblems)) {
            $blockedReasons[] = 'unresolved_diagnostic_signal_without_evidence_backed_action';
        }
        $blockedReasons = array_values(array_unique($blockedReasons));
        $missingTargetFacts = $this->otaDiagnosisHasMissingTargetFacts($result, $dataGaps);
        $status = 'action_required';
        if ($missingTargetFacts) {
            $status = 'blocked_by_missing_facts';
        } elseif (!empty($blockingDataGaps) || (empty($actionItems) && !empty($unresolvedProblems))) {
            $status = 'blocked_by_data';
        } elseif (!empty($readyItems)) {
            $status = 'action_required';
        } elseif (empty($actionItems)) {
            $status = 'no_action';
        } else {
            $status = 'blocked_by_data';
        }
        $isBlocked = in_array($status, ['blocked_by_data', 'blocked_by_missing_facts'], true);
        $isNoAction = $status === 'no_action';
        $legacyStatus = $isBlocked ? 'blocked' : ($isNoAction ? 'ready' : 'pending_human_confirmation');

        return [
            'status' => $status,
            'legacy_status' => $legacyStatus,
            'scope' => 'ota_channel',
            'chain' => 'OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions',
            'data_evidence_input' => [
                'source_policy' => (string)($result['source_policy'] ?? 'database_only'),
                'source_counts' => $result['data_summary']['source_counts'] ?? [],
                'evidence_refs' => $this->extractAiEvidenceRefs($result),
                'data_gaps' => $dataGaps,
                'blocking_data_gaps' => $blockingDataGaps,
                'optional_data_gaps' => $optionalDataGaps,
                'enough_for_decision' => !$isBlocked,
                'enough_for_executable_actions' => !$isBlocked && !empty($readyItems),
            ],
            'diagnostic_conclusion' => [
                'summary' => (string)($result['core_conclusion'] ?? $result['diagnosis']['summary'] ?? ''),
                'main_problems' => $result['main_problems'] ?? [],
                'possible_reasons' => $result['possible_reasons'] ?? [],
                'confidence_level' => (string)($governance['confidence_level'] ?? ''),
            ],
            'suggested_actions' => [
                'ready_count' => count($readyItems),
                'blocked_count' => count($blockedItems),
                'decision' => $isNoAction ? 'no_new_action' : ($isBlocked ? 'resolve_data_gaps' : 'manual_confirmation_required'),
                'items' => $actionItems,
            ],
            'blocked_state' => [
                'is_blocked' => $isBlocked,
                'blocked_reasons' => $blockedReasons,
                'blocked_items' => array_map(static fn(array $item): array => [
                    'id' => (string)($item['id'] ?? ''),
                    'status' => (string)($item['status'] ?? ''),
                    'blocked_reason' => (string)($item['blocked_reason'] ?? ''),
                    'missing_evidence' => $item['missing_evidence'] ?? [],
                ], $blockedItems),
            ],
            'human_confirmation' => [
                'required' => $status === 'action_required',
                'status' => $status === 'action_required' ? 'pending' : 'not_required',
                'reason' => $isNoAction
                    ? '本次没有达到行动阈值的证据，不创建运营执行意图。'
                    : ($isBlocked
                        ? '先补齐目标日期核心 OTA 证据，再重新生成诊断。'
                        : (string)($governance['human_confirmation_reason'] ?? 'manual confirmation required before operation execution')),
                'ready_action_ids' => array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $readyItems)),
                'confirm_before_execution' => $status === 'action_required',
            ],
        ];
    }

    /** @param list<array<string,mixed>> $dataGaps */
    private function otaDiagnosisHasMissingTargetFacts(array $result, array $dataGaps = []): bool
    {
        if (($result['workflow_status'] ?? '') === 'blocked_by_missing_facts') {
            return true;
        }
        if (($result['data_summary']['used_latest_available_data'] ?? false) === true
            || ($result['data_summary']['has_ota_data'] ?? null) === false
        ) {
            return true;
        }

        $blockingGaps = $this->blockingOtaDiagnosisDataGaps($dataGaps, $result);
        $codes = array_values(array_filter(array_map(
            static fn(array $gap): string => strtolower(trim((string)($gap['code'] ?? ''))),
            $blockingGaps
        )));
        foreach ($codes as $code) {
            if (in_array($code, [
                'ota_same_period_source_rows_missing',
                'ota_requested_period_source_rows_missing_used_latest_available',
                'all_ota_platform_source_missing',
            ], true)
                || str_contains($code, 'same_period_source_rows_missing')
                || str_starts_with($code, 'metric_missing:')
                || str_starts_with($code, 'missing_verified_field_fact:')
            ) {
                return true;
            }
        }

        return false;
    }

    private function buildOtaEvidenceTags(string $table, array $row): array
    {
        $tags = [$table];
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        if ($dataType !== '') {
            $tags[] = $dataType;
        }
        if (($row['compare_type'] ?? '') === 'competitor') {
            $tags[] = 'competitor';
        }
        $hasKnownTraffic = $this->hasKnownOtaDiagnosisMetric($row, [
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        ]);
        if ($hasKnownTraffic) {
            $tags[] = 'traffic';
        }
        $isNonRevenueType = in_array($dataType, ['advertising', 'quality', 'review', 'ads', 'ad', 'campaign'], true);
        $hasKnownRevenue = $this->hasKnownOtaDiagnosisMetric($row, ['amount', 'quantity', 'book_order_num']);
        if (!$isNonRevenueType && $hasKnownRevenue) {
            $tags[] = 'revenue';
        }
        if (!$isNonRevenueType && $this->hasKnownOtaDiagnosisMetric($row, ['book_order_num', 'order_filling_num', 'order_submit_num'])) {
            $tags[] = 'order';
        }
        if ($this->hasKnownOtaDiagnosisMetric($row, ['order_visitors', 'submit_users'])) {
            $tags[] = 'order';
        }
        $amount = $this->readRowNumber($row, 'amount');
        $quantity = $this->readRowNumber($row, 'quantity');
        if (!$isNonRevenueType && ($this->hasKnownOtaDiagnosisMetric($row, ['adr', 'price', 'our_price', 'current_price']) || ($amount !== null && $quantity !== null && $quantity > 0))) {
            $tags[] = 'price';
        }
        if (in_array($dataType, ['advertising', 'ads', 'ad', 'campaign'], true)) {
            $tags[] = 'advertising';
        }
        if (in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
            $tags[] = 'service_quality';
        }
        return array_values(array_unique($tags));
    }

    private function buildOtaEvidenceMetricPreview(array $row): array
    {
        $preview = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'adr', 'revenue', 'price', 'our_price', 'competitor_price',
            'current_price', 'suggested_price', 'list_exposure', 'detail_visitors', 'detail_exposure',
            'order_visitors', 'submit_users', 'order_filling_num', 'order_submit_num',
            'detail_rate', 'order_rate', 'submit_rate',
            'advertising_spend', 'advertising_order_amount', 'advertising_roas', 'avg_psi_score', 'avg_service_score',
            'occupancy_rate', 'room_count', 'guest_count',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $preview[$field] = $row[$field];
            }
        }
        return $preview;
    }

    private function selectOtaEvidenceRefsForAction(string $action, array $evidenceSources): array
    {
        $wantedTags = ['summary'];
        if ($this->textContainsAny($action, ['广告', '投放', 'ROAS', 'roi', 'ad', 'ads', 'advertising', 'campaign'])) {
            $wantedTags[] = 'advertising';
        }
        if ($this->textContainsAny($action, ['服务质量', '服务分', 'PSI', 'psi', 'service', 'quality'])) {
            $wantedTags[] = 'service_quality';
        }
        if ($this->textContainsAny($action, ['曝光', '访问', '流量', '列表', '详情', 'traffic', 'exposure'])) {
            $wantedTags[] = 'traffic';
        }
        if ($this->textContainsAny($action, ['价格', '竞对', 'ADR', '房型', '促销', 'price', 'competitor'])) {
            $wantedTags[] = 'price';
            $wantedTags[] = 'competitor';
        }
        if ($this->textContainsAny($action, ['订单', '下单', '转化', '间夜', 'order', 'conversion'])) {
            $wantedTags[] = 'order';
            $wantedTags[] = 'traffic';
        }
        if ($this->textContainsAny($action, ['补齐', '缺失', '同步', '抓取', '数据源', 'sync', 'missing'])) {
            $wantedTags[] = 'sync_log';
            $wantedTags[] = 'collection';
        }

        $refs = [];
        foreach ($evidenceSources as $source) {
            if (($source['decision_eligible'] ?? false) !== true) {
                continue;
            }
            $sourceTags = is_array($source['tags'] ?? null) ? $source['tags'] : [];
            if (empty(array_intersect($wantedTags, $sourceTags))) {
                continue;
            }
            $ref = (string)($source['ref'] ?? '');
            if ($ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
            if (count($refs) >= 5) {
                break;
            }
        }

        if (empty($refs)) {
            foreach ($evidenceSources as $source) {
                if (($source['decision_eligible'] ?? false) !== true) {
                    continue;
                }
                $ref = (string)($source['ref'] ?? '');
                if ($ref !== '' && !in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
                if (count($refs) >= 3) {
                    break;
                }
            }
        }

        return $refs;
    }

    private function hasCompareRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['compare_type'] ?? '') === 'competitor') {
                return true;
            }
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (!array_key_exists($table, $cache)) {
            try {
                $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
            } catch (\Throwable) {
                $cache[$table] = !empty(Db::query('PRAGMA table_info(`' . $table . '`)'));
            }
        }
        return $cache[$table];
    }

    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!$this->tableExists($table)) {
            $cache[$table] = [];
            return [];
        }

        $columns = [];
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($rows as $row) {
                if (!empty($row['Field'])) {
                    $columns[(string)$row['Field']] = true;
                }
            }
        } catch (\Throwable) {
            foreach (Db::query('PRAGMA table_info(`' . $table . '`)') as $row) {
                if (!empty($row['name'])) {
                    $columns[(string)$row['name']] = true;
                }
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }

    private function authoritativeTenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            return 0;
        }

        try {
            return max(0, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function existingFields(string $table, array $fields): array
    {
        $columns = $this->tableColumns($table);
        if (empty($columns)) {
            return [];
        }
        return array_values(array_intersect($fields, array_keys($columns)));
    }

    private function queryHotelDateRows(
        string $table,
        array $fields,
        int $hotelId,
        string $dateColumn,
        string $startDate,
        string $endDate,
        string $orderBy,
        ?callable $extraFilter = null,
        string $orderDirection = 'asc',
        int $limit = 0,
        string $hotelScopeColumn = 'hotel_id'
    ): array {
        if ($hotelId <= 0) {
            return [];
        }

        $columns = $this->tableColumns($table);
        if (empty($columns) || !isset($columns[$hotelScopeColumn]) || !isset($columns[$dateColumn])) {
            return [];
        }

        $selectedFields = array_values(array_unique(array_merge($fields, [$hotelScopeColumn, $dateColumn])));
        $selectedFields = array_values(array_intersect($selectedFields, array_keys($columns)));
        if (empty($selectedFields)) {
            return [];
        }

        $query = Db::name($table)
            ->field(implode(',', $selectedFields))
            ->where($hotelScopeColumn, $hotelId)
            ->where($dateColumn, '>=', $startDate)
            ->where($dateColumn, '<=', $endDate);

        if ($extraFilter !== null) {
            $extraFilter($query, $columns);
        }

        if (isset($columns[$orderBy])) {
            $query->order($orderBy, strtolower($orderDirection) === 'desc' ? 'desc' : 'asc');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->select()->toArray();
    }

    private function otaPlatformCode(string $platform): ?int
    {
        return [
            'ctrip' => 1,
            'meituan' => 2,
            'fliggy' => 3,
            'booking' => 4,
            'expedia' => 5,
            'agoda' => 6,
        ][$platform] ?? null;
    }

    private function maxDateTime(array $values): string
    {
        $max = '';
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ($max === '' || strtotime($value) > strtotime($max))) {
                $max = $value;
            }
        }
        return $max;
    }


    private function onlineDailyDataColumns(): array
    {
        return $this->tableColumns('online_daily_data');
    }

    private function buildOtaDiagnosisSummary(array $rows, int $hotelId, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $ownHotelNames = array_values(array_filter([$hotelName], static fn ($value): bool => trim((string)$value) !== ''));
        $ownPlatformHotelIds = $this->otaDiagnosisOwnPlatformHotelIds($rows, $hotelId, $platform);
        $summary = [
            'scope' => [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'analysis_type' => $analysisType,
            ],
            'record_count' => 0,
            'date_count' => 0,
            'hotel_names' => [],
            'totals' => [
                'amount' => null,
                'quantity' => null,
                'book_order_num' => null,
                'data_value' => null,
                'list_exposure' => null,
                'detail_visitors' => null,
                'flow_rate' => null,
                'order_visitors' => null,
                'submit_users' => null,
                'advertising_spend' => null,
                'advertising_order_amount' => null,
                'advertising_bookings' => null,
                'advertising_room_nights' => null,
                'advertising_impressions' => null,
                'advertising_clicks' => null,
                'advertising_rows' => 0,
                'service_quality_rows' => 0,
                'hotel_collect' => null,
            ],
            'averages' => [
                'comment_score' => null,
                'qunar_comment_score' => null,
                'adr' => null,
                'avg_psi_score' => null,
                'avg_service_score' => null,
                'avg_im_score' => null,
                'avg_reply_rate' => null,
            ],
            'daily' => [],
            'dimensions' => [],
            'data_anomalies' => [],
        ];

        $psiScores = [];
        $serviceScores = [];
        $imScores = [];
        $replyRates = [];
        $invalidRawCount = 0;
        $zeroValueCount = 0;
        $missingCoreValueCount = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }

            $dataType = $this->normalizeOtaDiagnosisDataType((string)($row['data_type'] ?? ''));

            $raw = [];
            if (!empty($row['raw_data'])) {
                $decoded = json_decode((string) $row['raw_data'], true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $invalidRawCount++;
                }
            }

            $isOrderMetricRow = in_array($dataType, ['business', 'order'], true);
            $amount = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'amount',
                    ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount', 'payAmount'],
                    ['order_amount', 'amount']
                )
                : null;
            $quantity = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'quantity',
                    ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'nightCount', 'roomCount', 'room_count', 'checkoutRoomNights', 'checkout_room_nights', 'checkOutQuantity', 'bookQuantity'],
                    ['room_nights', 'quantity']
                )
                : null;
            $bookOrderNum = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'book_order_num',
                    ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount'],
                    ['order_count', 'book_order_num']
                )
                : null;
            $dataValue = $this->readRowNumber($row, 'data_value');

            $isOwnOperatingRow = OtaOperatingScope::isOwnOperatingRow(
                $row,
                $raw,
                $ownHotelNames,
                $ownPlatformHotelIds
            );
            if (!$isOwnOperatingRow && !in_array($dataType, ['advertising', 'quality', 'review'], true)) {
                $summary['excluded_non_operating_rows'] = (int)($summary['excluded_non_operating_rows'] ?? 0) + 1;
                continue;
            }
            $summary['record_count']++;

            if (!isset($summary['daily'][$date])) {
                $summary['daily'][$date] = [
                    'date' => $date,
                    'amount' => null,
                    'quantity' => null,
                    'book_order_num' => null,
                    'data_value' => null,
                    'list_exposure' => null,
                    'detail_visitors' => null,
                    'flow_rate' => null,
                    'order_visitors' => null,
                    'submit_users' => null,
                    'advertising_spend' => null,
                    'advertising_order_amount' => null,
                    'advertising_bookings' => null,
                    'advertising_room_nights' => null,
                    'advertising_impressions' => null,
                    'advertising_clicks' => null,
                    'advertising_rows' => 0,
                    'service_quality_rows' => 0,
                    'hotel_collect' => null,
                ];
            }

            $rowHotelName = trim((string) ($row['hotel_name'] ?? ''));
            if ($rowHotelName !== '') {
                $summary['hotel_names'][$rowHotelName] = true;
            }

            $dimension = trim((string) ($row['dimension'] ?? ''));
            $dimensionKey = $dimension !== '' ? $dimension : '未标注维度';
            if (!isset($summary['dimensions'][$dimensionKey])) {
                $summary['dimensions'][$dimensionKey] = ['record_count' => 0, 'data_value' => null];
            }

            if (!in_array($dataType, ['advertising', 'quality', 'review'], true)) {
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'amount', $amount);
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'quantity', $quantity);
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'book_order_num', $bookOrderNum);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'amount', $amount);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'quantity', $quantity);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'book_order_num', $bookOrderNum);
            }
            $this->addNullableOtaDiagnosisMetric($summary['totals'], 'data_value', $dataValue);
            $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'data_value', $dataValue);
            $summary['dimensions'][$dimensionKey]['record_count']++;
            $this->addNullableOtaDiagnosisMetric($summary['dimensions'][$dimensionKey], 'data_value', $dataValue);

            if ($dataType === 'advertising') {
                $advertising = $this->extractOtaAdvertisingMetrics($row, $raw);
                foreach ($advertising as $key => $value) {
                    $this->addNullableOtaDiagnosisMetric($summary['totals'], $key, $value);
                    $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], $key, $value);
                }
                $summary['totals']['advertising_rows']++;
                $summary['daily'][$date]['advertising_rows']++;
            }

            if ($dataType === 'quality') {
                $quality = $this->extractOtaQualityMetrics($row, $raw);
                $summary['totals']['service_quality_rows']++;
                $summary['daily'][$date]['service_quality_rows']++;
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'hotel_collect', $quality['hotel_collect'] ?? null);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'hotel_collect', $quality['hotel_collect'] ?? null);
                if ($quality['avg_psi_score'] !== null) {
                    $psiScores[] = (float)$quality['avg_psi_score'];
                }
                if ($quality['avg_service_score'] !== null) {
                    $serviceScores[] = (float)$quality['avg_service_score'];
                }
                if ($quality['avg_im_score'] !== null) {
                    $imScores[] = (float)$quality['avg_im_score'];
                }
                if ($quality['avg_reply_rate'] !== null) {
                    $replyRates[] = (float)$quality['avg_reply_rate'];
                }
            }

            $traffic = in_array($dataType, ['traffic', 'business'], true)
                ? $this->extractOtaTrafficMetrics($row, $raw)
                : [
                    'list_exposure' => null,
                    'detail_visitors' => null,
                    'flow_rate' => null,
                    'order_visitors' => null,
                    'submit_users' => null,
                ];
            foreach ($traffic as $key => $value) {
                if ($key === 'flow_rate' && $value !== null) {
                    $summary['totals'][$key] = $value;
                    $summary['daily'][$date][$key] = $value;
                    continue;
                }
                $this->addNullableOtaDiagnosisMetric($summary['totals'], $key, $value);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], $key, $value);
            }

            $coreValueState = $this->otaDiagnosisCoreValueState($dataType, [$amount, $quantity, $bookOrderNum], array_values($traffic));
            if ($coreValueState === 'missing') {
                $missingCoreValueCount++;
            } elseif ($coreValueState === 'zero') {
                $zeroValueCount++;
            }
        }

        $summary['date_count'] = count($summary['daily']);
        $summary['hotel_names'] = array_values(array_keys($summary['hotel_names']));
        $summary['daily'] = array_values($summary['daily']);
        $summary['dimensions'] = $this->topDimensionStats($summary['dimensions']);
        $summary['averages']['adr'] = $this->nullableSafeAverage($summary['totals']['amount'], $summary['totals']['quantity']);
        $summary['averages']['avg_psi_score'] = $this->nullableAverage($psiScores);
        $summary['averages']['avg_service_score'] = $this->nullableAverage($serviceScores);
        $summary['averages']['avg_im_score'] = $this->nullableAverage($imScores);
        $summary['averages']['avg_reply_rate'] = $this->nullableAverage($replyRates);
        $summary['averages']['advertising_roas'] = $this->nullableSafeAverage($summary['totals']['advertising_order_amount'], $summary['totals']['advertising_spend']);
        $summary['derived_rates'] = [
            'detail_rate' => $this->nullablePercentRate($summary['totals']['detail_visitors'], $summary['totals']['list_exposure']),
            'order_rate' => $this->nullablePercentRate($summary['totals']['order_visitors'], $summary['totals']['detail_visitors']),
            'submit_rate' => $this->nullablePercentRate($summary['totals']['submit_users'], $summary['totals']['order_visitors']),
        ];
        $summary['data_gaps'] = array_values(array_map(
            static fn(string $field): string => 'metric_missing:' . $field,
            array_keys(array_filter(
                $summary['totals'],
                static fn(mixed $value, string $field): bool => !in_array($field, ['advertising_rows', 'service_quality_rows'], true) && $value === null,
                ARRAY_FILTER_USE_BOTH
            ))
        ));

        $missingDates = $this->missingDates($startDate, $endDate, array_column($summary['daily'], 'date'));
        if (!empty($missingDates)) {
            $summary['data_anomalies'][] = '日期缺失: ' . implode(',', $missingDates);
        }
        if ($invalidRawCount > 0) {
            $summary['data_anomalies'][] = '原始 JSON 解析失败记录数: ' . $invalidRawCount;
        }
        if ($zeroValueCount > 0) {
            $summary['data_anomalies'][] = '全指标为 0 的记录数: ' . $zeroValueCount;
        }
        if ($missingCoreValueCount > 0) {
            $summary['data_anomalies'][] = '核心指标未返回的记录数: ' . $missingCoreValueCount;
        }

        return $summary;
    }

    /**
     * Resolve only the OTA identifiers carried by the exact persisted data
     * sources represented in the diagnosis rows. This keeps name drift from
     * excluding the hotel's own facts without treating every hotel-bound row
     * as self evidence.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function otaDiagnosisOwnPlatformHotelIds(array $rows, int $hotelId, string $platform): array
    {
        $platform = strtolower(trim($platform));
        if ($hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
            return [];
        }

        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int)($row['data_source_id'] ?? 0),
            array_values(array_filter($rows, 'is_array'))
        ), static fn (int $id): bool => $id > 0)));
        if ($sourceIds === []) {
            return [];
        }
        $tenantId = $this->authoritativeTenantIdForHotel($hotelId);
        if ($tenantId <= 0) {
            return [];
        }

        try {
            $sources = Db::name('platform_data_sources')
                ->field('id,config_json')
                ->whereIn('id', $sourceIds)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'external_hotel_id'];
        $ids = [];
        foreach ($sources as $source) {
            $config = json_decode((string)($source['config_json'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = trim((string)($config[$key] ?? ''));
                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function extractOtaTrafficMetrics(array $row, array $raw): array
    {
        return [
            'list_exposure' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'list_exposure',
                ['mt_exposure', 'listExposure', 'list_exposure', 'exposure', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv'],
                ['list_exposure', 'mt_exposure']
            ),
            'detail_visitors' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'detail_exposure',
                ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors'],
                ['detail_exposure', 'mt_intention_uv']
            ),
            'flow_rate' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'flow_rate',
                ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'cvr'],
                ['flow_rate']
            ),
            'order_visitors' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'order_filling_num',
                ['orderFillingNum', 'order_filling_num', 'orderVisitors'],
                ['order_filling_num']
            ),
            'submit_users' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'order_submit_num',
                ['mt_pay_orders', 'pay_orders', 'payOrders', 'orderSubmitNum', 'order_submit_num', 'submitUsers', 'orderCount', 'order_count'],
                ['order_submit_num', 'mt_pay_orders']
            ),
        ];
    }

    private function normalizeOtaDiagnosisDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['ads', 'ad', 'advertising', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        return $value;
    }

    private function extractOtaAdvertisingMetrics(array $row, array $raw): array
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $spend = $this->readRowNumberFromKeys($row, ['amount', 'spend', 'cost', 'today_cost'])
            ?? $this->readSummaryNumber($detail, ['spend', 'cost', 'todayCost', 'today_cost'], null);
        $orderAmount = $this->readSummaryNumber($detail, ['orderAmount', 'order_amount', 'bookAmount', 'saleAmount', 'revenue'], null);
        if ($orderAmount === null) {
            $roas = $this->readRowNumberFromKeys($row, ['data_value', 'roas'])
                ?? $this->readSummaryNumber($detail, ['roas', 'roi'], null);
            $orderAmount = $spend !== null && $roas !== null ? (float)$spend * (float)$roas : null;
        }

        return [
            'advertising_spend' => $spend,
            'advertising_order_amount' => $orderAmount,
            'advertising_bookings' => $this->readRowNumberFromKeys($row, ['book_order_num', 'bookings', 'order_count'])
                ?? $this->readSummaryNumber($detail, ['bookings', 'bookingCount', 'orderCount', 'orderQuantity'], null),
            'advertising_room_nights' => $this->readRowNumberFromKeys($row, ['quantity', 'room_nights'])
                ?? $this->readSummaryNumber($detail, ['roomNights', 'room_nights', 'nights'], null),
            'advertising_impressions' => $this->readRowNumberFromKeys($row, ['list_exposure', 'impressions'])
                ?? $this->readSummaryNumber($detail, ['impressions', 'exposure', 'listExposure'], null),
            'advertising_clicks' => $this->readRowNumberFromKeys($row, ['detail_exposure', 'clicks'])
                ?? $this->readSummaryNumber($detail, ['clicks', 'clickCount', 'detailExposure'], null),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private function extractOtaQualityMetrics(array $row, array $raw): array
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $psiScore = $this->readSummaryNumber($detail, ['psiScore', 'psi_score'], null)
            ?? $this->readRowNumberFromKeys($row, ['psi_score', 'data_value']);

        return [
            'avg_psi_score' => $psiScore,
            'avg_service_score' => $this->readSummaryNumber($detail, ['serviceScore', 'service_score'], null)
                ?? $this->readRowNumberFromKeys($row, ['service_score']),
            'avg_im_score' => $this->readSummaryNumber($detail, ['imScore', 'im_score'], null)
                ?? $this->readRowNumberFromKeys($row, ['im_score']),
            'avg_reply_rate' => $this->readSummaryNumber($detail, ['replyRate', 'reply_rate'], null)
                ?? $this->readRowNumberFromKeys($row, ['reply_rate']),
            'hotel_collect' => $this->readSummaryNumber($detail, ['hotelCollect', 'hotel_collect'], null)
                ?? $this->readRowNumberFromKeys($row, ['hotel_collect']),
        ];
    }

    private function otaDiagnosisRawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
    }

    private function readRowNumberFromKeys(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->readRowNumber($row, $key);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function readRowNumber(array $row, string $key): ?float
    {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (float) $row[$key];
        }
        return null;
    }

    private function readOtaDiagnosisEvidenceNumber(array $row, array $raw, string $rowKey, array $rawKeys, array $metricKeys): ?float
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $rawValue = $this->readSummaryNumber($detail, $rawKeys, null);
        if ($rawValue !== null) {
            return $rawValue;
        }

        $value = $this->readRowNumber($row, $rowKey);
        if ($value === null) {
            return null;
        }
        if ((float)$value !== 0.0) {
            return $value;
        }

        return $this->otaDiagnosisMetricFactCaptured($raw, $rowKey, $metricKeys) ? 0.0 : null;
    }

    private function otaDiagnosisMetricFactCaptured(array $raw, string $normalizedField, array $metricKeys): bool
    {
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact) || ($fact['status'] ?? '') !== 'captured' || ($fact['stored_value_present'] ?? false) !== true) {
                continue;
            }
            $factMetric = trim((string)($fact['metric_key'] ?? ''));
            $factField = trim((string)($fact['normalized_field'] ?? ''));
            if ($factField === $normalizedField || in_array($factMetric, $metricKeys, true)) {
                return true;
            }
        }
        return false;
    }

    private function readSummaryNumber(array $data, array $keys, ?float $default): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return $default;
    }

    private function addNullableOtaDiagnosisMetric(array &$bucket, string $field, mixed $value): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }
        $bucket[$field] = ($bucket[$field] ?? 0) + (float)$value;
    }

    private function hasKnownOtaDiagnosisMetric(array $metrics, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $metrics) && $metrics[$field] !== null && $metrics[$field] !== '') {
                return true;
            }
        }
        return false;
    }

    private function nullablePercentRate(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator * 100, 2);
    }

    private function nullableSafeAverage(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator, 2);
    }

    private function formatOtaDiagnosisMetric(mixed $value, string $suffix = ''): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '未返回';
        }
        return (string)$value . $suffix;
    }

    private function topDimensionStats(array $dimensions): array
    {
        uasort($dimensions, function (array $a, array $b): int {
            $left = $a['data_value'] ?? null;
            $right = $b['data_value'] ?? null;
            if ($left === null) {
                return $right === null ? 0 : 1;
            }
            if ($right === null) {
                return -1;
            }
            return (float)$right <=> (float)$left;
        });
        return array_slice($dimensions, 0, 10, true);
    }

    private function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function nullableAverage(array $values): ?float
    {
        return $values === [] ? null : $this->average($values);
    }

    /**
     * Return prices from the latest single, fully comparable public-rate key.
     * Legacy rows intentionally fail this gate instead of being coerced to 0.
     *
     * @param array<int,array<string,mixed>> $priceRows
     * @param array<int,array<string,mixed>> $analysisRows
     * @return array<int,float>
     */
    private function otaDiagnosisComparableCompetitorPrices(array $priceRows, array $analysisRows): array
    {
        $groups = [];
        foreach (array_merge($priceRows, $analysisRows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->otaDiagnosisCompetitorComparisonKey($row);
            if ($key === '') {
                continue;
            }
            $price = $row['price'] ?? $row['competitor_price'] ?? null;
            $capturedAt = trim((string)($row['collected_at'] ?? $row['fetch_time'] ?? $row['create_time'] ?? ''));
            $groups[$key]['prices'][] = (float)$price;
            if ($capturedAt !== '' && strcmp($capturedAt, (string)($groups[$key]['latest'] ?? '')) > 0) {
                $groups[$key]['latest'] = $capturedAt;
            }
        }

        if ($groups === []) {
            return [];
        }
        uasort($groups, static fn(array $left, array $right): int => strcmp((string)($right['latest'] ?? ''), (string)($left['latest'] ?? '')));
        $latestGroup = reset($groups);
        return is_array($latestGroup['prices'] ?? null) ? array_values($latestGroup['prices']) : [];
    }

    private function otaDiagnosisCompetitorComparisonKey(array $row): string
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1
            || !in_array(strtolower(trim((string)($row['validation_status'] ?? ''))), ['normal', 'available', 'ok', 'valid', 'verified'], true)
            || !in_array(strtolower(trim((string)($row['availability'] ?? ''))), ['available', 'bookable'], true)
        ) {
            return '';
        }

        $price = $row['price'] ?? $row['competitor_price'] ?? null;
        if (!is_numeric($price) || (float)$price <= 0) {
            return '';
        }

        $requiredStrings = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis', 'currency',
            'source_method', 'source_ref',
        ];
        foreach ($requiredStrings as $field) {
            $value = $field === 'platform'
                ? ($row['platform'] ?? $row['ota_platform'] ?? null)
                : ($row[$field] ?? null);
            if (trim((string)$value) === '') {
                return '';
            }
        }

        $capturedAt = trim((string)($row['collected_at'] ?? $row['fetch_time'] ?? $row['create_time'] ?? ''));
        if ($capturedAt === '' || strtotime($capturedAt) === false) {
            return '';
        }
        $checkIn = trim((string)($row['check_in_date'] ?? ''));
        $checkOut = trim((string)($row['check_out_date'] ?? ''));
        if (strtotime($checkIn) === false || strtotime($checkOut) === false || strtotime($checkOut) <= strtotime($checkIn)) {
            return '';
        }
        if (!array_key_exists('tax_fee_included', $row)
            || !is_numeric($row['adults'] ?? null)
            || (int)$row['adults'] <= 0
            || !is_numeric($row['children'] ?? null)
            || (int)$row['children'] < 0
        ) {
            return '';
        }

        $keyFields = [
            'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key', 'breakfast',
            'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis', 'currency',
            'adults', 'children',
        ];
        $keyParts = [strtolower(trim((string)($row['platform'] ?? $row['ota_platform'] ?? '')))];
        foreach ($keyFields as $field) {
            $keyParts[] = strtolower(trim((string)$row[$field]));
        }

        return hash('sha256', implode('|', $keyParts));
    }

    private function percentRate(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator * 100, 2);
    }

    private function percentSafeAverage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator, 2);
    }

    private function missingDates(string $startDate, string $endDate, array $existingDates): array
    {
        $existing = array_flip($existingDates);
        $missing = [];
        for ($time = strtotime($startDate); $time <= strtotime($endDate); $time += 86400) {
            $date = date('Y-m-d', $time);
            if (!isset($existing[$date])) {
                $missing[] = $date;
            }
        }
        return $missing;
    }

    private function buildOtaDiagnosisPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是宿析OS酒店OTA经营分析顾问。只基于以下系统已入库数据摘要输出诊断，不要实时抓取OTA后台，不要把Cookie状态作为历史诊断失败原因，不要编造未提供的数据。\n"
            . "可使用知识库参考解释指标口径、诊断模板和行动拆解，但经营结论必须来自本次结构化摘要。\n"
            . "必须返回 JSON，字段为 summary、data_overview、abnormal_metrics、traffic_analysis、exposure_analysis、visit_conversion_analysis、order_conversion_analysis、price_analysis、competitor_analysis、advertising_analysis、service_quality_analysis、actions、priority。\n"
            . "data_overview、abnormal_metrics、actions 必须是数组；priority 只能是 high、medium、low。\n"
            . "异常描述必须优先写成数据口径提示或需复核提示；除非历史日期多次同步仍异常，不输出严重异常、严重采集异常或违反基本漏斗逻辑。\n"
            . "建议动作必须受证据约束：证据不足时只输出补数据、复核或blocked类动作，不输出调价、投放、运营执行等可执行建议。\n"
            . "actions 允许为空数组；当结构化摘要中的规则动作和异常指标均为空时，不得自行新增问题或行动，明确说明本次无需新增行动。\n"
            . "派生指标只能按摘要给出的公式和真实字段解释；未知字段、未知金额或未量化目标必须保持未知，不能补0或猜测。\n"
            . $knowledgeContext
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是宿析OS酒店OTA经营分析顾问。经营结论只基于以下前端当前抓取的携程ebooking结构化摘要；知识库只用于解释指标口径、诊断模板和行动拆解，不要查询或假设其他经营数据。\n"
            . "必须返回 JSON，字段为 overall_conclusion、key_findings、competitor_insights、problem_hotels、recommended_actions、priority、data_anomalies。\n"
            . "key_findings、competitor_insights、recommended_actions、data_anomalies 必须是字符串数组；priority 只能是 high、medium、low。\n"
            . "problem_hotels 必须是对象数组，固定格式为 {\"hotel_name\":\"酒店名\",\"problem\":\"问题\",\"key_metrics\":[\"订单127\",\"间夜104\",\"ADR 387.60\",\"评分4.6\"],\"suggestion\":\"建议\"}，不允许返回字符串数组。\n"
            . "曝光、访客、浏览率、订单率、转化率为0时，必须先看 data_quality.is_cross_day_window；若处于OTA跨日统计窗口，不要判断为经营异常，统一表述为“流量类指标可能尚未完成统计”。\n"
            . "当天或刚过12点的数据，订单、间夜、收入、ADR、评分作为主要分析依据；流量漏斗类指标只作为数据完整性提示，不作为核心经营判断。\n"
            . "若 data_quality.warning 非空，必须把它归类为“数据口径提示”或“数据未完全更新”，不能写成“严重采集异常”或核心经营结论。\n"
            . "字段名 data_anomalies 是兼容字段；当 data_quality.warning 非空或处于跨日统计窗口时，内容写数据口径提示、数据未完全更新或需复核提示，不写异常定性。\n"
            . "不要输出“违反基本漏斗逻辑”“严重异常”“严重采集异常”等绝对结论，除非是历史日期且确认多次采集仍异常。\n"
            . "建议动作优先为：等待平台数据更新后重新同步；先看订单、间夜、收入、ADR、评分；次日上午复查曝光、访客、转化率；历史日期长期为0再检查接口、字段映射或Cookie权限。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。不要输出 API Key、Cookie 或认证信息。\n"
            . $knowledgeContext
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaFinalPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是酒店OTA渠道分析顾问。请基于多个分组分析结果，输出一份面向酒店经营者的携程OTA渠道样本诊断报告。\n"
            . "不要逐组复述，要综合归纳。只基于分组报告摘要，不要使用完整原始抓取数据或假设数据；知识库只用于解释指标口径、诊断模板和行动拆解。所有结论必须限定在已抓取的携程OTA渠道、覆盖酒店和已返回字段内，不得外推全酒店营收、全渠道需求或整体经营状况。\n"
            . "重点回答：1. 携程OTA渠道样本现状；2. 渠道内最需关注的问题或尚不能判断的缺口；3. 最值得关注的酒店；4. 已有竞对样本体现的机会或证据不足；5. 价格与订单表现、流量数据口径提示；6. 下一步建议优先复核的运营动作。建议不等于已执行。\n"
            . "返回 JSON：{\"overall_conclusion\":\"总体结论\",\"key_findings\":[],\"competitor_insights\":[],\"problem_hotels\":[{\"hotel_name\":\"酒店名\",\"problem\":\"问题\",\"key_metrics\":[],\"suggestion\":\"建议\"}],\"recommended_actions\":[],\"priority\":\"high/medium/low\",\"data_anomalies\":[]}\n"
            . "key_findings、competitor_insights、recommended_actions、data_anomalies 必须是字符串数组；problem_hotels 必须是对象数组，不允许返回字符串数组；priority 只能是 high、medium、low。\n"
            . "若 data_quality.is_cross_day_window 为 true，曝光、访客、浏览率、订单率、转化率为0只作为数据口径提示，不能作为核心经营异常或严重结论。\n"
            . "综合结论主要基于订单、间夜、OTA渠道收入、OTA渠道ADR、评分等已返回指标；流量漏斗类指标建议待平台更新后复查。这些指标不得表述为全酒店经营指标。\n"
            . "若 data_quality.warning 非空，必须把它归类为“数据口径提示”或“数据未完全更新”，不能写成“严重采集异常”或核心经营结论。\n"
            . "字段名 data_anomalies 是兼容字段；当 data_quality.warning 非空或处于跨日统计窗口时，内容写数据口径提示、数据未完全更新或需复核提示，不写异常定性。\n"
            . "不要输出“违反基本漏斗逻辑”“严重异常”“严重采集异常”等绝对结论，除非是历史日期且确认多次采集仍异常。\n"
            . "建议动作优先为等待平台更新后重新同步、先看订单/间夜/收入/ADR/评分、次日上午复查流量指标，历史日期长期为0再检查接口、字段映射或Cookie权限。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。若存在失败组，请在 data_anomalies 中提示分析覆盖不足。不要输出 API Key、Cookie 或认证信息。\n"
            . $knowledgeContext
            . "分组报告摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function parseOtaDiagnosisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'core_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'main_problems' => [],
                'possible_reasons' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies_needing_confirmation' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'summary' => (string) ($data['summary'] ?? $data['core_conclusion'] ?? ''),
            'data_overview' => array_values((array) ($data['data_overview'] ?? [])),
            'abnormal_metrics' => array_values((array) ($data['abnormal_metrics'] ?? $data['main_problems'] ?? [])),
            'traffic_analysis' => (string) ($data['traffic_analysis'] ?? ''),
            'exposure_analysis' => (string) ($data['exposure_analysis'] ?? ''),
            'visit_conversion_analysis' => (string) ($data['visit_conversion_analysis'] ?? ''),
            'order_conversion_analysis' => (string) ($data['order_conversion_analysis'] ?? ''),
            'price_analysis' => (string) ($data['price_analysis'] ?? ''),
            'competitor_analysis' => (string) ($data['competitor_analysis'] ?? ''),
            'advertising_analysis' => (string) ($data['advertising_analysis'] ?? ''),
            'service_quality_analysis' => (string) ($data['service_quality_analysis'] ?? ''),
            'comment_analysis' => '',
            'actions' => array_values((array) ($data['actions'] ?? $data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
        ];
    }

    private function parseCapturedOtaAnalysisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'overall_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'key_findings' => [],
                'competitor_insights' => [],
                'problem_hotels' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'overall_conclusion' => (string) ($data['overall_conclusion'] ?? ''),
            'key_findings' => array_values((array) ($data['key_findings'] ?? [])),
            'competitor_insights' => array_values((array) ($data['competitor_insights'] ?? [])),
            'problem_hotels' => $this->sanitizeProblemHotels($data['problem_hotels'] ?? [], 10),
            'recommended_actions' => array_values((array) ($data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
            'data_anomalies' => array_values((array) ($data['data_anomalies'] ?? [])),
            'data_quality' => is_array($data['data_quality'] ?? null) ? $data['data_quality'] : [],
        ];
    }

    private function extractJsonObjectFromText(string $content): string
    {
        $json = trim($content);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim($matches[1]);
        }
        if (json_decode($json, true) !== null) {
            return $json;
        }
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($json, $start, $end - $start + 1);
        }
        return $json;
    }

}
