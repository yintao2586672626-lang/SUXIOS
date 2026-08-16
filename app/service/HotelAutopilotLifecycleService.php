<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Durable, secret-free lifecycle projection for a tenant and each hotel.
 *
 * The service coordinates existing binding, signed collection-plan, durable
 * collection-receipt, operating-loop and profile-preview capabilities. It
 * never stores credentials or raw OTA payloads and never writes to an OTA.
 */
final class HotelAutopilotLifecycleService
{
    public const TENANT_TABLE = 'tenant_automation_lifecycles';
    public const HOTEL_TABLE = 'hotel_automation_lifecycles';
    public const SCHEMA_VERSION = 1;
    public const TOTAL_STAGE_COUNT = 6;

    /** @var Closure(array<string,mixed>,int,string,array<string,int>):array<string,mixed> */
    private Closure $bindingReceiptLoader;

    /** @var Closure(array<string,mixed>,int,string):array<string,mixed> */
    private Closure $planReader;

    /** @var Closure(array<string,mixed>,int,array<string,mixed>):array<string,mixed> */
    private Closure $planSaver;

    /** @var Closure(array<string,mixed>):array<string,mixed> */
    private Closure $dispatcherProvisioner;

    /** @var Closure(int,int,int):array<string,mixed> */
    private Closure $latestRunLoader;

    /** @var Closure(int,int,string,int):array<string,mixed> */
    private Closure $operatingLoopReconciler;

    /** @var Closure(int,int,array<int,int>):array<string,mixed> */
    private Closure $profilePreviewer;

    /** @var Closure(int,int,string,string):array<string,mixed> */
    private Closure $analysisAuthorizationProvisioner;

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(string,int,int,string):array<string,mixed> */
    private Closure $qualityJudgmentProvisioner;

    /** @var Closure(int,int,string,int,array<int,array<string,mixed>>):array<string,mixed> */
    private Closure $approvalIntentProvisioner;

    public function __construct(
        ?callable $bindingReceiptLoader = null,
        ?callable $planReader = null,
        ?callable $planSaver = null,
        ?callable $dispatcherProvisioner = null,
        ?callable $latestRunLoader = null,
        ?callable $operatingLoopReconciler = null,
        ?callable $profilePreviewer = null,
        ?callable $analysisAuthorizationProvisioner = null,
        ?callable $clock = null,
        ?callable $qualityJudgmentProvisioner = null,
        ?callable $approvalIntentProvisioner = null
    ) {
        $this->bindingReceiptLoader = $bindingReceiptLoader !== null
            ? Closure::fromCallable($bindingReceiptLoader)
            : static fn(array $hotel, int $actorId, string $date, array $sources): array =>
                (new HotelCollectionBindingReceiptService())->receipt($hotel, $actorId, $date, $sources);
        $this->planReader = $planReader !== null
            ? Closure::fromCallable($planReader)
            : static fn(array $hotel, int $actorId, string $date): array =>
                (new HotelCollectionPlanService())->read($hotel, $actorId, $date);
        $this->planSaver = $planSaver !== null
            ? Closure::fromCallable($planSaver)
            : static fn(array $hotel, int $actorId, array $input): array =>
                (new HotelCollectionPlanService())->save($hotel, $actorId, $input);
        $this->dispatcherProvisioner = $dispatcherProvisioner !== null
            ? Closure::fromCallable($dispatcherProvisioner)
            : static fn(array $scope): array =>
                (new HotelAutopilotDispatcherProvisioningService())->provision($scope);
        $this->latestRunLoader = $latestRunLoader !== null
            ? Closure::fromCallable($latestRunLoader)
            : fn(int $tenantId, int $hotelId, int $planId): array =>
                $this->loadLatestRun($tenantId, $hotelId, $planId);
        $this->operatingLoopReconciler = $operatingLoopReconciler !== null
            ? Closure::fromCallable($operatingLoopReconciler)
            : static fn(int $tenantId, int $hotelId, string $date, int $actorId): array =>
                (new OperatingLoopCoordinatorService())->reconcile($tenantId, $hotelId, $date, $actorId);
        $this->profilePreviewer = $profilePreviewer !== null
            ? Closure::fromCallable($profilePreviewer)
            : static fn(int $tenantId, int $hotelId, array $hotelIds): array =>
                (new OperatingNetworkService())->previewProfileDraft($tenantId, $hotelId, $hotelIds);
        $this->analysisAuthorizationProvisioner = $analysisAuthorizationProvisioner !== null
            ? Closure::fromCallable($analysisAuthorizationProvisioner)
            : fn(int $tenantId, int $hotelId, string $platform, string $planId): array =>
                $this->provisionAnalysisAuthorization($tenantId, $hotelId, $platform, $planId);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $this->qualityJudgmentProvisioner = $qualityJudgmentProvisioner !== null
            ? Closure::fromCallable($qualityJudgmentProvisioner)
            : static fn(string $runId, int $tenantId, int $hotelId, string $date): array =>
                (new HotelCollectionQualityJudgmentService())->assessAndPersist(
                    $runId,
                    $tenantId,
                    $hotelId,
                    $date
                );
        $this->approvalIntentProvisioner = $approvalIntentProvisioner !== null
            ? Closure::fromCallable($approvalIntentProvisioner)
            : static fn(int $tenantId, int $hotelId, string $date, int $actorId, array $evidenceRefs): array =>
                (new OperatingApprovalIntentService())->createPendingApproval(
                    $tenantId,
                    $hotelId,
                    $date,
                    $actorId,
                    $evidenceRefs
                );
    }

    /** @return array<string,mixed> */
    public function initializeTenant(int $tenantId, int $actorId): array
    {
        if ($tenantId <= 0 || $actorId < 0) {
            throw new InvalidArgumentException('tenant_automation_lifecycle_scope_invalid');
        }
        $this->assertTablesReady();
        $existing = Db::name(self::TENANT_TABLE)->where('tenant_id', $tenantId)->find();
        if (is_array($existing)) {
            return $this->tenantReadback($existing, true);
        }

        $now = $this->now()->format('Y-m-d H:i:s');
        $safeState = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'status' => 'initialized',
            'current_stage' => 'tenant_recorded',
            'automatic_hotel_backfill' => true,
            'external_action_triggered' => false,
            'sensitive_values_exposed' => false,
        ];
        $digest = $this->digest($safeState);
        try {
            Db::name(self::TENANT_TABLE)->insert([
                'tenant_id' => $tenantId,
                'status' => 'initialized',
                'current_stage' => 'tenant_recorded',
                'state_version' => 1,
                'state_digest' => $digest,
                'safe_state_json' => $this->encode($safeState),
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (Throwable $error) {
            $existing = Db::name(self::TENANT_TABLE)->where('tenant_id', $tenantId)->find();
            if (!is_array($existing)) {
                throw new RuntimeException('tenant_automation_lifecycle_create_failed', 0, $error);
            }
        }
        $readback = Db::name(self::TENANT_TABLE)->where('tenant_id', $tenantId)->find();
        if (!is_array($readback) || !hash_equals($digest, (string)($readback['state_digest'] ?? ''))) {
            throw new RuntimeException('tenant_automation_lifecycle_readback_failed');
        }
        return $this->tenantReadback($readback, false);
    }

    /**
     * Must be called inside the hotel-create transaction so a hotel can never
     * be committed without its initial lifecycle record.
     *
     * @param array<string,mixed>|object $hotel
     * @return array<string,mixed>
     */
    public function initializeHotel(array|object $hotel, int $actorId): array
    {
        $hotel = $this->hotelArray($hotel);
        [$tenantId, $hotelId] = $this->scope($hotel);
        if ($actorId < 0) {
            throw new InvalidArgumentException('hotel_automation_lifecycle_actor_invalid');
        }
        $this->assertTablesReady();
        $this->initializeTenant($tenantId, $actorId);
        $existing = Db::name(self::HOTEL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (is_array($existing)) {
            return $this->publicHotelState($existing, true);
        }

        $state = $this->initialState($hotel);
        $row = $this->rowFromState($state, $actorId, 1, true);
        try {
            Db::name(self::HOTEL_TABLE)->insert($row);
        } catch (Throwable $error) {
            $existing = Db::name(self::HOTEL_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->find();
            if (!is_array($existing)) {
                throw new RuntimeException('hotel_automation_lifecycle_create_failed', 0, $error);
            }
        }
        $readback = Db::name(self::HOTEL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($readback)
            || !hash_equals((string)$row['state_digest'], (string)($readback['state_digest'] ?? ''))
        ) {
            throw new RuntimeException('hotel_automation_lifecycle_readback_failed');
        }
        return $this->publicHotelState($readback, false);
    }

    /**
     * Reconcile one hotel. Only the background command should pass
     * $provisionDispatcher=true; HTTP creation never launches collection.
     *
     * @param array<string,mixed>|object $hotel
     * @return array<string,mixed>
     */
    public function reconcileHotel(array|object $hotel, int $actorId, bool $provisionDispatcher = false): array
    {
        $hotel = $this->hotelArray($hotel);
        [$tenantId, $hotelId] = $this->scope($hotel);
        $initial = $this->initializeHotel($hotel, max(0, $actorId));
        $stored = Db::name(self::HOTEL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($stored)) {
            throw new RuntimeException('hotel_automation_lifecycle_readback_failed');
        }
        if ((int)($hotel['status'] ?? 0) !== 1) {
            return $this->persistHotelState($stored, $this->state(
                $hotel,
                'disabled',
                'data_source_binding',
                'disabled',
                null,
                [],
                ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
            ), max(0, $actorId));
        }

        $strategy = $this->strategy((string)($hotel['ota_channel_strategy'] ?? 'none'));
        if ($strategy === 'none') {
            return $this->persistHotelState($stored, $this->state(
                $hotel,
                'awaiting_binding',
                'data_source_binding',
                'missing',
                null,
                [],
                ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
            ), max(0, $actorId));
        }
        if ($strategy !== 'dual') {
            return $this->persistHotelState($stored, $this->state(
                $hotel,
                'blocked',
                'collection_plan',
                'unsupported',
                'hotel_lifecycle_collection_scope_unsupported',
                ['hotel_lifecycle_collection_scope_unsupported'],
                ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
            ), max(0, $actorId));
        }
        if ($actorId <= 0) {
            return $this->persistHotelState($stored, $this->state(
                $hotel,
                'blocked',
                'data_source_binding',
                'blocked',
                'hotel_lifecycle_execution_owner_missing',
                ['hotel_lifecycle_execution_owner_missing'],
                ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
            ), 0);
        }

        $businessDate = $this->previousBusinessDate();
        $existingPlan = ['status' => 'missing'];
        try {
            $existingPlan = ($this->planReader)($hotel, $actorId, $businessDate);
        } catch (Throwable) {
            // A missing or unreadable plan simply leaves source selection to
            // the binding receipt. Never guess source IDs from another hotel.
        }
        try {
            $binding = ($this->bindingReceiptLoader)(
                $hotel,
                $actorId,
                $businessDate,
                $this->designatedSourceIds($existingPlan)
            );
        } catch (Throwable $error) {
            return $this->persistFailure(
                $stored,
                $hotel,
                'data_source_binding',
                'hotel_lifecycle_binding_read_failed',
                $this->safeCode($error->getMessage()),
                $actorId
            );
        }
        $bindingStatus = $this->safeCode((string)($binding['status'] ?? 'blocked')) ?: 'blocked';
        $issueCodes = $this->issueCodes($binding);
        $bindingDigest = strtolower(trim((string)($binding['binding_digest'] ?? '')));
        $ownerId = (int)($binding['execution_owner_user_id'] ?? 0);
        if (($binding['binding_ready'] ?? false) !== true
            || $bindingStatus !== 'ready'
            || !preg_match('/^[a-f0-9]{64}$/D', $bindingDigest)
            || $ownerId <= 0
            || $issueCodes !== []
        ) {
            $loginBlocked = $this->containsLoginIssue($issueCodes);
            $failureCode = $issueCodes[0] ?? ($ownerId <= 0
                ? 'hotel_lifecycle_execution_owner_missing'
                : 'hotel_lifecycle_binding_not_ready');
            $state = $this->state(
                $hotel,
                $loginBlocked ? 'awaiting_login' : 'awaiting_binding',
                'data_source_binding',
                $bindingStatus,
                $failureCode,
                $issueCodes !== [] ? $issueCodes : [$failureCode],
                ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
            );
            $state['binding_digest'] = preg_match('/^[a-f0-9]{64}$/D', $bindingDigest) ? $bindingDigest : null;
            return $this->persistHotelState($stored, $state, $actorId);
        }

        $sources = is_array($binding['bindings'] ?? null) ? $binding['bindings'] : [];
        $ctripSourceId = (int)($sources['ctrip']['source_id'] ?? 0);
        $meituanSourceId = (int)($sources['meituan']['source_id'] ?? 0);
        $pmsProvider = strtolower(trim((string)($sources['pms']['provider'] ?? '')));
        if ($ctripSourceId <= 0 || $meituanSourceId <= 0 || $pmsProvider === '') {
            return $this->persistFailure(
                $stored,
                $hotel,
                'data_source_binding',
                'hotel_lifecycle_binding_identity_incomplete',
                '',
                $actorId
            );
        }

        $plan = $existingPlan;
        if (!$this->planMatches($plan, $bindingDigest, $ctripSourceId, $meituanSourceId, $pmsProvider)) {
            try {
                $plan = ($this->planSaver)($hotel, $ownerId, [
                    'activate' => true,
                    'ctrip_source_id' => $ctripSourceId,
                    'meituan_source_id' => $meituanSourceId,
                    'pms_provider' => $pmsProvider,
                    'business_date_policy' => 'previous_business_day',
                    'timezone' => 'Asia/Shanghai',
                    'schedule_time' => '08:30',
                    'retry_interval_minutes' => 14,
                    'max_attempts' => 7,
                ]);
            } catch (Throwable $error) {
                return $this->persistFailure(
                    $stored,
                    $hotel,
                    'collection_plan',
                    'hotel_lifecycle_plan_activation_failed',
                    $this->safeCode($error->getMessage()),
                    $actorId,
                    $bindingDigest,
                    'ready'
                );
            }
        }
        if (!$this->planReady($plan)) {
            $planFailure = $this->firstPlanFailure($plan) ?: 'hotel_lifecycle_plan_readback_failed';
            return $this->persistFailure(
                $stored,
                $hotel,
                'collection_plan',
                $planFailure,
                '',
                $actorId,
                $bindingDigest,
                'ready'
            );
        }

        $planId = (int)($plan['id'] ?? 0);
        $planHash = strtolower(trim((string)($plan['plan_hash'] ?? '')));
        $analysisAuthorization = $this->authorizeAnalysis($tenantId, $hotelId, $planHash);

        $dispatcher = [
            'status' => (string)($stored['dispatcher_status'] ?? 'not_provisioned'),
            'task_name' => (string)($stored['dispatcher_task_name'] ?? ''),
            'task_started' => false,
            'reason_code' => '',
        ];
        $firstDispatchAt = trim((string)($stored['first_dispatch_requested_at'] ?? ''));
        $ownsFirstDispatch = false;
        if ($provisionDispatcher && $firstDispatchAt === '') {
            [$stored, $ownsFirstDispatch] = $this->claimFirstDispatch($stored, $actorId, [
                'binding_digest' => $bindingDigest,
                'active_plan_id' => $planId,
                'active_plan_hash' => $planHash,
                'analysis_authorization_status' => $analysisAuthorization['status'],
                'analysis_authorization_digest' => $analysisAuthorization['digest'],
            ]);
            $firstDispatchAt = trim((string)($stored['first_dispatch_requested_at'] ?? ''));
        }
        if ($provisionDispatcher) {
            try {
                $dispatcher = ($this->dispatcherProvisioner)([
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'source_ids' => [$ctripSourceId, $meituanSourceId],
                    'platforms' => ['ctrip', 'meituan'],
                    'schedule_time' => (string)($plan['schedule_time'] ?? '08:30'),
                    'plan_id' => $planId,
                    'plan_hash' => $planHash,
                    'replace_existing' => trim((string)($stored['active_plan_hash'] ?? '')) !== ''
                        && !hash_equals((string)$stored['active_plan_hash'], $planHash),
                    'start_now' => $ownsFirstDispatch,
                ]);
            } catch (Throwable $error) {
                $dispatcher = [
                    'status' => 'blocked',
                    'reason_code' => $this->safeCode($error->getMessage()) ?: 'hotel_lifecycle_dispatcher_unavailable',
                    'task_name' => '',
                    'task_started' => false,
                ];
            }
        }

        try {
            $run = ($this->latestRunLoader)($tenantId, $hotelId, $planId);
        } catch (Throwable $error) {
            $run = [
                'status' => 'unavailable',
                'failure_code' => $this->safeCode($error->getMessage()) ?: 'hotel_collection_run_receipt_store_unavailable',
                'readback_verified' => false,
            ];
        }

        $runStatus = $this->safeCode((string)($run['status'] ?? 'missing')) ?: 'missing';
        $common = [
            'binding_digest' => $bindingDigest,
            'active_plan_id' => $planId,
            'active_plan_hash' => $planHash,
            'dispatcher_status' => $this->safeCode((string)($dispatcher['status'] ?? 'not_provisioned')) ?: 'not_provisioned',
            'dispatcher_task_name' => $this->safeText((string)($dispatcher['task_name'] ?? ''), 191),
            'first_dispatch_requested_at' => $firstDispatchAt !== '' ? $firstDispatchAt : null,
            'analysis_authorization_status' => $analysisAuthorization['status'],
            'analysis_authorization_digest' => $analysisAuthorization['digest'],
        ];

        if (in_array($runStatus, ['missing', 'unavailable'], true)) {
            $dispatcherBlocked = $provisionDispatcher
                && (string)($dispatcher['status'] ?? '') !== 'ready'
                && ($dispatcher['task_started'] ?? false) !== true;
            $failure = $dispatcherBlocked
                ? ($this->safeCode((string)($dispatcher['reason_code'] ?? '')) ?: 'hotel_lifecycle_dispatcher_unavailable')
                : null;
            $status = $dispatcherBlocked ? 'blocked' : 'scheduled_waiting_first_collection';
            $state = $this->state(
                $hotel,
                $status,
                'first_trusted_collection',
                'ready',
                $failure,
                $failure !== null ? [$failure] : [],
                ['binding' => true, 'plan' => true, 'collection' => false, 'analysis' => false, 'profile' => false]
            ) + $common;
            $state['last_run_status'] = $runStatus === 'missing' ? null : $runStatus;
            return $this->persistHotelState($stored, $state, $actorId);
        }
        if (in_array($runStatus, ['started', 'in_progress', 'collected'], true)) {
            $state = $this->state(
                $hotel,
                'collecting',
                'first_trusted_collection',
                'ready',
                null,
                [],
                ['binding' => true, 'plan' => true, 'collection' => false, 'analysis' => false, 'profile' => false]
            ) + $common + $this->runState($run);
            return $this->persistHotelState($stored, $state, $actorId);
        }
        if (!$this->trustedRun($run, $planId)) {
            $failure = $this->safeCode((string)($run['failure_code'] ?? ''))
                ?: ($runStatus === 'succeeded'
                    ? 'hotel_lifecycle_first_collection_unverified'
                    : 'hotel_lifecycle_first_collection_failed');
            $loginBlocked = $this->containsLoginIssue([$failure]);
            $state = $this->state(
                $hotel,
                $loginBlocked ? 'awaiting_login' : 'blocked',
                'first_trusted_collection',
                'ready',
                $failure,
                [$failure],
                ['binding' => true, 'plan' => true, 'collection' => false, 'analysis' => false, 'profile' => false]
            ) + $common + $this->runState($run);
            return $this->persistHotelState($stored, $state, $actorId);
        }

        $runState = $this->runState($run);
        $runState['first_trusted_business_date'] = $runState['last_business_date'];
        $date = (string)$runState['last_business_date'];
        $dispatcherRunId = (string)($runState['last_dispatcher_run_id'] ?? '');
        $qualityReady = false;
        $qualityStatus = 'unavailable';
        $qualityDigest = null;
        $quality = [];
        $qualityFailure = null;
        try {
            if ($dispatcherRunId === '') {
                throw new RuntimeException('hotel_lifecycle_data_quality_scope_invalid');
            }
            $quality = ($this->qualityJudgmentProvisioner)(
                $dispatcherRunId,
                $tenantId,
                $hotelId,
                $date
            );
            $conclusion = is_array($quality['conclusion'] ?? null) ? $quality['conclusion'] : [];
            $evidenceScope = is_array($quality['evidence_scope'] ?? null) ? $quality['evidence_scope'] : [];
            $qualityStatus = $this->safeCode((string)($conclusion['status'] ?? 'unavailable')) ?: 'unavailable';
            $qualityDigestCandidate = strtolower(trim((string)($quality['judgment_digest'] ?? '')));
            $qualityDigest = preg_match('/^[a-f0-9]{64}$/D', $qualityDigestCandidate) === 1
                ? $qualityDigestCandidate
                : null;
            $qualityReady = ($quality['readback_verified'] ?? false) === true
                && $qualityStatus === 'available'
                && ($conclusion['claim_allowed'] ?? false) === true
                && ($conclusion['whole_hotel_conclusion_allowed'] ?? true) === false
                && ($conclusion['business_outcome_claimed'] ?? true) === false
                && (int)($quality['tenant_id'] ?? 0) === $tenantId
                && (int)($quality['system_hotel_id'] ?? 0) === $hotelId
                && (string)($quality['business_date'] ?? '') === $date
                && (string)($quality['dispatcher_run_id'] ?? '') === $dispatcherRunId
                && (string)($evidenceScope['ota_metric_scope'] ?? '') === 'ota_channel'
                && (string)($evidenceScope['pms_metric_scope'] ?? '') === 'whole_hotel_accommodation'
                && $qualityDigest !== null;
            if (!$qualityReady) {
                $qualityFailure = $this->qualityFailureCode($qualityStatus);
            }
        } catch (Throwable $error) {
            $qualityFailure = $this->safeCode($error->getMessage())
                ?: 'hotel_lifecycle_data_quality_readback_failed';
        }
        if (!$qualityReady) {
            $failure = $qualityFailure ?: 'hotel_lifecycle_data_quality_readback_failed';
            $state = $this->state(
                $hotel,
                'awaiting_analysis',
                'analysis_readback',
                'ready',
                $failure,
                [$failure],
                ['binding' => true, 'plan' => true, 'collection' => true, 'analysis' => false, 'profile' => false]
            ) + $common + $runState;
            $state['analysis_status'] = 'not_started';
            $state['profile_draft_status'] = 'not_started';
            $state['approval_task_status'] = 'not_ready';
            $state['data_quality_status'] = $qualityStatus;
            $state['data_quality_digest'] = $qualityDigest;
            return $this->persistHotelState($stored, $state, $actorId);
        }

        $analysisReady = false;
        $analysisStatus = 'blocked';
        $analysisDigest = null;
        $approvalTaskStatus = 'not_ready';
        $approvalIntentId = null;
        $approvalReadbackVerified = false;
        $approvalRequired = false;
        $approvalReady = true;
        $analysisFailure = null;
        $analysis = [];
        $waitingStage = '';
        try {
            $analysis = ($this->operatingLoopReconciler)($tenantId, $hotelId, $date, $ownerId);
            $analysisDigest = $this->digest($this->safeAnalysisSummary($analysis));
            $waiting = is_array($analysis['waiting'] ?? null) ? $analysis['waiting'] : [];
            $waitingStage = $this->safeCode((string)($waiting['stage'] ?? ''));
            $analysisReady = (string)($analysis['persistence_status'] ?? '') === 'readback_verified'
                && ($waitingStage === '' || in_array($waitingStage, [
                    'recommendation_human_decision',
                    'real_execution_receipt',
                    'comparable_outcome_readback',
                    'review_experience_promotion',
                ], true));
            $analysisStatus = $analysisReady ? 'readback_verified' : 'waiting';
            $approvalRequired = $analysisReady && $waitingStage === 'recommendation_human_decision';
            $approvalTaskStatus = $analysisReady && !$approvalRequired
                ? 'available_or_completed'
                : 'not_ready';
            if (!$analysisReady) {
                $analysisFailure = $this->safeCode((string)($waiting['code'] ?? '')) ?: 'hotel_lifecycle_analysis_blocked';
            }
        } catch (Throwable $error) {
            $analysisFailure = $this->safeCode($error->getMessage()) ?: 'hotel_lifecycle_analysis_blocked';
        }

        if ($approvalRequired) {
            try {
                $approval = ($this->approvalIntentProvisioner)(
                    $tenantId,
                    $hotelId,
                    $date,
                    $actorId,
                    $this->approvalEvidenceRefs($run, $quality, $analysis, $date)
                );
                $intent = is_array($approval['execution_intent'] ?? null)
                    ? $approval['execution_intent']
                    : [];
                $approvalIntentId = (int)($intent['id'] ?? 0) ?: null;
                $approvalReadbackVerified = (string)($approval['status'] ?? '') === 'pending_approval'
                    && (string)($approval['persistence_status'] ?? '') === 'readback_verified'
                    && ($approval['execution_task_created'] ?? true) === false
                    && ($approval['external_action_triggered'] ?? true) === false
                    && $approvalIntentId !== null
                    && (int)($intent['tenant_id'] ?? 0) === $tenantId
                    && (int)($intent['hotel_id'] ?? 0) === $hotelId
                    && (string)($intent['date_start'] ?? '') === $date
                    && (string)($intent['date_end'] ?? '') === $date
                    && (string)($intent['status'] ?? '') === 'pending_approval'
                    && is_array($intent['tasks'] ?? null)
                    && $intent['tasks'] === [];
                $approvalReady = $approvalReadbackVerified;
                $approvalTaskStatus = $approvalReady ? 'pending_human_approval' : 'readback_failed';
                if (!$approvalReady) {
                    $analysisFailure = 'hotel_lifecycle_approval_intent_readback_failed';
                }
            } catch (Throwable) {
                $approvalReady = false;
                $approvalTaskStatus = 'readback_failed';
                $analysisFailure = 'hotel_lifecycle_approval_intent_readback_failed';
            }
        }
        $workflowReady = $analysisReady && (!$approvalRequired || $approvalReady);

        $profileReady = false;
        $profileStatus = 'unavailable';
        $profileDigest = null;
        $profileSummary = [];
        try {
            if (!$workflowReady) {
                throw new RuntimeException('hotel_lifecycle_profile_waiting_for_analysis_handoff');
            }
            $preview = ($this->profilePreviewer)($tenantId, $hotelId, [$hotelId]);
            $profileStatus = $this->safeCode((string)($preview['preview_status'] ?? 'unavailable')) ?: 'unavailable';
            $profileDigest = strtolower(trim((string)($preview['preview_digest'] ?? '')));
            $profileSummary = $this->safeProfileSummary(
                is_array($preview['summary'] ?? null) ? $preview['summary'] : []
            );
            $draft = is_array($preview['draft'] ?? null) ? $preview['draft'] : [];
            $profileReady = ($preview['preview_only'] ?? false) === true
                && ($preview['automatic_verification'] ?? true) === false
                && (string)($preview['persistence_status'] ?? '') === 'not_persisted'
                && in_array($profileStatus, ['ready', 'partial'], true)
                && (int)($profileSummary['filled_dimension_count'] ?? 0) > 0
                && (int)($draft['hotel_id'] ?? 0) === $hotelId
                && (string)($draft['quality_status'] ?? '') === 'unverified'
                && preg_match('/^[a-f0-9]{64}$/D', $profileDigest) === 1;
        } catch (Throwable) {
            $profileReady = false;
        }

        $status = !$workflowReady
            ? 'awaiting_analysis'
            : ($profileReady ? 'continuous_running' : 'awaiting_profile');
        $failure = !$workflowReady
            ? ($analysisFailure ?: 'hotel_lifecycle_analysis_blocked')
            : ($profileReady ? null : 'hotel_lifecycle_profile_draft_unavailable');
        $state = $this->state(
            $hotel,
            $status,
            $workflowReady ? 'profile_draft' : 'analysis_readback',
            'ready',
            $failure,
            $failure !== null ? [$failure] : [],
            [
                'binding' => true,
                'plan' => true,
                'collection' => true,
                'analysis' => $workflowReady,
                'profile' => $profileReady,
            ]
        ) + $common + $runState;
        $state['analysis_status'] = $analysisStatus;
        $state['analysis_digest'] = $analysisDigest;
        $state['profile_draft_status'] = $profileReady
            ? $profileStatus
            : ($profileStatus === 'unavailable' ? 'unavailable' : 'invalid_readback');
        $state['profile_draft_digest'] = $profileReady ? $profileDigest : null;
        $state['profile_summary'] = $profileSummary;
        $state['approval_task_status'] = $approvalTaskStatus;
        $state['approval_intent_id'] = $approvalIntentId;
        $state['approval_readback_verified'] = $approvalReadbackVerified;
        $state['data_quality_status'] = $qualityStatus;
        $state['data_quality_digest'] = $qualityDigest;
        return $this->persistHotelState($stored, $state, $actorId);
    }

    private function qualityFailureCode(string $status): string
    {
        return match ($status) {
            'missing' => 'hotel_lifecycle_data_quality_missing',
            'partial' => 'hotel_lifecycle_data_quality_partial',
            'conflicted' => 'hotel_lifecycle_data_quality_conflicted',
            'stale' => 'hotel_lifecycle_data_quality_stale',
            default => 'hotel_lifecycle_data_quality_readback_failed',
        };
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $quality
     * @param array<string,mixed> $analysis
     * @return array<int,array<string,mixed>>
     */
    private function approvalEvidenceRefs(array $run, array $quality, array $analysis, string $date): array
    {
        $runReceiptId = (int)($quality['collection_run_receipt_id'] ?? $run['id'] ?? 0);
        $qualityJudgmentId = (int)($quality['persistence']['judgment_id'] ?? 0);
        $operatingLoop = is_array($analysis['operating_loop'] ?? null) ? $analysis['operating_loop'] : [];
        $cycleId = (int)($operatingLoop['record_id'] ?? 0);
        if ($runReceiptId <= 0 || $qualityJudgmentId <= 0 || $cycleId <= 0) {
            throw new RuntimeException('hotel_lifecycle_approval_evidence_readback_missing');
        }

        return [
            [
                'role' => 'collection_receipt',
                'source_kind' => 'formal_record',
                'table' => HotelCollectionRunReceiptService::RUN_TABLE,
                'row_ids' => [$runReceiptId],
                'business_date' => $date,
                'fact_scope' => 'exact_collection_receipt',
                'readback_verified' => true,
            ],
            [
                'role' => 'quality_judgment',
                'source_kind' => 'formal_record',
                'table' => HotelCollectionQualityJudgmentService::TABLE,
                'row_ids' => [$qualityJudgmentId],
                'business_date' => $date,
                'fact_scope' => 'collection_quality_judgment',
                'readback_verified' => true,
            ],
            [
                'role' => 'operating_cycle',
                'source_kind' => 'formal_record',
                'table' => OperatingLoopKernelService::RECORD_TABLE,
                'row_ids' => [$cycleId],
                'business_date' => $date,
                'fact_scope' => 'operating_analysis_readback',
                'readback_verified' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,int> */
    private function designatedSourceIds(array $plan): array
    {
        $planHash = strtolower(trim((string)($plan['plan_hash'] ?? '')));
        if ((string)($plan['plan_status'] ?? '') !== 'active'
            || ($plan['enabled'] ?? false) !== true
            || ($plan['active_slot'] ?? false) !== true
            || ($plan['readback_verified'] ?? false) !== true
            || preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1
        ) {
            return [];
        }

        $sources = is_array($plan['sources'] ?? null) ? $plan['sources'] : [];
        $ctripSourceId = (int)($sources['ctrip']['data_source_id'] ?? 0);
        $meituanSourceId = (int)($sources['meituan']['data_source_id'] ?? 0);
        if ($ctripSourceId <= 0 || $meituanSourceId <= 0 || $ctripSourceId === $meituanSourceId) {
            return [];
        }

        return [
            'ctrip' => $ctripSourceId,
            'meituan' => $meituanSourceId,
        ];
    }

    /**
     * Backfills missing lifecycle rows and advances a bounded enabled-hotel set.
     * Per-hotel failures remain in the returned result and do not stop peers.
     *
     * @return array<string,mixed>
     */
    public function reconcileDue(int $limit = 50, bool $provisionDispatchers = true, int $afterHotelId = 0): array
    {
        $this->assertTablesReady();
        $limit = max(1, min(500, $limit));
        $afterHotelId = max(0, $afterHotelId);
        $hotels = Db::name('hotels')
            ->field('id,tenant_id,name,status,ota_channel_strategy,owner_user_id,created_by')
            ->where('id', '>', $afterHotelId)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $results = [];
        $failureCount = 0;
        foreach ($hotels as $hotel) {
            if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
                continue;
            }
            $actorId = $this->hotelActor($hotel);
            try {
                $results[] = $this->reconcileHotel($hotel, $actorId, $provisionDispatchers);
            } catch (Throwable $error) {
                $failureCount++;
                $results[] = [
                    'hotel_id' => (int)($hotel['id'] ?? 0),
                    'tenant_id' => (int)($hotel['tenant_id'] ?? 0),
                    'status' => 'blocked',
                    'failure_code' => $this->safeCode($error->getMessage()) ?: 'hotel_lifecycle_reconcile_failed',
                    'external_action_triggered' => false,
                    'auto_write_ota' => false,
                ];
            }
        }
        return [
            'status' => $failureCount === 0 ? 'completed' : 'partial',
            'hotel_count' => count($results),
            'scanned_hotel_count' => count($hotels),
            'failure_count' => $failureCount,
            'next_after_hotel_id' => $hotels === []
                ? $afterHotelId
                : max(array_map(static fn(array $hotel): int => (int)$hotel['id'], $hotels)),
            'provision_dispatchers' => $provisionDispatchers,
            'external_ota_write_enabled' => false,
            'results' => $results,
        ];
    }

    /** @param array<int,int> $hotelIds @return array<int,array<string,mixed>> */
    public function readForHotels(int $tenantId, array $hotelIds): array
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('hotel_automation_lifecycle_tenant_invalid');
        }
        $this->assertTablesReady();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($hotelIds === []) {
            return [];
        }
        $rows = Db::name(self::HOTEL_TABLE)
            ->where('tenant_id', $tenantId)
            ->whereIn('system_hotel_id', $hotelIds)
            ->order('system_hotel_id', 'asc')
            ->select()
            ->toArray();
        $byHotel = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $byHotel[(int)$row['system_hotel_id']] = $this->publicHotelState($row, true);
            }
        }
        $result = [];
        foreach ($hotelIds as $hotelId) {
            $result[] = $byHotel[$hotelId] ?? $this->missingPublicState($tenantId, $hotelId);
        }
        return $result;
    }

    /** @param array<string,mixed> $stored @param array<string,mixed> $state @return array<string,mixed> */
    private function persistHotelState(array $stored, array $state, int $actorId): array
    {
        $version = max(1, (int)($stored['state_version'] ?? 0));
        $digest = $this->digest($this->digestState($state));
        if (preg_match('/^[a-f0-9]{64}$/D', (string)($stored['state_digest'] ?? '')) === 1
            && hash_equals((string)$stored['state_digest'], $digest)
        ) {
            return $this->publicHotelState($stored, true);
        }
        $version++;
        $row = $this->rowFromState($state, max(0, $actorId), $version, false);
        $updated = Db::name(self::HOTEL_TABLE)
            ->where('id', (int)$stored['id'])
            ->where('tenant_id', (int)$stored['tenant_id'])
            ->where('system_hotel_id', (int)$stored['system_hotel_id'])
            ->where('state_version', (int)$stored['state_version'])
            ->update($row);
        if ($updated !== 1) {
            throw new RuntimeException('hotel_automation_lifecycle_concurrent_update');
        }
        $readback = Db::name(self::HOTEL_TABLE)
            ->where('id', (int)$stored['id'])
            ->where('tenant_id', (int)$stored['tenant_id'])
            ->where('system_hotel_id', (int)$stored['system_hotel_id'])
            ->find();
        if (!is_array($readback)
            || (int)($readback['state_version'] ?? 0) !== $version
            || !hash_equals((string)$row['state_digest'], (string)($readback['state_digest'] ?? ''))
        ) {
            throw new RuntimeException('hotel_automation_lifecycle_readback_failed');
        }
        return $this->publicHotelState($readback, false);
    }

    /**
     * Persist the one-time start request before invoking the dispatcher. If a
     * process dies after the external call, later reconciliation sees the
     * durable request and must not issue start_now again.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $claimFields
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function claimFirstDispatch(array $stored, int $actorId, array $claimFields): array
    {
        if (trim((string)($stored['first_dispatch_requested_at'] ?? '')) !== '') {
            return [$stored, false];
        }
        $state = $this->decode($stored['safe_state_json'] ?? null);
        if ($state === []) {
            throw new RuntimeException('hotel_automation_lifecycle_state_invalid');
        }
        $state = array_replace($state, $claimFields, [
            'dispatcher_status' => 'dispatch_requested',
            'first_dispatch_requested_at' => $this->now()->format('Y-m-d H:i:s'),
        ]);

        try {
            $this->persistHotelState($stored, $state, $actorId);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'hotel_automation_lifecycle_concurrent_update') {
                throw $error;
            }
            $current = $this->hotelStateRow((int)$stored['tenant_id'], (int)$stored['system_hotel_id']);
            if (trim((string)($current['first_dispatch_requested_at'] ?? '')) === '') {
                throw $error;
            }
            return [$current, false];
        }

        return [
            $this->hotelStateRow((int)$stored['tenant_id'], (int)$stored['system_hotel_id']),
            true,
        ];
    }

    /** @return array<string,mixed> */
    private function hotelStateRow(int $tenantId, int $hotelId): array
    {
        $row = Db::name(self::HOTEL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('hotel_automation_lifecycle_readback_failed');
        }

        return $row;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function rowFromState(array $state, int $actorId, int $version, bool $creating): array
    {
        $now = $this->now()->format('Y-m-d H:i:s');
        $safeState = $this->publicStatePayload($state);
        $row = [
            'tenant_id' => (int)$state['tenant_id'],
            'system_hotel_id' => (int)$state['hotel_id'],
            'status' => (string)$state['status'],
            'current_stage' => (string)$state['current_stage'],
            'ota_channel_strategy' => (string)$state['ota_channel_strategy'],
            'completed_stage_count' => (int)$state['completed_stage_count'],
            'total_stage_count' => self::TOTAL_STAGE_COUNT,
            'binding_status' => (string)($state['binding_status'] ?? 'missing'),
            'binding_digest' => $this->digestOrNull($state['binding_digest'] ?? null),
            'active_plan_id' => (int)($state['active_plan_id'] ?? 0) ?: null,
            'active_plan_hash' => $this->digestOrNull($state['active_plan_hash'] ?? null),
            'dispatcher_status' => $this->safeCode((string)($state['dispatcher_status'] ?? 'not_provisioned')) ?: 'not_provisioned',
            'dispatcher_task_name' => $this->nullableText((string)($state['dispatcher_task_name'] ?? ''), 191),
            'first_dispatch_requested_at' => $this->dateTimeOrNull($state['first_dispatch_requested_at'] ?? null),
            'first_trusted_business_date' => $this->dateOrNull((string)($state['first_trusted_business_date'] ?? '')),
            'last_business_date' => $this->dateOrNull((string)($state['last_business_date'] ?? '')),
            'last_dispatcher_run_id' => $this->uuidOrNull((string)($state['last_dispatcher_run_id'] ?? '')),
            'last_run_status' => $this->nullableCode((string)($state['last_run_status'] ?? '')),
            'analysis_status' => $this->safeCode((string)($state['analysis_status'] ?? 'not_started')) ?: 'not_started',
            'analysis_digest' => $this->digestOrNull($state['analysis_digest'] ?? null),
            'profile_draft_status' => $this->safeCode((string)($state['profile_draft_status'] ?? 'not_started')) ?: 'not_started',
            'profile_draft_digest' => $this->digestOrNull($state['profile_draft_digest'] ?? null),
            'failure_code' => $this->nullableCode((string)($state['failure_code'] ?? '')),
            'upstream_failure_code' => $this->nullableCode((string)($state['upstream_failure_code'] ?? '')),
            'retryable' => ($state['retryable'] ?? false) === true ? 1 : 0,
            'attempt_count' => max(0, (int)($state['attempt_count'] ?? 0)),
            'next_retry_at' => $this->dateTimeOrNull($state['next_retry_at'] ?? null),
            'state_version' => $version,
            'state_digest' => $this->digest($this->digestState($state)),
            'safe_state_json' => $this->encode($safeState),
            'updated_by' => $actorId,
            'update_time' => $now,
        ];
        if (!$creating) {
            unset($row['tenant_id'], $row['system_hotel_id']);
        } else {
            $row['created_by'] = $actorId;
            $row['create_time'] = $now;
        }
        return $row;
    }

    /** @param array<string,mixed> $hotel @return array<string,mixed> */
    private function initialState(array $hotel): array
    {
        return $this->state(
            $hotel,
            (int)($hotel['status'] ?? 0) === 1 ? 'awaiting_binding' : 'disabled',
            'data_source_binding',
            'missing',
            null,
            [],
            ['binding' => false, 'plan' => false, 'collection' => false, 'analysis' => false, 'profile' => false]
        );
    }

    /** @param array<string,mixed> $hotel @param array<string,bool> $complete @return array<string,mixed> */
    private function state(
        array $hotel,
        string $status,
        string $currentStage,
        string $bindingStatus,
        ?string $failureCode,
        array $blockers,
        array $complete
    ): array {
        [$tenantId, $hotelId] = $this->scope($hotel);
        $complete = array_replace([
            'binding' => false,
            'plan' => false,
            'collection' => false,
            'analysis' => false,
            'profile' => false,
        ], $complete);
        $stages = [
            ['code' => 'identity_recorded', 'status' => 'complete'],
            ['code' => 'data_source_binding', 'status' => $complete['binding'] ? 'complete' : ($currentStage === 'data_source_binding' && $failureCode !== null ? 'blocked' : 'pending')],
            ['code' => 'collection_plan', 'status' => $complete['plan'] ? 'complete' : ($currentStage === 'collection_plan' && $failureCode !== null ? 'blocked' : 'pending')],
            ['code' => 'first_trusted_collection', 'status' => $complete['collection'] ? 'complete' : ($currentStage === 'first_trusted_collection' && $failureCode !== null ? 'blocked' : 'pending')],
            ['code' => 'analysis_readback', 'status' => $complete['analysis'] ? 'complete' : ($currentStage === 'analysis_readback' && $failureCode !== null ? 'blocked' : 'pending')],
            ['code' => 'profile_draft', 'status' => $complete['profile'] ? 'complete' : 'pending'],
        ];
        $completedCount = count(array_filter($stages, static fn(array $stage): bool => $stage['status'] === 'complete'));
        [$nextActionCode, $nextActionLabel] = $this->nextAction($status, $currentStage);
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'status' => $status,
            'current_stage' => $currentStage,
            'ota_channel_strategy' => $this->strategy((string)($hotel['ota_channel_strategy'] ?? 'none')),
            'completed_stage_count' => $completedCount,
            'total_stage_count' => self::TOTAL_STAGE_COUNT,
            'stages' => $stages,
            'binding_status' => $bindingStatus,
            'failure_code' => $failureCode,
            'upstream_failure_code' => $failureCode,
            'blockers' => array_values(array_unique(array_filter(array_map(fn(mixed $code): string => $this->safeCode((string)$code), $blockers)))),
            'next_action_code' => $nextActionCode,
            'next_action_label' => $nextActionLabel,
            'retryable' => false,
            'attempt_count' => 0,
            'boundaries' => [
                'continuous_running_is_business_success' => false,
                'profile_verified' => false,
                'business_outcome_claimed' => false,
                'external_action_triggered' => false,
                'auto_write_ota' => false,
                'credentials_stored_in_lifecycle' => false,
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function nextAction(string $status, string $stage): array
    {
        return match ($status) {
            'awaiting_binding' => ['open_hotel_binding', '补充并核验数据源绑定'],
            'awaiting_login' => ['open_hotel_login', '在原设备完成一次登录授权'],
            'awaiting_profile' => ['provide_business_profile', '补充经营基础资料'],
            'continuous_running' => ['open_profile_draft', '查看待核验经营画像'],
            'disabled' => ['', '门店已停用'],
            default => $stage === 'profile_draft'
                ? ['open_profile_draft', '查看待核验经营画像']
                : ['open_automation_monitor', '查看自动运行详情'],
        };
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    private function publicHotelState(array $stored, bool $reused): array
    {
        $safe = $this->decode($stored['safe_state_json'] ?? null);
        if ($safe === []) {
            throw new RuntimeException('hotel_automation_lifecycle_state_invalid');
        }
        $digest = $this->digest($this->digestState($safe));
        if (!hash_equals((string)($stored['state_digest'] ?? ''), $digest)) {
            throw new RuntimeException('hotel_automation_lifecycle_state_digest_mismatch');
        }
        $public = $this->externalStatePayload($safe);
        $public['state_version'] = (int)($stored['state_version'] ?? 0);
        $public['readback_verified'] = true;
        $public['reused'] = $reused;
        $public['updated_at'] = $this->dateTimeOrNull($stored['update_time'] ?? null);
        return $public;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function tenantReadback(array $row, bool $reused): array
    {
        $safe = $this->decode($row['safe_state_json'] ?? null);
        if ($safe === [] || !hash_equals((string)($row['state_digest'] ?? ''), $this->digest($safe))) {
            throw new RuntimeException('tenant_automation_lifecycle_state_digest_mismatch');
        }
        $safe['state_version'] = (int)($row['state_version'] ?? 0);
        $safe['readback_verified'] = true;
        $safe['reused'] = $reused;
        return $safe;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function publicStatePayload(array $state): array
    {
        $allowed = [
            'schema_version', 'tenant_id', 'hotel_id', 'status', 'current_stage',
            'ota_channel_strategy', 'completed_stage_count', 'total_stage_count',
            'stages', 'binding_status', 'dispatcher_status', 'last_business_date',
            'last_run_status', 'analysis_status', 'profile_draft_status',
            'profile_summary', 'approval_task_status', 'approval_intent_id',
            'approval_readback_verified', 'data_quality_status', 'data_quality_digest',
            'failure_code', 'upstream_failure_code', 'blockers', 'next_action_code',
            'next_action_label', 'boundaries', 'binding_digest', 'active_plan_id',
            'active_plan_hash', 'dispatcher_task_name', 'first_dispatch_requested_at',
            'first_trusted_business_date', 'last_dispatcher_run_id',
            'analysis_digest', 'profile_draft_digest', 'analysis_authorization_status',
            'analysis_authorization_digest', 'retryable', 'attempt_count', 'next_retry_at',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $state)) {
                $safe[$key] = $state[$key];
            }
        }
        return $safe;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function externalStatePayload(array $state): array
    {
        $allowed = [
            'schema_version', 'tenant_id', 'hotel_id', 'status', 'current_stage',
            'ota_channel_strategy', 'completed_stage_count', 'total_stage_count',
            'stages', 'binding_status', 'dispatcher_status', 'last_business_date',
            'last_run_status', 'analysis_status', 'profile_draft_status',
            'profile_summary', 'approval_task_status', 'approval_intent_id',
            'approval_readback_verified', 'data_quality_status', 'data_quality_digest',
            'failure_code', 'upstream_failure_code', 'blockers', 'next_action_code',
            'next_action_label', 'boundaries',
        ];
        $public = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $state)) {
                $public[$key] = $state[$key];
            }
        }
        return $public;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function digestState(array $state): array
    {
        return $this->publicStatePayload($state);
    }

    /** @return array<string,mixed> */
    private function missingPublicState(int $tenantId, int $hotelId): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'status' => 'blocked',
            'current_stage' => 'identity_recorded',
            'completed_stage_count' => 0,
            'total_stage_count' => self::TOTAL_STAGE_COUNT,
            'stages' => [],
            'next_action_code' => 'open_automation_monitor',
            'next_action_label' => '等待后台初始化生命周期',
            'failure_code' => 'hotel_automation_lifecycle_missing',
            'blockers' => ['hotel_automation_lifecycle_missing'],
            'boundaries' => [
                'profile_verified' => false,
                'business_outcome_claimed' => false,
                'external_action_triggered' => false,
                'auto_write_ota' => false,
            ],
            'readback_verified' => false,
        ];
    }

    /** @param array<string,mixed> $stored @param array<string,mixed> $hotel @return array<string,mixed> */
    private function persistFailure(
        array $stored,
        array $hotel,
        string $stage,
        string $failureCode,
        string $upstreamCode,
        int $actorId,
        ?string $bindingDigest = null,
        string $bindingStatus = 'blocked'
    ): array {
        $state = $this->state(
            $hotel,
            $this->containsLoginIssue([$failureCode, $upstreamCode]) ? 'awaiting_login' : 'blocked',
            $stage,
            $bindingStatus,
            $failureCode,
            array_values(array_filter([$failureCode, $upstreamCode])),
            [
                'binding' => $bindingStatus === 'ready',
                'plan' => false,
                'collection' => false,
                'analysis' => false,
                'profile' => false,
            ]
        );
        $state['binding_digest'] = $bindingDigest;
        $state['upstream_failure_code'] = $upstreamCode !== '' ? $upstreamCode : $failureCode;
        return $this->persistHotelState($stored, $state, $actorId);
    }

    /** @param array<string,mixed> $plan */
    private function planMatches(array $plan, string $bindingDigest, int $ctripId, int $meituanId, string $pmsProvider): bool
    {
        if (!$this->planReady($plan)
            || !hash_equals($bindingDigest, strtolower(trim((string)($plan['binding_digest'] ?? ''))))
        ) {
            return false;
        }
        $sources = is_array($plan['sources'] ?? null) ? $plan['sources'] : [];
        return (int)($sources['ctrip']['data_source_id'] ?? 0) === $ctripId
            && (int)($sources['meituan']['data_source_id'] ?? 0) === $meituanId
            && strtolower(trim((string)($sources['pms']['provider'] ?? ''))) === $pmsProvider
            && (string)($plan['business_date_policy'] ?? '') === 'previous_business_day'
            && (string)($plan['timezone'] ?? '') === 'Asia/Shanghai'
            && (string)($plan['schedule_time'] ?? '') === '08:30'
            && (int)($plan['retry_interval_minutes'] ?? 0) === 14
            && (int)($plan['max_attempts'] ?? 0) === 7;
    }

    /** @param array<string,mixed> $plan */
    private function planReady(array $plan): bool
    {
        return (string)($plan['status'] ?? '') === 'active_ready'
            && (string)($plan['plan_status'] ?? '') === 'active'
            && ($plan['enabled'] ?? false) === true
            && ($plan['active_slot'] ?? false) === true
            && ($plan['readback_verified'] ?? false) === true
            && ($plan['binding_digest_matches'] ?? false) === true
            && ($plan['execution_authorized'] ?? false) === true
            && (int)($plan['id'] ?? 0) > 0
            && preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($plan['plan_hash'] ?? '')))) === 1;
    }

    /** @param array<string,mixed> $plan */
    private function firstPlanFailure(array $plan): string
    {
        foreach ((array)($plan['failure_reasons'] ?? []) as $reason) {
            if (is_array($reason)) {
                $code = $this->safeCode((string)($reason['code'] ?? $reason['reason_code'] ?? ''));
                if ($code !== '') {
                    return $code;
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $run */
    private function trustedRun(array $run, int $planId): bool
    {
        if ((string)($run['status'] ?? '') !== 'succeeded'
            || ($run['ledger_structure_verified'] ?? false) !== true
            || ($run['readback_verified'] ?? false) !== true
            || (int)($run['plan_id'] ?? 0) !== $planId
            || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($run['collection_anchor_hash'] ?? '')))) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($run['trust_receipt_digest'] ?? '')))) !== 1
            || trim((string)($run['finished_at'] ?? '')) === ''
        ) {
            return false;
        }
        $pms = is_array($run['pms_receipt'] ?? null) ? $run['pms_receipt'] : [];
        if ((string)($pms['status'] ?? '') !== 'verified' || ($pms['readback_verified'] ?? false) !== true) {
            return false;
        }
        $sources = is_array($run['source_receipts'] ?? null) ? $run['source_receipts'] : [];
        if (count($sources) !== 2) {
            return false;
        }
        $platforms = [];
        foreach ($sources as $source) {
            if (!is_array($source)
                || (string)($source['status'] ?? '') !== 'success'
                || ($source['readback_verified'] ?? false) !== true
                || (int)($source['saved_row_count'] ?? 0) <= 0
                || (int)($source['saved_row_count'] ?? 0) !== (int)($source['readback_row_count'] ?? -1)
            ) {
                return false;
            }
            $platforms[] = strtolower(trim((string)($source['platform'] ?? '')));
        }
        sort($platforms, SORT_STRING);
        return $platforms === ['ctrip', 'meituan'];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function runState(array $run): array
    {
        $date = $this->dateOrNull((string)($run['business_date'] ?? ''));
        return [
            'last_business_date' => $date,
            'last_dispatcher_run_id' => $this->uuidOrNull((string)($run['dispatcher_run_id'] ?? '')),
            'last_run_status' => $this->safeCode((string)($run['status'] ?? '')) ?: null,
        ];
    }

    /** @return array<string,mixed> */
    private function loadLatestRun(int $tenantId, int $hotelId, int $planId): array
    {
        try {
            $scopedQuery = static fn() => Db::name(HotelCollectionRunReceiptService::RUN_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('plan_id', $planId);
            $reader = new HotelCollectionRunReceiptService();

            $row = $scopedQuery()
                ->whereIn('status', ['succeeded', 'blocked', 'skipped', 'deferred', 'partial', 'failed'])
                ->order('id', 'desc')
                ->field('id,dispatcher_run_id,business_date,status,failure_code')
                ->find();
            if (!is_array($row)) {
                $row = $scopedQuery()
                    ->order('id', 'desc')
                    ->field('id,dispatcher_run_id,business_date,status,failure_code')
                    ->find();
            }
            if (!is_array($row)) {
                return [
                    'status' => 'missing',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'failure_code' => 'hotel_collection_run_receipt_missing',
                    'readback_verified' => false,
                ];
            }
            $latest = $reader->readGroup(
                (string)$row['dispatcher_run_id'],
                $tenantId,
                $hotelId,
                (string)$row['business_date']
            );
            $verifiedCacheReuse = (string)($latest['status'] ?? '') === 'skipped'
                && (string)($latest['failure_code'] ?? '') === 'verified_cache_reused'
                && ($latest['readback_verified'] ?? false) === true;
            if (!$verifiedCacheReuse) {
                return $latest;
            }

            $succeededRows = $scopedQuery()
                ->where('status', 'succeeded')
                ->where('business_date', (string)$row['business_date'])
                ->where('id', '<', (int)$row['id'])
                ->order('id', 'desc')
                ->field('dispatcher_run_id,business_date')
                ->select()
                ->toArray();
            foreach ($succeededRows as $succeededRow) {
                if (!is_array($succeededRow)) {
                    continue;
                }
                try {
                    $producer = $reader->readGroup(
                        (string)$succeededRow['dispatcher_run_id'],
                        $tenantId,
                        $hotelId,
                        (string)$succeededRow['business_date']
                    );
                } catch (Throwable) {
                    continue;
                }
                if ($this->trustedRun($producer, $planId)) {
                    return $producer;
                }
            }
            return $latest;
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'failure_code' => 'hotel_collection_run_receipt_store_unavailable',
                'readback_verified' => false,
            ];
        }
    }

    /** @return array{status:string,digest:?string} */
    private function authorizeAnalysis(int $tenantId, int $hotelId, string $planHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $planHash)) {
            return ['status' => 'blocked', 'digest' => null];
        }
        $digests = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            try {
                $receipt = ($this->analysisAuthorizationProvisioner)(
                    $tenantId,
                    $hotelId,
                    $platform,
                    'hotel-lifecycle-' . $tenantId . '-' . $hotelId . '-' . substr($planHash, 0, 20)
                );
            } catch (Throwable) {
                return ['status' => 'blocked', 'digest' => null];
            }
            if (($receipt['readback_verified'] ?? false) !== true
                || ($receipt['analysis_only'] ?? false) !== true
                || ($receipt['external_action_allowed'] ?? true) !== false
            ) {
                return ['status' => 'blocked', 'digest' => null];
            }
            $digest = strtolower(trim((string)($receipt['authorization_digest'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                return ['status' => 'blocked', 'digest' => null];
            }
            $digests[] = $digest;
        }
        return ['status' => 'readback_verified', 'digest' => $this->digest($digests)];
    }

    /** @return array<string,mixed> */
    private function provisionAnalysisAuthorization(int $tenantId, int $hotelId, string $platform, string $planId): array
    {
        $store = new OnlineDataAutoFetchStatusStore();
        $store->mutate($hotelId, static function (array $status) use ($tenantId, $hotelId): array {
            $status['lifecycle_managed_analysis_enabled'] = true;
            $status['lifecycle_managed_tenant_id'] = $tenantId;
            $status['lifecycle_managed_hotel_id'] = $hotelId;
            $status['lifecycle_external_action_allowed'] = false;
            return $status;
        });
        return (new CanonicalOtaScheduledAnalysisAuthorizationProvisioningService())
            ->execute($tenantId, $hotelId, $platform, $planId);
    }

    /** @param array<string,mixed> $binding @return array<int,string> */
    private function issueCodes(array $binding): array
    {
        $codes = [];
        foreach (['blockers', 'recovery_reasons'] as $key) {
            foreach ((array)($binding[$key] ?? []) as $issue) {
                $code = is_array($issue)
                    ? $this->safeCode((string)($issue['code'] ?? $issue['reason_code'] ?? ''))
                    : $this->safeCode((string)$issue);
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }
        return array_keys($codes);
    }

    /** @param array<int,string> $codes */
    private function containsLoginIssue(array $codes): bool
    {
        foreach ($codes as $code) {
            $code = strtolower((string)$code);
            if (str_contains($code, 'source_binding_missing')
                || str_contains($code, 'source_binding_conflict')
                || str_contains($code, 'pms_binding_missing')
                || str_contains($code, 'pms_binding_conflict')
                || str_contains($code, 'platform_hotel_id')
                || str_contains($code, 'identity_missing')
                || str_contains($code, 'owner_permission_unverified')
            ) {
                return false;
            }
        }
        foreach ($codes as $code) {
            $code = strtolower((string)$code);
            if (str_contains($code, 'login')
                || str_contains($code, 'reauth')
                || str_contains($code, 'session')
                || str_contains($code, 'profile')
                || str_contains($code, 'device_offline')
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function safeAnalysisSummary(array $analysis): array
    {
        $waiting = is_array($analysis['waiting'] ?? null) ? $analysis['waiting'] : [];
        return [
            'persistence_status' => $this->safeCode((string)($analysis['persistence_status'] ?? '')),
            'reconciled_stages' => array_values(array_filter(array_map(
                fn(mixed $stage): string => $this->safeCode((string)$stage),
                (array)($analysis['reconciled_stages'] ?? [])
            ))),
            'waiting_stage' => $this->safeCode((string)($waiting['stage'] ?? '')),
            'waiting_code' => $this->safeCode((string)($waiting['code'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function safeProfileSummary(array $summary): array
    {
        return [
            'filled_dimension_count' => max(0, (int)($summary['filled_dimension_count'] ?? 0)),
            'missing_dimension_count' => max(0, (int)($summary['missing_dimension_count'] ?? 0)),
            'confirmation_gap_count' => max(0, (int)($summary['confirmation_gap_count'] ?? 0)),
            'active_binding_count' => max(0, (int)($summary['active_binding_count'] ?? 0)),
            'verified_fact_count' => max(0, (int)($summary['verified_fact_count'] ?? 0)),
            'verified_business_date_end' => $this->dateOrNull((string)($summary['verified_business_date_end'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $hotel */
    private function hotelActor(array $hotel): int
    {
        foreach (['owner_user_id', 'created_by'] as $key) {
            $candidate = (int)($hotel[$key] ?? 0);
            if ($candidate > 0) {
                return $candidate;
            }
        }
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $hotelId = (int)($hotel['id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0) {
            return 0;
        }
        try {
            $rows = Db::name('user_hotel_permissions')->alias('permission')
                ->where('permission.tenant_id', $tenantId)
                ->where('permission.hotel_id', $hotelId)
                ->where('permission.status', 'active')
                ->where('permission.can_view', 1)
                ->where(function ($query): void {
                    $query->where('permission.can_fetch_online_data', 1)
                        ->whereOr('permission.can_fetch_ota', 1);
                })
                ->where(function ($query): void {
                    $query->whereNull('permission.expires_at')
                        ->whereOr('permission.expires_at', '>', $this->now()->format('Y-m-d H:i:s'));
                })
                ->distinct(true)
                ->field('permission.user_id')
                ->order('permission.user_id', 'asc')
                ->select()
                ->toArray();
            $sameTenantUsers = [];
            $superAdminUsers = [];
            foreach ($rows as $row) {
                $userId = (int)($row['user_id'] ?? 0);
                $user = $userId > 0 ? User::find($userId) : null;
                if (!$user instanceof User || (int)($user->status ?? 0) !== 1) {
                    continue;
                }
                if ((int)($user->tenant_id ?? 0) === $tenantId) {
                    $sameTenantUsers[$userId] = true;
                } elseif ($user->isSuperAdmin()) {
                    $superAdminUsers[$userId] = true;
                }
            }
            if (count($sameTenantUsers) === 1) {
                return (int)array_key_first($sameTenantUsers);
            }
            if (count($sameTenantUsers) > 1) {
                return 0;
            }
            if (count($superAdminUsers) === 1) {
                return (int)array_key_first($superAdminUsers);
            }
            if (count($superAdminUsers) > 1) {
                return 0;
            }
        } catch (Throwable) {
            // Older schemas can lack the granular permission columns.
        }

        try {
            $tenantUsers = Db::name('users')
                ->where('tenant_id', $tenantId)
                ->where('status', 1)
                ->field('id')
                ->order('id', 'asc')
                ->limit(2)
                ->select()
                ->toArray();
            return count($tenantUsers) === 1 ? (int)($tenantUsers[0]['id'] ?? 0) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function assertTablesReady(): void
    {
        foreach ([self::TENANT_TABLE, self::HOTEL_TABLE] as $table) {
            try {
                Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable $error) {
                throw new RuntimeException('hotel_automation_lifecycle_tables_missing', 0, $error);
            }
        }
    }

    /** @param array<string,mixed>|object $hotel @return array<string,mixed> */
    private function hotelArray(array|object $hotel): array
    {
        if (is_array($hotel)) {
            return $hotel;
        }
        if (method_exists($hotel, 'toArray')) {
            $value = $hotel->toArray();
            if (is_array($value)) {
                return $value;
            }
        }
        return get_object_vars($hotel);
    }

    /** @param array<string,mixed> $hotel @return array{0:int,1:int} */
    private function scope(array $hotel): array
    {
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $hotelId = (int)($hotel['id'] ?? $hotel['system_hotel_id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('hotel_automation_lifecycle_scope_invalid');
        }
        return [$tenantId, $hotelId];
    }

    private function strategy(string $strategy): string
    {
        $strategy = strtolower(trim($strategy));
        return in_array($strategy, ['none', 'ctrip_only', 'dual', 'meituan_only'], true)
            ? $strategy
            : 'none';
    }

    private function previousBusinessDate(): string
    {
        return $this->now()->modify('-1 day')->format('Y-m-d');
    }

    private function now(): DateTimeImmutable
    {
        $value = ($this->clock)();
        if (!$value instanceof DateTimeImmutable) {
            throw new RuntimeException('hotel_automation_lifecycle_clock_invalid');
        }
        return $value->setTimezone(new DateTimeZone('Asia/Shanghai'));
    }

    private function digestOrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    private function uuidOrNull(string $value): ?string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1
            ? $value
            : null;
    }

    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strtotime($value) === false) {
            return null;
        }
        return date('Y-m-d H:i:s', (int)strtotime($value));
    }

    private function nullableCode(string $value): ?string
    {
        $value = $this->safeCode($value);
        return $value !== '' ? $value : null;
    }

    private function nullableText(string $value, int $maxLength): ?string
    {
        $value = $this->safeText($value, $maxLength);
        return $value !== '' ? $value : null;
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $maxLength);
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->encode($this->canonicalize($value)));
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

    /** @param array<mixed> $value */
    private function encode(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
