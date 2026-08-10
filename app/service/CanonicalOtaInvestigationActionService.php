<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\ExecutionOutcomeService;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Completes four deterministic, analysis-only OTA operational checks.
 *
 * The service never calls a collector or an OTA write endpoint. It promotes an
 * already-saved canonical investigation draft into four idempotent operation
 * records whose only execution is local arithmetic and exact-scope validation.
 */
final class CanonicalOtaInvestigationActionService
{
    public const SOURCE_MODULE = 'canonical_ota_investigation';
    public const INTENT_STATUS = 'system_authorized_analysis';
    public const EVIDENCE_TYPE = 'canonical_analysis_completion';
    public const ACTION_SET_VERSION = 'canonical_ota_investigation_actions.v1';
    public const EVIDENCE_VERSION = 'canonical_ota_investigation_evidence.v1';
    public const SCHEDULED_AUTHORIZATION_VERSION = 'canonical_ota_scheduled_analysis_authorization.v1';

    /** @var array<string,string> */
    private const ACTION_TYPES = [
        'check_list_to_detail_mathematical_consistency' => 'list_detail_math_check',
        'investigate_detail_to_order_fill_breakpoint' => 'detail_fill_breakpoint_check',
        'investigate_fill_to_submit_chain' => 'fill_submit_chain_check',
        'prepare_same_scope_recollection_and_entry_eligibility_check' => 'same_scope_recollection_eligibility_check',
    ];

    /** @var array<string,string> */
    private const MEITUAN_ACTION_TYPES = [
        'check_meituan_list_detail_count_order' => 'meituan_list_detail_count_order_check',
        'calculate_meituan_list_to_detail_rate' => 'meituan_list_detail_rate_check',
        'check_meituan_observed_flow_rate_alignment' => 'meituan_observed_flow_rate_alignment_check',
        'prepare_same_scope_recollection_and_entry_eligibility_check' => 'same_scope_recollection_eligibility_check',
    ];

    /** @return array<int,string> */
    public static function actionTypesForPlatform(string $platform): array
    {
        return array_values(self::actionTypeMapForPlatform($platform));
    }

    /** @var Closure(array<string,mixed>):array<string,mixed> */
    private Closure $draftRunner;

    /** @var Closure(int,array<string,mixed>):void */
    private Closure $beforePersistAction;

    /** @var Closure():string */
    private Closure $clock;

    /** @var Closure(array<int,int>,int,array<string,mixed>):array<string,mixed> */
    private Closure $flowReader;

    /** @var Closure(array<string,mixed>,int,int,string):array<string,mixed> */
    private Closure $scheduledAuthorizationResolver;

    /** @var Closure(array<string,mixed>):array<string,mixed> */
    private Closure $platformSelectionGate;

    /** @var Closure(int,int,string,string):array<string,mixed> */
    private Closure $platformSelectionResolver;

    private bool $usesDefaultDraftRunner;

    public function __construct(
        ?callable $draftRunner = null,
        ?callable $beforePersistAction = null,
        ?callable $clock = null,
        ?callable $flowReader = null,
        ?callable $scheduledAuthorizationResolver = null,
        ?callable $platformSelectionGate = null,
        ?callable $platformSelectionResolver = null
    ) {
        $this->usesDefaultDraftRunner = $draftRunner === null;
        $this->draftRunner = $draftRunner !== null
            ? Closure::fromCallable($draftRunner)
            : static fn(array $scope): array => (new CanonicalOtaInvestigationDraftService())->preflight($scope);
        $this->beforePersistAction = $beforePersistAction !== null
            ? Closure::fromCallable($beforePersistAction)
            : static function (int $index, array $action): void {
            };
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): string => date('Y-m-d H:i:s');
        $this->flowReader = $flowReader !== null
            ? Closure::fromCallable($flowReader)
            : static fn(array $hotelIds, int $hotelId, array $filters): array =>
                (new OperationManagementService())->executionFlow($hotelIds, $hotelId, $filters);
        $this->scheduledAuthorizationResolver = $scheduledAuthorizationResolver !== null
            ? Closure::fromCallable($scheduledAuthorizationResolver)
            : static fn(array $authorization, int $tenantId, int $hotelId, string $platform): array =>
                (new CanonicalOtaScheduledAnalysisAuthorizationService())->assertMatches(
                    $authorization,
                    $tenantId,
                    $hotelId,
                    $platform
                );
        $this->platformSelectionGate = $platformSelectionGate !== null
            ? Closure::fromCallable($platformSelectionGate)
            : static fn(array $scope): array =>
                (new CanonicalOtaDailyPlatformSelectionService())->assertScopeMayPersist($scope);
        $this->platformSelectionResolver = $platformSelectionResolver !== null
            ? Closure::fromCallable($platformSelectionResolver)
            : static fn(int $tenantId, int $hotelId, string $targetDate, string $period): array =>
                (new CanonicalOtaDailyPlatformSelectionService())->resolve(
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $period
                );
    }

    /** @param array<string,mixed> $scope */
    public function preflight(array $scope): array
    {
        return $this->run($scope, false);
    }

    /** @param array<string,mixed> $scope */
    public function execute(array $scope): array
    {
        return $this->run($scope, true);
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $authorization */
    public function executeScheduled(array $scope, array $authorization): array
    {
        return $this->run($scope, true, $authorization);
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    public function run(array $scope, bool $execute = false, array $scheduledAuthorization = []): array
    {
        $scope = $this->normalizeScope($scope);
        $scheduledAuthorization = $scheduledAuthorization === []
            ? []
            : $this->normalizeScheduledAuthorization($scheduledAuthorization, $scope);
        if ($scheduledAuthorization !== []) {
            try {
                $resolvedAuthorization = ($this->scheduledAuthorizationResolver)(
                    $scheduledAuthorization,
                    $scope['tenant_id'],
                    $scope['hotel_id'],
                    $scope['platform']
                );
            } catch (\Throwable) {
                throw new InvalidArgumentException('canonical_action_scheduled_authorization_not_granted');
            }
            if ($resolvedAuthorization !== $scheduledAuthorization) {
                throw new InvalidArgumentException('canonical_action_scheduled_authorization_not_granted');
            }
        }
        $draftResult = ($this->draftRunner)($scope);
        $draftSet = $this->assertSavedDraftResult($draftResult, $scope);
        $actionSet = $this->buildActionSet($draftSet, $scope, $scheduledAuthorization);

        if (!$execute) {
            return [
                'status' => 'ready',
                'execute' => false,
                'scope' => $scope,
                'action_set_digest' => $actionSet['content_digest'],
                'planned_operational_check_count' => 4,
                'trusted_operational_check_count' => 0,
                'trusted_external_operation_count' => 0,
                'db_readback_verified' => false,
                'external_action_triggered' => false,
                'business_outcome_claimed' => false,
                'action_set' => $actionSet,
            ];
        }

        $persisted = Db::transaction(function () use ($actionSet, $scope, $scheduledAuthorization): array {
            $selectionGate = ($this->platformSelectionGate)($scope);
            $ownerMetadata = $this->assertPlatformSelectionGate($selectionGate, $scope);
            if ($this->usesDefaultDraftRunner) {
                $this->lockExactCanonicalSource($scope);
            }
            $lockedDraftSet = $this->assertSavedDraftResult(($this->draftRunner)($scope), $scope);
            $lockedActionSet = $this->buildActionSet($lockedDraftSet, $scope, $scheduledAuthorization);
            if (!hash_equals((string)$actionSet['content_digest'], (string)$lockedActionSet['content_digest'])) {
                throw new RuntimeException('canonical_action_source_drift_detected');
            }
            $rows = [];
            $allIdempotent = true;
            foreach ($actionSet['actions'] as $index => $action) {
                ($this->beforePersistAction)($index, $action);
                $saved = $this->persistOneAction($scope, $actionSet, $action, $ownerMetadata);
                $rows[] = $saved;
                $allIdempotent = $allIdempotent && $saved['idempotent'];
            }
            $readback = $this->exactReadback($scope, $actionSet, $ownerMetadata);
            $selection = ($this->platformSelectionResolver)(
                $scope['tenant_id'],
                $scope['hotel_id'],
                $scope['target_date'],
                $scope['data_period']
            );
            return [
                'rows' => $rows,
                'idempotent' => $allIdempotent,
                'readback' => $readback,
                'daily_platform_selection' => $this->assertPlatformSelectionResolved(
                    $selection,
                    $scope,
                    $rows,
                    (string)$actionSet['content_digest']
                ),
            ];
        });
        $readback = $persisted['readback'];

        return [
            'status' => 'completed',
            'execute' => true,
            'scope' => $scope,
            'idempotent' => $persisted['idempotent'],
            'action_set_digest' => $actionSet['content_digest'],
            'trusted_operational_check_count' => 4,
            'trusted_external_operation_count' => 0,
            'db_readback_verified' => true,
            'operation_flow_readback_verified' => true,
            'effect_review_written' => false,
            'action_track_written' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'records' => $readback['records'],
            'flow_summary' => $readback['flow_summary'],
            'daily_platform_selection' => $persisted['daily_platform_selection'],
            'action_set' => $actionSet,
        ];
    }

    /** @param array<string,mixed> $draftResult @param array<string,mixed> $scope @return array<string,mixed> */
    private function assertSavedDraftResult(array $draftResult, array $scope): array
    {
        $draftSet = is_array($draftResult['draft_set'] ?? null) ? $draftResult['draft_set'] : [];
        if (($draftResult['status'] ?? '') !== 'ready'
            || ($draftResult['readback_verified'] ?? false) !== true
            || ($draftResult['idempotent'] ?? false) !== true
            || (int)($draftResult['draft_count'] ?? 0) !== 4
            || !is_array($draftResult['scope'] ?? null)
            || $draftResult['scope'] !== $scope
            || $draftSet === []
        ) {
            throw new RuntimeException('canonical_action_saved_draft_readback_required');
        }
        if (($draftSet['schema_version'] ?? '') !== CanonicalOtaInvestigationDraftService::SCHEMA_VERSION
            || (int)($draftSet['draft_count'] ?? 0) !== 4
            || count((array)($draftSet['drafts'] ?? [])) !== 4
            || ($draftSet['scope'] ?? null) !== $scope
            || ($draftSet['causality_claimed'] ?? true) !== false
            || ($draftSet['execution_status'] ?? '') !== 'not_authorized'
        ) {
            throw new RuntimeException('canonical_action_draft_contract_invalid');
        }
        $draftDigest = strtolower(trim((string)($draftSet['content_digest'] ?? '')));
        if (!$this->isDigest($draftDigest)
            || !hash_equals($draftDigest, $this->legacyDraftDigest($draftSet))
            || !hash_equals($draftDigest, strtolower(trim((string)($draftResult['content_digest'] ?? ''))))
        ) {
            throw new RuntimeException('canonical_action_draft_digest_invalid');
        }

        $sourceFact = is_array($draftSet['source_fact'] ?? null) ? $draftSet['source_fact'] : [];
        $sourceChecks = (int)($sourceFact['tenant_id'] ?? 0) === $scope['tenant_id']
            && (int)($sourceFact['hotel_id'] ?? 0) === $scope['hotel_id']
            && (int)($sourceFact['data_source_id'] ?? 0) === $scope['data_source_id']
            && (int)($sourceFact['sync_task_id'] ?? 0) === $scope['task_id']
            && (int)($sourceFact['row_id'] ?? 0) === $scope['row_id']
            && strtolower(trim((string)($sourceFact['platform'] ?? ''))) === $scope['platform']
            && (string)($sourceFact['target_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($sourceFact['data_period'] ?? ''))) === $scope['data_period']
            && (string)($sourceFact['validation_status'] ?? '') === 'verified'
            && (string)($sourceFact['history_status'] ?? '') === 'success'
            && ($sourceFact['readback_verified'] ?? false) === true
            && (string)($sourceFact['p0_status'] ?? '') === 'ready'
            && (string)($sourceFact['promotion_version'] ?? '') === 'ota_canonical_history_promotion.v3'
            && in_array($scope['row_id'], $this->positiveIds($sourceFact['run_readback_row_ids'] ?? []), true);
        foreach (['promotion_content_digest', 'authoritative_fact_digest', 'platform_hotel_identity_digest'] as $field) {
            $sourceChecks = $sourceChecks && $this->isDigest((string)($sourceFact[$field] ?? ''));
        }
        if (!$sourceChecks) {
            throw new RuntimeException('canonical_action_source_fact_scope_invalid');
        }

        $expectedCodes = array_keys(self::actionTypeMapForPlatform($scope['platform']));
        $actualCodes = [];
        foreach ((array)$draftSet['drafts'] as $draft) {
            if (!is_array($draft)
                || ($draft['action_kind'] ?? '') !== 'investigation_check'
                || ($draft['causality_claimed'] ?? true) !== false
                || ($draft['outcome_claimed'] ?? true) !== false
                || ($draft['execution_status'] ?? '') !== 'not_authorized'
            ) {
                throw new RuntimeException('canonical_action_draft_boundary_invalid');
            }
            $actualCodes[] = trim((string)($draft['action_code'] ?? ''));
        }
        if ($actualCodes !== $expectedCodes || count(array_unique($actualCodes)) !== 4) {
            throw new RuntimeException('canonical_action_draft_codes_invalid');
        }

        return $draftSet;
    }

    /** @param array<string,mixed> $draftSet @param array<string,mixed> $scope @return array<string,mixed> */
    private function buildActionSet(
        array $draftSet,
        array $scope,
        array $scheduledAuthorization = []
    ): array
    {
        $sourceFact = $draftSet['source_fact'];
        $metrics = is_array($sourceFact['traffic_metric_values'] ?? null)
            ? $sourceFact['traffic_metric_values']
            : [];
        $listExposure = $this->nonNegativeInteger($metrics['list_exposure'] ?? null, 'list_exposure');
        $detailExposure = $this->nonNegativeInteger($metrics['detail_exposure'] ?? null, 'detail_exposure');
        $observedFlowRate = $this->nonNegativeDecimal($metrics['flow_rate'] ?? null, 'flow_rate');

        $contract = $this->formulaContract($scope['platform']);
        $contractDigest = $this->digest($contract);
        $draftsByCode = [];
        foreach ($draftSet['drafts'] as $draft) {
            $draftsByCode[(string)$draft['action_code']] = $draft;
        }

        if ($scope['platform'] === 'meituan') {
            $results = [
                'check_meituan_list_detail_count_order' => $this->meituanListDetailCountOrderResult(
                    $listExposure,
                    $detailExposure
                ),
                'calculate_meituan_list_to_detail_rate' => $this->meituanListDetailRateResult(
                    $listExposure,
                    $detailExposure
                ),
                'check_meituan_observed_flow_rate_alignment' => $this->meituanObservedFlowRateAlignmentResult(
                    $listExposure,
                    $detailExposure,
                    $observedFlowRate
                ),
                'prepare_same_scope_recollection_and_entry_eligibility_check' => $this->recollectionEligibilityResult(
                    $scope,
                    $sourceFact
                ),
            ];
        } else {
            $orderFilling = $this->nonNegativeInteger(
                $metrics['order_filling_num'] ?? null,
                'order_filling_num'
            );
            $orderSubmit = $this->nonNegativeInteger(
                $metrics['order_submit_num'] ?? null,
                'order_submit_num'
            );
            $results = [
                'check_list_to_detail_mathematical_consistency' => $this->listDetailResult(
                    $listExposure,
                    $detailExposure,
                    $observedFlowRate
                ),
                'investigate_detail_to_order_fill_breakpoint' => $this->detailFillResult(
                    $detailExposure,
                    $orderFilling
                ),
                'investigate_fill_to_submit_chain' => $this->fillSubmitResult(
                    $orderFilling,
                    $orderSubmit
                ),
                'prepare_same_scope_recollection_and_entry_eligibility_check' => $this->recollectionEligibilityResult(
                    $scope,
                    $sourceFact
                ),
            ];
        }

        $actions = [];
        foreach (self::actionTypeMapForPlatform($scope['platform']) as $draftCode => $actionType) {
            $draft = $draftsByCode[$draftCode];
            $action = [
                'schema_version' => 'canonical_ota_investigation_action.v1',
                'action_id' => 'canonical_check_' . substr(hash('sha256', $draftSet['content_digest'] . '|' . $draftCode), 0, 24),
                'draft_id' => (string)$draft['draft_id'],
                'draft_action_code' => $draftCode,
                'action_type' => $actionType,
                'action_kind' => 'deterministic_operational_check',
                'title' => (string)$draft['title'],
                'action_text' => (string)$draft['action_text'],
                'acceptance_criteria' => array_values((array)$draft['acceptance_criteria']),
                'formula_contract' => array_replace(
                    $contract['common'],
                    $contract['actions'][$draftCode]
                ),
                'deterministic_result' => $results[$draftCode],
                'completion_status' => 'reviewed_completed',
                'deterministic_review' => [
                    'reviewer_contract_version' => 'canonical_ota_investigation_deterministic_review.v1',
                    'formula_result_match' => true,
                    'scope_match' => true,
                    'boundary_match' => true,
                    'verdict' => 'PASS',
                    'process_status' => 'READY',
                ],
                'causality_claimed' => false,
                'business_outcome_claimed' => false,
                'external_action_triggered' => false,
                'ota_mutation_performed' => false,
            ];
            $action['action_content_digest'] = $this->actionDigest($action);
            $actions[] = $action;
        }

        $set = [
            'schema_version' => self::ACTION_SET_VERSION,
            'scope' => $scope,
            'source_draft_set_id' => (string)$draftSet['draft_set_id'],
            'source_draft_set_digest' => (string)$draftSet['content_digest'],
            'promotion_content_digest' => (string)$sourceFact['promotion_content_digest'],
            'authoritative_fact_digest' => (string)$sourceFact['authoritative_fact_digest'],
            'platform_hotel_identity_digest' => (string)$sourceFact['platform_hotel_identity_digest'],
            'contract_digest' => $contractDigest,
            'action_count' => 4,
            'trusted_external_operation_count' => 0,
            'actions' => $actions,
            'causality_claimed' => false,
            'business_outcome_claimed' => false,
            'external_action_triggered' => false,
        ];
        if ($scheduledAuthorization !== []) {
            $set['scheduled_analysis_authorization'] = $scheduledAuthorization;
            $set['scheduled_analysis_authorization_digest'] =
                (string)$scheduledAuthorization['content_digest'];
        }
        $set['content_digest'] = $this->digest($set);
        $this->assertActionSet($set, $scope);
        return $set;
    }

    /** @return array<string,mixed> */
    private function formulaContract(string $platform): array
    {
        if ($platform === 'meituan') {
            return [
                'schema_version' => 'canonical_ota_investigation_formula_contract.v1',
                'common' => [
                    'rounding_mode' => 'half_up',
                    'output_scale' => 8,
                    'comparison_scale' => 2,
                    'zero_denominator_policy' => 'null_not_zero',
                    'metric_scope' => 'ota_channel',
                    'causality_claimed' => false,
                    'business_outcome_claimed' => false,
                ],
                'actions' => [
                    'check_meituan_list_detail_count_order' => [
                        'formula_id' => 'meituan_list_detail_count_order.v1',
                        'expression' => 'detail_exposure <= list_exposure',
                        'unit' => 'boolean',
                        'zero_denominator_policy' => 'not_applicable',
                    ],
                    'calculate_meituan_list_to_detail_rate' => [
                        'formula_id' => 'meituan_list_to_detail_rate.v1',
                        'expression' => 'detail_exposure / list_exposure * 100',
                        'unit' => 'percentage_point',
                        'zero_denominator_policy' => 'null_not_zero',
                    ],
                    'check_meituan_observed_flow_rate_alignment' => [
                        'formula_id' => 'meituan_observed_flow_rate_alignment.v1',
                        'expression' => 'round_half_up(detail_exposure / list_exposure * 100, 2) == round_half_up(flow_rate, 2)',
                        'unit' => 'boolean_or_null',
                        'zero_denominator_policy' => 'null_not_zero',
                    ],
                    'prepare_same_scope_recollection_and_entry_eligibility_check' => [
                        'formula_id' => 'meituan_same_scope_recollection_eligibility.v1',
                        'expression' => 'stable_scope_match && reference_authority_ready && fresh_profile_session_preflight',
                        'unit' => 'boolean_or_null',
                        'zero_denominator_policy' => 'not_applicable',
                    ],
                ],
            ];
        }

        return [
            'schema_version' => 'canonical_ota_investigation_formula_contract.v1',
            'common' => [
                'rounding_mode' => 'half_up',
                'output_scale' => 8,
                'comparison_scale' => 2,
                'zero_denominator_policy' => 'null_not_zero',
                'metric_scope' => 'ota_channel',
                'causality_claimed' => false,
                'business_outcome_claimed' => false,
            ],
            'actions' => [
                'check_list_to_detail_mathematical_consistency' => [
                    'formula_id' => 'ctrip_list_to_detail_rate.v1',
                    'expression' => 'detail_exposure / list_exposure * 100',
                    'unit' => 'percentage_point',
                    'zero_denominator_policy' => 'null_not_zero',
                ],
                'investigate_detail_to_order_fill_breakpoint' => [
                    'formula_id' => 'ctrip_detail_to_fill_rate.v1',
                    'expression' => 'order_filling_num / detail_exposure * 100',
                    'unit' => 'percentage_point',
                    'zero_denominator_policy' => 'null_not_zero',
                ],
                'investigate_fill_to_submit_chain' => [
                    'formula_id' => 'ctrip_fill_to_submit_rate.v1',
                    'expression' => 'order_submit_num / order_filling_num * 100',
                    'unit' => 'percentage_point',
                    'zero_denominator_policy' => 'null_not_zero',
                ],
                'prepare_same_scope_recollection_and_entry_eligibility_check' => [
                    'formula_id' => 'ctrip_same_scope_recollection_eligibility.v1',
                    'expression' => 'stable_scope_match && reference_authority_ready && fresh_profile_session_preflight',
                    'unit' => 'boolean_or_null',
                    'zero_denominator_policy' => 'not_applicable',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function listDetailResult(int $list, int $detail, float $observed): array
    {
        $ratio = $this->ratio($detail, $list);
        $computed2 = $ratio === null ? null : $ratio['rate_2dp'];
        $observed2 = $this->fixed($observed, 2);
        return [
            'inputs' => [
                'list_exposure' => $list,
                'detail_exposure' => $detail,
                'observed_flow_rate' => $this->fixed($observed, 2),
            ],
            'computed_rate_8dp' => $ratio['rate_8dp'] ?? null,
            'computed_rate_2dp' => $computed2,
            'observed_rate_2dp' => $observed2,
            'signed_delta_pp_8dp' => $ratio === null
                ? null
                : $this->fixed($observed - $ratio['raw'], 8),
            'count_order_status' => $detail <= $list ? 'consistent' : 'inconsistent',
            'calculation_status' => $ratio === null ? 'not_computable_zero_denominator' : 'completed',
            'result_status' => $ratio === null
                ? 'list_zero_detail_rate_unavailable'
                : ($computed2 === $observed2 ? 'consistent_at_2dp' : 'inconsistent_at_2dp'),
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanListDetailCountOrderResult(int $list, int $detail): array
    {
        $consistent = $detail <= $list;
        return [
            'inputs' => [
                'list_exposure' => $list,
                'detail_exposure' => $detail,
            ],
            'arithmetic_count_difference' => $list - $detail,
            'count_order_status' => $consistent ? 'consistent' : 'inconsistent',
            'calculation_status' => 'completed',
            'result_status' => $consistent
                ? 'detail_not_above_list_observed'
                : 'detail_above_list_definition_review_required',
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanListDetailRateResult(int $list, int $detail): array
    {
        $ratio = $this->ratio($detail, $list);
        return [
            'inputs' => [
                'list_exposure' => $list,
                'detail_exposure' => $detail,
            ],
            'computed_rate_8dp' => $ratio['rate_8dp'] ?? null,
            'computed_rate_2dp' => $ratio['rate_2dp'] ?? null,
            'calculation_status' => $ratio === null
                ? 'not_computable_zero_denominator'
                : 'completed',
            'result_status' => $ratio === null
                ? 'list_zero_detail_rate_unavailable'
                : 'descriptive_rate_computed',
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @return array<string,mixed> */
    private function meituanObservedFlowRateAlignmentResult(
        int $list,
        int $detail,
        float $observed
    ): array {
        $ratio = $this->ratio($detail, $list);
        $computed2 = $ratio['rate_2dp'] ?? null;
        $observed2 = $this->fixed($observed, 2);
        return [
            'inputs' => [
                'list_exposure' => $list,
                'detail_exposure' => $detail,
                'observed_flow_rate' => $observed2,
            ],
            'computed_rate_8dp' => $ratio['rate_8dp'] ?? null,
            'computed_rate_2dp' => $computed2,
            'observed_rate_2dp' => $observed2,
            'signed_delta_pp_8dp' => $ratio === null
                ? null
                : $this->fixed($observed - $ratio['raw'], 8),
            'alignment_status' => $ratio === null
                ? null
                : ($computed2 === $observed2 ? 'consistent' : 'inconsistent'),
            'calculation_status' => $ratio === null
                ? 'not_computable_zero_denominator'
                : 'completed',
            'result_status' => $ratio === null
                ? 'list_zero_flow_alignment_unavailable'
                : ($computed2 === $observed2 ? 'consistent_at_2dp' : 'inconsistent_at_2dp'),
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @return array<string,mixed> */
    private function detailFillResult(int $detail, int $fill): array
    {
        $ratio = $this->ratio($fill, $detail);
        return [
            'inputs' => ['detail_exposure' => $detail, 'order_filling_num' => $fill],
            'computed_rate_8dp' => $ratio['rate_8dp'] ?? null,
            'computed_rate_2dp' => $ratio['rate_2dp'] ?? null,
            'arithmetic_count_difference' => $detail - $fill,
            'count_order_status' => $fill <= $detail ? 'consistent' : 'inconsistent',
            'calculation_status' => $ratio === null ? 'not_computable_zero_denominator' : 'completed',
            'result_status' => $ratio === null
                ? 'detail_zero_fill_rate_unavailable'
                : ($fill === 0 ? 'positive_detail_zero_fill_observed' : 'descriptive_rate_computed'),
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @return array<string,mixed> */
    private function fillSubmitResult(int $fill, int $submit): array
    {
        $ratio = $this->ratio($submit, $fill);
        return [
            'inputs' => ['order_filling_num' => $fill, 'order_submit_num' => $submit],
            'computed_rate_8dp' => $ratio['rate_8dp'] ?? null,
            'computed_rate_2dp' => $ratio['rate_2dp'] ?? null,
            'arithmetic_count_difference' => $fill - $submit,
            'count_order_status' => $submit <= $fill ? 'consistent' : 'inconsistent',
            'calculation_status' => $ratio === null ? 'not_computable_zero_denominator' : 'completed',
            'result_status' => $ratio === null
                ? 'upstream_zero_submit_rate_unavailable'
                : 'descriptive_rate_computed',
            'cause_status' => 'unknown_requires_investigation',
        ];
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $sourceFact @return array<string,mixed> */
    private function recollectionEligibilityResult(array $scope, array $sourceFact): array
    {
        return [
            'stable_scope' => [
                'tenant_id' => $scope['tenant_id'],
                'hotel_id' => $scope['hotel_id'],
                'data_source_id' => $scope['data_source_id'],
                'platform' => $scope['platform'],
                'target_date' => $scope['target_date'],
                'data_period' => $scope['data_period'],
            ],
            'stable_scope_match' => true,
            'reference_authority_ready' => ($sourceFact['readback_verified'] ?? false) === true
                && ($sourceFact['p0_status'] ?? '') === 'ready'
                && ($sourceFact['promotion_version'] ?? '') === 'ota_canonical_history_promotion.v3',
            'fresh_profile_session_preflight' => 'not_checked',
            'runtime_collection_eligible' => null,
            'result_status' => 'scope_ready_runtime_preflight_required',
            'collection_triggered' => false,
            'external_execution_status' => 'not_authorized',
            'cause_status' => 'unknown_requires_runtime_preflight',
        ];
    }

    /** @return array{raw:float,rate_8dp:string,rate_2dp:string}|null */
    private function ratio(int $numerator, int $denominator): ?array
    {
        if ($denominator === 0) {
            return null;
        }
        $raw = ($numerator / $denominator) * 100;
        return [
            'raw' => $raw,
            'rate_8dp' => $this->fixed($raw, 8),
            'rate_2dp' => $this->fixed($raw, 2),
        ];
    }

    private function fixed(float $value, int $scale): string
    {
        return number_format(round($value, $scale, PHP_ROUND_HALF_UP), $scale, '.', '');
    }

    /** @param array<string,mixed> $set @param array<string,mixed> $scope */
    private function assertActionSet(array $set, array $scope): void
    {
        if (($set['schema_version'] ?? '') !== self::ACTION_SET_VERSION
            || ($set['scope'] ?? null) !== $scope
            || (int)($set['action_count'] ?? 0) !== 4
            || count((array)($set['actions'] ?? [])) !== 4
            || ($set['trusted_external_operation_count'] ?? -1) !== 0
            || ($set['causality_claimed'] ?? true) !== false
            || ($set['business_outcome_claimed'] ?? true) !== false
            || ($set['external_action_triggered'] ?? true) !== false
            || !$this->isDigest((string)($set['content_digest'] ?? ''))
            || !hash_equals((string)$set['content_digest'], $this->digest($set))
        ) {
            throw new RuntimeException('canonical_action_set_invalid');
        }
        $types = [];
        foreach ($set['actions'] as $action) {
            if (!is_array($action)
                || ($action['action_kind'] ?? '') !== 'deterministic_operational_check'
                || ($action['completion_status'] ?? '') !== 'reviewed_completed'
                || ($action['deterministic_review']['verdict'] ?? '') !== 'PASS'
                || ($action['causality_claimed'] ?? true) !== false
                || ($action['business_outcome_claimed'] ?? true) !== false
                || ($action['external_action_triggered'] ?? true) !== false
                || ($action['ota_mutation_performed'] ?? true) !== false
                || !$this->isDigest((string)($action['action_content_digest'] ?? ''))
                || !hash_equals((string)$action['action_content_digest'], $this->actionDigest($action))
            ) {
                throw new RuntimeException('canonical_action_contract_invalid');
            }
            $types[] = (string)$action['action_type'];
        }
        $expectedTypes = self::actionTypesForPlatform($scope['platform']);
        if ($types !== $expectedTypes || count(array_unique($types)) !== 4) {
            throw new RuntimeException('canonical_action_types_invalid');
        }
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $actionSet
     * @param array<string,mixed> $action
     * @return array{idempotent:bool,intent_id:int,task_id:int,evidence_id:int,action_type:string}
     */
    private function persistOneAction(
        array $scope,
        array $actionSet,
        array $action,
        array $ownerMetadata
    ): array
    {
        $idempotencyKey = $this->idempotencyKey($scope, $actionSet, $action);
        $existing = Db::name('operation_execution_intents')
            ->where('idempotency_key', $idempotencyKey)
            ->whereNull('deleted_at')
            ->lock(true)
            ->find();
        if (is_array($existing)) {
            return $this->assertPersistedTriplet(
                $scope,
                $actionSet,
                $action,
                $existing,
                true,
                $ownerMetadata
            );
        }

        $now = ($this->clock)();
        if (strtotime($now) === false) {
            throw new RuntimeException('canonical_action_clock_invalid');
        }
        $intentEvidence = $this->intentEvidence($scope, $actionSet, $action, $ownerMetadata);
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => $scope['tenant_id'],
            'idempotency_key' => $idempotencyKey,
            'source_module' => self::SOURCE_MODULE,
            'source_record_id' => $scope['row_id'],
            'hotel_id' => $scope['hotel_id'],
            'platform' => $scope['platform'],
            'object_type' => 'operation_checklist',
            'action_type' => $action['action_type'],
            'date_start' => $scope['target_date'],
            'date_end' => $scope['target_date'],
            'current_value_json' => $this->json([
                'source_scope' => $scope,
                'deterministic_inputs' => $action['deterministic_result']['inputs']
                    ?? $action['deterministic_result']['stable_scope']
                    ?? [],
            ]),
            'target_value_json' => $this->json([
                'title' => $action['title'],
                'action_text' => $action['action_text'],
                'steps' => [$action['action_text']],
                'acceptance_criteria' => $action['acceptance_criteria'],
                'execution_scope' => 'analysis_only',
                'external_write' => false,
                'assignee_id' => null,
                'due_at' => null,
                'review_at' => null,
            ]),
            'evidence_json' => $this->json($intentEvidence),
            'expected_metric' => 'investigation_completion',
            'expected_delta' => null,
            'risk_level' => 'low',
            'status' => self::INTENT_STATUS,
            'blocked_reason' => '',
            'review_remark' => 'System-authorized deterministic analysis only; no human approval or external action.',
            'created_by' => 0,
            'approved_by' => 0,
            'approved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        if ($intentId <= 0) {
            throw new RuntimeException('canonical_action_intent_save_failed');
        }

        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => $scope['tenant_id'],
            'intent_id' => $intentId,
            'hotel_id' => $scope['hotel_id'],
            'execution_mode' => 'analysis_only',
            'operator_id' => 0,
            'target_value_json' => $this->json(['action_content_digest' => $action['action_content_digest']]),
            'current_value_json' => $this->json(['deterministic_result' => $action['deterministic_result']]),
            'blocked_reason' => '',
            'action_track_id' => 0,
            'result_status' => 'observing',
            'result_summary' => '确定性核查已完成；未执行 OTA 或外部动作，未声称原因或经营成效。',
            'status' => 'executed',
            'executed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        if ($taskId <= 0) {
            throw new RuntimeException('canonical_action_task_save_failed');
        }

        $receipt = $this->evidenceReceipt(
            $scope,
            $actionSet,
            $action,
            $intentId,
            $taskId,
            $now,
            $ownerMetadata
        );
        $evidenceId = (int)Db::name('operation_execution_evidence')->insertGetId([
            'tenant_id' => $scope['tenant_id'],
            'task_id' => $taskId,
            'evidence_type' => self::EVIDENCE_TYPE,
            'before_json' => $this->json([]),
            'after_json' => $this->json([]),
            'attachment_path' => '',
            'platform_response_json' => $this->json(['analysis_receipt' => $receipt]),
            'remark' => 'System-generated deterministic OTA investigation readback; no external action or outcome claim.',
            'created_by' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);
        if ($evidenceId <= 0) {
            throw new RuntimeException('canonical_action_evidence_save_failed');
        }

        $inserted = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        if (!is_array($inserted)) {
            throw new RuntimeException('canonical_action_intent_readback_missing');
        }
        return $this->assertPersistedTriplet(
            $scope,
            $actionSet,
            $action,
            $inserted,
            false,
            $ownerMetadata
        );
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $set @param array<string,mixed> $action */
    private function idempotencyKey(array $scope, array $set, array $action): string
    {
        $basis = [
            'scope' => $scope,
            'source_draft_set_digest' => $set['source_draft_set_digest'],
            'promotion_content_digest' => $set['promotion_content_digest'],
            'authoritative_fact_digest' => $set['authoritative_fact_digest'],
            'contract_digest' => $set['contract_digest'],
            'action_content_digest' => $action['action_content_digest'],
        ];
        if (isset($set['scheduled_analysis_authorization_digest'])) {
            $basis['scheduled_analysis_authorization_digest'] =
                (string)$set['scheduled_analysis_authorization_digest'];
        }
        return self::SOURCE_MODULE . ':v1:' . hash('sha256', $this->json($basis));
    }

    /** @return array<string,mixed> */
    private function intentEvidence(
        array $scope,
        array $set,
        array $action,
        array $ownerMetadata
    ): array
    {
        $scheduledAuthorization = is_array($set['scheduled_analysis_authorization'] ?? null)
            ? $set['scheduled_analysis_authorization']
            : [];
        $evidence = [
            'schema_version' => self::ACTION_SET_VERSION,
            'draft_set_id' => $set['source_draft_set_id'],
            'draft_set_content_digest' => $set['source_draft_set_digest'],
            'action_set_digest' => $set['content_digest'],
            'action_id' => $action['action_id'],
            'action_code' => $action['draft_action_code'],
            'action_content_digest' => $action['action_content_digest'],
            'tenant_id' => $scope['tenant_id'],
            'hotel_id' => $scope['hotel_id'],
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'row_id' => $scope['row_id'],
            'platform' => $scope['platform'],
            'target_date' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'promotion_content_digest' => $set['promotion_content_digest'],
            'authoritative_fact_digest' => $set['authoritative_fact_digest'],
            'platform_hotel_identity_digest' => $set['platform_hotel_identity_digest'],
            'contract_digest' => $set['contract_digest'],
            'metric_scope' => 'ota_channel',
            'execution_scope' => 'analysis_only',
            'approval_authority' => $scheduledAuthorization === []
                ? 'system_goal_scoped_analysis'
                : 'system_scheduled_analysis',
            'human_approval_claimed' => false,
            'expected_delta_status' => 'not_quantified',
            'external_write' => false,
            'causality_claimed' => false,
            'outcome_claimed' => false,
        ];
        if ($scheduledAuthorization !== []) {
            $evidence['scheduled_analysis_authorization'] = $scheduledAuthorization;
            $evidence['scheduled_analysis_authorization_digest'] =
                (string)$set['scheduled_analysis_authorization_digest'];
        }
        foreach ($ownerMetadata as $field => $value) {
            $evidence[$field] = $value;
        }
        return $evidence;
    }

    /** @return array<string,mixed> */
    private function evidenceReceipt(
        array $scope,
        array $set,
        array $action,
        int $intentId,
        int $taskId,
        string $now,
        array $ownerMetadata
    ): array {
        $receipt = [
            'schema_version' => self::EVIDENCE_VERSION,
            'verification_authority' => 'canonical_ota_investigation_service',
            'source' => 'online_daily_data',
            'source_ref' => 'online_daily_data#' . $scope['row_id'],
            'tenant_id' => $scope['tenant_id'],
            'system_hotel_id' => $scope['hotel_id'],
            'data_source_id' => $scope['data_source_id'],
            'sync_task_id' => $scope['task_id'],
            'row_id' => $scope['row_id'],
            'platform' => $scope['platform'],
            'object_type' => 'operation_checklist',
            'date_start' => $scope['target_date'],
            'date_end' => $scope['target_date'],
            'data_period' => $scope['data_period'],
            'operation_intent_id' => $intentId,
            'operation_task_id' => $taskId,
            'action_type' => $action['action_type'],
            'draft_action_code' => $action['draft_action_code'],
            'action_content_digest' => $action['action_content_digest'],
            'action_set_digest' => $set['content_digest'],
            'source_draft_set_digest' => $set['source_draft_set_digest'],
            'promotion_content_digest' => $set['promotion_content_digest'],
            'authoritative_fact_digest' => $set['authoritative_fact_digest'],
            'platform_hotel_identity_digest' => $set['platform_hotel_identity_digest'],
            'contract_digest' => $set['contract_digest'],
            'formula_contract' => $action['formula_contract'],
            'deterministic_result' => $action['deterministic_result'],
            'deterministic_review' => $action['deterministic_review'],
            'action_snapshot' => $action,
            'metric_key' => 'investigation_completion',
            'database_written' => true,
            'readback_verified' => true,
            'readback_count' => 1,
            'readback_at' => $now,
            'validation_status' => 'verified',
            'failure_reason' => '',
            'execution_scope' => 'analysis_only',
            'external_write' => false,
            'external_action_triggered' => false,
            'ota_mutation_performed' => false,
            'causality_claimed' => false,
            'business_outcome_claimed' => false,
        ];
        if (is_array($set['scheduled_analysis_authorization'] ?? null)) {
            $receipt['approval_authority'] = 'system_scheduled_analysis';
            $receipt['scheduled_analysis_authorization'] = $set['scheduled_analysis_authorization'];
            $receipt['scheduled_analysis_authorization_digest'] =
                (string)($set['scheduled_analysis_authorization_digest'] ?? '');
        } else {
            $receipt['approval_authority'] = 'system_goal_scoped_analysis';
        }
        foreach ($ownerMetadata as $field => $value) {
            $receipt[$field] = $value;
        }
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $set
     * @param array<string,mixed> $action
     * @param array<string,mixed> $intent
     * @return array{idempotent:bool,intent_id:int,task_id:int,evidence_id:int,action_type:string}
     */
    private function assertPersistedTriplet(
        array $scope,
        array $set,
        array $action,
        array $intent,
        bool $idempotent,
        array $ownerMetadata
    ): array {
        $intentId = (int)($intent['id'] ?? 0);
        $intentEvidence = $this->decodeJson($intent['evidence_json'] ?? null, 'canonical_action_intent_evidence_invalid');
        $scheduledAuthorization = is_array($set['scheduled_analysis_authorization'] ?? null)
            ? $set['scheduled_analysis_authorization']
            : [];
        $expectedApprovalAuthority = $scheduledAuthorization === []
            ? 'system_goal_scoped_analysis'
            : 'system_scheduled_analysis';
        if ($intentId <= 0
            || (int)($intent['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($intent['hotel_id'] ?? 0) !== $scope['hotel_id']
            || (int)($intent['source_record_id'] ?? 0) !== $scope['row_id']
            || (string)($intent['source_module'] ?? '') !== self::SOURCE_MODULE
            || strtolower((string)($intent['platform'] ?? '')) !== $scope['platform']
            || (string)($intent['object_type'] ?? '') !== 'operation_checklist'
            || (string)($intent['action_type'] ?? '') !== $action['action_type']
            || substr((string)($intent['date_start'] ?? ''), 0, 10) !== $scope['target_date']
            || substr((string)($intent['date_end'] ?? ''), 0, 10) !== $scope['target_date']
            || (string)($intent['expected_metric'] ?? '') !== 'investigation_completion'
            || ($intent['expected_delta'] ?? null) !== null
            || (string)($intent['risk_level'] ?? '') !== 'low'
            || (string)($intent['status'] ?? '') !== self::INTENT_STATUS
            || (int)($intent['created_by'] ?? 0) !== 0
            || (int)($intent['approved_by'] ?? 0) !== 0
            || trim((string)($intent['approved_at'] ?? '')) !== ''
            || ($intentEvidence['human_approval_claimed'] ?? true) !== false
            || ($intentEvidence['external_write'] ?? true) !== false
            || (string)($intentEvidence['approval_authority'] ?? '') !== $expectedApprovalAuthority
            || ($scheduledAuthorization === []
                ? array_key_exists('scheduled_analysis_authorization', $intentEvidence)
                : ($intentEvidence['scheduled_analysis_authorization'] ?? null) !== $scheduledAuthorization)
            || ($scheduledAuthorization !== []
                && !hash_equals(
                    (string)$set['scheduled_analysis_authorization_digest'],
                    (string)($intentEvidence['scheduled_analysis_authorization_digest'] ?? '')
                ))
            || (int)($intentEvidence['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($intentEvidence['hotel_id'] ?? 0) !== $scope['hotel_id']
            || (int)($intentEvidence['data_source_id'] ?? 0) !== $scope['data_source_id']
            || (int)($intentEvidence['sync_task_id'] ?? 0) !== $scope['task_id']
            || (int)($intentEvidence['row_id'] ?? 0) !== $scope['row_id']
            || strtolower((string)($intentEvidence['platform'] ?? '')) !== $scope['platform']
            || (string)($intentEvidence['target_date'] ?? '') !== $scope['target_date']
            || strtolower((string)($intentEvidence['data_period'] ?? '')) !== $scope['data_period']
            || !hash_equals((string)$set['source_draft_set_digest'], (string)($intentEvidence['draft_set_content_digest'] ?? ''))
            || !hash_equals((string)$set['content_digest'], (string)($intentEvidence['action_set_digest'] ?? ''))
            || !hash_equals((string)$action['action_content_digest'], (string)($intentEvidence['action_content_digest'] ?? ''))
            || !hash_equals((string)$set['promotion_content_digest'], (string)($intentEvidence['promotion_content_digest'] ?? ''))
            || !hash_equals((string)$set['authoritative_fact_digest'], (string)($intentEvidence['authoritative_fact_digest'] ?? ''))
            || !hash_equals((string)$set['platform_hotel_identity_digest'], (string)($intentEvidence['platform_hotel_identity_digest'] ?? ''))
            || !hash_equals((string)$set['contract_digest'], (string)($intentEvidence['contract_digest'] ?? ''))
            || !$this->ownerMetadataMatches($intentEvidence, $ownerMetadata)
        ) {
            throw new RuntimeException('canonical_action_intent_idempotency_conflict');
        }

        $tasks = Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        if (count($tasks) !== 1) {
            throw new RuntimeException('canonical_action_task_membership_invalid');
        }
        $task = $tasks[0];
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0
            || (int)($task['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($task['hotel_id'] ?? 0) !== $scope['hotel_id']
            || (string)($task['execution_mode'] ?? '') !== 'analysis_only'
            || (int)($task['operator_id'] ?? 0) !== 0
            || (int)($task['action_track_id'] ?? 0) !== 0
            || (string)($task['status'] ?? '') !== 'executed'
            || (string)($task['result_status'] ?? '') !== 'observing'
        ) {
            throw new RuntimeException('canonical_action_task_readback_invalid');
        }

        $evidenceRows = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        if (count($evidenceRows) !== 1) {
            throw new RuntimeException('canonical_action_evidence_membership_invalid');
        }
        $evidence = $evidenceRows[0];
        $receiptWrapper = $this->decodeJson(
            $evidence['platform_response_json'] ?? null,
            'canonical_action_evidence_receipt_invalid'
        );
        $receipt = is_array($receiptWrapper['analysis_receipt'] ?? null)
            ? $receiptWrapper['analysis_receipt']
            : [];
        if ((int)($evidence['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (string)($evidence['evidence_type'] ?? '') !== self::EVIDENCE_TYPE
            || (int)($evidence['created_by'] ?? 0) !== 0
            || $this->decodeJson($evidence['before_json'] ?? null, 'canonical_action_before_invalid') !== []
            || $this->decodeJson($evidence['after_json'] ?? null, 'canonical_action_after_invalid') !== []
            || (int)($receipt['operation_intent_id'] ?? 0) !== $intentId
            || (int)($receipt['operation_task_id'] ?? 0) !== $taskId
            || (int)($receipt['row_id'] ?? 0) !== $scope['row_id']
            || (string)($receipt['action_type'] ?? '') !== $action['action_type']
            || !hash_equals((string)$action['action_content_digest'], (string)($receipt['action_content_digest'] ?? ''))
            || ($receipt['formula_contract'] ?? null) !== $action['formula_contract']
            || ($receipt['deterministic_result'] ?? null) !== $action['deterministic_result']
            || ($receipt['deterministic_review'] ?? null) !== $action['deterministic_review']
            || ($receipt['action_snapshot'] ?? null) !== $action
            || (string)($receipt['approval_authority'] ?? '') !== $expectedApprovalAuthority
            || ($scheduledAuthorization === []
                ? array_key_exists('scheduled_analysis_authorization', $receipt)
                : ($receipt['scheduled_analysis_authorization'] ?? null) !== $scheduledAuthorization)
            || ($scheduledAuthorization !== []
                && !hash_equals(
                    (string)$set['scheduled_analysis_authorization_digest'],
                    (string)($receipt['scheduled_analysis_authorization_digest'] ?? '')
                ))
            || !$this->ownerMetadataMatches($receipt, $ownerMetadata)
            || ($receipt['external_action_triggered'] ?? true) !== false
            || ($receipt['business_outcome_claimed'] ?? true) !== false
            || !$this->isDigest((string)($receipt['content_digest'] ?? ''))
            || !hash_equals((string)$receipt['content_digest'], $this->digest($receipt))
        ) {
            throw new RuntimeException('canonical_action_evidence_readback_invalid');
        }

        $normalizedIntent = $intent;
        $normalizedIntent['current_value'] = $this->decodeJson($intent['current_value_json'] ?? null, 'canonical_action_current_invalid');
        $normalizedIntent['target_value'] = $this->decodeJson($intent['target_value_json'] ?? null, 'canonical_action_target_invalid');
        $normalizedIntent['evidence'] = $intentEvidence;
        $normalizedTask = $task;
        $normalizedTask['current_value'] = $this->decodeJson($task['current_value_json'] ?? null, 'canonical_action_task_current_invalid');
        $normalizedTask['target_value'] = $this->decodeJson($task['target_value_json'] ?? null, 'canonical_action_task_target_invalid');
        $normalizedEvidence = $evidence;
        $normalizedEvidence['before'] = [];
        $normalizedEvidence['after'] = [];
        $normalizedEvidence['platform_response'] = $receiptWrapper;
        $truth = (new ExecutionOutcomeService())->assessExecutionEvidenceTruth(
            $normalizedIntent,
            $normalizedTask,
            $normalizedEvidence
        );
        if (($truth['source_verified'] ?? false) !== true) {
            throw new RuntimeException('canonical_action_evidence_truth_unverified');
        }

        return [
            'idempotent' => $idempotent,
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'evidence_id' => (int)$evidence['id'],
            'action_type' => (string)$action['action_type'],
        ];
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $set @return array<string,mixed> */
    private function exactReadback(array $scope, array $set, array $ownerMetadata): array
    {
        $records = [];
        foreach ($set['actions'] as $action) {
            $key = $this->idempotencyKey($scope, $set, $action);
            $intent = Db::name('operation_execution_intents')
                ->where('idempotency_key', $key)
                ->whereNull('deleted_at')
                ->find();
            if (!is_array($intent)) {
                throw new RuntimeException('canonical_action_exact_readback_missing');
            }
            $records[] = $this->assertPersistedTriplet(
                $scope,
                $set,
                $action,
                $intent,
                true,
                $ownerMetadata
            );
        }
        if (count($records) !== 4 || count(array_unique(array_column($records, 'intent_id'))) !== 4) {
            throw new RuntimeException('canonical_action_exact_readback_count_invalid');
        }

        $flow = ($this->flowReader)(
            [$scope['hotel_id']],
            $scope['hotel_id'],
            [
                'source_module' => self::SOURCE_MODULE,
                'platform' => $scope['platform'],
                'target_date' => $scope['target_date'],
                'limit' => 100,
            ]
        );
        $recordIntentIds = array_map('intval', array_column($records, 'intent_id'));
        sort($recordIntentIds, SORT_NUMERIC);
        $matchedItems = array_values(array_filter(
            (array)($flow['list'] ?? []),
            static function (array $item) use ($recordIntentIds, $scope): bool {
                return in_array((int)($item['id'] ?? 0), $recordIntentIds, true)
                    && (int)($item['hotel_id'] ?? 0) === $scope['hotel_id']
                    && (int)($item['recommendation']['source_record_id'] ?? 0) === $scope['row_id'];
            }
        ));
        if (count($matchedItems) !== 4) {
            throw new RuntimeException('canonical_action_operation_flow_membership_invalid');
        }
        foreach ($matchedItems as $item) {
            if (($item['approval']['status'] ?? '') !== self::INTENT_STATUS
                || ($item['execution']['mode'] ?? '') !== 'analysis_only'
                || ($item['execution']['status'] ?? '') !== 'executed'
                || ($item['evidence_truth']['source_verified'] ?? false) !== true
                || ($item['outcome_truth']['outcome_verified'] ?? true) !== false
                || ($item['stage'] ?? '') !== 'review'
                || ($item['review']['reported_status'] ?? '') !== 'observing'
                || ($item['next_action']['key'] ?? '') !== 'none'
            ) {
                throw new RuntimeException('canonical_action_operation_flow_truth_invalid');
            }
        }

        return [
            'records' => $records,
            'flow_summary' => [
                'matched_action_count' => 4,
                'stage' => 'review',
                'execution_status' => 'executed',
                'evidence_status' => 'source_verified',
                'outcome_status' => 'unverified_observing',
            ],
        ];
    }

    /** @param array<string,mixed> $gate @param array<string,mixed> $scope @return array<string,mixed> */
    private function assertPlatformSelectionGate(array $gate, array $scope): array
    {
        $status = strtolower(trim((string)($gate['status'] ?? '')));
        $ownerScope = $this->ownerScope($scope);
        if (($gate['scope'] ?? null) !== $scope
            || ($gate['owner_scope'] ?? null) !== $ownerScope
            || !in_array($status, ['claimable', 'replay'], true)
        ) {
            throw new RuntimeException('canonical_action_daily_platform_selection_gate_invalid');
        }
        if ($status === 'claimable') {
            if (($gate['claimable'] ?? false) !== true
                || ($gate['replay'] ?? true) !== false
                || ($gate['selection_receipt'] ?? null) !== null
            ) {
                throw new RuntimeException('canonical_action_daily_platform_selection_gate_invalid');
            }
            return $this->ownerMetadata($scope);
        }

        $receipt = is_array($gate['selection_receipt'] ?? null)
            ? $gate['selection_receipt']
            : [];
        $this->assertSelectionReceiptCore($receipt, $scope);
        if (($gate['claimable'] ?? true) !== false || ($gate['replay'] ?? false) !== true) {
            throw new RuntimeException('canonical_action_daily_platform_selection_gate_invalid');
        }
        return (string)($receipt['owner_source'] ?? '') === 'legacy_four_intent_inference'
            ? []
            : $this->ownerMetadata($scope);
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $scope
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function assertPlatformSelectionResolved(
        array $selection,
        array $scope,
        array $rows,
        string $actionSetDigest
    ): array {
        $receipt = is_array($selection['selection_receipt'] ?? null)
            ? $selection['selection_receipt']
            : [];
        $this->assertSelectionReceiptCore($receipt, $scope);
        $intentIds = array_map('intval', array_column($rows, 'intent_id'));
        sort($intentIds, SORT_NUMERIC);
        $receiptIntentIds = array_map('intval', is_array($receipt['intent_ids'] ?? null)
            ? $receipt['intent_ids']
            : []);
        sort($receiptIntentIds, SORT_NUMERIC);
        if (($selection['status'] ?? '') !== 'selected'
            || ($selection['selected'] ?? false) !== true
            || ($selection['scope'] ?? null) !== $scope
            || ($selection['owner_scope'] ?? null) !== $this->ownerScope($scope)
            || $receiptIntentIds !== $intentIds
            || count($intentIds) !== 4
            || !hash_equals($actionSetDigest, (string)($receipt['action_set_digest'] ?? ''))
        ) {
            throw new RuntimeException('canonical_action_daily_platform_selection_readback_invalid');
        }
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $scope */
    private function assertSelectionReceiptCore(array $receipt, array $scope): void
    {
        $ownerScope = $this->ownerScope($scope);
        $policy = [
            'name' => CanonicalOtaDailyPlatformSelectionService::POLICY,
            'version' => CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION,
            'preference' => ['ctrip', 'meituan'],
            'sticky_after_claim' => true,
        ];
        if (($receipt['schema_version'] ?? '')
                !== CanonicalOtaDailyPlatformSelectionService::SCHEMA_VERSION
            || ($receipt['status'] ?? '') !== 'selected'
            || ($receipt['selection_policy'] ?? '')
                !== CanonicalOtaDailyPlatformSelectionService::POLICY
            || ($receipt['selection_policy_version'] ?? '')
                !== CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION
            || !hash_equals(
                $this->digest($policy),
                (string)($receipt['selection_policy_digest'] ?? '')
            )
            || ($receipt['owner_scope'] ?? null) !== $ownerScope
            || !hash_equals(
                $this->digest($ownerScope),
                (string)($receipt['owner_scope_digest'] ?? '')
            )
            || ($receipt['scope'] ?? null) !== $scope
            || ($receipt['selected_platform'] ?? '') !== $scope['platform']
            || ($receipt['readback_verified'] ?? false) !== true
            || !$this->isDigest((string)($receipt['content_digest'] ?? ''))
            || !hash_equals((string)$receipt['content_digest'], $this->digest($receipt))
        ) {
            throw new RuntimeException('canonical_action_daily_platform_selection_receipt_invalid');
        }
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function ownerScope(array $scope): array
    {
        return [
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'target_date' => (string)$scope['target_date'],
            'data_period' => (string)$scope['data_period'],
        ];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function ownerMetadata(array $scope): array
    {
        $ownerScope = $this->ownerScope($scope);
        return [
            'owner_scope_digest' => $this->digest($ownerScope),
            'owner_platform' => (string)$scope['platform'],
            'selection_policy' => CanonicalOtaDailyPlatformSelectionService::POLICY,
            'selection_policy_version' => CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION,
        ];
    }

    /** @param array<string,mixed> $container @param array<string,mixed> $expected */
    private function ownerMetadataMatches(array $container, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $container) || $container[$field] !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $scope */
    private function lockExactCanonicalSource(array $scope): void
    {
        $task = Db::name('platform_data_sync_tasks')
            ->where('id', $scope['task_id'])
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $scope['hotel_id'])
            ->where('data_source_id', $scope['data_source_id'])
            ->where('platform', $scope['platform'])
            ->lock(true)
            ->find();
        $row = Db::name('online_daily_data')
            ->where('id', $scope['row_id'])
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $scope['hotel_id'])
            ->where('data_source_id', $scope['data_source_id'])
            ->where('sync_task_id', $scope['task_id'])
            ->where('source', $scope['platform'])
            ->where('platform', $scope['platform'])
            ->where('data_date', $scope['target_date'])
            ->where('data_period', $scope['data_period'])
            ->where('readback_verified', 1)
            ->lock(true)
            ->find();
        if (!is_array($row) || !is_array($task)) {
            throw new RuntimeException('canonical_action_exact_source_lock_failed');
        }
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function normalizeScope(array $scope): array
    {
        $fields = ['tenant_id', 'hotel_id', 'data_source_id', 'task_id', 'row_id', 'platform', 'target_date', 'data_period'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $scope)) {
                throw new InvalidArgumentException('canonical_action_scope_field_missing:' . $field);
            }
        }
        $normalized = [
            'tenant_id' => $this->positiveInteger($scope['tenant_id'], 'tenant_id'),
            'hotel_id' => $this->positiveInteger($scope['hotel_id'], 'hotel_id'),
            'data_source_id' => $this->positiveInteger($scope['data_source_id'], 'data_source_id'),
            'task_id' => $this->positiveInteger($scope['task_id'], 'task_id'),
            'row_id' => $this->positiveInteger($scope['row_id'], 'row_id'),
            'platform' => strtolower(trim((string)$scope['platform'])),
            'target_date' => trim((string)$scope['target_date']),
            'data_period' => strtolower(trim((string)$scope['data_period'])),
        ];
        if (!in_array($normalized['platform'], ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('canonical_action_platform_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized['target_date']);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $normalized['target_date']) {
            throw new InvalidArgumentException('canonical_action_target_date_invalid');
        }
        if (preg_match('/^[a-z0-9_]{1,40}$/D', $normalized['data_period']) !== 1) {
            throw new InvalidArgumentException('canonical_action_data_period_invalid');
        }
        return $normalized;
    }

    /** @return array<string,string> */
    private static function actionTypeMapForPlatform(string $platform): array
    {
        return match (strtolower(trim($platform))) {
            'ctrip' => self::ACTION_TYPES,
            'meituan' => self::MEITUAN_ACTION_TYPES,
            default => throw new InvalidArgumentException('canonical_action_platform_invalid'),
        };
    }

    /** @param array<string,mixed> $authorization @param array<string,mixed> $scope @return array<string,mixed> */
    private function normalizeScheduledAuthorization(array $authorization, array $scope): array
    {
        $normalized = [
            'schema_version' => trim((string)($authorization['schema_version'] ?? '')),
            'enabled' => ($authorization['enabled'] ?? false) === true,
            'plan_id' => strtolower(trim((string)($authorization['plan_id'] ?? ''))),
            'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
            'hotel_id' => (int)($authorization['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($authorization['platform'] ?? ''))),
            'trigger' => strtolower(trim((string)($authorization['trigger'] ?? ''))),
            'authorized_at' => trim((string)($authorization['authorized_at'] ?? '')),
            'authorized_by' => strtolower(trim((string)($authorization['authorized_by'] ?? ''))),
            'analysis_only' => ($authorization['analysis_only'] ?? false) === true,
            'operation_count' => (int)($authorization['operation_count'] ?? 0),
            'external_action_allowed' => ($authorization['external_action_allowed'] ?? true) === true,
        ];
        $digest = strtolower(trim((string)($authorization['content_digest'] ?? '')));
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized['authorized_at']);
        if ($normalized['schema_version'] !== self::SCHEDULED_AUTHORIZATION_VERSION
            || $normalized['enabled'] !== true
            || preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $normalized['plan_id']) !== 1
            || $normalized['tenant_id'] !== $scope['tenant_id']
            || $normalized['hotel_id'] !== $scope['hotel_id']
            || $normalized['platform'] !== $scope['platform']
            || $normalized['trigger'] !== 'historical_daily_canonical_promotion'
            || !($time instanceof \DateTimeImmutable)
            || $time->format('Y-m-d\TH:i:sP') !== $normalized['authorized_at']
            || $normalized['authorized_by'] !== 'user_goal'
            || $normalized['analysis_only'] !== true
            || $normalized['operation_count'] !== 4
            || $normalized['external_action_allowed'] !== false
            || !$this->isDigest($digest)
            || !hash_equals($digest, $this->digest($normalized))
        ) {
            throw new InvalidArgumentException('canonical_action_scheduled_authorization_invalid');
        }
        $normalized['content_digest'] = $digest;
        return $normalized;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new InvalidArgumentException('canonical_action_positive_integer_required:' . $field);
        }
        return (int)$validated;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && floor($value) === $value) {
            return (int)$value;
        }
        if (is_string($value)) {
            if (preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1) {
                return (int)$value;
            }
            if (preg_match('/^((?:0|[1-9]\d*))\.0+$/D', $value, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        throw new RuntimeException('canonical_action_metric_non_negative_integer_required:' . $field);
    }

    private function nonNegativeDecimal(mixed $value, string $field): float
    {
        if (!is_int($value) && !is_float($value)
            && !(is_string($value) && preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1)
        ) {
            throw new RuntimeException('canonical_action_metric_non_negative_decimal_required:' . $field);
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new RuntimeException('canonical_action_metric_non_negative_decimal_required:' . $field);
        }
        return $number;
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): int => (int)$item,
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $value */
    private function legacyDraftDigest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', $this->json($value));
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    /** @param array<string,mixed> $value */
    private function actionDigest(array $value): string
    {
        unset($value['action_content_digest']);
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value, string $error): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($error);
        }
        return $decoded;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }
}
