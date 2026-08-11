<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

/**
 * Durable per-hotel collection plan with exact save/readback verification.
 *
 * The plan persists only source identities, schedules and binding digests. It
 * never persists cookies, tokens, browser Profile locations or plaintext
 * device identifiers. A saved plan authorizes execution only while its current
 * hotel binding receipt remains ready and byte-for-byte digest compatible.
 */
final class HotelCollectionPlanService
{
    private const TABLE = 'hotel_collection_plans';
    private const TIMEZONE = 'Asia/Shanghai';
    private const SOURCE_KEYS = ['ctrip', 'meituan', 'pms'];

    /** @var callable|null */
    private $bindingReceiptLoader;

    /** @var callable|null */
    private $clock;

    private ?string $signingKey;

    public function __construct(
        ?callable $bindingReceiptLoader = null,
        ?callable $clock = null,
        ?string $signingKey = null
    )
    {
        $this->bindingReceiptLoader = $bindingReceiptLoader;
        $this->clock = $clock;
        $this->signingKey = $signingKey;
    }

    /**
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(array $hotel, int $actorUserId, array $input): array
    {
        [$tenantId, $hotelId] = $this->scope($hotel, $actorUserId);
        $this->assertTableReady();
        $selection = $this->sourceSelection($input);
        $businessDate = $this->dateOrEmpty((string)($input['business_date'] ?? $input['target_date'] ?? ''));
        $binding = $this->bindingReceipt(
            $hotel,
            $actorUserId,
            $businessDate,
            [
                'ctrip' => $selection['ctrip_source_id'],
                'meituan' => $selection['meituan_source_id'],
            ]
        );
        $this->assertBindingReceiptContract($binding, $tenantId, $hotelId, $selection);
        $validationReasons = $this->bindingIssues($binding);
        $bindings = is_array($binding['bindings'] ?? null) ? $binding['bindings'] : [];
        $pms = is_array($bindings['pms'] ?? null) ? $bindings['pms'] : [];
        $pmsProviderMismatch = strtolower(trim((string)($pms['provider'] ?? ''))) !== $selection['pms_provider'];
        if ($pmsProviderMismatch) {
            $validationReasons[] = $this->issue(
                'pms_plan_provider_mismatch',
                'pms',
                'The selected PMS provider does not match the hotel PMS binding.'
            );
        }
        $validationReasons = $this->uniqueIssues($validationReasons);
        $bindingStatus = strtolower(trim((string)($binding['status'] ?? 'blocked')));
        $validationStatus = !$pmsProviderMismatch && in_array($bindingStatus, ['ready', 'recoverable'], true)
            ? $bindingStatus
            : 'blocked';

        $activate = $this->boolean($input['activate'] ?? false);
        if ($activate && (int)($hotel['status'] ?? 0) !== 1) {
            throw new RuntimeException('hotel_collection_plan_hotel_disabled');
        }
        if ($activate && $validationStatus !== 'ready') {
            throw new RuntimeException('hotel_collection_plan_binding_not_ready');
        }
        $schedule = $this->schedule($input);
        $executionOwnerUserId = $this->singleExecutionOwner($bindings);
        $sources = $this->sourcePlan($selection, $bindings);
        if ($activate && $executionOwnerUserId <= 0) {
            throw new RuntimeException('hotel_collection_plan_execution_owner_missing');
        }

        $latestVersion = (int)Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->max('plan_version');
        $planVersion = max(1, $latestVersion + 1);
        $planStatus = $activate ? 'active' : 'draft';
        $activeSlot = $activate ? 1 : null;
        $bindingDigest = strtolower(trim((string)($binding['binding_digest'] ?? '')));
        if (!$this->digest($bindingDigest)) {
            throw new RuntimeException('hotel_collection_plan_binding_digest_invalid');
        }
        $sourcePlanJson = $this->encode($sources);
        $validationReasonsJson = $this->encode($validationReasons);
        $planHash = $this->planHash([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'plan_version' => $planVersion,
            'plan_status' => $planStatus,
            'enabled' => $activate ? 1 : 0,
            'active_slot' => $activeSlot,
            'business_date_policy' => $schedule['business_date_policy'],
            'timezone' => $schedule['timezone'],
            'schedule_time' => $schedule['schedule_time'],
            'retry_interval_minutes' => $schedule['retry_interval_minutes'],
            'max_attempts' => $schedule['max_attempts'],
            'execution_owner_user_id' => $executionOwnerUserId,
            'binding_digest' => $bindingDigest,
            'source_plan_json' => $sourcePlanJson,
        ]);
        $now = $this->now();
        $values = [
            'plan_version' => $planVersion,
            'plan_status' => $planStatus,
            'enabled' => $activate ? 1 : 0,
            'active_slot' => $activeSlot,
            'business_date_policy' => $schedule['business_date_policy'],
            'timezone' => $schedule['timezone'],
            'schedule_time' => $schedule['schedule_time'],
            'retry_interval_minutes' => $schedule['retry_interval_minutes'],
            'max_attempts' => $schedule['max_attempts'],
            'execution_owner_user_id' => $executionOwnerUserId > 0 ? $executionOwnerUserId : null,
            'binding_digest' => $bindingDigest,
            'plan_hash' => $planHash,
            'source_plan_json' => $sourcePlanJson,
            'validation_status' => $validationStatus,
            'validation_reasons_json' => $validationReasonsJson,
            'activated_at' => $activate ? $now : null,
            'updated_by' => $actorUserId,
            'update_time' => $now,
        ];

        $readback = Db::transaction(function () use (
            $hotel,
            $tenantId,
            $hotelId,
            $actorUserId,
            $businessDate,
            $activate,
            $planHash,
            $values,
            $now
        ): array {
            if ($activate) {
                $currentActive = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('active_slot', 1)
                    ->find();
                if (is_array($currentActive)) {
                    $superseded = [
                        'plan_status' => 'superseded',
                        'enabled' => 0,
                        'active_slot' => null,
                        'updated_by' => $actorUserId,
                        'update_time' => $now,
                    ];
                    $superseded['plan_hash'] = $this->planHash(
                        array_replace($currentActive, $superseded)
                    );
                    $updated = Db::name(self::TABLE)
                        ->where('id', (int)$currentActive['id'])
                        ->where('tenant_id', $tenantId)
                        ->where('system_hotel_id', $hotelId)
                        ->where('active_slot', 1)
                        ->update($superseded);
                    if ($updated !== 1) {
                        throw new RuntimeException('hotel_collection_plan_active_switch_failed');
                    }
                }
            }
            $id = (int)Db::name(self::TABLE)->insertGetId($values + [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'created_by' => $actorUserId,
                'create_time' => $now,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('hotel_collection_plan_create_failed');
            }
            $verified = $this->hydrateRow(
                $hotel,
                $actorUserId,
                $businessDate,
                (array)Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('id', $id)
                    ->find()
            );
            if (($verified['readback_verified'] ?? false) !== true
                || !hash_equals($planHash, (string)($verified['plan_hash'] ?? ''))
            ) {
                throw new RuntimeException('hotel_collection_plan_readback_failed');
            }
            if ($activate && ($verified['execution_authorized'] ?? false) !== true) {
                throw new RuntimeException('hotel_collection_plan_final_binding_not_ready');
            }
            return $verified;
        });

        $readback['save_verified'] = true;
        return $readback;
    }

    /**
     * @param array<string,mixed> $hotel
     * @return array<string,mixed>
     */
    public function read(array $hotel, int $actorUserId, string $businessDate = ''): array
    {
        [$tenantId, $hotelId] = $this->scope($hotel, $actorUserId);
        $businessDate = $this->dateOrEmpty($businessDate);
        $this->assertTableReady();
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('active_slot', 1)
            ->find();
        if (!is_array($row)) {
            $row = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('plan_status', 'draft')
                ->order('plan_version', 'desc')
                ->find();
        }
        if (!is_array($row)) {
            return [
                'status' => 'missing',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'latest_run_receipt' => $this->latestRunReceipt(
                    $tenantId,
                    $hotelId,
                    $businessDate
                ),
                'readback_verified' => false,
                'execution_authorized' => false,
                'failure_reasons' => [$this->issue(
                    'hotel_collection_plan_missing',
                    '',
                    'No collection plan has been saved for this hotel.'
                )],
                'sensitive_values_exposed' => false,
            ];
        }

        $result = $this->hydrateRow($hotel, $actorUserId, $businessDate, $row);
        if ((int)($row['active_slot'] ?? 0) === 1) {
            $pendingDraft = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('plan_status', 'draft')
                ->order('plan_version', 'desc')
                ->find();
            if (is_array($pendingDraft)) {
                $result['pending_draft'] = [
                    'id' => (int)($pendingDraft['id'] ?? 0),
                    'plan_version' => (int)($pendingDraft['plan_version'] ?? 0),
                    'validation_status' => (string)($pendingDraft['validation_status'] ?? 'blocked'),
                    'updated_at' => $this->timestampOrNull((string)($pendingDraft['update_time'] ?? '')),
                ];
            }
        }
        $result['latest_run_receipt'] = $this->latestRunReceipt(
            $tenantId,
            $hotelId,
            $businessDate
        );
        return $result;
    }

    /** @return array<string,mixed> */
    private function latestRunReceipt(int $tenantId, int $hotelId, string $businessDate): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $businessDate === '') {
            return [
                'status' => 'missing',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate !== '' ? $businessDate : null,
                'failure_code' => 'hotel_collection_run_business_date_missing',
                'readback_verified' => false,
                'automatic_device_substitution' => false,
                'sensitive_values_exposed' => false,
            ];
        }

        try {
            $run = Db::name(HotelCollectionRunReceiptService::RUN_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('business_date', $businessDate)
                ->order('id', 'desc')
                ->field('dispatcher_run_id')
                ->find();
            if (!is_array($run)) {
                return [
                    'status' => 'missing',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'failure_code' => 'hotel_collection_run_receipt_missing',
                    'readback_verified' => false,
                    'automatic_device_substitution' => false,
                    'sensitive_values_exposed' => false,
                ];
            }

            return (new HotelCollectionRunReceiptService())->readGroup(
                (string)($run['dispatcher_run_id'] ?? ''),
                $tenantId,
                $hotelId,
                $businessDate
            );
        } catch (\Throwable) {
            return [
                'status' => 'unavailable',
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'failure_code' => 'hotel_collection_run_receipt_store_unavailable',
                'readback_verified' => false,
                'automatic_device_substitution' => false,
                'sensitive_values_exposed' => false,
            ];
        }
    }

    /**
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateRow(
        array $hotel,
        int $actorUserId,
        string $businessDate,
        array $row
    ): array {
        [$tenantId, $hotelId] = $this->scope($hotel, $actorUserId);
        $sources = $this->decodeArray($row['source_plan_json'] ?? null);
        $storedValidationReasons = $this->issues($this->decodeArray($row['validation_reasons_json'] ?? null));
        $computedHash = $this->planHash($row);
        $storedHash = strtolower(trim((string)($row['plan_hash'] ?? '')));
        $readbackVerified = $this->digest($storedHash) && hash_equals($storedHash, $computedHash);
        $designated = [
            'ctrip' => (int)($sources['ctrip']['data_source_id'] ?? 0),
            'meituan' => (int)($sources['meituan']['data_source_id'] ?? 0),
        ];
        try {
            $binding = $this->bindingReceipt(
                $hotel,
                $actorUserId,
                $businessDate,
                $designated
            );
            $this->assertBindingReceiptContract($binding, $tenantId, $hotelId, [
                'ctrip_source_id' => $designated['ctrip'],
                'meituan_source_id' => $designated['meituan'],
                'pms_provider' => (string)($sources['pms']['provider'] ?? ''),
            ]);
        } catch (\Throwable $error) {
            $binding = [
                'status' => 'blocked',
                'binding_digest' => '',
                'blockers' => [$this->issue(
                    'hotel_collection_binding_read_failed',
                    '',
                    $error->getMessage()
                )],
                'recovery_reasons' => [],
            ];
        }
        $currentBindingDigest = strtolower(trim((string)($binding['binding_digest'] ?? '')));
        $bindingDigestMatches = $this->digest($currentBindingDigest)
            && hash_equals((string)$row['binding_digest'], $currentBindingDigest);
        $failureReasons = $this->uniqueIssues(array_merge(
            $storedValidationReasons,
            $this->bindingIssues($binding)
        ));
        if (!$readbackVerified) {
            $failureReasons[] = $this->issue(
                'hotel_collection_plan_signature_mismatch',
                '',
                'Saved collection plan failed signed exact readback.'
            );
        }
        if (!$bindingDigestMatches) {
            $failureReasons[] = $this->issue(
                'hotel_collection_plan_binding_drifted',
                '',
                'Current hotel binding no longer matches the saved plan binding digest.'
            );
        }
        $active = (string)($row['plan_status'] ?? '') === 'active'
            && (int)($row['enabled'] ?? 0) === 1
            && (int)($row['active_slot'] ?? 0) === 1;
        if (!$active) {
            $failureReasons[] = $this->issue(
                'hotel_collection_plan_not_active',
                '',
                'The saved plan is a draft or disabled and cannot schedule execution.'
            );
        }
        if ((int)($hotel['status'] ?? 0) !== 1) {
            $failureReasons[] = $this->issue(
                'hotel_collection_plan_hotel_disabled',
                '',
                'The system hotel is disabled and cannot schedule collection.'
            );
        }
        $failureReasons = $this->uniqueIssues($failureReasons);
        $executionAuthorized = $readbackVerified
            && $bindingDigestMatches
            && (string)($binding['status'] ?? '') === 'ready'
            && $active
            && (int)($hotel['status'] ?? 0) === 1
            && $failureReasons === [];

        return [
            'status' => $executionAuthorized
                ? 'active_ready'
                : ((string)($row['plan_status'] ?? '') === 'draft' ? 'draft' : 'blocked'),
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'plan_version' => (int)($row['plan_version'] ?? 0),
            'plan_status' => (string)($row['plan_status'] ?? ''),
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'active_slot' => (int)($row['active_slot'] ?? 0) === 1,
            'business_date_policy' => (string)($row['business_date_policy'] ?? ''),
            'timezone' => (string)($row['timezone'] ?? ''),
            'schedule_time' => (string)($row['schedule_time'] ?? ''),
            'retry_interval_minutes' => (int)($row['retry_interval_minutes'] ?? 0),
            'max_attempts' => (int)($row['max_attempts'] ?? 0),
            'execution_owner_bound' => (int)($row['execution_owner_user_id'] ?? 0) > 0,
            'sources' => $sources,
            'binding_digest' => (string)($row['binding_digest'] ?? ''),
            'current_binding_digest' => $currentBindingDigest !== '' ? $currentBindingDigest : null,
            'binding_digest_matches' => $bindingDigestMatches,
            'plan_hash' => $storedHash,
            'readback_verified' => $readbackVerified,
            'stored_validation_status' => (string)($row['validation_status'] ?? ''),
            'current_binding_status' => (string)($binding['status'] ?? 'blocked'),
            'execution_authorized' => $executionAuthorized,
            'automatic_device_substitution' => false,
            'failure_reasons' => $failureReasons,
            'activated_at' => $this->timestampOrNull((string)($row['activated_at'] ?? '')),
            'updated_at' => $this->timestampOrNull((string)($row['update_time'] ?? '')),
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Fail-closed gate for one scheduled execution scope.
     *
     * @param array<string,mixed> $hotel
     * @param array<int,mixed> $sourceIds
     * @param array<int,mixed> $platforms
     * @return array<string,mixed>
     */
    public function authorizeExecutionScope(
        array $hotel,
        string $businessDate,
        array $sourceIds,
        array $platforms,
        string $runMode
    ): array {
        [$tenantId, $hotelId] = $this->scope($hotel, 1);
        $businessDate = $this->dateOrEmpty($businessDate);
        $runMode = $this->safeCode($runMode);
        $this->assertTableReady();

        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('active_slot', 1)
            ->find();
        $executionOwnerUserId = is_array($row)
            ? (int)($row['execution_owner_user_id'] ?? 0)
            : 0;
        $plan = $this->read(
            $hotel,
            $executionOwnerUserId > 0 ? $executionOwnerUserId : 1,
            $businessDate
        );
        $executionOwnerBound = ($plan['execution_owner_bound'] ?? false) === true;
        $sources = is_array($plan['sources'] ?? null) ? $plan['sources'] : [];
        $expectedSourceIds = $this->positiveIds([
            $sources['ctrip']['data_source_id'] ?? 0,
            $sources['meituan']['data_source_id'] ?? 0,
        ]);
        $actualSourceIds = $this->positiveIds($sourceIds);
        $expectedPlatforms = ['ctrip', 'meituan'];
        $actualPlatforms = $this->platforms($platforms);
        $failureReasons = $this->issues((array)($plan['failure_reasons'] ?? []));

        if ($businessDate === '') {
            $failureReasons[] = $this->issue(
                'hotel_collection_execution_date_missing',
                '',
                'Scheduled execution must pin one exact business date.'
            );
        }
        if (!$executionOwnerBound) {
            $failureReasons[] = $this->issue(
                'hotel_collection_execution_owner_missing',
                '',
                'The plan has no single operator execution owner.'
            );
        }
        if ($expectedSourceIds === [] || $actualSourceIds !== $expectedSourceIds) {
            $failureReasons[] = $this->issue(
                'hotel_collection_execution_source_scope_mismatch',
                '',
                'Scheduled source ids do not exactly match this hotel plan.'
            );
        }
        if ($actualPlatforms !== $expectedPlatforms) {
            $failureReasons[] = $this->issue(
                'hotel_collection_execution_platform_scope_mismatch',
                '',
                'Scheduled platforms do not exactly match this hotel plan.'
            );
        }
        $businessDatePolicy = (string)($plan['business_date_policy'] ?? '');
        $expectedRunMode = $businessDatePolicy === 'same_day_realtime' ? 'realtime' : 'daily';
        if ($runMode !== $expectedRunMode) {
            $failureReasons[] = $this->issue(
                'hotel_collection_execution_mode_mismatch',
                '',
                'Scheduled run mode does not match this hotel plan.'
            );
        }
        $failureReasons = $this->uniqueIssues($failureReasons);
        $collectionAllowed = ($plan['execution_authorized'] ?? false) === true
            && $failureReasons === [];
        $scopeHash = hash('sha256', $this->encode([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'run_mode' => $runMode,
            'source_ids' => $actualSourceIds,
            'platforms' => $actualPlatforms,
            'plan_hash' => (string)($plan['plan_hash'] ?? ''),
        ]));

        return [
            'status' => $collectionAllowed ? 'ready' : 'blocked',
            'collection_allowed' => $collectionAllowed,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'run_mode' => $runMode,
            'plan_id' => (int)($plan['id'] ?? 0) ?: null,
            'plan_version' => (int)($plan['plan_version'] ?? 0),
            'plan_hash' => (string)($plan['plan_hash'] ?? ''),
            'plan_readback_verified' => ($plan['readback_verified'] ?? false) === true,
            'binding_digest_matches' => ($plan['binding_digest_matches'] ?? false) === true,
            'execution_owner_bound' => $executionOwnerBound,
            'execution_owner_user_id' => $executionOwnerUserId > 0
                ? $executionOwnerUserId
                : null,
            'sources' => $sources,
            'expected_source_ids' => $expectedSourceIds,
            'actual_source_ids' => $actualSourceIds,
            'expected_platforms' => $expectedPlatforms,
            'actual_platforms' => $actualPlatforms,
            'scope_hash' => $scopeHash,
            'failure_reasons' => $failureReasons,
            'automatic_device_substitution' => false,
            'resume_scope' => 'same_account_same_device_same_hotel_same_platform',
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function sourceSelection(array $input): array
    {
        $sources = is_array($input['sources'] ?? null) ? $input['sources'] : [];
        if (array_is_list($sources)) {
            $indexed = [];
            foreach ($sources as $source) {
                if (!is_array($source)) {
                    continue;
                }
                $key = strtolower(trim((string)($source['platform'] ?? $source['source_key'] ?? '')));
                if (in_array($key, self::SOURCE_KEYS, true)) {
                    $indexed[$key] = $source;
                }
            }
            $sources = $indexed;
        }
        $ctrip = is_array($sources['ctrip'] ?? null) ? $sources['ctrip'] : [];
        $meituan = is_array($sources['meituan'] ?? null) ? $sources['meituan'] : [];
        $pms = is_array($sources['pms'] ?? null) ? $sources['pms'] : [];
        $ctripSourceId = (int)($ctrip['data_source_id'] ?? $ctrip['source_id'] ?? $input['ctrip_source_id'] ?? 0);
        $meituanSourceId = (int)($meituan['data_source_id'] ?? $meituan['source_id'] ?? $input['meituan_source_id'] ?? 0);
        $pmsProvider = $this->safeCode((string)($pms['provider'] ?? $input['pms_provider'] ?? ''));
        if ($ctripSourceId <= 0 || $meituanSourceId <= 0 || $pmsProvider === '') {
            throw new \InvalidArgumentException('hotel_collection_plan_sources_required');
        }
        return [
            'ctrip_source_id' => $ctripSourceId,
            'meituan_source_id' => $meituanSourceId,
            'pms_provider' => $pmsProvider,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function schedule(array $input): array
    {
        $businessDatePolicy = $this->safeCode((string)($input['business_date_policy'] ?? 'previous_business_day'));
        if (!in_array($businessDatePolicy, ['previous_business_day', 'same_day_realtime'], true)) {
            throw new \InvalidArgumentException('hotel_collection_plan_date_policy_invalid');
        }
        $timezone = trim((string)($input['timezone'] ?? self::TIMEZONE));
        if ($timezone !== self::TIMEZONE) {
            throw new \InvalidArgumentException('hotel_collection_plan_timezone_invalid');
        }
        $scheduleTime = trim((string)($input['schedule_time'] ?? '08:30'));
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $scheduleTime) !== 1) {
            throw new \InvalidArgumentException('hotel_collection_plan_schedule_time_invalid');
        }
        $retryInterval = (int)($input['retry_interval_minutes'] ?? 14);
        $maxAttempts = (int)($input['max_attempts'] ?? 7);
        if ($retryInterval < 5 || $retryInterval > 120 || $maxAttempts < 1 || $maxAttempts > 12) {
            throw new \InvalidArgumentException('hotel_collection_plan_retry_policy_invalid');
        }
        return [
            'business_date_policy' => $businessDatePolicy,
            'timezone' => $timezone,
            'schedule_time' => $scheduleTime,
            'retry_interval_minutes' => $retryInterval,
            'max_attempts' => $maxAttempts,
        ];
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $bindings
     * @return array<string,array<string,mixed>>
     */
    private function sourcePlan(array $selection, array $bindings): array
    {
        $sources = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $binding = is_array($bindings[$platform] ?? null) ? $bindings[$platform] : [];
            $device = is_array($binding['execution_device_binding'] ?? null)
                ? $binding['execution_device_binding']
                : [];
            $profile = is_array($binding['profile_binding'] ?? null) ? $binding['profile_binding'] : [];
            $sources[$platform] = [
                'source_key' => $platform,
                'platform' => $platform,
                'data_source_id' => (int)$selection[$platform . '_source_id'],
                'ingestion_method' => strtolower(trim((string)($binding['ingestion_method'] ?? ''))),
                'platform_hotel_id' => trim((string)($binding['platform_hotel_id'] ?? '')) ?: null,
                'profile_binding_digest' => trim((string)($profile['profile_binding_digest'] ?? '')) ?: null,
                'execution_binding_digest' => trim((string)($device['execution_binding_digest'] ?? '')) ?: null,
                'device_binding_digest' => trim((string)($device['device_binding_digest'] ?? '')) ?: null,
                'binding_status' => (string)($binding['status'] ?? 'blocked'),
                'failure_codes' => $this->bindingCodes($binding),
                'automatic_device_substitution' => false,
                'resume_scope' => 'same_account_same_device_same_hotel_same_platform',
            ];
        }
        $pms = is_array($bindings['pms'] ?? null) ? $bindings['pms'] : [];
        $sources['pms'] = [
            'source_key' => 'pms',
            'platform' => 'pms',
            'provider' => $selection['pms_provider'],
            'provider_hotel_id' => trim((string)($pms['provider_hotel_id'] ?? '')) ?: null,
            'provider_hotel_name' => trim((string)($pms['provider_hotel_name'] ?? '')) ?: null,
            'binding_status' => (string)($pms['status'] ?? 'blocked'),
            'failure_codes' => $this->bindingCodes($pms),
        ];
        return $sources;
    }

    /** @param array<string,array<string,mixed>> $bindings */
    private function singleExecutionOwner(array $bindings): int
    {
        $ids = array_values(array_unique(array_filter([
            (int)($bindings['ctrip']['execution_owner_user_id'] ?? 0),
            (int)($bindings['meituan']['execution_owner_user_id'] ?? 0),
        ])));
        return count($ids) === 1 ? $ids[0] : 0;
    }

    /** @param array<string,mixed> $binding @return array<int,string> */
    private function bindingCodes(array $binding): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn(array $issue): string => $this->safeCode((string)($issue['code'] ?? '')),
            array_values(array_filter(array_merge(
                (array)($binding['blockers'] ?? []),
                (array)($binding['recovery_reasons'] ?? [])
            ), 'is_array'))
        ))));
    }

    /** @param array<string,mixed> $binding @return array<int,array<string,string>> */
    private function bindingIssues(array $binding): array
    {
        return $this->issues(array_merge(
            (array)($binding['blockers'] ?? []),
            (array)($binding['recovery_reasons'] ?? [])
        ));
    }

    /** @param array<int|string,mixed> $values @return array<int,array<string,string>> */
    private function issues(array $values): array
    {
        $issues = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            $issues[] = $this->issue(
                (string)($value['code'] ?? ''),
                (string)($value['platform'] ?? ''),
                (string)($value['message'] ?? '')
            );
        }
        return $this->uniqueIssues($issues);
    }

    /** @param array<int,mixed> $issues @return array<int,array<string,string>> */
    private function uniqueIssues(array $issues): array
    {
        $result = [];
        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $normalized = $this->issue(
                (string)($issue['code'] ?? ''),
                (string)($issue['platform'] ?? ''),
                (string)($issue['message'] ?? '')
            );
            $result[$normalized['platform'] . ':' . $normalized['code']] = $normalized;
        }
        return array_values($result);
    }

    /** @return array{code:string,platform:string,message:string} */
    private function issue(string $code, string $platform, string $message): array
    {
        $code = $this->safeCode($code) ?: 'collection_plan_error';
        $platform = $this->safeCode($platform);
        return [
            'code' => $code,
            'platform' => $platform,
            'message' => $this->safeIssueMessage($code, $platform),
        ];
    }

    private function safeIssueMessage(string $code, string $platform): string
    {
        $messages = [
            'hotel_collection_plan_missing' => 'No collection plan is saved for this hotel.',
            'hotel_collection_plan_not_active' => 'This plan version is not the active execution version.',
            'hotel_collection_plan_signature_mismatch' => 'The signed plan readback does not match.',
            'hotel_collection_plan_binding_drifted' => 'The current hotel binding differs from this plan version.',
            'hotel_collection_plan_hotel_disabled' => 'The system hotel is disabled.',
            'hotel_collection_binding_read_failed' => 'The current binding receipt could not be verified.',
            'pms_plan_provider_mismatch' => 'The selected PMS provider differs from the hotel binding.',
            'hotel_collection_execution_date_missing' => 'An exact business date is required.',
            'hotel_collection_execution_owner_missing' => 'A single operator execution owner is required.',
            'hotel_collection_execution_source_scope_mismatch' => 'Scheduled source ids differ from this hotel plan.',
            'hotel_collection_execution_platform_scope_mismatch' => 'Scheduled platforms differ from this hotel plan.',
            'hotel_collection_execution_mode_mismatch' => 'Scheduled mode differs from this hotel plan.',
            'device_offline' => 'The already-bound operator device is offline.',
            'login_required' => 'Login must be restored on the already-bound operator device.',
            'permission_denied' => 'The bound account lacks permission for this platform hotel.',
            'identity_mismatch' => 'The current platform hotel identity differs from the binding.',
        ];
        if (isset($messages[$code])) {
            return $messages[$code];
        }
        if (str_starts_with($code, 'ota_')) {
            return 'OTA binding validation failed for the exact hotel and platform scope.';
        }
        if (str_starts_with($code, 'pms_')) {
            return 'PMS binding validation failed for the exact hotel scope.';
        }
        return $platform !== ''
            ? 'Collection plan validation failed for this platform scope.'
            : 'Collection plan validation failed for this hotel scope.';
    }

    /** @param array<string,mixed> $hotel @return array{0:int,1:int} */
    private function scope(array $hotel, int $actorUserId): array
    {
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $hotelId = (int)($hotel['id'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException('hotel_collection_plan_scope_invalid');
        }
        return [$tenantId, $hotelId];
    }

    /** @param array<string,mixed> $hotel @return array<string,mixed> */
    private function bindingReceipt(
        array $hotel,
        int $actorUserId,
        string $businessDate,
        array $designatedSourceIds
    ): array {
        $value = $this->bindingReceiptLoader === null
            ? (new HotelCollectionBindingReceiptService())->receipt(
                $hotel,
                $actorUserId,
                $businessDate,
                $designatedSourceIds
            )
            : call_user_func(
                $this->bindingReceiptLoader,
                $hotel,
                $actorUserId,
                $businessDate,
                $designatedSourceIds
            );
        if (!is_array($value)) {
            throw new RuntimeException('hotel_collection_binding_receipt_invalid');
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $binding
     * @param array<string,mixed> $selection
     */
    private function assertBindingReceiptContract(
        array $binding,
        int $tenantId,
        int $hotelId,
        array $selection
    ): void {
        $systemHotel = is_array($binding['system_hotel'] ?? null)
            ? $binding['system_hotel']
            : [];
        if ((int)($systemHotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($systemHotel['system_hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new RuntimeException('hotel_collection_binding_receipt_scope_mismatch');
        }
        $bindings = is_array($binding['bindings'] ?? null) ? $binding['bindings'] : [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $ota = is_array($bindings[$platform] ?? null) ? $bindings[$platform] : [];
            $selectedSourceId = (int)($selection[$platform . '_source_id'] ?? 0);
            $sourceId = (int)($ota['source_id'] ?? 0);
            $designatedSourceId = (int)($ota['designated_source_id'] ?? 0);
            $status = strtolower(trim((string)($ota['status'] ?? 'blocked')));
            if (strtolower(trim((string)($ota['platform'] ?? ''))) !== $platform
                || (int)($ota['tenant_id'] ?? 0) !== $tenantId
                || (int)($ota['system_hotel_id'] ?? 0) !== $hotelId
                || $selectedSourceId <= 0
                || $designatedSourceId !== $selectedSourceId
                || ($sourceId > 0 && $sourceId !== $selectedSourceId)
                || (in_array($status, ['ready', 'recoverable'], true)
                    && $sourceId !== $selectedSourceId)
            ) {
                throw new RuntimeException('hotel_collection_binding_receipt_scope_mismatch');
            }
        }
        $pms = is_array($bindings['pms'] ?? null) ? $bindings['pms'] : [];
        if (strtolower(trim((string)($pms['platform'] ?? ''))) !== 'pms'
            || (int)($pms['tenant_id'] ?? 0) !== $tenantId
            || (int)($pms['system_hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new RuntimeException('hotel_collection_binding_receipt_scope_mismatch');
        }
    }

    /** @param array<string,mixed> $row */
    private function planHash(array $row): string
    {
        $payload = [
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'plan_version' => (int)($row['plan_version'] ?? 0),
            'plan_status' => (string)($row['plan_status'] ?? ''),
            'enabled' => (int)($row['enabled'] ?? 0),
            'active_slot' => (int)($row['active_slot'] ?? 0),
            'business_date_policy' => (string)($row['business_date_policy'] ?? ''),
            'timezone' => (string)($row['timezone'] ?? ''),
            'schedule_time' => (string)($row['schedule_time'] ?? ''),
            'retry_interval_minutes' => (int)($row['retry_interval_minutes'] ?? 0),
            'max_attempts' => (int)($row['max_attempts'] ?? 0),
            'execution_owner_user_id' => (int)($row['execution_owner_user_id'] ?? 0),
            'binding_digest' => strtolower(trim((string)($row['binding_digest'] ?? ''))),
            'source_plan_json' => $this->canonicalEncode(
                $this->decodeArray($row['source_plan_json'] ?? null)
            ),
        ];
        return hash_hmac('sha256', $this->canonicalEncode($payload), $this->planSigningKey());
    }

    private function assertTableReady(): void
    {
        try {
            Db::name(self::TABLE)->field('id,active_slot')->limit(1)->select();
        } catch (\Throwable $error) {
            throw new RuntimeException('hotel_collection_plan_table_missing', 0, $error);
        }
    }

    /** @return array<string,mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function encode(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalEncode(mixed $value): string
    {
        return $this->encode($this->canonicalize($value));
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

    private function planSigningKey(): string
    {
        $raw = $this->signingKey;
        if ($raw === null) {
            $encoded = trim((string)(function_exists('env')
                ? env(
                    'HOTEL_COLLECTION_PLAN_SIGNING_KEY_B64',
                    env('OTA_CREDENTIAL_KEY_B64', '')
                )
                : (getenv('HOTEL_COLLECTION_PLAN_SIGNING_KEY_B64')
                    ?: getenv('OTA_CREDENTIAL_KEY_B64'))));
            $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;
            $raw = is_string($decoded) ? $decoded : '';
        }
        if (strlen($raw) < 32) {
            throw new RuntimeException('hotel_collection_plan_signing_key_missing');
        }
        return hash_hkdf('sha256', $raw, 32, 'suxios-hotel-collection-plan-v1');
    }

    private function dateOrEmpty(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('hotel_collection_plan_business_date_invalid');
        }
        return $value;
    }

    private function timestampOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function now(): string
    {
        $value = $this->clock === null
            ? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE))
            : call_user_func($this->clock);
        if (!$value instanceof DateTimeImmutable) {
            throw new RuntimeException('hotel_collection_plan_clock_invalid');
        }
        return $value->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d H:i:s');
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered ?? false;
    }

    private function digest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', strtolower(trim($value))) === 1;
    }

    /** @param array<int,mixed> $values @return array<int,int> */
    private function positiveIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private function platforms(array $values): array
    {
        $platforms = [];
        foreach ($values as $value) {
            $platform = $this->safeCode((string)$value);
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $platforms[$platform] = $platform;
            }
        }
        $platforms = array_values($platforms);
        sort($platforms, SORT_STRING);
        return $platforms;
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }
}
